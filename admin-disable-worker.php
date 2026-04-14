<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminDisableWorkerH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminDisableWorkerRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminDisableWorkerQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminDisableWorkerTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminDisableWorkerGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminDisableWorkerTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminDisableWorkerQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminDisableWorkerFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminDisableWorkerValueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminDisableWorkerBuildName(array $row): string
{
    $full = trim((string) ddAdminDisableWorkerValueFromRow($row, array(
        'full_name',
        'name',
        'display_name',
        'username',
        'walker_name',
        'worker_name',
    ), ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown';
}

function ddAdminDisableWorkerIsActive(array $row): bool
{
    foreach (array('is_active', 'active', 'enabled') as $column) {
        if (array_key_exists($column, $row)) {
            return (int) $row[$column] === 1;
        }
    }

    if (array_key_exists('disabled', $row)) {
        return (int) $row['disabled'] !== 1;
    }

    foreach (array('status', 'account_status', 'worker_status') as $column) {
        if (!isset($row[$column])) {
            continue;
        }

        $value = strtolower(trim((string) $row[$column]));
        if ($value === '') {
            continue;
        }

        if (in_array($value, array('disabled', 'inactive', 'blocked', 'suspended'), true)) {
            return false;
        }

        if (in_array($value, array('active', 'enabled', 'approved'), true)) {
            return true;
        }
    }

    return true;
}

function ddAdminDisableWorkerCsrfToken(): string
{
    if (empty($_SESSION['admin_disable_worker_csrf']) || !is_string($_SESSION['admin_disable_worker_csrf'])) {
        $_SESSION['admin_disable_worker_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_disable_worker_csrf'];
}

function ddAdminDisableWorkerValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_disable_worker_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminDisableWorkerDetectSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('users', 'walkers', 'workers') as $table) {
        if (!ddAdminDisableWorkerTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminDisableWorkerGetColumns($pdo, $table);
        $idColumn = ddAdminDisableWorkerFirstExistingColumn($columns, array('id', 'user_id', 'walker_id', 'worker_id'));

        if ($idColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'role_column' => ddAdminDisableWorkerFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type')),
            'email_column' => ddAdminDisableWorkerFirstExistingColumn($columns, array('email')),
        );
    }

    return $sources;
}

function ddAdminDisableWorkerLoadRecord(PDO $pdo, int $workerId, array $sources): ?array
{
    foreach ($sources as $source) {
        $row = null;

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM ' . ddAdminDisableWorkerQuoteIdentifier((string) $source['table']) .
                ' WHERE ' . ddAdminDisableWorkerQuoteIdentifier((string) $source['id_column']) . ' = :id LIMIT 1'
            );
            $stmt->execute(array(':id' => $workerId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = false;
        }

        if (is_array($row) && !empty($row)) {
            $source['row'] = $row;
            return $source;
        }
    }

    return null;
}

$sources = ddAdminDisableWorkerDetectSources($pdo);
if (empty($sources)) {
    exit('No supported worker tables were found.');
}

$workerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($workerId <= 0) {
    $workerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : 0;
}
if ($workerId <= 0) {
    $workerId = isset($_POST['worker_id']) ? (int) $_POST['worker_id'] : 0;
}

if ($workerId <= 0) {
    ddAdminDisableWorkerRedirect('admin-walker-management.php');
}

$loaded = ddAdminDisableWorkerLoadRecord($pdo, $workerId, $sources);
if ($loaded === null) {
    exit('Worker not found.');
}

$workerTable = (string) $loaded['table'];
$userColumns = (array) $loaded['columns'];
$userIdCol = (string) $loaded['id_column'];
$worker = (array) $loaded['row'];

$workerName = ddAdminDisableWorkerBuildName($worker);
$workerRole = (string) ddAdminDisableWorkerValueFromRow($worker, array('role', 'user_role', 'account_role', 'account_type'), 'Worker');
$workerEmail = (string) ddAdminDisableWorkerValueFromRow($worker, array('email'), '—');
$alreadyDisabled = !ddAdminDisableWorkerIsActive($worker);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ddAdminDisableWorkerValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } else {
        try {
            $updates = array();
            $params = array(':id' => $workerId);

            $statusColumn = ddAdminDisableWorkerFirstExistingColumn($userColumns, array('status', 'account_status', 'worker_status'));
            $isActiveColumn = ddAdminDisableWorkerFirstExistingColumn($userColumns, array('is_active', 'active', 'enabled'));
            $disabledColumn = ddAdminDisableWorkerFirstExistingColumn($userColumns, array('disabled'));
            $updatedAtColumn = ddAdminDisableWorkerFirstExistingColumn($userColumns, array('updated_at', 'status_updated_at'));
            $updatedByColumn = ddAdminDisableWorkerFirstExistingColumn($userColumns, array('updated_by', 'status_updated_by'));

            if ($statusColumn !== null) {
                $updates[] = ddAdminDisableWorkerQuoteIdentifier($statusColumn) . ' = :status';
                $params[':status'] = 'disabled';
            }

            if ($isActiveColumn !== null) {
                $updates[] = ddAdminDisableWorkerQuoteIdentifier($isActiveColumn) . ' = :is_active';
                $params[':is_active'] = 0;
            } elseif ($disabledColumn !== null) {
                $updates[] = ddAdminDisableWorkerQuoteIdentifier($disabledColumn) . ' = :disabled';
                $params[':disabled'] = 1;
            }

            if ($updatedByColumn !== null) {
                $updates[] = ddAdminDisableWorkerQuoteIdentifier($updatedByColumn) . ' = :updated_by';
                $params[':updated_by'] = 'admin';
            }

            if ($updatedAtColumn !== null) {
                $updates[] = ddAdminDisableWorkerQuoteIdentifier($updatedAtColumn) . ' = CURRENT_TIMESTAMP';
            }

            if ($updates === array()) {
                $error = 'No disable-related status columns were found in the worker table.';
            } else {
                $sql = 'UPDATE ' . ddAdminDisableWorkerQuoteIdentifier($workerTable)
                    . ' SET ' . implode(', ', $updates)
                    . ' WHERE ' . ddAdminDisableWorkerQuoteIdentifier($userIdCol) . ' = :id';

                $stmt = $pdo->prepare($sql);

                foreach ($params as $placeholder => $value) {
                    if (is_int($value)) {
                        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                    } elseif ($value === null) {
                        $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                    } else {
                        $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                    }
                }

                $stmt->execute();

                ddAdminDisableWorkerRedirect('admin-worker-view.php?id=' . $workerId);
            }
        } catch (Throwable $e) {
            $error = 'Could not disable worker.';
        }
    }
}

$csrfToken = ddAdminDisableWorkerCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Disable Worker | Doggie Dorian’s</title>
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
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-worker-view.php?id=<?php echo (int) $workerId; ?>">Worker View</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <section class="panel">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Disable Worker</h1>
            <div class="sub">
                This will disable the selected worker account from the admin side.
            </div>

            <?php if ($error !== ''): ?>
                <div class="warn"><?php echo ddAdminDisableWorkerH($error); ?></div>
            <?php endif; ?>

            <?php if ($alreadyDisabled): ?>
                <div class="note">This worker already appears to be disabled, but you can still confirm the action if needed.</div>
            <?php else: ?>
                <div class="warn">You are about to disable this worker account.</div>
            <?php endif; ?>

            <div class="summary">
                <div class="box">
                    <div class="label">Worker</div>
                    <div class="value"><?php echo ddAdminDisableWorkerH($workerName); ?></div>
                </div>
                <div class="box">
                    <div class="label">Role</div>
                    <div class="value"><?php echo ddAdminDisableWorkerH($workerRole !== '' ? ucwords(str_replace('_', ' ', strtolower($workerRole))) : '—'); ?></div>
                </div>
                <div class="box">
                    <div class="label">Email</div>
                    <div class="value"><?php echo ddAdminDisableWorkerH($workerEmail); ?></div>
                </div>
                <div class="box">
                    <div class="label">Status</div>
                    <div class="value"><?php echo $alreadyDisabled ? 'Disabled' : 'Active'; ?></div>
                </div>
                <div class="box">
                    <div class="label">Source Table</div>
                    <div class="value"><?php echo ddAdminDisableWorkerH($workerTable); ?></div>
                </div>
            </div>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo ddAdminDisableWorkerH($csrfToken); ?>">
                <input type="hidden" name="worker_id" value="<?php echo (int) $workerId; ?>">

                <div class="actions">
                    <button class="btn btn-danger" type="submit">Confirm Disable</button>
                    <a class="btn btn-secondary" href="admin-worker-view.php?id=<?php echo (int) $workerId; ?>">Cancel</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>