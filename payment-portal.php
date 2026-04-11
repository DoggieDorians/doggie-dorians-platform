<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}

if (!function_exists('portal_redirect')) {
    function portal_redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    $_SESSION['custom_plan_flash_type'] = 'error';
    $_SESSION['custom_plan_flash_message'] = 'Please sign in to access your payment portal.';
    portal_redirect('login.php');
}

$memberId = (int)($member['id'] ?? 0);

$paymentContext = null;
$portalMode = 'service_overage';

$requestedPortalMode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? '')));
if ($requestedPortalMode === '') {
    $requestedPortalMode = '';
}

$sessionOverage = $_SESSION['service_payment_portal'] ?? null;
if (!is_array($sessionOverage)) {
    $sessionOverage = null;
}

$serviceType = strtolower(trim((string)($_GET['service_type'] ?? $_POST['service_type'] ?? '')));
$quantity = (int)($_GET['quantity'] ?? $_POST['quantity'] ?? 0);
$unitPrice = (float)($_GET['unit_price'] ?? $_POST['unit_price'] ?? 0);
$totalAmount = (float)($_GET['total_amount'] ?? $_POST['total_amount'] ?? 0);
$bookingDate = trim((string)($_GET['booking_date'] ?? $_POST['booking_date'] ?? ''));
$bookingTime = trim((string)($_GET['booking_time'] ?? $_POST['booking_time'] ?? ''));
$petName = trim((string)($_GET['pet_name'] ?? $_POST['pet_name'] ?? ''));
$petSize = trim((string)($_GET['pet_size'] ?? $_POST['pet_size'] ?? ''));
$durationLabel = trim((string)($_GET['duration_label'] ?? $_POST['duration_label'] ?? ''));
$bookingReference = trim((string)($_GET['booking_reference'] ?? $_POST['booking_reference'] ?? ''));
$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$memberPlanSlug = trim((string)($_GET['member_plan_slug'] ?? $_POST['member_plan_slug'] ?? ''));
$creditType = trim((string)($_GET['credit_type'] ?? $_POST['credit_type'] ?? ''));
$includedCredits = (int)($_GET['included_credits'] ?? $_POST['included_credits'] ?? 0);
$remainingCredits = (int)($_GET['remaining_credits'] ?? $_POST['remaining_credits'] ?? 0);
$overageUnits = (int)($_GET['overage_units'] ?? $_POST['overage_units'] ?? 0);

if ($sessionOverage !== null) {
    $sessionMemberId = (int)($sessionOverage['member_id'] ?? 0);

    if ($sessionMemberId > 0 && $sessionMemberId === $memberId) {
        $serviceType = strtolower(trim((string)($sessionOverage['service_type'] ?? $serviceType)));
        $quantity = (int)($sessionOverage['quantity'] ?? $quantity);
        $unitPrice = (float)($sessionOverage['unit_price'] ?? $unitPrice);
        $totalAmount = (float)($sessionOverage['total_amount'] ?? $totalAmount);
        $bookingDate = trim((string)($sessionOverage['booking_date'] ?? $bookingDate));
        $bookingTime = trim((string)($sessionOverage['booking_time'] ?? $bookingTime));
        $petName = trim((string)($sessionOverage['pet_name'] ?? $petName));
        $petSize = trim((string)($sessionOverage['pet_size'] ?? $petSize));
        $durationLabel = trim((string)($sessionOverage['duration_label'] ?? $durationLabel));
        $bookingReference = trim((string)($sessionOverage['booking_reference'] ?? $bookingReference));
        $bookingId = (int)($sessionOverage['booking_id'] ?? $bookingId);
        $memberPlanSlug = trim((string)($sessionOverage['member_plan_slug'] ?? $memberPlanSlug));
        $creditType = trim((string)($sessionOverage['credit_type'] ?? $creditType));
        $includedCredits = (int)($sessionOverage['included_credits'] ?? $includedCredits);
        $remainingCredits = (int)($sessionOverage['remaining_credits'] ?? $remainingCredits);
        $overageUnits = (int)($sessionOverage['overage_units'] ?? $overageUnits);
    }
}

if ($overageUnits <= 0 && $quantity > 0) {
    $overageUnits = $quantity;
}

if ($totalAmount <= 0 && $unitPrice > 0 && $overageUnits > 0) {
    $totalAmount = $unitPrice * $overageUnits;
}

