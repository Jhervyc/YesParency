<?php
include("utils/protect-page.php");

$bidder_id   = intval($_SESSION['user_id']);
$bidder_role = $_SESSION['role'] ?? 'bidder';

// ── 1. Bidder Profile Data ───────────────────────────────────────────────────
$prof_stmt = $conn->prepare("
    SELECT bp.*, u.firstname, u.lastname, u.email AS user_email, u.username, u.created_at AS user_created_at
    FROM users u
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE u.user_id = ?
");
$prof_stmt->bind_param("i", $bidder_id);
$prof_stmt->execute();
$profile = $prof_stmt->get_result()->fetch_assoc();
$prof_stmt->close();

$bidder_name     = trim(($profile['firstname'] ?? '') . ' ' . ($profile['lastname'] ?? '')) ?: htmlspecialchars($_SESSION['username']);
$business_name   = $profile['business_name'] ?? 'Registered Bidder';
$slsu_number = $profile['slsu_number'] ?? 'N/A';
$tin_number      = $profile['tin_number'] ?? 'N/A';
$business_email  = $profile['business_email'] ?? ($profile['user_email'] ?? 'N/A');
$business_phone  = $profile['business_phone'] ?? 'N/A';
$app_status      = strtolower($profile['application_status'] ?? 'approved');

// ── 2. Procurement Stats ─────────────────────────────────────────────────────
$total_procs_res = $conn->query("SELECT COUNT(*) FROM procurements WHERE status != 'draft'");
$total_procs     = ($total_procs_res && $row = $total_procs_res->fetch_row()) ? (int)$row[0] : 0;

$open_procs_res  = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'");
$open_procs      = ($open_procs_res && $row = $open_procs_res->fetch_row()) ? (int)$row[0] : 0;

// ── 3. My Bid Stats ─────────────────────────────────────────────────────────
$my_total_stmt = $conn->prepare("SELECT COUNT(*) FROM bids WHERE bidder_id = ?");
$my_total_stmt->bind_param("i", $bidder_id);
$my_total_stmt->execute();
$my_total_bids = (int)$my_total_stmt->get_result()->fetch_row()[0];
$my_total_stmt->close();

$my_active_stmt = $conn->prepare("SELECT COUNT(*) FROM bids WHERE bidder_id = ? AND status IN ('submitted', 'pending', 'opened')");
$my_active_stmt->bind_param("i", $bidder_id);
$my_active_stmt->execute();
$my_active_bids = (int)$my_active_stmt->get_result()->fetch_row()[0];
$my_active_stmt->close();

$my_pend_stmt = $conn->prepare("SELECT COUNT(*) FROM bids WHERE bidder_id = ? AND status = 'pending'");
$my_pend_stmt->bind_param("i", $bidder_id);
$my_pend_stmt->execute();
$pending_verification = (int)$my_pend_stmt->get_result()->fetch_row()[0];
$my_pend_stmt->close();

// ── 4. Upcoming Openings ─────────────────────────────────────────────────────
$upcoming_open_res = $conn->query("
    SELECT COUNT(*) 
    FROM procurements 
    WHERE opening_date IS NOT NULL 
      AND opening_date >= NOW() 
      AND status = 'open'
");
$upcoming_openings = ($upcoming_open_res && $row = $upcoming_open_res->fetch_row()) ? (int)$row[0] : 0;

// ── 5. Calendar Events ───────────────────────────────────────────────────────
$cal_result = $conn->query("
    SELECT id, title, slsu_ref_no, opening_date, closing_date, status
    FROM procurements
    WHERE status != 'draft' AND (opening_date IS NOT NULL OR closing_date IS NOT NULL)
    ORDER BY COALESCE(opening_date, closing_date) ASC
");
$cal_events = [];
if ($cal_result) {
    while ($c = $cal_result->fetch_assoc()) $cal_events[] = $c;
}

// ── 6. Open Procurements (for left subcolumn) ────────────────────────────────
$open_procs_stmt = $conn->prepare("
    SELECT 
        p.id,
        p.slsu_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT b.id FROM bids b WHERE b.procurement_id = p.id AND b.bidder_id = ? LIMIT 1) AS my_bid_id
    FROM procurements p
    WHERE p.status = 'open'
    ORDER BY p.closing_date ASC
    LIMIT 4
");
$open_procs_stmt->bind_param("i", $bidder_id);
$open_procs_stmt->execute();
$open_procs_result = $open_procs_stmt->get_result();

// ── 7. My Recent Bids (for right subcolumn) ──────────────────────────────────
$recent_bids_stmt = $conn->prepare("
    SELECT 
        b.id AS bid_id,
        b.submission_date,
        b.status AS bid_status,
        p.id AS proc_id,
        p.title AS proc_title,
        p.slsu_ref_no,
        p.abc AS proc_abc
    FROM bids b
    JOIN procurements p ON b.procurement_id = p.id
    WHERE b.bidder_id = ?
    ORDER BY b.submission_date DESC
    LIMIT 4
");
$recent_bids_stmt->bind_param("i", $bidder_id);
$recent_bids_stmt->execute();
$recent_bids_result = $recent_bids_stmt->get_result();

// ── 8. Announcements / Notifications (for 40% column) ────────────────────────
$notifs_stmt = $conn->prepare("
    SELECT sn.notification_id, sn.title, sn.message, sn.target_type, sn.target_role, sn.created_at,
           u.firstname, u.lastname
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    WHERE sn.target_type = 'all'
       OR (sn.target_type = 'role' AND sn.target_role = 'bidder')
       OR (sn.target_type = 'user' AND sn.target_user_id = ?)
    ORDER BY sn.created_at DESC
    LIMIT 3
");
$notifs_stmt->bind_param("i", $bidder_id);
$notifs_stmt->execute();
$notifs_result = $notifs_stmt->get_result();

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
    <title>Bidder Dashboard | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
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
            padding: 26px 28px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(6, 37, 27, 0.16);
            margin-bottom: 20px;
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
            margin-bottom: 12px;
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
            max-width: 580px;
            margin-bottom: 18px;
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
            padding: 7px 14px;
            border-radius: 12px;
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

        /* ── 4 Donut Stat Cards (Inside 60% Column) ── */
        .ap2-stats-4-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .ap2-stats-4-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .ap2-stats-4-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-donut-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 14px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .stat-donut-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16,36,26,.09);
        }

        .stat-donut-ring {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
        }

        .stat-donut-inner {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }

        .stat-donut-info {
            line-height: 1.2;
            min-width: 0;
        }

        .stat-donut-num {
            font-size: 20px;
            font-weight: 800;
            color: #06251b;
            line-height: 1.1;
        }

        .stat-donut-lbl {
            font-size: 11px;
            font-weight: 600;
            color: #88968d;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Side-by-Side Open Procurements & My Bids ── */
        .dash-side-by-side {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 768px) {
            .dash-side-by-side {
                grid-template-columns: 1fr;
            }
        }

        .sub-card {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .sub-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px 12px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .sub-card-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 7px;
        }

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

        .sub-card-link:hover,
        .side-panel-link:hover {
            background: #06251b;
            color: #ffc107;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(6, 37, 27, 0.12);
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

        .sub-item-title a {
            color: inherit;
            text-decoration: none;
        }
        .sub-item-title a:hover {
            color: #1f7a3d;
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

        .sub-item-action {
            flex-shrink: 0;
        }

        /* ── Profile Card (40% Column) ── */
        .profile-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 20px;
            position: relative;
        }

        .profile-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0f4f2;
        }

        .profile-avatar-box {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #06251b 0%, #1f7a3d 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.15);
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-biz-name {
            font-size: 15px;
            font-weight: 800;
            color: #06251b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .profile-rep-name {
            font-size: 12px;
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
            margin-top: 5px;
        }

        .profile-status-badge.approved { background: #e4f5ea; color: #1f7a3d; }
        .profile-status-badge.pending  { background: #fff3e0; color: #e67e22; }

        .profile-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .profile-detail-item {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 10px;
            padding: 8px 10px;
        }

        .profile-detail-lbl {
            font-size: 9.5px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 2px;
        }

        .profile-detail-val {
            font-size: 11.5px;
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Compact Calendar & Notifications (40% Column) ── */
        .side-panel-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .side-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px 12px;
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


        /* Calendar grid */
        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
            padding: 14px 16px 12px;
        }

        .mini-cal-dow {
            text-align: center;
            font-size: 9.5px;
            font-weight: 700;
            color: #aaa;
            padding: 2px 0 4px;
            text-transform: uppercase;
        }

        .mini-cal-day {
            aspect-ratio: 1;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding-top: 3px;
            font-size: 10.5px;
            font-weight: 600;
            color: #555;
            cursor: default;
            position: relative;
        }

        .mini-cal-day.empty { background: transparent; }
        .mini-cal-day.today {
            background: #06251b;
            color: #ffc107;
            font-weight: 800;
        }
        .mini-cal-day.has-event { background: #e8f5e9; color: #1f7a3d; }
        .mini-cal-day.has-event.today { background: #06251b; color: #ffc107; }

        .mini-cal-dots {
            display: flex;
            gap: 2px;
            margin-top: 1px;
            justify-content: center;
        }

        .mini-cal-dot {
            width: 3.5px;
            height: 3.5px;
            border-radius: 50%;
        }
        .mini-cal-dot.opening { background: #1f7a3d; }
        .mini-cal-dot.closing { background: #c23b3b; }

        .side-events-list {
            padding: 6px 14px 12px;
            border-top: 1px solid #f4f7f5;
        }

        .side-event-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid #f9fbf9;
            font-size: 11px;
            transition: all .15s ease;
        }

        .side-event-item:last-child { border-bottom: none; }
        .side-event-item:hover .side-event-title { color: #1f7a3d; }

        .side-event-title {
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 170px;
        }

        .side-event-date {
            font-size: 10px;
            color: #88968d;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Notification item in 40% column */
        .side-notif-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 16px;
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
            margin-top: 3px;
            font-size: 10px;
            color: #9aa8a1;
        }

        /* Buttons & Badges */
        .btn-mini-bid {
            background: #06251b;
            color: #ffc107;
            font-size: 10.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-decoration: none;
            white-space: nowrap;
            transition: all .15s ease;
        }

        .btn-mini-bid:hover {
            background: #124032;
        }

        .status-pill-mini {
            font-size: 9.5px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-pill-mini.pending   { background: #fff3e0; color: #e67e22; }
        .status-pill-mini.submitted { background: #e4f5ea; color: #1f7a3d; }
        .status-pill-mini.opened    { background: #e7eefe; color: #2F6FED; }
        .status-pill-mini.awarded   { background: #fff8e1; color: #f9a825; }
        .status-pill-mini.rejected  { background: #fbe1e1; color: #c23b3b; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Bidder Dashboard';
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
             1. Green Greetings Card
             2. Statistics Cards
             3. Open Procurement & My Bids Side-by-Side
             ════════════════════════════════════════════════════════ -->
        <div class="dash-col-60">

            <!-- 1. Green Greetings Card -->
            <div class="dash-greeting-card">
                <div class="greeting-header">
                    <div class="greeting-title">Welcome back, <?= htmlspecialchars($bidder_name) ?></div>
                    <span class="greeting-badge">
                        <i class="bi bi-patch-check-fill"></i> <?= strtoupper($app_status) ?> BIDDER
                    </span>
                </div>
                <div class="greeting-sub">
                    Welcome to the YesParency Bidder Portal. Track active bidding opportunities, submit proposals, and monitor review statuses in real-time.
                </div>
                <div class="greeting-pills">
                    <div class="greeting-pill">
                        <i class="bi bi-folder2-open"></i> <strong><?= $open_procs ?></strong> Open Procurements
                    </div>
                    <div class="greeting-pill">
                        <i class="bi bi-inbox"></i> <strong><?= $my_active_bids ?></strong> Active Proposals
                    </div>
                    <div class="greeting-pill">
                        <i class="bi bi-calendar3"></i> <?= date('F j, Y') ?>
                    </div>
                </div>
            </div>

            <!-- 2. Statistics (4 Donut Stat Cards) -->
            <div class="ap2-stats-4-grid">
                
                <!-- Open Procurements -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="background:conic-gradient(#43a047 0% <?= pct($open_procs, max($total_procs, 1)) ?>%, #e7ece9 0%);">
                        <div class="stat-donut-inner">
                            <i class="bi bi-folder2-open" style="color:#43a047;"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num"><?= $open_procs ?></div>
                        <div class="stat-donut-lbl">Open Bids</div>
                    </div>
                </div>

                <!-- My Active Bids -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="background:conic-gradient(#2F6FED 0% <?= pct($my_active_bids, max($my_total_bids, 1)) ?>%, #e7ece9 0%);">
                        <div class="stat-donut-inner">
                            <i class="bi bi-inbox" style="color:#2F6FED;"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num"><?= $my_active_bids ?></div>
                        <div class="stat-donut-lbl">Active Bids</div>
                    </div>
                </div>

                <!-- Pending Verification -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="background:conic-gradient(<?= $pending_verification > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= pct($pending_verification, max($my_total_bids, 1)) ?>%, #e7ece9 0%);">
                        <div class="stat-donut-inner">
                            <i class="bi bi-hourglass-split" style="color:<?= $pending_verification > 0 ? '#e67e22' : '#8B958E' ?>;"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num" style="color:<?= $pending_verification > 0 ? '#e67e22' : 'inherit' ?>"><?= $pending_verification ?></div>
                        <div class="stat-donut-lbl">Pending Review</div>
                    </div>
                </div>

                <!-- Upcoming Openings -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="background:conic-gradient(#8e44ad 0% <?= pct($upcoming_openings, max($total_procs, 1)) ?>%, #e7ece9 0%);">
                        <div class="stat-donut-inner">
                            <i class="bi bi-calendar-event" style="color:#8e44ad;"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num"><?= $upcoming_openings ?></div>
                        <div class="stat-donut-lbl">Upcoming</div>
                    </div>
                </div>

            </div>

            <!-- 3. Side-by-Side: Open Procurement & My Bids -->
            <div class="dash-side-by-side">

                <!-- Left: Open Procurements -->
                <div class="sub-card">
                    <div class="sub-card-head">
                        <div class="sub-card-title">
                            <i class="bi bi-folder2-open" style="color:#1f7a3d;"></i> Open Procurements
                        </div>
                        <a href="procurement.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if (!$open_procs_result || $open_procs_result->num_rows === 0): ?>
                            <div style="padding:32px 16px; text-align:center; color:#88968d; font-size:12px;">
                                <i class="bi bi-folder-x" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                                No active procurements open.
                            </div>
                        <?php else: while ($op = $open_procs_result->fetch_assoc()):
                            $alreadyBid = !empty($op['my_bid_id']);
                        ?>
                            <div class="sub-item-row">
                                <div class="sub-item-main">
                                    <div class="sub-item-title">
                                        <a href="view_procurement.php?id=<?= $op['id'] ?>" title="<?= htmlspecialchars($op['title']) ?>">
                                            <?= htmlspecialchars($op['title']) ?>
                                        </a>
                                    </div>
                                    <div class="sub-item-meta">
                                        <span style="font-weight:700; color:#06251b;">₱<?= number_format($op['abc'], 2) ?></span>
                                        <span>·</span>
                                        <span><?= $op['closing_date'] ? date('M j', strtotime($op['closing_date'])) : 'TBD' ?></span>
                                    </div>
                                </div>
                                <div class="sub-item-action">
                                    <?php if ($alreadyBid): ?>
                                        <a href="view_procurement.php?id=<?= $op['id'] ?>" class="proc-action-btn manage" style="background:#E4F5EA; color:#1f7a3d; font-size:11.5px; padding:5px 12px; border-radius:8px; font-weight:700; text-decoration:none;">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php else: ?>
                                        <a href="view_procurement.php?id=<?= $op['id'] ?>" class="proc-action-btn review" style="background:#E7EEFE; color:#2F6FED; font-size:11.5px; padding:5px 12px; border-radius:8px; font-weight:700; text-decoration:none;">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>

                <!-- Right: My Bids -->
                <div class="sub-card">
                    <div class="sub-card-head">
                        <div class="sub-card-title">
                            <i class="bi bi-inbox" style="color:#2F6FED;"></i> My Recent Bids
                        </div>
                        <a href="my_bids.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if (!$recent_bids_result || $recent_bids_result->num_rows === 0): ?>
                            <div style="padding:32px 16px; text-align:center; color:#88968d; font-size:12px;">
                                <i class="bi bi-inbox" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                                No bids submitted yet.
                            </div>
                        <?php else: while ($mb = $recent_bids_result->fetch_assoc()):
                            $bstat = strtolower($mb['bid_status'] ?? 'pending');
                        ?>
                            <div class="sub-item-row">
                                <div class="sub-item-main">
                                    <div class="sub-item-title" title="<?= htmlspecialchars($mb['proc_title']) ?>">
                                        <?= htmlspecialchars($mb['proc_title']) ?>
                                    </div>
                                    <div class="sub-item-meta">
                                        <span>Ref: <?= htmlspecialchars($mb['slsu_ref_no'] ?? 'N/A') ?></span>
                                        <span>·</span>
                                        <span><?= date('M j, Y', strtotime($mb['submission_date'])) ?></span>
                                    </div>
                                </div>
                                <div class="sub-item-action" style="display:flex; align-items:center; gap:8px;">
                                    <span class="status-pill-mini <?= $bstat ?>">
                                        <?= ucfirst($bstat) ?>
                                    </span>
                                    <a href="my_bids.php" class="proc-action-btn review" style="background:#E7EEFE; color:#2F6FED; font-size:11.5px; padding:5px 12px; border-radius:8px; font-weight:700; text-decoration:none;">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>

            </div>

            <!-- 4. Announcements / Notifications Card -->
            <div class="sub-card" style="margin-top:16px;">
                <div class="sub-card-head">
                    <div class="sub-card-title">
                        <i class="bi bi-megaphone" style="color:#e67e22;"></i> System Announcements &amp; Notices
                    </div>
                    <a href="notification.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <div>
                    <?php if (!$notifs_result || $notifs_result->num_rows === 0): ?>
                        <div style="padding:28px 16px; text-align:center; color:#88968d; font-size:12px;">
                            <i class="bi bi-bell-slash" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                            No recent announcements.
                        </div>
                    <?php else: while ($nt = $notifs_result->fetch_assoc()):
                        $targetBadge = strtoupper($nt['target_type']);
                        $targetColor = '#e3f2fd';
                        $targetFg    = '#1565c0';
                        if ($nt['target_type'] === 'role') {
                            $targetBadge = strtoupper($nt['target_role'] ?? 'BIDDER');
                            $targetColor = '#e8f5e9';
                            $targetFg    = '#1f7a3d';
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
                                    <div class="side-notif-meta" style="margin-top:0;">
                                        <span style="background:<?= $targetColor ?>; color:<?= $targetFg ?>; padding:1px 6px; border-radius:4px; font-weight:700; font-size:9.5px;">
                                            <?= htmlspecialchars($targetBadge) ?>
                                        </span>
                                        <span>From: <?= htmlspecialchars(trim(($nt['firstname'] ?? '') . ' ' . ($nt['lastname'] ?? '')) ?: 'BAC Secretariat') ?></span>
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
             1. Profile Card (New!)
             2. Calendar
             ════════════════════════════════════════════════════════ -->
        <div class="dash-col-40">

            <!-- 1. Profile Card -->
            <div class="profile-card">
                <div class="profile-top">
                    <div class="profile-avatar-box">
                        <?= strtoupper(substr($bidder_name, 0, 1)) ?>
                    </div>
                    <div class="profile-info">
                        <div class="profile-biz-name"><?= htmlspecialchars($business_name) ?></div>
                        <div class="profile-rep-name">
                            <i class="bi bi-person"></i> <?= htmlspecialchars($bidder_name) ?>
                        </div>
                        <span class="profile-status-badge <?= $app_status === 'approved' ? 'approved' : 'pending' ?>">
                            <i class="bi bi-<?= $app_status === 'approved' ? 'patch-check-fill' : 'hourglass-split' ?>"></i>
                            <?= ucfirst($app_status) ?> Account
                        </span>
                    </div>
                </div>

                <div class="profile-details-grid">
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">PhilGEPS No.</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($slsu_number) ?>"><?= htmlspecialchars($slsu_number) ?></div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">TIN Number</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($tin_number) ?>"><?= htmlspecialchars($tin_number) ?></div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">Business Email</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($business_email) ?>"><?= htmlspecialchars($business_email) ?></div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="profile-detail-lbl">Contact Phone</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($business_phone) ?>"><?= htmlspecialchars($business_phone) ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Calendar Card -->
            <div class="side-panel-card">
                <div class="side-panel-head">
                    <div class="side-panel-title">
                        <i class="bi bi-calendar3" style="color:#2F6FED;"></i> Bid Calendar &amp; Schedule
                    </div>
                    <a href="procurement.php" class="side-panel-link">View all <i class="bi bi-arrow-right"></i></a>
                </div>

                <div>
                    <?php
                    $today       = new DateTime();
                    $year        = (int)$today->format('Y');
                    $month       = (int)$today->format('n');
                    $todayDay    = (int)$today->format('j');
                    $firstDay    = new DateTime("$year-$month-01");
                    $daysInMonth = (int)$firstDay->format('t');
                    $startDow    = (int)$firstDay->format('w');

                    $eventsByDay = [];
                    foreach ($cal_events as $ev) {
                        if ($ev['opening_date']) {
                            $d = (int)(new DateTime($ev['opening_date']))->format('j');
                            $m = (int)(new DateTime($ev['opening_date']))->format('n');
                            $y = (int)(new DateTime($ev['opening_date']))->format('Y');
                            if ($m === $month && $y === $year) {
                                $eventsByDay[$d][] = ['type'=>'opening','title'=>$ev['title']];
                            }
                        }
                        if ($ev['closing_date']) {
                            $d = (int)(new DateTime($ev['closing_date']))->format('j');
                            $m = (int)(new DateTime($ev['closing_date']))->format('n');
                            $y = (int)(new DateTime($ev['closing_date']))->format('Y');
                            if ($m === $month && $y === $year) {
                                $eventsByDay[$d][] = ['type'=>'closing','title'=>$ev['title']];
                            }
                        }
                    }
                    ?>
                    <div class="mini-cal-grid">
                        <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
                            <div class="mini-cal-dow"><?= $d ?></div>
                        <?php endforeach; ?>

                        <?php for($i=0; $i<$startDow; $i++): ?>
                            <div class="mini-cal-day empty"></div>
                        <?php endfor; ?>

                        <?php for($d=1; $d<=$daysInMonth; $d++):
                            $isToday  = $d === $todayDay;
                            $hasEvent = isset($eventsByDay[$d]);
                            $cls = 'mini-cal-day';
                            if ($isToday)  $cls .= ' today';
                            if ($hasEvent) $cls .= ' has-event';
                        ?>
                            <div class="<?= $cls ?>" title="<?= $hasEvent ? implode(', ', array_column($eventsByDay[$d],'title')) : '' ?>">
                                <?= $d ?>
                                <?php if ($hasEvent): ?>
                                <div class="mini-cal-dots">
                                    <?php foreach($eventsByDay[$d] as $ev): ?>
                                        <div class="mini-cal-dot <?= $ev['type'] ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div style="display:flex; justify-content:space-around; padding:8px 14px 12px; font-size:10.5px; color:#777; border-top:1px solid #f4f7f5;">
                        <span style="display:flex; align-items:center; gap:5px;">
                            <span style="width:7px; height:7px; border-radius:50%; background:#1f7a3d; display:inline-block;"></span> Bid Opening
                        </span>
                        <span style="display:flex; align-items:center; gap:5px;">
                            <span style="width:7px; height:7px; border-radius:50%; background:#c23b3b; display:inline-block;"></span> Deadline
                        </span>
                    </div>

                    <!-- Upcoming Milestones -->
                    <div class="side-events-list">
                        <?php
                        $upcoming = array_filter($cal_events, function($ev) use ($today) {
                            $d = $ev['opening_date'] ?? $ev['closing_date'];
                            return $d && new DateTime($d) >= $today;
                        });
                        $upcoming = array_slice($upcoming, 0, 3);
                        if (empty($upcoming)): ?>
                            <div style="text-align:center; padding:10px 0; color:#aaa; font-size:11px;">No upcoming milestones this month.</div>
                        <?php else: foreach ($upcoming as $uev):
                            $isOpening = !empty($uev['opening_date']);
                            $eventDate = $isOpening ? $uev['opening_date'] : $uev['closing_date'];
                        ?>
                            <a href="view_procurement.php?id=<?= $uev['id'] ?>" class="side-event-item" style="text-decoration:none; color:inherit;">
                                <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                                    <span style="width:6px; height:6px; border-radius:50%; background:<?= $isOpening ? '#1f7a3d' : '#c23b3b' ?>; flex-shrink:0;"></span>
                                    <span class="side-event-title" title="<?= htmlspecialchars($uev['title']) ?>"><?= htmlspecialchars($uev['title']) ?></span>
                                </div>
                                <span class="side-event-date"><?= date('M j', strtotime($eventDate)) ?></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- ════════════════════════════════════════════════════════════
         BOTTOM: QUICK ACTIONS (100% WIDTH)
         ════════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Quick Actions</div>
    <div class="sad-actions">
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e8f5e9; color:#43a047;"><i class="bi bi-folder2-open"></i></div>
            <span>Browse Procurements</span>
        </a>
        <a href="my_bids.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e3f2fd; color:#1565c0;"><i class="bi bi-inbox"></i></div>
            <span>My Submissions</span>
        </a>
        <a href="notification.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fff8e1; color:#f9a825;"><i class="bi bi-bell"></i></div>
            <span>Notifications</span>
        </a>
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#f3e5f5; color:#7b1fa2;"><i class="bi bi-calendar3"></i></div>
            <span>Bid Calendar</span>
        </a>
        <a href="my_bids.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e0f2f1; color:#00796b;"><i class="bi bi-file-earmark-check"></i></div>
            <span>Verify Bids</span>
        </a>
        <a href="../logout.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fce4ec; color:#c62828;"><i class="bi bi-box-arrow-left"></i></div>
            <span>Logout</span>
        </a>
    </div>

</div>
</main>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
