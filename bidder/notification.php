<?php
include("utils/protect-page.php");

$bidder_id   = (int)$_SESSION['user_id'];
$bidder_role = $_SESSION['role'] ?? 'bidder';

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

// ── Filters & Pagination ───────────────────────────────────────────────────────
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 10;
$offset      = ($page - 1) * $per_page;

$valid_types = ['all', 'unread', 'broadcast', 'direct'];
if (!in_array($filter_type, $valid_types)) $filter_type = 'all';

// ── Stats Calculations ────────────────────────────────────────────────────────
// 1. Total Notifications for Bidder
$stat_total_stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM system_notifications sn 
    WHERE (sn.target_type = 'all'
       OR (sn.target_type = 'role' AND sn.target_role = ?)
       OR (sn.target_type = 'user' AND sn.target_user_id = ?))
      AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
");
$stat_total_stmt->bind_param("sii", $bidder_role, $bidder_id, $bidder_id);
$stat_total_stmt->execute();
$stat_total = (int)$stat_total_stmt->get_result()->fetch_row()[0];
$stat_total_stmt->close();

// 2. Unread Notifications
$stat_unread_stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM system_notifications sn
    LEFT JOIN user_notification_reads unr 
        ON sn.notification_id = unr.notification_id 
        AND unr.user_id = ?
    WHERE (sn.target_type = 'all'
       OR (sn.target_type = 'role' AND sn.target_role = ?)
       OR (sn.target_type = 'user' AND sn.target_user_id = ?))
      AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
      AND unr.read_at IS NULL
");
$stat_unread_stmt->bind_param("isii", $bidder_id, $bidder_role, $bidder_id, $bidder_id);
$stat_unread_stmt->execute();
$stat_unread = (int)$stat_unread_stmt->get_result()->fetch_row()[0];
$stat_unread_stmt->close();

