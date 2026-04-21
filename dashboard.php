<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function quotedIdentifier($value)
{
    return '"' . str_replace('"', '""', (string) $value) . '"';
}

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function isAdmin()
{
    return currentUserRole() === 'admin';
}

function currentUserId()
{
    $keys = array('user_id', 'member_id', 'client_id', 'id');

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function isMemberLike()
{
    return currentUserRole() === 'member' || currentUserId() > 0;
}

if (!isMemberLike() && !isAdmin()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', (string) $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = array();
        return array();
    }
}

function hasColumn(PDO $pdo, $table, $column)
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (hasColumn($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function valueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function safeFetchAll(PDO $pdo, $sql, array $params = array())
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

function safeFetchOne(PDO $pdo, $sql, array $params = array())
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

function ensureTableColumn(PDO $pdo, $table, $column, $definition)
{
    if (!hasTable($pdo, $table)) {
        return;
    }

    if (hasColumn($pdo, $table, $column)) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE ' . quotedIdentifier($table) . ' ADD COLUMN ' . quotedIdentifier($column) . ' ' . $definition);
    } catch (Throwable $e) {
    }
}

function ensureDogJourneySchema(PDO $pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dog_journey_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                baseline_walks INTEGER NOT NULL DEFAULT 0,
                baseline_daycare_sessions INTEGER NOT NULL DEFAULT 0,
                baseline_boarding_nights INTEGER NOT NULL DEFAULT 0,
                baseline_drop_in_sessions INTEGER NOT NULL DEFAULT 0,
                baseline_sitting_sessions INTEGER NOT NULL DEFAULT 0,
                favorite_service TEXT DEFAULT '',
                milestone_badge TEXT DEFAULT '',
                journey_note TEXT DEFAULT '',
                journey_highlight TEXT DEFAULT '',
                last_service_date TEXT DEFAULT '',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_walks', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_daycare_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_boarding_nights', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_drop_in_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_sitting_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'favorite_service', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'milestone_badge', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'journey_note', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'journey_highlight', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'last_service_date', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'updated_at', "TEXT DEFAULT CURRENT_TIMESTAMP");

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dog_journey_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                entry_type TEXT NOT NULL DEFAULT 'note',
                service_type TEXT DEFAULT '',
                entry_title TEXT DEFAULT '',
                entry_body TEXT DEFAULT '',
                entry_date TEXT DEFAULT '',
                created_by_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_dog_journey_user_pet ON dog_journey_profiles(user_id, pet_id)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dog_journey_entries_user_pet ON dog_journey_entries(user_id, pet_id)');
    } catch (Throwable $e) {
    }
}

function normalizeStatus($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'new' || $status === 'open' || $status === 'unassigned') {
        return 'available';
    }
    if ($status === 'assigned' || $status === 'confirmed') {
        return 'accepted';
    }
    if ($status === 'active' || $status === 'walking' || $status === 'started' || $status === 'in progress') {
        return 'in_progress';
    }
    if ($status === 'done' || $status === 'finished' || $status === 'closed') {
        return 'completed';
    }
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'cancelled by client' || $status === 'cancelled by walker' || $status === 'void') {
        return 'cancelled';
    }
    if ($status === 'released back' || $status === 're-opened' || $status === 'reopened') {
        return 'released';
    }

    return $status !== '' ? $status : 'pending';
}

function normalizePaymentStatus($status, $price = null)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'paid' || $status === 'succeeded' || $status === 'success' || $status === 'completed') {
        return 'paid';
    }

    if (
        $status === 'pending'
        || $status === 'processing'
        || $status === 'requires_action'
        || $status === 'requires_payment_method'
        || $status === 'awaiting_payment'
    ) {
        return 'pending';
    }

    if (
        $status === 'not_required'
        || $status === 'included'
        || $status === 'covered'
        || $status === 'n/a'
        || $status === 'none'
    ) {
        return 'not_required';
    }

    if ($status === 'unpaid' || $status === 'failed' || $status === 'declined' || $status === 'cancelled' || $status === 'canceled') {
        return 'unpaid';
    }

    if (is_numeric($price) && (float) $price <= 0) {
        return 'not_required';
    }

    return 'unpaid';
}

function normalizeServiceType($type)
{
    $type = strtolower(trim((string) $type));
    $type = str_replace(array('-', ' '), '_', $type);

    if ($type === '') {
        return 'service';
    }
    if (strpos($type, 'walk') !== false) {
        return 'walk';
    }
    if (strpos($type, 'board') !== false) {
        return 'boarding';
    }
    if (strpos($type, 'daycare') !== false || strpos($type, 'day_care') !== false) {
        return 'daycare';
    }
    if (strpos($type, 'sit') !== false) {
        return 'sitting';
    }
    if (strpos($type, 'drop') !== false) {
        return 'drop-in';
    }
    if ($type === 'service_credit') {
        return 'service_credit';
    }

    return str_replace('_', '-', $type);
}

function normalizeEntitlementType($type)
{
    $type = strtolower(trim((string) $type));
    $type = str_replace(array('-', ' '), '_', $type);

    if ($type === 'dropin') {
        return 'drop_in';
    }
    if ($type === 'boarding') {
        return 'boarding_night';
    }

    return $type;
}

function formatDateDisplay($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'Not scheduled';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function formatTimeDisplay($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return 'Time not set';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function formatMoney($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_numeric($value)) {
        return '$' . number_format((float) $value, 2);
    }
    return '$' . (string) $value;
}

function formatServiceLabel($serviceType)
{
    $serviceType = (string) $serviceType;

    if ($serviceType === 'drop-in') {
        return 'Drop-In';
    }
    if ($serviceType === 'service_credit') {
        return 'Service Credit';
    }

    return ucwords(str_replace(array('_', '-'), ' ', $serviceType));
}

function paymentBadgeClass($status)
{
    if ($status === 'paid') {
        return 'badge-paid';
    }
    if ($status === 'pending') {
        return 'badge-pay-pending';
    }
    if ($status === 'not_required') {
        return 'badge-pay-none';
    }

    return 'badge-unpaid';
}

function paymentBadgeLabel($status)
{
    if ($status === 'paid') {
        return 'Paid';
    }
    if ($status === 'pending') {
        return 'Pending Payment';
    }
    if ($status === 'not_required') {
        return 'No Payment Required';
    }

    return 'Unpaid';
}

function bookingBaseTable(PDO $pdo)
{
    $candidates = array('bookings', 'walks');

    foreach ($candidates as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function loadPetNameById(PDO $pdo, $petId)
{
    $petId = (int) $petId;

    if ($petId <= 0) {
        return '';
    }

    $tables = array('pets', 'dogs');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('name', 'pet_name', 'dog_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $petId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadWorkerName(PDO $pdo, $workerId)
{
    $workerId = (int) $workerId;

    if ($workerId <= 0) {
        return '';
    }

    $tables = array('walkers', 'staff', 'employees', 'users');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'walker_id', 'staff_id', 'employee_id', 'user_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'walker_name', 'staff_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $workerId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadMemberName(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return '';
    }

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'client_name', 'member_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadMemberCreatedAt(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return '';
    }

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        $createdCol = firstExistingColumn($pdo, $table, array('created_at', 'joined_at', 'registered_at', 'date_created'));

        if ($idCol === null || $createdCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$createdCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
            continue;
        }

        $value = $stmt->fetchColumn();
        if ($value !== false && trim((string) $value) !== '') {
            return (string) $value;
        }
    }

    return '';
}

function countUnreadNotificationsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $readCol = firstExistingColumn($pdo, $table, array('is_read', 'read_status', 'seen', 'viewed'));
        $userCol = firstExistingColumn($pdo, $table, array('user_id'));
        $memberCol = firstExistingColumn($pdo, $table, array('member_id'));

        if ($readCol === null) {
            continue;
        }

        if ($userCol !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$userCol} = :id AND COALESCE({$readCol}, 0) = 0");
            if (safeExecute($stmt, array(':id' => $userId))) {
                return (int) $stmt->fetchColumn();
            }
        }

        if ($memberCol !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$memberCol} = :id AND COALESCE({$readCol}, 0) = 0");
            if (safeExecute($stmt, array(':id' => $userId))) {
                return (int) $stmt->fetchColumn();
            }
        }
    }

    return 0;
}

function fetchMemberPetsDetailed(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $pets = array();
    $seen = array();

    foreach (array('pets', 'dogs') as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $ownerCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'owner_id', 'client_id'));
        $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('name', 'pet_name', 'dog_name'));
        $breedCol = firstExistingColumn($pdo, $table, array('breed'));
        $ageCol = firstExistingColumn($pdo, $table, array('age', 'dog_age'));
        $notesCol = firstExistingColumn($pdo, $table, array('notes', 'care_notes'));
        $createdCol = firstExistingColumn($pdo, $table, array('created_at', 'created_on'));

        if ($ownerCol === null || $idCol === null || $nameCol === null) {
            continue;
        }

        $select = array(
            quotedIdentifier($idCol) . ' AS pet_id',
            quotedIdentifier($nameCol) . ' AS pet_name',
            ($breedCol !== null ? quotedIdentifier($breedCol) : "''") . ' AS breed',
            ($ageCol !== null ? quotedIdentifier($ageCol) : "''") . ' AS age',
            ($notesCol !== null ? quotedIdentifier($notesCol) : "''") . ' AS notes',
            ($createdCol !== null ? quotedIdentifier($createdCol) : "''") . ' AS created_at',
        );

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . quotedIdentifier($table) . ' WHERE ' . quotedIdentifier($ownerCol) . ' = :user_id ORDER BY ' . quotedIdentifier($idCol) . ' DESC';
        $rows = safeFetchAll($pdo, $sql, array(':user_id' => $userId));

        foreach ($rows as $row) {
            $petId = (int) valueFromRow($row, array('pet_id'), 0);
            $petName = trim((string) valueFromRow($row, array('pet_name'), ''));

            if ($petId <= 0 || $petName === '') {
                continue;
            }

            if (isset($seen[$petId])) {
                continue;
            }

            $seen[$petId] = true;
            $pets[] = array(
                'pet_id' => $petId,
                'pet_name' => $petName,
                'breed' => (string) valueFromRow($row, array('breed'), ''),
                'age' => (string) valueFromRow($row, array('age'), ''),
                'notes' => (string) valueFromRow($row, array('notes'), ''),
                'created_at' => (string) valueFromRow($row, array('created_at'), ''),
                'source_table' => $table,
            );
        }
    }

    return $pets;
}

