<?php
    include ("utils/protect-page.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | YesParency</title>
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

        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <a href="bid_submissions.php" class="nav-item">
            <i class="bi bi-broadcast"></i>
            <span>Bid Opening</span>
        </a>

        <div class="nav-section-label">Procurement</div>

        <a href="procurement.php" class="nav-item">
            <i class="bi bi-folder2-open"></i>
            <span>Procurements</span>
        </a>

        <a href="bid_submissions.php" class="nav-item">
            <i class="bi bi-inbox"></i>
            <span>Bid Submissions</span>
        </a>

        <div class="nav-section-label">Management</div>

        <a href="account-management.php" class="nav-item">
            <i class="bi bi-people"></i>
            <span>Bidder Accounts</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-journal-text"></i>
            <span>Audit Trail</span>
        </a>

        <div class="nav-section-label">System</div>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <i class="bi bi-person"></i>
            </div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
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
        <span class="topbar-title">Admin Dashboard</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge">
            <i class="bi bi-bell"></i>
            <span class="badge-dot"></span>
        </div>
        <div class="topbar-avatar">
            <i class="bi bi-person"></i>
        </div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page header -->
        <div class="page-header">
            <h2>Welcome, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h2>
            <p>Here's a summary of procurement activity and pending tasks.</p>
        </div>

        <!-- Stat cards -->
        <div class="dash-stats">

            <div class="dash-stat-card green">
                <div class="stat-icon-box green">
                    <i class="bi bi-folder2-open"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>12</h3>
                    <p>Active Procurements</p>
                </div>
            </div>

            <div class="dash-stat-card yellow">
                <div class="stat-icon-box yellow">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>5</h3>
                    <p>Pending Bidder Applications</p>
                </div>
            </div>

            <div class="dash-stat-card blue">
                <div class="stat-icon-box blue">
                    <i class="bi bi-inbox"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>28</h3>
                    <p>Total Bid Submissions</p>
                </div>
            </div>

            <div class="dash-stat-card red">
                <div class="stat-icon-box red">
                    <i class="bi bi-people"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>67</h3>
                    <p>Registered Bidders</p>
                </div>
            </div>

        </div>

        <!-- Content grid -->
        <div class="dash-grid">

            <!-- Recent procurements -->
            <div class="dash-panel">
                <div class="panel-header">
                    <h4><i class="bi bi-folder2-open"></i> Recent Procurements</h4>
                    <a href="procurement.php" class="panel-link">View all →</a>
                </div>
                <ul class="proc-list">

                    <li class="proc-list-item">
                        <div class="proc-list-dot live"></div>
                        <div class="proc-list-info">
                            <strong>Supply and Delivery of Science Laboratory Equipment</strong>
                            <span>Opening Today · March 26, 2026 · 10:00 AM</span>
                        </div>
                        <span class="proc-list-badge live">● LIVE</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot open"></div>
                        <div class="proc-list-info">
                            <strong>Construction of New Multi-Purpose Hall</strong>
                            <span>Deadline: April 2, 2026 · Opening: April 3, 2026</span>
                        </div>
                        <span class="proc-list-badge open">OPEN</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot upcoming"></div>
                        <div class="proc-list-info">
                            <strong>Supply of IT Equipment and Peripherals</strong>
                            <span>Opening: April 10, 2026</span>
                        </div>
                        <span class="proc-list-badge upcoming">UPCOMING</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot closed"></div>
                        <div class="proc-list-info">
                            <strong>Procurement of Janitorial Services FY 2026</strong>
                            <span>Opened: March 15, 2026 · Notice of Award Released</span>
                        </div>
                        <span class="proc-list-badge closed">CLOSED</span>
                    </li>

                </ul>
            </div>

            <!-- Right column -->
            <div style="display:flex; flex-direction:column; gap:24px;">

                <!-- Quick actions -->
                <div class="dash-panel">
                    <div class="panel-header">
                        <h4><i class="bi bi-lightning-charge"></i> Quick Actions</h4>
                    </div>
                    <div class="quick-actions">
                        <a href="create_procurement.php" class="quick-action-btn">
                            <i class="bi bi-plus-circle"></i>
                            New Procurement
                        </a>
                        <a href="account-management.php" class="quick-action-btn">
                            <i class="bi bi-person-check"></i>
                            Review Bidders
                        </a>
                        <a href="bid_submissions.php" class="quick-action-btn">
                            <i class="bi bi-inbox"></i>
                            Bid Submissions
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-journal-text"></i>
                            Audit Trail
                        </a>
                    </div>
                </div>

                <!-- Pending bidder applications -->
                <div class="dash-panel">
                    <div class="panel-header">
                        <h4><i class="bi bi-hourglass-split"></i> Pending Applications</h4>
                        <a href="account-management.php" class="panel-link">View all →</a>
                    </div>
                    <ul class="announcement-list">
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-person-badge"></i></div>
                            <div class="ann-text">
                                <strong>Juan dela Cruz</strong>
                                <span>ABC Supplies Co. · Applied July 28, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-person-badge"></i></div>
                            <div class="ann-text">
                                <strong>Maria Santos</strong>
                                <span>Santos Trading · Applied July 27, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-person-badge"></i></div>
                            <div class="ann-text">
                                <strong>Pedro Reyes</strong>
                                <span>Reyes Construction · Applied July 25, 2026</span>
                            </div>
                        </li>
                    </ul>
                </div>

            </div>

        </div>

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
