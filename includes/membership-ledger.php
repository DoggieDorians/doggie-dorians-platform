<?php
declare(strict_types=1);

function dd_create_membership_if_not_exists(PDO $pdo, int $memberId, int $planId): int
{
    // Check existing membership
    $stmt = $pdo->prepare("
        SELECT id FROM member_memberships
        WHERE member_id = :member_id
          AND plan_id = :plan_id
        LIMIT 1
    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':plan_id' => $planId
    ]);

    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    // Create new membership
    $stmt = $pdo->prepare("
        INSERT INTO member_memberships (member_id, plan_id, created_at)
        VALUES (:member_id, :plan_id, datetime('now'))
    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':plan_id' => $planId
    ]);

    return (int)$pdo->lastInsertId();
}

function dd_seed_entitlements(PDO $pdo, int $membershipId, array $plan): void
{
    // Prevent duplicate seeding
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM membership_entitlements
        WHERE membership_id = :id
    ");
    $check->execute([':id' => $membershipId]);

    if ((int)$check->fetchColumn() > 0) {
        return;
    }

    $entitlements = [
        'walk' => (int)($plan['walks_per_month'] ?? 0),
        'daycare' => (int)($plan['daycare_per_month'] ?? 0),
        'boarding_night' => (int)($plan['boarding_nights'] ?? 0),
    ];

    foreach ($entitlements as $type => $units) {
        if ($units <= 0) continue;

        $stmt = $pdo->prepare("
            INSERT INTO membership_entitlements
            (membership_id, service_type, remaining_units)
            VALUES (:membership_id, :type, :units)
        ");

        $stmt->execute([
            ':membership_id' => $membershipId,
            ':type' => $type,
            ':units' => $units
        ]);

        // Log transaction
        $txn = $pdo->prepare("
            INSERT INTO membership_transactions
            (membership_id, service_type, direction, units, reason, created_at)
            VALUES (:membership_id, :type, 'credit', :units, 'initial_allocation', datetime('now'))
        ");

        $txn->execute([
            ':membership_id' => $membershipId,
            ':type' => $type,
            ':units' => $units
        ]);
    }
}