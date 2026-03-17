<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin-login.php');
    exit;
}

function getDatabaseConnection(): PDO
{
    $possiblePaths = [
        __DIR__ . '/data/members.sqlite',
        __DIR__ . '/data/database.sqlite',
        __DIR__ . '/database.sqlite',
        __DIR__ . '/data/site.sqlite',
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }

    throw new RuntimeException('Could not find SQLite database file.');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)($stmt->fetchColumn() ?: 0);
}

function floatScalar(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetchColumn() ?: 0);
}

function getTodayDate(): string
{
    return date('Y-m-d');
}

function money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

try {
    $pdo = getDatabaseConnection();

    $stats = [
        'members' => 0,
        'walks_total' => 0,
        'walks_pending' => 0,
        'walks_today' => 0,
        'non_member_total' => 0,
        'non_member_pending' => 0,
        'non_member_today' => 0,
        'walkers' => 0,
        'revenue_month' => 0.0,
    ];

    if (tableExists($pdo, 'members')) {
        $stats['members'] = scalar($pdo, "SELECT COUNT(*) FROM members");
    }

    if (tableExists($pdo, 'walks')) {
        $stats['walks_total'] = scalar($pdo, "SELECT COUNT(*) FROM walks");
        $stats['walks_pending'] = scalar(
            $pdo,
            "SELECT COUNT(*) FROM walks WHERE status IN ('Requested', 'Pending', 'Scheduled')"
        );
        $stats['walks_today'] = scalar(
            $pdo,
            "SELECT COUNT(*) FROM walks WHERE walk_date = :today",
            ['today' => getTodayDate()]
        );
    }

    if (tableExists($pdo, 'non_member_bookings')) {
        $stats['non_member_total'] = scalar($pdo, "SELECT COUNT(*) FROM non_member_bookings");

        try {
            $stats['non_member_pending'] = scalar(
                $pdo,
                "SELECT COUNT(*) FROM non_member_bookings WHERE status IN ('Requested', 'Pending', 'Scheduled', 'New')"
            );
        } catch (Throwable $e) {
            $stats['non_member_pending'] = 0;
        }

        $dateCandidates = ['date_start', 'booking_date', 'service_date', 'start_date', 'date'];
        $dateColumn = null;

        foreach ($dateCandidates as $candidate) {
            try {
                $stmt = $pdo->query("SELECT {$candidate} FROM non_member_bookings LIMIT 1");
                if ($stmt !== false) {
                    $dateColumn = $candidate;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        if ($dateColumn) {
            $stats['non_member_today'] = scalar(
                $pdo,
                "SELECT COUNT(*) FROM non_member_bookings WHERE {$dateColumn} = :today",
                ['today' => getTodayDate()]
            );
        }

        try {
            $monthStart = date('Y-m-01');
            $stats['revenue_month'] = floatScalar(
                $pdo,
                "SELECT COALESCE(SUM(CAST(estimated_price AS REAL)), 0)
                 FROM non_member_bookings
                 WHERE date(date_start) >= date(:month_start)
                 AND date(date_start) <= date(:today)
                 AND status != 'Cancelled'",
                [
                    'month_start' => $monthStart,
                    'today' => getTodayDate(),
                ]
            );
        } catch (Throwable $e) {
            $stats['revenue_month'] = 0.0;
        }
    }

    if (tableExists($pdo, 'walkers')) {
        try {
            $stats['walkers'] = scalar($pdo, "SELECT COUNT(*) FROM walkers WHERE is_active = 1");
        } catch (Throwable $e) {
            $stats['walkers'] = scalar($pdo, "SELECT COUNT(*) FROM walkers");
        }
    }

} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Doggie Dorian's</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
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
        .hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:28px}
        .hero h1{margin:0 0 8px;font-size:40px;line-height:1;letter-spacing:-1px}
        .hero p{margin:0;color:var(--muted);font-size:15px}
        .hero-actions{display:flex;gap:12px;flex-wrap:wrap}
        .btn{
            display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));padding:14px 18px;border-radius:14px;
            font-weight:800;box-shadow:var(--shadow);
        }
        .btn.secondary{
            color:var(--text);background:rgba(255,255,255,0.05);border:1px solid var(--border);box-shadow:none;
        }
        .stats{display:grid;grid-template-columns:repeat(5, minmax(0,1fr));gap:18px;margin-bottom:28px}
        .card{
            background:var(--panel);border:1px solid var(--border);border-radius:24px;padding:22px;
            box-shadow:var(--shadow);backdrop-filter:blur(10px);
        }
        .stat-label{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px}
        .stat-value{font-size:34px;font-weight:800;letter-spacing:-1px;margin-bottom:8px}
        .stat-sub{color:var(--gold-soft);font-size:13px}
        .grid-2{display:grid;grid-template-columns:1.35fr .85fr;gap:18px}
        .section-title{margin:0 0 16px;font-size:22px;letter-spacing:-0.3px}
        .list{display:grid;gap:12px}
        .list-item{
            display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;
            border-radius:18px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);
        }
        .list-item strong{display:block;font-size:15px;margin-bottom:4px}
        .list-item span{color:var(--muted);font-size:13px}
        .pill{
            display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:999px;
            border:1px solid var(--border);color:var(--gold-soft);background:rgba(212,175,55,0.08);
            font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
        }
        .error-box{
            border:1px solid rgba(255,0,0,0.25);background:rgba(255,0,0,0.08);padding:16px 18px;
            border-radius:16px;color:#ffd1d1;
        }
        @media (max-width: 1180px){
            .stats{grid-template-columns:repeat(2, minmax(0,1fr))}
            .grid-2{grid-template-columns:1fr}
        }
        @media (max-width: 860px){
            .admin-shell{grid-template-columns:1fr}
            .sidebar{position:relative;height:auto;border-right:none;border-bottom:1px solid var(--border)}
            .hero{flex-direction:column;align-items:flex-start}
        }
        @media (max-width: 640px){
            .main{padding:20px}
            .stats{grid-template-columns:1fr}
            .hero h1{font-size:32px}
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <aside class="sidebar">
            <div class="brand">Doggie <span>Dorian’s</span></div>
            <div class="tag">Premium admin control panel for bookings, revenue, memberships, walkers, and client management.</div>

            <nav class="nav">
                <a href="admin-dashboard.php" class="active">Dashboard</a>
                <a href="admin-bookings.php">Booking Management</a>
                <a href="admin-revenue.php">Revenue Dashboard</a>
                <a href="memberships.php">Memberships</a>
                <a href="non-member-booking.php">New Non-Member Booking</a>
                <a href="admin-logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main">
            <?php if (!empty($fatalError)): ?>
                <div class="error-box">
                    <strong>Database connection error:</strong><br>
                    <?php echo htmlspecialchars($fatalError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <section class="hero">
                    <div>
                        <h1>Admin Dashboard</h1>
                        <p>Luxury command center for Doggie Dorian’s operations.</p>
                    </div>
                    <div class="hero-actions">
                        <a class="btn" href="admin-bookings.php">Open Booking Manager</a>
                        <a class="btn secondary" href="admin-revenue.php">Open Revenue</a>
                    </div>
                </section>

                <section class="stats">
                    <div class="card">
                        <div class="stat-label">Total Members</div>
                        <div class="stat-value"><?php echo number_format($stats['members']); ?></div>
                        <div class="stat-sub">Registered member accounts</div>
                    </div>

                    <div class="card">
                        <div class="stat-label">Walk Requests Pending</div>
                        <div class="stat-value"><?php echo number_format($stats['walks_pending']); ?></div>
                        <div class="stat-sub"><?php echo number_format($stats['walks_total']); ?> total member walk records</div>
                    </div>

                    <div class="card">
                        <div class="stat-label">Non-Member Pending</div>
                        <div class="stat-value"><?php echo number_format($stats['non_member_pending']); ?></div>
                        <div class="stat-sub"><?php echo number_format($stats['non_member_total']); ?> total non-member bookings</div>
                    </div>

                    <div class="card">
                        <div class="stat-label">Active Walkers</div>
                        <div class="stat-value"><?php echo number_format($stats['walkers']); ?></div>
                        <div class="stat-sub">Current active walker accounts</div>
                    </div>

                    <div class="card">
                        <div class="stat-label">Revenue This Month</div>
                        <div class="stat-value"><?php echo money((float)$stats['revenue_month']); ?></div>
                        <div class="stat-sub">Based on non-member booked pricing</div>
                    </div>
                </section>

                <section class="grid-2">
                    <div class="card">
                        <h2 class="section-title">Today’s Booking Snapshot</h2>
                        <div class="list">
                            <div class="list-item">
                                <div>
                                    <strong>Member walks scheduled today</strong>
                                    <span>All walks with today’s date in the walks table</span>
                                </div>
                                <div class="pill"><?php echo number_format($stats['walks_today']); ?></div>
                            </div>

                            <div class="list-item">
                                <div>
                                    <strong>Non-member bookings scheduled today</strong>
                                    <span>All bookings in the non_member_bookings table for today</span>
                                </div>
                                <div class="pill"><?php echo number_format($stats['non_member_today']); ?></div>
                            </div>

                            <div class="list-item">
                                <div>
                                    <strong>Total bookings awaiting review</strong>
                                    <span>Combined view across your two booking systems</span>
                                </div>
                                <div class="pill"><?php echo number_format($stats['walks_pending'] + $stats['non_member_pending']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="section-title">Quick Actions</h2>
                        <div class="list">
                            <div class="list-item">
                                <div>
                                    <strong>Manage all bookings</strong>
                                    <span>Filter, review, and update statuses in one place</span>
                                </div>
                                <a class="btn secondary" href="admin-bookings.php">Open</a>
                            </div>

                            <div class="list-item">
                                <div>
                                    <strong>View revenue dashboard</strong>
                                    <span>Track revenue, services, and recent sales</span>
                                </div>
                                <a class="btn secondary" href="admin-revenue.php">View</a>
                            </div>

                            <div class="list-item">
                                <div>
                                    <strong>Create a non-member booking</strong>
                                    <span>Use the live booking form flow</span>
                                </div>
                                <a class="btn secondary" href="non-member-booking.php">Create</a>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>