<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$bidder_id      = intval($_SESSION['user_id']);
$bidder_doc_status = check_bidder_documents_status($conn, $bidder_id);

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// 1. Fetch Procurement Details
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status != 'draft' LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    $_SESSION['alert_error'] = "Procurement project not found.";
    header("Location: procurement.php");
    exit();
}

// 2. Fetch Associated Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_res = $lot_stmt->get_result();
$lots = [];
$total_lots_abc = 0;
while ($l = $lots_res->fetch_assoc()) {
    $lots[] = $l;
    $total_lots_abc += (float)$l['abc'];
}
$lot_stmt->close();

if (!function_exists('resolve_proc_doc_url')) {
    function resolve_proc_doc_url($file_path, $context = 'bidder') {
        $raw = trim($file_path ?? '');
        if (empty($raw)) return '#';
        if (preg_match('/^https?:\/\//i', $raw)) return $raw;

        $rel = ltrim($raw, '/');
        while (strpos($rel, '../') === 0) {
            $rel = substr($rel, 3);
        }
        if (strpos($rel, 'uploads/') !== 0) {
            $rel = 'uploads/procurements/' . $rel;
        }

        if (in_array($context, ['admin', 'bidder', 'user'])) {
            return '../' . $rel;
        }
        return $rel;
    }
}

// 3. Fetch Downloadable Documents (Separated by document_category)
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$docs_res = $doc_stmt->get_result();
$original_documents   = [];
$associated_documents = [];
while ($d = $docs_res->fetch_assoc()) {
    if (($d['document_category'] ?? 'original') === 'associated') {
        $associated_documents[] = $d;
    } else {
        $original_documents[] = $d;
    }
}
$doc_stmt->close();

// The existing Bidding Documents & Files section displays only original documents
$documents = $original_documents;

// 4. Check for Existing Bid Proposal by Current Bidder
$bid_check = $conn->prepare("SELECT id, submission_date, status FROM bids WHERE bidder_id = ? AND procurement_id = ? LIMIT 1");
$bid_check->bind_param("ii", $bidder_id, $procurement_id);
$bid_check->execute();
$my_bid = $bid_check->get_result()->fetch_assoc();
$bid_check->close();

$has_bid    = !empty($my_bid);
$p_status   = strtolower($procurement['status'] ?? 'open');
$is_open    = ($p_status === 'open');

// Deadline & Urgency Calculation
$diff_days  = null;
$is_urgent  = false;
$deadline_text = 'To Be Announced';

if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($is_open && $diff_days <= 3 && $diff_days >= 0) {
        $is_urgent = true;
    }
}

// Status styles
$status_pill_class = in_array($p_status, ['open','draft','closed','awarded','cancelled'])
    ? 'status-' . $p_status
    : 'status-open';

// Submission routing — SVP / Shopping → quotation page, everything else → bid page
$_proc_mode      = $procurement['procurement_mode'] ?? '';
$_is_quotation   = is_quotation_mode($_proc_mode);
$submit_url      = $_is_quotation
    ? "submit_quotation.php?id={$procurement_id}"
    : "submit_bid.php?id={$procurement_id}";
