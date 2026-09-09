<?php
/**
 * Bid Verified Email Template
 */
$recipient_name    = $recipient_name ?? 'Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$submission_date   = $submission_date ?? date('Y-m-d H:i');
$bid_id            = $bid_id ?? 0;
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Bid Submission Verified</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your electronic bid submission for the procurement project titled <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong> has been reviewed and <span class="badge badge-success">Verified</span> by the BAC Secretariat.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Bid Status</span>
        <span class="info-value"><span class="badge badge-success">Verified / Submitted</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement Title</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">PhilGEPS Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Submission Date</span>
        <span class="info-value"><?= htmlspecialchars($submission_date) ?></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Bidder Enterprise</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>Your submitted encrypted bid packets are securely staged in the YesParency vault and will be unlocked during the scheduled Bid Opening Session by authorized BAC members.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Bid Status in Portal</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    Please monitor your account notifications and email for the official Bid Opening schedule.
</p>
