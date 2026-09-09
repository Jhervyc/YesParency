<?php
/**
 * admin/checklist_pdf.php
 * Generates the official BAC Bid Opening Checklist PDF using the form layout
 * from bid_checklist_forms.html.  One eligibility page + one financial page
 * per bidder (financial skipped for disqualified bidders).
 * Only SECRETARIAT or superadmin may access.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// ── Auth ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    http_response_code(403); exit('Forbidden');
}
if ($_SESSION['role'] !== 'superadmin') {
    $at = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    $at->bind_param("i", $_SESSION['user_id']);
    $at->execute();
    $at_row = $at->get_result()->fetch_assoc();
    $at->close();
    if (($at_row['admin_type'] ?? '') !== 'SECRETARIAT') {
        http_response_code(403); exit('Forbidden');
    }
}

$session_id = (int)($_GET['session'] ?? 0);
if ($session_id <= 0) { http_response_code(400); exit('Invalid session.'); }

// ── Session ────────────────────────────────────────────────────────────────
$ss = $conn->prepare("
    SELECT bos.id, bos.status, bos.started_at, bos.ended_at,
           p.id AS proc_id, p.title AS proc_title, p.philgeps_ref_no,
           p.abc AS proc_abc, p.procurement_mode,
           COALESCE(p.procurement_type, 'goods_services') AS procurement_type
    FROM bid_opening_sessions bos
    JOIN procurements p ON p.id = bos.procurement_id
    WHERE bos.id = ? LIMIT 1
");
$ss->bind_param("i", $session_id);
$ss->execute();
$session = $ss->get_result()->fetch_assoc();
$ss->close();

if (!$session || $session['status'] !== 'ended') {
    http_response_code(403); exit('Session is not yet concluded.');
}

$proc_type  = $session['procurement_type']; // 'goods_services' | 'infrastructure'
$proc_title = $session['proc_title'];
$proc_abc   = '₱' . number_format((float)$session['proc_abc'], 2);
$open_date  = $session['started_at'] ? date('F j, Y', strtotime($session['started_at'])) : date('F j, Y');

// ── Invited committee members by role ─────────────────────────────────────
$inv = $conn->prepare("
    SELECT u.firstname, u.lastname, ar.admin_type
    FROM bid_session_invited bsi
    JOIN users u ON u.user_id = bsi.user_id
    LEFT JOIN admin_roles ar ON ar.user_id = bsi.user_id
    WHERE bsi.bid_session_id = ?
    ORDER BY ar.admin_type ASC, u.lastname ASC
");
$inv->bind_param("i", $session_id);
$inv->execute();
$invited = $inv->get_result()->fetch_all(MYSQLI_ASSOC);
$inv->close();

$bac_members = [];
$twg_members = [];
foreach ($invited as $m) {
    $name = strtoupper($m['firstname'] . ' ' . $m['lastname']);
    if ($m['admin_type'] === 'BAC')       $bac_members[] = $name;
    elseif ($m['admin_type'] === 'TWG')   $twg_members[] = $name;
}

// Assign BAC roles: first = Chairperson, second = Vice, rest = Members
$bac_chair = $bac_members[0] ?? '';
$bac_vice  = $bac_members[1] ?? '';
$bac_rest  = array_slice($bac_members, 2); // up to 4 members

// ── Lots ──────────────────────────────────────────────────────────────────
$lots_stmt = $conn->prepare("
    SELECT l.id, l.lot_number, l.lot_title, l.abc, l.status
    FROM lots l
    WHERE l.procurement_id = ?
    ORDER BY l.lot_number ASC
");
$lots_stmt->bind_param("i", $session['proc_id']);
$lots_stmt->execute();
$lots = $lots_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lots_stmt->close();

// ── Helpers ───────────────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Returns ✓ if result maps to passed/present, ✗ if failed/missing, blank otherwise
function pass_mark(string $result): string {
    return match($result) {
        'present'        => '&#10003;',   // ✓
        'missing'        => '&#10007;',   // ✗
        'not_applicable' => 'N/A',
        default          => '',
    };
}
function fail_mark(string $result): string {
    return match($result) {
        'missing' => '&#10007;',
        default   => '',
    };
}

// Render a checklist table header (same for both form types)
function tbl_head(): string {
    return '
    <colgroup>
      <col style="width:20px"><col style="width:20px"><col style="width:20px">
      <col style="width:20px"><col style="width:20px"><col style="width:20px">
      <col><col style="width:46px"><col style="width:46px">
    </colgroup>
    <thead>
      <tr>
        <th class="rot"><span>C</span><span>H</span><span>A</span><span>I</span><span>R</span><span>P</span><span>E</span><span>R</span><span>S</span><span>O</span><span>N</span></th>
        <th class="rot"><span>V</span><span>I</span><span>C</span><span>E</span></th>
        <th class="rot"><span>M</span><span>E</span><span>M</span><span>B</span><span>E</span><span>R</span></th>
        <th class="rot"><span>M</span><span>E</span><span>M</span><span>B</span><span>E</span><span>R</span></th>
        <th class="rot"><span>M</span><span>E</span><span>M</span><span>B</span><span>E</span><span>R</span></th>
        <th class="rot"><span>M</span><span>E</span><span>M</span><span>B</span><span>E</span><span>R</span></th>
        <th class="req">REQUIREMENTS</th>
        <th class="pf">Passed</th>
        <th class="pf">Failed</th>
      </tr>
    </thead>';
}

// Render 6 BAC-signature cells (filled with ✓/✗ based on whether that member signed)
// For simplicity: all BAC members who signed get ✓ in their column
function bac_cells(string $result, array $bac_members): string {
    $mark = ($result === 'present') ? '&#10003;' : (($result === 'missing') ? '&#10007;' : '');
    // Fill all 6 columns: chair, vice, member×4
    $cells = '';
    for ($i = 0; $i < 6; $i++) {
        // Only fill if that position has a member
        $has = ($i === 0 && $bac_members['chair'])
            || ($i === 1 && $bac_members['vice'])
            || ($i >= 2 && isset($bac_members['rest'][$i - 2]));
        $cells .= '<td class="chk">' . ($has ? $mark : '') . '</td>';
    }
    return $cells;
}

// Render signatory block (identical structure for both form types, infra has extra TWG + end user)
function sig_block(array $bac_members, array $twg_members, bool $is_infra): string {
    $chair = $bac_members[0] ?? '';
    $vice  = $bac_members[1] ?? '';
    $rest  = array_slice($bac_members, 2);

    $html  = '<div class="checked-by">CHECKED BY:</div>';
    $html .= '<div class="sig-single"><div class="name">' . h($chair) . '</div><div class="role">BAC Chairperson</div></div>';
    $html .= '<div class="sig-single"><div class="name">' . h($vice)  . '</div><div class="role">Vice-Chairperson</div></div>';

    // BAC members in pairs
    for ($i = 0; $i < count($rest); $i += 2) {
        $a = $rest[$i]     ?? '';
        $b = $rest[$i + 1] ?? '';
        if ($a && $b) {
            $html .= '<div class="sig-row">
              <div class="sig"><div class="name">' . h($a) . '</div><div class="role">BAC Member</div></div>
              <div class="sig"><div class="name">' . h($b) . '</div><div class="role">BAC Member</div></div>
            </div>';
        } elseif ($a) {
            $html .= '<div class="sig-single"><div class="name">' . h($a) . '</div><div class="role">BAC Member</div></div>';
        }
    }

    // TWG members in pairs
    for ($i = 0; $i < count($twg_members); $i += 2) {
        $a = $twg_members[$i]     ?? '';
        $b = $twg_members[$i + 1] ?? '';
        if ($a && $b) {
            $html .= '<div class="sig-row">
              <div class="sig"><div class="name">' . h($a) . '</div><div class="role">TWG Member</div></div>
              <div class="sig"><div class="name">' . h($b) . '</div><div class="role">TWG Member</div></div>
            </div>';
        } elseif ($a) {
            $html .= '<div class="sig-single"><div class="name">' . h($a) . '</div><div class="role">TWG Member</div></div>';
        }
    }

    if ($is_infra) {
        $html .= '<div class="sig-single"><div class="name"></div><div class="role">End User</div></div>';
    }

    return $html;
}

$is_infra = ($proc_type === 'infrastructure');

// ── Build per-bidder pages ─────────────────────────────────────────────────
$bac_sig_data = array_merge([$bac_chair, $bac_vice], $bac_rest);

$pages_html = '';

foreach ($lots as $lot) {
    $lot_abc   = '₱' . number_format((float)$lot['abc'], 2);
    $lot_label = 'Lot ' . $lot['lot_number'] . ($lot['lot_title'] ? ' – ' . $lot['lot_title'] : '');

    // Get bid_lots for this lot
    $bl_stmt = $conn->prepare("
        SELECT bl.id AS bid_lot_id, bl.eligibility_status, bl.financial_status,
               b.id AS bid_id,
               COALESCE(bp.business_name, CONCAT(u.firstname,' ',u.lastname)) AS business_name,
               b.submission_date
        FROM bid_lots bl
        JOIN bids b       ON b.id = bl.bid_id
        JOIN users u      ON u.user_id = b.bidder_id
        LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
        WHERE bl.lot_id = ?
        ORDER BY b.submission_date ASC
    ");
    $bl_stmt->bind_param("i", $lot['id']);
    $bl_stmt->execute();
    $bid_lots = $bl_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $bl_stmt->close();

    foreach ($bid_lots as $bl) {
        $bidder_name = strtoupper($bl['business_name']);
        $bid_lot_id  = (int)$bl['bid_lot_id'];

        // ── ELIGIBILITY PAGE ─────────────────────────────────────────────
        $elig_stmt = $conn->prepare("
            SELECT bc.item_name, bc.result, bc.remarks
            FROM bid_checklist bc
            JOIN checklist_templates ct ON ct.id = bc.template_item_id
            WHERE bc.bid_lot_id = ? AND ct.checklist_type = 'eligibility'
            ORDER BY ct.display_order ASC
        ");
        $elig_stmt->bind_param("i", $bid_lot_id);
        $elig_stmt->execute();
        $elig_items = $elig_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $elig_stmt->close();

        $elig_verdict = ($bl['eligibility_status'] === 'eligible')     ? '(&#10003;) Eligible &nbsp;&nbsp; ( ) Ineligible'
                     : (($bl['eligibility_status'] === 'disqualified') ? '( ) Eligible &nbsp;&nbsp; (&#10003;) Ineligible'
                     : '( ) Eligible &nbsp;&nbsp; ( ) Ineligible');

        // Build eligibility table body from saved bid_checklist rows
        $elig_rows = '';
        if ($elig_items) {
            foreach ($elig_items as $item) {
                $bac_cols = '';
                for ($i = 0; $i < 6; $i++) {
                    $has = ($i < count($bac_sig_data) && $bac_sig_data[$i]);
                    $mark = $has ? pass_mark($item['result']) : '';
                    $bac_cols .= '<td class="chk">' . $mark . '</td>';
                }
                $elig_rows .= '<tr>' . $bac_cols
                    . '<td>' . h($item['item_name']) . '</td>'
                    . '<td class="chk">' . pass_mark($item['result']) . '</td>'
                    . '<td class="chk">' . (($item['result'] === 'missing') ? '&#10007;' : '') . '</td>'
                    . '</tr>';
            }
        } else {
            // Fallback: use the fixed form rows from the HTML template (no DB records yet)
            if ($is_infra) {
                $elig_rows = '
                <tr><td colspan="6"></td><td class="section-hdr">Technical and Financial Components</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>a) PhilGEPS Certificate of Registration (Platinum Membership);</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>b) PCAB License and Registration;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>c) Statement of all ongoing government and private contracts;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>d) Statement of the Bidder\'s SLCC;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>e) NFCC computation;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>f) Joint Venture Agreement (JVA), if applicable;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>g) Bid Security;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>h) Project Requirements (organizational chart, personnel list, equipment list, Omnibus Sworn Statement);</td><td></td><td></td></tr>';
            } else {
                $elig_rows = '
                <tr><td colspan="6"></td><td class="section-hdr">Technical and Financial Components</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>i) PhilGEPS Certificate of Registration (Platinum Membership);</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>ii) Statement of SLCC;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>iii) NFCC Computation or committed Line of Credit (LoC);</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>iv) Statement of all ongoing government and private contracts;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>v) JVA, if applicable;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>vi) Bid Security;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>vii) Technical Specifications;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>viii) Omnibus Sworn Statement;</td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td>ix) For foreign Bidders, certification of reciprocal rights.</td><td></td><td></td></tr>';
            }
        }

        $pages_html .= '
        <div class="page">
          <div class="header">
            <div class="country">Republic of the Philippines</div>
            <div class="univ">Southern Luzon State University</div>
            <div class="bac">BIDS AND AWARDS COMMITTEE</div>
            <div class="place">Lucban, Quezon</div>
          </div>
          <div class="fields">
            <div>PROJECT: <span class="line">' . h($proc_title) . ' — ' . h($lot_label) . '</span></div>
            <div>APPROVED BUDGET: <span class="line">' . h($lot_abc) . '</span></div>
            <div>NAME OF THE BIDDER: <span class="line">' . h($bidder_name) . '</span></div>
            <div>DATE: <span class="line">' . h($open_date) . '</span></div>
          </div>
          <div class="title">ELIGIBILITY REQUIREMENTS FOR BIDDERS</div>
          <div class="subtitle">(Envelope No. 1)</div>
          <table class="form">' . tbl_head() . '<tbody>' . $elig_rows . '</tbody></table>
          <p class="note">NOTE: Any missing document in the above-mentioned checklist is a ground for outright rejection of the bid.</p>
          <p class="remarks">Remarks: &nbsp;' . $elig_verdict . '</p>
          ' . sig_block($bac_sig_data, $twg_members, $is_infra) . '
        </div>';

        // ── FINANCIAL PAGE (skip if disqualified) ─────────────────────────
        if ($bl['eligibility_status'] === 'disqualified') continue;

        $fin_stmt = $conn->prepare("
            SELECT bc.item_name, bc.result, bc.remarks
            FROM bid_checklist bc
            JOIN checklist_templates ct ON ct.id = bc.template_item_id
            WHERE bc.bid_lot_id = ? AND ct.checklist_type = 'financial'
            ORDER BY ct.display_order ASC
        ");
        $fin_stmt->bind_param("i", $bid_lot_id);
        $fin_stmt->execute();
        $fin_items = $fin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fin_stmt->close();

        $fin_verdict = ($bl['financial_status'] === 'qualified')     ? '(&#10003;) Comply &nbsp;&nbsp; ( ) Does Not-Comply'
                    : (($bl['financial_status'] === 'non_compliant') ? '( ) Comply &nbsp;&nbsp; (&#10003;) Does Not-Comply'
                    : '( ) Comply &nbsp;&nbsp; ( ) Does Not-Comply');

        $fin_rows = '';
        if ($fin_items) {
            foreach ($fin_items as $item) {
                $bac_cols = '';
                for ($i = 0; $i < 6; $i++) {
                    $has  = ($i < count($bac_sig_data) && $bac_sig_data[$i]);
                    $mark = $has ? pass_mark($item['result']) : '';
                    $bac_cols .= '<td class="chk">' . $mark . '</td>';
                }
                $fin_rows .= '<tr>' . $bac_cols
                    . '<td>' . h($item['item_name']) . '</td>'
                    . '<td class="chk">' . pass_mark($item['result']) . '</td>'
                    . '<td class="chk">' . (($item['result'] === 'missing') ? '&#10007;' : '') . '</td>'
                    . '</tr>';
            }
        } else {
            $fin_rows = $is_infra
                ? '<tr><td colspan="6"></td><td>Financial Bid Form, which includes bid prices and the bill of quantities, in accordance with ITB Clauses 13.1</td><td></td><td></td></tr>'
                : '<tr><td colspan="6"></td><td>a) Bid Form which includes the Bid price;</td><td></td><td></td></tr>
                   <tr><td colspan="6"></td><td>b) Price Schedules in accordance with ITB Clause 13.1;</td><td></td><td></td></tr>
                   <tr><td colspan="6"></td><td>c) Certificate of Domestic Preference, if applicable.</td><td></td><td></td></tr>';
        }

        // Awarded amount if available
        $award_row = '';
        $aw = $conn->prepare("
            SELECT a.awarded_amount FROM awards a
            JOIN bid_lots bl2 ON bl2.id = a.bid_lot_id
            WHERE bl2.id = ? LIMIT 1
        ");
        $aw->bind_param("i", $bid_lot_id);
        $aw->execute();
        $aw_row = $aw->get_result()->fetch_assoc();
        $aw->close();
        $amount_of_bid = $aw_row ? '₱' . number_format((float)$aw_row['awarded_amount'], 2) : '';

        $pages_html .= '
        <div class="page">
          <div class="header">
            <div class="country">Republic of the Philippines</div>
            <div class="univ">Southern Luzon State University</div>
            <div class="bac">BIDS AND AWARDS COMMITTEE</div>
            <div class="place">Lucban, Quezon</div>
          </div>
          <div class="fields">
            <div>PROJECT: <span class="line">' . h($proc_title) . ' — ' . h($lot_label) . '</span></div>
            <div>APPROVED BUDGET: <span class="line">' . h($lot_abc) . '</span></div>
            <div>NAME OF THE BIDDER: <span class="line">' . h($bidder_name) . '</span></div>
            <div>AMOUNT OF BID: <span class="line">' . h($amount_of_bid) . '</span></div>
            <div>DATE: <span class="line">' . h($open_date) . '</span></div>
          </div>
          <div class="title">THE FINANCIAL COMPONENTS</div>
          <div class="subtitle">(2nd Envelope)</div>
          <table class="form">' . tbl_head() . '<tbody>' . $fin_rows . '</tbody></table>
          <p class="remarks">Remarks: &nbsp;' . $fin_verdict . '</p>
          ' . sig_block($bac_sig_data, $twg_members, $is_infra) . '
        </div>';
    }
}

// ── Full HTML document ────────────────────────────────────────────────────
$full_html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color:#000; margin:0; padding:0; }
  .page { max-width:780px; margin:0 auto; padding:20px 24px; background:#fff; page-break-after:always; }
  .header { text-align:center; line-height:1.4; margin-bottom:18px; }
  .header .country { font-size:11.5px; }
  .header .univ, .header .bac { font-weight:bold; font-size:12px; }
  .header .place { font-size:11.5px; }
  .fields { margin-bottom:14px; font-size:11.5px; }
  .fields div { margin-bottom:10px; }
  .fields .line { display:inline-block; border-bottom:1px solid #000; min-width:300px; margin-left:5px; }
  .title { text-align:center; font-weight:bold; text-decoration:underline; font-size:12.5px; margin:14px 0 2px; }
  .subtitle { text-align:center; font-weight:bold; font-size:12.5px; margin:0 0 10px; }
  table.form { width:100%; border-collapse:collapse; margin-bottom:8px; }
  table.form th, table.form td { border:1px solid #000; padding:3px 5px; vertical-align:top; font-size:10.5px; }
  th.rot { width:18px; text-align:center; font-weight:bold; font-size:8px; padding:2px 1px; letter-spacing:1px; }
  th.rot span { display:block; line-height:1.05; }
  th.req { text-align:left; }
  th.pf { width:42px; text-align:center; }
  td.chk { text-align:center; }
  td.section-hdr { font-weight:bold; }
  .note { font-style:italic; font-size:10.5px; margin:6px 0 10px; }
  .remarks { font-size:11.5px; font-weight:bold; margin-bottom:18px; }
  .checked-by { text-align:center; font-weight:bold; margin-bottom:14px; font-size:11.5px; }
  .sig-row { display:flex; justify-content:center; gap:50px; margin-bottom:10px; text-align:center; }
  .sig { min-width:180px; }
  .sig .name, .sig-single .name { font-weight:bold; font-size:11.5px;
    border-top:1px solid #000; padding-top:2px; display:inline-block; min-width:200px; }
  .sig .role, .sig-single .role { font-size:10.5px; }
  .sig-single { text-align:center; margin-bottom:10px; }
</style>
</head>
<body>
' . $pages_html . '
</body></html>';

// ── Render PDF ─────────────────────────────────────────────────────────────
$opts = new Options();
$opts->set('defaultFont', 'DejaVu Sans');
$opts->set('isRemoteEnabled', false);
$opts->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($opts);
$dompdf->loadHtml($full_html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'BidChecklist_Session' . $session_id . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
