<?php
/**
 * admin/schedule_bid_opening.php
 * Schedule a bid opening session for a specific procurement.
 * Creates a bid_opening_sessions record (which now contains stream info directly).
 */
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';

$admin_id  = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'admin';

// Hydrate admin_type
if (!isset($_SESSION['admin_type']) && isset($conn)) {
    $at = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    if ($at) {
        $at->bind_param("i", $admin_id);
        $at->execute();
        $at_row = $at->get_result()->fetch_assoc();
        $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
        $at->close();
    }
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$can_manage = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

if (!$can_manage) {
    header("Location: bid_opening.php");
    exit();
}

// ── Get procurement ID from query string ──────────────────────────────────
$procurement_id = isset($_GET['procurement']) ? (int)$_GET['procurement'] : 0;
if ($procurement_id === 0) {
    header("Location: bid_opening.php");
    exit();
}

// ── Fetch procurement ─────────────────────────────────────────────────────
$ps = $conn->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM procurements p
    WHERE p.id = ? AND p.status = 'open'
    LIMIT 1
");$ps->bind_param("i", $procurement_id);
$ps->execute();
$procurement = $ps->get_result()->fetch_assoc();
$ps->close();

if (!$procurement) {
    $_SESSION['alert_error'] = "Procurement not found or is not open.";
    header("Location: bid_opening.php");
    exit();
}

// Guard: block if a session already exists for this procurement
$existing = $conn->prepare("SELECT id FROM bid_opening_sessions WHERE procurement_id = ? LIMIT 1");
$existing->bind_param("i", $procurement_id);
$existing->execute();
$existing->store_result();
if ($existing->num_rows > 0) {
    $_SESSION['alert_error'] = "A bid opening session already exists for this procurement.";
    header("Location: bid_opening.php");
    exit();
}
$existing->close();

// ── Fetch admins with BAC or TWG roles ────────────────────────────────────
$admins_res = $conn->query("
    SELECT u.user_id, u.firstname, u.lastname, u.username, u.profile_picture_url,
           ar.admin_type
    FROM users u
    JOIN admin_roles ar ON ar.user_id = u.user_id
    WHERE ar.admin_type IN ('BAC','TWG') AND u.status = 'active'
    ORDER BY ar.admin_type ASC, u.firstname ASC
");
$admin_list = $admins_res ? $admins_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Handle form submission ────────────────────────────────────────────────
$errors = [];
$alert_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_session'])) {
    $stream_path  = trim($_POST['stream_path'] ?? 'live');
    $stream_path  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $stream_path) ?: 'live';
    $event_title  = trim($_POST['event_title'] ?? '');
    $invited_ids  = $_POST['invited_users'] ?? [];

    if (empty($stream_path)) $errors[] = "Stream path is required.";

    // Guard: one session per procurement
    if (empty($errors)) {
        $dup = $conn->prepare("SELECT id FROM bid_opening_sessions WHERE procurement_id = ? LIMIT 1");
        $dup->bind_param("i", $procurement_id);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) {
            $errors[] = "A bid opening session already exists for this procurement.";
        }
        $dup->close();
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // 1. Create bid_opening_session
            $ins_ses = $conn->prepare("
                INSERT INTO bid_opening_sessions (procurement_id, stream_path, title, status, created_by)
                VALUES (?, ?, ?, 'scheduled', ?)
            ");
            $ins_ses->bind_param("issi", $procurement_id, $stream_path, $event_title, $admin_id);
            $ins_ses->execute();
            $session_id = $conn->insert_id;
            $ins_ses->close();

            // 2. Set procurement status to closed
            $upd_proc = $conn->prepare("UPDATE procurements SET status = 'closed' WHERE id = ?");
            $upd_proc->bind_param("i", $procurement_id);
            $upd_proc->execute();
            $upd_proc->close();

            // 3. Invite selected admins
            if (!empty($invited_ids)) {
                $ins_inv = $conn->prepare("
                    INSERT IGNORE INTO bid_session_invited (bid_session_id, user_id)
                    VALUES (?, ?)
                ");
                foreach ($invited_ids as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        $ins_inv->bind_param("ii", $session_id, $uid);
                        $ins_inv->execute();
                    }
                }
                $ins_inv->close();
            }

            // Audit session creation
            audit_log(
                $conn,
                'BID_SESSION_CREATED',
                'bid_opening',
                $session_id,
                "Scheduled bid opening session for procurement #{$procurement_id}: {$event_title}",
                null,
                [
                    'procurement_id' => $procurement_id,
                    'stream_path' => $stream_path,
                    'title' => $event_title,
                    'status' => 'scheduled'
                ]
            );

            // Audit procurement status change (open -> closed)
            audit_log(
                $conn,
                'PROCUREMENT_STATUS_CHANGED',
                'procurements',
                $procurement_id,
                "Procurement #{$procurement_id} closed for bid opening session #{$session_id}",
                ['status' => 'open'],
                ['status' => 'closed']
            );

            // Audit invited members
            if (!empty($invited_ids)) {
                $invited_clean = array_values(array_filter(array_map('intval', $invited_ids)));
                audit_log(
                    $conn,
                    'SESSION_MEMBER_INVITED',
                    'bid_opening',
                    $session_id,
                    "Invited " . count($invited_clean) . " BAC/TWG member(s) to session #{$session_id}",
                    null,
                    ['invited_user_ids' => $invited_clean]
                );
            }

            $conn->commit();
            $_SESSION['alert_success'] = "Bid opening session scheduled successfully.";
            header("Location: bid_opening.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Failed to schedule session: " . $e->getMessage();
        }
    }
}

