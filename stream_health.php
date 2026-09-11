<?php
/**
 * stream_health.php — server-side HLS stream availability check.
 *
 * Public, unauthenticated (same tier as live.php) so both the public viewer
 * and admin pages can gate their <iframe> embeds behind a real check instead
 * of pointing straight at the MediaMTX host:port and hoping it's up.
 *
 * Usage: GET stream_health.php?path=<stream_path>
 * Response: {"online": true|false}
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/mediamtx.php';

$path = $_GET['path'] ?? '';

// Only allow the same characters bid_opening_sessions.stream_path can contain —
// prevents building an arbitrary check URL from user input.
if (!is_string($path) || $path === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $path)) {
    echo json_encode(['online' => false, 'message' => 'Invalid stream path.']);
    exit;
}

echo json_encode(['online' => stream_health_check(mediamtx_url($path))]);
exit;

/**
 * GET-check a URL with a short timeout. Never throws — any failure/timeout
 * is reported as offline.
 *
 * NOTE: MediaMTX's HLS manifest endpoint does a session-cookie handshake
 * (first request 302s to itself with ?cookieCheck=1 and sets a cookie, the
 * follow-up request returns 200 + the manifest). It only does this dance for
 * GET — a bare HEAD gets a flat 404 even while the stream is live. So this
 * must use GET (PHP's http wrapper follows the redirect and carries the
 * Set-Cookie automatically); HEAD would report every live stream as offline.
 */
function stream_health_check(string $url, int $timeoutSec = 3): bool
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);

    $headers = @get_headers($url, true, $context);
    if ($headers === false) {
        return false;
    }

    // get_headers() with follow_location adds one numeric key per response in
    // the redirect chain (0 => first status line, 1 => second, ...) — take
    // the last one, which is the final response after following redirects.
    $statusLine = '';
    foreach ($headers as $key => $value) {
        if (is_int($key) && is_string($value) && str_starts_with($value, 'HTTP/')) {
            $statusLine = $value;
        }
    }
    return (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine);
}
