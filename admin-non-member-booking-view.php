<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/New_York');

$pdoConnection = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $pdoConnection = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $pdoConnection = $db;
}

if (!$pdoConnection instanceof PDO) {
    http_response_code(500);
    exit('Database connection not available.');
}

$pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Admin protection
|--------------------------------------------------------------------------
*/
function adminRedirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function isAdminSession(): bool
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

if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id']) && empty($_SESSION['is_admin'])) {
    adminRedirect('admin-login.php');
}

if (!isAdminSession()) {
    adminRedirect('admin-dashboard.php');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['admin_nonmember_view_csrf']) || !is_string($_SESSION['admin_nonmember_view_csrf'])) {
    $_SESSION['admin_nonmember_view_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_nonmember_view_csrf'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function format_money(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return h((string) $amount);
    }

    return '$' . number_format((float) $amount, 2);
}

function format_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return h($value);
    }

    return date('F j, Y', $timestamp);
}

function format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return h($value);
    }

    return date('F j, Y g:i A', $timestamp);
}

function service_badge_class(string $serviceType): string
{
    $normalized = strtolower(trim($serviceType));

    return match ($normalized) {
        'walk' => 'walk',
        'daycare' => 'daycare',
        'boarding' => 'boarding',
        'drop-in', 'drop in', 'dropin' => 'dropin',
        'drop-in + walk', 'drop in + walk', 'dropin + walk' => 'dropinwalk',
        'sitting', 'in-home sitting', 'in home sitting' => 'sitting',
        default => 'default',
    };
}

function status_badge_class(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending' => 'pending',
        'confirmed' => 'confirmed',
        'scheduled' => 'scheduled',
        'completed' => 'completed',
        'cancelled', 'canceled' => 'cancelled',
        default => 'default',
    };
}

function detail_value(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? h($value) : '—';
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
              AND name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $tableName]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function get_table_columns(PDO $pdo, string $tableName): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($tableName) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function build_select_fragment(?string $column, string $alias, string $fallbackSql = 'NULL'): string
{
    if ($column === null) {
        return $fallbackSql . ' AS ' . quotedIdentifier($alias);
    }

    return quotedIdentifier($column) . ' AS ' . quotedIdentifier($alias);
}

function fetch_one_safe(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function safe_execute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function value_from_row(array $row, array $candidates, string $default = ''): string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return (string) $row[$candidate];
        }
    }

    return $default;
}

function build_service_summary(array $booking): string
{
    $serviceType = strtolower(trim((string) ($booking['service_type'] ?? '')));
    $parts = [];

    if ($serviceType === 'walk') {
        if (!empty($booking['walk_duration'])) {
            $parts[] = (int) $booking['walk_duration'] . ' minute walk';
        }
        if (!empty($booking['preferred_walk_time'])) {
            $parts[] = 'Preferred time: ' . (string) $booking['preferred_walk_time'];
        }
    } elseif ($serviceType === 'daycare') {
        $parts[] = 'Daycare request';
        if (!empty($booking['dog_size'])) {
            $parts[] = (string) $booking['dog_size'] . ' dog';
        }
    } elseif ($serviceType === 'boarding') {
        $parts[] = 'Boarding request';
        if (!empty($booking['dog_size'])) {
            $parts[] = (string) $booking['dog_size'] . ' dog';
        }
    } elseif (in_array($serviceType, ['drop-in', 'drop in', 'dropin'], true)) {
        if (!empty($booking['dropin_hours'])) {
            $parts[] = (int) $booking['dropin_hours'] . ' hour visit';
        }
        if (!empty($booking['dropin_preferred_time'])) {
            $parts[] = 'Preferred start: ' . (string) $booking['dropin_preferred_time'];
        }
    } elseif (in_array($serviceType, ['drop-in + walk', 'drop in + walk', 'dropin + walk'], true)) {
        if (!empty($booking['dropin_hours'])) {
            $parts[] = (int) $booking['dropin_hours'] . ' hour visit';
        }
        if (!empty($booking['dropin_walk_duration'])) {
            $parts[] = (int) $booking['dropin_walk_duration'] . ' min included walk';
        }
        if (($booking['include_second_walk'] ?? 'No') === 'Yes') {
            $parts[] = 'Second walk added';
            if (!empty($booking['second_walk_duration'])) {
                $parts[] = (int) $booking['second_walk_duration'] . ' min second walk';
            }
        } else {
            $parts[] = 'No second walk';
        }
        if (!empty($booking['dropin_walk_preferred_time'])) {
            $parts[] = 'Preferred start: ' . (string) $booking['dropin_walk_preferred_time'];
        }
    } elseif (in_array($serviceType, ['sitting', 'in-home sitting', 'in home sitting'], true)) {
        $parts[] = 'In-home sitting session';
        if (!empty($booking['date_start'])) {
            $parts[] = 'Start: ' . format_date((string) $booking['date_start']);
        }
        if (!empty($booking['date_end'])) {
            $parts[] = 'End: ' . format_date((string) $booking['date_end']);
        }
    }

    return !empty($parts) ? implode(' • ', $parts) : '—';
}

