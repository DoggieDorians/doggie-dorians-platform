<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminDashboardEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminDashboardRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminDashboardNormalizeRole($value)
{
    return strtolower(trim((string) $value));
}

function ddAdminDashboardSessionBool(string $key): bool
{
    return isset($_SESSION[$key]) && $_SESSION[$key] === true;
}

function ddAdminDashboardSessionNonempty(string $key): bool
{
    return isset($_SESSION[$key]) && $_SESSION[$key] !== '' && $_SESSION[$key] !== null;
}

function ddAdminDashboardIsAdmin(): bool
{
    $roleCandidates = array(
        ddAdminDashboardNormalizeRole($_SESSION['role'] ?? ''),
        ddAdminDashboardNormalizeRole($_SESSION['user_role'] ?? ''),
        ddAdminDashboardNormalizeRole($_SESSION['user_type'] ?? ''),
        ddAdminDashboardNormalizeRole($_SESSION['account_type'] ?? ''),
        ddAdminDashboardNormalizeRole($_SESSION['access_role'] ?? ''),
        ddAdminDashboardNormalizeRole($_SESSION['admin']['role'] ?? ''),
    );

    $hasAdminRole = in_array('admin', $roleCandidates, true);

    $hasAdminFlag = (
        ddAdminDashboardSessionBool('admin_logged_in')
        || ddAdminDashboardSessionBool('is_admin')
        || (
            isset($_SESSION['admin'])
            && is_array($_SESSION['admin'])
            && (
                (!empty($_SESSION['admin']['logged_in']) && $_SESSION['admin']['logged_in'] === true)
                || (!empty($_SESSION['admin']['is_admin']) && $_SESSION['admin']['is_admin'] === true)
            )
        )
    );

    $hasAdminIdentity = (
        ddAdminDashboardSessionNonempty('admin_id')
        || ddAdminDashboardSessionNonempty('admin_email')
        || ddAdminDashboardSessionNonempty('admin_name')
        || (
            isset($_SESSION['admin'])
            && is_array($_SESSION['admin'])
            && !empty($_SESSION['admin'])
        )
    );

    return ($hasAdminFlag && ($hasAdminRole || $hasAdminIdentity))
        || ($hasAdminRole && $hasAdminIdentity);
}

function ddAdminDashboardNormalizeAdminSession(): void
{
    if (!ddAdminDashboardIsAdmin()) {
        return;
    }

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['is_admin'] = true;

    if (empty($_SESSION['role'])) {
        $_SESSION['role'] = 'admin';
    }

    if (empty($_SESSION['user_role'])) {
        $_SESSION['user_role'] = 'admin';
    }

    if (empty($_SESSION['admin_name']) && !empty($_SESSION['admin']['name'])) {
        $_SESSION['admin_name'] = (string) $_SESSION['admin']['name'];
    }

    if (empty($_SESSION['admin_email']) && !empty($_SESSION['admin']['email'])) {
        $_SESSION['admin_email'] = (string) $_SESSION['admin']['email'];
    }

    if (empty($_SESSION['admin_id'])) {
        if (!empty($_SESSION['admin']['id'])) {
            $_SESSION['admin_id'] = (int) $_SESSION['admin']['id'];
        } elseif (!empty($_SESSION['user_id'])) {
            $_SESSION['admin_id'] = (int) $_SESSION['user_id'];
        }
    }

    if (empty($_SESSION['user_id']) && !empty($_SESSION['admin_id'])) {
        $_SESSION['user_id'] = (int) $_SESSION['admin_id'];
    }
}

if (!ddAdminDashboardIsAdmin()) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

ddAdminDashboardNormalizeAdminSession();

function ddAdminDashboardCsrfToken(): string
{
    if (empty($_SESSION['admin_dashboard_csrf']) || !is_string($_SESSION['admin_dashboard_csrf'])) {
        $_SESSION['admin_dashboard_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_dashboard_csrf'];
}

function ddAdminDashboardValidateCsrfToken($token): bool
{
    $sessionToken = $_SESSION['admin_dashboard_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return is_string($token) && hash_equals($sessionToken, $token);
}

function ddAdminDashboardQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminDashboardSafeExecute(PDOStatement $stmt, array $params = array()): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function ddAdminDashboardSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ddAdminDashboardSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return array();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    }
}

function ddAdminDashboardTableExists(PDO $pdo, string $table): bool
{
    $row = ddAdminDashboardSafeFetchOne(
        $pdo,
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
        array(':table' => $table)
    );

    return $row !== null;
}

function ddAdminDashboardHasTable(PDO $pdo, string $table): bool
{
    return ddAdminDashboardTableExists($pdo, $table);
}

function ddAdminDashboardGetTableColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminDashboardTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $sql = 'PRAGMA table_info(' . ddAdminDashboardQuoteIdentifier($table) . ')';
        $rows = $pdo->query($sql);
        if (!($rows instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $columns = array();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminDashboardFirstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = ddAdminDashboardGetTableColumns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminDashboardValueFromRow(?array $row, array $keys = array(), $default = null)
{
    if ($row === null) {
        return $default;
    }

    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }

    return $default;
}

function ddAdminDashboardCountTable(PDO $pdo, string $table): int
{
    if (!ddAdminDashboardTableExists($pdo, $table)) {
        return 0;
    }

    $row = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT COUNT(*) AS count_value FROM ' . ddAdminDashboardQuoteIdentifier($table)
    );

    return (int) ddAdminDashboardValueFromRow($row, array('count_value'), 0);
}

function ddAdminDashboardFetchMemberCount(PDO $pdo): int
{
    return count(ddAdminDashboardFetchMemberOptions($pdo));
}

function ddAdminDashboardCountUnreadNotifications(PDO $pdo): int
{
    $table = 'notifications';
    if (!ddAdminDashboardTableExists($pdo, $table)) {
        return 0;
    }

    $statusCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('is_read', 'read_flag', 'status'));
    if ($statusCol === null) {
        return ddAdminDashboardCountTable($pdo, $table);
    }

    if ($statusCol === 'status') {
        $row = ddAdminDashboardSafeFetchOne(
            $pdo,
            'SELECT COUNT(*) AS count_value FROM ' . ddAdminDashboardQuoteIdentifier($table)
            . ' WHERE LOWER(COALESCE(' . ddAdminDashboardQuoteIdentifier($statusCol) . ", '')) IN ('unread', 'new')"
        );

        return (int) ddAdminDashboardValueFromRow($row, array('count_value'), 0);
    }

    $row = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT COUNT(*) AS count_value FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE COALESCE(' . ddAdminDashboardQuoteIdentifier($statusCol) . ', 0) = 0'
    );

    return (int) ddAdminDashboardValueFromRow($row, array('count_value'), 0);
}

function ddAdminDashboardServiceDisplayName($serviceType): string
{
    $serviceType = strtolower(trim((string) $serviceType));

    $map = array(
        'walk' => 'Walk',
        'walks' => 'Walk',
        'daycare' => 'Daycare',
        'boarding' => 'Boarding',
        'boarding_night' => 'Boarding Night',
        'drop-in' => 'Drop-In',
        'drop_in' => 'Drop-In',
        'dropin' => 'Drop-In',
        'group_walk' => 'Group Walk',
    );

    return isset($map[$serviceType]) ? $map[$serviceType] : ucwords(str_replace(array('-', '_'), ' ', $serviceType));
}

function ddAdminDashboardStatusBadgeClass($status): string
{
    $status = strtolower(trim((string) $status));

    $map = array(
        'pending' => 'badge-pending',
        'new' => 'badge-pending',
        'available' => 'badge-available',
        'accepted' => 'badge-accepted',
        'approved' => 'badge-accepted',
        'in_progress' => 'badge-progress',
        'in-progress' => 'badge-progress',
        'progress' => 'badge-progress',
        'completed' => 'badge-complete',
        'complete' => 'badge-complete',
        'done' => 'badge-complete',
        'cancelled' => 'badge-cancelled',
        'canceled' => 'badge-cancelled',
        'rejected' => 'badge-cancelled',
    );

    return isset($map[$status]) ? $map[$status] : 'badge-pending';
}

function ddAdminDashboardFormatDateDisplay($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y', $timestamp);
}

function ddAdminDashboardFetchRecentMemberBookings(PDO $pdo, int $limit = 5): array
{
    if (ddAdminDashboardTableExists($pdo, 'bookings')) {
        $table = 'bookings';
        $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
        if ($idCol === null) {
            return array();
        }

        $serviceCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('service_type', 'service'));
        $statusCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('status'));
        $dateCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('date', 'booking_date', 'service_date', 'created_at'));
        $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('client_name', 'full_name', 'name'));

        $sql = 'SELECT ' . ddAdminDashboardQuoteIdentifier($idCol) . ' AS id';
        $sql .= $serviceCol ? ', ' . ddAdminDashboardQuoteIdentifier($serviceCol) . ' AS service_type' : ", '' AS service_type";
        $sql .= $statusCol ? ', ' . ddAdminDashboardQuoteIdentifier($statusCol) . ' AS status' : ", '' AS status";
        $sql .= $dateCol ? ', ' . ddAdminDashboardQuoteIdentifier($dateCol) . ' AS date' : ", '' AS date";
        $sql .= $nameCol ? ', ' . ddAdminDashboardQuoteIdentifier($nameCol) . ' AS client_name' : ", '' AS client_name";
        $sql .= ' FROM ' . ddAdminDashboardQuoteIdentifier($table);
        $sql .= ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC LIMIT ' . (int) $limit;

        return ddAdminDashboardSafeFetchAll($pdo, $sql);
    }

    if (ddAdminDashboardTableExists($pdo, 'walks')) {
        $table = 'walks';
        $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
        if ($idCol === null) {
            return array();
        }

        $statusCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('status'));
        $dateCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('date', 'walk_date', 'service_date', 'created_at'));
        $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('client_name', 'full_name', 'name'));

        $sql = 'SELECT ' . ddAdminDashboardQuoteIdentifier($idCol) . " AS id, 'walk' AS service_type";
        $sql .= $statusCol ? ', ' . ddAdminDashboardQuoteIdentifier($statusCol) . ' AS status' : ", '' AS status";
        $sql .= $dateCol ? ', ' . ddAdminDashboardQuoteIdentifier($dateCol) . ' AS date' : ", '' AS date";
        $sql .= $nameCol ? ', ' . ddAdminDashboardQuoteIdentifier($nameCol) . ' AS client_name' : ", '' AS client_name";
        $sql .= ' FROM ' . ddAdminDashboardQuoteIdentifier($table);
        $sql .= ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC LIMIT ' . (int) $limit;

        return ddAdminDashboardSafeFetchAll($pdo, $sql);
    }

    return array();
}

