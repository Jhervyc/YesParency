<?php
// List fragment for user-role-management.php — rendered inside #userListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<!-- User rows -->
<?php if ($total_shown === 0): ?>
    <div class="empty-state empty-state--lg">
        <i class="bi bi-people"></i>
        <p>No <?= ['user' => 'users', 'admin' => 'admins', 'bidder' => 'bidders'][$role_filter] ?? 'users' ?> found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
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
            <div class="ap2-avatar ap2-avatar--<?= $user['role'] ?> avatar-cover">
                <?php if (!empty($userAvatarUrl)): ?>
                    <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>">
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
        </div>

        <div class="ap2-action-cell">
            <span class="ap2-badge <?= $roleClass ?>"><?= strtoupper($user['role']) ?></span>
        <?php if ($isSelf): ?>
            <span class="ap2-self-label">You</span>
        <?php else: ?>

            <button type="button" class="ap2-action-btn ap2-delete"
                    onclick="openConfirm('delete', <?= $user['user_id'] ?>, '<?= $safeFullName ?>', '<?= $safeUsername ?>')">
                <i class="bi bi-trash3"></i> Delete
            </button>

        <?php endif; ?>
        </div>

    </div>
    <?php endwhile; ?>

<div class="ap2-card-foot ap2-card-foot--flex">
    <div>
        Showing <strong><?= min($total_shown, $offset+1) ?></strong>–<strong><?= min($total_shown, $offset+$per_page) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> result<?= $total_shown !== 1 ? 's' : '' ?>
        <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'role'   => $role_filter !== 'all' ? $role_filter : null,
    ]) ?>
</div>
<?php endif; ?>
