<?php
// List fragment for bid_schedule.php — rendered inside #scheduleListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if (empty($procurements)): ?>
<div class="tbl-empty">
    <i class="bi bi-calendar-x"></i>
    <p>No <?= $filter !== 'all' ? htmlspecialchars($filter) . ' ' : '' ?>procurements found<?= $filter_mode !== '' ? ' in ' . htmlspecialchars($filter_mode) : '' ?><?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
</div>
<?php else: ?>
<div class="table-scroll">
    <table class="proc-table sched-cards">
        <thead>
            <tr>
                <th class="col-ref-th">SLSU Ref</th>
                <th>Project Title</th>
                <th>Mode</th>
                <th>ABC</th>
                <th>Opening Date</th>
                <th>Submission Cutoff</th>
                <th>Status</th>
                <th class="ta-right">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($procurements as $p):
            $ps = strtolower($p['status'] ?? 'open');
            $pillClass = match($ps) {
                'open'    => 'spill-open',
                'closed'  => 'spill-closed',
                'awarded' => 'spill-awarded',
                'opened'  => 'spill-awarded',
                default   => 'spill-other',
            };
            $pillIcon = match($ps) {
                'open'    => 'bi-door-open',
                'closed'  => 'bi-lock',
                'awarded' => 'bi-trophy',
                'opened'  => 'bi-unlock',
                default   => 'bi-circle',
            };
        ?>
        <tr>
            <td class="col-ref card-head"><i class="bi bi-hash"></i> <?= htmlspecialchars($p['slsu_ref_no'] ?: '—') ?></td>
            <td class="col-title">
                <a href="bid_view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars(mb_strimwidth($p['title'], 0, 65, '…')) ?></a>
                <?php if ((int)$p['lots_count'] > 0): ?>
                <div class="col-title-sub">
                    <i class="bi bi-boxes"></i> <?= (int)$p['lots_count'] ?> lot<?= $p['lots_count'] > 1 ? 's' : '' ?>
                </div>
                <?php endif; ?>
            </td>
            <td data-label="Mode"><span class="col-mode"><?= htmlspecialchars($p['procurement_mode'] ?: '—') ?></span></td>
            <td class="col-abc" data-label="ABC">₱<?= number_format((float)$p['abc'], 2) ?></td>
            <td class="col-date" data-label="Opening">
                <?php if ($p['opening_date']): ?>
                    <strong><?= date('M j, Y', strtotime($p['opening_date'])) ?></strong>
                    <?= date('g:i A', strtotime($p['opening_date'])) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="col-date" data-label="Cutoff">
                <?php if ($p['closing_date']): ?>
                    <strong><?= date('M j, Y', strtotime($p['closing_date'])) ?></strong>
                    <?= date('g:i A', strtotime($p['closing_date'])) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="card-badge">
                <span class="spill <?= $pillClass ?>">
                    <i class="bi <?= $pillIcon ?>"></i>
                    <?= ucfirst($ps) ?>
                </span>
            </td>
            <td class="ta-right card-actions">
                <a href="bid_view.php?id=<?= (int)$p['id'] ?>" class="act-btn act-btn-view">
                    <i class="bi bi-eye"></i> View
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="tbl-foot">
    <div>
        Showing <?= min($total_count, $offset+1) ?>–<?= min($total_count, $offset+$per_page) ?>
        of <?= number_format($total_count) ?> project<?= $total_count != 1 ? 's' : '' ?>
        <?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'filter' => $filter !== 'all' ? $filter : null,
        'mode'   => $filter_mode !== '' ? $filter_mode : null,
        'sort'   => $sort !== 'default' ? $sort : null,
    ]) ?>
</div>
<?php endif; ?>
