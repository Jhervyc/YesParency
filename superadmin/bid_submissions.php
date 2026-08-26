<?php
include("utils/protect-page.php");

// Handle Verify / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_bid'])) {
    $bid_id     = intval($_POST['bid_id']);
    $new_status = $_POST['status_action'] === 'approve' ? 'submitted' : 'rejected';
    $u = $conn->prepare("UPDATE bids SET status = ? WHERE id = ?");
    $u->bind_param("si", $new_status, $bid_id);
    if ($u->execute()) {
        $_SESSION['alert_success'] = "Bid " . strtoupper($new_status) . " successfully.";
    } else {
        $_SESSION['alert_error'] = "Failed to update bid status.";
    }
    $u->close();
    header("Location: " . $_SERVER['PHP_SELF'] . ($search ?? '' ? '?search='.urlencode($_GET['search'] ?? '') : ''));
    exit();
}

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Live stat counts
$stat_open   = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0];
$stat_bids   = $conn->query("SELECT COUNT(*) FROM bids b JOIN procurements p ON b.procurement_id = p.id WHERE p.status = 'open'")->fetch_row()[0];
$stat_pend   = $conn->query("SELECT COUNT(*) FROM bids WHERE status = 'pending'")->fetch_row()[0];

// Fetch procurements
if ($search !== '') {
    $like = '%' . $search . '%';
    $ps = $conn->prepare("
        SELECT p.id AS procurement_id, p.title AS procurement_title, p.philgeps_ref_no,
               COUNT(b.id) AS total_bids,
               SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending_bids
        FROM procurements p
        LEFT JOIN bids b ON p.id = b.procurement_id
        WHERE p.status = 'open' AND (p.title LIKE ? OR p.philgeps_ref_no LIKE ?)
        GROUP BY p.id ORDER BY p.id DESC
    ");
    $ps->bind_param("ss", $like, $like);
} else {
    $ps = $conn->prepare("
        SELECT p.id AS procurement_id, p.title AS procurement_title, p.philgeps_ref_no,
               COUNT(b.id) AS total_bids,
               SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending_bids
        FROM procurements p
        LEFT JOIN bids b ON p.id = b.procurement_id
        WHERE p.status = 'open'
        GROUP BY p.id ORDER BY p.id DESC
    ");
}
$ps->execute();
$proc_result = $ps->get_result();

$procurements = [];
while ($p_row = $proc_result->fetch_assoc()) {
    $p_id = $p_row['procurement_id'];

    $bs = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username AS bidder_name, u.email AS bidder_email, u.firstname, u.lastname
        FROM bids b JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ? ORDER BY b.submission_date DESC
    ");
    $bs->bind_param("i", $p_id);
    $bs->execute();
    $bids_res = $bs->get_result();

    $bids = [];
    while ($b = $bids_res->fetch_assoc()) {
        $bid_id = $b['bid_id'];

        $ls = $conn->prepare("SELECT l.lot_number, l.lot_title FROM bid_lots bl JOIN lots l ON bl.lot_id = l.id WHERE bl.bid_id = ?");
        $ls->bind_param("i", $bid_id);
        $ls->execute();
        $b['lots'] = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
        $ls->close();

        $ds = $conn->prepare("SELECT document_name, file_path FROM bid_documents WHERE bid_id = ? AND document_type = 'other' LIMIT 1");
        $ds->bind_param("i", $bid_id);
        $ds->execute();
        $b['receipt'] = $ds->get_result()->fetch_assoc();
        $ds->close();

        $bids[] = $b;
    }
    $bs->close();

    $p_row['bids'] = $bids;
    $procurements[] = $p_row;
}
$ps->close();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Submissions | YesParency</title>
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
        <a href="procurement.php" class="nav-item"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item active"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>
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
                <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
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
        <span class="topbar-title">Bid Submissions</span>
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
        <h2>Bid Submissions</h2>
        <p>Live overview of all open procurement bid submissions.</p>
    </div>

    <!-- ── Stat cards ── -->
    <div class="sad-section-label">Overview</div>
    <div class="ap2-stats" style="grid-template-columns:repeat(3,1fr); margin-bottom:20px;">
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#2F6FED 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-inbox" style="color:#2F6FED;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_bids ?></div>
                <div class="ap2-stat-lbl">Total Bids Received</div>
            </div>
        </div>
        <div class="ap2-stat <?= $stat_pend > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="background:conic-gradient(<?= $stat_pend > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= $stat_bids > 0 ? round($stat_pend/$stat_bids*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:<?= $stat_pend > 0 ? '#e67e22' : '#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $stat_pend > 0 ? '#e67e22' : 'inherit' ?>"><?= $stat_pend ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>
    </div>

    <!-- ── List panel ── -->
    <div class="sp-panel sp-list-panel">
        <!-- ── Search bar ── -->
        <form method="GET" action="" class="ap2-controls" style="margin-bottom:16px;">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by procurement title or PhilGEPS ref..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="ap2-go-btn">
                <i class="bi bi-search"></i> Search
            </button>
            <?php if ($search): ?>
                <a href="bid_submissions.php" class="ap2-filter-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="bi bi-x"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($procurements)): ?>
            <div class="empty-state" style="padding:48px;">
                <i class="bi bi-inbox"></i>
                <p>No open procurements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
            </div>

        <?php else: ?>
        <div class="proc-table-list">
        <?php foreach ($procurements as $proc):
            $hasPending  = intval($proc['pending_bids']) > 0;
            $totalBids   = intval($proc['total_bids']);
            $pendingBids = intval($proc['pending_bids']);
        ?>
            <div class="proc-row <?= $hasPending ? 'bsv-row-pending' : '' ?>">

                <!-- Colored left bar: orange if pending, green otherwise -->
                <div class="proc-row-status-bar" style="background:<?= $hasPending ? '#e67e22' : '#219653' ?>"></div>

                <div class="proc-row-body">
                    <div class="proc-row-main">
                        <div class="proc-row-title">
                            <?= htmlspecialchars($proc['procurement_title']) ?>
                        </div>
                        <div class="proc-row-meta">
                            <span><i class="bi bi-hash"></i> <?= htmlspecialchars($proc['philgeps_ref_no'] ?? 'N/A') ?></span>
                            <span><i class="bi bi-inbox"></i> <?= $totalBids ?> bid<?= $totalBids != 1 ? 's' : '' ?> received</span>
                            <?php if ($hasPending): ?>
                                <span style="color:#e67e22; font-weight:700;">
                                    <i class="bi bi-hourglass-split"></i> <?= $pendingBids ?> pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="proc-row-actions">
                        <?php if ($hasPending): ?>
                            <span class="bsv-pending-badge">
                                <i class="bi bi-hourglass-split"></i> <?= $pendingBids ?> Pending
                            </span>
                        <?php endif; ?>

                        <span class="proc-status-pill open">OPEN</span>

                        <a href="bid-submission-view.php?id=<?= $proc['procurement_id'] ?>" class="proc-action-btn review">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </div>
                </div>

            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="sp-list-foot">
            <?= count($procurements) ?> open procurement<?= count($procurements) != 1 ? 's' : '' ?> shown
        </div>

    </div><!-- /.sp-panel.sp-list-panel -->

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
</script>

</body>
</html>
