<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin-assign-walker.php
 *
 * Stable admin-only worker assignment page.
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

$success = '';
$error = '';

if (!table_exists($pdo, 'users')) {
    exit('Users table not found.');
}

if (!table_exists($pdo, 'bookings')) {
    exit('Bookings table not found.');
}

$userColumns = get_columns($pdo, 'users');
$bookingColumns = get_columns($pdo, 'bookings');

$userIdCol = first_existing_column($userColumns, ['id', 'user_id']);
$userRoleCol = first_existing_column($userColumns, ['role', 'user_role', 'account_role', 'account_type']);

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

if ($userIdCol === null || $userRoleCol === null) {
    exit('Users table is missing required ID or role columns.');
}

if ($bookingIdCol === null || $bookingWorkerCol === null) {
    exit('Bookings table is missing required ID or worker assignment columns.');
}

$workers = [];
$bookings = [];

try {
    $stmtWorkers = $pdo->query("
        SELECT *
        FROM users
        WHERE LOWER(TRIM(COALESCE({$userRoleCol}, ''))) IN ('walker', 'worker', 'staff', 'employee')
        ORDER BY {$userIdCol} DESC
    ");
    $workers = $stmtWorkers ? $stmtWorkers->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable) {
    $workers = [];
}

$selectedWorkerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $workerId = (int) ($_POST['worker_id'] ?? 0);

    if ($bookingId <= 0) {
        $error = 'Please select a booking.';
    } elseif ($workerId <= 0) {
        $error = 'Please select a worker.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET {$bookingWorkerCol} = :worker_id
                WHERE {$bookingIdCol} = :booking_id
            ");
            $stmt->execute([
                ':worker_id' => $workerId,
                ':booking_id' => $bookingId,
            ]);

            $success = 'Worker assigned successfully.';
            $selectedWorkerId = $workerId;
        } catch (Throwable $e) {
            $error = 'Could not assign worker: ' . $e->getMessage();
        }
    }
}

try {
    $stmtBookings = $pdo->query("
        SELECT *
        FROM bookings
        ORDER BY {$bookingOrderCol} DESC
    ");
    $bookings = $stmtBookings ? $stmtBookings->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable) {
    $bookings = [];
}

$unassignedBookings = [];
$assignedBookings = [];

foreach ($bookings as $booking) {
    $workerId = (int) ($booking[$bookingWorkerCol] ?? 0);
    if ($workerId > 0) {
        $assignedBookings[] = $booking;
    } else {
        $unassignedBookings[] = $booking;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Worker | Doggie Dorian’s</title>
    <meta name="description" content="Admin worker assignment page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold: #d4af37;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --red: #ef4444;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1320px;
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

        .hero, .panel {
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
            max-width: 820px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
            border: 1px solid rgba(34, 197, 94, 0.20);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border: 1px solid rgba(239, 68, 68, 0.20);
        }

        .assign-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 18px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            font-size: 0.84rem;
            font-weight: 800;
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        select {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.09);
            background: rgba(255,255,255,0.05);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 14px;
            font: inherit;
            outline: none;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #0f172a;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.08);
            color: var(--text);
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

        .item-title {
            font-weight: 900;
            margin-bottom: 6px;
        }

        .item-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: 0.92rem;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 900px) {
            .assign-grid,
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
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
                <a class="top-link" href="admin-assign-walker.php">Assign Worker</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Assignment Control</div>
            <h1>Assign Worker</h1>
            <div class="sub">
                Assign bookings to walker, worker, staff, or employee accounts using the current bookings and users structure.
            </div>
        </section>

        <section class="panel">
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="assign-grid">
                    <div class="field">
                        <label for="booking_id">Booking</label>
                        <select id="booking_id" name="booking_id" required>
                            <option value="">Select a booking</option>
                            <?php foreach ($unassignedBookings as $booking): ?>
                                <?php $bookingId = (int) ($booking[$bookingIdCol] ?? 0); ?>
                                <option value="<?= $bookingId ?>">
                                    #<?= $bookingId ?> — <?= h(booking_title($booking)) ?> — <?= h(booking_customer($booking)) ?> — <?= booking_when($booking) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php foreach ($assignedBookings as $booking): ?>
                                <?php $bookingId = (int) ($booking[$bookingIdCol] ?? 0); ?>
                                <option value="<?= $bookingId ?>">
                                    #<?= $bookingId ?> — <?= h(booking_title($booking)) ?> — <?= h(booking_customer($booking)) ?> — <?= booking_when($booking) ?> — reassignment
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="worker_id">Worker</label>
                        <select id="worker_id" name="worker_id" required>
                            <option value="">Select a worker</option>
                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerId = (int) ($worker[$userIdCol] ?? 0);
                                $workerName = build_name($worker);
                                $workerRole = (string) value_from_row($worker, ['role', 'user_role', 'account_role', 'account_type'], 'worker');
                                ?>
                                <option value="<?= $workerId ?>" <?= $selectedWorkerId === $workerId ? 'selected' : '' ?>>
                                    <?= h($workerName) ?> — <?= h(ucwords(str_replace('_', ' ', strtolower($workerRole)))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Assign Worker</button>
                    <a class="btn btn-secondary" href="admin-walker-management.php">Back to Workers</a>
                </div>
            </form>
        </section>

        <section class="grid">
            <div class="panel">
                <div class="panel-title">Unassigned Bookings</div>
                <?php if ($unassignedBookings === []): ?>
                    <div class="empty">No unassigned bookings found.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($unassignedBookings as $booking): ?>
                            <?php $bookingId = (int) ($booking[$bookingIdCol] ?? 0); ?>
                            <div class="item">
                                <div class="item-title">#<?= $bookingId ?> · <?= h(booking_title($booking)) ?></div>
                                <div class="item-text">
                                    Customer: <?= h(booking_customer($booking)) ?><br>
                                    When: <?= booking_when($booking) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title">Available Workers</div>
                <?php if ($workers === []): ?>
                    <div class="empty">No workers found.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($workers as $worker): ?>
                            <?php
                            $workerId = (int) ($worker[$userIdCol] ?? 0);
                            $workerName = build_name($worker);
                            $workerRole = (string) value_from_row($worker, ['role', 'user_role', 'account_role', 'account_type'], 'worker');
                            $workerEmail = (string) value_from_row($worker, ['email'], '—');
                            ?>
                            <div class="item">
                                <div class="item-title"><?= h($workerName) ?> · ID <?= $workerId ?></div>
                                <div class="item-text">
                                    Role: <?= h(ucwords(str_replace('_', ' ', strtolower($workerRole)))) ?><br>
                                    Email: <?= h($workerEmail) ?>
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