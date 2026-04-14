<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminBookingsH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminBookingsRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminBookingsNormalizeRole($value): string
{
    return strtolower(trim((string) $value));
}

function ddAdminBookingsSessionBool(string $key): bool
{
    return isset($_SESSION[$key]) && $_SESSION[$key] === true;
}

function ddAdminBookingsSessionNonempty(string $key): bool
{
    return isset($_SESSION[$key]) && $_SESSION[$key] !== '' && $_SESSION[$key] !== null;
}

function ddAdminBookingsIsAdmin(): bool
{
    $roleCandidates = array(
        ddAdminBookingsNormalizeRole($_SESSION['role'] ?? ''),
        ddAdminBookingsNormalizeRole($_SESSION['user_role'] ?? ''),
        ddAdminBookingsNormalizeRole($_SESSION['user_type'] ?? ''),
        ddAdminBookingsNormalizeRole($_SESSION['account_type'] ?? ''),
        ddAdminBookingsNormalizeRole($_SESSION['access_role'] ?? ''),
        ddAdminBookingsNormalizeRole($_SESSION['admin']['role'] ?? ''),
    );

    $hasAdminRole = in_array('admin', $roleCandidates, true);

    $hasAdminFlag = (
        ddAdminBookingsSessionBool('admin_logged_in')
        || ddAdminBookingsSessionBool('is_admin')
        || (
            isset($_SESSION['admin'])
            && is_array($_SESSION['admin'])
            && (
                (!empty($_SESSION['admin']['logged_in']) && $_SESSION['admin']['logged_in'] === true)
                || (!empty($_SESSION['admin']['is_admin']) && $_SESSION['admin']['is_admin'] === true)
            )
        )
    );

    $hasAdminIdentity = (
        ddAdminBookingsSessionNonempty('admin_id')
        || ddAdminBookingsSessionNonempty('admin_email')
        || ddAdminBookingsSessionNonempty('admin_name')
        || (
            isset($_SESSION['admin'])
            && is_array($_SESSION['admin'])
            && !empty($_SESSION['admin'])
        )
    );

    return ($hasAdminFlag && ($hasAdminRole || $hasAdminIdentity))
        || ($hasAdminRole && $hasAdminIdentity);
}

function ddAdminBookingsNormalizeAdminSession(): void
{
    if (!ddAdminBookingsIsAdmin()) {
        return;
    }

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['is_admin'] = true;

    if (empty($_SESSION['role'])) {
        $_SESSION['role'] = 'admin';
    }

    if (empty($_SESSION['user_role'])) {
        $_SESSION['user_role'] = 'admin';
    }

    if (empty($_SESSION['admin_name']) && !empty($_SESSION['admin']['name'])) {
        $_SESSION['admin_name'] = (string) $_SESSION['admin']['name'];
    }

    if (empty($_SESSION['admin_email']) && !empty($_SESSION['admin']['email'])) {
        $_SESSION['admin_email'] = (string) $_SESSION['admin']['email'];
    }

    if (empty($_SESSION['admin_id'])) {
        if (!empty($_SESSION['admin']['id'])) {
            $_SESSION['admin_id'] = (int) $_SESSION['admin']['id'];
        } elseif (!empty($_SESSION['user_id'])) {
            $_SESSION['admin_id'] = (int) $_SESSION['user_id'];
        }
    }

    if (empty($_SESSION['user_id']) && !empty($_SESSION['admin_id'])) {
        $_SESSION['user_id'] = (int) $_SESSION['admin_id'];
    }
}

if (!ddAdminBookingsIsAdmin()) {
    ddAdminBookingsRedirect('admin-login.php');
}

ddAdminBookingsNormalizeAdminSession();

function ddAdminBookingsQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminBookingsSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminBookingsSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminBookingsTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $row = ddAdminBookingsSafeFetchOne(
        $pdo,
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
        array(':table' => $table)
    );

    $cache[$table] = $row !== null;
    return $cache[$table];
}

function ddAdminBookingsGetTableColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminBookingsTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminBookingsQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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

function ddAdminBookingsFirstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = ddAdminBookingsGetTableColumns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminBookingsValueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminBookingsDecodeJsonIfPossible($value): ?array
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

function ddAdminBookingsCollectJsonSourcesFromRow(array $row): array
{
    $sources = array();

    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $decoded = ddAdminBookingsDecodeJsonIfPossible($value);
        if (is_array($decoded)) {
            $sources[] = $decoded;
        }
    }

    return $sources;
}

function ddAdminBookingsExtractNestedScalar(array $data, array $paths): string
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

function ddAdminBookingsBuildNameFromParts(array $row): string
{
    $first = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array('first_name', 'firstname', 'client_first_name', 'owner_first_name'),
        ''
    ));
    $last = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array('last_name', 'lastname', 'client_last_name', 'owner_last_name'),
        ''
    ));

    $full = trim($first . ' ' . $last);
    return $full !== '' ? $full : '';
}

function ddAdminBookingsExtractDisplayNotes(array $jsonSources, $rawNotes): string
{
    $rawNotes = trim((string) $rawNotes);

    if ($rawNotes !== '') {
        $decodedRawNotes = ddAdminBookingsDecodeJsonIfPossible($rawNotes);
        if (!is_array($decodedRawNotes)) {
            return $rawNotes;
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
            'care_instructions',
        ) as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                $text = trim((string) $json[$key]);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
    }

    $parts = array_values(array_unique($parts));

    return empty($parts) ? '' : implode("\n", $parts);
}

function ddAdminBookingsLookupRelatedName(PDO $pdo, string $table, $idValue, array $idCandidates, array $nameCandidates): string
{
    if ($idValue === null || $idValue === '' || !ddAdminBookingsTableExists($pdo, $table)) {
        return '';
    }

    $idColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $idCandidates);
    $nameColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $nameCandidates);

    if ($idColumn === null || $nameColumn === null) {
        return '';
    }

    $row = ddAdminBookingsSafeFetchOne(
        $pdo,
        'SELECT ' . ddAdminBookingsQuoteIdentifier($nameColumn) . ' AS resolved_name'
        . ' FROM ' . ddAdminBookingsQuoteIdentifier($table)
        . ' WHERE ' . ddAdminBookingsQuoteIdentifier($idColumn) . ' = :id LIMIT 1',
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

function ddAdminBookingsLookupRelatedFullName(
    PDO $pdo,
    string $table,
    $idValue,
    array $idCandidates,
    array $fullNameCandidates,
    array $firstNameCandidates,
    array $lastNameCandidates
): string {
    if ($idValue === null || $idValue === '' || !ddAdminBookingsTableExists($pdo, $table)) {
        return '';
    }

    $idColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $idCandidates);
    if ($idColumn === null) {
        return '';
    }

    $fullNameColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $fullNameCandidates);
    $firstNameColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $firstNameCandidates);
    $lastNameColumn = ddAdminBookingsFirstExistingColumn($pdo, $table, $lastNameCandidates);

    $selectParts = array();
    if ($fullNameColumn !== null) {
        $selectParts[] = ddAdminBookingsQuoteIdentifier($fullNameColumn) . ' AS full_name_value';
    }
    if ($firstNameColumn !== null) {
        $selectParts[] = ddAdminBookingsQuoteIdentifier($firstNameColumn) . ' AS first_name_value';
    }
    if ($lastNameColumn !== null) {
        $selectParts[] = ddAdminBookingsQuoteIdentifier($lastNameColumn) . ' AS last_name_value';
    }

    if (empty($selectParts)) {
        return '';
    }

    $row = ddAdminBookingsSafeFetchOne(
        $pdo,
        'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . ddAdminBookingsQuoteIdentifier($table)
        . ' WHERE ' . ddAdminBookingsQuoteIdentifier($idColumn) . ' = :id LIMIT 1',
        array(':id' => $idValue)
    );

    if ($row === null) {
        return '';
    }

    if (isset($row['full_name_value']) && trim((string) $row['full_name_value']) !== '') {
        return trim((string) $row['full_name_value']);
    }

    $first = trim((string) ($row['first_name_value'] ?? ''));
    $last = trim((string) ($row['last_name_value'] ?? ''));
    $full = trim($first . ' ' . $last);

    return $full !== '' ? $full : '';
}

