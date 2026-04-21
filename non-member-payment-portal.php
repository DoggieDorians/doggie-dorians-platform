<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}

if (!function_exists('portal_redirect')) {
    function portal_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

function normalize_service_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'drop_in', 'drop-in', 'dropin', 'drop in' => 'drop_in',
        'daycare', 'day care' => 'daycare',
        'boarding', 'board' => 'boarding',
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

function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
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

function safe_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
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
    } catch (Exception $e) {
        return null;
    }
}

function find_non_member_request_record(PDO $pdo, int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $tables = [
        [
            'table' => 'public_booking_requests',
            'id_candidates' => ['id', 'request_id'],
        ],
        [
            'table' => 'non_member_bookings',
            'id_candidates' => ['id', 'request_id'],
        ],
    ];

    foreach ($tables as $config) {
        $table = (string) $config['table'];
        $columns = get_table_columns($pdo, $table);

        if (empty($columns)) {
            continue;
        }

        $idColumn = first_existing_column($columns, $config['id_candidates']);
        if ($idColumn === null) {
            continue;
        }

        $row = safe_fetch_one(
            $pdo,
            "SELECT * FROM {$table} WHERE {$idColumn} = :id LIMIT 1",
            [':id' => $requestId]
        );

        if (is_array($row)) {
            return [
                'table' => $table,
                'row' => $row,
            ];
        }
    }

    return null;
}

