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

    $stmt = $conn->prepare("SELECT id, procurement_id, status, current_lot_id, started_at, ended_at FROM bid_opening_sessions WHERE id = ?");
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

    if ($bid_id <= 0 || $lot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit();
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
        $upd = $conn->prepare("UPDATE bid_opening_sessions SET status = ? WHERE id = ?");
        $upd->bind_param("si", $phase, $session_id);
    }
    $upd->execute();
    $upd->close();

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

    echo json_encode(['success' => true, 'new_status' => 'started']); exit();
}

// ── Update current lot for session ─────────────────────────────────────────
if ($action === 'set_current_lot') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $lot_id     = (int)($_POST['lot_id']     ?? 0);

    if ($session_id > 0) {
        if ($lot_id > 0) {
            $upd = $conn->prepare("UPDATE bid_opening_sessions SET current_lot_id = ? WHERE id = ?");
            $upd->bind_param("ii", $lot_id, $session_id);
        } else {
            $upd = $conn->prepare("UPDATE bid_opening_sessions SET current_lot_id = NULL WHERE id = ?");
            $upd->bind_param("i", $session_id);
        }
        $upd->execute();
        $upd->close();
    }
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

    echo json_encode(['success' => true, 'new_status' => 'ended']); exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
