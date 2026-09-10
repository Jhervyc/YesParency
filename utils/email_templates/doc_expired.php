<?php
/**
 * Document Expired Notification Template
 */
$recipient_name  = $recipient_name  ?? 'Bidder';
$business_name   = $business_name   ?? '';
$document_label  = $document_label  ?? 'Eligibility Document';
$expiration_date = $expiration_date ?? 'Expired';
$settings_url    = $settings_url    ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title" style="color:#b91c1c;">Document Expired — Bidding Paused</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>A required document for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has expired. Your ability to submit bids is currently paused until you upload a valid renewal.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Expired Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expired On</span>
        <span class="info-value" style="color:#b91c1c; font-weight:700;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Bid Submissions</span>
        <span class="info-value"><span class="badge badge-danger">Paused</span></span>
    </div>
</div>

<p>Upload a renewed copy in your account settings. Once the Secretariat reviews and approves it, your access will be restored.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn">Upload Renewal</a>
</div>
