<?php
require_once '../config/db_connect.php';

// ── Filters ───────────────────────────────────────────────────────────────
$filter_proc  = isset($_GET['proc_id'])  ? (int)$_GET['proc_id']  : 0;
$filter_bid   = isset($_GET['bid_id'])   ? (int)$_GET['bid_id']   : 0;
$filter_status = isset($_GET['status'])  ? trim($_GET['status'])   : '';

// ── Load all procurements for dropdown ────────────────────────────────────
$procs = $conn->query("SELECT id, philgeps_ref_no, title FROM procurements ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// ── Main bids query ───────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];
$types  = '';

if ($filter_proc > 0) {
    $where[] = 'b.procurement_id = ?';
    $params[] = $filter_proc;
    $types .= 'i';
}
if ($filter_bid > 0) {
    $where[] = 'b.id = ?';
    $params[] = $filter_bid;
    $types .= 'i';
}
if ($filter_status !== '') {
    $where[] = 'b.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

$sql = "
    SELECT
        b.id          AS bid_id,
        b.status      AS bid_status,
        b.submission_date,
        p.id          AS proc_id,
        p.philgeps_ref_no,
        p.title       AS proc_title,
        p.status      AS proc_status,
        u.user_id     AS bidder_user_id,
        u.firstname,
        u.lastname,
        u.username,
        COALESCE(bp.business_name, CONCAT(u.firstname,' ',u.lastname)) AS business_name,
        COUNT(DISTINCT bl.lot_id)       AS total_lots,
        COUNT(DISTINCT bd.id)           AS total_docs
    FROM bids b
    JOIN procurements p  ON p.id = b.procurement_id
    JOIN users u         ON u.user_id = b.bidder_id
    LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
    LEFT JOIN bid_lots bl        ON bl.bid_id = b.id
    LEFT JOIN bid_documents bd   ON bd.bid_id = b.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY b.id, b.status, b.submission_date,
             p.id, p.philgeps_ref_no, p.title, p.status,
             u.user_id, u.firstname, u.lastname, u.username, business_name
    ORDER BY b.id DESC
";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$bids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Bid detail (single bid expanded) ─────────────────────────────────────
$detail = null;
$detail_lots = [];
$detail_docs = [];

if ($filter_bid > 0 && !empty($bids)) {
    $detail = $bids[0];

    // Lot-level statuses
    $ls = $conn->prepare("
        SELECT bl.lot_id, bl.eligibility_status, bl.financial_status,
               l.lot_number, l.lot_title, l.abc
        FROM bid_lots bl
        JOIN lots l ON l.id = bl.lot_id
        WHERE bl.bid_id = ?
        ORDER BY l.lot_number ASC
    ");
    $ls->bind_param('i', $filter_bid);
    $ls->execute();
    $detail_lots = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();

    // Documents
    $ds = $conn->prepare("
        SELECT id, document_type, document_name, file_path, uploaded_at
        FROM bid_documents
        WHERE bid_id = ?
        ORDER BY document_type ASC, uploaded_at ASC
    ");
    $ds->bind_param('i', $filter_bid);
    $ds->execute();
    $detail_docs = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
    $ds->close();
}

// ── Helpers ───────────────────────────────────────────────────────────────
function statusBadge($s) {
    $map = [
        'submitted'  => '#3b82f6:#fff',
        'opened'     => '#8b5cf6:#fff',
        'pending'    => '#f59e0b:#fff',
        'awarded'    => '#16a34a:#fff',
        'rejected'   => '#ef4444:#fff',
        'eligible'   => '#16a34a:#fff',
        'disqualified' => '#ef4444:#fff',
        'non_compliant' => '#f97316:#fff',
        'open'       => '#16a34a:#fff',
        'closed'     => '#6b7280:#fff',
        'draft'      => '#9ca3af:#1f2937',
        'cancelled'  => '#dc2626:#fff',
    ];
    [$bg, $fg] = explode(':', $map[$s] ?? '#e5e7eb:#374151');
    return "<span style='background:{$bg};color:{$fg};padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block'>".htmlspecialchars($s)."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bids Debugger</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f1f5f2;color:#1a2a20;font-size:13px;padding:24px}
h1{font-size:20px;font-weight:800;color:#06251b;margin-bottom:4px}
.sub{font-size:12px;color:#55665a;margin-bottom:20px}
.card{background:#fff;border:1px solid #dde5e0;border-radius:10px;padding:20px;margin-bottom:20px}
.card-title{font-size:14px;font-weight:800;color:#06251b;margin-bottom:14px;display:flex;align-items:center;gap:7px}
form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
label{font-size:11.5px;font-weight:700;color:#55665a;display:block;margin-bottom:4px}
input[type=number],select{padding:7px 10px;border:1.5px solid #d1dbd5;border-radius:7px;font-size:12.5px;font-family:inherit;outline:none;color:#1a2a20;background:#fafcfb}
input[type=number]:focus,select:focus{border-color:#1f7a3d}
.btn{padding:7px 16px;border:none;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.btn-green{background:#06251b;color:#ffc107}
.btn-green:hover{background:#0c3d2c}
.btn-gray{background:#e5ece8;color:#374151}
.btn-gray:hover{background:#d1dbd5}
.btn-blue{background:#3b82f6;color:#fff;font-size:11.5px;padding:4px 12px}
.btn-blue:hover{background:#2563eb}
table{width:100%;border-collapse:collapse;font-size:12.5px}
thead th{background:#f4f7f5;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#55665a;text-transform:uppercase;letter-spacing:.04em;border-bottom:1.5px solid #e2ebe5;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f2;transition:background .1s}
tbody tr:hover{background:#fbfdfc}
tbody tr:last-child{border-bottom:none}
td{padding:10px 12px;vertical-align:middle}
.lot-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;margin:2px}
.lot-pill.elig-pending{background:#fef9c3;color:#92400e}
.lot-pill.elig-eligible{background:#dcfce7;color:#166534}
.lot-pill.elig-disqualified{background:#fee2e2;color:#991b1b}
.lot-pill.fin-pending{background:#f3f4f6;color:#6b7280}
.lot-pill.fin-opened{background:#ede9fe;color:#5b21b6}
.lot-pill.fin-non_compliant{background:#ffedd5;color:#9a3412}
.summary-box{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px}
.sbox{background:#f4f7f5;border-radius:8px;padding:12px 14px;text-align:center}
.sbox-num{font-size:22px;font-weight:800;color:#06251b;font-family:'Segoe UI',sans-serif}
.sbox-lbl{font-size:10.5px;color:#55665a;font-weight:700;margin-top:2px}
.detail-section{margin-bottom:16px}
.detail-section h4{font-size:12.5px;font-weight:800;color:#06251b;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #eee}
.empty{padding:28px;text-align:center;color:#88968d;font-size:12.5px}
</style>
</head>
<body>

<h1>🛠 Bids Debugger</h1>
<p class="sub">Inspect all submitted bids and their procurement context, lot statuses, and documents.</p>

<!-- Filter Card -->
<div class="card">
    <div class="card-title">🔍 Filters</div>
    <form method="GET">
        <div>
            <label>Procurement</label>
            <select name="proc_id" style="min-width:240px">
                <option value="">All Procurements</option>
                <?php foreach ($procs as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filter_proc===$p['id']?'selected':'' ?>>
                    #<?= $p['id'] ?> — <?= htmlspecialchars(mb_strimwidth($p['title'],0,45,'…')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Bid ID</label>
            <input type="number" name="bid_id" value="<?= $filter_bid ?: '' ?>" placeholder="e.g. 12" style="width:100px">
        </div>
        <div>
            <label>Bid Status</label>
            <select name="status">
                <option value="">All</option>
                <?php foreach (['submitted','opened','pending','awarded','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $filter_status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-green">Apply</button>
            <a href="show_bids.php" class="btn btn-gray" style="margin-left:4px">Reset</a>
        </div>
    </form>
</div>

<!-- Summary -->
<?php
$total    = count($bids);
$statuses = array_count_values(array_column($bids, 'bid_status'));
?>
<div class="card">
    <div class="card-title">📊 Summary</div>
    <div class="summary-box">
        <div class="sbox"><div class="sbox-num"><?= $total ?></div><div class="sbox-lbl">Total Bids</div></div>
        <?php foreach ($statuses as $s => $c): ?>
        <div class="sbox"><div class="sbox-num"><?= $c ?></div><div class="sbox-lbl"><?= ucfirst($s) ?></div></div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Bid Detail (when single bid selected) -->
<?php if ($detail): ?>
<div class="card">
    <div class="card-title">🔎 Bid #<?= $detail['bid_id'] ?> — Full Detail</div>

    <div class="detail-section">
        <h4>Procurement</h4>
        <table>
            <tr><th style="width:180px">ID</th><td>#<?= $detail['proc_id'] ?></td></tr>
            <tr><th>PhilGEPS Ref</th><td><?= htmlspecialchars($detail['philgeps_ref_no']) ?></td></tr>
            <tr><th>Title</th><td><?= htmlspecialchars($detail['proc_title']) ?></td></tr>
            <tr><th>Status</th><td><?= statusBadge($detail['proc_status']) ?></td></tr>
        </table>
    </div>

    <div class="detail-section">
        <h4>Bidder</h4>
        <table>
            <tr><th style="width:180px">User ID</th><td>#<?= $detail['bidder_user_id'] ?></td></tr>
            <tr><th>Name</th><td><?= htmlspecialchars($detail['firstname'].' '.$detail['lastname']) ?></td></tr>
            <tr><th>Username</th><td><?= htmlspecialchars($detail['username']) ?></td></tr>
            <tr><th>Business Name</th><td><?= htmlspecialchars($detail['business_name']) ?></td></tr>
        </table>
    </div>

    <div class="detail-section">
        <h4>Bid</h4>
        <table>
            <tr><th style="width:180px">Bid ID</th><td>#<?= $detail['bid_id'] ?></td></tr>
            <tr><th>Status</th><td><?= statusBadge($detail['bid_status']) ?></td></tr>
            <tr><th>Submitted At</th><td><?= $detail['submission_date'] ?></td></tr>
        </table>
    </div>

    <?php if ($detail_lots): ?>
    <div class="detail-section">
        <h4>Lot Statuses (bid_lots)</h4>
        <table>
            <thead><tr>
                <th>Lot ID</th><th>Lot #</th><th>Title</th><th>ABC</th>
                <th>Eligibility Status</th><th>Financial Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($detail_lots as $dl): ?>
            <tr>
                <td>#<?= $dl['lot_id'] ?></td>
                <td><?= $dl['lot_number'] ?></td>
                <td><?= htmlspecialchars($dl['lot_title'] ?? '—') ?></td>
                <td>₱<?= number_format($dl['abc'],2) ?></td>
                <td><span class="lot-pill elig-<?= $dl['eligibility_status'] ?>"><?= $dl['eligibility_status'] ?></span></td>
                <td><span class="lot-pill fin-<?= $dl['financial_status'] ?>"><?= $dl['financial_status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($detail_docs): ?>
    <div class="detail-section">
        <h4>Documents (bid_documents)</h4>
        <table>
            <thead><tr>
                <th>Doc ID</th><th>Type</th><th>Name</th><th>Path</th><th>Uploaded</th>
            </tr></thead>
            <tbody>
            <?php foreach ($detail_docs as $doc): ?>
            <tr>
                <td>#<?= $doc['id'] ?></td>
                <td><?= statusBadge($doc['document_type']) ?></td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($doc['document_name']) ?>">
                    <?= htmlspecialchars($doc['document_name']) ?>
                </td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#55665a" title="<?= htmlspecialchars($doc['file_path']) ?>">
                    <?= htmlspecialchars($doc['file_path']) ?>
                </td>
                <td style="white-space:nowrap"><?= $doc['uploaded_at'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- Bids Table -->
<div class="card">
    <div class="card-title">📋 Bids (<?= $total ?>)</div>
    <?php if (empty($bids)): ?>
    <div class="empty">No bids match the current filters.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Bid ID</th>
                <th>Procurement</th>
                <th>Bidder</th>
                <th>Business</th>
                <th>Status</th>
                <th>Lots</th>
                <th>Docs</th>
                <th>Submitted</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bids as $bid): ?>
        <tr>
            <td><strong>#<?= $bid['bid_id'] ?></strong></td>
            <td>
                <div style="font-weight:700;color:#06251b"><?= htmlspecialchars(mb_strimwidth($bid['proc_title'],0,35,'…')) ?></div>
                <div style="font-size:10.5px;color:#88968d">#<?= $bid['proc_id'] ?> · <?= htmlspecialchars($bid['philgeps_ref_no']) ?></div>
            </td>
            <td>
                <?= htmlspecialchars($bid['firstname'].' '.$bid['lastname']) ?>
                <div style="font-size:10.5px;color:#88968d">@<?= htmlspecialchars($bid['username']) ?></div>
            </td>
            <td><?= htmlspecialchars($bid['business_name']) ?></td>
            <td><?= statusBadge($bid['bid_status']) ?></td>
            <td style="text-align:center"><?= $bid['total_lots'] ?></td>
            <td style="text-align:center"><?= $bid['total_docs'] ?></td>
            <td style="white-space:nowrap;font-size:11.5px"><?= date('M j, Y g:i A', strtotime($bid['submission_date'])) ?></td>
            <td>
                <a href="?bid_id=<?= $bid['bid_id'] ?>&proc_id=<?= $bid['proc_id'] ?>" class="btn btn-blue">Inspect</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>
