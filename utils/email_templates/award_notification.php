<?php
/**
 * Notice of Award Email Template
 * Sent strictly to the winning bidder of the awarded lot.
 */
$recipient_name    = $recipient_name ?? 'Winning Bidder';
$business_name     = $business_name ?? '';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$lot_number        = $lot_number ?? 1;
$lot_title         = $lot_title ?? 'Lot 1';
$awarded_amount    = is_numeric($awarded_amount ?? null) ? number_format((float)$awarded_amount, 2) : ($awarded_amount ?? '0.00');
$award_date        = $award_date ?? date('F j, Y');
$login_url         = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Notice of Award</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>We are pleased to notify you that following the evaluation and post-qualification conducted by the Bids and Awards Committee (BAC), the contract for the project below has been <span class="badge badge-success">Awarded</span> to <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong>.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Award Status</span>
        <span class="info-value"><span class="badge badge-success">Awarded</span></span>
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
        <span class="info-label">Awarded Lot</span>
        <span class="info-value">Lot #<?= htmlspecialchars((string)$lot_number) ?>: <?= htmlspecialchars($lot_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Contract Amount</span>
        <span class="info-value" style="color: #047857; font-size: 15px;">₱<?= htmlspecialchars($awarded_amount) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Award Date</span>
        <span class="info-value"><?= htmlspecialchars($award_date) ?></span>
    </div>
</div>

<p><strong>Next Steps:</strong></p>
<ol style="padding-left: 20px; font-size: 14px; color: #475569; line-height: 1.6;">
    <li>Please log in to your YesParency bidder portal to review the formal Notice of Award documents.</li>
    <li>Within ten (10) calendar days from receipt of this notice, submit the required Performance Security in the form and amount prescribed under R.A. 9184.</li>
    <li>Coordinate with the BAC Secretariat for contract signing and issuance of the Notice to Proceed.</li>
</ol>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">View Award in Portal</a>
</div>