function ddAdminDashboardFetchRecentPublicBookings(PDO $pdo, int $limit = 5): array
{
    $table = 'non_member_bookings';
    if (!ddAdminDashboardTableExists($pdo, $table)) {
        return array();
    }

    $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
    if ($idCol === null) {
        return array();
    }

    $serviceCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('service_type', 'service'));
    $statusCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('status'));
    $dateCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('date', 'booking_date', 'service_date', 'created_at'));
    $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('client_name', 'full_name', 'name'));

    $sql = 'SELECT ' . ddAdminDashboardQuoteIdentifier($idCol) . ' AS id';
    $sql .= $serviceCol ? ', ' . ddAdminDashboardQuoteIdentifier($serviceCol) . ' AS service_type' : ", '' AS service_type";
    $sql .= $statusCol ? ', ' . ddAdminDashboardQuoteIdentifier($statusCol) . ' AS status' : ", '' AS status";
    $sql .= $dateCol ? ', ' . ddAdminDashboardQuoteIdentifier($dateCol) . ' AS date' : ", '' AS date";
    $sql .= $nameCol ? ', ' . ddAdminDashboardQuoteIdentifier($nameCol) . ' AS client_name' : ", '' AS client_name";
    $sql .= ' FROM ' . ddAdminDashboardQuoteIdentifier($table);
    $sql .= ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC LIMIT ' . (int) $limit;

    return ddAdminDashboardSafeFetchAll($pdo, $sql);
}

function ddAdminDashboardFetchRecentGroupWalkApplications(PDO $pdo, int $limit = 5): array
{
    $table = 'group_walk_applications';
    if (!ddAdminDashboardTableExists($pdo, $table)) {
        return array();
    }

    $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
    if ($idCol === null) {
        return array();
    }

    $statusCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('status'));
    $dateCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('date', 'created_at', 'submitted_at'));
    $ownerCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('owner_name', 'client_name', 'full_name', 'name'));
    $dogCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('dog_name', 'pet_name'));

    $sql = 'SELECT ' . ddAdminDashboardQuoteIdentifier($idCol) . ' AS id';
    $sql .= $ownerCol ? ', ' . ddAdminDashboardQuoteIdentifier($ownerCol) . ' AS owner_name' : ", '' AS owner_name";
    $sql .= $dogCol ? ', ' . ddAdminDashboardQuoteIdentifier($dogCol) . ' AS dog_name' : ", '' AS dog_name";
    $sql .= $statusCol ? ', ' . ddAdminDashboardQuoteIdentifier($statusCol) . ' AS status' : ", '' AS status";
    $sql .= $dateCol ? ', ' . ddAdminDashboardQuoteIdentifier($dateCol) . ' AS date' : ", '' AS date";
    $sql .= ' FROM ' . ddAdminDashboardQuoteIdentifier($table);
    $sql .= ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC LIMIT ' . (int) $limit;

    return ddAdminDashboardSafeFetchAll($pdo, $sql);
}

function ddAdminDashboardPlanCatalog(): array
{
    return array(
        'founder_walk_club' => array(
            'name' => 'Founder Walk Club',
            'entitlements' => array(
                'walk' => 12,
                'daycare' => 0,
                'drop-in' => 0,
                'boarding_night' => 0,
            ),
            'quarterly_service_credit' => 250,
        ),
        'founder_care_club' => array(
            'name' => 'Founder Care Club',
            'entitlements' => array(
                'walk' => 16,
                'daycare' => 2,
                'drop-in' => 2,
                'boarding_night' => 0,
            ),
            'quarterly_service_credit' => 500,
        ),
        'founder_elite_club' => array(
            'name' => 'Founder Elite Club',
            'entitlements' => array(
                'walk' => 20,
                'daycare' => 4,
                'drop-in' => 4,
                'boarding_night' => 3,
            ),
            'quarterly_service_credit' => 750,
        ),
    );
}

function ddAdminDashboardMembershipOwnerColumn(PDO $pdo): ?string
{
    if (!ddAdminDashboardHasTable($pdo, 'member_memberships')) {
        return null;
    }

    $columns = ddAdminDashboardGetTableColumns($pdo, 'member_memberships');
    if (in_array('user_id', $columns, true)) {
        return 'user_id';
    }

    if (in_array('member_id', $columns, true)) {
        return 'member_id';
    }

    return null;
}

function ddAdminDashboardUserRoleValue(?array $row): string
{
    if ($row === null) {
        return '';
    }

    foreach (array('role', 'user_role', 'account_type', 'user_type', 'access_role') as $key) {
        if (!isset($row[$key])) {
            continue;
        }

        $value = strtolower(trim((string) $row[$key]));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function ddAdminDashboardIsMemberLikeRow(?array $row): bool
{
    $role = ddAdminDashboardUserRoleValue($row);
    if ($role === '') {
        return true;
    }

    return !in_array($role, array('admin', 'walker', 'staff', 'employee'), true);
}

function ddAdminDashboardBuildPersonName(?array $row, ?string $nameCol, ?string $lastNameCol, string $fallbackPrefix, int $fallbackId): string
{
    if ($row !== null) {
        $name = '';

        if ($nameCol !== null) {
            $name = trim((string) ($row[$nameCol] ?? ''));
        }

        if ($name === '' && isset($row['first_name'])) {
            $name = trim((string) $row['first_name'] . ' ' . (string) ($row['last_name'] ?? ''));
        }

        if ($name !== '' && $lastNameCol !== null && !empty($row[$lastNameCol]) && stripos($name, (string) $row[$lastNameCol]) === false) {
            $name .= ' ' . trim((string) $row[$lastNameCol]);
        }

        if ($name !== '') {
            return $name;
        }
    }

    return $fallbackPrefix . ' #' . $fallbackId;
}

function ddAdminDashboardFindMemberRowByUserId(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !ddAdminDashboardHasTable($pdo, 'members')) {
        return null;
    }

    $userCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('user_id'));
    if ($userCol !== null) {
        $row = ddAdminDashboardSafeFetchOne(
            $pdo,
            'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
            . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userCol) . ' = :user_id LIMIT 1',
            array(':user_id' => $userId)
        );

        if ($row !== null) {
            return $row;
        }
    }

    if (!ddAdminDashboardHasTable($pdo, 'users')) {
        return null;
    }

    $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
    if ($userIdCol === null) {
        return null;
    }

    $userRow = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userIdCol) . ' = :user_id LIMIT 1',
        array(':user_id' => $userId)
    );

    if ($userRow === null) {
        return null;
    }

    return ddAdminDashboardFindMemberRowForUserRow($pdo, $userRow);
}

function ddAdminDashboardFindMemberRowByEmail(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || !ddAdminDashboardHasTable($pdo, 'members')) {
        return null;
    }

    $emailCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('email', 'member_email', 'user_email', 'client_email'));
    if ($emailCol === null) {
        return null;
    }

    return ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
        . ' WHERE LOWER(TRIM(COALESCE(' . ddAdminDashboardQuoteIdentifier($emailCol) . ", ''))) = :email LIMIT 1",
        array(':email' => $email)
    );
}

function ddAdminDashboardFindUserRowByEmail(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || !ddAdminDashboardHasTable($pdo, 'users')) {
        return null;
    }

    $emailCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('email', 'user_email', 'member_email', 'client_email'));
    if ($emailCol === null) {
        return null;
    }

    return ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
        . ' WHERE LOWER(TRIM(COALESCE(' . ddAdminDashboardQuoteIdentifier($emailCol) . ", ''))) = :email LIMIT 1",
        array(':email' => $email)
    );
}

function ddAdminDashboardRowDisplayName(?array $row): string
{
    if ($row === null) {
        return '';
    }

    foreach (array('full_name', 'name', 'client_name', 'member_name', 'username') as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined;
}

function ddAdminDashboardFindMemberRowForUserRow(PDO $pdo, array $userRow): ?array
{
    if (!ddAdminDashboardHasTable($pdo, 'members')) {
        return null;
    }

    $userId = (int) ($userRow['id'] ?? $userRow['user_id'] ?? 0);
    if ($userId > 0) {
        $userCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('user_id'));
        if ($userCol !== null) {
            $row = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
                . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userCol) . ' = :user_id LIMIT 1',
                array(':user_id' => $userId)
            );

            if ($row !== null) {
                return $row;
            }
        }
    }

    $email = strtolower(trim((string) ($userRow['email'] ?? $userRow['user_email'] ?? $userRow['member_email'] ?? '')));
    if ($email !== '') {
        $row = ddAdminDashboardFindMemberRowByEmail($pdo, $email);
        if ($row !== null) {
            return $row;
        }
    }

    $displayName = strtolower(trim(ddAdminDashboardRowDisplayName($userRow)));
    if ($displayName !== '') {
        $nameCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('full_name', 'name', 'client_name', 'member_name', 'username'));
        if ($nameCol !== null) {
            $row = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
                . ' WHERE LOWER(TRIM(COALESCE(' . ddAdminDashboardQuoteIdentifier($nameCol) . ", ''))) = :name LIMIT 1",
                array(':name' => $displayName)
            );

            if ($row !== null) {
                return $row;
            }
        }
    }

    return null;
}

