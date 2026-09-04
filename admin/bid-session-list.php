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
$admin_type  = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$user_role   = $_SESSION['role'] ?? 'admin';
$can_manage  = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

// ── Filters & Pagination ───────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filter     = isset($_GET['filter']) && in_array($_GET['filter'], ['all','live','scheduled','ended'])
              ? $_GET['filter'] : 'all';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 12;
$offset     = ($page - 1) * $per_page;

// ── Query ─────────────────────────────────────────────────────────────────
$where_parts = [];
$params      = [];
$types       = '';

if ($filter === 'live') {
    $where_parts[] = "bos.status IN ('eligibility','financial','awarding')";
} elseif ($filter === 'scheduled') {
    $where_parts[] = "bos.status = 'scheduled'";
} elseif ($filter === 'ended') {
    $where_parts[] = "bos.status = 'ended'";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(p.title LIKE ? OR p.philgeps_ref_no LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Count
$cnt_sql = "SELECT COUNT(*) FROM bid_opening_sessions bos JOIN procurements p ON bos.procurement_id = p.id $where_sql";
$cnt = $conn->prepare($cnt_sql);
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, ceil($total_shown / $per_page));

// Main list
$list_params = array_merge($params, [$per_page, $offset]);
$list_types  = $types . 'ii';
$list = $conn->prepare("
    SELECT bos.id AS session_id, bos.status AS session_status,
           bos.stream_path, bos.started_at, bos.ended_at, bos.created_at,
           p.id AS proc_id, p.title AS proc_title, p.philgeps_ref_no,
           p.procurement_mode, p.abc,
           (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count,
           (SELECT COUNT(*) FROM bid_session_invited bsi WHERE bsi.bid_session_id = bos.id) AS invited_count
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    $where_sql
    ORDER BY
        CASE bos.status
            WHEN 'eligibility' THEN 1
            WHEN 'financial'   THEN 2
            WHEN 'awarding'    THEN 3
            WHEN 'scheduled'   THEN 4
            WHEN 'ended'       THEN 5
        END ASC,
        bos.created_at DESC
    LIMIT ? OFFSET ?
");
$list->bind_param($list_types, ...$list_params);
$list->execute();
$sessions = $list->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Sessions | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Breadcrumb & Top Bar ── */
        .vp-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .vp-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #88968d;
            font-weight: 600;
        }
        .vp-breadcrumbs a {
            color: #1f7a3d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color .15s;
        }
        .vp-breadcrumbs a:hover { text-decoration: underline; color: #06251b; }
        .vp-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #06251b;
            font-size: 12.5px;
            font-weight: 700;
            background: #fff;
            border: 1px solid #eaeeec;
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,.02);
        }
        .vp-back-link:hover { background: #06251b; color: #ffc107; border-color: #06251b; }

        /* ── Table panel ── */
        .proc-table-panel {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .proc-table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; }
        .proc-table thead th {
            background: #fafcfb; padding: 13px 16px;
            font-size: 11px; font-weight: 700; color: #55665a;
            text-transform: uppercase; letter-spacing: .04em;
            border-bottom: 1.5px solid #edf1ef; white-space: nowrap;
        }
        .proc-table tbody tr { border-bottom: 1px solid #f0f4f2; transition: background .12s; }
        .proc-table tbody tr:last-child { border-bottom: none; }
        .proc-table tbody tr:hover { background: #fbfdfc; }
        .proc-table td { padding: 14px 16px; vertical-align: middle; }

        .proc-ref-cell  { font-weight: 700; color: #06251b; font-size: 11.5px; white-space: nowrap; }
        .proc-title-cell { font-weight: 700; color: #1a2a20; font-size: 13px; line-height: 1.35; max-width: 280px; }
        .proc-title-cell a { color: inherit; text-decoration: none; }
        .proc-title-cell a:hover { color: #1f7a3d; text-decoration: underline; }
        .proc-mode-tag {
            background: #f0f4f2; color: #384d40;
            padding: 3px 8px; border-radius: 6px;
            font-size: 10.5px; font-weight: 600; white-space: nowrap;
        }
        .proc-abc-cell {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 13px; font-weight: 800; color: #06251b; white-space: nowrap;
        }
        .proc-deadline-cell { font-size: 11.5px; color: #63736a; white-space: nowrap; line-height: 1.3; }
        .proc-deadline-cell strong { color: #1a1a1a; display: block; }

        /* ── Status pills ── */
        .session-pill {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10.5px; font-weight: 700;
            padding: 3px 9px; border-radius: 20px; white-space: nowrap;
        }
        .pill-live      { background: #fee2e2; color: #dc2626; }
        .pill-scheduled { background: #fef3c7; color: #d97706; }
        .pill-ended     { background: #e0f2f1; color: #00796b; }

        /* ── Live pulse ── */
        .pill-live { animation: pulseLive 1.8s infinite; }
        @keyframes pulseLive { 0%,100%{opacity:1;} 50%{opacity:.65;} }

        /* ── Action buttons ── */
        .proc-action-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 13px; border-radius: 8px;
            font-size: 11.5px; font-weight: 700;
            text-decoration: none; transition: all .15s;
            white-space: nowrap; cursor: pointer; border: none;
        }
        .btn-view       { background: #f0f4f2; color: #06251b; }
        .btn-view:hover { background: #06251b; color: #ffc107; }
        .btn-open       { background: #1f7a3d; color: #fff; }
        .btn-open:hover { background: #14592d; }
        .btn-schedule   { background: #ffc107; color: #06251b; }
        .btn-schedule:hover { background: #e6ac00; }

        /* ── Search bar ── */
        .ap2-search-field {
            flex: 1; min-width: 200px; position: relative;
            display: flex; align-items: center;
        }
        .ap2-search-field i {
            position: absolute; left: 11px; color: #88968d;
            font-size: 13px; pointer-events: none;
        }
        .ap2-search-field input {
            width: 100%; padding: 9px 12px 9px 33px;
            border: 1.5px solid #e0e8e4; border-radius: 9px;
            font-size: 12px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; background: #fafcfb; outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .ap2-search-field input:focus {
            border-color: #1f7a3d; background: #fff;
            box-shadow: 0 0 0 3px rgba(31,122,61,.08);
        }
        .ap2-filter-btn {
            background: #eef2f0; border: 1.5px solid #e0e8e4; color: #06251b;
            font-size: 12px; font-weight: 700; padding: 8px 14px;
            border-radius: 9px; cursor: pointer; transition: all .15s;
            font-family: 'Poppins', sans-serif; white-space: nowrap;
        }
        .ap2-filter-btn:hover, .ap2-filter-btn.active {
            background: #06251b; color: #ffc107; border-color: #06251b;
        }
        .ap2-go-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: #ffc107; color: #16241d;
            font-family: 'Poppins', sans-serif; font-weight: 700;
            font-size: 12.5px; border: none; padding: 8px 18px;
            border-radius: 9px; cursor: pointer; transition: all .15s; white-space: nowrap;
        }
        .ap2-go-btn:hover { background: #e6ac00; }

        /* ── Pagination ── */
        .table-foot {
            padding: 14px 20px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            border-top: 1px solid #f0f4f2;
            font-size: 12px; color: #88968d;
        }
        .pagination { display: flex; gap: 5px; flex-wrap: wrap; }
        .page-link {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px;
            font-size: 12px; font-weight: 700; text-decoration: none;
            color: #06251b; background: #f0f4f2; transition: all .15s;
        }
        .page-link:hover, .page-link.active { background: #06251b; color: #ffc107; }
        .page-link.disabled { opacity: .4; pointer-events: none; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Bid Sessions'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Breadcrumb & Top Bar ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="bid_opening.php"><i class="bi bi-envelope-open-fill"></i> Bid Opening</a>
            <span>/</span>
            <span style="color:#06251b;">Bid Sessions</span>
        </div>
        <a href="bid_opening.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Bid Opening
        </a>
    </div>

    <!-- ── Sessions Table ── -->
    <div class="sad-section-label">All Sessions</div>
    <div class="proc-table-panel">

        <!-- Search + Filter bar -->
        <div style="padding:16px 20px; border-bottom:1px solid #f0f4f2; background:#fafcfb;">
            <form method="GET" id="filterForm" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="filter" id="hiddenFilter" value="<?= htmlspecialchars($filter) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by title or reference number…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <!-- Status filter pills -->
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <?php foreach (['all'=>'All','live'=>'Live','scheduled'=>'Scheduled','ended'=>'Ended'] as $val => $lbl): ?>
                    <button type="button"
                        class="ap2-filter-btn <?= $filter === $val ? 'active' : '' ?>"
                        onclick="document.getElementById('hiddenFilter').value='<?= $val ?>'; document.getElementById('filterForm').submit();">
                        <?= $lbl ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if ($total_shown === 0): ?>
        <div style="padding:48px 20px; text-align:center; color:#88968d;">
            <i class="bi bi-collection" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            <div style="font-size:13px; font-weight:700;">No sessions found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th style="width:130px;">PhilGEPS Ref</th>
                        <th>Procurement</th>
                        <th>Mode</th>
                        <th>ABC</th>
                        <th>Started</th>
                        <th>Invited</th>
                        <th>Status</th>
                        <th style="text-align:right; min-width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $sessions->fetch_assoc()):
                    $st = $row['session_status'];
                    $is_live_status = in_array($st, ['eligibility', 'financial', 'awarding']);

                    if ($is_live_status) {
                        $pill_class = 'pill-live';
                        $pill_icon  = 'bi-broadcast';
                        $pill_label = ucfirst($st) . ' Phase';
                    } elseif ($st === 'scheduled') {
                        $pill_class = 'pill-scheduled';
                        $pill_icon  = 'bi-clock';
                        $pill_label = 'Scheduled';
                    } else {
                        $pill_class = 'pill-ended';
                        $pill_icon  = 'bi-check2-circle';
                        $pill_label = 'Ended';
                    }
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash" style="color:#88968d; font-size:10px;"></i>
                        <?= htmlspecialchars($row['philgeps_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['proc_id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['proc_title'], 0, 60, '…')) ?>
                        </a>
                        <div style="font-size:10.5px; color:#88968d; margin-top:2px;">
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-broadcast" style="font-size:9px;"></i>
                            <?= htmlspecialchars($row['stream_path']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
                    </td>
                    <td class="proc-abc-cell">₱<?= number_format((float)$row['abc'], 2) ?></td>
                    <td class="proc-deadline-cell">
                        <?php if ($row['started_at']): ?>
                            <strong><?= date('M j, Y', strtotime($row['started_at'])) ?></strong>
                            <?= date('g:i A', strtotime($row['started_at'])) ?>
                        <?php else: ?>
                            <span style="color:#c7d2cb;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px; color:#55665a; font-weight:600;">
                        <i class="bi bi-people" style="color:#88968d;"></i>
                        <?= (int)$row['invited_count'] ?>
                    </td>
                    <td>
                        <span class="session-pill <?= $pill_class ?>">
                            <i class="bi <?= $pill_icon ?>" style="font-size:9px;"></i>
                            <?= $pill_label ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($st === 'scheduled'): ?>
                            <?php if ($can_manage): ?>
                            <form method="POST" action="bid_opening.php" id="openNowForm-<?= $row['session_id'] ?>">
                                <input type="hidden" name="session_id" value="<?= $row['session_id'] ?>">
                                <input type="hidden" name="open_now" value="1">
                                <button type="button" class="proc-action-btn btn-schedule"
                                    onclick="openNowModal(<?= $row['session_id'] ?>, '<?= addslashes(htmlspecialchars($row['proc_title'])) ?>', '<?= addslashes(htmlspecialchars($row['philgeps_ref_no'])) ?>', '<?= addslashes(htmlspecialchars($row['stream_path'])) ?>', '<?= addslashes(mediamtx_url($row['stream_path'])) ?>')">
                                    <i class="bi bi-play-fill"></i> Open Now
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="proc-action-btn" style="background:#f0f4f2; color:#88968d; cursor:not-allowed; opacity:.6;">
                                <i class="bi bi-play-fill"></i> Open Now
                            </span>
                            <?php endif; ?>
                        <?php elseif ($is_live_status): ?>
                            <?php if ($can_manage): ?>
                            <a href="bid_session.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-open">
                                <i class="bi bi-broadcast"></i> Manage
                            </a>
                            <?php else: ?>
                            <a href="bid_session.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-view">
                                <i class="bi bi-box-arrow-in-right"></i> Join
                            </a>
                            <?php endif; ?>
                        <?php else: ?>
                        <a href="bid_opening_conduct.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-view">
                            <i class="bi bi-eye"></i> View
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <div>
                Showing <?= min($total_shown, $offset + 1) ?>–<?= min($total_shown, $offset + $per_page) ?>
                of <?= number_format($total_shown) ?> session<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search' => $search, 'filter' => $filter !== 'all' ? $filter : null]);
                $qstr = $qs ? '&' . http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1, $page - 1) ?><?= $qstr ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages, $page + 1) ?><?= $qstr ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</div>
</main>

<!-- ── Open Now Confirmation Modal ───────────────────────────────────────── -->
<div id="openNowModal" class="modal-backdrop" onclick="if(event.target===this)closeOpenNowModal()">
    <div class="urm-modal" style="max-width:520px; width:100%;">
        <button class="urm-modal-close" onclick="closeOpenNowModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon" style="background:#e8f5e9; color:#1f7a3d;">
                <i class="bi bi-play-fill"></i>
            </div>
        </div>
        <div class="urm-modal-text">
            <h3>Start Bid Opening Session</h3>
            <p>This will transition the session to <strong>Eligibility Phase</strong> and go live immediately.</p>
            <div class="urm-modal-user-pill" id="openNowPill"></div>
            <div style="margin-top:10px; display:inline-flex; align-items:center; gap:6px; background:#f0f4f2; border-radius:8px; padding:5px 12px; font-size:11.5px; font-weight:700; color:#55665a;">
                <i class="bi bi-broadcast" style="color:#1f7a3d;"></i>
                Stream path: <code id="openNowStreamPath" style="font-size:11.5px; color:#06251b;"></code>
            </div>
        </div>
        <div id="openNowStreamPreview" style="margin:0 0 20px; border-radius:12px; overflow:hidden; background:#000; aspect-ratio:16/9; display:none;">
            <iframe id="openNowIframe" src="" allow="autoplay; fullscreen" allowfullscreen
                style="width:100%; height:100%; border:none;"></iframe>
        </div>
        <div id="openNowNoStream" style="margin:0 0 20px; background:#f7faf8; border:1.5px dashed #cfdbd4; border-radius:12px; padding:16px; text-align:center; color:#88968d; font-size:12.5px; display:flex; align-items:center; gap:10px; justify-content:center;">
            <i class="bi bi-broadcast" style="font-size:20px; color:#c7d2cb;"></i>
            <span>Stream not live yet — start OBS before opening the session.</span>
        </div>
        <div class="urm-modal-actions">
            <button type="button" onclick="closeOpenNowModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="openNowConfirmBtn" class="urm-btn-confirm" style="background:#1f7a3d;">
                <i class="bi bi-play-fill"></i> Start Session
            </button>
        </div>
    </div>
</div>

<script>
    let _openNowSessionId = null;

    function openNowModal(sessionId, procTitle, refNo, streamPath, streamUrl) {
        _openNowSessionId = sessionId;
        document.getElementById('openNowPill').textContent = procTitle + '  ·  ' + refNo;
        document.getElementById('openNowStreamPath').textContent = streamPath;
        document.getElementById('openNowIframe').src = streamUrl;
        document.getElementById('openNowStreamPreview').style.display = 'block';
        document.getElementById('openNowNoStream').style.display = 'none';
        document.getElementById('openNowModal').classList.add('open');
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
