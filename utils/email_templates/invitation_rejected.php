<?php
/**
 * Invitation Rejected Email Template
 */
$recipient_name = $recipient_name ?? 'Applicant';
$company_name   = $company_name   ?? '';
$admin_notes    = $admin_notes    ?? null;
$register_url   = $register_url   ?? ($app_url . '/register.php');
?>
<h2 class="email-title">Update on Your Access Request</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your access request<?= !empty($company_name) ? ' for <strong>' . htmlspecialchars($company_name) . '</strong>' : '' ?> has been reviewed and wasn't approved this time.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Request Status</span>
        <span class="info-value"><span class="badge badge-danger">Not Approved</span></span>
    </div>
    <?php if (!empty($company_name)): ?>
    <div class="info-row">
        <span class="info-label">Organization</span>
        <span class="info-value"><?= htmlspecialchars($company_name) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($admin_notes)): ?>
    <div class="info-row">
        <span class="info-label">Remarks</span>
        <span class="info-value" style="color:#b91c1c;"><?= htmlspecialchars($admin_notes) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>If your situation has changed or you'd like to try again with updated information, feel free to submit a new request.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($register_url) ?>" class="btn">Submit a New Request</a>
</div>
