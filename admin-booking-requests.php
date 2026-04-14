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

function ddAdminBookingRequestsE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminBookingRequestsQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminBookingRequestsRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminBookingRequestsTableExists(PDO $pdo, string $table): bool
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

function ddAdminBookingRequestsGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminBookingRequestsTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminBookingRequestsQuoteIdentifier($table) . ')');
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

function ddAdminBookingRequestsHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminBookingRequestsFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminBookingRequestsSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminBookingRequestsSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminBookingRequestsCsrfToken(): string
{
    if (empty($_SESSION['admin_booking_requests_csrf']) || !is_string($_SESSION['admin_booking_requests_csrf'])) {
        $_SESSION['admin_booking_requests_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_booking_requests_csrf'];
}

function ddAdminBookingRequestsValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_booking_requests_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminBookingRequestsNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'approved') {
        return 'approved';
    }

    if ($status === 'declined' || $status === 'rejected') {
        return 'declined';
    }

    return 'pending';
}

function ddAdminBookingRequestsStatusLabel(string $status): string
{
    return ucfirst(ddAdminBookingRequestsNormalizeStatus($status));
}

function ddAdminBookingRequestsStatusClass(string $status): string
{
    return match (ddAdminBookingRequestsNormalizeStatus($status)) {
        'approved' => 'status approved',
        'declined' => 'status declined',
        default => 'status pending',
    };
}

function ddAdminBookingRequestsNormalizeRequestType(string $requestType): string
{
    $requestType = strtolower(trim($requestType));

    if ($requestType === 'cancel' || $requestType === 'cancellation') {
        return 'cancel';
    }

    if ($requestType === 'reschedule' || $requestType === 'rescheduled') {
        return 'reschedule';
    }

    return $requestType !== '' ? $requestType : 'request';
}

function ddAdminBookingRequestsRequestTypeLabel(string $requestType): string
{
    $requestType = ddAdminBookingRequestsNormalizeRequestType($requestType);

    return match ($requestType) {
        'cancel' => 'Cancel',
        'reschedule' => 'Reschedule',
        default => ucfirst(str_replace('_', ' ', $requestType)),
    };
}

function ddAdminBookingRequestsFormatDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
}

function ddAdminBookingRequestsBuildScheduleText(?string $date, ?string $time): string
{
    $date = trim((string) $date);
    $time = trim((string) $time);

    if ($date === '' && $time === '') {
        return '—';
    }

    if ($date !== '' && $time !== '') {
        return $date . ' · ' . $time;
    }

    return $date !== '' ? $date : $time;
}

