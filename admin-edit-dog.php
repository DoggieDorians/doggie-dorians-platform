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

function ddAdminEditDogH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminEditDogQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminEditDogRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminEditDogTableExists(PDO $pdo, string $table): bool
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

function ddAdminEditDogGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminEditDogTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminEditDogQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
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

function ddAdminEditDogPickExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }

    return null;
}

function ddAdminEditDogSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminEditDogSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminEditDogCsrfToken(): string
{
    if (empty($_SESSION['admin_edit_dog_csrf']) || !is_string($_SESSION['admin_edit_dog_csrf'])) {
        $_SESSION['admin_edit_dog_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_edit_dog_csrf'];
}

function ddAdminEditDogValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_edit_dog_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminEditDogDetectDogSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('dogs', 'pets') as $table) {
        if (!ddAdminEditDogTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminEditDogGetColumns($pdo, $table);
        $idColumn = ddAdminEditDogPickExistingColumn($columns, array('id', 'dog_id', 'pet_id'));
        $ownerColumn = ddAdminEditDogPickExistingColumn($columns, array('user_id', 'member_id', 'owner_id', 'client_id'));
        $nameColumn = ddAdminEditDogPickExistingColumn($columns, array('name', 'dog_name', 'pet_name'));

        if ($idColumn === null || $ownerColumn === null || $nameColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'owner_column' => $ownerColumn,
            'name_column' => $nameColumn,
            'breed_column' => ddAdminEditDogPickExistingColumn($columns, array('breed', 'dog_breed')),
            'age_column' => ddAdminEditDogPickExistingColumn($columns, array('age', 'dog_age')),
            'notes_column' => ddAdminEditDogPickExistingColumn($columns, array('notes', 'care_notes', 'dog_notes')),
            'created_column' => ddAdminEditDogPickExistingColumn($columns, array('created_at', 'created_on')),
            'updated_at_column' => ddAdminEditDogPickExistingColumn($columns, array('updated_at')),
        );
    }

    return $sources;
}

function ddAdminEditDogLoadDogFromTable(PDO $pdo, string $table, int $dogId, array $sources): ?array
{
    foreach ($sources as $source) {
        if ((string) $source['table'] !== $table) {
            continue;
        }

        $selectParts = array(
            ddAdminEditDogQuoteIdentifier((string) $source['id_column']) . ' AS id',
            ddAdminEditDogQuoteIdentifier((string) $source['owner_column']) . ' AS dog_owner_id',
            ddAdminEditDogQuoteIdentifier((string) $source['name_column']) . ' AS dog_name',
        );

        $selectParts[] = $source['breed_column'] !== null
            ? ddAdminEditDogQuoteIdentifier((string) $source['breed_column']) . ' AS dog_breed'
            : 'NULL AS dog_breed';

        $selectParts[] = $source['age_column'] !== null
            ? ddAdminEditDogQuoteIdentifier((string) $source['age_column']) . ' AS dog_age'
            : 'NULL AS dog_age';

        $selectParts[] = $source['notes_column'] !== null
            ? ddAdminEditDogQuoteIdentifier((string) $source['notes_column']) . ' AS dog_notes'
            : 'NULL AS dog_notes';

        $selectParts[] = $source['created_column'] !== null
            ? ddAdminEditDogQuoteIdentifier((string) $source['created_column']) . ' AS dog_created'
            : 'NULL AS dog_created';

        $loadSql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM ' . ddAdminEditDogQuoteIdentifier((string) $source['table'])
            . ' WHERE ' . ddAdminEditDogQuoteIdentifier((string) $source['id_column']) . ' = :id LIMIT 1';

        $dog = ddAdminEditDogSafeFetchOne($pdo, $loadSql, array(':id' => $dogId));

        if (!$dog) {
            return null;
        }

        $source['dog'] = $dog;
        return $source;
    }

    return null;
}

