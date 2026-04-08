<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin-disable-worker.php
 *
 * Stable admin-only worker disable page.
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
    $workerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : 0;
}

if ($workerId <= 0) {
    redirect_to('admin-walker-management.php');
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
$alreadyDisabled = !worker_is_active($worker);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updates = [];
        $params = [':id' => $workerId];

        if (in_array('status', $userColumns, true)) {
            $updates[] = 'status = :status';
            $params[':status'] = 'disabled';
        } elseif (in_array('account_status', $userColumns, true)) {
            $updates[] = 'account_status = :account_status';
            $params[':account_status'] = 'disabled';
        } elseif (in_array('worker_status', $userColumns, true)) {
            $updates[] = 'worker_status = :worker_status';
            $params[':worker_status'] = 'disabled';
        }

        if (in_array('is_active', $userColumns, true)) {
            $updates[] = 'is_active = :is_active';
            $params[':is_active'] = 0;
        } elseif (in_array('active', $userColumns, true)) {
            $updates[] = 'active = :active';
            $params[':active'] = 0;
        } elseif (in_array('enabled', $userColumns, true)) {
            $updates[] = 'enabled = :enabled';
            $params[':enabled'] = 0;
        } elseif (in_array('disabled', $userColumns, true)) {
            $updates[] = 'disabled = :disabled';
            $params[':disabled'] = 1;
        }

        if ($updates === []) {
            $error = 'No disable-related status columns were found in the users table.';
        } else {
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE {$userIdCol} = :id";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            redirect_to('admin-worker-view.php?id=' . $workerId);
        }
    } catch (Throwable $e) {
        $error = 'Could not disable worker: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disable Worker | Doggie Dorian’s</title>
    <meta name="description" content="Admin disable worker page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold-soft: #f5deb3;
            --red: #ef4444;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 900px;
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

        .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
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
            margin-bottom: 18px;
        }

        .summary {
            display: grid;
            gap: 12px;
            margin-bottom: 18px;
        }

        .box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 0.72rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .value {
            font-weight: 800;
            line-height: 1.5;
            word-break: break-word;
        }

        .warn {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border: 1px solid rgba(239, 68, 68, 0.20);
        }

        .note {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
            background: rgba(245, 158, 11, 0.16);
            color: #fde68a;
            border: 1px solid rgba(245, 158, 11, 0.20);
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: #fff;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.08);
            color: var(--text);
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
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-worker-view.php?id=<?= $workerId ?>">Worker View</a>
            </div>
        </div>

        <section class="panel">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Disable Worker</h1>
            <div class="sub">
                This will disable the selected worker account from the admin side.
            </div>

            <?php if ($error !== ''): ?>
                <div class="warn"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($alreadyDisabled): ?>
                <div class="note">This worker already appears to be disabled, but you can still confirm the action if needed.</div>
            <?php else: ?>
                <div class="warn">You are about to disable this worker account.</div>
            <?php endif; ?>

            <div class="summary">
                <div class="box">
                    <div class="label">Worker</div>
                    <div class="value"><?= h($workerName) ?></div>
                </div>
                <div class="box">
                    <div class="label">Role</div>
                    <div class="value"><?= h($workerRole !== '' ? ucwords(str_replace('_', ' ', strtolower($workerRole))) : '—') ?></div>
                </div>
                <div class="box">
                    <div class="label">Email</div>
                    <div class="value"><?= h($workerEmail) ?></div>
                </div>
                <div class="box">
                    <div class="label">Status</div>
                    <div class="value"><?= $alreadyDisabled ? 'Disabled' : 'Active' ?></div>
                </div>
            </div>

            <form method="post" action="">
                <div class="actions">
                    <button class="btn btn-danger" type="submit">Confirm Disable</button>
                    <a class="btn btn-secondary" href="admin-worker-view.php?id=<?= $workerId ?>">Cancel</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>