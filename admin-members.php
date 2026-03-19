<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/data/config/db.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pickExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }
    return null;
}

function formatDate(?string $date): string
{
    $date = trim((string) $date);

    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y', $timestamp);
}

$fatalError = '';
$members = [];
$search = strtolower(trim((string) ($_GET['search'] ?? '')));

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from data/config/db.php.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found in your database.');
    }

    $columns = getColumns($pdo, 'users');

    $idCol = pickExistingColumn($columns, ['id']);
    $nameCol = pickExistingColumn($columns, ['full_name', 'name']);
    $emailCol = pickExistingColumn($columns, ['email']);
    $phoneCol = pickExistingColumn($columns, ['phone', 'phone_number']);
    $roleCol = pickExistingColumn($columns, ['role']);
    $statusCol = pickExistingColumn($columns, ['status']);
    $createdCol = pickExistingColumn($columns, ['created_at', 'signup_date', 'joined_at']);

    $selectParts = [];
    $selectParts[] = $idCol !== null ? "$idCol AS member_id" : "NULL AS member_id";
    $selectParts[] = $nameCol !== null ? "$nameCol AS member_name" : "NULL AS member_name";
    $selectParts[] = $emailCol !== null ? "$emailCol AS member_email" : "NULL AS member_email";
    $selectParts[] = $phoneCol !== null ? "$phoneCol AS member_phone" : "NULL AS member_phone";
    $selectParts[] = $roleCol !== null ? "$roleCol AS member_role" : "'member' AS member_role";
    $selectParts[] = $statusCol !== null ? "$statusCol AS member_status" : "'unknown' AS member_status";
    $selectParts[] = $createdCol !== null ? "$createdCol AS member_created" : "NULL AS member_created";

    $orderBy = $createdCol !== null ? "$createdCol DESC" : ($idCol !== null ? "$idCol DESC" : "rowid DESC");

    $where = '';
    if ($roleCol !== null) {
        $where = "WHERE LOWER(COALESCE($roleCol, 'member')) != 'admin'";
    }

    $sql = "
        SELECT " . implode(", ", $selectParts) . "
        FROM users
        $where
        ORDER BY $orderBy
        LIMIT 500
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if ($search !== '') {
        $rows = array_values(array_filter($rows, function (array $row) use ($search): bool {
            $fields = [
                strtolower(trim((string) ($row['member_name'] ?? ''))),
                strtolower(trim((string) ($row['member_email'] ?? ''))),
                strtolower(trim((string) ($row['member_phone'] ?? ''))),
                strtolower(trim((string) ($row['member_role'] ?? ''))),
                strtolower(trim((string) ($row['member_status'] ?? ''))),
            ];

            foreach ($fields as $field) {
                if ($field !== '' && str_contains($field, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    $members = $rows;

} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --panel2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --shadow:0 20px 50px rgba(0,0,0,0.35);
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family:Inter, Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(255,255,255,0.05), transparent 24%),
                linear-gradient(180deg, #08080c 0%, #111119 100%);
        }

        .shell{
            display:grid;
            grid-template-columns:280px 1fr;
            min-height:100vh;
        }

        .sidebar{
            border-right:1px solid var(--border);
            background:linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            padding:28px 20px;
        }

        .brand{
            font-size:28px;
            font-weight:800;
            line-height:1.1;
            margin-bottom:10px;
        }

        .brand span{ color:var(--gold); }

        .tag{
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
            margin-bottom:26px;
        }

        .nav a{
            display:block;
            text-decoration:none;
            color:var(--text);
            padding:14px 16px;
            margin-bottom:10px;
            border-radius:16px;
            background:rgba(255,255,255,0.03);
            border:1px solid transparent;
            font-weight:600;
        }

        .nav a:hover,
        .nav a.active{
            border-color:var(--border);
            background:linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
        }

        .main{
            padding:34px;
        }

        .header{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:18px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }

        .header h1{
            margin:0 0 8px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
        }

        .toolbar{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:20px;
            box-shadow:var(--shadow);
            margin-bottom:20px;
        }

        .search-form{
            display:grid;
            grid-template-columns:1fr auto;
            gap:12px;
        }

        .search-form input{
            min-height:48px;
            padding:12px 14px;
            border-radius:14px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.04);
            color:var(--text);
            font:inherit;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border:none;
            cursor:pointer;
            min-height:48px;
            padding:12px 18px;
            border-radius:14px;
            font-weight:800;
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }

        .card{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:22px;
            box-shadow:var(--shadow);
        }

        .card h2{
            margin:0 0 8px;
            font-size:28px;
            letter-spacing:-0.4px;
        }

        .card-sub{
            margin:0 0 18px;
            color:var(--muted);
            font-size:14px;
            line-height:1.6;
        }

        .records{
            display:grid;
            gap:16px;
        }

        .member-link{
            text-decoration:none;
            color:inherit;
            display:block;
        }

        .member-row{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.07);
            border-radius:20px;
            padding:18px;
            transition:0.2s ease;
        }

        .member-link:hover .member-row{
            transform:translateY(-2px);
            border-color:rgba(212,175,55,0.28);
            background:rgba(255,255,255,0.05);
        }

        .member-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }

        .member-name{
            font-size:22px;
            font-weight:800;
            letter-spacing:-0.3px;
            margin-bottom:6px;
        }

        .member-sub{
            color:var(--muted);
            font-size:14px;
        }

        .pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 10px;
            border-radius:999px;
            border:1px solid var(--border);
            color:var(--gold-soft);
            background:rgba(212,175,55,0.08);
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        .details{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:12px;
        }

        .detail{
            border-radius:16px;
            padding:14px;
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
        }

        .detail strong{
            display:block;
            color:var(--gold-soft);
            margin-bottom:6px;
            font-size:13px;
        }

        .detail span{
            color:var(--muted);
            font-size:14px;
            line-height:1.5;
        }

        .empty{
            border:1px dashed rgba(255,255,255,0.14);
            border-radius:24px;
            padding:28px;
            text-align:center;
            color:var(--muted);
            background:rgba(255,255,255,0.03);
        }

        .error-box{
            border:1px solid rgba(255,0,0,0.25);
            background:rgba(255,0,0,0.08);
            padding:16px 18px;
            border-radius:16px;
            color:#ffd1d1;
            white-space:pre-wrap;
            word-break:break-word;
        }

        @media (max-width: 1100px){
            .details{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
        }

        @media (max-width: 900px){
            .shell{
                grid-template-columns:1fr;
            }

            .main{
                padding:20px;
            }
        }

        @media (max-width: 640px){
            .header h1{
                font-size:32px;
            }

            .search-form{
                grid-template-columns:1fr;
            }

            .details{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Doggie <span>Dorian’s</span></div>
        <div class="tag">Premium admin control panel for members, bookings, revenue, and operations.</div>

        <nav class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="admin-members.php" class="active">Members</a>
            <a href="book-walk.php">Preview Public Booking Form</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <?php if ($fatalError !== ''): ?>
            <div class="error-box">
                <strong>Members page error:</strong><br>
                <?php echo h($fatalError); ?>
            </div>
        <?php else: ?>
            <section class="header">
                <div>
                    <h1>Members</h1>
                    <div class="sub">View and search member accounts from one clean admin page.</div>
                </div>
            </section>

            <section class="toolbar">
                <form method="get" class="search-form">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by member name, email, phone, role, or status..."
                        value="<?php echo h((string) ($_GET['search'] ?? '')); ?>"
                    >
                    <button type="submit" class="btn">Search Members</button>
                </form>
            </section>

            <section class="card">
                <h2>Member Directory</h2>
                <p class="card-sub">Click any member to open their full profile page.</p>

                <?php if (empty($members)): ?>
                    <div class="empty">No members were found for your current search.</div>
                <?php else: ?>
                    <div class="records">
                        <?php foreach ($members as $member): ?>
                            <a class="member-link" href="admin-member-view.php?id=<?php echo (int) ($member['member_id'] ?? 0); ?>">
                                <article class="member-row">
                                    <div class="member-head">
                                        <div>
                                            <div class="member-name">
                                                <?php echo h((string) (($member['member_name'] ?? '') !== '' ? $member['member_name'] : 'Unnamed Member')); ?>
                                            </div>
                                            <div class="member-sub">
                                                User ID:
                                                <?php echo h((string) ($member['member_id'] ?? 'N/A')); ?>
                                            </div>
                                        </div>

                                        <span class="pill">
                                            <?php echo h((string) (($member['member_status'] ?? '') !== '' ? $member['member_status'] : 'Status Unknown')); ?>
                                        </span>
                                    </div>

                                    <div class="details">
                                        <div class="detail">
                                            <strong>Email</strong>
                                            <span><?php echo h((string) (($member['member_email'] ?? '') !== '' ? $member['member_email'] : 'N/A')); ?></span>
                                        </div>

                                        <div class="detail">
                                            <strong>Phone</strong>
                                            <span><?php echo h((string) (($member['member_phone'] ?? '') !== '' ? $member['member_phone'] : 'N/A')); ?></span>
                                        </div>

                                        <div class="detail">
                                            <strong>Role</strong>
                                            <span><?php echo h((string) (($member['member_role'] ?? '') !== '' ? $member['member_role'] : 'member')); ?></span>
                                        </div>

                                        <div class="detail">
                                            <strong>Joined</strong>
                                            <span><?php echo formatDate((string) ($member['member_created'] ?? '')); ?></span>
                                        </div>
                                    </div>
                                </article>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>