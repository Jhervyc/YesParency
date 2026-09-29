<?php
/**
 * utils/notification_helper.php
 *
 * Owned (per-user) in-app notifications.
 *
 * Every row in `notifications` belongs to exactly one user (`user_id`).
 * - System events (bid verified, doc expiring, ...) call notify_user() / notify_users().
 * - Admin-composed messages call send_admin_notification(), which fans out one row
 *   per recipient; the rows share a `batch_id` so the send can be tracked or recalled.
 *
 * `link` is stored relative to the app root (e.g. "bidder/my_bids.php"). Every
 * dashboard page lives one directory deep, so clients open it as "../" + link.
 */

// ── Type registry ──────────────────────────────────────────────────────────────
// icon  = Bootstrap Icons name, tone = colour token (see .nt-tone--* in dashboard-shell.css)
// category = inbox filter group
const NOTIF_TYPES = [
    'admin_message'      => ['icon' => 'megaphone-fill',            'tone' => 'blue',   'label' => 'Announcement',        'category' => 'admin'],
    'bidder_approved'    => ['icon' => 'patch-check-fill',          'tone' => 'green',  'label' => 'Account Approved',    'category' => 'account'],
    'bidder_rejected'    => ['icon' => 'x-octagon-fill',            'tone' => 'red',    'label' => 'Application Update',  'category' => 'account'],
    'bid_verified'       => ['icon' => 'check-circle-fill',         'tone' => 'green',  'label' => 'Bid Verified',        'category' => 'bids'],
    'bid_rejected'       => ['icon' => 'x-circle-fill',             'tone' => 'red',    'label' => 'Bid Rejected',        'category' => 'bids'],
    'lot_awarded'        => ['icon' => 'trophy-fill',               'tone' => 'gold',   'label' => 'Lot Awarded',         'category' => 'bids'],
    'bid_submitted'      => ['icon' => 'inbox-fill',                'tone' => 'green',  'label' => 'New Bid',             'category' => 'bids'],
    'session_scheduled'  => ['icon' => 'calendar-event-fill',       'tone' => 'teal',   'label' => 'Session Scheduled',   'category' => 'sessions'],
    'session_concluded'  => ['icon' => 'flag-fill',                 'tone' => 'purple', 'label' => 'Session Concluded',   'category' => 'sessions'],
    'doc_expiring'       => ['icon' => 'hourglass-split',           'tone' => 'orange', 'label' => 'Document Expiring',   'category' => 'documents'],
    'doc_expired'        => ['icon' => 'file-earmark-x-fill',       'tone' => 'red',    'label' => 'Document Expired',    'category' => 'documents'],
    'doc_reuploaded'     => ['icon' => 'file-earmark-arrow-up-fill','tone' => 'blue',   'label' => 'Document Re-uploaded','category' => 'documents'],
    'bidder_application' => ['icon' => 'person-plus-fill',          'tone' => 'purple', 'label' => 'Bidder Application',  'category' => 'account'],
    'invitation_request' => ['icon' => 'envelope-plus-fill',        'tone' => 'teal',   'label' => 'Access Request',      'category' => 'account'],
];

const NOTIF_CATEGORIES = [
    'all'       => ['label' => 'All',          'icon' => 'inbox'],
    'unread'    => ['label' => 'Unread',       'icon' => 'circle-fill'],
    'admin'     => ['label' => 'From Admins',  'icon' => 'megaphone'],
    'bids'      => ['label' => 'Bids',         'icon' => 'briefcase'],
    'sessions'  => ['label' => 'Bid Sessions', 'icon' => 'broadcast'],
    'account'   => ['label' => 'Account',      'icon' => 'person-badge'],
    'documents' => ['label' => 'Documents',    'icon' => 'file-earmark-text'],
];

const NOTIF_AUDIENCE_ROLES = [
    'bidder'     => 'Bidders',
    'admin'      => 'Admins',
    'user'       => 'Users',
    'superadmin' => 'Superadmins',
];

function notif_type_meta(string $type): array
{
    return NOTIF_TYPES[$type] ?? ['icon' => 'bell-fill', 'tone' => 'slate', 'label' => 'Notification', 'category' => 'all'];
}

