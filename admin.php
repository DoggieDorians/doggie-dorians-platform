<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin.php
 *
 * Stable main admin dashboard
 * linked only to the worker/admin pages we stabilized.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_admin_session(): bool
{
    $roleCandidates = [
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['account_role'] ?? null,
        $_SESSION['account_type'] ?? null,
    ];

    foreach ($roleCandidates as $role) {
        if (is_string($role) && strtolower(trim($role)) === 'admin') {
            return true;
        }
    }

    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_logged_in'])) {
        return true;
    }

    return false;
}

if (!isset($_SESSION['user_id']) && empty($_SESSION['admin_logged_in'])) {
    redirect_to('admin-login.php');
}

if (!is_admin_session()) {
    redirect_to('login.php');
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function get_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $cache[$table] = $columns;
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function value_from_row(array $row, array $candidates, mixed $default = null): mixed
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function build_name(array $row): string
{
    $full = trim((string) value_from_row($row, [
        'full_name',
        'name',
        'display_name',
        'username',
    ], ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last  = trim((string) ($row['last_name'] ?? ''));

    $combined = trim($first . ' ' . $last);
    return $combined !== '' ? $combined : 'Unknown';
}

function format_datetime_value(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return h($raw);
    }

    return date('M j, Y g:i A', $ts);
}

function count_rows(PDO $pdo, string $table): int
{
    if (!table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable) {
        return 0;
    }
}

function count_worker_like_users(PDO $pdo): int
{
    if (!table_exists($pdo, 'users')) {
        return 0;
    }

    $columns = get_columns($pdo, 'users');
    $roleCol = first_existing_column($columns, ['role', 'user_role', 'account_role', 'account_type']);

    if ($roleCol === null) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE LOWER(TRIM(COALESCE({$roleCol}, ''))) IN ('walker', 'worker', 'staff', 'employee')
        ");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function worker_is_active(array $row): bool
{
    foreach (['is_active', 'active', 'enabled'] as $col) {
        if (array_key_exists($col, $row)) {
            return (int) $row[$col] === 1;
        }
    }

    if (array_key_exists('disabled', $row)) {
        return (int) $row['disabled'] !== 1;
    }

    foreach (['status', 'account_status', 'worker_status'] as $col) {
        if (!isset($row[$col])) {
            continue;
        }

        $value = strtolower(trim((string) $row[$col]));
        if ($value === '') {
            continue;
        }

        if (in_array($value, ['disabled', 'inactive', 'blocked', 'suspended'], true)) {
            return false;
        }

        if (in_array($value, ['active', 'enabled', 'approved'], true)) {
            return true;
        }
    }

    return true;
}

function human_status(array $row): string
{
    foreach (['status', 'account_status', 'worker_status'] as $col) {
        if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
            return ucwords(str_replace(['_', '-'], ' ', strtolower((string) $row[$col])));
        }
    }

    foreach (['is_active', 'active', 'enabled'] as $col) {
        if (array_key_exists($col, $row)) {
            return ((int) $row[$col] === 1) ? 'Active' : 'Disabled';
        }
    }

    if (array_key_exists('disabled', $row)) {
        return ((int) $row['disabled'] === 1) ? 'Disabled' : 'Active';
    }

    return 'Unknown';
}

function booking_title(array $row): string
{
    $service = trim((string) value_from_row($row, [
        'service_name',
        'service_type',
        'service',
        'booking_type',
        'type',
    ], 'Service'));

    $pet = trim((string) value_from_row($row, [
        'pet_name',
        'dog_name',
        'animal_name',
    ], ''));

    return $pet !== '' ? $service . ' • ' . $pet : $service;
}

function booking_customer(array $row): string
{
    $name = trim((string) value_from_row($row, [
        'customer_name',
        'client_name',
        'member_name',
        'owner_name',
        'user_name',
    ], ''));

    if ($name !== '') {
        return $name;
    }

    $email = trim((string) value_from_row($row, [
        'customer_email',
        'client_email',
        'member_email',
        'owner_email',
    ], ''));

    return $email !== '' ? $email : '—';
}

function booking_when(array $row): string
{
    $date = trim((string) value_from_row($row, [
        'service_date',
        'booking_date',
        'scheduled_date',
        'walk_date',
        'appointment_date',
        'date',
        'start_date',
        'scheduled_for',
        'created_at',
    ], ''));

    $time = trim((string) value_from_row($row, [
        'service_time',
        'booking_time',
        'start_time',
        'scheduled_time',
        'time',
    ], ''));

    if ($date === '') {
        return '—';
    }

    $ts = strtotime($date);
    $formatted = $ts !== false ? date('M j, Y', $ts) : $date;

    return $time !== '' ? $formatted . ' • ' . h($time) : h($formatted);
}

function classify_job(array $job): string
{
    $status = strtolower(trim((string) value_from_row($job, [
        'status',
        'booking_status',
        'walk_status',
        'job_status',
    ], '')));

    $completedAt = trim((string) value_from_row($job, [
        'completed_at',
        'ended_at',
        'finished_at',
        'actual_end_time',
    ], ''));

    $startedAt = trim((string) value_from_row($job, [
        'started_at',
        'actual_start_time',
    ], ''));

    $trackingStatus = strtolower(trim((string) value_from_row($job, [
        'tracking_status',
    ], '')));

    if ($completedAt !== '' || in_array($status, ['completed', 'complete', 'finished', 'done', 'closed', 'checked_out'], true)) {
        return 'completed';
    }

    if (
        $startedAt !== '' ||
        $trackingStatus === 'live' ||
        in_array($status, ['in_progress', 'in-progress', 'active', 'started', 'walking', 'live', 'en_route', 'en-route', 'underway'], true)
    ) {
        return 'live';
    }

    return 'assigned';
}

$totalUsers = count_rows($pdo, 'users');
$totalBookings = count_rows($pdo, 'bookings');
$totalPets = table_exists($pdo, 'pets') ? count_rows($pdo, 'pets') : (table_exists($pdo, 'dogs') ? count_rows($pdo, 'dogs') : 0);
$totalWorkers = count_worker_like_users($pdo);

$recentWorkers = [];
$recentAssigned = [];
$recentLive = [];
$recentCompleted = [];

if (table_exists($pdo, 'users')) {
    $userColumns = get_columns($pdo, 'users');
    $userIdCol = first_existing_column($userColumns, ['id', 'user_id']);
    $roleCol = first_existing_column($userColumns, ['role', 'user_role', 'account_role', 'account_type']);
    $orderCol = first_existing_column($userColumns, ['created_at', 'joined_at', 'date_created', 'id']) ?? 'id';

    if ($userIdCol !== null && $roleCol !== null) {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE LOWER(TRIM(COALESCE({$roleCol}, ''))) IN ('walker', 'worker', 'staff', 'employee')
                ORDER BY {$orderCol} DESC
                LIMIT 6
            ");
            $stmt->execute();
            $recentWorkers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $recentWorkers = [];
        }
    }
}

if (table_exists($pdo, 'bookings')) {
    $bookingColumns = get_columns($pdo, 'bookings');
    $bookingOrderCol = first_existing_column($bookingColumns, [
        'service_date',
        'booking_date',
        'scheduled_date',
        'walk_date',
        'appointment_date',
        'start_date',
        'date',
        'created_at',
        'id',
    ]) ?? 'id';

    try {
        $stmt = $pdo->query("SELECT * FROM bookings ORDER BY {$bookingOrderCol} DESC");
        $bookings = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($bookings as $booking) {
            $type = classify_job($booking);

            if ($type === 'assigned' && count($recentAssigned) < 5) {
                $recentAssigned[] = $booking;
            } elseif ($type === 'live' && count($recentLive) < 5) {
                $recentLive[] = $booking;
            } elseif ($type === 'completed' && count($recentCompleted) < 5) {
                $recentCompleted[] = $booking;
            }

            if (
                count($recentAssigned) >= 5 &&
                count($recentLive) >= 5 &&
                count($recentCompleted) >= 5
            ) {
                break;
            }
        }
    } catch (Throwable) {
        $recentAssigned = [];
        $recentLive = [];
        $recentCompleted = [];
    }
}

$activeWorkers = 0;
$disabledWorkers = 0;

foreach ($recentWorkers as $worker) {
    if (worker_is_active($worker)) {
        $activeWorkers++;
    } else {
        $disabledWorkers++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | Doggie Dorian’s</title>
    <meta name="description" content="Main admin dashboard for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --blue: #38bdf8;
            --amber: #f59e0b;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1440px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 22%),
                linear-gradient(180deg, #07101d 0%, #0b1220 50%, #0f172a 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.65rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.94rem;
            transition: 0.18s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.09);
        }

        .hero,
        .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2.2rem;
            line-height: 1.08;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
            max-width: 880px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
            letter-spacing: 0.12em;
            font-size: 0.73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.55rem;
            font-weight: 900;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 22px;
        }

        .quick-card {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            transition: 0.18s ease;
        }

        .quick-card:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.07);
        }

        .quick-title {
            font-size: 1rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .quick-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: 0.92rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 22px;
        }

        .panel-title {
            font-size: 1.08rem;
            font-weight: 900;
            margin-bottom: 14px;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .item {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 6px;
        }

        .item-title {
            font-weight: 900;
            font-size: 1rem;
        }

        .item-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: 0.92rem;
        }

        .item-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .mini-link {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 900;
        }

        .badge-assigned {
            background: rgba(245, 158, 11, 0.16);
            color: #fde68a;
        }

        .badge-live {
            background: rgba(56, 189, 248, 0.16);
            color: #d0e4ff;
        }

        .badge-completed {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
        }

        .badge-active {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
        }

        .badge-disabled {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 1180px) {
            .quick-grid,
            .grid,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            h1 {
                font-size: 1.8rem;
            }

            .page {
                padding: 20px 12px 60px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s Admin</div>
            <div class="top-links">
                <a class="top-link" href="admin.php">Admin Home</a>
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-live-tracking.php">Live Tracking</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Operations Control Center</div>
            <h1>Admin Portal</h1>
            <div class="sub">
                Main command page for the Doggie Dorian’s admin system. From here you can manage bookings, workers, assignments, live tracking, and worker account status with the stabilized admin pages.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?= (int) $totalUsers ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Total Workers</div>
                    <div class="stat-value"><?= (int) $totalWorkers ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Total Bookings</div>
                    <div class="stat-value"><?= (int) $totalBookings ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Total Pets</div>
                    <div class="stat-value"><?= (int) $totalPets ?></div>
                </div>
            </div>

            <div class="quick-grid">
                <a class="quick-card" href="admin-dashboard.php">
                    <div class="quick-title">Admin Dashboard</div>
                    <div class="quick-text">Overview of platform metrics and admin activity.</div>
                </a>

                <a class="quick-card" href="admin-bookings.php">
                    <div class="quick-title">Manage Bookings</div>
                    <div class="quick-text">Review and control booking flow, service status, and workload.</div>
                </a>

                <a class="quick-card" href="admin-walker-management.php">
                    <div class="quick-title">Worker Management</div>
                    <div class="quick-text">View, edit, enable, disable, and inspect worker accounts.</div>
                </a>

                <a class="quick-card" href="admin-create-worker.php">
                    <div class="quick-title">Create Worker</div>
                    <div class="quick-text">Add walker, worker, staff, or employee accounts.</div>
                </a>

                <a class="quick-card" href="admin-assign-walker.php">
                    <div class="quick-title">Assign Worker</div>
                    <div class="quick-text">Assign bookings to the right worker from the admin side.</div>
                </a>

                <a class="quick-card" href="admin-live-tracking.php">
                    <div class="quick-title">Live Tracking</div>
                    <div class="quick-text">Review active service tracking and recent tracking points.</div>
                </a>
            </div>
        </section>

        <section class="grid">
            <div class="panel">
                <div class="panel-title">Recent Workers</div>

                <?php if ($recentWorkers === []): ?>
                    <div class="empty">No worker accounts found yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recentWorkers as $worker): ?>
                            <?php
                            $workerId = (int) value_from_row($worker, ['id', 'user_id'], 0);
                            $workerName = build_name($worker);
                            $workerRole = (string) value_from_row($worker, ['role', 'user_role', 'account_role', 'account_type'], 'Worker');
                            $workerEmail = (string) value_from_row($worker, ['email'], '—');
                            $workerStatus = human_status($worker);
                            $workerCreated = (string) value_from_row($worker, ['created_at', 'joined_at', 'date_created'], '');
                            $isActive = worker_is_active($worker);
                            ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title"><?= h($workerName) ?></div>
                                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-disabled' ?>">
                                        <?= h($workerStatus) ?>
                                    </span>
                                </div>
                                <div class="item-text">
                                    Role: <?= h(ucwords(str_replace('_', ' ', strtolower($workerRole)))) ?><br>
                                    Email: <?= h($workerEmail) ?><br>
                                    Joined: <?= h($workerCreated !== '' ? format_datetime_value($workerCreated) : '—') ?>
                                </div>
                                <div class="item-actions">
                                    <a class="mini-link" href="admin-worker-view.php?id=<?= $workerId ?>">View</a>
                                    <a class="mini-link" href="admin-edit-worker.php?id=<?= $workerId ?>">Edit</a>
                                    <a class="mini-link" href="admin-worker-jobs.php?id=<?= $workerId ?>">Jobs</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title">Assigned Jobs</div>

                <?php if ($recentAssigned === []): ?>
                    <div class="empty">No assigned jobs found.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recentAssigned as $job): ?>
                            <?php $jobId = (int) value_from_row($job, ['id', 'booking_id'], 0); ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">#<?= h((string) $jobId) ?> · <?= h(booking_title($job)) ?></div>
                                    <span class="badge badge-assigned">Assigned</span>
                                </div>
                                <div class="item-text">
                                    Customer: <?= h(booking_customer($job)) ?><br>
                                    When: <?= booking_when($job) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title">Live Jobs</div>

                <?php if ($recentLive === []): ?>
                    <div class="empty">No live jobs found.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recentLive as $job): ?>
                            <?php $jobId = (int) value_from_row($job, ['id', 'booking_id'], 0); ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">#<?= h((string) $jobId) ?> · <?= h(booking_title($job)) ?></div>
                                    <span class="badge badge-live">Live</span>
                                </div>
                                <div class="item-text">
                                    Customer: <?= h(booking_customer($job)) ?><br>
                                    When: <?= booking_when($job) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title">Completed Jobs</div>

                <?php if ($recentCompleted === []): ?>
                    <div class="empty">No completed jobs found.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recentCompleted as $job): ?>
                            <?php $jobId = (int) value_from_row($job, ['id', 'booking_id'], 0); ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">#<?= h((string) $jobId) ?> · <?= h(booking_title($job)) ?></div>
                                    <span class="badge badge-completed">Completed</span>
                                </div>
                                <div class="item-text">
                                    Customer: <?= h(booking_customer($job)) ?><br>
                                    When: <?= booking_when($job) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>