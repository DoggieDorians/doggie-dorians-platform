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
        $stmt = $pdo->prepare("\n            SELECT name\n            FROM sqlite_master\n            WHERE type = 'table' AND name = :name\n            LIMIT 1\n        ");
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

function dd_webhook_now(): string
{
    return date('Y-m-d H:i:s');
}

function dd_webhook_safe_money($value): float
{
    return round((float) $value, 2);
}

function dd_webhook_meta_string(array $metadata, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $metadata)) {
            continue;
        }

        $value = trim((string) $metadata[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function dd_webhook_meta_int(array $metadata, array $keys, int $default = 0): int
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $metadata)) {
            continue;
        }

        if (is_numeric($metadata[$key])) {
            return (int) $metadata[$key];
        }
    }

    return $default;
}

function dd_webhook_meta_float(array $metadata, array $keys, float $default = 0.0): float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $metadata)) {
            continue;
        }

        if (is_numeric($metadata[$key])) {
            return dd_webhook_safe_money((float) $metadata[$key]);
        }
    }

    return dd_webhook_safe_money($default);
}

function dd_webhook_ensure_events_table(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS stripe_events (\n            event_id TEXT PRIMARY KEY,\n            event_type TEXT,\n            processed_at TEXT DEFAULT CURRENT_TIMESTAMP\n        )\n    ");
}

