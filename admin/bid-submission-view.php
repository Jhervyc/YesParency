<?php
include("utils/protect-page.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($procurement_id === 0) {
    header("Location: bid_submissions.php");
    exit();
}

// ── Handle bid verify/reject ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_bid'])) {
    $bid_id     = intval($_POST['bid_id']);
    $new_status = $_POST['status_action'] === 'approve' ? 'submitted' : 'rejected';

    $u = $conn->prepare("UPDATE bids SET status = ? WHERE id = ?");
    $u->bind_param("si", $new_status, $bid_id);
    if ($u->execute()) {
        $_SESSION['alert_success'] = "Bid " . strtoupper($new_status) . " successfully.";
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
    header("Location: bid-submission-view.php?error=not_found");
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
    $total_lots_abc += $l['abc'];
    $lots[] = $l;
}
$lot_stmt->close();

// ── Bid filter ────────────────────────────────────────────────────────────────
$bid_filter = isset($_GET['bid_filter']) && in_array($_GET['bid_filter'], ['all','pending','submitted','rejected'])
              ? $_GET['bid_filter'] : 'all';

// ── Fetch bids with lots and documents ───────────────────────────────────────
if ($bid_filter !== 'all') {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ? AND b.status = ?
        ORDER BY b.submission_date DESC
    ");
    $bid_stmt->bind_param("is", $procurement_id, $bid_filter);
} else {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname
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
        SELECT l.lot_number, l.lot_title
        FROM bid_lots bl JOIN lots l ON bl.lot_id = l.id
        WHERE bl.bid_id = ?
    ");
    $bl->bind_param("i", $bid_id);
    $bl->execute();
    $b['lots'] = $bl->get_result()->fetch_all(MYSQLI_ASSOC);
    $bl->close();

    // Documents
    $bd = $conn->prepare("SELECT * FROM bid_documents WHERE bid_id = ?");
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

// Status colors
$sc = strtolower($proc['status']);
$pillColors = [
    'draft'   => ['bg'=>'#EEF0ED','fg'=>'#8B958E'],
    'open'    => ['bg'=>'#E4F5EA','fg'=>'#219653'],
    'closed'  => ['bg'=>'#E7EEFE','fg'=>'#2F6FED'],
    'awarded' => ['bg'=>'#FCF1CF','fg'=>'#C99A1D'],
];
$pc = $pillColors[$sc] ?? ['bg'=>'#EEF0ED','fg'=>'#8B958E'];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($proc['title']) ?> | YesParency</title>
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

    <!-- Page head -->
    <div class="pv-page-head">
        <div class="pv-title-wrap">
            <div class="pv-title-row">
                <h2><?= htmlspecialchars($proc['title']) ?></h2>
                <span class="pv-ref-tag"># <?= htmlspecialchars($proc['philgeps_ref_no'] ?? 'N/A') ?></span>
            </div>
        </div>
        <a href="bid_submissions.php" class="pv-back-btn">
            <i class="bi bi-arrow-left"></i> Back to Bid Submissions
        </a>
    </div>

    <!-- Stat donuts -->
    <div class="sad-section-label">Summary</div>
    <div class="pv-stats">
        <?php
        $stats = [
            ['num' => $total_bids,    'lbl' => 'Total Bids Received', 'color' => '#06251b', 'icon' => 'bi-inbox'],
            ['num' => $pending_bids,  'lbl' => 'Pending Review',       'color' => '#97710a', 'icon' => 'bi-hourglass-split'],
            ['num' => $verified_bids, 'lbl' => 'Verified & Sealed',    'color' => '#1f7a3d', 'icon' => 'bi-check-circle'],
            ['num' => $rejected_bids, 'lbl' => 'Rejected',             'color' => '#c23b3b', 'icon' => 'bi-x-circle'],
        ];
        foreach ($stats as $s):
            $pct = $total_bids > 0 ? round($s['num'] / $total_bids * 100) : 0;
        ?>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(<?= $s['color'] ?> 0% <?= $pct ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner">
                    <i class="bi <?= $s['icon'] ?>" style="color:<?= $s['color'] ?>; font-size:14px;"></i>
                </div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $s['num'] ?></div>
                <div class="ap2-stat-lbl"><?= $s['lbl'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Info grid: General + Dates -->
    <div class="pv-info-grid">

        <!-- General Information -->
        <div class="pv-card">
            <div class="pv-card-head">
                <h3><i class="bi bi-file-earmark-text"></i> General Information</h3>
                <span class="sp-status-pill" style="background:<?= $pc['bg'] ?>; color:<?= $pc['fg'] ?>">
                    <?= strtoupper($sc) ?>
                </span>
            </div>
            <div class="pv-field-grid">
                <div class="pv-field">
                    <label>PhilGEPS Reference No.</label>
                    <div class="pv-val"><?= htmlspecialchars($proc['philgeps_ref_no'] ?? 'N/A') ?></div>
                </div>
                <div class="pv-field">
                    <label>Procurement Mode</label>
                    <div class="pv-val"><?= htmlspecialchars($proc['procurement_mode'] ?? 'N/A') ?></div>
                </div>
                <div class="pv-field pv-field-full">
                    <label>Project Title</label>
                    <div class="pv-val"><?= htmlspecialchars($proc['title']) ?></div>
                </div>
                <?php if (!empty($proc['description'])): ?>
                <div class="pv-field pv-field-full">
                    <label>Description</label>
                    <div class="pv-val" style="white-space:pre-line; color:#555;"><?= htmlspecialchars($proc['description']) ?></div>
                </div>
                <?php endif; ?>
                <div class="pv-field">
                    <label>Approved Budget (ABC)</label>
                    <div class="pv-val pv-val-money">₱<?= number_format($proc['abc'], 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Important Dates -->
        <div class="pv-card">
            <div class="pv-card-head">
                <h3><i class="bi bi-calendar3"></i> Important Dates</h3>
            </div>
            <div class="pv-field-grid pv-single-col">
                <div class="pv-field">
                    <label>Posting Date</label>
                    <div class="pv-val"><?= $proc['posting_date'] ? date('F j, Y', strtotime($proc['posting_date'])) : 'N/A' ?></div>
                </div>
                <div class="pv-field">
                    <label>Bid Opening Date</label>
                    <div class="pv-val"><?= $proc['opening_date'] ? date('F j, Y · g:i A', strtotime($proc['opening_date'])) : 'N/A' ?></div>
                </div>
                <div class="pv-field">
                    <label>Bid Submission Deadline</label>
                    <div class="pv-val"><?= $proc['closing_date'] ? date('F j, Y · g:i A', strtotime($proc['closing_date'])) : 'N/A' ?></div>
                </div>
            </div>
        </div>

    </div><!-- /.pv-info-grid -->

    <!-- Associated Lots -->
    <div class="pv-card" style="margin-bottom:16px;">
        <div class="pv-card-head">
            <h3><i class="bi bi-layers"></i> Associated Lots</h3>
            <span class="pv-count-chip"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if (empty($lots)): ?>
            <div class="sad-empty" style="padding:32px;">No lots configured.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="pv-lots-table">
                <thead>
                    <tr>
                        <th>Lot No.</th>
                        <th>Lot Title</th>
                        <th>Description</th>
                        <th>ABC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lots as $lot): ?>
                    <tr>
                        <td class="pv-lot-no">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                        <td class="pv-lot-title"><?= htmlspecialchars($lot['lot_title'] ?? '—') ?></td>
                        <td class="pv-lot-desc"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                        <td class="pv-lot-abc">₱<?= number_format($lot['abc'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="pv-lots-total">
                        <td colspan="3" class="pv-total-label">Total Lots ABC</td>
                        <td class="pv-total-value">₱<?= number_format($total_lots_abc, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bid Submissions -->
    <div class="pv-card">
        <div class="pv-card-head">
            <h3><i class="bi bi-people"></i> Bid Submissions (<?= $total_bids ?>)</h3>
        </div>

        <!-- Filter tabs -->
        <div style="padding:12px 20px 0; display:flex; gap:8px; flex-wrap:wrap; border-bottom:1px solid #eaeeec; margin-bottom:0;">
            <?php
            $filterTabs = [
                'all'       => ['label' => 'All',       'count' => $total_bids],
                'pending'   => ['label' => 'Pending',   'count' => $pending_bids],
                'submitted' => ['label' => 'Submitted', 'count' => $verified_bids],
                'rejected'  => ['label' => 'Rejected',  'count' => $rejected_bids],
            ];
            foreach ($filterTabs as $val => $tab):
                $isActive = $bid_filter === $val;
            ?>
                <a href="bid-submission-view.php?id=<?= $procurement_id ?>&bid_filter=<?= $val ?>"
                   class="pv-filter-tab <?= $isActive ? 'active' : '' ?>">
                    <?= $tab['label'] ?>
                    <span class="pv-filter-count"><?= $tab['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($bids)): ?>
            <div class="sad-empty" style="padding:32px;">No bids submitted for this procurement.</div>
        <?php else: ?>

        <?php foreach ($bids as $bid):
            $bs = $bid['bid_status'];
            $bPillBg = ['pending'=>'#FDF0CF','submitted'=>'#D9F2DF','rejected'=>'#FBE1E1'][$bs] ?? '#EEF0ED';
            $bPillFg = ['pending'=>'#97710a','submitted'=>'#1f7a3d','rejected'=>'#c23b3b'][$bs] ?? '#6b7a72';
            $initials = strtoupper(substr($bid['firstname'],0,1).substr($bid['lastname'],0,1));
            $receipt  = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'other'))[0] ?? null;
            $elig     = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'eligibility'))[0] ?? null;
            $fin      = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'financial'))[0] ?? null;
        ?>
        <div class="pv-bid-row">

            <!-- Row head (click to expand) -->
            <div class="pv-bid-head" onclick="this.closest('.pv-bid-row').classList.toggle('expanded')">
                <div class="pv-bid-avatar"><?= htmlspecialchars($initials) ?></div>
                <div class="pv-bid-who">
                    <div class="pv-bid-name"><?= htmlspecialchars($bid['firstname'].' '.$bid['lastname']) ?></div>
                    <div class="pv-bid-email"><?= htmlspecialchars($bid['email']) ?></div>
                </div>
                <div class="pv-bid-right">
                    <span class="sp-status-pill" style="background:<?= $bPillBg ?>; color:<?= $bPillFg ?>">
                        <?= strtoupper($bs) ?>
                    </span>
                    <div class="pv-bid-time">
                        <i class="bi bi-clock" style="font-size:10px;"></i>
                        <?= date('M j, Y · g:i A', strtotime($bid['submission_date'])) ?>
                    </div>
                </div>
                <i class="bi bi-chevron-down pv-chevron"></i>
            </div>

            <!-- Expandable detail -->
            <div class="pv-bid-detail">

                <!-- Applied lots -->
                <div class="pv-bid-col">
                    <label><i class="bi bi-layers"></i> Applied Lots</label>
                    <?php if (!empty($bid['lots'])): ?>
                        <?php foreach ($bid['lots'] as $bl): ?>
                            <div class="pv-lot-chip">
                                Lot #<?= htmlspecialchars($bl['lot_number']) ?> — <?= htmlspecialchars($bl['lot_title']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="font-size:12px; color:#aaa;">No lots recorded</span>
                    <?php endif; ?>
                </div>

                <!-- Receipt -->
                <div class="pv-bid-col">
                    <label><i class="bi bi-receipt"></i> Payment Receipt</label>
                    <?php if ($receipt): ?>
                        <a href="<?= htmlspecialchars($receipt['file_path']) ?>" target="_blank" class="pv-doc-btn">
                            <i class="bi bi-eye"></i> View Receipt
                        </a>
                    <?php else: ?>
                        <span style="font-size:12px; color:#aaa;">No receipt uploaded</span>
                    <?php endif; ?>
                </div>

                <!-- Proposal documents -->
                <div class="pv-bid-col">
                    <label><i class="bi bi-shield-lock"></i> Proposal Documents</label>
                    <div class="pv-doc-pill"><i class="bi bi-lock-fill"></i> Eligibility — Sealed</div>
                    <div class="pv-doc-pill" style="margin-top:5px;"><i class="bi bi-lock-fill"></i> Financial — Sealed</div>
                </div>

                <!-- Verification status + actions -->
                <div class="pv-bid-col">
                    <label><i class="bi bi-check2-circle"></i> Admin Verification</label>
                    <?php if ($bs === 'pending'): ?>
                        <form method="POST" action="" style="display:flex; flex-direction:column; gap:8px;">
                            <input type="hidden" name="bid_id" value="<?= $bid['bid_id'] ?>">
                            <button type="submit" name="action_verify_bid" value="1"
                                    onclick="document.querySelector('#sa_<?= $bid['bid_id'] ?>').value='approve'"
                                    class="pv-action-btn pv-approve">
                                <i class="bi bi-check-circle-fill"></i> Approve Bid
                            </button>
                            <button type="submit" name="action_verify_bid" value="1"
                                    onclick="document.querySelector('#sa_<?= $bid['bid_id'] ?>').value='reject'"
                                    class="pv-action-btn pv-reject">
                                <i class="bi bi-x-circle-fill"></i> Reject Bid
                            </button>
                            <input type="hidden" id="sa_<?= $bid['bid_id'] ?>" name="status_action" value="approve">
                        </form>
                    <?php else: ?>
                        <div class="pv-verify-ok <?= $bs === 'rejected' ? 'pv-verify-rejected' : '' ?>">
                            <i class="bi bi-<?= $bs === 'rejected' ? 'x-circle-fill' : 'check-circle-fill' ?>"></i>
                            Action completed — <?= ucfirst($bs) ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.pv-bid-detail -->
        </div><!-- /.pv-bid-row -->
        <?php endforeach; ?>

        <?php endif; ?>
    </div><!-- /.pv-card -->

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