/*
|--------------------------------------------------------------------------
| Config
|--------------------------------------------------------------------------
*/
$allowedStatuses = ['Pending', 'Confirmed', 'Scheduled', 'Completed', 'Cancelled'];
$tableName = 'non_member_bookings';

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
$flashType = (string) ($_SESSION['admin_nonmember_flash_type'] ?? '');
$flashMessage = (string) ($_SESSION['admin_nonmember_flash_message'] ?? '');

unset($_SESSION['admin_nonmember_flash_type'], $_SESSION['admin_nonmember_flash_message']);

/*
|--------------------------------------------------------------------------
| Table validation
|--------------------------------------------------------------------------
*/
if (!table_exists($pdoConnection, $tableName)) {
    $_SESSION['admin_nonmember_flash_type'] = 'error';
    $_SESSION['admin_nonmember_flash_message'] = 'The non_member_bookings table was not found.';
    adminRedirect('admin-non-member-bookings.php');
}

$tableColumns = get_table_columns($pdoConnection, $tableName);
$idColumn = first_existing_column($tableColumns, ['id']);

if ($idColumn === null) {
    $_SESSION['admin_nonmember_flash_type'] = 'error';
    $_SESSION['admin_nonmember_flash_message'] = 'The non_member_bookings table is missing an ID column.';
    adminRedirect('admin-non-member-bookings.php');
}

$bookingReferenceCol = first_existing_column($tableColumns, ['booking_reference']);
$bookingSourceCol = first_existing_column($tableColumns, ['booking_source']);
$statusCol = first_existing_column($tableColumns, ['status']);
$fullNameCol = first_existing_column($tableColumns, ['full_name', 'client_name', 'name']);
$phoneCol = first_existing_column($tableColumns, ['phone', 'phone_number']);
$emailCol = first_existing_column($tableColumns, ['email']);
$serviceTypeCol = first_existing_column($tableColumns, ['service_type', 'service']);
$dogNameCol = first_existing_column($tableColumns, ['dog_name', 'pet_name', 'name']);
$dogSizeCol = first_existing_column($tableColumns, ['dog_size', 'size']);
$walkDurationCol = first_existing_column($tableColumns, ['walk_duration']);
$preferredWalkTimeCol = first_existing_column($tableColumns, ['preferred_walk_time']);
$dropinHoursCol = first_existing_column($tableColumns, ['dropin_hours']);
$dropinPreferredTimeCol = first_existing_column($tableColumns, ['dropin_preferred_time']);
$dropinWalkDurationCol = first_existing_column($tableColumns, ['dropin_walk_duration']);
$includeSecondWalkCol = first_existing_column($tableColumns, ['include_second_walk']);
$secondWalkDurationCol = first_existing_column($tableColumns, ['second_walk_duration']);
$dropinWalkPreferredTimeCol = first_existing_column($tableColumns, ['dropin_walk_preferred_time']);
$dateStartCol = first_existing_column($tableColumns, ['date_start']);
$dateEndCol = first_existing_column($tableColumns, ['date_end']);
$feedingScheduleCol = first_existing_column($tableColumns, ['feeding_schedule']);
$preferredContactCol = first_existing_column($tableColumns, ['preferred_contact']);
$notesCol = first_existing_column($tableColumns, ['notes']);
$estimatedPriceCol = first_existing_column($tableColumns, ['estimated_price', 'price', 'total_price']);
$pricingTypeCol = first_existing_column($tableColumns, ['pricing_type']);
$unitPriceCol = first_existing_column($tableColumns, ['unit_price']);
$discountLabelCol = first_existing_column($tableColumns, ['discount_label']);
$quantityCol = first_existing_column($tableColumns, ['quantity']);
$rawFormJsonCol = first_existing_column($tableColumns, ['raw_form_json']);
$createdAtCol = first_existing_column($tableColumns, ['created_at']);
$updatedAtCol = first_existing_column($tableColumns, ['updated_at']);

