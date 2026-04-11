<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/membership-ledger.php';

if (!defined('STRIPE_SECRET_KEY') || trim((string) STRIPE_SECRET_KEY) === '') {
    http_response_code(500);
    exit('Stripe secret key not configured');
}

if (!defined('STRIPE_WEBHOOK_SECRET') || trim((string) STRIPE_WEBHOOK_SECRET) === '') {
    http_response_code(500);
    exit('Stripe webhook secret not configured');
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

\Stripe\Stripe::setApiKey((string) STRIPE_SECRET_KEY);

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === false || $payload === '') {
    http_response_code(400);
    exit('Missing webhook payload');
}

if ($sigHeader === '') {
    http_response_code(400);
    exit('Missing Stripe signature');
}

function dd_webhook_ensure_events_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stripe_events (
            event_id TEXT PRIMARY KEY,
            event_type TEXT,
            processed_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function dd_webhook_event_already_processed(PDO $pdo, string $eventId): bool
{
    $stmt = $pdo->prepare("
        SELECT event_id
        FROM stripe_events
        WHERE event_id = :event_id
        LIMIT 1
    ");
    $stmt->execute([
        ':event_id' => $eventId,
    ]);

    return (bool)$stmt->fetchColumn();
}

function dd_webhook_mark_event_processed(PDO $pdo, string $eventId, string $eventType): void
{
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO stripe_events (event_id, event_type)
        VALUES (:event_id, :event_type)
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':event_type' => $eventType,
    ]);
}

function dd_webhook_mark_custom_plan_paid(PDO $pdo, array $metadata): void
{
    $planId = (int) ($metadata['custom_plan_id'] ?? 0);
    $memberId = (int) ($metadata['member_id'] ?? 0);

    if ($planId <= 0 || $memberId <= 0) {
        throw new RuntimeException('Invalid custom plan webhook metadata.');
    }

    $stmt = $pdo->prepare("
        UPDATE custom_plans
        SET payment_status = 'paid'
        WHERE id = :id
          AND member_id = :member_id
    ");
    $stmt->execute([
        ':id' => $planId,
        ':member_id' => $memberId,
    ]);
}

function dd_webhook_mark_service_overage_paid(PDO $pdo, array $metadata): void
{
    $bookingId = (int) ($metadata['booking_id'] ?? 0);
    $memberId = (int) ($metadata['member_id'] ?? 0);

    if ($bookingId <= 0 || $memberId <= 0) {
        throw new RuntimeException('Invalid service overage webhook metadata.');
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET payment_status = 'paid'
        WHERE id = :id
          AND member_id = :member_id
    ");
    $stmt->execute([
        ':id' => $bookingId,
        ':member_id' => $memberId,
    ]);
}

function dd_webhook_mark_non_member_paid(PDO $pdo, array $metadata): void
{
    $requestId = (int) ($metadata['request_id'] ?? 0);

    if ($requestId <= 0) {
        throw new RuntimeException('Invalid non-member webhook metadata.');
    }

    $stmt = $pdo->prepare("
        UPDATE non_member_bookings
        SET status = 'Paid'
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $requestId,
    ]);
}

function dd_webhook_handle_success(PDO $pdo, array $session): string
{
    $metadata = isset($session['metadata']) && is_array($session['metadata'])
        ? $session['metadata']
        : [];

    /*
    |--------------------------------------------------------------------------
    | Existing founder / membership ledger flow
    |--------------------------------------------------------------------------
    */
    if (($metadata['ledger_action'] ?? '') === 'membership_signup') {
        dd_process_membership_checkout_success($pdo, $session);
        return 'Processed membership signup';
    }

    /*
    |--------------------------------------------------------------------------
    | Unified payment modes
    |--------------------------------------------------------------------------
    */
    $mode = strtolower(trim((string) ($metadata['mode'] ?? '')));

    switch ($mode) {
        case 'custom_plan':
            dd_webhook_mark_custom_plan_paid($pdo, $metadata);
            return 'Processed custom plan payment';

        case 'service_overage':
            dd_webhook_mark_service_overage_paid($pdo, $metadata);
            return 'Processed service overage payment';

        case 'non_member':
            dd_webhook_mark_non_member_paid($pdo, $metadata);
            return 'Processed non-member payment';

        default:
            return 'Ignored unrecognized checkout mode';
    }
}

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        (string) STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    error_log('Stripe webhook invalid payload: ' . $e->getMessage());
    http_response_code(400);
    exit('Invalid payload');
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log('Stripe webhook invalid signature: ' . $e->getMessage());
    http_response_code(400);
    exit('Invalid signature');
}

try {
    dd_webhook_ensure_events_table($pdo);
} catch (Throwable $e) {
    error_log('Stripe webhook event table error: ' . $e->getMessage());
    http_response_code(500);
    exit('Webhook event storage failed');
}

$eventId = (string)($event->id ?? '');
$eventType = (string)($event->type ?? '');

if ($eventId !== '' && dd_webhook_event_already_processed($pdo, $eventId)) {
    http_response_code(200);
    exit('Event already processed');
}

try {
    switch ($eventType) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $session = $event->data->object->toArray();

            if (!is_array($session)) {
                http_response_code(400);
                exit('Invalid Stripe session object');
            }

            if (
                $eventType === 'checkout.session.completed'
                && (($session['payment_status'] ?? '') !== 'paid')
            ) {
                dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
                http_response_code(200);
                exit('Checkout completed but not paid');
            }

            $message = dd_webhook_handle_success($pdo, $session);

            if ($eventId !== '') {
                dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
            }

            http_response_code(200);
            exit($message);

        case 'checkout.session.async_payment_failed':
            if ($eventId !== '') {
                dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
            }
            http_response_code(200);
            exit('Async payment failed event noted');

        default:
            if ($eventId !== '') {
                dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
            }
            http_response_code(200);
            exit('Event ignored');
    }
} catch (Throwable $e) {
    error_log('Stripe webhook handler error: ' . $e->getMessage());
    http_response_code(500);
    exit('Webhook handler failed');
}