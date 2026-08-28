<?php
include("utils/protect-page.php");

$user_id         = (int)$_SESSION['user_id'];
$superadmin_role = $_SESSION['role'] ?? 'superadmin';

// ─────────────────────────────────────────────────────────────────────────────
// 1. HANDLE PROFILE PICTURE UPLOAD
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    $avatar_error   = '';
    $avatar_success = '';

    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['profile_pic']['tmp_name'];
        $file_name = $_FILES['profile_pic']['name'];
        $file_size = $_FILES['profile_pic']['size'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $max_size     = 5 * 1024 * 1024; // 5 MB

        if (!in_array($ext, $allowed_exts)) {
            $avatar_error = "Invalid file type. Please upload a JPG, JPEG, PNG, WEBP, or GIF image.";
        } elseif ($file_size > $max_size) {
            $avatar_error = "Image size exceeds the 5 MB limit. Please choose a smaller photo.";
        } else {
            $img_info = @getimagesize($file_tmp);
            if ($img_info === false) {
                $avatar_error = "Uploaded file is not a valid image.";
            } else {
                $upload_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }

                $new_filename = 'avatar_superadmin_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target_path  = $upload_dir . DIRECTORY_SEPARATOR . $new_filename;
                $db_rel_path  = 'uploads/profile/' . $new_filename;

                if (move_uploaded_file($file_tmp, $target_path)) {
                    $old_stmt = $conn->prepare("SELECT profile_picture_url FROM users WHERE user_id = ?");
                    $old_stmt->bind_param("i", $user_id);
                    $old_stmt->execute();
                    $old_row = $old_stmt->get_result()->fetch_assoc();
                    $old_stmt->close();

                    if (!empty($old_row['profile_picture_url'])) {
                        $old_file = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old_row['profile_picture_url']);
                        if (file_exists($old_file) && is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }

                    $upd_stmt = $conn->prepare("UPDATE users SET profile_picture_url = ? WHERE user_id = ?");
                    $upd_stmt->bind_param("si", $db_rel_path, $user_id);
                    if ($upd_stmt->execute()) {
                        $_SESSION['profile_picture_url'] = $db_rel_path;
                        $avatar_success = "Profile photo updated successfully!";
                    } else {
                        $avatar_error = "Failed to save profile photo in database.";
                    }
                    $upd_stmt->close();
                } else {
                    $avatar_error = "Failed to move uploaded photo to uploads/profile folder.";
                }
            }
        }
    } else {
        $avatar_error = "Please select an image file to upload.";
    }

    $_SESSION['avatar_error']   = $avatar_error;
    $_SESSION['avatar_success'] = $avatar_success;
    header("Location: settings.php?tab=profile#avatar-card");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. HANDLE REMOVE PROFILE PICTURE
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_avatar') {
    $old_stmt = $conn->prepare("SELECT profile_picture_url FROM users WHERE user_id = ?");
    $old_stmt->bind_param("i", $user_id);
    $old_stmt->execute();
    $old_row = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (!empty($old_row['profile_picture_url'])) {
        $old_file = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old_row['profile_picture_url']);
        if (file_exists($old_file) && is_file($old_file)) {
            @unlink($old_file);
        }
    }

    $upd_stmt = $conn->prepare("UPDATE users SET profile_picture_url = NULL WHERE user_id = ?");
    $upd_stmt->bind_param("i", $user_id);
    $upd_stmt->execute();
    $upd_stmt->close();

    unset($_SESSION['profile_picture_url']);
    $_SESSION['avatar_success'] = "Profile picture removed.";
    header("Location: settings.php?tab=profile#avatar-card");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. HANDLE UPDATE PROFILE INFORMATION (Name & Email editable, Username locked)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname  = trim($_POST['lastname'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    $prof_error   = '';
    $prof_success = '';

    if (empty($firstname) || empty($lastname)) {
        $prof_error = "First name and last name are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $prof_error = "Please provide a valid official email address.";
    } else {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $chk->bind_param("si", $email, $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $prof_error = "The email address is already in use by another account.";
        } else {
            $upd = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, email = ? WHERE user_id = ?");
            $upd->bind_param("sssi", $firstname, $lastname, $email, $user_id);
            if ($upd->execute()) {
                $prof_success = "Profile information updated successfully!";
                $_SESSION['firstname'] = $firstname;
                $_SESSION['lastname']  = $lastname;
                $_SESSION['email']     = $email;
            } else {
                $prof_error = "Failed to update profile information. Please try again.";
            }
            $upd->close();
        }
        $chk->close();
    }

    $_SESSION['prof_error']   = $prof_error;
    $_SESSION['prof_success'] = $prof_success;
    header("Location: settings.php?tab=profile#profile-card");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. HANDLE CHANGE PASSWORD
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['change_password']) || (isset($_POST['action']) && $_POST['action'] === 'change_password'))) {
    $current_pw = $_POST['current_pw'] ?? ($_POST['current_password'] ?? '');
    $new_pw     = $_POST['new_pw']     ?? ($_POST['new_password'] ?? '');
    $confirm_pw = $_POST['confirm_pw'] ?? ($_POST['confirm_password'] ?? '');

    $pw_error   = '';
    $pw_success = '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user_row || !password_verify($current_pw, $user_row['password'])) {
        $pw_error = "The current password you entered is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $pw_error = "New password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_pw) || !preg_match('/[a-z]/', $new_pw) || !preg_match('/[0-9]/', $new_pw)) {
        $pw_error = "New password must contain uppercase letters, lowercase letters, and at least one number.";
    } elseif ($new_pw !== $confirm_pw) {
        $pw_error = "New password and confirmation password do not match.";
    } elseif (password_verify($new_pw, $user_row['password'])) {
        $pw_error = "New password cannot be the same as your current password.";
    } else {
        $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $upd_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd_stmt->bind_param("si", $new_hash, $user_id);
        if ($upd_stmt->execute()) {
            $pw_success = "Your password has been changed successfully.";
        } else {
            $pw_error = "An error occurred while updating your password. Please try again.";
        }
        $upd_stmt->close();
    }

    $_SESSION['pw_error']   = $pw_error;
    $_SESSION['pw_success'] = $pw_success;
    header("Location: settings.php?tab=profile#password-card");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. HANDLE SAVE ORGANIZATION SETTINGS (SYSTEM TAB)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_org'])) {
    $fields = ['org_name', 'short_name', 'official_website', 'contact_email', 'address'];
    $stmt   = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                               VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        $stmt->bind_param("ss", $key, $val);
        $stmt->execute();
    }
    $stmt->close();
    $_SESSION['org_success'] = "Organization info saved successfully!";
    header("Location: settings.php?tab=system");
    exit();
}

