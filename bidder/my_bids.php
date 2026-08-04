<?php
include("utils/protect-page.php");

$bidder_id = intval($_SESSION['user_id']);

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

    $row['lots']      = $bid_lots;
    $row['documents'] = $bid_docs;
    $my_bids[]        = $row;
}
$stmt->close();

// Status config
$status_config = [
    'pending'   => ['label' => 'Pending Verification', 'class' => 'upcoming', 'icon' => 'bi-hourglass-split'],
    'submitted' => ['label' => 'Verified / Submitted',  'class' => 'open',     'icon' => 'bi-check-circle-fill'],
    'opened'    => ['label' => 'Opened',                 'class' => 'open',     'icon' => 'bi-broadcast'],
    'awarded'   => ['label' => 'Awarded',                'class' => 'open',     'icon' => 'bi-trophy-fill'],
    'rejected'  => ['label' => 'Rejected',               'class' => 'closed',   'icon' => 'bi-x-circle-fill'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bids | YesParency</title>
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
        <a href="procurement.php" class="nav-item"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="my_bids.php" class="nav-item active"><i class="bi bi-inbox"></i><span>My Bids</span></a>
        <div class="nav-section-label">More</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-calendar-event"></i><span>Bid Schedule</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Bidder') ?></div>
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
        <span class="topbar-title">My Submitted Proposals</span>
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

        <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>My Submitted Proposals</h2>
                <p>Track the status of all your bid submissions.</p>
            </div>
            <a href="procurement.php" class="bp-btn-view" style="width:auto;">
                <i class="bi bi-folder2-open"></i> Browse Procurements
            </a>
        </div>

        <?php if (empty($my_bids)): ?>

            <div class="dash-panel">
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>You haven't submitted any bids yet.</p>
                    <a href="procurement.php" style="color:#06251b; font-weight:600; font-size:13px;">
                        Browse open procurements →
                    </a>
                </div>
            </div>

        <?php else: ?>

            <div class="mb-list">
            <?php foreach ($my_bids as $bid):
                $s      = $bid['bid_status'];
                $cfg    = $status_config[$s] ?? ['label' => ucfirst($s), 'class' => 'closed', 'icon' => 'bi-circle'];
            ?>

                <div class="mb-card">

                    <!-- Header -->
                    <div class="mb-card-header">
                        <div class="mb-card-title-wrap">
                            <a href="view_procurement.php?id=<?= $bid['procurement_id'] ?>" class="mb-title">
                                <?= htmlspecialchars($bid['procurement_title']) ?>
                            </a>
                            <span class="mb-ref"><i class="bi bi-hash"></i> <?= htmlspecialchars($bid['philgeps_ref_no'] ?? 'N/A') ?></span>
                        </div>
                        <div class="mb-header-right">
                            <span class="proc-status-pill <?= $cfg['class'] ?>">
                                <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                            </span>
                            <span class="mb-date">
                                <i class="bi bi-calendar3"></i>
                                <?= date("M j, Y · g:i a", strtotime($bid['submission_date'])) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Body: lots + docs side by side -->
                    <div class="mb-card-body">

                        <!-- Applied lots -->
                        <div class="mb-section">
                            <div class="mb-section-label"><i class="bi bi-layers"></i> Applied Lots</div>
                            <?php if (!empty($bid['lots'])): ?>
                                <div class="mb-lot-tags">
                                    <?php foreach ($bid['lots'] as $lot): ?>
                                        <div class="mb-lot-tag">
                                            <span class="mb-lot-num">Lot <?= htmlspecialchars($lot['lot_number']) ?></span>
                                            <span class="mb-lot-name"><?= htmlspecialchars($lot['lot_title']) ?></span>
                                            <span class="mb-lot-abc">₱<?= number_format($lot['abc'], 2) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="mb-none">No lots recorded</span>
                            <?php endif; ?>
                        </div>

                        <!-- Submitted files -->
                        <div class="mb-section">
                            <div class="mb-section-label"><i class="bi bi-paperclip"></i> Submitted Files</div>
                            <?php if (!empty($bid['documents'])): ?>
                                <div class="mb-doc-list">
                                    <?php foreach ($bid['documents'] as $doc):
                                        $isReceipt = $doc['document_type'] === 'other';
                                        $icon      = $isReceipt ? 'bi-receipt' : ($doc['document_type'] === 'eligibility' ? 'bi-file-earmark-check' : 'bi-file-earmark-bar-graph');
                                        $docColor  = $isReceipt ? '#f9a825' : ($doc['document_type'] === 'eligibility' ? '#1565c0' : '#43a047');
                                    ?>
                                        <div class="mb-doc-item">
                                            <i class="bi <?= $icon ?>" style="color:<?= $docColor ?>; font-size:15px; flex-shrink:0;"></i>
                                            <span><?= htmlspecialchars($doc['document_name']) ?></span>
                                            <?php if ($isReceipt && !empty($doc['file_path'])): ?>
                                                <a href="../<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="mb-doc-view">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="mb-sealed"><i class="bi bi-shield-lock"></i> Sealed</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="mb-none">No documents recorded</span>
                            <?php endif; ?>
                        </div>

                    </div><!-- /.mb-card-body -->

                    <!-- Footer link -->
                    <div class="mb-card-footer">
                        <a href="view_procurement.php?id=<?= $bid['procurement_id'] ?>" class="vp-back-link">
                            <i class="bi bi-arrow-right"></i> View Procurement Details
                        </a>
                    </div>

                </div><!-- /.mb-card -->

            <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
</main>

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
</script>

</body>
</html>
