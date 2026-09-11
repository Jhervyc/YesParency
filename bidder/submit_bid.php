<?php
include("utils/protect-page.php");
require_once("../utils/crypto.php");
require_once(__DIR__ . "/../admin/utils/audit_helper.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$bidder_id      = intval($_SESSION['user_id']);

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// ── SERVER-SIDE BIDDER DOCUMENT COMPLIANCE GUARD ────────────────────────────
$doc_status = check_bidder_documents_status($conn, $bidder_id);
if (!$doc_status['is_valid']) {
    $_SESSION['alert_error'] = "Bid submission blocked: " . $doc_status['summary_error'] . " Please update your documents in Settings.";
    header("Location: view_procurement.php?id=" . $procurement_id);
    exit();
}

// 1. Fetch Procurement Details
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status = 'open' LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    $_SESSION['alert_error'] = "This procurement is not available for bidding or is no longer open.";
    header("Location: procurement.php");
    exit();
}

// Route SVP / Shopping to the quotation submission page — they don't use encryption or bid-sessions
if (is_quotation_mode($procurement['procurement_mode'] ?? '')) {
    header("Location: submit_quotation.php?id=" . $procurement_id);
    exit();
}

// 2. Check for existing bid
$check_bid = $conn->prepare("SELECT id FROM bids WHERE bidder_id = ? AND procurement_id = ? LIMIT 1");
$check_bid->bind_param("ii", $bidder_id, $procurement_id);
$check_bid->execute();
$check_bid->store_result();

if ($check_bid->num_rows > 0) {
    $check_bid->close();
    $_SESSION['alert_error'] = "You have already submitted a proposal for this procurement.";
    header("Location: view_procurement.php?id=" . $procurement_id);
    exit();
}
$check_bid->close();

// 3. Fetch Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_result = $lot_stmt->get_result();
$lots = [];
$total_lots_abc = 0;
while ($row = $lots_result->fetch_assoc()) {
    $lots[] = $row;
    $total_lots_abc += (float)$row['abc'];
}
$lot_stmt->close();

// Deadline & Urgency Calculation
$diff_days     = null;
$is_urgent     = false;
$deadline_text = 'To Be Announced';

if (!empty($procurement['closing_date'])) {
    $dl_time       = strtotime($procurement['closing_date']);
    $diff_days     = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($diff_days <= 3 && $diff_days >= 0) {
        $is_urgent = true;
    }
}