if ($requestedPortalMode === 'custom_plan') {
    $portalMode = 'custom_plan';
} elseif ($requestedPortalMode === 'service_overage') {
    $portalMode = 'service_overage';
} elseif ($sessionOverage !== null && $totalAmount > 0) {
    $portalMode = 'service_overage';
} elseif ($serviceType !== '' && $totalAmount > 0) {
    $portalMode = 'service_overage';
} else {
    $portalMode = 'custom_plan';
}

if ($portalMode === 'custom_plan') {
    $planId = (int)($_GET['plan_id'] ?? 0);

    if ($planId <= 0) {
        portal_redirect('customize-plan.php');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM custom_plans
        WHERE id = :id
          AND member_id = :member_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $planId,
        ':member_id' => $memberId,
    ]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $_SESSION['custom_plan_flash_type'] = 'error';
        $_SESSION['custom_plan_flash_message'] = 'That custom plan could not be found.';
        portal_redirect('customize-plan.php');
    }

    $planName = (string)($plan['plan_name'] ?? 'Custom Plan');
    $paymentMode = (string)($plan['payment_mode'] ?? 'upfront');
    $paymentStatus = (string)($plan['payment_status'] ?? 'pending');

    $finalTotal = (float)($plan['monthly_total'] ?? 0);
    $subtotalAmount = array_key_exists('subtotal_amount', $plan) ? (float)$plan['subtotal_amount'] : $finalTotal;
    $discountAmount = array_key_exists('discount_amount', $plan) ? (float)$plan['discount_amount'] : 0.00;
    $discountPercent = array_key_exists('discount_percent', $plan) ? (float)$plan['discount_percent'] : 0.00;

    $lineItems = [
        '15 Minute Walks' => (int)($plan['walks_15'] ?? 0),
        '20 Minute Walks' => (int)($plan['walks_20'] ?? 0),
        '30 Minute Walks' => (int)($plan['walks_30'] ?? 0),
        '45 Minute Walks' => (int)($plan['walks_45'] ?? 0),
        '60 Minute Walks' => (int)($plan['walks_60'] ?? 0),
        'Small Daycare Days' => (int)($plan['daycare_small'] ?? 0),
        'Medium Daycare Days' => (int)($plan['daycare_medium'] ?? 0),
        'Large Daycare Days' => (int)($plan['daycare_large'] ?? 0),
        'Small Boarding Nights' => (int)($plan['boarding_small'] ?? 0),
        'Medium Boarding Nights' => (int)($plan['boarding_medium'] ?? 0),
        'Large Boarding Nights' => (int)($plan['boarding_large'] ?? 0),
        'Drop-In Visits' => (int)($plan['drop_ins'] ?? 0),
    ];

    $visibleLineItems = [];
    foreach ($lineItems as $label => $qty) {
        if ($qty > 0) {
            $visibleLineItems[$label] = $qty;
        }
    }

    $paymentContext = [
        'mode' => 'custom_plan',
        'plan_id' => $planId,
        'hero_badge' => 'Secure Checkout',
        'hero_title' => 'Review your custom plan payment',
        'hero_text' => 'This payment portal reflects the custom plan saved to your account. Your exact total is pulled from the database on the server before Stripe checkout begins.',
        'hero_boxes' => [
            ['label' => 'Plan Name', 'value' => $planName],
            ['label' => 'Plan ID', 'value' => '#' . (int)$planId],
            ['label' => 'Payment Mode', 'value' => ucfirst($paymentMode)],
            ['label' => 'Current Status', 'value' => ucfirst($paymentStatus)],
        ],
        'services_title' => 'Included Services',
        'visible_line_items' => $visibleLineItems,
        'empty_line_text' => 'No line items were found for this plan.',
        'summary_title' => 'Payment Summary',
        'total_label' => 'Final Total Due',
        'total_value' => $finalTotal,
        'total_sub' => $discountAmount > 0
            ? 'Your member pricing subtotal qualified for a discount before checkout.'
            : 'No discount was applied to this plan total.',
        'summary_rows' => [
            ['label' => 'Subtotal', 'value' => money_fmt($subtotalAmount)],
            [
                'label' => 'Discount',
                'value' => $discountAmount > 0
                    ? '-' . money_fmt($discountAmount) . ($discountPercent > 0 ? ' (' . number_format($discountPercent, 0) . '%)' : '')
                    : '—'
            ],
        ],
        'checkout_note' => 'You’ll be redirected to Stripe’s secure hosted checkout to complete this payment.',
        'submit_label' => 'Pay ' . money_fmt($finalTotal) . ' Securely',
        'form_action' => 'create-checkout-session.php',
        'hidden_fields' => [
            'mode' => 'custom_plan',
            'plan_id' => (string)$planId,
            'csrf_token' => $csrfToken,
        ],
        'top_links' => [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'customize-plan.php', 'label' => 'Plans'],
        ],
        'footer_links' => [
            ['href' => 'dashboard.php', 'label' => 'Return to Dashboard'],
            ['href' => 'customize-plan.php', 'label' => 'Back to Plans'],
        ],
    ];
} else {
    $serviceTypeMap = [
        'walk' => 'Walk',
        'walks' => 'Walk',
        'daycare' => 'Daycare',
        'drop-in' => 'Drop-In',
        'drop_in' => 'Drop-In',
        'dropin' => 'Drop-In',
        'boarding' => 'Boarding',
        'sitting' => 'Sitting',
        'service' => 'Service',
    ];

    $serviceLabel = $serviceTypeMap[$serviceType] ?? ($serviceType !== '' ? ucwords(str_replace(['_', '-'], ' ', $serviceType)) : 'Service');
    $creditLabel = $creditType !== '' ? ucwords(str_replace(['_', '-'], ' ', $creditType)) : $serviceLabel . ' Credits';

    if ($overageUnits <= 0 && $quantity > 0) {
        $overageUnits = $quantity;
    }

    if ($quantity <= 0 && $overageUnits > 0) {
        $quantity = $overageUnits;
    }

    if ($totalAmount <= 0 || $overageUnits <= 0) {
        $_SESSION['custom_plan_flash_type'] = 'error';
        $_SESSION['custom_plan_flash_message'] = 'No service overage payment details were found for this booking.';
        portal_redirect('book-service.php');
    }

    $unitPriceDisplay = $unitPrice > 0 ? money_fmt($unitPrice) : 'TBD';
    $bookingLabel = $bookingReference !== '' ? $bookingReference : ($bookingId > 0 ? '#' . $bookingId : 'Pending');
    $planLabel = $memberPlanSlug !== '' ? ucwords(str_replace('_', ' ', $memberPlanSlug)) : 'Member Booking';
    $statusLabel = 'Awaiting Payment';

    $visibleLineItems = [];
    $lineDescription = $serviceLabel;
    if ($durationLabel !== '') {
        $lineDescription .= ' · ' . $durationLabel;
    }
    if ($petName !== '') {
        $lineDescription .= ' · ' . $petName;
    }
    if ($petSize !== '') {
        $lineDescription .= ' · ' . ucwords($petSize);
    }
    $visibleLineItems[$lineDescription] = $overageUnits;

    $summaryRows = [
        ['label' => 'Member Plan', 'value' => $planLabel],
        ['label' => 'Credit Type', 'value' => $creditLabel],
        ['label' => 'Included Credits', 'value' => (string)$includedCredits],
        ['label' => 'Remaining Credits', 'value' => (string)$remainingCredits],
        ['label' => 'Overage Units', 'value' => (string)$overageUnits],
        ['label' => 'Unit Price', 'value' => $unitPriceDisplay],
    ];

    if ($bookingDate !== '') {
        $summaryRows[] = ['label' => 'Booking Date', 'value' => $bookingDate];
    }

    if ($bookingTime !== '') {
        $summaryRows[] = ['label' => 'Booking Time', 'value' => $bookingTime];
    }

    $paymentContext = [
        'mode' => 'service_overage',
        'hero_badge' => 'Member Overage Payment',
        'hero_title' => 'Review your service overage payment',
        'hero_text' => 'Your membership credits were not enough to fully cover this booking. This portal shows the extra services due before secure Stripe checkout begins.',
        'hero_boxes' => [
            ['label' => 'Service', 'value' => $serviceLabel],
            ['label' => 'Booking Reference', 'value' => $bookingLabel],
            ['label' => 'Member Plan', 'value' => $planLabel],
            ['label' => 'Current Status', 'value' => $statusLabel],
        ],
        'services_title' => 'Overage Services Due',
        'visible_line_items' => $visibleLineItems,
        'empty_line_text' => 'No overage line items were found for this booking.',
        'summary_title' => 'Payment Summary',
        'total_label' => 'Overage Total Due',
        'total_value' => $totalAmount,
        'total_sub' => 'Only the amount beyond your included membership credits is being charged.',
        'summary_rows' => $summaryRows,
        'checkout_note' => 'You’ll be redirected to Stripe’s secure hosted checkout to pay only for the uncovered portion of this booking.',
        'submit_label' => 'Pay ' . money_fmt($totalAmount) . ' Securely',
        'form_action' => 'create-checkout-session.php',
        'hidden_fields' => [
            'mode' => 'service_overage',
            'csrf_token' => $csrfToken,
            'booking_id' => (string)$bookingId,
            'booking_reference' => $bookingReference,
            'service_type' => $serviceType,
            'service_label' => $serviceLabel,
            'quantity' => (string)$quantity,
            'overage_units' => (string)$overageUnits,
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'booking_date' => $bookingDate,
            'booking_time' => $bookingTime,
            'pet_name' => $petName,
            'pet_size' => $petSize,
            'duration_label' => $durationLabel,
            'member_plan_slug' => $memberPlanSlug,
            'credit_type' => $creditType,
            'included_credits' => (string)$includedCredits,
            'remaining_credits' => (string)$remainingCredits,
        ],
        'top_links' => [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'my-bookings.php', 'label' => 'My Bookings'],
        ],
        'footer_links' => [
            ['href' => 'dashboard.php', 'label' => 'Return to Dashboard'],
            ['href' => 'my-bookings.php', 'label' => 'View My Bookings'],
        ],
    ];
}