function ddAdminDashboardAttachUserIdToMemberIfPossible(PDO $pdo, int $memberId, int $userId): void
{
    if ($memberId <= 0 || $userId <= 0 || !ddAdminDashboardHasTable($pdo, 'members')) {
        return;
    }

    $memberIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
    $userCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('user_id'));
    if ($memberIdCol === null || $userCol === null) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE ' . ddAdminDashboardQuoteIdentifier('members')
        . ' SET ' . ddAdminDashboardQuoteIdentifier($userCol) . ' = :user_id'
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($memberIdCol) . ' = :member_id'
        . ' AND COALESCE(' . ddAdminDashboardQuoteIdentifier($userCol) . ', 0) <= 0'
    );

    ddAdminDashboardSafeExecute($stmt, array(
        ':user_id' => $userId,
        ':member_id' => $memberId,
    ));
}

function ddAdminDashboardFindUserRowByMemberId(PDO $pdo, int $memberId): ?array
{
    if ($memberId <= 0 || !ddAdminDashboardHasTable($pdo, 'members') || !ddAdminDashboardHasTable($pdo, 'users')) {
        return null;
    }

    $memberIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
    if ($memberIdCol === null) {
        return null;
    }

    $memberRow = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($memberIdCol) . ' = :member_id LIMIT 1',
        array(':member_id' => $memberId)
    );

    if ($memberRow === null) {
        return null;
    }

    $userCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('user_id'));
    $userId = $userCol !== null ? (int) ($memberRow[$userCol] ?? 0) : 0;
    if ($userId > 0) {
        $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
        if ($userIdCol !== null) {
            $userRow = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
                . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userIdCol) . ' = :user_id LIMIT 1',
                array(':user_id' => $userId)
            );

            if ($userRow !== null) {
                return $userRow;
            }
        }
    }

    $email = trim((string) ($memberRow['email'] ?? $memberRow['member_email'] ?? $memberRow['user_email'] ?? ''));
    if ($email !== '') {
        return ddAdminDashboardFindUserRowByEmail($pdo, $email);
    }

    return null;
}

function ddAdminDashboardEnsureMembersTable(PDO $pdo): bool
{
    if (ddAdminDashboardHasTable($pdo, 'members')) {
        return true;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . ddAdminDashboardQuoteIdentifier('members') . ' ('
            . ddAdminDashboardQuoteIdentifier('id') . ' INTEGER PRIMARY KEY AUTOINCREMENT, '
            . ddAdminDashboardQuoteIdentifier('user_id') . ' INTEGER, '
            . ddAdminDashboardQuoteIdentifier('full_name') . ' TEXT, '
            . ddAdminDashboardQuoteIdentifier('email') . ' TEXT, '
            . ddAdminDashboardQuoteIdentifier('created_at') . ' TEXT, '
            . ddAdminDashboardQuoteIdentifier('updated_at') . ' TEXT'
            . ')'
        );
    } catch (Throwable $e) {
        return ddAdminDashboardHasTable($pdo, 'members');
    }

    return ddAdminDashboardHasTable($pdo, 'members');
}

function ddAdminDashboardGetTableInfo(PDO $pdo, string $table): array
{
    if (!ddAdminDashboardTableExists($pdo, $table)) {
        return array();
    }

    try {
        $sql = 'PRAGMA table_info(' . ddAdminDashboardQuoteIdentifier($table) . ')';
        $rows = $pdo->query($sql);
        if (!($rows instanceof PDOStatement)) {
            return array();
        }

        $info = $rows->fetchAll(PDO::FETCH_ASSOC);
        return is_array($info) ? $info : array();
    } catch (Throwable $e) {
        return array();
    }
}

function ddAdminDashboardEnsureMemberRowForUser(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    if (!ddAdminDashboardEnsureMembersTable($pdo) || !ddAdminDashboardHasTable($pdo, 'users')) {
        return 0;
    }

    $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
    if ($userIdCol === null) {
        return 0;
    }

    $userRow = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userIdCol) . ' = :user_id LIMIT 1',
        array(':user_id' => $userId)
    );

    if ($userRow === null) {
        return 0;
    }

    $existing = ddAdminDashboardFindMemberRowForUserRow($pdo, $userRow);
    if ($existing !== null) {
        $memberId = (int) ddAdminDashboardValueFromRow($existing, array('id', 'member_id'), 0);
        if ($memberId > 0) {
            ddAdminDashboardAttachUserIdToMemberIfPossible($pdo, $memberId, $userId);
            return $memberId;
        }
    }

    $memberColumns = ddAdminDashboardGetTableColumns($pdo, 'members');
    $memberInfo = ddAdminDashboardGetTableInfo($pdo, 'members');
    if (empty($memberColumns) || empty($memberInfo)) {
        return 0;
    }

    $now = date('Y-m-d H:i:s');
    $fullName = trim((string) ($userRow['full_name'] ?? $userRow['name'] ?? ''));
    if ($fullName === '' && isset($userRow['first_name'])) {
        $fullName = trim((string) $userRow['first_name'] . ' ' . (string) ($userRow['last_name'] ?? ''));
    }
    $email = trim((string) ($userRow['email'] ?? ''));
    $phone = trim((string) ($userRow['phone'] ?? $userRow['phone_number'] ?? ''));

    $data = array();

    foreach ($memberInfo as $columnInfo) {
        $columnName = isset($columnInfo['name']) ? (string) $columnInfo['name'] : '';
        if ($columnName === '') {
            continue;
        }

        $isPrimary = !empty($columnInfo['pk']);
        if ($isPrimary) {
            continue;
        }

        $lower = strtolower($columnName);
        $type = strtoupper((string) ($columnInfo['type'] ?? ''));
        $isNotNull = !empty($columnInfo['notnull']);
        $defaultValue = $columnInfo['dflt_value'] ?? null;

        if ($lower === 'user_id') {
            $data[$columnName] = $userId;
            continue;
        }

        if (in_array($lower, array('created_at', 'updated_at'), true)) {
            $data[$columnName] = $now;
            continue;
        }

        if (in_array($lower, array('full_name', 'name', 'client_name', 'member_name'), true) && $fullName !== '') {
            $data[$columnName] = $fullName;
            continue;
        }

        if ($lower === 'username' && $fullName !== '') {
            $data[$columnName] = $fullName;
            continue;
        }

        if (in_array($lower, array('email', 'member_email', 'user_email', 'client_email'), true) && $email !== '') {
            $data[$columnName] = $email;
            continue;
        }

        if (in_array($lower, array('phone', 'phone_number', 'mobile', 'mobile_number'), true) && $phone !== '') {
            $data[$columnName] = $phone;
            continue;
        }

        if ($lower === 'signup_method') {
            $data[$columnName] = 'email';
            continue;
        }

        if ($lower === 'password_hash') {
            $data[$columnName] = (string) ($userRow['password_hash'] ?? '');
            continue;
        }

        if (in_array($lower, array('email_verified', 'is_verified'), true)) {
            $data[$columnName] = (int) ($userRow[$columnName] ?? 1);
            continue;
        }

        if (!array_key_exists($columnName, $userRow) || $userRow[$columnName] === null || $userRow[$columnName] === '') {
            if ($isNotNull && $defaultValue === null) {
                if (strpos($type, 'INT') !== false || strpos($type, 'REAL') !== false || strpos($type, 'NUM') !== false) {
                    $data[$columnName] = 0;
                } else {
                    $data[$columnName] = '';
                }
            }
            continue;
        }

        $data[$columnName] = $userRow[$columnName];
    }

    if (empty($data)) {
        return 0;
    }

    $fields = array_keys($data);
    $quotedCols = array();
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $quotedCols[] = ddAdminDashboardQuoteIdentifier($field);
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $data[$field];
    }

    try {
        $sql = 'INSERT INTO ' . ddAdminDashboardQuoteIdentifier('members')
            . ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        if (!ddAdminDashboardSafeExecute($stmt, $params)) {
            $existing = ddAdminDashboardFindMemberRowForUserRow($pdo, $userRow);
            return $existing !== null ? (int) ddAdminDashboardValueFromRow($existing, array('id', 'member_id'), 0) : 0;
        }

        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            ddAdminDashboardAttachUserIdToMemberIfPossible($pdo, $newId, $userId);
            return $newId;
        }
    } catch (Throwable $e) {
        $existing = ddAdminDashboardFindMemberRowForUserRow($pdo, $userRow);
        return $existing !== null ? (int) ddAdminDashboardValueFromRow($existing, array('id', 'member_id'), 0) : 0;
    }

    $created = ddAdminDashboardFindMemberRowForUserRow($pdo, $userRow);
    return $created !== null ? (int) ddAdminDashboardValueFromRow($created, array('id', 'member_id'), 0) : 0;
}

function ddAdminDashboardResolveMemberIdentityFromUserRow(PDO $pdo, array $row): array
{
    $idCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
    $nameCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('full_name', 'name', 'client_name', 'first_name', 'username'));
    $lastNameCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('last_name'));
    $emailCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('email'));

    $userId = $idCol !== null ? (int) ($row[$idCol] ?? 0) : 0;
    $memberRow = ddAdminDashboardFindMemberRowForUserRow($pdo, $row);
    $memberId = $memberRow !== null ? (int) ddAdminDashboardValueFromRow($memberRow, array('id', 'member_id'), 0) : 0;

    return array(
        'member_key' => $memberId > 0 ? 'm:' . $memberId : ($userId > 0 ? 'u:' . $userId : ''),
        'user_id' => $userId,
        'member_id' => $memberId,
        'member_name' => ddAdminDashboardBuildPersonName($row, $nameCol, $lastNameCol, 'Member', $memberId > 0 ? $memberId : $userId),
        'email' => $emailCol !== null ? trim((string) ($row[$emailCol] ?? '')) : '',
        'display_id' => $memberId > 0 ? $memberId : $userId,
        'source_table' => 'users',
    );
}

