<?php
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';

// ── Resolve admin type & manage permission ─────────────────────────────────
if (!isset($_SESSION['admin_type']) && isset($conn)) {
    $at = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $at->bind_param("i", $_SESSION['user_id']);
    $at->execute();
    $at_row = $at->get_result()->fetch_assoc();
    $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
    $at->close();
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$user_role  = $_SESSION['role'] ?? 'admin';
$can_manage = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

// ── Open Now — transition scheduled session to eligibility (go live) ───────
if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_now'])) {
    $session_id = (int)($_POST['session_id'] ?? 0);
    if ($session_id > 0) {
        $upd = $conn->prepare("
            UPDATE bid_opening_sessions
            SET status = 'eligibility', started_at = NOW()
            WHERE id = ? AND status = 'scheduled'
        ");
        $upd->bind_param("i", $session_id);
        $upd->execute();
        $upd->close();
    }
    // PRG — prevent resubmit on refresh
    header("Location: bid_opening.php");
    exit();
}

// ── Filters & Pagination ───────────────────────────────────────────────────
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$mode_filter = isset($_GET['mode'])   ? trim($_GET['mode'])   : 'all';
$sort        = isset($_GET['sort']) && in_array($_GET['sort'], ['closing_asc','closing_desc','abc_desc','abc_asc','newest'])
               ? $_GET['sort'] : 'closing_asc';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 12;
$offset      = ($page - 1) * $per_page;

// ── Current active bid opening session ────────────────────────────────────
$live_session = null;

$ls_res = $conn->query("
    SELECT bos.id AS session_id, bos.status AS session_status,
           bos.started_at, bos.stream_path,
           p.id AS proc_id, p.title AS proc_title, p.philgeps_ref_no
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status IN ('eligibility','financial','awarding')
    ORDER BY bos.started_at DESC
    LIMIT 1
");
if ($ls_res) $live_session = $ls_res->fetch_assoc();

// ── Scheduled sessions (up to 3) ──────────────────────────────────────────
$scheduled_res = $conn->query("
    SELECT bos.id AS session_id, bos.created_at, bos.stream_path,
           p.title AS proc_title, p.philgeps_ref_no, p.procurement_mode,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status = 'scheduled'
    ORDER BY bos.created_at ASC
    LIMIT 3
");
$scheduled_sessions = $scheduled_res ? $scheduled_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Available procurement modes for dropdown ───────────────────────────────
$modes_res   = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE status = 'open' AND procurement_mode IS NOT NULL AND procurement_mode != '' ORDER BY procurement_mode ASC");
$avail_modes = [];
if ($modes_res) while ($mr = $modes_res->fetch_assoc()) $avail_modes[] = $mr['procurement_mode'];

// ── Set of procurement IDs that already have a session (any status) ────────
$scheduled_proc_ids = [];
$sp_res = $conn->query("SELECT DISTINCT procurement_id FROM bid_opening_sessions");
if ($sp_res) while ($sp = $sp_res->fetch_row()) $scheduled_proc_ids[] = (int)$sp[0];

// ── Query for procurement table (open only) ────────────────────────────────
$where_parts = ["p.status = 'open'"];
$params      = [];
$types       = '';

if ($mode_filter !== 'all' && $mode_filter !== '') {
    $where_parts[] = "p.procurement_mode = ?";
    $params[] = $mode_filter; $types .= 's';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(p.title LIKE ? OR p.philgeps_ref_no LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$order_sql = "ORDER BY p.closing_date ASC, p.id DESC";
if ($sort === 'closing_desc') $order_sql = "ORDER BY p.closing_date DESC, p.id DESC";
elseif ($sort === 'abc_desc') $order_sql = "ORDER BY p.abc DESC, p.id DESC";
elseif ($sort === 'abc_asc')  $order_sql = "ORDER BY p.abc ASC, p.id DESC";
elseif ($sort === 'newest')   $order_sql = "ORDER BY p.id DESC";

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM procurements p $where_sql");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, ceil($total_shown / $per_page));

// Main list
$main = $conn->prepare("
    SELECT p.id, p.philgeps_ref_no, p.title, p.abc, p.procurement_mode,
           p.closing_date, p.opening_date, p.status,
           (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM procurements p
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
");
$mp = array_merge($params, [$per_page, $offset]);
$mt = $types . 'ii';
$main->bind_param($mt, ...$mp);
$main->execute();
$procs = $main->get_result();

// ── Summary stats ──────────────────────────────────────────────────────────
$stat_total   = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0];
$stat_open    = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date >= NOW()")->fetch_row()[0];
$stat_closed  = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_row()[0];
$stat_sessions= (int)$conn->query("SELECT COUNT(*) FROM bid_opening_sessions")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Opening | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Stat Cards ── */
        .bo-stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        @media(min-width:640px){ .bo-stat-grid{ grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; } }

        /* ── Live Banner ── */
        .bo-live-banner {
            background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #14593f 100%);
            border-radius: 0;
            padding: 16px;
            color: #fff;
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 0;
            box-shadow: none;
            border: none;
        }
        @media(min-width:640px){
            .bo-live-banner {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                padding: 20px 24px;
            }
        }

        .bo-live-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
            letter-spacing: .4px;
            animation: pulseLive 1.8s infinite;
        }

        @keyframes pulseLive {
            0%,100% { opacity:1; }
            50%      { opacity:.7; }
        }

        .bo-live-dot { width:7px; height:7px; border-radius:50%; background:#fff; }
        .bo-live-info { flex:1; min-width:0; }

        .bo-live-title {
            font-size: 15px; font-weight: 800; color: #fff;
            margin-bottom: 4px; line-height: 1.3;
        }
        @media(min-width:640px){ .bo-live-title{ font-size:17px; } }

        .bo-live-meta {
            font-size: 11.5px; color: #d1e5db;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .bo-live-meta span { display:inline-flex; align-items:center; gap:5px; }
        .bo-live-meta i   { color:#ffc107; }

        .bo-live-actions {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .bo-live-actions a { flex:1; justify-content:center; }
        @media(min-width:640px){
            .bo-live-actions { flex-shrink:0; flex-wrap:nowrap; }
            .bo-live-actions a { flex:none; }
        }

        .bo-btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            background: #ffc107; color: #06251b;
            font-size: 12.5px; font-weight: 800;
            padding: 9px 18px; border-radius: 10px;
            text-decoration: none; border: none; cursor: pointer;
            transition: all .15s;
        }
        .bo-btn-primary:hover { background: #e6ac00; }

        .bo-btn-outline {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.2);
            color: #fff;
            font-size: 12.5px; font-weight: 700;
            padding: 9px 18px; border-radius: 10px;
            text-decoration: none; cursor: pointer; transition: all .15s;
        }
        .bo-btn-outline:hover { background: rgba(255,255,255,.2); }

        /* ── Proc Table Panel ── */
        .proc-table-panel {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .proc-table { width:100%; border-collapse:collapse; font-size:12px; text-align:left; }
        .proc-table thead th {
            background:#fafcfb; padding:13px 16px;
            font-size:11px; font-weight:700; color:#55665a;
            text-transform:uppercase; letter-spacing:.04em;
            border-bottom:1.5px solid #edf1ef; white-space:nowrap;
        }
        .proc-table tbody tr { border-bottom:1px solid #f0f4f2; transition:background .12s; }
        .proc-table tbody tr:last-child { border-bottom:none; }
        .proc-table tbody tr:hover { background:#fbfdfc; }
        .proc-table td { padding:14px 16px; vertical-align:middle; }

        /* Hide less-important columns on mobile */
        .col-mode, .col-abc, .col-opening, .col-status { display:none; }
        @media(min-width:640px){ .col-mode, .col-status { display:table-cell; } }
        @media(min-width:900px){ .col-abc, .col-opening { display:table-cell; } }

        .proc-ref-cell { font-weight:700; color:#06251b; font-size:11.5px; white-space:nowrap; }
        .proc-title-cell { font-weight:700; color:#1a2a20; font-size:13px; line-height:1.35; }
        .proc-title-cell a { color:inherit; text-decoration:none; }
        .proc-title-cell a:hover { color:#1f7a3d; text-decoration:underline; }
        .proc-mode-tag {
            background:#f0f4f2; color:#384d40;
            padding:3px 8px; border-radius:6px;
            font-size:10.5px; font-weight:600; white-space:nowrap;
        }
        .proc-abc-cell {
            font-family:'Space Grotesk',sans-serif;
            font-size:13px; font-weight:800; color:#06251b; white-space:nowrap;
        }
        .proc-deadline-cell { font-size:11.5px; color:#63736a; white-space:nowrap; line-height:1.3; }
        .proc-deadline-cell strong { color:#1a1a1a; display:block; }
        .proc-status-pill {
            display:inline-flex; align-items:center; gap:4px;
            font-size:10.5px; font-weight:700;
            padding:3px 9px; border-radius:20px; white-space:nowrap;
        }
        .status-open    { background:#e4f5ea; color:#1f7a3d; }
        .status-closed  { background:#fee2e2; color:#dc2626; }
        .status-awarded { background:#e0f2f1; color:#00796b; }
        .status-draft   { background:#f3f4f6; color:#6b7280; }

        .proc-action-btn {
            display:inline-flex; align-items:center; gap:5px;
            padding:6px 13px; border-radius:8px;
            font-size:11.5px; font-weight:700;
            text-decoration:none; transition:all .15s;
            white-space:nowrap; cursor:pointer; border:none;
        }
        .btn-view       { background:#f0f4f2; color:#06251b; }
        .btn-view:hover { background:#06251b; color:#ffc107; }
        .btn-schedule   { background:#ffc107; color:#06251b; }
        .btn-schedule:hover { background:#e6ac00; }
        .btn-open       { background:#1f7a3d; color:#fff; }
        .btn-open:hover { background:#14592d; }

        /* ── Filter bar ── */
        .filter-bar {
            padding:12px 14px;
            border-bottom:1px solid #f0f4f2;
            background:#fafcfb;
            display:flex; flex-direction:column; gap:8px;
        }
        @media(min-width:640px){
            .filter-bar { flex-direction:row; align-items:center; padding:16px 20px; gap:10px; flex-wrap:wrap; }
        }
        .ap2-search-field {
            width:100%; position:relative; display:flex; align-items:center;
        }
        @media(min-width:640px){ .ap2-search-field{ flex:1; min-width:200px; } }
        .ap2-search-field i {
            position:absolute; left:11px; color:#88968d;
            font-size:13px; pointer-events:none;
        }
        .ap2-search-field input {
            width:100%; padding:9px 12px 9px 33px;
            border:1.5px solid #e0e8e4; border-radius:9px;
            font-size:12px; font-family:'Poppins',sans-serif;
            color:#1a1a1a; background:#fafcfb; outline:none;
            transition:border-color .15s, box-shadow .15s;
        }
        .ap2-search-field input:focus {
            border-color:#1f7a3d; background:#fff;
            box-shadow:0 0 0 3px rgba(31,122,61,.08);
        }
        .filter-dropdowns {
            display:flex; gap:8px; flex-wrap:wrap;
        }
        .filter-dropdowns select {
            border:1.5px solid #eaeeec; background:#eef2f0; color:#16241d;
            font-family:'Poppins',sans-serif; font-size:12.5px; font-weight:600;
            padding:8px 12px; border-radius:9px; outline:none; cursor:pointer;
            flex:1; min-width:120px;
        }
        .ap2-go-btn {
            display:inline-flex; align-items:center; justify-content:center; gap:7px;
            background:#ffc107; color:#16241d;
            font-family:'Poppins',sans-serif; font-weight:700;
            font-size:12.5px; border:none; padding:9px 18px;
            border-radius:9px; cursor:pointer; transition:all .15s; white-space:nowrap;
            width:100%;
        }
        @media(min-width:640px){ .ap2-go-btn{ width:auto; } }
        .ap2-go-btn:hover { background:#e6ac00; }

        .table-foot {
            padding:12px 16px;
            display:flex; align-items:center; justify-content:space-between;
            gap:10px; flex-wrap:wrap;
            border-top:1px solid #f0f4f2;
            font-size:12px; color:#88968d;
        }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; }
        .page-link {
            display:inline-flex; align-items:center; justify-content:center;
            width:32px; height:32px; border-radius:8px;
            font-size:12px; font-weight:700; text-decoration:none;
            color:#06251b; background:#f0f4f2; transition:all .15s;
        }
        .page-link:hover, .page-link.active { background:#06251b; color:#ffc107; }
        .page-link.disabled { opacity:.4; pointer-events:none; }

        /* ── Scheduled sessions panel ── */
        .sched-panel {
            background:#fff;
            border:1px solid #eaeeec;
            border-radius:18px;
            box-shadow:0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow:hidden;
        }
        .sched-panel-head {
            display:flex; align-items:center; justify-content:space-between;
            padding:12px 16px;
            border-bottom:1px solid #f0f4f2;
            background:#fafcfb;
        }
        @media(min-width:640px){ .sched-panel-head{ padding:14px 18px; } }
        .sched-list { display:flex; flex-direction:column; }
        .sched-card {
            background:#fff;
            padding:12px 14px;
            display:flex; align-items:center; gap:10px;
            border-bottom:1px solid #f0f4f2;
            transition:background .12s;
        }
        @media(min-width:640px){ .sched-card{ padding:14px 18px; gap:14px; } }
        .sched-card:last-child { border-bottom:none; }
        .sched-card:hover { background:#fbfdfc; }
        .sched-icon {
            width:36px; height:36px; border-radius:10px;
            background:#fef8e7; color:#d97706;
            display:flex; align-items:center; justify-content:center;
            font-size:16px; flex-shrink:0;
        }
        @media(min-width:640px){ .sched-icon{ width:40px; height:40px; font-size:18px; } }
        .sched-info { flex:1; min-width:0; }
        .sched-title {
            font-size:12.5px; font-weight:700; color:#06251b;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            margin-bottom:3px;
        }
        @media(min-width:640px){ .sched-title{ font-size:13px; } }
        .sched-meta {
            font-size:10.5px; color:#88968d;
            display:flex; align-items:center; gap:8px; flex-wrap:wrap;
        }
        .sched-pill {
            font-size:10px; font-weight:700; padding:2px 7px;
            border-radius:5px; background:#fef3c7; color:#d97706;
            display:inline-flex; align-items:center; gap:4px;
        }
        .btn-open-now {
            display:inline-flex; align-items:center; gap:5px;
            background:#1f7a3d; color:#fff;
            font-size:11.5px; font-weight:700; padding:6px 12px;
            border-radius:9px; text-decoration:none; border:none;
            cursor:pointer; transition:all .15s; white-space:nowrap; flex-shrink:0;
        }
        @media(min-width:640px){ .btn-open-now{ font-size:12px; padding:7px 15px; } }
        .btn-open-now:hover { background:#14592d; }
        .sched-empty {
            padding:24px 16px; text-align:center;
            color:#88968d; font-size:12.5px;
        }

        /* ── Section view-more link ── */
        .section-view-more {
            font-size:11.5px; font-weight:700; color:#1f7a3d;
            text-decoration:none; display:inline-flex; align-items:center; gap:4px;
            transition:color .15s; white-space:nowrap;
        }
        .section-view-more:hover { color:#06251b; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Bid Opening'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Page Header ─────────────────────────────────────────────────────── -->
    <div class="page-header">
        <h2>Bid Document Opening</h2>
        <p>Manage and conduct live bid openings, view session history, and schedule upcoming openings.</p>
    </div>

    <!-- ── Summary Stats ──────────────────────────────────────────────────── -->
    <div class="sad-section-label">Overview</div>
    <div class="bo-stat-grid" style="margin-bottom:24px;">
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#1f7a3d 0% <?= $stat_total>0 ? round($stat_open/$stat_total*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-check-circle" style="color:#1f7a3d;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#1f7a3d;"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Active Opportunities</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#e67e22 0% <?= $stat_total>0 ? round($stat_closed/$stat_total*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:#e67e22;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#e67e22;"><?= $stat_closed ?></div>
                <div class="ap2-stat-lbl">Closing in 3 Days</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#e67e22 0% <?= $stat_total>0 ? min(100,round($stat_sessions/$stat_total*100)) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-envelope-open" style="color:#e67e22;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#e67e22;"><?= $stat_sessions ?></div>
                <div class="ap2-stat-lbl">Opening Sessions</div>
            </div>
        </div>
    </div>

    <!-- ── Current Live Session ───────────────────────────────────────────── -->
    <div class="sched-panel" style="margin-bottom:20px;">
        <div class="sched-panel-head">
            <span style="font-size:13.5px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-broadcast" style="color:#dc2626;"></i> Current Live Session
            </span>
            <a href="bid-session-list.php?filter=live" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>

        <?php if ($live_session): ?>
        <div class="bo-live-banner" style="border-radius:0; box-shadow:none; margin-bottom:0; border:none; border-bottom:none;">
            <div style="display:flex; align-items:center; gap:14px; flex:1; min-width:0;">
                <div>
                    <div style="margin-bottom:8px;">
                        <span class="bo-live-pill"><span class="bo-live-dot"></span> LIVE</span>
                    </div>
                    <div class="bo-live-title"><?= htmlspecialchars($live_session['proc_title']) ?></div>
                    <div class="bo-live-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($live_session['philgeps_ref_no']) ?></span>
                        <span><i class="bi bi-activity"></i><?= ucfirst(htmlspecialchars($live_session['session_status'])) ?> phase</span>
                        <?php if ($live_session['started_at']): ?>
                        <span><i class="bi bi-clock"></i>
                            Started <?= date('g:i A', strtotime($live_session['started_at'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="bo-live-actions">
                <?php if ($can_manage): ?>
                <a href="bid_opening_conduct.php?session=<?= $live_session['session_id'] ?>" class="bo-btn-primary">
                    <i class="bi bi-envelope-open-fill"></i> Manage Session
                </a>
                <?php else: ?>
                <a href="bid_opening_conduct.php?session=<?= $live_session['session_id'] ?>" class="bo-btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Join
                </a>
                <?php endif; ?>
                <a href="../live.php" target="_blank" class="bo-btn-outline">
                    <i class="bi bi-broadcast"></i> Live Page
                </a>
            </div>
        </div>
        <?php else: ?>
        <div style="padding:22px 18px; display:flex; align-items:center; gap:14px; color:#55665a; font-size:13px;">
            <i class="bi bi-broadcast" style="font-size:22px; color:#c7d2cb; flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700; color:#374151; margin-bottom:2px;">No Active Live Session</div>
                <div style="font-size:12px;">
                    Schedule a bid opening session from
                    <a href="bid_opening.php" style="color:#1f7a3d; font-weight:700;">Bid Opening</a>
                    using the Schedule button next to any open procurement.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>


    <!-- ── Scheduled Bid Opening Sessions ────────────────────────────────── -->
    <div class="sched-panel" style="margin-bottom:28px;">
        <div class="sched-panel-head">
            <span style="font-size:13.5px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-calendar-event" style="color:#d97706;"></i> Scheduled Bid Opening
            </span>
            <a href="bid-session-list.php?filter=scheduled" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>
        <div class="sched-list">
            <?php if (empty($scheduled_sessions)): ?>
            <div class="sched-empty">
                <i class="bi bi-calendar-x" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                No scheduled bid opening sessions at the moment.
            </div>
            <?php else: ?>
            <?php foreach ($scheduled_sessions as $ss): ?>
            <div class="sched-card">
                <div class="sched-icon"><i class="bi bi-calendar-event"></i></div>
                <div class="sched-info">
                    <div class="sched-title"><?= htmlspecialchars($ss['proc_title']) ?></div>
                    <div class="sched-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($ss['philgeps_ref_no']) ?></span>
                        <?php if ($ss['procurement_mode']): ?>
                        <span><?= htmlspecialchars($ss['procurement_mode']) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-inbox"></i> <?= (int)$ss['bid_count'] ?> bid<?= $ss['bid_count'] != 1 ? 's' : '' ?></span>
                        <span class="sched-pill"><i class="bi bi-clock"></i> Scheduled</span>
                    </div>
                </div>
                <?php if ($can_manage): ?>
                <form method="POST" id="openNowForm-<?= $ss['session_id'] ?>" style="flex-shrink:0;">
                    <input type="hidden" name="session_id" value="<?= $ss['session_id'] ?>">
                    <input type="hidden" name="open_now" value="1">
                    <button type="button" class="btn-open-now"
                        onclick="openNowModal(<?= $ss['session_id'] ?>, '<?= addslashes(htmlspecialchars($ss['proc_title'])) ?>', '<?= addslashes(htmlspecialchars($ss['philgeps_ref_no'])) ?>', '<?= addslashes(htmlspecialchars($ss['stream_path'])) ?>', '<?= addslashes(mediamtx_url($ss['stream_path'])) ?>')">
                        <i class="bi bi-play-fill"></i> Open Now
                    </button>
                </form>
                <?php else: ?>
                <span class="btn-open-now" style="background:#f0f4f2; color:#88968d; cursor:not-allowed; opacity:.6;">
                    <i class="bi bi-play-fill"></i> Open Now
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Procurement Table ──────────────────────────────────────────────── -->
    <div class="sad-section-label">Open Procurements</div>
    <div class="proc-table-panel">

        <!-- Search + Filter bar -->
        <div class="filter-bar">
            <form method="GET" action="" id="procFilterForm" style="display:contents;">
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search title or ref…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-dropdowns">
                    <select name="mode" onchange="document.getElementById('procFilterForm').submit();">
                        <option value="all">All Modes</option>
                        <?php foreach ($avail_modes as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter === $m ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value; document.getElementById('procFilterForm').submit();">
                        <option value="closing_asc"  <?= $sort==='closing_asc'  ? 'selected':'' ?>>Closing Soonest</option>
                        <option value="closing_desc" <?= $sort==='closing_desc' ? 'selected':'' ?>>Closing Latest</option>
                        <option value="abc_desc"     <?= $sort==='abc_desc'     ? 'selected':'' ?>>ABC: High → Low</option>
                        <option value="abc_asc"      <?= $sort==='abc_asc'      ? 'selected':'' ?>>ABC: Low → High</option>
                        <option value="newest"       <?= $sort==='newest'       ? 'selected':'' ?>>Newest</option>
                    </select>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if ($total_shown === 0): ?>
        <div style="padding:48px 20px; text-align:center; color:#88968d;">
            <i class="bi bi-folder2-open" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            <div style="font-size:13px; font-weight:700;">No procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th style="width:120px;">PhilGEPS Ref</th>
                        <th>Title</th>
                        <th class="col-mode">Mode</th>
                        <th class="col-abc">ABC</th>
                        <th class="col-opening">Opening Date</th>
                        <th class="col-status">Status</th>
                        <th style="text-align:right; min-width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $procs->fetch_assoc()):
                    $st      = strtolower($row['status'] ?? 'draft');
                    $stClass = match($st) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded', default=>'status-draft' };
                    $stLabel = ucfirst($st);
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash" style="color:#88968d; font-size:10px;"></i>
                        <?= htmlspecialchars($row['philgeps_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?>
                        </a>
                        <div style="font-size:10.5px; color:#88968d; margin-top:2px;">
                            <i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot<?= $row['lot_count'] != 1 ? 's' : '' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
                    </td>
                    <td class="proc-abc-cell col-abc">₱<?= number_format((float)$row['abc'], 2) ?></td>
                    <td class="proc-deadline-cell col-opening">
                        <?php if (!empty($row['opening_date'])): ?>
                            <strong><?= date('M j, Y', strtotime($row['opening_date'])) ?></strong>
                            <?= date('g:i A', strtotime($row['opening_date'])) ?>
                        <?php else: ?>
                            <span style="color:#c7d2cb;">TBA</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-status">
                        <span class="proc-status-pill <?= $stClass ?>">
                            <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= $stLabel ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <?php if (in_array((int)$row['id'], $scheduled_proc_ids)): ?>
                            <span class="proc-action-btn" style="background:#f0f4f2; color:#88968d; cursor:default;">
                                <i class="bi bi-check-circle-fill" style="color:#1f7a3d;"></i> Scheduled
                            </span>
                            <?php elseif ($can_manage): ?>
                            <a href="schedule_bid_opening.php?procurement=<?= $row['id'] ?>" class="proc-action-btn btn-schedule">
                                <i class="bi bi-calendar-plus"></i> Schedule
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <div>
                Showing <?= min($total_shown, $offset+1) ?>–<?= min($total_shown, $offset+$per_page) ?>
                of <?= number_format($total_shown) ?> open procurement<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search,'mode'=>$mode_filter!=='all'?$mode_filter:null,'sort'=>$sort!=='closing_asc'?$sort:null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php for($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<!-- ── Open Now Confirmation Modal ───────────────────────────────────────── -->
<div id="openNowModal" class="modal-backdrop" onclick="if(event.target===this)closeOpenNowModal()">
    <div class="urm-modal" style="max-width:520px; width:100%;">
        <button class="urm-modal-close" onclick="closeOpenNowModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon" style="background:#e8f5e9; color:#1f7a3d;">
                <i class="bi bi-play-fill"></i>
            </div>
        </div>
        <div class="urm-modal-text">
            <h3>Start Bid Opening Session</h3>
            <p>This will transition the session to <strong>Eligibility Phase</strong> and go live immediately.</p>
            <div class="urm-modal-user-pill" id="openNowPill"></div>
            <div style="margin-top:10px; display:inline-flex; align-items:center; gap:6px; background:#f0f4f2; border-radius:8px; padding:5px 12px; font-size:11.5px; font-weight:700; color:#55665a;">
                <i class="bi bi-broadcast" style="color:#1f7a3d;"></i>
                Stream path: <code id="openNowStreamPath" style="font-size:11.5px; color:#06251b;"></code>
            </div>
        </div>
        <!-- Stream preview -->
        <div id="openNowStreamPreview" style="margin:0 0 20px; border-radius:12px; overflow:hidden; background:#000; aspect-ratio:16/9; display:none;">
            <iframe id="openNowIframe" src="" allow="autoplay; fullscreen" allowfullscreen
                style="width:100%; height:100%; border:none;"></iframe>
        </div>
        <div id="openNowNoStream" style="margin:0 0 20px; background:#f7faf8; border:1.5px dashed #cfdbd4; border-radius:12px; padding:16px; text-align:center; color:#88968d; font-size:12.5px; display:flex; align-items:center; gap:10px; justify-content:center;">
            <i class="bi bi-broadcast" style="font-size:20px; color:#c7d2cb;"></i>
            <span>Stream not live yet — start OBS before opening the session.</span>
        </div>
        <div class="urm-modal-actions">
            <button type="button" onclick="closeOpenNowModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="openNowConfirmBtn" class="urm-btn-confirm" style="background:#1f7a3d;">
                <i class="bi bi-play-fill"></i> Start Session
            </button>
        </div>
    </div>
</div>

</div>
</main>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

<script>
    let _openNowSessionId = null;

    function openNowModal(sessionId, procTitle, refNo, streamPath, streamUrl) {
        _openNowSessionId = sessionId;
        document.getElementById('openNowPill').textContent = procTitle + '  ·  ' + refNo;
        document.getElementById('openNowStreamPath').textContent = streamPath;

        document.getElementById('openNowIframe').src = streamUrl;
        document.getElementById('openNowStreamPreview').style.display = 'block';
        document.getElementById('openNowNoStream').style.display = 'none';

        document.getElementById('openNowModal').classList.add('open');
    }

    function closeOpenNowModal() {
        document.getElementById('openNowModal').classList.remove('open');
        document.getElementById('openNowIframe').src = '';
        _openNowSessionId = null;
    }

    document.getElementById('openNowConfirmBtn').addEventListener('click', function() {
        if (!_openNowSessionId) return;
        document.getElementById('openNowForm-' + _openNowSessionId).submit();
    });
</script>

</body>
</html>
