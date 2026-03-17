<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin-login.php');
    exit;
}

/* =========================
   HELPERS
========================= */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusBadge(string $status): string
{
    $statusKey = strtolower(trim($status));

    $map = [
        'requested' => ['bg' => 'rgba(212,175,55,0.16)', 'text' => '#f1d67a', 'border' => 'rgba(212,175,55,0.28)'],
        'pending'   => ['bg' => 'rgba(212,175,55,0.16)', 'text' => '#f1d67a', 'border' => 'rgba(212,175,55,0.28)'],
        'scheduled' => ['bg' => 'rgba(84,189,122,0.16)', 'text' => '#9be0b1', 'border' => 'rgba(84,189,122,0.28)'],
        'completed' => ['bg' => 'rgba(74,144,226,0.16)', 'text' => '#9fc8ff', 'border' => 'rgba(74,144,226,0.28)'],
        'cancelled' => ['bg' => 'rgba(229,57,53,0.16)', 'text' => '#ff9e9b', 'border' => 'rgba(229,57,53,0.28)'],
        'new'       => ['bg' => 'rgba(255,255,255,0.10)', 'text' => '#d9d9d9', 'border' => 'rgba(255,255,255,0.18)'],
    ];

    $style = $map[$statusKey] ?? $map['new'];

    return '<span style="
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:8px 12px;
        border-radius:999px;
        font-size:12px;
        font-weight:800;
        letter-spacing:1px;
        text-transform:uppercase;
        background:' . $style['bg'] . ';
        color:' . $style['text'] . ';
        border:1px solid ' . $style['border'] . ';
    ">' . h($status) . '</span>';
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($columns as $col) {
        if (($col['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

/* =========================
   HANDLE STATUS UPDATES
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = trim($_POST['type'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    $allowedStatuses = ['Requested', 'Pending', 'Scheduled', 'Completed', 'Cancelled'];

    if ($id <= 0 || !in_array($status, $allowedStatuses, true)) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Invalid update request.'
        ];
        header('Location: admin-bookings.php');
        exit;
    }

    try {
        if ($type === 'walk') {
            $stmt = $pdo->prepare("UPDATE walks SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Member walk updated successfully.'
            ];
        } elseif ($type === 'non_member') {
            if (!columnExists($pdo, 'non_member_bookings', 'status')) {
                throw new PDOException('The non_member_bookings table does not have a status column.');
            }

            $stmt = $pdo->prepare("UPDATE non_member_bookings SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Non-member booking updated successfully.'
            ];
        } else {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Unknown booking type.'
            ];
        }
    } catch (PDOException $e) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Update failed: ' . $e->getMessage()
        ];
    }

    header('Location: admin-bookings.php');
    exit;
}

/* =========================
   FETCH DATA
========================= */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$walks = [];
$nonMembers = [];
$fatalError = '';

try {
    if (tableExists($pdo, 'walks')) {
        $walks = $pdo->query("SELECT * FROM walks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (tableExists($pdo, 'non_member_bookings')) {
        $nonMembers = $pdo->query("SELECT * FROM non_member_bookings ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $fatalError = $e->getMessage();
}

/* =========================
   SUMMARY COUNTS
========================= */
$memberWalkCount = count($walks);
$nonMemberCount = count($nonMembers);

$requestedCount = 0;
$scheduledCount = 0;
$completedCount = 0;
$cancelledCount = 0;

foreach ($walks as $row) {
    $status = strtolower($row['status'] ?? '');
    if ($status === 'requested' || $status === 'pending') $requestedCount++;
    if ($status === 'scheduled') $scheduledCount++;
    if ($status === 'completed') $completedCount++;
    if ($status === 'cancelled') $cancelledCount++;
}

foreach ($nonMembers as $row) {
    $status = strtolower($row['status'] ?? '');
    if ($status === 'requested' || $status === 'pending' || $status === 'new') $requestedCount++;
    if ($status === 'scheduled') $scheduledCount++;
    if ($status === 'completed') $completedCount++;
    if ($status === 'cancelled') $cancelledCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bookings | Doggie Dorian's</title>
    <style>
        * { box-sizing: border-box; }

        :root{
            --bg:#0a0a0f;
            --bg-soft:#111119;
            --panel:rgba(255,255,255,0.06);
            --panel-2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.20);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --shadow:0 24px 60px rgba(0,0,0,0.35);
            --success-bg:rgba(84,189,122,0.14);
            --success-border:rgba(84,189,122,0.28);
            --success-text:#ccefd7;
            --error-bg:rgba(229,57,53,0.14);
            --error-border:rgba(229,57,53,0.28);
            --error-text:#ffd4d2;
        }

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
            position:sticky;
            top:0;
            height:100vh;
            backdrop-filter:blur(10px);
        }

        .brand{
            font-size:28px;
            font-weight:800;
            line-height:1.1;
            margin-bottom:10px;
            letter-spacing:-0.5px;
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
            transition:.2s ease;
        }

        .nav a:hover,
        .nav a.active{
            border-color:var(--border);
            background:linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
            transform:translateY(-1px);
        }

        .main{
            padding:34px;
        }

        .hero{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:20px;
            flex-wrap:wrap;
            margin-bottom:24px;
        }

        .hero h1{
            margin:0 0 10px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .hero p{
            margin:0;
            color:var(--muted);
            font-size:15px;
            max-width:720px;
        }

        .hero-actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border:none;
            cursor:pointer;
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            padding:14px 18px;
            border-radius:14px;
            font-weight:800;
            box-shadow:var(--shadow);
        }

        .btn.secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
            box-shadow:none;
        }

        .flash{
            margin-bottom:20px;
            padding:14px 16px;
            border-radius:16px;
            font-weight:700;
            line-height:1.5;
        }

        .flash.success{
            background:var(--success-bg);
            border:1px solid var(--success-border);
            color:var(--success-text);
        }

        .flash.error{
            background:var(--error-bg);
            border:1px solid var(--error-border);
            color:var(--error-text);
        }

        .stats{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:18px;
            margin-bottom:28px;
        }

        .stat-card,
        .section-card,
        .booking-card{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            box-shadow:var(--shadow);
            backdrop-filter:blur(10px);
        }

        .stat-card{
            padding:22px;
        }

        .stat-label{
            color:var(--muted);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1.2px;
            margin-bottom:10px;
            font-weight:700;
        }

        .stat-value{
            font-size:34px;
            font-weight:800;
            letter-spacing:-1px;
            margin-bottom:8px;
        }

        .stat-sub{
            color:var(--gold-soft);
            font-size:13px;
        }

        .section-card{
            padding:24px;
            margin-bottom:24px;
        }

        .section-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .section-head h2{
            margin:0;
            font-size:26px;
            letter-spacing:-0.4px;
        }

        .section-head p{
            margin:4px 0 0;
            color:var(--muted);
            font-size:14px;
        }

        .count-pill{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:48px;
            padding:10px 14px;
            border-radius:999px;
            background:rgba(212,175,55,0.10);
            border:1px solid var(--border);
            color:var(--gold-soft);
            font-size:13px;
            font-weight:800;
            letter-spacing:1px;
            text-transform:uppercase;
        }

        .booking-grid{
            display:grid;
            gap:18px;
        }

        .booking-card{
            overflow:hidden;
        }

        .booking-top{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:18px;
            padding:22px 22px 16px;
            border-bottom:1px solid rgba(255,255,255,0.06);
            flex-wrap:wrap;
        }

        .booking-title{
            margin:0 0 8px;
            font-size:22px;
            letter-spacing:-0.3px;
        }

        .booking-sub{
            color:var(--muted);
            font-size:14px;
            line-height:1.6;
        }

        .booking-body{
            padding:22px;
            display:grid;
            grid-template-columns:1.2fr .8fr;
            gap:18px;
        }

        .detail-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:14px;
        }

        .detail{
            padding:14px;
            border-radius:18px;
            background:var(--panel-2);
            border:1px solid rgba(255,255,255,0.06);
        }

        .detail-label{
            color:var(--muted);
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:1px;
            font-weight:700;
            margin-bottom:8px;
        }

        .detail-value{
            font-size:15px;
            line-height:1.5;
            word-break:break-word;
        }

        .update-box{
            padding:18px;
            border-radius:20px;
            background:var(--panel-2);
            border:1px solid rgba(255,255,255,0.06);
            height:fit-content;
        }

        .update-box h3{
            margin:0 0 14px;
            font-size:18px;
        }

        .update-box form{
            display:grid;
            gap:12px;
        }

        .update-box select{
            width:100%;
            padding:14px 14px;
            border-radius:14px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.05);
            color:var(--text);
            outline:none;
        }

        .empty{
            padding:20px;
            border-radius:18px;
            background:var(--panel-2);
            border:1px solid rgba(255,255,255,0.06);
            color:var(--muted);
            text-align:center;
        }

        .error-box{
            border:1px solid rgba(255,0,0,0.25);
            background:rgba(255,0,0,0.08);
            padding:16px 18px;
            border-radius:16px;
            color:#ffd1d1;
        }

        @media (max-width: 1180px){
            .stats{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
            .booking-body{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 900px){
            .shell{
                grid-template-columns:1fr;
            }
            .sidebar{
                position:relative;
                height:auto;
                border-right:none;
                border-bottom:1px solid var(--border);
            }
            .main{
                padding:20px;
            }
        }

        @media (max-width: 700px){
            .stats{
                grid-template-columns:1fr;
            }
            .detail-grid{
                grid-template-columns:1fr;
            }
            .hero h1{
                font-size:32px;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Doggie <span>Dorian’s</span></div>
        <div class="tag">Luxury booking command center for member walks and non-member services.</div>

        <nav class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php" class="active">Booking Management</a>
            <a href="admin-revenue.php">Revenue Dashboard</a>
            <a href="memberships.php">Memberships</a>
            <a href="non-member-booking.php">New Non-Member Booking</a>
            <a href="admin-logout.php">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <section class="hero">
            <div>
                <h1>Booking Management</h1>
                <p>Review, organize, and update all active service requests through one elevated admin experience.</p>
            </div>

            <div class="hero-actions">
                <a class="btn secondary" href="admin-dashboard.php">Back to Dashboard</a>
                <a class="btn" href="non-member-booking.php">Create Booking</a>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash <?php echo h($flash['type'] ?? 'success'); ?>">
                <?php echo h($flash['message'] ?? 'Update complete.'); ?>
            </div>
        <?php endif; ?>

        <?php if ($fatalError !== ''): ?>
            <div class="error-box"><?php echo h($fatalError); ?></div>
        <?php else: ?>

            <section class="stats">
                <div class="stat-card">
                    <div class="stat-label">Member Walks</div>
                    <div class="stat-value"><?php echo number_format($memberWalkCount); ?></div>
                    <div class="stat-sub">Operational walk records</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Non-Member Bookings</div>
                    <div class="stat-value"><?php echo number_format($nonMemberCount); ?></div>
                    <div class="stat-sub">Standalone customer bookings</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Awaiting Review</div>
                    <div class="stat-value"><?php echo number_format($requestedCount); ?></div>
                    <div class="stat-sub">Requested, pending, or new</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Scheduled / Completed</div>
                    <div class="stat-value"><?php echo number_format($scheduledCount + $completedCount); ?></div>
                    <div class="stat-sub">Active and fulfilled services</div>
                </div>
            </section>

            <section class="section-card">
                <div class="section-head">
                    <div>
                        <h2>Member Walks</h2>
                        <p>Walk requests synced into the admin operating system.</p>
                    </div>
                    <div class="count-pill"><?php echo number_format($memberWalkCount); ?></div>
                </div>

                <?php if (empty($walks)): ?>
                    <div class="empty">No member walks found yet.</div>
                <?php else: ?>
                    <div class="booking-grid">
                        <?php foreach ($walks as $w): ?>
                            <article class="booking-card">
                                <div class="booking-top">
                                    <div>
                                        <h3 class="booking-title">Member Walk #<?php echo (int)$w['id']; ?></h3>
                                        <div class="booking-sub">
                                            Premium member walk request ready for operational handling.
                                        </div>
                                    </div>
                                    <div>
                                        <?php echo statusBadge((string)($w['status'] ?? 'Requested')); ?>
                                    </div>
                                </div>

                                <div class="booking-body">
                                    <div class="detail-grid">
                                        <div class="detail">
                                            <div class="detail-label">Walk Date</div>
                                            <div class="detail-value"><?php echo h($w['walk_date'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Walk Time</div>
                                            <div class="detail-value"><?php echo h($w['walk_time'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Duration</div>
                                            <div class="detail-value"><?php echo h((string)($w['duration_minutes'] ?? '—')); ?> min</div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Price</div>
                                            <div class="detail-value">$<?php echo number_format((float)($w['price'] ?? 0), 2); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Member ID</div>
                                            <div class="detail-value"><?php echo h((string)($w['member_id'] ?? '—')); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Dog ID</div>
                                            <div class="detail-value"><?php echo h((string)($w['dog_id'] ?? '—')); ?></div>
                                        </div>

                                        <div class="detail" style="grid-column:1 / -1;">
                                            <div class="detail-label">Notes</div>
                                            <div class="detail-value"><?php echo nl2br(h($w['notes'] ?? '—')); ?></div>
                                        </div>
                                    </div>

                                    <div class="update-box">
                                        <h3>Update Status</h3>
                                        <form method="POST" action="">
                                            <input type="hidden" name="type" value="walk">
                                            <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">

                                            <select name="status">
                                                <?php foreach (['Requested','Pending','Scheduled','Completed','Cancelled'] as $s): ?>
                                                    <option value="<?php echo h($s); ?>" <?php echo (($w['status'] ?? '') === $s) ? 'selected' : ''; ?>>
                                                        <?php echo h($s); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button class="btn" type="submit">Save Update</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="section-card">
                <div class="section-head">
                    <div>
                        <h2>Non-Member Bookings</h2>
                        <p>Standalone service requests from clients outside the member system.</p>
                    </div>
                    <div class="count-pill"><?php echo number_format($nonMemberCount); ?></div>
                </div>

                <?php if (empty($nonMembers)): ?>
                    <div class="empty">No non-member bookings found yet.</div>
                <?php else: ?>
                    <div class="booking-grid">
                        <?php foreach ($nonMembers as $n): ?>
                            <article class="booking-card">
                                <div class="booking-top">
                                    <div>
                                        <h3 class="booking-title"><?php echo h($n['full_name'] ?? 'Client'); ?></h3>
                                        <div class="booking-sub">
                                            Non-member booking #<?php echo (int)($n['id'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php echo statusBadge((string)($n['status'] ?? 'New')); ?>
                                    </div>
                                </div>

                                <div class="booking-body">
                                    <div class="detail-grid">
                                        <div class="detail">
                                            <div class="detail-label">Service</div>
                                            <div class="detail-value"><?php echo h($n['service_type'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Dog Name</div>
                                            <div class="detail-value"><?php echo h($n['dog_name'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Dog Size</div>
                                            <div class="detail-value"><?php echo h($n['dog_size'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Price</div>
                                            <div class="detail-value">$<?php echo number_format((float)($n['estimated_price'] ?? 0), 2); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Date Start</div>
                                            <div class="detail-value"><?php echo h($n['date_start'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Preferred Time</div>
                                            <div class="detail-value"><?php echo h($n['preferred_walk_time'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Email</div>
                                            <div class="detail-value"><?php echo h($n['email'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail">
                                            <div class="detail-label">Phone</div>
                                            <div class="detail-value"><?php echo h($n['phone'] ?? '—'); ?></div>
                                        </div>

                                        <div class="detail" style="grid-column:1 / -1;">
                                            <div class="detail-label">Notes</div>
                                            <div class="detail-value"><?php echo nl2br(h($n['notes'] ?? '—')); ?></div>
                                        </div>
                                    </div>

                                    <div class="update-box">
                                        <h3>Update Status</h3>
                                        <form method="POST" action="">
                                            <input type="hidden" name="type" value="non_member">
                                            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">

                                            <select name="status">
                                                <?php foreach (['Requested','Pending','Scheduled','Completed','Cancelled'] as $s): ?>
                                                    <option value="<?php echo h($s); ?>" <?php echo (($n['status'] ?? '') === $s) ? 'selected' : ''; ?>>
                                                        <?php echo h($s); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button class="btn" type="submit">Save Update</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    </main>
</div>
</body>
</html>