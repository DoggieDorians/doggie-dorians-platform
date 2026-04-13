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

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    return isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
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

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
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

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    $columns = getTableColumns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
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
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        return null;
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
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'void') {
        return 'cancelled';
    }

    return $status !== '' ? $status : 'pending';
}

function normalizePublicStatus($status)
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'confirmed', 'completed', 'cancelled');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'new';
}

function normalizePaymentStatus($status)
{
    $status = strtolower(trim((string) $status));
    $allowed = array('unpaid', 'pending', 'paid', 'partially_paid', 'refunded');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'unpaid';
}

function normalizePaymentMethod($method)
{
    $method = strtolower(trim((string) $method));

    $map = array(
        'stripe' => 'stripe',
        'zelle' => 'zelle',
        'cash' => 'cash',
        'venmo' => 'venmo',
        'bank transfer' => 'bank_transfer',
        'bank_transfer' => 'bank_transfer',
        'check' => 'check',
        'cheque' => 'check',
        'other' => 'other',
    );

    return isset($map[$method]) ? $map[$method] : '';
}

function paymentMethodDisplayName($method)
{
    $method = normalizePaymentMethod($method);

    if ($method === 'bank_transfer') {
        return 'Bank Transfer';
    }

    if ($method === '') {
        return '—';
    }

    return ucfirst($method);
}

function normalizeServiceType($type)
{
    $type = strtolower(trim((string) $type));

    if ($type === '') {
        return 'service';
    }
    if (strpos($type, 'walk') !== false) {
        return 'walk';
    }
    if (strpos($type, 'board') !== false) {
        return 'boarding';
    }
    if (strpos($type, 'daycare') !== false || strpos($type, 'day care') !== false) {
        return 'daycare';
    }
    if (strpos($type, 'sit') !== false) {
        return 'sitting';
    }
    if (strpos($type, 'drop') !== false) {
        return 'drop-in';
    }

    return $type;
}

function serviceDisplayName($type)
{
    $type = normalizeServiceType($type);

    if ($type === 'drop-in') {
        return 'Drop-In';
    }

    return ucfirst($type);
}

