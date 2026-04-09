<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * dashboard.php
 *
 * Full replacement
 * Upgraded schema-tolerant member dashboard
 */

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

function isAdmin()
{
    return currentUserRole() === 'admin';
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

if (!isMemberLike() && !isAdmin()) {
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

function valueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
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
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'cancelled by client' || $status === 'cancelled by walker' || $status === 'void') {
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

function loadMemberName(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return '';
    }

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'client_name', 'member_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
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

    $orderCol = firstExistingColumn($pdo, $table, array(
        'service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'created_at', 'id'
    ));
    if ($orderCol === null) {
        $orderCol = 'id';
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$userCol} = :user_id ORDER BY {$orderCol} ASC, rowid DESC");
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
            'user_id' => $userId,
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

function countMemberPets(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return 0;
    }

    $tables = array('pets', 'dogs');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $ownerCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'owner_id', 'client_id'));
        if ($ownerCol === null) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$ownerCol} = :id");
            if (safeExecute($stmt, array(':id' => $userId))) {
                return (int) $stmt->fetchColumn();
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

function isFutureOrToday($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return false;
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return false;
    }

    return date('Y-m-d', $ts) >= date('Y-m-d');
}

function sortBookings(array $rows)
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

$userId = currentUserId();
if ($userId <= 0 && !isAdmin()) {
    redirectTo('login.php');
}

$memberName = loadMemberName($pdo, $userId);
$memberName = $memberName !== '' ? $memberName : 'Member';

$flash = isset($_SESSION['dashboard_flash']) ? $_SESSION['dashboard_flash'] : '';
unset($_SESSION['dashboard_flash']);

$bookings = fetchMemberBookings($pdo, $userId);
$petCount = countMemberPets($pdo, $userId);
$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);

$statusCounts = array(
    'pending' => 0,
    'available' => 0,
    'accepted' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'released' => 0,
);

$serviceCounts = array(
    'walk' => 0,
    'boarding' => 0,
    'daycare' => 0,
    'sitting' => 0,
    'drop-in' => 0,
    'service' => 0,
);

$upcoming = array();
$activeWalks = array();
$recentCompleted = array();

foreach ($bookings as $booking) {
    if (isset($statusCounts[$booking['status']])) {
        $statusCounts[$booking['status']]++;
    }

    if (isset($serviceCounts[$booking['service_type']])) {
        $serviceCounts[$booking['service_type']]++;
    } else {
        $serviceCounts['service']++;
    }

    if (isFutureOrToday((string) $booking['service_date']) && !in_array($booking['status'], array('completed', 'cancelled'), true)) {
        $upcoming[] = $booking;
    }

    if ($booking['service_type'] === 'walk' && hasActiveTracking($pdo, $booking)) {
        $activeWalks[] = $booking;
    }

    if ($booking['status'] === 'completed') {
        $recentCompleted[] = $booking;
    }
}

$upcoming = array_slice(sortBookings($upcoming), 0, 6);
$activeWalks = array_slice(sortBookings($activeWalks), 0, 4);
$recentCompleted = array_slice(array_reverse(sortBookings($recentCompleted)), 0, 4);

