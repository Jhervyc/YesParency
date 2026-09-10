<?php
/**
 * Document Expired Notification Template
 */
$recipient_name = $recipient_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$document_label = $document_label ?? 'Eligibility Document';
$expiration_date= $expiration_date ?? 'Expired';
$settings_url   = $settings_url ?? ($app_url . '/bidder/settings.php?tab=documents');
?>
<h2 class="email-title" style="color: #991b1b;">Notice: Document Expired — Bidding Locked</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>This is an official notification from <strong><?= htmlspecialchars($app_name) ?></strong>. The following legal eligibility document for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has expired as of <strong><?= htmlspecialchars($expiration_date) ?></strong>:</p>

<div class="info-card" style="border-left-color: #991b1b; background: #fff5f5;">
    <div class="info-row">
        <span class="info-label">Expired Document</span>
        <span class="info-value"><strong><?= htmlspecialchars($document_label) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Expiration Date</span>
        <span class="info-value" style="color: #991b1b; font-weight: 800;"><?= htmlspecialchars($expiration_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Submission Privilege</span>
        <span class="info-value"><span class="badge" style="background: #fee2e2; color: #991b1b; font-weight: 800;">LOCKED</span></span>
    </div>
</div>

<p style="color: #991b1b; font-weight: 600;">
    Under municipal procurement guidelines and RA 9184 compliance requirements, you are currently unable to submit electronic bid proposals on any open procurement opportunities.
</p>

<p>To restore your bidding privileges, please log in and upload your renewed document. Once uploaded, the Secretariat will verify the renewal:</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($settings_url) ?>" class="btn" style="background: #991b1b;">Renew Expired Document</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    If you have questions or require assistance, please contact the BAC Secretariat office.
</p>
