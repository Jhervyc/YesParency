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
    <style>
        .btn-mark-read-all {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eef2f0;
            color: #06251b;
            font-size: 12px;
            font-weight: 700;
            padding: 8px 14px;
            border-radius: 9px;
            border: none;
            cursor: pointer;
            transition: all .15s ease;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .btn-mark-read-all:hover {
            background: #06251b;
            color: #ffc107;
        }

        .vp-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #06251b;
            font-size: 12.5px;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #eaeeec;
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
        }
    </style>
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
    <div class="ap2-stats ap2-stats-4" style="margin-bottom:20px;">
        
        <!-- Total Notifications -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-bell-fill" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Total Notifications</div>
            </div>
        </div>

        <!-- Unread -->
        <div class="ap2-stat <?= $stat_unread > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="background:conic-gradient(<?= $stat_unread > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= $stat_total > 0 ? round($stat_unread / $stat_total * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-bell-slash-fill" style="color:<?= $stat_unread > 0 ? '#e67e22' : '#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $stat_unread > 0 ? '#e67e22' : 'inherit' ?>;"><?= number_format($stat_unread) ?></div>
                <div class="ap2-stat-lbl">Unread</div>
            </div>
        </div>

        <!-- Broadcast / Public -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% <?= $stat_total > 0 ? round($stat_broadcast / max($stat_total,1) * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-globe" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#219653;"><?= number_format($stat_broadcast) ?></div>
                <div class="ap2-stat-lbl">Broadcasts</div>
            </div>
        </div>

        <!-- Direct / Role Targeted -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#2F6FED 0% <?= $stat_total > 0 ? round($stat_direct / max($stat_total,1) * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-person-badge" style="color:#2F6FED;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#2F6FED;"><?= number_format($stat_direct) ?></div>
                <div class="ap2-stat-lbl">Role / Direct</div>
            </div>
        </div>

    </div>

    <!-- ── Notification List Panel (matching superadmin announcements layout) ── -->
    <div class="proc-table-panel" style="margin-bottom:24px;">

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="GET" action="notification.php" id="notifForm" style="display:contents;">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by title, message, or author..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
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
        <div style="padding:52px 20px; text-align:center; color:#88968d;">
            <i class="bi bi-megaphone" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            <div style="font-size:13px; font-weight:700;">No notifications found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?></div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th>Title / Message</th>
                        <th class="col-mode">Target</th>
                        <th class="col-opening">From</th>
                        <th class="col-abc">Date</th>
                        <th style="text-align:right; min-width:170px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($nt = $notifs_result->fetch_assoc()):
                    $notifs_data[$nt['notification_id']] = $nt;
                    $isRead = (bool)$nt['is_read'];
                    $tt = $nt['target_type'];
                    $pillBg = '#E7EEFE'; $pillFg = '#1565c0'; $badgeTxt = 'Broadcast'; $badgeIcn = 'globe';
                    if ($tt === 'role') {
                        $r = $nt['target_role'];
                        if ($r === 'bidder')     { $pillBg='#FCF1CF'; $pillFg='#C99A1D'; $badgeTxt='Bidders';  $badgeIcn='person-badge'; }
                        elseif ($r === 'admin')  { $pillBg='#E4F5EA'; $pillFg='#219653'; $badgeTxt='Admins';   $badgeIcn='shield-check'; }
                        else                     { $pillBg='#F3E5F5'; $pillFg='#7b1fa2'; $badgeTxt=strtoupper($r); $badgeIcn='people'; }
                    } elseif ($tt === 'user') { $pillBg='#EDE7F6'; $pillFg='#512da8'; $badgeTxt='Direct'; $badgeIcn='person'; }
                    $creatorName = trim(($nt['creator_fname']??'').' '.($nt['creator_lname']??'')) ?: 'BAC Secretariat';
                ?>
                <tr id="notif-row-<?= $nt['notification_id'] ?>" <?= !$isRead ? 'style="background:#fdfaf3;"' : '' ?>>
                    <td class="proc-title-cell">
                        <div style="display:flex; align-items:center; gap:7px; font-weight:700; color:#06251b; margin-bottom:3px;">
                            <?= htmlspecialchars($nt['title']) ?>
                            <?php if (!$isRead): ?>
                                <span style="background:#fff3e0; color:#e67e22; padding:1px 7px; border-radius:10px; font-weight:800; font-size:9.5px; flex-shrink:0;">UNREAD</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px; color:#63736a; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden;">
                            <?= htmlspecialchars($nt['message']) ?>
                        </div>
                    </td>
                    <td class="col-mode">
                        <span class="proc-status-pill" style="background:<?= $pillBg ?>; color:<?= $pillFg ?>; font-size:10px; padding:3px 8px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; white-space:nowrap;">
                            <i class="bi bi-<?= $badgeIcn ?>" style="font-size:9px;"></i> <?= htmlspecialchars($badgeTxt) ?>
                        </span>
                    </td>
                    <td class="proc-deadline-cell col-opening"><?= htmlspecialchars($creatorName) ?></td>
                    <td class="proc-deadline-cell col-abc" style="white-space:nowrap;">
                        <?= date('M j, Y', strtotime($nt['created_at'])) ?><br>
                        <span style="color:#88968d; font-size:10.5px;"><?= timeAgo($nt['created_at']) ?></span>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap;">
                            <?php if (!$isRead): ?>
                            <button type="button" class="proc-action-btn mark-read-btn"
                                    style="background:#e4f5ea; color:#1f7a3d; border:none; cursor:pointer;"
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
                row.style.background = '#fff';
                const unreadBadge = row.querySelector('.sp-proc-title span[style*="background:#fff3e0"]');
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
                row.style.background = '#fff';
                const unreadBadge = row.querySelector('.sp-proc-title span[style*="background:#fff3e0"]');
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
