<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    $_SESSION['custom_plan_flash_type'] = 'error';
    $_SESSION['custom_plan_flash_message'] = 'Please sign in to access your payment portal.';
    redirectTo('signup.php');
}

$planId = (int)($_GET['plan_id'] ?? 0);

if ($planId <= 0) {
    redirectTo('customize-plan.php');
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
    ':member_id' => (int)$member['id'],
]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    $_SESSION['custom_plan_flash_type'] = 'error';
    $_SESSION['custom_plan_flash_message'] = 'That custom plan could not be found.';
    redirectTo('customize-plan.php');
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
                    <a href="dashboard.php" class="top-link">Dashboard</a>
                    <a href="customize-plan.php" class="top-link">Plans</a>
                </div>
            </div>
        </div>

        <main class="payment-main">
            <div class="payment-shell">
                <div>
                    <section class="hero-card">
                        <div class="hero-badge">Secure Checkout</div>
                        <h1 class="hero-title">Review your custom plan payment</h1>
                        <p class="hero-text">
                            This payment portal reflects the custom plan saved to your account. Your exact total is pulled from the database on the server before Stripe checkout begins.
                        </p>

                        <div class="hero-grid">
                            <div class="hero-box">
                                <span class="hero-box-label">Plan Name</span>
                                <div class="hero-box-value"><?= h($planName) ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Plan ID</span>
                                <div class="hero-box-value">#<?= (int)$planId ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Payment Mode</span>
                                <div class="hero-box-value"><?= h(ucfirst($paymentMode)) ?></div>
                            </div>

                            <div class="hero-box">
                                <span class="hero-box-label">Current Status</span>
                                <div class="hero-box-value"><?= h(ucfirst($paymentStatus)) ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="services-card">
                        <h2 class="services-title">Included Services</h2>

                        <?php if (empty($visibleLineItems)): ?>
                            <div class="empty-item">No line items were found for this plan.</div>
                        <?php else: ?>
                            <div class="services-list">
                                <?php foreach ($visibleLineItems as $label => $qty): ?>
                                    <div class="service-item">
                                        <span class="service-label"><?= h($label) ?></span>
                                        <span class="service-qty"><?= (int)$qty ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="footer-actions">
                            <a href="dashboard.php" class="secondary-button">Return to Dashboard</a>
                            <a href="customize-plan.php" class="secondary-button">Back to Plans</a>
                        </div>
                    </section>
                </div>

                <aside class="summary-card">
                    <h2 class="summary-title">Payment Summary</h2>

                    <div class="total-panel">
                        <div class="total-label">Final Total Due</div>
                        <div class="total-value"><?= h(money_fmt($finalTotal)) ?></div>
                        <div class="total-sub">
                            <?= $discountAmount > 0
                                ? 'Your member pricing subtotal qualified for a discount before checkout.'
                                : 'No discount was applied to this plan total.' ?>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-row">
                            <span class="summary-row-label">Subtotal</span>
                            <span class="summary-row-value"><?= h(money_fmt($subtotalAmount)) ?></span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-row-label">Discount</span>
                            <span class="summary-row-value">
                                <?= $discountAmount > 0
                                    ? h('-' . money_fmt($discountAmount) . ($discountPercent > 0 ? ' (' . number_format($discountPercent, 0) . '%)' : ''))
                                    : '—' ?>
                            </span>
                        </div>
                    </div>

                    <div class="checkout-box">
                        <p class="checkout-note">
                            You’ll be redirected to Stripe’s secure hosted checkout to complete this payment.
                        </p>

                        <form method="POST" action="create-checkout-session.php">
                            <input type="hidden" name="plan_id" value="<?= (int)$planId ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                            <button type="submit" class="payment-button">
                                Pay <?= h(money_fmt($finalTotal)) ?> Securely
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