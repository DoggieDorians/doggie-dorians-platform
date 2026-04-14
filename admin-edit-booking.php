<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminEditBookingE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminEditBookingQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminEditBookingRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminEditBookingBackUrl(int $bookingId = 0, string $status = ''): string
{
    $query = array();

    if ($status !== '') {
        $query[] = $status . '=1';
    }

    if ($bookingId > 0) {
        $query[] = 'highlight=' . $bookingId;
    }

    return 'admin-bookings.php' . (!empty($query) ? '?' . implode('&', $query) : '');
}

function ddAdminEditBookingTableExists(PDO $pdo, string $table): bool
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

function ddAdminEditBookingGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminEditBookingTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminEditBookingQuoteIdentifier($table) . ')');
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

function ddAdminEditBookingHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminEditBookingFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminEditBookingSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminEditBookingSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminEditBookingCsrfToken(): string
{
    if (empty($_SESSION['admin_edit_booking_csrf']) || !is_string($_SESSION['admin_edit_booking_csrf'])) {
        $_SESSION['admin_edit_booking_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_edit_booking_csrf'];
}

function ddAdminEditBookingValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_edit_booking_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminEditBookingBuildPersonLabel(array $row, array $source): string
{
    $nameColumn = $source['name_column'] ?? null;
    if (is_string($nameColumn) && $nameColumn !== '' && !empty($row[$nameColumn])) {
        return trim((string) $row[$nameColumn]);
    }

    $firstNameColumn = $source['first_name_column'] ?? null;
    $lastNameColumn = $source['last_name_column'] ?? null;

    $first = is_string($firstNameColumn) ? trim((string) ($row[$firstNameColumn] ?? '')) : '';
    $last = is_string($lastNameColumn) ? trim((string) ($row[$lastNameColumn] ?? '')) : '';
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    $emailColumn = $source['email_column'] ?? null;
    if (is_string($emailColumn) && $emailColumn !== '' && !empty($row[$emailColumn])) {
        return trim((string) $row[$emailColumn]);
    }

    return 'Unknown';
}

function ddAdminEditBookingDetectClientSource(PDO $pdo): ?array
{
    foreach (array('users', 'members', 'client_profiles') as $table) {
        if (!ddAdminEditBookingTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminEditBookingGetColumns($pdo, $table);
        $idColumn = ddAdminEditBookingFirstExistingColumn($columns, array('id', 'user_id', 'member_id', 'client_id'));

        if ($idColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('full_name', 'name', 'client_name', 'username')),
            'first_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('first_name')),
            'last_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('last_name')),
            'email_column' => ddAdminEditBookingFirstExistingColumn($columns, array('email')),
            'role_column' => ddAdminEditBookingFirstExistingColumn($columns, array('role', 'user_role', 'account_type', 'account_role')),
        );
    }

    return null;
}

function ddAdminEditBookingFetchClients(PDO $pdo, array $source): array
{
    $table = $source['table'];
    $idColumn = $source['id_column'];
    $roleColumn = $source['role_column'];
    $nameColumn = $source['name_column'];
    $firstNameColumn = $source['first_name_column'];
    $emailColumn = $source['email_column'];

    $sql = 'SELECT * FROM ' . ddAdminEditBookingQuoteIdentifier($table);
    $params = array();

    if ($roleColumn !== null) {
        $sql .= ' WHERE LOWER(COALESCE(' . ddAdminEditBookingQuoteIdentifier((string) $roleColumn) . ", 'member')) != :admin_role";
        $params[':admin_role'] = 'admin';
    }

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $nameColumn) . ' ASC';
    } elseif ($firstNameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $firstNameColumn) . ' ASC';
    } elseif ($emailColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $emailColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $idColumn) . ' ASC';
    }

    $rows = ddAdminEditBookingSafeFetchAll($pdo, $sql, $params);
    $clients = array();

    foreach ($rows as $row) {
        $clientId = (int) ($row[$idColumn] ?? 0);
        if ($clientId <= 0) {
            continue;
        }

        $clients[] = array(
            'id' => $clientId,
            'label' => ddAdminEditBookingBuildPersonLabel($row, $source),
            'email' => $emailColumn !== null ? trim((string) ($row[$emailColumn] ?? '')) : '',
            'row' => $row,
        );
    }

    return $clients;
}

