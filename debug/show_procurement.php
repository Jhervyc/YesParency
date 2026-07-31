<?php 
    include ("../config/db_connect.php");

    $procurement = null;
    $all_procurements = [];
    $lots = [];
    $error = "";
    $searched_id = "";
    $mode = ""; // Tracks whether we are viewing 'single' or 'all'

    if ($conn) {
        // Mode 1: Fetch All Procurements
        if (isset($_GET['action']) && $_GET['action'] === 'fetch_all') {
            $mode = 'all';
            $sql = "SELECT p.*, COUNT(l.id) as total_lots 
                    FROM procurements p 
                    LEFT JOIN lots l ON p.id = l.procurement_id 
                    GROUP BY p.id 
                    ORDER BY p.id DESC";
            $result = mysqli_query($conn, $sql);
            
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $all_procurements[] = $row;
                }
            } else {
                $error = "Error fetching procurements list: " . mysqli_error($conn);
            }
        } 
        // Mode 2: Fetch Single Procurement by ID
        elseif (isset($_GET['procurement_id']) && !empty(trim($_GET['procurement_id']))) {
            $mode = 'single';
            $searched_id = trim($_GET['procurement_id']);

            // 1. Fetch Procurement Details using prepared statements
            $stmt = mysqli_prepare($conn, "SELECT * FROM procurements WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $searched_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $procurement = mysqli_fetch_assoc($result);

            if ($procurement) {
                // 2. Fetch Associated Lots
                $lots_stmt = mysqli_prepare($conn, "SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
                mysqli_stmt_bind_param($lots_stmt, "i", $searched_id);
                mysqli_stmt_execute($lots_stmt);
                $lots_result = mysqli_stmt_get_result($lots_stmt);

                while ($row = mysqli_fetch_assoc($lots_result)) {
                    $lots[] = $row;
                }
            } else {
                $error = "No procurement found with ID: " . htmlspecialchars($searched_id);
            }
        }
    } else {
        $error = "Database connection failed. Please check db_connect.php.";
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Debugger</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
            color: #333;
        }
        h1, h2, h3 { color: #2c3e50; }
        .search-card, .data-card {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            border: 1px solid #ddd;
        }
        .form-container {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        form { display: flex; gap: 10px; }
        input[type="number"] {
            padding: 8px;
            font-size: 16px;
            width: 200px;
        }
        .btn {
            padding: 8px 16px;
            font-size: 16px;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background-color: #007bff; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-secondary { background-color: #6c757d; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-sm {
            padding: 4px 8px;
            font-size: 13px;
        }
        .error { color: #d9534f; font-weight: bold; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border: 1px solid #ddd;
        }
        th { background-color: #f2f2f2; }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            background-color: #e2e3e5;
        }
        .divider {
            border-left: 1px solid #ccc;
            height: 30px;
            margin: 0 5px;
        }
    </style>
</head>
<body>

    <h1>🛠️ Procurement Debug Tool</h1>

    <!-- Search & Control Card -->
    <div class="search-card">
        <div class="form-container">
            <!-- Search Form -->
            <form method="GET" action="">
                <input 
                    type="number" 
                    name="procurement_id" 
                    placeholder="Enter Procurement ID" 
                    value="<?= htmlspecialchars($searched_id) ?>" 
                    required
                >
                <button type="submit" class="btn btn-primary">Fetch Data</button>
            </form>

            <div class="divider"></div>

            <!-- Fetch All Button -->
            <a href="?action=fetch_all" class="btn btn-secondary">Fetch All Procurements</a>
        </div>
    </div>

    <!-- Error Message Display -->
    <?php if (!empty($error)): ?>
        <p class="error"><?= $error ?></p>
    <?php endif; ?>

    <!-- MODE: Display All Procurements Table -->
    <?php if ($mode === 'all'): ?>
        <div class="data-card">
            <h2>All Procurements (<?= count($all_procurements) ?> total)</h2>
            <?php if (!empty($all_procurements)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>PhilGEPS Ref No.</th>
                            <th>ABC</th>
                            <th>Status</th>
                            <th>Lots</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_procurements as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['id']) ?></td>
                                <td><strong><?= htmlspecialchars($p['title']) ?></strong></td>
                                <td><?= htmlspecialchars($p['philgeps_ref_no'] ?? 'N/A') ?></td>
                                <td>₱<?= number_format($p['abc'], 2) ?></td>
                                <td><span class="badge"><?= htmlspecialchars($p['status']) ?></span></td>
                                <td><?= $p['total_lots'] ?> lot(s)</td>
                                <td><?= $p['created_at'] ?></td>
                                <td>
                                    <a href="?procurement_id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Inspect</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><em>No procurements found in the database.</em></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- MODE: Single Procurement Details Section -->
    <?php if ($mode === 'single' && $procurement): ?>
        <div class="data-card">
            <h2>Procurement Details (ID: <?= htmlspecialchars($procurement['id']) ?>)</h2>
            <table>
                <tr>
                    <th>Title</th>
                    <td><?= htmlspecialchars($procurement['title']) ?></td>
                </tr>
                <tr>
                    <th>PhilGEPS Ref No.</th>
                    <td><?= htmlspecialchars($procurement['philgeps_ref_no']) ?></td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td><?= nl2br(htmlspecialchars($procurement['description'] ?? 'N/A')) ?></td>
                </tr>
                <tr>
                    <th>Approved Budget for the Contract (ABC)</th>
                    <td><strong>₱<?= number_format($procurement['abc'], 2) ?></strong></td>
                </tr>
                <tr>
                    <th>Procurement Mode</th>
                    <td><?= htmlspecialchars($procurement['procurement_mode'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><span class="badge"><?= htmlspecialchars($procurement['status']) ?></span></td>
                </tr>
                <tr>
                    <th>Key Dates</th>
                    <td>
                        <strong>Posting:</strong> <?= $procurement['posting_date'] ?? 'N/A' ?><br>
                        <strong>Opening:</strong> <?= $procurement['opening_date'] ?? 'N/A' ?><br>
                        <strong>Closing:</strong> <?= $procurement['closing_date'] ?? 'N/A' ?>
                    </td>
                </tr>
                <tr>
                    <th>Created By / Date</th>
                    <td>User ID: <?= htmlspecialchars($procurement['created_by'] ?? 'N/A') ?> | <?= $procurement['created_at'] ?></td>
                </tr>
            </table>
        </div>

        <!-- Associated Lots Section -->
        <div class="data-card">
            <h3>Associated Lots (<?= count($lots) ?> found)</h3>
            <?php if (!empty($lots)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Lot ID</th>
                            <th>Lot #</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Lot ABC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lots as $lot): ?>
                            <tr>
                                <td><?= htmlspecialchars($lot['id']) ?></td>
                                <td><strong><?= htmlspecialchars($lot['lot_number']) ?></strong></td>
                                <td><?= htmlspecialchars($lot['lot_title'] ?? 'N/A') ?></td>
                                <td><?= nl2br(htmlspecialchars($lot['description'] ?? 'N/A')) ?></td>
                                <td>₱<?= number_format($lot['abc'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><em>No lots attached to this procurement ID.</em></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</body>
</html>
