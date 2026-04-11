<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin') {
        return true;
    }

    return false;
}

if (!isAdmin()) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function safeFetchOne(PDO $pdo, $sql, array $params = array())
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
    } catch (Exception $e) {
        return null;
    }
}

function safeFetchAll(PDO $pdo, $sql, array $params = array())
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
    } catch (Exception $e) {
        return array();
    }
}

function tableExists(PDO $pdo, $table)
{
    $row = safeFetchOne(
        $pdo,
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
        array(':table' => $table)
    );

    return $row !== null;
}

function hasTable(PDO $pdo, $table)
{
    return tableExists($pdo, $table);
}

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    } catch (Exception $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    $columns = getTableColumns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function valueFromRow(array $row = null, array $keys = array(), $default = null)
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

function countTable(PDO $pdo, $table)
{
    if (!tableExists($pdo, $table)) {
        return 0;
    }

    $row = safeFetchOne($pdo, 'SELECT COUNT(*) AS count_value FROM ' . $table);
    return (int) valueFromRow($row, array('count_value'), 0);
}

function fetchMemberCount(PDO $pdo)
{
    foreach (array('members', 'users') as $table) {
        if (tableExists($pdo, $table)) {
            $row = safeFetchOne($pdo, 'SELECT COUNT(*) AS count_value FROM ' . $table);
            return (int) valueFromRow($row, array('count_value'), 0);
        }
    }

    return 0;
}

function countUnreadNotifications(PDO $pdo)
{
    if (!tableExists($pdo, 'notifications')) {
        return 0;
    }

    $statusCol = firstExistingColumn($pdo, 'notifications', array('is_read', 'read_flag', 'status'));
    if ($statusCol === null) {
        return countTable($pdo, 'notifications');
    }

    if ($statusCol === 'status') {
        $row = safeFetchOne($pdo, "SELECT COUNT(*) AS count_value FROM notifications WHERE LOWER(COALESCE(status, '')) IN ('unread', 'new')");
        return (int) valueFromRow($row, array('count_value'), 0);
    }

    $row = safeFetchOne($pdo, 'SELECT COUNT(*) AS count_value FROM notifications WHERE COALESCE(' . $statusCol . ', 0) = 0');
    return (int) valueFromRow($row, array('count_value'), 0);
}

function serviceDisplayName($serviceType)
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

function statusBadgeClass($status)
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

function formatDateDisplay($value)
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

function fetchRecentMemberBookings(PDO $pdo, $limit = 5)
{
    if (tableExists($pdo, 'bookings')) {
        $serviceCol = firstExistingColumn($pdo, 'bookings', array('service_type', 'service'));
        $statusCol = firstExistingColumn($pdo, 'bookings', array('status'));
        $dateCol = firstExistingColumn($pdo, 'bookings', array('date', 'booking_date', 'service_date', 'created_at'));
        $nameCol = firstExistingColumn($pdo, 'bookings', array('client_name', 'full_name', 'name'));

        $sql = 'SELECT id';
        $sql .= $serviceCol ? ', ' . $serviceCol . ' AS service_type' : ", '' AS service_type";
        $sql .= $statusCol ? ', ' . $statusCol . ' AS status' : ", '' AS status";
        $sql .= $dateCol ? ', ' . $dateCol . ' AS date' : ", '' AS date";
        $sql .= $nameCol ? ', ' . $nameCol . ' AS client_name' : ", '' AS client_name";
        $sql .= ' FROM bookings ORDER BY id DESC LIMIT ' . (int) $limit;

        return safeFetchAll($pdo, $sql);
    }

    if (tableExists($pdo, 'walks')) {
        $statusCol = firstExistingColumn($pdo, 'walks', array('status'));
        $dateCol = firstExistingColumn($pdo, 'walks', array('date', 'walk_date', 'service_date', 'created_at'));
        $nameCol = firstExistingColumn($pdo, 'walks', array('client_name', 'full_name', 'name'));

        $sql = "SELECT id, 'walk' AS service_type";
        $sql .= $statusCol ? ', ' . $statusCol . ' AS status' : ", '' AS status";
        $sql .= $dateCol ? ', ' . $dateCol . ' AS date' : ", '' AS date";
        $sql .= $nameCol ? ', ' . $nameCol . ' AS client_name' : ", '' AS client_name";
        $sql .= ' FROM walks ORDER BY id DESC LIMIT ' . (int) $limit;

        return safeFetchAll($pdo, $sql);
    }

    return array();
}

function fetchRecentPublicBookings(PDO $pdo, $limit = 5)
{
    if (!tableExists($pdo, 'non_member_bookings')) {
        return array();
    }

    $serviceCol = firstExistingColumn($pdo, 'non_member_bookings', array('service_type', 'service'));
    $statusCol = firstExistingColumn($pdo, 'non_member_bookings', array('status'));
    $dateCol = firstExistingColumn($pdo, 'non_member_bookings', array('date', 'booking_date', 'service_date', 'created_at'));
    $nameCol = firstExistingColumn($pdo, 'non_member_bookings', array('client_name', 'full_name', 'name'));

    $sql = 'SELECT id';
    $sql .= $serviceCol ? ', ' . $serviceCol . ' AS service_type' : ", '' AS service_type";
    $sql .= $statusCol ? ', ' . $statusCol . ' AS status' : ", '' AS status";
    $sql .= $dateCol ? ', ' . $dateCol . ' AS date' : ", '' AS date";
    $sql .= $nameCol ? ', ' . $nameCol . ' AS client_name' : ", '' AS client_name";
    $sql .= ' FROM non_member_bookings ORDER BY id DESC LIMIT ' . (int) $limit;

    return safeFetchAll($pdo, $sql);
}

function fetchRecentGroupWalkApplications(PDO $pdo, $limit = 5)
{
    if (!tableExists($pdo, 'group_walk_applications')) {
        return array();
    }

    $statusCol = firstExistingColumn($pdo, 'group_walk_applications', array('status'));
    $dateCol = firstExistingColumn($pdo, 'group_walk_applications', array('date', 'created_at', 'submitted_at'));
    $ownerCol = firstExistingColumn($pdo, 'group_walk_applications', array('owner_name', 'client_name', 'full_name', 'name'));
    $dogCol = firstExistingColumn($pdo, 'group_walk_applications', array('dog_name', 'pet_name'));

    $sql = 'SELECT id';
    $sql .= $ownerCol ? ', ' . $ownerCol . ' AS owner_name' : ", '' AS owner_name";
    $sql .= $dogCol ? ', ' . $dogCol . ' AS dog_name' : ", '' AS dog_name";
    $sql .= $statusCol ? ', ' . $statusCol . ' AS status' : ", '' AS status";
    $sql .= $dateCol ? ', ' . $dateCol . ' AS date' : ", '' AS date";
    $sql .= ' FROM group_walk_applications ORDER BY id DESC LIMIT ' . (int) $limit;

    return safeFetchAll($pdo, $sql);
}

/* =========================
   MEMBERSHIP HELPERS
========================= */

function dd_plan_catalog()
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

function dd_fetch_member_options(PDO $pdo)
{
    foreach (array('members', 'users') as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'member_id', 'user_id'));
        if ($idCol === null) {
            continue;
        }

        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'client_name', 'first_name'));
        $lastNameCol = firstExistingColumn($pdo, $table, array('last_name'));
        $emailCol = firstExistingColumn($pdo, $table, array('email'));

        $rows = safeFetchAll($pdo, 'SELECT * FROM ' . $table . ' ORDER BY ' . $idCol . ' DESC');
        $options = array();

        foreach ($rows as $row) {
            $name = '';
            if ($nameCol !== null) {
                $name = trim((string) ($row[$nameCol] ?? ''));
            }

            if ($name !== '' && $lastNameCol !== null && !empty($row[$lastNameCol]) && stripos($name, (string) $row[$lastNameCol]) === false) {
                $name .= ' ' . trim((string) $row[$lastNameCol]);
            }

            if ($name === '' && isset($row['first_name'])) {
                $name = trim((string) $row['first_name'] . ' ' . (string) ($row['last_name'] ?? ''));
            }

            if ($name === '') {
                $name = 'Member #' . (int) $row[$idCol];
            }

            $options[] = array(
                'member_id' => (int) $row[$idCol],
                'member_name' => $name,
                'email' => $emailCol !== null ? trim((string) ($row[$emailCol] ?? '')) : '',
            );
        }

        return $options;
    }

    return array();
}

