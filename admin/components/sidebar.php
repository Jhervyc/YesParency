<?php
/**
 * Global Sidebar Component for Admin
 * Usage: <?php include("components/sidebar.php"); ?>
 * Optional override: $active_nav = 'procurement';
 */

$current_script = basename($_SERVER['PHP_SELF'] ?? '');
$active = $active_nav ?? $current_script;

// Active state detection
$is_dashboard_active     = in_array($active, ['dashboard.php', 'dashboard', 'dashboard-twd-bac.php']);
$is_bid_opening_active   = in_array($active, ['bid_opening.php', 'bid_opening', 'bid_opening_conduct.php', 'schedule_bid_opening.php', 'bid-session-list.php']);
$is_procurement_active   = in_array($active, ['procurement.php', 'create_procurement.php', 'manage_lots.php', 'review_procurement.php', 'procurement-view.php', 'procurement', 'procurement-twd-bac.php']);
$is_bids_active          = in_array($active, ['bid_submissions.php', 'bid-submission-view.php', 'quotation_management.php']);
$is_invitations_active   = in_array($active, ['invitation_requests.php']);
$is_accounts_active      = in_array($active, ['account-management.php', 'account-management', 'bidder-profile.php', 'bidder-profile']);
$is_announcements_active = in_array($active, ['announcements.php', 'announcements']);
$is_audit_active         = in_array($active, ['audit_trail.php', 'audit_trail']);
$is_settings_active      = in_array($active, ['settings.php', 'settings']);
$is_notification_active  = in_array($active, ['notification.php', 'notification']);

$admin_username = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// Fetch admin role (BAC, TWG, SECRETARIAT) — cached in session after first load
if (!isset($_SESSION['admin_type']) && isset($conn) && isset($_SESSION['user_id'])) {
    $role_stmt = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    if ($role_stmt) {
        $role_stmt->bind_param("i", $_SESSION['user_id']);
        $role_stmt->execute();
        $role_row = $role_stmt->get_result()->fetch_assoc();
        $_SESSION['admin_type'] = $role_row['admin_type'] ?? 'SECRETARIAT';
        $role_stmt->close();
    }
}
$admin_type    = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$is_restricted = in_array($admin_type, ['BAC', 'TWG']);
?>

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/logo.png" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Admin Panel</div>
        </div>
    </a>

    <nav class="sidebar-nav">

        <!-- ── Overview ─────────────────────────────────────────────────── -->
        <div class="nav-section-label">Overview</div>
        <a href="<?= $is_restricted ? 'dashboard-twd-bac.php' : 'dashboard.php' ?>"
           class="nav-item <?= $is_dashboard_active ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <!-- ── Bid Opening ───────────────────────────────────────────────── -->
        <div class="nav-section-label">Bid Opening</div>
        <a href="bid_opening.php" class="nav-item <?= $is_bid_opening_active ? 'active' : '' ?>">
            <i class="bi bi-envelope-open-fill"></i><span>Bid Opening</span>
        </a>

        <!-- ── Procurement (Secretariat sees full set; BAC/TWG see read-only view) ── -->
        <div class="nav-section-label">Procurement</div>
        <?php if ($is_restricted): ?>
            <a href="procurement-twd-bac.php" class="nav-item <?= $is_procurement_active ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i><span>Procurements</span>
            </a>
        <?php else: ?>
            <a href="procurement.php" class="nav-item <?= $is_procurement_active ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i><span>Procurements</span>
            </a>
            <a href="bid_submissions.php" class="nav-item <?= $is_bids_active ? 'active' : '' ?>">
                <i class="bi bi-inbox"></i><span>Bid Submissions</span>
            </a>
        <?php endif; ?>

        <!-- ── Management (Secretariat only) ────────────────────────────── -->
        <?php if (!$is_restricted): ?>
        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item <?= $is_accounts_active ? 'active' : '' ?>">
            <i class="bi bi-people"></i><span>Bidder Accounts</span>
        </a>
        <a href="announcements.php" class="nav-item <?= $is_announcements_active ? 'active' : '' ?>">
            <i class="bi bi-megaphone"></i><span>Announcements</span>
        </a>
        <a href="invitation_requests.php" class="nav-item <?= $is_invitations_active ? 'active' : '' ?>">
            <i class="bi bi-envelope-paper-fill"></i><span>Invitations</span>
            <?php
            // Badge for pending invitation requests
            if (isset($conn)) {
                $inv_pending = $conn->query("SELECT COUNT(*) FROM invitation_requests WHERE status='pending'")?->fetch_row()[0] ?? 0;
                if ($inv_pending > 0):
            ?>
                <span style="background:#e67e22; color:#fff; font-size:9px; font-weight:800; padding:1px 5px; border-radius:8px; margin-left:auto;"><?= $inv_pending ?></span>
            <?php endif; } ?>
        </a>
        <?php endif; ?>

        <!-- ── Records ───────────────────────────────────────────────────── -->
        <div class="nav-section-label">Records</div>
        <?php if ($is_restricted): ?>
        <a href="notification.php" class="nav-item <?= $is_notification_active ? 'active' : '' ?>">
            <i class="bi bi-bell"></i><span>Notifications</span>
        </a>
        <?php endif; ?>
        <a href="audit_trail.php" class="nav-item <?= $is_audit_active ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>Audit Trail</span>
        </a>

        <!-- ── System ───────────────────────────────────────────────────── -->
        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item <?= $is_settings_active ? 'active' : '' ?>">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>

    </nav>

<?php
$admin_avatar = !empty($_SESSION['profile_picture_url'])
    ? '../' . ltrim($_SESSION['profile_picture_url'], '/')
    : '';
?>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
                <?php if (!empty($admin_avatar)): ?>
                    <img src="<?= htmlspecialchars($admin_avatar) ?>" alt="<?= $admin_username ?>"
                         style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="uname"><?= $admin_username ?></div>
                <div class="urole"><?= htmlspecialchars($admin_type) ?></div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>