function ddAdminBookingsNormalizeServiceType($type): string
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

function ddAdminBookingsServiceDisplayName($type): string
{
    $type = ddAdminBookingsNormalizeServiceType($type);

    if ($type === 'drop-in') {
        return 'Drop-In';
    }

    return ucfirst(str_replace('_', ' ', $type));
}

function ddAdminBookingsNormalizeStatus($status): string
{
    $status = strtolower(trim((string) $status));

    if ($status === 'new' || $status === 'open' || $status === 'unassigned') {
        return 'available';
    }
    if ($status === 'assigned' || $status === 'confirmed' || $status === 'accepted' || $status === 'approved') {
        return 'accepted';
    }
    if ($status === 'active' || $status === 'walking' || $status === 'started' || $status === 'in progress') {
        return 'in_progress';
    }
    if ($status === 'done' || $status === 'finished' || $status === 'closed' || $status === 'complete') {
        return 'completed';
    }
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'void' || $status === 'rejected') {
        return 'cancelled';
    }

    return $status !== '' ? $status : 'pending';
}

function ddAdminBookingsNormalizePublicStatus($status): string
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'confirmed', 'completed', 'cancelled');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return $status !== '' ? $status : 'new';
}

function ddAdminBookingsStatusBadgeClass($status): string
{
    $status = strtolower(trim((string) $status));

    $map = array(
        'pending' => 'badge-pending',
        'new' => 'badge-pending',
        'available' => 'badge-available',
        'accepted' => 'badge-accepted',
        'approved' => 'badge-accepted',
        'confirmed' => 'badge-accepted',
        'reviewed' => 'badge-progress',
        'in_progress' => 'badge-progress',
        'completed' => 'badge-complete',
        'complete' => 'badge-complete',
        'cancelled' => 'badge-cancelled',
        'canceled' => 'badge-cancelled',
        'rejected' => 'badge-cancelled',
    );

    return $map[$status] ?? 'badge-pending';
}

