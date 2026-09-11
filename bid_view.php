<?php
include "config/db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$user_dashboard_link = "login.php";
$user_logged_in = false;
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $user_logged_in = true;
    switch ($_SESSION['role']) {
        case "user":       $user_dashboard_link = "user/dashboard.php"; break;
        case "bidder":     $user_dashboard_link = "bidder/dashboard.php"; break;
        case "admin":      $user_dashboard_link = "admin/dashboard.php"; break;
        case "superadmin": $user_dashboard_link = "admin/dashboard.php"; break;
    }
}

$procurement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($procurement_id === 0) {
    header("Location: bid_schedule.php");
    exit();
}

// 1. Procurement
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status NOT IN ('draft','cancelled') LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    header("Location: bid_schedule.php");
    exit();
}

// 2. Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_res = $lot_stmt->get_result();
$lots = []; $total_lots_abc = 0;
while ($l = $lots_res->fetch_assoc()) { $lots[] = $l; $total_lots_abc += (float)$l['abc']; }
$lot_stmt->close();

if (!function_exists('resolve_proc_doc_url')) {
    function resolve_proc_doc_url($file_path, $context = 'root') {
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

// 3. Documents (Categorized into Original and Associated)
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$all_docs = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$doc_stmt->close();

$original_documents   = [];
$associated_documents = [];
foreach ($all_docs as $d) {
    if (($d['document_category'] ?? 'original') === 'associated') {
        $associated_documents[] = $d;
    } else {
        $original_documents[] = $d;
    }
}
// Existing Official Bidding Documents section displays only original documents
$documents = $original_documents;

$p_status = strtolower($procurement['status'] ?? 'open');
$is_open  = $p_status === 'open';

$diff_days = null; $is_urgent = false; $deadline_text = 'To Be Announced';
if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($is_open && $diff_days <= 3 && $diff_days >= 0) $is_urgent = true;
}

$status_pill_class = in_array($p_status, ['open','closed','awarded','cancelled'])
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- Custom CSS: base -> shared components -> page-specific -->
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/pages/bid_view.css">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
</head>
<body class="bview-page">

<?php render_public_navbar('procurement', $user_logged_in, $user_dashboard_link); ?>

<!-- ── Breadcrumb + Hero ── -->
<div class="bview-breadcrumb-bar">
    <div class="bview-breadcrumb-inner">
        <div class="bview-breadcrumbs">
            <a href="index.php"><i class="bi bi-house-fill"></i> Home</a>
            <span>/</span>
            <a href="bid_schedule.php"><i class="bi bi-calendar3"></i> Bid Schedule</a>
            <span>/</span>
            <span><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'Project Details') ?></span>
        </div>

        <div class="bview-hero">
            <div class="bview-hero-top">
                <div class="bview-badges">
                    <span class="bview-pill ref">
                        <i class="bi bi-hash"></i> <?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'SLSU-BAC') ?>
                    </span>
                    <span class="bview-pill <?= $status_pill_class ?>">
                        <i class="bi bi-circle-fill status-dot"></i> <?= strtoupper($p_status) ?>
                    </span>
                    <?php if ($is_urgent): ?>
                        <span class="bview-pill urgent">
                            <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="bview-hero-sub">
                    <i class="bi bi-building"></i> SLSU &mdash; BAC
                </span>
            </div>

            <h1 class="bview-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

            <div class="bview-metrics">
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-cash-stack"></i> Approved Budget (ABC)</div>
                    <div class="bview-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-briefcase"></i> Procurement Mode</div>
                    <div class="bview-metric-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                    <div class="bview-metric-val bview-metric-val--sm"><?= $deadline_text ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                    <div class="bview-metric-val bview-metric-val--sm">
                        <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ── Two-Column Content ── -->
