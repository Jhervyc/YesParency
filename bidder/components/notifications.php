<?php
/**
 * Global Topbar Notification Component for Bidder
 * Usage: <?php include("components/notifications.php"); ?> inside <div class="topbar-right">
 */

$comp_unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $comp_user_id = (int)$_SESSION['user_id'];
    $comp_role    = $_SESSION['role'] ?? 'bidder';

    $notif_unread_stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM system_notifications sn
        LEFT JOIN user_notification_reads unr 
            ON sn.notification_id = unr.notification_id 
            AND unr.user_id = ?
        WHERE (sn.target_type = 'all'
           OR (sn.target_type = 'role' AND sn.target_role = ?)
           OR (sn.target_type = 'user' AND sn.target_user_id = ?))
          AND unr.read_at IS NULL
    ");
    if ($notif_unread_stmt) {
        $notif_unread_stmt->bind_param("isi", $comp_user_id, $comp_role, $comp_user_id);
        $notif_unread_stmt->execute();
        $comp_unread_count = (int)$notif_unread_stmt->get_result()->fetch_row()[0];
        $notif_unread_stmt->close();
    }
}
?>

<!-- ════ TOPBAR NOTIFICATION BELL & DROPDOWN COMPONENT ════ -->
<div class="topbar-notif-wrapper" id="notifWrapper">
    <button type="button" class="topbar-badge notif-toggle-btn" id="notifToggleBtn" onclick="toggleNotifDropdown(event)" aria-label="Notifications" title="Notifications">
        <i class="bi bi-bell"></i>
        <span class="notif-badge-dot <?= $comp_unread_count > 0 ? 'active' : '' ?>" id="notifBadgeDot"></span>
        <span class="notif-badge-count <?= $comp_unread_count > 0 ? '' : 'd-none' ?>" id="notifBadgeCount"><?= $comp_unread_count ?></span>
    </button>

    <!-- Dropdown Menu -->
    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dropdown-header">
            <div class="notif-header-title">
                <i class="bi bi-bell-fill"></i>
                <span>Notifications</span>
                <span class="notif-header-pill" id="notifHeaderPill"><?= $comp_unread_count ?> Unread</span>
            </div>
            <div class="notif-header-actions">
                <button type="button" class="notif-header-btn mark-all-btn" onclick="markAllNotificationsRead()" title="Mark all as read">
                    <i class="bi bi-check2-all"></i> Mark all read
                </button>
            </div>
        </div>

        <div class="notif-tabs">
            <button type="button" class="notif-tab active" onclick="switchNotifFilter('all', this)">All</button>
            <button type="button" class="notif-tab" onclick="switchNotifFilter('unread', this)">Unread</button>
        </div>

        <div class="notif-list" id="notifList">
            <div class="notif-empty"><i class="bi bi-bell-slash"></i><p>Loading notifications...</p></div>
        </div>

        <div class="notif-footer">
            <a href="notification.php" class="notif-create-action-btn" style="text-decoration:none; justify-content:center;">
                <i class="bi bi-eye"></i> View More
            </a>
        </div>
    </div>
</div>