function ddAdminEditBookingDetectPetSource(PDO $pdo): ?array
{
    foreach (array('pets', 'dogs') as $table) {
        if (!ddAdminEditBookingTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminEditBookingGetColumns($pdo, $table);
        $idColumn = ddAdminEditBookingFirstExistingColumn($columns, array('id', 'pet_id', 'dog_id'));
        $ownerColumn = ddAdminEditBookingFirstExistingColumn($columns, array('user_id', 'member_id', 'client_id', 'owner_id'));

        if ($idColumn === null || $ownerColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'owner_column' => $ownerColumn,
            'name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('pet_name', 'dog_name', 'name')),
            'breed_column' => ddAdminEditBookingFirstExistingColumn($columns, array('breed', 'dog_breed')),
        );
    }

    return null;
}

function ddAdminEditBookingFetchPets(PDO $pdo, array $source): array
{
    $table = $source['table'];
    $idColumn = $source['id_column'];
    $nameColumn = $source['name_column'];

    $sql = 'SELECT * FROM ' . ddAdminEditBookingQuoteIdentifier($table);

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $nameColumn) . ' ASC, ' . ddAdminEditBookingQuoteIdentifier((string) $idColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $idColumn) . ' ASC';
    }

    $rows = ddAdminEditBookingSafeFetchAll($pdo, $sql);
    $pets = array();

    foreach ($rows as $row) {
        $petId = (int) ($row[$idColumn] ?? 0);
        if ($petId <= 0) {
            continue;
        }

        $petName = $nameColumn !== null ? trim((string) ($row[$nameColumn] ?? '')) : '';
        if ($petName === '') {
            $petName = 'Unnamed Pet';
        }

        $pets[] = array(
            'id' => $petId,
            'owner_id' => (int) ($row[$source['owner_column']] ?? 0),
            'pet_name' => $petName,
            'breed' => $source['breed_column'] !== null ? trim((string) ($row[$source['breed_column']] ?? '')) : '',
            'row' => $row,
        );
    }

    return $pets;
}

function ddAdminEditBookingDetectWalkerSource(PDO $pdo): ?array
{
    if (ddAdminEditBookingTableExists($pdo, 'walkers')) {
        $columns = ddAdminEditBookingGetColumns($pdo, 'walkers');
        $idColumn = ddAdminEditBookingFirstExistingColumn($columns, array('id', 'walker_id', 'worker_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'walkers',
                'columns' => $columns,
                'id_column' => $idColumn,
                'name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('full_name', 'name', 'walker_name', 'worker_name')),
                'first_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminEditBookingFirstExistingColumn($columns, array('email')),
                'role_column' => ddAdminEditBookingFirstExistingColumn($columns, array('role', 'user_role', 'account_type')),
                'active_column' => ddAdminEditBookingFirstExistingColumn($columns, array('is_active', 'active', 'enabled')),
                'status_column' => ddAdminEditBookingFirstExistingColumn($columns, array('status')),
                'uses_role_filter' => false,
            );
        }
    }

    if (ddAdminEditBookingTableExists($pdo, 'workers')) {
        $columns = ddAdminEditBookingGetColumns($pdo, 'workers');
        $idColumn = ddAdminEditBookingFirstExistingColumn($columns, array('id', 'worker_id', 'walker_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'workers',
                'columns' => $columns,
                'id_column' => $idColumn,
                'name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('full_name', 'name', 'worker_name', 'walker_name')),
                'first_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminEditBookingFirstExistingColumn($columns, array('email')),
                'role_column' => ddAdminEditBookingFirstExistingColumn($columns, array('role', 'user_role', 'account_type')),
                'active_column' => ddAdminEditBookingFirstExistingColumn($columns, array('is_active', 'active', 'enabled')),
                'status_column' => ddAdminEditBookingFirstExistingColumn($columns, array('status')),
                'uses_role_filter' => false,
            );
        }
    }

    if (ddAdminEditBookingTableExists($pdo, 'users')) {
        $columns = ddAdminEditBookingGetColumns($pdo, 'users');
        $idColumn = ddAdminEditBookingFirstExistingColumn($columns, array('id', 'user_id'));

        if ($idColumn !== null) {
            return array(
                'table' => 'users',
                'columns' => $columns,
                'id_column' => $idColumn,
                'name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('full_name', 'name', 'username')),
                'first_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('first_name')),
                'last_name_column' => ddAdminEditBookingFirstExistingColumn($columns, array('last_name')),
                'email_column' => ddAdminEditBookingFirstExistingColumn($columns, array('email')),
                'role_column' => ddAdminEditBookingFirstExistingColumn($columns, array('role', 'user_role', 'account_type', 'account_role')),
                'active_column' => ddAdminEditBookingFirstExistingColumn($columns, array('is_active', 'active', 'enabled')),
                'status_column' => ddAdminEditBookingFirstExistingColumn($columns, array('status')),
                'uses_role_filter' => true,
            );
        }
    }

    return null;
}

