<?php
/**
 * Master Email Layout — YesParency
 *
 * Variables expected:
 * - $content        : string — HTML body from the child template
 * - $subject        : string
 * - $recipient_name : string|null
 * - $app_name       : string
 * - $app_url        : string
 */
$app_name = $app_name ?? 'YesParency';
$app_url  = $app_url  ?? 'http://localhost/YesParency';
$year     = date('Y');
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($subject ?? 'YesParency') ?></title>
    <style>
        /* ── Reset ── */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        body {
            margin: 0; padding: 0; width: 100% !important;
            background-color: #f4f8f5;
            font-family: 'Poppins', 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #1a2e22;
            line-height: 1.6;
        }

        /* ── Wrapper ── */
        .wrapper { width: 100%; table-layout: fixed; background-color: #f4f8f5; padding: 32px 0; }

        /* ── Card ── */
        .main-card {
            max-width: 600px; margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(6,37,27,.10);
            border: 1px solid #d4e0d8;
        }

        /* ── Header ── */
        .email-header {
            background: #06251b;
            padding: 28px 36px;
            text-align: center;
        }
        .brand-name {
            font-size: 26px; font-weight: 800;
            color: #ffffff;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 0;
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
        }
        .brand-name span { color: #ffc107; }

        /* ── Body ── */
        .email-body {
            padding: 32px 36px;
            font-size: 14px;
            color: #2d3e35;
        }
        .email-title {
            font-size: 20px; font-weight: 700;
            color: #06251b; margin: 0 0 18px 0;
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
        }
        .email-body p { margin: 0 0 14px 0; }

        /* ── Info card ── */
        .info-card {
            background: #f4f8f5;
            border: 1px solid #d4e0d8;
            border-radius: 12px;
            padding: 4px 0;
            margin: 18px 0;
            overflow: hidden;
        }
        .info-row {
            display: table; width: 100%;
            padding: 10px 18px;
            border-bottom: 1px solid #eaeeec;
            box-sizing: border-box;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label {
            display: table-cell; width: 40%;
            font-size: 12px; font-weight: 700;
            color: #55665a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .info-value {
            display: table-cell; width: 60%;
            font-size: 13px; font-weight: 600;
            color: #06251b;
            text-align: right;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-info    { background: #dbeafe; color: #1d4ed8; }

        /* ── CTA Button ── */
        .btn-wrapper { text-align: center; margin: 24px 0 18px; }
        .btn {
            display: inline-block;
            background: #06251b; color: #ffc107 !important;
            padding: 13px 32px;
            font-size: 14px; font-weight: 700;
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            text-decoration: none;
            border-radius: 10px;
            letter-spacing: 0.3px;
        }

        /* ── Footer ── */
        .email-footer {
            background: #f4f8f5;
            border-top: 1px solid #d4e0d8;
            padding: 20px 36px;
            text-align: center;
            font-size: 12px;
            color: #88968d;
        }
        .email-footer a { color: #1f7a3d; text-decoration: none; font-weight: 600; }
        .email-footer a:hover { text-decoration: underline; }
        .footer-sep { margin: 0 6px; color: #c2cec5; }
    </style>
</head>
<body>
<table role="presentation" class="wrapper" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td align="center" style="padding: 32px 16px;">
            <table role="presentation" class="main-card" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;">

                <!-- Header -->
                <tr>
                    <td class="email-header">
                        <p class="brand-name">Yes<span>Parency</span></p>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td class="email-body">
                        <?= $content ?>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td class="email-footer">
                        <p style="margin:0 0 8px;">
                            <strong><?= htmlspecialchars($app_name) ?></strong>
                            <span class="footer-sep">&bull;</span>
                            &copy; <?= $year ?>
                        </p>
                        <p style="margin:0;">
                            <a href="<?= htmlspecialchars($app_url) ?>">Portal Home</a>
                            <span class="footer-sep">&bull;</span>
                            <a href="<?= htmlspecialchars($app_url) ?>/login.php">Sign In</a>
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
