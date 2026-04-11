<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function set_nonmember_flash(string $type, string $message): void
{
    $_SESSION['nonmember_flash_type'] = $type;
    $_SESSION['nonmember_flash_message'] = $message;
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string)$row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, get_table_columns($pdo, $table), true);
}

function normalize_service_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'daycare', 'day care' => 'daycare',
        'boarding', 'board' => 'boarding',
        'drop-in', 'drop in', 'drop_in', 'dropin' => 'drop_in',
        'sitting', 'pet sitting', 'in-home sitting', 'in_home_sitting' => 'sitting',
        default => '',
    };
}

function normalize_dog_size(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'small', 'small dog' => 'small',
        'medium', 'medium dog' => 'medium',
        'large', 'large dog' => 'large',
        default => '',
    };
}

function valid_walk_duration(int $minutes): bool
{
    return in_array($minutes, [15, 20, 30, 45, 60], true);
}

function bool_from_form(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function calculate_non_member_pricing(array $data): array
{
    $serviceType = $data['service_type'];

    if ($serviceType === 'walk') {
        $walkDuration = (int)$data['walk_duration'];

        if (!valid_walk_duration($walkDuration)) {
            throw new RuntimeException('Invalid walk duration.');
        }

        return dd_get_service_pricing('walk', false, [
            'duration_minutes' => $walkDuration,
        ]);
    }

    if ($serviceType === 'drop_in') {
        $dropInHours = (int)$data['drop_in_hours'];
        $dropInAddWalk = (bool)$data['drop_in_add_walk'];

        if (!in_array($dropInHours, [1, 2], true)) {
            throw new RuntimeException('Invalid drop-in length.');
        }

        return dd_get_service_pricing('drop_in', false, [
            'quantity' => $dropInHours,
            'add_walk' => $dropInAddWalk,
        ]);
    }

    if ($serviceType === 'daycare') {
        return dd_get_service_pricing('daycare', false, [
            'provide_food' => (bool)$data['daycare_provide_food'],
            'extra_walks' => (int)$data['daycare_extra_walks'],
        ]);
    }

    if ($serviceType === 'sitting') {
        return dd_get_service_pricing('sitting', false, [
            'extra_walks' => (int)$data['sitting_extra_walks'],
        ]);
    }

    if ($serviceType === 'boarding') {
        $dogSize = $data['dog_size'];
        $dateStart = $data['date_start'];
        $dateEnd = $data['date_end'];

        if ($dogSize === '' || $dateStart === '' || $dateEnd === '') {
            throw new RuntimeException('Boarding requires dog size, check-in date, and check-out date.');
        }

        $nights = dd_calculate_boarding_nights($dateStart, $dateEnd);

        if ($nights <= 0) {
            throw new RuntimeException('Boarding requires a valid date range.');
        }

        return dd_get_service_pricing('boarding', false, [
            'dog_size' => $dogSize,
            'quantity' => $nights,
        ]);
    }

    throw new RuntimeException('Unsupported non-member service.');
}

function save_non_member_booking(PDO $pdo, array $payload): int
{
    $primaryTable = table_exists($pdo, 'non_member_bookings') ? 'non_member_bookings' : '';
    $fallbackTable = table_exists($pdo, 'public_booking_requests') ? 'public_booking_requests' : '';

    $table = $primaryTable !== '' ? $primaryTable : $fallbackTable;

    if ($table === '') {
        throw new RuntimeException('No non-member booking table is available.');
    }

    $columns = get_table_columns($pdo, $table);

    $fieldMap = [
        'full_name' => $payload['full_name'],
        'email' => $payload['email'],
        'phone' => $payload['phone'],
        'dog_name' => $payload['dog_name'],
        'dog_size' => $payload['dog_size'],
        'service_type' => $payload['service_type'],
        'date_start' => $payload['date_start'],
        'date_end' => $payload['date_end'],
        'walk_duration' => $payload['walk_duration'],
        'drop_in_hours' => $payload['drop_in_hours'],
        'drop_in_add_walk' => $payload['drop_in_add_walk'] ? 1 : 0,
        'daycare_provide_food' => $payload['daycare_provide_food'] ? 1 : 0,
        'daycare_extra_walks' => $payload['daycare_extra_walks'],
        'sitting_extra_walks' => $payload['sitting_extra_walks'],
        'pricing_type' => $payload['pricing_type'],
        'discount_label' => $payload['discount_label'],
        'quantity' => $payload['quantity'],
        'unit_price' => number_format((float)$payload['unit_price'], 2, '.', ''),
        'estimated_price' => number_format((float)$payload['total_amount'], 2, '.', ''),
        'total_amount' => number_format((float)$payload['total_amount'], 2, '.', ''),
    ];

    if (in_array('status', $columns, true)) {
        $fieldMap['status'] = $table === 'non_member_bookings' ? 'Pending Payment' : 'pending';
    }

    if (in_array('payment_status', $columns, true)) {
        $fieldMap['payment_status'] = 'pending';
    }

    if (in_array('created_at', $columns, true)) {
        $fieldMap['created_at'] = date('Y-m-d H:i:s');
    }

    if (in_array('updated_at', $columns, true)) {
        $fieldMap['updated_at'] = date('Y-m-d H:i:s');
    }

    $insertColumns = [];
    $insertParams = [];
    $values = [];

    foreach ($fieldMap as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insertColumns[] = $column;
            $insertParams[] = ':' . $column;
            $values[':' . $column] = $value;
        }
    }

    if (empty($insertColumns)) {
        throw new RuntimeException('The non-member booking table does not have expected columns.');
    }

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $insertColumns),
        implode(', ', $insertParams)
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_nonmember_flash('error', 'Invalid request method.');
    redirect_to('non-member-booking.php');
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$dogName = trim((string)($_POST['dog_name'] ?? ''));
$dogSize = normalize_dog_size((string)($_POST['dog_size'] ?? ''));
$serviceType = normalize_service_type((string)($_POST['service_type'] ?? ''));
$dateStart = trim((string)($_POST['date_start'] ?? ''));
$dateEnd = trim((string)($_POST['date_end'] ?? ''));
$walkDuration = (int)($_POST['walk_duration'] ?? 0);
$dropInHours = (int)($_POST['drop_in_hours'] ?? 1);
$dropInAddWalk = bool_from_form((string)($_POST['drop_in_add_walk'] ?? ''));
$daycareProvideFood = bool_from_form((string)($_POST['daycare_provide_food'] ?? ''));
$daycareExtraWalks = (int)($_POST['daycare_extra_walks'] ?? 0);
$sittingExtraWalks = (int)($_POST['sitting_extra_walks'] ?? 0);

