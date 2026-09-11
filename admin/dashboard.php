<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

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

// ── Bid stats ───────────────────────────────────────────────────────────────
$total_bids     = (int)($conn->query("SELECT COUNT(*) FROM bids")->fetch_row()[0] ?? 0);
$pending_bids   = (int)($conn->query("SELECT COUNT(*) FROM bids WHERE status = 'pending'")->fetch_row()[0] ?? 0);
$submitted_bids = (int)($conn->query("SELECT COUNT(*) FROM bids WHERE status = 'submitted'")->fetch_row()[0] ?? 0);
$rejected_bids  = (int)($conn->query("SELECT COUNT(*) FROM bids WHERE status = 'rejected'")->fetch_row()[0] ?? 0);

// ── Application stats ───────────────────────────────────────────────────────
$total_apps   = (int)($conn->query("SELECT COUNT(*) FROM bidder_profiles")->fetch_row()[0] ?? 0);
$pending_apps = (int)($conn->query("SELECT COUNT(*) FROM bidder_profiles WHERE application_status = 'pending'")->fetch_row()[0] ?? 0);

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

// ── Pending bids overview (latest 4) ───────────────────────────────────────
$pending_bids_result = $conn->query("
    SELECT b.id AS bid_id, b.submission_date,
           u.firstname, u.lastname, u.email, u.username,
           p.title AS proc_title, p.id AS proc_id, p.slsu_ref_no
    FROM bids b
    JOIN users u  ON b.bidder_id    = u.user_id
    JOIN procurements p ON b.procurement_id = p.id
    WHERE b.status = 'pending'
    ORDER BY b.submission_date DESC
    LIMIT 4
");

// ── Pending applications (latest 4) ────────────────────────────────────────
$pending_result = $conn->query("
    SELECT u.firstname, u.lastname, bp.business_name, bp.created_at
    FROM bidder_profiles bp
    JOIN users u ON bp.user_id = u.user_id
    WHERE bp.application_status = 'pending'
    ORDER BY bp.created_at DESC
    LIMIT 4
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
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-dashboard.css">
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
             2. Stats for Procurement (Reduced Height)
             3. Stats for Bids (Reduced Height)
             4. Pending Applications & Pending Bids Side by Side
             5. Notifications
             ════════════════════════════════════════════════════════ -->
        <div class="dash-col-60">

            <!-- 1. Greetings Card -->
            <div class="dash-greeting-card">
                <div class="greeting-header">
                    <div class="greeting-title">Welcome back, <?= htmlspecialchars($admin_name) ?> </div>
                    <span class="greeting-badge">
                        <i class="bi bi-shield-check"></i> BAC ADMINISTRATOR
                    </span>
                </div>
                <div class="greeting-sub">
                    Municipal Bids &amp; Awards Committee Portal — Monitor active procurements, verify bidder submissions, and manage announcements in real-time.
                </div>
                <div class="greeting-pills">
                    <div class="greeting-pill">
                        <i class="bi bi-folder2-open"></i> <strong><?= $open_procs ?></strong> Open Opportunities
                    </div>
                    <div class="greeting-pill">
                        <i class="bi bi-hourglass-split"></i> <strong><?= $pending_bids ?></strong> Pending Bids
                    </div>
                    <div class="greeting-pill">
                        <i class="bi bi-person-check"></i> <strong><?= $pending_apps ?></strong> Pending Registrations
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
                        <i class="bi bi-pie-chart-fill clr-forest"></i> Procurement Overview
                    </div>
                    <a href="procurement.php" class="compact-stat-link">Manage Procurements <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="compact-stat-body">
                    <div class="compact-donut-wrap" style="background:conic-gradient(#1f7a3d 0% <?= pct($open_procs, max($total_procs,1)) ?>%, #c23b3b 0% <?= pct($open_procs+$closed_procs, max($total_procs,1)) ?>%, #f0b92e 0% 100%);">
                        <div class="compact-donut-inner">
                            <span class="compact-donut-num"><?= $total_procs ?></span>
                            <span class="donut-label">TOTAL</span>
                        </div>
                    </div>
                    <div class="compact-stat-metrics">
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--forest"><?= $open_procs ?></div>
                            <div class="compact-metric-lbl">Open</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--gold"><?= $draft_procs ?></div>
                            <div class="compact-metric-lbl">Draft</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--red"><?= $closed_procs ?></div>
                            <div class="compact-metric-lbl">Closed</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--blue"><?= $awarded_procs ?></div>
                            <div class="compact-metric-lbl">Awarded</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Bid Statistics (Reduced Height) -->
            <div class="compact-stat-card">
                <div class="compact-stat-top">
                    <div class="compact-stat-title">
                        <i class="bi bi-inbox-fill clr-blue"></i> Bid Submissions Overview
                    </div>
                    <a href="bid_submissions.php" class="compact-stat-link">View All Bids <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="compact-stat-body">
                    <div class="compact-donut-wrap" style="background:conic-gradient(#2F6FED 0% <?= pct($submitted_bids, max($total_bids,1)) ?>%, #e67e22 0% <?= pct($submitted_bids+$pending_bids, max($total_bids,1)) ?>%, #c23b3b 0% 100%);">
                        <div class="compact-donut-inner">
                            <span class="compact-donut-num"><?= $total_bids ?></span>
                            <span class="donut-label">BIDS</span>
                        </div>
                    </div>
                    <div class="compact-stat-metrics">
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--amber"><?= $pending_bids ?></div>
                            <div class="compact-metric-lbl">Pending Review</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--blue"><?= $submitted_bids ?></div>
                            <div class="compact-metric-lbl">Submitted</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--forest"><?= max(0, $total_bids - $pending_bids - $submitted_bids - $rejected_bids) ?></div>
                            <div class="compact-metric-lbl">Opened</div>
                        </div>
                        <div class="compact-metric-item">
                            <div class="compact-metric-num metric-num--red"><?= $rejected_bids ?></div>
                            <div class="compact-metric-lbl">Rejected</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Side-by-Side: Pending Applications & Pending Bids -->
            <div class="dash-side-by-side">

                <!-- Left: Pending Applications -->
                <div class="sub-card">
                    <div class="sub-card-head">
                        <div class="sub-card-title">
                            <i class="bi bi-person-check clr-amber-gold"></i> Pending Applications
                        </div>
                        <a href="account-management.php" class="sub-card-link">Review <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if ($pending_result->num_rows === 0): ?>
                            <div class="empty-mini">
                                <i class="bi bi-check-circle empty-mini-icon"></i>
                                No pending bidder registrations.
                            </div>
                        <?php else: while ($pa = $pending_result->fetch_assoc()):
                            $initials = strtoupper(substr($pa['firstname'],0,1).substr($pa['lastname'],0,1));
                        ?>
                            <div class="sub-item-row">
                                <div class="sub-item-main">
                                    <div class="sub-item-title"><?= htmlspecialchars($pa['firstname'].' '.$pa['lastname']) ?></div>
                                    <div class="sub-item-meta">
                                        <span><?= htmlspecialchars($pa['business_name'] ?? 'No business name') ?></span>
                                        <span>·</span>
                                        <span><?= date('M j', strtotime($pa['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="sub-item-action">
                                    <a href="account-management.php" class="proc-action-btn review review-pill">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>

                <!-- Right: Pending Bids -->
                <div class="sub-card">
                    <div class="sub-card-head">
                        <div class="sub-card-title">
                            <i class="bi bi-hourglass-split clr-amber"></i> Pending Bids
                        </div>
                        <a href="bid_submissions.php" class="sub-card-link">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="sub-card-list">
                        <?php if ($pending_bids_result->num_rows === 0): ?>
                            <div class="empty-mini">
                                <i class="bi bi-inbox empty-mini-icon"></i>
                                No pending bids to verify.
                            </div>
                        <?php else: while ($pb = $pending_bids_result->fetch_assoc()): ?>
                            <div class="sub-item-row">
                                <div class="sub-item-main">
                                    <div class="sub-item-title" title="<?= htmlspecialchars($pb['proc_title']) ?>">
                                        <?= htmlspecialchars($pb['proc_title']) ?>
                                    </div>
                                    <div class="sub-item-meta">
                                        <span>By: <?= htmlspecialchars($pb['firstname'].' '.$pb['lastname']) ?></span>
                                        <span>·</span>
                                        <span><?= date('M j', strtotime($pb['submission_date'])) ?></span>
                                    </div>
                                </div>
                                <div class="sub-item-action">
                                    <a href="bid_submissions.php" class="proc-action-btn review review-pill">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>

            </div>

            <!-- 5. System Announcements & Notices -->
            <div class="sub-card">
                <div class="sub-card-head">
                    <div class="sub-card-title">
                        <i class="bi bi-megaphone clr-amber"></i> System Announcements &amp; Broadcasts
                    </div>
                    <a href="announcements.php" class="sub-card-link">Manage Broadcasts <i class="bi bi-arrow-right"></i></a>
                </div>
                <div>
                    <?php if (!$notifs_dash_result || $notifs_dash_result->num_rows === 0): ?>
                        <div class="empty-announce">
                            <i class="bi bi-bell-slash empty-announce-icon"></i>
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
                                <div class="notif-title-row">
                                    <div class="side-notif-title" title="<?= htmlspecialchars($nt['title']) ?>">
                                        <?= htmlspecialchars($nt['title']) ?>
                                    </div>
                                    <span class="notif-time">
                                        <i class="bi bi-clock"></i> <?= timeAgo($nt['created_at']) ?>
                                    </span>
                                </div>
                                <div class="side-notif-snippet">
                                    <?= htmlspecialchars($nt['message']) ?>
                                </div>
                                <div class="notif-meta-row">
                                    <div class="side-notif-meta">
                                        <span class="notif-target-badge" style="--badge-bg:<?= $targetColor ?>; --badge-fg:<?= $targetFg ?>;">
                                            <?= htmlspecialchars($targetBadge) ?>
                                        </span>
                                        <span>By: <?= htmlspecialchars(trim(($nt['firstname'] ?? '') . ' ' . ($nt['lastname'] ?? '')) ?: 'Admin') ?></span>
                                    </div>
                                    <a href="announcements.php" class="proc-action-btn review review-pill">
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
                    <div class="profile-detail-item profile-detail-item--wide">
                        <div class="profile-detail-lbl">Official Email</div>
                        <div class="profile-detail-val" title="<?= htmlspecialchars($admin_email) ?>"><?= htmlspecialchars($admin_email) ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Bid Calendar & Schedules Card -->
            <div class="side-panel-card">
                <div class="side-panel-head">
                    <div class="side-panel-title">
                        <i class="bi bi-calendar3 clr-forest"></i>
                        <span>Schedule of Activities</span>
                    </div>
                    <a href="procurement.php" class="side-panel-link">View all <i class="bi bi-arrow-right"></i></a>
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

                <div class="legend-row">
                    <span class="legend-item">
                        <span class="legend-dot legend-dot--forest"></span> Bid Opening
                    </span>
                    <span class="legend-item">
                        <span class="legend-dot legend-dot--amber"></span> Deadline
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
        <a href="procurement.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--green"><i class="bi bi-folder2-open"></i></div>
            <span>Procurements</span>
        </a>
        <a href="account-management.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--blue"><i class="bi bi-people"></i></div>
            <span>Accounts</span>
        </a>
        <a href="bid_submissions.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--gold"><i class="bi bi-inbox"></i></div>
            <span>Bid Submissions</span>
        </a>
        <a href="audit_trail.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--purple"><i class="bi bi-journal-text"></i></div>
            <span>Audit Trail</span>
        </a>
        <a href="announcements.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--teal"><i class="bi bi-megaphone"></i></div>
            <span>Announcements</span>
        </a>
        <a href="../logout.php" class="sad-action-card">
            <div class="sad-action-icon sad-action-icon--red"><i class="bi bi-box-arrow-left"></i></div>
            <span>Logout</span>
        </a>
    </div>

</div>
</main>

<!-- Event Viewer Modal -->
<div class="modal-backdrop" id="calEventModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-calendar-event clr-forest"></i> <span id="calModalDate">Activities</span></h4>
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
            listEl.innerHTML = '<div class="mini-empty">No scheduled events this month.</div>';
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
            const typeClass = isOpening ? 'side-event-type--opening' : 'side-event-type--deadline';

            item.innerHTML = `
                <div class="side-event-main">
                    <div class="side-event-title" title="${e.title}">${e.title}</div>
                    <span class="side-event-type ${typeClass}">${typeLabel}</span>
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
            card.className = 'cal-event-card';

            let typeBadge = '';
            if (e.opening_date && e.opening_date.includes(dateStr)) {
                typeBadge = '<span class="cal-type-badge cal-type-badge--opening">BID OPENING</span>';
            } else {
                typeBadge = '<span class="cal-type-badge cal-type-badge--deadline">SUBMISSION DEADLINE</span>';
            }

            card.innerHTML = `
                <div class="cal-event-head">
                    ${typeBadge}
                    <span class="cal-event-ref">Ref: ${e.slsu_ref_no || 'N/A'}</span>
                </div>
                <div class="cal-event-title">${e.title}</div>
                <div class="cal-event-foot">
                    <span class="cal-event-abc">
                        ${e.abc ? '₱' + Number(e.abc).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) : ''}
                    </span>
                    <a href="procurement.php" class="proc-action-btn review review-pill review-pill--dark">
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
