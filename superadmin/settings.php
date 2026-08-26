<?php
include("utils/protect-page.php");

// ── Change Password ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_pw']  ?? '';
    $new_pw   = $_POST['new_pw']      ?? '';
    $confirm  = $_POST['confirm_pw']  ?? '';

    $error = $success = '';

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $new_pw) || !preg_match('/[a-z]/', $new_pw)
           || !preg_match('/[0-9]/', $new_pw) || !preg_match('/[^A-Za-z0-9]/', $new_pw)) {
        $error = "New password does not meet the requirements.";
    } elseif ($new_pw !== $confirm) {
        $error = "New passwords do not match.";
    } else {
        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd->bind_param("si", $hash, $_SESSION['user_id']);
        if ($upd->execute()) {
            $success = "Password updated successfully.";
        } else {
            $error = "Failed to update password. Please try again.";
        }
        $upd->close();
    }

    $_SESSION['pw_error']   = $error;
    $_SESSION['pw_success'] = $success;
    header("Location: settings.php?tab=profile");
    exit();
}

$pw_error   = $_SESSION['pw_error']   ?? ''; unset($_SESSION['pw_error']);
$pw_success = $_SESSION['pw_success'] ?? ''; unset($_SESSION['pw_success']);

// ── Save Organization Info ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_org'])) {
    $fields = ['org_name', 'short_name', 'official_website', 'contact_email', 'address'];
    $stmt   = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                               VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        $stmt->bind_param("ss", $key, $val);
        $stmt->execute();
    }
    $stmt->close();
    $_SESSION['org_success'] = "Organization info saved.";
    header("Location: settings.php?tab=system");
    exit();
}

$org_success = $_SESSION['org_success'] ?? ''; unset($_SESSION['org_success']);

