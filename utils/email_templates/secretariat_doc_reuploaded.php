<?php
/**
 * Secretariat Notification: Bidder Document Re-uploaded Template
 */
$recipient_name = $recipient_name ?? 'BAC Secretariat';
$bidder_name    = $bidder_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$document_label = $document_label ?? 'Document';
$review_url     = $review_url ?? ($app_url . '/admin/bidder-profile.php');
?>
<h2 class="email-title">Bidder Document Re-uploaded for Review</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>An accredited bidder has re-uploaded a legal eligibility document and is awaiting Secretariat review and expiration date validation:</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Bidder Enterprise</span>
        <span class="info-value"><strong><?= htmlspecialchars($business_name ?: $bidder_name) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Representative</span>
        <span class="info-value"><?= htmlspecialchars($bidder_name) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Re-uploaded Document</span>
        <span class="info-value"><span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;"><?= htmlspecialchars($document_label) ?></span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-warning">Awaiting Review</span></span>
    </div>
</div>

<p>Please review the uploaded file on the dedicated Bidder Profile page, verify its authenticity, and set or update its official expiration date:</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($review_url) ?>" class="btn">Open Bidder Profile &amp; Review</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    Setting the expiration date unlocks bidding eligibility if the previous document was expired.
</p>
