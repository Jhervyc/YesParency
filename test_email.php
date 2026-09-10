<?php
// Load Composer autoloader + dotenv (bootstrap.php calls Dotenv::createImmutable)
require_once 'bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// 1. CONFIGURATION — pulled from .env via bootstrap.php
// =========================================================================

$smtpHost     = $_ENV['MAIL_HOST']         ?? 'localhost';
$smtpPort     = (int)($_ENV['MAIL_PORT']   ?? 587);
$smtpUser     = $_ENV['MAIL_USERNAME']     ?? '';
$smtpPassword = $_ENV['MAIL_PASSWORD']     ?? '';
$fromEmail    = $_ENV['MAIL_FROM_ADDRESS'] ?? $smtpUser;
$fromName     = $_ENV['MAIL_FROM_NAME']    ?? 'YesParency';
$encryption   = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');

// Recipient
$toEmail = 'jhervycosejo05@gmail.com';
$toName  = 'Test User';

// =========================================================================
// 2. DUMP RESOLVED VALUES (so you can confirm .env is being read)
// =========================================================================
echo '<pre style="font-family:monospace; background:#f4f8f5; padding:16px; border-radius:8px; border:1px solid #d4e0d8; margin-bottom:20px;">';
echo '<strong>Resolved .env values:</strong>' . PHP_EOL;
echo '  MAIL_HOST       = ' . htmlspecialchars($smtpHost)  . PHP_EOL;
echo '  MAIL_PORT       = ' . htmlspecialchars($smtpPort)  . PHP_EOL;
echo '  MAIL_USERNAME   = ' . htmlspecialchars($smtpUser)  . PHP_EOL;
echo '  MAIL_PASSWORD   = ' . (empty($smtpPassword) ? '<span style="color:red;">EMPTY — not loaded!</span>' : '<span style="color:green;">SET (' . strlen($smtpPassword) . ' chars)</span>') . PHP_EOL;
echo '  MAIL_ENCRYPTION = ' . htmlspecialchars($encryption) . PHP_EOL;
echo '  MAIL_FROM       = ' . htmlspecialchars($fromEmail) . ' / ' . htmlspecialchars($fromName) . PHP_EOL;
echo '</pre>';

// =========================================================================
// 3. SMTP EXECUTION & TESTING
// =========================================================================

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug = 2; // Verbose — set to 0 after confirming it works

    $mail->isSMTP();
    $mail->Host     = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPassword;
    $mail->Port     = $smtpPort;

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = 'YesParency SMTP Test — ' . date('Y-m-d H:i:s');
    $mail->Body    = '
        <div style="font-family:Arial,sans-serif; padding:20px; border:1px solid #e0e0e0; border-radius:8px;">
            <h2 style="color:#1f7a3d;">SMTP Configuration Successful!</h2>
            <p>If you are receiving this, PHPMailer is reading your <code>.env</code> correctly and sending via <strong>' . htmlspecialchars($smtpHost) . '</strong>.</p>
            <hr style="border:none; border-top:1px solid #eee;">
            <p style="font-size:0.85em; color:#666;">Sent: ' . date('Y-m-d H:i:s') . '</p>
        </div>
    ';
    $mail->AltBody = 'SMTP test successful. Sent: ' . date('Y-m-d H:i:s');

    $mail->send();

    echo "<br><strong style='color:green;'>✓ SUCCESS: Message sent to {$toEmail}</strong>";

} catch (Exception $e) {
    echo "<br><strong style='color:red;'>✗ FAILURE: Message could not be sent.</strong><br>";
    echo "Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
}