// 4. Handle Form Submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bid'])) {
    $selected_lots = $_POST['selected_lots'] ?? [];
    $post_doc_check = check_bidder_documents_status($conn, $bidder_id);
    if (!$post_doc_check['is_valid']) {
        $errors[] = "Bid submission blocked: " . $post_doc_check['summary_error'];
    }

    if (empty($selected_lots)) {
        $errors[] = "Please select at least one lot you wish to bid on.";
    }

    if (!isset($_FILES['bid_receipt']) || $_FILES['bid_receipt']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please upload a valid Bid Receipt / Proof of Payment.";
    }

    // Validation for multiple files per lot
    foreach ($selected_lots as $lot_id) {
        $clean_lot_id = intval($lot_id);
        
        // Find lot number for clearer error messages
        $current_lot_num = $clean_lot_id;
        foreach ($lots as $l) {
            if ($l['id'] == $clean_lot_id) {
                $current_lot_num = $l['lot_number'];
                break;
            }
        }

        // Check Eligibility Docs
        $elig_names = array_filter($_FILES['eligibility_docs']['name'][$clean_lot_id] ?? []);
        if (empty($elig_names)) {
            $errors[] = "Missing Eligibility & Technical Documents for Lot #{$current_lot_num}.";
        }

        // Check Financial Docs
        $fin_names = array_filter($_FILES['financial_docs']['name'][$clean_lot_id] ?? []);
        if (empty($fin_names)) {
            $errors[] = "Missing Financial Proposal Form for Lot #{$current_lot_num}.";
        }
    }

    $upload_dir = "../uploads/bids/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            $bid_stmt = $conn->prepare("INSERT INTO bids (bidder_id, procurement_id, status) VALUES (?, ?, 'pending')");
            $bid_stmt->bind_param("ii", $bidder_id, $procurement_id);
            $bid_stmt->execute();
            $bid_id = $conn->insert_id;
            $bid_stmt->close();

            $lot_bid_stmt = $conn->prepare("INSERT INTO bid_lots (bid_id, lot_id) VALUES (?, ?)");
            foreach ($selected_lots as $lot_id) {
                $clean_lot_id = intval($lot_id);
                $lot_bid_stmt->bind_param("ii", $bid_id, $clean_lot_id);
                $lot_bid_stmt->execute();
            }
            $lot_bid_stmt->close();

            $doc_stmt = $conn->prepare("INSERT INTO bid_documents (bid_id, document_type, document_name, file_path) VALUES (?, ?, ?, ?)");

            // 1. Process Receipt File
            $receipt_file = $_FILES['bid_receipt'];
            $receipt_ext = strtolower(pathinfo($receipt_file['name'], PATHINFO_EXTENSION));
            $receipt_target = $upload_dir . "receipt_bid_" . $bid_id . "_" . time() . "." . $receipt_ext;

            if (move_uploaded_file($receipt_file['tmp_name'], $receipt_target)) {
                $uploaded_file_paths[] = $receipt_target;
                $doc_type = 'other';
                $orig_name = "Receipt - " . basename($receipt_file['name']);
                $doc_stmt->bind_param("isss", $bid_id, $doc_type, $orig_name, $receipt_target);
                $doc_stmt->execute();
            } else {
                throw new Exception("Failed to save Bid Receipt.");
            }

            // 2. Process Multiple Lot Files (Encrypted)
            foreach ($selected_lots as $lot_id) {
                $clean_lot_id = intval($lot_id);
                $types = [
                    'eligibility' => $_FILES['eligibility_docs'],
                    'financial'   => $_FILES['financial_docs']
                ];

                foreach ($types as $doc_type => $file_array) {
                    if (isset($file_array['name'][$clean_lot_id]) && is_array($file_array['name'][$clean_lot_id])) {
                        
                        foreach ($file_array['name'][$clean_lot_id] as $index => $raw_filename) {
                            if (empty($raw_filename)) continue;

                            $err_code = $file_array['error'][$clean_lot_id][$index];
                            if ($err_code === UPLOAD_ERR_OK) {
                                $orig_filename = basename($raw_filename);
                                $file_tmp = $file_array['tmp_name'][$clean_lot_id][$index];
                                $target = $upload_dir . bin2hex(random_bytes(16)) . ".enc";

                                $result = encryptFile($file_tmp, $target, $doc_type);

                                if ($result['success']) {
                                    $uploaded_file_paths[] = $target;
                                    $display_name = ucfirst($doc_type) . " ({$orig_filename})";
                                    $doc_stmt->bind_param("isss", $bid_id, $doc_type, $display_name, $target);
                                    $doc_stmt->execute();
                                } else {
                                    throw new Exception($result['message']);
                                }
                            } else {
                                throw new Exception("Error uploading a {$doc_type} document for Lot #{$clean_lot_id}.");
                            }
                        }
                    }
                }
            }
            $doc_stmt->close();

            $conn->commit();

            // ── Audit: BID_SUBMITTED (after successful commit) ──────────────────
            audit_log($conn, 'BID_SUBMITTED', 'bids', $bid_id,
                "Bidder #{$bidder_id} submitted bid #{$bid_id} for procurement #{$procurement_id} covering " . count($selected_lots) . " lot(s)",
                null,
                [
                    'bid_id'           => $bid_id,
                    'bidder_id'        => $bidder_id,
                    'procurement_id'   => $procurement_id,
                    'lots'             => array_values(array_map('intval', $selected_lots)),
                    'document_count'   => count($uploaded_file_paths),
                ]
            );

            // ── Audit: BID_DOCUMENT_UPLOADED (one entry per file type group) ────
            $doc_types_uploaded = array_unique(
                array_merge(['other'], array_keys(array_filter(['eligibility' => true, 'financial' => true]))
            ));
            foreach (['other', 'eligibility', 'financial'] as $dtype) {
                $dc = $conn->prepare("SELECT COUNT(*) FROM bid_documents WHERE bid_id = ? AND document_type = ?");
                $dc->bind_param("is", $bid_id, $dtype);
                $dc->execute();
                $dcnt = (int)$dc->get_result()->fetch_row()[0];
                $dc->close();
                if ($dcnt > 0) {
                    audit_log($conn, 'BID_DOCUMENT_UPLOADED', 'bids', $bid_id,
                        "Bid #{$bid_id}: {$dcnt} {$dtype} document(s) uploaded by bidder #{$bidder_id}",
                        null,
                        ['bid_id' => $bid_id, 'document_type' => $dtype, 'count' => $dcnt, 'bidder_id' => $bidder_id, 'procurement_id' => $procurement_id]
                    );
                }
            }

            $_SESSION['alert_success'] = "Your proposal and payment receipt were submitted and encrypted successfully!";
            header("Location: my_bids.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($uploaded_file_paths as $path) {
                if (file_exists($path)) @unlink($path);
            }
            $errors[] = "Submission failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Proposal — <?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-submit-bid.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>

