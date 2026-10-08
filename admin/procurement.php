<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
include(__DIR__ . "/../includes/pagination.php");

// ── Status filter ────────────────────────────────────────────────────────────
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$valid_statuses = ['draft', 'open', 'closed', 'awarded', 'opened'];
if ($status_filter !== 'all' && !in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

// ── Search ───────────────────────────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ── Pagination ───────────────────────────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

// ── Handle Delete ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_procurement'])) {
    $procurement_id = isset($_POST['procurement_id']) ? intval($_POST['procurement_id']) : 0;
    if ($procurement_id > 0) {
        // Fetch snapshot before deletion
        $snap = $conn->prepare("SELECT title, slsu_ref_no, status, abc, procurement_mode FROM procurements WHERE id = ?");
        $snap->bind_param("i", $procurement_id);
        $snap->execute();
        $old_data = $snap->get_result()->fetch_assoc();
        $snap->close();

        $doc_stmt = mysqli_prepare($conn, "SELECT file_path FROM procurement_documents WHERE procurement_id = ?");
        mysqli_stmt_bind_param($doc_stmt, "i", $procurement_id);
        mysqli_stmt_execute($doc_stmt);
        $r = mysqli_stmt_get_result($doc_stmt);
        while ($row = mysqli_fetch_assoc($r)) {
            if (!empty($row['file_path']) && file_exists($row['file_path'])) unlink($row['file_path']);
        }
        mysqli_stmt_close($doc_stmt);

        $del_stmt = mysqli_prepare($conn, "DELETE FROM procurements WHERE id = ?");
        mysqli_stmt_bind_param($del_stmt, "i", $procurement_id);
        if (mysqli_stmt_execute($del_stmt)) {
            $p_title = $old_data['title'] ?? "#$procurement_id";
            audit_log(
                $conn,
                'PROCUREMENT_DELETED',
                'procurements',
                $procurement_id,
                "Deleted procurement project: {$p_title}",
                $old_data,
                null
            );
            $_SESSION['alert_success'] = "Procurement deleted successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to delete procurement.";
        }
        mysqli_stmt_close($del_stmt);
    }
    // AJAX delete: the page reloads itself with location.replace (no history entry)
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => !isset($_SESSION['alert_error'])]);
        exit();
    }
    header("Location: procurement.php?" . http_build_query(array_filter(['status' => $status_filter, 'search' => $search, 'page' => $page > 1 ? $page : null])));
    exit();
}

