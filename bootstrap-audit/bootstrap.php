<?php
declare(strict_types=1);

// 🔐 GLOBAL SECURITY HEADERS (FIRST LINE OF EXECUTION)
require_once __DIR__ . '/security-headers.php';

// ---- EXISTING BOOTSTRAP LOGIC BELOW ----

// Detect HTTPS correctly behind proxy/load balancer
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

// Force HTTPS
if (!$isHttps && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Secure session settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}