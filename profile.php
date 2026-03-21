<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$success = '';
$error = '';
$user = [];
$userColumns = [];

/**
 * Get all column names from users table
 */
try {
    $columnsStmt = $pdo->query("PRAGMA table_info(users)");
    $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if (!empty($col['name'])) {
            $userColumns[] = $col['name'];
        }
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

/**
 * Helper: does column exist?
 */
function hasColumn(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

/**
 * Load user
 */
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php');
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
        $params = [':id' => $userId];

        if (hasColumn($userColumns, 'full_name')) {
            $fullName = trim($_POST['full_name'] ?? '');
            $fieldsToUpdate[] = "full_name = :full_name";
            $params[':full_name'] = $fullName;
        }

        if (hasColumn($userColumns, 'name')) {
            $name = trim($_POST['name'] ?? '');
            $fieldsToUpdate[] = "name = :name";
            $params[':name'] = $name;
        }

        if (hasColumn($userColumns, 'phone')) {
            $phone = trim($_POST['phone'] ?? '');
            $fieldsToUpdate[] = "phone = :phone";
            $params[':phone'] = $phone;
        }

        if (hasColumn($userColumns, 'address')) {
            $address = trim($_POST['address'] ?? '');
            $fieldsToUpdate[] = "address = :address";
            $params[':address'] = $address;
        }

        if (hasColumn($userColumns, 'city')) {
            $city = trim($_POST['city'] ?? '');
            $fieldsToUpdate[] = "city = :city";
            $params[':city'] = $city;
        }

        if (hasColumn($userColumns, 'state')) {
            $state = trim($_POST['state'] ?? '');
            $fieldsToUpdate[] = "state = :state";
            $params[':state'] = $state;
        }

        if (hasColumn($userColumns, 'zip_code')) {
            $zipCode = trim($_POST['zip_code'] ?? '');
            $fieldsToUpdate[] = "zip_code = :zip_code";
            $params[':zip_code'] = $zipCode;
        }

        if (hasColumn($userColumns, 'zipcode')) {
            $zipcode = trim($_POST['zipcode'] ?? '');
            $fieldsToUpdate[] = "zipcode = :zipcode";
            $params[':zipcode'] = $zipcode;
        }

        if (count($fieldsToUpdate) > 0) {
            $sql = "UPDATE users SET " . implode(', ', $fieldsToUpdate) . " WHERE id = :id";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
            $success = "Your profile has been updated successfully.";
        } else {
            $error = "No editable profile fields were found in the users table.";
        }

        // Reload fresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error = "Update failed: " . htmlspecialchars($e->getMessage());
    }
}

$displayName = 'Member';
if (!empty($user['full_name'])) {
    $displayName = $user['full_name'];
} elseif (!empty($user['name'])) {
    $displayName = $user['name'];
} elseif (!empty($user['email'])) {
    $displayName = $user['email'];
}

$memberSince = '';
if (!empty($user['created_at'])) {
    $timestamp = strtotime($user['created_at']);
    if ($timestamp) {
        $memberSince = date('F j, Y', $timestamp);
    } else {
        $memberSince = $user['created_at'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Doggie Dorian's</title>
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

        .layout {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 22px;
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

        input {
            width: 100%;
            background: var(--panel-2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 14px;
            font-size: 1rem;
            outline: none;
            transition: 0.2s ease;
        }

        input:focus {
            border-color: rgba(212,175,55,0.45);
            box-shadow: 0 0 0 3px rgba(212,175,55,0.08);
        }

        input[readonly] {
            opacity: 0.8;
            cursor: not-allowed;
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

        .info-list {
            display: grid;
            gap: 14px;
        }

        .info-box {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
        }

        .info-label {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1.5;
            word-break: break-word;
        }

        .small-note {
            margin-top: 18px;
            color: var(--muted);
            line-height: 1.7;
            font-size: 0.95rem;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }

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
            <div class="eyebrow">Member Profile</div>
            <h1><?php echo htmlspecialchars($displayName); ?></h1>
            <p>
                Review and update your account information so your member profile stays accurate
                and ready for bookings, care details, and future account features.
            </p>
        </section>

        <div class="layout">
            <section class="card">
                <h2>Edit Profile</h2>
                <p class="section-text">
                    Update the personal details stored on your member account.
                </p>

                <?php if ($success !== ''): ?>
                    <div class="message success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-grid">
                        <?php if (hasColumn($userColumns, 'full_name')): ?>
                            <div class="form-group full">
                                <label for="full_name">Full Name</label>
                                <input
                                    type="text"
                                    id="full_name"
                                    name="full_name"
                                    value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'name')): ?>
                            <div class="form-group full">
                                <label for="name">Name</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'email')): ?>
                            <div class="form-group full">
                                <label for="email">Email Address</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                    readonly
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'phone')): ?>
                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input
                                    type="text"
                                    id="phone"
                                    name="phone"
                                    value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'address')): ?>
                            <div class="form-group full">
                                <label for="address">Address</label>
                                <input
                                    type="text"
                                    id="address"
                                    name="address"
                                    value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'city')): ?>
                            <div class="form-group">
                                <label for="city">City</label>
                                <input
                                    type="text"
                                    id="city"
                                    name="city"
                                    value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'state')): ?>
                            <div class="form-group">
                                <label for="state">State</label>
                                <input
                                    type="text"
                                    id="state"
                                    name="state"
                                    value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'zip_code')): ?>
                            <div class="form-group">
                                <label for="zip_code">ZIP Code</label>
                                <input
                                    type="text"
                                    id="zip_code"
                                    name="zip_code"
                                    value="<?php echo htmlspecialchars($user['zip_code'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <?php if (hasColumn($userColumns, 'zipcode')): ?>
                            <div class="form-group">
                                <label for="zipcode">ZIP Code</label>
                                <input
                                    type="text"
                                    id="zipcode"
                                    name="zipcode"
                                    value="<?php echo htmlspecialchars($user['zipcode'] ?? ''); ?>"
                                >
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </form>
            </section>

            <aside class="card">
                <h2>Account Summary</h2>
                <p class="section-text">
                    A quick snapshot of the member account currently logged in.
                </p>

                <div class="info-list">
                    <div class="info-box">
                        <div class="info-label">Member ID</div>
                        <div class="info-value"><?php echo (int)($user['id'] ?? 0); ?></div>
                    </div>

                    <?php if (!empty($user['email'])): ?>
                        <div class="info-box">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($memberSince !== ''): ?>
                        <div class="info-box">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?php echo htmlspecialchars($memberSince); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="small-note">
                    Email is shown as read-only here to keep login credentials stable.
                    Password changes can be added as a separate secure page next.
                </div>
            </aside>
        </div>
    </main>

</body>
</html>