/*
|--------------------------------------------------------------------------
| Booking id
|--------------------------------------------------------------------------
*/
$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($bookingId <= 0) {
    adminRedirect('admin-non-member-bookings.php');
}

/*
|--------------------------------------------------------------------------
| Inline status update
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $newStatus = trim((string) ($_POST['status'] ?? ''));

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['admin_nonmember_flash_type'] = 'error';
        $_SESSION['admin_nonmember_flash_message'] = 'Session expired. Please refresh and try again.';
        adminRedirect('admin-non-member-bookings-view.php?id=' . $bookingId);
    }

    if ($statusCol === null) {
        $_SESSION['admin_nonmember_flash_type'] = 'error';
        $_SESSION['admin_nonmember_flash_message'] = 'This table does not have a status column.';
        adminRedirect('admin-non-member-bookings-view.php?id=' . $bookingId);
    }

    if (!in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['admin_nonmember_flash_type'] = 'error';
        $_SESSION['admin_nonmember_flash_message'] = 'Invalid status selected.';
        adminRedirect('admin-non-member-bookings-view.php?id=' . $bookingId);
    }

    $setParts = [quotedIdentifier($statusCol) . ' = :status'];
    $params = [
        ':status' => $newStatus,
        ':id' => $bookingId,
    ];

    if ($updatedAtCol !== null) {
        $setParts[] = quotedIdentifier($updatedAtCol) . ' = :updated_at';
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $sql = '
        UPDATE ' . quotedIdentifier($tableName) . '
        SET ' . implode(', ', $setParts) . '
        WHERE ' . quotedIdentifier($idColumn) . ' = :id
    ';

    $stmt = $pdoConnection->prepare($sql);

    if (safe_execute($stmt, $params)) {
        $_SESSION['admin_nonmember_flash_type'] = 'success';
        $_SESSION['admin_nonmember_flash_message'] = 'Booking status updated successfully.';
    } else {
        $_SESSION['admin_nonmember_flash_type'] = 'error';
        $_SESSION['admin_nonmember_flash_message'] = 'Unable to update the booking status.';
    }

    adminRedirect('admin-non-member-bookings-view.php?id=' . $bookingId);
}

/*
|--------------------------------------------------------------------------
| Load booking
|--------------------------------------------------------------------------
*/
$selectParts = [
    build_select_fragment($idColumn, 'id', '0'),
    build_select_fragment($bookingReferenceCol, 'booking_reference', "''"),
    build_select_fragment($bookingSourceCol, 'booking_source', "'non-member'"),
    build_select_fragment($statusCol, 'status', "'Pending'"),
    build_select_fragment($fullNameCol, 'full_name', "''"),
    build_select_fragment($phoneCol, 'phone', "''"),
    build_select_fragment($emailCol, 'email', "''"),
    build_select_fragment($serviceTypeCol, 'service_type', "''"),
    build_select_fragment($dogNameCol, 'dog_name', "''"),
    build_select_fragment($dogSizeCol, 'dog_size', "''"),
    build_select_fragment($walkDurationCol, 'walk_duration', 'NULL'),
    build_select_fragment($preferredWalkTimeCol, 'preferred_walk_time', "''"),
    build_select_fragment($dropinHoursCol, 'dropin_hours', 'NULL'),
    build_select_fragment($dropinPreferredTimeCol, 'dropin_preferred_time', "''"),
    build_select_fragment($dropinWalkDurationCol, 'dropin_walk_duration', 'NULL'),
    build_select_fragment($includeSecondWalkCol, 'include_second_walk', "'No'"),
    build_select_fragment($secondWalkDurationCol, 'second_walk_duration', 'NULL'),
    build_select_fragment($dropinWalkPreferredTimeCol, 'dropin_walk_preferred_time', "''"),
    build_select_fragment($dateStartCol, 'date_start', "''"),
    build_select_fragment($dateEndCol, 'date_end', "''"),
    build_select_fragment($feedingScheduleCol, 'feeding_schedule', "''"),
    build_select_fragment($preferredContactCol, 'preferred_contact', "''"),
    build_select_fragment($notesCol, 'notes', "''"),
    build_select_fragment($estimatedPriceCol, 'estimated_price', '0'),
    build_select_fragment($pricingTypeCol, 'pricing_type', "''"),
    build_select_fragment($unitPriceCol, 'unit_price', 'NULL'),
    build_select_fragment($discountLabelCol, 'discount_label', "''"),
    build_select_fragment($quantityCol, 'quantity', 'NULL'),
    build_select_fragment($rawFormJsonCol, 'raw_form_json', "''"),
    build_select_fragment($createdAtCol, 'created_at', "''"),
    build_select_fragment($updatedAtCol, 'updated_at', "''"),
];

