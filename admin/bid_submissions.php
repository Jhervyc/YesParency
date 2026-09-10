<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");

// Handle Verify / Reject
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
            "{$verb} bid #{$bid_id} by {$bidder_name} ({$old_bid['status']} -> {$new_status})",
            [
                'status' => $old_bid['status'] ?? 'pending',
                'procurement_id' => (int)($old_bid['procurement_id'] ?? 0),
                'bidder_id' => (int)($old_bid['bidder_id'] ?? 0)
            ],
            [
                'status' => $new_status,
                'procurement_id' => (int)($old_bid['procurement_id'] ?? 0),
                'bidder_id' => (int)($old_bid['bidder_id'] ?? 0)
            ]
        );
        $_SESSION['alert_success'] = "Bid " . strtoupper($new_status) . " successfully.";

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
    header("Location: " . $_SERVER['PHP_SELF'] . ($search ?? '' ? '?search='.urlencode($_GET['search'] ?? '') : ''));
    exit();
}

// Search & Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['all', 'pending', 'has_bids']) 
          ? $_GET['filter'] : 'all';
$mode_filter = isset($_GET['mode_filter']) && in_array($_GET['mode_filter'], ['all', 'public_bidding', 'svp', 'shopping'])
               ? $_GET['mode_filter'] : 'all';

