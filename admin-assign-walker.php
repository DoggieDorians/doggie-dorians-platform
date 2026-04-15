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
        'customer_name',
        'client_name',
        'member_name',
        'owner_name',
    ), ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown';
}

function ddAdminAssignWalkerIsActive(array $row): bool
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

function ddAdminAssignWalkerHumanRole(array $row, string $fallback = 'Worker'): string
{
    $role = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'role',
        'user_role',
        'account_role',
        'account_type',
        'worker_role',
    ), ''));

    if ($role === '') {
        return $fallback;
    }

    return ucwords(str_replace('_', ' ', strtolower($role)));
}

function ddAdminAssignWalkerRoleLooksAssignable(?string $role, string $table): bool
{
    $table = strtolower(trim($table));

    if ($role === null || trim($role) === '') {
        return $table !== 'users';
    }

    $role = strtolower(trim($role));

    if (in_array($role, array('walker', 'worker', 'staff', 'employee', 'pet care specialist', 'handler'), true)) {
        return true;
    }

    if (in_array($role, array('member', 'client', 'customer', 'owner', 'admin', 'administrator'), true)) {
        return false;
    }

    if (strpos($role, 'walk') !== false || strpos($role, 'work') !== false) {
        return true;
    }

    return $table !== 'users';
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

function ddAdminAssignWalkerBookingStatus(array $row): string
{
    $status = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'status',
        'booking_status',
        'service_status',
    ), ''));

    if ($status === '') {
        return 'Pending';
    }

    return ucwords(str_replace('_', ' ', strtolower($status)));
}

function ddAdminAssignWalkerCurrentAssignment(array $row): string
{
    $name = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'assigned_worker_name',
        'assigned_walker_name',
        'worker_name',
        'walker_name',
    ), ''));

    $source = trim((string) ddAdminAssignWalkerValueFromRow($row, array(
        'assigned_worker_source',
        'assigned_walker_source',
        'worker_source',
        'walker_source',
    ), ''));

    $id = (int) ddAdminAssignWalkerValueFromRow($row, array(
        'assigned_worker_id',
        'assigned_walker_id',
        'worker_id',
        'walker_id',
        'assigned_user_id',
        'assigned_to_user_id',
        'staff_id',
        'employee_id',
    ), 0);

    if ($name !== '') {
        return $name . ($source !== '' ? ' • ' . $source : '');
    }

    if ($id > 0) {
        return 'ID #' . $id . ($source !== '' ? ' • ' . $source : '');
    }

    return 'Unassigned';
}

function ddAdminAssignWalkerDetectWorkerSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('users', 'walkers', 'workers') as $table) {
        if (!ddAdminAssignWalkerTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminAssignWalkerGetColumns($pdo, $table);
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'user_id', 'walker_id', 'worker_id'));

        if ($idColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'label' => $table === 'users' ? 'Users' : ($table === 'walkers' ? 'Walkers' : 'Workers'),
        );
    }

    return $sources;
}

