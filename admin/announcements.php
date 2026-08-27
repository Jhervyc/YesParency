<?php
include("utils/protect-page.php");

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
        $del_stmt = $conn->prepare("DELETE FROM system_notifications WHERE notification_id = ?");
        $del_stmt->bind_param("i", $del_id);
        $del_stmt->execute();
        $del_stmt->close();
        $_SESSION['alert_success'] = "Announcement deleted successfully.";
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* Target selector radio tiles */
        .anc-target-tiles {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }
        .anc-tile-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px 10px;
            border: 1.5px solid #d4e0d8;
            border-radius: 12px;
            background: #fafcfb;
            cursor: pointer;
            transition: all .15s ease;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            color: #55665a;
        }
        .anc-tile-label i { font-size: 18px; color: #06251b; }
        .anc-tile-input { display: none; }
        .anc-tile-input:checked + .anc-tile-label {
            border-color: #06251b;
            background: #06251b;
            color: #ffc107;
        }
        .anc-tile-input:checked + .anc-tile-label i {
            color: #ffc107;
        }

        /* User search list inside modal */
        .anc-user-picker-box {
            border: 1px solid #d4e0d8;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .anc-picker-search {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #f7faf8;
            border-bottom: 1px solid #edf1ee;
        }
        .anc-picker-search input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 12px;
            width: 100%;
            font-family: inherit;
        }
        .anc-user-list-scroll {
            max-height: 130px;
            overflow-y: auto;
            padding: 4px 0;
        }
        .anc-user-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            cursor: pointer;
            transition: background .15s;
            border-bottom: 1px solid #f7f9f8;
        }
        .anc-user-option:last-child { border-bottom: none; }
        .anc-user-option:hover, .anc-user-option.selected {
            background: #eef5f0;
        }
        .anc-user-option-name {
            font-size: 12px;
            font-weight: 700;
            color: #182019;
        }
        .anc-user-option-sub {
            font-size: 10.5px;
            color: #88968d;
        }
        .anc-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
        }
        .anc-badge.bidder { background: #FCF1CF; color: #C99A1D; }
        .anc-badge.admin  { background: #E4F5EA; color: #219653; }
        .anc-badge.user   { background: #F3E5F5; color: #7B1FA2; }
        .anc-badge.superadmin { background: #E0F2F1; color: #004D40; }
    </style>
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
        <div style="background:#e8f5e9; color:#1f7a3d; border:1px solid #c8e6c9; border-radius:12px; padding:12px 18px; margin-bottom:18px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['alert_success']) ?></span>
        </div>
        <?php unset($_SESSION['alert_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['alert_error'])): ?>
        <div style="background:#ffebee; color:#c62828; border:1px solid #ffcdd2; border-radius:12px; padding:12px 18px; margin-bottom:18px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['alert_error']) ?></span>
        </div>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>

    <!-- ════ STATISTICS PANEL (matching superadmin/procurement.php) ════ -->
    <div class="sp-panel" style="margin-bottom:20px;">
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
                <div class="sp-donut" style="background:conic-gradient(<?= $grad ?>);"></div>
                <div class="sp-donut-hole">
                    <div class="sp-donut-num"><?= $stat_total ?></div>
                    <div class="sp-donut-lbl">Total<br>Announcements</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="sp-legend">
                <?php
                $legend = [
                    'all'     => ['label' => 'Broadcast (All)', 'color' => '#1565c0', 'soft' => '#E7EEFE'],
                    'bidders' => ['label' => 'Bidders',         'color' => '#C99A1D', 'soft' => '#FCF1CF'],
                    'admins'  => ['label' => 'Administrators',  'color' => '#219653', 'soft' => '#E4F5EA'],
                    'users'   => ['label' => 'Normal Users',    'color' => '#7b1fa2', 'soft' => '#F3E5F5'],
                    'direct'  => ['label' => 'Direct to User',  'color' => '#512da8', 'soft' => '#EDE7F6'],
                ];
                foreach ($legend as $key => $l):
                    $cnt = $counts[$key];
                    $p   = pct2($cnt, $stat_total);
                ?>
                <div class="sp-legend-row">
                    <span class="sp-legend-dot" style="background:<?= $l['color'] ?>"></span>
                    <span class="sp-legend-label"><?= $l['label'] ?></span>
                    <span class="sp-legend-value"><?= $cnt ?></span>
                    <span class="sp-legend-pct" style="color:<?= $l['color'] ?>; background:<?= $l['soft'] ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
                <div class="sp-legend-row sp-legend-total">
                    <span class="sp-legend-dot" style="background:#F0B92E"></span>
                    <span class="sp-legend-label">All Announcements</span>
                    <span class="sp-legend-value" style="color:#F0B92E"><?= $stat_total ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ ANNOUNCEMENTS LIST (matching procurement.php) ════ -->
    <!-- New announcement button -->
    <button type="button" class="sp-new-btn" onclick="openCreateModal()" style="cursor:pointer;">
        <i class="bi bi-plus-circle"></i> Broadcast New Announcement
    </button>

    <!-- List panel -->
    <div class="sp-panel sp-list-panel">
        <!-- Toolbar: tabs + search (matching procurement.php layout) -->
        <form method="GET" action="" class="ap2-controls" style="margin-bottom:12px;">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by title, message, or author..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="ap2-filters">
                <?php
                $tabs = [
                    'all'       => 'All',
                    'broadcast' => 'Broadcast (All)',
                    'bidders'   => 'Bidders',
                    'admins'    => 'Admins',
                    'users'     => 'Users',
                    'direct'    => 'Direct'
                ];
                foreach ($tabs as $val => $label):
                ?>
                    <button type="submit" name="type" value="<?= $val ?>"
                            class="ap2-filter-btn <?= $filter_type === $val ? 'active' : '' ?>">
                        <?= $label ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="ap2-go-btn">
                <i class="bi bi-search"></i> Search
            </button>
        </form>

        <?php if ($announcements_res->num_rows > 0):
            while ($row = $announcements_res->fetch_assoc()):
                $tt = $row['target_type'];
                $lc = '#1565c0';
                $pillBg = '#E7EEFE';
                $pillFg = '#1565c0';
                $badgeTxt = 'BROADCAST (ALL)';
                $badgeIcn = 'globe';

                if ($tt === 'role') {
                    $r = $row['target_role'];
                    if ($r === 'bidder') {
                        $lc = '#C99A1D'; $pillBg = '#FCF1CF'; $pillFg = '#C99A1D'; $badgeTxt = 'ROLE: BIDDERS'; $badgeIcn = 'person-badge';
                    } elseif ($r === 'admin') {
                        $lc = '#219653'; $pillBg = '#E4F5EA'; $pillFg = '#219653'; $badgeTxt = 'ROLE: ADMINS'; $badgeIcn = 'shield-check';
                    } elseif ($r === 'user') {
                        $lc = '#7b1fa2'; $pillBg = '#F3E5F5'; $pillFg = '#7b1fa2'; $badgeTxt = 'ROLE: USERS'; $badgeIcn = 'people';
                    } elseif ($r === 'superadmin') {
                        $lc = '#06251b'; $pillBg = '#E0F2F1'; $pillFg = '#004D40'; $badgeTxt = 'ROLE: SUPERADMINS'; $badgeIcn = 'person-gear';
                    }
                } elseif ($tt === 'user') {
                    $lc = '#512da8'; $pillBg = '#EDE7F6'; $pillFg = '#512da8';
                    $targetName = trim(($row['target_fname'] ?? '') . ' ' . ($row['target_lname'] ?? '')) ?: $row['target_username'];
                    $badgeTxt = 'DIRECT: ' . $targetName;
                    $badgeIcn = 'person';
                }

                $creatorName = trim(($row['creator_fname'] ?? '') . ' ' . ($row['creator_lname'] ?? '')) ?: ($row['creator_username'] ?? 'Superadmin');
        ?>
        <div class="sp-proc-row" style="border-left-color:<?= $lc ?>">
            <div class="sp-proc-body">
                <div class="sp-proc-title"><?= htmlspecialchars($row['title']) ?></div>
                <div class="sp-proc-meta">
                    <span><i class="bi bi-person"></i> By: <?= htmlspecialchars($creatorName) ?></span>
                    <span><i class="bi bi-calendar3"></i> <?= date('M j, Y', strtotime($row['created_at'])) ?> (<?= timeAgo($row['created_at']) ?>)</span>
                    <span><i class="bi bi-eye"></i> <?= (int)$row['read_count'] ?> views</span>
                </div>
                <div style="margin-top:6px; font-size:12.5px; color:#5c6b61; line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                    <?= htmlspecialchars($row['message']) ?>
                </div>
            </div>
            <div class="sp-proc-actions">
                <span class="sp-status-pill" style="background:<?= $pillBg ?>; color:<?= $pillFg ?>">
                    <i class="bi bi-<?= $badgeIcn ?>"></i> <?= htmlspecialchars($badgeTxt) ?>
                </span> | 
                <button type="button" class="sp-act-btn" style="background:#f0f4f2; color:#06251b; border:none; cursor:pointer;" onclick="viewAnnouncement(<?= htmlspecialchars(json_encode($row)) ?>)">
                    <i class="bi bi-eye"></i> View
                </button>
                <button type="button" class="sp-act-btn" style="background:#FBE7E8; color:#DB4C4C; border:none; cursor:pointer;" onclick="confirmDelete(<?= $row['notification_id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>')">
                    <i class="bi bi-trash3"></i> Delete
                </button>
            </div>
        </div>
        <?php endwhile;
        else: ?>
            <div class="empty-state" style="padding:48px;">
                <i class="bi bi-megaphone"></i>
                <p>No announcements found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
            </div>
        <?php endif; ?>

        <div class="sp-list-foot">
            Showing <?= $announcements_res->num_rows ?> announcement(s)
        </div>
    </div>

</div>
</main>

<!-- ════ CREATE ANNOUNCEMENT MODAL ════ -->
<div id="createAnnouncementModal" class="modal-backdrop" style="align-items:flex-start; overflow-y:auto; padding:24px 10px;" onclick="if(event.target===this)closeCreateModal()">
    <div class="modal-box" style="max-width:580px; width:95%; max-height:calc(100vh - 48px); overflow-y:auto; border-radius:18px; padding:24px; margin:auto;">
        <div class="modal-header" style="margin-bottom:16px; padding-bottom:12px;">
            <h4 style="font-size:16px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-megaphone-fill" style="color:#ffc107;"></i>
                <span>Broadcast New Announcement</span>
            </h4>
            <button type="button" class="modal-close" onclick="closeCreateModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <form method="POST" action="announcements.php" onsubmit="return validateAnnouncementForm()">
            <input type="hidden" name="create_announcement" value="1">
            <input type="hidden" id="selectedUserId" name="target_user_id" value="">

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="display:block; font-size:12px; font-weight:700; color:#06251b; margin-bottom:5px;">
                    Announcement Title <span style="color:#c23b3b;">*</span>
                </label>
                <input type="text" name="title" required placeholder="e.g., Scheduled System Maintenance or Bidding Updates" class="form-input" style="width:100%; padding:9px 12px; border:1px solid #d4e0d8; border-radius:10px; font-size:13px;">
            </div>

            <!-- Target audience selection with tiles & icons -->
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="display:block; font-size:12px; font-weight:700; color:#06251b; margin-bottom:5px;">
                    Target Audience <span style="color:#c23b3b;">*</span>
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
            <div class="form-group" id="roleSelectBlock" style="display:none; margin-bottom:12px;">
                <label class="form-label" style="display:block; font-size:12px; font-weight:700; color:#06251b; margin-bottom:5px;">
                    Select Role <span style="color:#c23b3b;">*</span>
                </label>
                <select name="target_role" id="targetRoleSelect" class="form-select" style="width:100%; padding:9px 12px; border:1px solid #d4e0d8; border-radius:10px; font-size:13px; background:#fff;">
                    <option value="bidder">Bidders Only</option>
                    <option value="admin">Administrators Only</option>
                    <option value="user">Normal Users Only</option>
                    <option value="superadmin">Super Administrators</option>
                </select>
            </div>

            <!-- Direct User Search & Selection Block -->
            <div class="form-group" id="userPickerBlock" style="display:none; margin-bottom:12px;">
                <label class="form-label" style="display:block; font-size:12px; font-weight:700; color:#06251b; margin-bottom:5px;">
                    Select Specific User <span style="color:#c23b3b;">*</span>
                </label>
                <div class="anc-user-picker-box">
                    <div class="anc-picker-search">
                        <i class="bi bi-search" style="color:#88968d;"></i>
                        <input type="text" id="userSearchField" placeholder="Search by name, username, or role..." oninput="filterUserPicker(this.value)">
                    </div>
                    <div class="anc-user-list-scroll" id="userPickerList" style="max-height:130px; overflow-y:auto;">
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
                <div id="selectedUserBanner" style="display:none; margin-top:6px; font-size:12px; font-weight:700; color:#06251b; background:#e8f5e9; padding:6px 12px; border-radius:8px; border:1px solid #c8e6c9;">
                    <i class="bi bi-check-circle-fill" style="color:#1f7a3d;"></i> Selected: <span id="selectedUserName"></span>
                </div>
            </div>

            <!-- Announcement message -->
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="display:block; font-size:12px; font-weight:700; color:#06251b; margin-bottom:5px;">
                    Announcement Message <span style="color:#c23b3b;">*</span>
                </label>
                <textarea name="message" rows="3" required placeholder="Write the complete announcement text..." class="form-input" style="width:100%; padding:9px 12px; border:1px solid #d4e0d8; border-radius:10px; font-size:13px; resize:vertical; font-family:inherit;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:4px; border-top:1px solid #eaeeec; margin-top:4px;">
                <button type="button" onclick="closeCreateModal()" style="padding:10px 20px; background:#fff; border:1.5px solid #d4e0d8; border-radius:9px; font-size:13px; font-weight:600; color:#4a5e54; cursor:pointer; font-family:'Poppins',sans-serif;">Cancel</button>
                <button type="submit" style="padding:10px 24px; background:#06251b; color:#ffc107; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:7px;">
                    <i class="bi bi-send-fill"></i> Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════ VIEW ANNOUNCEMENT DETAIL MODAL ════ -->
<div id="viewAnnouncementModal" class="modal-backdrop" style="align-items:flex-start; overflow-y:auto; padding:24px 10px;" onclick="if(event.target===this)closeViewModal()">
    <div class="modal-box" style="max-width:540px; width:95%; max-height:calc(100vh - 48px); overflow-y:auto; border-radius:18px; padding:24px; margin:auto;">
        <div class="modal-header" style="margin-bottom:16px; padding-bottom:12px;">
            <h4 style="font-size:16px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-megaphone-fill" style="color:#e67e22;"></i>
                <span id="vaTitle">Announcement Details</span>
            </h4>
            <button type="button" class="modal-close" onclick="closeViewModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <div style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span id="vaBadge" class="sp-status-pill"><i class="bi bi-globe"></i> Broadcast</span>
            <span id="vaDate" style="font-size:12px; color:#88968d; font-weight:600;"></span>
            <span id="vaAuthor" style="font-size:12px; color:#88968d; margin-left:auto;"></span>
        </div>

        <div id="vaMessage" style="background:#f7faf8; border:1px solid #eaeeec; border-radius:12px; padding:16px; font-size:13px; color:#2d3a32; line-height:1.6; white-space:pre-wrap; max-height:260px; overflow-y:auto;">
        </div>

        <div style="margin-top:14px; font-size:11.5px; color:#88968d; display:flex; align-items:center; gap:6px;">
            <i class="bi bi-eye"></i> <span id="vaReads">0</span> users have viewed this notification
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:18px; padding-top:14px; border-top:1px solid #eaeeec;">
            <button type="button" onclick="closeViewModal()" style="padding:10px 24px; background:#06251b; color:#ffc107; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:7px;">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ════ CONFIRM DELETE MODAL ════ -->
<div id="deleteConfirmModal" class="modal-backdrop" style="align-items:center; overflow-y:auto; padding:20px 10px;" onclick="if(event.target===this)closeDeleteModal()">
    <div class="modal-box" style="max-width:440px; width:95%; max-height:calc(100vh - 40px); overflow-y:auto; border-radius:18px; padding:24px; text-align:center; margin:auto;">
        <div style="width:50px; height:50px; border-radius:50%; background:#ffebee; color:#c62828; font-size:24px; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <i class="bi bi-trash-fill"></i>
        </div>
        <h4 style="font-size:16px; font-weight:800; color:#06251b; margin-bottom:8px;">Delete Announcement?</h4>
        <p style="font-size:12.5px; color:#6c776e; margin-bottom:14px;">Are you sure you want to permanently delete this announcement? Recipients will no longer see it.</p>
        
        <div id="delTitlePreview" style="background:#f7faf8; border:1px solid #eaeeec; border-radius:10px; padding:10px 14px; font-size:13px; font-weight:700; color:#06251b; margin-bottom:20px;">
        </div>

        <form method="POST" action="announcements.php">
            <input type="hidden" name="delete_announcement" value="1">
            <input type="hidden" id="delAnnouncementId" name="announcement_id" value="">
            <div style="display:flex; justify-content:center; gap:12px; padding-top:4px;">
                <button type="button" onclick="closeDeleteModal()" style="padding:10px 20px; background:#fff; border:1.5px solid #d4e0d8; border-radius:9px; font-size:13px; font-weight:600; color:#4a5e54; cursor:pointer; font-family:'Poppins',sans-serif;">Cancel</button>
                <button type="submit" style="padding:10px 24px; background:#c62828; color:#fff; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:7px;">
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
        document.getElementById('roleSelectBlock').style.display = type === 'role' ? 'block' : 'none';
        document.getElementById('userPickerBlock').style.display = type === 'user' ? 'block' : 'none';
    }

    function filterUserPicker(query) {
        const q = query.toLowerCase().trim();
        const options = document.querySelectorAll('#userPickerList .anc-user-option');
        options.forEach(opt => {
            const str = opt.getAttribute('data-search') || '';
            opt.style.display = str.includes(q) ? 'flex' : 'none';
        });
    }

    function selectUserForAnnouncement(userId, userName, el) {
        document.getElementById('selectedUserId').value = userId;
        document.getElementById('selectedUserName').textContent = userName;
        document.getElementById('selectedUserBanner').style.display = 'block';
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
        let pillBg = '#E7EEFE';
        let pillFg = '#1565c0';

        if (data.target_type === 'role') {
            const r = data.target_role;
            if (r === 'bidder') {
                pillBg = '#FCF1CF'; pillFg = '#C99A1D'; badgeHtml = '<i class="bi bi-person-badge"></i> ROLE: BIDDERS';
            } else if (r === 'admin') {
                pillBg = '#E4F5EA'; pillFg = '#219653'; badgeHtml = '<i class="bi bi-shield-check"></i> ROLE: ADMINS';
            } else if (r === 'user') {
                pillBg = '#F3E5F5'; pillFg = '#7b1fa2'; badgeHtml = '<i class="bi bi-people"></i> ROLE: USERS';
            } else if (r === 'superadmin') {
                pillBg = '#E0F2F1'; pillFg = '#004D40'; badgeHtml = '<i class="bi bi-person-gear"></i> ROLE: SUPERADMINS';
            }
        } else if (data.target_type === 'user') {
            pillBg = '#EDE7F6'; pillFg = '#512da8';
            badgeHtml = `<i class="bi bi-person"></i> Direct to User`;
        }

        const badgeEl = document.getElementById('vaBadge');
        badgeEl.innerHTML = badgeHtml;
        badgeEl.style.background = pillBg;
        badgeEl.style.color = pillFg;

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
