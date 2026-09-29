<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/notification_personal_api.php");

header('Content-Type: application/json');
$current_user_id = (int)$_SESSION['user_id'];

$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';

// ── Personal inbox actions (every admin) ──────────────────────────────────────
if (notif_handle_personal_action($conn, $current_user_id, $action)) {
    exit;
}

// ── Notification Management actions (Secretariat / superadmin only) ───────────
if (!isset($_SESSION['admin_type'])) {
    $rs = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $rs->bind_param("i", $current_user_id);
    $rs->execute();
    $_SESSION['admin_type'] = $rs->get_result()->fetch_assoc()['admin_type'] ?? 'SECRETARIAT';
    $rs->close();
}
if (in_array($_SESSION['admin_type'], ['BAC', 'TWG'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You are not allowed to manage notifications.']);
    exit;
}

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

/** Read the audience fields posted by the composer. */
function nm_read_audience(): array
{
    $type    = $_POST['audience_type'] ?? 'all';
    $roles   = array_map('strval', (array)($_POST['roles'] ?? []));
    $userIds = array_map('intval', (array)($_POST['user_ids'] ?? []));
    return [$type, $roles, $userIds];
}

switch ($action) {

    case 'stats':
        echo json_encode(['status' => 'success', 'stats' => notif_admin_stats($conn)]);
        exit;

    case 'search_users':
        $q    = trim($_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT u.user_id, u.firstname, u.lastname, u.username, u.email, u.role, bp.business_name
            FROM users u
            LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
            WHERE u.status = 'active'
              AND (CONCAT(u.firstname, ' ', u.lastname) LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR bp.business_name LIKE ?)
            ORDER BY u.firstname, u.lastname
            LIMIT 12
        ");
        $stmt->bind_param("ssss", $like, $like, $like, $like);
        $stmt->execute();
        $users = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
            $users[] = [
                'id'       => (int)$u['user_id'],
                'name'     => trim($u['firstname'] . ' ' . $u['lastname']),
                'username' => $u['username'],
                'email'    => $u['email'],
                'role'     => $u['role'],
                'business' => $u['business_name'],
            ];
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'users' => $users]);
        exit;

    case 'count_audience':
        [$type, $roles, $userIds] = nm_read_audience();
        echo json_encode([
            'status' => 'success',
            'count'  => notif_count_audience($conn, $type, $roles, $userIds, $current_user_id),
        ]);
        exit;

    case 'send':
        if (!$is_post) break;
        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $link    = notif_clean_link($_POST['link'] ?? '');
        [$type, $roles, $userIds] = nm_read_audience();

        if ($title === '' || $message === '') {
            echo json_encode(['status' => 'error', 'message' => 'Title and message are required.']);
            exit;
        }
        if (mb_strlen($title) > 120 || mb_strlen($message) > 1000) {
            echo json_encode(['status' => 'error', 'message' => 'Title is limited to 120 and message to 1000 characters.']);
            exit;
        }
        if ($link === false) {
            echo json_encode(['status' => 'error', 'message' => 'Link must be a page path such as bidder/procurement.php.']);
            exit;
        }

        $result = send_admin_notification($conn, $title, $message, $type, $roles, $userIds, $link ?: null, $current_user_id);
        if ($result['recipients'] === 0) {
            echo json_encode(['status' => 'error', 'message' => 'No active users match the selected audience.']);
            exit;
        }

        audit_log($conn, 'NOTIFICATION_SENT', 'notifications', null,
            "Sent notification \"{$title}\" to {$result['audience']} ({$result['recipients']} recipients)",
            null,
            [
                'batch_id'      => $result['batch_id'],
                'title'         => $title,
                'message'       => $message,
                'link'          => $link ?: null,
                'audience_type' => $type,
                'roles'         => $roles,
                'user_ids'      => $userIds,
                'recipients'    => $result['recipients'],
            ]
        );

        echo json_encode([
            'status'     => 'success',
            'message'    => "Notification sent to {$result['recipients']} " . ($result['recipients'] === 1 ? 'user' : 'users') . '.',
            'batch_id'   => $result['batch_id'],
            'recipients' => $result['recipients'],
            'stats'      => notif_admin_stats($conn),
        ]);
        exit;

    case 'list_batches':
        $search = trim($_GET['search'] ?? '');
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit  = 10;

        $where  = "n.type = 'admin_message' AND n.batch_id IS NOT NULL";
        $types  = '';
        $params = [];
        if ($search !== '') {
            $like   = '%' . $search . '%';
            $where .= " AND (n.title LIKE ? OR n.message LIKE ? OR n.audience LIKE ?)";
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $types .= 'ii';
        array_push($params, $limit + 1, $offset);

        $stmt = $conn->prepare("
            SELECT b.*, TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS age_sec,
                   a.firstname AS actor_fname, a.lastname AS actor_lname
            FROM (
                SELECT n.batch_id,
                       MIN(n.title) AS title, MIN(n.message) AS message, MIN(n.link) AS link,
                       MIN(n.audience) AS audience, MIN(n.actor_id) AS actor_id,
                       MIN(n.created_at) AS created_at,
                       COUNT(*) AS total, SUM(n.is_read) AS read_count
                FROM notifications n
                WHERE $where
                GROUP BY n.batch_id
            ) b
            LEFT JOIN users a ON a.user_id = b.actor_id
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $batches = array_map(function ($r) {
            $sender = trim(($r['actor_fname'] ?? '') . ' ' . ($r['actor_lname'] ?? ''));
            return [
                'batch_id'       => $r['batch_id'],
                'title'          => $r['title'],
                'message'        => $r['message'],
                'link'           => $r['link'],
                'audience'       => $r['audience'] ?: '—',
                'sender'         => $sender !== '' ? $sender : 'Former admin',
                'total'          => (int)$r['total'],
                'read_count'     => (int)$r['read_count'],
                'time_ago'       => notif_time_ago($r['created_at'], (int)$r['age_sec']),
                'formatted_date' => date('M j, Y · g:i A', strtotime($r['created_at'])),
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'batches' => $batches, 'has_more' => $has_more]);
        exit;

    case 'batch_recipients':
        $batch_id = preg_replace('/[^a-f0-9]/', '', $_GET['batch_id'] ?? '');
        $stmt = $conn->prepare("
            SELECT u.user_id, u.firstname, u.lastname, u.username, u.role, n.is_read, n.read_at
            FROM notifications n
            JOIN users u ON u.user_id = n.user_id
            WHERE n.batch_id = ? AND n.type = 'admin_message'
            ORDER BY n.is_read DESC, n.read_at DESC, u.firstname
        ");
        $stmt->bind_param("s", $batch_id);
        $stmt->execute();
        $recipients = array_map(fn($r) => [
            'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
            'username' => $r['username'],
            'role'     => $r['role'],
            'is_read'  => (int)$r['is_read'] === 1,
            'read_at'  => $r['read_at'] ? date('M j, Y · g:i A', strtotime($r['read_at'])) : null,
        ], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        echo json_encode(['status' => 'success', 'recipients' => $recipients]);
        exit;

    case 'recall_batch':
        if (!$is_post) break;
        $batch_id = preg_replace('/[^a-f0-9]/', '', $_POST['batch_id'] ?? '');
        $snap = $conn->prepare("
            SELECT MIN(title) AS title, MIN(message) AS message, MIN(audience) AS audience, COUNT(*) AS total
            FROM notifications WHERE batch_id = ? AND type = 'admin_message'
        ");
        $snap->bind_param("s", $batch_id);
        $snap->execute();
        $old = $snap->get_result()->fetch_assoc();
        $snap->close();

        if (!$old || (int)$old['total'] === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Notification not found or already recalled.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM notifications WHERE batch_id = ? AND type = 'admin_message'");
        $del->bind_param("s", $batch_id);
        $del->execute();
        $removed = $del->affected_rows;
        $del->close();

        audit_log($conn, 'NOTIFICATION_RECALLED', 'notifications', null,
            "Recalled notification \"{$old['title']}\" from {$removed} recipients",
            array_merge($old, ['batch_id' => $batch_id]),
            null
        );

        echo json_encode(['status' => 'success', 'message' => 'Notification recalled.', 'stats' => notif_admin_stats($conn)]);
        exit;

    case 'list_system':
        $category = $_GET['category'] ?? 'all';
        $search   = trim($_GET['search'] ?? '');
        $offset   = max(0, (int)($_GET['offset'] ?? 0));
        $limit    = 15;

        $where  = ["n.type <> 'admin_message'"];
        $types  = '';
        $params = [];
        $catTypes = isset(NOTIF_CATEGORIES[$category]) ? notif_category_types($category) : null;
        if ($catTypes !== null) {
            $where[] = 'n.type IN (' . implode(',', array_fill(0, count($catTypes), '?')) . ')';
            $types  .= str_repeat('s', count($catTypes));
            $params  = array_merge($params, $catTypes);
        }
        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where[] = "(n.title LIKE ? OR n.message LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
            $types  .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $types .= 'ii';
        array_push($params, $limit + 1, $offset);

        $stmt = $conn->prepare("
            SELECT n.*, " . NOTIF_AGE_SQL . ", u.firstname AS r_fname, u.lastname AS r_lname, u.role AS r_role,
                   a.firstname AS actor_fname, a.lastname AS actor_lname
            FROM notifications n
            JOIN users u ON u.user_id = n.user_id
            LEFT JOIN users a ON a.user_id = n.actor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY n.created_at DESC, n.notification_id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $items = array_map(function ($r) {
            $f = notif_format_row($r);
            $f['recipient']      = trim($r['r_fname'] . ' ' . $r['r_lname']);
            $f['recipient_role'] = $r['r_role'];
            return $f;
        }, $rows);

        echo json_encode(['status' => 'success', 'items' => $items, 'has_more' => $has_more]);
        exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