function ddAdminAssignWalkerFetchWorkers(PDO $pdo, array $sources): array
{
    $workers = array();

    foreach ($sources as $source) {
        $table = (string) $source['table'];
        $columns = (array) $source['columns'];
        $idColumn = (string) $source['id_column'];

        $orderColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array(
            'full_name',
            'name',
            'display_name',
            'walker_name',
            'worker_name',
            'username',
            'first_name',
            'email',
            $idColumn,
        ));

        $sql = 'SELECT * FROM ' . ddAdminAssignWalkerQuoteIdentifier($table);
        if ($orderColumn !== null) {
            $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($orderColumn) . ' ASC';
        } else {
            $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($idColumn) . ' DESC';
        }

        $rows = ddAdminAssignWalkerSafeFetchAll($pdo, $sql);

        foreach ($rows as $row) {
            $workerId = (int) ddAdminAssignWalkerValueFromRow($row, array($idColumn, 'id', 'user_id', 'walker_id', 'worker_id'), 0);
            if ($workerId <= 0) {
                continue;
            }

            $rawRole = ddAdminAssignWalkerValueFromRow($row, array(
                'role',
                'user_role',
                'account_role',
                'account_type',
                'worker_role',
            ), null);

            if (!ddAdminAssignWalkerRoleLooksAssignable($rawRole !== null ? (string) $rawRole : null, $table)) {
                continue;
            }

            $workers[] = array(
                'source_key' => $table . ':' . $workerId,
                'source_table' => $table,
                'source_label' => (string) $source['label'],
                'id' => $workerId,
                'name' => ddAdminAssignWalkerBuildName($row),
                'role' => ddAdminAssignWalkerHumanRole($row, ucfirst(rtrim($table, 's'))),
                'email' => trim((string) ddAdminAssignWalkerValueFromRow($row, array('email'), '')),
                'is_active' => ddAdminAssignWalkerIsActive($row),
                'row' => $row,
            );
        }
    }

    usort($workers, function (array $a, array $b): int {
        if ((int) $a['is_active'] !== (int) $b['is_active']) {
            return (int) $a['is_active'] > (int) $b['is_active'] ? -1 : 1;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $deduped = array();
    $seen = array();

    foreach ($workers as $worker) {
        $key = (string) $worker['source_key'];
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $worker;
    }

    return $deduped;
}

function ddAdminAssignWalkerDetectBookingSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('bookings', 'non_member_bookings', 'walks') as $table) {
        if (!ddAdminAssignWalkerTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminAssignWalkerGetColumns($pdo, $table);
        $idColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array('id', 'booking_id', 'walk_id'));

        if ($idColumn === null) {
            continue;
        }

        $assignmentIdColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array(
            'assigned_worker_id',
            'assigned_walker_id',
            'worker_id',
            'walker_id',
            'assigned_user_id',
            'assigned_to_user_id',
            'staff_id',
            'employee_id',
        ));

        if ($assignmentIdColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'assignment_id_column' => $assignmentIdColumn,
            'assignment_name_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'assigned_worker_name',
                'assigned_walker_name',
                'worker_name',
                'walker_name',
            )),
            'assignment_source_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'assigned_worker_source',
                'assigned_walker_source',
                'worker_source',
                'walker_source',
            )),
            'assignment_email_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'assigned_worker_email',
                'assigned_walker_email',
                'worker_email',
                'walker_email',
            )),
            'status_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'status',
                'booking_status',
                'service_status',
            )),
            'assigned_at_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'assigned_at',
                'worker_assigned_at',
                'walker_assigned_at',
            )),
            'updated_at_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'updated_at',
                'status_updated_at',
            )),
            'updated_by_column' => ddAdminAssignWalkerFirstExistingColumn($columns, array(
                'updated_by',
                'status_updated_by',
            )),
            'label' => $table === 'bookings'
                ? 'Member Booking'
                : ($table === 'non_member_bookings' ? 'Public Booking' : 'Walk'),
        );
    }

    return $sources;
}

function ddAdminAssignWalkerFetchBookings(PDO $pdo, array $sources): array
{
    $bookings = array();

    foreach ($sources as $source) {
        $table = (string) $source['table'];
        $columns = (array) $source['columns'];
        $idColumn = (string) $source['id_column'];

        $orderColumn = ddAdminAssignWalkerFirstExistingColumn($columns, array(
            'service_date',
            'booking_date',
            'scheduled_date',
            'walk_date',
            'appointment_date',
            'start_date',
            'date',
            'created_at',
            $idColumn,
        ));

        $sql = 'SELECT * FROM ' . ddAdminAssignWalkerQuoteIdentifier($table);
        if ($orderColumn !== null) {
            $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($orderColumn) . ' DESC';
        } else {
            $sql .= ' ORDER BY ' . ddAdminAssignWalkerQuoteIdentifier($idColumn) . ' DESC';
        }

        $rows = ddAdminAssignWalkerSafeFetchAll($pdo, $sql);

        foreach ($rows as $row) {
            $bookingId = (int) ddAdminAssignWalkerValueFromRow($row, array($idColumn, 'id', 'booking_id', 'walk_id'), 0);
            if ($bookingId <= 0) {
                continue;
            }

            $row['__source_table'] = $table;
            $row['__source_label'] = (string) $source['label'];
            $row['__source_key'] = $table . ':' . $bookingId;
            $row['__id_value'] = $bookingId;
            $row['__sort_timestamp'] = ddAdminAssignWalkerSortTimestamp($row);
            $bookings[] = $row;
        }
    }

    usort($bookings, function (array $a, array $b): int {
        $aTs = (int) ($a['__sort_timestamp'] ?? 0);
        $bTs = (int) ($b['__sort_timestamp'] ?? 0);

        if ($aTs === $bTs) {
            $aId = (int) ($a['__id_value'] ?? 0);
            $bId = (int) ($b['__id_value'] ?? 0);
            return $bId <=> $aId;
        }

        return $bTs <=> $aTs;
    });

    return $bookings;
}

