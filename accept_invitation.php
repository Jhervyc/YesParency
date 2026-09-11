<?php
session_start();
include("config/db_connect.php");

$token = trim($_GET['token'] ?? '');

// ── Validate token ────────────────────────────────────────────────────────────
$invitation = null;
$token_error = '';

if (empty($token)) {
    $token_error = "No invitation token provided. Please use the link from your email.";
} else {
    $inv_stmt = $conn->prepare("
        SELECT * FROM user_invitations
        WHERE token = ? AND status = 'pending' AND expires_at > NOW()
        LIMIT 1
    ");
    $inv_stmt->bind_param("s", $token);
    $inv_stmt->execute();
    $invitation = $inv_stmt->get_result()->fetch_assoc();
    $inv_stmt->close();

    if (!$invitation) {
        // Check if token exists but expired/accepted to give a better message
        $exp_stmt = $conn->prepare("SELECT status, expires_at FROM user_invitations WHERE token = ? LIMIT 1");
        $exp_stmt->bind_param("s", $token);
        $exp_stmt->execute();
        $exp_row = $exp_stmt->get_result()->fetch_assoc();
        $exp_stmt->close();

        if ($exp_row) {
            if ($exp_row['status'] === 'accepted') {
                $token_error = "This invitation has already been used. Please log in to your account.";
            } elseif ($exp_row['status'] === 'expired' || strtotime($exp_row['expires_at']) <= time()) {
                $token_error = "This invitation link has expired. Please contact the administrator to request a new one.";
            } else {
                $token_error = "This invitation is no longer valid.";
            }
        } else {
            $token_error = "Invalid invitation link. Please check your email or contact the administrator.";
        }
    }
}

// ── Redirect already logged-in users ─────────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role'] ?? '') {
        case 'user':       header("Location: user/dashboard.php");       exit();
        case 'bidder':     header("Location: bidder/dashboard.php");     exit();
        case 'admin':      header("Location: admin/dashboard.php");      exit();
        case 'superadmin': header("Location: admin/dashboard.php"); exit();
    }
}

$error   = $_SESSION['inv_error']   ?? '';
$success = $_SESSION['inv_success'] ?? '';
$old     = $_SESSION['inv_old']     ?? [];
unset($_SESSION['inv_error'], $_SESSION['inv_success'], $_SESSION['inv_old']);