function ddAdminDashboardResolveMemberIdentityFromMemberRow(PDO $pdo, array $row): array
{
    $idCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
    $userCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('user_id'));
    $nameCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('full_name', 'name', 'client_name', 'first_name', 'username'));
    $lastNameCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('last_name'));
    $emailCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('email', 'member_email'));

    $memberId = $idCol !== null ? (int) ($row[$idCol] ?? 0) : 0;
    $userId = $userCol !== null ? (int) ($row[$userCol] ?? 0) : 0;

    if ($userId <= 0) {
        $userRow = ddAdminDashboardFindUserRowByMemberId($pdo, $memberId);
        if ($userRow !== null) {
            $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
            if ($userIdCol !== null) {
                $userId = (int) ($userRow[$userIdCol] ?? 0);
            }
        }
    }

    if ($userId <= 0) {
        $email = $emailCol !== null ? trim((string) ($row[$emailCol] ?? '')) : '';
        if ($email !== '') {
            $userRow = ddAdminDashboardFindUserRowByEmail($pdo, $email);
            if ($userRow !== null) {
                $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
                if ($userIdCol !== null) {
                    $userId = (int) ($userRow[$userIdCol] ?? 0);
                }
            }
        }
    }

    return array(
        'member_key' => $memberId > 0 ? 'm:' . $memberId : ($userId > 0 ? 'u:' . $userId : ''),
        'user_id' => $userId,
        'member_id' => $memberId,
        'member_name' => ddAdminDashboardBuildPersonName($row, $nameCol, $lastNameCol, 'Member', $memberId > 0 ? $memberId : $userId),
        'email' => $emailCol !== null ? trim((string) ($row[$emailCol] ?? '')) : '',
        'display_id' => $memberId > 0 ? $memberId : $userId,
        'source_table' => 'members',
    );
}

function ddAdminDashboardResolveSelectedMember(PDO $pdo, string $memberKey): ?array
{
    $memberKey = trim($memberKey);
    if ($memberKey === '') {
        return null;
    }

    if (strpos($memberKey, ':') === false && ctype_digit($memberKey)) {
        $userRow = null;
        if (ddAdminDashboardHasTable($pdo, 'users')) {
            $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
            if ($userIdCol !== null) {
                $userRow = ddAdminDashboardSafeFetchOne(
                    $pdo,
                    'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
                    . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userIdCol) . ' = :id LIMIT 1',
                    array(':id' => (int) $memberKey)
                );
            }
        }

        if ($userRow !== null) {
            return ddAdminDashboardResolveMemberIdentityFromUserRow($pdo, $userRow);
        }

        if (ddAdminDashboardHasTable($pdo, 'members')) {
            $memberIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
            if ($memberIdCol !== null) {
                $memberRow = ddAdminDashboardSafeFetchOne(
                    $pdo,
                    'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
                    . ' WHERE ' . ddAdminDashboardQuoteIdentifier($memberIdCol) . ' = :id LIMIT 1',
                    array(':id' => (int) $memberKey)
                );
                if ($memberRow !== null) {
                    return ddAdminDashboardResolveMemberIdentityFromMemberRow($pdo, $memberRow);
                }
            }
        }

        return null;
    }

    list($prefix, $rawId) = array_pad(explode(':', $memberKey, 2), 2, '');
    $prefix = strtolower(trim($prefix));
    $id = (int) $rawId;

    if ($id <= 0) {
        return null;
    }

    if ($prefix === 'u' && ddAdminDashboardHasTable($pdo, 'users')) {
        $userIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
        if ($userIdCol !== null) {
            $userRow = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
                . ' WHERE ' . ddAdminDashboardQuoteIdentifier($userIdCol) . ' = :id LIMIT 1',
                array(':id' => $id)
            );
            if ($userRow !== null) {
                return ddAdminDashboardResolveMemberIdentityFromUserRow($pdo, $userRow);
            }
        }
    }

    if ($prefix === 'm' && ddAdminDashboardHasTable($pdo, 'members')) {
        $memberIdCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
        if ($memberIdCol !== null) {
            $memberRow = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
                . ' WHERE ' . ddAdminDashboardQuoteIdentifier($memberIdCol) . ' = :id LIMIT 1',
                array(':id' => $id)
            );
            if ($memberRow !== null) {
                return ddAdminDashboardResolveMemberIdentityFromMemberRow($pdo, $memberRow);
            }
        }
    }

    return null;
}

function ddAdminDashboardMembershipOwnerIdForIdentity(PDO $pdo, array $identity): int
{
    $ownerColumn = ddAdminDashboardMembershipOwnerColumn($pdo);

    if ($ownerColumn === 'member_id') {
        return (int) ($identity['member_id'] ?? 0);
    }

    if ($ownerColumn === 'user_id') {
        return (int) ($identity['user_id'] ?? 0);
    }

    return 0;
}

function ddAdminDashboardFetchMemberOptions(PDO $pdo): array
{
    $options = array();
    $seenUserIds = array();
    $seenMemberIds = array();

    if (ddAdminDashboardHasTable($pdo, 'users')) {
        $idCol = ddAdminDashboardFirstExistingColumn($pdo, 'users', array('id', 'user_id'));
        if ($idCol !== null) {
            $rows = ddAdminDashboardSafeFetchAll(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('users')
                . ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC'
            );

            foreach ($rows as $row) {
                if (!ddAdminDashboardIsMemberLikeRow($row)) {
                    continue;
                }

                $identity = ddAdminDashboardResolveMemberIdentityFromUserRow($pdo, $row);
                if ((int) $identity['user_id'] <= 0 && (int) $identity['member_id'] <= 0) {
                    continue;
                }

                $options[] = $identity;
                if ((int) $identity['user_id'] > 0) {
                    $seenUserIds[(int) $identity['user_id']] = true;
                }
                if ((int) $identity['member_id'] > 0) {
                    $seenMemberIds[(int) $identity['member_id']] = true;
                }
            }
        }
    }

    if (ddAdminDashboardHasTable($pdo, 'members')) {
        $idCol = ddAdminDashboardFirstExistingColumn($pdo, 'members', array('id', 'member_id'));
        if ($idCol !== null) {
            $rows = ddAdminDashboardSafeFetchAll(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier('members')
                . ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC'
            );

            foreach ($rows as $row) {
                $identity = ddAdminDashboardResolveMemberIdentityFromMemberRow($pdo, $row);

                if ((int) $identity['member_id'] > 0 && isset($seenMemberIds[(int) $identity['member_id']])) {
                    continue;
                }
                if ((int) $identity['user_id'] > 0 && isset($seenUserIds[(int) $identity['user_id']])) {
                    continue;
                }

                if ((int) $identity['user_id'] <= 0 && (int) $identity['member_id'] <= 0) {
                    continue;
                }

                $options[] = $identity;
                if ((int) $identity['user_id'] > 0) {
                    $seenUserIds[(int) $identity['user_id']] = true;
                }
                if ((int) $identity['member_id'] > 0) {
                    $seenMemberIds[(int) $identity['member_id']] = true;
                }
            }
        }
    }

    usort($options, function (array $a, array $b): int {
        return strcasecmp((string) ($a['member_name'] ?? ''), (string) ($b['member_name'] ?? ''));
    });

    return $options;
}

function ddAdminDashboardEnsureMembershipPlansTable(PDO $pdo): bool
{
    if (ddAdminDashboardHasTable($pdo, 'membership_plans')) {
        return true;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS membership_plans ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'slug TEXT, '
            . 'name TEXT, '
            . 'created_at TEXT'
            . ')'
        );
    } catch (Throwable $e) {
        return ddAdminDashboardHasTable($pdo, 'membership_plans');
    }

    return ddAdminDashboardHasTable($pdo, 'membership_plans');
}