function ddAdminAssignWalkerFindWorkerBySourceKey(array $workers, string $sourceKey): ?array
{
    foreach ($workers as $worker) {
        if ((string) ($worker['source_key'] ?? '') === $sourceKey) {
            return $worker;
        }
    }

    return null;
}

function ddAdminAssignWalkerFindBookingBySourceKey(array $bookings, string $sourceKey): ?array
{
    foreach ($bookings as $booking) {
        if ((string) ($booking['__source_key'] ?? '') === $sourceKey) {
            return $booking;
        }
    }

    return null;
}

function ddAdminAssignWalkerFindBookingSourceByTable(array $sources, string $table): ?array
{
    foreach ($sources as $source) {
        if ((string) ($source['table'] ?? '') === $table) {
            return $source;
        }
    }

    return null;
}

function ddAdminAssignWalkerBookingIsAssigned(array $booking, array $bookingSource): bool
{
    $assignmentIdColumn = (string) ($bookingSource['assignment_id_column'] ?? '');
    if ($assignmentIdColumn !== '' && !empty($booking[$assignmentIdColumn])) {
        return (int) $booking[$assignmentIdColumn] > 0;
    }

    $nameColumn = (string) ($bookingSource['assignment_name_column'] ?? '');
    if ($nameColumn !== '' && !empty($booking[$nameColumn])) {
        return true;
    }

    return false;
}

$success = '';
$error = '';
$fatalError = '';

$selectedWorkerSourceKey = trim((string) ($_POST['worker_source_key'] ?? ($_GET['worker_source_key'] ?? '')));
$selectedBookingSourceKey = trim((string) ($_POST['booking_source_key'] ?? ''));

$workers = array();
$bookings = array();
$unassignedBookings = array();
$assignedBookings = array();

