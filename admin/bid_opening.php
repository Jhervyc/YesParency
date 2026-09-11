<?php
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';

// ── Resolve admin type & manage permission ─────────────────────────────────
if (!isset($_SESSION['admin_type']) && isset($conn)) {
    $at = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $at->bind_param("i", $_SESSION['user_id']);
    $at->execute();
    $at_row = $at->get_result()->fetch_assoc();
    $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
    $at->close();
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$user_role  = $_SESSION['role'] ?? 'admin';
$can_manage = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

// ── Open Now — transition scheduled session to started ────────────────────
if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_now'])) {
    $session_id = (int)($_POST['session_id'] ?? 0);
    if ($session_id > 0) {
        $snap = $conn->prepare("SELECT bos.status, bos.procurement_id, p.title AS proc_title FROM bid_opening_sessions bos JOIN procurements p ON bos.procurement_id = p.id WHERE bos.id = ?");
        $snap->bind_param("i", $session_id);
        $snap->execute();
        $old_ses = $snap->get_result()->fetch_assoc();
        $snap->close();

        $upd = $conn->prepare("
            UPDATE bid_opening_sessions
            SET status = 'started', started_at = NOW()
            WHERE id = ? AND status = 'scheduled'
        ");
        $upd->bind_param("i", $session_id);
        if ($upd->execute() && $upd->affected_rows > 0) {
            $ptitle = $old_ses['proc_title'] ?? "procurement #{$old_ses['procurement_id']}";
            audit_log(
                $conn,
                'BID_SESSION_STARTED',
                'bid_opening',
                $session_id,
                "Started bid opening session #{$session_id} for {$ptitle}",
                ['status' => 'scheduled'],
                ['status' => 'started', 'started_at' => date('Y-m-d H:i:s')]
            );
        }
        $upd->close();
    }
    // PRG — prevent resubmit on refresh
    header("Location: bid_opening.php");
    exit();
}

// ── Filters & Pagination ───────────────────────────────────────────────────
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$mode_filter = isset($_GET['mode'])   ? trim($_GET['mode'])   : 'all';
$sort        = isset($_GET['sort']) && in_array($_GET['sort'], ['closing_asc','closing_desc','abc_desc','abc_asc','newest'])
               ? $_GET['sort'] : 'closing_asc';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 12;
$offset      = ($page - 1) * $per_page;

// ── Current active bid opening session ────────────────────────────────────
$live_session = null;

$ls_res = $conn->query("
    SELECT bos.id AS session_id, bos.status AS session_status,
           bos.started_at, bos.stream_path,
           p.id AS proc_id, p.title AS proc_title, p.slsu_ref_no
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status IN ('started','eligibility','financial','awarding')
    ORDER BY bos.started_at DESC
    LIMIT 1
");
if ($ls_res) $live_session = $ls_res->fetch_assoc();

// ── Scheduled sessions (up to 3) ──────────────────────────────────────────
$scheduled_res = $conn->query("
    SELECT bos.id AS session_id, bos.created_at, bos.stream_path,
           p.title AS proc_title, p.slsu_ref_no, p.procurement_mode,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status = 'scheduled'
    ORDER BY bos.created_at ASC
    LIMIT 3
");
$scheduled_sessions = $scheduled_res ? $scheduled_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Available procurement modes for dropdown ───────────────────────────────
$modes_res   = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE status = 'open' AND procurement_mode IS NOT NULL AND procurement_mode != '' ORDER BY procurement_mode ASC");
$avail_modes = [];
if ($modes_res) while ($mr = $modes_res->fetch_assoc()) $avail_modes[] = $mr['procurement_mode'];

// ── Set of procurement IDs that already have a session (any status) ────────
$scheduled_proc_ids = [];
$sp_res = $conn->query("SELECT DISTINCT procurement_id FROM bid_opening_sessions");
if ($sp_res) while ($sp = $sp_res->fetch_row()) $scheduled_proc_ids[] = (int)$sp[0];

// ── Query for procurement table (open only) ────────────────────────────────
$where_parts = ["p.status = 'open'"];
$params      = [];
$types       = '';

if ($mode_filter !== 'all' && $mode_filter !== '') {
    $where_parts[] = "p.procurement_mode = ?";
    $params[] = $mode_filter; $types .= 's';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$order_sql = "ORDER BY p.closing_date ASC, p.id DESC";
if ($sort === 'closing_desc') $order_sql = "ORDER BY p.closing_date DESC, p.id DESC";
elseif ($sort === 'abc_desc') $order_sql = "ORDER BY p.abc DESC, p.id DESC";
elseif ($sort === 'abc_asc')  $order_sql = "ORDER BY p.abc ASC, p.id DESC";
elseif ($sort === 'newest')   $order_sql = "ORDER BY p.id DESC";

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM procurements p $where_sql");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, ceil($total_shown / $per_page));

// Main list
$main = $conn->prepare("
    SELECT p.id, p.slsu_ref_no, p.title, p.abc, p.procurement_mode,
           p.closing_date, p.opening_date, p.status,
           (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
    FROM procurements p
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
");
$mp = array_merge($params, [$per_page, $offset]);
$mt = $types . 'ii';
$main->bind_param($mt, ...$mp);
$main->execute();
$procs = $main->get_result();

// ── Summary stats ──────────────────────────────────────────────────────────
$stat_total   = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0];
$stat_open    = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date >= NOW()")->fetch_row()[0];
$stat_closed  = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_row()[0];
$stat_sessions= (int)$conn->query("SELECT COUNT(*) FROM bid_opening_sessions")->fetch_row()[0];
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
    <link rel="stylesheet" href="../css/pages/admin-bid-opening.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Bid Opening'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Page Header ─────────────────────────────────────────────────────── -->
    <div class="page-header">
        <h2>Bid Document Opening</h2>
        <p>Manage and conduct live bid openings, view session history, and schedule upcoming openings.</p>
    </div>

    <!-- ── Summary Stats ──────────────────────────────────────────────────── -->
    <div class="sad-section-label">Overview</div>
    <div class="bo-stat-grid">
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%;">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open clr-dark"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#1f7a3d; --pct:<?= $stat_total>0 ? round($stat_open/$stat_total*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-check-circle clr-forest"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-forest"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Active Opportunities</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#e67e22; --pct:<?= $stat_total>0 ? round($stat_closed/$stat_total*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split clr-amber"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-amber"><?= $stat_closed ?></div>
                <div class="ap2-stat-lbl">Closing in 3 Days</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#e67e22; --pct:<?= $stat_total>0 ? min(100,round($stat_sessions/$stat_total*100)) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-envelope-open clr-amber"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-amber"><?= $stat_sessions ?></div>
                <div class="ap2-stat-lbl">Opening Sessions</div>
            </div>
        </div>
    </div>

    <!-- ── Current Live Session ───────────────────────────────────────────── -->
    <div class="sched-panel sched-panel--live">
        <div class="sched-panel-head">
            <span class="sched-panel-title">
                <i class="bi bi-broadcast clr-red"></i> Current Live Session
            </span>
            <a href="bid-session-list.php?filter=live" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>

        <?php if ($live_session): ?>
        <div class="bo-live-banner">
            <div class="bo-live-body">
                <div>
                    <div class="bo-live-head-row">
                        <span class="bo-live-pill"><span class="bo-live-dot"></span> LIVE</span>
                    </div>
                    <div class="bo-live-title"><?= htmlspecialchars($live_session['proc_title']) ?></div>
                    <div class="bo-live-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($live_session['slsu_ref_no']) ?></span>
                        <span><i class="bi bi-activity"></i><?= ucfirst(htmlspecialchars($live_session['session_status'])) ?> phase</span>
                        <?php if ($live_session['started_at']): ?>
                        <span><i class="bi bi-clock"></i>
                            Started <?= date('g:i A', strtotime($live_session['started_at'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="bo-live-actions">
                <?php if ($can_manage): ?>
                <a href="bid_session.php?session=<?= $live_session['session_id'] ?>" class="bo-btn-primary">
                    <i class="bi bi-envelope-open-fill"></i> Manage Session
                </a>
                <?php else: ?>
                <a href="bid_session.php?session=<?= $live_session['session_id'] ?>" class="bo-btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Join
                </a>
                <?php endif; ?>
                <a href="../live.php" target="_blank" class="bo-btn-outline">
                    <i class="bi bi-broadcast"></i> Live Page
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="bo-no-live-row">
            <i class="bi bi-broadcast bo-no-live-icon"></i>
            <div>
                <div class="bo-no-live-title">No Active Live Session</div>
                <div class="bo-no-live-desc">
                    Schedule a bid opening session from
                    <a href="bid_opening.php">Bid Opening</a>
                    using the Schedule button next to any open procurement.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>


    <!-- ── Scheduled Bid Opening Sessions ────────────────────────────────── -->
    <div class="sched-panel sched-panel--scheduled">
        <div class="sched-panel-head">
            <span class="sched-panel-title">
                <i class="bi bi-calendar-event clr-gold-dark"></i> Scheduled Bid Opening
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
                    <div class="sched-title"><?= htmlspecialchars($ss['proc_title']) ?></div>
                    <div class="sched-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($ss['slsu_ref_no']) ?></span>
                        <?php if ($ss['procurement_mode']): ?>
                        <span><?= htmlspecialchars($ss['procurement_mode']) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-inbox"></i> <?= (int)$ss['bid_count'] ?> bid<?= $ss['bid_count'] != 1 ? 's' : '' ?></span>
                        <span class="sched-pill"><i class="bi bi-clock"></i> Scheduled</span>
                    </div>
                </div>
                <?php if ($can_manage): ?>
                <form method="POST" id="openNowForm-<?= $ss['session_id'] ?>" class="sched-form">
                    <input type="hidden" name="session_id" value="<?= $ss['session_id'] ?>">
                    <input type="hidden" name="open_now" value="1">
                    <button type="button" class="btn-open-now"
                        onclick="openNowModal(<?= $ss['session_id'] ?>, '<?= addslashes(htmlspecialchars($ss['proc_title'])) ?>', '<?= addslashes(htmlspecialchars($ss['slsu_ref_no'])) ?>', '<?= addslashes(htmlspecialchars($ss['stream_path'])) ?>', '<?= addslashes(mediamtx_url($ss['stream_path'])) ?>')">
                        <i class="bi bi-play-fill"></i> Open Now
                    </button>
                </form>
                <?php else: ?>
                <span class="btn-open-now btn-open-now--disabled">
                    <i class="bi bi-play-fill"></i> Open Now
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Procurement Table ──────────────────────────────────────────────── -->
    <div class="sad-section-label">Open Procurements</div>
    <div class="proc-table-panel">

        <!-- Search + Filter bar -->
        <div class="filter-bar">
            <form method="GET" action="" id="procFilterForm" class="form-contents">
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search title or ref…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-dropdowns">
                    <select name="mode" onchange="document.getElementById('procFilterForm').submit();">
                        <option value="all">All Modes</option>
                        <?php foreach ($avail_modes as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter === $m ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value; document.getElementById('procFilterForm').submit();">
                        <option value="closing_asc"  <?= $sort==='closing_asc'  ? 'selected':'' ?>>Closing Soonest</option>
                        <option value="closing_desc" <?= $sort==='closing_desc' ? 'selected':'' ?>>Closing Latest</option>
                        <option value="abc_desc"     <?= $sort==='abc_desc'     ? 'selected':'' ?>>ABC: High → Low</option>
                        <option value="abc_asc"      <?= $sort==='abc_asc'      ? 'selected':'' ?>>ABC: Low → High</option>
                        <option value="newest"       <?= $sort==='newest'       ? 'selected':'' ?>>Newest</option>
                    </select>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if ($total_shown === 0): ?>
        <div class="results-empty">
            <i class="bi bi-folder2-open results-empty-icon"></i>
            <div class="results-empty-title">No procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</div>
        </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th class="col-ref-th">SLSU Ref</th>
                        <th>Title</th>
                        <th class="col-mode">Mode</th>
                        <th class="col-abc">ABC</th>
                        <th class="col-opening">Opening Date</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions-th">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $procs->fetch_assoc()):
                    $st      = strtolower($row['status'] ?? 'draft');
                    $stClass = match($st) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded', default=>'status-draft' };
                    $stLabel = ucfirst($st);
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash hash-icon"></i>
                        <?= htmlspecialchars($row['slsu_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="procurement-view.php?id=<?= $row['id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?>
                        </a>
                        <div class="proc-title-specs">
                            <i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot<?= $row['lot_count'] != 1 ? 's' : '' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
                    </td>
                    <td class="proc-abc-cell col-abc">₱<?= number_format((float)$row['abc'], 2) ?></td>
                    <td class="proc-deadline-cell col-opening">
                        <?php if (!empty($row['opening_date'])): ?>
                            <strong><?= date('M j, Y', strtotime($row['opening_date'])) ?></strong>
                            <?= date('g:i A', strtotime($row['opening_date'])) ?>
                        <?php else: ?>
                            <span class="deadline-tba">TBA</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-status">
                        <span class="proc-status-pill <?= $stClass ?>">
                            <i class="bi bi-circle-fill status-dot-tiny"></i> <?= $stLabel ?>
                        </span>
                    </td>
                    <td class="col-actions-th">
                        <div class="actions-row">
                            <a href="procurement-view.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <?php if (in_array((int)$row['id'], $scheduled_proc_ids)): ?>
                            <span class="proc-action-btn proc-action-btn--scheduled">
                                <i class="bi bi-check-circle-fill clr-forest"></i> Scheduled
                            </span>
                            <?php elseif ($can_manage): ?>
                            <a href="schedule_bid_opening.php?procurement=<?= $row['id'] ?>" class="proc-action-btn btn-schedule">
                                <i class="bi bi-calendar-plus"></i> Schedule
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <div>
                Showing <?= min($total_shown, $offset+1) ?>–<?= min($total_shown, $offset+$per_page) ?>
                of <?= number_format($total_shown) ?> open procurement<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search,'mode'=>$mode_filter!=='all'?$mode_filter:null,'sort'=>$sort!=='closing_asc'?$sort:null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php for($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<!-- ── Open Now Confirmation Modal ───────────────────────────────────────── -->
<div id="openNowModal" class="modal-backdrop" onclick="if(event.target===this)closeOpenNowModal()">
    <div class="urm-modal modal-wide">
        <button class="urm-modal-close" onclick="closeOpenNowModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon urm-modal-icon--green">
                <i class="bi bi-play-fill"></i>
            </div>
        </div>
        <div class="urm-modal-text">
            <h3>Start Bid Opening Session</h3>
            <p>This will open the session. You can then begin the <strong>Eligibility</strong> or <strong>Financial</strong> phase from inside the session.</p>
            <div class="urm-modal-user-pill" id="openNowPill"></div>
            <div class="stream-path-box">
                <i class="bi bi-broadcast clr-forest"></i>
                Stream path: <code id="openNowStreamPath"></code>
            </div>
        </div>
        <!-- Stream preview -->
        <div id="openNowStreamPreview" class="stream-preview-box">
            <iframe id="openNowIframe" src="" allow="autoplay; fullscreen" allowfullscreen></iframe>
        </div>
        <div id="openNowNoStream" class="stream-empty-box">
            <i class="bi bi-broadcast stream-empty-icon"></i>
            <span>Stream not live yet — start OBS before opening the session.</span>
        </div>
        <div class="urm-modal-actions">
            <button type="button" onclick="closeOpenNowModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="openNowConfirmBtn" class="urm-btn-confirm urm-btn-confirm--green">
                <i class="bi bi-play-fill"></i> Start Session
            </button>
        </div>
    </div>
</div>

</div>
</main>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

<script>
    let _openNowSessionId = null;

    function openNowModal(sessionId, procTitle, refNo, streamPath, streamUrl) {
        _openNowSessionId = sessionId;
        document.getElementById('openNowPill').textContent = procTitle + '  ·  ' + refNo;
        document.getElementById('openNowStreamPath').textContent = streamPath;

        const previewEl  = document.getElementById('openNowStreamPreview');
        const noStreamEl = document.getElementById('openNowNoStream');
        const iframeEl   = document.getElementById('openNowIframe');

        iframeEl.src = '';
        previewEl.style.display  = 'none';
        noStreamEl.style.display = 'flex';
        noStreamEl.querySelector('span').textContent = 'Checking stream status…';

        document.getElementById('openNowModal').classList.add('open');

        // Server-side health check instead of assuming the stream is up.
        fetch('../stream_health.php?path=' + encodeURIComponent(streamPath))
            .then(r => r.json())
            .then(data => {
                if (_openNowSessionId !== sessionId) return; // modal moved on before response
                if (data.online) {
                    iframeEl.src = streamUrl;
                    previewEl.style.display  = 'block';
                    noStreamEl.style.display = 'none';
                } else {
                    noStreamEl.querySelector('span').textContent = 'Stream not live yet — start OBS before opening the session.';
                }
            })
            .catch(() => {
                if (_openNowSessionId !== sessionId) return;
                noStreamEl.querySelector('span').textContent = 'Unable to reach stream server.';
            });
    }

    function closeOpenNowModal() {
        document.getElementById('openNowModal').classList.remove('open');
        document.getElementById('openNowIframe').src = '';
        _openNowSessionId = null;
    }

    document.getElementById('openNowConfirmBtn').addEventListener('click', function() {
        if (!_openNowSessionId) return;
        document.getElementById('openNowForm-' + _openNowSessionId).submit();
    });
</script>

</body>
</html>