function ddAdminDashboardFindPlanRow(PDO $pdo, string $planSlug): ?array
{
    if (!ddAdminDashboardEnsureMembershipPlansTable($pdo)) {
        return null;
    }

    $table = 'membership_plans';
    $slugCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('slug', 'plan_slug', 'code'));
    $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('name', 'plan_name', 'title'));

    if ($slugCol !== null) {
        $row = ddAdminDashboardSafeFetchOne(
            $pdo,
            'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
            . ' WHERE LOWER(TRIM(COALESCE(' . ddAdminDashboardQuoteIdentifier($slugCol) . ", ''))) = :slug LIMIT 1",
            array(':slug' => strtolower(trim($planSlug)))
        );
        if ($row !== null) {
            return $row;
        }
    }

    $catalog = ddAdminDashboardPlanCatalog();
    if ($nameCol !== null && isset($catalog[$planSlug])) {
        $row = ddAdminDashboardSafeFetchOne(
            $pdo,
            'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
            . ' WHERE LOWER(TRIM(COALESCE(' . ddAdminDashboardQuoteIdentifier($nameCol) . ", ''))) = :name LIMIT 1",
            array(':name' => strtolower(trim((string) $catalog[$planSlug]['name'])))
        );
        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

function ddAdminDashboardInsertPlanRow(PDO $pdo, string $planSlug, array $config): bool
{
    if (!ddAdminDashboardEnsureMembershipPlansTable($pdo)) {
        return false;
    }

    $table = 'membership_plans';
    $columns = ddAdminDashboardGetTableColumns($pdo, $table);
    if (empty($columns)) {
        return false;
    }

    $insertCols = array();
    $params = array();

    if (in_array('slug', $columns, true)) {
        $insertCols[] = 'slug';
        $params[':slug'] = $planSlug;
    } elseif (in_array('plan_slug', $columns, true)) {
        $insertCols[] = 'plan_slug';
        $params[':plan_slug'] = $planSlug;
    } elseif (in_array('code', $columns, true)) {
        $insertCols[] = 'code';
        $params[':code'] = $planSlug;
    }

    if (in_array('name', $columns, true)) {
        $insertCols[] = 'name';
        $params[':name'] = (string) ($config['name'] ?? '');
    } elseif (in_array('plan_name', $columns, true)) {
        $insertCols[] = 'plan_name';
        $params[':plan_name'] = (string) ($config['name'] ?? '');
    } elseif (in_array('title', $columns, true)) {
        $insertCols[] = 'title';
        $params[':title'] = (string) ($config['name'] ?? '');
    }

    if (in_array('created_at', $columns, true)) {
        $insertCols[] = 'created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insertCols)) {
        return false;
    }

    try {
        $quotedCols = array();
        $placeholders = array();
        foreach ($insertCols as $column) {
            $quotedCols[] = ddAdminDashboardQuoteIdentifier($column);
            $placeholders[] = ':' . $column;
        }

        $sql = 'INSERT INTO ' . ddAdminDashboardQuoteIdentifier($table)
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $pdo->prepare($sql);
        return ddAdminDashboardSafeExecute($stmt, $params);
    } catch (Throwable $e) {
        return false;
    }
}

function ddAdminDashboardEnsureFounderPlans(PDO $pdo): bool
{
    if (!ddAdminDashboardEnsureMembershipPlansTable($pdo)) {
        return false;
    }

    $catalog = ddAdminDashboardPlanCatalog();
    foreach ($catalog as $slug => $config) {
        if (ddAdminDashboardFindPlanRow($pdo, $slug) !== null) {
            continue;
        }

        ddAdminDashboardInsertPlanRow($pdo, $slug, $config);
    }

    foreach ($catalog as $slug => $config) {
        if (ddAdminDashboardFindPlanRow($pdo, $slug) === null) {
            return false;
        }
    }

    return true;
}

function ddAdminDashboardCreateMembershipRow(PDO $pdo, int $membershipOwnerId, int $planId, array $identity = array()): int
{
    $table = 'member_memberships';
    if (!ddAdminDashboardHasTable($pdo, $table)) {
        return 0;
    }

    $columns = ddAdminDashboardGetTableColumns($pdo, $table);
    $ownerCol = ddAdminDashboardMembershipOwnerColumn($pdo);
    $planCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('plan_id'));

    if ($ownerCol === null || $planCol === null) {
        return 0;
    }

    $insert = array($ownerCol, $planCol);
    $params = array(
        ':' . $ownerCol => $membershipOwnerId,
        ':' . $planCol => $planId,
    );

    if ($ownerCol !== 'user_id' && in_array('user_id', $columns, true) && !isset($params[':user_id']) && (int) ($identity['user_id'] ?? 0) > 0) {
        $insert[] = 'user_id';
        $params[':user_id'] = (int) $identity['user_id'];
    }

    if ($ownerCol !== 'member_id' && in_array('member_id', $columns, true) && !isset($params[':member_id']) && (int) ($identity['member_id'] ?? 0) > 0) {
        $insert[] = 'member_id';
        $params[':member_id'] = (int) $identity['member_id'];
    }

    if (in_array('renewal_count', $columns, true)) {
        $insert[] = 'renewal_count';
        $params[':renewal_count'] = 0;
    }

    if (in_array('status', $columns, true)) {
        $insert[] = 'status';
        $params[':status'] = 'active';
    }

    if (in_array('created_at', $columns, true)) {
        $insert[] = 'created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    if (in_array('updated_at', $columns, true)) {
        $insert[] = 'updated_at';
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $quotedCols = array();
    $placeholders = array();
    foreach (array_values(array_unique($insert)) as $column) {
        $quotedCols[] = ddAdminDashboardQuoteIdentifier($column);
        $placeholders[] = ':' . $column;
    }

    $sql = 'INSERT INTO ' . ddAdminDashboardQuoteIdentifier($table)
        . ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $pdo->prepare($sql);
    if (!ddAdminDashboardSafeExecute($stmt, $params)) {
        return 0;
    }

    return (int) $pdo->lastInsertId();
}

function ddAdminDashboardGetCurrentMembershipForMember(PDO $pdo, int $membershipOwnerId): ?array
{
    $table = 'member_memberships';
    if (!ddAdminDashboardHasTable($pdo, $table)) {
        return null;
    }

    $ownerCol = ddAdminDashboardMembershipOwnerColumn($pdo);
    if ($ownerCol === null) {
        return null;
    }

    $orderCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('created_at', 'updated_at', 'id'));
    if ($orderCol === null) {
        return null;
    }

    $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
    $sql = 'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($ownerCol) . ' = :owner_id'
        . ' ORDER BY ' . ddAdminDashboardQuoteIdentifier($orderCol) . ' DESC';

    if ($idCol !== null && $idCol !== $orderCol) {
        $sql .= ', ' . ddAdminDashboardQuoteIdentifier($idCol) . ' DESC';
    }

    $sql .= ' LIMIT 1';

    return ddAdminDashboardSafeFetchOne($pdo, $sql, array(':owner_id' => $membershipOwnerId));
}

function ddAdminDashboardPlanSlugFromMembership(PDO $pdo, ?array $membershipRow): string
{
    if ($membershipRow === null) {
        return '';
    }

    $table = 'membership_plans';
    $planId = (int) ddAdminDashboardValueFromRow($membershipRow, array('plan_id'), 0);
    if ($planId <= 0 || !ddAdminDashboardHasTable($pdo, $table)) {
        return '';
    }

    $planIdCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id', 'plan_id'));
    $slugCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('slug', 'plan_slug', 'code'));
    $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('name', 'plan_name', 'title'));

    if ($planIdCol === null) {
        return '';
    }

    $row = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($planIdCol) . ' = :plan_id LIMIT 1',
        array(':plan_id' => $planId)
    );

    if ($row === null) {
        return '';
    }

    if ($slugCol !== null && !empty($row[$slugCol])) {
        return strtolower(trim((string) $row[$slugCol]));
    }

    $planName = $nameCol !== null ? strtolower(trim((string) ($row[$nameCol] ?? ''))) : '';
    foreach (ddAdminDashboardPlanCatalog() as $slug => $config) {
        if (strtolower((string) $config['name']) === $planName) {
            return $slug;
        }
    }

    return '';
}

function ddAdminDashboardGetMembershipDisplaySummary(PDO $pdo, ?array $membershipRow): array
{
    $summary = array(
        'membership_id' => 0,
        'plan_name' => 'No Membership',
        'renewal_count' => 0,
    );

    if ($membershipRow === null) {
        return $summary;
    }

    $summary['membership_id'] = (int) ddAdminDashboardValueFromRow($membershipRow, array('id'), 0);
    $summary['renewal_count'] = (int) ddAdminDashboardValueFromRow($membershipRow, array('renewal_count'), 0);

    $planSlug = ddAdminDashboardPlanSlugFromMembership($pdo, $membershipRow);
    $catalog = ddAdminDashboardPlanCatalog();
    if ($planSlug !== '' && isset($catalog[$planSlug])) {
        $summary['plan_name'] = (string) $catalog[$planSlug]['name'];
        return $summary;
    }

    $table = 'membership_plans';
    $planId = (int) ddAdminDashboardValueFromRow($membershipRow, array('plan_id'), 0);
    if ($planId > 0 && ddAdminDashboardHasTable($pdo, $table)) {
        $planIdCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id', 'plan_id'));
        $nameCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('name', 'plan_name', 'title'));

        if ($planIdCol !== null && $nameCol !== null) {
            $planRow = ddAdminDashboardSafeFetchOne(
                $pdo,
                'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
                . ' WHERE ' . ddAdminDashboardQuoteIdentifier($planIdCol) . ' = :plan_id LIMIT 1',
                array(':plan_id' => $planId)
            );

            if ($planRow !== null && !empty($planRow[$nameCol])) {
                $summary['plan_name'] = (string) $planRow[$nameCol];
            }
        }
    }

    return $summary;
}

function ddAdminDashboardGetMembershipEntitlements(PDO $pdo, int $membershipId): array
{
    $default = array(
        'walk' => 0,
        'daycare' => 0,
        'drop-in' => 0,
        'boarding_night' => 0,
        'service_credit' => 0,
    );

    $table = 'membership_entitlements';
    if ($membershipId <= 0 || !ddAdminDashboardHasTable($pdo, $table)) {
        return $default;
    }

    $membershipCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('membership_id'));
    $typeCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('entitlement_type'));
    $totalCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('total'));
    $usedCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('used'));

    if ($membershipCol === null || $typeCol === null || $totalCol === null || $usedCol === null) {
        return $default;
    }

    $rows = ddAdminDashboardSafeFetchAll(
        $pdo,
        'SELECT '
        . ddAdminDashboardQuoteIdentifier($typeCol) . ' AS entitlement_type, '
        . ddAdminDashboardQuoteIdentifier($totalCol) . ' AS total, '
        . ddAdminDashboardQuoteIdentifier($usedCol) . ' AS used'
        . ' FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($membershipCol) . ' = :membership_id',
        array(':membership_id' => $membershipId)
    );

    foreach ($rows as $row) {
        $type = trim((string) ($row['entitlement_type'] ?? ''));
        if ($type === '') {
            continue;
        }

        $total = (int) ($row['total'] ?? 0);
        $used = (int) ($row['used'] ?? 0);
        $available = max(0, $total - $used);

        if (!array_key_exists($type, $default)) {
            $default[$type] = 0;
        }

        $default[$type] = $available;
    }

    return $default;
}

