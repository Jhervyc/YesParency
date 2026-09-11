<?php
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';

// ── Bidder guard ────────────────────────────────────────────────────────────
$user_role  = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['bidder', 'user'])) {
    header("Location: dashboard.php");
    exit();
}
$can_manage = false; // bidders cannot manage sessions

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
    <link rel="stylesheet" href="../css/pages/bidder-bid-session-list.css">
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
        <div class="session-filter-bar-wrap">
            <form method="GET" id="filterForm" class="session-filter-form">
                <input type="hidden" name="filter" id="hiddenFilter" value="<?= htmlspecialchars($filter) ?>">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by title or reference number…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <!-- Status filter pills -->
                <div class="filter-status-group">
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
        <div class="proc-empty-state">
            <i class="bi bi-collection proc-empty-icon"></i>
            <div class="proc-empty-title">No sessions found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
        </div>
        <?php else: ?>
        <div class="table-scroll-x">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th class="col-ref-narrow">SLSU Ref</th>
                        <th>Procurement</th>
                        <th>Mode</th>
                        <th>ABC</th>
                        <th>Started</th>
                        <th>Invited</th>
                        <th>Status</th>
                        <th class="col-actions-right">Actions</th>
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
                        <i class="bi bi-hash proc-ref-icon"></i>
                        <?= htmlspecialchars($row['slsu_ref_no']) ?>
                    </td>
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['proc_id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($row['proc_title'], 0, 60, '…')) ?>
                        </a>
                        <div class="proc-title-sub">
                            <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-broadcast stream-icon"></i>
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
                            <span class="clr-placeholder">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="invited-cell">
                        <i class="bi bi-people invited-icon"></i>
                        <?= (int)$row['invited_count'] ?>
                    </td>
                    <td>
                        <span class="session-pill <?= $pill_class ?>">
                            <i class="bi <?= $pill_icon ?> session-pill-icon"></i>
                            <?= $pill_label ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <?php if ($st === 'scheduled'): ?>
                            <span class="proc-action-btn proc-action-btn--upcoming">
                                <i class="bi bi-clock"></i> Upcoming
                            </span>
                        <?php elseif ($is_live_status): ?>
                            <a href="../live.php" class="proc-action-btn btn-open">
                                <i class="bi bi-play-circle-fill"></i> Watch Live
                            </a>
                        <?php else: ?>
                        <a href="view_procurement.php?id=<?= $row['proc_id'] ?>" class="proc-action-btn btn-view">
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

</body>
</html>
