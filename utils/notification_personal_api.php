<?php
/**
 * utils/notification_personal_api.php
 *
 * Personal inbox actions shared by admin/notifications_api.php and
 * bidder/notifications_api.php. Every query is scoped to the caller's own rows.
 *
 *   fetch          GET  category, search, offset, limit, with_counts
 *   mark_read      POST notification_id
 *   mark_unread    POST notification_id
 *   mark_all_read  POST
 *   delete         POST notification_id
 */
require_once __DIR__ . '/notification_helper.php';

/** Handle $action for $userId. Returns false when the action is not a personal one. */
function notif_handle_personal_action(mysqli $conn, int $userId, string $action): bool
{
    if (in_array($action, ['mark_read', 'mark_unread', 'mark_all_read', 'delete'], true)
        && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST required.']);
        return true;
    }

    switch ($action) {
        case 'fetch':
            $category = $_GET['category'] ?? 'all';
            if (!isset(NOTIF_CATEGORIES[$category])) $category = 'all';
            $search = trim($_GET['search'] ?? '');
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $limit  = min(50, max(1, (int)($_GET['limit'] ?? 15)));

            $where  = ['n.user_id = ?'];
            $types  = 'i';
            $params = [$userId];

            if ($category === 'unread') {
                $where[] = 'n.is_read = 0';
            }
            $catTypes = notif_category_types($category);
            if ($catTypes !== null) {
                $where[] = 'n.type IN (' . implode(',', array_fill(0, count($catTypes), '?')) . ')';
                $types  .= str_repeat('s', count($catTypes));
                $params  = array_merge($params, $catTypes);
            }
            if ($search !== '') {
                $like    = '%' . $search . '%';
                $where[] = '(n.title LIKE ? OR n.message LIKE ?)';
                $types  .= 'ss';
                array_push($params, $like, $like);
            }

            // Fetch one extra row to know whether another page exists
            $stmt = $conn->prepare("
                SELECT n.*, " . NOTIF_AGE_SQL . ", a.firstname AS actor_fname, a.lastname AS actor_lname
                FROM notifications n
                LEFT JOIN users a ON n.actor_id = a.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY n.created_at DESC, n.notification_id DESC
                LIMIT ? OFFSET ?
            ");
            $types .= 'ii';
            array_push($params, $limit + 1, $offset);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) array_pop($rows);

            $out = [
                'status'        => 'success',
                'unread_count'  => notif_unread_count($conn, $userId),
                'has_more'      => $hasMore,
                'notifications' => array_map('notif_format_row', $rows),
            ];
            if (!empty($_GET['with_counts'])) {
                $out['counts'] = notif_category_counts($conn, $userId);
            }
            echo json_encode($out);
            return true;

        case 'mark_read':
        case 'mark_unread':
            $id = (int)($_POST['notification_id'] ?? 0);
            if ($id > 0) {
                $sql = $action === 'mark_read'
                    ? "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE notification_id = ? AND user_id = ?"
                    : "UPDATE notifications SET is_read = 0, read_at = NULL WHERE notification_id = ? AND user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $id, $userId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['status' => 'success', 'unread_count' => notif_unread_count($conn, $userId)]);
            return true;

        case 'mark_all_read':
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['status' => 'success', 'unread_count' => 0]);
            return true;

        case 'delete':
            $id = (int)($_POST['notification_id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $id, $userId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['status' => 'success', 'unread_count' => notif_unread_count($conn, $userId)]);
            return true;
    }
    return false;
}