function ddAdminBookingRequestsDetectBookingSource(PDO $pdo): ?array
{
    foreach (array('bookings', 'walks') as $table) {
        if (!ddAdminBookingRequestsTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminBookingRequestsGetColumns($pdo, $table);
        $idColumn = ddAdminBookingRequestsFirstExistingColumn($columns, array('id', 'booking_id', 'walk_id'));

        if ($idColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'status_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('status')),
            'service_date_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('service_date', 'booking_date', 'date', 'walk_date', 'appointment_date')),
            'service_time_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('service_time', 'booking_time', 'time', 'start_time')),
            'updated_by_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('status_updated_by', 'updated_by')),
            'updated_at_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('status_updated_at', 'updated_at')),
        );
    }

    return null;
}

function ddAdminBookingRequestsDetectUserSource(PDO $pdo): ?array
{
    foreach (array('users', 'members', 'client_profiles') as $table) {
        if (!ddAdminBookingRequestsTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminBookingRequestsGetColumns($pdo, $table);
        $idColumn = ddAdminBookingRequestsFirstExistingColumn($columns, array('id', 'user_id', 'member_id', 'client_id'));

        if ($idColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'email_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('email')),
            'name_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('full_name', 'name', 'username', 'client_name')),
            'first_name_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('first_name')),
            'last_name_column' => ddAdminBookingRequestsFirstExistingColumn($columns, array('last_name')),
        );
    }

    return null;
}

function ddAdminBookingRequestsBuildUserLabel(array $row, array $userSource): string
{
    $nameColumn = $userSource['name_column'];
    if ($nameColumn !== null && !empty($row[$nameColumn])) {
        return trim((string) $row[$nameColumn]);
    }

    $firstNameColumn = $userSource['first_name_column'];
    $lastNameColumn = $userSource['last_name_column'];

    $first = $firstNameColumn !== null ? trim((string) ($row[$firstNameColumn] ?? '')) : '';
    $last = $lastNameColumn !== null ? trim((string) ($row[$lastNameColumn] ?? '')) : '';
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    $emailColumn = $userSource['email_column'];
    if ($emailColumn !== null && !empty($row[$emailColumn])) {
        return trim((string) $row[$emailColumn]);
    }

    return 'Unknown user';
}

function ddAdminBookingRequestsFindUserLabel(PDO $pdo, ?array $userSource, int $userId): string
{
    if ($userSource === null || $userId <= 0) {
        return 'Unknown user';
    }

    $row = ddAdminBookingRequestsSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminBookingRequestsQuoteIdentifier((string) $userSource['table'])
        . ' WHERE ' . ddAdminBookingRequestsQuoteIdentifier((string) $userSource['id_column']) . ' = :id LIMIT 1',
        array(':id' => $userId)
    );

    if ($row === null) {
        return 'Unknown user';
    }

    return ddAdminBookingRequestsBuildUserLabel($row, $userSource);
}

function ddAdminBookingRequestsFetchRequests(PDO $pdo, array $requestColumns, string $currentFilter): array
{
    $selectParts = array('r.id');
    $optionalRequestCols = array(
        'booking_id',
        'user_id',
        'request_type',
        'current_service_date',
        'current_service_time',
        'requested_service_date',
        'requested_service_time',
        'note',
        'notes',
        'status',
        'created_at',
    );

    foreach ($optionalRequestCols as $column) {
        if (ddAdminBookingRequestsHasColumn($requestColumns, $column)) {
            $selectParts[] = 'r.' . ddAdminBookingRequestsQuoteIdentifier($column) . ' AS ' . ddAdminBookingRequestsQuoteIdentifier($column);
        }
    }

    $sql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . ddAdminBookingRequestsQuoteIdentifier('booking_change_requests') . ' r';

    $params = array();

    if ($currentFilter !== 'all' && ddAdminBookingRequestsHasColumn($requestColumns, 'status')) {
        $sql .= ' WHERE LOWER(COALESCE(r.' . ddAdminBookingRequestsQuoteIdentifier('status') . ", 'pending')) = :status_filter";
        $params[':status_filter'] = strtolower($currentFilter);
    }

    if (ddAdminBookingRequestsHasColumn($requestColumns, 'created_at')) {
        $sql .= ' ORDER BY r.' . ddAdminBookingRequestsQuoteIdentifier('created_at') . ' DESC, r.' . ddAdminBookingRequestsQuoteIdentifier('id') . ' DESC';
    } else {
        $sql .= ' ORDER BY r.' . ddAdminBookingRequestsQuoteIdentifier('id') . ' DESC';
    }

    return ddAdminBookingRequestsSafeFetchAll($pdo, $sql, $params);
}

function ddAdminBookingRequestsFetchSummary(PDO $pdo, array $requestColumns): array
{
    $summary = array(
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'declined' => 0,
    );

    if (!ddAdminBookingRequestsHasColumn($requestColumns, 'status')) {
        $row = ddAdminBookingRequestsSafeFetchOne(
            $pdo,
            'SELECT COUNT(*) AS total_count FROM ' . ddAdminBookingRequestsQuoteIdentifier('booking_change_requests')
        );

        $summary['total'] = (int) ($row['total_count'] ?? 0);
        return $summary;
    }

    $row = ddAdminBookingRequestsSafeFetchOne(
        $pdo,
        'SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN LOWER(COALESCE(' . ddAdminBookingRequestsQuoteIdentifier('status') . ", 'pending')) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(COALESCE(" . ddAdminBookingRequestsQuoteIdentifier('status') . ", '')) = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN LOWER(COALESCE(" . ddAdminBookingRequestsQuoteIdentifier('status') . ", '')) IN ('declined', 'rejected') THEN 1 ELSE 0 END) AS declined_count
         FROM " . ddAdminBookingRequestsQuoteIdentifier('booking_change_requests')
    );

    if ($row !== null) {
        $summary['total'] = (int) ($row['total_count'] ?? 0);
        $summary['pending'] = (int) ($row['pending_count'] ?? 0);
        $summary['approved'] = (int) ($row['approved_count'] ?? 0);
        $summary['declined'] = (int) ($row['declined_count'] ?? 0);
    }

    return $summary;
}

$successMessage = '';
$errorMessage = '';

if (!ddAdminBookingRequestsTableExists($pdo, 'booking_change_requests')) {
    http_response_code(500);
    echo 'booking_change_requests table not found.';
    exit;
}

$requestColumns = ddAdminBookingRequestsGetColumns($pdo, 'booking_change_requests');
$bookingSource = ddAdminBookingRequestsDetectBookingSource($pdo);
$userSource = ddAdminBookingRequestsDetectUserSource($pdo);

$allowedFilterStatuses = array('all', 'pending', 'approved', 'declined');
$currentFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($currentFilter, $allowedFilterStatuses, true)) {
    $currentFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = strtolower(trim((string) ($_POST['action_type'] ?? '')));
    $redirectStatus = urlencode($currentFilter);

    if (!ddAdminBookingRequestsValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        ddAdminBookingRequestsRedirect('admin-booking-requests.php?error=csrf&status=' . $redirectStatus);
    }

    if ($requestId <= 0 || !in_array($action, array('approve', 'decline'), true)) {
        ddAdminBookingRequestsRedirect('admin-booking-requests.php?error=1&status=' . $redirectStatus);
    }

    $selectParts = array('id');
    foreach (array(
        'booking_id',
        'request_type',
        'requested_service_date',
        'requested_service_time',
        'status',
    ) as $column) {
        if (ddAdminBookingRequestsHasColumn($requestColumns, $column)) {
            $selectParts[] = ddAdminBookingRequestsQuoteIdentifier($column);
        }
    }

    $request = ddAdminBookingRequestsSafeFetchOne(
        $pdo,
        'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . ddAdminBookingRequestsQuoteIdentifier('booking_change_requests')
        . ' WHERE ' . ddAdminBookingRequestsQuoteIdentifier('id') . ' = :id LIMIT 1',
        array(':id' => $requestId)
    );

    if ($request === null) {
        ddAdminBookingRequestsRedirect('admin-booking-requests.php?error=1&status=' . $redirectStatus);
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'approve' && $bookingSource !== null && !empty($request['booking_id']) && isset($request['request_type'])) {
            $bookingId = (int) $request['booking_id'];
            $requestType = ddAdminBookingRequestsNormalizeRequestType((string) $request['request_type']);

            if ($bookingId > 0) {
                $updateParts = array();
                $params = array(':booking_id' => $bookingId);

                if ($requestType === 'cancel') {
                    if ($bookingSource['status_column'] !== null) {
                        $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['status_column']) . ' = :booking_status';
                        $params[':booking_status'] = 'cancelled';
                    }
                } elseif ($requestType === 'reschedule') {
                    if ($bookingSource['service_date_column'] !== null && !empty($request['requested_service_date'])) {
                        $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['service_date_column']) . ' = :service_date';
                        $params[':service_date'] = (string) $request['requested_service_date'];
                    }

                    if ($bookingSource['service_time_column'] !== null && !empty($request['requested_service_time'])) {
                        $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['service_time_column']) . ' = :service_time';
                        $params[':service_time'] = (string) $request['requested_service_time'];
                    }

                    if ($bookingSource['status_column'] !== null) {
                        $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['status_column']) . ' = :booking_status';
                        $params[':booking_status'] = 'confirmed';
                    }
                }

                if ($bookingSource['updated_by_column'] !== null) {
                    $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['updated_by_column']) . ' = :updated_by';
                    $params[':updated_by'] = 'admin';
                }

                if ($bookingSource['updated_at_column'] !== null) {
                    $updateParts[] = ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['updated_at_column']) . ' = CURRENT_TIMESTAMP';
                }

                if (!empty($updateParts)) {
                    $sql = 'UPDATE ' . ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['table'])
                        . ' SET ' . implode(', ', $updateParts)
                        . ' WHERE ' . ddAdminBookingRequestsQuoteIdentifier((string) $bookingSource['id_column']) . ' = :booking_id';

                    $stmt = $pdo->prepare($sql);

                    foreach ($params as $placeholder => $value) {
                        if (is_int($value)) {
                            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                        } elseif ($value === null) {
                            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                        } else {
                            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                        }
                    }

                    $stmt->execute();
                }
            }
        }

        if (ddAdminBookingRequestsHasColumn($requestColumns, 'status')) {
            $requestUpdateParts = array(
                ddAdminBookingRequestsQuoteIdentifier('status') . ' = :request_status',
            );
            $requestUpdateParams = array(
                ':request_status' => $action === 'approve' ? 'Approved' : 'Declined',
                ':request_id' => $requestId,
            );

            $requestUpdatedByColumn = ddAdminBookingRequestsFirstExistingColumn($requestColumns, array('status_updated_by', 'updated_by', 'reviewed_by'));
            $requestUpdatedAtColumn = ddAdminBookingRequestsFirstExistingColumn($requestColumns, array('status_updated_at', 'updated_at', 'reviewed_at'));

            if ($requestUpdatedByColumn !== null) {
                $requestUpdateParts[] = ddAdminBookingRequestsQuoteIdentifier($requestUpdatedByColumn) . ' = :request_updated_by';
                $requestUpdateParams[':request_updated_by'] = 'admin';
            }

            if ($requestUpdatedAtColumn !== null) {
                $requestUpdateParts[] = ddAdminBookingRequestsQuoteIdentifier($requestUpdatedAtColumn) . ' = CURRENT_TIMESTAMP';
            }

            $sql = 'UPDATE ' . ddAdminBookingRequestsQuoteIdentifier('booking_change_requests')
                . ' SET ' . implode(', ', $requestUpdateParts)
                . ' WHERE ' . ddAdminBookingRequestsQuoteIdentifier('id') . ' = :request_id';

            $stmt = $pdo->prepare($sql);

            foreach ($requestUpdateParams as $placeholder => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                }
            }

            $stmt->execute();
        }

        $pdo->commit();
        ddAdminBookingRequestsRedirect('admin-booking-requests.php?updated=1&status=' . $redirectStatus);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        ddAdminBookingRequestsRedirect('admin-booking-requests.php?error=1&status=' . $redirectStatus);
    }
}

