<?php 
    include("utils/protect-page.php");

    // 1. Get status filter from URL (defaults to 'all', converted to lowercase for DB matching)
    $status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';

    // Valid status list based on your DB schema (excluding cancelled)
    $valid_statuses = ['draft', 'open', 'closed', 'awarded'];
    if ($status_filter !== 'all' && !in_array($status_filter, $valid_statuses)) {
        $status_filter = 'all';
    }

    // 2. Handle Delete POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_procurement'])) {
    $procurement_id = isset($_POST['procurement_id']) ? intval($_POST['procurement_id']) : 0;

    if ($procurement_id > 0) {
        // 1. Fetch file paths of associated documents before deleting
        $doc_stmt = mysqli_prepare($conn, "SELECT file_path FROM procurement_documents WHERE procurement_id = ?");
        mysqli_stmt_bind_param($doc_stmt, "i", $procurement_id);
        mysqli_stmt_execute($doc_stmt);
        $result = mysqli_stmt_get_result($doc_stmt);

        // 2. Delete physical files from server disk
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                unlink($row['file_path']); // Permanently removes file from uploads folder
            }
        }
        mysqli_stmt_close($doc_stmt);
        
        // 3. Delete main procurement record
        $del_stmt = mysqli_prepare($conn, "DELETE FROM procurements WHERE id = ?");
        mysqli_stmt_bind_param($del_stmt, "i", $procurement_id);
        mysqli_stmt_execute($del_stmt);
        mysqli_stmt_close($del_stmt);

        $_SESSION['alert_success'] = "Procurement and associated files deleted successfully.";
    }

    // Preserve selected status tab after delete redirect
    header("Location: procurement.php?status=" . urlencode($status_filter));
    exit();
}

    // 3. Fetch procurements based on selected status
    if ($status_filter !== 'all') {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "s", $status_filter);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements ORDER BY id DESC");
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
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

<!-- ========================= -->
<!-- SIDEBAR                   -->
<!-- ========================= -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Admin Panel</div>
        </div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="bid_submissions.php" class="nav-item">
            <i class="bi bi-broadcast"></i><span>Bid Opening</span>
        </a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item active">
            <i class="bi bi-folder2-open"></i><span>Procurements</span>
        </a>
        <a href="create_procurement.php" class="nav-item">
            <i class="bi bi-plus-circle"></i><span>Create Procurement</span>
        </a>
        <a href="bid_submissions.php" class="nav-item">
            <i class="bi bi-inbox"></i><span>Bid Submissions</span>
        </a>

        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item">
            <i class="bi bi-people"></i><span>Bidder Accounts</span>
        </a>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-megaphone"></i><span>Announcements</span>
        </a>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-journal-text"></i><span>Audit Trail</span>
        </a>

        <div class="nav-section-label">System</div>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- ========================= -->
<!-- TOPBAR                    -->
<!-- ========================= -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="topbar-title">Procurements</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge">
            <i class="bi bi-bell"></i>
            <span class="badge-dot"></span>
        </div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page header -->
        <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>Procurements</h2>
                <p>Manage all procurement records and their status.</p>
            </div>
            <a href="create_procurement.php" class="btn-register" style="text-decoration:none;">
                <i class="bi bi-plus-circle"></i> New Procurement
            </a>
        </div>

        <!-- Status filter tabs -->
        <div class="proc-filter-tabs">
            <a href="procurement.php?status=all"     class="proc-tab <?= $status_filter === 'all'     ? 'active' : '' ?>"><i class="bi bi-grid"></i> All</a>
            <a href="procurement.php?status=draft"   class="proc-tab <?= $status_filter === 'draft'   ? 'active' : '' ?>"><i class="bi bi-pencil-square"></i> Draft</a>
            <a href="procurement.php?status=open"    class="proc-tab <?= $status_filter === 'open'    ? 'active' : '' ?>"><i class="bi bi-unlock"></i> Open</a>
            <a href="procurement.php?status=closed"  class="proc-tab <?= $status_filter === 'closed'  ? 'active' : '' ?>"><i class="bi bi-lock"></i> Closed</a>
            <a href="procurement.php?status=awarded" class="proc-tab <?= $status_filter === 'awarded' ? 'active' : '' ?>"><i class="bi bi-award"></i> Awarded</a>
        </div>

        <!-- Procurement list -->
        <div class="dash-panel" style="margin-top:0;">

            <?php if (mysqli_num_rows($result) > 0): ?>

                <div class="proc-table-list">
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <?php $current_status = strtolower($row['status']); ?>

                    <div class="proc-row">

                        <div class="proc-row-status-bar <?= $current_status ?>"></div>

                        <div class="proc-row-body">
                            <div class="proc-row-main">
                                <div class="proc-row-title">
                                    <?= htmlspecialchars($row['title']) ?>
                                </div>
                                <div class="proc-row-meta">
                                    <span><i class="bi bi-hash"></i> <?= htmlspecialchars($row['philgeps_ref_no'] ?? 'N/A') ?></span>
                                    <span><i class="bi bi-cash"></i> ₱<?= number_format($row['abc'], 2) ?></span>
                                    <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($row['procurement_mode'] ?? 'N/A') ?></span>
                                </div>
                            </div>

                            <div class="proc-row-actions">
                                <span class="proc-status-pill <?= $current_status ?>">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>

                                <?php if ($current_status === 'draft'): ?>
                                    <a href="review_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn review">
                                        <i class="bi bi-eye"></i> Review
                                    </a>
                                <?php endif; ?>

                                <?php if (in_array($current_status, ['draft'])): ?>
                                    <a href="manage_lots.php?id=<?= $row['id'] ?>" class="proc-action-btn manage">
                                        <i class="bi bi-sliders"></i> Manage
                                    </a>
                                <?php endif; ?>

                                <form method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?>" style="display:inline;" onsubmit="return confirm('Delete this procurement and all associated files? This cannot be undone.');">
                                    <input type="hidden" name="procurement_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_procurement" class="proc-action-btn delete">
                                        <i class="bi bi-trash3"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>

                <?php endwhile; ?>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    <p>No procurements found for status "<?= htmlspecialchars($status_filter) ?>".</p>
                </div>
            <?php endif; ?>

            <?php mysqli_stmt_close($stmt); ?>

        </div>

    </div>
</main>

<!-- Session alerts -->
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) {
        setTimeout(() => toast.classList.add('hide'), 4000);
    }
</script>

</body>
</html>
