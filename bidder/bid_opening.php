<?php
include("utils/protect-page.php");
include(__DIR__ . "/../includes/pagination.php");

// Guard
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['bidder', 'user'])) {
    header("Location: dashboard.php");
    exit();
}

// ── Live session ──────────────────────────────────────────────────────────
$live_session = null;
$ls = $conn->query("
    SELECT bos.id AS session_id, bos.status AS session_status,
           bos.started_at, bos.stream_path,
           p.id AS proc_id, p.title AS proc_title, p.slsu_ref_no
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status IN ('started','eligibility','financial','offered')
    ORDER BY bos.started_at DESC LIMIT 1
");
if ($ls) $live_session = $ls->fetch_assoc();

// ── Scheduled sessions (up to 3) ─────────────────────────────────────────
$scheduled_res = $conn->query("
    SELECT bos.id AS session_id, bos.created_at,
           p.title AS proc_title, p.slsu_ref_no, p.procurement_mode,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status = 'scheduled'
    ORDER BY bos.created_at ASC LIMIT 3
");
$scheduled_sessions = $scheduled_res ? $scheduled_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Filters & Pagination ──────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$mode_filter = trim($_GET['mode'] ?? '');
$sort        = in_array($_GET['sort'] ?? '', ['closing_asc','closing_desc','abc_desc','abc_asc','newest'])
               ? $_GET['sort'] : 'closing_asc';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 12;
$offset      = ($page - 1) * $per_page;

// ── Modes dropdown ───────────────────────────────────────────────────────
$modes_res   = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE status='open' AND procurement_mode IS NOT NULL AND procurement_mode!='' ORDER BY procurement_mode ASC");
$avail_modes = [];
if ($modes_res) while ($mr = $modes_res->fetch_assoc()) $avail_modes[] = $mr['procurement_mode'];

// ── Query (open procurements only) ───────────────────────────────────────
$where_parts = ["p.status = 'open'"];
$params = []; $types = '';

if ($mode_filter !== '' && $mode_filter !== 'all') {
    $where_parts[] = "p.procurement_mode = ?";
    $params[] = $mode_filter; $types .= 's';
}
if ($search !== '') {
    $like = '%'.$search.'%';
    $where_parts[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
$wsql = 'WHERE '.implode(' AND ', $where_parts);

$order_map = [
    'closing_asc'  => 'p.closing_date ASC, p.id DESC',
    'closing_desc' => 'p.closing_date DESC, p.id DESC',
    'abc_desc'     => 'p.abc DESC, p.id DESC',
    'abc_asc'      => 'p.abc ASC, p.id DESC',
    'newest'       => 'p.id DESC',
];
$order_sql = 'ORDER BY '.($order_map[$sort] ?? $order_map['closing_asc']);

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM procurements p $wsql");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, (int)ceil($total_shown / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Rows
$mp = array_merge($params, [$per_page, $offset]);
$mt = $types.'ii';
$main = $conn->prepare("
    SELECT p.id, p.slsu_ref_no, p.title, p.abc, p.procurement_mode,
           p.closing_date, p.opening_date, p.status,
           (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM procurements p
    $wsql $order_sql
    LIMIT ? OFFSET ?
");
$main->bind_param($mt, ...$mp);
$main->execute();
$procs = $main->get_result();

// Stats
$stat_total    = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status='open'")->fetch_row()[0];
$stat_open     = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status='open' AND closing_date IS NOT NULL AND closing_date>=NOW()")->fetch_row()[0];
$stat_closing  = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status='open' AND closing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_row()[0];
$stat_sessions = (int)$conn->query("SELECT COUNT(*) FROM bid_opening_sessions")->fetch_row()[0];

// Procurement IDs that already have a session
$scheduled_proc_ids = [];
$sp = $conn->query("SELECT DISTINCT procurement_id FROM bid_opening_sessions");
if ($sp) while ($r = $sp->fetch_row()) $scheduled_proc_ids[] = (int)$r[0];

// ── AJAX: return only the list fragment ──────────────────────────────────────
if (isset($_GET['ajax'])) {
    include __DIR__ . '/partials/bid-opening-list.php';
    exit();
}
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
<link rel="stylesheet" href="../dashboard.css">
<link rel="stylesheet" href="../css/dashboard-shell.css">
<link rel="stylesheet" href="../css/responsive.css">
<link rel="stylesheet" href="../css/pages/bidder-bid-opening.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Bid Opening'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Bid Opening</h2>
        <p>Monitor live bid openings and upcoming sessions for open procurement projects.</p>
    </div>

    <!-- Stats -->
    <div class="sad-section-label">Overview</div>
    <div class="bo-stat-grid">
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#1f7a3d; --pct:<?= $stat_total>0?round($stat_open/$stat_total*100):0 ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-check-circle"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--active"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Active Opportunities</div>
            </div>
        </div>
        <?php $closingColor = $stat_closing>0?'#e67e22':'#8B958E'; ?>
        <div class="ap2-stat <?= $stat_closing>0?'bsv-stat-warn':'' ?>">
            <div class="ap2-ring" style="--ring-color:<?= $closingColor ?>; --pct:<?= $stat_total>0?round($stat_closing/$stat_total*100):0 ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-alarm"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num <?= $stat_closing>0?'ap2-stat-num--warn':'' ?>"><?= $stat_closing ?></div>
                <div class="ap2-stat-lbl">Closing in 3 Days</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#2F6FED; --pct:100%">
                <div class="ap2-ring-inner"><i class="bi bi-broadcast"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--sessions"><?= $stat_sessions ?></div>
                <div class="ap2-stat-lbl">Total Sessions</div>
            </div>
        </div>
    </div>

    <!-- ── Current Live Session ── -->
    <div class="sched-panel mb-20">
        <div class="sched-panel-head">
            <span class="sched-panel-head-label">
                <i class="bi bi-broadcast clr-red"></i> Current Live Session
            </span>
            <a href="bid-session-list.php?filter=live" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>
        <?php if ($live_session): ?>
        <div class="bo-live-banner bo-live-banner--flush">
            <div class="bo-live-content">
                <div>
                    <div class="mb-8">
                        <span class="bo-live-pill"><span class="bo-live-dot"></span> LIVE</span>
                    </div>
                    <div class="bo-live-title"><?= htmlspecialchars($live_session['proc_title']) ?></div>
                    <div class="bo-live-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($live_session['slsu_ref_no']) ?></span>
                        <span><i class="bi bi-activity"></i><?= ucfirst($live_session['session_status']) ?> phase</span>
                        <?php if ($live_session['started_at']): ?>
                        <span><i class="bi bi-clock"></i>Started <?= date('g:i A', strtotime($live_session['started_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="bo-live-actions">
                <a href="../live.php" target="_blank" class="bo-btn-primary">
                    <i class="bi bi-play-circle-fill"></i> Join
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="bo-live-empty">
            <i class="bi bi-broadcast bo-live-empty-icon"></i>
            <div>
                <div class="bo-live-empty-title">No Active Live Session</div>
                <div class="bo-live-empty-desc">There is no bid opening session currently in progress.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Scheduled Bid Opening ── -->
    <div class="sched-panel mb-28">
        <div class="sched-panel-head">
            <span class="sched-panel-head-label">
                <i class="bi bi-calendar-event clr-amber"></i> Scheduled Bid Opening
            </span>
            <a href="bid-session-list.php?filter=scheduled" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>
        <div class="sched-list">
            <?php if (empty($scheduled_sessions)): ?>
            <div class="sched-empty">
                <i class="bi bi-calendar-x sched-empty-icon"></i>
                No scheduled bid opening sessions at the moment.
            </div>
            <?php else: ?>
            <?php foreach ($scheduled_sessions as $ss): ?>
            <div class="sched-card">
                <div class="sched-icon"><i class="bi bi-calendar-event"></i></div>
                <div class="sched-info">
                    <div class="sched-title"><?= htmlspecialchars(mb_strimwidth($ss['proc_title'],0,55,'…')) ?></div>
                    <div class="sched-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($ss['slsu_ref_no']) ?></span>
                        <?php if ($ss['procurement_mode']): ?>
                        <span><?= htmlspecialchars($ss['procurement_mode']) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-inbox"></i> <?= (int)$ss['bid_count'] ?> bid<?= $ss['bid_count']!=1?'s':'' ?></span>
                        <span class="sched-pill"><i class="bi bi-clock"></i> Scheduled</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Open Procurements Table -->
    <div class="sad-section-label sad-section-label--split">
        <span>Open Procurements</span>
        <a href="bid-session-list.php" class="section-view-more">All Sessions <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="proc-table-panel">
        <div class="filter-bar">
            <form method="GET" action="" id="procFilterForm" class="form-contents">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search title or ref…" value="<?= htmlspecialchars($search) ?>"
                        enterkeyhint="search" autocomplete="off">
                </div>
                <div class="filter-dropdowns">
                    <?php if (!empty($avail_modes)): ?>
                    <select name="mode">
                        <option value="">All Modes</option>
                        <?php foreach ($avail_modes as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter===$m?'selected':'' ?>><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <select id="sortSelect" name="sort">
                        <option value="closing_asc"  <?= $sort==='closing_asc' ?'selected':'' ?>>Closing Soonest</option>
                        <option value="closing_desc" <?= $sort==='closing_desc'?'selected':'' ?>>Closing Latest</option>
                        <option value="abc_desc"     <?= $sort==='abc_desc'    ?'selected':'' ?>>ABC: High → Low</option>
                        <option value="abc_asc"      <?= $sort==='abc_asc'     ?'selected':'' ?>>ABC: Low → High</option>
                        <option value="newest"       <?= $sort==='newest'      ?'selected':'' ?>>Newest</option>
                    </select>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <div id="openingListRoot" class="ajax-list-root">
            <?php include __DIR__ . '/partials/bid-opening-list.php'; ?>
        </div>
    </div>

</div>
</main>

<script src="../js/ajax-list.js"></script>
<script>
    AjaxList.init({
        root:     '#openingListRoot',
        form:     '#procFilterForm',
        endpoint: 'bid_opening.php',
    });
</script>
</body>
</html>
