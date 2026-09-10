<?php
/**
 * Password Reset Request Email Template
 */
$recipient_name  = $recipient_name  ?? 'User';
$reset_url       = $reset_url       ?? '#';
$expires_minutes = $expires_minutes ?? 15;
?>
<h2 class="email-title">Reset Your Password</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>We received a request to reset the password on your YesParency account. Click the button below to choose a new one.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($reset_url) ?>" class="btn">Reset My Password</a>
</div>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Link Expires</span>
        <span class="info-value"><?= (int)$expires_minutes ?> minutes from now</span>
    </div>
    <div class="info-row">
        <span class="info-label">One-Time Use</span>
        <span class="info-value">Link is invalidated after use</span>
    </div>
</div>

<p style="font-size:13px; color:#55665a; margin-top:16px;">
    If the button doesn't work, copy and paste this link into your browser:<br>
    <span style="font-size:12px; color:#1f7a3d; word-break:break-all;"><?= htmlspecialchars($reset_url) ?></span>
</p>

<p style="font-size:13px; color:#55665a; background:#f4f8f5; border:1px solid #d4e0d8; border-radius:8px; padding:12px 14px; margin-top:14px; line-height:1.6;">
    Didn't request this? You can safely ignore this email — your password won't change unless you use the link above.
</p>
