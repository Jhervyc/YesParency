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
           bos.current_lot_id, bos.signing_status,
           p.id AS proc_id, p.title AS proc_title, p.slsu_ref_no,
           p.abc, p.procurement_mode,
           COALESCE(p.procurement_type, 'goods_services') AS procurement_type,
           p.opening_date, p.closing_date
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
$signing_st     = $session['signing_status'] ?? 'not_started';
$current_lot_id = (int)($session['current_lot_id'] ?? 0);

$lots_stmt = $conn->prepare("
    SELECT
        l.id, l.lot_number, l.lot_title, l.abc,
        COUNT(bl.bid_id) AS total_bids,
        COUNT(CASE WHEN bl.eligibility_status IN ('pending','opened') THEN 1 END) AS pending_elig,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' THEN 1 END) AS eligible_bids,
        COUNT(CASE WHEN bl.eligibility_status = 'disqualified' THEN 1 END) AS disq_elig,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' AND bl.financial_status IN ('pending','opened') THEN 1 END) AS pending_fin,
        COUNT(CASE WHEN bl.eligibility_status = 'eligible' AND bl.financial_status IN ('qualified','non_compliant') THEN 1 END) AS done_fin
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

// A lot is only "done" once the admin has explicitly submitted Done for it
// (which advances current_lot_id past it) — never just inferred from having
// zero bids or nothing left pending, since that would mark bid-less lots
// done before the session ever reaches them.
$current_lot_number = null;
if ($current_lot_id > 0) {
    foreach ($lots as $l) {
        if ((int)$l['id'] === $current_lot_id) { $current_lot_number = (int)$l['lot_number']; break; }
    }
}

$all_lots_done = !empty($lots);
foreach ($lots as &$l) {
    if ($st === 'ended') {
        $l['is_done'] = true;
    } elseif ($current_lot_id === 0) {
        // No active lot pointer: either the session hasn't started yet
        // (nothing done) or every lot has already been explicitly
        // completed and we're waiting on the "End Session" confirmation.
        $l['is_done'] = ($st !== 'scheduled');
    } else {
        $l['is_done'] = ((int)$l['lot_number'] < $current_lot_number);
    }
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
} elseif ($st === 'offered') {
    $initial_activity_text = "Recording offered bid amounts before concluding the session.";
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
<link rel="stylesheet" href="../dashboard.css">
<link rel="stylesheet" href="../css/dashboard-shell.css">
<link rel="stylesheet" href="../css/responsive.css">
<link rel="stylesheet" href="../css/pages/admin-bid-session.css">
<!-- Pusher JS SDK -->
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
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
        <span class="t-dark"><?= htmlspecialchars(mb_strimwidth($session['proc_title'],0,40,'…')) ?></span>
    </div>
    <a href="bid_opening.php" class="vp-back-link"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="bs-layout">

<!-- ════ LEFT ════ -->
<div>

<!-- Procurement hero -->
<div class="proc-hero">
    <div class="proc-badges">
        <span class="ipill ref"><i class="bi bi-hash"></i><?= htmlspecialchars($session['slsu_ref_no']) ?></span>
        <?php if ($session['procurement_mode']): ?>
        <span class="ipill mode"><i class="bi bi-tag"></i><?= htmlspecialchars($session['procurement_mode']) ?></span>
        <?php endif; ?>
        <span class="ipill <?= in_array($st,['eligibility','financial','started','offered']) ? 'live' : 'sched' ?>" id="heroStatusPill">
            <i class="bi bi-<?= in_array($st,['eligibility','financial','started','offered']) ? 'broadcast' : 'clock' ?>"></i>
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
        <div class="met"><div class="met-lbl"><i class="bi bi-clock"></i> Started</div><div class="met-val fz-12"><?= $session['started_at'] ? date('g:i A',strtotime($session['started_at'])) : '—' ?></div></div>
    </div>
    <?php if ($can_manage && $st === 'scheduled'): ?>
    <div class="mt-14 flex-end" id="heroStartWrap">
        <button class="btn-confirm btn-confirm--gold-lg" id="btn-start-session" onclick="openStartSessionModal()">
            <i class="bi bi-play-circle-fill"></i> Start Session
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- MASTER TABS: Lot 1 -> Lot 2 -> ... -> Concluded -->
<div class="master-tabs-wrap" id="masterTabs">
    <?php foreach ($lots as $i => $lot): ?>
    <div class="master-tab <?= $lot['is_done'] ? 'done' : ($i===0 ? 'active' : 'locked') ?>" id="mtab-<?= $i ?>" onclick="onMasterTabClick(<?= $i ?>)">
        <span class="mtab-num"><?= $lot['lot_number'] ?></span>
        <span>Lot <?= $lot['lot_number'] ?></span>
    </div>
    <?php endforeach; ?>
    <div class="master-tab <?= ($st !== 'ended' && ($all_lots_done || $st === 'offered')) ? '' : 'locked' ?> <?= $st === 'offered' ? 'active' : '' ?>" id="mtab-offered" onclick="onMasterTabClick('offered')">
        <i class="bi bi-cash-coin t-gold"></i>
        <span>Offered Bids</span>
    </div>
    <div class="master-tab <?= $st === 'ended' ? '' : 'locked' ?>" id="mtab-conclusion" onclick="onMasterTabClick('conclusion')">
        <i class="bi bi-flag-fill t-forest"></i>
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
                    <i class="bi bi-layers-fill t-forest"></i>
                    <span>Lot 1</span>
                </div>
                <div class="lot-head-abc" id="lotHeadAbc">Approved Budget for Contract: <strong>₱0.00</strong></div>
            </div>
            <div id="lotStagePills" class="flexc gap-6">
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
        <div id="startEligibilityGate" class="hide gate-panel gate-panel--ready">
            <div class="notice-icon notice-icon--xl notice-icon--forest-lt">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="fz-15 fw-8 t-dark mb-6">Ready to begin Eligibility Review?</div>
            <div class="gate-desc-centered">
                Click the button below to officially start the Eligibility &amp; Technical evaluation phase. This will be recorded in the system.
            </div>
            <button id="btnStartEligibility" onclick="openStartEligibilityModal()"
                class="btn-begin-eligibility">
                <i class="bi bi-play-circle-fill"></i> Begin Eligibility &amp; Technical Phase
            </button>
        </div>
        <?php else: ?>
        <div id="startEligibilityGate" class="hide gate-panel gate-panel--waiting">
            <div class="banner-box banner-box--amber">
                <div class="notice-icon notice-icon--lg notice-icon--amber">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="fz-13-5 fw-8 t-warndark">Waiting for Eligibility Phase to Begin</div>
                    <div class="fz-12 t-warnmid mt-2">The Secretariat will officially open the Eligibility &amp; Technical review. Please stand by.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bidder Area (Tabs, Action Bar, Files Pane) -->
        <div id="lot-bidder-area">
            <div class="pad-24 flexr gap-8">
                <div class="skel h-32 w-120 r-20"></div>
                <div class="skel h-32 w-120 r-20"></div>
            </div>
        </div>

        <!-- Lot Footer Action Bar (Done Opening Lot #[N]) -->
        <?php if ($can_manage): ?>
        <div class="lot-footer-bar" id="lotFooterBar">
            <div class="lot-footer-info">
                <i class="bi bi-info-circle-fill t-teal"></i>
                <span>Complete review of bidders before finalizing this lot.</span>
            </div>
            <button class="btn-confirm-lot" id="btnDoneLot" onclick="openDoneLotModal()">
                <i class="bi bi-check2-circle"></i> Done Opening Lot #<span id="btnDoneLotNum">1</span>
            </button>
        </div>
        <?php else: ?>
        <div class="lot-footer-bar" id="lotFooterBar">
            <div class="lot-footer-info">
                <i class="bi bi-info-circle-fill t-teal"></i>
                <span>Review in progress — waiting for Secretariat to finalize this lot.</span>
            </div>
            <span class="status-chip status-chip--amber status-chip--lg">
                <i class="bi bi-hourglass-split"></i> Waiting for Secretariat…
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ OFFERED BIDS VIEW ══ -->
<div id="offered-bids-container" class="hide">

    <?php if (!$can_manage): ?>
    <div class="banner-box banner-box--amber mb-16">
        <div class="notice-icon notice-icon--lg notice-icon--amber">
            <i class="bi bi-hourglass-split"></i>
        </div>
        <div>
            <div class="fz-13-5 fw-8 t-warndark">Recording Offered Bids</div>
            <div class="fz-12 t-warnmid mt-2">The Secretariat is recording each bidder's offered amount before concluding the session.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="award-panel">
        <div class="award-head">
            <div class="flexc gap-10">
                <div class="notice-icon notice-icon--fs18 notice-icon--teal-lt">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <div>
                    <div class="fz-15 fw-8 t-dark">Offered Bids</div>
                    <div class="fz-11-5 t-muted">Record each eligible bidder's total offered bid amount per lot</div>
                </div>
            </div>
        </div>

        <div id="offered-bids-list">
            <div class="pad-24 flexcol gap-12">
                <div class="skel h-60"></div>
                <div class="skel h-60"></div>
            </div>
        </div>

        <?php if ($can_manage): ?>
        <div class="panel-footer panel-footer--end">
            <button class="btn-end-session" onclick="openEndSessionModal()">
                <i class="bi bi-check2-all"></i> Conclude &amp; End Session
            </button>
        </div>
        <?php else: ?>
        <div class="panel-footer panel-footer--end">
            <span class="status-chip status-chip--amber status-chip--lg">
                <i class="bi bi-hourglass-split"></i> Waiting for Secretariat to conclude…
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CONCLUSION VIEW ══ -->
<div id="conclusion-container" class="hide">
    <div class="award-panel">
        <div class="award-head">
            <div class="flexc gap-10">
                <div class="notice-icon notice-icon--fs18 notice-icon--forest-lt">
                    <i class="bi bi-flag-fill"></i>
                </div>
                <div>
                    <div class="fz-15 fw-8 t-dark">Session Concluded</div>
                    <div class="fz-11-5 t-muted">Official summary of this bid opening session</div>
                </div>
            </div>
            <span class="status-pill sp-active status-pill--ended"><i class="bi bi-check2-all"></i> Ended</span>
        </div>
        <div id="conclusion-body" class="pad-20">
            <div class="flexcol gap-8">
                <div class="skel h-80 r-12"></div>
                <div class="skel h-120 r-12"></div>
                <div class="skel h-120 r-12"></div>
            </div>
        </div>
        <div class="panel-footer panel-footer--between">
            <?php if ($can_manage): ?>
            <a href="checklist_pdf.php?session=<?= (int)$session_id ?>" target="_blank"
               class="modal-cta-link modal-cta-link--blue">
                <i class="bi bi-file-earmark-pdf-fill"></i> Download Checklist PDF
            </a>
            <?php else: ?><span></span><?php endif; ?>
            <a href="dashboard.php" class="modal-cta-link modal-cta-link--dark">
                <i class="bi bi-house-fill"></i> Go to Dashboard
            </a>
        </div>
    </div>
</div>

</div><!-- /left -->

<!-- ════ RIGHT ════ -->
<div class="flexcol gap-4">

<div class="sb-panel">
    <div class="sb-head"><i class="bi bi-chat-dots-fill t-gold"></i> Live Chat</div>
    <div class="chat-ph"><i class="bi bi-chat-square-dots"></i><span>Chat coming soon</span></div>
</div>

<div class="sb-panel">
    <div class="sb-head"><i class="bi bi-list-check t-gold"></i> Progress</div>
    <div class="sb-body">
        <?php
        $step1_done   = ($st !== 'scheduled');
        $step2_done   = ($all_lots_done || $st === 'offered' || $st === 'ended');
        $step2_active = (in_array($st, ['started','eligibility','financial']) && !$step2_done);
        $step3_done   = ($st === 'ended');
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

            <div class="ms-item" id="ms-step-ended">
                <div class="ms-dot <?= $step3_done ? 'ms-done' : 'ms-pending' ?>">
                    <i class="bi bi-check2-all"></i>
                </div>
                <div class="ms-text">
                    <div class="ms-label">Session Concluded</div>
                    <div class="ms-desc">All lots opened and archived</div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="sb-panel">
    <div class="sb-head">
        <i class="bi bi-people-fill t-gold"></i> Members
        <span class="count-badge"><?= count($invited) ?></span>
    </div>
    <div class="sb-body pad-10-12">
        <?php if (empty($invited)): ?>
        <div class="ta-center t-muted fz-12 pad-12">None invited.</div>
        <?php else: ?>
        <?php foreach ($invited as $m):
            $ini = strtoupper(substr($m['firstname'],0,1).substr($m['lastname'],0,1));
        ?>
        <div class="member-row">
            <div class="avatar-28 avatar-28--mint">
                <?php if ($m['profile_picture_url']): ?>
                <img src="../<?= htmlspecialchars($m['profile_picture_url']) ?>" class="w-full-h-full-cover">
                <?php else: ?><?= htmlspecialchars($ini) ?><?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="fz-12 fw-7 t-dark truncate"><?= htmlspecialchars($m['firstname'].' '.$m['lastname']) ?></div>
                <div class="fz-10 t-muted"><?= htmlspecialchars($m['admin_type'] ?? '—') ?></div>
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
            <h4><i class="bi bi-play-circle-fill t-gold"></i> Start Bid Opening Session</h4>
            <button class="bsm-x" onclick="closeStartSessionModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13 fw-7 t-dark mb-8">Are you sure you want to start this session?</p>
            <p class="fz-12-5 t-graphite mb-12">This will officially open the bid opening process. You will begin reviewing documents lot-by-lot starting with Lot 1.</p>
            <div class="notice-box notice-box--amber">
                <i class="bi bi-exclamation-triangle-fill t-amberdark shrink-0"></i>
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
            <h4><i class="bi bi-shield-check t-forest"></i> Begin Eligibility &amp; Technical Phase</h4>
            <button class="bsm-x" onclick="closeStartEligibilityModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13-5 fw-7 t-dark mb-8">Start the Eligibility &amp; Technical evaluation for <span id="startEligLotName">Lot 1</span>?</p>
            <p class="fz-12-5 t-graphite mb-12">This will officially open the eligibility review phase. All invited members will be notified and documents will be available for inspection.</p>
            <div class="notice-box notice-box--green">
                <i class="bi bi-info-circle-fill t-forest shrink-0"></i>
                This action will be recorded in the system.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeStartEligibilityModal()">Cancel</button>
            <button class="btn-confirm btn-confirm--green" id="startEligConfirmBtn" onclick="doStartEligibilityPhase()">
                <i class="bi bi-play-circle-fill"></i> Yes, Begin Phase
            </button>
        </div>
    </div>
</div>

<!-- ══ CANNOT PROCEED MODAL ══ -->
<div class="bsm-bg" id="cannotProceedModal" onclick="if(event.target===this)closeCannotProceedModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-exclamation-triangle-fill t-amberdark"></i> Cannot Proceed Yet</h4>
            <button class="bsm-x" onclick="closeCannotProceedModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13 fw-7 t-dark mb-10" id="cannotProceedTitle">Review incomplete</p>
            <div id="cannotProceedList" class="flexcol gap-8 mb-12"></div>
            <div class="notice-box notice-box--amber">
                <i class="bi bi-info-circle-fill t-amberdark shrink-0"></i>
                Complete all evaluations before finalizing this lot.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-confirm" onclick="closeCannotProceedModal()">Got it</button>
        </div>
    </div>
</div>

<!-- ══ DONE OPENING LOT MODAL ══ -->
<div class="bsm-bg" id="doneLotModal" onclick="if(event.target===this)closeDoneLotModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-check-circle-fill t-forest"></i> Complete Lot Opening</h4>
            <button class="bsm-x" onclick="closeDoneLotModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13-5 fw-7 t-dark mb-8">Complete opening for <span id="doneLotModalName">Lot 1</span>?</p>
            <p class="fz-12-5 t-graphite mb-12">This will mark this lot as completed and advance the session to the next step. You can still return to inspect this lot at any time.</p>
            <div class="notice-box notice-box--green">
                <i class="bi bi-info-circle-fill t-forest shrink-0"></i>
                <span id="doneLotModalPrompt">Proceeding to next Lot.</span>
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeDoneLotModal()">Cancel</button>
            <button class="btn-confirm btn-confirm--green" id="doneLotConfirmBtn" onclick="confirmDoneLot()">
                <i class="bi bi-arrow-right-circle-fill"></i> Confirm &amp; Proceed
            </button>
        </div>
    </div>
</div>

<!-- ══ SIGN TO DECRYPT MODAL (BAC members) ══ -->
<div class="bsm-bg" id="signModal" onclick="if(event.target===this)closeSignModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-pen-fill t-gold"></i> Sign to Open Bid Documents</h4>
            <button class="bsm-x" onclick="closeSignModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13 fw-7 t-dark mb-4">Bidder: <span id="signBidderName"></span></p>
            <p class="fz-12 t-muted mb-14">Your signature contributes to the quorum needed to decrypt this bid. Enter your password to sign.</p>
            <div id="signQuorumStatus" class="mb-12"></div>
            <input type="password" id="signPwInput" class="bsm-input" placeholder="Enter your password…"
                   onkeydown="if(event.key==='Enter')doSign()">
            <div class="bsm-err" id="signErr"></div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeSignModal()">Cancel</button>
            <button class="btn-confirm" id="signConfirmBtn" onclick="doSign()">
                <i class="bi bi-pen-fill"></i> Sign &amp; Submit
            </button>
        </div>
    </div>
</div>

<!-- ══ START OPENING CONFIRMATION MODAL (Secretariat) ══ -->
<div class="bsm-bg" id="startOpeningModal" onclick="if(event.target===this)closeStartOpeningModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-broadcast t-gold"></i> Start Bid Opening</h4>
            <button class="bsm-x" onclick="closeStartOpeningModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13-5 fw-7 t-dark mb-8">Start opening for <span id="startOpeningBidderName"></span>?</p>
            <p class="fz-12-5 t-graphite mb-12">This will signal BAC members that they can now sign to unlock the bid documents. The files will not be decrypted until the required number of BAC members have signed.</p>
            <div class="notice-box notice-box--amber">
                <i class="bi bi-info-circle-fill t-amberdark shrink-0"></i>
                BAC signatures are required to decrypt the documents. This action is recorded.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeStartOpeningModal()">Cancel</button>
            <button class="btn-confirm btn-confirm--green" id="startOpeningConfirmBtn" onclick="doSignalOpen()">
                <i class="bi bi-broadcast"></i> Yes, Start Opening
            </button>
        </div>
    </div>
</div>

<!-- ══ OPEN NOW CONFIRMATION MODAL (Secretariat — after quorum) ══ -->
<div class="bsm-bg" id="openNowModal" onclick="if(event.target===this)closeOpenNowModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-unlock-fill t-gold"></i> Open Bid Documents</h4>
            <button class="bsm-x" onclick="closeOpenNowModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13-5 fw-7 t-dark mb-8">Quorum has been reached.</p>
            <p class="fz-12-5 t-graphite mb-12">All required BAC members have signed. You can now officially open and decrypt the bid documents. This action will be permanently recorded.</p>
            <div class="notice-box notice-box--green">
                <i class="bi bi-shield-check t-forest shrink-0"></i>
                Documents will be decrypted and made available for review.
            </div>
        </div>
        <div class="bsm-foot">
            <button class="btn-cancel" onclick="closeOpenNowModal()">Cancel</button>
            <button class="btn-confirm btn-confirm--green" id="openNowConfirmBtn" onclick="doOpenFiles()">
                <i class="bi bi-unlock-fill"></i> Yes, Open Files
            </button>
        </div>
    </div>
</div>

<!-- ══ PASSWORD MODAL (Secretariat / legacy decrypt) ══ -->
<div class="bsm-bg" id="pwModal" onclick="if(event.target===this)closePw()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-lock-fill t-gold"></i> Enter Password to Open Files</h4>
            <button class="bsm-x" onclick="closePw()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-12-5 t-graphite mb-12">Your admin password is required to decrypt and open these documents.</p>
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
    <div class="bsm bsm-wide">
        <div class="bsm-head">
            <h4 id="eligModalTitle"><i class="bi bi-clipboard-check t-gold"></i> Document Checklist</h4>
            <button class="bsm-x" onclick="closeElig()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body modal-scroll">
            <p class="fz-12-5 t-graphite mb-2">Business: <strong id="eligName" class="t-dark"></strong></p>
            <p class="fz-12 t-muted mb-4" id="eligModalSub">Mark compliance for each document below.</p>
            <div id="eligChecklistArea">
                <div class="pad-16-0 flexr gap-8">
                    <div class="skel h-36 flex-1 r-8"></div>
                    <div class="skel h-36 flex-1 r-8"></div>
                </div>
            </div>
        </div>
        <div class="bsm-foot bsm-foot--wide">
            <?php if ($can_manage): ?>
            <button class="btn-ineligible" id="btn-disqualify" onclick="submitElig(0)"><i class="bi bi-x-circle-fill"></i> Disqualify</button>
            <button class="btn-eligible"   id="btn-comply"     onclick="submitElig(1)"><i class="bi bi-check-circle-fill"></i> Comply / Eligible</button>
            <?php else: ?>
            <button class="btn-cancel" onclick="closeElig()">Close</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ END SESSION CONFIRMATION MODAL ══ -->
<div class="bsm-bg" id="endSessionModal" onclick="if(event.target===this)closeEndSessionModal()">
    <div class="bsm">
        <div class="bsm-head">
            <h4><i class="bi bi-check2-all t-gold"></i> End Bid Opening Session</h4>
            <button class="bsm-x" onclick="closeEndSessionModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="bsm-body">
            <p class="fz-13 fw-7 t-dark mb-8">Are you sure you want to conclude this session?</p>
            <p class="fz-12-5 t-graphite mb-12">This will finalize all lot reviews, archive the live session, and update the procurement status. This action cannot be undone.</p>
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
const SESSION_ID     = <?= (int)$session_id ?>;
const PROC_ID        = <?= (int)$proc_id ?>;
const CAN_MANAGE     = <?= $can_manage ? 'true' : 'false' ?>;
const PROC_TYPE      = '<?= htmlspecialchars($session['procurement_type'] ?? 'goods_services') ?>';
const IS_BAC         = <?= ($admin_type === 'BAC') ? 'true' : 'false' ?>;
const ADMIN_TYPE     = '<?= htmlspecialchars($admin_type) ?>';
const LOTS           = <?= json_encode(array_values($lots)) ?>;
const CURRENT_LOT_ID = <?= $current_lot_id ?>;
let   SESSION_STATUS  = '<?= $st ?>';
let   SIGNING_STATUS  = '<?= $signing_st ?>';

// ── State Management ──────────────────────────────────────────────────────
const STATE = {
    currentTab: 0, // 0..N-1 for lots, or 'conclusion'
    activeLotIdx: 0,
    doneLotIndices: [],
    lots: {}, // lotId -> { stage: 'eligibility'|'financial', bidders: [], done: { eligibility: [], financial: [] } }
};
const openedDocs = {}; // `${lotId}:${docId}` -> { data_url, mime, file_name }
function odKey(lotId, docId){ return `${lotId}:${docId}`; }

// Pending modal context
let pw_stage, pw_lotId, pw_bidderId, pw_bidId, pw_files;
let el_bidId, el_bidderName, el_lotId, el_bidderId, el_phase, el_bidLotId;

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
        SIGNING_STATUS = data.signing_status || 'not_started';
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
            } else if(data.status === 'offered'){
                actEl.textContent = 'Recording offered bid amounts before concluding the session.';
            } else if(data.status === 'ended'){
                actEl.textContent = 'Bid Opening Session officially concluded and archived.';
            }
        }

        // Update Hero Status Pill
        const pill = document.getElementById('heroStatusPill');
        if(pill){
            const isLive = ['started','eligibility','financial','offered'].includes(data.status);
            pill.className = 'ipill ' + (isLive ? 'live' : 'sched');
            pill.innerHTML = `<i class="bi bi-${isLive ? 'broadcast' : 'clock'}"></i> ${data.status.charAt(0).toUpperCase() + data.status.slice(1)}`;
        }

        // Update master tabs done and unlocked states from DB
        let allLotsDone = lots.length > 0;
        lots.forEach((l, i)=>{
            const isDone = !!l.is_done;
            if(!isDone) allLotsDone = false;

            const t = document.getElementById('mtab-'+i);
            if(t && isDone){
                t.classList.add('done');
                t.classList.remove('locked');
            }
        });

        // Lock all lot tabs once past the lot-review phase
        if(data.status === 'offered' || data.status === 'ended'){
            LOTS.forEach((_, i) => {
                const t = document.getElementById('mtab-'+i);
                if(t) t.classList.add('locked');
            });
        }

        // Unlock the Offered Bids tab once all lots are done — but lock it
        // back once the session has actually ended, since Conclusion is the
        // only reachable tab from then on.
        const oTab = document.getElementById('mtab-offered');
        if(oTab){
            if(data.status === 'ended'){
                oTab.classList.add('locked');
            } else if(allLotsDone || data.status === 'offered'){
                oTab.classList.remove('locked');
            }
        }

        // Unlock conclusion tab when ended
        if(data.status === 'ended'){
            const cTab = document.getElementById('mtab-conclusion');
            if(cTab) cTab.classList.remove('locked');
        }

        // Viewers sitting on a lot tab get moved to Offered Bids once the
        // session reaches that phase (mirrors the 'ended' -> conclusion jump).
        if((data.status === 'offered' || allLotsDone) && typeof STATE.currentTab === 'number'){
            STATE.activeLotIdx = -1;
            switchMasterTab('offered');
        }

        // Update sidebar milestones from DB
        updateSidebarMilestones(data.status, allLotsDone, data.ended_at);
    });
}

