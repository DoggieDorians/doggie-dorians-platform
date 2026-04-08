<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url) {
    header('Location: ' . $url);
    exit;
}

function currentUserId() {
    foreach (['user_id','member_id','client_id','id'] as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }
    return 0;
}

$userId = currentUserId();
if ($userId <= 0) {
    redirectTo('login.php');
}

/* ---------- helpers ---------- */

function hasTable(PDO $pdo, $table) {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
    $stmt->execute([':t'=>$table]);
    return (bool)$stmt->fetchColumn();
}

function getCols(PDO $pdo, $table) {
    try {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    } catch (Throwable $e) {
        return [];
    }
}

function col(PDO $pdo, $table, $candidates) {
    $cols = getCols($pdo,$table);
    foreach ($candidates as $c) {
        if (in_array($c,$cols,true)) return $c;
    }
    return null;
}

/* ---------- mark read ---------- */

if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $id = (int)$_GET['read'];

    if (hasTable($pdo,'notifications')) {
        $cols = getCols($pdo,'notifications');

        $readCol = col($pdo,'notifications',['is_read','read','seen']);
        $idCol = col($pdo,'notifications',['id','notification_id']);
        $userCol = col($pdo,'notifications',['user_id','member_id']);

        if ($readCol && $idCol && $userCol) {
            $stmt = $pdo->prepare("UPDATE notifications SET $readCol=1 WHERE $idCol=:id AND $userCol=:uid");
            $stmt->execute([':id'=>$id,':uid'=>$userId]);
        }
    }

    redirectTo('notifications.php');
}

/* ---------- fetch ---------- */

$notifications = [];

if (hasTable($pdo,'notifications')) {
    $cols = getCols($pdo,'notifications');

    $idCol = col($pdo,'notifications',['id','notification_id']);
    $userCol = col($pdo,'notifications',['user_id','member_id']);
    $titleCol = col($pdo,'notifications',['title']);
    $msgCol = col($pdo,'notifications',['message','content','body']);
    $readCol = col($pdo,'notifications',['is_read','read','seen']);
    $dateCol = col($pdo,'notifications',['created_at','date']);

    if ($idCol && $userCol) {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE $userCol=:uid ORDER BY rowid DESC");
        $stmt->execute([':uid'=>$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $notifications[] = [
                'id' => (int)$r[$idCol],
                'title' => $titleCol ? (string)$r[$titleCol] : 'Notification',
                'message' => $msgCol ? (string)$r[$msgCol] : '',
                'read' => $readCol ? (int)$r[$readCol] : 0,
                'date' => $dateCol ? (string)$r[$dateCol] : ''
            ];
        }
    }
}

$unread = 0;
foreach ($notifications as $n) {
    if (!$n['read']) $unread++;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Notifications | Doggie Dorian’s</title>
<style>
body{margin:0;background:#09090d;color:#f4f1ea;font-family:sans-serif}
.page{max-width:900px;margin:auto;padding:20px}
.card{background:#111;padding:18px;border-radius:16px;margin-bottom:12px}
.unread{border:1px solid #c6b28b}
.top{display:flex;justify-content:space-between;margin-bottom:20px}
a{color:white;text-decoration:none}
.btn{padding:6px 10px;background:#333;border-radius:8px;font-size:12px}
.title{font-weight:800}
.msg{color:#ccc;margin-top:6px}
.small{font-size:12px;color:#888;margin-top:6px}
</style>
</head>
<body>

<div class="page">

<div class="top">
<div><strong>Notifications</strong> (<?php echo $unread; ?> unread)</div>
<div>
<a class="btn" href="dashboard.php">Dashboard</a>
<a class="btn" href="book-service.php">Book</a>
</div>
</div>

<?php if (empty($notifications)): ?>
<div class="card">No notifications yet.</div>
<?php else: ?>

<?php foreach ($notifications as $n): ?>
<div class="card <?php echo !$n['read'] ? 'unread':''; ?>">
<div class="title"><?php echo h($n['title']); ?></div>
<div class="msg"><?php echo h($n['message']); ?></div>

<div class="small">
<?php echo h($n['date']); ?>
<?php if (!$n['read']): ?>
 • <a href="?read=<?php echo $n['id']; ?>">Mark as read</a>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>