// Flash Messages
$avatar_error   = $_SESSION['avatar_error']   ?? ''; unset($_SESSION['avatar_error']);
$avatar_success = $_SESSION['avatar_success'] ?? ''; unset($_SESSION['avatar_success']);
$prof_error     = $_SESSION['prof_error']     ?? ''; unset($_SESSION['prof_error']);
$prof_success   = $_SESSION['prof_success']   ?? ''; unset($_SESSION['prof_success']);
$pw_error       = $_SESSION['pw_error']       ?? ''; unset($_SESSION['pw_error']);
$pw_success     = $_SESSION['pw_success']     ?? ''; unset($_SESSION['pw_success']);
$org_success    = $_SESSION['org_success']    ?? ''; unset($_SESSION['org_success']);

// ─────────────────────────────────────────────────────────────────────────────
// 6. FETCH CURRENT USER AND SYSTEM DATA
// ─────────────────────────────────────────────────────────────────────────────
$u_stmt = $conn->prepare("SELECT user_id, firstname, lastname, username, email, role, status, profile_picture_url, created_at FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$current_user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$firstname    = $current_user['firstname'] ?? '';
$lastname     = $current_user['lastname']  ?? '';
$fullname     = trim($firstname . ' ' . $lastname) ?: ($current_user['username'] ?? 'Super Admin');
$username     = $current_user['username'] ?? 'N/A';
$admin_email  = $current_user['email'] ?? 'N/A';
$role         = strtoupper($current_user['role'] ?? 'SUPERADMIN');
$status       = ucfirst($current_user['status'] ?? 'Active');
$avatar_url   = !empty($current_user['profile_picture_url']) ? '../' . ltrim($current_user['profile_picture_url'], '/') : '';
$member_since = !empty($current_user['created_at']) ? date('F j, Y', strtotime($current_user['created_at'])) : 'N/A';

$initials = strtoupper(substr($firstname ?: 'S', 0, 1) . substr($lastname ?: 'A', 0, 1));

// Load System Settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res) {
    while ($r = $res->fetch_assoc()) $settings[$r['setting_key']] = $r['setting_value'];
}

