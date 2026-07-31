<?php
    include ("utils/protect-page.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

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
        header("Location: bidder_procurement.php?error=not_found");
        exit();
    }

    // 2. Fetch Associated Lots
    $lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
    $lot_stmt->bind_param("i", $procurement_id);
    $lot_stmt->execute();
    $lots = $lot_stmt->get_result();

    // 3. Fetch Downloadable Documents
    $doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
    $doc_stmt->bind_param("i", $procurement_id);
    $doc_stmt->execute();
    $documents = $doc_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($procurement['title']); ?> - Details</title>
    <style>
        .details-card {
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
            margin-top: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .btn-submit {
            background-color: #28a745;
            color: #fff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        .btn-submit:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>
    Hello <?= htmlspecialchars($_SESSION["username"]); ?><br>
    <?php include("utils/side-nav.html"); ?>

    <p><a href="procurement.php">Back to Opportunities</a></p>

    <h2><?= htmlspecialchars($procurement['title']); ?></h2>

    <!-- General Procurement Information -->
    <div class="details-card">
        <h3>General Information</h3>
        <p><strong>PhilGEPS Ref No:</strong> <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A'); ?></p>
        <p><strong>Approved Budget for Contract (ABC):</strong> ₱<?= number_format($procurement['abc'], 2); ?></p>
        <p><strong>Procurement Mode:</strong> <?= htmlspecialchars($procurement['procurement_mode']); ?></p>
        <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($procurement['description'] ?? 'None')); ?></p>
        <hr>
        <p><strong>Posting Date:</strong> <?= htmlspecialchars($procurement['posting_date'] ?? 'N/A'); ?></p>
        <p><strong>Closing Date for Bids:</strong> <?= htmlspecialchars($procurement['closing_date'] ?? 'N/A'); ?></p>
        <p><strong>Opening Date:</strong> <?= htmlspecialchars($procurement['opening_date'] ?? 'N/A'); ?></p>
    </div>

    <!-- Downloadable Documents -->
    <div class="details-card">
        <h3>Bidding Documents (<?= $documents->num_rows; ?>)</h3>
        <?php if ($documents->num_rows > 0): ?>
            <ul>
                <?php while ($doc = $documents->fetch_assoc()): ?>
                    <li>
                        <a href="../admin/<?= htmlspecialchars($doc['file_path']); ?>" target="_blank" >
                            📄 <?= htmlspecialchars($doc['document_name']); ?>
                        </a>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="color: #888;">No bidding documents attached.</p>
        <?php endif; ?>
    </div>

    <!-- Associated Lots -->
    <div class="details-card">
        <h3>Lots Breakdown (<?= $lots->num_rows; ?>)</h3>
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
                    <?php while ($lot = $lots->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($lot['lot_number']); ?></td>
                            <td><?= htmlspecialchars($lot['lot_title']); ?></td>
                            <td><?= htmlspecialchars($lot['description']); ?></td>
                            <td>₱<?= number_format($lot['abc'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: #888;">No individual lots configured for this procurement.</p>
        <?php endif; ?>
    </div>

    <!-- Submission Action -->
    <div class="actions-bar">
        <a href="submit_bid.php?id=<?= $procurement_id; ?>" class="btn-submit">Submit Bid Proposal</a>
        <a href="procurement.php">Back</a>
    </div>

    <?php if (isset($_SESSION['alert_error'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_error']); ?>);
        </script>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>

</body>
</html>