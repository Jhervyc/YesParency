<?php
/**
 * One-time migration: broadcast notifications → owned per-user notifications.
 *
 *   php debug/migrate_notifications.php
 *
 * 1. Creates the `notifications` table (see query.sql).
 * 2. Fans every legacy `system_notifications` row out to one row per recipient
 *    (same targeting rules as before, only users registered before it was posted),
 *    carrying read state over from `user_notification_reads`.
 * 3. Drops `user_notification_reads` and `system_notifications`.
 *
 * Safe to re-run: it stops if the legacy tables no longer exist.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

chdir(__DIR__);
include "../config/db_connect.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

function table_exists(mysqli $conn, string $name): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n > 0;
}

// 1. Create the new table
$conn->query("
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL,
    actor_id INT NULL,
    batch_id CHAR(32) NULL,
    audience VARCHAR(100) NULL,
    dedupe_key VARCHAR(100) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (user_id, is_read, created_at),
    INDEX idx_batch (batch_id),
    INDEX idx_type (type, created_at),
    UNIQUE KEY uq_dedupe (user_id, dedupe_key),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "✓ notifications table ready\n";

if (!table_exists($conn, 'system_notifications')) {
    echo "• Legacy table system_notifications not found — nothing to migrate.\n";
    exit(0);
}

$hasReads = table_exists($conn, 'user_notification_reads');
$readJoin = $hasReads
    ? "LEFT JOIN user_notification_reads unr ON unr.notification_id = sn.notification_id AND unr.user_id = u.user_id"
    : "";
$readCols = $hasReads
    ? "IF(unr.read_at IS NULL, 0, 1), unr.read_at"
    : "0, NULL";

$conn->begin_transaction();
try {
    // 2. Fan out legacy announcements (dedupe_key guards against double runs)
    $conn->query("
        INSERT IGNORE INTO notifications
            (user_id, type, title, message, actor_id, batch_id, audience, dedupe_key, is_read, read_at, created_at)
        SELECT
            u.user_id,
            'admin_message',
            sn.title,
            sn.message,
            sn.created_by,
            MD5(CONCAT('legacy-', sn.notification_id)),
            CASE sn.target_type
                WHEN 'all'  THEN 'Everyone'
                WHEN 'role' THEN CASE sn.target_role
                                    WHEN 'bidder'     THEN 'Bidders'
                                    WHEN 'admin'      THEN 'Admins'
                                    WHEN 'user'       THEN 'Users'
                                    WHEN 'superadmin' THEN 'Superadmins'
                                 END
                ELSE LEFT(CONCAT(u.firstname, ' ', u.lastname), 100)
            END,
            CONCAT('legacy:', sn.notification_id),
            $readCols,
            sn.created_at
        FROM system_notifications sn
        JOIN users u
          ON (   sn.target_type = 'all'
              OR (sn.target_type = 'role' AND u.role = sn.target_role)
              OR (sn.target_type = 'user' AND u.user_id = sn.target_user_id))
         AND sn.created_at >= u.created_at
        $readJoin
    ");
    $migrated = $conn->affected_rows;
    $conn->commit();
    echo "✓ Migrated {$migrated} per-user notification rows\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "✗ Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 3. Drop legacy tables (DDL — outside the transaction)
if ($hasReads) $conn->query("DROP TABLE user_notification_reads");
$conn->query("DROP TABLE system_notifications");
echo "✓ Dropped legacy tables system_notifications, user_notification_reads\n";
echo "Done.\n";
