<?php
/**
 * live.php — Public Bid Opening Live Viewer
 *
 * Read-only viewer for a bid-opening session.
 * Authenticated bidders can participate in live chat.
 * Admins can moderate chat from the admin panel.
 *
 * URL: /live.php?procurement_id=N
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/mediamtx.php';
require_once __DIR__ . '/config/pusher.php';   // loads .env + makes $_ENV['PUSHER_APP_KEY'] available
session_start();

// ── Auth state ─────────────────────────────────────────────────────────────
$viewer_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$viewer_role = $_SESSION['role']     ?? 'guest';
$viewer_name = $_SESSION['username'] ?? '';
$can_chat    = ($viewer_role === 'bidder');
$is_admin    = in_array($viewer_role, ['admin', 'superadmin']);

// ── Resolve procurement_id from URL ───────────────────────────────────────
$procurement_id = (int)($_GET['procurement_id'] ?? 0);

// If no procurement_id given, fall back to the most recent active session
if ($procurement_id <= 0) {
    $fb = $conn->query("
        SELECT p.id FROM bid_opening_sessions bos
        JOIN procurements p ON p.id = bos.procurement_id
        WHERE bos.status IN ('eligibility','financial','awarding','started','scheduled')
        ORDER BY FIELD(bos.status,'eligibility','financial','awarding','started','scheduled'), bos.started_at DESC
        LIMIT 1
    ");
    if ($fb) {
        $fb_row = $fb->fetch_assoc();
        $procurement_id = (int)($fb_row['id'] ?? 0);
    }
}

// ── Load procurement + session ─────────────────────────────────────────────
$procurement = null;
$session     = null;
$lots        = [];
$page_error  = null;

if ($procurement_id > 0) {
    // Validate procurement exists
    $ps = $conn->prepare("
        SELECT id, title, slsu_ref_no, description, abc,
               procurement_mode, procurement_type, opening_date, closing_date, status
        FROM procurements
        WHERE id = ?
        LIMIT 1
    ");
    $ps->bind_param("i", $procurement_id);
    $ps->execute();
    $procurement = $ps->get_result()->fetch_assoc();
    $ps->close();

    if (!$procurement) {
        $page_error = 'Procurement not found.';
    } else {
        // Load associated bid opening session
        $ss = $conn->prepare("
            SELECT id, stream_path, title AS session_title, status, signing_status,
                   current_lot_id, started_at, ended_at
            FROM bid_opening_sessions
            WHERE procurement_id = ?
            ORDER BY
                FIELD(status,'eligibility','financial','awarding','started','scheduled','ended') ASC,
                started_at DESC
            LIMIT 1
        ");
        $ss->bind_param("i", $procurement_id);
        $ss->execute();
        $session = $ss->get_result()->fetch_assoc();
        $ss->close();

        if (!$session) {
            $page_error = 'No bid opening session found for this procurement.';
        } else {
            // Load lots for this procurement
            $ls = $conn->prepare("
                SELECT l.id, l.lot_number, l.lot_title, l.abc, l.status AS lot_status,
                       COUNT(bl.id) AS bid_count
                FROM lots l
                LEFT JOIN bid_lots bl ON bl.lot_id = l.id
                WHERE l.procurement_id = ?
                GROUP BY l.id
                ORDER BY l.lot_number ASC
            ");
            $ls->bind_param("i", $procurement_id);
            $ls->execute();
            $lots = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
            $ls->close();
        }
    }
} else {
    $page_error = 'No active bid opening session found.';
}

// ── Derived state ──────────────────────────────────────────────────────────
$is_live      = $session && in_array($session['status'], ['eligibility','financial','awarding','started']);
$is_scheduled = $session && $session['status'] === 'scheduled';
$is_ended     = $session && $session['status'] === 'ended';
$session_id   = $session ? (int)$session['id'] : 0;

// Current lot
$current_lot = null;
if ($session && $session['current_lot_id']) {
    foreach ($lots as $l) {
        if ((int)$l['id'] === (int)$session['current_lot_id']) {
            $current_lot = $l;
            break;
        }
    }
}

// Stream URL — from DB session stream_path
$stream_path = $session['stream_path'] ?? mediamtx_default_path();
$stream_url  = mediamtx_url($stream_path);

// Recent chat messages (last 60, oldest first)
$comments = [];
if ($session_id > 0) {
    $cs = $conn->prepare("
        SELECT lc.id, lc.comment, lc.created_at,
               u.username, u.firstname, u.lastname, u.profile_picture_url
        FROM live_comments lc
        JOIN users u ON lc.user_id = u.user_id
        WHERE lc.bid_session_id = ? AND lc.status = 'visible'
        ORDER BY lc.created_at DESC
        LIMIT 60
    ");
    $cs->bind_param("i", $session_id);
    $cs->execute();
    $rows = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
    $cs->close();
    $comments = array_reverse($rows);
}
$last_comment_id = !empty($comments) ? (int)end($comments)['id'] : 0;

// ── Status label helpers ───────────────────────────────────────────────────
function sessionStatusLabel(string $status): string {
    return match($status) {
        'scheduled'   => 'Scheduled',
        'started'     => 'Starting',
        'eligibility' => 'Eligibility Review',
        'financial'   => 'Financial Review',
        'awarding'    => 'Awarding',
        'ended'       => 'Session Ended',
        default       => ucfirst($status),
    };
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M j', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $procurement ? htmlspecialchars($procurement['title']) . ' — Live' : 'Bid Opening Live' ?> | YesParency</title>
<link rel="icon" type="image/png" href="images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- Pusher JS SDK — same version as admin/bid_session.php -->
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
<style>
/* ─────────────────────────────────────────────────────────────────────────
   RESET / BASE
───────────────────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #0b1812;
    --surface:   #112a1e;
    --surface2:  #0f2318;
    --border:    rgba(255,255,255,0.08);
    --border2:   rgba(255,255,255,0.05);
    --text:      #e2ede6;
    --text-muted:#7a9a83;
    --accent:    #ffc107;
    --green:     #1f7a3d;
    --green-l:   #4ade80;
    --red:       #dc2626;
    --blue:      #3b82f6;
    --radius:    14px;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
}

/* ─────────────────────────────────────────────────────────────────────────
   TOPBAR
───────────────────────────────────────────────────────────────────────── */
.topbar {
    position: sticky;
    top: 0;
    z-index: 200;
    background: rgba(11,24,18,0.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    gap: 12px;
}

.tb-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    flex-shrink: 0;
}

