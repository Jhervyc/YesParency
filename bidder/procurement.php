<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");

$bidder_id = (int)$_SESSION['user_id'];
$bidder_doc_status = check_bidder_documents_status($conn, $bidder_id);

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
    SELECT id, title, slsu_ref_no, abc, opening_date, closing_date, status
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
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count,
        (SELECT b.id FROM bids b WHERE b.procurement_id = p.id AND b.bidder_id = ? LIMIT 1) AS my_bid_id
    FROM procurements p
    WHERE p.status = 'open' AND p.closing_date IS NOT NULL AND p.closing_date >= NOW()
    ORDER BY p.closing_date ASC
    LIMIT 5
";
$rank_stmt = $conn->prepare($ranking_sql);
$rank_stmt->bind_param("i", $bidder_id);
$rank_stmt->execute();
$ranked_procs = $rank_stmt->get_result();
$rank_stmt->close();

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

// Count total
$count_sql = "SELECT COUNT(*) FROM procurements p $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_shown = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = max(1, ceil($total_shown / $per_page));

// Fetch Records
$main_sql = "
    SELECT 
        p.id,
        p.slsu_ref_no,
        p.title,
        p.description,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count,
        (SELECT b.id FROM bids b WHERE b.procurement_id = p.id AND b.bidder_id = ? LIMIT 1) AS my_bid_id,
        (SELECT b.status FROM bids b WHERE b.procurement_id = p.id AND b.bidder_id = ? LIMIT 1) AS my_bid_status
    FROM procurements p
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
";

