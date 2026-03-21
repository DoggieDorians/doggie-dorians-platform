<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$user = null;
$pets = [];
$petCount = 0;

try {
    // Get logged in user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Get user's pets
    $stmt = $pdo->prepare("SELECT * FROM pets WHERE user_id = :user_id ORDER BY id DESC");
    $stmt->execute([':user_id' => $userId]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $petCount = count($pets);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$displayName = '';
if (!empty($user['full_name'])) {
    $displayName = $user['full_name'];
} elseif (!empty($user['name'])) {
    $displayName = $user['name'];
} else {
    $displayName = $user['email'] ?? 'Member';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard | Doggie Dorian's</title>
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
            --success: #7ecb8a;
            --shadow: 0 18px 50px rgba(0,0,0,0.35);
            --radius: 22px;
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
            width: min(1200px, 92%);
            margin: 0 auto;
            padding: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .brand {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: var(--gold-soft);
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-links a {
            padding: 10px 16px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            transition: 0.25s ease;
            font-size: 0.95rem;
        }

        .top-links a:hover {
            background: rgba(212, 175, 55, 0.12);
            border-color: rgba(212,175,55,0.35);
        }

        .container {
            width: min(1200px, 92%);
            margin: 36px auto 60px;
        }

        .hero {
            background: linear-gradient(135deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 38px 32px;
            box-shadow: var(--shadow);
            margin-bottom: 28px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 0.8rem;
            margin-bottom: 12px;
        }

        .hero h1 {
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1.1;
            margin-bottom: 12px;
        }

        .hero p {
            color: var(--muted);
            max-width: 760px;
            font-size: 1rem;
            line-height: 1.7;
        }

        .hero-actions {
            margin-top: 24px;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 14px 20px;
            border-radius: 999px;
            font-weight: 700;
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
            background: rgba(255,255,255,0.02);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: rgba(212,175,55,0.08);
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            font-size: 1.35rem;
            margin-bottom: 8px;
        }

        .card p.section-text {
            color: var(--muted);
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .stat-box {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.92rem;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--gold-soft);
        }

        .pet-list {
            display: grid;
            gap: 14px;
        }

        .pet-item {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
        }

        .pet-item h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .pet-meta {
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .empty-state {
            background: rgba(255,255,255,0.02);
            border: 1px dashed rgba(212,175,55,0.25);
            border-radius: 18px;
            padding: 24px;
            color: var(--muted);
            line-height: 1.7;
        }

        .quick-links {
            display: grid;
            gap: 12px;
            margin-top: 12px;
        }

        .quick-links a {
            display: block;
            padding: 16px 18px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 16px;
            transition: 0.25s ease;
        }

        .quick-links a:hover {
            background: rgba(212,175,55,0.08);
            transform: translateY(-1px);
        }

        .quick-links strong {
            display: block;
            margin-bottom: 4px;
            color: var(--gold-soft);
        }

        .quick-links span {
            color: var(--muted);
            font-size: 0.95rem;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .topbar-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">Doggie Dorian's Member Area</div>
            <nav class="top-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="my-pets.php">My Pets</a>
                <a href="add-pet.php">Add Pet</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="hero">
            <div class="eyebrow">Member Dashboard</div>
            <h1>Welcome back, <?php echo htmlspecialchars($displayName); ?></h1>
            <p>
                Manage your pets, prepare for bookings, and keep your member account organized
                through your private Doggie Dorian's dashboard.
            </p>
            <div class="hero-actions">
                <a href="add-pet.php" class="btn btn-primary">Add a New Pet</a>
                <a href="my-pets.php" class="btn btn-secondary">View My Pets</a>
            </div>
        </section>

        <div class="grid">
            <section class="card">
                <h2>Your Account Overview</h2>
                <p class="section-text">
                    This section gives you a quick snapshot of your member profile and pet account.
                </p>

                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-label">Registered Pets</div>
                        <div class="stat-value"><?php echo (int)$petCount; ?></div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-label">Member Email</div>
                        <div class="stat-value" style="font-size: 1rem; line-height: 1.4;">
                            <?php echo htmlspecialchars($user['email'] ?? 'Not available'); ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 22px;">
                    <h2 style="font-size: 1.2rem; margin-bottom: 12px;">Recently Added Pets</h2>

                    <?php if (!empty($pets)): ?>
                        <div class="pet-list">
                            <?php foreach (array_slice($pets, 0, 3) as $pet): ?>
                                <div class="pet-item">
                                    <h3><?php echo htmlspecialchars($pet['pet_name'] ?? $pet['name'] ?? 'Unnamed Pet'); ?></h3>
                                    <div class="pet-meta">
                                        Breed:
                                        <?php echo htmlspecialchars($pet['breed'] ?? 'N/A'); ?>
                                        <br>
                                        Age:
                                        <?php echo htmlspecialchars($pet['age'] ?? 'N/A'); ?>
                                        <br>
                                        Weight:
                                        <?php echo htmlspecialchars($pet['weight'] ?? 'N/A'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            You have not added any pets yet. Add your first pet now so your account
                            is ready for future bookings and member services.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="card">
                <h2>Quick Actions</h2>
                <p class="section-text">
                    Use these shortcuts to keep building out your member profile.
                </p>

                <div class="quick-links">
                    <a href="add-pet.php">
                        <strong>Add a Pet</strong>
                        <span>Create a pet profile connected to your member account.</span>
                    </a>

                    <a href="my-pets.php">
                        <strong>Manage My Pets</strong>
                        <span>View and update the dogs attached to your account.</span>
                    </a>

                    <a href="book-walk.php">
                        <strong>Book a Service</strong>
                        <span>Move into walk, daycare, or boarding scheduling.</span>
                    </a>
                </div>
            </aside>
        </div>
    </main>

</body>
</html>