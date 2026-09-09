<?php
/**
 * YesParency Background Email Queue Worker (CLI / Cron)
 *
 * Usage:
 *   php utils/email_worker.php
 *   php utils/email_worker.php --limit=50
 *   php utils/email_worker.php --loop --interval=10
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Access Denied: This worker script must be executed from the CLI.\n");
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/mailer.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "[ERROR] Database connection unavailable.\n");
    exit(1);
}

// Parse command-line options
$options = getopt('', ['limit::', 'loop::', 'interval::']);
$limit    = isset($options['limit']) ? (int)$options['limit'] : 25;
$isLoop   = isset($options['loop']);
$interval = isset($options['interval']) ? max(2, (int)$options['interval']) : 10;

echo "======================================================\n";
echo " YesParency Email Queue Worker\n";
echo " Started at: " . date('Y-m-d H:i:s') . "\n";
echo " Batch limit: {$limit} | Mode: " . ($isLoop ? "Continuous (Interval: {$interval}s)" : "Single Batch") . "\n";
echo "======================================================\n\n";

do {
    $startTime = microtime(true);
    $result    = process_email_queue($conn, $limit);
    $elapsed   = round((microtime(true) - $startTime) * 1000, 2);

    $now = date('Y-m-d H:i:s');
    if ($result['processed'] > 0) {
        echo "[{$now}] Processed: {$result['processed']} | Sent: {$result['sent']} | Failed: {$result['failed']} ({$elapsed}ms)\n";
    } else {
        echo "[{$now}] Queue idle (0 pending items).\n";
    }

    if ($isLoop) {
        sleep($interval);
    }
} while ($isLoop);

echo "\nWorker execution complete.\n";
exit(0);
