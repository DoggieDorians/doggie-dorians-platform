<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin-login.php');
    exit;
}

function getDatabaseConnection(): PDO
{
    $possiblePaths = [
        __DIR__ . '/data/members.sqlite',
        __DIR__ . '/data/database.sqlite',
        __DIR__ . '/database.sqlite',
        __DIR__ . '/data/site.sqlite',
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }

    throw new RuntimeException('Could not find SQLite database file.');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function getTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map(static fn($row) => $row['name'] ?? '', $rows)));
    } catch (Throwable $e) {
        return [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function formatDisplayDate(?string $date): string
{
    $date = trim((string)$date);

    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y', $timestamp);
}

function formatDisplayTime(?string $time): string
{
    $time = trim((string)$time);

    if ($time === '') {
        return 'N/A';
    }

    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
    }

    return date('g:i A', $timestamp);
}

function formatDisplayDateTime(?string $dateTime): string
{
    $dateTime = trim((string)$dateTime);

    if ($dateTime === '') {
        return 'N/A';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return htmlspecialchars($dateTime, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatStatusClass(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'approved', 'confirmed', 'completed' => 'status-positive',
        'pending', 'requested', 'scheduled' => 'status-neutral',
        'declined', 'cancelled', 'canceled' => 'status-negative',
        default => 'status-default',
    };
}

function formatRequestType(string $type): string
{
    $type = strtolower(trim($type));

    return match ($type) {
        'cancel' => 'Cancellation Request',
        'reschedule' => 'Reschedule Request',
        default => ucwords(str_replace(['-', '_'], ' ', $type)),
    };
}

$successMessage = '';
$errorMessage = '';
$requests = [];
$fatalError = '';

try {
    $pdo = getDatabaseConnection();

    if (!tableExists($pdo, 'booking_change_requests')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                request_type TEXT NOT NULL,
                current_service_date TEXT,
                current_service_time TEXT,
                requested_service_date TEXT,
                requested_service_time TEXT,
                note TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Pending',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $action = strtolower(trim($_POST['admin_action'] ?? ''));

        if ($requestId <= 0) {
            $errorMessage = 'Invalid request ID.';
        } elseif (!in_array($action, ['approve', 'decline'], true)) {
            $errorMessage = 'Invalid admin action.';
        } else {
            $requestStmt = $pdo->prepare("
                SELECT *
                FROM booking_change_requests
                WHERE id = :id
                LIMIT 1
            ");
            $requestStmt->execute(['id' => $requestId]);
            $requestRow = $requestStmt->fetch();

            if (!$requestRow) {
                $errorMessage = 'Request not found.';
            } elseif (strtolower((string)$requestRow['status']) !== 'pending') {
                $errorMessage = 'This request has already been reviewed.';
            } elseif (!tableExists($pdo, 'bookings')) {
                $errorMessage = 'Bookings table not found.';
            } else {
                $bookingColumns = getTableColumns($pdo, 'bookings');

                try {
                    $pdo->beginTransaction();

                    if ($action === 'approve') {
                        $requestType = strtolower(trim((string)$requestRow['request_type']));
                        $bookingId = (int)$requestRow['booking_id'];

                        if ($requestType === 'cancel') {
                            if (!hasColumn($bookingColumns, 'status')) {
                                throw new RuntimeException('Bookings table is missing the status column.');
                            }

                            $updateBooking = $pdo->prepare("
                                UPDATE bookings
                                SET status = :status
                                WHERE id = :booking_id
                            ");
                            $updateBooking->execute([
                                'status' => 'Cancelled',
                                'booking_id' => $bookingId,
                            ]);
                        } elseif ($requestType === 'reschedule') {
                            if (!hasColumn($bookingColumns, 'service_date')) {
                                throw new RuntimeException('Bookings table is missing the service_date column.');
                            }

                            $requestedDate = trim((string)($requestRow['requested_service_date'] ?? ''));
                            $requestedTime = trim((string)($requestRow['requested_service_time'] ?? ''));

                            if ($requestedDate === '') {
                                throw new RuntimeException('Reschedule request is missing a requested date.');
                            }

                            $setParts = ['service_date = :service_date'];
                            $params = [
                                'service_date' => $requestedDate,
                                'booking_id' => $bookingId,
                            ];

                            if (hasColumn($bookingColumns, 'service_time') && $requestedTime !== '') {
                                $setParts[] = 'service_time = :service_time';
                                $params['service_time'] = $requestedTime;
                            }

                            if (hasColumn($bookingColumns, 'status')) {
                                $setParts[] = 'status = :status';
                                $params['status'] = 'Scheduled';
                            }

                            $sql = "
                                UPDATE bookings
                                SET " . implode(', ', $setParts) . "
                                WHERE id = :booking_id
                            ";

                            $updateBooking = $pdo->prepare($sql);
                            $updateBooking->execute($params);
                        } else {
                            throw new RuntimeException('Unknown request type.');
                        }

                        $updateRequest = $pdo->prepare("
                            UPDATE booking_change_requests
                            SET status = 'Approved'
                            WHERE id = :id
                        ");
                        $updateRequest->execute(['id' => $requestId]);

                        $pdo->commit();
                        $successMessage = 'Request approved and booking updated successfully.';
                    } else {
                        $updateRequest = $pdo->prepare("
                            UPDATE booking_change_requests
                            SET status = 'Declined'
                            WHERE id = :id
                        ");
                        $updateRequest->execute(['id' => $requestId]);

                        $pdo->commit();
                        $successMessage = 'Request declined successfully.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = $e->getMessage();
                }
            }
        }
    }

    $bookingColumns = tableExists($pdo, 'bookings') ? getTableColumns($pdo, 'bookings') : [];
    $petsColumns = tableExists($pdo, 'pets') ? getTableColumns($pdo, 'pets') : [];
    $usersColumns = tableExists($pdo, 'users') ? getTableColumns($pdo, 'users') : [];
    $membersColumns = tableExists($pdo, 'members') ? getTableColumns($pdo, 'members') : [];

    $petJoin = '';
    $petSelect = "NULL AS booking_pet_name";

    if (tableExists($pdo, 'bookings') && tableExists($pdo, 'pets') && hasColumn($bookingColumns, 'pet_id') && hasColumn($petsColumns, 'id') && hasColumn($petsColumns, 'pet_name')) {
        $petJoin = " LEFT JOIN pets p ON b.pet_id = p.id ";
        $petSelect = "p.pet_name AS booking_pet_name";
    } elseif (hasColumn($bookingColumns, 'pet_name')) {
        $petSelect = "b.pet_name AS booking_pet_name";
    }

    $memberNameSelect = "NULL AS member_full_name";

    if (tableExists($pdo, 'users')) {
        if (hasColumn($usersColumns, 'full_name') && hasColumn($usersColumns, 'id')) {
            $memberNameSelect = "(SELECT u.full_name FROM users u WHERE u.id = r.user_id LIMIT 1) AS member_full_name";
        } elseif (hasColumn($usersColumns, 'name') && hasColumn($usersColumns, 'id')) {
            $memberNameSelect = "(SELECT u.name FROM users u WHERE u.id = r.user_id LIMIT 1) AS member_full_name";
        }
    } elseif (tableExists($pdo, 'members')) {
        if (hasColumn($membersColumns, 'full_name') && hasColumn($membersColumns, 'id')) {
            $memberNameSelect = "(SELECT m.full_name FROM members m WHERE m.id = r.user_id LIMIT 1) AS member_full_name";
        } elseif (hasColumn($membersColumns, 'name') && hasColumn($membersColumns, 'id')) {
            $memberNameSelect = "(SELECT m.name FROM members m WHERE m.id = r.user_id LIMIT 1) AS member_full_name";
        }
    }

    $sql = "
        SELECT
            r.*,
            {$petSelect},
            {$memberNameSelect},
            b.service_type AS booking_service_type,
            b.status AS booking_status,
            b.price AS booking_price
        FROM booking_change_requests r
        LEFT JOIN bookings b ON b.id = r.booking_id
        {$petJoin}
        ORDER BY
            CASE
                WHEN LOWER(r.status) = 'pending' THEN 0
                WHEN LOWER(r.status) = 'approved' THEN 1
                ELSE 2
            END,
            r.created_at DESC
    ";

    $stmt = $pdo->query($sql);
    $requests = $stmt->fetchAll();
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Change Requests | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --success:#9fe0b1;
            --danger:#ff9d9d;
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
        .admin-shell{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
        .sidebar{
            border-right:1px solid var(--border);
            background:linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            padding:28px 20px;
            position:sticky;
            top:0;
            height:100vh;
            backdrop-filter:blur(10px);
        }
        .brand{font-size:28px;font-weight:800;letter-spacing:-0.5px;line-height:1.1;margin-bottom:10px}
        .brand span{color:var(--gold)}
        .tag{color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:26px}
        .nav a{
            display:block;text-decoration:none;color:var(--text);padding:14px 16px;margin-bottom:10px;
            border-radius:16px;background:rgba(255,255,255,0.03);border:1px solid transparent;
            transition:.2s ease;font-weight:600;
        }
        .nav a:hover,.nav a.active{
            border-color:var(--border);
            background:linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));
            transform:translateY(-1px);
        }
        .main{padding:34px}
        .hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:28px;flex-wrap:wrap}
        .hero h1{margin:0 0 8px;font-size:40px;line-height:1;letter-spacing:-1px}
        .hero p{margin:0;color:var(--muted);font-size:15px;max-width:740px}
        .status-message{
            border-radius:16px;padding:14px 16px;margin-bottom:18px;font-size:14px
        }
        .status-message.success{
            background:rgba(159,224,177,0.10);
            border:1px solid rgba(159,224,177,0.30);
            color:var(--success);
        }
        .status-message.error{
            background:rgba(255,157,157,0.10);
            border:1px solid rgba(255,157,157,0.30);
            color:var(--danger);
        }
        .requests{display:grid;gap:18px}
        .request-card{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:22px;
            box-shadow:var(--shadow);
        }
        .request-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }
        .request-title{
            font-size:24px;
            font-weight:800;
            letter-spacing:-0.4px;
            margin-bottom:6px;
        }
        .request-sub{
            color:var(--muted);
            font-size:14px;
        }
        .status-badge{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
            border:1px solid rgba(255,255,255,0.12);
        }
        .status-positive{
            background:rgba(159,224,177,0.10);
            color:var(--success);
            border-color:rgba(159,224,177,0.24);
        }
        .status-neutral{
            background:rgba(212,175,55,0.10);
            color:var(--gold-soft);
            border-color:rgba(212,175,55,0.24);
        }
        .status-negative{
            background:rgba(255,157,157,0.10);
            color:var(--danger);
            border-color:rgba(255,157,157,0.24);
        }
        .status-default{
            background:rgba(255,255,255,0.06);
            color:var(--text);
            border-color:rgba(255,255,255,0.12);
        }
        .details-grid{
            display:grid;
            grid-template-columns:repeat(3, minmax(0,1fr));
            gap:14px;
            margin-bottom:16px;
        }
        .detail-box{
            border-radius:18px;
            padding:14px;
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
        }
        .detail-box strong{
            display:block;
            color:var(--gold-soft);
            margin-bottom:6px;
            font-size:13px;
        }
        .detail-box span{
            color:var(--muted);
            font-size:14px;
            line-height:1.5;
        }
        .request-note{
            border-radius:18px;
            padding:16px;
            background:rgba(212,175,55,0.08);
            border:1px solid rgba(212,175,55,0.16);
            margin-bottom:16px;
        }
        .request-note strong{
            display:block;
            color:var(--text);
            margin-bottom:8px;
            font-size:14px;
        }
        .request-note p{
            margin:0;
            color:var(--muted);
            line-height:1.6;
            font-size:14px;
        }
        .actions{
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
            padding:13px 18px;
            border-radius:14px;
            font-weight:800;
            min-height:46px;
            transition:.2s ease;
        }
        .btn:hover{transform:translateY(-1px)}
        .btn-primary{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }
        .btn-secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
        }
        .empty-state{
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
        }
        @media (max-width: 1180px){
            .details-grid{grid-template-columns:repeat(2, minmax(0,1fr))}
        }
        @media (max-width: 860px){
            .admin-shell{grid-template-columns:1fr}
            .sidebar{position:relative;height:auto;border-right:none;border-bottom:1px solid var(--border)}
            .main{padding:20px}
            .hero h1{font-size:32px}
            .details-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <aside class="sidebar">
            <div class="brand">Doggie <span>Dorian’s</span></div>
            <div class="tag">Premium admin control panel for bookings, revenue, memberships, walkers, and client management.</div>

            <nav class="nav">
                <a href="admin-dashboard.php">Dashboard</a>
                <a href="admin-bookings.php">Booking Management</a>
                <a href="admin-booking-requests.php" class="active">Change Requests</a>
                <a href="admin-revenue.php">Revenue Dashboard</a>
                <a href="memberships.php">Memberships</a>
                <a href="admin-logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main">
            <?php if ($fatalError !== ''): ?>
                <div class="error-box">
                    <strong>System error:</strong><br>
                    <?php echo htmlspecialchars($fatalError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <section class="hero">
                    <div>
                        <h1>Booking Change Requests</h1>
                        <p>Review member cancellation and reschedule requests in one place and keep scheduling decisions controlled.</p>
                    </div>
                </section>

                <?php if ($successMessage !== ''): ?>
                    <div class="status-message success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="status-message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if (!empty($requests)): ?>
                    <section class="requests">
                        <?php foreach ($requests as $request): ?>
                            <article class="request-card">
                                <div class="request-head">
                                    <div>
                                        <div class="request-title">
                                            <?php echo htmlspecialchars(formatRequestType((string)$request['request_type'])); ?>
                                        </div>
                                        <div class="request-sub">
                                            Member:
                                            <?php echo htmlspecialchars((string)($request['member_full_name'] ?: ('User #' . $request['user_id']))); ?>
                                            · Booking #<?php echo (int)$request['booking_id']; ?>
                                        </div>
                                    </div>

                                    <span class="status-badge <?php echo htmlspecialchars(formatStatusClass((string)$request['status'])); ?>">
                                        <?php echo htmlspecialchars((string)$request['status']); ?>
                                    </span>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <strong>Service</strong>
                                        <span><?php echo htmlspecialchars(formatServiceName((string)($request['booking_service_type'] ?? 'Service'))); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Pet</strong>
                                        <span><?php echo htmlspecialchars((string)($request['booking_pet_name'] ?: 'Pet not specified')); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Booking Price</strong>
                                        <span>
                                            <?php echo isset($request['booking_price']) && $request['booking_price'] !== null && $request['booking_price'] !== ''
                                                ? '$' . number_format((float)$request['booking_price'], 2)
                                                : 'N/A'; ?>
                                        </span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Current Date</strong>
                                        <span><?php echo formatDisplayDate($request['current_service_date'] ?? ''); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Current Time</strong>
                                        <span><?php echo formatDisplayTime($request['current_service_time'] ?? ''); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Created</strong>
                                        <span><?php echo formatDisplayDateTime($request['created_at'] ?? ''); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Requested New Date</strong>
                                        <span><?php echo formatDisplayDate($request['requested_service_date'] ?? ''); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Requested New Time</strong>
                                        <span><?php echo formatDisplayTime($request['requested_service_time'] ?? ''); ?></span>
                                    </div>

                                    <div class="detail-box">
                                        <strong>Booking Status</strong>
                                        <span><?php echo htmlspecialchars((string)($request['booking_status'] ?? 'Unknown')); ?></span>
                                    </div>
                                </div>

                                <div class="request-note">
                                    <strong>Member Note</strong>
                                    <p><?php echo nl2br(htmlspecialchars((string)$request['note'], ENT_QUOTES, 'UTF-8')); ?></p>
                                </div>

                                <div class="actions">
                                    <?php if (strtolower((string)$request['status']) === 'pending'): ?>
                                        <form method="post" style="display:inline-flex;">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                            <input type="hidden" name="admin_action" value="approve">
                                            <button type="submit" class="btn btn-primary">Approve & Update Booking</button>
                                        </form>

                                        <form method="post" style="display:inline-flex;">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                            <input type="hidden" name="admin_action" value="decline">
                                            <button type="submit" class="btn btn-secondary">Decline</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="empty-state" style="padding:14px 18px; text-align:left;">
                                            This request has already been reviewed.
                                        </div>
                                    <?php endif; ?>

                                    <a href="admin-bookings.php" class="btn btn-secondary">Open Booking Manager</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php else: ?>
                    <div class="empty-state">
                        No booking change requests have been submitted yet.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>