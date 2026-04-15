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

function ddAdminDogsH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminDogsQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminDogsTableExists(PDO $pdo, string $table): bool
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

function ddAdminDogsGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminDogsTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminDogsQuoteIdentifier($table) . ')');
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

function ddAdminDogsFirstExistingColumn(array $columns, array $options): ?string
{
    foreach ($options as $option) {
        if (in_array($option, $columns, true)) {
            return $option;
        }
    }

    return null;
}

function ddAdminDogsSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
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

function ddAdminDogsSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminDogsDetectDogSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('dogs', 'pets') as $table) {
        if (!ddAdminDogsTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminDogsGetColumns($pdo, $table);
        $idColumn = ddAdminDogsFirstExistingColumn($columns, array('id', 'dog_id', 'pet_id'));
        $ownerColumn = ddAdminDogsFirstExistingColumn($columns, array('user_id', 'member_id', 'owner_id', 'client_id'));

        if ($idColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'owner_column' => $ownerColumn,
            'name_column' => ddAdminDogsFirstExistingColumn($columns, array('name', 'dog_name', 'pet_name')),
            'breed_column' => ddAdminDogsFirstExistingColumn($columns, array('breed', 'dog_breed')),
            'age_column' => ddAdminDogsFirstExistingColumn($columns, array('age', 'dog_age')),
            'notes_column' => ddAdminDogsFirstExistingColumn($columns, array('notes', 'dog_notes', 'care_notes')),
            'created_at_column' => ddAdminDogsFirstExistingColumn($columns, array('created_at')),
        );
    }

    return $sources;
}

function ddAdminDogsDetectOwnerSource(PDO $pdo): ?array
{
    foreach (array('users', 'members', 'client_profiles') as $table) {
        if (!ddAdminDogsTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminDogsGetColumns($pdo, $table);
        $idColumn = ddAdminDogsFirstExistingColumn($columns, array('id', 'user_id', 'member_id', 'client_id'));

        if ($idColumn === null) {
            continue;
        }

        return array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'name_column' => ddAdminDogsFirstExistingColumn($columns, array('full_name', 'name', 'client_name', 'username')),
            'first_name_column' => ddAdminDogsFirstExistingColumn($columns, array('first_name')),
            'last_name_column' => ddAdminDogsFirstExistingColumn($columns, array('last_name')),
            'email_column' => ddAdminDogsFirstExistingColumn($columns, array('email')),
        );
    }

    return null;
}

function ddAdminDogsBuildOwnerLabel(array $row, array $ownerSource): string
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

function ddAdminDogsFindOwner(PDO $pdo, ?array $ownerSource, int $ownerId): array
{
    if ($ownerSource === null || $ownerId <= 0) {
        return array(
            'owner_id' => $ownerId,
            'owner_name' => 'Owner',
            'owner_email' => '',
        );
    }

    $row = ddAdminDogsSafeFetchOne(
        $pdo,
        'SELECT * FROM ' . ddAdminDogsQuoteIdentifier((string) $ownerSource['table'])
        . ' WHERE ' . ddAdminDogsQuoteIdentifier((string) $ownerSource['id_column']) . ' = :id LIMIT 1',
        array(':id' => $ownerId)
    );

    if ($row === null) {
        return array(
            'owner_id' => $ownerId,
            'owner_name' => 'Owner',
            'owner_email' => '',
        );
    }

    $emailColumn = $ownerSource['email_column'] ?? null;

    return array(
        'owner_id' => $ownerId,
        'owner_name' => ddAdminDogsBuildOwnerLabel($row, $ownerSource),
        'owner_email' => is_string($emailColumn) && $emailColumn !== '' ? trim((string) ($row[$emailColumn] ?? '')) : '',
    );
}

function ddAdminDogsNormalizeSortTimestamp(array $dogRecord): int
{
    $raw = trim((string) ($dogRecord['created_at_raw'] ?? ''));
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false) {
            return $ts;
        }
    }

    return (int) ($dogRecord['id'] ?? 0);
}

$search = strtolower(trim((string) ($_GET['search'] ?? '')));
$dogs = array();
$error = '';