function boolish_label(string $value, string $trueLabel = 'Yes', string $falseLabel = 'No'): string
{
    $value = strtolower(trim($value));

    if (in_array($value, ['1', 'true', 'yes', 'y', 'on'], true)) {
        return $trueLabel;
    }

    if (in_array($value, ['0', 'false', 'no', 'n', 'off'], true)) {
        return $falseLabel;
    }

    return trim($value) !== '' ? $value : $falseLabel;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

$sessionPortal = $_SESSION['non_member_payment_portal'] ?? null;
if (!is_array($sessionPortal)) {
    $sessionPortal = null;
}

$requestId = (int) ($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$fullName = trim((string) ($_GET['full_name'] ?? $_POST['full_name'] ?? ''));
$email = trim((string) ($_GET['email'] ?? $_POST['email'] ?? ''));
$phone = trim((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''));
$dogName = trim((string) ($_GET['dog_name'] ?? $_POST['dog_name'] ?? ''));
$dogSize = normalize_dog_size((string) ($_GET['dog_size'] ?? $_POST['dog_size'] ?? ''));
$serviceType = normalize_service_type((string) ($_GET['service_type'] ?? $_POST['service_type'] ?? ''));
$dateStart = trim((string) ($_GET['date_start'] ?? $_POST['date_start'] ?? ''));
$dateEnd = trim((string) ($_GET['date_end'] ?? $_POST['date_end'] ?? ''));
$walkDuration = trim((string) ($_GET['walk_duration'] ?? $_POST['walk_duration'] ?? ''));
$pricingType = trim((string) ($_GET['pricing_type'] ?? $_POST['pricing_type'] ?? 'non_member'));
$discountLabel = trim((string) ($_GET['discount_label'] ?? $_POST['discount_label'] ?? 'standard_non_member'));
$quantity = (int) ($_GET['quantity'] ?? $_POST['quantity'] ?? 0);
$unitPrice = (float) ($_GET['unit_price'] ?? $_POST['unit_price'] ?? 0);
$totalAmount = (float) ($_GET['total_amount'] ?? $_POST['total_amount'] ?? $_GET['estimated_price'] ?? $_POST['estimated_price'] ?? 0);
$originalTotalAmount = (float) ($_GET['original_total_amount'] ?? $_POST['original_total_amount'] ?? 0);
$discountAmount = (float) ($_GET['discount_amount'] ?? $_POST['discount_amount'] ?? 0);
$ambassadorCode = trim((string) ($_GET['ambassador_code'] ?? $_POST['ambassador_code'] ?? ''));

$dropInHours = trim((string) ($_GET['drop_in_hours'] ?? $_POST['drop_in_hours'] ?? ''));
$dropInAddWalk = trim((string) ($_GET['drop_in_add_walk'] ?? $_POST['drop_in_add_walk'] ?? ''));
$daycareProvideFood = trim((string) ($_GET['daycare_provide_food'] ?? $_POST['daycare_provide_food'] ?? ''));
$daycareExtraWalks = trim((string) ($_GET['daycare_extra_walks'] ?? $_POST['daycare_extra_walks'] ?? ''));
$sittingExtraWalks = trim((string) ($_GET['sitting_extra_walks'] ?? $_POST['sitting_extra_walks'] ?? ''));

if ($sessionPortal !== null) {
    $requestId = (int) ($sessionPortal['request_id'] ?? $requestId);
    $fullName = trim((string) ($sessionPortal['full_name'] ?? $fullName));
    $email = trim((string) ($sessionPortal['email'] ?? $email));
    $phone = trim((string) ($sessionPortal['phone'] ?? $phone));
    $dogName = trim((string) ($sessionPortal['dog_name'] ?? $dogName));
    $dogSize = normalize_dog_size((string) ($sessionPortal['dog_size'] ?? $dogSize));
    $serviceType = normalize_service_type((string) ($sessionPortal['service_type'] ?? $serviceType));
    $dateStart = trim((string) ($sessionPortal['date_start'] ?? $dateStart));
    $dateEnd = trim((string) ($sessionPortal['date_end'] ?? $dateEnd));
    $walkDuration = trim((string) ($sessionPortal['walk_duration'] ?? $walkDuration));
    $pricingType = trim((string) ($sessionPortal['pricing_type'] ?? $pricingType));
    $discountLabel = trim((string) ($sessionPortal['discount_label'] ?? $discountLabel));
    $quantity = (int) ($sessionPortal['quantity'] ?? $quantity);
    $unitPrice = (float) ($sessionPortal['unit_price'] ?? $unitPrice);
    $totalAmount = (float) ($sessionPortal['total_amount'] ?? $totalAmount);
    $originalTotalAmount = (float) ($sessionPortal['original_total_amount'] ?? $originalTotalAmount);
    $discountAmount = (float) ($sessionPortal['discount_amount'] ?? $discountAmount);
    $ambassadorCode = trim((string) ($sessionPortal['ambassador_code'] ?? $ambassadorCode));
    $dropInHours = trim((string) ($sessionPortal['drop_in_hours'] ?? $dropInHours));
    $dropInAddWalk = trim((string) ($sessionPortal['drop_in_add_walk'] ?? $dropInAddWalk));
    $daycareProvideFood = trim((string) ($sessionPortal['daycare_provide_food'] ?? $daycareProvideFood));
    $daycareExtraWalks = trim((string) ($sessionPortal['daycare_extra_walks'] ?? $daycareExtraWalks));
    $sittingExtraWalks = trim((string) ($sessionPortal['sitting_extra_walks'] ?? $sittingExtraWalks));
}

if ($requestId > 0) {
    $record = find_non_member_request_record($pdo, $requestId);

    if ($record !== null) {
        $requestRow = $record['row'];

        $fullName = trim((string) ($requestRow['full_name'] ?? $fullName));
        $email = trim((string) ($requestRow['email'] ?? $email));
        $phone = trim((string) ($requestRow['phone'] ?? $phone));
        $dogName = trim((string) ($requestRow['dog_name'] ?? $dogName));
        $dogSize = normalize_dog_size((string) ($requestRow['dog_size'] ?? $dogSize));
        $serviceType = normalize_service_type((string) ($requestRow['service_type'] ?? $serviceType));
        $dateStart = trim((string) ($requestRow['date_start'] ?? $dateStart));
        $dateEnd = trim((string) ($requestRow['date_end'] ?? $dateEnd));
        $walkDuration = trim((string) ($requestRow['walk_duration'] ?? $walkDuration));
        $pricingType = trim((string) ($requestRow['pricing_type'] ?? $pricingType));
        $discountLabel = trim((string) ($requestRow['discount_label'] ?? $discountLabel));
        $quantity = (int) ($requestRow['quantity'] ?? $quantity);
        $unitPrice = (float) ($requestRow['unit_price'] ?? $unitPrice);
        $totalAmount = (float) ($requestRow['estimated_price'] ?? $requestRow['total_amount'] ?? $totalAmount);
        $originalTotalAmount = (float) ($requestRow['original_price'] ?? $requestRow['original_amount'] ?? $originalTotalAmount);
        $discountAmount = (float) ($requestRow['discount_amount'] ?? $requestRow['ambassador_discount_amount'] ?? $discountAmount);
        $ambassadorCode = trim((string) ($requestRow['ambassador_code'] ?? $requestRow['referral_code'] ?? $ambassadorCode));
        $dropInHours = trim((string) ($requestRow['drop_in_hours'] ?? $dropInHours));
        $dropInAddWalk = trim((string) ($requestRow['drop_in_add_walk'] ?? $dropInAddWalk));
        $daycareProvideFood = trim((string) ($requestRow['daycare_provide_food'] ?? $daycareProvideFood));
        $daycareExtraWalks = trim((string) ($requestRow['daycare_extra_walks'] ?? $daycareExtraWalks));
        $sittingExtraWalks = trim((string) ($requestRow['sitting_extra_walks'] ?? $sittingExtraWalks));
    }
}

if ($quantity <= 0 && $unitPrice > 0 && $totalAmount > 0) {
    $quantity = (int) max(1, round($totalAmount / $unitPrice));
}

if ($totalAmount <= 0 && $unitPrice > 0 && $quantity > 0) {
    $totalAmount = $unitPrice * $quantity;
}

if ($originalTotalAmount <= 0) {
    $originalTotalAmount = $totalAmount;
}

if (!in_array($serviceType, ['walk', 'drop_in', 'sitting'], true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Daycare and boarding are currently included only through founder packages while availability remains.';
    portal_redirect('non-member-booking.php');
}

if ($serviceType === '' || $totalAmount <= 0) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'No non-member payment details were found for checkout.';
    portal_redirect('non-member-booking.php');
}

$serviceTypeMap = [
    'walk' => 'Walk',
    'drop_in' => 'Drop-In',
    'sitting' => 'Pet Sitting',
];

$serviceLabel = $serviceTypeMap[$serviceType] ?? ucwords(str_replace(['_', '-'], ' ', $serviceType));
$dogSizeLabel = $dogSize !== '' ? ucwords(str_replace(['_', '-'], ' ', $dogSize)) : 'Not provided';

$serviceDescription = $serviceLabel;
if ($walkDuration !== '') {
    $serviceDescription .= ' · ' . $walkDuration;
}
if ($dogName !== '') {
    $serviceDescription .= ' · ' . $dogName;
}
if ($dogSize !== '' && $serviceType === 'walk') {
    $serviceDescription .= ' · ' . $dogSizeLabel;
}

$serviceDetails = [];

if ($dateStart !== '') {
    $serviceDetails[] = ['label' => 'Start Date', 'value' => $dateStart];
}
if ($walkDuration !== '') {
    $serviceDetails[] = ['label' => 'Walk Duration', 'value' => $walkDuration];
}
if ($dropInHours !== '') {
    $serviceDetails[] = ['label' => 'Drop-In Hours', 'value' => $dropInHours];
}
if ($dropInAddWalk !== '') {
    $serviceDetails[] = ['label' => 'Walk Add-On', 'value' => boolish_label($dropInAddWalk)];
}
if ($sittingExtraWalks !== '') {
    $serviceDetails[] = ['label' => 'Sitting Extra Walks', 'value' => $sittingExtraWalks];
}
if ($serviceType === 'sitting') {
    $serviceDetails[] = ['label' => 'Included Walk', 'value' => '1 × 30 min'];
}

$summaryRows = [
    ['label' => 'Client', 'value' => $fullName !== '' ? $fullName : 'Guest Client'],
    ['label' => 'Email', 'value' => $email !== '' ? $email : 'Not provided'],
    ['label' => 'Dog', 'value' => $dogName !== '' ? $dogName : 'Not provided'],
    ['label' => 'Dog Size', 'value' => $dogSizeLabel],
    ['label' => 'Service Type', 'value' => $serviceLabel],
    ['label' => 'Pricing Type', 'value' => $pricingType !== '' ? ucwords(str_replace('_', ' ', $pricingType)) : 'Non Member'],
    ['label' => 'Rate', 'value' => $unitPrice > 0 ? money_fmt($unitPrice) : 'Calculated Total'],
    ['label' => 'Quantity', 'value' => (string) max(1, $quantity)],
];

if ($discountAmount > 0) {
    $summaryRows[] = ['label' => 'Original Total', 'value' => money_fmt($originalTotalAmount)];
    $summaryRows[] = ['label' => 'Discount', 'value' => '-' . money_fmt($discountAmount)];
}

if ($ambassadorCode !== '') {
    $summaryRows[] = ['label' => 'Ambassador Code', 'value' => $ambassadorCode];
}

if ($discountLabel !== '') {
    $summaryRows[] = ['label' => 'Price Label', 'value' => ucwords(str_replace('_', ' ', $discountLabel))];
}

if ($requestId > 0) {
    $summaryRows[] = ['label' => 'Request ID', 'value' => '#' . $requestId];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Member Payment Portal | Doggie Dorian’s</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(212, 175, 55, 0.12), transparent 34%),
                linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
            color: #f4f1ea;
        }

        .payment-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 20px 22px 0;
        }

        .topbar-inner {
            max-width: 1160px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            color: #f4f1ea;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand span {
            color: #d4af37;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #f4f1ea;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.95rem;
            transition: 0.2s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.08);
        }

        .payment-main {
            flex: 1;
            padding: 26px 18px 64px;
        }

        .payment-shell {
            max-width: 1160px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
            align-items: start;
        }

        .hero-card,
        .summary-card,
        .services-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.40);
            backdrop-filter: blur(8px);
        }

        .hero-card::before,
        .summary-card::before,
        .services-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(212,175,55,0.08), transparent 35%);
            pointer-events: none;
        }

        .hero-card {
            padding: 32px 28px;
            margin-bottom: 24px;
        }

        .hero-badge {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
            background: rgba(212,175,55,0.14);
            color: #f2d471;
            border: 1px solid rgba(212,175,55,0.25);
        }

        .hero-title {
            position: relative;
            z-index: 1;
            margin: 0 0 10px;
            font-size: 2.55rem;
            line-height: 1.04;
            color: #fff;
        }

        .hero-text {
            position: relative;
            z-index: 1;
            margin: 0;
            color: rgba(244,241,234,0.78);
            line-height: 1.75;
            max-width: 720px;
            font-size: 1rem;
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-top: 24px;
        }

        .hero-box {
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .hero-box-label {
            display: block;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            color: rgba(244,241,234,0.56);
            margin-bottom: 8px;
        }

        .hero-box-value {
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
        }

        .summary-card {
            padding: 28px 24px;
            position: sticky;
            top: 20px;
        }

        .summary-title {
            position: relative;
            z-index: 1;
            margin: 0 0 16px;
            font-size: 1.45rem;
            color: #fff;
        }

        .total-panel {
            position: relative;
            z-index: 1;
            padding: 22px;
            border-radius: 22px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            text-align: center;
        }

        .total-label {
            font-size: 0.88rem;
            color: rgba(244,241,234,0.62);
            text-transform: uppercase;
            letter-spacing: 0.10em;
        }

        .total-value {
            margin-top: 10px;
            font-size: 3rem;
            line-height: 1;
            font-weight: 800;
            color: #f2d471;
        }

        .total-sub {
            margin-top: 10px;
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .summary-grid {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .summary-row-label {
            color: rgba(244,241,234,0.66);
        }

        .summary-row-value {
            color: #fff;
            font-weight: 700;
            text-align: right;
        }

        .checkout-box {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            padding: 18px;
            border-radius: 18px;
            background: rgba(212,175,55,0.11);
            border: 1px solid rgba(212,175,55,0.22);
        }

        .checkout-note {
            margin: 0 0 14px;
            color: #f3e5c7;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .payment-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            padding: 15px 20px;
            border-radius: 999px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .payment-button:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .secure-note {
            margin-top: 12px;
            font-size: 0.84rem;
            text-align: center;
            color: rgba(243,229,199,0.75);
        }

        .services-card {
            grid-column: 1 / 2;
            padding: 28px 24px;
        }

        .services-title {
            position: relative;
            z-index: 1;
            margin: 0 0 16px;
            font-size: 1.4rem;
            color: #fff;
        }

        .services-list {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 12px;
        }

        .service-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 15px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .service-label {
            color: #f4f1ea;
        }

        .service-qty {
            color: #f2d471;
            font-weight: 800;
        }

        .empty-item {
            position: relative;
            z-index: 1;
            padding: 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
            color: rgba(244,241,234,0.72);
        }

        .footer-actions {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .secondary-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.06);
            color: #ffffff;
            padding: 14px 18px;
            border-radius: 999px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.08);
            transition: 0.2s ease;
        }

        .secondary-button:hover {
            background: rgba(255,255,255,0.10);
        }

        @media (max-width: 980px) {
            .payment-shell {
                grid-template-columns: 1fr;
            }

            .summary-card {
                position: static;
                order: 2;
            }

            .services-card {
                grid-column: auto;
                order: 3;
            }
        }

        @media (max-width: 680px) {
            .topbar {
                padding: 16px 14px 0;
            }

            .topbar-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .brand {
                text-align: center;
            }

            .top-actions {
                justify-content: center;
            }

            .payment-main {
                padding: 20px 14px 52px;
            }

            .hero-card,
            .summary-card,
            .services-card {
                border-radius: 22px;
            }

            .hero-card,
            .summary-card,
            .services-card {
                padding: 24px 18px;
            }

            .hero-title {
                font-size: 2rem;
                text-align: center;
            }

            .hero-text {
                text-align: center;
            }

            .hero-grid {
                grid-template-columns: 1fr;
            }

            .total-value {
                font-size: 2.4rem;
            }

            .footer-actions {
                flex-direction: column;
            }

            .secondary-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="payment-page">
        <div class="topbar">
            <div class="topbar-inner">
                <a href="index.php" class="brand">Doggie <span>Dorian’s</span></a>

                <div class="top-actions">
                    <a href="non-member-booking.php" class="top-link">Booking</a>
                    <a href="pricing.php" class="top-link">Pricing</a>
                </div>
            </div>
        </div>

        <main class="payment-main">
            <div class="payment-shell">
                <div>
                    <section class="hero-card">
                        <div class="hero-badge">Non-Member Checkout</div>
                        <h1 class="hero-title">Review your non-member booking payment</h1>
                        <p class="hero-text">
                            This checkout page is dedicated to public and non-member bookings only for walks, drop-ins, and in-home sitting.
                            Daycare and boarding are currently included only through founder packages while availability remains,
                            with broader access for other clients coming soon.
                        </p>

                        <div class="hero-grid">
                            <div class="hero-box">
                                <span class="hero-box-label">Service</span>
                                <div class="hero-box-value"><?= h($serviceLabel) ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Client</span>
                                <div class="hero-box-value"><?= h($fullName !== '' ? $fullName : 'Guest Client') ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Dog</span>
                                <div class="hero-box-value"><?= h($dogName !== '' ? $dogName : 'Not provided') ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Pricing</span>
                                <div class="hero-box-value"><?= h($pricingType !== '' ? ucwords(str_replace('_', ' ', $pricingType)) : 'Standard Non-Member') ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="services-card">
                        <h2 class="services-title">Booking Details</h2>

                        <div class="services-list">
                            <div class="service-item">
                                <span class="service-label"><?= h($serviceDescription) ?></span>
                                <span class="service-qty"><?= (int) max(1, $quantity) ?></span>
                            </div>

                            <?php if (!empty($serviceDetails)): ?>
                                <?php foreach ($serviceDetails as $detail): ?>
                                    <div class="service-item">
                                        <span class="service-label"><?= h((string) $detail['label']) ?></span>
                                        <span class="service-qty"><?= h((string) $detail['value']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-item">Your booking details will appear here once service selections are passed into checkout.</div>
                            <?php endif; ?>
                        </div>

                        <div class="footer-actions">
                            <a href="non-member-booking.php" class="secondary-button">Back to Booking</a>
                            <a href="pricing.php" class="secondary-button">View Pricing</a>
                        </div>
                    </section>
                </div>

                <aside class="summary-card">
                    <h2 class="summary-title">Payment Summary</h2>

                    <div class="total-panel">
                        <div class="total-label">Total Due</div>
                        <div class="total-value"><?= h(money_fmt($totalAmount)) ?></div>
                        <div class="total-sub">
                            This total reflects your current non-member booking price only.
                            Founder package access, member credits, and member-only pricing do not apply on this page.
                        </div>
                    </div>

                    <div class="summary-grid">
                        <?php foreach ($summaryRows as $row): ?>
                            <div class="summary-row">
                                <span class="summary-row-label"><?= h((string) $row['label']) ?></span>
                                <span class="summary-row-value"><?= h((string) $row['value']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="checkout-box">
                        <p class="checkout-note">
                            You’ll be redirected to Stripe’s secure hosted checkout to complete this non-member payment.
                        </p>

                        <form method="POST" action="create-checkout-session.php">
                            <input type="hidden" name="mode" value="non_member">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                            <input type="hidden" name="full_name" value="<?= h($fullName) ?>">
                            <input type="hidden" name="email" value="<?= h($email) ?>">
                            <input type="hidden" name="phone" value="<?= h($phone) ?>">
                            <input type="hidden" name="dog_name" value="<?= h($dogName) ?>">
                            <input type="hidden" name="dog_size" value="">
                            <input type="hidden" name="service_type" value="<?= h($serviceType) ?>">
                            <input type="hidden" name="date_start" value="<?= h($dateStart) ?>">
                            <input type="hidden" name="date_end" value="">
                            <input type="hidden" name="walk_duration" value="<?= h($walkDuration) ?>">
                            <input type="hidden" name="pricing_type" value="<?= h($pricingType) ?>">
                            <input type="hidden" name="discount_label" value="<?= h($discountLabel) ?>">
                            <input type="hidden" name="quantity" value="<?= (int) max(1, $quantity) ?>">
                            <input type="hidden" name="unit_price" value="<?= h(number_format($unitPrice, 2, '.', '')) ?>">
                            <input type="hidden" name="original_total_amount" value="<?= h(number_format($originalTotalAmount, 2, '.', '')) ?>">
                            <input type="hidden" name="discount_amount" value="<?= h(number_format($discountAmount, 2, '.', '')) ?>">
                            <input type="hidden" name="total_amount" value="<?= h(number_format($totalAmount, 2, '.', '')) ?>">
                            <input type="hidden" name="estimated_price" value="<?= h(number_format($totalAmount, 2, '.', '')) ?>">
                            <input type="hidden" name="ambassador_code" value="<?= h($ambassadorCode) ?>">
                            <input type="hidden" name="drop_in_hours" value="<?= h($dropInHours) ?>">
                            <input type="hidden" name="drop_in_add_walk" value="<?= h($dropInAddWalk) ?>">
                            <input type="hidden" name="daycare_provide_food" value="0">
                            <input type="hidden" name="daycare_extra_walks" value="0">
                            <input type="hidden" name="sitting_extra_walks" value="<?= h($sittingExtraWalks) ?>">

                            <button type="submit" class="payment-button">
                                Pay <?= h(money_fmt($totalAmount)) ?> Securely
                            </button>
                        </form>

                        <div class="secure-note">Secure checkout powered by Stripe.</div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</body>
</html>