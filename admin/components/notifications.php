<?php
/**
 * Global Topbar Notification Component for Admins
 * Usage: <?php include("components/notifications.php"); ?> inside <div class="topbar-right">
 * Secretariat / superadmins also get a shortcut to Notification Management.
 */
$notif_bell_manage_url = in_array($_SESSION['admin_type'] ?? 'SECRETARIAT', ['BAC', 'TWG'], true)
    ? null
    : 'notification-management.php';
include __DIR__ . '/../../includes/notification_bell.php';