function dd_ensure_membership_plans_table(PDO $pdo)
{
    if (hasTable($pdo, 'membership_plans')) {
        return true;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS membership_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT,
                name TEXT,
                created_at TEXT
            )
        ");
    } catch (Throwable $e) {
        return hasTable($pdo, 'membership_plans');
    } catch (Exception $e) {
        return hasTable($pdo, 'membership_plans');
    }

    return hasTable($pdo, 'membership_plans');
}

function dd_find_plan_row(PDO $pdo, $planSlug)
{
    if (!dd_ensure_membership_plans_table($pdo)) {
        return null;
    }

    $slugCol = firstExistingColumn($pdo, 'membership_plans', array('slug', 'plan_slug', 'code'));
    $nameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));

    if ($slugCol !== null) {
        $row = safeFetchOne(
            $pdo,
            'SELECT * FROM membership_plans WHERE LOWER(TRIM(COALESCE(' . $slugCol . ", ''))) = :slug LIMIT 1",
            array(':slug' => strtolower(trim((string) $planSlug)))
        );
        if ($row !== null) {
            return $row;
        }
    }

    $catalog = dd_plan_catalog();
    if ($nameCol !== null && isset($catalog[$planSlug])) {
        $row = safeFetchOne(
            $pdo,
            'SELECT * FROM membership_plans WHERE LOWER(TRIM(COALESCE(' . $nameCol . ", ''))) = :name LIMIT 1",
            array(':name' => strtolower(trim((string) $catalog[$planSlug]['name'])))
        );
        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

function dd_insert_plan_row(PDO $pdo, $planSlug, array $config)
{
    if (!dd_ensure_membership_plans_table($pdo)) {
        return false;
    }

    $columns = getTableColumns($pdo, 'membership_plans');
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
        $params[':name'] = $config['name'];
    } elseif (in_array('plan_name', $columns, true)) {
        $insertCols[] = 'plan_name';
        $params[':plan_name'] = $config['name'];
    } elseif (in_array('title', $columns, true)) {
        $insertCols[] = 'title';
        $params[':title'] = $config['name'];
    }

    if (in_array('created_at', $columns, true)) {
        $insertCols[] = 'created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insertCols)) {
        return false;
    }

    try {
        $placeholders = array();
        foreach ($insertCols as $col) {
            $placeholders[] = ':' . $col;
        }

        $sql = 'INSERT INTO membership_plans (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);

        return safeExecute($stmt, $params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function dd_ensure_founder_plans(PDO $pdo)
{
    if (!dd_ensure_membership_plans_table($pdo)) {
        return false;
    }

    $catalog = dd_plan_catalog();

    foreach ($catalog as $slug => $config) {
        $existing = dd_find_plan_row($pdo, $slug);
        if ($existing !== null) {
            continue;
        }

        dd_insert_plan_row($pdo, $slug, $config);
    }

    foreach ($catalog as $slug => $config) {
        if (dd_find_plan_row($pdo, $slug) === null) {
            return false;
        }
    }

    return true;
}

function dd_create_membership_row(PDO $pdo, $memberId, $planId)
{
    if (!hasTable($pdo, 'member_memberships')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'member_memberships');
    $insert = array();
    $params = array();

    $memberCol = firstExistingColumn($pdo, 'member_memberships', array('member_id', 'user_id'));
    $planCol = firstExistingColumn($pdo, 'member_memberships', array('plan_id'));

    if ($memberCol === null || $planCol === null) {
        return 0;
    }

    $insert[] = $memberCol;
    $params[':' . $memberCol] = (int) $memberId;

    $insert[] = $planCol;
    $params[':' . $planCol] = (int) $planId;

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

    $placeholders = array();
    foreach ($insert as $column) {
        $placeholders[] = ':' . $column;
    }

    $sql = 'INSERT INTO member_memberships (' . implode(', ', $insert) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);

    if (!safeExecute($stmt, $params)) {
        return 0;
    }

    return (int) $pdo->lastInsertId();
}

function dd_get_current_membership_for_member(PDO $pdo, $memberId)
{
    if (!hasTable($pdo, 'member_memberships')) {
        return null;
    }

    $memberCol = firstExistingColumn($pdo, 'member_memberships', array('member_id', 'user_id'));
    if ($memberCol === null) {
        return null;
    }

    $orderCol = firstExistingColumn($pdo, 'member_memberships', array('created_at', 'updated_at', 'id'));
    if ($orderCol === null) {
        $orderCol = 'id';
    }

    return safeFetchOne(
        $pdo,
        'SELECT * FROM member_memberships WHERE ' . $memberCol . ' = :member_id ORDER BY ' . $orderCol . ' DESC, id DESC LIMIT 1',
        array(':member_id' => (int) $memberId)
    );
}

function dd_get_membership_display_summary(PDO $pdo, array $membershipRow = null)
{
    $summary = array(
        'membership_id' => 0,
        'plan_name' => 'No Membership',
        'renewal_count' => 0,
    );

    if ($membershipRow === null) {
        return $summary;
    }

    $summary['membership_id'] = (int) valueFromRow($membershipRow, array('id'), 0);
    $summary['renewal_count'] = (int) valueFromRow($membershipRow, array('renewal_count'), 0);

    $planSlug = dd_plan_slug_from_membership($pdo, $membershipRow);
    $catalog = dd_plan_catalog();

    if ($planSlug !== '' && isset($catalog[$planSlug])) {
        $summary['plan_name'] = $catalog[$planSlug]['name'];
        return $summary;
    }

    $planId = (int) valueFromRow($membershipRow, array('plan_id'), 0);
    if ($planId > 0 && hasTable($pdo, 'membership_plans')) {
        $planIdCol = firstExistingColumn($pdo, 'membership_plans', array('id', 'plan_id'));
        $nameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));

        if ($planIdCol !== null) {
            $planRow = safeFetchOne(
                $pdo,
                'SELECT * FROM membership_plans WHERE ' . $planIdCol . ' = :plan_id LIMIT 1',
                array(':plan_id' => $planId)
            );

            if ($planRow !== null && $nameCol !== null && !empty($planRow[$nameCol])) {
                $summary['plan_name'] = (string) $planRow[$nameCol];
            }
        }
    }

    return $summary;
}

