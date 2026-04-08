<?php
declare(strict_types=1);

/**
 * Doggie Dorian's
 * Referral / Ambassador System
 */

function referral_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function referral_get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!referral_table_exists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable) {
        $cache[$table] = [];
        return [];
    }
}

function referral_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, referral_get_table_columns($pdo, $table), true);
}

function referral_first_existing_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (referral_has_column($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function referral_safe_execute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable) {
        return false;
    }
}

function normalizeReferralCode(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return substr($code, 0, 50);
}

function generateReferralCode(int $userId): string
{
    $prefix = strtoupper(substr(sha1((string) $userId), 0, 4));

    try {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable) {
        $suffix = strtoupper(substr(md5((string) ($userId . microtime(true))), 0, 6));
    }

    return $prefix . $suffix;
}

function getUserByReferralCode(PDO $pdo, string $code): ?array
{
    $code = normalizeReferralCode($code);

    if ($code === '' || !referral_table_exists($pdo, 'users')) {
        return null;
    }

    $columns = referral_get_table_columns($pdo, 'users');
    if ($columns === []) {
        return null;
    }

    $idColumn = referral_first_existing_column($pdo, 'users', ['id', 'user_id']);
    if ($idColumn === null) {
        return null;
    }

    $nameColumn = referral_first_existing_column($pdo, 'users', ['full_name', 'name', 'username', 'email']);

    $select = [$idColumn . ' AS internal_user_id', 'referral_code'];

    if ($nameColumn !== null) {
        $select[] = $nameColumn . ' AS display_name';
    } else {
        $select[] = '"" AS display_name';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM users WHERE UPPER(referral_code) = UPPER(:code) LIMIT 1'
    );

    if (!referral_safe_execute($stmt, [':code' => $code])) {
        return null;
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user !== false ? $user : null;
}

function calculateCommission(string $serviceType, float $price): float
{
    $serviceType = strtolower(trim($serviceType));

    if (str_contains($serviceType, 'walk')) {
        return 10.0;
    }

    if (str_contains($serviceType, 'boarding')) {
        return 25.0;
    }

    if (str_contains($serviceType, 'daycare')) {
        return 20.0;
    }

    if (str_contains($serviceType, 'sitting')) {
        return 15.0;
    }

    if (str_contains($serviceType, 'drop')) {
        return 10.0;
    }

    return round($price * 0.10, 2);
}

function referral_exists_for_booking(PDO $pdo, int $bookingId): bool
{
    if ($bookingId <= 0 || !referral_table_exists($pdo, 'referrals')) {
        return false;
    }

    $bookingColumn = referral_first_existing_column($pdo, 'referrals', ['booking_id', 'booking_reference', 'order_id']);
    if ($bookingColumn === null) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM referrals WHERE {$bookingColumn} = :booking_id LIMIT 1");
    if (!referral_safe_execute($stmt, [':booking_id' => $bookingId])) {
        return false;
    }

    return (bool) $stmt->fetchColumn();
}

function createReferral(
    PDO $pdo,
    int $referrerUserId,
    int $referredUserId,
    string $referralCode,
    int $bookingId,
    float $commission = 0.0,
    string $status = 'pending'
): bool {
    $referralCode = normalizeReferralCode($referralCode);

    if (
        $referrerUserId <= 0 ||
        $referredUserId <= 0 ||
        $bookingId <= 0 ||
        $referralCode === '' ||
        !referral_table_exists($pdo, 'referrals')
    ) {
        return false;
    }

    if (referral_exists_for_booking($pdo, $bookingId)) {
        return true;
    }

    $columns = referral_get_table_columns($pdo, 'referrals');
    if ($columns === []) {
        return false;
    }

    $data = [
        'referrer_user_id' => $referrerUserId,
        'ambassador_user_id' => $referrerUserId,
        'user_id' => $referrerUserId,
        'referrer_id' => $referrerUserId,
        'owner_user_id' => $referrerUserId,

        'referred_user_id' => $referredUserId,
        'customer_user_id' => $referredUserId,

        'referral_code' => $referralCode,
        'code' => $referralCode,
        'used_code' => $referralCode,

        'booking_id' => $bookingId,
        'booking_reference' => (string) $bookingId,
        'order_id' => (string) $bookingId,

        'commission_amount' => $commission,
        'reward_amount' => $commission,
        'credit_amount' => $commission,
        'payout_amount' => $commission,
        'amount' => $commission,

        'status' => $status,
        'referral_status' => $status,
        'state' => $status,

        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'referred_at' => date('Y-m-d H:i:s'),
        'date_created' => date('Y-m-d H:i:s'),
    ];

    $insertData = [];
    foreach ($data as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insertData[$column] = $value;
        }
    }

    if ($insertData === []) {
        return false;
    }

    $fields = array_keys($insertData);
    $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
    $params = [];

    foreach ($insertData as $field => $value) {
        $params[':' . $field] = $value;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO referrals (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    return referral_safe_execute($stmt, $params);
}

function markReferralEarned(PDO $pdo, int $bookingId): void
{
    if ($bookingId <= 0 || !referral_table_exists($pdo, 'referrals')) {
        return;
    }

    $columns = referral_get_table_columns($pdo, 'referrals');
    if ($columns === []) {
        return;
    }

    $bookingColumn = referral_first_existing_column($pdo, 'referrals', ['booking_id', 'booking_reference', 'order_id']);
    if ($bookingColumn === null) {
        return;
    }

    $setParts = [];

    foreach (['status', 'referral_status', 'state'] as $statusColumn) {
        if (in_array($statusColumn, $columns, true)) {
            $setParts[] = $statusColumn . " = 'earned'";
        }
    }

    if (in_array('updated_at', $columns, true)) {
        $setParts[] = "updated_at = :updated_at";
    }

    if ($setParts === []) {
        return;
    }

    $sql = 'UPDATE referrals SET ' . implode(', ', $setParts) . ' WHERE ' . $bookingColumn . ' = :booking_id';
    $params = [
        ':booking_id' => $bookingId,
    ];

    if (in_array('updated_at', $columns, true)) {
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare($sql);
    referral_safe_execute($stmt, $params);
}

function attachReferralToBooking(
    PDO $pdo,
    int $bookingId,
    int $userId,
    ?string $referralCode,
    string $serviceType,
    float $price
): void {
    $referralCode = normalizeReferralCode((string) $referralCode);

    if ($bookingId <= 0 || $userId <= 0 || $referralCode === '') {
        return;
    }

    $referrer = getUserByReferralCode($pdo, $referralCode);

    if (!$referrer) {
        return;
    }

    $referrerId = (int) ($referrer['internal_user_id'] ?? 0);
    if ($referrerId <= 0) {
        return;
    }

    // Prevent self-referral.
    if ($referrerId === $userId) {
        return;
    }

    if (referral_exists_for_booking($pdo, $bookingId)) {
        return;
    }

    $commission = calculateCommission($serviceType, $price);

    createReferral(
        $pdo,
        $referrerId,
        $userId,
        $referralCode,
        $bookingId,
        $commission,
        'pending'
    );
}