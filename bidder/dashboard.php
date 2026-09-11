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
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-dashboard.css">
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
                
                <?php $pending_active = $pending_verification > 0; ?>

                <!-- Open Procurements -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="--ring-color:#43a047; --pct:<?= pct($open_procs, max($total_procs, 1)) ?>%">
                        <div class="stat-donut-inner">
                            <i class="bi bi-folder2-open"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num"><?= $open_procs ?></div>
                        <div class="stat-donut-lbl">Open Bids</div>
                    </div>
                </div>

                <!-- My Active Bids -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="--ring-color:#2F6FED; --pct:<?= pct($my_active_bids, max($my_total_bids, 1)) ?>%">
                        <div class="stat-donut-inner">
                            <i class="bi bi-inbox"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num"><?= $my_active_bids ?></div>
                        <div class="stat-donut-lbl">Active Bids</div>
                    </div>
                </div>

                <!-- Pending Verification -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="--ring-color:<?= $pending_active ? '#e67e22' : '#8B958E' ?>; --pct:<?= pct($pending_verification, max($my_total_bids, 1)) ?>%">
                        <div class="stat-donut-inner">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                    <div class="stat-donut-info">
                        <div class="stat-donut-num <?= $pending_active ? 'stat-donut-num--warn' : '' ?>"><?= $pending_verification ?></div>
                        <div class="stat-donut-lbl">Pending Review</div>
                    </div>
                </div>

                <!-- Upcoming Openings -->
                <div class="stat-donut-card">
                    <div class="stat-donut-ring" style="--ring-color:#8e44ad; --pct:<?= pct($upcoming_openings, max($total_procs, 1)) ?>%">
                        <div class="stat-donut-inner">
                            <i class="bi bi-calendar-event"></i>
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
                            <i class="bi bi-folder2-open clr-green"></i> Open Procurements
                        </div>
                        <a href="procurement.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if (!$open_procs_result || $open_procs_result->num_rows === 0): ?>
                            <div class="sub-empty">
                                <i class="bi bi-folder-x sub-empty-icon"></i>
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
                                        <span class="sub-item-meta-abc">₱<?= number_format($op['abc'], 2) ?></span>
                                        <span>·</span>
                                        <span><?= $op['closing_date'] ? date('M j', strtotime($op['closing_date'])) : 'TBD' ?></span>
                                    </div>
                                </div>
                                <div class="sub-item-action">
                                    <?php if ($alreadyBid): ?>
                                        <a href="view_procurement.php?id=<?= $op['id'] ?>" class="proc-action-btn--manage">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php else: ?>
                                        <a href="view_procurement.php?id=<?= $op['id'] ?>" class="proc-action-btn--view">
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
                            <i class="bi bi-inbox clr-blue"></i> My Recent Bids
                        </div>
                        <a href="my_bids.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if (!$recent_bids_result || $recent_bids_result->num_rows === 0): ?>
                            <div class="sub-empty">
                                <i class="bi bi-inbox sub-empty-icon"></i>
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
                                <div class="sub-item-action sub-item-action--gap">
                                    <span class="status-pill-mini <?= $bstat ?>">
                                        <?= ucfirst($bstat) ?>
                                    </span>
                                    <a href="my_bids.php" class="proc-action-btn--view">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>

            </div>

            <!-- 4. Announcements / Notifications Card -->
            <div class="sub-card sub-card--mt">
                <div class="sub-card-head">
                    <div class="sub-card-title">
                        <i class="bi bi-megaphone clr-orange"></i> System Announcements &amp; Notices
                    </div>
                    <a href="notification.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <div>
                    <?php if (!$notifs_result || $notifs_result->num_rows === 0): ?>
                        <div class="sub-empty sub-empty--sm">
                            <i class="bi bi-bell-slash sub-empty-icon"></i>
                            No recent announcements.
                        </div>
                    <?php else: while ($nt = $notifs_result->fetch_assoc()):
                        $targetBadge = strtoupper($nt['target_type']);
                        $targetBadgeClass = 'side-notif-target-badge--all';
                        if ($nt['target_type'] === 'role') {
                            $targetBadge = strtoupper($nt['target_role'] ?? 'BIDDER');
                            $targetBadgeClass = 'side-notif-target-badge--role';
                        }
                    ?>
                        <div class="side-notif-row">
                            <div class="side-notif-icon">
                                <i class="bi bi-bell-fill"></i>
                            </div>
                            <div class="side-notif-body">
                                <div class="side-notif-top-row">
                                    <div class="side-notif-title" title="<?= htmlspecialchars($nt['title']) ?>">
                                        <?= htmlspecialchars($nt['title']) ?>
                                    </div>
                                    <span class="side-notif-time">
                                        <i class="bi bi-clock"></i> <?= timeAgo($nt['created_at']) ?>
                                    </span>
                                </div>
                                <div class="side-notif-snippet">
                                    <?= htmlspecialchars($nt['message']) ?>
                                </div>
                                <div class="side-notif-foot-row">
                                    <div class="side-notif-meta">
                                        <span class="side-notif-target-badge <?= $targetBadgeClass ?>">
                                            <?= htmlspecialchars($targetBadge) ?>
                                        </span>
                                        <span>From: <?= htmlspecialchars(trim(($nt['firstname'] ?? '') . ' ' . ($nt['lastname'] ?? '')) ?: 'BAC Secretariat') ?></span>
                                    </div>
                                    <a href="notification.php" class="proc-action-btn--view proc-action-btn--sm">
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
                        <i class="bi bi-calendar3 clr-blue"></i> Bid Calendar &amp; Schedule
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

                    <div class="cal-legend">
                        <span class="cal-legend-item">
                            <span class="cal-legend-dot cal-legend-dot--opening"></span> Bid Opening
                        </span>
                        <span class="cal-legend-item">
                            <span class="cal-legend-dot cal-legend-dot--closing"></span> Deadline
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
                            <div class="side-events-empty">No upcoming milestones this month.</div>
                        <?php else: foreach ($upcoming as $uev):
                            $isOpening = !empty($uev['opening_date']);
                            $eventDate = $isOpening ? $uev['opening_date'] : $uev['closing_date'];
                            $dotClass  = $isOpening ? 'side-event-dot--opening' : 'side-event-dot--closing';
                        ?>
                            <a href="view_procurement.php?id=<?= $uev['id'] ?>" class="side-event-item">
                                <div class="side-event-left">
                                    <span class="side-event-dot <?= $dotClass ?>"></span>
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
            <div class="sad-action-icon sad-action-icon--green"><i class="bi bi-folder2-open"></i></div>
            <span>Browse Procurements</span>
        </a>
        <a href="my_bids.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--blue"><i class="bi bi-inbox"></i></div>
            <span>My Submissions</span>
        </a>
        <a href="notification.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--amber"><i class="bi bi-bell"></i></div>
            <span>Notifications</span>
        </a>
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--purple"><i class="bi bi-calendar3"></i></div>
            <span>Bid Calendar</span>
        </a>
        <a href="my_bids.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--teal"><i class="bi bi-file-earmark-check"></i></div>
            <span>Verify Bids</span>
        </a>
        <a href="../logout.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--red"><i class="bi bi-box-arrow-left"></i></div>
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
