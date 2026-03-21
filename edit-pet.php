<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$petId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($petId <= 0) {
    header('Location: my-pets.php');
    exit;
}

$success = '';
$error = '';
$pet = [];
$petColumns = [];

/**
 * Get all column names from pets table
 */
try {
    $columnsStmt = $pdo->query("PRAGMA table_info(pets)");
    $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if (!empty($col['name'])) {
            $petColumns[] = $col['name'];
        }
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

/**
 * Helper: check if column exists
 */
function hasPetColumn(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

/**
 * Load pet owned by logged-in user
 */
try {
    $stmt = $pdo->prepare("SELECT * FROM pets WHERE id = :id AND user_id = :user_id LIMIT 1");
    $stmt->execute([
        ':id' => $petId,
        ':user_id' => $userId
    ]);
    $pet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pet) {
        header('Location: my-pets.php');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

/**
 * Handle update
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fieldsToUpdate = [];
        $params = [
            ':id' => $petId,
            ':user_id' => $userId
        ];

        // Name fields
        if (hasPetColumn($petColumns, 'pet_name')) {
            $petName = trim($_POST['pet_name'] ?? '');
            if ($petName === '') {
                $error = 'Pet name is required.';
            } else {
                $fieldsToUpdate[] = "pet_name = :pet_name";
                $params[':pet_name'] = $petName;
            }
        }

        if (hasPetColumn($petColumns, 'name')) {
            $name = trim($_POST['name'] ?? '');
            if ($name === '' && !hasPetColumn($petColumns, 'pet_name')) {
                $error = 'Pet name is required.';
            } else {
                $fieldsToUpdate[] = "name = :name";
                $params[':name'] = $name;
            }
        }

        // Basic pet details
        if (hasPetColumn($petColumns, 'breed')) {
            $fieldsToUpdate[] = "breed = :breed";
            $params[':breed'] = trim($_POST['breed'] ?? '');
        }

        if (hasPetColumn($petColumns, 'age')) {
            $fieldsToUpdate[] = "age = :age";
            $params[':age'] = trim($_POST['age'] ?? '');
        }

        if (hasPetColumn($petColumns, 'weight')) {
            $fieldsToUpdate[] = "weight = :weight";
            $params[':weight'] = trim($_POST['weight'] ?? '');
        }

        if (hasPetColumn($petColumns, 'gender')) {
            $fieldsToUpdate[] = "gender = :gender";
            $params[':gender'] = trim($_POST['gender'] ?? '');
        }

        if (hasPetColumn($petColumns, 'size')) {
            $fieldsToUpdate[] = "size = :size";
            $params[':size'] = trim($_POST['size'] ?? '');
        }

        if (hasPetColumn($petColumns, 'color')) {
            $fieldsToUpdate[] = "color = :color";
            $params[':color'] = trim($_POST['color'] ?? '');
        }

        // Health / care details
        if (hasPetColumn($petColumns, 'notes')) {
            $fieldsToUpdate[] = "notes = :notes";
            $params[':notes'] = trim($_POST['notes'] ?? '');
        }

        if (hasPetColumn($petColumns, 'medical_notes')) {
            $fieldsToUpdate[] = "medical_notes = :medical_notes";
            $params[':medical_notes'] = trim($_POST['medical_notes'] ?? '');
        }

        if (hasPetColumn($petColumns, 'feeding_instructions')) {
            $fieldsToUpdate[] = "feeding_instructions = :feeding_instructions";
            $params[':feeding_instructions'] = trim($_POST['feeding_instructions'] ?? '');
        }

        if (hasPetColumn($petColumns, 'behavior_notes')) {
            $fieldsToUpdate[] = "behavior_notes = :behavior_notes";
            $params[':behavior_notes'] = trim($_POST['behavior_notes'] ?? '');
        }

        if (hasPetColumn($petColumns, 'medications')) {
            $fieldsToUpdate[] = "medications = :medications";
            $params[':medications'] = trim($_POST['medications'] ?? '');
        }

        if (hasPetColumn($petColumns, 'allergies')) {
            $fieldsToUpdate[] = "allergies = :allergies";
            $params[':allergies'] = trim($_POST['allergies'] ?? '');
        }

        if (hasPetColumn($petColumns, 'vet_name')) {
            $fieldsToUpdate[] = "vet_name = :vet_name";
            $params[':vet_name'] = trim($_POST['vet_name'] ?? '');
        }

        if (hasPetColumn($petColumns, 'vet_phone')) {
            $fieldsToUpdate[] = "vet_phone = :vet_phone";
            $params[':vet_phone'] = trim($_POST['vet_phone'] ?? '');
        }

        if ($error === '' && count($fieldsToUpdate) > 0) {
            $sql = "UPDATE pets 
                    SET " . implode(', ', $fieldsToUpdate) . "
                    WHERE id = :id AND user_id = :user_id";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            header('Location: my-pets.php?updated=1');
            exit;
        }

        if ($error === '' && count($fieldsToUpdate) === 0) {
            $error = 'No editable pet fields were found in the pets table.';
        }

        // Reload current pet data after attempted update
        $stmt = $pdo->prepare("SELECT * FROM pets WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute([
            ':id' => $petId,
            ':user_id' => $userId
        ]);
        $pet = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error = "Update failed: " . htmlspecialchars($e->getMessage());
    }
}

$petDisplayName = 'Pet';
if (!empty($pet['pet_name'])) {
    $petDisplayName = $pet['pet_name'];
} elseif (!empty($pet['name'])) {
    $petDisplayName = $pet['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pet | Doggie Dorian's</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #0b0b0f;
            --bg-soft: #111118;
            --panel: #15151c;
            --panel-2: #1d1d26;
            --gold: #d4af37;
            --gold-soft: #f1df9b;
            --text: #f5f2e8;
            --muted: #b7b0a0;
            --border: rgba(212, 175, 55, 0.18);
            --success-bg: rgba(126, 203, 138, 0.10);
            --success-border: rgba(126, 203, 138, 0.35);
            --success-text: #9be3a6;
            --error-bg: rgba(255, 107, 107, 0.10);
            --error-border: rgba(255, 107, 107, 0.30);
            --error-text: #ffb3b3;
            --shadow: 0 18px 50px rgba(0,0,0,0.35);
            --radius: 24px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top, rgba(212,175,55,0.08), transparent 30%),
                linear-gradient(180deg, #09090c 0%, #101016 100%);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .topbar {
            width: 100%;
            border-bottom: 1px solid var(--border);
            background: rgba(11,11,15,0.88);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-inner {
            width: min(1180px, 92%);
            margin: 0 auto;
            padding: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--gold-soft);
            letter-spacing: 0.4px;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav-links a {
            padding: 10px 16px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            transition: 0.25s ease;
            font-size: 0.95rem;
        }

        .nav-links a:hover {
            background: rgba(212,175,55,0.12);
            border-color: rgba(212,175,55,0.35);
        }

        .container {
            width: min(1180px, 92%);
            margin: 36px auto 60px;
        }

        .hero {
            background: linear-gradient(135deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 36px 30px;
            box-shadow: var(--shadow);
            margin-bottom: 26px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 0.8rem;
            margin-bottom: 12px;
        }

        .hero h1 {
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.1;
            margin-bottom: 12px;
        }

        .hero p {
            color: var(--muted);
            max-width: 760px;
            line-height: 1.7;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 26px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            font-size: 1.35rem;
            margin-bottom: 8px;
        }

        .card p.section-text {
            color: var(--muted);
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .message {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .message.success {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-text);
        }

        .message.error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.92rem;
            margin-bottom: 8px;
            color: var(--gold-soft);
            font-weight: 600;
        }

        input,
        textarea,
        select {
            width: 100%;
            background: var(--panel-2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 14px;
            font-size: 1rem;
            outline: none;
            transition: 0.2s ease;
            font-family: inherit;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: rgba(212,175,55,0.45);
            box-shadow: 0 0 0 3px rgba(212,175,55,0.08);
        }

        .form-actions {
            margin-top: 22px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 14px 20px;
            border-radius: 999px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: 0.25s ease;
            font-size: 0.96rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f1df9b);
            color: #141414;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            filter: brightness(1.03);
        }

        .btn-secondary {
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.02);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: rgba(212,175,55,0.08);
        }

        @media (max-width: 900px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">Doggie Dorian's Member Area</div>
            <nav class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="profile.php">Profile</a>
                <a href="my-pets.php">My Pets</a>
                <a href="add-pet.php">Add Pet</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="hero">
            <div class="eyebrow">Edit Pet</div>
            <h1><?php echo htmlspecialchars($petDisplayName); ?></h1>
            <p>
                Update your pet's profile so care details, notes, and service information stay accurate.
            </p>
        </section>

        <section class="card">
            <h2>Pet Information</h2>
            <p class="section-text">
                Edit the details connected to this pet profile.
            </p>

            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-grid">
                    <?php if (hasPetColumn($petColumns, 'pet_name')): ?>
                        <div class="form-group full">
                            <label for="pet_name">Pet Name</label>
                            <input
                                type="text"
                                id="pet_name"
                                name="pet_name"
                                value="<?php echo htmlspecialchars($pet['pet_name'] ?? ''); ?>"
                                required
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'name') && !hasPetColumn($petColumns, 'pet_name')): ?>
                        <div class="form-group full">
                            <label for="name">Pet Name</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?php echo htmlspecialchars($pet['name'] ?? ''); ?>"
                                required
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'breed')): ?>
                        <div class="form-group">
                            <label for="breed">Breed</label>
                            <input
                                type="text"
                                id="breed"
                                name="breed"
                                value="<?php echo htmlspecialchars($pet['breed'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'age')): ?>
                        <div class="form-group">
                            <label for="age">Age</label>
                            <input
                                type="text"
                                id="age"
                                name="age"
                                value="<?php echo htmlspecialchars($pet['age'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'weight')): ?>
                        <div class="form-group">
                            <label for="weight">Weight</label>
                            <input
                                type="text"
                                id="weight"
                                name="weight"
                                value="<?php echo htmlspecialchars($pet['weight'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'gender')): ?>
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <input
                                type="text"
                                id="gender"
                                name="gender"
                                value="<?php echo htmlspecialchars($pet['gender'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'size')): ?>
                        <div class="form-group">
                            <label for="size">Size</label>
                            <select id="size" name="size">
                                <?php
                                $currentSize = $pet['size'] ?? '';
                                $sizeOptions = ['', 'Small', 'Medium', 'Large'];
                                foreach ($sizeOptions as $option):
                                ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($currentSize === $option ? 'selected' : ''); ?>>
                                        <?php echo $option === '' ? 'Select size' : htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'color')): ?>
                        <div class="form-group">
                            <label for="color">Color</label>
                            <input
                                type="text"
                                id="color"
                                name="color"
                                value="<?php echo htmlspecialchars($pet['color'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'notes')): ?>
                        <div class="form-group full">
                            <label for="notes">General Notes</label>
                            <textarea id="notes" name="notes"><?php echo htmlspecialchars($pet['notes'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'medical_notes')): ?>
                        <div class="form-group full">
                            <label for="medical_notes">Medical Notes</label>
                            <textarea id="medical_notes" name="medical_notes"><?php echo htmlspecialchars($pet['medical_notes'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'feeding_instructions')): ?>
                        <div class="form-group full">
                            <label for="feeding_instructions">Feeding Instructions</label>
                            <textarea id="feeding_instructions" name="feeding_instructions"><?php echo htmlspecialchars($pet['feeding_instructions'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'behavior_notes')): ?>
                        <div class="form-group full">
                            <label for="behavior_notes">Behavior Notes</label>
                            <textarea id="behavior_notes" name="behavior_notes"><?php echo htmlspecialchars($pet['behavior_notes'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'medications')): ?>
                        <div class="form-group full">
                            <label for="medications">Medications</label>
                            <textarea id="medications" name="medications"><?php echo htmlspecialchars($pet['medications'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'allergies')): ?>
                        <div class="form-group full">
                            <label for="allergies">Allergies</label>
                            <textarea id="allergies" name="allergies"><?php echo htmlspecialchars($pet['allergies'] ?? ''); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'vet_name')): ?>
                        <div class="form-group">
                            <label for="vet_name">Veterinarian Name</label>
                            <input
                                type="text"
                                id="vet_name"
                                name="vet_name"
                                value="<?php echo htmlspecialchars($pet['vet_name'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <?php if (hasPetColumn($petColumns, 'vet_phone')): ?>
                        <div class="form-group">
                            <label for="vet_phone">Veterinarian Phone</label>
                            <input
                                type="text"
                                id="vet_phone"
                                name="vet_phone"
                                value="<?php echo htmlspecialchars($pet['vet_phone'] ?? ''); ?>"
                            >
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Pet Changes</button>
                    <a href="my-pets.php" class="btn btn-secondary">Back to My Pets</a>
                </div>
            </form>
        </section>
    </main>

</body>
</html>