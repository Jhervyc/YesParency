<?php
include("utils/protect-page.php");

// Handle promote to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_user'])) {
    $target_id = intval($_POST['user_id']);
    if ($target_id === intval($_SESSION['user_id'])) {
        $_SESSION['alert_msg']  = "You cannot change your own role.";
        $_SESSION['alert_type'] = "error";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE user_id = ? AND role != 'superadmin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['alert_msg']  = "User successfully promoted to Administrator.";
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

if ($search !== '') {
    if ($role_filter !== 'all') {
        $stmt = $conn->prepare("SELECT user_id,firstname,lastname,username,email,role,status,profile_picture_url FROM users WHERE role != 'superadmin' AND role = ? AND (username LIKE ? OR email LIKE ? OR firstname LIKE ? OR lastname LIKE ?) ORDER BY role ASC, username ASC");
        $like = '%'.$search.'%';
        $stmt->bind_param("sssss", $role_filter, $like, $like, $like, $like);
    } else {
        $stmt = $conn->prepare("SELECT user_id,firstname,lastname,username,email,role,status,profile_picture_url FROM users WHERE role != 'superadmin' AND (username LIKE ? OR email LIKE ? OR firstname LIKE ? OR lastname LIKE ?) ORDER BY role ASC, username ASC");
        $like = '%'.$search.'%';
        $stmt->bind_param("ssss", $like, $like, $like, $like);
    }
} else {
    if ($role_filter !== 'all') {
        $stmt = $conn->prepare("SELECT user_id,firstname,lastname,username,email,role,status,profile_picture_url FROM users WHERE role != 'superadmin' AND role = ? ORDER BY role ASC, username ASC");
        $stmt->bind_param("s", $role_filter);
    } else {
        $stmt = $conn->prepare("SELECT user_id,firstname,lastname,username,email,role,status,profile_picture_url FROM users WHERE role != 'superadmin' ORDER BY role ASC, username ASC");
    }
}
$stmt->execute();
$users = $stmt->get_result();
$total_shown = $users->num_rows;
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
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
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
                <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                    <div class="ap2-ring-inner"><i class="bi bi-people" style="color:#06251b;"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_users ?></div>
                    <div class="ap2-stat-lbl">Total Users</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="background:conic-gradient(#43a047 0% <?= $total_users > 0 ? round($total_admins/$total_users*100) : 0 ?>%, #e7ece9 0%);">
                    <div class="ap2-ring-inner"><i class="bi bi-shield-check" style="color:#43a047;"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_admins ?></div>
                    <div class="ap2-stat-lbl">Administrators</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="background:conic-gradient(#f9a825 0% <?= $total_users > 0 ? round($total_bidders/$total_users*100) : 0 ?>%, #e7ece9 0%);">
                    <div class="ap2-ring-inner"><i class="bi bi-person-badge" style="color:#f9a825;"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_bidders ?></div>
                    <div class="ap2-stat-lbl">Bidders</div>
                </div>
            </div>
            <div class="ap2-stat">
                <div class="ap2-ring" style="background:conic-gradient(#1565c0 0% <?= $total_users > 0 ? round($total_normal/$total_users*100) : 0 ?>%, #e7ece9 0%);">
                    <div class="ap2-ring-inner"><i class="bi bi-person" style="color:#1565c0;"></i></div>
                </div>
                <div class="ap2-stat-text">
                    <div class="ap2-stat-num"><?= $total_normal ?></div>
                    <div class="ap2-stat-lbl">Normal Users</div>
                </div>
            </div>
        </div>

        <!-- Directory card -->
        <div class="ap2-card">

            <div class="ap2-card-head">
                <h3>Directory</h3>
            </div>

            <!-- Search + filter controls -->
            <form method="GET" action="" class="ap2-controls" style="margin-bottom: 16px;">
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
                <div class="empty-state" style="padding:40px;">
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
                        <div class="ap2-avatar ap2-avatar--<?= $user['role'] ?>" style="overflow:hidden;display:flex;align-items:center;justify-content:center;">
                            <?php if (!empty($userAvatarUrl)): ?>
                                <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
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
                        <span class="ap2-badge <?= $roleClass ?>"><?= strtoupper($user['role']) ?></span>
                    </div>

                    <div class="ap2-action-cell">
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

            <div class="ap2-card-foot">
                Showing <?= $total_shown ?> result<?= $total_shown !== 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>

        </div><!-- /.ap2-card -->

    </div>
</main>

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
<form id="actionForm" method="POST" action="" style="display:none;">
    <input type="hidden" id="actionUserId" name="user_id">
    <input type="hidden" id="actionType"   name="" value="1">
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

    function openConfirm(action, userId, fullName, username) {
        const modal     = document.getElementById('confirmModal');
        const iconWrap  = document.getElementById('modalIcon');
        const iconEl    = document.getElementById('modalIconInner');
        const title     = document.getElementById('modalTitle');
        const desc      = document.getElementById('modalDesc');
        const pill      = document.getElementById('modalUserPill');
        const btn       = document.getElementById('modalConfirmBtn');
        const typeInput = document.getElementById('actionType');

        const cfg = {
            promote: {
                icon:    'bi-person-up',
                color:   '#43a047',
                bg:      '#e8f5e9',
                title:   'Promote to Administrator',
                desc:    'This user will gain full admin access to the system.',
                btnTx:   'Yes, Promote',
                name:    'promote_user',
            },
            demote: {
                icon:    'bi-person-down',
                color:   '#e67e22',
                bg:      '#fff3e0',
                title:   'Demote to User',
                desc:    'This user will lose all administrator privileges.',
                btnTx:   'Yes, Demote',
                name:    'demote_user',
            },
            delete: {
                icon:    'bi-trash3',
                color:   '#e53935',
                bg:      '#ffebee',
                title:   'Delete Account',
                desc:    'This will permanently delete the account. This cannot be undone.',
                btnTx:   'Yes, Delete',
                name:    'delete_user',
            },
        };

        const c = cfg[action];

        iconWrap.style.background = c.bg;
        iconWrap.style.color      = c.color;
        iconEl.className          = `bi ${c.icon}`;
        title.textContent         = c.title;
        desc.textContent          = c.desc;
        pill.textContent          = `${fullName}  ·  @${username}`;
        btn.textContent           = c.btnTx;
        btn.style.background      = c.color;

        document.getElementById('actionUserId').value = userId;
        typeInput.name  = c.name;
        typeInput.value = '1';

        modal.classList.add('open');
    }

    function closeConfirm() {
        document.getElementById('confirmModal').classList.remove('open');
    }

    document.getElementById('modalConfirmBtn').addEventListener('click', () => {
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
