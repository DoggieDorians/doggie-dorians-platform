<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * client-map.php
 *
 * Full replacement
 * Schema-tolerant client-facing live walk tracking page
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function currentUserRole(): string
{
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}

function isMemberLike(): bool
{
    return currentUserRole() === 'member' || !empty($_SESSION['user_id']) || !empty($_SESSION['member_id']);
}

function currentUserId(): int
{
    foreach (['user_id', 'member_id', 'client_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

function hasTable(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = [];
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable) {
        $cache[$table] = [];
        return [];
    }
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (hasColumn($pdo, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function valueFromRow(array $row, array $candidates, mixed $default = null): mixed
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }
    return $default;
}

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable) {
        return false;
    }
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'new', 'open', 'unassigned' => 'available',
        'assigned', 'confirmed' => 'accepted',
        'active', 'walking', 'started', 'in progress' => 'in_progress',
        'done', 'finished', 'closed' => 'completed',
        'canceled', 'cancelled by client', 'cancelled by walker', 'void' => 'cancelled',
        'released back', 're-opened', 'reopened' => 'released',
        default => $status !== '' ? $status : 'pending',
    };
}

function normalizeServiceType(?string $type): string
{
    $type = strtolower(trim((string) $type));

    if ($type === '') {
        return 'service';
    }
    if (str_contains($type, 'walk')) {
        return 'walk';
    }
    if (str_contains($type, 'board')) {
        return 'boarding';
    }
    if (str_contains($type, 'daycare') || str_contains($type, 'day care')) {
        return 'daycare';
    }
    if (str_contains($type, 'sit')) {
        return 'sitting';
    }
    if (str_contains($type, 'drop')) {
        return 'drop-in';
    }

    return $type;
}