function setting(array $s, string $k, string $default = ''): string {
    return htmlspecialchars($s[$k] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System &amp; Account Settings | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    
    <style>
        /* ── Base Container & Tabs Layout ── */
        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        .set-layout {
            display: grid;
            grid-template-columns: 230px 1fr;
            gap: 24px;
            align-items: flex-start;
        }

        @media (max-width: 900px) {
            .set-layout {
                grid-template-columns: 1fr;
            }
            .set-sidenav {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 10px;
                position: static !important;
            }
            .set-nav-section {
                display: none;
            }
        }

        /* ── Side Navigation ── */
        .set-sidenav {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            padding: 12px 10px;
            position: sticky;
            top: 84px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03);
        }

        .set-nav-section {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: #88968d;
            padding: 10px 14px 4px;
        }

        .set-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 11px;
            font-size: 13px;
            font-weight: 600;
            color: #4a5e54;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            transition: all .15s ease;
            font-family: 'Poppins', sans-serif;
            margin-bottom: 2px;
        }

        .set-nav-item i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
            color: #6b7c72;
        }

        .set-nav-item:hover {
            background: #f0f4f2;
            color: #06251b;
        }

        .set-nav-item.active {
            background: #06251b;
            color: #ffc107;
        }

        .set-nav-item.active i {
            color: #ffc107;
        }

        /* ── Panels ── */
        .set-panel {
            display: none;
            flex-direction: column;
            gap: 22px;
        }

        .set-panel.active {
            display: flex;
        }

        /* ── Profile 2-Column Grid ── */
        .settings-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ── Setting Cards ── */
        .set-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.06);
            overflow: hidden;
            margin-bottom: 20px;
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

        .set-card-body {
            padding: 22px;
        }

        /* ── Avatar Upload Section ── */
        .avatar-preview-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 8px 0 16px;
        }

        .avatar-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 3px solid #1f7a3d;
            box-shadow: 0 4px 16px rgba(31, 122, 61, 0.15);
            background: #f0f7f2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 800;
            color: #1f7a3d;
            overflow: hidden;
            position: relative;
            margin-bottom: 14px;
        }

        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .avatar-meta-name {
            font-size: 16px;
            font-weight: 800;
            color: #06251b;
            margin-bottom: 3px;
        }

        .avatar-meta-sub {
            font-size: 12px;
            color: #88968d;
            margin-bottom: 14px;
        }

        .avatar-drop-zone {
            border: 2px dashed #d6e2db;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            background: #fafcfb;
            cursor: pointer;
            transition: all .2s ease;
            position: relative;
            width: 100%;
            margin-bottom: 14px;
        }

        .avatar-drop-zone:hover, .avatar-drop-zone.dragover {
            border-color: #1f7a3d;
            background: #eef7f1;
        }

        .avatar-drop-zone i {
            font-size: 26px;
            color: #1f7a3d;
            display: block;
            margin-bottom: 6px;
        }

        .avatar-drop-zone p {
            font-size: 12px;
            font-weight: 600;
            color: #06251b;
            margin: 0;
        }

        .avatar-drop-zone span {
            font-size: 10.5px;
            color: #88968d;
        }

        .file-hidden-input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .btn-set-primary {
            background: #06251b;
            color: #ffc107;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: all .2s ease;
            width: 100%;
        }

        .btn-set-primary:hover {
            background: #144937;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.15);
        }

        .btn-set-danger {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
            padding: 9px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .15s ease;
            width: 100%;
        }

        .btn-set-danger:hover {
            background: #dc2626;
            color: #ffffff;
        }

        /* ── Profile Information (Editable & Locked Form) ── */
        .info-alert-box {
            background: #f4f8f5;
            border: 1px solid #dcebe1;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .info-alert-box i {
            font-size: 18px;
            color: #1f7a3d;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .info-alert-text {
            font-size: 12px;
            color: #385141;
            line-height: 1.45;
        }

        .info-alert-text strong {
            color: #06251b;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (max-width: 640px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        .form-field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }

        .form-field-group.span-2 {
            grid-column: span 2;
        }

        @media (max-width: 640px) {
            .form-field-group.span-2 {
                grid-column: span 1;
            }
        }

        .field-label {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #55665a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .field-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .field-input-wrap i.prefix-icon {
            position: absolute;
            left: 14px;
            color: #88968d;
            font-size: 14px;
            pointer-events: none;
        }

        .field-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1.5px solid #d4e0d8;
            border-radius: 10px;
            font-size: 12.5px;
            font-family: 'Poppins', sans-serif;
            color: #1a1a1a;
            outline: none;
            background: #ffffff;
            transition: all .15s ease;
        }

        .field-input:focus {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 3px rgba(31,122,61,0.1);
        }

        .field-input.locked {
            background: #fafcfb;
            border: 1.5px solid #eef2ef;
            color: #4a5a50;
            cursor: not-allowed;
            padding-right: 36px;
            font-weight: 600;
        }

        .locked-badge-icon {
            position: absolute;
            right: 14px;
            color: #aab5ae;
            font-size: 13px;
            pointer-events: none;
        }

        .status-pill-badge {
            font-size: 11px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase;
        }

        .status-pill-badge.active      { background: #e4f5ea; color: #1f7a3d; }
        .status-pill-badge.superadmin  { background: #f3e5f5; color: #7b1fa2; }

        /* ── Password Change Form ── */
        .pw-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }

        .pw-label {
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
        }

        .pw-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .pw-input-wrap i.prefix-icon {
            position: absolute;
            left: 14px;
            color: #88968d;
            font-size: 14px;
            pointer-events: none;
        }

        .pw-input-field {
            width: 100%;
            padding: 10px 42px 10px 38px;
            border: 1.5px solid #d4e0d8;
            border-radius: 10px;
            font-size: 12.5px;
            font-family: 'Poppins', sans-serif;
            color: #1a1a1a;
            outline: none;
            background: #ffffff;
            transition: all .15s ease;
        }

        .pw-input-field:focus {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 3px rgba(31,122,61,0.1);
        }

        .pw-toggle-btn {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #88968d;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color .15s;
        }

        .pw-toggle-btn:hover {
            color: #06251b;
        }

        .pw-requirements-box {
            background: #fbfdfc;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .pw-req-title {
            font-size: 11px;
            font-weight: 700;
            color: #55665a;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 8px;
        }

        .pw-req-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 14px;
        }

        @media (max-width: 600px) {
            .pw-req-list {
                grid-template-columns: 1fr;
            }
        }

        .pw-req-item {
            font-size: 11.5px;
            color: #88968d;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color .15s;
        }

        .pw-req-item.valid {
            color: #1f7a3d;
            font-weight: 600;
        }

        .pw-req-item i {
            font-size: 13px;
        }

        /* ── Alert Flash Messages ── */
        .flash-alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInAlert .2s ease;
        }

        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .flash-alert.success {
            background: #eaf7ee;
            color: #1f7a3d;
            border: 1px solid #c9e8d3;
        }

        .flash-alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* ── Maintenance Info List ── */
        .maint-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid #f0f4f2;
        }
        .maint-row:last-child {
            border-bottom: none;
        }
        .maint-lbl {
            font-size: 13px;
            font-weight: 600;
            color: #4a5e54;
        }
        .maint-badge {
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            font-family: 'Space Grotesk', sans-serif;
        }
        .maint-badge.green  { background: #e4f5ea; color: #1f7a3d; }
        .maint-badge.blue   { background: #e7eefe; color: #2F6FED; }
        .maint-badge.yellow { background: #fef3c7; color: #d97706; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>

<?php 
$topbar_title = 'Settings';
include("components/topbar.php"); 
?>

<!-- ════════════════════════════════════════════════════════════
     MAIN CONTENT
     ════════════════════════════════════════════════════════════ -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header" style="margin-bottom:24px;">
        <h2>System &amp; Account Settings</h2>
        <p>Configure institutional procurement parameters, manage administrator credentials, and access maintenance tools.</p>
    </div>

    <div class="set-layout">

        <!-- ── SIDE NAVIGATION TABS ── -->
        <div class="set-sidenav">
            <div class="set-nav-section">General</div>
            <button type="button" class="set-nav-item active" onclick="switchTab('system', this)">
                <i class="bi bi-building"></i> System Config
            </button>
            <button type="button" class="set-nav-item" onclick="switchTab('profile', this)">
                <i class="bi bi-person-circle"></i> My Profile
            </button>
            <div class="set-nav-section">System Administration</div>
            <button type="button" class="set-nav-item" onclick="switchTab('maintenance', this)">
                <i class="bi bi-tools"></i> Maintenance
            </button>
        </div>

        <!-- ── TAB PANELS CONTAINER ── -->
        <div style="min-width:0;">

            <!-- ════════════════════════════════════════════════════════════
                 TAB 1: SYSTEM & ORGANIZATION CONFIG
                 ════════════════════════════════════════════════════════════ -->
            <div class="set-panel active" id="panel-system">
                <div class="set-card">
                    <div class="set-card-head">
                        <span class="set-card-title">
                            <i class="bi bi-building-gear" style="color:#1f7a3d;"></i> Institutional &amp; Organization Parameters
                        </span>
                        <span style="font-size:11px; font-weight:700; color:#88968d;">Global Config</span>
                    </div>

                    <div class="set-card-body">
                        <?php if (!empty($org_success)): ?>
                            <div class="flash-alert success">
                                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($org_success) ?>
                            </div>
                        <?php endif; ?>

                        <div class="info-alert-box">
                            <i class="bi bi-info-circle-fill"></i>
                            <div class="info-alert-text">
                                <strong>System Identity:</strong> These official organization details appear on public procurement notices, bid invitation exports, and broadcast notifications.
                            </div>
                        </div>

                        <form method="POST" action="settings.php">
                            <input type="hidden" name="save_org" value="1">

                            <div class="form-grid-2">
                                
                                <div class="form-field-group">
                                    <label class="field-label" for="org_name"><i class="bi bi-building"></i> Organization Name</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-building prefix-icon"></i>
                                        <input type="text" name="org_name" id="org_name" class="field-input" value="<?= setting($settings, 'org_name', 'YesParency') ?>" placeholder="e.g. Provincial Government / Agency">
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="short_name"><i class="bi bi-tag"></i> Short Name / Acronym</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-tag prefix-icon"></i>
                                        <input type="text" name="short_name" id="short_name" class="field-input" value="<?= setting($settings, 'short_name', 'YSP') ?>" placeholder="e.g. BAC, LGU, DBM">
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="official_website"><i class="bi bi-globe"></i> Official Website URL</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-globe prefix-icon"></i>
                                        <input type="url" name="official_website" id="official_website" class="field-input" value="<?= setting($settings, 'official_website') ?>" placeholder="https://example.gov.ph">
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="contact_email"><i class="bi bi-envelope"></i> Secretariat Contact Email</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-envelope prefix-icon"></i>
                                        <input type="email" name="contact_email" id="contact_email" class="field-input" value="<?= setting($settings, 'contact_email') ?>" placeholder="bac.secretariat@example.gov.ph">
                                    </div>
                                </div>

                                <div class="form-field-group span-2">
                                    <label class="field-label" for="address"><i class="bi bi-geo-alt"></i> Official Physical Office Address</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-geo-alt prefix-icon"></i>
                                        <input type="text" name="address" id="address" class="field-input" value="<?= setting($settings, 'address') ?>" placeholder="Full official address of the procuring entity...">
                                    </div>
                                </div>

                            </div>

                            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                                <button type="submit" class="btn-set-primary" style="width:auto; padding:10px 24px;">
                                    <i class="bi bi-check2-circle"></i> Save System Parameters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 TAB 2: MY PROFILE (Matching Admin Settings with 2-Column Grid)
                 ════════════════════════════════════════════════════════════ -->
            <div class="set-panel" id="panel-profile">
                <div class="settings-grid">

                    <!-- Left Column: Avatar & Account Summary -->
                    <div class="settings-col-left">

                        <!-- Profile Photo Card -->
                        <div class="set-card" id="avatar-card">
                            <div class="set-card-head">
                                <span class="set-card-title">
                                    <i class="bi bi-person-badge-fill" style="color:#1f7a3d;"></i> Profile Photo
                                </span>
                                <span style="font-size:11px; font-weight:700; color:#88968d;">Avatar</span>
                            </div>
                            
                            <div class="set-card-body">
                                <?php if (!empty($avatar_success)): ?>
                                    <div class="flash-alert success">
                                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($avatar_success) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($avatar_error)): ?>
                                    <div class="flash-alert error">
                                        <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($avatar_error) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="settings.php?tab=profile" enctype="multipart/form-data" id="avatarForm">
                                    <input type="hidden" name="action" value="upload_avatar">
                                    
                                    <div class="avatar-preview-wrapper">
                                        <div class="avatar-circle" id="avatarPreviewBox">
                                            <?php if (!empty($avatar_url)): ?>
                                                <img src="<?= htmlspecialchars($avatar_url) ?>" alt="<?= htmlspecialchars($fullname) ?>" id="avatarImgPreview">
                                            <?php else: ?>
                                                <span id="avatarInitials"><?= $initials ?></span>
                                                <img src="" alt="Preview" id="avatarImgPreview" style="display:none;">
                                            <?php endif; ?>
                                        </div>

                                        <div class="avatar-meta-name"><?= htmlspecialchars($fullname) ?></div>
                                        <div class="avatar-meta-sub">@<?= htmlspecialchars($username) ?> &bull; Super Administrator</div>

                                        <!-- Drag-and-Drop / File Picker Zone -->
                                        <div class="avatar-drop-zone" id="dropZone">
                                            <input type="file" name="profile_pic" id="profilePicInput" accept="image/jpeg,image/png,image/webp,image/gif" class="file-hidden-input" onchange="previewAvatar(this)">
                                            <i class="bi bi-cloud-arrow-up-fill"></i>
                                            <p>Click to browse photo</p>
                                            <span>JPG, PNG, WEBP or GIF (Max 5MB)</span>
                                        </div>

                                        <button type="submit" class="btn-set-primary" id="saveAvatarBtn" style="display:none; margin-bottom:10px;">
                                            <i class="bi bi-check2-circle"></i> Save Photo
                                        </button>
                                    </div>
                                </form>

                                <?php if (!empty($avatar_url)): ?>
                                    <form method="POST" action="settings.php?tab=profile" onsubmit="return confirm('Are you sure you want to remove your profile picture?');">
                                        <input type="hidden" name="action" value="remove_avatar">
                                        <button type="submit" class="btn-set-danger">
                                            <i class="bi bi-trash3"></i> Remove Photo
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Security Card -->
                        <div class="set-card">
                            <div class="set-card-head">
                                <span class="set-card-title">
                                    <i class="bi bi-shield-check" style="color:#7b1fa2;"></i> Privileges
                                </span>
                                <span class="status-pill-badge superadmin">SUPERADMIN</span>
                            </div>
                            <div class="set-card-body" style="font-size:12px; color:#55665a; line-height:1.6;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                                    <span>Member Since:</span>
                                    <strong style="color:#06251b;"><?= $member_since ?></strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                                    <span>Access Tier:</span>
                                    <strong style="color:#7b1fa2;">Full Root Access</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Security Status:</span>
                                    <span style="color:#1f7a3d; font-weight:700;"><i class="bi bi-shield-lock-fill"></i> Maximum</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Right Column: Personal Information (Editable + Locked Username) + Change Password -->
                    <div class="settings-col-right">

                        <!-- Personal Information Card -->
                        <div class="set-card" id="profile-card">
                            <div class="set-card-head">
                                <span class="set-card-title">
                                    <i class="bi bi-person-lines-fill" style="color:#1f7a3d;"></i> Personal &amp; Super Admin Credentials
                                </span>
                                <span style="font-size:11.5px; font-weight:700; color:#88968d;">Admin Identity</span>
                            </div>

                            <div class="set-card-body">
                                <?php if (!empty($prof_success)): ?>
                                    <div class="flash-alert success">
                                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($prof_success) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($prof_error)): ?>
                                    <div class="flash-alert error">
                                        <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($prof_error) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="info-alert-box">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <div class="info-alert-text">
                                        <strong>Account Details:</strong> You can modify your full name and official contact email address. System username and role credentials remain locked to preserve database consistency.
                                    </div>
                                </div>

                                <form method="POST" action="settings.php?tab=profile">
                                    <input type="hidden" name="action" value="update_profile">

                                    <div class="form-grid-2">
                                        
                                        <!-- First Name -->
                                        <div class="form-field-group">
                                            <label class="field-label" for="firstname"><i class="bi bi-person"></i> First Name</label>
                                            <div class="field-input-wrap">
                                                <i class="bi bi-person prefix-icon"></i>
                                                <input type="text" name="firstname" id="firstname" required class="field-input" value="<?= htmlspecialchars($firstname) ?>" placeholder="Enter first name">
                                            </div>
                                        </div>

                                        <!-- Last Name -->
                                        <div class="form-field-group">
                                            <label class="field-label" for="lastname"><i class="bi bi-person"></i> Last Name</label>
                                            <div class="field-input-wrap">
                                                <i class="bi bi-person prefix-icon"></i>
                                                <input type="text" name="lastname" id="lastname" required class="field-input" value="<?= htmlspecialchars($lastname) ?>" placeholder="Enter last name">
                                            </div>
                                        </div>

                                        <!-- Username (LOCKED) -->
                                        <div class="form-field-group">
                                            <label class="field-label" for="username"><i class="bi bi-at"></i> Portal Username (Locked)</label>
                                            <div class="field-input-wrap">
                                                <i class="bi bi-at prefix-icon"></i>
                                                <input type="text" id="username" class="field-input locked" value="<?= htmlspecialchars($username) ?>" readonly disabled title="Username cannot be changed">
                                                <i class="bi bi-lock-fill locked-badge-icon"></i>
                                            </div>
                                        </div>

                                        <!-- Role (LOCKED) -->
                                        <div class="form-field-group">
                                            <label class="field-label" for="role"><i class="bi bi-shield-check"></i> Assigned Role (Locked)</label>
                                            <div class="field-input-wrap">
                                                <i class="bi bi-shield-check prefix-icon"></i>
                                                <input type="text" id="role" class="field-input locked" value="Super Administrator" readonly disabled title="Super Administrator Role">
                                                <i class="bi bi-lock-fill locked-badge-icon"></i>
                                            </div>
                                        </div>

                                        <!-- Email (Editable) -->
                                        <div class="form-field-group span-2">
                                            <label class="field-label" for="email"><i class="bi bi-envelope"></i> Official Contact Email</label>
                                            <div class="field-input-wrap">
                                                <i class="bi bi-envelope prefix-icon"></i>
                                                <input type="email" name="email" id="email" required class="field-input" value="<?= htmlspecialchars($admin_email) ?>" placeholder="superadmin@domain.gov.ph">
                                            </div>
                                        </div>

                                    </div>

                                    <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                                        <button type="submit" class="btn-set-primary" style="width:auto; padding:10px 24px;">
                                            <i class="bi bi-check2-circle"></i> Save Profile Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Change Password Card -->
                        <div class="set-card" id="password-card">
                            <div class="set-card-head">
                                <span class="set-card-title">
                                    <i class="bi bi-key-fill" style="color:#d97706;"></i> Change Password
                                </span>
                                <span style="font-size:11px; font-weight:700; color:#88968d;">Security</span>
                            </div>

                            <div class="set-card-body">
                                <?php if (!empty($pw_success)): ?>
                                    <div class="flash-alert success">
                                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($pw_success) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($pw_error)): ?>
                                    <div class="flash-alert error">
                                        <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($pw_error) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="settings.php?tab=profile" id="changePasswordForm" onsubmit="return validatePasswordForm(event)">
                                    <input type="hidden" name="action" value="change_password">

                                    <!-- Current Password -->
                                    <div class="pw-form-group">
                                        <label class="pw-label" for="current_password">Current Password</label>
                                        <div class="pw-input-wrap">
                                            <i class="bi bi-shield-lock prefix-icon"></i>
                                            <input type="password" name="current_password" id="current_password" required class="pw-input-field" placeholder="Enter current password">
                                            <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('current_password', this)" aria-label="Toggle password visibility">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- New Password -->
                                    <div class="pw-form-group">
                                        <label class="pw-label" for="new_password">New Password</label>
                                        <div class="pw-input-wrap">
                                            <i class="bi bi-key prefix-icon"></i>
                                            <input type="password" name="new_password" id="new_password" required class="pw-input-field" placeholder="Enter new strong password" oninput="checkPasswordStrength(this.value)">
                                            <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('new_password', this)" aria-label="Toggle password visibility">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Password Requirements Checklist -->
                                    <div class="pw-requirements-box">
                                        <div class="pw-req-title">Password Requirements:</div>
                                        <ul class="pw-req-list">
                                            <li class="pw-req-item" id="req-length"><i class="bi bi-circle"></i> At least 8 characters</li>
                                            <li class="pw-req-item" id="req-upper"><i class="bi bi-circle"></i> Uppercase letter (A-Z)</li>
                                            <li class="pw-req-item" id="req-lower"><i class="bi bi-circle"></i> Lowercase letter (a-z)</li>
                                            <li class="pw-req-item" id="req-num"><i class="bi bi-circle"></i> At least one number (0-9)</li>
                                        </ul>
                                    </div>

                                    <!-- Confirm New Password -->
                                    <div class="pw-form-group">
                                        <label class="pw-label" for="confirm_password">Confirm New Password</label>
                                        <div class="pw-input-wrap">
                                            <i class="bi bi-check2-circle prefix-icon"></i>
                                            <input type="password" name="confirm_password" id="confirm_password" required class="pw-input-field" placeholder="Re-enter new password" oninput="checkPasswordMatch()">
                                            <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div id="match-hint" style="font-size:11.5px; margin-top:4px; display:none;"></div>
                                    </div>

                                    <div style="display:flex; justify-content:flex-end; margin-top:20px;">
                                        <button type="submit" class="btn-set-primary" style="width:auto; padding:10px 24px;">
                                            <i class="bi bi-shield-check"></i> Update Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 TAB 3: SYSTEM MAINTENANCE & RUNTIME ENVIRONMENT
                 ════════════════════════════════════════════════════════════ -->
            <div class="set-panel" id="panel-maintenance">
                
                <!-- Environment Info -->
                <div class="set-card">
                    <div class="set-card-head">
                        <span class="set-card-title">
                            <i class="bi bi-hdd-network" style="color:#2F6FED;"></i> System Runtime &amp; Server Environment
                        </span>
                        <span style="font-size:11px; font-weight:700; color:#88968d;">Diagnostics</span>
                    </div>

                    <div class="set-card-body" style="padding:0;">
                        <?php
                        $sys_info = [
                            ['Application Engine', 'YesParency Transparency Portal', 'green'],
                            ['Platform Version',   'v2.4.0 (Enterprise)',             'blue'],
                            ['PHP Runtime',        phpversion(),                      'blue'],
                            ['Web Server Host',    $_SERVER['SERVER_SOFTWARE'] ?? 'Apache/XAMPP', null],
                            ['Environment Mode',   'Local / Development',             'yellow'],
                            ['Database Status',    'MySQL / MariaDB Connected',       'green'],
                            ['System Timestamp',   date('F j, Y, g:i a'),             null],
                        ];
                        foreach ($sys_info as $row):
                        ?>
                            <div class="maint-row">
                                <span class="maint-lbl"><?= htmlspecialchars($row[0]) ?></span>
                                <?php if (!empty($row[2])): ?>
                                    <span class="maint-badge <?= $row[2] ?>"><?= htmlspecialchars($row[1]) ?></span>
                                <?php else: ?>
                                    <span style="font-size:12.5px; color:#6c776e; font-weight:600;"><?= htmlspecialchars($row[1]) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Danger Zone -->
                <div class="set-card" style="border-color:#fee2e2;">
                    <div class="set-card-head" style="background:#fef2f2; border-bottom-color:#fecaca;">
                        <span class="set-card-title" style="color:#b91c1c;">
                            <i class="bi bi-exclamation-triangle-fill"></i> Restricted Danger Zone
                        </span>
                        <span style="font-size:11px; font-weight:700; color:#b91c1c;">Superadmin Only</span>
                    </div>

                    <div class="set-card-body">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;">
                            <div style="max-width:550px;">
                                <div style="font-size:13.5px; font-weight:700; color:#06251b; margin-bottom:3px;">System Maintenance Mode</div>
                                <div style="font-size:12px; color:#88968d; line-height:1.45;">
                                    Temporarily restrict portal access for regular bidders and non-admin users during scheduled database updates or migrations.
                                </div>
                            </div>
                            <button type="button" class="btn-set-danger" style="width:auto; padding:9px 18px;" onclick="alert('Maintenance mode toggled for demonstration.')">
                                <i class="bi bi-power"></i> Toggle Mode
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

