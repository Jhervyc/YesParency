<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// 1. Fetch Procurement Details
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    header("Location: procurement.php?error=not_found");
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

// 3. Fetch Uploaded Documents
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$docs_res = $doc_stmt->get_result();
$documents = [];
while ($d = $docs_res->fetch_assoc()) {
    $documents[] = $d;
}
$doc_stmt->close();

// 4. Handle Document Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc'])) {
    $doc_id = intval($_POST['doc_id']);
    $d = $conn->prepare("SELECT file_path, document_name FROM procurement_documents WHERE id = ? AND procurement_id = ?");
    $d->bind_param("ii", $doc_id, $procurement_id);
    $d->execute();
    $doc_row = $d->get_result()->fetch_assoc();
    $d->close();

    if ($doc_row) {
        if (file_exists($doc_row['file_path'])) {
            @unlink($doc_row['file_path']);
        }
        $del = $conn->prepare("DELETE FROM procurement_documents WHERE id = ?");
        $del->bind_param("i", $doc_id);
        if ($del->execute()) {
            $doc_name = $doc_row['document_name'] ?? "#$doc_id";
            audit_log(
                $conn,
                'PROCUREMENT_DOCUMENT_DELETED',
                'procurements',
                $procurement_id,
                "Deleted procurement document: {$doc_name}",
                ['document_id' => $doc_id, 'document_name' => $doc_name],
                null
            );
            $_SESSION['alert_success'] = "Document removed successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to remove document record.";
        }
        $del->close();
    } else {
        $_SESSION['alert_error'] = "Document not found.";
    }
    header("Location: review_procurement.php?id=" . $procurement_id);
    exit();
}

