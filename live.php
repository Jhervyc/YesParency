<?php
/**
 * live.php — Public Live Bid Opening Page
 * Anyone can watch. Only authenticated bidders can comment.
 * Stream: OBS → RTMP → MediaMTX → WebRTC → iframe embed
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/mediamtx.php';
session_start();

// ── Auth state (no redirect — page is public) ─────────────────────────────
$viewer_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$viewer_role = $_SESSION['role'] ?? 'guest';
$can_chat    = ($viewer_role === 'bidder');
$is_admin    = in_array($viewer_role, ['admin', 'superadmin']);

// ── Find the active bid opening session ───────────────────────────────────
$event    = null;
$db_error = null;

$ev_stmt = $conn->prepare("
    SELECT bos.id, bos.stream_path, bos.title, bos.status, bos.started_at,
           p.title AS proc_title, p.philgeps_ref_no, p.abc,
           p.opening_date, p.closing_date, p.status AS proc_status,
           p.procurement_mode, p.description AS proc_description
    FROM bid_opening_sessions bos
    JOIN procurements p ON bos.procurement_id = p.id
    WHERE bos.status IN ('eligibility', 'financial', 'awarding', 'scheduled')
    ORDER BY
        CASE bos.status
            WHEN 'eligibility' THEN 1
            WHEN 'financial'   THEN 2
            WHEN 'awarding'    THEN 3
            WHEN 'scheduled'   THEN 4
        END ASC,
        bos.started_at DESC
    LIMIT 1
");
if ($ev_stmt) {
    $ev_stmt->execute();
    $event = $ev_stmt->get_result()->fetch_assoc();
    $ev_stmt->close();
} else {
    $db_error = $conn->error;
}

// Active means any non-ended, non-scheduled state
$is_live = $event && in_array($event['status'], ['eligibility', 'financial', 'awarding']);

// ?force=1 lets admin test the stream without a DB session (bypasses detection)
$force_stream = isset($_GET['force']) && $_GET['force'] === '1' && $is_admin;

// Stream URL built from env (server config) + DB stream_path (session config)
define('STREAM_URL', mediamtx_url($event['stream_path'] ?? mediamtx_default_path()));

// ── Recent visible comments (latest 50, ordered asc for display) ──────────
$comments = [];
if ($event) {
    $c_stmt = $conn->prepare("
        SELECT lc.id, lc.comment, lc.created_at,
               u.username, u.firstname, u.lastname, u.profile_picture_url
        FROM live_comments lc
        JOIN users u ON lc.user_id = u.user_id
        WHERE lc.bid_session_id = ? AND lc.status = 'visible'
        ORDER BY lc.created_at DESC
        LIMIT 50
    ");
    $c_stmt->bind_param("i", $event['id']);
    $c_stmt->execute();
    $rows = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $c_stmt->close();
    $comments = array_reverse($rows); // oldest first for display
}

// ── Handle comment POST (server-side role enforcement) ────────────────────
$chat_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_comment'])) {
    } elseif (!$viewer_id) {
        $chat_error = 'You must be logged in to comment.';
    } elseif ($viewer_role !== 'bidder') {
        $chat_error = 'Only registered bidders may post comments.';
    } elseif (!$event || !$is_live) {
        $chat_error = 'Comments are only open during a live session.';
    } else {
        $raw = trim($_POST['comment'] ?? '');
        if ($raw === '') {
            $chat_error = 'Comment cannot be empty.';
        } elseif (mb_strlen($raw) > 500) {
            $chat_error = 'Comment must be 500 characters or fewer.';
        } else {
            $clean = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            $ins = $conn->prepare("
                INSERT INTO live_comments (bid_session_id, user_id, comment)
                VALUES (?, ?, ?)
            ");
            $ins->bind_param("iis", $event['id'], $viewer_id, $clean);
            $ins->execute();
            $ins->close();
            // PRG — prevent resubmit on refresh
            header("Location: live.php");
            exit();
        }
    }

function timeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400)return floor($diff / 3600) . 'h ago';
    return date('M j', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Bid Opening | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: #0d1a14;
            color: #e8ede9;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        /* ── Topbar ── */
        .live-topbar {
            background: #06251b;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0 24px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .live-topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .live-topbar-brand img {
            width: 32px;
            height: 32px;
            object-fit: contain;
        }

        .live-topbar-brand span {
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.3px;
        }

        .live-topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .live-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 11px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .5px;
            animation: pulseRed 1.8s infinite;
        }

        .live-pill.offline {
            background: #4b5563;
            animation: none;
        }

        .live-pill.scheduled {
            background: #d97706;
            animation: none;
        }

        @keyframes pulseRed {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.7; }
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fff;
        }

        .topbar-auth-link {
            font-size: 12px;
            font-weight: 700;
            color: #ffc107;
            text-decoration: none;
            padding: 6px 12px;
            border: 1px solid rgba(255,193,7,.35);
            border-radius: 8px;
            transition: all .15s;
        }

        .topbar-auth-link:hover {
            background: #ffc107;
            color: #06251b;
        }

        /* ── Main layout ── */
        .live-wrapper {
            max-width: 1320px;
            margin: 0 auto;
            padding: 24px 20px 40px;
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 22px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .live-wrapper { grid-template-columns: 1fr; }
        }

        /* ── Video column ── */
        .video-col {}

        .video-player-wrap {
            background: #000;
            border-radius: 16px;
            overflow: hidden;
            aspect-ratio: 16 / 9;
            position: relative;
            box-shadow: 0 8px 32px rgba(0,0,0,.5);
            margin-bottom: 16px;
        }

        .video-player-wrap iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        .video-offline-state {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: #6b7280;
            background: #111827;
        }

        .video-offline-state i {
            font-size: 48px;
            color: #374151;
        }

        .video-offline-state p {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
        }

        /* ── Procurement info card ── */
        .proc-info-card {
            background: #112a1e;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            padding: 18px 20px;
        }

        .proc-info-title {
            font-size: 17px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 10px;
            line-height: 1.35;
        }

        .proc-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }

        .proc-meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 600;
            color: #c8d8cc;
        }

        .proc-meta-pill i { color: #ffc107; font-size: 12px; }

        .proc-desc {
            font-size: 12.5px;
            color: #8fa899;
            line-height: 1.55;
            margin: 0;
        }

        /* ── Chat column ── */
        .chat-col {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .chat-card {
            background: #112a1e;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 560px;
        }

        .chat-head {
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .chat-head-title {
            font-size: 13px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .chat-head-title i { color: #ffc107; }

        .chat-count-pill {
            background: rgba(255,255,255,0.08);
            color: #c8d8cc;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            scroll-behavior: smooth;
        }

        .chat-messages::-webkit-scrollbar { width: 4px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

        .chat-msg {
            display: flex;
            align-items: flex-start;
            gap: 9px;
        }

        .chat-avatar {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #1f7a3d;
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .chat-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .chat-bubble {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 0 10px 10px 10px;
            padding: 8px 11px;
            flex: 1;
            min-width: 0;
        }

        .chat-bubble-name {
            font-size: 11px;
            font-weight: 800;
            color: #ffc107;
            margin-bottom: 3px;
        }

        .chat-bubble-text {
            font-size: 12.5px;
            color: #d1e0d5;
            line-height: 1.45;
            word-break: break-word;
        }

        .chat-bubble-time {
            font-size: 10px;
            color: #5a7060;
            margin-top: 4px;
        }

        .chat-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #4a6052;
            font-size: 12.5px;
            gap: 8px;
        }

        .chat-empty i { font-size: 28px; }

        /* ── Chat input ── */
        .chat-input-area {
            padding: 12px 14px;
            border-top: 1px solid rgba(255,255,255,0.07);
            flex-shrink: 0;
        }

        .chat-form {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .chat-textarea {
            flex: 1;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 9px 12px;
            color: #e8ede9;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            resize: none;
            outline: none;
            transition: border-color .15s;
            max-height: 80px;
            min-height: 40px;
        }

        .chat-textarea:focus { border-color: #1f7a3d; }
        .chat-textarea::placeholder { color: #5a7060; }

        .chat-send-btn {
            background: #1f7a3d;
            color: #fff;
            border: none;
            border-radius: 10px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            flex-shrink: 0;
            transition: background .15s;
        }

        .chat-send-btn:hover { background: #14592d; }

        .chat-login-prompt {
            text-align: center;
            font-size: 12px;
            color: #5a7060;
            padding: 12px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }

        .chat-login-prompt a { color: #ffc107; font-weight: 700; text-decoration: none; }

        .chat-error-msg {
            font-size: 11.5px;
            color: #f87171;
            margin-bottom: 6px;
            padding: 0 2px;
        }

        /* ── Viewer info card ── */
        .viewer-card {
            background: #112a1e;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 12px;
            color: #8fa899;
        }

        .viewer-card strong { color: #d1e0d5; }
    </style>
</head>
<body>

<!-- ── Topbar ──────────────────────────────────────────────────────────── -->
<div class="live-topbar">
    <a class="live-topbar-brand" href="index.php">
        <img src="images/logo.png" alt="YesParency">
        <span>YesParency</span>
    </a>
    <div class="live-topbar-right">
        <?php if ($event && $is_live): ?>
            <span class="live-pill"><span class="live-dot"></span> LIVE</span>
        <?php elseif ($event && $event['status'] === 'scheduled'): ?>
            <span class="live-pill scheduled"><i class="bi bi-clock"></i> Scheduled</span>
        <?php else: ?>
            <span class="live-pill offline"><i class="bi bi-wifi-off"></i> Offline</span>
        <?php endif; ?>

        <?php if ($viewer_id): ?>
            <?php if ($is_admin): ?>
                <a href="admin/bid_opening.php" class="topbar-auth-link">
                    <i class="bi bi-broadcast"></i> Manage Session
                </a>
            <?php endif; ?>
            <a href="logout.php" class="topbar-auth-link">Logout</a>
        <?php else: ?>
            <a href="login.php" class="topbar-auth-link">Login to Chat</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Main content ────────────────────────────────────────────────────── -->
<div class="live-wrapper">

    <!-- LEFT: Video + Procurement info -->
    <div class="video-col">

        <div class="video-player-wrap">
            <?php if ($is_live || $force_stream): ?>
                <iframe
                    src="<?= htmlspecialchars(STREAM_URL) ?>"
                    allow="autoplay; fullscreen"
                    allowfullscreen>
                </iframe>
            <?php else: ?>
                <div class="video-offline-state">
                    <i class="bi bi-broadcast"></i>
                    <p>
                        <?php if ($db_error && $is_admin): ?>
                            <span style="color:#f87171; font-size:12px;"><?= htmlspecialchars($db_error) ?></span>
                        <?php elseif ($event && $event['status'] === 'scheduled'): ?>
                            Stream is scheduled — check back soon.
                        <?php else: ?>
                            No active live session at the moment.
                        <?php endif; ?>
                    </p>
                    <?php if ($event && !empty($event['started_at'])): ?>
                        <p style="font-size:12px;">
                            Scheduled for <?= date('F j, Y · g:i A', strtotime($event['started_at'])) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($is_admin): ?>
                        <p style="font-size:11px; color:#fbbf24;">
                            <i class="bi bi-info-circle"></i>
                            To bypass: <a href="live.php?force=1" style="color:#ffc107;">live.php?force=1</a>
                            — or schedule a session via the
                            <a href="admin/bid_opening.php" style="color:#ffc107;">admin panel</a>.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($event): ?>
        <div class="proc-info-card">
            <div class="proc-meta-row">
                <span class="proc-meta-pill">
                    <i class="bi bi-hash"></i>
                    <?= htmlspecialchars($event['philgeps_ref_no']) ?>
                </span>
                <span class="proc-meta-pill">
                    <i class="bi bi-tag-fill"></i>
                    <?= htmlspecialchars($event['procurement_mode'] ?? 'Public Bidding') ?>
                </span>
                <span class="proc-meta-pill">
                    <i class="bi bi-cash-stack"></i>
                    ₱<?= number_format($event['abc'], 2) ?>
                </span>
                <?php if (!empty($event['opening_date'])): ?>
                <span class="proc-meta-pill">
                    <i class="bi bi-calendar-event"></i>
                    <?= date('M j, Y · g:i A', strtotime($event['opening_date'])) ?>
                </span>
                <?php endif; ?>
            </div>
            <h2 class="proc-info-title">
                <?= htmlspecialchars($event['title'] ?? $event['proc_title']) ?>
            </h2>
            <?php if (!empty($event['proc_description'])): ?>
                <p class="proc-desc"><?= nl2br(htmlspecialchars($event['proc_description'])) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT: Chat -->
    <div class="chat-col">

        <div class="chat-card">
            <div class="chat-head">
                <div class="chat-head-title">
                    <i class="bi bi-chat-dots-fill"></i> Live Chat
                </div>
                <span class="chat-count-pill" id="commentCount">
                    <?= count($comments) ?> message<?= count($comments) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <div class="chat-messages" id="chatMessages">
                <?php if (empty($comments)): ?>
                    <div class="chat-empty" id="chatEmpty">
                        <i class="bi bi-chat-square-dots"></i>
                        <span>No messages yet. Be the first!</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $cm):
                        $initials = strtoupper(substr($cm['firstname'],0,1) . substr($cm['lastname'],0,1));
                        $avatar   = !empty($cm['profile_picture_url']) ? htmlspecialchars($cm['profile_picture_url']) : '';
                    ?>
                    <div class="chat-msg">
                        <div class="chat-avatar">
                            <?php if ($avatar): ?>
                                <img src="<?= $avatar ?>" alt="<?= htmlspecialchars($initials) ?>">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-bubble">
                            <div class="chat-bubble-name">@<?= htmlspecialchars($cm['username']) ?></div>
                            <div class="chat-bubble-text"><?= htmlspecialchars($cm['comment']) ?></div>
                            <div class="chat-bubble-time"><?= timeAgo($cm['created_at']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Input area -->
            <?php if ($can_chat && $event && $is_live): ?>
                <div class="chat-input-area">
                    <?php if ($chat_error): ?>
                        <div class="chat-error-msg"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($chat_error) ?></div>
                    <?php endif; ?>
                    <form class="chat-form" method="POST" action="">
                        <textarea
                            class="chat-textarea"
                            name="comment"
                            placeholder="Type a message…"
                            maxlength="500"
                            rows="1"
                            required
                            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"
                        ></textarea>
                        <input type="hidden" name="post_comment" value="1">
                        <button type="submit" class="chat-send-btn" title="Send">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </form>
                </div>
            <?php elseif ($viewer_role === 'bidder' && $event && !$is_live): ?>
                <div class="chat-login-prompt">Chat is only open during a live session.</div>
            <?php elseif (!$viewer_id): ?>
                <div class="chat-login-prompt">
                    <a href="login.php">Log in</a> as a bidder to participate in chat.
                </div>
            <?php elseif ($viewer_role === 'user'): ?>
                <div class="chat-login-prompt">Only registered bidders can post messages.</div>
            <?php elseif ($is_admin): ?>
                <div class="chat-login-prompt">Admins can moderate via the <a href="admin/bid_opening.php">management panel</a>.</div>
            <?php endif; ?>
        </div>

        <!-- Viewer info -->
        <div class="viewer-card">
            <i class="bi bi-person-circle"></i>
            <?php if ($viewer_id): ?>
                Viewing as <strong>@<?= htmlspecialchars($_SESSION['username']) ?></strong>
                (<?= htmlspecialchars($viewer_role) ?>)
            <?php else: ?>
                Watching as <strong>Guest</strong> —
                <a href="login.php" style="color:#ffc107; font-weight:700; text-decoration:none;">Login</a>
                to participate.
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    // Auto-scroll chat to bottom on load
    const chatEl = document.getElementById('chatMessages');
    if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;

    // Auto-resize textarea
    const ta = document.querySelector('.chat-textarea');
    if (ta) {
        ta.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });
    }

    // Poll for new messages every 8 seconds (simple polling until WebSocket is added)
    <?php if ($event && $is_live): ?>
    let lastCommentId = <?= !empty($comments) ? (int)end($comments)['id'] : 0 ?>;

    function pollChat() {
        fetch('live_chat_api.php?action=fetch&session_id=<?= (int)($event['id'] ?? 0) ?>&after=' + lastCommentId)
            .then(r => r.json())
            .then(data => {
                if (data.comments && data.comments.length > 0) {
                    const empty = document.getElementById('chatEmpty');
                    if (empty) empty.remove();

                    data.comments.forEach(c => {
                        lastCommentId = Math.max(lastCommentId, c.id);
                        const msg = document.createElement('div');
                        msg.className = 'chat-msg';
                        const initials = (c.firstname.charAt(0) + c.lastname.charAt(0)).toUpperCase();
                        const avatarHtml = c.profile_picture_url
                            ? `<img src="${escHtml(c.profile_picture_url)}" alt="${escHtml(initials)}">`
                            : escHtml(initials);
                        msg.innerHTML = `
                            <div class="chat-avatar">${avatarHtml}</div>
                            <div class="chat-bubble">
                                <div class="chat-bubble-name">@${escHtml(c.username)}</div>
                                <div class="chat-bubble-text">${escHtml(c.comment)}</div>
                                <div class="chat-bubble-time">${escHtml(c.time_ago)}</div>
                            </div>
                        `;
                        chatEl.appendChild(msg);
                    });
                    chatEl.scrollTop = chatEl.scrollHeight;

                    const pill = document.getElementById('commentCount');
                    if (pill) {
                        const n = chatEl.querySelectorAll('.chat-msg').length;
                        pill.textContent = n + ' message' + (n !== 1 ? 's' : '');
                    }
                }
            })
            .catch(() => {}); // silent fail — stream may be starting/stopping
    }

    setInterval(pollChat, 8000);

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    <?php endif; ?>
</script>

</body>
</html>