/** Types that belong to an inbox category ('all' / 'unread' → null = no type filter). */
function notif_category_types(string $category): ?array
{
    if ($category === 'all' || $category === 'unread') return null;
    $types = [];
    foreach (NOTIF_TYPES as $t => $m) {
        if ($m['category'] === $category) $types[] = $t;
    }
    return $types ?: ['__none__'];
}

// ── Formatting ─────────────────────────────────────────────────────────────────
/**
 * Ages are computed by MySQL (same clock that wrote created_at), so "time ago"
 * stays correct even when PHP's timezone differs from the database's.
 * Select this next to n.* in every notification query.
 */
const NOTIF_AGE_SQL = "TIMESTAMPDIFF(SECOND, n.created_at, NOW()) AS age_sec, DATEDIFF(CURDATE(), DATE(n.created_at)) AS age_days";

function notif_time_ago(string $datetime, ?int $ageSec = null): string
{
    $diff = $ageSec ?? (time() - strtotime($datetime));
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

/** Date bucket used to group the inbox list (age_days = calendar days since created). */
function notif_date_group(int $ageDays): string
{
    if ($ageDays <= 0) return 'Today';
    if ($ageDays === 1) return 'Yesterday';
    if ($ageDays <= 6) return 'This Week';
    return 'Earlier';
}

/** Shape a DB row (n.* + NOTIF_AGE_SQL + optional actor_fname/actor_lname) for JSON output. */
function notif_format_row(array $row): array
{
    $meta  = notif_type_meta($row['type']);
    $actor = trim(($row['actor_fname'] ?? '') . ' ' . ($row['actor_lname'] ?? ''));
    return [
        'id'             => (int)$row['notification_id'],
        'type'           => $row['type'],
        'title'          => $row['title'],
        'message'        => $row['message'],
        'link'           => $row['link'] ?: null,
        'is_read'        => (int)$row['is_read'] === 1,
        'created_at'     => $row['created_at'],
        'time_ago'       => notif_time_ago($row['created_at'], isset($row['age_sec']) ? (int)$row['age_sec'] : null),
        'date_group'     => notif_date_group((int)($row['age_days'] ?? 0)),
        'formatted_date' => date('M j, Y · g:i A', strtotime($row['created_at'])),
        'actor_name'     => $actor !== '' ? $actor : 'System',
        'icon'           => $meta['icon'],
        'tone'           => $meta['tone'],
        'label'          => $meta['label'],
    ];
}

/** Bid-session list for the recipient's portal (null for roles without one). */
function notif_session_link(string $role): ?string
{
    if ($role === 'bidder') return 'bidder/bid-session-list.php';
    if ($role === 'admin' || $role === 'superadmin') return 'admin/bid-session-list.php';
    return null;
}

/** Validate an optional app-relative link. Returns the clean link, '' for none, or false if invalid. */
function notif_clean_link(?string $link)
{
    $link = trim((string)$link);
    if ($link === '') return '';
    $link = ltrim($link, '/');
    if (strpos($link, '..') !== false) return false;
    if (!preg_match('#^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$#', $link)) return false;
    return substr($link, 0, 255);
}

// ── Writing ────────────────────────────────────────────────────────────────────
/**
 * Create one notification owned by $userId.
 * $dedupeKey makes the call idempotent per user (INSERT IGNORE on uq_dedupe).
 */
function notify_user(
    mysqli $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $actorId = null,
    ?string $dedupeKey = null
): bool {
    return notify_users($conn, [$userId], $type, $title, $message, $link, $actorId, $dedupeKey) > 0;
}

/** Create the same notification for several users. Returns the number of rows inserted. */
function notify_users(
    mysqli $conn,
    array $userIds,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $actorId = null,
    ?string $dedupeKey = null
): int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
    if (empty($userIds)) return 0;

    $stmt = $conn->prepare("
        INSERT IGNORE INTO notifications (user_id, type, title, message, link, actor_id, dedupe_key)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return 0;

    $title    = mb_substr($title, 0, 255);
    $inserted = 0;
    $uid      = 0;
    $stmt->bind_param("issssis", $uid, $type, $title, $message, $link, $actorId, $dedupeKey);
    foreach ($userIds as $uid) {
        if ($stmt->execute()) $inserted += $stmt->affected_rows;
    }
    $stmt->close();
    return $inserted;
}

/** Business name for bidders, otherwise full name — used in admin-facing alert text. */
function notif_user_display_name(mysqli $conn, int $userId): string
{
    $stmt = $conn->prepare("
        SELECT u.firstname, u.lastname, bp.business_name
        FROM users u
        LEFT JOIN bidder_profiles bp ON bp.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) return "User #{$userId}";
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) return "User #{$userId}";
    return $u['business_name'] ?: (trim($u['firstname'] . ' ' . $u['lastname']) ?: "User #{$userId}");
}

