<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

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

function currentUserId()
{
    $keys = array('user_id', 'member_id', 'client_id', 'id');

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function isMemberLike()
{
    return currentUserRole() === 'member' || currentUserId() > 0;
}

if (!isMemberLike()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = array();

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = array();
        return array();
    } catch (Exception $e) {
        $cache[$table] = array();
        return array();
    }
}

function hasColumn(PDO $pdo, $table, $column)
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (hasColumn($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function valueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function bookingBaseTable(PDO $pdo)
{
    $candidates = array('bookings', 'walks');

    foreach ($candidates as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function normalizeStatus($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'new' || $status === 'open' || $status === 'unassigned') {
        return 'available';
    }
    if ($status === 'assigned' || $status === 'confirmed') {
        return 'accepted';
    }
    if ($status === 'active' || $status === 'walking' || $status === 'started' || $status === 'in progress') {
        return 'in_progress';
    }
    if ($status === 'done' || $status === 'finished' || $status === 'closed') {
        return 'completed';
    }
    if ($status === 'canceled' || $status === 'cancelled by client' || $status === 'cancelled by walker' || $status === 'void') {
        return 'cancelled';
    }
    if ($status === 'released back' || $status === 're-opened' || $status === 'reopened') {
        return 'released';
    }

    return $status !== '' ? $status : 'pending';
}

function normalizeServiceType($type)
{
    $type = strtolower(trim((string) $type));

    if ($type === '') {
        return 'service';
    }
    if (strpos($type, 'walk') !== false) {
        return 'walk';
    }
    if (strpos($type, 'board') !== false) {
        return 'boarding';
    }
    if (strpos($type, 'daycare') !== false || strpos($type, 'day care') !== false) {
        return 'daycare';
    }
    if (strpos($type, 'sit') !== false) {
        return 'sitting';
    }
    if (strpos($type, 'drop') !== false) {
        return 'drop-in';
    }

    return $type;
}

function serviceDisplayName($type)
{
    $type = normalizeServiceType($type);

    if ($type === 'drop-in') {
        return 'Drop-In';
    }

    return ucfirst($type);
}

function formatDateDisplay($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'Not scheduled';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function formatTimeDisplay($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return 'Time not set';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function formatMoney($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_numeric($value)) {
        return '$' . number_format((float) $value, 2);
    }
    return '$' . (string) $value;
}

function loadPetNameById(PDO $pdo, $petId)
{
    $petId = (int) $petId;

    if ($petId <= 0) {
        return '';
    }

    $tables = array('pets', 'dogs');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('name', 'pet_name', 'dog_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $petId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadWorkerName(PDO $pdo, $workerId)
{
    $workerId = (int) $workerId;

    if ($workerId <= 0) {
        return '';
    }

    $tables = array('walkers', 'staff', 'employees', 'users');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'walker_id', 'staff_id', 'employee_id', 'user_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'walker_name', 'staff_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $workerId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function countUnreadNotificationsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $readCol = firstExistingColumn($pdo, $table, array('is_read', 'read_status', 'seen', 'viewed'));
        $userCol = firstExistingColumn($pdo, $table, array('user_id'));
        $memberCol = firstExistingColumn($pdo, $table, array('member_id'));

        if ($readCol === null) {
            continue;
        }

        try {
            if ($userCol !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$userCol} = :id AND COALESCE({$readCol}, 0) = 0");
                if (safeExecute($stmt, array(':id' => $userId))) {
                    return (int) $stmt->fetchColumn();
                }
            }

            if ($memberCol !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$memberCol} = :id AND COALESCE({$readCol}, 0) = 0");
                if (safeExecute($stmt, array(':id' => $userId))) {
                    return (int) $stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            continue;
        } catch (Exception $e) {
            continue;
        }
    }

    return 0;
}

function hasActiveTracking(PDO $pdo, array $booking)
{
    if ((isset($booking['service_type']) ? $booking['service_type'] : '') !== 'walk') {
        return false;
    }

    $status = normalizeStatus((string) (isset($booking['status']) ? $booking['status'] : ''));
    if (in_array($status, array('accepted', 'in_progress', 'completed'), true)) {
        return true;
    }

    $tables = array('walk_sessions', 'tracking_sessions');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $bookingCol = firstExistingColumn($pdo, $table, array('booking_id', 'walk_id'));
        if ($bookingCol === null) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$bookingCol} = :id ORDER BY rowid DESC LIMIT 1");
            if (!safeExecute($stmt, array(':id' => (int) (isset($booking['id']) ? $booking['id'] : 0)))) {
                continue;
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return true;
            }
        } catch (Throwable $e) {
            continue;
        } catch (Exception $e) {
            continue;
        }
    }

    return false;
}

function statusBadgeClass($status)
{
    if ($status === 'accepted') {
        return 'badge-accepted';
    }
    if ($status === 'in_progress') {
        return 'badge-progress';
    }
    if ($status === 'completed') {
        return 'badge-complete';
    }
    if ($status === 'cancelled') {
        return 'badge-cancelled';
    }
    if ($status === 'released') {
        return 'badge-released';
    }
    if ($status === 'available') {
        return 'badge-available';
    }

    return 'badge-pending';
}

function fetchMemberBookings(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $table = bookingBaseTable($pdo);

    if ($table === null || $userId <= 0) {
        return array();
    }

    $userCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'client_id', 'owner_id'));
    if ($userCol === null) {
        return array();
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$userCol} = :user_id ORDER BY rowid DESC");
    if (!safeExecute($stmt, array(':user_id' => $userId))) {
        return array();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $normalized = array();

    foreach ($rows as $row) {
        $id = (int) valueFromRow($row, array('id', 'booking_id', 'walk_id'), 0);
        if ($id <= 0) {
            continue;
        }

        $workerId = (int) valueFromRow($row, array('walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'), 0);
        $petId = (int) valueFromRow($row, array('pet_id', 'dog_id'), 0);

        $petName = (string) valueFromRow($row, array('pet_name', 'dog_name', 'name'), '');
        if ($petName === '' && $petId > 0) {
            $petName = loadPetNameById($pdo, $petId);
        }

        $normalized[] = array(
            'id' => $id,
            'worker_id' => $workerId,
            'worker_name' => $workerId > 0 ? loadWorkerName($pdo, $workerId) : '',
            'service_type' => normalizeServiceType((string) valueFromRow($row, array('service_type', 'type', 'booking_type', 'category'), 'service')),
            'status' => normalizeStatus((string) valueFromRow($row, array('status', 'booking_status', 'service_status', 'walk_status'), 'pending')),
            'service_date' => (string) valueFromRow($row, array('service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'), ''),
            'service_time' => (string) valueFromRow($row, array('service_time', 'booking_time', 'walk_time', 'time', 'start_time', 'scheduled_time'), ''),
            'duration' => (string) valueFromRow($row, array('duration_minutes', 'duration', 'minutes'), ''),
            'price' => valueFromRow($row, array('price', 'total_price', 'amount'), ''),
            'notes' => (string) valueFromRow($row, array('notes', 'special_instructions', 'instructions', 'care_notes'), ''),
            'pet_name' => $petName,
        );
    }

    return $normalized;
}

function sortBookingsByDate(array $rows)
{
    usort($rows, function ($a, $b) {
        $aTs = strtotime(trim((isset($a['service_date']) ? $a['service_date'] : '') . ' ' . (isset($a['service_time']) ? $a['service_time'] : '')));
        $bTs = strtotime(trim((isset($b['service_date']) ? $b['service_date'] : '') . ' ' . (isset($b['service_time']) ? $b['service_time'] : '')));

        if ($aTs === false) {
            $aTs = PHP_INT_MAX;
        }
        if ($bTs === false) {
            $bTs = PHP_INT_MAX;
        }

        if ($aTs === $bTs) {
            return 0;
        }

        return $aTs < $bTs ? -1 : 1;
    });

    return $rows;
}

$userId = currentUserId();
if ($userId <= 0) {
    redirectTo('login.php');
}

$flash = isset($_SESSION['dashboard_flash']) ? (string) $_SESSION['dashboard_flash'] : '';
unset($_SESSION['dashboard_flash']);

$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);
$bookings = fetchMemberBookings($pdo, $userId);
$bookings = sortBookingsByDate($bookings);