// 5. Handle Edit Procurement (with optional new file uploads)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_procurement'])) {
    $title          = trim($_POST['title'] ?? '');
    $slsu       = trim($_POST['slsu_ref_no'] ?? '');
    $mode           = trim($_POST['procurement_mode'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $abc            = floatval($_POST['abc'] ?? 0);
    $posting_date   = $_POST['posting_date']  ?: null;
    $closing_date   = $_POST['closing_date']  ?: null;
    $opening_date   = $_POST['opening_date']  ?: null;

    $upd = $conn->prepare("
        UPDATE procurements
        SET title = ?, slsu_ref_no = ?, procurement_mode = ?,
            description = ?, abc = ?,
            posting_date = ?, closing_date = ?, opening_date = ?
        WHERE id = ?
    ");
    $upd->bind_param("ssssdsssi",
        $title, $slsu, $mode,
        $description, $abc,
        $posting_date, $closing_date, $opening_date,
        $procurement_id
    );

    if ($upd->execute()) {
        $uploaded_new_docs = [];
        // Handle new file uploads
        if (!empty($_FILES['new_documents']['name'][0])) {
            $upload_dir = "../uploads/procurements/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $ins = $conn->prepare("INSERT INTO procurement_documents (procurement_id, document_category, document_name, file_path) VALUES (?, 'original', ?, ?)");
            foreach ($_FILES['new_documents']['tmp_name'] as $i => $tmp) {
                if ($_FILES['new_documents']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $orig   = basename($_FILES['new_documents']['name'][$i]);
                $unique = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
                $target = $upload_dir . $unique;
                if (move_uploaded_file($tmp, $target)) {
                    $ins->bind_param("iss", $procurement_id, $orig, $target);
                    $ins->execute();
                    $uploaded_new_docs[] = $orig;
                }
            }
            $ins->close();
        }

        // Audit log procurement update
        audit_log(
            $conn,
            'PROCUREMENT_UPDATED',
            'procurements',
            $procurement_id,
            "Updated procurement project: {$title}",
            [
                'title' => $procurement['title'],
                'slsu_ref_no' => $procurement['slsu_ref_no'],
                'procurement_mode' => $procurement['procurement_mode'],
                'description' => $procurement['description'],
                'abc' => (float)$procurement['abc'],
                'posting_date' => $procurement['posting_date'],
                'closing_date' => $procurement['closing_date'],
                'opening_date' => $procurement['opening_date']
            ],
            [
                'title' => $title,
                'slsu_ref_no' => $slsu,
                'procurement_mode' => $mode,
                'description' => $description,
                'abc' => $abc,
                'posting_date' => $posting_date,
                'closing_date' => $closing_date,
                'opening_date' => $opening_date,
                'new_documents' => $uploaded_new_docs
            ]
        );

        $_SESSION['alert_success'] = "Procurement updated successfully.";
    } else {
        $_SESSION['alert_error'] = "Failed to update procurement.";
    }
    $upd->close();
    header("Location: review_procurement.php?id=" . $procurement_id);
    exit();
}

// 6. Handle Final Publish Trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_publish'])) {
    if (empty($lots)) {
        $_SESSION['alert_error'] = "Cannot publish a procurement with zero lots. Please add at least one lot first.";
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }

    $pub_stmt = $conn->prepare("UPDATE procurements SET status = 'open' WHERE id = ?");
    $pub_stmt->bind_param("i", $procurement_id);

    if ($pub_stmt->execute()) {
        $pub_stmt->close();

        // Audit log procurement publish
        audit_log(
            $conn,
            'PROCUREMENT_STATUS_CHANGED',
            'procurements',
            $procurement_id,
            "Published procurement project: {$procurement['title']} (draft -> open)",
            ['status' => $procurement['status'] ?? 'draft'],
            ['status' => 'open']
        );

        $_SESSION['alert_success'] = "Procurement has been successfully published and is now open for bidding!";
        header("Location: procurement.php");
        exit();
    } else {
        $pub_stmt->close();
        $_SESSION['alert_error'] = "Failed to update procurement status.";
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }
}

// Status & Deadline Computations
$p_status = strtolower($procurement['status'] ?? 'draft');
$is_open  = ($p_status === 'open');

$diff_days     = null;
$deadline_text = 'To Be Announced';

if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
}

$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#eef0ed';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#6c776e';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review &amp; Publish: <?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-review-procurement.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Top Navigation Bar & Breadcrumbs ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span class="vp-breadcrumbs-current">Review &amp; Publish</span>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Procurements
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <?php if (!empty($procurement['slsu_ref_no'])): ?>
                    <span class="hero-pill ref" onclick="copyPhilgeps('<?= htmlspecialchars($procurement['slsu_ref_no']) ?>')" title="Click to copy Reference No.">
                        <i class="bi bi-hash"></i> REF: <?= htmlspecialchars($procurement['slsu_ref_no']) ?>
                        <i class="bi bi-copy ref-copy-icon"></i>
                    </span>
                <?php endif; ?>

                <span class="hero-pill mode">
                    <i class="bi bi-sliders"></i> <?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?>
                </span>

                <span class="hero-pill <?= $p_status ?>">
                    <i class="bi bi-circle-fill status-dot-icon"></i> <?= strtoupper($p_status) ?>
                </span>
            </div>

            <span class="hero-status-note">
                <i class="bi bi-building"></i> Southern Luzon State University
            </span>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-currency-exchange"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-boxes"></i> Associated Lots</div>
                <div class="vp-hero-metric-val"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-file-earmark-text"></i> Documents Attached</div>
                <div class="vp-hero-metric-val"><?= count($documents) ?> File<?= count($documents) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--deadline">
                    <?= !empty($procurement['closing_date']) ? date('M j, Y · g:i A', strtotime($procurement['closing_date'])) : 'Not Set' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Specifications, Lots & Documents ── -->
        <div class="vp-left-col">

            <!-- 1. Project Overview & Description -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle clr-dark"></i>
                        <span>Project Overview &amp; Description</span>
                    </div>
                </div>
                <div class="vp-card-body">

                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-box field-span-2">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val field-strong-lg"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-box field-span-2">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val field-abc">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                        </div>
                    </div>

                    <div class="desc-label">Description / Scope of Work</div>
                    <div class="desc-text-box">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed technical specification summary specified for this procurement package.') ?>
                    </div>

                </div>
            </div>

            <!-- 2. Lots Breakdown -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-boxes clr-forest"></i>
                        <span>Project Lots &amp; Components</span>
                    </div>
                    <div class="card-head-actions">
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></span>
                        <a href="manage_lots.php?id=<?= $procurement_id ?>" class="vp-back-link vp-back-link--sm">
                            <i class="bi bi-pencil-square"></i> Manage Lots
                        </a>
                    </div>
                </div>
                <div class="vp-card-body p-0">
                    <?php if (!empty($lots)): ?>
                        <div class="table-scroll-x">
                            <table class="lots-data-table">
                                <thead>
                                    <tr>
                                        <th class="col-lot-narrow">Lot No.</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th class="col-abc-narrow">ABC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td><span class="lot-badge">Lot <?= htmlspecialchars($lot['lot_number']) ?></span></td>
                                            <td class="field-strong"><?= htmlspecialchars($lot['lot_title']) ?></td>
                                            <td class="lots-desc-cell"><?= htmlspecialchars($lot['description'] ?: '—') ?></td>
                                            <td class="lots-abc-cell">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" class="text-right">Total Lots Approved Budget (ABC)</td>
                                            <td class="lots-total-abc-cell">
                                                ₱<?= number_format($total_lots_abc, 2) ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-lots-box">
                            <i class="bi bi-exclamation-triangle no-lots-icon"></i>
                            <div class="no-lots-title">No Lots Configured Yet</div>
                            <div class="no-lots-desc">You must add at least one lot before this procurement can be published.</div>
                            <a href="manage_lots.php?id=<?= $procurement_id ?>" class="btn-publish-main btn-publish-main--inline">
                                <i class="bi bi-plus-circle"></i> Add Project Lots Now
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Downloadable Bidding Documents -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-folder2 clr-dark"></i>
                        <span>Bidding Documents &amp; Attachments</span>
                    </div>
                    <div class="card-head-actions">
                        <span class="vp-card-count"><?= count($documents) ?> File<?= count($documents) !== 1 ? 's' : '' ?></span>
                        <button type="button" onclick="openEditModal()" class="vp-back-link vp-back-link--sm vp-back-link--btn">
                            <i class="bi bi-cloud-upload"></i> Upload Files
                        </button>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($documents)): ?>
                        <div class="docs-cards-grid">
                            <?php foreach ($documents as $doc): ?>
                                <div class="doc-item-card">
                                    <div class="doc-item-left">
                                        <div class="doc-file-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                                        <div class="doc-file-info">
                                            <div class="doc-file-name" title="<?= htmlspecialchars($doc['document_name']) ?>">
                                                <?= htmlspecialchars($doc['document_name']) ?>
                                            </div>
                                            <div class="doc-file-sub">
                                                <?= date('M j, Y · g:i A', strtotime($doc['uploaded_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="doc-view-btn">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="docs-empty">
                            <i class="bi bi-file-earmark-x docs-empty-icon"></i>
                            No official bidding documents uploaded yet. You can attach documents via the Edit Procurement modal.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Actions, Schedule Timeline & Admin Info ── -->
        <div class="vp-right-col">

            <!-- Card 1: Review & Publishing Action Center -->
            <div class="vp-card">
                <div class="vp-card-head vp-card-head--dark">
                    <div class="vp-card-title">
                        <i class="bi bi-send-check"></i>
                        <span>Publishing &amp; Action Center</span>
                    </div>
                    <span class="status-badge-pill">
                        <?= strtoupper($p_status) ?>
                    </span>
                </div>
                <div class="vp-card-body">
                    <div class="action-card-body">

                        <div class="status-info-box">
                            <?php if ($p_status === 'draft'): ?>
                                <i class="bi bi-info-circle-fill clr-forest"></i>
                                <strong>Draft Mode:</strong> This procurement is not visible to public users or bidders. Verify all details, lots, and documents before publishing.
                            <?php else: ?>
                                <i class="bi bi-check-circle-fill clr-forest"></i>
                                <strong>Active:</strong> This procurement is published and publicly visible.
                            <?php endif; ?>
                        </div>

                        <?php if ($p_status === 'draft'): ?>
                            <form method="POST" action="" id="publishForm" class="m-0">
                                <input type="hidden" name="confirm_publish" value="1">
                                <button type="button" class="btn-publish-main" onclick="openPublishConfirm()" <?= empty($lots) ? 'disabled title="Add at least 1 lot first"' : '' ?>>
                                    <i class="bi bi-send-check-fill"></i> Confirm &amp; Publish Project
                                </button>
                            </form>
                        <?php endif; ?>

                        <button type="button" onclick="openEditModal()" class="btn-action-outline">
                            <i class="bi bi-pencil-square"></i> Edit Procurement Details
                        </button>

                        <a href="manage_lots.php?id=<?= $procurement_id ?>" class="btn-action-outline">
                            <i class="bi bi-boxes"></i> Manage / Edit Lots
                        </a>

                    </div>
                </div>
            </div>

            <!-- Card 2: Procurement Milestones & Schedule Timeline -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-event clr-forest"></i>
                        <span>Procurement Milestones</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="vp-timeline">
                        
                        <!-- 1. Posting Date -->
                        <?php
                            $has_posted = !empty($procurement['posting_date']);
                            $post_passed = $has_posted && (strtotime($procurement['posting_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $post_passed ? 'passed' : ($has_posted ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $post_passed ? 'check-lg' : 'megaphone' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">1. Posting Date</span>
                                    <span class="timeline-badge <?= $post_passed ? 'past' : 'future' ?>"><?= $post_passed ? 'Published' : 'Scheduled' ?></span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_posted ? date('F j, Y', strtotime($procurement['posting_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Submission Deadline -->
                        <?php
                            $has_closing = !empty($procurement['closing_date']);
                            $close_passed = $has_closing && (strtotime($procurement['closing_date']) <= time());
                            $close_current = $has_closing && !$close_passed && $is_open;
                        ?>
                        <div class="timeline-item <?= $close_passed ? 'passed' : ($close_current ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $close_passed ? 'check-lg' : 'clock-history' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">2. Submission Deadline</span>
                                    <span class="timeline-badge <?= $close_passed ? 'past' : ($close_current ? 'current' : 'future') ?>">
                                        <?= $close_passed ? 'Closed' : ($close_current ? 'Active' : 'Pending') ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_closing ? date('F j, Y · g:i A', strtotime($procurement['closing_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Bid Opening Date -->
                        <?php
                            $has_opening = !empty($procurement['opening_date']);
                            $open_passed = $has_opening && (strtotime($procurement['opening_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $open_passed ? 'passed' : ($has_opening ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $open_passed ? 'check-lg' : 'envelope-open' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">3. Bid Opening &amp; Evaluation</span>
                                    <span class="timeline-badge <?= $open_passed ? 'past' : 'future' ?>">
                                        <?= $open_passed ? 'Completed' : 'Upcoming' ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_opening ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
</main>

<!-- ========================= -->
<!-- EDIT PROCUREMENT MODAL    -->
<!-- ========================= -->
<div id="editProcModal" class="modal-backdrop" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-dialog-box">

        <!-- Modal Header -->
        <div class="modal-head">
            <h3><i class="bi bi-pencil-square"></i> Edit Procurement Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal Form -->
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="edit_procurement" value="1">
            
            <div class="modal-body">

                <!-- Title -->
                <div class="form-group-custom">
                    <label>Project Title <span class="required-mark">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-folder2"></i>
                        <input type="text" name="title" value="<?= htmlspecialchars($procurement['title']) ?>" required placeholder="e.g. Supply and Delivery of Office Equipment">
                    </div>
                </div>

                <!-- SLSU + Mode -->
                <div class="form-grid-2col-14">
                    <div class="form-group-custom">
                        <label>SLSU Reference No.</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-hash"></i>
                            <input type="text" name="slsu_ref_no" value="<?= htmlspecialchars($procurement['slsu_ref_no'] ?? '') ?>" placeholder="e.g. 10928374">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Procurement Mode</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-sliders"></i>
                            <select name="procurement_mode">
                                <?php
                                $modes = ['Public Bidding', 'Competitive Bidding', 'Small Value Procurement', 'Shopping', 'Negotiated Procurement', 'Direct Contracting', 'Limited Source Bidding', 'Repeat Order'];
                                foreach ($modes as $m): ?>
                                    <option value="<?= $m ?>" <?= $procurement['procurement_mode'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ABC -->
                <div class="form-group-custom">
                    <label>Approved Budget for the Contract (ABC) <span class="required-mark">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-currency-exchange"></i>
                        <input type="number" name="abc" step="0.01" min="0" value="<?= htmlspecialchars($procurement['abc']) ?>" required placeholder="0.00">
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group-custom">
                    <label>Description / Technical Specifications</label>
                    <div class="form-input-wrap">
                        <textarea name="description" rows="3" class="textarea-flush" placeholder="Brief description and technical scope..."><?= htmlspecialchars($procurement['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Dates Grid -->
                <div class="form-grid-auto-180">
                    <div class="form-group-custom">
                        <label>Posting Date</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar"></i>
                            <input type="date" name="posting_date" value="<?= htmlspecialchars($procurement['posting_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Submission Deadline</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar-x"></i>
                            <input type="datetime-local" name="closing_date" value="<?= $procurement['closing_date'] ? date('Y-m-d\TH:i', strtotime($procurement['closing_date'])) : '' ?>">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Bid Opening Date</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar-check"></i>
                            <input type="datetime-local" name="opening_date" value="<?= $procurement['opening_date'] ? date('Y-m-d\TH:i', strtotime($procurement['opening_date'])) : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Existing Documents Section -->
                <div class="section-divider-top">
                    <label class="section-label-row">
                        <i class="bi bi-paperclip"></i> Existing Attached Documents (<?= count($documents) ?>)
                    </label>

                    <?php if (!empty($documents)): ?>
                        <div class="docs-list-col" id="existingDocsList">
                            <?php foreach ($documents as $md): ?>
                                <div class="modal-doc-row" id="doc-row-<?= $md['id'] ?>">
                                    <div class="modal-doc-left">
                                        <i class="bi bi-file-earmark-text modal-doc-icon"></i>
                                        <a href="<?= htmlspecialchars($md['file_path']) ?>" target="_blank" class="modal-doc-link">
                                            <?= htmlspecialchars($md['document_name']) ?>
                                        </a>
                                    </div>
                                    <button type="button" onclick="removeDoc(<?= $md['id'] ?>)" class="modal-doc-remove-btn">
                                        <i class="bi bi-trash3"></i> Remove
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="docs-empty-note">No documents uploaded yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Upload New Files -->
                <div>
                    <label class="upload-label">Upload Additional Documents</label>
                    <!-- Hidden accumulator — this is what actually gets submitted -->
                    <input type="file" id="newDocsReal" name="new_documents[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" class="visually-hidden-input">
                    <!-- Visible trigger -->
                    <label for="newDocsPicker" class="upload-drop-zone">
                        <i class="bi bi-cloud-arrow-up upload-drop-icon"></i>
                        <span class="upload-drop-text">Click to browse files (PDF, DOCX, XLSX, PNG, JPG)</span>
                        <span class="upload-drop-sub">Multiple files — pick as many times as needed</span>
                        <input type="file" id="newDocsPicker" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" class="visually-hidden-input" onchange="stackNewDocs(this)">
                    </label>
                    <div id="newFilePreview" class="new-file-preview-list"></div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-foot">
                <button type="button" onclick="closeEditModal()" class="vp-back-link vp-back-link--btn">
                    Cancel
                </button>
                <button type="submit" class="btn-publish-main btn-publish-main--modal">
                    <i class="bi bi-check2"></i> Save Changes
                </button>
            </div>

        </form>

    </div>
</div>

<!-- ========================= -->
<!-- PUBLISH CONFIRM MODAL     -->
<!-- ========================= -->
<div id="publishConfirmModal" class="modal-backdrop" onclick="if(event.target===this)closePublishConfirm()">
    <div class="modal-dialog-box modal-dialog-box--confirm">

        <div class="confirm-icon-circle">
            <i class="bi bi-send-check-fill"></i>
        </div>

        <h3 class="confirm-title">Publish Procurement?</h3>
        <p class="confirm-desc">
            This procurement will become active and publicly visible to all accredited bidders and observers.
        </p>

        <div class="confirm-title-pill">
            <?= htmlspecialchars($procurement['title']) ?>
        </div>

        <div class="confirm-actions-row">
            <button type="button" onclick="closePublishConfirm()" class="vp-back-link vp-back-link--confirm">
                Cancel
            </button>
            <button type="button" onclick="document.getElementById('publishForm').submit()" class="btn-publish-main btn-publish-main--confirm">
                <i class="bi bi-send-check-fill"></i> Yes, Publish Now
            </button>
        </div>

    </div>
</div>

<!-- Hidden delete doc form -->
<form id="deleteDocForm" method="POST" action="" hidden>
    <input type="hidden" name="delete_doc" value="1">
    <input type="hidden" id="deleteDocId" name="doc_id" value="">
</form>

<!-- Toast alert notifications -->
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
    // Toast Alert auto-hide
    const toast = document.getElementById('toastAlert');
    if (toast) {
        setTimeout(() => {
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    // Modal Triggers
    function openEditModal()  { document.getElementById('editProcModal').classList.add('open'); }
    function closeEditModal() { document.getElementById('editProcModal').classList.remove('open'); }

    function openPublishConfirm()  { document.getElementById('publishConfirmModal').classList.add('open'); }
    function closePublishConfirm() { document.getElementById('publishConfirmModal').classList.remove('open'); }

    // Remove Document Handler
    function removeDoc(docId) {
        if (!confirm('Are you sure you want to remove this document?')) return;
        document.getElementById('deleteDocId').value = docId;
        document.getElementById('deleteDocForm').submit();
    }

    // File stacking for new attachments
    function stackNewDocs(picker) {
        const real = document.getElementById('newDocsReal');
        const dt   = new DataTransfer();

        // Keep existing accumulated files
        Array.from(real.files).forEach(f => dt.items.add(f));

        // Add new picks, skip exact name duplicates
        const existing = new Set(Array.from(real.files).map(f => f.name));
        Array.from(picker.files).forEach(f => {
            if (!existing.has(f.name)) dt.items.add(f);
        });

        real.files   = dt.files;
        picker.value = '';
        renderNewDocs();
    }

    function renderNewDocs() {
        const real    = document.getElementById('newDocsReal');
        const preview = document.getElementById('newFilePreview');
        preview.innerHTML = '';

        Array.from(real.files).forEach((f, idx) => {
            const size = f.size > 1048576
                ? (f.size / 1048576).toFixed(1) + ' MB'
                : (f.size / 1024).toFixed(0) + ' KB';

            const row = document.createElement('div');
            row.className = 'new-file-row';
            row.innerHTML = `
                <i class="bi bi-file-earmark-check-fill new-file-icon"></i>
                <span class="new-file-name" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
                <span class="new-file-size">${size}</span>
                <button type="button" onclick="removeNewDoc(${idx})" class="new-file-remove-btn" title="Remove">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            `;
            preview.appendChild(row);
        });
    }

    function removeNewDoc(idx) {
        const real = document.getElementById('newDocsReal');
        const dt   = new DataTransfer();
        Array.from(real.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
        real.files = dt.files;
        renderNewDocs();
    }

    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Copy Reference Number
    function copyPhilgeps(text) {
        navigator.clipboard.writeText(text).then(() => {
            const toast = document.createElement('div');
            toast.className = 'toast-alert success';
            toast.innerHTML = '<i class="bi bi-check-circle-fill toast-icon--success"></i> SLSU Reference No. copied to clipboard!';
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('hide');
                setTimeout(() => toast.remove(), 400);
            }, 2500);
        });
    }
</script>

</body>
</html>