function ddAdminBookingsFormatDateDisplay($date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '—';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function ddAdminBookingsFormatTimeDisplay($time): string
{
    $time = trim((string) $time);
    if ($time === '') {
        return '—';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function ddAdminBookingsFormatDateTimeDisplay($value): string
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

function ddAdminBookingsFormatMoney($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (is_numeric($value)) {
        return '$' . number_format((float) $value, 2);
    }

    return '$' . (string) $value;
}

function ddAdminBookingsCountUnreadNotifications(PDO $pdo): int
{
    $table = 'notifications';
    if (!ddAdminBookingsTableExists($pdo, $table)) {
        return 0;
    }

    $statusCol = ddAdminBookingsFirstExistingColumn($pdo, $table, array('is_read', 'read_flag', 'status'));
    if ($statusCol === null) {
        $row = ddAdminBookingsSafeFetchOne(
            $pdo,
            'SELECT COUNT(*) AS count_value FROM ' . ddAdminBookingsQuoteIdentifier($table)
        );
        return (int) ($row['count_value'] ?? 0);
    }

    if ($statusCol === 'status') {
        $row = ddAdminBookingsSafeFetchOne(
            $pdo,
            'SELECT COUNT(*) AS count_value FROM ' . ddAdminBookingsQuoteIdentifier($table)
            . ' WHERE LOWER(COALESCE(' . ddAdminBookingsQuoteIdentifier($statusCol) . ", '')) IN ('unread', 'new')"
        );
        return (int) ($row['count_value'] ?? 0);
    }

    $row = ddAdminBookingsSafeFetchOne(
        $pdo,
        'SELECT COUNT(*) AS count_value FROM ' . ddAdminBookingsQuoteIdentifier($table)
        . ' WHERE COALESCE(' . ddAdminBookingsQuoteIdentifier($statusCol) . ', 0) = 0'
    );

    return (int) ($row['count_value'] ?? 0);
}

function ddAdminBookingsBaseTable(PDO $pdo): ?string
{
    foreach (array('bookings', 'walks') as $candidate) {
        if (ddAdminBookingsTableExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminBookingsResolveMemberClientName(PDO $pdo, array $row, array $jsonSources): string
{
    $name = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array(
            'client_name',
            'owner_name',
            'member_name',
            'customer_name',
            'customer',
            'full_name',
            'name',
            'display_name',
        ),
        ''
    ));

    if ($name === '') {
        $name = ddAdminBookingsBuildNameFromParts($row);
    }

    if ($name === '') {
        foreach ($jsonSources as $json) {
            $name = ddAdminBookingsExtractNestedScalar($json, array(
                'client_name',
                'owner_name',
                'member_name',
                'customer_name',
                'full_name',
                'name',
                'client.full_name',
                'client.name',
                'owner.full_name',
                'owner.name',
                'member.full_name',
                'member.name',
                'customer.full_name',
                'customer.name',
            ));
            if ($name !== '') {
                break;
            }
        }
    }

    if ($name === '') {
        $memberId = ddAdminBookingsValueFromRow($row, array('member_id', 'user_id', 'client_id'), null);

        if ($memberId !== null && $memberId !== '') {
            $name = ddAdminBookingsLookupRelatedFullName(
                $pdo,
                'members',
                $memberId,
                array('id', 'member_id', 'user_id'),
                array('full_name', 'name', 'client_name'),
                array('first_name'),
                array('last_name')
            );
        }

        if ($name === '' && $memberId !== null && $memberId !== '') {
            $name = ddAdminBookingsLookupRelatedFullName(
                $pdo,
                'users',
                $memberId,
                array('id', 'user_id', 'member_id'),
                array('full_name', 'name'),
                array('first_name'),
                array('last_name')
            );
        }

        if ($name === '' && $memberId !== null && $memberId !== '') {
            $name = ddAdminBookingsLookupRelatedFullName(
                $pdo,
                'client_profiles',
                $memberId,
                array('id', 'user_id', 'member_id', 'client_id'),
                array('full_name', 'name', 'client_name'),
                array('first_name'),
                array('last_name')
            );
        }
    }

    return $name !== '' ? $name : 'Member Client';
}

function ddAdminBookingsResolvePublicClientName(array $row, array $jsonSources): string
{
    $name = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array(
            'client_name',
            'owner_name',
            'full_name',
            'name',
            'customer_name',
            'customer',
        ),
        ''
    ));

    if ($name === '') {
        $name = ddAdminBookingsBuildNameFromParts($row);
    }

    if ($name === '') {
        foreach ($jsonSources as $json) {
            $name = ddAdminBookingsExtractNestedScalar($json, array(
                'client_name',
                'owner_name',
                'full_name',
                'name',
                'customer_name',
                'client.full_name',
                'client.name',
                'owner.full_name',
                'owner.name',
                'customer.full_name',
                'customer.name',
            ));
            if ($name !== '') {
                break;
            }
        }
    }

    return $name !== '' ? $name : 'Public Client';
}

function ddAdminBookingsResolvePetName(PDO $pdo, array $row, array $jsonSources): string
{
    $petName = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array('pet_name', 'dog_name', 'pet', 'dog'),
        ''
    ));

    if ($petName === '') {
        foreach ($jsonSources as $json) {
            $petName = ddAdminBookingsExtractNestedScalar($json, array(
                'pet_name',
                'dog_name',
                'pet',
                'dog',
                'pet.name',
                'dog.name',
            ));
            if ($petName !== '') {
                break;
            }
        }
    }

    if ($petName === '') {
        $petId = ddAdminBookingsValueFromRow($row, array('pet_id', 'dog_id'), null);

        if ($petId !== null && $petId !== '') {
            $petName = ddAdminBookingsLookupRelatedName(
                $pdo,
                'dogs',
                $petId,
                array('id', 'dog_id', 'pet_id'),
                array('dog_name', 'pet_name', 'name')
            );
        }

        if ($petName === '' && $petId !== null && $petId !== '') {
            $petName = ddAdminBookingsLookupRelatedName(
                $pdo,
                'pets',
                $petId,
                array('id', 'pet_id', 'dog_id'),
                array('pet_name', 'dog_name', 'name')
            );
        }
    }

    return $petName;
}

