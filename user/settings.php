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
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        .dash-content { max-width:100%; overflow-x:hidden; }

        .settings-grid {
            display: grid;
            grid-template-columns: 320px minmax(0,1fr);
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) { .settings-grid { grid-template-columns: 1fr; } }

        /* ── Cards ── */
        .set-card {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.06);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .set-card-head {
            padding: 16px 22px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .set-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .set-card-body { padding: 22px; }

        /* ── Avatar ── */
        .avatar-preview-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 6px 0 16px;
        }
        .avatar-circle {
            width: 114px;
            height: 114px;
            border-radius: 50%;
            border: 3px solid #1f7a3d;
            box-shadow: 0 4px 16px rgba(31,122,61,.15);
            background: #f0f7f2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 800;
            color: #1f7a3d;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .avatar-circle img { width:100%; height:100%; object-fit:cover; display:block; }
        .avatar-meta-name  { font-size:15px; font-weight:800; color:#06251b; margin-bottom:3px; }
        .avatar-meta-sub   { font-size:12px; color:#88968d; margin-bottom:14px; }
        .avatar-drop-zone {
            border: 2px dashed #d6e2db;
            border-radius: 14px;
            padding: 16px;
            text-align: center;
            background: #fafcfb;
            cursor: pointer;
            transition: all .2s;
            position: relative;
            width: 100%;
            margin-bottom: 12px;
        }
        .avatar-drop-zone:hover { border-color:#1f7a3d; background:#eef7f1; }
        .avatar-drop-zone i  { font-size:24px; color:#1f7a3d; display:block; margin-bottom:5px; }
        .avatar-drop-zone p  { font-size:12px; font-weight:600; color:#06251b; margin:0; }
        .avatar-drop-zone span { font-size:10.5px; color:#88968d; }
        .file-hidden-input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }

        /* ── Buttons ── */
        .btn-set-primary {
            background: #06251b; color: #ffc107; border: none;
            padding: 10px 18px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins',sans-serif;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            transition: all .2s; width: 100%; margin-bottom: 10px;
        }
        .btn-set-primary:hover { background:#144937; color:#fff; transform:translateY(-1px); }
        .btn-set-danger {
            background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2;
            padding: 9px 14px; border-radius: 10px;
            font-size: 12px; font-weight: 700; font-family: 'Poppins',sans-serif;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            transition: all .15s; width: 100%;
        }
        .btn-set-danger:hover { background:#dc2626; color:#fff; }

        /* ── Read-only fields ── */
        .readonly-grid {
            display: grid;
            grid-template-columns: repeat(2,1fr);
            gap: 16px;
        }
        @media (max-width:640px) { .readonly-grid { grid-template-columns:1fr; } }
        .readonly-field-group { display:flex; flex-direction:column; gap:5px; }
        .readonly-field-group.span-2 { grid-column:span 2; }
        @media (max-width:640px) { .readonly-field-group.span-2 { grid-column:span 1; } }
        .readonly-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; color: #88968d;
            display: flex; align-items: center; gap: 6px;
        }
        .readonly-value-box {
            background: #fafcfb; border: 1.5px solid #eef2ef; border-radius: 10px;
            padding: 10px 14px; font-size: 12.5px; font-weight: 600; color: #06251b;
            display: flex; align-items: center; justify-content: space-between; min-height: 42px;
        }
        .readonly-value-box .lock-icon { color:#aab5ae; font-size:12px; }

        /* ── Account summary ── */
        .acct-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f4f2;
            font-size: 12.5px;
            color: #55665a;
        }
        .acct-summary-row:last-child { border-bottom: none; }
        .acct-summary-row strong { color:#06251b; font-weight:700; }

        /* ── Password form ── */
        .pw-form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
        .pw-label { font-size:12px; font-weight:700; color:#06251b; }
        .pw-input-wrap { position:relative; display:flex; align-items:center; }
        .pw-input-wrap i.prefix-icon {
            position:absolute; left:14px; color:#88968d; font-size:14px; pointer-events:none;
        }
        .pw-input-field {
            width: 100%; padding: 10px 42px 10px 38px;
            border: 1.5px solid #d4e0d8; border-radius: 10px;
            font-size: 12.5px; font-family: 'Poppins',sans-serif; color:#1a1a1a;
            outline: none; background: #fff; transition: all .15s;
        }
        .pw-input-field:focus { border-color:#1f7a3d; box-shadow:0 0 0 3px rgba(31,122,61,.1); }
        .pw-toggle-btn {
            position:absolute; right:12px; background:transparent; border:none;
            color:#88968d; cursor:pointer; font-size:14px; padding:4px;
            display:flex; align-items:center; justify-content:center; transition:color .15s;
        }
        .pw-toggle-btn:hover { color:#06251b; }
        .pw-requirements-box {
            background:#fbfdfc; border:1px solid #edf1ee; border-radius:12px;
            padding:14px 16px; margin-bottom:20px;
        }
        .pw-req-title { font-size:11px; font-weight:700; color:#55665a; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
        .pw-req-list {
            list-style:none; padding:0; margin:0;
            display:grid; grid-template-columns:1fr 1fr; gap:6px 14px;
        }
        @media (max-width:600px) { .pw-req-list { grid-template-columns:1fr; } }
        .pw-req-item { font-size:11.5px; color:#88968d; display:flex; align-items:center; gap:6px; transition:color .15s; }
        .pw-req-item.valid { color:#1f7a3d; font-weight:600; }

        /* ── Flash alerts ── */
        .flash-alert {
            padding: 12px 16px; border-radius: 12px; font-size: 12.5px; font-weight: 600;
            margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
            animation: fadeInAlert .2s ease;
        }
        @keyframes fadeInAlert { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }
        .flash-alert.success { background:#eaf7ee; color:#1f7a3d; border:1px solid #c9e8d3; }
        .flash-alert.error   { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Account Settings'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header" style="margin-bottom:24px;">
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
                        <i class="bi bi-person-circle" style="color:#1f7a3d;"></i> Profile Photo
                    </span>
                    <span style="font-size:11px;font-weight:700;color:#88968d;">Avatar</span>
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
                                    <img src="" alt="" id="avatarImgPreview" style="display:none;">
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

                            <button type="submit" class="btn-set-primary" id="saveAvatarBtn" style="display:none;">
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
                        <i class="bi bi-shield-check" style="color:#2F6FED;"></i> Account Summary
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
                        <strong style="color:#1f7a3d;"><i class="bi bi-check-circle-fill"></i> <?= ucfirst($data['status'] ?? 'Active') ?></strong>
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
                        <i class="bi bi-person-lines-fill" style="color:#1f7a3d;"></i> Account Information
                    </span>
                    <span style="font-size:11.5px;font-weight:700;color:#88968d;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-lock-fill" style="color:#aab5ae;"></i> Read-Only
                    </span>
                </div>
                <div class="set-card-body">
                    <div class="flash-alert" style="background:#f4f8f5;border:1px solid #dcebe1;color:#385141;margin-bottom:20px;">
                        <i class="bi bi-info-circle-fill" style="color:#1f7a3d;flex-shrink:0;"></i>
                        <span style="font-size:12px;">Your account details are managed by the system administrator. Contact support if any information needs to be updated.</span>
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
                        <i class="bi bi-key-fill" style="color:#e67e22;"></i> Change Password
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
                            <div class="pw-req-title"><i class="bi bi-shield-lock" style="margin-right:4px;"></i> Password Requirements</div>
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

                        <button type="submit" class="btn-set-primary" style="width:auto; padding:10px 28px;">
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