$allCount = count($bookings);
$walkCount = 0;
$activeCount = 0;
$completedCount = 0;

foreach ($bookings as $booking) {
    if ($booking['service_type'] === 'walk') {
        $walkCount++;
    }
    if (in_array($booking['status'], array('pending', 'available', 'accepted', 'in_progress', 'released'), true)) {
        $activeCount++;
    }
    if ($booking['status'] === 'completed') {
        $completedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Doggie Dorian’s</title>
    <meta name="description" content="View and manage your Doggie Dorian’s bookings.">
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
            max-width: 1380px;
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
            grid-template-columns: 1.15fr 0.85fr;
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

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
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

        h2 {
            margin: 0 0 10px;
            font-size: 1.25rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.30);
            color: #f3e5c2;
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.45rem;
            font-weight: 900;
        }

        .cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            transition: transform .15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        .booking-list {
            display: grid;
            gap: 14px;
            margin-top: 22px;
        }

        .booking-card {
            display: grid;
            gap: 14px;
        }

        .booking-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .booking-title {
            font-size: 1.05rem;
            font-weight: 900;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .meta-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .meta-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .meta-value {
            font-size: .95rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .detail-copy {
            color: rgba(244,241,234,0.74);
            line-height: 1.65;
        }

        .row-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mini-link {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .85rem;
            font-weight: 800;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 900;
            letter-spacing: .02em;
            text-transform: capitalize;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }
        .badge-released { background: rgba(172,145,255,0.18); color: #e1d6ff; }

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 1100px) {
            .hero,
            .meta-grid,
            .stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 760px) {
            .hero,
            .meta-grid,
            .stats {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .cta-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>

            <div class="top-links">
                <a class="top-link" href="dashboard.php">Dashboard</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="ambassadors.php">Ambassadors</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="profile.php">Profile</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo h($flash); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Member Bookings</div>
                <h1>My Bookings</h1>
                <div class="sub">
                    Review your services, check live walk visibility when available, and keep all scheduling under one member booking flow.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">All Bookings</div>
                        <div class="stat-value"><?php echo (int) $allCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Walks</div>
                        <div class="stat-value"><?php echo (int) $walkCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Active</div>
                        <div class="stat-value"><?php echo (int) $activeCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Completed</div>
                        <div class="stat-value"><?php echo (int) $completedCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Next Step</div>
                <h2>Need to schedule something new?</h2>
                <div class="sub">
                    Use the unified booking page to create walks, boarding, daycare, sitting, and other service requests from one place.
                </div>

                <div class="cta-row">
                    <a class="btn btn-gold" href="book-service.php">Book Service</a>
                    <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                </div>
            </div>
        </section>

        <section class="booking-list">
            <?php if (empty($bookings)): ?>
                <div class="card">
                    <div class="empty">
                        You do not have any bookings yet. Use <strong>Book Service</strong> to schedule your first service.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($bookings as $row): ?>
                    <div class="card booking-card">
                        <div class="booking-top">
                            <div class="booking-title">
                                #<?php echo (int) $row['id']; ?> · <?php echo h(serviceDisplayName($row['service_type'])); ?> · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Pet not listed'); ?>
                            </div>

                            <span class="badge <?php echo h(statusBadgeClass($row['status'])); ?>">
                                <?php echo h(ucwords(str_replace('_', ' ', $row['status']))); ?>
                            </span>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Date</div>
                                <div class="meta-value"><?php echo h(formatDateDisplay($row['service_date'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Time</div>
                                <div class="meta-value"><?php echo h(formatTimeDisplay($row['service_time'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Walker</div>
                                <div class="meta-value"><?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Awaiting assignment'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Price</div>
                                <div class="meta-value"><?php echo h(formatMoney($row['price'])); ?></div>
                            </div>
                        </div>

                        <?php if ((string) $row['notes'] !== ''): ?>
                            <div class="detail-copy">
                                <strong style="color:#f3e5c7;">Notes:</strong>
                                <?php echo h($row['notes']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="row-links">
                            <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>

                            <?php if ($row['service_type'] === 'walk' && hasActiveTracking($pdo, $row)): ?>
                                <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">Track Walk</a>
                            <?php endif; ?>

                            <a class="mini-link" href="book-service.php">Book Another Service</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>