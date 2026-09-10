<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../admin/utils/audit_helper.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");
require_once(__DIR__ . "/../utils/mailer.php");

$user_id     = (int)$_SESSION['user_id'];
$bidder_role = $_SESSION['role'] ?? 'bidder';

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
            // Verify image contents
            $img_info = @getimagesize($file_tmp);
            if ($img_info === false) {
                $avatar_error = "Uploaded file is not a valid image.";
            } else {
                // Ensure target uploads/profile folder exists in root
                $upload_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }

                // Generate safe, unique filename
                $new_filename = 'avatar_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target_path  = $upload_dir . DIRECTORY_SEPARATOR . $new_filename;
                $db_rel_path  = 'uploads/profile/' . $new_filename;

                if (move_uploaded_file($file_tmp, $target_path)) {
                    // Fetch and delete old avatar file if present
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

                    // Update database
                    $upd_stmt = $conn->prepare("UPDATE users SET profile_picture_url = ? WHERE user_id = ?");
                    $upd_stmt->bind_param("si", $db_rel_path, $user_id);
                    if ($upd_stmt->execute()) {
                        $_SESSION['profile_picture_url'] = $db_rel_path;
                        $avatar_success = "Profile photo updated successfully!";
                    } else {
                        $avatar_error = "Failed to save profile picture path in database.";
                    }
                    $upd_stmt->close();
                } else {
                    $avatar_error = "Failed to move uploaded photo to uploads folder. Please check directory permissions.";
                }
            }
        }
    } else {
        $avatar_error = "Please select an image file to upload.";
    }

    $_SESSION['avatar_error']   = $avatar_error;
    $_SESSION['avatar_success'] = $avatar_success;
    header("Location: settings.php#avatar-card");
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
    header("Location: settings.php#avatar-card");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. HANDLE CHANGE PASSWORD
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    $pw_error   = '';
    $pw_success = '';

    // Fetch user password
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
            audit_log($conn, 'USER_PASSWORD_CHANGED', 'users', $user_id,
                "Bidder #{$user_id} changed their own password",
                null, ['user_id' => $user_id]
            );
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
// 4. HANDLE RE-UPLOAD BIDDER DOCUMENT
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reupload_document') {
    $doc_type = trim($_POST['document_type'] ?? '');
    $validTypes = array_keys(REQUIRED_BIDDER_DOCS);

    if (!in_array($doc_type, $validTypes)) {
        $_SESSION['doc_error'] = "Invalid document category selected.";
        header("Location: settings.php?tab=documents");
        exit();
    }

    $docLabel = REQUIRED_BIDDER_DOCS[$doc_type];

    if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['doc_error'] = "Please select a valid file to upload for {$docLabel}.";
        header("Location: settings.php?tab=documents");
        exit();
    }

    $fileTmp  = $_FILES['doc_file']['tmp_name'];
    $fileName = $_FILES['doc_file']['name'];
    $fileSize = $_FILES['doc_file']['size'];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize     = 20 * 1024 * 1024; // 20 MB

    if (!in_array($ext, $allowedExts)) {
        $_SESSION['doc_error'] = "Invalid file type for {$docLabel}. Allowed: PDF, JPG, JPEG, PNG.";
        header("Location: settings.php?tab=documents");
        exit();
    }

    if ($fileSize > $maxSize) {
        $_SESSION['doc_error'] = "File size exceeds the 20 MB limit. Please upload a smaller file.";
        header("Location: settings.php?tab=documents");
        exit();
    }

    // Target upload folder: uploads/bidders/{user_id}/
    $uploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bidders' . DIRECTORY_SEPARATOR . $user_id;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $cleanOrigName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($fileName));
    $newFileName   = $doc_type . '_' . uniqid() . '_' . $cleanOrigName;
    $targetPath    = $uploadDir . DIRECTORY_SEPARATOR . $newFileName;
    $dbRelPath     = '../uploads/bidders/' . $user_id . '/' . $newFileName;

    if (move_uploaded_file($fileTmp, $targetPath)) {
        // Fetch existing document to delete old physical file if present
        $oldStmt = $conn->prepare("SELECT document_id, file_path FROM bidder_documents WHERE user_id = ? AND document_type = ?");
        $oldStmt->bind_param("is", $user_id, $doc_type);
        $oldStmt->execute();
        $oldDoc = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();

        if ($oldDoc) {
            // Delete previous physical file to save storage
            delete_bidder_physical_file($oldDoc['file_path']);

            // Update database record and reset expiration_date to NULL for Secretariat review
            $updStmt = $conn->prepare("
                UPDATE bidder_documents
                SET file_name = ?, file_path = ?, upload_date = NOW(), expiration_date = NULL
                WHERE document_id = ? AND user_id = ?
            ");
            $updStmt->bind_param("ssii", $newFileName, $dbRelPath, $oldDoc['document_id'], $user_id);
            $updStmt->execute();
            $updStmt->close();
            $targetDocId = (int)$oldDoc['document_id'];
        } else {
            // Insert new document record with NULL expiration_date
            $insStmt = $conn->prepare("
                INSERT INTO bidder_documents (user_id, document_type, file_name, file_path, upload_date, expiration_date)
                VALUES (?, ?, ?, ?, NOW(), NULL)
            ");
            $insStmt->bind_param("isss", $user_id, $doc_type, $newFileName, $dbRelPath);
            $insStmt->execute();
            $targetDocId = (int)$conn->insert_id;
            $insStmt->close();
        }

        audit_log(
            $conn,
            'DOCUMENT_REUPLOADED',
            'bidder_documents',
            $targetDocId,
            "Bidder #{$user_id} uploaded replacement for {$docLabel}",
            $oldDoc ?? null,
            ['file_name' => $newFileName, 'file_path' => $dbRelPath]
        );

        // Notify Secretariat via email
        notify_secretariat_document_reuploaded($conn, $user_id, $targetDocId, $doc_type);

        $_SESSION['doc_success'] = "{$docLabel} uploaded successfully. The BAC Secretariat has been notified to verify your renewal.";
    } else {
        $_SESSION['doc_error'] = "Failed to save file to server storage. Please check directory permissions.";
    }

    header("Location: settings.php?tab=documents");
    exit();
}

// Flash Messages
$avatar_error   = $_SESSION['avatar_error']   ?? ''; unset($_SESSION['avatar_error']);
$avatar_success = $_SESSION['avatar_success'] ?? ''; unset($_SESSION['avatar_success']);
$pw_error       = $_SESSION['pw_error']       ?? ''; unset($_SESSION['pw_error']);
$pw_success     = $_SESSION['pw_success']     ?? ''; unset($_SESSION['pw_success']);
$doc_error      = $_SESSION['doc_error']      ?? ''; unset($_SESSION['doc_error']);
$doc_success    = $_SESSION['doc_success']    ?? ''; unset($_SESSION['doc_success']);

// ─────────────────────────────────────────────────────────────────────────────
// 4. FETCH CURRENT USER AND BIDDER PROFILE INFORMATION (READ-ONLY)
// ─────────────────────────────────────────────────────────────────────────────
$query = "
    SELECT 
        u.user_id,
        u.firstname,
        u.lastname,
        u.username,
        u.email,
        u.role,
        u.status AS user_status,
        u.profile_picture_url,
        u.created_at AS registered_at,
        bp.business_name,
        bp.philgeps_number,
        bp.tin_number,
        bp.business_type,
        bp.year_established,
        bp.business_address,
        bp.business_email,
        bp.business_phone,
        bp.application_status
    FROM users u
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE u.user_id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullname       = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')) ?: $data['username'];
$username       = $data['username'] ?? 'N/A';
$user_email     = $data['email'] ?? 'N/A';
$role           = ucfirst($data['role'] ?? 'Bidder');
$user_status    = ucfirst($data['user_status'] ?? 'Active');
$avatar_url     = !empty($data['profile_picture_url']) ? '../' . ltrim($data['profile_picture_url'], '/') : '';
$member_since   = !empty($data['registered_at']) ? date('F j, Y', strtotime($data['registered_at'])) : 'N/A';

$business_name   = $data['business_name'] ?? 'Not Specified';
$philgeps_number = $data['philgeps_number'] ?? 'Not Specified';
$tin_number      = $data['tin_number'] ?? 'Not Specified';
$business_type   = $data['business_type'] ?? 'Not Specified';
$year_est        = $data['year_established'] ?? 'Not Specified';
$biz_address     = $data['business_address'] ?? 'Not Specified';
$biz_email       = $data['business_email'] ?? $user_email;
$biz_phone       = $data['business_phone'] ?? 'Not Specified';
$app_status      = strtolower($data['application_status'] ?? 'approved');

$initials = strtoupper(substr($data['firstname'] ?? 'B', 0, 1) . substr($data['lastname'] ?? 'P', 0, 1));
if (trim($initials) === '') $initials = 'BP';

// Document compliance status
$doc_status = check_bidder_documents_status($conn, $user_id);
$uploaded_docs = $doc_status['all_docs'];

// Active tab (default to profile or documents)
$active_tab = in_array($_GET['tab'] ?? '', ['profile', 'documents']) ? $_GET['tab'] : 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | YesParency Bidder Portal</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    
    <style>
        /* ── Base Container ── */
        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ── Settings Tabs Navigation (matching admin settings) ── */
        .settings-tabs-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid #eef2f0;
            padding-bottom: 2px;
        }

        .stab-btn {
            background: transparent;
            border: none;
            padding: 10px 20px;
            border-radius: 12px 12px 0 0;
            font-size: 13.5px;
            font-weight: 700;
            color: #63736a;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .15s ease;
            text-decoration: none;
        }

        .stab-btn:hover {
            background: #f0f4f2;
            color: #06251b;
        }

        .stab-btn.active {
            background: #06251b;
            color: #ffc107;
            box-shadow: 0 2px 10px rgba(6,37,27,.18);
        }

        .stab-btn i { font-size: 15px; }

        .stab-badge-warn {
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 999px;
            margin-left: 2px;
        }

        .stab-panel { display: none; }
        .stab-panel.active { display: block; }

        /* ── Document Management UI Styles ── */
        .doc-comp-banner {
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .doc-comp-banner.valid {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .doc-comp-banner.invalid {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .doc-comp-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .doc-comp-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .doc-comp-banner.valid .doc-comp-icon {
            background: #dcfce7;
            color: #16a34a;
        }

        .doc-comp-banner.invalid .doc-comp-icon {
            background: #fee2e2;
            color: #dc2626;
        }

        .doc-comp-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .doc-comp-desc {
            font-size: 12.5px;
            opacity: 0.9;
            line-height: 1.4;
        }

        .doc-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 20px;
        }

        @media (max-width: 768px) {
            .doc-cards-grid { grid-template-columns: 1fr; }
        }

        .doc-item-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 8px 20px -10px rgba(16,36,26,.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
            transition: all .15s ease;
        }

        .doc-item-card:hover {
            border-color: #d8e2dc;
            box-shadow: 0 4px 16px rgba(16,36,26,.08);
        }

        .doc-item-card.is-expired {
            border-color: #fecaca;
            background: #fffafa;
        }

        .doc-item-card.is-missing {
            border: 1.5px dashed #f87171;
            background: #fff8f8;
        }

        .doc-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .doc-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .doc-type-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            background: #f0f4f2;
            color: #06251b;
        }

        .doc-item-card.is-expired .doc-type-icon,
        .doc-item-card.is-missing .doc-type-icon {
            background: #fee2e2;
            color: #dc2626;
        }

        .doc-title-text {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            line-height: 1.3;
        }

        .doc-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .doc-details-box {
            background: #f8faf9;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12px;
            color: #4b5563;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .doc-details-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .doc-details-lbl {
            color: #6b7280;
            font-weight: 600;
        }

        .doc-details-val {
            font-weight: 700;
            color: #111827;
        }

        .doc-file-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 700;
            color: #1f7a3d;
            text-decoration: none;
            background: #dcfce7;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11.5px;
        }

        .doc-file-link:hover {
            background: #1f7a3d;
            color: #ffffff;
        }

        .doc-upload-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 4px;
        }

        .doc-file-input {
            width: 100%;
            padding: 7px 10px;
            border: 1.5px dashed #cbd5e1;
            border-radius: 8px;
            font-size: 11.5px;
            background: #ffffff;
            cursor: pointer;
        }

        .doc-file-input:focus {
            outline: none;
            border-color: #1f7a3d;
        }

        .doc-upload-btn {
            background: #06251b;
            color: #ffc107;
            border: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .15s ease;
        }

        .doc-upload-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
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

        .set-card-body {
            padding: 22px;
        }

        /* ── Avatar Upload Section ── */
        .avatar-preview-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 10px 0 20px;
        }

        .avatar-circle {
            width: 124px;
            height: 124px;
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

        .avatar-action-btns {
            display: flex;
            gap: 10px;
            width: 100%;
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

        /* ── Profile Information (Read-Only) ── */
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

        .readonly-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (max-width: 640px) {
            .readonly-grid {
                grid-template-columns: 1fr;
            }
        }

        .readonly-field-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .readonly-field-group.span-2 {
            grid-column: span 2;
        }

        @media (max-width: 640px) {
            .readonly-field-group.span-2 {
                grid-column: span 1;
            }
        }

        .readonly-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #88968d;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .readonly-value-box {
            background: #fafcfb;
            border: 1.5px solid #eef2ef;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 12.5px;
            font-weight: 600;
            color: #06251b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 42px;
        }

        .readonly-value-box i.lock-icon {
            color: #aab5ae;
            font-size: 12px;
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

        .status-pill-badge.approved { background: #e4f5ea; color: #1f7a3d; }
        .status-pill-badge.pending  { background: #fef3c7; color: #d97706; }
        .status-pill-badge.rejected { background: #fee2e2; color: #dc2626; }

        /* ── Change Password Form ── */
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
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>

<?php 
$topbar_title = 'Account Settings';
include("components/topbar.php"); 
?>

<!-- ════════════════════════════════════════════════════════════
     MAIN CONTENT
     ════════════════════════════════════════════════════════════ -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header" style="margin-bottom:20px;">
        <h2>Account Settings</h2>
        <p>Manage your bidder portal avatar, view verified credentials, manage compliance documents, and secure your login password.</p>
    </div>

    <!-- ── Settings Tabs Navigation ── -->
    <div class="settings-tabs-bar" role="tablist">
        <button class="stab-btn <?= $active_tab === 'profile' ? 'active' : '' ?>"
                role="tab" aria-selected="<?= $active_tab === 'profile' ? 'true' : 'false' ?>"
                onclick="switchSettingsTab('profile')">
            <i class="bi bi-person-circle"></i> Profile &amp; Security
        </button>
        <button class="stab-btn <?= $active_tab === 'documents' ? 'active' : '' ?>"
                role="tab" aria-selected="<?= $active_tab === 'documents' ? 'true' : 'false' ?>"
                onclick="switchSettingsTab('documents')">
            <i class="bi bi-file-earmark-check"></i> Legal Documents
            <?php if (!$doc_status['is_valid']): ?>
                <span class="stab-badge-warn">Action Required</span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB 1: PROFILE & SECURITY
         ══════════════════════════════════════════════════════════ -->
    <div id="stab-profile" class="stab-panel <?= $active_tab === 'profile' ? 'active' : '' ?>" role="tabpanel">

    <!-- 2-Column Settings Layout -->
    <div class="settings-grid">

        <!-- ── LEFT COLUMN: Profile Picture & Quick Security ── -->
        <div class="settings-col-left">

            <!-- 1. Profile Picture Card -->
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

                    <form method="POST" action="settings.php" enctype="multipart/form-data" id="avatarForm">
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
                            <div class="avatar-meta-sub">@<?= htmlspecialchars($username) ?> &bull; <?= htmlspecialchars($role) ?></div>

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
                        <form method="POST" action="settings.php" onsubmit="return confirm('Are you sure you want to remove your profile picture?');">
                            <input type="hidden" name="action" value="remove_avatar">
                            <button type="submit" class="btn-set-danger">
                                <i class="bi bi-trash3"></i> Remove Photo
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Account Summary Card -->
            <div class="set-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-shield-check" style="color:#2F6FED;"></i> Account Security
                    </span>
                    <span class="status-pill-badge <?= $app_status ?>"><?= htmlspecialchars($data['application_status'] ?? 'Verified') ?></span>
                </div>
                <div class="set-card-body" style="font-size:12px; color:#55665a; line-height:1.6;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                        <span>Member Since:</span>
                        <strong style="color:#06251b;"><?= $member_since ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                        <span>Portal Role:</span>
                        <strong style="color:#06251b;">Registered Bidder</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>2FA Protection:</span>
                        <span style="color:#1f7a3d; font-weight:700;"><i class="bi bi-shield-lock-fill"></i> Active</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Profile Info (Read-Only) + Change Password ── -->
        <div class="settings-col-right">

            <!-- 2. Profile Information Card (Read-Only) -->
            <div class="set-card">
                <div class="set-card-head">
                    <span class="set-card-title">
                        <i class="bi bi-building-fill-check" style="color:#1f7a3d;"></i> Bidder &amp; Business Profile
                    </span>
                    <span style="font-size:11.5px; font-weight:700; color:#88968d; display:inline-flex; align-items:center; gap:4px;">
                        <i class="bi bi-lock-fill" style="color:#aab5ae;"></i> Read-Only
                    </span>
                </div>

                <div class="set-card-body">
                    <div class="info-alert-box">
                        <i class="bi bi-info-circle-fill"></i>
                        <div class="info-alert-text">
                            <strong>Official Verified Information:</strong> Profile credentials are tied to your validated PhilGEPS accreditation. To request corrections or changes to your company name, TIN, or registered email, please contact the BAC Secretariat.
                        </div>
                    </div>

                    <div class="readonly-grid">
                        
                        <!-- Full Name -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-person"></i> Authorized Representative</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($fullname) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Username -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-at"></i> Portal Username</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($username) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Login Email -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-envelope"></i> Login Email Address</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($user_email) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-telephone"></i> Business Telephone</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($biz_phone) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Business Name -->
                        <div class="readonly-field-group span-2">
                            <span class="readonly-label"><i class="bi bi-building"></i> Registered Business / Entity Name</span>
                            <div class="readonly-value-box">
                                <span style="font-weight:700;"><?= htmlspecialchars($business_name) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- PhilGEPS Number -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-hash"></i> PhilGEPS Certificate #</span>
                            <div class="readonly-value-box">
                                <span style="font-family:'Space Grotesk',sans-serif; font-weight:700; color:#1f7a3d;"><?= htmlspecialchars($philgeps_number) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- TIN -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-receipt"></i> Tax ID (TIN)</span>
                            <div class="readonly-value-box">
                                <span style="font-family:'Space Grotesk',sans-serif;"><?= htmlspecialchars($tin_number) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Business Type -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-briefcase"></i> Organization Type</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($business_type) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Year Established -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-calendar-check"></i> Year Established</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($year_est) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="readonly-field-group span-2">
                            <span class="readonly-label"><i class="bi bi-geo-alt"></i> Official Business Address</span>
                            <div class="readonly-value-box">
                                <span><?= htmlspecialchars($biz_address) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- 3. Change Password Card -->
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

                    <form method="POST" action="settings.php" id="changePasswordForm" onsubmit="return validatePasswordForm(event)">
                        <input type="hidden" name="action" value="change_password">

                        <!-- Current Password -->
                        <div class="pw-form-group">
                            <label class="pw-label" for="current_password">Current Password</label>
                            <div class="pw-input-wrap">
                                <i class="bi bi-shield-lock prefix-icon"></i>
                                <input type="password" name="current_password" id="current_password" required class="pw-input-field" placeholder="Enter your current password">
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

    </div><!-- /#stab-profile -->

    <!-- ══════════════════════════════════════════════════════════
         TAB 2: LEGAL DOCUMENTS MANAGEMENT
         ══════════════════════════════════════════════════════════ -->
    <div id="stab-documents" class="stab-panel <?= $active_tab === 'documents' ? 'active' : '' ?>" role="tabpanel">

        <!-- Flash messages for document uploads -->
        <?php if (!empty($doc_success)): ?>
            <div class="flash-alert success" style="margin-bottom:20px;">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($doc_success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($doc_error)): ?>
            <div class="flash-alert error" style="margin-bottom:20px;">
                <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($doc_error) ?>
            </div>
        <?php endif; ?>

        <!-- Compliance Banner -->
        <?php if ($doc_status['is_valid']): ?>
            <div class="doc-comp-banner valid">
                <div class="doc-comp-left">
                    <div class="doc-comp-icon"><i class="bi bi-patch-check-fill"></i></div>
                    <div>
                        <div class="doc-comp-title">Compliance Verified — Electronic Bidding Active</div>
                        <div class="doc-comp-desc">All required legal eligibility documents are currently on file and valid. You are eligible to submit bid proposals across all open municipal opportunities.</div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="doc-comp-banner invalid">
                <div class="doc-comp-left">
                    <div class="doc-comp-icon"><i class="bi bi-exclamation-octagon-fill"></i></div>
                    <div>
                        <div class="doc-comp-title">Action Required: Compliance Incomplete or Expired</div>
                        <div class="doc-comp-desc">
                            <?= htmlspecialchars($doc_status['summary_error']) ?><br>
                            <strong>Bid proposal submissions are locked</strong> until replacement documents are uploaded and verified by the Secretariat.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents Grid -->
        <div class="doc-cards-grid">
            <?php foreach (REQUIRED_BIDDER_DOCS as $docType => $docLabel):
                $hasDoc = isset($uploaded_docs[$docType]);
                $doc = $hasDoc ? $uploaded_docs[$docType] : null;
                $valInfo = $hasDoc ? get_document_validity_info($doc['expiration_date'] ?? null) : null;
                $filePath = $hasDoc ? $doc['file_path'] : '';
                if ($hasDoc && !str_starts_with($filePath, '../') && !str_starts_with($filePath, 'http')) {
                    $fileHref = '../' . ltrim($filePath, '/');
                } else {
                    $fileHref = $filePath;
                }
                $isExpired = $hasDoc && $valInfo['is_expired'];
                $isMissing = !$hasDoc;
            ?>
            <div class="doc-item-card <?= $isExpired ? 'is-expired' : ($isMissing ? 'is-missing' : '') ?>">

                <div>
                    <!-- Top header -->
                    <div class="doc-card-top">
                        <div class="doc-title-row">
                            <div class="doc-type-icon">
                                <i class="bi <?= $isMissing ? 'bi-file-earmark-x' : ($isExpired ? 'bi-file-earmark-break' : 'bi-file-earmark-check-fill') ?>"></i>
                            </div>
                            <div>
                                <div class="doc-title-text"><?= htmlspecialchars($docLabel) ?></div>
                                <span style="font-size:11px; color:#88968d; font-weight:600;">Standard Required File</span>
                            </div>
                        </div>

                        <div>
                            <?php if ($hasDoc): ?>
                                <span class="doc-badge-pill" style="<?= $valInfo['badge_style'] ?>">
                                    <i class="bi <?= $valInfo['is_expired'] ? 'bi-x-circle-fill' : 'bi-check-circle-fill' ?>"></i>
                                    <?= htmlspecialchars($valInfo['label']) ?>
                                </span>
                            <?php else: ?>
                                <span class="doc-badge-pill" style="background:#FBE1E1; color:#c23b3b; border:1px solid #f5b7b7;">
                                    Missing File
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Details Box -->
                    <div class="doc-details-box" style="margin-top:14px;">
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Uploaded File:</span>
                            <span class="doc-details-val">
                                <?php if ($hasDoc): ?>
                                    <a href="<?= htmlspecialchars($fileHref) ?>" target="_blank" class="doc-file-link" title="Open file">
                                        <i class="bi bi-box-arrow-up-right"></i> View File
                                    </a>
                                <?php else: ?>
                                    <em style="color:#9ca3af; font-weight:normal;">None uploaded</em>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Upload Date:</span>
                            <span class="doc-details-val"><?= $hasDoc ? date('M j, Y · g:i A', strtotime($doc['upload_date'])) : '—' ?></span>
                        </div>
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Official Expiration:</span>
                            <span class="doc-details-val" style="<?= $isExpired ? 'color:#dc2626;' : '' ?>">
                                <?= $hasDoc ? $valInfo['date_formatted'] : '—' ?>
                            </span>
                        </div>
                    </div>

                    <div style="font-size:11.5px; color:#6b7280; margin-top:8px; display:flex; align-items:center; gap:5px;">
                        <i class="bi bi-shield-lock"></i> Expiration dates are set and managed by the BAC Secretariat.
                    </div>
                </div>

                <!-- Re-upload Form -->
                <div style="border-top:1px solid #edf1ee; padding-top:14px; margin-top:10px;">
                    <form method="POST" action="settings.php?tab=documents" enctype="multipart/form-data" class="doc-upload-form">
                        <input type="hidden" name="action" value="reupload_document">
                        <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">

                        <label style="font-size:11.5px; font-weight:700; color:#374151;">
                            <?= $hasDoc ? 'Replace / Re-upload Document' : 'Upload Required Document' ?>:
                        </label>
                        <input type="file" name="doc_file" class="doc-file-input" accept=".pdf,.jpg,.jpeg,.png" required>
                        <span style="font-size:10.5px; color:#88968d;">
                            Accepted: PDF, JPG, PNG (Max: 20MB). Re-uploading replaces previous copy to save storage.
                        </span>

                        <button type="submit" class="doc-upload-btn" style="margin-top:4px;">
                            <i class="bi bi-cloud-arrow-up-fill"></i> <?= $hasDoc ? 'Upload Replacement' : 'Submit Document' ?>
                        </button>
                    </form>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

    </div><!-- /#stab-documents -->

</div>
</main>

<script>
// ── Avatar Preview & Dropzone ──
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate max size 5MB
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

// Drag and drop effects
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

// ── Tab Switching Function ──
function switchSettingsTab(tabName) {
    document.querySelectorAll('.settings-tabs-bar .stab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.stab-panel').forEach(panel => {
        panel.classList.remove('active');
    });

    const targetPanel = document.getElementById('stab-' + tabName);
    if (targetPanel) {
        targetPanel.classList.add('active');
    }

    const clickedBtn = event ? event.currentTarget : null;
    if (clickedBtn) {
        clickedBtn.classList.add('active');
        clickedBtn.setAttribute('aria-selected', 'true');
    }

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url.toString());
}
</script>

</body>
</html>