$main_params = array_merge([$bidder_id, $bidder_id], $params, [$per_page, $offset]);
$main_types  = 'ii' . $types . 'ii';
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
    <title>Procurements & Bidding Opportunities | YesParency</title>
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
    <link rel="stylesheet" href="../css/pages/bidder-procurement.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Procurements & Bidding Opportunities';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header">
        <h2>Procurements &amp; Bidding Opportunities</h2>
        <p>Explore municipal procurement projects, evaluate lot specifications, and submit competitive electronic proposals.</p>
    </div>

    <!-- ── Document Compliance Warning Banner if Invalid ── -->
    <?php if (!$bidder_doc_status['is_valid']): ?>
        <div class="locked-banner">
            <div class="locked-banner-left">
                <div class="locked-icon">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                </div>
                <div>
                    <div class="locked-title">Bidding Proposal Submissions Locked</div>
                    <div class="locked-desc">
                        <?= htmlspecialchars($bidder_doc_status['summary_error']) ?> Electronic proposal submissions are locked until your documents are updated.
                    </div>
                </div>
            </div>
            <a href="settings.php?tab=documents" class="locked-cta">
                <i class="bi bi-file-earmark-arrow-up"></i> Update in Settings
            </a>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         TOP SECTION (100% WIDTH): Summary Stat Cards
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Summary Overview</div>
    <div class="proc-stats-grid">
        
        <!-- 1. Total Opportunities -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring proc-stat-ring--total">
                <div class="proc-stat-inner">
                    <i class="bi bi-folder2-open"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num"><?= $stat_all ?></div>
                <div class="proc-stat-lbl">Total Listed</div>
            </div>
        </div>

        <!-- 2. Active / Open for Bidding -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring proc-stat-ring--open" style="--pct:<?= $stat_all > 0 ? round($stat_open / $stat_all * 100) : 0 ?>%">
                <div class="proc-stat-inner">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num proc-stat-num--open"><?= $stat_open ?></div>
                <div class="proc-stat-lbl">Open for Bidding</div>
            </div>
        </div>

        <!-- 3. Closing Soon (<= 3 days) -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring proc-stat-ring--urgent" style="--pct:<?= $stat_all > 0 ? round($stat_urgent / $stat_all * 100) : 0 ?>%">
                <div class="proc-stat-inner">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num proc-stat-num--urgent"><?= $stat_urgent ?></div>
                <div class="proc-stat-lbl">Closing Soon</div>
            </div>
        </div>

        <!-- 4. Closed / Awarded -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring proc-stat-ring--close" style="--pct:<?= $stat_all > 0 ? round($stat_close / $stat_all * 100) : 0 ?>%">
                <div class="proc-stat-inner">
                    <i class="bi bi-archive"></i>
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
                        <i class="bi bi-clock-history clr-green"></i>
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
    <div class="ranked-card-panel mb-24">
        <div class="ranked-card-header">
            <div class="ranked-card-title">
                <i class="bi bi-alarm clr-orange"></i>
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
                            <?php if (!empty($rp['my_bid_id'])): ?>
                                <span class="bidded-pill">
                                    <i class="bi bi-check-circle-fill"></i> Bidded
                                </span>
                            <?php endif; ?>
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
            <div class="ap2-controls">

                <!-- 1. Search Input -->
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="procSearchInput"
                        placeholder="Search by title, SLSU ref..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>

                <!-- 2. Status Filter Buttons -->
                <div class="ap2-status-group">
                    <button type="submit" name="status" value="open"
                        class="ap2-filter-btn <?= $status_filter === 'open' ? 'active' : '' ?>">Open</button>
                    <button type="submit" name="status" value="all"
                        class="ap2-filter-btn <?= $status_filter === 'all' ? 'active' : '' ?>">All</button>
                    <button type="submit" name="status" value="closed"
                        class="ap2-filter-btn <?= $status_filter === 'closed' ? 'active' : '' ?>">Closed</button>
                </div>

                <!-- Keep mode & sort as hidden fields that update via selects -->
                <input type="hidden" name="mode" id="hiddenMode" value="<?= htmlspecialchars($mode_filter) ?>">
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">

                <!-- 3. Procurement Mode Dropdown -->
                <div class="proc-module-select">
                    <select id="modeSelect" onchange="document.getElementById('hiddenMode').value=this.value;">
                        <option value="all" <?= $mode_filter === 'all' ? 'selected' : '' ?>>All Modes</option>
                        <?php foreach ($avail_modes as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter === $m ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Sort Dropdown -->
                <div class="proc-module-select">
                    <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value;">
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
                            $hasBid = !empty($row['my_bid_id']);
                            $isUrgent = false;
                            $diff_text = 'TBD';

                            if ($row['closing_date']) {
                                $dl_time   = strtotime($row['closing_date']);
                                $diff_days = ceil(($dl_time - time()) / 86400);
                                if ($diff_days <= 3 && $diff_days >= 0) $isUrgent = true;

                                if ($diff_days > 3) $diff_text = $diff_days . ' days left';
                                elseif ($diff_days > 0) $diff_text = $diff_days . ' day(s) left';
                                elseif ($diff_days === 0) $diff_text = 'Closing today';
                                else $diff_text = 'Closed';
                            }
                        ?>
                        <tr>
                            <!-- SLSU Ref -->
                            <td class="proc-ref-cell">
                                <span><?= htmlspecialchars($row['slsu_ref_no'] ?? 'N/A') ?></span>
                                <?php if ($hasBid): ?>
                                    <div class="bidded-pill-wrap">
                                        <span class="bidded-pill">
                                            <i class="bi bi-check-circle-fill"></i> Bidded
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Title & Specs -->
                            <td class="proc-title-cell">
                                <a href="view_procurement.php?id=<?= $row['id'] ?>" title="<?= htmlspecialchars($row['title']) ?>">
                                    <?= htmlspecialchars($row['title']) ?>
                                </a>
                                <div class="proc-title-specs">
                                    <span><i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot(s)</span>
                                    <span>·</span>
                                    <span><i class="bi bi-file-earmark-text"></i> <?= (int)$row['doc_count'] ?> doc(s)</span>
                                    <?php if (!empty($row['opening_date'])): ?>
                                        <span>·</span>
                                        <span><i class="bi bi-door-open"></i> Opens: <?= date('M j, Y', strtotime($row['opening_date'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Mode -->
                            <td>
                                <span class="proc-mode-tag">
                                    <?= htmlspecialchars($row['procurement_mode'] ?? 'Public Bidding') ?>
                                </span>
                            </td>

                            <!-- ABC -->
                            <td class="proc-abc-cell">
                                ₱<?= number_format((float)$row['abc'], 2) ?>
                            </td>

                            <!-- Deadline -->
                            <td class="proc-deadline-cell">
                                <strong><?= $row['closing_date'] ? date('M j, Y', strtotime($row['closing_date'])) : 'TBD' ?></strong>
                                <?php if ($row['closing_date']): ?>
                                    <span class="rank-urgency-pill <?= $isUrgent ? 'urgent' : 'normal' ?>">
                                        <i class="bi bi-clock"></i> <?= $diff_text ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Actions -->
                            <td class="proc-actions-cell">
                                <div class="proc-actions-row">
                                    <?php if (!$hasBid && $row['status'] === 'open'): ?>
                                        <?php if ($bidder_doc_status['is_valid']): ?>
                                            <a href="submit_bid.php?procurement_id=<?= $row['id'] ?>" class="proc-action-btn proc-action-btn--gold">
                                                <i class="bi bi-send-fill"></i> Bid
                                            </a>
                                        <?php else: ?>
                                            <a href="settings.php?tab=documents" class="proc-action-btn proc-action-btn--locked" title="Bidding locked: Document(s) expired or missing. Click to update in Settings.">
                                                <i class="bi bi-lock-fill"></i> Bid
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn secondary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ── -->
            <?php if ($total_pages > 1): ?>
                <div class="ap2-pagination">
                    <div class="ap2-pagination-info">
                        Showing <strong><?= min($total_shown, $offset + 1) ?></strong> to <strong><?= min($total_shown, $offset + $per_page) ?></strong> of <strong><?= number_format($total_shown) ?></strong> procurements
                    </div>
                    <div class="ap2-pagination-links">
                        <?php if ($page > 1): ?>
                            <a href="?search=<?= urlencode($search) ?>&mode=<?= urlencode($mode_filter) ?>&status=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page - 1 ?>" class="vp-back-link vp-back-link--page">
                                <i class="bi bi-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="?search=<?= urlencode($search) ?>&mode=<?= urlencode($mode_filter) ?>&status=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $p ?>"
                               class="vp-back-link vp-back-link--page <?= $p === $page ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?search=<?= urlencode($search) ?>&mode=<?= urlencode($mode_filter) ?>&status=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page + 1 ?>" class="vp-back-link vp-back-link--page">
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

<!-- Event Viewer Modal -->
<div class="modal-backdrop" id="calEventModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-calendar-event clr-green"></i> <span id="calModalDate">Activities</span></h4>
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
            const badgeClass = isOpening ? 'js-event-type-badge--opening' : 'js-event-type-badge--deadline';
            const badgeText  = isOpening ? 'BID OPENING' : 'SUBMISSION DEADLINE';
            const typeBadge  = `<span class="js-event-type-badge ${badgeClass}">${badgeText}</span>`;

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
                <div class="js-modal-event-foot">
                    <span class="js-modal-event-abc">
                        ${e.abc ? '₱' + Number(e.abc).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) : ''}
                    </span>
                    <a href="view_procurement.php?id=${e.id}" class="proc-action-btn proc-action-btn--xs">
                        <i class="bi bi-eye"></i> View Details
                    </a>
                </div>
            `;
            body.appendChild(card);
        });

        modal.classList.add('open');
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
        renderMiniCalendar();
    });
</script>

</body>
</html>
