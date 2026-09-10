<?php
include("utils/protect-page.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$user_id        = (int)$_SESSION['user_id'];
$user_role      = $_SESSION['role'] ?? 'user';

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
    header("Location: procurement.php");
    exit();
}

// 2. Fetch User & Bidder Profile Status
$prof_stmt = $conn->prepare("SELECT profile_id, business_name, application_status FROM bidder_profiles WHERE user_id = ? LIMIT 1");
$prof_stmt->bind_param("i", $user_id);
$prof_stmt->execute();
$user_profile = $prof_stmt->get_result()->fetch_assoc();
$prof_stmt->close();

$app_status = strtolower($user_profile['application_status'] ?? 'none');

// 3. Fetch Associated Lots
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
    function resolve_proc_doc_url($file_path, $context = 'user') {
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

// 4. Fetch Downloadable Documents (Categorized into Original and Associated)
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

$p_status = strtolower($procurement['status'] ?? 'open');
$is_open  = ($p_status === 'open');

// Deadline & Urgency Calculation
$diff_days     = null;
$is_urgent     = false;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared styles -->
    <link rel="stylesheet" href="../style.css">
    <!-- Dashboard styles -->
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
            cursor: pointer;
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
        }

        .hero-pill.urgent {
            background: rgba(235, 87, 87, 0.25);
            border: 1px solid rgba(235, 87, 87, 0.5);
            color: #ff8a80;
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

        .spec-field-item {
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            padding: 12px 14px;
        }

        .spec-field-lbl {
            font-size: 10.5px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 3px;
        }

        .spec-field-val {
            font-size: 13px;
            font-weight: 700;
            color: #1a2a20;
            line-height: 1.3;
        }

        /* ── Lots List Table ── */
        .lots-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .lots-table th {
            background: #f4f8f5;
            padding: 10px 14px;
            font-size: 11px;
            font-weight: 700;
            color: #495b50;
            text-transform: uppercase;
            letter-spacing: .03em;
            border-bottom: 1px solid #eaeeec;
            text-align: left;
        }

        .lots-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f2f5f3;
            vertical-align: middle;
        }

        .lots-table tr:last-child td {
            border-bottom: none;
        }

        /* ── Timeline Activity List ── */
        .timeline-list {
            position: relative;
            padding-left: 24px;
        }

        .timeline-list::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e0e8e4;
        }

        .timeline-step {
            position: relative;
            margin-bottom: 18px;
        }

        .timeline-step:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -24px;
            top: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid #1f7a3d;
        }

        .timeline-step.urgent .timeline-dot {
            border-color: #e67e22;
        }

        .timeline-lbl {
            font-size: 12.5px;
            font-weight: 700;
            color: #1a2a20;
            margin-bottom: 2px;
        }

        .timeline-date {
            font-size: 11.5px;
            color: #728277;
        }

        /* ── Documents List ── */
        .doc-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            margin-bottom: 10px;
            gap: 12px;
        }

        .doc-item-row:last-child {
            margin-bottom: 0;
        }

        .doc-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .doc-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #eef5f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .doc-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #18261e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-dl-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #06251b;
            color: #ffc107;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            text-decoration: none;
            transition: all .15s;
            flex-shrink: 0;
        }

        .doc-dl-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
        }

        .badge-associated-pill {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            margin-left: 6px;
            vertical-align: middle;
        }

        /* ── Action / Accreditation Sidebar Card ── */
        .accredit-cta-box {
            background: #f7faf8;
            border: 1.5px dashed #c0d8cb;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
        }

        .accredit-cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: #06251b;
            color: #ffc107;
            font-weight: 700;
            font-size: 13px;
            padding: 12px 18px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .15s ease;
            margin-top: 12px;
        }

        .accredit-cta-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
            transform: translateY(-1px);
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
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <span>/</span>
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'Project Details') ?></span>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Opportunities
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref">
                    <i class="bi bi-hash"></i> SLSU: <?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'SLSU-BAC') ?>
                </span>
                <span class="hero-pill" style="background:<?= $status_badge_bg ?>; color:<?= $status_badge_fg ?>;">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                    </span>
                <?php endif; ?>
            </div>
            <span style="font-size:12px; color:#d1e5db;">
                <i class="bi bi-building"></i> Southern Luzon State University - BAC
            </span>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <!-- Key Metrics Row -->
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-briefcase"></i> Procurement Mode</div>
                <div class="vp-hero-metric-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val" style="font-size:15px;"><?= $deadline_text ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val" style="font-size:15px;">
                    <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Layout (65% + 35%) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Specifications, Lots & Documents ── -->
        <div class="vp-left-col">

            <!-- 1. General Project Details -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle" style="color:#06251b;"></i>
                        <span>Project Overview &amp; Description</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    
                    <div class="spec-fields-grid">
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b;"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-item" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b; line-height:1.4;"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-item" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val" style="font-size:16px; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#1f7a3d;">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                        </div>
                    </div>

                    <div style="font-size:11px; font-weight:700; color:#88968d; text-transform:uppercase; margin-bottom:6px;">Description</div>
                    <div style="background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; padding:16px; font-size:13px; color:#2d3a32; line-height:1.6; white-space:pre-wrap;">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed technical specification summary specified for this procurement package. Please consult the official bidding documents below.') ?>
                    </div>

                </div>
            </div>

            <!-- 2. Lots Breakdown -->
            <?php if (!empty($lots)): ?>
                <div class="vp-card">
                    <div class="vp-card-head">
                        <div class="vp-card-title">
                            <i class="bi bi-boxes" style="color:#1f7a3d;"></i>
                            <span>Project Lots &amp; Components</span>
                        </div>
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="vp-card-body" style="padding:0;">
                        <div style="overflow-x:auto;">
                            <table class="lots-table">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">Lot #</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th style="text-align:right;">Approved Budget (ABC)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td style="font-weight:700; color:#06251b;">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                                            <td style="font-weight:600; color:#1a2a20;"><?= htmlspecialchars($lot['lot_title'] ?? $lot['title'] ?? '—') ?></td>
                                            <td style="color:#63736a; font-size:12px;"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                                            <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                <tfoot>
                                    <tr style="background:#f9fbf9; border-top:2px solid #eaeeec; font-weight:700;">
                                        <td colspan="3" style="text-align:right; font-size:12px; color:#495b50;">Total Lots ABC:</td>
                                        <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                            ₱<?= number_format((float)$total_lots_abc, 2) ?>
                                        </td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3. Official Downloadable Documents (Original Category Only) -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-file-earmark-arrow-down" style="color:#1565c0;"></i>
                        <span>Official Bidding Documents &amp; Notices</span>
                    </div>
                    <span class="vp-card-count"><?= count($original_documents) ?> File<?= count($original_documents) != 1 ? 's' : '' ?></span>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($original_documents)): ?>
                        <?php foreach ($original_documents as $doc): 
                            $dl_url = resolve_proc_doc_url($doc['file_path'], 'user');
                        ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                                    <div style="min-width:0;">
                                        <div class="doc-title" title="<?= htmlspecialchars($doc['document_name']) ?>"><?= htmlspecialchars($doc['document_name']) ?></div>
                                        <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($dl_url) ?>" download class="doc-dl-btn">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                            <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                            No original bidding documents uploaded yet for this opportunity.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. Associated Documents (Associated Category Only) -->
            <div class="vp-card" style="margin-top:20px;">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip" style="color:#0284c7;"></i>
                        <span>Associated Documents</span>
                    </div>
                    <span class="vp-card-count"><?= count($associated_documents) ?> File<?= count($associated_documents) != 1 ? 's' : '' ?></span>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($associated_documents)): ?>
                        <?php foreach ($associated_documents as $adoc): 
                            $adl_url = resolve_proc_doc_url($adoc['file_path'], 'user');
                            $aext = strtolower(pathinfo($adoc['document_name'], PATHINFO_EXTENSION));
                            $aicon = 'bi-file-earmark-pdf';
                            if (in_array($aext, ['doc','docx'])) $aicon = 'bi-file-earmark-word';
                            elseif (in_array($aext, ['xls','xlsx'])) $aicon = 'bi-file-earmark-excel';
                            elseif (in_array($aext, ['zip','rar','7z'])) $aicon = 'bi-file-earmark-zip';
                            elseif (in_array($aext, ['jpg','jpeg','png'])) $aicon = 'bi-file-earmark-image';
                        ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi <?= $aicon ?>" style="color:#0284c7;"></i></div>
                                    <div style="min-width:0;">
                                        <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                            <?= htmlspecialchars($adoc['document_name']) ?>
                                            <span class="badge-associated-pill">Associated</span>
                                        </div>
                                        <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn" style="background:#0284c7; color:#ffffff;">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                            <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                            No associated documents (bid bulletins, addenda, notices) uploaded yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Accreditation CTA & Key Timelines ── -->
        <div class="vp-right-col">

            <!-- 1. Supplier Participation / Accreditation CTA -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-shield-check" style="color:#06251b;"></i>
                        <span>Supplier Participation</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if ($app_status === 'approved'): ?>
                        <div class="accredit-cta-box" style="background:#f2faf4; border-color:#b7e3c4;">
                            <i class="bi bi-patch-check-fill" style="font-size:28px; color:#219653; display:block; margin-bottom:6px;"></i>
                            <div style="font-size:13.5px; font-weight:800; color:#06251b; margin-bottom:4px;">Accredited Bidder Access</div>
                            <p style="font-size:12px; color:#55665a; margin-bottom:0;">
                                You are an accredited supplier. You can prepare and submit price proposal forms directly through the Bidder Portal.
                            </p>
                            <a href="../bidder/submit_bid.php?procurement_id=<?= $procurement_id ?>" class="accredit-cta-btn" style="background:#219653; color:#fff;">
                                <i class="bi bi-send-fill"></i> Submit Bid Proposal
                            </a>
                        </div>
                    <?php elseif ($app_status === 'pending'): ?>
                        <div class="accredit-cta-box" style="background:#fffbf0; border-color:#fae099;">
                            <i class="bi bi-hourglass-split" style="font-size:28px; color:#e67e22; display:block; margin-bottom:6px;"></i>
                            <div style="font-size:13.5px; font-weight:800; color:#06251b; margin-bottom:4px;">Accreditation Pending</div>
                            <p style="font-size:12px; color:#55665a; margin-bottom:0;">
                                Your supplier registration is currently undergoing evaluation by the BAC Secretariat. Once approved, proposal submission will be unlocked.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="accredit-cta-box">
                            <i class="bi bi-person-badge" style="font-size:28px; color:#06251b; display:block; margin-bottom:6px;"></i>
                            <div style="font-size:13.5px; font-weight:800; color:#06251b; margin-bottom:4px;">Interested in Bidding?</div>
                            <p style="font-size:12px; color:#55665a; margin-bottom:0;">
                                To participate in electronic bidding and submit tender proposals, your business must be registered as an accredited supplier.
                            </p>
                            <a href="bidder-registration.php" class="accredit-cta-btn">
                                <i class="bi bi-person-plus"></i> Register as Bidder
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Bidding Timeline & Schedule -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-check" style="color:#1f7a3d;"></i>
                        <span>Schedule of Activities</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="timeline-list">
                        
                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Advertisement / Posting Date</div>
                            <div class="timeline-date"><?= date('F j, Y', strtotime($procurement['created_at'])) ?></div>
                        </div>

                        <div class="timeline-step <?= $is_urgent ? 'urgent' : '' ?>">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Submission &amp; Receipt of Bids</div>
                            <div class="timeline-date"><?= $deadline_text ?></div>
                        </div>

                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Opening of Bid Proposals</div>
                            <div class="timeline-date">
                                <?= !empty($procurement['opening_date']) ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Announced' ?>
                            </div>
                        </div>

                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Bid Evaluation &amp; Post-Qualification</div>
                            <div class="timeline-date">Following Bid Opening Session</div>
                        </div>

                    </div>
                </div>
            </div>


        </div>

    </div>

</div>
</main>

</body>
</html>
