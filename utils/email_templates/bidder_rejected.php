<?php
/**
 * Bidder Account Rejected Email Template
 */
$recipient_name = $recipient_name ?? 'Applicant';
$business_name  = $business_name ?? '';
$reason         = $reason ?? null;
$login_url      = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Notice Regarding Your Bidder Application</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Thank you for submitting your bidder accreditation application for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> to YesParency.</p>

<p>After careful evaluation by the Bids and Awards Committee (BAC) Secretariat, we regret to inform you that your application has been <span class="badge badge-danger">Rejected</span>.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Application Status</span>
        <span class="info-value"><span class="badge badge-danger">Rejected</span></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business Name</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($reason)): ?>
    <div class="info-row">
        <span class="info-label">Remarks / Reason</span>
        <span class="info-value" style="color: #b91c1c;"><?= htmlspecialchars($reason) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>If you believe this decision was made in error or if you need to provide updated eligibility documents, please sign in to review your profile or get in touch with the Secretariat.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">Sign In to Review Account</a>
</div>