/** Active Secretariat members; falls back to active superadmins when none are configured. */
function get_secretariat_recipients(mysqli $conn): array
{
    $recipients = [];
    $stmt = $conn->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.email
        FROM users u
        JOIN admin_roles ar ON u.user_id = ar.user_id
        WHERE ar.admin_type = 'SECRETARIAT' AND u.status = 'active'
    ");
    if ($stmt) {
        $stmt->execute();
        $recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    if (empty($recipients)) {
        $res = $conn->query("SELECT user_id, firstname, lastname, email FROM users WHERE role = 'superadmin' AND status = 'active'");
        if ($res) $recipients = $res->fetch_all(MYSQLI_ASSOC);
    }
    return $recipients;
}

function notify_secretariat(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $actorId = null,
    ?string $dedupeKey = null
): int {
    $ids = array_column(get_secretariat_recipients($conn), 'user_id');
    return notify_users($conn, $ids, $type, $title, $message, $link, $actorId, $dedupeKey);
}

// ── Admin-composed messages (fan-out) ──────────────────────────────────────────
/**
 * Build the WHERE clause selecting recipients for an admin send.
 * $audienceType: 'all' | 'role' | 'users'
 * Returns [sql, types, params] or null when the audience is empty/invalid.
 */
function notif_audience_where(string $audienceType, array $roles, array $userIds, int $excludeUserId = 0): ?array
{
    $sql    = "u.status = 'active'";
    $types  = '';
    $params = [];

    if ($audienceType === 'role') {
        $roles = array_values(array_intersect(array_keys(NOTIF_AUDIENCE_ROLES), $roles));
        if (empty($roles)) return null;
        $sql   .= " AND u.role IN (" . implode(',', array_fill(0, count($roles), '?')) . ")";
        $types .= str_repeat('s', count($roles));
        $params = array_merge($params, $roles);
    } elseif ($audienceType === 'users') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
        if (empty($userIds)) return null;
        $sql   .= " AND u.user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")";
        $types .= str_repeat('i', count($userIds));
        $params = array_merge($params, $userIds);
        $excludeUserId = 0; // explicitly picked users always receive it
    } elseif ($audienceType !== 'all') {
        return null;
    }

    if ($excludeUserId > 0) {
        $sql     .= " AND u.user_id <> ?";
        $types   .= 'i';
        $params[] = $excludeUserId;
    }
    return [$sql, $types, $params];
}

function notif_count_audience(mysqli $conn, string $audienceType, array $roles, array $userIds, int $excludeUserId = 0): int
{
    $where = notif_audience_where($audienceType, $roles, $userIds, $excludeUserId);
    if (!$where) return 0;
    [$sql, $types, $params] = $where;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $sql");
    if (!$stmt) return 0;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}

/** Human label stored on each fanned-out row, e.g. "Everyone", "Bidders, Admins", "3 users". */
function notif_audience_label(mysqli $conn, string $audienceType, array $roles, array $userIds): string
{
    if ($audienceType === 'all') return 'Everyone';
    if ($audienceType === 'role') {
        $labels = [];
        foreach (NOTIF_AUDIENCE_ROLES as $r => $l) {
            if (in_array($r, $roles, true)) $labels[] = $l;
        }
        return implode(', ', $labels);
    }
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (count($userIds) === 1) {
        $stmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userIds[0]);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($u) return mb_substr(trim($u['firstname'] . ' ' . $u['lastname']), 0, 100);
    }
    return count($userIds) . ' users';
}

