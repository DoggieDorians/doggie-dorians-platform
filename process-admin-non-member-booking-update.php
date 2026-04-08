<?php
session_start();
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/New_York');

$pdoConnection = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $pdoConnection = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $pdoConnection = $db;
}

if (!$pdoConnection instanceof PDO) {
    $_SESSION['admin_nonmember_flash_type'] = 'error';
    $_SESSION['admin_nonmember_flash_message'] = 'Database connection not available.';
    header('Location: admin-non-member-bookings.php');
    exit;
}

$pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Admin protection
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userRole = $_SESSION['role'] ?? 'member';
if ($userRole !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-non-member-bookings.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function redirect_to_booking(int $bookingId, string $type, string $message): void
{
    $_SESSION['admin_nonmember_flash_type'] = $type;
    $_SESSION['admin_nonmember_flash_message'] = $message;
    header('Location: admin-non-member-booking-view.php?id=' . $bookingId);
    exit;
}

function redirect_to_list(string $type, string $message): void
{
    $_SESSION['admin_nonmember_flash_type'] = $type;
    $_SESSION['admin_nonmember_flash_message'] = $message;
    header('Location: admin-non-member-bookings.php');
    exit;
}

function booking_reference(): string
{
    return 'DDNM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT name
        FROM sqlite_master
        WHERE type = 'table'
          AND name = :table
        LIMIT 1
    ");
    $stmt->execute([':table' => $tableName]);

    return (bool) $stmt->fetchColumn();
}

function get_table_columns(PDO $pdo, string $tableName): array
{
    $columns = [];
    $stmt = $pdo->query("PRAGMA table_info(" . $tableName . ")");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        if (!empty($row['name'])) {
            $columns[] = $row['name'];
        }
    }

    return $columns;
}

function add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $definition, array $existingColumns): array
{
    if (!in_array($columnName, $existingColumns, true)) {
        $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$definition}");
        $existingColumns[] = $columnName;
    }

    return $existingColumns;
}

function ensure_non_member_bookings_table(PDO $pdo): void
{
    if (!table_exists($pdo, 'non_member_bookings')) {
        $pdo->exec("
            CREATE TABLE non_member_bookings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_reference TEXT UNIQUE,
                booking_source TEXT DEFAULT 'non-member',
                status TEXT NOT NULL DEFAULT 'Pending',

                full_name TEXT NOT NULL,
                phone TEXT,
                email TEXT NOT NULL,

                service_type TEXT NOT NULL,
                dog_name TEXT NOT NULL,
                dog_size TEXT,

                walk_duration INTEGER,
                preferred_walk_time TEXT,

                dropin_hours INTEGER,
                dropin_preferred_time TEXT,

                dropin_walk_duration INTEGER,
                include_second_walk TEXT DEFAULT 'No',
                second_walk_duration INTEGER,
                dropin_walk_preferred_time TEXT,

                date_start TEXT NOT NULL,
                date_end TEXT,

                feeding_schedule TEXT,
                preferred_contact TEXT,
                notes TEXT,

                estimated_price REAL NOT NULL DEFAULT 0,

                pricing_type TEXT,
                unit_price REAL,
                discount_label TEXT,
                quantity INTEGER,

                raw_form_json TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        return;
    }

    $columns = get_table_columns($pdo, 'non_member_bookings');

    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'booking_reference', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'booking_source', "TEXT DEFAULT 'non-member'", $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'dropin_hours', 'INTEGER', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'dropin_preferred_time', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'dropin_walk_duration', 'INTEGER', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'include_second_walk', "TEXT DEFAULT 'No'", $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'second_walk_duration', 'INTEGER', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'dropin_walk_preferred_time', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'pricing_type', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'unit_price', 'REAL', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'discount_label', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'quantity', 'INTEGER', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'raw_form_json', 'TEXT', $columns);
    $columns = add_column_if_missing($pdo, 'non_member_bookings', 'updated_at', "TEXT DEFAULT CURRENT_TIMESTAMP", $columns);

    if (in_array('booking_source', $columns, true)) {
        $pdo->exec("UPDATE non_member_bookings SET booking_source = 'non-member' WHERE booking_source IS NULL OR booking_source = ''");
    }

    if (in_array('booking_reference', $columns, true)) {
        $rows = $pdo->query("SELECT id FROM non_member_bookings WHERE booking_reference IS NULL OR booking_reference = ''")->fetchAll();
        foreach ($rows as $row) {
            $ref = booking_reference();
            $stmt = $pdo->prepare("UPDATE non_member_bookings SET booking_reference = :ref WHERE id = :id");
            $stmt->execute([
                ':ref' => $ref,
                ':id' => (int) $row['id'],
            ]);
        }
    }

    if (in_array('updated_at', $columns, true)) {
        $pdo->exec("UPDATE non_member_bookings SET updated_at = created_at WHERE updated_at IS NULL OR updated_at = ''");
    }
}

ensure_non_member_bookings_table($pdoConnection);

/*
|--------------------------------------------------------------------------
| Validate input
|--------------------------------------------------------------------------
*/
$bookingId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$newStatus = trim((string) ($_POST['status'] ?? ''));

$allowedStatuses = ['Pending', 'Confirmed', 'Scheduled', 'Completed', 'Cancelled'];

if ($bookingId <= 0) {
    redirect_to_list('error', 'Invalid non-member booking ID.');
}

if (!in_array($newStatus, $allowedStatuses, true)) {
    redirect_to_booking($bookingId, 'error', 'Invalid booking status selected.');
}

/*
|--------------------------------------------------------------------------
| Confirm booking exists and belongs to non-member flow
|--------------------------------------------------------------------------
*/
$stmt = $pdoConnection->prepare("
    SELECT id, booking_reference, status
    FROM non_member_bookings
    WHERE id = :id
      AND COALESCE(booking_source, 'non-member') = 'non-member'
    LIMIT 1
");
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    redirect_to_list('error', 'Non-member booking not found.');
}

$currentStatus = (string) ($booking['status'] ?? 'Pending');

if ($currentStatus === $newStatus) {
    redirect_to_booking(
        $bookingId,
        'success',
        'Status remains ' . $newStatus . '. No additional changes were needed.'
    );
}

/*
|--------------------------------------------------------------------------
| Update booking status
|--------------------------------------------------------------------------
*/
try {
    $updateStmt = $pdoConnection->prepare("
        UPDATE non_member_bookings
        SET
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
          AND COALESCE(booking_source, 'non-member') = 'non-member'
    ");

    $updateStmt->execute([
        ':status' => $newStatus,
        ':id' => $bookingId,
    ]);

    redirect_to_booking(
        $bookingId,
        'success',
        'Non-member booking status updated successfully to ' . $newStatus . '.'
    );
} catch (Throwable $e) {
    redirect_to_booking(
        $bookingId,
        'error',
        'Unable to update this non-member booking right now.'
    );
}
?>