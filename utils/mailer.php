<?php
/**
 * Centralized Email Notification Service for YesParency
 *
 * Handles PHPMailer configuration, queueing, sending, and specific-recipient notifications.
 * Strictly adheres to specific-user notifications (no broadcast emails).
 */

require_once __DIR__ . '/../bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Read SMTP and application configuration from environment
 */
function mailer_get_config(): array
{
    return [
        'host'         => $_ENV['MAIL_HOST'] ?? 'localhost',
        'port'         => (int)($_ENV['MAIL_PORT'] ?? 587),
        'username'     => $_ENV['MAIL_USERNAME'] ?? '',
        'password'     => $_ENV['MAIL_PASSWORD'] ?? '',
        'encryption'   => strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls'),
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@yesparency.site',
        'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'YesParency',
        'app_name'     => $_ENV['APP_NAME'] ?? 'YesParency',
        'app_url'      => mailer_get_app_url(),
    ];
}

/**
 * Dynamically resolve the application base URL
 */
function mailer_get_app_url(): string
{
    if (!empty($_ENV['APP_URL'])) {
        return rtrim($_ENV['APP_URL'], '/');
    }

    if (isset($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/YesParency';
    }

    return 'http://localhost/YesParency';
}

/**
 * Factory for creating configured PHPMailer instances
 */
function mailer_create_instance(?array $config = null): PHPMailer
{
    $config = $config ?? mailer_get_config();

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['host'];
    $mail->SMTPAuth   = !empty($config['username']);
    $mail->Username   = $config['username'];
    $mail->Password   = $config['password'];
    $mail->Port       = $config['port'];
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 10; // 10 seconds max timeout

    if ($config['encryption'] === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($config['encryption'] === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($config['from_address'], $config['from_name']);
    $mail->isHTML(true);

    return $mail;
}

/**
 * Render an email template inside the master layout
 */
function mailer_render_template(string $templateName, array $data): array
{
    $config   = mailer_get_config();
    $app_name = $config['app_name'];
    $app_url  = $config['app_url'];
    $subject  = $data['subject'] ?? 'YesParency Notification';

    $templateFile = __DIR__ . '/email_templates/' . $templateName . '.php';
    if (!file_exists($templateFile)) {
        throw new \InvalidArgumentException("Email template '{$templateName}' not found.");
    }

    $layoutFile = __DIR__ . '/email_templates/layout.php';
    if (!file_exists($layoutFile)) {
        throw new \InvalidArgumentException("Email layout template not found.");
    }

    // 1. Render child template
    extract($data, EXTR_SKIP);
    ob_start();
    include $templateFile;
    $content = ob_get_clean();

    // 2. Render layout wrapping content
    ob_start();
    include $layoutFile;
    $html = ob_get_clean();

    // 3. Generate plain text fallback
    $plainText = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $content));
    $plainText = preg_replace("/\n\s+\n/", "\n\n", trim($plainText));

    return [
        'html' => $html,
        'text' => $plainText
    ];
}

/**
 * Queue an email in email_queue table
 *
 * @return int|null Queue record ID on success, null on failure
 */
function queue_email(
    mysqli $conn,
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $template,
    array $payload = [],
    ?string $scheduledAt = null
): ?int {
    $recipientEmail = trim($recipientEmail);
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("[YesParency Mailer] Invalid recipient email: " . $recipientEmail);
        return null;
    }

    try {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $scheduledAt = $scheduledAt ?: date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO email_queue (
                recipient_email,
                recipient_name,
                subject,
                template,
                payload,
                status,
                attempts,
                scheduled_at,
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', 0, ?, NOW())
        ");

        if (!$stmt) {
            error_log("[YesParency Mailer] Prepare failed: " . $conn->error);
            return null;
        }

        $stmt->bind_param("ssssss", $recipientEmail, $recipientName, $subject, $template, $payloadJson, $scheduledAt);
        $result = $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        return $result ? (int)$insertId : null;
    } catch (\Throwable $e) {
        error_log("[YesParency Mailer] queue_email error: " . $e->getMessage());
        return null;
    }
}

/**
 * Send a specific email from the queue by its ID
 */
function send_queued_email(mysqli $conn, int $queueId): bool
{
    if ($queueId <= 0) return false;

    // Fetch queue record
    $stmt = $conn->prepare("SELECT * FROM email_queue WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("i", $queueId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) return false;
    if ($item['status'] === 'sent') return true;

    // Mark as processing
    $upd = $conn->prepare("UPDATE email_queue SET status = 'processing', attempts = attempts + 1, updated_at = NOW() WHERE id = ?");
    if ($upd) {
        $upd->bind_param("i", $queueId);
        $upd->execute();
        $upd->close();
    }

    $payload = !empty($item['payload']) ? json_decode($item['payload'], true) : [];
    if (!is_array($payload)) $payload = [];
    $payload['subject']        = $item['subject'];
    $payload['recipient_name'] = $item['recipient_name'];

    try {
        // Render template
        $rendered = mailer_render_template($item['template'], $payload);

        // Configure and send mail
        $mail = mailer_create_instance();
        $mail->addAddress($item['recipient_email'], $item['recipient_name'] ?: '');
        $mail->Subject = $item['subject'];
        $mail->Body    = $rendered['html'];
        $mail->AltBody = $rendered['text'];

        $mail->send();

        // Mark as sent
        $okStmt = $conn->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?");
        if ($okStmt) {
            $okStmt->bind_param("i", $queueId);
            $okStmt->execute();
            $okStmt->close();
        }

        return true;
    } catch (\Throwable $e) {
        // Sanitize error message to prevent leaking SMTP password or auth credentials
        $rawError = $e->getMessage();
        $config   = mailer_get_config();
        if (!empty($config['password'])) {
            $rawError = str_replace($config['password'], '***REDACTED***', $rawError);
        }
        $errorMsg = mb_substr($rawError, 0, 1000);

        error_log("[YesParency Mailer] Send failed for queue #{$queueId}: " . $errorMsg);

        $failStmt = $conn->prepare("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?");
        if ($failStmt) {
            $failStmt->bind_param("si", $errorMsg, $queueId);
            $failStmt->execute();
            $failStmt->close();
        }

        return false;
    }
}

/**
 * Dispatch a queued email safely without throwing or breaking caller flow
 */
function dispatch_queued_email_safe(mysqli $conn, ?int $queueId): bool
{
    if (!$queueId || $queueId <= 0) return false;

    try {
        return send_queued_email($conn, $queueId);
    } catch (\Throwable $e) {
        error_log("[YesParency Mailer] dispatch_queued_email_safe error: " . $e->getMessage());
        return false;
    }
}

/**
 * Process pending/failed items in the email queue (used by worker or cron)
 */
function process_email_queue(mysqli $conn, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt  = $conn->prepare("
        SELECT id FROM email_queue
        WHERE status IN ('pending', 'failed')
          AND attempts < 5
          AND scheduled_at <= NOW()
        ORDER BY scheduled_at ASC
        LIMIT ?
    ");

    if (!$stmt) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $processed = 0;
    $sent      = 0;
    $failed    = 0;

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $processed++;
        if (send_queued_email($conn, $id)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return [
        'processed' => $processed,
        'sent'      => $sent,
        'failed'    => $failed
    ];
}

/**
 * Check if a specific business notification is already queued/sent (Duplicate Prevention)
 */
function is_notification_already_queued(
    mysqli $conn,
    string $template,
    string $recipientEmail,
    string $payloadKey,
    $payloadValue
): bool {
    // Check if status is pending, processing, sent, or failed for this template & email
    $stmt = $conn->prepare("
        SELECT payload FROM email_queue
        WHERE template = ?
          AND recipient_email = ?
          AND status IN ('pending', 'processing', 'sent', 'failed')
    ");

    if (!$stmt) return false;
    $stmt->bind_param("ss", $template, $recipientEmail);
    $stmt->execute();
    $res = $stmt->get_result();

    $exists = false;
    while ($row = $res->fetch_assoc()) {
        $p = !empty($row['payload']) ? json_decode($row['payload'], true) : [];
        if (isset($p[$payloadKey]) && (string)$p[$payloadKey] === (string)$payloadValue) {
            $exists = true;
            break;
        }
    }
    $stmt->close();

    return $exists;
}

// ─────────────────────────────────────────────────────────────────────────────
// BUSINESS EVENT NOTIFICATION HELPERS (SPECIFIC RECIPIENTS ONLY)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 1. Notify Bidder Account Approved
 */
function notify_bidder_approved(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) return false;

    $stmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.email,
               bp.business_name
        FROM users u
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $bidder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bidder || empty($bidder['email'])) return false;

    $recipientName = trim($bidder['firstname'] . ' ' . $bidder['lastname']);
    $businessName  = $bidder['business_name'] ?: $recipientName;

    // Duplicate prevention
    if (is_notification_already_queued($conn, 'bidder_approved', $bidder['email'], 'user_id', $userId)) {
        return true;
    }

    $subject = "Your YesParency Bidder Account Has Been Approved";
    $payload = [
        'user_id'        => $userId,
        'recipient_name' => $recipientName,
        'business_name'  => $businessName,
        'login_url'      => mailer_get_app_url() . '/login.php',
    ];

    $queueId = queue_email($conn, $bidder['email'], $recipientName, $subject, 'bidder_approved', $payload);
    return dispatch_queued_email_safe($conn, $queueId);
}

/**
 * 2. Notify Bidder Account Rejected
 */
function notify_bidder_rejected(mysqli $conn, int $userId, ?string $reason = null): bool
{
    if ($userId <= 0) return false;

    $stmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.email,
               bp.business_name
        FROM users u
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $bidder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bidder || empty($bidder['email'])) return false;

    $recipientName = trim($bidder['firstname'] . ' ' . $bidder['lastname']);
    $businessName  = $bidder['business_name'] ?: $recipientName;

    // Duplicate prevention
    if (is_notification_already_queued($conn, 'bidder_rejected', $bidder['email'], 'user_id', $userId)) {
        return true;
    }

    $subject = "Notice Regarding Your YesParency Bidder Application";
    $payload = [
        'user_id'        => $userId,
        'recipient_name' => $recipientName,
        'business_name'  => $businessName,
        'reason'         => $reason,
        'login_url'      => mailer_get_app_url() . '/login.php',
    ];

    $queueId = queue_email($conn, $bidder['email'], $recipientName, $subject, 'bidder_rejected', $payload);
    return dispatch_queued_email_safe($conn, $queueId);
}

/**
 * 3. Notify Bid Verified
 */
function notify_bid_verified(mysqli $conn, int $bidId): bool
{
    if ($bidId <= 0) return false;

    $stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date,
               p.title AS proc_title, p.philgeps_ref_no,
               u.user_id, u.firstname, u.lastname, u.email,
               bp.business_name
        FROM bids b
        JOIN procurements p ON b.procurement_id = p.id
        JOIN users u ON b.bidder_id = u.user_id
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        WHERE b.id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $bidId);
    $stmt->execute();
    $bid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bid || empty($bid['email'])) return false;

    $recipientName = trim($bid['firstname'] . ' ' . $bid['lastname']);
    $businessName  = $bid['business_name'] ?: $recipientName;

    // Duplicate prevention
    if (is_notification_already_queued($conn, 'bid_verified', $bid['email'], 'bid_id', $bidId)) {
        return true;
    }

    $subject = "Bid Submission Verified: " . $bid['proc_title'];
    $payload = [
        'bid_id'            => $bidId,
        'recipient_name'    => $recipientName,
        'business_name'     => $businessName,
        'procurement_title' => $bid['proc_title'],
        'philgeps_ref_no'   => $bid['philgeps_ref_no'],
        'submission_date'   => $bid['submission_date'] ? date('F j, Y h:i A', strtotime($bid['submission_date'])) : 'Recorded',
        'login_url'         => mailer_get_app_url() . '/login.php',
    ];

    $queueId = queue_email($conn, $bid['email'], $recipientName, $subject, 'bid_verified', $payload);
    return dispatch_queued_email_safe($conn, $queueId);
}