if (!$paymentContext || !is_array($paymentContext)) {
    $_SESSION['custom_plan_flash_type'] = 'error';
    $_SESSION['custom_plan_flash_message'] = 'The payment portal could not be prepared.';
    portal_redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Portal | Doggie Dorian’s</title>
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
                    <?php foreach ($paymentContext['top_links'] as $link): ?>
                        <a href="<?= h((string)$link['href']) ?>" class="top-link"><?= h((string)$link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <main class="payment-main">
            <div class="payment-shell">
                <div>
                    <section class="hero-card">
                        <div class="hero-badge"><?= h((string)$paymentContext['hero_badge']) ?></div>
                        <h1 class="hero-title"><?= h((string)$paymentContext['hero_title']) ?></h1>
                        <p class="hero-text"><?= h((string)$paymentContext['hero_text']) ?></p>

                        <div class="hero-grid">
                            <?php foreach ($paymentContext['hero_boxes'] as $box): ?>
                                <div class="hero-box">
                                    <span class="hero-box-label"><?= h((string)$box['label']) ?></span>
                                    <div class="hero-box-value"><?= h((string)$box['value']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="services-card">
                        <h2 class="services-title"><?= h((string)$paymentContext['services_title']) ?></h2>

                        <?php if (empty($paymentContext['visible_line_items'])): ?>
                            <div class="empty-item"><?= h((string)$paymentContext['empty_line_text']) ?></div>
                        <?php else: ?>
                            <div class="services-list">
                                <?php foreach ($paymentContext['visible_line_items'] as $label => $qty): ?>
                                    <div class="service-item">
                                        <span class="service-label"><?= h((string)$label) ?></span>
                                        <span class="service-qty"><?= (int)$qty ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="footer-actions">
                            <?php foreach ($paymentContext['footer_links'] as $link): ?>
                                <a href="<?= h((string)$link['href']) ?>" class="secondary-button"><?= h((string)$link['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <aside class="summary-card">
                    <h2 class="summary-title"><?= h((string)$paymentContext['summary_title']) ?></h2>

                    <div class="total-panel">
                        <div class="total-label"><?= h((string)$paymentContext['total_label']) ?></div>
                        <div class="total-value"><?= h(money_fmt((float)$paymentContext['total_value'])) ?></div>
                        <div class="total-sub"><?= h((string)$paymentContext['total_sub']) ?></div>
                    </div>

                    <div class="summary-grid">
                        <?php foreach ($paymentContext['summary_rows'] as $row): ?>
                            <div class="summary-row">
                                <span class="summary-row-label"><?= h((string)$row['label']) ?></span>
                                <span class="summary-row-value"><?= h((string)$row['value']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="checkout-box">
                        <p class="checkout-note"><?= h((string)$paymentContext['checkout_note']) ?></p>

                        <form method="POST" action="<?= h((string)$paymentContext['form_action']) ?>">
                            <?php foreach ($paymentContext['hidden_fields'] as $name => $value): ?>
                                <input type="hidden" name="<?= h((string)$name) ?>" value="<?= h((string)$value) ?>">
                            <?php endforeach; ?>

                            <button type="submit" class="payment-button">
                                <?= h((string)$paymentContext['submit_label']) ?>
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