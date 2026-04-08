<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$memberId = (int)($_SESSION['member_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Doggie Dorian’s | Membership Signup</title>
        <style>
            * { box-sizing: border-box; }

            body {
                margin: 0;
                background: #09090d;
                color: #f4f1ea;
                font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                min-height: 100vh;
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            .page {
                max-width: 1100px;
                margin: 0 auto;
                padding: 28px 18px 80px;
            }

            .topbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 24px;
            }

            .brand {
                font-size: 1.5rem;
                font-weight: 900;
                letter-spacing: .04em;
            }

            .top-links {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }

            .top-link {
                padding: 10px 14px;
                border-radius: 999px;
                background: rgba(255,255,255,0.06);
                border: 1px solid rgba(255,255,255,0.08);
                font-weight: 700;
            }

            .top-link-signup {
                background: linear-gradient(135deg, #e2c48d, #b9975b);
                color: #0b0b10;
                border: 1px solid rgba(255,255,255,0.14);
            }

            .card {
                background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 28px;
                padding: 32px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.28);
            }

            .eyebrow {
                color: #c6b28b;
                text-transform: uppercase;
                letter-spacing: .14em;
                font-size: .75rem;
                font-weight: 800;
                margin-bottom: 10px;
            }

            h1 {
                margin: 0 0 12px;
                font-size: clamp(2.2rem, 5vw, 4rem);
                line-height: 1.04;
            }

            p {
                color: rgba(244,241,234,0.78);
                line-height: 1.7;
                font-size: 1rem;
            }

            .actions {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 24px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 48px;
                padding: 13px 18px;
                border-radius: 14px;
                font-size: .95rem;
                font-weight: 800;
                border: none;
                cursor: pointer;
            }

            .btn-gold {
                background: linear-gradient(135deg, #e2c48d, #b9975b);
                color: #0b0b10;
            }

            .btn-light {
                background: rgba(255,255,255,0.06);
                color: #fff;
                border: 1px solid rgba(255,255,255,0.12);
            }

            .notice {
                margin-top: 18px;
                padding: 14px 16px;
                border-radius: 18px;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.08);
                color: rgba(244,241,234,0.82);
            }

            @media (max-width: 700px) {
                .topbar {
                    flex-direction: column;
                    align-items: flex-start;
                }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="topbar">
                <a href="index.php" class="brand">Doggie Dorian’s</a>

                <div class="top-links">
                    <a href="index.php" class="top-link">Home</a>
                    <a href="pricing.php" class="top-link">Pricing</a>
                    <a href="founders-memberships.php" class="top-link">Founder Memberships</a>
                    <a href="contact.php" class="top-link">Contact</a>
                    <a href="login.php" class="top-link">Login</a>
                    <a href="signup.php" class="top-link top-link-signup">Sign Up</a>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Membership Checkout</div>
                <h1>Start membership checkout from the membership flow.</h1>
                <p>
                    This page is used to launch Stripe checkout after a membership or founder flow has been selected.
                    It is not meant to be opened directly.
                </p>

                <div class="notice">
                    <?php if ($memberId > 0): ?>
                        You are currently signed in. Choose a membership plan or continue from your founder approval flow.
                    <?php else: ?>
                        You need to sign in before starting membership checkout.
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <?php if ($memberId > 0): ?>
                        <a href="memberships.php" class="btn btn-gold">View Memberships</a>
                        <a href="dashboard.php" class="btn btn-light">Go to Dashboard</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-gold">Member Login</a>
                        <a href="memberships.php" class="btn btn-light">View Memberships</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$planId   = (int)($_POST['plan_id'] ?? 0);
$agreeTos = isset($_POST['agree_tos']) ? trim((string)$_POST['agree_tos']) : '';

if ($memberId <= 0) {
    http_response_code(401);
    exit('You must be logged in to complete membership signup.');
}

if ($planId <= 0) {
    http_response_code(400);
    exit('Invalid plan selected.');
}

if ($agreeTos !== '1') {
    http_response_code(400);
    exit('You must agree to the Terms of Service before continuing.');
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        signup_price_cents,
        included_credits
    FROM membership_plans
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $planId
]);

$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    exit('Membership plan not found.');
}

$priceCents = (int)($plan['signup_price_cents'] ?? 0);

if ($priceCents <= 0) {
    http_response_code(400);
    exit('This membership plan is not configured for checkout.');
}

$planName = trim((string)($plan['name'] ?? 'Membership'));
$includedCredits = (int)($plan['included_credits'] ?? 0);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = rtrim($scheme . '://' . $host, '/');

$successUrl = $baseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = $baseUrl . '/payment-cancel.php?plan_id=' . urlencode((string)$planId);

$checkoutReference = 'membership_signup_' . $memberId . '_' . $planId;

try {
    $session = \Stripe\Checkout\Session::create(
        [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'payment_method_types' => ['card'],
            'client_reference_id' => $checkoutReference,
            'metadata' => [
                'ledger_action'    => 'membership_signup',
                'member_id'        => (string)$memberId,
                'plan_id'          => (string)$planId,
                'included_credits' => (string)$includedCredits,
                'agree_tos'        => '1',
                'tos_version_date' => '2026-04-07',
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $priceCents,
                    'product_data' => [
                        'name' => $planName,
                        'description' => 'Doggie Dorian’s membership signup',
                    ],
                ],
            ]],
        ],
        [
            'idempotency_key' => 'signup_' . $memberId . '_' . $planId . '_' . date('YmdHis'),
        ]
    );

    if (empty($session->url)) {
        throw new RuntimeException('Stripe Checkout URL was not returned.');
    }

    header('Location: ' . $session->url);
    exit;
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe API error in signup.php: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to start checkout right now. Please try again.');
} catch (Throwable $e) {
    error_log('General error in signup.php: ' . $e->getMessage());
    http_response_code(500);
    exit('An unexpected error occurred. Please try again.');
}