// 3. Broadcast Notifications
$stat_broadcast_res = $conn->prepare("
    SELECT COUNT(*) FROM system_notifications
    WHERE target_type = 'all'
      AND created_at >= (SELECT created_at FROM users WHERE user_id = ?)
");
$stat_broadcast_res->bind_param("i", $bidder_id);
$stat_broadcast_res->execute();
$stat_broadcast = (int)$stat_broadcast_res->get_result()->fetch_row()[0];
$stat_broadcast_res->close();

// 4. Direct / Role Targeted
$stat_direct_stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM system_notifications sn
    WHERE ((sn.target_type = 'role' AND sn.target_role = ?)
       OR (sn.target_type = 'user' AND sn.target_user_id = ?))
      AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
");
$stat_direct_stmt->bind_param("sii", $bidder_role, $bidder_id, $bidder_id);
$stat_direct_stmt->execute();
$stat_direct = (int)$stat_direct_stmt->get_result()->fetch_row()[0];
$stat_direct_stmt->close();

// ── Query Construction ────────────────────────────────────────────────────────
$where_parts = ["(sn.target_type = 'all' OR (sn.target_type = 'role' AND sn.target_role = ?) OR (sn.target_type = 'user' AND sn.target_user_id = ?))"];
$params      = [$bidder_role, $bidder_id];
$types       = 'si';

// Only show notifications created after this user's account was registered
$where_parts[] = "sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)";
$params[]      = $bidder_id;
$types        .= 'i';

if ($filter_type === 'unread') {
    $where_parts[] = "unr.read_at IS NULL";
} elseif ($filter_type === 'broadcast') {
    $where_parts[] = "sn.target_type = 'all'";
} elseif ($filter_type === 'direct') {
    $where_parts[] = "(sn.target_type IN ('role', 'user'))";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(sn.title LIKE ? OR sn.message LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// Count matching notifications
$count_sql = "
    SELECT COUNT(*) 
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    LEFT JOIN user_notification_reads unr ON sn.notification_id = unr.notification_id AND unr.user_id = ?
    $where_sql
";
$count_params = array_merge([$bidder_id], $params);
$count_types  = 'i' . $types;
$count_stmt   = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_shown = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = max(1, ceil($total_shown / $per_page));

// Paged Notifications Query
$main_sql = "
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
        CASE WHEN unr.read_at IS NOT NULL THEN 1 ELSE 0 END AS is_read
    FROM system_notifications sn
    LEFT JOIN users u ON sn.created_by = u.user_id
    LEFT JOIN user_notification_reads unr ON sn.notification_id = unr.notification_id AND unr.user_id = ?
    $where_sql
    ORDER BY sn.created_at DESC
    LIMIT ? OFFSET ?
";
$main_params = array_merge([$bidder_id], $params, [$per_page, $offset]);
$main_types  = 'i' . $types . 'ii';
$main_stmt   = $conn->prepare($main_sql);
$main_stmt->bind_param($main_types, ...$main_params);
$main_stmt->execute();
$notifs_result = $main_stmt->get_result();

$notifs_data = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications &amp; Announcements | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-notification.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Notifications & Announcements';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Notifications &amp; Announcements</h2>
        <p>Stay informed with official procurement notices, BAC broadcasts, and direct updates for bidders.</p>
    </div>

    <!-- ── Stat Cards (matching /superadmin/announcements.php & /admin/audit_trail.php) ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4 mb-20">

        <!-- Total Notifications -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%">
                <div class="ap2-ring-inner"><i class="bi bi-bell-fill"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Total Notifications</div>
            </div>
        </div>

        <!-- Unread -->
        <?php $unreadColor = $stat_unread > 0 ? '#e67e22' : '#8B958E'; ?>
        <div class="ap2-stat <?= $stat_unread > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="--ring-color:<?= $unreadColor ?>; --pct:<?= $stat_total > 0 ? round($stat_unread / $stat_total * 100) : 0 ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-bell-slash-fill"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num <?= $stat_unread > 0 ? 'ap2-stat-num--warn' : '' ?>"><?= number_format($stat_unread) ?></div>
                <div class="ap2-stat-lbl">Unread</div>
            </div>
        </div>

        <!-- Broadcast / Public -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#219653; --pct:<?= $stat_total > 0 ? round($stat_broadcast / max($stat_total,1) * 100) : 0 ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-globe"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--broadcast"><?= number_format($stat_broadcast) ?></div>
                <div class="ap2-stat-lbl">Broadcasts</div>
            </div>
        </div>

        <!-- Direct / Role Targeted -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#2F6FED; --pct:<?= $stat_total > 0 ? round($stat_direct / max($stat_total,1) * 100) : 0 ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-person-badge"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--direct"><?= number_format($stat_direct) ?></div>
                <div class="ap2-stat-lbl">Role / Direct</div>
            </div>
        </div>

    </div>

    <!-- ── Notification List Panel (matching superadmin announcements layout) ── -->
    <div class="proc-table-panel mb-24">

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="GET" action="notification.php" id="notifForm" class="form-contents">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by title, message, or author..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-status-group">
                    <?php foreach (['all'=>'All','unread'=>'Unread','broadcast'=>'Broadcast','direct'=>'Direct / Role'] as $val => $lbl): ?>
                        <button type="submit" name="type" value="<?= $val ?>"
                                class="ap2-filter-btn <?= $filter_type === $val ? 'active' : '' ?>">
                            <?= $lbl ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
                <?php if ($stat_unread > 0): ?>
                    <button type="button" class="btn-mark-read-all" onclick="pageMarkAllRead()">
                        <i class="bi bi-check2-all"></i> Mark All as Read
                    </button>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($total_shown === 0): ?>
        <div class="notif-empty-state">
            <i class="bi bi-megaphone notif-empty-icon"></i>
            <div class="notif-empty-title">No notifications found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?></div>
        </div>
        <?php else: ?>
        <div class="table-scroll-x">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th>Title / Message</th>
                        <th class="col-mode">Target</th>
                        <th class="col-opening">From</th>
                        <th class="col-abc">Date</th>
                        <th class="col-actions-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($nt = $notifs_result->fetch_assoc()):
                    $notifs_data[$nt['notification_id']] = $nt;
                    $isRead = (bool)$nt['is_read'];
                    $tt = $nt['target_type'];
                    $pillClass = 'notif-pill--broadcast'; $badgeTxt = 'Broadcast'; $badgeIcn = 'globe';
                    if ($tt === 'role') {
                        $r = $nt['target_role'];
                        if ($r === 'bidder')     { $pillClass='notif-pill--bidder';    $badgeTxt='Bidders';  $badgeIcn='person-badge'; }
                        elseif ($r === 'admin')  { $pillClass='notif-pill--admin';     $badgeTxt='Admins';   $badgeIcn='shield-check'; }
                        else                     { $pillClass='notif-pill--role-other'; $badgeTxt=strtoupper($r); $badgeIcn='people'; }
                    } elseif ($tt === 'user') { $pillClass='notif-pill--direct'; $badgeTxt='Direct'; $badgeIcn='person'; }
                    $creatorName = trim(($nt['creator_fname']??'').' '.($nt['creator_lname']??'')) ?: 'BAC Secretariat';
                ?>
                <tr id="notif-row-<?= $nt['notification_id'] ?>" class="<?= !$isRead ? 'notif-row--unread' : '' ?>">
                    <td class="proc-title-cell">
                        <div class="notif-title-row">
                            <?= htmlspecialchars($nt['title']) ?>
                            <?php if (!$isRead): ?>
                                <span class="notif-unread-badge">UNREAD</span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-message-clamp">
                            <?= htmlspecialchars($nt['message']) ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-status-pill notif-pill <?= $pillClass ?>">
                            <i class="bi bi-<?= $badgeIcn ?> notif-pill-icon"></i> <?= htmlspecialchars($badgeTxt) ?>
                        </span>
                    </td>
                    <td class="proc-deadline-cell col-opening"><?= htmlspecialchars($creatorName) ?></td>
                    <td class="proc-deadline-cell col-abc notif-date-cell">
                        <?= date('M j, Y', strtotime($nt['created_at'])) ?><br>
                        <span class="notif-time-ago"><?= timeAgo($nt['created_at']) ?></span>
                    </td>
                    <td class="notif-actions-cell">
                        <div class="notif-actions-wrap">
                            <?php if (!$isRead): ?>
                            <button type="button" class="proc-action-btn mark-read-btn proc-action-btn--mark-read"
                                    onclick="pageMarkSingleRead(<?= $nt['notification_id'] ?>, this)">
                                <i class="bi bi-check2"></i> Read
                            </button>
                            <?php endif; ?>
                            <button type="button" class="proc-action-btn btn-view"
                                    onclick="viewAnnouncement(<?= $nt['notification_id'] ?>)">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <div>Showing <strong><?= min($total_shown,$offset+1) ?></strong>–<strong><?= min($total_shown,$offset+$per_page) ?></strong> of <strong><?= number_format($total_shown) ?></strong> notices</div>
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <a href="?type=<?= urlencode($filter_type) ?>&search=<?= urlencode($search) ?>&page=<?= max(1,$page-1) ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="?type=<?= urlencode($filter_type) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?type=<?= urlencode($filter_type) ?>&search=<?= urlencode($search) ?>&page=<?= min($total_pages,$page+1) ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</div>
</main>

<script>
const pageNotifsData = <?= json_encode($notifs_data) ?>;

async function viewAnnouncement(id) {
    const nt = pageNotifsData[id];
    if (!nt) return;

    if (typeof window.openNotifDetail === 'function') {
        const notifObj = {
            id: nt.notification_id,
            title: nt.title,
            message: nt.message,
            target_type: nt.target_type,
            target_role: nt.target_role,
            formatted_date: new Date(nt.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric', hour12: true }),
            creator_name: (nt.creator_fname || '') + ' ' + (nt.creator_lname || '') || 'BAC Secretariat',
            is_read: parseInt(nt.is_read) === 1
        };

        // Open modal
        document.getElementById('ndModalTitle').textContent   = notifObj.title;
        document.getElementById('ndModalMessage').textContent = notifObj.message;
        document.getElementById('ndModalDate').textContent    = notifObj.formatted_date;
        document.getElementById('ndModalAuthor').textContent  = `From: ${notifObj.creator_name}`;

        const badgeEl = document.getElementById('ndModalBadge');
        if (badgeEl) {
            badgeEl.className = `notif-target-badge ${notifObj.target_type}`;
            if (notifObj.target_type === 'all') badgeEl.innerHTML = '<i class="bi bi-globe"></i> Broadcast (All)';
            else if (notifObj.target_type === 'role') badgeEl.innerHTML = `<i class="bi bi-people"></i> Role: ${notifObj.target_role}`;
            else badgeEl.innerHTML = '<i class="bi bi-person"></i> Direct to you';
        }

        const modal = document.getElementById('notifDetailModal');
        if (modal) modal.classList.add('open');

        // Mark as read in background if unread
        if (!notifObj.is_read) {
            nt.is_read = 1;
            const row = document.getElementById(`notif-row-${id}`);
            if (row) {
                row.classList.remove('notif-row--unread');
                const unreadBadge = row.querySelector('.notif-unread-badge');
                if (unreadBadge) unreadBadge.remove();
                const readBtn = row.querySelector('.mark-read-btn');
                if (readBtn) readBtn.remove();
            }

            try {
                const formData = new FormData();
                formData.append('action', 'mark_read');
                formData.append('notification_id', id);
                await fetch('notifications_api.php', { method: 'POST', body: formData });
                if (typeof window.fetchNotifications === 'function') window.fetchNotifications();
            } catch (e) {
                console.error(e);
            }
        }
    }
}

async function pageMarkSingleRead(id, btn) {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', id);
        const res = await fetch('notifications_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            const row = document.getElementById(`notif-row-${id}`);
            if (row) {
                row.classList.remove('notif-row--unread');
                const unreadBadge = row.querySelector('.notif-unread-badge');
                if (unreadBadge) unreadBadge.remove();
            }
            if (btn) btn.remove();
            if (typeof window.updateNotifBadge === 'function') {
                window.updateNotifBadge(data.unread_count);
            }
            if (typeof window.fetchNotifications === 'function') {
                window.fetchNotifications();
            }
        }
    } catch (e) {
        console.error(e);
    }
}

async function pageMarkAllRead() {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        const res = await fetch('notifications_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            window.location.reload();
        }
    } catch (e) {
        console.error(e);
    }
}
</script>

</body>
</html>
