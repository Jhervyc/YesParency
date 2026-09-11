<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
if ($procurement_id === 0) {
    header("Location: bid_submissions.php");
    exit();
}

// ── Bid filter parameter ──────────────────────────────────────────────────────
$bid_filter = isset($_GET['bid_filter']) && in_array($_GET['bid_filter'], ['all','pending','submitted','rejected'])
              ? $_GET['bid_filter'] : 'all';

// ── Handle bid verify/reject ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_bid'])) {
    $bid_id     = intval($_POST['bid_id']);
    $new_status = $_POST['status_action'] === 'approve' ? 'submitted' : 'rejected';

    $snap = $conn->prepare("SELECT b.status, b.procurement_id, b.bidder_id, p.title AS proc_title, u.username, bp.business_name FROM bids b JOIN procurements p ON b.procurement_id = p.id JOIN users u ON b.bidder_id = u.user_id LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id WHERE b.id = ?");
    $snap->bind_param("i", $bid_id);
    $snap->execute();
    $old_bid = $snap->get_result()->fetch_assoc();
    $snap->close();

    $u = $conn->prepare("UPDATE bids SET status = ? WHERE id = ?");
    $u->bind_param("si", $new_status, $bid_id);
    if ($u->execute()) {
        $bidder_name = $old_bid['business_name'] ?? ($old_bid['username'] ?? "Bidder #{$old_bid['bidder_id']}");
        $verb = $new_status === 'submitted' ? 'Approved & verified' : 'Rejected';
        audit_log(
            $conn,
            'BID_STATUS_CHANGED',
            'bids',
            $bid_id,
            "{$verb} bid #{$bid_id} by {$bidder_name} for procurement #{$procurement_id} ({$old_bid['status']} -> {$new_status})",
            [
                'status' => $old_bid['status'] ?? 'pending',
                'procurement_id' => $procurement_id,
                'bidder_id' => (int)($old_bid['bidder_id'] ?? 0)
            ],
            [
                'status' => $new_status,
                'procurement_id' => $procurement_id,
                'bidder_id' => (int)($old_bid['bidder_id'] ?? 0)
            ]
        );
        $_SESSION['alert_success'] = "Bid " . ($new_status === 'submitted' ? 'approved and verified' : 'marked as rejected') . " successfully.";

        // Queue & send email notification to the specific bidder
        require_once __DIR__ . '/../utils/mailer.php';
        if ($new_status === 'submitted') {
            notify_bid_verified($conn, $bid_id);
        } else {
            notify_bid_rejected($conn, $bid_id);
        }
    } else {
        $_SESSION['alert_error'] = "Failed to update bid status.";
    }
    $u->close();
    header("Location: bid-submission-view.php?id=" . $procurement_id . ($bid_filter !== 'all' ? '&bid_filter='.$bid_filter : ''));
    exit();
}

// ── Fetch procurement ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$proc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proc) {
    header("Location: bid_submissions.php?error=not_found");
    exit();
}

// ── Fetch lots ────────────────────────────────────────────────────────────────
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_result = $lot_stmt->get_result();
$lots = [];
$total_lots_abc = 0;
while ($l = $lots_result->fetch_assoc()) {
    $total_lots_abc += (float)$l['abc'];
    $lots[] = $l;
}
$lot_stmt->close();

// ── Fetch bids with lots and documents ───────────────────────────────────────
if ($bid_filter !== 'all') {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname, u.profile_picture_url
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ? AND b.status = ?
        ORDER BY b.submission_date DESC
    ");
    $bid_stmt->bind_param("is", $procurement_id, $bid_filter);
} else {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname, u.profile_picture_url
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ?
        ORDER BY b.submission_date DESC
    ");
    $bid_stmt->bind_param("i", $procurement_id);
}
$bid_stmt->execute();
$bids_result = $bid_stmt->get_result();

