<?php
// List fragment for bid-session-list.php — rendered inside #sessionListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
<div class="proc-empty-state">
    <i class="bi bi-collection proc-empty-icon"></i>
    <div class="proc-empty-title">No <?= $filter !== 'all' ? htmlspecialchars($filter) . ' ' : '' ?>sessions found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
</div>
<?php else: ?>
<div class="table-scroll-x">
    <table class="proc-table proc-cards">
        <thead>
            <tr>
                <th class="col-ref-narrow">SLSU Ref</th>
                <th>Procurement</th>
                <th>Mode</th>
                <th>ABC</th>
                <th>Started</th>
                <th>Invited</th>
                <th>Status</th>
                <th class="col-actions-right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = $sessions->fetch_assoc()):
            $st = $row['session_status'];
            $is_live_status = in_array($st, ['eligibility', 'financial', 'offered']);

            if ($is_live_status) {
                $pill_class = 'pill-live';
                $pill_icon  = 'bi-broadcast';
                $pill_label = ucfirst($st) . ' Phase';
            } elseif ($st === 'scheduled') {
                $pill_class = 'pill-scheduled';
                $pill_icon  = 'bi-clock';
                $pill_label = 'Scheduled';
            } else {
                $pill_class = 'pill-ended';
                $pill_icon  = 'bi-check2-circle';
                $pill_label = 'Ended';
            }
        ?>
        <tr>
            <td class="proc-ref-cell card-head">
                <i class="bi bi-hash proc-ref-icon"></i>
                <?= htmlspecialchars($row['slsu_ref_no']) ?>
            </td>
            <td class="proc-title-cell">
                <a href="view_procurement.php?id=<?= $row['proc_id'] ?>">
                    <?= htmlspecialchars(mb_strimwidth($row['proc_title'], 0, 60, '…')) ?>
                </a>
                <div class="proc-title-sub">
                    <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                    &nbsp;·&nbsp;
                    <i class="bi bi-broadcast stream-icon"></i>
                    <?= htmlspecialchars($row['stream_path']) ?>
                </div>
            </td>
            <td>
                <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
            </td>
            <td class="proc-abc-cell" data-label="ABC">₱<?= number_format((float)$row['abc'], 2) ?></td>
            <td class="proc-deadline-cell" data-label="Started">
                <?php if ($row['started_at']): ?>
                    <strong><?= date('M j, Y', strtotime($row['started_at'])) ?></strong>
                    <?= date('g:i A', strtotime($row['started_at'])) ?>
                <?php else: ?>
                    <span class="clr-placeholder">—</span>
                <?php endif; ?>
            </td>
            <td class="invited-cell" data-label="Invited">
                <i class="bi bi-people invited-icon"></i>
                <?= (int)$row['invited_count'] ?>
            </td>
            <td class="card-badge">
                <span class="session-pill <?= $pill_class ?>">
                    <i class="bi <?= $pill_icon ?> session-pill-icon"></i>
                    <?= $pill_label ?>
                </span>
            </td>
            <td class="text-right card-actions">
                <?php if ($st === 'scheduled'): ?>
                    <span class="proc-action-btn proc-action-btn--upcoming">
                        <i class="bi bi-clock"></i> Upcoming
                    </span>
                <?php elseif ($is_live_status): ?>
                    <a href="../live.php" class="proc-action-btn btn-open">
                        <i class="bi bi-play-circle-fill"></i> Watch Live
                    </a>
                <?php else: ?>
                <a href="view_procurement.php?id=<?= $row['proc_id'] ?>" class="proc-action-btn btn-view">
                    <i class="bi bi-eye"></i> View
                </a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="table-foot">
    <div>
        Showing <?= min($total_shown, $offset + 1) ?>–<?= min($total_shown, $offset + $per_page) ?>
        of <?= number_format($total_shown) ?> session<?= $total_shown != 1 ? 's' : '' ?>
        <?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'filter' => $filter !== 'all' ? $filter : null,
    ]) ?>
</div>
<?php endif; ?>
