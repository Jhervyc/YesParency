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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Submissions | YesParency</title>
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
        <a href="bid_submissions.php" class="nav-item active"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
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
                <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
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
        <span class="topbar-title">Bid Submissions & Verification</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i><span class="badge-dot"></span></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <div class="page-header">
            <h2>Bid Submissions & Receipt Verification</h2>
            <p>Review submitted bids, verify payment receipts, and approve or reject bidder submissions.</p>
        </div>

        <?php if (empty($procurements)): ?>
            <div class="dash-panel">
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No open procurements found.</p>
                </div>
            </div>

        <?php else: ?>
            <?php foreach ($procurements as $proc): ?>

            <div class="bs-procurement-block">

                <!-- Procurement header (clickable to toggle) -->
                <div class="bs-proc-header bs-toggle-trigger" onclick="toggleBidList(this)">
                    <div class="bs-proc-info">
                        <div class="bs-proc-title">
                            <i class="bi bi-folder2-open"></i>
                            <?= htmlspecialchars($proc['procurement_title']) ?>
                        </div>
                        <div class="bs-proc-meta">
                            <span><i class="bi bi-hash"></i> <?= htmlspecialchars($proc['philgeps_ref_no'] ?? 'N/A') ?></span>
                            <span><i class="bi bi-inbox"></i> <?= $proc['total_bids'] ?> bid(s) received</span>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span class="proc-status-pill open">Open</span>
                        <span class="bs-chevron"><i class="bi bi-chevron-down"></i></span>
                    </div>
                </div>

                <!-- Bids list (collapsed by default) -->
                <div class="bs-collapsible">
                <?php if (empty($proc['bids'])): ?>
                    <div class="bs-empty">
                        <i class="bi bi-file-earmark-x"></i>
                        No submitted bids for this procurement yet.
                    </div>
                <?php else: ?>

                    <div class="bs-bid-list">
                    <?php foreach ($proc['bids'] as $bid):
                        // Status styling
                        $bidStatusClass = 'closed';
                        $bidStatusLabel = ucfirst($bid['bid_status']);
                        if ($bid['bid_status'] === 'pending')   { $bidStatusClass = 'upcoming'; $bidStatusLabel = 'Pending Receipt Check'; }
                        if ($bid['bid_status'] === 'submitted') { $bidStatusClass = 'open';     }
                        if ($bid['bid_status'] === 'rejected')  { $bidStatusClass = 'closed';   }
                    ?>
                        <div class="bs-bid-card">

                            <!-- Row 1: bidder + status -->
                            <div class="bs-bid-top">
                                <div class="bs-bidder-info">
                                    <div class="bs-bidder-avatar"><i class="bi bi-person-fill"></i></div>
                                    <div>
                                        <div class="bs-bidder-name"><?= htmlspecialchars($bid['bidder_name']) ?></div>
                                        <div class="bs-bidder-email"><?= htmlspecialchars($bid['bidder_email']) ?></div>
                                    </div>
                                </div>
                                <div class="bs-bid-right">
                                    <span class="proc-status-pill <?= $bidStatusClass ?>"><?= $bidStatusLabel ?></span>
                                    <span class="bs-date"><i class="bi bi-calendar3"></i> <?= date("M j, Y g:i A", strtotime($bid['submission_date'])) ?></span>
                                </div>
                            </div>

                            <!-- Row 2: lots + docs + action -->
                            <div class="bs-bid-body">

                                <!-- Applied lots -->
                                <div class="bs-section">
                                    <div class="bs-section-label"><i class="bi bi-layers"></i> Applied Lots</div>
                                    <?php if (!empty($bid['lots'])): ?>
                                        <div class="bs-lot-tags">
                                            <?php foreach ($bid['lots'] as $lot): ?>
                                                <span class="bs-lot-tag">
                                                    Lot #<?= htmlspecialchars($lot['lot_number']) ?> — <?= htmlspecialchars($lot['lot_title']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="bs-none">No lots recorded</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Receipt -->
                                <div class="bs-section">
                                    <div class="bs-section-label"><i class="bi bi-receipt"></i> Payment Receipt</div>
                                    <?php if (!empty($bid['receipt'])): ?>
                                        <a href="../bidder/<?= htmlspecialchars($bid['receipt']['file_path']) ?>" target="_blank" class="bs-doc-link">
                                            <i class="bi bi-file-earmark-pdf"></i> View Receipt
                                        </a>
                                    <?php else: ?>
                                        <span class="bs-none">No receipt uploaded</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Proposal docs (sealed) -->
                                <div class="bs-section">
                                    <div class="bs-section-label"><i class="bi bi-lock-fill"></i> Proposal Documents</div>
                                    <div class="bs-sealed-tags">
                                        <span class="bs-sealed"><i class="bi bi-shield-lock"></i> Eligibility — Sealed</span>
                                        <span class="bs-sealed"><i class="bi bi-shield-lock"></i> Financial — Sealed</span>
                                    </div>
                                </div>

                                <!-- Admin action -->
                                <?php if ($bid['bid_status'] === 'pending'): ?>
                                <div class="bs-section">
                                    <div class="bs-section-label"><i class="bi bi-check2-circle"></i> Admin Verification</div>
                                    <div class="bs-actions">
                                        <form method="POST" action="" style="display:inline;"
                                              onsubmit="return confirm('Verify receipt and approve this bid for submission?');">
                                            <input type="hidden" name="bid_id" value="<?= $bid['bid_id'] ?>">
                                            <input type="hidden" name="status_action" value="approve">
                                            <button type="submit" name="action_verify_bid" class="proc-action-btn manage">
                                                <i class="bi bi-check-circle-fill"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" action="" style="display:inline;"
                                              onsubmit="return confirm('Reject this bid due to invalid receipt?');">
                                            <input type="hidden" name="bid_id" value="<?= $bid['bid_id'] ?>">
                                            <input type="hidden" name="status_action" value="reject">
                                            <button type="submit" name="action_verify_bid" class="proc-action-btn delete">
                                                <i class="bi bi-x-circle-fill"></i> Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="bs-section">
                                    <div class="bs-section-label"><i class="bi bi-check2-circle"></i> Admin Verification</div>
                                    <span class="bs-completed">
                                        <i class="bi bi-check-circle"></i> Action completed — <?= ucfirst($bid['bid_status']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                            </div><!-- /.bs-bid-body -->
                        </div><!-- /.bs-bid-card -->
                    <?php endforeach; ?>
                    </div><!-- /.bs-bid-list -->

                <?php endif; ?>
                </div><!-- /.bs-collapsible -->
            </div><!-- /.bs-procurement-block -->

            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</main>

<!-- Session alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

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

    function toggleBidList(header) {
        const block    = header.closest('.bs-procurement-block');
        const content  = block.querySelector('.bs-collapsible');
        const chevron  = header.querySelector('.bs-chevron i');
        const expanded = content.classList.toggle('open');
        chevron.className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
    }

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
