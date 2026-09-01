<?php
/**
 * Restrict page to SECRETARIAT admins only.
 * Include this AFTER protect-page.php on any page BAC/TWG must not access.
 * Redirects restricted roles to their dashboard.
 */
if (!isset($_SESSION['admin_type'])) {
    if (isset($conn) && isset($_SESSION['user_id'])) {
        $rs_user_id = (int)$_SESSION['user_id'];
        $rs_stmt = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
        if ($rs_stmt) {
            $rs_stmt->bind_param("i", $rs_user_id);
            $rs_stmt->execute();
            $rs_row = $rs_stmt->get_result()->fetch_assoc();
            $_SESSION['admin_type'] = $rs_row['admin_type'] ?? 'SECRETARIAT';
            $rs_stmt->close();
        }
    }
}

if (in_array($_SESSION['admin_type'] ?? 'SECRETARIAT', ['BAC', 'TWG'])) {
    header("Location: dashboard-twd-bac.php");
    exit();
}
