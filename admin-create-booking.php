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

function createBookingsTableIfMissing(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            dog_id INTEGER,
            service_type TEXT NOT NULL,
            service_date TEXT NOT NULL,
            service_time TEXT,
            duration_minutes INTEGER,
            price REAL,
            status TEXT NOT NULL DEFAULT 'Scheduled',
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$selectedUserId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$selectedDogId = (int)($_POST['dog_id'] ?? 0);

$serviceType = trim((string)($_POST['service_type'] ?? 'walk'));
$serviceDate = trim((string)($_POST['service_date'] ?? ''));
$serviceTime = trim((string)($_POST['service_time'] ?? ''));
$durationMinutes = trim((string)($_POST['duration_minutes'] ?? '30'));
$price = trim((string)($_POST['price'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'Scheduled'));
$notes = trim((string)($_POST['notes'] ?? ''));

$users = [];
$dogs = [];
$selectedUserName = '';
$selectedUserEmail = '';

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

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    createBookingsTableIfMissing($pdo);

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

    if ($selectedUserId > 0) {
        $selectedUserStmt = $pdo->prepare("
            SELECT id, full_name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $selectedUserStmt->execute([$selectedUserId]);
        $selectedUser = $selectedUserStmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedUser) {
            $selectedUserName = (string)($selectedUser['full_name'] ?? '');
            $selectedUserEmail = (string)($selectedUser['email'] ?? '');
        }
    }

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($selectedUserId <= 0) {
            $errorMessage = 'Please select a member.';
        } elseif ($serviceDate === '') {
            $errorMessage = 'Please choose a service date.';
        } elseif ($serviceType === '') {
            $errorMessage = 'Please choose a service type.';
        } else {
            $bookingColumns = getColumns($pdo, 'bookings');

            $userCol = hasColumn($bookingColumns, 'user_id')
                ? 'user_id'
                : (hasColumn($bookingColumns, 'member_id')
                    ? 'member_id'
                    : (hasColumn($bookingColumns, 'client_id') ? 'client_id' : null));

            if ($userCol === null) {
                throw new RuntimeException('The bookings table does not have a supported user column.');
            }

            $dogCol = hasColumn($bookingColumns, 'dog_id')
                ? 'dog_id'
                : (hasColumn($bookingColumns, 'pet_id') ? 'pet_id' : null);

            $serviceCol = pickExistingColumn($bookingColumns, ['service_type', 'service']);
            $dateCol = pickExistingColumn($bookingColumns, ['service_date', 'booking_date']);
            $timeCol = hasColumn($bookingColumns, 'service_time') ? 'service_time' : null;
            $durationCol = hasColumn($bookingColumns, 'duration_minutes') ? 'duration_minutes' : null;
            $priceCol = pickExistingColumn($bookingColumns, ['price', 'estimated_price', 'amount']);
            $statusCol = hasColumn($bookingColumns, 'status') ? 'status' : null;
            $notesCol = pickExistingColumn($bookingColumns, ['notes', 'client_notes']);

            if ($serviceCol === null || $dateCol === null) {
                throw new RuntimeException('The bookings table is missing required service/date columns.');
            }

            $insertColumns = [$userCol, $serviceCol, $dateCol];
            $insertValues = [$selectedUserId, $serviceType, $serviceDate];
            $placeholders = ['?', '?', '?'];

            if ($dogCol !== null) {
                $insertColumns[] = $dogCol;
                $insertValues[] = $selectedDogId > 0 ? $selectedDogId : null;
                $placeholders[] = '?';
            }

            if ($timeCol !== null) {
                $insertColumns[] = $timeCol;
                $insertValues[] = $serviceTime !== '' ? $serviceTime : null;
                $placeholders[] = '?';
            }

            if ($durationCol !== null) {
                $insertColumns[] = $durationCol;
                $insertValues[] = $durationMinutes !== '' ? (int)$durationMinutes : null;
                $placeholders[] = '?';
            }

            if ($priceCol !== null) {
                $insertColumns[] = $priceCol;
                $insertValues[] = $price !== '' ? (float)$price : null;
                $placeholders[] = '?';
            }

            if ($statusCol !== null) {
                $insertColumns[] = $statusCol;
                $insertValues[] = $status !== '' ? $status : 'Scheduled';
                $placeholders[] = '?';
            }

            if ($notesCol !== null) {
                $insertColumns[] = $notesCol;
                $insertValues[] = $notes;
                $placeholders[] = '?';
            }

            $sql = "
                INSERT INTO bookings (" . implode(', ', $insertColumns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertValues);

            $successMessage = 'Booking created successfully.';
            $selectedDogId = 0;
            $serviceType = 'walk';
            $serviceDate = '';
            $serviceTime = '';
            $durationMinutes = '30';
            $price = '';
            $status = 'Scheduled';
            $notes = '';
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
    <title>Create Booking | Doggie Dorian's Admin</title>
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

        .user-box{
            margin-bottom:18px;
            padding:16px;
            border-radius:16px;
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
        }

        .user-box strong{
            display:block;
            margin-bottom:6px;
            font-size:16px;
        }

        .user-box span{
            color:var(--muted);
            font-size:14px;
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
        <div class="tag">Premium admin control panel for members, dogs, bookings, and operations.</div>

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
                <h1>Create Booking</h1>
                <div class="sub">Create a member booking and attach it to a dog when available.</div>
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Create booking error:</strong><br>
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

                <?php if ($selectedUserId > 0 && $selectedUserName !== ''): ?>
                    <div class="user-box">
                        <strong><?php echo h($selectedUserName); ?></strong>
                        <span><?php echo h($selectedUserEmail); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="admin-create-booking.php<?php echo $selectedUserId > 0 ? '?user_id=' . (int)$selectedUserId : ''; ?>">
                    <?php if ($selectedUserId <= 0): ?>
                        <div class="field">
                            <label for="user_id">Select Member</label>
                            <select id="user_id" name="user_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>" <?php echo $selectedUserId === (int)$user['id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string)$user['full_name']); ?> — <?php echo h((string)$user['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo (int)$selectedUserId; ?>">
                    <?php endif; ?>

                    <div class="grid">
                        <div class="field">
                            <label for="dog_id">Dog</label>
                            <select id="dog_id" name="dog_id">
                                <option value="">No dog selected</option>
                                <?php foreach ($dogs as $dog): ?>
                                    <option value="<?php echo (int)$dog['id']; ?>" <?php echo $selectedDogId === (int)$dog['id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string)$dog['display_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="service_type">Service Type</label>
                            <select id="service_type" name="service_type" required>
                                <?php foreach ($serviceOptions as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>" <?php echo $serviceType === $value ? 'selected' : ''; ?>>
                                        <?php echo h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="service_date">Service Date</label>
                            <input type="date" id="service_date" name="service_date" value="<?php echo h($serviceDate); ?>" required>
                        </div>

                        <div class="field">
                            <label for="service_time">Service Time</label>
                            <input type="time" id="service_time" name="service_time" value="<?php echo h($serviceTime); ?>">
                        </div>

                        <div class="field">
                            <label for="duration_minutes">Duration (minutes)</label>
                            <input type="number" id="duration_minutes" name="duration_minutes" value="<?php echo h($durationMinutes); ?>" min="0" step="1">
                        </div>

                        <div class="field">
                            <label for="price">Price</label>
                            <input type="number" id="price" name="price" value="<?php echo h($price); ?>" min="0" step="0.01">
                        </div>

                        <div class="field full">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <?php foreach ($statusOptions as $statusOption): ?>
                                    <option value="<?php echo h($statusOption); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                                        <?php echo h($statusOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field full">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes"><?php echo h($notes); ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Create Booking</button>

                        <?php if ($selectedUserId > 0): ?>
                            <a class="btn btn-secondary" href="admin-member-view.php?id=<?php echo (int)$selectedUserId; ?>">Back to Member</a>
                        <?php else: ?>
                            <a class="btn btn-secondary" href="admin-members.php">Back to Members</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>