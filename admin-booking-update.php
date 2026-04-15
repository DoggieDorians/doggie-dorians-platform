<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminBookingUpdateH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminBookingUpdateRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminBookingUpdateQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminBookingUpdateTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminBookingUpdateGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminBookingUpdateTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminBookingUpdateQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!empty($column['name'])) {
                $columns[] = (string) $column['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminBookingUpdateFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminBookingUpdateSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminBookingUpdateValueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminBookingUpdateDecodeJsonIfPossible($value): ?array
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $firstChar = substr($value, 0, 1);
    if ($firstChar !== '{' && $firstChar !== '[') {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function ddAdminBookingUpdateCollectJsonSourcesFromRow(array $row): array
{
    $sources = array();

    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $decoded = ddAdminBookingUpdateDecodeJsonIfPossible($value);
        if (is_array($decoded)) {
            $sources[] = $decoded;
        }
    }

    return $sources;
}

function ddAdminBookingUpdateExtractNestedScalar(array $data, array $paths): string
{
    foreach ($paths as $path) {
        $parts = explode('.', $path);
        $current = $data;
        $found = true;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                $found = false;
                break;
            }
        }

        if ($found && is_scalar($current)) {
            $value = trim((string) $current);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function ddAdminBookingUpdateBuildNameFromParts(array $row): string
{
    $first = trim((string) ddAdminBookingUpdateValueFromRow(
        $row,
        array('first_name', 'firstname', 'client_first_name', 'owner_first_name'),
        ''
    ));
    $last = trim((string) ddAdminBookingUpdateValueFromRow(
        $row,
        array('last_name', 'lastname', 'client_last_name', 'owner_last_name'),
        ''
    ));

    $full = trim($first . ' ' . $last);
    return $full !== '' ? $full : '';
}

function ddAdminBookingUpdateResolveClientName(array $row, array $jsonSources): string
{
    $name = trim((string) ddAdminBookingUpdateValueFromRow(
        $row,
        array('client_name', 'owner_name', 'full_name', 'name', 'customer_name', 'customer'),
        ''
    ));

    if ($name === '') {
        $name = ddAdminBookingUpdateBuildNameFromParts($row);
    }

    if ($name === '') {
        foreach ($jsonSources as $json) {
            $name = ddAdminBookingUpdateExtractNestedScalar($json, array(
                'client_name',
                'owner_name',
                'full_name',
                'name',
                'customer_name',
                'client.full_name',
                'client.name',
                'owner.full_name',
                'owner.name',
                'customer.full_name',
                'customer.name',
            ));
            if ($name !== '') {
                break;
            }
        }
    }

    return $name !== '' ? $name : 'Public Client';
}

function ddAdminBookingUpdateResolvePetName(array $row, array $jsonSources): string
{
    $petName = trim((string) ddAdminBookingUpdateValueFromRow(
        $row,
        array('pet_name', 'dog_name', 'pet', 'dog'),
        ''
    ));

    if ($petName === '') {
        foreach ($jsonSources as $json) {
            $petName = ddAdminBookingUpdateExtractNestedScalar($json, array(
                'pet_name',
                'dog_name',
                'pet',
                'dog',
                'pet.name',
                'dog.name',
            ));
            if ($petName !== '') {
                break;
            }
        }
    }

    return $petName;
}

function ddAdminBookingUpdateCsrfToken(): string
{
    if (empty($_SESSION['admin_booking_update_csrf']) || !is_string($_SESSION['admin_booking_update_csrf'])) {
        $_SESSION['admin_booking_update_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_booking_update_csrf'];
}

function ddAdminBookingUpdateValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_booking_update_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminBookingUpdateFlash(string $type, string $message): void
{
    $_SESSION['admin_booking_update_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

function ddAdminBookingUpdatePullFlash(): ?array
{
    if (!isset($_SESSION['admin_booking_update_flash']) || !is_array($_SESSION['admin_booking_update_flash'])) {
        return null;
    }

    $flash = $_SESSION['admin_booking_update_flash'];
    unset($_SESSION['admin_booking_update_flash']);

    return $flash;
}

function ddAdminBookingUpdateNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));

    $allowed = array('new', 'reviewed', 'confirmed', 'completed', 'cancelled');
    return in_array($status, $allowed, true) ? $status : '';
}

function ddAdminBookingUpdateFormatDateInput(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : $value;
}

function ddAdminBookingUpdateFormatTimeInput(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts !== false ? date('H:i', $ts) : $value;
}

function ddAdminBookingUpdateFormatDateTimeDisplay(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);
    return $ts !== false ? date('F j, Y \a\t g:i A', $ts) : $value;
}

function ddAdminBookingUpdateFormatMoney($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (is_numeric($value)) {
        return '$' . number_format((float) $value, 2);
    }

    return '$' . (string) $value;
}

$table = 'non_member_bookings';

if (!ddAdminBookingUpdateTableExists($pdo, $table)) {
    ddAdminBookingUpdateRedirect('admin-bookings.php?view=public&error=missing_public_table');
}

$columns = ddAdminBookingUpdateGetColumns($pdo, $table);
$idColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('id', 'booking_id'));

if ($idColumn === null) {
    ddAdminBookingUpdateRedirect('admin-bookings.php?view=public&error=missing_id_column');
}

$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    ddAdminBookingUpdateRedirect('admin-bookings.php?view=public');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ddAdminBookingUpdateValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        ddAdminBookingUpdateFlash('error', 'Security check failed. Please refresh the page and try again.');
        ddAdminBookingUpdateRedirect('admin-booking-update.php?id=' . $bookingId);
    }

    $updateParts = array();
    $params = array(':booking_id' => $bookingId);

    $addUpdate = function (string $column, string $placeholder, $value) use (&$updateParts, &$params): void {
        $updateParts[] = ddAdminBookingUpdateQuoteIdentifier($column) . ' = ' . $placeholder;
        $params[$placeholder] = $value;
    };

    $statusColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('status'));
    $serviceTypeColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('service_type', 'service'));
    $serviceDateColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('service_date', 'booking_date', 'date', 'scheduled_date', 'date_start'));
    $serviceTimeColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('service_time', 'booking_time', 'time', 'start_time', 'scheduled_time', 'preferred_walk_time', 'dropin_preferred_time'));
    $durationColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('duration_minutes', 'duration', 'walk_duration', 'dropin_walk_duration'));
    $priceColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('price', 'amount', 'total_price', 'total', 'estimated_price'));
    $notesColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('admin_notes', 'notes', 'client_notes'));
    $paymentStatusColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('payment_status'));
    $paymentMethodColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('payment_method'));
    $paymentReferenceColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('payment_reference'));
    $paymentNotesColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('payment_notes'));
    $paymentPaidAtColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('payment_paid_at'));
    $updatedAtColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('updated_at', 'status_updated_at'));
    $updatedByColumn = ddAdminBookingUpdateFirstExistingColumn($columns, array('updated_by', 'status_updated_by'));

    $status = ddAdminBookingUpdateNormalizeStatus((string) ($_POST['status'] ?? ''));
    if ($status !== '' && $statusColumn !== null) {
        $addUpdate($statusColumn, ':status', $status);
    }

    if ($serviceTypeColumn !== null) {
        $serviceType = trim((string) ($_POST['service_type'] ?? ''));
        if ($serviceType !== '') {
            $addUpdate($serviceTypeColumn, ':service_type', $serviceType);
        }
    }

    if ($serviceDateColumn !== null) {
        $serviceDate = trim((string) ($_POST['service_date'] ?? ''));
        if ($serviceDate !== '') {
            $addUpdate($serviceDateColumn, ':service_date', $serviceDate);
        }
    }

    if ($serviceTimeColumn !== null) {
        $serviceTime = trim((string) ($_POST['service_time'] ?? ''));
        if ($serviceTime !== '') {
            $addUpdate($serviceTimeColumn, ':service_time', $serviceTime);
        }
    }

    if ($durationColumn !== null) {
        $duration = trim((string) ($_POST['duration_minutes'] ?? ''));
        if ($duration !== '') {
            $durationValue = (int) $duration;
            if ($durationValue > 0) {
                $addUpdate($durationColumn, ':duration_minutes', $durationValue);
            }
        }
    }

    if ($priceColumn !== null) {
        $price = trim((string) ($_POST['price'] ?? ''));
        if ($price !== '') {
            $priceValue = (float) $price;
            if ($priceValue >= 0) {
                $addUpdate($priceColumn, ':price', $priceValue);
            }
        }
    }

    if ($notesColumn !== null) {
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        $addUpdate($notesColumn, ':notes', $notes !== '' ? $notes : null);
    }

    if ($paymentStatusColumn !== null) {
        $paymentStatus = trim((string) ($_POST['payment_status'] ?? ''));
        $addUpdate($paymentStatusColumn, ':payment_status', $paymentStatus !== '' ? $paymentStatus : null);
    }

    if ($paymentMethodColumn !== null) {
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $addUpdate($paymentMethodColumn, ':payment_method', $paymentMethod !== '' ? $paymentMethod : null);
    }

    if ($paymentReferenceColumn !== null) {
        $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
        $addUpdate($paymentReferenceColumn, ':payment_reference', $paymentReference !== '' ? $paymentReference : null);
    }

    if ($paymentNotesColumn !== null) {
        $paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));
        $addUpdate($paymentNotesColumn, ':payment_notes', $paymentNotes !== '' ? $paymentNotes : null);
    }

    if ($paymentPaidAtColumn !== null) {
        $paymentPaidAt = trim((string) ($_POST['payment_paid_at'] ?? ''));
        $addUpdate($paymentPaidAtColumn, ':payment_paid_at', $paymentPaidAt !== '' ? $paymentPaidAt : null);
    }

    if ($updatedByColumn !== null) {
        $addUpdate($updatedByColumn, ':updated_by', 'admin');
    }

    if ($updatedAtColumn !== null) {
        $updateParts[] = ddAdminBookingUpdateQuoteIdentifier($updatedAtColumn) . ' = CURRENT_TIMESTAMP';
    }

    if (empty($updateParts)) {
        ddAdminBookingUpdateFlash('error', 'No editable fields were detected for this booking.');
        ddAdminBookingUpdateRedirect('admin-booking-update.php?id=' . $bookingId);
    }

    $sql = 'UPDATE ' . ddAdminBookingUpdateQuoteIdentifier($table)
        . ' SET ' . implode(', ', $updateParts)
        . ' WHERE ' . ddAdminBookingUpdateQuoteIdentifier($idColumn) . ' = :booking_id';

    try {
        $stmt = $pdo->prepare($sql);

        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            } elseif (is_float($value)) {
                $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
            } elseif ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        ddAdminBookingUpdateFlash('success', 'Public booking updated successfully.');
    } catch (Throwable $e) {
        ddAdminBookingUpdateFlash('error', 'Could not save public booking changes.');
    }

    ddAdminBookingUpdateRedirect('admin-booking-update.php?id=' . $bookingId);
}

