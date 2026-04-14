<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminAssignWalkerH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminAssignWalkerQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminAssignWalkerRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminAssignWalkerTableExists(PDO $pdo, string $table): bool
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

function ddAdminAssignWalkerGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminAssignWalkerTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminAssignWalkerQuoteIdentifier($table) . ')');
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

function ddAdminAssignWalkerFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminAssignWalkerValueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminAssignWalkerSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminAssignWalkerSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ddAdminAssignWalkerCsrfToken(): string
{
    if (empty($_SESSION['admin_assign_walker_csrf']) || !is_string($_SESSION['admin_assign_walker_csrf'])) {
        $_SESSION['admin_assign_walker_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_assign_walker_csrf'];
}

function ddAdminAssignWalkerValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_assign_walker_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminAssignWalkerBuildName(array $row): string
{
    $full = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
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

function ddAdminAssignWalkerBookingTitle(array $row): string
{
    $service = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'service_name',
        'service_type',
        'service',
        'booking_type',
        'type',
    ), 'Service'));

    $pet = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'pet_name',
        'dog_name',
        'animal_name',
    ), ''));

    return $pet !== '' ? $service . ' • ' . $pet : $service;
}

function ddAdminAssignWalkerBookingCustomer(array $row): string
{
    $name = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'customer_name',
        'client_name',
        'member_name',
        'owner_name',
        'user_name',
        'full_name',
        'name',
    ), ''));

    if ($name !== '') {
        return $name;
    }

    $email = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'customer_email',
        'client_email',
        'member_email',
        'owner_email',
        'email',
    ), ''));

    return $email !== '' ? $email : '—';
}

function ddAdminAssignWalkerBookingWhen(array $row): string
{
    $date = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'service_date',
        'booking_date',
        'scheduled_date',
        'walk_date',
        'appointment_date',
        'date',
        'start_date',
        'scheduled_for',
        'created_at',
    ), ''));

    $time = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'service_time',
        'booking_time',
        'start_time',
        'scheduled_time',
        'time',
    ), ''));

    if ($date === '') {
        return '—';
    }

    $ts = strtotime($date);
    $formattedDate = $ts !== false ? date('M j, Y', $ts) : $date;

    return $time !== '' ? $formattedDate . ' • ' . $time : $formattedDate;
}

function ddAdminAssignWalkerSortTimestamp(array $row): int
{
    foreach (array('service_date', 'booking_date', 'scheduled_date', 'walk_date', 'appointment_date', 'date', 'start_date', 'created_at', 'updated_at') as $key) {
        if (!empty($row[$key])) {
            $ts = strtotime((string) $row[$key]);
            if ($ts !== false) {
                return $ts;
            }
        }
    }

    return 0;
}

function ddAdminAssignWalkerDetectWorkerSource(PDO $pdo): ?array
{
    if (ddAdminAssignWalkerTableExists($pdo, 'users')) {
        $columns = ddAdminAssignWalkerGetColumns($pdo, 'users');
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'user_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'users',
                'columns' => $columns,
                'id_column' => $idColumn,
                'role_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type')),
                'name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('full_name', 'name', 'display_name', 'username')),
                'first_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('email')),
                'uses_role_filter' => true,
            );
        }
    }

    if (ddAdminAssignWalkerTableExists($pdo, 'walkers')) {
        $columns = ddAdminAssignWalkerGetColumns($pdo, 'walkers');
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'walker_id', 'worker_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'walkers',
                'columns' => $columns,
                'id_column' => $idColumn,
                'role_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type')),
                'name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('full_name', 'name', 'walker_name', 'worker_name')),
                'first_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('email')),
                'uses_role_filter' => false,
            );
        }
    }

    if (ddAdminAssignWalkerTableExists($pdo, 'workers')) {
        $columns = ddAdminAssignWalkerGetColumns($pdo, 'workers');
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'worker_id', 'walker_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'workers',
                'columns' => $columns,
                'id_column' => $idColumn,
                'role_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type')),
                'name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('full_name', 'name', 'worker_name', 'walker_name')),
                'first_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('email')),
                'uses_role_filter' => false,
            );
        }
    }

    return null;
}

