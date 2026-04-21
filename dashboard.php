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

ensureDogJourneySchema($pdo);

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

        .journey-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
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
            .journey-grid {
                grid-template-columns: 1fr;
            }

            .journey-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .metrics,
            .stats-grid,
            .quick-grid,
            .journey-stats {
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

            <?php if (empty($journeyCards)): ?>
                <div class="empty" style="margin-top:18px;">
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
