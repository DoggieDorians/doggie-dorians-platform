<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminAddDogH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminAddDogQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminAddDogTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminAddDogGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminAddDogTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminAddDogQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminAddDogHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminAddDogFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminAddDogSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return array();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    }
}

function ddAdminAddDogSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ddAdminAddDogCsrfToken(): string
{
    if (empty($_SESSION['admin_add_dog_csrf']) || !is_string($_SESSION['admin_add_dog_csrf'])) {
        $_SESSION['admin_add_dog_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_add_dog_csrf'];
}

function ddAdminAddDogValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_add_dog_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminAddDogResolveDisplayName(array $row, ?string $nameColumn, ?string $firstNameColumn, ?string $lastNameColumn, string $fallbackPrefix = 'Member'): string
{
    if ($nameColumn !== null && !empty($row[$nameColumn])) {
        return trim((string) $row[$nameColumn]);
    }

    $first = $firstNameColumn !== null ? trim((string) ($row[$firstNameColumn] ?? '')) : '';
    $last = $lastNameColumn !== null ? trim((string) ($row[$lastNameColumn] ?? '')) : '';
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    return $fallbackPrefix . ' #' . (int) ($row['__resolved_id'] ?? 0);
}

function ddAdminAddDogDetectMemberSource(PDO $pdo): ?array
{
    $candidates = array(
        array('table' => 'users'),
        array('table' => 'members'),
        array('table' => 'client_profiles'),
    );

    foreach ($candidates as $candidate) {
        $table = $candidate['table'];
        if (!ddAdminAddDogTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminAddDogGetColumns($pdo, $table);
        if (empty($columns)) {
            continue;
        }

        $idColumn = ddAdminAddDogFirstExistingColumn($columns, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idColumn === null) {
            continue;
        }

        $nameColumn = ddAdminAddDogFirstExistingColumn($columns, array('full_name', 'name', 'client_name'));
        $firstNameColumn = ddAdminAddDogFirstExistingColumn($columns, array('first_name'));
        $lastNameColumn = ddAdminAddDogFirstExistingColumn($columns, array('last_name'));
        $emailColumn = ddAdminAddDogFirstExistingColumn($columns, array('email'));
        $roleColumn = ddAdminAddDogFirstExistingColumn($columns, array('role', 'user_role', 'account_type'));

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'name_column' => $nameColumn,
            'first_name_column' => $firstNameColumn,
            'last_name_column' => $lastNameColumn,
            'email_column' => $emailColumn,
            'role_column' => $roleColumn,
        );
    }

    return null;
}

function ddAdminAddDogFetchMembers(PDO $pdo, array $source): array
{
    $table = $source['table'];
    $idColumn = $source['id_column'];
    $nameColumn = $source['name_column'];
    $firstNameColumn = $source['first_name_column'];
    $emailColumn = $source['email_column'];
    $roleColumn = $source['role_column'];

    $sql = 'SELECT * FROM ' . ddAdminAddDogQuoteIdentifier($table);
    $params = array();

    if ($roleColumn !== null) {
        $sql .= ' WHERE LOWER(COALESCE(' . ddAdminAddDogQuoteIdentifier($roleColumn) . ", 'member')) != :admin_role";
        $params[':admin_role'] = 'admin';
    }

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAddDogQuoteIdentifier($nameColumn) . ' ASC';
    } elseif ($firstNameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAddDogQuoteIdentifier($firstNameColumn) . ' ASC';
    } elseif ($emailColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminAddDogQuoteIdentifier($emailColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminAddDogQuoteIdentifier($idColumn) . ' ASC';
    }

    $rows = ddAdminAddDogSafeFetchAll($pdo, $sql, $params);
    $members = array();

    foreach ($rows as $row) {
        $row['__resolved_id'] = (int) ($row[$idColumn] ?? 0);

        $members[] = array(
            'id' => (int) ($row[$idColumn] ?? 0),
            'full_name' => ddAdminAddDogResolveDisplayName(
                $row,
                $source['name_column'],
                $source['first_name_column'],
                $source['last_name_column']
            ),
            'email' => $source['email_column'] !== null ? trim((string) ($row[$source['email_column']] ?? '')) : '',
        );
    }

    return $members;
}

function ddAdminAddDogFetchSelectedMember(PDO $pdo, array $source, int $selectedUserId): ?array
{
    $table = $source['table'];
    $idColumn = $source['id_column'];

    $row = ddAdminAddDogSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminAddDogQuoteIdentifier($table)
        . ' WHERE ' . ddAdminAddDogQuoteIdentifier($idColumn) . ' = :id LIMIT 1',
        array(':id' => $selectedUserId)
    );

    if ($row === null) {
        return null;
    }

    $row['__resolved_id'] = (int) ($row[$idColumn] ?? 0);

    return array(
        'id' => (int) ($row[$idColumn] ?? 0),
        'full_name' => ddAdminAddDogResolveDisplayName(
            $row,
            $source['name_column'],
            $source['first_name_column'],
            $source['last_name_column']
        ),
        'email' => $source['email_column'] !== null ? trim((string) ($row[$source['email_column']] ?? '')) : '',
    );
}

