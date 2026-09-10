<?php
/**
 * Global Topbar Component for General User
 * Usage: <?php include("components/topbar.php"); ?>
 * Optional override: $topbar_title = 'Custom Title';
 */

if (!isset($topbar_title) || empty($topbar_title)) {
    $script_name = basename($_SERVER['PHP_SELF'] ?? '');
    $titles_map = [
        'dashboard.php'             => 'User Dashboard',
        'procurement.php'           => 'Procurements',
        'view_procurement.php'      => 'Procurement Details',
        'bidder-registration.php'   => 'Bidder Registration',
        'settings.php'              => 'Account Settings',
    ];
    $topbar_title = $titles_map[$script_name] ?? 'YesParency';
}

$user_tb_avatar = !empty($_SESSION['profile_picture_url']) ? '../' . ltrim($_SESSION['profile_picture_url'], '/') : '';
?>

<!-- TOPBAR -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
        <span class="topbar-title"><?= htmlspecialchars($topbar_title) ?></span>
    </div>
    <div class="topbar-right">

        <a href="settings.php" class="topbar-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center; text-decoration:none;" title="Account Settings">
            <?php if (!empty($user_tb_avatar)): ?>
                <img src="<?= htmlspecialchars($user_tb_avatar) ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
            <?php else: ?>
                <i class="bi bi-person"></i>
            <?php endif; ?>
        </a>
    </div>
</div>

<script>
(function() {
    if (window._sidebarToggleLoaded) return;
    window._sidebarToggleLoaded = true;

    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('dashOverlay');
        if (!sidebar) return;
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            if (overlay) overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    };

    window.closeSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('dashOverlay');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
    };
})();
</script>