function formatDateDisplay($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return '—';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function formatTimeDisplay($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return '—';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function formatDateTimeDisplay($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    try {
        $dateTime = new DateTime($value, new DateTimeZone('UTC'));
        $dateTime->setTimezone(new DateTimeZone('America/New_York'));
        return $dateTime->format('F j, Y \a\t g:i A T');
    } catch (Throwable $e) {
        $ts = strtotime($value);
        return $ts !== false ? date('F j, Y \a\t g:i A', $ts) : $value;
    }
}

function formatDateTimeLocalInput($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    try {
        $dateTime = new DateTime($value, new DateTimeZone('UTC'));
        $dateTime->setTimezone(new DateTimeZone('America/New_York'));
        return $dateTime->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
    }
}

function normalizeDateTimeLocalToUtc($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    try {
        $dateTime = new DateTime($value, new DateTimeZone('America/New_York'));
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        return $dateTime->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

function currentUtcTimestampFromNewYork()
{
    try {
        $dateTime = new DateTime('now', new DateTimeZone('America/New_York'));
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        return $dateTime->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return gmdate('Y-m-d H:i:s');
    }
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

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function bookingBaseTable(PDO $pdo)
{
    foreach (array('bookings', 'walks') as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function buildNameFromParts(array $row)
{
    $first = trim((string) valueFromRow($row, array('first_name', 'firstname', 'client_first_name', 'owner_first_name'), ''));
    $last = trim((string) valueFromRow($row, array('last_name', 'lastname', 'client_last_name', 'owner_last_name'), ''));

    $full = trim($first . ' ' . $last);
    return $full !== '' ? $full : '';
}

function decodeJsonIfPossible($value)
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $firstChar = substr($value, 0, 1);
    if ($firstChar !== '{' && $firstChar !== '[') {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function looksLikeJsonObjectOrArray($value)
{
    if (!is_string($value) && !is_numeric($value)) {
        return false;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $firstChar = substr($value, 0, 1);
    $lastChar = substr($value, -1);

    if (!(($firstChar === '{' && $lastChar === '}') || ($firstChar === '[' && $lastChar === ']'))) {
        return false;
    }

    json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE;
}

function stripJsonLinesFromText($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $lines = preg_split("/\r\n|\n|\r/", $text);
    $cleanLines = array();

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);

        if ($trimmed === '') {
            continue;
        }

        if (looksLikeJsonObjectOrArray($trimmed)) {
            continue;
        }

        $cleanLines[] = rtrim((string) $line);
    }

    return trim(implode("\n", $cleanLines));
}

function cleanDisplayNotesText($text)
{
    $text = stripJsonLinesFromText($text);
    if ($text === '') {
        return '';
    }

    $lines = preg_split("/\r\n|\n|\r/", $text);
    $cleanLines = array();

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        $lower = strtolower($trimmed);

        if ($lower === 'booking details:' || $lower === 'booking details') {
            continue;
        }

        $cleanLines[] = rtrim((string) $line);
    }

    return trim(implode("\n", $cleanLines));
}

function extractNestedScalar(array $data, array $paths)
{
    foreach ($paths as $path) {
        $parts = explode('.', $path);
        $current = $data;
        $found = true;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                $found = false;
                break;
            }
        }

        if ($found && is_scalar($current)) {
            $value = trim((string) $current);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function collectJsonSourcesFromRow(array $row)
{
    $sources = array();

    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $decoded = decodeJsonIfPossible($value);
        if (is_array($decoded)) {
            $sources[] = $decoded;
        }
    }

    return $sources;
}

function extractDisplayNotesFromSources(array $jsonSources, $rawNotes)
{
    $rawNotes = trim((string) $rawNotes);

    if ($rawNotes !== '') {
        $cleanRawNotes = cleanDisplayNotesText($rawNotes);
        if ($cleanRawNotes !== '') {
            return $cleanRawNotes;
        }
    }

    $parts = array();

    foreach ($jsonSources as $json) {
        foreach (array(
            'notes',
            'note',
            'special_instructions',
            'instructions',
            'care_notes',
            'client_notes',
            'additional_notes',
            'details',
            'message',
            'booking_details',
            'care_instructions'
        ) as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                $text = cleanDisplayNotesText((string) $json[$key]);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
    }

    $parts = array_values(array_unique($parts));

    return empty($parts) ? '' : implode("\n", $parts);
}

function lookupRelatedName(PDO $pdo, $table, $idValue, array $idCandidates, array $nameCandidates)
{
    if ($idValue === null || $idValue === '' || !hasTable($pdo, $table)) {
        return '';
    }

    $idColumn = firstExistingColumn($pdo, $table, $idCandidates);
    $nameColumn = firstExistingColumn($pdo, $table, $nameCandidates);

    if ($idColumn === null || $nameColumn === null) {
        return '';
    }

    $safeTable = '"' . str_replace('"', '""', $table) . '"';
    $safeIdColumn = '"' . str_replace('"', '""', $idColumn) . '"';
    $safeNameColumn = '"' . str_replace('"', '""', $nameColumn) . '"';

    $row = safeFetchOne(
        $pdo,
        'SELECT ' . $safeNameColumn . ' AS resolved_name FROM ' . $safeTable . ' WHERE ' . $safeIdColumn . ' = :id LIMIT 1',
        array(':id' => $idValue)
    );

    if ($row && isset($row['resolved_name']) && $row['resolved_name'] !== null) {
        $value = trim((string) $row['resolved_name']);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function lookupRelatedFullName(PDO $pdo, $table, $idValue, array $idCandidates, array $fullNameCandidates, array $firstNameCandidates, array $lastNameCandidates)
{
    if ($idValue === null || $idValue === '' || !hasTable($pdo, $table)) {
        return '';
    }

    $idColumn = firstExistingColumn($pdo, $table, $idCandidates);
    if ($idColumn === null) {
        return '';
    }

    $fullNameColumn = firstExistingColumn($pdo, $table, $fullNameCandidates);
    $firstNameColumn = firstExistingColumn($pdo, $table, $firstNameCandidates);
    $lastNameColumn = firstExistingColumn($pdo, $table, $lastNameCandidates);

    if ($fullNameColumn === null && $firstNameColumn === null && $lastNameColumn === null) {
        return '';
    }

    $selectParts = array();

    if ($fullNameColumn !== null) {
        $selectParts[] = '"' . str_replace('"', '""', $fullNameColumn) . '" AS full_name';
    } else {
        $selectParts[] = "'' AS full_name";
    }

    if ($firstNameColumn !== null) {
        $selectParts[] = '"' . str_replace('"', '""', $firstNameColumn) . '" AS first_name';
    } else {
        $selectParts[] = "'' AS first_name";
    }

    if ($lastNameColumn !== null) {
        $selectParts[] = '"' . str_replace('"', '""', $lastNameColumn) . '" AS last_name';
    } else {
        $selectParts[] = "'' AS last_name";
    }

    $safeTable = '"' . str_replace('"', '""', $table) . '"';
    $safeIdColumn = '"' . str_replace('"', '""', $idColumn) . '"';

    $row = safeFetchOne(
        $pdo,
        'SELECT ' . implode(', ', $selectParts) . ' FROM ' . $safeTable . ' WHERE ' . $safeIdColumn . ' = :id LIMIT 1',
        array(':id' => $idValue)
    );

    if (!$row) {
        return '';
    }

    $fullName = trim((string) ($row['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $firstName = trim((string) ($row['first_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($firstName . ' ' . $lastName);

    return $combined !== '' ? $combined : '';
}

function resolveMemberClientName(PDO $pdo, array $row, array $jsonSources)
{
    $clientName = (string) valueFromRow(
        $row,
        array(
            'client_name',
            'owner_name',
            'member_name',
            'customer_name',
            'customer',
            'full_name',
            'name',
            'member_full_name'
        ),
        ''
    );

    if ($clientName === '') {
        $clientName = buildNameFromParts($row);
    }

    if ($clientName === '') {
        foreach ($jsonSources as $json) {
            $clientName = extractNestedScalar($json, array(
                'client_name',
                'owner_name',
                'member_name',
                'customer_name',
                'full_name',
                'name',
                'client.name',
                'customer.name',
                'owner.name',
                'member.name',
                'user.name',
                'member_full_name'
            ));

            if ($clientName !== '') {
                break;
            }
        }
    }

    if ($clientName === '') {
        $memberId = valueFromRow($row, array('member_id', 'memberID'), '');
        if ($memberId !== '') {
            $clientName = lookupRelatedFullName(
                $pdo,
                'members',
                $memberId,
                array('id', 'member_id'),
                array('full_name', 'name', 'member_name', 'client_name'),
                array('first_name', 'firstname'),
                array('last_name', 'lastname')
            );
        }
    }

    if ($clientName === '') {
        $userId = valueFromRow($row, array('user_id', 'client_id', 'owner_id'), '');
        if ($userId !== '') {
            $clientName = lookupRelatedFullName(
                $pdo,
                'users',
                $userId,
                array('id', 'user_id'),
                array('full_name', 'name', 'display_name', 'client_name'),
                array('first_name', 'firstname'),
                array('last_name', 'lastname')
            );
        }
    }

    if ($clientName === '') {
        foreach ($jsonSources as $json) {
            $memberId = extractNestedScalar($json, array('member_id', 'member.id', 'booking.member_id'));
            if ($memberId !== '') {
                $clientName = lookupRelatedFullName(
                    $pdo,
                    'members',
                    $memberId,
                    array('id', 'member_id'),
                    array('full_name', 'name', 'member_name', 'client_name'),
                    array('first_name', 'firstname'),
                    array('last_name', 'lastname')
                );
                if ($clientName !== '') {
                    break;
                }
            }

            $userId = extractNestedScalar($json, array('user_id', 'user.id', 'client_id', 'owner_id'));
            if ($userId !== '') {
                $clientName = lookupRelatedFullName(
                    $pdo,
                    'users',
                    $userId,
                    array('id', 'user_id'),
                    array('full_name', 'name', 'display_name', 'client_name'),
                    array('first_name', 'firstname'),
                    array('last_name', 'lastname')
                );
                if ($clientName !== '') {
                    break;
                }
            }
        }
    }

    return $clientName !== '' ? $clientName : 'Member Client';
}

function resolveMemberPetName(PDO $pdo, array $row, array $jsonSources)
{
    $petName = (string) valueFromRow(
        $row,
        array(
            'pet_name',
            'dog_name',
            'pet',
            'dog',
            'dogs',
            'pet_names'
        ),
        ''
    );

    if ($petName === '') {
        foreach ($jsonSources as $json) {
            $petName = extractNestedScalar($json, array(
                'pet_name',
                'dog_name',
                'pet',
                'dog',
                'pet.name',
                'dog.name',
                'primary_pet_name'
            ));

            if ($petName !== '') {
                break;
            }
        }
    }

    if ($petName === '') {
        $dogId = valueFromRow($row, array('dog_id', 'pet_id'), '');
        if ($dogId !== '') {
            $petName = lookupRelatedName(
                $pdo,
                'dogs',
                $dogId,
                array('id', 'dog_id'),
                array('dog_name', 'pet_name', 'name')
            );
        }
    }

    if ($petName === '') {
        $petId = valueFromRow($row, array('pet_id', 'dog_id'), '');
        if ($petId !== '') {
            $petName = lookupRelatedName(
                $pdo,
                'pets',
                $petId,
                array('id', 'pet_id'),
                array('pet_name', 'dog_name', 'name')
            );
        }
    }

    if ($petName === '') {
        foreach ($jsonSources as $json) {
            $dogId = extractNestedScalar($json, array('dog_id', 'pet_id', 'dog.id', 'pet.id'));
            if ($dogId !== '') {
                $petName = lookupRelatedName(
                    $pdo,
                    'dogs',
                    $dogId,
                    array('id', 'dog_id'),
                    array('dog_name', 'pet_name', 'name')
                );
                if ($petName !== '') {
                    break;
                }
            }

            $petId = extractNestedScalar($json, array('pet_id', 'dog_id', 'pet.id', 'dog.id'));
            if ($petId !== '') {
                $petName = lookupRelatedName(
                    $pdo,
                    'pets',
                    $petId,
                    array('id', 'pet_id'),
                    array('pet_name', 'dog_name', 'name')
                );
                if ($petName !== '') {
                    break;
                }
            }
        }
    }

    return $petName;
}

function getMissingPaymentColumns(PDO $pdo, $table)
{
    if ($table === null || !hasTable($pdo, $table)) {
        return array('payment_status', 'payment_method', 'payment_paid_at', 'payment_reference', 'payment_notes');
    }

    $required = array('payment_status', 'payment_method', 'payment_paid_at', 'payment_reference', 'payment_notes');
    $existing = getTableColumns($pdo, $table);
    $missing = array();

    foreach ($required as $column) {
        if (!in_array($column, $existing, true)) {
            $missing[] = $column;
        }
    }

    return $missing;
}

function paymentColumnsReady(PDO $pdo, $table)
{
    return count(getMissingPaymentColumns($pdo, $table)) === 0;
}

function updateRowById(PDO $pdo, $table, $idColumn, $idValue, array $updates)
{
    if ($table === null || $idColumn === null || empty($updates)) {
        return false;
    }

    $setParts = array();
    $params = array(':row_id' => $idValue);

    foreach ($updates as $column => $value) {
        $setParts[] = '"' . str_replace('"', '""', $column) . '" = :' . $column;
        $params[':' . $column] = $value;
    }

    $sql = 'UPDATE "' . str_replace('"', '""', $table) . '" SET ' . implode(', ', $setParts) . ' WHERE "' . str_replace('"', '""', $idColumn) . '" = :row_id';
    $stmt = $pdo->prepare($sql);

    return safeExecute($stmt, $params);
}

function fetchMemberBookings(PDO $pdo)
{
    $table = bookingBaseTable($pdo);
    if ($table === null) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM "' . str_replace('"', '""', $table) . '" ORDER BY rowid DESC');
    $normalized = array();

    foreach ($rows as $row) {
        $jsonSources = collectJsonSourcesFromRow($row);

        $clientName = resolveMemberClientName($pdo, $row, $jsonSources);
        $petName = resolveMemberPetName($pdo, $row, $jsonSources);

        $serviceType = (string) valueFromRow(
            $row,
            array(
                'service_type',
                'type',
                'booking_type',
                'category',
                'service'
            ),
            ''
        );

        if ($serviceType === '') {
            foreach ($jsonSources as $json) {
                $serviceType = extractNestedScalar($json, array(
                    'service_type',
                    'type',
                    'booking_type',
                    'category',
                    'service',
                    'booking.service_type'
                ));

                if ($serviceType !== '') {
                    break;
                }
            }
        }

        $serviceDate = (string) valueFromRow(
            $row,
            array(
                'service_date',
                'booking_date',
                'walk_date',
                'date',
                'scheduled_date',
                'start_date'
            ),
            ''
        );

        if ($serviceDate === '') {
            foreach ($jsonSources as $json) {
                $serviceDate = extractNestedScalar($json, array(
                    'service_date',
                    'booking_date',
                    'walk_date',
                    'date',
                    'scheduled_date',
                    'start_date',
                    'booking.date'
                ));

                if ($serviceDate !== '') {
                    break;
                }
            }
        }

        $serviceTime = (string) valueFromRow(
            $row,
            array(
                'service_time',
                'booking_time',
                'walk_time',
                'time',
                'scheduled_time',
                'start_time'
            ),
            ''
        );

        if ($serviceTime === '') {
            foreach ($jsonSources as $json) {
                $serviceTime = extractNestedScalar($json, array(
                    'service_time',
                    'booking_time',
                    'walk_time',
                    'time',
                    'scheduled_time',
                    'start_time',
                    'booking.time'
                ));

                if ($serviceTime !== '') {
                    break;
                }
            }
        }

        $rawNotes = (string) valueFromRow(
            $row,
            array(
                'notes',
                'special_instructions',
                'instructions',
                'care_notes',
                'client_notes'
            ),
            ''
        );

        $cleanNotes = extractDisplayNotesFromSources($jsonSources, $rawNotes);

        $normalized[] = array(
            'source' => 'member',
            'id' => (int) valueFromRow($row, array('id', 'booking_id', 'walk_id'), 0),
            'client_name' => $clientName,
            'service_type' => normalizeServiceType($serviceType !== '' ? $serviceType : 'service'),
            'service_date' => $serviceDate,
            'service_time' => $serviceTime,
            'pet_name' => $petName,
            'price' => valueFromRow($row, array('price', 'total_price', 'amount'), ''),
            'status' => normalizeStatus((string) valueFromRow($row, array('status', 'booking_status', 'service_status', 'walk_status'), 'pending')),
            'payment_status' => normalizePaymentStatus((string) valueFromRow($row, array('payment_status'), 'unpaid')),
            'payment_method' => normalizePaymentMethod((string) valueFromRow($row, array('payment_method'), '')),
            'payment_paid_at' => (string) valueFromRow($row, array('payment_paid_at'), ''),
            'payment_reference' => (string) valueFromRow($row, array('payment_reference'), ''),
            'payment_notes' => (string) valueFromRow($row, array('payment_notes'), ''),
            'notes' => $cleanNotes,
            'created_at' => (string) valueFromRow($row, array('created_at'), ''),
            'raw' => $row,
        );
    }

    return $normalized;
}

function fetchPublicBookings(PDO $pdo)
{
    if (!hasTable($pdo, 'non_member_bookings')) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM non_member_bookings ORDER BY rowid DESC');
    $normalized = array();

    foreach ($rows as $row) {
        $normalized[] = array(
            'source' => 'public',
            'id' => (int) valueFromRow($row, array('id'), 0),
            'client_name' => (string) valueFromRow($row, array('full_name', 'name'), 'Public Client'),
            'email' => (string) valueFromRow($row, array('email'), ''),
            'phone' => (string) valueFromRow($row, array('phone'), ''),
            'service_type' => normalizeServiceType((string) valueFromRow($row, array('service_type', 'service'), 'service')),
            'service_date' => (string) valueFromRow($row, array('service_date', 'date', 'date_start'), ''),
            'service_time' => (string) valueFromRow($row, array('service_time', 'time', 'preferred_walk_time'), ''),
            'pet_name' => (string) valueFromRow($row, array('pet_name', 'dog_name'), ''),
            'pet_breed' => (string) valueFromRow($row, array('pet_breed', 'breed'), ''),
            'pet_size' => (string) valueFromRow($row, array('pet_size', 'size', 'dog_size'), ''),
            'price' => '',
            'status' => normalizePublicStatus((string) valueFromRow($row, array('status'), 'new')),
            'payment_status' => normalizePaymentStatus((string) valueFromRow($row, array('payment_status'), 'unpaid')),
            'payment_method' => normalizePaymentMethod((string) valueFromRow($row, array('payment_method'), '')),
            'payment_paid_at' => (string) valueFromRow($row, array('payment_paid_at'), ''),
            'payment_reference' => (string) valueFromRow($row, array('payment_reference'), ''),
            'payment_notes' => (string) valueFromRow($row, array('payment_notes'), ''),
            'notes' => (string) valueFromRow($row, array('notes'), ''),
            'created_at' => (string) valueFromRow($row, array('created_at'), ''),
            'raw' => $row,
        );
    }

    return $normalized;
}

function statusBadgeClass($status)
{
    if ($status === 'accepted' || $status === 'confirmed') {
        return 'badge-accepted';
    }
    if ($status === 'in_progress' || $status === 'reviewed') {
        return 'badge-progress';
    }
    if ($status === 'completed') {
        return 'badge-complete';
    }
    if ($status === 'cancelled') {
        return 'badge-cancelled';
    }
    if ($status === 'available' || $status === 'new') {
        return 'badge-available';
    }

    return 'badge-pending';
}

function paymentBadgeClass($status)
{
    if ($status === 'paid') {
        return 'badge-complete';
    }
    if ($status === 'pending' || $status === 'partially_paid') {
        return 'badge-progress';
    }
    if ($status === 'refunded') {
        return 'badge-cancelled';
    }

    return 'badge-pending';
}

$memberTable = bookingBaseTable($pdo);
$memberTableIdColumn = $memberTable !== null ? firstExistingColumn($pdo, $memberTable, array('id', 'booking_id', 'walk_id')) : null;
$memberStatusColumn = $memberTable !== null ? firstExistingColumn($pdo, $memberTable, array('status', 'booking_status', 'service_status', 'walk_status')) : null;
$memberPaymentMissingColumns = getMissingPaymentColumns($pdo, $memberTable);
$memberPaymentReady = count($memberPaymentMissingColumns) === 0;

$publicTable = hasTable($pdo, 'non_member_bookings') ? 'non_member_bookings' : null;
$publicTableIdColumn = $publicTable !== null ? firstExistingColumn($pdo, $publicTable, array('id')) : null;
$publicPaymentMissingColumns = getMissingPaymentColumns($pdo, $publicTable);
$publicPaymentReady = count($publicPaymentMissingColumns) === 0;

$flash = isset($_SESSION['admin_bookings_flash']) ? (string) $_SESSION['admin_bookings_flash'] : '';
$flashType = isset($_SESSION['admin_bookings_flash_type']) ? (string) $_SESSION['admin_bookings_flash_type'] : '';
unset($_SESSION['admin_bookings_flash'], $_SESSION['admin_bookings_flash_type']);

$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : 'all';
if (!in_array($view, array('all', 'member', 'public'), true)) {
    $view = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $postedView = isset($_POST['view']) ? strtolower(trim((string) $_POST['view'])) : $view;

    if (!in_array($postedView, array('all', 'member', 'public'), true)) {
        $postedView = 'all';
    }

    if ($action === 'update_member_booking_status') {
        $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        $status = normalizeStatus(isset($_POST['status']) ? $_POST['status'] : 'pending');

        if ($bookingId <= 0 || $memberTable === null || $memberTableIdColumn === null || $memberStatusColumn === null) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Member booking status could not be updated.';
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        $ok = updateRowById(
            $pdo,
            $memberTable,
            $memberTableIdColumn,
            $bookingId,
            array(
                $memberStatusColumn => $status,
            )
        );

        if ($ok) {
            $_SESSION['admin_bookings_flash_type'] = 'success';
            $_SESSION['admin_bookings_flash'] = 'Member booking status updated successfully.';
        } else {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Could not update the member booking status.';
        }

        redirectTo('admin-bookings.php?view=' . urlencode($postedView));
    }

    if ($action === 'update_public_booking_status') {
        $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        $status = normalizePublicStatus(isset($_POST['status']) ? $_POST['status'] : 'new');

        if ($bookingId <= 0 || $publicTable === null || $publicTableIdColumn === null) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Public booking status could not be updated.';
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        $ok = updateRowById(
            $pdo,
            $publicTable,
            $publicTableIdColumn,
            $bookingId,
            array(
                'status' => $status,
            )
        );

        if ($ok) {
            $_SESSION['admin_bookings_flash_type'] = 'success';
            $_SESSION['admin_bookings_flash'] = 'Public booking status updated successfully.';
        } else {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Could not update the public booking status.';
        }

        redirectTo('admin-bookings.php?view=' . urlencode($postedView));
    }

    if ($action === 'update_member_payment') {
        $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;

        if ($bookingId <= 0 || $memberTable === null || $memberTableIdColumn === null) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Member payment details could not be updated.';
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        if (!$memberPaymentReady) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Member payment fields are not ready yet. Missing: ' . implode(', ', $memberPaymentMissingColumns);
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        $paymentStatus = normalizePaymentStatus(isset($_POST['payment_status']) ? $_POST['payment_status'] : 'unpaid');
        $paymentMethod = normalizePaymentMethod(isset($_POST['payment_method']) ? $_POST['payment_method'] : '');
        $paymentPaidAtInput = isset($_POST['payment_paid_at']) ? (string) $_POST['payment_paid_at'] : '';
        $paymentPaidAt = normalizeDateTimeLocalToUtc($paymentPaidAtInput);
        $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
        $paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));

        if ($paymentStatus === 'paid' && $paymentPaidAt === '') {
            $paymentPaidAt = currentUtcTimestampFromNewYork();
        }

        $ok = updateRowById(
            $pdo,
            $memberTable,
            $memberTableIdColumn,
            $bookingId,
            array(
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'payment_paid_at' => $paymentPaidAt,
                'payment_reference' => $paymentReference,
                'payment_notes' => $paymentNotes,
            )
        );

        if ($ok) {
            $_SESSION['admin_bookings_flash_type'] = 'success';
            $_SESSION['admin_bookings_flash'] = 'Member payment details updated successfully.';
        } else {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Could not update the member payment details.';
        }

        redirectTo('admin-bookings.php?view=' . urlencode($postedView));
    }

    if ($action === 'update_public_payment') {
        $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;

        if ($bookingId <= 0 || $publicTable === null || $publicTableIdColumn === null) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Public payment details could not be updated.';
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        if (!$publicPaymentReady) {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Public payment fields are not ready yet. Missing: ' . implode(', ', $publicPaymentMissingColumns);
            redirectTo('admin-bookings.php?view=' . urlencode($postedView));
        }

        $paymentStatus = normalizePaymentStatus(isset($_POST['payment_status']) ? $_POST['payment_status'] : 'unpaid');
        $paymentMethod = normalizePaymentMethod(isset($_POST['payment_method']) ? $_POST['payment_method'] : '');
        $paymentPaidAtInput = isset($_POST['payment_paid_at']) ? (string) $_POST['payment_paid_at'] : '';
        $paymentPaidAt = normalizeDateTimeLocalToUtc($paymentPaidAtInput);
        $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
        $paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));

        if ($paymentStatus === 'paid' && $paymentPaidAt === '') {
            $paymentPaidAt = currentUtcTimestampFromNewYork();
        }

        $ok = updateRowById(
            $pdo,
            $publicTable,
            $publicTableIdColumn,
            $bookingId,
            array(
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'payment_paid_at' => $paymentPaidAt,
                'payment_reference' => $paymentReference,
                'payment_notes' => $paymentNotes,
            )
        );

        if ($ok) {
            $_SESSION['admin_bookings_flash_type'] = 'success';
            $_SESSION['admin_bookings_flash'] = 'Public payment details updated successfully.';
        } else {
            $_SESSION['admin_bookings_flash_type'] = 'error';
            $_SESSION['admin_bookings_flash'] = 'Could not update the public payment details.';
        }

        redirectTo('admin-bookings.php?view=' . urlencode($postedView));
    }
}

$memberBookings = fetchMemberBookings($pdo);
$publicBookings = fetchPublicBookings($pdo);

$allBookings = array_merge($memberBookings, $publicBookings);

usort($allBookings, function ($a, $b) {
    $aDate = trim((isset($a['created_at']) ? $a['created_at'] : '') . ' ' . (isset($a['service_date']) ? $a['service_date'] : ''));
    $bDate = trim((isset($b['created_at']) ? $b['created_at'] : '') . ' ' . (isset($b['service_date']) ? $b['service_date'] : ''));

    $aTs = strtotime($aDate);
    $bTs = strtotime($bDate);

    if ($aTs === false) {
        $aTs = 0;
    }
    if ($bTs === false) {
        $bTs = 0;
    }

    if ($aTs === $bTs) {
        return 0;
    }

    return $aTs > $bTs ? -1 : 1;
});

$displayBookings = $allBookings;
if ($view === 'member') {
    $displayBookings = $memberBookings;
} elseif ($view === 'public') {
    $displayBookings = $publicBookings;
}

$totalCount = count($allBookings);
$memberCount = count($memberBookings);
$publicCount = count($publicBookings);

$newPublicCount = 0;
foreach ($publicBookings as $booking) {
    if ($booking['status'] === 'new') {
        $newPublicCount++;
    }
}

$paidCount = 0;
foreach ($allBookings as $booking) {
    if (($booking['payment_status'] ?? '') === 'paid') {
        $paidCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bookings | Doggie Dorian’s</title>
    <meta name="description" content="Manage member and non-member bookings.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1380px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
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
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
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

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
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

        h2 {
            margin: 0 0 10px;
            font-size: 1.25rem;
        }

        h3 {
            margin: 0 0 10px;
            font-size: 1rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
            color: #d7f1dd;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .notice {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(244,241,234,0.84);
            line-height: 1.55;
        }

        .notice strong {
            color: #f3e5c7;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 900;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .filter-pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 700;
        }

        .filter-pill.active {
            background: rgba(198,178,139,0.16);
            border-color: rgba(198,178,139,0.32);
            color: #f3e5c7;
        }

        .booking-list {
            display: grid;
            gap: 16px;
            margin-top: 22px;
        }

        .booking-card {
            display: grid;
            gap: 18px;
        }

        .booking-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .booking-title {
            font-size: 1.08rem;
            font-weight: 900;
        }

        .pill-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
            background: rgba(255,255,255,0.08);
            color: #f5f3ef;
        }

        .pill.member {
            background: rgba(109,174,255,0.16);
            color: #d0e4ff;
        }

        .pill.public {
            background: rgba(198,178,139,0.16);
            color: #f3e5c7;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 900;
            letter-spacing: .02em;
            text-transform: capitalize;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .meta-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .meta-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .meta-value {
            font-size: .95rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .detail-copy {
            color: rgba(244,241,234,0.74);
            line-height: 1.65;
            white-space: pre-wrap;
        }

        .admin-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 16px;
        }

        .admin-panel {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .panel-copy {
            color: rgba(244,241,234,0.65);
            line-height: 1.55;
            margin-bottom: 12px;
        }

        form {
            display: grid;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 8px;
            color: rgba(244,241,234,0.78);
        }

        select,
        input[type="text"],
        input[type="datetime-local"],
        textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            transition: transform .15s ease;
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 1100px) {
            .hero,
            .stats,
            .meta-grid,
            .admin-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .btn-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-group-walk-applications.php">Group Walk Applications</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Booking Control</div>
                <h1>Admin Bookings</h1>
                <div class="sub">
                    Review both member and public booking activity from one dashboard while keeping booking status and payment status managed separately.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">All Bookings</div>
                        <div class="stat-value"><?php echo (int) $totalCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Member</div>
                        <div class="stat-value"><?php echo (int) $memberCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Public</div>
                        <div class="stat-value"><?php echo (int) $publicCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Paid</div>
                        <div class="stat-value"><?php echo (int) $paidCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Filter View</div>
                <h2>Choose what to review</h2>
                <div class="sub">
                    Switch between all bookings, member bookings, or public non-member bookings.
                </div>

                <div class="filter-row">
                    <a class="filter-pill <?php echo $view === 'all' ? 'active' : ''; ?>" href="admin-bookings.php?view=all">All</a>
                    <a class="filter-pill <?php echo $view === 'member' ? 'active' : ''; ?>" href="admin-bookings.php?view=member">Member</a>
                    <a class="filter-pill <?php echo $view === 'public' ? 'active' : ''; ?>" href="admin-bookings.php?view=public">Public</a>
                </div>

                <div class="notice">
                    <strong>Member payment fields:</strong>
                    <?php echo $memberPaymentReady ? 'Ready' : 'Missing columns: ' . h(implode(', ', $memberPaymentMissingColumns)); ?>
                    <br>
                    <strong>Public payment fields:</strong>
                    <?php echo $publicPaymentReady ? 'Ready' : 'Missing columns: ' . h(implode(', ', $publicPaymentMissingColumns)); ?>
                </div>
            </div>
        </section>

        <section class="booking-list">
            <?php if (empty($displayBookings)): ?>
                <div class="card">
                    <div class="empty">No bookings match this view yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($displayBookings as $booking): ?>
                    <?php
                    $isMemberBooking = $booking['source'] === 'member';
                    $paymentReadyForRow = $isMemberBooking ? $memberPaymentReady : $publicPaymentReady;
                    ?>
                    <div class="card booking-card">
                        <div class="booking-top">
                            <div class="booking-title">
                                #<?php echo (int) $booking['id']; ?> · <?php echo h(serviceDisplayName($booking['service_type'])); ?> · <?php echo h($booking['client_name']); ?>
                            </div>

                            <div class="pill-row">
                                <span class="pill <?php echo $isMemberBooking ? 'member' : 'public'; ?>">
                                    <?php echo $isMemberBooking ? 'Member Booking' : 'Public Booking'; ?>
                                </span>
                                <span class="badge <?php echo h(statusBadgeClass($booking['status'])); ?>">
                                    Booking: <?php echo h(ucwords(str_replace('_', ' ', $booking['status']))); ?>
                                </span>
                                <span class="badge <?php echo h(paymentBadgeClass($booking['payment_status'])); ?>">
                                    Payment: <?php echo h(ucwords(str_replace('_', ' ', $booking['payment_status']))); ?>
                                </span>
                            </div>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Service Date</div>
                                <div class="meta-value"><?php echo h(formatDateDisplay($booking['service_date'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Service Time</div>
                                <div class="meta-value"><?php echo h(formatTimeDisplay($booking['service_time'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Pet</div>
                                <div class="meta-value"><?php echo h($booking['pet_name'] !== '' ? $booking['pet_name'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Created</div>
                                <div class="meta-value"><?php echo h(formatDateTimeDisplay($booking['created_at'])); ?></div>
                            </div>

                            <?php if ($isMemberBooking): ?>
                                <div class="meta-box">
                                    <div class="meta-label">Price</div>
                                    <div class="meta-value"><?php echo h(formatMoney($booking['price'])); ?></div>
                                </div>
                            <?php else: ?>
                                <div class="meta-box">
                                    <div class="meta-label">Email</div>
                                    <div class="meta-value"><?php echo h(isset($booking['email']) ? $booking['email'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Phone</div>
                                    <div class="meta-value"><?php echo h(isset($booking['phone']) ? $booking['phone'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Breed</div>
                                    <div class="meta-value"><?php echo h(isset($booking['pet_breed']) ? $booking['pet_breed'] : '—'); ?></div>
                                </div>

                                <div class="meta-box">
                                    <div class="meta-label">Size</div>
                                    <div class="meta-value"><?php echo h(isset($booking['pet_size']) ? $booking['pet_size'] : '—'); ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="meta-box">
                                <div class="meta-label">Payment Method</div>
                                <div class="meta-value"><?php echo h(paymentMethodDisplayName($booking['payment_method'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Paid At</div>
                                <div class="meta-value"><?php echo h(formatDateTimeDisplay($booking['payment_paid_at'])); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Reference</div>
                                <div class="meta-value"><?php echo h(trim((string) $booking['payment_reference']) !== '' ? $booking['payment_reference'] : '—'); ?></div>
                            </div>
                        </div>

                        <?php if (trim((string) $booking['notes']) !== ''): ?>
                            <div class="detail-copy">
                                <strong style="color:#f3e5c7;">Notes:</strong>
                                <?php echo h($booking['notes']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (trim((string) $booking['payment_notes']) !== ''): ?>
                            <div class="detail-copy">
                                <strong style="color:#f3e5c7;">Payment Notes:</strong>
                                <?php echo h($booking['payment_notes']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-grid">
                            <div class="admin-panel">
                                <div class="eyebrow">Booking Status</div>
                                <h3><?php echo $isMemberBooking ? 'Update member booking status' : 'Update public booking status'; ?></h3>
                                <div class="panel-copy">
                                    Booking progress is managed separately from payment tracking.
                                </div>

                                <?php if ($isMemberBooking): ?>
                                    <form method="post" action="admin-bookings.php">
                                        <input type="hidden" name="action" value="update_member_booking_status">
                                        <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">
                                        <input type="hidden" name="view" value="<?php echo h($view); ?>">

                                        <div>
                                            <label for="member_status_<?php echo (int) $booking['id']; ?>">Member Booking Status</label>
                                            <select id="member_status_<?php echo (int) $booking['id']; ?>" name="status">
                                                <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="accepted" <?php echo $booking['status'] === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                                <option value="in_progress" <?php echo $booking['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </div>

                                        <div class="btn-row">
                                            <button type="submit" class="btn btn-gold">Save Booking Status</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="admin-bookings.php">
                                        <input type="hidden" name="action" value="update_public_booking_status">
                                        <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">
                                        <input type="hidden" name="view" value="<?php echo h($view); ?>">

                                        <div>
                                            <label for="public_status_<?php echo (int) $booking['id']; ?>">Public Booking Status</label>
                                            <select id="public_status_<?php echo (int) $booking['id']; ?>" name="status">
                                                <option value="new" <?php echo $booking['status'] === 'new' ? 'selected' : ''; ?>>New</option>
                                                <option value="reviewed" <?php echo $booking['status'] === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                                <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </div>

                                        <div class="btn-row">
                                            <button type="submit" class="btn btn-gold">Save Booking Status</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="admin-panel">
                                <div class="eyebrow">Payment Details</div>
                                <h3><?php echo $isMemberBooking ? 'Manage member payment' : 'Manage public payment'; ?></h3>
                                <div class="panel-copy">
                                    Mark bookings paid manually for Stripe, Zelle, cash, Venmo, bank transfer, check, or other methods.
                                </div>

                                <?php if (!$paymentReadyForRow): ?>
                                    <div class="notice" style="margin-top:0;">
                                        Payment fields are not ready for this booking type yet. Add the missing database columns first.
                                    </div>
                                <?php else: ?>
                                    <form method="post" action="admin-bookings.php">
                                        <input type="hidden" name="action" value="<?php echo $isMemberBooking ? 'update_member_payment' : 'update_public_payment'; ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">
                                        <input type="hidden" name="view" value="<?php echo h($view); ?>">

                                        <div class="form-grid">
                                            <div>
                                                <label for="payment_status_<?php echo (int) $booking['id']; ?>">Payment Status</label>
                                                <select id="payment_status_<?php echo (int) $booking['id']; ?>" name="payment_status">
                                                    <option value="unpaid" <?php echo $booking['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                                    <option value="pending" <?php echo $booking['payment_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="paid" <?php echo $booking['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                                    <option value="partially_paid" <?php echo $booking['payment_status'] === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                                                    <option value="refunded" <?php echo $booking['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label for="payment_method_<?php echo (int) $booking['id']; ?>">Payment Method</label>
                                                <select id="payment_method_<?php echo (int) $booking['id']; ?>" name="payment_method">
                                                    <option value="" <?php echo $booking['payment_method'] === '' ? 'selected' : ''; ?>>Select Method</option>
                                                    <option value="stripe" <?php echo $booking['payment_method'] === 'stripe' ? 'selected' : ''; ?>>Stripe</option>
                                                    <option value="zelle" <?php echo $booking['payment_method'] === 'zelle' ? 'selected' : ''; ?>>Zelle</option>
                                                    <option value="cash" <?php echo $booking['payment_method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                    <option value="venmo" <?php echo $booking['payment_method'] === 'venmo' ? 'selected' : ''; ?>>Venmo</option>
                                                    <option value="bank_transfer" <?php echo $booking['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                                    <option value="check" <?php echo $booking['payment_method'] === 'check' ? 'selected' : ''; ?>>Check</option>
                                                    <option value="other" <?php echo $booking['payment_method'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label for="payment_paid_at_<?php echo (int) $booking['id']; ?>">Payment Paid At</label>
                                                <input
                                                    type="datetime-local"
                                                    id="payment_paid_at_<?php echo (int) $booking['id']; ?>"
                                                    name="payment_paid_at"
                                                    value="<?php echo h(formatDateTimeLocalInput($booking['payment_paid_at'])); ?>"
                                                >
                                            </div>

                                            <div>
                                                <label for="payment_reference_<?php echo (int) $booking['id']; ?>">Payment Reference</label>
                                                <input
                                                    type="text"
                                                    id="payment_reference_<?php echo (int) $booking['id']; ?>"
                                                    name="payment_reference"
                                                    value="<?php echo h($booking['payment_reference']); ?>"
                                                    placeholder="Transaction ID, memo, check number, or note"
                                                >
                                            </div>
                                        </div>

                                        <div>
                                            <label for="payment_notes_<?php echo (int) $booking['id']; ?>">Payment Notes</label>
                                            <textarea
                                                id="payment_notes_<?php echo (int) $booking['id']; ?>"
                                                name="payment_notes"
                                                placeholder="Internal payment notes"><?php echo h($booking['payment_notes']); ?></textarea>
                                        </div>

                                        <div class="btn-row">
                                            <button type="submit" class="btn btn-gold">Save Payment Details</button>

                                            <?php if (!$isMemberBooking && isset($booking['email']) && trim((string) $booking['email']) !== ''): ?>
                                                <button
                                                    type="submit"
                                                    formmethod="post"
                                                    formaction="process-admin-non-member-booking-update.php"
                                                    name="action"
                                                    value="send_email"
                                                    class="btn btn-light"
                                                >Email Client</button>
                                                <input type="hidden" formmethod="post" formaction="process-admin-non-member-booking-update.php" name="id" value="<?php echo (int) $booking['id']; ?>">
                                            <?php endif; ?>

                                            <a class="btn btn-light" href="admin-dashboard.php">Back to Dashboard</a>
                                        </div>
                                    </form>

                                    <?php if (!$isMemberBooking && isset($booking['email']) && trim((string) $booking['email']) !== ''): ?>
                                        <form method="post" action="process-admin-non-member-booking-update.php" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="send_email">
                                            <input type="hidden" name="id" value="<?php echo (int) $booking['id']; ?>">
                                            <input type="hidden" name="return_url" value="admin-bookings.php?view=<?php echo h($view); ?>">
                                            <div class="btn-row">
                                                <button type="submit" class="btn btn-light">Email Client</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>