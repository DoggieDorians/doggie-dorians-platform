<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin-worker-jobs.php
 *
 * Stable admin-only worker job history page.
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

function format_date_value(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return h($raw);
    }

    return date('M j, Y', $ts);
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

if (!table_exists($pdo, 'users')) {
    exit('Users table not found.');
}

$userColumns = get_columns($pdo, 'users');
$userIdCol = first_existing_column($userColumns, ['id', 'user_id']);

if ($userIdCol === null) {
    exit('Users table is missing a usable ID column.');
}

$workerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($workerId <= 0) {
    exit('Invalid worker ID.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE {$userIdCol} = :id LIMIT 1");
    $stmt->execute([':id' => $workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit('Could not load worker: ' . h($e->getMessage()));
}

if (!$worker) {
    exit('Worker not found.');
}

$workerName = build_name($worker);
$workerRole = (string) value_from_row($worker, ['role', 'user_role', 'account_role', 'account_type'], 'Worker');
$workerEmail = (string) value_from_row($worker, ['email'], '—');

$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
$allowedFilters = ['all', 'assigned', 'live', 'completed'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$allJobs = [];
$filteredJobs = [];
$assignedCount = 0;
$liveCount = 0;
$completedCount = 0;

if (table_exists($pdo, 'bookings')) {
    $bookingColumns = get_columns($pdo, 'bookings');
    $bookingIdCol = first_existing_column($bookingColumns, ['id', 'booking_id']);
    $bookingWorkerCol = first_existing_column($bookingColumns, [
        'walker_id',
        'worker_id',
        'staff_id',
        'employee_id',
        'assigned_walker_id',
        'assigned_worker_id',
        'assigned_user_id',
        'assigned_to_user_id',
    ]);
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

    if ($bookingIdCol !== null && $bookingWorkerCol !== null) {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM bookings
                WHERE {$bookingWorkerCol} = :worker_id
                ORDER BY {$bookingOrderCol} DESC
            ");
            $stmt->execute([':worker_id' => $workerId]);
            $allJobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $allJobs = [];
        }
    }
}

foreach ($allJobs as $job) {
    $type = classify_job($job);

    if ($type === 'assigned') {
        $assignedCount++;
    } elseif ($type === 'live') {
        $liveCount++;
    } elseif ($type === 'completed') {
        $completedCount++;
    }

    if ($filter === 'all' || $filter === $type) {
        $filteredJobs[] = $job;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Jobs | Doggie Dorian’s</title>
    <meta name="description" content="Admin worker jobs page for Doggie Dorian’s.">
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
            --max: 1400px;
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
            font-size: 1.55rem;
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
            font-size: 2rem;
            line-height: 1.08;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
            max-width: 860px;
        }

        .hero-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.92rem;
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

        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .filter {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 800;
            font-size: 0.92rem;
        }

        .filter.active {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #0f172a;
            border-color: transparent;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .item {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 8px;
        }

        .item-title {
            font-weight: 900;
            font-size: 1rem;
        }

        .item-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.6;
            font-size: 0.92rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 900;
        }

        .badge-live {
            background: rgba(56, 189, 248, 0.16);
            color: #d0e4ff;
        }

        .badge-completed {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
        }

        .badge-assigned {
            background: rgba(245, 158, 11, 0.16);
            color: #fde68a;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 1100px) {
            .stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 760px) {
            .stats {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.65rem;
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
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-worker-view.php?id=<?= $workerId ?>">Worker View</a>
                <a class="top-link" href="admin-worker-jobs.php?id=<?= $workerId ?>">Worker Jobs</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Job History</div>
            <h1><?= h($workerName) ?></h1>
            <div class="sub">
                Full job history and workload breakdown for this worker account across assigned, live, and completed services.
            </div>

            <div class="hero-meta">
                <div class="pill">Worker ID: <?= h((string) $workerId) ?></div>
                <div class="pill">Role: <?= h($workerRole !== '' ? ucwords(str_replace('_', ' ', strtolower($workerRole))) : '—') ?></div>
                <div class="pill">Email: <?= h($workerEmail) ?></div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">All Jobs</div>
                    <div class="stat-value"><?= count($allJobs) ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Assigned</div>
                    <div class="stat-value"><?= $assignedCount ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Live</div>
                    <div class="stat-value"><?= $liveCount ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?= $completedCount ?></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="filters">
                <a class="filter <?= $filter === 'all' ? 'active' : '' ?>" href="admin-worker-jobs.php?id=<?= $workerId ?>&filter=all">All</a>
                <a class="filter <?= $filter === 'assigned' ? 'active' : '' ?>" href="admin-worker-jobs.php?id=<?= $workerId ?>&filter=assigned">Assigned</a>
                <a class="filter <?= $filter === 'live' ? 'active' : '' ?>" href="admin-worker-jobs.php?id=<?= $workerId ?>&filter=live">Live</a>
                <a class="filter <?= $filter === 'completed' ? 'active' : '' ?>" href="admin-worker-jobs.php?id=<?= $workerId ?>&filter=completed">Completed</a>
            </div>

            <?php if ($filteredJobs === []): ?>
                <div class="empty">
                    No jobs found for this filter.
                </div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($filteredJobs as $job): ?>
                        <?php
                        $type = classify_job($job);
                        $badgeClass = $type === 'live' ? 'badge-live' : ($type === 'completed' ? 'badge-completed' : 'badge-assigned');
                        $jobId = (int) value_from_row($job, ['id', 'booking_id'], 0);
                        ?>
                        <div class="item">
                            <div class="item-top">
                                <div class="item-title">
                                    #<?= h($jobId > 0 ? (string) $jobId : '—') ?> · <?= h(booking_title($job)) ?>
                                </div>
                                <span class="badge <?= $badgeClass ?>"><?= h(ucfirst($type)) ?></span>
                            </div>
                            <div class="item-text">
                                Customer: <?= h(booking_customer($job)) ?><br>
                                When: <?= booking_when($job) ?><br>
                                Start: <?= h((string) value_from_row($job, ['started_at', 'actual_start_time', 'start_time'], '—')) ?><br>
                                End: <?= h((string) value_from_row($job, ['completed_at', 'ended_at', 'finished_at', 'actual_end_time'], '—')) ?><br>
                                Status: <?= h((string) value_from_row($job, ['status', 'booking_status', 'walk_status', 'job_status'], ucfirst($type))) ?><br>
                                Created: <?= h((string) (
                                    (($created = value_from_row($job, ['created_at'], '')) !== '')
                                        ? format_datetime_value($created)
                                        : '—'
                                )) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>