function dd_webhook_event_already_processed(PDO $pdo, string $eventId): bool
{
    $stmt = $pdo->prepare("\n        SELECT event_id\n        FROM stripe_events\n        WHERE event_id = :event_id\n        LIMIT 1\n    ");
    $stmt->execute([
        ':event_id' => $eventId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function dd_webhook_mark_event_processed(PDO $pdo, string $eventId, string $eventType): void
{
    $stmt = $pdo->prepare("\n        INSERT OR IGNORE INTO stripe_events (event_id, event_type)\n        VALUES (:event_id, :event_type)\n    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':event_type' => $eventType,
    ]);
}

function dd_webhook_find_user_id_by_member_id(PDO $pdo, int $memberId): int
{
    if ($memberId <= 0 || !dd_webhook_has_table($pdo, 'members')) {
        return 0;
    }

    $columns = dd_webhook_table_columns($pdo, 'members');
    $idColumn = dd_webhook_first_existing_column($columns, ['id', 'member_id']);
    $userIdColumn = dd_webhook_first_existing_column($columns, ['user_id']);

    if ($idColumn === null || $userIdColumn === null) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT {$userIdColumn} FROM members WHERE {$idColumn} = :id LIMIT 1");
        $stmt->execute([':id' => $memberId]);
        $value = $stmt->fetchColumn();
        return $value !== false && is_numeric($value) ? (int) $value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function dd_webhook_find_member_id_by_user_id(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !dd_webhook_has_table($pdo, 'members')) {
        return 0;
    }

    $columns = dd_webhook_table_columns($pdo, 'members');
    $idColumn = dd_webhook_first_existing_column($columns, ['id', 'member_id']);
    $userIdColumn = dd_webhook_first_existing_column($columns, ['user_id']);

    if ($idColumn === null || $userIdColumn === null) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT {$idColumn} FROM members WHERE {$userIdColumn} = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && is_numeric($value) ? (int) $value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function dd_webhook_write_notification(PDO $pdo, int $userId, int $bookingId, string $title, string $message): void
{
    if ($userId <= 0 || !dd_webhook_has_table($pdo, 'notifications')) {
        return;
    }

    $columns = dd_webhook_table_columns($pdo, 'notifications');
    if (empty($columns)) {
        return;
    }

    $data = [];

    if (in_array('user_id', $columns, true)) {
        $data['user_id'] = $userId;
    }
    if (in_array('member_id', $columns, true)) {
        $data['member_id'] = $userId;
    }
    if (in_array('booking_id', $columns, true) && $bookingId > 0) {
        $data['booking_id'] = $bookingId;
    }
    if (in_array('type', $columns, true)) {
        $data['type'] = 'referral';
    }
    if (in_array('title', $columns, true)) {
        $data['title'] = $title;
    }

    if (in_array('message', $columns, true)) {
        $data['message'] = $message;
    } elseif (in_array('content', $columns, true)) {
        $data['content'] = $message;
    } elseif (in_array('body', $columns, true)) {
        $data['body'] = $message;
    }

    if (in_array('is_read', $columns, true)) {
        $data['is_read'] = 0;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = dd_webhook_now();
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = dd_webhook_now();
    }

    if (empty($data)) {
        return;
    }

    $fields = array_keys($data);
    $placeholders = [];
    $params = [];

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO notifications (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);
    } catch (Throwable $e) {
    }
}

function dd_webhook_referral_balance_target(PDO $pdo, int $referringUserId, int $referringMemberId): ?array
{
    $candidates = [
        [
            'table' => 'users',
            'id' => $referringUserId,
            'id_candidates' => ['id', 'user_id'],
            'balance_candidates' => ['ambassador_credit_balance', 'referral_credit_balance', 'service_credit_balance', 'account_credit', 'credit_balance', 'wallet_balance'],
        ],
        [
            'table' => 'members',
            'id' => $referringMemberId,
            'id_candidates' => ['id', 'member_id'],
            'balance_candidates' => ['ambassador_credit_balance', 'referral_credit_balance', 'service_credit_balance', 'account_credit', 'credit_balance', 'wallet_balance'],
        ],
        [
            'table' => 'members',
            'id' => $referringUserId,
            'id_candidates' => ['user_id'],
            'balance_candidates' => ['ambassador_credit_balance', 'referral_credit_balance', 'service_credit_balance', 'account_credit', 'credit_balance', 'wallet_balance'],
        ],
        [
            'table' => 'client_profiles',
            'id' => $referringUserId,
            'id_candidates' => ['user_id', 'id', 'client_id'],
            'balance_candidates' => ['ambassador_credit_balance', 'referral_credit_balance', 'service_credit_balance', 'account_credit', 'credit_balance', 'wallet_balance'],
        ],
    ];

    foreach ($candidates as $candidate) {
        if (($candidate['id'] ?? 0) <= 0 || !dd_webhook_has_table($pdo, (string) $candidate['table'])) {
            continue;
        }

        $columns = dd_webhook_table_columns($pdo, (string) $candidate['table']);
        $idColumn = dd_webhook_first_existing_column($columns, $candidate['id_candidates']);
        $balanceColumn = dd_webhook_first_existing_column($columns, $candidate['balance_candidates']);

        if ($idColumn === null || $balanceColumn === null) {
            continue;
        }

        return [
            'table' => (string) $candidate['table'],
            'id_column' => $idColumn,
            'balance_column' => $balanceColumn,
            'id' => (int) $candidate['id'],
            'columns' => $columns,
        ];
    }

    return null;
}

function dd_webhook_apply_referral_credit(PDO $pdo, array $metadata, int $bookingId = 0): void
{
    $rewardAmount = dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount', 'ambassador_credit_amount'], 0.0);
    $referringMemberId = dd_webhook_meta_int($metadata, ['referring_member_id', 'referrer_member_id', 'member_id'], 0);
    $referringUserId = dd_webhook_meta_int($metadata, ['referring_user_id', 'referrer_user_id', 'user_id'], 0);

    if ($rewardAmount <= 0) {
        return;
    }

    if ($referringUserId <= 0 && $referringMemberId > 0) {
        $referringUserId = dd_webhook_find_user_id_by_member_id($pdo, $referringMemberId);
    }
    if ($referringMemberId <= 0 && $referringUserId > 0) {
        $referringMemberId = dd_webhook_find_member_id_by_user_id($pdo, $referringUserId);
    }

    $target = dd_webhook_referral_balance_target($pdo, $referringUserId, $referringMemberId);
    if ($target === null) {
        return;
    }

    $table = (string) $target['table'];
    $idColumn = (string) $target['id_column'];
    $balanceColumn = (string) $target['balance_column'];
    $columns = (array) $target['columns'];

    $updateParts = [
        $balanceColumn . ' = COALESCE(' . $balanceColumn . ', 0) + :reward_amount',
    ];
    $params = [
        ':reward_amount' => number_format($rewardAmount, 2, '.', ''),
        ':id' => (int) $target['id'],
    ];

    if (in_array('updated_at', $columns, true)) {
        $updateParts[] = 'updated_at = :updated_at';
        $params[':updated_at'] = dd_webhook_now();
    }

    $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $updateParts) . " WHERE {$idColumn} = :id");
    $stmt->execute($params);

    if ($stmt->rowCount() > 0 && $referringUserId > 0) {
        dd_webhook_write_notification(
            $pdo,
            $referringUserId,
            $bookingId,
            'Ambassador Credit Earned',
            'A successful ambassador booking was paid. ' . number_format($rewardAmount, 2) . ' in ambassador credit has been added to your account.'
        );
    }
}