function updateSidebarMilestones(status, allLotsDone, endedAt){
    const s1 = document.getElementById('ms-step-start');
    const s2 = document.getElementById('ms-step-lots');
    const s4 = document.getElementById('ms-step-ended');

    if(s1){
        const done = status !== 'scheduled';
        s1.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : 'ms-active');
        s1.querySelector('.ms-dot i').className = 'bi ' + (done ? 'bi-check2' : 'bi-play-circle');
    }

    if(s2){
        const done = allLotsDone || status === 'offered' || status === 'ended';
        const active = ['started','eligibility','financial'].includes(status) && !done;
        s2.querySelector('.ms-dot').className = 'ms-dot ' + (done ? 'ms-done' : (active ? 'ms-active' : 'ms-pending'));
        s2.querySelector('.ms-dot i').className = 'bi ' + (done ? 'bi-check2' : 'bi-layers');
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
    if(tab === 'conclusion'){
        const conclusionTab = document.getElementById('mtab-conclusion');
        if(conclusionTab && conclusionTab.classList.contains('locked')) return;
        switchMasterTab('conclusion');
    } else if(tab === 'offered'){
        const offeredTab = document.getElementById('mtab-offered');
        if(offeredTab && offeredTab.classList.contains('locked')) return;
        // Backed by state, not just the DOM class, so removing 'locked' via
        // DevTools alone can't get back in once the session has ended —
        // matches the guard the lot tabs already use.
        if(SESSION_STATUS === 'ended') return;
        switchMasterTab('offered');
    } else {
        const idx = parseInt(tab);
        const tabEl = document.getElementById('mtab-'+idx);
        // Block locked tabs (future lots)
        if(tabEl && tabEl.classList.contains('locked')) return;
        // Block lot navigation once the session has ended, or once every lot
        // is done and we're just on the Offered Bids tab (activeLotIdx = -1)
        if(SESSION_STATUS === 'ended' || STATE.activeLotIdx === -1) return;
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
        } else if(tab === 'offered' || tab === 'conclusion'){
            // Lock all lot tabs once past the lot-review phase
            t.classList.add('locked');
        }
    });

    const offeredTab = document.getElementById('mtab-offered');
    if(offeredTab){
        offeredTab.classList.remove('active');
        if(tab === 'offered'){
            offeredTab.classList.add('active');
            offeredTab.classList.remove('locked');
        } else if(tab === 'conclusion'){
            offeredTab.classList.add('locked');
        }
    }

    const conclusionTab = document.getElementById('mtab-conclusion');
    if(conclusionTab){
        conclusionTab.classList.remove('active');
        if(tab === 'conclusion'){
            conclusionTab.classList.add('active');
            conclusionTab.classList.remove('locked');
        }
    }

    const lotContainer     = document.getElementById('lot-session-container');
    const offeredContainer = document.getElementById('offered-bids-container');
    const conclusionContainer = document.getElementById('conclusion-container');

    lotContainer.classList.toggle('hide', tab === 'offered' || tab === 'conclusion');
    offeredContainer.classList.toggle('hide', tab !== 'offered');
    conclusionContainer.classList.toggle('hide', tab !== 'conclusion');

    if(tab === 'conclusion'){
        loadConclusion();
    } else if(tab === 'offered'){
        loadOfferedBids();
    } else {
        STATE.activeLotIdx = tab;
        loadLot(tab);
    }
}

