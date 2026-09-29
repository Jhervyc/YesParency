<?php
/**
 * Dashboard widget: the current user's latest notifications.
 * Expects $widget_notifs = notif_latest($conn, $userId, 3).
 * Clicking an item reuses the topbar bell controller (openNotifObject).
 */
$widget_notifs = $widget_notifs ?? [];
?>
<div class="nw-list">
    <?php if (empty($widget_notifs)): ?>
        <div class="nw-empty">
            <i class="bi bi-bell-slash"></i>
            <span>You're all caught up — no notifications yet.</span>
        </div>
    <?php else: foreach ($widget_notifs as $n): ?>
        <button type="button" class="nw-item <?= $n['is_read'] ? '' : 'unread' ?>"
                data-notif="<?= htmlspecialchars(json_encode($n), ENT_QUOTES) ?>"
                onclick="openWidgetNotif(this)">
            <span class="nw-body">
                <span class="nw-top">
                    <span class="nw-title"><?= htmlspecialchars($n['title']) ?></span>
                    <span class="nw-time"><?= htmlspecialchars($n['time_ago']) ?></span>
                </span>
                <span class="nw-msg"><?= htmlspecialchars($n['message']) ?></span>
                <span class="nw-meta">
                    <span class="nt-type-pill nt-tone--<?= htmlspecialchars($n['tone']) ?>"><?= htmlspecialchars($n['label']) ?></span>
                    <span class="nw-actor"><?= htmlspecialchars($n['actor_name']) ?></span>
                </span>
            </span>
            <?php if (!$n['is_read']): ?><span class="notif-unread-indicator"></span><?php endif; ?>
        </button>
    <?php endforeach; endif; ?>
</div>
<script>
window.openWidgetNotif = window.openWidgetNotif || function (el) {
    const n = JSON.parse(el.dataset.notif);
    openNotifObject(n, function () {
        el.classList.remove('unread');
        const dot = el.querySelector('.notif-unread-indicator');
        if (dot) dot.remove();
    });
};
</script>
