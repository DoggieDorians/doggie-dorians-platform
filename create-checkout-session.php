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

function failPage(string $message, int $statusCode = 400, string $returnUrl = 'index.php'): void
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
    <body style="font-family: Arial; background:#111; color:#fff; padding:40px;">
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

function getBaseUrl(): string
{
    if (function_exists('dd_stripe_public_base_url')) {
        return rtrim((string) dd_stripe_public_base_url(), '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    return $host ? $scheme . '://' . $host : '';
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
        default => '',
    };
}

function valid_walk_duration(int $minutes): bool
{
    return in_array($minutes, [15, 20, 30, 45, 60], true);
}

function current_member_id(PDO $pdo): int
{
    $member = currentMember($pdo);
    return (int)($member['id'] ?? 0);
}

function current_member_email(PDO $pdo): string
{
    $member = currentMember($pdo);

    $candidates = [
        (string)($member['email'] ?? ''),
        (string)($member['member_email'] ?? ''),
        (string)($member['user_email'] ?? ''),
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failPage('Invalid request method.', 405);
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
$postCsrf = (string)($_POST['csrf_token'] ?? '');

if ($sessionCsrf === '' || $postCsrf === '' || !hash_equals($sessionCsrf, $postCsrf)) {
    failPage('Session expired. Try again.', 403);
}

/*
|--------------------------------------------------------------------------
| Shared Stripe Setup
|--------------------------------------------------------------------------
*/
$stripeKey = getStripeSecretKey();
$baseUrl = getBaseUrl();

if ($stripeKey === '' || $baseUrl === '') {
    failPage('Payment system not configured.', 500);
}

$mode = strtolower(trim((string)($_POST['mode'] ?? 'custom_plan')));
$allowedModes = ['custom_plan', 'service_overage', 'non_member'];

if (!in_array($mode, $allowedModes, true)) {
    failPage('Invalid checkout mode.', 400);
}

$memberId = current_member_id($pdo);
$checkoutName = '';
$amount = 0.00;
$amountCents = 0;
$successUrl = $baseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}&mode=' . urlencode($mode);
$cancelUrl = $baseUrl . '/payment-cancel.php?mode=' . urlencode($mode);
$metadata = [];
$customerEmail = '';

/*
|--------------------------------------------------------------------------
| Custom Plan Checkout
|--------------------------------------------------------------------------
*/
if ($mode === 'custom_plan') {
    if ($memberId <= 0) {
        header('Location: login.php');
        exit;
    }

    $planId = (int)($_POST['plan_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT *
        FROM custom_plans
        WHERE id = :id AND member_id = :member_id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $planId,
        ':member_id' => $memberId,
    ]);

    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        failPage('Plan not found.', 404, 'customize-plan.php');
    }

    $amount = (float)($plan['monthly_total'] ?? 0);

    if ($amount <= 0) {
        failPage('Invalid plan amount.', 400, 'customize-plan.php');
    }

    $amountCents = (int) round($amount * 100);

    if ($amountCents <= 0 || $amountCents > 5000000) {
        failPage('Invalid payment amount.', 400, 'customize-plan.php');
    }

    $checkoutName = (string)($plan['plan_name'] ?? 'Custom Plan');
    $cancelUrl = $baseUrl . '/payment-cancel.php?mode=custom_plan&plan_id=' . $planId;
    $customerEmail = current_member_email($pdo);

    $metadata = [
        'mode' => 'custom_plan',
        'custom_plan_id' => (string)$planId,
        'member_id' => (string)$memberId,
    ];
}

/*
|--------------------------------------------------------------------------
| Member Service Overage Checkout
|--------------------------------------------------------------------------
*/
if ($mode === 'service_overage') {
    if ($memberId <= 0) {
        header('Location: login.php');
        exit;
    }

    $sessionPortal = $_SESSION['service_payment_portal'] ?? null;
    if (!is_array($sessionPortal)) {
        $sessionPortal = [];
    }

    $portalMemberId = (int)($sessionPortal['member_id'] ?? 0);
    if ($portalMemberId <= 0 || $portalMemberId !== $memberId) {
        failPage('Overage payment session not found.', 400, 'book-service.php');
    }

    $serviceType = normalize_service_type((string)($sessionPortal['service_type'] ?? $_POST['service_type'] ?? ''));
    $creditType = normalize_credit_type((string)($sessionPortal['credit_type'] ?? $_POST['credit_type'] ?? ''));
    $quantity = (int)($sessionPortal['quantity'] ?? $_POST['quantity'] ?? 0);
    $overageUnits = (int)($sessionPortal['overage_units'] ?? $_POST['overage_units'] ?? 0);
    $bookingId = (int)($sessionPortal['booking_id'] ?? $_POST['booking_id'] ?? 0);
    $memberPlanSlug = trim((string)($sessionPortal['member_plan_slug'] ?? $_POST['member_plan_slug'] ?? ''));
    $durationLabel = trim((string)($sessionPortal['duration_label'] ?? $_POST['duration_label'] ?? ''));
    $petName = trim((string)($sessionPortal['pet_name'] ?? $_POST['pet_name'] ?? ''));
    $petSize = normalize_dog_size((string)($sessionPortal['pet_size'] ?? $_POST['pet_size'] ?? ''));
    $bookingDate = trim((string)($sessionPortal['booking_date'] ?? $_POST['booking_date'] ?? ''));
    $bookingTime = trim((string)($sessionPortal['booking_time'] ?? $_POST['booking_time'] ?? ''));
    $includedCredits = (int)($sessionPortal['included_credits'] ?? $_POST['included_credits'] ?? 0);
    $remainingCredits = (int)($sessionPortal['remaining_credits'] ?? $_POST['remaining_credits'] ?? 0);

    if ($serviceType === '') {
        failPage('Invalid service overage details.', 400, 'book-service.php');
    }

    if ($overageUnits <= 0) {
        $overageUnits = $quantity;
    }

    if ($overageUnits <= 0) {
        failPage('Invalid overage quantity.', 400, 'book-service.php');
    }

    try {
        if ($serviceType === 'walk') {
            $walkDuration = (int)$durationLabel;
            if (!valid_walk_duration($walkDuration)) {
                throw new Exception('Invalid walk duration.');
            }

            $pricing = dd_get_service_pricing('walk', true, [
                'duration_minutes' => $walkDuration,
                'quantity' => $overageUnits,
            ]);
        } elseif ($serviceType === 'daycare') {
            $pricing = dd_get_service_pricing('daycare', true, [
                'quantity' => $overageUnits,
            ]);
        } elseif ($serviceType === 'drop_in') {
            $pricing = dd_get_service_pricing('drop_in', true, [
                'quantity' => $overageUnits,
            ]);
        } elseif ($serviceType === 'boarding') {
            if ($petSize === '') {
                throw new Exception('Dog size is required for boarding overage.');
            }

            $pricing = dd_get_service_pricing('boarding', true, [
                'dog_size' => $petSize,
                'quantity' => $overageUnits,
            ]);
        } else {
            throw new Exception('Unsupported member overage service.');
        }
    } catch (Throwable $e) {
        failPage($e->getMessage(), 400, 'book-service.php');
    }

    $unitPrice = (float)($pricing['unit_price'] ?? 0);
    $amount = $unitPrice * $overageUnits;

    if ($unitPrice <= 0 || $amount <= 0) {
        failPage('Invalid member overage amount.', 400, 'book-service.php');
    }

    $amountCents = (int) round($amount * 100);

    if ($amountCents <= 0 || $amountCents > 5000000) {
        failPage('Invalid payment amount.', 400, 'book-service.php');
    }

    $checkoutName = service_label_from_type($serviceType) . ' Overage';

    if ($durationLabel !== '') {
        $checkoutName .= ' - ' . $durationLabel;
    }

    $cancelUrl = $baseUrl . '/payment-cancel.php?mode=service_overage';
    if ($bookingId > 0) {
        $cancelUrl .= '&booking_id=' . $bookingId;
    }

    $customerEmail = current_member_email($pdo);

    $metadata = [
        'mode' => 'service_overage',
        'member_id' => (string)$memberId,
        'booking_id' => (string)$bookingId,
        'service_type' => $serviceType,
        'credit_type' => $creditType,
        'member_plan_slug' => $memberPlanSlug,
        'overage_units' => (string)$overageUnits,
        'unit_price' => number_format($unitPrice, 2, '.', ''),
        'total_amount' => number_format($amount, 2, '.', ''),
        'included_credits' => (string)$includedCredits,
        'remaining_credits' => (string)$remainingCredits,
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

    $requestId = (int)($sessionPortal['request_id'] ?? 0);
    $serviceType = normalize_service_type((string)($sessionPortal['service_type'] ?? $_POST['service_type'] ?? ''));
    $dogSize = normalize_dog_size((string)($sessionPortal['dog_size'] ?? $_POST['dog_size'] ?? ''));
    $dateStart = trim((string)($sessionPortal['date_start'] ?? $_POST['date_start'] ?? ''));
    $dateEnd = trim((string)($sessionPortal['date_end'] ?? $_POST['date_end'] ?? ''));
    $walkDuration = (int)($sessionPortal['walk_duration'] ?? $_POST['walk_duration'] ?? 0);
    $dropInHours = (int)($sessionPortal['drop_in_hours'] ?? $_POST['drop_in_hours'] ?? 1);
    $dropInAddWalk = (string)($sessionPortal['drop_in_add_walk'] ?? $_POST['drop_in_add_walk'] ?? '') === '1';
    $daycareProvideFood = (string)($sessionPortal['daycare_provide_food'] ?? $_POST['daycare_provide_food'] ?? '') === '1';
    $daycareExtraWalks = (int)($sessionPortal['daycare_extra_walks'] ?? $_POST['daycare_extra_walks'] ?? 0);
    $sittingExtraWalks = (int)($sessionPortal['sitting_extra_walks'] ?? $_POST['sitting_extra_walks'] ?? 0);
    $fullName = trim((string)($sessionPortal['full_name'] ?? $_POST['full_name'] ?? ''));
    $email = trim((string)($sessionPortal['email'] ?? $_POST['email'] ?? ''));
    $phone = trim((string)($sessionPortal['phone'] ?? $_POST['phone'] ?? ''));
    $dogName = trim((string)($sessionPortal['dog_name'] ?? $_POST['dog_name'] ?? ''));
    $pricingType = trim((string)($sessionPortal['pricing_type'] ?? $_POST['pricing_type'] ?? 'non_member'));
    $discountLabel = trim((string)($sessionPortal['discount_label'] ?? $_POST['discount_label'] ?? 'standard_non_member'));

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

    $amount = (float)($pricing['total_price'] ?? 0);
    $unitPrice = (float)($pricing['unit_price'] ?? 0);
    $quantity = (int)($pricing['quantity'] ?? 0);

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

    $cancelUrl = $baseUrl . '/payment-cancel.php?mode=non_member';
    if ($requestId > 0) {
        $cancelUrl .= '&request_id=' . $requestId;
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $customerEmail = $email;
    }

    $metadata = [
        'mode' => 'non_member',
        'request_id' => (string)$requestId,
        'service_type' => $serviceType,
        'pricing_type' => $pricingType,
        'discount_label' => $discountLabel,
        'quantity' => (string)$quantity,
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

/*
|--------------------------------------------------------------------------
| Stripe Checkout
|--------------------------------------------------------------------------
*/
try {
    $lastCheckoutTime = (int)($_SESSION['last_checkout_time'] ?? 0);
    if ($lastCheckoutTime > 0 && (time() - $lastCheckoutTime) < 5) {
        failPage(
            'Duplicate checkout attempt detected. Please wait a moment and try again.',
            429,
            $mode === 'non_member' ? 'non-member-payment-portal.php' : 'payment-portal.php'
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
                'currency' => 'usd',
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
        $planId = (int)($_POST['plan_id'] ?? 0);

        $pdo->prepare("
            UPDATE custom_plans
            SET payment_status = 'pending'
            WHERE id = :id AND member_id = :member_id
        ")->execute([
            ':id' => $planId,
            ':member_id' => $memberId
        ]);
    }

    if ($mode === 'service_overage') {
        $bookingId = (int)($metadata['booking_id'] ?? 0);

        if ($bookingId > 0) {
            try {
                $pdo->prepare("
                    UPDATE bookings
                    SET payment_status = 'pending'
                    WHERE id = :id AND member_id = :member_id
                ")->execute([
                    ':id' => $bookingId,
                    ':member_id' => $memberId,
                ]);
            } catch (Throwable $e) {
                // Do not block checkout if this column/table state differs.
            }
        }
    }

    if ($mode === 'non_member') {
        $requestId = (int)($metadata['request_id'] ?? 0);

        if ($requestId > 0) {
            try {
                $pdo->prepare("
                    UPDATE non_member_bookings
                    SET status = 'Pending Payment'
                    WHERE id = :id
                ")->execute([
                    ':id' => $requestId,
                ]);
            } catch (Throwable $e) {
                // Do not block checkout if schema differs.
            }
        }
    }

    header('Location: ' . $session->url);
    exit;

} catch (Throwable $e) {
    error_log('Stripe error: ' . $e->getMessage());
    failPage('Checkout failed. Try again.', 500, $mode === 'non_member' ? 'non-member-payment-portal.php' : 'payment-portal.php');
}