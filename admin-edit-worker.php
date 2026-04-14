<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminEditWorkerH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminEditWorkerRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminEditWorkerQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminEditWorkerTableExists(PDO $pdo, string $table): bool
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

function ddAdminEditWorkerGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminEditWorkerTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminEditWorkerQuoteIdentifier($table) . ')');
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

function ddAdminEditWorkerFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminEditWorkerValueFromRow(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null) {
            return $row[$candidate];
        }
    }

    return $default;
}

function ddAdminEditWorkerBuildName(array $row): string
{
    $full = trim((string) ddAdminEditWorkerValueFromRow($row, array(
        'full_name',
        'name',
        'display_name',
        'username',
        'walker_name',
        'worker_name',
    ), ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown';
}

function ddAdminEditWorkerSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
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

function ddAdminEditWorkerCsrfToken(): string
{
    if (empty($_SESSION['admin_edit_worker_csrf']) || !is_string($_SESSION['admin_edit_worker_csrf'])) {
        $_SESSION['admin_edit_worker_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_edit_worker_csrf'];
}

function ddAdminEditWorkerValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_edit_worker_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminEditWorkerDetectSources(PDO $pdo): array
{
    $sources = array();

    foreach (array('users', 'walkers', 'workers') as $table) {
        if (!ddAdminEditWorkerTableExists($pdo, $table)) {
            continue;
        }

        $columns = ddAdminEditWorkerGetColumns($pdo, $table);
        $idColumn = ddAdminEditWorkerFirstExistingColumn($columns, array('id', 'user_id', 'walker_id', 'worker_id'));

        if ($idColumn === null) {
            continue;
        }

        $sources[] = array(
            'table' => $table,
            'columns' => $columns,
            'id_column' => $idColumn,
            'role_column' => ddAdminEditWorkerFirstExistingColumn($columns, array('role', 'user_role', 'account_role', 'account_type')),
            'email_column' => ddAdminEditWorkerFirstExistingColumn($columns, array('email')),
        );
    }

    return $sources;
}

function ddAdminEditWorkerLoadRecordFromTable(PDO $pdo, string $table, int $workerId, array $sources): ?array
{
    foreach ($sources as $source) {
        if ((string) $source['table'] !== $table) {
            continue;
        }

        $row = ddAdminEditWorkerSafeFetchOne(
            $pdo,
            'SELECT * FROM ' . ddAdminEditWorkerQuoteIdentifier((string) $source['table']) .
            ' WHERE ' . ddAdminEditWorkerQuoteIdentifier((string) $source['id_column']) . ' = :id LIMIT 1',
            array(':id' => $workerId)
        );

        if (!is_array($row) || empty($row)) {
            return null;
        }

        if ($table === 'users') {
            $roleColumn = $source['role_column'] ?? null;
            if (is_string($roleColumn) && $roleColumn !== '') {
                $role = strtolower(trim((string) ($row[$roleColumn] ?? '')));
                if (!in_array($role, array('walker', 'worker', 'staff', 'employee'), true)) {
                    return null;
                }
            }
        }

        $source['row'] = $row;
        return $source;
    }

    return null;
}

function ddAdminEditWorkerLoadRecord(PDO $pdo, int $workerId, array $sources, string $preferredSource = ''): ?array
{
    $validSources = array('users', 'walkers', 'workers');

    if ($preferredSource !== '' && in_array($preferredSource, $validSources, true)) {
        $preferred = ddAdminEditWorkerLoadRecordFromTable($pdo, $preferredSource, $workerId, $sources);
        if ($preferred !== null) {
            return $preferred;
        }
    }

    foreach ($validSources as $table) {
        if ($table === $preferredSource) {
            continue;
        }

        $loaded = ddAdminEditWorkerLoadRecordFromTable($pdo, $table, $workerId, $sources);
        if ($loaded !== null) {
            return $loaded;
        }
    }

    return null;
}

$sources = ddAdminEditWorkerDetectSources($pdo);
if (empty($sources)) {
    exit('No supported worker tables were found.');
}

$workerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($workerId <= 0) {
    $workerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : 0;
}
if ($workerId <= 0) {
    $workerId = isset($_POST['worker_id']) ? (int) $_POST['worker_id'] : 0;
}

$workerSource = strtolower(trim((string) ($_GET['source'] ?? $_POST['source'] ?? '')));
if (!in_array($workerSource, array('users', 'walkers', 'workers'), true)) {
    $workerSource = '';
}

if ($workerId <= 0) {
    ddAdminEditWorkerRedirect('admin-walker-management.php');
}

$loaded = ddAdminEditWorkerLoadRecord($pdo, $workerId, $sources, $workerSource);
if ($loaded === null) {
    ddAdminEditWorkerRedirect('admin-walker-management.php');
}

$workerTable = (string) $loaded['table'];
$userColumns = (array) $loaded['columns'];
$userIdCol = (string) $loaded['id_column'];
$roleCol = $loaded['role_column'];
$worker = (array) $loaded['row'];

$resolvedWorkerSource = $workerTable;
$sourceParam = urlencode($resolvedWorkerSource);

$error = '';

$currentFullName = trim((string) ddAdminEditWorkerValueFromRow($worker, array('full_name', 'name'), ''));
$currentFirstName = trim((string) ddAdminEditWorkerValueFromRow($worker, array('first_name'), ''));
$currentLastName = trim((string) ddAdminEditWorkerValueFromRow($worker, array('last_name'), ''));
$currentUsername = trim((string) ddAdminEditWorkerValueFromRow($worker, array('username'), ''));
$currentEmail = trim((string) ddAdminEditWorkerValueFromRow($worker, array('email'), ''));
$currentPhone = trim((string) ddAdminEditWorkerValueFromRow($worker, array('phone', 'phone_number', 'mobile'), ''));
$currentRole = strtolower(trim((string) ddAdminEditWorkerValueFromRow($worker, array('role', 'user_role', 'account_role', 'account_type'), 'walker')));
$currentStatus = strtolower(trim((string) ddAdminEditWorkerValueFromRow($worker, array('status', 'account_status', 'worker_status'), '')));
$currentAvailability = trim((string) ddAdminEditWorkerValueFromRow($worker, array('availability', 'worker_availability', 'schedule'), ''));
$currentBio = trim((string) ddAdminEditWorkerValueFromRow($worker, array('bio', 'about', 'about_me', 'notes', 'worker_bio'), ''));

if ($currentStatus === '') {
    $activeGuess = ddAdminEditWorkerValueFromRow($worker, array('is_active', 'active', 'enabled'), null);
    if ($activeGuess !== null) {
        $currentStatus = ((int) $activeGuess === 1) ? 'active' : 'disabled';
    } elseif (array_key_exists('disabled', $worker)) {
        $currentStatus = ((int) $worker['disabled'] === 1) ? 'disabled' : 'active';
    } else {
        $currentStatus = 'active';
    }
}

$form = array(
    'full_name' => $currentFullName,
    'first_name' => $currentFirstName,
    'last_name' => $currentLastName,
    'username' => $currentUsername,
    'email' => $currentEmail,
    'phone' => $currentPhone,
    'role' => in_array($currentRole, array('walker', 'worker', 'staff', 'employee'), true) ? $currentRole : 'walker',
    'status' => in_array($currentStatus, array('active', 'disabled'), true) ? $currentStatus : 'active',
    'availability' => $currentAvailability,
    'bio' => $currentBio,
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['role'] = strtolower(trim((string) ($_POST['role'] ?? 'walker')));
    $form['status'] = strtolower(trim((string) ($_POST['status'] ?? 'active')));
    $form['availability'] = trim((string) ($_POST['availability'] ?? ''));
    $form['bio'] = trim((string) ($_POST['bio'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!ddAdminEditWorkerValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } else {
        $allowedRoles = array('walker', 'worker', 'staff', 'employee');
        if (!in_array($form['role'], $allowedRoles, true)) {
            $form['role'] = 'walker';
        }

        if (!in_array($form['status'], array('active', 'disabled'), true)) {
            $form['status'] = 'active';
        }

        $displayName = $form['full_name'] !== ''
            ? $form['full_name']
            : trim($form['first_name'] . ' ' . $form['last_name']);

        if ($displayName === '' && $form['username'] === '') {
            $error = 'Please enter a worker name or username.';
        } elseif ($form['email'] === '') {
            $error = 'Please enter an email address.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $emailColumn = ddAdminEditWorkerFirstExistingColumn($userColumns, array('email'));
                if ($emailColumn !== null) {
                    $emailStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM ' . ddAdminEditWorkerQuoteIdentifier($workerTable) .
                        ' WHERE LOWER(' . ddAdminEditWorkerQuoteIdentifier($emailColumn) . ') = LOWER(:email)' .
                        ' AND ' . ddAdminEditWorkerQuoteIdentifier($userIdCol) . ' != :id'
                    );
                    $emailStmt->execute(array(
                        ':email' => $form['email'],
                        ':id' => $workerId,
                    ));

                    if ((int) $emailStmt->fetchColumn() > 0) {
                        $error = 'That email is already in use by another account.';
                    }
                }

                if ($error === '') {
                    $updates = array();
                    $params = array(':id' => $workerId);

                    if (in_array('full_name', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('full_name') . ' = :full_name';
                        $params[':full_name'] = $displayName;
                    } elseif (in_array('name', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('name') . ' = :name';
                        $params[':name'] = $displayName;
                    }

                    if (in_array('first_name', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('first_name') . ' = :first_name';
                        $params[':first_name'] = $form['first_name'];
                    }

                    if (in_array('last_name', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('last_name') . ' = :last_name';
                        $params[':last_name'] = $form['last_name'];
                    }

                    if (in_array('username', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('username') . ' = :username';
                        $params[':username'] = $form['username'] !== '' ? $form['username'] : strtolower((string) preg_replace('/\s+/', '', $displayName));
                    }

                    if ($emailColumn !== null) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier($emailColumn) . ' = :email';
                        $params[':email'] = $form['email'];
                    }

                    if ($roleCol !== null) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier((string) $roleCol) . ' = :role';
                        $params[':role'] = $form['role'];
                    }

                    if (in_array('phone', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('phone') . ' = :phone';
                        $params[':phone'] = $form['phone'];
                    } elseif (in_array('phone_number', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('phone_number') . ' = :phone_number';
                        $params[':phone_number'] = $form['phone'];
                    } elseif (in_array('mobile', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('mobile') . ' = :mobile';
                        $params[':mobile'] = $form['phone'];
                    }

                    if (in_array('status', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('status') . ' = :status';
                        $params[':status'] = $form['status'];
                    } elseif (in_array('account_status', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('account_status') . ' = :account_status';
                        $params[':account_status'] = $form['status'];
                    } elseif (in_array('worker_status', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('worker_status') . ' = :worker_status';
                        $params[':worker_status'] = $form['status'];
                    }

                    if (in_array('is_active', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('is_active') . ' = :is_active';
                        $params[':is_active'] = $form['status'] === 'active' ? 1 : 0;
                    } elseif (in_array('active', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('active') . ' = :active';
                        $params[':active'] = $form['status'] === 'active' ? 1 : 0;
                    } elseif (in_array('enabled', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('enabled') . ' = :enabled';
                        $params[':enabled'] = $form['status'] === 'active' ? 1 : 0;
                    } elseif (in_array('disabled', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('disabled') . ' = :disabled';
                        $params[':disabled'] = $form['status'] === 'active' ? 0 : 1;
                    }

                    if (in_array('availability', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('availability') . ' = :availability';
                        $params[':availability'] = $form['availability'];
                    } elseif (in_array('worker_availability', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('worker_availability') . ' = :worker_availability';
                        $params[':worker_availability'] = $form['availability'];
                    } elseif (in_array('schedule', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('schedule') . ' = :schedule';
                        $params[':schedule'] = $form['availability'];
                    }

                    if (in_array('bio', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('bio') . ' = :bio';
                        $params[':bio'] = $form['bio'];
                    } elseif (in_array('about', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('about') . ' = :about';
                        $params[':about'] = $form['bio'];
                    } elseif (in_array('about_me', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('about_me') . ' = :about_me';
                        $params[':about_me'] = $form['bio'];
                    } elseif (in_array('notes', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('notes') . ' = :notes';
                        $params[':notes'] = $form['bio'];
                    } elseif (in_array('worker_bio', $userColumns, true)) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier('worker_bio') . ' = :worker_bio';
                        $params[':worker_bio'] = $form['bio'];
                    }

                    if ($password !== '') {
                        if (in_array('password_hash', $userColumns, true)) {
                            $updates[] = ddAdminEditWorkerQuoteIdentifier('password_hash') . ' = :password_hash';
                            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                        } elseif (in_array('password', $userColumns, true)) {
                            $updates[] = ddAdminEditWorkerQuoteIdentifier('password') . ' = :password';
                            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                        }
                    }

                    $updatedByColumn = ddAdminEditWorkerFirstExistingColumn($userColumns, array('updated_by', 'status_updated_by'));
                    $updatedAtColumn = ddAdminEditWorkerFirstExistingColumn($userColumns, array('updated_at', 'status_updated_at'));

                    if ($updatedByColumn !== null) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier($updatedByColumn) . ' = :updated_by';
                        $params[':updated_by'] = 'admin';
                    }

                    if ($updatedAtColumn !== null) {
                        $updates[] = ddAdminEditWorkerQuoteIdentifier($updatedAtColumn) . ' = CURRENT_TIMESTAMP';
                    }

                    if ($updates === array()) {
                        $error = 'No editable columns were found in the worker table.';
                    } else {
                        $sql = 'UPDATE ' . ddAdminEditWorkerQuoteIdentifier($workerTable)
                            . ' SET ' . implode(', ', $updates)
                            . ' WHERE ' . ddAdminEditWorkerQuoteIdentifier($userIdCol) . ' = :id';

                        $stmt = $pdo->prepare($sql);

                        foreach ($params as $placeholder => $value) {
                            if (is_int($value)) {
                                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                            } elseif ($value === null) {
                                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                            } else {
                                $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                            }
                        }

                        $stmt->execute();

                        ddAdminEditWorkerRedirect('admin-worker-view.php?id=' . $workerId . '&source=' . urlencode($resolvedWorkerSource));
                    }
                }
            } catch (Throwable $e) {
                $error = 'Could not update worker.';
            }
        }
    }
}

$displayWorkerName = ddAdminEditWorkerBuildName($worker);
$csrfToken = ddAdminEditWorkerCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Worker | Doggie Dorian’s</title>
    <meta name="description" content="Admin edit worker page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --red: #ef4444;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1120px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 22%),
                linear-gradient(180deg, #07101d 0%, #0b1220 50%, #0f172a 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.94rem;
        }

        .hero, .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
            max-width: 820px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border: 1px solid rgba(239, 68, 68, 0.20);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.84rem;
            font-weight: 800;
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        input, select, textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.09);
            background: rgba(255,255,255,0.05);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #0f172a;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.08);
            color: var(--text);
        }

        @media (max-width: 760px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.65rem;
            }

            .page {
                padding: 20px 12px 60px;
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
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-worker-view.php?id=<?php echo (int) $workerId; ?>&source=<?php echo $sourceParam; ?>">Worker View</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Edit Worker</h1>
            <div class="sub">
                Update this worker account’s profile, role, contact details, availability, notes, and password.
                <br><br>
                Editing: <strong><?php echo ddAdminEditWorkerH($displayWorkerName); ?></strong>
                <br>
                Source: <strong><?php echo ddAdminEditWorkerH($resolvedWorkerSource); ?></strong>
            </div>
        </section>

        <section class="panel">
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo ddAdminEditWorkerH($error); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo ddAdminEditWorkerH($csrfToken); ?>">
                <input type="hidden" name="worker_id" value="<?php echo (int) $workerId; ?>">
                <input type="hidden" name="source" value="<?php echo ddAdminEditWorkerH($resolvedWorkerSource); ?>">

                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" type="text" value="<?php echo ddAdminEditWorkerH($form['full_name']); ?>" placeholder="Worker full name">
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" value="<?php echo ddAdminEditWorkerH($form['username']); ?>" placeholder="worker username">
                    </div>

                    <div class="field">
                        <label for="first_name">First Name</label>
                        <input id="first_name" name="first_name" type="text" value="<?php echo ddAdminEditWorkerH($form['first_name']); ?>" placeholder="First name">
                    </div>

                    <div class="field">
                        <label for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" type="text" value="<?php echo ddAdminEditWorkerH($form['last_name']); ?>" placeholder="Last name">
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?php echo ddAdminEditWorkerH($form['email']); ?>" placeholder="worker@email.com" required>
                    </div>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="text" value="<?php echo ddAdminEditWorkerH($form['phone']); ?>" placeholder="Phone number">
                    </div>

                    <div class="field">
                        <label for="password">New Password</label>
                        <input id="password" name="password" type="password" placeholder="Leave blank to keep current password">
                    </div>

                    <div class="field">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="walker" <?php echo $form['role'] === 'walker' ? 'selected' : ''; ?>>Walker</option>
                            <option value="worker" <?php echo $form['role'] === 'worker' ? 'selected' : ''; ?>>Worker</option>
                            <option value="staff" <?php echo $form['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="employee" <?php echo $form['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="disabled" <?php echo $form['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label for="availability">Availability</label>
                        <input id="availability" name="availability" type="text" value="<?php echo ddAdminEditWorkerH($form['availability']); ?>" placeholder="Example: Mon-Fri mornings, weekends flexible">
                    </div>

                    <div class="field full">
                        <label for="bio">Bio / Notes</label>
                        <textarea id="bio" name="bio" placeholder="Worker notes, specialties, experience, or admin notes..."><?php echo ddAdminEditWorkerH($form['bio']); ?></textarea>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Save Changes</button>
                    <a class="btn btn-secondary" href="admin-worker-view.php?id=<?php echo (int) $workerId; ?>&source=<?php echo $sourceParam; ?>">Back to Worker View</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>