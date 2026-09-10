<?php
include "config/db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$user_dashboard_link = "login.php";
$user_logged_in = false;
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $user_logged_in = true;
    switch ($_SESSION['role']) {
        case "user":       $user_dashboard_link = "user/dashboard.php"; break;
        case "bidder":     $user_dashboard_link = "bidder/dashboard.php"; break;
        case "admin":      $user_dashboard_link = "admin/dashboard.php"; break;
        case "superadmin": $user_dashboard_link = "superadmin/dashboard.php"; break;
    }
}

$procurement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($procurement_id === 0) {
    header("Location: bid_schedule.php");
    exit();
}

// 1. Procurement
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status NOT IN ('draft','cancelled') LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    header("Location: bid_schedule.php");
    exit();
}

// 2. Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_res = $lot_stmt->get_result();
$lots = []; $total_lots_abc = 0;
while ($l = $lots_res->fetch_assoc()) { $lots[] = $l; $total_lots_abc += (float)$l['abc']; }
$lot_stmt->close();

if (!function_exists('resolve_proc_doc_url')) {
    function resolve_proc_doc_url($file_path, $context = 'root') {
        $raw = trim($file_path ?? '');
        if (empty($raw)) return '#';
        if (preg_match('/^https?:\/\//i', $raw)) return $raw;

        $rel = ltrim($raw, '/');
        while (strpos($rel, '../') === 0) {
            $rel = substr($rel, 3);
        }
        if (strpos($rel, 'uploads/') !== 0) {
            $rel = 'uploads/procurements/' . $rel;
        }

        if (in_array($context, ['admin', 'bidder', 'user'])) {
            return '../' . $rel;
        }
        return $rel;
    }
}

// 3. Documents (Categorized into Original and Associated)
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$all_docs = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$doc_stmt->close();

$original_documents   = [];
$associated_documents = [];
foreach ($all_docs as $d) {
    if (($d['document_category'] ?? 'original') === 'associated') {
        $associated_documents[] = $d;
    } else {
        $original_documents[] = $d;
    }
}
// Existing Official Bidding Documents section displays only original documents
$documents = $original_documents;

$p_status = strtolower($procurement['status'] ?? 'open');
$is_open  = $p_status === 'open';

$diff_days = null; $is_urgent = false; $deadline_text = 'To Be Announced';
if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($is_open && $diff_days <= 3 && $diff_days >= 0) $is_urgent = true;
}

