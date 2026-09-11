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
$slsu_number = $data['slsu_number'] ?? 'Not Specified';
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
    
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    
    <link rel="stylesheet" href="../css/pages/bidder-settings.css">
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
    <div class="page-header page-header--tight">
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
                        <i class="bi bi-person-badge-fill clr-green"></i> Profile Photo
                    </span>
                    <span class="set-card-tag">Avatar</span>
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
                                    <img src="" alt="Preview" id="avatarImgPreview" hidden>
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

                            <button type="submit" class="btn-set-primary btn-set-primary--saveavatar" id="saveAvatarBtn" hidden>
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
                        <i class="bi bi-shield-check clr-blue"></i> Account Security
                    </span>
                    <span class="status-pill-badge <?= $app_status ?>"><?= htmlspecialchars($data['application_status'] ?? 'Verified') ?></span>
                </div>
                <div class="set-card-body set-card-body--security">
                    <div class="security-row">
                        <span>Member Since:</span>
                        <strong class="security-row-val"><?= $member_since ?></strong>
                    </div>
                    <div class="security-row">
                        <span>Portal Role:</span>
                        <strong class="security-row-val">Registered Bidder</strong>
                    </div>
                    <div class="security-row">
                        <span>2FA Protection:</span>
                        <span class="security-2fa-val"><i class="bi bi-shield-lock-fill"></i> Active</span>
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
                        <i class="bi bi-building-fill-check clr-green"></i> Bidder &amp; Business Profile
                    </span>
                    <span class="readonly-tag">
                        <i class="bi bi-lock-fill clr-muted"></i> Read-Only
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
                                <span class="fw-700"><?= htmlspecialchars($business_name) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- PhilGEPS Number -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-hash"></i> PhilGEPS Certificate #</span>
                            <div class="readonly-value-box">
                                <span class="font-mono fw-700 clr-green"><?= htmlspecialchars($slsu_number) ?></span>
                                <i class="bi bi-lock-fill lock-icon"></i>
                            </div>
                        </div>

                        <!-- TIN -->
                        <div class="readonly-field-group">
                            <span class="readonly-label"><i class="bi bi-receipt"></i> Tax ID (TIN)</span>
                            <div class="readonly-value-box">
                                <span class="font-mono"><?= htmlspecialchars($tin_number) ?></span>
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
                        <i class="bi bi-key-fill clr-amber"></i> Change Password
                    </span>
                    <span class="set-card-tag">Security</span>
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
                            <div id="match-hint" class="match-hint" hidden></div>
                        </div>

                        <div class="pw-submit-row">
                            <button type="submit" class="btn-set-primary btn-set-primary--auto">
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
            <div class="flash-alert success mb-20">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($doc_success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($doc_error)): ?>
            <div class="flash-alert error mb-20">
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

                $docBadgeClass = 'doc-badge-pill--missing';
                if ($hasDoc) {
                    if ($valInfo['is_expired']) {
                        $docBadgeClass = 'doc-badge-pill--expired';
                    } elseif (($valInfo['urgency'] ?? '') === 'urgent') {
                        $docBadgeClass = 'doc-badge-pill--urgent';
                    } elseif (($valInfo['urgency'] ?? '') === 'warning') {
                        $docBadgeClass = 'doc-badge-pill--warning';
                    } elseif ($valInfo['status'] === 'no_expiration') {
                        $docBadgeClass = 'doc-badge-pill--neutral';
                    } else {
                        $docBadgeClass = 'doc-badge-pill--valid';
                    }
                }
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
                                <span class="doc-title-sub">Standard Required File</span>
                            </div>
                        </div>

                        <div>
                            <?php if ($hasDoc): ?>
                                <span class="doc-badge-pill <?= $docBadgeClass ?>">
                                    <i class="bi <?= $valInfo['is_expired'] ? 'bi-x-circle-fill' : 'bi-check-circle-fill' ?>"></i>
                                    <?= htmlspecialchars($valInfo['label']) ?>
                                </span>
                            <?php else: ?>
                                <span class="doc-badge-pill doc-badge-pill--missing">
                                    Missing File
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Details Box -->
                    <div class="doc-details-box">
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Uploaded File:</span>
                            <span class="doc-details-val">
                                <?php if ($hasDoc): ?>
                                    <a href="<?= htmlspecialchars($fileHref) ?>" target="_blank" class="doc-file-link" title="Open file">
                                        <i class="bi bi-box-arrow-up-right"></i> View File
                                    </a>
                                <?php else: ?>
                                    <em class="doc-details-empty">None uploaded</em>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Upload Date:</span>
                            <span class="doc-details-val"><?= $hasDoc ? date('M j, Y · g:i A', strtotime($doc['upload_date'])) : '—' ?></span>
                        </div>
                        <div class="doc-details-row">
                            <span class="doc-details-lbl">Official Expiration:</span>
                            <span class="doc-details-val <?= $isExpired ? 'doc-details-val--expired' : '' ?>">
                                <?= $hasDoc ? $valInfo['date_formatted'] : '—' ?>
                            </span>
                        </div>
                    </div>

                    <div class="doc-expiration-note">
                        <i class="bi bi-shield-lock"></i> Expiration dates are set and managed by the BAC Secretariat.
                    </div>
                </div>

                <!-- Re-upload Form -->
                <div class="doc-upload-section">
                    <form method="POST" action="settings.php?tab=documents" enctype="multipart/form-data" class="doc-upload-form">
                        <input type="hidden" name="action" value="reupload_document">
                        <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">

                        <label class="doc-upload-label">
                            <?= $hasDoc ? 'Replace / Re-upload Document' : 'Upload Required Document' ?>:
                        </label>
                        <input type="file" name="doc_file" class="doc-file-input" accept=".pdf,.jpg,.jpeg,.png" required>
                        <span class="doc-upload-hint">
                            Accepted: PDF, JPG, PNG (Max: 20MB). Re-uploading replaces previous copy to save storage.
                        </span>

                        <button type="submit" class="doc-upload-btn">
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
        matchHint.innerHTML = '<span class="match-hint-ok"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
    } else {
        matchHint.innerHTML = '<span class="match-hint-bad"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
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