try {
    $dogSources = ddAdminDogsDetectDogSources($pdo);
    if ($dogSources === array()) {
        throw new RuntimeException('Dogs table not found.');
    }

    $ownerSource = ddAdminDogsDetectOwnerSource($pdo);

    foreach ($dogSources as $dogSource) {
        $orderColumn = $dogSource['created_at_column'] ?? $dogSource['id_column'];

        $rows = ddAdminDogsSafeFetchAll(
            $pdo,
            'SELECT * FROM ' . ddAdminDogsQuoteIdentifier((string) $dogSource['table'])
            . ' ORDER BY ' . ddAdminDogsQuoteIdentifier((string) $orderColumn) . ' DESC, '
            . ddAdminDogsQuoteIdentifier((string) $dogSource['id_column']) . ' DESC'
        );

        foreach ($rows as $row) {
            $dogId = (int) ($row[$dogSource['id_column']] ?? 0);
            if ($dogId <= 0) {
                continue;
            }

            $dogName = $dogSource['name_column'] !== null ? trim((string) ($row[$dogSource['name_column']] ?? '')) : 'Dog';
            if ($dogName === '') {
                $dogName = 'Dog';
            }

            $breed = $dogSource['breed_column'] !== null ? trim((string) ($row[$dogSource['breed_column']] ?? '')) : '';
            $age = $dogSource['age_column'] !== null ? trim((string) ($row[$dogSource['age_column']] ?? '')) : '';
            $notes = $dogSource['notes_column'] !== null ? trim((string) ($row[$dogSource['notes_column']] ?? '')) : '';
            $ownerId = $dogSource['owner_column'] !== null ? (int) ($row[$dogSource['owner_column']] ?? 0) : 0;
            $owner = ddAdminDogsFindOwner($pdo, $ownerSource, $ownerId);

            $dogRecord = array(
                'id' => $dogId,
                'dog_name' => $dogName,
                'breed' => $breed,
                'age' => $age,
                'notes' => $notes,
                'user_id' => (int) $owner['owner_id'],
                'owner_name' => (string) $owner['owner_name'],
                'owner_email' => (string) $owner['owner_email'],
                'source_table' => (string) $dogSource['table'],
                'created_at_raw' => $dogSource['created_at_column'] !== null ? (string) ($row[$dogSource['created_at_column']] ?? '') : '',
            );

            if ($search !== '') {
                $haystack = strtolower(
                    $dogRecord['dog_name'] . ' ' .
                    $dogRecord['owner_name'] . ' ' .
                    $dogRecord['owner_email'] . ' ' .
                    $dogRecord['breed']
                );

                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $dogs[] = $dogRecord;
        }
    }

    usort($dogs, static function (array $a, array $b): int {
        return ddAdminDogsNormalizeSortTimestamp($b) <=> ddAdminDogsNormalizeSortTimestamp($a);
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dogs | Doggie Dorian’s</title>
<meta name="description" content="Manage dog profiles in the Doggie Dorian’s admin area.">
<style>
:root{
    --bg:#0b0b0f;
    --panel:#111319;
    --panel-soft:rgba(255,255,255,0.04);
    --border:rgba(212,175,55,0.18);
    --text:#f8f5ee;
    --muted:#b8b1a3;
    --gold:#d4af37;
    --shadow:0 24px 70px rgba(0,0,0,0.36);
}

*{box-sizing:border-box}

body{
    margin:0;
    font-family:Inter, Arial, Helvetica, sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 26%),
        linear-gradient(180deg, #08080c 0%, #101117 100%);
}

a{
    color:inherit;
    text-decoration:none;
}

.page{
    max-width:1240px;
    margin:0 auto;
    padding:30px 18px 60px;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:22px;
}

.brand{
    font-size:1.5rem;
    font-weight:900;
    letter-spacing:.04em;
}

.top-links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.top-link{
    padding:10px 14px;
    border-radius:999px;
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.08);
    font-weight:700;
    font-size:.94rem;
}

.hero,
.panel{
    background:linear-gradient(180deg, rgba(17,19,25,.96), rgba(17,19,25,.86));
    border:1px solid var(--border);
    border-radius:28px;
    box-shadow:var(--shadow);
}

.hero{
    padding:24px;
    margin-bottom:22px;
}

.eyebrow{
    color:#f0de9e;
    text-transform:uppercase;
    letter-spacing:.14em;
    font-size:.75rem;
    font-weight:800;
    margin-bottom:10px;
}

h1{
    margin:0 0 10px;
    font-size:2rem;
    line-height:1.08;
}

.sub{
    color:rgba(248,245,238,.72);
    line-height:1.65;
    max-width:820px;
}

.search-wrap{
    margin-top:18px;
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}

.search-wrap input{
    min-width:280px;
    flex:1;
    min-height:50px;
    padding:0 14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,0.10);
    background:rgba(255,255,255,0.05);
    color:var(--text);
    font:inherit;
}

.search-wrap button,
.search-wrap a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:50px;
    padding:0 18px;
    border-radius:14px;
    font-weight:800;
    border:1px solid transparent;
}

.btn-gold{
    background:linear-gradient(135deg, #d4af37, #f5deb3);
    color:#111;
}

.btn-dark{
    background:rgba(255,255,255,0.05);
    border-color:rgba(255,255,255,0.08);
    color:var(--text);
}

.panel{
    padding:24px;
}

.error{
    padding:14px 16px;
    border-radius:16px;
    margin-bottom:16px;
    font-weight:700;
    background:rgba(239,68,68,0.16);
    color:#ffd5d5;
    border:1px solid rgba(239,68,68,0.20);
}

.empty{
    padding:18px;
    border-radius:18px;
    background:var(--panel-soft);
    border:1px solid rgba(255,255,255,0.06);
    color:rgba(248,245,238,.68);
}

.grid{
    display:grid;
    grid-template-columns:repeat(2, 1fr);
    gap:16px;
}

.card{
    background:var(--panel-soft);
    border:1px solid rgba(255,255,255,0.06);
    border-radius:18px;
    padding:20px;
}

.card h2{
    margin:0 0 10px;
    font-size:1.2rem;
}

.meta{
    color:var(--muted);
    line-height:1.65;
    margin-bottom:14px;
}

.meta strong{
    color:var(--text);
}

.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:14px;
}

.action-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:0 14px;
    border-radius:12px;
    font-weight:800;
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.05);
}

