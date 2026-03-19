<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Member';
$filter = strtolower(trim($_GET['filter'] ?? 'all'));

$allowedFilters = ['all', 'upcoming', 'past', 'cancelled'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$bookings = [];
$totalBookings = 0;
$upcomingCount = 0;
$pastCount = 0;
$cancelledCount = 0;

function getTableColumns(PDO $pdo, string $tableName): array
{
    $columns = [];

    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $tableName . ")");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as $column) {
            if (!empty($column['name'])) {
                $columns[] = $column['name'];
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function buildBookingsQuery(PDO $pdo, string $filter): array
{
    $bookingColumns = getTableColumns($pdo, 'bookings');
    $petColumns = getTableColumns($pdo, 'pets');

    $hasPetId = hasColumn($bookingColumns, 'pet_id');
    $hasBookingPetName = hasColumn($bookingColumns, 'pet_name');
    $hasPetsTableId = hasColumn($petColumns, 'id');
    $hasPetsTablePetName = hasColumn($petColumns, 'pet_name');

    $petNameSelect = "NULL AS booking_pet_name";
    $joinClause = "";

    if ($hasPetId && $hasPetsTableId && $hasPetsTablePetName) {
        $petNameSelect = "p.pet_name AS booking_pet_name";
        $joinClause = " LEFT JOIN pets p ON b.pet_id = p.id ";
    } elseif ($hasBookingPetName) {
        $petNameSelect = "b.pet_name AS booking_pet_name";
    }

    $where = ["b.user_id = :user_id"];

    if ($filter === 'upcoming') {
        $where[] = "date(b.service_date) >= date('now')";
        $where[] = "LOWER(b.status) NOT IN ('cancelled', 'canceled', 'completed')";
    } elseif ($filter === 'past') {
        $where[] = "("
            . "date(b.service_date) < date('now') "
            . "OR LOWER(b.status) = 'completed'"
            . ")";
        $where[] = "LOWER(b.status) NOT IN ('cancelled', 'canceled')";
    } elseif ($filter === 'cancelled') {
        $where[] = "LOWER(b.status) IN ('cancelled', 'canceled')";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            b.id,
            b.service_type,
            b.service_date,
            b.service_time,
            b.duration_minutes,
            b.status,
            b.price,
            b.created_at,
            {$petNameSelect}
        FROM bookings b
        {$joinClause}
        WHERE {$whereSql}
        ORDER BY
            CASE
                WHEN date(b.service_date) >= date('now') THEN 0
                ELSE 1
            END,
            date(b.service_date) ASC,
            time(COALESCE(b.service_time, '23:59:59')) ASC,
            b.created_at DESC
    ";

    return [$sql, ['user_id' => $GLOBALS['userId']]];
}

function countBookingsByType(PDO $pdo, int $userId, string $type): int
{
    try {
        if ($type === 'all') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            return (int)$stmt->fetchColumn();
        }

        if ($type === 'upcoming') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE user_id = :user_id
                  AND date(service_date) >= date('now')
                  AND LOWER(status) NOT IN ('cancelled', 'canceled', 'completed')
            ");
            $stmt->execute(['user_id' => $userId]);
            return (int)$stmt->fetchColumn();
        }

        if ($type === 'past') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE user_id = :user_id
                  AND (
                        date(service_date) < date('now')
                        OR LOWER(status) = 'completed'
                  )
                  AND LOWER(status) NOT IN ('cancelled', 'canceled')
            ");
            $stmt->execute(['user_id' => $userId]);
            return (int)$stmt->fetchColumn();
        }

        if ($type === 'cancelled') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE user_id = :user_id
                  AND LOWER(status) IN ('cancelled', 'canceled')
            ");
            $stmt->execute(['user_id' => $userId]);
            return (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

function formatServiceName(string $service): string
{
    return ucwords(str_replace('-', ' ', $service));
}

function formatDisplayDate(?string $date): string
{
    $date = trim((string)$date);

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
    $time = trim((string)$time);

    if ($time === '') {
        return 'N/A';
    }

    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
    }

    return date('g:i A', $timestamp);
}

function bookingPetLabel(array $booking): string
{
    $petName = trim((string)($booking['booking_pet_name'] ?? ''));
    return $petName !== '' ? $petName : 'Pet not specified';
}

function formatStatusClass(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'confirmed', 'completed', 'active', 'approved' => 'status-positive',
        'requested', 'pending', 'scheduled' => 'status-neutral',
        'cancelled', 'canceled' => 'status-negative',
        default => 'status-default',
    };
}

try {
    [$sql, $params] = buildBookingsQuery($pdo, $filter);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalBookings = countBookingsByType($pdo, $userId, 'all');
    $upcomingCount = countBookingsByType($pdo, $userId, 'upcoming');
    $pastCount = countBookingsByType($pdo, $userId, 'past');
    $cancelledCount = countBookingsByType($pdo, $userId, 'cancelled');
} catch (PDOException $e) {
    die('Bookings page error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Doggie Dorian's</title>
    <meta name="description" content="View and manage your bookings with Doggie Dorian's.">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #07080b;
            --bg-soft: #0d1016;
            --panel: rgba(255,255,255,0.04);
            --panel-strong: rgba(255,255,255,0.06);
            --line: rgba(255,255,255,0.10);
            --text: #f6f1e8;
            --muted: #c9c0af;
            --soft: #9d968a;
            --gold: #d7b26a;
            --gold-light: #f0d59f;
            --white: #ffffff;
            --success: #9fe0b1;
            --danger: #ff9d9d;
            --shadow: 0 22px 65px rgba(0,0,0,0.34);
            --max: 1280px;
        }

        body {
            font-family: "Georgia", "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 24%),
                linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
            color: var(--text);
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            min-height: 100vh;
            padding: 30px 20px 64px;
        }

        .wrap {
            width: min(var(--max), 100%);
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
            font-size: 1.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: var(--white);
        }

        .topnav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topnav a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--white);
            font-weight: 700;
            transition: 0.22s ease;
        }

        .topnav a:hover,
        .topnav a.active {
            transform: translateY(-1px);
            border-color: rgba(215,178,106,0.28);
            color: var(--gold);
        }

        .hero {
            border-radius: 34px;
            border: 1px solid rgba(255,255,255,0.08);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
                linear-gradient(135deg, rgba(215,178,106,0.10), rgba(255,255,255,0.02));
            box-shadow: var(--shadow);
            padding: 38px;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(215,178,106,0.30);
            background: rgba(215,178,106,0.08);
            color: #f2d9a8;
            font-size: 0.78rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: clamp(2.3rem, 5vw, 4.1rem);
            line-height: 0.96;
            color: var(--white);
        }

        .hero p {
            margin: 0;
            max-width: 760px;
            color: var(--muted);
            font-size: 1rem;
        }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 13px 20px;
            border-radius: 999px;
            font-weight: 700;
            transition: 0.22s ease;
            border: 1px solid transparent;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            color: #17120d;
            box-shadow: 0 16px 38px rgba(215,178,106,0.22);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.03);
            color: var(--white);
            border-color: rgba(255,255,255,0.08);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            border-radius: 24px;
            padding: 24px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow);
        }

        .stat-label {
            margin: 0 0 10px;
            color: var(--soft);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .stat-value {
            margin: 0;
            font-size: 2.2rem;
            font-weight: 700;
            color: #f5ddaf;
            line-height: 1;
        }

        .stat-sub {
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.94rem;
        }

        .filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            color: var(--white);
            font-weight: 700;
            transition: 0.22s ease;
        }

        .filter-chip:hover,
        .filter-chip.active {
            border-color: rgba(215,178,106,0.28);
            background: rgba(215,178,106,0.10);
            color: var(--gold);
        }

        .booking-grid {
            display: grid;
            gap: 18px;
        }

        .booking-card {
            border-radius: 24px;
            padding: 24px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow);
        }

        .booking-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: start;
            margin-bottom: 16px;
        }

        .booking-title {
            color: var(--white);
            font-size: 1.5rem;
            margin-bottom: 6px;
        }

        .booking-pet {
            color: #f2d9a8;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .booking-meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 12px;
        }

        .booking-meta-box {
            border-radius: 16px;
            padding: 14px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .booking-meta-box strong {
            display: block;
            color: #f5ddaf;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .booking-meta-box span {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .status-positive {
            background: rgba(159,224,177,0.10);
            color: var(--success);
            border-color: rgba(159,224,177,0.24);
        }

        .status-neutral {
            background: rgba(215,178,106,0.10);
            color: #f2d9a8;
            border-color: rgba(215,178,106,0.24);
        }

        .status-negative {
            background: rgba(255,157,157,0.10);
            color: var(--danger);
            border-color: rgba(255,157,157,0.24);
        }

        .status-default {
            background: rgba(255,255,255,0.06);
            color: var(--white);
            border-color: rgba(255,255,255,0.12);
        }

        .booking-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .booking-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            color: var(--white);
            font-weight: 700;
            transition: 0.22s ease;
        }

        .booking-actions a:hover {
            border-color: rgba(215,178,106,0.28);
            color: var(--gold);
        }

        .empty-state {
            border: 1px dashed rgba(255,255,255,0.14);
            border-radius: 24px;
            padding: 34px;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.02);
            box-shadow: var(--shadow);
        }

        .empty-state p + p {
            margin-top: 8px;
        }

        .empty-state a {
            color: var(--gold);
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            .stats,
            .booking-meta-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 720px) {
            .page {
                padding: 20px 14px 50px;
            }

            .hero,
            .stat-card,
            .booking-card,
            .empty-state {
                padding: 22px;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .stats,
            .booking-meta-grid {
                grid-template-columns: 1fr;
            }

            .topnav,
            .hero-actions {
                width: 100%;
            }

            .topnav a,
            .hero-actions a {
                flex: 1;
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
                    <a href="dashboard.php">Dashboard</a>
                    <a href="my-bookings.php" class="active">My Bookings</a>
                    <a href="add-pet.php">Add Pet</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <div class="eyebrow">Member Bookings</div>
                <h1>Your bookings, all in one place.</h1>
                <p>
                    Review your upcoming services, look back at past visits, and keep your Doggie Dorian’s care schedule organized in one premium view.
                </p>
                <div class="hero-actions">
                    <a class="btn btn-primary" href="book-walk.php">Book a Service</a>
                    <a class="btn btn-secondary" href="dashboard.php">Back to Dashboard</a>
                </div>
            </section>

            <section class="stats">
                <div class="stat-card">
                    <p class="stat-label">All Bookings</p>
                    <p class="stat-value"><?php echo $totalBookings; ?></p>
                    <p class="stat-sub">Your full service history</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Upcoming</p>
                    <p class="stat-value"><?php echo $upcomingCount; ?></p>
                    <p class="stat-sub">Future services currently scheduled</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Past</p>
                    <p class="stat-value"><?php echo $pastCount; ?></p>
                    <p class="stat-sub">Completed or past services</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Cancelled</p>
                    <p class="stat-value"><?php echo $cancelledCount; ?></p>
                    <p class="stat-sub">Cancelled booking records</p>
                </div>
            </section>

            <section class="filters">
                <a class="filter-chip <?php echo $filter === 'all' ? 'active' : ''; ?>" href="my-bookings.php?filter=all">All</a>
                <a class="filter-chip <?php echo $filter === 'upcoming' ? 'active' : ''; ?>" href="my-bookings.php?filter=upcoming">Upcoming</a>
                <a class="filter-chip <?php echo $filter === 'past' ? 'active' : ''; ?>" href="my-bookings.php?filter=past">Past</a>
                <a class="filter-chip <?php echo $filter === 'cancelled' ? 'active' : ''; ?>" href="my-bookings.php?filter=cancelled">Cancelled</a>
            </section>

            <?php if (!empty($bookings)): ?>
                <section class="booking-grid">
                    <?php foreach ($bookings as $booking): ?>
                        <article class="booking-card">
                            <div class="booking-head">
                                <div>
                                    <h2 class="booking-title"><?php echo htmlspecialchars(formatServiceName((string)$booking['service_type'])); ?></h2>
                                    <div class="booking-pet">Pet: <?php echo htmlspecialchars(bookingPetLabel($booking)); ?></div>
                                </div>

                                <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string)$booking['status'])); ?>">
                                    <?php echo htmlspecialchars((string)$booking['status']); ?>
                                </span>
                            </div>

                            <div class="booking-meta-grid">
                                <div class="booking-meta-box">
                                    <strong>Date</strong>
                                    <span><?php echo formatDisplayDate($booking['service_date'] ?? ''); ?></span>
                                </div>

                                <div class="booking-meta-box">
                                    <strong>Time</strong>
                                    <span><?php echo formatDisplayTime($booking['service_time'] ?? ''); ?></span>
                                </div>

                                <div class="booking-meta-box">
                                    <strong>Duration</strong>
                                    <span>
                                        <?php echo $booking['duration_minutes'] !== null ? htmlspecialchars((string)$booking['duration_minutes']) . ' mins' : 'N/A'; ?>
                                    </span>
                                </div>

                                <div class="booking-meta-box">
                                    <strong>Price</strong>
                                    <span>$<?php echo number_format((float)$booking['price'], 2); ?></span>
                                </div>
                            </div>

                            <div class="booking-actions">
                                <a href="booking-details.php?id=<?php echo urlencode((string)$booking['id']); ?>">View Details</a>
                                <a href="book-walk.php">Book Again</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <div class="empty-state">
                    <p>No bookings found for this section.</p>
                    <p><a href="book-walk.php">Book your next service</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>