// ── Load Lot ──────────────────────────────────────────────────────────────
function loadLot(idx){
    const lot = LOTS[idx];
    if(!lot) return;

    document.getElementById('lotHeadTitle').innerHTML = `<i class="bi bi-layers-fill t-forest"></i> <span>Lot ${esc(lot.lot_number)}${lot.lot_title ? ' · '+esc(lot.lot_title) : ''}</span>`;
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
        if(gate) gate.classList.remove('hide');
        area.innerHTML = '';
        return;
    }

    if(gate) gate.classList.add('hide');

    area.innerHTML = '<div class="pad-24 flexr gap-8"><div class="skel h-32 w-120 r-20"></div><div class="skel h-32 w-120 r-20"></div></div>';

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
        if(gate) gate.classList.add('hide');
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
        SESSION_STATUS        = stage;
        SIGNING_STATUS        = 'not_started';  // reset signing for new phase
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
        eligBtn.className  = 'stage-step active' + (CAN_MANAGE ? '' : ' stage-step--noclick');
        // financial locked until eligibility done — pointer-events blocked
        finBtn.className   = 'stage-step stage-step--noclick stage-step--dim';
        pill.className    = 'ipill live';
        pill.innerHTML    = '<i class="bi bi-shield-check"></i> Stage 1: Eligibility &amp; Technical';
    } else {
        // once on financial, eligibility is read-only indicator
        eligBtn.className  = 'stage-step done stage-step--noclick';
        finBtn.className   = 'stage-step active' + (CAN_MANAGE ? '' : ' stage-step--noclick');
        pill.className    = 'ipill mode';
        pill.innerHTML    = '<i class="bi bi-cash-stack"></i> Stage 2: Financial Proposal';
    }
}

// ── Fetch and Render Bidders for Lot & Stage ───────────────────────────────
function fetchAndRenderBidders(lotId, stage){
    const area = document.getElementById('lot-bidder-area');
    area.innerHTML = '<div class="pad-24 flexr gap-8"><div class="skel h-32 w-120 r-20"></div><div class="skel h-32 w-120 r-20"></div></div>';

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
            ${isDisq ? '<span class="disq-badge"><i class="bi bi-x-circle-fill"></i> Disqualified</span>' : (isDone ? '<i class="bi bi-check2-circle t-teal fz-11 ml-2"></i>' : '')}
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
                if(finBtn){ finBtn.classList.remove('stage-step--noclick', 'stage-step--dim'); }

                actBar.innerHTML = `
                    <div class="fz-12-5 fw-7 t-greendark flexc gap-6">
                        <i class="bi bi-check-circle-fill"></i> Eligibility Stage Complete
                    </div>
                    <button class="btn-confirm" onclick="setLotStage('financial')">
                        Proceed to Stage 2: Financial <i class="bi bi-arrow-right"></i>
                    </button>
                `;
                // Hide the button for non-managers
                if(!CAN_MANAGE){
                    actBar.querySelector('button.btn-confirm') && actBar.querySelector('button.btn-confirm').classList.add('hide');
                }
                fileArea.innerHTML = '<div class="p-empty"><i class="bi bi-check2-all t-greendark"></i>All eligibility documents reviewed for this lot.</div>';
            } else {
                actBar.innerHTML = `
                    <div class="fz-12-5 fw-7 t-greendark flexc gap-6">
                        <i class="bi bi-check-circle-fill"></i> Financial Stage Complete
                    </div>
                `;
                fileArea.innerHTML = '<div class="p-empty"><i class="bi bi-trophy t-teal"></i>All bids reviewed for this lot. Click "Done Opening Lot" below to proceed.</div>';
            }
        }
    }
}

function selectBidderTab(lotId, stage, bidder, actBar, fileArea){
    // Track active bid_lot_id so the poll tick can reference the selected bidder
    if(typeof _poll !== 'undefined') _poll._activeBidLotId = bidder.bid_lot_id || 0;

    // Highlight tab
    document.querySelectorAll('.bidder-tab').forEach(t=>t.classList.remove('active'));
    const t = document.getElementById(`btab-${lotId}-${stage}-${bidder.bidder_id}`);
    if(t) t.classList.add('active');

    const bName = bidder.business_name || (bidder.firstname+' '+bidder.lastname);

    fileArea.innerHTML = '<div class="pad-16 flexcol gap-8"><div class="skel h-44"></div><div class="skel h-44"></div></div>';

    actBar.innerHTML = `
        <div class="action-bar-name"><i class="bi bi-building"></i>${esc(bName)}</div>
        <div class="action-bar-btns" id="actbtns-${bidder.bidder_id}">
        </div>
    `;

    // Load files + quorum state together, then render the correct action buttons
    Promise.all([
        get({ action:'files',        bidder_id:bidder.bidder_id, lot_id:lotId, phase:stage, session_id:SESSION_ID }),
        get({ action:'check_quorum', bid_lot_id:bidder.bid_lot_id, opening_type:stage, session_id:SESSION_ID })
    ]).then(([fd, qd]) => {
        const files   = fd.files || [];
        const quorum  = qd;

        if(!files.length){
            fileArea.innerHTML = `<div class="p-empty"><i class="bi bi-file-earmark-x"></i>No ${stage} documents uploaded.</div>`;
            _renderActionButtons(bidder, stage, lotId, files, quorum);
            return;
        }

        // Decrypt only when BOTH conditions are true:
        // 1. signing_status = 'done' (secretariat clicked Open Now after quorum)
        // 2. bidder.files_opened = true for THIS phase (phase-specific DB status)
        // This prevents financial files from auto-decrypting just because eligibility was opened.
        const signingDone = (quorum.signing_status === 'done') && bidder.files_opened;
        if(signingDone){
            (async ()=>{
                for(const f of files){
                    if(openedDocs[odKey(lotId, f.id)]) continue;
                    const res = await post({ action:'decrypt_file', doc_id:f.id, session_id:SESSION_ID });
                    if(res.success) openedDocs[odKey(lotId, f.id)] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
                }
                renderFiles(fileArea, files, quorum, lotId);
                const chkBtn = document.getElementById(`btn-chk-${bidder.bidder_id}`);
                if(chkBtn) chkBtn.classList.add('ready');
            })();
        } else {
            renderFiles(fileArea, files, quorum, lotId);
        }

        _renderActionButtons(bidder, stage, lotId, files, quorum);
    });
}

