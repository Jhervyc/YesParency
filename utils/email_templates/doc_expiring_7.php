<?php
/**
 * 7-Day Urgent Document Expiration Reminder Template
 */
$recipient_name  = $recipient_name  ?? 'Bidder';
$business_name   = $business_name   ?? '';
$document_label  = $document_label  ?? 'Eligibility Document';
$expiration_date = $expiration_date ?? 'Soon';
$days_left       = $days_left       ?? 7;
$settings_url    = $settings_url    ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title" style="color:#b91c1c;">Action Needed — Document Expiring in <?= (int)$days_left ?> Days</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>Your document for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> is expiring very soon. Once it expires, you won't be able to submit bids until a renewed copy is uploaded and verified.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expires On</span>
        <span class="info-value" style="color:#b91c1c; font-weight:700;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Time Remaining</span>
        <span class="info-value"><span class="badge badge-danger"><?= (int)$days_left ?> Days Left</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Action Required</span>
        <span class="info-value">Upload renewed document</span>
    </div>
</div>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn">Upload Now</a>
</div>
