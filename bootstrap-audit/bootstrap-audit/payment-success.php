<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt(float $amount): string
{
    return '$' . number_format($amount, 2);
}

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    redirectTo('signup.php');
}

$sessionId = trim((string)($_GET['session_id'] ?? ''));

if ($sessionId === '') {
    http_response_code(400);
    exit('Invalid payment session.');
}

$stripeKey = dd_stripe_secret_key();

if ($stripeKey === '') {
    error_log('Stripe key missing in payment-success.php');
    http_response_code(500);
    exit('Payment system configuration error.');
}

$verifiedPaid = false;
$errorMessage = '';
$plan = null;

try {
    \Stripe\Stripe::setApiKey($stripeKey);

    $session = \Stripe\Checkout\Session::retrieve($sessionId);

    if (!$session) {
        throw new RuntimeException('Stripe session not found.');
    }

    $paymentStatus = (string)($session->payment_status ?? '');
    $planId = (int)($session->metadata->custom_plan_id ?? 0);
    $memberId = (int)($session->metadata->member_id ?? 0);
    $sessionPaymentIntentId = trim((string)($session->payment_intent ?? ''));

    if ($planId <= 0 || $memberId <= 0) {
        throw new RuntimeException('Invalid Stripe session metadata.');
    }

    if ($memberId !== (int)$member['id']) {
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

    if ($paymentStatus !== 'paid') {
        throw new RuntimeException('Stripe has not marked this payment as paid.');
    }

    $amountPaidCents = (int)($session->amount_total ?? 0);
    $expectedAmountCents = (int)round((float)($plan['monthly_total'] ?? 0) * 100);

    if ($expectedAmountCents > 0 && $amountPaidCents > 0 && $amountPaidCents !== $expectedAmountCents) {
        throw new RuntimeException('Paid amount does not match expected plan amount.');
    }

    if (($plan['payment_status'] ?? '') !== 'paid') {
        $update = $pdo->prepare("
            UPDATE custom_plans
            SET payment_status = :payment_status
            WHERE id = :id
              AND member_id = :member_id
        ");
        $update->execute([
            ':payment_status' => 'paid',
            ':id' => $planId,
            ':member_id' => $memberId,
        ]);

        $plan['payment_status'] = 'paid';
    }

    $verifiedPaid = true;

} catch (\Throwable $e) {
    error_log('payment-success.php error: ' . $e->getMessage());
    $errorMessage = 'We could not verify this payment yet. Please contact support if this persists.';
}

$planName = (string)($plan['plan_name'] ?? 'Custom Plan');
$amountPaid = (float)($plan['monthly_total'] ?? 0.00);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $verifiedPaid ? 'Payment Successful' : 'Payment Verification' ?> | Doggie Dorian’s</title>
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
                    <a href="dashboard.php" class="top-link">Dashboard</a>
                    <a href="my-bookings.php" class="top-link">My Bookings</a>
                </div>
            </div>
        </div>

        <main class="success-main">
            <div class="success-shell">
                <section class="success-card">
                    <?php if ($verifiedPaid): ?>
                        <div class="status-badge success">Payment Confirmed</div>
                        <h1 class="success-title">Payment Successful</h1>
                        <p class="success-text">
                            Your custom plan has been verified with Stripe and activated successfully.
                        </p>

                        <div class="amount-panel">
                            <div class="amount-label">Amount Paid</div>
                            <div class="amount-value"><?= h(money_fmt($amountPaid)) ?></div>
                        </div>

                        <div class="plan-box">
                            <span class="plan-label">Plan</span>
                            <div class="plan-name"><?= h($planName) ?></div>
                        </div>

                        <div class="success-actions">
                            <a href="dashboard.php" class="btn-primary">Go to Dashboard</a>
                            <a href="my-bookings.php" class="btn-secondary">View Bookings</a>
                        </div>
                    <?php else: ?>
                        <div class="status-badge error">Verification Issue</div>
                        <h1 class="success-title">We couldn’t confirm this payment yet</h1>
                        <p class="success-text">
                            There was a problem verifying the Stripe session for this payment.
                        </p>

                        <div class="debug-box"><?= h($errorMessage !== '' ? $errorMessage : 'Unknown error.') ?></div>

                        <div class="success-actions">
                            <a href="dashboard.php" class="btn-primary">Return to Dashboard</a>
                            <a href="customize-plan.php" class="btn-secondary">Back to Plans</a>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</body>
</html>