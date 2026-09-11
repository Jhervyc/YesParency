<?php
include("utils/protect-page.php");

// Handle create admin account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    $firstname  = trim($_POST['firstname']  ?? '');
    $lastname   = trim($_POST['lastname']   ?? '');
    $username   = trim($_POST['username']   ?? '');
    $email      = trim($_POST['email']      ?? '');
    $password   = $_POST['password']        ?? '';
    $admin_type = in_array($_POST['admin_type'] ?? '', ['BAC','TWG','SECRETARIAT'])
                  ? $_POST['admin_type'] : 'SECRETARIAT';

    if (!$firstname || !$lastname || !$username || !$email || !$password) {
        $_SESSION['alert_msg']  = "All fields are required.";
        $_SESSION['alert_type'] = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['alert_msg']  = "Invalid email address.";
        $_SESSION['alert_type'] = "error";
    } elseif (strlen($password) < 8) {
        $_SESSION['alert_msg']  = "Password must be at least 8 characters.";
        $_SESSION['alert_type'] = "error";
    } else {
        // Check username/email uniqueness
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $chk->bind_param("ss", $username, $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $_SESSION['alert_msg']  = "Username or email already exists.";
            $_SESSION['alert_type'] = "error";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins  = $conn->prepare("INSERT INTO users (firstname, lastname, username, email, password, role, status) VALUES (?, ?, ?, ?, ?, 'admin', 'active')");
            $ins->bind_param("sssss", $firstname, $lastname, $username, $email, $hash);
            if ($ins->execute()) {
                $new_id = $ins->insert_id;
                $ins->close();
                $role_stmt = $conn->prepare("INSERT INTO admin_roles (user_id, admin_type) VALUES (?, ?) ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)");
                $role_stmt->bind_param("is", $new_id, $admin_type);
                $role_stmt->execute();
                $role_stmt->close();
                $_SESSION['alert_msg']  = "Admin account created for {$firstname} {$lastname} ({$admin_type}).";
                $_SESSION['alert_type'] = "success";
            } else {
                $_SESSION['alert_msg']  = "Failed to create account: " . $conn->error;
                $_SESSION['alert_type'] = "error";
            }
        }
        $chk->close();
    }
    header("Location: user-role-management.php");
    exit();
}

// Handle promote to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_user'])) {
    $target_id  = intval($_POST['user_id']);
    $admin_type = isset($_POST['admin_type']) && in_array($_POST['admin_type'], ['BAC', 'TWG', 'SECRETARIAT'])
                  ? $_POST['admin_type'] : 'SECRETARIAT';

    if ($target_id === intval($_SESSION['user_id'])) {
        $_SESSION['alert_msg']  = "You cannot change your own role.";
        $_SESSION['alert_type'] = "error";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE user_id = ? AND role != 'superadmin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Upsert admin_type into admin_roles
            $role_stmt = $conn->prepare("INSERT INTO admin_roles (user_id, admin_type) VALUES (?, ?) ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)");
            $role_stmt->bind_param("is", $target_id, $admin_type);
            $role_stmt->execute();
            $role_stmt->close();
            $_SESSION['alert_msg']  = "User promoted to Administrator ({$admin_type}).";
            $_SESSION['alert_type'] = "success";
        } else {
            $_SESSION['alert_msg']  = "Could not promote user. They may already be an admin or superadmin.";
            $_SESSION['alert_type'] = "error";
        }
        $stmt->close();
    }
    $qs = http_build_query(array_filter(['search' => $_POST['search'] ?? '', 'role' => $_POST['role'] ?? '']));
    header("Location: user-role-management.php" . ($qs ? "?$qs" : ""));
    exit();
}

// Handle demote back to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demote_user'])) {
    $target_id = intval($_POST['user_id']);
    if ($target_id === intval($_SESSION['user_id'])) {
        $_SESSION['alert_msg']  = "You cannot change your own role.";
        $_SESSION['alert_type'] = "error";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = 'user' WHERE user_id = ? AND role = 'admin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['alert_msg']  = "Administrator demoted back to User.";
            $_SESSION['alert_type'] = "success";
        } else {
            $_SESSION['alert_msg']  = "Could not demote user.";
            $_SESSION['alert_type'] = "error";
        }
        $stmt->close();
    }
    $qs = http_build_query(array_filter(['search' => $_POST['search'] ?? '', 'role' => $_POST['role'] ?? '']));
    header("Location: user-role-management.php" . ($qs ? "?$qs" : ""));
    exit();
}

