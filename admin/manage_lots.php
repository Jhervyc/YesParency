<?php 
    include("utils/protect-page.php");
    include("utils/protect-secretariat.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);

    if ($procurement_id === 0) {
        header("Location: procurement.php");
        exit();
    }

    // ------------------------------------
    // 1. Fetch Procurement Details
    // ------------------------------------
    $stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $procurement_id);
    $stmt->execute();
    $proc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proc) {
        $_SESSION['alert_error'] = "Procurement project not found.";
        header("Location: procurement.php");
        exit();
    }

    // ------------------------------------
    // 2. ADD LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_lot'])) {
        $lot_number  = intval($_POST['lot_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $abc         = floatval($_POST['abc'] ?? 0);

        if ($lot_number <= 0) {
            $_SESSION['alert_error'] = "Please provide a valid Lot Number greater than 0.";
        } elseif (empty($title)) {
            $_SESSION['alert_error'] = "Lot Title is required.";
        } elseif ($abc <= 0) {
            $_SESSION['alert_error'] = "Approved Budget for the Lot (ABC) must be greater than 0.";
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? LIMIT 1");
            $check_stmt->bind_param("ii", $procurement_id, $lot_number);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "Lot Number #{$lot_number} already exists in this procurement!";
            } else {
                $check_stmt->close();
                $stmt = $conn->prepare("
                    INSERT INTO lots (procurement_id, lot_number, lot_title, description, abc)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissd", $procurement_id, $lot_number, $title, $description, $abc);
                if ($stmt->execute()) {
                    $new_lot_id = $conn->insert_id;
                    audit_log(
                        $conn,
                        'LOT_CREATED',
                        'lots',
                        $new_lot_id,
                        "Added Lot #{$lot_number}: {$title} to procurement #{$procurement_id}",
                        null,
                        [
                            'procurement_id' => $procurement_id,
                            'lot_number' => $lot_number,
                            'lot_title' => $title,
                            'description' => $description,
                            'abc' => $abc
                        ]
                    );
                    $_SESSION['alert_success'] = "Lot #{$lot_number} ({$title}) was added successfully.";
                } else {
                    $_SESSION['alert_error'] = "Failed to add lot. Please try again.";
                }
                $stmt->close();
            }
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 3. UPDATE LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_lot'])) {
        $lot_id      = intval($_POST['lot_id'] ?? 0);
        $lot_number  = intval($_POST['lot_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $abc         = floatval($_POST['abc'] ?? 0);

        if ($lot_id <= 0 || $lot_number <= 0) {
            $_SESSION['alert_error'] = "Invalid lot record or lot number.";
        } elseif (empty($title)) {
            $_SESSION['alert_error'] = "Lot Title is required.";
        } elseif ($abc <= 0) {
            $_SESSION['alert_error'] = "Approved Budget for the Lot (ABC) must be greater than 0.";
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? AND id != ? LIMIT 1");
            $check_stmt->bind_param("iii", $procurement_id, $lot_number, $lot_id);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "Lot Number #{$lot_number} is already assigned to another lot!";
            } else {
                $check_stmt->close();

                // Snapshot before update
                $snap = $conn->prepare("SELECT lot_number, lot_title, description, abc, status FROM lots WHERE id = ?");
                $snap->bind_param("i", $lot_id);
                $snap->execute();
                $old_lot = $snap->get_result()->fetch_assoc();
                $snap->close();

                $stmt = $conn->prepare("
                    UPDATE lots 
                    SET lot_number = ?, lot_title = ?, description = ?, abc = ? 
                    WHERE id = ? AND procurement_id = ?
                ");
                $stmt->bind_param("issdii", $lot_number, $title, $description, $abc, $lot_id, $procurement_id);
                if ($stmt->execute()) {
                    audit_log(
                        $conn,
                        'LOT_UPDATED',
                        'lots',
                        $lot_id,
                        "Updated Lot #{$lot_number}: {$title}",
                        [
                            'lot_number' => (int)($old_lot['lot_number'] ?? 0),
                            'lot_title' => $old_lot['lot_title'] ?? '',
                            'description' => $old_lot['description'] ?? '',
                            'abc' => (float)($old_lot['abc'] ?? 0)
                        ],
                        [
                            'lot_number' => $lot_number,
                            'lot_title' => $title,
                            'description' => $description,
                            'abc' => $abc
                        ]
                    );
                    $_SESSION['alert_success'] = "Lot #{$lot_number} details have been updated.";
                } else {
                    $_SESSION['alert_error'] = "Failed to update lot details.";
                }
                $stmt->close();
            }
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 4. DELETE LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_lot'])) {
        $lot_id = intval($_POST['lot_id'] ?? 0);

        if ($lot_id > 0) {
            // Snapshot before deletion
            $snap = $conn->prepare("SELECT lot_number, lot_title, description, abc, status FROM lots WHERE id = ?");
            $snap->bind_param("i", $lot_id);
            $snap->execute();
            $old_lot = $snap->get_result()->fetch_assoc();
            $snap->close();

            $stmt = $conn->prepare("DELETE FROM lots WHERE id = ? AND procurement_id = ?");
            $stmt->bind_param("ii", $lot_id, $procurement_id);
            if ($stmt->execute()) {
                $ltitle = $old_lot['lot_title'] ?? "#$lot_id";
                $lnum = $old_lot['lot_number'] ?? '';
                audit_log(
                    $conn,
                    'LOT_DELETED',
                    'lots',
                    $lot_id,
                    "Deleted Lot #{$lnum} ({$ltitle}) from procurement #{$procurement_id}",
                    $old_lot,
                    null
                );
                $_SESSION['alert_success'] = "Lot was removed successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to delete lot.";
            }
            $stmt->close();
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 5. Fetch Existing Lots & Statistics
    // ------------------------------------
    $lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
    $lot_stmt->bind_param("i", $procurement_id);
    $lot_stmt->execute();
    $lots_res = $lot_stmt->get_result();

    $lots = [];
    $total_lots_abc = 0.0;
    $max_lot_number = 0;

    while ($l = $lots_res->fetch_assoc()) {
        $total_lots_abc += (float)$l['abc'];
        if ((int)$l['lot_number'] > $max_lot_number) {
            $max_lot_number = (int)$l['lot_number'];
        }
        $lots[] = $l;
    }
    $lot_stmt->close();

    $proc_abc = (float)$proc['abc'];
    $remaining_abc = $proc_abc - $total_lots_abc;
    $lots_count = count($lots);
    $budget_pct = $proc_abc > 0 ? min(100, round(($total_lots_abc / $proc_abc) * 100, 1)) : 0;
    $suggested_next_lot = $max_lot_number + 1;

    // Status Badge Helpers
    $p_status = strtolower($proc['status'] ?? 'draft');
    $status_label = ucfirst($p_status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lots: <?= htmlspecialchars($proc['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Base Layout & Utility ── */
        * {
            box-sizing: border-box;
        }

        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ── Breadcrumb & Top Bar ── */
        .vp-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .vp-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #88968d;
            font-weight: 600;
        }

        .vp-breadcrumbs a {
            color: #1f7a3d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color .15s;
        }

        .vp-breadcrumbs a:hover {
            text-decoration: underline;
            color: #06251b;
        }

        .vp-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #06251b;
            font-size: 12.5px;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #eaeeec;
            padding: 8px 16px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
            transform: translateY(-1px);
        }

        /* ── Hero Banner ── */
        .vp-hero-card {
            background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #14593f 100%);
            border-radius: 20px;
            padding: 26px 30px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(6, 37, 27, 0.16);
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .vp-hero-card::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.15) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .vp-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .vp-hero-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hero-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: .3px;
        }

        .hero-pill.ref {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all .15s;
        }

        .hero-pill.ref:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .hero-pill.mode {
            background: rgba(255, 193, 7, 0.2);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
        }

        .hero-pill.status {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
        }

        .vp-hero-title {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.35;
            margin-bottom: 20px;
            letter-spacing: -0.3px;
        }

        /* Hero Key Metrics */
        .vp-hero-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .vp-hero-metric-item {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 12px 16px;
        }

        .vp-hero-metric-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #d1e5db;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .vp-hero-metric-lbl i {
            color: #ffc107;
        }

        .vp-hero-metric-val {
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            font-family: 'Space Grotesk', sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .vp-hero-metric-val.gold {
            color: #ffc107;
        }

        /* ── Modern Stat Ring Cards ── */
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-ring-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(16,36,26,.02);
            transition: all .2s ease;
        }

        .stat-ring-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16,36,26,.06);
            border-color: #d2ded7;
        }

        .stat-ring-wrap {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-ring-wrap.green { background: #eef7f1; color: #1f7a3d; }
        .stat-ring-wrap.gold  { background: #fff8e6; color: #b78103; }
        .stat-ring-wrap.blue  { background: #eef4ff; color: #2563eb; }
        .stat-ring-wrap.red   { background: #fdf2f2; color: #dc2626; }

        .stat-ring-info {
            min-width: 0;
        }

        .stat-ring-num {
            font-size: 19px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1.1;
        }

        .stat-ring-lbl {
            font-size: 11.5px;
            font-weight: 600;
            color: #718278;
            margin-top: 3px;
        }

        /* ── Two-Column Layout (1.7fr + 1fr) ── */
        .vp-grid-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 30px;
            min-width: 0;
            max-width: 100%;
        }

        .vp-left-col {
            min-width: 0;
            max-width: 100%;
        }

        .vp-right-col {
            min-width: 0;
            max-width: 100%;
            position: sticky;
            top: 20px;
        }

        @media (max-width: 1060px) {
            .vp-grid-layout {
                grid-template-columns: 1fr;
            }
            .vp-right-col {
                position: static;
            }
        }

        /* ── Card Containers ── */
        .vp-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .vp-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
            gap: 10px;
            flex-wrap: wrap;
        }

        .vp-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vp-card-count {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .vp-card-body {
            padding: 20px;
        }

        /* ── Allocation Visual Progress Meter ── */
        .allocation-meter-card {
            background: #fbfdfc;
            border: 1px solid #eaeeec;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .alloc-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
            margin-bottom: 8px;
        }

        .alloc-progress-track {
            width: 100%;
            height: 10px;
            background: #eaeeec;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .alloc-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1f7a3d 0%, #2ecc71 100%);
            border-radius: 10px;
            transition: width .4s ease;
        }

        .alloc-progress-fill.overbudget {
            background: linear-gradient(90deg, #e53e3e 0%, #fc8181 100%);
        }

        .alloc-sub-text {
            font-size: 11px;
            color: #63736a;
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ── Lots List Table/Items ── */
        .lots-table-container {
            width: 100%;
            border-collapse: collapse;
        }

        .lot-row-card {
            background: #ffffff;
            border: 1px solid #e7ede9;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            transition: all .2s ease;
        }

        .lot-row-card:hover {
            border-color: #bad3c5;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.04);
            transform: translateY(-1px);
        }

        .lot-badge-num {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #06251b;
            color: #ffc107;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(6, 37, 27, 0.12);
        }

        .lot-main-info {
            flex: 1 1 auto;
            min-width: 0;
        }

        .lot-title-heading {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .lot-desc-text {
            font-size: 12px;
            color: #63736a;
            line-height: 1.45;
            margin-bottom: 8px;
        }

        .lot-meta-tags {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .lot-abc-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            padding: 3px 10px;
            border-radius: 6px;
            background: #eef7f1;
            color: #1f7a3d;
            border: 1px solid #d2ebd9;
        }

        .lot-action-btns {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .btn-lot-action {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s ease;
            border: 1px solid transparent;
        }

        .btn-lot-action.edit {
            background: #f4faf6;
            color: #1f7a3d;
            border-color: #cce7d6;
        }

        .btn-lot-action.edit:hover {
            background: #1f7a3d;
            color: #ffffff;
            border-color: #1f7a3d;
        }

        .btn-lot-action.delete {
            background: #fdf2f2;
            color: #dc2626;
            border-color: #fbd5d5;
        }

        .btn-lot-action.delete:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }

        /* ── Input Form Elements ── */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
            min-width: 0;
        }

        .field-label {
            font-size: 12px;
            font-weight: 700;
            color: #2b3a31;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .field-label .req {
            color: #e53935;
            font-weight: 800;
        }

        .field-hint {
            font-size: 11px;
            color: #88968d;
            font-weight: 400;
        }

        .input-icon-box {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            min-width: 0;
        }

        .input-icon-box i.input-icon {
            position: absolute;
            left: 14px;
            color: #88968d;
            font-size: 15px;
            pointer-events: none;
            transition: color .15s;
            z-index: 1;
        }

        .input-icon-box input,
        .input-icon-box select,
        .input-icon-box textarea {
            width: 100%;
            min-width: 0;
            background: #ffffff;
            border: 1.5px solid #dce4e0;
            border-radius: 10px;
            padding: 10px 14px 10px 40px;
            font-size: 13px;
            font-family: inherit;
            color: #06251b;
            font-weight: 500;
            transition: all .2s ease;
            outline: none;
            box-sizing: border-box;
        }

        .input-icon-box textarea {
            padding: 12px 14px 12px 40px;
            resize: vertical;
            min-height: 80px;
            line-height: 1.5;
        }

        .input-icon-box textarea + i.input-icon {
            top: 14px;
        }

        .input-icon-box input:focus,
        .input-icon-box textarea:focus {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 3px rgba(31, 122, 61, 0.12);
            background: #fafcfb;
        }

        .input-icon-box input:focus + i.input-icon,
        .input-icon-box textarea:focus + i.input-icon {
            color: #1f7a3d;
        }

        .currency-preview-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            margin-top: 6px;
            border: 1px solid #d3ebd9;
            transition: all .2s;
        }

        /* ── Submit Buttons ── */
        .btn-submit-proposal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #06251b;
            color: #ffc107;
            font-size: 13.5px;
            font-weight: 800;
            padding: 12px 22px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 4px 14px rgba(6, 37, 27, 0.18);
            width: 100%;
        }

        .btn-submit-proposal:hover {
            background: #144937;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6, 37, 27, 0.26);
        }

        /* ── Guidelines Timeline ── */
        .timeline-guide {
            position: relative;
            padding-left: 20px;
        }

        .timeline-guide::before {
            content: '';
            position: absolute;
            top: 6px;
            bottom: 6px;
            left: 5px;
            width: 2px;
            background: #eaeeec;
        }

        .timeline-guide-step {
            position: relative;
            margin-bottom: 14px;
        }

        .timeline-guide-step:last-child {
            margin-bottom: 0;
        }

        .timeline-guide-dot {
            position: absolute;
            left: -19px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ffffff;
            border: 2.5px solid #1f7a3d;
        }

        .timeline-guide-title {
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
        }

        .timeline-guide-sub {
            font-size: 11px;
            color: #88968d;
            margin-top: 1px;
            line-height: 1.4;
        }

        /* ── Empty State ── */
        .vp-empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #fafcfb;
            border: 1.5px dashed #d5ded9;
            border-radius: 16px;
        }

        .vp-empty-state i {
            font-size: 40px;
            color: #88968d;
            display: block;
            margin-bottom: 10px;
        }

        .vp-empty-state h4 {
            font-size: 15px;
            font-weight: 800;
            color: #06251b;
            margin: 0 0 6px;
        }

        .vp-empty-state p {
            font-size: 12px;
            color: #718278;
            margin: 0;
            max-width: 380px;
            margin-inline: auto;
        }

        /* ── Modals & Backdrop ── */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(6, 37, 27, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-backdrop.open {
            display: flex;
            opacity: 1;
        }

        .modal-dialog-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: modalPopIn .2s ease;
            overflow: hidden;
            text-align: left;
        }

        @keyframes modalPopIn {
            from { transform: scale(0.96) translateY(8px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .modal-head {
            padding: 18px 22px;
            background: #06251b;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .modal-head h3 {
            font-size: 15px;
            font-weight: 800;
            color: #ffc107;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close-btn {
            background: none;
            border: none;
            color: #d1e5db;
            font-size: 18px;
            cursor: pointer;
            transition: color .15s;
        }

        .modal-close-btn:hover {
            color: #ffffff;
        }

        .modal-body-content {
            padding: 22px;
        }

        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            background: #fafcfb;
            border-top: 1px solid #eaeeec;
        }

        .btn-modal-cancel {
            padding: 10px 18px;
            border-radius: 10px;
            border: 1px solid #eaeeec;
            background: #ffffff;
            color: #63736a;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
        }

        .btn-modal-cancel:hover {
            background: #f5f8f6;
            color: #06251b;
        }

        .btn-modal-save {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            background: #06251b;
            color: #ffc107;
            font-size: 12.5px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .15s;
        }

        .btn-modal-save:hover {
            background: #144937;
            color: #ffffff;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Top Nav & Breadcrumbs ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span>Manage Lots</span>
        </div>
        <div>
            <a href="procurement.php" class="vp-back-link">
                <i class="bi bi-check2-circle text-success"></i> Done — Back to Procurements
            </a>
        </div>
    </div>

    <!-- ── Hero Card Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($proc['philgeps_ref_no']) ?>');" title="Click to copy">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($proc['philgeps_ref_no']) ?> <i class="bi bi-copy" style="font-size:10px; opacity:0.8;"></i>
                </span>
                <span class="hero-pill mode">
                    <i class="bi bi-briefcase"></i> <?= htmlspecialchars($proc['procurement_mode']) ?>
                </span>
                <span class="hero-pill status">
                    <i class="bi bi-circle-fill" style="font-size:8px; color:#4ade80;"></i> <?= $status_label ?>
                </span>
            </div>
        </div>

        <div class="vp-hero-title">
            <?= htmlspecialchars($proc['title']) ?>
        </div>

        <!-- Hero Metrics -->
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Total Project ABC</div>
                <div class="vp-hero-metric-val gold">₱ <?= number_format($proc_abc, 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers"></i> Configured Lots</div>
                <div class="vp-hero-metric-val"><?= $lots_count ?> <?= $lots_count === 1 ? 'Lot' : 'Lots' ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-pie-chart"></i> Allocated Budget</div>
                <div class="vp-hero-metric-val">₱ <?= number_format($total_lots_abc, 2) ?> (<?= $budget_pct ?>%)</div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-wallet2"></i> Remaining Unallocated</div>
                <div class="vp-hero-metric-val" style="color: <?= $remaining_abc < 0 ? '#ff8585' : '#ffffff' ?>;">
                    ₱ <?= number_format($remaining_abc, 2) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Stats Summary Grid ── -->
    <div class="stats-summary-grid">
        <div class="stat-ring-card">
            <div class="stat-ring-wrap green">
                <i class="bi bi-layers-fill" style="font-size:22px;"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num"><?= $lots_count ?></div>
                <div class="stat-ring-lbl">Total Project Lots</div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap gold">
                <i class="bi bi-cash-stack" style="font-size:22px;"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num">₱ <?= number_format($total_lots_abc, 2) ?></div>
                <div class="stat-ring-lbl">Lots ABC Sum</div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap <?= $remaining_abc < 0 ? 'red' : 'blue' ?>">
                <i class="bi <?= $remaining_abc < 0 ? 'bi-exclamation-triangle-fill' : 'bi-wallet-fill' ?>" style="font-size:22px;"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num" style="<?= $remaining_abc < 0 ? 'color:#dc2626;' : '' ?>">₱ <?= number_format($remaining_abc, 2) ?></div>
                <div class="stat-ring-lbl"><?= $remaining_abc < 0 ? 'Overbudget Deficit' : 'Unallocated Balance' ?></div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap green">
                <i class="bi bi-percent" style="font-size:22px;"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num"><?= $budget_pct ?>%</div>
                <div class="stat-ring-lbl">Budget Allocated</div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Main Layout ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Existing Lots Management ── -->
        <div class="vp-left-col">

            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-layers text-success"></i> Existing Project Lots
                        <span class="vp-card-count"><?= $lots_count ?> Configured</span>
                    </div>
                </div>

                <div class="vp-card-body">
                    
                    <!-- Budget Allocation Meter -->
                    <div class="allocation-meter-card">
                        <div class="alloc-header-row">
                            <span><i class="bi bi-bar-chart-fill" style="color:#1f7a3d;"></i> ABC Allocation Progress</span>
                            <span>₱ <?= number_format($total_lots_abc, 2) ?> / ₱ <?= number_format($proc_abc, 2) ?> (<?= $budget_pct ?>%)</span>
                        </div>
                        <div class="alloc-progress-track">
                            <div class="alloc-progress-fill <?= $remaining_abc < 0 ? 'overbudget' : '' ?>" style="width: <?= min(100, $budget_pct) ?>%;"></div>
                        </div>
                        <div class="alloc-sub-text">
                            <?php if ($remaining_abc < 0): ?>
                                <span style="color:#dc2626; font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> Warning: Total lot ABC exceeds overall project ABC budget by ₱ <?= number_format(abs($remaining_abc), 2) ?>!</span>
                            <?php elseif ($remaining_abc == 0): ?>
                                <span style="color:#1f7a3d; font-weight:700;"><i class="bi bi-check-circle-fill"></i> 100% of project budget is perfectly allocated across lots.</span>
                            <?php else: ?>
                                <span>₱ <?= number_format($remaining_abc, 2) ?> remaining to be allocated to other lots or contingencies.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lots List -->
                    <?php if ($lots_count > 0): ?>
                        <div class="lots-list-group">
                            <?php foreach ($lots as $lot): ?>
                                <div class="lot-row-card">
                                    <div class="lot-badge-num">
                                        <?= htmlspecialchars($lot['lot_number']) ?>
                                    </div>
                                    <div class="lot-main-info">
                                        <div class="lot-title-heading">
                                            <span>Lot <?= htmlspecialchars($lot['lot_number']) ?>: <?= htmlspecialchars($lot['lot_title']) ?></span>
                                        </div>
                                        <?php if (!empty($lot['description'])): ?>
                                            <div class="lot-desc-text">
                                                <?= nl2br(htmlspecialchars($lot['description'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="lot-meta-tags">
                                            <span class="lot-abc-pill">
                                                <i class="bi bi-cash-stack"></i> ABC: ₱ <?= number_format($lot['abc'], 2) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="lot-action-btns">
                                        <button type="button" 
                                                class="btn-lot-action edit"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($lot)) ?>)"
                                                title="Edit Lot #<?= htmlspecialchars($lot['lot_number']) ?>">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </button>

                                        <button type="button" 
                                                class="btn-lot-action delete"
                                                onclick="openDeleteModal(<?= (int)$lot['id'] ?>, <?= htmlspecialchars(json_encode($lot['lot_number'])) ?>, <?= htmlspecialchars(json_encode($lot['lot_title'])) ?>)"
                                                title="Delete Lot">
                                            <i class="bi bi-trash3"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="vp-empty-state">
                            <i class="bi bi-layers"></i>
                            <h4>No Lots Added Yet</h4>
                            <p>This procurement project does not have any configured lots. Use the form on the right to add Lot #1.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Add Lot Form + Allocation Guidelines ── -->
        <div class="vp-right-col">

            <!-- Card 1: Add Lot Form -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-plus-circle-fill text-success"></i> Add New Lot
                    </div>
                </div>

                <div class="vp-card-body">
                    <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST" id="addLotForm">
                        <input type="hidden" name="procurement_id" value="<?= $procurement_id ?>">

                        <!-- Lot Number -->
                        <div class="field-group">
                            <label class="field-label" for="add_lot_number">
                                Lot Number <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="number" 
                                       id="add_lot_number" 
                                       name="lot_number" 
                                       min="1" 
                                       value="<?= $suggested_next_lot ?>" 
                                       placeholder="e.g. 1" 
                                       required>
                                <i class="bi bi-hash input-icon"></i>
                            </div>
                            <span class="field-hint">Sequential lot identifier in bidding docs</span>
                        </div>

                        <!-- Lot Title -->
                        <div class="field-group">
                            <label class="field-label" for="add_title">
                                Lot Title / Classification <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="text" 
                                       id="add_title" 
                                       name="title" 
                                       placeholder="e.g. Lot 1 - Supply & Delivery of IT Equipment" 
                                       required>
                                <i class="bi bi-tag input-icon"></i>
                            </div>
                        </div>

                        <!-- Lot Approved Budget (ABC) -->
                        <div class="field-group">
                            <label class="field-label" for="add_abc">
                                Lot Approved Budget (ABC in PHP) <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="number" 
                                       step="0.01" 
                                       min="0.01" 
                                       id="add_abc" 
                                       name="abc" 
                                       placeholder="0.00" 
                                       oninput="updateAddAbcPreview(this.value)" 
                                       required>
                                <i class="bi bi-currency-dollar input-icon"></i>
                            </div>
                            <div class="currency-preview-pill" id="addAbcFormattedPreview">
                                <i class="bi bi-cash-stack"></i>
                                <span>Formatted: ₱ 0.00</span>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="field-group">
                            <label class="field-label" for="add_description">
                                Lot Description &amp; Technical Scope
                            </label>
                            <div class="input-icon-box">
                                <textarea id="add_description" 
                                          name="description" 
                                          rows="3" 
                                          placeholder="Specify technical specifications, delivery terms, or bill of quantities for this lot..."></textarea>
                                <i class="bi bi-card-text input-icon"></i>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" name="add_lot" class="btn-submit-proposal" style="margin-top:6px;">
                            <i class="bi bi-plus-lg"></i> Add Lot to Project
                        </button>
                    </form>
                </div>
            </div>

            <!-- Card 2: Lot Partitioning Guidelines -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle text-success"></i> Lot Partitioning Guide
                    </div>
                </div>

                <div class="vp-card-body">
                    <div class="timeline-guide">
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Single vs Multi-Lot Procurements</div>
                            <div class="timeline-guide-sub">If the procurement has only 1 unified scope, create Lot 1 with the full ABC amount.</div>
                        </div>
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Independent Bidder Evaluation</div>
                            <div class="timeline-guide-sub">Each lot is evaluated and awarded separately according to lowest calculated responsive bid (LCRB).</div>
                        </div>
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Cumulative Budget Cap</div>
                            <div class="timeline-guide-sub">The sum of all lot ABC allocations should not exceed the overall project ABC of ₱ <?= number_format($proc_abc, 2) ?>.</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
</main>

<!-- ========================= -->
<!-- EDIT LOT MODAL            -->
<!-- ========================= -->
<div id="editLotModal" class="modal-backdrop">
    <div class="modal-dialog-box">
        <div class="modal-head">
            <h3><i class="bi bi-pencil-square"></i> Edit Lot Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST" id="editLotForm">
            <div class="modal-body-content">
                <input type="hidden" id="edit_lot_id" name="lot_id">

                <!-- Lot Number -->
                <div class="field-group">
                    <label class="field-label" for="edit_lot_number">
                        Lot Number <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="number" id="edit_lot_number" name="lot_number" min="1" required>
                        <i class="bi bi-hash input-icon"></i>
                    </div>
                </div>

                <!-- Lot Title -->
                <div class="field-group">
                    <label class="field-label" for="edit_title">
                        Lot Title <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="text" id="edit_title" name="title" required>
                        <i class="bi bi-tag input-icon"></i>
                    </div>
                </div>

                <!-- Lot ABC -->
                <div class="field-group">
                    <label class="field-label" for="edit_abc">
                        Approved Budget (ABC in PHP) <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="number" step="0.01" min="0.01" id="edit_abc" name="abc" oninput="updateEditAbcPreview(this.value)" required>
                        <i class="bi bi-currency-dollar input-icon"></i>
                    </div>
                    <div class="currency-preview-pill" id="editAbcFormattedPreview">
                        <i class="bi bi-cash-stack"></i>
                        <span>Formatted: ₱ 0.00</span>
                    </div>
                </div>

                <!-- Description -->
                <div class="field-group">
                    <label class="field-label" for="edit_description">
                        Description &amp; Specifications
                    </label>
                    <div class="input-icon-box">
                        <textarea id="edit_description" name="description" rows="3"></textarea>
                        <i class="bi bi-card-text input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" onclick="closeEditModal()" class="btn-modal-cancel">
                    Cancel
                </button>
                <button type="submit" name="update_lot" class="btn-modal-save">
                    <i class="bi bi-floppy-fill"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================= -->
<!-- DELETE CONFIRMATION MODAL -->
<!-- ========================= -->
<div id="deleteLotModal" class="modal-backdrop">
    <div class="modal-dialog-box" style="max-width:440px;">
        <div class="modal-head" style="background:#4a0f13;">
            <h3 style="color:#ffffff;"><i class="bi bi-trash3-fill text-danger"></i> Confirm Lot Deletion</h3>
            <button type="button" class="modal-close-btn" onclick="closeDeleteModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST">
            <div class="modal-body-content" style="text-align:center; padding:28px 24px;">
                <input type="hidden" id="delete_lot_id" name="lot_id">
                
                <div style="width:52px; height:52px; border-radius:50%; background:#ffeaea; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 16px;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <h4 style="font-size:16px; font-weight:800; color:#06251b; margin:0 0 8px;">
                    Delete Lot #<span id="delete_lot_num_txt"></span>?
                </h4>
                <p style="font-size:13px; color:#63736a; margin:0; line-height:1.5;">
                    Are you sure you want to remove <strong id="delete_lot_title_txt" style="color:#06251b;"></strong>? Any line items or bidder allocations associated with this lot will be affected.
                </p>
            </div>

            <div class="modal-foot" style="justify-content:center; gap:12px;">
                <button type="button" onclick="closeDeleteModal()" class="btn-modal-cancel">
                    Cancel
                </button>
                <button type="submit" name="delete_lot" class="btn-lot-action delete" style="padding:10px 20px; font-size:13px; border-radius:10px;">
                    <i class="bi bi-trash3-fill"></i> Yes, Delete Lot
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Session Alert Toasts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
    // ── Helper: Format Currency ──
    function formatPHP(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '₱ 0.00';
        return '₱ ' + num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateAddAbcPreview(val) {
        const preview = document.getElementById('addAbcFormattedPreview');
        if (preview) {
            preview.querySelector('span').textContent = 'Formatted: ' + formatPHP(val);
        }
    }

    function updateEditAbcPreview(val) {
        const preview = document.getElementById('editAbcFormattedPreview');
        if (preview) {
            preview.querySelector('span').textContent = 'Formatted: ' + formatPHP(val);
        }
    }

    // ── Edit Modal Handlers ──
    const editModal = document.getElementById('editLotModal');

    function openEditModal(lot) {
        if (!lot) return;
        document.getElementById('edit_lot_id').value      = lot.id;
        document.getElementById('edit_lot_number').value  = lot.lot_number;
        document.getElementById('edit_title').value       = lot.lot_title;
        document.getElementById('edit_description').value = lot.description || '';
        document.getElementById('edit_abc').value         = lot.abc;
        updateEditAbcPreview(lot.abc);

        editModal.classList.add('open');
    }

    function closeEditModal() {
        editModal.classList.remove('open');
    }

    // ── Delete Modal Handlers ──
    const deleteModal = document.getElementById('deleteLotModal');

    function openDeleteModal(lotId, lotNum, lotTitle) {
        document.getElementById('delete_lot_id').value        = lotId;
        document.getElementById('delete_lot_num_txt').textContent   = lotNum;
        document.getElementById('delete_lot_title_txt').textContent = lotTitle;

        deleteModal.classList.add('open');
    }

    function closeDeleteModal() {
        deleteModal.classList.remove('open');
    }

    // Modal backdrop click dismissal
    window.addEventListener('click', e => {
        if (e.target === editModal) closeEditModal();
        if (e.target === deleteModal) closeDeleteModal();
    });

    // Escape key dismissal
    window.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeEditModal();
            closeDeleteModal();
        }
    });

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
