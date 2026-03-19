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
$upcomingBookings = [];
$nextBooking = null;
$membershipLabel = 'Standard Member';
$membershipBenefits = [
    'title' => 'Member Benefits',
    'subtitle' => 'Your account includes member-level access and a more elevated care experience.',
    'items' => [
        'Simpler access to recurring booking routines',
        'Member-oriented care experience and account support',
        'Cleaner path to premium services and upgrades',
        'A more polished overall client experience',
    ],
    'metrics' => [],
];

$includedWalks = null;
$walksUsedThisMonth = 0;
$walksRemaining = null;

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

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(['table' => $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getUserKeyForTable(PDO $pdo, string $tableName): ?string
{
    $columns = getTableColumns($pdo, $tableName);

    foreach (['user_id', 'member_id', 'id'] as $candidateKey) {
        if (hasColumn($columns, $candidateKey)) {
            return $candidateKey;
        }
    }

    return null;
}

function firstAvailableValue(PDO $pdo, string $table, array $candidateColumns, int $userId): ?string
{
    if (!tableExists($pdo, $table)) {
        return null;
    }

    $columns = getTableColumns($pdo, $table);
    $userKey = getUserKeyForTable($pdo, $table);

    if ($userKey === null) {
        return null;
    }

    foreach ($candidateColumns as $candidateColumn) {
        if (!hasColumn($columns, $candidateColumn)) {
            continue;
        }

        try {
            $sql = "SELECT {$candidateColumn} FROM {$table} WHERE {$userKey} = :user_id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            $value = $stmt->fetchColumn();

            if ($value !== false && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

function firstAvailableMixedValue(PDO $pdo, string $table, array $candidateColumns, int $userId)
{
    if (!tableExists($pdo, $table)) {
        return null;
    }

    $columns = getTableColumns($pdo, $table);
    $userKey = getUserKeyForTable($pdo, $table);

    if ($userKey === null) {
        return null;
    }

    foreach ($candidateColumns as $candidateColumn) {
        if (!hasColumn($columns, $candidateColumn)) {
            continue;
        }

        try {
            $sql = "SELECT {$candidateColumn} FROM {$table} WHERE {$userKey} = :user_id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            $value = $stmt->fetchColumn();

            if ($value !== false && $value !== null && trim((string)$value) !== '') {
                return $value;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

function detectMembershipLabel(PDO $pdo, int $userId): string
{
    $sessionCandidates = [
        $_SESSION['membership_name'] ?? '',
        $_SESSION['membership_plan'] ?? '',
        $_SESSION['plan_name'] ?? '',
        $_SESSION['member_plan'] ?? '',
    ];

    foreach ($sessionCandidates as $candidate) {
        if (trim((string)$candidate) !== '') {
            return trim((string)$candidate);
        }
    }

    $membershipTableCandidates = [
        'memberships' => ['plan_name', 'membership_name', 'name', 'plan', 'tier'],
        'members' => ['membership_name', 'membership_plan', 'plan_name', 'plan', 'tier'],
        'users' => ['membership_name', 'membership_plan', 'plan_name', 'plan', 'tier'],
    ];

    foreach ($membershipTableCandidates as $table => $columns) {
        $value = firstAvailableValue($pdo, $table, $columns, $userId);
        if ($value !== null) {
            return $value;
        }
    }

    return 'Standard Member';
}

function detectMembershipMetrics(PDO $pdo, int $userId): array
{
    $metricMap = [
        'included_walks' => ['included_walks', 'walks_included', 'walks_per_month', 'monthly_walks'],
        'daycare_days' => ['daycare_days', 'included_daycare_days', 'daycare_per_month'],
        'boarding_nights' => ['boarding_nights', 'included_boarding_nights', 'boarding_per_month'],
        'yearly_credit' => ['yearly_credit', 'annual_credit', 'member_credit'],
        'free_gifts' => ['free_gifts', 'gifts_included', 'gift_count'],
        'priority_booking' => ['priority_booking', 'priority_access', 'priority_scheduling'],
        'rollover_walks' => ['rollover_walks', 'walk_rollover', 'rollover_enabled'],
        'member_rate' => ['member_rate', 'preferred_walk_rate', 'walk_rate_member'],
    ];

    $tables = ['memberships', 'members', 'users'];
    $results = [];

    foreach ($metricMap as $metricKey => $candidateColumns) {
        foreach ($tables as $table) {
            $value = firstAvailableMixedValue($pdo, $table, $candidateColumns, $userId);
            if ($value !== null) {
                $results[$metricKey] = $value;
                break;
            }
        }
    }

    return $results;
}

function buildBookingSelectSql(PDO $pdo, int $limit = 5, bool $upcomingOnly = false): string
{
    $bookingColumns = getTableColumns($pdo, 'bookings');
    $petColumns = getTableColumns($pdo, 'pets');

    $hasPetId = hasColumn($bookingColumns, 'pet_id');
    $hasBookingPetName = hasColumn($bookingColumns, 'pet_name');
    $hasPetsTablePetName = hasColumn($petColumns, 'pet_name');
    $hasPetsTableId = hasColumn($petColumns, 'id');

    $petNameSelect = "NULL AS booking_pet_name";
    $joinClause = "";

    if ($hasPetId && $hasPetsTableId && $hasPetsTablePetName) {
        $petNameSelect = "p.pet_name AS booking_pet_name";
        $joinClause = " LEFT JOIN pets p ON b.pet_id = p.id ";
    } elseif ($hasBookingPetName) {
        $petNameSelect = "b.pet_name AS booking_pet_name";
    }

    $whereClause = "WHERE b.user_id = ?";

    if ($upcomingOnly) {
        $whereClause .= " AND b.service_date >= date('now') AND b.status NOT IN ('Cancelled', 'Completed')";
        $orderClause = "ORDER BY b.service_date ASC, b.service_time ASC";
    } else {
        $orderClause = "ORDER BY b.created_at DESC";
    }

    return "
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
        {$whereClause}
        {$orderClause}
        LIMIT {$limit}
    ";
}

function countWalksUsedThisMonth(PDO $pdo, int $userId): int
{
    if (!tableExists($pdo, 'bookings')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'bookings');
    if (!hasColumn($columns, 'service_type') || !hasColumn($columns, 'service_date') || !hasColumn($columns, 'status') || !hasColumn($columns, 'user_id')) {
        return 0;
    }

    try {
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');

        $sql = "
            SELECT COUNT(*)
            FROM bookings
            WHERE user_id = :user_id
              AND date(service_date) >= date(:month_start)
              AND date(service_date) <= date(:today)
              AND LOWER(status) NOT IN ('cancelled', 'canceled')
              AND (
                    LOWER(service_type) = 'walk'
                    OR LOWER(service_type) = 'dog-walk'
                    OR LOWER(service_type) = 'dog walk'
                    OR LOWER(service_type) LIKE '%walk%'
              )
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'month_start' => $monthStart,
            'today' => $today,
        ]);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
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

function bookingPetLabel(array $booking): string
{
    $petName = trim((string)($booking['booking_pet_name'] ?? ''));

    return $petName !== '' ? $petName : 'Pet not specified';
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

function formatMembershipLabel(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return 'Standard Member';
    }
    return ucwords(str_replace(['-', '_'], ' ', $label));
}

function truthyValue($value): bool
{
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
}

function buildMembershipMetrics(array $rawMetrics, ?int $includedWalks = null, int $walksUsedThisMonth = 0, ?int $walksRemaining = null): array
{
    $metrics = [];

    if ($includedWalks !== null) {
        $metrics[] = [
            'label' => 'Included Walks',
            'value' => (string)$includedWalks,
        ];
        $metrics[] = [
            'label' => 'Used This Month',
            'value' => (string)$walksUsedThisMonth,
        ];
        $metrics[] = [
            'label' => 'Walks Remaining',
            'value' => (string)max(0, (int)$walksRemaining),
        ];
    } elseif (!empty($rawMetrics['included_walks'])) {
        $metrics[] = [
            'label' => 'Included Walks',
            'value' => (string)$rawMetrics['included_walks'],
        ];
    }

    if (!empty($rawMetrics['daycare_days'])) {
        $metrics[] = [
            'label' => 'Daycare Days',
            'value' => (string)$rawMetrics['daycare_days'],
        ];
    }

    if (!empty($rawMetrics['boarding_nights'])) {
        $metrics[] = [
            'label' => 'Boarding Nights',
            'value' => (string)$rawMetrics['boarding_nights'],
        ];
    }

    if (!empty($rawMetrics['yearly_credit'])) {
        $value = trim((string)$rawMetrics['yearly_credit']);
        if ($value !== '') {
            if (is_numeric($value) && str_starts_with($value, '$') === false) {
                $value = '$' . number_format((float)$value, 2);
            }
            $metrics[] = [
                'label' => 'Yearly Credit',
                'value' => $value,
            ];
        }
    }

    if (!empty($rawMetrics['free_gifts'])) {
        $metrics[] = [
            'label' => 'Gift Count',
            'value' => (string)$rawMetrics['free_gifts'],
        ];
    }

    if (!empty($rawMetrics['member_rate'])) {
        $value = trim((string)$rawMetrics['member_rate']);
        if ($value !== '') {
            if (is_numeric($value) && str_starts_with($value, '$') === false) {
                $value = '$' . number_format((float)$value, 2);
            }
            $metrics[] = [
                'label' => 'Member Rate',
                'value' => $value,
            ];
        }
    }

    if (!empty($rawMetrics['priority_booking']) && truthyValue($rawMetrics['priority_booking'])) {
        $metrics[] = [
            'label' => 'Priority Access',
            'value' => 'Included',
        ];
    }

    if (!empty($rawMetrics['rollover_walks']) && truthyValue($rawMetrics['rollover_walks'])) {
        $metrics[] = [
            'label' => 'Rollover',
            'value' => 'Enabled',
        ];
    }

    return $metrics;
}

function getMembershipBenefits(
    string $membershipLabel,
    array $rawMetrics = [],
    ?int $includedWalks = null,
    int $walksUsedThisMonth = 0,
    ?int $walksRemaining = null
): array {
    $normalized = strtolower(trim($membershipLabel));

    if (str_contains($normalized, 'founder')) {
        $config = [
            'title' => 'Founder Benefits',
            'subtitle' => 'Your highest-tier membership includes your strongest ongoing value and priority access.',
            'items' => [
                'Priority booking access for premium scheduling',
                'Rollover-friendly value structure where applicable',
                'Founder-level perks and elevated client treatment',
                'Preferred recurring care experience',
            ],
        ];
    } elseif (str_contains($normalized, 'gold')) {
        $config = [
            'title' => 'Gold Membership Benefits',
            'subtitle' => 'Your plan is designed for stronger recurring value and premium care access.',
            'items' => [
                'Preferred member pricing on eligible services',
                'Stronger monthly value than standard booking',
                'Higher-tier perks and premium care positioning',
                'Better fit for recurring service routines',
            ],
        ];
    } elseif (str_contains($normalized, 'silver')) {
        $config = [
            'title' => 'Silver Membership Benefits',
            'subtitle' => 'A strong recurring plan built for consistent care and member-only value.',
            'items' => [
                'Preferred pricing compared with public booking',
                'Recurring care structure for easier scheduling',
                'Member-level perks and loyalty value',
                'Cleaner monthly care planning',
            ],
        ];
    } elseif (str_contains($normalized, 'walk club')) {
        $config = [
            'title' => 'Walk Club Benefits',
            'subtitle' => 'Your plan is focused on simpler, stronger recurring walk value.',
            'items' => [
                'Walk-centered recurring value',
                'Preferred member pricing on added eligible walks',
                'A cleaner monthly structure for regular care',
                'Better value than one-off public booking',
            ],
        ];
    } elseif (str_contains($normalized, 'vip') || str_contains($normalized, 'premium')) {
        $config = [
            'title' => 'Premium Member Benefits',
            'subtitle' => 'Your plan is built for elevated care access and a more exclusive experience.',
            'items' => [
                'Premium service positioning and stronger care access',
                'Priority scheduling support where available',
                'Preferred value compared with standard booking',
                'More elevated overall member experience',
            ],
        ];
    } else {
        $config = [
            'title' => 'Member Benefits',
            'subtitle' => 'Your account includes member-level access and a more elevated care experience.',
            'items' => [
                'Simpler access to recurring booking routines',
                'Member-oriented care experience and account support',
                'Cleaner path to premium services and upgrades',
                'A more polished overall client experience',
            ],
        ];
    }

    $config['metrics'] = buildMembershipMetrics($rawMetrics, $includedWalks, $walksUsedThisMonth, $walksRemaining);

    return $config;
}

try {
    $petCountStmt = $pdo->prepare("SELECT COUNT(*) FROM pets WHERE user_id = ?");
    $petCountStmt->execute([$userId]);
    $petCount = (int)$petCountStmt->fetchColumn();

    $bookingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
    $bookingCountStmt->execute([$userId]);
    $bookingCount = (int)$bookingCountStmt->fetchColumn();

    $membershipLabel = detectMembershipLabel($pdo, (int)$userId);
    $membershipMetrics = detectMembershipMetrics($pdo, (int)$userId);

    if (isset($membershipMetrics['included_walks']) && is_numeric((string)$membershipMetrics['included_walks'])) {
        $includedWalks = (int)$membershipMetrics['included_walks'];
    }

    $walksUsedThisMonth = countWalksUsedThisMonth($pdo, (int)$userId);

    if ($includedWalks !== null) {
        $walksRemaining = max(0, $includedWalks - $walksUsedThisMonth);
    }

    $membershipBenefits = getMembershipBenefits(
        $membershipLabel,
        $membershipMetrics,
        $includedWalks,
        $walksUsedThisMonth,
        $walksRemaining
    );

    $petsStmt = $pdo->prepare("
        SELECT id, pet_name, breed, age, weight, gender, birthday, status, created_at
        FROM pets
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $petsStmt->execute([$userId]);
    $recentPets = $petsStmt->fetchAll();

    $upcomingSql = buildBookingSelectSql($pdo, 5, true);
    $upcomingStmt = $pdo->prepare($upcomingSql);
    $upcomingStmt->execute([$userId]);
    $upcomingBookings = $upcomingStmt->fetchAll();

    if (!empty($upcomingBookings)) {
        $nextBooking = $upcomingBookings[0];
    }

    $recentSql = buildBookingSelectSql($pdo, 5, false);
    $recentStmt = $pdo->prepare($recentSql);
    $recentStmt->execute([$userId]);
    $recentBookings = $recentStmt->fetchAll();
} catch (PDOException $e) {
    die('Dashboard error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Doggie Dorian's</title>
    <meta name="description" content="Manage your pets, upcoming bookings, and premium care experience with Doggie Dorian's.">
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
            --gold-soft: rgba(215,178,106,0.12);
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

        .topnav a:hover {
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

        .hero-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
            align-items: start;
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

        .membership-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(215,178,106,0.28);
            background: rgba(215,178,106,0.12);
            color: #f5ddaf;
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.03em;
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

        .next-booking-card {
            border-radius: 24px;
            padding: 22px;
            border: 1px solid rgba(215,178,106,0.22);
            background:
                linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
            box-shadow: var(--shadow);
        }

        .next-booking-card h2 {
            color: var(--white);
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .next-booking-card p {
            color: var(--muted);
            font-size: 0.96rem;
            margin-bottom: 16px;
        }

        .next-booking-highlight {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #f5ddaf;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .next-booking-meta {
            display: grid;
            gap: 10px;
            margin-bottom: 16px;
        }

        .next-booking-meta div {
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--muted);
            font-size: 0.94rem;
        }

        .next-booking-meta strong {
            color: var(--white);
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

        .content-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 22px;
        }

        .right-stack {
            display: grid;
            gap: 22px;
        }

        .card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 28px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 2rem;
            color: var(--white);
        }

        .card-subtext {
            margin: 0 0 22px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 0.98rem;
        }

        .pets-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .pet-card {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 18px;
            background: rgba(255,255,255,0.02);
        }

        .pet-name {
            margin: 0 0 8px;
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--white);
        }

        .pet-meta {
            margin: 0 0 10px;
            color: var(--muted);
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .pet-status {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(159,224,177,0.10);
            border: 1px solid rgba(159,224,177,0.24);
            color: var(--success);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .booking-list {
            display: grid;
            gap: 14px;
        }

        .booking-item {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 18px;
            background: rgba(255,255,255,0.02);
        }

        .booking-title {
            margin: 0 0 8px;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--white);
        }

        .booking-pet {
            margin: 0 0 10px;
            color: #f2d9a8;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .booking-meta {
            margin: 0;
            color: var(--muted);
            line-height: 1.65;
            font-size: 0.95rem;
        }

        .benefits-box {
            border-radius: 20px;
            padding: 18px;
            border: 1px solid rgba(215,178,106,0.18);
            background: rgba(215,178,106,0.08);
        }

        .benefits-box h3 {
            color: var(--white);
            font-size: 1.15rem;
            margin-bottom: 8px;
        }

        .benefits-box p {
            color: var(--muted);
            font-size: 0.94rem;
            margin-bottom: 14px;
        }

        .benefits-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .benefit-metric {
            border-radius: 16px;
            padding: 14px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .benefit-metric strong {
            display: block;
            color: #f5ddaf;
            font-size: 1.05rem;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .benefit-metric span {
            color: var(--muted);
            font-size: 0.86rem;
        }

        .benefits-list {
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .benefits-list li {
            position: relative;
            padding-left: 20px;
            color: var(--muted);
            font-size: 0.94rem;
        }

        .benefits-list li::before {
            content: "✦";
            position: absolute;
            left: 0;
            top: 0;
            color: var(--gold);
        }

        .status-badge {
            display: inline-block;
            margin-top: 12px;
            padding: 7px 10px;
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

        .empty-state {
            border: 1px dashed rgba(255,255,255,0.14);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.02);
        }

        .empty-state p + p {
            margin-top: 8px;
        }

        .empty-state a {
            color: var(--gold);
            font-weight: 700;
        }

        .quick-links {
            display: grid;
            gap: 14px;
        }

        .quick-links a {
            color: var(--white);
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 16px 18px;
            font-weight: 700;
            transition: 0.22s ease;
        }

        .quick-links a:hover {
            border-color: rgba(215,178,106,0.24);
            background: rgba(215,178,106,0.08);
            color: var(--gold);
        }

        @media (max-width: 1100px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .benefits-metrics {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 980px) {
            .hero-grid,
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .page {
                padding: 20px 14px 50px;
            }

            .hero {
                padding: 26px;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .pets-grid,
            .benefits-metrics {
                grid-template-columns: 1fr;
            }

            .card,
            .next-booking-card {
                padding: 22px;
            }

            .brand {
                font-size: 1.4rem;
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
                    <a href="add-pet.php">Add Pet</a>
                    <a href="book-walk.php">Book a Service</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <div class="hero-grid">
                    <div>
                        <div class="eyebrow">Member Dashboard</div>
                        <div class="membership-badge"><?php echo htmlspecialchars(formatMembershipLabel($membershipLabel)); ?></div>
                        <h1>Welcome back, <?php echo htmlspecialchars($fullName); ?></h1>
                        <p>
                            Manage your dogs, review your upcoming services, and enjoy a premium care experience designed for convenience, trust, and elevated service.
                        </p>
                        <div class="hero-actions">
                            <a class="btn btn-primary" href="add-pet.php">Add a Dog</a>
                            <a class="btn btn-secondary" href="book-walk.php">Book a Service</a>
                        </div>
                    </div>

                    <div class="next-booking-card">
                        <h2>Your Next Booking</h2>

                        <?php if ($nextBooking): ?>
                            <span class="next-booking-highlight">
                                <?php echo htmlspecialchars(formatServiceName($nextBooking['service_type'])); ?>
                            </span>

                            <p>Your next scheduled service is ready at a glance.</p>

                            <div class="next-booking-meta">
                                <div><strong>Pet:</strong> <?php echo htmlspecialchars(bookingPetLabel($nextBooking)); ?></div>
                                <div><strong>Date:</strong> <?php echo formatDisplayDate($nextBooking['service_date'] ?? ''); ?></div>
                                <div><strong>Time:</strong> <?php echo formatDisplayTime($nextBooking['service_time'] ?? ''); ?></div>
                                <div>
                                    <strong>Duration:</strong>
                                    <?php echo $nextBooking['duration_minutes'] !== null ? htmlspecialchars((string)$nextBooking['duration_minutes']) . ' mins' : 'N/A'; ?>
                                </div>
                                <div><strong>Price:</strong> $<?php echo number_format((float)$nextBooking['price'], 2); ?></div>
                            </div>

                            <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string)$nextBooking['status'])); ?>">
                                <?php echo htmlspecialchars($nextBooking['status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="next-booking-highlight">No upcoming booking</span>
                            <p>You do not currently have a future service scheduled.</p>
                            <a class="btn btn-primary" href="book-walk.php">Book Your Next Service</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="stats">
                <div class="stat-card">
                    <p class="stat-label">Your Dogs</p>
                    <p class="stat-value"><?php echo $petCount; ?></p>
                    <p class="stat-sub">Pet profiles connected to your account</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Total Bookings</p>
                    <p class="stat-value"><?php echo $bookingCount; ?></p>
                    <p class="stat-sub">Your full booking history to date</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Membership</p>
                    <p class="stat-value"><?php echo htmlspecialchars(formatMembershipLabel($membershipLabel)); ?></p>
                    <p class="stat-sub">Your current plan or access level</p>
                </div>

                <div class="stat-card">
                    <p class="stat-label">Account Type</p>
                    <p class="stat-value"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'member')); ?></p>
                    <p class="stat-sub">Your current client account access level</p>
                </div>
            </section>

            <section class="content-grid">
                <div class="card">
                    <h2>Your Dogs</h2>
                    <p class="card-subtext">Every pet profile helps us deliver a more personal, safe, and elevated care experience.</p>

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

                <div class="right-stack">
                    <div class="card">
                        <h2>Membership Benefits</h2>
                        <p class="card-subtext">Your current plan includes member-level advantages designed to make your care experience smoother and more valuable.</p>

                        <div class="benefits-box">
                            <h3><?php echo htmlspecialchars($membershipBenefits['title']); ?></h3>
                            <p><?php echo htmlspecialchars($membershipBenefits['subtitle']); ?></p>

                            <?php if (!empty($membershipBenefits['metrics'])): ?>
                                <div class="benefits-metrics">
                                    <?php foreach ($membershipBenefits['metrics'] as $metric): ?>
                                        <div class="benefit-metric">
                                            <strong><?php echo htmlspecialchars($metric['value']); ?></strong>
                                            <span><?php echo htmlspecialchars($metric['label']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <ul class="benefits-list">
                                <?php foreach ($membershipBenefits['items'] as $item): ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Upcoming Bookings</h2>
                        <p class="card-subtext">Your next scheduled services appear here first.</p>

                        <?php if (count($upcomingBookings) > 0): ?>
                            <div class="booking-list">
                                <?php foreach ($upcomingBookings as $booking): ?>
                                    <div class="booking-item">
                                        <h3 class="booking-title">
                                            <?php echo htmlspecialchars(formatServiceName($booking['service_type'])); ?>
                                        </h3>
                                        <p class="booking-pet">Pet: <?php echo htmlspecialchars(bookingPetLabel($booking)); ?></p>
                                        <p class="booking-meta">
                                            Date: <?php echo formatDisplayDate($booking['service_date'] ?? ''); ?><br>
                                            Time: <?php echo formatDisplayTime($booking['service_time'] ?? ''); ?><br>
                                            Duration:
                                            <?php echo $booking['duration_minutes'] !== null ? htmlspecialchars((string)$booking['duration_minutes']) . ' mins' : 'N/A'; ?><br>
                                            Price: $<?php echo number_format((float)$booking['price'], 2); ?>
                                        </p>
                                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string)$booking['status'])); ?>">
                                            <?php echo htmlspecialchars($booking['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No upcoming bookings scheduled yet.</p>
                                <p><a href="book-walk.php">Book your next service</a></p>
                            </div>
                        <?php endif; ?>
                    </div>

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
                                        <p class="booking-pet">Pet: <?php echo htmlspecialchars(bookingPetLabel($booking)); ?></p>
                                        <p class="booking-meta">
                                            Date: <?php echo formatDisplayDate($booking['service_date'] ?? ''); ?><br>
                                            Time: <?php echo formatDisplayTime($booking['service_time'] ?? ''); ?><br>
                                            Duration:
                                            <?php echo $booking['duration_minutes'] !== null ? htmlspecialchars((string)$booking['duration_minutes']) . ' mins' : 'N/A'; ?><br>
                                            Price: $<?php echo number_format((float)$booking['price'], 2); ?>
                                        </p>
                                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string)$booking['status'])); ?>">
                                            <?php echo htmlspecialchars($booking['status']); ?>
                                        </span>
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

                    <div class="card">
                        <h2>Quick Actions</h2>
                        <p class="card-subtext">Keep your account moving with the next best steps.</p>

                        <div class="quick-links">
                            <a href="add-pet.php">Add a New Pet Profile</a>
                            <a href="book-walk.php">Book Premium Care</a>
                            <a href="profile.php">Update Account Details</a>
                            <a href="logout.php">Sign Out</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>