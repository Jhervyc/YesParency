<?php
/**
 * Bid Rejected Email Template
 *
 * NOTE: Never includes decrypted bid documents, financial values, or encryption keys.
 */
$recipient_name    = $recipient_name ?? 'Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$reason            = $reason ?? null;
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Notice Regarding Your Bid Submission</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>We are writing to notify you regarding your bid submission for the procurement project titled <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong>.</p>

<p>Following administrative evaluation, your bid has been marked as <span class="badge badge-danger">Rejected</span>.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Bid Status</span>
        <span class="info-value"><span class="badge badge-danger">Rejected</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement Title</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">PhilGEPS Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Bidder Enterprise</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($reason)): ?>
    <div class="info-row">
        <span class="info-label">Reason / Remarks</span>
        <span class="info-value" style="color: #b91c1c;"><?= htmlspecialchars($reason) ?></span>
    </div>
    <?php endif; ?>
</div>

<p>You may log in to the YesParency portal to review the status details. In accordance with R.A. 9184 and data privacy rules, sensitive bid files are protected and accessible only through authorized portal channels.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Details on Portal</a>
</div>