<?php 
$topbar_title = 'Submit Bid Proposal';
include("components/topbar.php"); 
?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Top Navigation & Breadcrumbs -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <a href="view_procurement.php?id=<?= $procurement_id ?>"><?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 30, '...')) ?></a>
            <span>/</span>
            <span class="vp-breadcrumbs-current">Submit Proposal</span>
        </div>
        <a href="view_procurement.php?id=<?= $procurement_id ?>" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Procurement Details
        </a>
    </div>

    <!-- Project Hero Header Banner -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref" onclick="copyRefNo('<?= htmlspecialchars($procurement['slsu_ref_no'] ?? '') ?>')" title="Click to copy reference number">
                    <i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($procurement['slsu_ref_no'] ?? 'N/A') ?>
                    <i class="bi bi-copy ref-copy-icon"></i>
                </span>
                <span class="hero-pill mode">
                    <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($procurement['procurement_mode'] ?? 'Public Bidding') ?>
                </span>
                <span class="hero-pill open">
                    <i class="bi bi-check-circle-fill"></i> Open for Submission
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm-fill"></i> <?= $diff_days == 0 ? 'Closing Today!' : "Closes in {$diff_days} days" ?>
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <span class="hero-encrypt-note">
                    <i class="bi bi-shield-lock-fill clr-gold"></i> AES-256 Encrypted Envelopes
                </span>
            </div>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Total Project ABC</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format($procurement['abc'], 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers-fill"></i> Available Lots</div>
                <div class="vp-hero-metric-val"><?= count($lots) ?> <?= count($lots) === 1 ? 'Lot' : 'Lots' ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-hourglass-split"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val vp-hero-metric-val--sm"><?= $deadline_text ?></div>
            </div>
        </div>
    </div>

    <!-- Error Validation Display -->
    <?php if (!empty($errors)): ?>
        <div class="sb-error-banner">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <strong>Please resolve the following before submitting:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Two-Column Grid: Left Column Form, Right Column Summary -->
    <div class="vp-grid-layout">
        
        <!-- ============================================== -->
        <!-- LEFT COLUMN: PROPOSAL FORM                     -->
        <!-- ============================================== -->
        <div class="vp-left-col">
            
            <form method="POST" action="" enctype="multipart/form-data" id="bidProposalForm" onsubmit="return validateBeforeSubmit(event)">
                <!-- ─────────────────────────────────────────── -->
                <!-- STEP 1: Select Bidding Lots                 -->
                <!-- ─────────────────────────────────────────── -->
                <div class="submit-step-card" id="step1Card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">1</div>
                            <div class="step-title-text">
                                <h4>Select Lots to Bid On <span class="required-mark">*</span></h4>
                                <p>Choose one or more project lots. Each selected lot requires its own technical &amp; financial proposal.</p>
                            </div>
                        </div>
                        <?php if (count($lots) > 1): ?>
                            <div class="lots-quick-btns">
                                <button type="button" class="btn-quick-toggle" onclick="toggleAllLots(true)">Select All</button>
                                <button type="button" class="btn-quick-toggle" onclick="toggleAllLots(false)">Clear All</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="step-card-body">
                        <div class="lots-select-toolbar">
                            <div class="lots-select-info">
                                <span id="selectedLotsCount">0</span> of <?= count($lots) ?> lots selected
                            </div>
                            <div class="lots-select-info">
                                Selected Total ABC: <strong id="selectedLotsAbc" class="selected-abc-value">₱0.00</strong>
                            </div>
                        </div>

                        <div class="sb-lot-cards-grid">
                            <?php foreach ($lots as $lot): ?>
                                <div class="sb-lot-card" id="card-<?= $lot['id'] ?>" onclick="handleLotCardClick(<?= $lot['id'] ?>, event)">
                                    <input
                                        type="checkbox"
                                        name="selected_lots[]"
                                        value="<?= $lot['id'] ?>"
                                        id="chk-<?= $lot['id'] ?>"
                                        class="lot-checkbox"
                                        data-lot-id="<?= $lot['id'] ?>"
                                        data-lot-num="<?= htmlspecialchars($lot['lot_number']) ?>"
                                        data-lot-abc="<?= $lot['abc'] ?>"
                                        onchange="onLotCheckboxChange(this)"
                                    >
                                    <div class="sb-lot-card-top">
                                        <span class="sb-lot-badge">LOT <?= htmlspecialchars($lot['lot_number']) ?></span>
                                        <div class="sb-lot-checkbox-ui" id="check-ui-<?= $lot['id'] ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </div>
                                    </div>

                                    <div class="sb-lot-card-title"><?= htmlspecialchars($lot['lot_title']) ?></div>

                                    <div class="sb-lot-card-bottom">
                                        <span class="sb-lot-abc-lbl">Approved Budget (ABC)</span>
                                        <span class="sb-lot-abc-val">₱<?= number_format($lot['abc'], 2) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ─────────────────────────────────────────── -->
                <!-- STEP 2: Bidding Fee / Official Receipt      -->
                <!-- ─────────────────────────────────────────── -->
                <div class="submit-step-card" id="step2Card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">2</div>
                            <div class="step-title-text">
                                <h4>Bidding Fee Receipt / Proof of Payment <span class="required-mark">*</span></h4>
                                <p>Upload your Official Receipt or electronic payment confirmation issued by the BAC Secretariat.</p>
                            </div>
                        </div>
                        <span class="envelope-status-pill pending" id="receiptStatusPill">
                            <i class="bi bi-clock"></i> Required
                        </span>
                    </div>

                    <div class="step-card-body">
                        <!-- Single input: submitted directly, no hidden-input copy needed -->
                        <input type="file" name="bid_receipt" id="bid_receipt" accept=".pdf,.jpg,.jpeg,.png" class="visually-hidden-input" onchange="handleReceiptSelected(this)">

                        <div class="receipt-dropzone" id="receiptDropzone" onclick="document.getElementById('bid_receipt').click()">
                            <div class="receipt-icon-wrap">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div class="receipt-dropzone-title">Upload Official Payment Receipt</div>
                            <div class="receipt-dropzone-sub">Accepted formats: PDF, JPG, PNG &bull; Max file size: 10MB</div>
                            <button type="button" class="btn-browse-file">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Browse Receipt File
                            </button>
                        </div>

                        <!-- Stack item container for receipt -->
                        <div class="file-stack-list" id="receiptFileContainer"></div>
                    </div>
                </div>

                <!-- ─────────────────────────────────────────── -->
                <!-- STEP 3: Proposal Documents Per Lot          -->
                <!-- ─────────────────────────────────────────── -->
                <div class="submit-step-card" id="step3Card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">3</div>
                            <div class="step-title-text">
                                <h4>Proposal Documents per Lot <span class="required-mark">*</span></h4>
                                <p>Provide both Technical/Eligibility and Financial envelopes for each selected lot.</p>
                            </div>
                        </div>
                    </div>

                    <div class="step-card-body">
                        <!-- Placeholder when no lots are selected -->
                        <div class="sb-no-lots-placeholder" id="noLotsPlaceholder">
                            <i class="bi bi-arrow-up-circle"></i>
                            <div class="placeholder-title">No Lots Selected Yet</div>
                            <div>Please select at least one lot in <strong>Step 1</strong> above to unlock document upload fields.</div>
                        </div>

                        <!-- Lot Upload Cards -->
                        <div id="lotEnvelopesContainer">
                            <?php foreach ($lots as $lot): ?>
                                <div class="sb-lot-envelope-card hidden" id="lot-section-<?= $lot['id'] ?>">
                                    <div class="sb-lot-envelope-header">
                                        <div class="sb-envelope-lot-title">
                                            <span class="envelope-badge">LOT <?= htmlspecialchars($lot['lot_number']) ?></span>
                                            <span><?= htmlspecialchars($lot['lot_title']) ?></span>
                                        </div>
                                        <span class="envelope-status-pill pending" id="lot-pill-<?= $lot['id'] ?>">
                                            <i class="bi bi-hourglass-split"></i> Awaiting Documents
                                        </span>
                                    </div>

                                    <div class="sb-lot-envelope-body">
                                        
                                        <!-- Eligibility / Technical Envelope -->
                                        <div class="envelope-upload-box">
                                            <div class="envelope-box-head">
                                                <div class="envelope-icon">
                                                    <i class="bi bi-file-earmark-check-fill"></i>
                                                </div>
                                                <div>
                                                    <div class="envelope-box-title">Eligibility &amp; Technical Envelope <span class="required-mark">*</span></div>
                                                    <div class="envelope-box-desc">PhilGEPS cert, Mayor's permit, Omnibus statement, specs (PDF/DOCX/ZIP).</div>
                                                </div>
                                            </div>

                                            <!-- Single input: submitted directly with proper name -->
                                            <input type="file"
                                                   name="eligibility_docs[<?= $lot['id'] ?>][]"
                                                   id="elig_<?= $lot['id'] ?>"
                                                   accept=".pdf,.doc,.docx,.zip"
                                                   multiple
                                                   class="visually-hidden-input"
                                                   onchange="stackFiles(this, 'elig_<?= $lot['id'] ?>', 'fn-elig-<?= $lot['id'] ?>', <?= $lot['id'] ?>)">

                                            <div class="envelope-upload-box-bottom">
                                                <div class="envelope-upload-trigger" onclick="document.getElementById('elig_<?= $lot['id'] ?>').click()">
                                                    <div class="trigger-left">
                                                        <i class="bi bi-plus-circle-fill clr-forest"></i> Add Document(s)
                                                    </div>
                                                    <span class="trigger-btn">Browse</span>
                                                </div>
                                                <div class="file-stack-list" id="fn-elig-<?= $lot['id'] ?>"></div>
                                            </div>
                                        </div>

                                        <!-- Financial Proposal Envelope -->
                                        <div class="envelope-upload-box">
                                            <div class="envelope-box-head">
                                                <div class="envelope-icon financial">
                                                    <i class="bi bi-file-earmark-bar-graph-fill"></i>
                                                </div>
                                                <div>
                                                    <div class="envelope-box-title">Financial Proposal Envelope <span class="required-mark">*</span></div>
                                                    <div class="envelope-box-desc">Bid Form, Bill of Quantities / Detailed Price Breakdown (PDF/DOCX/ZIP).</div>
                                                </div>
                                            </div>

                                            <!-- Single input: submitted directly with proper name -->
                                            <input type="file"
                                                   name="financial_docs[<?= $lot['id'] ?>][]"
                                                   id="fin_<?= $lot['id'] ?>"
                                                   accept=".pdf,.doc,.docx,.zip"
                                                   multiple
                                                   class="visually-hidden-input"
                                                   onchange="stackFiles(this, 'fin_<?= $lot['id'] ?>', 'fn-fin-<?= $lot['id'] ?>', <?= $lot['id'] ?>)">

                                            <div class="envelope-upload-box-bottom">
                                                <div class="envelope-upload-trigger" onclick="document.getElementById('fin_<?= $lot['id'] ?>').click()">
                                                    <div class="trigger-left">
                                                        <i class="bi bi-plus-circle-fill clr-amber-dark"></i> Add Document(s)
                                                    </div>
                                                    <span class="trigger-btn">Browse</span>
                                                </div>
                                                <div class="file-stack-list" id="fn-fin-<?= $lot['id'] ?>"></div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ─────────────────────────────────────────── -->
                <!-- STEP 4: Legal Certification & Submission    -->
                <!-- ─────────────────────────────────────────── -->
                <div class="submit-step-card" id="step4Card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">4</div>
                            <div class="step-title-text">
                                <h4>Certification &amp; Final Submission</h4>
                                <p>Review your information and complete the encrypted submission.</p>
                            </div>
                        </div>
                    </div>

                    <div class="step-card-body">
                        <div class="security-notice-card">
                            <i class="bi bi-shield-check"></i>
                            <div>
                                <strong>Cryptographic Sealing &amp; Security Assurance</strong>
                                <p>All proposal documents are encrypted using AES-256-CBC encryption upon submission. Technical and financial envelopes remain sealed and cannot be accessed until the designated BAC Opening Date.</p>
                            </div>
                        </div>

                        <label class="certification-checkbox-label">
                            <input type="checkbox" id="certifyCheckbox" onchange="updateSubmitButtonState()">
                            <span>I hereby certify that all documents submitted are authentic, accurate, and compliant with RA 9184 and the prescribed Bidding Documents. I acknowledge that submitting falsified documents constitutes grounds for disqualification and legal liability.</span>
                        </label>

                        <div class="form-actions-bar">
                            <button type="button" id="btnSubmitProposal" class="btn-submit-proposal" onclick="openBidConfirm()">
                                <i class="bi bi-send-check-fill"></i> Submit Encrypted Bid Proposal
                            </button>
                            <a href="view_procurement.php?id=<?= $procurement_id ?>" class="btn-cancel-proposal">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>

                    </div>
                </div>

            </form>

        </div><!-- /.vp-left-col -->


        <!-- ============================================== -->
        <!-- RIGHT COLUMN: SIDEBAR SUMMARY & CHECKLIST     -->
        <!-- ============================================== -->
        <div class="vp-right-col">
            
            <!-- Proposal Summary Card (Normal Static Card) -->
            <div class="sidebar-summary-card">
                <div class="summary-card-head">
                    <h4><i class="bi bi-journal-check"></i> Bid Proposal Summary</h4>
                    <span class="envelope-status-pill pending" id="overallReadinessBadge">Incomplete</span>
                </div>

                <div class="summary-card-body">
                    <div class="summary-metric-box">
                        <div class="summary-metric-lbl">Total Bidding Value (Selected Lots)</div>
                        <div class="summary-metric-val" id="summaryTotalAbc">₱0.00</div>
                    </div>

                    <div class="checklist-heading">
                        Submission Checklist
                    </div>

                    <div class="summary-checklist">
                        <div class="checklist-item" id="checkLotsItem">
                            <i class="bi bi-circle"></i>
                            <span>At least 1 lot selected (<span id="summaryLotsCount">0</span> selected)</span>
                        </div>
                        <div class="checklist-item" id="checkReceiptItem">
                            <i class="bi bi-circle"></i>
                            <span>Bidding fee receipt attached</span>
                        </div>
                        <div class="checklist-item" id="checkDocsItem">
                            <i class="bi bi-circle"></i>
                            <span>Eligibility &amp; Financial docs uploaded</span>
                        </div>
                        <div class="checklist-item" id="checkCertItem">
                            <i class="bi bi-circle"></i>
                            <span>Declaration &amp; certification checked</span>
                        </div>
                    </div>

                    <div class="summary-guidelines-box">
                        <strong><i class="bi bi-info-circle"></i> Important Reminders</strong>
                        <ul class="guidelines-list">
                            <li>Bids submitted past the deadline will be automatically rejected.</li>
                            <li>Ensure file sizes are within 10MB per document for optimal encryption.</li>
                            <li>You can track submission verification in <em>My Bids</em> portal.</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div><!-- /.vp-right-col -->

    </div><!-- /.vp-grid-layout -->

</div>
</main>

<!-- Notification Toast for Copy / Feedback -->
<div id="toastAlert" class="wide-toast wide-toast--success" hidden>
    <i id="toastIcon" class="bi bi-check-circle-fill"></i> <span id="toastMessage"></span>
</div>

<script>
    // ── Copy Reference Number Helper ──────────────────────────────────────────────
    function copyRefNo(ref) {
        if (!ref || ref === 'N/A') return;
        navigator.clipboard.writeText(ref).then(() => {
            showToast("Reference number " + ref + " copied to clipboard!");
        });
    }

    function showToast(msg, type = 'success') {
        const toast = document.getElementById('toastAlert');
        const text  = document.getElementById('toastMessage');
        const icon  = document.getElementById('toastIcon');
        if (!toast) return;

        if (type === 'error') {
            toast.className = 'wide-toast wide-toast--error';
            icon.className  = 'bi bi-exclamation-triangle-fill';
        } else {
            toast.className = 'wide-toast wide-toast--success';
            icon.className  = 'bi bi-check-circle-fill';
        }

        text.textContent = msg;
        toast.style.display = 'flex';
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(() => {
            toast.style.display = 'none';
        }, 4000);
    }

    // ── Lot Card Click & Toggle ───────────────────────────────────────────────────
    function handleLotCardClick(lotId, event) {
        // Prevent recursive trigger if directly clicking checkbox
        if (event.target.tagName === 'INPUT') return;
        const chk = document.getElementById('chk-' + lotId);
        if (chk) {
            chk.checked = !chk.checked;
            onLotCheckboxChange(chk);
        }
    }

    function onLotCheckboxChange(checkbox) {
        const lotId     = checkbox.getAttribute('data-lot-id');
        const card      = document.getElementById('card-' + lotId);
        const checkUi   = document.getElementById('check-ui-' + lotId);
        const section   = document.getElementById('lot-section-' + lotId);

        if (checkbox.checked) {
            card.classList.add('selected');
            if (checkUi) checkUi.style.display = 'flex';
            if (section) section.classList.remove('hidden');
        } else {
            card.classList.remove('selected');
            if (section) section.classList.add('hidden');
            // Clear inputs for this lot
            clearLotFiles(lotId);
        }

        updateSummaryMetrics();
    }

    function toggleAllLots(select) {
        const checkboxes = document.querySelectorAll('.lot-checkbox');
        checkboxes.forEach(chk => {
            chk.checked = select;
            onLotCheckboxChange(chk);
        });
    }

    function clearLotFiles(lotId) {
        ['elig_' + lotId, 'fin_' + lotId].forEach(id => {
            fileStacks[id] = [];
            const inp = document.getElementById(id);
            if (inp) inp.files = new DataTransfer().files;
            const list = document.getElementById('fn-' + id.replace('_', '-'));
            if (list) list.innerHTML = '';
        });
        updateLotStatusPill(lotId);
    }

    // ── Receipt Handling ──────────────────────────────────────────────────────────
    const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB per file

    function handleReceiptSelected(picker) {
        if (picker.files && picker.files[0]) {
            const file = picker.files[0];
            if (file.size > MAX_FILE_BYTES) {
                showToast(`"${file.name}" is too large (${(file.size/1024/1024).toFixed(1)} MB). Max allowed is 10 MB.`, 'error');
                picker.value = '';
                return;
            }
        }
        renderReceiptStack();
        updateSummaryMetrics();
    }

    function renderReceiptStack() {
        const real = document.getElementById('bid_receipt');
        const container = document.getElementById('receiptFileContainer');
        const pill = document.getElementById('receiptStatusPill');

        if (!real.files || real.files.length === 0) {
            container.innerHTML = '';
            pill.className = 'envelope-status-pill pending';
            pill.innerHTML = '<i class="bi bi-clock"></i> Required';
            return;
        }

        const file = real.files[0];
        pill.className = 'envelope-status-pill complete';
        pill.innerHTML = '<i class="bi bi-check-circle-fill"></i> Uploaded';

        container.innerHTML = `
            <div class="file-stack-item">
                <div class="file-stack-item-left">
                    <i class="bi bi-receipt file-icon"></i>
                    <span class="file-stack-name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
                    <span class="file-stack-size">${(file.size / 1024).toFixed(1)} KB</span>
                </div>
                <button type="button" class="file-remove-btn" onclick="removeReceiptFile()" title="Remove file">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        `;
    }

    function removeReceiptFile() {
        const real = document.getElementById('bid_receipt');
        real.files = new DataTransfer().files;
        renderReceiptStack();
        updateSummaryMetrics();
    }

    // ── Multi-File Stacking per Lot ──────────────────────────────────────────────
    // fileStacks holds accumulated File objects per real input id
    const fileStacks = {};

    function stackFiles(input, realInputId, listId, lotId) {
        if (!fileStacks[realInputId]) fileStacks[realInputId] = [];

        const existingNames = new Set(fileStacks[realInputId].map(f => f.name));
        const oversized = [];

        Array.from(input.files).forEach(f => {
            if (f.size > MAX_FILE_BYTES) {
                oversized.push(f.name);
            } else if (!existingNames.has(f.name)) {
                fileStacks[realInputId].push(f);
                existingNames.add(f.name);
            }
        });

        if (oversized.length > 0) {
            oversized.forEach(name => {
                showToast(`"${name}" exceeds the 10 MB limit and was not added.`, 'error');
            });
        }

        // Sync stack back into the input so it submits with the form
        const dt = new DataTransfer();
        fileStacks[realInputId].forEach(f => dt.items.add(f));
        input.files = dt.files;

        renderFileStack(realInputId, listId, lotId);
        updateLotStatusPill(lotId);
        updateSummaryMetrics();
    }

    function renderFileStack(realInputId, listId, lotId) {
        const el    = document.getElementById(listId);
        const input = document.getElementById(realInputId);
        if (!el) return;

        const files = fileStacks[realInputId] || [];
        if (files.length === 0) {
            el.innerHTML = '';
            return;
        }

        let html = '';
        files.forEach((file, idx) => {
            html += `
                <div class="file-stack-item">
                    <div class="file-stack-item-left">
                        <i class="bi bi-file-earmark-text-fill file-icon"></i>
                        <span class="file-stack-name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
                        <span class="file-stack-size">${(file.size / 1024).toFixed(1)} KB</span>
                    </div>
                    <button type="button" class="file-remove-btn" onclick="removeStackedFile('${realInputId}', ${idx}, '${listId}', ${lotId})" title="Remove file">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>
            `;
        });
        el.innerHTML = html;
    }

    function removeStackedFile(realInputId, indexToRemove, listId, lotId) {
        if (fileStacks[realInputId]) {
            fileStacks[realInputId].splice(indexToRemove, 1);
        }
        // Sync back to input
        const input = document.getElementById(realInputId);
        if (input) {
            const dt = new DataTransfer();
            (fileStacks[realInputId] || []).forEach(f => dt.items.add(f));
            input.files = dt.files;
        }
        renderFileStack(realInputId, listId, lotId);
        updateLotStatusPill(lotId);
        updateSummaryMetrics();
    }

    function updateLotStatusPill(lotId) {
        const hasElig = (fileStacks['elig_' + lotId] || []).length > 0;
        const hasFin  = (fileStacks['fin_' + lotId]  || []).length > 0;
        const pill = document.getElementById('lot-pill-' + lotId);
        if (!pill) return;

        if (hasElig && hasFin) {
            pill.className = 'envelope-status-pill complete';
            pill.innerHTML = '<i class="bi bi-check-circle-fill"></i> Complete';
        } else if (hasElig || hasFin) {
            pill.className = 'envelope-status-pill pending';
            pill.innerHTML = '<i class="bi bi-hourglass-split"></i> Partially Complete';
        } else {
            pill.className = 'envelope-status-pill pending';
            pill.innerHTML = '<i class="bi bi-clock"></i> Awaiting Documents';
        }
    }

    // ── Real-Time Metrics & Checklist Tracker ─────────────────────────────────────
    function updateSummaryMetrics() {
        const checkedLots = Array.from(document.querySelectorAll('.lot-checkbox:checked'));
        const lotCount    = checkedLots.length;
        const noLotsMsg   = document.getElementById('noLotsPlaceholder');

        // Placeholder toggle
        if (noLotsMsg) {
            noLotsMsg.style.display = lotCount > 0 ? 'none' : 'flex';
        }

        // Calculate selected ABC
        let totalAbc = 0;
        checkedLots.forEach(chk => {
            totalAbc += parseFloat(chk.getAttribute('data-lot-abc') || 0);
        });

        const formattedAbc = '₱' + totalAbc.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('selectedLotsCount').textContent = lotCount;
        document.getElementById('selectedLotsAbc').textContent   = formattedAbc;
        document.getElementById('summaryLotsCount').textContent  = lotCount;
        document.getElementById('summaryTotalAbc').textContent   = formattedAbc;

        // Checklist states
        const hasLots = lotCount > 0;
        setChecklistItem('checkLotsItem', hasLots);

        const receiptInput = document.getElementById('bid_receipt');
        const hasReceipt   = receiptInput && receiptInput.files && receiptInput.files.length > 0;
        setChecklistItem('checkReceiptItem', hasReceipt);

        // Check if all selected lots have both elig and fin docs
        let allDocsComplete = hasLots;
        if (hasLots) {
            checkedLots.forEach(chk => {
                const id = chk.getAttribute('data-lot-id');
                if (!(fileStacks['elig_' + id] || []).length || !(fileStacks['fin_' + id] || []).length) {
                    allDocsComplete = false;
                }
            });
        }
        setChecklistItem('checkDocsItem', allDocsComplete);

        const certifyChk = document.getElementById('certifyCheckbox');
        const isCertified = certifyChk && certifyChk.checked;
        setChecklistItem('checkCertItem', isCertified);

        // Overall readiness
        const isReady = hasLots && hasReceipt && allDocsComplete && isCertified;
        const badge = document.getElementById('overallReadinessBadge');
        if (badge) {
            if (isReady) {
                badge.className = 'envelope-status-pill complete';
                badge.textContent = 'Ready to Submit';
            } else {
                badge.className = 'envelope-status-pill pending';
                badge.textContent = 'Incomplete';
            }
        }
    }

    function setChecklistItem(elementId, isDone) {
        const item = document.getElementById(elementId);
        if (!item) return;
        if (isDone) {
            item.classList.add('done');
            item.querySelector('i').className = 'bi bi-check-circle-fill';
        } else {
            item.classList.remove('done');
            item.querySelector('i').className = 'bi bi-circle';
        }
    }

    function updateSubmitButtonState() {
        updateSummaryMetrics();
    }

    // ── Form Validation Before Submit ─────────────────────────────────────────────
    function openBidConfirm() {
        const checkedLots = document.querySelectorAll('.lot-checkbox:checked');
        if (checkedLots.length === 0) {
            alert("Please select at least one lot you wish to bid on.");
            document.getElementById('step1Card').scrollIntoView({ behavior: 'smooth' });
            return;
        }

        const receiptInput = document.getElementById('bid_receipt');
        if (!receiptInput || !receiptInput.files || receiptInput.files.length === 0) {
            alert("Please upload your Official Bidding Fee Receipt / Proof of Payment.");
            document.getElementById('step2Card').scrollIntoView({ behavior: 'smooth' });
            return;
        }

        // Validate each selected lot
        for (let chk of checkedLots) {
            const lotId  = chk.getAttribute('data-lot-id');
            const lotNum = chk.getAttribute('data-lot-num');

            if (!(fileStacks['elig_' + lotId] || []).length) {
                alert(`Please upload the Eligibility & Technical documents for Lot #${lotNum}.`);
                document.getElementById('step3Card').scrollIntoView({ behavior: 'smooth' });
                return;
            }

            if (!(fileStacks['fin_' + lotId] || []).length) {
                alert(`Please upload the Financial Proposal Form for Lot #${lotNum}.`);
                document.getElementById('step3Card').scrollIntoView({ behavior: 'smooth' });
                return;
            }
        }

        const certifyChk = document.getElementById('certifyCheckbox');
        if (!certifyChk || !certifyChk.checked) {
            alert("Please check the declaration checkbox to certify document authenticity before submitting.");
            document.getElementById('step4Card').scrollIntoView({ behavior: 'smooth' });
            return;
        }

        // All validations passed — open confirm modal
        document.getElementById('bidConfirmModal').classList.add('open');
    }

    function closeBidConfirm() {
        document.getElementById('bidConfirmModal').classList.remove('open');
    }

    function validateBeforeSubmit(event) {
        return true;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
        updateSummaryMetrics();
    });