/**
 * 4. Notify Bid Rejected
 */
function notify_bid_rejected(mysqli $conn, int $bidId, ?string $reason = null): bool
{
    if ($bidId <= 0) return false;

    $stmt = $conn->prepare("
        SELECT b.id AS bid_id,
               p.title AS proc_title, p.philgeps_ref_no,
               u.user_id, u.firstname, u.lastname, u.email,
               bp.business_name
        FROM bids b
        JOIN procurements p ON b.procurement_id = p.id
        JOIN users u ON b.bidder_id = u.user_id
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        WHERE b.id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $bidId);
    $stmt->execute();
    $bid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bid || empty($bid['email'])) return false;

    $recipientName = trim($bid['firstname'] . ' ' . $bid['lastname']);
    $businessName  = $bid['business_name'] ?: $recipientName;

    // Duplicate prevention
    if (is_notification_already_queued($conn, 'bid_rejected', $bid['email'], 'bid_id', $bidId)) {
        return true;
    }

    $subject = "Notice Regarding Your Bid Submission: " . $bid['proc_title'];
    $payload = [
        'bid_id'            => $bidId,
        'recipient_name'    => $recipientName,
        'business_name'     => $businessName,
        'procurement_title' => $bid['proc_title'],
        'philgeps_ref_no'   => $bid['philgeps_ref_no'],
        'reason'            => $reason,
        'login_url'         => mailer_get_app_url() . '/login.php',
    ];

    $queueId = queue_email($conn, $bid['email'], $recipientName, $subject, 'bid_rejected', $payload);
    return dispatch_queued_email_safe($conn, $queueId);
}

