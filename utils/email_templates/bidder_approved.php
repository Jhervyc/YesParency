<?php
/**
 * Bidder Account Approved Email Template
 */
$recipient_name = $recipient_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$login_url      = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Your Account Has Been Approved</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Great news — your bidder account for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has been reviewed and approved. You can now log in and start participating in active procurement opportunities.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Account Status</span>
        <span class="info-value"><span class="badge badge-success">Approved</span></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business Name</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
        <span class="info-label">Access</span>
        <span class="info-value">Bidder Portal</span>
    </div>
</div>

<p>Head to the portal to browse open procurements, download bidding documents, and submit your bids.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">Log In to Your Account</a>
</div>