<!-- ════ NOTIFICATION DETAIL MODAL ════ -->
<div id="notifDetailModal" class="modal-backdrop" style="align-items:flex-start; overflow-y:auto; padding:24px 10px; z-index:2000;" onclick="if(event.target===this)closeNotifDetailModal()">
    <div class="modal-box" style="max-width:520px; width:95%; max-height:calc(100vh - 48px); overflow-y:auto; border-radius:18px; padding:24px; margin:auto;">
        <div class="modal-header" style="margin-bottom:14px; padding-bottom:12px;">
            <h4 style="font-size:16px; font-weight:800; color:#06251b; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-bell-fill" style="color:#e67e22;"></i>
                <span id="ndModalTitle">Notification Details</span>
            </h4>
            <button type="button" class="modal-close" onclick="closeNotifDetailModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div style="margin-bottom:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span id="ndModalBadge" class="notif-target-badge all"><i class="bi bi-globe"></i> Broadcast</span>
            <span id="ndModalDate" style="font-size:11.5px; color:#88968d; font-weight:600;"></span>
            <span id="ndModalAuthor" style="font-size:11.5px; color:#88968d; margin-left:auto;"></span>
        </div>
        <div id="ndModalMessage" style="background:#f7faf8; border:1px solid #eaeeec; border-radius:12px; padding:16px; font-size:13px; color:#2d3a32; line-height:1.6; white-space:pre-wrap; max-height:280px; overflow-y:auto;">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:14px; border-top:1px solid #eaeeec;">
            <button type="button" style="padding:10px 24px; background:#06251b; color:#ffc107; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:7px;" onclick="closeNotifDetailModal()">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ════ NOTIFICATIONS JAVASCRIPT CONTROLLER ════ -->
