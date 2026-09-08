<?php
/**
 * admin/bid_session_api.php — AJAX API for bid_session.php
 *
 * Actions (GET):
 *   bidders       &lot_id=N &phase=eligibility|financial &session_id=N
 *   files         &bidder_id=N &lot_id=N &phase=... &session_id=N
 *   progress      &session_id=N
 *
 * Actions (POST):
 *   decrypt_file  { doc_id, password, session_id }
 *   set_eligible  { bid_id, lot_id, eligible: 1|0, session_id }
 *   start_phase   { session_id, phase: eligibility|financial }
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../config/pusher.php';
session_start();

header('Content-Type: application/json');

// ── Auth guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']); exit();
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

// ═══════════════════════════════ GET ACTIONS ══════════════════════════════

// ── Bidders for a lot ──────────────────────────────────────────────────────
if ($action === 'bidders') {
    $lot_id     = (int)($_GET['lot_id']     ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);
    $phase      = in_array($_GET['phase'] ?? '', ['eligibility','financial','awarding']) ? $_GET['phase'] : 'eligibility';

    if ($lot_id <= 0) { echo json_encode(['bidders' => []]); exit(); }

    // Do NOT filter out rejected bidders so they remain visible in list (grayed out)
    $stmt = $conn->prepare("
        SELECT
            u.user_id AS bidder_id,
            u.firstname, u.lastname, u.username,
            u.profile_picture_url AS avatar,
            COALESCE(bp.business_name, CONCAT(u.firstname,' ',u.lastname)) AS business_name,
            b.id AS bid_id,
            b.status AS bid_status,
            bl.id AS bid_lot_id,
            bl.eligibility_status,
            bl.financial_status,
            b.submission_date
        FROM bid_lots bl
        JOIN bids b         ON b.id = bl.bid_id
        JOIN users u        ON u.user_id = b.bidder_id
        LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
        WHERE bl.lot_id = ?
        ORDER BY b.submission_date ASC
    ");
    $stmt->bind_param("i", $lot_id);
    $stmt->execute();
    $bidders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($bidders as &$b) {
        $doc_type = $phase === 'financial' ? 'financial' : 'eligibility';
        $fc = $conn->prepare("
            SELECT COUNT(*) FROM bid_documents bd
            JOIN bid_lots bl2 ON bl2.bid_id = bd.bid_id
            WHERE bd.bid_id = ? AND bl2.lot_id = ? AND bd.document_type = ?
        ");
        $fc->bind_param("iis", $b['bid_id'], $lot_id, $doc_type);
        $fc->execute();
        $b['file_count'] = (int)$fc->get_result()->fetch_row()[0];
        $fc->close();

        // Disqualification and evaluated state based on schema fields
        if ($phase === 'eligibility') {
            $b['disqualified'] = ($b['eligibility_status'] === 'disqualified');
            $b['evaluated']    = in_array($b['eligibility_status'], ['eligible','disqualified']);
            $b['files_opened'] = in_array($b['eligibility_status'], ['opened','eligible','disqualified']);
        } else {
            $b['disqualified'] = ($b['eligibility_status'] === 'disqualified' || $b['financial_status'] === 'non_compliant');
            $b['evaluated']    = in_array($b['financial_status'], ['qualified','non_compliant']);
            $b['files_opened'] = in_array($b['financial_status'], ['opened','qualified','non_compliant']);
        }
    }
    unset($b);

    echo json_encode(['bidders' => $bidders]); exit();
}

// ── Files for a bidder + lot ───────────────────────────────────────────────
if ($action === 'files') {
    $bidder_id  = (int)($_GET['bidder_id']  ?? 0);
    $lot_id     = (int)($_GET['lot_id']     ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);
    $phase      = in_array($_GET['phase'] ?? '', ['eligibility','financial']) ? $_GET['phase'] : 'eligibility';
    $doc_type   = $phase;

    if ($bidder_id <= 0 || $lot_id <= 0) { echo json_encode(['files' => []]); exit(); }

    $stmt = $conn->prepare("
        SELECT bd.id, bd.document_name AS file_name, bd.document_type,
               bd.file_path,
               DATE_FORMAT(bd.uploaded_at, '%b %e, %Y · %h:%i %p') AS uploaded_at,
               b.id AS bid_id
        FROM bid_documents bd
        JOIN bids b     ON b.id = bd.bid_id
        JOIN bid_lots bl ON bl.bid_id = b.id
        WHERE b.bidder_id = ?
          AND bl.lot_id   = ?
          AND bd.document_type = ?
        ORDER BY bd.uploaded_at ASC
    ");
    $stmt->bind_param("iis", $bidder_id, $lot_id, $doc_type);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Clean up file names
    foreach ($files as &$f) {
        // document_name may be "Eligibility (filename.pdf)" — extract the display name
        if (preg_match('/\((.+)\)$/', $f['file_name'], $m)) {
            $f['display_name'] = trim($m[1]);
        } else {
            $f['display_name'] = basename($f['file_name']);
        }
        $f['opened'] = false; // decryption state tracked client-side per session
    }
    unset($f);

    echo json_encode(['files' => $files]); exit();
}

// ── Session progress (database-driven) ────────────────────────────────────
if ($action === 'progress') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    if ($session_id <= 0) { echo json_encode(['status' => 'unknown']); exit(); }

    $stmt = $conn->prepare("SELECT id, procurement_id, status, signing_status, current_lot_id, started_at, ended_at FROM bid_opening_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $sess_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sess_row) { echo json_encode(['status' => 'unknown']); exit(); }

    $proc_id = (int)$sess_row['procurement_id'];

    // Fetch lots evaluation & award progress directly from database
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
    $lots_summary = $lots_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lots_stmt->close();

    foreach ($lots_summary as &$ls) {
        $total  = (int)$ls['total_bids'];
        $p_elig = (int)$ls['pending_elig'];
        $p_fin  = (int)$ls['pending_fin'];
        $ls['is_done'] = ($total === 0 || ($p_elig === 0 && $p_fin === 0) || (int)$ls['is_awarded'] > 0);
    }
    unset($ls);

    // Derive current stage from status — 'eligibility' and 'financial' are used directly;
    // 'started' maps to 'eligibility' as the default opening phase.
    $raw_status    = $sess_row['status'];
    $current_stage = in_array($raw_status, ['eligibility', 'financial']) ? $raw_status : 'eligibility';

    echo json_encode([
        'status'         => $raw_status,
        'current_stage'  => $current_stage,
        'signing_status' => $sess_row['signing_status'] ?? 'not_started',
        'current_lot_id' => (int)($sess_row['current_lot_id'] ?? 0),
        'started_at'     => $sess_row['started_at'],
        'ended_at'       => $sess_row['ended_at'],
        'lots'           => $lots_summary
    ]);
    exit();
}

// ── Get awards for procurement ─────────────────────────────────────────────
if ($action === 'get_awards') {
    $proc_id    = (int)($_GET['proc_id'] ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);

    if ($proc_id <= 0 && $session_id > 0) {
        $ps = $conn->prepare("SELECT procurement_id FROM bid_opening_sessions WHERE id = ?");
        $ps->bind_param("i", $session_id);
        $ps->execute();
        $ps_row = $ps->get_result()->fetch_assoc();
        $ps->close();
        $proc_id = (int)($ps_row['procurement_id'] ?? 0);
    }

    if ($proc_id <= 0) { echo json_encode(['awards' => []]); exit(); }

    $stmt = $conn->prepare("
        SELECT a.id, a.lot_id, a.bid_lot_id, a.awarded_amount,
               DATE_FORMAT(a.award_date, '%b %e, %Y') AS award_date_fmt,
               l.lot_number, l.lot_title, l.abc,
               u.firstname, u.lastname, u.username, u.profile_picture_url AS avatar,
               COALESCE(bp.business_name, CONCAT(u.firstname,' ',u.lastname)) AS business_name,
               bl.bid_id
        FROM awards a
        JOIN lots l      ON l.id = a.lot_id
        JOIN bid_lots bl  ON bl.id = a.bid_lot_id
        JOIN bids b       ON b.id = bl.bid_id
        JOIN users u      ON u.user_id = b.bidder_id
        LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
        WHERE l.procurement_id = ?
        ORDER BY l.lot_number ASC
    ");
    $stmt->bind_param("i", $proc_id);
    $stmt->execute();
    $awards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Failed lots — read directly from lots.status = 'failed', scoped per lot_id
    $fl = $conn->prepare("
        SELECT id AS lot_id, lot_number
        FROM lots
        WHERE procurement_id = ? AND status = 'failed'
    ");
    $fl->bind_param("i", $proc_id);
    $fl->execute();
    $failed_lots = $fl->get_result()->fetch_all(MYSQLI_ASSOC);
    $fl->close();

    echo json_encode(['awards' => $awards, 'failed_lots' => $failed_lots]); exit();
}

// ═══════════════════════════════ POST ACTIONS ═════════════════════════════

// ── Decrypt file ───────────────────────────────────────────────────────────
if ($action === 'decrypt_file') {
    $doc_id     = (int)($_POST['doc_id']     ?? 0);
    $password   = $_POST['password']          ?? '';
    $session_id = (int)($_POST['session_id'] ?? 0);

    if ($doc_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters.']); exit();
    }

    // Verify admin password only when provided (silent re-decrypt on reload skips this)
    if (!empty($password)) {
        $pw_stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $pw_stmt->bind_param("i", $user_id);
        $pw_stmt->execute();
        $pw_row = $pw_stmt->get_result()->fetch_assoc();
        $pw_stmt->close();

        if (!$pw_row || !password_verify($password, $pw_row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect password.']); exit();
        }
    }

    // Fetch document
    $doc_stmt = $conn->prepare("SELECT file_path, document_type, document_name FROM bid_documents WHERE id = ?");
    $doc_stmt->bind_param("i", $doc_id);
    $doc_stmt->execute();
    $doc = $doc_stmt->get_result()->fetch_assoc();
    $doc_stmt->close();

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']); exit();
    }

    $abs_path = realpath(dirname(__DIR__) . '/' . ltrim(str_replace('../', '', $doc['file_path']), '/'));
    if (!$abs_path) {
        $clean = preg_replace('#^(\.\./)+#', '', $doc['file_path']);
        $abs_path = dirname(__DIR__) . '/' . ltrim($clean, '/');
    }
    $result = decryptFile($abs_path, $doc['document_type']);

    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => $result['message']]); exit();
    }

    $display_name = $doc['document_name'];
    if (preg_match('/\((.+)\)$/', $display_name, $m)) {
        $display_name = trim($m[1]);
    }
    $ext  = strtolower(pathinfo($display_name, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'pdf'        => 'application/pdf',
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        default      => 'application/octet-stream',
    };

    $b64 = base64_encode($result['data']);
    echo json_encode([
        'success'   => true,
        'data_url'  => "data:{$mime};base64,{$b64}",
        'mime'      => $mime,
        'file_name' => $display_name,
        'ext'       => $ext,
    ]);
    exit();
}

// ── Set eligibility / compliance result for a bidder on a lot ───────────────────────────
if ($action === 'set_eligible') {
    $bid_id     = (int)($_POST['bid_id']     ?? 0);
    $lot_id     = (int)($_POST['lot_id']     ?? 0);
    $eligible   = (int)($_POST['eligible']   ?? 0); // 1 = eligible/comply, 0 = disqualified/non_compliant
    $phase      = $_POST['phase']            ?? 'eligibility';
    $session_id = (int)($_POST['session_id'] ?? 0);
    $bid_lot_id = (int)($_POST['bid_lot_id'] ?? 0);

    if ($bid_id <= 0 || $lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // ── Server-side pending-item guard (Eligible / Comply only — not Disqualify) ──
    // Prevents bypassing the JS disabled button via a crafted request.
    if ($eligible && $bid_lot_id > 0) {
        $pending_chk = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM bid_checklist
            WHERE bid_lot_id = ?
              AND result = 'pending'
        ");
        $pending_chk->bind_param("i", $bid_lot_id);
        $pending_chk->execute();
        $pending_cnt = (int)$pending_chk->get_result()->fetch_assoc()['cnt'];
        $pending_chk->close();

        if ($pending_cnt > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Cannot mark bidder as Eligible. All checklist items must be completed first ({$pending_cnt} still pending).",
            ]); exit();
        }
    }

    if ($phase === 'financial') {
        $fin_status = $eligible ? 'qualified' : 'non_compliant';
        $lupd = $conn->prepare("UPDATE bid_lots SET financial_status = ? WHERE bid_id = ? AND lot_id = ?");
        $lupd->bind_param("sii", $fin_status, $bid_id, $lot_id);
        $lupd->execute();
        $lupd->close();
    } else {
        $elig_status = $eligible ? 'eligible' : 'disqualified';
        $lupd = $conn->prepare("UPDATE bid_lots SET eligibility_status = ? WHERE bid_id = ? AND lot_id = ?");
        $lupd->bind_param("sii", $elig_status, $bid_id, $lot_id);
        $lupd->execute();
        $lupd->close();

        // Disqualifying eligibility cascades to financial on the same lot
        if (!$eligible) {
            $lupd2 = $conn->prepare("UPDATE bid_lots SET financial_status = 'non_compliant' WHERE bid_id = ? AND lot_id = ?");
            $lupd2->bind_param("ii", $bid_id, $lot_id);
            $lupd2->execute();
            $lupd2->close();
        }
    }

    // Reset signing_status so the next bidder requires a fresh signing cycle
    if ($session_id > 0) {
        $srst = $conn->prepare("UPDATE bid_opening_sessions SET signing_status = 'not_started' WHERE id = ?");
        $srst->bind_param("i", $session_id);
        $srst->execute();
        $srst->close();
    }

    pusher_trigger($session_id, 'eligibility_updated', [
        'bid_id'  => $bid_id,
        'lot_id'  => $lot_id,
        'phase'   => $phase,
        'eligible'=> $eligible,
    ]);

    echo json_encode(['success' => true]); exit();
}

// ── Advance session phase ──────────────────────────────────────────────────
if ($action === 'start_phase') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $phase      = $_POST['phase'] ?? '';

    $allowed = ['eligibility', 'financial', 'awarding', 'ended', 'started'];
    if (!in_array($phase, $allowed) || $session_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    if ($phase === 'ended') {
        $upd = $conn->prepare("UPDATE bid_opening_sessions SET status = 'ended', ended_at = NOW() WHERE id = ?");
        $upd->bind_param("i", $session_id);
    } else {
        // Reset signing_status to 'not_started' whenever the phase advances —
        // each new phase/lot requires a fresh signing cycle
        $upd = $conn->prepare("UPDATE bid_opening_sessions SET status = ?, signing_status = 'not_started' WHERE id = ?");
        $upd->bind_param("si", $phase, $session_id);
    }
    $upd->execute();
    $upd->close();

    pusher_trigger($session_id, 'phase_changed', ['phase' => $phase]);

    echo json_encode(['success' => true, 'new_status' => $phase]); exit();
}

// ── Start session (scheduled → started) ───────────────────────────────────
if ($action === 'start_session') {
    $session_id   = (int)($_POST['session_id']   ?? 0);
    $first_lot_id = (int)($_POST['first_lot_id'] ?? 0);
    if ($session_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid session.']); exit();
    }

    if ($first_lot_id > 0) {
        $upd = $conn->prepare("
            UPDATE bid_opening_sessions
            SET status = 'started', started_at = NOW(), current_lot_id = ?
            WHERE id = ? AND status = 'scheduled'
        ");
        $upd->bind_param("ii", $first_lot_id, $session_id);
    } else {
        $upd = $conn->prepare("
            UPDATE bid_opening_sessions
            SET status = 'started', started_at = NOW()
            WHERE id = ? AND status = 'scheduled'
        ");
        $upd->bind_param("i", $session_id);
    }
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    pusher_trigger($session_id, 'session_started', []);

    echo json_encode(['success' => true, 'new_status' => 'started']); exit();
}

// ── Update current lot for session ─────────────────────────────────────────
if ($action === 'set_current_lot') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $lot_id     = (int)($_POST['lot_id']     ?? 0);

    if ($session_id > 0) {
        if ($lot_id > 0) {
            // Reset signing_status when advancing to a new lot — fresh cycle required
            $upd = $conn->prepare("UPDATE bid_opening_sessions SET current_lot_id = ?, signing_status = 'not_started' WHERE id = ?");
            $upd->bind_param("ii", $lot_id, $session_id);
        } else {
            $upd = $conn->prepare("UPDATE bid_opening_sessions SET current_lot_id = NULL, signing_status = 'not_started' WHERE id = ?");
            $upd->bind_param("i", $session_id);
        }
        $upd->execute();
        $upd->close();
    }
    pusher_trigger($session_id, 'lot_changed', ['lot_id' => $lot_id]);
    echo json_encode(['success' => true]); exit();
}

// ── Set bid_lot status to opened (phase-specific) ─────────────────────────
if ($action === 'set_lot_opened') {
    $bid_id     = (int)($_POST['bid_id']     ?? 0);
    $lot_id     = (int)($_POST['lot_id']     ?? 0);
    $phase      = $_POST['phase']            ?? 'eligibility';
    $session_id = (int)($_POST['session_id'] ?? 0);

    if ($bid_id <= 0 || $lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    if ($phase === 'financial') {
        $upd = $conn->prepare("
            UPDATE bid_lots SET financial_status = 'opened'
            WHERE bid_id = ? AND lot_id = ? AND financial_status = 'pending'
        ");
    } else {
        $upd = $conn->prepare("
            UPDATE bid_lots SET eligibility_status = 'opened'
            WHERE bid_id = ? AND lot_id = ? AND eligibility_status = 'pending'
        ");
    }
    $upd->bind_param("ii", $bid_id, $lot_id);
    $upd->execute();
    $upd->close();

    echo json_encode(['success' => true]); exit();
}

// ── Fail Lot (no award — lot failed) ─────────────────────────────────────
if ($action === 'fail_lot') {
    $lot_id     = (int)($_POST['lot_id']     ?? 0);
    $session_id = (int)($_POST['session_id'] ?? 0);

    if ($lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Mark the lot itself as failed — scoped directly to this lot_id, no cross-lot bleed
    $upd = $conn->prepare("UPDATE lots SET status = 'failed' WHERE id = ?");
    $upd->bind_param("i", $lot_id);
    $upd->execute();
    $upd->close();

    // Remove any stale award row for this lot (in case of undo from awarded state)
    $del = $conn->prepare("DELETE FROM awards WHERE lot_id = ?");
    $del->bind_param("i", $lot_id);
    $del->execute();
    $del->close();

    pusher_trigger($session_id, 'lot_failed', ['lot_id' => $lot_id]);

    echo json_encode(['success' => true, 'lot_id' => $lot_id]); exit();
}

// ── Award Lot to Winner ───────────────────────────────────────────────────
if ($action === 'award_lot') {
    $session_id     = (int)($_POST['session_id']     ?? 0);
    $lot_id         = (int)($_POST['lot_id']         ?? 0);
    $bid_lot_id     = (int)($_POST['bid_lot_id']     ?? 0);
    $awarded_amount = (float)($_POST['awarded_amount'] ?? 0);

    if ($lot_id <= 0 || $bid_lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Verify bid_lot belongs to this lot
    $chk = $conn->prepare("SELECT id, bid_id FROM bid_lots WHERE id = ? AND lot_id = ?");
    $chk->bind_param("ii", $bid_lot_id, $lot_id);
    $chk->execute();
    $bl_row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$bl_row) {
        echo json_encode(['success' => false, 'message' => 'bid_lot not found for this lot.']); exit();
    }

    // Upsert into awards using bid_lot_id
    $ins = $conn->prepare("
        INSERT INTO awards (lot_id, bid_lot_id, awarded_amount, award_date)
        VALUES (?, ?, ?, CURDATE())
        ON DUPLICATE KEY UPDATE bid_lot_id = VALUES(bid_lot_id), awarded_amount = VALUES(awarded_amount), award_date = VALUES(award_date)
    ");
    $ins->bind_param("iid", $lot_id, $bid_lot_id, $awarded_amount);
    $ins->execute();
    $ins->close();

    // Mark the winning bid as awarded
    $bid_id = (int)$bl_row['bid_id'];
    $bupd = $conn->prepare("UPDATE bids SET status = 'awarded' WHERE id = ?");
    $bupd->bind_param("i", $bid_id);
    $bupd->execute();
    $bupd->close();

    // Mark the lot itself as awarded
    $lupd = $conn->prepare("UPDATE lots SET status = 'awarded' WHERE id = ?");
    $lupd->bind_param("i", $lot_id);
    $lupd->execute();
    $lupd->close();

    pusher_trigger($session_id, 'lot_awarded', [
        'lot_id'     => $lot_id,
        'bid_lot_id' => $bid_lot_id,
    ]);

    echo json_encode(['success' => true, 'message' => 'Award recorded successfully.']); exit();
}

// ── End Session ───────────────────────────────────────────────────────────
if ($action === 'end_session') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $proc_id    = (int)($_POST['proc_id']    ?? 0);

    if ($session_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid session.']); exit();
    }

    // 1. Mark session as ended
    $upd = $conn->prepare("
        UPDATE bid_opening_sessions
        SET status = 'ended', ended_at = NOW()
        WHERE id = ?
    ");
    $upd->bind_param("i", $session_id);
    $upd->execute();
    $upd->close();

    if ($proc_id > 0) {

        // 2. Any lot still 'pending' (no award, not marked failed) → mark failed
        $lupd = $conn->prepare("
            UPDATE lots SET status = 'failed'
            WHERE procurement_id = ? AND status = 'pending'
        ");
        $lupd->bind_param("i", $proc_id);
        $lupd->execute();
        $lupd->close();

        // 3. Mark losing bids as 'rejected' (submitted/opened/pending but not awarded)
        $bupd = $conn->prepare("
            UPDATE bids
            SET status = 'rejected'
            WHERE procurement_id = ?
              AND status NOT IN ('awarded', 'rejected')
        ");
        $bupd->bind_param("i", $proc_id);
        $bupd->execute();
        $bupd->close();

        // 4. Update procurement status — 'awarded' if any lot was awarded, else 'closed'
        $ac = $conn->prepare("
            SELECT COUNT(*) FROM lots
            WHERE procurement_id = ? AND status = 'awarded'
        ");
        $ac->bind_param("i", $proc_id);
        $ac->execute();
        $award_count = (int)$ac->get_result()->fetch_row()[0];
        $ac->close();

        $proc_status = ($award_count > 0) ? 'awarded' : 'closed';
        $pupd = $conn->prepare("UPDATE procurements SET status = ? WHERE id = ?");
        $pupd->bind_param("si", $proc_status, $proc_id);
        $pupd->execute();
        $pupd->close();
    }

    pusher_trigger($session_id, 'session_ended', []);

    echo json_encode(['success' => true, 'new_status' => 'ended']); exit();
}

// ── Signal open (Secretariat signals that a bid_lot is ready to be opened) ─
// This does NOT decrypt — it just sets the "open now" flag so BAC can sign.
// Stored in bid_lot_signatures with user_id = secretariat and a special role marker.
// We reuse the same table but mark it so quorum logic skips it.
// ── Signal open (Secretariat starts the signing process) ──────────────────
// Sets bid_opening_sessions.signing_status = 'signing' so BAC knows to sign.
// Does NOT touch bid_lots or bid_lot_signatures.
if ($action === 'signal_open') {
    $session_id   = (int)($_POST['session_id']   ?? 0);

    if ($session_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Verify caller is SECRETARIAT or superadmin
    $role_chk = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $role_chk->bind_param("i", $user_id);
    $role_chk->execute();
    $role_row = $role_chk->get_result()->fetch_assoc();
    $role_chk->close();
    $caller_type = $role_row['admin_type'] ?? '';
    if ($_SESSION['role'] !== 'superadmin' && $caller_type !== 'SECRETARIAT') {
        echo json_encode(['success' => false, 'message' => 'Only Secretariat can start the signing.']); exit();
    }

    $upd = $conn->prepare("UPDATE bid_opening_sessions SET signing_status = 'signing' WHERE id = ?");
    $upd->bind_param("i", $session_id);
    $upd->execute();
    $upd->close();

    pusher_trigger($session_id, 'signing_started', []);

    echo json_encode(['success' => true]); exit();
}

// ── Sign lot (BAC member enters password and signs to contribute to quorum) ──
if ($action === 'sign_lot') {
    $bid_lot_id  = (int)($_POST['bid_lot_id']  ?? 0);
    $opening_type = $_POST['opening_type']      ?? 'eligibility';
    $password    = $_POST['password']            ?? '';
    $session_id  = (int)($_POST['session_id']  ?? 0);

    if ($bid_lot_id <= 0 || !in_array($opening_type, ['eligibility','financial']) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // 1. Verify caller is BAC
    $role_chk = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $role_chk->bind_param("i", $user_id);
    $role_chk->execute();
    $role_row = $role_chk->get_result()->fetch_assoc();
    $role_chk->close();
    if (($role_row['admin_type'] ?? '') !== 'BAC') {
        echo json_encode(['success' => false, 'message' => 'Only BAC members can sign.']); exit();
    }

    // 2. Verify caller is invited to this session
    $inv_chk = $conn->prepare("SELECT 1 FROM bid_session_invited WHERE bid_session_id = ? AND user_id = ? LIMIT 1");
    $inv_chk->bind_param("ii", $session_id, $user_id);
    $inv_chk->execute();
    $is_invited = (bool)$inv_chk->get_result()->fetch_row();
    $inv_chk->close();
    if (!$is_invited) {
        echo json_encode(['success' => false, 'message' => 'You are not invited to this session.']); exit();
    }

    // 3. Verify password
    $pw_stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $pw_stmt->bind_param("i", $user_id);
    $pw_stmt->execute();
    $pw_row = $pw_stmt->get_result()->fetch_assoc();
    $pw_stmt->close();
    if (!$pw_row || !password_verify($password, $pw_row['password'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']); exit();
    }

    // 4. Insert signature (ignore duplicate — user already signed)
    $ins = $conn->prepare("
        INSERT IGNORE INTO bid_lot_signatures (bid_lot_id, user_id, opening_type)
        VALUES (?, ?, ?)
    ");
    $ins->bind_param("iis", $bid_lot_id, $user_id, $opening_type);
    $ins->execute();
    $ins->close();

    // 5. Check quorum — count BAC members invited to this session
    $bac_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_session_invited bsi
        JOIN admin_roles ar ON ar.user_id = bsi.user_id
        WHERE bsi.bid_session_id = ? AND ar.admin_type = 'BAC'
    ");
    $bac_count_stmt->bind_param("i", $session_id);
    $bac_count_stmt->execute();
    $bac_total = (int)$bac_count_stmt->get_result()->fetch_row()[0];
    $bac_count_stmt->close();

    // Count signatures for this bid_lot + opening_type (only from invited BAC members)
    $sig_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_lot_signatures bls
        JOIN bid_session_invited bsi ON bsi.user_id = bls.user_id AND bsi.bid_session_id = ?
        JOIN admin_roles ar ON ar.user_id = bls.user_id AND ar.admin_type = 'BAC'
        WHERE bls.bid_lot_id = ? AND bls.opening_type = ?
    ");
    $sig_count_stmt->bind_param("iis", $session_id, $bid_lot_id, $opening_type);
    $sig_count_stmt->execute();
    $sig_count = (int)$sig_count_stmt->get_result()->fetch_row()[0];
    $sig_count_stmt->close();

    // Majority = more than half (ceiling of bac_total / 2)
    $required  = ($bac_total > 0) ? (int)ceil($bac_total / 2) : 1;
    $quorum_reached = ($sig_count >= $required);

    // Fetch signers list for display
    $signers_stmt = $conn->prepare("
        SELECT u.firstname, u.lastname, bls.signed_at
        FROM bid_lot_signatures bls
        JOIN users u ON u.user_id = bls.user_id
        JOIN bid_session_invited bsi ON bsi.user_id = bls.user_id AND bsi.bid_session_id = ?
        JOIN admin_roles ar ON ar.user_id = bls.user_id AND ar.admin_type = 'BAC'
        WHERE bls.bid_lot_id = ? AND bls.opening_type = ?
        ORDER BY bls.signed_at ASC
    ");
    $signers_stmt->bind_param("iis", $session_id, $bid_lot_id, $opening_type);
    $signers_stmt->execute();
    $signers = $signers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $signers_stmt->close();

    // Read current signing_status
    $ss_stmt = $conn->prepare("SELECT signing_status FROM bid_opening_sessions WHERE id = ?");
    $ss_stmt->bind_param("i", $session_id);
    $ss_stmt->execute();
    $ss_row = $ss_stmt->get_result()->fetch_assoc();
    $ss_stmt->close();

    pusher_trigger($session_id, 'bac_signed', [
        'bid_lot_id'     => $bid_lot_id,
        'opening_type'   => $opening_type,
        'sig_count'      => $sig_count,
        'required'       => $required,
        'quorum_reached' => $quorum_reached,
        'signing_status' => $ss_row['signing_status'] ?? 'signing',
    ]);

    echo json_encode([
        'success'        => true,
        'sig_count'      => $sig_count,
        'bac_total'      => $bac_total,
        'required'       => $required,
        'quorum_reached' => $quorum_reached,
        'signing_status' => $ss_row['signing_status'] ?? 'signing',
        'signers'        => $signers,
    ]); exit();
}

// ── Check quorum status for a bid_lot ─────────────────────────────────────
if ($action === 'check_quorum') {
    $bid_lot_id   = (int)($_GET['bid_lot_id']   ?? 0);
    $opening_type = $_GET['opening_type']         ?? 'eligibility';
    $session_id   = (int)($_GET['session_id']   ?? 0);

    if ($bid_lot_id <= 0 || $session_id <= 0) {
        echo json_encode(['quorum_reached' => false, 'sig_count' => 0, 'required' => 1, 'bac_total' => 0]); exit();
    }

    // Total invited BAC members
    $bac_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_session_invited bsi
        JOIN admin_roles ar ON ar.user_id = bsi.user_id
        WHERE bsi.bid_session_id = ? AND ar.admin_type = 'BAC'
    ");
    $bac_count_stmt->bind_param("i", $session_id);
    $bac_count_stmt->execute();
    $bac_total = (int)$bac_count_stmt->get_result()->fetch_row()[0];
    $bac_count_stmt->close();

    // Signatures from invited BAC members only
    $sig_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_lot_signatures bls
        JOIN bid_session_invited bsi ON bsi.user_id = bls.user_id AND bsi.bid_session_id = ?
        JOIN admin_roles ar ON ar.user_id = bls.user_id AND ar.admin_type = 'BAC'
        WHERE bls.bid_lot_id = ? AND bls.opening_type = ?
    ");
    $sig_count_stmt->bind_param("iis", $session_id, $bid_lot_id, $opening_type);
    $sig_count_stmt->execute();
    $sig_count = (int)$sig_count_stmt->get_result()->fetch_row()[0];
    $sig_count_stmt->close();

    // Who has already signed (to show in UI)
    $signers_stmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.profile_picture_url AS avatar,
               bls.signed_at
        FROM bid_lot_signatures bls
        JOIN users u ON u.user_id = bls.user_id
        JOIN bid_session_invited bsi ON bsi.user_id = bls.user_id AND bsi.bid_session_id = ?
        JOIN admin_roles ar ON ar.user_id = bls.user_id AND ar.admin_type = 'BAC'
        WHERE bls.bid_lot_id = ? AND bls.opening_type = ?
        ORDER BY bls.signed_at ASC
    ");
    $signers_stmt->bind_param("iis", $session_id, $bid_lot_id, $opening_type);
    $signers_stmt->execute();
    $signers = $signers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $signers_stmt->close();

    // Has the current user already signed?
    $already_signed = !empty(array_filter($signers, fn($s) => (int)$s['user_id'] === $user_id));

    // Read signing_status directly from bid_opening_sessions
    $ss_stmt = $conn->prepare("SELECT signing_status FROM bid_opening_sessions WHERE id = ?");
    $ss_stmt->bind_param("i", $session_id);
    $ss_stmt->execute();
    $ss_row = $ss_stmt->get_result()->fetch_assoc();
    $ss_stmt->close();
    $signing_status = $ss_row['signing_status'] ?? 'not_started';

    $required = ($bac_total > 0) ? (int)ceil($bac_total / 2) : 1;

    echo json_encode([
        'quorum_reached'   => ($sig_count >= $required),
        'sig_count'        => $sig_count,
        'bac_total'        => $bac_total,
        'required'         => $required,
        'already_signed'   => $already_signed,
        'signing_status'   => $signing_status,
        'signers'          => $signers,
    ]); exit();
}

// ── Open Files (Secretariat opens after quorum reached — decrypts + marks opened) ──
if ($action === 'open_files') {
    $bid_id      = (int)($_POST['bid_id']      ?? 0);
    $lot_id      = (int)($_POST['lot_id']      ?? 0);
    $bid_lot_id  = (int)($_POST['bid_lot_id']  ?? 0);
    $opening_type = $_POST['opening_type']      ?? 'eligibility';
    $session_id  = (int)($_POST['session_id']  ?? 0);

    if ($bid_id <= 0 || $lot_id <= 0 || $bid_lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Verify caller is SECRETARIAT or superadmin
    $role_chk = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $role_chk->bind_param("i", $user_id);
    $role_chk->execute();
    $role_row = $role_chk->get_result()->fetch_assoc();
    $role_chk->close();
    $caller_type = $role_row['admin_type'] ?? '';
    if ($_SESSION['role'] !== 'superadmin' && $caller_type !== 'SECRETARIAT') {
        echo json_encode(['success' => false, 'message' => 'Only Secretariat can open files.']); exit();
    }

    // Verify quorum is actually reached before allowing open
    $bac_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_session_invited bsi
        JOIN admin_roles ar ON ar.user_id = bsi.user_id
        WHERE bsi.bid_session_id = ? AND ar.admin_type = 'BAC'
    ");
    $bac_count_stmt->bind_param("i", $session_id);
    $bac_count_stmt->execute();
    $bac_total = (int)$bac_count_stmt->get_result()->fetch_row()[0];
    $bac_count_stmt->close();

    $sig_count_stmt = $conn->prepare("
        SELECT COUNT(*) FROM bid_lot_signatures bls
        JOIN bid_session_invited bsi ON bsi.user_id = bls.user_id AND bsi.bid_session_id = ?
        JOIN admin_roles ar ON ar.user_id = bls.user_id AND ar.admin_type = 'BAC'
        WHERE bls.bid_lot_id = ? AND bls.opening_type = ?
    ");
    $sig_count_stmt->bind_param("iis", $session_id, $bid_lot_id, $opening_type);
    $sig_count_stmt->execute();
    $sig_count = (int)$sig_count_stmt->get_result()->fetch_row()[0];
    $sig_count_stmt->close();

    $required = ($bac_total > 0) ? (int)ceil($bac_total / 2) : 1;
    if ($sig_count < $required) {
        echo json_encode(['success' => false, 'message' => "Quorum not yet reached ({$sig_count}/{$required})."]); exit();
    }

    // Update bid_lots status to 'opened'
    if ($opening_type === 'financial') {
        $upd = $conn->prepare("UPDATE bid_lots SET financial_status = 'opened' WHERE bid_id = ? AND lot_id = ? AND financial_status = 'pending'");
    } else {
        $upd = $conn->prepare("UPDATE bid_lots SET eligibility_status = 'opened' WHERE bid_id = ? AND lot_id = ? AND eligibility_status = 'pending'");
    }
    $upd->bind_param("ii", $bid_id, $lot_id);
    $upd->execute();
    $upd->close();

    // Mark signing as done on the session
    $supd = $conn->prepare("UPDATE bid_opening_sessions SET signing_status = 'done' WHERE id = ?");
    $supd->bind_param("i", $session_id);
    $supd->execute();
    $supd->close();

    pusher_trigger($session_id, 'files_opened', [
        'bid_id'       => $bid_id,
        'lot_id'       => $lot_id,
        'bid_lot_id'   => $bid_lot_id,
        'opening_type' => $opening_type,
    ]);

    echo json_encode(['success' => true]); exit();
}

// ── Get / init checklist for a bid_lot ────────────────────────────────────
if ($action === 'get_checklist') {
    $bid_lot_id       = (int)($_GET['bid_lot_id']       ?? 0);
    $checklist_type   = $_GET['checklist_type']           ?? '';
    $procurement_type = $_GET['procurement_type']         ?? '';
    $session_id       = (int)($_GET['session_id']       ?? 0);

    $valid_checklist   = ['eligibility', 'financial'];
    $valid_procurement = ['goods_services', 'infrastructure'];

    if ($bid_lot_id <= 0
        || !in_array($checklist_type, $valid_checklist)
        || !in_array($procurement_type, $valid_procurement)
        || $session_id <= 0
    ) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Verify bid_lot belongs to this session's procurement
    $chk = $conn->prepare("
        SELECT bl.id, bl.eligibility_status, bl.lot_id
        FROM bid_lots bl
        JOIN lots l ON l.id = bl.lot_id
        JOIN bid_opening_sessions bos ON bos.procurement_id = l.procurement_id
        WHERE bl.id = ? AND bos.id = ?
        LIMIT 1
    ");
    $chk->bind_param("ii", $bid_lot_id, $session_id);
    $chk->execute();
    $bl_row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$bl_row) {
        echo json_encode(['success' => false, 'message' => 'Bid lot not found in this session.']); exit();
    }

    // Financial checklist: disqualified bidders cannot access it
    if ($checklist_type === 'financial' && $bl_row['eligibility_status'] === 'disqualified') {
        echo json_encode(['success' => false, 'message' => 'Disqualified bidders cannot access the financial checklist.']); exit();
    }

    // Load active templates for this procurement_type + checklist_type
    $tpl = $conn->prepare("
        SELECT id, item_name, description, is_required, display_order
        FROM checklist_templates
        WHERE procurement_type = ?
          AND checklist_type   = ?
          AND is_active = 1
        ORDER BY display_order ASC
    ");
    $tpl->bind_param("ss", $procurement_type, $checklist_type);
    $tpl->execute();
    $templates = $tpl->get_result()->fetch_all(MYSQLI_ASSOC);
    $tpl->close();

    if (empty($templates)) {
        echo json_encode(['success' => true, 'items' => [], 'procurement_type' => $procurement_type, 'checklist_type' => $checklist_type]); exit();
    }

    // Init missing bid_checklist rows — INSERT IGNORE preserves existing records and their snapshots
    $ins = $conn->prepare("
        INSERT IGNORE INTO bid_checklist (bid_lot_id, template_item_id, item_name)
        VALUES (?, ?, ?)
    ");
    foreach ($templates as $t) {
        $ins->bind_param("iis", $bid_lot_id, $t['id'], $t['item_name']);
        $ins->execute();
    }
    $ins->close();

    // Build a lookup for template metadata
    $tpl_map = array_column($templates, null, 'id');
    $tpl_ids = implode(',', array_column($templates, 'id'));

    // Load saved checklist rows ordered by template display_order
    $rows = $conn->prepare("
        SELECT bc.id, bc.template_item_id, bc.item_name, bc.result, bc.remarks,
               bc.checked_at,
               u.firstname, u.lastname
        FROM bid_checklist bc
        LEFT JOIN users u ON u.user_id = bc.checked_by
        WHERE bc.bid_lot_id = ?
          AND bc.template_item_id IN (
              SELECT id FROM checklist_templates
              WHERE procurement_type = ? AND checklist_type = ? AND is_active = 1
          )
        ORDER BY (
            SELECT display_order FROM checklist_templates WHERE id = bc.template_item_id
        ) ASC
    ");
    $rows->bind_param("iss", $bid_lot_id, $procurement_type, $checklist_type);
    $rows->execute();
    $items = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
    $rows->close();

    // Merge template metadata into each saved row
    foreach ($items as &$item) {
        $tpl_entry = $tpl_map[$item['template_item_id']] ?? [];
        $item['is_required']  = (bool)($tpl_entry['is_required']  ?? true);
        $item['description']  = $tpl_entry['description'] ?? '';
    }
    unset($item);

    echo json_encode([
        'success'          => true,
        'items'            => $items,
        'procurement_type' => $procurement_type,
        'checklist_type'   => $checklist_type,
    ]); exit();
}

// ── Save a single checklist item ───────────────────────────────────────────
if ($action === 'save_checklist_item') {
    $checklist_id = (int)($_POST['checklist_id'] ?? 0);
    $result       = $_POST['result']              ?? '';
    $remarks      = trim($_POST['remarks']        ?? '');
    $session_id   = (int)($_POST['session_id']   ?? 0);

    $allowed_results = ['pending', 'present', 'missing', 'not_applicable'];
    if ($checklist_id <= 0 || !in_array($result, $allowed_results) || $session_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
    }

    // Verify the checklist row belongs to this session's procurement and get context
    $chk = $conn->prepare("
        SELECT bc.id, bc.bid_lot_id,
               bl.eligibility_status,
               ct.checklist_type
        FROM bid_checklist bc
        JOIN bid_lots bl    ON bl.id = bc.bid_lot_id
        JOIN checklist_templates ct ON ct.id = bc.template_item_id
        JOIN lots l         ON l.id = bl.lot_id
        JOIN bid_opening_sessions bos ON bos.procurement_id = l.procurement_id
        WHERE bc.id = ? AND bos.id = ?
        LIMIT 1
    ");
    $chk->bind_param("ii", $checklist_id, $session_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Checklist item not found in this session.']); exit();
    }

    // Financial checklist: disqualified bidders cannot be evaluated
    if ($row['checklist_type'] === 'financial' && $row['eligibility_status'] === 'disqualified') {
        echo json_encode(['success' => false, 'message' => 'Cannot evaluate financial checklist for a disqualified bidder.']); exit();
    }

    // Use server-side user_id — never trust JS
    $checked_at = ($result !== 'pending') ? date('Y-m-d H:i:s') : null;
    $checked_by = ($result !== 'pending') ? $user_id : null;

    $upd = $conn->prepare("
        UPDATE bid_checklist
        SET result = ?, remarks = ?, checked_by = ?, checked_at = ?
        WHERE id = ?
    ");
    $upd->bind_param("ssisi", $result, $remarks, $checked_by, $checked_at, $checklist_id);
    $upd->execute();
    $upd->close();

    pusher_trigger($session_id, 'checklist_updated', [
        'checklist_id' => $checklist_id,
        'bid_lot_id'   => (int)$row['bid_lot_id'],
        'result'       => $result,
    ]);

    echo json_encode(['success' => true]); exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
