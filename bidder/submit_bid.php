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

        // Validation: At least 1 lot selected
        if (empty($selected_lots)) {
            $errors[] = "Please select at least one lot you wish to bid on.";
        }

        // Validation: Bid Receipt is required
        if (!isset($_FILES['bid_receipt']) || $_FILES['bid_receipt']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload a valid Bid Receipt / Proof of Payment.";
        }

        // Validation: For every selected lot, verify both files are attached
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
                // A. Create Bid Entry (Status = 'pending')
                $bid_stmt = $conn->prepare("INSERT INTO bids (bidder_id, procurement_id, status) VALUES (?, ?, 'pending')");
                $bid_stmt->bind_param("ii", $bidder_id, $procurement_id);
                $bid_stmt->execute();
                $bid_id = $conn->insert_id;
                $bid_stmt->close();

                // B. Insert Selected Lots
                $lot_bid_stmt = $conn->prepare("INSERT INTO bid_lots (bid_id, lot_id) VALUES (?, ?)");
                foreach ($selected_lots as $lot_id) {
                    $clean_lot_id = intval($lot_id);
                    $lot_bid_stmt->bind_param("ii", $bid_id, $clean_lot_id);
                    $lot_bid_stmt->execute();
                }
                $lot_bid_stmt->close();

                // C. Upload Bid Receipt (Saved in bid_documents as 'other')
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

                // D. Upload Per-Lot Eligibility & Financial Folders
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

                        $result = encryptFile(
                            $file_tmp,
                            $target,
                            $doc_type
                        );

                        if ($result['success']) {

                            $uploaded_file_paths[] = $target;

                            $display_name = ucfirst($doc_type) . " ({$orig_filename})";

                            $doc_stmt->bind_param("isss", $bid_id, $doc_type, $display_name, $target);
                            $doc_stmt->execute();

                        } else {
                            throw new Exception($result['message']);
                        }
                    } else {
                        // Optional: throw error if a required file failed uploading
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
                    if (file_exists($path)) {
                        unlink($path);
                    }
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
    <title>Submit Proposal - <?= htmlspecialchars($procurement['title']); ?></title>
    <style>
        .form-card {
            border: 1px solid #ccc;
            padding: 20px;
            background: #fafafa;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .lot-upload-card {
            border: 1px solid #cce5ff;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
        }
        .lot-upload-card.hidden {
            display: none;
        }
        .form-group {
            margin-bottom: 12px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .btn-submit {
            background-color: #28a745;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    Hello <?= htmlspecialchars($_SESSION["username"] ?? "Bidder"); ?><br>
    <?php include("utils/side-nav.html"); ?>

    <p><a href="view_procurement.php?id=<?= $procurement_id; ?>">← Back to Details</a></p>

    <h2>Submit Proposal</h2>

    <?php if (!empty($errors)): ?>
        <div class="alert-danger">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data" onsubmit="return confirm('Submit this proposal and receipt?');">
        
        <!-- 1. Select Lots -->
        <div class="form-card">
            <h3>1. Select Lots to Bid On</h3>
            <?php foreach ($lots as $lot): ?>
                <div style="margin-bottom: 10px;">
                    <label>
                        <input 
                            type="checkbox" 
                            name="selected_lots[]" 
                            value="<?= $lot['id']; ?>"
                            class="lot-checkbox"
                            data-lot-id="<?= $lot['id']; ?>"
                            onchange="toggleLotUploads(this)"
                        >
                        <strong>Lot #<?= htmlspecialchars($lot['lot_number']); ?>:</strong> <?= htmlspecialchars($lot['lot_title']); ?> (ABC: ₱<?= number_format($lot['abc'], 2); ?>)
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 2. Bidding Fee Receipt -->
        <div class="form-card">
            <h3>2. Bidding Fee Receipt <span style="color:red;">*</span></h3>
            <div class="form-group">
                <label for="bid_receipt">Upload Payment Receipt / Official Receipt (PDF, JPG, PNG)</label>
                <input type="file" name="bid_receipt" id="bid_receipt" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
        </div>

        <!-- 3. Dynamic Per-Lot Upload Sections -->
        <div class="form-card">
            <h3>3. Proposal Documents Per Lot</h3>
            <p><small>Check a lot above to reveal its document upload fields.</small></p>

            <?php foreach ($lots as $lot): ?>
                <div class="lot-upload-card hidden" id="lot-section-<?= $lot['id']; ?>">
                    <h4>Uploads for Lot #<?= htmlspecialchars($lot['lot_number']); ?> - <?= htmlspecialchars($lot['lot_title']); ?></h4>
                    
                    <div class="form-group">
                        <label>Eligibility & Technical Documents (PDF/ZIP)</label>
                        <input 
                            type="file" 
                            name="eligibility_docs[<?= $lot['id']; ?>]" 
                            id="elig_<?= $lot['id']; ?>"
                            accept=".pdf,.doc,.docx,.zip"
                        >
                    </div>

                    <div class="form-group">
                        <label>Financial Proposal Form (PDF/ZIP)</label>
                        <input 
                            type="file" 
                            name="financial_docs[<?= $lot['id']; ?>]" 
                            id="fin_<?= $lot['id']; ?>"
                            accept=".pdf,.doc,.docx,.zip"
                        >
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions -->
        <div style="margin-top: 20px;">
            <button type="submit" name="submit_bid" class="btn-submit">Submit Complete Proposal</button>
            <a href="view_procurement.php?id=<?= $procurement_id; ?>">Cancel</a>
        </div>
    </form>

    <script>
        function toggleLotUploads(checkbox) {
            const lotId = checkbox.getAttribute('data-lot-id');
            const section = document.getElementById('lot-section-' + lotId);
            const eligInput = document.getElementById('elig_' + lotId);
            const finInput = document.getElementById('fin_' + lotId);

            if (checkbox.checked) {
                section.classList.remove('hidden');
                eligInput.required = true;
                finInput.required = true;
            } else {
                section.classList.add('hidden');
                eligInput.required = false;
                finInput.required = false;
                eligInput.value = ''; // clear file if unchecked
                finInput.value = '';
            }
        }
    </script>
</body>
</html>