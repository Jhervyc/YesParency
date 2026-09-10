<?php
/**
 * Bid Opening Session Scheduled Email Template
 */
$recipient_name    = $recipient_name ?? 'Committee Member';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$session_title     = $session_title ?? 'Bid Opening Session';
$scheduled_date    = $scheduled_date ?? date('F j, Y');
$scheduled_time    = $scheduled_time ?? date('h:i A');
$session_id        = $session_id ?? 0;
$portal_url        = $portal_url ?? ($app_url . '/admin/bid_session.php?session=' . $session_id);
?>
<h2 class="email-title">Bid Opening Session Scheduled</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>You've been invited to a bid opening session for the following procurement. Please make sure you're available and logged in before the session starts.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-info">Scheduled</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Session</span>
        <span class="info-value"><?= htmlspecialchars($session_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Date &amp; Time</span>
        <span class="info-value"><?= htmlspecialchars($scheduled_date) ?> at <?= htmlspecialchars($scheduled_time) ?></span>
    </div>
</div>

<p>Use the button below to access the session room. You'll need to be signed in with your authorized account.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($portal_url) ?>" class="btn">Open Session Room</a>
</div>
