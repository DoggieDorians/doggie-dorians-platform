<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| ACCESS CHECK
|--------------------------------------------------------------------------
| Do NOT redirect to member dashboard from this file.
| If session is not admin enough, show an on-page access message instead.
|--------------------------------------------------------------------------
*/

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$roleRaw  = (string) ($_SESSION['role'] ?? '');
$role     = strtolower(trim($roleRaw));
$isAdmin  = !empty($_SESSION['is_admin']);

$allowedRoles = ['admin', 'superadmin', 'owner'];

$hasAdminAccess = (
    $isAdmin ||
    $adminId > 0 ||
    ($userId > 0 && in_array($role, $allowedRoles, true))
);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($tableName) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
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

function buildSelectFragment(?string $column, string $alias, string $fallbackSql = 'NULL', string $tableAlias = ''): string
{
    if ($column === null) {
        return $fallbackSql . ' AS ' . quotedIdentifier($alias);
    }

    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    return $prefix . quotedIdentifier($column) . ' AS ' . quotedIdentifier($alias);
}

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function formatDateTimeValue(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y • g:i A');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function formatNumberValue(mixed $value, int $decimals = 5): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (!is_numeric($value)) {
        return (string) $value;
    }

    return number_format((float) $value, $decimals);
}

function badgeClass(string $status): string
{
    $status = strtolower(trim($status));

    return match (true) {
        in_array($status, ['active', 'in_progress', 'in progress', 'started', 'live', 'tracking', 'en_route', 'en route'], true) => 'badge badge-live',
        in_array($status, ['completed', 'complete', 'finished', 'done'], true) => 'badge badge-done',
        in_array($status, ['cancelled', 'canceled', 'failed', 'stopped'], true) => 'badge badge-cancelled',
        default => 'badge badge-neutral',
    };
}

function normalizeStatus(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'Unknown';
    }

    return ucwords(str_replace('_', ' ', strtolower($value)));
}