<div class="bview-content">

    <!-- ── LEFT: Overview, Lots, Documents ── -->
    <div>

        <!-- Project Overview -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-info-circle ic-dark"></i> Project Overview
                </div>
            </div>
            <div class="bv-card-body">
                <div class="spec-grid">
                    <div class="spec-item">
                        <div class="spec-lbl">SLSU Ref. No.</div>
                        <div class="spec-val"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-lbl">Procurement Mode</div>
                        <div class="spec-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                    </div>
                    <div class="spec-item spec-item--full">
                        <div class="spec-lbl">Project Title</div>
                        <div class="spec-val spec-val--title"><?= htmlspecialchars($procurement['title']) ?></div>
                    </div>
                    <div class="spec-item spec-item--full">
                        <div class="spec-lbl">Approved Budget for the Contract (ABC)</div>
                        <div class="spec-val spec-val--abc">
                            ₱<?= number_format((float)$procurement['abc'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="desc-label">Description</div>
                <div class="desc-box">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed description specified for this procurement. Please consult the official bidding documents.') ?>
                </div>
            </div>
        </div>

        <!-- Lots -->
        <?php if (!empty($lots)): ?>
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-boxes ic-green"></i> Project Lots &amp; Components
                </div>
                <span class="bv-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body bv-card-body--flush">
                <div class="table-scroll">
                    <table class="lots-table">
                        <thead>
                            <tr>
                                <th class="col-lotnum-th">Lot #</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th class="ta-right">ABC</th>
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
                                <td colspan="3" class="lots-total-label">Total ABC:</td>
                                <td class="lots-total-abc">
                                    ₱<?= number_format($total_lots_abc, 2) ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documents (Original Only) -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-file-earmark-arrow-down ic-blue"></i> Official Bidding Documents
                </div>
                <span class="bv-card-count"><?= count($original_documents) ?> File<?= count($original_documents) != 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body">
                <?php if (!empty($original_documents)): ?>
                    <?php foreach ($original_documents as $doc):
                        $dl_url = resolve_proc_doc_url($doc['file_path'], 'root');
                    ?>
                    <div class="doc-row">
                        <div class="doc-row-info">
                            <div class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                            <div class="doc-row-text">
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
                        No original bidding documents uploaded yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Associated Documents (Associated Only) -->
        <div class="bv-card bv-card--mt">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-paperclip ic-cyan"></i> Associated Documents
                </div>
                <span class="bv-card-count"><?= count($associated_documents) ?> File<?= count($associated_documents) != 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body">
                <?php if (!empty($associated_documents)): ?>
                    <?php foreach ($associated_documents as $adoc):
                        $adl_url = resolve_proc_doc_url($adoc['file_path'], 'root');
                        $aext    = strtolower(pathinfo($adoc['document_name'], PATHINFO_EXTENSION));
                        $aicon   = 'bi-file-earmark-pdf';
                        if (in_array($aext, ['doc','docx'])) $aicon = 'bi-file-earmark-word';
                        elseif (in_array($aext, ['xls','xlsx'])) $aicon = 'bi-file-earmark-excel';
                        elseif (in_array($aext, ['zip','rar','7z'])) $aicon = 'bi-file-earmark-zip';
                        elseif (in_array($aext, ['jpg','jpeg','png'])) $aicon = 'bi-file-earmark-image';
                    ?>
                    <div class="doc-row">
                        <div class="doc-row-info">
                            <div class="doc-icon doc-icon--blue"><i class="bi <?= $aicon ?>"></i></div>
                            <div class="doc-row-text">
                                <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                    <?= htmlspecialchars($adoc['document_name']) ?>
                                    <span class="badge-associated-pill">Associated</span>
                                </div>
                                <div class="doc-meta">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn doc-dl-btn--blue">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="doc-empty">
                        <i class="bi bi-folder-x doc-empty-icon"></i>
                        No associated documents (bulletins, addenda, notices) published yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── RIGHT: CTA + Schedule ── -->
    <div>

        <!-- Participation CTA -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-shield-check ic-dark"></i> Supplier Participation
                </div>
            </div>
            <div class="bv-card-body">
                <?php if ($user_logged_in): ?>
                    <div class="cta-box cta-box--success">
                        <i class="bi bi-person-check-fill cta-icon cta-icon--success"></i>
                        <div class="cta-title">You're Logged In</div>
                        <p class="cta-desc">Access your bidder portal to view submission requirements and eligibility details.</p>
                        <a href="<?= htmlspecialchars($user_dashboard_link) ?>" class="cta-btn">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <div class="cta-box">
                        <i class="bi bi-person-badge cta-icon cta-icon--default"></i>
                        <div class="cta-title">Interested in Bidding?</div>
                        <p class="cta-desc">
                            Register as an accredited supplier to participate in electronic bidding and submit proposals.
                        </p>
                        <a href="register.php" class="cta-btn">
                            <i class="bi bi-person-plus"></i> Register as Bidder
                        </a>
                        <a href="login.php" class="cta-subtle-link">
                            Already registered? <u>Login here</u>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bidding Schedule -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-calendar-check ic-green"></i> Schedule of Activities
                </div>
            </div>
            <div class="bv-card-body">
                <div class="tl-list">
                    <?php if (!empty($procurement['posting_date'])): ?>
                    <div class="tl-step">
                        <div class="tl-dot"></div>
                        <div class="tl-lbl">Advertisement / Posting</div>
                        <div class="tl-date"><?= date('F j, Y', strtotime($procurement['posting_date'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($procurement['pre_bid_date'])): ?>
                    <div class="tl-step">
                        <div class="tl-dot"></div>
                        <div class="tl-lbl">Pre-Bid Conference</div>
                        <div class="tl-date"><?= date('F j, Y · g:i A', strtotime($procurement['pre_bid_date'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="tl-step">
                        <div class="tl-dot tl-dot--warn"></div>
                        <div class="tl-lbl tl-lbl--warn">Deadline for Submission</div>
                        <div class="tl-date"><?= $deadline_text ?></div>
                    </div>

                    <div class="tl-step">
                        <div class="tl-dot tl-dot--info"></div>
                        <div class="tl-lbl">Bid Opening</div>
                        <div class="tl-date">
                            <?= !empty($procurement['opening_date']) ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back link -->
        <a href="bid_schedule.php" class="btn-back-outline">
            <i class="bi bi-arrow-left"></i> Back to Bid Schedule
        </a>

    </div>
</div>

<!-- ── Footer ── -->
<?php require_once __DIR__ . '/includes/footer.php'; render_public_footer(); ?>

</body>
</html>
