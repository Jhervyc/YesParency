<?php
    include("utils/protect-page.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

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
    $lots = $lot_stmt->get_result();

    // 3. Fetch Uploaded Documents
    $doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
    $doc_stmt->bind_param("i", $procurement_id);
    $doc_stmt->execute();
    $documents = $doc_stmt->get_result();

    // 4. Handle Final Publish Trigger
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_publish'])) {
        // Optional validation: Ensure at least one lot exists before publishing
        if ($lots->num_rows === 0) {
            $_SESSION['alert_error'] = "Cannot publish a procurement with zero lots. Please add at least one lot first.";
            header("Location: review_procurement.php?id=" . $procurement_id);
            exit();
        }

        // Update status to 'open'
        $pub_stmt = $conn->prepare("UPDATE procurements SET status = 'open' WHERE id = ?");
        $pub_stmt->bind_param("i", $procurement_id);
        
        if ($pub_stmt->execute()) {
            $pub_stmt->close();
            $_SESSION['alert_success'] = "Procurement has been successfully published!";
            header("Location: procurement.php");
            exit();
        } else {
            $pub_stmt->close();
            $_SESSION['alert_error'] = "Failed to update procurement status.";
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review & Publish Procurement</title>
    <style>
        .review-card {
            border: 1px solid #ccc;
            padding: 20px;
            background: #fafafa;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .lot-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .lot-table th, .lot-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .lot-table th {
            background-color: #f0f0f0;
        }
        .actions-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-top: 20px;
        }
        .btn-publish {
            background-color: #28a745;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-edit {
            padding: 10px 15px;
            text-decoration: none;
            background: #007bff;
            color: #fff;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    Hello <?= htmlspecialchars($_SESSION["username"]); ?><br>
    <?php include("utils/side-nav.html"); ?>

    <h2>Review Procurement Details</h2>

    <!-- General Procurement Summary -->
    <div class="review-card">
        <h3>General Information</h3>
        <p><strong>PhilGEPS Ref No:</strong> <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A'); ?></p>
        <p><strong>Title:</strong> <?= htmlspecialchars($procurement['title']); ?></p>
        <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($procurement['description'] ?? 'None')); ?></p>
        <p><strong>Approved Budget for Contract (ABC):</strong> ₱<?= number_format($procurement['abc'], 2); ?></p>
        <p><strong>Procurement Mode:</strong> <?= htmlspecialchars($procurement['procurement_mode']); ?></p>
        <p><strong>Current Status:</strong> <strong><?= htmlspecialchars($procurement['status']); ?></strong></p>
        <hr>
        <p><strong>Posting Date:</strong> <?= htmlspecialchars($procurement['posting_date'] ?? 'N/A'); ?></p>
        <p><strong>Closing Date:</strong> <?= htmlspecialchars($procurement['closing_date'] ?? 'N/A'); ?></p>
        <p><strong>Opening Date:</strong> <?= htmlspecialchars($procurement['opening_date'] ?? 'N/A'); ?></p>
    </div>

    <!-- Associated Documents Summary -->
    <div class="review-card">
        <h3>Uploaded Documents (<?= $documents->num_rows; ?>)</h3>
        
        <?php if ($documents->num_rows > 0): ?>
            <ul>
                <?php while ($doc = $documents->fetch_assoc()): ?>
                    <li>
                        <a href="<?= htmlspecialchars($doc['file_path']); ?>" target="_blank">
                            <?= htmlspecialchars($doc['document_name']); ?>
                        </a>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="color: #888;">No documents uploaded for this procurement.</p>
        <?php endif; ?>
    </div>

    <!-- Lots Summary -->
    <div class="review-card">
        <h3>Associated Lots (<?= $lots->num_rows; ?>)</h3>
        
        <?php if ($lots->num_rows > 0): ?>
            <table class="lot-table">
                <thead>
                    <tr>
                        <th>Lot No.</th>
                        <th>Lot Title</th>
                        <th>Description</th>
                        <th>ABC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        $total_lots_abc = 0;
                        while ($lot = $lots->fetch_assoc()): 
                            $total_lots_abc += $lot['abc'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($lot['lot_number']); ?></td>
                            <td><?= htmlspecialchars($lot['lot_title']); ?></td>
                            <td><?= htmlspecialchars($lot['description']); ?></td>
                            <td>₱<?= number_format($lot['abc'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;"><strong>Total Lots ABC:</strong></td>
                        <td><strong>₱<?= number_format($total_lots_abc, 2); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <p style="color: #d9534f;">No lots configured yet for this procurement.</p>
        <?php endif; ?>
    </div>

    <!-- Actions -->
    <div class="actions-bar">
        <a href="manage_lots.php?id=<?= $procurement_id; ?>" class="btn-edit">← Edit Lots</a>

        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to publish this procurement?');">
            <button type="submit" name="confirm_publish" class="btn-publish">✓ Confirm & Publish</button>
        </form>

        <a href="procurement.php">Cancel</a>
    </div>

    <!-- Session Error Alert -->
    <?php if (isset($_SESSION['alert_error'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_error']); ?>);
        </script>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>
</body>
</html>