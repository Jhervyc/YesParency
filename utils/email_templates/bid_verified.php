<?php
/**
 * Bid Submission Verified Email Template
 */
$recipient_name    = $recipient_name ?? 'Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$submission_date   = $submission_date ?? date('Y-m-d H:i');
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Bid Submission Confirmed</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your bid for <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong> has been received and verified. You're all set for the bid opening.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-success">Verified</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Submitted</span>
        <span class="info-value"><?= htmlspecialchars($submission_date) ?></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>We'll notify you when the bid opening session is scheduled. Keep an eye on your portal for updates.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Bid Status</a>
</div>
