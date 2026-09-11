 
 <?php
/*
*admin/live_event.php — Live Bid Opening Management
 * Create/start/end live events, associate with a procurement, moderate chat.
 * Accessible to all admin roles (BAC/TWG can watch; SECRETARIAT can control).
 */
include("utils/protect-page.php");
require_once dirname(__DIR__) . '/config/mediamtx.php';

header("Location: bid_opening.php");
exit();

$admin_id   = (int)$_SESSION['user_id'];

// Hydrate admin_type from DB if not cached (same logic as sidebar)
if (!isset($_SESSION['admin_type']) && isset($conn)) {
    $at_stmt = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    if ($at_stmt) {
        $at_stmt->bind_param("i", $admin_id);
        $at_stmt->execute();
        $at_row = $at_stmt->get_result()->fetch_assoc();
        $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
        $at_stmt->close();
    }
}

$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$user_role  = $_SESSION['role'] ?? 'admin';

// superadmin always has full access; SECRETARIAT admins can manage
$can_manage = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

// ── Handle actions ────────────────────────────────────────────────────────

// Create new event
if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    $proc_id     = (int)$_POST['procurement_id'];
    $stream_path = trim($_POST['stream_path'] ?? 'live');
    $stream_path = preg_replace('/[^a-zA-Z0-9_\-]/', '', $stream_path) ?: 'live';
    $title       = trim($_POST['event_title'] ?? '');

    $ins = $conn->prepare("INSERT INTO live_events (procurement_id, stream_path, status, title, created_by) VALUES (?, ?, 'scheduled', ?, ?)");
    $ins->bind_param("issi", $proc_id, $stream_path, $title, $admin_id);
    $ins->execute();
    $ins->close();
    $_SESSION['alert_success'] = "Live event created.";
    header("Location: live_event.php"); exit();
}

// Go live
if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go_live'])) {
    $ev_id = (int)$_POST['event_id'];
    $u = $conn->prepare("UPDATE live_events SET status='live', started_at=NOW() WHERE id=? AND status='scheduled'");
    $u->bind_param("i", $ev_id);
    $u->execute(); $u->close();
    $_SESSION['alert_success'] = "Stream is now LIVE.";
    header("Location: live_event.php"); exit();
}

// End stream
if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_live'])) {
    $ev_id = (int)$_POST['event_id'];
    $u = $conn->prepare("UPDATE live_events SET status='ended', ended_at=NOW() WHERE id=? AND status='live'");
    $u->bind_param("i", $ev_id);
    $u->execute(); $u->close();
    $_SESSION['alert_success'] = "Stream ended.";
    header("Location: live_event.php"); exit();
}

// Moderate comment
if (in_array($admin_type, ['BAC','TWG','SECRETARIAT']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['moderate_comment'])) {
    $cid    = (int)$_POST['comment_id'];
    $status = ($_POST['mod_status'] ?? '') === 'hidden' ? 'hidden' : 'visible';
    $u = $conn->prepare("UPDATE live_comments SET status=? WHERE id=?");
    $u->bind_param("si", $status, $cid);
    $u->execute(); $u->close();
    header("Location: live_event.php"); exit();
}

// Flash
$alert_success = $_SESSION['alert_success'] ?? ''; unset($_SESSION['alert_success']);

// ── Data ──────────────────────────────────────────────────────────────────

