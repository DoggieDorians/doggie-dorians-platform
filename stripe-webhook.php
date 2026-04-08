<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/membership-ledger.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

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

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
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
    switch ($event->type) {
        case 'checkout.session.completed':
            $session = $event->data->object->toArray();

            $paymentStatus = (string)($session['payment_status'] ?? '');
            $metadata      = $session['metadata'] ?? [];

            if (($metadata['ledger_action'] ?? '') !== 'membership_signup') {
                http_response_code(200);
                exit('Ignored non-membership checkout');
            }

            if ($paymentStatus !== 'paid') {
                http_response_code(200);
                exit('Checkout completed but not paid');
            }

            dd_handle_membership_checkout_success($pdo, $session);

            http_response_code(200);
            exit('Webhook processed');

        case 'checkout.session.async_payment_succeeded':
            $session = $event->data->object->toArray();
            $metadata = $session['metadata'] ?? [];

            if (($metadata['ledger_action'] ?? '') !== 'membership_signup') {
                http_response_code(200);
                exit('Ignored non-membership checkout');
            }

            dd_handle_membership_checkout_success($pdo, $session);

            http_response_code(200);
            exit('Async webhook processed');

        default:
            http_response_code(200);
            exit('Event ignored');
    }
} catch (Throwable $e) {
    error_log('Stripe webhook handler error: ' . $e->getMessage());
    http_response_code(500);
    exit('Webhook handler failed');
}

/**
 * Handles successful Stripe checkout for membership signup.
 *
 * Safe to run multiple times for the same Checkout Session.
 */
function dd_handle_membership_checkout_success(PDO $pdo, array $session): void
{
    $sessionId = trim((string)($session['id'] ?? ''));
    $metadata  = $session['metadata'] ?? [];

    if ($sessionId === '') {
        throw new RuntimeException('Missing Stripe Checkout Session ID');
    }

    $memberId = (int)($metadata['member_id'] ?? 0);
    $planId   = (int)($metadata['plan_id'] ?? 0);

    if ($memberId <= 0 || $planId <= 0) {
        throw new RuntimeException('Missing or invalid membership metadata');
    }

    $stmt = $pdo->prepare("
        SELECT id, name, included_credits
        FROM membership_plans
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $planId
    ]);

    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        throw new RuntimeException('Membership plan not found for webhook');
    }

    $includedCredits = (int)($plan['included_credits'] ?? 0);

    $pdo->beginTransaction();

    try {
        $dupStmt = $pdo->prepare("
            SELECT id
            FROM membership_transactions
            WHERE external_source = :external_source
              AND external_id = :external_id
            LIMIT 1
        ");
        $dupStmt->execute([
            ':external_source' => 'stripe_checkout_session',
            ':external_id' => $sessionId,
        ]);

        $existingTransactionId = $dupStmt->fetchColumn();

        if ($existingTransactionId) {
            $pdo->commit();
            return;
        }

        $membershipId = dd_create_membership_if_not_exists($pdo, $memberId, $planId);

        dd_seed_entitlements($pdo, $membershipId, $plan);

        if ($includedCredits > 0) {
            $creditStmt = $pdo->prepare("
                INSERT INTO membership_transactions (
                    membership_id,
                    transaction_type,
                    amount,
                    note,
                    external_source,
                    external_id,
                    created_at
                ) VALUES (
                    :membership_id,
                    :transaction_type,
                    :amount,
                    :note,
                    :external_source,
                    :external_id,
                    datetime('now')
                )
            ");
            $creditStmt->execute([
                ':membership_id'   => $membershipId,
                ':transaction_type'=> 'credit',
                ':amount'          => $includedCredits,
                ':note'            => 'Initial membership credits from successful Stripe payment',
                ':external_source' => 'stripe_checkout_session',
                ':external_id'     => $sessionId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}