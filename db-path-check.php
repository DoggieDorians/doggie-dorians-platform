<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "__DIR__: " . __DIR__ . PHP_EOL;
echo "DOGGIEDORIANS_DB_PATH: " . (getenv('DOGGIEDORIANS_DB_PATH') ?: '[empty]') . PHP_EOL;

try {
    echo "resolvedDatabasePath: " . resolveDatabasePath() . PHP_EOL;
} catch (Throwable $e) {
    echo "resolvedDatabasePath ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;
echo "PRAGMA database_list:" . PHP_EOL;

try {
    $stmt = $pdo->query('PRAGMA database_list;');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    print_r($rows);
} catch (Throwable $e) {
    echo "database_list ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;
echo "Patricia in users:" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone
        FROM users
        WHERE lower(email)=lower(:email)
           OR lower(COALESCE(full_name, '')) LIKE '%patricia%'
           OR lower(COALESCE(full_name, '')) LIKE '%vliet%'
    ");
    $stmt->execute([':email' => 'patricia.vandervliet@live.nl']);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo "users ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;
echo "Patricia in members:" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT id, username, email, phone, preferred_login, email_verified
        FROM members
        WHERE lower(email)=lower(:email)
           OR lower(COALESCE(username, '')) LIKE '%patricia%'
           OR lower(COALESCE(username, '')) LIKE '%vliet%'
    ");
    $stmt->execute([':email' => 'patricia.vandervliet@live.nl']);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo "members ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;
echo "member_memberships schema:" . PHP_EOL;

try {
    $stmt = $pdo->query('PRAGMA table_info(member_memberships);');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    print_r($rows);
} catch (Throwable $e) {
    echo "member_memberships ERROR: " . $e->getMessage() . PHP_EOL;
}