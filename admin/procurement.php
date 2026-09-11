<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

// ── Status filter ────────────────────────────────────────────────────────────
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$valid_statuses = ['draft', 'open', 'closed', 'awarded'];
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
    header("Location: procurement.php?status=" . urlencode($status_filter) . ($search ? '&search=' . urlencode($search) : ''));
    exit();
}

// ── Stats for donut ───────────────────────────────────────────────────────────
$counts = [];
foreach (['draft','open','closed','awarded'] as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = '$s'");
    $counts[$s] = intval($r->fetch_row()[0]);
}
$total_proc = array_sum($counts);

function pct2($part, $total) { return $total > 0 ? round($part / $total * 100) : 0; }

// Build conic gradient dynamically
$colors = ['draft' => '#8B958E', 'open' => '#219653', 'closed' => '#2F6FED', 'awarded' => '#F0B92E'];
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
if ($search !== '') {
    $like = '%' . $search . '%';
    if ($status_filter !== 'all') {
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM procurements WHERE LOWER(status) = ? AND (title LIKE ? OR slsu_ref_no LIKE ?)");
        mysqli_stmt_bind_param($count_stmt, "sss", $status_filter, $like, $like);
        mysqli_stmt_execute($count_stmt);
        $total_shown = (int)mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
        mysqli_stmt_close($count_stmt);

        $stmt = mysqli_prepare($conn, "SELECT id, title, slsu_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? AND (title LIKE ? OR slsu_ref_no LIKE ?) ORDER BY id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "sssii", $status_filter, $like, $like, $per_page, $offset);
    } else {
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM procurements WHERE title LIKE ? OR slsu_ref_no LIKE ?");
        mysqli_stmt_bind_param($count_stmt, "ss", $like, $like);
        mysqli_stmt_execute($count_stmt);
        $total_shown = (int)mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
        mysqli_stmt_close($count_stmt);

        $stmt = mysqli_prepare($conn, "SELECT id, title, slsu_ref_no, abc, procurement_mode, status FROM procurements WHERE title LIKE ? OR slsu_ref_no LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "ssii", $like, $like, $per_page, $offset);
    }
} else {
    if ($status_filter !== 'all') {
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM procurements WHERE LOWER(status) = ?");
        mysqli_stmt_bind_param($count_stmt, "s", $status_filter);
        mysqli_stmt_execute($count_stmt);
        $total_shown = (int)mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
        mysqli_stmt_close($count_stmt);

        $stmt = mysqli_prepare($conn, "SELECT id, title, slsu_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? ORDER BY id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "sii", $status_filter, $per_page, $offset);
    } else {
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM procurements");
        mysqli_stmt_execute($count_stmt);
        $total_shown = (int)mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
        mysqli_stmt_close($count_stmt);

        $stmt = mysqli_prepare($conn, "SELECT id, title, slsu_ref_no, abc, procurement_mode, status FROM procurements ORDER BY id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "ii", $per_page, $offset);
    }
}
$total_pages = max(1, ceil($total_shown / $per_page));
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
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
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-status-group">
                    <?php
                    $tabs = ['all'=>'All','draft'=>'Draft','open'=>'Open','closed'=>'Closed','awarded'=>'Awarded'];
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

        <?php if (mysqli_num_rows($result) > 0): ?>
        <div class="table-scroll">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th class="col-ref-narrow">SLSU Ref</th>
                        <th>Title</th>
                        <th class="col-mode">Mode</th>
                        <th class="col-abc">ABC</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($result)):
                    $cs = strtolower($row['status']);
                    $stClass = match($cs) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded', default=>'status-draft' };
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash proc-ref-icon"></i>
                        <?= htmlspecialchars($row['slsu_ref_no'] ?? '—') ?>
                    </td>
                    <td class="proc-title-cell">
                        <?php if ($cs !== 'draft'): ?>
                            <a href="procurement-view.php?id=<?= $row['id'] ?>"><?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?>
                        <?php endif; ?>
                        <div class="proc-title-sub">
                            <i class="bi bi-briefcase"></i> <?= htmlspecialchars($row['procurement_mode'] ?? '—') ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
                    </td>
                    <td class="proc-abc-cell col-abc">₱<?= number_format($row['abc'], 2) ?></td>
                    <td class="col-status">
                        <span class="proc-status-pill <?= $stClass ?>">
                            <i class="bi bi-circle-fill status-dot-icon"></i> <?= ucfirst($cs) ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <div class="actions-wrap-end">
                            <?php if ($cs !== 'draft'): ?>
                                <a href="procurement-view.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            <?php endif; ?>
                            <?php if ($cs === 'draft'): ?>
                                <a href="review_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn proc-action-btn--review-alt">
                                    <i class="bi bi-eye"></i> Review
                                </a>
                                <a href="manage_lots.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-open">
                                    <i class="bi bi-sliders"></i> Manage
                                </a>
                            <?php endif; ?>
                            <form method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="form-inline">
                                <input type="hidden" name="procurement_id" value="<?= $row['id'] ?>">
                                <button type="button" class="proc-action-btn proc-action-btn--delete"
                                        onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>')">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <div>
                Showing <strong><?= min($total_shown, $offset + 1) ?></strong>–<strong><?= min($total_shown, $offset + $per_page) ?></strong>
                of <strong><?= number_format($total_shown) ?></strong> procurement<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search, 'status'=>$status_filter !== 'all' ? $status_filter : null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="proc-empty-state">
            <i class="bi bi-folder2-open proc-empty-icon"></i>
            <div class="proc-empty-title">No procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</div>
        </div>
        <?php endif; ?>

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
<form id="deleteForm" method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" hidden>
    <input type="hidden" id="deleteId" name="procurement_id">
    <input type="hidden" name="delete_procurement" value="1">
</form>

<script>


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

    document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
        document.getElementById('deleteForm').submit();
    });

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });
</script>

</body>
</html>
