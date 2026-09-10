<?php
/**
 * YesParency Email Queue Processor Cron Job
 *
 * Designed to run every minute via Hestia CP Cron:
 *   Schedule: * * * * *
 *   Command:  php /home/USERNAME/web/DOMAIN/public_html/cron/process_email_queue.php
 *
 * What it does:
 *   Picks up pending (and previously failed) emails from the email_queue table
 *   and sends them via SMTP using PHPMailer. Exits immediately with a short
 *   summary if the queue is empty — no wasted overhead.
 *
 * Optional: pass --limit=N on the command line to override the default batch
 *   size of 25 emails per run.
 *   php cron/process_email_queue.php --limit=50
 */

// ─────────────────────────────────────────────────────────────────────────────
// SECURITY GATE — CLI only, or web request with valid CRON_KEY
// ─────────────────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $cronKey     = $_GET['key'] ?? '';
    $expectedKey = getenv('CRON_KEY') ?: 'yesparency_cron_secure_key';
    if (empty($cronKey) || $cronKey !== $expectedKey) {
        http_response_code(403);
        exit("Access Denied: This script must be run from CLI or with a valid cron key.\n");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BOOTSTRAP
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/mailer.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "[ERROR] Database connection unavailable.\n");
    exit(1);
}

// Parse optional --limit argument
$options = getopt('', ['limit::']);
$limit   = isset($options['limit']) ? max(1, min(100, (int)$options['limit'])) : 25;

$startTime = microtime(true);
$nowStr    = date('Y-m-d H:i:s');

echo "=================================================================\n";
echo " YesParency Email Queue Processor\n";
echo " Started at: {$nowStr} | Batch limit: {$limit}\n";
echo "=================================================================\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PROCESS QUEUE
// ─────────────────────────────────────────────────────────────────────────────
$result  = process_email_queue($conn, $limit);
$elapsed = round((microtime(true) - $startTime) * 1000, 2);

if ($result['processed'] === 0) {
    echo "Queue is empty — nothing to send.\n";
} else {
    echo "Processed : {$result['processed']}\n";
    echo "Sent      : {$result['sent']}\n";
    echo "Failed    : {$result['failed']}\n";
}

echo "\n=================================================================\n";
echo " Execution Time: {$elapsed} ms\n";
echo "=================================================================\n";

exit($result['failed'] > 0 ? 1 : 0);
