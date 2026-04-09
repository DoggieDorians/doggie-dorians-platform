<?php
declare(strict_types=1);

if (headers_sent()) {
    return;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== '' &&
    strtolower((string)$_SERVER['HTTPS']) !== 'off'
) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "img-src 'self' data: https:; " .
    "style-src 'self' 'unsafe-inline' https:; " .
    "script-src 'self' 'unsafe-inline' https:; " .
    "font-src 'self' data: https:; " .
    "connect-src 'self' https:; " .
    "frame-ancestors 'self'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "object-src 'none';"
);