<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

require_once __DIR__ . '/includes/analytics.php';

dd_analytics_ensure_schema($pdo);

$payload = dd_analytics_consume_payload();

if ($payload !== []) {
    dd_analytics_log_event($pdo, $payload);
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true]);
