<?php
include("utils/protect-page.php");

$user_id = (int)$_SESSION['user_id'];

// ── Handle Profile Picture Upload ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    $avatar_error = $avatar_success = '';

    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $ext      = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','webp','gif'];
        $max_size = 5 * 1024 * 1024;

        if (!in_array($ext, $allowed)) {
            $avatar_error = "Invalid file type. Please upload a JPG, PNG, WEBP, or GIF image.";
        } elseif ($_FILES['profile_pic']['size'] > $max_size) {
            $avatar_error = "Image size exceeds the 5 MB limit.";
        } elseif (@getimagesize($_FILES['profile_pic']['tmp_name']) === false) {
            $avatar_error = "Uploaded file is not a valid image.";
        } else {
            $upload_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

            $new_filename = 'avatar_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target_path  = $upload_dir . DIRECTORY_SEPARATOR . $new_filename;
            $db_rel_path  = 'uploads/profile/' . $new_filename;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target_path)) {
                $old = $conn->prepare("SELECT profile_picture_url FROM users WHERE user_id = ?");
                $old->bind_param("i", $user_id); $old->execute();
                $old_row = $old->get_result()->fetch_assoc(); $old->close();

                if (!empty($old_row['profile_picture_url'])) {
                    $old_file = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/','\\'],[DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR],$old_row['profile_picture_url']);
                    if (file_exists($old_file)) @unlink($old_file);
                }

                $u = $conn->prepare("UPDATE users SET profile_picture_url = ? WHERE user_id = ?");
                $u->bind_param("si", $db_rel_path, $user_id);
                if ($u->execute()) {
                    $_SESSION['profile_picture_url'] = $db_rel_path;
                    $avatar_success = "Profile photo updated successfully.";
                } else {
                    $avatar_error = "Failed to save profile picture.";
                }
                $u->close();
            } else {
                $avatar_error = "Failed to move uploaded file.";
            }
        }
    } else {
        $avatar_error = "Please select an image file to upload.";
    }

    $_SESSION['avatar_error']   = $avatar_error;
    $_SESSION['avatar_success'] = $avatar_success;
    header("Location: settings.php"); exit();
}

// ── Handle Remove Profile Picture ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_avatar') {
    $old = $conn->prepare("SELECT profile_picture_url FROM users WHERE user_id = ?");
    $old->bind_param("i", $user_id); $old->execute();
    $old_row = $old->get_result()->fetch_assoc(); $old->close();

    if (!empty($old_row['profile_picture_url'])) {
        $f = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/','\\'],[DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR],$old_row['profile_picture_url']);
        if (file_exists($f)) @unlink($f);
    }

    $u = $conn->prepare("UPDATE users SET profile_picture_url = NULL WHERE user_id = ?");
    $u->bind_param("i", $user_id); $u->execute(); $u->close();
    unset($_SESSION['profile_picture_url']);
    $_SESSION['avatar_success'] = "Profile picture removed.";
    header("Location: settings.php"); exit();
}

// ── Handle Change Password ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';
    $pw_error = $pw_success = '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$user_row || !password_verify($current_pw, $user_row['password'])) {
        $pw_error = "The current password you entered is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $pw_error = "New password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_pw) || !preg_match('/[a-z]/', $new_pw) || !preg_match('/[0-9]/', $new_pw)) {
        $pw_error = "New password must contain uppercase, lowercase, and at least one number.";
    } elseif ($new_pw !== $confirm_pw) {
        $pw_error = "New password and confirmation do not match.";
    } elseif (password_verify($new_pw, $user_row['password'])) {
        $pw_error = "New password cannot be the same as your current password.";
    } else {
        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $u    = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $u->bind_param("si", $hash, $user_id);
        if ($u->execute()) $pw_success = "Your password has been changed successfully.";
        else $pw_error = "An error occurred while updating your password.";
        $u->close();
    }

    $_SESSION['pw_error']   = $pw_error;
    $_SESSION['pw_success'] = $pw_success;
    header("Location: settings.php"); exit();
}

// ── Flash messages ────────────────────────────────────────────────────────────
$avatar_error   = $_SESSION['avatar_error']   ?? ''; unset($_SESSION['avatar_error']);
$avatar_success = $_SESSION['avatar_success'] ?? ''; unset($_SESSION['avatar_success']);
$pw_error       = $_SESSION['pw_error']       ?? ''; unset($_SESSION['pw_error']);
$pw_success     = $_SESSION['pw_success']     ?? ''; unset($_SESSION['pw_success']);

