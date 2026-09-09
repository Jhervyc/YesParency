<?php
include "config/db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$user_dashboard_link = "login.php";
$user_logged_in = false;
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $user_logged_in = true;
    switch ($_SESSION['role']) {
        case "user":       $user_dashboard_link = "user/dashboard.php"; break;
        case "bidder":     $user_dashboard_link = "bidder/dashboard.php"; break;
        case "admin":      $user_dashboard_link = "admin/dashboard.php"; break;
        case "superadmin": $user_dashboard_link = "superadmin/dashboard.php"; break;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter  = in_array($_GET['filter'] ?? '', ['all','open','closed','awarded']) ? ($_GET['filter']) : 'all';
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
               p.title AS proc_title, p.philgeps_ref_no, p.abc
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.status IN ('started', 'eligibility','financial','awarding')
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
        $where[] = "(p.title LIKE ? OR p.philgeps_ref_no LIKE ?)";
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
    $total_pages = max(1, ceil($total_count / $per_page));

    // Order
    $order_sql = $sort === 'closing'
        ? "ORDER BY CASE WHEN p.closing_date >= NOW() THEN 0 ELSE 1 END ASC, p.closing_date ASC"
        : "ORDER BY CASE p.status WHEN 'open' THEN 1 WHEN 'closed' THEN 2 WHEN 'awarded' THEN 3 ELSE 4 END ASC, p.opening_date ASC, p.id DESC";

    // Rows
    $lp = array_merge($params, [$per_page, $offset]);
    $lt = $types . 'ii';
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.philgeps_ref_no, p.procurement_mode,
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
<link rel="stylesheet" href="style.css">
<style>
* { box-sizing:border-box; }
html { scroll-behavior:smooth; scroll-padding-top:74px; }
body.sched-page {
    background:#f4f8f5; font-family:'Poppins',sans-serif;
    color:#222; overflow-x:hidden; min-height:100vh;
}

/* ── Navbar ── */
/* (see includes/navbar.php) */

/* ── Page header bar ── */
.sched-header {
    background:linear-gradient(135deg,#06251b 0%,#0c3d2c 100%);
    padding:92px 24px 30px; position:relative; overflow:hidden;
}
.sched-header::before {
    content:''; position:absolute; inset:0;
    background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);
    background-size:40px 40px; pointer-events:none;
}
.sched-header-inner { max-width:1200px; margin:0 auto; position:relative; z-index:2; }
.sched-header-inner h1 { font-size:28px; font-weight:800; color:#fff; font-family:'Space Grotesk',sans-serif; margin:0 0 6px; }
.sched-header-inner h1 span { color:#ffc107; }
.sched-header-inner p { font-size:13px; color:#9cb3a6; margin:0; }
.sched-back { display:inline-flex; align-items:center; gap:6px; color:#9cb3a6; font-size:12px; font-weight:600; text-decoration:none; margin-bottom:16px; transition:color .2s; }
.sched-back:hover { color:#ffc107; }

/* ── Live Banner ── */
.live-banner {
    max-width:1200px; margin:0 auto; padding:0 24px;
    position:relative; top:-18px;
}
.live-banner-inner {
    background:linear-gradient(135deg,#991b1b 0%,#dc2626 100%);
    border-radius:16px; padding:18px 24px;
    display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
    box-shadow:0 8px 24px rgba(220,38,38,.3);
}
.live-banner-left { display:flex; align-items:center; gap:14px; }
.live-pulse-dot {
    width:12px; height:12px; border-radius:50%;
    background:#fff; box-shadow:0 0 0 0 rgba(255,255,255,.6);
    animation:livePulse 1.4s infinite;
    flex-shrink:0;
}
@keyframes livePulse {
    0%   { box-shadow:0 0 0 0 rgba(255,255,255,.6); }
    70%  { box-shadow:0 0 0 10px rgba(255,255,255,0); }
    100% { box-shadow:0 0 0 0 rgba(255,255,255,0); }
}
.live-banner-label { font-size:11px; font-weight:800; color:rgba(255,255,255,.8); text-transform:uppercase; letter-spacing:.6px; margin-bottom:2px; }
.live-banner-title { font-size:14px; font-weight:800; color:#fff; }
.live-banner-ref   { font-size:11px; color:rgba(255,255,255,.7); margin-top:2px; }
.btn-join-live {
    display:inline-flex; align-items:center; gap:8px;
    background:#fff; color:#dc2626; font-size:13px; font-weight:800;
    padding:10px 22px; border-radius:10px; text-decoration:none;
    transition:all .15s; white-space:nowrap; flex-shrink:0;
}
.btn-join-live:hover { background:#fee2e2; transform:translateY(-1px); }

/* ── Filter bar ── */
.filter-bar-wrap {
    background:#fff; border-bottom:1px solid #e2ece6;
    padding:14px 24px; position:sticky; top:64px; z-index:90;
    box-shadow:0 2px 10px rgba(6,37,27,.05);
}
.filter-bar-inner { max-width:1200px; margin:0 auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.filter-search { position:relative; flex:1; min-width:220px; }
.filter-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#88968d; font-size:13px; pointer-events:none; }
.filter-search input {
    width:100%; padding:9px 14px 9px 36px; border:1.5px solid #d4e0d8; border-radius:9px;
    font-size:12.5px; font-family:'Poppins',sans-serif; color:#1a1a1a; outline:none; background:#fff;
    transition:border-color .15s, box-shadow .15s;
}
.filter-search input:focus { border-color:#1f7a3d; box-shadow:0 0 0 3px rgba(31,122,61,.08); }
.filter-btn {
    background:#eef2f0; border:1.5px solid #e0e8e4; color:#06251b;
    font-size:12px; font-weight:700; padding:8px 16px; border-radius:9px;
    cursor:pointer; transition:all .15s; font-family:'Poppins',sans-serif; white-space:nowrap;
}
.filter-btn:hover, .filter-btn.active { background:#06251b; color:#ffc107; border-color:#06251b; }
.filter-go {
    display:inline-flex; align-items:center; gap:6px;
    background:#ffc107; color:#16241d; font-family:'Poppins',sans-serif;
    font-weight:700; font-size:12.5px; border:none; padding:8px 18px;
    border-radius:9px; cursor:pointer; transition:all .15s;
}
.filter-go:hover { background:#e6ac00; }
.filter-count { font-size:12px; font-weight:700; color:#55665a; white-space:nowrap; margin-left:auto; }

/* ── Table panel ── */
.table-panel-wrap { max-width:1200px; margin:0 auto; padding:0 24px 80px; }
.table-panel {
    background:#fff; border:1px solid #eaeeec; border-radius:18px;
    box-shadow:0 1px 2px rgba(16,36,26,.03),0 10px 24px -14px rgba(16,36,26,.08);
    overflow:hidden; margin-top:24px;
}
.proc-table { width:100%; border-collapse:collapse; font-size:12px; text-align:left; }
.proc-table thead th {
    background:#fafcfb; padding:13px 16px; font-size:11px; font-weight:700; color:#55665a;
    text-transform:uppercase; letter-spacing:.04em; border-bottom:1.5px solid #edf1ef; white-space:nowrap;
}
.proc-table tbody tr { border-bottom:1px solid #f0f4f2; transition:background .12s; }
.proc-table tbody tr:last-child { border-bottom:none; }
.proc-table tbody tr:hover { background:#fbfdfc; }
.proc-table td { padding:14px 16px; vertical-align:middle; }

.col-ref   { font-weight:700; color:#06251b; font-size:11.5px; white-space:nowrap; }
.col-title { font-weight:700; color:#1a2a20; font-size:13px; line-height:1.35; max-width:300px; }
.col-title a { color:inherit; text-decoration:none; }
.col-title a:hover { color:#1f7a3d; text-decoration:underline; }
.col-mode { background:#f0f4f2; color:#384d40; padding:3px 8px; border-radius:6px; font-size:10.5px; font-weight:600; white-space:nowrap; }
.col-abc  { font-family:'Space Grotesk',sans-serif; font-size:13px; font-weight:800; color:#06251b; white-space:nowrap; }
.col-date { font-size:11.5px; color:#63736a; white-space:nowrap; line-height:1.4; }
.col-date strong { color:#1a1a1a; display:block; }

/* status pills */
.spill { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
.spill-open    { background:#eef7f1; color:#1f7a3d; border:1px solid #c2e8ce; }
.spill-closed  { background:#e7eefe; color:#2F6FED; border:1px solid #c5d4fb; }
.spill-awarded { background:#fef3c7; color:#d97706; border:1px solid #fde68a; }
.spill-other   { background:#f0f4f2; color:#55665a; border:1px solid #d6e2db; }

/* action btn */
.act-btn {
    display:inline-flex; align-items:center; gap:5px; padding:6px 13px; border-radius:8px;
    font-size:11.5px; font-weight:700; text-decoration:none; transition:all .15s; white-space:nowrap;
}
.act-btn-view  { background:#f0f4f2; color:#06251b; }
.act-btn-view:hover { background:#06251b; color:#ffc107; }

/* empty */
.tbl-empty { padding:52px 20px; text-align:center; color:#88968d; }
.tbl-empty i { font-size:36px; display:block; margin-bottom:10px; color:#c8d8ce; }
.tbl-empty p { font-size:13px; font-weight:700; margin:0; }

/* pagination */
.tbl-foot {
    padding:14px 20px; display:flex; align-items:center; justify-content:space-between;
    gap:12px; flex-wrap:wrap; border-top:1px solid #f0f4f2;
    font-size:12px; color:#88968d;
}
.pagination { display:flex; gap:5px; }
.plink {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:8px; font-size:12px; font-weight:700;
    text-decoration:none; color:#06251b; background:#f0f4f2; transition:all .15s;
}
.plink:hover, .plink.active { background:#06251b; color:#ffc107; }
.plink.disabled { opacity:.4; pointer-events:none; }

/* footer */
.sched-footer { background:#020c09; color:#6b8077; padding:28px 24px; text-align:center; font-size:12px; border-top:1px solid rgba(255,255,255,.06); }
.sched-footer a { color:#9cb3a6; text-decoration:none; }
.sched-footer a:hover { color:#ffc107; }
</style>
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar_css(); ?>
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
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($live_session['philgeps_ref_no']) ?>
                    &nbsp;·&nbsp; <?= ucfirst($live_session['session_status']) ?> Phase
                    &nbsp;·&nbsp; ₱<?= number_format((float)$live_session['abc'], 2) ?>
                </div>
            </div>
        </div>
        <a href="live.php" class="btn-join-live">
            <i class="bi bi-play-circle-fill"></i> Watch Live
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ── Filter Bar ── -->
<div class="filter-bar-wrap">
    <form class="filter-bar-inner" method="GET" action="bid_schedule.php" id="filterForm">
        <input type="hidden" name="filter" id="hiddenFilter" value="<?= htmlspecialchars($filter) ?>">
        <input type="hidden" name="sort"   id="hiddenSort"   value="<?= htmlspecialchars($sort) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search by title or PhilGEPS ref…" value="<?= htmlspecialchars($search) ?>">
        </div>

        <!-- Status -->
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <?php foreach (['all'=>'All','open'=>'Open','closed'=>'Closed','awarded'=>'Awarded'] as $v => $l): ?>
            <button type="button" class="filter-btn <?= $filter === $v ? 'active' : '' ?>"
                onclick="document.getElementById('hiddenFilter').value='<?= $v ?>'; document.getElementById('filterForm').submit();">
                <?= $l ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Mode dropdown -->
        <?php if (!empty($modes)): ?>
        <select name="mode" class="filter-btn" style="padding:8px 12px; cursor:pointer;"
                onchange="document.getElementById('filterForm').submit()">
            <option value="">All Modes</option>
            <?php foreach ($modes as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $filter_mode === $m ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Sort: Closing Soonest -->
        <button type="button" class="filter-btn <?= $sort === 'closing' ? 'active' : '' ?>"
            onclick="document.getElementById('hiddenSort').value='<?= $sort === 'closing' ? 'default' : 'closing' ?>'; document.getElementById('filterForm').submit();">
            <i class="bi bi-clock-history"></i> Closing Soonest
        </button>

        <button type="submit" class="filter-go"><i class="bi bi-search"></i> Search</button>

        <!-- Always visible clear -->
        <a href="bid_schedule.php" style="font-size:12px; font-weight:700; color:#dc2626; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
            <i class="bi bi-x-circle"></i> Clear
        </a>
    </form>
</div>

<!-- ── Table ── -->
<div class="table-panel-wrap">
    <div class="table-panel">
        <?php if (empty($procurements)): ?>
        <div class="tbl-empty">
            <i class="bi bi-calendar-x"></i>
            <p>No procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th style="width:130px;">PhilGEPS Ref</th>
                        <th>Project Title</th>
                        <th>Mode</th>
                        <th>ABC</th>
                        <th>Opening Date</th>
                        <th>Submission Cutoff</th>
                        <th>Status</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($procurements as $p):
                    $ps = strtolower($p['status'] ?? 'open');
                    $pillClass = match($ps) {
                        'open'    => 'spill-open',
                        'closed'  => 'spill-closed',
                        'awarded' => 'spill-awarded',
                        default   => 'spill-other',
                    };
                    $pillIcon = match($ps) {
                        'open'    => 'bi-door-open',
                        'closed'  => 'bi-lock',
                        'awarded' => 'bi-trophy',
                        default   => 'bi-circle',
                    };
                ?>
                <tr>
                    <td class="col-ref"><i class="bi bi-hash" style="color:#88968d; font-size:10px;"></i> <?= htmlspecialchars($p['philgeps_ref_no'] ?: '—') ?></td>
                    <td class="col-title">
                        <a href="bid_view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars(mb_strimwidth($p['title'], 0, 65, '…')) ?></a>
                        <?php if ((int)$p['lots_count'] > 0): ?>
                        <div style="font-size:10.5px; color:#88968d; margin-top:2px;">
                            <i class="bi bi-boxes"></i> <?= (int)$p['lots_count'] ?> lot<?= $p['lots_count'] > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="col-mode"><?= htmlspecialchars($p['procurement_mode'] ?: '—') ?></span></td>
                    <td class="col-abc">₱<?= number_format((float)$p['abc'], 2) ?></td>
                    <td class="col-date">
                        <?php if ($p['opening_date']): ?>
                            <strong><?= date('M j, Y', strtotime($p['opening_date'])) ?></strong>
                            <?= date('g:i A', strtotime($p['opening_date'])) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="col-date">
                        <?php if ($p['closing_date']): ?>
                            <strong><?= date('M j, Y', strtotime($p['closing_date'])) ?></strong>
                            <?= date('g:i A', strtotime($p['closing_date'])) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <span class="spill <?= $pillClass ?>">
                            <i class="bi <?= $pillIcon ?>" style="font-size:9px;"></i>
                            <?= ucfirst($ps) ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <a href="bid_view.php?id=<?= (int)$p['id'] ?>" class="act-btn act-btn-view">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="tbl-foot">
            <div>
                Showing <?= min($total_count, $offset+1) ?>–<?= min($total_count, $offset+$per_page) ?>
                of <?= number_format($total_count) ?> project<?= $total_count != 1 ? 's' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search, 'filter'=>$filter!=='all'?$filter:null, 'mode'=>$filter_mode!==''?$filter_mode:null, 'sort'=>$sort!=='default'?$sort:null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="plink <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="?page=<?= $pg ?><?= $qstr ?>" class="plink <?= $pg===$page?'active':'' ?>"><?= $pg ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="plink <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Footer ── -->
<footer class="sched-footer">
    <p>&copy; <?= date('Y') ?> YesParency — SLSU Procurement Transparency System —
       <a href="index.php">Home</a> &bull; <a href="login.php">Login</a>
    </p>
</footer>

<script>
document.querySelector('.filter-search input')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('filterForm').submit();
});
</script>
</body>
</html>
