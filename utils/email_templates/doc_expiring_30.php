<?php
/**
 * 30-Day Document Expiration Reminder Template
 */
$recipient_name = $recipient_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$document_label = $document_label ?? 'Eligibility Document';
$expiration_date= $expiration_date ?? 'Soon';
$days_left      = $days_left ?? 30;
$settings_url   = $settings_url ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title">Document Expiration Reminder</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>This is an automated notice from <strong><?= htmlspecialchars($app_name) ?></strong> regarding your accredited supplier eligibility documents for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong>.</p>

<p>One of your compliance documents will expire in <strong><?= (int)$days_left ?> days</strong>. Please prepare and upload your renewed document before the expiration date to prevent any disruption to your bidding eligibility.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expiration Date</span>
        <span class="info-value" style="color: #d97706; font-weight: 700;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Time Remaining</span>
        <span class="info-value"><span class="badge" style="background: #fef3c7; color: #92400e;"><?= (int)$days_left ?> Days Remaining</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Current Bidding Status</span>
        <span class="info-value"><span class="badge badge-success">Valid / Eligible</span></span>
    </div>
</div>

<p>To avoid being disqualified from ongoing or upcoming procurement opportunities, please upload the renewed document in your Account Settings:</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn">Update Document in Settings</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    Once uploaded, the BAC Secretariat will review the renewal and update your document standing. If you have already secured the renewal, please log in and submit the latest copy as soon as possible.
</p>
