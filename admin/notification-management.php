<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/notification_helper.php");

$stats = notif_admin_stats($conn);

$system_filters = ['all' => 'All', 'bids' => 'Bids', 'sessions' => 'Bid Sessions', 'account' => 'Account', 'documents' => 'Documents'];
$sender_name    = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: ($_SESSION['username'] ?? 'You');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Management | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-notification-management.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ════ HERO ════ -->
    <header class="nm-hero">
        <div class="nm-hero-text">
            <h2>Notification Management</h2>
            <p>Send notifications straight to users' bells and track who has read them. System alerts (bids, sessions, documents) are logged here automatically.</p>
        </div>
        <div class="nm-kpis">
            <div class="nm-kpi">
                <span class="nm-kpi-icon"><i class="bi bi-send-fill"></i></span>
                <div><div class="nm-kpi-num" id="kpiSent"><?= number_format($stats['sent_30d']) ?></div><div class="nm-kpi-lbl">Sent · 30 days</div></div>
            </div>
            <div class="nm-kpi">
                <span class="nm-kpi-icon nm-kpi-icon--gold"><i class="bi bi-eye-fill"></i></span>
                <div><div class="nm-kpi-num" id="kpiRate"><?= $stats['read_rate'] ?>%</div><div class="nm-kpi-lbl">Avg. read rate</div></div>
            </div>
            <div class="nm-kpi">
                <span class="nm-kpi-icon nm-kpi-icon--orange"><i class="bi bi-envelope-exclamation-fill"></i></span>
                <div><div class="nm-kpi-num" id="kpiUnread"><?= number_format($stats['unread']) ?></div><div class="nm-kpi-lbl">Unread system-wide</div></div>
            </div>
        </div>
    </header>

    <div class="nm-grid">

        <!-- ════ COMPOSER ════ -->
        <section class="nm-card nm-composer" aria-labelledby="composerTitle">
            <div class="nm-card-head">
                <div class="nm-card-title" id="composerTitle"><span class="nm-card-icon"><i class="bi bi-pencil-square"></i></span> Compose</div>
                <button type="button" class="nm-text-btn" onclick="resetComposer()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </div>

            <form id="composerForm" autocomplete="off" onsubmit="sendNotification(event)">
                <div class="nm-field">
                    <label for="nmTitle">Title <span class="nm-req">*</span></label>
                    <input type="text" id="nmTitle" maxlength="120" placeholder="e.g. Pre-bid conference moved to Friday" required>
                    <div class="nm-counter"><span id="titleCount">0</span>/120</div>
                </div>

                <div class="nm-field">
                    <label for="nmMessage">Message <span class="nm-req">*</span></label>
                    <textarea id="nmMessage" rows="4" maxlength="1000" placeholder="Write what recipients need to know…" required></textarea>
                    <div class="nm-counter"><span id="msgCount">0</span>/1000</div>
                </div>

                <div class="nm-field">
                    <label>Audience <span class="nm-req">*</span></label>
                    <div class="nm-segment" role="radiogroup">
                        <label><input type="radio" name="audience" value="all" checked><span><i class="bi bi-globe2"></i> Everyone</span></label>
                        <label><input type="radio" name="audience" value="role"><span><i class="bi bi-people-fill"></i> By role</span></label>
                        <label><input type="radio" name="audience" value="users"><span><i class="bi bi-person-check-fill"></i> Specific users</span></label>
                    </div>

                    <div class="nm-audience-panel" id="rolePanel" hidden>
                        <div class="nm-chips">
                            <?php foreach (NOTIF_AUDIENCE_ROLES as $role => $label): ?>
                            <label class="nm-chip">
                                <input type="checkbox" name="roles" value="<?= $role ?>" <?= $role === 'bidder' ? 'checked' : '' ?>>
                                <span><i class="bi bi-check-lg"></i> <?= $label ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="nm-audience-panel" id="usersPanel" hidden>
                        <div class="nm-combobox">
                            <i class="bi bi-search"></i>
                            <input type="text" id="userSearch" placeholder="Search name, username, email or business…" aria-autocomplete="list" aria-controls="userResults">
                            <div class="nm-combo-results" id="userResults" role="listbox" hidden></div>
                        </div>
                        <div class="nm-picked" id="pickedUsers"><span class="nm-picked-empty">No users selected yet.</span></div>
                    </div>
                </div>

                <!-- Live preview -->
                <div class="nm-preview">
                    <div class="nm-preview-label"><i class="bi bi-phone"></i> Preview in bell</div>
                    <div class="notif-item unread nm-preview-item">
                        <div class="notif-item-content">
                            <div class="notif-item-top">
                                <span class="notif-item-title" id="pvTitle">Your title appears here</span>
                                <span class="notif-item-time">Just now</span>
                            </div>
                            <div class="notif-item-msg" id="pvMessage">And your message right below it.</div>
                            <div class="notif-item-meta">
                                <span class="nt-type-pill nt-tone--blue">Announcement</span>
                                <span class="notif-item-actor"><?= htmlspecialchars($sender_name) ?></span>
                                <span class="notif-unread-indicator"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="nm-send-row">
                    <div class="nm-reach" id="reach"><i class="bi bi-people"></i> <span id="reachText">Counting recipients…</span></div>
                    <button type="submit" class="nm-send-btn" id="sendBtn">
                        <span class="nm-send-label"><i class="bi bi-send-fill"></i> Send</span>
                        <span class="nm-spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </section>

        <!-- ════ ACTIVITY FEED ════ -->
        <section class="nm-card nm-feed" aria-label="Notification activity">
            <div class="nm-tabs" role="tablist">
                <button type="button" class="nm-tab active" role="tab" data-tab="sent"><i class="bi bi-send"></i> Sent messages</button>
                <button type="button" class="nm-tab" role="tab" data-tab="system"><i class="bi bi-cpu"></i> System activity</button>
            </div>

            <div class="nm-toolbar">
                <div class="nm-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="feedSearch" placeholder="Search sent messages…">
                </div>
                <div class="nm-filter-chips" id="systemFilters" hidden>
                    <?php foreach ($system_filters as $key => $label): ?>
                    <button type="button" class="nm-filter-chip <?= $key === 'all' ? 'active' : '' ?>" data-cat="<?= $key ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="nm-feed-list" id="feedList" aria-live="polite"></div>

            <div class="nm-feed-foot">
                <button type="button" class="nm-more-btn" id="loadMoreBtn" hidden><i class="bi bi-arrow-down-circle"></i> Load more</button>
            </div>
        </section>

    </div>
