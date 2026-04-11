<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function currentUserId()
{
    foreach (array('user_id', 'member_id', 'client_id', 'id') as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function isMemberLike()
{
    return currentUserId() > 0 && currentUserRole() !== 'walker';
}

if (!isMemberLike()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = array();
        return array();
    } catch (Exception $e) {
        $cache[$table] = array();
        return array();
    }
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    $columns = getTableColumns($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function countUnreadNotificationsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $readCol = firstExistingColumn($pdo, $table, array('is_read', 'read_status', 'seen', 'viewed'));
        $userCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id'));

        if ($readCol === null || $userCol === null) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$userCol} = :id AND COALESCE({$readCol}, 0) = 0");
            if (safeExecute($stmt, array(':id' => $userId))) {
                return (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            continue;
        } catch (Exception $e) {
            continue;
        }
    }

    return 0;
}

function fetchMemberName(PDO $pdo, $userId)
{
    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'client_name', 'member_name'));

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return 'Member';
}

function petTableName(PDO $pdo)
{
    foreach (array('pets', 'dogs') as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function ensurePetTable(PDO $pdo)
{
    $table = petTableName($pdo);
    if ($table !== null) {
        return $table;
    }

    $sql = "CREATE TABLE IF NOT EXISTS pets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        breed TEXT DEFAULT '',
        size TEXT DEFAULT '',
        age TEXT DEFAULT '',
        notes TEXT DEFAULT '',
        created_at TEXT DEFAULT ''
    )";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }

    return hasTable($pdo, 'pets') ? 'pets' : null;
}

function insertPetForUser(PDO $pdo, $userId, array $data)
{
    $table = ensurePetTable($pdo);
    if ($table === null) {
        return false;
    }

    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return false;
    }

    $ownerCol = null;
    foreach (array('user_id', 'member_id', 'owner_id', 'client_id') as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $ownerCol = $candidate;
            break;
        }
    }

    $nameCol = null;
    foreach (array('name', 'pet_name', 'dog_name') as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $nameCol = $candidate;
            break;
        }
    }

    if ($ownerCol === null || $nameCol === null) {
        return false;
    }

    $map = array(
        $ownerCol => $userId,
        $nameCol => $data['name'],
    );

    foreach (array('breed', 'size', 'age', 'notes') as $field) {
        if (in_array($field, $columns, true)) {
            $map[$field] = $data[$field];
        }
    }

    if (in_array('care_notes', $columns, true)) {
        $map['care_notes'] = $data['notes'];
    }

    if (in_array('special_notes', $columns, true)) {
        $map['special_notes'] = $data['notes'];
    }

    if (in_array('created_at', $columns, true)) {
        $map['created_at'] = date('Y-m-d H:i:s');
    }

    $fields = array_keys($map);
    $placeholders = array();
    $params = array();

    foreach ($fields as $field) {
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $map[$field];
    }

    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);

    return safeExecute($stmt, $params);
}

$userId = currentUserId();
$memberName = fetchMemberName($pdo, $userId);
$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);

$flash = isset($_SESSION['add_pet_flash']) ? (string) $_SESSION['add_pet_flash'] : '';
$flashType = isset($_SESSION['add_pet_flash_type']) ? (string) $_SESSION['add_pet_flash_type'] : '';
unset($_SESSION['add_pet_flash'], $_SESSION['add_pet_flash_type']);

