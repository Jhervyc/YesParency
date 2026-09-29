<?php
/**
 * Personal notification inbox — shared by admin/notification.php and bidder/notification.php.
 * Expects $conn and $inbox_user_id. Uses ./notifications_api.php and the bell controller
 * (includes/notification_bell.php, loaded by the topbar) for notifPost / notifHref / updateNotifBadge.
 */
require_once __DIR__ . '/../utils/notification_helper.php';

$inbox_counts = notif_category_counts($conn, (int)$inbox_user_id);
$inbox_unread = $inbox_counts['all']['unread'];
?>
<header class="ni-head">
    <div class="ni-head-text">
        <h2>
            Notifications
            <span class="ni-new-badge <?= $inbox_unread ? '' : 'is-zero' ?>" id="niNewBadge"><?= $inbox_unread ?> new</span>
        </h2>
        <p>Updates about your bids, bid sessions, documents and messages from the BAC Secretariat.</p>
    </div>
    <button type="button" class="ni-markall" id="niMarkAll" <?= $inbox_unread ? '' : 'disabled' ?>>
        <i class="bi bi-check2-all"></i> Mark all as read
    </button>
</header>

<div class="ni-layout">

    <!-- ── Filter rail ── -->
    <aside class="ni-rail">
        <div class="ni-search">
            <i class="bi bi-search"></i>
            <input type="search" id="niSearch" placeholder="Search notifications…" aria-label="Search notifications">
        </div>
        <nav class="ni-cats" id="niCats" aria-label="Filter notifications">
            <?php foreach (NOTIF_CATEGORIES as $key => $cat):
                $c = $inbox_counts[$key];
                $badge = $key === 'all' || $key === 'unread' ? $c['total'] : $c['unread'];
            ?>
            <button type="button" class="ni-cat <?= $key === 'all' ? 'active' : '' ?>" data-cat="<?= $key ?>">
                <i class="bi bi-<?= $cat['icon'] ?>"></i>
                <span class="ni-cat-label"><?= $cat['label'] ?></span>
                <span class="ni-cat-count <?= ($key !== 'all' && $c['unread'] > 0) ? 'has-unread' : '' ?> <?= $badge ? '' : 'is-zero' ?>" data-count="<?= $key ?>"><?= $badge ?></span>
            </button>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- ── List ── -->
    <section class="ni-panel" aria-live="polite">
        <div class="ni-panel-head">
            <h3 id="niPanelTitle">All notifications</h3>
            <span id="niPanelSub"></span>
        </div>
        <div class="ni-list" id="niList"></div>
        <div class="ni-foot">
            <button type="button" class="ni-more" id="niMore" hidden><i class="bi bi-arrow-down-circle"></i> Load more</button>
        </div>
    </section>
</div>

<div class="ni-toast" id="niToast" role="status"><i class="bi bi-check-circle-fill"></i><span></span></div>