function ddAdminBookingsResolveWorkerName(PDO $pdo, array $row): string
{
    $name = trim((string) ddAdminBookingsValueFromRow(
        $row,
        array(
            'walker_name',
            'worker_name',
            'assigned_walker_name',
            'assigned_worker_name',
        ),
        ''
    ));

    if ($name !== '') {
        return $name;
    }

    $workerId = ddAdminBookingsValueFromRow($row, array('walker_id', 'worker_id', 'assigned_worker_id', 'assigned_walker_id'), null);

    if ($workerId === null || $workerId === '') {
        return '';
    }

    $name = ddAdminBookingsLookupRelatedFullName(
        $pdo,
        'walkers',
        $workerId,
        array('id', 'walker_id', 'worker_id'),
        array('full_name', 'name', 'walker_name', 'worker_name'),
        array('first_name'),
        array('last_name')
    );

    if ($name === '') {
        $name = ddAdminBookingsLookupRelatedFullName(
            $pdo,
            'workers',
            $workerId,
            array('id', 'worker_id', 'walker_id'),
            array('full_name', 'name', 'worker_name', 'walker_name'),
            array('first_name'),
            array('last_name')
        );
    }

    return $name;
}

function ddAdminBookingsBuildSortTimestamp(array $row): int
{
    foreach (array('created_at', 'updated_at', 'submitted_at', 'booking_date', 'service_date', 'date') as $key) {
        if (!empty($row[$key])) {
            $ts = strtotime((string) $row[$key]);
            if ($ts !== false) {
                return $ts;
            }
        }
    }

    return 0;
}

function ddAdminBookingsFetchMemberBookings(PDO $pdo): array
{
    $table = ddAdminBookingsBaseTable($pdo);
    if ($table === null) {
        return array();
    }

    $rows = ddAdminBookingsSafeFetchAll(
        $pdo,
        'SELECT * FROM ' . ddAdminBookingsQuoteIdentifier($table) . ' ORDER BY rowid DESC'
    );

    $normalized = array();

    foreach ($rows as $row) {
        $jsonSources = ddAdminBookingsCollectJsonSourcesFromRow($row);

        $id = (int) ddAdminBookingsValueFromRow($row, array('id', 'booking_id'), 0);
        $serviceType = (string) ddAdminBookingsValueFromRow($row, array('service_type', 'service'), '');
        $status = ddAdminBookingsNormalizeStatus(ddAdminBookingsValueFromRow($row, array('status'), 'pending'));
        $date = (string) ddAdminBookingsValueFromRow($row, array('date', 'booking_date', 'service_date'), '');
        $time = (string) ddAdminBookingsValueFromRow($row, array('time', 'service_time', 'booking_time', 'start_time'), '');
        $createdAt = (string) ddAdminBookingsValueFromRow($row, array('created_at', 'submitted_at', 'updated_at'), '');
        $price = ddAdminBookingsValueFromRow($row, array('price', 'amount', 'total_price', 'total'), '');
        $paymentStatus = (string) ddAdminBookingsValueFromRow($row, array('payment_status', 'paid_status'), '');
        $paymentMethod = (string) ddAdminBookingsValueFromRow($row, array('payment_method'), '');
        $notesRaw = (string) ddAdminBookingsValueFromRow($row, array('notes', 'special_instructions', 'instructions', 'care_notes', 'client_notes'), '');

        $clientName = ddAdminBookingsResolveMemberClientName($pdo, $row, $jsonSources);
        $petName = ddAdminBookingsResolvePetName($pdo, $row, $jsonSources);
        $workerName = ddAdminBookingsResolveWorkerName($pdo, $row);
        $notes = ddAdminBookingsExtractDisplayNotes($jsonSources, $notesRaw);

        $normalized[] = array(
            'source' => 'member',
            'source_label' => 'Member',
            'table' => $table,
            'id' => $id,
            'service_type' => ddAdminBookingsNormalizeServiceType($serviceType),
            'service_label' => ddAdminBookingsServiceDisplayName($serviceType),
            'client_name' => $clientName,
            'pet_name' => $petName,
            'worker_name' => $workerName,
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'date' => $date,
            'time' => $time,
            'created_at' => $createdAt,
            'price' => $price,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'notes' => $notes,
            'sort_timestamp' => ddAdminBookingsBuildSortTimestamp($row),
            'view_url' => 'booking-details.php?id=' . $id,
            'edit_url' => 'admin-edit-booking.php?id=' . $id,
            'assign_url' => 'admin-assign-walker.php?id=' . $id,
            'status_url' => 'admin-update-booking-status.php?id=' . $id,
        );
    }

    return $normalized;
}