function ddAdminEditDogLoadDog(PDO $pdo, int $dogId, array $sources, string $preferredSource = ''): ?array
{
    $validSources = array('dogs', 'pets');

    if ($preferredSource !== '' && in_array($preferredSource, $validSources, true)) {
        $preferred = ddAdminEditDogLoadDogFromTable($pdo, $preferredSource, $dogId, $sources);
        if ($preferred !== null) {
            return $preferred;
        }
    }

    foreach ($validSources as $table) {
        if ($table === $preferredSource) {
            continue;
        }

        $loaded = ddAdminEditDogLoadDogFromTable($pdo, $table, $dogId, $sources);
        if ($loaded !== null) {
            return $loaded;
        }
    }

    return null;
}

function ddAdminEditDogDetectOwnerSource(PDO $pdo): ?array
{
    foreach (array('users', 'members', 'client_profiles') as $table) {
        if (!ddAdminEditDogTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminEditDogGetColumns($pdo, $table);
        $idColumn = ddAdminEditDogPickExistingColumn($columns, array('id', 'user_id', 'member_id', 'client_id'));

        if ($idColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'role_column' => ddAdminEditDogPickExistingColumn($columns, array('role', 'user_role', 'account_type', 'account_role')),
            'name_column' => ddAdminEditDogPickExistingColumn($columns, array('full_name', 'name', 'client_name', 'username')),
            'first_name_column' => ddAdminEditDogPickExistingColumn($columns, array('first_name')),
            'last_name_column' => ddAdminEditDogPickExistingColumn($columns, array('last_name')),
            'email_column' => ddAdminEditDogPickExistingColumn($columns, array('email')),
        );
    }

    return null;
}

function ddAdminEditDogBuildOwnerLabel(array $row, array $ownerSource): string
{
    $nameColumn = $ownerSource['name_column'] ?? null;
    if (is_string($nameColumn) && $nameColumn !== '' && !empty($row[$nameColumn])) {
        return trim((string) $row[$nameColumn]);
    }

    $firstNameColumn = $ownerSource['first_name_column'] ?? null;
    $lastNameColumn = $ownerSource['last_name_column'] ?? null;

    $first = is_string($firstNameColumn) ? trim((string) ($row[$firstNameColumn] ?? '')) : '';
    $last = is_string($lastNameColumn) ? trim((string) ($row[$lastNameColumn] ?? '')) : '';
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    $emailColumn = $ownerSource['email_column'] ?? null;
    if (is_string($emailColumn) && $emailColumn !== '' && !empty($row[$emailColumn])) {
        return trim((string) $row[$emailColumn]);
    }

    return 'Owner';
}

function ddAdminEditDogFetchOwners(PDO $pdo, array $ownerSource): array
{
    $table = $ownerSource['table'];
    $idColumn = $ownerSource['id_column'];
    $roleColumn = $ownerSource['role_column'];
    $nameColumn = $ownerSource['name_column'];
    $firstNameColumn = $ownerSource['first_name_column'];
    $emailColumn = $ownerSource['email_column'];

    $sql = 'SELECT * FROM ' . ddAdminEditDogQuoteIdentifier($table);
    $params = array();

    if ($roleColumn !== null) {
        $sql .= ' WHERE LOWER(COALESCE(' . ddAdminEditDogQuoteIdentifier((string) $roleColumn) . ", 'member')) != :admin_role";
        $params[':admin_role'] = 'admin';
    }

    if ($nameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditDogQuoteIdentifier((string) $nameColumn) . ' ASC';
    } elseif ($firstNameColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditDogQuoteIdentifier((string) $firstNameColumn) . ' ASC';
    } elseif ($emailColumn !== null) {
        $sql .= ' ORDER BY ' . ddAdminEditDogQuoteIdentifier((string) $emailColumn) . ' ASC';
    } else {
        $sql .= ' ORDER BY ' . ddAdminEditDogQuoteIdentifier((string) $idColumn) . ' ASC';
    }

    $rows = ddAdminEditDogSafeFetchAll($pdo, $sql, $params);
    $owners = array();

    foreach ($rows as $row) {
        $ownerId = (int) ($row[$idColumn] ?? 0);
        if ($ownerId <= 0) {
            continue;
        }

        $owners[] = array(
            'id' => $ownerId,
            'full_name' => ddAdminEditDogBuildOwnerLabel($row, $ownerSource),
            'email' => $emailColumn !== null ? trim((string) ($row[$emailColumn] ?? '')) : '',
        );
    }

    return $owners;
}

$successMessage = '';
$errorMessage = '';
$fatalError = '';

$dogId = (int) ($_GET['id'] ?? $_GET['dog_id'] ?? $_POST['dog_id'] ?? 0);
$dogSourceParam = strtolower(trim((string) ($_GET['source'] ?? $_POST['source'] ?? '')));

if (!in_array($dogSourceParam, array('dogs', 'pets'), true)) {
    $dogSourceParam = '';
}

if ($dogId <= 0) {
    ddAdminEditDogRedirect('admin-dogs.php');
}

$owners = array();
$dog = null;
$dogSource = null;
$resolvedDogSource = '';

try {
    $dogSources = ddAdminEditDogDetectDogSources($pdo);
    if ($dogSources === array()) {
        throw new RuntimeException('The dogs table was not found.');
    }

    $loadedDog = ddAdminEditDogLoadDog($pdo, $dogId, $dogSources, $dogSourceParam);
    if ($loadedDog === null) {
        throw new RuntimeException('Dog not found.');
    }

    $dogSource = $loadedDog;
    $dog = (array) $loadedDog['dog'];
    $resolvedDogSource = (string) $loadedDog['table'];

    $ownerSource = ddAdminEditDogDetectOwnerSource($pdo);
    if ($ownerSource === null) {
        throw new RuntimeException('No supported owner/member table was found.');
    }

    $owners = ddAdminEditDogFetchOwners($pdo, $ownerSource);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ddAdminEditDogValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
            $errorMessage = 'Security check failed. Please refresh the page and try again.';
        } else {
            $newOwnerId = (int) ($_POST['user_id'] ?? 0);
            $newDogName = trim((string) ($_POST['dog_name'] ?? ''));
            $newBreed = trim((string) ($_POST['breed'] ?? ''));
            $newAge = trim((string) ($_POST['age'] ?? ''));
            $newNotes = trim((string) ($_POST['notes'] ?? ''));

            if ($newOwnerId <= 0) {
                $errorMessage = 'Please select an owner/member.';
            } elseif ($newDogName === '') {
                $errorMessage = 'Please enter the dog name.';
            } else {
                $ownerExists = false;
                foreach ($owners as $owner) {
                    if ((int) $owner['id'] === $newOwnerId) {
                        $ownerExists = true;
                        break;
                    }
                }

                if (!$ownerExists) {
                    $errorMessage = 'Selected owner/member was not found.';
                } else {
                    $setParts = array(
                        ddAdminEditDogQuoteIdentifier((string) $dogSource['owner_column']) . ' = :owner_id',
                        ddAdminEditDogQuoteIdentifier((string) $dogSource['name_column']) . ' = :dog_name',
                    );

                    $params = array(
                        ':owner_id' => $newOwnerId,
                        ':dog_name' => $newDogName,
                        ':id' => $dogId,
                    );

                    if ($dogSource['breed_column'] !== null) {
                        $setParts[] = ddAdminEditDogQuoteIdentifier((string) $dogSource['breed_column']) . ' = :breed';
                        $params[':breed'] = $newBreed !== '' ? $newBreed : null;
                    }

                    if ($dogSource['age_column'] !== null) {
                        $setParts[] = ddAdminEditDogQuoteIdentifier((string) $dogSource['age_column']) . ' = :age';
                        $params[':age'] = $newAge !== '' ? $newAge : null;
                    }

                    if ($dogSource['notes_column'] !== null) {
                        $setParts[] = ddAdminEditDogQuoteIdentifier((string) $dogSource['notes_column']) . ' = :notes';
                        $params[':notes'] = $newNotes !== '' ? $newNotes : null;
                    }

                    if ($dogSource['updated_at_column'] !== null) {
                        $setParts[] = ddAdminEditDogQuoteIdentifier((string) $dogSource['updated_at_column']) . ' = CURRENT_TIMESTAMP';
                    }

                    $updateSql = 'UPDATE ' . ddAdminEditDogQuoteIdentifier((string) $dogSource['table'])
                        . ' SET ' . implode(', ', $setParts)
                        . ' WHERE ' . ddAdminEditDogQuoteIdentifier((string) $dogSource['id_column']) . ' = :id';

                    $updateStmt = $pdo->prepare($updateSql);

                    foreach ($params as $placeholder => $value) {
                        if (is_int($value)) {
                            $updateStmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                        } elseif ($value === null) {
                            $updateStmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                        } else {
                            $updateStmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                        }
                    }

                    $updateStmt->execute();

                    ddAdminEditDogRedirect('admin-edit-dog.php?id=' . $dogId . '&source=' . urlencode($resolvedDogSource) . '&updated=1');
                }
            }
        }
    }

    if (isset($_GET['updated']) && $_GET['updated'] === '1') {
        $successMessage = 'Dog updated successfully.';

        $reloadedDog = ddAdminEditDogLoadDog($pdo, $dogId, $dogSources, $resolvedDogSource);
        if ($reloadedDog !== null) {
            $dogSource = $reloadedDog;
            $dog = (array) $reloadedDog['dog'];
            $resolvedDogSource = (string) $reloadedDog['table'];
        }
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

$csrfToken = ddAdminEditDogCsrfToken();
$sourceParam = urlencode($resolvedDogSource !== '' ? $resolvedDogSource : $dogSourceParam);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Edit Dog | Doggie Dorian’s</title>
    <meta name="description" content="Edit dog details in the Doggie Dorian’s admin area.">
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
            line-height:1.6;
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
            <a href="admin-nav.php">Admin Nav</a>
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php">Members</a>
            <a href="admin-dogs.php" class="active">Dog Management</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <section class="header">
            <div>
                <h1>Edit Dog</h1>
                <div class="sub">
                    Update dog details and owner information.
                    <?php if ($resolvedDogSource !== ''): ?>
                        <br>Source: <strong><?php echo ddAdminEditDogH($resolvedDogSource); ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Edit dog error:</strong><br>
                <?php echo ddAdminEditDogH($fatalError); ?>
            </div>
        <?php else: ?>
            <div class="card">
                <?php if ($successMessage !== ''): ?>
                    <div class="message success"><?php echo ddAdminEditDogH($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="message error"><?php echo ddAdminEditDogH($errorMessage); ?></div>
                <?php endif; ?>

                <form method="post" action="admin-edit-dog.php?id=<?php echo (int) $dogId; ?>&source=<?php echo $sourceParam; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminEditDogH($csrfToken); ?>">
                    <input type="hidden" name="dog_id" value="<?php echo (int) $dogId; ?>">
                    <input type="hidden" name="source" value="<?php echo ddAdminEditDogH($resolvedDogSource); ?>">

                    <div class="grid">
                        <div class="field">
                            <label for="user_id">Owner / Member</label>
                            <select id="user_id" name="user_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($owners as $owner): ?>
                                    <option value="<?php echo (int) $owner['id']; ?>" <?php echo (int) ($dog['dog_owner_id'] ?? 0) === (int) $owner['id'] ? 'selected' : ''; ?>>
                                        <?php echo ddAdminEditDogH((string) $owner['full_name']); ?><?php echo $owner['email'] !== '' ? ' — ' . ddAdminEditDogH((string) $owner['email']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="dog_name">Dog Name</label>
                            <input type="text" id="dog_name" name="dog_name" value="<?php echo ddAdminEditDogH((string) ($dog['dog_name'] ?? '')); ?>" required>
                        </div>

                        <div class="field">
                            <label for="breed">Breed</label>
                            <input type="text" id="breed" name="breed" value="<?php echo ddAdminEditDogH((string) ($dog['dog_breed'] ?? '')); ?>">
                        </div>

                        <div class="field">
                            <label for="age">Age</label>
                            <input type="text" id="age" name="age" value="<?php echo ddAdminEditDogH((string) ($dog['dog_age'] ?? '')); ?>">
                        </div>

                        <div class="field full">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes"><?php echo ddAdminEditDogH((string) ($dog['dog_notes'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Dog Changes</button>
                        <?php if ((int) ($dog['dog_owner_id'] ?? 0) > 0): ?>
                            <a class="btn btn-secondary" href="admin-member-view.php?id=<?php echo (int) ($dog['dog_owner_id'] ?? 0); ?>">View Owner</a>
                        <?php endif; ?>
                        <a class="btn btn-secondary" href="admin-dogs.php">Back to Dog Manager</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>