/**
 * Fan an admin message out to every matching active user in one INSERT … SELECT.
 * Returns ['batch_id' => string, 'recipients' => int, 'audience' => string].
 */
function send_admin_notification(
    mysqli $conn,
    string $title,
    string $message,
    string $audienceType,
    array $roles,
    array $userIds,
    ?string $link,
    int $actorId
): array {
    $where = notif_audience_where($audienceType, $roles, $userIds, $actorId);
    if (!$where) return ['batch_id' => null, 'recipients' => 0, 'audience' => ''];
    [$whereSql, $whereTypes, $whereParams] = $where;

    $batchId  = bin2hex(random_bytes(16));
    $audience = notif_audience_label($conn, $audienceType, $roles, $userIds);
    $title    = mb_substr($title, 0, 255);
    $link     = $link ?: null;

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link, actor_id, batch_id, audience)
        SELECT u.user_id, 'admin_message', ?, ?, ?, ?, ?, ?
        FROM users u
        WHERE $whereSql
    ");
    if (!$stmt) return ['batch_id' => null, 'recipients' => 0, 'audience' => $audience];

    $types  = 'sssiss' . $whereTypes;
    $params = array_merge([$title, $message, $link, $actorId, $batchId, $audience], $whereParams);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    return ['batch_id' => $count > 0 ? $batchId : null, 'recipients' => max(0, $count), 'audience' => $audience];
}

// ── Reading ────────────────────────────────────────────────────────────────────
function notif_unread_count(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}

/** Latest $limit notifications for a user, already formatted. */
function notif_latest(mysqli $conn, int $userId, int $limit = 3): array
{
    $stmt = $conn->prepare("
        SELECT n.*, " . NOTIF_AGE_SQL . ", a.firstname AS actor_fname, a.lastname AS actor_lname
        FROM notifications n
        LEFT JOIN users a ON n.actor_id = a.user_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC, n.notification_id DESC
        LIMIT ?
    ");
    if (!$stmt) return [];
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $rows = array_map('notif_format_row', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $rows;
}

/** Headline figures for Notification Management. */
function notif_admin_stats(mysqli $conn): array
{
    $row = $conn->query("
        SELECT COUNT(DISTINCT batch_id) AS sends,
               COUNT(*) AS delivered,
               COALESCE(SUM(is_read), 0) AS read_cnt
        FROM notifications
        WHERE type = 'admin_message' AND batch_id IS NOT NULL
          AND created_at >= NOW() - INTERVAL 30 DAY
    ")->fetch_assoc();
    $unread = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetch_row()[0];
    return [
        'sent_30d'  => (int)$row['sends'],
        'read_rate' => (int)$row['delivered'] > 0 ? (int)round($row['read_cnt'] / $row['delivered'] * 100) : 0,
        'unread'    => $unread,
    ];
}

/** Per-category counts (total + unread) for the inbox filter rail. */
function notif_category_counts(mysqli $conn, int $userId): array
{
    $counts = [];
    foreach (array_keys(NOTIF_CATEGORIES) as $c) $counts[$c] = ['total' => 0, 'unread' => 0];

    $stmt = $conn->prepare("SELECT type, is_read, COUNT(*) AS c FROM notifications WHERE user_id = ? GROUP BY type, is_read");
    if (!$stmt) return $counts;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $c      = (int)$r['c'];
        $unread = (int)$r['is_read'] === 0;
        $cat    = notif_type_meta($r['type'])['category'];

        $counts['all']['total'] += $c;
        if ($unread) {
            $counts['all']['unread']    += $c;
            $counts['unread']['total']  += $c;
            $counts['unread']['unread'] += $c;
        }
        if (isset($counts[$cat]) && $cat !== 'all') {
            $counts[$cat]['total'] += $c;
            if ($unread) $counts[$cat]['unread'] += $c;
        }
    }
    $stmt->close();
    return $counts;
}