// ── Render action buttons based on role + quorum state ────────────────────
function _renderActionButtons(bidder, stage, lotId, files, quorum){
    const btns = document.getElementById(`actbtns-${bidder.bidder_id}`);
    if(!btns) return;

    const bName     = bidder.business_name || (bidder.firstname+' '+bidder.lastname);
    const isOpened  = bidder.files_opened;
    const signaled  = (quorum.signing_status === 'signing' || quorum.signing_status === 'done');
    const qReached  = quorum.quorum_reached;
    const sigDone   = (quorum.signing_status === 'done');

    if(CAN_MANAGE){
        // ── SECRETARIAT flow ──
        if(isOpened || sigDone){
            // Files already officially opened
            btns.innerHTML = `<span class="status-chip status-chip--forest"><i class="bi bi-check-circle-fill"></i> Files Opened</span>`;
        } else if(qReached && signaled){
            // Quorum reached — secretariat can now officially open
            btns.innerHTML = `<button class="btn-signal-open" id="btn-open-now-${bidder.bidder_id}"
                onclick="openOpenNowModal('${stage}', ${lotId}, ${bidder.bidder_id}, ${bidder.bid_id}, ${bidder.bid_lot_id})">
                <i class="bi bi-unlock-fill"></i> Open Now
            </button>`;
        } else if(signaled){
            // Secretariat already signaled — waiting for BAC to sign
            btns.innerHTML = `<span class="status-chip status-chip--blue"><i class="bi bi-hourglass-split"></i> Waiting for BAC (${quorum.sig_count}/${quorum.required})</span>`;
        } else {
            // Not yet started — show Start Opening button
            btns.innerHTML = `<button class="btn-signal-open" id="btn-start-opening-${bidder.bidder_id}"
                onclick="openStartOpeningModal('${stage}', ${lotId}, ${bidder.bidder_id}, ${bidder.bid_id}, ${bidder.bid_lot_id}, '${esc(bName).replace(/'/g,"\\'")}')">
                <i class="bi bi-broadcast"></i> Start Opening
            </button>`;
        }

        // Checklist always visible for secretariat (enabled only after files opened)
        btns.innerHTML += `<button class="btn-checklist${isOpened?' ready':''}" id="btn-chk-${bidder.bidder_id}"
            onclick="openEligModal(${bidder.bid_id}, '${esc(bName).replace(/'/g,"\\'")}', '${stage}', ${lotId}, ${bidder.bidder_id}, ${bidder.bid_lot_id})">
            <i class="bi bi-clipboard-check"></i> Checklist
        </button>`;

    } else if(IS_BAC){
        // ── BAC flow ──
        if(isOpened){
            // Files opened — show evaluation status
        } else if(qReached){
            btns.innerHTML = `<span class="status-chip status-chip--blue"><i class="bi bi-shield-check"></i> Quorum Reached — Secretariat opening files</span>`;
        } else if(signaled){
            // Secretariat signaled — BAC can sign
            if(quorum.already_signed){
                btns.innerHTML = `<span class="status-chip status-chip--green"><i class="bi bi-check2-circle"></i> Signed — ${quorum.sig_count}/${quorum.required} reached</span>`;
            } else {
                btns.innerHTML = `<button class="btn-sign" id="btn-sign-${bidder.bidder_id}"
                    onclick="openSignModal('${stage}', ${lotId}, ${bidder.bidder_id}, ${bidder.bid_id}, ${bidder.bid_lot_id}, '${esc(bName).replace(/'/g,"\\'")}')">
                    <i class="bi bi-pen-fill"></i> Sign to Open
                </button>`;
            }
        } else {
            // Secretariat hasn't started yet
            btns.innerHTML = `<span class="status-chip status-chip--amber"><i class="bi bi-hourglass-split"></i> Waiting for Secretariat…</span>`;
        }

        // Evaluation status pill
        const st = stage === 'eligibility' ? bidder.eligibility_status : bidder.financial_status;
        if(st === 'eligible' || st === 'qualified')
            btns.innerHTML += ` <span class="status-chip status-chip--green"><i class="bi bi-check-circle-fill"></i> ${st === 'eligible' ? 'Eligible' : 'Qualified'}</span>`;
        else if(st === 'disqualified' || st === 'non_compliant')
            btns.innerHTML += ` <span class="status-chip status-chip--red"><i class="bi bi-x-circle-fill"></i> ${st === 'disqualified' ? 'Disqualified' : 'Non-Compliant'}</span>`;

    } else {
        // ── TWG / observer — status only ──
        const st = stage === 'eligibility' ? bidder.eligibility_status : bidder.financial_status;
        if(st === 'eligible' || st === 'qualified')
            btns.innerHTML = `<span class="status-chip status-chip--green"><i class="bi bi-check-circle-fill"></i> ${st === 'eligible' ? 'Eligible' : 'Qualified'}</span>`;
        else if(st === 'disqualified' || st === 'non_compliant')
            btns.innerHTML = `<span class="status-chip status-chip--red"><i class="bi bi-x-circle-fill"></i> ${st === 'disqualified' ? 'Disqualified' : 'Non-Compliant'}</span>`;
        else
            btns.innerHTML = `<span class="status-chip status-chip--amber"><i class="bi bi-hourglass-split"></i> Waiting for evaluation…</span>`;
    }
}