function dd_webhook_referral_already_completed(PDO $pdo, array $metadata, string $mode): bool
{
    if (!dd_webhook_has_table($pdo, 'referrals')) {
        return false;
    }

    $columns = dd_webhook_table_columns($pdo, 'referrals');
    if (empty($columns)) {
        return false;
    }

    $statusColumn = dd_webhook_first_existing_column($columns, ['status', 'referral_status']);
    if ($statusColumn === null) {
        return false;
    }

    $conditions = [];
    $params = [];

    if ($mode === 'service_overage') {
        $bookingIdColumn = dd_webhook_first_existing_column($columns, ['booking_id']);
        $bookingId = dd_webhook_meta_int($metadata, ['booking_id'], 0);
        if ($bookingIdColumn !== null && $bookingId > 0) {
            $conditions[] = $bookingIdColumn . ' = :booking_id';
            $params[':booking_id'] = $bookingId;
        }
    } elseif ($mode === 'non_member') {
        $requestIdColumn = dd_webhook_first_existing_column($columns, ['request_id', 'booking_id']);
        $requestId = dd_webhook_meta_int($metadata, ['request_id'], 0);
        if ($requestIdColumn !== null && $requestId > 0) {
            $conditions[] = $requestIdColumn . ' = :request_id';
            $params[':request_id'] = $requestId;
        }
    }

    $codeColumn = dd_webhook_first_existing_column($columns, ['ambassador_code', 'referral_code', 'code', 'promo_code']);
    $code = dd_webhook_meta_string($metadata, ['ambassador_code', 'referral_code', 'promo_code']);
    if (empty($conditions) && $codeColumn !== null && $code !== '') {
        $conditions[] = $codeColumn . ' = :code';
        $params[':code'] = $code;
    }

    if (empty($conditions)) {
        return false;
    }

    $sql = 'SELECT COUNT(*) FROM referrals WHERE (' . implode(' OR ', $conditions) . ") AND LOWER(TRIM(COALESCE({$statusColumn}, ''))) = 'completed'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ((int) $stmt->fetchColumn()) > 0;
}