function ddAdminAddDogCreateDogsTableIfMissing(PDO $pdo): void
{
    if (ddAdminAddDogTableExists($pdo, 'dogs') || ddAdminAddDogTableExists($pdo, 'pets')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS dogs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            breed TEXT,
            age TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function ddAdminAddDogDetectDogTarget(PDO $pdo): ?array
{
    ddAdminAddDogCreateDogsTableIfMissing($pdo);

    $table = null;
    if (ddAdminAddDogTableExists($pdo, 'dogs')) {
        $table = 'dogs';
    } elseif (ddAdminAddDogTableExists($pdo, 'pets')) {
        $table = 'pets';
    }

    if ($table === null) {
        return null;
    }

    $columns = ddAdminAddDogGetColumns($pdo, $table);
    if (empty($columns)) {
        return null;
    }

    return array(
        'table' => $table,
        'columns' => $columns,
        'owner_column' => ddAdminAddDogFirstExistingColumn($columns, array('user_id', 'member_id', 'owner_id', 'client_id')),
        'name_column' => ddAdminAddDogFirstExistingColumn($columns, array('name', 'pet_name', 'dog_name')),
        'breed_column' => ddAdminAddDogFirstExistingColumn($columns, array('breed', 'dog_breed')),
        'age_column' => ddAdminAddDogFirstExistingColumn($columns, array('age', 'dog_age')),
        'notes_column' => ddAdminAddDogFirstExistingColumn($columns, array('notes', 'dog_notes', 'care_notes')),
        'created_at_column' => ddAdminAddDogFirstExistingColumn($columns, array('created_at')),
    );
}

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$selectedUserId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$fullName = '';
$email = '';

$dogName = trim((string) ($_POST['dog_name'] ?? ''));
$breed = trim((string) ($_POST['breed'] ?? ''));
$age = trim((string) ($_POST['age'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

$users = array();

try {
    $memberSource = ddAdminAddDogDetectMemberSource($pdo);
    if ($memberSource === null) {
        throw new RuntimeException('No supported member source table was found.');
    }

    $users = ddAdminAddDogFetchMembers($pdo, $memberSource);

    if ($selectedUserId > 0) {
        $selectedUser = ddAdminAddDogFetchSelectedMember($pdo, $memberSource, $selectedUserId);

        if ($selectedUser !== null) {
            $fullName = (string) $selectedUser['full_name'];
            $email = (string) $selectedUser['email'];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ddAdminAddDogValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
            $errorMessage = 'Security check failed. Please refresh the page and try again.';
        } elseif ($selectedUserId <= 0) {
            $errorMessage = 'Please select a member first.';
        } elseif ($dogName === '') {
            $errorMessage = 'Please enter the dog’s name.';
        } else {
            $selectedUser = ddAdminAddDogFetchSelectedMember($pdo, $memberSource, $selectedUserId);
            if ($selectedUser === null) {
                $errorMessage = 'The selected member was not found.';
            } else {
                $dogTarget = ddAdminAddDogDetectDogTarget($pdo);
                if ($dogTarget === null) {
                    throw new RuntimeException('No supported dogs table could be prepared.');
                }

                if ($dogTarget['owner_column'] === null) {
                    throw new RuntimeException('The dogs table does not have a supported owner column.');
                }

                if ($dogTarget['name_column'] === null) {
                    throw new RuntimeException('The dogs table does not have a supported dog name column.');
                }

                $insertColumns = array($dogTarget['owner_column'], $dogTarget['name_column']);
                $placeholders = array(':owner_id', ':dog_name');
                $params = array(
                    ':owner_id' => $selectedUserId,
                    ':dog_name' => $dogName,
                );

                if ($dogTarget['breed_column'] !== null) {
                    $insertColumns[] = $dogTarget['breed_column'];
                    $placeholders[] = ':breed';
                    $params[':breed'] = $breed !== '' ? $breed : null;
                }

                if ($dogTarget['age_column'] !== null) {
                    $insertColumns[] = $dogTarget['age_column'];
                    $placeholders[] = ':age';
                    $params[':age'] = $age !== '' ? $age : null;
                }

                if ($dogTarget['notes_column'] !== null) {
                    $insertColumns[] = $dogTarget['notes_column'];
                    $placeholders[] = ':notes';
                    $params[':notes'] = $notes !== '' ? $notes : null;
                }

                if ($dogTarget['created_at_column'] !== null) {
                    $insertColumns[] = $dogTarget['created_at_column'];
                    $placeholders[] = 'CURRENT_TIMESTAMP';
                }

                $quotedColumns = array();
                foreach ($insertColumns as $column) {
                    $quotedColumns[] = ddAdminAddDogQuoteIdentifier($column);
                }

                $sql = 'INSERT INTO ' . ddAdminAddDogQuoteIdentifier($dogTarget['table'])
                    . ' (' . implode(', ', $quotedColumns) . ')'
                    . ' VALUES (' . implode(', ', $placeholders) . ')';

                $stmt = $pdo->prepare($sql);

                foreach ($params as $placeholder => $value) {
                    if ($value === null) {
                        $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                    } elseif (is_int($value)) {
                        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                    }
                }

                $stmt->execute();

                $successMessage = 'Dog added successfully.';
                $fullName = (string) $selectedUser['full_name'];
                $email = (string) $selectedUser['email'];

                $dogName = '';
                $breed = '';
                $age = '';
                $notes = '';
            }
        }
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

$csrfToken = ddAdminAddDogCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Add Dog | Doggie Dorian’s</title>
    <meta name="description" content="Add a dog profile for a member in the Doggie Dorian’s admin area.">
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

        a{
            color:inherit;
            text-decoration:none;
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
            <a href="admin-nav.php">Admin Nav</a>
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php" class="active">Members</a>
            <a href="admin-dogs.php">Dogs</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="logout.php">Logout</a>
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
                <?php echo ddAdminAddDogH($fatalError); ?>
            </div>
        <?php else: ?>
            <div class="card">
                <?php if ($successMessage !== ''): ?>
                    <div class="message success"><?php echo ddAdminAddDogH($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="message error"><?php echo ddAdminAddDogH($errorMessage); ?></div>
                <?php endif; ?>

                <?php if ($selectedUserId > 0 && $fullName !== ''): ?>
                    <div class="user-box">
                        <strong><?php echo ddAdminAddDogH($fullName); ?></strong>
                        <span><?php echo ddAdminAddDogH($email); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="admin-add-dog.php<?php echo $selectedUserId > 0 ? '?user_id=' . (int) $selectedUserId : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminAddDogH($csrfToken); ?>">

                    <?php if ($selectedUserId <= 0): ?>
                        <div class="field">
                            <label for="user_id">Select Member</label>
                            <select id="user_id" name="user_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int) $user['id']; ?>" <?php echo $selectedUserId === (int) $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo ddAdminAddDogH((string) $user['full_name']); ?><?php echo $user['email'] !== '' ? ' — ' . ddAdminAddDogH((string) $user['email']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $selectedUserId; ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label for="dog_name">Dog Name</label>
                        <input type="text" id="dog_name" name="dog_name" value="<?php echo ddAdminAddDogH($dogName); ?>" required>
                    </div>

                    <div class="field">
                        <label for="breed">Breed</label>
                        <input type="text" id="breed" name="breed" value="<?php echo ddAdminAddDogH($breed); ?>">
                    </div>

                    <div class="field">
                        <label for="age">Age</label>
                        <input type="text" id="age" name="age" value="<?php echo ddAdminAddDogH($age); ?>">
                    </div>

                    <div class="field">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes"><?php echo ddAdminAddDogH($notes); ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Add Dog</button>

                        <?php if ($selectedUserId > 0): ?>
                            <a class="btn btn-secondary" href="admin-member-view.php?id=<?php echo (int) $selectedUserId; ?>">Back to Member</a>
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