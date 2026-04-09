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

function failPage(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Checkout Error | Doggie Dorian’s</title>
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
                    radial-gradient(circle at top, rgba(212, 175, 55, 0.10), transparent 35%),
                    linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
                color: #f4f1ea;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-card {
                width: 100%;
                max-width: 700px;
                background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 28px;
                padding: 30px 24px;
                box-shadow: 0 24px 70px rgba(0,0,0,0.40);
                backdrop-filter: blur(8px);
            }

            .badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 14px;
                border-radius: 999px;
                font-size: 0.82rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin-bottom: 16px;
                background: rgba(255,92,92,0.12);
                color: #ffb3b3;
                border: 1px solid rgba(255,92,92,0.22);
            }

            h1 {
                margin: 0 0 10px;
                font-size: 2rem;
                line-height: 1.08;
                color: #fff;
            }

            p {
                margin: 0;
                color: rgba(244,241,234,0.80);
                line-height: 1.7;
            }

            .error-message {
                margin-top: 18px;
                padding: 16px;
                border-radius: 16px;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.08);
                color: #f3e5c7;
                word-break: break-word;
            }

            .actions {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 24px;
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
            }

            .btn-secondary {
                background: rgba(255,255,255,0.06);
                color: #ffffff;
                border: 1px solid rgba(255,255,255,0.08);
            }

            .btn-primary:hover,
            .btn-secondary:hover {
                filter: brightness(1.04);
            }

            @media (max-width: 640px) {
                .error-card {
                    padding: 24px 18px;
                    border-radius: 22px;
                }

                h1 {
                    font-size: 1.75rem;
                }

                .actions {
                    flex-direction: column;
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
        <section class="error-card">
            <div class="badge">Checkout Error</div>
            <h1>We couldn’t start checkout</h1>
            <p>
                Something interrupted the secure payment flow before Stripe checkout could begin.
            </p>

            <div class="error-message">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="actions">
                <a href="customize-plan.php" class="btn-primary">Return to Plans</a>
                <a href="dashboard.php" class="btn-secondary">Go to Dashboard</a>
            </div>
        </section>
    </body>
    </html>
    <?php
    exit;
}

function getStripeSecretKey(): string
{
    if (function_exists('dd_stripe_secret_key')) {
        return trim((string) dd_stripe_secret_key());
    }

    if (function_exists('stripe_secret_key')) {
        return trim((string) stripe_secret_key());
    }

    return '';
}

function getStripePublicBaseUrl(): string
{
    if (function_exists('dd_stripe_public_base_url')) {
        return trim((string) dd_stripe_public_base_url());
    }

    if (function_exists('stripe_public_base_url')) {
        return trim((string) stripe_public_base_url());
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host;
}

function sanitizeBaseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return rtrim($url, '/');
}

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    redirectTo('signup.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failPage('Invalid request method. Please start checkout from the payment portal.', 405);
}

$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
$postCsrf = (string)($_POST['csrf_token'] ?? '');

if ($sessionCsrf === '' || $postCsrf === '' || !hash_equals($sessionCsrf, $postCsrf)) {
    failPage('Your session expired. Please return to the payment portal and try again.', 403);
}

$planId = (int)($_POST['plan_id'] ?? 0);
$memberId = (int)($member['id'] ?? 0);

if ($planId <= 0) {
    failPage('Invalid plan selection.');
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
    failPage('That custom plan could not be found for this account.', 404);
}

$amount = (float)($plan['monthly_total'] ?? 0);

if ($amount <= 0) {
    failPage('This plan does not have a valid payable amount.');
}

$amountCents = (int) round($amount * 100);

if ($amountCents < 50) {
    failPage('This amount is too low to process.');
}

$stripeKey = getStripeSecretKey();

if ($stripeKey === '') {
    error_log('Stripe checkout error: missing Stripe secret key configuration.');
    failPage('Stripe is not configured yet. Please contact support before trying again.', 500);
}

$baseUrl = sanitizeBaseUrl(getStripePublicBaseUrl());

if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
    error_log('Stripe checkout error: invalid public base URL.');
    failPage('The payment system is not configured correctly yet. Please contact support before trying again.', 500);
}

$successUrl = $baseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $baseUrl . '/payment-cancel.php?plan_id=' . urlencode((string)$planId);

$planName = trim((string)($plan['plan_name'] ?? 'Doggie Dorian’s Custom Plan'));
$memberEmail = trim((string)($member['email'] ?? ''));

try {
    \Stripe\Stripe::setApiKey($stripeKey);

    $sessionPayload = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => 'custom_plan_' . $planId,
        'metadata' => [
            'custom_plan_id' => (string)$planId,
            'member_id' => (string)$memberId,
            'source' => 'doggie_dorians_custom_plan',
        ],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => $amountCents,
                'product_data' => [
                    'name' => $planName,
                    'description' => 'Upfront custom plan payment for Doggie Dorian’s',
                ],
            ],
        ]],
    ];

    if ($memberEmail !== '' && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
        $sessionPayload['customer_email'] = $memberEmail;
    }

    $checkoutSession = \Stripe\Checkout\Session::create($sessionPayload);

    if (empty($checkoutSession->id) || empty($checkoutSession->url)) {
        throw new RuntimeException('Stripe did not return a valid checkout session.');
    }

    $update = $pdo->prepare("
        UPDATE custom_plans
        SET payment_status = :payment_status
        WHERE id = :id
          AND member_id = :member_id
    ");
    $update->execute([
        ':payment_status' => 'pending',
        ':id' => $planId,
        ':member_id' => $memberId,
    ]);

    redirectTo($checkoutSession->url);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe checkout API error: ' . $e->getMessage());
    failPage('We could not connect to secure checkout right now. Please try again in a moment.', 500);
} catch (\Throwable $e) {
    error_log('Stripe checkout general error: ' . $e->getMessage());
    failPage('We could not start checkout right now. Please try again in a moment.', 500);
}