function ddAdminAssignWalkerFetchWorkers(PDO $pdo, array $source): array
{
    $table = $source['table'];
    $idColumn = $source['id_column'];
    $roleColumn = $source['role_column'];
    $nameColumn = $source['name_column'];
    $firstNameColumn = $source['first_name_column'];
    $emailColumn = $source['email_column'];
    $usesRoleFilter = (bool) ($source['uses_role_filter'] ?? false);

    $sql = 'SELECT * FROM ' . ddAdminAssignWalkerQuoteIdentifier($table);
    $params = array();

    if ($usesRoleFilter && $roleColumn !== null) {
        $sql .= ' WHERE LOWER(TRIM(COALESCE(' . ddAdminAssignWalkerQuoteIdentifier($roleColumn) . ", ''))) IN ('walker', 'worker', 'staff', 'employee')";
    }

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($nameColumn) . ' ASC';
    } elseif ($firstNameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($firstNameColumn) . ' ASC';
    } elseif ($emailColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($emailColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($idColumn) . ' DESC';
    }

    $rows = ddAdminAssignWalkerSafeFetchAll($pdo, $sql, $params);
    $workers = array();

    foreach ($rows as $row) {
        $workerId = (int) ($row[$idColumn] ?? 0);
        if ($workerId <= 0) {
            continue;
        }

        $workerName = ddAdminAssignWalkerBuildName($row);
        $workerRole = trim((string) ddAdminAssignWalkerValueFromRow($row, array('role', 'user_role', 'account_role', 'account_type'), $usesRoleFilter ? 'worker' : ucfirst(rtrim($table, 's'))));
        $workerEmail = trim((string) ddAdminAssignWalkerValueFromRow($row, array('email'), ''));

        $workers[] = array(
            'id' => $workerId,
            'name' => $workerName,
            'role' => $workerRole,
            'email' => $workerEmail,
            'row' => $row,
        );
    }

    return $workers;
}

function ddAdminAssignWalkerDetectBookingSource(PDO $pdo): ?array
{
    foreach (array('bookings', 'walks') as $table) {
        if (!ddAdminAssignWalkerTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminAssignWalkerGetColumns($pdo, $table);
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'booking_id', 'walk_id'));
        $workerIdColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array(
            'walker_id',
            'worker_id',
            'staff_id',
            'employee_id',
            'assigned_walker_id',
            'assigned_worker_id',
            'assigned_user_id',
            'assigned_to_user_id',
        ));

        if ($idColumn === null || $workerIdColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'worker_id_column' => $workerIdColumn,
            'worker_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'walker_name',
                'worker_name',
                'assigned_walker_name',
                'assigned_worker_name',
            )),
            'updated_at_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('updated_at', 'status_updated_at')),
            'updated_by_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array('status_updated_by', 'updated_by')),
            'order_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'service_date',
                'booking_date',
                'scheduled_date',
                'walk_date',
                'appointment_date',
                'start_date',
                'date',
                'created_at',
                'id',
            )),
        );
    }

    return null;
}

function ddAdminAssignWalkerFetchBookings(PDO $pdo, array $source): array
{
    $table = $source['table'];
    $orderColumn = $source['order_column'] ?? $source['id_column'];

    $sql = 'SELECT * FROM ' . ddAdminAssignWalkerQuoteIdentifier($table)
        . ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier((string) $orderColumn) . ' DESC';

    $rows = ddAdminAssignWalkerSafeFetchAll($pdo, $sql);
    $bookings = array();

    foreach ($rows as $row) {
        $row['__sort_timestamp'] = ddAdminAssignWalkerSortTimestamp($row);
        $bookings[] = $row;
    }

    usort($bookings, function (array $a, array $b): int {
        $aTs = (int) ($a['__sort_timestamp'] ?? 0);
        $bTs = (int) ($b['__sort_timestamp'] ?? 0);

        if ($aTs === $bTs) {
            $aId = (int) ($a['id'] ?? $a['booking_id'] ?? $a['walk_id'] ?? 0);
            $bId = (int) ($b['id'] ?? $b['booking_id'] ?? $b['walk_id'] ?? 0);
            return $bId <=> $aId;
        }

        return $bTs <=> $aTs;
    });

    return $bookings;
}

