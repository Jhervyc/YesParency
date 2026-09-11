<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");
require_once(__DIR__ . "/../admin/utils/audit_helper.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$bidder_id      = intval($_SESSION['user_id']);

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// 1. Fetch Procurement — must be open and a quotation-mode procurement
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status = 'open' LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    $_SESSION['alert_error'] = "This procurement is not available or is no longer open.";
    header("Location: procurement.php");
    exit();
}

// Guard — if someone hits this page with a Public Bidding procurement, send them to submit_bid.php
if (!is_quotation_mode($procurement['procurement_mode'] ?? '')) {
    header("Location: submit_bid.php?id=" . $procurement_id);
    exit();
}

// 2. Check for an existing quotation
$check = $conn->prepare("SELECT id FROM bids WHERE bidder_id = ? AND procurement_id = ? LIMIT 1");
$check->bind_param("ii", $bidder_id, $procurement_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    $_SESSION['alert_error'] = "You have already submitted a quotation for this procurement.";
    header("Location: view_procurement.php?id=" . $procurement_id);
    exit();
}
$check->close();

// 3. Fetch Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_result = $lot_stmt->get_result();
$lots = [];
while ($row = $lots_result->fetch_assoc()) {
    $lots[] = $row;
}
$lot_stmt->close();

// Deadline & Urgency
$diff_days     = null;
$is_urgent     = false;
$deadline_text = 'To Be Announced';
if (!empty($procurement['closing_date'])) {
    $dl_time       = strtotime($procurement['closing_date']);
    $diff_days     = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($diff_days <= 3 && $diff_days >= 0) $is_urgent = true;
}

// 4. Handle Form Submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quotation'])) {
    $selected_lots   = $_POST['selected_lots'] ?? [];
    $uploaded_paths  = [];

    if (empty($selected_lots)) {
        $errors[] = "Please select at least one lot to include in your quotation.";
    }

    // Each selected lot must have a quotation file
    foreach ($selected_lots as $lot_id) {
        $clean_lot_id = intval($lot_id);
        $lot_num = $clean_lot_id;
        foreach ($lots as $l) {
            if ($l['id'] == $clean_lot_id) { $lot_num = $l['lot_number']; break; }
        }
        $fname = $_FILES['quotation_docs']['name'][$clean_lot_id] ?? '';
        if (empty($fname)) {
            $errors[] = "Missing quotation document for Lot #{$lot_num}.";
        }
    }

    $upload_dir = "../uploads/bids/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Insert bid record with bid_type = 'quotation'
            $bid_stmt = $conn->prepare("INSERT INTO bids (bidder_id, procurement_id, bid_type, status) VALUES (?, ?, 'quotation', 'pending')");
            $bid_stmt->bind_param("ii", $bidder_id, $procurement_id);
            $bid_stmt->execute();
            $bid_id = $conn->insert_id;
            $bid_stmt->close();

            // Insert bid_lots rows (total_offered_bid stays NULL — admin fills it later)
            $lot_bid_stmt = $conn->prepare("INSERT INTO bid_lots (bid_id, lot_id) VALUES (?, ?)");
            foreach ($selected_lots as $lot_id) {
                $clean_lot_id = intval($lot_id);
                $lot_bid_stmt->bind_param("ii", $bid_id, $clean_lot_id);
                $lot_bid_stmt->execute();
            }
            $lot_bid_stmt->close();

            // Upload & store quotation documents — plain, no encryption
            $doc_stmt = $conn->prepare("INSERT INTO bid_documents (bid_id, document_type, document_name, file_path) VALUES (?, 'quotation', ?, ?)");

            foreach ($selected_lots as $lot_id) {
                $clean_lot_id = intval($lot_id);

                $raw_filename = $_FILES['quotation_docs']['name'][$clean_lot_id] ?? '';
                if (empty($raw_filename)) continue;

                $err_code = $_FILES['quotation_docs']['error'][$clean_lot_id];
                if ($err_code !== UPLOAD_ERR_OK) {
                    throw new Exception("Upload error (code {$err_code}) for lot #{$clean_lot_id}.");
                }

                $orig_filename = basename($raw_filename);
                $ext           = strtolower(pathinfo($orig_filename, PATHINFO_EXTENSION));

                // Allowed file types
                $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
                if (!in_array($ext, $allowed)) {
                    throw new Exception("File type '.{$ext}' is not allowed for Lot #{$clean_lot_id}. Allowed: " . implode(', ', $allowed) . ".");
                }

                // Size limit — 20 MB
                if ($_FILES['quotation_docs']['size'][$clean_lot_id] > 20 * 1024 * 1024) {
                    throw new Exception("File for Lot #{$clean_lot_id} exceeds the 20 MB size limit.");
                }

                // Store with a random name so the original filename isn't exposed
                $target = $upload_dir . bin2hex(random_bytes(16)) . "." . $ext;

                if (!move_uploaded_file($_FILES['quotation_docs']['tmp_name'][$clean_lot_id], $target)) {
                    throw new Exception("Failed to save quotation document for Lot #{$clean_lot_id}.");
                }

                $uploaded_paths[] = $target;
                $display_name     = "Quotation ({$orig_filename})";
                $doc_stmt->bind_param("iss", $bid_id, $display_name, $target);
                $doc_stmt->execute();
            }
            $doc_stmt->close();

            $conn->commit();

            // Audit
            audit_log($conn, 'QUOTATION_SUBMITTED', 'bids', $bid_id,
                "Bidder #{$bidder_id} submitted quotation #{$bid_id} for procurement #{$procurement_id} covering " . count($selected_lots) . " lot(s)",
                null,
                ['bid_id' => $bid_id, 'bidder_id' => $bidder_id, 'procurement_id' => $procurement_id,
                 'bid_type' => 'quotation', 'lots' => array_values(array_map('intval', $selected_lots)),
                 'document_count' => count($uploaded_paths)]
            );

            $_SESSION['alert_success'] = "Your quotation was submitted successfully!";
            header("Location: my_bids.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($uploaded_paths as $path) {
                if (file_exists($path)) @unlink($path);
            }
            $errors[] = "Submission failed: " . $e->getMessage();
        }
    }
}