// Handle delete account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $target_id = intval($_POST['user_id']);
    if ($target_id === intval($_SESSION['user_id'])) {
        $_SESSION['alert_msg']  = "You cannot delete your own account.";
        $_SESSION['alert_type'] = "error";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role != 'superadmin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['alert_msg']  = "User account deleted successfully.";
            $_SESSION['alert_type'] = "success";
        } else {
            $_SESSION['alert_msg']  = "Could not delete user. Superadmin accounts are protected.";
            $_SESSION['alert_type'] = "error";
        }
        $stmt->close();
    }
    $qs = http_build_query(array_filter(['search' => $_POST['search'] ?? '', 'role' => $_POST['role'] ?? '']));
    header("Location: user-role-management.php" . ($qs ? "?$qs" : ""));
    exit();
}

// Pick up flash message after redirect
$message     = $_SESSION['alert_msg']  ?? '';
$messageType = $_SESSION['alert_type'] ?? '';
unset($_SESSION['alert_msg'], $_SESSION['alert_type']);

// Stats
$total_users   = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetch_row()[0];
$total_admins  = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0];
$total_bidders = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'bidder'")->fetch_row()[0];
$total_normal  = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetch_row()[0];

// Search + filter
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) && in_array($_GET['role'], ['all','user','admin','bidder']) ? $_GET['role'] : 'all';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;
$offset      = ($page - 1) * $per_page;

