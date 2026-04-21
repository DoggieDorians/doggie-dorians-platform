<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

const DD_AMBASSADOR_DISCOUNT_AMOUNT = 10.00;
const DD_AMBASSADOR_REWARD_AMOUNT = 5.00;

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

function stash_nonmember_form_data(array $source): void
{
    $_SESSION['nonmember_form_data'] = $source;
}

function redirect_back_with_error(string $message, array $formData = []): void
{
    if ($formData !== []) {
        stash_nonmember_form_data($formData);
    }

    set_nonmember_flash('error', $message);
    redirect_to('non-member-booking.php');
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
        $cache[$table] = (bool) $stmt->fetchColumn();
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
                    $columns[] = (string) $row['name'];
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

function first_existing_column(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = get_table_columns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
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

function normalize_ambassador_code(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? '';

    return $value;
}

function is_valid_ambassador_code_format(string $value): bool
{
    return $value === '' || (bool) preg_match('/^[A-Z0-9_-]{3,50}$/', $value);
}

function get_client_ip_address(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', (string) $candidate));

        foreach ($parts as $part) {
            if (filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }

    return '';
}

function find_member_id_by_user_id(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !table_exists($pdo, 'members') || !has_column($pdo, 'members', 'user_id')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM members WHERE user_id = :user_id ORDER BY id ASC LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int) $value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function find_user_id_by_member_id(PDO $pdo, int $memberId): int
{
    if ($memberId <= 0 || !table_exists($pdo, 'members') || !has_column($pdo, 'members', 'user_id')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT user_id FROM members WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $memberId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int) $value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function find_email_for_member(PDO $pdo, int $memberId): string
{
    if ($memberId <= 0) {
        return '';
    }

    if (table_exists($pdo, 'members') && has_column($pdo, 'members', 'email')) {
        try {
            $stmt = $pdo->prepare('SELECT email FROM members WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $memberId]);
            $value = $stmt->fetchColumn();

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (Throwable $e) {
        }
    }

    $userId = find_user_id_by_member_id($pdo, $memberId);

    if ($userId > 0 && table_exists($pdo, 'users') && has_column($pdo, 'users', 'email')) {
        try {
            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $value = $stmt->fetchColumn();

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

function lookup_ambassador_code(PDO $pdo, string $code): ?array
{
    if ($code === '') {
        return null;
    }

    $searchCode = strtolower($code);

    $sources = [
        'members' => [
            'table' => 'members',
            'code_columns' => ['ambassador_code', 'referral_code', 'custom_referral_code', 'custom_code', 'promo_code'],
            'member_id_columns' => ['id', 'member_id'],
            'user_id_columns' => ['user_id'],
            'email_columns' => ['email'],
            'name_columns' => ['full_name', 'name'],
        ],
        'users' => [
            'table' => 'users',
            'code_columns' => ['ambassador_code', 'referral_code', 'custom_referral_code', 'custom_code', 'promo_code'],
            'member_id_columns' => [],
            'user_id_columns' => ['id', 'user_id'],
            'email_columns' => ['email'],
            'name_columns' => ['full_name', 'name'],
        ],
        'ambassadors' => [
            'table' => 'ambassadors',
            'code_columns' => ['ambassador_code', 'referral_code', 'code', 'custom_code', 'promo_code'],
            'member_id_columns' => ['referring_member_id', 'referrer_member_id', 'member_id'],
            'user_id_columns' => ['user_id', 'referring_user_id', 'referrer_user_id'],
            'email_columns' => ['email', 'member_email'],
            'name_columns' => ['full_name', 'name', 'member_name'],
        ],
        'referrals' => [
            'table' => 'referrals',
            'code_columns' => ['ambassador_code', 'referral_code', 'code', 'custom_code', 'promo_code'],
            'member_id_columns' => ['referring_member_id', 'referrer_member_id', 'member_id'],
            'user_id_columns' => ['user_id', 'referring_user_id', 'referrer_user_id'],
            'email_columns' => ['email', 'member_email', 'referrer_email'],
            'name_columns' => ['full_name', 'name', 'member_name', 'referrer_name'],
        ],
    ];

    foreach ($sources as $config) {
        $table = $config['table'];

        if (!table_exists($pdo, $table)) {
            continue;
        }

        $codeColumn = first_existing_column($pdo, $table, $config['code_columns']);

        if ($codeColumn === null) {
            continue;
        }

        $selects = [$codeColumn . ' AS ambassador_code'];

        $memberIdColumn = first_existing_column($pdo, $table, $config['member_id_columns']);
        $userIdColumn = first_existing_column($pdo, $table, $config['user_id_columns']);
        $emailColumn = first_existing_column($pdo, $table, $config['email_columns']);
        $nameColumn = first_existing_column($pdo, $table, $config['name_columns']);

        if ($memberIdColumn !== null) {
            $selects[] = $memberIdColumn . ' AS raw_member_id';
        }

        if ($userIdColumn !== null) {
            $selects[] = $userIdColumn . ' AS raw_user_id';
        }

        if ($emailColumn !== null) {
            $selects[] = $emailColumn . ' AS owner_email';
        }

        if ($nameColumn !== null) {
            $selects[] = $nameColumn . ' AS owner_name';
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE LOWER(TRIM(%s)) = :code ORDER BY rowid DESC LIMIT 1',
            implode(', ', $selects),
            $table,
            $codeColumn
        );

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':code' => $searchCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                continue;
            }

            $memberId = isset($row['raw_member_id']) ? (int) $row['raw_member_id'] : 0;
            $userId = isset($row['raw_user_id']) ? (int) $row['raw_user_id'] : 0;

            if ($memberId <= 0 && $userId > 0) {
                $memberId = find_member_id_by_user_id($pdo, $userId);
            }

            if ($userId <= 0 && $memberId > 0) {
                $userId = find_user_id_by_member_id($pdo, $memberId);
            }

            $ownerEmail = trim((string) ($row['owner_email'] ?? ''));

            if ($ownerEmail === '' && $memberId > 0) {
                $ownerEmail = find_email_for_member($pdo, $memberId);
            }

            return [
                'code' => normalize_ambassador_code((string) ($row['ambassador_code'] ?? $code)),
                'member_id' => $memberId,
                'user_id' => $userId,
                'owner_email' => $ownerEmail,
                'owner_name' => trim((string) ($row['owner_name'] ?? '')),
                'source_table' => $table,
            ];
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

function has_prior_referral_ip_usage(PDO $pdo, string $ipAddress): bool
{
    if ($ipAddress === '') {
        return false;
    }

    $bookingTables = ['non_member_bookings', 'public_booking_requests'];

    foreach ($bookingTables as $table) {
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $ipColumn = first_existing_column($pdo, $table, ['referral_ip', 'client_ip', 'ip_address']);
        $codeColumn = first_existing_column($pdo, $table, ['ambassador_code', 'referral_code', 'promo_code']);
        $discountColumn = first_existing_column($pdo, $table, ['ambassador_discount_amount', 'discount_amount']);

        if ($ipColumn === null) {
            continue;
        }

        $conditions = [$ipColumn . ' = :ip'];
        if ($codeColumn !== null) {
            $conditions[] = "TRIM(COALESCE(" . $codeColumn . ", '')) <> ''";
        }
        if ($discountColumn !== null) {
            $conditions[] = $discountColumn . ' > 0';
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $table,
            implode(' AND ', $conditions)
        );

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':ip' => $ipAddress]);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }

    if (table_exists($pdo, 'referrals')) {
        $ipColumn = first_existing_column($pdo, 'referrals', ['referral_ip', 'used_ip', 'client_ip', 'ip_address']);
        $codeColumn = first_existing_column($pdo, 'referrals', ['ambassador_code', 'referral_code', 'code', 'promo_code']);
        $statusColumn = first_existing_column($pdo, 'referrals', ['status', 'referral_status']);

        if ($ipColumn !== null) {
            $conditions = [$ipColumn . ' = :ip'];
            if ($codeColumn !== null) {
                $conditions[] = "TRIM(COALESCE(" . $codeColumn . ", '')) <> ''";
            }
            if ($statusColumn !== null) {
                $conditions[] = "LOWER(COALESCE(" . $statusColumn . ", '')) NOT IN ('cancelled', 'canceled', 'failed', 'void', 'blocked')";
            }

            $sql = sprintf(
                'SELECT COUNT(*) FROM referrals WHERE %s',
                implode(' AND ', $conditions)
            );

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':ip' => $ipAddress]);
                if ((int) $stmt->fetchColumn() > 0) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }
    }

    return false;
}

function calculate_non_member_pricing(array $data): array
{
    $serviceType = $data['service_type'];

    if ($serviceType === 'walk') {
        $walkDuration = (int) $data['walk_duration'];

        if (!valid_walk_duration($walkDuration)) {
            throw new RuntimeException('Invalid walk duration.');
        }

        return dd_get_service_pricing('walk', false, [
            'duration_minutes' => $walkDuration,
        ]);
    }

    if ($serviceType === 'drop_in') {
        $dropInHours = (int) $data['drop_in_hours'];
        $dropInAddWalk = (bool) $data['drop_in_add_walk'];

        if (!in_array($dropInHours, [1, 2], true)) {
            throw new RuntimeException('Invalid drop-in length.');
        }

        return dd_get_service_pricing('drop_in', false, [
            'quantity' => $dropInHours,
            'add_walk' => $dropInAddWalk,
        ]);
    }

    if ($serviceType === 'sitting') {
        return dd_get_service_pricing('sitting', false, [
            'extra_walks' => (int) $data['sitting_extra_walks'],
        ]);
    }

    if ($serviceType === 'daycare' || $serviceType === 'boarding') {
        throw new RuntimeException('Daycare and boarding are currently included only through founder packages while availability remains.');
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
    $now = date('Y-m-d H:i:s');

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
        'unit_price' => number_format((float) $payload['unit_price'], 2, '.', ''),
        'estimated_price' => number_format((float) $payload['final_total_amount'], 2, '.', ''),
        'total_amount' => number_format((float) $payload['final_total_amount'], 2, '.', ''),
        'original_price' => number_format((float) $payload['original_total_amount'], 2, '.', ''),
        'original_amount' => number_format((float) $payload['original_total_amount'], 2, '.', ''),
        'subtotal_amount' => number_format((float) $payload['original_total_amount'], 2, '.', ''),
        'discount_amount' => number_format((float) $payload['discount_amount'], 2, '.', ''),
        'ambassador_discount_amount' => number_format((float) $payload['discount_amount'], 2, '.', ''),
        'final_price' => number_format((float) $payload['final_total_amount'], 2, '.', ''),
        'final_amount' => number_format((float) $payload['final_total_amount'], 2, '.', ''),
        'ambassador_code' => $payload['ambassador_code'],
        'referral_code' => $payload['ambassador_code'],
        'promo_code' => $payload['ambassador_code'],
        'referring_member_id' => $payload['referring_member_id'] > 0 ? $payload['referring_member_id'] : null,
        'referrer_member_id' => $payload['referring_member_id'] > 0 ? $payload['referring_member_id'] : null,
        'referring_user_id' => $payload['referring_user_id'] > 0 ? $payload['referring_user_id'] : null,
        'referrer_user_id' => $payload['referring_user_id'] > 0 ? $payload['referring_user_id'] : null,
        'referral_status' => $payload['referral_status'],
        'referral_ip' => $payload['referral_ip'],
        'client_ip' => $payload['referral_ip'],
    ];

    if (in_array('status', $columns, true)) {
        $fieldMap['status'] = $table === 'non_member_bookings' ? 'Pending Payment' : 'pending';
    }

    if (in_array('payment_status', $columns, true)) {
        $fieldMap['payment_status'] = 'pending';
    }

    if (in_array('created_at', $columns, true)) {
        $fieldMap['created_at'] = $now;
    }

    if (in_array('updated_at', $columns, true)) {
        $fieldMap['updated_at'] = $now;
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

    return (int) $pdo->lastInsertId();
}

function save_pending_referral_record(PDO $pdo, array $payload): void
{
    if (!table_exists($pdo, 'referrals')) {
        return;
    }

    $columns = get_table_columns($pdo, 'referrals');

    if ($columns === []) {
        return;
    }

    $now = date('Y-m-d H:i:s');

    $fieldMap = [
        'ambassador_code' => $payload['ambassador_code'],
        'referral_code' => $payload['ambassador_code'],
        'code' => $payload['ambassador_code'],
        'promo_code' => $payload['ambassador_code'],
        'referring_member_id' => $payload['referring_member_id'] > 0 ? $payload['referring_member_id'] : null,
        'referrer_member_id' => $payload['referring_member_id'] > 0 ? $payload['referring_member_id'] : null,
        'member_id' => $payload['referring_member_id'] > 0 ? $payload['referring_member_id'] : null,
        'referring_user_id' => $payload['referring_user_id'] > 0 ? $payload['referring_user_id'] : null,
        'referrer_user_id' => $payload['referring_user_id'] > 0 ? $payload['referring_user_id'] : null,
        'user_id' => $payload['referring_user_id'] > 0 ? $payload['referring_user_id'] : null,
        'referred_name' => $payload['client_name'],
        'client_name' => $payload['client_name'],
        'full_name' => $payload['client_name'],
        'referred_email' => $payload['client_email'],
        'client_email' => $payload['client_email'],
        'email' => $payload['client_email'],
        'booking_id' => $payload['booking_id'],
        'non_member_booking_id' => $payload['booking_id'],
        'request_id' => $payload['booking_id'],
        'discount_amount' => number_format((float) $payload['discount_amount'], 2, '.', ''),
        'reward_amount' => number_format((float) $payload['reward_amount'], 2, '.', ''),
        'credit_amount' => number_format((float) $payload['reward_amount'], 2, '.', ''),
        'ambassador_credit_amount' => number_format((float) $payload['reward_amount'], 2, '.', ''),
        'status' => $payload['status'],
        'referral_status' => $payload['status'],
        'referral_ip' => $payload['referral_ip'],
        'used_ip' => $payload['referral_ip'],
        'client_ip' => $payload['referral_ip'],
        'source' => 'non_member_booking',
        'notes' => 'Pending ambassador referral created before payment.',
        'created_at' => $now,
        'updated_at' => $now,
        'pending_at' => $now,
    ];

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

    if ($insertColumns === []) {
        return;
    }

    try {
        $sql = sprintf(
            'INSERT INTO referrals (%s) VALUES (%s)',
            implode(', ', $insertColumns),
            implode(', ', $insertParams)
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    } catch (Throwable $e) {
        return;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_nonmember_flash('error', 'Invalid request method.');
    redirect_to('non-member-booking.php');
}

$formData = $_POST;

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$dogName = trim((string) ($_POST['dog_name'] ?? ''));
$dogSize = normalize_dog_size((string) ($_POST['dog_size'] ?? ''));
$serviceType = normalize_service_type((string) ($_POST['service_type'] ?? ''));
$dateStart = trim((string) ($_POST['date_start'] ?? ''));
$dateEnd = trim((string) ($_POST['date_end'] ?? ''));
$walkDuration = (int) ($_POST['walk_duration'] ?? 0);
$dropInHours = (int) ($_POST['drop_in_hours'] ?? 1);
$dropInAddWalk = bool_from_form((string) ($_POST['drop_in_add_walk'] ?? ''));
$daycareProvideFood = bool_from_form((string) ($_POST['daycare_provide_food'] ?? ''));
$daycareExtraWalks = (int) ($_POST['daycare_extra_walks'] ?? 0);
$sittingExtraWalks = (int) ($_POST['sitting_extra_walks'] ?? 0);
$ambassadorCode = normalize_ambassador_code((string) ($_POST['ambassador_code'] ?? ''));

$formData['ambassador_code'] = $ambassadorCode;

if ($fullName === '' || $email === '' || $dogName === '' || $serviceType === '') {
    redirect_back_with_error('Please complete all required booking fields.', $formData);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_back_with_error('Please enter a valid email address.', $formData);
}

if (!is_valid_ambassador_code_format($ambassadorCode)) {
    redirect_back_with_error('Please enter a valid ambassador code format.', $formData);
}

if (!in_array($serviceType, ['walk', 'drop_in', 'sitting'], true)) {
    redirect_back_with_error('Daycare and boarding are currently included only through founder packages while availability remains.', $formData);
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
    redirect_back_with_error($e->getMessage(), $formData);
}

$originalTotalAmount = (float) ($pricing['total_price'] ?? 0);
$unitPrice = (float) ($pricing['unit_price'] ?? 0);
$quantity = (int) ($pricing['quantity'] ?? 0);

if ($originalTotalAmount <= 0 || $quantity <= 0) {
    redirect_back_with_error('We could not calculate a valid non-member booking total.', $formData);
}

$pricingType = 'non_member';
$discountLabel = 'standard_non_member';
$discountAmount = 0.00;
$finalTotalAmount = $originalTotalAmount;
$referralStatus = '';
$referringMemberId = 0;
$referringUserId = 0;
$clientIpAddress = get_client_ip_address();

if ($ambassadorCode !== '') {
    $lookup = lookup_ambassador_code($pdo, $ambassadorCode);

    if ($lookup === null) {
        redirect_back_with_error('That ambassador code could not be validated.', $formData);
    }

    $referringMemberId = (int) ($lookup['member_id'] ?? 0);
    $referringUserId = (int) ($lookup['user_id'] ?? 0);
    $ownerEmail = strtolower(trim((string) ($lookup['owner_email'] ?? '')));

    if ($referringMemberId <= 0 && $referringUserId <= 0) {
        redirect_back_with_error('That ambassador code is not connected to a valid member account.', $formData);
    }

    if ($ownerEmail !== '' && strtolower($email) === $ownerEmail) {
        redirect_back_with_error('You cannot use your own ambassador code.', $formData);
    }

    if ($clientIpAddress !== '' && has_prior_referral_ip_usage($pdo, $clientIpAddress)) {
        redirect_back_with_error('This IP address has already used an ambassador discount.', $formData);
    }

    $discountAmount = min(DD_AMBASSADOR_DISCOUNT_AMOUNT, $originalTotalAmount);
    $finalTotalAmount = max(0, $originalTotalAmount - $discountAmount);
    $pricingType = 'non_member_ambassador';
    $discountLabel = 'ambassador_code';
    $referralStatus = 'pending';
}

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
        'daycare_provide_food' => false,
        'daycare_extra_walks' => 0,
        'sitting_extra_walks' => $sittingExtraWalks,
        'pricing_type' => $pricingType,
        'discount_label' => $discountLabel,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'original_total_amount' => $originalTotalAmount,
        'discount_amount' => $discountAmount,
        'final_total_amount' => $finalTotalAmount,
        'ambassador_code' => $ambassadorCode,
        'referring_member_id' => $referringMemberId,
        'referring_user_id' => $referringUserId,
        'referral_status' => $referralStatus,
        'referral_ip' => $clientIpAddress,
    ]);
} catch (Throwable $e) {
    redirect_back_with_error('We could not save your booking request for payment.', $formData);
}

if ($ambassadorCode !== '') {
    save_pending_referral_record($pdo, [
        'ambassador_code' => $ambassadorCode,
        'referring_member_id' => $referringMemberId,
        'referring_user_id' => $referringUserId,
        'client_name' => $fullName,
        'client_email' => $email,
        'booking_id' => $requestId,
        'discount_amount' => $discountAmount,
        'reward_amount' => DD_AMBASSADOR_REWARD_AMOUNT,
        'status' => 'pending',
        'referral_ip' => $clientIpAddress,
    ]);
}

$_SESSION['non_member_payment_portal'] = [
    'request_id' => $requestId,
    'full_name' => $fullName,
    'email' => $email,
    'phone' => $phone,
    'dog_name' => $dogName,
    'dog_size' => '',
    'service_type' => $serviceType,
    'date_start' => $dateStart,
    'date_end' => '',
    'walk_duration' => $walkDuration,
    'drop_in_hours' => $dropInHours,
    'drop_in_add_walk' => $dropInAddWalk ? '1' : '0',
    'daycare_provide_food' => '0',
    'daycare_extra_walks' => 0,
    'sitting_extra_walks' => $sittingExtraWalks,
    'pricing_type' => $pricingType,
    'discount_label' => $discountLabel,
    'quantity' => $quantity,
    'unit_price' => $unitPrice,
    'original_total_amount' => $originalTotalAmount,
    'discount_amount' => $discountAmount,
    'total_amount' => $finalTotalAmount,
    'final_total_amount' => $finalTotalAmount,
    'ambassador_code' => $ambassadorCode,
    'referring_member_id' => $referringMemberId,
    'referring_user_id' => $referringUserId,
    'referral_status' => $referralStatus,
    'referral_reward_amount' => DD_AMBASSADOR_REWARD_AMOUNT,
    'referral_ip' => $clientIpAddress,
];

redirect_to('non-member-payment-portal.php?request_id=' . $requestId);