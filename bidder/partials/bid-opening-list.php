<?php
// List fragment for bid_opening.php — rendered inside #openingListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
<div class="proc-empty-state">
    <i class="bi bi-folder2-open proc-empty-icon"></i>
    <div class="proc-empty-title">No open procurements found<?= $mode_filter !== '' && $mode_filter !== 'all' ? ' in ' . htmlspecialchars($mode_filter) : '' ?><?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
</div>
<?php else: ?>
<div class="table-scroll-x">
    <table class="proc-table proc-cards">
        <thead>
            <tr>
                <th class="col-ref-narrow">SLSU Ref</th>
                <th>Title</th>
                <th class="col-mode">Mode</th>
                <th class="col-abc">ABC</th>
                <th class="col-opening">Opening Date</th>
                <th class="col-status">Status</th>
                <th class="col-actions-right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = $procs->fetch_assoc()):
            $st      = strtolower($row['status'] ?? 'open');
            $stClass = match($st) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded', default=>'status-draft' };
            $isScheduled = in_array((int)$row['id'], $scheduled_proc_ids);
        ?>
        <tr>
            <td class="proc-ref-cell card-head">
                <i class="bi bi-hash proc-ref-icon"></i>
                <?= htmlspecialchars($row['slsu_ref_no']) ?>
            </td>
            <td class="proc-title-cell">
                <a href="view_procurement.php?id=<?= $row['id'] ?>">
                    <?= htmlspecialchars(mb_strimwidth($row['title'],0,65,'…')) ?>
                </a>
                <div class="proc-title-sub">
                    <i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot<?= $row['lot_count']!=1?'s':'' ?>
                    &nbsp;·&nbsp;
                    <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count']!=1?'s':'' ?>
                </div>
            </td>
            <td class="col-mode" data-label="Mode">
                <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode']??'—') ?></span>
            </td>
            <td class="proc-abc-cell col-abc" data-label="ABC">₱<?= number_format((float)$row['abc'],2) ?></td>
            <td class="proc-deadline-cell col-opening" data-label="Opening">
                <?php if (!empty($row['opening_date'])): ?>
                    <strong><?= date('M j, Y', strtotime($row['opening_date'])) ?></strong>
                    <?= date('g:i A', strtotime($row['opening_date'])) ?>
                <?php else: ?><span class="clr-placeholder">TBA</span><?php endif; ?>
            </td>
            <td class="col-status card-badge">
                <span class="proc-status-pill <?= $stClass ?>">
                    <i class="bi bi-circle-fill status-dot-icon"></i> <?= ucfirst($st) ?>
                </span>
            </td>
            <td class="text-right card-actions">
                <div class="actions-wrap-end">
                    <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                        <i class="bi bi-eye"></i> View
                    </a>
                    <?php if ($isScheduled): ?>
                    <a href="bid-session-list.php?filter=scheduled" class="proc-action-btn proc-action-btn--scheduled">
                        <i class="bi bi-calendar-check"></i> Scheduled
                    </a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="table-foot">
    <div>
        Showing <?= min($total_shown,$offset+1) ?>–<?= min($total_shown,$offset+$per_page) ?>
        of <?= number_format($total_shown) ?> procurement<?= $total_shown!=1?'s':'' ?>
        <?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'mode'   => $mode_filter !== 'all' ? $mode_filter : null,
        'sort'   => $sort !== 'closing_asc' ? $sort : null,
    ]) ?>
</div>
<?php endif; ?>
