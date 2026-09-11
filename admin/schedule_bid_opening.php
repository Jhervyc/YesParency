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

            // Notify invited committee members & participants (bid_session_invited only)
            require_once __DIR__ . '/../utils/mailer.php';
            notify_bid_session_scheduled($conn, $session_id);

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
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-schedule-bid-opening.css">
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
            <i class="bi bi-chevron-right fz-10"></i>
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
            <strong class="error-text-strong">Please fix the following errors:</strong>
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
                    <i class="bi bi-hash"></i><?= htmlspecialchars($procurement['slsu_ref_no']) ?>
                </span>
                <?php if ($procurement['procurement_mode']): ?>
                <span class="hero-pill mode">
                    <i class="bi bi-tag"></i><?= htmlspecialchars($procurement['procurement_mode']) ?>
                </span>
                <?php endif; ?>
                <span class="hero-pill open">
                    <i class="bi bi-circle-fill status-dot-tiny"></i> Open
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
                <div class="vp-hero-metric-val vp-hero-metric-val--sm">
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
                                <h4><i class="bi bi-broadcast clr-red"></i> Live Stream Setup</h4>
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
                                value="[<?= htmlspecialchars($procurement['slsu_ref_no']) ?>] <?= htmlspecialchars($procurement['title']) ?>">
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
                        <div class="sbo-field field-mb-0">
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
                                    <i class="bi bi-people-fill clr-forest"></i>
                                    Invite BAC / TWG Members
                                    <span class="invited-count-pill" id="invitedCountPill">0</span>
                                </h4>
                                <p>Select which BAC and TWG admins to invite to this bid opening session.</p>
                            </div>
                        </div>
                        <div class="step-header-actions">
                            <button type="button" class="btn-quick-toggle" onclick="toggleAllAdmins(true)">Select All</button>
                            <button type="button" class="btn-quick-toggle" onclick="toggleAllAdmins(false)">Clear</button>
                        </div>
                    </div>
                    <div class="step-card-body">
                        <?php if (empty($admin_list)): ?>
                        <div class="no-admins-msg">
                            <i class="bi bi-person-x no-admins-icon"></i>
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
                        <div id="inviteError" class="invite-error-inline">
                            <i class="bi bi-exclamation-triangle-fill icon-shrink-0"></i>
                            Please invite at least one BAC or TWG member before scheduling.
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column — Summary -->
            <div>
                <div class="sbo-summary-card">
                    <div class="sbo-summary-head">
                        <i class="bi bi-calendar-check clr-forest"></i> Session Summary
                    </div>
                    <div class="sbo-summary-body">
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Procurement</span>
                            <span class="sbo-summary-val summary-val--narrow">
                                <?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 50, '…')) ?>
                            </span>
                        </div>
                        <div class="sbo-summary-row">
                            <span class="sbo-summary-lbl">Reference</span>
                            <span class="sbo-summary-val"><?= htmlspecialchars($procurement['slsu_ref_no']) ?></span>
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
                            <span class="sbo-summary-val summary-val--amber">
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
                        <a href="bid_opening.php" class="cancel-link">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </form>

<!-- ── Schedule Confirmation Modal ───────────────────────────────────────── -->
<div id="scheduleModal" class="modal-backdrop" onclick="if(event.target===this)closeScheduleModal()">
    <div class="urm-modal modal-wide">
        <button class="urm-modal-close" onclick="closeScheduleModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon urm-modal-icon--green">
                <i class="bi bi-calendar-plus"></i>
            </div>
        </div>
        <div class="urm-modal-text">
            <h3>Schedule Bid Opening</h3>
            <p>This will schedule a bid opening session and set the procurement status to <strong>Closed</strong>. This cannot be undone.</p>
            <div class="urm-modal-user-pill"><?= htmlspecialchars($procurement['slsu_ref_no']) ?> · <?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 55, '…')) ?></div>
        </div>
        <div class="urm-modal-actions">
            <button type="button" onclick="closeScheduleModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="scheduleConfirmBtn" class="urm-btn-confirm urm-btn-confirm--green">
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
        if (count > 0) document.getElementById('inviteError').classList.remove('show');
    }
</script>

<script>
    function openScheduleModal() {
        const count = document.querySelectorAll('#adminInviteGrid input[type="checkbox"]:checked').length;
        if (count === 0) {
            const err = document.getElementById('inviteError');
            err.classList.add('show');
            err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        document.getElementById('inviteError').classList.remove('show');
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
