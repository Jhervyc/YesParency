<?php
/**
 * live-test-chat-api.php — Polling + send endpoint for the standalone
 * live-stream test page (live-test.php).
 *
 * Deliberately NOT wired to bid_opening_sessions / live_comments / user
 * accounts — this is a throwaway harness for testing the MediaMTX stream +
 * Pusher chat pipeline without needing a real procurement, session, or
 * bidder login. Messages live in their own table (live_test_messages) and
 * are broadcast on a fixed Pusher channel ('live-test').
 *
 * GET  ?action=fetch&after=N   -> {"messages":[...]}
 * POST action=send, name, message -> {"success":true,"message":{...}}
 * POST action=clear            -> wipes the test table
 *
 * Remove this file (and live-test.php) once testing is done.
 */

require_once __DIR__ . '/config/db_connect.php';

header('Content-Type: application/json');

// ── Ensure the test table exists (self-contained, no migration needed) ────
$conn->query("
    CREATE TABLE IF NOT EXISTS live_test_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        message VARCHAR(500) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function time_ago_str(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M j', strtotime($dt));
}

// ── Fetch messages newer than `after` ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch') {
    $after = (int)($_GET['after'] ?? 0);

    $stmt = $conn->prepare("
        SELECT id, name, message, created_at
        FROM live_test_messages
        WHERE id > ?
        ORDER BY id ASC
        LIMIT 50
    ");
    $stmt->bind_param("i", $after);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['time_ago'] = time_ago_str($r['created_at']);
    }
    unset($r);

    echo json_encode(['messages' => $rows]);
    exit();
}

// ── Send a message ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $name    = trim($_POST['name']    ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '')    $name = 'Anonymous';
    if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);

    if ($message === '') {
        echo json_encode(['error' => 'Message cannot be empty.']); exit();
    }
    if (mb_strlen($message) > 500) {
        echo json_encode(['error' => 'Message must be 500 characters or fewer.']); exit();
    }

    $cleanName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $cleanMsg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    $ins = $conn->prepare("INSERT INTO live_test_messages (name, message) VALUES (?, ?)");
    $ins->bind_param("ss", $cleanName, $cleanMsg);
    $ins->execute();
    $new_id = $ins->insert_id;
    $ins->close();

    $row = $conn->prepare("SELECT id, name, message, created_at FROM live_test_messages WHERE id = ?");
    $row->bind_param("i", $new_id);
    $row->execute();
    $new_message = $row->get_result()->fetch_assoc();
    $row->close();
    $new_message['time_ago'] = 'Just now';

    // Broadcast via Pusher (best-effort — chat still works via polling if this fails)
    require_once __DIR__ . '/config/pusher.php';
    try {
        $pusher = new Pusher\Pusher(
            $_ENV['PUSHER_APP_KEY']    ?? '',
            $_ENV['PUSHER_APP_SECRET'] ?? '',
            $_ENV['PUSHER_APP_ID']     ?? '',
            ['cluster' => $_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3', 'useTLS' => true]
        );
        $pusher->trigger('live-test', 'chat_message', $new_message);
    } catch (\Throwable $e) {
        error_log('live-test Pusher trigger failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => $new_message]);
    exit();
}

// ── Clear all test messages ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $conn->query("TRUNCATE TABLE live_test_messages");
    echo json_encode(['success' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
