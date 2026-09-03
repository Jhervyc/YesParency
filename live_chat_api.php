<?php
/**
 * live_chat_api.php — Polling endpoint for live chat messages.
 * Returns JSON. No HTML output.
 *
 * GET  ?action=fetch&event_id=N&after=M  — returns comments with id > M
 * POST action=moderate (admin only)       — hide/show a comment
 */

require_once __DIR__ . '/config/db_connect.php';
session_start();

header('Content-Type: application/json');

$viewer_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$viewer_role = $_SESSION['role'] ?? 'guest';

// ── Fetch new comments ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    $after      = (int)($_GET['after']      ?? 0);

    if ($session_id <= 0) {
        echo json_encode(['comments' => []]);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT lc.id, lc.comment, lc.created_at,
               u.username, u.firstname, u.lastname, u.profile_picture_url
        FROM live_comments lc
        JOIN users u ON lc.user_id = u.user_id
        WHERE lc.bid_session_id = ? AND lc.id > ? AND lc.status = 'visible'
        ORDER BY lc.created_at ASC
        LIMIT 30
    ");
    $stmt->bind_param("ii", $session_id, $after);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Add time_ago for display
    foreach ($rows as &$r) {
        $diff = time() - strtotime($r['created_at']);
        if ($diff < 60)    $r['time_ago'] = 'Just now';
        elseif ($diff < 3600) $r['time_ago'] = floor($diff/60) . 'm ago';
        elseif ($diff < 86400) $r['time_ago'] = floor($diff/3600) . 'h ago';
        else $r['time_ago'] = date('M j', strtotime($r['created_at']));
    }
    unset($r);

    echo json_encode(['comments' => $rows]);
    exit();
}

// ── Moderate comment (admin/superadmin only) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'moderate') {
    if (!in_array($viewer_role, ['admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }

    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $new_status = ($_POST['status'] ?? '') === 'hidden' ? 'hidden' : 'visible';

    if ($comment_id <= 0) {
        echo json_encode(['error' => 'Invalid comment ID']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE live_comments SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $comment_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'ok', 'new_status' => $new_status]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