function fetchMemberBookings(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $table = bookingBaseTable($pdo);

    if ($table === null || $userId <= 0) {
        return array();
    }

    $userCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'client_id', 'owner_id'));
    if ($userCol === null) {
        return array();
    }

    $orderCol = firstExistingColumn($pdo, $table, array(
        'service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'created_at', 'id'
    ));
    if ($orderCol === null) {
        $orderCol = 'id';
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$userCol} = :user_id ORDER BY {$orderCol} ASC, rowid DESC");
    if (!safeExecute($stmt, array(':user_id' => $userId))) {
        return array();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $normalized = array();

    foreach ($rows as $row) {
        $id = (int) valueFromRow($row, array('id', 'booking_id', 'walk_id'), 0);
        if ($id <= 0) {
            continue;
        }

        $workerId = (int) valueFromRow($row, array('walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'), 0);
        $petId = (int) valueFromRow($row, array('pet_id', 'dog_id'), 0);

        $petName = (string) valueFromRow($row, array('pet_name', 'dog_name', 'name'), '');
        if ($petName === '' && $petId > 0) {
            $petName = loadPetNameById($pdo, $petId);
        }

        $price = valueFromRow($row, array('price', 'total_price', 'amount'), '');
        $paymentRaw = valueFromRow($row, array('payment_status', 'payment_state'), '');
        $serviceType = normalizeServiceType((string) valueFromRow($row, array('service_type', 'type', 'booking_type', 'category'), 'service'));
        $quantity = 1;

        if ($serviceType === 'boarding') {
            $quantityCandidate = valueFromRow($row, array('quantity', 'nights', 'boarding_nights', 'total_nights'), 1);
            $quantity = is_numeric($quantityCandidate) ? max(1, (int) $quantityCandidate) : 1;
        }

        $normalized[] = array(
            'id' => $id,
            'user_id' => $userId,
            'worker_id' => $workerId,
            'worker_name' => $workerId > 0 ? loadWorkerName($pdo, $workerId) : '',
            'service_type' => $serviceType,
            'status' => normalizeStatus((string) valueFromRow($row, array('status', 'booking_status', 'service_status', 'walk_status'), 'pending')),
            'payment_status' => normalizePaymentStatus($paymentRaw, $price),
            'service_date' => (string) valueFromRow($row, array('service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'), ''),
            'service_time' => (string) valueFromRow($row, array('service_time', 'booking_time', 'walk_time', 'time', 'start_time', 'scheduled_time'), ''),
            'duration' => (string) valueFromRow($row, array('duration_minutes', 'duration', 'minutes'), ''),
            'price' => $price,
            'notes' => (string) valueFromRow($row, array('notes', 'special_instructions', 'instructions', 'care_notes'), ''),
            'pet_id' => $petId,
            'pet_name' => $petName,
            'quantity' => $quantity,
        );
    }

    return $normalized;
}

function countMemberPets(PDO $pdo, $userId)
{
    return count(fetchMemberPetsDetailed($pdo, $userId));
}

function hasActiveTracking(PDO $pdo, array $booking)
{
    if ((isset($booking['service_type']) ? $booking['service_type'] : '') !== 'walk') {
        return false;
    }

    $status = normalizeStatus((string) (isset($booking['status']) ? $booking['status'] : ''));
    if (in_array($status, array('accepted', 'in_progress', 'completed'), true)) {
        return true;
    }

    $tables = array('walk_sessions', 'tracking_sessions');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $bookingCol = firstExistingColumn($pdo, $table, array('booking_id', 'walk_id'));
        if ($bookingCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$bookingCol} = :id ORDER BY rowid DESC LIMIT 1");
        if (!safeExecute($stmt, array(':id' => (int) (isset($booking['id']) ? $booking['id'] : 0)))) {
            continue;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return true;
        }
    }

    return false;
}

function isFutureOrToday($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return false;
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return false;
    }

    return date('Y-m-d', $ts) >= date('Y-m-d');
}

function sortBookings(array $rows)
{
    usort($rows, function ($a, $b) {
        $aTs = strtotime(trim((isset($a['service_date']) ? $a['service_date'] : '') . ' ' . (isset($a['service_time']) ? $a['service_time'] : '')));
        $bTs = strtotime(trim((isset($b['service_date']) ? $b['service_date'] : '') . ' ' . (isset($b['service_time']) ? $b['service_time'] : '')));

        if ($aTs === false) {
            $aTs = PHP_INT_MAX;
        }
        if ($bTs === false) {
            $bTs = PHP_INT_MAX;
        }

        if ($aTs === $bTs) {
            return 0;
        }

        return $aTs < $bTs ? -1 : 1;
    });

    return $rows;
}

function statusBadgeClass($status)
{
    if ($status === 'accepted') {
        return 'badge-accepted';
    }
    if ($status === 'in_progress') {
        return 'badge-progress';
    }
    if ($status === 'completed') {
        return 'badge-complete';
    }
    if ($status === 'cancelled') {
        return 'badge-cancelled';
    }
    if ($status === 'released') {
        return 'badge-released';
    }
    if ($status === 'available') {
        return 'badge-available';
    }

    return 'badge-pending';
}

function founderPlanDefaults()
{
    return array(
        'founder_walk_club' => array(
            'name' => 'Founder Walk Club',
            'walk' => 12,
            'daycare' => 0,
            'drop_in' => 0,
            'boarding_night' => 0,
            'service_credit' => 0,
        ),
        'founder_care_club' => array(
            'name' => 'Founder Care Club',
            'walk' => 16,
            'daycare' => 2,
            'drop_in' => 2,
            'boarding_night' => 0,
            'service_credit' => 0,
        ),
        'founder_elite_club' => array(
            'name' => 'Founder Elite Club',
            'walk' => 20,
            'daycare' => 4,
            'drop_in' => 4,
            'boarding_night' => 3,
            'service_credit' => 0,
        ),
    );
}

function getMembershipSummary(PDO $pdo, int $userId)
{
    $result = array(
        'membership_name' => 'No Membership',
        'membership_id' => 0,
        'plan_slug' => '',
        'renewal_count' => 0,
        'walk' => 0,
        'daycare' => 0,
        'drop-in' => 0,
        'boarding_night' => 0,
        'service_credit' => 0,
    );

    if ($userId <= 0) {
        return $result;
    }

    if (!hasTable($pdo, 'member_memberships')) {
        return $result;
    }

    $memberIdCol = firstExistingColumn($pdo, 'member_memberships', array('member_id', 'user_id', 'client_id'));
    $planIdCol = firstExistingColumn($pdo, 'member_memberships', array('plan_id'));
    $membershipIdCol = firstExistingColumn($pdo, 'member_memberships', array('id'));
    $createdCol = firstExistingColumn($pdo, 'member_memberships', array('created_at', 'updated_at', 'id'));

    if ($memberIdCol === null || $planIdCol === null || $membershipIdCol === null) {
        return $result;
    }

    $orderBy = $createdCol !== null ? $createdCol : $membershipIdCol;

    $stmt = $pdo->prepare("
        SELECT *
        FROM member_memberships
        WHERE {$memberIdCol} = :member_id
        ORDER BY {$orderBy} DESC, rowid DESC
        LIMIT 1
    ");
    if (!safeExecute($stmt, array(':member_id' => $userId))) {
        return $result;
    }

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$membership) {
        return $result;
    }

    $membershipId = (int) valueFromRow($membership, array($membershipIdCol), 0);
    $planId = (int) valueFromRow($membership, array($planIdCol), 0);

    $result['membership_id'] = $membershipId;
    $result['renewal_count'] = (int) valueFromRow($membership, array('renewal_count'), 0);

    if ($planId > 0 && hasTable($pdo, 'membership_plans')) {
        $planNameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));
        $planSlugCol = firstExistingColumn($pdo, 'membership_plans', array('slug', 'plan_slug', 'code'));
        $planIdLookupCol = firstExistingColumn($pdo, 'membership_plans', array('id', 'plan_id'));

        if ($planIdLookupCol !== null) {
            $planStmt = $pdo->prepare("SELECT * FROM membership_plans WHERE {$planIdLookupCol} = :plan_id LIMIT 1");
            if (safeExecute($planStmt, array(':plan_id' => $planId))) {
                $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);

                if ($planRow) {
                    if ($planNameCol !== null && !empty($planRow[$planNameCol])) {
                        $result['membership_name'] = (string) $planRow[$planNameCol];
                    }

                    if ($planSlugCol !== null && !empty($planRow[$planSlugCol])) {
                        $result['plan_slug'] = strtolower(trim((string) $planRow[$planSlugCol]));
                    }
                }
            }
        }
    }

    $hasEntitlementRows = false;

    if ($membershipId > 0 && hasTable($pdo, 'membership_entitlements')) {
        $entMembershipCol = firstExistingColumn($pdo, 'membership_entitlements', array('membership_id'));
        $serviceCol = firstExistingColumn($pdo, 'membership_entitlements', array('entitlement_type', 'service_type', 'type'));
        $remainingCol = firstExistingColumn($pdo, 'membership_entitlements', array('remaining_units', 'units_remaining', 'balance'));
        $totalCol = firstExistingColumn($pdo, 'membership_entitlements', array('total'));
        $usedCol = firstExistingColumn($pdo, 'membership_entitlements', array('used'));

        if ($entMembershipCol !== null && $serviceCol !== null) {
            $entStmt = $pdo->prepare("SELECT * FROM membership_entitlements WHERE {$entMembershipCol} = :membership_id");

            if (safeExecute($entStmt, array(':membership_id' => $membershipId))) {
                $entRows = $entStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($entRows as $entRow) {
                    $hasEntitlementRows = true;

                    $serviceType = normalizeEntitlementType((string) valueFromRow($entRow, array($serviceCol), ''));
                    $remainingUnits = 0;

                    if ($remainingCol !== null && isset($entRow[$remainingCol]) && $entRow[$remainingCol] !== '') {
                        $remainingUnits = (int) $entRow[$remainingCol];
                    } else {
                        $totalUnits = $totalCol !== null ? (int) valueFromRow($entRow, array($totalCol), 0) : 0;
                        $usedUnits = $usedCol !== null ? (int) valueFromRow($entRow, array($usedCol), 0) : 0;
                        $remainingUnits = max(0, $totalUnits - $usedUnits);
                    }

                    if ($serviceType === 'walk') {
                        $result['walk'] = $remainingUnits;
                    } elseif ($serviceType === 'daycare') {
                        $result['daycare'] = $remainingUnits;
                    } elseif ($serviceType === 'drop_in') {
                        $result['drop-in'] = $remainingUnits;
                    } elseif ($serviceType === 'boarding_night') {
                        $result['boarding_night'] = $remainingUnits;
                    } elseif ($serviceType === 'service_credit') {
                        $result['service_credit'] = $remainingUnits;
                    }
                }
            }
        }
    }

    if (!$hasEntitlementRows && $result['plan_slug'] !== '') {
        $defaults = founderPlanDefaults();
        if (isset($defaults[$result['plan_slug']])) {
            $defaultPlan = $defaults[$result['plan_slug']];
            $result['membership_name'] = $defaultPlan['name'];
            $result['walk'] = (int) $defaultPlan['walk'];
            $result['daycare'] = (int) $defaultPlan['daycare'];
            $result['drop-in'] = (int) $defaultPlan['drop_in'];
            $result['boarding_night'] = (int) $defaultPlan['boarding_night'];
            $result['service_credit'] = (int) $defaultPlan['service_credit'];
        }
    }

    return $result;
}

function normalizePetKey($value)
{
    return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
}

function fetchDogJourneyProfileMap(PDO $pdo, $userId)
{
    $rows = array();

    if (!hasTable($pdo, 'dog_journey_profiles')) {
        return $rows;
    }

    $items = safeFetchAll(
        $pdo,
        'SELECT * FROM dog_journey_profiles WHERE user_id = :user_id ORDER BY pet_id ASC, id ASC',
        array(':user_id' => (int) $userId)
    );

    foreach ($items as $item) {
        $rows[(int) valueFromRow($item, array('pet_id'), 0)] = $item;
    }

    return $rows;
}

