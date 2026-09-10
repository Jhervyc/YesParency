<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($procurement_id === 0) {
    header("Location: bid_submissions.php");
    exit();
}

// ── Stream a quotation document securely ─────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'view_doc') {
    $doc_id = intval($_GET['doc_id'] ?? 0);
    if ($doc_id > 0) {
        $ds = $conn->prepare("
            SELECT bd.file_path, bd.document_name
            FROM bid_documents bd
            JOIN bids b ON bd.bid_id = b.id
            WHERE bd.id = ? AND b.procurement_id = ? AND bd.document_type = 'quotation'
            LIMIT 1
        ");
        $ds->bind_param("ii", $doc_id, $procurement_id);
        $ds->execute();
        $doc_row = $ds->get_result()->fetch_assoc();
        $ds->close();

        if ($doc_row && file_exists($doc_row['file_path'])) {
            $ext  = strtolower(pathinfo($doc_row['file_path'], PATHINFO_EXTENSION));
            $mime = ['pdf'=>'application/pdf','doc'=>'application/msword',
                     'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'xls'=>'application/vnd.ms-excel',
                     'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                     'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'][$ext] ?? 'application/octet-stream';
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc_row['document_name'] ?? 'quotation');
            header("Content-Type: {$mime}");
            header('Content-Disposition: inline; filename="' . $safe_name . '"');
            header('Content-Length: ' . filesize($doc_row['file_path']));
            header('Cache-Control: no-store, no-cache');
            readfile($doc_row['file_path']);
            exit();
        }
    }
    http_response_code(404);
    echo "Document not found.";
    exit();
}

