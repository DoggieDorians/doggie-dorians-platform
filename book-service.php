<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

$referralInclude = __DIR__ . '/includes/referral.php';
if (is_file($referralInclude)) {
    require_once $referralInclude;
}

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
    } catch (Exception $e) {
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
    } catch (Exception $e) {
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

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function old($key, array $data)
{
    return h(isset($data[$key]) ? $data[$key] : '');
}

function normalizeServiceTypeLocal($value)
{
    $value = strtolower(trim((string) $value));

    if ($value === 'dropin' || $value === 'drop_in') {
        return 'drop-in';
    }

    if ($value === 'in-home-sitting' || $value === 'in_home_sitting') {
        return 'sitting';
    }

    $allowed = array('walk', 'boarding', 'daycare', 'sitting', 'drop-in');
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'walk';
}

function serviceLabel($serviceType)
{
    if ($serviceType === 'walk') {
        return 'Walk';
    }
    if ($serviceType === 'boarding') {
        return 'Boarding';
    }
    if ($serviceType === 'daycare') {
        return 'Daycare';
    }
    if ($serviceType === 'sitting') {
        return 'In-Home Sitting';
    }
    if ($serviceType === 'drop-in') {
        return 'Drop-In';
    }

    return 'Service';
}

function normalizeReferralCodeLocal($code)
{
    if (function_exists('normalizeReferralCode')) {
        return normalizeReferralCode($code);
    }

    $code = strtoupper(trim((string) $code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return substr($code, 0, 50);
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

function isLoggedInMember()
{
    return currentUserId() > 0 && !in_array(currentUserRole(), array('walker'), true);
}

function bookingTable(PDO $pdo)
{
    $candidates = array('bookings', 'walks');

    foreach ($candidates as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function getUserDisplayName(PDO $pdo, $userId)
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

function getPetsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $results = array();
    $tables = array('pets', 'dogs');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
        $ownerCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'owner_id', 'client_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('name', 'pet_name', 'dog_name'));
        $breedCol = firstExistingColumn($pdo, $table, array('breed'));
        $sizeCol = firstExistingColumn($pdo, $table, array('size'));

        if ($idCol === null || $ownerCol === null || $nameCol === null) {
            continue;
        }

        $select = "{$idCol} AS pet_id, {$nameCol} AS pet_name";
        $select .= $breedCol !== null ? ", {$breedCol} AS breed" : ", '' AS breed";
        $select .= $sizeCol !== null ? ", {$sizeCol} AS size" : ", '' AS size";

        $stmt = $pdo->prepare("SELECT {$select} FROM {$table} WHERE {$ownerCol} = :user_id ORDER BY {$nameCol} ASC");
        if (!safeExecute($stmt, array(':user_id' => $userId))) {
            continue;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $results[] = array(
                'pet_id' => (int) (isset($row['pet_id']) ? $row['pet_id'] : 0),
                'pet_name' => (string) (isset($row['pet_name']) ? $row['pet_name'] : ''),
                'breed' => (string) (isset($row['breed']) ? $row['breed'] : ''),
                'size' => strtolower((string) (isset($row['size']) ? $row['size'] : '')),
            );
        }

        if (!empty($results)) {
            break;
        }
    }

    return $results;
}

function writeNotification(PDO $pdo, $userId, $bookingId, $title, $message)
{
    $userId = (int) $userId;
    $bookingId = (int) $bookingId;

    if (!hasTable($pdo, 'notifications')) {
        return;
    }

    $columns = getTableColumns($pdo, 'notifications');
    if (empty($columns)) {
        return;
    }

    $data = array();

    if (in_array('user_id', $columns, true)) {
        $data['user_id'] = $userId;
    }
    if (in_array('member_id', $columns, true)) {
        $data['member_id'] = $userId;
    }
    if (in_array('booking_id', $columns, true)) {
        $data['booking_id'] = $bookingId;
    }
    if (in_array('type', $columns, true)) {
        $data['type'] = 'booking';
    }
    if (in_array('title', $columns, true)) {
        $data['title'] = $title;
    }

    if (in_array('message', $columns, true)) {
        $data['message'] = $message;
    } elseif (in_array('content', $columns, true)) {
        $data['content'] = $message;
    } elseif (in_array('body', $columns, true)) {
        $data['body'] = $message;
    }

    if (in_array('is_read', $columns, true)) {
        $data['is_read'] = 0;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = date('Y-m-d H:i:s');
    }

    if (empty($data)) {
        return;
    }

    $fields = array_keys($data);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    safeExecute($stmt, $params);
}

function lookupReferralOwnerName(PDO $pdo, $referralCode)
{
    $referralCode = (string) $referralCode;

    if ($referralCode === '' || !hasTable($pdo, 'users')) {
        return '';
    }

    $idCol = firstExistingColumn($pdo, 'users', array('id', 'user_id'));
    if ($idCol === null) {
        return '';
    }

    $nameCol = firstExistingColumn($pdo, 'users', array('full_name', 'name', 'username', 'email'));
    if ($nameCol === null) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT {$nameCol} AS display_name FROM users WHERE UPPER(referral_code) = UPPER(:code) LIMIT 1");
        if (!$stmt->execute(array(':code' => $referralCode))) {
            return '';
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && trim((string) (isset($row['display_name']) ? $row['display_name'] : '')) !== '')
            ? (string) $row['display_name']
            : '';
    } catch (Throwable $e) {
        return '';
    } catch (Exception $e) {
        return '';
    }
}

function memberServiceConfig()
{
    return array(
        'drop_in' => array(
            'hourly_rate' => 25.00,
            'walk_add_on' => 7.00,
            'max_hours' => 2,
            'walk_duration_minutes' => 30,
        ),
        'daycare' => array(
            'base_rate' => 55.00,
            'hours' => 6,
            'food_fee' => 5.00,
            'included_walks' => 1,
            'walk_duration_minutes' => 30,
            'additional_walk_rate' => 10.00,
        ),
        'sitting' => array(
            'base_rate' => 120.00,
            'hours' => 4,
            'included_walks' => 1,
            'walk_duration_minutes' => 30,
            'additional_walk_rate' => 10.00,
        ),
    );
}

function calculateMemberBookingPricing(array $input)
{
    $config = memberServiceConfig();

    $serviceType = normalizeServiceTypeLocal(isset($input['service_type']) ? $input['service_type'] : '');
    $petSize = strtolower(trim((string) (isset($input['pet_size']) ? $input['pet_size'] : '')));
    $durationMinutes = (int) (isset($input['duration_minutes']) ? $input['duration_minutes'] : 0);
    $startDate = trim((string) (isset($input['start_date']) ? $input['start_date'] : ''));
    $endDate = trim((string) (isset($input['end_date']) ? $input['end_date'] : ''));
    $dropInHours = (int) (isset($input['drop_in_hours']) ? $input['drop_in_hours'] : 1);
    $dropInAddWalk = !empty($input['drop_in_add_walk']);
    $daycareProvideFood = !empty($input['daycare_provide_food']);
    $daycareExtraWalks = max(0, (int) (isset($input['daycare_extra_walks']) ? $input['daycare_extra_walks'] : 0));
    $sittingExtraWalks = max(0, (int) (isset($input['sitting_extra_walks']) ? $input['sitting_extra_walks'] : 0));

    if ($serviceType === 'walk') {
        return dd_get_service_pricing('walk', true, array(
            'duration_minutes' => $durationMinutes,
        ));
    }

    if ($serviceType === 'daycare') {
        $basePrice = (float) $config['daycare']['base_rate'];
        $foodFee = $daycareProvideFood ? (float) $config['daycare']['food_fee'] : 0.00;
        $extraWalkCost = $daycareExtraWalks * (float) $config['daycare']['additional_walk_rate'];
        $totalPrice = $basePrice + $foodFee + $extraWalkCost;

        return array(
            'service_type' => 'daycare',
            'pricing_type' => 'member',
            'discount_label' => 'member_daycare_6hr_custom',
            'quantity' => 1,
            'unit_label' => 'session',
            'unit_price' => $basePrice,
            'total_price' => $totalPrice,
            'duration' => (int) $config['daycare']['hours'] * 60,
            'dog_size' => $petSize !== '' ? $petSize : null,
            'pricing_breakdown' => array(
                'base_price' => $basePrice,
                'food_fee' => $foodFee,
                'included_walks' => (int) $config['daycare']['included_walks'],
                'included_walk_duration_minutes' => (int) $config['daycare']['walk_duration_minutes'],
                'extra_walks' => $daycareExtraWalks,
                'extra_walk_rate' => (float) $config['daycare']['additional_walk_rate'],
                'extra_walk_cost' => $extraWalkCost,
                'session_hours' => (int) $config['daycare']['hours'],
            ),
        );
    }

    if ($serviceType === 'boarding') {
        $nights = dd_calculate_boarding_nights($startDate, $endDate);

        return dd_get_service_pricing('boarding', true, array(
            'dog_size' => $petSize,
            'quantity' => $nights,
        ));
    }

    if ($serviceType === 'drop-in') {
        $dropInHours = max(1, min((int) $config['drop_in']['max_hours'], $dropInHours));
        $basePrice = $dropInHours * (float) $config['drop_in']['hourly_rate'];
        $walkFee = $dropInAddWalk ? (float) $config['drop_in']['walk_add_on'] : 0.00;
        $totalPrice = $basePrice + $walkFee;

        return array(
            'service_type' => 'drop-in',
            'pricing_type' => 'member',
            'discount_label' => 'member_dropin_hourly_custom',
            'quantity' => $dropInHours,
            'unit_label' => 'hour',
            'unit_price' => (float) $config['drop_in']['hourly_rate'],
            'total_price' => $totalPrice,
            'duration' => $dropInHours * 60,
            'dog_size' => $petSize !== '' ? $petSize : null,
            'pricing_breakdown' => array(
                'base_price' => $basePrice,
                'hours' => $dropInHours,
                'walk_added' => $dropInAddWalk ? 1 : 0,
                'walk_duration_minutes' => (int) $config['drop_in']['walk_duration_minutes'],
                'walk_fee' => $walkFee,
            ),
        );
    }

    if ($serviceType === 'sitting') {
        $basePrice = (float) $config['sitting']['base_rate'];
        $extraWalkCost = $sittingExtraWalks * (float) $config['sitting']['additional_walk_rate'];
        $totalPrice = $basePrice + $extraWalkCost;

        return array(
            'service_type' => 'sitting',
            'pricing_type' => 'member',
            'discount_label' => 'member_in_home_sitting_custom',
            'quantity' => 1,
            'unit_label' => 'session',
            'unit_price' => $basePrice,
            'total_price' => $totalPrice,
            'duration' => (int) $config['sitting']['hours'] * 60,
            'dog_size' => $petSize !== '' ? $petSize : null,
            'pricing_breakdown' => array(
                'base_price' => $basePrice,
                'included_walks' => (int) $config['sitting']['included_walks'],
                'included_walk_duration_minutes' => (int) $config['sitting']['walk_duration_minutes'],
                'extra_walks' => $sittingExtraWalks,
                'extra_walk_rate' => (float) $config['sitting']['additional_walk_rate'],
                'extra_walk_cost' => $extraWalkCost,
                'session_hours' => (int) $config['sitting']['hours'],
            ),
        );
    }

    throw new InvalidArgumentException('Invalid service type selected.');
}

function serializeBookingMeta(array $meta)
{
    $clean = array();

    foreach ($meta as $key => $value) {
        if (is_bool($value)) {
            $clean[$key] = $value ? 1 : 0;
        } else {
            $clean[$key] = $value;
        }
    }

    return json_encode($clean, JSON_UNESCAPED_SLASHES);
}

function normalizeTimeForSql($time)
{
    $time = trim((string) $time);

    if ($time === '') {
        return '';
    }

    $formats = array('H:i:s', 'H:i', 'g:i A', 'g:iA', 'h:i A', 'h:iA');

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $time);
        if ($dt instanceof DateTime) {
            return $dt->format('H:i:s');
        }
    }

    $ts = strtotime($time);
    if ($ts !== false) {
        return date('H:i:s', $ts);
    }

    return '';
}

function combineDateAndTime($date, $time)
{
    $date = trim((string) $date);
    $time = normalizeTimeForSql($time);

    if ($date === '' || $time === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
    return $dt instanceof DateTime ? $dt : null;
}

function bookingEndDateForCapacity($serviceType, $serviceDate, $endDate)
{
    $serviceType = normalizeServiceTypeLocal($serviceType);

    if ($serviceType === 'boarding') {
        $cleanEndDate = trim((string) $endDate);
        if ($cleanEndDate !== '') {
            return $cleanEndDate;
        }
    }

    return trim((string) $serviceDate);
}

function getRequestedDateRangeForCapacity($serviceType, $serviceDate, $endDate)
{
    $start = trim((string) $serviceDate);
    $end = bookingEndDateForCapacity($serviceType, $serviceDate, $endDate);

    if ($start === '') {
        return array('', '');
    }

    if ($end === '') {
        $end = $start;
    }

    if ($end < $start) {
        $end = $start;
    }

    return array($start, $end);
}

function dayOfWeekForDate($date)
{
    $ts = strtotime((string) $date);
    if ($ts === false) {
        return null;
    }

    return (int) date('w', $ts);
}

function capacityBlockingStatuses()
{
    return array(
        'pending',
        'available',
        'accepted',
        'assigned',
        'confirmed',
        'in_progress',
        'in progress',
        'active',
        'walking',
        'started',
    );
}

function normalizedStatusCountsForCapacity($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'in progress') {
        return 'in_progress';
    }

    return $status;
}

function getActiveWalkerFallbackCapacity(PDO $pdo)
{
    if (!hasTable($pdo, 'walkers')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'walkers');
    if (empty($columns)) {
        return 0;
    }

    try {
        if (in_array('is_active', $columns, true)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM walkers WHERE COALESCE(is_active, 0) = 1");
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM walkers");
        }

        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function walkerAvailabilityHasAnyRows(PDO $pdo)
{
    if (!hasTable($pdo, 'walker_availability')) {
        return false;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM walker_availability WHERE COALESCE(is_active, 1) = 1");
        return ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function getAvailableWalkerCapacity(PDO $pdo, $serviceDate, $serviceTime, $durationMinutes)
{
    $durationMinutes = max(1, (int) $durationMinutes);

    if (!walkerAvailabilityHasAnyRows($pdo)) {
        return getActiveWalkerFallbackCapacity($pdo);
    }

    $dayOfWeek = dayOfWeekForDate($serviceDate);
    $startTime = normalizeTimeForSql($serviceTime);

    if ($dayOfWeek === null || $startTime === '') {
        return 0;
    }

    $startDateTime = combineDateAndTime($serviceDate, $startTime);
    if (!$startDateTime instanceof DateTime) {
        return 0;
    }

    $endDateTime = clone $startDateTime;
    $endDateTime->modify('+' . $durationMinutes . ' minutes');

    $requestStart = $startDateTime->format('H:i:s');
    $requestEnd = $endDateTime->format('H:i:s');

    try {
        $sql = "
            SELECT COUNT(DISTINCT wa.walker_id)
            FROM walker_availability wa
            INNER JOIN walkers w ON w.id = wa.walker_id
            WHERE wa.day_of_week = :day_of_week
              AND COALESCE(wa.is_active, 1) = 1
              AND COALESCE(w.is_active, 1) = 1
              AND wa.start_time < :request_end
              AND wa.end_time > :request_start
        ";

        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute(array(
            ':day_of_week' => $dayOfWeek,
            ':request_start' => $requestStart,
            ':request_end' => $requestEnd,
        ));

        if (!$ok) {
            return 0;
        }

        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function countOverlappingMemberBookings(PDO $pdo, $serviceDate, $endDate, $serviceTime, $durationMinutes)
{
    $table = bookingTable($pdo);
    if ($table === null) {
        return 0;
    }

    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return 0;
    }

    $serviceDateCol = firstExistingColumn($pdo, $table, array('service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date'));
    $serviceTimeCol = firstExistingColumn($pdo, $table, array('service_time', 'booking_time', 'walk_time', 'time', 'scheduled_time', 'start_time'));
    $durationCol = firstExistingColumn($pdo, $table, array('duration_minutes', 'duration', 'minutes'));
    $statusCol = firstExistingColumn($pdo, $table, array('status', 'booking_status', 'service_status', 'walk_status'));
    $endDateCol = firstExistingColumn($pdo, $table, array('end_date', 'check_out_date'));

    if ($serviceDateCol === null || $serviceTimeCol === null || $durationCol === null || $statusCol === null) {
        return 0;
    }

    list($requestStartDate, $requestEndDate) = getRequestedDateRangeForCapacity('walk', $serviceDate, $endDate);
    $requestStartTime = normalizeTimeForSql($serviceTime);

    if ($requestStartDate === '' || $requestStartTime === '') {
        return 0;
    }

    $requestStart = combineDateAndTime($requestStartDate, $requestStartTime);
    if (!$requestStart instanceof DateTime) {
        return 0;
    }

    $requestEnd = clone $requestStart;
    $requestEnd->modify('+' . max(1, (int) $durationMinutes) . ' minutes');

    try {
        $dateSql = $endDateCol !== null
            ? "COALESCE(NULLIF({$endDateCol}, ''), {$serviceDateCol}) >= :request_start_date AND {$serviceDateCol} <= :request_end_date"
            : "{$serviceDateCol} = :request_start_date";

        $stmt = $pdo->prepare("
            SELECT {$serviceDateCol} AS service_date_value,
                   {$serviceTimeCol} AS service_time_value,
                   {$durationCol} AS duration_value,
                   {$statusCol} AS status_value
            FROM {$table}
            WHERE {$dateSql}
        ");

        $params = array(
            ':request_start_date' => $requestStartDate,
            ':request_end_date' => $requestEndDate,
        );

        if (!$stmt->execute($params)) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        foreach ($rows as $row) {
            $status = normalizedStatusCountsForCapacity(isset($row['status_value']) ? $row['status_value'] : '');
            if (!in_array($status, capacityBlockingStatuses(), true)) {
                continue;
            }

            $rowDate = trim((string) (isset($row['service_date_value']) ? $row['service_date_value'] : ''));
            $rowTime = normalizeTimeForSql(isset($row['service_time_value']) ? $row['service_time_value'] : '');
            $rowDuration = max(1, (int) (isset($row['duration_value']) ? $row['duration_value'] : 0));

            $rowStart = combineDateAndTime($rowDate, $rowTime);
            if (!$rowStart instanceof DateTime) {
                continue;
            }

            $rowEnd = clone $rowStart;
            $rowEnd->modify('+' . $rowDuration . ' minutes');

            if ($rowStart < $requestEnd && $rowEnd > $requestStart) {
                $count++;
            }
        }

        return $count;
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function countOverlappingNonMemberBookings(PDO $pdo, $serviceDate, $endDate, $serviceTime, $durationMinutes)
{
    if (!hasTable($pdo, 'non_member_bookings')) {
        return 0;
    }

    $table = 'non_member_bookings';
    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return 0;
    }

    $dateStartCol = firstExistingColumn($pdo, $table, array('date_start', 'service_date', 'booking_date', 'start_date'));
    $dateEndCol = firstExistingColumn($pdo, $table, array('date_end', 'end_date', 'check_out_date'));
    $timeCol = firstExistingColumn($pdo, $table, array('preferred_walk_time', 'service_time', 'preferred_time', 'time', 'start_time'));
    $durationCol = firstExistingColumn($pdo, $table, array('walk_duration', 'duration_minutes', 'duration'));
    $statusCol = firstExistingColumn($pdo, $table, array('status'));

    if ($dateStartCol === null || $timeCol === null || $statusCol === null) {
        return 0;
    }

    list($requestStartDate, $requestEndDate) = getRequestedDateRangeForCapacity('walk', $serviceDate, $endDate);
    $requestStartTime = normalizeTimeForSql($serviceTime);

    if ($requestStartDate === '' || $requestStartTime === '') {
        return 0;
    }

    $requestStart = combineDateAndTime($requestStartDate, $requestStartTime);
    if (!$requestStart instanceof DateTime) {
        return 0;
    }

    $requestEnd = clone $requestStart;
    $requestEnd->modify('+' . max(1, (int) $durationMinutes) . ' minutes');

    try {
        $dateSql = $dateEndCol !== null
            ? "COALESCE(NULLIF({$dateEndCol}, ''), {$dateStartCol}) >= :request_start_date AND {$dateStartCol} <= :request_end_date"
            : "{$dateStartCol} = :request_start_date";

        $selectDuration = $durationCol !== null ? "{$durationCol} AS duration_value" : "30 AS duration_value";

        $stmt = $pdo->prepare("
            SELECT {$dateStartCol} AS service_date_value,
                   {$timeCol} AS service_time_value,
                   {$selectDuration},
                   {$statusCol} AS status_value
            FROM {$table}
            WHERE {$dateSql}
        ");

        $params = array(
            ':request_start_date' => $requestStartDate,
            ':request_end_date' => $requestEndDate,
        );

        if (!$stmt->execute($params)) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        foreach ($rows as $row) {
            $status = normalizedStatusCountsForCapacity(isset($row['status_value']) ? $row['status_value'] : '');
            if (!in_array($status, capacityBlockingStatuses(), true)) {
                continue;
            }

            $rowDate = trim((string) (isset($row['service_date_value']) ? $row['service_date_value'] : ''));
            $rowTime = normalizeTimeForSql(isset($row['service_time_value']) ? $row['service_time_value'] : '');
            $rowDuration = max(1, (int) (isset($row['duration_value']) ? $row['duration_value'] : 30));

            $rowStart = combineDateAndTime($rowDate, $rowTime);
            if (!$rowStart instanceof DateTime) {
                continue;
            }

            $rowEnd = clone $rowStart;
            $rowEnd->modify('+' . $rowDuration . ' minutes');

            if ($rowStart < $requestEnd && $rowEnd > $requestStart) {
                $count++;
            }
        }

        return $count;
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getBookingCapacitySnapshot(PDO $pdo, $serviceDate, $endDate, $serviceTime, $durationMinutes)
{
    $capacity = getAvailableWalkerCapacity($pdo, $serviceDate, $serviceTime, $durationMinutes);
    $memberOverlapCount = countOverlappingMemberBookings($pdo, $serviceDate, $endDate, $serviceTime, $durationMinutes);
    $nonMemberOverlapCount = countOverlappingNonMemberBookings($pdo, $serviceDate, $endDate, $serviceTime, $durationMinutes);
    $bookedCount = $memberOverlapCount + $nonMemberOverlapCount;

    return array(
        'capacity' => $capacity,
        'member_overlap_count' => $memberOverlapCount,
        'non_member_overlap_count' => $nonMemberOverlapCount,
        'booked_count' => $bookedCount,
        'remaining_capacity' => max(0, $capacity - $bookedCount),
        'is_available' => ($capacity > 0 && $bookedCount < $capacity),
        'used_fallback_capacity' => !walkerAvailabilityHasAnyRows($pdo),
    );
}

function insertBooking(PDO $pdo, array $payload)
{
    $table = bookingTable($pdo);
    if ($table === null) {
        return array('ok' => false, 'message' => 'No supported bookings table was found.', 'booking_id' => 0);
    }

    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return array('ok' => false, 'message' => 'Bookings table columns could not be loaded.', 'booking_id' => 0);
    }

    $data = array();
    $userId = (int) $payload['user_id'];
    $petId = (int) $payload['pet_id'];
    $petName = (string) $payload['pet_name'];
    $clientName = (string) $payload['client_name'];
    $serviceType = (string) $payload['service_type'];
    $serviceDate = (string) $payload['service_date'];
    $endDate = (string) $payload['end_date'];
    $serviceTime = (string) $payload['service_time'];
    $duration = (int) $payload['duration_minutes'];
    $notes = (string) $payload['notes'];
    $price = (float) $payload['price'];
    $referralCode = (string) $payload['referral_code'];
    $pricingType = isset($payload['pricing_type']) ? (string) $payload['pricing_type'] : 'member';
    $discountLabel = isset($payload['discount_label']) ? (string) $payload['discount_label'] : 'standard_member';
    $quantity = isset($payload['quantity']) ? (int) $payload['quantity'] : 1;
    $unitPrice = isset($payload['unit_price']) ? (float) $payload['unit_price'] : $price;
    $bookingMeta = isset($payload['booking_meta']) && is_array($payload['booking_meta']) ? $payload['booking_meta'] : array();

    $capacitySnapshot = getBookingCapacitySnapshot(
        $pdo,
        $serviceDate,
        $endDate,
        $serviceTime,
        $duration
    );

    if (!$capacitySnapshot['is_available']) {
        return array(
            'ok' => false,
            'message' => 'That time slot is no longer available. Please choose another time.',
            'booking_id' => 0,
        );
    }

    $bookingMeta['capacity_total'] = (int) $capacitySnapshot['capacity'];
    $bookingMeta['capacity_booked'] = (int) $capacitySnapshot['booked_count'];
    $bookingMeta['capacity_remaining_after_booking'] = max(0, (int) $capacitySnapshot['remaining_capacity'] - 1);
    $bookingMeta['capacity_member_overlap_count'] = (int) $capacitySnapshot['member_overlap_count'];
    $bookingMeta['capacity_non_member_overlap_count'] = (int) $capacitySnapshot['non_member_overlap_count'];
    $bookingMeta['capacity_used_fallback_walkers'] = $capacitySnapshot['used_fallback_capacity'] ? 1 : 0;

    $metaJson = !empty($bookingMeta) ? serializeBookingMeta($bookingMeta) : '';

    $fullNotes = $notes;
    if ($metaJson !== '') {
        $metaBlock = "Booking details:\n" . $metaJson;
        $fullNotes = trim($notes) !== '' ? ($notes . "\n\n" . $metaBlock) : $metaBlock;
    }

    $mapping = array(
        'user_id' => $userId,
        'member_id' => $userId,
        'client_id' => $userId,
        'owner_id' => $userId,
        'owner_user_id' => $userId,
        'client_user_id' => $userId,

        'pet_id' => $petId > 0 ? $petId : null,
        'dog_id' => $petId > 0 ? $petId : null,

        'pet_name' => $petName,
        'dog_name' => $petName,

        'client_name' => $clientName,
        'owner_name' => $clientName,
        'member_name' => $clientName,
        'customer_name' => $clientName,
        'full_name' => $clientName,
        'name' => $clientName,

        'service_type' => $serviceType,
        'type' => $serviceType,
        'booking_type' => $serviceType,
        'category' => $serviceType,
        'service' => $serviceType,

        'service_date' => $serviceDate,
        'booking_date' => $serviceDate,
        'walk_date' => $serviceDate,
        'date' => $serviceDate,
        'scheduled_date' => $serviceDate,
        'start_date' => $serviceDate,
        'check_in_date' => $serviceDate,

        'end_date' => $endDate !== '' ? $endDate : null,
        'check_out_date' => $endDate !== '' ? $endDate : null,

        'service_time' => $serviceTime,
        'booking_time' => $serviceTime,
        'walk_time' => $serviceTime,
        'time' => $serviceTime,
        'scheduled_time' => $serviceTime,
        'start_time' => $serviceTime,

        'duration_minutes' => $duration,
        'duration' => $duration,
        'minutes' => $duration,

        'notes' => $fullNotes,
        'special_instructions' => $fullNotes,
        'instructions' => $fullNotes,
        'care_notes' => $fullNotes,
        'client_notes' => $fullNotes,

        'price' => $price,
        'total_price' => $price,
        'amount' => $price,
        'unit_price' => $unitPrice,

        'status' => 'pending',
        'booking_status' => 'pending',
        'service_status' => 'pending',
        'walk_status' => 'pending',

        'pricing_type' => $pricingType,
        'label' => $discountLabel,
        'discount_label' => $discountLabel,
        'quantity' => $quantity,

        'referral_code' => $referralCode !== '' ? $referralCode : null,
        'ref_code' => $referralCode !== '' ? $referralCode : null,

        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'status_updated_at' => date('Y-m-d H:i:s'),
        'status_updated_by' => 'client',
    );

    foreach ($mapping as $candidate => $value) {
        if (in_array($candidate, $columns, true)) {
            $data[$candidate] = $value;
        }
    }

    if (empty($data)) {
        return array('ok' => false, 'message' => 'No compatible booking columns were found.', 'booking_id' => 0);
    }

    try {
        $pdo->beginTransaction();

        $recheckSnapshot = getBookingCapacitySnapshot(
            $pdo,
            $serviceDate,
            $endDate,
            $serviceTime,
            $duration
        );

        if (!$recheckSnapshot['is_available']) {
            $pdo->rollBack();
            return array(
                'ok' => false,
                'message' => 'That time slot was just taken. Please choose another time.',
                'booking_id' => 0,
            );
        }

        $fields = array_keys($data);
        $placeholders = array();
        $params = array();

        foreach ($fields as $field) {
            $placeholders[] = ':' . $field;
            $params[':' . $field] = $data[$field];
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);

        if (!safeExecute($stmt, $params)) {
            $pdo->rollBack();
            return array('ok' => false, 'message' => 'The booking could not be saved.', 'booking_id' => 0);
        }

        $bookingId = (int) $pdo->lastInsertId();
        if ($bookingId <= 0) {
            $idCol = firstExistingColumn($pdo, $table, array('id', 'booking_id', 'walk_id'));
            if ($idCol !== null) {
                $lookupStmt = $pdo->prepare("SELECT {$idCol} FROM {$table} ORDER BY {$idCol} DESC LIMIT 1");
                if (safeExecute($lookupStmt)) {
                    $bookingId = (int) $lookupStmt->fetchColumn();
                }
            }
        }

        $pdo->commit();

        return array('ok' => true, 'message' => 'Booking created successfully.', 'booking_id' => $bookingId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array('ok' => false, 'message' => 'The booking could not be saved.', 'booking_id' => 0);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array('ok' => false, 'message' => 'The booking could not be saved.', 'booking_id' => 0);
    }
}