$form = array(
    'name' => '',
    'breed' => '',
    'size' => '',
    'age' => '',
    'notes' => '',
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name'] = trim((string) (isset($_POST['name']) ? $_POST['name'] : ''));
    $form['breed'] = trim((string) (isset($_POST['breed']) ? $_POST['breed'] : ''));
    $form['size'] = trim((string) (isset($_POST['size']) ? $_POST['size'] : ''));
    $form['age'] = trim((string) (isset($_POST['age']) ? $_POST['age'] : ''));
    $form['notes'] = trim((string) (isset($_POST['notes']) ? $_POST['notes'] : ''));

    if ($form['name'] === '') {
        $error = 'Please enter your pet’s name.';
    } elseif ($form['breed'] === '') {
        $error = 'Please enter your pet’s breed.';
    } elseif ($form['size'] === '') {
        $error = 'Please choose your pet’s size.';
    } else {
        if (insertPetForUser($pdo, $userId, $form)) {
            $_SESSION['add_pet_flash_type'] = 'success';
            $_SESSION['add_pet_flash'] = 'Your pet was added successfully.';
            redirectTo('manage-pets.php');
        } else {
            $error = 'We could not add your pet right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Pet | Doggie Dorian’s</title>
    <meta name="description" content="Add a pet profile to your Doggie Dorian’s account.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1120px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.25rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
            color: #d7f1dd;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 900;
        }

        .cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            transition: transform .15s ease;
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        form {
            display: grid;
            gap: 16px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
        }

        input, select, textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .helper-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .helper-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .helper-box strong {
            display: block;
            margin-bottom: 6px;
            color: #f3e5c7;
        }

        @media (max-width: 900px) {
            .hero,
            .form-grid,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .cta-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>

            <div class="top-links">
                <a class="top-link" href="dashboard.php">Dashboard</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="manage-pets.php">Manage Pets</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="flash flash-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">New Pet Profile</div>
                <h1>Add a Pet</h1>
                <div class="sub">
                    Create a clean pet profile so your future bookings, care notes, and service details stay organized under one member account.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Member</div>
                        <div class="stat-value"><?php echo h($memberName); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Booking Hub</div>
                        <div class="stat-value">Book Service</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Unread Notices</div>
                        <div class="stat-value"><?php echo (int) $unreadNotifications; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Before You Save</div>
                <h2>What to include</h2>
                <div class="sub">
                    Add the basics now so booking stays easier later.
                </div>

                <div class="helper-list">
                    <div class="helper-box">
                        <strong>Name and breed</strong>
                        Makes booking and profile management cleaner.
                    </div>

                    <div class="helper-box">
                        <strong>Size and age</strong>
                        Helps support better care visibility and pricing context.
                    </div>

                    <div class="helper-box">
                        <strong>Care notes</strong>
                        Add anything useful you want visible for future service coordination.
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="eyebrow">Pet Details</div>
            <h2>Create your pet profile</h2>
            <div class="sub" style="margin-bottom:18px;">
                Add your pet now, then return to <strong>Book Service</strong> when you’re ready to schedule.
            </div>

            <form method="post" action="add-pet.php" novalidate>
                <div class="form-grid">
                    <div>
                        <label for="name">Pet Name</label>
                        <input type="text" id="name" name="name" value="<?php echo h($form['name']); ?>" required>
                    </div>

                    <div>
                        <label for="breed">Breed</label>
                        <input type="text" id="breed" name="breed" value="<?php echo h($form['breed']); ?>" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="size">Size</label>
                        <select id="size" name="size" required>
                            <option value="">Select size</option>
                            <option value="small" <?php echo $form['size'] === 'small' ? 'selected' : ''; ?>>Small</option>
                            <option value="medium" <?php echo $form['size'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="large" <?php echo $form['size'] === 'large' ? 'selected' : ''; ?>>Large</option>
                        </select>
                    </div>

                    <div>
                        <label for="age">Age</label>
                        <input type="text" id="age" name="age" value="<?php echo h($form['age']); ?>" placeholder="Example: 2 years">
                    </div>
                </div>

                <div>
                    <label for="notes">Care Notes</label>
                    <textarea id="notes" name="notes" placeholder="Temperament, feeding notes, medical reminders, walking habits, or anything else important..."><?php echo h($form['notes']); ?></textarea>
                </div>

                <div class="cta-row">
                    <button type="submit" class="btn btn-gold">Save Pet</button>
                    <a class="btn btn-light" href="manage-pets.php">Back to Manage Pets</a>
                    <a class="btn btn-light" href="book-service.php">Book Service</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>