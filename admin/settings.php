<?php
include("utils/protect-page.php");

$user_id = (int)$_SESSION['user_id'];

// ── Update Profile Information ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname  = trim($_POST['lastname'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    $prof_error = $prof_success = '';

    if (empty($firstname) || empty($lastname)) {
        $prof_error = "First name and last name are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $prof_error = "Please provide a valid email address.";
    } else {
        // Check if email is already used by someone else
        $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $chk->bind_param("si", $email, $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $prof_error = "Email address is already in use by another account.";
        } else {
            $upd = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, email = ? WHERE user_id = ?");
            $upd->bind_param("sssi", $firstname, $lastname, $email, $user_id);
            if ($upd->execute()) {
                $prof_success = "Profile information updated successfully.";
                $_SESSION['firstname'] = $firstname;
                $_SESSION['lastname']  = $lastname;
                $_SESSION['email']     = $email;
            } else {
                $prof_error = "Failed to update profile. Please try again.";
            }
            $upd->close();
        }
        $chk->close();
    }

    $_SESSION['prof_error']   = $prof_error;
    $_SESSION['prof_success'] = $prof_success;
    header("Location: settings.php");
    exit();
}

$prof_error   = $_SESSION['prof_error']   ?? ''; unset($_SESSION['prof_error']);
$prof_success = $_SESSION['prof_success'] ?? ''; unset($_SESSION['prof_success']);

// ── Change Password ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_pw'] ?? '';
    $new_pw  = $_POST['new_pw']     ?? '';
    $confirm = $_POST['confirm_pw'] ?? '';

    $pw_error = $pw_success = '';

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        $pw_error = "Current password is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $pw_error = "New password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $new_pw) || !preg_match('/[a-z]/', $new_pw)
           || !preg_match('/[0-9]/', $new_pw) || !preg_match('/[^A-Za-z0-9]/', $new_pw)) {
        $pw_error = "New password does not meet all security requirements.";
    } elseif ($new_pw !== $confirm) {
        $pw_error = "New passwords do not match.";
    } else {
        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd->bind_param("si", $hash, $user_id);
        if ($upd->execute()) {
            $pw_success = "Password updated successfully.";
        } else {
            $pw_error = "Failed to update password. Please try again.";
        }
        $upd->close();
    }

    $_SESSION['pw_error']   = $pw_error;
    $_SESSION['pw_success'] = $pw_success;
    header("Location: settings.php");
    exit();
}

$pw_error   = $_SESSION['pw_error']   ?? ''; unset($_SESSION['pw_error']);
$pw_success = $_SESSION['pw_success'] ?? ''; unset($_SESSION['pw_success']);

