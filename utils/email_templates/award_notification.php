<?php
/**
 * Notice of Award Email Template
 */
$recipient_name    = $recipient_name ?? 'Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$lot_number        = $lot_number ?? 1;
$lot_title         = $lot_title ?? 'Lot 1';
$awarded_amount    = is_numeric($awarded_amount ?? null) ? number_format((float)$awarded_amount, 2) : ($awarded_amount ?? '0.00');
$award_date        = $award_date ?? date('F j, Y');
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">You've Been Awarded a Contract</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Congratulations! <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has been selected as the winning bidder for the procurement project below.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-success">Awarded</span></span>
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
        <span class="info-label">Awarded Lot</span>
        <span class="info-value">Lot #<?= (int)$lot_number ?>: <?= htmlspecialchars($lot_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Contract Amount</span>
        <span class="info-value" style="color:#166534; font-weight:700;">&#8369;<?= htmlspecialchars($awarded_amount) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Award Date</span>
        <span class="info-value"><?= htmlspecialchars($award_date) ?></span>
    </div>
</div>

<p>Log in to the portal to view the full award details and coordinate with the Secretariat for the next steps.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Award in Portal</a>
</div>
