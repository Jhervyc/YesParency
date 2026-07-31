<?php 
    include("utils/protect-page.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($procurement_id === 0) {
        header("Location: procurement.php");
        exit();
    }

    // ------------------------------------
    // 1. ADD LOT
    // ------------------------------------
    if (isset($_POST['add_lot'])) {
        $lot_number = intval($_POST['lot_number']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $abc = floatval($_POST['abc']);

        // Check for duplicate lot number in this procurement
        $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? LIMIT 1");
        $check_stmt->bind_param("ii", $procurement_id, $lot_number);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            $_SESSION['alert_error'] = "Lot Number {$lot_number} already exists for this procurement!";
            header("Location: manage_lots.php?id=" . $procurement_id);
            exit();
        }
        $check_stmt->close();

        // Insert if no duplicate found
        $stmt = $conn->prepare("
            INSERT INTO lots (procurement_id, lot_number, lot_title, description, abc)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iissd", $procurement_id, $lot_number, $title, $description, $abc);
        $stmt->execute();
        $stmt->close();

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 2. UPDATE LOT (From Modal)
    // ------------------------------------
    if (isset($_POST['update_lot'])) {
        $lot_id = intval($_POST['lot_id']);
        $lot_number = intval($_POST['lot_number']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $abc = floatval($_POST['abc']);

        // Check if the new lot number belongs to ANOTHER lot in the same procurement
        $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? AND id != ? LIMIT 1");
        $check_stmt->bind_param("iii", $procurement_id, $lot_number, $lot_id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            $_SESSION['alert_error'] = "Lot Number {$lot_number} is already taken by another lot!";
            header("Location: manage_lots.php?id=" . $procurement_id);
            exit();
        }
        $check_stmt->close();

        // Update if valid
        $stmt = $conn->prepare("
            UPDATE lots 
            SET lot_number = ?, lot_title = ?, description = ?, abc = ? 
            WHERE id = ? AND procurement_id = ?
        ");
        $stmt->bind_param("issdii", $lot_number, $title, $description, $abc, $lot_id, $procurement_id);
        $stmt->execute();
        $stmt->close();

        header("Location: manage_lots.php?id=" . $procurement_id . "&status=updated");
        exit();
    }

    // ------------------------------------
    // 3. DELETE LOT
    // ------------------------------------
    if (isset($_POST['delete_lot'])) {
        $lot_id = intval($_POST['lot_id']);

        if ($lot_id > 0) {
            $stmt = $conn->prepare("DELETE FROM lots WHERE id = ? AND procurement_id = ?");
            $stmt->bind_param("ii", $lot_id, $procurement_id);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: manage_lots.php?id=" . $procurement_id . "&status=deleted");
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Lots</title>
    <style>
        .modal-backdrop {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; top: 0; 
            width: 100%; height: 100%; 
            background-color: rgba(0, 0, 0, 0.5); 
        }
        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 6px;
            width: 400px;
            position: relative;
        }
        .close-btn {
            position: absolute;
            right: 15px; top: 10px;
            font-size: 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    Hello <?php echo htmlspecialchars($_SESSION["username"]) . "<br>"; ?> 
    <?php include("utils/side-nav.html"); ?>

    <h2>Manage Lots</h2>

    <!-- Add Lot Form -->
    <form action="" method="POST">
        <input type="hidden" name="procurement_id" value="<?= $procurement_id; ?>">

        <div>
            <label>Lot Number</label><br>
            <input type="number" name="lot_number" min="1" required>
        </div>
        <br>
        <div>
            <label>Lot Title</label><br>
            <input type="text" name="title" required>
        </div>
        <br>
        <div>
            <label>Description</label><br>
            <textarea name="description" rows="4"></textarea>
        </div>
        <br>
        <div>
            <label>ABC</label><br>
            <input type="number" step="0.01" min="0" name="abc" required>
        </div>
        <br>
        <button type="submit" name="add_lot">Add Lot</button>
    </form>

    <h3>Existing Lots</h3>

    <table border="1" cellpadding="10">
        <tr>
            <th>Lot No.</th>
            <th>Title</th>
            <th>ABC</th>
            <th>Actions</th>
        </tr>

        <?php
        $query = "SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $procurement_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while($lot = $result->fetch_assoc()):
        ?>
        <tr>
            <td><?= htmlspecialchars($lot['lot_number']); ?></td>
            <td><?= htmlspecialchars($lot['lot_title']); ?></td>
            <td>₱<?= number_format($lot['abc'], 2); ?></td>
            <td>
                <!-- Edit Button -->
                <button type="button" 
                        class="edit-btn"
                        data-id="<?= $lot['id']; ?>"
                        data-number="<?= htmlspecialchars($lot['lot_number']); ?>"
                        data-title="<?= htmlspecialchars($lot['lot_title']); ?>"
                        data-description="<?= htmlspecialchars($lot['description']); ?>"
                        data-abc="<?= htmlspecialchars($lot['abc']); ?>">
                    Edit
                </button>

                |

                <!-- Delete Form -->
                <form action="" method="POST" style="display:inline;" onsubmit="return confirm('Delete this lot?');">
                    <input type="hidden" name="lot_id" value="<?= $lot['id']; ?>">
                    <button type="submit" name="delete_lot">Delete</button>
                </form>
            </td>
        </tr>
        <?php 
        endwhile; 
        $stmt->close();
        ?>
    </table>

    <br>
    <a href="procurement.php">Continue</a>

    <!-- EDIT LOT MODAL -->
    <div id="editLotModal" class="modal-backdrop">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h3>Edit Lot</h3>
            
            <form action="" method="POST">
                <input type="hidden" id="edit_lot_id" name="lot_id">

                <div>
                    <label>Lot Number</label><br>
                    <input type="number" id="edit_lot_number" name="lot_number" min="1" required>
                </div>
                <br>
                <div>
                    <label>Lot Title</label><br>
                    <input type="text" id="edit_title" name="title" required>
                </div>
                <br>
                <div>
                    <label>Description</label><br>
                    <textarea id="edit_description" name="description" rows="4"></textarea>
                </div>
                <br>
                <div>
                    <label>ABC</label><br>
                    <input type="number" step="0.01" min="0" id="edit_abc" name="abc" required>
                </div>
                <br>
                <button type="submit" name="update_lot">Update Lot</button>
                <button type="button" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Alert Trigger for Duplicate Errors -->
    <?php if (isset($_SESSION['alert_error'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_error']); ?>);
        </script>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>

    <script>
        const modal = document.getElementById('editLotModal');

        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('edit_lot_id').value = this.getAttribute('data-id');
                document.getElementById('edit_lot_number').value = this.getAttribute('data-number');
                document.getElementById('edit_title').value = this.getAttribute('data-title');
                document.getElementById('edit_description').value = this.getAttribute('data-description');
                document.getElementById('edit_abc').value = this.getAttribute('data-abc');

                modal.style.display = 'block';
            });
        });

        function closeModal() {
            modal.style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>