</div>
</main>

<script>
// ── Tab Switching Logic ──
function switchTab(tab, btn) {
    document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.set-nav-item').forEach(b => b.classList.remove('active'));
    
    const panel = document.getElementById('panel-' + tab);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
    
    // Update URL query string seamlessly
    history.replaceState(null, '', '?tab=' + tab);
}

// Restore tab on load from query param
(function () {
    const params = new URLSearchParams(window.location.search);
    const tab    = params.get('tab') || 'system';
    const panel  = document.getElementById('panel-' + tab);
    if (panel) {
        document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.set-nav-item').forEach(b => b.classList.remove('active'));
        panel.classList.add('active');
        const btn = document.querySelector('.set-nav-item[onclick*="' + tab + '"]');
        if (btn) btn.classList.add('active');
    }
})();

// ── Avatar Preview & Dropzone ──
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        if (file.size > 5 * 1024 * 1024) {
            alert("File size exceeds 5MB limit.");
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById('avatarImgPreview');
            const initials = document.getElementById('avatarInitials');
            const saveBtn = document.getElementById('saveAvatarBtn');

            img.src = e.target.result;
            img.style.display = 'block';
            if (initials) initials.style.display = 'none';

            if (saveBtn) {
                saveBtn.style.display = 'inline-flex';
                saveBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };
        reader.readAsDataURL(file);
    }
}

