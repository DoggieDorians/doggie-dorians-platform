<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!function_exists('redirectTo')) {
    function redirectTo(string $url, int $statusCode = 302): never
    {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
}

function failPage(string $message, int $statusCode = 400, string $returnUrl = 'index.php'): never
{
    http_response_code($statusCode);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Checkout Error | Doggie Dorian’s</title>
    </head>
    <body style="font-family: Arial, sans-serif; background:#111; color:#fff; padding:40px;">
        <h1>Checkout Error</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" style="color:gold;">Go Back</a>
    </body>
    </html>
    <?php
    exit;
}

function getStripeSecretKey(): string
{
    return function_exists('dd_stripe_secret_key')
        ? trim((string) dd_stripe_secret_key())
        : '';
}

function getStripeCurrency(): string
{
    $currency = function_exists('dd_stripe_currency')
        ? trim((string) dd_stripe_currency())
        : 'usd';

    return $currency !== '' ? strtolower($currency) : 'usd';
}

function getBaseUrl(): string
{
    if (function_exists('dd_stripe_public_base_url')) {
        $baseUrl = trim((string) dd_stripe_public_base_url());
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    return $host !== '' ? $scheme . '://' . $host : '';
}

function appendQueryParams(string $url, array $params): string
{
    if ($url === '') {
        return '';
    }

    $separator = str_contains($url, '?') ? '&' : '?';

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $url .= $separator . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        $separator = '&';
    }

    return $url;
}

function absolutize_url(string $url, string $baseUrl): string
{
    $url = trim($url);
    $baseUrl = rtrim(trim($baseUrl), '/');

    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if ($baseUrl === '') {
        return $url;
    }

    if (str_starts_with($url, '/')) {
        return $baseUrl . $url;
    }

    return $baseUrl . '/' . ltrim($url, '/');
}

function buildSuccessUrl(string $mode, string $baseUrl): string
{
    $successUrl = function_exists('dd_stripe_success_url')
        ? trim((string) dd_stripe_success_url())
        : '';

    if ($successUrl === '') {
        $successUrl = $baseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
    }

    $successUrl = absolutize_url($successUrl, $baseUrl);
    return appendQueryParams($successUrl, ['mode' => $mode]);
}

function buildCancelUrl(string $mode, string $baseUrl, array $extraParams = []): string
{
    $cancelUrl = function_exists('dd_stripe_cancel_url')
        ? trim((string) dd_stripe_cancel_url())
        : '';

    if ($cancelUrl === '') {
        $cancelUrl = $baseUrl . '/payment-cancel.php';
    }

    $cancelUrl = absolutize_url($cancelUrl, $baseUrl);
    $params = array_merge(['mode' => $mode], $extraParams);

    return appendQueryParams($cancelUrl, $params);
}

function sanitize_metadata(array $metadata): array
{
    $clean = [];

    foreach ($metadata as $key => $value) {
        if ($value === null) {
            continue;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            continue;
        }

        $key = trim((string) $key);
        $value = trim((string) $value);

        if ($key === '' || $value === '') {
            continue;
        }

        $clean[substr($key, 0, 40)] = substr($value, 0, 500);
    }

    return $clean;
}

function normalize_service_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'daycare', 'day care' => 'daycare',
        'boarding', 'board' => 'boarding',
        'drop-in', 'drop in', 'drop_in', 'dropin' => 'drop_in',
        'sitting', 'in-home sitting', 'in_home_sitting', 'pet sitting' => 'sitting',
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

function normalize_credit_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'daycare', 'day care' => 'daycare',
        'drop-in', 'drop in', 'drop_in', 'dropin' => 'drop_in',
        'boarding', 'board' => 'boarding',
        'sitting', 'in-home sitting', 'in_home_sitting', 'pet sitting' => 'sitting',
        default => '',
    };
}

function valid_walk_duration(int $minutes): bool
{
    return in_array($minutes, [15, 20, 30, 45, 60], true);
}

