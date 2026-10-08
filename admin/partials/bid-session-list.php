<?php
// List fragment for bid-session-list.php — rendered inside #sessionListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
<div class="results-empty">
    <i class="bi bi-collection results-empty-icon"></i>
    <div class="results-empty-title">No <?= $filter !== 'all' ? htmlspecialchars($filter) . ' ' : '' ?>sessions found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
</div>
<?php else: ?>
<div class="table-scroll">
    <table class="proc-table proc-cards">
        <thead>
            <tr>
                <th class="col-ref-th">SLSU Ref</th>
                <th>Procurement</th>
                <th>Mode</th>
                <th>ABC</th>
                <th>Started</th>
                <th>Invited</th>
                <th>Status</th>
                <th class="col-actions-th">Actions</th>
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
                <i class="bi bi-hash hash-icon"></i>
                <?= htmlspecialchars($row['slsu_ref_no']) ?>
            </td>
            <td class="proc-title-cell">
                <a href="view_procurement.php?id=<?= $row['proc_id'] ?>">
                    <?= htmlspecialchars(mb_strimwidth($row['proc_title'], 0, 60, '…')) ?>
                </a>
                <div class="proc-title-specs">
                    <i class="bi bi-inbox"></i> <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count'] != 1 ? 's' : '' ?>
                    &nbsp;·&nbsp;
                    <i class="bi bi-broadcast stream-icon-tiny"></i>
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
                    <span class="deadline-tba">—</span>
                <?php endif; ?>
            </td>
            <td class="invited-cell" data-label="Invited">
                <i class="bi bi-people invited-icon"></i>
                <?= (int)$row['invited_count'] ?>
            </td>
            <td class="card-badge">
                <span class="session-pill <?= $pill_class ?>">
                    <i class="bi <?= $pill_icon ?> status-icon-tiny"></i>
                    <?= $pill_label ?>
                </span>
            </td>
            <td class="col-actions-th card-actions">
                <?php if ($st === 'scheduled'): ?>
                    <?php if ($can_manage): ?>
                    <form method="POST" action="bid_opening.php" id="openNowForm-<?= $row['session_id'] ?>">
                        <input type="hidden" name="session_id" value="<?= $row['session_id'] ?>">
                        <input type="hidden" name="open_now" value="1">
                        <button type="button" class="proc-action-btn btn-schedule"
                            onclick="openNowModal(<?= $row['session_id'] ?>, '<?= addslashes(htmlspecialchars($row['proc_title'])) ?>', '<?= addslashes(htmlspecialchars($row['slsu_ref_no'])) ?>', '<?= addslashes(htmlspecialchars($row['stream_path'])) ?>', '<?= addslashes(mediamtx_url($row['stream_path'])) ?>')">
                            <i class="bi bi-play-fill"></i> Open Now
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="proc-action-btn proc-action-btn--disabled">
                        <i class="bi bi-play-fill"></i> Open Now
                    </span>
                    <?php endif; ?>
                <?php elseif ($is_live_status): ?>
                    <?php if ($can_manage): ?>
                    <a href="bid_session.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-open">
                        <i class="bi bi-broadcast"></i> Manage
                    </a>
                    <?php else: ?>
                    <a href="bid_session.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-view">
                        <i class="bi bi-box-arrow-in-right"></i> Join
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                <a href="bid_session.php?session=<?= $row['session_id'] ?>" class="proc-action-btn btn-view">
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
