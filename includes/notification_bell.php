<?php
/**
 * Shared topbar notification bell + dropdown (admin & bidder portals).
 *
 * Included by {admin,bidder}/components/notifications.php. Optional input:
 *   $notif_bell_manage_url  — when set, shows a "Manage" shortcut in the footer.
 *
 * Talks to ./notifications_api.php (personal actions only).
 */
require_once __DIR__ . '/../utils/notification_helper.php';

$comp_unread_count = (isset($conn) && isset($_SESSION['user_id']))
    ? notif_unread_count($conn, (int)$_SESSION['user_id'])
    : 0;
$notif_bell_manage_url = $notif_bell_manage_url ?? null;
?>

<!-- ════ TOPBAR NOTIFICATION BELL & DROPDOWN ════ -->
<div class="topbar-notif-wrapper" id="notifWrapper">
    <button type="button" class="topbar-badge notif-toggle-btn" id="notifToggleBtn" onclick="toggleNotifDropdown(event)" aria-label="Notifications" title="Notifications">
        <i class="bi bi-bell"></i>
        <span class="notif-badge-dot <?= $comp_unread_count > 0 ? 'active' : '' ?>" id="notifBadgeDot"></span>
        <span class="notif-badge-count <?= $comp_unread_count > 0 ? '' : 'd-none' ?>" id="notifBadgeCount"><?= $comp_unread_count > 99 ? '99+' : $comp_unread_count ?></span>
    </button>

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

        <div class="notif-footer notif-footer--split">
            <a href="notification.php" class="notif-create-action-btn notif-footer-link">
                <i class="bi bi-inbox"></i> View all
            </a>
            <?php if ($notif_bell_manage_url): ?>
            <a href="<?= htmlspecialchars($notif_bell_manage_url) ?>" class="notif-create-action-btn notif-footer-link notif-footer-link--ghost">
                <i class="bi bi-send"></i> Send notification
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════ NOTIFICATION DETAIL MODAL (for notifications without a link) ════ -->
<div id="notifDetailModal" class="modal-backdrop notif-detail-backdrop" onclick="if(event.target===this)closeNotifDetailModal()">
    <div class="modal-box notif-detail-box">
        <div class="notif-detail-head">
            <div class="notif-detail-headtext">
                <span class="nt-type-pill" id="ndModalLabel">Notification</span>
                <h4 id="ndModalTitle">Notification</h4>
            </div>
            <button type="button" class="modal-close" onclick="closeNotifDetailModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="notif-detail-meta">
            <span><i class="bi bi-clock"></i> <span id="ndModalDate"></span></span>
            <span><i class="bi bi-person"></i> <span id="ndModalAuthor"></span></span>
        </div>
        <div id="ndModalMessage" class="notif-detail-message"></div>
        <div class="notif-detail-foot">
            <a id="ndModalLink" class="notif-detail-open" href="#" hidden><i class="bi bi-box-arrow-up-right"></i> Open</a>
            <button type="button" class="notif-detail-close" onclick="closeNotifDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    if (window._notifBellLoaded) return;
    window._notifBellLoaded = true;

    const API = 'notifications_api.php';
    let items = [];
    let filter = 'all';
    let open = false;

    const $ = (id) => document.getElementById(id);

    function esc(text) {
        if (text === null || text === undefined) return '';
        return String(text).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }
    window.notifEscape = esc;

    /** Links are stored relative to the app root; every portal page is one level deep. */
    window.notifHref = function (link) { return link ? '../' + link : null; };

    async function post(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(API, { method: 'POST', body: fd });
        return res.json();
    }
    window.notifPost = post;

    window.toggleNotifDropdown = function (e) {
        if (e) e.stopPropagation();
        open = !open;
        $('notifDropdown').classList.toggle('open', open);
        $('notifToggleBtn').classList.toggle('active', open);
        if (open) fetchNotifications();
    };

    document.addEventListener('click', function (e) {
        const wrapper = $('notifWrapper');
        if (open && wrapper && !wrapper.contains(e.target)) {
            open = false;
            $('notifDropdown').classList.remove('open');
            $('notifToggleBtn').classList.remove('active');
        }
    });

    window.fetchNotifications = async function () {
        try {
            const res = await fetch(`${API}?action=fetch&limit=15`);
            const data = await res.json();
            if (data.status === 'success') {
                items = data.notifications || [];
                updateNotifBadge(data.unread_count);
                render();
            }
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    };

    window.updateNotifBadge = function (count) {
        const countEl = $('notifBadgeCount'), dotEl = $('notifBadgeDot'), pillEl = $('notifHeaderPill');
        if (!countEl) return;
        countEl.textContent = count > 99 ? '99+' : count;
        countEl.classList.toggle('d-none', count <= 0);
        dotEl.classList.toggle('active', count > 0);
        pillEl.textContent = `${count} Unread`;
        document.dispatchEvent(new CustomEvent('notif:unread', { detail: count }));
    };

    function render() {
        const listEl = $('notifList');
        const list = filter === 'unread' ? items.filter((n) => !n.is_read) : items;
        if (!list.length) {
            listEl.innerHTML = `<div class="notif-empty"><i class="bi bi-bell-slash"></i><p>${filter === 'unread' ? "You're all caught up" : 'No notifications yet'}</p></div>`;
            return;
        }
        listEl.innerHTML = list.map((n) => `
            <div class="notif-item ${n.is_read ? 'read' : 'unread'}" onclick="openNotif(${n.id})">
                <div class="notif-item-content">
                    <div class="notif-item-top">
                        <span class="notif-item-title" title="${esc(n.title)}">${esc(n.title)}</span>
                        <span class="notif-item-time">${esc(n.time_ago)}</span>
                    </div>
                    <div class="notif-item-msg">${esc(n.message)}</div>
                    <div class="notif-item-meta">
                        <span class="nt-type-pill nt-tone--${esc(n.tone)}">${esc(n.label)}</span>
                        <span class="notif-item-actor">${esc(n.actor_name)}</span>
                        ${n.link ? '<i class="bi bi-arrow-right-short notif-item-go"></i>' : ''}
                        ${!n.is_read ? '<span class="notif-unread-indicator"></span>' : ''}
                    </div>
                </div>
            </div>`).join('');
    }

    window.switchNotifFilter = function (f, btn) {
        filter = f;
        document.querySelectorAll('.notif-tab').forEach((b) => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        render();
    };

    /** Open a notification object: mark read, then follow its link or show the detail modal. */
    window.openNotifObject = async function (n, onRead) {
        if (!n.is_read) {
            n.is_read = true;
            try {
                const data = await post('mark_read', { notification_id: n.id });
                if (data.status === 'success') updateNotifBadge(data.unread_count);
            } catch (err) { console.error(err); }
            if (onRead) onRead(n);
        }
        if (n.link) {
            window.location.href = notifHref(n.link);
            return;
        }
        showNotifDetail(n);
    };

    window.openNotif = function (id) {
        const n = items.find((x) => x.id === id);
        if (!n) return;
        open = false;
        $('notifDropdown').classList.remove('open');
        $('notifToggleBtn').classList.remove('active');
        openNotifObject(n, render);
    };

    window.showNotifDetail = function (n) {
        $('ndModalLabel').className = `nt-type-pill nt-tone--${n.tone}`;
        $('ndModalLabel').textContent = n.label;
        $('ndModalTitle').textContent = n.title;
        $('ndModalMessage').textContent = n.message;
        $('ndModalDate').textContent = n.formatted_date;
        $('ndModalAuthor').textContent = n.actor_name;
        const linkEl = $('ndModalLink');
        linkEl.hidden = !n.link;
        if (n.link) linkEl.href = notifHref(n.link);
        $('notifDetailModal').classList.add('open');
    };

    window.closeNotifDetailModal = function () {
        $('notifDetailModal').classList.remove('open');
    };

    window.markAllNotificationsRead = async function () {
        try {
            const data = await post('mark_all_read');
            if (data.status === 'success') {
                items.forEach((n) => (n.is_read = true));
                updateNotifBadge(0);
                render();
                document.dispatchEvent(new CustomEvent('notif:all-read'));
            }
        } catch (err) {
            console.error('Error marking all as read:', err);
        }
    };

    // Keep the badge fresh
    setInterval(fetchNotifications, 60000);
})();
</script>
