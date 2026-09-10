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
$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#e4f5ea';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#1f7a3d';

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
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Breadcrumb & Back Bar ── */
        .vp-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .vp-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #88968d;
            font-weight: 600;
        }

        .vp-breadcrumbs a {
            color: #1f7a3d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .vp-breadcrumbs a:hover {
            text-decoration: underline;
        }

        .vp-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #06251b;
            font-size: 12.5px;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #eaeeec;
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
        }

        /* ── Project Header Hero Banner ── */
        .vp-hero-card {
            background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #14593f 100%);
            border-radius: 20px;
            padding: 26px 30px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(6, 37, 27, 0.16);
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .vp-hero-card::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.15) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .vp-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .vp-hero-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hero-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: .3px;
        }

        .hero-pill.ref {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all .15s;
        }
        .hero-pill.ref:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .hero-pill.urgent {
            background: rgba(235, 87, 87, 0.25);
            border: 1px solid rgba(235, 87, 87, 0.5);
            color: #ff8a80;
            animation: pulseHeroUrgent 2s infinite;
        }

        .hero-pill.submitted {
            background: rgba(33, 150, 83, 0.25);
            border: 1px solid rgba(33, 150, 83, 0.5);
            color: #81c784;
        }

        @keyframes pulseHeroUrgent {
            0% { opacity: 1; }
            50% { opacity: 0.75; }
            100% { opacity: 1; }
        }

        .vp-hero-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.3;
            margin-bottom: 20px;
            letter-spacing: -0.3px;
        }

        /* Hero Key Metrics Row */
        .vp-hero-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .vp-hero-metric-item {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 12px 16px;
        }

        .vp-hero-metric-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #d1e5db;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .vp-hero-metric-lbl i {
            color: #ffc107;
        }

        .vp-hero-metric-val {
            font-size: 17px;
            font-weight: 800;
            color: #ffffff;
            font-family: 'Space Grotesk', sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .vp-hero-metric-val.gold {
            color: #ffc107;
        }

        /* ── Main Two-Column Layout (65% + 35%) ── */
        .vp-grid-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 30px;
        }

        /* CRITICAL: Grid children must have min-width: 0 to prevent overflow */
        .vp-left-col,
        .vp-right-col {
            min-width: 0;
            overflow: hidden;
        }

        @media (max-width: 1040px) {
            .vp-grid-layout {
                grid-template-columns: 1fr;
            }
        }

        /* ── Section Cards ── */
        .vp-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .vp-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .vp-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vp-card-count {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .vp-card-body {
            padding: 20px;
        }

        /* ── Key Spec Fields Grid ── */
        .spec-fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 18px;
        }

        @media (max-width: 600px) {
            .spec-fields-grid {
                grid-template-columns: 1fr;
            }
        }

        .spec-field-box {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 11px 14px;
        }

        .spec-field-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }

        .spec-field-val {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
        }

        .desc-text-box {
            background: #fbfdfc;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 16px;
            font-size: 12.5px;
            color: #3b4d42;
            line-height: 1.65;
            white-space: pre-line;
        }

        /* ── Lots Table ── */
        .lots-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            text-align: left;
        }

        .lots-data-table thead th {
            background: #fafcfb;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #55665a;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-bottom: 1px solid #edf1ee;
        }

        .lots-data-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f4f7f5;
            vertical-align: middle;
        }

        .lots-data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .lots-data-table tfoot td {
            background: #f7faf8;
            padding: 12px 16px;
            border-top: 2px solid #eaeeec;
            font-weight: 800;
            color: #06251b;
        }

        .lot-badge {
            background: #eef7f1;
            color: #1f7a3d;
            font-weight: 800;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        /* ── Milestones Timeline ── */
        .vp-timeline {
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            padding-left: 28px;
        }

        .vp-timeline::before {
            content: '';
            position: absolute;
            top: 6px;
            bottom: 6px;
            left: 10px;
            width: 2px;
            background: #e0e8e3;
        }

        .timeline-step {
            position: relative;
        }

        .timeline-dot {
            position: absolute;
            left: -28px;
            top: 2px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: #1f7a3d;
        }

        .timeline-dot.closing {
            border-color: #c23b3b;
            color: #c23b3b;
        }

        .timeline-dot.opening {
            border-color: #2F6FED;
            color: #2F6FED;
        }

        .timeline-lbl {
            font-size: 11px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .timeline-val {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
            margin-top: 2px;
        }

        /* ── Right Sidebar Action CTA Card ── */
        .vp-cta-box {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            text-align: center;
        }

        .vp-cta-box.has-bid {
            background: linear-gradient(180deg, #f2faf4 0%, #ffffff 100%);
            border-color: #cde6d5;
        }

        .cta-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px;
        }

        .vp-cta-box.has-bid .cta-icon-wrap {
            background: #219653;
            color: #ffffff;
        }

        .cta-title {
            font-size: 16px;
            font-weight: 800;
            color: #06251b;
            margin-bottom: 6px;
        }

        .cta-desc {
            font-size: 12px;
            color: #6c776e;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .btn-submit-proposal {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #06251b;
            color: #ffc107;
            font-size: 13px;
            font-weight: 700;
            padding: 12px 18px;
            border-radius: 12px;
            text-decoration: none;
            transition: all .2s ease;
            width: 100%;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.15);
        }

        .btn-submit-proposal:hover {
            background: #144937;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(6, 37, 27, 0.22);
        }

        .btn-view-submission {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #e7eefe;
            color: #2F6FED;
            font-size: 12.5px;
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .15s ease;
            width: 100%;
        }

        .btn-view-submission:hover {
            background: #d4e4fd;
        }

        /* ── Documents List ── */
        .doc-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #f4f7f5;
            transition: background .15s ease;
            min-width: 0;
            overflow: hidden;
        }

        .doc-item-row:last-child {
            border-bottom: none;
        }

        .doc-item-row:hover {
            background: #f9fbf9;
        }

        .doc-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1 1 0;
            min-width: 0;
            overflow: hidden;
        }

        .doc-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .doc-title {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-date {
            font-size: 10.5px;
            color: #88968d;
            margin-top: 1px;
        }

        .doc-download-btn {
            background: #f0f4f2;
            color: #06251b;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 7px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all .15s ease;
            flex-shrink: 0;
        }

        .doc-download-btn:hover {
            background: #06251b;
            color: #ffc107;
        }

        /* ── Secretariat Support Card ── */
        .support-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f9fbf9;
            font-size: 12px;
            color: #55665a;
        }

        .support-info-item:last-child {
            border-bottom: none;
        }

        .support-info-item i {
            color: #1f7a3d;
            font-size: 14px;
            width: 18px;
        }
    </style>
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
            <span style="color:#06251b;">Ref #<?= htmlspecialchars($procurement['philgeps_ref_no'] ?? $procurement['id']) ?></span>
        </div>

        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Opportunities
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <!-- PhilGEPS Reference Badge -->
                <span class="hero-pill ref" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($procurement['philgeps_ref_no'] ?? '') ?>'); alert('Reference number copied!');" title="Click to copy reference number">
                    <i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A') ?>
                    <i class="bi bi-clipboard" style="font-size:10px; margin-left:2px; opacity:0.8;"></i>
                </span>

                <!-- Procurement Status Pill -->
                <span class="hero-pill" style="background:<?= $status_badge_bg ?>; color:<?= $status_badge_fg ?>; font-weight:800;">
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

            <div style="font-size:11.5px; color:#d1e5db;">
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
                <div class="vp-hero-metric-val" style="font-size:14px; font-weight:700;">
                    <?= !empty($procurement['closing_date']) ? date('M j, Y · g:i A', strtotime($procurement['closing_date'])) : 'TBD' ?>
                </div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-door-open"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val" style="font-size:14px; font-weight:700;">
                    <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'TBD' ?>
                </div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers"></i> Structure &amp; Docs</div>
                <div class="vp-hero-metric-val" style="font-size:14px; font-weight:700;">
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
                        <i class="bi bi-file-earmark-text" style="color:#1f7a3d;"></i>
                        Project Specifications &amp; Overview
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">PhilGEPS Reference No.</div>
                            <div class="spec-field-val" style="font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A') ?>
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
                            <div class="spec-field-val" style="color:#06251b; font-family:'Space Grotesk',sans-serif;">
                                ₱<?= number_format($procurement['abc'], 2) ?>
                            </div>
                        </div>

                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Bidding Status</div>
                            <div class="spec-field-val">
                                <span style="background:<?= $status_badge_bg ?>; color:<?= $status_badge_fg ?>; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:800;">
                                    <?= strtoupper($p_status) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($procurement['description'])): ?>
                        <div style="margin-top:10px;">
                            <div class="spec-field-lbl" style="margin-bottom:6px;">Scope of Work / Project Description</div>
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
                        <i class="bi bi-layers" style="color:#2F6FED;"></i>
                        Lots Breakdown &amp; Allotted ABC
                    </div>
                    <span class="vp-card-count"><?= count($lots) ?> Lot(s)</span>
                </div>

                <div>
                    <?php if (count($lots) > 0): ?>
                        <div style="overflow-x:auto;">
                            <table class="lots-data-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Lot #</th>
                                        <th>Lot Title &amp; Description</th>
                                        <th style="text-align:right; width:150px;">Allocated ABC</th>
                                        <th style="text-align:right; width:90px;">Share (%)</th>
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
                                            <div style="font-weight:700; color:#1a1a1a; font-size:12.5px;">
                                                <?= htmlspecialchars($lot['lot_title']) ?>
                                            </div>
                                            <?php if (!empty($lot['description'])): ?>
                                                <div style="font-size:11px; color:#6c776e; margin-top:2px;">
                                                    <?= htmlspecialchars($lot['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                            ₱<?= number_format($lot['abc'], 2) ?>
                                        </td>
                                        <td style="text-align:right; color:#88968d; font-weight:600; font-size:11px;">
                                            <?= $lot_share ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" style="text-align:right;">Total ABC Across All Lots</td>
                                        <td style="text-align:right; font-family:'Space Grotesk',sans-serif; font-size:13.5px;">
                                            ₱<?= number_format($total_lots_abc ?: $procurement['abc'], 2) ?>
                                        </td>
                                        <td style="text-align:right;">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding:32px 20px; text-align:center; color:#88968d; font-size:12px;">
                            <i class="bi bi-layers" style="font-size:28px; color:#c7d2cb; display:block; margin-bottom:6px;"></i>
                            This procurement project is bid as a single consolidated contract without lot sub-items.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Key Milestone Dates & Schedule -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar3" style="color:#e67e22;"></i>
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
                            <div class="timeline-lbl" style="color:#c23b3b;">2. Submission Deadline</div>
                            <div class="timeline-val">
                                <?= $deadline_text ?>
                                <?php if ($diff_days !== null && $is_open): ?>
                                    <span style="font-size:11px; font-weight:700; color:<?= $is_urgent ? '#c23b3b' : '#1f7a3d' ?>; margin-left:6px;">
                                        (<?= $diff_days > 0 ? "{$diff_days} day(s) remaining" : "Closing today" ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Step 3: Opening Date -->
                        <div class="timeline-step">
                            <div class="timeline-dot opening"><i class="bi bi-door-open-fill"></i></div>
                            <div class="timeline-lbl" style="color:#2F6FED;">3. Bid Opening Conference</div>
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
                        Status: <strong style="text-transform:uppercase; color:#1f7a3d;"><?= htmlspecialchars($my_bid['status']) ?></strong>.
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
                        <div class="cta-icon-wrap" style="background:#fee2e2; color:#dc2626;"><i class="bi bi-exclamation-octagon-fill"></i></div>
                        <div class="cta-title" style="color:#991b1b;">Proposal Submission Locked</div>
                        <div class="cta-desc" style="color:#b91c1c;">
                            <?= htmlspecialchars($bidder_doc_status['summary_error']) ?> You cannot submit a bid proposal until your eligibility documents are updated.
                        </div>
                        <a href="settings.php?tab=documents" class="btn-submit-proposal" style="background:#dc2626; color:#ffffff;">
                            <i class="bi bi-file-earmark-arrow-up"></i> Update Documents in Settings
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cta-icon-wrap" style="background:#ffebee; color:#c23b3b;"><i class="bi bi-lock-fill"></i></div>
                    <div class="cta-title">Bidding Closed</div>
                    <div class="cta-desc">
                        This opportunity is no longer accepting bid submissions. It has progressed to evaluation or contract award.
                    </div>
                    <a href="procurement.php" class="side-panel-link" style="justify-content:center; padding:8px 14px;">
                        Browse Active Opportunities
                    </a>
                <?php endif; ?>
            </div>

            <!-- 2. Downloadable Bidding Documents Card (Original Only) -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip" style="color:#06251b;"></i>
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
                                    <div style="min-width:0; overflow:hidden; flex:1 1 0;">
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
                        <div style="padding:28px 16px; text-align:center; color:#88968d; font-size:12px;">
                            <i class="bi bi-file-earmark-x" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:4px;"></i>
                            No original bidding documents attached yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Associated Documents Card (Associated Only) -->
            <div class="vp-card" style="margin-top: 18px;">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-files" style="color:#0284c7;"></i>
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
                                    <div class="doc-icon"><i class="bi <?= $ficon ?>" style="color:#0284c7;"></i></div>
                                    <div style="min-width:0; overflow:hidden; flex:1 1 0;">
                                        <div class="doc-title" title="<?= htmlspecialchars($fname) ?>">
                                            <?= htmlspecialchars($fname) ?>
                                            <span style="font-size:10px; font-weight:700; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; margin-left:4px;">Associated</span>
                                        </div>
                                        <div class="doc-date">Uploaded <?= !empty($adoc['uploaded_at']) ? date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) : 'N/A' ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($furl) ?>" target="_blank" download class="doc-download-btn" style="background:#0284c7;">
                                    <i class="bi bi-download"></i> Get
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding:28px 16px; text-align:center; color:#88968d; font-size:12px;">
                            <i class="bi bi-folder-x" style="font-size:24px; color:#c7d2cb; display:block; margin-bottom:4px;"></i>
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
