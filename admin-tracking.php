<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/New_York');

$success = '';
$errors = [];

$allowedStatuses = [
    'Walker Assigned',
    'On The Way',
    'Arrived',
    'Walk Started',
    'Bathroom Break',
    'Walk Completed',
];

$userId = (int) ($_SESSION['user_id'] ?? 0);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$roleRaw = (string) ($_SESSION['role'] ?? '');
$role = strtolower(trim($roleRaw));
$isAdmin = !empty($_SESSION['is_admin']);
$allowedRoles = ['admin', 'superadmin', 'owner'];

$hasAdminAccess = (
    $isAdmin ||
    $adminId > 0 ||
    ($userId > 0 && in_array($role, $allowedRoles, true))
);

if (empty($_SESSION['gps_tracking_admin_csrf']) || !is_string($_SESSION['gps_tracking_admin_csrf'])) {
    $_SESSION['gps_tracking_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['gps_tracking_admin_csrf'];

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function e(mixed $value): string
{
    return h($value);
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($tableName) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function buildSelectFragment(?string $column, string $alias, string $fallbackSql = 'NULL', string $tableAlias = ''): string
{
    if ($column === null) {
        return $fallbackSql . ' AS ' . quotedIdentifier($alias);
    }

    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    return $prefix . quotedIdentifier($column) . ' AS ' . quotedIdentifier($alias);
}

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function fetchOneSafe(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function formatDateTimeValue(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y • g:i A');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function buildFullNameExpression(string $alias, ?string $nameCol, ?string $firstCol, ?string $lastCol): string
{
    if ($nameCol !== null) {
        return "COALESCE(NULLIF({$alias}." . quotedIdentifier($nameCol) . ", ''), '—')";
    }

    $first = $firstCol !== null ? "COALESCE({$alias}." . quotedIdentifier($firstCol) . ", '')" : "''";
    $last = $lastCol !== null ? "COALESCE({$alias}." . quotedIdentifier($lastCol) . ", '')" : "''";

    return "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), '—')";
}

$walksTable = tableExists($pdo, 'walks') ? 'walks' : null;
$walkSessionsTable = tableExists($pdo, 'walk_sessions') ? 'walk_sessions' : null;
$dogsTable = tableExists($pdo, 'dogs') ? 'dogs' : (tableExists($pdo, 'pets') ? 'pets' : null);
$membersTable = tableExists($pdo, 'members') ? 'members' : null;
$usersTable = tableExists($pdo, 'users') ? 'users' : null;

$walkCols = $walksTable !== null ? getTableColumns($pdo, $walksTable) : [];
$sessionCols = $walkSessionsTable !== null ? getTableColumns($pdo, $walkSessionsTable) : [];
$dogCols = $dogsTable !== null ? getTableColumns($pdo, $dogsTable) : [];
$memberCols = $membersTable !== null ? getTableColumns($pdo, $membersTable) : [];
$userCols = $usersTable !== null ? getTableColumns($pdo, $usersTable) : [];

$walkIdCol = firstExistingColumn($walkCols, ['id', 'walk_id']);
$walkDateCol = firstExistingColumn($walkCols, ['walk_date', 'service_date', 'date']);
$walkTimeCol = firstExistingColumn($walkCols, ['walk_time', 'service_time', 'time']);
$durationCol = firstExistingColumn($walkCols, ['duration_minutes', 'duration', 'minutes']);
$walkerNameCol = firstExistingColumn($walkCols, ['walker_name', 'staff_name', 'employee_name']);
$dogIdCol = firstExistingColumn($walkCols, ['dog_id', 'pet_id']);
$memberIdCol = firstExistingColumn($walkCols, ['member_id', 'user_id', 'client_id']);

$sessionIdCol = firstExistingColumn($sessionCols, ['id']);
$sessionWalkRefCol = firstExistingColumn($sessionCols, ['walk_id', 'booking_id']);
$sessionStatusCol = firstExistingColumn($sessionCols, ['session_status', 'status']);
$etaCol = firstExistingColumn($sessionCols, ['eta_minutes']);
$currentLocationCol = firstExistingColumn($sessionCols, ['current_location']);
$lastUpdateCol = firstExistingColumn($sessionCols, ['last_update']);
$bathroomUpdateCol = firstExistingColumn($sessionCols, ['bathroom_update']);
$photoNoteCol = firstExistingColumn($sessionCols, ['photo_note']);
$routeNoteCol = firstExistingColumn($sessionCols, ['route_note']);
$startedAtCol = firstExistingColumn($sessionCols, ['started_at']);
$completedAtCol = firstExistingColumn($sessionCols, ['completed_at']);
$updatedAtCol = firstExistingColumn($sessionCols, ['updated_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasAdminAccess) {
        $errors[] = 'Admin access is required.';
    }

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Session expired. Please refresh and try again.';
    }

    $walkId = (int) ($_POST['walk_id'] ?? 0);
    $sessionStatus = trim((string) ($_POST['session_status'] ?? ''));
    $etaMinutes = trim((string) ($_POST['eta_minutes'] ?? ''));
    $currentLocation = trim((string) ($_POST['current_location'] ?? ''));
    $lastUpdate = trim((string) ($_POST['last_update'] ?? ''));
    $bathroomUpdate = trim((string) ($_POST['bathroom_update'] ?? ''));
    $photoNote = trim((string) ($_POST['photo_note'] ?? ''));
    $routeNote = trim((string) ($_POST['route_note'] ?? ''));

    if ($walkId <= 0) {
        $errors[] = 'Invalid walk selected.';
    }

    if (!in_array($sessionStatus, $allowedStatuses, true)) {
        $errors[] = 'Invalid tracking status.';
    }

    if ($walksTable === null || $walkSessionsTable === null || $walkIdCol === null || $sessionWalkRefCol === null) {
        $errors[] = 'Walk tracking tables are not configured correctly.';
    }

    if (!$errors) {
        $existing = fetchOneSafe(
            $pdo,
            'SELECT * FROM ' . quotedIdentifier($walkSessionsTable) . ' WHERE ' . quotedIdentifier($sessionWalkRefCol) . ' = :walk_id LIMIT 1',
            [':walk_id' => $walkId]
        );

        $normalizedEta = null;
        if ($etaMinutes !== '') {
            $normalizedEta = max(0, (int) $etaMinutes);
        }

        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $sets = [];
            $params = [':walk_id' => $walkId];

            if ($sessionStatusCol !== null) {
                $sets[] = quotedIdentifier($sessionStatusCol) . ' = :session_status';
                $params[':session_status'] = $sessionStatus;
            }
            if ($etaCol !== null) {
                $sets[] = quotedIdentifier($etaCol) . ' = :eta_minutes';
                $params[':eta_minutes'] = $normalizedEta;
            }
            if ($currentLocationCol !== null) {
                $sets[] = quotedIdentifier($currentLocationCol) . ' = :current_location';
                $params[':current_location'] = $currentLocation !== '' ? $currentLocation : null;
            }
            if ($lastUpdateCol !== null) {
                $sets[] = quotedIdentifier($lastUpdateCol) . ' = :last_update';
                $params[':last_update'] = $lastUpdate !== '' ? $lastUpdate : null;
            }
            if ($bathroomUpdateCol !== null) {
                $sets[] = quotedIdentifier($bathroomUpdateCol) . ' = :bathroom_update';
                $params[':bathroom_update'] = $bathroomUpdate !== '' ? $bathroomUpdate : null;
            }
            if ($photoNoteCol !== null) {
                $sets[] = quotedIdentifier($photoNoteCol) . ' = :photo_note';
                $params[':photo_note'] = $photoNote !== '' ? $photoNote : null;
            }
            if ($routeNoteCol !== null) {
                $sets[] = quotedIdentifier($routeNoteCol) . ' = :route_note';
                $params[':route_note'] = $routeNote !== '' ? $routeNote : null;
            }
            if ($startedAtCol !== null && $sessionStatus === 'Walk Started' && empty($existing[$startedAtCol])) {
                $sets[] = quotedIdentifier($startedAtCol) . ' = :started_at';
                $params[':started_at'] = $now;
            }
            if ($completedAtCol !== null && $sessionStatus === 'Walk Completed') {
                $sets[] = quotedIdentifier($completedAtCol) . ' = :completed_at';
                $params[':completed_at'] = $now;
            }
            if ($updatedAtCol !== null) {
                $sets[] = quotedIdentifier($updatedAtCol) . ' = :updated_at';
                $params[':updated_at'] = $now;
            }

            if (!empty($sets)) {
                $update = $pdo->prepare(
                    'UPDATE ' . quotedIdentifier($walkSessionsTable) .
                    ' SET ' . implode(', ', $sets) .
                    ' WHERE ' . quotedIdentifier($sessionWalkRefCol) . ' = :walk_id'
                );

                if (!safeExecute($update, $params)) {
                    $errors[] = 'Could not update tracking session.';
                }
            }
        } else {
            $fields = [];
            $placeholders = [];
            $params = [];

            $fieldMap = [
                $sessionWalkRefCol => $walkId,
                $sessionStatusCol => $sessionStatus,
                $etaCol => $normalizedEta,
                $currentLocationCol => $currentLocation !== '' ? $currentLocation : null,
                $lastUpdateCol => $lastUpdate !== '' ? $lastUpdate : null,
                $bathroomUpdateCol => $bathroomUpdate !== '' ? $bathroomUpdate : null,
                $photoNoteCol => $photoNote !== '' ? $photoNote : null,
                $routeNoteCol => $routeNote !== '' ? $routeNote : null,
                $startedAtCol => $sessionStatus === 'Walk Started' ? $now : null,
                $completedAtCol => $sessionStatus === 'Walk Completed' ? $now : null,
                $updatedAtCol => $now,
            ];

            foreach ($fieldMap as $column => $value) {
                if ($column === null) {
                    continue;
                }

                $fields[] = quotedIdentifier($column);
                $placeholder = ':' . $column;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $value;
            }

            if (empty($fields)) {
                $errors[] = 'No writable tracking session columns were found.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO ' . quotedIdentifier($walkSessionsTable) .
                    ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
                );

                if (!safeExecute($insert, $params)) {
                    $errors[] = 'Could not create tracking session.';
                }
            }
        }

        if (!$errors && $walksTable !== null) {
            $walkStatusCol = firstExistingColumn($walkCols, ['status', 'walk_status', 'booking_status']);
            $walkStartedAtCol = firstExistingColumn($walkCols, ['started_at', 'walk_started_at']);
            $walkCompletedAtCol = firstExistingColumn($walkCols, ['completed_at', 'walk_completed_at']);
            $walkUpdatedAtCol = firstExistingColumn($walkCols, ['updated_at']);

            $walkSets = [];
            $walkParams = [':walk_id' => $walkId];

            if ($walkStatusCol !== null) {
                $mappedWalkStatus = match ($sessionStatus) {
                    'Walker Assigned', 'On The Way', 'Arrived' => 'accepted',
                    'Walk Started', 'Bathroom Break' => 'in_progress',
                    'Walk Completed' => 'completed',
                    default => null,
                };

                if ($mappedWalkStatus !== null) {
                    $walkSets[] = quotedIdentifier($walkStatusCol) . ' = :walk_status';
                    $walkParams[':walk_status'] = $mappedWalkStatus;
                }
            }

            if ($walkStartedAtCol !== null && $sessionStatus === 'Walk Started') {
                $walkSets[] = quotedIdentifier($walkStartedAtCol) . ' = COALESCE(' . quotedIdentifier($walkStartedAtCol) . ', :walk_started_at)';
                $walkParams[':walk_started_at'] = $now;
            }

            if ($walkCompletedAtCol !== null && $sessionStatus === 'Walk Completed') {
                $walkSets[] = quotedIdentifier($walkCompletedAtCol) . ' = :walk_completed_at';
                $walkParams[':walk_completed_at'] = $now;
            }

            if ($walkUpdatedAtCol !== null) {
                $walkSets[] = quotedIdentifier($walkUpdatedAtCol) . ' = :walk_updated_at';
                $walkParams[':walk_updated_at'] = $now;
            }

            if (!empty($walkSets)) {
                $walkUpdate = $pdo->prepare(
                    'UPDATE ' . quotedIdentifier($walksTable) .
                    ' SET ' . implode(', ', $walkSets) .
                    ' WHERE ' . quotedIdentifier($walkIdCol) . ' = :walk_id'
                );
                safeExecute($walkUpdate, $walkParams);
            }
        }

        if (!$errors) {
            $success = 'Tracking session updated successfully at ' . date('g:i:s A');
        }
    }
}

$rows = [];

if ($walksTable !== null && $walkIdCol !== null) {
    $dogJoin = '';
    $dogNameExpr = "'—'";
    $dogNameCol = firstExistingColumn($dogCols, ['dog_name', 'pet_name', 'name']);
    $dogTableIdCol = firstExistingColumn($dogCols, ['id', 'dog_id', 'pet_id']);

    if ($dogsTable !== null && $dogIdCol !== null && $dogTableIdCol !== null && $dogNameCol !== null) {
        $dogJoin = ' LEFT JOIN ' . quotedIdentifier($dogsTable) . ' d ON w.' . quotedIdentifier($dogIdCol) . ' = d.' . quotedIdentifier($dogTableIdCol) . ' ';
        $dogNameExpr = 'COALESCE(NULLIF(d.' . quotedIdentifier($dogNameCol) . ", ''), '—')";
    }

    $memberJoin = '';
    $memberEmailExpr = "'—'";
    $memberUsernameExpr = "'—'";

    if ($memberIdCol !== null && $membersTable !== null) {
        $memberTableIdCol = firstExistingColumn($memberCols, ['id', 'member_id', 'user_id']);
        $memberEmailCol = firstExistingColumn($memberCols, ['email']);
        $memberUsernameCol = firstExistingColumn($memberCols, ['username', 'full_name', 'name', 'member_name']);
        $memberFirstCol = firstExistingColumn($memberCols, ['first_name']);
        $memberLastCol = firstExistingColumn($memberCols, ['last_name']);

        if ($memberTableIdCol !== null) {
            $memberJoin = ' LEFT JOIN ' . quotedIdentifier($membersTable) . ' m ON w.' . quotedIdentifier($memberIdCol) . ' = m.' . quotedIdentifier($memberTableIdCol) . ' ';
            if ($memberEmailCol !== null) {
                $memberEmailExpr = 'COALESCE(NULLIF(m.' . quotedIdentifier($memberEmailCol) . ", ''), '—')";
            }
            $memberUsernameExpr = buildFullNameExpression('m', $memberUsernameCol, $memberFirstCol, $memberLastCol);
        }
    } elseif ($memberIdCol !== null && $usersTable !== null) {
        $userTableIdCol = firstExistingColumn($userCols, ['id', 'user_id']);
        $userEmailCol = firstExistingColumn($userCols, ['email']);
        $userNameCol = firstExistingColumn($userCols, ['username', 'full_name', 'name']);
        $userFirstCol = firstExistingColumn($userCols, ['first_name']);
        $userLastCol = firstExistingColumn($userCols, ['last_name']);

        if ($userTableIdCol !== null) {
            $memberJoin = ' LEFT JOIN ' . quotedIdentifier($usersTable) . ' m ON w.' . quotedIdentifier($memberIdCol) . ' = m.' . quotedIdentifier($userTableIdCol) . ' ';
            if ($userEmailCol !== null) {
                $memberEmailExpr = 'COALESCE(NULLIF(m.' . quotedIdentifier($userEmailCol) . ", ''), '—')";
            }
            $memberUsernameExpr = buildFullNameExpression('m', $userNameCol, $userFirstCol, $userLastCol);
        }
    }

    $sessionJoin = '';
    if ($walkSessionsTable !== null && $sessionWalkRefCol !== null) {
        $sessionJoin = ' LEFT JOIN ' . quotedIdentifier($walkSessionsTable) . ' ws ON ws.' . quotedIdentifier($sessionWalkRefCol) . ' = w.' . quotedIdentifier($walkIdCol) . ' ';
    }

    $sql = "
        SELECT
            " . buildSelectFragment($walkIdCol, 'walk_id', 'NULL', 'w') . ",
            " . buildSelectFragment($walkDateCol, 'walk_date', "''", 'w') . ",
            " . buildSelectFragment($walkTimeCol, 'walk_time', "''", 'w') . ",
            " . buildSelectFragment($durationCol, 'duration_minutes', 'NULL', 'w') . ",
            " . buildSelectFragment($walkerNameCol, 'walker_name', "''", 'w') . ",
            {$dogNameExpr} AS " . quotedIdentifier('dog_name') . ",
            {$memberEmailExpr} AS " . quotedIdentifier('member_email') . ",
            {$memberUsernameExpr} AS " . quotedIdentifier('member_username') . ",
            " . buildSelectFragment($sessionStatusCol, 'session_status', "''", 'ws') . ",
            " . buildSelectFragment($etaCol, 'eta_minutes', 'NULL', 'ws') . ",
            " . buildSelectFragment($currentLocationCol, 'current_location', "''", 'ws') . ",
            " . buildSelectFragment($lastUpdateCol, 'last_update', "''", 'ws') . ",
            " . buildSelectFragment($bathroomUpdateCol, 'bathroom_update', "''", 'ws') . ",
            " . buildSelectFragment($photoNoteCol, 'photo_note', "''", 'ws') . ",
            " . buildSelectFragment($routeNoteCol, 'route_note', "''", 'ws') . "
        FROM " . quotedIdentifier($walksTable) . " w
        {$dogJoin}
        {$memberJoin}
        {$sessionJoin}
        ORDER BY " . buildSelectFragment($walkDateCol, 'walk_date_order', "''", 'w') . ", " . buildSelectFragment($walkTimeCol, 'walk_time_order', "''", 'w') . ", " . buildSelectFragment($walkIdCol, 'walk_id_order', '0', 'w');

    $rows = fetchAllSafe($pdo, $sql);
}

?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/nav.php'; ?>

<style>
.admin-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}
.admin-shell {
  max-width: 1320px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}
.admin-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}
.admin-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}
.admin-hero p {
  margin: 0;
  color: rgba(255,255,255,0.82);
}
.message {
  border-radius: 14px;
  padding: 14px 16px;
}
.message.error {
  background: #fff3f3;
  color: #9b1c1c;
}
.message.success {
  background: #f4fbf2;
  color: #256029;
}
.admin-list {
  display: grid;
  gap: 20px;
}
.admin-card {
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.admin-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}
.admin-box {
  background: #f7f4ee;
  border-radius: 16px;
  padding: 14px 16px;
}
.admin-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}
.assign-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.assign-form .full {
  grid-column: 1 / -1;
}
.assign-form label {
  display: block;
  font-weight: 700;
  margin-bottom: 8px;
}
.assign-form input,
.assign-form select,
.assign-form textarea {
  width: 100%;
  padding: 13px 14px;
  border: 1px solid #ddd;
  border-radius: 14px;
  font-size: 15px;
  font-family: Arial, sans-serif;
}
.assign-form textarea {
  min-height: 100px;
  resize: vertical;
}
.assign-button {
  display: inline-block;
  background: #d4af37;
  color: #111111;
  border: none;
  border-radius: 999px;
  padding: 14px 20px;
  font-weight: 700;
  cursor: pointer;
}
.assign-button:hover {
  opacity: 0.95;
}
.admin-tools {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.admin-tools a {
  display: inline-block;
  background: #ffffff;
  color: #111111;
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
}
.empty-state {
  background: #ffffff;
  border-radius: 24px;
  padding: 26px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.access-state {
  background: #fff3f3;
  color: #9b1c1c;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
@media (max-width: 900px) {
  .admin-grid,
  .assign-form {
    grid-template-columns: 1fr;
  }
  .admin-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="admin-page">
  <div class="admin-shell">

    <section class="admin-hero">
      <h1>GPS Tracking Admin</h1>
      <p>Update the live tracking session status, ETA, location, and walk updates.</p>
      <div class="admin-tools" style="margin-top:18px;">
        <a href="admin-dashboard.php">Back to Dashboard</a>
        <a href="admin-bookings.php">Bookings</a>
        <a href="admin-live-tracking.php">Live Tracking</a>
      </div>
    </section>

    <?php if (!$hasAdminAccess): ?>
      <div class="access-state">
        <strong>Admin access required.</strong>
        <div style="margin-top:8px;">This page is restricted to admin sessions.</div>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="message error">
          <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="message success"><?= e($success) ?></div>
      <?php endif; ?>

      <?php if ($walksTable === null): ?>
        <div class="empty-state">The <strong>walks</strong> table was not found.</div>
      <?php elseif ($walkSessionsTable === null): ?>
        <div class="empty-state">The <strong>walk_sessions</strong> table was not found.</div>
      <?php elseif (empty($rows)): ?>
        <div class="empty-state">No walks were found to manage.</div>
      <?php else: ?>
        <section class="admin-list">
          <?php foreach ($rows as $row): ?>
            <div class="admin-card">
              <div class="admin-grid">
                <div class="admin-box">
                  <strong>Dog</strong>
                  <?= e($row['dog_name'] !== '' ? $row['dog_name'] : '—') ?>
                </div>
                <div class="admin-box">
                  <strong>Member</strong>
                  <?= e(($row['member_username'] ?? '') !== '' ? $row['member_username'] : ($row['member_email'] ?? '—')) ?>
                </div>
                <div class="admin-box">
                  <strong>Walk</strong>
                  <?= e((string) ($row['walk_date'] ?? '')) ?><?= (string) ($row['walk_time'] ?? '') !== '' ? ' at ' . e((string) $row['walk_time']) : '' ?>
                </div>
                <div class="admin-box">
                  <strong>Tracker Link</strong>
                  <a href="live-tracking.php?booking_id=<?= e((string) $row['walk_id']) ?>">Open Live View</a>
                </div>
              </div>

              <form method="post" class="assign-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="walk_id" value="<?= e((string) $row['walk_id']) ?>">

                <div>
                  <label>Session Status</label>
                  <select name="session_status" required>
                    <?php
                      $selectedStatus = (string) ($row['session_status'] ?? '');
                      if ($selectedStatus === '') {
                          $selectedStatus = 'Walker Assigned';
                      }
                    ?>
                    <?php foreach ($allowedStatuses as $status): ?>
                      <option value="<?= e($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>>
                        <?= e($status) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label>ETA Minutes</label>
                  <input type="number" min="0" name="eta_minutes" value="<?= e((string) ($row['eta_minutes'] ?? '')) ?>">
                </div>

                <div>
                  <label>Current Location</label>
                  <input type="text" name="current_location" value="<?= e((string) ($row['current_location'] ?? '')) ?>" placeholder="Upper East Side">
                </div>

                <div>
                  <label>Last Update</label>
                  <input type="text" name="last_update" value="<?= e((string) ($row['last_update'] ?? '')) ?>" placeholder="Walker is on the way">
                </div>

                <div>
                  <label>Bathroom Update</label>
                  <input type="text" name="bathroom_update" value="<?= e((string) ($row['bathroom_update'] ?? '')) ?>" placeholder="Peed once, no poop yet">
                </div>

                <div>
                  <label>Photo Update</label>
                  <input type="text" name="photo_note" value="<?= e((string) ($row['photo_note'] ?? '')) ?>" placeholder="Photo uploaded from the park">
                </div>

                <div class="full">
                  <label>Route Note</label>
                  <textarea name="route_note" placeholder="Route moved through Central Park South"><?= e((string) ($row['route_note'] ?? '')) ?></textarea>
                </div>

                <div class="full">
                  <button type="submit" class="assign-button">Update Tracking Session</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>