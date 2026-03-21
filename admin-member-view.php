<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/data/config/db.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y', $timestamp);
}

function formatDateTime(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatMoney($amount): string
{
    if ($amount === null || $amount === '') {
        return 'N/A';
    }

    return '$' . number_format((float)$amount, 2);
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

$userId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    die('Invalid member ID.');
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from data/config/db.php.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    $userStmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Member not found.');
    }

    $dogs = [];

    if (tableExists($pdo, 'dogs')) {
        $dogColumns = getColumns($pdo, 'dogs');

        $dogOwnerCol = pickExistingColumn($dogColumns, ['user_id', 'member_id', 'owner_id', 'client_id']);
        $dogNameCol = pickExistingColumn($dogColumns, ['name', 'pet_name', 'dog_name']);
        $dogBreedCol = pickExistingColumn($dogColumns, ['breed']);
        $dogAgeCol = pickExistingColumn($dogColumns, ['age', 'dog_age']);
        $dogNotesCol = pickExistingColumn($dogColumns, ['notes', 'care_notes']);
        $dogCreatedCol = pickExistingColumn($dogColumns, ['created_at', 'created_on']);

        if ($dogOwnerCol !== null) {
            $selectParts = [
                "id",
                $dogNameCol !== null ? "$dogNameCol AS display_name" : "'Dog' AS display_name",
                $dogBreedCol !== null ? "$dogBreedCol AS display_breed" : "NULL AS display_breed",
                $dogAgeCol !== null ? "$dogAgeCol AS display_age" : "NULL AS display_age",
                $dogNotesCol !== null ? "$dogNotesCol AS display_notes" : "NULL AS display_notes",
                $dogCreatedCol !== null ? "$dogCreatedCol AS display_created" : "NULL AS display_created",
            ];

            $orderBy = $dogCreatedCol !== null ? "$dogCreatedCol DESC" : "id DESC";

            $dogSql = "
                SELECT " . implode(", ", $selectParts) . "
                FROM dogs
                WHERE $dogOwnerCol = ?
                ORDER BY $orderBy
            ";

            $dogStmt = $pdo->prepare($dogSql);
            $dogStmt->execute([$userId]);
            $dogs = $dogStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $bookings = [];

    if (tableExists($pdo, 'bookings')) {
        $bookingColumns = getColumns($pdo, 'bookings');

        $bookingUserCol = pickExistingColumn($bookingColumns, ['member_id', 'user_id', 'client_id']);
        $bookingServiceCol = pickExistingColumn($bookingColumns, ['service_type', 'service']);
        $bookingDateCol = pickExistingColumn($bookingColumns, ['service_date', 'booking_date', 'created_at']);
        $bookingTimeCol = pickExistingColumn($bookingColumns, ['service_time']);
        $bookingDurationCol = pickExistingColumn($bookingColumns, ['duration_minutes']);
        $bookingStatusCol = pickExistingColumn($bookingColumns, ['status']);
        $bookingPriceCol = pickExistingColumn($bookingColumns, ['price', 'estimated_price', 'amount']);
        $bookingNotesCol = pickExistingColumn($bookingColumns, ['notes', 'client_notes']);

        if ($bookingUserCol !== null) {
            $selectParts = [
                "id",
                $bookingServiceCol !== null ? "$bookingServiceCol AS display_service" : "'Service' AS display_service",
                $bookingDateCol !== null ? "$bookingDateCol AS display_date" : "NULL AS display_date",
                $bookingTimeCol !== null ? "$bookingTimeCol AS display_time" : "NULL AS display_time",
                $bookingDurationCol !== null ? "$bookingDurationCol AS display_duration" : "NULL AS display_duration",
                $bookingStatusCol !== null ? "$bookingStatusCol AS display_status" : "NULL AS display_status",
                $bookingPriceCol !== null ? "$bookingPriceCol AS display_price" : "NULL AS display_price",
                $bookingNotesCol !== null ? "$bookingNotesCol AS display_notes" : "NULL AS display_notes",
            ];

            $orderBy = $bookingDateCol !== null ? "$bookingDateCol DESC" : "id DESC";

            $bookingSql = "
                SELECT " . implode(", ", $selectParts) . "
                FROM bookings
                WHERE $bookingUserCol = ?
                ORDER BY $orderBy
                LIMIT 15
            ";

            $bookingStmt = $pdo->prepare($bookingSql);
            $bookingStmt->execute([$userId]);
            $bookings = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $clientProfile = null;

    if (tableExists($pdo, 'client_profiles')) {
        $profileColumns = getColumns($pdo, 'client_profiles');
        $profileUserCol = pickExistingColumn($profileColumns, ['user_id', 'member_id', 'client_id']);

        if ($profileUserCol !== null) {
            $profileStmt = $pdo->prepare("
                SELECT *
                FROM client_profiles
                WHERE $profileUserCol = ?
                LIMIT 1
            ");
            $profileStmt->execute([$userId]);
            $clientProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

} catch (Throwable $e) {
    die('Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile | Doggie Dorian's Admin</title>
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

        .container{
            max-width:1200px;
            margin:40px auto;
            padding:20px;
        }

        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:16px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }

        .topbar h1{
            margin:0 0 8px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
        }

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:12px 16px;
            border-radius:14px;
            text-decoration:none;
            font-weight:800;
        }

        .btn-primary{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }

        .btn-secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
        }

        .section{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:24px;
            margin-bottom:20px;
            box-shadow:var(--shadow);
        }

        .section h2{
            margin:0 0 14px;
            font-size:26px;
            letter-spacing:-0.4px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:14px;
        }

        .box{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .label{
            color:var(--gold-soft);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:6px;
            font-weight:800;
        }

        .value{
            color:var(--text);
            font-size:15px;
            line-height:1.5;
        }

        .list{
            display:grid;
            gap:14px;
        }

        .item{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:18px;
            padding:16px;
        }

        .item-title{
            font-size:18px;
            font-weight:800;
            margin-bottom:8px;
        }

        .item-meta{
            color:var(--muted);
            line-height:1.7;
            font-size:14px;
        }

        .empty{
            border:1px dashed rgba(255,255,255,0.14);
            border-radius:18px;
            padding:24px;
            text-align:center;
            color:var(--muted);
            background:rgba(255,255,255,0.03);
        }

        @media (max-width: 1100px){
            .grid{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
        }

        @media (max-width: 700px){
            .grid{
                grid-template-columns:1fr;
            }

            .topbar h1{
                font-size:32px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="topbar">
        <div>
            <h1><?php echo h($user['full_name'] ?? 'Member'); ?></h1>
            <div class="sub">Full member profile, pets, and booking history.</div>
        </div>

        <div class="actions">
            <a href="admin-add-dog.php?user_id=<?php echo (int)$userId; ?>" class="btn btn-primary">+ Add Dog</a>
            <a href="admin-create-booking.php?user_id=<?php echo (int)$userId; ?>" class="btn btn-primary">+ Create Booking</a>
            <a href="admin-members.php" class="btn btn-secondary">← Back to Members</a>
        </div>
    </div>

    <section class="section">
        <h2>Member Information</h2>
        <div class="grid">
            <div class="box">
                <div class="label">Full Name</div>
                <div class="value"><?php echo h($user['full_name'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Email</div>
                <div class="value"><?php echo h($user['email'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Phone</div>
                <div class="value"><?php echo h($user['phone'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Status</div>
                <div class="value"><?php echo h($user['status'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Role</div>
                <div class="value"><?php echo h($user['role'] ?? 'member'); ?></div>
            </div>

            <div class="box">
                <div class="label">Joined</div>
                <div class="value"><?php echo formatDate($user['created_at'] ?? ''); ?></div>
            </div>

            <div class="box">
                <div class="label">User ID</div>
                <div class="value"><?php echo h((string)($user['id'] ?? 'N/A')); ?></div>
            </div>

            <div class="box">
                <div class="label">Client Profile</div>
                <div class="value"><?php echo $clientProfile ? 'Found' : 'Not found'; ?></div>
            </div>
        </div>
    </section>

    <section class="section">
        <h2>Dogs</h2>

        <?php if (empty($dogs)): ?>
            <div class="empty">No dogs found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($dogs as $dog): ?>
                    <div class="item">
                        <div class="item-title"><?php echo h($dog['display_name'] ?? 'Dog'); ?></div>
                        <div class="item-meta">
                            Breed: <?php echo h($dog['display_breed'] ?? 'N/A'); ?><br>
                            Age: <?php echo h((string)($dog['display_age'] ?? 'N/A')); ?><br>
                            Notes: <?php echo h($dog['display_notes'] ?? 'N/A'); ?><br>
                            Added: <?php echo formatDateTime($dog['display_created'] ?? ''); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Recent Bookings</h2>

        <?php if (empty($bookings)): ?>
            <div class="empty">No bookings found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($bookings as $booking): ?>
                    <div class="item">
                        <div class="item-title"><?php echo h($booking['display_service'] ?? 'Service'); ?></div>
                        <div class="item-meta">
                            Date: <?php echo formatDate($booking['display_date'] ?? ''); ?><br>
                            Time: <?php echo h($booking['display_time'] ?? 'N/A'); ?><br>
                            Duration: <?php echo h((string)($booking['display_duration'] ?? 'N/A')); ?><br>
                            Status: <?php echo h($booking['display_status'] ?? 'N/A'); ?><br>
                            Price: <?php echo formatMoney($booking['display_price'] ?? null); ?><br>
                            Notes: <?php echo h($booking['display_notes'] ?? 'N/A'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

</body>
</html>