</div>
</main>

<!-- ════ RECIPIENTS DRAWER ════ -->
<div class="nm-drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
<aside class="nm-drawer" id="recipientsDrawer" aria-hidden="true" aria-labelledby="drawerTitle">
    <div class="nm-drawer-head">
        <div>
            <div class="nm-drawer-eyebrow">Recipients</div>
            <h3 id="drawerTitle">—</h3>
            <div class="nm-drawer-sub" id="drawerSub"></div>
        </div>
        <button type="button" class="nm-icon-btn" onclick="closeDrawer()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="nm-drawer-summary">
        <div class="nm-ring" id="drawerRing"><span id="drawerPct">0%</span></div>
        <div>
            <div class="nm-drawer-stat"><span class="nm-dot nm-dot--read"></span> <b id="drawerRead">0</b> read</div>
            <div class="nm-drawer-stat"><span class="nm-dot"></span> <b id="drawerUnread">0</b> not yet read</div>
        </div>
    </div>
    <div class="nm-drawer-filter">
        <button type="button" class="nm-filter-chip active" data-rfilter="all">All</button>
        <button type="button" class="nm-filter-chip" data-rfilter="read">Read</button>
        <button type="button" class="nm-filter-chip" data-rfilter="unread">Unread</button>
    </div>
    <div class="nm-drawer-list" id="drawerList"></div>
</aside>

<!-- ════ RECALL CONFIRM ════ -->
<div class="nm-dialog-backdrop" id="recallDialog" onclick="if(event.target===this)closeRecall()">
    <div class="nm-dialog" role="alertdialog" aria-labelledby="recallTitle">
        <span class="nm-dialog-icon"><i class="bi bi-arrow-counterclockwise"></i></span>
        <h4 id="recallTitle">Recall this notification?</h4>
        <p>It will be removed from every recipient's bell and inbox, including people who already read it. This can't be undone.</p>
        <div class="nm-dialog-preview" id="recallPreview"></div>
        <div class="nm-dialog-actions">
            <button type="button" class="nm-btn-ghost" onclick="closeRecall()">Cancel</button>
            <button type="button" class="nm-btn-danger" id="recallConfirmBtn"><i class="bi bi-arrow-counterclockwise"></i> Recall</button>
        </div>
    </div>
</div>

<!-- ════ TOAST ════ -->
<div class="nm-toast" id="toast" role="status"><i class="bi bi-check-circle-fill"></i><span id="toastText"></span></div>

