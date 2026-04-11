<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
require_once __DIR__ . '/db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload',
    ]);
    exit;
}

$identifier = trim((string) ($data['identifier'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter your email and password.',
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, password_hash, role
        FROM users
        WHERE lower(email) = lower(:identifier)
        LIMIT 1
    ");
    $stmt->execute([
        ':identifier' => $identifier,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && isset($user['password_hash']) && password_verify($password, (string) $user['password_hash'])) {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['full_name'] = (string) ($user['full_name'] ?? '');
        $_SESSION['email'] = (string) ($user['email'] ?? '');
        $_SESSION['role'] = (string) ($user['role'] ?? 'member');

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user_id' => (int) $user['id'],
            'role' => (string) ($_SESSION['role'] ?? 'member'),
            'full_name' => (string) ($_SESSION['full_name'] ?? ''),
            'email' => (string) ($_SESSION['email'] ?? ''),
        ]);
        exit;
    }

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or password.',
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong while trying to log in. Please try again.',
    ]);
    exit;
}