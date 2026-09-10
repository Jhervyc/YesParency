<?php
/**
 * Bid Opening Session Concluded Email Template
 */
$recipient_name    = $recipient_name ?? 'Committee Member';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$session_id        = $session_id ?? 0;
$concluded_date    = $concluded_date ?? date('F j, Y');
$report_url        = $report_url ?? ($app_url . '/admin/checklist_pdf.php?session=' . $session_id);
?>
<h2 class="email-title">Bid Opening Session Concluded</h2>

<p>Hi <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>The bid opening session for <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong> has wrapped up. The official checklist report is now ready for review.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value"><span class="badge badge-success">Concluded</span></span>
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
        <span class="info-label">Date Concluded</span>
        <span class="info-value"><?= htmlspecialchars($concluded_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Report</span>
        <span class="info-value"><span class="badge badge-success">Available</span></span>
    </div>
</div>

<p>Sign in to access and download the official bid opening checklist report.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($report_url) ?>" class="btn">View Report</a>
</div>
