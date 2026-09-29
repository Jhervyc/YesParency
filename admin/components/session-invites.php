<?php
/**
 * Dashboard bid-session panel (styled like "Current Live Session" on Bid Opening;
 * CSS: css/bid-session-panel.css).
 * Usage (below the greeting card): <?php include("components/session-invites.php"); ?>
 *
 * - Secretariat & superadmin: every live / scheduled session (they manage them all).
 * - BAC / TWG: only the sessions they are invited to (bid_session_invited).
 * Renders nothing when there is no live or scheduled session to show.
 */
$si_user_id    = (int)($_SESSION['user_id'] ?? 0);
$si_admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$si_can_manage = ($_SESSION['role'] ?? '') === 'superadmin' || $si_admin_type === 'SECRETARIAT';
$si_rows       = [];
$si_total      = 0;

if (isset($conn) && $si_user_id > 0) {
    $si_scope = $si_can_manage
        ? ""
        : "JOIN bid_session_invited bsi ON bsi.bid_session_id = bos.id AND bsi.user_id = ?";
    $si_stmt = $conn->prepare("
        SELECT bos.id AS session_id, bos.status, bos.started_at,
               p.title AS proc_title, p.slsu_ref_no, p.procurement_mode, p.opening_date,
               (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count,
               COUNT(*) OVER () AS total_sessions
        FROM bid_opening_sessions bos
        JOIN procurements p ON p.id = bos.procurement_id
        $si_scope
        WHERE bos.status <> 'ended'
        ORDER BY (bos.status = 'scheduled') ASC, p.opening_date IS NULL, p.opening_date ASC, bos.created_at DESC
        LIMIT 4
    ");
    if ($si_stmt) {
        if (!$si_can_manage) $si_stmt->bind_param("i", $si_user_id);
        $si_stmt->execute();
        $si_rows = $si_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $si_stmt->close();
        $si_total = (int)($si_rows[0]['total_sessions'] ?? 0);
    }
}

if (!$si_rows) return;

$si_live      = array_values(array_filter($si_rows, fn($r) => $r['status'] !== 'scheduled'));
$si_scheduled = array_values(array_filter($si_rows, fn($r) => $r['status'] === 'scheduled'));
$si_more      = $si_total - count($si_rows);
?>
<div class="sched-panel sched-panel--live si-panel">
    <div class="sched-panel-head">
        <span class="sched-panel-title">
            <?php if ($si_live): ?>
                <i class="bi bi-broadcast clr-red"></i> Current Live Session<?= count($si_live) > 1 ? 's' : '' ?>
            <?php else: ?>
                <i class="bi bi-calendar-event clr-gold-dark"></i> <?= $si_can_manage ? 'Scheduled Bid Opening' : 'Your Bid Opening Invitations' ?>
            <?php endif; ?>
        </span>
        <a href="bid-session-list.php" class="section-view-more">
            <i class="bi bi-arrow-right"></i> <?= $si_more > 0 ? "View {$si_more} more" : 'View More' ?>
        </a>
    </div>

    <?php foreach ($si_live as $ls): ?>
    <div class="bo-live-banner">
        <div class="bo-live-body">
            <div class="si-live-info">
                <div class="bo-live-head-row">
                    <span class="bo-live-pill"><span class="bo-live-dot"></span> LIVE</span>
                    <?php if (!$si_can_manage): ?><span class="si-invited-tag"><i class="bi bi-envelope-check"></i> Invited</span><?php endif; ?>
                </div>
                <div class="bo-live-title si-ellipsis" title="<?= htmlspecialchars($ls['proc_title']) ?>"><?= htmlspecialchars($ls['proc_title']) ?></div>
                <div class="bo-live-meta">
                    <span><i class="bi bi-hash"></i><?= htmlspecialchars($ls['slsu_ref_no'] ?? '—') ?></span>
                    <span><i class="bi bi-activity"></i><?= ucfirst(htmlspecialchars($ls['status'])) ?> phase</span>
                    <?php if ($ls['started_at']): ?>
                    <span><i class="bi bi-clock"></i> Started <?= date('g:i A', strtotime($ls['started_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="bo-live-actions">
            <a href="bid_session.php?session=<?= (int)$ls['session_id'] ?>" class="bo-btn-primary">
                <?php if ($si_can_manage): ?>
                    <i class="bi bi-envelope-open-fill"></i> Manage Session
                <?php else: ?>
                    <i class="bi bi-box-arrow-in-right"></i> Join
                <?php endif; ?>
            </a>
            <a href="../live.php" target="_blank" class="bo-btn-outline">
                <i class="bi bi-broadcast"></i> Live Page
            </a>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($si_scheduled): ?>
    <div class="sched-list">
        <?php foreach ($si_scheduled as $ss): ?>
        <div class="sched-card">
            <div class="sched-icon"><i class="bi bi-calendar-event"></i></div>
            <div class="sched-info">
                <div class="sched-title" title="<?= htmlspecialchars($ss['proc_title']) ?>"><?= htmlspecialchars($ss['proc_title']) ?></div>
                <div class="sched-meta">
                    <span><i class="bi bi-hash"></i><?= htmlspecialchars($ss['slsu_ref_no'] ?? '—') ?></span>
                    <?php if ($ss['opening_date']): ?>
                    <span><i class="bi bi-calendar3"></i> <?= date('M j, Y · g:i A', strtotime($ss['opening_date'])) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-inbox"></i> <?= (int)$ss['bid_count'] ?> bid<?= $ss['bid_count'] != 1 ? 's' : '' ?></span>
                    <span class="sched-pill"><i class="bi bi-<?= $si_can_manage ? 'clock' : 'envelope-check' ?>"></i> <?= $si_can_manage ? 'Scheduled' : 'Invited' ?></span>
                </div>
            </div>
            <?php if ($si_can_manage): ?>
            <a href="bid_opening.php" class="btn-open-now">
                <i class="bi bi-play-fill"></i> Open Now
            </a>
            <?php else: ?>
            <a href="bid_session.php?session=<?= (int)$ss['session_id'] ?>" class="btn-open-now">
                <i class="bi bi-door-open"></i> View Session
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