$mode_label = procurement_mode_label($procurement['procurement_mode'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Quotation — <?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-submit-quotation.css">
</head>
<body class="dash-body">

<?php
$active_nav = 'procurement.php';
include("components/sidebar.php");
?>
<?php
$topbar_title = 'Submit Quotation';
include("components/topbar.php");
?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Breadcrumbs -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <a href="view_procurement.php?id=<?= $procurement_id ?>"><?= htmlspecialchars(mb_strimwidth($procurement['title'], 0, 30, '...')) ?></a>
            <span>/</span>
            <span class="vp-breadcrumbs-current">Submit Quotation</span>
        </div>
        <a href="view_procurement.php?id=<?= $procurement_id ?>" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Procurement
        </a>
    </div>

    <!-- Hero -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref">
                    <i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($procurement['procurement_ref_no'] ?? $procurement['slsu_ref_no'] ?? 'N/A') ?>
                </span>
                <span class="hero-pill mode">
                    <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($procurement['procurement_mode'] ?? $mode_label) ?>
                </span>
                <span class="hero-pill open">
                    <i class="bi bi-check-circle-fill"></i> Open for Quotation
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm-fill"></i> <?= $diff_days == 0 ? 'Closing Today!' : "Closes in {$diff_days} day" . ($diff_days > 1 ? 's' : '') ?>
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <span class="hero-note">
                    <i class="bi bi-file-earmark-text-fill clr-gold"></i> Quotation Submission
                </span>
            </div>
        </div>
        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Total ABC</div>
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

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
        <div class="sq-error-banner">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <strong>Please resolve the following before submitting:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="vp-grid-layout">

        <!-- ══════════════════════════════════════ -->
        <!-- LEFT COLUMN — QUOTATION FORM           -->
        <!-- ══════════════════════════════════════ -->
        <div class="vp-left-col">

            <!-- Info Note -->
            <div class="sq-info-note">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    For <strong><?= htmlspecialchars($mode_label) ?></strong>, upload a quotation document for each lot you wish to quote on.
                    Your offered price will be confirmed by the procurement officer after reviewing your document.
                    No eligibility envelope or financial envelope is required.
                </div>
            </div>

            <form method="POST" action="" enctype="multipart/form-data"
                  id="quotationForm" onsubmit="return validateBeforeSubmit(event)">

                <!-- ── STEP 1: Select Lots ── -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">1</div>
                            <div class="step-title-text">
                                <h4>Select Lots to Quote On <span class="required-mark">*</span></h4>
                                <p>Choose the lots you want to submit a price quotation for.</p>
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
                        <div class="lots-select-info">
                            <span><span id="selectedLotsCount">0</span> of <strong><?= count($lots) ?></strong> lots selected</span>
                        </div>
                        <div class="sb-lot-cards-grid" id="lotCardsGrid">
                            <?php foreach ($lots as $lot): ?>
                                <div class="sb-lot-card" id="lot-card-<?= $lot['id'] ?>"
                                     onclick="toggleLot(<?= $lot['id'] ?>)">
                                    <input type="checkbox" name="selected_lots[]"
                                           value="<?= $lot['id'] ?>"
                                           id="lot_chk_<?= $lot['id'] ?>">
                                    <div class="sb-lot-card-top">
                                        <span class="sb-lot-badge">Lot <?= $lot['lot_number'] ?></span>
                                        <div class="sb-lot-checkbox-ui" id="chk-ui-<?= $lot['id'] ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </div>
                                    </div>
                                    <div class="sb-lot-card-title"><?= htmlspecialchars($lot['lot_title']) ?></div>
                                    <div class="sb-lot-card-bottom">
                                        <span class="sb-lot-abc-lbl">ABC</span>
                                        <span class="sb-lot-abc-val">₱<?= number_format((float)$lot['abc'], 2) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ── STEP 2: Upload Quotation Documents ── -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">2</div>
                            <div class="step-title-text">
                                <h4>Upload Quotation Documents <span class="required-mark">*</span></h4>
                                <p>One quotation document per lot. Accepted: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG (max 20 MB each).</p>
                            </div>
                        </div>
                    </div>
                    <div class="step-card-body">

                        <!-- Placeholder when no lots are selected yet -->
                        <div id="noLotsPlaceholder" class="sq-no-lots-placeholder">
                            <i class="bi bi-arrow-up-circle"></i>
                            <strong class="sq-placeholder-strong">Select lots above to upload quotation documents</strong>
                            <span class="sq-placeholder-sub">Each selected lot will appear here with its own upload area.</span>
                        </div>

                        <!-- Per-lot upload zones (shown/hidden by JS) -->
                        <div class="sq-lot-upload-list" id="lotUploadList">
                            <?php foreach ($lots as $lot): ?>
                                <div class="sq-lot-upload-item hidden" id="lot-upload-<?= $lot['id'] ?>">
                                    <div class="sq-lot-upload-header">
                                        <div class="sq-lot-upload-title">
                                            <span class="sq-lot-num-badge">Lot <?= $lot['lot_number'] ?></span>
                                            <?= htmlspecialchars($lot['lot_title']) ?>
                                        </div>
                                        <span class="sq-lot-abc-note">₱<?= number_format((float)$lot['abc'], 2) ?> ABC</span>
                                    </div>
                                    <div class="sq-lot-upload-body">
                                        <div class="sq-upload-zone" id="zone-<?= $lot['id'] ?>"
                                             onclick="document.getElementById('file_lot_<?= $lot['id'] ?>').click()">
                                            <div class="sq-upload-zone-icon">
                                                <i class="bi bi-file-earmark-arrow-up"></i>
                                            </div>
                                            <div class="sq-upload-zone-title">Click or drag a file here</div>
                                            <div class="sq-upload-zone-sub">Quotation document for Lot <?= $lot['lot_number'] ?></div>
                                            <button type="button" class="btn-browse-file" onclick="event.stopPropagation(); document.getElementById('file_lot_<?= $lot['id'] ?>').click()">
                                                <i class="bi bi-folder2-open"></i> Browse File
                                            </button>
                                        </div>
                                        <div class="sq-file-preview" id="preview-<?= $lot['id'] ?>">
                                            <i class="bi bi-file-earmark-check-fill"></i>
                                            <div>
                                                <div class="sq-file-preview-name" id="fname-<?= $lot['id'] ?>"></div>
                                                <div class="sq-file-preview-size" id="fsize-<?= $lot['id'] ?>"></div>
                                            </div>
                                            <button type="button" class="sq-remove-file"
                                                    onclick="removeFile(<?= $lot['id'] ?>)" title="Remove file">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                        <!-- Hidden file input — keyed by lot_id so PHP can read $_FILES['quotation_docs'][lot_id] -->
                                        <input type="file"
                                               name="quotation_docs[<?= $lot['id'] ?>]"
                                               id="file_lot_<?= $lot['id'] ?>"
                                               class="visually-hidden-input"
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
                                               onchange="handleFileSelect(<?= $lot['id'] ?>, this)">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>

                <!-- ── Submit Button (mobile visible, also mirrored in right panel) ── -->
                <div class="sq-submit-wrap d-md-none sq-submit-wrap--mobile">
                    <button type="submit" name="submit_quotation" id="submitBtn" class="btn-submit-quotation" disabled>
                        <i class="bi bi-send-fill"></i> Submit Quotation
                    </button>
                </div>

            </form>
        </div><!-- /vp-left-col -->

        <!-- ══════════════════════════════════════ -->
        <!-- RIGHT COLUMN — SUMMARY & INFO          -->
        <!-- ══════════════════════════════════════ -->
        <div class="vp-right-col">

            <!-- Submission Summary Card -->
            <div class="sq-summary-card">
                <div class="sq-summary-head">
                    <i class="bi bi-clipboard2-check-fill"></i>
                    <div class="sq-summary-head-title">Quotation Summary</div>
                </div>
                <div class="sq-summary-body">
                    <div class="sq-stat-row">
                        <span class="sq-stat-lbl">Lots Available</span>
                        <span class="sq-stat-val"><?= count($lots) ?></span>
                    </div>
                    <div class="sq-stat-row">
                        <span class="sq-stat-lbl">Lots Selected</span>
                        <span class="sq-stat-val" id="summaryLotCount">0</span>
                    </div>
                    <div class="sq-stat-row">
                        <span class="sq-stat-lbl">Mode</span>
                        <span class="sq-stat-val">
                            <span class="sq-mode-badge"><i class="bi bi-tag-fill"></i> <?= htmlspecialchars($mode_label) ?></span>
                        </span>
                    </div>
                    <div class="sq-stat-row">
                        <span class="sq-stat-lbl">Encryption</span>
                        <span class="sq-stat-val sq-stat-val--muted">None Required</span>
                    </div>
                    <div class="sq-stat-row">
                        <span class="sq-stat-lbl">Deadline</span>
                        <span class="sq-stat-val sq-stat-val--sm"><?= $deadline_text ?></span>
                    </div>
                    <div class="sq-selected-lots-wrap">
                        <div class="sq-selected-lots-label">Selected Lots</div>
                        <div class="sq-selected-lots-list" id="selectedLotsList">
                            <span class="sq-no-sel-msg">No lots selected yet.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button (desktop) -->
            <div class="sq-submit-wrap">
                <button type="submit" form="quotationForm" name="submit_quotation"
                        id="submitBtnDesk" class="btn-submit-quotation" disabled>
                    <i class="bi bi-send-fill"></i> Submit Quotation
                </button>
                <p class="sq-submit-note">
                    Once submitted, your quotation cannot be edited. The procurement officer will review your document and confirm the offered price.
                </p>
            </div>

            <!-- Procurement Info Card -->
            <div class="sq-proc-info-card sq-proc-info-card--spaced">
                <div class="sq-proc-info-head">
                    <i class="bi bi-info-circle-fill"></i>
                    <div class="sq-proc-info-head-title">Procurement Info</div>
                </div>
                <div class="sq-proc-info-body">
                    <div class="sq-info-field">
                        <div class="sq-info-field-lbl">Project Title</div>
                        <div class="sq-info-field-val"><?= htmlspecialchars($procurement['title']) ?></div>
                    </div>
                    <?php if (!empty($procurement['description'])): ?>
                    <div class="sq-info-field">
                        <div class="sq-info-field-lbl">Description</div>
                        <div class="sq-info-field-val sq-info-field-val--desc">
                            <?= nl2br(htmlspecialchars($procurement['description'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="sq-info-field">
                        <div class="sq-info-field-lbl">Budget (ABC)</div>
                        <div class="sq-info-field-val sq-info-field-val--accent">₱<?= number_format($procurement['abc'], 2) ?></div>
                    </div>
                    <div class="sq-info-field">
                        <div class="sq-info-field-lbl">Procurement Mode</div>
                        <div class="sq-info-field-val">
                            <span class="sq-mode-badge"><i class="bi bi-tag-fill"></i> <?= htmlspecialchars($mode_label) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /vp-right-col -->
    </div><!-- /vp-grid-layout -->

</div>
</main>

<!-- Toast Alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
// ── Lot data from PHP ──────────────────────────────────────────────────────
const lots = <?= json_encode(array_map(fn($l) => [
    'id'         => $l['id'],
    'lot_number' => $l['lot_number'],
    'lot_title'  => $l['lot_title'],
    'abc'        => (float)$l['abc'],
], $lots), JSON_UNESCAPED_UNICODE) ?>;

let selectedLots = new Set();
let uploadedFiles = {}; // lot_id → true/false

// ── Toast ──────────────────────────────────────────────────────────────────
const toast = document.getElementById('toastAlert');
if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

// ── Toggle a single lot ────────────────────────────────────────────────────
function toggleLot(lotId) {
    const card = document.getElementById('lot-card-' + lotId);
    const chk  = document.getElementById('lot_chk_' + lotId);
    if (selectedLots.has(lotId)) {
        selectedLots.delete(lotId);
        card.classList.remove('selected');
        chk.checked = false;
        document.getElementById('lot-upload-' + lotId).classList.add('hidden');
    } else {
        selectedLots.add(lotId);
        card.classList.add('selected');
        chk.checked = true;
        document.getElementById('lot-upload-' + lotId).classList.remove('hidden');
    }
    syncUI();
}

// ── Select / Clear All ─────────────────────────────────────────────────────
function toggleAllLots(select) {
    lots.forEach(l => {
        const card = document.getElementById('lot-card-' + l.id);
        const chk  = document.getElementById('lot_chk_' + l.id);
        const up   = document.getElementById('lot-upload-' + l.id);
        if (select) {
            selectedLots.add(l.id);
            card.classList.add('selected');
            chk.checked = true;
            up.classList.remove('hidden');
        } else {
            selectedLots.delete(l.id);
            card.classList.remove('selected');
            chk.checked = false;
            up.classList.add('hidden');
        }
    });
    syncUI();
}

// ── Handle file selection ──────────────────────────────────────────────────
function handleFileSelect(lotId, input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    uploadedFiles[lotId] = true;

    const preview = document.getElementById('preview-' + lotId);
    document.getElementById('fname-' + lotId).textContent = file.name;
    document.getElementById('fsize-' + lotId).textContent = formatBytes(file.size);
    preview.classList.add('visible');
    document.getElementById('zone-' + lotId).style.display = 'none';
    syncUI();
}

function removeFile(lotId) {
    const inp = document.getElementById('file_lot_' + lotId);
    inp.value = '';
    uploadedFiles[lotId] = false;
    document.getElementById('preview-' + lotId).classList.remove('visible');
    document.getElementById('zone-' + lotId).style.display = '';
    syncUI();
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// ── Sync counter, summary panel, submit button ─────────────────────────────
function syncUI() {
    const count = selectedLots.size;
    document.getElementById('selectedLotsCount').textContent = count;
    document.getElementById('summaryLotCount').textContent = count;

    // No lots placeholder
    const placeholder = document.getElementById('noLotsPlaceholder');
    if (placeholder) placeholder.style.display = count > 0 ? 'none' : '';

    // Selected lots tags
    const listEl = document.getElementById('selectedLotsList');
    if (count === 0) {
        listEl.innerHTML = '<span class="sq-no-sel-msg">No lots selected yet.</span>';
    } else {
        listEl.innerHTML = '';
        lots.filter(l => selectedLots.has(l.id)).forEach(l => {
            const tag = document.createElement('div');
            tag.className = 'sq-sel-lot-tag';
            tag.innerHTML = `<i class="bi bi-check-circle-fill"></i> Lot ${l.lot_number}: ${escHtml(l.lot_title)}`;
            listEl.appendChild(tag);
        });
    }

    // Enable submit only if all selected lots have a file
    const allHaveFiles = count > 0 && [...selectedLots].every(id => uploadedFiles[id]);
    document.getElementById('submitBtn').disabled    = !allHaveFiles;
    document.getElementById('submitBtnDesk').disabled = !allHaveFiles;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Before submit validation ───────────────────────────────────────────────
function validateBeforeSubmit(e) {
    if (selectedLots.size === 0) {
        alert('Please select at least one lot before submitting.');
        e.preventDefault();
        return false;
    }
    const missing = [...selectedLots].filter(id => !uploadedFiles[id]);
    if (missing.length > 0) {
        const nums = missing.map(id => {
            const l = lots.find(l => l.id === id);
            return l ? 'Lot ' + l.lot_number : '#' + id;
        });
        alert('Missing quotation document for: ' + nums.join(', '));
        e.preventDefault();
        return false;
    }
    return true;
}

// ── Drag-and-drop on upload zones ─────────────────────────────────────────
lots.forEach(l => {
    const zone = document.getElementById('zone-' + l.id);
    if (!zone) return;
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('has-file'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('has-file'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('has-file');
        const files = e.dataTransfer.files;
        if (files && files[0]) {
            const inp = document.getElementById('file_lot_' + l.id);
            // DataTransfer trick to assign dropped file to input
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            inp.files = dt.files;
            handleFileSelect(l.id, inp);
        }
    });
});

// Init
syncUI();
</script>
</body>
</html>
