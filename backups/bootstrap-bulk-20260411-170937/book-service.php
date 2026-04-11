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

function normalizeEntitlementTypeLocal($value)
{
    $value = strtolower(trim((string) $value));
    $value = str_replace(array('-', ' '), '_', $value);

    if ($value === 'dropin') {
        return 'drop_in';
    }

    if ($value === 'boarding') {
        return 'boarding_night';
    }

    return $value;
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
        'drop_in' => dd_get_drop_in_config(true),
        'daycare' => dd_get_daycare_config(true),
        'sitting' => dd_get_sitting_config(true),
    );
}

function calculateMemberBookingPricing(array $input)
{
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
        return dd_get_service_pricing('daycare', true, array(
            'provide_food' => $daycareProvideFood,
            'extra_walks' => $daycareExtraWalks,
        ));
    }

    if ($serviceType === 'boarding') {
        $nights = dd_calculate_boarding_nights($startDate, $endDate);

        return dd_get_service_pricing('boarding', true, array(
            'dog_size' => $petSize,
            'quantity' => $nights,
        ));
    }

    if ($serviceType === 'drop-in') {
        return dd_get_service_pricing('drop_in', true, array(
            'quantity' => $dropInHours,
            'add_walk' => $dropInAddWalk,
        ));
    }

    if ($serviceType === 'sitting') {
        return dd_get_service_pricing('sitting', true, array(
            'extra_walks' => $sittingExtraWalks,
        ));
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

    return array('ok' => true, 'message' => 'Booking created successfully.', 'booking_id' => $bookingId);
}

/* =========================
   MEMBERSHIP CREDIT HELPERS
   ========================= */