function renderFiles(area, files, quorum, lotId){
    area.innerHTML = '';

    // Quorum status bar
    if(quorum){
        const bar = mkEl('div','');
        if(quorum.signing_status === 'done'){
            bar.className = 'quorum-bar quorum-bar--done';
            bar.innerHTML = `<i class="bi bi-unlock-fill fz-14"></i> Files officially opened — decryption complete`;
        } else if(quorum.quorum_reached){
            bar.className = 'quorum-bar quorum-bar--reached';
            bar.innerHTML = `<i class="bi bi-shield-check fz-14"></i> Quorum reached (${quorum.sig_count}/${quorum.required} signatures) — waiting for Secretariat to open`;
        } else {
            const pct = quorum.bac_total > 0 ? Math.round((quorum.sig_count / quorum.required) * 100) : 0;
            bar.className = 'quorum-bar quorum-bar--pending';
            bar.innerHTML = `
                <div class="flexc gap-7 mb-6">
                    <i class="bi bi-hourglass-split fz-13"></i>
                    Awaiting signatures — ${quorum.sig_count} of ${quorum.required} required (${quorum.bac_total} BAC invited)
                </div>
                <div class="progress-track progress-track--amber-light">
                    <div class="progress-fill progress-fill--amber" style="--w:${Math.min(pct,100)}%"></div>
                </div>
                ${quorum.signers && quorum.signers.length ? `<div class="mt-6 fz-10-5 t-warnmid">Signed: ${quorum.signers.map(s=>`${s.firstname} ${s.lastname}`).join(', ')}</div>` : ''}
            `;
        }
        area.appendChild(bar);
    }

    files.forEach(f=>{
        const opened = !!openedDocs[odKey(lotId, f.id)];
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
                onclick="viewDoc(${lotId},${f.id})" title="${opened?'View document':'Quorum not reached yet'}">
                <i class="bi bi-eye"></i> View
            </button>
        `;
        area.appendChild(row);
    });
}

// ── Secretariat: signal opening of a bid ─────────────────────────────────
// ── Secretariat: Start Opening modal ─────────────────────────────────────
let _startOpeningCtx = null;
function openStartOpeningModal(stage, lotId, bidderId, bidId, bidLotId, bName){
    _startOpeningCtx = { stage, lotId, bidderId, bidId, bidLotId };
    document.getElementById('startOpeningBidderName').textContent = bName;
    document.getElementById('startOpeningConfirmBtn').disabled = false;
    document.getElementById('startOpeningConfirmBtn').innerHTML = '<i class="bi bi-broadcast"></i> Yes, Start Opening';
    document.getElementById('startOpeningModal').classList.add('open');
}
function closeStartOpeningModal(){
    document.getElementById('startOpeningModal').classList.remove('open');
    _startOpeningCtx = null;
    _catchUpAfterModal();
}
function doSignalOpen(){
    if(!_startOpeningCtx) return;
    const { stage, lotId, bidderId, bidId, bidLotId } = _startOpeningCtx;
    const btn = document.getElementById('startOpeningConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting…';

    post({ action:'signal_open', session_id:SESSION_ID })
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-broadcast"></i> Yes, Start Opening';
        if(!d.success){ alert(d.message||'Failed.'); return; }
        SIGNING_STATUS = 'signing';
        closeStartOpeningModal();
        const startBtn = document.getElementById(`btn-start-opening-${bidderId}`);
        if(startBtn) startBtn.outerHTML = `<span class="status-chip status-chip--blue"><i class="bi bi-hourglass-split"></i> Waiting for BAC…</span>`;
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-broadcast"></i> Yes, Start Opening'; });
}

// ── Secretariat: Open Now confirmation modal ──────────────────────────────
let _openNowCtx = null;
function openOpenNowModal(stage, lotId, bidderId, bidId, bidLotId){
    _openNowCtx = { stage, lotId, bidderId, bidId, bidLotId };
    document.getElementById('openNowConfirmBtn').disabled = false;
    document.getElementById('openNowConfirmBtn').innerHTML = '<i class="bi bi-unlock-fill"></i> Yes, Open Files';
    document.getElementById('openNowModal').classList.add('open');
}
function closeOpenNowModal(){
    document.getElementById('openNowModal').classList.remove('open');
    _openNowCtx = null;
    _catchUpAfterModal();
}

// ── Secretariat: Open Files after quorum ─────────────────────────────────
function doOpenFiles(){
    if(!_openNowCtx) return;
    const { stage, lotId, bidderId, bidId, bidLotId } = _openNowCtx;
    const btn = document.getElementById('openNowConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Opening…';

    post({ action:'open_files', bid_id:bidId, lot_id:lotId, bid_lot_id:bidLotId, opening_type:stage, session_id:SESSION_ID })
    .then(async d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-unlock-fill"></i> Yes, Open Files';
        if(!d.success){ alert(d.message||'Failed.'); return; }

        SIGNING_STATUS = 'done';
        closeOpenNowModal();

        // Decrypt and render files
        const area = document.getElementById('lot-bidder-area');
        const fileArea = area ? area.querySelector('.files-pane') : null;
        if(fileArea){
            const fd = await get({ action:'files', bidder_id:bidderId, lot_id:lotId, phase:stage, session_id:SESSION_ID });
            const files = fd.files || [];
            for(const f of files){
                const res = await post({ action:'decrypt_file', doc_id:f.id, session_id:SESSION_ID });
                if(res.success) openedDocs[odKey(lotId, f.id)] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
            }
            renderFiles(fileArea, files, { quorum_reached:true, sig_count:1, required:1, signing_status:'done' }, lotId);
        }

        // Replace only the "Open Now" button — leave checklist intact
        const openNowBtn = document.getElementById(`btn-open-now-${bidderId}`);
        if(openNowBtn) openNowBtn.outerHTML = `<span class="status-chip status-chip--forest"><i class="bi bi-check-circle-fill"></i> Files Opened</span>`;
        const chkBtn = document.getElementById(`btn-chk-${bidderId}`);
        if(chkBtn) chkBtn.classList.add('ready');
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-unlock-fill"></i> Yes, Open Files'; });
}

// ── BAC: sign modal ───────────────────────────────────────────────────────
let _signCtx = null;
function openSignModal(stage, lotId, bidderId, bidId, bidLotId, bName){
    _signCtx = { stage, lotId, bidderId, bidId, bidLotId };
    document.getElementById('signBidderName').textContent = bName;
    document.getElementById('signPwInput').value = '';
    document.getElementById('signErr').classList.remove('show');

    // Load current quorum state
    get({ action:'check_quorum', bid_lot_id:bidLotId, opening_type:stage, session_id:SESSION_ID })
    .then(q => { _renderSignQuorumStatus(q); });

    document.getElementById('signModal').classList.add('open');
    setTimeout(()=>document.getElementById('signPwInput').focus(), 100);
}
function closeSignModal(){
    document.getElementById('signModal').classList.remove('open');
    _signCtx = null;
    _catchUpAfterModal();
}

function _renderSignQuorumStatus(q){
    const el = document.getElementById('signQuorumStatus');
    if(!el) return;
    const pct = q.bac_total > 0 ? Math.round((q.sig_count / q.required) * 100) : 0;
    el.innerHTML = `
        <div class="info-box info-box--gray">
            <div class="flex-between mb-6">
                <span class="fw-7">Signatures</span>
                <span class="fw-8 t-dark">${q.sig_count} / ${q.required} needed</span>
            </div>
            <div class="progress-track progress-track--gray">
                <div class="progress-fill progress-fill--blue" style="--w:${Math.min(pct,100)}%"></div>
            </div>
            ${q.signers && q.signers.length ? `<div class="mt-6 t-slate fz-10-5">Signed: ${q.signers.map(s=>`${s.firstname} ${s.lastname}`).join(', ')}</div>` : ''}
        </div>
    `;
}

function doSign(){
    if(!_signCtx) return;
    const pw  = document.getElementById('signPwInput').value.trim();
    const err = document.getElementById('signErr');
    const btn = document.getElementById('signConfirmBtn');
    if(!pw){ err.textContent = 'Password is required.'; err.classList.add('show'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Signing…';
    err.classList.remove('show');

    post({
        action:       'sign_lot',
        bid_lot_id:   _signCtx.bidLotId,
        opening_type: _signCtx.stage,
        password:     pw,
        session_id:   SESSION_ID
    }).then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-pen-fill"></i> Sign &amp; Submit';

        if(!d.success){
            err.textContent = d.message || 'Failed.';
            err.classList.add('show');
            return;
        }

        // Update quorum display in modal
        _renderSignQuorumStatus(d);

        if(d.quorum_reached){
            closeSignModal();
            // Quorum reached — BAC's job is done. Update button to inform BAC.
            // Secretariat will see "Open Now" on their next render.
            const btns = document.getElementById(`actbtns-${_signCtx.bidderId}`);
            if(btns){
                const signBtn = document.getElementById(`btn-sign-${_signCtx.bidderId}`);
                if(signBtn) signBtn.outerHTML = `<span class="status-chip status-chip--blue"><i class="bi bi-shield-check"></i> Quorum Reached — waiting for Secretariat to open</span>`;
            }
        } else {
            // Update button to "Signed" state
            const btns = document.getElementById(`actbtns-${_signCtx.bidderId}`);
            if(btns){
                const signBtn = document.getElementById(`btn-sign-${_signCtx.bidderId}`);
                if(signBtn) signBtn.outerHTML = `<span class="status-chip status-chip--green"><i class="bi bi-check2-circle"></i> Signed (${d.sig_count}/${d.required})</span>`;
            }
            closeSignModal();
        }
    }).catch(()=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-pen-fill"></i> Sign &amp; Submit';
        err.textContent = 'Network error. Please try again.';
        err.classList.add('show');
    });
}

// ── Legacy decrypt (kept for secretariat direct decrypt if needed) ─────────
function closePw(){ document.getElementById('pwModal').classList.remove('open'); }

function doPwConfirm(){
    const pw  = document.getElementById('pwInput').value.trim();
    const err = document.getElementById('pwErr');
    const btn = document.getElementById('pwConfirmBtn');
    if(!pw){ err.textContent = 'Password is required.'; err.classList.add('show'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Decrypting…';
    err.classList.remove('show');

    (async ()=>{
        let ok = true;
        for(const f of pw_files){
            const res = await post({ action:'decrypt_file', doc_id:f.id, password:pw, session_id:SESSION_ID });
            if(!res.success){
                err.textContent = res.message;
                err.classList.add('show');
                ok = false;
                break;
            }
            openedDocs[odKey(pw_lotId, f.id)] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
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
    files.forEach(f=>{
        const b = document.getElementById('vbtn-'+f.id);
        if(b){ b.classList.add('active'); b.title = 'View document'; }
    });
    const chkBtn = document.getElementById(`btn-chk-${bidderId}`);
    if(chkBtn) chkBtn.classList.add('ready');
}

function viewDoc(lotId, docId){
    const e = openedDocs[odKey(lotId, docId)];
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
        ifr.className = 'fv-pdf-frame';
        c.appendChild(ifr);
    } else if(e.mime.startsWith('image/')){
        const img = document.createElement('img');
        img.src   = objUrl;
        img.className = 'fv-img';
        c.appendChild(img);
    } else {
        const a = document.createElement('a');
        a.href  = objUrl;
        a.download = e.file_name;
        a.className = 'btn-confirm fv-download-link';
        a.innerHTML = '<i class="bi bi-download"></i> Download File';
        c.appendChild(a);
    }

    document.getElementById('fvOverlay').classList.add('open');
}
function closeFv(){
    document.getElementById('fvOverlay').classList.remove('open');
    document.getElementById('fvContent').innerHTML = '';
}

// ── Checklist Modal ───────────────────────────────────────────────────────
function openEligModal(bidId, name, stage, lotId, bidderId, bidLotId){
    const ck = document.getElementById(`btn-chk-${bidderId}`);
    if(ck && !ck.classList.contains('ready')) return;

    el_bidId      = bidId;
    el_bidderName = name;
    el_phase      = stage;
    el_lotId      = lotId;
    el_bidderId   = bidderId;
    el_bidLotId   = bidLotId;

    document.getElementById('eligName').textContent = name;
    document.getElementById('eligModalTitle').innerHTML =
        `<i class="bi bi-clipboard-check t-gold"></i> ${stage==='eligibility'?'Eligibility & Technical':'Financial'} Checklist`;
    document.getElementById('eligModalSub').textContent = `Review all documents for ${name}.`;

    // Load checklist from DB
    const area = document.getElementById('eligChecklistArea');
    area.innerHTML = '<div class="pad-12-0 flexcol gap-8"><div class="skel h-36 r-8"></div><div class="skel h-36 r-8"></div><div class="skel h-36 r-8"></div></div>';

    get({ action:'get_checklist', bid_lot_id:bidLotId, checklist_type:stage, procurement_type:PROC_TYPE, session_id:SESSION_ID })
    .then(d => {
        if(!d.success){ area.innerHTML = `<div class="p-empty"><i class="bi bi-exclamation-circle"></i>${d.message||'Failed to load checklist.'}</div>`; return; }
        _renderChecklist(area, d.items, stage);
    })
    .catch(()=>{ area.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Network error.</div>'; });

    document.getElementById('eligModal').classList.add('open');
}
function closeElig(){ document.getElementById('eligModal').classList.remove('open'); _catchUpAfterModal(); }

function _renderChecklist(area, items, stage){
    if(!items || !items.length){
        area.innerHTML = `<div class="pad-16-0 ta-center t-muted fz-12-5"><i class="bi bi-inbox"></i> No checklist items configured for the ${stage} phase.</div>`;
        _refreshEligibleBtn();
        return;
    }

    const resultLabels = { pending:'Pending', present:'Present', missing:'Missing', not_applicable:'N/A' };

    let html = '<div class="cl-list">';
    items.forEach(item => {
        const checkedBy = item.checked_by_name || (item.firstname ? `${item.firstname} ${item.lastname}` : '');
        const checkedAt = item.checked_at ? `· ${item.checked_at.substring(0,16).replace('T',' ')}` : '';
        const byLine    = (item.result !== 'pending' && checkedBy)
            ? `<div class="cl-checked-by"><i class="bi bi-person-check"></i> ${esc(checkedBy)} ${esc(checkedAt)}</div>`
            : '';

        html += `
        <div class="cl-item cl-row-${item.result}" id="cl-item-${item.id}">
            <div class="cl-item-name">
                ${esc(item.item_name)}
                ${item.is_required ? '<span class="cl-required">Required</span>' : ''}
            </div>
            ${item.description ? `<div class="fz-11 t-muted mb-5">${esc(item.description)}</div>` : ''}
            <div class="cl-controls">
                <select class="cl-select ${item.result}" id="cl-result-${item.id}"
                    onchange="_onResultChange(this, ${item.id})">
                    <option value="pending"        ${item.result==='pending'        ?'selected':''}>Pending</option>
                    <option value="present"        ${item.result==='present'        ?'selected':''}>Present</option>
                    <option value="missing"        ${item.result==='missing'        ?'selected':''}>Missing</option>
                    <option value="not_applicable" ${item.result==='not_applicable' ?'selected':''}>N/A</option>
                </select>
                <textarea class="cl-remarks" id="cl-remarks-${item.id}" rows="1"
                    placeholder="Remarks (optional)">${esc(item.remarks||'')}</textarea>
                <button class="cl-save-btn" id="cl-save-${item.id}"
                    onclick="_saveChecklistItem(${item.id})">
                    <i class="bi bi-floppy-fill"></i> Save
                </button>
            </div>
            ${byLine}
        </div>`;
    });
    html += '</div>';
    area.innerHTML = html;

    _refreshEligibleBtn();
}

// ── Checklist helper: update row background and select colour when result changes ──
function _onResultChange(selectEl, checklistId){
    // Update the select's own colour class (existing behaviour)
    selectEl.className = 'cl-select ' + selectEl.value;

    // Update the parent row background
    const row = document.getElementById(`cl-item-${checklistId}`);
    if(row){
        row.classList.remove('cl-row-pending','cl-row-present','cl-row-missing','cl-row-not_applicable');
        row.classList.add('cl-row-' + selectEl.value);
    }

    // Refresh the Eligible button and hint message
    _refreshEligibleBtn();
}

// ── Refresh the Comply/Eligible button disabled state and show/hide hint ──
function _refreshEligibleBtn(){
    const complyBtn = document.getElementById('btn-comply');
    if(!complyBtn) return;   // non-manage view — nothing to do

    // Count selects that are still on "pending"
    const area = document.getElementById('eligChecklistArea');
    if(!area){ complyBtn.disabled = false; return; }

    const pendingSelects = area.querySelectorAll('.cl-select');
    let pendingCount = 0;
    pendingSelects.forEach(s => { if(s.value === 'pending') pendingCount++; });

    const hasPending = pendingCount > 0;

    // Disable / enable the Eligible button
    complyBtn.disabled = hasPending;
    complyBtn.classList.toggle('btn-eligible--pending', hasPending);
    complyBtn.title            = hasPending
        ? `Complete all checklist items before marking the bidder as Eligible. (${pendingCount} pending)`
        : '';

    // Show or remove the pending-hint bar that lives just below the checklist area
    const foot = document.querySelector('#eligModal .bsm-foot');
    if(!foot) return;

    let hint = document.getElementById('elig-pending-hint');
    if(hasPending){
        if(!hint){
            hint = document.createElement('div');
            hint.id        = 'elig-pending-hint';
            hint.className = 'cl-pending-hint';
            // Insert as the very first child of the footer so it spans full width above the buttons
            foot.insertBefore(hint, foot.firstChild);
        }
        hint.innerHTML = `<i class="bi bi-hourglass-split"></i> Complete all checklist items before marking the bidder as Eligible. <strong>(${pendingCount} pending)</strong>`;
    } else {
        if(hint) hint.remove();
    }
}

function _saveChecklistItem(checklistId){
    const resultEl  = document.getElementById(`cl-result-${checklistId}`);
    const remarksEl = document.getElementById(`cl-remarks-${checklistId}`);
    const saveBtn   = document.getElementById(`cl-save-${checklistId}`);
    if(!resultEl || !saveBtn) return;

    const origHtml = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    post({
        action:       'save_checklist_item',
        checklist_id: checklistId,
        result:       resultEl.value,
        remarks:      remarksEl ? remarksEl.value : '',
        session_id:   SESSION_ID
    }).then(d => {
        saveBtn.disabled = false;
        if(!d.success){
            saveBtn.innerHTML = '<i class="bi bi-x-circle-fill"></i>';
            setTimeout(()=>{ saveBtn.innerHTML = origHtml; }, 1500);
        } else {
            saveBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Saved';
            saveBtn.classList.add('cl-save-btn--saved');
            setTimeout(()=>{ saveBtn.innerHTML = origHtml; saveBtn.classList.remove('cl-save-btn--saved'); }, 1800);
            // Re-sync row background from the saved value (already matches what the user selected,
            // but calling _refreshEligibleBtn keeps the hint/button in sync after every save)
            _refreshEligibleBtn();
        }
    }).catch(()=>{
        saveBtn.disabled = false;
        saveBtn.innerHTML = origHtml;
    });
}

function submitElig(eligible){
    // ── Pending-item guard — only blocks Comply/Eligible, not Disqualify ──
    if(eligible){
        const area = document.getElementById('eligChecklistArea');
        if(area){
            const selects = area.querySelectorAll('.cl-select');
            let pendingCount = 0;
            selects.forEach(s => { if(s.value === 'pending') pendingCount++; });
            if(pendingCount > 0){
                // Button is visually disabled but guard here too in case of
                // a focus-Enter or other indirect trigger
                _refreshEligibleBtn(); // re-sync UI
                return;
            }
        }
    }

    closeElig();
    post({ action:'set_eligible', bid_id:el_bidId, lot_id:el_lotId, bid_lot_id:el_bidLotId, eligible, phase:el_phase, session_id:SESSION_ID })
    .then(d=>{
        if(!d.success){ alert(d.message); return; }

        // Reset signing state — next bidder needs a fresh signing cycle
        SIGNING_STATUS = 'not_started';

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
        row.className = 'cannot-proceed-row';
        row.innerHTML = `<i class="bi bi-x-circle-fill t-red shrink-0"></i>${item}`;
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
            promptEl.textContent = `This is the final lot! The session will now be concluded.`;
        }
        document.getElementById('doneLotModal').classList.add('open');
    })
    .catch(()=>{
        openCannotProceedModal('Error', ['Failed to verify lot status. Please try again.']);
    });
}
function closeDoneLotModal(){ document.getElementById('doneLotModal').classList.remove('open'); _catchUpAfterModal(); }

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
    }

    const nextIdx = curIdx + 1;
    if(nextIdx < LOTS.length){
        // Unlock next lot tab and advance
        const nextTab = document.getElementById('mtab-'+nextIdx);
        if(nextTab) nextTab.classList.remove('locked');
        STATE.activeLotIdx = nextIdx;
        // Reset status to 'started' so the eligibility gate shows for the new lot
        SESSION_STATUS = 'started';
        SIGNING_STATUS = 'not_started';
        post({ action:'set_current_lot', session_id:SESSION_ID, lot_id:LOTS[nextIdx].id })
        .then(()=> post({ action:'start_phase', session_id:SESSION_ID, phase:'started' }))
        .then(()=>{ refreshSessionProgressFromDB(); });
        switchMasterTab(nextIdx);
    } else {
        // All lots done — move to the Offered Bids tab instead of popping
        // the End Session modal immediately (avoids stacking two modals
        // back-to-back). Conclude & End Session now lives on that tab.
        STATE.activeLotIdx = -1; // no active lot
        SESSION_STATUS = 'offered';
        post({ action:'set_current_lot', session_id:SESSION_ID, lot_id:0 })
        .then(()=> post({ action:'start_phase', session_id:SESSION_ID, phase:'offered' }))
        .then(()=>{ refreshSessionProgressFromDB(); });
        const offeredTab = document.getElementById('mtab-offered');
        if(offeredTab) offeredTab.classList.remove('locked');
        switchMasterTab('offered');
    }
}

// ── Conclude & End Session ────────────────────────────────────────────────
function openEndSessionModal(){
    document.getElementById('endSessionModal').classList.add('open');
}
function closeEndSessionModal(){ document.getElementById('endSessionModal').classList.remove('open'); _catchUpAfterModal(); }

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
        stopPolling(); // session concluded — no more polling needed
        // Unlock conclusion tab, navigate to conclusion
        const cTab = document.getElementById('mtab-conclusion');
        if(cTab) cTab.classList.remove('locked');
        switchMasterTab('conclusion');
    }).catch(()=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> Yes, End Session';
        alert('Network error.');
    });
}

function mkEl(tag,cls){ const e=document.createElement(tag); if(cls) e.className=cls; return e; }

// ── Conclusion: render file list for a bidder+lot+phase (uses openedDocs cache) ──
function _conclusionFileList(bidderId, lotId, phase){
    // Fetch files from API then render inline — returns a placeholder div
    const id = `cl-files-${bidderId}-${lotId}-${phase}`;
    // Use a setTimeout so the DOM has been inserted before we try to populate it
    setTimeout(()=>{
        get({ action:'files', bidder_id:bidderId, lot_id:lotId, phase:phase, session_id:SESSION_ID })
        .then(d => {
            const el = document.getElementById(id);
            if(!el) return;
            const files = d.files || [];
            if(!files.length){ el.innerHTML = '<span class="fz-11 t-gray">No documents.</span>'; return; }
            el.innerHTML = files.map(f => {
                const opened = !!openedDocs[odKey(lotId, f.id)];
                const ext = (f.display_name||'').split('.').pop().toLowerCase();
                const icon = ext==='pdf' ? 'bi-file-earmark-pdf'
                           : ['jpg','jpeg','png','gif','webp'].includes(ext) ? 'bi-file-earmark-image'
                           : 'bi-file-earmark-text';
                return `<div class="list-row-sm">
                    <i class="bi ${esc(icon)} t-muted fz-13 shrink-0"></i>
                    <span class="flex-1 fz-11-5 t-dark truncate">${esc(f.display_name)}</span>
                    <button class="btn-view${opened?' active':''}" onclick="viewDoc(${lotId},${f.id})"
                        class="fz-11 pad-3-10"
                        title="${opened?'View document':'File not yet decrypted'}">
                        <i class="bi bi-eye"></i> View
                    </button>
                </div>`;
            }).join('');
            // Trigger silent decrypt for any not yet in cache
            (async ()=>{
                for(const f of files){
                    if(openedDocs[odKey(lotId, f.id)]) continue;
                    const res = await post({ action:'decrypt_file', doc_id:f.id, session_id:SESSION_ID });
                    if(res.success){
                        openedDocs[odKey(lotId, f.id)] = { data_url:res.data_url, mime:res.mime, file_name:res.file_name };
                        const vbtn = el.querySelector(`button[onclick="viewDoc(${lotId},${f.id})"]`);
                        if(vbtn){ vbtn.classList.add('active'); vbtn.title='View document'; }
                    }
                }
            })();
        });
    }, 0);
    return `<div id="${id}" class="pad-2-0"><span class="fz-11 t-gray">Loading…</span></div>`;
}

// ── Offered Bids Tab ─────────────────────────────────────────────────────
function loadOfferedBids(){
    const list = document.getElementById('offered-bids-list');
    if(!list) return;
    list.innerHTML = '<div class="pad-24 flexcol gap-12"><div class="skel h-60"></div><div class="skel h-60"></div></div>';

    Promise.all(LOTS.map(lot => get({ action:'bidders', lot_id:lot.id, phase:'financial', session_id:SESSION_ID })))
    .then(allBiddersData => {
        list.innerHTML = '';

        LOTS.forEach((lot, i)=>{
            const bidders = (allBiddersData[i] && allBiddersData[i].bidders) ? allBiddersData[i].bidders : [];

            const card = mkEl('div','award-lot-card');

            const head = mkEl('div','award-lot-head');
            head.innerHTML = `
                <div>
                    <div class="award-lot-title">Lot ${esc(lot.lot_number)}${lot.lot_title ? ' · '+esc(lot.lot_title) : ''}</div>
                    <div class="fz-11-5 t-graphite">Approved Budget: <strong class="t-forest font-sg">₱${parseFloat(lot.abc||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</strong></div>
                </div>
            `;
            card.appendChild(head);

            if(!bidders.length){
                const empty = mkEl('div','p-empty pad-20');
                empty.innerHTML = '<i class="bi bi-inbox fz-22"></i>No submitted bids for this lot.';
                card.appendChild(empty);
            } else {
                const bList = mkEl('div','award-bidders-list');
                bidders.forEach(b=>{
                    const isDisq = !!b.disqualified;
                    const bName  = b.business_name || (b.firstname + ' ' + b.lastname);
                    const ini    = (b.firstname||'').charAt(0).toUpperCase() + (b.lastname||'').charAt(0).toUpperCase();
                    const amtVal = (b.total_offered_bid !== null && b.total_offered_bid !== undefined) ? parseFloat(b.total_offered_bid).toFixed(2) : '';

                    const bCard = mkEl('div','award-bidder-card' + (isDisq ? ' disqualified' : ''));
                    let actionHtml = '';

                    if(isDisq){
                        actionHtml = `<span class="disq-badge fz-11 pad-3-8"><i class="bi bi-x-circle-fill"></i> Ineligible / Disqualified</span>`;
                    } else if(CAN_MANAGE){
                        actionHtml = `
                            <div class="award-amount-wrap">
                                ₱ <input type="number" step="0.01" class="award-amount-input" id="offered-amt-${b.bid_lot_id}"
                                    value="${esc(amtVal)}" placeholder="Amount"
                                    onchange="saveOfferedBid(${b.bid_lot_id}, this)">
                            </div>
                        `;
                    } else {
                        actionHtml = `<span class="fz-12 fw-7 t-dark">${amtVal ? '₱'+Number(amtVal).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—'}</span>`;
                    }

                    bCard.innerHTML = `
                        <div class="award-bidder-info">
                            <div class="bt-avatar">${b.avatar ? `<img src="../${esc(b.avatar)}" alt="">` : esc(ini)}</div>
                            <div>
                                <div class="fz-13 fw-7 t-dark">${esc(bName)}</div>
                                <div class="fz-11 t-muted">Bid Ref #${esc(b.bid_id)} · ${esc(b.submission_date||'')}</div>
                            </div>
                        </div>
                        <div class="award-bidder-actions">${actionHtml}</div>
                    `;
                    bList.appendChild(bCard);
                });
                card.appendChild(bList);
            }

            list.appendChild(card);
        });
    }).catch(()=>{
        list.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Failed to load bidders.</div>';
    });
}