$totalBookings = count($bookings);
$activeServices = $statusCounts['pending'] + $statusCounts['available'] + $statusCounts['accepted'] + $statusCounts['in_progress'] + $statusCounts['released'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Doggie Dorian’s</title>
    <meta name="description" content="Member dashboard for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1460px;
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
            grid-template-columns: 1.2fr 1fr;
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

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .metric {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .metric-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 900;
        }

        .chips {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .chip {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 700;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .quick-link {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.07);
            display: grid;
            gap: 8px;
        }

        .quick-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .quick-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: .92rem;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.3);
            color: #f3e5c2;
            font-weight: 700;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel-title {
            font-size: 1.12rem;
            font-weight: 900;
            margin-bottom: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .stat-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-name {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.56);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 900;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .row {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            display: grid;
            gap: 8px;
        }

        .row-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .row-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .row-meta {
            color: rgba(244,241,234,0.68);
            font-size: .92rem;
            line-height: 1.55;
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
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }
        .badge-released { background: rgba(172,145,255,0.18); color: #e1d6ff; }

        .empty {
            padding: 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        .footer {
            margin-top: 26px;
            padding-top: 22px;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .footer-copy {
            color: rgba(244,241,234,0.56);
            font-size: .9rem;
        }

        .footer-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .footer-link {
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.07);
            font-size: .85rem;
            font-weight: 800;
            color: rgba(244,241,234,0.76);
        }

        @media (max-width: 1180px) {
            .hero,
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .metrics,
            .stats-grid,
            .quick-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.6rem;
            }

            .page {
                padding: 20px 12px 60px;
            }

            .footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="index.php">Home</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="live-tracking.php">Live Tracking</a>
                <a class="top-link" href="memberships.php">Memberships</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="profile.php">Profile</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo h($flash); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card">
                <div class="eyebrow">Member Hub</div>
                <h1>Welcome back, <?php echo h($memberName); ?></h1>
                <div class="sub">
                    Track your upcoming services, watch live walks when available, manage bookings, update your pets, and access upgrade options from one place.
                </div>

                <div class="metrics">
                    <div class="metric">
                        <div class="metric-label">Total Bookings</div>
                        <div class="metric-value"><?php echo (int) $totalBookings; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Active Services</div>
                        <div class="metric-value"><?php echo (int) $activeServices; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Pets</div>
                        <div class="metric-value"><?php echo (int) $petCount; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Unread Notices</div>
                        <div class="metric-value"><?php echo (int) $unreadNotifications; ?></div>
                    </div>
                </div>

                <div class="chips">
                    <div class="chip">Walks: <?php echo (int) $serviceCounts['walk']; ?></div>
                    <div class="chip">Boarding: <?php echo (int) $serviceCounts['boarding']; ?></div>
                    <div class="chip">Daycare: <?php echo (int) $serviceCounts['daycare']; ?></div>
                    <div class="chip">Sitting: <?php echo (int) $serviceCounts['sitting']; ?></div>
                    <div class="chip">Drop-In: <?php echo (int) $serviceCounts['drop-in']; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Quick Actions</div>
                <div class="quick-grid">
                    <a class="quick-link" href="book-service.php">
                        <div class="quick-title">Book a Service</div>
                        <div class="quick-text">Schedule walks, boarding, daycare, sitting, and more from one coordinated booking page.</div>
                    </a>

                    <a class="quick-link" href="my-bookings.php">
                        <div class="quick-title">View My Bookings</div>
                        <div class="quick-text">Review upcoming, active, and completed services with cleaner visibility.</div>
                    </a>

                    <a class="quick-link" href="live-tracking.php">
                        <div class="quick-title">Open Live Tracking</div>
                        <div class="quick-text">Go straight to live tracking and active service visibility for eligible walk bookings.</div>
                    </a>

                    <a class="quick-link" href="manage-pets.php">
                        <div class="quick-title">Manage Pets</div>
                        <div class="quick-text">Keep pet details, care notes, and profiles updated for future services.</div>
                    </a>

                    <a class="quick-link" href="add-pet.php">
                        <div class="quick-title">Add a Pet</div>
                        <div class="quick-text">Add another pet profile so new bookings stay organized and faster to complete.</div>
                    </a>

                    <a class="quick-link" href="memberships.php">
                        <div class="quick-title">Memberships</div>
                        <div class="quick-text">Explore membership options and premium service paths built for repeat clients.</div>
                    </a>

                    <a class="quick-link" href="customize-plan.php">
                        <div class="quick-title">Customize Plan</div>
                        <div class="quick-text">Build a more tailored service setup for your dog’s needs and your preferred schedule.</div>
                    </a>

                    <a class="quick-link" href="ambassadors.php">
                        <div class="quick-title">Ambassador Dashboard</div>
                        <div class="quick-text">View your referral code, track rewards, and grow with the ambassador program.</div>
                    </a>

                    <a class="quick-link" href="notifications.php">
                        <div class="quick-title">Open Notifications</div>
                        <div class="quick-text">Review service updates, live walk alerts, and booking changes in one place.</div>
                    </a>

                    <a class="quick-link" href="profile.php">
                        <div class="quick-title">Profile</div>
                        <div class="quick-text">Update your account details and keep your client profile current.</div>
                    </a>

                    <a class="quick-link" href="contact.php">
                        <div class="quick-title">Contact Support</div>
                        <div class="quick-text">Reach Doggie Dorian’s if you need help choosing services or updating care details.</div>
                    </a>

                    <a class="quick-link" href="index.php">
                        <div class="quick-title">Return to Homepage</div>
                        <div class="quick-text">Go back to the main site, services overview, and public-facing booking options.</div>
                    </a>
                </div>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="card">
                <div class="eyebrow">Status Overview</div>
                <div class="panel-title">Booking Status Snapshot</div>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-name">Pending</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['pending']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Available</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['available']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Accepted</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['accepted']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">In Progress</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['in_progress']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Completed</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['completed']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Cancelled</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['cancelled']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Released</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['released']; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Live Tracking</div>
                <div class="panel-title">Walks With Tracking Visibility</div>
                <div class="list">
                    <?php if ($activeWalks === array()): ?>
                        <div class="empty">No walk tracking is visible right now.</div>
                    <?php else: ?>
                        <?php foreach ($activeWalks as $row): ?>
                            <div class="row">
                                <div class="row-top">
                                    <div class="row-title">
                                        #<?php echo (int) $row['id']; ?> · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Walk'); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($row['status'])); ?>">
                                        <?php echo h(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                    </span>
                                </div>
                                <div class="row-meta">
                                    <?php echo h(formatDateDisplay($row['service_date'])); ?> at <?php echo h(formatTimeDisplay($row['service_time'])); ?> ·
                                    Walker: <?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Awaiting assignment'); ?>
                                </div>
                                <div class="row-links">
                                    <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">Track Walk</a>
                                    <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>
                                    <a class="mini-link" href="live-tracking.php">Tracking Hub</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Upcoming</div>
                <div class="panel-title">Upcoming Services</div>
                <div class="list">
                    <?php if ($upcoming === array()): ?>
                        <div class="empty">You do not have any upcoming services right now.</div>
                    <?php else: ?>
                        <?php foreach ($upcoming as $row): ?>
                            <div class="row">
                                <div class="row-top">
                                    <div class="row-title">
                                        #<?php echo (int) $row['id']; ?> · <?php echo h(ucfirst($row['service_type'])); ?> · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Pet not listed'); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($row['status'])); ?>">
                                        <?php echo h(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                    </span>
                                </div>
                                <div class="row-meta">
                                    <?php echo h(formatDateDisplay($row['service_date'])); ?> at <?php echo h(formatTimeDisplay($row['service_time'])); ?> ·
                                    Walker: <?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Awaiting assignment'); ?> ·
                                    Price: <?php echo h(formatMoney($row['price'])); ?>
                                </div>
                                <div class="row-links">
                                    <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>
                                    <?php if ($row['service_type'] === 'walk' && hasActiveTracking($pdo, $row)): ?>
                                        <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">Track Walk</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Recent Activity</div>
                <div class="panel-title">Recently Completed Services</div>
                <div class="list">
                    <?php if ($recentCompleted === array()): ?>
                        <div class="empty">No completed services are available yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentCompleted as $row): ?>
                            <div class="row">
                                <div class="row-top">
                                    <div class="row-title">
                                        #<?php echo (int) $row['id']; ?> · <?php echo h(ucfirst($row['service_type'])); ?> · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Pet not listed'); ?>
                                    </div>
                                    <span class="badge badge-complete">Completed</span>
                                </div>
                                <div class="row-meta">
                                    <?php echo h(formatDateDisplay($row['service_date'])); ?> at <?php echo h(formatTimeDisplay($row['service_time'])); ?> ·
                                    Walker: <?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Not listed'); ?>
                                </div>
                                <div class="row-links">
                                    <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>
                                    <?php if ($row['service_type'] === 'walk'): ?>
                                        <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">View Walk Map</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <footer class="footer">
            <div class="footer-copy">
                Doggie Dorian’s member dashboard — premium client access, live visibility, and cleaner service management.
            </div>

            <div class="footer-links">
                <a class="footer-link" href="memberships.php">Memberships</a>
                <a class="footer-link" href="customize-plan.php">Customize Plan</a>
                <a class="footer-link" href="contact.php">Contact</a>
                <a class="footer-link" href="privacy-policy.php">Privacy Policy</a>
                <a class="footer-link" href="legal-notice.php">Legal Notice</a>
            </div>
        </footer>
    </div>
</body>
</html>