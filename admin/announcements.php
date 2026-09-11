<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

// Ensure tables exist
$conn->query("
CREATE TABLE IF NOT EXISTS system_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_type ENUM('all', 'role', 'user') NOT NULL DEFAULT 'all',
    target_role ENUM('user', 'bidder', 'admin', 'superadmin') NULL,
    target_user_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (target_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
CREATE TABLE IF NOT EXISTS user_notification_reads (
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES system_notifications(notification_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$current_user_id = (int)$_SESSION['user_id'];
$current_role    = $_SESSION['role'] ?? 'superadmin';

// ── Handle Delete ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $del_id = (int)($_POST['announcement_id'] ?? 0);
    if ($del_id > 0) {
        $snap = $conn->prepare("SELECT title, message, target_type, target_role, target_user_id FROM system_notifications WHERE notification_id = ?");
        $snap->bind_param("i", $del_id);
        $snap->execute();
        $old_anno = $snap->get_result()->fetch_assoc();
        $snap->close();

        $del_stmt = $conn->prepare("DELETE FROM system_notifications WHERE notification_id = ?");
        $del_stmt->bind_param("i", $del_id);
        if ($del_stmt->execute()) {
            $atitle = $old_anno['title'] ?? "#$del_id";
            audit_log(
                $conn,
                'ANNOUNCEMENT_DELETED',
                'announcements',
                $del_id,
                "Deleted system announcement: {$atitle}",
                $old_anno,
                null
            );
            $_SESSION['alert_success'] = "Announcement deleted successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to delete announcement.";
        }
        $del_stmt->close();
    }
    header("Location: announcements.php");
    exit();
}

// ── Handle Create ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
    $title       = trim($_POST['title'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $target_type = trim($_POST['target_type'] ?? 'all');
    $target_role = !empty($_POST['target_role']) ? trim($_POST['target_role']) : null;
    $target_user = !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : null;

    if (!empty($title) && !empty($message)) {
        if (!in_array($target_type, ['all', 'role', 'user'])) $target_type = 'all';
        if ($target_type === 'role' && !in_array($target_role, ['user', 'bidder', 'admin', 'superadmin'])) $target_role = 'bidder';
        if ($target_type !== 'user') $target_user = null;

        $stmt = $conn->prepare("
            INSERT INTO system_notifications (title, message, target_type, target_role, target_user_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssii", $title, $message, $target_type, $target_role, $target_user, $current_user_id);
        if ($stmt->execute()) {
            $new_anno_id = $conn->insert_id;
            audit_log(
                $conn,
                'ANNOUNCEMENT_CREATED',
                'announcements',
                $new_anno_id,
                "Published system announcement: {$title}",
                null,
                [
                    'title' => $title,
                    'message' => $message,
                    'target_type' => $target_type,
                    'target_role' => $target_role,
                    'target_user_id' => $target_user
                ]
            );

            $_SESSION['alert_success'] = "Announcement published successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to publish announcement: " . $conn->error;
        }
        $stmt->close();
    }
    header("Location: announcements.php");
    exit();
}

function pct2($val, $total) {
    if ($total == 0) return 0;
    return round(($val / $total) * 100);
}

// ── Statistics Breakdown (matching procurement.php donut math) ───────────────
$stat_all     = (int)$conn->query("SELECT COUNT(*) FROM system_notifications WHERE target_type = 'all'")->fetch_row()[0];
$stat_bidders = (int)$conn->query("SELECT COUNT(*) FROM system_notifications WHERE target_type = 'role' AND target_role = 'bidder'")->fetch_row()[0];
$stat_admins  = (int)$conn->query("SELECT COUNT(*) FROM system_notifications WHERE target_type = 'role' AND target_role = 'admin'")->fetch_row()[0];
$stat_users   = (int)$conn->query("SELECT COUNT(*) FROM system_notifications WHERE target_type = 'role' AND target_role = 'user'")->fetch_row()[0];
$stat_direct  = (int)$conn->query("SELECT COUNT(*) FROM system_notifications WHERE target_type = 'user'")->fetch_row()[0];

$counts = [
    'all'     => $stat_all,
    'bidders' => $stat_bidders,
    'admins'  => $stat_admins,
    'users'   => $stat_users,
    'direct'  => $stat_direct,
];
$stat_total = (int)$conn->query("SELECT COUNT(*) FROM system_notifications")->fetch_row()[0];

$colors = [
    'all'     => '#1565c0',
    'bidders' => '#C99A1D',
    'admins'  => '#219653',
    'users'   => '#7b1fa2',
    'direct'  => '#512da8',
];

$grad = '';
$cur  = 0;
foreach ($counts as $s => $cnt) {
    $end = $cur + pct2($cnt, $stat_total);
    $grad .= "{$colors[$s]} {$cur}% {$end}%, ";
    $cur = $end;
}
$grad = rtrim($grad, ', ');
if (!$grad || $stat_total == 0) $grad = '#e5eae4 0% 100%';



// ── Filters & Search ─────────────────────────────────────────────────────────
$filter_type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'all';
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clauses = [];
$params        = [];
$types         = '';

if ($filter_type === 'broadcast') {
    $where_clauses[] = "sn.target_type = 'all'";
} elseif ($filter_type === 'bidders') {
    $where_clauses[] = "(sn.target_type = 'role' AND sn.target_role = 'bidder')";
} elseif ($filter_type === 'admins') {
    $where_clauses[] = "(sn.target_type = 'role' AND sn.target_role = 'admin')";
} elseif ($filter_type === 'users') {
    $where_clauses[] = "(sn.target_type = 'role' AND sn.target_role = 'user')";
} elseif ($filter_type === 'direct') {
    $where_clauses[] = "sn.target_type = 'user'";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where_clauses[] = "(sn.title LIKE ? OR sn.message LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query_sql = "
    SELECT 
        sn.notification_id,
        sn.title,
        sn.message,
        sn.target_type,
        sn.target_role,
        sn.target_user_id,
        sn.created_at,
        u.firstname AS creator_fname,
        u.lastname AS creator_lname,
        u.username AS creator_username,
        tu.firstname AS target_fname,
        tu.lastname AS target_lname,
        tu.username AS target_username,
        tu.role AS target_user_role,
        (SELECT COUNT(*) FROM user_notification_reads unr WHERE unr.notification_id = sn.notification_id) AS read_count
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    LEFT JOIN users tu ON sn.target_user_id = tu.user_id
    $where_sql
    ORDER BY sn.created_at DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($query_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $announcements_res = $stmt->get_result();
} else {
    $announcements_res = $conn->query($query_sql);
}

// Fetch all users for the target user selector
$all_users_res = $conn->query("
    SELECT user_id, firstname, lastname, username, role, email 
    FROM users 
    ORDER BY role ASC, firstname ASC
");
$all_users = [];
while ($u = $all_users_res->fetch_assoc()) $all_users[] = $u;

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Announcements | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-announcements.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>System Announcements</h2>
        <p>Broadcast notices to everyone, specific user roles, or direct messages to individual accounts.</p>
    </div>

    <!-- Alert notifications -->
    <?php if (isset($_SESSION['alert_success'])): ?>
        <div class="anc-alert anc-alert--success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['alert_success']) ?></span>
        </div>
        <?php unset($_SESSION['alert_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['alert_error'])): ?>
        <div class="anc-alert anc-alert--error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['alert_error']) ?></span>
        </div>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>

    <!-- ════ STATISTICS PANEL (matching superadmin/procurement.php) ════ -->
    <div class="sp-panel mb-20">
        <div class="sp-panel-head">
            <div class="sp-panel-title">
                <div class="sp-title-icon"><i class="bi bi-pie-chart"></i></div>
                Announcement Statistics
            </div>
            <div class="sp-panel-sub">Breakdown by target audience</div>
        </div>

        <div class="sp-stats-body">
            <!-- Donut -->
            <div class="sp-donut-wrap">
                <div class="sp-donut" style="--donut-grad: <?= $grad ?>;"></div>
                <div class="sp-donut-hole">
                    <div class="sp-donut-num"><?= $stat_total ?></div>
                    <div class="sp-donut-lbl">Total<br>Announcements</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="sp-legend">
                <?php
                $legend = [
                    'all'     => ['label' => 'Broadcast (All)', 'mod' => 'all'],
                    'bidders' => ['label' => 'Bidders',         'mod' => 'bidders'],
                    'admins'  => ['label' => 'Administrators',  'mod' => 'admins'],
                    'users'   => ['label' => 'Normal Users',    'mod' => 'users'],
                    'direct'  => ['label' => 'Direct to User',  'mod' => 'direct'],
                ];
                foreach ($legend as $key => $l):
                    $cnt = $counts[$key];
                    $p   = pct2($cnt, $stat_total);
                ?>
                <div class="sp-legend-row">
                    <span class="sp-legend-dot anc-legend-dot--<?= $l['mod'] ?>"></span>
                    <span class="sp-legend-label"><?= $l['label'] ?></span>
                    <span class="sp-legend-value"><?= $cnt ?></span>
                    <span class="sp-legend-pct anc-legend-pct--<?= $l['mod'] ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
                <div class="sp-legend-row sp-legend-total">
                    <span class="sp-legend-dot anc-legend-dot--total"></span>
                    <span class="sp-legend-label">All Announcements</span>
                    <span class="sp-legend-value anc-legend-value--total"><?= $stat_total ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ ANNOUNCEMENTS LIST (matching procurement.php) ════ -->
    <!-- New announcement button -->
    <button type="button" class="sp-new-btn anc-new-btn" onclick="openCreateModal()">
        <i class="bi bi-plus-circle"></i> Broadcast New Announcement
    </button>

    <!-- List panel -->
    <div class="proc-table-panel mb-24">
        <div class="filter-bar">
            <form method="GET" action="" id="annoFilterForm" class="anc-filter-form">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by title, message, or author..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="anc-tabs">
                    <?php
                    $tabs = ['all'=>'All','broadcast'=>'Broadcast','bidders'=>'Bidders','admins'=>'Admins','users'=>'Users','direct'=>'Direct'];
                    foreach ($tabs as $val => $label):
                    ?>
                        <button type="submit" name="type" value="<?= $val ?>"
                                class="ap2-filter-btn <?= $filter_type === $val ? 'active' : '' ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if ($announcements_res->num_rows > 0): ?>
        <div class="table-scroll">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th>Title / Message</th>
                        <th class="col-mode">Target</th>
                        <th class="col-opening">Posted By</th>
                        <th class="col-abc">Date</th>
                        <th class="col-status">Views</th>
                        <th class="anc-actions-th">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $announcements_res->fetch_assoc()):
                    $tt = $row['target_type'];
                    $pillMod = 'all'; $badgeTxt = 'Broadcast'; $badgeIcn = 'globe';
                    if ($tt === 'role') {
                        $r = $row['target_role'];
                        if ($r === 'bidder')     { $pillMod='bidders';    $badgeTxt='Bidders';    $badgeIcn='person-badge'; }
                        elseif ($r === 'admin')  { $pillMod='admins';     $badgeTxt='Admins';     $badgeIcn='shield-check'; }
                        elseif ($r === 'user')   { $pillMod='users';      $badgeTxt='Users';      $badgeIcn='people'; }
                        elseif ($r === 'superadmin') { $pillMod='superadmin'; $badgeTxt='Superadmins'; $badgeIcn='person-gear'; }
                    } elseif ($tt === 'user') {
                        $pillMod='direct';
                        $targetName = trim(($row['target_fname']??'').' '.($row['target_lname']??'')) ?: $row['target_username'];
                        $badgeTxt = 'Direct: '.$targetName; $badgeIcn='person';
                    }
                    $creatorName = trim(($row['creator_fname']??'').' '.($row['creator_lname']??'')) ?: ($row['creator_username']??'Superadmin');
                ?>
                <tr>
                    <td class="proc-title-cell">
                        <span class="anc-row-title"><?= htmlspecialchars($row['title']) ?></span>
                        <div class="anc-msg-preview">
                            <?= htmlspecialchars($row['message']) ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-status-pill anc-target-pill--<?= $pillMod ?>">
                            <i class="bi bi-<?= $badgeIcn ?> fz-9"></i> <?= htmlspecialchars($badgeTxt) ?>
                        </span>
                    </td>
                    <td class="proc-deadline-cell col-opening"><?= htmlspecialchars($creatorName) ?></td>
                    <td class="proc-deadline-cell col-abc"><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
                    <td class="col-status anc-views-cell">
                        <i class="bi bi-eye clr-idle"></i> <?= (int)$row['read_count'] ?>
                    </td>
                    <td class="text-right">
                        <div class="anc-row-actions">
                            <button type="button" class="proc-action-btn btn-view"
                                    onclick="viewAnnouncement(<?= htmlspecialchars(json_encode($row)) ?>)">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <button type="button" class="proc-action-btn anc-del-btn"
                                    onclick="confirmDelete(<?= $row['notification_id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>')">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div class="table-foot">
            <div><?= $announcements_res->num_rows ?> announcement<?= $announcements_res->num_rows != 1 ? 's' : '' ?> shown</div>
        </div>
        <?php else: ?>
        <div class="anc-empty-state">
            <i class="bi bi-megaphone anc-empty-icon"></i>
            <div class="anc-empty-text">No announcements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</div>
        </div>
        <?php endif; ?>
    </div>

</div>
</main>

<!-- ════ CREATE ANNOUNCEMENT MODAL ════ -->
<div id="createAnnouncementModal" class="modal-backdrop modal-backdrop--top" onclick="if(event.target===this)closeCreateModal()">
    <div class="modal-box modal-box--scroll modal-box--580" >
        <div class="modal-header modal-header--tight">
            <h4 class="modal-title">
                <i class="bi bi-megaphone-fill clr-amber-icon"></i>
                <span>Broadcast New Announcement</span>
            </h4>
            <button type="button" class="modal-close" onclick="closeCreateModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <form method="POST" action="announcements.php" onsubmit="return validateAnnouncementForm()">
            <input type="hidden" name="create_announcement" value="1">
            <input type="hidden" id="selectedUserId" name="target_user_id" value="">

            <div class="anc-form-group">
                <label class="anc-form-label">
                    Announcement Title <span class="anc-required">*</span>
                </label>
                <input type="text" name="title" required placeholder="e.g., Scheduled System Maintenance or Bidding Updates" class="anc-form-input">
            </div>

            <!-- Target audience selection with tiles & icons -->
            <div class="anc-form-group">
                <label class="anc-form-label">
                    Target Audience <span class="anc-required">*</span>
                </label>
                <div class="anc-target-tiles">
                    <div>
                        <input type="radio" name="target_type" id="tt_all" value="all" checked class="anc-tile-input" onchange="handleTargetChange('all')">
                        <label for="tt_all" class="anc-tile-label">
                            <i class="bi bi-globe"></i>
                            <span>Everyone</span>
                        </label>
                    </div>
                    <div>
                        <input type="radio" name="target_type" id="tt_role" value="role" class="anc-tile-input" onchange="handleTargetChange('role')">
                        <label for="tt_role" class="anc-tile-label">
                            <i class="bi bi-people"></i>
                            <span>Specific Role</span>
                        </label>
                    </div>
                    <div>
                        <input type="radio" name="target_type" id="tt_user" value="user" class="anc-tile-input" onchange="handleTargetChange('user')">
                        <label for="tt_user" class="anc-tile-label">
                            <i class="bi bi-person"></i>
                            <span>Direct User</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Specific Role Selection -->
            <div class="anc-form-group hide" id="roleSelectBlock">
                <label class="anc-form-label">
                    Select Role <span class="anc-required">*</span>
                </label>
                <select name="target_role" id="targetRoleSelect" class="anc-form-input anc-form-input--select">
                    <option value="bidder">Bidders Only</option>
                    <option value="admin">Administrators Only</option>
                    <option value="user">Normal Users Only</option>
                    <option value="superadmin">Super Administrators</option>
                </select>
            </div>

            <!-- Direct User Search & Selection Block -->
            <div class="anc-form-group hide" id="userPickerBlock">
                <label class="anc-form-label">
                    Select Specific User <span class="anc-required">*</span>
                </label>
                <div class="anc-user-picker-box">
                    <div class="anc-picker-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="userSearchField" placeholder="Search by name, username, or role..." oninput="filterUserPicker(this.value)">
                    </div>
                    <div class="anc-user-list-scroll" id="userPickerList">
                        <?php foreach($all_users as $u): ?>
                            <div class="anc-user-option" data-id="<?= $u['user_id'] ?>" data-search="<?= strtolower(htmlspecialchars($u['firstname'].' '.$u['lastname'].' '.$u['username'].' '.$u['role'])) ?>" onclick="selectUserForAnnouncement(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['firstname'].' '.$u['lastname'])) ?>', this)">
                                <div>
                                    <div class="anc-user-option-name"><?= htmlspecialchars($u['firstname'].' '.$u['lastname']) ?></div>
                                    <div class="anc-user-option-sub">@<?= htmlspecialchars($u['username']) ?> · <?= htmlspecialchars($u['email']) ?></div>
                                </div>
                                <span class="anc-badge <?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="selectedUserBanner" class="anc-selected-banner">
                    <i class="bi bi-check-circle-fill"></i> Selected: <span id="selectedUserName"></span>
                </div>
            </div>

            <!-- Announcement message -->
            <div class="anc-form-group anc-form-group--tight">
                <label class="anc-form-label">
                    Announcement Message <span class="anc-required">*</span>
                </label>
                <textarea name="message" rows="3" required placeholder="Write the complete announcement text..." class="anc-form-input anc-form-input--textarea"></textarea>
            </div>

            <div class="anc-form-footer">
                <button type="button" onclick="closeCreateModal()" class="anc-btn-cancel">Cancel</button>
                <button type="submit" class="anc-btn-submit">
                    <i class="bi bi-send-fill"></i> Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════ VIEW ANNOUNCEMENT DETAIL MODAL ════ -->
<div id="viewAnnouncementModal" class="modal-backdrop modal-backdrop--top" onclick="if(event.target===this)closeViewModal()">
    <div class="modal-box modal-box--scroll modal-box--540">
        <div class="modal-header modal-header--tight">
            <h4 class="modal-title">
                <i class="bi bi-megaphone-fill clr-amber-view"></i>
                <span id="vaTitle">Announcement Details</span>
            </h4>
            <button type="button" class="modal-close" onclick="closeViewModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="anc-view-meta">
            <span id="vaBadge" class="sp-status-pill"><i class="bi bi-globe"></i> Broadcast</span>
            <span id="vaDate" class="anc-view-date"></span>
            <span id="vaAuthor" class="anc-view-author"></span>
        </div>

        <div id="vaMessage" class="anc-view-message">
        </div>

        <div class="anc-view-reads">
            <i class="bi bi-eye"></i> <span id="vaReads">0</span> users have viewed this notification
        </div>

        <div class="anc-form-footer anc-form-footer--view">
            <button type="button" onclick="closeViewModal()" class="anc-btn-submit">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ════ CONFIRM DELETE MODAL ════ -->
<div id="deleteConfirmModal" class="modal-backdrop modal-backdrop--center" onclick="if(event.target===this)closeDeleteModal()">
    <div class="modal-box modal-box--scroll modal-box--scroll-sm modal-box--420 text-center">
        <div class="anc-del-icon">
            <i class="bi bi-trash-fill"></i>
        </div>
        <h4 class="anc-del-title">Delete Announcement?</h4>
        <p class="anc-del-desc">Are you sure you want to permanently delete this announcement? Recipients will no longer see it.</p>

        <div id="delTitlePreview" class="anc-del-preview">
        </div>

        <form method="POST" action="announcements.php">
            <input type="hidden" name="delete_announcement" value="1">
            <input type="hidden" id="delAnnouncementId" name="announcement_id" value="">
            <div class="anc-form-footer anc-form-footer--center">
                <button type="button" onclick="closeDeleteModal()" class="anc-btn-cancel">Cancel</button>
                <button type="submit" class="anc-btn-delete">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>


    // Modal helpers
    function openCreateModal() {
        document.getElementById('createAnnouncementModal').classList.add('open');
        handleTargetChange('all');
    }
    function closeCreateModal() {
        document.getElementById('createAnnouncementModal').classList.remove('open');
    }

    function handleTargetChange(type) {
        document.getElementById('roleSelectBlock').classList.toggle('hide', type !== 'role');
        document.getElementById('userPickerBlock').classList.toggle('hide', type !== 'user');
    }

    function filterUserPicker(query) {
        const q = query.toLowerCase().trim();
        const options = document.querySelectorAll('#userPickerList .anc-user-option');
        options.forEach(opt => {
            const str = opt.getAttribute('data-search') || '';
            opt.classList.toggle('hide', !str.includes(q));
        });
    }

    function selectUserForAnnouncement(userId, userName, el) {
        document.getElementById('selectedUserId').value = userId;
        document.getElementById('selectedUserName').textContent = userName;
        document.getElementById('selectedUserBanner').classList.add('show');
        document.querySelectorAll('#userPickerList .anc-user-option').forEach(o => o.classList.remove('selected'));
        if (el) el.classList.add('selected');
    }

    function validateAnnouncementForm() {
        const type = document.querySelector('input[name="target_type"]:checked').value;
        if (type === 'user') {
            const uid = document.getElementById('selectedUserId').value;
            if (!uid) {
                alert('Please select a specific recipient user from the list.');
                return false;
            }
        }
        return true;
    }

    function viewAnnouncement(data) {
        document.getElementById('vaTitle').textContent = data.title;
        document.getElementById('vaDate').textContent = new Date(data.created_at).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
        document.getElementById('vaAuthor').textContent = `By: ${data.creator_fname || 'Admin'} ${data.creator_lname || ''}`;
        document.getElementById('vaMessage').textContent = data.message;
        document.getElementById('vaReads').textContent = data.read_count || 0;

        let badgeHtml = '<i class="bi bi-globe"></i> Broadcast (All)';
        let pillMod = 'all';

        if (data.target_type === 'role') {
            const r = data.target_role;
            if (r === 'bidder') {
                pillMod = 'bidders'; badgeHtml = '<i class="bi bi-person-badge"></i> ROLE: BIDDERS';
            } else if (r === 'admin') {
                pillMod = 'admins'; badgeHtml = '<i class="bi bi-shield-check"></i> ROLE: ADMINS';
            } else if (r === 'user') {
                pillMod = 'users'; badgeHtml = '<i class="bi bi-people"></i> ROLE: USERS';
            } else if (r === 'superadmin') {
                pillMod = 'superadmin'; badgeHtml = '<i class="bi bi-person-gear"></i> ROLE: SUPERADMINS';
            }
        } else if (data.target_type === 'user') {
            pillMod = 'direct';
            badgeHtml = `<i class="bi bi-person"></i> Direct to User`;
        }

        const badgeEl = document.getElementById('vaBadge');
        badgeEl.innerHTML = badgeHtml;
        badgeEl.className = `sp-status-pill anc-target-pill--${pillMod}`;

        document.getElementById('viewAnnouncementModal').classList.add('open');
    }
    function closeViewModal() {
        document.getElementById('viewAnnouncementModal').classList.remove('open');
    }

    function confirmDelete(id, title) {
        document.getElementById('delAnnouncementId').value = id;
        document.getElementById('delTitlePreview').textContent = title;
        document.getElementById('deleteConfirmModal').classList.add('open');
    }
    function closeDeleteModal() {
        document.getElementById('deleteConfirmModal').classList.remove('open');
    }
</script>

</body>
</html>
