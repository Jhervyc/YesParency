<?php
/**
 * Bidder Account Approved Email Template
 */
$recipient_name = $recipient_name ?? 'Bidder';
$business_name  = $business_name ?? '';
$login_url      = $login_url ?? ($app_url . '/login.php');
?>
<h2 class="email-title">Bidder Account Approved</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>We are pleased to inform you that your bidder registration application for <strong><?= htmlspecialchars($business_name ?: $recipient_name) ?></strong> has been officially reviewed and <span class="badge badge-success">Approved</span> by the Bids and Awards Committee (BAC) Secretariat.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Account Status</span>
        <span class="info-value"><span class="badge badge-success">Active / Approved</span></span>
    </div>
    <?php if (!empty($business_name)): ?>
    <div class="info-row">
        <span class="info-label">Business Enterprise</span>
        <span class="info-value"><?= htmlspecialchars($business_name) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
        <span class="info-label">Access Level</span>
        <span class="info-value">Accredited Bidder</span>
    </div>
</div>

<p>You can now log in to the YesParency portal to view active procurement opportunities, download bidding documents, and submit electronic bids.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($login_url) ?>" class="btn">Log In to Bidder Portal</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    Please ensure your account credentials remain confidential. For any inquiries, you may contact the BAC Secretariat through the official portal.
</p>
