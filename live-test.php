<?php
/**
 * live-test.php — Standalone harness for testing the MediaMTX live stream +
 * Pusher chat pipeline, with no procurement/session/bidder-login required.
 *
 * Not linked from anywhere in the app on purpose. Remove this file and
 * live-test-chat-api.php once testing is done.
 *
 * URL: /live-test.php?path=live
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/mediamtx.php';
require_once __DIR__ . '/config/pusher.php'; // loads .env + makes $_ENV['PUSHER_APP_KEY'] available

$stream_path = trim($_GET['path'] ?? '');
if ($stream_path === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $stream_path)) {
    $stream_path = mediamtx_default_path();
}
$stream_url = mediamtx_url($stream_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Stream Test | YesParency</title>
<link rel="icon" type="image/png" href="images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
<style>
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

.topbar {
    position: sticky; top: 0; z-index: 200;
    background: rgba(11,24,18,0.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    height: 58px;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 20px; gap: 12px;
}
.tb-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
.tb-brand img { width: 30px; height: 30px; object-fit: contain; }
.tb-brand span { font-size: 15px; font-weight: 800; color: #fff; }
.tb-tag {
    font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px;
    color: var(--accent); background: rgba(255,193,7,.12); border: 1px solid rgba(255,193,7,.25);
    padding: 3px 10px; border-radius: 20px;
}

.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 800; padding: 4px 11px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: .5px; white-space: nowrap;
}
.status-badge.online  { background: rgba(74,222,128,.15); color: #4ade80; border: 1px solid rgba(74,222,128,.3); }
.status-badge.offline { background: rgba(220,38,38,.2);   color: #f87171; border: 1px solid rgba(220,38,38,.3); }
.status-badge.checking{ background: rgba(217,119,6,.15);  color: #fbbf24; border: 1px solid rgba(217,119,6,.3); }
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.status-badge.online .live-dot { animation: pulseBadge 2s infinite; }
@keyframes pulseBadge { 0%,100%{opacity:1} 50%{opacity:.55} }

.config-bar {
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
    padding: 10px 20px;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    font-size: 12px; color: var(--text-muted);
}
.config-bar code {
    background: rgba(255,255,255,.06);
    border: 1px solid var(--border2);
    border-radius: 6px;
    padding: 2px 8px;
    color: #cfe8d4;
    font-family: 'Space Grotesk', monospace;
    word-break: break-all;
}
.config-bar form { display: flex; gap: 6px; align-items: center; margin-left: auto; }
.config-bar input {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px; padding: 6px 10px; color: var(--text); font-size: 12px;
    font-family: 'Poppins', sans-serif; width: 140px;
}
.config-bar button {
    background: var(--green); color: #fff; border: none; border-radius: 8px;
    padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer;
}
.config-bar button:hover { background: #14592d; }

.page-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 0;
    min-height: calc(100vh - 58px - 42px);
}
@media (max-width: 1100px) { .page-grid { grid-template-columns: 1fr; } }

.main-col { display: flex; flex-direction: column; border-right: 1px solid var(--border); min-width: 0; }
@media (max-width: 1100px) { .main-col { border-right: none; border-bottom: 1px solid var(--border); } }

.video-wrap { background: #000; position: relative; aspect-ratio: 16 / 9; width: 100%; flex-shrink: 0; }
.video-wrap iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: none; }
.video-offline {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 12px;
    background: #0b1612; color: var(--text-muted);
}
.video-offline i  { font-size: 52px; color: #1f3828; }
.video-offline h3 { font-size: 15px; font-weight: 700; color: #3a5a44; }
.video-offline p  { font-size: 12.5px; text-align: center; max-width: 320px; }

.debug-panel {
    padding: 16px 22px; border-bottom: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 8px;
}
.debug-row { display: flex; gap: 10px; align-items: baseline; font-size: 12.5px; flex-wrap: wrap; }
.debug-row .lbl { color: var(--text-muted); font-weight: 700; min-width: 110px; }
.debug-row .val { color: #cfe8d4; font-family: 'Space Grotesk', monospace; word-break: break-all; }
.btn-mini {
    background: rgba(255,255,255,.06); border: 1px solid var(--border2); color: var(--text);
    border-radius: 8px; padding: 5px 12px; font-size: 11.5px; font-weight: 700; cursor: pointer;
}
.btn-mini:hover { background: rgba(255,255,255,.1); }

.chat-col { display: flex; flex-direction: column; height: calc(100vh - 58px - 42px); position: sticky; top: 58px; min-width: 0; }
@media (max-width: 1100px) { .chat-col { position: static; height: 520px; } }

.chat-head {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: var(--surface2);
}
.chat-head-title { font-size: 13px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 7px; }
.chat-head-title i { color: var(--accent); }
.chat-count { font-size: 10.5px; font-weight: 700; color: var(--text-muted); background: rgba(255,255,255,.07); padding: 2px 8px; border-radius: 10px; }

.chat-messages { flex: 1; overflow-y: auto; padding: 14px 14px 8px; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-track { background: transparent; }
.chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 2px; }

.chat-msg { display: flex; align-items: flex-start; gap: 9px; }
.chat-av {
    width: 30px; height: 30px; border-radius: 8px; background: var(--green); color: #fff;
    font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.chat-bubble { background: rgba(255,255,255,.04); border: 1px solid var(--border2); border-radius: 0 10px 10px 10px; padding: 8px 11px; flex: 1; min-width: 0; }
.bubble-name { font-size: 11px; font-weight: 800; color: var(--accent); margin-bottom: 3px; }
.bubble-text { font-size: 12.5px; color: #cfe8d4; line-height: 1.45; word-break: break-word; }
.bubble-time { font-size: 10px; color: #4a6052; margin-top: 4px; }

.chat-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #3a5a44; font-size: 12.5px; text-align: center; padding: 24px; }
.chat-empty i { font-size: 36px; }

.chat-input { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; background: var(--surface2); }
.chat-form { display: flex; gap: 8px; align-items: flex-end; }
.chat-ta {
    flex: 1; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); border-radius: 10px;
    padding: 9px 12px; color: var(--text); font-size: 13px; font-family: 'Poppins', sans-serif;
    resize: none; outline: none; min-height: 40px; max-height: 80px; transition: border-color .15s;
}
.chat-ta:focus { border-color: var(--green); }
.chat-ta::placeholder { color: #3a5a44; }
.chat-send {
    width: 40px; height: 40px; flex-shrink: 0; background: var(--green); color: #fff; border: none; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; transition: background .15s;
}
.chat-send:hover { background: #14592d; }
.chat-send:disabled { opacity: .45; cursor: not-allowed; }

.name-row { padding: 10px 14px; border-top: 1px solid var(--border); background: var(--surface2); display: flex; gap: 8px; align-items: center; }
.name-row input {
    flex: 1; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); border-radius: 8px;
    padding: 7px 10px; color: var(--text); font-size: 12px; font-family: 'Poppins', sans-serif;
}
.name-row label { font-size: 11px; color: var(--text-muted); font-weight: 700; white-space: nowrap; }

.ws-dot { width: 7px; height: 7px; border-radius: 50%; background: #4b5563; transition: background .3s; flex-shrink: 0; }
.ws-dot.connected    { background: var(--green-l); }
.ws-dot.disconnected { background: #f87171; }

@media (max-width: 640px) {
    .topbar { padding: 0 12px; }
    .config-bar { padding: 8px 12px; }
    .debug-panel { padding: 12px 14px; }
}
</style>
</head>
<body>

<div class="topbar">
    <a class="tb-brand" href="index.php">
        <img src="images/logo.png" alt="YesParency">
        <span>YesParency</span>
    </a>
    <span class="tb-tag"><i class="bi bi-tools"></i> Live Stream Test Harness</span>
    <div style="display:flex;align-items:center;gap:10px">
        <span class="status-badge checking" id="healthBadge"><span class="live-dot"></span> Checking…</span>
        <span class="ws-dot" id="wsDot" title="WebSocket connection"></span>
    </div>
</div>

<div class="config-bar">
    <span>Path: <code id="pathDisplay"><?= htmlspecialchars($stream_path) ?></code></span>
    <span>URL: <code id="urlDisplay"><?= htmlspecialchars($stream_url) ?></code></span>
    <form method="get" action="live-test.php">
        <input type="text" name="path" placeholder="stream path" value="<?= htmlspecialchars($stream_path) ?>">
        <button type="submit">Switch</button>
    </form>
</div>

<div class="page-grid">

    <div class="main-col">
        <div class="video-wrap">
            <div class="video-offline" id="videoOfflineState">
                <i class="bi bi-broadcast"></i>
                <h3 id="offlineTitle">Waiting for stream…</h3>
                <p id="offlineSub">Polling <code><?= htmlspecialchars($stream_path) ?>/index.m3u8</code> every 5 seconds. Start publishing to this path and the player below will load automatically.</p>
            </div>
            <iframe id="liveStreamIframe" allow="autoplay; fullscreen" allowfullscreen style="display:none;"></iframe>
        </div>

        <div class="debug-panel">
            <div class="debug-row"><span class="lbl">Stream path</span><span class="val" id="dbgPath"><?= htmlspecialchars($stream_path) ?></span></div>
            <div class="debug-row"><span class="lbl">Computed URL</span><span class="val" id="dbgUrl"><?= htmlspecialchars($stream_url) ?></span></div>
            <div class="debug-row"><span class="lbl">Health check</span><span class="val" id="dbgHealth">—</span>
                <button class="btn-mini" onclick="checkStreamNow()"><i class="bi bi-arrow-repeat"></i> Recheck now</button>
            </div>
            <div class="debug-row"><span class="lbl">Last checked</span><span class="val" id="dbgTime">—</span></div>
        </div>

        <div style="padding:16px 22px;color:var(--text-muted);font-size:12.5px;line-height:1.6">
            This page is a standalone test harness — it is not linked from the app and does not require login.
            It reuses the same <code>mediamtx_url()</code> / <code>stream_health.php</code> logic as <code>live.php</code>,
            and posts chat messages to a throwaway table (<code>live_test_messages</code>) broadcast on the
            <code>live-test</code> Pusher channel. Safe to delete (<code>live-test.php</code> + <code>live-test-chat-api.php</code>)
            once you're done testing.
        </div>
    </div>

    <div class="chat-col">
        <div class="chat-head">
            <div class="chat-head-title"><i class="bi bi-chat-dots-fill"></i> Test Chat</div>
            <span class="chat-count" id="chatCount">0</span>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="chat-empty" id="chatEmpty">
                <i class="bi bi-chat-square-dots"></i>
                <span>No messages yet.</span>
                <span style="font-size:11px">Say something to test the pipeline!</span>
            </div>
        </div>

        <div class="chat-input">
            <div class="chat-form">
                <textarea class="chat-ta" id="chatInput" placeholder="Type a test message…" maxlength="500" rows="1"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChat();}"></textarea>
                <button class="chat-send" id="chatSendBtn" onclick="sendChat()" title="Send">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
        <div class="name-row">
            <label for="chatName">Name</label>
            <input type="text" id="chatName" placeholder="Tester" maxlength="60">
            <button class="btn-mini" onclick="clearChat()" title="Wipe test messages"><i class="bi bi-trash"></i></button>
        </div>
    </div>

</div>

<script>
const STREAM_PATH = <?= json_encode($stream_path) ?>;
const STREAM_URL  = <?= json_encode($stream_url) ?>;

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

// ── Remember tester name across reloads ────────────────────────────────────
const nameEl = document.getElementById('chatName');
nameEl.value = localStorage.getItem('liveTestName') || '';
nameEl.addEventListener('input', () => localStorage.setItem('liveTestName', nameEl.value));

// ── Stream health polling ───────────────────────────────────────────────────
const healthBadge  = document.getElementById('healthBadge');
const dbgHealth     = document.getElementById('dbgHealth');
const dbgTime       = document.getElementById('dbgTime');
const offlineState  = document.getElementById('videoOfflineState');
const offlineTitle  = document.getElementById('offlineTitle');
const offlineSub    = document.getElementById('offlineSub');
const iframeEl      = document.getElementById('liveStreamIframe');
let streamOnline    = false;

function setHealthUI(online) {
    streamOnline = online;
    healthBadge.className = 'status-badge ' + (online ? 'online' : 'offline');
    healthBadge.innerHTML = online
        ? '<span class="live-dot"></span> Online'
        : '<i class="bi bi-wifi-off"></i> Offline';
    dbgHealth.textContent = online ? 'online (200 via GET)' : 'offline (not publishing)';
    dbgTime.textContent = new Date().toLocaleTimeString();

    if (online) {
        if (iframeEl.style.display === 'none' || !iframeEl.src) {
            iframeEl.src = STREAM_URL;
            iframeEl.style.display = '';
        }
        offlineState.style.display = 'none';
    } else {
        iframeEl.style.display = 'none';
        iframeEl.removeAttribute('src');
        offlineState.style.display = '';
        offlineTitle.textContent = 'Stream offline';
        offlineSub.innerHTML = `Polling <code>${escHtml(STREAM_PATH)}/index.m3u8</code> every 5 seconds. Start publishing to this path and the player will load automatically.`;
    }
}

function checkStreamNow() {
    dbgHealth.textContent = 'checking…';
    fetch('stream_health.php?path=' + encodeURIComponent(STREAM_PATH))
        .then(r => r.json())
        .then(data => setHealthUI(!!data.online))
        .catch(() => setHealthUI(false));
}

checkStreamNow();
setInterval(checkStreamNow, 5000);

// ── Chat ─────────────────────────────────────────────────────────────────
const chatEl = document.getElementById('chatMessages');
function scrollChatBottom() { chatEl.scrollTop = chatEl.scrollHeight; }

const chatTa = document.getElementById('chatInput');
chatTa.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 80) + 'px';
});

function appendChatMessage(m) {
    const empty = document.getElementById('chatEmpty');
    if (empty) empty.remove();

    const initials = (m.name || 'A').trim().charAt(0).toUpperCase();

    const msg = document.createElement('div');
    msg.className = 'chat-msg';
    if (m.id) msg.setAttribute('data-msg-id', m.id);
    msg.innerHTML = `
        <div class="chat-av">${escHtml(initials)}</div>
        <div class="chat-bubble">
            <div class="bubble-name">${escHtml(m.name)}</div>
            <div class="bubble-text">${escHtml(m.message)}</div>
            <div class="bubble-time">${escHtml(m.time_ago || 'Just now')}</div>
        </div>
    `;
    chatEl.appendChild(msg);

    const countEl = document.getElementById('chatCount');
    countEl.textContent = chatEl.querySelectorAll('.chat-msg').length;

    const nearBottom = (chatEl.scrollHeight - chatEl.scrollTop - chatEl.clientHeight) < 120;
    if (nearBottom) scrollChatBottom();
}

let _lastId = 0;
function sendChat() {
    const input = document.getElementById('chatInput');
    const btn   = document.getElementById('chatSendBtn');
    const msg   = input.value.trim();
    if (!msg) return;

    btn.disabled = true;
    const body = new URLSearchParams({
        action:  'send',
        name:    nameEl.value.trim() || 'Anonymous',
        message: msg,
    });

    fetch('live-test-chat-api.php', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.error) return;
            input.value = '';
            input.style.height = '';
            if (d.message) {
                if (d.message.id > _lastId) _lastId = d.message.id;
                appendChatMessage(d.message);
            }
        })
        .catch(() => { btn.disabled = false; });
}

function clearChat() {
    if (!confirm('Clear all test messages?')) return;
    fetch('live-test-chat-api.php', { method: 'POST', body: new URLSearchParams({ action: 'clear' }) })
        .then(() => location.reload());
}

function pollChat() {
    fetch('live-test-chat-api.php?action=fetch&after=' + _lastId)
        .then(r => r.json())
        .then(d => {
            (d.messages || []).forEach(m => {
                if (m.id > _lastId) _lastId = m.id;
                if (!document.querySelector(`[data-msg-id="${m.id}"]`)) appendChatMessage(m);
            });
        })
        .catch(() => {});
}

pollChat();
setInterval(pollChat, 5000);

// ── Pusher realtime (falls back to polling above if unavailable) ──────────
<?php if (!empty($_ENV['PUSHER_APP_KEY'])): ?>
(function() {
    const wsDot = document.getElementById('wsDot');
    const pusher = new Pusher('<?= htmlspecialchars($_ENV['PUSHER_APP_KEY']) ?>', {
        cluster:  '<?= htmlspecialchars($_ENV['PUSHER_APP_CLUSTER'] ?? 'ap3') ?>',
        forceTLS: true,
    });
    pusher.connection.bind('connected', () => { wsDot.className = 'ws-dot connected'; wsDot.title = 'Connected'; });
    pusher.connection.bind('disconnected', () => { wsDot.className = 'ws-dot disconnected'; wsDot.title = 'Disconnected'; });

    const channel = pusher.subscribe('live-test');
    channel.bind('chat_message', function(data) {
        if (!data || !data.message) return;
        if (data.id && document.querySelector(`[data-msg-id="${data.id}"]`)) return;
        if (data.id > _lastId) _lastId = data.id;
        appendChatMessage(data);
    });
})();
<?php endif; ?>
</script>

</body>
</html>
