<?php
/**
 * Invitation Rejected Email Template
 *
 * Sent when an admin rejects an invitation_request.
 *
 * Expected variables:
 *   $recipient_name  — contact person name
 *   $company_name    — organization name
 *   $admin_notes     — optional rejection reason from the admin
 *   $register_url    — link back to the registration/request page
 */
$recipient_name = $recipient_name ?? 'Applicant';
$company_name   = $company_name   ?? '';
$admin_notes    = $admin_notes    ?? null;
$register_url   = $register_url   ?? ($app_url . '/register.php');
?>
<h2 class="email-title">Update on Your Access Request</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Thank you for submitting an access request for
<strong><?= htmlspecialchars($company_name ?: $recipient_name) ?></strong>
to the YesParency Procurement Portal.</p>

<p>After reviewing your submission, we regret to inform you that your request has been
<span class="badge badge-danger">Declined</span> at this time.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Request Status</span>
        <span class="info-value"><span class="badge badge-danger">Declined</span></span>
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

<p style="font-size:14px; color:#334155;">
    If you believe this decision was made in error, or if your organization's circumstances have
    changed, you are welcome to submit a new request with updated information.
</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($register_url) ?>" class="btn">Submit a New Request</a>
</div>

<p style="font-size:13px; color:#64748b; margin-top:20px; line-height:1.6;">
    For inquiries, please contact the BAC Secretariat through the official portal.
</p>
