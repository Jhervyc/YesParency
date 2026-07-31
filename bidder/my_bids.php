<?php
include("utils/protect-page.php");

$bidder_id = intval($_SESSION['user_id']);

// Fetch all bids submitted by this bidder along with procurement title and documents
$sql = "
    SELECT 
        b.id AS bid_id,
        b.submission_date,
        b.status AS bid_status,
        p.id AS procurement_id,
        p.title AS procurement_title,
        p.philgeps_ref_no
    FROM bids b
    JOIN procurements p ON b.procurement_id = p.id
    WHERE b.bidder_id = ?
    ORDER BY b.submission_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bidder_id);
$stmt->execute();
$bids_result = $stmt->get_result();

$my_bids = [];
while ($row = $bids_result->fetch_assoc()) {
    $bid_id = $row['bid_id'];

    // 1. Fetch Selected Lots for this Bid
    $lots_stmt = $conn->prepare("
        SELECT l.lot_number, l.lot_title, l.abc 
        FROM bid_lots bl
        JOIN lots l ON bl.lot_id = l.id
        WHERE bl.bid_id = ?
        ORDER BY l.lot_number ASC
    ");
    $lots_stmt->bind_param("i", $bid_id);
    $lots_stmt->execute();
    $lots_res = $lots_stmt->get_result();
    $bid_lots = [];
    while ($lot = $lots_res->fetch_assoc()) {
        $bid_lots[] = $lot;
    }
    $lots_stmt->close();

    // 2. Fetch Uploaded Documents for this Bid
    $docs_stmt = $conn->prepare("
        SELECT document_type, document_name, file_path 
        FROM bid_documents 
        WHERE bid_id = ?
    ");
    $docs_stmt->bind_param("i", $bid_id);
    $docs_stmt->execute();
    $docs_res = $docs_stmt->get_result();
    $bid_docs = [];
    while ($doc = $docs_res->fetch_assoc()) {
        $bid_docs[] = $doc;
    }
    $docs_stmt->close();

    $row['lots'] = $bid_lots;
    $row['documents'] = $bid_docs;
    $my_bids[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Submitted Bids</title>
    <style>
        body {
        }
        .bid-card {
            border: 1px solid #ddd;
            background: #ffffff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .bid-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .bid-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #0056b3;
            margin: 0;
        }
        .ref-no {
            font-size: 0.85rem;
            color: #666;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-pending { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge-submitted { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-opened { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .badge-awarded { background-color: #28a745; color: #ffffff; }
        .badge-rejected { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .section-title {
            font-size: 0.95rem;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 6px;
            color: #444;
        }
        .lot-list, .doc-list {
            margin: 0;
            padding-left: 20px;
        }
        .lot-list li, .doc-list li {
            margin-bottom: 4px;
            font-size: 0.9rem;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            background: #f9f9f9;
            border: 1px dashed #ccc;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    Hello <?= htmlspecialchars($_SESSION["username"] ?? "Bidder"); ?><br>
    <?php include("utils/side-nav.html"); ?>

    <h2>My Submitted Proposals</h2>

    <?php if (empty($my_bids)): ?>
        <div class="empty-state">
            <p>You have not submitted any bids yet.</p>
            <a href="bidder_procurement.php">View Available Procurements</a>
        </div>
    <?php else: ?>
        <?php foreach ($my_bids as $bid): ?>
            <div class="bid-card">
                <div class="bid-header">
                    <div>
                        <a href="view_procurement.php?id=<?= $bid['procurement_id']; ?>" class="bid-title">
                            <?= htmlspecialchars($bid['procurement_title']); ?>
                        </a>
                        <div class="ref-no">Ref No: <?= htmlspecialchars($bid['philgeps_ref_no'] ?? 'N/A'); ?></div>
                    </div>
                    <div>
                        <?php
                            $status = $bid['bid_status'];
                            $badge_class = 'badge-' . $status;
                            
                            $status_labels = [
                                'pending'   => 'Pending Verification',
                                'submitted' => 'Verified / Submitted',
                                'opened'    => 'Opened',
                                'awarded'   => 'Awarded',
                                'rejected'  => 'Rejected'
                            ];
                            $display_status = $status_labels[$status] ?? 
                            ucfirst($status);
                        ?>
                        <span class="status-badge <?= $badge_class; ?>">
                            <?= $display_status; ?>
                        </span>
                    </div>
                </div>

                <p style="font-size: 0.85rem; color: #555;">
                    <strong>Date Submitted:</strong> <?= date("F j, Y, g:i a", strtotime($bid['submission_date'])); ?>
                </p>

                <!-- Selected Lots -->
                <div class="section-title">Applied Lots:</div>
                <ul class="lot-list">
                    <?php foreach ($bid['lots'] as $lot): ?>
                        <li>
                            <strong>Lot #<?= htmlspecialchars($lot['lot_number']); ?>:</strong> 
                            <?= htmlspecialchars($lot['lot_title']); ?> 
                            (ABC: ₱<?= number_format($lot['abc'], 2); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Uploaded Documents -->
                <div class="section-title">Submitted Files:</div>
                <ul class="doc-list">
                    <?php foreach ($bid['documents'] as $doc): ?>
                        <li>
                            <?= htmlspecialchars($doc['document_name']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>