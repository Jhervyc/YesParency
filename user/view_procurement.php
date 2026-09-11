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
$status_pill_class = in_array($p_status, ['open','draft','closed','awarded','cancelled'])
    ? 'status-' . $p_status
    : 'status-open';
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
    <!-- Dashboard styles -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/pages/user-view-procurement.css">
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
                <span class="hero-pill <?= $status_pill_class ?>">
                    <i class="bi bi-circle-fill status-dot"></i> <?= strtoupper($p_status) ?>
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="vp-hero-sub">
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
                <div class="vp-hero-metric-val vp-hero-metric-val--sm"><?= $deadline_text ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--sm">
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
                        <i class="bi bi-info-circle clr-dark"></i>
                        <span>Project Overview &amp; Description</span>
                    </div>
                </div>
                <div class="vp-card-body">

                    <div class="spec-fields-grid">
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val spec-field-val--strong"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-item spec-field-item--full">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val spec-field-val--title"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-item spec-field-item--full">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val spec-field-val--abc">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                        </div>
                    </div>

                    <div class="desc-label">Description</div>
                    <div class="desc-box">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed technical specification summary specified for this procurement package. Please consult the official bidding documents below.') ?>
                    </div>

                </div>
            </div>

            <!-- 2. Lots Breakdown -->
            <?php if (!empty($lots)): ?>
                <div class="vp-card">
                    <div class="vp-card-head">
                        <div class="vp-card-title">
                            <i class="bi bi-boxes clr-green"></i>
                            <span>Project Lots &amp; Components</span>
                        </div>
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="vp-card-body vp-card-body--flush">
                        <div class="table-scroll">
                            <table class="lots-table">
                                <thead>
                                    <tr>
                                        <th class="col-lotnum-th">Lot #</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th class="ta-right">Approved Budget (ABC)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td class="lot-num-td">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                                            <td class="lot-title-td"><?= htmlspecialchars($lot['lot_title'] ?? $lot['title'] ?? '—') ?></td>
                                            <td class="lot-desc-td"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                                            <td class="lot-abc-td">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                <tfoot>
                                    <tr class="lots-total-row">
                                        <td colspan="3" class="lots-total-label">Total Lots ABC:</td>
                                        <td class="lots-total-abc">
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
                        <i class="bi bi-file-earmark-arrow-down clr-blue"></i>
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
                                    <div class="doc-text-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($doc['document_name']) ?>"><?= htmlspecialchars($doc['document_name']) ?></div>
                                        <div class="doc-meta">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($dl_url) ?>" download class="doc-dl-btn">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="doc-empty">
                            <i class="bi bi-folder-x doc-empty-icon"></i>
                            No original bidding documents uploaded yet for this opportunity.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. Associated Documents (Associated Category Only) -->
            <div class="vp-card vp-card--mt">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip clr-cyan"></i>
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
                                    <div class="doc-icon"><i class="bi <?= $aicon ?> clr-cyan"></i></div>
                                    <div class="doc-text-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                            <?= htmlspecialchars($adoc['document_name']) ?>
                                            <span class="badge-associated-pill">Associated</span>
                                        </div>
                                        <div class="doc-meta">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn doc-dl-btn--cyan">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="doc-empty">
                            <i class="bi bi-folder-x doc-empty-icon"></i>
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
                        <i class="bi bi-shield-check clr-dark"></i>
                        <span>Supplier Participation</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if ($app_status === 'approved'): ?>
                        <div class="accredit-cta-box accredit-cta-box--approved">
                            <i class="bi bi-patch-check-fill accredit-cta-icon accredit-cta-icon--success"></i>
                            <div class="accredit-cta-title">Accredited Bidder Access</div>
                            <p class="accredit-cta-desc">
                                You are an accredited supplier. You can prepare and submit price proposal forms directly through the Bidder Portal.
                            </p>
                            <a href="../bidder/submit_bid.php?procurement_id=<?= $procurement_id ?>" class="accredit-cta-btn accredit-cta-btn--green">
                                <i class="bi bi-send-fill"></i> Submit Bid Proposal
                            </a>
                        </div>
                    <?php elseif ($app_status === 'pending'): ?>
                        <div class="accredit-cta-box accredit-cta-box--pending">
                            <i class="bi bi-hourglass-split accredit-cta-icon accredit-cta-icon--warn"></i>
                            <div class="accredit-cta-title">Accreditation Pending</div>
                            <p class="accredit-cta-desc">
                                Your supplier registration is currently undergoing evaluation by the BAC Secretariat. Once approved, proposal submission will be unlocked.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="accredit-cta-box">
                            <i class="bi bi-person-badge accredit-cta-icon accredit-cta-icon--default"></i>
                            <div class="accredit-cta-title">Interested in Bidding?</div>
                            <p class="accredit-cta-desc">
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
                        <i class="bi bi-calendar-check clr-green"></i>
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
