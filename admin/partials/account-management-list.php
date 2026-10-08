<?php
// List fragment for account-management.php — rendered inside #accountListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
    <div class="empty-state empty-state--lg">
        <i class="bi bi-people"></i>
        <p>No <?= $status_filter !== 'all' ? htmlspecialchars($status_filter) . ' ' : '' ?>bidders found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
    </div>
<?php else: ?>
    <?php while ($row = $result->fetch_assoc()):
        $appSt    = $row['application_status'] ?? null;
        $isPending = $appSt === 'pending';
        $badgeClass = match($appSt) {
            'approved' => 'ap2-status-badge--approved',
            'pending'  => 'ap2-status-badge--pending',
            'rejected' => 'ap2-status-badge--rejected',
            default    => 'ap2-status-badge--default',
        };
        $businessName = $row['business_name'] ?? 'No business profile';
        $initials     = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1));
        $avatarUrl    = !empty($row['profile_picture_url']) ? '../'.ltrim($row['profile_picture_url'],'/') : '';
        $safeFullName = htmlspecialchars(addslashes($row['firstname'].' '.$row['lastname']));
        $safeUsername = htmlspecialchars(addslashes($row['username']));
    ?>
    <div class="ap2-user-row <?= $isPending ? 'bsv-row-pending' : '' ?>">

        <div class="ap2-who-cell">
            <div class="ap2-avatar ap2-avatar--<?= $row['role'] ?> avatar-cover">
                <?php if (!empty($avatarUrl)): ?>
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>">
                <?php else: ?>
                    <?= htmlspecialchars($initials) ?>
                <?php endif; ?>
            </div>
            <div class="ap2-user-text">
                <div class="ap2-user-name"><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></div>
                <div class="ap2-user-sub">
                    @<?= htmlspecialchars($row['username']) ?>
                    &nbsp;·&nbsp;
                    <i class="bi bi-building fz-10"></i> <?= htmlspecialchars($businessName) ?>
                    &nbsp;·&nbsp;
                    <?= htmlspecialchars($row['email']) ?>
                </div>
            </div>
        </div>

        <div class="ap2-action-cell">
            <span class="ap2-badge <?= $badgeClass ?>">
                <?= strtoupper($appSt ?? 'UNKNOWN') ?>
            </span>
            <a href="bidder-profile.php?id=<?= $row['user_id'] ?>" class="proc-action-btn btn-view no-underline">
                <i class="bi bi-eye"></i> View Profile
            </a>
        </div>

    </div>
    <?php endwhile; ?>

<div class="ap2-card-foot ap2-card-foot--flex">
    <div>
        Showing <strong><?= min($total_shown, $offset+1) ?></strong>–<strong><?= min($total_shown, $offset+$per_page) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> bidder<?= $total_shown != 1 ? 's' : '' ?>
        <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'status' => $status_filter !== 'all' ? $status_filter : null,
    ]) ?>
</div>
<?php endif; ?>