// ── Stats for donut ───────────────────────────────────────────────────────────
$counts = [];
foreach (['draft','open','closed','awarded','opened'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = '$s'");
    $counts[$s] = intval($r->fetch_row()[0]);
}
$total_proc = array_sum($counts);

function pct2($part, $total) { return $total > 0 ? round($part / $total * 100) : 0; }

// Build conic gradient dynamically
$colors = ['draft' => '#8B958E', 'open' => '#219653', 'closed' => '#2F6FED', 'awarded' => '#F0B92E', 'opened' => '#F0B92E'];
$grad = '';
$cur = 0;
foreach ($counts as $s => $cnt) {
    $end = $cur + pct2($cnt, $total_proc);
    $grad .= "{$colors[$s]} {$cur}% {$end}%, ";
    $cur = $end;
}
$grad = rtrim($grad, ', ');
if (!$grad) $grad = '#e5eae4 0% 100%';

// ── Fetch procurements list ───────────────────────────────────────────────────
$where  = [];
$types  = '';
$params = [];
if ($status_filter !== 'all') {
    $where[]  = 'LOWER(status) = ?';
    $types   .= 's';
    $params[] = $status_filter;
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(title LIKE ? OR slsu_ref_no LIKE ?)';
    $types   .= 'ss';
    array_push($params, $like, $like);
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM procurements $where_sql");
if ($params) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$total_shown = (int)mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
mysqli_stmt_close($count_stmt);

$total_pages = max(1, (int)ceil($total_shown / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = mysqli_prepare($conn, "SELECT id, title, slsu_ref_no, abc, procurement_mode, status FROM procurements $where_sql ORDER BY id DESC LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, $types . 'ii', ...array_merge($params, [$per_page, $offset]));
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// ── AJAX: return only the list fragment ──────────────────────────────────────
if (isset($_GET['ajax'])) {
    include __DIR__ . '/partials/procurement-list.php';
    mysqli_stmt_close($stmt);
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurements | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-procurement.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Procurements</h2>
        <p>Manage all procurement records, view statistics, and track activity.</p>
    </div>

    <!-- ════ STATISTICS PANEL ════ -->
    <div class="sp-panel mb-20">
        <div class="sp-panel-head">
            <div class="sp-panel-title">
                <div class="sp-title-icon"><i class="bi bi-pie-chart"></i></div>
                Procurement Statistics
            </div>
            <div class="sp-panel-sub">Breakdown by current status</div>
        </div>

        <div class="sp-stats-body">
            <!-- Donut -->
            <div class="sp-donut-wrap">
                <div class="sp-donut" style="--donut-grad:conic-gradient(<?= $grad ?>);"></div>
                <div class="sp-donut-hole">
                    <div class="sp-donut-num"><?= $total_proc ?></div>
                    <div class="sp-donut-lbl">Total<br>Procurements</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="sp-legend">
                <?php
                $legend = [
                    'draft'   => 'Draft',
                    'open'    => 'Open',
                    'closed'  => 'Closed',
                    'awarded' => 'Awarded',
                    'opened'  => 'Opened',
                ];
                foreach ($legend as $key => $label):
                    $cnt = $counts[$key];
                    $p   = pct2($cnt, $total_proc);
                ?>
                <div class="sp-legend-row">
                    <span class="sp-legend-dot sp-legend-dot--<?= $key ?>"></span>
                    <span class="sp-legend-label"><?= $label ?></span>
                    <span class="sp-legend-value"><?= $cnt ?></span>
                    <span class="sp-legend-pct sp-legend-pct--<?= $key ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
                <div class="sp-legend-row sp-legend-total">
                    <span class="sp-legend-dot sp-legend-dot--total"></span>
                    <span class="sp-legend-label">All Procurements</span>
                    <span class="sp-legend-value sp-legend-value--total"><?= $total_proc ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ PROCUREMENT LIST ════ -->
    <!-- New procurement button -->
    <a href="create_procurement.php" class="sp-new-btn sp-new-btn--dark">
        <i class="bi bi-plus-circle"></i> New Procurement
    </a>

    <!-- List -->
    <div class="proc-table-panel mb-24">
        <div class="filter-bar">
            <form method="GET" action="" id="procFilterForm" class="form-contents">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by title or SLSU ref..."
                        enterkeyhint="search" autocomplete="off"
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-status-group filter-chips">
                    <?php
                    $tabs = ['all'=>'All','draft'=>'Draft','open'=>'Open','closed'=>'Closed','opened'=>'Opened'];
                    foreach ($tabs as $val => $label):
                    ?>
                        <button type="submit" name="status" value="<?= $val ?>"
                                class="ap2-filter-btn <?= $status_filter === $val ? 'active' : '' ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn">
                    <i class="bi bi-search"></i> Search
                </button>
            </form>
        </div>

        <div id="procListRoot" class="ajax-list-root">
            <?php include __DIR__ . '/partials/procurement-list.php'; ?>
        </div>

        <?php mysqli_stmt_close($stmt); ?>
    </div>

</div>
</main>

<!-- Toasts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<!-- DELETE CONFIRMATION MODAL -->
<div id="deleteModal" class="modal-backdrop">
    <div class="urm-modal">
        <button class="urm-modal-close" onclick="closeDeleteModal()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon urm-modal-icon--red">
                <i class="bi bi-trash3"></i>
            </div>
        </div>

        <div class="urm-modal-text">
            <h3>Delete Procurement</h3>
            <p>This will permanently delete the procurement and all associated files. This cannot be undone.</p>
            <div class="urm-modal-user-pill" id="deleteModalTitle"></div>
        </div>

        <div class="urm-modal-actions">
            <button type="button" onclick="closeDeleteModal()" class="urm-btn-cancel">Cancel</button>
            <button type="button" id="deleteConfirmBtn" class="urm-btn-confirm urm-btn-confirm--red">
                Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- Hidden form submitted by modal -->
<form id="deleteForm" method="POST" action="procurement.php" hidden>
    <input type="hidden" id="deleteId" name="procurement_id">
    <input type="hidden" name="delete_procurement" value="1">
</form>

<script src="../js/ajax-list.js"></script>
<script>
    AjaxList.init({
        root:     '#procListRoot',
        form:     '#procFilterForm',
        endpoint: 'procurement.php',
    });

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    function openDeleteModal(id, title) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteModalTitle').textContent = title;
        document.getElementById('deleteModal').classList.add('open');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('open');
    }

    // Row delete buttons live inside the AJAX-swapped list, so delegate
    document.getElementById('procListRoot').addEventListener('click', e => {
        const btn = e.target.closest('[data-delete-id]');
        if (btn) openDeleteModal(btn.dataset.deleteId, btn.dataset.deleteTitle);
    });

    // Delete via fetch, then reload in place (keeps filter/page, no history entry)
    document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
        this.disabled = true;
        const form = document.getElementById('deleteForm');
        const data = new FormData(form);
        data.append('ajax', '1');
        fetch('procurement.php' + location.search, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(() => location.replace(location.href))
            .catch(() => { form.action = 'procurement.php' + location.search; form.submit(); });
    });

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });
</script>

</body>
</html>