function chooseExistingTable(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (tableExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

$trackingTable = chooseExistingTable($pdo, [
    'walk_tracking_points',
    'tracking_points',
    'live_tracking',
    'gps_tracking',
    'walk_gps_points',
    'booking_tracking_points',
    'walk_tracking',
]);

$bookingsTable = chooseExistingTable($pdo, [
    'bookings',
    'walks',
]);

$workersTable = chooseExistingTable($pdo, [
    'workers',
    'walkers',
]);

$membersTable = chooseExistingTable($pdo, [
    'members',
]);

$petsTable = chooseExistingTable($pdo, [
    'pets',
    'dogs',
]);

$usersTable = chooseExistingTable($pdo, [
    'users',
]);

$liveJobs = [];
$trackingPoints = [];
$stats = [
    'live_jobs' => 0,
    'tracking_points' => 0,
    'active_workers' => 0,
    'last_ping' => '—',
];

$infoMessage = '';
$errorMessage = '';

if ($hasAdminAccess) {
    if ($bookingsTable !== null) {
        $bookingCols = getTableColumns($pdo, $bookingsTable);

        $bookingIdCol      = firstExistingColumn($bookingCols, ['id', 'booking_id', 'walk_id']);
        $serviceCol        = firstExistingColumn($bookingCols, ['service_type', 'service', 'booking_type', 'type']);
        $statusCol         = firstExistingColumn($bookingCols, ['status', 'booking_status', 'walk_status']);
        $workerIdCol       = firstExistingColumn($bookingCols, ['worker_id', 'walker_id', 'staff_id', 'employee_id']);
        $memberIdCol       = firstExistingColumn($bookingCols, ['member_id', 'client_id', 'user_id']);
        $petIdCol          = firstExistingColumn($bookingCols, ['pet_id', 'dog_id']);
        $scheduledStartCol = firstExistingColumn($bookingCols, ['scheduled_start', 'start_time', 'booking_date', 'scheduled_at', 'service_date']);
        $scheduledEndCol   = firstExistingColumn($bookingCols, ['scheduled_end', 'end_time', 'ends_at']);
        $startedAtCol      = firstExistingColumn($bookingCols, ['started_at', 'actual_start', 'walk_started_at', 'tracking_started_at']);
        $completedAtCol    = firstExistingColumn($bookingCols, ['completed_at', 'actual_end', 'walk_completed_at', 'tracking_completed_at']);
        $createdAtCol      = firstExistingColumn($bookingCols, ['created_at', 'created_on']);

        $whereParts = [];

        if ($statusCol !== null) {
            $whereParts[] = "LOWER(COALESCE(b." . quotedIdentifier($statusCol) . ", '')) IN ('active','in_progress','started','live','tracking','en_route')";
        }

        if ($startedAtCol !== null && $completedAtCol !== null) {
            $whereParts[] = "(b." . quotedIdentifier($startedAtCol) . " IS NOT NULL AND b." . quotedIdentifier($startedAtCol) . " != '' AND (b." . quotedIdentifier($completedAtCol) . " IS NULL OR b." . quotedIdentifier($completedAtCol) . " = ''))";
        } elseif ($startedAtCol !== null) {
            $whereParts[] = "(b." . quotedIdentifier($startedAtCol) . " IS NOT NULL AND b." . quotedIdentifier($startedAtCol) . " != '')";
        }

        $whereSql = !empty($whereParts) ? '(' . implode(' OR ', $whereParts) . ')' : '1 = 0';

        $memberJoin = '';
        $memberNameExpr = "'—'";

        if ($memberIdCol !== null && $membersTable !== null) {
            $memberCols = getTableColumns($pdo, $membersTable);
            $mIdCol = firstExistingColumn($memberCols, ['id', 'member_id', 'user_id']);
            $mNameCol = firstExistingColumn($memberCols, ['full_name', 'name', 'member_name']);
            $mFirstCol = firstExistingColumn($memberCols, ['first_name']);
            $mLastCol = firstExistingColumn($memberCols, ['last_name']);

            if ($mIdCol !== null) {
                $memberJoin = ' LEFT JOIN ' . quotedIdentifier($membersTable) . ' m ON b.' . quotedIdentifier($memberIdCol) . ' = m.' . quotedIdentifier($mIdCol) . ' ';
                if ($mNameCol !== null) {
                    $memberNameExpr = 'COALESCE(NULLIF(m.' . quotedIdentifier($mNameCol) . ", ''), '—')";
                } else {
                    $first = $mFirstCol !== null ? 'COALESCE(m.' . quotedIdentifier($mFirstCol) . ", '')" : "''";
                    $last  = $mLastCol !== null ? 'COALESCE(m.' . quotedIdentifier($mLastCol) . ", '')" : "''";
                    $memberNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        } elseif ($memberIdCol !== null && $usersTable !== null) {
            $userCols = getTableColumns($pdo, $usersTable);
            $uIdCol = firstExistingColumn($userCols, ['id', 'user_id']);
            $uNameCol = firstExistingColumn($userCols, ['name', 'full_name', 'username']);
            $uFirstCol = firstExistingColumn($userCols, ['first_name']);
            $uLastCol = firstExistingColumn($userCols, ['last_name']);

            if ($uIdCol !== null) {
                $memberJoin = ' LEFT JOIN ' . quotedIdentifier($usersTable) . ' m ON b.' . quotedIdentifier($memberIdCol) . ' = m.' . quotedIdentifier($uIdCol) . ' ';
                if ($uNameCol !== null) {
                    $memberNameExpr = 'COALESCE(NULLIF(m.' . quotedIdentifier($uNameCol) . ", ''), '—')";
                } else {
                    $first = $uFirstCol !== null ? 'COALESCE(m.' . quotedIdentifier($uFirstCol) . ", '')" : "''";
                    $last  = $uLastCol !== null ? 'COALESCE(m.' . quotedIdentifier($uLastCol) . ", '')" : "''";
                    $memberNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        }

        $workerJoin = '';
        $workerNameExpr = "'—'";

        if ($workerIdCol !== null && $workersTable !== null) {
            $workerCols = getTableColumns($pdo, $workersTable);
            $wIdCol = firstExistingColumn($workerCols, ['id', 'worker_id', 'user_id', 'walker_id']);
            $wNameCol = firstExistingColumn($workerCols, ['full_name', 'name', 'worker_name', 'walker_name']);
            $wFirstCol = firstExistingColumn($workerCols, ['first_name']);
            $wLastCol = firstExistingColumn($workerCols, ['last_name']);

            if ($wIdCol !== null) {
                $workerJoin = ' LEFT JOIN ' . quotedIdentifier($workersTable) . ' w ON b.' . quotedIdentifier($workerIdCol) . ' = w.' . quotedIdentifier($wIdCol) . ' ';
                if ($wNameCol !== null) {
                    $workerNameExpr = 'COALESCE(NULLIF(w.' . quotedIdentifier($wNameCol) . ", ''), '—')";
                } else {
                    $first = $wFirstCol !== null ? 'COALESCE(w.' . quotedIdentifier($wFirstCol) . ", '')" : "''";
                    $last  = $wLastCol !== null ? 'COALESCE(w.' . quotedIdentifier($wLastCol) . ", '')" : "''";
                    $workerNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        } elseif ($workerIdCol !== null && $usersTable !== null) {
            $userCols = getTableColumns($pdo, $usersTable);
            $uIdCol = firstExistingColumn($userCols, ['id', 'user_id']);
            $uNameCol = firstExistingColumn($userCols, ['name', 'full_name', 'username']);
            $uFirstCol = firstExistingColumn($userCols, ['first_name']);
            $uLastCol = firstExistingColumn($userCols, ['last_name']);

            if ($uIdCol !== null) {
                $workerJoin = ' LEFT JOIN ' . quotedIdentifier($usersTable) . ' w ON b.' . quotedIdentifier($workerIdCol) . ' = w.' . quotedIdentifier($uIdCol) . ' ';
                if ($uNameCol !== null) {
                    $workerNameExpr = 'COALESCE(NULLIF(w.' . quotedIdentifier($uNameCol) . ", ''), '—')";
                } else {
                    $first = $uFirstCol !== null ? 'COALESCE(w.' . quotedIdentifier($uFirstCol) . ", '')" : "''";
                    $last  = $uLastCol !== null ? 'COALESCE(w.' . quotedIdentifier($uLastCol) . ", '')" : "''";
                    $workerNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        }

        $petJoin = '';
        $petNameExpr = "'—'";

        if ($petIdCol !== null && $petsTable !== null) {
            $petCols = getTableColumns($pdo, $petsTable);
            $pIdCol = firstExistingColumn($petCols, ['id', 'pet_id', 'dog_id']);
            $pNameCol = firstExistingColumn($petCols, ['name', 'pet_name', 'dog_name']);

            if ($pIdCol !== null && $pNameCol !== null) {
                $petJoin = ' LEFT JOIN ' . quotedIdentifier($petsTable) . ' p ON b.' . quotedIdentifier($petIdCol) . ' = p.' . quotedIdentifier($pIdCol) . ' ';
                $petNameExpr = 'COALESCE(NULLIF(p.' . quotedIdentifier($pNameCol) . ", ''), '—')";
            }
        }

        $liveSql = "
            SELECT
                " . buildSelectFragment($bookingIdCol, 'booking_id', 'NULL', 'b') . ",
                " . buildSelectFragment($serviceCol, 'service_type', "'Service'", 'b') . ",
                " . buildSelectFragment($statusCol, 'status', "'active'", 'b') . ",
                " . buildSelectFragment($workerIdCol, 'worker_id', 'NULL', 'b') . ",
                " . buildSelectFragment($memberIdCol, 'member_id', 'NULL', 'b') . ",
                " . buildSelectFragment($petIdCol, 'pet_id', 'NULL', 'b') . ",
                " . buildSelectFragment($scheduledStartCol, 'scheduled_start', 'NULL', 'b') . ",
                " . buildSelectFragment($scheduledEndCol, 'scheduled_end', 'NULL', 'b') . ",
                " . buildSelectFragment($startedAtCol, 'started_at', 'NULL', 'b') . ",
                " . buildSelectFragment($completedAtCol, 'completed_at', 'NULL', 'b') . ",
                " . buildSelectFragment($createdAtCol, 'created_at', 'NULL', 'b') . ",
                {$memberNameExpr} AS " . quotedIdentifier('member_name') . ",
                {$workerNameExpr} AS " . quotedIdentifier('worker_name') . ",
                {$petNameExpr} AS " . quotedIdentifier('pet_name') . "
            FROM " . quotedIdentifier($bookingsTable) . " b
            {$memberJoin}
            {$workerJoin}
            {$petJoin}
            WHERE {$whereSql}
            ORDER BY
                CASE WHEN " . quotedIdentifier('started_at') . " IS NULL OR " . quotedIdentifier('started_at') . " = '' THEN 1 ELSE 0 END,
                " . quotedIdentifier('started_at') . " DESC,
                " . quotedIdentifier('scheduled_start') . " DESC,
                " . quotedIdentifier('created_at') . " DESC
            LIMIT 100
        ";

        $liveJobs = fetchAllSafe($pdo, $liveSql);
        $stats['live_jobs'] = count($liveJobs);

        $uniqueWorkers = [];
        foreach ($liveJobs as $job) {
            $id = trim((string) ($job['worker_id'] ?? ''));
            if ($id !== '') {
                $uniqueWorkers[$id] = true;
            }
        }
        $stats['active_workers'] = count($uniqueWorkers);
    }

    if ($trackingTable !== null) {
        $trackingCols = getTableColumns($pdo, $trackingTable);

        $tpIdCol        = firstExistingColumn($trackingCols, ['id', 'tracking_id', 'point_id']);
        $tpBookingIdCol = firstExistingColumn($trackingCols, ['booking_id', 'walk_id', 'job_id']);
        $tpWorkerIdCol  = firstExistingColumn($trackingCols, ['worker_id', 'walker_id', 'staff_id', 'employee_id']);
        $tpLatCol       = firstExistingColumn($trackingCols, ['latitude', 'lat']);
        $tpLngCol       = firstExistingColumn($trackingCols, ['longitude', 'lng', 'lon']);
        $tpAccCol       = firstExistingColumn($trackingCols, ['accuracy', 'gps_accuracy']);
        $tpSpeedCol     = firstExistingColumn($trackingCols, ['speed']);
        $tpHeadingCol   = firstExistingColumn($trackingCols, ['heading', 'bearing']);
        $tpCreatedAtCol = firstExistingColumn($trackingCols, ['created_at', 'recorded_at', 'tracked_at', 'timestamp']);

        $tpWorkerJoin = '';
        $tpWorkerNameExpr = "'—'";

        if ($tpWorkerIdCol !== null && $workersTable !== null) {
            $workerCols = getTableColumns($pdo, $workersTable);
            $wIdCol = firstExistingColumn($workerCols, ['id', 'worker_id', 'user_id', 'walker_id']);
            $wNameCol = firstExistingColumn($workerCols, ['full_name', 'name', 'worker_name', 'walker_name']);
            $wFirstCol = firstExistingColumn($workerCols, ['first_name']);
            $wLastCol = firstExistingColumn($workerCols, ['last_name']);

            if ($wIdCol !== null) {
                $tpWorkerJoin = ' LEFT JOIN ' . quotedIdentifier($workersTable) . ' w ON t.' . quotedIdentifier($tpWorkerIdCol) . ' = w.' . quotedIdentifier($wIdCol) . ' ';
                if ($wNameCol !== null) {
                    $tpWorkerNameExpr = 'COALESCE(NULLIF(w.' . quotedIdentifier($wNameCol) . ", ''), '—')";
                } else {
                    $first = $wFirstCol !== null ? 'COALESCE(w.' . quotedIdentifier($wFirstCol) . ", '')" : "''";
                    $last  = $wLastCol !== null ? 'COALESCE(w.' . quotedIdentifier($wLastCol) . ", '')" : "''";
                    $tpWorkerNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        } elseif ($tpWorkerIdCol !== null && $usersTable !== null) {
            $userCols = getTableColumns($pdo, $usersTable);
            $uIdCol = firstExistingColumn($userCols, ['id', 'user_id']);
            $uNameCol = firstExistingColumn($userCols, ['name', 'full_name', 'username']);
            $uFirstCol = firstExistingColumn($userCols, ['first_name']);
            $uLastCol = firstExistingColumn($userCols, ['last_name']);

            if ($uIdCol !== null) {
                $tpWorkerJoin = ' LEFT JOIN ' . quotedIdentifier($usersTable) . ' w ON t.' . quotedIdentifier($tpWorkerIdCol) . ' = w.' . quotedIdentifier($uIdCol) . ' ';
                if ($uNameCol !== null) {
                    $tpWorkerNameExpr = 'COALESCE(NULLIF(w.' . quotedIdentifier($uNameCol) . ", ''), '—')";
                } else {
                    $first = $uFirstCol !== null ? 'COALESCE(w.' . quotedIdentifier($uFirstCol) . ", '')" : "''";
                    $last  = $uLastCol !== null ? 'COALESCE(w.' . quotedIdentifier($uLastCol) . ", '')" : "''";
                    $tpWorkerNameExpr = "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
                }
            }
        }

        $trackingSql = "
            SELECT
                " . buildSelectFragment($tpIdCol, 'point_id', 'NULL', 't') . ",
                " . buildSelectFragment($tpBookingIdCol, 'booking_id', 'NULL', 't') . ",
                " . buildSelectFragment($tpWorkerIdCol, 'worker_id', 'NULL', 't') . ",
                " . buildSelectFragment($tpLatCol, 'latitude', 'NULL', 't') . ",
                " . buildSelectFragment($tpLngCol, 'longitude', 'NULL', 't') . ",
                " . buildSelectFragment($tpAccCol, 'accuracy', 'NULL', 't') . ",
                " . buildSelectFragment($tpSpeedCol, 'speed', 'NULL', 't') . ",
                " . buildSelectFragment($tpHeadingCol, 'heading', 'NULL', 't') . ",
                " . buildSelectFragment($tpCreatedAtCol, 'created_at', 'NULL', 't') . ",
                {$tpWorkerNameExpr} AS " . quotedIdentifier('worker_name') . "
            FROM " . quotedIdentifier($trackingTable) . " t
            {$tpWorkerJoin}
            ORDER BY " . quotedIdentifier('created_at') . " DESC
            LIMIT 200
        ";

        $trackingPoints = fetchAllSafe($pdo, $trackingSql);
        $stats['tracking_points'] = count($trackingPoints);

        if (!empty($trackingPoints[0]['created_at'])) {
            $stats['last_ping'] = formatDateTimeValue($trackingPoints[0]['created_at']);
        }
    } else {
        $infoMessage = 'No tracking points table was found yet. This page will automatically populate once tracking data is being stored.';
    }
}

$systemNotes = [];
$systemNotes[] = $hasAdminAccess ? 'Admin access detected' : 'Admin access NOT detected';
$systemNotes[] = 'Session role: ' . ($roleRaw !== '' ? $roleRaw : '—');
$systemNotes[] = 'Session user_id: ' . ($userId > 0 ? (string) $userId : '0');
$systemNotes[] = 'Session admin_id: ' . ($adminId > 0 ? (string) $adminId : '0');
$systemNotes[] = 'Session is_admin: ' . ($isAdmin ? 'true' : 'false');
$systemNotes[] = $bookingsTable !== null ? "Bookings table detected: {$bookingsTable}" : 'Bookings table not found';
$systemNotes[] = $trackingTable !== null ? "Tracking table detected: {$trackingTable}" : 'Tracking table not found';
$systemNotes[] = $workersTable !== null ? "Worker table detected: {$workersTable}" : 'Worker table not found';
$systemNotes[] = $petsTable !== null ? "Pet table detected: {$petsTable}" : 'Pet table not found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Live Tracking | Doggie Dorian’s</title>
    <meta name="description" content="Doggie Dorian’s admin live tracking dashboard">
    <style>
        :root {
            --bg: #07111f;
            --panel: rgba(10, 20, 35, 0.88);
            --line: rgba(255, 255, 255, 0.10);
            --text: #f4f7fb;
            --muted: #9eb0c7;
            --gold: #d6b36a;
            --green: #2cc58a;
            --red: #ef6b6b;
            --blue: #67a8ff;
            --shadow: 0 22px 60px rgba(0, 0, 0, 0.35);
            --radius-xl: 24px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background:
                radial-gradient(circle at top, rgba(214, 179, 106, 0.12), transparent 24%),
                linear-gradient(180deg, #08111d 0%, #091625 48%, #07111f 100%);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .shell {
            max-width: 1480px;
            margin: 0 auto;
            padding: 28px 20px 60px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
        }

        .title {
            margin: 0;
            font-size: clamp(28px, 4vw, 42px);
            line-height: 1.05;
            letter-spacing: -0.03em;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            transition: 0.2s ease;
            backdrop-filter: blur(10px);
        }

        .btn:hover {
            transform: translateY(-1px);
            border-color: rgba(214, 179, 106, 0.4);
            background: rgba(214, 179, 106, 0.08);
        }

        .btn-primary {
            border-color: rgba(214, 179, 106, 0.38);
            background: linear-gradient(180deg, rgba(214, 179, 106, 0.22), rgba(214, 179, 106, 0.10));
            color: #fff7e8;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .stat-card,
        .panel {
            background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
        }

        .stat-card {
            padding: 18px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: clamp(24px, 3.4vw, 34px);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
        }

        .stat-meta {
            font-size: 13px;
            color: var(--muted);
        }

        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 14px;
            line-height: 1.5;
            border: 1px solid var(--line);
            margin-bottom: 22px;
        }

        .alert-info {
            background: rgba(103, 168, 255, 0.08);
            color: #dfeeff;
            border-color: rgba(103, 168, 255, 0.18);
        }

        .alert-error {
            background: rgba(239, 107, 107, 0.10);
            color: #ffe0e0;
            border-color: rgba(239, 107, 107, 0.22);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 18px;
            align-items: start;
        }

        .panel {
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        th, td {
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            vertical-align: top;
        }

        th {
            background: rgba(12, 26, 43, 0.96);
            color: #dce7f5;
            font-size: 12px;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            font-weight: 800;
        }

        td {
            font-size: 14px;
            color: #edf3fa;
        }

        tbody tr:hover {
            background: rgba(255,255,255,0.03);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 11px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            white-space: nowrap;
        }

        .badge::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            display: inline-block;
        }

        .badge-live {
            background: rgba(44, 197, 138, 0.12);
            color: #bff3da;
            border: 1px solid rgba(44, 197, 138, 0.24);
        }
        .badge-live::before { background: #2cc58a; }

        .badge-done {
            background: rgba(103, 168, 255, 0.10);
            color: #d9eaff;
            border: 1px solid rgba(103, 168, 255, 0.22);
        }
        .badge-done::before { background: #67a8ff; }

        .badge-cancelled {
            background: rgba(239, 107, 107, 0.10);
            color: #ffd9d9;
            border: 1px solid rgba(239, 107, 107, 0.22);
        }
        .badge-cancelled::before { background: #ef6b6b; }

        .badge-neutral {
            background: rgba(255, 255, 255, 0.06);
            color: #edf3fa;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .badge-neutral::before { background: #c7d2df; }

        .cell-title {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .cell-sub {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .empty-state {
            padding: 36px 22px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--text);
            font-size: 18px;
        }

        .notes-list {
            display: grid;
            gap: 10px;
            padding: 18px 20px 20px;
        }

        .note-item {
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
            border-radius: 14px;
            padding: 12px 14px;
            color: var(--muted);
            font-size: 14px;
        }

        .access-box {
            padding: 28px;
        }

        .access-title {
            margin: 0 0 10px;
            font-size: 24px;
            font-weight: 800;
        }

        .access-text {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
            font-size: 15px;
        }

        @media (max-width: 1180px) {
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            .shell { padding: 20px 14px 42px; }
            .stats-grid { grid-template-columns: 1fr; }
            table { min-width: 760px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="topbar">
            <div class="brand">
                <div class="eyebrow">Doggie Dorian’s Admin</div>
                <h1 class="title">Live Tracking</h1>
                <p class="subtitle">Monitor active jobs, incoming tracking points, and system detection.</p>
            </div>

            <div class="top-actions">
                <a class="btn" href="admin-dashboard.php">Back to Dashboard</a>
                <a class="btn" href="admin-revenue.php">Revenue</a>
                <a class="btn" href="admin-bookings.php">Bookings</a>
                <a class="btn btn-primary" href="admin-live-tracking.php">Refresh</a>
            </div>
        </div>

        <?php if (!$hasAdminAccess): ?>
            <div class="panel">
                <div class="access-box">
                    <h2 class="access-title">Admin access not detected</h2>
                    <p class="access-text">
                        This file loaded successfully, which means the redirect is no longer coming from this page.
                        Your current session does not look like an admin session, so the issue is now confirmed to be in the admin login/session setup rather than this file’s layout.
                    </p>
                </div>
            </div>
        <?php else: ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Live Jobs</div>
                    <div class="stat-value"><?php echo h($stats['live_jobs']); ?></div>
                    <div class="stat-meta">Current active jobs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Tracking Points</div>
                    <div class="stat-value"><?php echo h($stats['tracking_points']); ?></div>
                    <div class="stat-meta">Recent points loaded</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Active Workers</div>
                    <div class="stat-value"><?php echo h($stats['active_workers']); ?></div>
                    <div class="stat-meta">Workers tied to live jobs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Last Ping</div>
                    <div class="stat-value" style="font-size:22px;"><?php echo h($stats['last_ping']); ?></div>
                    <div class="stat-meta">Most recent tracking timestamp</div>
                </div>
            </div>

            <?php if ($infoMessage !== ''): ?>
                <div class="alert alert-info"><?php echo h($infoMessage); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error"><?php echo h($errorMessage); ?></div>
            <?php endif; ?>

            <div class="content-grid">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title">Live Jobs Table</h2>
                            <p class="panel-subtitle">Active services currently in progress</p>
                        </div>
                    </div>

                    <?php if (empty($liveJobs)): ?>
                        <div class="empty-state">
                            <h3>No live jobs right now</h3>
                            <p>The system did not find any active bookings based on the current schema.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Booking</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Worker</th>
                                        <th>Client</th>
                                        <th>Pet</th>
                                        <th>Started</th>
                                        <th>Scheduled</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($liveJobs as $job): ?>
                                        <tr>
                                            <td>
                                                <div class="cell-title">#<?php echo h($job['booking_id'] ?? '—'); ?></div>
                                                <div class="cell-sub mono">Created: <?php echo h(formatDateTimeValue($job['created_at'] ?? '')); ?></div>
                                            </td>
                                            <td><?php echo h($job['service_type'] ?: 'Service'); ?></td>
                                            <td>
                                                <span class="<?php echo h(badgeClass((string) ($job['status'] ?? ''))); ?>">
                                                    <?php echo h(normalizeStatus($job['status'] ?? '')); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="cell-title"><?php echo h($job['worker_name'] ?? '—'); ?></div>
                                                <div class="cell-sub">Worker ID: <?php echo h($job['worker_id'] ?? '—'); ?></div>
                                            </td>
                                            <td>
                                                <div class="cell-title"><?php echo h($job['member_name'] ?? '—'); ?></div>
                                                <div class="cell-sub">Client ID: <?php echo h($job['member_id'] ?? '—'); ?></div>
                                            </td>
                                            <td>
                                                <div class="cell-title"><?php echo h($job['pet_name'] ?? '—'); ?></div>
                                                <div class="cell-sub">Pet ID: <?php echo h($job['pet_id'] ?? '—'); ?></div>
                                            </td>
                                            <td>
                                                <div class="cell-title"><?php echo h(formatDateTimeValue($job['started_at'] ?? '')); ?></div>
                                                <div class="cell-sub">Completed: <?php echo h(formatDateTimeValue($job['completed_at'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <div class="cell-title"><?php echo h(formatDateTimeValue($job['scheduled_start'] ?? '')); ?></div>
                                                <div class="cell-sub">Ends: <?php echo h(formatDateTimeValue($job['scheduled_end'] ?? '')); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title">Tracking Points Table</h2>
                            <p class="panel-subtitle">Recent GPS/tracking rows detected</p>
                        </div>
                    </div>

                    <?php if ($trackingTable === null): ?>
                        <div class="empty-state">
                            <h3>Tracking table not found</h3>
                            <p>This page is ready and will populate automatically when tracking data starts being stored.</p>
                        </div>
                    <?php elseif (empty($trackingPoints)): ?>
                        <div class="empty-state">
                            <h3>No tracking points yet</h3>
                            <p>The tracking table exists, but no recent rows were returned.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Point</th>
                                        <th>Booking</th>
                                        <th>Worker</th>
                                        <th>Latitude</th>
                                        <th>Longitude</th>
                                        <th>Accuracy</th>
                                        <th>Speed</th>
                                        <th>Heading</th>
                                        <th>Recorded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trackingPoints as $point): ?>
                                        <tr>
                                            <td class="mono"><?php echo h($point['point_id'] ?? '—'); ?></td>
                                            <td>#<?php echo h($point['booking_id'] ?? '—'); ?></td>
                                            <td>
                                                <div class="cell-title"><?php echo h($point['worker_name'] ?? '—'); ?></div>
                                                <div class="cell-sub">Worker ID: <?php echo h($point['worker_id'] ?? '—'); ?></div>
                                            </td>
                                            <td class="mono"><?php echo h(formatNumberValue($point['latitude'] ?? null)); ?></td>
                                            <td class="mono"><?php echo h(formatNumberValue($point['longitude'] ?? null)); ?></td>
                                            <td><?php echo h(formatNumberValue($point['accuracy'] ?? null, 2)); ?></td>
                                            <td><?php echo h(formatNumberValue($point['speed'] ?? null, 2)); ?></td>
                                            <td><?php echo h(formatNumberValue($point['heading'] ?? null, 2)); ?></td>
                                            <td><?php echo h(formatDateTimeValue($point['created_at'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <section class="panel" style="margin-top:18px;">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">System Detection</h2>
                    <p class="panel-subtitle">Session + schema notes for this screen</p>
                </div>
            </div>

            <div class="notes-list">
                <?php foreach ($systemNotes as $note): ?>
                    <div class="note-item"><?php echo h($note); ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</body>
</html>