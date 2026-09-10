<?php
/**
 * Bid Rejected Email Template
 */
$recipient_name    = $recipient_name ?? 'Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$reason            = $reason ?? null;
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Update on Your Bid Submission</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your bid submission for <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong> has been reviewed and was not successful this time.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Bid Status</span>
        <span class="info-value"><span class="badge badge-danger">Not Awarded</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business</span>
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

<p>You can log in to the portal to review the full details of your submission.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Details</a>
</div>
