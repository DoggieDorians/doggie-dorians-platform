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

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    return isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
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

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
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

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    $columns = getTableColumns($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
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

function safeFetchAll(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return array();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    } catch (Exception $e) {
        return array();
    }
}

function safeFetchOne(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
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
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'void') {
        return 'cancelled';
    }

    return $status !== '' ? $status : 'pending';
}

function normalizePublicStatus($status)
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'confirmed', 'completed', 'cancelled');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'new';
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
        return '—';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function formatTimeDisplay($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return '—';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function formatDateTimeDisplay($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);
    return $ts !== false ? date('F j, Y \a\t g:i A', $ts) : $value;
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

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function bookingBaseTable(PDO $pdo)
{
    foreach (array('bookings', 'walks') as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function fetchMemberBookings(PDO $pdo)
{
    $table = bookingBaseTable($pdo);
    if ($table === null) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM ' . $table . ' ORDER BY rowid DESC');
    $normalized = array();

    foreach ($rows as $row) {
        $normalized[] = array(
            'source' => 'member',
            'id' => (int) valueFromRow($row, array('id', 'booking_id', 'walk_id'), 0),
            'client_name' => (string) valueFromRow($row, array('client_name', 'owner_name', 'member_name', 'customer_name', 'full_name', 'name'), 'Member Client'),
            'service_type' => normalizeServiceType((string) valueFromRow($row, array('service_type', 'type', 'booking_type', 'category', 'service'), 'service')),
            'service_date' => (string) valueFromRow($row, array('service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date'), ''),
            'service_time' => (string) valueFromRow($row, array('service_time', 'booking_time', 'walk_time', 'time', 'scheduled_time', 'start_time'), ''),
            'pet_name' => (string) valueFromRow($row, array('pet_name', 'dog_name'), ''),
            'price' => valueFromRow($row, array('price', 'total_price', 'amount'), ''),
            'status' => normalizeStatus((string) valueFromRow($row, array('status', 'booking_status', 'service_status', 'walk_status'), 'pending')),
            'notes' => (string) valueFromRow($row, array('notes', 'special_instructions', 'instructions', 'care_notes', 'client_notes'), ''),
            'created_at' => (string) valueFromRow($row, array('created_at'), ''),
            'raw' => $row,
        );
    }

    return $normalized;
}

function fetchPublicBookings(PDO $pdo)
{
    if (!hasTable($pdo, 'non_member_bookings')) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM non_member_bookings ORDER BY rowid DESC');
    $normalized = array();

    foreach ($rows as $row) {
        $normalized[] = array(
            'source' => 'public',
            'id' => (int) valueFromRow($row, array('id'), 0),
            'client_name' => (string) valueFromRow($row, array('full_name', 'name'), 'Public Client'),
            'email' => (string) valueFromRow($row, array('email'), ''),
            'phone' => (string) valueFromRow($row, array('phone'), ''),
            'service_type' => normalizeServiceType((string) valueFromRow($row, array('service_type', 'service'), 'service')),
            'service_date' => (string) valueFromRow($row, array('service_date', 'date'), ''),
            'service_time' => (string) valueFromRow($row, array('service_time', 'time'), ''),
            'pet_name' => (string) valueFromRow($row, array('pet_name'), ''),
            'pet_breed' => (string) valueFromRow($row, array('pet_breed', 'breed'), ''),
            'pet_size' => (string) valueFromRow($row, array('pet_size', 'size'), ''),
            'price' => '',
            'status' => normalizePublicStatus((string) valueFromRow($row, array('status'), 'new')),
            'notes' => (string) valueFromRow($row, array('notes'), ''),
            'created_at' => (string) valueFromRow($row, array('created_at'), ''),
            'raw' => $row,
        );
    }

    return $normalized;
}

function statusBadgeClass($status)
{
    if ($status === 'accepted' || $status === 'confirmed') {
        return 'badge-accepted';
    }
    if ($status === 'in_progress' || $status === 'reviewed') {
        return 'badge-progress';
    }
    if ($status === 'completed') {
        return 'badge-complete';
    }
    if ($status === 'cancelled') {
        return 'badge-cancelled';
    }
    if ($status === 'available' || $status === 'new') {
        return 'badge-available';
    }

    return 'badge-pending';
}

$flash = isset($_SESSION['admin_bookings_flash']) ? (string) $_SESSION['admin_bookings_flash'] : '';
$flashType = isset($_SESSION['admin_bookings_flash_type']) ? (string) $_SESSION['admin_bookings_flash_type'] : '';
unset($_SESSION['admin_bookings_flash'], $_SESSION['admin_bookings_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'update_public_booking_status') {
        $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        $status = normalizePublicStatus(isset($_POST['status']) ? $_POST['status'] : 'new');

        if ($bookingId <= 0) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Invalid booking selected.';
            redirectTo('admin-bookings.php');
        }

        if (!hasTable($pdo, 'non_member_bookings')) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Public booking table was not found.';
            redirectTo('admin-bookings.php');
        }

        $stmt = $pdo->prepare('UPDATE non_member_bookings SET status = :status WHERE id = :id');
        $ok = safeExecute($stmt, array(':status' => $status, ':id' => $bookingId));

        if ($ok) {
            $_SESSION['admin_bookings_flash_type'] = 'success';
            $_SESSION['admin_bookings_flash'] = 'Public booking updated successfully.';
        } else {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Could not update the public booking.';
        }

        redirectTo('admin-bookings.php');
    }
}

$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : 'all';
if (!in_array($view, array('all', 'member', 'public'), true)) {
    $view = 'all';
}

$memberBookings = fetchMemberBookings($pdo);
$publicBookings = fetchPublicBookings($pdo);

$allBookings = array_merge($memberBookings, $publicBookings);

usort($allBookings, function ($a, $b) {
    $aDate = trim((isset($a['created_at']) ? $a['created_at'] : '') . ' ' . (isset($a['service_date']) ? $a['service_date'] : ''));
    $bDate = trim((isset($b['created_at']) ? $b['created_at'] : '') . ' ' . (isset($b['service_date']) ? $b['service_date'] : ''));

    $aTs = strtotime($aDate);
    $bTs = strtotime($bDate);

    if ($aTs === false) {
        $aTs = 0;
    }
    if ($bTs === false) {
        $bTs = 0;
    }

    if ($aTs === $bTs) {
        return 0;
    }

    return $aTs > $bTs ? -1 : 1;
});

$displayBookings = $allBookings;
if ($view === 'member') {
    $displayBookings = $memberBookings;
} elseif ($view === 'public') {
    $displayBookings = $publicBookings;
}

$totalCount = count($allBookings);
$memberCount = count($memberBookings);
$publicCount = count($publicBookings);

$newPublicCount = 0;
foreach ($publicBookings as $booking) {
    if ($booking['status'] === 'new') {
        $newPublicCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bookings | Doggie Dorian’s</title>
    <meta name="description" content="Manage member and non-member bookings.">
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
            grid-template-columns: 1.08fr 0.92fr;
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
            font-weight: 700;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
            color: #d7f1dd;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
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
            font-size: 1.3rem;
            font-weight: 900;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .filter-pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 700;
        }

        .filter-pill.active {
            background: rgba(198,178,139,0.16);
            border-color: rgba(198,178,139,0.32);
            color: #f3e5c7;
        }

        .booking-list {
            display: grid;
            gap: 16px;
            margin-top: 22px;
        }

        .booking-card {
            display: grid;
            gap: 16px;
        }

        .booking-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .booking-title {
            font-size: 1.08rem;
            font-weight: 900;
        }

        .pill-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
            background: rgba(255,255,255,0.08);
            color: #f5f3ef;
        }

        .pill.member {
            background: rgba(109,174,255,0.16);
            color: #d0e4ff;
        }

        .pill.public {
            background: rgba(198,178,139,0.16);
            color: #f3e5c7;
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
            white-space: pre-wrap;
        }

        form {
            display: grid;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 14px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 8px;
            color: rgba(244,241,234,0.78);
        }

        select {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            border: none;
            cursor: pointer;
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

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 1100px) {
            .hero,
            .stats,
            .meta-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
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

            .btn-row {
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
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-group-walk-applications.php">Group Walk Applications</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Booking Control</div>
                <h1>Admin Bookings</h1>
                <div class="sub">
                    Review both member and public booking activity from one launch-ready dashboard page.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">All Bookings</div>
                        <div class="stat-value"><?php echo (int) $totalCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Member</div>
                        <div class="stat-value"><?php echo (int) $memberCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Public</div>
                        <div class="stat-value"><?php echo (int) $publicCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">New Public</div>
                        <div class="stat-value"><?php echo (int) $newPublicCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Filter View</div>
                <h2>Choose what to review</h2>
                <div class="sub">
                    Switch between all bookings, member bookings, or public non-member bookings.
                </div>

                <div class="filter-row">
                    <a class="filter-pill <?php echo $view === 'all' ? 'active' : ''; ?>" href="admin-bookings.php?view=all">All</a>
                    <a class="filter-pill <?php echo $view === 'member' ? 'active' : ''; ?>" href="admin-bookings.php?view=member">Member</a>
                    <a class="filter-pill <?php echo $view === 'public' ? 'active' : ''; ?>" href="admin-bookings.php?view=public">Public</a>
                </div>
            </div>
        </section>

        <section class="booking-list">
            <?php if (empty($displayBookings)): ?>
                <div class="card">
                    <div class="empty">No bookings match this view yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($displayBookings as $booking): ?>
                    <div class="card booking-card">
                        <div class="booking-top">
                            <div class="booking-title">
                                #<?php echo (int) $booking['id']; ?> · <?php echo h(serviceDisplayName($booking['service_type'])); ?> · <?php echo h($booking['client_name']); ?>
                            </div>

                            <div class="pill-row">
                                <span class="pill <?php echo $booking['source'] === 'member' ? 'member' : 'public'; ?>">
                                    <?php echo $booking['source'] === 'member' ? 'Member Booking' : 'Public Booking'; ?>
                                </span>
                                <span class="badge <?php echo h(statusBadgeClass($booking['status'])); ?>">
                                    <?php echo h(ucwords(str_replace('_', ' ', $booking['status']))); ?>
                                </span>
                            </div>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Service Date</div>
                                <div class="meta-value"><?php echo h(formatDateDisplay($booking['service_date'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Service Time</div>
                                <div class="meta-value"><?php echo h(formatTimeDisplay($booking['service_time'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Pet</div>
                                <div class="meta-value"><?php echo h($booking['pet_name'] !== '' ? $booking['pet_name'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Created</div>
                                <div class="meta-value"><?php echo h(formatDateTimeDisplay($booking['created_at'])); ?></div>
                            </div>

                            <?php if ($booking['source'] === 'public'): ?>
                                <div class="meta-box">
                                    <div class="meta-label">Email</div>
                                    <div class="meta-value"><?php echo h(isset($booking['email']) ? $booking['email'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Phone</div>
                                    <div class="meta-value"><?php echo h(isset($booking['phone']) ? $booking['phone'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Breed</div>
                                    <div class="meta-value"><?php echo h(isset($booking['pet_breed']) ? $booking['pet_breed'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Size</div>
                                    <div class="meta-value"><?php echo h(isset($booking['pet_size']) ? $booking['pet_size'] : '—'); ?></div>
                                </div>
                            <?php else: ?>
                                <div class="meta-box">
                                    <div class="meta-label">Price</div>
                                    <div class="meta-value"><?php echo h(formatMoney($booking['price'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (trim((string) $booking['notes']) !== ''): ?>
                            <div class="detail-copy">
                                <strong style="color:#f3e5c7;">Notes:</strong>
                                <?php echo h($booking['notes']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($booking['source'] === 'public'): ?>
                            <form method="post" action="admin-bookings.php">
                                <input type="hidden" name="action" value="update_public_booking_status">
                                <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">

                                <div class="form-grid">
                                    <div>
                                        <label for="status_<?php echo (int) $booking['id']; ?>">Public Booking Status</label>
                                        <select id="status_<?php echo (int) $booking['id']; ?>" name="status">
                                            <option value="new" <?php echo $booking['status'] === 'new' ? 'selected' : ''; ?>>New</option>
                                            <option value="reviewed" <?php echo $booking['status'] === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                            <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>

                                    <div class="btn-row" style="align-items:end;">
                                        <button type="submit" class="btn btn-gold">Save Status</button>
                                        <?php if (isset($booking['email']) && trim((string) $booking['email']) !== ''): ?>
                                            <a class="btn btn-light" href="mailto:<?php echo h($booking['email']); ?>">Email Client</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="btn-row">
                                <a class="btn btn-light" href="admin-dashboard.php">Back to Dashboard</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>