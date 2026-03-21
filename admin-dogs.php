<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/data/config/db.php';

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return array_column($rows, 'name');
}

function pickCol(array $cols, array $options): ?string {
    foreach ($options as $o) {
        if (in_array($o, $cols, true)) return $o;
    }
    return null;
}

$search = strtolower(trim($_GET['search'] ?? ''));
$dogs = [];
$error = '';

try {

    if (!tableExists($pdo, 'dogs')) {
        throw new RuntimeException('Dogs table not found.');
    }

    $dogCols = getColumns($pdo, 'dogs');
    $userCols = tableExists($pdo, 'users') ? getColumns($pdo, 'users') : [];

    $ownerCol = pickCol($dogCols, ['user_id','member_id','owner_id']);
    $nameCol = pickCol($dogCols, ['name','dog_name','pet_name']);
    $breedCol = pickCol($dogCols, ['breed']);
    $ageCol = pickCol($dogCols, ['age']);
    $notesCol = pickCol($dogCols, ['notes']);

    $userNameCol = pickCol($userCols, ['full_name','name']);

    $sql = "
        SELECT 
            d.id,
            " . ($nameCol ? "d.$nameCol AS dog_name" : "'Dog' AS dog_name") . ",
            " . ($breedCol ? "d.$breedCol AS breed" : "NULL AS breed") . ",
            " . ($ageCol ? "d.$ageCol AS age" : "NULL AS age") . ",
            " . ($notesCol ? "d.$notesCol AS notes" : "NULL AS notes") . ",
            u.id AS user_id,
            " . ($userNameCol ? "u.$userNameCol AS owner_name" : "'Owner' AS owner_name") . "
        FROM dogs d
        LEFT JOIN users u ON d.$ownerCol = u.id
        ORDER BY d.id DESC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Search filter
    if ($search !== '') {
        $dogs = array_filter($rows, function($d) use ($search) {
            return str_contains(strtolower($d['dog_name'] ?? ''), $search)
                || str_contains(strtolower($d['owner_name'] ?? ''), $search)
                || str_contains(strtolower($d['breed'] ?? ''), $search);
        });
    } else {
        $dogs = $rows;
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Dog Management</title>
<style>
body { background:#0b0b0f; color:#fff; font-family:Arial; margin:0; }
.container { padding:30px; }
.card { background:#111; padding:20px; border-radius:16px; margin-bottom:15px; }
.btn { padding:10px 14px; border-radius:10px; text-decoration:none; display:inline-block; margin-right:10px;}
.gold { background:#d4af37; color:#000; }
.gray { background:#333; color:#fff; }
input { padding:10px; border-radius:8px; border:none; width:300px; margin-bottom:20px;}
</style>
</head>

<body>
<div class="container">

<h1>Dog Management</h1>

<form method="get">
<input type="text" name="search" placeholder="Search dogs..." value="<?php echo h($search); ?>">
</form>

<?php if ($error): ?>
<div><?php echo h($error); ?></div>
<?php endif; ?>

<?php if (empty($dogs)): ?>
<p>No dogs found.</p>
<?php else: ?>

<?php foreach ($dogs as $dog): ?>
<div class="card">

<h2><?php echo h($dog['dog_name']); ?></h2>

<p><strong>Owner:</strong> <?php echo h($dog['owner_name']); ?></p>
<p><strong>Breed:</strong> <?php echo h($dog['breed'] ?? 'N/A'); ?></p>
<p><strong>Age:</strong> <?php echo h($dog['age'] ?? 'N/A'); ?></p>
<p><strong>Notes:</strong> <?php echo h($dog['notes'] ?? ''); ?></p>

<a class="btn gray" href="admin-member-view.php?id=<?php echo (int)$dog['user_id']; ?>">View Owner</a>
<a class="btn gold" href="admin-edit-dog.php?id=<?php echo (int)$dog['id']; ?>">Edit Dog</a>

</div>
<?php endforeach; ?>

<?php endif; ?>

</div>
</body>
</html>