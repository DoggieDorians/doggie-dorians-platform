<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$dbPath = $dataDir . '/members.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        email TEXT NOT NULL UNIQUE,
        phone TEXT UNIQUE,
        preferred_login TEXT NOT NULL CHECK(preferred_login IN ('username', 'email', 'phone')),
        password_hash TEXT NOT NULL,
        email_verified INTEGER NOT NULL DEFAULT 0,
        verification_token TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS dogs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        dog_name TEXT NOT NULL,
        breed TEXT,
        age TEXT,
        weight TEXT,
        temperament TEXT,
        feeding_instructions TEXT,
        medication_notes TEXT,
        emergency_contact TEXT,
        vet_name TEXT,
        vet_phone TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS walks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        dog_id INTEGER NOT NULL,
        walk_date TEXT NOT NULL,
        walk_time TEXT NOT NULL,
        duration_minutes INTEGER NOT NULL,
        notes TEXT,
        status TEXT NOT NULL DEFAULT 'Requested',
        walker_id INTEGER,
        walker_name TEXT,
        walker_phone TEXT,
        walker_notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
        FOREIGN KEY (dog_id) REFERENCES dogs(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS custom_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        plan_name TEXT NOT NULL,
        walks_15 INTEGER NOT NULL DEFAULT 0,
        walks_20 INTEGER NOT NULL DEFAULT 0,
        walks_30 INTEGER NOT NULL DEFAULT 0,
        walks_45 INTEGER NOT NULL DEFAULT 0,
        walks_60 INTEGER NOT NULL DEFAULT 0,
        daycare_days INTEGER NOT NULL DEFAULT 0,
        boarding_nights INTEGER NOT NULL DEFAULT 0,
        drop_ins INTEGER NOT NULL DEFAULT 0,
        monthly_total REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS walk_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        walk_id INTEGER NOT NULL,
        session_status TEXT NOT NULL DEFAULT 'Not Started',
        eta_minutes INTEGER,
        current_location TEXT,
        current_lat REAL,
        current_lng REAL,
        route_points TEXT,
        last_gps_at TEXT,
        last_update TEXT,
        bathroom_update TEXT,
        photo_note TEXT,
        route_note TEXT,
        started_at TEXT,
        completed_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (walk_id) REFERENCES walks(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS walkers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        phone TEXT,
        password_hash TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if (($col['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

ensureColumn($pdo, 'custom_plans', 'payment_mode', "TEXT NOT NULL DEFAULT 'payg'");
ensureColumn($pdo, 'custom_plans', 'payment_status', "TEXT NOT NULL DEFAULT 'pending'");
ensureColumn($pdo, 'walks', 'walker_id', "INTEGER");
ensureColumn($pdo, 'walks', 'walker_name', "TEXT");
ensureColumn($pdo, 'walks', 'walker_phone', "TEXT");
ensureColumn($pdo, 'walks', 'walker_notes', "TEXT");

ensureColumn($pdo, 'walk_sessions', 'current_lat', "REAL");
ensureColumn($pdo, 'walk_sessions', 'current_lng', "REAL");
ensureColumn($pdo, 'walk_sessions', 'route_points', "TEXT");
ensureColumn($pdo, 'walk_sessions', 'last_gps_at', "TEXT");

$walkerCountStmt = $pdo->query("SELECT COUNT(*) AS total FROM walkers");
$walkerCount = (int)$walkerCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

if ($walkerCount === 0) {
    $seed = $pdo->prepare("
        INSERT INTO walkers (full_name, email, phone, password_hash, is_active)
        VALUES (:full_name, :email, :phone, :password_hash, 1)
    ");

    $seed->execute([
        ':full_name' => 'John Walker',
        ':email' => 'walker@doggiedorians.com',
        ':phone' => '(631) 555-8181',
        ':password_hash' => password_hash('walker123', PASSWORD_DEFAULT)
    ]);
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $path): void {
    header('Location: ' . $path);
    exit;
}

function requireMemberLogin(): void {
    if (empty($_SESSION['member_id'])) {
        redirectTo('login.php');
    }
}

function requireWalkerLogin(): void {
    if (empty($_SESSION['walker_id'])) {
        redirectTo('walker-login.php');
    }
}

function currentMember(PDO $pdo): ?array {
    if (empty($_SESSION['member_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['member_id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    return $member ?: null;
}

function currentWalker(PDO $pdo): ?array {
    if (empty($_SESSION['walker_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM walkers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['walker_id']]);
    $walker = $stmt->fetch(PDO::FETCH_ASSOC);

    return $walker ?: null;
}