// Active/scheduled events
$events_result = $conn->query("
    SELECT le.*, p.title AS proc_title, p.slsu_ref_no
    FROM live_events le
    JOIN procurements p ON le.procurement_id = p.id
    WHERE le.status IN ('live','scheduled')
    ORDER BY le.status='live' DESC, le.created_at DESC
");

// Open procurements (for create form)
$procs_result = $conn->query("
    SELECT id, title, slsu_ref_no FROM procurements
    WHERE status IN ('open','closed')
    ORDER BY created_at DESC
");

// Current live event comments
$live_event = $conn->query("SELECT id FROM live_events WHERE status='live' LIMIT 1")->fetch_assoc();
$comments   = [];
if ($live_event) {
    $c_stmt = $conn->prepare("
        SELECT lc.id, lc.comment, lc.status AS comment_status, lc.created_at,
               u.username, u.firstname, u.lastname
        FROM live_comments lc
        JOIN users u ON lc.user_id = u.user_id
        WHERE lc.live_event_id = ?
        ORDER BY lc.created_at DESC
        LIMIT 100
    ");
    $c_stmt->bind_param("i", $live_event['id']);
    $c_stmt->execute();
    $comments = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();
}

function timeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    return floor($diff/3600) . 'h ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Bid Opening — Manage | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php $topbar_title = 'Live Bid Opening'; include("components/topbar.php"); ?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Live Bid Opening</h2>
        <p>Manage live stream sessions, monitor chat, and moderate comments.</p>
    </div>

    <?php if ($alert_success): ?>
        <div class="toast-alert success" id="toastAlert">
            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($alert_success) ?>
        </div>
    <?php endif; ?>

    <!-- ── Stream preview ── -->
    <?php $active_event = null;
    $events_result->data_seek(0);
    while ($ev = $events_result->fetch_assoc()) {
        if ($ev['status'] === 'live') { $active_event = $ev; break; }
    }
    $events_result->data_seek(0);
    ?>

    <div class="sad-section-label">Stream Preview</div>
    <div class="ap2-card" style="margin-bottom:22px;">
        <div class="ap2-card-head" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;">
                <?php if ($active_event): ?>
                    <span style="color:#dc2626; font-weight:800; margin-right:8px;">● LIVE</span>
                    <?= htmlspecialchars($active_event['proc_title']) ?>
                <?php else: ?>
                    <span style="color:#6b7280;">No active stream</span>
                <?php endif; ?>
            </h3>
            <a href="../live.php" target="_blank" class="compact-stat-link" style="font-size:12px; font-weight:700; color:#1f7a3d; background:#eef7f1; padding:6px 12px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                <i class="bi bi-box-arrow-up-right"></i> Public Page
            </a>
        </div>
        <?php if ($active_event): ?>
        <div style="padding:0 20px 20px;">
            <div style="aspect-ratio:16/9; background:#000; border-radius:12px; overflow:hidden; max-width:700px; position:relative;">
                <div id="streamPreviewChecking" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:12.5px; text-align:center; padding:16px;">
                    <span><i class="bi bi-broadcast"></i> Checking stream…</span>
                </div>
                <iframe
                    id="streamPreviewIframe"
                    data-stream-path="<?= htmlspecialchars($active_event['stream_path']) ?>"
                    data-stream-url="<?= htmlspecialchars(mediamtx_url($active_event['stream_path'])) ?>"
                    allow="autoplay; fullscreen"
                    allowfullscreen
                    style="width:100%; height:100%; border:none; display:none;">
                </iframe>
            </div>
        </div>
        <?php else: ?>
        <div style="padding:32px; text-align:center; color:#88968d; font-size:13px;">
            <i class="bi bi-broadcast" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
            Start OBS, then go live from the event controls below.
        </div>
        <?php endif; ?>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:22px; align-items:start;">

        <!-- ── Event controls ── -->
        <div>
            <div class="sad-section-label">Event Controls</div>

            <?php if ($can_manage): ?>
            <!-- Scheduled / Live events -->
            <?php $events_result->data_seek(0); while ($ev = $events_result->fetch_assoc()): ?>
            <div class="ap2-card" style="margin-bottom:14px;">
                <div class="ap2-card-head">
                    <h3 style="font-size:13.5px; margin:0;">
                        <?= htmlspecialchars($ev['proc_title']) ?>
                        <span style="font-size:10.5px; font-weight:700; padding:2px 7px; border-radius:5px; margin-left:6px;
                            background:<?= $ev['status']==='live' ? '#fee2e2' : '#fef3c7' ?>;
                            color:<?= $ev['status']==='live' ? '#dc2626' : '#d97706' ?>;">
                            <?= strtoupper($ev['status']) ?>
                        </span>
                    </h3>
                </div>
                <div style="padding:12px 18px; font-size:12px; color:#55665a; display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between;">
                    <span><i class="bi bi-hash"></i> <?= htmlspecialchars($ev['slsu_ref_no']) ?></span>
                    <span><i class="bi bi-broadcast"></i> Path: <code><?= htmlspecialchars($ev['stream_path']) ?></code></span>
                    <div style="display:flex; gap:8px;">
                        <?php if ($ev['status'] === 'scheduled'): ?>
                        <form method="POST">
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <button type="submit" name="go_live" value="1"
                                style="background:#dc2626; color:#fff; border:none; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                <i class="bi bi-broadcast"></i> Go Live
                            </button>
                        </form>
                        <?php elseif ($ev['status'] === 'live'): ?>
                        <form method="POST" onsubmit="return confirm('End this live session?')">
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <button type="submit" name="end_live" value="1"
                                style="background:#374151; color:#fff; border:none; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                <i class="bi bi-stop-circle"></i> End Stream
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>

            <!-- Create new event form -->
            <div class="ap2-card">
                <div class="ap2-card-head"><h3>Schedule New Live Event</h3></div>
                <div style="padding:16px 20px;">
                    <form method="POST" style="display:flex; flex-direction:column; gap:12px;">
                        <div>
                            <label style="font-size:12px; font-weight:700; color:#06251b; display:block; margin-bottom:4px;">Procurement</label>
                            <select name="procurement_id" required
                                style="width:100%; padding:9px 12px; border:1px solid #d0d9d3; border-radius:9px; font-size:13px; font-family:'Poppins',sans-serif;">
                                <option value="">— Select procurement —</option>
                                <?php while ($p = $procs_result->fetch_assoc()): ?>
                                    <option value="<?= $p['id'] ?>">
                                        [<?= htmlspecialchars($p['slsu_ref_no']) ?>] <?= htmlspecialchars(mb_strimwidth($p['title'],0,60,'…')) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:12px; font-weight:700; color:#06251b; display:block; margin-bottom:4px;">Stream Path (MediaMTX)</label>
                            <input type="text" name="stream_path" value="<?= htmlspecialchars(mediamtx_default_path()) ?>" required
                                style="width:100%; padding:9px 12px; border:1px solid #d0d9d3; border-radius:9px; font-size:13px; font-family:'Poppins',sans-serif;"
                                placeholder="e.g. live">
                            <span style="font-size:10.5px; color:#88968d;">Must match your OBS stream key / MediaMTX path.</span>
                        </div>
                        <div>
                            <label style="font-size:12px; font-weight:700; color:#06251b; display:block; margin-bottom:4px;">Display Title (optional)</label>
                            <input type="text" name="event_title"
                                style="width:100%; padding:9px 12px; border:1px solid #d0d9d3; border-radius:9px; font-size:13px; font-family:'Poppins',sans-serif;"
                                placeholder="Leave blank to use procurement title">
                        </div>
                        <button type="submit" name="create_event" value="1"
                            style="background:#06251b; color:#ffc107; border:none; border-radius:9px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; align-self:flex-start; display:inline-flex; align-items:center; gap:7px;">
                            <i class="bi bi-calendar-plus"></i> Schedule Event
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="ap2-card">
                <div style="padding:24px; text-align:center; color:#88968d; font-size:12.5px;">
                    <i class="bi bi-lock" style="font-size:24px; display:block; margin-bottom:6px;"></i>
                    Stream controls are managed by the Secretariat.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Chat moderation ── -->
        <div>
            <div class="sad-section-label">Chat Moderation</div>
            <div class="ap2-card" style="max-height:520px; overflow-y:auto;">
                <?php if (empty($comments)): ?>
                    <div style="padding:32px; text-align:center; color:#88968d; font-size:12.5px;">
                        <i class="bi bi-chat-square-dots" style="font-size:26px; display:block; margin-bottom:6px;"></i>
                        No messages yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $cm): ?>
                    <div class="ap2-user-row" style="<?= $cm['comment_status']==='hidden' ? 'opacity:.45;' : '' ?>">
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:12.5px; font-weight:700; color:#06251b;">
                                @<?= htmlspecialchars($cm['username']) ?>
                                <span style="font-size:10px; color:#88968d; font-weight:400; margin-left:6px;"><?= timeAgo($cm['created_at']) ?></span>
                                <?php if ($cm['comment_status']==='hidden'): ?>
                                    <span style="font-size:9.5px; background:#fee2e2; color:#dc2626; padding:1px 6px; border-radius:4px; margin-left:4px; font-weight:700;">HIDDEN</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px; color:#374151; margin-top:2px; word-break:break-word;">
                                <?= htmlspecialchars($cm['comment']) ?>
                            </div>
                        </div>
                        <form method="POST" style="flex-shrink:0;">
                            <input type="hidden" name="comment_id" value="<?= $cm['id'] ?>">
                            <input type="hidden" name="mod_status" value="<?= $cm['comment_status']==='visible' ? 'hidden' : 'visible' ?>">
                            <button type="submit" name="moderate_comment" value="1"
                                style="background:<?= $cm['comment_status']==='visible' ? '#fee2e2' : '#e4f5ea' ?>;
                                       color:<?= $cm['comment_status']==='visible' ? '#dc2626' : '#1f7a3d' ?>;
                                       border:none; border-radius:7px; padding:4px 9px; font-size:11px; font-weight:700; cursor:pointer;">
                                <?= $cm['comment_status']==='visible' ? '<i class="bi bi-eye-slash"></i> Hide' : '<i class="bi bi-eye"></i> Show' ?>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>
</main>

<script>
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    // ── Stream health check before showing the preview iframe ──────────────
    (function () {
        const iframeEl   = document.getElementById('streamPreviewIframe');
        const checkingEl = document.getElementById('streamPreviewChecking');
        if (!iframeEl) return;

        const streamPath = iframeEl.dataset.streamPath;
        fetch('../stream_health.php?path=' + encodeURIComponent(streamPath))
            .then(r => r.json())
            .then(data => {
                if (data.online) {
                    iframeEl.src = iframeEl.dataset.streamUrl;
                    iframeEl.style.display = '';
                    if (checkingEl) checkingEl.style.display = 'none';
                } else if (checkingEl) {
                    checkingEl.innerHTML = '<span><i class="bi bi-broadcast"></i> Stream not confirmed online yet.</span>';
                }
            })
            .catch(() => {
                if (checkingEl) checkingEl.innerHTML = '<span><i class="bi bi-broadcast"></i> Unable to reach stream server.</span>';
            });
    })();
</script>

</body>
</html>
