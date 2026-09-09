<?php
// Load PHPMailer classes using Composer's autoloader
// Adjust the path if your vendor folder is located elsewhere (e.g., ../vendor/autoload.php)
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// 1. CONFIGURATION (Fill in your details below)
// =========================================================================

$smtpHost     = 'mail.yesparency.site';
$smtpPort     = 587;                          // 587 (TLS) or 465 (SSL)
$smtpUser     = 'no-reply@yesparency.site';  // Your HestiaCP email account
$smtpPassword = 'W8F@By/hwFDFkEq#';        // <-- Enter your email account password here

$fromEmail    = 'no-reply@yesparency.site';
$fromName     = 'YesParency System Test';

// Recipient details
$toEmail      = 'jhervycosejo05@gmail.com';       // <-- Enter your personal email address here
$toName       = 'Test User';                  // <-- Enter recipient's name here

// =========================================================================
// 2. SMTP EXECUTION & TESTING
// =========================================================================

$mail = new PHPMailer(true);

try {
    // Enable verbose debug output (useful for troubleshooting connection issues)
    // Set to 0 once testing is successful to disable debug logs
    $mail->SMTPDebug = 2; 

    // Server Settings
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPassword;
    
    // Encryption setup
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Use ENCRYPTION_SMTPS if using port 465
    $mail->Port       = $smtpPort;

    // Sender & Recipient Setup
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $toName);

    // Email Content
    $mail->isHTML(true);
    $mail->Subject = 'YesParency SMTP Test Connection';
    $mail->Body    = '
        <div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
            <h2 style="color: #2563eb;">SMTP Configuration Successful!</h2>
            <p>If you are receiving this message, your PHPMailer setup with <strong>mail.yesparency.site</strong> is functioning correctly.</p>
            <hr style="border: none; border-top: 1px solid #eeeeee;">
            <p style="font-size: 0.85em; color: #666666;">Sent on: ' . date('Y-m-d H:i:s') . '</p>
        </div>
    ';
    $mail->AltBody = 'SMTP Configuration Successful! If you are receiving this message, your PHPMailer setup is functioning correctly.';

    // Send the email
    $mail->send();
    
    // Success Verification Output
    echo "<br><strong style='color: green;'>SUCCESS: Message has been sent successfully to {$toEmail}!</strong>";

} catch (Exception $e) {
    // Failure Verification Output
    echo "<br><strong style='color: red;'>FAILURE: Message could not be sent.</strong><br>";
    echo "Mailer Error: {$mail->ErrorInfo}";
}