.tb-brand img { width: 30px; height: 30px; object-fit: contain; }
.tb-brand span { font-size: 15px; font-weight: 800; color: #fff; }

.tb-center {
    flex: 1;
    min-width: 0;
    text-align: center;
    overflow: hidden;
}

.tb-proc-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tb-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 11px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}

.status-badge.live     { background: rgba(220,38,38,.2);  color: #f87171; border: 1px solid rgba(220,38,38,.3); animation: pulseBadge 2s infinite; }
.status-badge.sched    { background: rgba(217,119,6,.2);  color: #fbbf24; border: 1px solid rgba(217,119,6,.3); }
.status-badge.ended    { background: rgba(100,116,139,.2);color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
.status-badge.offline  { background: rgba(75,85,99,.2);   color: #9ca3af; border: 1px solid rgba(75,85,99,.3); }

@keyframes pulseBadge { 0%,100%{opacity:1} 50%{opacity:.65} }

.live-dot {
    width: 7px; height: 7px; border-radius: 50%; background: currentColor;
}

.tb-link {
    font-size: 12px;
    font-weight: 700;
    color: var(--accent);
    text-decoration: none;
    padding: 5px 12px;
    border: 1px solid rgba(255,193,7,.3);
    border-radius: 8px;
    transition: all .15s;
}
.tb-link:hover { background: var(--accent); color: #06251b; }

/* ─────────────────────────────────────────────────────────────────────────
   HERO BANNER
───────────────────────────────────────────────────────────────────────── */
.hero-banner {
    background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #143d20 100%);
    border-bottom: 1px solid rgba(255,255,255,.06);
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10.5px;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 20px;
    white-space: nowrap;
}
.hero-badge.ref  { background: rgba(255,255,255,.1); color: #d1fae5; border: 1px solid rgba(255,255,255,.15); }
.hero-badge.mode { background: rgba(255,193,7,.15); color: var(--accent); border: 1px solid rgba(255,193,7,.25); }
.hero-badge.type { background: rgba(59,130,246,.15); color: #93c5fd; border: 1px solid rgba(59,130,246,.25); }

.hero-title {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    line-height: 1.3;
    flex: 1 1 100%;
}

.hero-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    flex: 1 1 100%;
}

.hero-metric { display: flex; flex-direction: column; gap: 2px; }
.hero-metric-label { font-size: 10px; font-weight: 700; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .5px; }
.hero-metric-val { font-size: 14px; font-weight: 800; color: #fff; font-family: 'Space Grotesk', sans-serif; }
.hero-metric-val.gold { color: var(--accent); }

/* ─────────────────────────────────────────────────────────────────────────
   MAIN GRID
───────────────────────────────────────────────────────────────────────── */
.page-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 0;
    min-height: calc(100vh - 58px - 80px); /* topbar + hero approximate */
}

@media (max-width: 1100px) {
    .page-grid { grid-template-columns: 1fr; }
}

/* ─────────────────────────────────────────────────────────────────────────
   LEFT COLUMN
───────────────────────────────────────────────────────────────────────── */
.main-col {
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--border);
    min-width: 0;
}

@media (max-width: 1100px) {
    .main-col { border-right: none; border-bottom: 1px solid var(--border); }
}

/* Video player */
.video-wrap {
    background: #000;
    position: relative;
    aspect-ratio: 16 / 9;
    width: 100%;
    flex-shrink: 0;
}

.video-wrap iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: none;
}

.video-offline {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    background: #0b1612;
    color: var(--text-muted);
}

.video-offline i  { font-size: 52px; color: #1f3828; }
.video-offline h3 { font-size: 15px; font-weight: 700; color: #3a5a44; }
.video-offline p  { font-size: 12.5px; text-align: center; max-width: 280px; }

/* Session status panel */
.session-panel {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
}

.sp-heading {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--text-muted);
    margin-bottom: 14px;
}

.sp-stage {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.sp-stage-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.sp-stage-icon.elig  { background: rgba(30,122,61,.25); color: #4ade80; }
.sp-stage-icon.fin   { background: rgba(59,130,246,.2); color: #93c5fd; }
.sp-stage-icon.award { background: rgba(255,193,7,.15); color: #ffc107; }
.sp-stage-icon.sched { background: rgba(217,119,6,.15); color: #fbbf24; }
.sp-stage-icon.ended { background: rgba(100,116,139,.15); color: #94a3b8; }

.sp-stage-text { flex: 1; min-width: 0; }
.sp-stage-label { font-size: 15px; font-weight: 800; color: #fff; }
.sp-stage-sub   { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

/* Lots list */
.lots-panel {
    padding: 16px 22px 20px;
}

.lots-grid {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 10px;
}

.lot-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border2);
    transition: border-color .15s;
}

.lot-row.lot-active {
    background: rgba(31,122,61,.1);
    border-color: rgba(31,122,61,.35);
}

.lot-row.lot-done {
    background: rgba(74,222,128,.05);
    border-color: rgba(74,222,128,.12);
}

.lot-row.lot-failed {
    background: rgba(220,38,38,.05);
    border-color: rgba(220,38,38,.12);
    opacity: .7;
}

.lot-num {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    flex-shrink: 0;
    font-family: 'Space Grotesk', sans-serif;
}
.lot-num.active  { background: var(--green);  color: #fff; }
.lot-num.done    { background: rgba(74,222,128,.2); color: #4ade80; }
.lot-num.failed  { background: rgba(220,38,38,.15); color: #f87171; }
.lot-num.pending { background: rgba(255,255,255,.08); color: var(--text-muted); }

.lot-name { flex: 1; font-size: 12.5px; font-weight: 700; color: #d1fae5; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lot-sub  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

.lot-state-pill {
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 10px;
    white-space: nowrap;
    flex-shrink: 0;
}
.lot-state-pill.active  { background: rgba(31,122,61,.3); color: #4ade80; }
.lot-state-pill.done    { background: rgba(74,222,128,.15); color: #86efac; }
.lot-state-pill.failed  { background: rgba(220,38,38,.15); color: #fca5a5; }
.lot-state-pill.pending { background: rgba(255,255,255,.06); color: var(--text-muted); }

/* ─────────────────────────────────────────────────────────────────────────
   RIGHT COLUMN — CHAT
───────────────────────────────────────────────────────────────────────── */
.chat-col {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 58px - 80px);
    position: sticky;
    top: 58px;
    min-width: 0;
}

@media (max-width: 1100px) {
    .chat-col {
        position: static;
        height: 520px;
    }
}

.chat-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    background: var(--surface2);
}

.chat-head-title {
    font-size: 13px;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 7px;
}

.chat-head-title i { color: var(--accent); }

.chat-count {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--text-muted);
    background: rgba(255,255,255,.07);
    padding: 2px 8px;
    border-radius: 10px;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 14px 14px 8px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    scroll-behavior: smooth;
}

.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-track { background: transparent; }
.chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 2px; }

.chat-msg { display: flex; align-items: flex-start; gap: 9px; }

.chat-av {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--green); color: #fff;
    font-size: 11px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}

.chat-av img { width: 100%; height: 100%; object-fit: cover; }

.chat-bubble {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border2);
    border-radius: 0 10px 10px 10px;
    padding: 8px 11px;
    flex: 1;
    min-width: 0;
}

.bubble-name { font-size: 11px; font-weight: 800; color: var(--accent); margin-bottom: 3px; }
.bubble-text { font-size: 12.5px; color: #cfe8d4; line-height: 1.45; word-break: break-word; }
.bubble-time { font-size: 10px; color: #4a6052; margin-top: 4px; }

.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #3a5a44;
    font-size: 12.5px;
    text-align: center;
    padding: 24px;
}

.chat-empty i { font-size: 36px; }

.chat-input {
    padding: 12px 14px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--surface2);
}

.chat-form { display: flex; gap: 8px; align-items: flex-end; }

.chat-ta {
    flex: 1;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px;
    padding: 9px 12px;
    color: var(--text);
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    resize: none;
    outline: none;
    min-height: 40px;
    max-height: 80px;
    transition: border-color .15s;
}

.chat-ta:focus { border-color: var(--green); }
.chat-ta::placeholder { color: #3a5a44; }

.chat-send {
    width: 40px; height: 40px; flex-shrink: 0;
    background: var(--green); color: #fff;
    border: none; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; cursor: pointer; transition: background .15s;
}

.chat-send:hover { background: #14592d; }
.chat-send:disabled { opacity: .45; cursor: not-allowed; }

.chat-notice {
    font-size: 12px;
    color: var(--text-muted);
    text-align: center;
    padding: 12px 14px;
    border-top: 1px solid var(--border);
    background: var(--surface2);
}

.chat-notice a { color: var(--accent); font-weight: 700; text-decoration: none; }

.chat-err {
    font-size: 11.5px;
    color: #f87171;
    margin-bottom: 6px;
}

/* WS status indicator */
.ws-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #4b5563;
    transition: background .3s;
    flex-shrink: 0;
}
.ws-dot.connected    { background: var(--green-l); }
.ws-dot.disconnected { background: #f87171; }

/* ─────────────────────────────────────────────────────────────────────────
   ERROR / EMPTY PAGE STATE
───────────────────────────────────────────────────────────────────────── */
.page-error {
    min-height: 60vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 40px 24px;
    text-align: center;
}

.page-error i { font-size: 56px; color: #1f3828; }
.page-error h2 { font-size: 18px; font-weight: 800; color: #3a5a44; }
.page-error p  { font-size: 13px; color: var(--text-muted); max-width: 360px; }

.btn-home {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: var(--green);
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    padding: 9px 20px;
    border-radius: 10px;
    transition: background .15s;
    font-family: 'Poppins', sans-serif;
}

.btn-home:hover { background: #14592d; }

/* ─────────────────────────────────────────────────────────────────────────
   RESPONSIVE — stack below 1100px (already handled by grid)
   Extra adjustments for mobile
───────────────────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .hero-banner  { padding: 14px 16px; }
    .hero-title   { font-size: 15px; }
    .session-panel, .lots-panel { padding: 14px 14px 16px; }
    .topbar { padding: 0 12px; }
    .tb-brand span { display: none; }
    .tb-proc-title { font-size: 11px; }
    .tb-link { font-size: 11px; padding: 4px 9px; }
    .hero-metric-val { font-size: 13px; }
}
</style>
</head>
<body>

<!-- ══ TOPBAR ══════════════════════════════════════════════════════════════ -->
<div class="topbar">
    <a class="tb-brand" href="index.php">
        <img src="images/logo.png" alt="YesParency">
        <span>YesParency</span>
    </a>

    <div class="tb-center">
        <?php if ($procurement): ?>
            <div class="tb-proc-title"><?= htmlspecialchars($procurement['title']) ?></div>
        <?php endif; ?>
    </div>

    <div class="tb-right">
        <!-- Live / Scheduled / Ended badge -->
        <?php if ($is_live): ?>
            <span class="status-badge live"><span class="live-dot"></span> LIVE</span>
        <?php elseif ($is_scheduled): ?>
            <span class="status-badge sched"><i class="bi bi-clock"></i> Scheduled</span>
        <?php elseif ($is_ended): ?>
            <span class="status-badge ended"><i class="bi bi-check2-all"></i> Ended</span>
        <?php else: ?>
            <span class="status-badge offline"><i class="bi bi-wifi-off"></i> Offline</span>
        <?php endif; ?>

        <!-- WS dot -->
        <span class="ws-dot" id="wsDot" title="WebSocket connection"></span>

        <?php if ($is_admin): ?>
            <a href="admin/bid_session.php?session=<?= $session_id ?>" class="tb-link">
                <i class="bi bi-broadcast"></i> Manage
            </a>
        <?php elseif ($viewer_id): ?>
            <a href="logout.php" class="tb-link">Logout</a>
        <?php else: ?>
            <a href="login.php" class="tb-link">Login</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($page_error): ?>
<!-- ══ ERROR STATE ══════════════════════════════════════════════════════════ -->
<div class="page-error">
    <i class="bi bi-broadcast"></i>
    <h2><?= htmlspecialchars($page_error) ?></h2>
    <p>The bid opening session you are looking for does not exist or has not started yet.</p>
    <a href="index.php" class="btn-home"><i class="bi bi-house-fill"></i> Go Home</a>
</div>

<?php else: ?>

<!-- ══ HERO BANNER ══════════════════════════════════════════════════════════ -->
<div class="hero-banner">
    <div style="display:flex;flex-wrap:wrap;gap:6px;flex:1 1 100%">
        <span class="hero-badge ref"><i class="bi bi-hash"></i> <?= htmlspecialchars($procurement['slsu_ref_no']) ?></span>
        <span class="hero-badge mode"><i class="bi bi-tag-fill"></i> <?= htmlspecialchars($procurement['procurement_mode']) ?></span>
        <span class="hero-badge type"><i class="bi bi-layers"></i> <?= $procurement['procurement_type'] === 'infrastructure' ? 'Infrastructure' : 'Goods &amp; Services' ?></span>
    </div>
    <h1 class="hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>
    <div class="hero-meta">
        <div class="hero-metric">
            <span class="hero-metric-label">ABC</span>
            <span class="hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></span>
        </div>
        <div class="hero-metric">
            <span class="hero-metric-label">Opening Date</span>
            <span class="hero-metric-val"><?= $procurement['opening_date'] ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : '—' ?></span>
        </div>
        <div class="hero-metric">
            <span class="hero-metric-label">Lots</span>
            <span class="hero-metric-val"><?= count($lots) ?></span>
        </div>
        <div class="hero-metric">
            <span class="hero-metric-label">Status</span>
            <span class="hero-metric-val" id="sessionStatusVal"><?= htmlspecialchars(sessionStatusLabel($session['status'])) ?></span>
        </div>
    </div>
</div>

<!-- ══ MAIN GRID ════════════════════════════════════════════════════════════ -->
<div class="page-grid">

    <!-- ── LEFT COLUMN ── -->
    <div class="main-col">

        <!-- Video -->
        <div class="video-wrap">
            <?php if ($is_live): ?>
                <iframe src="<?= htmlspecialchars($stream_url) ?>" allow="autoplay; fullscreen" allowfullscreen></iframe>
            <?php else: ?>
                <div class="video-offline" id="videoOfflineState">
                    <i class="bi bi-broadcast"></i>
                    <h3>
                        <?php if ($is_scheduled): ?>Stream is scheduled<?php
                        elseif ($is_ended): ?>Session has ended<?php
                        else: ?>Stream is offline<?php endif; ?>
                    </h3>
                    <p>
                        <?php if ($is_scheduled && $procurement['opening_date']): ?>
                            Opening on <?= date('F j, Y · g:i A', strtotime($procurement['opening_date'])) ?>
                        <?php elseif ($is_ended): ?>
                            This bid-opening session has concluded.
                        <?php else: ?>
                            No active stream at the moment. Check back when the session is live.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Session status -->
        <div class="session-panel">
            <div class="sp-heading"><i class="bi bi-activity"></i> Session Status</div>
            <?php
            $stageIcon  = match($session['status']) {
                'eligibility' => 'sp-stage-icon elig',
                'financial'   => 'sp-stage-icon fin',
                'awarding'    => 'sp-stage-icon award',
                'scheduled'   => 'sp-stage-icon sched',
                default       => 'sp-stage-icon ended',
            };
            $stageI = match($session['status']) {
                'eligibility' => 'bi-shield-check',
                'financial'   => 'bi-cash-stack',
                'awarding'    => 'bi-trophy-fill',
                'scheduled'   => 'bi-clock-fill',
                default       => 'bi-check2-all',
            };
            ?>
            <div class="sp-stage">
                <div class="<?= $stageIcon ?>" id="stageIcon"><i class="bi <?= $stageI ?>"></i></div>
                <div class="sp-stage-text">
                    <div class="sp-stage-label" id="stageLabel"><?= htmlspecialchars(sessionStatusLabel($session['status'])) ?></div>
                    <div class="sp-stage-sub" id="stageSub">
                        <?php if ($current_lot): ?>
                            Lot <?= htmlspecialchars($current_lot['lot_number']) ?> — <?= htmlspecialchars($current_lot['lot_title']) ?>
                        <?php elseif ($is_ended): ?>
                            Session concluded
                        <?php elseif ($is_scheduled): ?>
                            Waiting to begin
                        <?php else: ?>
                            In progress
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lots -->
        <?php if (!empty($lots)): ?>
        <div class="lots-panel">
            <div class="sp-heading"><i class="bi bi-layers"></i> Lots</div>
            <div class="lots-grid" id="lotsGrid">
                <?php foreach ($lots as $lot):
                    $isActiveLot = $session['current_lot_id'] && (int)$lot['id'] === (int)$session['current_lot_id'];
                    $isDone      = $lot['lot_status'] === 'awarded' || $lot['lot_status'] === 'failed';
                    $isFailed    = $lot['lot_status'] === 'failed';

                    if ($isActiveLot)       { $rowCls = 'lot-row lot-active'; $numCls = 'lot-num active'; $pillCls = 'lot-state-pill active'; $pillTxt = 'Live'; }
                    elseif ($isFailed)      { $rowCls = 'lot-row lot-failed'; $numCls = 'lot-num failed'; $pillCls = 'lot-state-pill failed'; $pillTxt = 'No Award'; }
                    elseif ($isDone)        { $rowCls = 'lot-row lot-done';   $numCls = 'lot-num done';   $pillCls = 'lot-state-pill done';   $pillTxt = 'Done'; }
                    else                    { $rowCls = 'lot-row';             $numCls = 'lot-num pending';$pillCls = 'lot-state-pill pending'; $pillTxt = 'Upcoming'; }
                ?>
                <div class="<?= $rowCls ?>" id="lot-row-<?= $lot['id'] ?>">
                    <div class="<?= $numCls ?>"><?= $lot['lot_number'] ?></div>
                    <div style="flex:1;min-width:0">
                        <div class="lot-name">Lot <?= htmlspecialchars($lot['lot_number']) ?><?= $lot['lot_title'] ? ' · ' . htmlspecialchars($lot['lot_title']) : '' ?></div>
                        <div class="lot-sub">₱<?= $lot['abc'] ? number_format((float)$lot['abc'], 2) : '—' ?> · <?= (int)$lot['bid_count'] ?> bid<?= $lot['bid_count'] != 1 ? 's' : '' ?></div>
                    </div>
                    <span class="<?= $pillCls ?>"><?= $pillTxt ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Procurement description -->
        <?php if (!empty($procurement['description'])): ?>
        <div style="padding:16px 22px 24px; border-top: 1px solid var(--border);">
            <div class="sp-heading"><i class="bi bi-file-text"></i> Procurement Description</div>
            <p style="font-size:12.5px;color:var(--text-muted);line-height:1.65;margin-top:8px">
                <?= nl2br(htmlspecialchars($procurement['description'])) ?>
            </p>
        </div>
        <?php endif; ?>

    </div><!-- /main-col -->

    <!-- ── RIGHT COLUMN: CHAT ── -->
    <div class="chat-col">
        <div class="chat-head">
            <div class="chat-head-title"><i class="bi bi-chat-dots-fill"></i> Live Chat</div>
            <span class="chat-count" id="chatCount"><?= count($comments) ?></span>
        </div>

        <div class="chat-messages" id="chatMessages">
            <?php if (empty($comments)): ?>
                <div class="chat-empty" id="chatEmpty">
                    <i class="bi bi-chat-square-dots"></i>
                    <span>No messages yet.</span>
                    <?php if ($can_chat && $is_live): ?>
                        <span style="font-size:11px">Be the first to say something!</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($comments as $cm):
                    $ini = strtoupper(substr($cm['firstname'],0,1) . substr($cm['lastname'],0,1));
                    $av  = !empty($cm['profile_picture_url']) ? htmlspecialchars($cm['profile_picture_url']) : '';
                ?>
                <div class="chat-msg">
                    <div class="chat-av">
                        <?php if ($av): ?><img src="<?= $av ?>" alt=""><?php else: ?><?= htmlspecialchars($ini) ?><?php endif; ?>
                    </div>
                    <div class="chat-bubble">
                        <div class="bubble-name">@<?= htmlspecialchars($cm['username']) ?></div>
                        <div class="bubble-text"><?= htmlspecialchars($cm['comment']) ?></div>
                        <div class="bubble-time"><?= timeAgo($cm['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($can_chat && $is_live): ?>
        <div class="chat-input">
            <div class="chat-err" id="chatErr" style="display:none"></div>
            <div class="chat-form">
                <textarea class="chat-ta" id="chatInput" placeholder="Type a message…" maxlength="500" rows="1"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChat();}"></textarea>
                <button class="chat-send" id="chatSendBtn" onclick="sendChat()" title="Send">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
        <?php elseif ($viewer_role === 'bidder'): ?>
            <div class="chat-notice">Chat is only open during a live session.</div>
        <?php elseif (!$viewer_id): ?>
            <div class="chat-notice"><a href="login.php">Log in</a> as a registered bidder to participate in chat.</div>
        <?php elseif ($is_admin): ?>
            <div class="chat-notice">Admins view chat — moderate via the <a href="admin/bid_session.php?session=<?= $session_id ?>">admin panel</a>.</div>
        <?php else: ?>
            <div class="chat-notice">Only registered bidders can participate in chat.</div>
        <?php endif; ?>
    </div><!-- /chat-col -->

</div><!-- /page-grid -->
<?php endif; // end $page_error check ?>

<script>
// ═══════════════════════════════════════════════════════════════════════════
// LIVE.PHP — WebSocket + UI
// Uses the same Pusher channel as admin/bid_session.php:
//   channel: 'session-{SESSION_ID}'
// Events received: phase_changed, lot_changed, session_started,
//   session_ended, bac_signed, files_opened, eligibility_updated,
//   lot_awarded, lot_failed, chat_message
// ═══════════════════════════════════════════════════════════════════════════

const SESSION_ID     = <?= $session_id ?>;
const PROCUREMENT_ID = <?= $procurement_id ?>;
const IS_LIVE        = <?= $is_live ? 'true' : 'false' ?>;
const CAN_CHAT       = <?= $can_chat ? 'true' : 'false' ?>;

// ── Helpers ────────────────────────────────────────────────────────────────

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

// ── Auto-resize textarea ───────────────────────────────────────────────────
const chatTa = document.getElementById('chatInput');
if (chatTa) {
    chatTa.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 80) + 'px';
    });
}

// Scroll chat to bottom
const chatEl = document.getElementById('chatMessages');
function scrollChatBottom() {
    if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;
}
scrollChatBottom();

// ── Append a chat message to the chat pane ────────────────────────────────
function appendChatMessage(c) {
    if (!chatEl) return;

    const empty = document.getElementById('chatEmpty');
    if (empty) empty.remove();

    const initials  = ((c.firstname||'').charAt(0) + (c.lastname||'').charAt(0)).toUpperCase();
    const avatarHtml = c.profile_picture_url
        ? `<img src="${escHtml(c.profile_picture_url)}" alt="">`
        : escHtml(initials);

    const msg = document.createElement('div');
    msg.className = 'chat-msg';
    if (c.id) msg.setAttribute('data-msg-id', c.id);
    msg.innerHTML = `
        <div class="chat-av">${avatarHtml}</div>
        <div class="chat-bubble">
            <div class="bubble-name">@${escHtml(c.username)}</div>
            <div class="bubble-text">${escHtml(c.comment)}</div>
            <div class="bubble-time">${escHtml(c.time_ago || 'Just now')}</div>
        </div>
    `;
    chatEl.appendChild(msg);

    // Update count badge
    const countEl = document.getElementById('chatCount');
    if (countEl) countEl.textContent = chatEl.querySelectorAll('.chat-msg').length;

    // Auto-scroll only if user is near bottom (within 100px)
    const nearBottom = (chatEl.scrollHeight - chatEl.scrollTop - chatEl.clientHeight) < 120;
    if (nearBottom) scrollChatBottom();
}

// ── Send chat message ─────────────────────────────────────────────────────
function sendChat() {
    if (!CAN_CHAT || !SESSION_ID) return;

    const input = document.getElementById('chatInput');
    const errEl = document.getElementById('chatErr');
    const btn   = document.getElementById('chatSendBtn');
    if (!input) return;

    const msg = input.value.trim();
    if (!msg) return;

    btn.disabled = true;
    if (errEl) errEl.style.display = 'none';

    const body = new URLSearchParams({
        action:     'send',
        session_id: SESSION_ID,
        comment:    msg,
    });

    fetch('live_chat_api.php', { method: 'POST', body })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.error) {
            if (errEl) { errEl.textContent = d.error; errEl.style.display = 'block'; }
            return;
        }
        input.value = '';
        input.style.height = '';
        // Pusher will deliver the message to all viewers including sender via chat_message event.
        // We still append it locally for instant feedback in case of slight Pusher delay.
        if (d.comment) appendChatMessage(d.comment);
    })
    .catch(() => {
        btn.disabled = false;
        if (errEl) { errEl.textContent = 'Network error. Please try again.'; errEl.style.display = 'block'; }
    });
}

// ── Session status label helper ────────────────────────────────────────────
function statusLabel(s) {
    const map = {
        scheduled:   'Scheduled',
        started:     'Starting',
        eligibility: 'Eligibility Review',
        financial:   'Financial Review',
        awarding:    'Awarding',
        ended:       'Session Ended',
    };
    return map[s] || s.charAt(0).toUpperCase() + s.slice(1);
}

// ── Update stage panel from new status ────────────────────────────────────
function updateStageUI(status) {
    const lbl  = document.getElementById('stageLabel');
    const sub  = document.getElementById('stageSub');
    const icon = document.getElementById('stageIcon');
    const sval = document.getElementById('sessionStatusVal');

    if (lbl)  lbl.textContent  = statusLabel(status);
    if (sval) sval.textContent = statusLabel(status);

    if (icon) {
        const classMap = {
            eligibility: 'sp-stage-icon elig',
            financial:   'sp-stage-icon fin',
            awarding:    'sp-stage-icon award',
            scheduled:   'sp-stage-icon sched',
        };
        icon.className = classMap[status] || 'sp-stage-icon ended';

        const iMap = {
            eligibility: 'bi-shield-check',
            financial:   'bi-cash-stack',
            awarding:    'bi-trophy-fill',
            scheduled:   'bi-clock-fill',
        };
        icon.innerHTML = `<i class="bi ${iMap[status] || 'bi-check2-all'}"></i>`;
    }

    // Update topbar badge
    const badge = document.querySelector('.status-badge');
    if (badge) {
        const live = ['eligibility','financial','awarding','started'].includes(status);
        const ended = status === 'ended';
        badge.className = 'status-badge ' + (live ? 'live' : ended ? 'ended' : 'sched');
        badge.innerHTML = live ? '<span class="live-dot"></span> LIVE'
                        : ended ? '<i class="bi bi-check2-all"></i> Ended'
                        : '<i class="bi bi-clock"></i> Scheduled';
    }
}

// ── Refresh lot rows from DB (after lot_changed / lot_awarded / lot_failed) ─
function refreshLots() {
    fetch(`live_chat_api.php?action=lots&procurement_id=${PROCUREMENT_ID}&session_id=${SESSION_ID}`)
    .then(r => r.json())
    .then(d => {
        if (!d.lots) return;
        const grid = document.getElementById('lotsGrid');
        if (!grid) return;

        d.lots.forEach(lot => {
            const row = document.getElementById('lot-row-' + lot.id);
            if (!row) return;

            const isActive = lot.is_current;
            const isFailed = lot.status === 'failed';
            const isDone   = lot.status === 'awarded' || lot.status === 'failed';

            // Row class
            row.className = isActive ? 'lot-row lot-active'
                          : isFailed ? 'lot-row lot-failed'
                          : isDone   ? 'lot-row lot-done'
                          : 'lot-row';

            // Number badge
            const numEl = row.querySelector('.lot-num');
            if (numEl) numEl.className = 'lot-num ' + (isActive ? 'active' : isFailed ? 'failed' : isDone ? 'done' : 'pending');

            // Pill
            const pillEl = row.querySelector('[class*="lot-state-pill"]');
            if (pillEl) {
                pillEl.className = 'lot-state-pill ' + (isActive ? 'active' : isFailed ? 'failed' : isDone ? 'done' : 'pending');
                pillEl.textContent = isActive ? 'Live' : isFailed ? 'No Award' : isDone ? 'Done' : 'Upcoming';
            }
        });

        // Update sub-text for active lot
        const activeLot = d.lots.find(l => l.is_current);
        const subEl = document.getElementById('stageSub');
        if (subEl && activeLot) {
            subEl.textContent = `Lot ${activeLot.lot_number} — ${activeLot.lot_title || ''}`;
        }
    })
    .catch(() => {});
}

// ── Pusher WebSocket ───────────────────────────────────────────────────────
<?php if ($session_id > 0 && !empty($_ENV['PUSHER_APP_KEY'])): ?>
(function() {
    const wsDot = document.getElementById('wsDot');

    const pusher = new Pusher('<?= htmlspecialchars($_ENV['PUSHER_APP_KEY']) ?>', {
        cluster:  '<?= htmlspecialchars($_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3') ?>',
        forceTLS: true,
    });

    pusher.connection.bind('connected', function() {
        if (wsDot) { wsDot.className = 'ws-dot connected'; wsDot.title = 'Connected'; }
    });
    pusher.connection.bind('disconnected', function() {
        if (wsDot) { wsDot.className = 'ws-dot disconnected'; wsDot.title = 'Disconnected'; }
    });

    const channel = pusher.subscribe('session-' + SESSION_ID);

    // ── Session status / lot changes ──────────────────────────────────────
    ['session_started','phase_changed','session_ended'].forEach(ev => {
        channel.bind(ev, function(data) {
            if (data && data.session_id && data.session_id != SESSION_ID) return;
            // Re-fetch session status from DB and update UI
            fetch(`live_chat_api.php?action=session_status&session_id=${SESSION_ID}`)
            .then(r => r.json())
            .then(d => {
                if (d.status) updateStageUI(d.status);
            })
            .catch(() => {});
            refreshLots();
        });
    });

    channel.bind('lot_changed', function() { refreshLots(); });
    channel.bind('lot_awarded', function() { refreshLots(); });
    channel.bind('lot_failed',  function() { refreshLots(); });

    channel.bind('eligibility_updated', function() { refreshLots(); });

    // ── Chat messages ──────────────────────────────────────────────────────
    channel.bind('chat_message', function(data) {
        if (!data || !data.comment) return;
        // Deduplicate: skip if we already rendered this id (happens when sender
        // also gets the push and already appended locally in sendChat())
        if (data.id && document.querySelector(`[data-msg-id="${data.id}"]`)) return;
        appendChatMessage(data);
    });

})();
<?php else: ?>
// Pusher not configured or no session — fallback polling below
<?php endif; ?>

// ── Fallback: 30-second heartbeat for session status + chat ───────────────
// Catches any missed events (reconnects, browser sleep, Pusher outage).
<?php if ($session_id > 0 && $is_live): ?>
let _lastCommentId = <?= $last_comment_id ?>;

function heartbeat() {
    // Session status
    fetch(`live_chat_api.php?action=session_status&session_id=${SESSION_ID}`)
    .then(r => r.json())
    .then(d => { if (d.status) updateStageUI(d.status); })
    .catch(() => {});

    // Chat messages (fallback only — Pusher handles this in real time)
    fetch(`live_chat_api.php?action=fetch&session_id=${SESSION_ID}&after=${_lastCommentId}`)
    .then(r => r.json())
    .then(d => {
        (d.comments || []).forEach(c => {
            if (c.id > _lastCommentId) _lastCommentId = c.id;
            if (!document.querySelector(`[data-msg-id="${c.id}"]`)) appendChatMessage(c);
        });
    })
    .catch(() => {});

    // Lots
    refreshLots();
}

setInterval(heartbeat, 30000);
<?php endif; ?>
</script>

</body>
</html>
