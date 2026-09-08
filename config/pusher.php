<?php
/**
 * config/pusher.php
 * Provides pusher_trigger() — call this after any state-changing DB write
 * to broadcast the event to all connected clients in real time.
 *
 * Channel convention:  session-{session_id}
 * Event convention:    snake_case verb, e.g. 'session_started', 'files_opened'
 *
 * Usage:
 *   require_once __DIR__ . '/pusher.php';
 *   pusher_trigger($session_id, 'files_opened', ['bid_lot_id' => $bid_lot_id]);
 */

// ── Bootstrap dotenv if not already loaded (mirrors config/mediamtx.php) ──
if (empty($_ENV['PUSHER_APP_KEY'])) {
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
}

/**
 * Trigger a Pusher event on the session channel.
 *
 * @param int    $session_id  bid_opening_sessions.id
 * @param string $event       Event name, e.g. 'files_opened'
 * @param array  $payload     Extra data sent to the client (keep it small)
 */
function pusher_trigger(int $session_id, string $event, array $payload = []): void
{
    if ($session_id <= 0) return;

    try {
        $pusher = new Pusher\Pusher(
            $_ENV['PUSHER_APP_KEY']     ?? '',
            $_ENV['PUSHER_APP_SECRET']  ?? '',
            $_ENV['PUSHER_APP_ID']      ?? '',
            [
                'cluster' => $_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3',
                'useTLS'  => true,
            ]
        );

        // Always include session_id so the client can double-check channel ownership
        $payload['session_id'] = $session_id;

        $pusher->trigger('session-' . $session_id, $event, $payload);

    } catch (\Throwable $e) {
        // Never let a Pusher failure break the API response.
        // Errors are silently swallowed — the client's 30-second fallback
        // poll will catch up if a push is missed.
        error_log('Pusher trigger failed [' . $event . ']: ' . $e->getMessage());
    }
}
