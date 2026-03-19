<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/db.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map(static fn($row) => $row['name'] ?? '', $rows)));
    } catch (Throwable $e) {
        return [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function formatDisplayDate(?string $date): string
{
    $date = trim((string) $date);

    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y', $timestamp);
}

function formatDisplayTime(?string $time): string
{
    $time = trim((string) $time);

    if ($time === '') {
        return 'N/A';
    }

    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
    }

    return date('g:i A', $timestamp);
}

function formatDisplayDateTime(?string $dateTime): string
{
    $dateTime = trim((string) $dateTime);

    if ($dateTime === '') {
        return 'N/A';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return htmlspecialchars($dateTime, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatMoney(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return 'N/A';
    }

    return '$' . number_format((float) $amount, 2);
}

function formatServiceName(string $service): string
{
    $service = trim($service);
    if ($service === '') {
        return 'Service';
    }

    return ucwords(str_replace(['-', '_'], ' ', $service));
}

function formatStatusClass(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'approved', 'confirmed', 'completed', 'active' => 'status-positive',
        'pending', 'requested', 'scheduled', 'new' => 'status-neutral',
        'declined', 'cancelled', 'canceled' => 'status-negative',
        default => 'status-default',
    };
}

function formatRequestType(string $type): string
{
    $type = strtolower(trim($type));

    return match ($type) {
        'cancel' => 'Cancellation Request',
        'reschedule' => 'Reschedule Request',
        default => ucwords(str_replace(['-', '_'], ' ', $type)),
    };
}

function createBookingChangeRequestsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            request_type TEXT NOT NULL,
            current_service_date TEXT,
            current_service_time TEXT,
            requested_service_date TEXT,
            requested_service_time TEXT,
            note TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function normalizeStatus(?string $status): string
{
    return strtolower(trim((string) $status));
}

function recordMatchesSearch(array $record, array $fields, string $search): bool
{
    if ($search === '') {
        return true;
    }

    foreach ($fields as $field) {
        $value = strtolower(trim((string) ($record[$field] ?? '')));
        if ($value !== '' && str_contains($value, $search)) {
            return true;
        }
    }

    return false;
}

function recordMatchesStatus(array $record, string $statusField, string $statusFilter): bool
{
    if ($statusFilter === '') {
        return true;
    }

    return normalizeStatus((string) ($record[$statusField] ?? '')) === $statusFilter;
}

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$memberBookings = [];
$publicBookings = [];
$changeRequests = [];

$search = strtolower(trim((string) ($_GET['search'] ?? '')));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$sourceFilter = strtolower(trim((string) ($_GET['source'] ?? 'all')));

$allowedSourceFilters = ['all', 'member', 'requests', 'public'];
if (!in_array($sourceFilter, $allowedSourceFilters, true)) {
    $sourceFilter = 'all';
}

try {
    $pdo = getDatabaseConnection();
    createBookingChangeRequestsTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $actionType = trim((string) ($_POST['action_type'] ?? ''));

        if ($actionType === 'update_member_booking_status') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));

            $allowedStatuses = ['Requested', 'Pending', 'Scheduled', 'Confirmed', 'Completed', 'Cancelled'];

            if ($bookingId <= 0) {
                $errorMessage = 'Invalid member booking ID.';
            } elseif (!in_array($newStatus, $allowedStatuses, true)) {
                $errorMessage = 'Invalid member booking status.';
            } elseif (!tableExists($pdo, 'bookings')) {
                $errorMessage = 'Bookings table not found.';
            } else {
                $columns = getTableColumns($pdo, 'bookings');

                if (!hasColumn($columns, 'status')) {
                    $errorMessage = 'Bookings table is missing the status column.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE bookings
                        SET status = :status
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'status' => $newStatus,
                        'id' => $bookingId,
                    ]);

                    $successMessage = 'Member booking status updated successfully.';
                }
            }
        } elseif ($actionType === 'update_public_booking_status') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));

            $allowedStatuses = ['New', 'Requested', 'Pending', 'Scheduled', 'Confirmed', 'Completed', 'Cancelled'];

            if ($bookingId <= 0) {
                $errorMessage = 'Invalid public booking ID.';
            } elseif (!in_array($newStatus, $allowedStatuses, true)) {
                $errorMessage = 'Invalid public booking status.';
            } elseif (!tableExists($pdo, 'public_booking_requests')) {
                $errorMessage = 'Public booking requests table not found.';
            } else {
                $columns = getTableColumns($pdo, 'public_booking_requests');

                if (!hasColumn($columns, 'status')) {
                    $errorMessage = 'Public booking requests table is missing the status column.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE public_booking_requests
                        SET status = :status
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'status' => $newStatus,
                        'id' => $bookingId,
                    ]);

                    $successMessage = 'Public booking status updated successfully.';
                }
            }
        } elseif ($actionType === 'review_change_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $adminAction = strtolower(trim((string) ($_POST['admin_action'] ?? '')));

            if ($requestId <= 0) {
                $errorMessage = 'Invalid change request ID.';
            } elseif (!in_array($adminAction, ['approve', 'decline'], true)) {
                $errorMessage = 'Invalid change request action.';
            } else {
                $requestStmt = $pdo->prepare("
                    SELECT *
                    FROM booking_change_requests
                    WHERE id = :id
                    LIMIT 1
                ");
                $requestStmt->execute(['id' => $requestId]);
                $requestRow = $requestStmt->fetch();

                if (!$requestRow) {
                    $errorMessage = 'Change request not found.';
                } elseif (strtolower((string) ($requestRow['status'] ?? '')) !== 'pending') {
                    $errorMessage = 'This request has already been reviewed.';
                } elseif (!tableExists($pdo, 'bookings')) {
                    $errorMessage = 'Bookings table not found.';
                } else {
                    $bookingColumns = getTableColumns($pdo, 'bookings');

                    try {
                        $pdo->beginTransaction();

                        if ($adminAction === 'approve') {
                            $requestType = strtolower(trim((string) ($requestRow['request_type'] ?? '')));
                            $bookingId = (int) ($requestRow['booking_id'] ?? 0);

                            if ($requestType === 'cancel') {
                                if (!hasColumn($bookingColumns, 'status')) {
                                    throw new RuntimeException('Bookings table is missing the status column.');
                                }

                                $updateBooking = $pdo->prepare("
                                    UPDATE bookings
                                    SET status = :status
                                    WHERE id = :booking_id
                                ");
                                $updateBooking->execute([
                                    'status' => 'Cancelled',
                                    'booking_id' => $bookingId,
                                ]);
                            } elseif ($requestType === 'reschedule') {
                                if (!hasColumn($bookingColumns, 'service_date')) {
                                    throw new RuntimeException('Bookings table is missing the service_date column.');
                                }

                                $requestedDate = trim((string) ($requestRow['requested_service_date'] ?? ''));
                                $requestedTime = trim((string) ($requestRow['requested_service_time'] ?? ''));

                                if ($requestedDate === '') {
                                    throw new RuntimeException('Reschedule request is missing a requested date.');
                                }

                                $setParts = ['service_date = :service_date'];
                                $params = [
                                    'service_date' => $requestedDate,
                                    'booking_id' => $bookingId,
                                ];

                                if (hasColumn($bookingColumns, 'service_time') && $requestedTime !== '') {
                                    $setParts[] = 'service_time = :service_time';
                                    $params['service_time'] = $requestedTime;
                                }

                                if (hasColumn($bookingColumns, 'status')) {
                                    $setParts[] = 'status = :status';
                                    $params['status'] = 'Scheduled';
                                }

                                $sql = "
                                    UPDATE bookings
                                    SET " . implode(', ', $setParts) . "
                                    WHERE id = :booking_id
                                ";

                                $updateBooking = $pdo->prepare($sql);
                                $updateBooking->execute($params);
                            } else {
                                throw new RuntimeException('Unknown request type.');
                            }

                            $updateRequest = $pdo->prepare("
                                UPDATE booking_change_requests
                                SET status = 'Approved'
                                WHERE id = :id
                            ");
                            $updateRequest->execute(['id' => $requestId]);

                            $pdo->commit();
                            $successMessage = 'Change request approved and booking updated successfully.';
                        } else {
                            $updateRequest = $pdo->prepare("
                                UPDATE booking_change_requests
                                SET status = 'Declined'
                                WHERE id = :id
                            ");
                            $updateRequest->execute(['id' => $requestId]);

                            $pdo->commit();
                            $successMessage = 'Change request declined successfully.';
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errorMessage = $e->getMessage();
                    }
                }
            }
        }
    }

    if (tableExists($pdo, 'bookings')) {
        $bookingColumns = getTableColumns($pdo, 'bookings');
        $petsColumns = tableExists($pdo, 'pets') ? getTableColumns($pdo, 'pets') : [];
        $usersColumns = tableExists($pdo, 'users') ? getTableColumns($pdo, 'users') : [];
        $membersColumns = tableExists($pdo, 'members') ? getTableColumns($pdo, 'members') : [];

        $petJoin = '';
        $petSelect = "NULL AS booking_pet_name";

        if (
            tableExists($pdo, 'pets') &&
            hasColumn($bookingColumns, 'pet_id') &&
            hasColumn($petsColumns, 'id') &&
            hasColumn($petsColumns, 'pet_name')
        ) {
            $petJoin = " LEFT JOIN pets p ON b.pet_id = p.id ";
            $petSelect = "p.pet_name AS booking_pet_name";
        } elseif (hasColumn($bookingColumns, 'pet_name')) {
            $petSelect = "b.pet_name AS booking_pet_name";
        }

        $memberNameSelect = "NULL AS member_full_name";

        if (tableExists($pdo, 'users')) {
            if (hasColumn($usersColumns, 'full_name') && hasColumn($usersColumns, 'id')) {
                $memberNameSelect = "(SELECT u.full_name FROM users u WHERE u.id = b.user_id LIMIT 1) AS member_full_name";
            } elseif (hasColumn($usersColumns, 'name') && hasColumn($usersColumns, 'id')) {
                $memberNameSelect = "(SELECT u.name FROM users u WHERE u.id = b.user_id LIMIT 1) AS member_full_name";
            }
        } elseif (tableExists($pdo, 'members')) {
            if (hasColumn($membersColumns, 'full_name') && hasColumn($membersColumns, 'id')) {
                $memberNameSelect = "(SELECT m.full_name FROM members m WHERE m.id = b.user_id LIMIT 1) AS member_full_name";
            } elseif (hasColumn($membersColumns, 'name') && hasColumn($membersColumns, 'id')) {
                $memberNameSelect = "(SELECT m.name FROM members m WHERE m.id = b.user_id LIMIT 1) AS member_full_name";
            }
        }

        $orderDate = hasColumn($bookingColumns, 'service_date') ? "date(COALESCE(b.service_date, '9999-12-31'))" : "date('9999-12-31')";
        $orderTime = hasColumn($bookingColumns, 'service_time') ? "time(COALESCE(b.service_time, '23:59:59'))" : "time('23:59:59')";
        $createdAtOrder = hasColumn($bookingColumns, 'created_at') ? "b.created_at DESC" : "b.id DESC";

        $memberBookingsSql = "
            SELECT
                b.*,
                {$petSelect},
                {$memberNameSelect}
            FROM bookings b
            {$petJoin}
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(b.status, '')) IN ('requested', 'pending', 'scheduled') THEN 0
                    ELSE 1
                END,
                {$orderDate} ASC,
                {$orderTime} ASC,
                {$createdAtOrder}
            LIMIT 250
        ";

        $memberBookings = $pdo->query($memberBookingsSql)->fetchAll();

        $memberBookings = array_values(array_filter($memberBookings, function ($booking) use ($search, $statusFilter) {
            return recordMatchesSearch($booking, [
                'member_full_name',
                'booking_pet_name',
                'pet_name',
                'service_type',
                'email',
                'phone'
            ], $search) && recordMatchesStatus($booking, 'status', $statusFilter);
        }));
    }

    if (tableExists($pdo, 'public_booking_requests')) {
        $publicColumns = getTableColumns($pdo, 'public_booking_requests');

        $dateExpr = hasColumn($publicColumns, 'preferred_date')
            ? 'preferred_date'
            : (hasColumn($publicColumns, 'created_at') ? 'created_at' : 'id');

        $timeExpr = hasColumn($publicColumns, 'preferred_time') ? 'preferred_time' : "'23:59:59'";
        $createdAtOrder = hasColumn($publicColumns, 'created_at') ? 'created_at DESC' : 'id DESC';

        $publicSql = "
            SELECT *
            FROM public_booking_requests
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(status, '')) IN ('new', 'requested', 'pending', 'scheduled') THEN 0
                    ELSE 1
                END,
                date(COALESCE({$dateExpr}, '9999-12-31')) ASC,
                time(COALESCE({$timeExpr}, '23:59:59')) ASC,
                {$createdAtOrder}
            LIMIT 250
        ";

        $publicBookings = $pdo->query($publicSql)->fetchAll();

        $publicBookings = array_values(array_filter($publicBookings, function ($booking) use ($search, $statusFilter) {
            return recordMatchesSearch($booking, [
                'full_name',
                'pet_name',
                'service_type',
                'email',
                'phone'
            ], $search) && recordMatchesStatus($booking, 'status', $statusFilter);
        }));
    }

    if (tableExists($pdo, 'booking_change_requests')) {
        $bookingColumns = tableExists($pdo, 'bookings') ? getTableColumns($pdo, 'bookings') : [];
        $petsColumns = tableExists($pdo, 'pets') ? getTableColumns($pdo, 'pets') : [];
        $usersColumns = tableExists($pdo, 'users') ? getTableColumns($pdo, 'users') : [];
        $membersColumns = tableExists($pdo, 'members') ? getTableColumns($pdo, 'members') : [];

        $petJoin = '';
        $petSelect = "NULL AS booking_pet_name";

        if (
            tableExists($pdo, 'bookings') &&
            tableExists($pdo, 'pets') &&
            hasColumn($bookingColumns, 'pet_id') &&
            hasColumn($petsColumns, 'id') &&
            hasColumn($petsColumns, 'pet_name')
        ) {
            $petJoin = " LEFT JOIN bookings b ON b.id = r.booking_id LEFT JOIN pets p ON b.pet_id = p.id ";
            $petSelect = "p.pet_name AS booking_pet_name";
        } else {
            $petJoin = " LEFT JOIN bookings b ON b.id = r.booking_id ";
            if (hasColumn($bookingColumns, 'pet_name')) {
                $petSelect = "b.pet_name AS booking_pet_name";
            }
        }

        $memberNameSelect = "NULL AS member_full_name";

        if (tableExists($pdo, 'users')) {
            if (hasColumn($usersColumns, 'full_name') && hasColumn($usersColumns, 'id')) {
                $memberNameSelect = "(SELECT u.full_name FROM users u WHERE u.id = r.user_id LIMIT 1) AS member_full_name";
            } elseif (hasColumn($usersColumns, 'name') && hasColumn($usersColumns, 'id')) {
                $memberNameSelect = "(SELECT u.name FROM users u WHERE u.id = r.user_id LIMIT 1) AS member_full_name";
            }
        } elseif (tableExists($pdo, 'members')) {
            if (hasColumn($membersColumns, 'full_name') && hasColumn($membersColumns, 'id')) {
                $memberNameSelect = "(SELECT m.full_name FROM members m WHERE m.id = r.user_id LIMIT 1) AS member_full_name";
            } elseif (hasColumn($membersColumns, 'name') && hasColumn($membersColumns, 'id')) {
                $memberNameSelect = "(SELECT m.name FROM members m WHERE m.id = r.user_id LIMIT 1) AS member_full_name";
            }
        }

        $bookingServiceSelect = hasColumn($bookingColumns, 'service_type') ? "b.service_type AS booking_service_type" : "NULL AS booking_service_type";
        $bookingStatusSelect = hasColumn($bookingColumns, 'status') ? "b.status AS booking_status" : "NULL AS booking_status";
        $bookingPriceSelect = hasColumn($bookingColumns, 'price') ? "b.price AS booking_price" : "NULL AS booking_price";

        $changeRequestsSql = "
            SELECT
                r.*,
                {$petSelect},
                {$memberNameSelect},
                {$bookingServiceSelect},
                {$bookingStatusSelect},
                {$bookingPriceSelect}
            FROM booking_change_requests r
            {$petJoin}
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(r.status, '')) = 'pending' THEN 0
                    WHEN LOWER(COALESCE(r.status, '')) = 'approved' THEN 1
                    ELSE 2
                END,
                r.created_at DESC
            LIMIT 250
        ";

        $changeRequests = $pdo->query($changeRequestsSql)->fetchAll();

        $changeRequests = array_values(array_filter($changeRequests, function ($request) use ($search, $statusFilter) {
            return recordMatchesSearch($request, [
                'member_full_name',
                'booking_pet_name',
                'booking_service_type',
                'request_type',
                'note'
            ], $search) && recordMatchesStatus($request, 'status', $statusFilter);
        }));
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

$showMemberSection = in_array($sourceFilter, ['all', 'member'], true);
$showRequestsSection = in_array($sourceFilter, ['all', 'requests'], true);
$showPublicSection = in_array($sourceFilter, ['all', 'public'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --success:#9fe0b1;
            --danger:#ff9d9d;
            --shadow:0 20px 50px rgba(0,0,0,0.35);
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:Inter, Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(255,255,255,0.05), transparent 24%),
                linear-gradient(180deg, #08080c 0%, #111119 100%);
        }
        .admin-shell{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
        .sidebar{
            border-right:1px solid var(--border);
            background:linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            padding:28px 20px;
            position:sticky;
            top:0;
            height:100vh;
            backdrop-filter:blur(10px);
        }
        .brand{font-size:28px;font-weight:800;letter-spacing:-0.5px;line-height:1.1;margin-bottom:10px}
        .brand span{color:var(--gold)}
        .tag{color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:26px}
        .nav a{
            display:block;text-decoration:none;color:var(--text);padding:14px 16px;margin-bottom:10px;
            border-radius:16px;background:rgba(255,255,255,0.03);border:1px solid transparent;
            transition:.2s ease;font-weight:600;
        }
        .nav a:hover,.nav a.active{
            border-color:var(--border);
            background:linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
            transform:translateY(-1px);
        }
        .main{padding:34px}
        .hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:28px;flex-wrap:wrap}
        .hero h1{margin:0 0 8px;font-size:40px;line-height:1;letter-spacing:-1px}
        .hero p{margin:0;color:var(--muted);font-size:15px;max-width:760px}
        .status-message{
            border-radius:16px;padding:14px 16px;margin-bottom:18px;font-size:14px
        }
        .status-message.success{
            background:rgba(159,224,177,0.10);
            border:1px solid rgba(159,224,177,0.30);
            color:var(--success);
        }
        .status-message.error{
            background:rgba(255,157,157,0.10);
            border:1px solid rgba(255,157,157,0.30);
            color:var(--danger);
        }
        .section-card{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:22px;
            box-shadow:var(--shadow);
            margin-bottom:24px;
        }
        .section-card h2{
            margin:0 0 8px;
            font-size:28px;
            letter-spacing:-0.4px;
        }
        .section-card p{
            margin:0 0 18px;
            color:var(--muted);
            font-size:14px;
            line-height:1.6;
        }
        .filter-bar{
            display:grid;
            grid-template-columns: 1.3fr 0.8fr 0.8fr auto;
            gap:12px;
            margin-bottom:24px;
        }
        .filter-bar input,
        .filter-bar select{
            min-height:46px;
            padding:12px 14px;
            border-radius:14px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.04);
            color:var(--text);
            font:inherit;
        }
        .records{
            display:grid;
            gap:18px;
        }
        .record-card{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:22px;
            padding:18px;
        }
        .record-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }
        .record-title{
            font-size:22px;
            font-weight:800;
            letter-spacing:-0.3px;
            margin-bottom:6px;
        }
        .record-sub{
            color:var(--muted);
            font-size:14px;
        }
        .status-badge{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
            border:1px solid rgba(255,255,255,0.12);
        }
        .status-positive{
            background:rgba(159,224,177,0.10);
            color:var(--success);
            border-color:rgba(159,224,177,0.24);
        }
        .status-neutral{
            background:rgba(212,175,55,0.10);
            color:var(--gold-soft);
            border-color:rgba(212,175,55,0.24);
        }
        .status-negative{
            background:rgba(255,157,157,0.10);
            color:var(--danger);
            border-color:rgba(255,157,157,0.24);
        }
        .status-default{
            background:rgba(255,255,255,0.06);
            color:var(--text);
            border-color:rgba(255,255,255,0.12);
        }
        .details-grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:12px;
            margin-bottom:16px;
        }
        .detail-box{
            border-radius:16px;
            padding:14px;
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
        }
        .detail-box strong{
            display:block;
            color:var(--gold-soft);
            margin-bottom:6px;
            font-size:13px;
        }
        .detail-box span{
            color:var(--muted);
            font-size:14px;
            line-height:1.5;
        }
        .request-note{
            border-radius:18px;
            padding:16px;
            background:rgba(212,175,55,0.08);
            border:1px solid rgba(212,175,55,0.16);
            margin-bottom:16px;
        }
        .request-note strong{
            display:block;
            color:var(--text);
            margin-bottom:8px;
            font-size:14px;
        }
        .request-note p{
            margin:0;
            color:var(--muted);
            line-height:1.6;
            font-size:14px;
        }
        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            align-items:center;
        }
        .inline-form{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
        }
        .inline-form select{
            min-height:44px;
            padding:10px 12px;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.04);
            color:var(--text);
        }
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border:none;
            cursor:pointer;
            padding:12px 16px;
            border-radius:14px;
            font-weight:800;
            min-height:44px;
            transition:.2s ease;
        }
        .btn:hover{transform:translateY(-1px)}
        .btn-primary{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }
        .btn-secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
        }
        .empty-state{
            border:1px dashed rgba(255,255,255,0.14);
            border-radius:24px;
            padding:28px;
            text-align:center;
            color:var(--muted);
            background:rgba(255,255,255,0.03);
        }
        .error-box{
            border:1px solid rgba(255,0,0,0.25);
            background:rgba(255,0,0,0.08);
            padding:16px 18px;
            border-radius:16px;
            color:#ffd1d1;
            white-space:pre-wrap;
            word-break:break-word;
        }
        @media (max-width: 1180px){
            .details-grid{grid-template-columns:repeat(2, minmax(0,1fr))}
            .filter-bar{grid-template-columns:1fr 1fr}
        }
        @media (max-width: 860px){
            .admin-shell{grid-template-columns:1fr}
            .sidebar{position:relative;height:auto;border-right:none;border-bottom:1px solid var(--border)}
            .main{padding:20px}
            .hero h1{font-size:32px}
            .details-grid{grid-template-columns:1fr}
            .filter-bar{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <aside class="sidebar">
            <div class="brand">Doggie <span>Dorian’s</span></div>
            <div class="tag">Premium admin control panel for bookings, revenue, memberships, walkers, and client management.</div>

            <nav class="nav">
                <a href="admin-dashboard.php">Dashboard</a>
                <a href="admin-bookings.php" class="active">Booking Management</a>
                <a href="admin-revenue.php">Revenue Dashboard</a>
                <a href="admin-members.php">Members</a>
                <a href="book-walk.php">Preview Public Booking Form</a>
                <a href="admin-logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main">
            <?php if ($fatalError !== ''): ?>
                <div class="error-box">
                    <strong>System error:</strong><br>
                    <?php echo htmlspecialchars($fatalError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <section class="hero">
                    <div>
                        <h1>Booking Management</h1>
                        <p>Manage member bookings, public booking inquiries, and change requests from one premium operations page.</p>
                    </div>
                </section>

                <?php if ($successMessage !== ''): ?>
                    <div class="status-message success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="status-message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <section class="section-card">
                    <h2>Search & Filters</h2>
                    <p>Search by client, dog, service, note, phone, or email and narrow results by status or source.</p>

                    <form method="get" class="filter-bar">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search client, dog, service, email, phone..."
                            value="<?php echo htmlspecialchars((string) ($_GET['search'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        >

                        <select name="status">
                            <option value="">All Statuses</option>
                            <?php
                            $statusOptions = ['new', 'requested', 'pending', 'scheduled', 'confirmed', 'completed', 'approved', 'declined', 'cancelled'];
                            foreach ($statusOptions as $statusOption):
                            ?>
                                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="source">
                            <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                            <option value="member" <?php echo $sourceFilter === 'member' ? 'selected' : ''; ?>>Member Bookings</option>
                            <option value="requests" <?php echo $sourceFilter === 'requests' ? 'selected' : ''; ?>>Change Requests</option>
                            <option value="public" <?php echo $sourceFilter === 'public' ? 'selected' : ''; ?>>Public Bookings</option>
                        </select>

                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </form>
                </section>

                <?php if ($showMemberSection): ?>
                <section class="section-card">
                    <h2>Member Bookings</h2>
                    <p>Update member booking statuses and keep ongoing care organized.</p>

                    <?php if (!empty($memberBookings)): ?>
                        <div class="records">
                            <?php foreach ($memberBookings as $booking): ?>
                                <article class="record-card">
                                    <div class="record-head">
                                        <div>
                                            <div class="record-title">
                                                <?php echo htmlspecialchars(formatServiceName((string) ($booking['service_type'] ?? 'Service')), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="record-sub">
                                                Member:
                                                <?php echo htmlspecialchars((string) (($booking['member_full_name'] ?? '') !== '' ? $booking['member_full_name'] : ('User #' . ($booking['user_id'] ?? 'Unknown'))), ENT_QUOTES, 'UTF-8'); ?>
                                                · Booking #<?php echo (int) ($booking['id'] ?? 0); ?>
                                            </div>
                                        </div>

                                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string) ($booking['status'] ?? 'Unknown')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($booking['status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>

                                    <div class="details-grid">
                                        <div class="detail-box">
                                            <strong>Pet</strong>
                                            <span><?php echo htmlspecialchars((string) (trim((string) ($booking['booking_pet_name'] ?? '')) !== '' ? $booking['booking_pet_name'] : 'Pet not specified'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Date</strong>
                                            <span><?php echo formatDisplayDate($booking['service_date'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Time</strong>
                                            <span><?php echo formatDisplayTime($booking['service_time'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Price</strong>
                                            <span><?php echo formatMoney($booking['price'] ?? null); ?></span>
                                        </div>
                                    </div>

                                    <div class="actions">
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action_type" value="update_member_booking_status">
                                            <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                            <select name="new_status">
                                                <?php
                                                $bookingStatuses = ['Requested', 'Pending', 'Scheduled', 'Confirmed', 'Completed', 'Cancelled'];
                                                $currentStatus = (string) ($booking['status'] ?? 'Requested');
                                                foreach ($bookingStatuses as $statusOption):
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStatus === $statusOption ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary">Update Status</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No member bookings matched your filters.</div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ($showRequestsSection): ?>
                <section class="section-card">
                    <h2>Booking Change Requests</h2>
                    <p>Review and action cancellation and reschedule requests without leaving the booking manager.</p>

                    <?php if (!empty($changeRequests)): ?>
                        <div class="records">
                            <?php foreach ($changeRequests as $request): ?>
                                <article class="record-card">
                                    <div class="record-head">
                                        <div>
                                            <div class="record-title">
                                                <?php echo htmlspecialchars(formatRequestType((string) ($request['request_type'] ?? 'Request')), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="record-sub">
                                                Member:
                                                <?php echo htmlspecialchars((string) (($request['member_full_name'] ?? '') !== '' ? $request['member_full_name'] : ('User #' . ($request['user_id'] ?? 'Unknown'))), ENT_QUOTES, 'UTF-8'); ?>
                                                · Booking #<?php echo (int) ($request['booking_id'] ?? 0); ?>
                                            </div>
                                        </div>

                                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string) ($request['status'] ?? 'Unknown')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($request['status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>

                                    <div class="details-grid">
                                        <div class="detail-box">
                                            <strong>Service</strong>
                                            <span><?php echo htmlspecialchars(formatServiceName((string) ($request['booking_service_type'] ?? 'Service')), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Pet</strong>
                                            <span><?php echo htmlspecialchars((string) (($request['booking_pet_name'] ?? '') !== '' ? $request['booking_pet_name'] : 'Pet not specified'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Current Date</strong>
                                            <span><?php echo formatDisplayDate($request['current_service_date'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Current Time</strong>
                                            <span><?php echo formatDisplayTime($request['current_service_time'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Requested New Date</strong>
                                            <span><?php echo formatDisplayDate($request['requested_service_date'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Requested New Time</strong>
                                            <span><?php echo formatDisplayTime($request['requested_service_time'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Booking Status</strong>
                                            <span><?php echo htmlspecialchars((string) ($request['booking_status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Created</strong>
                                            <span><?php echo formatDisplayDateTime($request['created_at'] ?? ''); ?></span>
                                        </div>
                                    </div>

                                    <div class="request-note">
                                        <strong>Member Note</strong>
                                        <p><?php echo nl2br(htmlspecialchars((string) ($request['note'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                    </div>

                                    <div class="actions">
                                        <?php if (strtolower((string) ($request['status'] ?? '')) === 'pending'): ?>
                                            <form method="post" style="display:inline-flex;">
                                                <input type="hidden" name="action_type" value="review_change_request">
                                                <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                <input type="hidden" name="admin_action" value="approve">
                                                <button type="submit" class="btn btn-primary">Approve & Update Booking</button>
                                            </form>

                                            <form method="post" style="display:inline-flex;">
                                                <input type="hidden" name="action_type" value="review_change_request">
                                                <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                <input type="hidden" name="admin_action" value="decline">
                                                <button type="submit" class="btn btn-secondary">Decline</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="empty-state" style="padding:14px 18px; text-align:left;">
                                                This request has already been reviewed.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No booking change requests matched your filters.</div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ($showPublicSection): ?>
                <section class="section-card">
                    <h2>Public Booking Requests</h2>
                    <p>Review and update non-member booking inquiries from the public booking form.</p>

                    <?php if (!empty($publicBookings)): ?>
                        <div class="records">
                            <?php foreach ($publicBookings as $booking): ?>
                                <article class="record-card">
                                    <div class="record-head">
                                        <div>
                                            <div class="record-title">
                                                <?php echo htmlspecialchars(formatServiceName((string) ($booking['service_type'] ?? 'Service')), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="record-sub">
                                                Client:
                                                <?php echo htmlspecialchars((string) ($booking['full_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                                · Request #<?php echo (int) ($booking['id'] ?? 0); ?>
                                            </div>
                                        </div>

                                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string) ($booking['status'] ?? 'Unknown')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($booking['status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>

                                    <div class="details-grid">
                                        <div class="detail-box">
                                            <strong>Dog</strong>
                                            <span><?php echo htmlspecialchars((string) ($booking['pet_name'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Preferred Date</strong>
                                            <span><?php echo formatDisplayDate($booking['preferred_date'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Preferred Time</strong>
                                            <span><?php echo formatDisplayTime($booking['preferred_time'] ?? ''); ?></span>
                                        </div>

                                        <div class="detail-box">
                                            <strong>Phone</strong>
                                            <span><?php echo htmlspecialchars((string) ($booking['phone'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </div>

                                    <div class="actions">
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action_type" value="update_public_booking_status">
                                            <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                            <select name="new_status">
                                                <?php
                                                $publicStatuses = ['New', 'Requested', 'Pending', 'Scheduled', 'Confirmed', 'Completed', 'Cancelled'];
                                                $currentStatus = (string) ($booking['status'] ?? 'New');
                                                foreach ($publicStatuses as $statusOption):
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStatus === $statusOption ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary">Update Status</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No public booking requests matched your filters.</div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>