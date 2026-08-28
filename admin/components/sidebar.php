<?php
/**
 * Global Sidebar Component for Admin
 * Usage: <?php include("components/sidebar.php"); ?>
 * Optional override: $active_nav = 'procurement';
 */

$current_script = basename($_SERVER['PHP_SELF'] ?? '');
$active = $active_nav ?? $current_script;

// Active state detection
$is_procurement_active   = in_array($active, ['procurement.php', 'create_procurement.php', 'manage_lots.php', 'review_procurement.php', 'procurement-view.php', 'procurement']);
$is_bids_active          = in_array($active, ['bid_submissions.php', 'bid-submission-view.php', 'bid_submissions']);
$is_dashboard_active     = ($active === 'dashboard.php' || $active === 'dashboard');
$is_accounts_active      = in_array($active, ['account-management.php', 'account-management']);
$is_announcements_active = in_array($active, ['announcements.php', 'announcements']);
$is_settings_active      = in_array($active, ['settings.php', 'settings']);
$is_audit_active         = in_array($active, ['audit_trail.php', 'audit_trail']);
$admin_username          = htmlspecialchars($_SESSION['username'] ?? 'Admin');
?>

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/logo.png" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item <?= $is_dashboard_active ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="bid_submissions.php" class="nav-item <?= ($active === 'bid_opening') ? 'active' : '' ?>">
            <i class="bi bi-broadcast"></i><span>Bid Opening</span>
        </a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item <?= $is_procurement_active ? 'active' : '' ?>">
            <i class="bi bi-folder2-open"></i><span>Procurements</span>
        </a>
        <a href="bid_submissions.php" class="nav-item <?= ($is_bids_active && $active !== 'bid_opening') ? 'active' : '' ?>">
            <i class="bi bi-inbox"></i><span>Bid Submissions</span>
        </a>

        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item <?= $is_accounts_active ? 'active' : '' ?>">
            <i class="bi bi-people"></i><span>Bidder Accounts</span>
        </a>
        <a href="announcements.php" class="nav-item <?= $is_announcements_active ? 'active' : '' ?>">
            <i class="bi bi-megaphone"></i><span>Announcements</span>
        </a>
        <a href="audit_trail.php" class="nav-item <?= $is_audit_active ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>Audit Trail</span>
        </a>

        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item <?= $is_settings_active ? 'active' : '' ?>">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>
    </nav>
<?php
$admin_avatar = !empty($_SESSION['profile_picture_url']) ? '../' . ltrim($_SESSION['profile_picture_url'], '/') : '';
?>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
                <?php if (!empty($admin_avatar)): ?>
                    <img src="<?= htmlspecialchars($admin_avatar) ?>" alt="<?= $admin_username ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="uname"><?= $admin_username ?></div>
                <div class="urole">Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>