function ddAdminAssignWalkerFindWorkerById(array $workers, int $workerId): ?array
{
    foreach ($workers as $worker) {
        if ((int) ($worker['id'] ?? 0) === $workerId) {
            return $worker;
        }
    }

    return null;
}

$success = '';
$error = '';
$fatalError = '';

$selectedWorkerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : (int) ($_POST['worker_id'] ?? 0);
$selectedBookingId = (int) ($_POST['booking_id'] ?? 0);

$workers = array();
$bookings = array();
$unassignedBookings = array();
$assignedBookings = array();

try {
    $workerSource = ddAdminAssignWalkerDetectWorkerSource($pdo);
    if ($workerSource === null) {
        throw new RuntimeException('No supported worker source table was found.');
    }

    $bookingSource = ddAdminAssignWalkerDetectBookingSource($pdo);
    if ($bookingSource === null) {
        throw new RuntimeException('No supported bookings table with a worker assignment column was found.');
    }

    $workers = ddAdminAssignWalkerFetchWorkers($pdo, $workerSource);
    $bookings = ddAdminAssignWalkerFetchBookings($pdo, $bookingSource);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ddAdminAssignWalkerValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
            $error = 'Security check failed. Please refresh the page and try again.';
        } elseif ($selectedBookingId <= 0) {
            $error = 'Please select a booking.';
        } elseif ($selectedWorkerId <= 0) {
            $error = 'Please select a worker.';
        } else {
            $selectedWorker = ddAdminAssignWalkerFindWorkerById($workers, $selectedWorkerId);
            if ($selectedWorker === null) {
                $error = 'The selected worker was not found.';
            } else {
                $bookingIdColumn = (string) $bookingSource['id_column'];
                $workerIdColumn = (string) $bookingSource['worker_id_column'];
                $workerNameColumn = $bookingSource['worker_name_column'];
                $updatedAtColumn = $bookingSource['updated_at_column'];
                $updatedByColumn = $bookingSource['updated_by_column'];

                $updateParts = array(
                    ddAdminAssignWalkerQuoteIdentifier($workerIdColumn) . ' = :worker_id',
                );

                $params = array(
                    ':worker_id' => $selectedWorkerId,
                    ':booking_id' => $selectedBookingId,
                );

                if ($workerNameColumn !== null) {
                    $updateParts[] = ddAdminAssignWalkerQuoteIdentifier((string) $workerNameColumn) . ' = :worker_name';
                    $params[':worker_name'] = (string) ($selectedWorker['name'] ?? '');
                }

                if ($updatedByColumn !== null) {
                    $updateParts[] = ddAdminAssignWalkerQuoteIdentifier((string) $updatedByColumn) . ' = :updated_by';
                    $params[':updated_by'] = 'admin';
                }

                if ($updatedAtColumn !== null) {
                    $updateParts[] = ddAdminAssignWalkerQuoteIdentifier((string) $updatedAtColumn) . ' = CURRENT_TIMESTAMP';
                }

                $sql = 'UPDATE ' . ddAdminAssignWalkerQuoteIdentifier((string) $bookingSource['table'])
                    . ' SET ' . implode(', ', $updateParts)
                    . ' WHERE ' . ddAdminAssignWalkerQuoteIdentifier($bookingIdColumn) . ' = :booking_id';

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

                $success = 'Worker assigned successfully.';
                $bookings = ddAdminAssignWalkerFetchBookings($pdo, $bookingSource);
            }
        }
    }

    $workerIdColumn = (string) $bookingSource['worker_id_column'];

    foreach ($bookings as $booking) {
        $assignedWorkerId = (int) ($booking[$workerIdColumn] ?? 0);

        if ($assignedWorkerId > 0) {
            $assignedBookings[] = $booking;
        } else {
            $unassignedBookings[] = $booking;
        }
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

$csrfToken = ddAdminAssignWalkerCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assign Worker | Doggie Dorian’s</title>
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

        .error-box {
            border: 1px solid rgba(239, 68, 68, 0.25);
            background: rgba(239, 68, 68, 0.10);
            padding: 16px 18px;
            border-radius: 16px;
            color: #ffd5d5;
            white-space: pre-wrap;
            word-break: break-word;
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
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-assign-walker.php">Assign Worker</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Assignment Control</div>
            <h1>Assign Worker</h1>
            <div class="sub">
                Assign bookings to walker, worker, staff, or employee accounts using the current bookings and worker tables without breaking older schema patterns.
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Assign worker error:</strong><br>
                <?php echo ddAdminAssignWalkerH($fatalError); ?>
            </div>
        <?php else: ?>
            <section class="panel">
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo ddAdminAssignWalkerH($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?php echo ddAdminAssignWalkerH($error); ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminAssignWalkerH($csrfToken); ?>">

                    <div class="assign-grid">
                        <div class="field">
                            <label for="booking_id">Booking</label>
                            <select id="booking_id" name="booking_id" required>
                                <option value="">Select a booking</option>

                                <?php foreach ($unassignedBookings as $booking): ?>
                                    <?php $bookingId = (int) ($booking[$bookingSource['id_column']] ?? 0); ?>
                                    <option value="<?php echo $bookingId; ?>" <?php echo $selectedBookingId === $bookingId ? 'selected' : ''; ?>>
                                        #<?php echo $bookingId; ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?>
                                    </option>
                                <?php endforeach; ?>

                                <?php foreach ($assignedBookings as $booking): ?>
                                    <?php $bookingId = (int) ($booking[$bookingSource['id_column']] ?? 0); ?>
                                    <option value="<?php echo $bookingId; ?>" <?php echo $selectedBookingId === $bookingId ? 'selected' : ''; ?>>
                                        #<?php echo $bookingId; ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?> — <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?> — reassignment
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="worker_id">Worker</label>
                            <select id="worker_id" name="worker_id" required>
                                <option value="">Select a worker</option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo (int) $worker['id']; ?>" <?php echo $selectedWorkerId === (int) $worker['id'] ? 'selected' : ''; ?>>
                                        <?php echo ddAdminAssignWalkerH((string) $worker['name']); ?> — <?php echo ddAdminAssignWalkerH(ucwords(str_replace('_', ' ', strtolower((string) $worker['role'])))); ?>
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

                    <?php if ($unassignedBookings === array()): ?>
                        <div class="empty">No unassigned bookings found.</div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($unassignedBookings as $booking): ?>
                                <?php $bookingId = (int) ($booking[$bookingSource['id_column']] ?? 0); ?>
                                <div class="item">
                                    <div class="item-title">#<?php echo $bookingId; ?> · <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?></div>
                                    <div class="item-text">
                                        Customer: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?><br>
                                        When: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-title">Available Workers</div>

                    <?php if ($workers === array()): ?>
                        <div class="empty">No workers found.</div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($workers as $worker): ?>
                                <div class="item">
                                    <div class="item-title"><?php echo ddAdminAssignWalkerH((string) $worker['name']); ?> · ID <?php echo (int) $worker['id']; ?></div>
                                    <div class="item-text">
                                        Role: <?php echo ddAdminAssignWalkerH(ucwords(str_replace('_', ' ', strtolower((string) $worker['role'])))); ?><br>
                                        Email: <?php echo ddAdminAssignWalkerH((string) ($worker['email'] !== '' ? $worker['email'] : '—')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>