if ($fullName === '' || $email === '' || $dogName === '' || $serviceType === '') {
    set_nonmember_flash('error', 'Please complete all required booking fields.');
    redirect_to('non-member-booking.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_nonmember_flash('error', 'Please enter a valid email address.');
    redirect_to('non-member-booking.php');
}

try {
    $pricing = calculate_non_member_pricing([
        'service_type' => $serviceType,
        'dog_size' => $dogSize,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
        'walk_duration' => $walkDuration,
        'drop_in_hours' => $dropInHours,
        'drop_in_add_walk' => $dropInAddWalk,
        'daycare_provide_food' => $daycareProvideFood,
        'daycare_extra_walks' => $daycareExtraWalks,
        'sitting_extra_walks' => $sittingExtraWalks,
    ]);
} catch (Throwable $e) {
    set_nonmember_flash('error', $e->getMessage());
    redirect_to('non-member-booking.php');
}

$totalAmount = (float)($pricing['total_price'] ?? 0);
$unitPrice = (float)($pricing['unit_price'] ?? 0);
$quantity = (int)($pricing['quantity'] ?? 0);

if ($totalAmount <= 0 || $quantity <= 0) {
    set_nonmember_flash('error', 'We could not calculate a valid non-member booking total.');
    redirect_to('non-member-booking.php');
}

$pricingType = 'non_member';
$discountLabel = 'standard_non_member';

try {
    $requestId = save_non_member_booking($pdo, [
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'dog_name' => $dogName,
        'dog_size' => $dogSize,
        'service_type' => $serviceType,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
        'walk_duration' => $walkDuration,
        'drop_in_hours' => $dropInHours,
        'drop_in_add_walk' => $dropInAddWalk,
        'daycare_provide_food' => $daycareProvideFood,
        'daycare_extra_walks' => $daycareExtraWalks,
        'sitting_extra_walks' => $sittingExtraWalks,
        'pricing_type' => $pricingType,
        'discount_label' => $discountLabel,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total_amount' => $totalAmount,
    ]);
} catch (Throwable $e) {
    set_nonmember_flash('error', 'We could not save your booking request for payment.');
    redirect_to('non-member-booking.php');
}

$_SESSION['non_member_payment_portal'] = [
    'request_id' => $requestId,
    'full_name' => $fullName,
    'email' => $email,
    'phone' => $phone,
    'dog_name' => $dogName,
    'dog_size' => $dogSize,
    'service_type' => $serviceType,
    'date_start' => $dateStart,
    'date_end' => $dateEnd,
    'walk_duration' => $walkDuration,
    'drop_in_hours' => $dropInHours,
    'drop_in_add_walk' => $dropInAddWalk ? '1' : '0',
    'daycare_provide_food' => $daycareProvideFood ? '1' : '0',
    'daycare_extra_walks' => $daycareExtraWalks,
    'sitting_extra_walks' => $sittingExtraWalks,
    'pricing_type' => $pricingType,
    'discount_label' => $discountLabel,
    'quantity' => $quantity,
    'unit_price' => $unitPrice,
    'total_amount' => $totalAmount,
];

redirect_to('non-member-payment-portal.php?request_id=' . $requestId);