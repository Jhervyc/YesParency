<?php
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';
include(__DIR__ . "/../includes/pagination.php");

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
    $where_parts[] = "bos.status IN ('eligibility','financial','offered')";
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
$total_pages = max(1, (int)ceil($total_shown / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

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
            WHEN 'offered'     THEN 3
            WHEN 'scheduled'   THEN 4
            WHEN 'ended'       THEN 5
        END ASC,
        bos.created_at DESC
    LIMIT ? OFFSET ?
");
$list->bind_param($list_types, ...$list_params);
$list->execute();
$sessions = $list->get_result();

// ── AJAX: return only the list fragment ──────────────────────────────────────
if (isset($_GET['ajax'])) {
    include __DIR__ . '/partials/bid-session-list.php';
    exit();
}
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
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by title or reference number…" value="<?= htmlspecialchars($search) ?>"
                           enterkeyhint="search" autocomplete="off">
                </div>
                <!-- Status filter pills -->
                <div class="filter-pills-group filter-chips">
                    <?php foreach (['all'=>'All','live'=>'Live','scheduled'=>'Scheduled','ended'=>'Ended'] as $val => $lbl): ?>
                    <button type="submit" name="filter" value="<?= $val ?>"
                        class="ap2-filter-btn <?= $filter === $val ? 'active' : '' ?>">
                        <?= $lbl ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <div id="sessionListRoot" class="ajax-list-root">
            <?php include __DIR__ . '/partials/bid-session-list.php'; ?>
        </div>

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

<script src="../js/ajax-list.js"></script>
<script>
    AjaxList.init({
        root:     '#sessionListRoot',
        form:     '#filterForm',
        endpoint: 'bid-session-list.php',
    });

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
