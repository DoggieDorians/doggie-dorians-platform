<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin-login.php');
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function redirectBack(): void
{
    header('Location: admin-bookings.php');
    exit;
}

function getDB(): PDO
{
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/members.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request.');
    redirectBack();
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$bookingType = trim((string)($_POST['booking_type'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));

$allowedStatuses = ['Requested', 'Pending', 'Scheduled', 'Completed', 'Cancelled'];

if ($bookingId <= 0) {
    setFlash('error', 'Invalid booking ID.');
    redirectBack();
}

if (!in_array($status, $allowedStatuses, true)) {
    setFlash('error', 'Invalid status selected.');
    redirectBack();
}

try {
    $pdo = getDB();

    if ($bookingType === 'walk') {
        if (!columnExists($pdo, 'walks', 'status')) {
            throw new RuntimeException('The walks table does not have a status column.');
        }

        $stmt = $pdo->prepare("UPDATE walks SET status = :status WHERE id = :id");
        $stmt->execute([
            'status' => $status,
            'id' => $bookingId,
        ]);

        setFlash('success', 'Member walk updated successfully.');
        redirectBack();
    }

    if ($bookingType === 'non_member') {
        if (!columnExists($pdo, 'non_member_bookings', 'status')) {
            throw new RuntimeException('The non_member_bookings table does not have a status column yet.');
        }

        $stmt = $pdo->prepare("UPDATE non_member_bookings SET status = :status WHERE id = :id");
        $stmt->execute([
            'status' => $status,
            'id' => $bookingId,
        ]);

        setFlash('success', 'Non-member booking updated successfully.');
        redirectBack();
    }

    throw new RuntimeException('Unknown booking type.');

} catch (Throwable $e) {
    setFlash('error', $e->getMessage());
    redirectBack();
}