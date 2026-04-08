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

function createDogsTableIfMissing(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dogs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            breed TEXT,
            age TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$selectedUserId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$fullName = '';
$email = '';

$dogName = trim((string)($_POST['dog_name'] ?? ''));
$breed = trim((string)($_POST['breed'] ?? ''));
$age = trim((string)($_POST['age'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

$users = [];

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from data/config/db.php.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    createDogsTableIfMissing($pdo);

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
        $userStmt = $pdo->prepare("
            SELECT id, full_name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $userStmt->execute([$selectedUserId]);
        $selectedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedUser) {
            $fullName = (string)($selectedUser['full_name'] ?? '');
            $email = (string)($selectedUser['email'] ?? '');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($selectedUserId <= 0) {
            $errorMessage = 'Please select a member first.';
        } elseif ($dogName === '') {
            $errorMessage = 'Please enter the dog’s name.';
        } else {
            $dogColumns = getColumns($pdo, 'dogs');

            $ownerCol = hasColumn($dogColumns, 'user_id')
                ? 'user_id'
                : (hasColumn($dogColumns, 'member_id')
                    ? 'member_id'
                    : (hasColumn($dogColumns, 'owner_id')
                        ? 'owner_id'
                        : (hasColumn($dogColumns, 'client_id') ? 'client_id' : null)));

            if ($ownerCol === null) {
                throw new RuntimeException('The dogs table does not have a supported owner column.');
            }

            $nameCol = hasColumn($dogColumns, 'name')
                ? 'name'
                : (hasColumn($dogColumns, 'pet_name')
                    ? 'pet_name'
                    : (hasColumn($dogColumns, 'dog_name') ? 'dog_name' : null));

            if ($nameCol === null) {
                throw new RuntimeException('The dogs table does not have a supported dog name column.');
            }

            $insertColumns = [$ownerCol, $nameCol];
            $insertValues = [$selectedUserId, $dogName];
            $placeholders = ['?', '?'];

            if (hasColumn($dogColumns, 'breed')) {
                $insertColumns[] = 'breed';
                $insertValues[] = $breed;
                $placeholders[] = '?';
            }

            if (hasColumn($dogColumns, 'age')) {
                $insertColumns[] = 'age';
                $insertValues[] = $age;
                $placeholders[] = '?';
            } elseif (hasColumn($dogColumns, 'dog_age')) {
                $insertColumns[] = 'dog_age';
                $insertValues[] = $age;
                $placeholders[] = '?';
            }

            if (hasColumn($dogColumns, 'notes')) {
                $insertColumns[] = 'notes';
                $insertValues[] = $notes;
                $placeholders[] = '?';
            }

            $sql = "
                INSERT INTO dogs (" . implode(', ', $insertColumns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertValues);

            $successMessage = 'Dog added successfully.';
            $dogName = '';
            $breed = '';
            $age = '';
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
    <title>Add Dog | Doggie Dorian's Admin</title>
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
            max-width:760px;
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

        .field{
            margin-bottom:16px;
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

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:16px;
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
            max-width:760px;
        }

        @media (max-width: 900px){
            .shell{ grid-template-columns:1fr; }
            .main{ padding:20px; }
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
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php" class="active">Members</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <section class="header">
            <div>
                <h1>Add Dog</h1>
                <div class="sub">Create a dog profile for a member and attach it to their account.</div>
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Add dog error:</strong><br>
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

                <?php if ($selectedUserId > 0 && $fullName !== ''): ?>
                    <div class="user-box">
                        <strong><?php echo h($fullName); ?></strong>
                        <span><?php echo h($email); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="admin-add-dog.php<?php echo $selectedUserId > 0 ? '?user_id=' . (int)$selectedUserId : ''; ?>">
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

                    <div class="field">
                        <label for="dog_name">Dog Name</label>
                        <input type="text" id="dog_name" name="dog_name" value="<?php echo h($dogName); ?>" required>
                    </div>

                    <div class="field">
                        <label for="breed">Breed</label>
                        <input type="text" id="breed" name="breed" value="<?php echo h($breed); ?>">
                    </div>

                    <div class="field">
                        <label for="age">Age</label>
                        <input type="text" id="age" name="age" value="<?php echo h($age); ?>">
                    </div>

                    <div class="field">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes"><?php echo h($notes); ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Add Dog</button>

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