$bids = [];
while ($b = $bids_result->fetch_assoc()) {
    $bid_id = $b['bid_id'];

    // Applied lots
    $bl = $conn->prepare("
        SELECT l.lot_number, l.lot_title, l.abc
        FROM bid_lots bl JOIN lots l ON bl.lot_id = l.id
        WHERE bl.bid_id = ?
        ORDER BY l.lot_number ASC
    ");
    $bl->bind_param("i", $bid_id);
    $bl->execute();
    $b['lots'] = $bl->get_result()->fetch_all(MYSQLI_ASSOC);
    $bl->close();

    // Documents
    $bd = $conn->prepare("SELECT * FROM bid_documents WHERE bid_id = ? ORDER BY uploaded_at ASC");
    $bd->bind_param("i", $bid_id);
    $bd->execute();
    $b['docs'] = $bd->get_result()->fetch_all(MYSQLI_ASSOC);
    $bd->close();

    $bids[] = $b;
}
$bid_stmt->close();

// ── Bid stats (always counts across ALL bids, regardless of filter) ───────────
$stats_stmt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM bids
    WHERE procurement_id = ?
    GROUP BY status
");
$stats_stmt->bind_param("i", $procurement_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$total_bids = 0; $pending_bids = 0; $verified_bids = 0; $rejected_bids = 0;
while ($row = $stats_result->fetch_assoc()) {
    $total_bids += $row['cnt'];
    if ($row['status'] === 'pending')   $pending_bids  = $row['cnt'];
    if ($row['status'] === 'submitted') $verified_bids = $row['cnt'];
    if ($row['status'] === 'rejected')  $rejected_bids = $row['cnt'];
}
$stats_stmt->close();

// Status & Timing Details
$p_status = strtolower($proc['status'] ?? 'open');
$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#e4f5ea';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#1f7a3d';

// ── Quotation data for SVP / Shopping procurements ────────────────────────────
$_bsv_is_quotation = is_quotation_mode($proc['procurement_mode'] ?? '');
$bsv_quotations    = [];   // lot_id → [ rows ]
$bsv_awards_map    = [];   // lot_id → award row
if ($_bsv_is_quotation) {
    $aw_bsv = $conn->prepare("SELECT * FROM awards WHERE lot_id IN (SELECT id FROM lots WHERE procurement_id = ?)");
    $aw_bsv->bind_param("i", $procurement_id);
    $aw_bsv->execute();
    foreach ($aw_bsv->get_result()->fetch_all(MYSQLI_ASSOC) as $_aw) $bsv_awards_map[$_aw['lot_id']] = $_aw;
    $aw_bsv->close();

    foreach ($lots as $_l) $bsv_quotations[$_l['id']] = [];

    $bsv_q = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               bl.id AS bid_lot_id, bl.lot_id, bl.total_offered_bid,
               u.firstname, u.lastname, u.email, u.profile_picture_url,
               bp.business_name,
               (SELECT bd.id FROM bid_documents bd WHERE bd.bid_id = b.id AND bd.document_type = 'quotation' LIMIT 1) AS doc_id
        FROM bids b
        JOIN bid_lots bl ON bl.bid_id = b.id
        JOIN users u ON b.bidder_id = u.user_id
        LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
        WHERE b.procurement_id = ? AND b.bid_type = 'quotation'
        ORDER BY bl.lot_id ASC, bl.total_offered_bid ASC, b.submission_date ASC
    ");
    $bsv_q->bind_param("i", $procurement_id);
    $bsv_q->execute();
    foreach ($bsv_q->get_result()->fetch_all(MYSQLI_ASSOC) as $_qr) {
        if (isset($bsv_quotations[$_qr['lot_id']])) $bsv_quotations[$_qr['lot_id']][] = $_qr;
    }
    $bsv_q->close();

    foreach ($bsv_quotations as $_lid => $_rows) {
        $_rank = 1;
        foreach ($_rows as $_i => $_row) {
            $bsv_quotations[$_lid][$_i]['computed_rank'] = ($_row['total_offered_bid'] !== null) ? $_rank++ : null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Submissions: <?= htmlspecialchars($proc['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-bid-submission-view.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Top Navigation Bar & Breadcrumbs ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="bid_submissions.php"><i class="bi bi-inbox"></i> Bid Submissions</a>
            <span>/</span>
            <span class="clr-dark">Review Submissions</span>
        </div>
        <a href="bid_submissions.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Bid Submissions
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <?php if (!empty($proc['slsu_ref_no'])): ?>
                    <span class="hero-pill ref" onclick="copyPhilgeps('<?= htmlspecialchars($proc['slsu_ref_no']) ?>')" title="Click to copy Reference No.">
                        <i class="bi bi-hash"></i> REF: <?= htmlspecialchars($proc['slsu_ref_no']) ?>
                        <i class="bi bi-copy icon-dim"></i>
                    </span>
                <?php endif; ?>

                <span class="hero-pill mode">
                    <i class="bi bi-sliders"></i> <?= htmlspecialchars($proc['procurement_mode'] ?: 'Public Bidding') ?>
                </span>

                <span class="hero-pill status hero-pill--bright">
                    <i class="bi bi-circle-fill status-dot-tiny"></i> <?= strtoupper($p_status) ?>
                </span>
            </div>

            <span class="hero-status-note">
                <i class="bi bi-building"></i> Southern Luzon State University
            </span>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($proc['title']) ?></h1>

        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-currency-exchange"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$proc['abc'], 2) ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-boxes"></i> Associated Lots</div>
                <div class="vp-hero-metric-val"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-inbox-fill"></i> Total Bids Received</div>
                <div class="vp-hero-metric-val"><?= $total_bids ?> Proposal<?= $total_bids !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val hero-metric-val--sm">
                    <?= !empty($proc['closing_date']) ? date('M j, Y · g:i A', strtotime($proc['closing_date'])) : 'Not Set' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4">
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%;">
                <div class="ap2-ring-inner"><i class="bi bi-inbox-fill clr-dark"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $total_bids ?></div>
                <div class="ap2-stat-lbl">Total Bids</div>
            </div>
        </div>
        <div class="ap2-stat <?= $pending_bids > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="--ring-color:<?= $pending_bids > 0 ? '#e67e22' : '#8B958E' ?>; --pct:<?= $total_bids > 0 ? round($pending_bids/$total_bids*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split <?= $pending_bids > 0 ? 'clr-amber' : 'clr-idle' ?>"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num <?= $pending_bids > 0 ? 'stat-num--warn' : '' ?>"><?= $pending_bids ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#219653; --pct:<?= $total_bids > 0 ? round($verified_bids/$total_bids*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-shield-check clr-green"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $verified_bids ?></div>
                <div class="ap2-stat-lbl">Verified &amp; Sealed</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#c23b3b; --pct:<?= $total_bids > 0 ? round($rejected_bids/$total_bids*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-x-circle clr-red"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $rejected_bids ?></div>
                <div class="ap2-stat-lbl">Rejected</div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Bids Desk & Project Details ── -->
        <div class="vp-left-col">

            <!-- 1. Bid Submissions Desk -->
            <?php if (!$_bsv_is_quotation): ?>
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-people clr-dark"></i>
                        <span>Bidder Proposal Submissions</span>
                    </div>
                    <span class="vp-card-count"><?= count($bids) ?> Showing</span>
                </div>

                <!-- Filter Tabs -->
                <div class="bids-filter-bar">
                    <?php
                    $filterTabs = [
                        'all'       => ['label' => 'All Bids',            'count' => $total_bids],
                        'pending'   => ['label' => 'Pending Verification', 'count' => $pending_bids],
                        'submitted' => ['label' => 'Verified & Sealed',    'count' => $verified_bids],
                        'rejected'  => ['label' => 'Rejected',            'count' => $rejected_bids],
                    ];
                    foreach ($filterTabs as $val => $tab):
                        $isActive = ($bid_filter === $val);
                    ?>
                        <a href="bid-submission-view.php?id=<?= $procurement_id ?>&bid_filter=<?= $val ?>"
                           class="bid-filter-tab <?= $isActive ? 'active' : '' ?>">
                            <span><?= $tab['label'] ?></span>
                            <span class="bid-tab-count"><?= $tab['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Submissions List -->
                <?php if (empty($bids)): ?>
                    <div class="results-empty">
                        <i class="bi bi-inbox results-empty-icon"></i>
                        <div class="results-empty-title">No Bids Found</div>
                        <div class="results-empty-sub">There are no proposals matching the current filter selection.</div>
                    </div>
                <?php else: ?>
                    <div class="bids-container">
                        <?php foreach ($bids as $bid):
                            $bs = $bid['bid_status'];
                            $initials = strtoupper(substr($bid['firstname'],0,1).substr($bid['lastname'],0,1));
                            $avatar_url = !empty($bid['profile_picture_url']) ? '../' . ltrim($bid['profile_picture_url'], '/') : '';
                            
                            $receipt  = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'other'))[0] ?? null;
                            $elig     = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'eligibility'))[0] ?? null;
                            $fin      = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'financial'))[0] ?? null;
                            
                            $json_data = htmlspecialchars(json_encode($bid), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="bid-item-card" id="bid-card-<?= $bid['bid_id'] ?>">
                                
                                <!-- Header Row (Click to open details modal) -->
                                <div class="bid-item-header" onclick='openBidDetailModal(<?= $json_data ?>)'>
                                    <div class="bidder-avatar-wrap">
                                        <?php if (!empty($avatar_url)): ?>
                                            <img src="<?= htmlspecialchars($avatar_url) ?>" alt="<?= htmlspecialchars($bid['firstname']) ?>">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initials) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="bidder-profile-info">
                                        <div class="bidder-name"><?= htmlspecialchars($bid['firstname'] . ' ' . $bid['lastname']) ?></div>
                                        <div class="bidder-email"><i class="bi bi-envelope"></i> <?= htmlspecialchars($bid['email']) ?></div>
                                    </div>

                                    <div class="bid-header-meta">
                                        <span class="bid-status-pill <?= $bs ?>">
                                            <i class="bi bi-circle-fill status-dot-xtiny"></i> <?= strtoupper($bs === 'submitted' ? 'VERIFIED' : $bs) ?>
                                        </span>
                                        <div class="bid-timestamp">
                                            <i class="bi bi-clock"></i> <?= date('M j, Y · g:i A', strtotime($bid['submission_date'])) ?>
                                        </div>
                                        <button type="button" class="btn-view-submission-modal" onclick='event.stopPropagation(); openBidDetailModal(<?= $json_data ?>)'>
                                            <i class="bi bi-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>

            <?php else: ?>
            <!-- ── Quotation Rankings (SVP / Shopping) ── -->
            <div class="vp-card qr-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-list-ol clr-gold-dark"></i>
                        <span>Quotation Rankings</span>
                    </div>
                    <a href="quotation_management.php?id=<?= $procurement_id ?>" class="qr-manage-link">
                        <i class="bi bi-arrow-up-right-square"></i> Full Management
                    </a>
                </div>
                <div>
                <?php if (empty($lots)): ?>
                    <div class="qr-empty">No lots defined yet.</div>
                <?php else:
                    foreach ($lots as $_bsvlot):
                        $_bsv_quotes  = $bsv_quotations[$_bsvlot['id']] ?? [];
                        $_bsv_award   = $bsv_awards_map[$_bsvlot['id']] ?? null;
                        $_bsv_lot_status = strtolower($_bsvlot['status'] ?? 'pending');
                ?>
                    <div class="qr-lot-block">
                        <div class="qr-lot-label-row">
                            <span class="qr-lot-num-badge">Lot <?= $_bsvlot['lot_number'] ?></span>
                            <span class="qr-lot-title"><?= htmlspecialchars($_bsvlot['lot_title']) ?></span>
                            <?php if ($_bsv_lot_status === 'awarded'): ?>
                                <span class="qr-lot-status-badge qr-lot-status-badge--awarded"><i class="bi bi-trophy-fill"></i> Awarded</span>
                            <?php elseif ($_bsv_lot_status === 'failed'): ?>
                                <span class="qr-lot-status-badge qr-lot-status-badge--failed"><i class="bi bi-x-circle-fill"></i> Failed</span>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($_bsv_quotes)): ?>
                            <div class="qr-no-quotes">No quotations submitted yet.</div>
                        <?php else: ?>
                            <table class="qr-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Bidder</th>
                                        <th>Offered Price</th>
                                        <th>Status</th>
                                        <th>Doc</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($_bsv_quotes as $_bq):
                                    $_br    = $_bq['computed_rank'];
                                    $_bbiz  = $_bq['business_name'] ?: ($_bq['firstname'].' '.$_bq['lastname']);
                                    $_bwinner = $_bsv_award && $_bsv_award['bid_lot_id'] == $_bq['bid_lot_id'];
                                    $_bbs   = $_bq['bid_status'];
                                    $_bbs_label = match($_bbs) { 'confirmed'=>'Confirmed','awarded'=>'Awarded','rejected'=>'Rejected','submitted'=>'Verified',default=>ucfirst($_bbs) };
                                    $_bbs_class = match($_bbs) { 'confirmed'=>'qr-status-badge--confirmed','awarded'=>'qr-status-badge--awarded','rejected'=>'qr-status-badge--rejected',default=>'qr-status-badge--default' };
                                    $_rank_class = $_br===1?'qr-rank--gold':($_br===2?'qr-rank--silver':($_br===3?'qr-rank--bronze':'qr-rank--default'));
                                ?>
                                <tr class="qr-row <?= $_bwinner ? 'qr-row--winner' : '' ?>">
                                    <td>
                                        <span class="qr-rank <?= $_rank_class ?>">
                                            <?= $_br ? '#'.$_br : '—' ?>
                                        </span>
                                    </td>
                                    <td class="qr-bidder-name">
                                        <?= htmlspecialchars($_bbiz) ?>
                                        <?php if ($_bwinner): ?><span class="qr-winner-badge">WINNER</span><?php endif; ?>
                                    </td>
                                    <td class="qr-price">
                                        <?= $_bq['total_offered_bid'] !== null ? '₱'.number_format((float)$_bq['total_offered_bid'],2) : '<span class="qr-price-pending">Pending</span>' ?>
                                    </td>
                                    <td>
                                        <span class="qr-status-badge <?= $_bbs_class ?>"><?= $_bbs_label ?></span>
                                    </td>
                                    <td>
                                        <?php if ($_bq['doc_id']): ?>
                                            <a href="quotation_management.php?id=<?= $procurement_id ?>&action=view_doc&doc_id=<?= $_bq['doc_id'] ?>"
                                               target="_blank"
                                               class="qr-doc-link">
                                                <i class="bi bi-file-earmark-text-fill"></i> View
                                            </a>
                                        <?php else: ?><span class="qr-doc-none">—</span><?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 2. Project Specifications & Scope Overview -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle clr-dark"></i>
                        <span>Procurement Specifications &amp; Overview</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val"><?= htmlspecialchars($proc['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($proc['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-box field-span-2">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val field-strong-lg"><?= htmlspecialchars($proc['title']) ?></div>
                        </div>
                        <div class="spec-field-box field-span-2">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val field-abc">₱<?= number_format((float)$proc['abc'], 2) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($proc['description'])): ?>
                        <div class="desc-label">Description / Technical Scope</div>
                        <div class="desc-text-box">
<?= htmlspecialchars($proc['description']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Associated Lots Table -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-boxes clr-forest"></i>
                        <span>Project Lots Breakdown</span>
                    </div>
                    <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="vp-card-body p-0">
                    <?php if (!empty($lots)): ?>
                        <div class="table-scroll-x">
                            <table class="lots-data-table">
                                <thead>
                                    <tr>
                                        <th class="col-lot-narrow">Lot No.</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th class="col-abc-narrow">ABC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td><span class="lot-badge">Lot <?= htmlspecialchars($lot['lot_number']) ?></span></td>
                                            <td class="field-strong-lg"><?= htmlspecialchars($lot['lot_title']) ?></td>
                                            <td class="lots-desc-cell"><?= htmlspecialchars($lot['description'] ?: '—') ?></td>
                                            <td class="lots-abc-cell">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" class="lots-total-label-cell">Total Lots Approved Budget (ABC)</td>
                                            <td class="lots-total-abc-cell">
                                                ₱<?= number_format($total_lots_abc, 2) ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="results-empty--sm">No lots configured for this procurement.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Activity & Timeline ── -->
        <div class="vp-right-col">

            <!-- Card 1: Key Dates & Milestones Timeline -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-event clr-forest"></i>
                        <span>Procurement Milestones</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="vp-timeline">
                        
                        <!-- 1. Posting Date -->
                        <?php
                            $has_posted = !empty($proc['posting_date']);
                            $post_passed = $has_posted && (strtotime($proc['posting_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $post_passed ? 'passed' : ($has_posted ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $post_passed ? 'check-lg' : 'megaphone' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">1. Posting Date</span>
                                    <span class="timeline-badge <?= $post_passed ? 'past' : 'future' ?>"><?= $post_passed ? 'Published' : 'Scheduled' ?></span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_posted ? date('F j, Y', strtotime($proc['posting_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Submission Deadline -->
                        <?php
                            $has_closing = !empty($proc['closing_date']);
                            $close_passed = $has_closing && (strtotime($proc['closing_date']) <= time());
                            $close_current = $has_closing && !$close_passed && ($p_status === 'open');
                        ?>
                        <div class="timeline-item <?= $close_passed ? 'passed' : ($close_current ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $close_passed ? 'check-lg' : 'clock-history' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">2. Submission Deadline</span>
                                    <span class="timeline-badge <?= $close_passed ? 'past' : ($close_current ? 'current' : 'future') ?>">
                                        <?= $close_passed ? 'Closed' : ($close_current ? 'Active' : 'Pending') ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_closing ? date('F j, Y · g:i A', strtotime($proc['closing_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Bid Opening Date -->
                        <?php
                            $has_opening = !empty($proc['opening_date']);
                            $open_passed = $has_opening && (strtotime($proc['opening_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $open_passed ? 'passed' : ($has_opening ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $open_passed ? 'check-lg' : 'envelope-open' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">3. Bid Opening &amp; Evaluation</span>
                                    <span class="timeline-badge <?= $open_passed ? 'past' : 'future' ?>">
                                        <?= $open_passed ? 'Completed' : 'Upcoming' ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_opening ? date('F j, Y · g:i A', strtotime($proc['opening_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
</main>

<!-- ============================================== -->
<!-- BID SUBMISSION DETAILS MODAL                   -->
<!-- ============================================== -->
<div id="bidDetailModal" class="modal-backdrop" onclick="if(event.target===this)closeBidDetailModal()">
    <div class="modal-dialog-large">
        
        <!-- Modal Head -->
        <div class="modal-head">
            <h3><i class="bi bi-file-earmark-person-fill"></i> Bid Proposal Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeBidDetailModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal Body Content -->
        <div class="modal-body-content">
            
            <!-- Bidder Summary Header Card -->
            <div class="modal-bidder-card">
                <div class="modal-bidder-summary-left">
                    <div id="modalAvatar" class="bidder-avatar-wrap modal-avatar--lg"></div>
                    <div>
                        <div id="modalBidderNameDisplay" class="modal-bidder-name"></div>
                        <div id="modalBidderEmailDisplay" class="modal-bidder-email"></div>
                    </div>
                </div>
                <div class="modal-bidder-summary-right">
                    <div id="modalStatusBadge"></div>
                    <div id="modalTimestamp" class="modal-timestamp-text"></div>
                </div>
            </div>

            <!-- Two-Column Sections Grid -->
            <div class="modal-bid-grid">
                
                <!-- Applied Lots -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-boxes"></i> Applied Lots
                    </div>
                    <div id="modalLotsList" class="lot-chip-list"></div>
                </div>

                <!-- Payment Proof / Receipt -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-receipt"></i> Official Payment Receipt
                    </div>
                    <div id="modalReceiptBox" class="modal-receipt-box"></div>
                </div>

                <!-- Encrypted Envelopes (Two-Envelope Security) -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-shield-lock"></i> Encrypted Bidding Envelopes
                    </div>
                    <div class="doc-sealed-pill">
                        <span><i class="bi bi-file-earmark-lock2"></i> Technical &amp; Eligibility</span>
                        <span class="doc-sealed-badge"><i class="bi bi-lock-fill"></i> Sealed</span>
                    </div>
                    <div class="doc-sealed-pill">
                        <span><i class="bi bi-cash-stack"></i> Financial Proposal</span>
                        <span class="doc-sealed-badge"><i class="bi bi-lock-fill"></i> Sealed</span>
                    </div>
                    <div class="modal-envelope-note">
                        <i class="bi bi-info-circle"></i> Unlocks automatically upon the declared Bid Opening schedule.
                    </div>
                </div>

                <!-- Verification & Clearance Action -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-check2-circle"></i> BAC Clearance &amp; Verification
                    </div>
                    <div id="modalVerificationActionBox" class="modal-verify-box"></div>
                </div>

            </div>

        </div>

        <!-- Modal Footer -->
        <div class="modal-foot">
            <button type="button" onclick="closeBidDetailModal()" class="vp-back-link modal-back-btn">
                Close
            </button>
        </div>

    </div>
</div>

<!-- ============================================== -->
<!-- VERIFY / REJECT ACTION CONFIRMATION MODAL      -->
<!-- ============================================== -->
<div id="verifyModal" class="modal-backdrop" onclick="if(event.target===this)closeVerifyModal()">
    <div class="modal-dialog-box">
        <div id="modalIconWrap" class="verify-icon-circle verify-icon-circle--approve">
            <i class="bi bi-check-circle-fill" id="modalIcon"></i>
        </div>

        <h3 id="modalTitle" class="verify-modal-title">Approve Bid Proposal</h3>
        <p id="modalDesc" class="verify-modal-desc">
            Are you sure you want to verify and approve the bid submission from this bidder?
        </p>

        <div id="modalBidderName" class="verify-modal-bidder-name"></div>

        <form method="POST" action="" id="verifyForm">
            <input type="hidden" name="action_verify_bid" value="1">
            <input type="hidden" name="bid_id" id="modalBidId" value="">
            <input type="hidden" name="status_action" id="modalStatusAction" value="approve">

            <div class="modal-actions-row">
                <button type="button" onclick="closeVerifyModal()" class="vp-back-link modal-back-btn--padded">
                    Cancel
                </button>
                <button type="submit" id="modalSubmitBtn" class="btn-action-verify approve btn-action-verify--wide">
                    <i class="bi bi-check-circle-fill"></i> Confirm Approval
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast alert notifications -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill toast-icon--success"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill toast-icon--error"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
    // Toast Alert auto-hide
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    // Open Bid Detail Modal with Dynamic Data
    function openBidDetailModal(bid) {
        const fullName = (bid.firstname || '') + ' ' + (bid.lastname || '');
        const initials = ((bid.firstname ? bid.firstname.charAt(0) : 'B') + (bid.lastname ? bid.lastname.charAt(0) : 'P')).toUpperCase();
        
        // Avatar
        const avatarEl = document.getElementById('modalAvatar');
        if (bid.profile_picture_url) {
            const pic = '../' + bid.profile_picture_url.replace(/^\/+/, '');
            avatarEl.innerHTML = '<img src="' + escapeHtml(pic) + '" alt="' + escapeHtml(fullName) + '">';
        } else {
            avatarEl.textContent = initials;
        }

        // Names & Emails
        document.getElementById('modalBidderNameDisplay').textContent = fullName;
        document.getElementById('modalBidderEmailDisplay').innerHTML = '<i class="bi bi-envelope"></i> ' + escapeHtml(bid.email);

        // Status Badge
        const statusEl = document.getElementById('modalStatusBadge');
        let statusClass = bid.bid_status || 'pending';
        let statusLabel = statusClass === 'submitted' ? 'VERIFIED' : statusClass.toUpperCase();
        statusEl.innerHTML = '<span class="bid-status-pill ' + escapeHtml(statusClass) + '"><i class="bi bi-circle-fill status-dot-xtiny"></i> ' + escapeHtml(statusLabel) + '</span>';

        // Timestamp
        const dateObj = new Date(bid.submission_date);
        document.getElementById('modalTimestamp').innerHTML = '<i class="bi bi-clock"></i> ' + escapeHtml(bid.submission_date);

        // Lots List
        const lotsContainer = document.getElementById('modalLotsList');
        lotsContainer.innerHTML = '';
        if (bid.lots && bid.lots.length > 0) {
            bid.lots.forEach(lot => {
                const item = document.createElement('div');
                item.className = 'lot-chip-item';
                item.innerHTML = '<span><strong>Lot #' + escapeHtml(lot.lot_number) + '</strong> — ' + escapeHtml(lot.lot_title) + '</span>'
                               + '<span class="lot-chip-amount">₱' + Number(lot.abc).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>';
                lotsContainer.appendChild(item);
            });
        } else {
            lotsContainer.innerHTML = '<span class="muted-note-sm">No specific lots recorded</span>';
        }

        // Receipt
        const receiptBox = document.getElementById('modalReceiptBox');
        receiptBox.innerHTML = '';
        const receipt = bid.docs ? bid.docs.find(d => d.document_type === 'other') : null;
        if (receipt) {
            const rawName = receipt.document_name || 'Official_Receipt.pdf';
            const shortName = truncateFileName(rawName, 26);
            receiptBox.innerHTML = '<div class="receipt-name-text" title="' + escapeHtml(rawName) + '">'
                                 + '<i class="bi bi-file-earmark-check"></i> ' + escapeHtml(shortName) + '</div>'
                                 + '<a href="' + escapeHtml(receipt.file_path) + '" target="_blank" class="btn-receipt-view">'
                                 + '<i class="bi bi-eye"></i> View Official Receipt</a>';
        } else {
            receiptBox.innerHTML = '<span class="muted-note-sm">No receipt uploaded</span>';
        }

        // Verification Actions
        const verifyBox = document.getElementById('modalVerificationActionBox');
        verifyBox.innerHTML = '';
        if (bid.bid_status === 'pending') {
            const approveBtn = document.createElement('button');
            approveBtn.type = 'button';
            approveBtn.className = 'btn-action-verify approve';
            approveBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Approve & Verify Bid';
            approveBtn.onclick = function() {
                confirmBidAction(bid.bid_id, 'approve', fullName);
            };

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'btn-action-verify reject';
            rejectBtn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Reject Submission';
            rejectBtn.onclick = function() {
                confirmBidAction(bid.bid_id, 'reject', fullName);
            };

            verifyBox.appendChild(approveBtn);
            verifyBox.appendChild(rejectBtn);
        } else if (bid.bid_status === 'submitted') {
            verifyBox.innerHTML = '<div class="verify-done-tag submitted"><i class="bi bi-shield-check-fill verify-tag-icon"></i>'
                                + '<div><div>Verified &amp; Sealed</div><small class="verify-tag-sub">Cleared for bid opening</small></div></div>';
        } else {
            verifyBox.innerHTML = '<div class="verify-done-tag rejected"><i class="bi bi-x-circle-fill verify-tag-icon"></i>'
                                + '<div><div>Submission Rejected</div><small class="verify-tag-sub">Disqualified by BAC</small></div></div>';
        }

        document.getElementById('bidDetailModal').classList.add('open');
    }

    function closeBidDetailModal() {
        document.getElementById('bidDetailModal').classList.remove('open');
    }

    // Modal Confirmation for Bid Verification
    function confirmBidAction(bidId, action, bidderName) {
        document.getElementById('modalBidId').value = bidId;
        document.getElementById('modalStatusAction').value = action;
        document.getElementById('modalBidderName').textContent = bidderName;

        const iconWrap = document.getElementById('modalIconWrap');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const desc = document.getElementById('modalDesc');
        const btn = document.getElementById('modalSubmitBtn');

        if (action === 'approve') {
            iconWrap.className = 'verify-icon-circle verify-icon-circle--approve';
            icon.className = 'bi bi-check-circle-fill';
            title.textContent = 'Approve Bid Proposal';
            desc.textContent = 'Are you sure you want to verify and approve this bid proposal? It will be cleared for the opening schedule.';
            btn.className = 'btn-action-verify approve btn-action-verify--wide';
            btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Confirm Approval';
        } else {
            iconWrap.className = 'verify-icon-circle verify-icon-circle--reject';
            icon.className = 'bi bi-x-circle-fill';
            title.textContent = 'Reject Bid Proposal';
            desc.textContent = 'Are you sure you want to reject this bid proposal? The bidder will be notified of disqualification.';
            btn.className = 'btn-action-verify reject btn-action-verify--wide';
            btn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Confirm Rejection';
        }

        document.getElementById('verifyModal').classList.add('open');
    }

    function closeVerifyModal() {
        document.getElementById('verifyModal').classList.remove('open');
    }

    // Utility escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Truncate file name with ellipsis
    function truncateFileName(fileName, maxLength = 26) {
        if (!fileName) return '';
        if (fileName.length <= maxLength) return fileName;
        const lastDotIndex = fileName.lastIndexOf('.');
        if (lastDotIndex > 0 && (fileName.length - lastDotIndex) <= 6) {
            const ext = fileName.substring(lastDotIndex);
            const base = fileName.substring(0, lastDotIndex);
            const keepChars = maxLength - ext.length - 3;
            if (keepChars >= 3) {
                return base.substring(0, keepChars) + '...' + ext;
            }
        }
        return fileName.substring(0, maxLength - 3) + '...';
    }

    // Copy Reference Number
    function copyPhilgeps(text) {
        navigator.clipboard.writeText(text).then(() => {
            const toast = document.createElement('div');
            toast.className = 'toast-alert success';
            toast.innerHTML = '<i class="bi bi-check-circle-fill toast-icon--success"></i> SLSU Reference No. copied to clipboard!';
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('hide');
                setTimeout(() => toast.remove(), 400);
            }, 2500);
        });
    }
</script>

</body>
</html>
