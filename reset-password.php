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

// ─────────────────────────────────────────────────────────────────────────────
// Helper — look up a valid (unexpired) reset row by raw token
// Returns the DB row array or null
// ─────────────────────────────────────────────────────────────────────────────
function find_valid_reset(mysqli $conn, string $rawToken): ?array
{
    if (empty($rawToken)) return null;
    $hash = hash('sha256', $rawToken);
    $stmt = $conn->prepare("
        SELECT pr.id, pr.email, u.user_id, u.firstname, u.lastname
        FROM   password_resets pr
        JOIN   users u ON u.email = pr.email AND u.status = 'active'
        WHERE  pr.token_hash = ?
          AND  pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Determine whether the token in the URL is valid
// ─────────────────────────────────────────────────────────────────────────────
$rawToken   = trim($_GET['token'] ?? '');
$tokenError = '';
$resetRow   = null;

if (empty($rawToken)) {
    $tokenError = "No reset token provided. Please use the link from your email.";
} else {
    $resetRow = find_valid_reset($conn, $rawToken);
    if (!$resetRow) {
        // Give a more precise message if the token exists but is expired/used
        $check = $conn->prepare("SELECT expires_at FROM password_resets WHERE token_hash = ? LIMIT 1");
        $check->bind_param("s", hash('sha256', $rawToken));
        $check->execute();
        $staleRow = $check->get_result()->fetch_assoc();
        $check->close();

        if ($staleRow) {
            $tokenError = "This password reset link has expired. Please request a new one.";
        } else {
            $tokenError = "This reset link is invalid or has already been used. Please request a new one.";
        }
    }
}

$pw_error   = '';
$pw_success = false;

// ─────────────────────────────────────────────────────────────────────────────
// POST — process new password submission
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenError && $resetRow) {
    $postToken      = trim($_POST['token']            ?? '');
    $newPassword    = $_POST['new_password']           ?? '';
    $confirmPassword = $_POST['confirm_password']      ?? '';

    // Re-validate token from hidden field (race-condition guard)
    $freshRow = find_valid_reset($conn, $postToken);
    if (!$freshRow || $postToken !== $rawToken) {
        $pw_error = "Your reset link has expired or is no longer valid. Please request a new one.";
    } elseif (strlen($newPassword) < 8) {
        $pw_error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $pw_error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $newPassword)) {
        $pw_error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $pw_error = "Password must contain at least one number.";
    } elseif ($newPassword !== $confirmPassword) {
        $pw_error = "Passwords do not match.";
    } else {
        $newHash   = password_hash($newPassword, PASSWORD_DEFAULT);
        $tokenHash = hash('sha256', $postToken);
        $userId    = (int)$freshRow['user_id'];

        $conn->begin_transaction();
        try {
            // Update the user's password
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $upd->bind_param("si", $newHash, $userId);
            $upd->execute();
            $upd->close();

            // Delete the used token — one-time use, no stale rows
            $del = $conn->prepare("DELETE FROM password_resets WHERE token_hash = ?");
            $del->bind_param("s", $tokenHash);
            $del->execute();
            $del->close();

            // Audit log
            require_once __DIR__ . '/admin/utils/audit_helper.php';
            audit_log(
                $conn,
                'PASSWORD_RESET',
                'users',
                $userId,
                "Password reset via email token for user #{$userId} ({$freshRow['email']})",
                null,
                ['reset_method' => 'email_token'],
                $userId
            );

            $conn->commit();

            $_SESSION['inv_success'] = "Your password has been reset successfully. Please sign in with your new password.";
            header("Location: login.php");
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("[reset-password] Failed for user #{$userId}: " . $e->getMessage());
            $pw_error = "Something went wrong. Please try again or request a new reset link.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | YesParency</title>
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

/* Invalid token card */
.invalid-card { text-align: center; padding: 10px 0 4px; }
.invalid-card .inv-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: #fef2f2; color: #b91c1c;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 0 auto 16px;
}
.invalid-card h4 { font-size: 18px; font-weight: 800; color: #06251b; margin: 0 0 8px; }
.invalid-card p  { font-size: 13px; color: #63736a; line-height: 1.6; margin: 0 0 22px; }

.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    background: #06251b; color: #ffc107; text-decoration: none;
    padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700;
    transition: all .2s;
}
.btn-back:hover { background: #144937; color: #fff; }

/* Form */
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: #06251b; margin-bottom: 6px; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.input-icon-left { position: absolute; left: 13px; color: #88968d; font-size: 14px; pointer-events: none; }
.input-wrapper input {
    width: 100%; padding: 10px 42px 10px 38px;
    border: 1.5px solid #d4e0d8; border-radius: 10px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    color: #1a1a1a; outline: none; background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.input-wrapper input:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }
.toggle-password {
    position: absolute; right: 12px; background: none; border: none;
    color: #88968d; cursor: pointer; font-size: 14px; padding: 4px;
    display: flex; align-items: center;
}
.toggle-password:hover { color: #06251b; }

/* Password requirements */
.pw-requirements {
    background: #f4f8f5; border: 1px solid #d4e0d8; border-radius: 9px;
    padding: 10px 14px; margin: 4px 0 12px; font-size: 11.5px;
}
.pw-req-title { font-weight: 700; color: #374151; margin-bottom: 6px; }
.pw-req-list  { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.pw-req-item  { color: #88968d; display: flex; align-items: center; gap: 6px; transition: color .15s; }
.pw-req-item.met { color: #1f7a3d; }
.pw-req-item.met i::before { content: "\f26a"; } /* bi-check-circle-fill */

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

        <?php if ($tokenError): ?>

            <!-- ── Invalid / expired token ── -->
            <div class="invalid-card">
                <div class="inv-icon"><i class="bi bi-shield-x"></i></div>
                <h4>Link Not Valid</h4>
                <p><?= htmlspecialchars($tokenError) ?></p>
                <a href="forgot-password.php" class="btn-back">
                    <i class="bi bi-arrow-repeat"></i> Request New Link
                </a>
                <div style="margin-top:14px;">
                    <a href="login.php" style="font-size:12.5px; color:#1f7a3d; font-weight:700; text-decoration:none;">
                        <i class="bi bi-box-arrow-in-right"></i> Back to Login
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- ── New password form ── -->
            <h3>Set New Password</h3>
            <p>Choose a strong password for your account.</p>

            <?php if ($pw_error): ?>
                <div class="alert-error">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($pw_error) ?>
                </div>
            <?php endif; ?>

            <form action="reset-password.php?token=<?= urlencode($rawToken) ?>" method="POST" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">

                <!-- New Password -->
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-key input-icon-left"></i>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="Enter new password" required
                               autocomplete="new-password"
                               oninput="checkRequirements(this.value)">
                        <button type="button" class="toggle-password"
                                onclick="togglePass('new_password','icon-np')"
                                aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="icon-np"></i>
                        </button>
                    </div>
                </div>

                <!-- Requirements checklist -->
                <div class="pw-requirements">
                    <div class="pw-req-title">Password Requirements:</div>
                    <ul class="pw-req-list">
                        <li class="pw-req-item" id="req-length">
                            <i class="bi bi-circle"></i> At least 8 characters
                        </li>
                        <li class="pw-req-item" id="req-upper">
                            <i class="bi bi-circle"></i> Uppercase letter (A–Z)
                        </li>
                        <li class="pw-req-item" id="req-lower">
                            <i class="bi bi-circle"></i> Lowercase letter (a–z)
                        </li>
                        <li class="pw-req-item" id="req-num">
                            <i class="bi bi-circle"></i> At least one number (0–9)
                        </li>
                    </ul>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-check2-circle input-icon-left"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Re-enter new password" required
                               autocomplete="new-password"
                               oninput="checkMatch()">
                        <button type="button" class="toggle-password"
                                onclick="togglePass('confirm_password','icon-cp')"
                                aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="icon-cp"></i>
                        </button>
                    </div>
                    <div id="match-hint" style="font-size:11.5px; margin-top:4px; display:none;"></div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="bi bi-shield-check"></i> Reset Password
                </button>
            </form>

            <div class="login-footer">
                Remembered it? <a href="login.php">Back to login</a>
            </div>

        <?php endif; ?>

    </div>
</div>

<script>
function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function checkRequirements(val) {
    const rules = {
        'req-length': val.length >= 8,
        'req-upper':  /[A-Z]/.test(val),
        'req-lower':  /[a-z]/.test(val),
        'req-num':    /[0-9]/.test(val),
    };
    Object.entries(rules).forEach(([id, met]) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('met', met);
        el.querySelector('i').className = met ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    });
    checkMatch(); // re-evaluate match hint whenever password changes
}

function checkMatch() {
    const pw   = document.getElementById('new_password')?.value     ?? '';
    const cpw  = document.getElementById('confirm_password')?.value ?? '';
    const hint = document.getElementById('match-hint');
    if (!hint || !cpw.length) { if (hint) hint.style.display = 'none'; return; }
    if (pw === cpw) {
        hint.style.display = 'block';
        hint.style.color   = '#1f7a3d';
        hint.textContent   = '✓ Passwords match';
    } else {
        hint.style.display = 'block';
        hint.style.color   = '#dc2626';
        hint.textContent   = '✗ Passwords do not match';
    }
}
</script>
</body>
</html>