<script>
(function() {
    if (window._bidderNotifLoaded) return;
    window._bidderNotifLoaded = true;

    let allNotifications = [];
    let currentNotifFilter = 'all';
    let isNotifDropdownOpen = false;

    window.toggleNotifDropdown = function(e) {
        if (e) e.stopPropagation();
        const dropdown = document.getElementById('notifDropdown');
        const btn = document.getElementById('notifToggleBtn');
        if (!dropdown || !btn) return;

        isNotifDropdownOpen = !isNotifDropdownOpen;
        if (isNotifDropdownOpen) {
            dropdown.classList.add('open');
            btn.classList.add('active');
            fetchNotifications();
        } else {
            dropdown.classList.remove('open');
            btn.classList.remove('active');
        }
    };

    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('notifWrapper');
        if (wrapper && !wrapper.contains(e.target) && isNotifDropdownOpen) {
            const dropdown = document.getElementById('notifDropdown');
            const btn = document.getElementById('notifToggleBtn');
            if (dropdown) dropdown.classList.remove('open');
            if (btn) btn.classList.remove('active');
            isNotifDropdownOpen = false;
        }
    });

    window.fetchNotifications = async function() {
        try {
            const res = await fetch('notifications_api.php?action=fetch');
            const data = await res.json();
            if (data.status === 'success') {
                allNotifications = data.notifications || [];
                updateNotifBadge(data.unread_count);
                renderNotifList(allNotifications, currentNotifFilter);
            }
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    };

    window.updateNotifBadge = function(unreadCount) {
        const countEl = document.getElementById('notifBadgeCount');
        const dotEl   = document.getElementById('notifBadgeDot');
        const pillEl  = document.getElementById('notifHeaderPill');

        if (countEl && dotEl && pillEl) {
            if (unreadCount > 0) {
                countEl.textContent = unreadCount;
                countEl.classList.remove('d-none');
                dotEl.classList.add('active');
                pillEl.textContent = `${unreadCount} Unread`;
                pillEl.style.display = 'inline-block';
            } else {
                countEl.classList.add('d-none');
                dotEl.classList.remove('active');
                pillEl.textContent = '0 Unread';
            }
        }
    };

    window.renderNotifList = function(list, filter) {
        const listEl = document.getElementById('notifList');
        if (!listEl) return;

        let items = list;
        if (filter === 'unread') {
            items = list.filter(n => !n.is_read);
        }

        if (items.length === 0) {
            listEl.innerHTML = `
                <div class="notif-empty">
                    <i class="bi bi-bell-slash"></i>
                    <p>${filter === 'unread' ? 'No unread notifications' : 'No notifications yet'}</p>
                </div>
            `;
            return;
        }

        listEl.innerHTML = items.map(n => {
            let targetBadge = '<span class="notif-target-badge all"><i class="bi bi-globe"></i> Broadcast</span>';
            if (n.target_type === 'role') {
                targetBadge = `<span class="notif-target-badge role"><i class="bi bi-people"></i> ${escapeHtml(n.target_role)}</span>`;
            } else if (n.target_type === 'user') {
                targetBadge = '<span class="notif-target-badge user"><i class="bi bi-person"></i> Direct</span>';
            }

            return `
                <div class="notif-item ${n.is_read ? 'read' : 'unread'}" onclick="openNotifDetail(${n.id})">
                    <div class="notif-item-icon">
                        <i class="bi bi-${n.is_read ? 'bell' : 'bell-fill'}"></i>
                    </div>
                    <div class="notif-item-content">
                        <div class="notif-item-top">
                            <span class="notif-item-title" title="${escapeHtml(n.title)}">${escapeHtml(n.title)}</span>
                            <span class="notif-item-time">${escapeHtml(n.time_ago)}</span>
                        </div>
                        <div class="notif-item-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-item-meta">
                            ${targetBadge}
                            <span style="font-size:10px; color:#aaa;">by ${escapeHtml(n.creator_name)}</span>
                            ${!n.is_read ? '<span class="notif-unread-indicator"></span>' : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    };

    window.switchNotifFilter = function(filter, tabBtn) {
        currentNotifFilter = filter;
        document.querySelectorAll('.notif-tab').forEach(btn => btn.classList.remove('active'));
        if (tabBtn) tabBtn.classList.add('active');
        renderNotifList(allNotifications, currentNotifFilter);
    };

    window.openNotifDetail = async function(notifId) {
        const notif = allNotifications.find(n => n.id == notifId);
        if (!notif) return;

        // Close dropdown when opening detail modal
        const dropdown = document.getElementById('notifDropdown');
        const btn = document.getElementById('notifToggleBtn');
        if (dropdown) dropdown.classList.remove('open');
        if (btn) btn.classList.remove('active');
        isNotifDropdownOpen = false;

        document.getElementById('ndModalTitle').textContent   = notif.title;
        document.getElementById('ndModalMessage').textContent = notif.message;
        document.getElementById('ndModalDate').textContent    = notif.formatted_date;
        document.getElementById('ndModalAuthor').textContent  = `From: ${notif.creator_name}`;

        const badgeEl = document.getElementById('ndModalBadge');
        if (badgeEl) {
            badgeEl.className = `notif-target-badge ${notif.target_type}`;
            if (notif.target_type === 'all') badgeEl.innerHTML = '<i class="bi bi-globe"></i> Broadcast';
            else if (notif.target_type === 'role') badgeEl.innerHTML = `<i class="bi bi-people"></i> ${notif.target_role}`;
            else badgeEl.innerHTML = '<i class="bi bi-person"></i> Direct';
        }

        const modal = document.getElementById('notifDetailModal');
        if (modal) modal.classList.add('open');

        // Mark as read in backend
        if (!notif.is_read) {
            notif.is_read = true;
            try {
                const formData = new FormData();
                formData.append('action', 'mark_read');
                formData.append('notification_id', notifId);
                const res = await fetch('notifications_api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') {
                    updateNotifBadge(data.unread_count);
                    renderNotifList(allNotifications, currentNotifFilter);
                }
            } catch (err) {
                console.error('Error marking as read:', err);
            }
        }
    };

    window.closeNotifDetailModal = function() {
        const modal = document.getElementById('notifDetailModal');
        if (modal) modal.classList.remove('open');
    };

    window.markAllNotificationsRead = async function() {
        try {
            const formData = new FormData();
            formData.append('action', 'mark_all_read');
            const res = await fetch('notifications_api.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                allNotifications.forEach(n => n.is_read = true);
                updateNotifBadge(0);
                renderNotifList(allNotifications, currentNotifFilter);
            }
        } catch (err) {
            console.error('Error marking all as read:', err);
        }
    };

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Auto-poll notifications every 60s
    setInterval(fetchNotifications, 60000);
})();
</script>
