<?php
/**
 * config/mediamtx.php — MediaMTX server configuration helper.
 *
 * Reads MEDIAMTX_* values from the environment (.env via dotenv).
 * Provides a single function to build a WebRTC playback URL from a stream path.
 *
 * IMPORTANT: This file provides SERVER config only.
 * Individual stream paths come from bid_opening_sessions.stream_path in the database.
 */

// Load dotenv if not already loaded (idempotent — safe to call multiple times)
if (!function_exists('mediamtx_url')) {

    // Ensure dotenv is loaded — bootstrap.php may not have been included yet
    if (!isset($_ENV['MEDIAMTX_HOST'])) {
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->safeLoad(); // safeLoad won't throw if .env is missing
        }
    }

    /**
     * Build the WebRTC playback URL for a given stream path.
     *
     * @param  string $streamPath  Value from bid_opening_sessions.stream_path (e.g. "live", "bid-opening-001")
     * @return string              Full URL for iframe src, e.g. "http://localhost:8889/live"
     */
    function mediamtx_url(string $streamPath): string {
        $host = $_ENV['MEDIAMTX_HOST']         ?? 'localhost';
        // $port = $_ENV['MEDIAMTX_WEBRTC_PORT']  ?? '8889';
        // Sanitize — only host/port from env, only path from DB
        $host = rtrim($host, '/');
        // $port = (int)$port ?: 8889;
        $path = ltrim($streamPath, '/');
        // return "http://{$host}:{$port}/{$path}";
        return "http://{$host}/{$path}";
    }

    /**
     * Default stream path from env (used as placeholder in create-event form).
     */
    function mediamtx_default_path(): string {
        return $_ENV['MEDIAMTX_DEFAULT_PATH'] ?? 'live';
    }
}