$alert_success = $_SESSION['alert_success'] ?? ''; unset($_SESSION['alert_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Bid Opening — <?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        * { box-sizing: border-box; }

        /* ── Nav bar ── */
        .vp-nav-bar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
        }
        .vp-breadcrumbs {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #88968d; font-weight: 600;
        }
        .vp-breadcrumbs a {
            color: #1f7a3d; text-decoration: none;
            display: inline-flex; align-items: center; gap: 4px; transition: color .15s;
        }
        .vp-breadcrumbs a:hover { text-decoration: underline; color: #06251b; }
        .vp-back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: #06251b; font-size: 12.5px; font-weight: 700;
            background: #fff; border: 1px solid #eaeeec;
            padding: 7px 14px; border-radius: 10px;
            text-decoration: none; transition: all .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,.02);
        }
        .vp-back-link:hover { background: #06251b; color: #ffc107; border-color: #06251b; }

        /* ── Hero card ── */
        .vp-hero-card {
            background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #14593f 100%);
            border-radius: 20px; padding: 26px 30px; color: #fff;
            position: relative; overflow: hidden;
            box-shadow: 0 8px 24px rgba(6,37,27,.16);
            margin-bottom: 24px; border: 1px solid rgba(255,255,255,.08);
        }
        .vp-hero-card::after {
            content: ''; position: absolute; top: -50px; right: -50px;
            width: 240px; height: 240px;
            background: radial-gradient(circle, rgba(255,193,7,.14) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none;
        }
        .vp-hero-top {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap; margin-bottom: 12px;
        }
        .vp-hero-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hero-pill {
            font-size: 11px; font-weight: 700; padding: 4px 10px;
            border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; letter-spacing: .3px;
        }
        .hero-pill.ref {
            background: rgba(255,255,255,.12); color: #fff;
            border: 1px solid rgba(255,255,255,.2);
        }
        .hero-pill.mode {
            background: rgba(255,193,7,.2); border: 1px solid rgba(255,193,7,.4); color: #ffc107;
        }
        .hero-pill.open {
            background: rgba(33,150,83,.25); border: 1px solid rgba(33,150,83,.5); color: #81c784;
        }
        .hero-pill.locked {
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15); color: #d1e5db;
            font-size: 10.5px;
        }
        .vp-hero-title {
            font-size: 22px; font-weight: 800; color: #fff;
            line-height: 1.35; margin-bottom: 18px; letter-spacing: -.2px;
        }
        .vp-hero-metrics {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;
        }
        .vp-hero-metric-item {
            background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
            border-radius: 14px; padding: 12px 16px;
        }
        .vp-hero-metric-lbl {
            font-size: 10px; font-weight: 700; color: #d1e5db;
            text-transform: uppercase; letter-spacing: .4px;
            margin-bottom: 4px; display: flex; align-items: center; gap: 5px;
        }
        .vp-hero-metric-lbl i { color: #ffc107; }
        .vp-hero-metric-val {
            font-size: 16px; font-weight: 800; color: #fff;
            font-family: 'Space Grotesk', sans-serif;
        }
        .vp-hero-metric-val.gold { color: #ffc107; }

        /* ── Layout ── */
        .sbo-layout {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 24px; align-items: start;
        }
        @media(max-width:1040px){ .sbo-layout{ grid-template-columns:1fr; } }

        /* ── Step cards ── */
        .submit-step-card {
            background: #fff; border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px; overflow: hidden;
            transition: border-color .2s;
        }
        .submit-step-card:focus-within { border-color: #1f7a3d; }
        .step-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px; border-bottom: 1px solid #f0f4f2;
            background: #fafcfb; gap: 14px; flex-wrap: wrap;
        }
        .step-header-left { display: flex; align-items: center; gap: 14px; flex: 1; }
        .step-badge {
            width: 36px; height: 36px; border-radius: 10px;
            background: #06251b; color: #ffc107;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 800; font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0; box-shadow: 0 2px 6px rgba(6,37,27,.15);
        }
        .step-title-text h4 {
            font-size: 15px; font-weight: 800; color: #06251b; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .step-title-text p { font-size: 12px; color: #6c776e; margin: 3px 0 0; }
        .step-card-body { padding: 22px; }

        /* ── Form fields ── */
        .sbo-field { margin-bottom: 18px; }
        .sbo-label {
            font-size: 12px; font-weight: 700; color: #06251b;
            display: block; margin-bottom: 5px;
        }
        .sbo-label .sbo-required { color: #dc2626; margin-left: 3px; }
        .sbo-input {
            width: 100%; padding: 10px 13px;
            border: 1.5px solid #e0e8e4; border-radius: 10px;
            font-size: 13px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; background: #fafcfb; outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .sbo-input:focus {
            border-color: #1f7a3d; background: #fff;
            box-shadow: 0 0 0 3px rgba(31,122,61,.08);
        }
        .sbo-input[readonly] {
            background: #f4f7f5; color: #55665a; cursor: not-allowed;
        }
        .sbo-hint { font-size: 11px; color: #88968d; margin-top: 4px; }

        /* ── Error banner ── */
        .sb-error-banner {
            background: #fdf2f2; border: 1px solid #f8d7da;
            border-left: 4px solid #dc3545; border-radius: 14px;
            padding: 16px 20px; margin-bottom: 22px;
            display: flex; align-items: flex-start; gap: 14px; color: #842029;
        }
        .sb-error-banner i { font-size: 20px; color: #dc3545; flex-shrink: 0; margin-top: 1px; }
        .sb-error-banner ul { margin: 6px 0 0; padding-left: 18px; font-size: 12.5px; line-height: 1.6; }

        /* ── Admin invite cards ── */
        .admin-invite-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
        }
        .admin-invite-card {
            background: #fafcfb; border: 2px solid #e4eae6;
            border-radius: 14px; padding: 14px 16px;
            cursor: pointer; transition: all .2s; position: relative;
            display: flex; align-items: center; gap: 12px;
            user-select: none;
        }
        .admin-invite-card:hover { border-color: #b0c9bb; background: #f4faf6; }
        .admin-invite-card.selected {
            background: #f2f9f4; border-color: #1f7a3d;
            box-shadow: 0 4px 14px rgba(31,122,61,.12);
        }
        .admin-invite-card input[type="checkbox"] {
            position: absolute; opacity: 0; pointer-events: none;
        }
        .admin-invite-avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: #e0ece4; color: #06251b;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800; flex-shrink: 0;
            overflow: hidden;
        }
        .admin-invite-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .admin-invite-info { flex: 1; min-width: 0; }
        .admin-invite-name {
            font-size: 13px; font-weight: 700; color: #06251b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .admin-invite-role {
            font-size: 10.5px; font-weight: 700;
            padding: 2px 7px; border-radius: 5px; display: inline-block; margin-top: 3px;
        }
        .role-bac { background: #e4f5ea; color: #1f7a3d; }
        .role-twg { background: #fef8e7; color: #c98800; }
        .admin-invite-check {
            width: 20px; height: 20px; border-radius: 6px;
            border: 2px solid #cfd8d3; background: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; color: #fff; transition: all .15s; flex-shrink: 0;
        }
        .admin-invite-card.selected .admin-invite-check {
            background: #1f7a3d; border-color: #1f7a3d;
        }
        .no-admins-msg {
            background: #fafcfb; border: 2px dashed #dae3de;
            border-radius: 14px; padding: 28px 20px;
            text-align: center; color: #6c776e; font-size: 12.5px;
        }

        /* ── Quick toggle buttons ── */
        .btn-quick-toggle {
            background: #f0f4f2; border: 1.5px solid #dbe2df; color: #06251b;
            font-size: 11px; font-weight: 700; padding: 5px 12px;
            border-radius: 8px; cursor: pointer; transition: all .15s;
            font-family: 'Poppins', sans-serif;
        }
        .btn-quick-toggle:hover { background: #06251b; color: #ffc107; border-color: #06251b; }

        /* ── Side summary card ── */
        .sbo-summary-card {
            background: #fff; border: 1px solid #eaeeec;
            border-radius: 18px; overflow: hidden;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            position: sticky; top: 90px;
        }
        .sbo-summary-head {
            padding: 16px 20px; background: #fafcfb;
            border-bottom: 1px solid #f0f4f2;
            font-size: 14px; font-weight: 800; color: #06251b;
            display: flex; align-items: center; gap: 8px;
        }
        .sbo-summary-body { padding: 18px 20px; }
        .sbo-summary-row {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 8px; margin-bottom: 14px; font-size: 12.5px;
        }
        .sbo-summary-row:last-child { margin-bottom: 0; }
        .sbo-summary-lbl { color: #88968d; font-weight: 600; }
        .sbo-summary-val { color: #06251b; font-weight: 700; text-align: right; }
        .sbo-summary-divider { border: none; border-top: 1px solid #f0f4f2; margin: 14px 0; }

        /* ── Submit button ── */
        .sbo-submit-btn {
            width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;
            background: #06251b; color: #ffc107;
            font-size: 14px; font-weight: 800;
            padding: 14px 20px; border-radius: 12px;
            border: none; cursor: pointer; transition: all .15s;
            font-family: 'Poppins', sans-serif;
            margin-top: 4px;
        }
        .sbo-submit-btn:hover { background: #0c3d2c; }

        /* ── Invited counter pill ── */
        .invited-count-pill {
            background: #06251b; color: #ffc107;
            font-size: 10.5px; font-weight: 800;
            padding: 1px 8px; border-radius: 10px;
            display: inline-block; margin-left: 6px;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Schedule Bid Opening'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Nav Bar ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="bid_opening.php"><i class="bi bi-envelope-open"></i> Bid Opening</a>
            <i class="bi bi-chevron-right" style="font-size:10px;"></i>
            <span>Schedule Session</span>
        </div>
        <a href="bid_opening.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Bid Opening
        </a>
    </div>

    <!-- ── Error banner ── -->
    <?php if (!empty($errors)): ?>
    <div class="sb-error-banner">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong style="font-size:13px;">Please fix the following errors:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Procurement Hero ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref">
                    <i class="bi bi-hash"></i><?= htmlspecialchars($procurement['philgeps_ref_no']) ?>
                </span>
                <?php if ($procurement['procurement_mode']): ?>
                <span class="hero-pill mode">
                    <i class="bi bi-tag"></i><?= htmlspecialchars($procurement['procurement_mode']) ?>
                </span>
                <?php endif; ?>
                <span class="hero-pill open">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> Open
                </span>
                <span class="hero-pill locked">
                    <i class="bi bi-lock-fill"></i> Procurement Locked
                </span>
            </div>
        </div>
        <div class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></div>
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-currency-dollar"></i> Approved Budget</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers"></i> Lots</div>
                <div class="vp-hero-metric-val"><?= (int)$procurement['lot_count'] ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-inbox"></i> Bids Received</div>
                <div class="vp-hero-metric-val"><?= (int)$procurement['bid_count'] ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-calendar3"></i> Opening Date</div>
                <div class="vp-hero-metric-val" style="font-size:13px;">
                    <?= !empty($procurement['opening_date']) ? date('M j, Y', strtotime($procurement['opening_date'])) : 'TBA' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Main Form ── -->
    <form method="POST" id="scheduleForm">
        <input type="hidden" name="schedule_session" value="1">
        <div class="sbo-layout">

            <!-- Left Column -->
            <div>

                <!-- Step 1: Live Event Setup -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">1</div>
                            <div class="step-title-text">
                                <h4><i class="bi bi-broadcast" style="color:#dc2626;"></i> Live Stream Setup</h4>
                                <p>Configure the MediaMTX stream path for this bid opening session.</p>
                            </div>
                        </div>
                    </div>
                    <div class="step-card-body">
                        <div class="sbo-field">
                            <label class="sbo-label">
                                Linked Procurement
                            </label>
                            <input type="text" class="sbo-input" readonly
                                value="[<?= htmlspecialchars($procurement['philgeps_ref_no']) ?>] <?= htmlspecialchars($procurement['title']) ?>">
                            <input type="hidden" name="procurement_id" value="<?= $procurement_id ?>">
                            <p class="sbo-hint"><i class="bi bi-lock-fill"></i> This field is pre-set and cannot be changed.</p>
                        </div>
                        <div class="sbo-field">
                            <label class="sbo-label">
                                Stream Path (MediaMTX) <span class="sbo-required">*</span>
                            </label>
                            <input type="text" name="stream_path" class="sbo-input"
                                value="<?= htmlspecialchars(mediamtx_default_path()) ?>"
                                placeholder="e.g. live" required>
                            <p class="sbo-hint">Must match your OBS stream key / MediaMTX path. Use only letters, numbers, hyphens, and underscores.</p>
                        </div>
                        <div class="sbo-field" style="margin-bottom:0;">
                            <label class="sbo-label">Display Title (optional)</label>
                            <input type="text" name="event_title" class="sbo-input"
                                placeholder="Leave blank to use procurement title">
                            <p class="sbo-hint">Shown on the public live page if provided.</p>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Invite Admins -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">2</div>
                            <div class="step-title-text">
                                <h4>
                                    <i class="bi bi-people-fill" style="color:#1f7a3d;"></i>
                                    Invite BAC / TWG Members
                                    <span class="invited-count-pill" id="invitedCountPill">0</span>
                                </h4>
                                <p>Select which BAC and TWG admins to invite to this bid opening session.</p>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn-quick-toggle" onclick="toggleAllAdmins(true)">Select All</button>
                            <button type="button" class="btn-quick-toggle" onclick="toggleAllAdmins(false)">Clear</button>
                        </div>
                    </div>
                    <div class="step-card-body">
                        <?php if (empty($admin_list)): ?>
                        <div class="no-admins-msg">
                            <i class="bi bi-person-x" style="font-size:28px; color:#88968d; display:block; margin-bottom:8px;"></i>
                            No active BAC or TWG admins found. Assign admin roles first via Account Management.
                        </div>
                        <?php else: ?>
                        <div class="admin-invite-grid" id="adminInviteGrid">
                            <?php foreach ($admin_list as $adm): ?>
                            <?php $initials = strtoupper(substr($adm['firstname'],0,1) . substr($adm['lastname'],0,1)); ?>
                            <div class="admin-invite-card" data-uid="<?= $adm['user_id'] ?>">
                                <input type="checkbox" name="invited_users[]" value="<?= $adm['user_id'] ?>" id="cb-<?= $adm['user_id'] ?>">
                                <div class="admin-invite-avatar">
                                    <?php if ($adm['profile_picture_url']): ?>
                                        <img src="../<?= htmlspecialchars($adm['profile_picture_url']) ?>" alt="<?= htmlspecialchars($initials) ?>">
                                    <?php else: ?>
                                        <?= htmlspecialchars($initials) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-invite-info">
                                    <div class="admin-invite-name">
                                        <?= htmlspecialchars($adm['firstname'] . ' ' . $adm['lastname']) ?>
                                    </div>
                                    <span class="admin-invite-role role-<?= strtolower($adm['admin_type']) ?>">
                                        <?= htmlspecialchars($adm['admin_type']) ?>
                                    </span>
                                </div>
                                <div class="admin-invite-check">
                                    <i class="bi bi-check2"></i>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <!-- Inline invite validation message -->
                        <div id="inviteError" style="display:none; margin-top:12px; background:#fdf2f2; border:1px solid #f8d7da; border-left:4px solid #dc3545; border-radius:10px; padding:10px 14px; font-size:12px; color:#842029; display:none; align-items:center; gap:8px;">
                            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;"></i>
                            Please invite at least one BAC or TWG member before scheduling.
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column — Summary -->
            <div>
                <div class="sbo-summary-card">
                    <div class="sbo-summary-head">
                        <i class="bi bi-calendar-check" style="color:#1f7a3d;"></i> Session Summary
                    </div>
                    <div class="sbo-summary-body">
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Procurement</span>
                            <span class="sbo-summary-val" style="font-size:11.5px; max-width:160px;">
                                <?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 50, '…')) ?>
                            </span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Reference</span>
                            <span class="sbo-summary-val"><?= htmlspecialchars($procurement['philgeps_ref_no']) ?></span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Bids Received</span>
                            <span class="sbo-summary-val"><?= (int)$procurement['bid_count'] ?></span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Lots</span>
                            <span class="sbo-summary-val"><?= (int)$procurement['lot_count'] ?></span>
                        </div>
                        <hr class="sbo-summary-divider">
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Stream Status</span>
                            <span class="sbo-summary-val" style="color:#d97706;">
                                <i class="bi bi-calendar3"></i> Scheduled
                            </span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Initial Phase</span>
                            <span class="sbo-summary-val">Eligibility</span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Invited Members</span>
                            <span class="sbo-summary-val" id="summaryInviteCount">0</span>
                        </div>
                        <hr class="sbo-summary-divider">
                        <button type="button" onclick="openScheduleModal()" class="sbo-submit-btn">
                            <i class="bi bi-calendar-plus"></i> Schedule Bid Opening
                        </button>
                        <a href="bid_opening.php" style="display:block; text-align:center; margin-top:12px; font-size:12px; color:#88968d; font-weight:600; text-decoration:none;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </form>

<!-- ── Schedule Confirmation Modal ───────────────────────────────────────── -->
<div id="scheduleModal" class="modal-backdrop" onclick="if(event.target===this)closeScheduleModal()">
    <div class="urm-modal" style="max-width:460px; width:100%;">
        <button class="urm-modal-close" onclick="closeScheduleModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon" style="background:#e8f5e9; color:#1f7a3d;">
                <i class="bi bi-calendar-plus"></i>
            </div>
        </div>
        <div class="urm-modal-text">
            <h3>Schedule Bid Opening</h3>
            <p>This will schedule a bid opening session and set the procurement status to <strong>Closed</strong>. This cannot be undone.</p>
            <div class="urm-modal-user-pill"><?= htmlspecialchars($procurement['philgeps_ref_no']) ?> · <?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 55, '…')) ?></div>
        </div>
        <div class="urm-modal-actions">
            <button type="button" onclick="closeScheduleModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="scheduleConfirmBtn" class="urm-btn-confirm" style="background:#1f7a3d;">
                <i class="bi bi-calendar-check"></i> Confirm Schedule
            </button>
        </div>
    </div>
</div>

</div>
</main>

<script>
    // Admin invite card toggle — event delegation on the grid
    document.getElementById('adminInviteGrid')?.addEventListener('click', function(e) {
        const card = e.target.closest('.admin-invite-card');
        if (!card) return;
        const cb = card.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        card.classList.toggle('selected', cb.checked);
        updateInviteCount();
    });

    function toggleAllAdmins(select) {
        document.querySelectorAll('#adminInviteGrid .admin-invite-card').forEach(card => {
            const cb = card.querySelector('input[type="checkbox"]');
            cb.checked = select;
            card.classList.toggle('selected', select);
        });
        updateInviteCount();
    }

    function updateInviteCount() {
        const count = document.querySelectorAll('#adminInviteGrid input[type="checkbox"]:checked').length;
        const pill    = document.getElementById('invitedCountPill');
        const summary = document.getElementById('summaryInviteCount');
        if (pill)    pill.textContent    = count;
        if (summary) summary.textContent = count;
        if (count > 0) document.getElementById('inviteError').style.display = 'none';
    }
</script>

<script>
    function openScheduleModal() {
        const count = document.querySelectorAll('#adminInviteGrid input[type="checkbox"]:checked').length;
        if (count === 0) {
            const err = document.getElementById('inviteError');
            err.style.display = 'flex';
            err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        document.getElementById('inviteError').style.display = 'none';
        document.getElementById('scheduleModal').classList.add('open');
    }

    function closeScheduleModal() {
        document.getElementById('scheduleModal').classList.remove('open');
    }

    document.getElementById('scheduleConfirmBtn').addEventListener('click', function() {
        document.getElementById('scheduleForm').submit();
    });
</script>

</body>
</html>
