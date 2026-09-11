<?php
/**
 * config/mediamtx.php — MediaMTX server configuration helper.
 *
 * Priority order for each setting:
 *   1. system_settings table in the DB  (superadmin can change via Live tab)
 *   2. .env fallback
 *   3. Hard-coded default
 *
 * In-memory cache (static vars) means the DB is hit at most ONCE per PHP
 * request, no matter how many times mediamtx_url() is called.
 *
 * Cache invalidation:
 *   When the admin saves new config, the save handler touches a sentinel file
 *   (storage/mediamtx_cache.bust).  The next request that sees a newer mtime
 *   on that file flushes the static cache and re-reads from the DB.
 */

// ── Bootstrap dotenv once ────────────────────────────────────────────────────
if (empty($_ENV['MEDIAMTX_HOST'])) {
    $__envFile = dirname(__DIR__) . '/.env';
    if (file_exists($__envFile)) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $__dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $__dotenv->safeLoad();
    }
    unset($__envFile, $__dotenv);
}

// ── Sentinel file path ───────────────────────────────────────────────────────
define('MEDIAMTX_BUST_FILE', dirname(__DIR__) . '/storage/mediamtx_cache.bust');

/**
 * Internal: load all mediamtx_* keys from system_settings, with in-memory cache.
 * Returns an associative array keyed by setting_key.
 */
function _mediamtx_load_config(): array
{
    static $cache     = null;   // the config array
    static $bustMtime = 0;      // mtime of the sentinel file when last loaded

    // Check if the sentinel file has been touched since we last loaded
    $currentMtime = file_exists(MEDIAMTX_BUST_FILE) ? (int)filemtime(MEDIAMTX_BUST_FILE) : 0;

    if ($cache !== null && $currentMtime === $bustMtime) {
        return $cache; // still fresh — return in-memory copy
    }

    // Re-read from DB
    $defaults = [
        'mediamtx_host'         => $_ENV['MEDIAMTX_HOST']         ?? 'localhost',
        'mediamtx_hls_port'     => $_ENV['MEDIAMTX_HLS_PORT']     ?? '8888',
        'mediamtx_rtmp_port'    => $_ENV['MEDIAMTX_RTMP_PORT']    ?? '1935',
        'mediamtx_default_path' => $_ENV['MEDIAMTX_DEFAULT_PATH'] ?? 'live',
        'mediamtx_manifest'     => $_ENV['MEDIAMTX_MANIFEST']     ?? 'index.m3u8',
    ];

    $loaded = [];

    // $conn is the global mysqli connection (set by config/db_connect.php)
    global $conn;
    if ($conn instanceof mysqli) {
        $keys         = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types        = str_repeat('s', count($keys));

        $stmt = $conn->prepare(
            "SELECT setting_key, setting_value
             FROM system_settings
             WHERE setting_key IN ($placeholders)"
        );
        if ($stmt) {
            $stmt->bind_param($types, ...$keys);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $row) {
                $loaded[$row['setting_key']] = $row['setting_value'];
            }
        }
    }

    // Merge: DB values win over .env defaults
    $cache     = array_merge($defaults, $loaded);
    $bustMtime = $currentMtime;

    return $cache;
}

/**
 * Build the HLS playback URL for a given stream path.
 *
 * @param  string $streamPath  Value from bid_opening_sessions.stream_path (e.g. "live", "bid-opening-001")
 * @return string              Full URL for iframe src, e.g. "http://stream.example.com:8888/live/index.m3u8"
 */
function mediamtx_url(string $streamPath): string
{
    $cfg      = _mediamtx_load_config();
    $host     = rtrim($cfg['mediamtx_host'], '/');
    $port     = (int)($cfg['mediamtx_hls_port'] ?: 8888);
    $path     = ltrim($streamPath, '/');
    $manifest = ltrim($cfg['mediamtx_manifest'] ?? 'index.m3u8', '/');
    return "http://{$host}:{$port}/{$path}/{$manifest}";
}

/**
 * Default stream path (used as placeholder in create-event form).
 */
function mediamtx_default_path(): string
{
    return _mediamtx_load_config()['mediamtx_default_path'] ?? 'live';
}

/**
 * Full config array — use when you need all values at once (e.g. admin UI).
 */
function mediamtx_config(): array
{
    return _mediamtx_load_config();
}

/**
 * Bust the cache sentinel file.
 * Call this after saving new config to system_settings so that the
 * next PHP request re-reads from the DB immediately.
 */
function mediamtx_bust_cache(): void
{
    $dir = dirname(MEDIAMTX_BUST_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents(MEDIAMTX_BUST_FILE, (string)time());
}
