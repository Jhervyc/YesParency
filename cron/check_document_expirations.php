<?php
/**
 * YesParency Document Expiration Checker Cron Job
 *
 * Designed to be executed daily via Hestia CP Cron:
 *   Schedule: 0 6 * * * (Daily at 6:00 AM)
 *   Command:  php /path/to/YesParency/cron/check_document_expirations.php
 *
 * Checks for:
 *   1. 30 days before expiration -> standard reminder (doc_expiring_30)
 *   2. 7 days before expiration  -> urgent reminder (doc_expiring_7)
 *   3. On/after expiration        -> expired notification (doc_expired)
 *
 * Prevents duplicate notifications for the same expiration event.
 */

// Allow CLI execution, or web execution if a matching CRON_KEY is provided
if (php_sapi_name() !== 'cli') {
    $cronKey = $_GET['key'] ?? '';
    $expectedKey = getenv('CRON_KEY') ?: 'yesparency_cron_secure_key';
    if (empty($cronKey) || $cronKey !== $expectedKey) {
        http_response_code(403);
        exit("Access Denied: This script must be run from CLI or with a valid cron key.\n");
    }
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/bidder_document_helper.php';
require_once __DIR__ . '/../utils/mailer.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "[ERROR] Database connection is unavailable.\n");
    exit(1);
}

$startTime = microtime(true);
$nowStr    = date('Y-m-d H:i:s');

echo "=================================================================\n";
echo " YesParency Bidder Document Expiration Monitor\n";
echo " Started at: {$nowStr}\n";
echo "=================================================================\n\n";

// Fetch all bidder documents with an expiration date set
$query = "
    SELECT d.document_id, d.user_id, d.document_type, d.expiration_date,
           DATEDIFF(d.expiration_date, CURDATE()) AS days_until,
           u.email, u.firstname, u.lastname, u.role, u.status AS user_status,
           bp.business_name
    FROM bidder_documents d
    JOIN users u ON d.user_id = u.user_id
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE d.expiration_date IS NOT NULL
      AND u.role = 'bidder'
      AND u.status = 'active'
    ORDER BY d.expiration_date ASC
";

$result = $conn->query($query);
if (!$result) {
    fwrite(STDERR, "[ERROR] Failed to query documents: " . $conn->error . "\n");
    exit(1);
}

$totalEvaluated = $result->num_rows;
$notified30     = 0;
$notified7      = 0;
$notifiedExpired= 0;
$alreadyQueued  = 0;
$skipped        = 0;

echo "Found {$totalEvaluated} document(s) with active expiration dates.\n\n";

while ($doc = $result->fetch_assoc()) {
    $docId          = (int)$doc['document_id'];
    $userId         = (int)$doc['user_id'];
    $docType        = $doc['document_type'];
    $expDate        = $doc['expiration_date'];
    $daysUntil      = (int)$doc['days_until'];
    $email          = trim($doc['email']);
    $recipientName  = trim($doc['firstname'] . ' ' . $doc['lastname']);
    $businessName   = $doc['business_name'] ?: $recipientName;
    $docLabel       = REQUIRED_BIDDER_DOCS[$docType] ?? $docType;

    // ── STAGE 1: 30 DAYS BEFORE EXPIRATION ────────────────────────────────────
    if ($daysUntil <= 30 && $daysUntil > 7) {
        if (is_doc_notification_queued($conn, 'doc_expiring_30', $email, $docId, $expDate)) {
            $alreadyQueued++;
        } else {
            echo "[30-DAY REMINDER] {$businessName} ({$email}) - {$docLabel} expires in {$daysUntil} day(s) on {$expDate}\n";
            if (notify_bidder_document_expiring($conn, $docId, $daysUntil)) {
                $notified30++;
            }
        }
    }
    // ── STAGE 2: 7 DAYS BEFORE EXPIRATION (URGENT) ───────────────────────────
    elseif ($daysUntil <= 7 && $daysUntil > 0) {
        if (is_doc_notification_queued($conn, 'doc_expiring_7', $email, $docId, $expDate)) {
            $alreadyQueued++;
        } else {
            echo "[7-DAY URGENT] {$businessName} ({$email}) - {$docLabel} expires in {$daysUntil} day(s) on {$expDate}\n";
            if (notify_bidder_document_expiring($conn, $docId, $daysUntil)) {
                $notified7++;
            }
        }
    }
    // ── STAGE 3: ON OR AFTER EXPIRATION ──────────────────────────────────────
    elseif ($daysUntil <= 0) {
        if (is_doc_notification_queued($conn, 'doc_expired', $email, $docId, $expDate)) {
            $alreadyQueued++;
        } else {
            echo "[EXPIRED NOTICE] {$businessName} ({$email}) - {$docLabel} expired on {$expDate} ({$daysUntil} days ago)\n";
            if (notify_bidder_document_expired($conn, $docId)) {
                $notifiedExpired++;
            }
        }
    }
    else {
        // More than 30 days remaining; no notification needed yet
        $skipped++;
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "\n=================================================================\n";
echo " Summary Report:\n";
echo " Total Documents Checked: {$totalEvaluated}\n";
echo " 30-Day Reminders Queued: {$notified30}\n";
echo " 7-Day Urgent Reminders:  {$notified7}\n";
echo " Expired Notices Queued:  {$notifiedExpired}\n";
echo " Already Queued / Skipped:{$alreadyQueued}\n";
echo " Execution Time:          {$elapsed} ms\n";
echo "=================================================================\n";

exit(0);
