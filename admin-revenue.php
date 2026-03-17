<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin-login.php');
    exit;
}

function getDB(): PDO
{
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/members.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $rows = $stmt ? $stmt->fetchAll() : [];
    $columns = [];
    foreach ($rows as $row) {
        if (!empty($row['name'])) {
            $columns[] = $row['name'];
        }
    }
    return $columns;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return '$' . number_format($amount, 2);
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

function maxValue(array $rows, string $key): float
{
    $max = 0.0;
    foreach ($rows as $row) {
        $value = (float)($row[$key] ?? 0);
        if ($value > $max) {
            $max = $value;
        }
    }
    return $max;
}

try {
    $pdo = getDB();

    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');

    $totals = [
        'today' => 0.0,
        'week' => 0.0,
        'month' => 0.0,
        'all_time' => 0.0,
    ];

    $sources = [
        'non_member' => 0.0,
        'member_bookings' => 0.0,
    ];

    $counts = [
        'today' => 0,
        'week' => 0,
        'month' => 0,
        'all_time' => 0,
    ];

    $serviceBreakdown = [];
    $dailyRevenue = [];
    $recentRevenue = [];
    $revenueNotes = [];
    $topService = '—';
    $topServiceRevenue = 0.0;
    $bestDay = '—';
    $bestDayRevenue = 0.0;
    $avgTicket = 0.0;

    $combinedServiceMap = [];
    $combinedDayMap = [];
    $combinedRecent = [];

    /*
    |--------------------------------------------------------------------------
    | NON-MEMBER BOOKINGS REVENUE
    |--------------------------------------------------------------------------
    */
    if (tableExists($pdo, 'non_member_bookings')) {
        $cols = getColumns($pdo, 'non_member_bookings');

        $priceCol = pickExistingColumn($cols, ['estimated_price', 'price', 'total_price', 'amount']);
        $dateCol = pickExistingColumn($cols, ['date_start', 'booking_date', 'service_date', 'created_at']);
        $serviceCol = pickExistingColumn($cols, ['service_type', 'service', 'booking_type']);
        $statusCol = pickExistingColumn($cols, ['status']);
        $nameCol = pickExistingColumn($cols, ['full_name', 'client_name', 'name']);
        $dogCol = pickExistingColumn($cols, ['dog_name', 'pet_name', 'dog']);

        if ($priceCol !== null && $dateCol !== null) {
            $statusExclusion = '';
            if ($statusCol !== null) {
                $statusExclusion = " AND COALESCE($statusCol, '') NOT IN ('Cancelled')";
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) = date(:today)
                $statusExclusion
            ");
            $stmt->execute(['today' => $today]);
            $totals['today'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:week_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'week_start' => $weekStart,
                'today' => $today
            ]);
            $totals['week'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:month_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'month_start' => $monthStart,
                'today' => $today
            ]);
            $totals['month'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $nonMemberAllTime = (float)($stmt->fetchColumn() ?: 0);
            $totals['all_time'] += $nonMemberAllTime;
            $sources['non_member'] = $nonMemberAllTime;

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) = date(:today)
                $statusExclusion
            ");
            $stmt->execute(['today' => $today]);
            $counts['today'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:week_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'week_start' => $weekStart,
                'today' => $today
            ]);
            $counts['week'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:month_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'month_start' => $monthStart,
                'today' => $today
            ]);
            $counts['month'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COUNT(*)
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $counts['all_time'] += (int)($stmt->fetchColumn() ?: 0);

            if ($serviceCol !== null) {
                $stmt = $pdo->query("
                    SELECT
                        COALESCE($serviceCol, 'Service') AS service_name,
                        COUNT(*) AS booking_count,
                        COALESCE(SUM(CAST($priceCol AS REAL)), 0) AS total_revenue
                    FROM non_member_bookings
                    WHERE $priceCol IS NOT NULL
                    $statusExclusion
                    GROUP BY $serviceCol
                ");
                foreach ($stmt->fetchAll() as $row) {
                    $serviceName = (string)($row['service_name'] ?? 'Service');
                    if (!isset($combinedServiceMap[$serviceName])) {
                        $combinedServiceMap[$serviceName] = [
                            'service_name' => $serviceName,
                            'booking_count' => 0,
                            'total_revenue' => 0.0,
                        ];
                    }
                    $combinedServiceMap[$serviceName]['booking_count'] += (int)$row['booking_count'];
                    $combinedServiceMap[$serviceName]['total_revenue'] += (float)$row['total_revenue'];
                }
            }

            $stmt = $pdo->query("
                SELECT
                    date($dateCol) AS revenue_day,
                    COUNT(*) AS booking_count,
                    COALESCE(SUM(CAST($priceCol AS REAL)), 0) AS total_revenue
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
                GROUP BY date($dateCol)
            ");
            foreach ($stmt->fetchAll() as $row) {
                $day = (string)$row['revenue_day'];
                if (!isset($combinedDayMap[$day])) {
                    $combinedDayMap[$day] = [
                        'revenue_day' => $day,
                        'booking_count' => 0,
                        'total_revenue' => 0.0,
                    ];
                }
                $combinedDayMap[$day]['booking_count'] += (int)$row['booking_count'];
                $combinedDayMap[$day]['total_revenue'] += (float)$row['total_revenue'];
            }

            $selectName = $nameCol !== null ? $nameCol : "'Client'";
            $selectDog = $dogCol !== null ? $dogCol : "''";
            $selectService = $serviceCol !== null ? $serviceCol : "'Service'";
            $selectStatus = $statusCol !== null ? $statusCol : "'Requested'";

            $stmt = $pdo->query("
                SELECT
                    id,
                    'non_member' AS source_type,
                    $selectName AS client_name,
                    $selectDog AS dog_name,
                    $selectService AS service_name,
                    CAST($priceCol AS REAL) AS revenue_amount,
                    $dateCol AS revenue_date,
                    $selectStatus AS status_name
                FROM non_member_bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $combinedRecent = array_merge($combinedRecent, $stmt->fetchAll());
        } else {
            $revenueNotes[] = 'The non_member_bookings table is missing a price or date field needed for full revenue reporting.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MEMBER BOOKINGS REVENUE
    |--------------------------------------------------------------------------
    */
    if (tableExists($pdo, 'bookings')) {
        $cols = getColumns($pdo, 'bookings');

        $priceCol = pickExistingColumn($cols, ['price', 'estimated_price', 'amount']);
        $dateCol = pickExistingColumn($cols, ['service_date', 'booking_date', 'created_at']);
        $serviceCol = pickExistingColumn($cols, ['service_type']);
        $statusCol = pickExistingColumn($cols, ['status']);
        $durationCol = pickExistingColumn($cols, ['duration_minutes']);
        $clientNotesCol = pickExistingColumn($cols, ['client_notes']);

        if ($priceCol !== null && $dateCol !== null) {
            $statusExclusion = '';
            if ($statusCol !== null) {
                $statusExclusion = " AND LOWER(COALESCE($statusCol, '')) NOT IN ('cancelled')";
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) = date(:today)
                $statusExclusion
            ");
            $stmt->execute(['today' => $today]);
            $totals['today'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:week_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'week_start' => $weekStart,
                'today' => $today
            ]);
            $totals['week'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:month_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'month_start' => $monthStart,
                'today' => $today
            ]);
            $totals['month'] += (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COALESCE(SUM(CAST($priceCol AS REAL)), 0)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $memberAllTime = (float)($stmt->fetchColumn() ?: 0);
            $totals['all_time'] += $memberAllTime;
            $sources['member_bookings'] = $memberAllTime;

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) = date(:today)
                $statusExclusion
            ");
            $stmt->execute(['today' => $today]);
            $counts['today'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:week_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'week_start' => $weekStart,
                'today' => $today
            ]);
            $counts['week'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                AND date($dateCol) >= date(:month_start)
                AND date($dateCol) <= date(:today)
                $statusExclusion
            ");
            $stmt->execute([
                'month_start' => $monthStart,
                'today' => $today
            ]);
            $counts['month'] += (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COUNT(*)
                FROM bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $counts['all_time'] += (int)($stmt->fetchColumn() ?: 0);

            $serviceExpr = "
                CASE
                    WHEN $serviceCol = 'walk' AND $durationCol IS NOT NULL THEN CAST($durationCol AS TEXT) || ' Min Walk'
                    WHEN $serviceCol = 'daycare' THEN 'Daycare'
                    WHEN $serviceCol = 'boarding' THEN 'Boarding'
                    WHEN $serviceCol = 'drop-in visit' THEN 'Drop-In Visit'
                    WHEN $serviceCol = 'pet taxi' THEN 'Pet Taxi'
                    ELSE 'Booking'
                END
            ";

            $stmt = $pdo->query("
                SELECT
                    $serviceExpr AS service_name,
                    COUNT(*) AS booking_count,
                    COALESCE(SUM(CAST($priceCol AS REAL)), 0) AS total_revenue
                FROM bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
                GROUP BY $serviceExpr
            ");
            foreach ($stmt->fetchAll() as $row) {
                $serviceName = (string)($row['service_name'] ?? 'Booking');
                if (!isset($combinedServiceMap[$serviceName])) {
                    $combinedServiceMap[$serviceName] = [
                        'service_name' => $serviceName,
                        'booking_count' => 0,
                        'total_revenue' => 0.0,
                    ];
                }
                $combinedServiceMap[$serviceName]['booking_count'] += (int)$row['booking_count'];
                $combinedServiceMap[$serviceName]['total_revenue'] += (float)$row['total_revenue'];
            }

            $stmt = $pdo->query("
                SELECT
                    date($dateCol) AS revenue_day,
                    COUNT(*) AS booking_count,
                    COALESCE(SUM(CAST($priceCol AS REAL)), 0) AS total_revenue
                FROM bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
                GROUP BY date($dateCol)
            ");
            foreach ($stmt->fetchAll() as $row) {
                $day = (string)$row['revenue_day'];
                if (!isset($combinedDayMap[$day])) {
                    $combinedDayMap[$day] = [
                        'revenue_day' => $day,
                        'booking_count' => 0,
                        'total_revenue' => 0.0,
                    ];
                }
                $combinedDayMap[$day]['booking_count'] += (int)$row['booking_count'];
                $combinedDayMap[$day]['total_revenue'] += (float)$row['total_revenue'];
            }

            $statusExpr = $statusCol !== null ? $statusCol : "'pending'";

            $stmt = $pdo->query("
                SELECT
                    id,
                    'member_booking' AS source_type,
                    'Member Client' AS client_name,
                    '' AS dog_name,
                    $serviceExpr AS service_name,
                    CAST($priceCol AS REAL) AS revenue_amount,
                    $dateCol AS revenue_date,
                    $statusExpr AS status_name
                FROM bookings
                WHERE $priceCol IS NOT NULL
                $statusExclusion
            ");
            $combinedRecent = array_merge($combinedRecent, $stmt->fetchAll());
        } else {
            $revenueNotes[] = 'The bookings table is missing a price or date field needed for member revenue reporting.';
        }
    } else {
        $revenueNotes[] = 'The bookings table was not found.';
    }

    if ($counts['all_time'] > 0) {
        $avgTicket = $totals['all_time'] / $counts['all_time'];
    }

    $serviceBreakdown = array_values($combinedServiceMap);
    usort($serviceBreakdown, function (array $a, array $b): int {
        $revCompare = (float)$b['total_revenue'] <=> (float)$a['total_revenue'];
        if ($revCompare !== 0) {
            return $revCompare;
        }
        return (int)$b['booking_count'] <=> (int)$a['booking_count'];
    });

    if (!empty($serviceBreakdown)) {
        $topService = (string)($serviceBreakdown[0]['service_name'] ?? '—');
        $topServiceRevenue = (float)($serviceBreakdown[0]['total_revenue'] ?? 0);
    }

    $dailyRevenue = array_values($combinedDayMap);
    usort($dailyRevenue, function (array $a, array $b): int {
        return strcmp((string)$b['revenue_day'], (string)$a['revenue_day']);
    });
    $dailyRevenue = array_slice($dailyRevenue, 0, 10);

    foreach ($dailyRevenue as $row) {
        if ((float)$row['total_revenue'] > $bestDayRevenue) {
            $bestDayRevenue = (float)$row['total_revenue'];
            $bestDay = (string)$row['revenue_day'];
        }
    }

    usort($combinedRecent, function (array $a, array $b): int {
        $dateCompare = strcmp((string)$b['revenue_date'], (string)$a['revenue_date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return (int)$b['id'] <=> (int)$a['id'];
    });
    $recentRevenue = array_slice($combinedRecent, 0, 10);

} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Dashboard | Doggie Dorian's</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --panel2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --shadow:0 20px 50px rgba(0,0,0,0.35);
            --note-bg:rgba(255,213,154,0.08);
            --note-border:rgba(255,213,154,0.18);
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

        .shell{
            display:grid;
            grid-template-columns:280px 1fr;
            min-height:100vh;
        }

        .sidebar{
            border-right:1px solid var(--border);
            background:linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            padding:28px 20px;
        }

        .brand{
            font-size:28px;
            font-weight:800;
            line-height:1.1;
            margin-bottom:10px;
        }

        .brand span{ color:var(--gold); }

        .tag{
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
            margin-bottom:26px;
        }

        .nav a{
            display:block;
            text-decoration:none;
            color:var(--text);
            padding:14px 16px;
            margin-bottom:10px;
            border-radius:16px;
            background:rgba(255,255,255,0.03);
            border:1px solid transparent;
            font-weight:600;
        }

        .nav a:hover,
        .nav a.active{
            border-color:var(--border);
            background:linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
        }

        .main{
            padding:34px;
        }

        .header{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:18px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }

        .header h1{
            margin:0 0 8px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            padding:14px 18px;
            border-radius:14px;
            font-weight:800;
            box-shadow:var(--shadow);
        }

        .btn.secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
            box-shadow:none;
        }

        .stats{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:18px;
            margin-bottom:18px;
        }

        .card{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:22px;
            box-shadow:var(--shadow);
        }

        .stat-label{
            color:var(--muted);
            font-size:13px;
            text-transform:uppercase;
            letter-spacing:1.2px;
            margin-bottom:10px;
        }

        .stat-value{
            font-size:34px;
            font-weight:800;
            letter-spacing:-1px;
            margin-bottom:8px;
        }

        .stat-sub{
            color:var(--gold-soft);
            font-size:13px;
        }

        .mini-stats{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:18px;
            margin-bottom:24px;
        }

        .grid{
            display:grid;
            grid-template-columns:1.05fr .95fr;
            gap:18px;
            margin-bottom:18px;
        }

        .grid-bottom{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .section-title{
            margin:0 0 16px;
            font-size:24px;
            letter-spacing:-0.4px;
        }

        .section-sub{
            margin:-6px 0 18px;
            color:var(--muted);
            font-size:14px;
        }

        .note{
            padding:14px 16px;
            border-radius:16px;
            background:var(--note-bg);
            border:1px solid var(--note-border);
            color:#ffe4bd;
            margin-bottom:14px;
            line-height:1.6;
        }

        .service-list,
        .daily-list{
            display:grid;
            gap:14px;
        }

        .metric-row{
            padding:16px;
            border-radius:18px;
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.06);
        }

        .metric-head{
            display:flex;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
            align-items:center;
        }

        .metric-title{
            font-weight:800;
            font-size:16px;
        }

        .metric-meta{
            color:var(--muted);
            font-size:13px;
            margin-bottom:10px;
        }

        .bar-wrap{
            width:100%;
            height:12px;
            border-radius:999px;
            background:rgba(255,255,255,0.06);
            overflow:hidden;
            border:1px solid rgba(255,255,255,0.05);
        }

        .bar{
            height:100%;
            border-radius:999px;
            background:linear-gradient(90deg, #b58b18, #d4af37, #f3df9b);
            min-width:2%;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th, td{
            text-align:left;
            padding:14px 10px;
            border-bottom:1px solid rgba(255,255,255,0.07);
            vertical-align:top;
        }

        th{
            color:var(--gold-soft);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        td{
            color:var(--text);
            font-size:14px;
        }

        .muted{
            color:var(--muted);
        }

        .pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 10px;
            border-radius:999px;
            border:1px solid var(--border);
            color:var(--gold-soft);
            background:rgba(212,175,55,0.08);
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        .empty{
            color:var(--muted);
        }

        .error-box{
            border:1px solid rgba(255,0,0,0.25);
            background:rgba(255,0,0,0.08);
            padding:16px 18px;
            border-radius:16px;
            color:#ffd1d1;
        }

        @media (max-width: 1200px){
            .stats,
            .mini-stats{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
            .grid,
            .grid-bottom{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 900px){
            .shell{ grid-template-columns:1fr; }
            .main{ padding:20px; }
        }

        @media (max-width: 640px){
            .stats,
            .mini-stats{
                grid-template-columns:1fr;
            }
            .header h1{
                font-size:32px;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Doggie <span>Dorian’s</span></div>
        <div class="tag">Premium admin control panel for revenue, bookings, and operations.</div>

        <nav class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php" class="active">Revenue Dashboard</a>
            <a href="memberships.php">Memberships</a>
            <a href="non-member-booking.php">New Non-Member Booking</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <?php if (!empty($fatalError)): ?>
            <div class="error-box">
                <strong>Revenue dashboard error:</strong><br>
                <?php echo h($fatalError); ?>
            </div>
        <?php else: ?>
            <section class="header">
                <div>
                    <h1>Revenue Dashboard</h1>
                    <div class="sub">Executive revenue visibility for bookings, services, and performance.</div>
                </div>
                <div>
                    <a class="btn secondary" href="admin-dashboard.php">Back to Dashboard</a>
                </div>
            </section>

            <?php if (!empty($revenueNotes)): ?>
                <?php foreach ($revenueNotes as $note): ?>
                    <div class="note"><?php echo h($note); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <section class="stats">
                <div class="card">
                    <div class="stat-label">Revenue Today</div>
                    <div class="stat-value"><?php echo money($totals['today']); ?></div>
                    <div class="stat-sub">Member + non-member bookings</div>
                </div>

                <div class="card">
                    <div class="stat-label">Revenue This Week</div>
                    <div class="stat-value"><?php echo money($totals['week']); ?></div>
                    <div class="stat-sub">Monday through today</div>
                </div>

                <div class="card">
                    <div class="stat-label">Revenue This Month</div>
                    <div class="stat-value"><?php echo money($totals['month']); ?></div>
                    <div class="stat-sub">Current month total</div>
                </div>

                <div class="card">
                    <div class="stat-label">All-Time Revenue</div>
                    <div class="stat-value"><?php echo money($totals['all_time']); ?></div>
                    <div class="stat-sub">Combined booked revenue</div>
                </div>
            </section>

            <section class="mini-stats">
                <div class="card">
                    <div class="stat-label">Bookings Today</div>
                    <div class="stat-value"><?php echo number_format($counts['today']); ?></div>
                    <div class="stat-sub">Revenue-generating bookings</div>
                </div>

                <div class="card">
                    <div class="stat-label">Bookings This Month</div>
                    <div class="stat-value"><?php echo number_format($counts['month']); ?></div>
                    <div class="stat-sub">Current monthly volume</div>
                </div>

                <div class="card">
                    <div class="stat-label">Average Ticket</div>
                    <div class="stat-value"><?php echo money($avgTicket); ?></div>
                    <div class="stat-sub">Across all tracked bookings</div>
                </div>

                <div class="card">
                    <div class="stat-label">Top Service</div>
                    <div class="stat-value" style="font-size:24px;"><?php echo h($topService); ?></div>
                    <div class="stat-sub"><?php echo money($topServiceRevenue); ?> total</div>
                </div>
            </section>

            <section class="grid">
                <div class="card">
                    <h2 class="section-title">Revenue by Service</h2>
                    <div class="section-sub">Non-member and member booking services combined.</div>

                    <?php if (empty($serviceBreakdown)): ?>
                        <div class="empty">No service revenue data is available yet.</div>
                    <?php else: ?>
                        <?php $maxServiceRevenue = maxValue($serviceBreakdown, 'total_revenue'); ?>
                        <div class="service-list">
                            <?php foreach ($serviceBreakdown as $row): ?>
                                <?php
                                $serviceRevenue = (float)($row['total_revenue'] ?? 0);
                                $serviceBookings = (int)($row['booking_count'] ?? 0);
                                $barWidth = $maxServiceRevenue > 0 ? ($serviceRevenue / $maxServiceRevenue) * 100 : 0;
                                ?>
                                <div class="metric-row">
                                    <div class="metric-head">
                                        <div class="metric-title"><?php echo h((string)($row['service_name'] ?? 'Service')); ?></div>
                                        <div class="metric-title"><?php echo money($serviceRevenue); ?></div>
                                    </div>
                                    <div class="metric-meta"><?php echo number_format($serviceBookings); ?> bookings</div>
                                    <div class="bar-wrap">
                                        <div class="bar" style="width: <?php echo number_format($barWidth, 2, '.', ''); ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2 class="section-title">Daily Revenue Trend</h2>
                    <div class="section-sub">Most recent booked revenue days across all tracked sources.</div>

                    <?php if (empty($dailyRevenue)): ?>
                        <div class="empty">No daily revenue data is available yet.</div>
                    <?php else: ?>
                        <?php $maxDayRevenue = maxValue($dailyRevenue, 'total_revenue'); ?>
                        <div class="daily-list">
                            <?php foreach ($dailyRevenue as $row): ?>
                                <?php
                                $dayRevenue = (float)($row['total_revenue'] ?? 0);
                                $dayBookings = (int)($row['booking_count'] ?? 0);
                                $barWidth = $maxDayRevenue > 0 ? ($dayRevenue / $maxDayRevenue) * 100 : 0;
                                ?>
                                <div class="metric-row">
                                    <div class="metric-head">
                                        <div class="metric-title"><?php echo h((string)($row['revenue_day'] ?? '—')); ?></div>
                                        <div class="metric-title"><?php echo money($dayRevenue); ?></div>
                                    </div>
                                    <div class="metric-meta"><?php echo number_format($dayBookings); ?> bookings</div>
                                    <div class="bar-wrap">
                                        <div class="bar" style="width: <?php echo number_format($barWidth, 2, '.', ''); ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="grid-bottom">
                <div class="card">
                    <h2 class="section-title">Performance Highlights</h2>
                    <table>
                        <tbody>
                            <tr>
                                <th>Best Revenue Day</th>
                                <td><?php echo h($bestDay); ?></td>
                                <td><?php echo money($bestDayRevenue); ?></td>
                            </tr>
                            <tr>
                                <th>Non-Member Revenue</th>
                                <td>Tracked Source</td>
                                <td><?php echo money($sources['non_member']); ?></td>
                            </tr>
                            <tr>
                                <th>Member Booking Revenue</th>
                                <td>Tracked Source</td>
                                <td><?php echo money($sources['member_bookings']); ?></td>
                            </tr>
                            <tr>
                                <th>Revenue This Week</th>
                                <td><?php echo number_format($counts['week']); ?> bookings</td>
                                <td><?php echo money($totals['week']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h2 class="section-title">Recent Revenue</h2>

                    <?php if (empty($recentRevenue)): ?>
                        <div class="empty">No recent revenue records found.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRevenue as $row): ?>
                                    <tr>
                                        <td>
                                            <?php echo h((string)($row['client_name'] ?? 'Client')); ?><br>
                                            <span class="muted"><?php echo h((string)($row['dog_name'] ?? '')); ?></span>
                                        </td>
                                        <td>
                                            <?php echo h((string)($row['service_name'] ?? 'Service')); ?><br>
                                            <span class="muted"><?php echo h((string)($row['revenue_date'] ?? '')); ?></span>
                                        </td>
                                        <td>
                                            <?php echo money((float)($row['revenue_amount'] ?? 0)); ?><br>
                                            <span class="pill"><?php echo h((string)($row['status_name'] ?? 'pending')); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>