$status_colors = [
    'open'      => ['bg'=>'#e4f5ea','fg'=>'#1f7a3d'],
    'closed'    => ['bg'=>'#e7eefe','fg'=>'#2F6FED'],
    'awarded'   => ['bg'=>'#fcf1cf','fg'=>'#b78103'],
    'cancelled' => ['bg'=>'#ffebee','fg'=>'#c23b3b'],
];
$sc = $status_colors[$p_status] ?? $status_colors['open'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
<link rel="icon" type="image/png" href="images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
* { box-sizing:border-box; }
html { scroll-behavior:smooth; scroll-padding-top:80px; }
body.bview-page {
    background:#f4f8f5; font-family:'Poppins',sans-serif;
    color:#222; overflow-x:hidden; min-height:100vh;
}

/* ── Navbar (see includes/navbar.php) ── */

/* ── Breadcrumb Bar ── */
.bview-breadcrumb-bar {
    background:linear-gradient(135deg,#06251b 0%,#0c3d2c 100%);
    padding:90px 24px 28px; position:relative; overflow:hidden;
}
.bview-breadcrumb-bar::before {
    content:''; position:absolute; inset:0;
    background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);
    background-size:40px 40px; pointer-events:none;
}
.bview-breadcrumb-inner {
    max-width:1240px; margin:0 auto; position:relative; z-index:2;
}
.bview-breadcrumbs {
    display:flex; align-items:center; gap:6px;
    font-size:12px; font-weight:600; color:#9cb3a6; margin-bottom:16px;
}
.bview-breadcrumbs a {
    color:#9cb3a6; text-decoration:none;
    display:inline-flex; align-items:center; gap:4px; transition:color .2s;
}
.bview-breadcrumbs a:hover { color:#ffc107; }

/* ── Hero Card (inside breadcrumb bar) ── */
.bview-hero {
    background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12);
    border-radius:20px; padding:24px 28px;
}
.bview-hero-top {
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; flex-wrap:wrap; margin-bottom:12px;
}
.bview-badges { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.bview-pill {
    font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px;
    display:inline-flex; align-items:center; gap:4px;
}
.bview-pill.ref { background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2); }
.bview-pill.urgent { background:rgba(235,87,87,.25); border:1px solid rgba(235,87,87,.5); color:#ff8a80; }
.bview-hero-title {
    font-size:22px; font-weight:800; color:#fff;
    line-height:1.3; margin-bottom:20px; letter-spacing:-.3px;
}
.bview-metrics {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px;
}
.bview-metric {
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12);
    border-radius:14px; padding:12px 16px;
}
.bview-metric-lbl {
    font-size:10px; font-weight:700; color:#d1e5db;
    text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px;
    display:flex; align-items:center; gap:5px;
}
.bview-metric-lbl i { color:#ffc107; }
.bview-metric-val {
    font-size:16px; font-weight:800; color:#fff;
    font-family:'Space Grotesk',sans-serif;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.bview-metric-val.gold { color:#ffc107; }

/* ── Main Content ── */
.bview-content {
    max-width:1240px; margin:0 auto; padding:36px 24px 80px;
    display:grid; grid-template-columns:1.7fr 1fr; gap:24px; align-items:start;
}
@media(max-width:1040px) { .bview-content { grid-template-columns:1fr; } }

/* ── Cards ── */
.bv-card {
    background:#fff; border:1px solid #eaeeec; border-radius:18px;
    box-shadow:0 1px 2px rgba(16,36,26,.03),0 10px 24px -14px rgba(16,36,26,.08);
    margin-bottom:22px; overflow:hidden;
}
.bv-card-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 20px; border-bottom:1px solid #f0f4f2; background:#fafcfb;
}
.bv-card-title {
    font-size:13.5px; font-weight:800; color:#06251b;
    display:flex; align-items:center; gap:8px;
}
.bv-card-count {
    background:#eef7f1; color:#1f7a3d;
    font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;
}
.bv-card-body { padding:20px; }

/* spec fields */
.spec-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
@media(max-width:600px) { .spec-grid { grid-template-columns:1fr; } }
.spec-item { background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; padding:11px 14px; }
.spec-lbl { font-size:10.5px; font-weight:700; color:#88968d; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
.spec-val { font-size:13px; font-weight:700; color:#1a2a20; line-height:1.3; }

/* lots table */
.lots-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.lots-table th {
    background:#f4f8f5; padding:10px 14px; font-size:11px; font-weight:700;
    color:#495b50; text-transform:uppercase; border-bottom:1px solid #eaeeec; text-align:left;
}
.lots-table td { padding:12px 14px; border-bottom:1px solid #f2f5f3; vertical-align:middle; }
.lots-table tr:last-child td { border-bottom:none; }

/* documents */
.doc-row {
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 14px; background:#fafcfb; border:1px solid #eaeeec;
    border-radius:12px; margin-bottom:10px; gap:12px;
}
.doc-row:last-child { margin-bottom:0; }
.doc-icon {
    width:32px; height:32px; border-radius:8px; background:#eef5f1;
    color:#1f7a3d; display:flex; align-items:center; justify-content:center;
    font-size:14px; flex-shrink:0;
}
.doc-title { font-size:12.5px; font-weight:700; color:#18261e; }
.doc-dl-btn {
    display:inline-flex; align-items:center; gap:5px;
    background:#06251b; color:#ffc107; font-size:11px; font-weight:700;
    padding:6px 12px; border-radius:8px; text-decoration:none;
    transition:all .15s; flex-shrink:0;
}
.doc-dl-btn:hover { background:#0a3a2a; color:#fff; }
.badge-associated-pill {
    display: inline-block;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 6px;
    margin-left: 6px;
    vertical-align: middle;
}

/* timeline */
.tl-list { position:relative; padding-left:24px; }
.tl-list::before {
    content:''; position:absolute; left:7px; top:6px; bottom:6px;
    width:2px; background:#e0e8e4;
}
.tl-step { position:relative; margin-bottom:18px; }
.tl-step:last-child { margin-bottom:0; }
.tl-dot {
    position:absolute; left:-24px; top:2px;
    width:16px; height:16px; border-radius:50%;
    background:#fff; border:3px solid #1f7a3d;
}
.tl-lbl { font-size:12.5px; font-weight:700; color:#1a2a20; margin-bottom:2px; }
.tl-date { font-size:11.5px; color:#728277; }

/* CTA box */
.cta-box {
    background:#f7faf8; border:1.5px dashed #c0d8cb;
    border-radius:14px; padding:18px; text-align:center; margin-bottom:4px;
}
.cta-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    width:100%; background:#06251b; color:#ffc107; font-weight:700;
    font-size:13px; padding:12px 18px; border-radius:10px;
    text-decoration:none; transition:all .15s ease; margin-top:12px;
}
.cta-btn:hover { background:#0a3a2a; color:#fff; transform:translateY(-1px); }

/* Footer */
.bview-footer {
    background:#020c09; color:#6b8077; padding:28px 24px;
    text-align:center; font-size:12px;
    border-top:1px solid rgba(255,255,255,.06);
}
.bview-footer a { color:#9cb3a6; text-decoration:none; }
.bview-footer a:hover { color:#ffc107; }
</style>
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar_css(); ?>
</head>
<body class="bview-page">

<?php render_public_navbar('procurement', $user_logged_in, $user_dashboard_link); ?>

<!-- ── Breadcrumb + Hero ── -->
<div class="bview-breadcrumb-bar">
    <div class="bview-breadcrumb-inner">
        <div class="bview-breadcrumbs">
            <a href="index.php"><i class="bi bi-house-fill"></i> Home</a>
            <span>/</span>
            <a href="bid_schedule.php"><i class="bi bi-calendar3"></i> Bid Schedule</a>
            <span>/</span>
            <span><?= htmlspecialchars($procurement['philgeps_ref_no'] ?: 'Project Details') ?></span>
        </div>

        <div class="bview-hero">
            <div class="bview-hero-top">
                <div class="bview-badges">
                    <span class="bview-pill ref">
                        <i class="bi bi-hash"></i> <?= htmlspecialchars($procurement['philgeps_ref_no'] ?: 'SLSU-BAC') ?>
                    </span>
                    <span class="bview-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                        <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                    </span>
                    <?php if ($is_urgent): ?>
                        <span class="bview-pill urgent">
                            <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span style="font-size:12px; color:#d1e5db;">
                    <i class="bi bi-building"></i> SLSU &mdash; BAC
                </span>
            </div>

            <h1 class="bview-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

            <div class="bview-metrics">
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-cash-stack"></i> Approved Budget (ABC)</div>
                    <div class="bview-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-briefcase"></i> Procurement Mode</div>
                    <div class="bview-metric-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                    <div class="bview-metric-val" style="font-size:13px;"><?= $deadline_text ?></div>
                </div>
                <div class="bview-metric">
                    <div class="bview-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                    <div class="bview-metric-val" style="font-size:13px;">
                        <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ── Two-Column Content ── -->
<div class="bview-content">

    <!-- ── LEFT: Overview, Lots, Documents ── -->
    <div>

        <!-- Project Overview -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-info-circle" style="color:#06251b;"></i> Project Overview
                </div>
            </div>
            <div class="bv-card-body">
                <div class="spec-grid">
                    <div class="spec-item">
                        <div class="spec-lbl">PhilGEPS Ref. No.</div>
                        <div class="spec-val"><?= htmlspecialchars($procurement['philgeps_ref_no'] ?: 'N/A') ?></div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-lbl">Procurement Mode</div>
                        <div class="spec-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                    </div>
                    <div class="spec-item" style="grid-column:1/-1;">
                        <div class="spec-lbl">Project Title</div>
                        <div class="spec-val" style="font-size:14px; color:#06251b;"><?= htmlspecialchars($procurement['title']) ?></div>
                    </div>
                    <div class="spec-item" style="grid-column:1/-1;">
                        <div class="spec-lbl">Approved Budget for the Contract (ABC)</div>
                        <div class="spec-val" style="font-size:17px; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#1f7a3d;">
                            ₱<?= number_format((float)$procurement['abc'], 2) ?>
                        </div>
                    </div>
                </div>

                <div style="font-size:11px; font-weight:700; color:#88968d; text-transform:uppercase; margin-bottom:6px;">Description</div>
                <div style="background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; padding:16px; font-size:13px; color:#2d3a32; line-height:1.6; white-space:pre-wrap;">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed description specified for this procurement. Please consult the official bidding documents.') ?>
                </div>
            </div>
        </div>

        <!-- Lots -->
        <?php if (!empty($lots)): ?>
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-boxes" style="color:#1f7a3d;"></i> Project Lots &amp; Components
                </div>
                <span class="bv-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                    <table class="lots-table">
                        <thead>
                            <tr>
                                <th style="width:70px;">Lot #</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th style="text-align:right;">ABC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lots as $lot): ?>
                            <tr>
                                <td style="font-weight:700; color:#06251b;">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                                <td style="font-weight:600; color:#1a2a20;"><?= htmlspecialchars($lot['lot_title'] ?? $lot['title'] ?? '—') ?></td>
                                <td style="color:#63736a; font-size:12px;"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                                <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                    ₱<?= number_format((float)$lot['abc'], 2) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (count($lots) > 1): ?>
                        <tfoot>
                            <tr style="background:#f9fbf9; border-top:2px solid #eaeeec; font-weight:700;">
                                <td colspan="3" style="text-align:right; font-size:12px; color:#495b50; padding:12px 14px;">Total ABC:</td>
                                <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b; padding:12px 14px;">
                                    ₱<?= number_format($total_lots_abc, 2) ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documents (Original Only) -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-file-earmark-arrow-down" style="color:#1565c0;"></i> Official Bidding Documents
                </div>
                <span class="bv-card-count"><?= count($original_documents) ?> File<?= count($original_documents) != 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body">
                <?php if (!empty($original_documents)): ?>
                    <?php foreach ($original_documents as $doc): 
                        $dl_url = resolve_proc_doc_url($doc['file_path'], 'root');
                    ?>
                    <div class="doc-row">
                        <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                            <div class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                            <div style="min-width:0;">
                                <div class="doc-title" title="<?= htmlspecialchars($doc['document_name']) ?>"><?= htmlspecialchars($doc['document_name']) ?></div>
                                <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($dl_url) ?>" download class="doc-dl-btn">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                        <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                        No original bidding documents uploaded yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Associated Documents (Associated Only) -->
        <div class="bv-card" style="margin-top:20px;">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-paperclip" style="color:#0284c7;"></i> Associated Documents
                </div>
                <span class="bv-card-count"><?= count($associated_documents) ?> File<?= count($associated_documents) != 1 ? 's' : '' ?></span>
            </div>
            <div class="bv-card-body">
                <?php if (!empty($associated_documents)): ?>
                    <?php foreach ($associated_documents as $adoc): 
                        $adl_url = resolve_proc_doc_url($adoc['file_path'], 'root');
                        $aext    = strtolower(pathinfo($adoc['document_name'], PATHINFO_EXTENSION));
                        $aicon   = 'bi-file-earmark-pdf';
                        if (in_array($aext, ['doc','docx'])) $aicon = 'bi-file-earmark-word';
                        elseif (in_array($aext, ['xls','xlsx'])) $aicon = 'bi-file-earmark-excel';
                        elseif (in_array($aext, ['zip','rar','7z'])) $aicon = 'bi-file-earmark-zip';
                        elseif (in_array($aext, ['jpg','jpeg','png'])) $aicon = 'bi-file-earmark-image';
                    ?>
                    <div class="doc-row">
                        <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                            <div class="doc-icon" style="background:#e0f2fe; color:#0284c7;"><i class="bi <?= $aicon ?>"></i></div>
                            <div style="min-width:0;">
                                <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                    <?= htmlspecialchars($adoc['document_name']) ?>
                                    <span class="badge-associated-pill">Associated</span>
                                </div>
                                <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn" style="background:#0284c7; color:#ffffff;">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                        <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                        No associated documents (bulletins, addenda, notices) published yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── RIGHT: CTA + Schedule ── -->
    <div>

        <!-- Participation CTA -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-shield-check" style="color:#06251b;"></i> Supplier Participation
                </div>
            </div>
            <div class="bv-card-body">
                <?php if ($user_logged_in): ?>
                    <div class="cta-box" style="background:#f2faf4; border-color:#b7e3c4;">
                        <i class="bi bi-person-check-fill" style="font-size:28px; color:#219653; display:block; margin-bottom:6px;"></i>
                        <div style="font-size:13.5px; font-weight:800; color:#06251b; margin-bottom:4px;">You're Logged In</div>
                        <p style="font-size:12px; color:#55665a; margin-bottom:0;">Access your bidder portal to view submission requirements and eligibility details.</p>
                        <a href="<?= htmlspecialchars($user_dashboard_link) ?>" class="cta-btn">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <div class="cta-box">
                        <i class="bi bi-person-badge" style="font-size:28px; color:#06251b; display:block; margin-bottom:6px;"></i>
                        <div style="font-size:13.5px; font-weight:800; color:#06251b; margin-bottom:4px;">Interested in Bidding?</div>
                        <p style="font-size:12px; color:#55665a; margin-bottom:0;">
                            Register as an accredited supplier to participate in electronic bidding and submit proposals.
                        </p>
                        <a href="register.php" class="cta-btn">
                            <i class="bi bi-person-plus"></i> Register as Bidder
                        </a>
                        <a href="login.php" style="display:block; margin-top:10px; font-size:12px; color:#1f7a3d; font-weight:700; text-align:center; text-decoration:none;">
                            Already registered? <u>Login here</u>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bidding Schedule -->
        <div class="bv-card">
            <div class="bv-card-head">
                <div class="bv-card-title">
                    <i class="bi bi-calendar-check" style="color:#1f7a3d;"></i> Schedule of Activities
                </div>
            </div>
            <div class="bv-card-body">
                <div class="tl-list">
                    <?php if (!empty($procurement['posting_date'])): ?>
                    <div class="tl-step">
                        <div class="tl-dot"></div>
                        <div class="tl-lbl">Advertisement / Posting</div>
                        <div class="tl-date"><?= date('F j, Y', strtotime($procurement['posting_date'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($procurement['pre_bid_date'])): ?>
                    <div class="tl-step">
                        <div class="tl-dot"></div>
                        <div class="tl-lbl">Pre-Bid Conference</div>
                        <div class="tl-date"><?= date('F j, Y · g:i A', strtotime($procurement['pre_bid_date'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="tl-step">
                        <div class="tl-dot" style="border-color:#e67e22;"></div>
                        <div class="tl-lbl" style="color:#e67e22;">Deadline for Submission</div>
                        <div class="tl-date"><?= $deadline_text ?></div>
                    </div>

                    <div class="tl-step">
                        <div class="tl-dot" style="border-color:#1565c0;"></div>
                        <div class="tl-lbl">Bid Opening</div>
                        <div class="tl-date">
                            <?= !empty($procurement['opening_date']) ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back link -->
        <a href="bid_schedule.php" style="
            display:flex; align-items:center; justify-content:center; gap:8px;
            background:#fff; border:1.5px solid #e2ece6; color:#06251b;
            font-size:12.5px; font-weight:700; padding:11px 18px; border-radius:12px;
            text-decoration:none; transition:all .15s ease;">
            <i class="bi bi-arrow-left"></i> Back to Bid Schedule
        </a>

    </div>
</div>

<!-- ── Footer ── -->
<footer class="bview-footer">
    <p>&copy; <?= date('Y') ?> YesParency &mdash; SLSU Procurement Transparency System &mdash;
       <a href="index.php">Home</a> &bull; <a href="bid_schedule.php">Bid Schedule</a> &bull; <a href="login.php">Login</a>
    </p>
</footer>

</body>
</html>