function ddAdminDashboardLogMembershipTransaction(PDO $pdo, int $membershipId, string $serviceType, string $transactionType, int $amount, string $note = '', string $externalId = ''): void
{
    $table = 'membership_transactions';
    if (!ddAdminDashboardHasTable($pdo, $table)) {
        return;
    }

    $columns = ddAdminDashboardGetTableColumns($pdo, $table);
    $insert = array();
    $params = array();

    if (in_array('membership_id', $columns, true)) {
        $insert[] = 'membership_id';
        $params[':membership_id'] = $membershipId;
    }

    if (in_array('transaction_type', $columns, true)) {
        $insert[] = 'transaction_type';
        $params[':transaction_type'] = $transactionType;
    }

    if (in_array('amount', $columns, true)) {
        $insert[] = 'amount';
        $params[':amount'] = $amount;
    }

    if (in_array('note', $columns, true)) {
        $insert[] = 'note';
        $params[':note'] = $serviceType . ($note !== '' ? ' | ' . $note : '');
    }

    if (in_array('external_source', $columns, true)) {
        $insert[] = 'external_source';
        $params[':external_source'] = 'admin_dashboard';
    }

    if (in_array('external_id', $columns, true)) {
        $insert[] = 'external_id';
        $params[':external_id'] = $externalId !== '' ? $externalId : uniqid('admin_', true);
    }

    if (in_array('created_at', $columns, true)) {
        $insert[] = 'created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insert)) {
        return;
    }

    try {
        $quotedCols = array();
        $placeholders = array();
        foreach ($insert as $column) {
            $quotedCols[] = ddAdminDashboardQuoteIdentifier($column);
            $placeholders[] = ':' . $column;
        }

        $sql = 'INSERT INTO ' . ddAdminDashboardQuoteIdentifier($table)
            . ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $pdo->prepare($sql);
        ddAdminDashboardSafeExecute($stmt, $params);
    } catch (Throwable $e) {
    }
}

function ddAdminDashboardUpsertEntitlementUnits(PDO $pdo, int $membershipId, string $serviceType, int $amount): array
{
    $table = 'membership_entitlements';
    if (!ddAdminDashboardHasTable($pdo, $table)) {
        return array('ok' => false, 'message' => 'membership_entitlements table was not found.');
    }

    if ($membershipId <= 0) {
        return array('ok' => false, 'message' => 'Invalid membership.');
    }

    $membershipCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('membership_id'));
    $typeCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('entitlement_type'));
    $totalCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('total'));
    $usedCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('used'));
    $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));

    if ($membershipCol === null || $typeCol === null || $totalCol === null || $usedCol === null) {
        return array('ok' => false, 'message' => 'membership_entitlements schema is missing required columns.');
    }

    $existing = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($membershipCol) . ' = :membership_id'
        . ' AND ' . ddAdminDashboardQuoteIdentifier($typeCol) . ' = :entitlement_type LIMIT 1',
        array(
            ':membership_id' => $membershipId,
            ':entitlement_type' => $serviceType,
        )
    );

    if ($existing === null) {
        $startingTotal = max(0, $amount);
        $columns = ddAdminDashboardGetTableColumns($pdo, $table);
        $insert = array();
        $params = array();

        foreach (array('membership_id', 'entitlement_type', 'total', 'used', 'created_at') as $column) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $insert[] = $column;
            if ($column === 'membership_id') {
                $params[':membership_id'] = $membershipId;
            } elseif ($column === 'entitlement_type') {
                $params[':entitlement_type'] = $serviceType;
            } elseif ($column === 'total') {
                $params[':total'] = $startingTotal;
            } elseif ($column === 'used') {
                $params[':used'] = 0;
            } elseif ($column === 'created_at') {
                $params[':created_at'] = date('Y-m-d H:i:s');
            }
        }

        if ($startingTotal <= 0) {
            return array('ok' => true, 'message' => 'No new credits were applied.');
        }

        $quotedCols = array();
        $placeholders = array();
        foreach ($insert as $column) {
            $quotedCols[] = ddAdminDashboardQuoteIdentifier($column);
            $placeholders[] = ':' . $column;
        }

        $sql = 'INSERT INTO ' . ddAdminDashboardQuoteIdentifier($table)
            . ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $pdo->prepare($sql);
        if (!ddAdminDashboardSafeExecute($stmt, $params)) {
            return array('ok' => false, 'message' => 'Could not create entitlement row.');
        }

        return array('ok' => true, 'message' => 'Entitlement created.');
    }

    if ($idCol === null) {
        return array('ok' => false, 'message' => 'membership_entitlements is missing an id column.');
    }

    $currentTotal = (int) ddAdminDashboardValueFromRow($existing, array($totalCol, 'total'), 0);
    $currentUsed = (int) ddAdminDashboardValueFromRow($existing, array($usedCol, 'used'), 0);
    $newTotal = $currentTotal + $amount;

    if ($newTotal < $currentUsed) {
        $newTotal = $currentUsed;
    }

    if ($newTotal < 0) {
        $newTotal = 0;
    }

    $sql = 'UPDATE ' . ddAdminDashboardQuoteIdentifier($table)
        . ' SET ' . ddAdminDashboardQuoteIdentifier($totalCol) . ' = :total'
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($idCol) . ' = :id';

    $stmt = $pdo->prepare($sql);
    if (!ddAdminDashboardSafeExecute($stmt, array(
        ':total' => $newTotal,
        ':id' => (int) ddAdminDashboardValueFromRow($existing, array($idCol, 'id'), 0),
    ))) {
        return array('ok' => false, 'message' => 'Could not update entitlement row.');
    }

    return array('ok' => true, 'message' => 'Entitlement updated.');
}

function ddAdminDashboardSeedMembershipEntitlements(PDO $pdo, int $membershipId, array $planConfig, string $reason): void
{
    if (!isset($planConfig['entitlements']) || !is_array($planConfig['entitlements'])) {
        return;
    }

    foreach ($planConfig['entitlements'] as $serviceType => $units) {
        if ((int) $units <= 0) {
            continue;
        }

        $result = ddAdminDashboardUpsertEntitlementUnits($pdo, $membershipId, (string) $serviceType, (int) $units);
        if ($result['ok']) {
            ddAdminDashboardLogMembershipTransaction($pdo, $membershipId, (string) $serviceType, 'credit', (int) $units, $reason, (string) ($planConfig['name'] ?? ''));
        }
    }
}

