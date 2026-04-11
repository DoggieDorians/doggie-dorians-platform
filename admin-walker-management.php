<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin-walker-management.php
 *
 * Stable admin-only worker management page.
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

function format_datetime_value(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return h($raw);
    }

    return date('M j, Y g:i A', $timestamp);
}

$workers = [];
$totalWorkers = 0;
$activeWorkers = 0;
$disabledWorkers = 0;

if (table_exists($pdo, 'users')) {
    $columns = get_columns($pdo, 'users');
    $idCol = first_existing_column($columns, ['id', 'user_id']);
    $roleCol = first_existing_column($columns, ['role', 'user_role', 'account_role', 'account_type']);
    $orderCol = first_existing_column($columns, ['created_at', 'joined_at', 'date_created', 'id']) ?? 'id';

    if ($idCol !== null && $roleCol !== null) {
        try {
            $stmt = $pdo->query("
                SELECT *
                FROM users
                WHERE LOWER(TRIM(COALESCE({$roleCol}, ''))) IN ('walker', 'worker', 'staff', 'employee')
                ORDER BY {$orderCol} DESC
            ");
            $workers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $workers = [];
        }
    }
}

$totalWorkers = count($workers);

foreach ($workers as $worker) {
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
    <title>Worker Management | Doggie Dorian’s</title>
    <meta name="description" content="Admin worker management for Doggie Dorian’s.">
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
            --max: 1380px;
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

        .hero {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
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

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
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

        .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 24px;
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .panel-title {
            font-size: 1.08rem;
            font-weight: 900;
            margin-bottom: 14px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
            background: rgba(255,255,255,0.02);
        }

        th, td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            vertical-align: top;
        }

        th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            color: var(--muted);
        }

        .name {
            font-weight: 900;
            margin-bottom: 4px;
        }

        .subtext {
            color: rgba(244,241,234,0.65);
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 900;
        }

        .badge-active {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
        }

        .badge-disabled {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: 0.85rem;
            font-weight: 800;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 980px) {
            .stats {
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
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Worker Management</h1>
            <div class="sub">
                Stable admin-side view for walker, worker, staff, and employee accounts. This page is built to load safely even if some worker fields or records are missing.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total Workers</div>
                    <div class="stat-value"><?= (int) $totalWorkers ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?= (int) $activeWorkers ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Disabled</div>
                    <div class="stat-value"><?= (int) $disabledWorkers ?></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-title">All Worker Accounts</div>

            <?php if ($workers === []): ?>
                <div class="empty">
                    No worker accounts were found yet.
                    <br><br>
                    Once walker / worker / staff / employee users are in the `users` table, they will appear here automatically.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Contact</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerId = (int) value_from_row($worker, ['id', 'user_id'], 0);
                                $workerName = build_name($worker);
                                $workerRole = (string) value_from_row($worker, ['role', 'user_role', 'account_role', 'account_type'], 'Worker');
                                $workerEmail = (string) value_from_row($worker, ['email'], '—');
                                $workerPhone = (string) value_from_row($worker, ['phone', 'phone_number', 'mobile'], '—');
                                $workerStatus = human_status($worker);
                                $workerCreated = (string) value_from_row($worker, ['created_at', 'joined_at', 'date_created'], '');
                                $workerIsActive = worker_is_active($worker);
                                ?>
                                <tr>
                                    <td>
                                        <div class="name"><?= h($workerName) ?></div>
                                        <div class="subtext">ID: <?= $workerId > 0 ? $workerId : '—' ?></div>
                                    </td>
                                    <td><?= h(ucwords(str_replace('_', ' ', strtolower($workerRole)))) ?></td>
                                    <td>
                                        <span class="badge <?= $workerIsActive ? 'badge-active' : 'badge-disabled' ?>">
                                            <?= h($workerStatus) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="subtext">
                                            Email: <?= h($workerEmail) ?><br>
                                            Phone: <?= h($workerPhone) ?>
                                        </div>
                                    </td>
                                    <td><?= h($workerCreated !== '' ? format_datetime_value($workerCreated) : '—') ?></td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($workerId > 0): ?>
                                                <a class="action" href="admin-worker-view.php?id=<?= $workerId ?>">View</a>
                                                <a class="action" href="admin-edit-worker.php?id=<?= $workerId ?>">Edit</a>
                                                <?php if ($workerIsActive): ?>
                                                    <a class="action" href="admin-disable-worker.php?id=<?= $workerId ?>">Disable</a>
                                                <?php else: ?>
                                                    <a class="action" href="admin-enable-worker.php?id=<?= $workerId ?>">Enable</a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="subtext">No actions available</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>