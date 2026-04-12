<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/membership-ledger.php';

function dd_webhook_fail(string $logMessage, int $statusCode = 500, string $publicMessage = 'Webhook request failed.'): never
{
    error_log($logMessage);
    http_response_code($statusCode);
    exit($publicMessage);
}

function dd_webhook_has_table(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table' AND name = :name
            LIMIT 1
        ");
        $stmt->execute([
            ':name' => $table,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dd_webhook_table_columns(PDO $pdo, string $table): array
{
    if (!dd_webhook_has_table($pdo, $table)) {
        return [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name']) && is_string($row['name'])) {
                    $columns[] = $row['name'];
                }
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function dd_webhook_first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function dd_webhook_require_rows_affected(PDOStatement $stmt, string $message): void
{
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException($message);
    }
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

    return (bool) $stmt->fetchColumn();
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

    dd_webhook_require_rows_affected(
        $stmt,
        'Custom plan payment could not be matched to a database record.'
    );
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

    dd_webhook_require_rows_affected(
        $stmt,
        'Service overage payment could not be matched to a booking record.'
    );
}

function dd_webhook_mark_non_member_paid(PDO $pdo, array $metadata): void
{
    $requestId = (int) ($metadata['request_id'] ?? 0);

    if ($requestId <= 0) {
        throw new RuntimeException('Invalid non-member webhook metadata.');
    }

    $tableConfigs = [
        [
            'table' => 'non_member_bookings',
            'id_candidates' => ['id'],
            'status_candidates' => ['status', 'payment_status'],
        ],
        [
            'table' => 'public_booking_requests',
            'id_candidates' => ['id', 'request_id'],
            'status_candidates' => ['status', 'payment_status'],
        ],
    ];

    foreach ($tableConfigs as $config) {
        $table = $config['table'];
        $columns = dd_webhook_table_columns($pdo, $table);

        if (empty($columns)) {
            continue;
        }

        $idColumn = dd_webhook_first_existing_column($columns, $config['id_candidates']);
        $statusColumn = dd_webhook_first_existing_column($columns, $config['status_candidates']);

        if ($idColumn === null || $statusColumn === null) {
            continue;
        }

        $paidValue = $statusColumn === 'payment_status' ? 'paid' : 'Paid';

        $stmt = $pdo->prepare("
            UPDATE {$table}
            SET {$statusColumn} = :paid_value
            WHERE {$idColumn} = :id
        ");
        $stmt->execute([
            ':paid_value' => $paidValue,
            ':id' => $requestId,
        ]);

        if ($stmt->rowCount() > 0) {
            return;
        }
    }

    throw new RuntimeException('Non-member payment could not be matched to a booking record.');
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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    dd_webhook_fail('Stripe webhook database connection is not available.', 500, 'Server configuration error.');
}

$stripeSecretKey = trim((string) dd_stripe_secret_key());
$webhookSecret = trim((string) dd_stripe_webhook_secret());

if ($stripeSecretKey === '') {
    dd_webhook_fail('Stripe webhook secret key missing from stripe-config.', 500, 'Server configuration error.');
}

if ($webhookSecret === '') {
    dd_webhook_fail('Stripe webhook signing secret missing from stripe-config.', 500, 'Server configuration error.');
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

$payload = file_get_contents('php://input');
$sigHeader = trim((string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));

if (!is_string($payload) || $payload === '') {
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
        $webhookSecret
    );
} catch (\UnexpectedValueException $e) {
    dd_webhook_fail('Stripe webhook invalid payload: ' . $e->getMessage(), 400, 'Invalid payload');
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    dd_webhook_fail('Stripe webhook invalid signature: ' . $e->getMessage(), 400, 'Invalid signature');
}

try {
    dd_webhook_ensure_events_table($pdo);
} catch (Throwable $e) {
    dd_webhook_fail('Stripe webhook event table error: ' . $e->getMessage(), 500, 'Webhook event storage failed');
}

$eventId = (string) ($event->id ?? '');
$eventType = (string) ($event->type ?? '');

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
                if ($eventId !== '') {
                    dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
                }

                http_response_code(200);
                exit('Checkout completed but not paid');
            }

            $startedTransaction = false;

            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTransaction = true;
                }

                $message = dd_webhook_handle_success($pdo, $session);

                if ($eventId !== '') {
                    dd_webhook_mark_event_processed($pdo, $eventId, $eventType);
                }

                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
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
    dd_webhook_fail('Stripe webhook handler error: ' . $e->getMessage(), 500, 'Webhook handler failed');
}