$submit_label    = $_is_quotation ? 'Submit Price Quotation' : 'Submit Bid Proposal';
$submit_icon     = $_is_quotation ? 'bi-file-earmark-text-fill' : 'bi-send-fill';
$cta_title       = $_is_quotation ? 'Submit a Price Quotation' : 'Submit Electronic Bid';
$cta_desc        = $_is_quotation
    ? 'Upload your quotation document for each lot. The procurement officer will confirm your offered price after review.'
    : 'Ensure all lot financial components and eligibility documents comply with RA 9184 before the submission deadline.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-view-procurement.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Procurement Details';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Navigation & Back Bar ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php">Bidding Opportunities</a>
            <span>/</span>
            <span class="vp-breadcrumb-current">Ref #<?= htmlspecialchars($procurement['slsu_ref_no'] ?? $procurement['id']) ?></span>
        </div>

        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Opportunities
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <!-- SLSU Reference Badge -->
                <span class="hero-pill ref" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($procurement['slsu_ref_no'] ?? '') ?>'); alert('Reference number copied!');" title="Click to copy reference number">
                    <i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($procurement['slsu_ref_no'] ?? 'N/A') ?>
                    <i class="bi bi-clipboard hero-pill-copy-icon"></i>
                </span>

                <!-- Procurement Status Pill -->
                <span class="hero-pill <?= $status_pill_class ?>">
                    ● <?= strtoupper($p_status) ?>
                </span>

                <!-- Urgency Pill if closing soon -->
                <?php if ($is_open && $is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-hourglass-bottom"></i> <?= $diff_days > 0 ? "Closing in {$diff_days} day(s)" : "Closing Today" ?>
                    </span>
                <?php endif; ?>

                <!-- Bid Submitted Status Pill -->
                <?php if ($has_bid): ?>
                    <span class="hero-pill submitted">
                        <i class="bi bi-check-circle-fill"></i> Proposal Submitted
                    </span>
                <?php endif; ?>
            </div>

            <div class="vp-hero-posted">
                <i class="bi bi-calendar-check"></i> Posted: <?= !empty($procurement['posting_date']) ? date('M j, Y', strtotime($procurement['posting_date'])) : date('M j, Y', strtotime($procurement['created_at'])) ?>
            </div>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <!-- 4 Key Metric Tiles Inside Hero Banner -->
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format($procurement['abc'], 2) ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-alarm"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--sm">
                    <?= !empty($procurement['closing_date']) ? date('M j, Y · g:i A', strtotime($procurement['closing_date'])) : 'TBD' ?>
                </div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-door-open"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--sm">
                    <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'TBD' ?>
                </div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers"></i> Structure &amp; Docs</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--sm">
                    <?= count($lots) ?> Lot(s) · <?= count($documents) ?> Doc(s)
                </div>
            </div>
        </div>
    </div>

    <!-- ── Main Grid Layout (65% + 35%) ── -->
    <div class="vp-grid-layout">

        <!-- ════════════════════════════════════════════════════════
             LEFT COLUMN (65%): Details, Lots, Timeline
             ════════════════════════════════════════════════════════ -->
        <div class="vp-left-col">

            <!-- 1. General Project Details & Description -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-file-earmark-text clr-green"></i>
                        Project Specifications &amp; Overview
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val spec-field-val--mono">
                                <?= htmlspecialchars($procurement['slsu_ref_no'] ?? 'N/A') ?>
                            </div>
                        </div>

                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val">
                                <?= htmlspecialchars($procurement['procurement_mode'] ?? 'Public Bidding') ?>
                            </div>
                        </div>

                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Total Approved Budget (ABC)</div>
                            <div class="spec-field-val spec-field-val--mono">
                                ₱<?= number_format($procurement['abc'], 2) ?>
                            </div>
                        </div>

                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Bidding Status</div>
                            <div class="spec-field-val">
                                <span class="spec-status-badge <?= $status_pill_class ?>">
                                    <?= strtoupper($p_status) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($procurement['description'])): ?>
                        <div class="desc-wrap">
                            <div class="spec-field-lbl spec-field-lbl--gap">Scope of Work / Project Description</div>
                            <div class="desc-text-box">
                                <?= htmlspecialchars($procurement['description']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Lots Breakdown Card -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-layers clr-blue"></i>
                        Lots Breakdown &amp; Allotted ABC
                    </div>
                    <span class="vp-card-count"><?= count($lots) ?> Lot(s)</span>
                </div>

                <div>
                    <?php if (count($lots) > 0): ?>
                        <div class="table-scroll">
                            <table class="lots-data-table">
                                <thead>
                                    <tr>
                                        <th class="col-lotnum-th">Lot #</th>
                                        <th>Lot Title &amp; Description</th>
                                        <th class="col-abc-th">Allocated ABC</th>
                                        <th class="col-share-th">Share (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot):
                                        $lot_share = $procurement['abc'] > 0 ? round(((float)$lot['abc'] / (float)$procurement['abc']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="lot-badge">Lot <?= htmlspecialchars($lot['lot_number']) ?></span>
                                        </td>
                                        <td>
                                            <div class="lot-title-cell">
                                                <?= htmlspecialchars($lot['lot_title']) ?>
                                            </div>
                                            <?php if (!empty($lot['description'])): ?>
                                                <div class="lot-desc-cell">
                                                    <?= htmlspecialchars($lot['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="lot-abc-cell">
                                            ₱<?= number_format($lot['abc'], 2) ?>
                                        </td>
                                        <td class="lot-share-cell">
                                            <?= $lot_share ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" class="ta-right">Total ABC Across All Lots</td>
                                        <td class="ta-right lots-total-abc">
                                            ₱<?= number_format($total_lots_abc ?: $procurement['abc'], 2) ?>
                                        </td>
                                        <td class="ta-right">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="lots-empty">
                            <i class="bi bi-layers lots-empty-icon"></i>
                            This procurement project is bid as a single consolidated contract without lot sub-items.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Key Milestone Dates & Schedule -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar3 clr-orange"></i>
                        Procurement Timeline &amp; Key Deadlines
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="vp-timeline">
                        <!-- Step 1: Posted -->
                        <div class="timeline-step">
                            <div class="timeline-dot"><i class="bi bi-check2"></i></div>
                            <div class="timeline-lbl">1. Opportunity Posted</div>
                            <div class="timeline-val">
                                <?= !empty($procurement['posting_date']) ? date('F j, Y', strtotime($procurement['posting_date'])) : date('F j, Y', strtotime($procurement['created_at'])) ?>
                            </div>
                        </div>

                        <!-- Step 2: Submission Deadline -->
                        <div class="timeline-step">
                            <div class="timeline-dot closing"><i class="bi bi-alarm-fill"></i></div>
                            <div class="timeline-lbl timeline-lbl--closing">2. Submission Deadline</div>
                            <div class="timeline-val">
                                <?= $deadline_text ?>
                                <?php if ($diff_days !== null && $is_open): ?>
                                    <span class="timeline-countdown <?= $is_urgent ? 'timeline-countdown--urgent' : 'timeline-countdown--normal' ?>">
                                        (<?= $diff_days > 0 ? "{$diff_days} day(s) remaining" : "Closing today" ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Step 3: Opening Date -->
                        <div class="timeline-step">
                            <div class="timeline-dot opening"><i class="bi bi-door-open-fill"></i></div>
                            <div class="timeline-lbl timeline-lbl--opening">3. Bid Opening Conference</div>
                            <div class="timeline-val">
                                <?= !empty($procurement['opening_date']) ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Announced' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.vp-left-col -->

        <!-- ════════════════════════════════════════════════════════
             RIGHT COLUMN (35%): CTA Card, Documents, BAC Info
             ════════════════════════════════════════════════════════ -->
        <div class="vp-right-col">

            <!-- 1. Bid Submission Action Box -->
            <div class="vp-cta-box <?= $has_bid ? 'has-bid' : '' ?>">
                <?php if ($has_bid): ?>
                    <div class="cta-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="cta-title">Proposal Already Submitted</div>
                    <div class="cta-desc">
                        You submitted a proposal for this project on <strong><?= date('M j, Y · g:i A', strtotime($my_bid['submission_date'])) ?></strong>.
                        Status: <strong class="cta-bid-status"><?= htmlspecialchars($my_bid['status']) ?></strong>.
                    </div>
                    <a href="my_bids.php" class="btn-view-submission">
                        <i class="bi bi-inbox-fill"></i> View in My Submitted Bids
                    </a>
                <?php elseif ($is_open): ?>
                    <?php if ($bidder_doc_status['is_valid']): ?>
                        <div class="cta-icon-wrap"><i class="bi bi-send-check-fill"></i></div>
                        <div class="cta-title"><?= htmlspecialchars($cta_title) ?></div>
                        <div class="cta-desc">
                            <?= htmlspecialchars($cta_desc) ?>
                        </div>
                        <a href="<?= htmlspecialchars($submit_url) ?>" class="btn-submit-proposal">
                            <i class="bi <?= $submit_icon ?>"></i> <?= htmlspecialchars($submit_label) ?> Now
                        </a>
                    <?php else: ?>
                        <div class="cta-icon-wrap cta-icon-wrap--locked"><i class="bi bi-exclamation-octagon-fill"></i></div>
                        <div class="cta-title cta-title--locked">Proposal Submission Locked</div>
                        <div class="cta-desc cta-desc--locked">
                            <?= htmlspecialchars($bidder_doc_status['summary_error']) ?> You cannot submit a bid proposal until your eligibility documents are updated.
                        </div>
                        <a href="settings.php?tab=documents" class="btn-submit-proposal btn-submit-proposal--danger">
                            <i class="bi bi-file-earmark-arrow-up"></i> Update Documents in Settings
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cta-icon-wrap cta-icon-wrap--closed"><i class="bi bi-lock-fill"></i></div>
                    <div class="cta-title">Bidding Closed</div>
                    <div class="cta-desc">
                        This opportunity is no longer accepting bid submissions. It has progressed to evaluation or contract award.
                    </div>
                    <a href="procurement.php" class="btn-browse-opportunities">
                        Browse Active Opportunities
                    </a>
                <?php endif; ?>
            </div>

            <!-- 2. Downloadable Bidding Documents Card (Original Only) -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip clr-dark"></i>
                        Bidding Documents &amp; Files
                    </div>
                    <span class="vp-card-count"><?= count($original_documents) ?> File(s)</span>
                </div>

                <div>
                    <?php if (count($original_documents) > 0): ?>
                        <div>
                            <?php foreach ($original_documents as $doc):
                                $fname = $doc['document_name'] ?? 'Bidding Document';
                                $furl  = resolve_proc_doc_url($doc['file_path'], 'bidder');
                                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                                $ficon = 'bi-file-earmark-pdf';
                                if (in_array($ext, ['doc','docx'])) $ficon = 'bi-file-earmark-word';
                                elseif (in_array($ext, ['xls','xlsx'])) $ficon = 'bi-file-earmark-excel';
                                elseif (in_array($ext, ['zip','rar'])) $ficon = 'bi-file-earmark-zip';
                            ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi <?= $ficon ?>"></i></div>
                                    <div class="doc-text-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></div>
                                        <div class="doc-date">Uploaded <?= !empty($doc['uploaded_at']) ? date('M j, Y', strtotime($doc['uploaded_at'])) : 'N/A' ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($furl) ?>" target="_blank" download class="doc-download-btn">
                                    <i class="bi bi-download"></i> Get
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="doc-empty">
                            <i class="bi bi-file-earmark-x doc-empty-icon"></i>
                            No original bidding documents attached yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Associated Documents Card (Associated Only) -->
            <div class="vp-card vp-card--mt">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-files clr-cyan"></i>
                        Associated Documents
                    </div>
                    <span class="vp-card-count"><?= count($associated_documents) ?> File(s)</span>
                </div>

                <div>
                    <?php if (count($associated_documents) > 0): ?>
                        <div>
                            <?php foreach ($associated_documents as $adoc):
                                $fname = $adoc['document_name'] ?? 'Associated Document';
                                $furl  = resolve_proc_doc_url($adoc['file_path'], 'bidder');
                                $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                                $ficon = 'bi-file-earmark-pdf';
                                if (in_array($ext, ['doc','docx'])) $ficon = 'bi-file-earmark-word';
                                elseif (in_array($ext, ['xls','xlsx'])) $ficon = 'bi-file-earmark-excel';
                                elseif (in_array($ext, ['zip','rar','7z'])) $ficon = 'bi-file-earmark-zip';
                                elseif (in_array($ext, ['jpg','jpeg','png'])) $ficon = 'bi-file-earmark-image';
                            ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi <?= $ficon ?> clr-cyan"></i></div>
                                    <div class="doc-text-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($fname) ?>">
                                            <?= htmlspecialchars($fname) ?>
                                            <span class="doc-associated-tag">Associated</span>
                                        </div>
                                        <div class="doc-date">Uploaded <?= !empty($adoc['uploaded_at']) ? date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) : 'N/A' ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($furl) ?>" target="_blank" download class="doc-download-btn doc-download-btn--cyan">
                                    <i class="bi bi-download"></i> Get
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="doc-empty">
                            <i class="bi bi-folder-x doc-empty-icon"></i>
                            No associated documents (bid bulletins, notices) published yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /.vp-right-col -->

    </div><!-- /.vp-grid-layout -->

</div>
</main>

<!-- Session alert -->
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
