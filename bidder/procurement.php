<?php
    include ("utils/protect-page.php");

    // Fetch all open procurements available for bidding
    $query = "
        SELECT 
            p.id,
            p.philgeps_ref_no,
            p.title,
            p.abc,
            p.procurement_mode,
            p.closing_date,
            (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
            (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
        FROM procurements p
        WHERE p.status = 'open'
        ORDER BY p.closing_date ASC
    ";
    
    $result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Opportunities | Bidder Portal</title>
    <style>
        #procurement {
            color: gold;
        }
        .procurement-card {
            border: 1px solid #ccc;
            padding: 16px;
            margin-bottom: 16px;
            background-color: #fcfcfc;
            border-radius: 6px;
        }
        .procurement-card h3 {
            margin-top: 0;
            margin-bottom: 8px;
        }
        .meta-info {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 12px;
        }
        .btn-view {
            display: inline-block;
            background-color: #007bff;
            color: #fff;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        .btn-view:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    Hello <?php echo htmlspecialchars($_SESSION["username"]) . "<br>"; ?> 
    <?php include("utils/side-nav.html"); ?>
    <hr>

    <h2>Active Bidding Opportunities</h2>

    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <?php while ($procurement = mysqli_fetch_assoc($result)): ?>
            <div class="procurement-card">
                <h3><?= htmlspecialchars($procurement['title']); ?></h3>
                
                <div class="meta-info">
                    <strong>PhilGEPS Ref No:</strong> <?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A'); ?> | 
                    <strong>Mode:</strong> <?= htmlspecialchars($procurement['procurement_mode']); ?> | 
                    <strong>Approved Budget (ABC):</strong> ₱<?= number_format($procurement['abc'], 2); ?>
                </div>
 
                <p>
                    <strong>Closing Deadline:</strong> 
                    <?= $procurement['closing_date'] ? date('F j, Y, g:i a', strtotime($procurement['closing_date'])) : 'N/A'; ?>
                    <br>
                    <small>Lots: <?= $procurement['lot_count']; ?> | Documents attached: <?= $procurement['doc_count']; ?></small>
                </p>

                <a href="view_procurement.php?id=<?= $procurement['id']; ?>" class="btn-view">View Details & Submit Bid →</a>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No active procurements open for bidding at this time.</p>
    <?php endif; ?>

</body>
</html>