$booking = ddAdminBookingUpdateSafeFetchOne(
    $pdo,
    'SELECT * FROM ' . ddAdminBookingUpdateQuoteIdentifier($table)
    . ' WHERE ' . ddAdminBookingUpdateQuoteIdentifier($idColumn) . ' = :id LIMIT 1',
    array(':id' => $bookingId)
);

if ($booking === null) {
    ddAdminBookingUpdateRedirect('admin-bookings.php?view=public&error=booking_not_found');
}

$jsonSources = ddAdminBookingUpdateCollectJsonSourcesFromRow($booking);
$clientName = ddAdminBookingUpdateResolveClientName($booking, $jsonSources);
$petName = ddAdminBookingUpdateResolvePetName($booking, $jsonSources);

$currentStatus = (string) ddAdminBookingUpdateValueFromRow($booking, array('status'), 'new');
$currentServiceType = (string) ddAdminBookingUpdateValueFromRow($booking, array('service_type', 'service'), '');
$currentServiceDate = (string) ddAdminBookingUpdateValueFromRow($booking, array('service_date', 'booking_date', 'date', 'scheduled_date', 'date_start'), '');
$currentServiceTime = (string) ddAdminBookingUpdateValueFromRow($booking, array('service_time', 'booking_time', 'time', 'start_time', 'scheduled_time', 'preferred_walk_time', 'dropin_preferred_time'), '');
$currentDuration = (string) ddAdminBookingUpdateValueFromRow($booking, array('duration_minutes', 'duration', 'walk_duration', 'dropin_walk_duration'), '');
$currentPrice = (string) ddAdminBookingUpdateValueFromRow($booking, array('price', 'amount', 'total_price', 'total', 'estimated_price'), '');
$currentNotes = (string) ddAdminBookingUpdateValueFromRow($booking, array('admin_notes', 'notes', 'client_notes'), '');
$currentPaymentStatus = (string) ddAdminBookingUpdateValueFromRow($booking, array('payment_status'), '');
$currentPaymentMethod = (string) ddAdminBookingUpdateValueFromRow($booking, array('payment_method'), '');
$currentPaymentReference = (string) ddAdminBookingUpdateValueFromRow($booking, array('payment_reference'), '');
$currentPaymentNotes = (string) ddAdminBookingUpdateValueFromRow($booking, array('payment_notes'), '');
$currentPaymentPaidAt = (string) ddAdminBookingUpdateValueFromRow($booking, array('payment_paid_at'), '');
$currentWorkerName = (string) ddAdminBookingUpdateValueFromRow($booking, array('assigned_worker_name', 'assigned_walker_name', 'worker_name', 'walker_name'), '');
$currentWorkerSource = (string) ddAdminBookingUpdateValueFromRow($booking, array('assigned_worker_source', 'assigned_walker_source', 'worker_source', 'walker_source'), '');
$currentWorkerId = (string) ddAdminBookingUpdateValueFromRow($booking, array('assigned_worker_id', 'assigned_walker_id', 'worker_id', 'walker_id'), '');
$createdAt = (string) ddAdminBookingUpdateValueFromRow($booking, array('created_at'), '');
$updatedAt = (string) ddAdminBookingUpdateValueFromRow($booking, array('updated_at', 'status_updated_at'), '');
$flash = ddAdminBookingUpdatePullFlash();
$csrfToken = ddAdminBookingUpdateCsrfToken();