<script>
(function () {
    const API = 'notifications_api.php';
    const $ = (id) => document.getElementById(id);
    const esc = (t) => (t === null || t === undefined) ? '' : String(t).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
    const ROLE_LABELS = <?= json_encode(NOTIF_AUDIENCE_ROLES) ?>;

    async function api(action, { method = 'GET', params = {}, body = null } = {}) {
        let url = `${API}?action=${encodeURIComponent(action)}`;
        Object.entries(params).forEach(([k, v]) => { url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`; });
        const opts = { method };
        if (body) { body.append('action', action); opts.body = body; }
        const res = await fetch(url, opts);
        return res.json();
    }

    function toast(text, isError = false) {
        const el = $('toast');
        $('toastText').textContent = text;
        el.classList.toggle('nm-toast--error', isError);
        el.querySelector('i').className = isError ? 'bi bi-exclamation-circle-fill' : 'bi bi-check-circle-fill';
        el.classList.add('show');
        clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.remove('show'), 3200);
    }

    function applyStats(s) {
        if (!s) return;
        $('kpiSent').textContent = Number(s.sent_30d).toLocaleString();
        $('kpiRate').textContent = s.read_rate + '%';
        $('kpiUnread').textContent = Number(s.unread).toLocaleString();
    }

    /* ─────────────────────── Composer ─────────────────────── */
    const picked = new Map(); // id -> {name, role}

    function audienceType() { return document.querySelector('input[name="audience"]:checked').value; }
    function selectedRoles() { return [...document.querySelectorAll('input[name="roles"]:checked')].map((c) => c.value); }

    function audienceForm(fd = new FormData()) {
        fd.append('audience_type', audienceType());
        selectedRoles().forEach((r) => fd.append('roles[]', r));
        [...picked.keys()].forEach((id) => fd.append('user_ids[]', id));
        return fd;
    }

    function syncAudiencePanels() {
        const t = audienceType();
        $('rolePanel').hidden = t !== 'role';
        $('usersPanel').hidden = t !== 'users';
        refreshReach();
    }

    const refreshReach = debounce(async () => {
        const t = audienceType();
        if ((t === 'role' && !selectedRoles().length) || (t === 'users' && !picked.size)) {
            setReach(0, t === 'role' ? 'Pick at least one role' : 'Pick at least one user');
            return;
        }
        try {
            const data = await api('count_audience', { method: 'POST', body: audienceForm() });
            setReach(data.count);
        } catch (e) { setReach(null); }
    }, 250);

    function setReach(n, hint) {
        const el = $('reach');
        el.classList.toggle('nm-reach--empty', !n);
        if (hint) { $('reachText').textContent = hint; return; }
        if (n === null) { $('reachText').textContent = 'Could not count recipients'; return; }
        $('reachText').innerHTML = n ? `Will reach <b>${n.toLocaleString()}</b> ${n === 1 ? 'user' : 'users'}` : 'No active users match';
    }

    function updatePreview() {
        const title = $('nmTitle').value.trim(), msg = $('nmMessage').value.trim();
        $('titleCount').textContent = $('nmTitle').value.length;
        $('msgCount').textContent = $('nmMessage').value.length;
        $('pvTitle').textContent = title || 'Your title appears here';
        $('pvMessage').textContent = msg || 'And your message right below it.';
    }

    function renderPicked() {
        const box = $('pickedUsers');
        if (!picked.size) { box.innerHTML = '<span class="nm-picked-empty">No users selected yet.</span>'; return; }
        box.innerHTML = [...picked.entries()].map(([id, u]) => `
            <span class="nm-picked-chip">
                <span class="nm-avatar nm-avatar--xs">${esc(initials(u.name))}</span>
                <span class="nm-picked-chip-name" title="${esc(u.name)}">${esc(u.name)}</span> <small>${esc(ROLE_LABELS[u.role] || u.role)}</small>
                <button type="button" aria-label="Remove ${esc(u.name)}" data-remove="${id}"><i class="bi bi-x"></i></button>
            </span>`).join('');
    }

    function initials(name) {
        return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
    }

    const searchUsers = debounce(async (q) => {
        const box = $('userResults');
        if (!q.trim()) { box.hidden = true; return; }
        const data = await api('search_users', { params: { q } });
        const users = data.users || [];
        box.innerHTML = users.length ? users.map((u) => `
            <button type="button" class="nm-combo-opt ${picked.has(u.id) ? 'picked' : ''}" role="option" data-user='${esc(JSON.stringify({ id: u.id, name: u.name, role: u.role }))}'>
                <span class="nm-avatar">${esc(initials(u.name))}</span>
                <span class="nm-combo-text">
                    <span class="nm-combo-name">${esc(u.name)}${u.business ? ` <small>· ${esc(u.business)}</small>` : ''}</span>
                    <span class="nm-combo-sub">@${esc(u.username)} · ${esc(u.email)}</span>
                </span>
                <span class="nm-role-tag nm-role-tag--${esc(u.role)}">${esc(ROLE_LABELS[u.role] || u.role)}</span>
                <i class="bi bi-check-circle-fill nm-combo-check"></i>
            </button>`).join('') : '<div class="nm-combo-empty">No matching active users</div>';
        box.hidden = false;
    }, 220);

    $('userSearch').addEventListener('input', (e) => searchUsers(e.target.value));
    $('userSearch').addEventListener('focus', (e) => { if (e.target.value.trim()) searchUsers(e.target.value); });
    $('userResults').addEventListener('click', (e) => {
        const opt = e.target.closest('.nm-combo-opt');
        if (!opt) return;
        const u = JSON.parse(opt.dataset.user);
        if (picked.has(u.id)) picked.delete(u.id); else picked.set(u.id, u);
        opt.classList.toggle('picked', picked.has(u.id));
        renderPicked();
        refreshReach();
    });
    $('pickedUsers').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove]');
        if (!btn) return;
        picked.delete(Number(btn.dataset.remove));
        renderPicked();
        refreshReach();
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.nm-combobox')) $('userResults').hidden = true;
    });

    document.querySelectorAll('input[name="audience"]').forEach((r) => r.addEventListener('change', syncAudiencePanels));
    document.querySelectorAll('input[name="roles"]').forEach((c) => c.addEventListener('change', refreshReach));
    ['nmTitle', 'nmMessage'].forEach((id) => $(id).addEventListener('input', updatePreview));

    window.resetComposer = function () {
        $('composerForm').reset();
        picked.clear();
        renderPicked();
        $('userSearch').value = '';
        syncAudiencePanels();
        updatePreview();
    };

    window.sendNotification = async function (e) {
        e.preventDefault();
        const t = audienceType();
        if (t === 'role' && !selectedRoles().length) return toast('Pick at least one role.', true);
        if (t === 'users' && !picked.size) return toast('Pick at least one user.', true);

        const btn = $('sendBtn');
        btn.classList.add('loading');
        btn.disabled = true;
        try {
            const fd = audienceForm();
            fd.append('title', $('nmTitle').value.trim());
            fd.append('message', $('nmMessage').value.trim());
            const data = await api('send', { method: 'POST', body: fd });
            if (data.status !== 'success') { toast(data.message || 'Could not send notification.', true); return; }
            toast(data.message);
            applyStats(data.stats);
            resetComposer();
            switchTab('sent');
        } catch (err) {
            toast('Network error — please try again.', true);
        } finally {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    };

    /* ─────────────────────── Feed ─────────────────────── */
    const feed = { tab: 'sent', offset: 0, search: '', category: 'all', loading: false, batches: new Map() };

    function switchTab(tab) {
        feed.tab = tab;
        document.querySelectorAll('.nm-tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
        $('systemFilters').hidden = tab !== 'system';
        $('feedSearch').placeholder = tab === 'sent' ? 'Search sent messages…' : 'Search by title, message or recipient…';
        loadFeed(true);
    }

    document.querySelectorAll('.nm-tab').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
    document.querySelectorAll('#systemFilters .nm-filter-chip').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('#systemFilters .nm-filter-chip').forEach((x) => x.classList.toggle('active', x === b));
        feed.category = b.dataset.cat;
        loadFeed(true);
    }));
    $('feedSearch').addEventListener('input', debounce((e) => { feed.search = e.target.value.trim(); loadFeed(true); }, 300));
    $('loadMoreBtn').addEventListener('click', () => loadFeed(false));

    function skeleton() {
        return Array.from({ length: 3 }, () => `
            <div class="nm-skel"><div class="nm-skel-lines"><span></span><span></span><span></span></div></div>`).join('');
    }

    async function loadFeed(reset) {
        if (feed.loading) return;
        feed.loading = true;
        const list = $('feedList');
        if (reset) { feed.offset = 0; feed.batches.clear(); list.innerHTML = skeleton(); }
        $('loadMoreBtn').disabled = true;

        try {
            const isSent = feed.tab === 'sent';
            const data = await api(isSent ? 'list_batches' : 'list_system', {
                params: { offset: feed.offset, search: feed.search, category: feed.category },
            });
            const rows = isSent ? (data.batches || []) : (data.items || []);
            if (reset) list.innerHTML = '';

            if (reset && !rows.length) {
                list.innerHTML = emptyState(isSent);
            } else {
                list.insertAdjacentHTML('beforeend', rows.map(isSent ? batchCard : systemRow).join(''));
                if (isSent) rows.forEach((b) => feed.batches.set(b.batch_id, b));
            }
            feed.offset += rows.length;
            $('loadMoreBtn').hidden = !data.has_more;
        } catch (err) {
            list.innerHTML = `<div class="nm-empty"><i class="bi bi-wifi-off"></i><h5>Couldn't load activity</h5><p>Check your connection and try again.</p></div>`;
        } finally {
            feed.loading = false;
            $('loadMoreBtn').disabled = false;
        }
    }

    function emptyState(isSent) {
        if (feed.search) return `<div class="nm-empty"><i class="bi bi-search"></i><h5>No results</h5><p>Nothing matches “${esc(feed.search)}”.</p></div>`;
        return isSent
            ? `<div class="nm-empty"><i class="bi bi-send"></i><h5>No messages sent yet</h5><p>Compose your first notification on the left — it lands in recipients' bells instantly.</p></div>`
            : `<div class="nm-empty"><i class="bi bi-cpu"></i><h5>No system activity</h5><p>Automatic alerts for bids, sessions and documents will appear here.</p></div>`;
    }

    function batchCard(b) {
        const pct = b.total ? Math.round(b.read_count / b.total * 100) : 0;
        return `
        <article class="nm-batch" data-batch="${esc(b.batch_id)}">
            <div class="nm-batch-top">
                <div class="nm-batch-main">
                    <div class="nm-batch-title">${esc(b.title)}</div>
                    <div class="nm-batch-msg">${esc(b.message)}</div>
                    <div class="nm-batch-meta">
                        <span class="nm-aud-pill"><i class="bi bi-people-fill"></i> ${esc(b.audience)}</span>
                        <span title="${esc(b.formatted_date)}"><i class="bi bi-clock"></i> ${esc(b.time_ago)}</span>
                        <span><i class="bi bi-person"></i> ${esc(b.sender)}</span>
                        ${b.link ? `<span class="nm-link-tag"><i class="bi bi-link-45deg"></i> ${esc(b.link)}</span>` : ''}
                    </div>
                </div>
                <div class="nm-batch-actions">
                    <button type="button" class="nm-icon-btn" title="View recipients" data-action="recipients"><i class="bi bi-people"></i></button>
                    <button type="button" class="nm-icon-btn nm-icon-btn--danger" title="Recall" data-action="recall"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div>
            </div>
            <button type="button" class="nm-progress-row" data-action="recipients" title="View recipients">
                <span class="nm-progress"><span class="nm-progress-bar" style="width:${pct}%"></span></span>
                <span class="nm-progress-lbl"><b>${b.read_count.toLocaleString()}</b> / ${b.total.toLocaleString()} read · ${pct}%</span>
            </button>
        </article>`;
    }

    function systemRow(n) {
        return `
        <div class="nm-sys ${n.is_read ? '' : 'nm-sys--unread'}">
            <div class="nm-sys-main">
                <div class="nm-sys-top">
                    <span class="nm-sys-title">${esc(n.title)}</span>
                    <span class="nm-sys-time" title="${esc(n.formatted_date)}">${esc(n.time_ago)}</span>
                </div>
                <div class="nm-sys-msg">${esc(n.message)}</div>
                <div class="nm-sys-meta">
                    <span class="nt-type-pill nt-tone--${esc(n.tone)}">${esc(n.label)}</span>
                    <span class="nm-sys-to"><i class="bi bi-arrow-return-right"></i> ${esc(n.recipient)} <small>${esc(ROLE_LABELS[n.recipient_role] || n.recipient_role)}</small></span>
                    <span class="nm-read-state ${n.is_read ? 'is-read' : ''}">${n.is_read ? '<i class="bi bi-check2-all"></i> Read' : '<i class="bi bi-circle-fill"></i> Unread'}</span>
                </div>
            </div>
        </div>`;
    }

    $('feedList').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const b = feed.batches.get(btn.closest('.nm-batch').dataset.batch);
        if (!b) return;
        if (btn.dataset.action === 'recipients') openDrawer(b);
        if (btn.dataset.action === 'recall') openRecall(b);
    });

    /* ─────────────────────── Recipients drawer ─────────────────────── */
    let drawerRows = [];
    let drawerFilter = 'all';

    async function openDrawer(b) {
        $('drawerTitle').textContent = b.title;
        $('drawerSub').textContent = `${b.audience} · ${b.formatted_date}`;
        $('drawerList').innerHTML = skeleton();
        setDrawerFilter('all', false);
        $('recipientsDrawer').classList.add('open');
        $('recipientsDrawer').setAttribute('aria-hidden', 'false');
        $('drawerBackdrop').classList.add('open');

        const data = await api('batch_recipients', { params: { batch_id: b.batch_id } });
        drawerRows = data.recipients || [];
        const read = drawerRows.filter((r) => r.is_read).length;
        const pct = drawerRows.length ? Math.round(read / drawerRows.length * 100) : 0;
        $('drawerRead').textContent = read;
        $('drawerUnread').textContent = drawerRows.length - read;
        $('drawerPct').textContent = pct + '%';
        $('drawerRing').style.setProperty('--pct', pct);
        renderDrawer();
    }

    function setDrawerFilter(f, render = true) {
        drawerFilter = f;
        document.querySelectorAll('[data-rfilter]').forEach((x) => x.classList.toggle('active', x.dataset.rfilter === f));
        if (render) renderDrawer();
    }
    document.querySelectorAll('[data-rfilter]').forEach((b) => b.addEventListener('click', () => setDrawerFilter(b.dataset.rfilter)));

    function renderDrawer() {
        const rows = drawerRows.filter((r) => drawerFilter === 'all' || (drawerFilter === 'read' ? r.is_read : !r.is_read));
        $('drawerList').innerHTML = rows.length ? rows.map((r) => `
            <div class="nm-recipient">
                <span class="nm-avatar">${esc(initials(r.name))}</span>
                <div class="nm-recipient-main">
                    <div class="nm-recipient-name">${esc(r.name)} <span class="nm-role-tag nm-role-tag--${esc(r.role)}">${esc(ROLE_LABELS[r.role] || r.role)}</span></div>
                    <div class="nm-recipient-sub">${r.is_read ? `Read ${esc(r.read_at)}` : '@' + esc(r.username)}</div>
                </div>
                <span class="nm-dot ${r.is_read ? 'nm-dot--read' : ''}" title="${r.is_read ? 'Read' : 'Unread'}"></span>
            </div>`).join('') : '<div class="nm-empty nm-empty--sm"><i class="bi bi-people"></i><p>No recipients in this view.</p></div>';
    }

    window.closeDrawer = function () {
        $('recipientsDrawer').classList.remove('open');
        $('recipientsDrawer').setAttribute('aria-hidden', 'true');
        $('drawerBackdrop').classList.remove('open');
    };

    /* ─────────────────────── Recall ─────────────────────── */
    let recallTarget = null;

    function openRecall(b) {
        recallTarget = b;
        $('recallPreview').innerHTML = `<b>${esc(b.title)}</b><span>${esc(b.audience)} · ${b.total.toLocaleString()} recipients</span>`;
        $('recallDialog').classList.add('open');
    }
    window.closeRecall = function () { $('recallDialog').classList.remove('open'); recallTarget = null; };

    $('recallConfirmBtn').addEventListener('click', async () => {
        if (!recallTarget) return;
        const btn = $('recallConfirmBtn');
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('batch_id', recallTarget.batch_id);
            const data = await api('recall_batch', { method: 'POST', body: fd });
            if (data.status !== 'success') { toast(data.message || 'Could not recall notification.', true); return; }
            const card = document.querySelector(`.nm-batch[data-batch="${recallTarget.batch_id}"]`);
            if (card) { card.classList.add('removing'); setTimeout(() => { card.remove(); if (!$('feedList').children.length) $('feedList').innerHTML = emptyState(true); }, 250); }
            feed.batches.delete(recallTarget.batch_id);
            feed.offset = Math.max(0, feed.offset - 1);
            applyStats(data.stats);
            toast(data.message);
            closeRecall();
        } finally {
            btn.disabled = false;
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        closeDrawer();
        closeRecall();
    });

    // Init
    syncAudiencePanels();
    updatePreview();
    loadFeed(true);
})();
</script>

</body>
</html>
