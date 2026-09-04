<?php
include("utils/protect-page.php");

if (!isset($_SESSION['admin_type']) && isset($conn)) {
    $at = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $at->bind_param("i", $_SESSION['user_id']);
    $at->execute();
    $at_row = $at->get_result()->fetch_assoc();
    $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
    $at->close();
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$user_role  = $_SESSION['role'] ?? 'admin';
$can_manage = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

$session_id = (int)($_GET['session'] ?? 0);
if ($session_id === 0) { header("Location: bid_opening.php"); exit(); }

$ss = $conn->prepare("
    SELECT bos.id AS session_id, bos.status AS session_status,
           bos.stream_path, bos.title AS session_title, bos.started_at, bos.ended_at,
           bos.current_lot_id,
           p.id AS proc_id, p.title AS proc_title, p.philgeps_ref_no,
           p.abc, p.procurement_mode, p.opening_date, p.closing_date
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.id = ? LIMIT 1
");
$ss->bind_param("i", $session_id);
$ss->execute();
$session = $ss->get_result()->fetch_assoc();
$ss->close();
if (!$session) { header("Location: bid_opening.php"); exit(); }

// ── Invited-only access guard ──────────────────────────────────────────────
// Superadmin and SECRETARIAT always have access.
// BAC and TWG must be in bid_session_invited for this session.
if ($user_role !== 'superadmin' && $admin_type !== 'SECRETARIAT') {
    $inv_chk = $conn->prepare("
        SELECT 1 FROM bid_session_invited
        WHERE bid_session_id = ? AND user_id = ?
        LIMIT 1
    ");
    $inv_chk->bind_param("ii", $session_id, $_SESSION['user_id']);
    $inv_chk->execute();
    $is_invited = (bool)$inv_chk->get_result()->fetch_row();
    $inv_chk->close();

    if (!$is_invited) {
        header("Location: bid_opening.php?error=not_invited"); exit();
    }
}

$proc_id        = (int)$session['proc_id'];
$st             = $session['session_status'];
$current_lot_id = (int)($session['current_lot_id'] ?? 0);

$lots_stmt = $conn->prepare("
    SELECT
        l.id, l.lot_number, l.lot_title, l.abc,
        COUNT(bl.bid_id) AS total_bids,
        COUNT(CASE WHEN bl.eligibility_status IN ('pending','opened') THEN 1 END) AS pending_elig,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' THEN 1 END) AS eligible_bids,
        COUNT(CASE WHEN bl.eligibility_status = 'disqualified' THEN 1 END) AS disq_elig,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' AND bl.financial_status IN ('pending','opened') THEN 1 END) AS pending_fin,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' AND bl.financial_status IN ('qualified','non_compliant') THEN 1 END) AS done_fin,
        (SELECT COUNT(*) FROM awards a WHERE a.lot_id = l.id) AS is_awarded
    FROM lots l
    LEFT JOIN bid_lots bl ON bl.lot_id = l.id
    WHERE l.procurement_id = ?
    GROUP BY l.id, l.lot_number, l.lot_title, l.abc
    ORDER BY l.lot_number ASC
");
$lots_stmt->bind_param("i", $proc_id);
$lots_stmt->execute();
$lots = $lots_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lots_stmt->close();

$all_lots_done = !empty($lots);
foreach ($lots as &$l) {
    $total  = (int)$l['total_bids'];
    $p_elig = (int)$l['pending_elig'];
    $p_fin  = (int)$l['pending_fin'];
    $l['is_done'] = ($total === 0 || ($p_elig === 0 && $p_fin === 0) || (int)$l['is_awarded'] > 0);
    if (!$l['is_done']) {
        $all_lots_done = false;
    }
}
unset($l);

// Active lot from database
$active_lot_db = null;
if ($current_lot_id > 0) {
    foreach ($lots as $l) {
        if ((int)$l['id'] === $current_lot_id) { $active_lot_db = $l; break; }
    }
}
if (!$active_lot_db && !empty($lots)) {
    foreach ($lots as $l) {
        if (!$l['is_done']) { $active_lot_db = $l; break; }
    }
    if (!$active_lot_db) $active_lot_db = $lots[0];
}

// Initial activity text directly from database
$initial_activity_text = 'Session is scheduled — waiting to begin.';
if ($st === 'scheduled') {
    $initial_activity_text = 'Session is scheduled — waiting for Secretariat to start.';
} elseif ($st === 'started' || $st === 'eligibility') {
    $lot_txt = $active_lot_db ? ('Lot ' . $active_lot_db['lot_number']) : 'Lot 1';
    $initial_activity_text = "Session in progress · Reviewing {$lot_txt} (Eligibility & Technical Phase)";
} elseif ($st === 'financial') {
    $lot_txt = $active_lot_db ? ('Lot ' . $active_lot_db['lot_number']) : 'Lot 1';
    $initial_activity_text = "Session in progress · Reviewing {$lot_txt} (Financial Phase)";
} elseif ($st === 'awarding') {
    $initial_activity_text = "Awarding phase in progress · Selecting winning bidders per lot.";
} elseif ($st === 'ended') {
    $initial_activity_text = "Bid Opening Session officially concluded and archived.";
}

$inv_res = $conn->prepare("
    SELECT u.user_id, u.firstname, u.lastname, u.profile_picture_url, ar.admin_type
    FROM bid_session_invited bsi
    JOIN users u ON bsi.user_id = u.user_id
    LEFT JOIN admin_roles ar ON ar.user_id = u.user_id
    WHERE bsi.bid_session_id = ?
    ORDER BY ar.admin_type ASC, u.firstname ASC
");
$inv_res->bind_param("i", $session_id);
$inv_res->execute();
$invited = $inv_res->get_result()->fetch_all(MYSQLI_ASSOC);
$inv_res->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bid Session — <?= htmlspecialchars($session['proc_title']) ?> | YesParency</title>
<link rel="icon" type="image/png" href="images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="../style.css">
<link rel="stylesheet" href="../dashboard.css">
<style>
.bs-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}
@media(min-width:1100px){.bs-layout{grid-template-columns:minmax(0,1fr) 310px}}

/* nav */
.vp-nav-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.vp-breadcrumbs{display:flex;align-items:center;gap:6px;font-size:12px;color:#88968d;font-weight:600}
.vp-breadcrumbs a{color:#1f7a3d;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.vp-breadcrumbs a:hover{text-decoration:underline;color:#06251b}
.vp-back-link{display:inline-flex;align-items:center;gap:6px;color:#06251b;font-size:12.5px;font-weight:700;background:#fff;border:1px solid #eaeeec;padding:7px 14px;border-radius:10px;text-decoration:none;transition:all .2s}
.vp-back-link:hover{background:#06251b;color:#ffc107;border-color:#06251b}

/* proc hero */
.proc-hero{background:linear-gradient(135deg,#06251b 0%,#0c3d2c 60%,#14593f 100%);border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden;margin-bottom:18px;border:1px solid rgba(255,255,255,.08)}
.proc-hero::after{content:'';position:absolute;top:-40px;right:-40px;width:180px;height:180px;background:radial-gradient(circle,rgba(255,193,7,.14) 0%,transparent 70%);border-radius:50%;pointer-events:none}
.proc-badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.ipill{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:4px}
.ipill.ref{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)}
.ipill.mode{background:rgba(255,193,7,.2);color:#ffc107;border:1px solid rgba(255,193,7,.35)}
.ipill.live{background:rgba(220,38,38,.25);color:#f87171;border:1px solid rgba(220,38,38,.4)}
.ipill.sched{background:rgba(217,119,6,.2);color:#fbbf24;border:1px solid rgba(217,119,6,.35)}
.status-pill{font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;border:1px solid transparent}
.status-pill.sp-active{background:#e0f2f1;color:#00796b;border-color:#b2dfdb}
.status-pill.sp-failed{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.activity-bar{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:9px 13px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:12px;color:#e2ece4}
.activity-bar i{color:#ffc107;flex-shrink:0}
.proc-title{font-size:18px;font-weight:800;color:#fff;line-height:1.3;margin-bottom:14px}
.proc-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
@media(min-width:640px){.proc-metrics{grid-template-columns:repeat(4,1fr)}}
.met{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 12px}
.met-lbl{font-size:9.5px;font-weight:700;color:#d1e5db;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;display:flex;align-items:center;gap:4px}
.met-lbl i{color:#ffc107}
.met-val{font-size:14px;font-weight:800;color:#fff;font-family:'Space Grotesk',sans-serif}
.met-val.gold{color:#ffc107}

/* MASTER TAB BAR (Lots -> Awarding) */
.master-tabs-wrap{background:#fff;border:1px solid #eaeeec;border-radius:14px;padding:8px 10px;margin-bottom:18px;box-shadow:0 1px 3px rgba(16,36,26,.03);display:flex;align-items:center;gap:8px;overflow-x:auto}
.master-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:10px;font-size:12.5px;font-weight:700;color:#55665a;background:#f8faf9;border:1.5px solid #eaeeec;cursor:pointer;transition:all .18s;white-space:nowrap;user-select:none}
.master-tab:hover{background:#f0f5f2;color:#06251b;border-color:#d0dcd5}
.master-tab.active{background:#06251b;color:#fff;border-color:#06251b;box-shadow:0 3px 10px rgba(6,37,27,.18)}
.master-tab.active .mtab-num{background:#ffc107;color:#06251b}
.master-tab.done{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.master-tab.done .mtab-num{background:#16a34a;color:#fff}
.master-tab.locked{opacity:.5;cursor:not-allowed}
.master-tab.locked:hover{background:#f8faf9;color:#55665a;border-color:#eaeeec}
.mtab-num{width:22px;height:22px;border-radius:50%;background:#e5e7eb;color:#4b5563;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mtab-badge{font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;display:inline-flex;align-items:center;gap:3px}
.mtab-badge.done{background:#dcfce7;color:#15803d}
.mtab-badge.live{background:#fee2e2;color:#dc2626;animation:pulse 1.8s infinite}

/* LOT SESSION PANEL */
.lot-panel{background:#fff;border:1px solid #eaeeec;border-radius:16px;overflow:hidden;margin-bottom:18px;box-shadow:0 1px 2px rgba(16,36,26,.03),0 8px 20px -12px rgba(16,36,26,.08);min-width:0}
.lot-head{padding:16px 20px;background:#fafcfb;border-bottom:1px solid #f0f4f2;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.lot-head-title{font-size:15px;font-weight:800;color:#06251b;display:flex;align-items:center;gap:8px}
.lot-head-abc{font-size:12px;color:#55665a;font-weight:600}
.lot-head-abc strong{color:#1f7a3d;font-family:'Space Grotesk',sans-serif}

/* STAGE BAR (Stage 1: Eligibility -> Stage 2: Financial) */
.stage-bar{display:flex;align-items:center;background:#f4f7f5;border-bottom:1px solid #eaeeec;padding:8px 16px;gap:8px;overflow-x:auto}
.stage-step{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;color:#6b7280;background:transparent;cursor:pointer;transition:all .15s;border:none}
.stage-step.active{background:#fff;color:#06251b;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.stage-step.done{color:#15803d}
.stage-sep{color:#cbd5e1;font-size:12px}

/* BIDDER TABS */
.bidder-tabs{display:flex;gap:6px;padding:12px 16px;overflow-x:auto;border-bottom:1px solid #f0f4f2;flex-wrap:wrap;background:#fafcfb}
.bidder-tab{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:20px;font-size:11.5px;font-weight:700;color:#55665a;background:#f0f4f2;border:1.5px solid transparent;user-select:none;cursor:pointer;transition:all .15s}
.bidder-tab:hover:not(.disqualified){background:#e6ebe8}
.bidder-tab.active{background:#e4f5ea;color:#1f7a3d;border-color:#1f7a3d;opacity:1}
.bidder-tab.done{background:#e0f2f1;color:#00796b;border-color:#b2dfdb;opacity:1}
.bidder-tab.disqualified{background:#f3f4f6!important;color:#9ca3af!important;border-color:#e5e7eb!important;opacity:.65!important;cursor:not-allowed!important}
.bidder-tab.disqualified .bt-avatar{background:#d1d5db!important;color:#6b7280!important}
.disq-badge{font-size:9.5px;font-weight:700;color:#ef4444;background:#fee2e2;padding:1px 6px;border-radius:10px;margin-left:5px;display:inline-flex;align-items:center;gap:3px}
.bt-avatar{width:22px;height:22px;border-radius:50%;background:#06251b;color:#ffc107;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.bt-avatar img{width:100%;height:100%;object-fit:cover}

/* ACTION BAR ABOVE FILES */
.action-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #f0f4f2;background:#fafcfb;gap:10px;flex-wrap:wrap;min-width:0}
.action-bar-name{font-size:12.5px;font-weight:700;color:#06251b;display:flex;align-items:center;gap:7px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%}
.action-bar-btns{display:flex;align-items:center;gap:8px;flex-shrink:0}
.btn-open-files{display:inline-flex;align-items:center;gap:6px;background:#06251b;color:#ffc107;font-size:12px;font-weight:700;padding:7px 16px;border-radius:9px;border:none;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .15s}
.btn-open-files:hover:not(:disabled){background:#0c3d2c}
.btn-open-files:disabled{opacity:.4;cursor:not-allowed}
.btn-checklist{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:7px 16px;border-radius:9px;border:none;cursor:not-allowed;font-family:'Poppins',sans-serif;background:#f0f4f2;color:#88968d;transition:all .15s}
.btn-checklist.ready{background:#e4f5ea;color:#1f7a3d;cursor:pointer}
.btn-checklist.ready:hover{background:#1f7a3d;color:#fff}

/* FILE ROWS */
.files-pane{padding:0;overflow:hidden;min-width:0;max-width:100%}
.file-row{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid #f7faf8;transition:background .12s;min-width:0;max-width:100%;overflow:hidden;box-sizing:border-box}
.file-row:last-child{border-bottom:none}
.file-row:hover{background:#fbfdfc}
.f-icon{width:32px;height:32px;border-radius:8px;background:#f0f4f2;color:#55665a;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.f-info{flex:1 1 0%;min-width:0;overflow:hidden;display:flex;flex-direction:column}
.f-name{font-size:12.5px;font-weight:700;color:#06251b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%}
.f-meta{font-size:10.5px;color:#88968d;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%}
.btn-view{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:5px 12px;border-radius:7px;border:none;font-family:'Poppins',sans-serif;transition:all .15s;background:#f0f4f2;color:#88968d;cursor:not-allowed;opacity:.5;flex-shrink:0;white-space:nowrap}
.btn-view.active{background:#06251b;color:#ffc107;cursor:pointer;opacity:1}
.btn-view.active:hover{background:#0c3d2c}

/* LOT FOOTER BAR (Done opening Lot #[N]) */
.lot-footer-bar{padding:14px 20px;background:#fafcfb;border-top:1px solid #f0f4f2;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.lot-footer-info{font-size:12px;color:#55665a;display:flex;align-items:center;gap:8px}
.btn-confirm-lot{display:inline-flex;align-items:center;gap:7px;background:#1f7a3d;color:#fff;font-size:13px;font-weight:800;padding:9px 22px;border-radius:10px;border:none;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .15s;box-shadow:0 2px 8px rgba(31,122,61,.25)}
.btn-confirm-lot:hover{background:#176030;transform:translateY(-1px)}

/* EMPTY STATE */
.p-empty{padding:36px;text-align:center;color:#88968d;font-size:12.5px}
.p-empty i{font-size:32px;color:#c7d2cb;display:block;margin-bottom:8px}

/* AWARDING VIEW */
.award-panel{background:#fff;border:1px solid #eaeeec;border-radius:16px;overflow:hidden;margin-bottom:18px;box-shadow:0 1px 2px rgba(16,36,26,.03),0 8px 20px -12px rgba(16,36,26,.08)}
.award-head{padding:16px 20px;background:#fafcfb;border-bottom:1px solid #f0f4f2;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.award-lot-card{border:1px solid #eaeeec;border-radius:14px;overflow:hidden;margin:16px 20px}
.award-lot-head{padding:12px 18px;background:#f8faf9;border-bottom:1px solid #eaeeec;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.award-lot-title{font-size:13.5px;font-weight:800;color:#06251b}
.award-bidders-list{padding:14px 18px;display:flex;flex-direction:column;gap:10px}
.award-bidder-card{border:1.5px solid #eaeeec;border-radius:12px;padding:12px 16px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;transition:all .15s}
.award-bidder-card:hover:not(.disqualified){border-color:#b2dfdb;background:#fbfdfc}
.award-bidder-card.disqualified{opacity:.55;background:#fafafa;border-color:#eee;cursor:not-allowed}
.award-bidder-info{display:flex;align-items:center;gap:10px}
.award-bidder-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.award-amount-wrap{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#06251b}
.award-amount-input{width:140px;padding:6px 10px;border:1.5px solid #e0e8e4;border-radius:8px;font-size:12px;font-family:'Space Grotesk',sans-serif;font-weight:700;outline:none}
.award-amount-input:focus{border-color:#00796b;box-shadow:0 0 0 2px rgba(0,121,107,.1)}
.btn-select-winner{background:#00796b;color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn-select-winner:hover{background:#004d40}
.btn-fail-lot{background:#fff;color:#dc2626;border:1.5px solid #fecaca;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn-fail-lot:hover{background:#fef2f2;border-color:#f87171}
.award-winner-badge{background:#e0f2f1;color:#00796b;border:1px solid #b2dfdb;font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:5px}
.awarded-box{background:#f0fdfa;border:1.5px solid #b2dfdb;border-radius:12px;padding:14px 18px;margin:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.awarded-box-left{display:flex;align-items:center;gap:12px}
.awarded-box-icon{width:38px;height:38px;border-radius:10px;background:#00796b;color:#ffc107;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.awarded-box-winner{font-size:13px;font-weight:800;color:#06251b}
.awarded-box-amount{font-size:11.5px;color:#00796b;font-weight:700;font-family:'Space Grotesk',sans-serif;margin-top:2px}
.failed-box{background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:14px 18px;margin:12px 18px;display:flex;align-items:center;gap:12px}
.failed-box-icon{width:38px;height:38px;border-radius:10px;background:#dc2626;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.failed-box-label{font-size:13px;font-weight:800;color:#991b1b}
.failed-box-sub{font-size:11.5px;color:#ef4444;font-weight:600;margin-top:2px}
.btn-end-session{display:inline-flex;align-items:center;gap:7px;background:#06251b;color:#ffc107;font-size:13px;font-weight:800;padding:9px 20px;border-radius:10px;border:none;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .15s}
.btn-end-session:hover{background:#0c3d2c}

/* SIDEBAR */
.sb-panel{background:#fff;border:1px solid #eaeeec;border-radius:16px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 2px rgba(16,36,26,.03),0 8px 20px -12px rgba(16,36,26,.08)}
.sb-head{padding:13px 16px;background:#fafcfb;border-bottom:1px solid #f0f4f2;font-size:13px;font-weight:800;color:#06251b;display:flex;align-items:center;gap:8px}
.sb-body{padding:16px}
.chat-ph{height:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#c7d2cb;font-size:12.5px}
.chat-ph i{font-size:32px}

/* MILESTONE */
.ms-list{display:flex;flex-direction:column;gap:0}
.ms-item{display:flex;align-items:flex-start;gap:12px;padding:8px 0;position:relative;min-height:44px}
.ms-connector{width:2px;background:#f0f4f2;flex-shrink:0;margin-left:12px;min-height:16px;align-self:stretch}
.ms-dot{width:26px;height:26px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px}
.ms-done{background:#e4f5ea;color:#1f7a3d}
.ms-active{background:#06251b;color:#ffc107}
.ms-pending{background:#f3f4f6;color:#9ca3af}
.ms-text{flex:1;padding-top:3px}
.ms-label{font-size:12.5px;font-weight:700;color:#06251b}
.ms-desc{font-size:11px;color:#88968d;margin-top:1px}

/* SKELETON */
.skel{background:linear-gradient(90deg,#f0f4f2 25%,#e4eae6 50%,#f0f4f2 75%);background-size:200% 100%;animation:sk 1.4s infinite;border-radius:6px}
@keyframes sk{0%{background-position:200% 0}100%{background-position:-200% 0}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

/* MODALS */
.bsm-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1050;display:none;align-items:center;justify-content:center;padding:16px}
.bsm-bg.open{display:flex}
.bsm{background:#fff;border-radius:20px;width:100%;max-width:440px;box-shadow:0 16px 40px rgba(0,0,0,.18);animation:popIn .2s cubic-bezier(.34,1.56,.64,1);overflow:hidden}
@keyframes popIn{from{transform:scale(.94);opacity:0}to{transform:scale(1);opacity:1}}
.bsm-head{padding:16px 20px;background:#fafcfb;border-bottom:1px solid #f0f4f2;display:flex;align-items:center;justify-content:space-between}
.bsm-head h4{font-size:14px;font-weight:800;color:#06251b;margin:0;display:flex;align-items:center;gap:8px}
.bsm-x{background:none;border:none;color:#88968d;font-size:16px;cursor:pointer;padding:4px;border-radius:6px}
.bsm-x:hover{background:#eef2f0;color:#06251b}
.bsm-body{padding:20px}
.bsm-foot{padding:14px 20px;background:#fafcfb;border-top:1px solid #f0f4f2;display:flex;justify-content:flex-end;gap:10px}
.bsm-input{width:100%;padding:10px 12px;border:1.5px solid #e0e8e4;border-radius:9px;font-size:13px;font-family:'Poppins',sans-serif;color:#1a1a1a;outline:none;transition:border-color .15s;box-sizing:border-box}
.bsm-input:focus{border-color:#1f7a3d;box-shadow:0 0 0 3px rgba(31,122,61,.08)}
.bsm-err{font-size:12px;color:#dc2626;margin-top:6px;display:none}
.btn-cancel{background:#f0f4f2;color:#06251b;border:none;padding:8px 18px;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif}
.btn-confirm{background:#06251b;color:#ffc107;border:none;padding:8px 18px;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif}
.btn-eligible{background:#e4f5ea;color:#1f7a3d;border:none;padding:8px 18px;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;flex:1}
.btn-ineligible{background:#fee2e2;color:#dc2626;border:none;padding:8px 18px;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;flex:1}
.btn-eligible:hover{background:#1f7a3d;color:#fff}
.btn-ineligible:hover{background:#dc2626;color:#fff}

/* FILE VIEWER */
.fv-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:2000;display:none;flex-direction:column}
.fv-overlay.open{display:flex}
.fv-bar{padding:12px 20px;background:#06251b;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;flex-shrink:0}
.fv-title{font-size:13px;font-weight:700;color:#fff}
.fv-close{background:rgba(255,255,255,.1);border:none;color:#fff;font-size:13px;padding:7px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;font-weight:700}
.fv-close:hover{background:rgba(255,255,255,.2)}
.fv-content{flex:1;display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto}
.fv-content iframe{width:100%;height:100%;border:none;border-radius:8px}
.fv-content img{max-width:100%;max-height:100%;border-radius:8px;object-fit:contain}
</style>
</head>
<body class="dash-body">
<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Bid Session'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

<div class="vp-nav-bar">
    <div class="vp-breadcrumbs">
        <a href="bid_opening.php"><i class="bi bi-envelope-open-fill"></i> Bid Opening</a>
        <span>/</span>
        <span style="color:#06251b"><?= htmlspecialchars(mb_strimwidth($session['proc_title'],0,40,'…')) ?></span>
    </div>
    <a href="bid_opening.php" class="vp-back-link"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="bs-layout">

<!-- ════ LEFT ════ -->
<div>

<!-- Procurement hero -->
<div class="proc-hero">
    <div class="proc-badges">
        <span class="ipill ref"><i class="bi bi-hash"></i><?= htmlspecialchars($session['philgeps_ref_no']) ?></span>
        <?php if ($session['procurement_mode']): ?>
        <span class="ipill mode"><i class="bi bi-tag"></i><?= htmlspecialchars($session['procurement_mode']) ?></span>
        <?php endif; ?>
        <span class="ipill <?= in_array($st,['eligibility','financial','awarding','started']) ? 'live' : 'sched' ?>" id="heroStatusPill">
            <i class="bi bi-<?= in_array($st,['eligibility','financial','awarding','started']) ? 'broadcast' : 'clock' ?>"></i>
            <?= ucfirst($st) ?>
        </span>
    </div>
    <div class="activity-bar">
        <i class="bi bi-activity"></i>
        <span id="activityText"><?= htmlspecialchars($initial_activity_text) ?></span>
    </div>
    <div class="proc-title"><?= htmlspecialchars($session['proc_title']) ?></div>
    <div class="proc-metrics">
        <div class="met"><div class="met-lbl"><i class="bi bi-currency-dollar"></i> ABC</div><div class="met-val gold">₱<?= number_format((float)$session['abc'],2) ?></div></div>
        <div class="met"><div class="met-lbl"><i class="bi bi-layers"></i> Lots</div><div class="met-val"><?= count($lots) ?></div></div>
        <div class="met"><div class="met-lbl"><i class="bi bi-people"></i> Invited</div><div class="met-val"><?= count($invited) ?></div></div>
        <div class="met"><div class="met-lbl"><i class="bi bi-clock"></i> Started</div><div class="met-val" style="font-size:12px"><?= $session['started_at'] ? date('g:i A',strtotime($session['started_at'])) : '—' ?></div></div>
    </div>
    <?php if ($can_manage && $st === 'scheduled'): ?>
    <div style="margin-top:14px;display:flex;justify-content:flex-end" id="heroStartWrap">
        <button class="btn-confirm" style="background:#ffc107;color:#06251b;font-weight:800;padding:9px 20px;border-radius:10px;border:none;cursor:pointer" id="btn-start-session" onclick="openStartSessionModal()">
            <i class="bi bi-play-circle-fill"></i> Start Session
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- MASTER TABS: Lot 1 -> Lot 2 -> ... -> Awarding -->
<div class="master-tabs-wrap" id="masterTabs">
    <?php foreach ($lots as $i => $lot): ?>
    <div class="master-tab <?= $lot['is_done'] ? 'done' : ($i===0 ? 'active' : 'locked') ?>" id="mtab-<?= $i ?>" onclick="onMasterTabClick(<?= $i ?>)">
        <span class="mtab-num"><?= $lot['lot_number'] ?></span>
        <span>Lot <?= $lot['lot_number'] ?></span>
        <span class="mtab-badge <?= $lot['is_done'] ? 'done' : '' ?>" id="mtab-badge-<?= $i ?>"><?= $lot['is_done'] ? '<i class="bi bi-check2"></i> Done' : '' ?></span>
    </div>
    <?php endforeach; ?>
    <div class="master-tab <?= (empty($lots) || $all_lots_done || in_array($st,['awarding','ended'])) ? 'active' : 'locked' ?>" id="mtab-awarding" onclick="onMasterTabClick('awarding')">
        <i class="bi bi-trophy-fill" style="color:#ffc107"></i>
        <span>Awarding</span>
        <span class="mtab-badge" id="mtab-badge-awarding"></span>
    </div>
    <div class="master-tab <?= $st === 'ended' ? '' : 'locked' ?>" id="mtab-conclusion" onclick="onMasterTabClick('conclusion')">
        <i class="bi bi-flag-fill" style="color:#1f7a3d"></i>
        <span>Conclusion</span>
        <span class="mtab-badge" id="mtab-badge-conclusion"></span>
    </div>
</div>

<!-- ══ LOT OPENING VIEW (Active for Lot 0..N) ══ -->
<div id="lot-session-container">
    <div class="lot-panel">
        <div class="lot-head">
            <div>
                <div class="lot-head-title" id="lotHeadTitle">
                    <i class="bi bi-layers-fill" style="color:#1f7a3d"></i>
                    <span>Lot 1</span>
                </div>
                <div class="lot-head-abc" id="lotHeadAbc">Approved Budget for Contract: <strong>₱0.00</strong></div>
            </div>
            <div id="lotStagePills" style="display:flex;align-items:center;gap:6px">
                <span class="ipill live" id="lotCurrentStagePill"><i class="bi bi-shield-check"></i> Eligibility Stage</span>
            </div>
        </div>

        <!-- Stage Switcher / Status -->
        <div class="stage-bar">
            <button class="stage-step active" id="stageBtn-eligibility" <?= $can_manage ? 'onclick="setLotStage(\'eligibility\')"' : '' ?>>
                <i class="bi bi-shield-check"></i> Stage 1: Eligibility &amp; Technical
            </button>
            <i class="bi bi-arrow-right stage-sep"></i>
            <button class="stage-step" id="stageBtn-financial" <?= $can_manage ? 'onclick="setLotStage(\'financial\')"' : '' ?>>
                <i class="bi bi-cash-stack"></i> Stage 2: Financial Proposal
            </button>
        </div>

        <!-- Start Eligibility Phase gate — shown only when status is 'started' (not yet in a phase) -->
        <?php if ($can_manage): ?>
        <div id="startEligibilityGate" style="display:none;padding:32px 24px;text-align:center;border-bottom:1px solid #eaeeec">
            <div style="width:52px;height:52px;border-radius:14px;background:#e4f5ea;color:#1f7a3d;display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 14px">
                <i class="bi bi-shield-check"></i>
            </div>
            <div style="font-size:15px;font-weight:800;color:#06251b;margin-bottom:6px">Ready to begin Eligibility Review?</div>
            <div style="font-size:12.5px;color:#55665a;margin-bottom:20px;max-width:380px;margin-left:auto;margin-right:auto">
                Click the button below to officially start the Eligibility &amp; Technical evaluation phase. This will be recorded in the system.
            </div>
            <button id="btnStartEligibility" onclick="openStartEligibilityModal()"
                style="background:#1f7a3d;color:#fff;border:none;border-radius:10px;padding:10px 24px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px">
                <i class="bi bi-play-circle-fill"></i> Begin Eligibility &amp; Technical Phase
            </button>
        </div>
        <?php else: ?>
        <div id="startEligibilityGate" style="display:none;padding:20px;border-bottom:1px solid #eaeeec">
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:14px">
                <div style="width:40px;height:40px;border-radius:12px;background:#f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div style="font-size:13.5px;font-weight:800;color:#92400e">Waiting for Eligibility Phase to Begin</div>
                    <div style="font-size:12px;color:#b45309;margin-top:2px">The Secretariat will officially open the Eligibility &amp; Technical review. Please stand by.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bidder Area (Tabs, Action Bar, Files Pane) -->
        <div id="lot-bidder-area">
            <div style="padding:24px;display:flex;gap:8px">
                <div class="skel" style="height:32px;width:120px;border-radius:20px"></div>
                <div class="skel" style="height:32px;width:120px;border-radius:20px"></div>
            </div>
        </div>

        <!-- Lot Footer Action Bar (Done Opening Lot #[N]) -->
        <?php if ($can_manage): ?>
        <div class="lot-footer-bar" id="lotFooterBar">
            <div class="lot-footer-info">
                <i class="bi bi-info-circle-fill" style="color:#00796b"></i>
                <span>Complete review of bidders before finalizing this lot.</span>
            </div>
            <button class="btn-confirm-lot" id="btnDoneLot" onclick="openDoneLotModal()">
                <i class="bi bi-check2-circle"></i> Done Opening Lot #<span id="btnDoneLotNum">1</span>
            </button>
        </div>
        <?php else: ?>
        <div class="lot-footer-bar" id="lotFooterBar">
            <div class="lot-footer-info">
                <i class="bi bi-info-circle-fill" style="color:#00796b"></i>
                <span>Review in progress — waiting for Secretariat to finalize this lot.</span>
            </div>
            <span style="font-size:11px;font-weight:700;color:#f59e0b;background:#fef3c7;border:1px solid #fde68a;padding:6px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:6px">
                <i class="bi bi-hourglass-split"></i> Waiting for Secretariat…
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ AWARDING VIEW (Active for 'awarding' tab) ══ -->
<div id="awarding-container" style="display:none">

    <?php if (!$can_manage): ?>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
        <div style="width:40px;height:40px;border-radius:12px;background:#f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
            <i class="bi bi-hourglass-split"></i>
        </div>
        <div>
            <div style="font-size:13.5px;font-weight:800;color:#92400e">Awarding in Progress</div>
            <div style="font-size:12px;color:#b45309;margin-top:2px">The Secretariat is currently declaring winners for each lot. Results will appear below once finalized.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="award-panel">
        <div class="award-head">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:36px;height:36px;border-radius:10px;background:#e0f2f1;color:#00796b;display:flex;align-items:center;justify-content:center;font-size:18px">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:800;color:#06251b">Awarding Phase</div>
                    <div style="font-size:11.5px;color:#88968d">Select and declare winning bidders for each lot</div>
                </div>
            </div>
            <span class="status-pill sp-active" id="awardStatusPill"><i class="bi bi-trophy"></i> Ready to Award</span>
        </div>

        <div id="awarding-lots-list">
            <div style="padding:24px;display:flex;flex-direction:column;gap:12px">
                <div class="skel" style="height:60px"></div>
                <div class="skel" style="height:60px"></div>
            </div>
        </div>

        <?php if ($can_manage && $st !== 'ended'): ?>
        <div style="padding:16px 20px;border-top:1px solid #f0f4f2;background:#fafcfb;display:flex;justify-content:flex-end;">
            <button class="btn-end-session" onclick="openEndSessionModal()">
                <i class="bi bi-check2-all"></i> Conclude &amp; End Session
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CONCLUSION VIEW ══ -->
<div id="conclusion-container" style="display:none">
    <div class="award-panel">
        <div class="award-head">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:36px;height:36px;border-radius:10px;background:#e4f5ea;color:#1f7a3d;display:flex;align-items:center;justify-content:center;font-size:18px">
                    <i class="bi bi-flag-fill"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:800;color:#06251b">Session Concluded</div>
                    <div style="font-size:11.5px;color:#88968d">Official summary of this bid opening session</div>
                </div>
            </div>
            <span class="status-pill sp-active" style="background:#e4f5ea;color:#1f7a3d;border-color:#bbf7d0"><i class="bi bi-check2-all"></i> Ended</span>
        </div>
        <div id="conclusion-body" style="padding:20px">
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="skel" style="height:80px;border-radius:12px"></div>
                <div class="skel" style="height:120px;border-radius:12px"></div>
                <div class="skel" style="height:120px;border-radius:12px"></div>
            </div>
        </div>
        <div style="padding:16px 20px;border-top:1px solid #f0f4f2;background:#fafcfb;display:flex;justify-content:flex-end">
            <a href="dashboard.php" style="display:inline-flex;align-items:center;gap:8px;background:#06251b;color:#ffc107;font-size:13px;font-weight:800;padding:9px 20px;border-radius:10px;text-decoration:none;font-family:'Poppins',sans-serif;transition:all .15s">
                <i class="bi bi-house-fill"></i> Go to Dashboard
            </a>
        </div>
    </div>
</div>

</div><!-- /left -->

<!-- ════ RIGHT ════ -->
<div style="display:flex;flex-direction:column;gap:4px">

<div class="sb-panel">
    <div class="sb-head"><i class="bi bi-chat-dots-fill" style="color:#ffc107"></i> Live Chat</div>
    <div class="chat-ph"><i class="bi bi-chat-square-dots"></i><span>Chat coming soon</span></div>
</div>

<div class="sb-panel">
    <div class="sb-head"><i class="bi bi-list-check" style="color:#ffc107"></i> Progress</div>
    <div class="sb-body">
        <?php
        $step1_done   = ($st !== 'scheduled');
        $step2_done   = ($all_lots_done || in_array($st, ['awarding','ended']));
        $step2_active = (in_array($st, ['started','eligibility','financial']) && !$step2_done);
        $step3_done   = ($st === 'ended');
        $step3_active = ($st === 'awarding');
        $step4_done   = ($st === 'ended');
        ?>
        <div class="ms-list">

            <div class="ms-item" id="ms-step-start">
                <div class="ms-dot <?= $step1_done ? 'ms-done' : 'ms-active' ?>">
                    <i class="bi <?= $step1_done ? 'bi-check2' : 'bi-play-circle' ?>"></i>
                </div>
                <div class="ms-text">
                    <div class="ms-label">Session Started</div>
                    <div class="ms-desc">Session officially opened</div>
                </div>
            </div>

            <div class="ms-connector"></div>

            <div class="ms-item" id="ms-step-lots">
                <div class="ms-dot <?= $step2_done ? 'ms-done' : ($step2_active ? 'ms-active' : 'ms-pending') ?>">
                    <i class="bi <?= $step2_done ? 'bi-check2' : 'bi-layers' ?>"></i>
                </div>
                <div class="ms-text">
                    <div class="ms-label">Lots Review</div>
                    <div class="ms-desc">Opening eligibility &amp; financial</div>
                </div>
            </div>

            <div class="ms-connector"></div>

            <div class="ms-item" id="ms-step-award">
                <div class="ms-dot <?= $step3_done ? 'ms-done' : ($step3_active ? 'ms-active' : 'ms-pending') ?>">
                    <i class="bi <?= $step3_done ? 'bi-check2' : 'bi-trophy' ?>"></i>
                </div>
                <div class="ms-text">
                    <div class="ms-label">Awarding Phase</div>
                    <div class="ms-desc">Winner declaration per lot</div>
                </div>
            </div>

            <div class="ms-connector"></div>

            <div class="ms-item" id="ms-step-ended">
                <div class="ms-dot <?= $step4_done ? 'ms-done' : 'ms-pending' ?>">
                    <i class="bi bi-check2-all"></i>
                </div>
                <div class="ms-text">
                    <div class="ms-label">Session Concluded</div>
                    <div class="ms-desc">All lots awarded and archived</div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="sb-panel">
    <div class="sb-head">
        <i class="bi bi-people-fill" style="color:#ffc107"></i> Members
        <span style="margin-left:auto;font-size:11px;font-weight:700;background:#06251b;color:#ffc107;padding:1px 8px;border-radius:10px"><?= count($invited) ?></span>
    </div>
    <div class="sb-body" style="padding:10px 12px">
        <?php if (empty($invited)): ?>
        <div style="text-align:center;color:#88968d;font-size:12px;padding:12px">None invited.</div>
        <?php else: ?>
        <?php foreach ($invited as $m):
            $ini = strtoupper(substr($m['firstname'],0,1).substr($m['lastname'],0,1));
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:7px 4px;border-bottom:1px solid #f7faf8">
            <div style="width:28px;height:28px;border-radius:50%;background:#e0ece4;color:#06251b;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                <?php if ($m['profile_picture_url']): ?>
                <img src="../<?= htmlspecialchars($m['profile_picture_url']) ?>" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?><?= htmlspecialchars($ini) ?><?php endif; ?>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700;color:#06251b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($m['firstname'].' '.$m['lastname']) ?></div>
                <div style="font-size:10px;color:#88968d"><?= htmlspecialchars($m['admin_type'] ?? '—') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /right -->
</div><!-- /bs-layout -->
</div>
</main>

<!-- ══ START SESSION CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="startSessionModal" onclick="if(event.target===this)closeStartSessionModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-play-circle-fill" style="color:#ffc107"></i> Start Bid Opening Session</h4>
            <button class="bsm-x" onclick="closeStartSessionModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13px;color:#06251b;font-weight:700;margin:0 0 8px">Are you sure you want to start this session?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">This will officially open the bid opening process. You will begin reviewing documents lot-by-lot starting with Lot 1.</p>
            <div style="background:#fef8e7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#92400e;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;flex-shrink:0"></i>
                All invited committee members and bidders can view live progress.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeStartSessionModal()">Cancel</button>
            <button class="btn-confirm" id="startSessionConfirmBtn" onclick="doStartSession()">
                <i class="bi bi-play-fill"></i> Yes, Start Session
            </button>
        </div>
    </div>
</div>

<!-- ══ START ELIGIBILITY CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="startEligibilityModal" onclick="if(event.target===this)closeStartEligibilityModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-shield-check" style="color:#1f7a3d"></i> Begin Eligibility &amp; Technical Phase</h4>
            <button class="bsm-x" onclick="closeStartEligibilityModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13.5px;color:#06251b;font-weight:700;margin:0 0 8px">Start the Eligibility &amp; Technical evaluation for <span id="startEligLotName">Lot 1</span>?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">This will officially open the eligibility review phase. All invited members will be notified and documents will be available for inspection.</p>
            <div style="background:#e4f5ea;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#166534;">
                <i class="bi bi-info-circle-fill" style="color:#1f7a3d;flex-shrink:0"></i>
                This action will be recorded in the system.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeStartEligibilityModal()">Cancel</button>
            <button class="btn-confirm" style="background:#1f7a3d;color:#fff" id="startEligConfirmBtn" onclick="doStartEligibilityPhase()">
                <i class="bi bi-play-circle-fill"></i> Yes, Begin Phase
            </button>
        </div>
    </div>
</div>

<!-- ══ CANNOT PROCEED MODAL ══ -->
<div class="bsm-bg" id="cannotProceedModal" onclick="if(event.target===this)closeCannotProceedModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-exclamation-triangle-fill" style="color:#d97706"></i> Cannot Proceed Yet</h4>
            <button class="bsm-x" onclick="closeCannotProceedModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13px;color:#06251b;font-weight:700;margin:0 0 10px" id="cannotProceedTitle">Review incomplete</p>
            <div id="cannotProceedList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
            <div style="background:#fef8e7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#92400e;">
                <i class="bi bi-info-circle-fill" style="color:#d97706;flex-shrink:0"></i>
                Complete all evaluations before finalizing this lot.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-confirm" style="background:#06251b" onclick="closeCannotProceedModal()">Got it</button>
        </div>
    </div>
</div>

<!-- ══ DONE OPENING LOT MODAL ══ -->
<div class="bsm-bg" id="doneLotModal" onclick="if(event.target===this)closeDoneLotModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-check-circle-fill" style="color:#1f7a3d"></i> Complete Lot Opening</h4>
            <button class="bsm-x" onclick="closeDoneLotModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13.5px;color:#06251b;font-weight:700;margin:0 0 8px">Complete opening for <span id="doneLotModalName">Lot 1</span>?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">This will mark this lot as completed and advance the session to the next step. You can still return to inspect this lot at any time.</p>
            <div style="background:#e4f5ea;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#166534;">
                <i class="bi bi-info-circle-fill" style="color:#1f7a3d;flex-shrink:0"></i>
                <span id="doneLotModalPrompt">Proceeding to next Lot.</span>
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeDoneLotModal()">Cancel</button>
            <button class="btn-confirm" style="background:#1f7a3d;color:#fff" id="doneLotConfirmBtn" onclick="confirmDoneLot()">
                <i class="bi bi-arrow-right-circle-fill"></i> Confirm &amp; Proceed
            </button>
        </div>
    </div>
</div>

<!-- ══ PASSWORD MODAL ══ -->
<div class="bsm-bg" id="pwModal" onclick="if(event.target===this)closePw()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-lock-fill" style="color:#ffc107"></i> Enter Password to Open Files</h4>
            <button class="bsm-x" onclick="closePw()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">Your admin password is required to decrypt and open these documents.</p>
            <input type="password" id="pwInput" class="bsm-input" placeholder="Enter your password…"
                   onkeydown="if(event.key==='Enter')doPwConfirm()">
            <div class="bsm-err" id="pwErr"></div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closePw()">Cancel</button>
            <button class="btn-confirm" id="pwConfirmBtn" onclick="doPwConfirm()">
                <i class="bi bi-unlock-fill"></i> Open Files
            </button>
        </div>
    </div>
</div>

<!-- ══ ELIGIBILITY / FINANCIAL CHECKLIST MODAL ══ -->
<div class="bsm-bg" id="eligModal" onclick="if(event.target===this)closeElig()">
    <div class="bsm">
        <div class="bsm-head">
            <h4 id="eligModalTitle"><i class="bi bi-clipboard-check" style="color:#ffc107"></i> Document Checklist</h4>
            <button class="bsm-x" onclick="closeElig()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:12.5px;color:#55665a;margin:0 0 4px">Business: <strong id="eligName" style="color:#06251b"></strong></p>
            <p style="font-size:12px;color:#88968d;margin:0" id="eligModalSub">Mark evaluation after reviewing all submitted documents.</p>
        </div>
        <div class="bsm-foot" style="gap:10px">
            <?php if ($can_manage): ?>
            <button class="btn-ineligible" onclick="submitElig(0)"><i class="bi bi-x-circle-fill"></i> Disqualify</button>
            <button class="btn-eligible"   onclick="submitElig(1)"><i class="bi bi-check-circle-fill"></i> Comply / Eligible</button>
            <?php else: ?>
            <button class="btn-cancel" onclick="closeElig()">Close</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ AWARD CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="awardModal" onclick="if(event.target===this)closeAwardModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-trophy-fill" style="color:#ffc107"></i> Confirm Winning Bidder</h4>
            <button class="bsm-x" onclick="closeAwardModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13px;color:#06251b;font-weight:700;margin:0 0 8px">Declare Winner for <span id="awardLotTitle">Lot</span>?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 6px">Winner: <strong id="awardBidderName" style="color:#06251b"></strong></p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">Awarded Amount: <strong id="awardDisplayAmount" style="color:#00796b;font-family:'Space Grotesk',sans-serif"></strong></p>
            <div style="background:#fef8e7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#92400e;">
                <i class="bi bi-info-circle-fill" style="color:#d97706;flex-shrink:0"></i>
                This will officially record the winning award for this lot.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeAwardModal()">Cancel</button>
            <button class="btn-confirm" id="awardConfirmBtn" onclick="doAwardConfirm()">
                <i class="bi bi-trophy-fill"></i> Confirm Award
            </button>
        </div>
    </div>
</div>

<!-- ══ FAIL LOT CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="failLotModal" onclick="if(event.target===this)closeFailLotModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-x-circle-fill" style="color:#dc2626"></i> Mark Lot as Failed</h4>
            <button class="bsm-x" onclick="closeFailLotModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13.5px;color:#06251b;font-weight:700;margin:0 0 8px">Mark <span id="failLotTitle">Lot</span> as Failed / No Award?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">This will record that no winner was declared for this lot. All bids will be marked as rejected.</p>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:12px;color:#991b1b;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;flex-shrink:0"></i>
                This action can be reviewed but cannot be automatically undone.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeFailLotModal()">Cancel</button>
            <button class="btn-confirm" style="background:#dc2626;color:#fff" id="failLotConfirmBtn" onclick="doFailLot()">
                <i class="bi bi-x-circle-fill"></i> Confirm — No Award
            </button>
        </div>
    </div>
</div>

<!-- ══ END SESSION CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="endSessionModal" onclick="if(event.target===this)closeEndSessionModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-check2-all" style="color:#ffc107"></i> End Bid Opening Session</h4>
            <button class="bsm-x" onclick="closeEndSessionModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p style="font-size:13px;color:#06251b;font-weight:700;margin:0 0 8px">Are you sure you want to conclude this session?</p>
            <p style="font-size:12.5px;color:#55665a;margin:0 0 12px">This will finalize all lot reviews, archive the live session, and update the procurement status. This action cannot be undone.</p>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeEndSessionModal()">Cancel</button>
            <button class="btn-confirm" id="endSessionConfirmBtn" onclick="doEndSession()">
                <i class="bi bi-check2-circle"></i> Yes, End Session
            </button>
        </div>
    </div>
</div>

<!-- ══ FILE VIEWER ══ -->
<div class="fv-overlay" id="fvOverlay">
    <div class="fv-bar">
        <span class="fv-title" id="fvTitle">Document</span>
        <button class="fv-close" onclick="closeFv()"><i class="bi bi-x-lg"></i> Close</button>
    </div>
    <div class="fv-content" id="fvContent"></div>
</div>

<script>
const SESSION_ID = <?= (int)$session_id ?>;
const PROC_ID        = <?= (int)$proc_id ?>;
const CAN_MANAGE     = <?= $can_manage ? 'true' : 'false' ?>;
const LOTS           = <?= json_encode(array_values($lots)) ?>;
const CURRENT_LOT_ID = <?= $current_lot_id ?>;
let   SESSION_STATUS = '<?= $st ?>';

// ── State Management ──────────────────────────────────────────────────────
const STATE = {
    currentTab: 0, // 0..N-1 for lots, or 'awarding'
    activeLotIdx: 0,
    doneLotIndices: [],
    lots: {}, // lotId -> { stage: 'eligibility'|'financial', bidders: [], done: { eligibility: [], financial: [] } }
    awards: [],
};
const openedDocs = {}; // docId -> { data_url, mime, file_name }

// Pending modal context
let pw_stage, pw_lotId, pw_bidderId, pw_bidId, pw_files;
let el_bidId, el_bidderName, el_lotId, el_bidderId, el_phase;
let _awardTarget = null;

// ── Helpers ───────────────────────────────────────────────────────────────
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

function post(params){
    return fetch('bid_session_api.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams(params)
    }).then(r=>r.json());
}

function get(params){
    return fetch('bid_session_api.php?'+new URLSearchParams(params)).then(r=>r.json());
}

// ── Database-Driven Session Progress & Milestones ─────────────────────────
function refreshSessionProgressFromDB(){
    get({ action:'progress', session_id:SESSION_ID })
    .then(data=>{
        if(!data || !data.status) return;
        SESSION_STATUS = data.status;
        const curLotId = parseInt(data.current_lot_id || 0);
        const lots = data.lots || [];

        // Find active lot from database
        const activeLot = lots.find(l => parseInt(l.id) === curLotId) || lots.find(l => !l.is_done) || lots[0];
        const lotNum = activeLot ? ('Lot ' + activeLot.lot_number) : 'Lot 1';

        // Update Activity text directly from database state
        const actEl = document.getElementById('activityText');
        if(actEl){
            if(data.status === 'scheduled'){
                actEl.textContent = 'Session is scheduled — waiting for Secretariat to start.';
            } else if(data.status === 'started' || data.status === 'eligibility'){
                actEl.textContent = `Session in progress · Reviewing ${lotNum} (Eligibility & Technical Phase)`;
            } else if(data.status === 'financial'){
                actEl.textContent = `Session in progress · Reviewing ${lotNum} (Financial Phase)`;
            } else if(data.status === 'awarding'){
                actEl.textContent = 'Awarding phase in progress · Selecting winning bidders per lot.';
            } else if(data.status === 'ended'){
                actEl.textContent = 'Bid Opening Session officially concluded and archived.';
            }
        }

        // Update Hero Status Pill
        const pill = document.getElementById('heroStatusPill');
        if(pill){
            const isLive = ['started','eligibility','financial','awarding'].includes(data.status);
            pill.className = 'ipill ' + (isLive ? 'live' : 'sched');
            pill.innerHTML = `<i class="bi bi-${isLive ? 'broadcast' : 'clock'}"></i> ${data.status.charAt(0).toUpperCase() + data.status.slice(1)}`;
        }

        // Update master tabs done and unlocked states from DB
        let allLotsDone = lots.length > 0;
        lots.forEach((l, i)=>{
            const isDone = !!l.is_done;
            if(!isDone) allLotsDone = false;

            const t = document.getElementById('mtab-'+i);
            const b = document.getElementById('mtab-badge-'+i);
            if(t){
                if(isDone){
                    t.classList.add('done');
                    t.classList.remove('locked');
                }
                if(b){
                    b.className = 'mtab-badge' + (isDone ? ' done' : '');
                    b.innerHTML = isDone ? '<i class="bi bi-check2"></i> Done' : '';
                }
            }
        });

        // Awarding tab unlock state
        const awardTab = document.getElementById('mtab-awarding');
        if(awardTab && (allLotsDone || ['awarding','ended'].includes(data.status))){
            awardTab.classList.remove('locked');
        }

        // Lock all lot tabs when in awarding or ended phase
        if(['awarding','ended'].includes(data.status)){
            LOTS.forEach((_, i) => {
                const t = document.getElementById('mtab-'+i);
                if(t) t.classList.add('locked');
            });
        }

        // Unlock conclusion tab when ended, lock awarding
        if(data.status === 'ended'){
            const cTab = document.getElementById('mtab-conclusion');
            if(cTab) cTab.classList.remove('locked');
            const awardTab = document.getElementById('mtab-awarding');
            if(awardTab) awardTab.classList.add('locked');
        }

        // Update sidebar milestones from DB
        updateSidebarMilestones(data.status, allLotsDone, data.ended_at);
    });
}

function updateSidebarMilestones(status, allLotsDone, endedAt){
    const s1 = document.getElementById('ms-step-start');
    const s2 = document.getElementById('ms-step-lots');
    const s3 = document.getElementById('ms-step-award');
    const s4 = document.getElementById('ms-step-ended');

    if(s1){
        const done = status !== 'scheduled';
        s1.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : 'ms-active');
        s1.querySelector('.ms-dot i').className = 'bi ' + (done ? 'bi-check2' : 'bi-play-circle');
    }

    if(s2){
        const done = allLotsDone || ['awarding','ended'].includes(status);
        const active = ['started','eligibility','financial'].includes(status) && !done;
        s2.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : (active ? 'ms-active' : 'ms-pending'));
        s2.querySelector('.ms-dot i').className = 'bi ' + (done ? 'bi-check2' : 'bi-layers');
    }

    if(s3){
        const done = status === 'ended';
        const active = status === 'awarding';
        s3.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : (active ? 'ms-active' : 'ms-pending'));
        s3.querySelector('.ms-dot i').className = 'bi ' + (done ? 'bi-check2' : 'bi-trophy');
    }

    if(s4){
        const done = status === 'ended';
        s4.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : 'ms-pending');
        s4.querySelector('.ms-dot i').className = 'bi bi-check2-all';
    }
}

// ── Start Session ─────────────────────────────────────────────────────────
function openStartSessionModal(){ document.getElementById('startSessionModal').classList.add('open'); }
function closeStartSessionModal(){ document.getElementById('startSessionModal').classList.remove('open'); }

function doStartSession(){
    const btn = document.getElementById('startSessionConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting…';
    const firstLotId = LOTS.length > 0 ? LOTS[0].id : 0;
    post({ action:'start_session', session_id:SESSION_ID, first_lot_id:firstLotId })
    .then(d=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill"></i> Yes, Start Session';
        if(!d.success){ alert(d.message||'Failed to start session.'); return; }
        closeStartSessionModal();
        SESSION_STATUS = 'started';
        const wrap = document.getElementById('heroStartWrap');
        if(wrap) wrap.remove();
        refreshSessionProgressFromDB();
        loadLot(STATE.activeLotIdx);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-play-fill"></i> Yes, Start Session'; alert('Network error.'); });
}

// ── Master Tab Navigation ─────────────────────────────────────────────────
function onMasterTabClick(tab){
    if(tab === 'awarding'){
        const awardTab = document.getElementById('mtab-awarding');
        if(awardTab && awardTab.classList.contains('locked')) return;
        if(SESSION_STATUS === 'ended') return;
        switchMasterTab('awarding');
    } else if(tab === 'conclusion'){
        const conclusionTab = document.getElementById('mtab-conclusion');
        if(conclusionTab && conclusionTab.classList.contains('locked')) return;
        switchMasterTab('conclusion');
    } else {
        const idx = parseInt(tab);
        const tabEl = document.getElementById('mtab-'+idx);
        // Block locked tabs (future lots or awarding-phase lock)
        if(tabEl && tabEl.classList.contains('locked')) return;
        // Block lot navigation once in awarding or ended phase
        if(['awarding','ended'].includes(SESSION_STATUS)) return;
        // Block previous lots — can't go back once a lot is done
        if(idx < STATE.activeLotIdx) return;
        switchMasterTab(idx);
    }
}

function switchMasterTab(tab){
    STATE.currentTab = tab;

    // Update master tab styles
    LOTS.forEach((lot, i)=>{
        const t = document.getElementById('mtab-'+i);
        if(!t) return;
        t.classList.remove('active');
        if(tab === i){
            t.classList.add('active');
            t.classList.remove('locked');
        } else if(tab === 'awarding' || tab === 'conclusion'){
            // Lock all lot tabs once in awarding/conclusion phase
            t.classList.add('locked');
        }
    });

    const awardTab = document.getElementById('mtab-awarding');
    if(awardTab){
        awardTab.classList.remove('active');
        if(tab === 'awarding'){
            awardTab.classList.add('active');
            awardTab.classList.remove('locked');
        }
    }

    const conclusionTab = document.getElementById('mtab-conclusion');
    if(conclusionTab){
        conclusionTab.classList.remove('active');
        if(tab === 'conclusion'){
            conclusionTab.classList.add('active');
            conclusionTab.classList.remove('locked');
            // Lock awarding once in conclusion — session is fully ended
            const awardTab2 = document.getElementById('mtab-awarding');
            if(awardTab2) awardTab2.classList.add('locked');
        }
    }

    const lotContainer    = document.getElementById('lot-session-container');
    const awardContainer  = document.getElementById('awarding-container');
    const conclusionContainer = document.getElementById('conclusion-container');

    lotContainer.style.display        = (tab !== 'awarding' && tab !== 'conclusion') ? 'block' : 'none';
    awardContainer.style.display      = (tab === 'awarding')   ? 'block' : 'none';
    conclusionContainer.style.display = (tab === 'conclusion') ? 'block' : 'none';

    if(tab === 'awarding'){
        loadAwarding();
    } else if(tab === 'conclusion'){
        loadConclusion();
    } else {
        STATE.activeLotIdx = tab;
        loadLot(tab);
    }
}

// ── Load Lot ──────────────────────────────────────────────────────────────
function loadLot(idx){
    const lot = LOTS[idx];
    if(!lot) return;

    document.getElementById('lotHeadTitle').innerHTML = `<i class="bi bi-layers-fill" style="color:#1f7a3d"></i> <span>Lot ${esc(lot.lot_number)}${lot.lot_title ? ' · '+esc(lot.lot_title) : ''}</span>`;
    document.getElementById('lotHeadAbc').innerHTML = `Approved Budget for Contract: <strong>₱${parseFloat(lot.abc||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</strong>`;

    const doneNumEl = document.getElementById('btnDoneLotNum');
    if(doneNumEl) doneNumEl.textContent = lot.lot_number;

    if(!STATE.lots[lot.id]){
        STATE.lots[lot.id] = {
            stage: 'eligibility', // will be corrected after bidders load
            bidders: [],
            done: { eligibility: [], financial: [] }
        };
    }

    // Determine correct stage from DB by fetching bidders first
    fetchAndRenderBiddersByDB(lot.id);
}

// Fetch bidders and render — stage is read from bid_opening_sessions.status (SESSION_STATUS), not derived from bidder data
function fetchAndRenderBiddersByDB(lotId){
    const area = document.getElementById('lot-bidder-area');
    const gate = document.getElementById('startEligibilityGate');

    // If session is still in 'started' state, show the "Begin Eligibility Phase" gate
    // and hide the bidder area until the phase is officially started
    if(SESSION_STATUS === 'started'){
        if(gate) gate.style.display = '';
        area.innerHTML = '';
        return;
    }

    if(gate) gate.style.display = 'none';

    area.innerHTML = '<div style="padding:24px;display:flex;gap:8px"><div class="skel" style="height:32px;width:120px;border-radius:20px"></div><div class="skel" style="height:32px;width:120px;border-radius:20px"></div></div>';

    // Stage comes from the DB status field — SESSION_STATUS is set on PHP page load from
    // bid_opening_sessions.status, and updated whenever setLotStage() persists a change.
    const stage = (SESSION_STATUS === 'financial') ? 'financial' : 'eligibility';

    if(!STATE.lots[lotId]) STATE.lots[lotId] = { stage, bidders: {}, done: { eligibility: [], financial: [] } };
    STATE.lots[lotId].stage = stage;

    updateStageUI(stage);
    fetchAndRenderBidders(lotId, stage);
}

// ── Begin Eligibility Phase ───────────────────────────────────────────────
function openStartEligibilityModal(){
    const lot = LOTS[STATE.activeLotIdx];
    const lotName = lot ? `Lot ${lot.lot_number}${lot.lot_title ? ' · '+lot.lot_title : ''}` : 'this lot';
    document.getElementById('startEligLotName').textContent = lotName;
    document.getElementById('startEligibilityModal').classList.add('open');
}
function closeStartEligibilityModal(){
    document.getElementById('startEligibilityModal').classList.remove('open');
}

function doStartEligibilityPhase(){
    const btn = document.getElementById('startEligConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting…';

    post({ action:'start_phase', session_id:SESSION_ID, phase:'eligibility' })
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle-fill"></i> Yes, Begin Phase';
        if(!d.success){ alert(d.message||'Failed.'); return; }
        closeStartEligibilityModal();
        SESSION_STATUS = 'eligibility';
        const gate = document.getElementById('startEligibilityGate');
        if(gate) gate.style.display = 'none';
        refreshSessionProgressFromDB();
        const lot = LOTS[STATE.activeLotIdx];
        if(lot) fetchAndRenderBiddersByDB(lot.id);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-play-circle-fill"></i> Yes, Begin Phase'; });
}

function setLotStage(stage){
    const lot = LOTS[STATE.activeLotIdx];
    if(!lot) return;
    if(!STATE.lots[lot.id]) STATE.lots[lot.id] = { stage, bidders: {}, done: { eligibility: [], financial: [] } };

    const lotState = STATE.lots[lot.id];

    // Guard: can't go to financial unless all eligibility is evaluated
    if(stage === 'financial' && lotState.stage !== 'financial'){
        const bidders = (lotState.bidders && lotState.bidders['eligibility']) || [];
        if(bidders.length === 0){
            // Eligibility bidders not yet loaded — fetch first, then re-check
            get({ action:'bidders', lot_id:lot.id, phase:'eligibility', session_id:SESSION_ID })
            .then(d => {
                lotState.bidders['eligibility'] = d.bidders || [];
                setLotStage(stage); // retry now that we have data
            });
            return;
        }
        const pending = bidders.filter(b => ['pending','opened'].includes(b.eligibility_status));
        if(pending.length > 0){
            openCannotProceedModal(
                'Cannot advance to Financial stage yet',
                [`${pending.length} bidder${pending.length>1?'s':''} still pending Eligibility & Technical evaluation`]
            );
            return;
        }
    }

    // Persist the stage change to bid_opening_sessions.status so it survives page refresh
    post({ action:'start_phase', session_id:SESSION_ID, phase:stage })
    .then(()=>{
        SESSION_STATUS        = stage;   // keep in-memory status in sync
        lotState.stage        = stage;
        updateStageUI(stage);
        fetchAndRenderBidders(lot.id, stage);
    });
}

function updateStageUI(stage){
    const eligBtn = document.getElementById('stageBtn-eligibility');
    const finBtn  = document.getElementById('stageBtn-financial');
    const pill    = document.getElementById('lotCurrentStagePill');

    if(stage === 'eligibility'){
        eligBtn.className  = 'stage-step active';
        eligBtn.style.pointerEvents = CAN_MANAGE ? '' : 'none';
        finBtn.className   = 'stage-step';
        // financial locked until eligibility done — pointer-events blocked
        finBtn.style.pointerEvents = 'none';
        finBtn.style.opacity = '.45';
        pill.className    = 'ipill live';
        pill.innerHTML    = '<i class="bi bi-shield-check"></i> Stage 1: Eligibility &amp; Technical';
    } else {
        eligBtn.className  = 'stage-step done';
        // once on financial, eligibility is read-only indicator
        eligBtn.style.pointerEvents = 'none';
        eligBtn.style.opacity = '1';
        finBtn.className   = 'stage-step active';
        finBtn.style.pointerEvents = CAN_MANAGE ? '' : 'none';
        finBtn.style.opacity = '';
        pill.className    = 'ipill mode';
        pill.innerHTML    = '<i class="bi bi-cash-stack"></i> Stage 2: Financial Proposal';
    }
}

// ── Fetch and Render Bidders for Lot & Stage ───────────────────────────────
function fetchAndRenderBidders(lotId, stage){
    const area = document.getElementById('lot-bidder-area');
    area.innerHTML = '<div style="padding:24px;display:flex;gap:8px"><div class="skel" style="height:32px;width:120px;border-radius:20px"></div><div class="skel" style="height:32px;width:120px;border-radius:20px"></div></div>';

    get({ action:'bidders', lot_id:lotId, phase:stage, session_id:SESSION_ID })
    .then(d=>{
        const bidders = d.bidders || [];
        if(!STATE.lots[lotId]) STATE.lots[lotId] = { stage, bidders: {}, done: { eligibility: [], financial: [] } };
        // Store per-stage so guards can always access the right set
        STATE.lots[lotId].bidders[stage] = bidders;
        STATE.lots[lotId].stage = stage;

        renderLotBidderArea(lotId, stage, bidders);
    })
    .catch(()=>{
        area.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Failed to load bidders.</div>';
    });
}

function renderLotBidderArea(lotId, stage, bidders){
    const area = document.getElementById('lot-bidder-area');
    area.innerHTML = '';

    if(!bidders.length){
        area.innerHTML = '<div class="p-empty"><i class="bi bi-inbox"></i>No bids submitted for this lot.</div>';
        return;
    }

    const lotState = STATE.lots[lotId];
    const doneList = lotState.done[stage] || [];

    // Tabs row
    const tabsRow  = mkEl('div','bidder-tabs');
    const actBar   = mkEl('div','action-bar');
    const fileArea = mkEl('div','files-pane');

    area.appendChild(tabsRow);
    area.appendChild(actBar);
    area.appendChild(fileArea);

    let firstActiveBidder = null;

    bidders.forEach((b)=>{
        const isEligDisq = (b.eligibility_status === 'disqualified');
        const isFinDisq  = (b.financial_status === 'non_compliant');
        const isDisq     = stage === 'eligibility' ? isEligDisq : (isEligDisq || isFinDisq);

        const isEvaluated = stage === 'eligibility'
            ? ['eligible','disqualified'].includes(b.eligibility_status)
            : ['qualified','non_compliant'].includes(b.financial_status);

        const isDone = isEvaluated && !isDisq;
        const bName  = b.business_name || (b.firstname+' '+b.lastname);
        const ini    = (b.firstname||'').charAt(0).toUpperCase() + (b.lastname||'').charAt(0).toUpperCase();

        let cls = 'bidder-tab';
        if(isDisq){
            cls += ' disqualified';
        } else if(isDone){
            cls += ' done';
        }

        const tab = mkEl('div', cls);
        tab.id = `btab-${lotId}-${stage}-${b.bidder_id}`;
        tab.innerHTML = `
            <div class="bt-avatar">${b.avatar ? `<img src="../${esc(b.avatar)}" alt="">` : esc(ini)}</div>
            <span>${esc(bName)}</span>
            ${isDisq ? '<span class="disq-badge"><i class="bi bi-x-circle-fill"></i> Disqualified</span>' : (isDone ? '<i class="bi bi-check2-circle" style="color:#00796b;font-size:11px;margin-left:2px"></i>' : '')}
        `;

        if(!isDisq){
            tab.onclick = ()=>{
                // Only allow clicking the first unevaluated bidder
                if(isDone) return;
                const currentBidder = bidders.find(b2 =>
                    !b2.disqualified &&
                    !(stage === 'eligibility'
                        ? ['eligible','disqualified'].includes(b2.eligibility_status)
                        : ['qualified','non_compliant'].includes(b2.financial_status)
                    )
                );
                if(!currentBidder || currentBidder.bidder_id !== b.bidder_id) return;
                selectBidderTab(lotId, stage, b, actBar, fileArea);
            };
        }

        tabsRow.appendChild(tab);

        if(!firstActiveBidder && !isDone && !isDisq){
            firstActiveBidder = b;
        }
    });

    if(firstActiveBidder){
        selectBidderTab(lotId, stage, firstActiveBidder, actBar, fileArea);
    } else {
        // If all bidders for this stage are evaluated
        const remaining = bidders.filter(b => !b.disqualified && !(stage === 'eligibility'
            ? ['eligible','disqualified'].includes(b.eligibility_status)
            : ['qualified','non_compliant'].includes(b.financial_status)
        ));
        if(remaining.length === 0){
            if(stage === 'eligibility'){
                // Unlock the financial stage button now
                const finBtn = document.getElementById('stageBtn-financial');
                if(finBtn){ finBtn.style.pointerEvents=''; finBtn.style.opacity=''; }

                actBar.innerHTML = `
                    <div style="font-size:12.5px;font-weight:700;color:#15803d;display:flex;align-items:center;gap:6px">
                        <i class="bi bi-check-circle-fill"></i> Eligibility Stage Complete
                    </div>
                    <button class="btn-confirm" style="background:#06251b;color:#ffc107" onclick="setLotStage('financial')">
                        Proceed to Stage 2: Financial <i class="bi bi-arrow-right"></i>
                    </button>
                `;
                // Hide the button for non-managers
                if(!CAN_MANAGE){
                    actBar.querySelector('button.btn-confirm') && (actBar.querySelector('button.btn-confirm').style.display = 'none');
                }
                fileArea.innerHTML = '<div class="p-empty"><i class="bi bi-check2-all" style="color:#15803d"></i>All eligibility documents reviewed for this lot.</div>';
            } else {
                actBar.innerHTML = `
                    <div style="font-size:12.5px;font-weight:700;color:#15803d;display:flex;align-items:center;gap:6px">
                        <i class="bi bi-check-circle-fill"></i> Financial Stage Complete
                    </div>
                `;
                fileArea.innerHTML = '<div class="p-empty"><i class="bi bi-trophy" style="color:#00796b"></i>All bids reviewed for this lot. Click "Done Opening Lot" below to proceed.</div>';
            }
        }
    }
}

function selectBidderTab(lotId, stage, bidder, actBar, fileArea){
    // Highlight tab
    document.querySelectorAll('.bidder-tab').forEach(t=>t.classList.remove('active'));
    const t = document.getElementById(`btab-${lotId}-${stage}-${bidder.bidder_id}`);
    if(t) t.classList.add('active');

    const bName = bidder.business_name || (bidder.firstname+' '+bidder.lastname);

    fileArea.innerHTML = '<div style="padding:16px;display:flex;flex-direction:column;gap:8px"><div class="skel" style="height:44px"></div><div class="skel" style="height:44px"></div></div>';

    actBar.innerHTML = `
        <div class="action-bar-name"><i class="bi bi-building"></i>${esc(bName)}</div>
        <div class="action-bar-btns">
            <button class="btn-open-files" id="btn-open-files-${bidder.bidder_id}" disabled
                onclick="openFiles('${stage}', ${lotId}, ${bidder.bidder_id}, ${bidder.bid_id})">
                <i class="bi bi-folder2-open"></i> Open Files
            </button>
            ${CAN_MANAGE ? `
            <button class="btn-checklist" id="btn-chk-${bidder.bidder_id}"
                onclick="openEligModal(${bidder.bid_id}, '${esc(bName).replace(/'/g,"\\'")}', '${stage}', ${lotId}, ${bidder.bidder_id})">
                <i class="bi bi-clipboard-check"></i> Checklist
            </button>` : (() => {
                const st = stage === 'eligibility' ? bidder.eligibility_status : bidder.financial_status;
                if(st === 'pending' || st === 'opened')
                    return `<span style="font-size:11px;font-weight:700;color:#f59e0b;background:#fef3c7;border:1px solid #fde68a;padding:4px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px"><i class="bi bi-hourglass-split"></i> Waiting for evaluation…</span>`;
                if(st === 'eligible' || st === 'qualified')
                    return `<span style="font-size:11px;font-weight:700;color:#15803d;background:#dcfce7;border:1px solid #bbf7d0;padding:4px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px"><i class="bi bi-check-circle-fill"></i> ${st === 'eligible' ? 'Eligible' : 'Qualified'}</span>`;
                if(st === 'disqualified' || st === 'non_compliant')
                    return `<span style="font-size:11px;font-weight:700;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;padding:4px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px"><i class="bi bi-x-circle-fill"></i> ${st === 'disqualified' ? 'Disqualified' : 'Non-Compliant'}</span>`;
                return '';
            })()}
        </div>
    `;

    get({ action:'files', bidder_id:bidder.bidder_id, lot_id:lotId, phase:stage, session_id:SESSION_ID })
    .then(d=>{
        const files = d.files || [];
        if(!files.length){
            fileArea.innerHTML = `<div class="p-empty"><i class="bi bi-file-earmark-x"></i>No ${stage} documents uploaded.</div>`;
            return;
        }
        const openBtn = document.getElementById(`btn-open-files-${bidder.bidder_id}`);

        // If files were already opened (DB says so), auto-decrypt immediately
        if(bidder.files_opened){
            if(openBtn){
                openBtn.disabled = true;
                openBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Decrypting…';
            }
            (async ()=>{
                for(const f of files){
                    if(openedDocs[f.id]) continue; // already in memory
                    const res = await post({ action:'decrypt_file', doc_id:f.id, session_id:SESSION_ID });
                    if(res.success) openedDocs[f.id] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
                }
                if(openBtn){
                    openBtn.disabled = true;
                    openBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Opened';
                    openBtn.style.background = '#e4f5ea';
                    openBtn.style.color = '#1f7a3d';
                }
                renderFiles(fileArea, files);
                // Re-activate view buttons
                files.forEach(f=>{
                    const b = document.getElementById('vbtn-'+f.id);
                    if(b){ b.classList.add('active'); b.title='View document'; }
                });
                // Enable checklist if not yet evaluated
                const chkBtn = document.getElementById(`btn-chk-${bidder.bidder_id}`);
                if(chkBtn) chkBtn.classList.add('ready');
            })();
        } else {
            if(openBtn) openBtn.disabled = false;
            renderFiles(fileArea, files);
        }
    });
}

function renderFiles(area, files){
    area.innerHTML = '';
    files.forEach(f=>{
        const opened = !!openedDocs[f.id];
        const ext = (f.display_name||'').split('.').pop().toLowerCase();
        const icon = ext==='pdf'?'bi-file-earmark-pdf':['jpg','jpeg','png','gif','webp'].includes(ext)?'bi-file-earmark-image':'bi-file-earmark-text';
        const row = mkEl('div','file-row');
        row.id = 'fr-'+f.id;
        row.innerHTML = `
            <div class="f-icon"><i class="bi ${esc(icon)}"></i></div>
            <div class="f-info">
                <div class="f-name" title="${esc(f.display_name)}">${esc(f.display_name)}</div>
                <div class="f-meta">${esc(f.uploaded_at)}</div>
            </div>
            <button class="btn-view${opened?' active':''}" id="vbtn-${f.id}"
                onclick="viewDoc(${f.id})" title="${opened?'View document':'Open files first'}">
                <i class="bi bi-eye"></i> View
            </button>
        `;
        area.appendChild(row);
    });
}

// ── Open Files — password prompt, then decrypt ────────────────────────────
function openFiles(stage, lotId, bidderId, bidId){
    const openBtn = document.getElementById(`btn-open-files-${bidderId}`);
    if(openBtn){ openBtn.disabled = true; openBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading…'; }

    // Fetch file list first, store context, then show password modal
    get({ action:'files', bidder_id:bidderId, lot_id:lotId, phase:stage, session_id:SESSION_ID })
    .then(d=>{
        const files = d.files || [];
        if(!files.length){
            if(openBtn){ openBtn.disabled = false; openBtn.innerHTML = '<i class="bi bi-folder2-open"></i> Open Files'; }
            return;
        }
        // Store context for doPwConfirm
        pw_stage    = stage;
        pw_lotId    = lotId;
        pw_bidderId = bidderId;
        pw_bidId    = bidId;
        pw_files    = files;

        if(openBtn){ openBtn.disabled = false; openBtn.innerHTML = '<i class="bi bi-folder2-open"></i> Open Files'; }
        document.getElementById('pwInput').value = '';
        document.getElementById('pwErr').style.display = 'none';
        document.getElementById('pwModal').classList.add('open');
        setTimeout(()=>document.getElementById('pwInput').focus(), 100);
    }).catch(()=>{
        if(openBtn){ openBtn.disabled = false; openBtn.innerHTML = '<i class="bi bi-folder2-open"></i> Open Files'; }
    });
}

function closePw(){ document.getElementById('pwModal').classList.remove('open'); }

function doPwConfirm(){
    const pw  = document.getElementById('pwInput').value.trim();
    const err = document.getElementById('pwErr');
    const btn = document.getElementById('pwConfirmBtn');
    if(!pw){ err.textContent = 'Password is required.'; err.style.display = 'block'; return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Decrypting…';
    err.style.display = 'none';

    (async ()=>{
        let ok = true;
        for(const f of pw_files){
            const res = await post({ action:'decrypt_file', doc_id:f.id, password:pw, session_id:SESSION_ID });
            if(!res.success){
                err.textContent = res.message;
                err.style.display = 'block';
                ok = false;
                break;
            }
            openedDocs[f.id] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-unlock-fill"></i> Open Files';

        if(ok){
            closePw();
            _markFilesOpened(pw_bidderId, pw_bidId, pw_lotId, pw_stage, pw_files);
        }
    })();
}

// Mark files as opened in UI and DB
function _markFilesOpened(bidderId, bidId, lotId, stage, files){
    const openBtn = document.getElementById(`btn-open-files-${bidderId}`);
    if(openBtn){
        openBtn.disabled = true;
        openBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Opened';
        openBtn.style.background = '#e4f5ea';
        openBtn.style.color = '#1f7a3d';
    }
    files.forEach(f=>{
        const b = document.getElementById('vbtn-'+f.id);
        if(b){ b.classList.add('active'); b.title = 'View document'; }
    });
    const chkBtn = document.getElementById(`btn-chk-${bidderId}`);
    if(chkBtn) chkBtn.classList.add('ready');
    post({ action:'set_lot_opened', bid_id:bidId, lot_id:lotId, phase:stage, session_id:SESSION_ID });
}

function viewDoc(docId){
    const e = openedDocs[docId];
    if(!e) return;
    document.getElementById('fvTitle').textContent = e.file_name || 'Document';
    const c = document.getElementById('fvContent');
    c.innerHTML = '';

    const [header, b64] = e.data_url.split(',');
    const binary = atob(b64);
    const bytes  = new Uint8Array(binary.length);
    for(let i=0; i<binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const blob   = new Blob([bytes], { type: e.mime });
    const objUrl = URL.createObjectURL(blob);

    if(e.mime === 'application/pdf'){
        const ifr = document.createElement('iframe');
        ifr.src   = objUrl;
        ifr.style.cssText = 'width:100%;height:100%;min-height:80vh;border:none;border-radius:8px;';
        c.appendChild(ifr);
    } else if(e.mime.startsWith('image/')){
        const img = document.createElement('img');
        img.src   = objUrl;
        img.style.cssText = 'max-width:100%;max-height:80vh;border-radius:8px;object-fit:contain;';
        c.appendChild(img);
    } else {
        const a = document.createElement('a');
        a.href  = objUrl;
        a.download = e.file_name;
        a.className = 'btn-confirm';
        a.style.cssText = 'display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:10px;text-decoration:none;';
        a.innerHTML = '<i class="bi bi-download"></i> Download File';
        c.style.justifyContent = 'center';
        c.appendChild(a);
    }

    document.getElementById('fvOverlay').classList.add('open');
}
function closeFv(){
    document.getElementById('fvOverlay').classList.remove('open');
    document.getElementById('fvContent').innerHTML = '';
}

// ── Checklist Modal ───────────────────────────────────────────────────────
function openEligModal(bidId, name, stage, lotId, bidderId){
    const ck = document.getElementById(`btn-chk-${bidderId}`);
    if(ck && !ck.classList.contains('ready')) return;

    el_bidId      = bidId;
    el_bidderName = name;
    el_phase      = stage;
    el_lotId      = lotId;
    el_bidderId   = bidderId;

    document.getElementById('eligName').textContent = name;
    document.getElementById('eligModalTitle').innerHTML = `<i class="bi bi-clipboard-check" style="color:#ffc107"></i> ${stage==='eligibility'?'Eligibility & Technical':'Financial'} Evaluation`;
    document.getElementById('eligModalSub').textContent = `Mark compliance for ${name}.`;
    document.getElementById('eligModal').classList.add('open');
}
function closeElig(){ document.getElementById('eligModal').classList.remove('open'); }

function submitElig(eligible){
    closeElig();
    post({ action:'set_eligible', bid_id:el_bidId, lot_id:el_lotId, eligible, phase:el_phase, session_id:SESSION_ID })
    .then(d=>{
        if(!d.success){ alert(d.message); return; }

        const lotState = STATE.lots[el_lotId];
        if(lotState){
            if(!lotState.done[el_phase]) lotState.done[el_phase] = [];
            lotState.done[el_phase].push(el_bidderId);
        }

        // Re-render bidders for this stage
        fetchAndRenderBidders(el_lotId, el_phase);
        refreshSessionProgressFromDB();
    });
}

// ── Cannot Proceed Modal ──────────────────────────────────────────────────
function openCannotProceedModal(title, items){
    document.getElementById('cannotProceedTitle').textContent = title;
    const list = document.getElementById('cannotProceedList');
    list.innerHTML = '';
    items.forEach(item => {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:8px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:8px 12px;font-size:12px;color:#9a3412;font-weight:600';
        row.innerHTML = `<i class="bi bi-x-circle-fill" style="color:#dc2626;flex-shrink:0"></i>${item}`;
        list.appendChild(row);
    });
    document.getElementById('cannotProceedModal').classList.add('open');
}
function closeCannotProceedModal(){ document.getElementById('cannotProceedModal').classList.remove('open'); }

// ── Done Opening Lot #[N] Modal & Flow ─────────────────────────────────────
function openDoneLotModal(){
    const lot = LOTS[STATE.activeLotIdx];
    if(!lot) return;

    // Always fetch fresh from DB — never rely on what's cached in STATE
    // This is the only way to be truly DB-dependent when clicking Done
    Promise.all([
        get({ action:'bidders', lot_id:lot.id, phase:'eligibility', session_id:SESSION_ID }),
        get({ action:'bidders', lot_id:lot.id, phase:'financial',   session_id:SESSION_ID })
    ]).then(([ed, fd]) => {
        if(!STATE.lots[lot.id]) STATE.lots[lot.id] = { stage:'eligibility', bidders:{}, done:{ eligibility:[], financial:[] } };
        STATE.lots[lot.id].bidders['eligibility'] = ed.bidders || [];
        STATE.lots[lot.id].bidders['financial']   = fd.bidders || [];

        const eligBidders = ed.bidders || [];
        const finBidders  = fd.bidders || [];
        const issues = [];

        // Check eligibility: any bidder still pending/opened
        const pendingElig = eligBidders.filter(b => ['pending','opened'].includes(b.eligibility_status));
        if(pendingElig.length > 0){
            issues.push(`${pendingElig.length} bidder${pendingElig.length>1?'s':''} still pending Eligibility & Technical evaluation`);
        }

        // Check financial: eligible bidders still pending/opened (disqualified elig → skip)
        const pendingFin = finBidders.filter(b => {
            if(b.eligibility_status === 'disqualified') return false;
            return ['pending','opened'].includes(b.financial_status);
        });
        if(pendingFin.length > 0){
            issues.push(`${pendingFin.length} bidder${pendingFin.length>1?'s':''} still pending Financial Proposal evaluation`);
        }

        if(issues.length > 0){
            openCannotProceedModal(`Lot ${lot.lot_number} is not ready to finalize`, issues);
            return;
        }

        document.getElementById('doneLotModalName').textContent = `Lot ${lot.lot_number}`;
        const nextIdx = STATE.activeLotIdx + 1;
        const promptEl = document.getElementById('doneLotModalPrompt');
        if(nextIdx < LOTS.length){
            promptEl.textContent = `This will conclude Lot ${lot.lot_number} and proceed to Lot ${LOTS[nextIdx].lot_number}.`;
        } else {
            promptEl.textContent = `This is the final lot! You will proceed directly to the Awarding tab.`;
        }
        document.getElementById('doneLotModal').classList.add('open');
    })
    .catch(()=>{
        openCannotProceedModal('Error', ['Failed to verify lot status. Please try again.']);
    });
}
function closeDoneLotModal(){ document.getElementById('doneLotModal').classList.remove('open'); }

function confirmDoneLot(){
    closeDoneLotModal();
    const curIdx = STATE.activeLotIdx;
    const curLot = LOTS[curIdx];
    if(!curLot) return;

    if(!STATE.doneLotIndices.includes(curIdx)){
        STATE.doneLotIndices.push(curIdx);
    }

    // Mark current master tab as done and lock it
    const tabEl = document.getElementById('mtab-'+curIdx);
    if(tabEl){
        tabEl.classList.add('done');
        tabEl.classList.remove('active');
        const badge = document.getElementById('mtab-badge-'+curIdx);
        if(badge){ badge.className = 'mtab-badge done'; badge.innerHTML = '<i class="bi bi-check2"></i> Done'; }
    }

    const nextIdx = curIdx + 1;
    if(nextIdx < LOTS.length){
        // Unlock next lot tab and advance
        const nextTab = document.getElementById('mtab-'+nextIdx);
        if(nextTab) nextTab.classList.remove('locked');
        STATE.activeLotIdx = nextIdx;
        // Reset status to 'started' so the eligibility gate shows for the new lot
        SESSION_STATUS = 'started';
        post({ action:'set_current_lot', session_id:SESSION_ID, lot_id:LOTS[nextIdx].id })
        .then(()=> post({ action:'start_phase', session_id:SESSION_ID, phase:'started' }))
        .then(()=>{ refreshSessionProgressFromDB(); });
        switchMasterTab(nextIdx);
    } else {
        // All lots done — go to awarding
        const awardTab = document.getElementById('mtab-awarding');
        if(awardTab) awardTab.classList.remove('locked');
        STATE.activeLotIdx = -1; // no active lot
        post({ action:'set_current_lot', session_id:SESSION_ID, lot_id:0 });
        post({ action:'start_phase', session_id:SESSION_ID, phase:'awarding' })
        .then(()=>{ refreshSessionProgressFromDB(); });
        switchMasterTab('awarding');
    }
}

// ── Awarding Phase ────────────────────────────────────────────────────────
function loadAwarding(){
    const list = document.getElementById('awarding-lots-list');
    list.innerHTML = '<div style="padding:24px;display:flex;flex-direction:column;gap:12px"><div class="skel" style="height:60px"></div><div class="skel" style="height:60px"></div></div>';

    Promise.all([
        get({ action:'get_awards', proc_id:PROC_ID, session_id:SESSION_ID }),
        Promise.all(LOTS.map(lot => get({ action:'bidders', lot_id:lot.id, phase:'financial', session_id:SESSION_ID })))
    ]).then(([aData, allBiddersData])=>{
        const awards     = aData.awards      || [];
        const failedIds  = (aData.failed_lots || []).map(f => parseInt(f.lot_id));
        STATE.awards = awards;
        list.innerHTML = '';

        LOTS.forEach((lot, i)=>{
            const awarded = awards.find(a => parseInt(a.lot_id) === parseInt(lot.id));
            const failed  = !awarded && failedIds.includes(parseInt(lot.id));
            const bidders = (allBiddersData[i] && allBiddersData[i].bidders) ? allBiddersData[i].bidders : [];

            const card = mkEl('div','award-lot-card');

            // ── Card head ──────────────────────────────────────────────
            const head = mkEl('div','award-lot-head');
            let headStatus = '';
            if(awarded)      headStatus = '<span class="award-winner-badge"><i class="bi bi-trophy-fill"></i> Awarded</span>';
            else if(failed)  headStatus = '<span class="status-pill sp-failed"><i class="bi bi-x-circle-fill"></i> Failed / No Award</span>';
            else             headStatus = '<span style="font-size:11px;font-weight:700;color:#88968d">Select winning bidder below</span>';

            head.innerHTML = `
                <div>
                    <div class="award-lot-title">Lot ${esc(lot.lot_number)}${lot.lot_title ? ' · '+esc(lot.lot_title) : ''}</div>
                    <div style="font-size:11.5px;color:#55665a">Approved Budget: <strong style="color:#1f7a3d;font-family:'Space Grotesk',sans-serif">₱${parseFloat(lot.abc||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</strong></div>
                </div>
                ${headStatus}
            `;
            card.appendChild(head);

            // ── Card body ──────────────────────────────────────────────
            if(awarded){
                const box   = mkEl('div','awarded-box');
                const bName = awarded.business_name || (awarded.firstname + ' ' + awarded.lastname);
                box.innerHTML = `
                    <div class="awarded-box-left">
                        <div class="awarded-box-icon"><i class="bi bi-trophy-fill"></i></div>
                        <div>
                            <div class="awarded-box-winner"><i class="bi bi-building"></i> ${esc(bName)}</div>
                            <div class="awarded-box-amount">Awarded Amount: ₱${parseFloat(awarded.awarded_amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})} · Date: ${esc(awarded.award_date_fmt||'Today')}</div>
                        </div>
                    </div>
                `;
                card.appendChild(box);

            } else if(failed){
                const box = mkEl('div','failed-box');
                box.innerHTML = `
                    <div class="failed-box-icon"><i class="bi bi-x-lg"></i></div>
                    <div>
                        <div class="failed-box-label">Lot ${esc(lot.lot_number)} — No Award</div>
                        <div class="failed-box-sub">This lot has been marked as failed. No winner was declared.</div>
                    </div>
                `;
                card.appendChild(box);

            } else if(!bidders.length){
                card.innerHTML += '<div class="p-empty" style="padding:20px"><i class="bi bi-inbox" style="font-size:22px"></i>No submitted bids for this lot.</div>';

            } else {
                const bList = mkEl('div','award-bidders-list');
                bidders.forEach(b=>{
                    const isDisq = !!b.disqualified;
                    const bName  = b.business_name || (b.firstname + ' ' + b.lastname);
                    const ini    = (b.firstname||'').charAt(0).toUpperCase() + (b.lastname||'').charAt(0).toUpperCase();

                    const bCard = mkEl('div','award-bidder-card' + (isDisq ? ' disqualified' : ''));
                    let actionHtml = '';

                    if(isDisq){
                        actionHtml = `<span class="disq-badge" style="font-size:11px;padding:3px 8px"><i class="bi bi-x-circle-fill"></i> Ineligible / Disqualified</span>`;
                    } else if(CAN_MANAGE){
                        actionHtml = `
                            <div class="award-amount-wrap">
                                ₱ <input type="number" step="0.01" class="award-amount-input" id="award-amt-${lot.id}-${b.bid_lot_id}" value="${parseFloat(lot.abc||0).toFixed(2)}" placeholder="Amount">
                            </div>
                            <button class="btn-select-winner" onclick="openAwardModal(${lot.id}, ${b.bid_lot_id}, '${esc(bName).replace(/'/g,"\\'")}', ${lot.lot_number}, document.getElementById('award-amt-${lot.id}-${b.bid_lot_id}').value)">
                                <i class="bi bi-trophy-fill"></i> Declare Winner
                            </button>
                        `;
                    } else {
                        actionHtml = `<span class="status-pill sp-active"><i class="bi bi-check2"></i> Qualified</span>`;
                    }

                    bCard.innerHTML = `
                        <div class="award-bidder-info">
                            <div class="bt-avatar">${b.avatar ? `<img src="../${esc(b.avatar)}" alt="">` : esc(ini)}</div>
                            <div>
                                <div style="font-size:13px;font-weight:700;color:#06251b">${esc(bName)}</div>
                                <div style="font-size:11px;color:#88968d">Bid Ref #${esc(b.bid_id)} · ${esc(b.submission_date||'')}</div>
                            </div>
                        </div>
                        <div class="award-bidder-actions">${actionHtml}</div>
                    `;
                    bList.appendChild(bCard);
                });
                card.appendChild(bList);

                // "Mark as Failed" footer — only for managers when lot is not yet resolved
                if(CAN_MANAGE){
                    const failBar = mkEl('div','');
                    failBar.style.cssText = 'padding:10px 18px;border-top:1px solid #fef2f2;background:#fffafa;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap';
                    failBar.innerHTML = `
                        <span style="font-size:11.5px;color:#88968d">No qualified bidder? Mark this lot as failed instead.</span>
                        <button class="btn-fail-lot" onclick="openFailLotModal(${lot.id}, ${lot.lot_number})">
                            <i class="bi bi-x-circle-fill"></i> Mark as Failed / No Award
                        </button>
                    `;
                    card.appendChild(failBar);
                }
            }

            list.appendChild(card);
        });
    }).catch(()=>{
        list.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Failed to load awarding details.</div>';
    });
}

function openAwardModal(lotId, bidLotId, bidderName, lotNumber, amount){
    _awardTarget = { lotId, bidLotId, bidderName, lotNumber, amount: parseFloat(amount||0) };
    document.getElementById('awardLotTitle').textContent = 'Lot ' + lotNumber;
    document.getElementById('awardBidderName').textContent = bidderName;
    document.getElementById('awardDisplayAmount').textContent = '₱' + parseFloat(amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('awardModal').classList.add('open');
}
function closeAwardModal(){
    document.getElementById('awardModal').classList.remove('open');
    _awardTarget = null;
}

// ── Fail Lot Modal ────────────────────────────────────────────────────────
let _failTarget = null;
function openFailLotModal(lotId, lotNumber){
    _failTarget = { lotId, lotNumber };
    document.getElementById('failLotTitle').textContent = 'Lot ' + lotNumber;
    document.getElementById('failLotModal').classList.add('open');
}
function closeFailLotModal(){
    document.getElementById('failLotModal').classList.remove('open');
    _failTarget = null;
}
function doFailLot(){
    if(!_failTarget) return;
    const btn = document.getElementById('failLotConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Recording…';
    post({ action:'fail_lot', session_id:SESSION_ID, lot_id:_failTarget.lotId })
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Confirm — No Award';
        if(!d.success){ alert(d.message||'Failed to record.'); return; }
        closeFailLotModal();
        loadAwarding();
        refreshSessionProgressFromDB();
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-x-circle-fill"></i> Confirm — No Award'; });
}

function doAwardConfirm(){
    if(!_awardTarget) return;
    const btn = document.getElementById('awardConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Recording…';

    post({
        action: 'award_lot',
        session_id: SESSION_ID,
        lot_id: _awardTarget.lotId,
        bid_lot_id: _awardTarget.bidLotId,
        awarded_amount: _awardTarget.amount
    }).then(d=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trophy-fill"></i> Confirm Award';
        if(!d.success){ alert(d.message||'Failed to award lot.'); return; }
        closeAwardModal();
        loadAwarding();
        refreshSessionProgressFromDB();
    }).catch(()=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trophy-fill"></i> Confirm Award';
        alert('Network error.');
    });
}

// ── Conclude & End Session ────────────────────────────────────────────────
function openEndSessionModal(){
    // Guard: all lots must be awarded before ending
    get({ action:'get_awards', proc_id:PROC_ID, session_id:SESSION_ID })
    .then(d=>{
        const awards      = d.awards      || [];
        const failedLots  = (d.failed_lots || []).map(f => parseInt(f.lot_id));
        // A lot is resolved if it has an award OR has been marked as failed
        const unresolvedLots = LOTS.filter(lot =>
            !awards.find(a => parseInt(a.lot_id) === parseInt(lot.id)) &&
            !failedLots.includes(parseInt(lot.id))
        );
        if(unresolvedLots.length > 0){
            const items = unresolvedLots.map(l => `Lot ${l.lot_number} — no winner declared and not marked as failed`);
            openCannotProceedModal('Cannot conclude session yet', items);
            return;
        }
        document.getElementById('endSessionModal').classList.add('open');
    })
    .catch(()=>{ openCannotProceedModal('Error', ['Failed to verify award status. Please try again.']); });
}
function closeEndSessionModal(){ document.getElementById('endSessionModal').classList.remove('open'); }

function doEndSession(){
    const btn = document.getElementById('endSessionConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Concluding…';

    post({
        action: 'end_session',
        session_id: SESSION_ID,
        proc_id: PROC_ID
    }).then(d=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> Yes, End Session';
        if(!d.success){ alert(d.message||'Failed to end session.'); return; }
        closeEndSessionModal();
        SESSION_STATUS = 'ended';
        // Unlock conclusion tab, lock awarding tab, navigate to conclusion
        const cTab = document.getElementById('mtab-conclusion');
        if(cTab) cTab.classList.remove('locked');
        const aTab = document.getElementById('mtab-awarding');
        if(aTab) aTab.classList.add('locked');
        switchMasterTab('conclusion');
    }).catch(()=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> Yes, End Session';
        alert('Network error.');
    });
}

function mkEl(tag,cls){ const e=document.createElement(tag); if(cls) e.className=cls; return e; }

// ── Conclusion Tab ────────────────────────────────────────────────────────
function loadConclusion(){
    const body = document.getElementById('conclusion-body');
    if(!body) return;
    body.innerHTML = '<div style="display:flex;flex-direction:column;gap:10px"><div class="skel" style="height:80px;border-radius:12px"></div><div class="skel" style="height:140px;border-radius:12px"></div><div class="skel" style="height:140px;border-radius:12px"></div></div>';

    Promise.all([
        get({ action:'progress',   session_id:SESSION_ID }),
        get({ action:'get_awards', proc_id:PROC_ID, session_id:SESSION_ID })
    ]).then(([prog, aData])=>{
        const awards     = aData.awards      || [];
        const failedLots = (aData.failed_lots || []).map(f=>parseInt(f.lot_id));

        const startedAt = prog.started_at || '—';
        const endedAt   = prog.ended_at   || '—';

        // Duration
        let durationHtml = '—';
        if(prog.started_at && prog.ended_at){
            const ms  = new Date(prog.ended_at) - new Date(prog.started_at);
            const h   = Math.floor(ms/3600000);
            const m   = Math.floor((ms%3600000)/60000);
            const s   = Math.floor((ms%60000)/1000);
            durationHtml = [h?h+'h':'', m?m+'m':'', s+'s'].filter(Boolean).join(' ');
        }

        const fmtDate = str => {
            if(!str || str==='—') return '—';
            const d = new Date(str);
            return d.toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'})
                 + ' · ' + d.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});
        };

        // Session info card
        let html = `
        <div style="background:#f8faf9;border:1px solid #eaeeec;border-radius:14px;padding:18px 20px;margin-bottom:14px">
            <div style="font-size:12px;font-weight:800;color:#88968d;letter-spacing:.06em;text-transform:uppercase;margin-bottom:12px">Session Info</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div>
                    <div style="font-size:11px;color:#88968d;font-weight:600;margin-bottom:3px"><i class="bi bi-play-circle-fill" style="color:#1f7a3d"></i> Started</div>
                    <div style="font-size:13px;font-weight:700;color:#06251b">${esc(fmtDate(startedAt))}</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#88968d;font-weight:600;margin-bottom:3px"><i class="bi bi-stop-circle-fill" style="color:#dc2626"></i> Concluded</div>
                    <div style="font-size:13px;font-weight:700;color:#06251b">${esc(fmtDate(endedAt))}</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#88968d;font-weight:600;margin-bottom:3px"><i class="bi bi-clock-fill" style="color:#f59e0b"></i> Duration</div>
                    <div style="font-size:13px;font-weight:700;color:#06251b">${durationHtml}</div>
                </div>
            </div>
        </div>`;

        // Lots result cards
        html += `<div style="font-size:12px;font-weight:800;color:#88968d;letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px">Lot Results</div>`;
        html += `<div style="display:flex;flex-direction:column;gap:10px">`;

        LOTS.forEach(lot => {
            const awarded    = awards.find(a => parseInt(a.lot_id) === parseInt(lot.id));
            const isFailed   = failedLots.includes(parseInt(lot.id));
            const lotName    = `Lot ${lot.lot_number}${lot.lot_title ? ' · '+lot.lot_title : ''}`;

            if(awarded){
                const bName = awarded.business_name || (awarded.firstname+' '+awarded.lastname);
                const amt   = parseFloat(awarded.awarded_amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
                html += `
                <div style="border:1.5px solid #b2dfdb;border-radius:12px;padding:14px 18px;background:#f0fdfa;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:12px">
                        <div style="width:36px;height:36px;border-radius:10px;background:#00796b;color:#ffc107;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0"><i class="bi bi-trophy-fill"></i></div>
                        <div>
                            <div style="font-size:12px;font-weight:800;color:#06251b">${esc(lotName)}</div>
                            <div style="font-size:12.5px;font-weight:700;color:#00796b;margin-top:2px"><i class="bi bi-building"></i> ${esc(bName)}</div>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-size:11px;color:#88968d;font-weight:600">Awarded Amount</div>
                        <div style="font-size:14px;font-weight:800;color:#1f7a3d;font-family:'Space Grotesk',sans-serif">₱${amt}</div>
                        <div style="font-size:10.5px;color:#88968d">${esc(awarded.award_date_fmt||'')}</div>
                    </div>
                </div>`;
            } else if(isFailed){
                html += `
                <div style="border:1.5px solid #fecaca;border-radius:12px;padding:14px 18px;background:#fef2f2;display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:10px;background:#dc2626;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0"><i class="bi bi-x-lg"></i></div>
                    <div>
                        <div style="font-size:12px;font-weight:800;color:#991b1b">${esc(lotName)}</div>
                        <div style="font-size:11.5px;color:#ef4444;font-weight:600;margin-top:2px">No Award — Lot Failed</div>
                    </div>
                </div>`;
            } else {
                html += `
                <div style="border:1.5px solid #e5e7eb;border-radius:12px;padding:14px 18px;background:#f9fafb;display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:10px;background:#e5e7eb;color:#9ca3af;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0"><i class="bi bi-dash"></i></div>
                    <div>
                        <div style="font-size:12px;font-weight:800;color:#374151">${esc(lotName)}</div>
                        <div style="font-size:11.5px;color:#9ca3af;font-weight:600;margin-top:2px">No result recorded</div>
                    </div>
                </div>`;
            }
        });

        html += `</div>`;

        // Summary counts
        const awardedCount = awards.length;
        const failedCount  = failedLots.length;
        const totalLots    = LOTS.length;
        html += `
        <div style="margin-top:14px;background:#06251b;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
            <div style="flex:1;min-width:80px;text-align:center">
                <div style="font-size:22px;font-weight:800;color:#ffc107;font-family:'Space Grotesk',sans-serif">${totalLots}</div>
                <div style="font-size:11px;color:#a8c5ae;font-weight:600;margin-top:2px">Total Lots</div>
            </div>
            <div style="flex:1;min-width:80px;text-align:center">
                <div style="font-size:22px;font-weight:800;color:#4ade80;font-family:'Space Grotesk',sans-serif">${awardedCount}</div>
                <div style="font-size:11px;color:#a8c5ae;font-weight:600;margin-top:2px">Awarded</div>
            </div>
            <div style="flex:1;min-width:80px;text-align:center">
                <div style="font-size:22px;font-weight:800;color:#f87171;font-family:'Space Grotesk',sans-serif">${failedCount}</div>
                <div style="font-size:11px;color:#a8c5ae;font-weight:600;margin-top:2px">Failed / No Award</div>
            </div>
        </div>`;

        body.innerHTML = html;
    }).catch(()=>{
        body.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Failed to load session summary.</div>';
    });
}

// ── Initialization ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', ()=>{
    refreshSessionProgressFromDB();
    if(SESSION_STATUS === 'ended'){
        // Unlock all lot tabs as done, unlock awarding + conclusion, go straight to conclusion
        LOTS.forEach((_, i)=>{
            const t = document.getElementById('mtab-'+i);
            if(t){ t.classList.remove('locked'); t.classList.add('done'); }
        });
        const aTab = document.getElementById('mtab-awarding');
        if(aTab) aTab.classList.remove('locked');
        const cTab = document.getElementById('mtab-conclusion');
        if(cTab) cTab.classList.remove('locked');
        // Lock awarding — session is concluded, conclusion tab is the final destination
        if(aTab) aTab.classList.add('locked');
        switchMasterTab('conclusion');
    } else if(SESSION_STATUS === 'awarding'){
        // Unlock all lot tabs as done, unlock awarding tab, go to awarding
        LOTS.forEach((_, i)=>{
            const t = document.getElementById('mtab-'+i);
            if(t){ t.classList.remove('locked'); t.classList.add('done'); }
        });
        const aTab = document.getElementById('mtab-awarding');
        if(aTab) aTab.classList.remove('locked');
        switchMasterTab('awarding');
    } else {
        // Session in progress — determine active lot from DB current_lot_id
        let initialIdx = 0;
        if(CURRENT_LOT_ID > 0){
            const found = LOTS.findIndex(l=>parseInt(l.id) === CURRENT_LOT_ID);
            if(found >= 0) initialIdx = found;
        }

        LOTS.forEach((lot, i)=>{
            const t = document.getElementById('mtab-'+i);
            if(!t) return;
            if(i < initialIdx){
                t.classList.remove('locked');
                t.classList.add('done');
                const b = document.getElementById('mtab-badge-'+i);
                if(b){ b.className='mtab-badge done'; b.innerHTML='<i class="bi bi-check2"></i> Done'; }
            } else if(i === initialIdx){
                t.classList.remove('locked');
                t.classList.add('active');
            } else {
                t.classList.add('locked');
                t.classList.remove('active','done');
            }
        });

        const aTab = document.getElementById('mtab-awarding');
        if(aTab) aTab.classList.add('locked');

        STATE.activeLotIdx = initialIdx;
        STATE.currentTab   = initialIdx;

        if(LOTS.length > 0){
            switchMasterTab(initialIdx);
        } else {
            switchMasterTab('awarding');
        }
    }
});
</script>

</body>
</html>
