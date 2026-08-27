<?php
include("utils/protect-page.php");

// ── Procurement stats ───────────────────────────────────────────────────────
$total_procs   = $conn->query("SELECT COUNT(*) FROM procurements")->fetch_row()[0];
$open_procs    = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0];
$draft_procs   = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'draft'")->fetch_row()[0];
$closed_procs  = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'closed'")->fetch_row()[0];
$awarded_procs = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'awarded'")->fetch_row()[0];

// ── Bid stats ───────────────────────────────────────────────────────────────
$total_bids    = $conn->query("SELECT COUNT(*) FROM bids")->fetch_row()[0];
$pending_bids  = $conn->query("SELECT COUNT(*) FROM bids WHERE status = 'pending'")->fetch_row()[0];
$submitted_bids= $conn->query("SELECT COUNT(*) FROM bids WHERE status = 'submitted'")->fetch_row()[0];
$rejected_bids = $conn->query("SELECT COUNT(*) FROM bids WHERE status = 'rejected'")->fetch_row()[0];

// ── Application stats ───────────────────────────────────────────────────────
$total_apps    = $conn->query("SELECT COUNT(*) FROM bidder_profiles")->fetch_row()[0];
$pending_apps  = $conn->query("SELECT COUNT(*) FROM bidder_profiles WHERE application_status = 'pending'")->fetch_row()[0];

