<?php
include "config/db_connect.php";
include __DIR__ . "/includes/pagination.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$user_dashboard_link = "login.php";
$user_logged_in = false;
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $user_logged_in = true;
    switch ($_SESSION['role']) {
        case "user":       $user_dashboard_link = "user/dashboard.php"; break;
        case "bidder":     $user_dashboard_link = "bidder/dashboard.php"; break;
        case "admin":      $user_dashboard_link = "admin/dashboard.php"; break;
        case "superadmin": $user_dashboard_link = "admin/dashboard.php"; break;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter  = in_array($_GET['filter'] ?? '', ['all','open','closed','awarded','opened']) ? ($_GET['filter']) : 'all';
$sort    = ($_GET['sort'] ?? '') === 'closing' ? 'closing' : 'default';
$filter_mode = trim($_GET['mode'] ?? '');
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset  = ($page - 1) * $per_page;

// ── Live session check ────────────────────────────────────────────────────────
$live_session = null;
if (isset($conn) && $conn instanceof mysqli) {
    $ls = $conn->query("
        SELECT bos.id AS session_id, bos.status AS session_status, bos.stream_path,
               p.title AS proc_title, p.slsu_ref_no, p.abc
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.status IN ('started', 'eligibility','financial')
        ORDER BY bos.started_at DESC
        LIMIT 1
    ");
    if ($ls) $live_session = $ls->fetch_assoc();
}

// ── Procurements query ────────────────────────────────────────────────────────
$procurements = [];
$total_count  = 0;

if (isset($conn) && $conn instanceof mysqli) {
    $where  = ["p.status NOT IN ('draft','cancelled')"];
    $params = []; $types = '';

    if ($filter !== 'all') {
        $where[] = "p.status = ?"; $params[] = $filter; $types .= 's';
    }
    if ($filter_mode !== '') {
        $where[] = "p.procurement_mode = ?"; $params[] = $filter_mode; $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ?)";
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    $wsql = 'WHERE ' . implode(' AND ', $where);

    // Distinct modes for dropdown
    $modes_res = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE procurement_mode IS NOT NULL AND procurement_mode != '' ORDER BY procurement_mode ASC");
    $modes = [];
    if ($modes_res) while ($m = $modes_res->fetch_assoc()) $modes[] = $m['procurement_mode'];

    // Count
    $cnt = $conn->prepare("SELECT COUNT(*) FROM procurements p $wsql");
    if ($params) $cnt->bind_param($types, ...$params);
    $cnt->execute();
    $total_count = (int)$cnt->get_result()->fetch_row()[0];
    $cnt->close();
    $total_pages = max(1, (int)ceil($total_count / $per_page));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per_page;

    // Order
    $order_sql = $sort === 'closing'
        ? "ORDER BY CASE WHEN p.closing_date >= NOW() THEN 0 ELSE 1 END ASC, p.closing_date ASC"
        : "ORDER BY CASE p.status WHEN 'open' THEN 1 WHEN 'closed' THEN 2 WHEN 'awarded' THEN 3 WHEN 'opened' THEN 3 ELSE 4 END ASC, p.opening_date ASC, p.id DESC";

    // Rows
    $lp = array_merge($params, [$per_page, $offset]);
    $lt = $types . 'ii';
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.slsu_ref_no, p.procurement_mode,
               p.abc, p.status, p.posting_date, p.closing_date, p.opening_date,
               (SELECT COUNT(*) FROM lots WHERE lots.procurement_id = p.id) AS lots_count
        FROM procurements p
        $wsql
        $order_sql
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($lt, ...$lp);
    $stmt->execute();
    $procurements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$total_pages = $total_pages ?? 1;

// ── AJAX: return only the list fragment ──────────────────────────────────────
if (isset($_GET['ajax'])) {
    include __DIR__ . '/partials/bid-schedule-list.php';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Procurement | YesParency</title>
<link rel="icon" type="image/png" href="images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- Custom CSS: base -> shared components -> page-specific -->
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/pages/bid_schedule.css">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
</head>
<body class="sched-page">

<?php render_public_navbar('procurement', $user_logged_in, $user_dashboard_link); ?>

<!-- ── Header ── -->
<div class="sched-header">
    <div class="sched-header-inner">
        <a href="index.php" class="sched-back"><i class="bi bi-arrow-left"></i> Back to Home</a>
        <h1>Procurement <span>Directory</span></h1>
        <p>Complete record of all SLSU procurement projects — open for bidding, awarded, and closed.</p>
    </div>
</div>

<?php if ($live_session): ?>
<!-- ── Live Banner ── -->
<div class="live-banner">
    <div class="live-banner-inner">
        <div class="live-banner-left">
            <div class="live-pulse-dot"></div>
            <div>
                <div class="live-banner-label"><i class="bi bi-broadcast"></i> &nbsp;Live Now — Bid Opening in Progress</div>
                <div class="live-banner-title"><?= htmlspecialchars(mb_strimwidth($live_session['proc_title'], 0, 70, '…')) ?></div>
                <div class="live-banner-ref">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($live_session['slsu_ref_no']) ?>
                    &nbsp;·&nbsp; <?= ucfirst($live_session['session_status']) ?> Phase
                    &nbsp;·&nbsp; ₱<?= number_format((float)$live_session['abc'], 2) ?>
                </div>
            </div>
        </div>
        <a href="live.php" class="btn-watch-live">
            <i class="bi bi-play-circle-fill"></i> Watch Live
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ── Filter Bar ── -->
<div class="filter-bar-wrap">
    <form class="filter-bar-inner" method="GET" action="bid_schedule.php" id="filterForm">
        <input type="hidden" name="sort"   id="hiddenSort"   value="<?= htmlspecialchars($sort) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search by title or SLSU ref…" value="<?= htmlspecialchars($search) ?>"
                   enterkeyhint="search" autocomplete="off">
        </div>

        <!-- Status -->
        <div class="filter-status-group filter-chips">
            <?php foreach (['all'=>'All','open'=>'Open','closed'=>'Closed','opened'=>'Opened'] as $v => $l): ?>
            <button type="submit" name="filter" value="<?= $v ?>" class="filter-btn <?= $filter === $v ? 'active' : '' ?>">
                <?= $l ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Mode dropdown -->
        <?php if (!empty($modes)): ?>
        <select name="mode" class="filter-btn filter-select">
            <option value="">All Modes</option>
            <?php foreach ($modes as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $filter_mode === $m ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Sort: Closing Soonest -->
        <button type="button" id="sortToggle" class="filter-btn <?= $sort === 'closing' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i> Closing Soonest
        </button>

        <button type="submit" class="filter-go"><i class="bi bi-search"></i> Search</button>

        <!-- Always visible clear -->
        <a href="bid_schedule.php" class="filter-clear-link" id="filterClear">
            <i class="bi bi-x-circle"></i> Clear
        </a>
    </form>
</div>

<!-- ── Table ── -->
<div class="table-panel-wrap">
    <div class="table-panel">
        <div id="scheduleListRoot" class="ajax-list-root">
            <?php include __DIR__ . '/partials/bid-schedule-list.php'; ?>
        </div>
    </div>
</div>

<!-- ── Footer ── -->
<?php require_once __DIR__ . '/includes/footer.php'; render_public_footer(); ?>

<script src="js/ajax-list.js"></script>
<script>
AjaxList.init({
    root:     '#scheduleListRoot',
    form:     '#filterForm',
    endpoint: 'bid_schedule.php',
});

// "Closing Soonest" is an on/off toggle backed by the hidden sort field
document.getElementById('sortToggle').addEventListener('click', function () {
    const sort = document.getElementById('hiddenSort');
    sort.value = sort.value === 'closing' ? 'default' : 'closing';
    this.classList.toggle('active', sort.value === 'closing');
    document.getElementById('filterForm').requestSubmit();
});

// Clear: reload the bare page without adding a history entry
document.getElementById('filterClear').addEventListener('click', e => {
    e.preventDefault();
    location.replace('bid_schedule.php');
});
</script>
</body>
</html>