function dd_get_membership_entitlements(PDO $pdo, $membershipId)
{
    $default = array(
        'walk' => 0,
        'daycare' => 0,
        'drop-in' => 0,
        'boarding_night' => 0,
        'service_credit' => 0,
    );

    if ($membershipId <= 0 || !hasTable($pdo, 'membership_entitlements')) {
        return $default;
    }

    $rows = safeFetchAll(
        $pdo,
        'SELECT entitlement_type, total, used FROM membership_entitlements WHERE membership_id = :membership_id',
        array(':membership_id' => (int) $membershipId)
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

function dd_log_membership_transaction(PDO $pdo, $membershipId, $serviceType, $transactionType, $amount, $note = '', $externalId = '')
{
    if (!hasTable($pdo, 'membership_transactions')) {
        return;
    }

    $columns = getTableColumns($pdo, 'membership_transactions');
    $insert = array();
    $params = array();

    if (in_array('membership_id', $columns, true)) {
        $insert[] = 'membership_id';
        $params[':membership_id'] = (int) $membershipId;
    }

    if (in_array('transaction_type', $columns, true)) {
        $insert[] = 'transaction_type';
        $params[':transaction_type'] = (string) $transactionType;
    }

    if (in_array('amount', $columns, true)) {
        $insert[] = 'amount';
        $params[':amount'] = (int) $amount;
    }

    if (in_array('note', $columns, true)) {
        $insert[] = 'note';
        $params[':note'] = (string) ($serviceType . ($note !== '' ? ' | ' . $note : ''));
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
        $sql = 'INSERT INTO membership_transactions (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
        $stmt = $pdo->prepare($sql);
        safeExecute($stmt, $params);
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }
}

function dd_upsert_entitlement_units(PDO $pdo, $membershipId, $serviceType, $amount)
{
    if (!hasTable($pdo, 'membership_entitlements')) {
        return array('ok' => false, 'message' => 'membership_entitlements table was not found.');
    }

    if ($membershipId <= 0) {
        return array('ok' => false, 'message' => 'Invalid membership.');
    }

    $existing = safeFetchOne(
        $pdo,
        'SELECT * FROM membership_entitlements WHERE membership_id = :membership_id AND entitlement_type = :entitlement_type LIMIT 1',
        array(
            ':membership_id' => (int) $membershipId,
            ':entitlement_type' => (string) $serviceType,
        )
    );

    if ($existing === null) {
        $startingTotal = max(0, (int) $amount);

        $columns = getTableColumns($pdo, 'membership_entitlements');
        $insert = array();
        $params = array();

        foreach (array('membership_id', 'entitlement_type', 'total', 'used', 'created_at') as $column) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $insert[] = $column;

            if ($column === 'membership_id') {
                $params[':membership_id'] = (int) $membershipId;
            } elseif ($column === 'entitlement_type') {
                $params[':entitlement_type'] = (string) $serviceType;
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

        $sql = 'INSERT INTO membership_entitlements (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
        $stmt = $pdo->prepare($sql);

        if (!safeExecute($stmt, $params)) {
            return array('ok' => false, 'message' => 'Could not create entitlement row.');
        }

        return array('ok' => true, 'message' => 'Entitlement created.');
    }

    $currentTotal = (int) valueFromRow($existing, array('total'), 0);
    $currentUsed = (int) valueFromRow($existing, array('used'), 0);
    $newTotal = $currentTotal + (int) $amount;

    if ($newTotal < $currentUsed) {
        $newTotal = $currentUsed;
    }

    if ($newTotal < 0) {
        $newTotal = 0;
    }

    $sql = 'UPDATE membership_entitlements SET total = :total WHERE id = :id';
    $params = array(
        ':total' => $newTotal,
        ':id' => (int) valueFromRow($existing, array('id'), 0),
    );

    $stmt = $pdo->prepare($sql);
    if (!safeExecute($stmt, $params)) {
        return array('ok' => false, 'message' => 'Could not update entitlement row.');
    }

    return array('ok' => true, 'message' => 'Entitlement updated.');
}

function dd_seed_membership_entitlements(PDO $pdo, $membershipId, array $planConfig, $reason)
{
    if (!isset($planConfig['entitlements']) || !is_array($planConfig['entitlements'])) {
        return;
    }

    foreach ($planConfig['entitlements'] as $serviceType => $units) {
        if ((int) $units <= 0) {
            continue;
        }

        $result = dd_upsert_entitlement_units($pdo, $membershipId, $serviceType, (int) $units);
        if ($result['ok']) {
            dd_log_membership_transaction($pdo, $membershipId, $serviceType, 'credit', (int) $units, $reason, $planConfig['name']);
        }
    }
}

function dd_assign_membership_admin(PDO $pdo, $memberId, $planSlug)
{
    $catalog = dd_plan_catalog();

    if (!isset($catalog[$planSlug])) {
        return array('ok' => false, 'message' => 'Unknown membership plan selected.');
    }

    if (!dd_ensure_founder_plans($pdo)) {
        return array('ok' => false, 'message' => 'Could not prepare founder membership plans.');
    }

    $planRow = dd_find_plan_row($pdo, $planSlug);
    if ($planRow === null) {
        return array('ok' => false, 'message' => 'Selected plan was not found in membership_plans.');
    }

    $planId = (int) valueFromRow($planRow, array('id', 'plan_id'), 0);
    if ($planId <= 0) {
        return array('ok' => false, 'message' => 'Selected plan ID is invalid.');
    }

    $pdo->beginTransaction();

    try {
        $membershipId = dd_create_membership_row($pdo, (int) $memberId, $planId);
        if ($membershipId <= 0) {
            throw new RuntimeException('Could not create membership row.');
        }

        dd_seed_membership_entitlements($pdo, $membershipId, $catalog[$planSlug], 'admin_initial_assignment');

        $pdo->commit();
        return array('ok' => true, 'message' => $catalog[$planSlug]['name'] . ' assigned successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => $e->getMessage());
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => $e->getMessage());
    }
}

function dd_plan_slug_from_membership(PDO $pdo, array $membershipRow = null)
{
    if ($membershipRow === null) {
        return '';
    }

    $planId = (int) valueFromRow($membershipRow, array('plan_id'), 0);
    if ($planId <= 0 || !hasTable($pdo, 'membership_plans')) {
        return '';
    }

    $planIdCol = firstExistingColumn($pdo, 'membership_plans', array('id', 'plan_id'));
    $slugCol = firstExistingColumn($pdo, 'membership_plans', array('slug', 'plan_slug', 'code'));
    $nameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));

    if ($planIdCol === null) {
        return '';
    }

    $row = safeFetchOne($pdo, 'SELECT * FROM membership_plans WHERE ' . $planIdCol . ' = :plan_id LIMIT 1', array(':plan_id' => $planId));
    if ($row === null) {
        return '';
    }

    if ($slugCol !== null && !empty($row[$slugCol])) {
        return strtolower(trim((string) $row[$slugCol]));
    }

    $planName = $nameCol !== null ? strtolower(trim((string) ($row[$nameCol] ?? ''))) : '';
    foreach (dd_plan_catalog() as $slug => $config) {
        if (strtolower($config['name']) === $planName) {
            return $slug;
        }
    }

    return '';
}

function dd_renew_membership_admin(PDO $pdo, $membershipId)
{
    $membershipRow = safeFetchOne($pdo, 'SELECT * FROM member_memberships WHERE id = :id LIMIT 1', array(':id' => (int) $membershipId));
    if ($membershipRow === null) {
        return array('ok' => false, 'message' => 'Membership not found.');
    }

    $planSlug = dd_plan_slug_from_membership($pdo, $membershipRow);
    $catalog = dd_plan_catalog();

    if ($planSlug === '' || !isset($catalog[$planSlug])) {
        return array('ok' => false, 'message' => 'Could not match membership to a founder plan.');
    }

    $renewalCount = (int) valueFromRow($membershipRow, array('renewal_count'), 0);
    $nextRenewalCount = $renewalCount + 1;

    $pdo->beginTransaction();

    try {
        if (in_array('renewal_count', getTableColumns($pdo, 'member_memberships'), true)) {
            $sql = 'UPDATE member_memberships SET renewal_count = :renewal_count';
            if (in_array('updated_at', getTableColumns($pdo, 'member_memberships'), true)) {
                $sql .= ', updated_at = :updated_at';
            }
            $sql .= ' WHERE id = :id';

            $params = array(
                ':renewal_count' => $nextRenewalCount,
                ':id' => (int) $membershipId,
            );

            if (in_array('updated_at', getTableColumns($pdo, 'member_memberships'), true)) {
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare($sql);
            if (!safeExecute($stmt, $params)) {
                throw new RuntimeException('Failed to update renewal count.');
            }
        }

        dd_seed_membership_entitlements($pdo, $membershipId, $catalog[$planSlug], 'admin_renewal_allocation');

        if ($nextRenewalCount % 3 === 0 && (int) $catalog[$planSlug]['quarterly_service_credit'] > 0) {
            $bonus = (int) $catalog[$planSlug]['quarterly_service_credit'];
            $result = dd_upsert_entitlement_units($pdo, $membershipId, 'service_credit', $bonus);
            if (!$result['ok']) {
                throw new RuntimeException($result['message']);
            }

            dd_log_membership_transaction(
                $pdo,
                $membershipId,
                'service_credit',
                'credit',
                $bonus,
                'admin_quarterly_bonus',
                $catalog[$planSlug]['name']
            );
        }

        $pdo->commit();
        return array('ok' => true, 'message' => 'Membership renewed successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => $e->getMessage());
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => $e->getMessage());
    }
}

function dd_adjust_membership_credit_admin(PDO $pdo, $membershipId, $serviceType, $amount, $reason)
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
        $result = dd_upsert_entitlement_units($pdo, $membershipId, $serviceType, (int) $amount);
        if (!$result['ok']) {
            throw new RuntimeException($result['message']);
        }

        dd_log_membership_transaction(
            $pdo,
            $membershipId,
            $serviceType,
            $amount > 0 ? 'credit' : 'debit',
            abs((int) $amount),
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
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => $e->getMessage());
    }
}

