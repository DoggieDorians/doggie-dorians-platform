<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money_fmt(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function current_member_id(PDO $pdo): int
{
    $member = currentMember($pdo);
    return (int)($member['id'] ?? 0);
}

function normalize_mode(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'custom_plan' => 'custom_plan',
        'service_overage' => 'service_overage',
        'non_member' => 'non_member',
        default => '',
    };
}

function service_label_from_type(string $serviceType): string
{
    $serviceType = strtolower(trim($serviceType));

    return match ($serviceType) {
        'walk', 'walks' => 'Walk',
        'drop_in', 'drop-in', 'dropin', 'drop in' => 'Drop-In',
        'daycare', 'day care' => 'Daycare',
        'boarding', 'board' => 'Boarding',
        'sitting', 'pet sitting', 'in-home sitting', 'in_home_sitting' => 'Pet Sitting',
        default => 'Service',
    };
}

$sessionId = trim((string) ($_GET['session_id'] ?? ''));

if ($sessionId === '') {
    http_response_code(400);
    exit('Invalid payment session.');
}

$stripeKey = trim((string) dd_stripe_secret_key());

if ($stripeKey === '') {
    error_log('Stripe key missing in payment-success.php');
    http_response_code(500);
    exit('Payment system configuration error.');
}

$verifiedPaid = false;
$errorMessage = '';
$mode = normalize_mode((string)($_GET['mode'] ?? ''));
$pageTitle = 'Payment Verification';
$headline = 'We couldn’t confirm this payment yet';
$bodyText = 'There was a problem verifying the Stripe session for this payment.';
$amountPaid = 0.00;
$itemLabel = 'Purchase';
$itemName = 'Payment';
$primaryHref = 'index.php';
$primaryLabel = 'Return Home';
$secondaryHref = 'index.php';
$secondaryLabel = 'Go Back';
$topLinks = [
    ['href' => 'index.php', 'label' => 'Home'],
    ['href' => 'contact.php', 'label' => 'Contact'],
];

