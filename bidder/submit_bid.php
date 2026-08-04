<?php
    include("utils/protect-page.php");
    require_once ("../utils/crypto.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $bidder_id = intval($_SESSION['user_id']); 

    if ($procurement_id === 0) {
        header("Location: bidder_procurement.php");
        exit();
    }

    // 1. Fetch Procurement Details
    $stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status = 'open' LIMIT 1");
    $stmt->bind_param("i", $procurement_id);
    $stmt->execute();
    $procurement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$procurement) {
        $_SESSION['alert_error'] = "Procurement is not available for bidding.";
        header("Location: bidder_procurement.php");
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
    while ($row = $lots_result->fetch_assoc()) {
        $lots[] = $row;
    }

    // 4. Handle Process
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bid'])) {
        $selected_lots = $_POST['selected_lots'] ?? [];
        $errors = [];
        $uploaded_file_paths = [];

        if (empty($selected_lots)) {
            $errors[] = "Please select at least one lot you wish to bid on.";
        }

        if (!isset($_FILES['bid_receipt']) || $_FILES['bid_receipt']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload a valid Bid Receipt / Proof of Payment.";
        }

        foreach ($selected_lots as $lot_id) {
            if (empty($_FILES['eligibility_docs']['name'][$lot_id])) {
                $errors[] = "Missing Eligibility & Technical document for Lot #{$lot_id}.";
            }
            if (empty($_FILES['financial_docs']['name'][$lot_id])) {
                $errors[] = "Missing Financial Proposal document for Lot #{$lot_id}.";
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

                foreach ($selected_lots as $lot_id) {
                    $types = [
                        'eligibility' => $_FILES['eligibility_docs'],
                        'financial'   => $_FILES['financial_docs']
                    ];

                    foreach ($types as $doc_type => $file_array) {
                        if (isset($file_array['error'][$lot_id]) && $file_array['error'][$lot_id] === UPLOAD_ERR_OK) {
                            $orig_filename = basename($file_array['name'][$lot_id]);
                            $file_tmp = $file_array['tmp_name'][$lot_id];
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
                            throw new Exception("Error uploading {$doc_type} document for Lot #{$lot_id}.");
                        }
                    }
                }
                $doc_stmt->close();

                $conn->commit();
                $_SESSION['alert_success'] = "Your proposal and payment receipt were submitted successfully!";
                header("Location: my_bids.php");
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                foreach ($uploaded_file_paths as $path) {
                    if (file_exists($path)) unlink($path);
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
    <title>Submit Proposal | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- ========================= -->
<!-- SIDEBAR                   -->
<!-- ========================= -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Bidder Portal</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="my_bids.php" class="nav-item"><i class="bi bi-inbox"></i><span>My Bids</span></a>
        <div class="nav-section-label">More</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-calendar-event"></i><span>Bid Schedule</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Bidder') ?></div>
                <div class="urole">Bidder</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- ========================= -->
<!-- TOPBAR                    -->
<!-- ========================= -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="topbar-title">Submit Bid Proposal</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page header -->
        <div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <a href="view_procurement.php?id=<?= $procurement_id ?>" class="vp-back-link">
                    <i class="bi bi-arrow-left"></i> Back to Procurement Details
                </a>
                <h2 style="margin-top:8px;">Submit Bid Proposal</h2>
                <p><?= htmlspecialchars($procurement['title']) ?></p>
            </div>
        </div>

        <!-- Validation errors -->
        <?php if (!empty($errors)): ?>
            <div class="sb-error-list">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>
                    <strong>Please fix the following before submitting:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data"
              onsubmit="return confirm('Submit this proposal and payment receipt? This cannot be undone.');">

            <!-- ========================= -->
            <!-- STEP 1: Select Lots       -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">1</div>
                    <div>
                        <h4>Select Lots to Bid On</h4>
                        <p>Choose one or more lots you wish to bid on. Each selected lot requires its own documents.</p>
                    </div>
                </div>

                <div class="sb-lot-select-list">
                    <?php foreach ($lots as $lot): ?>
                        <label class="sb-lot-check-card" id="card-<?= $lot['id'] ?>">
                            <input
                                type="checkbox"
                                name="selected_lots[]"
                                value="<?= $lot['id'] ?>"
                                class="lot-checkbox"
                                data-lot-id="<?= $lot['id'] ?>"
                                onchange="toggleLotUploads(this)"
                            >
                            <div class="sb-lot-check-body">
                                <div class="sb-lot-num">Lot <?= htmlspecialchars($lot['lot_number']) ?></div>
                                <div class="sb-lot-title"><?= htmlspecialchars($lot['lot_title']) ?></div>
                                <div class="sb-lot-abc"><i class="bi bi-cash"></i> ₱<?= number_format($lot['abc'], 2) ?></div>
                            </div>
                            <i class="bi bi-check-circle-fill sb-check-icon"></i>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ========================= -->
            <!-- STEP 2: Bid Receipt       -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">2</div>
                    <div>
                        <h4>Bidding Fee Receipt <span style="color:#e53935;">*</span></h4>
                        <p>Upload your Official Receipt or Proof of Payment. Accepted: PDF, JPG, PNG.</p>
                    </div>
                </div>

                <div class="file-upload-card">
                    <div class="file-upload-info">
                        <i class="bi bi-receipt"></i>
                        <div>
                            <strong>Payment Receipt / Official Receipt</strong>
                            <span>Required — must match the procurement fee</span>
                        </div>
                    </div>
                    <label class="file-upload-label" for="bid_receipt">
                        <i class="bi bi-upload"></i> Choose File
                        <input type="file" name="bid_receipt" id="bid_receipt"
                               accept=".pdf,.jpg,.jpeg,.png" required
                               onchange="showFileName(this, 'fn-receipt')">
                    </label>
                </div>
                <span class="file-name-display" id="fn-receipt"></span>
            </div>

            <!-- ========================= -->
            <!-- STEP 3: Per-lot docs      -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">3</div>
                    <div>
                        <h4>Proposal Documents Per Lot</h4>
                        <p>Select lots above to reveal their upload fields. All documents are encrypted upon submission.</p>
                    </div>
                </div>

                <div id="lot-uploads-container">
                    <div class="sb-no-lots-msg" id="noLotsMsg">
                        <i class="bi bi-arrow-up-circle"></i>
                        Select at least one lot above to upload its documents.
                    </div>

                    <?php foreach ($lots as $lot): ?>
                        <div class="sb-lot-upload-block hidden" id="lot-section-<?= $lot['id'] ?>">

                            <div class="sb-lot-upload-header">
                                <i class="bi bi-layers"></i>
                                Lot <?= htmlspecialchars($lot['lot_number']) ?> — <?= htmlspecialchars($lot['lot_title']) ?>
                            </div>

                            <div class="reg-grid" style="margin-top:14px;">

                                <div class="form-group">
                                    <label>Eligibility &amp; Technical Documents</label>
                                    <div class="file-upload-card" style="flex-direction:column; align-items:flex-start; gap:10px;">
                                        <div class="file-upload-info" style="padding:0;">
                                            <i class="bi bi-file-earmark-check" style="font-size:18px;"></i>
                                            <div>
                                                <strong>Eligibility Documents</strong>
                                                <span>PDF, DOC, DOCX, ZIP</span>
                                            </div>
                                        </div>
                                        <label class="file-upload-label" for="elig_<?= $lot['id'] ?>">
                                            <i class="bi bi-upload"></i> Choose File
                                            <input type="file"
                                                   name="eligibility_docs[<?= $lot['id'] ?>]"
                                                   id="elig_<?= $lot['id'] ?>"
                                                   accept=".pdf,.doc,.docx,.zip"
                                                   onchange="showFileName(this, 'fn-elig-<?= $lot['id'] ?>')">
                                        </label>
                                    </div>
                                    <span class="file-name-display" id="fn-elig-<?= $lot['id'] ?>"></span>
                                </div>

                                <div class="form-group">
                                    <label>Financial Proposal Form</label>
                                    <div class="file-upload-card" style="flex-direction:column; align-items:flex-start; gap:10px;">
                                        <div class="file-upload-info" style="padding:0;">
                                            <i class="bi bi-file-earmark-bar-graph" style="font-size:18px;"></i>
                                            <div>
                                                <strong>Financial Documents</strong>
                                                <span>PDF, DOC, DOCX, ZIP</span>
                                            </div>
                                        </div>
                                        <label class="file-upload-label" for="fin_<?= $lot['id'] ?>">
                                            <i class="bi bi-upload"></i> Choose File
                                            <input type="file"
                                                   name="financial_docs[<?= $lot['id'] ?>]"
                                                   id="fin_<?= $lot['id'] ?>"
                                                   accept=".pdf,.doc,.docx,.zip"
                                                   onchange="showFileName(this, 'fn-fin-<?= $lot['id'] ?>')">
                                        </label>
                                    </div>
                                    <span class="file-name-display" id="fn-fin-<?= $lot['id'] ?>"></span>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Submit / Cancel -->
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <button type="submit" name="submit_bid" class="btn-register">
                    <i class="bi bi-send-check-fill"></i> Submit Complete Proposal
                </button>
                <a href="view_procurement.php?id=<?= $procurement_id ?>" class="proc-action-btn delete" style="text-decoration:none; padding:13px 20px;">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            </div>

        </form>

    </div>
</main>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    function toggleLotUploads(checkbox) {
        const lotId   = checkbox.getAttribute('data-lot-id');
        const section = document.getElementById('lot-section-' + lotId);
        const card    = document.getElementById('card-' + lotId);
        const eligInput = document.getElementById('elig_' + lotId);
        const finInput  = document.getElementById('fin_' + lotId);
        const noMsg     = document.getElementById('noLotsMsg');

        if (checkbox.checked) {
            section.classList.remove('hidden');
            card.classList.add('selected');
            eligInput.required = true;
            finInput.required  = true;
        } else {
            section.classList.add('hidden');
            card.classList.remove('selected');
            eligInput.required = false;
            finInput.required  = false;
            eligInput.value    = '';
            finInput.value     = '';
        }

        // Hide hint if any lot is selected
        const anyChecked = document.querySelectorAll('.lot-checkbox:checked').length > 0;
        noMsg.style.display = anyChecked ? 'none' : 'flex';
    }

    // Filename display
    function showFileName(input, targetId) {
        const el = document.getElementById(targetId);
        if (!el) return;
        if (input.files[0]) {
            el.innerHTML =
                '<i class="bi bi-paperclip"></i> ' + input.files[0].name +
                '<button type="button" class="file-remove-btn" onclick="removeFile(\'' + input.name.replace(/[\[\]]/g,'\\\\$&') + '\',\'' + targetId + '\')">' +
                '<i class="bi bi-x-circle-fill"></i></button>';
        } else {
            el.innerHTML = '';
        }
    }

    function removeFile(inputName, targetId) {
        const label    = document.querySelector('input[name="' + inputName + '"]').closest('label');
        const oldInput = label.querySelector('input[type="file"]');
        const newInput = oldInput.cloneNode(true);
        newInput.value = '';
        oldInput.replaceWith(newInput);
        const el = document.getElementById(targetId);
        if (el) el.innerHTML = '';
    }
</script>

</body>
</html>
