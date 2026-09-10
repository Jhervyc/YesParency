<?php
include("utils/protect-page.php");

header('Content-Type: application/json');
$current_user_id = (int)$_SESSION['user_id'];
$current_role    = $_SESSION['role'] ?? 'superadmin';

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';

if ($action === 'fetch') {
    // Fetch notifications applicable to this user
    $stmt = $conn->prepare("
        SELECT 
            sn.notification_id,
            sn.title,
            sn.message,
            sn.target_type,
            sn.target_role,
            sn.target_user_id,
            sn.created_at,
            u.firstname AS creator_fname,
            u.lastname AS creator_lname,
            tu.firstname AS target_fname,
            tu.lastname AS target_lname,
            CASE WHEN unr.read_at IS NOT NULL THEN 1 ELSE 0 END AS is_read
        FROM system_notifications sn
        LEFT JOIN users u ON sn.created_by = u.user_id
        LEFT JOIN users tu ON sn.target_user_id = tu.user_id
        LEFT JOIN user_notification_reads unr 
            ON sn.notification_id = unr.notification_id 
            AND unr.user_id = ?
        WHERE (sn.target_type = 'all'
           OR (sn.target_type = 'role' AND sn.target_role = ?)
           OR (sn.target_type = 'user' AND sn.target_user_id = ?))
          AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
        ORDER BY sn.created_at DESC
        LIMIT 30
    ");
    $stmt->bind_param("isii", $current_user_id, $current_role, $current_user_id, $current_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $notifications = [];
    $unread_count = 0;
    while ($row = $res->fetch_assoc()) {
        $is_read = (int)$row['is_read'];
        if (!$is_read) $unread_count++;
        
        $notifications[] = [
            'id'           => (int)$row['notification_id'],
            'title'        => $row['title'],
            'message'      => $row['message'],
            'target_type'  => $row['target_type'],
            'target_role'  => $row['target_role'],
            'created_at'   => $row['created_at'],
            'time_ago'     => timeAgo($row['created_at']),
            'formatted_date' => date('M j, Y · g:i A', strtotime($row['created_at'])),
            'creator_name' => trim(($row['creator_fname'] ?? '') . ' ' . ($row['creator_lname'] ?? '')) ?: 'System',
            'is_read'      => $is_read
        ];
    }
    $stmt->close();

    echo json_encode([
        'status'        => 'success',
        'unread_count'  => $unread_count,
        'notifications' => $notifications
    ]);
    exit;
}

if ($action === 'mark_read') {
    $notif_id = (int)($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO user_notification_reads (notification_id, user_id, read_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ");
        $stmt->bind_param("ii", $notif_id, $current_user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Get new unread count
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM system_notifications sn
        LEFT JOIN user_notification_reads unr 
            ON sn.notification_id = unr.notification_id 
            AND unr.user_id = ?
        WHERE (sn.target_type = 'all'
           OR (sn.target_type = 'role' AND sn.target_role = ?)
           OR (sn.target_type = 'user' AND sn.target_user_id = ?))
          AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
          AND unr.read_at IS NULL
    ");
    $stmt->bind_param("isii", $current_user_id, $current_role, $current_user_id, $current_user_id);
    $stmt->execute();
    $unread_count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    echo json_encode(['status' => 'success', 'unread_count' => $unread_count]);
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO user_notification_reads (notification_id, user_id, read_at)
        SELECT sn.notification_id, ?, NOW()
        FROM system_notifications sn
        LEFT JOIN user_notification_reads unr 
            ON sn.notification_id = unr.notification_id 
            AND unr.user_id = ?
        WHERE (sn.target_type = 'all'
           OR (sn.target_type = 'role' AND sn.target_role = ?)
           OR (sn.target_type = 'user' AND sn.target_user_id = ?))
          AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
          AND unr.read_at IS NULL
    ");
    $stmt->bind_param("iisii", $current_user_id, $current_user_id, $current_role, $current_user_id, $current_user_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success', 'unread_count' => 0]);
    exit;
}

if ($action === 'create_notification') {
    $title       = trim($_POST['title'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $target_type = trim($_POST['target_type'] ?? 'all');
    $target_role = !empty($_POST['target_role']) ? trim($_POST['target_role']) : null;
    $target_user = !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : null;

    if (empty($title) || empty($message)) {
        echo json_encode(['status' => 'error', 'message' => 'Title and message are required.']);
        exit;
    }

    if (!in_array($target_type, ['all', 'role', 'user'])) {
        $target_type = 'all';
    }

    if ($target_type === 'role' && !in_array($target_role, ['user', 'bidder', 'admin', 'superadmin'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid target role.']);
        exit;
    }

    if ($target_type === 'user' && (!$target_user || $target_user <= 0)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid target user.']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO system_notifications (title, message, target_type, target_role, target_user_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssii", $title, $message, $target_type, $target_role, $target_user, $current_user_id);
    
    if ($stmt->execute()) {
        $notif_id = $stmt->insert_id;
        $stmt->close();
        echo json_encode([
            'status' => 'success',
            'message' => 'Notification announcement published successfully!',
            'notification_id' => $notif_id
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

if ($action === 'get_users') {
    // Fetch users for target_user selector
    $res = $conn->query("
        SELECT user_id, firstname, lastname, username, role, email 
        FROM users 
        ORDER BY role ASC, firstname ASC 
        LIMIT 100
    ");
    $users = [];
    while ($u = $res->fetch_assoc()) {
        $users[] = [
            'id'       => (int)$u['user_id'],
            'name'     => htmlspecialchars($u['firstname'] . ' ' . $u['lastname']),
            'username' => htmlspecialchars($u['username']),
            'role'     => $u['role'],
            'email'    => htmlspecialchars($u['email'])
        ];
    }
    echo json_encode(['status' => 'success', 'users' => $users]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