function dd_webhook_upsert_referral_completion(PDO $pdo, array $metadata, string $mode, int $bookingId = 0): void
{
    if (!dd_webhook_has_table($pdo, 'referrals')) {
        return;
    }

    $columns = dd_webhook_table_columns($pdo, 'referrals');
    if (empty($columns)) {
        return;
    }

    $now = dd_webhook_now();
    $ambassadorCode = dd_webhook_meta_string($metadata, ['ambassador_code', 'referral_code', 'promo_code']);
    $referringMemberId = dd_webhook_meta_int($metadata, ['referring_member_id', 'referrer_member_id', 'member_id'], 0);
    $referringUserId = dd_webhook_meta_int($metadata, ['referring_user_id', 'referrer_user_id', 'user_id'], 0);
    $rewardAmount = dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount', 'ambassador_credit_amount'], 0.0);
    $discountAmount = dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0);
    $originalAmount = dd_webhook_meta_float($metadata, ['original_total_amount'], 0.0);
    $finalAmount = dd_webhook_meta_float($metadata, ['final_total_amount', 'total_amount'], 0.0);
    $referralIp = dd_webhook_meta_string($metadata, ['referral_ip', 'client_ip']);
    $sessionId = dd_webhook_meta_string($metadata, ['stripe_session_id']);
    $paymentIntentId = dd_webhook_meta_string($metadata, ['payment_intent_id']);
    $requestId = dd_webhook_meta_int($metadata, ['request_id'], 0);

    $statusColumn = dd_webhook_first_existing_column($columns, ['status', 'referral_status']);
    $updatedAtColumn = dd_webhook_first_existing_column($columns, ['updated_at']);
    $completedAtColumn = dd_webhook_first_existing_column($columns, ['completed_at', 'rewarded_at', 'credited_at']);

    $matchConditions = [];
    $matchParams = [];

    $bookingIdColumn = dd_webhook_first_existing_column($columns, ['booking_id']);
    if ($bookingIdColumn !== null && $bookingId > 0) {
        $matchConditions[] = $bookingIdColumn . ' = :booking_id';
        $matchParams[':booking_id'] = $bookingId;
    }

    $requestIdColumn = dd_webhook_first_existing_column($columns, ['request_id']);
    if ($requestIdColumn !== null && $requestId > 0) {
        $matchConditions[] = $requestIdColumn . ' = :request_id';
        $matchParams[':request_id'] = $requestId;
    }

    $codeColumn = dd_webhook_first_existing_column($columns, ['ambassador_code', 'referral_code', 'code', 'promo_code']);
    if ($codeColumn !== null && $ambassadorCode !== '') {
        $matchConditions[] = $codeColumn . ' = :code';
        $matchParams[':code'] = $ambassadorCode;
    }

    if (!empty($matchConditions) && $statusColumn !== null) {
        $setParts = [
            $statusColumn . " = 'completed'",
        ];
        $params = $matchParams;

        $updates = [
            'reward_amount' => number_format($rewardAmount, 2, '.', ''),
            'ambassador_credit_amount' => number_format($rewardAmount, 2, '.', ''),
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'ambassador_discount_amount' => number_format($discountAmount, 2, '.', ''),
            'original_total_amount' => number_format($originalAmount, 2, '.', ''),
            'final_total_amount' => number_format($finalAmount, 2, '.', ''),
            'total_amount' => number_format($finalAmount, 2, '.', ''),
            'referral_ip' => $referralIp,
            'used_ip' => $referralIp,
            'client_ip' => $referralIp,
            'stripe_session_id' => $sessionId,
            'payment_intent_id' => $paymentIntentId,
            'external_source' => 'stripe_checkout',
            'external_id' => $sessionId !== '' ? $sessionId : $paymentIntentId,
        ];

        foreach ($updates as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $paramKey = ':set_' . $column;
            $setParts[] = $column . ' = ' . $paramKey;
            $params[$paramKey] = $value;
        }

        if ($updatedAtColumn !== null) {
            $setParts[] = $updatedAtColumn . ' = :updated_at';
            $params[':updated_at'] = $now;
        }
        if ($completedAtColumn !== null) {
            $setParts[] = $completedAtColumn . ' = :completed_at';
            $params[':completed_at'] = $now;
        }

        $stmt = $pdo->prepare('UPDATE referrals SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' OR ', $matchConditions));
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            return;
        }
    }

    $insertData = [];
    $defaults = [
        'booking_id' => $bookingId > 0 ? $bookingId : null,
        'request_id' => $requestId > 0 ? $requestId : null,
        'service_type' => dd_webhook_meta_string($metadata, ['service_type']),
        'ambassador_code' => $ambassadorCode !== '' ? $ambassadorCode : null,
        'referral_code' => $ambassadorCode !== '' ? $ambassadorCode : null,
        'code' => $ambassadorCode !== '' ? $ambassadorCode : null,
        'promo_code' => $ambassadorCode !== '' ? $ambassadorCode : null,
        'referring_member_id' => $referringMemberId > 0 ? $referringMemberId : null,
        'referrer_member_id' => $referringMemberId > 0 ? $referringMemberId : null,
        'referring_user_id' => $referringUserId > 0 ? $referringUserId : null,
        'referrer_user_id' => $referringUserId > 0 ? $referringUserId : null,
        'referred_user_id' => dd_webhook_meta_int($metadata, ['referred_user_id'], 0) > 0 ? dd_webhook_meta_int($metadata, ['referred_user_id'], 0) : null,
        'referred_member_id' => dd_webhook_meta_int($metadata, ['referred_member_id'], 0) > 0 ? dd_webhook_meta_int($metadata, ['referred_member_id'], 0) : null,
        'original_total_amount' => number_format($originalAmount, 2, '.', ''),
        'discount_amount' => number_format($discountAmount, 2, '.', ''),
        'ambassador_discount_amount' => number_format($discountAmount, 2, '.', ''),
        'final_total_amount' => number_format($finalAmount, 2, '.', ''),
        'total_amount' => number_format($finalAmount, 2, '.', ''),
        'reward_amount' => number_format($rewardAmount, 2, '.', ''),
        'ambassador_credit_amount' => number_format($rewardAmount, 2, '.', ''),
        'status' => 'completed',
        'referral_status' => 'completed',
        'referral_ip' => $referralIp !== '' ? $referralIp : null,
        'used_ip' => $referralIp !== '' ? $referralIp : null,
        'client_ip' => $referralIp !== '' ? $referralIp : null,
        'stripe_session_id' => $sessionId !== '' ? $sessionId : null,
        'payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
        'external_source' => 'stripe_checkout',
        'external_id' => ($sessionId !== '' ? $sessionId : $paymentIntentId) !== '' ? ($sessionId !== '' ? $sessionId : $paymentIntentId) : null,
        'notes' => 'Ambassador referral completed after successful Stripe payment.',
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => $now,
        'rewarded_at' => $now,
        'credited_at' => $now,
    ];

    foreach ($defaults as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insertData[$column] = $value;
        }
    }

    if (empty($insertData)) {
        return;
    }

    $fields = array_keys($insertData);
    $placeholders = [];
    $params = [];
    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $insertData[$field];
    }

    $stmt = $pdo->prepare('INSERT INTO referrals (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
}