// ── Handle account creation POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$token_error && $invitation) {
    $firstname       = trim($_POST['firstname']       ?? '');
    $lastname        = trim($_POST['lastname']        ?? '');
    $username        = trim($_POST['username']        ?? '');
    $password        = $_POST['password']             ?? '';
    $confirm_password = $_POST['confirm_password']    ?? '';
    $post_token      = trim($_POST['token']           ?? '');

    $errors = [];

    // Re-validate token from POST (prevent tampering)
    if ($post_token !== $token) {
        $errors[] = "Token mismatch. Please use the original invitation link.";
    }

    if (empty($firstname))  $errors[] = "First name is required.";
    if (empty($lastname))   $errors[] = "Last name is required.";
    if (empty($username))   $errors[] = "Username is required.";
    elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors[] = "Username must be 3–30 characters and may only contain letters, numbers, and underscores.";
    }
    if (empty($password))   $errors[] = "Password is required.";
    elseif (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    elseif ($password !== $confirm_password) $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        // Re-validate token is still valid (race condition guard)
        $rev = $conn->prepare("SELECT * FROM user_invitations WHERE token = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1");
        $rev->bind_param("s", $post_token);
        $rev->execute();
        $fresh_inv = $rev->get_result()->fetch_assoc();
        $rev->close();

        if (!$fresh_inv) {
            $errors[] = "Your invitation has expired or was already used. Please request a new one.";
        }
    }

    if (empty($errors)) {
        // Check username uniqueness
        $ucheck = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $ucheck->bind_param("s", $username);
        $ucheck->execute();
        $ucheck->store_result();
        if ($ucheck->num_rows > 0) {
            $errors[] = "That username is already taken. Please choose a different one.";
        }
        $ucheck->close();
    }

    if (empty($errors)) {
        // Check email uniqueness (email is locked from the invitation)
        $echeck = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $echeck->bind_param("s", $invitation['email']);
        $echeck->execute();
        $echeck->store_result();
        if ($echeck->num_rows > 0) {
            $errors[] = "An account with this email already exists. Please log in.";
        }
        $echeck->close();
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role   = $invitation['role'] ?? 'user';

        $conn->begin_transaction();
        try {
            // Create user account
            $ins = $conn->prepare("
                INSERT INTO users (firstname, lastname, email, username, password, role, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $ins->bind_param(
                "ssssss",
                $firstname, $lastname, $invitation['email'],
                $username, $hashed, $role
            );
            $ins->execute();
            $new_user_id = $conn->insert_id;
            $ins->close();

            // Delete the used token — one-time use, no stale rows
            $upd = $conn->prepare("DELETE FROM user_invitations WHERE token = ?");
            $upd->bind_param("s", $post_token);
            $upd->execute();
            $upd->close();

            // Delete the invitation request — no longer needed, audit trail covers it
            $delReq = $conn->prepare("DELETE FROM invitation_requests WHERE email = ? AND status = 'approved'");
            $delReq->bind_param("s", $invitation['email']);
            $delReq->execute();
            $delReq->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Account creation failed. Please try again.";
            error_log("[accept_invitation] Account creation error: " . $e->getMessage());
        }
    }

    if (empty($errors)) {
        $_SESSION['inv_success'] = "Your account has been created successfully! You can now log in.";
        header("Location: login.php");
        exit();
    } else {
        $_SESSION['inv_error'] = implode(' ', $errors);
        $_SESSION['inv_old']   = compact('firstname', 'lastname', 'username');
        header("Location: accept_invitation.php?token=" . urlencode($token));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS: base -> shared components -> page-specific -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/pages/accept-invitation.css">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
</head>
<body>

<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar(); ?>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="images/logo.png" alt="YesParency">
            <div>
                <div class="auth-logo-name">YesParency</div>
                <div class="auth-logo-sub">SLSU Procurement Portal</div>
            </div>
        </div>

        <?php if ($token_error): ?>
            <!-- ── Invalid / Expired Token ── -->
            <div class="invalid-card">
                <div class="inv-icon"><i class="bi bi-envelope-x-fill"></i></div>
                <h4>Invitation Not Valid</h4>
                <p><?= htmlspecialchars($token_error) ?></p>
                <a href="login.php" class="btn-back"><i class="bi bi-box-arrow-in-right"></i> Go to Login</a>
                <div class="secondary-link-wrap">
                    <a href="register.php" class="secondary-link">
                        <i class="bi bi-send-fill"></i> Submit a New Access Request
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- ── Valid Token — Show Account Creation Form ── -->
            <h3>Create Your Account</h3>
            <p>Your invitation has been verified. Set up your account below.</p>

            <?php if ($error): ?>
                <div class="alert-error"><i class="bi bi-exclamation-circle-fill"></i> <span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <!-- Locked email strip -->
            <div class="invite-info-strip">
                <i class="bi bi-envelope-check-fill"></i>
                <div>
                    <div class="invite-info-email"><?= htmlspecialchars($invitation['email']) ?></div>
                    <div class="invite-info-note">This email is linked to your invitation and cannot be changed.</div>
                </div>
            </div>

            <form action="accept_invitation.php?token=<?= urlencode($token) ?>" method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="firstname">First Name <span class="required-mark">*</span></label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon-left"></i>
                            <input type="text" id="firstname" name="firstname"
                                   placeholder="Juan"
                                   value="<?= htmlspecialchars($old['firstname'] ?? '') ?>"
                                   required autocomplete="given-name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="lastname">Last Name <span class="required-mark">*</span></label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon-left"></i>
                            <input type="text" id="lastname" name="lastname"
                                   placeholder="dela Cruz"
                                   value="<?= htmlspecialchars($old['lastname'] ?? '') ?>"
                                   required autocomplete="family-name">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon-left"></i>
                        <input type="email" value="<?= htmlspecialchars($invitation['email']) ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username <span class="required-mark">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-at input-icon-left"></i>
                        <input type="text" id="username" name="username"
                               placeholder="juandelacruz"
                               value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                               required autocomplete="username"
                               pattern="[a-zA-Z0-9_]{3,30}"
                               title="3–30 characters: letters, numbers, underscores only">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required-mark">*</span></label>
                        <div class="input-wrapper has-toggle">
                            <i class="bi bi-lock input-icon-left"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="Create a password" required
                                   autocomplete="new-password" oninput="checkStrength(this.value)">
                            <button type="button" class="toggle-password"
                                    onclick="togglePass('password','icon-pw')">
                                <i class="bi bi-eye" id="icon-pw"></i>
                            </button>
                        </div>
                        <div class="strength-bar">
                            <span id="s1"></span><span id="s2"></span>
                            <span id="s3"></span><span id="s4"></span>
                        </div>
                        <div class="strength-label" id="strength-label"></div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password <span class="required-mark">*</span></label>
                        <div class="input-wrapper has-toggle" id="confirmPasswordWrap">
                            <i class="bi bi-lock-fill input-icon-left"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="Repeat your password" required
                                   autocomplete="new-password">
                            <button type="button" class="toggle-password"
                                    onclick="togglePass('confirm_password','icon-cpw')">
                                <i class="bi bi-eye" id="icon-cpw"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-auth-submit">
                    <i class="bi bi-person-check-fill"></i> Create Account
                </button>
            </form>

        <?php endif; ?>

        <div class="login-footer">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>

    </div>
</div>

<script>
function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function checkStrength(val) {
    const bars  = ['s1','s2','s3','s4'].map(id => document.getElementById(id));
    const label = document.getElementById('strength-label');
    if (!bars[0]) return;
    const levels = ['weak','fair','good','strong'];
    const texts  = ['Weak','Fair','Good','Strong'];
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    bars.forEach((b, i) => { b.className = i < score ? levels[score - 1] : ''; });
    label.textContent = val.length ? (texts[score - 1] || '') : '';
}

// Confirm password match indicator
document.getElementById('confirm_password')?.addEventListener('input', function() {
    const pw   = document.getElementById('password')?.value;
    const cpw  = this.value;
    const wrap = document.getElementById('confirmPasswordWrap');
    wrap.classList.toggle('input-mismatch', cpw.length > 0 && pw !== cpw);
});
</script>
</body>
</html>
