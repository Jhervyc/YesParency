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
    $where_parts[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ?)";
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
           p.id AS proc_id, p.title AS proc_title, p.slsu_ref_no,
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
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-bid-session-list.css">
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
            <span class="vp-breadcrumbs-current">Bid Sessions</span>
        </div>
        <a href="bid_opening.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Bid Opening
        </a>
    </div>

    <!-- ── Sessions Table ── -->
    <div class="sad-section-label">All Sessions</div>
    <div class="proc-table-panel">

        <!-- Search + Filter bar -->
        <div class="filter-bar">
            <form method="GET" id="filterForm" class="filter-form">
                <input type="hidden" name="filter" id="hiddenFilter" value="<?= htmlspecialchars($filter) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by title or reference number…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <!-- Status filter pills -->
                <div class="filter-pills-group">
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
        <div class="results-empty">
            <i class="bi bi-collection results-empty-icon"></i>
            <div class="results-empty-title">No sessions found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
        </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th class="col-ref-th">SLSU Ref</th>
                        <th>Procurement</th>
                        <th>Mode</th>
                        <th>ABC</th>
                        <th>Started</th>
                        <th>Invited</th>
                        <th>Status</th>
                        <th class="col-actions-th">Actions</th>
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
                        <i class="bi bi-hash hash-icon"></i>
                        <?= htmlspecialchars($row['slsu_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['proc_id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['proc_title'], 0, 60, '…')) ?>
                        </a>
                        <div class="proc-title-specs">
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-broadcast stream-icon-tiny"></i>
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
                            <span class="deadline-tba">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="invited-cell">
                        <i class="bi bi-people invited-icon"></i>
                        <?= (int)$row['invited_count'] ?>
                    </td>
                    <td>
                        <span class="session-pill <?= $pill_class ?>">
                            <i class="bi <?= $pill_icon ?> status-icon-tiny"></i>
                            <?= $pill_label ?>
                        </span>
                    </td>
                    <td class="col-actions-th">
                        <?php if ($st === 'scheduled'): ?>
                            <?php if ($can_manage): ?>
                            <form method="POST" action="bid_opening.php" id="openNowForm-<?= $row['session_id'] ?>">
                                <input type="hidden" name="session_id" value="<?= $row['session_id'] ?>">
                                <input type="hidden" name="open_now" value="1">
                                <button type="button" class="proc-action-btn btn-schedule"
                                    onclick="openNowModal(<?= $row['session_id'] ?>, '<?= addslashes(htmlspecialchars($row['proc_title'])) ?>', '<?= addslashes(htmlspecialchars($row['slsu_ref_no'])) ?>', '<?= addslashes(htmlspecialchars($row['stream_path'])) ?>', '<?= addslashes(mediamtx_url($row['stream_path'])) ?>')">
                                    <i class="bi bi-play-fill"></i> Open Now
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="proc-action-btn proc-action-btn--disabled">
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
            <p>This will transition the session to <strong>Eligibility Phase</strong> and go live immediately.</p>
            <div class="urm-modal-user-pill" id="openNowPill"></div>
            <div class="stream-path-box">
                <i class="bi bi-broadcast clr-forest"></i>
                Stream path: <code id="openNowStreamPath"></code>
            </div>
        </div>
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
