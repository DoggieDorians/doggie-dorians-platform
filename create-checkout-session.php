<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

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
    </head>
    <body style="font-family: Arial; background:#111; color:#fff; padding:40px;">
        <h1>Checkout Error</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="customize-plan.php" style="color:gold;">Return to Plans</a>
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

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    header('Location: signup.php');
    exit;
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
| Plan
|--------------------------------------------------------------------------
*/
$planId = (int)($_POST['plan_id'] ?? 0);
$memberId = (int)($member['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT * FROM custom_plans
    WHERE id = :id AND member_id = :member_id
    LIMIT 1
");

$stmt->execute([
    ':id' => $planId,
    ':member_id' => $memberId
]);

$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    failPage('Plan not found.', 404);
}

$amount = (float)($plan['monthly_total'] ?? 0);

if ($amount <= 0) {
    failPage('Invalid plan amount.');
}

$amountCents = (int) round($amount * 100);

/*
|--------------------------------------------------------------------------
| Stripe
|--------------------------------------------------------------------------
*/
$stripeKey = getStripeSecretKey();
$baseUrl = getBaseUrl();

if ($stripeKey === '' || $baseUrl === '') {
    failPage('Payment system not configured.', 500);
}

$successUrl = $baseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $baseUrl . '/payment-cancel.php?plan_id=' . $planId;

try {
    \Stripe\Stripe::setApiKey($stripeKey);

    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'metadata' => [
            'custom_plan_id' => (string)$planId,
            'member_id' => (string)$memberId,
        ],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => $amountCents,
                'product_data' => [
                    'name' => $plan['plan_name'] ?? 'Custom Plan',
                ],
            ],
        ]],
    ]);

    if (empty($session->url)) {
        throw new Exception('Stripe session failed.');
    }

    $pdo->prepare("
        UPDATE custom_plans
        SET payment_status = 'pending'
        WHERE id = :id AND member_id = :member_id
    ")->execute([
        ':id' => $planId,
        ':member_id' => $memberId
    ]);

    header('Location: ' . $session->url);
    exit;

} catch (Throwable $e) {
    error_log('Stripe error: ' . $e->getMessage());
    failPage('Checkout failed. Try again.');
}