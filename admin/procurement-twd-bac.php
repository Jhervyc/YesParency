<?php
include("utils/protect-page.php");

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// ── Filters, Search, Sort & Pagination ────────────────────────────────────────
$search         = isset($_GET['search']) ? trim($_GET['search']) : '';
$mode_filter    = isset($_GET['mode']) ? trim($_GET['mode']) : 'all';
$status_filter  = isset($_GET['status']) ? trim($_GET['status']) : 'open';
$sort           = isset($_GET['sort']) ? trim($_GET['sort']) : 'closing_asc';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 10;
$offset         = ($page - 1) * $per_page;

// ── Calendar Events ───────────────────────────────────────────────────────────
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

// ── Ranking of Procurements Based on Closing Date (Top 5 Closing Soonest) ───────
$ranking_sql = "
    SELECT
        p.id,
        p.slsu_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
    FROM procurements p
    WHERE p.status = 'open' AND p.closing_date IS NOT NULL AND p.closing_date >= NOW()
    ORDER BY p.closing_date ASC
    LIMIT 5
";
$rank_stmt = $conn->query($ranking_sql);
$ranked_procs = $rank_stmt;

// ── Fetch Distinct Procurement Modes for Filter Dropdown ──────────────────────
$modes_res = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE status != 'draft' AND procurement_mode IS NOT NULL AND procurement_mode != '' ORDER BY procurement_mode ASC");
$avail_modes = [];
if ($modes_res) {
    while ($mr = $modes_res->fetch_assoc()) {
        $avail_modes[] = $mr['procurement_mode'];
    }
}
if (empty($avail_modes)) {
    $avail_modes = ['Public Bidding', 'Small Value Procurement', 'Shopping', 'Direct Contracting', 'Negotiated Procurement'];
}

// ── Query Construction for Main Tabular List ───────────────────────────────────
$where_parts = ["p.status != 'draft'"];
$params      = [];
$types       = '';

// Status Filter
if ($status_filter === 'open') {
    $where_parts[] = "p.status = 'open'";
} elseif ($status_filter === 'closed') {
    $where_parts[] = "p.status IN ('closed', 'awarded')";
}

// Procurement Mode Filter
if ($mode_filter !== 'all' && $mode_filter !== '') {
    $where_parts[] = "p.procurement_mode = ?";
    $params[] = $mode_filter;
    $types   .= 's';
}

// Search
if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ? OR p.procurement_mode LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// Sorting
$order_sql = "ORDER BY p.closing_date ASC, p.id DESC";
if ($sort === 'closing_desc')  $order_sql = "ORDER BY p.closing_date DESC, p.id DESC";
elseif ($sort === 'abc_desc') $order_sql = "ORDER BY p.abc DESC, p.id DESC";
elseif ($sort === 'abc_asc')  $order_sql = "ORDER BY p.abc ASC, p.id DESC";
elseif ($sort === 'newest')   $order_sql = "ORDER BY p.id DESC";

// Count Total Matching Records
$count_sql  = "SELECT COUNT(*) FROM procurements p $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_shown = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = max(1, ceil($total_shown / $per_page));

// Main Paged Query
$main_sql = "
    SELECT
        p.id,
        p.slsu_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
    FROM procurements p
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
";
$main_params = array_merge($params, [$per_page, $offset]);
$main_types  = $types . 'ii';
$main_stmt   = $conn->prepare($main_sql);
$main_stmt->bind_param($main_types, ...$main_params);
$main_stmt->execute();
$procs_result = $main_stmt->get_result();

// ── Overall Summary Counts ─────────────────────────────────────────────────────
$stat_all_res   = $conn->query("SELECT COUNT(*) FROM procurements WHERE status != 'draft'");
$stat_all       = (int)($stat_all_res ? $stat_all_res->fetch_row()[0] : 0);

$stat_open_res  = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'");
$stat_open      = (int)($stat_open_res ? $stat_open_res->fetch_row()[0] : 0);

$stat_close_res = $conn->query("SELECT COUNT(*) FROM procurements WHERE status IN ('closed', 'awarded')");
$stat_close     = (int)($stat_close_res ? $stat_close_res->fetch_row()[0] : 0);