if (isset($_GET['updated'])) {
    $successMessage = 'Booking request updated successfully.';
} elseif (isset($_GET['error'])) {
    $errorCode = (string) $_GET['error'];
    $errorMessage = $errorCode === 'csrf'
        ? 'Security check failed. Please refresh the page and try again.'
        : 'Something went wrong while updating the booking request.';
}

$requests = ddAdminBookingRequestsFetchRequests($pdo, $requestColumns, $currentFilter);
$summary = ddAdminBookingRequestsFetchSummary($pdo, $requestColumns);

foreach ($requests as $index => $request) {
    $userId = (int) ($request['user_id'] ?? 0);
    $requests[$index]['user_label'] = ddAdminBookingRequestsFindUserLabel($pdo, $userSource, $userId);

    $rawStatus = (string) ($request['status'] ?? 'Pending');
    $requests[$index]['normalized_status'] = ddAdminBookingRequestsNormalizeStatus($rawStatus);
    $requests[$index]['status_label'] = ddAdminBookingRequestsStatusLabel($rawStatus);

    $rawRequestType = (string) ($request['request_type'] ?? 'Request');
    $requests[$index]['request_type_label'] = ddAdminBookingRequestsRequestTypeLabel($rawRequestType);

    $requestNotes = trim((string) ($request['note'] ?? ''));
    if ($requestNotes === '' && isset($request['notes'])) {
        $requestNotes = trim((string) $request['notes']);
    }
    $requests[$index]['display_notes'] = $requestNotes;

    $requests[$index]['current_schedule'] = ddAdminBookingRequestsBuildScheduleText(
        isset($request['current_service_date']) ? (string) $request['current_service_date'] : '',
        isset($request['current_service_time']) ? (string) $request['current_service_time'] : ''
    );

    $requests[$index]['requested_schedule'] = ddAdminBookingRequestsBuildScheduleText(
        isset($request['requested_service_date']) ? (string) $request['requested_service_date'] : '',
        isset($request['requested_service_time']) ? (string) $request['requested_service_time'] : ''
    );

    $requests[$index]['created_at_display'] = ddAdminBookingRequestsFormatDateTime(isset($request['created_at']) ? (string) $request['created_at'] : '');
}

$csrfToken = ddAdminBookingRequestsCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Booking Requests | Doggie Dorian’s</title>
  <meta name="description" content="Review and manage booking change requests in the Doggie Dorian’s admin area.">
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --green: #5ed39a;
      --red: #ff9898;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 26px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 42px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 760px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .flash {
      margin-bottom: 22px;
      padding: 15px 18px;
      border-radius: 16px;
      font-weight: 700;
      box-shadow: var(--shadow);
    }

    .flash.success {
      background: rgba(94,211,154,0.12);
      border: 1px solid rgba(94,211,154,0.30);
      color: #c8ffe2;
    }

    .flash.error {
      background: rgba(255,152,152,0.12);
      border: 1px solid rgba(255,152,152,0.28);
      color: #ffd7d7;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border: 1px solid var(--line);
      border-radius: 20px;
      padding: 20px;
      box-shadow: var(--shadow);
    }

    .card-label {
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .card-value {
      font-size: 34px;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .card-note {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 22px 22px 18px;
      border-bottom: 1px solid var(--line);
      flex-wrap: wrap;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
    }

    .panel-title {
      font-size: 24px;
      font-weight: 800;
      margin: 0;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-top: 6px;
    }

    .filters {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .filter-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 0 16px;
      border-radius: 999px;
      text-decoration: none;
      color: var(--text);
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      font-weight: 700;
      font-size: 14px;
    }

    .filter-pill.active {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1320px;
    }

    thead th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      text-align: left;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      background: rgba(255,255,255,0.01);
    }

    tbody td {
      padding: 18px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
    }

    tbody tr:hover {
      background: rgba(255,255,255,0.015);
    }

    .primary-text {
      font-weight: 700;
      font-size: 15px;
      color: var(--text);
    }

    .secondary-text {
      margin-top: 6px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
      white-space: pre-wrap;
    }

    .status {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 12px;
      font-weight: 800;
      text-transform: capitalize;
      letter-spacing: 0.04em;
    }

    .status.pending {
      background: rgba(143,149,163,0.18);
      color: #e5e7ee;
    }

    .status.approved {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.declined {
      background: rgba(255,152,152,0.15);
      color: #ffd0d0;
    }

    .actions {
      display: grid;
      gap: 8px;
    }

    .actions form {
      margin: 0;
    }

    .actions button {
      width: 100%;
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      font-weight: 800;
      cursor: pointer;
    }

    .actions button:hover {
      border-color: rgba(212,175,55,0.26);
    }

    .empty-state {
      padding: 44px 22px;
      text-align: center;
      color: var(--muted);
    }

    .empty-state strong {
      display: block;
      color: var(--text);
      font-size: 18px;
      margin-bottom: 10px;
    }

    @media (max-width: 1100px) {
      .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 700px) {
      .summary-grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 32px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Booking Requests</h1>
        <div class="subtext">
          Review reschedule and cancellation requests submitted by members, then approve or decline them while keeping the main bookings table in sync.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-nav.php" class="top-btn">Admin Nav</a>
        <a href="admin-bookings.php" class="top-btn">Main Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="flash success">
        <?php echo ddAdminBookingRequestsE($successMessage); ?>
      </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
      <div class="flash error">
        <?php echo ddAdminBookingRequestsE($errorMessage); ?>
      </div>
    <?php endif; ?>

    <div class="summary-grid">
      <div class="card">
        <div class="card-label">Total Requests</div>
        <div class="card-value"><?php echo (int) $summary['total']; ?></div>
        <div class="card-note">All change requests received</div>
      </div>

      <div class="card">
        <div class="card-label">Pending</div>
        <div class="card-value"><?php echo (int) $summary['pending']; ?></div>
        <div class="card-note">Requests awaiting review</div>
      </div>

      <div class="card">
        <div class="card-label">Approved</div>
        <div class="card-value"><?php echo (int) $summary['approved']; ?></div>
        <div class="card-note">Requests approved by admin</div>
      </div>

      <div class="card">
        <div class="card-label">Declined</div>
        <div class="card-value"><?php echo (int) $summary['declined']; ?></div>
        <div class="card-note">Requests declined by admin</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Request Queue</h2>
          <div class="panel-subtitle">
            Review and resolve incoming booking changes without leaving the admin workflow.
          </div>
        </div>

        <div class="filters">
          <a class="filter-pill <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=all">All</a>
          <a class="filter-pill <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=pending">Pending</a>
          <a class="filter-pill <?php echo $currentFilter === 'approved' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=approved">Approved</a>
          <a class="filter-pill <?php echo $currentFilter === 'declined' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=declined">Declined</a>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$requests): ?>
          <div class="empty-state">
            <strong>No booking requests found</strong>
            There are no requests in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Member</th>
                <th>Booking</th>
                <th>Request Type</th>
                <th>Current Schedule</th>
                <th>Requested Schedule</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <tr>
                  <td>
                    <div class="primary-text">#<?php echo (int) $request['id']; ?></div>
                    <div class="secondary-text"><?php echo ddAdminBookingRequestsE($request['created_at_display']); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo ddAdminBookingRequestsE((string) ($request['user_label'] ?? 'Unknown user')); ?></div>
                    <div class="secondary-text">User ID: <?php echo (int) ($request['user_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text">Booking #<?php echo (int) ($request['booking_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo ddAdminBookingRequestsE((string) $request['request_type_label']); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo ddAdminBookingRequestsE((string) $request['current_schedule']); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo ddAdminBookingRequestsE((string) $request['requested_schedule']); ?></div>
                  </td>

                  <td>
                    <div class="secondary-text"><?php echo ddAdminBookingRequestsE((string) $request['display_notes']); ?></div>
                  </td>

                  <td>
                    <span class="<?php echo ddAdminBookingRequestsE(ddAdminBookingRequestsStatusClass((string) $request['normalized_status'])); ?>">
                      <?php echo ddAdminBookingRequestsE((string) $request['status_label']); ?>
                    </span>
                  </td>

                  <td>
                    <div class="actions">
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo ddAdminBookingRequestsE($csrfToken); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="action_type" value="approve">
                        <button type="submit">Approve</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo ddAdminBookingRequestsE($csrfToken); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="action_type" value="decline">
                        <button type="submit">Decline</button>
                      </form>

                      <a href="admin-edit-booking.php?id=<?php echo (int) ($request['booking_id'] ?? 0); ?>" class="top-btn" style="min-height:36px; padding:0 14px; border-radius:10px; justify-content:center;">
                        Edit Booking
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>