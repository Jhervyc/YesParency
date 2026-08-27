<?php
include("utils/protect-page.php");

// ── User stats ──────────────────────────────────────────────────────────────
$total_users   = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetch_row()[0];
$total_admins  = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0];
$total_bidders = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'bidder'")->fetch_row()[0];
$total_normal  = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetch_row()[0];

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
    SELECT id, title, opening_date, closing_date, status
    FROM procurements
    WHERE opening_date IS NOT NULL OR closing_date IS NOT NULL
    ORDER BY COALESCE(opening_date, closing_date) ASC
");
$cal_events = [];
while ($c = $cal_result->fetch_assoc()) $cal_events[] = $c;

// ── Pending bids overview (latest 6) ───────────────────────────────────────
$pending_bids_result = $conn->query("
    SELECT b.id AS bid_id, b.submission_date,
           u.firstname, u.lastname, u.email,
           p.title AS proc_title, p.id AS proc_id
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

// ── Recent registrations (latest 5) ────────────────────────────────────────
$users_result = $conn->query("
    SELECT user_id, firstname, lastname, username, role, created_at
    FROM users
    WHERE role != 'superadmin'
    ORDER BY created_at DESC
    LIMIT 5
");

function pct($part, $total) {
    return $total > 0 ? round($part / $total * 100) : 0;
}



// ── Stats for user donut ─────────────────────────────────────────────────────
$user_counts = [
    'admin'  => $total_admins,
    'bidder' => $total_bidders,
    'user'   => $total_normal,
];
$user_colors = [
    'admin'  => '#219653',
    'bidder' => '#F0B92E',
    'user'   => '#2F6FED',
];
$user_grad = '';
$user_cur = 0;
foreach ($user_counts as $role_key => $cnt) {
    $end = $user_cur + pct($cnt, $total_users);
    $user_grad .= "{$user_colors[$role_key]} {$user_cur}% {$end}%, ";
    $user_cur = $end;
}
$user_grad = rtrim($user_grad, ', ');
if (!$user_grad || $total_users == 0) $user_grad = '#e5eae4 0% 100%';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Calendar ── */
        .dash-cal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr 1fr;
            gap: 2px;
        }
        .dash-cal-dow {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #aaa;
            padding: 6px 0 8px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .dash-cal-day {
            aspect-ratio: 1;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding-top: 6px;
            font-size: 12px;
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
        .dash-cal-day.other-month { color: #ccc; }
        .dash-cal-dot-wrap {
            display: flex;
            gap: 2px;
            margin-top: 3px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .dash-cal-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }
        .dash-cal-dot.opening { background: #1f7a3d; }
        .dash-cal-dot.closing { background: #c23b3b; }

        /* Cal event list */
        .dash-cal-events {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 16px;
        }
        .dash-cal-event-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 9px;
            background: #f7faf8;
            border: 1px solid #eaeeec;
        }
        .dash-cal-event-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .dash-cal-event-badge.opening { background: #e4f5ea; color: #1f7a3d; }
        .dash-cal-event-badge.closing { background: #fbe1e1; color: #c23b3b; }
        .dash-cal-event-title {
            font-size: 12px;
            font-weight: 600;
            color: #1a1a1a;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dash-cal-event-date {
            font-size: 11px;
            color: #aaa;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Two-col bottom grid ── */
        .dash-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 900px) {
            .dash-two-col { grid-template-columns: 1fr; }
        }

        /* ── Bid Schedule (1/4) & Pending Bids (3/5) row ── */
        .dash-schedule-bids-row {
            display: grid;
            grid-template-columns: 1fr 2.4fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 1024px) {
            .dash-schedule-bids-row { grid-template-columns: 1fr; }
        }

        /* Pending bid card row */
        .pbd-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid #f0f4f2;
        }
        .pbd-row:last-child { border-bottom: none; }
        .pbd-avatar {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: #e8f5e9;
            color: #1f7a3d;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pbd-info { flex: 1; min-width: 0; }
        .pbd-name {
            font-size: 12.5px;
            font-weight: 600;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pbd-proc {
            font-size: 11px;
            color: #aaa;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pbd-date {
            font-size: 10.5px;
            color: #bbb;
            white-space: nowrap;
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

    <!-- ════ USER STATISTICS PANEL ════ -->
    <div class="sp-panel" style="margin-bottom:20px;">
        <div class="sp-panel-head">
            <div class="sp-panel-title">
                <div class="sp-title-icon"><i class="bi bi-people"></i></div>
                User Statistics
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="sp-panel-sub">Breakdown by registered account role</span>
                <a href="user-role-management.php" class="dsh-card-head-link">Manage <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>

        <div class="sp-stats-body">
            <!-- Donut -->
            <div class="sp-donut-wrap">
                <div class="sp-donut" style="background:conic-gradient(<?= $user_grad ?>);"></div>
                <div class="sp-donut-hole">
                    <div class="sp-donut-num"><?= $total_users ?></div>
                    <div class="sp-donut-lbl">Total<br>Users</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="sp-legend">
                <?php
                $user_legend = [
                    'admin'  => ['label' => 'Administrators', 'color' => '#219653', 'soft' => '#E4F5EA', 'count' => $total_admins],
                    'bidder' => ['label' => 'Bidders',        'color' => '#C99A1D', 'soft' => '#FCF1CF', 'count' => $total_bidders],
                    'user'   => ['label' => 'Normal Users',   'color' => '#2F6FED', 'soft' => '#E7EEFE', 'count' => $total_normal],
                ];
                foreach ($user_legend as $key => $l):
                    $cnt = $l['count'];
                    $p   = pct($cnt, $total_users);
                ?>
                <div class="sp-legend-row">
                    <span class="sp-legend-dot" style="background:<?= $l['color'] ?>"></span>
                    <span class="sp-legend-label"><?= $l['label'] ?></span>
                    <span class="sp-legend-value"><?= $cnt ?></span>
                    <span class="sp-legend-pct" style="color:<?= $l['color'] ?>; background:<?= $l['soft'] ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
                <div class="sp-legend-row sp-legend-total">
                    <span class="sp-legend-dot" style="background:#F0B92E"></span>
                    <span class="sp-legend-label">All Users</span>
                    <span class="sp-legend-value" style="color:#F0B92E"><?= $total_users ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Procurements + Bids side by side ── -->
    <div class="dash-two-col" style="margin-bottom:20px;">

        <!-- Procurements panel -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e8f5e9;color:#43a047;">
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
                    <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-folder2" style="color:#06251b;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $total_procs ?></div>
                    <div class="dsh-stat-lbl">Total</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#43a047 0% <?= pct($open_procs,$total_procs) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#43a047;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $open_procs ?></div>
                    <div class="dsh-stat-lbl">Open</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#546e7a 0% <?= pct($draft_procs,$total_procs) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-pencil-square" style="color:#546e7a;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $draft_procs ?></div>
                    <div class="dsh-stat-lbl">Drafts</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#f9a825 0% <?= pct($awarded_procs,$total_procs) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-trophy" style="color:#f9a825;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $awarded_procs ?></div>
                    <div class="dsh-stat-lbl">Awarded</div>
                </div>
            </div>
        </div>

        <!-- Bids panel -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff3e0;color:#e67e22;">
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
                    <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-inbox" style="color:#06251b;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $total_bids ?></div>
                    <div class="dsh-stat-lbl">Total</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#e67e22 0% <?= pct($pending_bids,$total_bids) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:#e67e22;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num" style="color:<?= $pending_bids > 0 ? '#e67e22' : 'inherit' ?>"><?= $pending_bids ?></div>
                    <div class="dsh-stat-lbl">Pending</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#1f7a3d 0% <?= pct($submitted_bids,$total_bids) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-check-circle" style="color:#1f7a3d;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $submitted_bids ?></div>
                    <div class="dsh-stat-lbl">Verified</div>
                </div>
                <div class="dsh-stat-divider"></div>
                <div class="dsh-stat-item">
                    <div class="ap2-ring" style="background:conic-gradient(#c23b3b 0% <?= pct($rejected_bids,$total_bids) ?>%,#e7ece9 0%);">
                        <div class="ap2-ring-inner"><i class="bi bi-x-circle" style="color:#c23b3b;font-size:14px;"></i></div>
                    </div>
                    <div class="dsh-stat-num"><?= $rejected_bids ?></div>
                    <div class="dsh-stat-lbl">Rejected</div>
                </div>
            </div>
        </div>

    </div><!-- /.dash-two-col stats -->

    <!-- ── Calendar + Pending Bids ── -->
    <div class="dash-schedule-bids-row">

        <!-- Calendar -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e7eefe;color:#2F6FED;">
                    <i class="bi bi-calendar3"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Bid Schedule</div>
                    <div class="dsh-card-head-sub">Opening &amp; deadline dates</div>
                </div>
                <a href="procurement.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div style="padding:0 20px 4px;">
                <?php
                $today     = new DateTime();
                $year      = (int)$today->format('Y');
                $month     = (int)$today->format('n');
                $todayDay  = (int)$today->format('j');
                $firstDay  = new DateTime("$year-$month-01");
                $daysInMonth = (int)$firstDay->format('t');
                $startDow  = (int)$firstDay->format('w'); // 0=Sun

                // Map events to days
                $eventsByDay = [];
                foreach ($cal_events as $ev) {
                    if ($ev['opening_date']) {
                        $d = (int)(new DateTime($ev['opening_date']))->format('j');
                        $m = (int)(new DateTime($ev['opening_date']))->format('n');
                        $y = (int)(new DateTime($ev['opening_date']))->format('Y');
                        if ($m === $month && $y === $year)
                            $eventsByDay[$d][] = ['type'=>'opening','title'=>$ev['title']];
                    }
                    if ($ev['closing_date']) {
                        $d = (int)(new DateTime($ev['closing_date']))->format('j');
                        $m = (int)(new DateTime($ev['closing_date']))->format('n');
                        $y = (int)(new DateTime($ev['closing_date']))->format('Y');
                        if ($m === $month && $y === $year)
                            $eventsByDay[$d][] = ['type'=>'closing','title'=>$ev['title']];
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

                    <?php for($i=0;$i<$startDow;$i++): ?>
                        <div class="dash-cal-day empty"></div>
                    <?php endfor; ?>

                    <?php for($d=1;$d<=$daysInMonth;$d++):
                        $isToday    = $d === $todayDay;
                        $hasEvent   = isset($eventsByDay[$d]);
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
                <div style="display:flex;flex-wrap:wrap;gap:10px 16px;margin-top:12px;font-size:11px;color:#777;">
                    <span style="display:flex;align-items:center;gap:5px;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#1f7a3d;display:inline-block;"></span> Bid Opening
                    </span>
                    <span style="display:flex;align-items:center;gap:5px;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#c23b3b;display:inline-block;"></span> Submission Deadline
                    </span>
                </div>
            </div>

            <!-- Upcoming events list -->
            <?php
            $upcoming = array_filter($cal_events, function($ev) use ($today) {
                $d = $ev['opening_date'] ?? $ev['closing_date'];
                return $d && new DateTime($d) >= $today;
            });
            $upcoming = array_slice(array_values($upcoming), 0, 4);
            ?>
            <?php if (!empty($upcoming)): ?>
            <div style="padding:0 20px 16px;">
                <div style="font-size:11px;font-weight:700;color:#aaa;letter-spacing:.5px;text-transform:uppercase;margin:14px 0 8px;">Upcoming</div>
                <div class="dash-cal-events">
                    <?php foreach($upcoming as $ev):
                        $useDate = $ev['opening_date'] ?? $ev['closing_date'];
                        $type    = $ev['opening_date'] ? 'opening' : 'closing';
                        $label   = $type === 'opening' ? 'BID OPEN' : 'DEADLINE';
                    ?>
                    <div class="dash-cal-event-row">
                        <span class="dash-cal-event-badge <?= $type ?>"><?= $label ?></span>
                        <span class="dash-cal-event-title"><?= htmlspecialchars($ev['title']) ?></span>
                        <span class="dash-cal-event-date"><?= date('M j', strtotime($useDate)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pending Bids Overview -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff3e0;color:#e67e22;">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Pending Bids</div>
                    <div class="dsh-card-head-sub">Awaiting admin review</div>
                </div>
                <a href="bid_submissions.php" class="dsh-card-head-link">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div style="padding:0 20px 20px;">
                <?php if ($pending_bids_result->num_rows === 0): ?>
                    <div class="sad-empty">No pending bids.</div>
                <?php else: while ($pb = $pending_bids_result->fetch_assoc()):
                    $initials = strtoupper(substr($pb['firstname'],0,1).substr($pb['lastname'],0,1));
                ?>
                    <div class="pbd-row">
                        <div class="pbd-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="pbd-info">
                            <div class="pbd-name"><?= htmlspecialchars($pb['firstname'].' '.$pb['lastname']) ?></div>
                            <div class="pbd-proc"><?= htmlspecialchars($pb['proc_title']) ?></div>
                        </div>
                        <div class="pbd-date"><?= date('M j', strtotime($pb['submission_date'])) ?></div>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

    </div><!-- /.dash-two-col -->

    <!-- ── Pending Applications + Recent Registrations ── -->
    <div class="dash-two-col">

        <!-- Pending Applications -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#fff8e1;color:#f9a825;">
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
                        <div class="ap2-avatar ap2-avatar--bidder" style="width:34px;height:34px;font-size:11px;border-radius:9px;flex-shrink:0;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div class="sad-row-info">
                            <span class="sad-row-title"><?= htmlspecialchars($pa['firstname'].' '.$pa['lastname']) ?></span>
                            <span class="sad-row-sub"><?= htmlspecialchars($pa['business_name'] ?? 'No business name') ?> · <?= date('M j, Y', strtotime($pa['created_at'])) ?></span>
                        </div>
                        <span class="ap2-badge" style="background:#fff3e0;color:#e67e22;">PENDING</span>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

        <!-- Recent Registrations -->
        <div class="ap2-card">
            <div class="dsh-card-head">
                <div class="dsh-card-head-icon" style="background:#e3f2fd;color:#1565c0;">
                    <i class="bi bi-person-plus"></i>
                </div>
                <div class="dsh-card-head-text">
                    <div class="dsh-card-head-title">Recent Registrations</div>
                    <div class="dsh-card-head-sub">Newest accounts in the system</div>
                </div>
                <a href="user-role-management.php" class="dsh-card-head-link">Manage <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="sad-list" style="padding:0 20px 16px;">
                <?php if ($users_result->num_rows === 0): ?>
                    <div class="sad-empty">No recent registrations.</div>
                <?php else: while ($u = $users_result->fetch_assoc()):
                    $initials  = strtoupper(substr($u['firstname'],0,1).substr($u['lastname'],0,1));
                    $roleClass = $u['role'] === 'admin' ? 'ap2-avatar--admin' : ($u['role'] === 'bidder' ? 'ap2-avatar--bidder' : 'ap2-avatar--user');
                    $badgeClass= $u['role'] === 'admin' ? 'ap2-badge-admin' : ($u['role'] === 'bidder' ? 'ap2-badge-bidder' : 'ap2-badge-user');
                ?>
                    <div class="sad-row">
                        <div class="ap2-avatar <?= $roleClass ?>" style="width:34px;height:34px;font-size:11px;border-radius:9px;flex-shrink:0;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div class="sad-row-info">
                            <span class="sad-row-title"><?= htmlspecialchars($u['firstname'].' '.$u['lastname']) ?></span>
                            <span class="sad-row-sub">@<?= htmlspecialchars($u['username']) ?> · <?= date('M j, Y', strtotime($u['created_at'])) ?></span>
                        </div>
                        <span class="ap2-badge <?= $badgeClass ?>"><?= strtoupper($u['role']) ?></span>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>

    </div><!-- /.dash-two-col -->

    <!-- ── Quick Actions ── -->
    <div class="sad-section-label">Quick Actions</div>
    <div class="sad-actions">
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e8f5e9;color:#43a047;"><i class="bi bi-folder2-open"></i></div>
            <span>Procurements</span>
        </a>
        <a href="account-management.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fff8e1;color:#f9a825;"><i class="bi bi-person-check"></i></div>
            <span>Review Bidders</span>
        </a>
        <a href="bid_submissions.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#e3f2fd;color:#1565c0;"><i class="bi bi-inbox"></i></div>
            <span>Bid Submissions</span>
        </a>
        <a href="user-role-management.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#fce4ec;color:#c62828;"><i class="bi bi-person-gear"></i></div>
            <span>Role Management</span>
        </a>
        <a href="create_procurement.php" class="sad-action-card">
            <div class="sad-action-icon" style="background:#f3e5f5;color:#7b1fa2;"><i class="bi bi-plus-circle"></i></div>
            <span>New Procurement</span>
        </a>
    </div>

</div>
</main>



<script>



</script>

</body>
</html>