// ── Calendar: procurements with opening/closing dates ───────────────────────
$cal_result = $conn->query("
    SELECT id, title, philgeps_ref_no, opening_date, closing_date, status
    FROM procurements
    WHERE opening_date IS NOT NULL OR closing_date IS NOT NULL
    ORDER BY COALESCE(opening_date, closing_date) ASC
");
$cal_events = [];
while ($c = $cal_result->fetch_assoc()) $cal_events[] = $c;

// ── Recent Notifications / Announcements (latest 3) ─────────────────────────
$notifs_dash_result = $conn->query("
    SELECT sn.notification_id, sn.title, sn.message, sn.target_type, sn.target_role, sn.created_at,
           u.firstname, u.lastname
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    ORDER BY sn.created_at DESC
    LIMIT 4
");

// ── Pending bids overview (latest 6) ───────────────────────────────────────
$pending_bids_result = $conn->query("
    SELECT b.id AS bid_id, b.submission_date,
           u.firstname, u.lastname, u.email, u.username,
           p.title AS proc_title, p.id AS proc_id, p.philgeps_ref_no
    FROM bids b
    JOIN users u  ON b.bidder_id    = u.user_id
    JOIN procurements p ON b.procurement_id = p.id
    WHERE b.status = 'pending'
    ORDER BY b.submission_date DESC
    LIMIT 6
");

// ── Pending applications (latest 5) ────────────────────────────────────────
$pending_result = $conn->query("
    SELECT u.firstname, u.lastname, bp.business_name, bp.created_at
    FROM bidder_profiles bp
    JOIN users u ON bp.user_id = u.user_id
    WHERE bp.application_status = 'pending'
    ORDER BY bp.created_at DESC
    LIMIT 5
");

function pct($part, $total) {
    return $total > 0 ? round($part / $total * 100) : 0;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Grid Rows ── */
        .dash-row-70-30 {
            display: grid;
            grid-template-columns: 2.3fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .dash-row-30-70 {
            display: grid;
            grid-template-columns: 1fr 2.3fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .dash-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 1080px) {
            .dash-row-70-30,
            .dash-row-30-70,
            .dash-two-col {
                grid-template-columns: 1fr;
            }
        }

        /* ── Redesigned Bid Schedule (70%) ── */
        .bs-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 16px;
            padding: 12px 18px 14px;
        }

        @media (max-width: 768px) {
            .bs-layout {
                grid-template-columns: 1fr;
            }
        }

        .bs-cal-col {
            border-right: 1px solid #f0f4f2;
            padding-right: 16px;
        }

        @media (max-width: 768px) {
            .bs-cal-col {
                border-right: none;
                border-bottom: 1px solid #f0f4f2;
                padding-right: 0;
                padding-bottom: 12px;
            }
        }

        .dash-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }

        .dash-cal-dow {
            text-align: center;
            font-size: 9.5px;
            font-weight: 700;
            color: #aaa;
            padding: 2px 0 4px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .dash-cal-day {
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
            transition: background .15s;
        }

        .dash-cal-day.empty { background: transparent; }
        .dash-cal-day.today {
            background: #06251b;
            color: #ffc107;
            font-weight: 800;
        }
        .dash-cal-day.has-event { background: #e8f5e9; color: #1f7a3d; }
        .dash-cal-day.has-event.today { background: #06251b; color: #ffc107; }

        .dash-cal-dot-wrap {
            display: flex;
            gap: 2px;
            margin-top: 1px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .dash-cal-dot {
            width: 3.5px;
            height: 3.5px;
            border-radius: 50%;
        }
        .dash-cal-dot.opening { background: #1f7a3d; }
        .dash-cal-dot.closing { background: #c23b3b; }

        /* Schedule Timeline List */
        .bs-events-col {
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }

        .bs-events-title {
            font-size: 10.5px;
            font-weight: 700;
            color: #9aa8a1;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .bs-event-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 10px;
            background: #f7faf8;
            border: 1px solid #eaeeec;
            margin-bottom: 6px;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
            transition: all .15s ease;
        }

        .bs-event-item:last-child {
            margin-bottom: 0;
        }

        .bs-event-item:hover {
            background: #ffffff;
            border-color: #06251b;
            box-shadow: 0 4px 12px rgba(6,37,27,.06);
        }

        .bs-event-badge {
            font-size: 9.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 20px;
            white-space: nowrap;
            letter-spacing: .4px;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .bs-event-badge.opening { background: #e4f5ea; color: #1f7a3d; }
        .bs-event-badge.closing { background: #fbe1e1; color: #c23b3b; }

        .bs-event-info {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        .bs-event-title {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            width: 100%;
        }

        .bs-event-meta {
            font-size: 10.5px;
            color: #88968d;
            margin-top: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bs-event-date {
            font-size: 11.5px;
            font-weight: 700;
            color: #06251b;
            white-space: nowrap;
            flex-shrink: 0;
            text-align: right;
            line-height: 1.2;
        }

        /* ── Notifications Card (30%) ── */
        .dash-notif-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 9px 14px;
            border-bottom: 1px solid #f0f4f2;
            transition: background .15s;
        }

        .dash-notif-item:last-child { border-bottom: none; }
        .dash-notif-item:hover { background: #f8faf8; }

        .dash-notif-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #e7eefe;
            color: #2F6FED;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .dash-notif-body { flex: 1; min-width: 0; }
        .dash-notif-title {
            font-size: 12px;
            font-weight: 700;
            color: #182019;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .dash-notif-msg {
            font-size: 11px;
            color: #6C776E;
            margin-top: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.3;
        }

        .dash-notif-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
            font-size: 10px;
            color: #9aa8a1;
        }

        /* ── Pending Bids (70% in Row 3) ── */
        .pbd-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pbd-row-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 20px;
            border-bottom: 1px solid #f0f4f2;
            transition: background .15s;
        }

        .pbd-row-card:last-child { border-bottom: none; }
        .pbd-row-card:hover { background: #f8faf8; }

        .pbd-avatar {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(145deg, #1f4638, #06251b);
            color: #eafff2;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .pbd-info { flex: 1; min-width: 0; }
        .pbd-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 2px;
        }

        .pbd-proc {
            font-size: 12px;
            color: #55665a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pbd-meta-tags {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
            font-size: 11px;
            color: #9aa8a1;
        }

        .pbd-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        /* ── Panel stat row ── */
        .dsh-stat-row {
            display: flex;
            align-items: stretch;
            padding: 8px 20px 20px;
        }
        .dsh-stat-row--wrap {
            flex-wrap: wrap;
            gap: 0;
        }
        .dsh-stat-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 16px 12px;
            min-width: 80px;
        }
        .dsh-stat-num {
            font-size: 26px;
            font-weight: 800;
            color: #06251b;
            line-height: 1;
        }
        .dsh-stat-lbl {
            font-size: 11px;
            font-weight: 600;
            color: #999;
            text-align: center;
            white-space: nowrap;
        }
        .dsh-stat-divider {
            width: 1px;
            background: #eaeeec;
            margin: 16px 0;
            flex-shrink: 0;
        }

        @media (max-width: 600px) {
            .dsh-stat-row { flex-wrap: wrap; }
            .dsh-stat-divider { display: none; }
            .dsh-stat-item { min-width: calc(50% - 24px); }
        }

        /* ── Card section header ── */
        .dsh-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 20px 16px;
            border-bottom: 1px solid #f0f4f2;
            margin-bottom: 4px;
        }
        .dsh-card-head-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .dsh-card-head-text {
            flex: 1;
            min-width: 0;
        }
        .dsh-card-head-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            line-height: 1.2;
        }
        .dsh-card-head-sub {
            font-size: 11px;
            color: #aab5ae;
            margin-top: 2px;
            font-weight: 500;
        }
        .dsh-card-head-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: #06251b;
            text-decoration: none;
            padding: 5px 12px;
            border: 1.5px solid #d4e0d8;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
            transition: background .15s, border-color .15s, color .15s;
        }
        .dsh-card-head-link:hover {
            background: #06251b;
            border-color: #06251b;
            color: #ffc107;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Welcome, <?= htmlspecialchars($_SESSION['username']) ?></h2>
        <p>System-wide overview — live data from the database.</p>
    </div>
    <!-- ════════════════════════════════════════════════════════════
         ROW 2: PROCUREMENT STATS & BID STATS
         ════════════════════════════════════════════════════════════ -->
    <div class="dash-two-col">

        <!-- Procurements panel -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e8f5e9; color:#43a047;">
                    <i class="bi bi-folder2-open"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Procurements</div>
                    <div class="dsh-card-head-sub">Active &amp; archived projects</div>
                </div>
                <a href="procurement.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="dsh-stat-row dsh-stat-row--wrap">
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-folder2" style="color:#06251b; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $total_procs ?></div>
                    <div class="dsh-stat-lbl">Total</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#43a047 0% <?= pct($open_procs,$total_procs) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#43a047; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $open_procs ?></div>
                    <div class="dsh-stat-lbl">Open</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#546e7a 0% <?= pct($draft_procs,$total_procs) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-pencil-square" style="color:#546e7a; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $draft_procs ?></div>
                    <div class="dsh-stat-lbl">Drafts</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#f9a825 0% <?= pct($awarded_procs,$total_procs) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-trophy" style="color:#f9a825; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $awarded_procs ?></div>
                    <div class="dsh-stat-lbl">Awarded</div>
                </div>
            </div>
        </div>

        <!-- Bids panel -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff3e0; color:#e67e22;">
                    <i class="bi bi-inbox"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Bids</div>
                    <div class="dsh-card-head-sub">Submissions &amp; review status</div>
                </div>
                <a href="bid_submissions.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="dsh-stat-row dsh-stat-row--wrap">
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-inbox" style="color:#06251b; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $total_bids ?></div>
                    <div class="dsh-stat-lbl">Total</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#e67e22 0% <?= pct($pending_bids,$total_bids) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:#e67e22; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num" style="color:<?= $pending_bids > 0 ? '#e67e22' : 'inherit' ?>"><?= $pending_bids ?></div>
                    <div class="dsh-stat-lbl">Pending</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#1f7a3d 0% <?= pct($submitted_bids,$total_bids) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-check-circle" style="color:#1f7a3d; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $submitted_bids ?></div>
                    <div class="dsh-stat-lbl">Verified</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#c23b3b 0% <?= pct($rejected_bids,$total_bids) ?>%, #e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-x-circle" style="color:#c23b3b; font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $rejected_bids ?></div>
                    <div class="dsh-stat-lbl">Rejected</div>
                </div>
            </div>
        </div>

    </div><!-- /.dash-two-col -->

    <!-- ════════════════════════════════════════════════════════════
         ROW 1: CALENDAR & BID SCHEDULES (70%) + NOTIFICATIONS (30%)
         ════════════════════════════════════════════════════════════ -->
    <div class="dash-row-70-30">

        <!-- Bid Schedule (70%) -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e7eefe; color:#2F6FED;">
                    <i class="bi bi-calendar3"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Bid Schedule &amp; Calendar</div>
                    <div class="dsh-card-head-sub">Procurement opening dates &amp; submission deadlines</div>
                </div>
                <a href="procurement.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>

            <div class="bs-layout">
                <!-- Left: Interactive Calendar -->
                <div class="bs-cal-col">
                    <?php
                    $today       = new DateTime();
                    $year        = (int)$today->format('Y');
                    $month       = (int)$today->format('n');
                    $todayDay    = (int)$today->format('j');
                    $firstDay    = new DateTime("$year-$month-01");
                    $daysInMonth = (int)$firstDay->format('t');
                    $startDow    = (int)$firstDay->format('w'); // 0=Sun

                    // Map events to days
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
                    <div style="text-align:center; font-size:13px; font-weight:700; color:#06251b; margin-bottom:10px;">
                        <?= $firstDay->format('F Y') ?>
                    </div>
                    <div class="dash-cal-grid">
                        <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
                            <div class="dash-cal-dow"><?= $d ?></div>
                        <?php endforeach; ?>

                        <?php for($i=0; $i<$startDow; $i++): ?>
                            <div class="dash-cal-day empty"></div>
                        <?php endfor; ?>

                        <?php for($d=1; $d<=$daysInMonth; $d++):
                            $isToday  = $d === $todayDay;
                            $hasEvent = isset($eventsByDay[$d]);
                            $cls = 'dash-cal-day';
                            if ($isToday)  $cls .= ' today';
                            if ($hasEvent) $cls .= ' has-event';
                        ?>
                            <div class="<?= $cls ?>" title="<?= $hasEvent ? implode(', ', array_column($eventsByDay[$d],'title')) : '' ?>">
                                <?= $d ?>
                                <?php if ($hasEvent): ?>
                                <div class="dash-cal-dot-wrap">
                                    <?php foreach($eventsByDay[$d] as $ev): ?>
                                        <div class="dash-cal-dot <?= $ev['type'] ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Legend -->
                    <div style="display:flex; flex-direction:column; gap:6px; margin-top:14px; font-size:11px; color:#777;">
                        <span style="display:flex; align-items:center; gap:6px;">
                            <span style="width:8px; height:8px; border-radius:50%; background:#1f7a3d; display:inline-block;"></span> Bid Opening
                        </span>
                        <span style="display:flex; align-items:center; gap:6px;">
                            <span style="width:8px; height:8px; border-radius:50%; background:#c23b3b; display:inline-block;"></span> Submission Deadline
                        </span>
                    </div>
                </div>

                <!-- Right: Upcoming Bid Schedule List -->
                <div class="bs-events-col">
                    <?php
                    $upcoming = array_filter($cal_events, function($ev) use ($today) {
                        $d = $ev['opening_date'] ?? $ev['closing_date'];
                        return $d && new DateTime($d) >= $today;
                    });
                    $upcoming = array_slice(array_values($upcoming), 0, 3);
                    ?>
                    <div class="bs-events-title">
                        <span>Upcoming Schedules</span>
                        <span style="font-weight:600;"><?= count($upcoming) ?> Active</span>
                    </div>

                    <?php if (empty($upcoming)): ?>
                        <div class="sad-empty" style="padding:32px 0;">No upcoming bid schedules.</div>
                    <?php else: ?>
                        <?php foreach($upcoming as $ev):
                            $useDate = $ev['opening_date'] ?? $ev['closing_date'];
                            $type    = $ev['opening_date'] ? 'opening' : 'closing';
                            $label   = $type === 'opening' ? 'Bid Opening' : 'Deadline';
                            $refNo   = $ev['philgeps_ref_no'] ?? 'N/A';
                        ?>
                        <div class="bs-event-item">
                            <span class="bs-event-badge <?= $type ?>"><?= $label ?></span>
                            <div class="bs-event-info">
                                <div class="bs-event-title" title="<?= htmlspecialchars($ev['title']) ?>"><?= htmlspecialchars($ev['title']) ?></div>
                                <div class="bs-event-meta">
                                    <span><i class="bi bi-hash"></i> <?= htmlspecialchars($refNo) ?></span>
                                    <span>·</span>
                                    <span style="text-transform:capitalize;"><?= htmlspecialchars($ev['status']) ?></span>
                                </div>
                            </div>
                            <div class="bs-event-date">
                                <div><?= date('M j, Y', strtotime($useDate)) ?></div>
                                <div style="font-size:10.5px; font-weight:500; color:#88968d;"><?= date('g:i A', strtotime($useDate)) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notification / Announcements Card (30%) -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e8f5e9; color:#1f7a3d;">
                    <i class="bi bi-megaphone"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Announcements</div>
                    <div class="dsh-card-head-sub">System notices &amp; broadcasts</div>
                </div>
                <a href="announcements.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>

            <div>
                <?php if (!$notifs_dash_result || $notifs_dash_result->num_rows === 0): ?>
                    <div class="sad-empty" style="padding:48px 20px;">
                        <i class="bi bi-bell-slash" style="font-size:28px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
                        No announcements posted.
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
                    <div class="dash-notif-item">
                        <div class="dash-notif-icon">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <div class="dash-notif-body">
                            <div class="dash-notif-title" title="<?= htmlspecialchars($nt['title']) ?>"><?= htmlspecialchars($nt['title']) ?></div>
                            <div class="dash-notif-msg"><?= htmlspecialchars($nt['message']) ?></div>
                            <div class="dash-notif-meta">
                                <span style="background:<?= $targetColor ?>; color:<?= $targetFg ?>; padding:1px 6px; border-radius:4px; font-weight:700; font-size:9.5px;">
                                    <?= htmlspecialchars($targetBadge) ?>
                                </span>
                                <span><i class="bi bi-clock"></i> <?= date('M j, g:i A', strtotime($nt['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

    </div><!-- /.dash-row-70-30 -->
    <!-- ════════════════════════════════════════════════════════════
         ROW 3: PENDING APPLICATIONS (30%) + PENDING BIDS (70%)
         ════════════════════════════════════════════════════════════ -->
    <div class="dash-row-30-70">

        <!-- Pending Applications (30%) -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff8e1; color:#f9a825;">
                    <i class="bi bi-person-check"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Pending Applications</div>
                    <div class="dsh-card-head-sub">Bidder registrations to review</div>
                </div>
                <a href="account-management.php" class="dsh-card-head-link">Review <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="sad-list" style="padding:0 20px 16px;">
                <?php if ($pending_result->num_rows === 0): ?>
                    <div class="sad-empty">No pending applications.</div>
                <?php else: while ($pa = $pending_result->fetch_assoc()):
                    $initials = strtoupper(substr($pa['firstname'],0,1).substr($pa['lastname'],0,1));
                ?>
                    <div class="sad-row">
                        <div class="ap2-avatar ap2-avatar--bidder" style="width:34px; height:34px; font-size:11px; border-radius:9px; flex-shrink:0;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div class="sad-row-info">
                            <span class="sad-row-title"><?= htmlspecialchars($pa['firstname'].' '.$pa['lastname']) ?></span>
                            <span class="sad-row-sub"><?= htmlspecialchars($pa['business_name'] ?? 'No business name') ?> · <?= date('M j, Y', strtotime($pa['created_at'])) ?></span>
                        </div>
                        <span class="ap2-badge" style="background:#fff3e0; color:#e67e22;">PENDING</span>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

        <!-- Pending Bids (70%) -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff3e0; color:#e67e22;">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Pending Bids</div>
                    <div class="dsh-card-head-sub">Submissions awaiting admin verification</div>
                </div>
                <a href="bid_submissions.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>

            <div style="padding:4px 0 12px;">
                <?php if ($pending_bids_result->num_rows === 0): ?>
                    <div class="sad-empty" style="padding:48px 20px;">No pending bids awaiting review.</div>
                <?php else: while ($pb = $pending_bids_result->fetch_assoc()):
                    $initials = strtoupper(substr($pb['firstname'],0,1).substr($pb['lastname'],0,1));
                ?>
                    <div class="pbd-row-card">
                        <div class="pbd-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="pbd-info">
                            <div class="pbd-name"><?= htmlspecialchars($pb['firstname'].' '.$pb['lastname']) ?> <span style="font-size:12px; font-weight:500; color:#88968d;">(@<?= htmlspecialchars($pb['username']) ?>)</span></div>
                            <div class="pbd-proc" title="<?= htmlspecialchars($pb['proc_title']) ?>">
                                <i class="bi bi-folder2" style="color:#2F6FED; margin-right:4px;"></i> <?= htmlspecialchars($pb['proc_title']) ?>
                            </div>
                            <div class="pbd-meta-tags">
                                <span><i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($pb['philgeps_ref_no'] ?? 'N/A') ?></span>
                                <span>·</span>
                                <span><i class="bi bi-clock"></i> Submitted: <?= date('M j, Y · g:i A', strtotime($pb['submission_date'])) ?></span>
                            </div>
                        </div>
                        <div class="pbd-actions">
                            <a href="bid_submissions.php" class="proc-action-btn review" style="background:#E7EEFE; color:#2F6FED; font-size:12px; padding:6px 14px; border-radius:8px;">
                                <i class="bi bi-eye"></i> Review Bid
                            </a>
                        </div>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

    </div><!-- /.dash-row-30-70 -->


    <!-- ════════════════════════════════════════════════════════════
         ROW 4: QUICK ACTIONS
         ════════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Quick Actions</div>
    <div class="sad-actions">
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e8f5e9; color:#43a047;"><i class="bi bi-folder2-open"></i></div>
            <span>Procurements</span>
        </a>
        <a href="account-management.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fff8e1; color:#f9a825;"><i class="bi bi-person-check"></i></div>
            <span>Review Bidders</span>
        </a>
        <a href="bid_submissions.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e3f2fd; color:#1565c0;"><i class="bi bi-inbox"></i></div>
            <span>Bid Submissions</span>
        </a>
        <a href="announcements.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fce4ec; color:#c62828;"><i class="bi bi-megaphone"></i></div>
            <span>Announcements</span>
        </a>
        <a href="audit_trail.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e0f2f1; color:#00796b;"><i class="bi bi-journal-text"></i></div>
            <span>Audit Trail</span>
        </a>
        <a href="create_procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#f3e5f5; color:#7b1fa2;"><i class="bi bi-plus-circle"></i></div>
            <span>New Procurement</span>
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
