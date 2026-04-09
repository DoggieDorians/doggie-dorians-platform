<?php
declare(strict_types=1);

function dd_get_membership_balance(PDO $pdo, int $membershipId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE 
                WHEN transaction_type = 'credit' THEN amount
                WHEN transaction_type = 'debit' THEN -amount
                WHEN transaction_type = 'restore' THEN amount
                ELSE 0
            END
        ), 0)
        FROM membership_transactions
        WHERE membership_id = :membership_id
    ");

    $stmt->execute([
        ':membership_id' => $membershipId
    ]);

    return (int)$stmt->fetchColumn();
}