</script>

<!-- ========================= -->
<!-- BID CONFIRM MODAL         -->
<!-- ========================= -->
<div id="bidConfirmModal" class="modal-backdrop" onclick="if(event.target===this)closeBidConfirm()">
    <div class="urm-modal">
        <button class="urm-modal-close" onclick="closeBidConfirm()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon urm-modal-icon--green">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
        </div>

        <div class="urm-modal-text">
            <h3>Submit Bid Proposal</h3>
            <p>All documents will be encrypted with AES-256 and securely sealed. This action cannot be undone.</p>
            <div class="urm-modal-user-pill"><?= htmlspecialchars($procurement['title']) ?></div>
        </div>

        <div class="urm-modal-actions">
            <button type="button" onclick="closeBidConfirm()" class="urm-btn-cancel">
                Cancel
            </button>
            <button type="button" class="urm-btn-confirm urm-btn-confirm--green"
                    onclick="
                        this.disabled=true;
                        this.innerHTML='<i class=\'bi bi-hourglass-split\'></i> Submitting...';
                        var form = document.getElementById('bidProposalForm');
                        var flag = document.createElement('input');
                        flag.type  = 'hidden';
                        flag.name  = 'submit_bid';
                        flag.value = '1';
                        form.appendChild(flag);
                        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
                    ">
                <i class="bi bi-send-check-fill"></i> Yes, Submit
            </button>
        </div>
    </div>
</div>

</body>
</html>
