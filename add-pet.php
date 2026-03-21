<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$success = '';
$error = '';

$petName = '';
$breed = '';
$age = '';
$weight = '';
$birthday = '';
$gender = '';
$spayedNeutered = '';
$status = 'Active';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $petName = trim((string) ($_POST['pet_name'] ?? ''));
    $breed = trim((string) ($_POST['breed'] ?? ''));
    $age = trim((string) ($_POST['age'] ?? ''));
    $weight = trim((string) ($_POST['weight'] ?? ''));
    $birthday = trim((string) ($_POST['birthday'] ?? ''));
    $gender = trim((string) ($_POST['gender'] ?? ''));
    $spayedNeutered = trim((string) ($_POST['spayed_neutered'] ?? ''));
    $status = 'Active';

    if ($petName === '') {
        $error = 'Please enter your dog’s name.';
    } elseif ($breed === '') {
        $error = 'Please enter your dog’s breed.';
    } elseif ($age === '') {
        $error = 'Please enter your dog’s age.';
    } elseif (!is_numeric($age) || (int) $age < 0 || (int) $age > 40) {
        $error = 'Please enter a valid age.';
    } elseif ($weight === '') {
        $error = 'Please enter your dog’s weight.';
    } elseif ($birthday === '') {
        $error = 'Please select your dog’s birthday.';
    } elseif ($gender === '') {
        $error = 'Please select your dog’s gender.';
    } elseif ($spayedNeutered !== 'Yes' && $spayedNeutered !== 'No') {
        $error = 'Please select whether your dog is spayed or neutered.';
    } else {
        try {
            $userCheck = $pdo->prepare("
                SELECT id
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $userCheck->execute([$userId]);
            $existingUser = $userCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existingUser) {
                $error = 'Your account could not be verified. Please log out and log back in.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pets (
                        user_id,
                        pet_name,
                        breed,
                        age,
                        weight,
                        birthday,
                        gender,
                        spayed_neutered,
                        status,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");

                $stmt->execute([
                    $userId,
                    $petName,
                    $breed,
                    (int) $age,
                    $weight,
                    $birthday,
                    $gender,
                    $spayedNeutered,
                    $status
                ]);

                $success = 'Your pet profile has been added successfully.';

                $petName = '';
                $breed = '';
                $age = '';
                $weight = '';
                $birthday = '';
                $gender = '';
                $spayedNeutered = '';
            }
        } catch (PDOException $e) {
            $error = 'Could not save pet profile: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Pet | Doggie Dorian's</title>
    <meta name="description" content="Add your dog’s profile to your Doggie Dorian’s member account.">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #f4f1ec;
            --panel: #ffffff;
            --text: #121212;
            --muted: #666;
            --gold: #d7b26a;
            --danger-bg: #fce8e8;
            --danger-text: #a52828;
            --success-bg: #e9f7ee;
            --success-text: #1f7a44;
            --shadow: 0 18px 45px rgba(0,0,0,0.08);
            --radius: 24px;
            --max: 860px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .page {
            min-height: 100vh;
            padding: 40px 20px 60px;
        }

        .wrap {
            max-width: var(--max);
            margin: 0 auto;
        }

        .card {
            background: var(--panel);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 36px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        h1 {
            font-size: 3rem;
            line-height: 1;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
        }

        .subtext {
            color: var(--muted);
            font-size: 1.05rem;
            margin-bottom: 28px;
        }

        .alert {
            padding: 16px 18px;
            border-radius: 18px;
            margin-bottom: 22px;
            font-size: 1rem;
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid #f3caca;
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #ccebd8;
        }

        form {
            display: grid;
            gap: 22px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #111;
        }

        input,
        select {
            width: 100%;
            min-height: 54px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid #d6d1c9;
            background: #fff;
            color: #111;
            font-size: 1rem;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(215,178,106,0.15);
        }

        .choice-card {
            border: 1px solid #ddd7cf;
            border-radius: 18px;
            background: #faf8f5;
            padding: 18px;
        }

        .actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            padding-top: 4px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 52px;
            padding: 0 22px;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary {
            background: #0f1115;
            color: #fff;
        }

        .btn-secondary {
            background: #efefef;
            color: #111;
        }

        .helper {
            color: var(--muted);
            font-size: 0.92rem;
            margin-top: 6px;
        }

        @media (max-width: 720px) {
            .page {
                padding: 20px 12px 40px;
            }

            .card {
                padding: 24px;
                border-radius: 20px;
            }

            h1 {
                font-size: 2.2rem;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="card">
                <h1>Add Your Dog</h1>
                <p class="subtext">
                    Create a polished pet profile so your care experience can feel personal, safe, and premium.
                </p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="field field-full">
                        <label for="pet_name">Dog Name</label>
                        <input type="text" id="pet_name" name="pet_name" value="<?php echo h($petName); ?>" required>
                    </div>

                    <div class="grid">
                        <div class="field">
                            <label for="breed">Breed</label>
                            <input type="text" id="breed" name="breed" value="<?php echo h($breed); ?>" required>
                        </div>

                        <div class="field">
                            <label for="age">Age</label>
                            <input type="number" id="age" name="age" min="0" max="40" value="<?php echo h($age); ?>" required>
                        </div>

                        <div class="field">
                            <label for="weight">Weight</label>
                            <input type="text" id="weight" name="weight" placeholder="Example: 22 lbs" value="<?php echo h($weight); ?>" required>
                        </div>

                        <div class="field">
                            <label for="birthday">Birthday</label>
                            <input type="date" id="birthday" name="birthday" value="<?php echo h($birthday); ?>" required>
                        </div>

                        <div class="field">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="spayed_neutered">Spayed / Neutered</label>
                            <select id="spayed_neutered" name="spayed_neutered" required>
                                <option value="">Select option</option>
                                <option value="Yes" <?php echo $spayedNeutered === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                <option value="No" <?php echo $spayedNeutered === 'No' ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                    </div>

                    <div class="choice-card">
                        <strong>Profile note:</strong>
                        <div class="helper">
                            This profile helps us personalize care, keep records cleaner, and make future booking smoother.
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Pet Profile</button>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>