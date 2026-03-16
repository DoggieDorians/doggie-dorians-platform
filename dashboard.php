<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Member';

$petCount = 0;
$bookingCount = 0;
$recentPets = [];
$recentBookings = [];

try {
    $petCountStmt = $pdo->prepare("SELECT COUNT(*) FROM pets WHERE user_id = ?");
    $petCountStmt->execute([$userId]);
    $petCount = (int)$petCountStmt->fetchColumn();

    $bookingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
    $bookingCountStmt->execute([$userId]);
    $bookingCount = (int)$bookingCountStmt->fetchColumn();

    $petsStmt = $pdo->prepare("
        SELECT id, pet_name, breed, age, weight, gender, birthday, status, created_at
        FROM pets
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $petsStmt->execute([$userId]);
    $recentPets = $petsStmt->fetchAll();

    $bookingsStmt = $pdo->prepare("
        SELECT id, service_type, service_date, service_time, duration_minutes, status, price, created_at
        FROM bookings
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $bookingsStmt->execute([$userId]);
    $recentBookings = $bookingsStmt->fetchAll();
} catch (PDOException $e) {
    die('Dashboard error: ' . $e->getMessage());
}

function formatPetMeta(array $pet): string
{
    $parts = [];

    if (!empty($pet['breed'])) {
        $parts[] = $pet['breed'];
    }

    if ($pet['age'] !== null && $pet['age'] !== '') {
        $parts[] = $pet['age'] . ' yr' . ((int)$pet['age'] === 1 ? '' : 's');
    }

    if (!empty($pet['weight'])) {
        $parts[] = $pet['weight'];
    }

    if (!empty($pet['gender'])) {
        $parts[] = $pet['gender'];
    }

    return implode(' • ', $parts);
}

function formatServiceName(string $service): string
{
    return ucwords(str_replace('-', ' ', $service));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Doggie Dorian's</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f8fb;
            color: #111;
        }

        .page {
            min-height: 100vh;
            padding: 32px 20px 60px;
        }

        .wrap {
            max-width: 1180px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .brand {
            font-size: 30px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .topnav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topnav a {
            text-decoration: none;
            color: #111;
            background: #fff;
            padding: 11px 15px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            font-weight: 700;
        }

        .hero {
            background: linear-gradient(135deg, #111 0%, #2a2a2a 100%);
            color: #fff;
            border-radius: 24px;
            padding: 34px;
            margin-bottom: 24px;
        }

        .hero h1 {
            margin: 0 0 10px;
            font-size: 38px;
            line-height: 1.1;
        }

        .hero p {
            margin: 0;
            max-width: 720px;
            color: rgba(255, 255, 255, 0.86);
            line-height: 1.6;
            font-size: 16px;
        }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .hero-actions a {
            text-decoration: none;
            padding: 14px 18px;
            border-radius: 12px;
            font-weight: 700;
            display: inline-block;
        }

        .btn-primary {
            background: #fff;
            color: #111;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.16);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.06);
        }

        .stat-label {
            margin: 0 0 10px;
            color: #666;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .stat-value {
            margin: 0;
            font-size: 34px;
            font-weight: 700;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 22px;
        }

        .card {
            background: #fff;
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 26px;
        }

        .card-subtext {
            margin: 0 0 22px;
            color: #666;
            line-height: 1.6;
        }

        .pets-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .pet-card {
            border: 1px solid #ececec;
            border-radius: 18px;
            padding: 18px;
            background: #fcfcfc;
        }

        .pet-name {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 700;
        }

        .pet-meta {
            margin: 0 0 10px;
            color: #555;
            line-height: 1.5;
        }

        .pet-status {
            display: inline-block;
            padding: 7px 10px;
            border-radius: 999px;
            background: #e8f8ea;
            color: #146c2e;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .booking-list {
            display: grid;
            gap: 14px;
        }

        .booking-item {
            border: 1px solid #ececec;
            border-radius: 16px;
            padding: 16px;
            background: #fcfcfc;
        }

        .booking-title {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 700;
        }

        .booking-meta {
            margin: 0;
            color: #555;
            line-height: 1.6;
        }

        .status-badge {
            display: inline-block;
            margin-top: 12px;
            padding: 7px 10px;
            border-radius: 999px;
            background: #efefef;
            color: #111;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .empty-state {
            border: 1px dashed #d7d7d7;
            border-radius: 18px;
            padding: 24px;
            text-align: center;
            color: #666;
            background: #fafafa;
        }

        .quick-links {
            display: grid;
            gap: 14px;
        }

        .quick-links a {
            text-decoration: none;
            color: #111;
            background: #f8f8f8;
            border: 1px solid #ececec;
            border-radius: 16px;
            padding: 16px 18px;
            font-weight: 700;
        }

        .section-spacer {
            margin-top: 22px;
        }

        @media (max-width: 980px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .hero {
                padding: 26px;
            }

            .hero h1 {
                font-size: 30px;
            }

            .pets-grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 22px;
            }

            .brand {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="topbar">
                <div class="brand">Doggie Dorian’s</div>
                <div class="topnav">
                    <a href="add-pet.php">Add Pet</a>
                    <a href="book-walk.php">Book Walk</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <h1>Welcome back, <?php echo htmlspecialchars($fullName); ?></h1>
                <p>
                    Manage your dogs, review your recent bookings, and enjoy a premium pet care experience
                    built for convenience, trust, and elevated service.
                </p>
                <div class="hero-actions">
                    <a class="btn-primary" href="add-pet.php">Add a Dog</a>
                    <a class="btn-secondary" href="book-walk.php">Book a Service</a>
                </div>
            </section>

            <section class="stats">
                <div class="stat-card">
                    <p class="stat-label">Your Dogs</p>
                    <p class="stat-value"><?php echo $petCount; ?></p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Total Bookings</p>
                    <p class="stat-value"><?php echo $bookingCount; ?></p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Account Type</p>
                    <p class="stat-value"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'member')); ?></p>
                </div>
            </section>

            <section class="content-grid">
                <div class="card">
                    <h2>Your Dogs</h2>
                    <p class="card-subtext">Every pet profile helps us deliver a more personal, safe, and luxury-level experience.</p>

                    <?php if (count($recentPets) > 0): ?>
                        <div class="pets-grid">
                            <?php foreach ($recentPets as $pet): ?>
                                <div class="pet-card">
                                    <h3 class="pet-name"><?php echo htmlspecialchars($pet['pet_name']); ?></h3>

                                    <?php $meta = formatPetMeta($pet); ?>
                                    <?php if ($meta !== ''): ?>
                                        <p class="pet-meta"><?php echo htmlspecialchars($meta); ?></p>
                                    <?php else: ?>
                                        <p class="pet-meta">Profile started. Add more details anytime.</p>
                                    <?php endif; ?>

                                    <?php if (!empty($pet['birthday'])): ?>
                                        <p class="pet-meta">Birthday: <?php echo htmlspecialchars($pet['birthday']); ?></p>
                                    <?php endif; ?>

                                    <span class="pet-status"><?php echo htmlspecialchars($pet['status']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>You haven’t added a dog yet.</p>
                            <p><a href="add-pet.php">Create your first pet profile</a></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="card">
                        <h2>Recent Bookings</h2>
                        <p class="card-subtext">Your most recent service activity appears here.</p>

                        <?php if (count($recentBookings) > 0): ?>
                            <div class="booking-list">
                                <?php foreach ($recentBookings as $booking): ?>
                                    <div class="booking-item">
                                        <h3 class="booking-title">
                                            <?php echo htmlspecialchars(formatServiceName($booking['service_type'])); ?>
                                        </h3>
                                        <p class="booking-meta">
                                            Date: <?php echo htmlspecialchars($booking['service_date']); ?><br>
                                            Time: <?php echo htmlspecialchars($booking['service_time']); ?><br>
                                            Duration:
                                            <?php echo $booking['duration_minutes'] !== null ? htmlspecialchars((string)$booking['duration_minutes']) . ' mins' : 'N/A'; ?><br>
                                            Price: $<?php echo number_format((float)$booking['price'], 2); ?>
                                        </p>
                                        <span class="status-badge"><?php echo htmlspecialchars($booking['status']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No bookings yet.</p>
                                <p><a href="book-walk.php">Book your first service</a></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card section-spacer">
                        <h2>Quick Actions</h2>
                        <p class="card-subtext">Keep your account moving with the next best steps.</p>

                        <div class="quick-links">
                            <a href="add-pet.php">Add another dog</a>
                            <a href="book-walk.php">Book a walk or service</a>
                            <a href="profile.php">Update your client profile</a>
                            <a href="logout.php">Log out of your account</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>