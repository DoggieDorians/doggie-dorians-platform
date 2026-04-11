<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/data/config/db.php';

function goToMembers(string $message, string $type = 'error'): void
{
    header('Location: admin-members.php?status_type=' . urlencode($type) . '&status_message=' . urlencode($message));
    exit;
}

function goToMemberView(int $userId, string $message, string $type = 'success'): void
{
    if ($userId > 0) {
        header('Location: admin-member-view.php?id=' . $userId . '&status_type=' . urlencode($type) . '&status_message=' . urlencode($message));
        exit;
    }

    goToMembers($message, $type);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string)$row['name'];
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

$bookingId = (int)($_POST['booking_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$newStatus = trim((string)($_POST['status'] ?? ''));
$adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

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

    $requiredColumns = ['status', 'admin_notes', 'status_updated_at', 'status_updated_by'];
    foreach ($requiredColumns as $requiredColumn) {
        if (!in_array($requiredColumn, $bookingColumns, true)) {
            throw new RuntimeException('Bookings table is missing required column: ' . $requiredColumn);
        }
    }

    $bookingUserCol = pickExistingColumn($bookingColumns, ['user_id', 'member_id', 'client_id']);

    $selectParts = ['id', 'status'];
    if ($bookingUserCol !== null) {
        $selectParts[] = $bookingUserCol . ' AS owner_id';
    }

    $checkStmt = $pdo->prepare("
        SELECT " . implode(', ', $selectParts) . "
        FROM bookings
        WHERE id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$bookingId]);
    $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new RuntimeException('Booking not found.');
    }

    $ownerId = (int)($booking['owner_id'] ?? 0);
    if ($ownerId > 0) {
        $userId = $ownerId;
    }

    if ($userId <= 0) {
        throw new RuntimeException('Booking is not linked to a valid member.');
    }

    $oldStatus = trim((string)($booking['status'] ?? 'Requested'));
    if ($oldStatus === '' || strtolower($oldStatus) === 'pending') {
        $oldStatus = 'Requested';
    }

    $adminName = trim((string)(
        $_SESSION['admin_name']
        ?? $_SESSION['full_name']
        ?? $_SESSION['email']
        ?? 'Admin'
    ));
    if ($adminName === '') {
        $adminName = 'Admin';
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("
        UPDATE bookings
        SET
            status = :status,
            admin_notes = :admin_notes,
            status_updated_at = CURRENT_TIMESTAMP,
            status_updated_by = :status_updated_by
        WHERE id = :id
    ");
    $updateStmt->execute([
        'status' => $newStatus,
        'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
        'status_updated_by' => $adminName,
        'id' => $bookingId,
    ]);

    if (tableExists($pdo, 'booking_status_history')) {
        $historyColumns = getColumns($pdo, 'booking_status_history');
        $requiredHistoryColumns = ['booking_id', 'old_status', 'new_status', 'notes', 'changed_by'];
        $hasHistoryColumns = true;

        foreach ($requiredHistoryColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $historyColumns, true)) {
                $hasHistoryColumns = false;
                break;
            }
        }

        if ($hasHistoryColumns) {
            $historyStmt = $pdo->prepare("
                INSERT INTO booking_status_history (
                    booking_id,
                    old_status,
                    new_status,
                    notes,
                    changed_by
                )
                VALUES (
                    :booking_id,
                    :old_status,
                    :new_status,
                    :notes,
                    :changed_by
                )
            ");
            $historyStmt->execute([
                'booking_id' => $bookingId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $adminNotes !== '' ? $adminNotes : null,
                'changed_by' => $adminName,
            ]);
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