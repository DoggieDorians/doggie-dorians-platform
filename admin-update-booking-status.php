<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/db.php';

function goToMembers(string $message, string $type = 'error'): never
{
    header('Location: admin-members.php?status_type=' . urlencode($type) . '&status_message=' . urlencode($message));
    exit;
}

function goToMemberView(int $userId, string $message, string $type = 'success'): never
{
    if ($userId > 0) {
        header('Location: admin-member-view.php?id=' . $userId . '&status_type=' . urlencode($type) . '&status_message=' . urlencode($message));
        exit;
    }

    goToMembers($message, $type);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($table) . ')');
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

function pickExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }
    return null;
}

function safeFetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
$newStatus = trim((string) ($_POST['status'] ?? ''));
$adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['admin_member_view_csrf'] ?? '');

$allowedStatuses = [
    'Requested',
    'Confirmed',
    'In Progress',
    'Completed',
    'Cancelled',
];

if ($bookingId <= 0) {
    goToMembers('Invalid booking ID.');
}

if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    goToMemberView($userId, 'Session expired. Please refresh and try again.', 'error');
}

if (!in_array($newStatus, $allowedStatuses, true)) {
    goToMemberView($userId, 'Invalid booking status selected.', 'error');
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection failed.');
    }

    if (!tableExists($pdo, 'bookings')) {
        throw new RuntimeException('Bookings table not found.');
    }

    $bookingColumns = getColumns($pdo, 'bookings');
    if (empty($bookingColumns)) {
        throw new RuntimeException('Could not read bookings table schema.');
    }

    $bookingIdCol = pickExistingColumn($bookingColumns, ['id', 'booking_id']);
    $bookingStatusCol = pickExistingColumn($bookingColumns, ['status', 'booking_status', 'walk_status']);
    $bookingUserCol = pickExistingColumn($bookingColumns, ['user_id', 'member_id', 'client_id', 'owner_id']);
    $bookingAdminNotesCol = pickExistingColumn($bookingColumns, ['admin_notes']);
    $bookingStatusUpdatedAtCol = pickExistingColumn($bookingColumns, ['status_updated_at']);
    $bookingStatusUpdatedByCol = pickExistingColumn($bookingColumns, ['status_updated_by']);

    if ($bookingIdCol === null) {
        throw new RuntimeException('Bookings table is missing a booking ID column.');
    }

    if ($bookingStatusCol === null) {
        throw new RuntimeException('Bookings table is missing a status column.');
    }

    $selectParts = [
        quotedIdentifier($bookingIdCol) . ' AS booking_id',
        quotedIdentifier($bookingStatusCol) . ' AS current_status',
    ];

    if ($bookingUserCol !== null) {
        $selectParts[] = quotedIdentifier($bookingUserCol) . ' AS owner_id';
    }

    $checkSql = "
        SELECT " . implode(', ', $selectParts) . "
        FROM " . quotedIdentifier('bookings') . "
        WHERE " . quotedIdentifier($bookingIdCol) . " = :booking_id
        LIMIT 1
    ";

    $booking = safeFetchOne($pdo, $checkSql, [':booking_id' => $bookingId]);

    if (!$booking) {
        throw new RuntimeException('Booking not found.');
    }

    $ownerId = (int) ($booking['owner_id'] ?? 0);
    if ($ownerId > 0) {
        $userId = $ownerId;
    }

    if ($userId <= 0) {
        throw new RuntimeException('Booking is not linked to a valid member.');
    }

    $oldStatus = trim((string) ($booking['current_status'] ?? 'Requested'));
    if ($oldStatus === '' || strtolower($oldStatus) === 'pending') {
        $oldStatus = 'Requested';
    }

    $adminName = trim((string) (
        $_SESSION['admin_name']
        ?? $_SESSION['full_name']
        ?? $_SESSION['email']
        ?? $_SESSION['user_name']
        ?? 'Admin'
    ));

    if ($adminName === '') {
        $adminName = 'Admin';
    }

    $pdo->beginTransaction();

    $setParts = [
        quotedIdentifier($bookingStatusCol) . ' = :new_status',
    ];

    $updateParams = [
        ':new_status' => $newStatus,
        ':booking_id' => $bookingId,
    ];

    if ($bookingAdminNotesCol !== null) {
        $setParts[] = quotedIdentifier($bookingAdminNotesCol) . ' = :admin_notes';
        $updateParams[':admin_notes'] = $adminNotes !== '' ? $adminNotes : null;
    }

    if ($bookingStatusUpdatedAtCol !== null) {
        $setParts[] = quotedIdentifier($bookingStatusUpdatedAtCol) . ' = CURRENT_TIMESTAMP';
    }

    if ($bookingStatusUpdatedByCol !== null) {
        $setParts[] = quotedIdentifier($bookingStatusUpdatedByCol) . ' = :status_updated_by';
        $updateParams[':status_updated_by'] = $adminName;
    }

    $updateSql = "
        UPDATE " . quotedIdentifier('bookings') . "
        SET " . implode(', ', $setParts) . "
        WHERE " . quotedIdentifier($bookingIdCol) . " = :booking_id
    ";

    $updateStmt = $pdo->prepare($updateSql);

    if (!safeExecute($updateStmt, $updateParams)) {
        throw new RuntimeException('Could not update booking.');
    }

    if (tableExists($pdo, 'booking_status_history')) {
        $historyColumns = getColumns($pdo, 'booking_status_history');

        $historyBookingIdCol = pickExistingColumn($historyColumns, ['booking_id']);
        $historyOldStatusCol = pickExistingColumn($historyColumns, ['old_status', 'from_status']);
        $historyNewStatusCol = pickExistingColumn($historyColumns, ['new_status', 'to_status']);
        $historyNotesCol = pickExistingColumn($historyColumns, ['notes', 'note', 'admin_notes']);
        $historyChangedByCol = pickExistingColumn($historyColumns, ['changed_by', 'updated_by', 'admin_name']);
        $historyCreatedAtCol = pickExistingColumn($historyColumns, ['created_at', 'changed_at', 'logged_at']);

        $insertColumns = [];
        $insertPlaceholders = [];
        $historyParams = [];

        if ($historyBookingIdCol !== null) {
            $insertColumns[] = quotedIdentifier($historyBookingIdCol);
            $insertPlaceholders[] = ':history_booking_id';
            $historyParams[':history_booking_id'] = $bookingId;
        }

        if ($historyOldStatusCol !== null) {
            $insertColumns[] = quotedIdentifier($historyOldStatusCol);
            $insertPlaceholders[] = ':history_old_status';
            $historyParams[':history_old_status'] = $oldStatus;
        }

        if ($historyNewStatusCol !== null) {
            $insertColumns[] = quotedIdentifier($historyNewStatusCol);
            $insertPlaceholders[] = ':history_new_status';
            $historyParams[':history_new_status'] = $newStatus;
        }

        if ($historyNotesCol !== null) {
            $insertColumns[] = quotedIdentifier($historyNotesCol);
            $insertPlaceholders[] = ':history_notes';
            $historyParams[':history_notes'] = $adminNotes !== '' ? $adminNotes : null;
        }

        if ($historyChangedByCol !== null) {
            $insertColumns[] = quotedIdentifier($historyChangedByCol);
            $insertPlaceholders[] = ':history_changed_by';
            $historyParams[':history_changed_by'] = $adminName;
        }

        if ($historyCreatedAtCol !== null) {
            $insertColumns[] = quotedIdentifier($historyCreatedAtCol);
            $insertPlaceholders[] = 'CURRENT_TIMESTAMP';
        }

        if (!empty($insertColumns) && count($insertColumns) >= 3) {
            $historySql = "
                INSERT INTO " . quotedIdentifier('booking_status_history') . " (
                    " . implode(', ', $insertColumns) . "
                ) VALUES (
                    " . implode(', ', $insertPlaceholders) . "
                )
            ";

            $historyStmt = $pdo->prepare($historySql);
            safeExecute($historyStmt, $historyParams);
        }
    }

    $pdo->commit();

    goToMemberView($userId, 'Booking updated successfully.', 'success');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($userId > 0) {
        goToMemberView($userId, $e->getMessage(), 'error');
    }

    goToMembers($e->getMessage(), 'error');
}