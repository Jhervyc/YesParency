<?php
/**
 * Global Sidebar Component for General User
 * Usage: <?php include("components/sidebar.php"); ?>
 * Optional override: $active_nav = 'dashboard';
 */

$current_script = basename($_SERVER['PHP_SELF'] ?? '');
$active = $active_nav ?? $current_script;

// Active state detection
$is_dashboard_active     = in_array($active, ['dashboard.php', 'dashboard']);
$is_procurement_active   = in_array($active, ['procurement.php', 'view_procurement.php', 'procurement']);
$is_registration_active  = in_array($active, ['bidder-registration.php', 'bidder-registration', 'registration']);
$is_settings_active      = in_array($active, ['settings.php', 'settings']);

$user_username = htmlspecialchars($_SESSION['username'] ?? 'User');
$user_avatar   = !empty($_SESSION['profile_picture_url']) ? '../' . ltrim($_SESSION['profile_picture_url'], '/') : '';
?>

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/logo.png" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">User Portal</div>
        </div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item <?= $is_dashboard_active ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="procurement.php" class="nav-item <?= $is_procurement_active ? 'active' : '' ?>">
            <i class="bi bi-folder2-open"></i><span>Procurement</span>
        </a>

        <div class="nav-section-label">Bidder Accreditation</div>
        <a href="bidder-registration.php" class="nav-item <?= $is_registration_active ? 'active' : '' ?>">
            <i class="bi bi-person-plus"></i><span>Register as Bidder</span>
        </a>

        <div class="nav-section-label">Account</div>
        <a href="settings.php" class="nav-item <?= $is_settings_active ? 'active' : '' ?>">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
                <?php if (!empty($user_avatar)): ?>
                    <img src="<?= htmlspecialchars($user_avatar) ?>" alt="<?= $user_username ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="uname"><?= $user_username ?></div>
                <div class="urole">User</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>