/**
 * 5. Notify Bid Opening Session Scheduled
 * Strictly sends individual notifications ONLY to the users in bid_session_invited.
 */
function notify_bid_session_scheduled(mysqli $conn, int $sessionId): array
{
    if ($sessionId <= 0) return ['total' => 0, 'queued' => 0];

    // Fetch session and procurement details
    $stmt = $conn->prepare("
        SELECT bos.id AS session_id, bos.title AS session_title, bos.stream_path,
               p.title AS proc_title, p.philgeps_ref_no, p.opening_date
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.id = ?
        LIMIT 1
    ");
    if (!$stmt) return ['total' => 0, 'queued' => 0];
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) return ['total' => 0, 'queued' => 0];

    // Fetch ONLY the invited users for this specific session
    $invStmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.email
        FROM bid_session_invited bsi
        JOIN users u ON bsi.user_id = u.user_id
        WHERE bsi.bid_session_id = ? AND u.status = 'active'
    ");
    if (!$invStmt) return ['total' => 0, 'queued' => 0];
    $invStmt->bind_param("i", $sessionId);
    $invStmt->execute();
    $invitedUsers = $invStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $invStmt->close();

    $total  = count($invitedUsers);
    $queued = 0;

    $scheduledTimestamp = !empty($session['opening_date']) ? strtotime($session['opening_date']) : time();
    $scheduledDate      = date('F j, Y', $scheduledTimestamp);
    $scheduledTime      = date('h:i A', $scheduledTimestamp);
    $subject            = "Invitation: Bid Opening Session for " . $session['proc_title'];

    foreach ($invitedUsers as $u) {
        $email = trim($u['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        // Duplicate check per invited user for this session
        if (is_notification_already_queued($conn, 'session_scheduled', $email, 'session_id', $sessionId)) {
            continue;
        }

        $recipientName = trim($u['firstname'] . ' ' . $u['lastname']);
        $payload = [
            'session_id'        => $sessionId,
            'user_id'           => (int)$u['user_id'],
            'recipient_name'    => $recipientName,
            'procurement_title' => $session['proc_title'],
            'philgeps_ref_no'   => $session['philgeps_ref_no'],
            'session_title'     => $session['session_title'] ?: $session['proc_title'],
            'scheduled_date'    => $scheduledDate,
            'scheduled_time'    => $scheduledTime,
            'stream_path'       => $session['stream_path'],
            'portal_url'        => mailer_get_app_url() . '/admin/bid_session.php?session=' . $sessionId,
        ];

        $qid = queue_email($conn, $email, $recipientName, $subject, 'session_scheduled', $payload);
        if ($qid) {
            dispatch_queued_email_safe($conn, $qid);
            $queued++;
        }
    }

    return ['total' => $total, 'queued' => $queued];
}

/**
 * 6. Notify Bid Session Concluded & Report Available
 * Sends individual emails to users associated with this session.
 */
function notify_bid_session_concluded(mysqli $conn, int $sessionId): array
{
    if ($sessionId <= 0) return ['total' => 0, 'queued' => 0];

    // Fetch session and procurement
    $stmt = $conn->prepare("
        SELECT bos.id AS session_id, bos.created_by,
               p.title AS proc_title, p.philgeps_ref_no
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.id = ?
        LIMIT 1
    ");
    if (!$stmt) return ['total' => 0, 'queued' => 0];
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) return ['total' => 0, 'queued' => 0];

    // Get invited users for this session
    $invStmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.email
        FROM bid_session_invited bsi
        JOIN users u ON bsi.user_id = u.user_id
        WHERE bsi.bid_session_id = ? AND u.status = 'active'
    ");
    if (!$invStmt) return ['total' => 0, 'queued' => 0];
    $invStmt->bind_param("i", $sessionId);
    $invStmt->execute();
    $recipients = $invStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $invStmt->close();

    // Also include session creator if not already present
    if (!empty($session['created_by'])) {
        $found = false;
        foreach ($recipients as $r) {
            if ((int)$r['user_id'] === (int)$session['created_by']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $crStmt = $conn->prepare("SELECT user_id, firstname, lastname, email FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
            if ($crStmt) {
                $crStmt->bind_param("i", $session['created_by']);
                $crStmt->execute();
                $crUser = $crStmt->get_result()->fetch_assoc();
                $crStmt->close();
                if ($crUser) $recipients[] = $crUser;
            }
        }
    }

    $total  = count($recipients);
    $queued = 0;
    $concludedDate = date('F j, Y');
    $subject       = "Bid Opening Session Concluded: " . $session['proc_title'];

    foreach ($recipients as $u) {
        $email = trim($u['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        if (is_notification_already_queued($conn, 'session_concluded', $email, 'session_id', $sessionId)) {
            continue;
        }

        $recipientName = trim($u['firstname'] . ' ' . $u['lastname']);
        $payload = [
            'session_id'        => $sessionId,
            'user_id'           => (int)$u['user_id'],
            'recipient_name'    => $recipientName,
            'procurement_title' => $session['proc_title'],
            'philgeps_ref_no'   => $session['philgeps_ref_no'],
            'concluded_date'    => $concludedDate,
            'report_url'        => mailer_get_app_url() . '/admin/checklist_pdf.php?session=' . $sessionId,
            'login_url'         => mailer_get_app_url() . '/login.php',
        ];

        $qid = queue_email($conn, $email, $recipientName, $subject, 'session_concluded', $payload);
        if ($qid) {
            dispatch_queued_email_safe($conn, $qid);
            $queued++;
        }
    }

    return ['total' => $total, 'queued' => $queued];
}

/**
 * 7. Notify Lot Awarded
 * Sent strictly to the winning bidder of the awarded lot.
 */
function notify_lot_awarded(mysqli $conn, int $lotId, int $bidLotId, float $awardedAmount): bool
{
    if ($lotId <= 0 || $bidLotId <= 0) return false;

    $stmt = $conn->prepare("
        SELECT bl.id AS bid_lot_id, bl.lot_id,
               l.lot_number, l.lot_title,
               p.title AS proc_title, p.philgeps_ref_no,
               u.user_id, u.firstname, u.lastname, u.email,
               bp.business_name
        FROM bid_lots bl
        JOIN bids b ON bl.bid_id = b.id
        JOIN users u ON b.bidder_id = u.user_id
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        JOIN lots l ON bl.lot_id = l.id
        JOIN procurements p ON l.procurement_id = p.id
        WHERE bl.id = ? AND l.id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $bidLotId, $lotId);
    $stmt->execute();
    $winner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$winner || empty($winner['email'])) return false;

    $recipientName = trim($winner['firstname'] . ' ' . $winner['lastname']);
    $businessName  = $winner['business_name'] ?: $recipientName;

    // Duplicate check for this lot award to this winner
    if (is_notification_already_queued($conn, 'award_notification', $winner['email'], 'lot_id', $lotId)) {
        return true;
    }

    $subject = "Notice of Award: " . $winner['proc_title'] . " (Lot #" . $winner['lot_number'] . ")";
    $payload = [
        'lot_id'            => $lotId,
        'bid_lot_id'        => $bidLotId,
        'recipient_name'    => $recipientName,
        'business_name'     => $businessName,
        'procurement_title' => $winner['proc_title'],
        'philgeps_ref_no'   => $winner['philgeps_ref_no'],
        'lot_number'        => $winner['lot_number'],
        'lot_title'         => $winner['lot_title'],
        'awarded_amount'    => $awardedAmount,
        'award_date'        => date('F j, Y'),
        'login_url'         => mailer_get_app_url() . '/login.php',
    ];

    $queueId = queue_email($conn, $winner['email'], $recipientName, $subject, 'award_notification', $payload);
    return dispatch_queued_email_safe($conn, $queueId);
}