// Live stat counts
$stat_open   = (int)$conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'")->fetch_row()[0];
$stat_bids   = (int)$conn->query("SELECT COUNT(*) FROM bids b JOIN procurements p ON b.procurement_id = p.id WHERE p.status = 'open'")->fetch_row()[0];
$stat_pend   = (int)$conn->query("SELECT COUNT(*) FROM bids b JOIN procurements p ON b.procurement_id = p.id WHERE p.status = 'open' AND b.status = 'pending'")->fetch_row()[0];
$stat_proc_pending = (int)$conn->query("
    SELECT COUNT(DISTINCT p.id) 
    FROM procurements p 
    JOIN bids b ON p.id = b.procurement_id 
    WHERE p.status = 'open' AND b.status = 'pending'
")->fetch_row()[0];
$stat_proc_with_bids = (int)$conn->query("
    SELECT COUNT(DISTINCT p.id) 
    FROM procurements p 
    JOIN bids b ON p.id = b.procurement_id 
    WHERE p.status = 'open'
")->fetch_row()[0];

// Fetch procurements with optional filter
$having_clause = "";
if ($filter === 'pending') {
    $having_clause = "HAVING pending_bids > 0";
} elseif ($filter === 'has_bids') {
    $having_clause = "HAVING total_bids > 0";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $ps = $conn->prepare("
        SELECT p.id AS procurement_id, p.title AS procurement_title, p.slsu_ref_no,
               p.procurement_mode,
               COUNT(b.id) AS total_bids,
               SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending_bids
        FROM procurements p
        LEFT JOIN bids b ON p.id = b.procurement_id
        WHERE p.status = 'open' AND (p.title LIKE ? OR p.slsu_ref_no LIKE ?)
        GROUP BY p.id
        $having_clause
        ORDER BY pending_bids DESC, p.id DESC
    ");
    $ps->bind_param("ss", $like, $like);
} else {
    $ps = $conn->prepare("
        SELECT p.id AS procurement_id, p.title AS procurement_title, p.slsu_ref_no,
               p.procurement_mode,
               COUNT(b.id) AS total_bids,
               SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending_bids
        FROM procurements p
        LEFT JOIN bids b ON p.id = b.procurement_id
        WHERE p.status = 'open'
        GROUP BY p.id
        $having_clause
        ORDER BY pending_bids DESC, p.id DESC
    ");
}
$ps->execute();
$proc_result = $ps->get_result();

$procurements = [];
while ($p_row = $proc_result->fetch_assoc()) {
    $procurements[] = $p_row;
}
$ps->close();

// Post-filter by procurement mode category if selected
if ($mode_filter !== 'all') {
    $procurements = array_values(array_filter($procurements, function($p) use ($mode_filter) {
        return get_procurement_category($p['procurement_mode'] ?? '') === $mode_filter;
    }));
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Submissions | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Bid Submissions</h2>
        <p>Live overview of all open procurement bid submissions.</p>
    </div>

    <!-- ── Stat cards ── -->
    <div class="sad-section-label">Overview</div>
    <div class="ap2-stats" style="grid-template-columns:repeat(3,1fr); margin-bottom:20px;">
        <a href="bid_submissions.php" class="ap2-stat" style="text-decoration:none; color:inherit; cursor:pointer;">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-folder2-open" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_open ?></div>
                <div class="ap2-stat-lbl">Open Procurements</div>
            </div>
        </a>
        <a href="bid_submissions.php?filter=has_bids" class="ap2-stat" style="text-decoration:none; color:inherit; cursor:pointer;">
            <div class="ap2-ring" style="background:conic-gradient(#2F6FED 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-inbox" style="color:#2F6FED;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_bids ?></div>
                <div class="ap2-stat-lbl">Total Bids Received</div>
            </div>
        </a>
        <a href="bid_submissions.php?filter=pending" class="ap2-stat <?= $stat_pend > 0 ? 'bsv-stat-warn' : '' ?>" style="text-decoration:none; color:inherit; cursor:pointer;">
            <div class="ap2-ring" style="background:conic-gradient(<?= $stat_pend > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= $stat_bids > 0 ? round($stat_pend/$stat_bids*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:<?= $stat_pend > 0 ? '#e67e22' : '#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $stat_pend > 0 ? '#e67e22' : 'inherit' ?>"><?= $stat_pend ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </a>
    </div>

    <!-- ── List panel ── -->
    <div class="proc-table-panel" style="margin-bottom:24px;">
        <div class="filter-bar">
            <form method="GET" action="" style="display:contents;">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by procurement title or SLSU ref..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <select name="mode_filter"
                            style="border:1.5px solid #eaeeec; background:#f4f7f5; color:#06251b;
                                   font-family:'Poppins',sans-serif; font-size:12px; font-weight:600;
                                   padding:7px 10px; border-radius:9px; outline:none; cursor:pointer;
                                   transition:border-color .15s;"
                            onchange="this.form.submit()">
                        <option value="all"            <?= $mode_filter==='all'?'selected':'' ?>>All Modes</option>
                        <option value="public_bidding" <?= $mode_filter==='public_bidding'?'selected':'' ?>>Public Bidding</option>
                        <option value="svp"            <?= $mode_filter==='svp'?'selected':'' ?>>Small Value Procurement</option>
                        <option value="shopping"       <?= $mode_filter==='shopping'?'selected':'' ?>>Shopping</option>
                    </select>
                    <button type="submit" name="filter" value="all" class="ap2-filter-btn <?= $filter==='all'?'active':'' ?>">All Open</button>
                    <button type="submit" name="filter" value="pending" class="ap2-filter-btn <?= $filter==='pending'?'active':'' ?>">
                        <i class="bi bi-hourglass-split"></i> Pending Bids
                        <?php if ($stat_proc_pending > 0): ?>
                            <span style="background:<?= $filter==='pending'?'#ffc107':'#e67e22' ?>; color:<?= $filter==='pending'?'#06251b':'#fff' ?>; font-size:10px; font-weight:800; padding:1px 6px; border-radius:10px; margin-left:4px;"><?= $stat_proc_pending ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="submit" name="filter" value="has_bids" class="ap2-filter-btn <?= $filter==='has_bids'?'active':'' ?>">
                        <i class="bi bi-inbox"></i> With Bids
                    </button>
                </div>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if (empty($procurements)): ?>
        <div style="padding:52px 20px; text-align:center; color:#88968d;">
            <i class="bi bi-inbox" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            <div style="font-size:13px; font-weight:700;">
                <?php if ($filter==='pending'): ?>No procurements with pending bids<?= $search?' for "'.htmlspecialchars($search).'"':'' ?>.
                <?php elseif ($filter==='has_bids'): ?>No procurements with submitted bids found<?= $search?' for "'.htmlspecialchars($search).'"':'' ?>.
                <?php else: ?>No open procurements found<?= $search?' for "'.htmlspecialchars($search).'"':'' ?>.
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th style="width:130px;">SLSU Ref</th>
                        <th>Procurement Title</th>
                        <th class="col-abc">Total Bids</th>
                        <th class="col-status">Status</th>
                        <th style="text-align:right; min-width:100px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($procurements as $proc):
                    $hasPending  = intval($proc['pending_bids']) > 0;
                    $totalBids   = intval($proc['total_bids']);
                    $pendingBids = intval($proc['pending_bids']);
                ?>
                <tr>
                    <td class="proc-ref-cell">
                        <i class="bi bi-hash" style="color:#88968d; font-size:10px;"></i>
                        <?= htmlspecialchars($proc['slsu_ref_no'] ?? '—') ?>
                    </td>
                    <td class="proc-title-cell">
                        <?= htmlspecialchars(mb_strimwidth($proc['procurement_title'], 0, 65, '…')) ?>
                        <?php if (is_quotation_mode($proc['procurement_mode'] ?? '')): ?>
                        <span style="display:inline-flex; align-items:center; gap:3px; background:#fff8e1; border:1px solid #ffe082; color:#b78103; font-size:10px; font-weight:800; padding:1px 7px; border-radius:20px; margin-left:5px;">
                            <i class="bi bi-file-earmark-text-fill"></i> Quotation
                        </span>
                        <?php endif; ?>
                        <?php if ($hasPending): ?>
                        <div style="font-size:10.5px; color:#e67e22; font-weight:700; margin-top:2px;">
                            <i class="bi bi-hourglass-split"></i> <?= $pendingBids ?> pending review
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="proc-abc-cell col-abc"><?= $totalBids ?></td>
                    <td class="col-status">
                        <span class="proc-status-pill status-open">
                            <i class="bi bi-circle-fill" style="font-size:7px;"></i> Open
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <?php
                            $view_url = is_quotation_mode($proc['procurement_mode'] ?? '')
                                ? "bid-submission-view.php?id={$proc['procurement_id']}"
                                : "bid-submission-view.php?id={$proc['procurement_id']}";
                        ?>
                        <a href="<?= htmlspecialchars($view_url) ?>" class="proc-action-btn btn-view">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-foot">
            <div><?= count($procurements) ?> open procurement<?= count($procurements)!=1?'s':'' ?> shown<?= $filter==='pending'?' (filtered: pending bids)':'' ?></div>
        </div>
        <?php endif; ?>
    </div><!-- /.proc-table-panel -->

</div>
</main>

<!-- Toasts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
