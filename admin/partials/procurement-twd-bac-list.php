<?php
// List fragment for procurement-twd-bac.php — rendered inside #twdListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<!-- ── Main Table List ── -->
<?php if ($total_shown === 0): ?>
    <div class="results-empty">
        <i class="bi bi-search results-empty-icon"></i>
        <p class="results-empty-title">
            No <?= in_array($status_filter, ['open', 'closed'], true) ? $status_filter . ' ' : '' ?>procurements found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
        </p>
        <p class="results-empty-desc">
            <?= $mode_filter !== 'all' && $mode_filter !== '' ? 'Mode: ' . htmlspecialchars($mode_filter) . '. ' : '' ?>Try a different search or filter.
        </p>
        <a href="procurement-twd-bac.php" class="vp-back-link">
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
                    $st = strtolower($row['status'] ?? 'open');
                    $isOpen = ($st === 'open');
                    $daysLeft = null;
                    if ($row['closing_date']) {
                        $daysLeft = ceil((strtotime($row['closing_date']) - time()) / 86400);
                    }
                ?>
                    <tr>
                        <td class="proc-ref-cell card-head">
                            <i class="bi bi-hash"></i> <?= htmlspecialchars($row['slsu_ref_no'] ?: 'N/A') ?>
                        </td>

                        <td class="proc-title-cell">
                            <a href="view_procurement.php?id=<?= $row['id'] ?>">
                                <?= htmlspecialchars($row['title']) ?>
                            </a>
                        </td>

                        <td data-label="Mode">
                            <span class="proc-mode-tag">
                                <?= htmlspecialchars($row['procurement_mode'] ?: 'Public Bidding') ?>
                            </span>
                        </td>

                        <td class="proc-abc-cell" data-label="ABC">
                            ₱<?= number_format((float)$row['abc'], 2) ?>
                        </td>

                        <td class="proc-deadline-cell" data-label="Closes">
                            <?php if ($row['closing_date']): ?>
                                <strong><?= date('M j, Y · g:i A', strtotime($row['closing_date'])) ?></strong>
                                <?php if ($isOpen && $daysLeft !== null): ?>
                                    <?php if ($daysLeft < 0): ?>
                                        <span class="deadline-status deadline-status--closed">Submission Closed</span>
                                    <?php elseif ($daysLeft == 0): ?>
                                        <span class="deadline-status deadline-status--today">● Closes Today</span>
                                    <?php elseif ($daysLeft <= 3): ?>
                                        <span class="deadline-status deadline-status--soon">▲ <?= $daysLeft ?> days left</span>
                                    <?php else: ?>
                                        <span class="deadline-status deadline-status--normal"><?= $daysLeft ?> days remaining</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="deadline-tba">To be announced</span>
                            <?php endif; ?>
                        </td>

                        <td class="proc-actions-cell card-actions">
                            <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn">
                                <i class="bi bi-eye"></i> View
                            </a>
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