function ddAdminBookingsFetchPublicBookings(PDO $pdo): array
{
    $table = 'non_member_bookings';
    if (!ddAdminBookingsTableExists($pdo, $table)) {
        return array();
    }

    $rows = ddAdminBookingsSafeFetchAll(
        $pdo,
        'SELECT * FROM ' . ddAdminBookingsQuoteIdentifier($table) . ' ORDER BY rowid DESC'
    );

    $normalized = array();

    foreach ($rows as $row) {
        $jsonSources = ddAdminBookingsCollectJsonSourcesFromRow($row);

        $id = (int) ddAdminBookingsValueFromRow($row, array('id', 'booking_id'), 0);
        $serviceType = (string) ddAdminBookingsValueFromRow($row, array('service_type', 'service'), '');
        $status = ddAdminBookingsNormalizePublicStatus(ddAdminBookingsValueFromRow($row, array('status'), 'new'));
        $date = (string) ddAdminBookingsValueFromRow($row, array('date', 'booking_date', 'service_date'), '');
        $time = (string) ddAdminBookingsValueFromRow($row, array('time', 'service_time', 'booking_time', 'start_time'), '');
        $createdAt = (string) ddAdminBookingsValueFromRow($row, array('created_at', 'submitted_at', 'updated_at'), '');
        $price = ddAdminBookingsValueFromRow($row, array('price', 'amount', 'total_price', 'total'), '');
        $paymentStatus = (string) ddAdminBookingsValueFromRow($row, array('payment_status', 'paid_status'), '');
        $paymentMethod = (string) ddAdminBookingsValueFromRow($row, array('payment_method'), '');
        $notesRaw = (string) ddAdminBookingsValueFromRow($row, array('notes', 'special_instructions', 'instructions', 'care_notes', 'client_notes'), '');

        $clientName = ddAdminBookingsResolvePublicClientName($row, $jsonSources);
        $petName = ddAdminBookingsResolvePetName($pdo, $row, $jsonSources);
        $notes = ddAdminBookingsExtractDisplayNotes($jsonSources, $notesRaw);

        $normalized[] = array(
            'source' => 'public',
            'source_label' => 'Public',
            'table' => $table,
            'id' => $id,
            'service_type' => ddAdminBookingsNormalizeServiceType($serviceType),
            'service_label' => ddAdminBookingsServiceDisplayName($serviceType),
            'client_name' => $clientName,
            'pet_name' => $petName,
            'worker_name' => '',
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'date' => $date,
            'time' => $time,
            'created_at' => $createdAt,
            'price' => $price,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'notes' => $notes,
            'sort_timestamp' => ddAdminBookingsBuildSortTimestamp($row),
            'view_url' => 'admin-non-member-booking-view.php?id=' . $id,
            'edit_url' => 'admin-booking-update.php?id=' . $id,
            'assign_url' => '',
            'status_url' => 'admin-booking-update.php?id=' . $id,
        );
    }

    return $normalized;
}