function ddAdminEditBookingFetchWalkers(PDO $pdo, ?array $source): array
{
    if ($source === null) {
        return array();
    }

    $table = $source['table'];
    $idColumn = $source['id_column'];
    $roleColumn = $source['role_column'];
    $activeColumn = $source['active_column'];
    $statusColumn = $source['status_column'];
    $nameColumn = $source['name_column'];
    $firstNameColumn = $source['first_name_column'];
    $emailColumn = $source['email_column'];

    $whereParts = array();
    $params = array();

    if (($source['uses_role_filter'] ?? false) && $roleColumn !== null) {
        $whereParts[] = 'LOWER(TRIM(COALESCE(' . ddAdminEditBookingQuoteIdentifier((string) $roleColumn) . ", ''))) IN ('walker', 'worker', 'staff', 'employee')";
    }

    if ($activeColumn !== null) {
        $whereParts[] = 'COALESCE(' . ddAdminEditBookingQuoteIdentifier((string) $activeColumn) . ', 1) = 1';
    }

    if ($statusColumn !== null) {
        $whereParts[] = "LOWER(COALESCE(" . ddAdminEditBookingQuoteIdentifier((string) $statusColumn) . ", 'active')) NOT IN ('disabled', 'inactive')";
    }

    $sql = 'SELECT * FROM ' . ddAdminEditBookingQuoteIdentifier($table);

    if (!empty($whereParts)) {
        $sql .= ' WHERE ' . implode(' AND ', $whereParts);
    }

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $nameColumn) . ' ASC';
    } elseif ($firstNameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $firstNameColumn) . ' ASC';
    } elseif ($emailColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $emailColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminEditBookingQuoteIdentifier((string) $idColumn) . ' ASC';
    }

    $rows = ddAdminEditBookingSafeFetchAll($pdo, $sql, $params);
    $walkers = array();

    foreach ($rows as $row) {
        $walkerId = (int) ($row[$idColumn] ?? 0);
        if ($walkerId <= 0) {
            continue;
        }

        $walkers[] = array(
            'id' => $walkerId,
            'label' => ddAdminEditBookingBuildPersonLabel($row, $source),
            'email' => $emailColumn !== null ? trim((string) ($row[$emailColumn] ?? '')) : '',
            'row' => $row,
        );
    }

    return $walkers;
}

if (!ddAdminEditBookingTableExists($pdo, 'bookings')) {
    ddAdminEditBookingRedirect(ddAdminEditBookingBackUrl(0, 'missing_table'));
}

$bookingColumns = ddAdminEditBookingGetColumns($pdo, 'bookings');
$idColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('id', 'booking_id'));

if ($idColumn === null) {
    ddAdminEditBookingRedirect(ddAdminEditBookingBackUrl(0, 'missing_table'));
}

$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($bookingId <= 0) {
    $bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
}
if ($bookingId <= 0) {
    $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
}
if ($bookingId <= 0) {
    ddAdminEditBookingRedirect(ddAdminEditBookingBackUrl(0, 'invalid_booking'));
}

$clientSource = ddAdminEditBookingDetectClientSource($pdo);
$petSource = ddAdminEditBookingDetectPetSource($pdo);
$walkerSource = ddAdminEditBookingDetectWalkerSource($pdo);

$clients = $clientSource !== null ? ddAdminEditBookingFetchClients($pdo, $clientSource) : array();
$pets = $petSource !== null ? ddAdminEditBookingFetchPets($pdo, $petSource) : array();
$walkers = ddAdminEditBookingFetchWalkers($pdo, $walkerSource);

