<?php
/**
 * Bid Session Concluded & Report Available Email Template
 * Sent to invited session users. Securely links to authorized report.
 */
$recipient_name    = $recipient_name ?? 'Committee Member';
$procurement_title = $procurement_title ?? 'Procurement Project';
$philgeps_ref_no   = $philgeps_ref_no ?? 'N/A';
$session_id        = $session_id ?? 0;
$concluded_date    = $concluded_date ?? date('F j, Y');
$report_url        = $report_url ?? ($app_url . '/admin/checklist_pdf.php?session=' . $session_id);
?>
<h2 class="email-title">Bid Opening Session Concluded</h2>

<p>Dear <strong><?= htmlspecialchars($recipient_name) ?></strong>,</p>

<p>The formal Bid Opening Session for the procurement project titled <strong>"<?= htmlspecialchars($procurement_title) ?>"</strong> has officially <span class="badge badge-success">Concluded</span>.</p>

<div class="info-card">
    <div class="info-row">
        <span class="info-label">Session Status</span>
        <span class="info-value"><span class="badge badge-success">Concluded</span></span>
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
        <span class="info-label">Conclusion Date</span>
        <span class="info-value"><?= htmlspecialchars($concluded_date) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Official Report</span>
        <span class="info-value">Generated &amp; Available</span>
    </div>
</div>

<p>The official BAC Bid Opening Checklist Report has been compiled. In accordance with data security and procurement transparency guidelines, the report is accessible securely inside YesParency following authentication.</p>

<div class="btn-wrapper">
    <a href="<?= htmlspecialchars($report_url) ?>" class="btn">View Bid Opening Report</a>
</div>

<p style="font-size: 13px; color: #64748b; margin-top: 24px;">
    <strong>Security Notice:</strong> You must be signed in with an authorized account to access the official PDF checklist. Unauthorized access is strictly prohibited under R.A. 9184 and R.A. 10173.
</p>
