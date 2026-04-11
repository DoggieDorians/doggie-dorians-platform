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

$dogId = (int)($_GET['id'] ?? $_POST['dog_id'] ?? 0);

if ($dogId <= 0) {
    die('Invalid dog ID.');
}

$users = [];
$dog = null;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from data/config/db.php.');
    }

    if (!tableExists($pdo, 'dogs')) {
        throw new RuntimeException('The dogs table was not found.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    $dogColumns = getColumns($pdo, 'dogs');
    $userColumns = getColumns($pdo, 'users');

    $ownerCol = pickExistingColumn($dogColumns, ['user_id', 'member_id', 'owner_id', 'client_id']);
    $nameCol = pickExistingColumn($dogColumns, ['name', 'dog_name', 'pet_name']);
    $breedCol = pickExistingColumn($dogColumns, ['breed']);
    $ageCol = pickExistingColumn($dogColumns, ['age', 'dog_age']);
    $notesCol = pickExistingColumn($dogColumns, ['notes', 'care_notes']);
    $createdCol = pickExistingColumn($dogColumns, ['created_at', 'created_on']);

    if ($ownerCol === null || $nameCol === null) {
        throw new RuntimeException('The dogs table is missing required owner or name columns.');
    }

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
    | LOAD DOG
    |--------------------------------------------------------------------------
    */
    $selectParts = [
        "id",
        "$ownerCol AS dog_owner_id",
        "$nameCol AS dog_name",
    ];

    $selectParts[] = $breedCol !== null ? "$breedCol AS dog_breed" : "NULL AS dog_breed";
    $selectParts[] = $ageCol !== null ? "$ageCol AS dog_age" : "NULL AS dog_age";
    $selectParts[] = $notesCol !== null ? "$notesCol AS dog_notes" : "NULL AS dog_notes";
    $selectParts[] = $createdCol !== null ? "$createdCol AS dog_created" : "NULL AS dog_created";

    $loadSql = "
        SELECT " . implode(", ", $selectParts) . "
        FROM dogs
        WHERE id = ?
        LIMIT 1
    ";

    $loadStmt = $pdo->prepare($loadSql);
    $loadStmt->execute([$dogId]);
    $dog = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dog) {
        throw new RuntimeException('Dog not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE UPDATE
    |--------------------------------------------------------------------------
    */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newOwnerId = (int)($_POST['user_id'] ?? 0);
        $newDogName = trim((string)($_POST['dog_name'] ?? ''));
        $newBreed = trim((string)($_POST['breed'] ?? ''));
        $newAge = trim((string)($_POST['age'] ?? ''));
        $newNotes = trim((string)($_POST['notes'] ?? ''));

        if ($newOwnerId <= 0) {
            $errorMessage = 'Please select an owner/member.';
        } elseif ($newDogName === '') {
            $errorMessage = 'Please enter the dog name.';
        } else {
            $setParts = [
                "$ownerCol = ?",
                "$nameCol = ?",
            ];

            $values = [
                $newOwnerId,
                $newDogName,
            ];

            if ($breedCol !== null) {
                $setParts[] = "$breedCol = ?";
                $values[] = $newBreed;
            }

            if ($ageCol !== null) {
                $setParts[] = "$ageCol = ?";
                $values[] = $newAge;
            }

            if ($notesCol !== null) {
                $setParts[] = "$notesCol = ?";
                $values[] = $newNotes;
            }

            $values[] = $dogId;

            $updateSql = "
                UPDATE dogs
                SET " . implode(", ", $setParts) . "
                WHERE id = ?
            ";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($values);

            header('Location: admin-edit-dog.php?id=' . $dogId . '&updated=1');
            exit;
        }
    }

    if (isset($_GET['updated']) && $_GET['updated'] === '1') {
        $successMessage = 'Dog updated successfully.';

        $reloadStmt = $pdo->prepare($loadSql);
        $reloadStmt->execute([$dogId]);
        $dog = $reloadStmt->fetch(PDO::FETCH_ASSOC);
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
    <title>Edit Dog | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
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

        .brand span{
            color:var(--gold);
        }

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
            .shell{
                grid-template-columns:1fr;
            }

            .main{
                padding:20px;
            }

            .grid{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 640px){
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
        <div class="tag">Premium admin control panel for members, dogs, bookings, and operations.</div>

        <nav class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php">Members</a>
            <a href="admin-dogs.php" class="active">Dog Management</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <section class="header">
            <div>
                <h1>Edit Dog</h1>
                <div class="sub">Update dog details and owner information.</div>
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Edit dog error:</strong><br>
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

                <form method="post" action="admin-edit-dog.php?id=<?php echo (int)$dogId; ?>">
                    <input type="hidden" name="dog_id" value="<?php echo (int)$dogId; ?>">

                    <div class="grid">
                        <div class="field">
                            <label for="user_id">Owner / Member</label>
                            <select id="user_id" name="user_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>" <?php echo (int)($dog['dog_owner_id'] ?? 0) === (int)$user['id'] ? 'selected' : ''; ?>>
                                        <?php echo h((string)$user['full_name']); ?> — <?php echo h((string)$user['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="dog_name">Dog Name</label>
                            <input type="text" id="dog_name" name="dog_name" value="<?php echo h((string)($dog['dog_name'] ?? '')); ?>" required>
                        </div>

                        <div class="field">
                            <label for="breed">Breed</label>
                            <input type="text" id="breed" name="breed" value="<?php echo h((string)($dog['dog_breed'] ?? '')); ?>">
                        </div>

                        <div class="field">
                            <label for="age">Age</label>
                            <input type="text" id="age" name="age" value="<?php echo h((string)($dog['dog_age'] ?? '')); ?>">
                        </div>

                        <div class="field full">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes"><?php echo h((string)($dog['dog_notes'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Dog Changes</button>
                        <a class="btn btn-secondary" href="admin-dogs.php">Back to Dog Manager</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>