$selectParts = array(ddAdminEditBookingQuoteIdentifier($idColumn) . ' AS id');
$optionalBookingColumns = array(
    'user_id',
    'member_id',
    'client_id',
    'owner_id',
    'customer_id',
    'pet_id',
    'dog_id',
    'animal_id',
    'assigned_walker_id',
    'assigned_worker_id',
    'walker_id',
    'worker_id',
    'staff_id',
    'employee_id',
    'assigned_user_id',
    'service_type',
    'service',
    'booking_type',
    'type',
    'service_date',
    'booking_date',
    'date',
    'appointment_date',
    'walk_date',
    'service_time',
    'booking_time',
    'time',
    'start_time',
    'duration_minutes',
    'duration',
    'status',
    'price',
    'amount',
    'total_price',
    'total',
    'walker_name',
    'worker_name',
    'assigned_walker_name',
    'assigned_worker_name',
    'admin_notes',
    'notes'
);

foreach ($optionalBookingColumns as $column) {
    if (ddAdminEditBookingHasColumn($bookingColumns, $column)) {
        $selectParts[] = ddAdminEditBookingQuoteIdentifier($column) . ' AS ' . ddAdminEditBookingQuoteIdentifier($column);
    }
}

$booking = ddAdminEditBookingSafeFetchOne(
    $pdo,
    'SELECT ' . implode(', ', $selectParts)
    . ' FROM ' . ddAdminEditBookingQuoteIdentifier('bookings')
    . ' WHERE ' . ddAdminEditBookingQuoteIdentifier($idColumn) . ' = :id LIMIT 1',
    array(':id' => $bookingId)
);

if (!$booking) {
    ddAdminEditBookingRedirect(ddAdminEditBookingBackUrl($bookingId, 'missing_booking'));
}

$clientIdColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('user_id', 'member_id', 'client_id', 'owner_id', 'customer_id'));
$petIdColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('pet_id', 'dog_id', 'animal_id'));
$walkerIdColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('assigned_walker_id', 'assigned_worker_id', 'walker_id', 'worker_id', 'staff_id', 'employee_id', 'assigned_user_id'));
$serviceTypeColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('service_type', 'service', 'booking_type', 'type'));
$serviceDateColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('service_date', 'booking_date', 'date', 'appointment_date', 'walk_date'));
$serviceTimeColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('service_time', 'booking_time', 'time', 'start_time'));
$durationColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('duration_minutes', 'duration'));
$statusColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('status'));
$priceColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('price', 'amount', 'total_price', 'total'));
$walkerNameColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('walker_name', 'worker_name', 'assigned_walker_name', 'assigned_worker_name'));
$adminNotesColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('admin_notes', 'notes'));
$statusUpdatedByColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('status_updated_by', 'updated_by'));
$statusUpdatedAtColumn = ddAdminEditBookingFirstExistingColumn($bookingColumns, array('status_updated_at', 'updated_at'));

$serviceOptions = array(
    'walk' => 'Walk',
    'daycare' => 'Daycare',
    'boarding' => 'Boarding',
    'drop-in' => 'Drop-In',
    'drop-in-walk' => 'Drop-In + Walk',
);

$statusOptions = array(
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
);

$form = array(
    'user_id' => $clientIdColumn !== null ? (string) ($booking[$clientIdColumn] ?? '') : '',
    'pet_id' => $petIdColumn !== null ? (string) ($booking[$petIdColumn] ?? '') : '',
    'service_type' => $serviceTypeColumn !== null ? (string) ($booking[$serviceTypeColumn] ?? 'walk') : 'walk',
    'service_date' => $serviceDateColumn !== null ? (string) ($booking[$serviceDateColumn] ?? '') : '',
    'service_time' => $serviceTimeColumn !== null ? (string) ($booking[$serviceTimeColumn] ?? '') : '',
    'duration_minutes' => $durationColumn !== null ? (string) ($booking[$durationColumn] ?? '30') : '30',
    'status' => $statusColumn !== null ? (string) ($booking[$statusColumn] ?? 'pending') : 'pending',
    'price' => $priceColumn !== null && isset($booking[$priceColumn]) ? (string) $booking[$priceColumn] : '',
    'assigned_walker_id' => $walkerIdColumn !== null ? (string) ($booking[$walkerIdColumn] ?? '') : '',
    'admin_notes' => $adminNotesColumn !== null ? (string) ($booking[$adminNotesColumn] ?? '') : '',
);