function dd_get_latest_membership_for_user(PDO $pdo, int $userId): array
{
    $result = array(
        'membership_id' => 0,
        'plan_id' => 0,
    );

    if ($userId <= 0 || !hasTable($pdo, 'member_memberships')) {
        return $result;
    }

    $memberIdCol = firstExistingColumn($pdo, 'member_memberships', array('member_id', 'user_id', 'client_id'));
    $membershipIdCol = firstExistingColumn($pdo, 'member_memberships', array('id'));
    $planIdCol = firstExistingColumn($pdo, 'member_memberships', array('plan_id'));
    $orderCol = firstExistingColumn($pdo, 'member_memberships', array('created_at', 'updated_at', 'id'));

    if ($memberIdCol === null || $membershipIdCol === null) {
        return $result;
    }

    if ($orderCol === null) {
        $orderCol = $membershipIdCol;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM member_memberships
        WHERE {$memberIdCol} = :member_id
        ORDER BY {$orderCol} DESC, rowid DESC
        LIMIT 1
    ");

    if (!safeExecute($stmt, array(':member_id' => $userId))) {
        return $result;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $result;
    }

    $result['membership_id'] = (int) ($row[$membershipIdCol] ?? 0);
    if ($planIdCol !== null) {
        $result['plan_id'] = (int) ($row[$planIdCol] ?? 0);
    }

    return $result;
}

function dd_get_credit_service_and_units(string $serviceType, int $quantity): array
{
    $serviceType = normalizeServiceTypeLocal($serviceType);
    $quantity = max(1, $quantity);

    if ($serviceType === 'walk') {
        return array('service_type' => 'walk', 'units' => 1);
    }

    if ($serviceType === 'daycare') {
        return array('service_type' => 'daycare', 'units' => 1);
    }

    if ($serviceType === 'boarding') {
        return array('service_type' => 'boarding_night', 'units' => $quantity);
    }

    if ($serviceType === 'drop-in') {
        return array('service_type' => 'drop_in', 'units' => 1);
    }

    return array('service_type' => '', 'units' => 0);
}

function dd_get_membership_credit_balance(PDO $pdo, int $membershipId, string $serviceType): int
{
    if ($membershipId <= 0 || $serviceType === '' || !hasTable($pdo, 'membership_entitlements')) {
        return 0;
    }

    $membershipCol = firstExistingColumn($pdo, 'membership_entitlements', array('membership_id'));
    $serviceCol = firstExistingColumn($pdo, 'membership_entitlements', array('entitlement_type', 'service_type', 'type'));

    $remainingCol = firstExistingColumn($pdo, 'membership_entitlements', array('remaining_units', 'units_remaining', 'balance'));
    $totalCol = firstExistingColumn($pdo, 'membership_entitlements', array('total'));
    $usedCol = firstExistingColumn($pdo, 'membership_entitlements', array('used'));

    if ($membershipCol === null || $serviceCol === null) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM membership_entitlements
        WHERE {$membershipCol} = :membership_id
          AND {$serviceCol} = :service_type
        LIMIT 1
    ");

    if (!safeExecute($stmt, array(
        ':membership_id' => $membershipId,
        ':service_type' => $serviceType,
    ))) {
        return 0;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }

    if ($remainingCol !== null && isset($row[$remainingCol]) && $row[$remainingCol] !== '') {
        return (int) $row[$remainingCol];
    }

    if ($totalCol !== null && $usedCol !== null) {
        $total = (int) ($row[$totalCol] ?? 0);
        $used = (int) ($row[$usedCol] ?? 0);
        return max(0, $total - $used);
    }

    return 0;
}

function dd_has_required_membership_credits(PDO $pdo, int $userId, string $serviceType, int $unitsNeeded): array
{
    $membership = dd_get_latest_membership_for_user($pdo, $userId);
    $membershipId = (int) $membership['membership_id'];

    if ($membershipId <= 0) {
        return array(
            'membership_id' => 0,
            'ok' => false,
            'remaining' => 0,
        );
    }

    $remaining = dd_get_membership_credit_balance($pdo, $membershipId, $serviceType);

    return array(
        'membership_id' => $membershipId,
        'ok' => $remaining >= $unitsNeeded,
        'remaining' => $remaining,
    );
}

function dd_insert_membership_transaction(PDO $pdo, array $data): bool
{
    if (!hasTable($pdo, 'membership_transactions')) {
        return false;
    }

    $columns = getTableColumns($pdo, 'membership_transactions');
    if (empty($columns)) {
        return false;
    }

    $row = array();

    if (in_array('membership_id', $columns, true)) {
        $row['membership_id'] = (int) $data['membership_id'];
    }

    if (in_array('service_type', $columns, true)) {
        $row['service_type'] = (string) $data['service_type'];
    }

    if (in_array('direction', $columns, true)) {
        $row['direction'] = (string) $data['direction'];
    }

    if (in_array('transaction_type', $columns, true)) {
        $row['transaction_type'] = (string) $data['direction'];
    }

    if (in_array('units', $columns, true)) {
        $row['units'] = (int) $data['units'];
    }

    if (in_array('amount', $columns, true)) {
        $row['amount'] = (int) $data['units'];
    }

    if (in_array('reason', $columns, true)) {
        $row['reason'] = (string) $data['reason'];
    }

    if (in_array('note', $columns, true)) {
        $row['note'] = (string) $data['reason'];
    }

    if (in_array('created_at', $columns, true)) {
        $row['created_at'] = date('Y-m-d H:i:s');
    }

    if (in_array('booking_id', $columns, true)) {
        $row['booking_id'] = (int) $data['booking_id'];
    }

    if (in_array('external_source', $columns, true)) {
        $row['external_source'] = 'booking';
    }

    if (in_array('external_id', $columns, true)) {
        $row['external_id'] = (string) $data['external_id'];
    }

    if (empty($row)) {
        return false;
    }

    $fields = array_keys($row);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $row[$field];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO membership_transactions (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    return safeExecute($stmt, $params);
}

function dd_deduct_membership_credits(PDO $pdo, int $membershipId, string $serviceType, int $units, int $bookingId): array
{
    if ($membershipId <= 0 || $serviceType === '' || $units <= 0) {
        return array('ok' => true, 'message' => '');
    }

    if (!hasTable($pdo, 'membership_entitlements')) {
        return array('ok' => false, 'message' => 'Membership credits table was not found.');
    }

    $membershipCol = firstExistingColumn($pdo, 'membership_entitlements', array('membership_id'));
    $serviceCol = firstExistingColumn($pdo, 'membership_entitlements', array('entitlement_type', 'service_type', 'type'));

    $remainingCol = firstExistingColumn($pdo, 'membership_entitlements', array('remaining_units', 'units_remaining', 'balance'));
    $totalCol = firstExistingColumn($pdo, 'membership_entitlements', array('total'));
    $usedCol = firstExistingColumn($pdo, 'membership_entitlements', array('used'));

    if ($membershipCol === null || $serviceCol === null) {
        return array('ok' => false, 'message' => 'Membership credit columns were not found.');
    }

    $currentBalance = dd_get_membership_credit_balance($pdo, $membershipId, $serviceType);
    if ($currentBalance < $units) {
        return array('ok' => false, 'message' => 'Not enough credits remain for this booking.');
    }

    if ($remainingCol !== null) {
        $stmt = $pdo->prepare("
            UPDATE membership_entitlements
            SET {$remainingCol} = {$remainingCol} - :units
            WHERE {$membershipCol} = :membership_id
              AND {$serviceCol} = :service_type
              AND {$remainingCol} >= :units
        ");

        if (!safeExecute($stmt, array(
            ':units' => $units,
            ':membership_id' => $membershipId,
            ':service_type' => $serviceType,
        ))) {
            return array('ok' => false, 'message' => 'Could not update membership credits.');
        }

        if ($stmt->rowCount() < 1) {
            return array('ok' => false, 'message' => 'Could not reserve membership credits.');
        }
    } elseif ($usedCol !== null && $totalCol !== null) {
        $stmt = $pdo->prepare("
            UPDATE membership_entitlements
            SET {$usedCol} = {$usedCol} + :units
            WHERE {$membershipCol} = :membership_id
              AND {$serviceCol} = :service_type
        ");

        if (!safeExecute($stmt, array(
            ':units' => $units,
            ':membership_id' => $membershipId,
            ':service_type' => $serviceType,
        ))) {
            return array('ok' => false, 'message' => 'Could not update usage.');
        }

        if ($stmt->rowCount() < 1) {
            return array('ok' => false, 'message' => 'Could not reserve membership credits.');
        }
    } else {
        return array('ok' => false, 'message' => 'Membership credit columns were not found.');
    }

    dd_insert_membership_transaction($pdo, array(
        'membership_id' => $membershipId,
        'service_type' => $serviceType,
        'direction' => 'debit',
        'units' => $units,
        'reason' => 'booking_usage',
        'booking_id' => $bookingId,
        'external_id' => 'booking_' . $bookingId . '_' . $serviceType,
    ));

    return array('ok' => true, 'message' => '');
}

function dd_money_round($amount)
{
    return round((float) $amount, 2);
}

function dd_get_service_payment_redirect_url()
{
    if (defined('SERVICE_PAYMENT_PORTAL_URL') && is_string(SERVICE_PAYMENT_PORTAL_URL) && trim(SERVICE_PAYMENT_PORTAL_URL) !== '') {
        return trim(SERVICE_PAYMENT_PORTAL_URL);
    }

    $envKeys = array(
        'SERVICE_PAYMENT_PORTAL_URL',
        'STRIPE_SERVICE_PAYMENT_URL',
        'STRIPE_PAYMENT_LINK_URL',
    );

    foreach ($envKeys as $envKey) {
        $envValue = getenv($envKey);
        if (is_string($envValue) && trim($envValue) !== '') {
            return trim($envValue);
        }
    }

    $localCandidates = array(
        'service-checkout.php',
        'payment-portal.php',
        'checkout.php',
        'stripe-checkout.php',
    );

    foreach ($localCandidates as $candidate) {
        if (is_file(__DIR__ . '/' . $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function dd_store_pending_service_checkout(array $payload)
{
    $_SESSION['pending_service_checkout'] = $payload;
}

function dd_get_credit_coverage_breakdown(PDO $pdo, int $userId, array $pricingInput, array $pricingResult, array $memberConfig): array
{
    $serviceType = normalizeServiceTypeLocal(isset($pricingInput['service_type']) ? $pricingInput['service_type'] : '');
    $totalPrice = dd_money_round(isset($pricingResult['total_price']) ? $pricingResult['total_price'] : 0);
    $quantity = max(1, (int) (isset($pricingResult['quantity']) ? $pricingResult['quantity'] : 1));
    $durationMinutes = (int) (isset($pricingInput['duration_minutes']) ? $pricingInput['duration_minutes'] : 0);
    $petSize = strtolower(trim((string) (isset($pricingInput['pet_size']) ? $pricingInput['pet_size'] : '')));
    $dropInHours = max(1, (int) (isset($pricingInput['drop_in_hours']) ? $pricingInput['drop_in_hours'] : 1));
    $dropInAddWalk = !empty($pricingInput['drop_in_add_walk']);

    $creditUsage = dd_get_credit_service_and_units($serviceType, $quantity);
    $creditServiceType = (string) $creditUsage['service_type'];
    $unitsNeeded = (int) $creditUsage['units'];

    $result = array(
        'has_credit_type' => ($creditServiceType !== '' && $unitsNeeded > 0),
        'membership_id' => 0,
        'credit_service_type' => $creditServiceType,
        'units_needed' => $unitsNeeded,
        'available_units' => 0,
        'credits_to_use' => 0,
        'covered_amount' => 0.00,
        'charge_amount' => $totalPrice,
        'requires_payment_redirect' => false,
        'reason_code' => '',
        'reason_message' => '',
    );

    if ($creditServiceType === '' || $unitsNeeded <= 0) {
        return $result;
    }

    $membership = dd_has_required_membership_credits($pdo, $userId, $creditServiceType, $unitsNeeded);
    $membershipId = (int) $membership['membership_id'];
    $availableUnits = 0;

    if ($membershipId > 0) {
        $availableUnits = dd_get_membership_credit_balance($pdo, $membershipId, $creditServiceType);
    }

    $result['membership_id'] = $membershipId;
    $result['available_units'] = $availableUnits;

    if ($serviceType === 'walk') {
        if ($availableUnits > 0) {
            $result['credits_to_use'] = 1;

            if ($durationMinutes > 30) {
                $includedPricing = dd_get_service_pricing('walk', true, array(
                    'duration_minutes' => 30,
                ));
                $coveredAmount = dd_money_round(isset($includedPricing['total_price']) ? $includedPricing['total_price'] : 0);
                $result['covered_amount'] = min($coveredAmount, $totalPrice);
                $result['charge_amount'] = dd_money_round(max(0, $totalPrice - $result['covered_amount']));
                if ($result['charge_amount'] > 0) {
                    $result['requires_payment_redirect'] = true;
                    $result['reason_code'] = 'walk_duration_exceeds_credit';
                    $result['reason_message'] = 'Your walk credit covers up to 30 minutes. The extra time will continue to checkout.';
                }
            } else {
                $result['covered_amount'] = $totalPrice;
                $result['charge_amount'] = 0.00;
            }
        } else {
            $result['requires_payment_redirect'] = true;
            $result['reason_code'] = 'walk_credit_unavailable';
            $result['reason_message'] = 'You do not have a walk credit remaining, so this booking will continue to checkout.';
        }

        return $result;
    }

    if ($serviceType === 'daycare') {
        if ($availableUnits > 0) {
            $result['credits_to_use'] = 1;

            $includedPricing = dd_get_service_pricing('daycare', true, array(
                'provide_food' => false,
                'extra_walks' => 0,
            ));
            $coveredAmount = dd_money_round(isset($includedPricing['total_price']) ? $includedPricing['total_price'] : 0);
            $result['covered_amount'] = min($coveredAmount, $totalPrice);
            $result['charge_amount'] = dd_money_round(max(0, $totalPrice - $result['covered_amount']));

            if ($result['charge_amount'] > 0) {
                $result['requires_payment_redirect'] = true;
                $result['reason_code'] = 'daycare_addons_exceed_credit';
                $result['reason_message'] = 'Your daycare credit covers the base daycare session. Any add-ons will continue to checkout.';
            }
        } else {
            $result['requires_payment_redirect'] = true;
            $result['reason_code'] = 'daycare_credit_unavailable';
            $result['reason_message'] = 'You do not have a daycare credit remaining, so this booking will continue to checkout.';
        }

        return $result;
    }

    if ($serviceType === 'drop-in') {
        if ($availableUnits > 0) {
            $result['credits_to_use'] = 1;

            $includedPricing = dd_get_service_pricing('drop_in', true, array(
                'quantity' => 1,
                'add_walk' => false,
            ));
            $coveredAmount = dd_money_round(isset($includedPricing['total_price']) ? $includedPricing['total_price'] : 0);
            $result['covered_amount'] = min($coveredAmount, $totalPrice);
            $result['charge_amount'] = dd_money_round(max(0, $totalPrice - $result['covered_amount']));

            if ($dropInHours > 1 || $dropInAddWalk || $result['charge_amount'] > 0) {
                $result['requires_payment_redirect'] = true;
                $result['reason_code'] = 'dropin_exceeds_credit';
                $result['reason_message'] = 'Your drop-in credit covers a standard 1-hour drop-in. Any extra time or add-ons will continue to checkout.';
            }
        } else {
            $result['requires_payment_redirect'] = true;
            $result['reason_code'] = 'dropin_credit_unavailable';
            $result['reason_message'] = 'You do not have a drop-in credit remaining, so this booking will continue to checkout.';
        }

        return $result;
    }

    if ($serviceType === 'boarding') {
        $unitPrice = dd_money_round(isset($pricingResult['unit_price']) ? $pricingResult['unit_price'] : 0);

        if ($unitPrice <= 0 && $quantity > 0) {
            $unitPrice = dd_money_round($totalPrice / $quantity);
        }

        $creditsToUse = min($availableUnits, $unitsNeeded);
        $result['credits_to_use'] = $creditsToUse;

        if ($creditsToUse > 0) {
            $coveredAmount = dd_money_round($unitPrice * $creditsToUse);
            $result['covered_amount'] = min($coveredAmount, $totalPrice);
            $result['charge_amount'] = dd_money_round(max(0, $totalPrice - $result['covered_amount']));
        }

        if ($creditsToUse < $unitsNeeded) {
            $result['requires_payment_redirect'] = true;
            $result['reason_code'] = 'boarding_nights_exceed_credit';
            $result['reason_message'] = 'Your boarding credits will be applied first, and any extra nights will continue to checkout.';
        }

        return $result;
    }

    return $result;
}

if (!isLoggedInMember()) {
    redirectTo('login.php');
}

$userId = currentUserId();
$clientName = getUserDisplayName($pdo, $userId);
$pets = getPetsForUser($pdo, $userId);
$memberConfig = memberServiceConfig();

$formData = array(
    'service_type' => normalizeServiceTypeLocal(isset($_GET['service']) ? $_GET['service'] : 'walk'),
    'pet_id' => '',
    'service_date' => '',
    'end_date' => '',
    'service_time' => '',
    'duration_minutes' => '30',
    'drop_in_hours' => '1',
    'drop_in_add_walk' => '0',
    'daycare_provide_food' => '0',
    'daycare_extra_walks' => '0',
    'sitting_extra_walks' => '0',
    'notes' => '',
    'referral_code' => normalizeReferralCodeLocal(isset($_GET['ref']) ? $_GET['ref'] : ''),
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['service_type'] = normalizeServiceTypeLocal(isset($_POST['service_type']) ? $_POST['service_type'] : 'walk');
    $formData['pet_id'] = trim((string) (isset($_POST['pet_id']) ? $_POST['pet_id'] : ''));
    $formData['service_date'] = trim((string) (isset($_POST['service_date']) ? $_POST['service_date'] : ''));
    $formData['end_date'] = trim((string) (isset($_POST['end_date']) ? $_POST['end_date'] : ''));
    $formData['service_time'] = trim((string) (isset($_POST['service_time']) ? $_POST['service_time'] : ''));
    $formData['duration_minutes'] = trim((string) (isset($_POST['duration_minutes']) ? $_POST['duration_minutes'] : '30'));
    $formData['drop_in_hours'] = trim((string) (isset($_POST['drop_in_hours']) ? $_POST['drop_in_hours'] : '1'));
    $formData['drop_in_add_walk'] = isset($_POST['drop_in_add_walk']) ? '1' : '0';
    $formData['daycare_provide_food'] = isset($_POST['daycare_provide_food']) ? '1' : '0';
    $formData['daycare_extra_walks'] = trim((string) (isset($_POST['daycare_extra_walks']) ? $_POST['daycare_extra_walks'] : '0'));
    $formData['sitting_extra_walks'] = trim((string) (isset($_POST['sitting_extra_walks']) ? $_POST['sitting_extra_walks'] : '0'));
    $formData['notes'] = trim((string) (isset($_POST['notes']) ? $_POST['notes'] : ''));
    $formData['referral_code'] = normalizeReferralCodeLocal(isset($_POST['referral_code']) ? $_POST['referral_code'] : '');

    $petId = (int) $formData['pet_id'];
    $serviceType = $formData['service_type'];
    $serviceDate = $formData['service_date'];
    $endDate = $formData['end_date'];
    $serviceTime = $formData['service_time'];
    $duration = (int) $formData['duration_minutes'];
    $dropInHours = (int) $formData['drop_in_hours'];
    $daycareExtraWalks = max(0, (int) $formData['daycare_extra_walks']);
    $sittingExtraWalks = max(0, (int) $formData['sitting_extra_walks']);
    $notes = $formData['notes'];
    $referralCode = $formData['referral_code'];

    $selectedPet = null;
    foreach ($pets as $pet) {
        if ((int) $pet['pet_id'] === $petId) {
            $selectedPet = $pet;
            break;
        }
    }

    if ($selectedPet === null) {
        $error = 'Please choose a valid pet.';
    } elseif ($serviceDate === '') {
        $error = 'Please choose a service date.';
    } elseif ($serviceType === 'boarding' && $endDate === '') {
        $error = 'Please choose a check-out date for boarding.';
    } elseif (!in_array($serviceType, array('boarding'), true) && $serviceTime === '') {
        $error = 'Please choose a service time.';
    } elseif ($serviceType === 'walk' && $duration <= 0) {
        $error = 'Please choose a valid walk duration.';
    } elseif ($serviceType === 'drop-in' && !in_array($dropInHours, array(1, 2), true)) {
        $error = 'Drop-ins can only be booked for 1 or 2 hours.';
    } else {
        try {
            $pricingInput = array(
                'service_type' => $serviceType,
                'pet_size' => isset($selectedPet['size']) ? $selectedPet['size'] : '',
                'duration_minutes' => $duration,
                'start_date' => $serviceDate,
                'end_date' => $endDate !== '' ? $endDate : $serviceDate,
                'drop_in_hours' => $dropInHours,
                'drop_in_add_walk' => ($formData['drop_in_add_walk'] === '1'),
                'daycare_provide_food' => ($formData['daycare_provide_food'] === '1'),
                'daycare_extra_walks' => $daycareExtraWalks,
                'sitting_extra_walks' => $sittingExtraWalks,
            );

            $pricingResult = calculateMemberBookingPricing($pricingInput);
            $fullServicePrice = dd_money_round((float) $pricingResult['total_price']);

            $bookingMeta = array(
                'service_type' => $serviceType,
                'member_pricing' => 1,
            );

            if ($serviceType === 'drop-in') {
                $bookingMeta['drop_in_hours'] = $dropInHours;
                $bookingMeta['drop_in_add_walk'] = ($formData['drop_in_add_walk'] === '1');
                $bookingMeta['drop_in_walk_duration_minutes'] = $memberConfig['drop_in']['walk_duration_minutes'];
            } elseif ($serviceType === 'daycare') {
                $bookingMeta['daycare_hours'] = $memberConfig['daycare']['hours'];
                $bookingMeta['daycare_provide_food'] = ($formData['daycare_provide_food'] === '1');
                $bookingMeta['daycare_included_walks'] = $memberConfig['daycare']['included_walks'];
                $bookingMeta['daycare_included_walk_duration_minutes'] = $memberConfig['daycare']['included_walk_duration_minutes'];
                $bookingMeta['daycare_extra_walks'] = $daycareExtraWalks;
            } elseif ($serviceType === 'sitting') {
                $bookingMeta['sitting_hours'] = $memberConfig['sitting']['hours'];
                $bookingMeta['sitting_included_walks'] = $memberConfig['sitting']['included_walks'];
                $bookingMeta['sitting_included_walk_duration_minutes'] = $memberConfig['sitting']['included_walk_duration_minutes'];
                $bookingMeta['sitting_extra_walks'] = $sittingExtraWalks;
            }

            $coverage = dd_get_credit_coverage_breakdown(
                $pdo,
                $userId,
                $pricingInput,
                $pricingResult,
                $memberConfig
            );

            $priceToChargeNow = dd_money_round($coverage['charge_amount']);

            if ($coverage['has_credit_type']) {
                $bookingMeta['membership_credit_service_type'] = $coverage['credit_service_type'];
                $bookingMeta['membership_credit_units_needed'] = (int) $coverage['units_needed'];
                $bookingMeta['membership_credit_units_available'] = (int) $coverage['available_units'];
                $bookingMeta['membership_credit_units_to_use'] = (int) $coverage['credits_to_use'];
                $bookingMeta['membership_credit_covered_amount'] = dd_money_round($coverage['covered_amount']);
                $bookingMeta['membership_charge_amount'] = $priceToChargeNow;
            }

            if ($coverage['requires_payment_redirect'] && $priceToChargeNow > 0) {
                $paymentUrl = dd_get_service_payment_redirect_url();

                if ($paymentUrl === '') {
                    throw new RuntimeException('A payment portal or Stripe checkout URL has not been configured yet.');
                }

                dd_store_pending_service_checkout(array(
                    'type' => 'service_booking',
                    'user_id' => $userId,
                    'membership_id' => (int) $coverage['membership_id'],
                    'credits_to_use' => (int) $coverage['credits_to_use'],
                    'credit_service_type' => (string) $coverage['credit_service_type'],
                    'covered_amount' => dd_money_round($coverage['covered_amount']),
                    'charge_amount' => $priceToChargeNow,
                    'full_service_price' => $fullServicePrice,
                    'service_type' => $serviceType,
                    'service_label' => serviceLabel($serviceType),
                    'service_date' => $serviceDate,
                    'end_date' => $endDate,
                    'service_time' => $serviceTime,
                    'duration_minutes' => (int) $pricingResult['duration'],
                    'quantity' => (int) $pricingResult['quantity'],
                    'unit_price' => dd_money_round((float) $pricingResult['unit_price']),
                    'pricing_type' => (string) $pricingResult['pricing_type'],
                    'discount_label' => (string) $pricingResult['discount_label'],
                    'client_name' => $clientName,
                    'pet_id' => $petId,
                    'pet_name' => (string) $selectedPet['pet_name'],
                    'pet_size' => isset($selectedPet['size']) ? (string) $selectedPet['size'] : '',
                    'notes' => $notes,
                    'referral_code' => $referralCode,
                    'booking_meta' => $bookingMeta,
                    'reason_code' => (string) $coverage['reason_code'],
                    'reason_message' => (string) $coverage['reason_message'],
                    'created_at' => date('c'),
                    'return_to' => 'book-service.php',
                ));

                $_SESSION['dashboard_flash'] = $coverage['reason_message'];
                redirectTo($paymentUrl);
            }

            $creditUsage = dd_get_credit_service_and_units($serviceType, (int) $pricingResult['quantity']);

            if ($creditUsage['service_type'] !== '' && $creditUsage['units'] > 0 && !$coverage['requires_payment_redirect']) {
                $creditCheck = dd_has_required_membership_credits(
                    $pdo,
                    $userId,
                    $creditUsage['service_type'],
                    (int) $coverage['credits_to_use']
                );

                if ((int) $coverage['credits_to_use'] > 0 && !$creditCheck['ok']) {
                    if ($creditUsage['service_type'] === 'walk') {
                        throw new RuntimeException('You do not have enough walk credits remaining for this booking.');
                    } elseif ($creditUsage['service_type'] === 'daycare') {
                        throw new RuntimeException('You do not have enough daycare credits remaining for this booking.');
                    } elseif ($creditUsage['service_type'] === 'boarding_night') {
                        throw new RuntimeException('You do not have enough boarding nights remaining for this booking.');
                    } elseif ($creditUsage['service_type'] === 'drop_in') {
                        throw new RuntimeException('You do not have enough drop-in credits remaining for this booking.');
                    } else {
                        throw new RuntimeException('You do not have enough membership credits remaining for this booking.');
                    }
                }
            }

            $pdo->beginTransaction();

            try {
                $insert = insertBooking($pdo, array(
                    'user_id' => $userId,
                    'pet_id' => $petId,
                    'pet_name' => (string) $selectedPet['pet_name'],
                    'client_name' => $clientName,
                    'service_type' => $serviceType,
                    'service_date' => $serviceDate,
                    'end_date' => $endDate,
                    'service_time' => $serviceTime,
                    'duration_minutes' => (int) $pricingResult['duration'],
                    'notes' => $notes,
                    'price' => $priceToChargeNow,
                    'pricing_type' => (string) $pricingResult['pricing_type'],
                    'discount_label' => (string) $pricingResult['discount_label'],
                    'quantity' => (int) $pricingResult['quantity'],
                    'unit_price' => (float) $pricingResult['unit_price'],
                    'referral_code' => $referralCode,
                    'booking_meta' => $bookingMeta,
                ));

                if (!$insert['ok']) {
                    throw new RuntimeException($insert['message']);
                }

                $bookingId = (int) $insert['booking_id'];

                if ($creditUsage['service_type'] !== '' && (int) $coverage['credits_to_use'] > 0 && !$coverage['requires_payment_redirect']) {
                    $membership = dd_get_latest_membership_for_user($pdo, $userId);
                    $deduct = dd_deduct_membership_credits(
                        $pdo,
                        (int) $membership['membership_id'],
                        $creditUsage['service_type'],
                        (int) $coverage['credits_to_use'],
                        $bookingId
                    );

                    if (!$deduct['ok']) {
                        throw new RuntimeException($deduct['message']);
                    }
                }

                if (
                    $bookingId > 0
                    && $referralCode !== ''
                    && function_exists('attachReferralToBooking')
                ) {
                    try {
                        attachReferralToBooking(
                            $pdo,
                            $bookingId,
                            $userId,
                            $referralCode,
                            $serviceType,
                            $priceToChargeNow
                        );
                    } catch (Throwable $e) {
                    } catch (Exception $e) {
                    }
                }

                if ($bookingId > 0) {
                    $message = 'Your ' . serviceLabel($serviceType) . ' booking has been created and is pending confirmation.';
                    if ($creditUsage['service_type'] !== '' && (int) $coverage['credits_to_use'] > 0) {
                        $message .= ' ' . (int) $coverage['credits_to_use'] . ' membership credit' . ((int) $coverage['credits_to_use'] === 1 ? '' : 's') . ' reserved.';
                    }
                    if ($referralCode !== '') {
                        $message .= ' Referral code ' . $referralCode . ' was applied.';
                    }

                    writeNotification(
                        $pdo,
                        $userId,
                        $bookingId,
                        'Booking Created',
                        $message
                    );
                }

                $pdo->commit();

                $_SESSION['dashboard_flash'] = 'Your ' . serviceLabel($serviceType) . ' booking request was submitted successfully.';
                redirectTo('my-bookings.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$previewPrice = 0.00;
$previewLabel = 'Live member pricing updates automatically as you change selections.';
$previewSummaryLines = array();

try {
    $previewPetSize = '';
    if ($formData['pet_id'] !== '') {
        foreach ($pets as $pet) {
            if ((string) $pet['pet_id'] === $formData['pet_id']) {
                $previewPetSize = isset($pet['size']) ? (string) $pet['size'] : '';
                break;
            }
        }
    }

    $previewPricing = calculateMemberBookingPricing(array(
        'service_type' => $formData['service_type'],
        'pet_size' => $previewPetSize,
        'duration_minutes' => (int) $formData['duration_minutes'],
        'start_date' => $formData['service_date'] !== '' ? $formData['service_date'] : date('Y-m-d'),
        'end_date' => $formData['end_date'] !== '' ? $formData['end_date'] : ($formData['service_date'] !== '' ? $formData['service_date'] : date('Y-m-d')),
        'drop_in_hours' => (int) $formData['drop_in_hours'],
        'drop_in_add_walk' => ($formData['drop_in_add_walk'] === '1'),
        'daycare_provide_food' => ($formData['daycare_provide_food'] === '1'),
        'daycare_extra_walks' => (int) $formData['daycare_extra_walks'],
        'sitting_extra_walks' => (int) $formData['sitting_extra_walks'],
    ));

    $previewPrice = (float) $previewPricing['total_price'];

    if ($formData['service_type'] === 'walk') {
        $previewLabel = 'Member walk pricing based on your selected duration.';
        $previewSummaryLines[] = 'Walk length: ' . (int) $formData['duration_minutes'] . ' minutes';
    } elseif ($formData['service_type'] === 'drop-in') {
        $hours = max(1, min(2, (int) $formData['drop_in_hours']));
        $previewLabel = 'Drop-ins are billed hourly and capped at 2 hours.';
        $previewSummaryLines[] = $hours . ' hour drop-in at ' . dd_format_money((float) $memberConfig['drop_in']['hourly_rate']) . ' per hour';
        if ($formData['drop_in_add_walk'] === '1') {
            $previewSummaryLines[] = 'Includes 1 add-on ' . (int) $memberConfig['drop_in']['walk_duration_minutes'] . '-minute walk for ' . dd_format_money((float) $memberConfig['drop_in']['walk_add_on']);
        }
    } elseif ($formData['service_type'] === 'daycare') {
        $previewLabel = '6-hour daycare includes 1 complimentary 30-minute walk.';
        $previewSummaryLines[] = '6-hour daycare session: ' . dd_format_money((float) $memberConfig['daycare']['base_rate']);
        if ($formData['daycare_provide_food'] === '1') {
            $previewSummaryLines[] = 'Food provided by Doggie Dorian’s: +' . dd_format_money((float) $memberConfig['daycare']['food_fee']);
        } else {
            $previewSummaryLines[] = 'Pet parent provides food: no food fee';
        }
        $extraWalks = max(0, (int) $formData['daycare_extra_walks']);
        if ($extraWalks > 0) {
            $previewSummaryLines[] = $extraWalks . ' additional 30-minute walk(s) at ' . dd_format_money((float) $memberConfig['daycare']['additional_walk_rate']) . ' each';
        }
    } elseif ($formData['service_type'] === 'sitting') {
        $previewLabel = 'In-home sitting includes 1 complimentary 30-minute walk.';
        $previewSummaryLines[] = 'Up to ' . (int) $memberConfig['sitting']['hours'] . ' hours in your home';
        $previewSummaryLines[] = 'Base session: ' . dd_format_money((float) $memberConfig['sitting']['base_rate']);
        $extraWalks = max(0, (int) $formData['sitting_extra_walks']);
        if ($extraWalks > 0) {
            $previewSummaryLines[] = $extraWalks . ' additional 30-minute walk(s) at ' . dd_format_money((float) $memberConfig['sitting']['additional_walk_rate']) . ' each';
        }
    } elseif ($formData['service_type'] === 'boarding') {
        $previewLabel = 'Boarding is priced by dog size and number of nights.';
        if ($previewPetSize === '') {
            $previewSummaryLines[] = 'Select a pet to preview the most accurate size-based boarding rate.';
        } else {
            $previewSummaryLines[] = 'Boarding uses member nightly pricing for ' . $previewPetSize . ' dogs.';
        }
        $previewSummaryLines[] = '5+ nights automatically receive the extended-stay member rate.';
    }
} catch (Throwable $e) {
    $previewPrice = 0.00;
}

$referralOwnerName = lookupReferralOwnerName($pdo, $formData['referral_code']);
$pageTitle = 'Book Premium Care';
$pageEyebrow = 'Member Booking';
$pricingMatrix = dd_pricing_matrix();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?> | Doggie Dorian’s</title>
    <meta name="description" content="Book premium pet care services with Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #09090d;
            --panel: rgba(255,255,255,0.06);
            --panel-2: rgba(255,255,255,0.04);
            --stroke: rgba(255,255,255,0.10);
            --text: #f4f1ea;
            --muted: rgba(244,241,234,0.68);
            --gold: #e2c48d;
            --gold-deep: #b9975b;
            --success: #d7f1dd;
            --success-bg: rgba(125,206,141,0.14);
            --success-stroke: rgba(125,206,141,0.26);
            --danger: #ffd5d5;
            --danger-bg: rgba(214,123,123,0.14);
            --danger-stroke: rgba(214,123,123,0.30);
        }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(185,151,91,0.12), transparent 32%),
                radial-gradient(circle at top right, rgba(226,196,141,0.08), transparent 28%),
                var(--bg);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 80px;
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
            font-size: 1.55rem;
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
            background: var(--panel);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            transition: .18s ease;
        }

        .top-link:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.08);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 22px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.30);
            backdrop-filter: blur(8px);
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
            font-size: 2.1rem;
            line-height: 1.06;
        }

        .sub {
            color: var(--muted);
            line-height: 1.6;
        }

        .flash-error {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
            background: var(--danger-bg);
            border: 1px solid var(--danger-stroke);
            color: var(--danger);
        }

        .referral-banner {
            margin: 16px 0 0;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(198,178,139,0.12);
            border: 1px solid rgba(198,178,139,0.25);
            color: #f3e5c7;
            font-weight: 700;
            line-height: 1.55;
        }

        form {
            display: grid;
            gap: 16px;
            margin-top: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .field-shell {
            padding: 14px;
            border-radius: 18px;
            background: var(--panel-2);
            border: 1px solid rgba(255,255,255,0.06);
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
        }

        select, input, textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid var(--stroke);
            background: rgba(0,0,0,0.28);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease;
        }

        select:focus, input:focus, textarea:focus {
            border-color: rgba(226,196,141,0.58);
            box-shadow: 0 0 0 4px rgba(226,196,141,0.10);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .submit-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        button {
            border: none;
            cursor: pointer;
            border-radius: 14px;
            padding: 13px 18px;
            font-weight: 800;
            font-size: .95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold), var(--gold-deep));
            color: #0b0b10;
            box-shadow: 0 12px 30px rgba(185,151,91,0.22);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
        }

        .feature-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .feature {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .feature strong {
            display: block;
            margin-bottom: 6px;
        }

        .price-preview {
            margin-top: 16px;
            padding: 18px;
            border-radius: 20px;
            background: rgba(198,178,139,0.12);
            border: 1px solid rgba(198,178,139,0.25);
        }

        .price-label {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.62);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .price-value {
            font-size: 2.15rem;
            font-weight: 900;
        }

        .muted {
            color: rgba(244,241,234,0.64);
        }

        .live-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--success-bg);
            border: 1px solid var(--success-stroke);
            color: var(--success);
            font-weight: 700;
            font-size: .85rem;
        }

        .helper-note {
            color: rgba(244,241,234,0.60);
            font-size: .85rem;
            line-height: 1.5;
            margin-top: 8px;
        }

        .section-note {
            color: rgba(244,241,234,0.70);
            font-size: .9rem;
            line-height: 1.6;
            margin-top: 4px;
        }

        .service-addon-box {
            display: grid;
            gap: 12px;
            padding: 16px;
            border-radius: 20px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .addon-title {
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #c6b28b;
            font-weight: 800;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(0,0,0,0.20);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            padding: 0;
            border-radius: 6px;
            accent-color: #d7bb82;
            box-shadow: none;
        }

        .checkbox-copy {
            display: grid;
            gap: 4px;
        }

        .checkbox-copy strong {
            font-size: .95rem;
        }

        .checkbox-copy span {
            color: var(--muted);
            font-size: .88rem;
        }

        .summary-list {
            margin: 12px 0 0;
            padding-left: 18px;
            color: rgba(244,241,234,0.80);
            line-height: 1.6;
        }

        .summary-list li + li {
            margin-top: 5px;
        }

        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .86rem;
            font-weight: 700;
            color: rgba(244,241,234,0.84);
        }

        .hidden {
            display: none !important;
        }

        @media (max-width: 980px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.7rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="dashboard.php">Dashboard</a>
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="ambassadors.php">Ambassadors</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="flash-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card">
                <div class="eyebrow"><?php echo h($pageEyebrow); ?></div>
                <h1><?php echo h($pageTitle); ?></h1>
                <div class="sub">
                    Use one member booking page for walks, drop-ins, daycare, in-home sitting, and boarding.
                </div>

                <?php if ($formData['referral_code'] !== ''): ?>
                    <div class="referral-banner">
                        Referral code <strong><?php echo h($formData['referral_code']); ?></strong> is attached to this booking.
                        <?php if ($referralOwnerName !== ''): ?>
                            This code belongs to <strong><?php echo h($referralOwnerName); ?></strong>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="book-service.php<?php echo $formData['service_type'] === 'walk' ? '?service=walk' : ''; ?>" novalidate>
                    <div class="grid">
                        <div class="field-shell">
                            <label for="service_type">Service Type</label>
                            <select name="service_type" id="service_type" required>
                                <option value="walk" <?php echo $formData['service_type'] === 'walk' ? 'selected' : ''; ?>>Walk</option>
                                <option value="boarding" <?php echo $formData['service_type'] === 'boarding' ? 'selected' : ''; ?>>Boarding</option>
                                <option value="daycare" <?php echo $formData['service_type'] === 'daycare' ? 'selected' : ''; ?>>Daycare</option>
                                <option value="sitting" <?php echo $formData['service_type'] === 'sitting' ? 'selected' : ''; ?>>In-Home Sitting</option>
                                <option value="drop-in" <?php echo $formData['service_type'] === 'drop-in' ? 'selected' : ''; ?>>Drop-In</option>
                            </select>
                        </div>

                        <div class="field-shell">
                            <label for="pet_id">Choose Pet</label>
                            <select name="pet_id" id="pet_id" required>
                                <option value="">Select your pet</option>
                                <?php foreach ($pets as $pet): ?>
                                    <option
                                        value="<?php echo (int) $pet['pet_id']; ?>"
                                        data-pet-size="<?php echo h(strtolower(isset($pet['size']) ? $pet['size'] : '')); ?>"
                                        <?php echo (string) $pet['pet_id'] === $formData['pet_id'] ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($pet['pet_name']); ?><?php echo $pet['breed'] !== '' ? ' · ' . h($pet['breed']) : ''; ?><?php echo $pet['size'] !== '' ? ' · ' . h($pet['size']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid">
                        <div class="field-shell">
                            <label for="service_date" id="start-date-label">Service Date</label>
                            <input type="date" id="service_date" name="service_date" value="<?php echo old('service_date', $formData); ?>" required>
                        </div>

                        <div class="field-shell" id="end-date-group">
                            <label for="end_date" id="end-date-label">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo old('end_date', $formData); ?>">
                            <div class="helper-note" id="end-date-helper"></div>
                        </div>
                    </div>

                    <div class="grid">
                        <div class="field-shell" id="time-group">
                            <label for="service_time" id="time-label">Preferred Time</label>
                            <input type="time" id="service_time" name="service_time" value="<?php echo old('service_time', $formData); ?>">
                        </div>

                        <div class="field-shell" id="duration-group">
                            <label for="duration_minutes">Walk Duration</label>
                            <select name="duration_minutes" id="duration_minutes">
                                <option value="15" <?php echo $formData['duration_minutes'] === '15' ? 'selected' : ''; ?>>15 minutes</option>
                                <option value="20" <?php echo $formData['duration_minutes'] === '20' ? 'selected' : ''; ?>>20 minutes</option>
                                <option value="30" <?php echo $formData['duration_minutes'] === '30' ? 'selected' : ''; ?>>30 minutes</option>
                                <option value="45" <?php echo $formData['duration_minutes'] === '45' ? 'selected' : ''; ?>>45 minutes</option>
                                <option value="60" <?php echo $formData['duration_minutes'] === '60' ? 'selected' : ''; ?>>60 minutes</option>
                            </select>
                        </div>
                    </div>

                    <div class="stack" id="dropin-options">
                        <div class="service-addon-box">
                            <div class="addon-title">Drop-In Options</div>
                            <div class="grid">
                                <div class="field-shell">
                                    <label for="drop_in_hours">Drop-In Length</label>
                                    <select name="drop_in_hours" id="drop_in_hours">
                                        <option value="1" <?php echo $formData['drop_in_hours'] === '1' ? 'selected' : ''; ?>>1 hour · <?php echo dd_format_money((float) $memberConfig['drop_in']['hourly_rate']); ?></option>
                                        <option value="2" <?php echo $formData['drop_in_hours'] === '2' ? 'selected' : ''; ?>>2 hours · <?php echo dd_format_money((float) $memberConfig['drop_in']['hourly_rate'] * 2); ?></option>
                                    </select>
                                    <div class="helper-note">Drop-ins are capped at 2 hours. Anything longer should be booked as daycare.</div>
                                </div>

                                <div class="field-shell">
                                    <div class="checkbox-row" style="height: 100%;">
                                        <input type="checkbox" id="drop_in_add_walk" name="drop_in_add_walk" value="1" <?php echo $formData['drop_in_add_walk'] === '1' ? 'checked' : ''; ?>>
                                        <div class="checkbox-copy">
                                            <strong>Add a 30-minute walk</strong>
                                            <span>Member add-on: <?php echo dd_format_money((float) $memberConfig['drop_in']['walk_add_on']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stack" id="daycare-options">
                        <div class="service-addon-box">
                            <div class="addon-title">Daycare Options</div>
                            <div class="section-note">
                                6-hour daycare includes 1 complimentary 30-minute walk.
                            </div>

                            <div class="grid">
                                <div class="field-shell">
                                    <div class="checkbox-row" style="height: 100%;">
                                        <input type="checkbox" id="daycare_provide_food" name="daycare_provide_food" value="1" <?php echo $formData['daycare_provide_food'] === '1' ? 'checked' : ''; ?>>
                                        <div class="checkbox-copy">
                                            <strong>Have us provide food</strong>
                                            <span>Add <?php echo dd_format_money((float) $memberConfig['daycare']['food_fee']); ?> if you want us to provide the meal.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="field-shell">
                                    <label for="daycare_extra_walks">Additional 30-Minute Walks</label>
                                    <select name="daycare_extra_walks" id="daycare_extra_walks">
                                        <?php for ($i = 0; $i <= 4; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $formData['daycare_extra_walks'] === (string) $i ? 'selected' : ''; ?>>
                                                <?php echo $i; ?><?php echo $i === 0 ? ' extra walks' : ' extra walk' . ($i === 1 ? '' : 's'); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <div class="helper-note">Each extra 30-minute walk is <?php echo dd_format_money((float) $memberConfig['daycare']['additional_walk_rate']); ?> for members.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stack" id="sitting-options">
                        <div class="service-addon-box">
                            <div class="addon-title">In-Home Sitting Options</div>
                            <div class="section-note">
                                Up to <?php echo (int) $memberConfig['sitting']['hours']; ?> hours in your home and includes 1 complimentary 30-minute walk.
                            </div>

                            <div class="field-shell">
                                <label for="sitting_extra_walks">Additional 30-Minute Walks</label>
                                <select name="sitting_extra_walks" id="sitting_extra_walks">
                                    <?php for ($i = 0; $i <= 4; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $formData['sitting_extra_walks'] === (string) $i ? 'selected' : ''; ?>>
                                            <?php echo $i; ?><?php echo $i === 0 ? ' extra walks' : ' extra walk' . ($i === 1 ? '' : 's'); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <div class="helper-note">Each extra 30-minute walk is <?php echo dd_format_money((float) $memberConfig['sitting']['additional_walk_rate']); ?> for members.</div>
                            </div>
                        </div>
                    </div>

                    <div class="grid">
                        <div class="field-shell">
                            <label for="referral_code">Referral / Ambassador Code</label>
                            <input
                                type="text"
                                id="referral_code"
                                name="referral_code"
                                maxlength="50"
                                placeholder="Optional code"
                                value="<?php echo old('referral_code', $formData); ?>"
                            >
                        </div>

                        <div class="field-shell">
                            <label>Booking Status</label>
                            <div class="pill-row">
                                <div class="pill">Member pricing active</div>
                                <div class="pill">Single service hub</div>
                            </div>
                        </div>
                    </div>

                    <div class="field-shell">
                        <label for="notes">Care Notes</label>
                        <textarea id="notes" name="notes" placeholder="Feeding notes, leash preferences, access notes, behavior notes, or anything else important..."><?php echo old('notes', $formData); ?></textarea>
                    </div>

                    <div class="submit-row">
                        <button type="submit" class="btn-primary">Request Booking</button>
                        <a class="top-link" href="my-bookings.php">Back to My Bookings</a>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="eyebrow">Booking Overview</div>

                <div class="price-preview">
                    <div class="price-label">Live Member Price</div>
                    <div class="price-value" id="live-price"><?php echo dd_format_money((float) $previewPrice); ?></div>
                    <div class="live-badge" id="live-price-note"><?php echo h($previewLabel); ?></div>
                    <ul class="summary-list" id="live-summary-list">
                        <?php foreach ($previewSummaryLines as $line): ?>
                            <li><?php echo h($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="feature-list">
                    <div class="feature">
                        <strong>Walk pricing</strong>
                        15 min <?php echo dd_format_money((float) $pricingMatrix['walk']['member'][15]); ?> ·
                        20 min <?php echo dd_format_money((float) $pricingMatrix['walk']['member'][20]); ?> ·
                        30 min <?php echo dd_format_money((float) $pricingMatrix['walk']['member'][30]); ?> ·
                        45 min <?php echo dd_format_money((float) $pricingMatrix['walk']['member'][45]); ?> ·
                        60 min <?php echo dd_format_money((float) $pricingMatrix['walk']['member'][60]); ?>
                    </div>

                    <div class="feature">
                        <strong>Member drop-ins</strong>
                        <?php echo dd_format_money((float) $memberConfig['drop_in']['hourly_rate']); ?>/hour, capped at 2 hours, with an optional 30-minute walk add-on for <?php echo dd_format_money((float) $memberConfig['drop_in']['walk_add_on']); ?>.
                    </div>

                    <div class="feature">
                        <strong>Member daycare</strong>
                        <?php echo dd_format_money((float) $memberConfig['daycare']['base_rate']); ?> for 6 hours, includes 1 complimentary 30-minute walk, plus <?php echo dd_format_money((float) $memberConfig['daycare']['food_fee']); ?> if we provide food.
                    </div>

                    <div class="feature">
                        <strong>In-home sitting</strong>
                        <?php echo dd_format_money((float) $memberConfig['sitting']['base_rate']); ?> for up to <?php echo (int) $memberConfig['sitting']['hours']; ?> hours in your apartment/home and includes 1 complimentary 30-minute walk.
                    </div>

                    <div class="feature">
                        <strong>Boarding</strong>
                        Boarding is priced by dog size and automatically applies the 5+ night member rate when eligible.
                    </div>

                    <div class="feature muted">
                        This is the single member booking page for all service types.
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        (function () {
            var pricingMatrix = <?php echo json_encode($pricingMatrix); ?>;
            var memberConfig = <?php echo json_encode($memberConfig); ?>;

            var serviceField = document.getElementById('service_type');
            var petField = document.getElementById('pet_id');
            var startDateField = document.getElementById('service_date');
            var endDateField = document.getElementById('end_date');
            var timeField = document.getElementById('service_time');
            var durationField = document.getElementById('duration_minutes');
            var dropInHoursField = document.getElementById('drop_in_hours');
            var dropInAddWalkField = document.getElementById('drop_in_add_walk');
            var daycareProvideFoodField = document.getElementById('daycare_provide_food');
            var daycareExtraWalksField = document.getElementById('daycare_extra_walks');
            var sittingExtraWalksField = document.getElementById('sitting_extra_walks');

            var livePrice = document.getElementById('live-price');
            var liveNote = document.getElementById('live-price-note');
            var liveSummaryList = document.getElementById('live-summary-list');

            var startDateLabel = document.getElementById('start-date-label');
            var endDateLabel = document.getElementById('end-date-label');
            var endDateGroup = document.getElementById('end-date-group');
            var endDateHelper = document.getElementById('end-date-helper');
            var timeGroup = document.getElementById('time-group');
            var timeLabel = document.getElementById('time-label');
            var durationGroup = document.getElementById('duration-group');

            var dropInOptions = document.getElementById('dropin-options');
            var daycareOptions = document.getElementById('daycare-options');
            var sittingOptions = document.getElementById('sitting-options');

            if (!serviceField || !petField || !startDateField || !endDateField || !timeField || !durationField || !livePrice) {
                return;
            }

            function getSelectedPetSize() {
                var option = petField.options[petField.selectedIndex];
                if (!option) {
                    return '';
                }

                return String(option.getAttribute('data-pet-size') || '').toLowerCase();
            }

            function nightsBetween(start, end) {
                if (!start || !end) {
                    return 0;
                }

                var startDate = new Date(start + 'T00:00:00');
                var endDate = new Date(end + 'T00:00:00');

                if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || endDate <= startDate) {
                    return 0;
                }

                var diff = endDate.getTime() - startDate.getTime();
                return Math.floor(diff / 86400000);
            }

            function formatMoney(amount) {
                return '$' + Number(amount || 0).toFixed(2);
            }

            function getPricingPreview() {
                var service = serviceField.value || 'walk';
                var petSize = getSelectedPetSize();
                var duration = String(durationField.value || '30');
                var startDate = startDateField.value || '';
                var endDate = endDateField.value || '';
                var response = {
                    total: 0,
                    note: 'Live member pricing updates automatically as you change selections.',
                    lines: []
                };

                if (service === 'walk') {
                    var walkPrice = pricingMatrix.walk.member[duration] || pricingMatrix.walk.member['30'] || 25;
                    response.total = Number(walkPrice);
                    response.note = 'Member walk pricing based on your selected duration.';
                    response.lines.push('Walk length: ' + parseInt(duration, 10) + ' minutes');
                    return response;
                }

                if (service === 'daycare') {
                    var base = Number(memberConfig.daycare.base_rate || 55);
                    var foodFee = daycareProvideFoodField && daycareProvideFoodField.checked ? Number(memberConfig.daycare.food_fee || 5) : 0;
                    var extraWalks = daycareExtraWalksField ? parseInt(daycareExtraWalksField.value || '0', 10) : 0;
                    if (isNaN(extraWalks) || extraWalks < 0) {
                        extraWalks = 0;
                    }
                    var extraWalkCost = extraWalks * Number(memberConfig.daycare.additional_walk_rate || 10);
                    response.total = base + foodFee + extraWalkCost;
                    response.note = '6-hour daycare includes 1 complimentary 30-minute walk.';
                    response.lines.push('Base daycare: ' + formatMoney(base));
                    response.lines.push(daycareProvideFoodField && daycareProvideFoodField.checked ? 'Food provided by Doggie Dorian’s: +' + formatMoney(foodFee) : 'Pet parent provides food: no food fee');
                    if (extraWalks > 0) {
                        response.lines.push(extraWalks + ' extra 30-minute walk(s) at ' + formatMoney(memberConfig.daycare.additional_walk_rate) + ' each');
                    }
                    return response;
                }

                if (service === 'boarding') {
                    if (!petSize || !pricingMatrix.boarding.member[petSize]) {
                        response.total = Number(pricingMatrix.boarding.member.medium || 90);
                        response.note = 'Boarding is priced by dog size and number of nights.';
                        response.lines.push('Select a pet to preview the most accurate size-based boarding rate.');
                        response.lines.push('5+ nights automatically receive the extended-stay member rate.');
                        return response;
                    }

                    var boardingNights = nightsBetween(startDate, endDate);
                    if (boardingNights <= 0) {
                        boardingNights = 1;
                    }

                    if (boardingNights >= 5) {
                        response.total = Number(pricingMatrix.boarding.member_5plus[petSize]) * boardingNights;
                        response.note = '5+ night member boarding rate applied.';
                    } else {
                        response.total = Number(pricingMatrix.boarding.member[petSize]) * boardingNights;
                        response.note = 'Boarding is priced by dog size and number of nights.';
                    }

                    response.lines.push(boardingNights + ' night(s)');
                    response.lines.push('Pet size: ' + petSize);
                    if (boardingNights >= 5) {
                        response.lines.push('Extended-stay member rate included.');
                    }
                    return response;
                }

                if (service === 'drop-in') {
                    var hours = dropInHoursField ? parseInt(dropInHoursField.value || '1', 10) : 1;
                    if (isNaN(hours) || hours < 1) {
                        hours = 1;
                    }
                    if (hours > 2) {
                        hours = 2;
                    }

                    var dropBase = hours * Number(memberConfig.drop_in.hourly_rate || 25);
                    var walkFee = dropInAddWalkField && dropInAddWalkField.checked ? Number(memberConfig.drop_in.walk_add_on || 7) : 0;
                    response.total = dropBase + walkFee;
                    response.note = 'Drop-ins are hourly and capped at 2 hours.';
                    response.lines.push(hours + ' hour drop-in at ' + formatMoney(memberConfig.drop_in.hourly_rate) + ' per hour');
                    if (dropInAddWalkField && dropInAddWalkField.checked) {
                        response.lines.push('Includes 1 add-on 30-minute walk for ' + formatMoney(memberConfig.drop_in.walk_add_on));
                    }
                    return response;
                }

                if (service === 'sitting') {
                    var sittingBase = Number(memberConfig.sitting.base_rate || 120);
                    var sittingExtraWalks = sittingExtraWalksField ? parseInt(sittingExtraWalksField.value || '0', 10) : 0;
                    if (isNaN(sittingExtraWalks) || sittingExtraWalks < 0) {
                        sittingExtraWalks = 0;
                    }
                    var sittingExtraCost = sittingExtraWalks * Number(memberConfig.sitting.additional_walk_rate || 10);
                    response.total = sittingBase + sittingExtraCost;
                    response.note = 'In-home sitting includes 1 complimentary 30-minute walk.';
                    response.lines.push('Up to ' + Number(memberConfig.sitting.hours || 4) + ' hours in your home');
                    response.lines.push('Base session: ' + formatMoney(sittingBase));
                    if (sittingExtraWalks > 0) {
                        response.lines.push(sittingExtraWalks + ' extra 30-minute walk(s) at ' + formatMoney(memberConfig.sitting.additional_walk_rate) + ' each');
                    }
                    return response;
                }

                return response;
            }

            function renderSummaryLines(lines) {
                if (!liveSummaryList) {
                    return;
                }

                liveSummaryList.innerHTML = '';

                if (!lines || !lines.length) {
                    return;
                }

                lines.forEach(function (line) {
                    var li = document.createElement('li');
                    li.textContent = line;
                    liveSummaryList.appendChild(li);
                });
            }

            function updateFieldVisibility() {
                var service = serviceField.value || 'walk';

                dropInOptions.classList.add('hidden');
                daycareOptions.classList.add('hidden');
                sittingOptions.classList.add('hidden');

                if (service === 'walk') {
                    startDateLabel.textContent = 'Service Date';
                    endDateGroup.classList.add('hidden');
                    endDateField.required = false;
                    endDateHelper.textContent = '';
                    timeGroup.classList.remove('hidden');
                    timeLabel.textContent = 'Preferred Time';
                    timeField.required = true;
                    durationGroup.classList.remove('hidden');
                    durationField.required = true;
                    return;
                }

                if (service === 'daycare') {
                    startDateLabel.textContent = 'Service Date';
                    endDateGroup.classList.add('hidden');
                    endDateField.required = false;
                    endDateHelper.textContent = '';
                    timeGroup.classList.remove('hidden');
                    timeLabel.textContent = 'Preferred Drop-Off Time';
                    timeField.required = true;
                    durationGroup.classList.add('hidden');
                    durationField.required = false;
                    daycareOptions.classList.remove('hidden');
                    return;
                }

                if (service === 'boarding') {
                    startDateLabel.textContent = 'Check-In Date';
                    endDateGroup.classList.remove('hidden');
                    endDateLabel.textContent = 'Check-Out Date';
                    endDateHelper.textContent = 'Boarding is priced by dog size and number of nights. 5+ nights automatically receive the extended-stay member rate.';
                    endDateField.required = true;
                    timeGroup.classList.remove('hidden');
                    timeLabel.textContent = 'Preferred Check-In Time';
                    timeField.required = false;
                    durationGroup.classList.add('hidden');
                    durationField.required = false;
                    return;
                }

                if (service === 'drop-in') {
                    startDateLabel.textContent = 'Service Date';
                    endDateGroup.classList.add('hidden');
                    endDateField.required = false;
                    endDateHelper.textContent = '';
                    timeGroup.classList.remove('hidden');
                    timeLabel.textContent = 'Preferred Time';
                    timeField.required = true;
                    durationGroup.classList.add('hidden');
                    durationField.required = false;
                    dropInOptions.classList.remove('hidden');
                    return;
                }

                if (service === 'sitting') {
                    startDateLabel.textContent = 'Service Date';
                    endDateGroup.classList.add('hidden');
                    endDateField.required = false;
                    endDateHelper.textContent = '';
                    timeGroup.classList.remove('hidden');
                    timeLabel.textContent = 'Preferred Start Time';
                    timeField.required = true;
                    durationGroup.classList.add('hidden');
                    durationField.required = false;
                    sittingOptions.classList.remove('hidden');
                    return;
                }
            }

            function renderPrice() {
                var preview = getPricingPreview();

                livePrice.textContent = formatMoney(preview.total || 0);

                if (liveNote) {
                    liveNote.textContent = preview.note || '';
                }

                renderSummaryLines(preview.lines || []);
            }

            function handleChange() {
                updateFieldVisibility();
                renderPrice();
            }

            [
                serviceField,
                petField,
                startDateField,
                endDateField,
                timeField,
                durationField,
                dropInHoursField,
                dropInAddWalkField,
                daycareProvideFoodField,
                daycareExtraWalksField,
                sittingExtraWalksField
            ].forEach(function (field) {
                if (!field) {
                    return;
                }
                field.addEventListener('change', handleChange);
                field.addEventListener('input', handleChange);
            });

            handleChange();
        })();
    </script>
</body>
</html>