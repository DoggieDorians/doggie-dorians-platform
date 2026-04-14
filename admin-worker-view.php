<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminWorkerViewH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminWorkerViewRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminWorkerViewQuoteIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function ddAdminWorkerViewTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminWorkerViewGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminWorkerViewTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminWorkerViewQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    }
}

function ddAdminWorkerViewFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminWorkerViewValueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminWorkerViewBuildName(array $row): string
{
    $full = trim((string) ddAdminWorkerViewValueFromRow($row, array(
        'full_name',
        'name',
        'display_name',
        'worker_name',
        'walker_name',
        'username',
        'email',
    ), ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown';
}

function ddAdminWorkerViewHumanStatus(array $row): string
{
    foreach (array('status', 'account_status', 'worker_status') as $column) {
        if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
            return ucwords(str_replace(array('_', '-'), ' ', strtolower((string) $row[$column])));
        }
    }

    foreach (array('is_active', 'active', 'enabled') as $column) {
        if (array_key_exists($column, $row)) {
            return ((int) $row[$column] === 1) ? 'Active' : 'Disabled';
        }
    }

    if (array_key_exists('disabled', $row)) {
        return ((int) $row['disabled'] === 1) ? 'Disabled' : 'Active';
    }

    return 'Unknown';
}

function ddAdminWorkerViewWorkerIsActive(array $row): bool
{
    foreach (array('is_active', 'active', 'enabled') as $column) {
        if (array_key_exists($column, $row)) {
            return (int) $row[$column] === 1;
        }
    }

    if (array_key_exists('disabled', $row)) {
        return (int) $row['disabled'] !== 1;
    }

    foreach (array('status', 'account_status', 'worker_status') as $column) {
        if (!isset($row[$column])) {
            continue;
        }

        $value = strtolower(trim((string) $row[$column]));
        if ($value === '') {
            continue;
        }

        if (in_array($value, array('disabled', 'inactive', 'blocked', 'suspended'), true)) {
            return false;
        }

        if (in_array($value, array('active', 'enabled', 'approved'), true)) {
            return true;
        }
    }

    return true;
}

function ddAdminWorkerViewFormatDateTime($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('M j, Y g:i A', $timestamp);
}

function ddAdminWorkerViewBookingTitle(array $row): string
{
    $service = trim((string) ddAdminWorkerViewValueFromRow($row, array(
        'service_name',
        'service_type',
        'service',
        'booking_type',
        'type',
    ), 'Service'));

    $service = $service !== '' ? ucwords(str_replace(array('_', '-'), ' ', $service)) : 'Service';

    $pet = trim((string) ddAdminWorkerViewValueFromRow($row, array(
        'pet_name',
        'dog_name',
        'animal_name',
    ), ''));

    return $pet !== '' ? $service . ' • ' . $pet : $service;
}

function ddAdminWorkerViewBookingWhen(array $row): string
{
    $date = trim((string) ddAdminWorkerViewValueFromRow($row, array(
        'service_date',
        'booking_date',
        'scheduled_date',
        'walk_date',
        'appointment_date',
        'date',
        'start_date',
        'scheduled_for',
        'created_at',
    ), ''));

    $time = trim((string) ddAdminWorkerViewValueFromRow($row, array(
        'service_time',
        'booking_time',
        'start_time',
        'scheduled_time',
        'time',
    ), ''));

    if ($date === '') {
        return '—';
    }

    $timestamp = strtotime($date);
    $formatted = $timestamp !== false ? date('M j, Y', $timestamp) : $date;

    return $time !== '' ? $formatted . ' • ' . $time : $formatted;
}

function ddAdminWorkerViewNormalizeJobStatus(string $status): string
{
    $normalized = strtolower(trim($status));
    $normalized = str_replace(array(' ', '-'), '_', $normalized);

    return match ($normalized) {
        'done', 'finished', 'complete' => 'completed',
        'inprogress', 'active', 'started', 'walking', 'live', 'en_route', 'underway' => 'in_progress',
        '' => 'assigned',
        default => $normalized,
    };
}

function ddAdminWorkerViewClassifyJob(array $job): string
{
    $status = ddAdminWorkerViewNormalizeJobStatus((string) ddAdminWorkerViewValueFromRow($job, array(
        'status',
        'booking_status',
        'walk_status',
        'job_status',
    ), ''));

    $completedAt = trim((string) ddAdminWorkerViewValueFromRow($job, array(
        'completed_at',
        'ended_at',
        'finished_at',
        'actual_end_time',
    ), ''));

    $startedAt = trim((string) ddAdminWorkerViewValueFromRow($job, array(
        'started_at',
        'actual_start_time',
    ), ''));

    $trackingStatus = strtolower(trim((string) ddAdminWorkerViewValueFromRow($job, array(
        'tracking_status',
    ), '')));

    if ($completedAt !== '' || $status === 'completed') {
        return 'completed';
    }

    if ($startedAt !== '' || $trackingStatus === 'live' || $status === 'in_progress') {
        return 'live';
    }

    return 'assigned';
}

function ddAdminWorkerViewSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminWorkerViewSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminWorkerViewLoadWorkerFromTable(PDO $pdo, string $table, int $workerId): ?array
{
    if (!ddAdminWorkerViewTableExists($pdo, $table)) {
        return null;
    }

    $columns = ddAdminWorkerViewGetColumns($pdo, $table);
    if ($columns === array()) {
        return null;
    }

    $idColumn = ddAdminWorkerViewFirstExistingColumn($columns, array('id', 'user_id', 'worker_id', 'walker_id'));
    if ($idColumn === null) {
        return null;
    }

    $sql = 'SELECT * FROM ' . ddAdminWorkerViewQuoteIdentifier($table)
        . ' WHERE ' . ddAdminWorkerViewQuoteIdentifier($idColumn) . ' = :id LIMIT 1';

    $row = ddAdminWorkerViewSafeFetchOne($pdo, $sql, array(':id' => $workerId));

    if (!$row) {
        return null;
    }

    if ($table === 'users') {
        $roleColumn = ddAdminWorkerViewFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type'));
        if ($roleColumn !== null) {
            $role = strtolower(trim((string) ($row[$roleColumn] ?? '')));
            if (!in_array($role, array('walker', 'worker', 'staff', 'employee'), true)) {
                return null;
            }
        }
    }

    $row['_source_table'] = $table;
    return $row;
}

function ddAdminWorkerViewLoadWorker(PDO $pdo, int $workerId, string $preferredSource = ''): ?array
{
    $validSources = array('workers', 'walkers', 'users');

    if ($preferredSource !== '' && in_array($preferredSource, $validSources, true)) {
        $worker = ddAdminWorkerViewLoadWorkerFromTable($pdo, $preferredSource, $workerId);
        if ($worker) {
            return $worker;
        }
    }

    foreach ($validSources as $table) {
        if ($table === $preferredSource) {
            continue;
        }

        $worker = ddAdminWorkerViewLoadWorkerFromTable($pdo, $table, $workerId);
        if ($worker) {
            return $worker;
        }
    }

    return null;
}

function ddAdminWorkerViewBuildLookup(PDO $pdo, array $sourceTables, array $idCandidates, array $nameCandidates, array $extraMap = array()): array
{
    $lookup = array();

    foreach ($sourceTables as $table) {
        if (!ddAdminWorkerViewTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminWorkerViewGetColumns($pdo, $table);
        if ($columns === array()) {
            continue;
        }

        $idColumn = ddAdminWorkerViewFirstExistingColumn($columns, $idCandidates);
        if ($idColumn === null) {
            continue;
        }

        $orderColumn = ddAdminWorkerViewFirstExistingColumn($columns, array('created_at', 'joined_at', 'date_created', $idColumn)) ?? $idColumn;
        $rows = ddAdminWorkerViewSafeFetchAll(
            $pdo,
            'SELECT * FROM ' . ddAdminWorkerViewQuoteIdentifier($table) . ' ORDER BY ' . ddAdminWorkerViewQuoteIdentifier($orderColumn) . ' DESC'
        );

        foreach ($rows as $row) {
            $id = (int) ($row[$idColumn] ?? 0);
            if ($id <= 0 || isset($lookup[$id])) {
                continue;
            }

            $label = '';
            foreach ($nameCandidates as $candidate) {
                if (isset($row[$candidate]) && trim((string) $row[$candidate]) !== '') {
                    $label = trim((string) $row[$candidate]);
                    break;
                }
            }

            if ($label === '') {
                $label = ddAdminWorkerViewBuildName($row);
            }

            $extra = array();
            foreach ($extraMap as $key => $candidateList) {
                $extra[$key] = ddAdminWorkerViewValueFromRow($row, $candidateList, '');
            }

            $lookup[$id] = array(
                'label' => $label !== '' ? $label : 'Unknown',
                'extra' => $extra,
            );
        }
    }

    return $lookup;
}

$workerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$workerSource = isset($_GET['source']) ? strtolower(trim((string) $_GET['source'])) : '';

if ($workerId <= 0) {
    ddAdminWorkerViewRedirect('admin-walker-management.php');
}

$worker = ddAdminWorkerViewLoadWorker($pdo, $workerId, $workerSource);

if (!$worker) {
    ddAdminWorkerViewRedirect('admin-walker-management.php');
}

$resolvedWorkerSource = (string) ($worker['_source_table'] ?? $workerSource ?: 'unknown');
$sourceParam = urlencode($resolvedWorkerSource);

$workerName = ddAdminWorkerViewBuildName($worker);
$workerEmail = (string) ddAdminWorkerViewValueFromRow($worker, array('email'), '—');
$workerPhone = (string) ddAdminWorkerViewValueFromRow($worker, array('phone', 'phone_number', 'mobile'), '—');
$workerRole = (string) ddAdminWorkerViewValueFromRow($worker, array('role', 'user_role', 'account_role', 'account_type'), 'Worker');
$workerStatus = ddAdminWorkerViewHumanStatus($worker);
$workerAvailability = (string) ddAdminWorkerViewValueFromRow($worker, array('availability', 'worker_availability', 'schedule'), '');
$workerBio = (string) ddAdminWorkerViewValueFromRow($worker, array('bio', 'about', 'about_me', 'notes', 'worker_bio'), '');
$workerCreated = (string) ddAdminWorkerViewValueFromRow($worker, array('created_at', 'joined_at', 'date_created'), '');
$workerIsActive = ddAdminWorkerViewWorkerIsActive($worker);

$clientLookup = ddAdminWorkerViewBuildLookup(
    $pdo,
    array('users', 'members', 'client_profiles'),
    array('id', 'user_id', 'member_id', 'client_id'),
    array('full_name', 'name', 'client_name', 'member_name', 'username', 'email'),
    array('email' => array('email'))
);

$petLookup = ddAdminWorkerViewBuildLookup(
    $pdo,
    array('dogs', 'pets'),
    array('id', 'dog_id', 'pet_id'),
    array('dog_name', 'pet_name', 'name'),
    array(
        'breed' => array('breed'),
        'size' => array('size', 'dog_size', 'pet_size'),
    )
);

$allJobs = array();
$assignedJobs = array();
$liveJobs = array();
$completedJobs = array();

if (ddAdminWorkerViewTableExists($pdo, 'bookings')) {
    $bookingColumns = ddAdminWorkerViewGetColumns($pdo, 'bookings');

    $bookingIdColumn = ddAdminWorkerViewFirstExistingColumn($bookingColumns, array('id', 'booking_id'));
    $bookingWorkerColumn = ddAdminWorkerViewFirstExistingColumn($bookingColumns, array(
        'walker_id',
        'worker_id',
        'staff_id',
        'employee_id',
        'assigned_walker_id',
        'assigned_worker_id',
        'assigned_user_id',
        'assigned_to_user_id',
        'assigned_to',
    ));
    $bookingOrderColumn = ddAdminWorkerViewFirstExistingColumn($bookingColumns, array(
        'service_date',
        'booking_date',
        'scheduled_date',
        'walk_date',
        'appointment_date',
        'start_date',
        'date',
        'created_at',
        'id',
    )) ?? 'id';

    if ($bookingIdColumn !== null && $bookingWorkerColumn !== null) {
        $rows = ddAdminWorkerViewSafeFetchAll(
            $pdo,
            'SELECT * FROM ' . ddAdminWorkerViewQuoteIdentifier('bookings') . '
             WHERE ' . ddAdminWorkerViewQuoteIdentifier($bookingWorkerColumn) . ' = :worker_id
             ORDER BY ' . ddAdminWorkerViewQuoteIdentifier($bookingOrderColumn) . ' DESC, ' . ddAdminWorkerViewQuoteIdentifier($bookingIdColumn) . ' DESC',
            array(':worker_id' => $workerId)
        );

        foreach ($rows as $row) {
            $clientName = trim((string) ddAdminWorkerViewValueFromRow($row, array(
                'customer_name',
                'client_name',
                'member_name',
                'owner_name',
                'user_name',
                'full_name',
            ), ''));

            $clientId = (int) ddAdminWorkerViewValueFromRow($row, array('user_id', 'member_id', 'client_id', 'owner_id'), 0);
            if ($clientName === '' && $clientId > 0 && isset($clientLookup[$clientId])) {
                $clientName = (string) ($clientLookup[$clientId]['label'] ?? '');
            }

            $petName = trim((string) ddAdminWorkerViewValueFromRow($row, array('pet_name', 'dog_name', 'animal_name'), ''));
            $petId = (int) ddAdminWorkerViewValueFromRow($row, array('pet_id', 'dog_id'), 0);
            $petBreed = '';
            $petSize = '';

            if ($petName === '' && $petId > 0 && isset($petLookup[$petId])) {
                $petName = (string) ($petLookup[$petId]['label'] ?? '');
                $petBreed = (string) ($petLookup[$petId]['extra']['breed'] ?? '');
                $petSize = (string) ($petLookup[$petId]['extra']['size'] ?? '');
            }

            $normalized = $row;
            $normalized['_resolved_client_name'] = $clientName !== '' ? $clientName : '—';
            $normalized['_resolved_pet_name'] = $petName !== '' ? $petName : '—';
            $normalized['_resolved_pet_breed'] = $petBreed;
            $normalized['_resolved_pet_size'] = $petSize;
            $normalized['_resolved_booking_id'] = (int) ($row[$bookingIdColumn] ?? 0);

            $allJobs[] = $normalized;
        }
    }
}

foreach ($allJobs as $job) {
    $type = ddAdminWorkerViewClassifyJob($job);

    if ($type === 'live') {
        $liveJobs[] = $job;
    } elseif ($type === 'completed') {
        $completedJobs[] = $job;
    } else {
        $assignedJobs[] = $job;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker View | Doggie Dorian’s</title>
    <meta name="description" content="Admin worker detail page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --red: #ef4444;
            --blue: #38bdf8;
            --amber: #f59e0b;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1400px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 22%),
                linear-gradient(180deg, #07101d 0%, #0b1220 50%, #0f172a 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.94rem;
        }

        .hero,
        .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
            max-width: 860px;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.05);
        }

        .btn-gold {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #0f172a;
            border-color: transparent;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
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
            letter-spacing: 0.12em;
            font-size: 0.73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.55rem;
            font-weight: 900;
        }

        .grid {
            display: grid;
            grid-template-columns: 0.95fr 1.05fr;
            gap: 20px;
            margin-top: 22px;
        }

        .panel-title {
            font-size: 1.08rem;
            font-weight: 900;
            margin-bottom: 14px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .info-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .info-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 0.72rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .info-value {
            font-weight: 800;
            line-height: 1.5;
            word-break: break-word;
        }

        .bio-box {
            margin-top: 12px;
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.82);
            line-height: 1.65;
            white-space: pre-wrap;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .item {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-title {
            font-weight: 900;
            margin-bottom: 6px;
        }

        .item-text {
            color: rgba(244,241,234,0.68);
            line-height: 1.55;
            font-size: 0.92rem;
        }

        .item-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .item-action {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: 0.85rem;
            font-weight: 800;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 900;
            margin-top: 8px;
        }

        .badge-live {
            background: rgba(56, 189, 248, 0.16);
            color: #d0e4ff;
        }

        .badge-completed {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
        }

        .badge-assigned {
            background: rgba(245, 158, 11, 0.16);
            color: #fde68a;
        }

        .badge-disabled {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: rgba(244,241,234,0.68);
        }

        @media (max-width: 1100px) {
            .grid,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.65rem;
            }

            .page {
                padding: 20px 12px 60px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-worker-view.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Worker View</a>
                <a class="top-link" href="admin-worker-jobs.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Worker Jobs</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Detail</div>
            <h1><?php echo ddAdminWorkerViewH($workerName); ?></h1>
            <div class="sub">
                Full admin-side view of this account, including profile info, status, workload, and related bookings.
            </div>

            <div class="hero-actions">
                <a class="btn btn-gold" href="admin-edit-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Edit Worker</a>
                <a class="btn" href="admin-walker-management.php">Back to Workers</a>
                <a class="btn" href="admin-worker-jobs.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">View All Jobs</a>
                <a class="btn" href="admin-assign-walker.php?worker_id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Assign Booking</a>
                <?php if ($workerIsActive): ?>
                    <a class="btn" href="admin-disable-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Disable</a>
                <?php else: ?>
                    <a class="btn" href="admin-enable-worker.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">Enable</a>
                <?php endif; ?>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Assigned Jobs</div>
                    <div class="stat-value"><?php echo count($assignedJobs); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Live Jobs</div>
                    <div class="stat-value"><?php echo count($liveJobs); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Completed Jobs</div>
                    <div class="stat-value"><?php echo count($completedJobs); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Total Jobs</div>
                    <div class="stat-value"><?php echo count($allJobs); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Source</div>
                    <div class="stat-value"><?php echo ddAdminWorkerViewH($resolvedWorkerSource); ?></div>
                </div>
            </div>
        </section>

        <section class="grid">
            <div class="panel">
                <div class="panel-title">Worker Profile</div>

                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-label">Worker ID</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH((string) $workerId); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Role</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH($workerRole !== '' ? ucwords(str_replace('_', ' ', strtolower($workerRole))) : '—'); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <?php echo ddAdminWorkerViewH($workerStatus); ?>
                            <?php if (!$workerIsActive): ?>
                                <span class="badge badge-disabled">Disabled</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Joined</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH($workerCreated !== '' ? ddAdminWorkerViewFormatDateTime($workerCreated) : '—'); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH($workerEmail); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH($workerPhone); ?></div>
                    </div>

                    <div class="info-box" style="grid-column: 1 / -1;">
                        <div class="info-label">Availability</div>
                        <div class="info-value"><?php echo ddAdminWorkerViewH($workerAvailability !== '' ? $workerAvailability : '—'); ?></div>
                    </div>
                </div>

                <div class="panel-title" style="margin-top:18px;">Bio / Notes</div>
                <div class="bio-box"><?php echo ddAdminWorkerViewH($workerBio !== '' ? $workerBio : 'No bio or notes available.'); ?></div>
            </div>

            <div class="panel">
                <div class="panel-title">Recent Jobs</div>

                <?php if ($allJobs === array()): ?>
                    <div class="empty">
                        No jobs found for this account yet.
                    </div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach (array_slice($allJobs, 0, 8) as $job): ?>
                            <?php
                            $jobId = (int) ($job['_resolved_booking_id'] ?? ddAdminWorkerViewValueFromRow($job, array('id', 'booking_id'), 0));
                            $type = ddAdminWorkerViewClassifyJob($job);
                            $badgeClass = $type === 'live' ? 'badge-live' : ($type === 'completed' ? 'badge-completed' : 'badge-assigned');
                            $badgeText = ucfirst($type);
                            $resolvedClient = (string) ($job['_resolved_client_name'] ?? '—');
                            $resolvedPet = (string) ($job['_resolved_pet_name'] ?? '—');
                            $resolvedBreed = (string) ($job['_resolved_pet_breed'] ?? '');
                            $resolvedSize = (string) ($job['_resolved_pet_size'] ?? '');
                            ?>
                            <div class="item">
                                <div class="item-title">
                                    #<?php echo ddAdminWorkerViewH($jobId > 0 ? (string) $jobId : '—'); ?> · <?php echo ddAdminWorkerViewH(ddAdminWorkerViewBookingTitle($job)); ?>
                                </div>
                                <div class="item-text">
                                    Customer: <?php echo ddAdminWorkerViewH($resolvedClient); ?><br>
                                    Pet: <?php echo ddAdminWorkerViewH($resolvedPet); ?>
                                    <?php if ($resolvedBreed !== '' || $resolvedSize !== ''): ?>
                                        (<?php echo ddAdminWorkerViewH(trim($resolvedBreed . ($resolvedBreed !== '' && $resolvedSize !== '' ? ' • ' : '') . $resolvedSize)); ?>)
                                    <?php endif; ?>
                                    <br>
                                    When: <?php echo ddAdminWorkerViewH(ddAdminWorkerViewBookingWhen($job)); ?>
                                </div>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo ddAdminWorkerViewH($badgeText); ?></span>

                                <div class="item-actions">
                                    <?php if ($jobId > 0): ?>
                                        <a class="item-action" href="admin-edit-booking.php?id=<?php echo $jobId; ?>">Open Booking</a>
                                    <?php endif; ?>
                                    <a class="item-action" href="admin-worker-jobs.php?id=<?php echo $workerId; ?>&source=<?php echo $sourceParam; ?>">View All Jobs</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>