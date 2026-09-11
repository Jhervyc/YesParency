<?php
include("utils/protect-page.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$user_id        = (int)$_SESSION['user_id'];
$user_role      = $_SESSION['role'] ?? 'user';

// Secretariat & Admin Permission Check
if (!isset($_SESSION['admin_type']) && isset($conn) && isset($_SESSION['user_id'])) {
    $at_stmt = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    if ($at_stmt) {
        $at_stmt->bind_param("i", $_SESSION['user_id']);
        $at_stmt->execute();
        $at_row = $at_stmt->get_result()->fetch_assoc();
        $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
        $at_stmt->close();
    }
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$can_manage_docs = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

if (!function_exists('resolve_proc_doc_url')) {
    function resolve_proc_doc_url($file_path, $context = 'admin') {
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

// ── Handle Document Upload & Delete POST Actions ──
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_upload_associated_doc'])) {
        if (!$can_manage_docs) {
            $_SESSION['alert_error'] = "Unauthorized. Only Secretariat or Administrators can upload associated documents.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $doc_name = trim($_POST['document_name'] ?? '');
        if (empty($doc_name)) {
            $_SESSION['alert_error'] = "Please provide a document title/name.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        if (empty($_FILES['associated_document']['name']) || $_FILES['associated_document']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['alert_error'] = "Please select a valid file to upload.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $file = $_FILES['associated_document'];
        $max_size = 50 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            $_SESSION['alert_error'] = "File size exceeds the 50MB limit.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $orig_name = basename($file['name']);
        $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'zip', 'rar', '7z', 'png', 'jpg', 'jpeg'];
        if (!in_array($file_ext, $allowed_exts)) {
            $_SESSION['alert_error'] = "Invalid file type. Allowed formats: " . implode(', ', $allowed_exts);
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $upload_dir = "../uploads/procurements/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $clean_orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
        $unique_filename = time() . '_' . uniqid() . '_' . $clean_orig;
        $target_path = $upload_dir . $unique_filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $ins = $conn->prepare("INSERT INTO procurement_documents (procurement_id, document_category, document_name, file_path) VALUES (?, 'associated', ?, ?)");
            $ins->bind_param("iss", $procurement_id, $doc_name, $target_path);
            if ($ins->execute()) {
                $inserted_id = $ins->insert_id;
                audit_log(
                    $conn,
                    'PROCUREMENT_DOCUMENT_UPLOADED',
                    'procurement_documents',
                    $inserted_id,
                    "Uploaded associated document '{$doc_name}' for procurement project #{$procurement_id}",
                    [],
                    [
                        'procurement_id'    => $procurement_id,
                        'document_name'     => $doc_name,
                        'document_category' => 'associated',
                        'file_path'         => $target_path
                    ]
                );
                $_SESSION['alert_success'] = "Associated document '{$doc_name}' uploaded successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to record document in database.";
            }
            $ins->close();
        } else {
            $_SESSION['alert_error'] = "Failed to save the uploaded file to server.";
        }

        header("Location: view_procurement.php?id=" . $procurement_id);
        exit();
    }

    if (isset($_POST['action_delete_associated_doc'])) {
        if (!$can_manage_docs) {
            $_SESSION['alert_error'] = "Unauthorized. Only Secretariat or Administrators can remove associated documents.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $doc_id = intval($_POST['doc_id'] ?? 0);
        $d_stmt = $conn->prepare("SELECT id, document_name, file_path, document_category FROM procurement_documents WHERE id = ? AND procurement_id = ? LIMIT 1");
        $d_stmt->bind_param("ii", $doc_id, $procurement_id);
        $d_stmt->execute();
        $doc_row = $d_stmt->get_result()->fetch_assoc();
        $d_stmt->close();

        if ($doc_row) {
            if (!empty($doc_row['file_path']) && file_exists($doc_row['file_path'])) {
                @unlink($doc_row['file_path']);
            }
            $del = $conn->prepare("DELETE FROM procurement_documents WHERE id = ?");
            $del->bind_param("i", $doc_id);
            if ($del->execute()) {
                audit_log(
                    $conn,
                    'PROCUREMENT_DOCUMENT_DELETED',
                    'procurement_documents',
                    $doc_id,
                    "Deleted {$doc_row['document_category']} document '{$doc_row['document_name']}' from procurement #{$procurement_id}",
                    ['document_name' => $doc_row['document_name'], 'document_category' => $doc_row['document_category']],
                    []
                );
                $_SESSION['alert_success'] = "Document '{$doc_row['document_name']}' removed successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to remove document record.";
            }
            $del->close();
        } else {
            $_SESSION['alert_error'] = "Document not found.";
        }

        header("Location: view_procurement.php?id=" . $procurement_id);
        exit();
    }
}

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

// Bid summary for this procurement
$bid_summary_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')   AS pending,
        SUM(status = 'submitted') AS submitted,
        SUM(status = 'rejected')  AS rejected,
        SUM(status = 'opened')    AS opened
    FROM bids WHERE procurement_id = ?
");
$bid_summary_stmt->bind_param("i", $procurement_id);
$bid_summary_stmt->execute();
$bid_summary = $bid_summary_stmt->get_result()->fetch_assoc();
$bid_summary_stmt->close();

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
$valid_status_pills = ['open', 'draft', 'closed', 'awarded', 'cancelled'];
$status_pill_class   = 'hero-pill--status-' . (in_array($p_status, $valid_status_pills) ? $p_status : 'open');
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
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-view-procurement.css">
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
                    <i class="bi bi-circle-fill status-dot-icon"></i> <?= strtoupper($p_status) ?>
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="flex-row-gap12">
                <?php if ($can_manage_docs): ?>
                    <button type="button" class="btn-associated-doc" onclick="openAssociatedDocModal()">
                        <i class="bi bi-paperclip"></i> Associated Documents
                    </button>
                <?php endif; ?>
                <span class="hero-status-note">
                    <i class="bi bi-building"></i> Southern Luzon State University - BAC
                </span>
            </div>
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
                <div class="vp-hero-metric-val hero-metric-val--sm"><?= $deadline_text ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val hero-metric-val--sm">
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
                            <div class="spec-field-val field-strong"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-item field-span-2">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val field-strong-lg"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-item field-span-2">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val field-abc">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
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
                            <i class="bi bi-boxes clr-forest"></i>
                            <span>Project Lots &amp; Components</span>
                        </div>
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="vp-card-body p-0">
                        <div class="table-scroll-x">
                            <table class="lots-table">
                                <thead>
                                    <tr>
                                        <th class="col-narrow-70">Lot #</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th class="text-right">Approved Budget (ABC)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td class="lots-col-num">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                                            <td class="lots-col-title"><?= htmlspecialchars($lot['lot_title'] ?? $lot['title'] ?? '—') ?></td>
                                            <td class="lots-col-desc"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                                            <td class="lots-col-abc">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                <tfoot>
                                    <tr class="lots-total-row">
                                        <td colspan="3" class="lots-total-label">Total Lots ABC:</td>
                                        <td class="lots-col-abc">
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
                        <i class="bi bi-file-earmark-arrow-down clr-blue-dark"></i>
                        <span>Official Bidding Documents &amp; Notices</span>
                    </div>
                    <span class="vp-card-count"><?= count($original_documents) ?> File<?= count($original_documents) != 1 ? 's' : '' ?></span>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($original_documents)): ?>
                        <?php foreach ($original_documents as $doc):
                            $dl_url = resolve_proc_doc_url($doc['file_path'], 'admin');
                        ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                                    <div class="doc-meta-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($doc['document_name']) ?>"><?= htmlspecialchars($doc['document_name']) ?></div>
                                        <div class="doc-meta-date">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
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
            <div class="vp-card mt-20">
                <div class="vp-card-head card-head-split">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip clr-cyan"></i>
                        <span>Associated Documents</span>
                    </div>
                    <div class="card-head-actions">
                        <span class="vp-card-count"><?= count($associated_documents) ?> File<?= count($associated_documents) != 1 ? 's' : '' ?></span>
                        <?php if ($can_manage_docs): ?>
                            <button type="button" class="btn-associated-doc" onclick="openAssociatedDocModal()">
                                <i class="bi bi-plus-circle"></i> Associated Documents
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($associated_documents)): ?>
                        <?php foreach ($associated_documents as $adoc):
                            $adl_url = resolve_proc_doc_url($adoc['file_path'], 'admin');
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
                                    <div class="doc-meta-wrap">
                                        <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                            <?= htmlspecialchars($adoc['document_name']) ?>
                                            <span class="badge-associated-pill">Associated</span>
                                        </div>
                                        <div class="doc-meta-date">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <div class="doc-actions-row">
                                    <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn doc-dl-btn--assoc">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <?php if ($can_manage_docs): ?>
                                        <form method="POST" class="form-inline-m0" onsubmit="return confirm('Are you sure you want to delete this associated document?');">
                                            <input type="hidden" name="action_delete_associated_doc" value="1">
                                            <input type="hidden" name="doc_id" value="<?= (int)$adoc['id'] ?>">
                                            <button type="submit" class="doc-delete-btn" title="Delete Associated Document">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
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

            <!-- 1. Bid Summary -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-inbox-fill clr-dark"></i>
                        <span>Bid Summary</span>
                    </div>
                    <a href="bid_submissions.php?procurement_id=<?= $procurement_id ?>" class="bid-summary-view-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="vp-card-body">
                    <?php
                    $total     = (int)($bid_summary['total']     ?? 0);
                    $pending   = (int)($bid_summary['pending']   ?? 0);
                    $submitted = (int)($bid_summary['submitted'] ?? 0);
                    $rejected  = (int)($bid_summary['rejected']  ?? 0);
                    $opened    = (int)($bid_summary['opened']    ?? 0);
                    ?>
                    <div class="bid-stat-grid">
                        <div class="bid-stat-box bid-stat-box--total">
                            <div class="bid-stat-num bid-stat-num--dark"><?= $total ?></div>
                            <div class="bid-stat-lbl">Total Bids</div>
                        </div>
                        <div class="bid-stat-box bid-stat-box--pending">
                            <div class="bid-stat-num bid-stat-num--amber"><?= $pending ?></div>
                            <div class="bid-stat-lbl">Pending</div>
                        </div>
                        <div class="bid-stat-box bid-stat-box--submitted">
                            <div class="bid-stat-num bid-stat-num--blue"><?= $submitted ?></div>
                            <div class="bid-stat-lbl">Submitted</div>
                        </div>
                        <div class="bid-stat-box bid-stat-box--opened">
                            <div class="bid-stat-num bid-stat-num--forest"><?= $opened ?></div>
                            <div class="bid-stat-lbl">Opened</div>
                        </div>
                    </div>
                    <?php if ($total === 0): ?>
                        <div class="bid-empty">
                            <i class="bi bi-inbox bid-empty-icon"></i>
                            No bids received yet.
                        </div>
                    <?php else: ?>
                        <a href="bid_submissions.php?procurement_id=<?= $procurement_id ?>" class="bid-review-btn">
                            <i class="bi bi-eye-fill"></i> Review Bid Submissions
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Bidding Timeline & Schedule -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-check clr-forest"></i>
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

<?php if ($can_manage_docs): ?>
<!-- ══ ASSOCIATED DOCUMENTS MODAL (Secretariat / Admin) ══ -->
<div id="associatedDocModal" class="modal-backdrop" onclick="if(event.target===this)closeAssociatedDocModal()">
    <div class="modal-dialog-box">
        <div class="modal-head">
            <h3><i class="bi bi-paperclip"></i> Associated Documents</h3>
            <button type="button" class="modal-close-btn" onclick="closeAssociatedDocModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="view_procurement.php?id=<?= $procurement_id ?>">
            <input type="hidden" name="action_upload_associated_doc" value="1">
            <div class="modal-body">
                <p class="modal-intro-text">
                    Upload an associated document for this procurement project (e.g., Supplemental Bid Bulletin, Minutes of Pre-Bid Conference, Addendum, or Postponement Notice).
                </p>

                <div class="form-group-custom">
                    <label for="docNameInput">Document Name / Title <span class="required-mark">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-tag"></i>
                        <input type="text" id="docNameInput" name="document_name" required placeholder="e.g., Supplemental Bid Bulletin No. 1">
                    </div>
                </div>

                <div class="form-group-custom">
                    <label>Document File <span class="required-mark">*</span></label>
                    <label for="associatedFileInput" id="fileDropZone" class="file-drop-zone">
                        <i class="bi bi-cloud-arrow-up file-drop-icon"></i>
                        <span id="fileChosenLabel" class="file-chosen-label">Click to select file (PDF, DOCX, XLSX, etc.)</span>
                        <span class="file-drop-sub">Supported: PDF, Word, Excel, Images, ZIP — Max 50MB</span>
                        <input type="file" id="associatedFileInput" name="associated_document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.zip,.rar,.7z,.png,.jpg,.jpeg" class="visually-hidden-input" onchange="handleAssociatedFileSelect(this)">
                    </label>
                </div>

                <div class="info-note-box">
                    <i class="bi bi-info-circle-fill info-note-icon"></i>
                    <span class="info-note-text">
                        This document will be saved with category: <strong>associated</strong>. It will appear under the Associated Documents panel for bidders and public visitors.
                    </span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" onclick="closeAssociatedDocModal()" class="vp-back-link modal-cancel-btn">Cancel</button>
                <button type="submit" class="btn-associated-doc modal-submit-btn">
                    <i class="bi bi-cloud-arrow-up"></i> Upload Document
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Toast alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill toast-icon--success"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill toast-icon--error"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
function openAssociatedDocModal() {
    const modal = document.getElementById('associatedDocModal');
    if (modal) modal.classList.add('open');
}
function closeAssociatedDocModal() {
    const modal = document.getElementById('associatedDocModal');
    if (modal) modal.classList.remove('open');
}
function handleAssociatedFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        document.getElementById('fileChosenLabel').textContent = file.name + ' (' + sizeMb + ' MB)';
        const docNameInput = document.getElementById('docNameInput');
        if (docNameInput && !docNameInput.value.trim()) {
            const nameWithoutExt = file.name.replace(/\.[^/.]+$/, "");
            docNameInput.value = nameWithoutExt;
        }
    }
}
// Toast auto-hide
const toast = document.getElementById('toastAlert');
if (toast) {
    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}
</script>

</body>
</html>
