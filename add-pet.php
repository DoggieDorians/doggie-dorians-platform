<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $petName = trim($_POST['pet_name'] ?? '');
    $breed = trim($_POST['breed'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $spayedNeutered = isset($_POST['spayed_neutered']) ? 1 : 0;

    if ($petName === '') {
        $error = 'Please enter your dog’s name.';
    } else {
        try {
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
                    photo_path,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                $petName,
                $breed !== '' ? $breed : null,
                $age !== '' ? (int)$age : null,
                $weight !== '' ? $weight : null,
                $birthday !== '' ? $birthday : null,
                $gender !== '' ? $gender : null,
                $spayedNeutered,
                null,
                'active'
            ]);

            $success = 'Pet profile added successfully.';
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
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f8fb;
            color: #111;
        }

        .page {
            min-height: 100vh;
            padding: 40px 20px;
            box-sizing: border-box;
        }

        .wrap {
            max-width: 760px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 28px;
            font-weight: 700;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav-links a {
            text-decoration: none;
            color: #111;
            background: #fff;
            padding: 10px 14px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            font-weight: 700;
        }

        .card {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 34px;
        }

        .subtext {
            margin: 0 0 24px;
            color: #666;
            line-height: 1.6;
        }

        .message {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
        }

        .error {
            background: #ffe7e7;
            color: #9b1111;
        }

        .success {
            background: #e8f8ea;
            color: #146c2e;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin: 0 0 6px;
            font-weight: 700;
        }

        input,
        select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9d9d9;
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 15px;
            background: #fff;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #111;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fafafa;
            padding: 14px;
            border-radius: 12px;
            border: 1px solid #ececec;
        }

        .checkbox-row input {
            width: auto;
            margin: 0;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 14px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .btn-primary {
            background: #111;
            color: #fff;
        }

        .btn-secondary {
            background: #efefef;
            color: #111;
        }

        @media (max-width: 720px) {
            .grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="topbar">
                <div class="brand">Doggie Dorian’s</div>
                <div class="nav-links">
                    <a href="dashboard.php">Dashboard</a>
                    <a href="book-walk.php">Book Walk</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <div class="card">
                <h1>Add Your Dog</h1>
                <p class="subtext">Create a polished pet profile so your care experience can feel personal, safe, and premium.</p>

                <?php if ($error !== ''): ?>
                    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="message success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="grid">
                        <div class="full">
                            <label for="pet_name">Dog Name</label>
                            <input type="text" id="pet_name" name="pet_name" required>
                        </div>

                        <div>
                            <label for="breed">Breed</label>
                            <input type="text" id="breed" name="breed">
                        </div>

                        <div>
                            <label for="age">Age</label>
                            <input type="number" id="age" name="age" min="0" step="1">
                        </div>

                        <div>
                            <label for="weight">Weight</label>
                            <input type="text" id="weight" name="weight" placeholder="Example: 22 lbs">
                        </div>

                        <div>
                            <label for="birthday">Birthday</label>
                            <input type="date" id="birthday" name="birthday">
                        </div>

                        <div>
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender">
                                <option value="">Select gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <div class="full">
                            <label>Spayed / Neutered</label>
                            <div class="checkbox-row">
                                <input type="checkbox" id="spayed_neutered" name="spayed_neutered" value="1">
                                <label for="spayed_neutered" style="margin: 0; font-weight: 600;">Yes, this pet is spayed or neutered</label>
                            </div>
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