try {
    $workerSources = ddAdminAssignWalkerDetectWorkerSources($pdo);
    if (empty($workerSources)) {
        throw new RuntimeException('No supported worker source tables were found.');
    }

    $bookingSources = ddAdminAssignWalkerDetectBookingSources($pdo);
    if (empty($bookingSources)) {
        throw new RuntimeException('No supported booking tables with worker assignment fields were found.');
    }

    $workers = ddAdminAssignWalkerFetchWorkers($pdo, $workerSources);
    $bookings = ddAdminAssignWalkerFetchBookings($pdo, $bookingSources);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ddAdminAssignWalkerValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
            $error = 'Security check failed. Please refresh the page and try again.';
        } elseif ($selectedBookingSourceKey === '') {
            $error = 'Please select a booking.';
        } elseif ($selectedWorkerSourceKey === '') {
            $error = 'Please select a worker.';
        } else {
            $selectedWorker = ddAdminAssignWalkerFindWorkerBySourceKey($workers, $selectedWorkerSourceKey);
            $selectedBooking = ddAdminAssignWalkerFindBookingBySourceKey($bookings, $selectedBookingSourceKey);

            if ($selectedWorker === null) {
                $error = 'The selected worker was not found.';
            } elseif (!(bool) ($selectedWorker['is_active'] ?? false)) {
                $error = 'The selected worker is not currently active.';
            } elseif ($selectedBooking === null) {
                $error = 'The selected booking was not found.';
            } else {
                $bookingTable = (string) ($selectedBooking['__source_table'] ?? '');
                $bookingId = (int) ($selectedBooking['__id_value'] ?? 0);
                $bookingSource = ddAdminAssignWalkerFindBookingSourceByTable($bookingSources, $bookingTable);

                if ($bookingSource === null || $bookingId <= 0) {
                    $error = 'The selected booking source could not be resolved.';
                } else {
                    $bookingIdColumn = (string) $bookingSource['id_column'];
                    $assignmentIdColumn = (string) $bookingSource['assignment_id_column'];
                    $assignmentNameColumn = (string) ($bookingSource['assignment_name_column'] ?? '');
                    $assignmentSourceColumn = (string) ($bookingSource['assignment_source_column'] ?? '');
                    $assignmentEmailColumn = (string) ($bookingSource['assignment_email_column'] ?? '');
                    $statusColumn = (string) ($bookingSource['status_column'] ?? '');
                    $assignedAtColumn = (string) ($bookingSource['assigned_at_column'] ?? '');
                    $updatedAtColumn = (string) ($bookingSource['updated_at_column'] ?? '');
                    $updatedByColumn = (string) ($bookingSource['updated_by_column'] ?? '');

                    $updateParts = array(
                        ddAdminAssignWalkerQuoteIdentifier($assignmentIdColumn) . ' = :worker_id',
                    );

                    $params = array(
                        ':worker_id' => (int) $selectedWorker['id'],
                        ':booking_id' => $bookingId,
                    );

                    if ($assignmentNameColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($assignmentNameColumn) . ' = :worker_name';
                        $params[':worker_name'] = (string) ($selectedWorker['name'] ?? '');
                    }

                    if ($assignmentSourceColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($assignmentSourceColumn) . ' = :worker_source';
                        $params[':worker_source'] = (string) ($selectedWorker['source_table'] ?? '');
                    }

                    if ($assignmentEmailColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($assignmentEmailColumn) . ' = :worker_email';
                        $params[':worker_email'] = (string) ($selectedWorker['email'] ?? '');
                    }

                    if ($statusColumn !== '') {
                        $currentStatus = strtolower(trim((string) ($selectedBooking[$statusColumn] ?? '')));
                        if ($currentStatus === '' || in_array($currentStatus, array('pending', 'new', 'unassigned', 'open', 'requested'), true)) {
                            $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($statusColumn) . ' = :status';
                            $params[':status'] = 'assigned';
                        }
                    }

                    if ($updatedByColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($updatedByColumn) . ' = :updated_by';
                        $params[':updated_by'] = 'admin';
                    }

                    if ($assignedAtColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($assignedAtColumn) . ' = CURRENT_TIMESTAMP';
                    }

                    if ($updatedAtColumn !== '') {
                        $updateParts[] = ddAdminAssignWalkerQuoteIdentifier($updatedAtColumn) . ' = CURRENT_TIMESTAMP';
                    }

                    $sql = 'UPDATE ' . ddAdminAssignWalkerQuoteIdentifier($bookingTable)
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
                    $bookings = ddAdminAssignWalkerFetchBookings($pdo, $bookingSources);
                    $selectedBookingSourceKey = '';
                }
            }
        }
    }

    foreach ($bookings as $booking) {
        $bookingSource = ddAdminAssignWalkerFindBookingSourceByTable($bookingSources, (string) ($booking['__source_table'] ?? ''));
        if ($bookingSource === null) {
            continue;
        }

        if (ddAdminAssignWalkerBookingIsAssigned($booking, $bookingSource)) {
            $assignedBookings[] = $booking;
        } else {
            $unassignedBookings[] = $booking;
        }
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

$csrfToken = ddAdminAssignWalkerCsrfToken();
$activeWorkerCount = 0;

foreach ($workers as $worker) {
    if (!empty($worker['is_active'])) {
        $activeWorkerCount++;
    }
}
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
            max-width: 860px;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .hero-stat {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .hero-stat-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 0.7rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .hero-stat-value {
            font-weight: 900;
            font-size: 1.3rem;
            line-height: 1.2;
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

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 24px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-top: 8px;
            border: 1px solid transparent;
        }

        .pill-active {
            background: rgba(34, 197, 94, 0.14);
            color: #dcfce7;
            border-color: rgba(34, 197, 94, 0.18);
        }

        .pill-inactive {
            background: rgba(239, 68, 68, 0.14);
            color: #fecaca;
            border-color: rgba(239, 68, 68, 0.18);
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

        @media (max-width: 980px) {
            .assign-grid,
            .grid,
            .hero-stats {
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
                Assign bookings to active worker accounts across member bookings, public bookings, and worker tables without losing the worker source.
            </div>

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-label">Total Bookings</div>
                    <div class="hero-stat-value"><?php echo (int) count($bookings); ?></div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-label">Unassigned</div>
                    <div class="hero-stat-value"><?php echo (int) count($unassignedBookings); ?></div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-label">Workers Loaded</div>
                    <div class="hero-stat-value"><?php echo (int) count($workers); ?></div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-label">Active Workers</div>
                    <div class="hero-stat-value"><?php echo (int) $activeWorkerCount; ?></div>
                </div>
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
                            <label for="booking_source_key">Booking</label>
                            <select id="booking_source_key" name="booking_source_key" required>
                                <option value="">Select a booking</option>

                                <?php foreach ($unassignedBookings as $booking): ?>
                                    <option value="<?php echo ddAdminAssignWalkerH((string) $booking['__source_key']); ?>" <?php echo $selectedBookingSourceKey === (string) $booking['__source_key'] ? 'selected' : ''; ?>>
                                        [<?php echo ddAdminAssignWalkerH((string) $booking['__source_label']); ?>]
                                        #<?php echo (int) $booking['__id_value']; ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?>
                                    </option>
                                <?php endforeach; ?>

                                <?php foreach ($assignedBookings as $booking): ?>
                                    <option value="<?php echo ddAdminAssignWalkerH((string) $booking['__source_key']); ?>" <?php echo $selectedBookingSourceKey === (string) $booking['__source_key'] ? 'selected' : ''; ?>>
                                        [<?php echo ddAdminAssignWalkerH((string) $booking['__source_label']); ?>]
                                        #<?php echo (int) $booking['__id_value']; ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?> —
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?> —
                                        reassignment
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="worker_source_key">Worker</label>
                            <select id="worker_source_key" name="worker_source_key" required>
                                <option value="">Select a worker</option>
                                <?php foreach ($workers as $worker): ?>
                                    <option
                                        value="<?php echo ddAdminAssignWalkerH((string) $worker['source_key']); ?>"
                                        <?php echo $selectedWorkerSourceKey === (string) $worker['source_key'] ? 'selected' : ''; ?>
                                        <?php echo empty($worker['is_active']) ? 'disabled' : ''; ?>
                                    >
                                        [<?php echo ddAdminAssignWalkerH((string) $worker['source_table']); ?>]
                                        <?php echo ddAdminAssignWalkerH((string) $worker['name']); ?> —
                                        <?php echo ddAdminAssignWalkerH((string) $worker['role']); ?>
                                        <?php echo empty($worker['is_active']) ? ' — inactive' : ''; ?>
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
                                <div class="item">
                                    <div class="item-title">
                                        [<?php echo ddAdminAssignWalkerH((string) $booking['__source_label']); ?>]
                                        #<?php echo (int) $booking['__id_value']; ?> ·
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?>
                                    </div>
                                    <div class="item-text">
                                        Customer: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?><br>
                                        When: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?><br>
                                        Status: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingStatus($booking)); ?>
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
                                    <div class="item-title">
                                        [<?php echo ddAdminAssignWalkerH((string) $worker['source_table']); ?>]
                                        <?php echo ddAdminAssignWalkerH((string) $worker['name']); ?> · ID <?php echo (int) $worker['id']; ?>
                                    </div>
                                    <div class="item-text">
                                        Role: <?php echo ddAdminAssignWalkerH((string) $worker['role']); ?><br>
                                        Email: <?php echo ddAdminAssignWalkerH((string) ($worker['email'] !== '' ? $worker['email'] : '—')); ?>
                                    </div>
                                    <div class="pill <?php echo !empty($worker['is_active']) ? 'pill-active' : 'pill-inactive'; ?>">
                                        <?php echo !empty($worker['is_active']) ? 'Active' : 'Inactive'; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="grid">
                <div class="panel">
                    <div class="panel-title">Recently Assigned / Reassignable Bookings</div>

                    <?php if ($assignedBookings === array()): ?>
                        <div class="empty">No assigned bookings found.</div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($assignedBookings as $booking): ?>
                                <div class="item">
                                    <div class="item-title">
                                        [<?php echo ddAdminAssignWalkerH((string) $booking['__source_label']); ?>]
                                        #<?php echo (int) $booking['__id_value']; ?> ·
                                        <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingTitle($booking)); ?>
                                    </div>
                                    <div class="item-text">
                                        Customer: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingCustomer($booking)); ?><br>
                                        When: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerBookingWhen($booking)); ?><br>
                                        Current Assignment: <?php echo ddAdminAssignWalkerH(ddAdminAssignWalkerCurrentAssignment($booking)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-title">What This Page Updates</div>
                    <div class="list">
                        <div class="item">
                            <div class="item-title">Worker source-aware assignment</div>
                            <div class="item-text">
                                This page saves the selected worker ID and, when supported by the booking table, also saves the worker name, worker source table, worker email, and assignment timestamps.
                            </div>
                        </div>
                        <div class="item">
                            <div class="item-title">Booking tables supported</div>
                            <div class="item-text">
                                It checks supported live booking tables including bookings, non_member_bookings, and walks, using safe schema inspection before loading them.
                            </div>
                        </div>
                        <div class="item">
                            <div class="item-title">Worker tables supported</div>
                            <div class="item-text">
                                It loads assignable worker accounts from users, walkers, and workers, while preferring active accounts and keeping source IDs separate.
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>