function ddAdminDashboardAssignMembershipAdmin(PDO $pdo, string $memberKey, string $planSlug): array
{
    $catalog = ddAdminDashboardPlanCatalog();
    if (!isset($catalog[$planSlug])) {
        return array('ok' => false, 'message' => 'Unknown membership plan selected.');
    }

    if (!ddAdminDashboardEnsureFounderPlans($pdo)) {
        return array('ok' => false, 'message' => 'Could not prepare founder membership plans.');
    }

    $identity = ddAdminDashboardResolveSelectedMember($pdo, $memberKey);
    if ($identity === null) {
        return array('ok' => false, 'message' => 'Selected member could not be resolved.');
    }

    $ownerColumn = ddAdminDashboardMembershipOwnerColumn($pdo);
    if ($ownerColumn === null) {
        return array('ok' => false, 'message' => 'member_memberships table is not available.');
    }

    if ($ownerColumn === 'member_id' && (int) ($identity['member_id'] ?? 0) <= 0 && (int) ($identity['user_id'] ?? 0) > 0) {
        $identity['member_id'] = ddAdminDashboardEnsureMemberRowForUser($pdo, (int) $identity['user_id']);
    }

    $membershipOwnerId = ddAdminDashboardMembershipOwnerIdForIdentity($pdo, $identity);
    if ($membershipOwnerId <= 0) {
        return array('ok' => false, 'message' => 'Selected member account could not be linked to the membership ledger.');
    }

    $planRow = ddAdminDashboardFindPlanRow($pdo, $planSlug);
    if ($planRow === null) {
        return array('ok' => false, 'message' => 'Selected plan was not found in membership_plans.');
    }

    $planId = (int) ddAdminDashboardValueFromRow($planRow, array('id', 'plan_id'), 0);
    if ($planId <= 0) {
        return array('ok' => false, 'message' => 'Selected plan ID is invalid.');
    }

    $pdo->beginTransaction();
    try {
        $membershipId = ddAdminDashboardCreateMembershipRow($pdo, $membershipOwnerId, $planId, $identity);
        if ($membershipId <= 0) {
            throw new RuntimeException('Could not create membership row.');
        }

        ddAdminDashboardSeedMembershipEntitlements($pdo, $membershipId, $catalog[$planSlug], 'admin_initial_assignment');
        $pdo->commit();
        return array('ok' => true, 'message' => (string) $catalog[$planSlug]['name'] . ' assigned successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array('ok' => false, 'message' => $e->getMessage());
    }
}

function ddAdminDashboardRenewMembershipAdmin(PDO $pdo, int $membershipId): array
{
    $table = 'member_memberships';
    $idCol = ddAdminDashboardFirstExistingColumn($pdo, $table, array('id'));
    if ($idCol === null || !ddAdminDashboardHasTable($pdo, $table)) {
        return array('ok' => false, 'message' => 'Membership table is not available.');
    }

    $membershipRow = ddAdminDashboardSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDashboardQuoteIdentifier($table)
        . ' WHERE ' . ddAdminDashboardQuoteIdentifier($idCol) . ' = :id LIMIT 1',
        array(':id' => $membershipId)
    );

    if ($membershipRow === null) {
        return array('ok' => false, 'message' => 'Membership not found.');
    }

    $planSlug = ddAdminDashboardPlanSlugFromMembership($pdo, $membershipRow);
    $catalog = ddAdminDashboardPlanCatalog();
    if ($planSlug === '' || !isset($catalog[$planSlug])) {
        return array('ok' => false, 'message' => 'Could not match membership to a founder plan.');
    }

    $renewalCount = (int) ddAdminDashboardValueFromRow($membershipRow, array('renewal_count'), 0);
    $nextRenewalCount = $renewalCount + 1;

    $pdo->beginTransaction();
    try {
        $columns = ddAdminDashboardGetTableColumns($pdo, $table);
        if (in_array('renewal_count', $columns, true)) {
            $sql = 'UPDATE ' . ddAdminDashboardQuoteIdentifier($table)
                . ' SET ' . ddAdminDashboardQuoteIdentifier('renewal_count') . ' = :renewal_count';

            $params = array(
                ':renewal_count' => $nextRenewalCount,
                ':id' => $membershipId,
            );

            if (in_array('updated_at', $columns, true)) {
                $sql .= ', ' . ddAdminDashboardQuoteIdentifier('updated_at') . ' = :updated_at';
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }

            $sql .= ' WHERE ' . ddAdminDashboardQuoteIdentifier($idCol) . ' = :id';

            $stmt = $pdo->prepare($sql);
            if (!ddAdminDashboardSafeExecute($stmt, $params)) {
                throw new RuntimeException('Failed to update renewal count.');
            }
        }

        ddAdminDashboardSeedMembershipEntitlements($pdo, $membershipId, $catalog[$planSlug], 'admin_renewal_allocation');

        if ($nextRenewalCount % 3 === 0 && (int) $catalog[$planSlug]['quarterly_service_credit'] > 0) {
            $bonus = (int) $catalog[$planSlug]['quarterly_service_credit'];
            $result = ddAdminDashboardUpsertEntitlementUnits($pdo, $membershipId, 'service_credit', $bonus);
            if (!$result['ok']) {
                throw new RuntimeException((string) $result['message']);
            }

            ddAdminDashboardLogMembershipTransaction(
                $pdo,
                $membershipId,
                'service_credit',
                'credit',
                $bonus,
                'admin_quarterly_bonus',
                (string) $catalog[$planSlug]['name']
            );
        }

        $pdo->commit();
        return array('ok' => true, 'message' => 'Membership renewed successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array('ok' => false, 'message' => $e->getMessage());
    }
}

function ddAdminDashboardAdjustMembershipCreditAdmin(PDO $pdo, int $membershipId, string $serviceType, int $amount, string $reason): array
{
    if ($membershipId <= 0) {
        return array('ok' => false, 'message' => 'Membership is required.');
    }

    if (!in_array($serviceType, array('walk', 'daycare', 'drop-in', 'boarding_night', 'service_credit'), true)) {
        return array('ok' => false, 'message' => 'Invalid service type.');
    }

    if ($amount === 0) {
        return array('ok' => false, 'message' => 'Adjustment amount cannot be zero.');
    }

    $pdo->beginTransaction();
    try {
        $result = ddAdminDashboardUpsertEntitlementUnits($pdo, $membershipId, $serviceType, $amount);
        if (!$result['ok']) {
            throw new RuntimeException((string) $result['message']);
        }

        ddAdminDashboardLogMembershipTransaction(
            $pdo,
            $membershipId,
            $serviceType,
            $amount > 0 ? 'credit' : 'debit',
            abs($amount),
            'admin_adjustment',
            $reason
        );

        $pdo->commit();
        return array('ok' => true, 'message' => 'Credits updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array('ok' => false, 'message' => $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = array('ok' => false, 'message' => 'No action was processed.');

    if (!ddAdminDashboardValidateCsrfToken($_POST['csrf_token'] ?? '')) {
        $result['message'] = 'Security check failed. Please refresh the page and try again.';
    } elseif (isset($_POST['assign_membership'])) {
        $memberKey = trim((string) ($_POST['member_key'] ?? $_POST['member_id'] ?? ''));
        $planSlug = trim((string) ($_POST['plan_slug'] ?? ''));
        $result = ddAdminDashboardAssignMembershipAdmin($pdo, $memberKey, $planSlug);
    } elseif (isset($_POST['renew_membership'])) {
        $membershipId = (int) ($_POST['renew_membership_id'] ?? 0);
        $result = ddAdminDashboardRenewMembershipAdmin($pdo, $membershipId);
    } elseif (isset($_POST['adjust_credits'])) {
        $membershipId = (int) ($_POST['adjust_membership_id'] ?? 0);
        $serviceType = trim((string) ($_POST['service_type'] ?? ''));
        $amount = (int) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Admin adjustment'));
        $result = ddAdminDashboardAdjustMembershipCreditAdmin($pdo, $membershipId, $serviceType, $amount, $reason);
    }

    $_SESSION['admin_dashboard_flash'] = (string) $result['message'];
    ddAdminDashboardRedirect('admin-dashboard.php');
}

$flash = isset($_SESSION['admin_dashboard_flash']) ? (string) $_SESSION['admin_dashboard_flash'] : '';
unset($_SESSION['admin_dashboard_flash']);

$memberBookings = ddAdminDashboardCountTable($pdo, 'bookings');
if ($memberBookings === 0) {
    $memberBookings = ddAdminDashboardCountTable($pdo, 'walks');
}
$publicBookings = ddAdminDashboardCountTable($pdo, 'non_member_bookings');
$groupWalkApps = ddAdminDashboardCountTable($pdo, 'group_walk_applications');
$unreadNotifications = ddAdminDashboardCountUnreadNotifications($pdo);
$totalBookings = $memberBookings + $publicBookings;

$recentMemberBookings = ddAdminDashboardFetchRecentMemberBookings($pdo, 5);
$recentPublicBookings = ddAdminDashboardFetchRecentPublicBookings($pdo, 5);
$recentGroupWalkApps = ddAdminDashboardFetchRecentGroupWalkApplications($pdo, 5);

$newPublicCount = 0;
foreach ($recentPublicBookings as $row) {
    if (strtolower(trim((string) ($row['status'] ?? ''))) === 'new') {
        $newPublicCount++;
    }
}

$memberOptions = ddAdminDashboardFetchMemberOptions($pdo);
$memberCount = count($memberOptions);
$founderPlans = ddAdminDashboardPlanCatalog();
$csrfToken = ddAdminDashboardCsrfToken();

$memberMembershipSnapshots = array();
foreach ($memberOptions as $memberOption) {
    $membershipOwnerId = ddAdminDashboardMembershipOwnerIdForIdentity($pdo, $memberOption);
    $membershipRow = $membershipOwnerId > 0
        ? ddAdminDashboardGetCurrentMembershipForMember($pdo, $membershipOwnerId)
        : null;
    $summary = ddAdminDashboardGetMembershipDisplaySummary($pdo, $membershipRow);
    $entitlements = ddAdminDashboardGetMembershipEntitlements($pdo, (int) $summary['membership_id']);

    $memberMembershipSnapshots[] = array(
        'member_key' => (string) ($memberOption['member_key'] ?? ''),
        'member_id' => (int) ($memberOption['display_id'] ?? 0),
        'membership_owner_id' => $membershipOwnerId,
        'member_name' => (string) $memberOption['member_name'],
        'email' => (string) $memberOption['email'],
        'membership' => $summary,
        'entitlements' => $entitlements,
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Doggie Dorian’s</title>
    <meta name="description" content="Doggie Dorian’s admin control center.">
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
            max-width: 1400px;
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

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.30);
            color: #f3e5c2;
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
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

        h3 {
            margin: 0 0 8px;
            font-size: 1rem;
        }

        .sub {
            color: rgba(255,255,255,0.74);
            line-height: 1.65;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 800;
            border: none;
            cursor: pointer;
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        .sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 24px;
        }

        .wide-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 24px;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .item {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 8px;
        }

        .item-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .item-meta {
            color: rgba(255,255,255,0.68);
            font-size: .92rem;
            line-height: 1.55;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 900;
            letter-spacing: .02em;
            text-transform: capitalize;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }

        .muted {
            color: rgba(255,255,255,0.62);
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.62);
        }

        .control-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }

        .form-grid {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .form-grid.two {
            grid-template-columns: 1fr 1fr;
        }

        .form-grid.three {
            grid-template-columns: 1fr 1fr 1fr;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.05);
            color: #fff;
            font: inherit;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .helper {
            color: rgba(255,255,255,0.62);
            font-size: .88rem;
            line-height: 1.5;
        }

        .member-snapshot-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .snapshot-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .credit-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .credit-pill {
            padding: 8px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .84rem;
            font-weight: 700;
        }

        @media (max-width: 1180px) {
            .grid {
                grid-template-columns: 1fr 1fr;
            }

            .hero,
            .sections,
            .wide-section,
            .member-snapshot-grid,
            .form-grid.two,
            .form-grid.three {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .grid {
                grid-template-columns: 1fr;
            }

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
                <a href="admin-nav.php">Admin Nav</a>
                <a href="admin-revenue.php">Revenue</a>
                <a href="admin-bookings.php">Bookings</a>
                <a href="admin-members.php">Members</a>
                <a href="admin-group-walk-applications.php">Group Walks</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo ddAdminDashboardEscape($flash); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-card">
                <div class="eyebrow">Control Center</div>
                <h1>Admin Dashboard</h1>
                <div class="sub">
                    Monitor bookings, member growth, public requests, group walk applications, and founder membership operations from one premium control panel.
                </div>

                <div class="btn-row">
                    <a href="admin-bookings.php" class="btn btn-gold">Manage Bookings</a>
                    <a href="admin-members.php" class="btn btn-light">View Members</a>
                    <a href="admin-group-walk-applications.php" class="btn btn-light">Review Applications</a>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Live Snapshot</div>
                <h2>System status</h2>
                <div class="sub">
                    Quick view of the most important activity on the platform right now.
                </div>

                <div class="btn-row">
                    <div class="btn btn-light">Unread Notifications: <?php echo (int) $unreadNotifications; ?></div>
                    <div class="btn btn-light">New Public Bookings: <?php echo (int) $newPublicCount; ?></div>
                </div>
            </div>
        </section>

        <div class="grid">
            <div class="stat-card">
                <div class="label">Total Bookings</div>
                <div class="big"><?php echo (int) $totalBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Member Bookings</div>
                <div class="big"><?php echo (int) $memberBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Public Bookings</div>
                <div class="big"><?php echo (int) $publicBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Group Walk Apps</div>
                <div class="big"><?php echo (int) $groupWalkApps; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Members</div>
                <div class="big"><?php echo (int) $memberCount; ?></div>
            </div>
        </div>

        <section class="wide-section">
            <div class="card">
                <div class="eyebrow">Membership Control</div>
                <h2>Assign Founder Membership</h2>
                <div class="sub">
                    Manually assign a founder membership to a member account and seed the correct starting credits immediately.
                </div>

                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminDashboardEscape($csrfToken); ?>">

                    <select name="member_key" required>
                        <option value="">Select member</option>
                        <?php foreach ($memberOptions as $member): ?>
                            <option value="<?php echo ddAdminDashboardEscape((string) ($member['member_key'] ?? '')); ?>">
                                <?php echo ddAdminDashboardEscape($member['member_name']); ?><?php echo $member['email'] !== '' ? ' · ' . ddAdminDashboardEscape($member['email']) : ''; ?> · ID <?php echo (int) ($member['display_id'] ?? 0); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="plan_slug" required>
                        <option value="">Select founder plan</option>
                        <?php foreach ($founderPlans as $slug => $config): ?>
                            <option value="<?php echo ddAdminDashboardEscape($slug); ?>"><?php echo ddAdminDashboardEscape($config['name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="assign_membership" class="btn btn-gold">Assign Membership</button>
                </form>

                <div class="helper">
                    This creates a new membership row and seeds the correct credits for the selected founder plan.
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Renewal Control</div>
                <h2>Renew Existing Membership</h2>
                <div class="sub">
                    Manually renew a current membership, increment renewal count, reseed monthly credits, and apply quarterly service credit every 3 renewals.
                </div>

                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminDashboardEscape($csrfToken); ?>">

                    <select name="renew_membership_id" required>
                        <option value="">Select active membership</option>
                        <?php foreach ($memberMembershipSnapshots as $snapshot): ?>
                            <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                <option value="<?php echo (int) $snapshot['membership']['membership_id']; ?>">
                                    <?php echo ddAdminDashboardEscape($snapshot['member_name']); ?> · <?php echo ddAdminDashboardEscape($snapshot['membership']['plan_name']); ?> · Renewal <?php echo (int) $snapshot['membership']['renewal_count']; ?> · Membership ID <?php echo (int) $snapshot['membership']['membership_id']; ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="renew_membership" class="btn btn-light">Renew Membership</button>
                </form>

                <div class="helper">
                    Renewals add the founder plan’s monthly entitlement mix again. Every 3rd renewal adds the quarterly service credit for that founder plan.
                </div>
            </div>
        </section>

        <section class="wide-section">
            <div class="card">
                <div class="eyebrow">Credit Adjustment</div>
                <h2>Add or subtract credits</h2>
                <div class="sub">
                    Give extra credits, remove credits for corrections, or add service credit manually.
                </div>

                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminDashboardEscape($csrfToken); ?>">

                    <select name="adjust_membership_id" required>
                        <option value="">Select membership</option>
                        <?php foreach ($memberMembershipSnapshots as $snapshot): ?>
                            <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                <option value="<?php echo (int) $snapshot['membership']['membership_id']; ?>">
                                    <?php echo ddAdminDashboardEscape($snapshot['member_name']); ?> · <?php echo ddAdminDashboardEscape($snapshot['membership']['plan_name']); ?> · Membership ID <?php echo (int) $snapshot['membership']['membership_id']; ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <div class="form-grid two">
                        <select name="service_type" required>
                            <option value="walk">Walk</option>
                            <option value="daycare">Daycare</option>
                            <option value="drop-in">Drop-In</option>
                            <option value="boarding_night">Boarding Night</option>
                            <option value="service_credit">Service Credit</option>
                        </select>

                        <input type="number" name="amount" required placeholder="Use positive to add, negative to subtract">
                    </div>

                    <textarea name="reason" placeholder="Reason for adjustment (example: founder bonus, courtesy credit, correction)"></textarea>

                    <button type="submit" name="adjust_credits" class="btn btn-gold">Update Credits</button>
                </form>

                <div class="helper">
                    Positive numbers add credits. Negative numbers subtract credits. This logs an admin adjustment transaction automatically.
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Membership Snapshot</div>
                <h2>Current founder member balances</h2>

                <div class="list">
                    <?php if (empty($memberMembershipSnapshots)): ?>
                        <div class="empty">No member accounts were found.</div>
                    <?php else: ?>
                        <?php foreach (array_slice($memberMembershipSnapshots, 0, 8) as $snapshot): ?>
                            <div class="snapshot-box">
                                <div class="item-top">
                                    <div class="item-title">
                                        <?php echo ddAdminDashboardEscape($snapshot['member_name']); ?>
                                    </div>
                                    <span class="badge <?php echo (int) $snapshot['membership']['membership_id'] > 0 ? 'badge-accepted' : 'badge-pending'; ?>">
                                        <?php echo (int) $snapshot['membership']['membership_id'] > 0 ? 'Has Membership' : 'No Membership'; ?>
                                    </span>
                                </div>

                                <div class="item-meta">
                                    <?php echo $snapshot['email'] !== '' ? ddAdminDashboardEscape($snapshot['email']) . ' · ' : ''; ?>
                                    <?php echo ddAdminDashboardEscape($snapshot['membership']['plan_name']); ?>
                                    <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                        · Renewal <?php echo (int) $snapshot['membership']['renewal_count']; ?>
                                        · Membership ID <?php echo (int) $snapshot['membership']['membership_id']; ?>
                                    <?php endif; ?>
                                </div>

                                <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                    <div class="credit-pills">
                                        <div class="credit-pill">Walk: <?php echo (int) $snapshot['entitlements']['walk']; ?></div>
                                        <div class="credit-pill">Daycare: <?php echo (int) $snapshot['entitlements']['daycare']; ?></div>
                                        <div class="credit-pill">Drop-In: <?php echo (int) $snapshot['entitlements']['drop-in']; ?></div>
                                        <div class="credit-pill">Boarding: <?php echo (int) $snapshot['entitlements']['boarding_night']; ?></div>
                                        <div class="credit-pill">Service Credit: <?php echo (int) $snapshot['entitlements']['service_credit']; ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="sections">
            <div class="card">
                <div class="eyebrow">Recent Member Bookings</div>
                <h2>Latest member activity</h2>

                <div class="list">
                    <?php if (empty($recentMemberBookings)): ?>
                        <div class="empty">No member bookings found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentMemberBookings as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo ddAdminDashboardEscape(ddAdminDashboardServiceDisplayName($item['service_type'] ?? '')); ?>
                                    </div>
                                    <span class="badge <?php echo ddAdminDashboardEscape(ddAdminDashboardStatusBadgeClass($item['status'] ?? '')); ?>">
                                        <?php echo ddAdminDashboardEscape(str_replace('_', ' ', (string) ($item['status'] ?? ''))); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo ddAdminDashboardEscape((string) ($item['client_name'] ?? '')); ?> · <?php echo ddAdminDashboardEscape(ddAdminDashboardFormatDateDisplay($item['date'] ?? '')); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Recent Public Bookings</div>
                <h2>Latest non-member requests</h2>

                <div class="list">
                    <?php if (empty($recentPublicBookings)): ?>
                        <div class="empty">No public bookings found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentPublicBookings as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo ddAdminDashboardEscape(ddAdminDashboardServiceDisplayName($item['service_type'] ?? '')); ?>
                                    </div>
                                    <span class="badge <?php echo ddAdminDashboardEscape(ddAdminDashboardStatusBadgeClass($item['status'] ?? '')); ?>">
                                        <?php echo ddAdminDashboardEscape(str_replace('_', ' ', (string) ($item['status'] ?? ''))); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo ddAdminDashboardEscape((string) ($item['client_name'] ?? '')); ?> · <?php echo ddAdminDashboardEscape(ddAdminDashboardFormatDateDisplay($item['date'] ?? '')); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Recent Group Walk Applications</div>
                <h2>Latest applicants</h2>

                <div class="list">
                    <?php if (empty($recentGroupWalkApps)): ?>
                        <div class="empty">No group walk applications found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentGroupWalkApps as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo ddAdminDashboardEscape((string) ($item['owner_name'] ?? '')); ?>
                                    </div>
                                    <span class="badge <?php echo ddAdminDashboardEscape(ddAdminDashboardStatusBadgeClass($item['status'] ?? '')); ?>">
                                        <?php echo ddAdminDashboardEscape(str_replace('_', ' ', (string) ($item['status'] ?? ''))); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    Dog: <?php echo ddAdminDashboardEscape((string) ($item['dog_name'] ?? '')); ?> · <?php echo ddAdminDashboardEscape(ddAdminDashboardFormatDateDisplay($item['date'] ?? '')); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Quick Links</div>
                <h2>Admin shortcuts</h2>

                <div class="list">
                    <a class="item" href="admin-bookings.php">
                        <div class="item-title">Open bookings manager</div>
                        <div class="item-meta">Review member and public bookings in one place.</div>
                    </a>

                    <a class="item" href="admin-members.php">
                        <div class="item-title">Open member directory</div>
                        <div class="item-meta">See all signed-up members and their account details.</div>
                    </a>

                    <a class="item" href="admin-group-walk-applications.php">
                        <div class="item-title">Open group walk applications</div>
                        <div class="item-meta">Review, approve, or reject applicants.</div>
                    </a>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
