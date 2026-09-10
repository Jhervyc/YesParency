<?php
// ─────────────────────────────────────────────────────────────────────────────
// Session bootstrap
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

include "config/db_connect.php";

// Redirect already-authenticated users
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'user':       header("Location: user/dashboard.php");   exit();
        case 'bidder':     header("Location: bidder/dashboard.php"); exit();
        case 'admin':
        case 'superadmin': header("Location: admin/dashboard.php");  exit();
    }
}

$error   = '';
$success = false;

// ─────────────────────────────────────────────────────────────────────────────
// POST — process email submission
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Look up the user — silently succeed even if not found (no enumeration)
        $u_stmt = $conn->prepare("
            SELECT user_id, firstname, lastname FROM users
            WHERE email = ? AND status = 'active'
            LIMIT 1
        ");
        $u_stmt->bind_param("s", $email);
        $u_stmt->execute();
        $user = $u_stmt->get_result()->fetch_assoc();
        $u_stmt->close();

        if ($user) {
            // Clear any existing reset tokens for this email
            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param("s", $email);
            $del->execute();
            $del->close();

            // Generate raw token (never stored) and its SHA-256 hash (stored)
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $ins = $conn->prepare("
                INSERT INTO password_resets (email, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
            ");
            $ins->bind_param("ss", $email, $tokenHash);
            $ins->execute();
            $ins->close();

            // Build reset URL
            require_once __DIR__ . '/bootstrap.php';
            $appUrl   = rtrim($_ENV['APP_URL'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/YesParency'), '/');
            $resetUrl = $appUrl . '/reset-password.php?token=' . urlencode($rawToken);

            // Send email
            require_once __DIR__ . '/utils/mailer.php';
            $recipientName = trim($user['firstname'] . ' ' . $user['lastname']) ?: 'User';
            send_password_reset_email($conn, $email, $recipientName, $resetUrl);
        }

        // Always show success — prevents email enumeration
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar_css(); ?>
<style>
* { box-sizing: border-box; }
body { background: #f4f8f5; font-family: 'Poppins', sans-serif; margin: 0; }

.auth-page {
    min-height: 100vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 100px 16px 60px;
    background: #f4f8f5;
}

.auth-card {
    background: #fff;
    border: 1px solid #e2ece6;
    border-radius: 20px;
    padding: 40px 36px;
    width: 100%; max-width: 440px;
    box-shadow: 0 4px 32px rgba(6,37,27,.08);
}

.auth-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; }
.auth-logo img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
.auth-logo-name { font-size: 16px; font-weight: 800; color: #06251b; font-family: 'Space Grotesk', sans-serif; }
.auth-logo-sub  { font-size: 11px; color: #88968d; }

.auth-card h3 { font-size: 22px; font-weight: 800; color: #06251b; margin: 0 0 4px; font-family: 'Space Grotesk', sans-serif; }
.auth-card > p { font-size: 13px; color: #63736a; margin: 0 0 24px; }

.alert-error {
    background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px;
    font-weight: 600; margin-bottom: 18px;
    display: flex; align-items: center; gap: 8px;
}

.alert-success {
    background: #eaf7ee; color: #1f7a3d; border: 1px solid #c9e8d3;
    border-radius: 10px; padding: 16px 18px; font-size: 13px;
    font-weight: 600; margin-bottom: 18px;
    display: flex; align-items: flex-start; gap: 10px; line-height: 1.6;
}
.alert-success i { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: #06251b; margin-bottom: 6px; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.input-icon-left { position: absolute; left: 13px; color: #88968d; font-size: 14px; pointer-events: none; }
.input-wrapper input {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid #d4e0d8; border-radius: 10px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    color: #1a1a1a; outline: none; background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.input-wrapper input:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }

.btn-submit {
    width: 100%; background: #06251b; color: #ffc107; border: none;
    padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 800;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; margin-top: 6px;
}
.btn-submit:hover { background: #144937; color: #fff; transform: translateY(-1px); }

.login-footer { text-align: center; margin-top: 20px; font-size: 13px; color: #63736a; }
.login-footer a { color: #1f7a3d; font-weight: 700; text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }

@media (max-width: 480px) { .auth-card { padding: 28px 20px; } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar('login'); ?>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="images/logo.png" alt="YesParency">
            <div>
                <div class="auth-logo-name">YesParency</div>
                <div class="auth-logo-sub">SLSU Procurement Portal</div>
            </div>
        </div>

        <?php if ($success): ?>

            <!-- ── Success state ── -->
            <h3>Check Your Email</h3>
            <p>We've sent you a reset link if your email is registered.</p>

            <div class="alert-success">
                <i class="bi bi-envelope-check-fill"></i>
                <div>
                    If an account with that email exists, a password reset link has been sent. The link expires in <strong>15 minutes</strong>.
                </div>
            </div>

            <div class="login-footer" style="margin-top:8px;">
                Didn't receive it? <a href="forgot-password.php">Try again</a>
                &nbsp;&bull;&nbsp;
                <a href="login.php">Back to login</a>
            </div>

        <?php else: ?>

            <!-- ── Request form ── -->
            <h3>Forgot Password</h3>
            <p>Enter your account email and we'll send you a reset link.</p>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="forgot-password.php" method="POST" novalidate>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon-left"></i>
                        <input type="email" id="email" name="email"
                               placeholder="Enter your registered email"
                               value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                               required autocomplete="email">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="bi bi-send-fill"></i> Send Reset Link
                </button>
            </form>

            <div class="login-footer">
                Remembered it? <a href="login.php">Back to login</a>
            </div>

        <?php endif; ?>

    </div>
</div>

</body>
</html>