function fetchDogJourneyEntriesMap(PDO $pdo, $userId, $limitPerPet = 4)
{
    if (!hasTable($pdo, 'dog_journey_entries')) {
        return array();
    }

    $rows = safeFetchAll(
        $pdo,
        "SELECT * FROM dog_journey_entries WHERE user_id = :user_id ORDER BY COALESCE(NULLIF(entry_date, ''), created_at) DESC, id DESC",
        array(':user_id' => (int) $userId)
    );

    $map = array();
    foreach ($rows as $row) {
        $petId = (int) valueFromRow($row, array('pet_id'), 0);
        if ($petId <= 0) {
            continue;
        }

        if (!isset($map[$petId])) {
            $map[$petId] = array();
        }

        if (count($map[$petId]) >= (int) $limitPerPet) {
            continue;
        }

        $map[$petId][] = array(
            'entry_type' => (string) valueFromRow($row, array('entry_type'), 'note'),
            'service_type' => (string) valueFromRow($row, array('service_type'), ''),
            'entry_title' => (string) valueFromRow($row, array('entry_title'), ''),
            'entry_body' => (string) valueFromRow($row, array('entry_body'), ''),
            'entry_date' => (string) valueFromRow($row, array('entry_date', 'created_at'), ''),
            'created_at' => (string) valueFromRow($row, array('created_at'), ''),
            'created_by_admin' => (int) valueFromRow($row, array('created_by_admin'), 0),
        );
    }

    return $map;
}

function dogJourneyMomentLabel(array $entry)
{
    $type = strtolower(trim((string) valueFromRow($entry, array('entry_type'), 'note')));

    if ($type === 'badge_award') {
        return 'Badge Awarded';
    }

    if ($type === 'milestone') {
        return 'Milestone';
    }

    return 'Journey Moment';
}

function buildAutoJourneyBadge($totalServices)
{
    $totalServices = (int) $totalServices;

    if ($totalServices >= 30) {
        return 'Dorian’s Inner Circle';
    }
    if ($totalServices >= 15) {
        return 'VIP Companion';
    }
    if ($totalServices >= 5) {
        return 'Routine Favorite';
    }
    if ($totalServices >= 1) {
        return 'First Strolls';
    }

    return 'Journey Begins';
}

function buildJourneyHighlight(array $counts, $petName)
{
    $totalServices =
        (int) $counts['walk'] +
        (int) $counts['daycare'] +
        (int) $counts['boarding_night'] +
        (int) $counts['drop_in'] +
        (int) $counts['sitting'];

    if ($totalServices <= 0) {
        return $petName . ' is ready to begin their Dog Journey.';
    }

    arsort($counts);
    $topKey = (string) key($counts);
    $topValue = (int) current($counts);

    return $petName . ' has ' . $totalServices . ' total recorded services so far, with ' . $topValue . ' in ' . formatServiceLabel($topKey) . '.';
}

function buildDogJourneyCards(PDO $pdo, $userId, array $pets, array $bookings, $memberCreatedAt = '')
{
    $profiles = fetchDogJourneyProfileMap($pdo, $userId);
    $entriesMap = fetchDogJourneyEntriesMap($pdo, $userId);
    $cards = array();

    foreach ($pets as $pet) {
        $petId = (int) valueFromRow($pet, array('pet_id'), 0);
        $petName = (string) valueFromRow($pet, array('pet_name'), 'Dog');
        $profile = isset($profiles[$petId]) ? $profiles[$petId] : array();

        $liveCounts = array(
            'walk' => 0,
            'daycare' => 0,
            'boarding_night' => 0,
            'drop_in' => 0,
            'sitting' => 0,
        );

        $latestLiveDate = '';

        foreach ($bookings as $booking) {
            $bookingPetId = (int) valueFromRow($booking, array('pet_id'), 0);
            $bookingPetName = normalizePetKey((string) valueFromRow($booking, array('pet_name'), ''));
            $matchesPet = false;

            if ($petId > 0 && $bookingPetId > 0 && $petId === $bookingPetId) {
                $matchesPet = true;
            } elseif ($bookingPetId <= 0 && $bookingPetName !== '' && $bookingPetName === normalizePetKey($petName)) {
                $matchesPet = true;
            }

            if (!$matchesPet) {
                continue;
            }

            if ((string) valueFromRow($booking, array('status'), '') === 'cancelled') {
                continue;
            }

            $serviceType = (string) valueFromRow($booking, array('service_type'), 'service');
            $quantity = (int) valueFromRow($booking, array('quantity'), 1);
            if ($quantity < 1) {
                $quantity = 1;
            }

            if ($serviceType === 'walk') {
                $liveCounts['walk'] += 1;
            } elseif ($serviceType === 'daycare') {
                $liveCounts['daycare'] += 1;
            } elseif ($serviceType === 'boarding') {
                $liveCounts['boarding_night'] += $quantity;
            } elseif ($serviceType === 'drop-in') {
                $liveCounts['drop_in'] += 1;
            } elseif ($serviceType === 'sitting') {
                $liveCounts['sitting'] += 1;
            }

            $serviceDate = trim((string) valueFromRow($booking, array('service_date'), ''));
            if ($serviceDate !== '') {
                if ($latestLiveDate === '' || strtotime($serviceDate) > strtotime($latestLiveDate)) {
                    $latestLiveDate = $serviceDate;
                }
            }
        }

        $baselineCounts = array(
            'walk' => (int) valueFromRow($profile, array('baseline_walks'), 0),
            'daycare' => (int) valueFromRow($profile, array('baseline_daycare_sessions'), 0),
            'boarding_night' => (int) valueFromRow($profile, array('baseline_boarding_nights'), 0),
            'drop_in' => (int) valueFromRow($profile, array('baseline_drop_in_sessions'), 0),
            'sitting' => (int) valueFromRow($profile, array('baseline_sitting_sessions'), 0),
        );

        $displayCounts = array(
            'walk' => $baselineCounts['walk'] + $liveCounts['walk'],
            'daycare' => $baselineCounts['daycare'] + $liveCounts['daycare'],
            'boarding_night' => $baselineCounts['boarding_night'] + $liveCounts['boarding_night'],
            'drop_in' => $baselineCounts['drop_in'] + $liveCounts['drop_in'],
            'sitting' => $baselineCounts['sitting'] + $liveCounts['sitting'],
        );

        arsort($displayCounts);
        $autoFavorite = (string) key($displayCounts);
        $favoriteService = trim((string) valueFromRow($profile, array('favorite_service'), ''));
        if ($favoriteService === '' || !in_array($favoriteService, array('walk', 'daycare', 'boarding_night', 'drop_in', 'sitting'), true)) {
            $favoriteService = $displayCounts[$autoFavorite] > 0 ? $autoFavorite : '';
        }

        $totalServices =
            (int) $displayCounts['walk'] +
            (int) $displayCounts['daycare'] +
            (int) $displayCounts['boarding_night'] +
            (int) $displayCounts['drop_in'] +
            (int) $displayCounts['sitting'];

        $badge = trim((string) valueFromRow($profile, array('milestone_badge'), ''));
        if ($badge === '') {
            $badge = buildAutoJourneyBadge($totalServices);
        }

        $storedLastServiceDate = trim((string) valueFromRow($profile, array('last_service_date'), ''));
        $lastServiceDate = $latestLiveDate !== '' ? $latestLiveDate : $storedLastServiceDate;

        $journeyHighlight = trim((string) valueFromRow($profile, array('journey_highlight'), ''));
        if ($journeyHighlight === '') {
            $journeyHighlight = buildJourneyHighlight($displayCounts, $petName);
        }

        $cards[] = array(
            'pet_id' => $petId,
            'pet_name' => $petName,
            'breed' => (string) valueFromRow($pet, array('breed'), ''),
            'age' => (string) valueFromRow($pet, array('age'), ''),
            'member_since' => $memberCreatedAt,
            'last_service_date' => $lastServiceDate,
            'favorite_service' => $favoriteService,
            'milestone_badge' => $badge,
            'journey_note' => (string) valueFromRow($profile, array('journey_note'), ''),
            'journey_highlight' => $journeyHighlight,
            'counts' => $displayCounts,
            'baseline_counts' => $baselineCounts,
            'live_counts' => $liveCounts,
            'total_services' => $totalServices,
            'journey_entries' => isset($entriesMap[$petId]) ? $entriesMap[$petId] : array(),
        );
    }

    return $cards;
}



function ensureBadgeVaultSchema(PDO $pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                badge_key TEXT NOT NULL,
                badge_name TEXT NOT NULL DEFAULT '',
                badge_mark TEXT NOT NULL DEFAULT '',
                badge_group TEXT NOT NULL DEFAULT '',
                badge_family TEXT NOT NULL DEFAULT '',
                badge_scope TEXT NOT NULL DEFAULT 'member',
                theme_class TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                reward_title TEXT NOT NULL DEFAULT '',
                reward_note TEXT NOT NULL DEFAULT '',
                source_type TEXT NOT NULL DEFAULT '',
                source_reference TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 1,
                is_featured INTEGER NOT NULL DEFAULT 1,
                unlocked_at TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    ensureTableColumn($pdo, 'member_badges', 'pet_id', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'member_badges', 'badge_key', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_name', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_mark', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_group', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_family', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_scope', "TEXT NOT NULL DEFAULT 'member'");
    ensureTableColumn($pdo, 'member_badges', 'theme_class', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'description', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'reward_title', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'reward_note', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'source_type', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'source_reference', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
    ensureTableColumn($pdo, 'member_badges', 'is_featured', 'INTEGER NOT NULL DEFAULT 1');
    ensureTableColumn($pdo, 'member_badges', 'unlocked_at', "TEXT DEFAULT CURRENT_TIMESTAMP");
    ensureTableColumn($pdo, 'member_badges', 'created_at', "TEXT DEFAULT CURRENT_TIMESTAMP");
    ensureTableColumn($pdo, 'member_badges', 'updated_at', "TEXT DEFAULT CURRENT_TIMESTAMP");

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_member_badges_user_pet_key ON member_badges(user_id, pet_id, badge_key)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_member_badges_user_group_active ON member_badges(user_id, badge_group, is_active)');
    } catch (Throwable $e) {
    }
}

function normalizeBadgeKey($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? $value : 'badge';
}

function badgeMarkFromName($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'BDG';
    }

    $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $letters = '';

    foreach ((array) $words as $word) {
        $letters .= strtoupper(substr((string) $word, 0, 1));
        if (strlen($letters) >= 3) {
            break;
        }
    }

    if ($letters === '') {
        $letters = strtoupper(substr((string) preg_replace('/[^a-z0-9]/i', '', $name), 0, 3));
    }

    return $letters !== '' ? $letters : 'BDG';
}

