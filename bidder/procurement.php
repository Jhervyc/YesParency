<?php
    include ("utils/protect-page.php");

    // Fetch all open procurements available for bidding
    $query = "
        SELECT 
            p.id,
            p.philgeps_ref_no,
            p.title,
            p.abc,
            p.procurement_mode,
            p.closing_date,
            (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
            (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
        FROM procurements p
        WHERE p.status = 'open'
        ORDER BY p.closing_date ASC
    ";
    
    $result = mysqli_query($conn, $query);
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
            <div class="sub">Bidder Portal</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="my_bids.php" class="nav-item"><i class="bi bi-inbox"></i><span>My Bids</span></a>
        <div class="nav-section-label">More</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-calendar-event"></i><span>Bid Schedule</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Bidder</div>
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
        <span class="topbar-title">Active Bidding Opportunities</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <div class="page-header">
            <h2>Active Bidding Opportunities</h2>
            <p>All procurement projects currently open for bid submission. Click any item to view details and submit a bid.</p>
        </div>

        <?php if ($result && mysqli_num_rows($result) > 0): ?>

            <div class="bp-grid">
            <?php while ($procurement = mysqli_fetch_assoc($result)): ?>

                <div class="bp-card">

                    <div class="bp-card-top">
                        <span class="proc-status-pill open">● Open</span>
                        <?php if ($procurement['closing_date']): ?>
                            <?php
                                $deadline   = strtotime($procurement['closing_date']);
                                $now        = time();
                                $diff_days  = ceil(($deadline - $now) / 86400);
                                $urgency    = $diff_days <= 3 ? 'bp-deadline-urgent' : 'bp-deadline-normal';
                            ?>
                            <span class="bp-deadline <?= $urgency ?>">
                                <i class="bi bi-clock"></i>
                                <?= $diff_days > 0 ? $diff_days . ' day(s) left' : 'Closing soon' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <h4 class="bp-title"><?= htmlspecialchars($procurement['title']) ?></h4>

                    <div class="bp-meta">
                        <span><i class="bi bi-hash"></i> <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A') ?></span>
                        <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($procurement['procurement_mode']) ?></span>
                        <span><i class="bi bi-cash"></i> ₱<?= number_format($procurement['abc'], 2) ?></span>
                    </div>

                    <div class="bp-info-row">
                        <div class="bp-info-item">
                            <span class="bp-info-label">Submission Deadline</span>
                            <span class="bp-info-value">
                                <?= $procurement['closing_date']
                                    ? date('M j, Y · g:i a', strtotime($procurement['closing_date']))
                                    : 'N/A' ?>
                            </span>
                        </div>
                        <div class="bp-info-item">
                            <span class="bp-info-label">Lots</span>
                            <span class="bp-info-value"><?= $procurement['lot_count'] ?> lot(s)</span>
                        </div>
                        <div class="bp-info-item">
                            <span class="bp-info-label">Documents</span>
                            <span class="bp-info-value"><?= $procurement['doc_count'] ?> attached</span>
                        </div>
                    </div>

                    <a href="view_procurement.php?id=<?= $procurement['id'] ?>" class="bp-btn-view">
                        View Details & Submit Bid <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

            <?php endwhile; ?>
            </div>

        <?php else: ?>
            <div class="dash-panel">
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    <p>No active procurements open for bidding at this time.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

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
</script>

</body>
</html>
