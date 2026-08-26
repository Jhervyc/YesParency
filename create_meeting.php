<?php
require_once __DIR__ . '/bootstrap.php';
$accountId = $_ENV['ZOOM_ACCOUNT_ID'];
$clientId = $_ENV['ZOOM_CLIENT_ID'];
$clientSecret = $_ENV['ZOOM_CLIENT_SECRET'];

$userId = 'jhervyc01@gmail.com';


// ===============================
// 1. Get Access Token
// ===============================

$credentials = base64_encode($clientId . ':' . $clientSecret);

$ch = curl_init('https://zoom.us/oauth/token');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'account_credentials',
        'account_id' => $accountId
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);

if ($response === false) {
    die('Token Error: ' . curl_error($ch));
}

curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die('Failed to get access token: ' . $response);
}

$accessToken = $tokenData['access_token'];


// ===============================
// 2. Create Zoom Meeting
// ===============================

$url = "https://api-us.zoom.us/v2/users/" . urlencode($userId) . "/meetings";

$meetingData = [
    'topic' => 'Test Bid Opening',
    'type' => 2,
    'start_time' => '2026-08-12T10:00:00',
    'duration' => 30,
    'timezone' => 'Asia/Manila',
    'settings' => [
        'waiting_room' => true,
        'join_before_host' => false
    ]
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($meetingData),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);

if ($response === false) {
    die('Meeting Error: ' . curl_error($ch));
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

$data = json_decode($response, true);


// ===============================
// 3. Display Result
// ===============================

echo '<pre>';

echo "HTTP Status: " . $httpCode . "\n\n";

print_r($data);

echo '</pre>';