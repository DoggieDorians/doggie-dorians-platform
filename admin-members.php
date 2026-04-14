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
    } catch (Throwable $e) {
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
        $safeTable = quotedIdentifier($table);
        $stmt = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
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
    } catch (Throwable $e) {
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
    } catch (Throwable $e) {
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
    } catch (Throwable $e) {
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

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('F j, Y \a\t g:i A', $ts);
}

function ddPlanCatalog(): array
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
        $catalog = ddPlanCatalog();

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

function fetchMembersFromUsers(PDO $pdo): array
{
    if (!hasTable($pdo, 'users')) {
        return [];
    }

    $userColumns = getTableColumns($pdo, 'users');

    $idCol = firstExistingColumn($userColumns, ['id', 'user_id']);
    if ($idCol === null) {
        return [];
    }

    $nameCol = firstExistingColumn($userColumns, ['full_name', 'name', 'display_name']);
    $firstCol = firstExistingColumn($userColumns, ['first_name']);
    $lastCol = firstExistingColumn($userColumns, ['last_name']);
    $emailCol = firstExistingColumn($userColumns, ['email']);
    $phoneCol = firstExistingColumn($userColumns, ['phone', 'phone_number', 'mobile', 'cell_phone']);
    $usernameCol = firstExistingColumn($userColumns, ['username']);
    $membershipCol = firstExistingColumn($userColumns, ['membership_type', 'membership', 'plan_type']);
    $preferredLoginCol = firstExistingColumn($userColumns, ['preferred_login']);
    $createdCol = firstExistingColumn($userColumns, ['created_at', 'date_created', 'registered_at']);
    $addressCol = firstExistingColumn($userColumns, ['address', 'street_address']);
    $cityCol = firstExistingColumn($userColumns, ['city']);
    $stateCol = firstExistingColumn($userColumns, ['state', 'province']);
    $zipCol = firstExistingColumn($userColumns, ['zip', 'zipcode', 'postal_code']);
    $aptCol = firstExistingColumn($userColumns, ['apartment_number', 'apartment', 'apt']);
    $roleCol = firstExistingColumn($userColumns, ['role', 'user_role', 'account_type']);
    $isAdminCol = in_array('is_admin', $userColumns, true) ? 'is_admin' : null;

    $select = [
        quotedIdentifier($idCol) . ' AS member_id',
        ($nameCol !== null ? quotedIdentifier($nameCol) : "''") . ' AS full_name',
        ($firstCol !== null ? quotedIdentifier($firstCol) : "''") . ' AS first_name',
        ($lastCol !== null ? quotedIdentifier($lastCol) : "''") . ' AS last_name',
        ($emailCol !== null ? quotedIdentifier($emailCol) : "''") . ' AS email',
        ($phoneCol !== null ? quotedIdentifier($phoneCol) : "''") . ' AS phone',
        ($usernameCol !== null ? quotedIdentifier($usernameCol) : "''") . ' AS username',
        ($membershipCol !== null ? quotedIdentifier($membershipCol) : "''") . ' AS membership_type',
        ($preferredLoginCol !== null ? quotedIdentifier($preferredLoginCol) : "''") . ' AS preferred_login',
        ($createdCol !== null ? quotedIdentifier($createdCol) : "''") . ' AS created_at',
        ($addressCol !== null ? quotedIdentifier($addressCol) : "''") . ' AS address',
        ($cityCol !== null ? quotedIdentifier($cityCol) : "''") . ' AS city',
        ($stateCol !== null ? quotedIdentifier($stateCol) : "''") . ' AS state',
        ($zipCol !== null ? quotedIdentifier($zipCol) : "''") . ' AS zip_code',
        ($aptCol !== null ? quotedIdentifier($aptCol) : "''") . ' AS apartment_number',
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . quotedIdentifier('users');

    $conditions = [];
    if ($roleCol !== null) {
        $conditions[] = 'LOWER(COALESCE(' . quotedIdentifier($roleCol) . ", 'member')) NOT IN ('admin','administrator','walker','staff','employee','owner','superadmin')";
    }
    if ($isAdminCol !== null) {
        $conditions[] = 'COALESCE(' . quotedIdentifier($isAdminCol) . ', 0) = 0';
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    if ($createdCol !== null) {
        $sql .= ' ORDER BY ' . quotedIdentifier($createdCol) . ' DESC';
    } else {
        $sql .= ' ORDER BY ' . quotedIdentifier($idCol) . ' DESC';
    }

    $rows = safeFetchAll($pdo, $sql);

    foreach ($rows as &$row) {
        $row['full_name'] = buildFullName($row);
        $fallbackMembership = valueFromRow($row, ['membership_type']);
        $row['membership_type'] = ddMembershipNameForMember($pdo, (int) valueFromRow($row, ['member_id'], '0'), $fallbackMembership);
        $row['address_display'] = buildAddress($row);
    }
    unset($row);

    return $rows;
}

function fetchMembersFallback(PDO $pdo): array
{
    $possibleTables = ['members', 'client_profiles'];

    foreach ($possibleTables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $columns = getTableColumns($pdo, $table);
        if (empty($columns)) {
            continue;
        }

        $idCol = firstExistingColumn($columns, ['id', 'user_id', 'member_id', 'client_id']);
        if ($idCol === null) {
            continue;
        }

        $nameCol = firstExistingColumn($columns, ['full_name', 'name', 'client_name', 'member_name']);
        $firstCol = firstExistingColumn($columns, ['first_name']);
        $lastCol = firstExistingColumn($columns, ['last_name']);
        $emailCol = firstExistingColumn($columns, ['email']);
        $phoneCol = firstExistingColumn($columns, ['phone', 'phone_number', 'mobile', 'cell_phone']);
        $usernameCol = firstExistingColumn($columns, ['username']);
        $membershipCol = firstExistingColumn($columns, ['membership_type', 'membership', 'plan_type']);
        $preferredLoginCol = firstExistingColumn($columns, ['preferred_login']);
        $createdCol = firstExistingColumn($columns, ['created_at', 'date_created', 'registered_at']);
        $addressCol = firstExistingColumn($columns, ['address', 'street_address']);
        $cityCol = firstExistingColumn($columns, ['city']);
        $stateCol = firstExistingColumn($columns, ['state', 'province']);
        $zipCol = firstExistingColumn($columns, ['zip', 'zipcode', 'postal_code']);
        $aptCol = firstExistingColumn($columns, ['apartment_number', 'apartment', 'apt']);

        $select = [
            quotedIdentifier($idCol) . ' AS member_id',
            ($nameCol !== null ? quotedIdentifier($nameCol) : "''") . ' AS full_name',
            ($firstCol !== null ? quotedIdentifier($firstCol) : "''") . ' AS first_name',
            ($lastCol !== null ? quotedIdentifier($lastCol) : "''") . ' AS last_name',
            ($emailCol !== null ? quotedIdentifier($emailCol) : "''") . ' AS email',
            ($phoneCol !== null ? quotedIdentifier($phoneCol) : "''") . ' AS phone',
            ($usernameCol !== null ? quotedIdentifier($usernameCol) : "''") . ' AS username',
            ($membershipCol !== null ? quotedIdentifier($membershipCol) : "''") . ' AS membership_type',
            ($preferredLoginCol !== null ? quotedIdentifier($preferredLoginCol) : "''") . ' AS preferred_login',
            ($createdCol !== null ? quotedIdentifier($createdCol) : "''") . ' AS created_at',
            ($addressCol !== null ? quotedIdentifier($addressCol) : "''") . ' AS address',
            ($cityCol !== null ? quotedIdentifier($cityCol) : "''") . ' AS city',
            ($stateCol !== null ? quotedIdentifier($stateCol) : "''") . ' AS state',
            ($zipCol !== null ? quotedIdentifier($zipCol) : "''") . ' AS zip_code',
            ($aptCol !== null ? quotedIdentifier($aptCol) : "''") . ' AS apartment_number',
        ];

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . quotedIdentifier($table);

        if ($createdCol !== null) {
            $sql .= ' ORDER BY ' . quotedIdentifier($createdCol) . ' DESC';
        } else {
            $sql .= ' ORDER BY ' . quotedIdentifier($idCol) . ' DESC';
        }

        $rows = safeFetchAll($pdo, $sql);

        foreach ($rows as &$row) {
            $row['full_name'] = buildFullName($row);
            $fallbackMembership = valueFromRow($row, ['membership_type']);
            $row['membership_type'] = ddMembershipNameForMember($pdo, (int) valueFromRow($row, ['member_id'], '0'), $fallbackMembership);
            $row['address_display'] = buildAddress($row);
        }
        unset($row);

        if (!empty($rows)) {
            return $rows;
        }
    }

    return [];
}

function fetchMembers(PDO $pdo): array
{
    $rows = fetchMembersFromUsers($pdo);
    if (!empty($rows)) {
        return $rows;
    }

    return fetchMembersFallback($pdo);
}

$members = fetchMembers($pdo);
$totalMembers = count($members);

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($search !== '') {
    $filtered = [];

    foreach ($members as $member) {
        $haystack = strtolower(
            valueFromRow($member, ['full_name']) . ' ' .
            valueFromRow($member, ['email']) . ' ' .
            valueFromRow($member, ['phone']) . ' ' .
            valueFromRow($member, ['username']) . ' ' .
            valueFromRow($member, ['membership_type']) . ' ' .
            valueFromRow($member, ['address_display'])
        );

        if (strpos($haystack, strtolower($search)) !== false) {
            $filtered[] = $member;
        }
    }

    $members = $filtered;
}

$withPhone = 0;
$withEmail = 0;
$recentSignups = 0;
$membersWithMembership = 0;
$thirtyDaysAgo = strtotime('-30 days');

foreach ($members as $member) {
    if (trim(valueFromRow($member, ['phone'])) !== '') {
        $withPhone++;
    }
    if (trim(valueFromRow($member, ['email'])) !== '') {
        $withEmail++;
    }
    if (trim(valueFromRow($member, ['membership_type'])) !== '') {
        $membersWithMembership++;
    }

    $createdAt = valueFromRow($member, ['created_at']);
    $createdTs = strtotime($createdAt);
    if ($createdTs !== false && $createdTs >= $thirtyDaysAgo) {
        $recentSignups++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Members | Doggie Dorian’s</title>
    <meta name="description" content="View all member signups and account details.">
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
            max-width: 1450px;
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
            grid-template-columns: 1.08fr 0.92fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            padding: 22px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-card {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.2rem;
        }

        .sub {
            color: rgba(255,255,255,0.74);
            line-height: 1.65;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: rgba(255,255,255,0.04);
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.06);
        }

        .label {
            color: #aaa;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .big {
            font-size: 28px;
            font-weight: 900;
        }

        .search-form {
            display: grid;
            gap: 12px;
        }

        .search-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
        }

        input {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            margin-top: 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.68);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .member-name {
            font-weight: 900;
            font-size: 15px;
        }

        .member-sub {
            color: rgba(255,255,255,0.64);
            font-size: 12px;
            margin-top: 4px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: rgba(198,178,139,0.16);
            color: #f3e5c7;
            border: 1px solid rgba(198,178,139,0.28);
        }

        .muted {
            color: rgba(255,255,255,0.62);
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.62);
            margin-top: 24px;
        }

        .actions-col {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.10);
        }

        @media (max-width: 1180px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }

            .search-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
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
                <a href="admin-revenue.php">Revenue</a>
                <a href="admin-bookings.php">Bookings</a>
                <a href="admin-members.php">Members</a>
                <a href="admin-group-walk-applications.php">Group Walks</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <section class="hero">
            <div class="card hero-card">
                <div class="eyebrow">Member Directory</div>
                <h1>Admin Members</h1>
                <div class="sub">
                    View everyone who signed up as a member, review their contact details, and open each full member profile from one centralized admin directory.
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Search Members</div>
                <h2>Find a member fast</h2>
                <form class="search-form" method="get" action="admin-members.php">
                    <div class="search-row">
                        <input
                            type="text"
                            name="q"
                            value="<?php echo h($search); ?>"
                            placeholder="Search by name, email, phone, username, membership, or address"
                        >
                        <button type="submit" class="btn btn-gold">Search</button>
                        <a href="admin-members.php" class="btn btn-light">Reset</a>
                    </div>
                </form>
            </div>
        </section>

        <div class="grid">
            <div class="stat-card">
                <div class="label">Visible Members</div>
                <div class="big"><?php echo (int) count($members); ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Total Members</div>
                <div class="big"><?php echo (int) $totalMembers; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">With Email</div>
                <div class="big"><?php echo (int) $withEmail; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">With Phone</div>
                <div class="big"><?php echo (int) $withPhone; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">With Membership</div>
                <div class="big"><?php echo (int) $membersWithMembership; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">New 30 Days</div>
                <div class="big"><?php echo (int) $recentSignups; ?></div>
            </div>
        </div>

        <?php if (empty($members)): ?>
            <div class="empty">No member records matched your search.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Username</th>
                            <th>Membership</th>
                            <th>Preferred Login</th>
                            <th>Address</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php
                            $memberId = (int) valueFromRow($member, ['member_id'], '0');
                            $fullAddress = trim(valueFromRow($member, ['address_display']));
                            ?>
                            <tr>
                                <td>
                                    <div class="member-name"><?php echo h(valueFromRow($member, ['full_name'], '—')); ?></div>
                                    <div class="member-sub">ID #<?php echo h((string) $memberId); ?></div>
                                </td>
                                <td><?php echo h(valueFromRow($member, ['email'], '—')); ?></td>
                                <td><?php echo h(valueFromRow($member, ['phone'], '—')); ?></td>
                                <td><?php echo h(valueFromRow($member, ['username'], '—')); ?></td>
                                <td>
                                    <?php if (trim(valueFromRow($member, ['membership_type'])) !== ''): ?>
                                        <span class="pill"><?php echo h(valueFromRow($member, ['membership_type'])); ?></span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h(valueFromRow($member, ['preferred_login'], '—')); ?></td>
                                <td><?php echo h($fullAddress !== '' ? $fullAddress : '—'); ?></td>
                                <td><?php echo h(formatDateTimeDisplay(valueFromRow($member, ['created_at'], ''))); ?></td>
                                <td>
                                    <div class="actions-col">
                                        <a class="action-link" href="admin-member-view.php?id=<?php echo $memberId; ?>">View</a>
                                        <a class="action-link" href="admin-add-dog.php?user_id=<?php echo $memberId; ?>">Add Dog</a>
                                        <a class="action-link" href="admin-create-booking.php?user_id=<?php echo $memberId; ?>">Create Booking</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>