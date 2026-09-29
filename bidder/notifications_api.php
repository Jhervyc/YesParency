<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/notification_personal_api.php");

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';

if (!notif_handle_personal_action($conn, (int)$_SESSION['user_id'], $action)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
