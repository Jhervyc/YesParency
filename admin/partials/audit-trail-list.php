<?php
// List fragment for audit_trail.php — rendered inside #auditListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if ($total_shown === 0): ?>
<div class="anc-empty-state">
    <i class="bi bi-database-exclamation anc-empty-icon"></i>
    <div class="anc-empty-text">
        <?php if ($search !== ''): ?>
            No audit records found for "<?= htmlspecialchars($search) ?>".
        <?php elseif ($action_filter !== 'all' || $module_filter !== 'all'): ?>
            No audit records match the selected filters.
        <?php else: ?>
            No audit records yet.
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="table-scroll">
    <table class="proc-table at-list-table">
        <thead>
            <tr>
                <th class="col-w-42"></th>
                <th>Description</th>
                <th class="col-mode">Actor</th>
                <th class="col-opening">Module</th>
                <th class="col-abc">IP Address</th>
                <th class="col-status">Time</th>
                <th class="at-action-th">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()):
            $logs_data[$row['log_id']] = $row;
            $act = strtoupper($row['action']);
            $mod = 'default'; $icon = 'bi-database';
            $is_create = in_array($act,['CREATE','INSERT'])||str_ends_with($act,'_CREATED')||str_ends_with($act,'SUBMITTED')||str_ends_with($act,'INVITED')||str_ends_with($act,'RECORDED');
            $is_update = in_array($act,['UPDATE','PUBLISH','APPROVE','REJECT','TOGGLE_MAINTENANCE'])||str_ends_with($act,'_UPDATED')||str_ends_with($act,'_CHANGED')||str_ends_with($act,'_APPROVED')||str_ends_with($act,'_REJECTED')||str_ends_with($act,'_STARTED')||str_ends_with($act,'_ACTIVATED')||str_ends_with($act,'_DEACTIVATED')||str_ends_with($act,'_REORDERED');
            $is_delete = $act==='DELETE'||str_ends_with($act,'_DELETED')||str_ends_with($act,'_CANCELLED')||str_ends_with($act,'_REMOVED');
            if ($is_create) { $mod='create'; $icon='bi-plus-circle'; }
            elseif ($is_update) { $mod='update'; $icon='bi-pencil-square'; }
            elseif ($is_delete) { $mod='delete'; $icon='bi-trash3'; }
            $actorName = $row['username'] ? trim($row['firstname'].' '.$row['lastname']) : 'System';
            $usernameStr = $row['username'] ? '@'.$row['username'] : '—';
        ?>
        <tr>
            <td class="at-icon-col">
                <div class="at-action-icon at-action-icon--<?= $mod ?>">
                    <i class="bi <?= $icon ?>"></i>
                </div>
            </td>
            <td class="proc-title-cell">
                <?= htmlspecialchars(mb_strimwidth($row['description'], 0, 70, '…')) ?>
                <div class="at-action-sub">
                    <span class="proc-status-pill at-action-pill at-action-pill--<?= $mod ?>">
                        <?= htmlspecialchars($act) ?>
                    </span>
                </div>
            </td>
            <td class="proc-deadline-cell col-mode">
                <strong><?= htmlspecialchars($actorName) ?></strong>
                <span class="clr-idle"><?= htmlspecialchars($usernameStr) ?></span>
            </td>
            <td class="proc-deadline-cell col-opening">
                <strong><?= htmlspecialchars(ucfirst($row['module'])) ?></strong>
                <?php if ($row['record_id']): ?><span class="clr-idle">#<?= intval($row['record_id']) ?></span><?php endif; ?>
            </td>
            <td class="proc-deadline-cell col-abc"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
            <td class="proc-deadline-cell col-status nowrap">
                <?= date('M j, Y', strtotime($row['created_at'])) ?><br>
                <span class="clr-idle"><?= date('g:i A', strtotime($row['created_at'])) ?></span>
            </td>
            <td class="text-right at-view-cell">
                <button type="button" class="proc-action-btn btn-view" aria-label="View details" onclick="loadLogDetail(<?= $row['log_id'] ?>)">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="table-foot">
    <div>
        Showing <strong><?= number_format(min($total_shown,$offset+1)) ?></strong>–<strong><?= number_format(min($total_shown,$offset+$per_page)) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> record<?= $total_shown!=1?'s':'' ?>
        <?= $search?' for "'.htmlspecialchars($search).'"':'' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'action' => $action_filter !== 'all' ? $action_filter : null,
        'module' => $module_filter !== 'all' ? $module_filter : null,
        'search' => $search,
    ]) ?>
</div>
<?php endif; ?>

<!-- Row data for the detail drawer (re-read after each AJAX swap) -->
<script type="application/json" id="auditLogsData"><?= json_encode((object)$logs_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
