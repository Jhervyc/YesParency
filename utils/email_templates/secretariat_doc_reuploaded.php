<?php
/**
 * Secretariat Notification: Bidder Document Re-uploaded Template
 */
$recipient_name = $recipient_name ?? 'Secretariat';
$bidder_name    = $bidder_name    ?? 'Bidder';
$business_name  = $business_name  ?? '';
$document_label = $document_label ?? 'Document';
$review_url     = $review_url     ?? ($app_url . '/admin/bidder-profile.php');
?>
<h2 class="email-title">Document Re-uploaded — Review Needed</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>A bidder has uploaded a new document and is waiting for your review.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Bidder</span>
        <span class="info-value"><strong><?= htmlspecialchars($business_name ?: $bidder_name) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Representative</span>
        <span class="info-value"><?= htmlspecialchars($bidder_name) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Document</span>
        <span class="info-value"><span class="badge badge-info"><?= htmlspecialchars($document_label) ?></span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-warning">Awaiting Review</span></span>
    </div>
</div>

<p>Open the bidder profile to verify the document and set its expiration date. This will restore the bidder's access if it was previously locked.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($review_url) ?>" class="btn">Review Document</a>
</div>