function current_portal_user_id(): int
{
    $keys = array('user_id', 'member_id', 'client_id', 'id');

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function current_member_row_id(PDO $pdo): int
{
    $member = function_exists('currentMember') ? currentMember($pdo) : null;
    return is_array($member) ? (int) ($member['id'] ?? 0) : 0;
}

function current_member_email(PDO $pdo): string
{
    $member = function_exists('currentMember') ? currentMember($pdo) : null;
    if (!is_array($member)) {
        return '';
    }

    $candidates = [
        (string) ($member['email'] ?? ''),
        (string) ($member['member_email'] ?? ''),
        (string) ($member['user_email'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }

    return '';
}

function service_label_from_type(string $serviceType): string
{
    return match ($serviceType) {
        'walk' => 'Walk',
        'drop_in' => 'Drop-In',
        'daycare' => 'Daycare',
        'boarding' => 'Boarding',
        'sitting' => 'Pet Sitting',
        default => 'Service',
    };
}

function checkoutReturnUrlForMode(string $mode): string
{
    return $mode === 'non_member'
        ? 'non-member-payment-portal.php'
        : 'payment-portal.php';
}

function hasTable(PDO $pdo, string $table): bool
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
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = [];
        return $cache[$table];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = [];
        return $cache[$table];
    } catch (Exception $e) {
        $cache[$table] = [];
        return $cache[$table];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function row_first_value(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function row_first_int(array $row, array $candidates, int $default = 0): int
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && is_numeric($row[$candidate])) {
            return (int) $row[$candidate];
        }
    }

    return $default;
}

function row_first_float(array $row, array $candidates, float $default = 0.0): float
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && is_numeric($row[$candidate])) {
            return (float) $row[$candidate];
        }
    }

    return $default;
}

