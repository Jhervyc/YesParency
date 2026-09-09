<?php
/**
 * Bid Opening Session Scheduled Email Template
 * Sent strictly to invited committee members / participants in bid_session_invited.
 */
$recipient_name    = $recipient_name ?? 'Committee Member';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$session_title     = $session_title ?? 'Bid Opening Session';
$scheduled_date    = $scheduled_date ?? date('F j, Y');
$scheduled_time    = $scheduled_time ?? date('h:i A');
$stream_path       = $stream_path ?? 'live';
$session_id        = $session_id ?? 0;
$portal_url        = $portal_url ?? ($app_url . '/admin/bid_session.php?session=' . $session_id);
?>
<h2 class="email-title">Bid Opening Session Scheduled</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>You are officially invited to participate in the upcoming <strong>Bid Opening Session</strong> for the following procurement project administered by the Bids and Awards Committee:</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Session Status</span>
        <span class="info-value"><span class="badge badge-info">Scheduled</span></span>
    </div>
    <div class="info-row">
        <span class="info-label">Procurement Title</span>
        <span class="info-value"><?= htmlspecialchars($procurement_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">PhilGEPS Ref. No.</span>
        <span class="info-value"><?= htmlspecialchars($philgeps_ref_no) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Session Title</span>
        <span class="info-value"><?= htmlspecialchars($session_title) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Date &amp; Time</span>
        <span class="info-value"><?= htmlspecialchars($scheduled_date) ?> at <?= htmlspecialchars($scheduled_time) ?></span>
    </div>
</div>

<p><strong>Access &amp; Participation Instructions:</strong></p>
<ul style="padding-left: 20px; font-size: 14px; color: #475569; line-height: 1.6;">
    <li>Please log in with your authorized YesParency administrative account before the scheduled opening time.</li>
    <li>Ensure you have a stable network connection to participate in the quorum attendance, dual-key signing, and eligibility evaluation.</li>
    <li>Only authorized BAC and TWG members with active invitations can access the live session room.</li>
</ul>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($portal_url) ?>" class="btn">Enter Bid Opening Portal</a>
</div>