function bookingBaseTable(PDO $pdo): ?string
{
    foreach (['bookings', 'walks'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function loadPetNameById(PDO $pdo, int $petId): string
{
    if ($petId <= 0) {
        return '';
    }

    foreach (['pets', 'dogs'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, ['id', 'pet_id', 'dog_id']);
        $nameCol = firstExistingColumn($pdo, $table, ['name', 'pet_name', 'dog_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $petId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadClientName(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    foreach (['users', 'members', 'client_profiles'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, ['id', 'user_id', 'member_id', 'client_id']);
        $nameCol = firstExistingColumn($pdo, $table, ['full_name', 'name', 'client_name', 'member_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $userId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function findBooking(PDO $pdo, int $bookingId): ?array
{
    $table = bookingBaseTable($pdo);
    if ($table === null) {
        return null;
    }

    $idCol = firstExistingColumn($pdo, $table, ['id', 'booking_id', 'walk_id']);
    if ($idCol === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idCol} = :id LIMIT 1");
    if (!safeExecute($stmt, [':id' => $bookingId])) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $petId = (int) valueFromRow($row, ['pet_id', 'dog_id'], 0);
    $userId = (int) valueFromRow($row, ['user_id', 'member_id', 'client_id', 'owner_id'], 0);

    return [
        '_table' => $table,
        '_id_col' => $idCol,
        '_raw' => $row,
        'id' => (int) valueFromRow($row, [$idCol], $bookingId),
        'user_id' => $userId,
        'worker_id' => (int) valueFromRow($row, ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'], 0),
        'pet_name' => (string) valueFromRow($row, ['pet_name', 'dog_name', 'name'], $petId > 0 ? loadPetNameById($pdo, $petId) : ''),
        'client_name' => (string) valueFromRow($row, ['client_name', 'owner_name', 'member_name', 'customer_name'], $userId > 0 ? loadClientName($pdo, $userId) : ''),
        'service_type' => normalizeServiceType((string) valueFromRow($row, ['service_type', 'type', 'booking_type', 'category'], 'service')),
        'status' => normalizeStatus((string) valueFromRow($row, ['status', 'booking_status', 'service_status', 'walk_status'], 'pending')),
        'service_date' => (string) valueFromRow($row, ['service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'], ''),
        'service_time' => (string) valueFromRow($row, ['service_time', 'booking_time', 'walk_time', 'time', 'start_time', 'scheduled_time'], ''),
        'duration' => (string) valueFromRow($row, ['duration_minutes', 'duration', 'minutes'], ''),
        'notes' => (string) valueFromRow($row, ['notes', 'special_instructions', 'instructions', 'care_notes'], ''),
    ];
}

function getWalkSessionTable(PDO $pdo): ?string
{
    foreach (['walk_sessions', 'tracking_sessions'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function getTrackingPointTable(PDO $pdo): ?string
{
    foreach (['tracking_points', 'walk_points', 'location_updates', 'gps_points'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function loadTrackingSession(PDO $pdo, array $booking): ?array
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return null;
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    $bookingIdCol = firstExistingColumn($pdo, $sessionTable, ['booking_id', 'walk_id']);
    if ($sessionIdCol === null || $bookingIdCol === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$sessionTable} WHERE {$bookingIdCol} = :booking_id ORDER BY {$sessionIdCol} DESC LIMIT 1");
    if (!safeExecute($stmt, [':booking_id' => (int) $booking['id']])) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getLatestTrackingSnapshot(PDO $pdo, array $booking): array
{
    $snapshot = [
        'session_status' => null,
        'started_at' => null,
        'ended_at' => null,
        'latitude' => null,
        'longitude' => null,
        'accuracy' => null,
        'last_ping_at' => null,
    ];

    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return $snapshot;
    }

    $session = loadTrackingSession($pdo, $booking);
    if (!$session) {
        return $snapshot;
    }

    $snapshot['session_status'] = normalizeStatus((string) valueFromRow($session, ['status', 'walk_status', 'service_status'], ''));
    $snapshot['started_at'] = (string) valueFromRow($session, ['started_at'], '');
    $snapshot['ended_at'] = (string) valueFromRow($session, ['ended_at'], '');
    $snapshot['latitude'] = valueFromRow($session, ['latitude', 'lat', 'current_latitude', 'current_lat'], null);
    $snapshot['longitude'] = valueFromRow($session, ['longitude', 'lng', 'lon', 'current_longitude', 'current_lng'], null);
    $snapshot['accuracy'] = valueFromRow($session, ['accuracy', 'gps_accuracy'], null);
    $snapshot['last_ping_at'] = (string) valueFromRow($session, ['updated_at', 'last_ping_at', 'last_location_at'], '');

    return $snapshot;
}

function getTrackingHistory(PDO $pdo, array $booking, int $limit = 200): array
{
    $pointTable = getTrackingPointTable($pdo);
    if ($pointTable === null) {
        return [];
    }

    $bookingIdCol = firstExistingColumn($pdo, $pointTable, ['booking_id', 'walk_id']);
    $latCol = firstExistingColumn($pdo, $pointTable, ['latitude', 'lat']);
    $lngCol = firstExistingColumn($pdo, $pointTable, ['longitude', 'lng', 'lon']);
    $accuracyCol = firstExistingColumn($pdo, $pointTable, ['accuracy', 'gps_accuracy']);
    $timeCol = firstExistingColumn($pdo, $pointTable, ['created_at', 'recorded_at', 'timestamp']);

    if ($bookingIdCol === null || $latCol === null || $lngCol === null) {
        return [];
    }

    $selectCols = [
        "{$latCol} AS lat",
        "{$lngCol} AS lng",
    ];
    if ($accuracyCol !== null) {
        $selectCols[] = "{$accuracyCol} AS accuracy";
    }
    if ($timeCol !== null) {
        $selectCols[] = "{$timeCol} AS recorded_at";
    }

    $orderCol = $timeCol ?? firstExistingColumn($pdo, $pointTable, ['id']) ?? $bookingIdCol;

    $sql = "SELECT " . implode(', ', $selectCols) . " FROM {$pointTable} WHERE {$bookingIdCol} = :booking_id ORDER BY {$orderCol} ASC LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($sql);
    if (!safeExecute($stmt, [':booking_id' => (int) $booking['id']])) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $points = [];

    foreach ($rows as $row) {
        if (!isset($row['lat'], $row['lng'])) {
            continue;
        }

        $points[] = [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'accuracy' => isset($row['accuracy']) && $row['accuracy'] !== null && $row['accuracy'] !== '' ? (float) $row['accuracy'] : null,
            'recorded_at' => (string) ($row['recorded_at'] ?? ''),
        ];
    }

    return $points;
}

function formatDisplayDateTime(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);

    if ($date === '' && $time === '') {
        return 'Not scheduled';
    }

    if ($date !== '' && $time !== '') {
        $ts = strtotime($date . ' ' . $time);
        return $ts !== false ? date('F j, Y \a\t g:i A', $ts) : ($date . ' ' . $time);
    }

    if ($date !== '') {
        $ts = strtotime($date);
        return $ts !== false ? date('F j, Y', $ts) : $date;
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
if ($bookingId <= 0) {
    redirectTo('my-bookings.php');
}

$booking = findBooking($pdo, $bookingId);
if (!$booking) {
    http_response_code(404);
    echo 'Walk booking not found.';
    exit;
}

if ($booking['service_type'] !== 'walk') {
    http_response_code(400);
    echo 'Client tracking is only available for walk services.';
    exit;
}

$currentUserId = currentUserId();
if (!isAdmin()) {
    if ($currentUserId <= 0 || (int) $booking['user_id'] !== $currentUserId) {
        http_response_code(403);
        echo 'You do not have permission to view this tracking page.';
        exit;
    }
}

$apiAction = strtolower(trim((string) ($_GET['api'] ?? '')));
if ($apiAction === 'status') {
    $freshBooking = findBooking($pdo, $bookingId) ?? $booking;
    jsonResponse([
        'ok' => true,
        'booking_status' => normalizeStatus((string) $freshBooking['status']),
        'snapshot' => getLatestTrackingSnapshot($pdo, $freshBooking),
        'points' => getTrackingHistory($pdo, $freshBooking, 500),
    ]);
}

$snapshot = getLatestTrackingSnapshot($pdo, $booking);
$points = getTrackingHistory($pdo, $booking, 500);
$bookingStatus = normalizeStatus((string) $booking['status']);
$sessionStatus = normalizeStatus((string) ($snapshot['session_status'] ?? ''));
$isActive = in_array($bookingStatus, ['accepted', 'in_progress', 'completed'], true) || in_array($sessionStatus, ['accepted', 'in_progress', 'completed'], true);
$isInProgress = $bookingStatus === 'in_progress' || $sessionStatus === 'in_progress';
$isCompleted = $bookingStatus === 'completed' || $sessionStatus === 'completed';

$startTimestamp = '';
if (!empty($snapshot['started_at'])) {
    $ts = strtotime((string) $snapshot['started_at']);
    if ($ts !== false) {
        $startTimestamp = date('c', $ts);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Walk | Doggie Dorian’s</title>
    <meta name="description" content="Client-facing live walk tracking for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        .page {
            max-width: 1320px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .brand {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: .04em;
        }
        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }
        .hero {
            display: grid;
            grid-template-columns: 1.2fr .9fr;
            gap: 20px;
            margin-bottom: 22px;
        }
        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }
        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }
        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }
        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 800;
        }
        .metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .metric {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .metric-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
            margin-bottom: 8px;
        }
        .metric-value {
            font-size: 1.4rem;
            font-weight: 900;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 20px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .detail {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .detail-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
            margin-bottom: 8px;
        }
        .detail-value {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.5;
            word-break: break-word;
        }
        .map-shell {
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            background: #101019;
            min-height: 420px;
            position: relative;
        }
        #map {
            width: 100%;
            min-height: 420px;
        }
        .map-fallback {
            min-height: 420px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(244,241,234,0.72);
            padding: 24px;
            text-align: center;
            line-height: 1.6;
        }
        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(109,174,255,0.14);
            border: 1px solid rgba(109,174,255,0.3);
            color: #d8e8ff;
            font-weight: 700;
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .muted {
            color: rgba(244,241,234,0.58);
        }
        .status-live { color: #d8e8ff; }
        .status-done { color: #d7f1dd; }
        .status-wait { color: #f3dfb1; }
        @media (max-width: 1100px) {
            .hero, .grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 720px) {
            .metrics, .detail-grid {
                grid-template-columns: 1fr;
            }
            h1 {
                font-size: 1.6rem;
            }
            .page {
                padding: 20px 12px 60px;
            }
        }
    </style>
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    />
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <?php if (isAdmin()): ?>
                    <a class="top-link" href="admin-bookings.php">Admin Bookings</a>
                <?php else: ?>
                    <a class="top-link" href="my-bookings.php">My Bookings</a>
                <?php endif; ?>
                <a class="top-link" href="booking-details.php?id=<?= (int) $booking['id'] ?>">Booking Details</a>
            </div>
        </div>

        <div class="flash">
            <?php if ($isCompleted): ?>
                This walk has been completed. You can still review the latest tracking information below.
            <?php elseif ($isInProgress): ?>
                Your walk is currently in progress. Live location updates will appear automatically.
            <?php elseif ($isActive): ?>
                Your walk has been accepted. Live tracking will appear once the walker starts the session.
            <?php else: ?>
                Tracking is not active yet for this walk.
            <?php endif; ?>
        </div>

        <section class="hero">
            <div class="card">
                <div class="eyebrow">Client Walk Tracking</div>
                <h1>Track Walk #<?= (int) $booking['id'] ?></h1>
                <div class="sub">
                    Follow the latest walk progress, view the most recent GPS position, and monitor session timing from one place.
                </div>

                <div class="pill-row">
                    <div class="pill">Client: <?= h($booking['client_name'] !== '' ? $booking['client_name'] : 'Unknown client') ?></div>
                    <div class="pill">Pet: <?= h($booking['pet_name'] !== '' ? $booking['pet_name'] : 'Unknown pet') ?></div>
                    <div class="pill">Scheduled: <?= h(formatDisplayDateTime((string) $booking['service_date'], (string) $booking['service_time'])) ?></div>
                </div>

                <div class="metrics">
                    <div class="metric">
                        <div class="metric-label">Booking Status</div>
                        <div class="metric-value" id="bookingStatusLabel">
                            <?= h(ucwords(str_replace('_', ' ', $bookingStatus))) ?>
                        </div>
                    </div>

                    <div class="metric">
                        <div class="metric-label">Session Status</div>
                        <div class="metric-value" id="sessionStatusLabel">
                            <?= h(ucwords(str_replace('_', ' ', $sessionStatus !== '' ? $sessionStatus : 'not_started'))) ?>
                        </div>
                    </div>

                    <div class="metric">
                        <div class="metric-label">Elapsed Time</div>
                        <div class="metric-value mono" id="elapsedTimer">00:00:00</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Live Snapshot</div>
                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Latitude</div>
                        <div class="detail-value mono" id="latLabel"><?= h($snapshot['latitude'] !== null ? (string) $snapshot['latitude'] : '—') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Longitude</div>
                        <div class="detail-value mono" id="lngLabel"><?= h($snapshot['longitude'] !== null ? (string) $snapshot['longitude'] : '—') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">GPS Accuracy</div>
                        <div class="detail-value mono" id="accuracyLabel">
                            <?= h($snapshot['accuracy'] !== null && $snapshot['accuracy'] !== '' ? (string) $snapshot['accuracy'] . ' m' : '—') ?>
                        </div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Last Ping</div>
                        <div class="detail-value mono" id="lastPingLabel">
                            <?= h($snapshot['last_ping_at'] !== null && $snapshot['last_ping_at'] !== '' ? (string) $snapshot['last_ping_at'] : 'Waiting') ?>
                        </div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Started At</div>
                        <div class="detail-value mono" id="startedAtLabel">
                            <?= h($snapshot['started_at'] !== null && $snapshot['started_at'] !== '' ? (string) $snapshot['started_at'] : 'Not started') ?>
                        </div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Ended At</div>
                        <div class="detail-value mono" id="endedAtLabel">
                            <?= h($snapshot['ended_at'] !== null && $snapshot['ended_at'] !== '' ? (string) $snapshot['ended_at'] : 'Not completed') ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid">
            <div class="card">
                <div class="eyebrow">Live Map</div>
                <div class="map-shell">
                    <div id="map"></div>
                    <div id="mapFallback" class="map-fallback hidden">
                        Waiting for the first live GPS point. The map will appear as soon as location data is available.
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Walk Details</div>
                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Booking ID</div>
                        <div class="detail-value">#<?= (int) $booking['id'] ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Duration</div>
                        <div class="detail-value"><?= h((string) $booking['duration'] !== '' ? (string) $booking['duration'] . ' min' : 'Not specified') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Tracking Points</div>
                        <div class="detail-value" id="pointsCountLabel"><?= (int) count($points) ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Live State</div>
                        <div class="detail-value" id="liveStateLabel">
                            <?php if ($isInProgress): ?>
                                <span class="status-live">Live now</span>
                            <?php elseif ($isCompleted): ?>
                                <span class="status-done">Completed</span>
                            <?php else: ?>
                                <span class="status-wait">Waiting to start</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="detail" style="margin-top:14px;">
                    <div class="detail-label">Care Notes</div>
                    <div class="detail-value"><?= h($booking['notes'] !== '' ? (string) $booking['notes'] : 'No special care notes were provided for this walk.') ?></div>
                </div>
            </div>
        </section>
    </div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script>
        const bookingId = <?= (int) $booking['id'] ?>;
        let startIso = <?= json_encode($startTimestamp) ?>;
        let bookingStatus = <?= json_encode($bookingStatus) ?>;
        let sessionStatus = <?= json_encode($sessionStatus) ?>;
        let points = <?= json_encode($points, JSON_UNESCAPED_SLASHES) ?>;

        const bookingStatusLabel = document.getElementById('bookingStatusLabel');
        const sessionStatusLabel = document.getElementById('sessionStatusLabel');
        const elapsedTimer = document.getElementById('elapsedTimer');
        const latLabel = document.getElementById('latLabel');
        const lngLabel = document.getElementById('lngLabel');
        const accuracyLabel = document.getElementById('accuracyLabel');
        const lastPingLabel = document.getElementById('lastPingLabel');
        const startedAtLabel = document.getElementById('startedAtLabel');
        const endedAtLabel = document.getElementById('endedAtLabel');
        const pointsCountLabel = document.getElementById('pointsCountLabel');
        const liveStateLabel = document.getElementById('liveStateLabel');
        const mapElement = document.getElementById('map');
        const mapFallback = document.getElementById('mapFallback');

        let map = null;
        let polyline = null;
        let marker = null;

        function titleCaseStatus(value) {
            return String(value || 'not_started')
                .replaceAll('_', ' ')
                .replace(/\b\w/g, c => c.toUpperCase());
        }

        function formatElapsed(seconds) {
            const hrs = String(Math.floor(seconds / 3600)).padStart(2, '0');
            const mins = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
            const secs = String(seconds % 60).padStart(2, '0');
            return `${hrs}:${mins}:${secs}`;
        }

        function updateTimer() {
            const liveLike = ['in_progress', 'completed'].includes(sessionStatus) || ['in_progress', 'completed'].includes(bookingStatus);

            if (!liveLike || !startIso) {
                elapsedTimer.textContent = '00:00:00';
                return;
            }

            const startedMs = new Date(startIso).getTime();
            if (Number.isNaN(startedMs)) {
                elapsedTimer.textContent = '00:00:00';
                return;
            }

            const nowMs = Date.now();
            const diffSeconds = Math.max(0, Math.floor((nowMs - startedMs) / 1000));
            elapsedTimer.textContent = formatElapsed(diffSeconds);
        }

        function ensureMap() {
            if (map) return;

            map = L.map('map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
        }

        function renderMap() {
            if (!Array.isArray(points) || points.length === 0) {
                mapElement.classList.add('hidden');
                mapFallback.classList.remove('hidden');
                return;
            }

            mapFallback.classList.add('hidden');
            mapElement.classList.remove('hidden');

            ensureMap();

            const latlngs = points
                .filter(p => typeof p.lat === 'number' && typeof p.lng === 'number')
                .map(p => [p.lat, p.lng]);

            if (latlngs.length === 0) {
                mapElement.classList.add('hidden');
                mapFallback.classList.remove('hidden');
                return;
            }

            if (polyline) {
                polyline.remove();
            }
            if (marker) {
                marker.remove();
            }

            polyline = L.polyline(latlngs).addTo(map);
            marker = L.marker(latlngs[latlngs.length - 1]).addTo(map);

            map.fitBounds(polyline.getBounds(), { padding: [30, 30] });
            setTimeout(() => map.invalidateSize(), 100);
        }

        function applySnapshot(data) {
            if (!data || !data.ok) return;

            if (data.booking_status) {
                bookingStatus = data.booking_status;
                bookingStatusLabel.textContent = titleCaseStatus(data.booking_status);
            }

            if (data.snapshot) {
                const snap = data.snapshot;

                if (snap.session_status) {
                    sessionStatus = snap.session_status;
                    sessionStatusLabel.textContent = titleCaseStatus(snap.session_status);
                } else {
                    sessionStatus = '';
                    sessionStatusLabel.textContent = 'Not Started';
                }

                if (snap.started_at) {
                    startedAtLabel.textContent = snap.started_at;
                    const parsed = new Date(snap.started_at);
                    if (!Number.isNaN(parsed.getTime())) {
                        startIso = parsed.toISOString();
                    }
                }

                endedAtLabel.textContent = snap.ended_at ? snap.ended_at : 'Not completed';
                latLabel.textContent = snap.latitude !== null && snap.latitude !== '' ? String(snap.latitude) : '—';
                lngLabel.textContent = snap.longitude !== null && snap.longitude !== '' ? String(snap.longitude) : '—';
                accuracyLabel.textContent = snap.accuracy !== null && snap.accuracy !== '' ? `${snap.accuracy} m` : '—';
                lastPingLabel.textContent = snap.last_ping_at ? snap.last_ping_at : 'Waiting';
            }

            if (Array.isArray(data.points)) {
                points = data.points;
                pointsCountLabel.textContent = String(points.length);
                renderMap();
            }

            if (bookingStatus === 'completed' || sessionStatus === 'completed') {
                liveStateLabel.innerHTML = '<span class="status-done">Completed</span>';
            } else if (bookingStatus === 'in_progress' || sessionStatus === 'in_progress') {
                liveStateLabel.innerHTML = '<span class="status-live">Live now</span>';
            } else {
                liveStateLabel.innerHTML = '<span class="status-wait">Waiting to start</span>';
            }
        }

        async function refreshStatus() {
            try {
                const response = await fetch(`client-map.php?booking_id=${bookingId}&api=status`, {
                    credentials: 'same-origin'
                });
                const data = await response.json();
                applySnapshot(data);
            } catch (error) {
                console.error(error);
            }
        }

        renderMap();
        updateTimer();
        setInterval(updateTimer, 1000);
        setInterval(() => {
            void refreshStatus();
        }, 10000);
    </script>
</body>
</html>