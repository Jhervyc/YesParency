<?php
/**
 * Global Topbar Component for Super Admin
 * Usage: <?php include("components/topbar.php"); ?>
 * Optional override: $topbar_title = 'Custom Title';
 */

if (!isset($topbar_title) || empty($topbar_title)) {
    $script_name = basename($_SERVER['PHP_SELF'] ?? '');
    $titles_map = [
        'dashboard.php'             => 'Super Admin Dashboard',
        'announcements.php'         => 'System Announcements',
        'procurement.php'           => 'Procurements',
        'user-role-management.php'  => 'User & Role Management',
        'account-management.php'    => 'Bidder Accounts',
        'bid_submissions.php'       => 'Bid Submissions',
        'settings.php'              => 'Settings',
        'review_procurement.php'    => 'Procurement > Review & Publish',
        'create_procurement.php'    => 'Create Procurement',
        'manage_lots.php'           => 'Manage Lots',
        'procurement-view.php'      => 'Procurement > Procurement View',
        'bid-submission-view.php'   => 'Bid Submissions > Procurement View',
    ];
    $topbar_title = $titles_map[$script_name] ?? 'YesParency';
}
?>

<!-- TOPBAR -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
        <span class="topbar-title"><?= htmlspecialchars($topbar_title) ?></span>
    </div>
    <div class="topbar-right">
        <?php include(__DIR__ . "/notifications.php"); ?>

        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
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