$sql = '
    SELECT ' . implode(",\n           ", $selectParts) . '
    FROM ' . quotedIdentifier($tableName) . '
    WHERE ' . quotedIdentifier($idColumn) . ' = :id
';

if ($bookingSourceCol !== null) {
    $sql .= ' AND COALESCE(' . quotedIdentifier($bookingSourceCol) . ", 'non-member') = 'non-member'";
}

$sql .= ' LIMIT 1';

$booking = fetch_one_safe($pdoConnection, $sql, [':id' => $bookingId]);

if (!$booking) {
    adminRedirect('admin-non-member-bookings.php');
}

/*
|--------------------------------------------------------------------------
| Detail formatting
|--------------------------------------------------------------------------
*/
$serviceType = (string) ($booking['service_type'] ?? '');
$status = (string) ($booking['status'] ?? 'Pending');
$serviceSummary = build_service_summary($booking);

$rawFormData = [];
if (!empty($booking['raw_form_json'])) {
    $decoded = json_decode((string) $booking['raw_form_json'], true);
    if (is_array($decoded)) {
        $rawFormData = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin | Non-Member Booking View | Doggie Dorian’s</title>
  <meta name="description" content="Admin detail view for a non-member booking at Doggie Dorian’s.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #09090c;
      --bg-soft: #111116;
      --panel: rgba(255,255,255,0.045);
      --panel-strong: rgba(255,255,255,0.065);
      --border: rgba(255,255,255,0.08);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --gold-deep: #b9921f;
      --cream: #f6f1e8;
      --text: rgba(255,255,255,0.88);
      --muted: rgba(255,255,255,0.66);
      --shadow: 0 24px 70px rgba(0,0,0,0.45);
      --radius-xl: 30px;
      --radius-lg: 22px;
      --radius-md: 16px;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 24%),
        radial-gradient(circle at top right, rgba(212,175,55,0.06), transparent 20%),
        linear-gradient(180deg, #08080a 0%, #101116 100%);
      min-height: 100vh;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    button,
    select {
      font: inherit;
    }

    .layout {
      display: grid;
      grid-template-columns: 280px 1fr;
      min-height: 100vh;
    }

    .sidebar {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 26px 20px;
      border-right: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015)),
        rgba(10,10,14,0.82);
      backdrop-filter: blur(18px);
    }

    .brand {
      padding: 12px 10px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      margin-bottom: 18px;
    }

    .brand-title {
      color: var(--cream);
      font-size: 1.55rem;
      font-weight: 700;
      letter-spacing: 0.2px;
    }

    .brand-subtitle {
      margin-top: 8px;
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.2px;
      color: rgba(240,215,122,0.92);
    }

    .side-label {
      margin: 22px 10px 10px;
      font-family: Arial, sans-serif;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 2.2px;
      color: rgba(255,255,255,0.45);
    }

    .side-nav {
      display: grid;
      gap: 8px;
    }

    .side-nav a {
      display: block;
      padding: 14px 14px;
      border-radius: 16px;
      font-family: Arial, sans-serif;
      font-size: 0.94rem;
      color: rgba(255,255,255,0.84);
      border: 1px solid transparent;
      transition: 0.2s ease;
    }

    .side-nav a:hover,
    .side-nav a.active {
      background: rgba(212,175,55,0.12);
      border-color: rgba(212,175,55,0.22);
      color: var(--cream);
    }

    .content {
      padding: 28px;
    }

    .hero {
      border-radius: var(--radius-xl);
      border: 1px solid rgba(255,255,255,0.08);
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.15), transparent 30%),
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025));
      box-shadow: var(--shadow);
      padding: 30px;
      margin-bottom: 24px;
    }

    .eyebrow {
      font-family: Arial, sans-serif;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 2.6px;
      color: var(--gold-soft);
      margin-bottom: 10px;
    }

    .hero-top {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
      align-items: flex-start;
    }

    .hero h1 {
      color: var(--cream);
      font-size: clamp(2rem, 4vw, 3.3rem);
      line-height: 0.98;
      letter-spacing: -1.2px;
      margin-bottom: 10px;
    }

    .hero p {
      max-width: 900px;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      font-size: 1rem;
    }

    .hero-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 18px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.1);
      cursor: pointer;
      font-family: Arial, sans-serif;
      font-size: 0.94rem;
      font-weight: 700;
      transition: transform 0.18s ease, opacity 0.18s ease;
      background: none;
      color: var(--text);
    }

    .btn:hover {
      transform: translateY(-1px);
      opacity: 0.98;
    }

    .btn-gold {
      border-color: rgba(212,175,55,0.3);
      color: #18140a;
      background: linear-gradient(135deg, #f0d77a 0%, #d4af37 50%, #b9921f 100%);
      box-shadow: 0 14px 30px rgba(212,175,55,0.2);
    }

    .btn-dark {
      background: rgba(255,255,255,0.05);
      color: var(--text);
    }

    .flash {
      margin-bottom: 22px;
      padding: 16px 18px;
      border-radius: 18px;
      font-family: Arial, sans-serif;
      border: 1px solid rgba(255,255,255,0.10);
    }

    .flash.success {
      background: rgba(29,143,91,0.14);
      border-color: rgba(29,143,91,0.32);
      color: #d6ffe9;
    }

    .flash.error {
      background: rgba(184,75,75,0.14);
      border-color: rgba(184,75,75,0.32);
      color: #ffe1e1;
    }

    .top-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 22px;
      margin-bottom: 22px;
    }

    .panel {
      border-radius: 28px;
      padding: 24px;
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025)),
        rgba(255,255,255,0.015);
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .panel + .panel {
      margin-top: 22px;
    }

    .panel h2 {
      color: var(--cream);
      font-size: 1.5rem;
      margin-bottom: 8px;
    }

    .panel-head-copy {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.66);
      font-size: 0.95rem;
      margin-bottom: 18px;
    }

    .summary-card {
      display: grid;
      gap: 16px;
    }

    .summary-row {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      padding: 14px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .summary-row:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .summary-label {
      font-family: Arial, sans-serif;
      font-size: 0.86rem;
      text-transform: uppercase;
      letter-spacing: 1.4px;
      color: rgba(255,255,255,0.5);
    }

    .summary-value {
      text-align: right;
      color: var(--cream);
      font-family: Arial, sans-serif;
      font-weight: 700;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 0 12px;
      border-radius: 999px;
      font-size: 0.82rem;
      font-weight: 700;
      border: 1px solid rgba(255,255,255,0.12);
      white-space: nowrap;
      font-family: Arial, sans-serif;
    }

    .badge.default { background: rgba(255,255,255,0.06); color: var(--text); }
    .badge.walk { background: rgba(84,127,199,0.14); color: #dfe9ff; border-color: rgba(84,127,199,0.28); }
    .badge.daycare { background: rgba(28,140,92,0.14); color: #ddffef; border-color: rgba(28,140,92,0.28); }
    .badge.boarding { background: rgba(179,135,40,0.16); color: #fff0c8; border-color: rgba(179,135,40,0.3); }
    .badge.dropin { background: rgba(160,120,215,0.14); color: #efe5ff; border-color: rgba(160,120,215,0.28); }
    .badge.dropinwalk { background: rgba(211,93,141,0.14); color: #ffe2ee; border-color: rgba(211,93,141,0.28); }
    .badge.sitting { background: rgba(212,175,55,0.14); color: #fff2cc; border-color: rgba(212,175,55,0.30); }

    .status.pending { background: rgba(179,135,40,0.16); color: #ffeec1; border-color: rgba(179,135,40,0.3); }
    .status.confirmed { background: rgba(84,127,199,0.14); color: #e2ecff; border-color: rgba(84,127,199,0.28); }
    .status.scheduled { background: rgba(160,120,215,0.14); color: #f1e7ff; border-color: rgba(160,120,215,0.28); }
    .status.completed { background: rgba(28,140,92,0.14); color: #ddffef; border-color: rgba(28,140,92,0.28); }
    .status.cancelled { background: rgba(184,81,81,0.14); color: #ffe0e0; border-color: rgba(184,81,81,0.28); }
    .status.default { background: rgba(255,255,255,0.06); color: var(--text); }

    .details-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .detail-card {
      border-radius: 20px;
      padding: 18px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.07);
    }

    .detail-label {
      font-family: Arial, sans-serif;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 1.8px;
      color: var(--gold-soft);
      margin-bottom: 8px;
    }

    .detail-value {
      font-family: Arial, sans-serif;
      color: var(--cream);
      font-size: 1rem;
      line-height: 1.5;
      word-break: break-word;
    }

    .notes-box,
    .raw-box {
      border-radius: 22px;
      padding: 20px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.07);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      line-height: 1.7;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .status-form {
      display: grid;
      gap: 14px;
    }

    .status-form label {
      font-family: Arial, sans-serif;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.84);
      font-weight: 600;
    }

    .status-form select {
      width: 100%;
      min-height: 52px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.1);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      padding: 0 14px;
      font-family: Arial, sans-serif;
      outline: none;
    }

    .status-form select:focus {
      border-color: rgba(212,175,55,0.45);
      box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
      background: rgba(255,255,255,0.055);
    }

    .raw-grid {
      display: grid;
      gap: 10px;
    }

    .raw-item {
      display: grid;
      grid-template-columns: 220px 1fr;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      font-family: Arial, sans-serif;
    }

    .raw-item:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .raw-key {
      color: rgba(255,255,255,0.52);
      font-size: 0.88rem;
      word-break: break-word;
    }

    .raw-value {
      color: rgba(255,255,255,0.86);
      font-size: 0.92rem;
      word-break: break-word;
    }

    @media (max-width: 1100px) {
      .layout {
        grid-template-columns: 1fr;
      }

      .sidebar {
        position: relative;
        height: auto;
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.08);
      }

      .top-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 760px) {
      .content {
        padding: 18px;
      }

      .hero,
      .panel {
        padding: 20px;
      }

      .details-grid {
        grid-template-columns: 1fr;
      }

      .hero-actions {
        width: 100%;
      }

      .btn {
        width: 100%;
      }

      .raw-item {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-title">Doggie Dorian’s</div>
        <div class="brand-subtitle">Admin Concierge</div>
      </div>

      <div class="side-label">Navigation</div>
      <nav class="side-nav">
        <a href="admin-dashboard.php">Admin Dashboard</a>
        <a href="admin-revenue.php">Revenue</a>
        <a href="admin-bookings.php">Member Bookings</a>
        <a href="admin-non-member-bookings.php" class="active">Non-Member Bookings</a>
        <a href="admin-members.php">Members</a>
        <a href="admin-walks.php">Walks</a>
        <a href="logout.php">Logout</a>
      </nav>
    </aside>

    <main class="content">
      <?php if ($flashMessage !== ''): ?>
        <div class="flash <?php echo $flashType === 'success' ? 'success' : 'error'; ?>">
          <?php echo h($flashMessage); ?>
        </div>
      <?php endif; ?>

      <section class="hero">
        <div class="eyebrow">Dedicated Public Booking Record</div>

        <div class="hero-top">
          <div>
            <h1>Non-Member Booking</h1>
            <p>
              Review the full details for this public booking request while keeping it fully separate from the member-only booking flow.
            </p>
          </div>

          <div class="hero-actions">
            <a href="admin-non-member-bookings.php" class="btn btn-dark">Back to Non-Member Bookings</a>
          </div>
        </div>
      </section>

      <section class="top-grid">
        <div class="panel">
          <h2>Booking Summary</h2>
          <div class="panel-head-copy">
            High-level details for quick admin review.
          </div>

          <div class="summary-card">
            <div class="summary-row">
              <div class="summary-label">Booking Reference</div>
              <div class="summary-value"><?php echo h($booking['booking_reference'] !== '' ? $booking['booking_reference'] : 'N/A'); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Booking Type</div>
              <div class="summary-value">Non-Member</div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Service</div>
              <div class="summary-value">
                <span class="badge <?php echo h(service_badge_class($serviceType)); ?>">
                  <?php echo h($serviceType !== '' ? $serviceType : 'Service'); ?>
                </span>
              </div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Status</div>
              <div class="summary-value">
                <span class="badge status <?php echo h(status_badge_class($status)); ?>">
                  <?php echo h($status); ?>
                </span>
              </div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Estimated Price</div>
              <div class="summary-value"><?php echo format_money($booking['estimated_price'] ?? 0); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Pricing Type</div>
              <div class="summary-value"><?php echo detail_value($booking['pricing_type'] ?? null); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Unit Price</div>
              <div class="summary-value"><?php echo format_money($booking['unit_price'] ?? null); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Discount Label</div>
              <div class="summary-value"><?php echo detail_value($booking['discount_label'] ?? null); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Quantity</div>
              <div class="summary-value"><?php echo detail_value(isset($booking['quantity']) ? (string) $booking['quantity'] : null); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Requested Dates</div>
              <div class="summary-value">
                <?php echo format_date($booking['date_start'] ?? null); ?>
                <?php if (!empty($booking['date_end'])): ?>
                  → <?php echo format_date($booking['date_end']); ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Submitted</div>
              <div class="summary-value"><?php echo format_datetime($booking['created_at'] ?? null); ?></div>
            </div>

            <div class="summary-row">
              <div class="summary-label">Last Updated</div>
              <div class="summary-value"><?php echo format_datetime($booking['updated_at'] ?? null); ?></div>
            </div>
          </div>
        </div>

        <div class="panel">
          <h2>Update Booking Status</h2>
          <div class="panel-head-copy">
            Move this request through your non-member workflow.
          </div>

          <form class="status-form" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

            <label for="status">Booking Status</label>
            <select id="status" name="status" required>
              <?php foreach ($allowedStatuses as $allowedStatus): ?>
                <option value="<?php echo h($allowedStatus); ?>" <?php echo $status === $allowedStatus ? 'selected' : ''; ?>>
                  <?php echo h($allowedStatus); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-gold">Save Status Update</button>
          </form>
        </div>
      </section>

      <section class="panel">
        <h2>Client & Dog Details</h2>
        <div class="panel-head-copy">
          Core contact information and pet details submitted through the public booking form.
        </div>

        <div class="details-grid">
          <div class="detail-card">
            <div class="detail-label">Client Name</div>
            <div class="detail-value"><?php echo detail_value($booking['full_name'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Phone</div>
            <div class="detail-value"><?php echo detail_value($booking['phone'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Email</div>
            <div class="detail-value"><?php echo detail_value($booking['email'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Preferred Contact Method</div>
            <div class="detail-value"><?php echo detail_value($booking['preferred_contact'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Dog Name</div>
            <div class="detail-value"><?php echo detail_value($booking['dog_name'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Dog Size</div>
            <div class="detail-value"><?php echo detail_value($booking['dog_size'] ?? null); ?></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <h2>Service Details</h2>
        <div class="panel-head-copy">
          The service selection and submitted timing details for this non-member request.
        </div>

        <div class="details-grid">
          <div class="detail-card">
            <div class="detail-label">Service Type</div>
            <div class="detail-value"><?php echo detail_value($booking['service_type'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Service Summary</div>
            <div class="detail-value"><?php echo h($serviceSummary); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Walk Duration</div>
            <div class="detail-value">
              <?php echo !empty($booking['walk_duration']) ? (int) $booking['walk_duration'] . ' minutes' : '—'; ?>
            </div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Preferred Walk Time</div>
            <div class="detail-value"><?php echo detail_value($booking['preferred_walk_time'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Drop-In Length</div>
            <div class="detail-value">
              <?php echo !empty($booking['dropin_hours']) ? (int) $booking['dropin_hours'] . ' hour(s)' : '—'; ?>
            </div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Drop-In Preferred Start</div>
            <div class="detail-value"><?php echo detail_value($booking['dropin_preferred_time'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Included Walk for Drop-In + Walk</div>
            <div class="detail-value">
              <?php echo !empty($booking['dropin_walk_duration']) ? (int) $booking['dropin_walk_duration'] . ' minutes' : '—'; ?>
            </div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Second Walk Added</div>
            <div class="detail-value"><?php echo detail_value($booking['include_second_walk'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Second Walk Duration</div>
            <div class="detail-value">
              <?php echo !empty($booking['second_walk_duration']) ? (int) $booking['second_walk_duration'] . ' minutes' : '—'; ?>
            </div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Drop-In + Walk Preferred Start</div>
            <div class="detail-value"><?php echo detail_value($booking['dropin_walk_preferred_time'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Requested Start Date</div>
            <div class="detail-value"><?php echo format_date($booking['date_start'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Requested End Date</div>
            <div class="detail-value"><?php echo format_date($booking['date_end'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Feeding Schedule</div>
            <div class="detail-value"><?php echo detail_value($booking['feeding_schedule'] ?? null); ?></div>
          </div>

          <div class="detail-card">
            <div class="detail-label">Estimated Price</div>
            <div class="detail-value"><?php echo format_money($booking['estimated_price'] ?? 0); ?></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <h2>Additional Notes</h2>
        <div class="panel-head-copy">
          Any extra information the client included with their non-member booking request.
        </div>

        <div class="notes-box"><?php echo trim((string) ($booking['notes'] ?? '')) !== '' ? h((string) $booking['notes']) : 'No additional notes were submitted.'; ?></div>
      </section>

      <?php if (!empty($rawFormData)): ?>
        <section class="panel">
          <h2>Raw Submitted Form Data</h2>
          <div class="panel-head-copy">
            Full backup of the original submitted fields for troubleshooting and admin reference.
          </div>

          <div class="raw-box">
            <div class="raw-grid">
              <?php foreach ($rawFormData as $key => $value): ?>
                <div class="raw-item">
                  <div class="raw-key"><?php echo h((string) $key); ?></div>
                  <div class="raw-value">
                    <?php
                    if (is_array($value)) {
                        echo h((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    } else {
                        echo h((string) $value);
                    }
                    ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>