// ── Fetch Current User Data ──────────────────────────────────────────────────
$u_stmt = $conn->prepare("SELECT user_id, firstname, lastname, username, email, role, created_at FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$current_user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$initials = strtoupper(substr($current_user['firstname'] ?? 'A', 0, 1) . substr($current_user['lastname'] ?? 'D', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        .set-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* ── Card ── */
        .set-card {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(16,36,26,.04);
        }
        .set-card-head {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 18px 24px;
            border-bottom: 1px solid #f0f4f2;
        }
        .set-card-head-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .set-card-head-title { font-size: 14.5px; font-weight: 800; color: #06251b; }
        .set-card-head-sub   { font-size: 11.5px; color: #aab5ae; margin-top: 2px; }
        .set-card-body       { padding: 24px 28px; display: flex; flex-direction: column; gap: 20px; }

        /* ── Profile Summary Banner ── */
        .prof-banner {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            background: #f7faf8;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            flex-wrap: wrap;
        }
        .prof-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(145deg, #1f4638, #06251b);
            color: #eafff2;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .prof-banner-info { flex: 1; min-width: 180px; }
        .prof-banner-name { font-size: 15px; font-weight: 700; color: #06251b; }
        .prof-banner-meta { font-size: 12px; color: #7a9088; margin-top: 2px; display: flex; gap: 10px; flex-wrap: wrap; }
        .prof-banner-meta span { display: inline-flex; align-items: center; gap: 4px; }

        /* ── Fields ── */
        .set-field { display: flex; flex-direction: column; gap: 5px; }
        .set-field label { font-size: 12px; font-weight: 700; color: #4a5e54; }
        .set-field input[type="text"],
        .set-field input[type="email"],
        .set-field input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e0e8e3;
            border-radius: 10px;
            font-size: 13.5px;
            font-family: 'Poppins', sans-serif;
            color: #222;
            background: #fafbfa;
            outline: none;
            box-sizing: border-box;
            transition: border-color .2s, box-shadow .2s;
        }
        .set-field input:focus {
            border-color: #06251b;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(6,37,27,.07);
        }
        .set-field input[readonly] {
            background: #f0f4f2;
            color: #7a9088;
            cursor: not-allowed;
        }

        .set-field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 600px) { .set-field-grid { grid-template-columns: 1fr; } }

        /* ── Password input wrapper ── */
        .set-pw-wrap { position: relative; }
        .set-pw-wrap input { padding-right: 42px; }
        .set-pw-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #aab5ae;
            font-size: 16px;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color .15s;
        }
        .set-pw-toggle:hover { color: #06251b; }

        /* ── Password strength bar ── */
        .set-pw-strength {
            height: 4px;
            border-radius: 4px;
            background: #eaeeec;
            overflow: hidden;
            margin-top: 2px;
        }
        .set-pw-strength-fill {
            height: 100%;
            border-radius: 4px;
            width: 0%;
            transition: width .3s, background .3s;
        }
        .set-pw-strength-label {
            font-size: 11px;
            font-weight: 600;
            color: #aab5ae;
            margin-top: 3px;
        }
        .set-hint { font-size: 11.5px; color: #aab5ae; }

        /* ── Change password inner grid ── */
        .set-pw-card-inner {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 0;
        }
        @media (max-width: 720px) { .set-pw-card-inner { grid-template-columns: 1fr; } }

        .set-pw-left {
            padding: 24px 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            border-right: 1px solid #f0f4f2;
        }
        @media (max-width: 720px) { .set-pw-left { border-right: none; border-bottom: 1px solid #f0f4f2; } }

        .set-pw-right {
            padding: 24px 28px;
            background: #fafbfa;
            display: flex;
            flex-direction: column;
            gap: 12px;
            justify-content: center;
        }

        .set-pw-right-title {
            font-size: 11.5px;
            font-weight: 800;
            color: #06251b;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        .set-pw-rule {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #aab5ae;
            font-weight: 500;
            transition: color .2s;
        }
        .set-pw-rule i {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            background: #eaeeec;
            color: #bbb;
            flex-shrink: 0;
            transition: background .2s, color .2s;
        }
        .set-pw-rule.met { color: #1f7a3d; }
        .set-pw-rule.met i { background: #e4f5ea; color: #1f7a3d; }

        /* ── Save bar ── */
        .set-save-bar {
            display: flex;
            justify-content: flex-end;
            padding-top: 4px;
        }
        .set-btn-save {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #06251b;
            color: #ffc107;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: background .2s, transform .15s;
        }
        .set-btn-save:hover { background: #0a3d26; transform: translateY(-1px); }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Account Settings</h2>
        <p>Manage your administrator profile details and update your password security.</p>
    </div>

    <div class="set-container">

        <!-- ════════════════════════════════════════════════════════════
             1. PROFILE INFORMATION
             ════════════════════════════════════════════════════════════ -->
        <div class="set-card">
            <div class="set-card-head">
                <div class="set-card-head-icon" style="background:#e8f0ec; color:#06251b;">
                    <i class="bi bi-person-circle"></i>
                </div>
                <div>
                    <div class="set-card-head-title">Profile Information</div>
                    <div class="set-card-head-sub">Update your personal information and contact email</div>
                </div>
            </div>

            <div class="set-card-body">
                <!-- Profile summary banner -->
                <div class="prof-banner">
                    <div class="prof-avatar"><?= htmlspecialchars($initials) ?></div>
                    <div class="prof-banner-info">
                        <div class="prof-banner-name">
                            <?= htmlspecialchars(($current_user['firstname'] ?? '') . ' ' . ($current_user['lastname'] ?? '')) ?>
                        </div>
                        <div class="prof-banner-meta">
                            <span><i class="bi bi-at"></i><?= htmlspecialchars($current_user['username'] ?? '') ?></span>
                            <span>·</span>
                            <span><i class="bi bi-shield-check"></i> Administrator</span>
                            <span>·</span>
                            <span><i class="bi bi-calendar3"></i> Joined <?= date('M Y', strtotime($current_user['created_at'] ?? 'now')) ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($prof_error): ?>
                    <div class="toast-alert error" style="position:static; box-shadow:none; margin:0;">
                        <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($prof_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($prof_success): ?>
                    <div class="toast-alert success" style="position:static; box-shadow:none; margin:0;">
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($prof_success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="settings.php" style="display:flex; flex-direction:column; gap:16px;">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="set-field-grid">
                        <div class="set-field">
                            <label>First Name</label>
                            <input type="text" name="firstname" value="<?= htmlspecialchars($current_user['firstname'] ?? '') ?>" placeholder="First name" required>
                        </div>
                        <div class="set-field">
                            <label>Last Name</label>
                            <input type="text" name="lastname" value="<?= htmlspecialchars($current_user['lastname'] ?? '') ?>" placeholder="Last name" required>
                        </div>
                    </div>

                    <div class="set-field-grid">
                        <div class="set-field">
                            <label>Username (System Identifier)</label>
                            <input type="text" value="<?= htmlspecialchars($current_user['username'] ?? '') ?>" readonly title="Usernames cannot be changed">
                            <span class="set-hint"><i class="bi bi-lock-fill"></i> Unique username identifier is locked.</span>
                        </div>
                        <div class="set-field">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($current_user['email'] ?? '') ?>" placeholder="admin@example.com" required>
                        </div>
                    </div>

                    <div class="set-save-bar">
                        <button type="submit" class="set-btn-save">
                            <i class="bi bi-check2"></i> Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             2. CHANGE PASSWORD
             ════════════════════════════════════════════════════════════ -->
        <div class="set-card">
            <div class="set-card-head">
                <div class="set-card-head-icon" style="background:#fbe1e1; color:#c23b3b;">
                    <i class="bi bi-key-fill"></i>
                </div>
                <div>
                    <div class="set-card-head-title">Change Password</div>
                    <div class="set-card-head-sub">Keep your administrator account secure with a strong password</div>
                </div>
            </div>

            <div class="set-pw-card-inner">

                <!-- Left: Form inputs -->
                <div class="set-pw-left">
                    <?php if ($pw_error): ?>
                        <div class="toast-alert error" style="position:static; box-shadow:none; margin-bottom:4px;">
                            <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($pw_error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pw_success): ?>
                        <div class="toast-alert success" style="position:static; box-shadow:none; margin-bottom:4px;">
                            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($pw_success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="settings.php" style="display:flex; flex-direction:column; gap:16px;">
                        <input type="hidden" name="change_password" value="1">

                        <div class="set-field">
                            <label>Current Password</label>
                            <div class="set-pw-wrap">
                                <input type="password" id="currentPw" name="current_pw" placeholder="Enter your current password" required>
                                <button type="button" class="set-pw-toggle" onclick="togglePw('currentPw', this)" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div style="height:1px; background:#f0f4f2; margin:2px 0;"></div>

                        <div class="set-field">
                            <label>New Password</label>
                            <div class="set-pw-wrap">
                                <input type="password" id="newPw" name="new_pw" placeholder="Create a new password" oninput="checkStrength(this.value)" required>
                                <button type="button" class="set-pw-toggle" onclick="togglePw('newPw', this)" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <!-- Strength meter -->
                            <div class="set-pw-strength">
                                <div class="set-pw-strength-fill" id="strengthFill"></div>
                            </div>
                            <span class="set-pw-strength-label" id="strengthLabel">Enter a new password</span>
                        </div>

                        <div class="set-field">
                            <label>Confirm New Password</label>
                            <div class="set-pw-wrap">
                                <input type="password" id="confirmPw" name="confirm_pw" placeholder="Repeat new password" oninput="checkMatch()" required>
                                <button type="button" class="set-pw-toggle" onclick="togglePw('confirmPw', this)" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <span class="set-hint" id="matchHint" style="min-height:16px; display:block;"></span>
                        </div>

                        <div class="set-save-bar" style="padding-top:0;">
                            <button type="submit" class="set-btn-save">
                                <i class="bi bi-shield-check"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right: Rules checklist -->
                <div class="set-pw-right">
                    <div class="set-pw-right-title">
                        <i class="bi bi-shield-lock" style="margin-right:5px;"></i>Password Requirements
                    </div>
                    <div class="set-pw-rule" id="rule-len">
                        <i class="bi bi-check"></i> At least 8 characters
                    </div>
                    <div class="set-pw-rule" id="rule-upper">
                        <i class="bi bi-check"></i> One uppercase letter (A-Z)
                    </div>
                    <div class="set-pw-rule" id="rule-lower">
                        <i class="bi bi-check"></i> One lowercase letter (a-z)
                    </div>
                    <div class="set-pw-rule" id="rule-num">
                        <i class="bi bi-check"></i> One number (0-9)
                    </div>
                    <div class="set-pw-rule" id="rule-sym">
                        <i class="bi bi-check"></i> One special character (!@#$%^&*)
                    </div>
                </div>

            </div>
        </div>

    </div>

</div>
</main>

<script>
    // Password visibility toggle
    function togglePw(id, btn) {
        const input = document.getElementById(id);
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.querySelector('i').className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
    }

    // Password strength + rules validation
    function checkStrength(val) {
        const rules = {
            'rule-len':   val.length >= 8,
            'rule-upper': /[A-Z]/.test(val),
            'rule-lower': /[a-z]/.test(val),
            'rule-num':   /[0-9]/.test(val),
            'rule-sym':   /[^A-Za-z0-9]/.test(val),
        };

        let met = Object.values(rules).filter(Boolean).length;

        Object.entries(rules).forEach(([id, pass]) => {
            document.getElementById(id).classList.toggle('met', pass);
        });

        const fill   = document.getElementById('strengthFill');
        const label  = document.getElementById('strengthLabel');
        const pct    = (met / 5) * 100;
        const colors = ['#e0e0e0', '#c23b3b', '#e67e22', '#f9a825', '#43a047', '#1f7a3d'];
        const labels = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

        fill.style.width = pct + '%';
        fill.style.background = colors[met];
        label.textContent = val.length === 0 ? 'Enter a new password' : labels[met];
        label.style.color = met < 3 ? '#c23b3b' : met < 5 ? '#e67e22' : '#1f7a3d';

        checkMatch();
    }

    // Confirm password match check
    function checkMatch() {
        const np = document.getElementById('newPw').value;
        const cp = document.getElementById('confirmPw').value;
        const hint = document.getElementById('matchHint');
        if (!cp) { hint.textContent = ''; return; }
        if (np === cp) {
            hint.textContent = '✓ Passwords match';
            hint.style.color = '#1f7a3d';
        } else {
            hint.textContent = '✗ Passwords do not match';
            hint.style.color = '#c23b3b';
        }
    }
</script>

</body>
</html>
