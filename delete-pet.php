<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$petId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($petId <= 0) {
    header('Location: my-pets.php');
    exit;
}

try {
    // Make sure the pet belongs to the logged-in user
    $stmt = $pdo->prepare("SELECT id FROM pets WHERE id = :id AND user_id = :user_id LIMIT 1");
    $stmt->execute([
        ':id' => $petId,
        ':user_id' => $userId
    ]);
    $pet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pet) {
        header('Location: my-pets.php');
        exit;
    }

    // Delete the pet
    $deleteStmt = $pdo->prepare("DELETE FROM pets WHERE id = :id AND user_id = :user_id");
    $deleteStmt->execute([
        ':id' => $petId,
        ':user_id' => $userId
    ]);

    header('Location: my-pets.php?deleted=1');
    exit;

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}