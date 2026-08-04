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
    <title><?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
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
            <div class="sub">Bidder Portal</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="my_bids.php" class="nav-item"><i class="bi bi-inbox"></i><span>My Bids</span></a>
        <div class="nav-section-label">More</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-calendar-event"></i><span>Bid Schedule</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Bidder</div>
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
        <span class="topbar-title">Procurement Details</span>
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
        <div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <a href="procurement.php" class="vp-back-link">
                    <i class="bi bi-arrow-left"></i> Back to Opportunities
                </a>
                <h2 style="margin-top:8px;"><?= htmlspecialchars($procurement['title']) ?></h2>
                <p>Review all details carefully before submitting your bid proposal.</p>
            </div>
            <a href="submit_bid.php?id=<?= $procurement_id ?>" class="vp-btn-submit">
                <i class="bi bi-send-check"></i> Submit Bid Proposal
            </a>
        </div>

        <!-- Two-column layout -->
        <div class="vp-layout">

            <!-- LEFT: main info -->
            <div class="vp-main">

                <!-- General info -->
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
                        <div class="review-field">
                            <span class="review-label">Approved Budget (ABC)</span>
                            <span class="review-value review-value-accent">₱<?= number_format($procurement['abc'], 2) ?></span>
                        </div>
                        <div class="review-field">
                            <span class="review-label">Status</span>
                            <span class="proc-status-pill open" style="display:inline-block;">Open</span>
                        </div>
                        <?php if (!empty($procurement['description'])): ?>
                        <div class="review-field review-field-full">
                            <span class="review-label">Description</span>
                            <span class="review-value" style="white-space:pre-line;"><?= htmlspecialchars($procurement['description']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dates -->
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

                <!-- Lots table -->
                <div class="review-section">
                    <div class="review-section-title">
                        <i class="bi bi-layers"></i> Lots Breakdown
                        <span class="review-count"><?= $lots->num_rows ?></span>
                    </div>

                    <?php if ($lots->num_rows > 0): ?>
                        <?php
                            $lots_data = [];
                            $total_abc = 0;
                            while ($lot = $lots->fetch_assoc()) {
                                $total_abc += $lot['abc'];
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
                                        <td colspan="3" style="text-align:right; font-weight:700; color:#06251b;">Total ABC</td>
                                        <td style="font-weight:700; color:#06251b;">₱<?= number_format($total_abc, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="review-empty">
                            <i class="bi bi-layers"></i> No individual lots configured for this procurement.
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.vp-main -->

            <!-- RIGHT: documents + submit -->
            <div class="vp-sidebar">

                <!-- Bidding documents -->
                <div class="review-section">
                    <div class="review-section-title">
                        <i class="bi bi-paperclip"></i> Bidding Documents
                        <span class="review-count"><?= $documents->num_rows ?></span>
                    </div>

                    <?php if ($documents->num_rows > 0): ?>
                        <div class="gb-doc-list">
                        <?php while ($doc = $documents->fetch_assoc()): ?>
                            <div class="gb-doc-item">
                                <div class="gb-doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                                <div class="gb-doc-info"><?= htmlspecialchars($doc['document_name']) ?></div>
                                <a href="../admin/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="gb-doc-link">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="gb-empty">
                            <i class="bi bi-file-earmark-x"></i> No bidding documents attached.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Submit CTA card -->
                <div class="vp-cta-card">
                    <div class="vp-cta-icon"><i class="bi bi-send-check-fill"></i></div>
                    <h4>Ready to Bid?</h4>
                    <p>Review all documents and lots, then submit your proposal before the deadline.</p>
                    <a href="submit_bid.php?id=<?= $procurement_id ?>" class="vp-btn-submit" style="width:100%; justify-content:center;">
                        <i class="bi bi-send-check"></i> Submit Bid Proposal
                    </a>
                    <a href="procurement.php" class="vp-back-link" style="display:flex; justify-content:center; margin-top:12px;">
                        <i class="bi bi-arrow-left"></i> Back to Opportunities
                    </a>
                </div>

            </div><!-- /.vp-sidebar -->

        </div><!-- /.vp-layout -->

    </div>
</main>

<!-- Session alert -->
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