// Build WHERE
$where  = ["role != 'superadmin'"];
$params = []; $types = '';
if ($role_filter !== 'all') { $where[] = "role = ?"; $params[] = $role_filter; $types .= 's'; }
if ($search !== '') {
    $like = '%'.$search.'%';
    $where[] = "(username LIKE ? OR email LIKE ? OR firstname LIKE ? OR lastname LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
$wsql = 'WHERE '.implode(' AND ', $where);

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM users $wsql");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, ceil($total_shown / $per_page));

// Rows
$lp = array_merge($params, [$per_page, $offset]);
$lt = $types . 'ii';
$stmt = $conn->prepare("SELECT user_id,firstname,lastname,username,email,role,status,profile_picture_url FROM users $wsql ORDER BY role ASC, username ASC LIMIT ? OFFSET ?");
$stmt->bind_param($lt, ...$lp);
$stmt->execute();
$users = $stmt->get_result();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User & Role Management | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-user-role-management.css">
</head>
<body class="dash-body">

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page heading -->
        <div class="page-header">
            <h2>User & Role Management</h2>
            <p>Search users, promote to Administrator, demote, or delete accounts.</p>
        </div>

        <!-- Toast -->
        <?php if ($message): ?>
            <div class="toast-alert <?= $messageType === 'success' ? 'success' : 'error' ?>" id="toastAlert">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stat cards -->
        <div class="sad-section-label">Summary</div>
        <div class="ap2-stats ap2-stats-4">
            <div class="ap2-stat">
                <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%;">
                    <div class="ap2-ring-inner"><i class="bi bi-people clr-dark"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_users ?></div>
                    <div class="ap2-stat-lbl">Total Users</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="--ring-color:#43a047; --pct:<?= $total_users > 0 ? round($total_admins/$total_users*100) : 0 ?>%;">
                    <div class="ap2-ring-inner"><i class="bi bi-shield-check clr-green"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_admins ?></div>
                    <div class="ap2-stat-lbl">Administrators</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="--ring-color:#f9a825; --pct:<?= $total_users > 0 ? round($total_bidders/$total_users*100) : 0 ?>%;">
                    <div class="ap2-ring-inner"><i class="bi bi-person-badge clr-amber"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_bidders ?></div>
                    <div class="ap2-stat-lbl">Bidders</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="--ring-color:#1565c0; --pct:<?= $total_users > 0 ? round($total_normal/$total_users*100) : 0 ?>%;">
                    <div class="ap2-ring-inner"><i class="bi bi-person clr-blue"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_normal ?></div>
                    <div class="ap2-stat-lbl">Normal Users</div>
                </div>
            </div>
        </div>

        <!-- Directory card -->
        <div class="directory-toolbar">
            <button type="button" class="ap2-go-btn ap2-go-btn--gap" onclick="openCreateAdminModal()">
                <i class="bi bi-person-plus-fill"></i> Create Admin Account
            </button>
        </div>
        <div class="ap2-card">

            <div class="ap2-card-head">
                <h3>Directory</h3>
            </div>

            <!-- Search + filter controls -->
            <form method="GET" action="" class="ap2-controls mb-16">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                           placeholder="Search by name, username, or email"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="ap2-filters">
                    <?php
                    $tabs = ['all' => 'All', 'user' => 'Users', 'admin' => 'Admins', 'bidder' => 'Bidders'];
                    foreach ($tabs as $val => $label):
                    ?>
                        <button type="submit" name="role" value="<?= $val ?>"
                                class="ap2-filter-btn <?= $role_filter === $val ? 'active' : '' ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn">
                    <i class="bi bi-search"></i> Search
                </button>
            </form>

            <!-- Table header -->
            <div class="ap2-table-head">
                <span>User</span>
                <span>Actions</span>
            </div>

            <!-- User rows -->
            <?php if ($total_shown === 0): ?>
                <div class="empty-state empty-state--lg">
                    <i class="bi bi-people"></i>
                    <p>No users found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
                </div>
            <?php else: ?>
                <?php while ($user = $users->fetch_assoc()):
                    $isSelf     = $user['user_id'] === intval($_SESSION['user_id']);
                    $initials   = strtoupper(substr($user['firstname'],0,1).substr($user['lastname'],0,1));
                    $roleClass  = $user['role'] === 'admin' ? 'ap2-badge-admin' : ($user['role'] === 'bidder' ? 'ap2-badge-bidder' : 'ap2-badge-user');
                    $safeFullName = htmlspecialchars(addslashes($user['firstname'].' '.$user['lastname']));
                    $safeUsername = htmlspecialchars(addslashes($user['username']));
                    $userAvatarUrl = !empty($user['profile_picture_url']) ? '../' . ltrim($user['profile_picture_url'], '/') : '';
                ?>
                <div class="ap2-user-row">

                    <div class="ap2-who-cell">
                        <div class="ap2-avatar ap2-avatar--<?= $user['role'] ?> avatar-cover">
                            <?php if (!empty($userAvatarUrl)): ?>
                                <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>
                        <div class="ap2-user-text">
                            <div class="ap2-user-name"><?= htmlspecialchars($user['firstname'].' '.$user['lastname']) ?></div>
                            <div class="ap2-user-sub">
                                @<?= htmlspecialchars($user['username']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($user['email']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="ap2-action-cell">
                        <span class="ap2-badge <?= $roleClass ?>"><?= strtoupper($user['role']) ?></span>
                    <?php if ($isSelf): ?>
                        <span class="ap2-self-label">You</span>
                    <?php else: ?>

                        <?php if ($user['role'] !== 'admin'): ?>
                            <button type="button" class="ap2-action-btn ap2-promote"
                                    onclick="openConfirm('promote', <?= $user['user_id'] ?>, '<?= $safeFullName ?>', '<?= $safeUsername ?>')">
                                <i class="bi bi-person-up"></i> Promote
                            </button>
                        <?php else: ?>
                            <button type="button" class="ap2-action-btn ap2-demote"
                                    onclick="openConfirm('demote', <?= $user['user_id'] ?>, '<?= $safeFullName ?>', '<?= $safeUsername ?>')">
                                <i class="bi bi-person-down"></i> Demote
                            </button>
                        <?php endif; ?>

                        <button type="button" class="ap2-action-btn ap2-delete"
                                onclick="openConfirm('delete', <?= $user['user_id'] ?>, '<?= $safeFullName ?>', '<?= $safeUsername ?>')">
                            <i class="bi bi-trash3"></i> Delete
                        </button>

                    <?php endif; ?>
                    </div>

                </div>
                <?php endwhile; ?>
            <?php endif; ?>

            <div class="ap2-card-foot ap2-card-foot--flex">
                <div>
                    Showing <strong><?= min($total_shown, $offset+1) ?></strong>–<strong><?= min($total_shown, $offset+$per_page) ?></strong>
                    of <strong><?= number_format($total_shown) ?></strong> result<?= $total_shown !== 1 ? 's' : '' ?>
                    <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
                </div>
                <?php if ($total_pages > 1):
                    $qs = array_filter(['search'=>$search, 'role'=>$role_filter!=='all'?$role_filter:null]);
                    $qstr = $qs ? '&'.http_build_query($qs) : '';
                ?>
                <div class="pagination">
                    <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                    <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                    <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /.ap2-card -->

    </div>
</main>

<!-- ========================= -->
<!-- CREATE ADMIN MODAL        -->
<!-- ========================= -->
<div id="createAdminModal" class="modal-backdrop">
    <div class="urm-modal modal-wide">
        <button class="urm-modal-close" onclick="closeCreateAdminModal()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="urm-modal-icon-wrap modal-icon-wrap--left">
            <div class="urm-modal-icon modal-icon--green">
                <i class="bi bi-person-plus-fill"></i>
            </div>
        </div>

        <div class="urm-modal-text modal-text--left">
            <h3>Create Admin Account</h3>
            <p>Fill in the details below to create a new administrator account.</p>
        </div>

        <form method="POST" action="user-role-management.php" id="createAdminForm" class="modal-form-pad">
            <input type="hidden" name="create_admin" value="1">

            <div class="urmf-field-row-2">
                <div>
                    <label class="urmf-label">First Name <span class="urmf-required">*</span></label>
                    <div class="urmf-input-wrap">
                        <i class="bi bi-person urmf-input-icon"></i>
                        <input type="text" name="firstname" required placeholder="Juan" class="urmf-input">
                    </div>
                </div>
                <div>
                    <label class="urmf-label">Last Name <span class="urmf-required">*</span></label>
                    <div class="urmf-input-wrap">
                        <i class="bi bi-person urmf-input-icon"></i>
                        <input type="text" name="lastname" required placeholder="dela Cruz" class="urmf-input">
                    </div>
                </div>
            </div>

            <div class="urmf-field-group">
                <label class="urmf-label">Username <span class="urmf-required">*</span></label>
                <div class="urmf-input-wrap">
                    <i class="bi bi-at urmf-input-icon"></i>
                    <input type="text" name="username" required placeholder="juandelacruz" class="urmf-input">
                </div>
            </div>

            <div class="urmf-field-group">
                <label class="urmf-label">Email Address <span class="urmf-required">*</span></label>
                <div class="urmf-input-wrap">
                    <i class="bi bi-envelope urmf-input-icon"></i>
                    <input type="email" name="email" required placeholder="juan@slsu.edu.ph" class="urmf-input">
                </div>
            </div>

            <div class="urmf-field-group">
                <label class="urmf-label">Password <span class="urmf-required">*</span></label>
                <div class="urmf-input-wrap">
                    <i class="bi bi-lock urmf-input-icon"></i>
                    <input type="password" name="password" id="createAdminPw" required placeholder="Min. 8 characters" class="urmf-input urmf-input--pw">
                    <button type="button" onclick="toggleCreatePw()" class="urmf-pw-toggle">
                        <i class="bi bi-eye" id="createAdminPwIcon"></i>
                    </button>
                </div>
            </div>

            <div class="urmf-field-group--lg">
                <label class="urmf-label">
                    <i class="bi bi-shield-check urmf-role-icon"></i> Admin Role <span class="urmf-required">*</span>
                </label>
                <select name="admin_type" class="urmf-select">
                    <option value="SECRETARIAT">Secretariat — Full Access</option>
                    <option value="BAC">BAC — Limited Access</option>
                    <option value="TWG">TWG — Limited Access</option>
                </select>
            </div>

            <div class="urm-modal-actions modal-actions--flush">
                <button type="button" onclick="closeCreateAdminModal()" class="urm-btn-cancel">Cancel</button>
                <button type="submit" class="urm-btn-confirm confirm-btn--promote">
                    <i class="bi bi-person-plus-fill"></i> Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================= -->
<!-- CONFIRMATION MODAL        -->
<!-- ========================= -->
<div id="confirmModal" class="modal-backdrop">
    <div class="urm-modal">
        <!-- Close -->
        <button class="urm-modal-close" onclick="closeConfirm()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <!-- Centered icon -->
        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon" id="modalIcon">
                <i class="bi bi-person-gear" id="modalIconInner"></i>
            </div>
        </div>

        <!-- Title + description -->
        <div class="urm-modal-text">
            <h3 id="modalTitle">Confirm Action</h3>
            <p id="modalDesc">Are you sure?</p>
            <div class="urm-modal-user-pill" id="modalUserPill"></div>
            <!-- Admin role selector (shown only for promote) -->
            <div id="adminRoleField" class="admin-role-field hide">
                <label class="urmf-label urmf-label--md">
                    <i class="bi bi-shield-check urmf-role-icon"></i> Assign Admin Role
                </label>
                <select id="adminTypeSelect" class="urm-role-select">
                    <option value="SECRETARIAT">Secretariat — Full Access</option>
                    <option value="BAC">BAC — Limited Access</option>
                    <option value="TWG">TWG — Limited Access</option>
                </select>
            </div>
        </div>

        <!-- Actions -->
        <div class="urm-modal-actions">
            <button type="button" onclick="closeConfirm()" class="urm-btn-cancel">
                Cancel
            </button>
            <button type="button" id="modalConfirmBtn" class="urm-btn-confirm">
                Confirm
            </button>
        </div>
    </div>
</div>

<!-- Hidden form submitted by modal -->
<form id="actionForm" method="POST" action="" class="hide">
    <input type="hidden" id="actionUserId" name="user_id">
    <input type="hidden" id="actionType"   name="" value="1">
    <input type="hidden" id="actionAdminType" name="admin_type" value="SECRETARIAT">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="role"   value="<?= htmlspecialchars($role_filter) ?>">
</form>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    const actionColors = {
        promote: '#43a047',
        demote:  '#e67e22',
        delete:  '#e53935',
    };

    function openCreateAdminModal() {
        document.getElementById('createAdminModal').classList.add('open');
        document.getElementById('createAdminForm').reset();
    }

    function closeCreateAdminModal() {
        document.getElementById('createAdminModal').classList.remove('open');
    }

    function toggleCreatePw() {
        const inp  = document.getElementById('createAdminPw');
        const icon = document.getElementById('createAdminPwIcon');
        inp.type   = inp.type === 'password' ? 'text' : 'password';
        icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    }

    document.getElementById('createAdminModal').addEventListener('click', function(e) {
        if (e.target === this) closeCreateAdminModal();
    });

    function openConfirm(action, userId, fullName, username) {
        const modal         = document.getElementById('confirmModal');
        const iconWrap      = document.getElementById('modalIcon');
        const iconEl        = document.getElementById('modalIconInner');
        const title         = document.getElementById('modalTitle');
        const desc          = document.getElementById('modalDesc');
        const pill          = document.getElementById('modalUserPill');
        const btn           = document.getElementById('modalConfirmBtn');
        const typeInput     = document.getElementById('actionType');
        const roleField     = document.getElementById('adminRoleField');
        const adminTypeSel  = document.getElementById('adminTypeSelect');

        const cfg = {
            promote: {
                icon:  'bi-person-up',
                title: 'Promote to Administrator',
                desc:  'Select a role and confirm. The user will gain admin access based on the assigned role.',
                btnTx: 'Yes, Promote',
                name:  'promote_user',
            },
            demote: {
                icon:  'bi-person-down',
                title: 'Demote to User',
                desc:  'This user will lose all administrator privileges.',
                btnTx: 'Yes, Demote',
                name:  'demote_user',
            },
            delete: {
                icon:  'bi-trash3',
                title: 'Delete Account',
                desc:  'This will permanently delete the account. This cannot be undone.',
                btnTx: 'Yes, Delete',
                name:  'delete_user',
            },
        };

        const c = cfg[action];

        iconWrap.className        = `urm-modal-icon confirm-icon--${action}`;
        iconEl.className          = `bi ${c.icon}`;
        title.textContent         = c.title;
        desc.textContent          = c.desc;
        pill.textContent          = `${fullName}  ·  @${username}`;
        btn.textContent           = c.btnTx;
        btn.className             = `urm-btn-confirm confirm-btn--${action}`;

        document.getElementById('actionUserId').value = userId;
        typeInput.name  = c.name;
        typeInput.value = '1';

        // Show role selector only for promote
        if (action === 'promote') {
            roleField.classList.remove('hide');
            adminTypeSel.value = 'SECRETARIAT';
            document.getElementById('actionAdminType').value = 'SECRETARIAT';
        } else {
            roleField.classList.add('hide');
        }

        modal.classList.add('open');
    }

    function closeConfirm() {
        document.getElementById('confirmModal').classList.remove('open');
    }

    document.getElementById('modalConfirmBtn').addEventListener('click', () => {
        // Sync role selector to hidden input before submitting
        const adminTypeSel = document.getElementById('adminTypeSelect');
        const adminTypeInput = document.getElementById('actionAdminType');
        if (!document.getElementById('adminRoleField').classList.contains('hide')) {
            adminTypeInput.value = adminTypeSel.value;
        }
        document.getElementById('actionForm').submit();
    });

    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) closeConfirm();
    });

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