/* =========================
   HANDLE ADMIN FORM ACTIONS
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = array('ok' => false, 'message' => 'No action was processed.');

    if (isset($_POST['assign_membership'])) {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $planSlug = trim((string) ($_POST['plan_slug'] ?? ''));

        $result = dd_assign_membership_admin($pdo, $memberId, $planSlug);
    } elseif (isset($_POST['renew_membership'])) {
        $membershipId = (int) ($_POST['renew_membership_id'] ?? 0);
        $result = dd_renew_membership_admin($pdo, $membershipId);
    } elseif (isset($_POST['adjust_credits'])) {
        $membershipId = (int) ($_POST['adjust_membership_id'] ?? 0);
        $serviceType = trim((string) ($_POST['service_type'] ?? ''));
        $amount = (int) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Admin adjustment'));

        $result = dd_adjust_membership_credit_admin($pdo, $membershipId, $serviceType, $amount, $reason);
    }

    $_SESSION['admin_dashboard_flash'] = (string) $result['message'];
    redirectTo('admin-dashboard.php');
}

$flash = isset($_SESSION['admin_dashboard_flash']) ? (string) $_SESSION['admin_dashboard_flash'] : '';
unset($_SESSION['admin_dashboard_flash']);

$memberBookings = countTable($pdo, 'bookings');
if ($memberBookings === 0) {
    $memberBookings = countTable($pdo, 'walks');
}
$publicBookings = countTable($pdo, 'non_member_bookings');
$groupWalkApps = countTable($pdo, 'group_walk_applications');
$memberCount = fetchMemberCount($pdo);
$unreadNotifications = countUnreadNotifications($pdo);

$totalBookings = $memberBookings + $publicBookings;

$recentMemberBookings = fetchRecentMemberBookings($pdo, 5);
$recentPublicBookings = fetchRecentPublicBookings($pdo, 5);
$recentGroupWalkApps = fetchRecentGroupWalkApplications($pdo, 5);

$newPublicCount = 0;
foreach ($recentPublicBookings as $row) {
    if (($row['status'] ?? '') === 'new') {
        $newPublicCount++;
    }
}

$memberOptions = dd_fetch_member_options($pdo);
$founderPlans = dd_plan_catalog();

$memberMembershipSnapshots = array();
foreach ($memberOptions as $memberOption) {
    $membershipRow = dd_get_current_membership_for_member($pdo, (int) $memberOption['member_id']);
    $summary = dd_get_membership_display_summary($pdo, $membershipRow);
    $entitlements = dd_get_membership_entitlements($pdo, (int) $summary['membership_id']);

    $memberMembershipSnapshots[] = array(
        'member_id' => (int) $memberOption['member_id'],
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
                <a href="admin-bookings.php">Bookings</a>
                <a href="admin-members.php">Members</a>
                <a href="admin-group-walk-applications.php">Group Walks</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo h($flash); ?></div>
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
                    <select name="member_id" required>
                        <option value="">Select member</option>
                        <?php foreach ($memberOptions as $member): ?>
                            <option value="<?php echo (int) $member['member_id']; ?>">
                                <?php echo h($member['member_name']); ?><?php echo $member['email'] !== '' ? ' · ' . h($member['email']) : ''; ?> · ID <?php echo (int) $member['member_id']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="plan_slug" required>
                        <option value="">Select founder plan</option>
                        <?php foreach ($founderPlans as $slug => $config): ?>
                            <option value="<?php echo h($slug); ?>"><?php echo h($config['name']); ?></option>
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
                    <select name="renew_membership_id" required>
                        <option value="">Select active membership</option>
                        <?php foreach ($memberMembershipSnapshots as $snapshot): ?>
                            <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                <option value="<?php echo (int) $snapshot['membership']['membership_id']; ?>">
                                    <?php echo h($snapshot['member_name']); ?> · <?php echo h($snapshot['membership']['plan_name']); ?> · Renewal <?php echo (int) $snapshot['membership']['renewal_count']; ?> · Membership ID <?php echo (int) $snapshot['membership']['membership_id']; ?>
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
                    <select name="adjust_membership_id" required>
                        <option value="">Select membership</option>
                        <?php foreach ($memberMembershipSnapshots as $snapshot): ?>
                            <?php if ((int) $snapshot['membership']['membership_id'] > 0): ?>
                                <option value="<?php echo (int) $snapshot['membership']['membership_id']; ?>">
                                    <?php echo h($snapshot['member_name']); ?> · <?php echo h($snapshot['membership']['plan_name']); ?> · Membership ID <?php echo (int) $snapshot['membership']['membership_id']; ?>
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
                                        <?php echo h($snapshot['member_name']); ?>
                                    </div>
                                    <span class="badge <?php echo (int) $snapshot['membership']['membership_id'] > 0 ? 'badge-accepted' : 'badge-pending'; ?>">
                                        <?php echo (int) $snapshot['membership']['membership_id'] > 0 ? 'Has Membership' : 'No Membership'; ?>
                                    </span>
                                </div>

                                <div class="item-meta">
                                    <?php echo $snapshot['email'] !== '' ? h($snapshot['email']) . ' · ' : ''; ?>
                                    <?php echo h($snapshot['membership']['plan_name']); ?>
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
                                        #<?php echo (int) $item['id']; ?> · <?php echo h(serviceDisplayName($item['service_type'])); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', (string) $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo h($item['client_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
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
                                        #<?php echo (int) $item['id']; ?> · <?php echo h(serviceDisplayName($item['service_type'])); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', (string) $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo h($item['client_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
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
                                        #<?php echo (int) $item['id']; ?> · <?php echo h($item['owner_name']); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', (string) $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    Dog: <?php echo h($item['dog_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
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