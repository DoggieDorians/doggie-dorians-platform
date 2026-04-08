<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$pets = [];
$successMessage = '';

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $successMessage = 'Pet updated successfully.';
}

if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $successMessage = 'Pet deleted successfully.';
}

if (isset($_GET['added']) && $_GET['added'] == '1') {
    $successMessage = 'Pet added successfully.';
}

try {
    $stmt = $pdo->prepare("SELECT * FROM pets WHERE user_id = :user_id ORDER BY id DESC");
    $stmt->execute([':user_id' => $userId]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Pets | Doggie Dorian's</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #0b0b0f;
            --panel: #15151c;
            --panel-2: #1d1d26;
            --gold: #d4af37;
            --gold-soft: #f1df9b;
            --text: #f5f2e8;
            --muted: #b7b0a0;
            --border: rgba(212, 175, 55, 0.18);
            --shadow: 0 18px 50px rgba(0,0,0,0.35);
            --success-bg: rgba(126, 203, 138, 0.10);
            --success-border: rgba(126, 203, 138, 0.35);
            --success-text: #9be3a6;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top, rgba(212,175,55,0.08), transparent 30%),
                linear-gradient(180deg, #09090c 0%, #101016 100%);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .topbar {
            width: 100%;
            border-bottom: 1px solid var(--border);
            background: rgba(11,11,15,0.88);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-inner {
            width: min(1180px, 92%);
            margin: 0 auto;
            padding: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--gold-soft);
            letter-spacing: 0.4px;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav-links a {
            padding: 10px 16px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            transition: 0.25s ease;
            font-size: 0.95rem;
        }

        .nav-links a:hover {
            background: rgba(212,175,55,0.12);
            border-color: rgba(212,175,55,0.35);
        }

        .container {
            width: min(1150px, 92%);
            margin: 40px auto 60px;
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        h1 {
            font-size: clamp(2rem, 4vw, 3rem);
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 13px 18px;
            border-radius: 999px;
            font-weight: 700;
            text-decoration: none;
            transition: 0.25s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f1df9b);
            color: #141414;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            filter: brightness(1.03);
        }

        .btn-secondary {
            border: 1px solid var(--border);
            color: var(--text);
            background: rgba(255,255,255,0.02);
        }

        .btn-secondary:hover {
            background: rgba(212,175,55,0.08);
        }

        .success-message {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 16px;
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-text);
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .pet-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .pet-card h2 {
            font-size: 1.35rem;
            margin-bottom: 12px;
            color: var(--gold-soft);
        }

        .pet-meta {
            color: var(--muted);
            line-height: 1.8;
            margin-bottom: 18px;
        }

        .pet-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pet-actions a {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.92rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--border);
            color: var(--text);
            background: rgba(255,255,255,0.03);
            transition: 0.25s ease;
        }

        .pet-actions a:hover {
            background: rgba(212,175,55,0.08);
        }

        .empty-state {
            background: rgba(255,255,255,0.02);
            border: 1px dashed rgba(212,175,55,0.28);
            border-radius: 22px;
            padding: 30px;
            color: var(--muted);
            line-height: 1.8;
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">Doggie Dorian's Member Area</div>
            <nav class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="profile.php">Profile</a>
                <a href="my-pets.php">My Pets</a>
                <a href="add-pet.php">Add Pet</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="top">
            <h1>My Pets</h1>
            <div class="actions">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <a href="add-pet.php" class="btn btn-primary">Add New Pet</a>
            </div>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pets)): ?>
            <div class="grid">
                <?php foreach ($pets as $pet): ?>
                    <div class="pet-card">
                        <h2><?php echo htmlspecialchars($pet['pet_name'] ?? $pet['name'] ?? 'Unnamed Pet'); ?></h2>

                        <div class="pet-meta">
                            <strong>Breed:</strong> <?php echo htmlspecialchars($pet['breed'] ?? 'N/A'); ?><br>
                            <strong>Age:</strong> <?php echo htmlspecialchars($pet['age'] ?? 'N/A'); ?><br>
                            <strong>Weight:</strong> <?php echo htmlspecialchars($pet['weight'] ?? 'N/A'); ?><br>

                            <?php if (!empty($pet['size'])): ?>
                                <strong>Size:</strong> <?php echo htmlspecialchars($pet['size']); ?><br>
                            <?php endif; ?>

                            <?php if (!empty($pet['gender'])): ?>
                                <strong>Gender:</strong> <?php echo htmlspecialchars($pet['gender']); ?><br>
                            <?php endif; ?>

                            <?php if (!empty($pet['notes'])): ?>
                                <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($pet['notes'])); ?>
                            <?php endif; ?>
                        </div>

                        <div class="pet-actions">
                            <a href="edit-pet.php?id=<?php echo (int)$pet['id']; ?>">Edit</a>
                            <a href="delete-pet.php?id=<?php echo (int)$pet['id']; ?>" onclick="return confirm('Are you sure you want to delete this pet?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                You have not added any pets yet. Click <strong>Add New Pet</strong> to create your first pet profile.
            </div>
        <?php endif; ?>
    </main>

</body>
</html>