<?php
    include ("utils/protect-page.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | YesParency</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared styles -->
    <link rel="stylesheet" href="../style.css">
    <!-- Dashboard styles -->
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<!-- Mobile overlay -->
<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- ========================= -->
<!-- SIDEBAR                   -->
<!-- ========================= -->
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Procurement System</div>
        </div>
    </a>

    <!-- Nav -->
    <nav class="sidebar-nav">

        <div class="nav-section-label">Main</div>

        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-broadcast"></i>
            <span>Bid Opening Live</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-calendar-event"></i>
            <span>Bid Schedule</span>
        </a>

        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <div class="nav-section-label">Account</div>

        <a href="bidder-registration.php" class="nav-item">
            <i class="bi bi-person-plus"></i>
            <span>Register as Bidder</span>
        </a>

    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <i class="bi bi-person"></i>
            </div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole"><?= htmlspecialchars($_SESSION['role']) ?></div>
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
        <span class="topbar-title">Dashboard Overview</span>
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
            <h2>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h2>
            <p>Here's what's happening in SLSU procurement today.</p>
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
                    <i class="bi bi-broadcast"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>3</h3>
                    <p>Live Bid Sessions</p>
                </div>
            </div>

            <div class="dash-stat-card blue">
                <div class="stat-icon-box blue">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>8</h3>
                    <p>Upcoming Openings</p>
                </div>
            </div>

            <div class="dash-stat-card red">
                <div class="stat-icon-box red">
                    <i class="bi bi-megaphone"></i>
                </div>
                <div class="dash-stat-info">
                    <h3>5</h3>
                    <p>New Announcements</p>
                </div>
            </div>

        </div>

        <!-- Content grid -->
        <div class="dash-grid">

            <!-- Active procurements panel -->
            <div class="dash-panel">
                <div class="panel-header">
                    <h4><i class="bi bi-folder2-open"></i> Active & Upcoming Procurements</h4>
                    <a href="#" class="panel-link">View all →</a>
                </div>
                <ul class="proc-list">

                    <li class="proc-list-item">
                        <div class="proc-list-dot live"></div>
                        <div class="proc-list-info">
                            <strong>Supply and Delivery of Science Laboratory Equipment</strong>
                            <span>Opening Today · March 26, 2026 · 10:00 AM · BAC Conference Room</span>
                        </div>
                        <span class="proc-list-badge live">● LIVE</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot open"></div>
                        <div class="proc-list-info">
                            <strong>Construction of New Multi-Purpose Hall at SLSU Main Campus</strong>
                            <span>Deadline: April 2, 2026 · Opening: April 3, 2026</span>
                        </div>
                        <span class="proc-list-badge open">OPEN</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot upcoming"></div>
                        <div class="proc-list-info">
                            <strong>Supply of Information Technology Equipment and Peripherals</strong>
                            <span>Opening: April 10, 2026 · Submission: April 9, 2026</span>
                        </div>
                        <span class="proc-list-badge upcoming">UPCOMING</span>
                    </li>

                    <li class="proc-list-item">
                        <div class="proc-list-dot closed"></div>
                        <div class="proc-list-info">
                            <strong>Procurement of Janitorial and Maintenance Services FY 2026</strong>
                            <span>Opened: March 15, 2026 · Notice of Award Released</span>
                        </div>
                        <span class="proc-list-badge closed">CLOSED</span>
                    </li>

                </ul>
            </div>

            <!-- Right column -->
            <div style="display: flex; flex-direction: column; gap: 24px;">

                <!-- Quick actions -->
                <div class="dash-panel">
                    <div class="panel-header">
                        <h4><i class="bi bi-lightning-charge"></i> Quick Actions</h4>
                    </div>
                    <div class="quick-actions">
                        <a href="bidder-registration.php" class="quick-action-btn">
                            <i class="bi bi-person-plus"></i>
                            Register as Bidder
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-broadcast"></i>
                            Watch Live Bid
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-calendar3"></i>
                            Bid Schedule
                        </a>
                        <a href="dashboard.php" class="quick-action-btn">
                            <i class="bi bi-megaphone"></i>
                            Announcements
                        </a>
                    </div>
                </div>

                <!-- Announcements -->
                <div class="dash-panel">
                    <div class="panel-header">
                        <h4><i class="bi bi-megaphone"></i> Announcements</h4>
                        <a href="#" class="panel-link">View all →</a>
                    </div>
                    <ul class="announcement-list">
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-info-circle"></i></div>
                            <div class="ann-text">
                                <strong>Pre-bid Conference Reminder</strong>
                                <span>July 28, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-file-earmark-text"></i></div>
                            <div class="ann-text">
                                <strong>Updated PhilGEPS Requirements</strong>
                                <span>July 25, 2026</span>
                            </div>
                        </li>
                        <li class="announcement-item">
                            <div class="ann-icon"><i class="bi bi-calendar-check"></i></div>
                            <div class="ann-text">
                                <strong>Bid Opening Schedule — Q3 2026</strong>
                                <span>July 20, 2026</span>
                            </div>
                        </li>
                    </ul>
                </div>

            </div>

        </div>

    </div>
</main>

<script>
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('dashOverlay');
    const body     = document.body;

    // Desktop: collapse/expand
    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }
</script>

</body>
</html>
