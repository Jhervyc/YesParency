<?php
include("utils/protect-page.php");

$admin_id = intval($_SESSION['user_id'] ?? 0);

// ── Admin Profile ─────────────────────────────────────────────────────────────
$admin_stmt = $conn->prepare("SELECT user_id, firstname, lastname, username, email, role, profile_picture_url, created_at FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_user = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

$admin_name     = trim(($admin_user['firstname'] ?? '') . ' ' . ($admin_user['lastname'] ?? '')) ?: htmlspecialchars($_SESSION['username'] ?? 'Administrator');
$admin_username = $admin_user['username'] ?? $_SESSION['username'] ?? 'admin';
$admin_email    = $admin_user['email'] ?? 'admin@yesparency.gov';
$admin_role     = strtoupper($admin_user['role'] ?? 'ADMIN');
$admin_since    = !empty($admin_user['created_at']) ? date('M Y', strtotime($admin_user['created_at'])) : '2026';
$admin_pic      = !empty($admin_user['profile_picture_url']) ? '../' . ltrim($admin_user['profile_picture_url'], '/') : (!empty($_SESSION['profile_picture_url']) ? '../' . ltrim($_SESSION['profile_picture_url'], '/') : '');

// ── Procurement stats ───────────────────────────────────────────────────────
$total_procs   = (int)($conn->query("SELECT COUNT(*) FROM procurements")->fetch_row()[0] ?? 0);
$open_procs    = (int)($conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0] ?? 0);
$draft_procs   = (int)($conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'draft'")->fetch_row()[0] ?? 0);
$closed_procs  = (int)($conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'closed'")->fetch_row()[0] ?? 0);
$awarded_procs = (int)($conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'awarded'")->fetch_row()[0] ?? 0);

// ── Calendar: procurements with opening/closing dates ───────────────────────
$cal_result = $conn->query("
    SELECT id, title, slsu_ref_no, abc, opening_date, closing_date, status
    FROM procurements
    WHERE opening_date IS NOT NULL OR closing_date IS NOT NULL
    ORDER BY COALESCE(opening_date, closing_date) ASC
");
$cal_events = [];
if ($cal_result) {
    while ($c = $cal_result->fetch_assoc()) $cal_events[] = $c;
}

// ── Recent Notifications / Announcements (latest 3) ─────────────────────────
$notifs_dash_result = $conn->query("
    SELECT sn.notification_id, sn.title, sn.message, sn.target_type, sn.target_role, sn.created_at,
           u.firstname, u.lastname
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    ORDER BY sn.created_at DESC
    LIMIT 3
");

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

function pct($part, $total) {
    return $total > 0 ? round($part / $total * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <style>
        /* ── 60% + 40% Layout ── */
        .dash-layout-60-40 {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 22px;
            align-items: start;
            margin-bottom: 24px;
        }

        @media (max-width: 1120px) {
            .dash-layout-60-40 {
                grid-template-columns: 1fr;
            }
        }

        /* ── Green Greetings Card ── */
        .dash-greeting-card {
            background: linear-gradient(135deg, #06251b 0%, #0b3829 55%, #14593f 100%);
            border-radius: 20px;
            padding: 24px 28px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(6, 37, 27, 0.16);
            margin-bottom: 18px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .dash-greeting-card::after {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.15) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .greeting-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .greeting-title {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
            letter-spacing: -0.3px;
        }

        .greeting-badge {
            background: rgba(255, 193, 7, 0.18);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .greeting-sub {
            font-size: 13px;
            color: #d1e5db;
            line-height: 1.5;
            max-width: 600px;
            margin-bottom: 16px;
        }

        .greeting-pills {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .greeting-pill {
            background: rgba(255, 255, 255, 0.09);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 6px 13px;
            border-radius: 11px;
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .greeting-pill i {
            color: #ffc107;
        }

        /* ── Compact Stat Cards (Reduced Height) ── */
        .compact-stat-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
        }

        .compact-stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f4f2;
        }

        .compact-stat-title {
            font-size: 13px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .compact-stat-link,
        .sub-card-link,
        .side-panel-link {
            font-size: 11px;
            font-weight: 700;
            color: #1f7a3d;
            background: #eef7f1;
            padding: 4px 10px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .15s ease;
            line-height: 1.2;
        }

        .compact-stat-link:hover,
        .sub-card-link:hover,
        .side-panel-link:hover {
            background: #06251b;
            color: #ffc107;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(6, 37, 27, 0.12);
        }

        .compact-stat-body {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .compact-donut-wrap {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
        }

        .compact-donut-inner {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        .compact-donut-num {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
        }

        .compact-stat-metrics {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .compact-metric-item {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 10px;
            padding: 7px 10px;
            text-align: center;
        }

        .compact-metric-num {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.1;
        }

        .compact-metric-lbl {
            font-size: 9.5px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-top: 2px;
        }

        /* ── Side-by-Side: Applications & Bids (60% Column) ── */
        .dash-side-by-side {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .dash-side-by-side {
                grid-template-columns: 1fr;
            }
        }

        .sub-card {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .sub-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 16px 11px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .sub-card-title {
            font-size: 13px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 7px;
        }



        .sub-card-list {
            padding: 2px 0;
            flex: 1;
        }

        .sub-item-row {
            padding: 10px 14px;
            border-bottom: 1px solid #f4f7f5;
            transition: background .15s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sub-item-row:last-child {
            border-bottom: none;
        }

        .sub-item-row:hover {
            background: #f9fbf9;
        }

        .sub-item-main {
            flex: 1;
            min-width: 0;
        }

        .sub-item-title {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }

        .sub-item-meta {
            font-size: 10.5px;
            color: #88968d;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Profile Card (40% Column) ── */
        .profile-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 18px;
        }

        .profile-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0f4f2;
        }

        .profile-avatar-box {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, #06251b 0%, #1f7a3d 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.15);
            overflow: hidden;
        }

        .profile-avatar-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-biz-name {
            font-size: 14.5px;
            font-weight: 800;
            color: #06251b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .profile-rep-name {
            font-size: 11.5px;
            color: #6C776E;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .profile-status-badge {
            font-size: 9.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-top: 4px;
            background: #e4f5ea;
            color: #1f7a3d;
        }

        .profile-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .profile-detail-item {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 10px;
            padding: 7px 9px;
        }

        .profile-detail-lbl {
            font-size: 9px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 2px;
        }

        .profile-detail-val {
            font-size: 11px;
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Compact Bid Calendar & Schedules (40% Column) ── */
        .side-panel-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 18px;
            overflow: hidden;
        }

        .side-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .side-panel-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .mini-cal-container {
            padding: 14px 18px;
        }

        .mini-cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .mini-cal-month {
            font-weight: 800;
            font-size: 13px;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
        }

        .mini-cal-btn {
            background: #eef2f0;
            border: none;
            width: 26px;
            height: 26px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #06251b;
            font-size: 11px;
            transition: all .15s;
        }

        .mini-cal-btn:hover {
            background: #06251b;
            color: #ffc107;
        }

        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            text-align: center;
        }

        .mini-cal-day-head {
            font-size: 10px;
            font-weight: 700;
            color: #88968d;
            padding: 4px 0;
            text-transform: uppercase;
        }

        .mini-cal-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            color: #1a1a1a;
            position: relative;
            cursor: default;
            transition: all .12s;
        }

        .mini-cal-cell.empty {
            visibility: hidden;
        }

        .mini-cal-cell.has-event {
            background: #f0f7f3;
            color: #06251b;
            font-weight: 800;
            cursor: pointer;
        }

        .mini-cal-cell.has-event:hover {
            background: #06251b;
            color: #ffc107;
        }

        .mini-cal-cell.is-today {
            border: 1.5px solid #1f7a3d;
        }

        .mini-cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #e67e22;
            position: absolute;
            bottom: 2px;
        }

        .mini-cal-cell:hover .mini-cal-dot {
            background: #ffc107;
        }

        .side-events-list {
            padding: 8px 18px 14px;
            border-top: 1px solid #f0f4f2;
            max-height: 180px;
            overflow-y: auto;
        }

        .side-event-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid #f0f4f2;
            font-size: 11.5px;
        }

        .side-event-item:last-child {
            border-bottom: none;
        }

        .side-event-title {
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }

        .side-event-date {
            font-size: 9.5px;
            color: #88968d;
            font-weight: 600;
            white-space: nowrap;
        }

        /* ── Event Viewer Modal ── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(6, 37, 27, 0.45);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            padding: 16px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            animation: modalPopIn .2s cubic-bezier(.34,1.56,.64,1);
        }

        @keyframes modalPopIn {
            from { transform: scale(0.94); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            background: #fafcfb;
            border-bottom: 1px solid #f0f4f2;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h4 {
            font-size: 15px;
            font-weight: 800;
            color: #06251b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            background: none;
            border: none;
            color: #88968d;
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            display: flex;
        }

        .modal-close:hover {
            color: #06251b;
            background: #eef2f0;
        }

        .modal-body {
            padding: 20px 22px;
            max-height: 380px;
            overflow-y: auto;
        }

        /* Notification item in 60% column */
        .side-notif-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #f4f7f5;
            transition: background .15s;
        }

        .side-notif-row:last-child { border-bottom: none; }
        .side-notif-row:hover { background: #f9fbf9; }

        .side-notif-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #e8f5e9;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .side-notif-body { flex: 1; min-width: 0; }
        .side-notif-title {
            font-size: 12px;
            font-weight: 700;
            color: #182019;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .side-notif-snippet {
            font-size: 11px;
            color: #6C776E;
            margin-top: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .side-notif-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: #9aa8a1;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Dashboard';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ════════════════════════════════════════════════════════════
         TWO-COLUMN LAYOUT (60% LEFT + 40% RIGHT)
         ════════════════════════════════════════════════════════════ -->
    <div class="dash-layout-60-40">

        <!-- ════════════════════════════════════════════════════════
             LEFT COLUMN (60%)
             1. Greetings Card
             2. Procurement Overview
             3. Notifications
             ════════════════════════════════════════════════════════ -->
        <div class="dash-col-60">

            <!-- 1. Greetings Card -->
            <div class="dash-greeting-card">
                <div class="greeting-header">
                    <div class="greeting-title">Welcome back, <?= htmlspecialchars($admin_name) ?> </div>
                    <span class="greeting-badge">
                        <i class="bi bi-shield-check"></i> <?= htmlspecialchars($_SESSION['admin_type'] ?? 'ADMIN') ?>
                    </span>
                </div>
                <div class="greeting-sub">
                    Municipal Bids &amp; Awards Committee Portal — Monitor active procurements and stay updated with announcements in real-time.
                </div>
                <div class="greeting-pills">
                    <div class="greeting-pill">
                        <i class="bi bi-folder2-open"></i> <strong><?= $open_procs ?></strong> Open Opportunities
                    </div>
                    <div class="greeting-pill">
                        <i class="bi bi-calendar3"></i> <?= date('F j, Y') ?>
                    </div>
                </div>
            </div>

            <!-- 2. Procurement Statistics (Reduced Height) -->
            <div class="compact-stat-card">
                <div class="compact-stat-top">
                    <div class="compact-stat-title">
                        <i class="bi bi-pie-chart-fill" style="color:#1f7a3d;"></i> Procurement Overview
                    </div>
                    <a href="bid_submissions.php" class="compact-stat-link">View Bid Opening <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="compact-stat-body">
                    <div class="compact-donut-wrap" style="background:conic-gradient(#1f7a3d 0% <?= pct($open_procs, max($total_procs,1)) ?>%, #c23b3b 0% <?= pct($open_procs+$closed_procs, max($total_procs,1)) ?>%, #f0b92e 0% 100%);">
                        <div class="compact-donut-inner">
                            <span class="compact-donut-num"><?= $total_procs ?></span>
                            <span style="font-size:8px; color:#88968d; font-weight:700;">TOTAL</span>
                        </div>
                    </div>
                    <div class="compact-stat-metrics">
                        <div class="compact-metric-item">
                            <div class="compact-metric-num" style="color:#1f7a3d;"><?= $open_procs ?></div>
                            <div class="compact-metric-lbl">Open</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num" style="color:#f0b92e;"><?= $draft_procs ?></div>
                            <div class="compact-metric-lbl">Draft</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num" style="color:#c23b3b;"><?= $closed_procs ?></div>
                            <div class="compact-metric-lbl">Closed</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num" style="color:#2F6FED;"><?= $awarded_procs ?></div>
                            <div class="compact-metric-lbl">Awarded</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Notifications -->
            <div class="sub-card">
                <div class="sub-card-head">
                    <div class="sub-card-title">
                        <i class="bi bi-bell" style="color:#e67e22;"></i> Notifications
                    </div>
                    <a href="notification.php" class="sub-card-link">View All <i class="bi bi-arrow-right"></i></a>
                </div>
                <div>
                    <?php if (!$notifs_dash_result || $notifs_dash_result->num_rows === 0): ?>
                        <div style="padding:26px 16px; text-align:center; color:#88968d; font-size:12px;">
                            <i class="bi bi-bell-slash" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                            No announcements posted yet.
                        </div>
                    <?php else: while ($nt = $notifs_dash_result->fetch_assoc()):
                        $targetBadge = strtoupper($nt['target_type']);
                        $targetColor = '#e3f2fd';
                        $targetFg    = '#1565c0';
                        if ($nt['target_type'] === 'role') {
                            $targetBadge = strtoupper($nt['target_role'] ?? 'ROLE');
                            $targetColor = '#f3e5f5';
                            $targetFg    = '#7b1fa2';
                        }
                    ?>
                        <div class="side-notif-row">
                            <div class="side-notif-icon">
                                <i class="bi bi-bell-fill"></i>
                            </div>
                            <div class="side-notif-body">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                    <div class="side-notif-title" title="<?= htmlspecialchars($nt['title']) ?>">
                                        <?= htmlspecialchars($nt['title']) ?>
                                    </div>
                                    <span style="font-size:10px; color:#88968d; white-space:nowrap;">
                                        <i class="bi bi-clock"></i> <?= timeAgo($nt['created_at']) ?>
                                    </span>
                                </div>
                                <div class="side-notif-snippet">
                                    <?= htmlspecialchars($nt['message']) ?>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:4px; gap:8px;">
                                    <div class="side-notif-meta">
                                        <span style="background:<?= $targetColor ?>; color:<?= $targetFg ?>; padding:1px 6px; border-radius:4px; font-weight:700; font-size:9.5px;">
                                            <?= htmlspecialchars($targetBadge) ?>
                                        </span>
                                        <span>By: <?= htmlspecialchars(trim(($nt['firstname'] ?? '') . ' ' . ($nt['lastname'] ?? '')) ?: 'Admin') ?></span>
                                    </div>
                                    <a href="notification.php" class="proc-action-btn review" style="background:#E7EEFE; color:#2F6FED; font-size:11px; padding:4px 10px; border-radius:7px; font-weight:700; text-decoration:none;">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; endif; ?>
                </div>
            </div>

        </div>

        <!-- ════════════════════════════════════════════════════════
             RIGHT COLUMN (40%)
             1. Admin Profile Card
             2. Bid Calendar & Schedules
             ════════════════════════════════════════════════════════ -->
        <div class="dash-col-40">

            <!-- 1. Profile Card -->
            <div class="profile-card">
                <div class="profile-top">
                    <div class="profile-avatar-box">
                        <?php if (!empty($admin_pic)): ?>
                            <img src="<?= htmlspecialchars($admin_pic) ?>" alt="<?= htmlspecialchars($admin_name) ?>">
                        <?php else: ?>
                            <?= strtoupper(substr($admin_name, 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <div class="profile-biz-name"><?= htmlspecialchars($admin_name) ?></div>
                        <div class="profile-rep-name">
                            <i class="bi bi-at"></i> <?= htmlspecialchars($admin_username) ?>
                        </div>
                        <span class="profile-status-badge">
                            <i class="bi bi-shield-check"></i> <?= $admin_role ?>
                        </span>
                    </div>
                </div>

                <div class="profile-details-grid">
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">Account Role</div>
                        <div class="profile-detail-val">Administrator</div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">Member Since</div>
                        <div class="profile-detail-val"><?= $admin_since ?></div>
                    </div>
                    <div class="profile-detail-item" style="grid-column: span 2;">
                        <div class="profile-detail-lbl">Official Email</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($admin_email) ?>"><?= htmlspecialchars($admin_email) ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Bid Calendar & Schedules Card -->
            <div class="side-panel-card">
                <div class="side-panel-head">
                    <div class="side-panel-title">
                        <i class="bi bi-calendar3" style="color:#1f7a3d;"></i>
                        <span>Schedule of Activities</span>
                    </div>
                    <a href="bid_submissions.php" class="side-panel-link">View all <i class="bi bi-arrow-right"></i></a>
                </div>

                <div class="mini-cal-container">
                    <div class="mini-cal-nav">
                        <button type="button" class="mini-cal-btn" onclick="prevMonth()"><i class="bi bi-chevron-left"></i></button>
                        <div class="mini-cal-month" id="calMonthLabel">Loading...</div>
                        <button type="button" class="mini-cal-btn" onclick="nextMonth()"><i class="bi bi-chevron-right"></i></button>
                    </div>

                    <div class="mini-cal-grid" id="calGrid">
                        <!-- Filled by JS -->
                    </div>
                </div>

                <div style="display:flex; justify-content:space-around; padding:6px 12px 8px; font-size:10.5px; color:#777; border-top:1px solid #f0f4f2; background:#fafcfb;">
                    <span style="display:flex; align-items:center; gap:4px;">
                        <span style="width:6px; height:6px; border-radius:50%; background:#1f7a3d; display:inline-block;"></span> Bid Opening
                    </span>
                    <span style="display:flex; align-items:center; gap:4px;">
                        <span style="width:6px; height:6px; border-radius:50%; background:#e67e22; display:inline-block;"></span> Deadline
                    </span>
                </div>

                <!-- Event list for the selected month/day -->
                <div class="side-events-list" id="sideEventsList">
                    <!-- Filled by JS -->
                </div>
            </div>

        </div>

    </div>

    <!-- ════════════════════════════════════════════════════════════
         BOTTOM: QUICK ACTIONS (100% WIDTH)
         ════════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Quick Actions</div>
    <div class="sad-actions">
        <a href="bid_submissions.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e8f5e9; color:#43a047;"><i class="bi bi-broadcast"></i></div>
            <span>Bid Opening</span>
        </a>
        <a href="notification.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fff8e1; color:#f9a825;"><i class="bi bi-bell"></i></div>
            <span>Notifications</span>
        </a>
        <a href="audit_trail.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#f3e5f5; color:#7b1fa2;"><i class="bi bi-journal-text"></i></div>
            <span>Audit Trail</span>
        </a>
        <a href="settings.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e0f2f1; color:#00796b;"><i class="bi bi-gear"></i></div>
            <span>Settings</span>
        </a>
        <a href="../logout.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fce4ec; color:#c62828;"><i class="bi bi-box-arrow-left"></i></div>
            <span>Logout</span>
        </a>
    </div>

</div>
</main>

<!-- Event Viewer Modal -->
<div class="modal-backdrop" id="calEventModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-calendar-event" style="color:#1f7a3d;"></i> <span id="calModalDate">Activities</span></h4>
            <button type="button" class="modal-close" onclick="document.getElementById('calEventModal').classList.remove('open')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body" id="calModalBody">
            <!-- Filled dynamically -->
        </div>
    </div>
</div>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    // ── Mini Calendar JS ──
    const calEvents = <?= json_encode($cal_events) ?>;
    let currentCalDate = new Date();

    function renderMiniCalendar() {
        const month = currentCalDate.getMonth();
        const year  = currentCalDate.getFullYear();

        const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
        document.getElementById('calMonthLabel').textContent = `${monthNames[month]} ${year}`;

        const grid = document.getElementById('calGrid');
        grid.innerHTML = '';

        const daysHeader = ['Su','Mo','Tu','We','Th','Fr','Sa'];
        daysHeader.forEach(d => {
            const h = document.createElement('div');
            h.className = 'mini-cal-day-head';
            h.textContent = d;
            grid.appendChild(h);
        });

        const firstDayIndex = new Date(year, month, 1).getDay();
        const lastDay = new Date(year, month + 1, 0).getDate();

        // Empty cells before the first day
        for (let i = 0; i < firstDayIndex; i++) {
            const empty = document.createElement('div');
            empty.className = 'mini-cal-cell empty';
            grid.appendChild(empty);
        }

        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

        for (let day = 1; day <= lastDay; day++) {
            const cell = document.createElement('div');
            cell.className = 'mini-cal-cell';
            cell.textContent = day;

            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

            if (dateStr === todayStr) {
                cell.classList.add('is-today');
            }

            // Find events on this date
            const matches = calEvents.filter(e => {
                const openD  = e.opening_date ? e.opening_date.split(' ')[0] : null;
                const closeD = e.closing_date ? e.closing_date.split(' ')[0] : null;
                return openD === dateStr || closeD === dateStr;
            });

            if (matches.length > 0) {
                cell.classList.add('has-event');
                const dot = document.createElement('div');
                dot.className = 'mini-cal-dot';
                cell.appendChild(dot);

                cell.onclick = () => openDayEventsModal(dateStr, matches);
            }

            grid.appendChild(cell);
        }

        renderSideEventsList(month, year);
    }

    function renderSideEventsList(month, year) {
        const listEl = document.getElementById('sideEventsList');
        listEl.innerHTML = '';

        const monthEvents = calEvents.filter(e => {
            const openD  = e.opening_date ? new Date(e.opening_date) : null;
            const closeD = e.closing_date ? new Date(e.closing_date) : null;
            return (openD && openD.getMonth() === month && openD.getFullYear() === year) ||
                   (closeD && closeD.getMonth() === month && closeD.getFullYear() === year);
        });

        if (monthEvents.length === 0) {
            listEl.innerHTML = '<div style="font-size:11px; color:#88968d; padding:10px 0; text-align:center;">No scheduled events this month.</div>';
            return;
        }

        monthEvents.slice(0, 5).forEach(e => {
            const item = document.createElement('div');
            item.className = 'side-event-item';
            const d = e.opening_date || e.closing_date;
            const dateObj = new Date(d);
            const fDate = dateObj.toLocaleDateString('en-US', { month:'short', day:'numeric' });
            const isOpening = Boolean(e.opening_date);
            const typeLabel = isOpening ? 'Bid Opening' : 'Deadline';
            const typeColor = isOpening ? '#1f7a3d' : '#e67e22';

            item.innerHTML = `
                <div style="min-width:0; flex:1;">
                    <div class="side-event-title" title="${e.title}">${e.title}</div>
                    <span style="font-size:9.5px; color:${typeColor}; font-weight:700; text-transform:uppercase;">${typeLabel}</span>
                </div>
                <span class="side-event-date">${fDate}</span>
            `;
            listEl.appendChild(item);
        });
    }

    function prevMonth() {
        currentCalDate.setMonth(currentCalDate.getMonth() - 1);
        renderMiniCalendar();
    }

    function nextMonth() {
        currentCalDate.setMonth(currentCalDate.getMonth() + 1);
        renderMiniCalendar();
    }

    function openDayEventsModal(dateStr, events) {
        const modal = document.getElementById('calEventModal');
        const dateTitle = new Date(dateStr).toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
        document.getElementById('calModalDate').textContent = dateTitle;

        const body = document.getElementById('calModalBody');
        body.innerHTML = '';

        events.forEach(e => {
            const card = document.createElement('div');
            card.style.cssText = 'padding:14px; background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; margin-bottom:10px;';
            
            let typeBadge = '';
            if (e.opening_date && e.opening_date.includes(dateStr)) {
                typeBadge = '<span style="background:#e4f5ea; color:#1f7a3d; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase;">BID OPENING</span>';
            } else {
                typeBadge = '<span style="background:#fff3e0; color:#e67e22; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase;">SUBMISSION DEADLINE</span>';
            }

            card.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                    ${typeBadge}
                    <span style="font-size:11px; color:#88968d; font-weight:600;">Ref: ${e.slsu_ref_no || 'N/A'}</span>
                </div>
                <div style="font-weight:700; font-size:13px; color:#1a2a20; margin-bottom:8px; line-height:1.35;">${e.title}</div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
                    <span style="font-family:'Space Grotesk',sans-serif; font-weight:800; font-size:12.5px; color:#06251b;">
                        ${e.abc ? '₱' + Number(e.abc).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) : ''}
                    </span>
                    <a href="procurement.php" class="proc-action-btn review" style="background:#06251b; color:#ffc107; font-size:11px; padding:4px 10px; border-radius:7px; font-weight:700; text-decoration:none;">
                        <i class="bi bi-eye"></i> View Procurement
                    </a>
                </div>
            `;
            body.appendChild(card);
        });

        modal.classList.add('open');
    }

    // Initialize calendar on page load
    document.addEventListener('DOMContentLoaded', () => {
        renderMiniCalendar();
    });
</script>

</body>
</html>