try {
    \Stripe\Stripe::setApiKey($stripeKey);

    $session = \Stripe\Checkout\Session::retrieve($sessionId);

    if (!$session) {
        throw new RuntimeException('Stripe session not found.');
    }

    $paymentStatus = (string) ($session->payment_status ?? '');
    $amountPaidCents = (int) ($session->amount_total ?? 0);
    $metadata = $session->metadata ?? new stdClass();

    if ($mode === '') {
        $mode = normalize_mode((string)($metadata->mode ?? ''));
    }

    if ($mode === '') {
        throw new RuntimeException('Invalid Stripe session mode.');
    }

    if ($paymentStatus !== 'paid') {
        throw new RuntimeException('Stripe has not marked this payment as paid.');
    }

    $amountPaid = $amountPaidCents > 0 ? ($amountPaidCents / 100) : 0.00;

    /*
    |--------------------------------------------------------------------------
    | Custom Plan Success
    |--------------------------------------------------------------------------
    */
    if ($mode === 'custom_plan') {
        $memberId = current_member_id($pdo);

        if ($memberId <= 0) {
            redirect_to('login.php');
        }

        $planId = (int) ($metadata->custom_plan_id ?? 0);
        $stripeMemberId = (int) ($metadata->member_id ?? 0);

        if ($planId <= 0 || $stripeMemberId <= 0) {
            throw new RuntimeException('Invalid Stripe session metadata.');
        }

        if ($stripeMemberId !== $memberId) {
            throw new RuntimeException('This payment does not belong to the signed-in member.');
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
            throw new RuntimeException('Matching custom plan was not found.');
        }

        $expectedAmountCents = (int) round((float) ($plan['monthly_total'] ?? 0) * 100);

        if ($expectedAmountCents > 0 && $amountPaidCents > 0 && $amountPaidCents !== $expectedAmountCents) {
            throw new RuntimeException('Paid amount does not match expected plan amount.');
        }

        $verifiedPaid = true;
        $pageTitle = 'Payment Successful';
        $headline = 'Payment Successful';
        $bodyText = 'Your custom plan has been verified with Stripe and activated successfully.';
        $itemLabel = 'Plan';
        $itemName = (string) ($plan['plan_name'] ?? 'Custom Plan');
        $amountPaid = (float) ($plan['monthly_total'] ?? $amountPaid);
        $primaryHref = 'dashboard.php';
        $primaryLabel = 'Go to Dashboard';
        $secondaryHref = 'my-bookings.php';
        $secondaryLabel = 'View Bookings';
        $topLinks = [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'my-bookings.php', 'label' => 'My Bookings'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Member Service Overage Success
    |--------------------------------------------------------------------------
    */
    if ($mode === 'service_overage') {
        $memberId = current_member_id($pdo);

        if ($memberId <= 0) {
            redirect_to('login.php');
        }

        $stripeMemberId = (int) ($metadata->member_id ?? 0);
        $bookingId = (int) ($metadata->booking_id ?? 0);
        $serviceType = (string) ($metadata->service_type ?? '');
        $overageUnits = (int) ($metadata->overage_units ?? 0);
        $memberPlanSlug = trim((string) ($metadata->member_plan_slug ?? ''));
        $expectedAmount = (float) ($metadata->total_amount ?? 0);

        if ($stripeMemberId <= 0 || $stripeMemberId !== $memberId) {
            throw new RuntimeException('This overage payment does not belong to the signed-in member.');
        }

        if ($expectedAmount > 0) {
            $expectedAmountCents = (int) round($expectedAmount * 100);

            if ($amountPaidCents > 0 && $expectedAmountCents !== $amountPaidCents) {
                throw new RuntimeException('Paid amount does not match expected overage total.');
            }
        }

        unset($_SESSION['service_payment_portal']);

        $verifiedPaid = true;
        $pageTitle = 'Payment Successful';
        $headline = 'Member Overage Paid';
        $bodyText = 'Your member overage payment has been verified successfully and the uncovered portion of your booking has been paid.';
        $itemLabel = 'Service';
        $itemName = service_label_from_type($serviceType) . ($overageUnits > 0 ? ' × ' . $overageUnits : '');
        if ($memberPlanSlug !== '') {
            $itemName .= ' · ' . ucwords(str_replace('_', ' ', $memberPlanSlug));
        }
        $primaryHref = 'my-bookings.php';
        $primaryLabel = 'View Bookings';
        $secondaryHref = 'dashboard.php';
        $secondaryLabel = 'Go to Dashboard';
        $topLinks = [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'my-bookings.php', 'label' => 'My Bookings'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Non-Member Success
    |--------------------------------------------------------------------------
    */
    if ($mode === 'non_member') {
        $requestId = (int) ($metadata->request_id ?? 0);
        $serviceType = (string) ($metadata->service_type ?? '');
        $fullName = trim((string) ($metadata->full_name ?? ''));
        $dogName = trim((string) ($metadata->dog_name ?? ''));
        $expectedAmount = (float) ($metadata->total_amount ?? 0);

        if ($expectedAmount > 0) {
            $expectedAmountCents = (int) round($expectedAmount * 100);

            if ($amountPaidCents > 0 && $expectedAmountCents !== $amountPaidCents) {
                throw new RuntimeException('Paid amount does not match expected non-member total.');
            }
        }

        unset($_SESSION['non_member_payment_portal']);

        $verifiedPaid = true;
        $pageTitle = 'Payment Successful';
        $headline = 'Non-Member Booking Paid';
        $bodyText = 'Your non-member booking payment has been verified successfully. Your request is now marked as paid and ready for follow-up.';
        $itemLabel = 'Booking';
        $itemName = service_label_from_type($serviceType);

        if ($dogName !== '') {
            $itemName .= ' · ' . $dogName;
        }

        if ($fullName !== '') {
            $itemName .= ' · ' . $fullName;
        }

        $primaryHref = 'non-member-booking.php';
        $primaryLabel = 'Book Another Service';
        $secondaryHref = 'contact.php';
        $secondaryLabel = 'Contact Us';
        $topLinks = [
            ['href' => 'non-member-booking.php', 'label' => 'Booking'],
            ['href' => 'contact.php', 'label' => 'Contact'],
        ];
    }

    if (!$verifiedPaid) {
        throw new RuntimeException('Payment mode was not handled.');
    }
} catch (\Throwable $e) {
    error_log('payment-success.php error: ' . $e->getMessage());
    $errorMessage = 'We could not verify this payment yet. Please contact support if this persists.';

    if ($mode === 'custom_plan') {
        $primaryHref = 'login.php';
        $primaryLabel = 'Member Login';
        $secondaryHref = 'customize-plan.php';
        $secondaryLabel = 'Back to Plans';
        $topLinks = [
            ['href' => 'login.php', 'label' => 'Login'],
            ['href' => 'customize-plan.php', 'label' => 'Plans'],
        ];
    } elseif ($mode === 'service_overage') {
        $primaryHref = 'login.php';
        $primaryLabel = 'Member Login';
        $secondaryHref = 'my-bookings.php';
        $secondaryLabel = 'View Bookings';
        $topLinks = [
            ['href' => 'login.php', 'label' => 'Login'],
            ['href' => 'my-bookings.php', 'label' => 'My Bookings'],
        ];
    } elseif ($mode === 'non_member') {
        $primaryHref = 'non-member-booking.php';
        $primaryLabel = 'Back to Booking';
        $secondaryHref = 'contact.php';
        $secondaryLabel = 'Contact Us';
        $topLinks = [
            ['href' => 'non-member-booking.php', 'label' => 'Booking'],
            ['href' => 'contact.php', 'label' => 'Contact'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $verifiedPaid ? h($pageTitle) : 'Payment Verification' ?> | Doggie Dorian’s</title>
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
                radial-gradient(circle at top, rgba(212, 175, 55, 0.14), transparent 35%),
                linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
            color: #f4f1ea;
        }

        .success-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 20px 22px 0;
        }

        .topbar-inner {
            max-width: 1120px;
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

        .success-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 34px 18px 72px;
        }

        .success-shell {
            width: 100%;
            max-width: 760px;
        }

        .success-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 34px 28px 30px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.40);
            backdrop-filter: blur(8px);
        }

        .success-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(212,175,55,0.10), transparent 35%);
            pointer-events: none;
        }

        .status-badge {
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
            position: relative;
            z-index: 1;
        }

        .status-badge.success {
            background: rgba(212,175,55,0.14);
            color: #f2d471;
            border: 1px solid rgba(212,175,55,0.25);
        }

        .status-badge.error {
            background: rgba(255,92,92,0.12);
            color: #ffb3b3;
            border: 1px solid rgba(255,92,92,0.22);
        }

        .success-title {
            position: relative;
            z-index: 1;
            margin: 0 0 10px;
            font-size: 2.35rem;
            line-height: 1.08;
            color: #fff;
        }

        .success-text {
            position: relative;
            z-index: 1;
            margin: 0;
            color: rgba(244,241,234,0.78);
            line-height: 1.7;
            font-size: 1.02rem;
        }

        .amount-panel {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            padding: 22px;
            border-radius: 22px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            text-align: center;
        }

        .amount-label {
            font-size: 0.9rem;
            color: rgba(244,241,234,0.62);
            text-transform: uppercase;
            letter-spacing: 0.10em;
        }

        .amount-value {
            margin-top: 10px;
            font-size: 3rem;
            font-weight: 800;
            color: #f2d471;
            line-height: 1;
        }

        .plan-box {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            padding: 18px 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
            text-align: center;
        }

        .plan-label {
            display: block;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            color: rgba(244,241,234,0.58);
            margin-bottom: 8px;
        }

        .plan-name {
            font-size: 1.12rem;
            font-weight: 700;
            color: #ffffff;
        }

        .debug-box {
            position: relative;
            z-index: 1;
            margin-top: 20px;
            padding: 16px 18px;
            border-radius: 16px;
            background: rgba(255,92,92,0.08);
            border: 1px solid rgba(255,92,92,0.18);
            color: #ffd2d2;
            line-height: 1.6;
            word-break: break-word;
        }

        .success-actions {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            padding: 14px 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            box-shadow: 0 10px 24px rgba(185,151,91,0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.10);
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

            .success-main {
                align-items: flex-start;
                padding: 24px 14px 56px;
            }

            .success-card {
                padding: 26px 18px 24px;
                border-radius: 22px;
            }

            .success-title {
                font-size: 1.95rem;
                text-align: center;
            }

            .success-text {
                text-align: center;
            }

            .amount-value {
                font-size: 2.45rem;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="success-page">
        <div class="topbar">
            <div class="topbar-inner">
                <a href="index.php" class="brand">Doggie <span>Dorian’s</span></a>

                <div class="top-actions">
                    <?php foreach ($topLinks as $link): ?>
                        <a href="<?= h((string)$link['href']) ?>" class="top-link"><?= h((string)$link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <main class="success-main">
            <div class="success-shell">
                <section class="success-card">
                    <?php if ($verifiedPaid): ?>
                        <div class="status-badge success">Payment Confirmed</div>
                        <h1 class="success-title"><?= h($headline) ?></h1>
                        <p class="success-text"><?= h($bodyText) ?></p>

                        <div class="amount-panel">
                            <div class="amount-label">Amount Paid</div>
                            <div class="amount-value"><?= h(money_fmt($amountPaid)) ?></div>
                        </div>

                        <div class="plan-box">
                            <span class="plan-label"><?= h($itemLabel) ?></span>
                            <div class="plan-name"><?= h($itemName) ?></div>
                        </div>

                        <div class="success-actions">
                            <a href="<?= h($primaryHref) ?>" class="btn-primary"><?= h($primaryLabel) ?></a>
                            <a href="<?= h($secondaryHref) ?>" class="btn-secondary"><?= h($secondaryLabel) ?></a>
                        </div>
                    <?php else: ?>
                        <div class="status-badge error">Verification Issue</div>
                        <h1 class="success-title"><?= h($headline) ?></h1>
                        <p class="success-text"><?= h($bodyText) ?></p>

                        <div class="debug-box"><?= h($errorMessage !== '' ? $errorMessage : 'Unknown error.') ?></div>

                        <div class="success-actions">
                            <a href="<?= h($primaryHref) ?>" class="btn-primary"><?= h($primaryLabel) ?></a>
                            <a href="<?= h($secondaryHref) ?>" class="btn-secondary"><?= h($secondaryLabel) ?></a>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</body>
</html>