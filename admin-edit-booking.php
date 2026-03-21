<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/data/config/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
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

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$bookingId = (int)($_GET['id'] ?? $_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
    die('Invalid booking ID.');
}

$users = [];
$dogs = [];
$booking = null;

$serviceOptions = [
    'walk' => 'Walk',
    'daycare' => 'Daycare',
    'boarding' => 'Boarding',
    'drop-in visit' => 'Drop-In Visit',
    'pet taxi' => 'Pet Taxi',
];

$statusOptions = [
    'Requested',
    'Pending',
    'Scheduled',
    'Confirmed',
    'Completed',
    'Cancelled',
];

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from data/config/db.php.');
    }

    if (!tableExists($pdo, 'bookings')) {
        throw new RuntimeException('The bookings table was not found.');
    }

    $bookingColumns = getColumns($pdo, 'bookings');

    $userCol = pickExistingColumn($bookingColumns, ['user_id', 'member_id', 'client_id']);
    $dogCol = pickExistingColumn($bookingColumns, ['dog_id', 'pet_id']);
    $serviceCol = pickExistingColumn($bookingColumns, ['service_type', 'service']);
    $dateCol = pickExistingColumn($bookingColumns, ['service_date', 'booking_date']);
    $timeCol = hasColumn($bookingColumns, 'service_time') ? 'service_time' : null;
    $durationCol = hasColumn($bookingColumns, 'duration_minutes') ? 'duration_minutes' : null;
    $priceCol = pickExistingColumn($bookingColumns, ['price', 'estimated_price', 'amount']);
    $statusCol = pickExistingColumn($bookingColumns, ['status']);
    $notesCol = pickExistingColumn($bookingColumns, ['notes', 'client_notes']);

    if ($userCol === null || $serviceCol === null || $dateCol === null) {
        throw new RuntimeException('The bookings table is missing required columns.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    $userColumns = getColumns($pdo, 'users');
    $roleCol = hasColumn($userColumns, 'role') ? 'role' : null;

    $userSql = "
        SELECT id, full_name, email
        FROM users
    ";

    if ($roleCol !== null) {
        $userSql .= " WHERE LOWER(COALESCE(role, 'member')) != 'admin' ";
    }

    $userSql .= " ORDER BY full_name ASC, email ASC";

    $users = $pdo->query($userSql)->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | LOAD BOOKING
    |--------------------------------------------------------------------------
    */
    $selectParts = [
        "id",
        "$userCol AS booking_user_id",
        "$serviceCol AS booking_service",
        "$dateCol AS booking_date",
    ];

    $selectParts[] = $dogCol !== null ? "$dogCol AS booking_dog_id" : "NULL AS booking_dog_id";
    $selectParts[] = $timeCol !== null ? "$timeCol AS booking_time" : "NULL AS booking_time";
    $selectParts[] = $durationCol !== null ? "$durationCol AS booking_duration" : "NULL AS booking_duration";
    $selectParts[] = $priceCol !== null ? "$priceCol AS booking_price" : "NULL AS booking_price";
    $selectParts[] = $statusCol !== null ? "$statusCol AS booking_status" : "'Scheduled' AS booking_status";
    $selectParts[] = $notesCol !== null ? "$notesCol AS booking_notes" : "NULL AS booking_notes";

    $loadSql = "
        SELECT " . implode(", ", $selectParts) . "
        FROM bookings
        WHERE id = ?
        LIMIT 1
    ";

    $loadStmt = $pdo->prepare($loadSql);
    $loadStmt->execute([$bookingId]);
    $booking = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new RuntimeException('Booking not found.');
    }

    $selectedUserId = (int)($booking['booking_user_id'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | LOAD DOGS FOR CURRENT USER
    |--------------------------------------------------------------------------
    */
    if (tableExists($pdo, 'dogs') && $selectedUserId > 0) {
        $dogColumns = getColumns($pdo, 'dogs');

        $dogOwnerCol = pickExistingColumn($dogColumns, ['user_id', 'member_id', 'owner_id', 'client_id']);
        $dogNameCol = pickExistingColumn($dogColumns, ['name', 'pet_name', 'dog_name']);

        if ($dogOwnerCol !== null) {
            $dogSql = "
                SELECT
                    id,
                    " . ($dogNameCol !== null ? $dogNameCol . " AS display_name" : "'Dog' AS display_name") . "
                FROM dogs
                WHERE $dogOwnerCol = ?
                ORDER BY id DESC
            ";

            $dogStmt = $pdo->prepare($dogSql);
            $dogStmt->execute([$selectedUserId]);
            $dogs = $dogStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE UPDATE
    |--------------------------------------------------------------------------
    */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newUserId = (int)($_POST['user_id'] ?? 0);
        $newDogId = (int)($_POST['dog_id'] ?? 0);
        $newService = trim((string)($_POST['service_type'] ?? ''));
        $newDate = trim((string)($_POST['service_date'] ?? ''));
        $newTime = trim((string)($_POST['service_time'] ?? ''));
        $newDuration = trim((string)($_POST['duration_minutes'] ?? ''));
        $newPrice = trim((string)($_POST['price'] ?? ''));
        $newStatus = trim((string)($_POST['status'] ?? 'Scheduled'));
        $newNotes = trim((string)($_POST['notes'] ?? ''));

        if ($newUserId <= 0) {
            $errorMessage = 'Please choose a member.';
        } elseif ($newService === '') {
            $errorMessage = 'Please choose a service type.';
        } elseif ($newDate === '') {
            $errorMessage = 'Please choose a service date.';
        } else {
            $setParts = [
                "$userCol = ?",
                "$serviceCol = ?",
                "$dateCol = ?",
            ];

            $values = [
                $newUserId,
                $newService,
                $newDate,
            ];

            if ($dogCol !== null) {
                $setParts[] = "$dogCol = ?";
                $values[] = $newDogId > 0 ? $newDogId : null;
            }

            if ($timeCol !== null) {
                $setParts[] = "$timeCol = ?";
                $values[] = $newTime !== '' ? $newTime : null;
            }

            if ($durationCol !== null) {
                $setParts[] = "$durationCol = ?";
                $values[] = $newDuration !== '' ? (int)$newDuration : null;
            }

            if ($priceCol !== null) {
                $setParts[] = "$priceCol = ?";
                $values[] = $newPrice !== '' ? (float)$newPrice : null;
            }

            if ($statusCol !== null) {
                $setParts[] = "$statusCol = ?";
                $values[] = $newStatus;
            }

            if ($notesCol !== null) {
                $setParts[] = "$notesCol = ?";
                $values[] = $newNotes;
            }

            $values[] = $bookingId;

            $updateSql = "
                UPDATE bookings
                SET " . implode(", ", $setParts) . "
                WHERE id = ?
            ";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($values);

            header('Location: admin-edit-booking.php?id=' . $bookingId . '&updated=1');
            exit;
        }
    }

    if (isset($_GET['updated']) && $_GET['updated'] === '1') {
        $successMessage = 'Booking updated successfully.';
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
    <title>Edit Booking | Doggie Dorian's Admin</title>
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
            --success:#9fe0b1;
            --danger:#ff9d9d;
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

        .card{
            max-width:860px;
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:24px;
            box-shadow:var(--shadow);
        }

        .message{
            margin-bottom:18px;
            padding:14px 16px;
            border-radius:16px;
            font-weight:700;
        }

        .message.success{
            background:rgba(159,224,177,0.10);
            border:1px solid rgba(159,224,177,0.30);
            color:var(--success);
        }

        .message.error{
            background:rgba(255,157,157,0.10);
            border:1px solid rgba(255,157,157,0.30);
            color:var(--danger);
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:16px;
        }

        .field{
            margin-bottom:16px;
        }

        .field.full{
            grid-column:1 / -1;
        }

        label{
            display:block;
            margin-bottom:8px;
            color:var(--gold-soft);
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        input, textarea, select{
            width:100%;
            padding:14px 15px;
            border-radius:14px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.05);
            color:var(--text);
            font:inherit;
        }

        textarea{
            min-height:120px;
            resize:vertical;
        }

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:12px;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border:none;
            cursor:pointer;
            min-height:48px;
            padding:12px 18px;
            border-radius:14px;
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

        .error-box{
            border:1px solid rgba(255,0,0,0.25);
            background:rgba(255,0,0,0.08);
            padding:16px 18px;
            border-radius:16px;
            color:#ffd1d1;
            white-space:pre-wrap;
            word-break:break-word;
            max-width:860px;
        }

        @media (max-width: 900px){
            .shell{ grid-template-columns:1fr; }
            .main{ padding:20px; }
            .grid{ grid-template-columns:1fr; }
        }

        @media (max-width: 640px){
            .header h1{ font-size:32px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Doggie <span>Dorian’s</span></div>
        <div class="tag">Premium admin control panel for bookings, members, dogs, and operations.</div>

        <nav class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php" class="active">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php">Members</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <section class="header">
            <div>
                <h1>Edit Booking</h1>
                <div class="sub">Update booking details, service info, and scheduling.</div>
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Edit booking error:</strong><br>
                <?php echo h($fatalError); ?>
            </div>
        <?php else: ?>
            <div class="card">
                <?php if ($successMessage !== ''): ?>
                    <div class="message success"><?php echo h($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="message error"><?php echo h($errorMessage); ?></div>
                <?php endif; ?>

                <form method="post" action="admin-edit-booking.php?id=<?php echo (int)$bookingId; ?>">
                    <input type="hidden" name="booking_id" value="<?php echo (int)$bookingId; ?>">

                    <div class="grid">
                        <div class="field">
                            <label for="user_id">Member</label>
                            <select id="user_id" name="user_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>" <?php echo (int)($booking['booking_user_id'] ?? 0) === (int)$user['id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string)$user['full_name']); ?> — <?php echo h((string)$user['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="dog_id">Dog</label>
                            <select id="dog_id" name="dog_id">
                                <option value="">No dog selected</option>
                                <?php foreach ($dogs as $dog): ?>
                                    <option value="<?php echo (int)$dog['id']; ?>" <?php echo (int)($booking['booking_dog_id'] ?? 0) === (int)$dog['id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string)$dog['display_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="service_type">Service Type</label>
                            <select id="service_type" name="service_type" required>
                                <?php foreach ($serviceOptions as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>" <?php echo (string)($booking['booking_service'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="service_date">Service Date</label>
                            <input type="date" id="service_date" name="service_date" value="<?php echo h((string)($booking['booking_date'] ?? '')); ?>" required>
                        </div>

                        <div class="field">
                            <label for="service_time">Service Time</label>
                            <input type="time" id="service_time" name="service_time" value="<?php echo h((string)($booking['booking_time'] ?? '')); ?>">
                        </div>

                        <div class="field">
                            <label for="duration_minutes">Duration (minutes)</label>
                            <input type="number" id="duration_minutes" name="duration_minutes" value="<?php echo h((string)($booking['booking_duration'] ?? '')); ?>" min="0" step="1">
                        </div>

                        <div class="field">
                            <label for="price">Price</label>
                            <input type="number" id="price" name="price" value="<?php echo h((string)($booking['booking_price'] ?? '')); ?>" min="0" step="0.01">
                        </div>

                        <div class="field">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option value="<?php echo h($option); ?>" <?php echo strtolower((string)($booking['booking_status'] ?? '')) === strtolower($option) ? 'selected' : ''; ?>>
                                        <?php echo h($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field full">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes"><?php echo h((string)($booking['booking_notes'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Booking Changes</button>
                        <a class="btn btn-secondary" href="admin-bookings.php">Back to Booking Manager</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>