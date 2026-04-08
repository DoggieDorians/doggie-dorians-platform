<?php
declare(strict_types=1);

session_start();
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

function currentUserId()
{
    foreach (array('user_id', 'member_id', 'client_id', 'id') as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function isMemberLike()
{
    return currentUserId() > 0 && currentUserRole() !== 'walker';
}

if (!isMemberLike()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
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

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
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

function fetchOne(PDO $pdo, $sql, array $params = array())
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
    } catch (Exception $e) {
        return null;
    }
}

function fetchAllRows(PDO $pdo, $sql, array $params = array())
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

function normalizeReferralCodeLocal($code)
{
    if (function_exists('normalizeReferralCode')) {
        return normalizeReferralCode($code);
    }

    $code = strtoupper(trim((string) $code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return substr($code, 0, 30);
}

function generateReferralCodeLocal($seed)
{
    $seed = strtoupper(trim((string) $seed));
    $seed = preg_replace('/[^A-Z0-9]/', '', $seed);

    if ($seed === '') {
        $seed = 'DORIAN';
    }

    $seed = substr($seed, 0, 8);

    try {
        return $seed . random_int(100, 999);
    } catch (Throwable $e) {
        return $seed . mt_rand(100, 999);
    } catch (Exception $e) {
        return $seed . mt_rand(100, 999);
    }
}

function countUnreadNotificationsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $readCol = firstExistingColumn($pdo, $table, array('is_read', 'read_status', 'seen', 'viewed'));
        $userCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id'));

        if ($readCol === null || $userCol === null) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$userCol} = :id AND COALESCE({$readCol}, 0) = 0");
            if (safeExecute($stmt, array(':id' => $userId))) {
                return (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            continue;
        } catch (Exception $e) {
            continue;
        }
    }

    return 0;
}

function getUserRecord(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
            continue;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $row['_table'] = $table;
            $row['_id_col'] = $idCol;
            return $row;
        }
    }

    return null;
}

function getDisplayNameFromUser(array $row)
{
    foreach (array('full_name', 'name', 'client_name', 'member_name', 'username', 'email') as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return (string) $row[$key];
        }
    }

    return 'Member';
}

function getCurrentReferralCode(array $row)
{
    foreach (array('referral_code', 'ambassador_code', 'affiliate_code') as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return strtoupper(trim((string) $row[$key]));
        }
    }

    return '';
}

function saveReferralCodeToUser(PDO $pdo, array $row, $code)
{
    $table = isset($row['_table']) ? (string) $row['_table'] : '';
    $idCol = isset($row['_id_col']) ? (string) $row['_id_col'] : '';
    $idVal = isset($row[$idCol]) ? (int) $row[$idCol] : 0;

    if ($table === '' || $idCol === '' || $idVal <= 0) {
        return false;
    }

    $columns = getTableColumns($pdo, $table);
    $refCol = null;

    foreach (array('referral_code', 'ambassador_code', 'affiliate_code') as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $refCol = $candidate;
            break;
        }
    }

    if ($refCol === null) {
        return false;
    }

    $sql = 'UPDATE ' . $table . ' SET ' . $refCol . ' = :code WHERE ' . $idCol . ' = :id';
    $stmt = $pdo->prepare($sql);

    return safeExecute($stmt, array(':code' => $code, ':id' => $idVal));
}

function findOtherUserByReferralCode(PDO $pdo, $code, $excludeUserId)
{
    $excludeUserId = (int) $excludeUserId;
    $code = trim((string) $code);

    if ($code === '') {
        return null;
    }

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idCol === null) {
            continue;
        }

        $refCol = firstExistingColumn($pdo, $table, array('referral_code', 'ambassador_code', 'affiliate_code'));
        if ($refCol === null) {
            continue;
        }

        $sql = "SELECT * FROM {$table} WHERE UPPER({$refCol}) = UPPER(:code) AND {$idCol} != :id LIMIT 1";
        $row = fetchOne($pdo, $sql, array(':code' => $code, ':id' => $excludeUserId));

        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

