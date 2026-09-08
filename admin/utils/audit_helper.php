<?php
/**
 * admin/utils/audit_helper.php
 * Reusable helper for centralized audit logging in YesParency.
 * Inserts records into the existing `audit_logs` table.
 */

if (!function_exists('audit_log')) {
    /**
     * Helper to clean sensitive fields before recording in audit log
     */
    function _sanitize_audit_values($data) {
        if (!is_array($data)) {
            return $data;
        }
        $sensitive_keys = ['password', 'confirm_password', 'current_password', 'new_password', 'token', 'secret', 'auth_token', 'private_key'];
        $clean = [];
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string)$k), $sensitive_keys, true)) {
                continue;
            }
            if (is_array($v)) {
                $clean[$k] = _sanitize_audit_values($v);
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    /**
     * Convert value to valid JSON string or null
     */
    function _format_audit_json($val) {
        if ($val === null || $val === '') {
            return null;
        }

        if (is_array($val) || is_object($val)) {
            $sanitized = _sanitize_audit_values($val);
            $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : null;
        }

        if (is_string($val)) {
            $trimmed = trim($val);
            if (($trimmed[0] === '{' && substr($trimmed, -1) === '}') ||
                ($trimmed[0] === '[' && substr($trimmed, -1) === ']')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $sanitized = _sanitize_audit_values($decoded);
                    return json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            return json_encode(['value' => $val], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode(['value' => $val], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Insert a record into the `audit_logs` table.
     *
     * @param mysqli      $conn        Active MySQLi database connection
     * @param string      $action      Name of action (e.g., 'PROCUREMENT_CREATED')
     * @param string      $module      System module (e.g., 'procurements', 'lots', 'bids', 'users', 'settings', 'bid_opening')
     * @param int|null    $record_id   Primary key ID of affected record
     * @param string      $description Human-readable description
     * @param mixed|null  $old_values  State before change (array, JSON string, or scalar)
     * @param mixed|null  $new_values  State after change (array, JSON string, or scalar)
     * @param int|null    $user_id     Actor user_id (defaults to $_SESSION['user_id'] if available)
     * @return bool                    True on success, false on failure
     */
    function audit_log(
        $conn,
        string $action,
        string $module,
        ?int $record_id,
        string $description,
        $old_values = null,
        $new_values = null,
        ?int $user_id = null
    ): bool {
        if (!$conn || !($conn instanceof mysqli)) {
            return false;
        }

        // 1. Resolve user_id from session if not explicitly provided
        if ($user_id === null) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            if (isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            }
        }

        // 2. Resolve IP Address and User Agent
        $ip_address = null;
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip_address = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip_address = trim($ip_parts[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }
        if ($ip_address !== null) {
            $ip_address = substr($ip_address, 0, 45);
        }

        $user_agent = null;
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = substr($_SERVER['HTTP_USER_AGENT'], 0, 255);
        }

        // 3. Format old and new values as valid JSON strings
        $old_json = _format_audit_json($old_values);
        $new_json = _format_audit_json($new_values);

        // 4. Clean strings
        $action_clean      = substr(strtoupper(trim($action)), 0, 50);
        $module_clean      = substr(strtolower(trim($module)), 0, 50);
        $description_clean = trim($description);
        $rec_id            = ($record_id !== null && $record_id > 0) ? (int)$record_id : null;

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
                (user_id, action, module, record_id, description, old_values, new_values, ip_address, user_agent, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            error_log("audit_log prepare error: " . $conn->error);
            return false;
        }

        $stmt->bind_param(
            "ississsss",
            $user_id,
            $action_clean,
            $module_clean,
            $rec_id,
            $description_clean,
            $old_json,
            $new_json,
            $ip_address,
            $user_agent
        );

        $executed = $stmt->execute();
        if (!$executed) {
            error_log("audit_log execute error: " . $stmt->error);
        }
        $stmt->close();

        return $executed;
    }
}
