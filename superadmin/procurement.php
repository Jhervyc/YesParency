<?php
include("utils/protect-page.php");

// ── Status filter ────────────────────────────────────────────────────────────
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$valid_statuses = ['draft', 'open', 'closed', 'awarded'];
if ($status_filter !== 'all' && !in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

// ── Search ───────────────────────────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ── Handle Delete ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_procurement'])) {
    $procurement_id = isset($_POST['procurement_id']) ? intval($_POST['procurement_id']) : 0;
    if ($procurement_id > 0) {
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
        mysqli_stmt_execute($del_stmt);
        mysqli_stmt_close($del_stmt);
        $_SESSION['alert_success'] = "Procurement deleted successfully.";
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
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? AND (title LIKE ? OR philgeps_ref_no LIKE ?) ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "sss", $status_filter, $like, $like);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements WHERE title LIKE ? OR philgeps_ref_no LIKE ? ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "ss", $like, $like);
    }
} else {
    if ($status_filter !== 'all') {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "s", $status_filter);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements ORDER BY id DESC");
    }
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurements | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Super Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>
        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>
        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item"><i class="bi bi-people"></i><span>Bidder Accounts</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-journal-text"></i><span>Audit Trail</span></a>
        <div class="nav-section-label">Super Admin</div>
        <a href="user-role-management.php" class="nav-item"><i class="bi bi-person-gear"></i><span>User & Role Management</span></a>
        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Super Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- TOPBAR -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
        <span class="topbar-title">Procurements</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i><span class="badge-dot"></span></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Procurements</h2>
        <p>Manage all procurement records, view statistics, and track activity.</p>
    </div>

    <!-- ════ STATISTICS PANEL ════ -->
    <div class="sp-panel" style="margin-bottom:20px;">
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
                <div class="sp-donut" style="background:conic-gradient(<?= $grad ?>);"></div>
                <div class="sp-donut-hole">
                    <div class="sp-donut-num"><?= $total_proc ?></div>
                    <div class="sp-donut-lbl">Total<br>Procurements</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="sp-legend">
                <?php
                $legend = [
                    'draft'   => ['label' => 'Draft',   'color' => '#8B958E', 'soft' => '#EEF0ED'],
                    'open'    => ['label' => 'Open',    'color' => '#219653', 'soft' => '#E4F5EA'],
                    'closed'  => ['label' => 'Closed',  'color' => '#2F6FED', 'soft' => '#E7EEFE'],
                    'awarded' => ['label' => 'Awarded', 'color' => '#C99A1D', 'soft' => '#FCF1CF'],
                ];
                foreach ($legend as $key => $l):
                    $cnt = $counts[$key];
                    $p   = pct2($cnt, $total_proc);
                ?>
                <div class="sp-legend-row">
                    <span class="sp-legend-dot" style="background:<?= $l['color'] ?>"></span>
                    <span class="sp-legend-label"><?= $l['label'] ?></span>
                    <span class="sp-legend-value"><?= $cnt ?></span>
                    <span class="sp-legend-pct" style="color:<?= $l['color'] ?>; background:<?= $l['soft'] ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
                <div class="sp-legend-row sp-legend-total">
                    <span class="sp-legend-dot" style="background:#F0B92E"></span>
                    <span class="sp-legend-label">All Procurements</span>
                    <span class="sp-legend-value" style="color:#F0B92E"><?= $total_proc ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ PROCUREMENT LIST ════ -->
    <!-- New procurement button -->
    <a href="create_procurement.php" class="sp-new-btn">
        <i class="bi bi-plus-circle"></i> New Procurement
    </a>

    <!-- List -->
    <div class="sp-panel sp-list-panel">
        <!-- Toolbar: tabs + search (matching user-role-management layout) -->
        <form method="GET" action="" class="ap2-controls" style="margin-bottom:12px;">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by title or PhilGEPS ref..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="ap2-filters">
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
        <?php if (mysqli_num_rows($result) > 0):
            while ($row = mysqli_fetch_assoc($result)):
                $cs = strtolower($row['status']);
                $lc = ['draft'=>'#8B958E','open'=>'#219653','closed'=>'#2F6FED','awarded'=>'#F0B92E'][$cs] ?? '#8B958E';
                $pillBg = ['draft'=>'#EEF0ED','open'=>'#E4F5EA','closed'=>'#E7EEFE','awarded'=>'#FCF1CF'][$cs] ?? '#EEF0ED';
                $pillFg = ['draft'=>'#8B958E','open'=>'#219653','closed'=>'#2F6FED','awarded'=>'#C99A1D'][$cs] ?? '#8B958E';
        ?>
        <div class="sp-proc-row" style="border-left-color:<?= $lc ?>">
            <div class="sp-proc-body">
                <div class="sp-proc-title"><?= htmlspecialchars($row['title']) ?></div>
                <div class="sp-proc-meta">
                    <span><i class="bi bi-hash"></i> <?= htmlspecialchars($row['philgeps_ref_no'] ?? 'N/A') ?></span>
                    <span><i class="bi bi-cash"></i> ₱<?= number_format($row['abc'], 2) ?></span>
                    <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($row['procurement_mode'] ?? 'N/A') ?></span>
                </div>
            </div>
            <div class="sp-proc-actions">
                <span class="sp-status-pill" style="background:<?= $pillBg ?>; color:<?= $pillFg ?>">
                    <?= strtoupper($cs) ?>
                </span> | 
                <?php if ($cs !== 'draft'): ?>
                    <a href="procurement-view.php?id=<?= $row['id'] ?>" class="sp-act-btn" style="background:#f0f4f2; color:#06251b;">
                        <i class="bi bi-eye"></i> View
                    </a>
                <?php endif; ?>

                <?php if ($cs === 'draft'): ?>
                    <a href="review_procurement.php?id=<?= $row['id'] ?>" class="sp-act-btn" style="background:#E7EEFE; color:#2F6FED;">
                        <i class="bi bi-eye"></i> Review
                    </a>
                    <a href="manage_lots.php?id=<?= $row['id'] ?>" class="sp-act-btn" style="background:#E4F5EA; color:#219653;">
                        <i class="bi bi-sliders"></i> Manage
                    </a>
                <?php endif; ?>

                <form method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" style="display:inline;">
                    <input type="hidden" name="procurement_id" value="<?= $row['id'] ?>">
                    <button type="button" class="sp-act-btn"
                            style="background:#FBE7E8; color:#DB4C4C; border:none;"
                            onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>')">
                        <i class="bi bi-trash3"></i> Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endwhile;
        else: ?>
            <div class="empty-state" style="padding:48px;">
                <i class="bi bi-folder2-open"></i>
                <p>No procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
            </div>
        <?php endif; ?>

        <?php mysqli_stmt_close($stmt); ?>

        <div class="sp-list-foot">
            Showing <?= mysqli_num_rows($result) ?? 0 ?> procurement(s)
        </div>
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
            <div class="urm-modal-icon" style="background:#ffebee; color:#e53935;">
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
            <button type="button" id="deleteConfirmBtn" class="urm-btn-confirm" style="background:#e53935;">
                Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- Hidden form submitted by modal -->
<form id="deleteForm" method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" style="display:none;">
    <input type="hidden" id="deleteId" name="procurement_id">
    <input type="hidden" name="delete_procurement" value="1">
</form>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');
    function toggleSidebar() {
        if (window.innerWidth <= 768) { sidebar.classList.toggle('mobile-open'); overlay.classList.toggle('active'); }
        else { document.body.classList.toggle('sidebar-collapsed'); }
    }
    function closeSidebar() { sidebar.classList.remove('mobile-open'); overlay.classList.remove('active'); }

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
