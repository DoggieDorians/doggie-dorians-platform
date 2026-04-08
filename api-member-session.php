<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'capacitor://localhost',
    'http://localhost',
    'https://localhost',
    'https://dorianspetcare.com',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None',
]);

session_start();

echo json_encode([
    'success' => true,
    'logged_in' => isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0,
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'role' => isset($_SESSION['role']) ? (string) $_SESSION['role'] : null,
    'email' => isset($_SESSION['email']) ? (string) $_SESSION['email'] : null,
    'full_name' => isset($_SESSION['full_name']) ? (string) $_SESSION['full_name'] : null,
]);
exit;