function ddAdminBookingsSortNormalizedBookings(array $bookings): array
{
    usort($bookings, function (array $a, array $b): int {
        $aTs = (int) ($a['sort_timestamp'] ?? 0);
        $bTs = (int) ($b['sort_timestamp'] ?? 0);

        if ($aTs === $bTs) {
            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        }

        return $bTs <=> $aTs;
    });

    return $bookings;
}

$view = strtolower(trim((string) ($_GET['view'] ?? 'all')));
if (!in_array($view, array('all', 'member', 'public'), true)) {
    $view = 'all';
}

$memberBookings = ddAdminBookingsFetchMemberBookings($pdo);
$publicBookings = ddAdminBookingsFetchPublicBookings($pdo);

$memberCount = count($memberBookings);
$publicCount = count($publicBookings);
$totalCount = $memberCount + $publicCount;
$newPublicCount = 0;

foreach ($publicBookings as $booking) {
    if (($booking['status'] ?? '') === 'new') {
        $newPublicCount++;
    }
}

$displayBookings = array();
if ($view === 'member') {
    $displayBookings = $memberBookings;
} elseif ($view === 'public') {
    $displayBookings = $publicBookings;
} else {
    $displayBookings = array_merge($memberBookings, $publicBookings);
}

$displayBookings = ddAdminBookingsSortNormalizedBookings($displayBookings);
$unreadNotifications = ddAdminBookingsCountUnreadNotifications($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bookings | Doggie Dorian’s</title>
    <meta name="description" content="Doggie Dorian’s admin bookings control panel.">
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .brand {
            font-weight: 900;
            font-size: 22px;
            letter-spacing: .03em;
            color: #fff;
        }

        .top-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: #fff;
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            padding: 22px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
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
            color: #fff;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.2rem;
            color: #fff;
        }

        .sub {
            color: rgba(244,241,234,0.76);
            line-height: 1.65;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 20px;
        }

        .stat {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 20px;
            padding: 16px;
        }

        .stat-label {
            color: rgba(244,241,234,0.64);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: #fff;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .filter-pill {
            padding: 10px 14px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.86);
        }

        .filter-pill.active {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
            border-color: transparent;
        }

        .meta-strip {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.88);
            font-size: .88rem;
            font-weight: 700;
        }

        .booking-list {
            display: grid;
            gap: 16px;
        }

        .booking-card {
            padding: 22px;
        }

        .booking-top {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .booking-title {
            font-size: 1.08rem;
            font-weight: 900;
            line-height: 1.4;
            color: #fff;
        }

        .booking-subtitle {
            margin-top: 6px;
            color: rgba(244,241,234,0.66);
            font-size: .92rem;
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
            white-space: nowrap;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }

        .source-badge {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.86);
            font-size: .78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .booking-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-box {
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            padding: 14px;
        }

        .info-label {
            color: rgba(244,241,234,0.58);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 7px;
        }

        .info-value {
            color: #fff;
            font-weight: 800;
            line-height: 1.45;
            word-break: break-word;
        }

        .notes-box {
            margin-top: 6px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
        }

        .notes-text {
            color: rgba(244,241,234,0.78);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: #fff;
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
            border-color: transparent;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 1180px) {
            .hero,
            .stats,
            .booking-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 760px) {
            .hero,
            .stats,
            .booking-grid {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.7rem;
            }

            .action-row {
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
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-revenue.php">Revenue</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-members.php">Members</a>
                <a class="top-link" href="admin-group-walk-applications.php">Group Walks</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Booking Control</div>
                <h1>Admin Bookings</h1>
                <div class="sub">
                    Review both member and public booking activity from one launch-ready dashboard page.
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
                        <div class="stat-label">New Public</div>
                        <div class="stat-value"><?php echo (int) $newPublicCount; ?></div>
                    </div>
                </div>

                <div class="meta-strip">
                    <div class="meta-chip">Unread Notifications: <?php echo (int) $unreadNotifications; ?></div>
                    <div class="meta-chip">Current View: <?php echo ddAdminBookingsH(ucfirst($view)); ?></div>
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

                <div class="action-row" style="margin-top:18px;">
                    <a class="btn btn-gold" href="admin-create-booking.php">Create Booking</a>
                    <a class="btn" href="admin-walks.php">View Walks</a>
                    <a class="btn" href="admin-non-member-bookings.php">Public Bookings Page</a>
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
                    <div class="card booking-card">
                        <div class="booking-top">
                            <div>
                                <div class="booking-title">
                                    #<?php echo (int) $booking['id']; ?>
                                    · <?php echo ddAdminBookingsH($booking['service_label']); ?>
                                    · <?php echo ddAdminBookingsH($booking['client_name']); ?>
                                </div>
                                <div class="booking-subtitle">
                                    <span class="source-badge"><?php echo ddAdminBookingsH($booking['source_label']); ?></span>
                                    <?php if ($booking['pet_name'] !== ''): ?>
                                        · Pet: <?php echo ddAdminBookingsH($booking['pet_name']); ?>
                                    <?php endif; ?>
                                    <?php if ($booking['worker_name'] !== ''): ?>
                                        · Worker: <?php echo ddAdminBookingsH($booking['worker_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <span class="badge <?php echo ddAdminBookingsH(ddAdminBookingsStatusBadgeClass($booking['status'])); ?>">
                                <?php echo ddAdminBookingsH($booking['status_label']); ?>
                            </span>
                        </div>

                        <div class="booking-grid">
                            <div class="info-box">
                                <div class="info-label">Service Date</div>
                                <div class="info-value"><?php echo ddAdminBookingsH(ddAdminBookingsFormatDateDisplay($booking['date'])); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Service Time</div>
                                <div class="info-value"><?php echo ddAdminBookingsH(ddAdminBookingsFormatTimeDisplay($booking['time'])); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Created</div>
                                <div class="info-value"><?php echo ddAdminBookingsH(ddAdminBookingsFormatDateTimeDisplay($booking['created_at'])); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Price</div>
                                <div class="info-value"><?php echo ddAdminBookingsH(ddAdminBookingsFormatMoney($booking['price'])); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Payment Status</div>
                                <div class="info-value"><?php echo ddAdminBookingsH($booking['payment_status'] !== '' ? $booking['payment_status'] : '—'); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Payment Method</div>
                                <div class="info-value"><?php echo ddAdminBookingsH($booking['payment_method'] !== '' ? $booking['payment_method'] : '—'); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Source Table</div>
                                <div class="info-value"><?php echo ddAdminBookingsH($booking['table']); ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Booking ID</div>
                                <div class="info-value"><?php echo (int) $booking['id']; ?></div>
                            </div>
                        </div>

                        <?php if ($booking['notes'] !== ''): ?>
                            <div class="notes-box">
                                <div class="info-label">Notes</div>
                                <div class="notes-text"><?php echo ddAdminBookingsH($booking['notes']); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="action-row">
                            <a class="btn btn-gold" href="<?php echo ddAdminBookingsH($booking['view_url']); ?>">Open Booking</a>

                            <?php if ($booking['edit_url'] !== ''): ?>
                                <a class="btn" href="<?php echo ddAdminBookingsH($booking['edit_url']); ?>">Edit Booking</a>
                            <?php endif; ?>

                            <?php if ($booking['assign_url'] !== ''): ?>
                                <a class="btn" href="<?php echo ddAdminBookingsH($booking['assign_url']); ?>">Assign Worker</a>
                            <?php endif; ?>

                            <?php if ($booking['status_url'] !== ''): ?>
                                <a class="btn" href="<?php echo ddAdminBookingsH($booking['status_url']); ?>">Update Status</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>