function dd_webhook_complete_referral_reward(PDO $pdo, array $metadata, string $mode, int $bookingId = 0): void
{
    $ambassadorCode = dd_webhook_meta_string($metadata, ['ambassador_code', 'referral_code', 'promo_code']);
    $discountAmount = dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0);
    $rewardAmount = dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount', 'ambassador_credit_amount'], 0.0);

    if ($ambassadorCode === '' || $discountAmount <= 0 || $rewardAmount <= 0) {
        return;
    }

    $alreadyCompleted = dd_webhook_referral_already_completed($pdo, $metadata, $mode);

    dd_webhook_upsert_referral_completion($pdo, $metadata, $mode, $bookingId);

    if ($alreadyCompleted) {
        return;
    }

    dd_webhook_apply_referral_credit($pdo, $metadata, $bookingId);
}

function dd_webhook_mark_custom_plan_paid(PDO $pdo, array $metadata): void
{
    $planId = (int) ($metadata['custom_plan_id'] ?? 0);
    $memberId = (int) ($metadata['member_id'] ?? 0);

    if ($planId <= 0 || $memberId <= 0) {
        throw new RuntimeException('Invalid custom plan webhook metadata.');
    }

    $stmt = $pdo->prepare("\n        UPDATE custom_plans\n        SET payment_status = 'paid'\n        WHERE id = :id\n          AND member_id = :member_id\n    ");
    $stmt->execute([
        ':id' => $planId,
        ':member_id' => $memberId,
    ]);

    dd_webhook_require_rows_affected(
        $stmt,
        'Custom plan payment could not be matched to a database record.'
    );
}

function dd_webhook_mark_service_overage_paid(PDO $pdo, array $metadata): int
{
    $bookingId = (int) ($metadata['booking_id'] ?? 0);
    $memberId = (int) ($metadata['member_id'] ?? 0);

    if ($bookingId <= 0 || $memberId <= 0) {
        throw new RuntimeException('Invalid service overage webhook metadata.');
    }

    $tableConfigs = [
        [
            'table' => 'bookings',
            'id_candidates' => ['id', 'booking_id'],
            'owner_candidates' => ['member_id', 'user_id', 'client_id', 'owner_id', 'owner_user_id', 'client_user_id'],
        ],
        [
            'table' => 'walks',
            'id_candidates' => ['id', 'walk_id', 'booking_id'],
            'owner_candidates' => ['member_id', 'user_id', 'client_id', 'owner_id', 'owner_user_id', 'client_user_id'],
        ],
    ];

    foreach ($tableConfigs as $config) {
        $table = $config['table'];
        $columns = dd_webhook_table_columns($pdo, $table);

        if (empty($columns)) {
            continue;
        }

        $idColumn = dd_webhook_first_existing_column($columns, $config['id_candidates']);
        $ownerColumn = dd_webhook_first_existing_column($columns, $config['owner_candidates']);
        $paymentStatusColumn = dd_webhook_first_existing_column($columns, ['payment_status', 'payment_state']);
        $updatedAtColumn = dd_webhook_first_existing_column($columns, ['updated_at']);
        $referralStatusColumn = dd_webhook_first_existing_column($columns, ['referral_status']);

        if ($idColumn === null || $ownerColumn === null || $paymentStatusColumn === null) {
            continue;
        }

        $setParts = [
            $paymentStatusColumn . " = 'paid'",
        ];
        $params = [
            ':id' => $bookingId,
            ':member_id' => $memberId,
        ];

        if ($referralStatusColumn !== null && dd_webhook_meta_string($metadata, ['ambassador_code', 'referral_code']) !== '') {
            $setParts[] = $referralStatusColumn . " = 'completed'";
        }
        if ($updatedAtColumn !== null) {
            $setParts[] = $updatedAtColumn . ' = :updated_at';
            $params[':updated_at'] = dd_webhook_now();
        }

        $extraFields = [
            'original_total_amount' => number_format(dd_webhook_meta_float($metadata, ['original_total_amount'], 0.0), 2, '.', ''),
            'discount_amount' => number_format(dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0), 2, '.', ''),
            'ambassador_discount_amount' => number_format(dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0), 2, '.', ''),
            'final_total_amount' => number_format(dd_webhook_meta_float($metadata, ['final_total_amount', 'total_amount'], 0.0), 2, '.', ''),
            'referral_reward_amount' => number_format(dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount'], 0.0), 2, '.', ''),
            'ambassador_credit_amount' => number_format(dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount'], 0.0), 2, '.', ''),
            'referral_ip' => dd_webhook_meta_string($metadata, ['referral_ip', 'client_ip']),
            'client_ip' => dd_webhook_meta_string($metadata, ['referral_ip', 'client_ip']),
        ];

        foreach ($extraFields as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $paramKey = ':set_' . $column;
            $setParts[] = $column . ' = ' . $paramKey;
            $params[$paramKey] = $value;
        }

        $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$idColumn} = :id AND {$ownerColumn} = :member_id");
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            return $bookingId;
        }
    }

    throw new RuntimeException('Service overage payment could not be matched to a booking record.');
}

