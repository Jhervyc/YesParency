<?php
/**
 * debug/live_debug.php — Diagnose live stream / bid session detection issues.
 * Remove or restrict this file in production.
 */
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/mediamtx.php';
session_start();

header('Content-Type: text/plain');

echo "=== YesParency Live Debug ===\n\n";

// 0. MediaMTX env config
echo "=== MediaMTX Config (from .env) ===\n";
echo "MEDIAMTX_HOST: "         . ($_ENV['MEDIAMTX_HOST']         ?? '(not set — default: localhost)') . "\n";
echo "MEDIAMTX_WEBRTC_PORT: "  . ($_ENV['MEDIAMTX_WEBRTC_PORT']  ?? '(not set — default: 8889)') . "\n";
echo "MEDIAMTX_RTMP_PORT: "    . ($_ENV['MEDIAMTX_RTMP_PORT']    ?? '(not set — default: 1935)') . "\n";
echo "MEDIAMTX_DEFAULT_PATH: " . ($_ENV['MEDIAMTX_DEFAULT_PATH'] ?? '(not set — default: live)') . "\n";
echo "Computed stream URL: "   . mediamtx_url('live') . "\n\n";

// 1. DB connection
echo "DB connected: " . ($conn ? "YES" : "NO") . "\n";
if ($conn) echo "DB error: " . ($conn->error ?: 'none') . "\n";

// 2. Table checks
$r_bos = $conn->query("SHOW TABLES LIKE 'bid_opening_sessions'");
echo "\nbid_opening_sessions table exists: " . ($r_bos && $r_bos->num_rows > 0 ? "YES" : "NO") . "\n";

$r_lc = $conn->query("SHOW TABLES LIKE 'live_comments'");
echo "live_comments table exists: " . ($r_lc && $r_lc->num_rows > 0 ? "YES" : "NO") . "\n";

$r_inv = $conn->query("SHOW TABLES LIKE 'bid_session_invited'");
echo "bid_session_invited table exists: " . ($r_inv && $r_inv->num_rows > 0 ? "YES" : "NO") . "\n";

// 3. Recent sessions
if ($r_bos && $r_bos->num_rows > 0) {
    $sessions = $conn->query("
        SELECT id, procurement_id, stream_path, status, started_at, created_at
        FROM bid_opening_sessions
        ORDER BY id DESC LIMIT 5
    ");
    echo "\n--- bid_opening_sessions (last 5) ---\n";
    if ($sessions->num_rows === 0) {
        echo "(no rows)\n";
    } else {
        while ($s = $sessions->fetch_assoc()) {
            echo "id={$s['id']} proc_id={$s['procurement_id']} path={$s['stream_path']} status={$s['status']} started={$s['started_at']} created={$s['created_at']}\n";
        }
    }

    // 4. The exact query live.php uses
    echo "\n--- Active session detection query result ---\n";
    $stmt = $conn->prepare("
        SELECT bos.id, bos.status, bos.stream_path, p.title
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.status IN ('eligibility','financial','awarding','scheduled')
        ORDER BY
            CASE bos.status
                WHEN 'eligibility' THEN 1
                WHEN 'financial'   THEN 2
                WHEN 'awarding'    THEN 3
                WHEN 'scheduled'   THEN 4
            END ASC,
            bos.started_at DESC
        LIMIT 1
    ");
    if (!$stmt) {
        echo "Prepare failed: " . $conn->error . "\n";
    } else {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            echo "Found session: id={$row['id']} status={$row['status']} path={$row['stream_path']} title={$row['title']}\n";
        } else {
            echo "No active/scheduled session found.\n";
        }
        $stmt->close();
    }
}

// 5. Session
echo "\n--- Session ---\n";
echo "user_id: " . ($_SESSION['user_id'] ?? 'not set') . "\n";
echo "role: "    . ($_SESSION['role']    ?? 'not set') . "\n";

echo "\n=== Done ===\n";
