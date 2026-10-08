<?php
// List fragment for bid_submissions.php — rendered inside #submissionListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if (empty($procurements)): ?>
<div class="results-empty">
    <i class="bi bi-inbox results-empty-icon"></i>
    <div class="results-empty-title">
        <?php
        $modeLabel = ['public_bidding' => 'Public Bidding', 'svp' => 'Small Value Procurement', 'shopping' => 'Shopping'][$mode_filter] ?? '';
        $forSearch = $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '';
        $inMode    = $modeLabel !== '' ? ' in ' . $modeLabel : '';
        ?>
        <?php if ($filter==='pending'): ?>No procurements with pending bids<?= $inMode ?><?= $forSearch ?>.
        <?php elseif ($filter==='has_bids'): ?>No procurements with submitted bids<?= $inMode ?><?= $forSearch ?>.
        <?php else: ?>No open procurements<?= $inMode ?><?= $forSearch ?>.
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="table-scroll">
    <table class="proc-table proc-cards">
        <thead>
            <tr>
                <th class="col-ref-th">SLSU Ref</th>
                <th>Procurement Title</th>
                <th class="col-abc">Total Bids</th>
                <th class="col-status">Status</th>
                <th class="col-actions-th">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($procurements as $proc):
            $hasPending  = intval($proc['pending_bids']) > 0;
            $totalBids   = intval($proc['total_bids']);
            $pendingBids = intval($proc['pending_bids']);
        ?>
        <tr>
            <td class="proc-ref-cell card-head">
                <i class="bi bi-hash hash-icon"></i>
                <?= htmlspecialchars($proc['slsu_ref_no'] ?? '—') ?>
            </td>
            <td class="proc-title-cell">
                <?= htmlspecialchars(mb_strimwidth($proc['procurement_title'], 0, 65, '…')) ?>
                <?php if ($hasPending): ?>
                <div class="pending-note">
                    <i class="bi bi-hourglass-split"></i> <?= $pendingBids ?> pending review
                </div>
                <?php endif; ?>
            </td>
            <td class="proc-abc-cell col-abc" data-label="Bids"><?= $totalBids ?></td>
            <td class="col-status card-badge">
                <span class="proc-status-pill status-open">
                    <i class="bi bi-circle-fill status-dot-tiny"></i> Open
                </span>
            </td>
            <td class="col-actions-th card-actions">
                <a href="bid-submission-view.php?id=<?= (int)$proc['procurement_id'] ?>" class="proc-action-btn btn-view">
                    <i class="bi bi-eye"></i> View
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="table-foot">
    <div>
        Showing <strong><?= min($total_shown, $offset + 1) ?></strong>–<strong><?= min($total_shown, $offset + $per_page) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> open procurement<?= $total_shown !== 1 ? 's' : '' ?>
        <?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search'      => $search,
        'filter'      => $filter !== 'all' ? $filter : null,
        'mode_filter' => $mode_filter !== 'all' ? $mode_filter : null,
    ]) ?>
</div>
<?php endif; ?>