<script>
(function () {
    const API = 'notifications_api.php';
    const $ = (id) => document.getElementById(id);
    const esc = (t) => (t === null || t === undefined) ? '' : String(t).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    const CATS = <?= json_encode(NOTIF_CATEGORIES) ?>;

    const state = { category: 'all', search: '', offset: 0, loading: false, items: new Map(), lastGroup: null };
    let counts = <?= json_encode($inbox_counts) ?>;

    async function post(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return (await fetch(API, { method: 'POST', body: fd })).json();
    }

    function toast(text) {
        const el = $('niToast');
        el.querySelector('span').textContent = text;
        el.classList.add('show');
        clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.remove('show'), 2600);
    }

    /* ── Counts / header ── */
    function renderCounts() {
        Object.entries(counts).forEach(([key, c]) => {
            const el = document.querySelector(`[data-count="${key}"]`);
            if (!el) return;
            const n = (key === 'all' || key === 'unread') ? c.total : c.unread;
            el.textContent = n;
            el.classList.toggle('is-zero', !n);
            el.classList.toggle('has-unread', key !== 'all' && c.unread > 0);
        });
        setUnread(counts.all.unread);
        const c = counts[state.category] || counts.all;
        $('niPanelSub').textContent = state.category === 'unread'
            ? `${c.total} unread`
            : `${c.total} total · ${c.unread} unread`;
    }

    function setUnread(n) {
        const badge = $('niNewBadge');
        badge.textContent = `${n} new`;
        badge.classList.toggle('is-zero', !n);
        $('niMarkAll').disabled = !n;
    }

    async function refreshCounts() {
        const data = await (await fetch(`${API}?action=fetch&limit=1&with_counts=1`)).json();
        if (data.counts) { counts = data.counts; renderCounts(); }
        if (typeof updateNotifBadge === 'function') updateNotifBadge(data.unread_count);
        if (typeof fetchNotifications === 'function') fetchNotifications();
    }

    /* ── List ── */
    function skeleton() {
        return Array.from({ length: 4 }, () => `
            <div class="ni-skel"><div class="ni-skel-lines"><span></span><span></span><span></span></div></div>`).join('');
    }

    function emptyState() {
        if (state.search) return `<div class="ni-empty"><i class="bi bi-search"></i><h4>No matches</h4><p>Nothing found for “${esc(state.search)}”.</p></div>`;
        if (state.category === 'unread') return `<div class="ni-empty"><i class="bi bi-check2-circle"></i><h4>You're all caught up</h4><p>No unread notifications. Nice work!</p></div>`;
        return `<div class="ni-empty"><i class="bi bi-bell-slash"></i><h4>Nothing here yet</h4><p>${state.category === 'all' ? "When something happens with your account, it'll show up here." : `No ${esc(CATS[state.category].label.toLowerCase())} notifications yet.`}</p></div>`;
    }

    function itemHtml(n) {
        return `
        <article class="ni-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}">
            <div class="ni-body" role="button" tabindex="0" data-open>
                <div class="ni-top">
                    <span class="ni-title">${esc(n.title)}</span>
                    <span class="ni-time" title="${esc(n.formatted_date)}">${esc(n.time_ago)}</span>
                </div>
                <div class="ni-msg">${esc(n.message)}</div>
                <div class="ni-meta">
                    <span class="nt-type-pill nt-tone--${esc(n.tone)}">${esc(n.label)}</span>
                    <span class="ni-actor"><i class="bi bi-person"></i> ${esc(n.actor_name)}</span>
                    ${n.link ? '<span class="ni-open">Open <i class="bi bi-arrow-right"></i></span>' : ''}
                </div>
            </div>
            <div class="ni-actions">
                <button type="button" class="ni-act" data-toggle title="${n.is_read ? 'Mark as unread' : 'Mark as read'}">
                    <i class="bi bi-${n.is_read ? 'envelope' : 'envelope-open'}"></i><span>${n.is_read ? 'Mark unread' : 'Mark read'}</span>
                </button>
                <button type="button" class="ni-act ni-act--danger" data-delete title="Delete">
                    <i class="bi bi-trash3"></i><span>Delete</span>
                </button>
            </div>
            <button type="button" class="ni-kebab" data-kebab aria-label="More actions"><i class="bi bi-three-dots-vertical"></i></button>
            <span class="ni-dot" aria-hidden="true"></span>
        </article>`;
    }

    async function load(reset) {
        if (state.loading) return;
        state.loading = true;
        const list = $('niList');
        if (reset) {
            state.offset = 0;
            state.lastGroup = null;
            state.items.clear();
            list.innerHTML = skeleton();
            $('niPanelTitle').textContent = state.category === 'all' ? 'All notifications' : CATS[state.category].label;
        }
        $('niMore').disabled = true;
        try {
            const q = new URLSearchParams({ action: 'fetch', category: state.category, search: state.search, offset: state.offset, limit: 15 });
            const data = await (await fetch(`${API}?${q}`)).json();
            const rows = data.notifications || [];
            if (reset) list.innerHTML = '';
            if (reset && !rows.length) {
                list.innerHTML = emptyState();
            } else {
                let html = '';
                rows.forEach((n) => {
                    state.items.set(n.id, n);
                    if (n.date_group !== state.lastGroup) {
                        state.lastGroup = n.date_group;
                        html += `<div class="ni-group">${esc(n.date_group)}</div>`;
                    }
                    html += itemHtml(n);
                });
                list.insertAdjacentHTML('beforeend', html);
            }
            state.offset += rows.length;
            $('niMore').hidden = !data.has_more;
        } catch (e) {
            list.innerHTML = `<div class="ni-empty"><i class="bi bi-wifi-off"></i><h4>Couldn't load notifications</h4><p>Check your connection and try again.</p></div>`;
        } finally {
            state.loading = false;
            $('niMore').disabled = false;
        }
    }

    function setRead(article, n, isRead) {
        n.is_read = isRead;
        article.classList.toggle('unread', !isRead);
        const btn = article.querySelector('[data-toggle]');
        btn.title = isRead ? 'Mark as unread' : 'Mark as read';
        btn.innerHTML = `<i class="bi bi-${isRead ? 'envelope' : 'envelope-open'}"></i><span>${isRead ? 'Mark unread' : 'Mark read'}</span>`;
    }

    function removeArticle(article) {
        article.classList.add('removing');
        setTimeout(() => {
            const prev = article.previousElementSibling, next = article.nextElementSibling;
            article.remove();
            // Drop a date header left without items
            if (prev && prev.classList.contains('ni-group') && (!next || next.classList.contains('ni-group'))) prev.remove();
            if (!$('niList').querySelector('.ni-item')) $('niList').innerHTML = emptyState();
        }, 220);
    }

    $('niList').addEventListener('click', async (e) => {
        const article = e.target.closest('.ni-item');
        if (!article) return;
        const n = state.items.get(Number(article.dataset.id));
        if (!n) return;

        if (e.target.closest('[data-kebab]')) {
            document.querySelectorAll('.ni-item.menu-open').forEach((x) => x !== article && x.classList.remove('menu-open'));
            article.classList.toggle('menu-open');
            return;
        }
        article.classList.remove('menu-open');

        if (e.target.closest('[data-toggle]')) {
            const next = !n.is_read;
            setRead(article, n, next);
            await post(next ? 'mark_read' : 'mark_unread', { notification_id: n.id });
            if (state.category === 'unread' && next) { state.items.delete(n.id); state.offset--; removeArticle(article); }
            refreshCounts();
            return;
        }

        if (e.target.closest('[data-delete]')) {
            state.items.delete(n.id);
            state.offset = Math.max(0, state.offset - 1);
            removeArticle(article);
            await post('delete', { notification_id: n.id });
            toast('Notification deleted');
            refreshCounts();
            return;
        }

        if (e.target.closest('[data-open]')) {
            if (n.link) {
                // Marks read, then navigates
                openNotifObject(n);
                return;
            }
            article.classList.toggle('expanded');
            if (!n.is_read) {
                setRead(article, n, true);
                await post('mark_read', { notification_id: n.id });
                refreshCounts();
            }
        }
    });

    $('niList').addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-open]')) {
            e.preventDefault();
            e.target.click();
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.ni-item')) document.querySelectorAll('.ni-item.menu-open').forEach((x) => x.classList.remove('menu-open'));
    });

    $('niCats').addEventListener('click', (e) => {
        const btn = e.target.closest('.ni-cat');
        if (!btn) return;
        document.querySelectorAll('.ni-cat').forEach((b) => b.classList.toggle('active', b === btn));
        state.category = btn.dataset.cat;
        renderCounts();
        load(true);
    });

    let searchTimer;
    $('niSearch').addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { state.search = e.target.value.trim(); load(true); }, 300);
    });

    $('niMore').addEventListener('click', () => load(false));

    $('niMarkAll').addEventListener('click', async () => {
        if (typeof markAllNotificationsRead === 'function') {
            await markAllNotificationsRead(); // fires notif:all-read
        } else {
            await post('mark_all_read');
            onAllRead();
        }
        toast('All notifications marked as read');
    });

    function onAllRead() {
        if (state.category === 'unread') { load(true); } else {
            document.querySelectorAll('.ni-item.unread').forEach((a) => {
                const n = state.items.get(Number(a.dataset.id));
                if (n) setRead(a, n, true);
            });
        }
        Object.values(counts).forEach((c) => (c.unread = 0));
        counts.unread.total = 0;
        renderCounts();
    }
    document.addEventListener('notif:all-read', onAllRead);
    document.addEventListener('notif:unread', (e) => setUnread(e.detail));

    renderCounts();
    load(true);
})();
</script>
