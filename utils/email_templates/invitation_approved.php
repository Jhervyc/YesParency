<?php
/**
 * Invitation Approved Email Template
 *
 * Sent when an admin approves an invitation_request.
 * Delivers the unique token link for the applicant to create their account.
 *
 * Expected variables:
 *   $recipient_name  — contact person name
 *   $company_name    — organization name
 *   $invite_url      — full URL to accept_invitation.php?token=...
 *   $expires_at      — human-readable expiry string e.g. "January 20, 2027 at 11:59 PM"
 */
$recipient_name = $recipient_name ?? 'Applicant';
$company_name   = $company_name   ?? '';
$invite_url     = $invite_url     ?? ($app_url . '/accept_invitation.php');
$expires_at     = $expires_at     ?? 'in 7 days';
?>
<h2 class="email-title">Your Access Request Has Been Approved</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>We are pleased to inform you that the access request submitted on behalf of
<strong><?= htmlspecialchars($company_name ?: $recipient_name) ?></strong> has been
<span class="badge badge-success">Approved</span> by the YesParency Secretariat.</p>

<p>You may now create your account by clicking the button below. Please do so before the link expires.</p>

<div class="info-card">
    <?php if (!empty($company_name)): ?>
    <div class="info-row">
        <span class="info-label">Organization</span>
        <span class="info-value"><?= htmlspecialchars($company_name) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
        <span class="info-label">Account Role</span>
        <span class="info-value"><span class="badge badge-info">Portal User</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Link Expires</span>
        <span class="info-value" style="color:#b45309;"><?= htmlspecialchars($expires_at) ?></span>
    </div>
</div>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($invite_url) ?>" class="btn">Create My Account</a>
</div>

<p style="font-size:13px; color:#64748b; line-height:1.6; margin-top:20px;">
    If the button above does not work, copy and paste this link into your browser:<br>
    <span style="font-size:12px; color:#0369a1; word-break:break-all;"><?= htmlspecialchars($invite_url) ?></span>
</p>

<p style="font-size:13px; color:#64748b; line-height:1.6;">
    Once your account is created, you will be able to view active procurement opportunities and
    apply for bidder accreditation through the portal. If you did not request access to YesParency,
    you may safely ignore this email.
</p>
