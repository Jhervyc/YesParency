<?php
// List fragment for procurement.php — rendered inside #procListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if (mysqli_num_rows($result) > 0): ?>
<div class="table-scroll">
    <table class="proc-table proc-list-table proc-cards">
        <thead>
            <tr>
                <th class="col-ref-narrow">SLSU Ref</th>
                <th>Title</th>
                <th class="col-mode">Mode</th>
                <th class="col-abc">ABC</th>
                <th class="col-status">Status</th>
                <th class="col-actions-right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)):
            $cs = strtolower($row['status']);
            $stClass = match($cs) { 'open'=>'status-open','closed'=>'status-closed','awarded'=>'status-awarded','opened'=>'status-awarded', default=>'status-draft' };
        ?>
        <tr>
            <td class="proc-ref-cell card-head">
                <i class="bi bi-hash proc-ref-icon"></i>
                <?= htmlspecialchars($row['slsu_ref_no'] ?? '—') ?>
            </td>
            <td class="proc-title-cell">
                <?php if ($cs !== 'draft'): ?>
                    <a href="procurement-view.php?id=<?= $row['id'] ?>"><?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars(mb_strimwidth($row['title'], 0, 65, '…')) ?>
                <?php endif; ?>
                <div class="proc-title-sub">
                    <i class="bi bi-briefcase"></i> <?= htmlspecialchars($row['procurement_mode'] ?? '—') ?>
                </div>
            </td>
            <td class="col-mode card-hide">
                <span class="proc-mode-tag"><?= htmlspecialchars($row['procurement_mode'] ?? '—') ?></span>
            </td>
            <td class="proc-abc-cell col-abc" data-label="ABC">₱<?= number_format($row['abc'], 2) ?></td>
            <td class="col-status card-badge">
                <span class="proc-status-pill <?= $stClass ?>">
                    <i class="bi bi-circle-fill status-dot-icon"></i> <?= ucfirst($cs) ?>
                </span>
            </td>
            <td class="text-right proc-actions-cell card-actions">
                <div class="actions-wrap-end">
                    <?php if ($cs !== 'draft'): ?>
                        <a href="procurement-view.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-view">
                            <i class="bi bi-eye"></i> View
                        </a>
                    <?php endif; ?>
                    <?php if ($cs === 'draft'): ?>
                        <a href="review_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn proc-action-btn--review-alt">
                            <i class="bi bi-eye"></i> Review
                        </a>
                        <a href="manage_lots.php?id=<?= $row['id'] ?>" class="proc-action-btn btn-open">
                            <i class="bi bi-sliders"></i> Manage
                        </a>
                    <?php endif; ?>
                    <button type="button" class="proc-action-btn proc-action-btn--delete"
                            data-delete-id="<?= $row['id'] ?>"
                            data-delete-title="<?= htmlspecialchars($row['title']) ?>">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="table-foot">
    <div>
        Showing <strong><?= min($total_shown, $offset + 1) ?></strong>–<strong><?= min($total_shown, $offset + $per_page) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> procurement<?= $total_shown != 1 ? 's' : '' ?>
        <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'status' => $status_filter !== 'all' ? $status_filter : null,
    ]) ?>
</div>

<?php else: ?>
<div class="proc-empty-state">
    <i class="bi bi-folder2-open proc-empty-icon"></i>
    <div class="proc-empty-title">
        No <?= $status_filter !== 'all' ? htmlspecialchars($status_filter) . ' ' : '' ?>procurements found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.
    </div>
</div>
<?php endif; ?>