$stat_urgent_res= $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)");
$stat_urgent    = (int)($stat_urgent_res ? $stat_urgent_res->fetch_row()[0] : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Opportunities | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared styles -->
    <!-- Dashboard styles -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-procurement-twd-bac.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php
$topbar_title = 'Procurements';
include("components/topbar.php");
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header">
        <h2>Procurement Opportunities</h2>
        <p>Explore all active, scheduled, and concluded municipal bidding projects and contracts.</p>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TOP SECTION (100% WIDTH): Summary Stat Cards
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Summary Overview</div>
    <div class="proc-stats-grid">

        <!-- 1. Total Opportunities -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="--ring-color:#06251b; --pct:100%;">
                <div class="proc-stat-inner">
                    <i class="bi bi-folder2-open clr-dark"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num"><?= $stat_all ?></div>
                <div class="proc-stat-lbl">Total Listed</div>
            </div>
        </div>

        <!-- 2. Active / Open for Bidding -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="--ring-color:#219653; --pct:<?= $stat_all > 0 ? round($stat_open / $stat_all * 100) : 0 ?>%;">
                <div class="proc-stat-inner">
                    <i class="bi bi-check-circle clr-green"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num proc-stat-num--open"><?= $stat_open ?></div>
                <div class="proc-stat-lbl">Open for Bidding</div>
            </div>
        </div>

        <!-- 3. Closing Soon (<= 3 days) -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="--ring-color:#e67e22; --pct:<?= $stat_all > 0 ? round($stat_urgent / $stat_all * 100) : 0 ?>%;">
                <div class="proc-stat-inner">
                    <i class="bi bi-hourglass-split clr-amber"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num proc-stat-num--urgent"><?= $stat_urgent ?></div>
                <div class="proc-stat-lbl">Closing Soon</div>
            </div>
        </div>

        <!-- 4. Closed / Awarded -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="--ring-color:#2F6FED; --pct:<?= $stat_all > 0 ? round($stat_close / $stat_all * 100) : 0 ?>%;">
                <div class="proc-stat-inner">
                    <i class="bi bi-archive clr-blue"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num proc-stat-num--close"><?= $stat_close ?></div>
                <div class="proc-stat-lbl">Closed / Concluded</div>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         MIDDLE SECTION: Calendar (30%) + Scheduled Activities List (70%)
         ══════════════════════════════════════════════════════════ -->
    <div class="proc-mid-layout">

        <!-- ── 30% Column: Mini Interactive Calendar ── -->
        <div class="proc-mid-col">
            <div class="sad-section-label">Bidding Calendar</div>

            <div class="side-cal-panel">
                <div class="side-cal-header">
                    <div class="side-cal-title">
                        <i class="bi bi-calendar3 clr-dark"></i>
                        <span>Schedule of Activities</span>
                    </div>
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
            </div>
        </div>

        <!-- ── 70% Column: Scheduled Events List ── -->
        <div class="proc-mid-col">
            <div class="sad-section-label">Scheduled Activities</div>

            <div class="side-cal-panel">
                <div class="side-cal-header">
                    <div class="side-cal-title">
                        <i class="bi bi-clock-history clr-forest"></i>
                        <span>Activities for <span id="eventListMonthLabel">This Month</span></span>
                    </div>
                    <span id="calEventsCountBadge" class="cal-events-badge">Events</span>
                </div>

                <!-- Event list for the selected month/day -->
                <div class="side-events-list" id="sideEventsList">
                    <!-- Filled by JS -->
                </div>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         URGENT OPPORTUNITIES (100% WIDTH): Scheduled Bids & Deadlines
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Scheduled Bids &amp; Deadlines</div>
    <div class="ranked-card-panel">
        <div class="ranked-card-header">
            <div class="ranked-card-title">
                <i class="bi bi-alarm clr-amber"></i>
                <span>Urgent Opportunities (Closing Soonest)</span>
            </div>
            <span class="mini-label">Top 5 Deadlines</span>
        </div>

        <?php if ($ranked_procs && $ranked_procs->num_rows > 0): ?>
            <?php $rnk = 1; while ($rp = $ranked_procs->fetch_assoc()):
                $days_left = ceil((strtotime($rp['closing_date']) - time()) / 86400);
                $is_urgent = ($days_left <= 3);
            ?>
                <div class="ranked-item-row">
                    <div class="rank-badge-num"><?= $rnk++ ?></div>
                    <div class="rank-main-info">
                        <div class="rank-item-title">
                            <a href="view_procurement.php?id=<?= $rp['id'] ?>" title="<?= htmlspecialchars($rp['title']) ?>">
                                <?= htmlspecialchars($rp['title']) ?>
                            </a>
                        </div>
                        <div class="rank-item-meta">
                            <span><i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($rp['slsu_ref_no'] ?: 'N/A') ?></span>
                            <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($rp['procurement_mode'] ?: 'Public Bidding') ?></span>
                            <span><i class="bi bi-calendar-x"></i> Deadline: <?= date('M j, Y', strtotime($rp['closing_date'])) ?></span>
                        </div>
                    </div>
                    <div class="rank-right-col">
                        <div class="rank-abc-val">₱<?= number_format((float)$rp['abc'], 2) ?></div>
                        <span class="rank-urgency-pill <?= $is_urgent ? 'urgent' : 'normal' ?>">
                            <i class="bi bi-clock"></i> <?= $days_left == 0 ? 'Closes Today' : ($days_left == 1 ? '1 day left' : $days_left . ' days left') ?>
                        </span>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="mini-empty">
                <i class="bi bi-inbox mini-empty-icon"></i>
                No active procurements currently closing soon.
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         BOTTOM SECTION (100% WIDTH): Tabular Procurement List
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">All Opportunities</div>
    <div class="proc-table-panel">

        <!-- Controls: Search + Filter Tabs + Mode Dropdown + Sort -->
        <div class="proc-controls-bar">
            <form method="GET" action="procurement.php" id="procFilterForm">

                <!-- Hidden inputs for sort -->
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">

                <div class="ap2-controls">

                    <!-- 1. Search Box -->
                    <div class="ap2-search-field">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search"
                            placeholder="Search by title, SLSU ref, or mode..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <!-- 2. Status Filter Tabs -->
                    <div class="ap2-status-group">
                        <?php
                        $status_tabs = [
                            'open'   => 'Open',
                            'all'    => 'All',
                            'closed' => 'Closed'
                        ];
                        foreach ($status_tabs as $val => $lbl):
                        ?>
                            <button type="submit" name="status" value="<?= $val ?>"
                                    class="ap2-filter-btn <?= $status_filter === $val ? 'active' : '' ?>">
                                <?= $lbl ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- 3. Procurement Mode Dropdown -->
                    <div class="proc-module-select">
                        <select name="mode" onchange="document.getElementById('procFilterForm').submit();">
                            <option value="all">All Modes</option>
                            <?php foreach ($avail_modes as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter === $m ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. Sort Dropdown -->
                    <div class="proc-module-select">
                        <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value; document.getElementById('procFilterForm').submit();">
                            <option value="closing_asc"  <?= $sort === 'closing_asc'  ? 'selected' : '' ?>>Closing Soonest</option>
                            <option value="closing_desc" <?= $sort === 'closing_desc' ? 'selected' : '' ?>>Closing Latest</option>
                            <option value="abc_desc"      <?= $sort === 'abc_desc'     ? 'selected' : '' ?>>ABC: High to Low</option>
                            <option value="abc_asc"       <?= $sort === 'abc_asc'      ? 'selected' : '' ?>>ABC: Low to High</option>
                            <option value="newest"        <?= $sort === 'newest'       ? 'selected' : '' ?>>Newest Listed</option>
                        </select>
                    </div>

                    <!-- 5. Search Button -->
                    <button type="submit" class="proc-go-btn">
                        <i class="bi bi-search"></i> Search
                    </button>

                </div>
            </form>
        </div>

        <!-- ── Main Table List ── -->
        <?php if ($total_shown === 0): ?>
            <div class="results-empty">
                <i class="bi bi-search results-empty-icon"></i>
                <p class="results-empty-title">No Matching Opportunities Found</p>
                <p class="results-empty-desc">We couldn't find any procurements matching your current search or filter criteria.</p>
                <a href="procurement.php" class="vp-back-link">
                    Reset Filters
                </a>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="proc-table">
                    <thead>
                        <tr>
                            <th class="col-ref-th">SLSU Ref</th>
                            <th>Procurement Project Title</th>
                            <th>Procurement Mode</th>
                            <th>Approved Budget (ABC)</th>
                            <th>Deadline / Urgency</th>
                            <th class="col-actions-th">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $procs_result->fetch_assoc()):
                            $st = strtolower($row['status'] ?? 'open');
                            $isOpen = ($st === 'open');
                            $daysLeft = null;
                            if ($row['closing_date']) {
                                $daysLeft = ceil((strtotime($row['closing_date']) - time()) / 86400);
                            }
                        ?>
                            <tr>
                                <td class="proc-ref-cell">
                                    <i class="bi bi-hash"></i> <?= htmlspecialchars($row['slsu_ref_no'] ?: 'N/A') ?>
                                </td>

                                <td class="proc-title-cell">
                                    <a href="view_procurement.php?id=<?= $row['id'] ?>">
                                        <?= htmlspecialchars($row['title']) ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="proc-mode-tag">
                                        <?= htmlspecialchars($row['procurement_mode'] ?: 'Public Bidding') ?>
                                    </span>
                                </td>

                                <td class="proc-abc-cell">
                                    ₱<?= number_format((float)$row['abc'], 2) ?>
                                </td>

                                <td class="proc-deadline-cell">
                                    <?php if ($row['closing_date']): ?>
                                        <strong><?= date('M j, Y · g:i A', strtotime($row['closing_date'])) ?></strong>
                                        <?php if ($isOpen && $daysLeft !== null): ?>
                                            <?php if ($daysLeft < 0): ?>
                                                <span class="deadline-status deadline-status--closed">Submission Closed</span>
                                            <?php elseif ($daysLeft == 0): ?>
                                                <span class="deadline-status deadline-status--today">● Closes Today</span>
                                            <?php elseif ($daysLeft <= 3): ?>
                                                <span class="deadline-status deadline-status--soon">▲ <?= $daysLeft ?> days left</span>
                                            <?php else: ?>
                                                <span class="deadline-status deadline-status--normal"><?= $daysLeft ?> days remaining</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="deadline-tba">To be announced</span>
                                    <?php endif; ?>
                                </td>

                                <td class="proc-actions-cell">
                                    <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="ap2-pagination">
                    <div class="ap2-pagination-info">
                        Showing <strong><?= min($total_shown, $offset + 1) ?></strong> to <strong><?= min($total_shown, $offset + $per_page) ?></strong> of <strong><?= number_format($total_shown) ?></strong> items
                    </div>
                    <div class="ap2-pagination-links">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page - 1 ?>" class="ap2-page-link">
                                <i class="bi bi-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $p ?>"
                               class="ap2-page-link <?= $p === $page ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page + 1 ?>" class="ap2-page-link">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>
</main>

<!-- ══════════════════════════════════════════════════════════
     MODAL FOR CALENDAR EVENTS
     ══════════════════════════════════════════════════════════ -->
<div id="calEventModal" class="modal-backdrop" onclick="if(event.target===this)closeCalModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-calendar-event"></i> <span id="calModalDate">Scheduled Activities</span></h4>
            <button type="button" class="modal-close" onclick="closeCalModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="calModalBody">
            <!-- Events populated by JS -->
        </div>
    </div>
</div>

<script>
// Raw calendar events passed from PHP
const calEvents = <?= json_encode($cal_events) ?>;

let currentCalDate = new Date();

function renderMiniCalendar() {
    const year  = currentCalDate.getFullYear();
    const month = currentCalDate.getMonth();

    const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    document.getElementById('calMonthLabel').textContent = `${monthNames[month]} ${year}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const lastDay       = new Date(year, month + 1, 0).getDate();

    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    // Day of week headers
    const days = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    days.forEach(d => {
        const dh = document.createElement('div');
        dh.className = 'mini-cal-day-head';
        dh.textContent = d;
        grid.appendChild(dh);
    });

    // Empty cells before day 1
    for (let i = 0; i < firstDayIndex; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'mini-cal-cell empty';
        grid.appendChild(emptyCell);
    }

    const todayStr = new Date().toISOString().split('T')[0];

    // Days 1..lastDay
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
    const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    const monthLabelEl = document.getElementById('eventListMonthLabel');
    const badgeEl = document.getElementById('calEventsCountBadge');

    if (monthLabelEl) monthLabelEl.textContent = `${monthNames[month]} ${year}`;
    listEl.innerHTML = '';

    const monthEvents = calEvents.filter(e => {
        const openD  = e.opening_date ? new Date(e.opening_date) : null;
        const closeD = e.closing_date ? new Date(e.closing_date) : null;
        return (openD && openD.getMonth() === month && openD.getFullYear() === year) ||
               (closeD && closeD.getMonth() === month && closeD.getFullYear() === year);
    });

    if (badgeEl) {
        badgeEl.textContent = `${monthEvents.length} Event${monthEvents.length !== 1 ? 's' : ''}`;
    }

    if (monthEvents.length === 0) {
        listEl.innerHTML = `
            <div class="mini-empty">
                <i class="bi bi-calendar-x mini-empty-icon"></i>
                <p class="mini-empty-text">No scheduled activities listed for ${monthNames[month]} ${year}.</p>
            </div>
        `;
        return;
    }

    monthEvents.forEach(e => {
        const item = document.createElement('div');
        item.className = 'js-event-row';

        const d = e.opening_date || e.closing_date;
        const dateObj = new Date(d);
        const fMonth = dateObj.toLocaleDateString('en-US', { month:'short' }).toUpperCase();
        const fDay = dateObj.getDate();
        const isOpening = Boolean(e.opening_date);
        const typeBadge = isOpening
            ? '<span class="js-event-type-badge js-event-type-badge--opening">BID OPENING</span>'
            : '<span class="js-event-type-badge js-event-type-badge--deadline">SUBMISSION DEADLINE</span>';

        item.innerHTML = `
            <div class="js-event-left">
                <div class="js-event-date-box">
                    <span class="month">${fMonth}</span>
                    <span class="day">${fDay}</span>
                </div>
                <div class="js-event-info">
                    <div class="js-event-title">
                        <a href="view_procurement.php?id=${e.id}">${e.title}</a>
                    </div>
                    <div class="js-event-meta">
                        <span><i class="bi bi-hash"></i> Ref: ${e.slsu_ref_no || 'N/A'}</span>
                        <span><i class="bi bi-clock"></i> ${dateObj.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                    </div>
                </div>
            </div>
            <div class="js-event-right">
                ${typeBadge}
                <a href="view_procurement.php?id=${e.id}" class="proc-action-btn proc-action-btn--sm">
                    <i class="bi bi-eye"></i> View
                </a>
            </div>
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
        card.className = 'js-modal-event-card';

        let typeBadge = '';
        if (e.opening_date && e.opening_date.includes(dateStr)) {
            typeBadge = '<span class="js-event-type-badge js-event-type-badge--opening">BID OPENING</span>';
        } else {
            typeBadge = '<span class="js-event-type-badge js-event-type-badge--deadline">SUBMISSION DEADLINE</span>';
        }

        card.innerHTML = `
            <div class="js-modal-event-top">
                ${typeBadge}
                <span class="js-modal-event-ref">Ref: ${e.slsu_ref_no || 'N/A'}</span>
            </div>
            <div class="js-modal-event-title">${e.title}</div>
            <a href="view_procurement.php?id=${e.id}" class="proc-action-btn proc-action-btn--xs">
                <i class="bi bi-eye"></i> View Details
            </a>
        `;
        body.appendChild(card);
    });

    modal.classList.add('open');
}

function closeCalModal() {
    document.getElementById('calEventModal').classList.remove('open');
}

// Initial Render
renderMiniCalendar();
</script>

</body>
</html>