// ── Confirm price for a bid_lot ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_price') {
    $bid_lot_id    = intval($_POST['bid_lot_id'] ?? 0);
    $offered_price = trim($_POST['offered_price'] ?? '');

    if ($bid_lot_id <= 0 || $offered_price === '' || !is_numeric($offered_price) || floatval($offered_price) < 0) {
        $_SESSION['alert_error'] = "Invalid price value.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    $price = floatval($offered_price);

    $verify = $conn->prepare("
        SELECT bl.id, bl.lot_id, bl.total_offered_bid, b.id AS bid_id, b.procurement_id
        FROM bid_lots bl
        JOIN bids b ON bl.bid_id = b.id
        WHERE bl.id = ? AND b.procurement_id = ? AND b.bid_type = 'quotation'
        LIMIT 1
    ");
    $verify->bind_param("ii", $bid_lot_id, $procurement_id);
    $verify->execute();
    $bl_row = $verify->get_result()->fetch_assoc();
    $verify->close();

    if (!$bl_row) {
        $_SESSION['alert_error'] = "Bid lot not found.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    $lot_id    = $bl_row['lot_id'];
    $old_price = $bl_row['total_offered_bid'];
    $bid_id    = $bl_row['bid_id'];

    $conn->begin_transaction();
    try {
        // Update offered price
        $upd = $conn->prepare("UPDATE bid_lots SET total_offered_bid = ? WHERE id = ?");
        $upd->bind_param("di", $price, $bid_lot_id);
        $upd->execute();
        $upd->close();

        // Flip bid status to 'confirmed'
        $upd2 = $conn->prepare("UPDATE bids SET status = 'confirmed' WHERE id = ? AND bid_type = 'quotation'");
        $upd2->bind_param("i", $bid_id);
        $upd2->execute();
        $upd2->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['alert_error'] = "Failed to save price: " . $e->getMessage();
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    audit_log($conn, 'QUOTATION_PRICE_CONFIRMED', 'bid_lots', $bid_lot_id,
        "Admin confirmed offered price ₱" . number_format($price, 2) . " for bid_lot #{$bid_lot_id} (lot #{$lot_id}) in procurement #{$procurement_id}",
        ['total_offered_bid' => $old_price],
        ['total_offered_bid' => $price, 'bid_status' => 'confirmed']
    );

    $_SESSION['alert_success'] = "Price confirmed for Lot #{$lot_id}.";
    header("Location: quotation_management.php?id=" . $procurement_id);
    exit();
}

// ── Award a lot ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'award_lot') {
    $lot_id        = intval($_POST['lot_id'] ?? 0);
    $winner_bl_id  = intval($_POST['winner_bid_lot_id'] ?? 0);

    if ($lot_id <= 0 || $winner_bl_id <= 0) {
        $_SESSION['alert_error'] = "Please select a winner before awarding.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    // Verify winner bid_lot belongs to this procurement + lot
    $vw = $conn->prepare("
        SELECT bl.id, bl.total_offered_bid, b.id AS bid_id, b.bidder_id
        FROM bid_lots bl
        JOIN bids b ON bl.bid_id = b.id
        WHERE bl.id = ? AND bl.lot_id = ? AND b.procurement_id = ? AND b.bid_type = 'quotation'
        LIMIT 1
    ");
    $vw->bind_param("iii", $winner_bl_id, $lot_id, $procurement_id);
    $vw->execute();
    $winner = $vw->get_result()->fetch_assoc();
    $vw->close();

    if (!$winner || $winner['total_offered_bid'] === null) {
        $_SESSION['alert_error'] = "Selected winner has no confirmed price. Cannot award.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    $conn->begin_transaction();
    try {
        // Insert into awards table (UNIQUE KEY on lot_id — replace if re-awarding)
        $ins_award = $conn->prepare("
            INSERT INTO awards (lot_id, bid_lot_id, awarded_amount, award_date)
            VALUES (?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE
                bid_lot_id = VALUES(bid_lot_id),
                awarded_amount = VALUES(awarded_amount),
                award_date = VALUES(award_date)
        ");
        $ins_award->bind_param("iid", $lot_id, $winner_bl_id, $winner['total_offered_bid']);
        $ins_award->execute();
        $ins_award->close();

        // Mark lot as awarded
        $upd_lot = $conn->prepare("UPDATE lots SET status = 'awarded' WHERE id = ?");
        $upd_lot->bind_param("i", $lot_id);
        $upd_lot->execute();
        $upd_lot->close();

        // Winner bid → awarded
        $upd_win = $conn->prepare("UPDATE bids SET status = 'awarded' WHERE id = ?");
        $upd_win->bind_param("i", $winner['bid_id']);
        $upd_win->execute();
        $upd_win->close();

        // All other quotation bids for this lot that are not the winner → rejected
        $upd_lose = $conn->prepare("
            UPDATE bids SET status = 'rejected'
            WHERE id != ?
              AND id IN (
                  SELECT bid_id FROM bid_lots WHERE lot_id = ?
              )
              AND bid_type = 'quotation'
              AND procurement_id = ?
        ");
        $upd_lose->bind_param("iii", $winner['bid_id'], $lot_id, $procurement_id);
        $upd_lose->execute();
        $upd_lose->close();

        // Check if all lots are now concluded (awarded or failed) → flip procurement to awarded
        $remaining = (int)$conn->query("
            SELECT COUNT(*) FROM lots
            WHERE procurement_id = {$procurement_id} AND status = 'pending'
        ")->fetch_row()[0];
        if ($remaining === 0) {
            $conn->query("UPDATE procurements SET status = 'awarded' WHERE id = {$procurement_id}");
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['alert_error'] = "Failed to award lot: " . $e->getMessage();
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    audit_log($conn, 'LOT_AWARDED', 'lots', $lot_id,
        "Lot #{$lot_id} awarded to bid_lot #{$winner_bl_id} (₱" . number_format($winner['total_offered_bid'], 2) . ") in procurement #{$procurement_id}",
        ['lot_status' => 'pending'],
        ['lot_status' => 'awarded', 'winner_bid_lot_id' => $winner_bl_id, 'awarded_amount' => $winner['total_offered_bid']]
    );

    $_SESSION['alert_success'] = "Lot #{$lot_id} has been awarded successfully.";
    header("Location: quotation_management.php?id=" . $procurement_id);
    exit();
}

// ── Fail a lot ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fail_lot') {
    $lot_id = intval($_POST['lot_id'] ?? 0);

    if ($lot_id <= 0) {
        $_SESSION['alert_error'] = "Invalid lot.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    // Verify lot belongs to this procurement
    $vl = $conn->prepare("SELECT id, status FROM lots WHERE id = ? AND procurement_id = ? LIMIT 1");
    $vl->bind_param("ii", $lot_id, $procurement_id);
    $vl->execute();
    $lot_row = $vl->get_result()->fetch_assoc();
    $vl->close();

    if (!$lot_row) {
        $_SESSION['alert_error'] = "Lot not found.";
        header("Location: quotation_management.php?id=" . $procurement_id);
        exit();
    }

    $upd_lot = $conn->prepare("UPDATE lots SET status = 'failed' WHERE id = ?");
    $upd_lot->bind_param("i", $lot_id);
    $upd_lot->execute();
    $upd_lot->close();

    // Check if all lots are now concluded → flip procurement
    $remaining = (int)$conn->query("
        SELECT COUNT(*) FROM lots
        WHERE procurement_id = {$procurement_id} AND status = 'pending'
    ")->fetch_row()[0];
    if ($remaining === 0) {
        $conn->query("UPDATE procurements SET status = 'awarded' WHERE id = {$procurement_id}");
    }

    audit_log($conn, 'LOT_FAILED', 'lots', $lot_id,
        "Lot #{$lot_id} marked as failed in procurement #{$procurement_id}",
        ['lot_status' => $lot_row['status']],
        ['lot_status' => 'failed']
    );

    $_SESSION['alert_success'] = "Lot #{$lot_id} marked as failed.";
    header("Location: quotation_management.php?id=" . $procurement_id);
    exit();
}

// ── Fetch procurement ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$proc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proc) { header("Location: bid_submissions.php?error=not_found"); exit(); }

if (!is_quotation_mode($proc['procurement_mode'] ?? '')) {
    header("Location: bid-submission-view.php?id=" . $procurement_id);
    exit();
}

// ── Fetch lots ────────────────────────────────────────────────────────────────
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots = $lot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lot_stmt->close();

// ── Fetch existing awards (lot_id → award row) ───────────────────────────────
$aw_stmt = $conn->prepare("SELECT * FROM awards WHERE lot_id IN (SELECT id FROM lots WHERE procurement_id = ?)");
$aw_stmt->bind_param("i", $procurement_id);
$aw_stmt->execute();
$awards_raw = $aw_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$aw_stmt->close();
$awards_map = []; // lot_id → award row
foreach ($awards_raw as $aw) $awards_map[$aw['lot_id']] = $aw;

// ── Fetch quotations per lot ──────────────────────────────────────────────────
$quotations = [];
foreach ($lots as $lot) $quotations[$lot['id']] = [];

$q_stmt = $conn->prepare("
    SELECT
        b.id AS bid_id, b.bidder_id, b.submission_date, b.status AS bid_status,
        bl.id AS bid_lot_id, bl.lot_id, bl.total_offered_bid,
        u.firstname, u.lastname, u.email, u.profile_picture_url,
        bp.business_name,
        (SELECT bd.id   FROM bid_documents bd WHERE bd.bid_id = b.id AND bd.document_type = 'quotation' LIMIT 1) AS doc_id,
        (SELECT bd.document_name FROM bid_documents bd WHERE bd.bid_id = b.id AND bd.document_type = 'quotation' LIMIT 1) AS doc_name
    FROM bids b
    JOIN bid_lots bl ON bl.bid_id = b.id
    JOIN users u ON b.bidder_id = u.user_id
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE b.procurement_id = ? AND b.bid_type = 'quotation'
    ORDER BY bl.lot_id ASC, bl.total_offered_bid ASC, b.submission_date ASC
");
$q_stmt->bind_param("i", $procurement_id);
$q_stmt->execute();
$q_result = $q_stmt->get_result();
while ($row = $q_result->fetch_assoc()) {
    $lid = $row['lot_id'];
    if (isset($quotations[$lid])) $quotations[$lid][] = $row;
}
$q_stmt->close();

// Compute rank per lot
foreach ($quotations as $lid => $rows) {
    $rank = 1;
    foreach ($rows as $i => $row) {
        $quotations[$lid][$i]['computed_rank'] = ($row['total_offered_bid'] !== null) ? $rank++ : null;
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_bidders    = (int)$conn->query("SELECT COUNT(DISTINCT bidder_id) FROM bids WHERE procurement_id = {$procurement_id} AND bid_type = 'quotation'")->fetch_row()[0];
$confirmed_prices = (int)$conn->query("SELECT COUNT(*) FROM bid_lots bl JOIN bids b ON bl.bid_id = b.id WHERE b.procurement_id = {$procurement_id} AND b.bid_type = 'quotation' AND bl.total_offered_bid IS NOT NULL")->fetch_row()[0];
$pending_prices   = (int)$conn->query("SELECT COUNT(*) FROM bid_lots bl JOIN bids b ON bl.bid_id = b.id WHERE b.procurement_id = {$procurement_id} AND b.bid_type = 'quotation' AND bl.total_offered_bid IS NULL")->fetch_row()[0];
$lots_pending     = (int)$conn->query("SELECT COUNT(*) FROM lots WHERE procurement_id = {$procurement_id} AND status = 'pending'")->fetch_row()[0];

$mode_label = procurement_mode_label($proc['procurement_mode'] ?? '');
$p_status   = strtolower($proc['status'] ?? 'open');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Management — <?= htmlspecialchars($proc['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="../images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        * { box-sizing: border-box; }
        .dash-content { max-width: 100%; overflow-x: hidden; }

        .qm-nav-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
        .qm-breadcrumbs { display:flex; align-items:center; gap:6px; font-size:12px; color:#88968d; font-weight:600; }
        .qm-breadcrumbs a { color:#1f7a3d; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .qm-breadcrumbs a:hover { text-decoration:underline; color:#06251b; }
        .qm-back-link { display:inline-flex; align-items:center; gap:6px; color:#06251b; font-size:12.5px; font-weight:700; background:#fff; border:1px solid #eaeeec; padding:7px 14px; border-radius:10px; text-decoration:none; transition:all .2s; }
        .qm-back-link:hover { background:#06251b; color:#ffc107; border-color:#06251b; }

        .qm-hero { background:linear-gradient(135deg,#06251b 0%,#0c3d2c 60%,#14593f 100%); border-radius:20px; padding:26px 30px; color:#fff; position:relative; overflow:hidden; box-shadow:0 8px 24px rgba(6,37,27,.16); margin-bottom:24px; border:1px solid rgba(255,255,255,.08); }
        .qm-hero::after { content:''; position:absolute; top:-50px; right:-50px; width:240px; height:240px; background:radial-gradient(circle,rgba(255,193,7,.14) 0%,transparent 70%); border-radius:50%; pointer-events:none; }
        .qm-hero-top { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
        .qm-hero-badges { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .hero-pill { font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
        .hero-pill.ref { background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2); }
        .hero-pill.mode { background:rgba(255,193,7,.2); border:1px solid rgba(255,193,7,.4); color:#ffc107; }
        .hero-pill.status-open { background:rgba(33,150,83,.25); border:1px solid rgba(33,150,83,.5); color:#81c784; }
        .hero-pill.status-closed, .hero-pill.status-awarded { background:rgba(47,111,237,.2); border:1px solid rgba(47,111,237,.4); color:#90caf9; }
        .qm-hero-title { font-size:22px; font-weight:800; color:#fff; line-height:1.35; margin-bottom:18px; }
        .qm-hero-metrics { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
        .qm-hero-metric { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:12px 16px; }
        .qm-metric-lbl { font-size:10px; font-weight:700; color:#d1e5db; text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; display:flex; align-items:center; gap:5px; }
        .qm-metric-lbl i { color:#ffc107; }
        .qm-metric-val { font-size:18px; font-weight:800; color:#fff; font-family:'Space Grotesk',sans-serif; }
        .qm-metric-val.gold { color:#ffc107; }
        .qm-metric-val.warn { color:#ffb74d; }

        .qm-stats-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:26px; }
        .qm-stat-card { background:#fff; border:1px solid #eaeeec; border-radius:14px; padding:16px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 4px rgba(0,0,0,.03); }
        .qm-stat-icon { width:42px; height:42px; border-radius:12px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
        .qm-stat-icon.green { background:#eef7f1; color:#1f7a3d; }
        .qm-stat-icon.yellow { background:#fffbeb; color:#b78103; }
        .qm-stat-icon.blue { background:#eff6ff; color:#2563eb; }
        .qm-stat-icon.red { background:#fef2f2; color:#dc2626; }
        .qm-stat-label { font-size:11px; font-weight:700; color:#6c776e; margin-bottom:2px; }
        .qm-stat-value { font-size:22px; font-weight:800; color:#06251b; font-family:'Space Grotesk',sans-serif; line-height:1; }

        .qm-lot-section { margin-bottom:32px; }
        .qm-lot-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .qm-lot-title-wrap { display:flex; align-items:center; gap:10px; }
        .qm-lot-num-badge { background:#06251b; color:#ffc107; font-size:11px; font-weight:800; padding:4px 10px; border-radius:8px; font-family:'Space Grotesk',sans-serif; }
        .qm-lot-title { font-size:16px; font-weight:800; color:#06251b; }
        .qm-lot-abc { font-size:12px; color:#6c776e; font-weight:600; }
        .qm-lot-status-badge { font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
        .qm-lot-status-badge.pending  { background:#fff8e1; border:1px solid #ffe082; color:#b78103; }
        .qm-lot-status-badge.done     { background:#e4f5ea; border:1px solid #a5d6a7; color:#1f7a3d; }
        .qm-lot-status-badge.awarded  { background:#e8f5e9; border:1px solid #81c784; color:#1b5e20; }
        .qm-lot-status-badge.failed   { background:#ffebee; border:1px solid #ef9a9a; color:#b71c1c; }

        .qm-table-wrap { background:#fff; border:1px solid #eaeeec; border-radius:16px; overflow:hidden; box-shadow:0 2px 10px rgba(6,37,27,.04); }
        .qm-table { width:100%; border-collapse:collapse; font-size:13px; }
        .qm-table thead tr { background:#fafcfb; border-bottom:2px solid #eaeeec; }
        .qm-table thead th { padding:12px 16px; text-align:left; font-size:10.5px; font-weight:800; color:#88968d; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
        .qm-table tbody tr { border-bottom:1px solid #f4f6f5; transition:background .12s; }
        .qm-table tbody tr:last-child { border-bottom:none; }
        .qm-table tbody tr:hover { background:#fafcfb; }
        .qm-table td { padding:13px 16px; vertical-align:middle; }

        .qm-rank-badge { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; font-family:'Space Grotesk',sans-serif; }
        .qm-rank-badge.rank-1 { background:#fff8e1; color:#b78103; border:1px solid #ffe082; }
        .qm-rank-badge.rank-2 { background:#f0f4f2; color:#45655a; border:1px solid #c8d8d0; }
        .qm-rank-badge.rank-3 { background:#fdf0e7; color:#a0522d; border:1px solid #f8cba8; }
        .qm-rank-badge.rank-other { background:#f4f6f5; color:#6c776e; border:1px solid #e0e6e3; }
        .qm-rank-badge.rank-none { background:#f9fafb; color:#b0bec5; border:1px dashed #cfd8d3; }

        .qm-bidder-cell { display:flex; align-items:center; gap:10px; }
        .qm-bidder-avatar { width:36px; height:36px; border-radius:10px; flex-shrink:0; background:#e0ece4; color:#06251b; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800; overflow:hidden; }
        .qm-bidder-avatar img { width:100%; height:100%; object-fit:cover; border-radius:10px; }
        .qm-bidder-name { font-size:13px; font-weight:700; color:#06251b; }
        .qm-bidder-biz { font-size:11px; color:#6c776e; }

        .qm-price-confirmed { font-size:14px; font-weight:800; color:#1f7a3d; font-family:'Space Grotesk',sans-serif; }
        .qm-price-pending { font-size:12px; color:#88968d; font-style:italic; }

        .qm-doc-link { display:inline-flex; align-items:center; gap:5px; color:#1f7a3d; font-size:12px; font-weight:700; background:#eef7f1; border:1px solid #c8e6c9; border-radius:8px; padding:5px 10px; text-decoration:none; transition:all .15s; }
        .qm-doc-link:hover { background:#1f7a3d; color:#fff; border-color:#1f7a3d; }
        .qm-no-doc { font-size:12px; color:#b0bec5; font-style:italic; }

        .qm-price-form { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .qm-price-input { border:1.5px solid #cfd8d3; border-radius:8px; padding:6px 10px; font-family:'Poppins',sans-serif; font-size:13px; font-weight:600; color:#06251b; width:130px; outline:none; transition:border-color .15s; background:#fafcfb; }
        .qm-price-input:focus { border-color:#1f7a3d; background:#fff; }
        .qm-price-btn { background:#06251b; color:#ffc107; border:none; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all .15s; white-space:nowrap; }
        .qm-price-btn:hover { background:#144937; color:#fff; }
        .qm-price-edit-btn { background:#f0f4f2; color:#06251b; border:1px solid #dbe2df; border-radius:7px; padding:5px 10px; font-size:11px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all .15s; }
        .qm-price-edit-btn:hover { background:#06251b; color:#ffc107; border-color:#06251b; }

        .qm-empty-state { padding:36px 20px; text-align:center; color:#88968d; }
        .qm-empty-state i { font-size:40px; display:block; margin-bottom:10px; opacity:.5; }
        .qm-empty-state strong { display:block; font-size:14px; color:#06251b; margin-bottom:4px; }

        /* Status pills */
        .bid-status-pill { font-size:10.5px; font-weight:800; padding:3px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; text-transform:uppercase; }
        .bid-status-pill.pending   { background:#fef3c7; color:#92400e; }
        .bid-status-pill.submitted { background:#d1fae5; color:#065f46; }
        .bid-status-pill.confirmed { background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; }
        .bid-status-pill.awarded   { background:#e3f2fd; color:#0d47a1; border:1px solid #90caf9; }
        .bid-status-pill.rejected  { background:#fee2e2; color:#991b1b; }

        /* ── Award Panel ── */
        .qm-award-panel {
            background:#fff; border:1px solid #eaeeec; border-radius:18px;
            overflow:hidden; margin-top:14px;
            box-shadow:0 2px 10px rgba(6,37,27,.04);
        }
        .qm-award-panel-head {
            background:#fafcfb; border-bottom:1px solid #eaeeec;
            padding:14px 20px; display:flex; align-items:center;
            justify-content:space-between; gap:10px; flex-wrap:wrap;
        }
        .qm-award-panel-title {
            font-size:14px; font-weight:800; color:#06251b;
            display:flex; align-items:center; gap:8px;
        }
        .qm-award-panel-body { padding:18px 20px; }
        .qm-award-locked {
            display:flex; align-items:center; gap:12px; padding:14px 16px;
            background:#f7faf8; border:1px solid #e2e9e5; border-radius:12px;
            font-size:13px; color:#6c776e;
        }
        .qm-award-locked i { font-size:20px; color:#88968d; }

        /* Radio option rows */
        .qm-winner-option {
            display:flex; align-items:center; gap:14px; padding:12px 16px;
            border:2px solid #eaeeec; border-radius:12px; margin-bottom:8px;
            cursor:pointer; transition:all .15s; background:#fafcfb;
        }
        .qm-winner-option:hover { border-color:#b0c9bb; background:#f4faf6; }
        .qm-winner-option.selected { border-color:#1f7a3d; background:#f2f9f4; }
        .qm-winner-option input[type="radio"] { width:18px; height:18px; accent-color:#1f7a3d; cursor:pointer; flex-shrink:0; }
        .qm-winner-rank { font-family:'Space Grotesk',sans-serif; font-weight:800; font-size:12px; }
        .qm-winner-biz { font-size:13px; font-weight:700; color:#06251b; }
        .qm-winner-price { font-size:13px; font-weight:800; color:#1f7a3d; font-family:'Space Grotesk',sans-serif; margin-left:auto; white-space:nowrap; }

        /* Award result display */
        .qm-awarded-result {
            display:flex; align-items:center; gap:14px; padding:14px 18px;
            background:#e8f5e9; border:1px solid #a5d6a7; border-radius:12px;
        }
        .qm-awarded-result i { font-size:22px; color:#1f7a3d; }
        .qm-awarded-result-biz { font-size:14px; font-weight:800; color:#1b5e20; }
        .qm-awarded-result-price { font-size:12px; color:#388e3c; font-weight:600; }
        .qm-failed-result {
            display:flex; align-items:center; gap:14px; padding:14px 18px;
            background:#ffebee; border:1px solid #ef9a9a; border-radius:12px;
        }
        .qm-failed-result i { font-size:22px; color:#b71c1c; }
        .qm-failed-result-text { font-size:14px; font-weight:800; color:#b71c1c; }

        .btn-award-lot {
            background:#1f7a3d; color:#fff; border:none; border-radius:10px;
            padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer;
            display:inline-flex; align-items:center; gap:6px; transition:all .15s;
        }
        .btn-award-lot:hover { background:#16602f; }
        .btn-award-lot:disabled { opacity:.45; cursor:not-allowed; }
        .btn-fail-lot {
            background:#fff; color:#b71c1c; border:1.5px solid #ef9a9a; border-radius:10px;
            padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer;
            display:inline-flex; align-items:center; gap:6px; transition:all .15s;
        }
        .btn-fail-lot:hover { background:#ffebee; border-color:#b71c1c; }
        .qm-award-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:16px; padding-top:14px; border-top:1px solid #f0f4f2; }
    </style>
</head>
<body class="dash-body">

<?php $active_nav = 'bid_submissions.php'; include("components/sidebar.php"); ?>
<?php $topbar_title = 'Quotation Management'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Breadcrumbs -->
    <div class="qm-nav-bar">
        <div class="qm-breadcrumbs">
            <a href="bid_submissions.php"><i class="bi bi-inbox"></i> Bid Submissions</a>
            <span>/</span>
            <span style="color:#06251b;">Quotation Management</span>
        </div>
        <a href="bid_submissions.php" class="qm-back-link">
            <i class="bi bi-arrow-left"></i> Back to Submissions
        </a>
    </div>

    <!-- Hero -->
    <div class="qm-hero">
        <div class="qm-hero-top">
            <div class="qm-hero-badges">
                <span class="hero-pill ref">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($proc['procurement_ref_no'] ?? $proc['philgeps_ref_no'] ?? 'N/A') ?>
                </span>
                <span class="hero-pill mode">
                    <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($mode_label) ?>
                </span>
                <span class="hero-pill status-<?= in_array($p_status,['open','awarded']) ? $p_status : 'closed' ?>">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                </span>
            </div>
            <span style="font-size:11px; color:#d1e5db; font-weight:600;">
                <i class="bi bi-list-ol" style="color:#ffc107;"></i> Quotation Ranking
            </span>
        </div>
        <h1 class="qm-hero-title"><?= htmlspecialchars($proc['title']) ?></h1>
        <div class="qm-hero-metrics">
            <div class="qm-hero-metric">
                <div class="qm-metric-lbl"><i class="bi bi-cash-stack"></i> Total ABC</div>
                <div class="qm-metric-val gold">₱<?= number_format((float)$proc['abc'], 2) ?></div>
            </div>
            <div class="qm-hero-metric">
                <div class="qm-metric-lbl"><i class="bi bi-layers-fill"></i> Lots</div>
                <div class="qm-metric-val"><?= count($lots) ?></div>
            </div>
            <div class="qm-hero-metric">
                <div class="qm-metric-lbl"><i class="bi bi-people-fill"></i> Bidders</div>
                <div class="qm-metric-val"><?= $total_bidders ?></div>
            </div>
            <div class="qm-hero-metric">
                <div class="qm-metric-lbl"><i class="bi bi-check2-circle"></i> Prices Confirmed</div>
                <div class="qm-metric-val <?= $confirmed_prices > 0 ? '' : 'warn' ?>"><?= $confirmed_prices ?></div>
            </div>
            <div class="qm-hero-metric">
                <div class="qm-metric-lbl"><i class="bi bi-hourglass-split"></i> Awaiting</div>
                <div class="qm-metric-val <?= $pending_prices > 0 ? 'warn' : '' ?>"><?= $pending_prices ?></div>
            </div>
        </div>
    </div>

    <!-- Stats Strip -->
    <div class="qm-stats-strip">
        <div class="qm-stat-card">
            <div class="qm-stat-icon green"><i class="bi bi-people-fill"></i></div>
            <div><div class="qm-stat-label">Total Quotations</div><div class="qm-stat-value"><?= $total_bidders ?></div></div>
        </div>
        <div class="qm-stat-card">
            <div class="qm-stat-icon yellow"><i class="bi bi-check2-square"></i></div>
            <div><div class="qm-stat-label">Prices Confirmed</div><div class="qm-stat-value"><?= $confirmed_prices ?></div></div>
        </div>
        <div class="qm-stat-card">
            <div class="qm-stat-icon blue"><i class="bi bi-clock-history"></i></div>
            <div><div class="qm-stat-label">Pending Review</div><div class="qm-stat-value"><?= $pending_prices ?></div></div>
        </div>
        <div class="qm-stat-card">
            <div class="qm-stat-icon <?= $lots_pending > 0 ? 'yellow' : 'green' ?>"><i class="bi bi-trophy-fill"></i></div>
            <div><div class="qm-stat-label">Lots Pending Award</div><div class="qm-stat-value"><?= $lots_pending ?></div></div>
        </div>
    </div>

    <!-- Lot-by-lot tables + award panels -->
    <?php if (empty($lots)): ?>
        <div class="qm-table-wrap">
            <div class="qm-empty-state">
                <i class="bi bi-layers"></i>
                <strong>No Lots Defined</strong>
                Add lots to this procurement first.
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($lots as $lot):
            $lot_quotes   = $quotations[$lot['id']] ?? [];
            $pending_cnt  = count(array_filter($lot_quotes, fn($q) => $q['total_offered_bid'] === null));
            $all_confirmed = count($lot_quotes) > 0 && $pending_cnt === 0;
            $lot_status   = strtolower($lot['status'] ?? 'pending');
            $award_row    = $awards_map[$lot['id']] ?? null;

            // Find the winner bid data if awarded
            $winner_data  = null;
            if ($award_row) {
                foreach ($lot_quotes as $q) {
                    if ($q['bid_lot_id'] == $award_row['bid_lot_id']) {
                        $winner_data = $q;
                        break;
                    }
                }
            }
        ?>
        <div class="qm-lot-section">
            <!-- Lot Header -->
            <div class="qm-lot-header">
                <div class="qm-lot-title-wrap">
                    <span class="qm-lot-num-badge">Lot <?= $lot['lot_number'] ?></span>
                    <div>
                        <div class="qm-lot-title"><?= htmlspecialchars($lot['lot_title']) ?></div>
                        <div class="qm-lot-abc">ABC: ₱<?= number_format((float)$lot['abc'], 2) ?></div>
                    </div>
                </div>
                <?php if ($lot_status === 'awarded'): ?>
                    <span class="qm-lot-status-badge awarded"><i class="bi bi-trophy-fill"></i> Awarded</span>
                <?php elseif ($lot_status === 'failed'): ?>
                    <span class="qm-lot-status-badge failed"><i class="bi bi-x-circle-fill"></i> Failed</span>
                <?php elseif ($all_confirmed): ?>
                    <span class="qm-lot-status-badge done"><i class="bi bi-check-circle-fill"></i> All Prices Confirmed</span>
                <?php elseif ($pending_cnt > 0): ?>
                    <span class="qm-lot-status-badge pending"><i class="bi bi-hourglass-split"></i> <?= $pending_cnt ?> Pending</span>
                <?php endif; ?>
            </div>

            <!-- Quotations Table -->
            <div class="qm-table-wrap">
                <?php if (empty($lot_quotes)): ?>
                    <div class="qm-empty-state">
                        <i class="bi bi-inbox"></i>
                        <strong>No Quotations Yet</strong>
                        No bidders have submitted a quotation for this lot.
                    </div>
                <?php else: ?>
                    <table class="qm-table">
                        <thead>
                            <tr>
                                <th style="width:44px;">Rank</th>
                                <th>Bidder</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Document</th>
                                <th>Offered Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lot_quotes as $q):
                            $rank      = $q['computed_rank'];
                            $has_price = $q['total_offered_bid'] !== null;
                            $initials  = strtoupper(substr($q['firstname'],0,1) . substr($q['lastname'],0,1));
                            $avatar    = !empty($q['profile_picture_url']) ? '../' . ltrim($q['profile_picture_url'], '/') : '';
                            $biz_name  = $q['business_name'] ?: ($q['firstname'] . ' ' . $q['lastname']);
                            $is_winner = $award_row && $award_row['bid_lot_id'] == $q['bid_lot_id'];

                            if (!$has_price) { $rank_class = 'rank-none'; $rank_display = '—'; }
                            else {
                                $rank_display = '#' . $rank;
                                $rank_class = match(true) { $rank===1=>'rank-1', $rank===2=>'rank-2', $rank===3=>'rank-3', default=>'rank-other' };
                            }
                            $row_bg = $is_winner ? 'background:#f0fdf4;' : '';
                        ?>
                        <tr id="row-<?= $q['bid_lot_id'] ?>" style="<?= $row_bg ?>">
                            <td>
                                <div class="qm-rank-badge <?= $rank_class ?>"><?= htmlspecialchars($rank_display) ?></div>
                            </td>
                            <td>
                                <div class="qm-bidder-cell">
                                    <div class="qm-bidder-avatar">
                                        <?php if (!empty($avatar)): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initials) ?><?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="qm-bidder-name">
                                            <?= htmlspecialchars($biz_name) ?>
                                            <?php if ($is_winner): ?><span style="font-size:10px; background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; border-radius:6px; padding:1px 6px; margin-left:4px; font-weight:800;">AWARDED</span><?php endif; ?>
                                        </div>
                                        <div class="qm-bidder-biz"><?= htmlspecialchars($q['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="white-space:nowrap; font-size:12px; color:#55665a;">
                                <?= date('M j, Y · g:i A', strtotime($q['submission_date'])) ?>
                            </td>
                            <td>
                                <?php
                                    $bs = $q['bid_status'];
                                    $bs_label = match($bs) { 'submitted'=>'VERIFIED', 'confirmed'=>'CONFIRMED', 'awarded'=>'AWARDED', 'rejected'=>'REJECTED', default=>strtoupper($bs) };
                                ?>
                                <span class="bid-status-pill <?= htmlspecialchars($bs) ?>">
                                    <i class="bi bi-circle-fill" style="font-size:5px;"></i> <?= $bs_label ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($q['doc_id']): ?>
                                    <a href="quotation_management.php?id=<?= $procurement_id ?>&action=view_doc&doc_id=<?= $q['doc_id'] ?>"
                                       target="_blank" class="qm-doc-link">
                                        <i class="bi bi-file-earmark-text-fill"></i> View
                                    </a>
                                <?php else: ?>
                                    <span class="qm-no-doc"><i class="bi bi-dash-circle"></i> No file</span>
                                <?php endif; ?>
                            </td>
                            <td id="price-cell-<?= $q['bid_lot_id'] ?>">
                                <?php if ($has_price): ?>
                                    <div class="qm-price-confirmed">₱<?= number_format((float)$q['total_offered_bid'], 2) ?></div>
                                <?php else: ?>
                                    <span class="qm-price-pending">Not confirmed</span>
                                <?php endif; ?>
                            </td>
                            <td id="action-cell-<?= $q['bid_lot_id'] ?>">
                                <?php if ($lot_status === 'awarded' || $lot_status === 'failed'): ?>
                                    <span style="font-size:11px; color:#88968d; font-style:italic;">Concluded</span>
                                <?php elseif ($has_price): ?>
                                    <button type="button" class="qm-price-edit-btn"
                                            onclick="showPriceForm(<?= $q['bid_lot_id'] ?>, <?= htmlspecialchars((string)$q['total_offered_bid'], ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil-fill"></i> Edit
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="qm-price-btn"
                                            onclick="showPriceForm(<?= $q['bid_lot_id'] ?>, null)">
                                        <i class="bi bi-check2-square"></i> Confirm Price
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <!-- Inline price form row -->
                        <tr id="form-row-<?= $q['bid_lot_id'] ?>" style="display:none; background:#f7faf8;">
                            <td colspan="7" style="padding:14px 16px;">
                                <form method="POST" action="quotation_management.php?id=<?= $procurement_id ?>" class="qm-price-form">
                                    <input type="hidden" name="action" value="confirm_price">
                                    <input type="hidden" name="bid_lot_id" value="<?= $q['bid_lot_id'] ?>">
                                    <span style="font-size:13px; font-weight:700; color:#06251b; flex-shrink:0;"><i class="bi bi-currency-exchange" style="color:#1f7a3d;"></i> Enter Offered Price:</span>
                                    <span style="font-size:14px; font-weight:800; color:#1f7a3d; flex-shrink:0;">₱</span>
                                    <input type="number" name="offered_price" step="0.01" min="0"
                                           id="price-input-<?= $q['bid_lot_id'] ?>"
                                           class="qm-price-input" placeholder="0.00"
                                           value="<?= $has_price ? htmlspecialchars((string)$q['total_offered_bid']) : '' ?>" required>
                                    <button type="submit" class="qm-price-btn"><i class="bi bi-check-lg"></i> Save</button>
                                    <button type="button" class="qm-price-edit-btn" onclick="hidePriceForm(<?= $q['bid_lot_id'] ?>)"><i class="bi bi-x"></i> Cancel</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ── Award / Fail Panel ── -->
            <div class="qm-award-panel">
                <div class="qm-award-panel-head">
                    <div class="qm-award-panel-title">
                        <i class="bi bi-trophy-fill" style="color:#1f7a3d;"></i>
                        Lot <?= $lot['lot_number'] ?> — Award Decision
                    </div>
                    <?php if ($lot_status === 'awarded'): ?>
                        <span class="qm-lot-status-badge awarded"><i class="bi bi-check-circle-fill"></i> Lot Awarded</span>
                    <?php elseif ($lot_status === 'failed'): ?>
                        <span class="qm-lot-status-badge failed"><i class="bi bi-x-circle-fill"></i> Lot Failed</span>
                    <?php elseif (!$all_confirmed): ?>
                        <span class="qm-lot-status-badge pending"><i class="bi bi-lock"></i> Prices Incomplete</span>
                    <?php endif; ?>
                </div>
                <div class="qm-award-panel-body">

                    <?php if ($lot_status === 'awarded' && $winner_data): ?>
                        <!-- Already awarded — show result -->
                        <div class="qm-awarded-result">
                            <i class="bi bi-trophy-fill"></i>
                            <div>
                                <div class="qm-awarded-result-biz"><?= htmlspecialchars($winner_data['business_name'] ?: ($winner_data['firstname'].' '.$winner_data['lastname'])) ?></div>
                                <div class="qm-awarded-result-price">Awarded at ₱<?= number_format((float)$award_row['awarded_amount'], 2) ?> &bull; <?= date('M j, Y', strtotime($award_row['award_date'])) ?></div>
                            </div>
                        </div>

                    <?php elseif ($lot_status === 'failed'): ?>
                        <div class="qm-failed-result">
                            <i class="bi bi-x-circle-fill"></i>
                            <div class="qm-failed-result-text">This lot has been marked as failed. No award was given.</div>
                        </div>

                    <?php elseif (!$all_confirmed || empty($lot_quotes)): ?>
                        <!-- Prices not all confirmed yet — lock the panel -->
                        <div class="qm-award-locked">
                            <i class="bi bi-lock-fill"></i>
                            <span>All prices for this lot must be confirmed before you can award or fail it. <strong><?= $pending_cnt ?></strong> price<?= $pending_cnt !== 1 ? 's' : '' ?> still pending.</span>
                        </div>

                    <?php else: ?>
                        <!-- Ready to award — radio selection -->
                        <p style="font-size:12.5px; color:#6c776e; margin:0 0 14px; line-height:1.6;">
                            Select the winning bidder for this lot. The ranking is for reference — you can choose any bidder.
                        </p>
                        <form method="POST" action="quotation_management.php?id=<?= $procurement_id ?>" id="award-form-<?= $lot['id'] ?>">
                            <input type="hidden" name="action" value="award_lot">
                            <input type="hidden" name="lot_id" value="<?= $lot['id'] ?>">

                            <?php foreach ($lot_quotes as $q):
                                $biz = $q['business_name'] ?: ($q['firstname'] . ' ' . $q['lastname']);
                                $r   = $q['computed_rank'];
                                $rank_label = $r ? "#{$r}" : '—';
                            ?>
                            <label class="qm-winner-option" onclick="this.classList.add('selected'); document.querySelectorAll('#award-form-<?= $lot['id'] ?> .qm-winner-option').forEach(o=>{ if(o!==this) o.classList.remove('selected'); }); document.getElementById('award-btn-<?= $lot['id'] ?>').disabled=false;">
                                <input type="radio" name="winner_bid_lot_id" value="<?= $q['bid_lot_id'] ?>" required>
                                <span class="qm-winner-rank qm-rank-badge <?= $r ? ($r===1?'rank-1':($r===2?'rank-2':($r===3?'rank-3':'rank-other'))) : 'rank-none' ?>"><?= htmlspecialchars($rank_label) ?></span>
                                <div>
                                    <div class="qm-winner-biz"><?= htmlspecialchars($biz) ?></div>
                                    <div style="font-size:11px; color:#6c776e;"><?= htmlspecialchars($q['email']) ?></div>
                                </div>
                                <div class="qm-winner-price">₱<?= number_format((float)$q['total_offered_bid'], 2) ?></div>
                            </label>
                            <?php endforeach; ?>

                            <div class="qm-award-actions">
                                <button type="submit" id="award-btn-<?= $lot['id'] ?>" class="btn-award-lot" disabled>
                                    <i class="bi bi-trophy-fill"></i> Award This Lot
                                </button>
                            </div>
                        </form>

                        <!-- Fail Lot — separate form -->
                        <form method="POST" action="quotation_management.php?id=<?= $procurement_id ?>"
                              style="margin-top:10px;"
                              onsubmit="return confirm('Mark Lot <?= $lot['lot_number'] ?> as failed? This cannot be undone.');">
                            <input type="hidden" name="action" value="fail_lot">
                            <input type="hidden" name="lot_id" value="<?= $lot['id'] ?>">
                            <button type="submit" class="btn-fail-lot">
                                <i class="bi bi-x-circle-fill"></i> Fail This Lot
                            </button>
                        </form>

                    <?php endif; ?>

                </div>
            </div>

        </div><!-- /qm-lot-section -->
        <?php endforeach; ?>
    <?php endif; ?>

</div>
</main>

<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?></div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert"><i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?></div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
const toast = document.getElementById('toastAlert');
if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

function showPriceForm(bidLotId, currentPrice) {
    const formRow = document.getElementById('form-row-' + bidLotId);
    if (formRow) formRow.style.display = '';
    const inp = document.getElementById('price-input-' + bidLotId);
    if (inp) {
        if (currentPrice !== null) inp.value = parseFloat(currentPrice).toFixed(2);
        inp.focus();
    }
}
function hidePriceForm(bidLotId) {
    const formRow = document.getElementById('form-row-' + bidLotId);
    if (formRow) formRow.style.display = 'none';
}
</script>
</body>
</html>