const dropZone = document.getElementById('dropZone');
if (dropZone) {
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
    });
}

// ── Password Visibility Toggle ──
function togglePasswordVisibility(fieldId, btn) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

// ── Live Password Requirements Checker ──
function checkPasswordStrength(pw) {
    const reqLength = document.getElementById('req-length');
    const reqUpper  = document.getElementById('req-upper');
    const reqLower  = document.getElementById('req-lower');
    const reqNum    = document.getElementById('req-num');

    updateReq(reqLength, pw.length >= 8);
    updateReq(reqUpper, /[A-Z]/.test(pw));
    updateReq(reqLower, /[a-z]/.test(pw));
    updateReq(reqNum, /[0-9]/.test(pw));

    checkPasswordMatch();
}

function updateReq(el, isValid) {
    if (!el) return;
    const icon = el.querySelector('i');
    if (isValid) {
        el.classList.add('valid');
        icon.className = 'bi bi-check-circle-fill';
    } else {
        el.classList.remove('valid');
        icon.className = 'bi bi-circle';
    }
}

function checkPasswordMatch() {
    const newPw = document.getElementById('new_password').value;
    const confirmPw = document.getElementById('confirm_password').value;
    const matchHint = document.getElementById('match-hint');

    if (!confirmPw) {
        matchHint.style.display = 'none';
        return;
    }

    matchHint.style.display = 'block';
    if (newPw === confirmPw) {
        matchHint.innerHTML = '<span style="color:#1f7a3d; font-weight:600;"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
    } else {
        matchHint.innerHTML = '<span style="color:#dc2626; font-weight:600;"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
    }
}

function validatePasswordForm(e) {
    const newPw = document.getElementById('new_password').value;
    const confirmPw = document.getElementById('confirm_password').value;

    if (newPw.length < 8 || !/[A-Z]/.test(newPw) || !/[a-z]/.test(newPw) || !/[0-9]/.test(newPw)) {
        alert("Please make sure your new password fulfills all security requirements.");
        e.preventDefault();
        return false;
    }

    if (newPw !== confirmPw) {
        alert("New password and confirmation password do not match.");
        e.preventDefault();
        return false;
    }
    return true;
}
</script>

</body>
</html>