.action-btn.gold{
    background:linear-gradient(135deg, #d4af37, #f5deb3);
    color:#111;
    border-color:transparent;
}

@media (max-width: 860px){
    .grid{
        grid-template-columns:1fr;
    }
}

@media (max-width: 640px){
    .page{
        padding:20px 12px 50px;
    }

    h1{
        font-size:1.7rem;
    }
}
</style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="brand">Doggie Dorian’s</div>
        <div class="top-links">
            <a class="top-link" href="admin-dashboard.php">Dashboard</a>
            <a class="top-link" href="admin-nav.php">Admin Nav</a>
            <a class="top-link" href="admin-members.php">Members</a>
            <a class="top-link" href="admin-dogs.php">Dogs</a>
            <a class="top-link" href="logout.php">Logout</a>
        </div>
    </div>

    <section class="hero">
        <div class="eyebrow">Admin Dog Control</div>
        <h1>Dog Management</h1>
        <div class="sub">
            Review dog profiles across the current system, search quickly by dog name, owner, or breed, and jump directly into owner or dog editing screens.
        </div>

        <form method="get" class="search-wrap">
            <input type="text" name="search" placeholder="Search dogs, owners, or breeds..." value="<?php echo ddAdminDogsH($search); ?>">
            <button type="submit" class="btn-gold">Search</button>
            <a href="admin-dogs.php" class="btn-dark">Reset</a>
        </form>
    </section>

    <section class="panel">
        <?php if ($error !== ''): ?>
            <div class="error"><?php echo ddAdminDogsH($error); ?></div>
        <?php endif; ?>

        <?php if (empty($dogs)): ?>
            <div class="empty">No dogs found.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($dogs as $dog): ?>
                    <?php $sourceParam = urlencode((string) $dog['source_table']); ?>
                    <div class="card">
                        <h2><?php echo ddAdminDogsH($dog['dog_name']); ?></h2>

                        <div class="meta">
                            <div><strong>Owner:</strong> <?php echo ddAdminDogsH($dog['owner_name']); ?></div>
                            <div><strong>Owner Email:</strong> <?php echo ddAdminDogsH($dog['owner_email'] !== '' ? $dog['owner_email'] : 'N/A'); ?></div>
                            <div><strong>Breed:</strong> <?php echo ddAdminDogsH($dog['breed'] !== '' ? $dog['breed'] : 'N/A'); ?></div>
                            <div><strong>Age:</strong> <?php echo ddAdminDogsH($dog['age'] !== '' ? $dog['age'] : 'N/A'); ?></div>
                            <div><strong>Notes:</strong> <?php echo ddAdminDogsH($dog['notes'] !== '' ? $dog['notes'] : ''); ?></div>
                        </div>

                        <div class="actions">
                            <?php if ((int) $dog['user_id'] > 0): ?>
                                <a class="action-btn" href="admin-member-view.php?id=<?php echo (int) $dog['user_id']; ?>">View Owner</a>
                            <?php endif; ?>

                            <a class="action-btn gold" href="admin-edit-dog.php?id=<?php echo (int) $dog['id']; ?>&source=<?php echo $sourceParam; ?>">Edit Dog</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</div>
</body>
</html>