$assignmentText = $currentWorkerName !== '' ? $currentWorkerName : ($currentWorkerId !== '' ? ('ID ' . $currentWorkerId) : 'Unassigned');
if ($currentWorkerSource !== '') {
    $assignmentText .= ' • ' . $currentWorkerSource;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Public Booking | Doggie Dorian’s</title>
    <meta name="description" content="Admin public booking update page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #09090d;
            --panel: rgba(255,255,255,0.05);
            --panel-strong: rgba(255,255,255,0.065);
            --line: rgba(255,255,255,0.08);
            --text: #f4f1ea;
            --muted: rgba(244,241,234,0.72);
            --gold: #b9975b;
            --gold-soft: #e2c48d;
            --green: #22c55e;
            --red: #ef4444;
            --shadow: 0 20px 60px rgba(0,0,0,0.28);
            --max: 1380px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .brand {
            font-weight: 900;
            font-size: 22px;
            letter-spacing: .03em;
            color: #fff;
        }

        .top-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: #fff;
            font-weight: 700;
        }

        .hero, .card {
            background: linear-gradient(180deg, var(--panel-strong), rgba(255,255,255,0.03));
            padding: 22px;
            border-radius: 24px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .eyebrow {
            color: var(--gold-soft);
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
            color: #fff;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.2rem;
            color: #fff;
        }

        .sub {
            color: var(--muted);
            line-height: 1.65;
        }

        .stats, .detail-grid, .form-grid, .layout {
            display: grid;
            gap: 12px;
        }

        .stats {
            grid-template-columns: repeat(4, 1fr);
            margin-top: 18px;
        }

        .detail-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .layout {
            grid-template-columns: 1fr 1fr;
            margin-top: 22px;
        }

        .stat, .detail, .field-wrap {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 18px;
            padding: 14px;
        }

        .stat-label, .detail-label, label {
            color: rgba(244,241,234,0.62);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 8px;
            display: block;
        }

        .stat-value, .detail-value {
            color: #fff;
            font-weight: 900;
            line-height: 1.45;
            word-break: break-word;
        }

        .stat-value {
            font-size: 1.4rem;
        }

        .field-wrap input,
        .field-wrap select,
        .field-wrap textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: var(--text);
            border-radius: 14px;
            padding: 12px 13px;
            font: inherit;
            outline: none;
        }

        .field-wrap textarea {
            min-height: 120px;
            resize: vertical;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
            border: 1px solid rgba(34, 197, 94, 0.20);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border: 1px solid rgba(239, 68, 68, 0.20);
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: #fff;
            cursor: pointer;
            font: inherit;
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-soft), var(--gold));
            color: #000;
            border-color: transparent;
        }

        .notes-box {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
        }

        .notes-text {
            color: rgba(244,241,234,0.82);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        @media (max-width: 1080px) {
            .hero,
            .layout,
            .stats,
            .detail-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.7rem;
            }

            .action-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-bookings.php?view=public">Bookings</a>
                <a class="top-link" href="admin-non-member-booking-view.php?id=<?php echo (int) $bookingId; ?>">Public View</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <section class="hero">
            <div class="hero-primary card">
                <div class="eyebrow">Public Booking Control</div>
                <h1>Update Public Booking</h1>
                <div class="sub">
                    Review and update this non-member booking without routing through a POST-only handler link.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Booking ID</div>
                        <div class="stat-value">#<?php echo (int) $bookingId; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Client</div>
                        <div class="stat-value"><?php echo ddAdminBookingUpdateH($clientName); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Pet</div>
                        <div class="stat-value"><?php echo ddAdminBookingUpdateH($petName !== '' ? $petName : '—'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Price</div>
                        <div class="stat-value"><?php echo ddAdminBookingUpdateH(ddAdminBookingUpdateFormatMoney($currentPrice)); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Booking Snapshot</div>
                <h2>Current details</h2>

                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Status</div>
                        <div class="detail-value"><?php echo ddAdminBookingUpdateH($currentStatus !== '' ? ucfirst($currentStatus) : '—'); ?></div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Service</div>
                        <div class="detail-value"><?php echo ddAdminBookingUpdateH($currentServiceType !== '' ? $currentServiceType : '—'); ?></div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Date</div>
                        <div class="detail-value"><?php echo ddAdminBookingUpdateH($currentServiceDate !== '' ? $currentServiceDate : '—'); ?></div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Time</div>
                        <div class="detail-value"><?php echo ddAdminBookingUpdateH($currentServiceTime !== '' ? $currentServiceTime : '—'); ?></div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Assigned Worker</div>
                        <div class="detail-value"><?php echo ddAdminBookingUpdateH($assignmentText); ?></div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Created / Updated</div>
                        <div class="detail-value">
                            <?php echo ddAdminBookingUpdateH(ddAdminBookingUpdateFormatDateTimeDisplay($createdAt)); ?><br>
                            <?php echo ddAdminBookingUpdateH(ddAdminBookingUpdateFormatDateTimeDisplay($updatedAt)); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($flash !== null && !empty($flash['message'])): ?>
            <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo ddAdminBookingUpdateH((string) $flash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="layout">
            <div class="card">
                <div class="eyebrow">Edit Booking</div>
                <h2>Update fields</h2>

                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminBookingUpdateH($csrfToken); ?>">
                    <input type="hidden" name="booking_id" value="<?php echo (int) $bookingId; ?>">

                    <div class="form-grid">
                        <div class="field-wrap">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <?php foreach (array('new', 'reviewed', 'confirmed', 'completed', 'cancelled') as $statusOption): ?>
                                    <option value="<?php echo ddAdminBookingUpdateH($statusOption); ?>" <?php echo strtolower($currentStatus) === $statusOption ? 'selected' : ''; ?>>
                                        <?php echo ddAdminBookingUpdateH(ucfirst($statusOption)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-wrap">
                            <label for="service_type">Service Type</label>
                            <input id="service_type" name="service_type" type="text" value="<?php echo ddAdminBookingUpdateH($currentServiceType); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="service_date">Service Date</label>
                            <input id="service_date" name="service_date" type="date" value="<?php echo ddAdminBookingUpdateH(ddAdminBookingUpdateFormatDateInput($currentServiceDate)); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="service_time">Service Time</label>
                            <input id="service_time" name="service_time" type="time" value="<?php echo ddAdminBookingUpdateH(ddAdminBookingUpdateFormatTimeInput($currentServiceTime)); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="duration_minutes">Duration Minutes</label>
                            <input id="duration_minutes" name="duration_minutes" type="number" min="0" step="1" value="<?php echo ddAdminBookingUpdateH($currentDuration); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="price">Price</label>
                            <input id="price" name="price" type="number" min="0" step="0.01" value="<?php echo ddAdminBookingUpdateH($currentPrice); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="payment_status">Payment Status</label>
                            <select id="payment_status" name="payment_status">
                                <?php
                                $paymentStatusOptions = array('', 'unpaid', 'pending', 'paid', 'refunded', 'cancelled');
                                foreach ($paymentStatusOptions as $paymentStatusOption):
                                ?>
                                    <option value="<?php echo ddAdminBookingUpdateH($paymentStatusOption); ?>" <?php echo strtolower($currentPaymentStatus) === strtolower($paymentStatusOption) ? 'selected' : ''; ?>>
                                        <?php echo ddAdminBookingUpdateH($paymentStatusOption !== '' ? ucfirst($paymentStatusOption) : '—'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-wrap">
                            <label for="payment_method">Payment Method</label>
                            <input id="payment_method" name="payment_method" type="text" value="<?php echo ddAdminBookingUpdateH($currentPaymentMethod); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="payment_reference">Payment Reference</label>
                            <input id="payment_reference" name="payment_reference" type="text" value="<?php echo ddAdminBookingUpdateH($currentPaymentReference); ?>">
                        </div>

                        <div class="field-wrap">
                            <label for="payment_paid_at">Payment Paid At</label>
                            <input id="payment_paid_at" name="payment_paid_at" type="text" value="<?php echo ddAdminBookingUpdateH($currentPaymentPaidAt); ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                        </div>

                        <div class="field-wrap" style="grid-column: 1 / -1;">
                            <label for="admin_notes">Admin Notes</label>
                            <textarea id="admin_notes" name="admin_notes"><?php echo ddAdminBookingUpdateH($currentNotes); ?></textarea>
                        </div>

                        <div class="field-wrap" style="grid-column: 1 / -1;">
                            <label for="payment_notes">Payment Notes</label>
                            <textarea id="payment_notes" name="payment_notes"><?php echo ddAdminBookingUpdateH($currentPaymentNotes); ?></textarea>
                        </div>
                    </div>

                    <div class="action-row">
                        <button class="btn btn-gold" type="submit">Save Changes</button>
                        <a class="btn" href="admin-bookings.php?view=public">Back to Bookings</a>
                        <a class="btn" href="admin-non-member-booking-view.php?id=<?php echo (int) $bookingId; ?>">Open Public View</a>
                        <a class="btn" href="admin-assign-walker.php?booking_source_key=<?php echo ddAdminBookingUpdateH(urlencode('non_member_bookings:' . $bookingId)); ?>">Assign Worker</a>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="eyebrow">Reference</div>
                <h2>Current notes and assignment</h2>

                <div class="notes-box">
                    <div class="detail-label">Current Notes</div>
                    <div class="notes-text"><?php echo ddAdminBookingUpdateH($currentNotes !== '' ? $currentNotes : '—'); ?></div>
                </div>

                <div class="notes-box" style="margin-top: 14px;">
                    <div class="detail-label">Current Payment Notes</div>
                    <div class="notes-text"><?php echo ddAdminBookingUpdateH($currentPaymentNotes !== '' ? $currentPaymentNotes : '—'); ?></div>
                </div>

                <div class="notes-box" style="margin-top: 14px;">
                    <div class="detail-label">Assignment</div>
                    <div class="notes-text"><?php echo ddAdminBookingUpdateH($assignmentText); ?></div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>