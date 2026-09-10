<?php
/**
 * Invitation Approved Email Template
 */
$recipient_name = $recipient_name ?? 'Applicant';
$company_name   = $company_name   ?? '';
$invite_url     = $invite_url     ?? ($app_url . '/accept_invitation.php');
$expires_at     = $expires_at     ?? 'in 7 days';
?>
<h2 class="email-title">Your Access Request Has Been Approved</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your access request<?= !empty($company_name) ? ' for <strong>' . htmlspecialchars($company_name) . '</strong>' : '' ?> has been approved. Click the button below to set up your account — the link expires <?= htmlspecialchars($expires_at) ?>, so don't wait too long.</p>

<div class="info-card">
    <?php if (!empty($company_name)): ?>
    <div class="info-row">
        <span class="info-label">Organization</span>
        <span class="info-value"><?= htmlspecialchars($company_name) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
        <span class="info-label">Account Type</span>
        <span class="info-value"><span class="badge badge-info">Portal User</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Link Expires</span>
        <span class="info-value" style="color:#854d0e;"><?= htmlspecialchars($expires_at) ?></span>
    </div>
</div>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($invite_url) ?>" class="btn">Create My Account</a>
</div>

<p style="font-size:13px; color:#55665a; margin-top:16px;">
    If the button doesn't work, copy and paste this link into your browser:<br>
    <span style="font-size:12px; color:#1f7a3d; word-break:break-all;"><?= htmlspecialchars($invite_url) ?></span>
</p>
