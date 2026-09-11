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
    <!-- Custom CSS: base -> shared components -> page-specific -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/pages/reset-password.css">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
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
                <div class="invalid-alt-link-wrap">
                    <a href="login.php" class="invalid-alt-link">
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
                    <div class="input-wrapper has-toggle">
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
                    <div class="input-wrapper has-toggle">
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
                    <div id="match-hint" class="match-hint" hidden></div>
                </div>

                <button type="submit" class="btn-auth-submit">
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
