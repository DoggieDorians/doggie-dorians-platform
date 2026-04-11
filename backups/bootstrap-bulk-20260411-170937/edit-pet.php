<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT name
        FROM sqlite_master
        WHERE type = 'table'
          AND name = :table_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tableName,
    ]);

    return (bool) $stmt->fetchColumn();
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->query("PRAGMA table_info(" . $tableName . ")");
    $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $names = [];

    foreach ($columns as $column) {
        if (isset($column['name'])) {
            $names[] = (string) $column['name'];
        }
    }

    return $names;
}

function firstExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }

    return null;
}

$userId = (int) $_SESSION['user_id'];
$petId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($petId <= 0) {
    header('Location: manage-pets.php');
    exit;
}

$error = '';
$success = '';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database not available.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (!tableExists($pdo, 'dogs')) {
        throw new RuntimeException('Pets table not found.');
    }

    $columns = getTableColumns($pdo, 'dogs');

    $ownerColumn = null;
    if (in_array('user_id', $columns, true)) {
        $ownerColumn = 'user_id';
    } elseif (in_array('member_id', $columns, true)) {
        $ownerColumn = 'member_id';
    }

    if ($ownerColumn === null) {
        throw new RuntimeException('Pet owner column not found.');
    }

    $nameColumn = firstExistingColumn($columns, ['dog_name', 'name']);
    if ($nameColumn === null) {
        throw new RuntimeException('Dog name column not found.');
    }

    $breedColumn = firstExistingColumn($columns, ['breed']);
    $sizeColumn = firstExistingColumn($columns, ['size']);
    $notesColumn = firstExistingColumn($columns, ['notes']);

    $selectFields = [
        "id",
        "{$ownerColumn} AS owner_id",
        "{$nameColumn} AS pet_name",
    ];

    if ($breedColumn !== null) {
        $selectFields[] = "{$breedColumn} AS breed";
    } else {
        $selectFields[] = "NULL AS breed";
    }

    if ($sizeColumn !== null) {
        $selectFields[] = "{$sizeColumn} AS size";
    } else {
        $selectFields[] = "NULL AS size";
    }

    if ($notesColumn !== null) {
        $selectFields[] = "{$notesColumn} AS notes";
    } else {
        $selectFields[] = "NULL AS notes";
    }

    $loadStmt = $pdo->prepare("
        SELECT " . implode(', ', $selectFields) . "
        FROM dogs
        WHERE id = :id
          AND {$ownerColumn} = :owner_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ':id' => $petId,
        ':owner_id' => $userId,
    ]);

    $pet = $loadStmt->fetch();

    if (!$pet) {
        header('Location: manage-pets.php');
        exit;
    }

    $name = trim((string) ($pet['pet_name'] ?? ''));
    $breed = trim((string) ($pet['breed'] ?? ''));
    $size = trim((string) ($pet['size'] ?? ''));
    $notes = trim((string) ($pet['notes'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $breed = trim((string) ($_POST['breed'] ?? ''));
        $size = trim((string) ($_POST['size'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $allowedSizes = ['', 'Small', 'Medium', 'Large'];

        if ($name === '') {
            $error = 'Dog name is required.';
        } elseif (!in_array($size, $allowedSizes, true)) {
            $error = 'Please choose a valid size.';
        } else {
            $updateParts = [];
            $params = [
                ':id' => $petId,
                ':owner_id' => $userId,
                ':name' => $name,
            ];

            $updateParts[] = "{$nameColumn} = :name";

            if ($breedColumn !== null) {
                $updateParts[] = "{$breedColumn} = :breed";
                $params[':breed'] = ($breed !== '') ? $breed : null;
            }

            if ($sizeColumn !== null) {
                $updateParts[] = "{$sizeColumn} = :size";
                $params[':size'] = ($size !== '') ? $size : null;
            }

            if ($notesColumn !== null) {
                $updateParts[] = "{$notesColumn} = :notes";
                $params[':notes'] = ($notes !== '') ? $notes : null;
            }

            $updateStmt = $pdo->prepare("
                UPDATE dogs
                SET " . implode(', ', $updateParts) . "
                WHERE id = :id
                  AND {$ownerColumn} = :owner_id
            ");
            $updateStmt->execute($params);

            header('Location: manage-pets.php');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = 'Unable to load or update this pet right now.';
    $name = $name ?? '';
    $breed = $breed ?? '';
    $size = $size ?? '';
    $notes = $notes ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pet | Doggie Dorian's</title>
    <meta name="description" content="Edit your pet details for Doggie Dorian's.">
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --bg: #0c0f14;
            --panel: #131923;
            --border: rgba(255,255,255,0.08);
            --text: #f5f7fb;
            --muted: #b7c0d1;
            --gold: #d6b36a;
            --gold-strong: #cfa85a;
            --danger-bg: rgba(255,125,125,0.12);
            --danger-border: rgba(255,125,125,0.26);
            --input: #0d131c;
            --shadow: 0 24px 70px rgba(0,0,0,0.35);
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top, rgba(214,179,106,0.08), transparent 28%),
                linear-gradient(180deg, #0a0d12 0%, #0f141c 100%);
            color: var(--text);
            padding: 32px 18px;
        }

        .container {
            max-width: 720px;
            margin: 0 auto;
        }

        .top-link {
            display: inline-block;
            margin-bottom: 18px;
            color: #f0cf8a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
        }

        .top-link:hover {
            text-decoration: underline;
        }

        .card {
            background: linear-gradient(180deg, rgba(19,25,35,0.98), rgba(14,19,28,0.98));
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 28px 28px 18px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(214,179,106,0.08), rgba(214,179,106,0.02));
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 13px;
            font-weight: 700;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
            line-height: 1.15;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 15px;
        }

        .card-body {
            padding: 28px;
        }

        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 18px;
            line-height: 1.55;
            font-size: 14px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: #ffd8d8;
        }

        .field {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #edf2fa;
        }

        input[type="text"],
        select,
        textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: var(--input);
            color: var(--text);
            border-radius: 14px;
            padding: 15px 16px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            font-family: inherit;
        }

        input[type="text"]:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(214,179,106,0.6);
            box-shadow: 0 0 0 4px rgba(214,179,106,0.12);
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }

        .btn,
        .btn-secondary {
            text-decoration: none;
            border: none;
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn {
            background: linear-gradient(180deg, #e3c27c 0%, var(--gold-strong) 100%);
            color: #17120a;
            box-shadow: 0 16px 32px rgba(207,168,90,0.20);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn:hover,
        .btn-secondary:hover {
            transform: translateY(-1px);
        }

        @media (max-width: 640px) {
            .card-header,
            .card-body {
                padding-left: 20px;
                padding-right: 20px;
            }

            h1 {
                font-size: 26px;
            }

            .actions {
                flex-direction: column;
            }

            .btn,
            .btn-secondary {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="manage-pets.php" class="top-link">← Back to Pets</a>

        <div class="card">
            <div class="card-header">
                <p class="eyebrow">Doggie Dorian's</p>
                <h1>Edit Pet</h1>
                <p class="subtitle">
                    Update your dog’s details so future bookings and care notes stay accurate.
                </p>
            </div>

            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div class="alert"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="field">
                        <label for="name">Dog Name *</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?php echo h($name); ?>"
                            placeholder="Enter your dog's name"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="breed">Breed</label>
                        <input
                            type="text"
                            id="breed"
                            name="breed"
                            value="<?php echo h($breed); ?>"
                            placeholder="Enter breed"
                        >
                    </div>

                    <div class="field">
                        <label for="size">Size</label>
                        <select id="size" name="size">
                            <option value="" <?php echo $size === '' ? 'selected' : ''; ?>>Select Size</option>
                            <option value="Small" <?php echo $size === 'Small' ? 'selected' : ''; ?>>Small</option>
                            <option value="Medium" <?php echo $size === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="Large" <?php echo $size === 'Large' ? 'selected' : ''; ?>>Large</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="notes">Care Notes</label>
                        <textarea
                            id="notes"
                            name="notes"
                            placeholder="Add feeding notes, routines, behavior notes, medications, or anything else helpful."
                        ><?php echo h($notes); ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn">Save Changes</button>
                        <a href="manage-pets.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>