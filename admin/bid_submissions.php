<?php
include("utils/protect-page.php"); // Ensure only Admin / BAC users can access

// Handle Action: Verify or Reject Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_bid'])) {
    $bid_id = intval($_POST['bid_id']);
    $new_status = $_POST['status_action'] === 'approve' ? 'submitted' : 'rejected';

    $update_stmt = $conn->prepare("UPDATE bids SET status = ? WHERE id = ?");
    $update_stmt->bind_param("si", $new_status, $bid_id);

    if ($update_stmt->execute()) {
        $_SESSION['alert_success'] = "Bid status successfully updated to " . strtoupper($new_status) . ".";
    } else {
        $_SESSION['alert_error'] = "Failed to update bid status.";
    }
    $update_stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// 1. Fetch Open Procurements with count of submitted bids
$proc_sql = "
    SELECT 
        p.id AS procurement_id,
        p.title AS procurement_title,
        p.philgeps_ref_no,
        COUNT(b.id) AS total_bids
    FROM procurements p
    LEFT JOIN bids b ON p.id = b.procurement_id
    WHERE p.status = 'open'
    GROUP BY p.id
    ORDER BY p.id DESC
";
$proc_result = $conn->query($proc_sql);

$procurements = [];
while ($p_row = $proc_result->fetch_assoc()) {
    $p_id = $p_row['procurement_id'];

    // 2. Fetch Bids submitted for this Procurement
    $bid_sql = "
        SELECT 
            b.id AS bid_id,
            b.submission_date,
            b.status AS bid_status,
            u.username AS bidder_name,
            u.email AS bidder_email
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ?
        ORDER BY b.submission_date DESC
    ";
    $bid_stmt = $conn->prepare($bid_sql);
    $bid_stmt->bind_param("i", $p_id);
    $bid_stmt->execute();
    $bids_res = $bid_stmt->get_result();

    $bids = [];
    while ($b_row = $bids_res->fetch_assoc()) {
        $b_id = $b_row['bid_id'];

        // 3. Fetch Applied Lots
        $lot_stmt = $conn->prepare("
            SELECT l.lot_number, l.lot_title 
            FROM bid_lots bl
            JOIN lots l ON bl.lot_id = l.id
            WHERE bl.bid_id = ?
        ");
        $lot_stmt->bind_param("i", $b_id);
        $lot_stmt->execute();
        $lots_res = $lot_stmt->get_result();
        $applied_lots = [];
        while ($l = $lots_res->fetch_assoc()) {
            $applied_lots[] = $l;
        }
        $lot_stmt->close();

        // 4. Fetch ONLY Receipt / Payment Document ('other')
        $doc_stmt = $conn->prepare("
            SELECT document_name, file_path 
            FROM bid_documents 
            WHERE bid_id = ? AND document_type = 'other' 
            LIMIT 1
        ");
        $doc_stmt->bind_param("i", $b_id);
        $doc_stmt->execute();
        $receipt = $doc_stmt->get_result()->fetch_assoc();
        $doc_stmt->close();

        $b_row['lots'] = $applied_lots;
        $b_row['receipt'] = $receipt;
        $bids[] = $b_row;
    }
    $bid_stmt->close();

    $p_row['bids'] = $bids;
    $procurements[] = $p_row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bid Submissions Verification - Admin</title>
    <style>
        .procurement{
            border: black 1px solid;
            padding: 20px;
            overflow: auto; 
        }
    </style>
</head>
<body>
    Hello <?= htmlspecialchars($_SESSION["username"] ?? "Admin"); ?><br>
    <?php include("utils/side-nav.html"); ?>

    <h2>Bid Submissions & Receipt Verification</h2>

    <?php if (empty($procurements)): ?>
        <p>No open procurements found.</p>
    <?php else: ?>
        <?php foreach ($procurements as $proc): ?>
            <div class="procurement">
                <h3><?= htmlspecialchars($proc['procurement_title']); ?></h3>
                <p>
                    PhilGEPS Ref No: <?= htmlspecialchars($proc['philgeps_ref_no'] ?? 'N/A'); ?><br>
                    Total Bids Received: <?= $proc['total_bids']; ?>
                </p>

                <?php if (empty($proc['bids'])): ?>
                    <p>No submitted bids for this procurement yet.</p>
                <?php else: ?>
                    <table border="1" cellpadding="5" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Bidder</th>
                                <th>Applied Lots</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Bidding Fee Receipt</th>
                                <th>Proposal Documents</th>
                                <th>Admin Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proc['bids'] as $bid): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($bid['bidder_name']); ?></strong><br>
                                        <small><?= htmlspecialchars($bid['bidder_email']); ?></small>
                                    </td>
                                    <td>
                                        <ul>
                                            <?php foreach ($bid['lots'] as $lot): ?>
                                                <li>Lot #<?= htmlspecialchars($lot['lot_number']); ?>: <?= htmlspecialchars($lot['lot_title']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                    <td><?= date("M j, Y g:i A", strtotime($bid['submission_date'])); ?></td>
                                    <td>
                                        <?= $bid['bid_status'] === 'pending' ? 'Pending Receipt Check' : ucfirst($bid['bid_status']); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($bid['receipt'])): ?>
                                            <a href="../bidder/<?= htmlspecialchars($bid['receipt']['file_path']); ?>" target="_blank">
                                                View Payment Receipt
                                            </a>
                                        <?php else: ?>
                                            No receipt uploaded
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        [Sealed] Eligibility<br>
                                        [Sealed] Financial
                                    </td>
                                    <td>
                                        <?php if ($bid['bid_status'] === 'pending'): ?>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Verify receipt and approve this bid for submission?');">
                                                <input type="hidden" name="bid_id" value="<?= $bid['bid_id']; ?>">
                                                <input type="hidden" name="status_action" value="approve">
                                                <button type="submit" name="action_verify_bid">Approve</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Reject this bid due to invalid receipt?');">
                                                <input type="hidden" name="bid_id" value="<?= $bid['bid_id']; ?>">
                                                <input type="hidden" name="status_action" value="reject">
                                                <button type="submit" name="action_verify_bid">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            Action completed (<?= ucfirst($bid['bid_status']); ?>)
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <hr>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>