$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) {
        $form[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    if (!ddAdminEditBookingValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    $userId = (int) $form['user_id'];
    $petId = (int) $form['pet_id'];
    $serviceType = $form['service_type'];
    $serviceDate = $form['service_date'];
    $serviceTime = $form['service_time'];
    $durationMinutes = $form['duration_minutes'] !== '' ? (int) $form['duration_minutes'] : 0;
    $status = $form['status'];
    $price = $form['price'] !== '' ? (float) $form['price'] : -1;
    $assignedWalkerId = $form['assigned_walker_id'] !== '' ? (int) $form['assigned_walker_id'] : 0;
    $adminNotes = $form['admin_notes'];

    if ($userId <= 0) {
        $errors[] = 'Please select a client.';
    }

    if ($petId <= 0) {
        $errors[] = 'Please select a pet.';
    }

    if (!isset($serviceOptions[$serviceType])) {
        $errors[] = 'Please select a valid service type.';
    }

    if ($serviceDate === '') {
        $errors[] = 'Please choose a service date.';
    }

    if ($serviceTime === '') {
        $errors[] = 'Please choose a service time.';
    }

    if ($durationMinutes <= 0) {
        $errors[] = 'Please enter a valid duration.';
    }

    if (!isset($statusOptions[$status])) {
        $errors[] = 'Please select a valid status.';
    }

    if ($price < 0) {
        $errors[] = 'Please enter a valid price.';
    }

    $selectedClient = null;
    foreach ($clients as $client) {
        if ((int) $client['id'] === $userId) {
            $selectedClient = $client;
            break;
        }
    }

    if ($selectedClient === null) {
        $errors[] = 'Selected client was not found.';
    }

    $selectedPet = null;
    foreach ($pets as $pet) {
        if ((int) ($pet['id'] ?? 0) === $petId) {
            $selectedPet = $pet;
            break;
        }
    }

    if ($selectedPet === null) {
        $errors[] = 'Selected pet was not found.';
    } elseif ((int) ($selectedPet['owner_id'] ?? 0) !== $userId) {
        $errors[] = 'That pet does not belong to the selected client.';
    }

    $selectedWalker = null;
    if ($assignedWalkerId > 0) {
        foreach ($walkers as $walker) {
            if ((int) $walker['id'] === $assignedWalkerId) {
                $selectedWalker = $walker;
                break;
            }
        }

        if ($selectedWalker === null) {
            $errors[] = 'Selected walker was not found.';
        }
    }

    if (empty($errors)) {
        if ($assignedWalkerId > 0 && $status === 'pending') {
            $status = 'confirmed';
        }

        $updateParts = array();
        $params = array(':id' => $bookingId);

        $addUpdate = function (string $column, string $placeholder, $value) use (&$updateParts, &$params): void {
            $updateParts[] = ddAdminEditBookingQuoteIdentifier($column) . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        };

        if ($clientIdColumn !== null) {
            $addUpdate($clientIdColumn, ':user_id', $userId);
        }

        if ($petIdColumn !== null) {
            $addUpdate($petIdColumn, ':pet_id', $petId);
        }

        if ($walkerIdColumn !== null) {
            $addUpdate($walkerIdColumn, ':assigned_walker_id', $assignedWalkerId > 0 ? $assignedWalkerId : null);
        }

        if ($serviceTypeColumn !== null) {
            $addUpdate($serviceTypeColumn, ':service_type', $serviceType);
        }

        if ($serviceDateColumn !== null) {
            $addUpdate($serviceDateColumn, ':service_date', $serviceDate);
        }

        if ($serviceTimeColumn !== null) {
            $addUpdate($serviceTimeColumn, ':service_time', $serviceTime);
        }

        if ($durationColumn !== null) {
            $addUpdate($durationColumn, ':duration_minutes', $durationMinutes);
        }

        if ($statusColumn !== null) {
            $addUpdate($statusColumn, ':status', $status);
        }

        if ($priceColumn !== null) {
            $addUpdate($priceColumn, ':price', $price);
        }

        if ($walkerNameColumn !== null) {
            $addUpdate($walkerNameColumn, ':walker_name', $selectedWalker !== null ? (string) ($selectedWalker['label'] ?? '') : null);
        }

        if ($adminNotesColumn !== null) {
            $addUpdate($adminNotesColumn, ':admin_notes', $adminNotes !== '' ? $adminNotes : null);
        }

        if ($statusUpdatedByColumn !== null) {
            $addUpdate($statusUpdatedByColumn, ':status_updated_by', 'admin');
        }

        if ($statusUpdatedAtColumn !== null) {
            $updateParts[] = ddAdminEditBookingQuoteIdentifier($statusUpdatedAtColumn) . ' = CURRENT_TIMESTAMP';
        }

        if (empty($updateParts)) {
            $errors[] = 'No compatible booking columns were found.';
        } else {
            $sql = 'UPDATE ' . ddAdminEditBookingQuoteIdentifier('bookings')
                . ' SET ' . implode(', ', $updateParts)
                . ' WHERE ' . ddAdminEditBookingQuoteIdentifier($idColumn) . ' = :id';

            try {
                $stmt = $pdo->prepare($sql);

                foreach ($params as $placeholder => $value) {
                    if (is_int($value)) {
                        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                    } elseif ($value === null) {
                        $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                    } elseif (is_float($value)) {
                        $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                    } else {
                        $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                    }
                }

                $stmt->execute();

                ddAdminEditBookingRedirect(ddAdminEditBookingBackUrl($bookingId));
            } catch (Throwable $e) {
                $errors[] = 'Booking could not be updated.';
            }
        }
    }
}

$csrfToken = ddAdminEditBookingCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Edit Booking | Doggie Dorian’s</title>
  <meta name="description" content="Edit a booking in the Doggie Dorian’s admin area.">
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .wrap {
      max-width: 1040px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 18px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 40px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 760px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-inner {
      padding: 24px;
    }

    .alert {
      margin-bottom: 18px;
      padding: 14px 16px;
      border-radius: 14px;
      font-weight: 700;
    }

    .alert.error {
      background: rgba(255,152,152,0.12);
      border: 1px solid rgba(255,152,152,0.28);
      color: #ffd7d7;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .field.full {
      grid-column: 1 / -1;
    }

    label {
      display: block;
      margin-bottom: 9px;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
    }

    input,
    select,
    textarea {
      width: 100%;
      min-height: 52px;
      border-radius: 14px;
      border: 1px solid var(--line);
      background: #0d1018;
      color: var(--text);
      padding: 0 14px;
      font-size: 15px;
      outline: none;
    }

    textarea {
      min-height: 120px;
      resize: vertical;
      padding: 14px;
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: rgba(212,175,55,0.55);
      box-shadow: 0 0 0 3px rgba(212,175,55,0.08);
    }

    .helper {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .button-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 22px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 18px;
      border-radius: 12px;
      text-decoration: none;
      font-size: 14px;
      font-weight: 800;
      border: none;
      cursor: pointer;
    }

    .btn-primary {
      background: var(--gold);
      color: #0a0a0f;
    }

    .btn-secondary {
      background: rgba(255,255,255,0.05);
      color: var(--text);
      border: 1px solid var(--line);
    }

    @media (max-width: 760px) {
      .grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 31px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Edit Booking #<?php echo (int) $bookingId; ?></h1>
        <div class="subtext">
          Update the client, pet, schedule, pricing, walker assignment, and service status for this booking.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-nav.php" class="top-btn">Admin Nav</a>
        <a href="admin-bookings.php" class="top-btn">Back to Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <?php if ($errors): ?>
          <div class="alert error">
            <?php foreach ($errors as $error): ?>
              <div><?php echo ddAdminEditBookingE($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin-edit-booking.php?id=<?php echo (int) $bookingId; ?>">
          <input type="hidden" name="csrf_token" value="<?php echo ddAdminEditBookingE($csrfToken); ?>">
          <input type="hidden" name="booking_id" value="<?php echo (int) $bookingId; ?>">

          <div class="grid">
            <div class="field">
              <label for="user_id">Client</label>
              <select name="user_id" id="user_id" required>
                <option value="">Select client</option>
                <?php foreach ($clients as $client): ?>
                  <option value="<?php echo (int) $client['id']; ?>" <?php echo ((string) $client['id'] === $form['user_id']) ? 'selected' : ''; ?>>
                    <?php echo ddAdminEditBookingE((string) $client['label']); ?><?php echo $client['email'] !== '' ? ' (' . ddAdminEditBookingE((string) $client['email']) . ')' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="pet_id">Pet</label>
              <select name="pet_id" id="pet_id" required>
                <option value="">Select pet</option>
                <?php foreach ($pets as $pet): ?>
                  <option
                    value="<?php echo (int) ($pet['id'] ?? 0); ?>"
                    data-owner-id="<?php echo (int) ($pet['owner_id'] ?? 0); ?>"
                    <?php echo ((string) ($pet['id'] ?? '') === $form['pet_id']) ? 'selected' : ''; ?>
                  >
                    <?php
                      $petLabel = (string) ($pet['pet_name'] ?? 'Unnamed Pet');
                      if (($pet['breed'] ?? '') !== '') {
                          $petLabel .= ' — ' . (string) $pet['breed'];
                      }
                    ?>
                    <?php echo ddAdminEditBookingE($petLabel); ?> (Client ID <?php echo (int) ($pet['owner_id'] ?? 0); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="helper">Only pets belonging to the selected client should be used for a booking.</div>
            </div>

            <div class="field">
              <label for="service_type">Service Type</label>
              <select name="service_type" id="service_type" required>
                <?php foreach ($serviceOptions as $value => $label): ?>
                  <option value="<?php echo ddAdminEditBookingE($value); ?>" <?php echo $form['service_type'] === $value ? 'selected' : ''; ?>>
                    <?php echo ddAdminEditBookingE($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="duration_minutes">Duration (Minutes)</label>
              <input type="number" name="duration_minutes" id="duration_minutes" min="1" value="<?php echo ddAdminEditBookingE($form['duration_minutes']); ?>" required>
            </div>

            <div class="field">
              <label for="service_date">Service Date</label>
              <input type="date" name="service_date" id="service_date" value="<?php echo ddAdminEditBookingE($form['service_date']); ?>" required>
            </div>

            <div class="field">
              <label for="service_time">Service Time</label>
              <input type="time" name="service_time" id="service_time" value="<?php echo ddAdminEditBookingE($form['service_time']); ?>" required>
            </div>

            <div class="field">
              <label for="price">Price</label>
              <input type="number" name="price" id="price" step="0.01" min="0" value="<?php echo ddAdminEditBookingE($form['price']); ?>" required>
            </div>

            <div class="field">
              <label for="status">Status</label>
              <select name="status" id="status" required>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?php echo ddAdminEditBookingE($value); ?>" <?php echo $form['status'] === $value ? 'selected' : ''; ?>>
                    <?php echo ddAdminEditBookingE($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field full">
              <label for="assigned_walker_id">Assign Walker (Optional)</label>
              <select name="assigned_walker_id" id="assigned_walker_id">
                <option value="">No walker assigned yet</option>
                <?php foreach ($walkers as $walker): ?>
                  <option value="<?php echo (int) $walker['id']; ?>" <?php echo ((string) $walker['id'] === $form['assigned_walker_id']) ? 'selected' : ''; ?>>
                    <?php echo ddAdminEditBookingE((string) $walker['label']); ?><?php echo $walker['email'] !== '' ? ' — ' . ddAdminEditBookingE((string) $walker['email']) : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="helper">If you assign a walker while status is still pending, the booking will automatically move to confirmed.</div>
            </div>

            <div class="field full">
              <label for="admin_notes">Admin Notes</label>
              <textarea name="admin_notes" id="admin_notes" placeholder="Optional internal notes for this booking"><?php echo ddAdminEditBookingE($form['admin_notes']); ?></textarea>
            </div>
          </div>

          <div class="button-row">
            <button type="submit" class="btn btn-primary">Save Booking Changes</button>
            <a href="admin-bookings.php" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const userSelect = document.getElementById('user_id');
      const petSelect = document.getElementById('pet_id');

      if (!userSelect || !petSelect) {
        return;
      }

      const originalOptions = Array.from(petSelect.options).map(function (option) {
        return {
          value: option.value,
          text: option.text,
          ownerId: option.getAttribute('data-owner-id') || '',
          selected: option.selected
        };
      });

      function rebuildPets() {
        const selectedUserId = userSelect.value;
        const currentPetId = petSelect.value;

        petSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select pet';
        petSelect.appendChild(placeholder);

        originalOptions.forEach(function (option) {
          if (option.value === '') {
            return;
          }

          if (selectedUserId !== '' && option.ownerId !== selectedUserId) {
            return;
          }

          const el = document.createElement('option');
          el.value = option.value;
          el.textContent = option.text;
          el.setAttribute('data-owner-id', option.ownerId);

          if (option.value === currentPetId) {
            el.selected = true;
          }

          petSelect.appendChild(el);
        });
      }

      userSelect.addEventListener('change', function () {
        petSelect.value = '';
        rebuildPets();
      });

      rebuildPets();
    })();
  </script>
</body>
</html>