function booking_table(PDO $pdo): ?string
{
    $candidates = ['bookings', 'walks'];

    foreach ($candidates as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function booking_belongs_to_user(array $row, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $ownerColumns = [
        'user_id',
        'member_id',
        'client_id',
        'owner_id',
        'owner_user_id',
        'client_user_id',
    ];

    foreach ($ownerColumns as $column) {
        if (isset($row[$column]) && is_numeric($row[$column]) && (int) $row[$column] === $userId) {
            return true;
        }
    }

    return false;
}

function load_booking_row(PDO $pdo, int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }

    $table = booking_table($pdo);
    if ($table === null) {
        return null;
    }

    $columns = getTableColumns($pdo, $table);
    $idColumn = first_existing_column($columns, ['id', 'booking_id', 'walk_id']);

    if ($idColumn === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idColumn} = :id LIMIT 1");
    if (!safeExecute($stmt, [':id' => $bookingId])) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function extract_booking_meta_json_from_text(string $text): ?string
{
    $text = trim($text);

    if ($text === '') {
        return null;
    }

    if (preg_match('/Booking details:\s*(\{.*\})/s', $text, $matches)) {
        return (string) $matches[1];
    }

    if ($text[0] === '{' && substr($text, -1) === '}') {
        return $text;
    }

    return null;
}

function decode_booking_meta_from_row(array $row): array
{
    $jsonCandidates = [
        'booking_meta',
        'booking_meta_json',
        'meta',
        'metadata',
        'booking_details_json',
    ];

    foreach ($jsonCandidates as $candidate) {
        if (!empty($row[$candidate]) && is_string($row[$candidate])) {
            $decoded = json_decode((string) $row[$candidate], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $textCandidates = [
        'notes',
        'special_instructions',
        'instructions',
        'care_notes',
        'client_notes',
    ];

    foreach ($textCandidates as $candidate) {
        if (empty($row[$candidate]) || !is_string($row[$candidate])) {
            continue;
        }

        $json = extract_booking_meta_json_from_text((string) $row[$candidate]);
        if ($json === null) {
            continue;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function build_service_overage_context_from_booking(array $row, array $meta, int $bookingId): array
{
    $serviceType = normalize_service_type((string) row_first_value($row, [
        'service_type',
        'type',
        'booking_type',
        'category',
        'service',
    ], ''));

    if ($serviceType === '' && !empty($meta['service_type'])) {
        $serviceType = normalize_service_type((string) $meta['service_type']);
    }

    $quantity = row_first_int($row, ['quantity'], 0);
    if ($quantity <= 0 && isset($meta['required_units']) && is_numeric($meta['required_units'])) {
        $quantity = (int) $meta['required_units'];
    }

    $overageUnits = 0;
    if (isset($meta['overage_units']) && is_numeric($meta['overage_units'])) {
        $overageUnits = max(0, (int) $meta['overage_units']);
    }
    if ($overageUnits <= 0 && $quantity > 0) {
        $overageUnits = $quantity;
    }

    $unitPrice = row_first_float($row, ['unit_price'], 0.0);
    if ($unitPrice <= 0 && isset($meta['unit_price']) && is_numeric($meta['unit_price'])) {
        $unitPrice = (float) $meta['unit_price'];
    }

    $totalAmount = row_first_float($row, [
        'price',
        'total_price',
        'amount_due',
        'amount',
    ], 0.0);

    if ($totalAmount <= 0 && isset($meta['overage_total']) && is_numeric($meta['overage_total'])) {
        $totalAmount = (float) $meta['overage_total'];
    }

    if ($unitPrice <= 0 && $totalAmount > 0 && $overageUnits > 0) {
        $unitPrice = $totalAmount / $overageUnits;
    }

    $bookingDate = trim((string) row_first_value($row, [
        'service_date',
        'booking_date',
        'walk_date',
        'date',
        'scheduled_date',
        'start_date',
        'check_in_date',
    ], ''));

    $bookingTime = trim((string) row_first_value($row, [
        'service_time',
        'booking_time',
        'walk_time',
        'time',
        'scheduled_time',
        'start_time',
    ], ''));

    $petName = trim((string) row_first_value($row, ['pet_name', 'dog_name'], ''));
    $petSize = normalize_dog_size((string) row_first_value($row, ['pet_size', 'dog_size', 'size'], ''));

    $memberPlanSlug = trim((string) ($meta['member_plan_slug'] ?? ''));
    $creditType = normalize_credit_type((string) ($meta['credit_type'] ?? ''));
    $includedCredits = isset($meta['included_credits']) && is_numeric($meta['included_credits']) ? (int) $meta['included_credits'] : 0;

    $remainingCredits = 0;
    if (isset($meta['remaining_credits_after']) && is_numeric($meta['remaining_credits_after'])) {
        $remainingCredits = (int) $meta['remaining_credits_after'];
    } elseif (isset($meta['remaining_credits']) && is_numeric($meta['remaining_credits'])) {
        $remainingCredits = (int) $meta['remaining_credits'];
    }

    $durationLabel = '';
    if ($serviceType === 'walk') {
        $minutes = row_first_int($row, ['duration_minutes', 'duration', 'minutes'], 0);
        if ($minutes <= 0 && isset($meta['duration_minutes']) && is_numeric($meta['duration_minutes'])) {
            $minutes = (int) $meta['duration_minutes'];
        }
        if ($minutes > 0) {
            $durationLabel = $minutes . ' Minutes';
        }
    } elseif ($serviceType === 'drop_in') {
        $hours = 0;
        if (isset($meta['drop_in_hours']) && is_numeric($meta['drop_in_hours'])) {
            $hours = (int) $meta['drop_in_hours'];
        }
        if ($hours > 0) {
            $durationLabel = $hours . ' Hour' . ($hours === 1 ? '' : 's');
        }
    }

    return [
        'booking_id' => $bookingId,
        'service_type' => $serviceType,
        'quantity' => $quantity,
        'overage_units' => $overageUnits,
        'unit_price' => round($unitPrice, 2),
        'total_amount' => round($totalAmount, 2),
        'booking_date' => $bookingDate,
        'booking_time' => $bookingTime,
        'pet_name' => $petName,
        'pet_size' => $petSize,
        'duration_label' => $durationLabel,
        'member_plan_slug' => $memberPlanSlug,
        'credit_type' => $creditType,
        'included_credits' => $includedCredits,
        'remaining_credits' => $remainingCredits,
    ];
}

function merge_context(array $primary, array $fallback): array
{
    foreach ($fallback as $key => $value) {
        if (!array_key_exists($key, $primary)) {
            $primary[$key] = $value;
            continue;
        }

        $current = $primary[$key];
        if (($current === '' || $current === null || $current === 0 || $current === 0.0) && $value !== '' && $value !== null) {
            $primary[$key] = $value;
        }
    }

    return $primary;
}

function mark_non_member_pending(PDO $pdo, int $requestId): void
{
    if ($requestId <= 0) {
        return;
    }

    $tableConfigs = [
        [
            'table' => 'non_member_bookings',
            'id_candidates' => ['id'],
        ],
        [
            'table' => 'public_booking_requests',
            'id_candidates' => ['id', 'request_id'],
        ],
    ];

    foreach ($tableConfigs as $config) {
        try {
            $table = (string) $config['table'];
            $columns = getTableColumns($pdo, $table);

            if (empty($columns)) {
                continue;
            }

            $idColumn = first_existing_column($columns, $config['id_candidates']);
            $paymentStatusColumn = first_existing_column($columns, ['payment_status']);
            $updatedAtColumn = first_existing_column($columns, ['updated_at']);

            if ($idColumn === null || $paymentStatusColumn === null) {
                continue;
            }

            $setParts = ["{$paymentStatusColumn} = :payment_status"];
            $params = [
                ':payment_status' => 'pending',
                ':id' => $requestId,
            ];

            if ($updatedAtColumn !== null) {
                $setParts[] = "{$updatedAtColumn} = :updated_at";
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare("
                UPDATE {$table}
                SET " . implode(', ', $setParts) . "
                WHERE {$idColumn} = :id
            ");
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                return;
            }
        } catch (Throwable $e) {
        } catch (Exception $e) {
        }
    }
}

function mark_booking_pending(PDO $pdo, int $bookingId, int $userId): void
{
    if ($bookingId <= 0 || $userId <= 0) {
        return;
    }

    $table = booking_table($pdo);
    if ($table === null) {
        return;
    }

    try {
        $columns = getTableColumns($pdo, $table);
        if (empty($columns)) {
            return;
        }

        $idColumn = first_existing_column($columns, ['id', 'booking_id', 'walk_id']);
        $ownerColumn = first_existing_column($columns, ['member_id', 'user_id', 'client_id', 'owner_id', 'owner_user_id', 'client_user_id']);
        $paymentStatusColumn = first_existing_column($columns, ['payment_status', 'payment_state']);
        $updatedAtColumn = first_existing_column($columns, ['updated_at']);

        if ($idColumn === null || $ownerColumn === null || $paymentStatusColumn === null) {
            return;
        }

        $setParts = ["{$paymentStatusColumn} = :payment_status"];
        $params = [
            ':payment_status' => 'pending',
            ':id' => $bookingId,
            ':user_id' => $userId,
        ];

        if ($updatedAtColumn !== null) {
            $setParts[] = "{$updatedAtColumn} = :updated_at";
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $sql = "
            UPDATE {$table}
            SET " . implode(', ', $setParts) . "
            WHERE {$idColumn} = :id
              AND {$ownerColumn} = :user_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('index.php', 303);
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
$postCsrf = (string) ($_POST['csrf_token'] ?? '');

if ($sessionCsrf === '' || $postCsrf === '' || !hash_equals($sessionCsrf, $postCsrf)) {
    failPage('Session expired. Try again.', 403);
}

/*
|--------------------------------------------------------------------------
| Shared Stripe Setup
|--------------------------------------------------------------------------
*/
$stripeKey = getStripeSecretKey();
$stripeCurrency = getStripeCurrency();
$baseUrl = getBaseUrl();

if ($stripeKey === '' || $baseUrl === '') {
    failPage('Payment system not configured.', 500);
}

$mode = strtolower(trim((string) ($_POST['mode'] ?? 'custom_plan')));
$allowedModes = ['custom_plan', 'service_overage', 'non_member'];

if (!in_array($mode, $allowedModes, true)) {
    failPage('Invalid checkout mode.', 400);
}

$portalUserId = current_portal_user_id();
$memberRowId = current_member_row_id($pdo);

$checkoutName = '';
$amount = 0.00;
$amountCents = 0;
$successUrl = buildSuccessUrl($mode, $baseUrl);
$cancelUrl = buildCancelUrl($mode, $baseUrl);
$metadata = [];
$customerEmail = '';

if (!preg_match('#^https?://#i', $successUrl) || !preg_match('#^https?://#i', $cancelUrl)) {
    failPage('Stripe return URL configuration is invalid. Success and cancel URLs must be absolute.', 500);
}

/*
|--------------------------------------------------------------------------
| Custom Plan Checkout
|--------------------------------------------------------------------------
*/
if ($mode === 'custom_plan') {
    if ($memberRowId <= 0) {
        redirectTo('login.php');
    }

    $planId = (int) ($_POST['plan_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT *
        FROM custom_plans
        WHERE id = :id AND member_id = :member_id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $planId,
        ':member_id' => $memberRowId,
    ]);

    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        failPage('Plan not found.', 404, 'customize-plan.php');
    }

    $amount = (float) ($plan['monthly_total'] ?? 0);

    if ($amount <= 0) {
        failPage('Invalid plan amount.', 400, 'customize-plan.php');
    }

    $amountCents = (int) round($amount * 100);

    if ($amountCents <= 0 || $amountCents > 5000000) {
        failPage('Invalid payment amount.', 400, 'customize-plan.php');
    }

    $checkoutName = (string) ($plan['plan_name'] ?? 'Custom Plan');
    $cancelUrl = buildCancelUrl('custom_plan', $baseUrl, ['plan_id' => $planId]);
    $customerEmail = current_member_email($pdo);

    $metadata = [
        'mode' => 'custom_plan',
        'custom_plan_id' => (string) $planId,
        'member_id' => (string) $memberRowId,
    ];
}

/*
|--------------------------------------------------------------------------
| Member Service Overage Checkout
|--------------------------------------------------------------------------
*/
if ($mode === 'service_overage') {
    $effectiveUserId = $portalUserId > 0 ? $portalUserId : $memberRowId;

    if ($effectiveUserId <= 0) {
        redirectTo('login.php');
    }

    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $serviceContext = null;

    if ($bookingId > 0) {
        $bookingRow = load_booking_row($pdo, $bookingId);

        if (!$bookingRow || !booking_belongs_to_user($bookingRow, $effectiveUserId)) {
            failPage('That booking could not be found for this account.', 404, 'book-service.php');
        }

        $bookingMeta = decode_booking_meta_from_row($bookingRow);
        $serviceContext = build_service_overage_context_from_booking($bookingRow, $bookingMeta, $bookingId);
    }

    $sessionPortal = $_SESSION['service_payment_portal'] ?? null;
    if (is_array($sessionPortal)) {
        $sessionMemberId = (int) ($sessionPortal['member_id'] ?? 0);

        if ($sessionMemberId > 0 && $sessionMemberId === $effectiveUserId) {
            $sessionContext = [
                'booking_id' => (int) ($sessionPortal['booking_id'] ?? $bookingId),
                'service_type' => normalize_service_type((string) ($sessionPortal['service_type'] ?? '')),
                'quantity' => (int) ($sessionPortal['quantity'] ?? 0),
                'overage_units' => (int) ($sessionPortal['overage_units'] ?? 0),
                'unit_price' => round((float) ($sessionPortal['unit_price'] ?? 0), 2),
                'total_amount' => round((float) ($sessionPortal['total_amount'] ?? 0), 2),
                'booking_date' => trim((string) ($sessionPortal['booking_date'] ?? '')),
                'booking_time' => trim((string) ($sessionPortal['booking_time'] ?? '')),
                'pet_name' => trim((string) ($sessionPortal['pet_name'] ?? '')),
                'pet_size' => normalize_dog_size((string) ($sessionPortal['pet_size'] ?? '')),
                'duration_label' => trim((string) ($sessionPortal['duration_label'] ?? '')),
                'member_plan_slug' => trim((string) ($sessionPortal['member_plan_slug'] ?? '')),
                'credit_type' => normalize_credit_type((string) ($sessionPortal['credit_type'] ?? '')),
                'included_credits' => (int) ($sessionPortal['included_credits'] ?? 0),
                'remaining_credits' => (int) ($sessionPortal['remaining_credits'] ?? 0),
            ];

            if ($serviceContext === null) {
                $serviceContext = $sessionContext;
            } else {
                $serviceContext = merge_context($serviceContext, $sessionContext);
            }
        }
    }

    $postedContext = [
        'booking_id' => (int) ($_POST['booking_id'] ?? 0),
        'service_type' => normalize_service_type((string) ($_POST['service_type'] ?? '')),
        'quantity' => (int) ($_POST['quantity'] ?? 0),
        'overage_units' => (int) ($_POST['overage_units'] ?? 0),
        'unit_price' => round((float) ($_POST['unit_price'] ?? 0), 2),
        'total_amount' => round((float) ($_POST['total_amount'] ?? 0), 2),
        'booking_date' => trim((string) ($_POST['booking_date'] ?? '')),
        'booking_time' => trim((string) ($_POST['booking_time'] ?? '')),
        'pet_name' => trim((string) ($_POST['pet_name'] ?? '')),
        'pet_size' => normalize_dog_size((string) ($_POST['pet_size'] ?? '')),
        'duration_label' => trim((string) ($_POST['duration_label'] ?? '')),
        'member_plan_slug' => trim((string) ($_POST['member_plan_slug'] ?? '')),
        'credit_type' => normalize_credit_type((string) ($_POST['credit_type'] ?? '')),
        'included_credits' => (int) ($_POST['included_credits'] ?? 0),
        'remaining_credits' => (int) ($_POST['remaining_credits'] ?? 0),
    ];

    if ($serviceContext === null) {
        $serviceContext = $postedContext;
    } else {
        $serviceContext = merge_context($serviceContext, $postedContext);
    }

    $bookingId = (int) ($serviceContext['booking_id'] ?? 0);
    $serviceType = normalize_service_type((string) ($serviceContext['service_type'] ?? ''));
    $quantity = (int) ($serviceContext['quantity'] ?? 0);
    $overageUnits = (int) ($serviceContext['overage_units'] ?? 0);
    $unitPrice = (float) ($serviceContext['unit_price'] ?? 0);
    $amount = (float) ($serviceContext['total_amount'] ?? 0);
    $memberPlanSlug = trim((string) ($serviceContext['member_plan_slug'] ?? ''));
    $creditType = normalize_credit_type((string) ($serviceContext['credit_type'] ?? ''));
    $durationLabel = trim((string) ($serviceContext['duration_label'] ?? ''));
    $petName = trim((string) ($serviceContext['pet_name'] ?? ''));
    $petSize = normalize_dog_size((string) ($serviceContext['pet_size'] ?? ''));
    $bookingDate = trim((string) ($serviceContext['booking_date'] ?? ''));
    $bookingTime = trim((string) ($serviceContext['booking_time'] ?? ''));
    $includedCredits = (int) ($serviceContext['included_credits'] ?? 0);
    $remainingCredits = (int) ($serviceContext['remaining_credits'] ?? 0);

    if ($overageUnits <= 0 && $quantity > 0) {
        $overageUnits = $quantity;
    }

    if ($quantity <= 0 && $overageUnits > 0) {
        $quantity = $overageUnits;
    }

    if ($amount <= 0 && $unitPrice > 0 && $overageUnits > 0) {
        $amount = $unitPrice * $overageUnits;
    }

    if ($serviceType === '' || $bookingId <= 0) {
        failPage('Invalid service overage details.', 400, 'book-service.php');
    }

    if ($overageUnits <= 0 || $amount <= 0) {
        failPage('Invalid member overage amount.', 400, 'payment-portal.php?mode=service_overage&booking_id=' . rawurlencode((string) $bookingId));
    }

    $amountCents = (int) round($amount * 100);

    if ($amountCents <= 0 || $amountCents > 5000000) {
        failPage('Invalid payment amount.', 400, 'payment-portal.php?mode=service_overage&booking_id=' . rawurlencode((string) $bookingId));
    }

    $checkoutName = service_label_from_type($serviceType) . ' Overage';

    if ($durationLabel !== '') {
        $checkoutName .= ' - ' . $durationLabel;
    }

    if ($petName !== '') {
        $checkoutName .= ' - ' . $petName;
    }

    $cancelUrl = buildCancelUrl('service_overage', $baseUrl, ['booking_id' => $bookingId]);
    $customerEmail = current_member_email($pdo);

    $metadata = [
        'mode' => 'service_overage',
        'member_id' => (string) $effectiveUserId,
        'booking_id' => (string) $bookingId,
        'service_type' => $serviceType,
        'credit_type' => $creditType,
        'member_plan_slug' => $memberPlanSlug,
        'overage_units' => (string) $overageUnits,
        'unit_price' => number_format($unitPrice, 2, '.', ''),
        'total_amount' => number_format($amount, 2, '.', ''),
        'included_credits' => (string) $includedCredits,
        'remaining_credits' => (string) $remainingCredits,
        'pet_name' => $petName,
        'pet_size' => $petSize,
        'booking_date' => $bookingDate,
        'booking_time' => $bookingTime,
    ];
}

/*
|--------------------------------------------------------------------------
| Non-Member Checkout
|--------------------------------------------------------------------------
*/
if ($mode === 'non_member') {
    $sessionPortal = $_SESSION['non_member_payment_portal'] ?? null;
    if (!is_array($sessionPortal)) {
        $sessionPortal = [];
    }

    $requestId = (int) ($sessionPortal['request_id'] ?? 0);
    $serviceType = normalize_service_type((string) ($sessionPortal['service_type'] ?? $_POST['service_type'] ?? ''));
    $dogSize = normalize_dog_size((string) ($sessionPortal['dog_size'] ?? $_POST['dog_size'] ?? ''));
    $dateStart = trim((string) ($sessionPortal['date_start'] ?? $_POST['date_start'] ?? ''));
    $dateEnd = trim((string) ($sessionPortal['date_end'] ?? $_POST['date_end'] ?? ''));
    $walkDuration = (int) ($sessionPortal['walk_duration'] ?? $_POST['walk_duration'] ?? 0);
    $dropInHours = (int) ($sessionPortal['drop_in_hours'] ?? $_POST['drop_in_hours'] ?? 1);
    $dropInAddWalk = (string) ($sessionPortal['drop_in_add_walk'] ?? $_POST['drop_in_add_walk'] ?? '') === '1';
    $daycareProvideFood = (string) ($sessionPortal['daycare_provide_food'] ?? $_POST['daycare_provide_food'] ?? '') === '1';
    $daycareExtraWalks = (int) ($sessionPortal['daycare_extra_walks'] ?? $_POST['daycare_extra_walks'] ?? 0);
    $sittingExtraWalks = (int) ($sessionPortal['sitting_extra_walks'] ?? $_POST['sitting_extra_walks'] ?? 0);
    $fullName = trim((string) ($sessionPortal['full_name'] ?? $_POST['full_name'] ?? ''));
    $email = trim((string) ($sessionPortal['email'] ?? $_POST['email'] ?? ''));
    $phone = trim((string) ($sessionPortal['phone'] ?? $_POST['phone'] ?? ''));
    $dogName = trim((string) ($sessionPortal['dog_name'] ?? $_POST['dog_name'] ?? ''));
    $pricingType = trim((string) ($sessionPortal['pricing_type'] ?? $_POST['pricing_type'] ?? 'non_member'));
    $discountLabel = trim((string) ($sessionPortal['discount_label'] ?? $_POST['discount_label'] ?? 'standard_non_member'));

    if ($serviceType === '') {
        failPage('Invalid non-member booking details.', 400, 'non-member-booking.php');
    }

    try {
        if ($serviceType === 'walk') {
            if (!valid_walk_duration($walkDuration)) {
                throw new Exception('Invalid walk duration.');
            }

            $pricing = dd_get_service_pricing('walk', false, [
                'duration_minutes' => $walkDuration,
            ]);
        } elseif ($serviceType === 'drop_in') {
            if (!in_array($dropInHours, [1, 2], true)) {
                throw new Exception('Invalid drop-in length.');
            }

            $pricing = dd_get_service_pricing('drop_in', false, [
                'quantity' => $dropInHours,
                'add_walk' => $dropInAddWalk,
            ]);
        } elseif ($serviceType === 'daycare') {
            $pricing = dd_get_service_pricing('daycare', false, [
                'provide_food' => $daycareProvideFood,
                'extra_walks' => $daycareExtraWalks,
            ]);
        } elseif ($serviceType === 'sitting') {
            $pricing = dd_get_service_pricing('sitting', false, [
                'extra_walks' => $sittingExtraWalks,
            ]);
        } elseif ($serviceType === 'boarding') {
            if ($dogSize === '' || $dateStart === '' || $dateEnd === '') {
                throw new Exception('Boarding requires size, check-in date, and check-out date.');
            }

            $nights = dd_calculate_boarding_nights($dateStart, $dateEnd);

            if ($nights <= 0) {
                throw new Exception('Boarding requires a valid date range.');
            }

            $pricing = dd_get_service_pricing('boarding', false, [
                'dog_size' => $dogSize,
                'quantity' => $nights,
            ]);
        } else {
            throw new Exception('Unsupported non-member service.');
        }
    } catch (Throwable $e) {
        failPage($e->getMessage(), 400, 'non-member-booking.php');
    }

    $amount = (float) ($pricing['total_price'] ?? 0);
    $unitPrice = (float) ($pricing['unit_price'] ?? 0);
    $quantity = (int) ($pricing['quantity'] ?? 0);

    if ($amount <= 0 || $quantity <= 0) {
        failPage('Invalid non-member total.', 400, 'non-member-booking.php');
    }

    $amountCents = (int) round($amount * 100);

    if ($amountCents <= 0 || $amountCents > 5000000) {
        failPage('Invalid payment amount.', 400, 'non-member-booking.php');
    }

    $checkoutName = service_label_from_type($serviceType);

    if ($serviceType === 'walk' && $walkDuration > 0) {
        $checkoutName .= ' - ' . $walkDuration . ' Minutes';
    }

    if ($serviceType === 'boarding' && $dogSize !== '') {
        $checkoutName .= ' - ' . ucfirst($dogSize);
    }

    $cancelUrl = buildCancelUrl('non_member', $baseUrl, ['request_id' => $requestId > 0 ? (string) $requestId : null]);

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $customerEmail = $email;
    }

    $metadata = [
        'mode' => 'non_member',
        'request_id' => (string) $requestId,
        'service_type' => $serviceType,
        'pricing_type' => $pricingType,
        'discount_label' => $discountLabel,
        'quantity' => (string) $quantity,
        'unit_price' => number_format($unitPrice, 2, '.', ''),
        'total_amount' => number_format($amount, 2, '.', ''),
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'dog_name' => $dogName,
        'dog_size' => $dogSize,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
    ];
}

$metadata = sanitize_metadata($metadata);

/*
|--------------------------------------------------------------------------
| Stripe Checkout
|--------------------------------------------------------------------------
*/
try {
    $lastCheckoutTime = (int) ($_SESSION['last_checkout_time'] ?? 0);
    if ($lastCheckoutTime > 0 && (time() - $lastCheckoutTime) < 5) {
        failPage(
            'Duplicate checkout attempt detected. Please wait a moment and try again.',
            429,
            checkoutReturnUrlForMode($mode)
        );
    }
    $_SESSION['last_checkout_time'] = time();

    \Stripe\Stripe::setApiKey($stripeKey);

    $sessionParams = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'metadata' => $metadata,
        'payment_intent_data' => [
            'metadata' => $metadata,
        ],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $stripeCurrency,
                'unit_amount' => $amountCents,
                'product_data' => [
                    'name' => $checkoutName,
                ],
            ],
        ]],
    ];

    if ($customerEmail !== '') {
        $sessionParams['customer_email'] = $customerEmail;
    }

    $session = \Stripe\Checkout\Session::create($sessionParams);

    if (empty($session->url)) {
        throw new Exception('Stripe session failed.');
    }

    if ($mode === 'custom_plan') {
        $planId = (int) ($_POST['plan_id'] ?? 0);

        try {
            $pdo->prepare("
                UPDATE custom_plans
                SET payment_status = 'pending'
                WHERE id = :id AND member_id = :member_id
            ")->execute([
                ':id' => $planId,
                ':member_id' => $memberRowId,
            ]);
        } catch (Throwable $e) {
        }
    }

    if ($mode === 'service_overage') {
        $bookingId = (int) ($metadata['booking_id'] ?? 0);
        $effectiveUserId = $portalUserId > 0 ? $portalUserId : $memberRowId;
        mark_booking_pending($pdo, $bookingId, $effectiveUserId);
    }

    if ($mode === 'non_member') {
        $requestId = (int) ($metadata['request_id'] ?? 0);
        mark_non_member_pending($pdo, $requestId);
    }

    redirectTo((string) $session->url);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe API error: ' . $e->getMessage());
    failPage(
        'Checkout is temporarily unavailable. Please try again in a few minutes.',
        500,
        checkoutReturnUrlForMode($mode)
    );
} catch (Throwable $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    failPage(
        'Checkout could not be started right now. Please try again in a few minutes.',
        500,
        checkoutReturnUrlForMode($mode)
    );
}