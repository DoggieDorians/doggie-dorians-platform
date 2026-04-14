<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminWalkerMgmtH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminWalkerMgmtQuoteIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function ddAdminWalkerMgmtTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminWalkerMgmtGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminWalkerMgmtTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminWalkerMgmtQuoteIdentifier($table) . ')');
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

function ddAdminWalkerMgmtFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminWalkerMgmtValueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminWalkerMgmtSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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
    }
}

function ddAdminWalkerMgmtBuildName(array $row): string
{
    $full = trim((string) ddAdminWalkerMgmtValueFromRow($row, array(
        'full_name',
        'name',
        'display_name',
        'worker_name',
        'walker_name',
        'username',
    ), ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown';
}

function ddAdminWalkerMgmtHumanStatus(array $row): string
{
    foreach (array('status', 'account_status', 'worker_status') as $column) {
        if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
            return ucwords(str_replace(array('_', '-'), ' ', strtolower((string) $row[$column])));
        }
    }

    foreach (array('is_active', 'active', 'enabled') as $column) {
        if (array_key_exists($column, $row)) {
            return ((int) $row[$column] === 1) ? 'Active' : 'Disabled';
        }
    }

    if (array_key_exists('disabled', $row)) {
        return ((int) $row['disabled'] === 1) ? 'Disabled' : 'Active';
    }

    return 'Unknown';
}

function ddAdminWalkerMgmtWorkerIsActive(array $row): bool
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

function ddAdminWalkerMgmtFormatDateTime($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('M j, Y g:i A', $timestamp);
}

function ddAdminWalkerMgmtNormalizeWorkerRecord(array $row, string $sourceTable): array
{
    $workerId = (int) ddAdminWalkerMgmtValueFromRow($row, array('id', 'user_id', 'worker_id', 'walker_id'), 0);
    $email = trim((string) ddAdminWalkerMgmtValueFromRow($row, array('email'), ''));
    $phone = trim((string) ddAdminWalkerMgmtValueFromRow($row, array('phone', 'phone_number', 'mobile'), ''));
    $created = trim((string) ddAdminWalkerMgmtValueFromRow($row, array('created_at', 'joined_at', 'date_created'), ''));
    $role = trim((string) ddAdminWalkerMgmtValueFromRow($row, array('role', 'user_role', 'account_role', 'account_type'), 'Worker'));

    return array(
        'source_table' => $sourceTable,
        'source_id' => $workerId,
        'name' => ddAdminWalkerMgmtBuildName($row),
        'role' => $role !== '' ? $role : 'Worker',
        'email' => $email !== '' ? $email : '—',
        'phone' => $phone !== '' ? $phone : '—',
        'status_label' => ddAdminWalkerMgmtHumanStatus($row),
        'is_active' => ddAdminWalkerMgmtWorkerIsActive($row),
        'created_at' => $created,
    );
}

function ddAdminWalkerMgmtFetchWorkersFromTable(PDO $pdo, string $table): array
{
    if (!ddAdminWalkerMgmtTableExists($pdo, $table)) {
        return array();
    }

    $columns = ddAdminWalkerMgmtGetColumns($pdo, $table);
    if ($columns === array()) {
        return array();
    }

    $idColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('id', 'user_id', 'worker_id', 'walker_id'));
    if ($idColumn === null) {
        return array();
    }

    $nameColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('full_name', 'name', 'display_name', 'worker_name', 'walker_name', 'username'));
    $firstNameColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('first_name'));
    $lastNameColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('last_name'));
    $emailColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('email'));
    $phoneColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('phone', 'phone_number', 'mobile'));
    $roleColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type'));
    $statusColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('status', 'account_status', 'worker_status'));
    $isActiveColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('is_active', 'active', 'enabled'));
    $disabledColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('disabled'));
    $createdColumn = ddAdminWalkerMgmtFirstExistingColumn($columns, array('created_at', 'joined_at', 'date_created', $idColumn)) ?? $idColumn;

    $selectParts = array(
        ddAdminWalkerMgmtQuoteIdentifier($idColumn) . ' AS id',
        $nameColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($nameColumn) . ' AS name' : "'' AS name",
        $firstNameColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($firstNameColumn) . ' AS first_name' : "'' AS first_name",
        $lastNameColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($lastNameColumn) . ' AS last_name' : "'' AS last_name",
        $emailColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($emailColumn) . ' AS email' : "'' AS email",
        $phoneColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($phoneColumn) . ' AS phone' : "'' AS phone",
        $roleColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($roleColumn) . ' AS role' : "'Worker' AS role",
        $statusColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($statusColumn) . ' AS status' : "'' AS status",
        $isActiveColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($isActiveColumn) . ' AS is_active' : "NULL AS is_active",
        $disabledColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($disabledColumn) . ' AS disabled' : "NULL AS disabled",
        $createdColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($createdColumn) . ' AS created_at' : "'' AS created_at",
    );

    $sql = '
        SELECT
            ' . implode(",\n            ", $selectParts) . '
        FROM ' . ddAdminWalkerMgmtQuoteIdentifier($table);

    if ($table === 'users' && $roleColumn !== null) {
        $sql .= '
        WHERE LOWER(TRIM(COALESCE(' . ddAdminWalkerMgmtQuoteIdentifier($roleColumn) . ", ''))) IN ('walker', 'worker', 'staff', 'employee')";
    }

    $sql .= '
        ORDER BY ' . ($createdColumn !== null ? ddAdminWalkerMgmtQuoteIdentifier($createdColumn) : ddAdminWalkerMgmtQuoteIdentifier($idColumn)) . ' DESC';

    $rows = ddAdminWalkerMgmtSafeFetchAll($pdo, $sql);
    $normalized = array();

    foreach ($rows as $row) {
        $normalized[] = ddAdminWalkerMgmtNormalizeWorkerRecord($row, $table);
    }

    return $normalized;
}

$workers = array();
$totalWorkers = 0;
$activeWorkers = 0;
$disabledWorkers = 0;
$sourceTablesUsed = array();

$allWorkers = array_merge(
    ddAdminWalkerMgmtFetchWorkersFromTable($pdo, 'workers'),
    ddAdminWalkerMgmtFetchWorkersFromTable($pdo, 'walkers'),
    ddAdminWalkerMgmtFetchWorkersFromTable($pdo, 'users')
);

$seen = array();
$sourcePriority = array(
    'workers' => 1,
    'walkers' => 2,
    'users' => 3,
);

foreach ($allWorkers as $worker) {
    $emailKey = strtolower(trim((string) $worker['email']));
    $nameKey = strtolower(trim((string) $worker['name']));
    $compoundKey = $emailKey !== '' && $emailKey !== '—'
        ? 'email:' . $emailKey
        : 'name:' . $nameKey . '|source:' . (string) $worker['source_table'] . '|id:' . (string) $worker['source_id'];

    if (!isset($seen[$compoundKey])) {
        $seen[$compoundKey] = $worker;
        continue;
    }

    $existing = $seen[$compoundKey];
    $existingPriority = $sourcePriority[$existing['source_table']] ?? 99;
    $newPriority = $sourcePriority[$worker['source_table']] ?? 99;

    if ($newPriority < $existingPriority) {
        $seen[$compoundKey] = $worker;
    }
}

$workers = array_values($seen);

usort($workers, static function (array $a, array $b): int {
    $aTime = strtotime((string) $a['created_at']);
    $bTime = strtotime((string) $b['created_at']);

    if ($aTime !== false && $bTime !== false && $aTime !== $bTime) {
        return $bTime <=> $aTime;
    }

    return strcasecmp((string) $a['name'], (string) $b['name']);
});

foreach ($workers as $worker) {
    $totalWorkers++;

    if (!empty($worker['is_active'])) {
        $activeWorkers++;
    } else {
        $disabledWorkers++;
    }

    $sourceTablesUsed[(string) $worker['source_table']] = true;
}

$sourceTableList = !empty($sourceTablesUsed)
    ? implode(', ', array_keys($sourceTablesUsed))
    : 'none';
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

        .stat-note {
            margin-top: 6px;
            color: rgba(244,241,234,0.68);
            font-size: 0.85rem;
            line-height: 1.45;
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
            margin-bottom: 8px;
        }

        .panel-copy {
            color: rgba(244,241,234,0.66);
            font-size: 0.92rem;
            line-height: 1.55;
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
            min-width: 1120px;
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

        @media (max-width: 1100px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            h1 {
                font-size: 1.65rem;
            }

            .page {
                padding: 20px 12px 60px;
            }

            .stats {
                grid-template-columns: 1fr;
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
                <a class="top-link" href="admin-revenue.php">Revenue</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-create-worker.php">Create Worker</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Worker Management</h1>
            <div class="sub">
                Stable admin-side view for walker, worker, staff, and employee accounts. This page reads from dedicated worker tables first, then worker-role user accounts, and now carries each worker’s source table through the action links so the correct record opens.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total Workers</div>
                    <div class="stat-value"><?php echo (int) $totalWorkers; ?></div>
                    <div class="stat-note">Combined unique records shown in this directory.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?php echo (int) $activeWorkers; ?></div>
                    <div class="stat-note">Workers currently marked available or enabled.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Disabled</div>
                    <div class="stat-value"><?php echo (int) $disabledWorkers; ?></div>
                    <div class="stat-note">Workers currently marked inactive or disabled.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Sources Used</div>
                    <div class="stat-value"><?php echo ddAdminWalkerMgmtH((string) count($sourceTablesUsed)); ?></div>
                    <div class="stat-note"><?php echo ddAdminWalkerMgmtH($sourceTableList); ?></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-title">All Worker Accounts</div>
            <div class="panel-copy">
                This directory prefers dedicated worker tables first, then worker-role accounts from the users table, so it stays compatible with your current schema and routes into the correct worker source.
            </div>

            <?php if ($workers === array()): ?>
                <div class="empty">
                    No worker accounts were found yet.
                    <br><br>
                    Once walker / worker / staff / employee records exist in <code>workers</code>, <code>walkers</code>, or the <code>users</code> table, they will appear here automatically.
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
                                <th>Source</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerId = (int) ($worker['source_id'] ?? 0);
                                $workerName = (string) ($worker['name'] ?? 'Unknown');
                                $workerRole = (string) ($worker['role'] ?? 'Worker');
                                $workerEmail = (string) ($worker['email'] ?? '—');
                                $workerPhone = (string) ($worker['phone'] ?? '—');
                                $workerStatus = (string) ($worker['status_label'] ?? 'Unknown');
                                $workerCreated = (string) ($worker['created_at'] ?? '');
                                $workerIsActive = !empty($worker['is_active']);
                                $sourceTable = (string) ($worker['source_table'] ?? 'unknown');
                                $sourceParam = urlencode($sourceTable);
                                ?>
                                <tr>
                                    <td>
                                        <div class="name"><?php echo ddAdminWalkerMgmtH($workerName); ?></div>
                                        <div class="subtext">ID: <?php echo $workerId > 0 ? $workerId : '—'; ?></div>
                                    </td>
                                    <td><?php echo ddAdminWalkerMgmtH(ucwords(str_replace('_', ' ', strtolower($workerRole)))); ?></td>
                                    <td>
                                        <span class="badge <?php echo $workerIsActive ? 'badge-active' : 'badge-disabled'; ?>">
                                            <?php echo ddAdminWalkerMgmtH($workerStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="subtext">
                                            Email: <?php echo ddAdminWalkerMgmtH($workerEmail); ?><br>
                                            Phone: <?php echo ddAdminWalkerMgmtH($workerPhone); ?>
                                        </div>
                                    </td>
                                    <td><?php echo ddAdminWalkerMgmtH($workerCreated !== '' ? ddAdminWalkerMgmtFormatDateTime($workerCreated) : '—'); ?></td>
                                    <td><?php echo ddAdminWalkerMgmtH($sourceTable); ?></td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($workerId > 0): ?>
                                                <a class="action" href="admin-worker-view.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">View</a>
                                                <a class="action" href="admin-edit-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Edit</a>
                                                <?php if ($workerIsActive): ?>
                                                    <a class="action" href="admin-disable-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Disable</a>
                                                <?php else: ?>
                                                    <a class="action" href="admin-enable-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Enable</a>
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