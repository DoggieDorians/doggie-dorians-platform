<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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

function petTableName(PDO $pdo)
{
    foreach (array('pets', 'dogs') as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function fetchPetsForUser(PDO $pdo, $userId)
{
    $table = petTableName($pdo);
    if ($table === null) {
        return array();
    }

    $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
    $ownerCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'owner_id', 'client_id'));
    $nameCol = firstExistingColumn($pdo, $table, array('name', 'pet_name', 'dog_name'));

    if ($idCol === null || $ownerCol === null || $nameCol === null) {
        return array();
    }

    $breedCol = firstExistingColumn($pdo, $table, array('breed'));
    $sizeCol = firstExistingColumn($pdo, $table, array('size'));
    $ageCol = firstExistingColumn($pdo, $table, array('age'));
    $notesCol = firstExistingColumn($pdo, $table, array('notes', 'care_notes', 'special_notes'));
    $createdCol = firstExistingColumn($pdo, $table, array('created_at'));

    $select = array(
        $idCol . ' AS internal_id',
        $nameCol . ' AS pet_name'
    );

    $select[] = $breedCol !== null ? $breedCol . ' AS breed' : "'' AS breed";
    $select[] = $sizeCol !== null ? $sizeCol . ' AS size' : "'' AS size";
    $select[] = $ageCol !== null ? $ageCol . ' AS age' : "'' AS age";
    $select[] = $notesCol !== null ? $notesCol . ' AS notes' : "'' AS notes";
    $select[] = $createdCol !== null ? $createdCol . ' AS created_at' : "'' AS created_at";

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE ' . $ownerCol . ' = :user_id ORDER BY ' . $nameCol . ' ASC';
    $stmt = $pdo->prepare($sql);

    if (!safeExecute($stmt, array(':user_id' => $userId))) {
        return array();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function deletePetForUser(PDO $pdo, $userId, $petId)
{
    $table = petTableName($pdo);
    if ($table === null) {
        return false;
    }

    $idCol = firstExistingColumn($pdo, $table, array('id', 'pet_id', 'dog_id'));
    $ownerCol = firstExistingColumn($pdo, $table, array('user_id', 'member_id', 'owner_id', 'client_id'));

    if ($idCol === null || $ownerCol === null) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $idCol . ' = :pet_id AND ' . $ownerCol . ' = :user_id');
    return safeExecute($stmt, array(':pet_id' => $petId, ':user_id' => $userId));
}

$userId = currentUserId();
$memberName = fetchMemberName($pdo, $userId);
$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);

$flash = isset($_SESSION['manage_pets_flash']) ? (string) $_SESSION['manage_pets_flash'] : '';
$flashType = isset($_SESSION['manage_pets_flash_type']) ? (string) $_SESSION['manage_pets_flash_type'] : '';
unset($_SESSION['manage_pets_flash'], $_SESSION['manage_pets_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'delete_pet') {
        $petId = isset($_POST['pet_id']) ? (int) $_POST['pet_id'] : 0;

        if ($petId <= 0) {
            $_SESSION['manage_pets_flash_type'] = 'error';
            $_SESSION['manage_pets_flash'] = 'Invalid pet selected.';
            redirectTo('manage-pets.php');
        }

        if (deletePetForUser($pdo, $userId, $petId)) {
            $_SESSION['manage_pets_flash_type'] = 'success';
            $_SESSION['manage_pets_flash'] = 'Pet removed successfully.';
        } else {
            $_SESSION['manage_pets_flash_type'] = 'error';
            $_SESSION['manage_pets_flash'] = 'Could not remove that pet right now.';
        }

        redirectTo('manage-pets.php');
    }
}

$pets = fetchPetsForUser($pdo, $userId);
$petCount = count($pets);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pets | Doggie Dorian’s</title>
    <meta name="description" content="Manage your pet profiles at Doggie Dorian’s.">
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
            max-width: 1240px;
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

        .pet-list {
            display: grid;
            gap: 14px;
        }

        .pet-card {
            display: grid;
            gap: 14px;
        }

        .pet-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .pet-title {
            font-size: 1.05rem;
            font-weight: 900;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(198,178,139,0.16);
            border: 1px solid rgba(198,178,139,0.30);
            color: #f3e5c7;
            font-size: .82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .meta-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .meta-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .meta-value {
            font-size: .96rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .detail-copy {
            color: rgba(244,241,234,0.74);
            line-height: 1.65;
        }

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 980px) {
            .hero,
            .stats,
            .meta-grid {
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
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="profile.php">Profile</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Pet Profiles</div>
                <h1>Manage Pets</h1>
                <div class="sub">
                    Keep your pet profiles organized so booking, scheduling, and care notes stay clean across your account.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Pet Profiles</div>
                        <div class="stat-value"><?php echo (int) $petCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Member</div>
                        <div class="stat-value"><?php echo h($memberName); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Unread Notices</div>
                        <div class="stat-value"><?php echo (int) $unreadNotifications; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Quick Actions</div>
                <h2>Keep your booking flow clean</h2>
                <div class="sub">
                    Add a new pet before booking, or head straight to the member booking page to schedule a service.
                </div>

                <div class="cta-row">
                    <a class="btn btn-gold" href="add-pet.php">Add a Pet</a>
                    <a class="btn btn-light" href="book-service.php">Book Service</a>
                    <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                </div>
            </div>
        </section>

        <section class="pet-list">
            <?php if (empty($pets)): ?>
                <div class="card">
                    <div class="empty">
                        You do not have any pet profiles yet. Add your first pet before using <strong>Book Service</strong>.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($pets as $pet): ?>
                    <div class="card pet-card">
                        <div class="pet-top">
                            <div class="pet-title"><?php echo h(isset($pet['pet_name']) ? $pet['pet_name'] : 'Pet'); ?></div>
                            <div class="pill"><?php echo h((isset($pet['size']) && trim((string) $pet['size']) !== '') ? $pet['size'] : 'Profile'); ?></div>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Breed</div>
                                <div class="meta-value"><?php echo h((isset($pet['breed']) && trim((string) $pet['breed']) !== '') ? $pet['breed'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Size</div>
                                <div class="meta-value"><?php echo h((isset($pet['size']) && trim((string) $pet['size']) !== '') ? $pet['size'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Age</div>
                                <div class="meta-value"><?php echo h((isset($pet['age']) && trim((string) $pet['age']) !== '') ? $pet['age'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Added</div>
                                <div class="meta-value"><?php echo h((isset($pet['created_at']) && trim((string) $pet['created_at']) !== '') ? $pet['created_at'] : '—'); ?></div>
                            </div>
                        </div>

                        <?php if (isset($pet['notes']) && trim((string) $pet['notes']) !== ''): ?>
                            <div class="detail-copy">
                                <strong style="color:#f3e5c7;">Notes:</strong>
                                <?php echo h($pet['notes']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="cta-row">
                            <a class="btn btn-light" href="book-service.php">Book Service</a>
                            <a class="btn btn-light" href="add-pet.php">Add Another Pet</a>

                            <form method="post" action="manage-pets.php" onsubmit="return confirm('Remove this pet profile?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_pet">
                                <input type="hidden" name="pet_id" value="<?php echo (int) $pet['internal_id']; ?>">
                                <button type="submit" class="btn btn-light">Remove Pet</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>