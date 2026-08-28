<?php
/**
 * Global Sidebar Component for Bidder
 * Usage: <?php include("components/sidebar.php"); ?>
 * Optional override: $active_nav = 'procurement';
 */

$current_script = basename($_SERVER['PHP_SELF'] ?? '');
$active = $active_nav ?? $current_script;

// Active state detection
$is_dashboard_active     = ($active === 'dashboard.php' || $active === 'dashboard');
$is_procurement_active   = in_array($active, ['procurement.php', 'view_procurement.php', 'submit_bid.php', 'procurement']);
$is_bids_active          = in_array($active, ['my_bids.php', 'my_bids']);
$is_schedule_active      = ($active === 'bid_schedule' || $active === 'schedule');
$is_notifications_active = in_array($active, ['notification.php', 'notifications.php', 'notification']);
$is_settings_active      = in_array($active, ['settings.php', 'settings']);

$bidder_username = htmlspecialchars($_SESSION['username'] ?? 'Bidder');
$bidder_avatar   = !empty($_SESSION['profile_picture_url']) ? '../' . ltrim($_SESSION['profile_picture_url'], '/') : '';
?>

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/logo.png" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Bidder Portal</div>
        </div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item <?= $is_dashboard_active ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="javascript:void(0)" class="nav-item">
            <i class="bi bi-broadcast"></i><span>Bid Opening</span>
        </a>

        <div class="nav-section-label">Main</div>
        <a href="procurement.php" class="nav-item <?= $is_procurement_active ? 'active' : '' ?>">
            <i class="bi bi-folder2-open"></i><span>Procurement</span>
        </a>
        <a href="my_bids.php" class="nav-item <?= $is_bids_active ? 'active' : '' ?>">
            <i class="bi bi-inbox"></i><span>My Bids</span>
        </a>
        <a href="procurement.php" class="nav-item <?= $is_schedule_active ? 'active' : '' ?>">
            <i class="bi bi-calendar-event"></i><span>Bid Schedule</span>
        </a>
        <a href="notification.php" class="nav-item <?= $is_notifications_active ? 'active' : '' ?>">
            <i class="bi bi-bell"></i><span>Notifications</span>
        </a>

        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item <?= $is_settings_active ? 'active' : '' ?>">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
                <?php if (!empty($bidder_avatar)): ?>
                    <img src="<?= htmlspecialchars($bidder_avatar) ?>" alt="<?= $bidder_username ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="uname"><?= $bidder_username ?></div>
                <div class="urole">Bidder</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>