function ensureUserHasReferralCode(PDO $pdo, array $user)
{
    $current = getCurrentReferralCode($user);
    if ($current !== '') {
        return $current;
    }

    $seed = getDisplayNameFromUser($user);

    for ($i = 0; $i < 25; $i++) {
        $candidate = generateReferralCodeLocal($seed);
        if (findOtherUserByReferralCode($pdo, $candidate, (int) $user[$user['_id_col']]) === null) {
            if (saveReferralCodeToUser($pdo, $user, $candidate)) {
                return $candidate;
            }
        }
    }

    return '';
}

function createNotificationIfPossible(PDO $pdo, $userId, $title, $message)
{
    $userId = (int) $userId;

    if (!hasTable($pdo, 'notifications')) {
        return;
    }

    $columns = getTableColumns($pdo, 'notifications');
    if (empty($columns)) {
        return;
    }

    $data = array();

    if (in_array('user_id', $columns, true)) {
        $data['user_id'] = $userId;
    }
    if (in_array('member_id', $columns, true)) {
        $data['member_id'] = $userId;
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
    if (in_array('type', $columns, true)) {
        $data['type'] = 'referral';
    }
    if (in_array('is_read', $columns, true)) {
        $data['is_read'] = 0;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = date('Y-m-d H:i:s');
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
        'INSERT INTO notifications (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    safeExecute($stmt, $params);
}

function getReferralStats(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    $stats = array(
        'total_referrals' => 0,
        'pending_referrals' => 0,
        'completed_referrals' => 0,
        'total_rewards' => 0.00,
    );

    if (!hasTable($pdo, 'referrals')) {
        return $stats;
    }

    $ownerCol = firstExistingColumn($pdo, 'referrals', array('referrer_user_id', 'ambassador_user_id', 'user_id', 'referrer_id', 'owner_user_id'));
    if ($ownerCol === null) {
        return $stats;
    }

    $statusCol = firstExistingColumn($pdo, 'referrals', array('status', 'referral_status', 'state'));
    $rewardCol = firstExistingColumn($pdo, 'referrals', array('reward_amount', 'commission_amount', 'credit_amount', 'payout_amount', 'amount'));

    $pendingExpr = '0';
    $completedExpr = '0';
    $rewardExpr = '0';

    if ($statusCol !== null) {
        $pendingExpr = "SUM(CASE WHEN LOWER(COALESCE({$statusCol}, '')) IN ('pending','new','awaiting','processing') OR COALESCE({$statusCol}, '') = '' THEN 1 ELSE 0 END)";
        $completedExpr = "SUM(CASE WHEN LOWER(COALESCE({$statusCol}, '')) IN ('completed','complete','paid','approved','converted') THEN 1 ELSE 0 END)";
    }

    if ($rewardCol !== null) {
        $rewardExpr = "COALESCE(SUM(CASE WHEN {$rewardCol} IS NOT NULL THEN {$rewardCol} ELSE 0 END), 0)";
    }

    $sql = "SELECT
        COUNT(*) AS total_referrals,
        {$pendingExpr} AS pending_referrals,
        {$completedExpr} AS completed_referrals,
        {$rewardExpr} AS total_rewards
        FROM referrals
        WHERE {$ownerCol} = :user_id";

    $row = fetchOne($pdo, $sql, array(':user_id' => $userId));

    if ($row !== null) {
        $stats['total_referrals'] = (int) (isset($row['total_referrals']) ? $row['total_referrals'] : 0);
        $stats['pending_referrals'] = (int) (isset($row['pending_referrals']) ? $row['pending_referrals'] : 0);
        $stats['completed_referrals'] = (int) (isset($row['completed_referrals']) ? $row['completed_referrals'] : 0);
        $stats['total_rewards'] = (float) (isset($row['total_rewards']) ? $row['total_rewards'] : 0);
    }

    return $stats;
}

function getRecentReferrals(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    if (!hasTable($pdo, 'referrals')) {
        return array();
    }

    $ownerCol = firstExistingColumn($pdo, 'referrals', array('referrer_user_id', 'ambassador_user_id', 'user_id', 'referrer_id', 'owner_user_id'));
    if ($ownerCol === null) {
        return array();
    }

    $statusCol = firstExistingColumn($pdo, 'referrals', array('status', 'referral_status', 'state'));
    $rewardCol = firstExistingColumn($pdo, 'referrals', array('reward_amount', 'commission_amount', 'credit_amount', 'payout_amount', 'amount'));
    $codeCol = firstExistingColumn($pdo, 'referrals', array('referral_code', 'code', 'used_code'));
    $createdCol = firstExistingColumn($pdo, 'referrals', array('created_at', 'referred_at', 'date_created'));
    $bookingCol = firstExistingColumn($pdo, 'referrals', array('booking_id', 'booking_reference', 'order_id'));
    $nameCol = firstExistingColumn($pdo, 'referrals', array('referred_name', 'customer_name', 'client_name', 'guest_name', 'name'));

    $select = array(
        ($nameCol !== null ? $nameCol : "''") . ' AS referred_name',
        ($codeCol !== null ? $codeCol : "''") . ' AS used_code',
        ($statusCol !== null ? $statusCol : "''") . ' AS referral_status',
        ($rewardCol !== null ? $rewardCol : '0') . ' AS reward_amount',
        ($bookingCol !== null ? $bookingCol : "''") . ' AS booking_ref',
        ($createdCol !== null ? $createdCol : "''") . ' AS created_at'
    );

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM referrals WHERE ' . $ownerCol . ' = :user_id';

    if ($createdCol !== null) {
        $sql .= ' ORDER BY ' . $createdCol . ' DESC';
    } else {
        $sql .= ' ORDER BY rowid DESC';
    }

    $sql .= ' LIMIT 12';

    return fetchAllRows($pdo, $sql, array(':user_id' => $userId));
}

$userId = currentUserId();
$user = getUserRecord($pdo, $userId);

if ($user === null) {
    http_response_code(404);
    echo 'Could not load your account.';
    exit;
}

$displayName = getDisplayNameFromUser($user);
$currentCode = ensureUserHasReferralCode($pdo, $user);

$flash = isset($_SESSION['ambassador_flash']) ? (string) $_SESSION['ambassador_flash'] : '';
$flashType = isset($_SESSION['ambassador_flash_type']) ? (string) $_SESSION['ambassador_flash_type'] : '';
unset($_SESSION['ambassador_flash'], $_SESSION['ambassador_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = normalizeReferralCodeLocal(isset($_POST['custom_referral_code']) ? $_POST['custom_referral_code'] : '');

    if ($submittedCode === '') {
        $_SESSION['ambassador_flash_type'] = 'error';
        $_SESSION['ambassador_flash'] = 'Please enter a referral code.';
        redirectTo('ambassadors.php');
    }

    if (strlen($submittedCode) < 4) {
        $_SESSION['ambassador_flash_type'] = 'error';
        $_SESSION['ambassador_flash'] = 'Referral code must be at least 4 characters.';
        redirectTo('ambassadors.php');
    }

    $existing = findOtherUserByReferralCode($pdo, $submittedCode, $userId);
    if ($existing !== null) {
        $_SESSION['ambassador_flash_type'] = 'error';
        $_SESSION['ambassador_flash'] = 'That referral code is already taken.';
        redirectTo('ambassadors.php');
    }

    if (saveReferralCodeToUser($pdo, $user, $submittedCode)) {
        createNotificationIfPossible(
            $pdo,
            $userId,
            'Ambassador code updated',
            'Your ambassador code was updated to ' . $submittedCode . '.'
        );

        $_SESSION['ambassador_flash_type'] = 'success';
        $_SESSION['ambassador_flash'] = 'Your ambassador code has been updated.';
    } else {
        $_SESSION['ambassador_flash_type'] = 'error';
        $_SESSION['ambassador_flash'] = 'We could not save your code right now.';
    }

    redirectTo('ambassadors.php');
}

$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);
$stats = getReferralStats($pdo, $userId);
$recentReferrals = getRecentReferrals($pdo, $userId);

$shareUrl = 'https://dorianspetcare.com/book-service.php';
$shareWithCodeUrl = $currentCode !== '' ? $shareUrl . '?ref=' . rawurlencode($currentCode) : $shareUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ambassadors | Doggie Dorian’s</title>
    <meta name="description" content="Manage your Doggie Dorian’s ambassador code and referral activity.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #09090d;
            color: #f4f1ea;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-primary {
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
            font-size: 1.25rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
            color: #d7f1dd;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .code-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.28);
            font-weight: 800;
            font-size: 18px;
            letter-spacing: 0.06em;
            flex-wrap: wrap;
            color: #f3e5c7;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.35rem;
            font-weight: 900;
        }

        .share-link {
            display: block;
            width: 100%;
            background: rgba(0,0,0,0.22);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 14px;
            padding: 12px 14px;
            word-break: break-all;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            cursor: pointer;
            transition: transform .15s ease;
            border: none;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
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
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
        }

        .helper {
            margin-top: 8px;
            color: rgba(244,241,234,0.62);
            font-size: .86rem;
            line-height: 1.6;
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
        }

        th {
            background: rgba(255,255,255,0.04);
            color: rgba(244,241,234,0.68);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
            background: rgba(255,255,255,0.08);
            color: #d7dce5;
        }

        .status.pending {
            background: rgba(255,183,77,0.14);
            color: #ffd08a;
        }

        .status.completed,
        .status.complete,
        .status.paid,
        .status.approved,
        .status.converted {
            background: rgba(125,206,141,0.14);
            color: #d7f1dd;
        }

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 980px) {
            .hero,
            .split,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .btn-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .code-badge {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>

            <div class="top-links">
                <a class="top-link" href="dashboard.php">Dashboard</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="profile.php">Profile</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Referral Program</div>
                <h1>Ambassador Center</h1>
                <div class="sub">
                    Manage your code, share your booking link, and track referral activity in one clean member dashboard.
                </div>

                <div class="code-badge">
                    <span>Your Code:</span>
                    <span><?php echo h($currentCode !== '' ? $currentCode : 'NOT SET'); ?></span>
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Total Referrals</div>
                        <div class="stat-value"><?php echo (int) $stats['total_referrals']; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?php echo (int) $stats['pending_referrals']; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Completed</div>
                        <div class="stat-value"><?php echo (int) $stats['completed_referrals']; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Tracked Rewards</div>
                        <div class="stat-value">$<?php echo h(number_format((float) $stats['total_rewards'], 2)); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Share Link</div>
                <h2>Send clients here</h2>
                <div class="share-link" id="share-link-box"><?php echo h($shareWithCodeUrl); ?></div>

                <div class="btn-row">
                    <button class="btn btn-gold" type="button" onclick="copyValue(document.getElementById('share-link-box').innerText, 'Booking link copied.')">Copy Link</button>
                    <button class="btn btn-light" type="button" onclick="copyValue('<?php echo h($currentCode); ?>', 'Ambassador code copied.')">Copy Code</button>
                </div>

                <div class="helper">
                    Share your booking link directly, or tell clients to use your ambassador code during booking.
                </div>
            </div>
        </section>

        <section class="split">
            <div class="card">
                <div class="eyebrow">Custom Code</div>
                <h2>Create your ambassador code</h2>
                <div class="sub" style="margin-bottom:18px;">
                    Choose a clean, memorable code you can use in posts, DMs, and referral links.
                </div>

                <form method="post" action="ambassadors.php" novalidate>
                    <div>
                        <label for="custom_referral_code">Custom Ambassador Code</label>
                        <input
                            id="custom_referral_code"
                            name="custom_referral_code"
                            type="text"
                            maxlength="30"
                            required
                            value="<?php echo h($currentCode); ?>"
                            placeholder="DORIANVIP"
                        >
                        <div class="helper">
                            Use letters, numbers, dashes, or underscores. Keep it easy to remember.
                        </div>
                    </div>

                    <div class="btn-row">
                        <button class="btn btn-gold" type="submit">Save Custom Code</button>
                        <button class="btn btn-light" type="button" onclick="fillSuggestion()">Suggest a Code</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="eyebrow">How To Use It</div>
                <h2>Turn your code into bookings</h2>
                <div class="sub" style="margin-bottom:18px;">
                    Use your ambassador code anywhere you promote Doggie Dorian’s. Your personal booking link already includes it.
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Use Case</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Instagram bio</td>
                                <td>Use code <strong><?php echo h($currentCode); ?></strong> when booking</td>
                            </tr>
                            <tr>
                                <td>Direct message</td>
                                <td><?php echo h($shareWithCodeUrl); ?></td>
                            </tr>
                            <tr>
                                <td>Referral post</td>
                                <td>Book with Doggie Dorian’s using my code when you schedule.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="btn-row">
                    <a class="btn btn-light" href="book-service.php?ref=<?php echo rawurlencode($currentCode); ?>">Test My Booking Link</a>
                    <a class="btn btn-light" href="notifications.php">View Notifications</a>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="eyebrow">Referral Activity</div>
            <h2>Recent referral activity</h2>

            <?php if (empty($recentReferrals)): ?>
                <div class="empty">No referral activity has been recorded yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Referred Client</th>
                                <th>Code Used</th>
                                <th>Status</th>
                                <th>Reward</th>
                                <th>Booking</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentReferrals as $row): ?>
                                <?php
                                $status = strtolower(trim((string) (isset($row['referral_status']) ? $row['referral_status'] : '')));
                                if ($status === '') {
                                    $status = 'pending';
                                }
                                ?>
                                <tr>
                                    <td><?php echo h((isset($row['referred_name']) && trim((string) $row['referred_name']) !== '') ? $row['referred_name'] : '—'); ?></td>
                                    <td><?php echo h((isset($row['used_code']) && trim((string) $row['used_code']) !== '') ? $row['used_code'] : $currentCode); ?></td>
                                    <td><span class="status <?php echo h($status); ?>"><?php echo h($status); ?></span></td>
                                    <td>$<?php echo h(number_format((float) (isset($row['reward_amount']) ? $row['reward_amount'] : 0), 2)); ?></td>
                                    <td><?php echo h((isset($row['booking_ref']) && trim((string) $row['booking_ref']) !== '') ? $row['booking_ref'] : '—'); ?></td>
                                    <td><?php echo h((isset($row['created_at']) && trim((string) $row['created_at']) !== '') ? $row['created_at'] : '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        function copyValue(value, successMessage) {
            if (!value) {
                alert('Nothing to copy yet.');
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function () {
                    alert(successMessage);
                }).catch(function () {
                    fallbackCopy(value, successMessage);
                });
            } else {
                fallbackCopy(value, successMessage);
            }
        }

        function fallbackCopy(value, successMessage) {
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                alert(successMessage);
            } catch (e) {
                alert('Copy failed. Please copy it manually.');
            }

            document.body.removeChild(textarea);
        }

        function fillSuggestion() {
            var input = document.getElementById('custom_referral_code');
            if (!input) return;

            var suggestions = [
                'DOGGIEDORIAN',
                'DORIANVIP',
                'WALKCLUBNYC',
                'DOGLUXE',
                'UPTOWNDOGS',
                'PACKLEADER',
                'PETCAREVIP'
            ];

            var pick = suggestions[Math.floor(Math.random() * suggestions.length)];
            input.value = pick;
            input.focus();
        }
    </script>
</body>
</html>