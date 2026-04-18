<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function isAdmin(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (!empty($_SESSION['admin_id'])) {
        return true;
    }

    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($role, ['admin', 'superadmin', 'owner'], true);
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function hasTable(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($table) . ')');
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

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function safeFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function safeFetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function valueFromRow(array $row, array $candidates, string $default = ''): string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return (string) $row[$candidate];
        }
    }

    return $default;
}

function formatDateTimeDisplay(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function buildAddress(array $row): string
{
    $parts = [];

    foreach (['address', 'street_address'] as $key) {
        $value = trim(valueFromRow($row, [$key]));
        if ($value !== '') {
            $parts[] = $value;
            break;
        }
    }

    $city = trim(valueFromRow($row, ['city']));
    $state = trim(valueFromRow($row, ['state', 'province']));
    $zip = trim(valueFromRow($row, ['zip_code', 'zip', 'zipcode', 'postal_code', 'apartment_number']));

    $tail = trim(implode(' ', array_filter([$city, $state, $zip], static fn($v) => $v !== '')));
    if ($tail !== '') {
        $parts[] = $tail;
    }

    return trim(implode(', ', $parts));
}

function buildFullName(array $row): string
{
    $fullName = trim(valueFromRow($row, ['full_name', 'name', 'client_name', 'member_name']));
    if ($fullName !== '') {
        return $fullName;
    }

    $first = trim(valueFromRow($row, ['first_name']));
    $last = trim(valueFromRow($row, ['last_name']));
    $combined = trim($first . ' ' . $last);
    if ($combined !== '') {
        return $combined;
    }

    $username = trim(valueFromRow($row, ['username']));
    if ($username !== '') {
        return $username;
    }

    $email = trim(valueFromRow($row, ['email']));
    if ($email !== '') {
        return $email;
    }

    return 'Member';
}

function ddDeleteMemberCsrfToken(): string
{
    if (empty($_SESSION['admin_delete_member_csrf']) || !is_string($_SESSION['admin_delete_member_csrf'])) {
        $_SESSION['admin_delete_member_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_delete_member_csrf'];
}

function ddDeleteMemberValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_delete_member_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($submittedToken) || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddDeletePlanCatalog(): array
{
    return [
        'founder_walk_club' => 'Founder Walk Club',
        'founder_care_club' => 'Founder Care Club',
        'founder_elite_club' => 'Founder Elite Club',
    ];
}

function ddPlanNameFromMembership(PDO $pdo, int $planId): string
{
    if ($planId <= 0 || !hasTable($pdo, 'membership_plans')) {
        return '';
    }

    $planColumns = getTableColumns($pdo, 'membership_plans');
    $planIdCol = firstExistingColumn($planColumns, ['id', 'plan_id']);
    $slugCol = firstExistingColumn($planColumns, ['slug', 'plan_slug', 'code']);
    $nameCol = firstExistingColumn($planColumns, ['name', 'plan_name', 'title']);

    if ($planIdCol === null) {
        return '';
    }

    $sql = 'SELECT * FROM ' . quotedIdentifier('membership_plans') . ' WHERE ' . quotedIdentifier($planIdCol) . ' = :plan_id LIMIT 1';
    $row = safeFetchOne($pdo, $sql, [':plan_id' => $planId]);

    if (!$row) {
        return '';
    }

    if ($nameCol !== null && !empty($row[$nameCol])) {
        return (string) $row[$nameCol];
    }

    if ($slugCol !== null && !empty($row[$slugCol])) {
        $slug = strtolower(trim((string) $row[$slugCol]));
        $catalog = ddDeletePlanCatalog();

        if (isset($catalog[$slug])) {
            return $catalog[$slug];
        }

        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }

    return '';
}

function ddMembershipNameForMember(PDO $pdo, int $memberId, string $fallback = ''): string
{
    if ($memberId <= 0 || !hasTable($pdo, 'member_memberships')) {
        return $fallback;
    }

    $membershipColumns = getTableColumns($pdo, 'member_memberships');
    $memberCol = firstExistingColumn($membershipColumns, ['member_id', 'user_id']);
    $planCol = firstExistingColumn($membershipColumns, ['plan_id']);
    $orderCol = firstExistingColumn($membershipColumns, ['created_at', 'updated_at', 'id']);

    if ($memberCol === null || $planCol === null) {
        return $fallback;
    }

    if ($orderCol === null) {
        $orderCol = 'id';
    }

    $sql = '
        SELECT *
        FROM ' . quotedIdentifier('member_memberships') . '
        WHERE ' . quotedIdentifier($memberCol) . ' = :member_id
        ORDER BY ' . quotedIdentifier($orderCol) . ' DESC, ' . quotedIdentifier('id') . ' DESC
        LIMIT 1
    ';

    $membershipRow = safeFetchOne($pdo, $sql, [':member_id' => $memberId]);

    if (!$membershipRow) {
        return $fallback;
    }

    $planName = ddPlanNameFromMembership($pdo, (int) ($membershipRow[$planCol] ?? 0));
    return $planName !== '' ? $planName : $fallback;
}

function fetchMemberRecord(PDO $pdo, int $memberId): ?array
{
    if ($memberId <= 0) {
        return null;
    }

    if (hasTable($pdo, 'users')) {
        $columns = getTableColumns($pdo, 'users');
        $idCol = firstExistingColumn($columns, ['id', 'user_id']);
        $roleCol = firstExistingColumn($columns, ['role', 'user_role', 'account_type']);
        $isAdminCol = in_array('is_admin', $columns, true) ? 'is_admin' : null;

        if ($idCol !== null) {
            $conditions = [quotedIdentifier($idCol) . ' = :member_id'];

            if ($roleCol !== null) {
                $conditions[] = 'LOWER(COALESCE(' . quotedIdentifier($roleCol) . ", 'member')) NOT IN ('admin','administrator','walker','staff','employee','owner','superadmin')";
            }

            if ($isAdminCol !== null) {
                $conditions[] = 'COALESCE(' . quotedIdentifier($isAdminCol) . ', 0) = 0';
            }

            $row = safeFetchOne(
                $pdo,
                'SELECT * FROM ' . quotedIdentifier('users') . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
                [':member_id' => $memberId]
            );

            if ($row !== null) {
                $row['_source_table'] = 'users';
                return $row;
            }
        }
    }

    foreach (['members', 'client_profiles'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $columns = getTableColumns($pdo, $table);
        $idCol = firstExistingColumn($columns, ['id', 'user_id', 'member_id', 'client_id']);
        if ($idCol === null) {
            continue;
        }

        $row = safeFetchOne(
            $pdo,
            'SELECT * FROM ' . quotedIdentifier($table) . ' WHERE ' . quotedIdentifier($idCol) . ' = :member_id LIMIT 1',
            [':member_id' => $memberId]
        );

        if ($row !== null) {
            $row['_source_table'] = $table;
            return $row;
        }
    }

    return null;
}

function normalizeMemberRecord(PDO $pdo, array $row, int $memberId): array
{
    $membershipFallback = valueFromRow($row, ['membership_type', 'membership', 'plan_type']);

    return [
        'member_id' => $memberId,
        'source_table' => (string) ($row['_source_table'] ?? ''),
        'full_name' => buildFullName($row),
        'email' => trim(valueFromRow($row, ['email'])),
        'phone' => trim(valueFromRow($row, ['phone', 'phone_number', 'mobile', 'cell_phone'])),
        'username' => trim(valueFromRow($row, ['username'])),
        'preferred_login' => trim(valueFromRow($row, ['preferred_login'])),
        'membership_type' => ddMembershipNameForMember($pdo, $memberId, $membershipFallback),
        'created_at' => trim(valueFromRow($row, ['created_at', 'date_created', 'registered_at'])),
        'address_display' => buildAddress($row),
    ];
}

function deleteByBookingIds(PDO $pdo, string $table, array $bookingIds): int
{
    if (empty($bookingIds) || !hasTable($pdo, $table)) {
        return 0;
    }

    $columns = getTableColumns($pdo, $table);
    $bookingIdCol = firstExistingColumn($columns, ['booking_id']);
    if ($bookingIdCol === null) {
        return 0;
    }

    $placeholders = [];
    $params = [];

    foreach (array_values(array_unique(array_map('intval', $bookingIds))) as $index => $bookingId) {
        $placeholder = ':booking_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $bookingId;
    }

    if (empty($placeholders)) {
        return 0;
    }

    $sql = 'DELETE FROM ' . quotedIdentifier($table) . ' WHERE ' . quotedIdentifier($bookingIdCol) . ' IN (' . implode(', ', $placeholders) . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

function fetchBookingIdsForMember(PDO $pdo, int $memberId): array
{
    if ($memberId <= 0 || !hasTable($pdo, 'bookings')) {
        return [];
    }

    $columns = getTableColumns($pdo, 'bookings');
    $bookingIdCol = firstExistingColumn($columns, ['id', 'booking_id']);
    if ($bookingIdCol === null) {
        return [];
    }

    $referenceColumns = [];
    foreach (['user_id', 'member_id', 'client_id', 'customer_id'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $referenceColumns[] = $candidate;
        }
    }

    if (empty($referenceColumns)) {
        return [];
    }

    $conditions = [];
    $params = [':member_id' => $memberId];

    foreach ($referenceColumns as $column) {
        $conditions[] = quotedIdentifier($column) . ' = :member_id';
    }

    $rows = safeFetchAll(
        $pdo,
        'SELECT ' . quotedIdentifier($bookingIdCol) . ' AS booking_id FROM ' . quotedIdentifier('bookings') . ' WHERE ' . implode(' OR ', $conditions),
        $params
    );

    $ids = [];
    foreach ($rows as $row) {
        $value = (int) ($row['booking_id'] ?? 0);
        if ($value > 0) {
            $ids[] = $value;
        }
    }

    return array_values(array_unique($ids));
}

function deleteRowsByIdentifiers(PDO $pdo, string $table, array $numericColumns, int $memberId, string $email = '', string $username = ''): int
{
    if ($memberId <= 0 || !hasTable($pdo, $table)) {
        return 0;
    }

    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return 0;
    }

    $conditions = [];
    $params = [];

    foreach ($numericColumns as $column) {
        if (in_array($column, $columns, true)) {
            $conditions[] = quotedIdentifier($column) . ' = :member_id';
            $params[':member_id'] = $memberId;
        }
    }

    if ($email !== '' && in_array('email', $columns, true)) {
        $conditions[] = 'LOWER(COALESCE(' . quotedIdentifier('email') . ", '')) = LOWER(:email)";
        $params[':email'] = $email;
    }

    if ($username !== '' && in_array('username', $columns, true)) {
        $conditions[] = 'LOWER(COALESCE(' . quotedIdentifier('username') . ", '')) = LOWER(:username)";
        $params[':username'] = $username;
    }

    if (empty($conditions)) {
        return 0;
    }

    $sql = 'DELETE FROM ' . quotedIdentifier($table) . ' WHERE ' . implode(' OR ', array_unique($conditions));

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

function deleteWalkTrackingByWalkSessionIds(PDO $pdo, array $walkSessionIds): int
{
    if (empty($walkSessionIds) || !hasTable($pdo, 'walk_tracking')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'walk_tracking');
    $walkSessionCol = firstExistingColumn($columns, ['walk_session_id']);
    if ($walkSessionCol === null) {
        return 0;
    }

    $placeholders = [];
    $params = [];

    foreach (array_values(array_unique(array_map('intval', $walkSessionIds))) as $index => $walkSessionId) {
        $placeholder = ':walk_session_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $walkSessionId;
    }

    if (empty($placeholders)) {
        return 0;
    }

    $sql = 'DELETE FROM ' . quotedIdentifier('walk_tracking') . ' WHERE ' . quotedIdentifier($walkSessionCol) . ' IN (' . implode(', ', $placeholders) . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

function fetchWalkSessionIdsByBookingIds(PDO $pdo, array $bookingIds): array
{
    if (empty($bookingIds) || !hasTable($pdo, 'walk_sessions')) {
        return [];
    }

    $columns = getTableColumns($pdo, 'walk_sessions');
    $walkSessionIdCol = firstExistingColumn($columns, ['id', 'walk_session_id']);
    $bookingIdCol = firstExistingColumn($columns, ['booking_id']);

    if ($walkSessionIdCol === null || $bookingIdCol === null) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach (array_values(array_unique(array_map('intval', $bookingIds))) as $index => $bookingId) {
        $placeholder = ':booking_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $bookingId;
    }

    if (empty($placeholders)) {
        return [];
    }

    $rows = safeFetchAll(
        $pdo,
        'SELECT ' . quotedIdentifier($walkSessionIdCol) . ' AS walk_session_id FROM ' . quotedIdentifier('walk_sessions') . ' WHERE ' . quotedIdentifier($bookingIdCol) . ' IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    $ids = [];
    foreach ($rows as $row) {
        $value = (int) ($row['walk_session_id'] ?? 0);
        if ($value > 0) {
            $ids[] = $value;
        }
    }

    return array_values(array_unique($ids));
}

function deleteMemberAndLinkedRows(PDO $pdo, int $memberId, string $email = '', string $username = ''): array
{
    $summary = [];

    $bookingIds = fetchBookingIdsForMember($pdo, $memberId);
    $walkSessionIds = fetchWalkSessionIdsByBookingIds($pdo, $bookingIds);

    $pdo->beginTransaction();

    try {
        $deleted = deleteWalkTrackingByWalkSessionIds($pdo, $walkSessionIds);
        if ($deleted > 0) {
            $summary[] = $deleted . ' walk tracking row(s)';
        }

        foreach (['walk_tracking', 'booking_status_history'] as $table) {
            $deleted = deleteByBookingIds($pdo, $table, $bookingIds);
            if ($deleted > 0) {
                $summary[] = $deleted . ' ' . str_replace('_', ' ', $table) . ' row(s)';
            }
        }

        $tableMap = [
            'walk_sessions' => ['booking_id', 'user_id', 'member_id', 'client_id'],
            'bookings' => ['user_id', 'member_id', 'client_id', 'customer_id'],
            'notifications' => ['user_id', 'member_id', 'client_id'],
            'custom_plans' => ['user_id', 'member_id', 'client_id'],
            'member_memberships' => ['user_id', 'member_id', 'client_id'],
            'membership_entitlements' => ['user_id', 'member_id', 'client_id'],
            'membership_transactions' => ['user_id', 'member_id', 'client_id'],
            'membership_walk_plans' => ['user_id', 'member_id', 'client_id'],
            'pets' => ['user_id', 'member_id', 'client_id', 'owner_id', 'customer_id'],
            'dogs' => ['user_id', 'member_id', 'client_id', 'owner_id', 'customer_id'],
            'referrals' => ['user_id', 'member_id', 'client_id', 'referrer_user_id', 'referrer_member_id'],
            'members' => ['id', 'user_id', 'member_id', 'client_id'],
            'client_profiles' => ['id', 'user_id', 'member_id', 'client_id'],
            'users' => ['id', 'user_id', 'member_id', 'client_id'],
        ];

        foreach ($tableMap as $table => $numericColumns) {
            $deleted = deleteRowsByIdentifiers($pdo, $table, $numericColumns, $memberId, $email, $username);
            if ($deleted > 0) {
                $summary[] = $deleted . ' ' . str_replace('_', ' ', $table) . ' row(s)';
            }
        }

        $pdo->commit();
        return [
            'ok' => true,
            'summary' => $summary,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'summary' => [],
        ];
    }
}

$memberId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['member_id'] ?? 0);
$memberRow = fetchMemberRecord($pdo, $memberId);

if ($memberRow === null) {
    redirectTo('admin-members.php?notice=member_not_found');
}

$member = normalizeMemberRecord($pdo, $memberRow, $memberId);
$error = '';
$deletionSummary = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!ddDeleteMemberValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif ($action === 'cancel') {
        redirectTo('admin-members.php?notice=member_delete_cancelled');
    } elseif ($action !== 'delete') {
        $error = 'Invalid action requested.';
    } elseif (!isset($_POST['confirm_delete']) || (string) $_POST['confirm_delete'] !== '1') {
        $error = 'Please confirm the deletion before continuing.';
    } else {
        $result = deleteMemberAndLinkedRows($pdo, $memberId, $member['email'], $member['username']);

        if (!$result['ok']) {
            $error = 'Member could not be deleted. Please try again.';
        } else {
            $deletionSummary = is_array($result['summary']) ? $result['summary'] : [];
            $redirectUrl = 'admin-members.php?notice=member_deleted&member=' . rawurlencode($member['full_name']);
            redirectTo($redirectUrl);
        }
    }
}

$csrfToken = ddDeleteMemberCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Member | Doggie Dorian’s</title>
    <meta name="description" content="Delete a member record from the admin system.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #fff;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1180px;
            margin: auto;
            padding: 30px;
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            font-weight: 900;
            font-size: 22px;
            letter-spacing: .03em;
        }

        .nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav a {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: #fff;
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.02fr 0.98fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            padding: 24px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-card {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .danger-card {
            background: linear-gradient(135deg, rgba(214,123,123,0.18), rgba(255,255,255,0.04));
            border-color: rgba(214,123,123,0.22);
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .danger-eyebrow {
            color: #ffb9b9;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 1.2rem;
        }

        p,
        .sub {
            color: rgba(255,255,255,0.76);
            line-height: 1.65;
            margin: 0;
        }

        .flash {
            margin-bottom: 20px;
            padding: 16px 18px;
            border-radius: 18px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border-color: rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-top: 20px;
        }

        .meta-item {
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .meta-label {
            color: rgba(255,255,255,0.56);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .meta-value {
            font-size: 15px;
            font-weight: 800;
            word-break: break-word;
        }

        .warning-list {
            margin: 18px 0 0;
            padding-left: 18px;
            color: rgba(255,255,255,0.76);
            line-height: 1.7;
        }

        .warning-list li + li {
            margin-top: 8px;
        }

        .confirm-box {
            margin-top: 20px;
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-top: 16px;
        }

        .checkbox-row input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            accent-color: #d67b7b;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 18px;
            border-radius: 14px;
            font-size: .95rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f0a3a3, #d67b7b);
            color: #150b0b;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .summary-list {
            margin: 14px 0 0;
            padding-left: 18px;
            color: rgba(255,255,255,0.76);
            line-height: 1.7;
        }

        @media (max-width: 920px) {
            .hero,
            .meta-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top">
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="nav">
                <a href="admin-dashboard.php">Dashboard</a>
                <a href="admin-members.php">Members</a>
                <a href="admin-bookings.php">Bookings</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="flash flash-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-card">
                <div class="eyebrow">Member Deletion Review</div>
                <h1>Delete <?php echo h($member['full_name']); ?>?</h1>
                <div class="sub">
                    Review the member details below before permanently removing this account and any linked records that can be matched safely across your database.
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Member ID</div>
                        <div class="meta-value">#<?php echo h((string) $member['member_id']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Source Table</div>
                        <div class="meta-value"><?php echo h($member['source_table'] !== '' ? $member['source_table'] : 'Unknown'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Email</div>
                        <div class="meta-value"><?php echo h($member['email'] !== '' ? $member['email'] : '—'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Phone</div>
                        <div class="meta-value"><?php echo h($member['phone'] !== '' ? $member['phone'] : '—'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Username</div>
                        <div class="meta-value"><?php echo h($member['username'] !== '' ? $member['username'] : '—'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Membership</div>
                        <div class="meta-value"><?php echo h($member['membership_type'] !== '' ? $member['membership_type'] : '—'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Preferred Login</div>
                        <div class="meta-value"><?php echo h($member['preferred_login'] !== '' ? $member['preferred_login'] : '—'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Created</div>
                        <div class="meta-value"><?php echo h(formatDateTimeDisplay($member['created_at'])); ?></div>
                    </div>
                    <div class="meta-item" style="grid-column: 1 / -1;">
                        <div class="meta-label">Address</div>
                        <div class="meta-value"><?php echo h($member['address_display'] !== '' ? $member['address_display'] : '—'); ?></div>
                    </div>
                </div>
            </div>

            <div class="card danger-card">
                <div class="eyebrow danger-eyebrow">Permanent Action</div>
                <h2>This cannot be undone automatically.</h2>
                <p>
                    This page is designed so the member listing stays clean, while the actual delete action runs separately with a confirmation step, CSRF protection, and a database transaction.
                </p>

                <ul class="warning-list">
                    <li>The delete flow attempts to remove matched records from core member-related tables such as users, members, client profiles, memberships, pets, bookings, and linked status/tracking rows when matching columns exist.</li>
                    <li>If your live schema differs from expected table structures, unmatched rows will simply be skipped rather than causing the whole page to break.</li>
                    <li>You should make a fresh backup of <strong>data/members.sqlite</strong> before using permanent deletion on a live account.</li>
                </ul>

                <?php if (!empty($deletionSummary)): ?>
                    <ul class="summary-list">
                        <?php foreach ($deletionSummary as $line): ?>
                            <li><?php echo h($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="post" action="admin-delete-member.php?id=<?php echo (int) $member['member_id']; ?>" class="confirm-box">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="member_id" value="<?php echo (int) $member['member_id']; ?>">

                    <div class="checkbox-row">
                        <input type="checkbox" id="confirm_delete" name="confirm_delete" value="1">
                        <label for="confirm_delete">
                            I understand this permanently deletes the selected member account and any linked rows that can be safely matched by the delete handler.
                        </label>
                    </div>

                    <div class="actions">
                        <button type="submit" name="action" value="delete" class="btn btn-danger">Delete Member Permanently</button>
                        <button type="submit" name="action" value="cancel" class="btn btn-light">Cancel</button>
                        <a href="admin-members.php" class="btn btn-light">Back to Members</a>
                    </div>
                </form>
            </div>
        </section>
    </div>
</body>
</html>