// ── Load Organization Settings ────────────────────────────────────────────────
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($r = $res->fetch_assoc()) $settings[$r['setting_key']] = $r['setting_value'];
// helpers
function setting(array $s, string $k, string $default = ''): string {
    return htmlspecialchars($s[$k] ?? $default);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Layout ── */
        .set-layout {
            display: grid;
            grid-template-columns: 210px 1fr;
            gap: 24px;
            align-items: flex-start;
        }
        @media (max-width: 860px) {
            .set-layout { grid-template-columns: 1fr; }
            .set-sidenav { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px; }
            .set-nav-section { display: none; }
        }

        /* ── Side nav ── */
        .set-sidenav {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 10px 8px;
            position: sticky;
            top: 84px;
        }
        .set-nav-section {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #b0bcb5;
            padding: 10px 12px 4px;
        }
        .set-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            color: #4a5e54;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            transition: background .15s, color .15s;
            font-family: 'Poppins', sans-serif;
        }
        .set-nav-item i { font-size: 16px; width: 18px; text-align: center; flex-shrink: 0; }
        .set-nav-item:hover { background: #f0f4f2; color: #06251b; }
        .set-nav-item.active { background: #06251b; color: #ffc107; }
        .set-nav-item.active i { color: #ffc107; }

        /* ── Panels ── */
        .set-panel { display: none; flex-direction: column; gap: 20px; }
        .set-panel.active { display: flex; }

        /* ── Card ── */
        .set-card {
            background: #fff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            overflow: hidden;
        }
        .set-card-head {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 18px 22px;
            border-bottom: 1px solid #f0f4f2;
        }
        .set-card-head-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .set-card-head-title { font-size: 14px; font-weight: 800; color: #06251b; }
        .set-card-head-sub   { font-size: 11px; color: #aab5ae; margin-top: 2px; }
        .set-card-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; }

        /* ── Fields ── */
        .set-field { display: flex; flex-direction: column; gap: 5px; }
        .set-field label { font-size: 12px; font-weight: 700; color: #4a5e54; }
        .set-field input[type="text"],
        .set-field input[type="email"],
        .set-field input[type="password"],
        .set-field input[type="number"],
        .set-field input[type="url"],
        .set-field select,
        .set-field textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e0e8e3;
            border-radius: 10px;
            font-size: 13.5px;
            font-family: 'Poppins', sans-serif;
            color: #222;
            background: #fafbfa;
            outline: none;
            box-sizing: border-box;
            transition: border-color .2s, box-shadow .2s;
        }
        .set-field input:focus,
        .set-field select:focus,
        .set-field textarea:focus {
            border-color: #06251b;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(6,37,27,.07);
        }
        .set-field textarea { resize: vertical; min-height: 80px; }
        .set-hint { font-size: 11px; color: #aab5ae; }

        /* password input wrapper */
        .set-pw-wrap {
            position: relative;
        }
        .set-pw-wrap input {
            padding-right: 42px;
        }
        .set-pw-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #aab5ae;
            font-size: 16px;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color .15s;
        }
        .set-pw-toggle:hover { color: #06251b; }

        .set-field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 600px) { .set-field-grid { grid-template-columns: 1fr; } }

        /* ── Password strength bar ── */
        .set-pw-strength {
            height: 4px;
            border-radius: 4px;
            background: #eaeeec;
            overflow: hidden;
            margin-top: 2px;
        }
        .set-pw-strength-fill {
            height: 100%;
            border-radius: 4px;
            width: 0%;
            transition: width .3s, background .3s;
        }
        .set-pw-strength-label {
            font-size: 11px;
            font-weight: 600;
            color: #aab5ae;
            margin-top: 3px;
        }

        /* ── Change password card redesign ── */
        .set-pw-card-inner {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        @media (max-width: 700px) { .set-pw-card-inner { grid-template-columns: 1fr; } }

        .set-pw-left {
            padding: 28px 28px 28px 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            border-right: 1px solid #f0f4f2;
        }
        @media (max-width: 700px) { .set-pw-left { border-right: none; border-bottom: 1px solid #f0f4f2; } }

        .set-pw-right {
            padding: 28px 24px 28px 28px;
            background: #fafbfa;
            display: flex;
            flex-direction: column;
            gap: 14px;
            justify-content: center;
        }

        .set-pw-rule {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12.5px;
            color: #aab5ae;
            font-weight: 500;
            transition: color .2s;
        }
        .set-pw-rule i {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            background: #eaeeec;
            color: #bbb;
            flex-shrink: 0;
            transition: background .2s, color .2s;
        }
        .set-pw-rule.met { color: #1f7a3d; }
        .set-pw-rule.met i { background: #e4f5ea; color: #1f7a3d; }

        .set-pw-right-title {
            font-size: 12px;
            font-weight: 800;
            color: #06251b;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 2px;
        }

        /* ── Save bar ── */
        .set-save-bar {
            display: flex;
            justify-content: flex-end;
            padding-top: 4px;
        }
        .set-btn-save {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 26px;
            background: #06251b;
            color: #ffc107;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: background .2s, transform .15s;
        }
        .set-btn-save:hover { background: #0a3d26; transform: translateY(-1px); }

        /* ── Danger zone ── */
        .set-danger-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid #f0f4f2;
        }
        .set-danger-row:last-child  { border-bottom: none; padding-bottom: 0; }
        .set-danger-row:first-child { padding-top: 0; }
        .set-danger-label { font-size: 13px; font-weight: 700; color: #1a1a1a; }
        .set-danger-desc  { font-size: 11.5px; color: #aab5ae; margin-top: 3px; }
        .set-btn-danger {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            background: #fff0f0;
            color: #c23b3b;
            border: 1.5px solid #f5c6c6;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            font-family: 'Poppins', sans-serif;
            transition: background .15s, border-color .15s;
        }
        .set-btn-danger:hover { background: #ffe0e0; border-color: #c23b3b; }

        /* ── Info badge ── */
        .set-info-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .set-info-badge.green  { background: #e4f5ea; color: #1f7a3d; }
        .set-info-badge.yellow { background: #fff3cd; color: #97710a; }
        .set-info-badge.blue   { background: #e7eefe; color: #2F6FED; }
        .set-info-badge.red    { background: #fbe1e1; color: #c23b3b; }
    </style>
</head>
<body class="dash-body">

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Super Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>
        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>
        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item"><i class="bi bi-people"></i><span>Bidder Accounts</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-journal-text"></i><span>Audit Trail</span></a>
        <div class="nav-section-label">Super Admin</div>
        <a href="user-role-management.php" class="nav-item"><i class="bi bi-person-gear"></i><span>User & Role Management</span></a>
        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item active"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Super Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- TOPBAR -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
        <span class="topbar-title">Settings</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Settings</h2>
        <p>Manage system configuration, your profile, and maintenance options.</p>
    </div>

    <div class="set-layout">

        <!-- ── Side nav ── -->
        <div class="set-sidenav">
            <div class="set-nav-section">General</div>
            <button class="set-nav-item active" onclick="switchTab('system', this)">
                <i class="bi bi-building"></i> System
            </button>
            <button class="set-nav-item" onclick="switchTab('profile', this)">
                <i class="bi bi-person-circle"></i> My Profile
            </button>
            <div class="set-nav-section">Advanced</div>
            <button class="set-nav-item" onclick="switchTab('maintenance', this)">
                <i class="bi bi-tools"></i> Maintenance
            </button>
        </div>

        <!-- ── Panels ── -->
        <div style="min-width:0;">

            <!-- ══════════════════════════
                 SYSTEM
            ══════════════════════════ -->
            <div class="set-panel active" id="panel-system">
                <div class="set-card">
                    <div class="set-card-head">
                        <div class="set-card-head-icon" style="background:#e8f0ec;color:#06251b;">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <div class="set-card-head-title">Organization Info</div>
                            <div class="set-card-head-sub">Basic details shown across the system</div>
                        </div>
                    </div>
                    <div class="set-card-body">
                        <?php if ($org_success): ?>
                        <div class="toast-alert success" style="position:static;box-shadow:none;">
                            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($org_success) ?>
                        </div>
                        <?php endif; ?>
                        <form method="POST" action="">
                        <input type="hidden" name="save_org" value="1">
                        <div class="set-field-grid">
                            <div class="set-field">
                                <label>Organization Name</label>
                                <input type="text" name="org_name" value="<?= setting($settings,'org_name','YesParency') ?>" placeholder="Organization name">
                            </div>
                            <div class="set-field">
                                <label>Short Name / Acronym</label>
                                <input type="text" name="short_name" value="<?= setting($settings,'short_name','YSP') ?>" placeholder="e.g. BAC, LGU">
                            </div>
                        </div>
                        <div class="set-field">
                            <label>Official Website</label>
                            <input type="url" name="official_website" value="<?= setting($settings,'official_website') ?>" placeholder="https://example.gov.ph">
                        </div>
                        <div class="set-field">
                            <label>Contact Email</label>
                            <input type="email" name="contact_email" value="<?= setting($settings,'contact_email') ?>" placeholder="procurement@example.gov.ph">
                        </div>
                        <div class="set-field">
                            <label>Address</label>
                            <textarea name="address" placeholder="Full office address..."><?= setting($settings,'address') ?></textarea>
                        </div>
                        <div class="set-save-bar">
                            <button type="submit" class="set-btn-save"><i class="bi bi-check2"></i> Save Changes</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div><!-- /#panel-system -->

            <!-- ══════════════════════════
                 PROFILE
            ══════════════════════════ -->
            <div class="set-panel" id="panel-profile">

                <!-- Change Password — redesigned -->
                <div class="set-card">
                    <div class="set-card-head">
                        <div class="set-card-head-icon" style="background:#fbe1e1;color:#c23b3b;">
                            <i class="bi bi-key-fill"></i>
                        </div>
                        <div>
                            <div class="set-card-head-title">Change Password</div>
                            <div class="set-card-head-sub">Keep your account secure with a strong password</div>
                        </div>
                    </div>

                    <div class="set-pw-card-inner">

                        <!-- Left: inputs -->
                        <div class="set-pw-left">
                            <?php if ($pw_error): ?>
                            <div class="toast-alert error" style="position:static;box-shadow:none;margin-bottom:4px;">
                                <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($pw_error) ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($pw_success): ?>
                            <div class="toast-alert success" style="position:static;box-shadow:none;margin-bottom:4px;">
                                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($pw_success) ?>
                            </div>
                            <?php endif; ?>

                            <form method="POST" action="">
                            <input type="hidden" name="change_password" value="1">

                            <div class="set-field">
                                <label>Current Password</label>
                                <div class="set-pw-wrap">
                                    <input type="password" id="currentPw" name="current_pw" placeholder="Enter current password" required>
                                    <button type="button" class="set-pw-toggle" onclick="togglePw('currentPw', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div style="height:1px;background:#f0f4f2;margin:0 -4px;"></div>

                            <div class="set-field">
                                <label>New Password</label>
                                <div class="set-pw-wrap">
                                    <input type="password" id="newPw" name="new_pw" placeholder="Create a new password" oninput="checkStrength(this.value)" required>
                                    <button type="button" class="set-pw-toggle" onclick="togglePw('newPw', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <!-- Strength bar -->
                                <div class="set-pw-strength">
                                    <div class="set-pw-strength-fill" id="strengthFill"></div>
                                </div>
                                <span class="set-pw-strength-label" id="strengthLabel">Enter a new password</span>
                            </div>

                            <div class="set-field">
                                <label>Confirm New Password</label>
                                <div class="set-pw-wrap">
                                    <input type="password" id="confirmPw" name="confirm_pw" placeholder="Repeat new password" oninput="checkMatch()" required>
                                    <button type="button" class="set-pw-toggle" onclick="togglePw('confirmPw', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <span class="set-hint" id="matchHint" style="min-height:16px;display:block;"></span>
                            </div>

                            <div class="set-save-bar" style="padding-top:0;">
                                <button type="submit" class="set-btn-save"><i class="bi bi-shield-check"></i> Update Password</button>
                            </div>

                            </form>
                        </div>

                        <!-- Right: rules checklist -->
                        <div class="set-pw-right">
                            <div class="set-pw-right-title"><i class="bi bi-shield-lock" style="margin-right:5px;"></i>Password Requirements</div>
                            <div class="set-pw-rule" id="rule-len">
                                <i class="bi bi-check"></i> At least 8 characters
                            </div>
                            <div class="set-pw-rule" id="rule-upper">
                                <i class="bi bi-check"></i> One uppercase letter
                            </div>
                            <div class="set-pw-rule" id="rule-lower">
                                <i class="bi bi-check"></i> One lowercase letter
                            </div>
                            <div class="set-pw-rule" id="rule-num">
                                <i class="bi bi-check"></i> One number
                            </div>
                            <div class="set-pw-rule" id="rule-sym">
                                <i class="bi bi-check"></i> One special character
                            </div>
                        </div>

                    </div><!-- /.set-pw-card-inner -->
                </div><!-- /.set-card -->

            </div><!-- /#panel-profile -->

            <!-- ══════════════════════════
                 MAINTENANCE
            ══════════════════════════ -->
            <div class="set-panel" id="panel-maintenance">

                <!-- System Information -->
                <div class="set-card">
                    <div class="set-card-head">
                        <div class="set-card-head-icon" style="background:#e8f0ec;color:#06251b;">
                            <i class="bi bi-info-circle"></i>
                        </div>
                        <div>
                            <div class="set-card-head-title">System Information</div>
                            <div class="set-card-head-sub">Runtime environment and version details</div>
                        </div>
                    </div>
                    <div class="set-card-body" style="gap:0;padding:0;">
                        <?php
                        $info = [
                            ['Application',  'YesParency Procurement System', 'green'],
                            ['Version',       'v1.0.0',                        'blue'],
                            ['PHP Version',   phpversion(),                    'blue'],
                            ['Server',        $_SERVER['SERVER_SOFTWARE'] ?? 'N/A', null],
                            ['Environment',   'Development',                   'yellow'],
                            ['Last Deployed', date('F j, Y'),                  null],
                        ];
                        foreach ($info as $i => [$lbl, $val, $badge]):
                        ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;
                                    padding:14px 24px;
                                    <?= $i < count($info)-1 ? 'border-bottom:1px solid #f0f4f2;' : '' ?>">
                            <span style="font-size:13px;font-weight:600;color:#4a5e54;"><?= $lbl ?></span>
                            <?php if ($badge): ?>
                                <span class="set-info-badge <?= $badge ?>"><?= htmlspecialchars($val) ?></span>
                            <?php else: ?>
                                <span style="font-size:13px;color:#888;"><?= htmlspecialchars($val) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Danger Zone — maintenance mode only -->
                <div class="set-card" style="border-color:#f5c6c6;">
                    <div class="set-card-head" style="background:#fff8f8;border-bottom-color:#fde8e8;">
                        <div class="set-card-head-icon" style="background:#fbe1e1;color:#c23b3b;">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <div>
                            <div class="set-card-head-title" style="color:#c23b3b;">Danger Zone</div>
                            <div class="set-card-head-sub">Irreversible operation — proceed with caution</div>
                        </div>
                    </div>
                    <div class="set-card-body">
                        <div class="set-danger-row" style="padding:0;">
                            <div>
                                <div class="set-danger-label">Enable Maintenance Mode</div>
                                <div class="set-danger-desc">Take the system offline for all non-admin users. They will see a maintenance page until you disable it.</div>
                            </div>
                            <button class="set-btn-danger"><i class="bi bi-tools"></i> Enable</button>
                        </div>
                    </div>
                </div>

            </div><!-- /#panel-maintenance -->

        </div><!-- /.panels wrapper -->
    </div><!-- /.set-layout -->

</div>
</main>

<script>
    // Sidebar
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');
    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    }
    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    // Tab switching
    function switchTab(tab, btn) {
        document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.set-nav-item').forEach(b => b.classList.remove('active'));
        document.getElementById('panel-' + tab).classList.add('active');
        btn.classList.add('active');
        // update URL without reload so the tab is bookmarkable
        history.replaceState(null, '', '?tab=' + tab);
    }

    // Restore tab from ?tab= query param (set by PRG redirect)
    (function () {
        const params = new URLSearchParams(window.location.search);
        const tab    = params.get('tab');
        if (tab && document.getElementById('panel-' + tab)) {
            document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.set-nav-item').forEach(b => b.classList.remove('active'));
            document.getElementById('panel-' + tab).classList.add('active');
            const btn = document.querySelector('.set-nav-item[onclick*="' + tab + '"]');
            if (btn) btn.classList.add('active');
        }
    })();

    // Auto-open profile tab if redirected after password change
    <?php if ($pw_error || $pw_success): ?>
    (function() {
        document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.set-nav-item').forEach(b => b.classList.remove('active'));
        document.getElementById('panel-profile').classList.add('active');
        document.querySelector('[onclick*="profile"]').classList.add('active');
    })();
    <?php endif; ?>

    // Password visibility toggle
    function togglePw(id, btn) {
        const input = document.getElementById(id);
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.querySelector('i').className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
    }

    // Password strength + rules
    function checkStrength(val) {
        const rules = {
            'rule-len':   val.length >= 8,
            'rule-upper': /[A-Z]/.test(val),
            'rule-lower': /[a-z]/.test(val),
            'rule-num':   /[0-9]/.test(val),
            'rule-sym':   /[^A-Za-z0-9]/.test(val),
        };

        let met = Object.values(rules).filter(Boolean).length;

        Object.entries(rules).forEach(([id, pass]) => {
            document.getElementById(id).classList.toggle('met', pass);
        });

        const fill  = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        const pct   = (met / 5) * 100;
        const colors = ['#e0e0e0', '#c23b3b', '#e67e22', '#f9a825', '#43a047', '#1f7a3d'];
        const labels = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

        fill.style.width = pct + '%';
        fill.style.background = colors[met];
        label.textContent = val.length === 0 ? 'Enter a new password' : labels[met];
        label.style.color = met < 3 ? '#c23b3b' : met < 5 ? '#e67e22' : '#1f7a3d';

        checkMatch();
    }

    // Confirm password match hint
    function checkMatch() {
        const np = document.getElementById('newPw').value;
        const cp = document.getElementById('confirmPw').value;
        const hint = document.getElementById('matchHint');
        if (!cp) { hint.textContent = ''; return; }
        if (np === cp) {
            hint.textContent = '✓ Passwords match';
            hint.style.color = '#1f7a3d';
        } else {
            hint.textContent = '✗ Passwords do not match';
            hint.style.color = '#c23b3b';
        }
    }
</script>

</body>
</html>
