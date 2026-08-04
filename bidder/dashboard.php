<?php
    include ("utils/protect-page.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bidder Dashboard | YesParency</title>
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

        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <a href="procurement.php" class="nav-item">
            <i class="bi bi-folder2-open"></i><span>Procurements</span>
        </a>

        <a href="my_bids.php" class="nav-item">
            <i class="bi bi-inbox"></i><span>My Bids</span>
        </a>

        <div class="nav-section-label">More</div>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-calendar-event"></i><span>Bid Schedule</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>

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
        <span class="topbar-title">Dashboard Overview</span>
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
        <div class="page-header">
            <h2>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h2>
            <p>Here's your bidder overview — active procurements, your bids, and upcoming schedules.</p>
        </div>

        <!-- Stat cards -->
        <div class="dash-stats">

            <div class="dash-stat-card green">
                <div class="stat-icon-box green">
                    <i class="bi bi-folder2-open"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>12</h3>
                    <p>Open Procurements</p>
                </div>
            </div>

            <div class="dash-stat-card blue">
                <div class="stat-icon-box blue">
                    <i class="bi bi-inbox"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>3</h3>
                    <p>My Active Bids</p>
                </div>
            </div>

            <div class="dash-stat-card yellow">
                <div class="stat-icon-box yellow">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>1</h3>
                    <p>Pending Verification</p>
                </div>
            </div>

            <div class="dash-stat-card red">
                <div class="stat-icon-box red">
                    <i class="bi bi-calendar-event"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>4</h3>
                    <p>Upcoming Openings</p>
                </div>
            </div>

        </div>

        <!-- Content grid -->
        <div class="dash-grid">

            <!-- Active procurements -->
            <div class="dash-panel">
                <div class="panel-header">
                    <h4><i class="bi bi-folder2-open"></i> Open Procurements</h4>
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
                        <div class="proc-list-dot upcoming"></div>
                        <div class="proc-list-info">
                            <strong>Procurement of Janitorial Services</strong>
                            <span>Opening: April 15, 2026</span>
                        </div>
                        <span class="proc-list-badge upcoming">UPCOMING</span>
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
                        <a href="procurement.php" class="quick-action-btn">
                            <i class="bi bi-folder2-open"></i>
                            Browse Bids
                        </a>
                        <a href="my_bids.php" class="quick-action-btn">
                            <i class="bi bi-inbox"></i>
                            My Bids
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-calendar3"></i>
                            Bid Schedule
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-gear"></i>
                            Settings
                        </a>
                    </div>
                </div>

                <!-- My recent bids -->
                <div class="dash-panel">
                    <div class="panel-header">
                        <h4><i class="bi bi-inbox"></i> My Recent Bids</h4>
                        <a href="my_bids.php" class="panel-link">View all →</a>
                    </div>
                    <ul class="announcement-list">
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-file-earmark-check"></i></div>
                            <div class="ann-text">
                                <strong>Science Laboratory Equipment</strong>
                                <span>Submitted · March 20, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon" style="background:#fff8e1; color:#f9a825;"><i class="bi bi-hourglass-split"></i></div>
                            <div class="ann-text">
                                <strong>ICT Equipment and Peripherals</strong>
                                <span>Pending Verification · March 18, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon" style="background:#ffebee; color:#e53935;"><i class="bi bi-x-circle"></i></div>
                            <div class="ann-text">
                                <strong>Janitorial Services FY 2025</strong>
                                <span>Rejected · Feb 10, 2026</span>
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
