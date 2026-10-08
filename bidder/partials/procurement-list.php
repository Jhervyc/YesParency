<?php
// List fragment for procurement.php — rendered inside #bidderProcListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
    <div class="results-empty">
        <i class="bi bi-search results-empty-icon"></i>
        <p class="results-empty-title">
            No <?= in_array($status_filter, ['open', 'closed'], true) ? $status_filter . ' ' : '' ?>procurements found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
        </p>
        <p class="results-empty-desc">
            <?= $mode_filter !== 'all' && $mode_filter !== '' ? 'Mode: ' . htmlspecialchars($mode_filter) . '. ' : '' ?>Try a different search or filter.
        </p>
        <a href="procurement.php" class="vp-back-link">
            Reset Filters
        </a>
    </div>
<?php else: ?>
    <div class="table-scroll">
        <table class="proc-table proc-cards">
            <thead>
                <tr>
                    <th class="col-ref-th">SLSU Ref</th>
                    <th>Procurement Project Title</th>
                    <th>Procurement Mode</th>
                    <th>Approved Budget (ABC)</th>
                    <th>Deadline / Urgency</th>
                    <th class="col-actions-th">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $procs_result->fetch_assoc()):
                    $hasBid = !empty($row['my_bid_id']);
                    $isUrgent = false;
                    $diff_text = 'TBD';

                    if ($row['closing_date']) {
                        $dl_time   = strtotime($row['closing_date']);
                        $diff_days = ceil(($dl_time - time()) / 86400);
                        if ($diff_days <= 3 && $diff_days >= 0) $isUrgent = true;

                        if ($diff_days > 3) $diff_text = $diff_days . ' days left';
                        elseif ($diff_days > 0) $diff_text = $diff_days . ' day(s) left';
                        elseif ($diff_days === 0) $diff_text = 'Closing today';
                        else $diff_text = 'Closed';
                    }
                ?>
                <tr>
                    <!-- SLSU Ref -->
                    <td class="proc-ref-cell card-head">
                        <span><?= htmlspecialchars($row['slsu_ref_no'] ?? 'N/A') ?></span>
                        <?php if ($hasBid): ?>
                            <div class="bidded-pill-wrap">
                                <span class="bidded-pill">
                                    <i class="bi bi-check-circle-fill"></i> Bidded
                                </span>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- Title & Specs -->
                    <td class="proc-title-cell">
                        <a href="view_procurement.php?id=<?= $row['id'] ?>" title="<?= htmlspecialchars($row['title']) ?>">
                            <?= htmlspecialchars($row['title']) ?>
                        </a>
                        <div class="proc-title-specs">
                            <span><i class="bi bi-layers"></i> <?= (int)$row['lot_count'] ?> lot(s)</span>
                            <span>·</span>
                            <span><i class="bi bi-file-earmark-text"></i> <?= (int)$row['doc_count'] ?> doc(s)</span>
                            <?php if (!empty($row['opening_date'])): ?>
                                <span>·</span>
                                <span><i class="bi bi-door-open"></i> Opens: <?= date('M j, Y', strtotime($row['opening_date'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Mode -->
                    <td data-label="Mode">
                        <span class="proc-mode-tag">
                            <?= htmlspecialchars($row['procurement_mode'] ?? 'Public Bidding') ?>
                        </span>
                    </td>

                    <!-- ABC -->
                    <td class="proc-abc-cell" data-label="ABC">
                        ₱<?= number_format((float)$row['abc'], 2) ?>
                    </td>

                    <!-- Deadline -->
                    <td class="proc-deadline-cell" data-label="Closes">
                        <strong><?= $row['closing_date'] ? date('M j, Y', strtotime($row['closing_date'])) : 'TBD' ?></strong>
                        <?php if ($row['closing_date']): ?>
                            <span class="rank-urgency-pill <?= $isUrgent ? 'urgent' : 'normal' ?>">
                                <i class="bi bi-clock"></i> <?= $diff_text ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td class="proc-actions-cell card-actions">
                        <div class="proc-actions-row">
                            <?php if (!$hasBid && $row['status'] === 'open'): ?>
                                <?php if ($bidder_doc_status['is_valid']): ?>
                                    <a href="submit_bid.php?procurement_id=<?= $row['id'] ?>" class="proc-action-btn proc-action-btn--gold">
                                        <i class="bi bi-send-fill"></i> Bid
                                    </a>
                                <?php else: ?>
                                    <a href="settings.php?tab=documents" class="proc-action-btn proc-action-btn--locked" title="Bidding locked: Document(s) expired or missing. Click to update in Settings.">
                                        <i class="bi bi-lock-fill"></i> Bid
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn secondary">
                                <i class="bi bi-eye"></i> View
                            </a>
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
        of <strong><?= number_format($total_shown) ?></strong> procurement<?= $total_shown !== 1 ? 's' : '' ?>
        <?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'status' => $status_filter !== 'open' ? $status_filter : null,
        'mode'   => $mode_filter !== 'all' ? $mode_filter : null,
        'sort'   => $sort !== 'closing_asc' ? $sort : null,
    ]) ?>
</div>

<?php endif; ?>
