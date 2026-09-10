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
        case 'superadmin': header("Location: superadmin/dashboard.php"); exit();
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

            // Mark invitation as accepted
            $upd = $conn->prepare("UPDATE user_invitations SET status = 'accepted' WHERE token = ?");
            $upd->bind_param("s", $post_token);
            $upd->execute();
            $upd->close();

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
}

.auth-card {
    background: #fff; border: 1px solid #e2ece6;
    border-radius: 20px; padding: 40px 36px;
    width: 100%; max-width: 560px;
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
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px; font-weight: 600;
    margin-bottom: 18px; display: flex; align-items: flex-start; gap: 8px; line-height: 1.6;
}
.alert-error i { flex-shrink: 0; margin-top: 1px; }
.alert-success {
    background: #eaf7ee; color: #1f7a3d; border: 1px solid #c9e8d3;
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px; font-weight: 600;
    margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
}

.invalid-card {
    text-align: center; padding: 10px 0 4px;
}
.invalid-card .inv-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: #fef2f2; color: #b91c1c;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 0 auto 16px;
}
.invalid-card h4 { font-size: 18px; font-weight: 800; color: #06251b; margin: 0 0 8px; }
.invalid-card p  { font-size: 13px; color: #63736a; line-height: 1.6; margin: 0 0 22px; }

.invite-info-strip {
    background: #f4f8f5; border: 1px solid #d4e0d8; border-radius: 12px;
    padding: 14px 16px; margin-bottom: 22px; display: flex; align-items: center; gap: 12px;
}
.invite-info-strip i { font-size: 20px; color: #1f7a3d; flex-shrink: 0; }
.invite-info-email { font-size: 13.5px; font-weight: 700; color: #06251b; }
.invite-info-note  { font-size: 11.5px; color: #6c776e; margin-top: 1px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

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
.input-wrapper input:disabled { background: #f4f8f5; color: #6c776e; cursor: not-allowed; }
.toggle-password {
    position: absolute; right: 12px; background: none; border: none;
    color: #88968d; cursor: pointer; font-size: 14px; padding: 4px;
    display: flex; align-items: center;
}
.toggle-password:hover { color: #06251b; }

.strength-bar { display: flex; gap: 4px; margin-top: 6px; }
.strength-bar span { flex: 1; height: 3px; border-radius: 4px; background: #e2ece6; transition: background .2s; }
.strength-bar span.weak   { background: #ef4444; }
.strength-bar span.fair   { background: #f97316; }
.strength-bar span.good   { background: #eab308; }
.strength-bar span.strong { background: #22c55e; }
.strength-label { font-size: 11px; font-weight: 700; color: #63736a; margin-top: 3px; }

.btn-register {
    width: 100%; background: #06251b; color: #ffc107; border: none;
    padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 800;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; margin-top: 6px;
}
.btn-register:hover { background: #144937; color: #fff; transform: translateY(-1px); }

.login-footer { text-align: center; margin-top: 20px; font-size: 13px; color: #63736a; }
.login-footer a { color: #1f7a3d; font-weight: 700; text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }

.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    background: #06251b; color: #ffc107; text-decoration: none;
    padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700;
    transition: all .2s; margin-top: 4px;
}
.btn-back:hover { background: #144937; color: #fff; }

@media (max-width: 480px) { .auth-card { padding: 28px 20px; } }
</style>
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
                <div style="margin-top:14px;">
                    <a href="register.php" style="font-size:12.5px; color:#1f7a3d; font-weight:700; text-decoration:none;">
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
                        <label for="firstname">First Name <span style="color:#e53935;">*</span></label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon-left"></i>
                            <input type="text" id="firstname" name="firstname"
                                   placeholder="Juan"
                                   value="<?= htmlspecialchars($old['firstname'] ?? '') ?>"
                                   required autocomplete="given-name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="lastname">Last Name <span style="color:#e53935;">*</span></label>
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
                    <label for="username">Username <span style="color:#e53935;">*</span></label>
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
                        <label for="password">Password <span style="color:#e53935;">*</span></label>
                        <div class="input-wrapper">
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
                        <label for="confirm_password">Confirm Password <span style="color:#e53935;">*</span></label>
                        <div class="input-wrapper">
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

                <button type="submit" class="btn-register">
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
    const pw  = document.getElementById('password')?.value;
    const cpw = this.value;
    this.style.borderColor = cpw.length && pw !== cpw ? '#ef4444' : '';
});
</script>
</body>
</html>
