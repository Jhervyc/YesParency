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
        if ($lots->num_rows === 0) {
            $_SESSION['alert_error'] = "Cannot publish a procurement with zero lots. Please add at least one lot first.";
            header("Location: review_procurement.php?id=" . $procurement_id);
            exit();
        }

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
    <title>Review Procurement | YesParency</title>
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
            <div class="sub">Admin Panel</div>
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

        <div class="nav-section-label">System</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Administrator</div>
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
        <span class="topbar-title">Review & Publish</span>
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
                <h2>Review & Publish Procurement</h2>
                <p>Check all details carefully before publishing. This will make the procurement publicly visible.</p>
            </div>
            <a href="procurement.php" class="proc-action-btn manage" style="text-decoration:none;">
                <i class="bi bi-arrow-left"></i> Back to Procurements
            </a>
        </div>

        <!-- ========================= -->
        <!-- GENERAL INFO              -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-file-earmark-text"></i> General Information
            </div>
            <div class="review-grid">
                <div class="review-field">
                    <span class="review-label">PhilGEPS Reference No.</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Procurement Mode</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['procurement_mode']) ?></span>
                </div>
                <div class="review-field review-field-full">
                    <span class="review-label">Project Title</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['title']) ?></span>
                </div>
                <div class="review-field review-field-full">
                    <span class="review-label">Description</span>
                    <span class="review-value" style="white-space:pre-line;"><?= htmlspecialchars($procurement['description'] ?? 'None') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Approved Budget (ABC)</span>
                    <span class="review-value review-value-accent">₱<?= number_format($procurement['abc'], 2) ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Status</span>
                    <span class="proc-status-pill <?= strtolower($procurement['status']) ?>" style="display:inline-block;">
                        <?= htmlspecialchars($procurement['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ========================= -->
        <!-- DATES                     -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-calendar3"></i> Important Dates
            </div>
            <div class="review-grid">
                <div class="review-field">
                    <span class="review-label">Posting Date</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['posting_date'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Bid Submission Deadline</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['closing_date'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Bid Opening Date</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['opening_date'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>

        <!-- ========================= -->
        <!-- DOCUMENTS                 -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-paperclip"></i> Uploaded Documents
                <span class="review-count"><?= $documents->num_rows ?></span>
            </div>

            <?php if ($documents->num_rows > 0): ?>
                <div class="doc-file-list">
                    <?php while ($doc = $documents->fetch_assoc()): ?>
                        <li>
                            <i class="bi bi-file-earmark-pdf"></i>
                            <span>
                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" style="color:#06251b; font-weight:600; text-decoration:none;">
                                    <?= htmlspecialchars($doc['document_name']) ?>
                                </a>
                            </span>
                            <small><i class="bi bi-box-arrow-up-right" style="font-size:11px;"></i> View</small>
                        </li>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="review-empty">
                    <i class="bi bi-file-earmark-x"></i> No documents uploaded for this procurement.
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= -->
        <!-- LOTS                      -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-layers"></i> Associated Lots
                <span class="review-count"><?= $lots->num_rows ?></span>
            </div>

            <?php if ($lots->num_rows > 0): ?>
                <?php
                    $total_lots_abc = 0;
                    $lots_data = [];
                    while ($lot = $lots->fetch_assoc()) {
                        $total_lots_abc += $lot['abc'];
                        $lots_data[] = $lot;
                    }
                ?>
                <div style="overflow-x:auto;">
                    <table class="awards-table">
                        <thead>
                            <tr>
                                <th>Lot No.</th>
                                <th>Lot Title</th>
                                <th>Description</th>
                                <th>ABC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lots_data as $lot): ?>
                            <tr>
                                <td><strong>Lot <?= htmlspecialchars($lot['lot_number']) ?></strong></td>
                                <td><?= htmlspecialchars($lot['lot_title']) ?></td>
                                <td style="color:#888; font-size:13px;"><?= htmlspecialchars($lot['description'] ?: '—') ?></td>
                                <td>₱<?= number_format($lot['abc'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right; font-weight:700; color:#06251b;">Total Lots ABC</td>
                                <td style="font-weight:700; color:#06251b;">₱<?= number_format($total_lots_abc, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="review-empty warn">
                    <i class="bi bi-exclamation-triangle"></i>
                    No lots configured yet. You must add at least one lot before publishing.
                    <a href="manage_lots.php?id=<?= $procurement_id ?>" style="color:#06251b; font-weight:600; margin-left:8px;">Add Lots →</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= -->
        <!-- ACTION BAR                -->
        <!-- ========================= -->
        <div class="review-action-bar">
            <a href="manage_lots.php?id=<?= $procurement_id ?>" class="proc-action-btn review" style="text-decoration:none; padding:12px 20px;">
                <i class="bi bi-pencil-square"></i> Edit Lots
            </a>

            <form method="POST" action="" onsubmit="return confirm('Publish this procurement? It will be publicly visible and open for bidding.');">
                <button type="submit" name="confirm_publish" class="btn-publish-final">
                    <i class="bi bi-send-check"></i> Confirm & Publish
                </button>
            </form>
        </div>

    </div>
</main>

<!-- Session alerts -->
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

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

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
