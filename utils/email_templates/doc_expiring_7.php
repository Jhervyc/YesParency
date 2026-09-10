<?php
/**
 * 7-Day Urgent Document Expiration Reminder Template
 */
$recipient_name = $recipient_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$document_label = $document_label ?? 'Eligibility Document';
$expiration_date= $expiration_date ?? 'Soon';
$days_left      = $days_left ?? 7;
$settings_url   = $settings_url ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title" style="color: #dc2626;">Urgent: Document Expiring Soon</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>This is an <strong>urgent compliance reminder</strong> regarding your accredited supplier documents for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> on <strong><?= htmlspecialchars($app_name) ?></strong>.</p>

<p>Your required document is scheduled to expire in <strong style="color: #dc2626;"><?= (int)$days_left ?> days</strong>. Per procurement regulations (RA 9184), <strong>suppliers with expired documents are strictly prohibited from submitting electronic bids</strong>.</p>

<div class="info-card" style="border-left-color: #dc2626;">
    <div class="info-row">
        <span class="info-label">Expiring Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expiration Date</span>
        <span class="info-value" style="color: #dc2626; font-weight: 800;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Urgency</span>
        <span class="info-value"><span class="badge" style="background: #fee2e2; color: #b91c1c; font-weight: 800;">Urgent (<?= (int)$days_left ?> Days Left)</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Action Required</span>
        <span class="info-value">Re-upload Renewed File</span>
    </div>
</div>

<p>Please update your compliance document immediately to keep your bidding privileges active:</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn" style="background: #dc2626;">Re-upload Document Now</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    Failure to renew before <?= htmlspecialchars($expiration_date) ?> will automatically lock bid proposal submissions across all active procurements.
</p>
