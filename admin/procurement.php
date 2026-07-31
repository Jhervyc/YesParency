<?php 
    include("utils/protect-page.php");

    // 1. Get status filter from URL (defaults to 'all', converted to lowercase for DB matching)
    $status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';

    // Valid status list based on your DB schema (excluding cancelled)
    $valid_statuses = ['draft', 'open', 'closed', 'awarded'];
    if ($status_filter !== 'all' && !in_array($status_filter, $valid_statuses)) {
        $status_filter = 'all';
    }

    // 2. Handle Delete POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_procurement'])) {
    $procurement_id = isset($_POST['procurement_id']) ? intval($_POST['procurement_id']) : 0;

    if ($procurement_id > 0) {
        // 1. Fetch file paths of associated documents before deleting
        $doc_stmt = mysqli_prepare($conn, "SELECT file_path FROM procurement_documents WHERE procurement_id = ?");
        mysqli_stmt_bind_param($doc_stmt, "i", $procurement_id);
        mysqli_stmt_execute($doc_stmt);
        $result = mysqli_stmt_get_result($doc_stmt);

        // 2. Delete physical files from server disk
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                unlink($row['file_path']); // Permanently removes file from uploads folder
            }
        }
        mysqli_stmt_close($doc_stmt);
        
        // 3. Delete main procurement record
        $del_stmt = mysqli_prepare($conn, "DELETE FROM procurements WHERE id = ?");
        mysqli_stmt_bind_param($del_stmt, "i", $procurement_id);
        mysqli_stmt_execute($del_stmt);
        mysqli_stmt_close($del_stmt);

        $_SESSION['alert_success'] = "Procurement and associated files deleted successfully.";
    }

    // Preserve selected status tab after delete redirect
    header("Location: procurement.php?status=" . urlencode($status_filter));
    exit();
}

    // 3. Fetch procurements based on selected status
    if ($status_filter !== 'all') {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements WHERE LOWER(status) = ? ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "s", $status_filter);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, title, philgeps_ref_no, abc, procurement_mode, status FROM procurements ORDER BY id DESC");
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Procurements</title>
    <style>
        .status-tabs {
            margin: 15px 0;
            display: flex;
            gap: 10px;
        }
        .tab-btn {
            padding: 8px 16px;
            text-decoration: none;
            color: #333;
            border: 1px solid #ccc;
            background-color: #f4f4f4;
            border-radius: 4px;
            text-transform: capitalize;
        }
        .tab-btn.active {
            background-color: #007bff;
            color: #fff;
            border-color: #007bff;
        }
        .procurement-card {
            border: 1px solid #e0e0e0;
            padding: 15px;
            margin-bottom: 12px;
            border-radius: 6px;
        }
        .action-btns {
            margin-top: 10px;
            display: flex;
            gap: 8px;
        }
        .status-badge {
            text-transform: uppercase;
            font-weight: bold;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 3px;
            background: #e9ecef;
        }
    </style>
</head>
<body>
    Hello <?php echo htmlspecialchars($_SESSION["username"]) . "<br>"; ?> 
    <?php include("utils/side-nav.html"); ?>

    <div>
        <h2>Procurement</h2>
        <a href="create_procurement.php">Add Procurement</a>
    </div>

    <!-- Status Tabs (5 Buttons matching database ENUMs) -->
    <div class="status-tabs">
        <a href="procurement.php?status=all" class="tab-btn <?= $status_filter === 'all' ? 'active' : '' ?>">All</a>
        <a href="procurement.php?status=draft" class="tab-btn <?= $status_filter === 'draft' ? 'active' : '' ?>">Draft</a>
        <a href="procurement.php?status=open" class="tab-btn <?= $status_filter === 'open' ? 'active' : '' ?>">Open</a>
        <a href="procurement.php?status=closed" class="tab-btn <?= $status_filter === 'closed' ? 'active' : '' ?>">Closed</a>
        <a href="procurement.php?status=awarded" class="tab-btn <?= $status_filter === 'awarded' ? 'active' : '' ?>">Awarded</a>
    </div>

    <div>
        <h3>Available Procurements</h3>

        <?php if (mysqli_num_rows($result) > 0): ?>
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                <?php $current_status = strtolower($row['status']); ?>
                <div class="procurement-card">
                    <div>
                        <strong>Title:</strong> <?= htmlspecialchars($row['title']) ?><br>
                        <strong>PhilGEPS Ref:</strong> <?= htmlspecialchars($row['philgeps_ref_no'] ?? 'N/A') ?><br>
                        <strong>ABC:</strong> ₱<?= number_format($row['abc'], 2) ?><br>
                        <strong>Mode:</strong> <?= htmlspecialchars($row['procurement_mode'] ?? 'N/A') ?><br>
                        <strong>Status:</strong> <span class="status-badge"><?= htmlspecialchars($row['status']) ?></span>
                    </div>
                    <div class="action-btns">
                        <!-- Review & Publish (For Drafts) -->
                        <?php if ($current_status === 'draft'): ?>
                            <a href="review_procurement.php?id=<?= $row['id']; ?>">
                                <button type="button">Review & Publish</button>
                            </a>
                        <?php endif; ?>

                        <!-- Manage Lots / Details -->
                        <?php if (in_array($current_status, ['draft', 'open'])): ?>
                            <a href="manage_lots.php?id=<?= $row['id'] ?>">
                                <button type="button">Manage Procurement</button>
                            </a>
                        <?php endif; ?>

                        <form method="POST" action="procurement.php?status=<?= urlencode($status_filter) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this procurement?');">
                            <input type="hidden" name="procurement_id" value="<?= $row['id'] ?>">
                            <button type="submit" name="delete_procurement">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No procurements found for status "<?= htmlspecialchars($status_filter) ?>".</p>
        <?php endif; ?>

        <?php mysqli_stmt_close($stmt); ?>
    </div>

    <!-- Alert Dialogs -->
    <?php if (isset($_SESSION['alert_error'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_error']); ?>);
        </script>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['alert_success'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_success']); ?>);
        </script>
        <?php unset($_SESSION['alert_success']); ?>
    <?php endif; ?>
</body>
</html>