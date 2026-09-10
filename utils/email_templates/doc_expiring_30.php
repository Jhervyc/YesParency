<?php
/**
 * 30-Day Document Expiration Reminder Template
 */
$recipient_name  = $recipient_name  ?? 'Bidder';
$business_name   = $business_name   ?? '';
$document_label  = $document_label  ?? 'Eligibility Document';
$expiration_date = $expiration_date ?? 'Soon';
$days_left       = $days_left       ?? 30;
$settings_url    = $settings_url    ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title">Document Expiring in <?= (int)$days_left ?> Days</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>One of your documents for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> is coming up for renewal. Upload a new copy before it expires to keep your bidding access active.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expires On</span>
        <span class="info-value" style="color:#854d0e; font-weight:700;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Time Remaining</span>
        <span class="info-value"><span class="badge badge-warning"><?= (int)$days_left ?> Days</span></span>
    </div>
</div>

<p>Head to your account settings to upload the renewed document.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn">Upload Renewal</a>
</div>
