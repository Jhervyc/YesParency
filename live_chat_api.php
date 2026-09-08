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

// ── Send a new comment (JSON API for WebSocket-integrated chat) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!$viewer_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Login required.']);
        exit();
    }
    if ($viewer_role !== 'bidder') {
        http_response_code(403);
        echo json_encode(['error' => 'Only registered bidders may post comments.']);
        exit();
    }

    $session_id = (int)($_POST['session_id'] ?? 0);
    $raw        = trim($_POST['comment'] ?? '');

    if ($session_id <= 0) {
        echo json_encode(['error' => 'Invalid session.']); exit();
    }
    if ($raw === '') {
        echo json_encode(['error' => 'Message cannot be empty.']); exit();
    }
    if (mb_strlen($raw) > 500) {
        echo json_encode(['error' => 'Message must be 500 characters or fewer.']); exit();
    }

    // Verify session exists and is live
    $sc = $conn->prepare("SELECT id, status FROM bid_opening_sessions WHERE id = ? LIMIT 1");
    $sc->bind_param("i", $session_id);
    $sc->execute();
    $sess = $sc->get_result()->fetch_assoc();
    $sc->close();
    if (!$sess || !in_array($sess['status'], ['eligibility','financial','awarding','started'])) {
        echo json_encode(['error' => 'Chat is only open during a live session.']); exit();
    }

    $clean = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    $ins   = $conn->prepare("INSERT INTO live_comments (bid_session_id, user_id, comment) VALUES (?, ?, ?)");
    $ins->bind_param("iis", $session_id, $viewer_id, $clean);
    $ins->execute();
    $new_id = $ins->insert_id;
    $ins->close();

    // Return the inserted comment so the sender can display it immediately
    $row = $conn->prepare("
        SELECT lc.id, lc.comment, lc.created_at,
               u.username, u.firstname, u.lastname, u.profile_picture_url
        FROM live_comments lc
        JOIN users u ON lc.user_id = u.user_id
        WHERE lc.id = ?
    ");
    $row->bind_param("i", $new_id);
    $row->execute();
    $new_comment = $row->get_result()->fetch_assoc();
    $row->close();
    $new_comment['time_ago'] = 'Just now';

    // Fire Pusher event so all viewers see the new message instantly
    require_once __DIR__ . '/config/pusher.php';
    pusher_trigger($session_id, 'chat_message', [
        'id'                  => $new_comment['id'],
        'comment'             => $new_comment['comment'],
        'username'            => $new_comment['username'],
        'firstname'           => $new_comment['firstname'],
        'lastname'            => $new_comment['lastname'],
        'profile_picture_url' => $new_comment['profile_picture_url'],
        'time_ago'            => 'Just now',
        'created_at'          => $new_comment['created_at'],
    ]);

    echo json_encode(['success' => true, 'comment' => $new_comment]);
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

// ── Session status (for live.php heartbeat + Pusher event handler) ────────
// GET ?action=session_status&session_id=N
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'session_status') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    if ($session_id <= 0) { echo json_encode(['status' => 'unknown']); exit(); }

    $stmt = $conn->prepare("
        SELECT bos.status, bos.current_lot_id, bos.signing_status,
               l.lot_number, l.lot_title
        FROM bid_opening_sessions bos
        LEFT JOIN lots l ON l.id = bos.current_lot_id
        WHERE bos.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['status' => 'unknown']); exit(); }
    echo json_encode($row);
    exit();
}

// ── Lots list for a procurement (for live.php lot panel updates) ──────────
// GET ?action=lots&procurement_id=N&session_id=N
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'lots') {
    $procurement_id = (int)($_GET['procurement_id'] ?? 0);
    $session_id     = (int)($_GET['session_id']     ?? 0);

    if ($procurement_id <= 0) { echo json_encode(['lots' => []]); exit(); }

    // Get current_lot_id for the session so we know which lot is active
    $current_lot_id = 0;
    if ($session_id > 0) {
        $ss = $conn->prepare("SELECT current_lot_id FROM bid_opening_sessions WHERE id = ? LIMIT 1");
        $ss->bind_param("i", $session_id);
        $ss->execute();
        $ss_row = $ss->get_result()->fetch_assoc();
        $ss->close();
        $current_lot_id = (int)($ss_row['current_lot_id'] ?? 0);
    }

    $stmt = $conn->prepare("
        SELECT l.id, l.lot_number, l.lot_title, l.abc, l.status,
               COUNT(bl.id) AS bid_count
        FROM lots l
        LEFT JOIN bid_lots bl ON bl.lot_id = l.id
        WHERE l.procurement_id = ?
        GROUP BY l.id
        ORDER BY l.lot_number ASC
    ");
    $stmt->bind_param("i", $procurement_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($lots as &$lot) {
        $lot['is_current'] = ($current_lot_id > 0 && (int)$lot['id'] === $current_lot_id);
    }
    unset($lot);

    echo json_encode(['lots' => $lots, 'current_lot_id' => $current_lot_id]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