function dd_webhook_mark_non_member_paid(PDO $pdo, array $metadata): int
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
        $updatedAtColumn = dd_webhook_first_existing_column($columns, ['updated_at']);
        $referralStatusColumn = dd_webhook_first_existing_column($columns, ['referral_status']);

        if ($idColumn === null || $statusColumn === null) {
            continue;
        }

        $paidValue = $statusColumn === 'payment_status' ? 'paid' : 'Paid';
        $setParts = [
            $statusColumn . ' = :paid_value',
        ];
        $params = [
            ':paid_value' => $paidValue,
            ':id' => $requestId,
        ];

        if ($referralStatusColumn !== null && dd_webhook_meta_string($metadata, ['ambassador_code', 'referral_code']) !== '') {
            $setParts[] = $referralStatusColumn . " = 'completed'";
        }
        if ($updatedAtColumn !== null) {
            $setParts[] = $updatedAtColumn . ' = :updated_at';
            $params[':updated_at'] = dd_webhook_now();
        }

        $extraFields = [
            'original_price' => number_format(dd_webhook_meta_float($metadata, ['original_total_amount'], 0.0), 2, '.', ''),
            'original_amount' => number_format(dd_webhook_meta_float($metadata, ['original_total_amount'], 0.0), 2, '.', ''),
            'discount_amount' => number_format(dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0), 2, '.', ''),
            'ambassador_discount_amount' => number_format(dd_webhook_meta_float($metadata, ['discount_amount', 'ambassador_discount_amount'], 0.0), 2, '.', ''),
            'final_price' => number_format(dd_webhook_meta_float($metadata, ['final_total_amount', 'total_amount'], 0.0), 2, '.', ''),
            'final_amount' => number_format(dd_webhook_meta_float($metadata, ['final_total_amount', 'total_amount'], 0.0), 2, '.', ''),
            'referral_ip' => dd_webhook_meta_string($metadata, ['referral_ip', 'client_ip']),
            'client_ip' => dd_webhook_meta_string($metadata, ['referral_ip', 'client_ip']),
            'referral_reward_amount' => number_format(dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount'], 0.0), 2, '.', ''),
            'ambassador_credit_amount' => number_format(dd_webhook_meta_float($metadata, ['referral_reward_amount', 'reward_amount'], 0.0), 2, '.', ''),
        ];

        foreach ($extraFields as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $paramKey = ':set_' . $column;
            $setParts[] = $column . ' = ' . $paramKey;
            $params[$paramKey] = $value;
        }

        $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$idColumn} = :id");
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            return $requestId;
        }
    }

    throw new RuntimeException('Non-member payment could not be matched to a booking record.');
}

function dd_webhook_handle_success(PDO $pdo, array $session): string
{
    $metadata = isset($session['metadata']) && is_array($session['metadata'])
        ? $session['metadata']
        : [];

    $metadata['stripe_session_id'] = isset($session['id']) ? (string) $session['id'] : '';
    $metadata['payment_intent_id'] = isset($session['payment_intent']) ? (string) $session['payment_intent'] : '';

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
            $bookingId = dd_webhook_mark_service_overage_paid($pdo, $metadata);
            dd_webhook_complete_referral_reward($pdo, $metadata, 'service_overage', $bookingId);
            return 'Processed service overage payment';

        case 'non_member':
            dd_webhook_mark_non_member_paid($pdo, $metadata);
            dd_webhook_complete_referral_reward($pdo, $metadata, 'non_member', 0);
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
