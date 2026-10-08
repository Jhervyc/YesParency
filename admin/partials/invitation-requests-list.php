<?php
// List fragment for invitation_requests.php — rendered inside #requestListRoot on full
// page load, and returned alone for ?ajax=1 requests (js/ajax-list.js).
?>
<?php if (empty($requests)): ?>
    <div class="results-empty">
        <i class="bi bi-envelope-paper results-empty-icon"></i>
        <div class="results-empty-title">
            No <?= $status_filter !== 'all' ? htmlspecialchars($status_filter) . ' ' : '' ?>requests found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.
        </div>
    </div>
<?php else: ?>
<div class="table-scroll">
    <table class="req-table req-list-table">
        <thead>
            <tr>
                <th>Organization</th>
                <th>Email</th>
                <th>Business Type</th>
                <th>TIN</th>
                <th>Submitted</th>
                <th>Status</th>
                <th class="col-actions-th">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $req):
            $status = $req['status'];
        ?>
        <tr>
            <td>
                <div class="req-company"><?= htmlspecialchars($req['company_name']) ?></div>
                <div class="req-contact"><i class="bi bi-person"></i> <?= htmlspecialchars($req['contact_person']) ?></div>
                <?php if (!empty($req['phone'])): ?>
                    <div class="req-contact"><i class="bi bi-telephone"></i> <?= htmlspecialchars($req['phone']) ?></div>
                <?php endif; ?>
            </td>
            <td class="email-cell">
                <?= htmlspecialchars($req['email']) ?>
            </td>
            <td class="biztype-cell">
                <?= htmlspecialchars($req['business_type'] ?: '—') ?>
            </td>
            <td class="tin-cell">
                <?= htmlspecialchars($req['tax_id_tin'] ?: '—') ?>
            </td>
            <td class="date-cell">
                <?= date('M j, Y', strtotime($req['created_at'])) ?>
                <?php if ($status !== 'pending' && $req['reviewed_at']): ?>
                    <div class="reviewed-note">
                        <?= $status === 'approved' ? 'Approved' : 'Rejected' ?> <?= date('M j, Y', strtotime($req['reviewed_at'])) ?>
                        <?php if ($req['reviewer_username']): ?>
                            by @<?= htmlspecialchars($req['reviewer_username']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td>
                <span class="req-status-pill <?= $status ?>">
                    <i class="bi bi-circle-fill status-dot-xxtiny"></i>
                    <?= ucfirst($status) ?>
                </span>
                <?php if (!empty($req['admin_notes']) && $status === 'rejected'): ?>
                    <div class="admin-notes-preview">
                        "<?= htmlspecialchars(mb_strimwidth($req['admin_notes'], 0, 60, '...')) ?>"
                    </div>
                <?php endif; ?>
            </td>
            <td class="actions-cell">
                <?php if ($status === 'pending'): ?>
                    <!-- Approve form -->
                    <form method="POST" action="invitation_requests.php" class="inline-form">
                        <input type="hidden" name="action"  value="approve">
                        <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                        <button type="submit" class="req-btn approve"
                                onclick="return confirm('Approve request from <?= htmlspecialchars(addslashes($req['company_name'])) ?>?')">
                            <i class="bi bi-check-circle-fill"></i> Approve
                        </button>
                    </form>
                    <!-- Reject — opens modal -->
                    <button type="button" class="req-btn reject"
                            onclick="openRejectModal(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['company_name'])) ?>')">
                        <i class="bi bi-x-circle-fill"></i> Reject
                    </button>

                <?php elseif ($status === 'approved'): ?>
                    <!-- Resend invite -->
                    <form method="POST" action="invitation_requests.php" class="inline-form">
                        <input type="hidden" name="action"  value="resend">
                        <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                        <button type="submit" class="req-btn resend"
                                onclick="return confirm('Re-generate and resend the invitation to <?= htmlspecialchars(addslashes($req['email'])) ?>?')">
                            <i class="bi bi-envelope-arrow-up-fill"></i> Resend
                        </button>
                    </form>

                <?php endif; ?>
                <!-- Delete — shown for all statuses -->
                <form method="POST" action="invitation_requests.php" class="inline-form">
                    <input type="hidden" name="action"  value="delete">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <button type="submit" class="req-btn delete"
                            onclick="return confirm('Permanently delete the request from <?= htmlspecialchars(addslashes($req['company_name'])) ?>? This cannot be undone.')">
                        <i class="bi bi-trash3-fill"></i> Delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="table-foot">
    <div>
        Showing <strong><?= min($total_shown, $offset + 1) ?></strong>–<strong><?= min($total_shown, $offset + $per_page) ?></strong>
        of <strong><?= number_format($total_shown) ?></strong> request<?= $total_shown !== 1 ? 's' : '' ?>
        <?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>
    </div>
    <?= render_pagination($page, $total_pages, [
        'search' => $search,
        'status' => $status_filter !== 'all' ? $status_filter : null,
    ]) ?>
</div>
<?php endif; ?>