function founderBadgeCatalogDetailed()
{
    return array(
        'founder_walk_club' => array(
            'badge_key' => 'founder_walk_club',
            'slug' => 'founder_walk_club',
            'membership_name' => 'Founder Walk Club',
            'badge_name' => 'Founding Walker',
            'badge_mark' => 'FW',
            'theme_class' => 'badge-tier-walk',
            'description' => 'Reserved for members who locked in Founder Walk Club access and became part of the first premium walk circle.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ),
        'founder_care_club' => array(
            'badge_key' => 'founder_care_club',
            'slug' => 'founder_care_club',
            'membership_name' => 'Founder Care Club',
            'badge_name' => 'Care Circle Founder',
            'badge_mark' => 'FC',
            'theme_class' => 'badge-tier-care',
            'description' => 'Awarded to members who secured Founder Care Club access with expanded recurring care benefits.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ),
        'founder_elite_club' => array(
            'badge_key' => 'founder_elite_club',
            'slug' => 'founder_elite_club',
            'membership_name' => 'Founder Elite Club',
            'badge_name' => 'Elite Founding Member',
            'badge_mark' => 'FE',
            'theme_class' => 'badge-tier-elite',
            'description' => 'The highest founder distinction for members who entered the Founder Elite Club collection.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ),
    );
}

function dogJourneyBadgeCatalogDetailed()
{
    return array(
        'journey_begins' => array(
            'badge_key' => 'journey_begins',
            'badge_name' => 'Journey Begins',
            'badge_mark' => 'JB',
            'theme_class' => 'badge-tier-journey',
            'description' => 'The first Dog Journey milestone, created when a dog profile begins building its care history.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ),
        'first_strolls' => array(
            'badge_key' => 'first_strolls',
            'badge_name' => 'First Strolls',
            'badge_mark' => 'FS',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Unlocked after a dog records its first meaningful set of services and begins a routine.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ),
        'routine_favorite' => array(
            'badge_key' => 'routine_favorite',
            'badge_name' => 'Routine Favorite',
            'badge_mark' => 'RF',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Marks a dog that has settled into a dependable luxury care rhythm with Doggie Dorian’s.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ),
        'vip_companion' => array(
            'badge_key' => 'vip_companion',
            'badge_name' => 'VIP Companion',
            'badge_mark' => 'VC',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Reserved for dogs with substantial service history and a strong premium care journey.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ),
        'dorians_inner_circle' => array(
            'badge_key' => 'dorians_inner_circle',
            'badge_name' => 'Dorian’s Inner Circle',
            'badge_mark' => 'DI',
            'theme_class' => 'badge-tier-journey',
            'description' => 'The signature Dog Journey distinction for dogs with deep ongoing service history.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ),
    );
}

function standardJourneyBadgeKeyByName($name)
{
    $name = strtolower(trim((string) $name));
    $catalog = dogJourneyBadgeCatalogDetailed();

    foreach ($catalog as $badgeKey => $config) {
        if (strtolower((string) $config['badge_name']) === $name) {
            return (string) $badgeKey;
        }
    }

    return '';
}

function awardOrUpdateMemberBadge(PDO $pdo, array $payload)
{
    if (!hasTable($pdo, 'member_badges')) {
        return;
    }

    $userId = (int) valueFromRow($payload, array('user_id'), 0);
    $petId = (int) valueFromRow($payload, array('pet_id'), 0);
    $badgeKey = trim((string) valueFromRow($payload, array('badge_key'), ''));

    if ($userId <= 0 || $badgeKey === '') {
        return;
    }

    $existing = safeFetchOne(
        $pdo,
        'SELECT id, unlocked_at FROM member_badges WHERE user_id = :user_id AND pet_id = :pet_id AND badge_key = :badge_key LIMIT 1',
        array(
            ':user_id' => $userId,
            ':pet_id' => $petId,
            ':badge_key' => $badgeKey,
        )
    );

    $params = array(
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':badge_key' => $badgeKey,
        ':badge_name' => trim((string) valueFromRow($payload, array('badge_name'), '')),
        ':badge_mark' => trim((string) valueFromRow($payload, array('badge_mark'), '')),
        ':badge_group' => trim((string) valueFromRow($payload, array('badge_group'), '')),
        ':badge_family' => trim((string) valueFromRow($payload, array('badge_family'), '')),
        ':badge_scope' => trim((string) valueFromRow($payload, array('badge_scope'), 'member')),
        ':theme_class' => trim((string) valueFromRow($payload, array('theme_class'), '')),
        ':description' => trim((string) valueFromRow($payload, array('description'), '')),
        ':reward_title' => trim((string) valueFromRow($payload, array('reward_title'), '')),
        ':reward_note' => trim((string) valueFromRow($payload, array('reward_note'), '')),
        ':source_type' => trim((string) valueFromRow($payload, array('source_type'), '')),
        ':source_reference' => trim((string) valueFromRow($payload, array('source_reference'), '')),
        ':is_active' => (int) valueFromRow($payload, array('is_active'), 1) ? 1 : 0,
        ':is_featured' => (int) valueFromRow($payload, array('is_featured'), 1) ? 1 : 0,
        ':unlocked_at' => trim((string) valueFromRow($payload, array('unlocked_at'), '')),
    );

    if ($params[':badge_mark'] === '') {
        $params[':badge_mark'] = badgeMarkFromName($params[':badge_name']);
    }

    if ($existing) {
        $sql = "
            UPDATE member_badges
            SET
                badge_name = :badge_name,
                badge_mark = :badge_mark,
                badge_group = :badge_group,
                badge_family = :badge_family,
                badge_scope = :badge_scope,
                theme_class = :theme_class,
                description = :description,
                reward_title = :reward_title,
                reward_note = :reward_note,
                source_type = :source_type,
                source_reference = :source_reference,
                is_active = :is_active,
                is_featured = :is_featured,
                unlocked_at = CASE
                    WHEN :unlocked_at <> '' THEN :unlocked_at
                    ELSE COALESCE(NULLIF(unlocked_at, ''), CURRENT_TIMESTAMP)
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id
              AND pet_id = :pet_id
              AND badge_key = :badge_key
        ";
    } else {
        $sql = "
            INSERT INTO member_badges (
                user_id,
                pet_id,
                badge_key,
                badge_name,
                badge_mark,
                badge_group,
                badge_family,
                badge_scope,
                theme_class,
                description,
                reward_title,
                reward_note,
                source_type,
                source_reference,
                is_active,
                is_featured,
                unlocked_at,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :pet_id,
                :badge_key,
                :badge_name,
                :badge_mark,
                :badge_group,
                :badge_family,
                :badge_scope,
                :theme_class,
                :description,
                :reward_title,
                :reward_note,
                :source_type,
                :source_reference,
                :is_active,
                :is_featured,
                CASE WHEN :unlocked_at <> '' THEN :unlocked_at ELSE CURRENT_TIMESTAMP END,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";
    }

    safeFetchOne($pdo, 'SELECT 1', array()); // keep PDO warm for some shared hosts
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
    }
}

function fetchActiveMemberBadges(PDO $pdo, $userId, $badgeGroup = '')
{
    if (!hasTable($pdo, 'member_badges') || (int) $userId <= 0) {
        return array();
    }

    $sql = 'SELECT * FROM member_badges WHERE user_id = :user_id AND COALESCE(is_active, 1) = 1';
    $params = array(':user_id' => (int) $userId);

    if ($badgeGroup !== '') {
        $sql .= ' AND badge_group = :badge_group';
        $params[':badge_group'] = (string) $badgeGroup;
    }

    $sql .= " ORDER BY COALESCE(NULLIF(unlocked_at, ''), created_at) DESC, id DESC";

    return safeFetchAll($pdo, $sql, $params);
}

function founderBadgeSlugsFromMembershipHistory(PDO $pdo, $userId, array $catalog)
{
    $matched = array();

    if ((int) $userId <= 0 || !hasTable($pdo, 'member_memberships')) {
        return $matched;
    }

    $ownerCol = firstExistingColumn($pdo, 'member_memberships', array('member_id', 'user_id', 'client_id'));
    $planCol = firstExistingColumn($pdo, 'member_memberships', array('plan_id'));

    if ($ownerCol === null || $planCol === null) {
        return $matched;
    }

    $rows = safeFetchAll(
        $pdo,
        'SELECT * FROM ' . quotedIdentifier('member_memberships')
        . ' WHERE ' . quotedIdentifier($ownerCol) . ' = :owner_id'
        . ' ORDER BY ' . quotedIdentifier('id') . ' DESC',
        array(':owner_id' => (int) $userId)
    );

    foreach ($rows as $row) {
        $slug = '';
        $planId = (int) valueFromRow($row, array($planCol), 0);

        if ($planId > 0 && hasTable($pdo, 'membership_plans')) {
            $planIdCol = firstExistingColumn($pdo, 'membership_plans', array('id', 'plan_id'));
            $slugCol = firstExistingColumn($pdo, 'membership_plans', array('slug', 'plan_slug', 'code'));
            $nameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));

            if ($planIdCol !== null) {
                $planRow = safeFetchOne(
                    $pdo,
                    'SELECT * FROM ' . quotedIdentifier('membership_plans')
                    . ' WHERE ' . quotedIdentifier($planIdCol) . ' = :plan_id LIMIT 1',
                    array(':plan_id' => $planId)
                );

                if ($planRow !== null) {
                    $slug = strtolower(trim((string) valueFromRow($planRow, array($slugCol), '')));

                    if ($slug === '' && $nameCol !== null) {
                        $planName = strtolower(trim((string) valueFromRow($planRow, array($nameCol), '')));
                        foreach ($catalog as $catalogSlug => $config) {
                            if (strtolower((string) $config['membership_name']) === $planName) {
                                $slug = (string) $catalogSlug;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($slug !== '' && isset($catalog[$slug])) {
            $matched[$slug] = true;
        }
    }

    return $matched;
}

function syncFounderMembershipBadges(PDO $pdo, $userId, array $membershipSummary = array())
{
    $catalog = founderBadgeCatalogDetailed();
    $matched = founderBadgeSlugsFromMembershipHistory($pdo, $userId, $catalog);

    $currentSlug = strtolower(trim((string) valueFromRow($membershipSummary, array('plan_slug'), '')));
    if ($currentSlug !== '' && isset($catalog[$currentSlug])) {
        $matched[$currentSlug] = true;
    }

    foreach ($matched as $slug => $enabled) {
        if (!$enabled || !isset($catalog[$slug])) {
            continue;
        }

        $config = $catalog[$slug];
        awardOrUpdateMemberBadge($pdo, array(
            'user_id' => (int) $userId,
            'pet_id' => 0,
            'badge_key' => (string) $config['badge_key'],
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'badge_group' => 'founder',
            'badge_family' => 'founder_membership',
            'badge_scope' => 'member',
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'source_type' => 'membership_sync',
            'source_reference' => (string) $slug,
            'is_active' => 1,
            'is_featured' => 1,
        ));
    }
}

function buildFounderBadgeVault(PDO $pdo, $userId, array $membershipSummary = array())
{
    $catalog = founderBadgeCatalogDetailed();
    $items = array();

    foreach ($catalog as $slug => $config) {
        $items[$slug] = array(
            'slug' => (string) $slug,
            'badge_key' => (string) $config['badge_key'],
            'membership_name' => (string) $config['membership_name'],
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'unlocked' => false,
            'is_current' => false,
            'status_label' => 'Locked',
        );
    }

    foreach (fetchActiveMemberBadges($pdo, $userId, 'founder') as $badge) {
        $slug = strtolower(trim((string) valueFromRow($badge, array('badge_key'), '')));
        if ($slug === '' || !isset($items[$slug])) {
            continue;
        }

        $items[$slug]['unlocked'] = true;
    }

    $currentSlug = strtolower(trim((string) valueFromRow($membershipSummary, array('plan_slug'), '')));
    if ($currentSlug !== '' && isset($items[$currentSlug])) {
        $items[$currentSlug]['unlocked'] = true;
        $items[$currentSlug]['is_current'] = true;
    }

    foreach ($items as $slug => $item) {
        if (!empty($item['is_current'])) {
            $items[$slug]['status_label'] = 'Current Founder Badge';
        } elseif (!empty($item['unlocked'])) {
            $items[$slug]['status_label'] = 'Founder Badge Earned';
        }
    }

    return array_values($items);
}

function extractJourneyBadgeNameFromEntry(array $entry)
{
    $type = strtolower(trim((string) valueFromRow($entry, array('entry_type'), '')));
    if ($type !== 'badge_award') {
        return '';
    }

    $body = trim((string) valueFromRow($entry, array('entry_body'), ''));
    if ($body !== '') {
        $parts = preg_split('/\s*·\s*/u', $body);
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return trim((string) valueFromRow($entry, array('entry_title'), ''));
}

function syncJourneyMilestoneBadges(PDO $pdo, $userId, array $journeyCards)
{
    if ((int) $userId <= 0) {
        return;
    }

    $catalog = dogJourneyBadgeCatalogDetailed();

    foreach ($journeyCards as $card) {
        $petId = (int) valueFromRow($card, array('pet_id'), 0);
        $petName = trim((string) valueFromRow($card, array('pet_name'), 'Dog'));

        if ($petId <= 0) {
            continue;
        }

        $awards = array();
        $currentBadge = trim((string) valueFromRow($card, array('milestone_badge'), ''));
        if ($currentBadge !== '') {
            $awards[$currentBadge] = true;
        }

        foreach ((array) valueFromRow($card, array('journey_entries'), array()) as $entry) {
            $entryBadge = trim((string) extractJourneyBadgeNameFromEntry((array) $entry));
            if ($entryBadge !== '') {
                $awards[$entryBadge] = true;
            }
        }

        foreach (array_keys($awards) as $badgeName) {
            $standardKey = standardJourneyBadgeKeyByName($badgeName);

            if ($standardKey !== '' && isset($catalog[$standardKey])) {
                $config = $catalog[$standardKey];
                awardOrUpdateMemberBadge($pdo, array(
                    'user_id' => (int) $userId,
                    'pet_id' => $petId,
                    'badge_key' => (string) $standardKey,
                    'badge_name' => (string) $config['badge_name'],
                    'badge_mark' => (string) $config['badge_mark'],
                    'badge_group' => 'journey',
                    'badge_family' => 'journey_milestone',
                    'badge_scope' => 'pet',
                    'theme_class' => (string) $config['theme_class'],
                    'description' => (string) $config['description'],
                    'reward_title' => (string) $config['reward_title'],
                    'reward_note' => (string) $config['reward_note'],
                    'source_type' => 'dog_journey_sync',
                    'source_reference' => 'pet:' . $petId,
                    'is_active' => 1,
                    'is_featured' => 1,
                ));
            } else {
                $customKey = 'journey_custom_' . normalizeBadgeKey($badgeName) . '_' . $petId;

                awardOrUpdateMemberBadge($pdo, array(
                    'user_id' => (int) $userId,
                    'pet_id' => $petId,
                    'badge_key' => $customKey,
                    'badge_name' => (string) $badgeName,
                    'badge_mark' => badgeMarkFromName($badgeName),
                    'badge_group' => 'journey',
                    'badge_family' => 'journey_custom',
                    'badge_scope' => 'pet',
                    'theme_class' => 'badge-tier-journey-custom',
                    'description' => $petName . ' earned a custom Dog Journey distinction.',
                    'reward_title' => 'Journey Reward Slot',
                    'reward_note' => 'Ready for future custom badge rewards, surprises, or premium unlocks.',
                    'source_type' => 'dog_journey_sync',
                    'source_reference' => 'pet:' . $petId,
                    'is_active' => 1,
                    'is_featured' => 1,
                ));
            }
        }
    }
}

function buildJourneyBadgeVault(PDO $pdo, $userId)
{
    $catalog = dogJourneyBadgeCatalogDetailed();
    $milestones = array();

    foreach ($catalog as $badgeKey => $config) {
        $milestones[$badgeKey] = array(
            'badge_key' => (string) $badgeKey,
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'pet_names' => array(),
            'earned_count' => 0,
            'unlocked' => false,
            'status_label' => 'Locked',
        );
    }

    $custom = array();

    foreach (fetchActiveMemberBadges($pdo, $userId, 'journey') as $badge) {
        $badgeKey = trim((string) valueFromRow($badge, array('badge_key'), ''));
        $badgeName = trim((string) valueFromRow($badge, array('badge_name'), ''));
        $petId = (int) valueFromRow($badge, array('pet_id'), 0);
        $petName = $petId > 0 ? loadPetNameById($pdo, $petId) : '';

        if ($badgeKey !== '' && isset($milestones[$badgeKey])) {
            $milestones[$badgeKey]['unlocked'] = true;
            $milestones[$badgeKey]['earned_count']++;

            if ($petName !== '' && !in_array($petName, $milestones[$badgeKey]['pet_names'], true)) {
                $milestones[$badgeKey]['pet_names'][] = $petName;
            }

            continue;
        }

        $customKey = $badgeKey !== '' ? $badgeKey : normalizeBadgeKey($badgeName);
        if (!isset($custom[$customKey])) {
            $custom[$customKey] = array(
                'badge_key' => $customKey,
                'badge_name' => $badgeName !== '' ? $badgeName : 'Custom Badge',
                'badge_mark' => trim((string) valueFromRow($badge, array('badge_mark'), '')) !== '' ? (string) valueFromRow($badge, array('badge_mark'), '') : badgeMarkFromName($badgeName),
                'theme_class' => trim((string) valueFromRow($badge, array('theme_class'), '')) !== '' ? (string) valueFromRow($badge, array('theme_class'), '') : 'badge-tier-journey-custom',
                'description' => trim((string) valueFromRow($badge, array('description'), '')) !== '' ? (string) valueFromRow($badge, array('description'), '') : 'A custom Dog Journey distinction earned by a member dog.',
                'reward_title' => trim((string) valueFromRow($badge, array('reward_title'), '')) !== '' ? (string) valueFromRow($badge, array('reward_title'), '') : 'Journey Reward Slot',
                'reward_note' => trim((string) valueFromRow($badge, array('reward_note'), '')) !== '' ? (string) valueFromRow($badge, array('reward_note'), '') : 'Ready for future custom badge rewards, surprises, or premium unlocks.',
                'pet_names' => array(),
                'earned_count' => 0,
                'unlocked' => true,
                'status_label' => 'Unlocked',
            );
        }

        $custom[$customKey]['earned_count']++;
        if ($petName !== '' && !in_array($petName, $custom[$customKey]['pet_names'], true)) {
            $custom[$customKey]['pet_names'][] = $petName;
        }
    }

    foreach ($milestones as $badgeKey => $badge) {
        if (!empty($badge['unlocked'])) {
            $milestones[$badgeKey]['status_label'] = 'Unlocked';
        }
    }

    $milestoneItems = array_values($milestones);
    $customItems = array_values($custom);

    usort($milestoneItems, function ($a, $b) {
        return strcasecmp((string) ($a['badge_name'] ?? ''), (string) ($b['badge_name'] ?? ''));
    });

    usort($customItems, function ($a, $b) {
        return strcasecmp((string) ($a['badge_name'] ?? ''), (string) ($b['badge_name'] ?? ''));
    });

    $unlockedCount = 0;
    foreach ($milestoneItems as $item) {
        if (!empty($item['unlocked'])) {
            $unlockedCount++;
        }
    }
    $unlockedCount += count($customItems);

    return array(
        'milestone_collection' => $milestoneItems,
        'custom_collection' => $customItems,
        'unlocked_count' => $unlockedCount,
    );
}

function badgeVaultUnlockedCount(array $items)
{
    $count = 0;
    foreach ($items as $item) {
        if (!empty($item['unlocked'])) {
            $count++;
        }
    }

    return $count;
}

function dogsWithJourneyBadgesCount(array $journeyCards)
{
    $count = 0;
    foreach ($journeyCards as $card) {
        if (trim((string) valueFromRow($card, array('milestone_badge'), '')) !== '') {
            $count++;
        }
    }

    return $count;
}


require_once __DIR__ . '/includes/member-badge-roadmap.php';

ensureDogJourneySchema($pdo);
ensureBadgeVaultSchema($pdo);

$userId = currentUserId();
if ($userId <= 0 && !isAdmin()) {
    redirectTo('login.php');
}

$memberName = loadMemberName($pdo, $userId);
$memberName = $memberName !== '' ? $memberName : 'Member';
$memberCreatedAt = loadMemberCreatedAt($pdo, $userId);

$flash = isset($_SESSION['dashboard_flash']) ? $_SESSION['dashboard_flash'] : '';
unset($_SESSION['dashboard_flash']);

$bookings = fetchMemberBookings($pdo, $userId);
$pets = fetchMemberPetsDetailed($pdo, $userId);
$petCount = count($pets);
$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);
$membershipSummary = getMembershipSummary($pdo, $userId);
$journeyCards = buildDogJourneyCards($pdo, $userId, $pets, $bookings, $memberCreatedAt);

syncFounderMembershipBadges($pdo, $userId, $membershipSummary);
syncJourneyMilestoneBadges($pdo, $userId, $journeyCards);

$badgeProgressSnapshot = buildMemberBadgeProgressSnapshot($journeyCards, $bookings, $membershipSummary, $memberCreatedAt);
syncRoadmapAutoBadges($pdo, $userId, $badgeProgressSnapshot);

$founderBadgeCollection = buildFounderBadgeVault($pdo, $userId, $membershipSummary);
$journeyBadgeVault = buildJourneyBadgeVault($pdo, $userId);
$roadmapBadgeVault = buildRoadmapBadgeVault($pdo, $userId);
$rewardTierSnapshot = buildRewardTierSnapshot($pdo, $userId);
$journeyMilestoneCollection = (array) valueFromRow($journeyBadgeVault, array('milestone_collection'), array());
$customJourneyBadgeCollection = (array) valueFromRow($journeyBadgeVault, array('custom_collection'), array());
$roadmapBadgeSections = (array) valueFromRow($roadmapBadgeVault, array('sections'), array());
$founderBadgeUnlockTotal = badgeVaultUnlockedCount($founderBadgeCollection);
$journeyBadgeUnlockTotal = (int) valueFromRow($journeyBadgeVault, array('unlocked_count'), 0);
$roadmapBadgeUnlockTotal = (int) valueFromRow($roadmapBadgeVault, array('unlocked_count'), 0);
$dogsWithJourneyBadges = dogsWithJourneyBadgesCount($journeyCards);
$totalUnlockedBadgeCount = (int) valueFromRow($rewardTierSnapshot, array('total_unlocked'), 0);

$statusCounts = array(
    'pending' => 0,
    'available' => 0,
    'accepted' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'released' => 0,
);

$paymentCounts = array(
    'paid' => 0,
    'pending' => 0,
    'unpaid' => 0,
    'not_required' => 0,
);

$serviceCounts = array(
    'walk' => 0,
    'boarding' => 0,
    'daycare' => 0,
    'sitting' => 0,
    'drop-in' => 0,
    'service' => 0,
);

$upcoming = array();
$activeWalks = array();
$recentCompleted = array();

foreach ($bookings as $booking) {
    if (isset($statusCounts[$booking['status']])) {
        $statusCounts[$booking['status']]++;
    }

    if (isset($paymentCounts[$booking['payment_status']])) {
        $paymentCounts[$booking['payment_status']]++;
    }

    if (isset($serviceCounts[$booking['service_type']])) {
        $serviceCounts[$booking['service_type']]++;
    } else {
        $serviceCounts['service']++;
    }

    if (isFutureOrToday((string) $booking['service_date']) && !in_array($booking['status'], array('completed', 'cancelled'), true)) {
        $upcoming[] = $booking;
    }

    if ($booking['service_type'] === 'walk' && hasActiveTracking($pdo, $booking)) {
        $activeWalks[] = $booking;
    }

    if ($booking['status'] === 'completed') {
        $recentCompleted[] = $booking;
    }
}

$upcoming = array_slice(sortBookings($upcoming), 0, 6);
$activeWalks = array_slice(sortBookings($activeWalks), 0, 4);
$recentCompleted = array_slice(array_reverse(sortBookings($recentCompleted)), 0, 4);

$totalBookings = count($bookings);
$activeServices = $statusCounts['pending'] + $statusCounts['available'] + $statusCounts['accepted'] + $statusCounts['in_progress'] + $statusCounts['released'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Doggie Dorian’s</title>
    <meta name="description" content="Member dashboard for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1460px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 8px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.82rem;
            line-height: 1.1;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
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
            line-height: 1.6;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .metric {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .metric-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 1.4rem;
            font-weight: 900;
        }

        .chips {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .chip {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 700;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .quick-link {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.07);
            display: grid;
            gap: 8px;
        }

        .quick-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .quick-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: .92rem;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.3);
            color: #f3e5c2;
            font-weight: 700;
        }

        .journey-card {
            margin-bottom: 22px;
        }

        .journey-showcase {
            display: grid;
            grid-template-columns: minmax(0, 1.75fr) minmax(320px, 0.9fr);
            gap: 18px;
            margin-top: 18px;
            align-items: start;
        }

        .journey-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .journey-item {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            display: grid;
            gap: 14px;
        }

        .journey-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .journey-name {
            font-size: 1.15rem;
            font-weight: 900;
            margin-bottom: 5px;
        }

        .journey-sub {
            color: rgba(244,241,234,0.64);
            font-size: .9rem;
            line-height: 1.5;
        }

        .journey-badge {
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(198,178,139,0.16);
            color: #f3e5c7;
            border: 1px solid rgba(198,178,139,0.22);
            font-size: .82rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .journey-highlight {
            color: rgba(244,241,234,0.76);
            line-height: 1.65;
            font-size: .95rem;
        }

        .journey-stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
        }

        .journey-stat {
            padding: 12px;
            border-radius: 16px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .journey-stat-label {
            color: rgba(244,241,234,0.54);
            text-transform: uppercase;
            letter-spacing: .1em;
            font-size: .68rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .journey-stat-value {
            font-size: 1.1rem;
            font-weight: 900;
        }

        .journey-footer {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .journey-chip {
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .82rem;
            font-weight: 800;
        }

        .journey-note {
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.74);
            line-height: 1.65;
        }


        .badge-case {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.06);
            display: grid;
            gap: 16px;
            position: sticky;
            top: 18px;
        }

        .badge-case-top {
            display: grid;
            gap: 8px;
        }

        .badge-case-title {
            font-size: 1.08rem;
            font-weight: 900;
        }

        .badge-case-sub {
            color: rgba(244,241,234,0.7);
            line-height: 1.6;
            font-size: .92rem;
        }

        .badge-case-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .badge-case-metric {
            padding: 12px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .badge-case-metric-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .1em;
            font-size: .66rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .badge-case-metric-value {
            font-size: 1.08rem;
            font-weight: 900;
        }

        .badge-case-section {
            display: grid;
            gap: 12px;
        }

        .badge-case-section-title {
            font-size: .92rem;
            font-weight: 900;
            color: rgba(244,241,234,0.9);
        }

        .badge-case-grid {
            display: grid;
            gap: 12px;
        }

        .badge-case-item {
            padding: 14px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
            display: grid;
            gap: 10px;
        }

        .badge-case-item.locked {
            opacity: .55;
            background: rgba(255,255,255,0.02);
        }

        .badge-case-item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge-case-mark {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            font-weight: 900;
            letter-spacing: .08em;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.05);
            color: #fff;
        }

        .badge-tier-walk .badge-case-mark {
            background: linear-gradient(135deg, rgba(177,140,78,0.28), rgba(226,196,141,0.14));
            color: #f4dfb1;
        }

        .badge-tier-care .badge-case-mark {
            background: linear-gradient(135deg, rgba(110,145,205,0.24), rgba(169,198,255,0.12));
            color: #d8e6ff;
        }

        .badge-tier-elite .badge-case-mark {
            background: linear-gradient(135deg, rgba(152,109,228,0.28), rgba(230,204,255,0.12));
            color: #ecd8ff;
        }

        .badge-tier-journey .badge-case-mark {
            background: linear-gradient(135deg, rgba(198,178,139,0.28), rgba(245,224,186,0.12));
            color: #f7e7c6;
        }

        .badge-tier-journey-custom .badge-case-mark {
            background: linear-gradient(135deg, rgba(118,154,206,0.26), rgba(208,226,255,0.12));
            color: #dce9ff;
        }

        .badge-tier-service-walk .badge-case-mark {
            background: linear-gradient(135deg, rgba(214,179,93,0.26), rgba(255,232,178,0.08));
            color: #ffe6a8;
        }

        .badge-tier-service-daycare .badge-case-mark {
            background: linear-gradient(135deg, rgba(138,110,255,0.28), rgba(198,183,255,0.08));
            color: #ece6ff;
        }

        .badge-tier-service-boarding .badge-case-mark {
            background: linear-gradient(135deg, rgba(88,148,255,0.28), rgba(189,219,255,0.08));
            color: #e4f1ff;
        }

        .badge-tier-service-dropin .badge-case-mark {
            background: linear-gradient(135deg, rgba(76,190,171,0.28), rgba(193,255,242,0.08));
            color: #dbfff8;
        }

        .badge-tier-service-sitting .badge-case-mark {
            background: linear-gradient(135deg, rgba(219,133,94,0.28), rgba(255,217,191,0.08));
            color: #fff0e1;
        }

        .badge-tier-service-multi .badge-case-mark {
            background: linear-gradient(135deg, rgba(244,196,48,0.22), rgba(124,92,255,0.18));
            color: #fff1c6;
        }

        .badge-tier-loyalty .badge-case-mark {
            background: linear-gradient(135deg, rgba(132,221,118,0.24), rgba(228,255,214,0.08));
            color: #ecffe3;
        }

        .badge-case-tier {
            display: grid;
            gap: 12px;
            padding: 16px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
        }

        .badge-case-tier-top,
        .badge-case-tier-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge-case-tier-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .1em;
            font-size: .66rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .badge-case-tier-name {
            font-size: 1.1rem;
            font-weight: 900;
            color: #f7ead2;
        }

        .badge-case-tier-count {
            font-size: .86rem;
            font-weight: 800;
            color: rgba(244,241,234,0.72);
        }

        .badge-case-tier-copy,
        .badge-case-tier-reward,
        .badge-case-tier-meta {
            color: rgba(244,241,234,0.72);
            font-size: .86rem;
            line-height: 1.6;
        }

        .badge-case-tier-track {
            position: relative;
            height: 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            overflow: hidden;
        }

        .badge-case-tier-fill {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(214,179,93,0.92), rgba(255,232,178,0.92));
        }

        .reward-tier-bronze .badge-case-tier-fill {
            background: linear-gradient(135deg, rgba(173,119,74,0.95), rgba(238,188,136,0.95));
        }

        .reward-tier-silver .badge-case-tier-fill {
            background: linear-gradient(135deg, rgba(156,168,184,0.95), rgba(231,237,245,0.95));
        }

        .reward-tier-gold .badge-case-tier-fill {
            background: linear-gradient(135deg, rgba(214,179,93,0.95), rgba(255,232,178,0.95));
        }

        .reward-tier-platinum .badge-case-tier-fill {
            background: linear-gradient(135deg, rgba(140,145,255,0.95), rgba(228,230,255,0.95));
        }

        .reward-tier-blacktag .badge-case-tier-fill {
            background: linear-gradient(135deg, rgba(36,36,36,0.98), rgba(214,179,93,0.95));
        }

        .badge-case-state {
            padding: 7px 10px;
            border-radius: 999px;
            font-size: .73rem;
            font-weight: 900;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(244,241,234,0.82);
        }

        .badge-case-name {
            font-size: .98rem;
            font-weight: 900;
        }

        .badge-case-membership {
            color: rgba(244,241,234,0.72);
            font-size: .84rem;
            font-weight: 800;
        }

        .badge-case-desc,
        .badge-case-meta {
            color: rgba(244,241,234,0.68);
            line-height: 1.58;
            font-size: .88rem;
        }

        .badge-case-reward {
            color: rgba(243,223,177,0.84);
            line-height: 1.55;
            font-size: .79rem;
            font-weight: 700;
            padding-top: 2px;
        }

        .badge-case-list {
            display: grid;
            gap: 10px;
        }

        .badge-case-list-item {
            padding: 13px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            display: grid;
            gap: 8px;
        }

        .badge-case-list-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge-case-list-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .badge-case-list-name {
            font-size: .92rem;
            font-weight: 900;
        }

        .badge-case-count {
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(198,178,139,0.16);
            border: 1px solid rgba(198,178,139,0.24);
            color: #f3e5c7;
            font-size: .72rem;
            font-weight: 900;
        }

        .badge-case-empty {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
            line-height: 1.6;
            font-size: .9rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel-title {
            font-size: 1.12rem;
            font-weight: 900;
            margin-bottom: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .status-section-separator {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .status-subtitle {
            font-size: .9rem;
            font-weight: 900;
            margin-bottom: 12px;
            color: rgba(244,241,234,0.86);
        }

        .stat-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-name {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.56);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 900;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .row {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            display: grid;
            gap: 8px;
        }

        .row-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .row-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .row-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .row-meta {
            color: rgba(244,241,234,0.68);
            font-size: .92rem;
            line-height: 1.55;
        }

        .row-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mini-link {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .85rem;
            font-weight: 800;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 900;
            letter-spacing: .02em;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }
        .badge-released { background: rgba(172,145,255,0.18); color: #e1d6ff; }

        .badge-paid {
            background: rgba(125,206,141,0.18);
            color: #d7f1dd;
            border: 1px solid rgba(125,206,141,0.22);
        }

        .badge-unpaid {
            background: rgba(214,123,123,0.18);
            color: #ffd5d5;
            border: 1px solid rgba(214,123,123,0.22);
        }

        .badge-pay-pending {
            background: rgba(215,183,120,0.18);
            color: #f3dfb1;
            border: 1px solid rgba(215,183,120,0.22);
        }

        .badge-pay-none {
            background: rgba(125,150,255,0.14);
            color: #d5deff;
            border: 1px solid rgba(125,150,255,0.18);
        }

        .empty {
            padding: 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        .footer {
            margin-top: 26px;
            padding-top: 22px;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .footer-copy {
            color: rgba(244,241,234,0.56);
            font-size: .9rem;
        }

        .footer-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .footer-link {
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.07);
            font-size: .85rem;
            font-weight: 800;
            color: rgba(244,241,234,0.76);
        }

        @media (max-width: 1180px) {
            .hero,
            .dashboard-grid,
            .journey-showcase,
            .journey-grid {
                grid-template-columns: 1fr;
            }

            .badge-case {
                position: static;
            }

            .journey-stats,
            .badge-case-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .metrics,
            .stats-grid,
            .quick-grid,
            .journey-stats,
            .badge-case-metrics {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.6rem;
            }

            .page {
                padding: 20px 12px 60px;
            }

            .footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="index.php">Home</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="live-tracking.php">Live Tracking</a>
                <a class="top-link" href="memberships.php">Memberships</a>
                <a class="top-link" href="member-care-library.php">Care Library</a>
                <a class="top-link" href="member-care-article.php">Featured Guide</a>
                <a class="top-link" href="notifications.php">Notifications</a>
                <a class="top-link" href="profile.php">Profile</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo h($flash); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card">
                <div class="eyebrow">Member Dashboard</div>
                <h1>Welcome back, <?php echo h($memberName); ?>.</h1>
                <div class="sub">
                    Review your membership, premium care activity, live service visibility, and your dog’s journey in one polished member dashboard.
                </div>

                <div class="metrics">
                    <div class="metric">
                        <div class="metric-label">Walk Credits</div>
                        <div class="metric-value"><?php echo (int) $membershipSummary['walk']; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Daycare Credits</div>
                        <div class="metric-value"><?php echo (int) $membershipSummary['daycare']; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Drop-In Credits</div>
                        <div class="metric-value"><?php echo (int) $membershipSummary['drop-in']; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Boarding Nights</div>
                        <div class="metric-value"><?php echo (int) $membershipSummary['boarding_night']; ?></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Service Credit</div>
                        <div class="metric-value"><?php echo h(formatMoney($membershipSummary['service_credit'])); ?></div>
                    </div>
                </div>

                <div class="chips">
                    <div class="chip">Plan: <?php echo h($membershipSummary['membership_name']); ?></div>
                    <div class="chip">Renewals: <?php echo (int) $membershipSummary['renewal_count']; ?></div>
                    <div class="chip">Pets: <?php echo (int) $petCount; ?></div>
                    <div class="chip">Total Bookings: <?php echo (int) $totalBookings; ?></div>
                    <div class="chip">Active Services: <?php echo (int) $activeServices; ?></div>
                    <div class="chip">Unread Alerts: <?php echo (int) $unreadNotifications; ?></div>
                    <div class="chip">Badges Unlocked: <?php echo (int) $totalUnlockedBadgeCount; ?></div>
                    <div class="chip">Paid: <?php echo (int) $paymentCounts['paid']; ?></div>
                    <div class="chip">Pending Payment: <?php echo (int) $paymentCounts['pending']; ?></div>
                    <div class="chip">Unpaid: <?php echo (int) $paymentCounts['unpaid']; ?></div>
                    <div class="chip">Walks: <?php echo (int) $serviceCounts['walk']; ?></div>
                    <div class="chip">Boarding: <?php echo (int) $serviceCounts['boarding']; ?></div>
                    <div class="chip">Daycare: <?php echo (int) $serviceCounts['daycare']; ?></div>
                    <div class="chip">Sitting: <?php echo (int) $serviceCounts['sitting']; ?></div>
                    <div class="chip">Drop-In: <?php echo (int) $serviceCounts['drop-in']; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Quick Actions</div>
                <div class="quick-grid">
                    <a class="quick-link" href="book-service.php">
                        <div class="quick-title">Book a Service</div>
                        <div class="quick-text">Schedule walks, boarding, daycare, sitting, and more from one coordinated booking page.</div>
                    </a>

                    <a class="quick-link" href="my-bookings.php">
                        <div class="quick-title">View My Bookings</div>
                        <div class="quick-text">Review upcoming, active, and completed services with cleaner visibility.</div>
                    </a>

                    <a class="quick-link" href="live-tracking.php">
                        <div class="quick-title">Open Live Tracking</div>
                        <div class="quick-text">Go straight to live tracking and active service visibility for eligible walk bookings.</div>
                    </a>

                    <a class="quick-link" href="manage-pets.php">
                        <div class="quick-title">Manage Pets</div>
                        <div class="quick-text">Keep pet details, care notes, and profiles updated for future services.</div>
                    </a>

                    <a class="quick-link" href="add-pet.php">
                        <div class="quick-title">Add a Pet</div>
                        <div class="quick-text">Add another pet profile so new bookings stay organized and faster to complete.</div>
                    </a>

                    <a class="quick-link" href="memberships.php">
                        <div class="quick-title">Memberships</div>
                        <div class="quick-text">Explore membership options and premium service paths built for repeat clients.</div>
                    </a>

                    <a class="quick-link" href="customize-plan.php">
                        <div class="quick-title">Customize Plan</div>
                        <div class="quick-text">Build a more tailored service setup for your dog’s needs and your preferred schedule.</div>
                    </a>

                    <a class="quick-link" href="ambassadors.php">
                        <div class="quick-title">Ambassador Dashboard</div>
                        <div class="quick-text">View your referral code, track rewards, and grow with the ambassador program.</div>
                    </a>

                    <a class="quick-link" href="notifications.php">
                        <div class="quick-title">Open Notifications</div>
                        <div class="quick-text">Review service updates, live walk alerts, and booking changes in one place.</div>
                    </a>

                    <a class="quick-link" href="profile.php">
                        <div class="quick-title">Profile</div>
                        <div class="quick-text">Update your account details and keep your client profile current.</div>
                    </a>

                    <a class="quick-link" href="contact.php">
                        <div class="quick-title">Contact Support</div>
                        <div class="quick-text">Reach Doggie Dorian’s if you need help choosing services or updating care details.</div>
                    </a>

                    <a class="quick-link" href="index.php">
                        <div class="quick-title">Return to Homepage</div>
                        <div class="quick-text">Go back to the main site, services overview, and public-facing booking options.</div>
                    </a>
                </div>
            </div>
        </section>

        <section class="card journey-card">
            <div class="eyebrow">Dog Journey</div>
            <div class="panel-title">Your Dog Journey Dashboard</div>
            <div class="sub">
                This premium timeline combines manually seeded pre-launch history with live website bookings so each dog’s journey feels complete from day one.
            </div>

            <div class="journey-showcase">
                <div>
                    <?php if (empty($journeyCards)): ?>
                        <div class="empty">
                            No pet profiles are connected yet. Add a pet to begin building your dog’s journey.
                        </div>
                    <?php else: ?>
                        <div class="journey-grid">
                            <?php foreach ($journeyCards as $card): ?>
                                <div class="journey-item">
                                    <div class="journey-head">
                                        <div>
                                            <div class="journey-name"><?php echo h($card['pet_name']); ?></div>
                                            <div class="journey-sub">
                                                <?php if ($card['breed'] !== ''): ?>
                                                    <?php echo h($card['breed']); ?>
                                                    <?php if ($card['age'] !== ''): ?> · <?php echo h($card['age']); ?><?php endif; ?>
                                                <?php elseif ($card['age'] !== ''): ?>
                                                    <?php echo h($card['age']); ?>
                                                <?php else: ?>
                                                    Dog Journey profile
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="journey-badge"><?php echo h($card['milestone_badge']); ?></div>
                                    </div>

                                    <div class="journey-highlight"><?php echo h($card['journey_highlight']); ?></div>

                                    <div class="journey-stats">
                                        <div class="journey-stat">
                                            <div class="journey-stat-label">Walks</div>
                                            <div class="journey-stat-value"><?php echo (int) $card['counts']['walk']; ?></div>
                                        </div>
                                        <div class="journey-stat">
                                            <div class="journey-stat-label">Daycare</div>
                                            <div class="journey-stat-value"><?php echo (int) $card['counts']['daycare']; ?></div>
                                        </div>
                                        <div class="journey-stat">
                                            <div class="journey-stat-label">Boarding</div>
                                            <div class="journey-stat-value"><?php echo (int) $card['counts']['boarding_night']; ?></div>
                                        </div>
                                        <div class="journey-stat">
                                            <div class="journey-stat-label">Drop-Ins</div>
                                            <div class="journey-stat-value"><?php echo (int) $card['counts']['drop_in']; ?></div>
                                        </div>
                                        <div class="journey-stat">
                                            <div class="journey-stat-label">Sitting</div>
                                            <div class="journey-stat-value"><?php echo (int) $card['counts']['sitting']; ?></div>
                                        </div>
                                    </div>

                                    <div class="journey-footer">
                                        <div class="journey-chip">Favorite: <?php echo h($card['favorite_service'] !== '' ? formatServiceLabel($card['favorite_service']) : 'Still unfolding'); ?></div>
                                        <div class="journey-chip">Last Service: <?php echo h($card['last_service_date'] !== '' ? formatDateDisplay($card['last_service_date']) : 'Not yet recorded'); ?></div>
                                        <div class="journey-chip">Total Services: <?php echo (int) $card['total_services']; ?></div>
                                        <div class="journey-chip">Member Since: <?php echo h($card['member_since'] !== '' ? formatDateDisplay($card['member_since']) : 'Welcome'); ?></div>
                                    </div>

                                    <?php if (trim((string) $card['journey_note']) !== ''): ?>
                                        <div class="journey-note"><?php echo h($card['journey_note']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($card['journey_entries'])): ?>
                                        <div class="journey-moments">
                                            <?php foreach ($card['journey_entries'] as $entry): ?>
                                                <div class="journey-moment">
                                                    <div class="journey-moment-top">
                                                        <div class="journey-moment-label"><?php echo h(dogJourneyMomentLabel($entry)); ?></div>
                                                        <div class="journey-moment-date"><?php echo h(formatDateDisplay((string) valueFromRow($entry, array('entry_date', 'created_at'), ''))); ?></div>
                                                    </div>
                                                    <?php if (trim((string) valueFromRow($entry, array('entry_title'), '')) !== ''): ?>
                                                        <div class="journey-moment-title"><?php echo h((string) valueFromRow($entry, array('entry_title'), '')); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string) valueFromRow($entry, array('entry_body'), '')) !== ''): ?>
                                                        <div class="journey-moment-body"><?php echo h((string) valueFromRow($entry, array('entry_body'), '')); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="badge-case">
                    <div class="badge-case-top">
                        <div class="eyebrow">Badge Collection</div>
                        <div class="badge-case-title">Your Member Badge Vault</div>
                        <div class="badge-case-sub">
                            Founder distinctions, journey milestones, service collectibles, and a visible reward tier now live in one shared vault with locked spaces ready for future unlocks.
                        </div>
                    </div>

                    <div class="badge-case-tier <?php echo h((string) ($rewardTierSnapshot['theme_class'] ?? '')); ?>">
                        <div class="badge-case-tier-top">
                            <div>
                                <div class="badge-case-tier-label">Visible Reward Tier</div>
                                <div class="badge-case-tier-name"><?php echo h((string) ($rewardTierSnapshot['current_tier_name'] ?? 'Bronze Collar')); ?></div>
                            </div>
                            <div class="badge-case-tier-count"><?php echo (int) ($rewardTierSnapshot['total_unlocked'] ?? 0); ?> badges</div>
                        </div>
                        <div class="badge-case-tier-copy"><?php echo h((string) ($rewardTierSnapshot['reward_note'] ?? 'Your reward tier grows as your badge vault expands.')); ?></div>
                        <div class="badge-case-tier-track">
                            <span class="badge-case-tier-fill" style="width: <?php echo h((string) ($rewardTierSnapshot['progress_percent'] ?? 0)); ?>%;"></span>
                        </div>
                        <div class="badge-case-tier-meta">
                            <span><?php echo h((string) ($rewardTierSnapshot['range_label'] ?? '0+ badges')); ?></span>
                            <span><?php echo h((string) ($rewardTierSnapshot['next_tier_message'] ?? '')); ?></span>
                        </div>
                    </div>

                    <div class="badge-case-metrics">
                        <div class="badge-case-metric">
                            <div class="badge-case-metric-label">Unlocked</div>
                            <div class="badge-case-metric-value"><?php echo (int) $totalUnlockedBadgeCount; ?></div>
                        </div>
                        <div class="badge-case-metric">
                            <div class="badge-case-metric-label">Founder Badges</div>
                            <div class="badge-case-metric-value"><?php echo (int) $founderBadgeUnlockTotal; ?>/3</div>
                        </div>
                        <div class="badge-case-metric">
                            <div class="badge-case-metric-label">Journey Badges</div>
                            <div class="badge-case-metric-value"><?php echo (int) $journeyBadgeUnlockTotal; ?></div>
                        </div>
                        <div class="badge-case-metric">
                            <div class="badge-case-metric-label">Roadmap Badges</div>
                            <div class="badge-case-metric-value"><?php echo (int) $roadmapBadgeUnlockTotal; ?></div>
                        </div>
                    </div>

                    <div class="badge-case-section">
                        <div class="badge-case-section-title">Founder Membership Badges</div>
                        <div class="badge-case-grid">
                            <?php foreach ($founderBadgeCollection as $badge): ?>
                                <div class="badge-case-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h($badge['theme_class']); ?>">
                                    <div class="badge-case-item-top">
                                        <div class="badge-case-mark"><?php echo h($badge['badge_mark']); ?></div>
                                        <div class="badge-case-state"><?php echo h($badge['status_label']); ?></div>
                                    </div>
                                    <div class="badge-case-name"><?php echo h($badge['badge_name']); ?></div>
                                    <div class="badge-case-membership"><?php echo h($badge['membership_name']); ?></div>
                                    <div class="badge-case-desc"><?php echo h($badge['description']); ?></div>
                                    <div class="badge-case-reward"><?php echo h($badge['reward_title']); ?>: <?php echo h($badge['reward_note']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="badge-case-section">
                        <div class="badge-case-section-title">Dog Journey Milestone Badges</div>
                        <div class="badge-case-grid">
                            <?php foreach ($journeyMilestoneCollection as $badge): ?>
                                <div class="badge-case-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h($badge['theme_class']); ?>">
                                    <div class="badge-case-item-top">
                                        <div class="badge-case-mark"><?php echo h($badge['badge_mark']); ?></div>
                                        <div class="badge-case-state"><?php echo h($badge['status_label']); ?></div>
                                    </div>
                                    <div class="badge-case-name"><?php echo h($badge['badge_name']); ?></div>
                                    <div class="badge-case-desc"><?php echo h($badge['description']); ?></div>
                                    <?php if (!empty($badge['pet_names'])): ?>
                                        <div class="badge-case-meta">Earned by: <?php echo h(implode(', ', $badge['pet_names'])); ?></div>
                                    <?php else: ?>
                                        <div class="badge-case-meta">This slot stays locked until one of your dogs reaches this milestone.</div>
                                    <?php endif; ?>
                                    <div class="badge-case-reward"><?php echo h($badge['reward_title']); ?>: <?php echo h($badge['reward_note']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php foreach ($roadmapBadgeSections as $section): ?>
                        <div class="badge-case-section">
                            <div class="badge-case-section-title"><?php echo h((string) ($section['title'] ?? 'Badge Collection')); ?> · <?php echo (int) ($section['unlocked_count'] ?? 0); ?>/<?php echo (int) ($section['total_count'] ?? 0); ?></div>
                            <div class="badge-case-grid">
                                <?php foreach ((array) ($section['items'] ?? array()) as $badge): ?>
                                    <div class="badge-case-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h((string) ($badge['theme_class'] ?? '')); ?>">
                                        <div class="badge-case-item-top">
                                            <div class="badge-case-mark"><?php echo h((string) ($badge['badge_mark'] ?? 'BDG')); ?></div>
                                            <div class="badge-case-state"><?php echo h((string) ($badge['status_label'] ?? 'Locked')); ?></div>
                                        </div>
                                        <div class="badge-case-name"><?php echo h((string) ($badge['badge_name'] ?? 'Badge')); ?></div>
                                        <div class="badge-case-desc"><?php echo h((string) ($badge['description'] ?? '')); ?></div>
                                        <div class="badge-case-reward"><?php echo h((string) ($badge['reward_title'] ?? 'Reward Slot')); ?>: <?php echo h((string) ($badge['reward_note'] ?? 'Ready for future member rewards.')); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="badge-case-section">
                        <div class="badge-case-section-title">Custom Journey Badges</div>
                        <?php if (empty($customJourneyBadgeCollection)): ?>
                            <div class="badge-case-empty">
                                Custom Dog Journey badges will appear here whenever Doggie Dorian’s manually awards a unique member collectible.
                            </div>
                        <?php else: ?>
                            <div class="badge-case-list">
                                <?php foreach ($customJourneyBadgeCollection as $badge): ?>
                                    <div class="badge-case-list-item">
                                        <div class="badge-case-list-top">
                                            <div class="badge-case-list-left">
                                                <div class="badge-case-mark"><?php echo h($badge['badge_mark']); ?></div>
                                                <div class="badge-case-list-name"><?php echo h($badge['badge_name']); ?></div>
                                            </div>
                                            <div class="badge-case-count"><?php echo count($badge['pet_names']); ?> dog<?php echo count($badge['pet_names']) === 1 ? '' : 's'; ?></div>
                                        </div>
                                        <div class="badge-case-meta"><?php echo h($badge['description']); ?></div>
                                        <?php if (!empty($badge['pet_names'])): ?>
                                            <div class="badge-case-meta">Seen on: <?php echo h(implode(', ', $badge['pet_names'])); ?></div>
                                        <?php endif; ?>
                                        <div class="badge-case-reward"><?php echo h($badge['reward_title']); ?>: <?php echo h($badge['reward_note']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="card">
                <div class="eyebrow">Live Services</div>
                <div class="panel-title">Active Walks</div>

                <div class="list">
                    <?php if ($activeWalks === array()): ?>
                        <div class="empty">There are no active walk tracking sessions right now.</div>
                    <?php else: ?>
                        <?php foreach ($activeWalks as $row): ?>
                            <div class="row">
                                <div class="row-top">
                                    <div class="row-title">
                                        #<?php echo (int) $row['id']; ?> · Walk · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Walk'); ?>
                                    </div>
                                    <div class="row-badges">
                                        <span class="badge <?php echo h(statusBadgeClass($row['status'])); ?>">
                                            <?php echo h(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                        </span>
                                        <span class="badge <?php echo h(paymentBadgeClass($row['payment_status'])); ?>">
                                            <?php echo h(paymentBadgeLabel($row['payment_status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="row-meta">
                                    <?php echo h(formatDateDisplay($row['service_date'])); ?> at <?php echo h(formatTimeDisplay($row['service_time'])); ?> ·
                                    Walker: <?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Awaiting assignment'); ?> ·
                                    Payment: <?php echo h(paymentBadgeLabel($row['payment_status'])); ?>
                                </div>
                                <div class="row-links">
                                    <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">Track Walk</a>
                                    <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>
                                    <a class="mini-link" href="live-tracking.php">Tracking Hub</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Upcoming</div>
                <div class="panel-title">Upcoming Services</div>
                <div class="list">
                    <?php if ($upcoming === array()): ?>
                        <div class="empty">You do not have any upcoming services right now.</div>
                    <?php else: ?>
                        <?php foreach ($upcoming as $row): ?>
                            <div class="row">
                                <div class="row-top">
                                    <div class="row-title">
                                        #<?php echo (int) $row['id']; ?> · <?php echo h(formatServiceLabel($row['service_type'])); ?> · <?php echo h($row['pet_name'] !== '' ? $row['pet_name'] : 'Pet not listed'); ?>
                                    </div>
                                    <div class="row-badges">
                                        <span class="badge <?php echo h(statusBadgeClass($row['status'])); ?>">
                                            <?php echo h(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                        </span>
                                        <span class="badge <?php echo h(paymentBadgeClass($row['payment_status'])); ?>">
                                            <?php echo h(paymentBadgeLabel($row['payment_status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="row-meta">
                                    <?php echo h(formatDateDisplay($row['service_date'])); ?> at <?php echo h(formatTimeDisplay($row['service_time'])); ?> ·
                                    Walker: <?php echo h($row['worker_name'] !== '' ? $row['worker_name'] : 'Awaiting assignment'); ?> ·
                                    Price: <?php echo h(formatMoney($row['price'])); ?> ·
                                    Payment: <?php echo h(paymentBadgeLabel($row['payment_status'])); ?>
                                </div>
                                <div class="row-links">
                                    <a class="mini-link" href="booking-details.php?id=<?php echo (int) $row['id']; ?>">Details</a>
                                    <?php if ($row['service_type'] === 'walk' && hasActiveTracking($pdo, $row)): ?>
                                        <a class="mini-link" href="client-map.php?booking_id=<?php echo (int) $row['id']; ?>">Track</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Service Status</div>
                <div class="panel-title">Dashboard Overview</div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-name">Pending</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['pending']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Available</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['available']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Accepted</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['accepted']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">In Progress</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['in_progress']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Completed</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['completed']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-name">Cancelled</div>
                        <div class="stat-value"><?php echo (int) $statusCounts['cancelled']; ?></div>
                    </div>
                </div>

                <div class="status-section-separator">
                    <div class="status-subtitle">Payment Visibility</div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-name">Paid</div>
                            <div class="stat-value"><?php echo (int) $paymentCounts['paid']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-name">Pending Payment</div>
                            <div class="stat-value"><?php echo (int) $paymentCounts['pending']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-name">Unpaid</div>
                            <div class="stat