// ── Fetch user data ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT user_id, firstname, lastname, username, email, role, status, profile_picture_url, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$data = $stmt->get_result()->fetch_assoc(); $stmt->close();

$fullname     = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')) ?: ($data['username'] ?? 'User');
$username     = $data['username']    ?? 'N/A';
$user_email   = $data['email']       ?? 'N/A';
$role         = ucfirst($data['role'] ?? 'User');
$member_since = !empty($data['created_at']) ? date('F j, Y', strtotime($data['created_at'])) : 'N/A';
$avatar_url   = !empty($data['profile_picture_url']) ? '../' . ltrim($data['profile_picture_url'], '/') : '';
$initials     = strtoupper(substr($data['firstname'] ?? 'U', 0, 1) . substr($data['lastname'] ?? 'R', 0, 1));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/user-settings.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Account Settings'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Account Settings</h2>
        <p>Manage your profile photo, view your account details, and update your password.</p>
    </div>

    <div class="settings-grid">

        <!-- ── LEFT: Profile Picture ── -->
        <div>

            <!-- Profile Photo Card -->
            <div class="set-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-person-circle clr-green"></i> Profile Photo
                    </span>
                    <span class="set-card-tag">Avatar</span>
                </div>
                <div class="set-card-body">

                    <?php if ($avatar_success): ?>
                        <div class="flash-alert success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($avatar_success) ?></div>
                    <?php endif; ?>
                    <?php if ($avatar_error): ?>
                        <div class="flash-alert error"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($avatar_error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="settings.php" enctype="multipart/form-data" id="avatarForm">
                        <input type="hidden" name="action" value="upload_avatar">
                        <div class="avatar-preview-wrapper">
                            <div class="avatar-circle" id="avatarPreviewBox">
                                <?php if ($avatar_url): ?>
                                    <img src="<?= htmlspecialchars($avatar_url) ?>" alt="" id="avatarImgPreview">
                                <?php else: ?>
                                    <span id="avatarInitials"><?= $initials ?></span>
                                    <img src="" alt="" id="avatarImgPreview" hidden>
                                <?php endif; ?>
                            </div>
                            <div class="avatar-meta-name"><?= htmlspecialchars($fullname) ?></div>
                            <div class="avatar-meta-sub">@<?= htmlspecialchars($username) ?> &bull; <?= $role ?></div>

                            <div class="avatar-drop-zone" id="dropZone">
                                <input type="file" name="profile_pic" id="profilePicInput"
                                       accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="file-hidden-input" onchange="previewAvatar(this)">
                                <i class="bi bi-cloud-arrow-up-fill"></i>
                                <p>Click to browse photo</p>
                                <span>JPG, PNG, WEBP or GIF &bull; Max 5 MB</span>
                            </div>

                            <button type="submit" class="btn-set-primary" id="saveAvatarBtn" hidden>
                                <i class="bi bi-check2-circle"></i> Save Photo
                            </button>
                        </div>
                    </form>

                    <?php if ($avatar_url): ?>
                    <form method="POST" action="settings.php" onsubmit="return confirm('Remove your profile picture?');">
                        <input type="hidden" name="action" value="remove_avatar">
                        <button type="submit" class="btn-set-danger">
                            <i class="bi bi-trash3"></i> Remove Photo
                        </button>
                    </form>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Account Summary Card -->
            <div class="set-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-shield-check clr-blue"></i> Account Summary
                    </span>
                </div>
                <div class="set-card-body">
                    <div class="acct-summary-row">
                        <span>Member Since</span>
                        <strong><?= $member_since ?></strong>
                    </div>
                    <div class="acct-summary-row">
                        <span>Portal Role</span>
                        <strong><?= $role ?></strong>
                    </div>
                    <div class="acct-summary-row">
                        <span>Account Status</span>
                        <strong class="clr-green"><i class="bi bi-check-circle-fill"></i> <?= ucfirst($data['status'] ?? 'Active') ?></strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── RIGHT: Account Info + Change Password ── -->
        <div>

            <!-- Account Information (Read-Only) -->
            <div class="set-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-person-lines-fill clr-green"></i> Account Information
                    </span>
                    <span class="readonly-tag">
                        <i class="bi bi-lock-fill clr-muted"></i> Read-Only
                    </span>
                </div>
                <div class="set-card-body">
                    <div class="flash-alert flash-alert--info">
                        <i class="bi bi-info-circle-fill clr-green"></i>
                        <span class="flash-note-text">Your account details are managed by the system administrator. Contact support if any information needs to be updated.</span>
                    </div>

                    <div class="readonly-grid">
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-person"></i> First Name</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($data['firstname'] ?? 'N/A') ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-person"></i> Last Name</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($data['lastname'] ?? 'N/A') ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-at"></i> Username</span>
                            <div class="readonly-value-box">
                                <span>@<?= htmlspecialchars($username) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-person-badge"></i> Role</span>
                            <div class="readonly-value-box">
                                <span><?= $role ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>
                        <div class="readonly-field-group span-2">
                            <span class="readonly-label"><i class="bi bi-envelope"></i> Email Address</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($user_email) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="set-card" id="password-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-key-fill clr-orange"></i> Change Password
                    </span>
                </div>
                <div class="set-card-body">

                    <?php if ($pw_success): ?>
                        <div class="flash-alert success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($pw_success) ?></div>
                    <?php endif; ?>
                    <?php if ($pw_error): ?>
                        <div class="flash-alert error"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($pw_error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="settings.php" id="pwForm" onsubmit="return validatePwForm()">
                        <input type="hidden" name="action" value="change_password">

                        <div class="pw-form-group">
                            <label class="pw-label">Current Password</label>
                            <div class="pw-input-wrap">
                                <i class="bi bi-lock prefix-icon"></i>
                                <input type="password" name="current_password" id="currentPw" class="pw-input-field" placeholder="Enter current password" required>
                                <button type="button" class="pw-toggle-btn" onclick="togglePw('currentPw',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="pw-requirements-box">
                            <div class="pw-req-title"><i class="bi bi-shield-lock"></i> Password Requirements</div>
                            <ul class="pw-req-list">
                                <li class="pw-req-item" id="req-len"><i class="bi bi-circle"></i> At least 8 characters</li>
                                <li class="pw-req-item" id="req-upper"><i class="bi bi-circle"></i> One uppercase letter</li>
                                <li class="pw-req-item" id="req-lower"><i class="bi bi-circle"></i> One lowercase letter</li>
                                <li class="pw-req-item" id="req-num"><i class="bi bi-circle"></i> One number</li>
                            </ul>
                        </div>

                        <div class="pw-form-group">
                            <label class="pw-label">New Password</label>
                            <div class="pw-input-wrap">
                                <i class="bi bi-lock-fill prefix-icon"></i>
                                <input type="password" name="new_password" id="newPw" class="pw-input-field" placeholder="Create a new password" oninput="checkReqs(this.value)" required>
                                <button type="button" class="pw-toggle-btn" onclick="togglePw('newPw',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="pw-form-group">
                            <label class="pw-label">Confirm New Password</label>
                            <div class="pw-input-wrap">
                                <i class="bi bi-lock-fill prefix-icon"></i>
                                <input type="password" name="confirm_password" id="confirmPw" class="pw-input-field" placeholder="Repeat new password" required>
                                <button type="button" class="pw-toggle-btn" onclick="togglePw('confirmPw',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <button type="submit" class="btn-set-primary btn-set-primary--auto">
                            <i class="bi bi-shield-check"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

        </div><!-- /.right col -->
    </div><!-- /.settings-grid -->

</div>
</main>

<script>
    function previewAvatar(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const img      = document.getElementById('avatarImgPreview');
            const initials = document.getElementById('avatarInitials');
            img.src        = e.target.result;
            img.style.display = 'block';
            if (initials) initials.style.display = 'none';
            document.getElementById('saveAvatarBtn').style.display = 'flex';
        };
        reader.readAsDataURL(input.files[0]);
    }

    function togglePw(id, btn) {
        const input = document.getElementById(id);
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.querySelector('i').className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
    }

    function checkReqs(val) {
        const set = (id, ok) => {
            const el = document.getElementById(id);
            el.classList.toggle('valid', ok);
            el.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
        };
        set('req-len',   val.length >= 8);
        set('req-upper', /[A-Z]/.test(val));
        set('req-lower', /[a-z]/.test(val));
        set('req-num',   /[0-9]/.test(val));
    }

    function validatePwForm() {
        const np = document.getElementById('newPw').value;
        const cp = document.getElementById('confirmPw').value;
        if (np !== cp) { alert('Passwords do not match.'); return false; }
        return true;
    }

    // Drag-over effect
    const dz = document.getElementById('dropZone');
    if (dz) {
        dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
        dz.addEventListener('drop',      e => { e.preventDefault(); dz.classList.remove('dragover'); const f = e.dataTransfer.files[0]; if (f) { document.getElementById('profilePicInput').files = e.dataTransfer.files; previewAvatar(document.getElementById('profilePicInput')); } });
    }
</script>

</body>
</html>
