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
    // 2. UPDATE LOT
    // ------------------------------------
    if (isset($_POST['update_lot'])) {
        $lot_id = intval($_POST['lot_id']);
        $lot_number = intval($_POST['lot_number']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $abc = floatval($_POST['abc']);

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
    <title>Manage Lots | YesParency</title>
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
            <div class="sub">Super Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>

        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item"><i class="bi bi-people"></i><span>Bidder Accounts</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-journal-text"></i><span>Audit Trail</span></a>

        <div class="nav-section-label">Super Admin</div>
        <a href="user-role-management.php" class="nav-item"><i class="bi bi-person-gear"></i><span>User & Role Management</span></a>

        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Super Administrator</div>
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
        <span class="topbar-title">Manage Lots</span>
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
        <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>Manage Lots</h2>
                <p>Add, edit, or remove lots for this procurement.</p>
            </div>
            <a href="procurement.php" class="proc-action-btn manage" style="text-decoration:none;">
                <i class="bi bi-check-circle"></i> Done — Back to Procurements
            </a>
        </div>

        <div class="lots-layout">

            <!-- ========================= -->
            <!-- ADD LOT FORM              -->
            <!-- ========================= -->
            <div class="reg-section" style="flex-shrink:0;">
                <div class="reg-section-header">
                    <div class="reg-step-num"><i class="bi bi-plus-lg" style="font-size:14px;"></i></div>
                    <div>
                        <h4>Add New Lot</h4>
                        <p>Fill in the details and click Add Lot.</p>
                    </div>
                </div>

                <form action="" method="POST">
                    <input type="hidden" name="procurement_id" value="<?= $procurement_id ?>">

                    <div class="form-group">
                        <label>Lot Number</label>
                        <div class="input-wrapper">
                            <i class="bi bi-hash input-icon-left"></i>
                            <input type="number" name="lot_number" min="1" placeholder="e.g. 1" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:14px;">
                        <label>Lot Title</label>
                        <div class="input-wrapper">
                            <i class="bi bi-file-earmark-text input-icon-left"></i>
                            <input type="text" name="title" placeholder="e.g. Supply of Office Supplies" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:14px;">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Brief description of this lot..."></textarea>
                    </div>

                    <div class="form-group" style="margin-top:14px;">
                        <label>Approved Budget (ABC)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-cash input-icon-left"></i>
                            <input type="number" step="0.01" min="0" name="abc" placeholder="0.00" required>
                        </div>
                    </div>

                    <button type="submit" name="add_lot" class="btn-register" style="margin-top:20px; width:100%;">
                        <i class="bi bi-plus-circle"></i> Add Lot
                    </button>
                </form>
            </div>

            <!-- ========================= -->
            <!-- EXISTING LOTS TABLE       -->
            <!-- ========================= -->
            <div class="dash-panel" style="flex:1; min-width:0;">
                <div class="panel-header">
                    <h4><i class="bi bi-layers"></i> Existing Lots</h4>
                </div>

                <?php
                $query = "SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $procurement_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0):
                ?>

                <div class="proc-table-list">
                <?php while($lot = $result->fetch_assoc()): ?>
                    <div class="proc-row">
                        <div class="proc-row-status-bar open"></div>
                        <div class="proc-row-body">
                            <div class="proc-row-main">
                                <div class="proc-row-title">
                                    Lot <?= htmlspecialchars($lot['lot_number']) ?> — <?= htmlspecialchars($lot['lot_title']) ?>
                                </div>
                                <div class="proc-row-meta">
                                    <span><i class="bi bi-cash"></i> ₱<?= number_format($lot['abc'], 2) ?></span>
                                    <?php if (!empty($lot['description'])): ?>
                                    <span><i class="bi bi-text-paragraph"></i> <?= htmlspecialchars(mb_strimwidth($lot['description'], 0, 60, '…')) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="proc-row-actions">
                                <button type="button" class="proc-action-btn review edit-btn"
                                    data-id="<?= $lot['id'] ?>"
                                    data-number="<?= htmlspecialchars($lot['lot_number']) ?>"
                                    data-title="<?= htmlspecialchars($lot['lot_title']) ?>"
                                    data-description="<?= htmlspecialchars($lot['description']) ?>"
                                    data-abc="<?= htmlspecialchars($lot['abc']) ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <form method="POST" action="" style="display:inline;"
                                      onsubmit="return confirm('Delete Lot <?= $lot['lot_number'] ?>? This cannot be undone.');">
                                    <input type="hidden" name="lot_id" value="<?= $lot['id'] ?>">
                                    <button type="submit" name="delete_lot" class="proc-action-btn delete">
                                        <i class="bi bi-trash3"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; $stmt->close(); ?>
                </div>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-layers"></i>
                        <p>No lots added yet. Use the form to add the first lot.</p>
                    </div>
                    <?php $stmt->close(); ?>
                <?php endif; ?>
            </div>

        </div><!-- /.lots-layout -->

    </div>
</main>

<!-- ========================= -->
<!-- EDIT LOT MODAL            -->
<!-- ========================= -->
<div id="editLotModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-pencil-square"></i> Edit Lot</h4>
            <button class="modal-close" onclick="closeModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form action="" method="POST">
            <input type="hidden" id="edit_lot_id" name="lot_id">

            <div class="form-group">
                <label>Lot Number</label>
                <div class="input-wrapper">
                    <i class="bi bi-hash input-icon-left"></i>
                    <input type="number" id="edit_lot_number" name="lot_number" min="1" required>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <label>Lot Title</label>
                <div class="input-wrapper">
                    <i class="bi bi-file-earmark-text input-icon-left"></i>
                    <input type="text" id="edit_title" name="title" required>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <label>Description</label>
                <textarea id="edit_description" name="description" rows="3"></textarea>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <label>Approved Budget (ABC)</label>
                <div class="input-wrapper">
                    <i class="bi bi-cash input-icon-left"></i>
                    <input type="number" step="0.01" min="0" id="edit_abc" name="abc" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal()" class="proc-action-btn delete" style="border:none;">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button type="submit" name="update_lot" class="btn-register">
                    <i class="bi bi-floppy"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Session alerts -->
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<?php if (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> Lot updated successfully.
    </div>
<?php endif; ?>

<?php if (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> Lot deleted successfully.
    </div>
<?php endif; ?>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');
    const modal   = document.getElementById('editLotModal');

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

    // Populate & open edit modal
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_lot_id').value          = this.dataset.id;
            document.getElementById('edit_lot_number').value      = this.dataset.number;
            document.getElementById('edit_title').value           = this.dataset.title;
            document.getElementById('edit_description').value     = this.dataset.description;
            document.getElementById('edit_abc').value             = this.dataset.abc;
            modal.classList.add('open');
        });
    });

    function closeModal() {
        modal.classList.remove('open');
    }

    window.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
