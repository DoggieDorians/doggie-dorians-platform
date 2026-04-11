<?php
declare(strict_types=1);

function dd_table_exists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function dd_table_columns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!dd_table_exists($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = array();
        return array();
    } catch (Exception $e) {
        $cache[$table] = array();
        return array();
    }
}

function dd_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, dd_table_columns($pdo, $table), true);
}

function dd_first_existing_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (dd_has_column($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function dd_safe_execute(PDOStatement $stmt, array $params = array()): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function dd_slugify_plan_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    return trim((string) $name, '-');
}

function dd_plan_profile(array $plan): array
{
    $name = trim((string) ($plan['name'] ?? $plan['plan_name'] ?? $plan['title'] ?? ''));
    $slug = dd_slugify_plan_name($name);

    $profile = array(
        'plan_name' => $name !== '' ? $name : 'Membership',
        'walk' => 0,
        'daycare' => 0,
        'drop-in' => 0,
        'boarding_night' => 0,
        'quarterly_service_credit' => 0,
        'boarding_discount_percent' => 0,
    );

    if ($slug === 'founder-walk-club') {
        $profile['walk'] = 12;
        $profile['quarterly_service_credit'] = 250;
        return $profile;
    }

    if ($slug === 'founder-care-club') {
        $profile['walk'] = 16;
        $profile['daycare'] = 2;
        $profile['drop-in'] = 2;
        $profile['quarterly_service_credit'] = 500;
        $profile['boarding_discount_percent'] = 10;
        return $profile;
    }

    if ($slug === 'founder-elite-club') {
        $profile['walk'] = 20;
        $profile['daycare'] = 4;
        $profile['drop-in'] = 4;
        $profile['boarding_night'] = 3;
        $profile['quarterly_service_credit'] = 750;
        $profile['boarding_discount_percent'] = 20;
        return $profile;
    }

    if (isset($plan['walks_per_month'])) {
        $profile['walk'] = max(0, (int) $plan['walks_per_month']);
    }
    if (isset($plan['daycare_per_month'])) {
        $profile['daycare'] = max(0, (int) $plan['daycare_per_month']);
    }
    if (isset($plan['drop_ins_per_month'])) {
        $profile['drop-in'] = max(0, (int) $plan['drop_ins_per_month']);
    } elseif (isset($plan['drop_in_visits'])) {
        $profile['drop-in'] = max(0, (int) $plan['drop_in_visits']);
    }
    if (isset($plan['boarding_nights'])) {
        $profile['boarding_night'] = max(0, (int) $plan['boarding_nights']);
    }
    if (isset($plan['quarterly_service_credit'])) {
        $profile['quarterly_service_credit'] = max(0, (int) $plan['quarterly_service_credit']);
    } elseif (isset($plan['quarterly_credit'])) {
        $profile['quarterly_service_credit'] = max(0, (int) $plan['quarterly_credit']);
    }
    if (isset($plan['boarding_discount_percent'])) {
        $profile['boarding_discount_percent'] = max(0, (int) $plan['boarding_discount_percent']);
    }

    return $profile;
}

function dd_create_membership_if_not_exists(PDO $pdo, int $memberId, int $planId): int
{
    if ($memberId <= 0 || $planId <= 0) {
        throw new RuntimeException('Invalid member or plan ID.');
    }

    $table = 'member_memberships';
    if (!dd_table_exists($pdo, $table)) {
        throw new RuntimeException('member_memberships table not found.');
    }

    $memberCol = dd_first_existing_column($pdo, $table, array('member_id', 'user_id', 'client_id'));
    $planCol = dd_first_existing_column($pdo, $table, array('plan_id'));
    $idCol = dd_first_existing_column($pdo, $table, array('id'));

    if ($memberCol === null || $planCol === null || $idCol === null) {
        throw new RuntimeException('Required membership columns are missing.');
    }

    $check = $pdo->prepare("
        SELECT {$idCol}
        FROM {$table}
        WHERE {$memberCol} = :member_id
          AND {$planCol} = :plan_id
        LIMIT 1
    ");
    $check->execute(array(
        ':member_id' => $memberId,
        ':plan_id' => $planId,
    ));

    $existing = $check->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $columns = dd_table_columns($pdo, $table);
    $data = array();

    if (in_array($memberCol, $columns, true)) {
        $data[$memberCol] = $memberId;
    }
    if (in_array($planCol, $columns, true)) {
        $data[$planCol] = $planId;
    }
    if (in_array('renewal_count', $columns, true)) {
        $data['renewal_count'] = 0;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('status', $columns, true)) {
        $data['status'] = 'active';
    }

    $fields = array_keys($data);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    $insert = $pdo->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    if (!dd_safe_execute($insert, $params)) {
        throw new RuntimeException('Could not create membership.');
    }

    return (int) $pdo->lastInsertId();
}

function dd_get_membership_renewal_count(PDO $pdo, int $membershipId): int
{
    if ($membershipId <= 0 || !dd_table_exists($pdo, 'member_memberships') || !dd_has_column($pdo, 'member_memberships', 'renewal_count')) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT renewal_count
        FROM member_memberships
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(array(':id' => $membershipId));

    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function dd_increment_membership_renewal_count(PDO $pdo, int $membershipId): int
{
    if ($membershipId <= 0) {
        return 0;
    }

    if (!dd_table_exists($pdo, 'member_memberships') || !dd_has_column($pdo, 'member_memberships', 'renewal_count')) {
        return 0;
    }

    $current = dd_get_membership_renewal_count($pdo, $membershipId);
    $next = $current + 1;

    $stmt = $pdo->prepare("
        UPDATE member_memberships
        SET renewal_count = :renewal_count" . (dd_has_column($pdo, 'member_memberships', 'updated_at') ? ", updated_at = :updated_at" : "") . "
        WHERE id = :id
    ");

    $params = array(
        ':renewal_count' => $next,
        ':id' => $membershipId,
    );

    if (dd_has_column($pdo, 'member_memberships', 'updated_at')) {
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    dd_safe_execute($stmt, $params);

    return $next;
}

function dd_find_entitlement_row(PDO $pdo, int $membershipId, string $serviceType): ?array
{
    if ($membershipId <= 0 || $serviceType === '' || !dd_table_exists($pdo, 'membership_entitlements')) {
        return null;
    }

    $membershipCol = dd_first_existing_column($pdo, 'membership_entitlements', array('membership_id'));
    $serviceCol = dd_first_existing_column($pdo, 'membership_entitlements', array('service_type', 'type'));
    $remainingCol = dd_first_existing_column($pdo, 'membership_entitlements', array('remaining_units', 'units_remaining', 'balance'));
    $idCol = dd_first_existing_column($pdo, 'membership_entitlements', array('id'));

    if ($membershipCol === null || $serviceCol === null || $remainingCol === null) {
        return null;
    }

    $selectId = $idCol !== null ? $idCol . ' AS entitlement_id,' : '';
    $stmt = $pdo->prepare("
        SELECT {$selectId} {$remainingCol} AS remaining_units
        FROM membership_entitlements
        WHERE {$membershipCol} = :membership_id
          AND {$serviceCol} = :service_type
        LIMIT 1
    ");
    $stmt->execute(array(
        ':membership_id' => $membershipId,
        ':service_type' => $serviceType,
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dd_upsert_entitlement_units(PDO $pdo, int $membershipId, string $serviceType, int $unitsToAdd): void
{
    if ($membershipId <= 0 || $serviceType === '' || $unitsToAdd <= 0) {
        return;
    }

    if (!dd_table_exists($pdo, 'membership_entitlements')) {
        return;
    }

    $membershipCol = dd_first_existing_column($pdo, 'membership_entitlements', array('membership_id'));
    $serviceCol = dd_first_existing_column($pdo, 'membership_entitlements', array('service_type', 'type'));
    $remainingCol = dd_first_existing_column($pdo, 'membership_entitlements', array('remaining_units', 'units_remaining', 'balance'));

    if ($membershipCol === null || $serviceCol === null || $remainingCol === null) {
        return;
    }

    $existing = dd_find_entitlement_row($pdo, $membershipId, $serviceType);

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE membership_entitlements
            SET {$remainingCol} = {$remainingCol} + :units
            WHERE {$membershipCol} = :membership_id
              AND {$serviceCol} = :service_type
        ");
        $stmt->execute(array(
            ':units' => $unitsToAdd,
            ':membership_id' => $membershipId,
            ':service_type' => $serviceType,
        ));
        return;
    }

    $columns = dd_table_columns($pdo, 'membership_entitlements');
    $data = array();

    if (in_array($membershipCol, $columns, true)) {
        $data[$membershipCol] = $membershipId;
    }
    if (in_array($serviceCol, $columns, true)) {
        $data[$serviceCol] = $serviceType;
    }
    if (in_array($remainingCol, $columns, true)) {
        $data[$remainingCol] = $unitsToAdd;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = date('Y-m-d H:i:s');
    }

    $fields = array_keys($data);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    $insert = $pdo->prepare(
        'INSERT INTO membership_entitlements (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    $insert->execute($params);
}

function dd_log_membership_transaction(
    PDO $pdo,
    int $membershipId,
    string $serviceType,
    string $direction,
    int $units,
    string $reason,
    string $externalSource = '',
    string $externalId = '',
    int $bookingId = 0
): void {
    if (!dd_table_exists($pdo, 'membership_transactions')) {
        return;
    }

    $columns = dd_table_columns($pdo, 'membership_transactions');
    if (empty($columns)) {
        return;
    }

    $data = array();

    if (in_array('membership_id', $columns, true)) {
        $data['membership_id'] = $membershipId;
    }
    if (in_array('service_type', $columns, true)) {
        $data['service_type'] = $serviceType;
    }
    if (in_array('direction', $columns, true)) {
        $data['direction'] = $direction;
    }
    if (in_array('units', $columns, true)) {
        $data['units'] = $units;
    }
    if (in_array('reason', $columns, true)) {
        $data['reason'] = $reason;
    }
    if (in_array('note', $columns, true)) {
        $data['note'] = $reason;
    }
    if ($bookingId > 0 && in_array('booking_id', $columns, true)) {
        $data['booking_id'] = $bookingId;
    }
    if ($externalSource !== '' && in_array('external_source', $columns, true)) {
        $data['external_source'] = $externalSource;
    }
    if ($externalId !== '' && in_array('external_id', $columns, true)) {
        $data['external_id'] = $externalId;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($data)) {
        return;
    }

    $fields = array_keys($data);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO membership_transactions (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    dd_safe_execute($stmt, $params);
}

function dd_seed_entitlements(PDO $pdo, int $membershipId, array $plan): void
{
    $profile = dd_plan_profile($plan);

    $serviceMap = array(
        'walk' => (int) $profile['walk'],
        'daycare' => (int) $profile['daycare'],
        'drop-in' => (int) $profile['drop-in'],
        'boarding_night' => (int) $profile['boarding_night'],
    );

    foreach ($serviceMap as $serviceType => $units) {
        if ($units <= 0) {
            continue;
        }

        $existing = dd_find_entitlement_row($pdo, $membershipId, $serviceType);
        if ($existing) {
            continue;
        }

        dd_upsert_entitlement_units($pdo, $membershipId, $serviceType, $units);
        dd_log_membership_transaction(
            $pdo,
            $membershipId,
            $serviceType,
            'credit',
            $units,
            'initial_allocation',
            'membership_seed',
            'membership_' . $membershipId . '_initial_' . $serviceType
        );
    }
}

function dd_apply_renewal_entitlements(PDO $pdo, int $membershipId, array $plan, int $renewalCount, string $sessionId): void
{
    $profile = dd_plan_profile($plan);

    $serviceMap = array(
        'walk' => (int) $profile['walk'],
        'daycare' => (int) $profile['daycare'],
        'drop-in' => (int) $profile['drop-in'],
        'boarding_night' => (int) $profile['boarding_night'],
    );

    foreach ($serviceMap as $serviceType => $units) {
        if ($units <= 0) {
            continue;
        }

        dd_upsert_entitlement_units($pdo, $membershipId, $serviceType, $units);
        dd_log_membership_transaction(
            $pdo,
            $membershipId,
            $serviceType,
            'credit',
            $units,
            'renewal_allocation',
            'stripe_checkout_session_credit',
            $sessionId . '_renewal_' . $serviceType
        );
    }

    if ($renewalCount > 0 && $renewalCount % 3 === 0) {
        $quarterlyCredit = (int) $profile['quarterly_service_credit'];

        if ($quarterlyCredit > 0) {
            dd_upsert_entitlement_units($pdo, $membershipId, 'service_credit', $quarterlyCredit);
            dd_log_membership_transaction(
                $pdo,
                $membershipId,
                'service_credit',
                'credit',
                $quarterlyCredit,
                'quarterly_founder_credit',
                'stripe_checkout_session_credit',
                $sessionId . '_quarterly_service_credit'
            );
        }
    }
}

function dd_has_processed_checkout_session(PDO $pdo, string $sessionId): bool
{
    if ($sessionId === '' || !dd_table_exists($pdo, 'membership_transactions')) {
        return false;
    }

    $columns = dd_table_columns($pdo, 'membership_transactions');
    if (!in_array('external_source', $columns, true) || !in_array('external_id', $columns, true)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM membership_transactions
        WHERE external_source = :external_source
          AND external_id = :external_id
        LIMIT 1
    ");
    $stmt->execute(array(
        ':external_source' => 'stripe_checkout_session',
        ':external_id' => $sessionId,
    ));

    return (bool) $stmt->fetchColumn();
}

function dd_mark_checkout_session_processed(PDO $pdo, int $membershipId, string $sessionId): void
{
    dd_log_membership_transaction(
        $pdo,
        $membershipId,
        'system',
        'credit',
        0,
        'checkout_session_processed',
        'stripe_checkout_session',
        $sessionId
    );
}

function dd_process_membership_checkout_success(PDO $pdo, array $session): void
{
    $sessionId = trim((string) ($session['id'] ?? ''));
    $metadata = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : array();

    if ($sessionId === '') {
        throw new RuntimeException('Missing Stripe Checkout Session ID.');
    }

    $memberId = (int) ($metadata['member_id'] ?? 0);
    $planId = (int) ($metadata['plan_id'] ?? 0);

    if ($memberId <= 0 || $planId <= 0) {
        throw new RuntimeException('Missing or invalid Stripe membership metadata.');
    }

    if (!dd_table_exists($pdo, 'membership_plans')) {
        throw new RuntimeException('membership_plans table not found.');
    }

    $planStmt = $pdo->prepare("
        SELECT *
        FROM membership_plans
        WHERE id = :id
        LIMIT 1
    ");
    $planStmt->execute(array(':id' => $planId));
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        throw new RuntimeException('Membership plan not found.');
    }

    $pdo->beginTransaction();

    try {
        if (dd_has_processed_checkout_session($pdo, $sessionId)) {
            $pdo->commit();
            return;
        }

        $membershipId = dd_create_membership_if_not_exists($pdo, $memberId, $planId);

        $existingMembershipStmt = $pdo->prepare("
            SELECT id
            FROM member_memberships
            WHERE id = :id
            LIMIT 1
        ");
        $existingMembershipStmt->execute(array(':id' => $membershipId));
        $existingMembership = $existingMembershipStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingMembership) {
            throw new RuntimeException('Membership could not be loaded after creation.');
        }

        $renewalCountBefore = dd_get_membership_renewal_count($pdo, $membershipId);
        $isFirstAllocation = $renewalCountBefore === 0;

        $hasSeededAnything = false;
        foreach (array('walk', 'daycare', 'drop-in', 'boarding_night', 'service_credit') as $serviceType) {
            $row = dd_find_entitlement_row($pdo, $membershipId, $serviceType);
            if ($row) {
                $hasSeededAnything = true;
                break;
            }
        }

        if (!$hasSeededAnything) {
            dd_seed_entitlements($pdo, $membershipId, $plan);
            dd_mark_checkout_session_processed($pdo, $membershipId, $sessionId);
            $pdo->commit();
            return;
        }

        $renewalCount = dd_increment_membership_renewal_count($pdo, $membershipId);
        if ($renewalCount <= 0 && $isFirstAllocation === false) {
            $renewalCount = $renewalCountBefore + 1;
        }

        dd_apply_renewal_entitlements($pdo, $membershipId, $plan, $renewalCount, $sessionId);
        dd_mark_checkout_session_processed($pdo, $membershipId, $sessionId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}