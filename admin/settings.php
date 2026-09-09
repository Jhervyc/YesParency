<?php
include("utils/protect-page.php");

$user_id    = (int)$_SESSION['user_id'];
$admin_role = $_SESSION['role'] ?? 'admin';

// ─────────────────────────────────────────────────────────────────────────────
// LIVE CONFIG — seed defaults into system_settings if not yet present
// ─────────────────────────────────────────────────────────────────────────────
$live_defaults = [
    'mediamtx_host'         => $_ENV['MEDIAMTX_HOST']         ?? 'localhost',
    'mediamtx_webrtc_port'  => $_ENV['MEDIAMTX_WEBRTC_PORT']  ?? '8889',
    'mediamtx_rtmp_port'    => $_ENV['MEDIAMTX_RTMP_PORT']    ?? '1935',
    'mediamtx_default_path' => $_ENV['MEDIAMTX_DEFAULT_PATH'] ?? 'live',
];
foreach ($live_defaults as $k => $v) {
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('" . $conn->real_escape_string($k) . "', '" . $conn->real_escape_string($v) . "')");
}

// ─────────────────────────────────────────────────────────────────────────────
// LIVE CONFIG AJAX ENDPOINTS
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['live_action'])) {
    header('Content-Type: application/json');

    // ── GET live config ───────────────────────────────────────────────────────
    if ($_GET['live_action'] === 'get') {
        $keys = array_keys($live_defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $config = [];
        foreach ($rows as $row) {
            $config[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode(['success' => true, 'config' => $config]);
        exit;
    }

    // ── SAVE live config ──────────────────────────────────────────────────────
    if ($_GET['live_action'] === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);

        $allowed_keys = array_keys($live_defaults);
        $errors = [];
        $saved  = [];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $data)) continue;
            $val = trim($data[$key] ?? '');

            // Basic validation
            if (in_array($key, ['mediamtx_webrtc_port', 'mediamtx_rtmp_port'], true)) {
                $port = (int)$val;
                if ($port < 1 || $port > 65535) {
                    $errors[] = "$key must be a valid port (1–65535).";
                    continue;
                }
                $val = (string)$port;
            } elseif ($key === 'mediamtx_default_path') {
                $val = ltrim($val, '/');
                if ($val === '') {
                    $errors[] = "mediamtx_default_path cannot be empty.";
                    continue;
                }
            } elseif ($key === 'mediamtx_host') {
                if ($val === '') {
                    $errors[] = "mediamtx_host cannot be empty.";
                    continue;
                }
            }

            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                                    VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("ss", $key, $val);
            $stmt->execute();
            $stmt->close();
            $saved[$key] = $val;
        }

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        // Bust PHP in-memory cache sentinel so next request re-reads from DB
        require_once __DIR__ . '/../config/mediamtx.php';
        mediamtx_bust_cache();

        // Audit log
        require_once __DIR__ . '/utils/audit_helper.php';
        audit_log($conn, 'LIVE_CONFIG_UPDATED', 'system_settings', null,
            'Updated MediaMTX live configuration', null, $saved);

        // Broadcast config:updated so the PHP in-memory cache knows to refresh
        require_once __DIR__ . '/../config/pusher.php';
        try {
            $pusher = new Pusher\Pusher(
                $_ENV['PUSHER_APP_KEY']    ?? '',
                $_ENV['PUSHER_APP_SECRET'] ?? '',
                $_ENV['PUSHER_APP_ID']     ?? '',
                ['cluster' => $_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3', 'useTLS' => true]
            );
            $pusher->trigger('system-config', 'config:updated', ['keys' => array_keys($saved)]);
        } catch (\Throwable $e) {
            error_log('Pusher config trigger failed: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown live action.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CHECKLIST AJAX ENDPOINTS
// All JSON responses, must come before any HTML output.
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['checklist_action'])) {
    header('Content-Type: application/json');

    $action          = $_GET['checklist_action'];
    $proc_type_vals  = ['goods_services', 'infrastructure'];
    $check_type_vals = ['eligibility', 'financial'];

    // ── LOAD items for a given group ──────────────────────────────────────────
    if ($action === 'load') {
        $pt = $_GET['proc_type']  ?? '';
        $ct = $_GET['check_type'] ?? '';

        if (!in_array($pt, $proc_type_vals, true) || !in_array($ct, $check_type_vals, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            exit;
        }

        $stmt = $conn->prepare(
            "SELECT id, item_name, description, is_required, display_order, is_active
             FROM checklist_templates
             WHERE procurement_type = ? AND checklist_type = ?
             ORDER BY display_order ASC, id ASC"
        );
        $stmt->bind_param("ss", $pt, $ct);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'items' => $rows]);
        exit;
    }

    // ── ADD item ──────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $data = json_decode(file_get_contents('php://input'), true);

        $pt          = $data['procurement_type'] ?? '';
        $ct          = $data['checklist_type']   ?? '';
        $item_name   = trim($data['item_name']   ?? '');
        $description = trim($data['description'] ?? '');
        $is_required = isset($data['is_required']) ? (int)(bool)$data['is_required'] : 1;
        $is_active   = isset($data['is_active'])   ? (int)(bool)$data['is_active']   : 1;
        $disp_order  = isset($data['display_order']) ? (int)$data['display_order']   : 0;

        if (!in_array($pt, $proc_type_vals, true) || !in_array($ct, $check_type_vals, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid type parameters.']);
            exit;
        }
        if ($item_name === '') {
            echo json_encode(['success' => false, 'message' => 'Item name is required.']);
            exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO checklist_templates
                (procurement_type, checklist_type, item_name, description, is_required, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssiii", $pt, $ct, $item_name, $description, $is_required, $disp_order, $is_active);
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Return the full new item
            $fetch = $conn->prepare(
                "SELECT id, item_name, description, is_required, display_order, is_active
                 FROM checklist_templates WHERE id = ?"
            );
            $fetch->bind_param("i", $new_id);
            $fetch->execute();
            $new_item = $fetch->get_result()->fetch_assoc();
            $fetch->close();

            audit_log(
                $conn,
                'CHECKLIST_TEMPLATE_CREATED',
                'settings',
                $new_id,
                "Created checklist template item '{$item_name}' ({$pt}/{$ct})",
                null,
                [
                    'procurement_type' => $pt,
                    'checklist_type' => $ct,
                    'item_name' => $item_name,
                    'description' => $description,
                    'is_required' => $is_required,
                    'display_order' => $disp_order,
                    'is_active' => $is_active
                ]
            );

            echo json_encode(['success' => true, 'item' => $new_item]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    // ── EDIT item ─────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $data = json_decode(file_get_contents('php://input'), true);

        $id          = (int)($data['id'] ?? 0);
        $item_name   = trim($data['item_name']   ?? '');
        $description = trim($data['description'] ?? '');
        $is_required = isset($data['is_required']) ? (int)(bool)$data['is_required'] : 1;
        $is_active   = isset($data['is_active'])   ? (int)(bool)$data['is_active']   : 1;
        $disp_order  = isset($data['display_order']) ? (int)$data['display_order']   : 0;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
            exit;
        }
        if ($item_name === '') {
            echo json_encode(['success' => false, 'message' => 'Item name is required.']);
            exit;
        }

        // Snapshot before update
        $snap = $conn->prepare("SELECT item_name, description, is_required, display_order, is_active, procurement_type, checklist_type FROM checklist_templates WHERE id = ?");
        $snap->bind_param("i", $id);
        $snap->execute();
        $old_tpl = $snap->get_result()->fetch_assoc();
        $snap->close();

        $stmt = $conn->prepare(
            "UPDATE checklist_templates
             SET item_name = ?, description = ?, is_required = ?, display_order = ?, is_active = ?
             WHERE id = ?"
        );
        $stmt->bind_param("ssiiii", $item_name, $description, $is_required, $disp_order, $is_active, $id);
        if ($stmt->execute()) {
            $stmt->close();

            audit_log(
                $conn,
                'CHECKLIST_TEMPLATE_UPDATED',
                'settings',
                $id,
                "Updated checklist template item #{$id}: {$item_name}",
                [
                    'item_name' => $old_tpl['item_name'] ?? '',
                    'description' => $old_tpl['description'] ?? '',
                    'is_required' => (int)($old_tpl['is_required'] ?? 0),
                    'display_order' => (int)($old_tpl['display_order'] ?? 0),
                    'is_active' => (int)($old_tpl['is_active'] ?? 0)
                ],
                [
                    'item_name' => $item_name,
                    'description' => $description,
                    'is_required' => $is_required,
                    'display_order' => $disp_order,
                    'is_active' => $is_active
                ]
            );

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    // ── DELETE item ───────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
            exit;
        }

        // Check if any bid_checklist rows reference this template item
        $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM bid_checklist WHERE template_item_id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $cnt_row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ((int)$cnt_row['cnt'] > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'This checklist item cannot be deleted because it is referenced by existing bid checklists. You may deactivate it instead.'
            ]);
            exit;
        }

        // Snapshot before deletion
        $snap = $conn->prepare("SELECT item_name, description, is_required, display_order, is_active, procurement_type, checklist_type FROM checklist_templates WHERE id = ?");
        $snap->bind_param("i", $id);
        $snap->execute();
        $old_tpl = $snap->get_result()->fetch_assoc();
        $snap->close();

        $stmt = $conn->prepare("DELETE FROM checklist_templates WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $stmt->close();

            $iname = $old_tpl['item_name'] ?? "#$id";
            audit_log(
                $conn,
                'CHECKLIST_TEMPLATE_DELETED',
                'settings',
                $id,
                "Deleted checklist template item #{$id}: {$iname}",
                $old_tpl,
                null
            );

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    // ── REORDER items ─────────────────────────────────────────────────────────
    if ($action === 'reorder') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $ordered = $data['order'] ?? []; // array of IDs in desired sequence

        if (empty($ordered) || !is_array($ordered)) {
            echo json_encode(['success' => false, 'message' => 'No order data provided.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE checklist_templates SET display_order = ? WHERE id = ?");
        $conn->begin_transaction();
        try {
            foreach ($ordered as $position => $item_id) {
                $pos = (int)$position + 1; // 1-based
                $iid = (int)$item_id;
                $stmt->bind_param("ii", $pos, $iid);
                $stmt->execute();
            }

            audit_log(
                $conn,
                'CHECKLIST_TEMPLATE_REORDERED',
                'settings',
                null,
                "Reordered " . count($ordered) . " checklist template items",
                null,
                ['order' => array_values($ordered)]
            );

            $conn->commit();
            $stmt->close();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

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

                $new_filename = 'avatar_admin_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
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
                        $avatar_error = "Failed to save profile photo path in database.";
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
// 3. HANDLE UPDATE PROFILE INFORMATION
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname  = trim($_POST['lastname']  ?? '');
    $email     = trim($_POST['email']     ?? '');

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
                audit_log(
                    $conn,
                    'USER_PROFILE_UPDATED',
                    'users',
                    $user_id,
                    "Updated profile information for {$firstname} {$lastname}",
                    [
                        'firstname' => $_SESSION['firstname'] ?? '',
                        'lastname'  => $_SESSION['lastname'] ?? '',
                        'email'     => $_SESSION['email'] ?? ''
                    ],
                    [
                        'firstname' => $firstname,
                        'lastname'  => $lastname,
                        'email'     => $email
                    ]
                );

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

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

// Flash Messages
$avatar_error   = $_SESSION['avatar_error']   ?? ''; unset($_SESSION['avatar_error']);
$avatar_success = $_SESSION['avatar_success'] ?? ''; unset($_SESSION['avatar_success']);
$prof_error     = $_SESSION['prof_error']     ?? ''; unset($_SESSION['prof_error']);
$prof_success   = $_SESSION['prof_success']   ?? ''; unset($_SESSION['prof_success']);
$pw_error       = $_SESSION['pw_error']       ?? ''; unset($_SESSION['pw_error']);
$pw_success     = $_SESSION['pw_success']     ?? ''; unset($_SESSION['pw_success']);

// ─────────────────────────────────────────────────────────────────────────────
// 5. FETCH CURRENT ADMIN DATA
// ─────────────────────────────────────────────────────────────────────────────
$u_stmt = $conn->prepare(
    "SELECT user_id, firstname, lastname, username, email, role, status,
            profile_picture_url, created_at
     FROM users WHERE user_id = ?"
);
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$current_user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$firstname    = $current_user['firstname'] ?? '';
$lastname     = $current_user['lastname']  ?? '';
$fullname     = trim($firstname . ' ' . $lastname) ?: ($current_user['username'] ?? 'Administrator');
$username     = $current_user['username'] ?? 'N/A';
$admin_email  = $current_user['email']    ?? 'N/A';
$role         = strtoupper($current_user['role'] ?? 'ADMIN');
$status       = ucfirst($current_user['status']  ?? 'Active');
$avatar_url   = !empty($current_user['profile_picture_url'])
                ? '../' . ltrim($current_user['profile_picture_url'], '/')
                : '';
$member_since = !empty($current_user['created_at'])
                ? date('F j, Y', strtotime($current_user['created_at']))
                : 'N/A';

$initials = strtoupper(substr($firstname ?: 'A', 0, 1) . substr($lastname ?: 'D', 0, 1));

// Active tab (from query string, default profile)
$active_tab = in_array($_GET['tab'] ?? '', ['profile', 'checklist', 'live'])
              ? ($_GET['tab'])
              : 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">

    <style>
        /* ─────────────────────────────────────────────────────────────────────
           BASE CONTAINER
        ───────────────────────────────────────────────────────────────────── */
        .dash-content { max-width: 100%; overflow-x: hidden; }

        /* ─────────────────────────────────────────────────────────────────────
           SETTINGS TABS NAV
        ───────────────────────────────────────────────────────────────────── */
        .settings-tabs-bar {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 14px;
            padding: 6px;
            margin-bottom: 28px;
            width: fit-content;
            box-shadow: 0 1px 4px rgba(16,36,26,.04);
        }

        .stab-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 20px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            color: #55665a;
            background: transparent;
            transition: all .18s ease;
            white-space: nowrap;
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

        /* ─────────────────────────────────────────────────────────────────────
           TAB PANELS
        ───────────────────────────────────────────────────────────────────── */
        .stab-panel { display: none; }
        .stab-panel.active { display: block; }

        /* ─────────────────────────────────────────────────────────────────────
           PROFILE — existing layout
        ───────────────────────────────────────────────────────────────────── */
        .settings-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .settings-grid { grid-template-columns: 1fr; }
        }

        /* ─────────────────────────────────────────────────────────────────────
           SHARED SETTING CARD
        ───────────────────────────────────────────────────────────────────── */
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

        .set-card-body { padding: 22px; }

        /* ─────────────────────────────────────────────────────────────────────
           PROFILE — avatar
        ───────────────────────────────────────────────────────────────────── */
        .avatar-preview-wrapper {
            display: flex; flex-direction: column;
            align-items: center; text-align: center;
            padding: 10px 0 20px;
        }

        .avatar-circle {
            width: 124px; height: 124px; border-radius: 50%;
            border: 3px solid #1f7a3d;
            box-shadow: 0 4px 16px rgba(31,122,61,.15);
            background: #f0f7f2;
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; font-weight: 800; color: #1f7a3d;
            overflow: hidden; position: relative; margin-bottom: 14px;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .avatar-meta-name   { font-size: 16px; font-weight: 800; color: #06251b; margin-bottom: 3px; }
        .avatar-meta-sub    { font-size: 12px; color: #88968d; margin-bottom: 14px; }

        .avatar-drop-zone {
            border: 2px dashed #d6e2db; border-radius: 14px;
            padding: 18px; text-align: center; background: #fafcfb;
            cursor: pointer; transition: all .2s ease;
            position: relative; width: 100%; margin-bottom: 14px;
        }

        .avatar-drop-zone:hover, .avatar-drop-zone.dragover {
            border-color: #1f7a3d; background: #eef7f1;
        }

        .avatar-drop-zone i   { font-size: 26px; color: #1f7a3d; display: block; margin-bottom: 6px; }
        .avatar-drop-zone p   { font-size: 12px; font-weight: 600; color: #06251b; margin: 0; }
        .avatar-drop-zone span { font-size: 10.5px; color: #88968d; }

        .file-hidden-input {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }

        /* ─────────────────────────────────────────────────────────────────────
           BUTTONS
        ───────────────────────────────────────────────────────────────────── */
        .btn-set-primary {
            background: #06251b; color: #ffc107; border: none;
            padding: 10px 18px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; display: inline-flex; align-items: center;
            justify-content: center; gap: 7px; transition: all .2s ease; width: 100%;
        }

        .btn-set-primary:hover {
            background: #144937; color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(6,37,27,.15);
        }

        .btn-set-danger {
            background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2;
            padding: 9px 14px; border-radius: 10px;
            font-size: 12px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; display: inline-flex; align-items: center;
            justify-content: center; gap: 6px; transition: all .15s ease; width: 100%;
        }

        .btn-set-danger:hover { background: #dc2626; color: #ffffff; }

        /* ─────────────────────────────────────────────────────────────────────
           PROFILE — form fields
        ───────────────────────────────────────────────────────────────────── */
        .info-alert-box {
            background: #f4f8f5; border: 1px solid #dcebe1; border-radius: 12px;
            padding: 12px 16px; margin-bottom: 20px;
            display: flex; align-items: flex-start; gap: 12px;
        }

        .info-alert-box i { font-size: 18px; color: #1f7a3d; margin-top: 1px; flex-shrink: 0; }

        .info-alert-text { font-size: 12px; color: #385141; line-height: 1.45; }
        .info-alert-text strong { color: #06251b; }

        .form-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }

        @media (max-width: 640px) { .form-grid-2 { grid-template-columns: 1fr; } }

        .form-field-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-field-group.span-2 { grid-column: span 2; }

        @media (max-width: 640px) { .form-field-group.span-2 { grid-column: span 1; } }

        .field-label {
            font-size: 11.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; color: #55665a;
            display: flex; align-items: center; gap: 6px;
        }

        .field-input-wrap { position: relative; display: flex; align-items: center; }
        .field-input-wrap i.prefix-icon { position: absolute; left: 14px; color: #88968d; font-size: 14px; pointer-events: none; }

        .field-input {
            width: 100%; padding: 10px 14px 10px 38px;
            border: 1.5px solid #d4e0d8; border-radius: 10px;
            font-size: 12.5px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; outline: none; background: #ffffff; transition: all .15s ease;
        }

        .field-input:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }

        .field-input.locked {
            background: #fafcfb; border: 1.5px solid #eef2ef; color: #4a5a50;
            cursor: not-allowed; padding-right: 36px; font-weight: 600;
        }

        .locked-badge-icon { position: absolute; right: 14px; color: #aab5ae; font-size: 13px; pointer-events: none; }

        .status-pill-badge {
            font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 20px;
            display: inline-flex; align-items: center; gap: 4px;
            font-family: 'Space Grotesk', sans-serif; text-transform: uppercase;
        }

        .status-pill-badge.active { background: #e4f5ea; color: #1f7a3d; }
        .status-pill-badge.admin  { background: #fef3c7; color: #d97706; }

        /* ─────────────────────────────────────────────────────────────────────
           PROFILE — password form
        ───────────────────────────────────────────────────────────────────── */
        .pw-form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .pw-label      { font-size: 12px; font-weight: 700; color: #06251b; }

        .pw-input-wrap { position: relative; display: flex; align-items: center; }
        .pw-input-wrap i.prefix-icon { position: absolute; left: 14px; color: #88968d; font-size: 14px; pointer-events: none; }

        .pw-input-field {
            width: 100%; padding: 10px 42px 10px 38px;
            border: 1.5px solid #d4e0d8; border-radius: 10px;
            font-size: 12.5px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; outline: none; background: #ffffff; transition: all .15s ease;
        }

        .pw-input-field:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }

        .pw-toggle-btn {
            position: absolute; right: 12px; background: transparent; border: none;
            color: #88968d; cursor: pointer; font-size: 14px; padding: 4px;
            display: flex; align-items: center; justify-content: center; transition: color .15s;
        }

        .pw-toggle-btn:hover { color: #06251b; }

        .pw-requirements-box {
            background: #fbfdfc; border: 1px solid #edf1ee;
            border-radius: 12px; padding: 14px 16px; margin-bottom: 20px;
        }

        .pw-req-title {
            font-size: 11px; font-weight: 700; color: #55665a;
            text-transform: uppercase; letter-spacing: .4px; margin-bottom: 8px;
        }

        .pw-req-list {
            list-style: none; padding: 0; margin: 0;
            display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px;
        }

        @media (max-width: 600px) { .pw-req-list { grid-template-columns: 1fr; } }

        .pw-req-item {
            font-size: 11.5px; color: #88968d;
            display: flex; align-items: center; gap: 6px; transition: color .15s;
        }

        .pw-req-item.valid { color: #1f7a3d; font-weight: 600; }
        .pw-req-item i     { font-size: 13px; }

        /* ─────────────────────────────────────────────────────────────────────
           FLASH ALERTS
        ───────────────────────────────────────────────────────────────────── */
        .flash-alert {
            padding: 12px 16px; border-radius: 12px;
            font-size: 12.5px; font-weight: 600; margin-bottom: 18px;
            display: flex; align-items: center; gap: 10px;
            animation: fadeInAlert .2s ease;
        }

        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .flash-alert.success { background: #eaf7ee; color: #1f7a3d; border: 1px solid #c9e8d3; }
        .flash-alert.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ─────────────────────────────────────────────────────────────────────
           CHECKLIST TAB — procurement & checklist type switchers
        ───────────────────────────────────────────────────────────────────── */
        .cl-switcher-row {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        .cl-switch-group {
            display: flex; background: #f0f4f2;
            border-radius: 10px; padding: 4px; gap: 4px;
        }

        .cl-sw-btn {
            padding: 8px 18px; border: none; border-radius: 8px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; color: #55665a; background: transparent;
            transition: all .16s ease; white-space: nowrap;
        }

        .cl-sw-btn:hover { background: #ffffff; color: #06251b; }

        .cl-sw-btn.active {
            background: #06251b; color: #ffc107;
            box-shadow: 0 2px 8px rgba(6,37,27,.18);
        }

        .cl-divider {
            width: 1px; height: 28px; background: #d6e2db; margin: 0 6px;
            align-self: center;
        }

        /* ─────────────────────────────────────────────────────────────────────
           CHECKLIST TAB — item list
        ───────────────────────────────────────────────────────────────────── */
        .cl-list-wrap {
            margin-top: 22px;
            min-height: 80px;
        }

        .cl-list-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 14px;
        }

        .cl-list-title {
            font-size: 13px; font-weight: 800; color: #06251b;
        }

        .cl-group-badge {
            font-size: 11px; font-weight: 700; padding: 3px 10px;
            border-radius: 20px; background: #eaf7ee; color: #1f7a3d;
            font-family: 'Space Grotesk', sans-serif;
        }

        .cl-empty-state {
            text-align: center; padding: 40px 20px; color: #88968d;
        }

        .cl-empty-state i   { font-size: 36px; display: block; margin-bottom: 10px; color: #c8d8ce; }
        .cl-empty-state p   { font-size: 13px; margin: 0; }

        .cl-loading {
            text-align: center; padding: 32px; color: #88968d; font-size: 13px;
        }

        /* Drag-and-drop list */
        .cl-item-list {
            list-style: none; padding: 0; margin: 0;
        }

        .cl-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 13px 16px;
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            margin-bottom: 8px;
            transition: box-shadow .15s ease, border-color .15s ease;
            cursor: default;
        }

        .cl-item:hover { border-color: #c5d9cc; box-shadow: 0 2px 8px rgba(16,36,26,.06); }

        .cl-item.dragging {
            opacity: .5; border: 2px dashed #1f7a3d;
        }

        .cl-item.drag-over {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 2px rgba(31,122,61,.15);
        }

        .cl-drag-handle {
            cursor: grab; color: #c8d8ce; font-size: 18px;
            padding-top: 2px; flex-shrink: 0;
            touch-action: none;
        }

        .cl-drag-handle:active { cursor: grabbing; }

        .cl-item-order {
            width: 22px; height: 22px; border-radius: 50%;
            background: #f0f4f2; color: #55665a;
            font-size: 11px; font-weight: 800; font-family: 'Space Grotesk', sans-serif;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; margin-top: 1px;
        }

        .cl-item-body { flex: 1; min-width: 0; }

        .cl-item-name {
            font-size: 13.5px; font-weight: 700; color: #06251b;
            margin-bottom: 4px; word-break: break-word;
        }

        .cl-item-desc {
            font-size: 12px; color: #55665a; margin-bottom: 6px;
            word-break: break-word; line-height: 1.45;
        }

        .cl-item-tags { display: flex; flex-wrap: wrap; gap: 5px; }

        .cl-tag {
            font-size: 10.5px; font-weight: 700; padding: 2px 8px;
            border-radius: 20px; font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase; letter-spacing: .2px;
        }

        .cl-tag.required  { background: #fff8e7; color: #d97706; border: 1px solid #fde68a; }
        .cl-tag.optional  { background: #f0f4f2; color: #55665a; border: 1px solid #d6e2db; }
        .cl-tag.active    { background: #e4f5ea; color: #1f7a3d; border: 1px solid #a7d9b6; }
        .cl-tag.inactive  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .cl-item-actions {
            display: flex; gap: 5px; flex-shrink: 0; padding-top: 1px;
        }

        .cl-action-btn {
            width: 30px; height: 30px; border: none; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; cursor: pointer; transition: all .14s ease;
        }

        .cl-action-btn.edit   { background: #f0f7ff; color: #2563eb; }
        .cl-action-btn.edit:hover { background: #2563eb; color: #ffffff; }
        .cl-action-btn.del    { background: #fef2f2; color: #dc2626; }
        .cl-action-btn.del:hover  { background: #dc2626; color: #ffffff; }

        /* ─────────────────────────────────────────────────────────────────────
           CHECKLIST — Add button row
        ───────────────────────────────────────────────────────────────────── */
        .cl-add-row {
            display: flex; justify-content: flex-end; margin-bottom: 4px;
        }

        .btn-cl-add {
            background: #06251b; color: #ffc107; border: none;
            padding: 9px 18px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            transition: all .18s ease;
        }

        .btn-cl-add:hover { background: #144937; color: #ffffff; transform: translateY(-1px); }

        /* ─────────────────────────────────────────────────────────────────────
           MODAL
        ───────────────────────────────────────────────────────────────────── */
        .cl-modal-backdrop {
            display: none;
            position: fixed; inset: 0; z-index: 9000;
            background: rgba(6,37,27,.45);
            align-items: center; justify-content: center;
            padding: 20px;
        }

        .cl-modal-backdrop.open { display: flex; }

        .cl-modal {
            background: #ffffff; border-radius: 20px;
            width: 100%; max-width: 540px;
            box-shadow: 0 24px 64px rgba(6,37,27,.22);
            animation: modalPop .18s ease;
            overflow: hidden;
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .cl-modal-head {
            padding: 18px 22px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
            display: flex; align-items: center; justify-content: space-between;
        }

        .cl-modal-title {
            font-size: 15px; font-weight: 800; color: #06251b;
            display: flex; align-items: center; gap: 8px;
        }

        .cl-modal-close {
            width: 32px; height: 32px; border: none;
            border-radius: 8px; background: #f0f4f2;
            color: #55665a; font-size: 18px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .14s;
        }

        .cl-modal-close:hover { background: #dc2626; color: #ffffff; }

        .cl-modal-body { padding: 22px; }

        .cl-form-group { margin-bottom: 16px; }

        .cl-form-label {
            display: block; font-size: 12px; font-weight: 700; color: #06251b;
            margin-bottom: 6px;
        }

        .cl-form-label span { color: #dc2626; margin-left: 2px; }

        .cl-form-input, .cl-form-textarea {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid #d4e0d8; border-radius: 10px;
            font-size: 13px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; outline: none; background: #ffffff;
            transition: border-color .15s, box-shadow .15s;
            box-sizing: border-box;
        }

        .cl-form-input:focus, .cl-form-textarea:focus {
            border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1);
        }

        .cl-form-textarea { resize: vertical; min-height: 72px; }

        .cl-form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

        @media (max-width: 540px) { .cl-form-row { grid-template-columns: 1fr 1fr; } }

        .cl-toggle-group {
            display: flex; gap: 6px;
        }

        .cl-toggle-opt {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            gap: 5px; padding: 9px 6px;
            border: 1.5px solid #d4e0d8; border-radius: 9px;
            font-size: 12px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; color: #55665a; background: #fafcfb;
            transition: all .14s;
            user-select: none;
        }

        .cl-toggle-opt.selected-req  { border-color: #d97706; background: #fff8e7; color: #d97706; }
        .cl-toggle-opt.selected-opt  { border-color: #55665a; background: #f0f4f2; color: #06251b; }
        .cl-toggle-opt.selected-active { border-color: #1f7a3d; background: #e4f5ea; color: #1f7a3d; }
        .cl-toggle-opt.selected-inactive { border-color: #dc2626; background: #fef2f2; color: #dc2626; }

        .cl-context-info {
            background: #f4f8f5; border: 1px solid #dcebe1; border-radius: 10px;
            padding: 10px 14px; margin-bottom: 18px;
            font-size: 12px; color: #385141;
            display: flex; align-items: center; gap: 8px;
        }

        .cl-context-info i { color: #1f7a3d; font-size: 14px; flex-shrink: 0; }

        .cl-modal-footer {
            padding: 16px 22px;
            border-top: 1px solid #f0f4f2;
            display: flex; justify-content: flex-end; gap: 10px;
        }

        .btn-cl-cancel {
            background: #f0f4f2; color: #55665a; border: none;
            padding: 10px 20px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; transition: all .14s;
        }

        .btn-cl-cancel:hover { background: #d6e2db; color: #06251b; }

        .btn-cl-save {
            background: #06251b; color: #ffc107; border: none;
            padding: 10px 22px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            transition: all .18s;
        }

        .btn-cl-save:hover { background: #144937; color: #ffffff; }

        .btn-cl-save:disabled { opacity: .6; cursor: not-allowed; }

        /* Error / success inside modal */
        .cl-modal-alert {
            padding: 10px 14px; border-radius: 10px;
            font-size: 12.5px; font-weight: 600; margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }

        .cl-modal-alert.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .cl-modal-alert.success { background: #eaf7ee; color: #1f7a3d; border: 1px solid #c9e8d3; }

        /* Toast */
        .cl-toast {
            position: fixed; bottom: 28px; right: 28px; z-index: 9999;
            background: #06251b; color: #ffffff;
            padding: 12px 20px; border-radius: 12px;
            font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif;
            display: flex; align-items: center; gap: 9px;
            box-shadow: 0 8px 24px rgba(6,37,27,.3);
            transform: translateY(80px); opacity: 0;
            transition: all .25s ease;
            pointer-events: none;
        }

        .cl-toast.show { transform: translateY(0); opacity: 1; }
        .cl-toast.error-toast { background: #dc2626; }

        /* Reorder hint */
        .cl-reorder-hint {
            font-size: 11.5px; color: #88968d; margin-top: 6px;
            display: flex; align-items: center; gap: 5px;
        }

        /* ─────────────────────────────────────────────────────────────────────
           LIVE CONFIG TAB
        ───────────────────────────────────────────────────────────────────── */
        .live-config-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        @media (max-width: 640px) { .live-config-grid { grid-template-columns: 1fr; } }

        .live-field-group { display: flex; flex-direction: column; gap: 6px; }

        .live-field-label {
            font-size: 11.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; color: #55665a;
            display: flex; align-items: center; gap: 6px;
        }

        .live-field-wrap { position: relative; display: flex; align-items: center; }
        .live-field-wrap i.prefix-icon {
            position: absolute; left: 14px; color: #88968d; font-size: 14px; pointer-events: none;
        }

        .live-field-input {
            width: 100%; padding: 10px 14px 10px 38px;
            border: 1.5px solid #d4e0d8; border-radius: 10px;
            font-size: 13px; font-family: 'Poppins', sans-serif;
            color: #1a1a1a; outline: none; background: #ffffff;
            transition: border-color .15s, box-shadow .15s;
            box-sizing: border-box;
        }
        .live-field-input:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }

        .live-field-hint { font-size: 11px; color: #88968d; line-height: 1.4; }

        .live-preview-box {
            background: #f4f8f5; border: 1px solid #dcebe1; border-radius: 12px;
            padding: 14px 18px; margin-top: 4px; margin-bottom: 20px;
            font-size: 12px; color: #385141;
        }
        .live-preview-box strong { color: #06251b; font-size: 12.5px; }
        .live-preview-url {
            font-family: monospace; font-size: 12.5px; color: #1f7a3d;
            word-break: break-all; margin-top: 4px;
            display: block;
        }

        .live-save-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }

        .live-status-msg {
            font-size: 12.5px; font-weight: 600;
            display: flex; align-items: center; gap: 7px;
            opacity: 0; transition: opacity .25s;
        }
        .live-status-msg.show { opacity: 1; }
        .live-status-msg.success { color: #1f7a3d; }
        .live-status-msg.error   { color: #dc2626; }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php
$topbar_title = 'Settings';
include("components/topbar.php");
?>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN CONTENT
     ═══════════════════════════════════════════════════════════════ -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header" style="margin-bottom:24px;">
        <h2>Account Settings</h2>
        <p>Manage your administrative profile, checklist templates, and system settings.</p>
    </div>

    <!-- ── Tabs Navigation ── -->
    <div class="settings-tabs-bar" role="tablist">
        <button class="stab-btn <?= $active_tab === 'profile'   ? 'active' : '' ?>"
                role="tab" aria-selected="<?= $active_tab === 'profile'   ? 'true' : 'false' ?>"
                onclick="switchSettingsTab('profile')">
            <i class="bi bi-person-circle"></i> Profile
        </button>
        <button class="stab-btn <?= $active_tab === 'checklist' ? 'active' : '' ?>"
                role="tab" aria-selected="<?= $active_tab === 'checklist' ? 'true' : 'false' ?>"
                onclick="switchSettingsTab('checklist')">
            <i class="bi bi-card-checklist"></i> Checklist
        </button>
        <button class="stab-btn <?= $active_tab === 'live'      ? 'active' : '' ?>"
                role="tab" aria-selected="<?= $active_tab === 'live'      ? 'true' : 'false' ?>"
                onclick="switchSettingsTab('live')">
            <i class="bi bi-broadcast"></i> Live
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB: PROFILE
         ══════════════════════════════════════════════════════════ -->
    <div id="stab-profile" class="stab-panel <?= $active_tab === 'profile' ? 'active' : '' ?>" role="tabpanel">

        <div class="settings-grid">

            <!-- ── LEFT COLUMN ── -->
            <div class="settings-col-left">

                <!-- Profile Picture Card -->
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
                                <div class="avatar-meta-sub">@<?= htmlspecialchars($username) ?> &bull; <?= htmlspecialchars($role) ?></div>

                                <div class="avatar-drop-zone" id="dropZone">
                                    <input type="file" name="profile_pic" id="profilePicInput"
                                           accept="image/jpeg,image/png,image/webp,image/gif"
                                           class="file-hidden-input" onchange="previewAvatar(this)">
                                    <i class="bi bi-cloud-arrow-up-fill"></i>
                                    <p>Click to browse photo</p>
                                    <span>JPG, PNG, WEBP or GIF (Max 5MB)</span>
                                </div>

                                <button type="submit" class="btn-set-primary" id="saveAvatarBtn"
                                        style="display:none; margin-bottom:10px;">
                                    <i class="bi bi-check2-circle"></i> Save Photo
                                </button>
                            </div>
                        </form>

                        <?php if (!empty($avatar_url)): ?>
                            <form method="POST" action="settings.php?tab=profile"
                                  onsubmit="return confirm('Are you sure you want to remove your profile picture?');">
                                <input type="hidden" name="action" value="remove_avatar">
                                <button type="submit" class="btn-set-danger">
                                    <i class="bi bi-trash3"></i> Remove Photo
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Account Overview Card -->
                <div class="set-card">
                    <div class="set-card-head">
                        <span class="set-card-title">
                            <i class="bi bi-shield-check" style="color:#2F6FED;"></i> Account Overview
                        </span>
                        <span class="status-pill-badge active"><?= htmlspecialchars($status) ?></span>
                    </div>
                    <div class="set-card-body" style="font-size:12px; color:#55665a; line-height:1.6;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                            <span>Member Since:</span>
                            <strong style="color:#06251b;"><?= $member_since ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #f0f4f2; padding-bottom:6px;">
                            <span>System Role:</span>
                            <strong style="color:#06251b;">BAC Secretariat Admin</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Security Protection:</span>
                            <span style="color:#1f7a3d; font-weight:700;"><i class="bi bi-shield-lock-fill"></i> Active</span>
                        </div>
                    </div>
                </div>

            </div><!-- /col-left -->

            <!-- ── RIGHT COLUMN ── -->
            <div class="settings-col-right">

                <!-- Personal Information Card -->
                <div class="set-card" id="profile-card">
                    <div class="set-card-head">
                        <span class="set-card-title">
                            <i class="bi bi-person-lines-fill" style="color:#1f7a3d;"></i> Personal &amp; Account Information
                        </span>
                        <span style="font-size:11.5px; font-weight:700; color:#88968d;">Administrator Info</span>
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
                                <strong>Profile Details:</strong> You can update your official name and contact email below.
                                Your system username and role assignments are permanently locked for audit and security compliance.
                            </div>
                        </div>

                        <form method="POST" action="settings.php?tab=profile">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="form-grid-2">
                                <div class="form-field-group">
                                    <label class="field-label" for="firstname"><i class="bi bi-person"></i> First Name</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-person prefix-icon"></i>
                                        <input type="text" name="firstname" id="firstname" required class="field-input"
                                               value="<?= htmlspecialchars($firstname) ?>" placeholder="Enter first name">
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="lastname"><i class="bi bi-person"></i> Last Name</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-person prefix-icon"></i>
                                        <input type="text" name="lastname" id="lastname" required class="field-input"
                                               value="<?= htmlspecialchars($lastname) ?>" placeholder="Enter last name">
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="username"><i class="bi bi-at"></i> Portal Username (Locked)</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-at prefix-icon"></i>
                                        <input type="text" id="username" class="field-input locked"
                                               value="<?= htmlspecialchars($username) ?>" readonly disabled>
                                        <i class="bi bi-lock-fill locked-badge-icon"></i>
                                    </div>
                                </div>

                                <div class="form-field-group">
                                    <label class="field-label" for="role_disp"><i class="bi bi-shield-check"></i> Account Role (Locked)</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-shield-check prefix-icon"></i>
                                        <input type="text" id="role_disp" class="field-input locked"
                                               value="BAC Secretariat / Admin" readonly disabled>
                                        <i class="bi bi-lock-fill locked-badge-icon"></i>
                                    </div>
                                </div>

                                <div class="form-field-group span-2">
                                    <label class="field-label" for="email"><i class="bi bi-envelope"></i> Official Email Address</label>
                                    <div class="field-input-wrap">
                                        <i class="bi bi-envelope prefix-icon"></i>
                                        <input type="email" name="email" id="email" required class="field-input"
                                               value="<?= htmlspecialchars($admin_email) ?>" placeholder="admin@domain.gov.ph">
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

                        <form method="POST" action="settings.php?tab=profile" id="changePasswordForm"
                              onsubmit="return validatePasswordForm(event)">
                            <input type="hidden" name="action" value="change_password">

                            <div class="pw-form-group">
                                <label class="pw-label" for="current_password">Current Password</label>
                                <div class="pw-input-wrap">
                                    <i class="bi bi-shield-lock prefix-icon"></i>
                                    <input type="password" name="current_password" id="current_password" required
                                           class="pw-input-field" placeholder="Enter your current password">
                                    <button type="button" class="pw-toggle-btn"
                                            onclick="togglePasswordVisibility('current_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="pw-form-group">
                                <label class="pw-label" for="new_password">New Password</label>
                                <div class="pw-input-wrap">
                                    <i class="bi bi-key prefix-icon"></i>
                                    <input type="password" name="new_password" id="new_password" required
                                           class="pw-input-field" placeholder="Enter new strong password"
                                           oninput="checkPasswordStrength(this.value)">
                                    <button type="button" class="pw-toggle-btn"
                                            onclick="togglePasswordVisibility('new_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="pw-requirements-box">
                                <div class="pw-req-title">Password Requirements:</div>
                                <ul class="pw-req-list">
                                    <li class="pw-req-item" id="req-length"><i class="bi bi-circle"></i> At least 8 characters</li>
                                    <li class="pw-req-item" id="req-upper"><i class="bi bi-circle"></i> Uppercase letter (A-Z)</li>
                                    <li class="pw-req-item" id="req-lower"><i class="bi bi-circle"></i> Lowercase letter (a-z)</li>
                                    <li class="pw-req-item" id="req-num"><i class="bi bi-circle"></i> At least one number (0-9)</li>
                                </ul>
                            </div>

                            <div class="pw-form-group">
                                <label class="pw-label" for="confirm_password">Confirm New Password</label>
                                <div class="pw-input-wrap">
                                    <i class="bi bi-check2-circle prefix-icon"></i>
                                    <input type="password" name="confirm_password" id="confirm_password" required
                                           class="pw-input-field" placeholder="Re-enter new password"
                                           oninput="checkPasswordMatch()">
                                    <button type="button" class="pw-toggle-btn"
                                            onclick="togglePasswordVisibility('confirm_password', this)">
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

            </div><!-- /col-right -->

        </div><!-- /settings-grid -->
    </div><!-- /stab-profile -->


    <!-- ══════════════════════════════════════════════════════════
         TAB: CHECKLIST
         ══════════════════════════════════════════════════════════ -->
    <div id="stab-checklist" class="stab-panel <?= $active_tab === 'checklist' ? 'active' : '' ?>" role="tabpanel">

        <div class="set-card">
            <div class="set-card-head">
                <span class="set-card-title">
                    <i class="bi bi-card-checklist" style="color:#1f7a3d;"></i> Checklist Templates
                </span>
                <span style="font-size:11px; font-weight:700; color:#88968d;">Bid Opening</span>
            </div>

            <div class="set-card-body">

                <!-- Switchers row -->
                <div class="cl-switcher-row">

                    <!-- Procurement Type -->
                    <div class="cl-switch-group" id="procTypeSwitcher">
                        <button class="cl-sw-btn active"
                                data-proc="goods_services"
                                onclick="selectProcType(this)">
                            <i class="bi bi-box-seam"></i> Goods &amp; Services
                        </button>
                        <button class="cl-sw-btn"
                                data-proc="infrastructure"
                                onclick="selectProcType(this)">
                            <i class="bi bi-building"></i> Infrastructure
                        </button>
                    </div>

                    <div class="cl-divider"></div>

                    <!-- Checklist Type -->
                    <div class="cl-switch-group" id="checkTypeSwitcher">
                        <button class="cl-sw-btn active"
                                data-check="eligibility"
                                onclick="selectCheckType(this)">
                            <i class="bi bi-person-check"></i> Eligibility
                        </button>
                        <button class="cl-sw-btn"
                                data-check="financial"
                                onclick="selectCheckType(this)">
                            <i class="bi bi-cash-stack"></i> Financial
                        </button>
                    </div>

                </div><!-- /switchers -->

                <!-- Checklist items area -->
                <div class="cl-list-wrap">

                    <div class="cl-list-header">
                        <div>
                            <div class="cl-list-title">Checklist Items</div>
                            <div class="cl-reorder-hint">
                                <i class="bi bi-grip-vertical"></i>
                                Drag rows to reorder. Order is saved automatically.
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="cl-group-badge" id="clGroupBadge">Goods &amp; Services &rarr; Eligibility</span>
                            <button class="btn-cl-add" onclick="openAddModal()">
                                <i class="bi bi-plus-lg"></i> Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Loading / empty / list container -->
                    <div id="clItemsContainer">
                        <div class="cl-loading"><i class="bi bi-arrow-repeat spin"></i> Loading...</div>
                    </div>

                </div>

            </div><!-- /set-card-body -->
        </div><!-- /set-card -->

    </div><!-- /stab-checklist -->


    <!-- ══════════════════════════════════════════════════════════
         TAB: LIVE
         ══════════════════════════════════════════════════════════ -->
    <div id="stab-live" class="stab-panel <?= $active_tab === 'live' ? 'active' : '' ?>" role="tabpanel">

        <div class="set-card">
            <div class="set-card-head">
                <span class="set-card-title">
                    <i class="bi bi-broadcast" style="color:#dc2626;"></i> MediaMTX Live Configuration
                </span>
                <span style="font-size:11px; font-weight:700; color:#88968d;">Streaming</span>
            </div>
            <div class="set-card-body">

                <div class="info-alert-box" style="margin-bottom:22px;">
                    <i class="bi bi-info-circle-fill"></i>
                    <div class="info-alert-text">
                        <strong>Live Stream Settings:</strong> These values control how the system connects to the
                        MediaMTX media server. Changes take effect immediately across all active stream URLs —
                        no restart required.
                    </div>
                </div>

                <!-- Fields -->
                <div class="live-config-grid" id="liveConfigGrid">

                    <div class="live-field-group">
                        <label class="live-field-label" for="liveHost">
                            <i class="bi bi-hdd-network"></i> Host / Domain
                        </label>
                        <div class="live-field-wrap">
                            <i class="bi bi-hdd-network prefix-icon"></i>
                            <input type="text" id="liveHost" class="live-field-input"
                                   placeholder="e.g. stream.yourdomain.com" autocomplete="off">
                        </div>
                        <span class="live-field-hint">Hostname or IP of the MediaMTX server. No trailing slash.</span>
                    </div>

                    <div class="live-field-group">
                        <label class="live-field-label" for="liveDefaultPath">
                            <i class="bi bi-signpost-split"></i> Default Stream Path
                        </label>
                        <div class="live-field-wrap">
                            <i class="bi bi-signpost-split prefix-icon"></i>
                            <input type="text" id="liveDefaultPath" class="live-field-input"
                                   placeholder="e.g. live" autocomplete="off">
                        </div>
                        <span class="live-field-hint">The default path used when creating new bid sessions.</span>
                    </div>

                    <div class="live-field-group">
                        <label class="live-field-label" for="liveWebrtcPort">
                            <i class="bi bi-ethernet"></i> WebRTC Port
                        </label>
                        <div class="live-field-wrap">
                            <i class="bi bi-ethernet prefix-icon"></i>
                            <input type="number" id="liveWebrtcPort" class="live-field-input"
                                   placeholder="8889" min="1" max="65535" autocomplete="off">
                        </div>
                        <span class="live-field-hint">Port for WebRTC playback (default: 8889).</span>
                    </div>

                    <div class="live-field-group">
                        <label class="live-field-label" for="liveRtmpPort">
                            <i class="bi bi-ethernet"></i> RTMP Port
                        </label>
                        <div class="live-field-wrap">
                            <i class="bi bi-ethernet prefix-icon"></i>
                            <input type="number" id="liveRtmpPort" class="live-field-input"
                                   placeholder="1935" min="1" max="65535" autocomplete="off">
                        </div>
                        <span class="live-field-hint">Port for RTMP ingest (default: 1935).</span>
                    </div>

                </div>

                <!-- Live URL preview -->
                <div class="live-preview-box" id="livePreviewBox">
                    <strong><i class="bi bi-eye"></i> WebRTC Playback URL Preview</strong>
                    <span class="live-preview-url" id="livePreviewUrl">—</span>
                </div>

                <!-- Save row -->
                <div class="live-save-row">
                    <span class="live-status-msg" id="liveStatusMsg">
                        <i class="bi bi-check-circle-fill"></i> <span id="liveStatusText"></span>
                    </span>
                    <button class="btn-set-primary" id="liveSaveBtn" onclick="saveLiveConfig()"
                            style="width:auto; min-width:160px;">
                        <i class="bi bi-floppy"></i> Save Configuration
                    </button>
                </div>

            </div>
        </div>

    </div><!-- /stab-live -->

</div>
</main>


<!-- ══════════════════════════════════════════════════════════════════
     ADD / EDIT CHECKLIST ITEM MODAL
     ══════════════════════════════════════════════════════════════════ -->
<div class="cl-modal-backdrop" id="clModal" role="dialog" aria-modal="true" aria-labelledby="clModalTitle">
    <div class="cl-modal">
        <div class="cl-modal-head">
            <span class="cl-modal-title" id="clModalTitle">
                <i class="bi bi-plus-circle"></i> <span id="clModalTitleText">Add Checklist Item</span>
            </span>
            <button class="cl-modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        </div>

        <div class="cl-modal-body">
            <div class="cl-context-info" id="clModalContext">
                <i class="bi bi-info-circle-fill"></i>
                <span id="clModalContextText">Adding item for: <strong>Goods &amp; Services &rarr; Eligibility</strong></span>
            </div>

            <div id="clModalAlert" style="display:none;" class="cl-modal-alert error"></div>

            <div class="cl-form-group">
                <label class="cl-form-label" for="clItemName">Item Name <span>*</span></label>
                <input type="text" id="clItemName" class="cl-form-input"
                       placeholder="e.g. PhilGEPS Registration Certificate" maxlength="255">
            </div>

            <div class="cl-form-group">
                <label class="cl-form-label" for="clItemDesc">Description</label>
                <textarea id="clItemDesc" class="cl-form-textarea"
                          placeholder="Optional: brief description or notes about this checklist item"
                          rows="3"></textarea>
            </div>

            <div class="cl-form-row">

                <!-- Required / Optional -->
                <div class="cl-form-group">
                    <label class="cl-form-label">Requirement</label>
                    <div class="cl-toggle-group">
                        <div class="cl-toggle-opt selected-req" id="toggleReq" onclick="setRequired(true)">
                            <i class="bi bi-asterisk"></i> Required
                        </div>
                        <div class="cl-toggle-opt" id="toggleOpt" onclick="setRequired(false)">
                            <i class="bi bi-dash-circle"></i> Optional
                        </div>
                    </div>
                </div>

                <!-- Active / Inactive -->
                <div class="cl-form-group">
                    <label class="cl-form-label">Status</label>
                    <div class="cl-toggle-group">
                        <div class="cl-toggle-opt selected-active" id="toggleActive" onclick="setActive(true)">
                            <i class="bi bi-check-circle"></i> Active
                        </div>
                        <div class="cl-toggle-opt" id="toggleInactive" onclick="setActive(false)">
                            <i class="bi bi-x-circle"></i> Inactive
                        </div>
                    </div>
                </div>

                <!-- Display Order -->
                <div class="cl-form-group">
                    <label class="cl-form-label" for="clDisplayOrder">Display Order</label>
                    <input type="number" id="clDisplayOrder" class="cl-form-input"
                           min="0" max="9999" value="0" placeholder="0">
                </div>

            </div>
        </div><!-- /modal-body -->

        <div class="cl-modal-footer">
            <button class="btn-cl-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-cl-save" id="clSaveBtn" onclick="saveItem()">
                <i class="bi bi-check2-circle"></i> <span id="clSaveBtnText">Add Item</span>
            </button>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div class="cl-toast" id="clToast"></div>

<style>
    @keyframes spin { to { transform: rotate(360deg); } }
    .spin { display: inline-block; animation: spin .8s linear infinite; }
</style>

<script>
// ════════════════════════════════════════════════════════════════════
// PROFILE TAB — unchanged functionality
// ════════════════════════════════════════════════════════════════════

function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 5 * 1024 * 1024) {
            alert('File size exceeds 5MB limit.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            const img      = document.getElementById('avatarImgPreview');
            const initials = document.getElementById('avatarInitials');
            const saveBtn  = document.getElementById('saveAvatarBtn');
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
    ['dragenter', 'dragover'].forEach(n => dropZone.addEventListener(n, () => dropZone.classList.add('dragover')));
    ['dragleave', 'drop'].forEach(n => dropZone.addEventListener(n, () => dropZone.classList.remove('dragover')));
}

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

function checkPasswordStrength(pw) {
    updateReq(document.getElementById('req-length'), pw.length >= 8);
    updateReq(document.getElementById('req-upper'),  /[A-Z]/.test(pw));
    updateReq(document.getElementById('req-lower'),  /[a-z]/.test(pw));
    updateReq(document.getElementById('req-num'),    /[0-9]/.test(pw));
    checkPasswordMatch();
}

function updateReq(el, isValid) {
    if (!el) return;
    const icon = el.querySelector('i');
    if (isValid) { el.classList.add('valid'); icon.className = 'bi bi-check-circle-fill'; }
    else         { el.classList.remove('valid'); icon.className = 'bi bi-circle'; }
}

function checkPasswordMatch() {
    const newPw     = document.getElementById('new_password')?.value    ?? '';
    const confirmPw = document.getElementById('confirm_password')?.value ?? '';
    const matchHint = document.getElementById('match-hint');
    if (!matchHint) return;
    if (!confirmPw) { matchHint.style.display = 'none'; return; }
    matchHint.style.display = 'block';
    if (newPw === confirmPw) {
        matchHint.innerHTML = '<span style="color:#1f7a3d;font-weight:600;"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
    } else {
        matchHint.innerHTML = '<span style="color:#dc2626;font-weight:600;"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
    }
}

function validatePasswordForm(e) {
    const newPw     = document.getElementById('new_password')?.value    ?? '';
    const confirmPw = document.getElementById('confirm_password')?.value ?? '';
    if (newPw.length < 8 || !/[A-Z]/.test(newPw) || !/[a-z]/.test(newPw) || !/[0-9]/.test(newPw)) {
        alert('Please make sure your new password fulfills all security requirements.');
        e.preventDefault(); return false;
    }
    if (newPw !== confirmPw) {
        alert('New password and confirmation password do not match.');
        e.preventDefault(); return false;
    }
    return true;
}

// ════════════════════════════════════════════════════════════════════
// SETTINGS TAB SWITCHING
// ════════════════════════════════════════════════════════════════════

function switchSettingsTab(tab) {
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url.toString());

    // Panels
    document.querySelectorAll('.stab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('stab-' + tab).classList.add('active');

    // Buttons
    document.querySelectorAll('.stab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.stab-btn').forEach(b => {
        if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + tab + "'")) {
            b.classList.add('active');
        }
    });

    // Load checklist on first activation
    if (tab === 'checklist' && !clState.loaded) {
        loadChecklist();
    }
}

// ════════════════════════════════════════════════════════════════════
// CHECKLIST STATE
// ════════════════════════════════════════════════════════════════════

const clState = {
    procType:   'goods_services',
    checkType:  'eligibility',
    items:      [],
    loaded:     false,
    editId:     null,        // null = add mode, number = edit mode
    isRequired: true,
    isActive:   true,
};

const PROC_LABELS  = { goods_services: 'Goods &amp; Services', infrastructure: 'Infrastructure' };
const CHECK_LABELS = { eligibility: 'Eligibility', financial: 'Financial' };

function groupLabel() {
    return PROC_LABELS[clState.procType] + ' &rarr; ' + CHECK_LABELS[clState.checkType];
}

// ── Switchers ────────────────────────────────────────────────────────

function selectProcType(btn) {
    document.querySelectorAll('#procTypeSwitcher .cl-sw-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    clState.procType = btn.dataset.proc;
    loadChecklist();
}

function selectCheckType(btn) {
    document.querySelectorAll('#checkTypeSwitcher .cl-sw-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    clState.checkType = btn.dataset.check;
    loadChecklist();
}

// ── Load from server ─────────────────────────────────────────────────

function loadChecklist() {
    const container = document.getElementById('clItemsContainer');
    container.innerHTML = '<div class="cl-loading"><i class="bi bi-arrow-repeat spin"></i> Loading...</div>';

    document.getElementById('clGroupBadge').innerHTML = groupLabel();

    const url = `settings.php?checklist_action=load&proc_type=${clState.procType}&check_type=${clState.checkType}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = `<div class="cl-empty-state">
                    <i class="bi bi-exclamation-circle"></i>
                    <p>Failed to load items: ${escHtml(data.message ?? 'Unknown error')}</p>
                </div>`;
                return;
            }
            clState.items  = data.items ?? [];
            clState.loaded = true;
            renderList();
        })
        .catch(err => {
            container.innerHTML = `<div class="cl-empty-state">
                <i class="bi bi-exclamation-circle"></i>
                <p>Network error. Please refresh and try again.</p>
            </div>`;
        });
}

// ── Render list ──────────────────────────────────────────────────────

function renderList() {
    const container = document.getElementById('clItemsContainer');

    if (!clState.items.length) {
        container.innerHTML = `<div class="cl-empty-state">
            <i class="bi bi-clipboard-x"></i>
            <p>No checklist items yet for this group.<br>Click <strong>Add Item</strong> to create the first one.</p>
        </div>`;
        return;
    }

    let html = '<ul class="cl-item-list" id="clSortableList">';

    clState.items.forEach((item, idx) => {
        const reqTag    = parseInt(item.is_required) ? '<span class="cl-tag required">Required</span>' : '<span class="cl-tag optional">Optional</span>';
        const activeTag = parseInt(item.is_active)   ? '<span class="cl-tag active">Active</span>'   : '<span class="cl-tag inactive">Inactive</span>';
        const desc      = item.description ? `<div class="cl-item-desc">${escHtml(item.description)}</div>` : '';

        html += `
        <li class="cl-item" draggable="true" data-id="${item.id}" data-idx="${idx}">
            <span class="cl-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
            <span class="cl-item-order">${idx + 1}</span>
            <div class="cl-item-body">
                <div class="cl-item-name">${escHtml(item.item_name)}</div>
                ${desc}
                <div class="cl-item-tags">${reqTag}${activeTag}</div>
            </div>
            <div class="cl-item-actions">
                <button class="cl-action-btn edit" title="Edit item" onclick="openEditModal(${item.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="cl-action-btn del" title="Delete item" onclick="deleteItem(${item.id})">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
        </li>`;
    });

    html += '</ul>';
    container.innerHTML = html;

    initDragSort();
}

// ════════════════════════════════════════════════════════════════════
// DRAG-AND-DROP REORDER
// ════════════════════════════════════════════════════════════════════

function initDragSort() {
    const list = document.getElementById('clSortableList');
    if (!list) return;

    let dragSrc = null;

    list.querySelectorAll('.cl-item').forEach(item => {
        item.addEventListener('dragstart', function(e) {
            dragSrc = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.id);
        });

        item.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            list.querySelectorAll('.cl-item').forEach(i => i.classList.remove('drag-over'));
        });

        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            list.querySelectorAll('.cl-item').forEach(i => i.classList.remove('drag-over'));
            if (this !== dragSrc) this.classList.add('drag-over');
        });

        item.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        item.addEventListener('drop', function(e) {
            e.preventDefault();
            if (this === dragSrc) return;
            this.classList.remove('drag-over');

            // Reorder DOM
            const allItems  = Array.from(list.querySelectorAll('.cl-item'));
            const srcIdx    = allItems.indexOf(dragSrc);
            const tgtIdx    = allItems.indexOf(this);

            if (srcIdx < tgtIdx) {
                list.insertBefore(dragSrc, this.nextSibling);
            } else {
                list.insertBefore(dragSrc, this);
            }

            // Persist new order
            saveReorder();
        });

        // Prevent handle from interfering with text selection
        item.querySelector('.cl-drag-handle')?.addEventListener('mousedown', function(e) {
            item.setAttribute('draggable', 'true');
        });
    });
}

function saveReorder() {
    const list = document.getElementById('clSortableList');
    if (!list) return;

    const order = Array.from(list.querySelectorAll('.cl-item')).map(i => i.dataset.id);

    fetch('settings.php?checklist_action=reorder', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ order })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('<i class="bi bi-check-circle-fill"></i> Order saved.');
            // Update local state order numbers
            const newOrder = order.map(id => clState.items.find(it => String(it.id) === String(id))).filter(Boolean);
            clState.items = newOrder;
            // Refresh order numbers in DOM without full re-render
            list.querySelectorAll('.cl-item').forEach((el, idx) => {
                const badge = el.querySelector('.cl-item-order');
                if (badge) badge.textContent = idx + 1;
            });
        } else {
            showToast('<i class="bi bi-exclamation-circle-fill"></i> Failed to save order.', true);
        }
    })
    .catch(() => showToast('<i class="bi bi-exclamation-circle-fill"></i> Network error.', true));
}

// ════════════════════════════════════════════════════════════════════
// ADD / EDIT MODAL
// ════════════════════════════════════════════════════════════════════

function openAddModal() {
    clState.editId     = null;
    clState.isRequired = true;
    clState.isActive   = true;

    document.getElementById('clModalTitleText').textContent = 'Add Checklist Item';
    document.getElementById('clSaveBtnText').textContent    = 'Add Item';
    document.getElementById('clModalContextText').innerHTML =
        'Adding item for: <strong>' + groupLabel() + '</strong>';

    document.getElementById('clItemName').value        = '';
    document.getElementById('clItemDesc').value        = '';
    document.getElementById('clDisplayOrder').value    = clState.items.length + 1;

    syncRequiredUI();
    syncActiveUI();
    clearModalAlert();

    document.getElementById('clModal').classList.add('open');
    document.getElementById('clItemName').focus();
}

function openEditModal(id) {
    const item = clState.items.find(i => String(i.id) === String(id));
    if (!item) { showToast('<i class="bi bi-exclamation-circle-fill"></i> Item not found.', true); return; }

    clState.editId     = id;
    clState.isRequired = !!parseInt(item.is_required);
    clState.isActive   = !!parseInt(item.is_active);

    document.getElementById('clModalTitleText').textContent = 'Edit Checklist Item';
    document.getElementById('clSaveBtnText').textContent    = 'Save Changes';
    document.getElementById('clModalContextText').innerHTML =
        'Editing item in: <strong>' + groupLabel() + '</strong>';

    document.getElementById('clItemName').value     = item.item_name;
    document.getElementById('clItemDesc').value     = item.description ?? '';
    document.getElementById('clDisplayOrder').value = item.display_order;

    syncRequiredUI();
    syncActiveUI();
    clearModalAlert();

    document.getElementById('clModal').classList.add('open');
    document.getElementById('clItemName').focus();
}

function closeModal() {
    document.getElementById('clModal').classList.remove('open');
}

// Close on backdrop click
document.getElementById('clModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// ── Required / Active toggles ────────────────────────────────────────

function setRequired(val) {
    clState.isRequired = val;
    syncRequiredUI();
}

function setActive(val) {
    clState.isActive = val;
    syncActiveUI();
}

function syncRequiredUI() {
    const reqEl = document.getElementById('toggleReq');
    const optEl = document.getElementById('toggleOpt');
    if (clState.isRequired) {
        reqEl.classList.add('selected-req');
        optEl.classList.remove('selected-opt');
    } else {
        reqEl.classList.remove('selected-req');
        optEl.classList.add('selected-opt');
    }
}

function syncActiveUI() {
    const actEl = document.getElementById('toggleActive');
    const inaEl = document.getElementById('toggleInactive');
    if (clState.isActive) {
        actEl.classList.add('selected-active');
        inaEl.classList.remove('selected-inactive');
    } else {
        actEl.classList.remove('selected-active');
        inaEl.classList.add('selected-inactive');
    }
}

// ── Save (add or edit) ───────────────────────────────────────────────

function saveItem() {
    const item_name    = document.getElementById('clItemName').value.trim();
    const description  = document.getElementById('clItemDesc').value.trim();
    const display_order = parseInt(document.getElementById('clDisplayOrder').value) || 0;

    if (!item_name) {
        showModalAlert('Item name is required.', 'error');
        document.getElementById('clItemName').focus();
        return;
    }

    clearModalAlert();
    const btn = document.getElementById('clSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving...';

    const isEdit   = clState.editId !== null;
    const action   = isEdit ? 'edit' : 'add';
    const payload  = {
        procurement_type: clState.procType,
        checklist_type:   clState.checkType,
        item_name,
        description,
        is_required:   clState.isRequired ? 1 : 0,
        is_active:     clState.isActive   ? 1 : 0,
        display_order,
    };
    if (isEdit) payload.id = clState.editId;

    fetch(`settings.php?checklist_action=${action}`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> <span id="clSaveBtnText">' +
                        (isEdit ? 'Save Changes' : 'Add Item') + '</span>';

        if (!data.success) {
            showModalAlert(data.message ?? 'An error occurred. Please try again.', 'error');
            return;
        }

        closeModal();
        showToast(isEdit
            ? '<i class="bi bi-check-circle-fill"></i> Item updated.'
            : '<i class="bi bi-check-circle-fill"></i> Item added.');
        loadChecklist();
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> <span id="clSaveBtnText">' +
                        (isEdit ? 'Save Changes' : 'Add Item') + '</span>';
        showModalAlert('Network error. Please try again.', 'error');
    });
}

// ── Delete ───────────────────────────────────────────────────────────

function deleteItem(id) {
    const item = clState.items.find(i => String(i.id) === String(id));
    const name = item ? item.item_name : 'this item';

    if (!confirm(`Delete "${name}"?\n\nThis cannot be undone if no bid checklists reference it.`)) return;

    fetch('settings.php?checklist_action=delete', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ id }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('<i class="bi bi-trash3-fill"></i> Item deleted.');
            loadChecklist();
        } else {
            showToast('<i class="bi bi-exclamation-circle-fill"></i> ' + (data.message ?? 'Delete failed.'), true);
        }
    })
    .catch(() => showToast('<i class="bi bi-exclamation-circle-fill"></i> Network error.', true));
}

// ════════════════════════════════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════════════════════════════════

function showModalAlert(msg, type) {
    const el = document.getElementById('clModalAlert');
    el.className = 'cl-modal-alert ' + type;
    el.innerHTML = (type === 'error' ? '<i class="bi bi-exclamation-circle-fill"></i> ' : '<i class="bi bi-check-circle-fill"></i> ') + msg;
    el.style.display = 'flex';
}

function clearModalAlert() {
    const el = document.getElementById('clModalAlert');
    el.style.display = 'none';
    el.innerHTML = '';
}

let toastTimer = null;
function showToast(msg, isError = false) {
    const toast = document.getElementById('clToast');
    toast.innerHTML = msg;
    toast.className = 'cl-toast' + (isError ? ' error-toast' : '') + ' show';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Auto-load checklist if starting on that tab ───────────────────────

(function() {
    const activeTab = '<?= $active_tab ?>';
    if (activeTab === 'checklist') {
        loadChecklist();
    }
})();
</script>

<!-- ══════════════════════════════════════════════════════════════════
     LIVE CONFIG JS
     ══════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    // ── Field refs ────────────────────────────────────────────────────────────
    const hostEl    = () => document.getElementById('liveHost');
    const pathEl    = () => document.getElementById('liveDefaultPath');
    const webrtcEl  = () => document.getElementById('liveWebrtcPort');
    const rtmpEl    = () => document.getElementById('liveRtmpPort');
    const previewEl = () => document.getElementById('livePreviewUrl');
    const statusEl  = () => document.getElementById('liveStatusMsg');
    const statusTxt = () => document.getElementById('liveStatusText');
    const saveBtn   = () => document.getElementById('liveSaveBtn');

    // ── URL preview ───────────────────────────────────────────────────────────
    function updatePreview() {
        const host = (hostEl()?.value || '').replace(/\/+$/, '');
        const path = (pathEl()?.value || '').replace(/^\/+/, '');
        const port = webrtcEl()?.value || '8889';
        if (host && path) {
            previewEl().textContent = `http://${host}:${port}/${path}`;
        } else {
            previewEl().textContent = '—';
        }
    }

    // ── Load config from server ───────────────────────────────────────────────
    function loadLiveConfig() {
        fetch('settings.php?live_action=get')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const c = data.config;
                if (hostEl())   hostEl().value   = c.mediamtx_host         ?? '';
                if (pathEl())   pathEl().value   = c.mediamtx_default_path ?? '';
                if (webrtcEl()) webrtcEl().value  = c.mediamtx_webrtc_port  ?? '8889';
                if (rtmpEl())   rtmpEl().value    = c.mediamtx_rtmp_port    ?? '1935';
                updatePreview();
            })
            .catch(() => {}); // silent — fields retain placeholder
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    window.saveLiveConfig = function () {
        const btn = saveBtn();
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving…'; }

        const payload = {
            mediamtx_host:         hostEl()?.value.trim()   ?? '',
            mediamtx_default_path: pathEl()?.value.trim()   ?? '',
            mediamtx_webrtc_port:  webrtcEl()?.value.trim() ?? '',
            mediamtx_rtmp_port:    rtmpEl()?.value.trim()   ?? '',
        };

        fetch('settings.php?live_action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            showLiveStatus(data.success, data.success
                ? 'Configuration saved successfully.'
                : (data.message || 'Save failed.'));
            updatePreview();
        })
        .catch(() => showLiveStatus(false, 'Network error. Please try again.'))
        .finally(() => {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-floppy"></i> Save Configuration'; }
        });
    };

    // ── Status message ────────────────────────────────────────────────────────
    function showLiveStatus(ok, msg) {
        const el  = statusEl();
        const txt = statusTxt();
        if (!el || !txt) return;
        txt.textContent = msg;
        el.className = 'live-status-msg show ' + (ok ? 'success' : 'error');
        el.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-exclamation-circle-fill';
        clearTimeout(el._timer);
        el._timer = setTimeout(() => el.classList.remove('show'), 4000);
    }

    // ── Live preview on input ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        ['liveHost', 'liveDefaultPath', 'liveWebrtcPort'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updatePreview);
        });

        // Load config when Live tab is first opened or already active
        if (document.querySelector('#stab-live.active')) {
            loadLiveConfig();
        }
    });

    // Re-load when switching to Live tab
    const origSwitch = window.switchSettingsTab;
    window.switchSettingsTab = function (tab) {
        origSwitch(tab);
        if (tab === 'live') loadLiveConfig();
    };

    // Spin keyframe (reuse if already defined)
    if (!document.getElementById('liveSpinStyle')) {
        const s = document.createElement('style');
        s.id = 'liveSpinStyle';
        s.textContent = '@keyframes spin { to { transform: rotate(360deg); } } .spin { display:inline-block; animation: spin .7s linear infinite; }';
        document.head.appendChild(s);
    }
})();
</script>

</body>
</html>
