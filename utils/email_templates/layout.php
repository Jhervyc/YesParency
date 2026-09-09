<?php
/**
 * Master Email Layout for YesParency System Notifications
 *
 * Variables expected:
 * - $content: string (HTML body rendered by the specific template)
 * - $subject: string
 * - $recipient_name: string|null
 * - $app_name: string (default 'YesParency')
 * - $app_url: string
 */
$app_name = $app_name ?? 'YesParency';
$app_url = $app_url ?? 'http://localhost/YesParency';
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($subject ?? 'YesParency Notification') ?></title>
    <style>
        /* Reset */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: #1e293b; line-height: 1.6; }

        /* Container */
        .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 32px 0; }
        .main-card { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0; }

        /* Header */
        .email-header { background: linear-gradient(135deg, #0a1e3b 0%, #0f2b54 100%); padding: 28px 32px; text-align: center; }
        .brand-logo { font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: 0.5px; text-transform: uppercase; margin: 0; }
        .brand-logo span { color: #f59e0b; }
        .brand-subtitle { font-size: 12px; color: #94a3b8; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 1px; }

        /* Body */
        .email-body { padding: 32px; font-size: 15px; color: #334155; }
        .email-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0; }

        /* Info box */
        .info-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin: 20px 0; }
        .info-row { display: table; width: 100%; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        .info-row:last-child { border-bottom: none; }
        .info-label { display: table-cell; width: 38%; font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { display: table-cell; width: 62%; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right; }

        /* Badges */
        .badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-success { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-warning { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-info { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        /* Button */
        .btn-wrapper { text-align: center; margin: 28px 0 16px 0; }
        .btn { display: inline-block; background: #0f2b54; color: #ffffff !important; padding: 13px 28px; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 6px rgba(15, 43, 84, 0.25); text-align: center; }
        .btn:hover { background: #0a1e3b; }

        /* Footer */
        .email-footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 24px 32px; text-align: center; font-size: 12px; color: #64748b; line-height: 1.6; }
        .footer-links a { color: #0369a1; text-decoration: none; margin: 0 8px; }
        .security-notice { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 11px; color: #94a3b8; }
    </style>
</head>
<body>
    <table role="presentation" class="wrapper" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" class="main-card" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <!-- Header -->
                    <tr>
                        <td class="email-header">
                            <h1 class="brand-logo">Yes<span>Parency</span></h1>
                            <p class="brand-subtitle">Electronic Procurement &amp; Transparency System</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="email-body">
                            <?= $content ?>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="email-footer">
                            <p style="margin: 0 0 8px 0;"><strong><?= htmlspecialchars($app_name) ?> Procurement Management System</strong></p>
                            <p style="margin: 0 0 12px 0;">In compliance with R.A. 9184 (Government Procurement Reform Act)</p>
                            <div class="footer-links">
                                <a href="<?= htmlspecialchars($app_url) ?>">Portal Home</a> &bull;
                                <a href="<?= htmlspecialchars($app_url) ?>/login.php">Sign In</a>
                            </div>
                            <div class="security-notice">
                                This is an automated notification. Please do not reply directly to this email.<br>
                                Recipient identity was authenticated and verified server-side.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