function saveOfferedBid(bidLotId, inputEl){
    const amount = inputEl.value.trim();
    inputEl.disabled = true;
    post({ action:'save_offered_bid', session_id:SESSION_ID, bid_lot_id:bidLotId, amount:amount })
    .then(d=>{
        inputEl.disabled = false;
        if(!d.success){ alert(d.message || 'Failed to save amount.'); }
    })
    .catch(()=>{
        inputEl.disabled = false;
        alert('Network error while saving amount.');
    });
}

// ── Conclusion Tab ────────────────────────────────────────────────────────
function loadConclusion(){
    const body = document.getElementById('conclusion-body');
    if(!body) return;
    body.innerHTML = '<div class="flexcol gap-10"><div class="skel h-80 r-12"></div><div class="skel h-140 r-12"></div><div class="skel h-140 r-12"></div></div>';

    get({ action:'progress', session_id:SESSION_ID })
    .then((prog)=>{
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
        <div class="info-card">
            <div class="eyebrow-label-lg mb-12">Session Info</div>
            <div class="grid-3col gap-12">
                <div>
                    <div class="fz-11 t-muted fw-6 mb-3"><i class="bi bi-play-circle-fill t-forest"></i> Started</div>
                    <div class="fz-13 fw-7 t-dark">${esc(fmtDate(startedAt))}</div>
                </div>
                <div>
                    <div class="fz-11 t-muted fw-6 mb-3"><i class="bi bi-stop-circle-fill t-red"></i> Concluded</div>
                    <div class="fz-13 fw-7 t-dark">${esc(fmtDate(endedAt))}</div>
                </div>
                <div>
                    <div class="fz-11 t-muted fw-6 mb-3"><i class="bi bi-clock-fill t-amber"></i> Duration</div>
                    <div class="fz-13 fw-7 t-dark">${durationHtml}</div>
                </div>
            </div>
        </div>`;

        // Lots result cards
        html += `<div class="eyebrow-label-lg mb-10">Lot Results</div>`;
        html += `<div class="flexcol gap-10">`;

        LOTS.forEach(lot => {
            const lotName = `Lot ${lot.lot_number}${lot.lot_title ? ' · '+lot.lot_title : ''}`;
            html += `
            <div class="result-box result-box--teal">
                <div class="flexc gap-12">
                    <div class="notice-icon notice-icon--teal-gold"><i class="bi bi-unlock-fill"></i></div>
                    <div>
                        <div class="fz-12 fw-8 t-dark">${esc(lotName)}</div>
                        <div class="fz-12-5 fw-7 t-teal mt-2"><i class="bi bi-check2-circle"></i> Opened</div>
                    </div>
                </div>
            </div>`;
        });

        html += `</div>`;

        // Summary counts
        const totalLots = LOTS.length;
        html += `
        <div class="quorum-summary-bar">
            <div class="flex-1 min-w-80 ta-center">
                <div class="fz-22 fw-8 t-gold font-sg">${totalLots}</div>
                <div class="fz-11 t-mint fw-6 mt-2">Total Lots</div>
            </div>
            <div class="flex-1 min-w-80 ta-center">
                <div class="fz-22 fw-8 t-lightgreen font-sg">${totalLots}</div>
                <div class="fz-11 t-mint fw-6 mt-2">Opened</div>
            </div>
        </div>`;

        // Set main content first
        body.innerHTML = html;

        // ── Bidder files per lot — fetched after main HTML is in DOM ──────
        const bFilesSection = document.createElement('div');
        body.appendChild(bFilesSection);

        Promise.all(LOTS.map(lot =>
            Promise.all([
                get({ action:'bidders', lot_id:lot.id, phase:'eligibility', session_id:SESSION_ID }),
                get({ action:'bidders', lot_id:lot.id, phase:'financial',   session_id:SESSION_ID })
            ]).then(([ed, fd]) => ({ lot, eligBidders: ed.bidders||[], finBidders: fd.bidders||[] }))
        )).then(lotBidders => {
            let bHtml = `<div class="eyebrow-label-lg mt-18 mb-10">Submitted Bid Documents</div>`;
            lotBidders.forEach(({ lot, eligBidders }) => {
                if(!eligBidders.length) return;
                const lotName = `Lot ${lot.lot_number}${lot.lot_title ? ' · '+lot.lot_title : ''}`;

                // Rank eligible bidders with a recorded offered amount — lowest
                // offered amount = Rank 1 (lowest calculated responsive bid).
                const hasAmount = b => b.total_offered_bid !== null && b.total_offered_bid !== undefined && b.total_offered_bid !== '';
                const rankable = eligBidders
                    .filter(b => b.eligibility_status === 'eligible' && hasAmount(b))
                    .slice()
                    .sort((a,b) => parseFloat(a.total_offered_bid) - parseFloat(b.total_offered_bid));
                const rankMap = {};
                rankable.forEach((b, idx) => { rankMap[b.bidder_id] = idx + 1; });

                bHtml += `<div class="bordered-card mb-14">
                    <div class="section-head-bar">
                        <i class="bi bi-layers-fill t-forest"></i> ${esc(lotName)}
                    </div>`;

                eligBidders.forEach(b => {
                    const bName = b.business_name || (b.firstname+' '+b.lastname);
                    const ini   = (b.firstname||'').charAt(0).toUpperCase()+(b.lastname||'').charAt(0).toUpperCase();
                    const statusClass = b.eligibility_status==='eligible' ? 'status-text--eligible' : b.eligibility_status==='disqualified' ? 'status-text--disqualified' : 'status-text--pending';
                    const statusLabel = b.eligibility_status==='eligible' ? 'Eligible' : b.eligibility_status==='disqualified' ? 'Disqualified' : b.eligibility_status;
                    const rank = rankMap[b.bidder_id];
                    const rankClass = rank===1?'rank-badge--gold':rank===2?'rank-badge--silver':rank===3?'rank-badge--bronze':'rank-badge--default';
                    const rankBadge = rank ? `<span class="rank-badge ${rankClass}">#${rank}</span>` : `<span class="rank-badge rank-badge--none">—</span>`;
                    const amtDisplay = hasAmount(b) ? '₱'+parseFloat(b.total_offered_bid).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';

                    bHtml += `<div class="pad-12-16 border-b">
                        <div class="flexc gap-10 mb-10">
                            <div class="avatar-28 avatar-28--dark">
                                ${b.avatar ? `<img src="../${esc(b.avatar)}" class="w-full-h-full-cover">` : esc(ini)}
                            </div>
                            <div class="fz-12-5 fw-7 t-dark flex-1">${esc(bName)}</div>
                            <span class="status-text ${statusClass}">${esc(statusLabel)}</span>
                        </div>
                        <div class="flexc gap-10 mb-10 fz-11-5">
                            ${rankBadge}
                            <span class="t-muted">Offered Bid:</span>
                            <strong class="t-forest font-sg">${amtDisplay}</strong>
                        </div>
                        <div class="eyebrow-label mb-4">Eligibility Documents</div>
                        ${_conclusionFileList(b.bidder_id, lot.id, 'eligibility')}
                        ${b.eligibility_status !== 'disqualified' ? `
                        <div class="eyebrow-label mt-8 mb-4">Financial Documents</div>
                        ${_conclusionFileList(b.bidder_id, lot.id, 'financial')}` : ''}
                    </div>`;
                });

                bHtml += `</div>`;
            });
            bFilesSection.innerHTML = bHtml;
        });
    }).catch(()=>{
        body.innerHTML = '<div class="p-empty"><i class="bi bi-exclamation-circle"></i>Failed to load session summary.</div>';
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// REALTIME SYNC — Pusher WebSocket + 30-second fallback heartbeat
//
// All state-changing API actions fire a Pusher event on channel
// 'session-{SESSION_ID}'. The JS client receives the event instantly and
// calls the appropriate silent-refresh helper, so every connected role
// (Secretariat, BAC, TWG) sees changes without polling on every tick.
//
// The 30-second heartbeat is a safety net for rare missed events
// (reconnects, browser tab wakes from sleep, etc.). It does nothing if
// everything is already up to date (fingerprint unchanged).
//
// Polling is completely removed. The only interval is the heartbeat.
// ═══════════════════════════════════════════════════════════════════════════

// ── Shared state (used by both Pusher handlers and heartbeat) ─────────────

const _rt = {
    pusher:            null,   // Pusher instance
    channel:           null,   // subscribed channel
    heartbeatTimer:    null,   // setInterval handle for the fallback
    lastProgressHash:  '',     // fingerprint of the last progress response
    lastBiddersHash:   '',     // fingerprint of the last bidders response
    lastSigningStatus: '',     // last known signing_status (quorum bar trigger)
    _activeBidLotId:   0,      // bid_lot_id of the currently selected bidder
};

// Keep the legacy _poll alias so _silentRefresh* helpers still work
// (they reference _poll._activeBidLotId and _poll.last*Hash)
const _poll = _rt;

// ── Fingerprinting helpers ────────────────────────────────────────────────

function _hashProgress(data){
    const lots = (data.lots || []).map(l =>
        l.id + ':' + (+!!l.is_done) + ':' + l.pending_elig + ':' + l.pending_fin
    ).join('|');
    return (data.status||'') + '|' + (data.signing_status||'') + '|' + (data.current_lot_id||0) + '|' + lots;
}

function _hashBidders(bidders){
    return (bidders || []).map(b =>
        b.bidder_id + ':' + b.eligibility_status + ':' + b.financial_status + ':' + (+!!b.files_opened)
    ).join('|');
}

// ── Modal guard ───────────────────────────────────────────────────────────

function _anyModalOpen(){
    return document.querySelectorAll('.bsm-bg.open').length > 0;
}

// ── Silent refresh helpers ────────────────────────────────────────────────

function _silentRefreshBidders(lotId, stage){
    if(!lotId || !stage) return;
    get({ action:'bidders', lot_id:lotId, phase:stage, session_id:SESSION_ID })
    .then(bd => {
        const bidders = bd.bidders || [];
        const newHash = _hashBidders(bidders);
        if(newHash === _rt.lastBiddersHash) return;
        _rt.lastBiddersHash = newHash;
        if(!STATE.lots[lotId])
            STATE.lots[lotId] = { stage, bidders:{}, done:{ eligibility:[], financial:[] } };
        STATE.lots[lotId].bidders[stage] = bidders;
        const activeLot = LOTS[STATE.activeLotIdx];
        if(!activeLot || activeLot.id !== lotId) return;
        renderLotBidderArea(lotId, stage, bidders);
    })
    .catch(function(){});
}

function _silentRefreshQuorum(lotId, stage){
    if(!lotId || !stage || !_rt._activeBidLotId) return;
    const stageBidders = (STATE.lots[lotId] && STATE.lots[lotId].bidders[stage]) || [];
    const activeBidder = stageBidders.find(b => b.bid_lot_id === _rt._activeBidLotId);
    if(!activeBidder) return;
    const activeLot = LOTS[STATE.activeLotIdx];
    if(!activeLot || activeLot.id !== lotId) return;
    Promise.all([
        get({ action:'check_quorum', bid_lot_id:_rt._activeBidLotId, opening_type:stage, session_id:SESSION_ID }),
        get({ action:'files', bidder_id:activeBidder.bidder_id, lot_id:lotId, phase:stage, session_id:SESSION_ID })
    ]).then(([qd, fd]) => {
        const btns = document.getElementById('actbtns-' + activeBidder.bidder_id);
        if(!btns) return;
        _renderActionButtons(activeBidder, stage, lotId, fd.files || [], qd);
        const fileArea = document.querySelector('#lot-bidder-area .files-pane');
        if(fileArea && (fd.files || []).length > 0) renderFiles(fileArea, fd.files, qd, lotId);
    }).catch(function(){});
}

// ── Core progress sync (shared by Pusher events and heartbeat) ────────────

function _handleProgressUpdate(data){
    if(!data || !data.status) return;

    // Session ended
    if(data.status === 'ended' && SESSION_STATUS !== 'ended'){
        SESSION_STATUS = 'ended';
        stopRealtimeSync();
        refreshSessionProgressFromDB();
        // Viewers who didn't trigger the end themselves (BAC/TWG watching the
        // session) never get switched to the Conclusion tab otherwise — the
        // person who clicked "End Session" does this themselves in
        // doEndSession(), but everyone else only has the tab unlocked.
        const cTab = document.getElementById('mtab-conclusion');
        if(cTab) cTab.classList.remove('locked');
        switchMasterTab('conclusion');
        return;
    }

    const newProgressHash = _hashProgress(data);
    const signingChanged  = data.signing_status !== _rt.lastSigningStatus;

    if(newProgressHash !== _rt.lastProgressHash){
        _rt.lastProgressHash  = newProgressHash;
        _rt.lastSigningStatus = data.signing_status || 'not_started';

        const prevStatus = SESSION_STATUS;
        SESSION_STATUS   = data.status;
        SIGNING_STATUS   = data.signing_status || 'not_started';

        refreshSessionProgressFromDB();

        const activeLot   = LOTS[STATE.activeLotIdx];
        const activeLotId = activeLot ? activeLot.id : 0;

        if(prevStatus !== data.status &&
           activeLotId > 0 &&
           (data.status === 'eligibility' || data.status === 'financial')){
            fetchAndRenderBiddersByDB(activeLotId);
            return;
        }
    } else {
        _rt.lastSigningStatus = data.signing_status || 'not_started';
    }

    const activeLot   = LOTS[STATE.activeLotIdx];
    const activeLotId = activeLot ? activeLot.id : 0;
    const stage       = (SESSION_STATUS === 'financial') ? 'financial' : 'eligibility';
    const onLotTab    = typeof STATE.currentTab === 'number';

    if(onLotTab && activeLotId > 0 &&
       (SESSION_STATUS === 'eligibility' || SESSION_STATUS === 'financial' || SESSION_STATUS === 'started')){
        _silentRefreshBidders(activeLotId, stage);
        if(signingChanged) _silentRefreshQuorum(activeLotId, stage);
    }
}

// ── Pusher event handlers ─────────────────────────────────────────────────
//
// Each handler responds to the specific Pusher event immediately rather than
// waiting for the next heartbeat tick. For events that affect the full
// session-level state (phase changes, session end), we fetch `progress` to
// get the authoritative server state. For targeted events (signing, checklist)
// we refresh only the affected component.

function _onPusherEvent(event, data){
    // Signing events are allowed through even when a modal is open —
    // they only update state behind the modal (quorum bars, action buttons).
    const signingEvent = (event === 'bac_signed' || event === 'signing_started' || event === 'files_opened');

    if(!signingEvent && _anyModalOpen()) return; // never disrupt an in-progress user action

    const activeLot   = LOTS[STATE.activeLotIdx];
    const activeLotId = activeLot ? activeLot.id : 0;
    const stage       = (SESSION_STATUS === 'financial') ? 'financial' : 'eligibility';

    switch(event){

        // ── Session lifecycle ─────────────────────────────────────────────
        case 'session_started':
        case 'phase_changed':
        case 'lot_changed':
        case 'session_ended':
            // Fetch authoritative progress and let _handleProgressUpdate drive all UI
            get({ action:'progress', session_id:SESSION_ID })
            .then(_handleProgressUpdate)
            .catch(function(){});
            break;

        // ── Signing flow ──────────────────────────────────────────────────
        case 'signing_started':
            // Secretariat hit "Start Opening" — full re-render for everyone.
            if(activeLotId > 0 && typeof STATE.currentTab === 'number'){
                _rt.lastBiddersHash = '';
                fetchAndRenderBiddersByDB(activeLotId);
            }
            break;

        case 'bac_signed':
            // A BAC member signed — always do a full guaranteed re-render.
            // Skip _silentRefreshBidders entirely — its hash guard can still
            // bail even after a reset if a concurrent call updates the hash
            // before the response arrives. fetchAndRenderBiddersByDB has no
            // hash check and always re-renders with fresh quorum from DB.
            if(activeLotId > 0 && typeof STATE.currentTab === 'number'){
                _rt.lastSigningStatus = data.signing_status || 'signing';
                SIGNING_STATUS = data.signing_status || 'signing';
                _rt.lastBiddersHash = '';
                fetchAndRenderBiddersByDB(activeLotId);
            }
            break;

        case 'files_opened':
            // Secretariat clicked "Open Now" — everyone sees files unlocked.
            if(activeLotId > 0 && typeof STATE.currentTab === 'number'){
                SIGNING_STATUS = 'done';
                _rt.lastBiddersHash = '';
                _silentRefreshBidders(activeLotId, stage);
            }
            break;

        // ── Eligibility / financial result ────────────────────────────────
        case 'eligibility_updated':
            if(activeLotId > 0 && typeof STATE.currentTab === 'number'){
                _silentRefreshBidders(activeLotId, stage);
            }
            // Also update progress fingerprint to keep tab badges fresh
            get({ action:'progress', session_id:SESSION_ID })
            .then(_handleProgressUpdate)
            .catch(function(){});
            break;

        // ── Checklist ─────────────────────────────────────────────────────
        case 'checklist_updated':
            // Only relevant if the checklist modal is open for this bid_lot —
            // but since the modal guard blocks Pusher while it's open, this
            // will fire after it closes, which is fine. The next modal open
            // will re-fetch from DB anyway.
            break;

    }
}

// ── 30-second heartbeat fallback ──────────────────────────────────────────
// Catches any events missed during reconnects or browser sleep.

function _heartbeatTick(){
    if(_anyModalOpen()) return;
    get({ action:'progress', session_id:SESSION_ID })
    .then(_handleProgressUpdate)
    .catch(function(){});
}

// ── Catch up after a modal closes ────────────────────────────────────────
// Any Pusher events that fired while the modal was open were dropped by the
// modal guard. Call this in every close function to immediately re-sync
// progress + the active bidder area instead of waiting for the 30s heartbeat.

function _catchUpAfterModal(){
    const activeLot   = LOTS[STATE.activeLotIdx];
    const activeLotId = activeLot ? activeLot.id : 0;
    const stage       = (SESSION_STATUS === 'financial') ? 'financial' : 'eligibility';
    const onLotTab    = typeof STATE.currentTab === 'number';

    // Always re-sync session-level state (hero pill, tab badges, sidebar)
    get({ action:'progress', session_id:SESSION_ID })
    .then(_handleProgressUpdate)
    .catch(function(){});

    // Re-sync the active lot's bidder area
    if(onLotTab && activeLotId > 0){
        _rt.lastBiddersHash = ''; // force re-render even if data unchanged
        _silentRefreshBidders(activeLotId, stage);
    }
}

// ── Start / stop ──────────────────────────────────────────────────────────

function startRealtimeSync(){
    // ── Pusher WebSocket ──────────────────────────────────────────────────
    _rt.pusher = new Pusher('<?= htmlspecialchars($_ENV['PUSHER_APP_KEY'] ?? '') ?>', {
        cluster: '<?= htmlspecialchars($_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3') ?>',
        forceTLS: true,
    });

    _rt.channel = _rt.pusher.subscribe('session-' + SESSION_ID);

    const events = [
        'session_started', 'phase_changed', 'lot_changed', 'session_ended',
        'signing_started', 'bac_signed', 'files_opened',
        'eligibility_updated', 'checklist_updated',
    ];

    events.forEach(function(ev){
        _rt.channel.bind(ev, function(data){ _onPusherEvent(ev, data || {}); });
    });

    // Log connection state changes (useful for debugging)
    _rt.pusher.connection.bind('state_change', function(states){
        console.log('[Pusher] ' + states.previous + ' → ' + states.current);
    });

    // ── 30-second heartbeat fallback ──────────────────────────────────────
    _rt.heartbeatTimer = setInterval(_heartbeatTick, 30000);
}

function stopRealtimeSync(){
    if(_rt.heartbeatTimer){ clearInterval(_rt.heartbeatTimer); _rt.heartbeatTimer = null; }
    if(_rt.channel)  { _rt.pusher.unsubscribe('session-' + SESSION_ID); _rt.channel = null; }
    if(_rt.pusher)   { _rt.pusher.disconnect(); _rt.pusher = null; }
}

// Legacy aliases so any existing call to startPolling/stopPolling still works
function startPolling(){ startRealtimeSync(); }
function stopPolling() { stopRealtimeSync();  }

// ── Initialization ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', ()=>{
    refreshSessionProgressFromDB();
    // Start Pusher WebSocket + 30s heartbeat fallback
    if(SESSION_STATUS !== 'ended'){
        startRealtimeSync();
    }
    if(SESSION_STATUS === 'ended'){
        // Unlock all lot tabs as done, unlock conclusion, go straight to conclusion
        LOTS.forEach((_, i)=>{
            const t = document.getElementById('mtab-'+i);
            if(t){ t.classList.remove('locked'); t.classList.add('done'); }
        });
        const cTab = document.getElementById('mtab-conclusion');
        if(cTab) cTab.classList.remove('locked');
        switchMasterTab('conclusion');
    } else if(SESSION_STATUS === 'offered' || (LOTS.length > 0 && LOTS.every(l => l.is_done))){
        // Reload while sitting on the Offered Bids tab, before End Session
        // was confirmed — go straight back there instead of reopening a lot.
        // The `every(is_done)` fallback covers the brief gap where the last
        // lot's Done was confirmed but the 'offered' phase hadn't finished
        // persisting to the DB yet.
        LOTS.forEach((_, i)=>{
            const t = document.getElementById('mtab-'+i);
            if(t){ t.classList.remove('locked','active'); t.classList.add('done'); }
        });
        const oTab = document.getElementById('mtab-offered');
        if(oTab) oTab.classList.remove('locked');
        STATE.activeLotIdx = -1;
        switchMasterTab('offered');
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
            } else if(i === initialIdx){
                t.classList.remove('locked');
                t.classList.add('active');
            } else {
                t.classList.add('locked');
                t.classList.remove('active','done');
            }
        });

        STATE.activeLotIdx = initialIdx;
        STATE.currentTab   = initialIdx;

        if(LOTS.length > 0){
            switchMasterTab(initialIdx);
        } else {
            switchMasterTab('conclusion');
        }
    }
});
</script>

</body>
</html>
