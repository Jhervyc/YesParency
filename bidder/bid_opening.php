<?php
include("utils/protect-page.php");

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
    WHERE bos.status IN ('started','eligibility','financial','awarding')
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
$total_pages = max(1, ceil($total_shown / $per_page));

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
<link rel="stylesheet" href="../style.css">
<link rel="stylesheet" href="../dashboard.css">
<style>
/* stat grid */
.bo-stat-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px; }
@media(min-width:640px){ .bo-stat-grid{ grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; } }

/* live banner */
.bo-live-banner {
    background:linear-gradient(135deg,#06251b 0%,#0c3d2c 60%,#14593f 100%);
    border-radius:0; padding:16px; color:#fff;
    display:flex; flex-direction:column; gap:14px; margin-bottom:0;
}
@media(min-width:640px){ .bo-live-banner{ flex-direction:row; align-items:center; justify-content:space-between; padding:20px 24px; } }
.bo-live-pill { display:inline-flex; align-items:center; gap:6px; background:#dc2626; color:#fff; font-size:11px; font-weight:800; padding:4px 12px; border-radius:20px; letter-spacing:.4px; animation:pulseLive 1.8s infinite; }
@keyframes pulseLive { 0%,100%{opacity:1;} 50%{opacity:.7;} }
.bo-live-dot { width:7px; height:7px; border-radius:50%; background:#fff; }
.bo-live-info { flex:1; min-width:0; }
.bo-live-title { font-size:15px; font-weight:800; color:#fff; margin-bottom:4px; line-height:1.3; }
@media(min-width:640px){ .bo-live-title{ font-size:17px; } }
.bo-live-meta { font-size:11.5px; color:#d1e5db; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.bo-live-meta span { display:inline-flex; align-items:center; gap:5px; }
.bo-live-meta i { color:#ffc107; }
.bo-live-actions { display:flex; gap:8px; flex-wrap:wrap; }
.bo-live-actions a { flex:1; justify-content:center; }
@media(min-width:640px){ .bo-live-actions{ flex-shrink:0; flex-wrap:nowrap; } .bo-live-actions a{ flex:none; } }
.bo-btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:6px; background:#ffc107; color:#06251b; font-size:12.5px; font-weight:800; padding:9px 18px; border-radius:10px; text-decoration:none; transition:all .15s; }
.bo-btn-primary:hover { background:#e6ac00; }

/* scheduled panel */
.sched-panel { background:#fff; border:1px solid #eaeeec; border-radius:18px; box-shadow:0 1px 2px rgba(16,36,26,.03),0 10px 24px -14px rgba(16,36,26,.08); overflow:hidden; }
.sched-panel-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #f0f4f2; background:#fafcfb; }
.sched-list { display:flex; flex-direction:column; }
.sched-card { background:#fff; padding:14px 18px; display:flex; align-items:center; gap:14px; border-bottom:1px solid #f0f4f2; transition:background .12s; }
.sched-card:last-child { border-bottom:none; }
.sched-card:hover { background:#fbfdfc; }
.sched-icon { width:40px; height:40px; border-radius:10px; background:#fef8e7; color:#d97706; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.sched-info { flex:1; min-width:0; }
.sched-title { font-size:13px; font-weight:700; color:#06251b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px; }
.sched-meta { font-size:10.5px; color:#88968d; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sched-pill { font-size:10px; font-weight:700; padding:2px 7px; border-radius:5px; background:#fef3c7; color:#d97706; display:inline-flex; align-items:center; gap:4px; }
.sched-empty { padding:24px 16px; text-align:center; color:#88968d; font-size:12.5px; }
.section-view-more { font-size:11.5px; font-weight:700; color:#1f7a3d; text-decoration:none; display:inline-flex; align-items:center; gap:4px; transition:color .15s; white-space:nowrap; }
.section-view-more:hover { color:#06251b; }
</style>
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
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#1f7a3d 0% <?= $stat_total>0?round($stat_open/$stat_total*100):0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-check-circle" style="color:#1f7a3d;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#1f7a3d;"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Active Opportunities</div>
            </div>
        </div>
        <div class="ap2-stat <?= $stat_closing>0?'bsv-stat-warn':'' ?>">
            <div class="ap2-ring" style="background:conic-gradient(<?= $stat_closing>0?'#e67e22':'#8B958E' ?> 0% <?= $stat_total>0?round($stat_closing/$stat_total*100):0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-alarm" style="color:<?= $stat_closing>0?'#e67e22':'#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $stat_closing>0?'#e67e22':'inherit' ?>;"><?= $stat_closing ?></div>
                <div class="ap2-stat-lbl">Closing in 3 Days</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#2F6FED 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-broadcast" style="color:#2F6FED;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#2F6FED;"><?= $stat_sessions ?></div>
                <div class="ap2-stat-lbl">Total Sessions</div>
            </div>
        </div>
    </div>

    <!-- ── Current Live Session ── -->
    <div class="sched-panel" style="margin-bottom:20px;">
        <div class="sched-panel-head">
            <span style="font-size:13.5px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-broadcast" style="color:#dc2626;"></i> Current Live Session
            </span>
            <a href="bid-session-list.php?filter=live" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>
        <?php if ($live_session): ?>
        <div class="bo-live-banner" style="border-radius:0; box-shadow:none; margin-bottom:0; border:none;">
            <div style="display:flex; align-items:center; gap:14px; flex:1; min-width:0;">
                <div>
                    <div style="margin-bottom:8px;">
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
        <div style="padding:22px 18px; display:flex; align-items:center; gap:14px; color:#55665a; font-size:13px;">
            <i class="bi bi-broadcast" style="font-size:22px; color:#c7d2cb; flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700; color:#374151; margin-bottom:2px;">No Active Live Session</div>
                <div style="font-size:12px; color:#88968d;">There is no bid opening session currently in progress.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Scheduled Bid Opening ── -->
    <div class="sched-panel" style="margin-bottom:28px;">
        <div class="sched-panel-head">
            <span style="font-size:13.5px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-calendar-event" style="color:#d97706;"></i> Scheduled Bid Opening
            </span>
            <a href="bid-session-list.php?filter=scheduled" class="section-view-more"><i class="bi bi-arrow-right"></i> View More</a>
        </div>
        <div class="sched-list">
            <?php if (empty($scheduled_sessions)): ?>
            <div class="sched-empty">
                <i class="bi bi-calendar-x" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
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
    <div class="sad-section-label" style="display:flex; align-items:center; justify-content:space-between;">
        <span>Open Procurements</span>
        <a href="bid-session-list.php" class="section-view-more">All Sessions <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="proc-table-panel">
        <div class="filter-bar">
            <form method="GET" action="" id="procFilterForm" style="display:contents;">
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search title or ref…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-dropdowns">
                    <?php if (!empty($avail_modes)): ?>
                    <select name="mode" onchange="document.getElementById('procFilterForm').submit();">
                        <option value="">All Modes</option>
                        <?php foreach ($avail_modes as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter===$m?'selected':'' ?>><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value; document.getElementById('procFilterForm').submit();">
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

        <?php if ($total_shown === 0): ?>
        <div style="padding:48px 20px; text-align:center; color:#88968d;">
            <i class="bi bi-folder2-open" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            <div style="font-size:13px; font-weight:700;">No open procurements found<?= $search?' for "'.htmlspecialchars($search).'"':'' ?>.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th style="width:120px;">SLSU Ref</th>
                        <th>Title</th>
                        <th class="col-mode">Mode</th>
                        <th class="col-abc">ABC</th>
                        <th class="col-opening">Opening Date</th>
                        <th class="col-status">Status</th>
                        <th style="text-align:right; min-width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $procs->fetch_assoc()):
                    $st      = strtolower($row['status'] ?? 'open');
                    $stClass = match($st) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded', default=>'status-draft' };
                    $isScheduled = in_array((int)$row['id'], $scheduled_proc_ids);
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash" style="color:#88968d; font-size:10px;"></i>
                        <?= htmlspecialchars($row['slsu_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['title'],0,65,'…')) ?>
                        </a>
                        <div style="font-size:10.5px; color:#88968d; margin-top:2px;">
                            <i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot<?= $row['lot_count']!=1?'s':'' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count']!=1?'s':'' ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode']??'—') ?></span>
                    </td>
                    <td class="proc-abc-cell col-abc">₱<?= number_format((float)$row['abc'],2) ?></td>
                    <td class="proc-deadline-cell col-opening">
                        <?php if (!empty($row['opening_date'])): ?>
                            <strong><?= date('M j, Y', strtotime($row['opening_date'])) ?></strong>
                            <?= date('g:i A', strtotime($row['opening_date'])) ?>
                        <?php else: ?><span style="color:#c7d2cb;">TBA</span><?php endif; ?>
                    </td>
                    <td class="col-status">
                        <span class="proc-status-pill <?= $stClass ?>">
                            <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= ucfirst($st) ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <?php if ($isScheduled): ?>
                            <a href="bid-session-list.php?filter=scheduled" class="proc-action-btn" style="background:#fef3c7; color:#d97706;">
                                <i class="bi bi-calendar-check"></i> Scheduled
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
                Showing <?= min($total_shown,$offset+1) ?>–<?= min($total_shown,$offset+$per_page) ?>
                of <?= number_format($total_shown) ?> procurement<?= $total_shown!=1?'s':'' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search,'mode'=>$mode_filter,'sort'=>$sort!=='closing_asc'?$sort:null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
</main>
</body>
</html>
