<?php
/**
 * Bidder Account Rejected Email Template
 */
$recipient_name = $recipient_name ?? 'Applicant';
$business_name  = $business_name ?? '';
$reason         = $reason ?? null;
$login_url      = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Update on Your Bidder Application</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your bidder accreditation application for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has been reviewed. Unfortunately, it wasn't approved this time.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Application Status</span>
        <span class="info-value"><span class="badge badge-danger">Not Approved</span></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business Name</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($reason)): ?>
    <div class="info-row">
        <span class="info-label">Reason</span>
        <span class="info-value" style="color:#b91c1c;"><?= htmlspecialchars($reason) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>If you think there's been a mistake or you'd like to update your documents, you can log in to review your profile or reach out to the Secretariat through the portal.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">Go to Portal</a>
</div>
