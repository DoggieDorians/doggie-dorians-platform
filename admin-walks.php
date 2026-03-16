<?php
declare(strict_types=1);
session_start();

$dbPath = __DIR__ . '/data/members.sqlite';

if (!file_exists($dbPath)) {
    die('Database not found.');
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateNice(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : $date;
}

function formatTimeNice(?string $time): string
{
    if (!$time) {
        return '—';
    }

    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

$successMessage = '';
$errorMessage   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $walkId = isset($_POST['walk_id']) ? (int)$_POST['walk_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($walkId <= 0) {
        $errorMessage = 'Invalid walk selected.';
    } else {
        try {
            if ($action === 'update_status') {
                $allowedStatuses = ['Requested', 'Accepted', 'In Progress', 'Completed', 'Cancelled'];
                $status = trim($_POST['status'] ?? '');

                if (!in_array($status, $allowedStatuses, true)) {
                    $errorMessage = 'Invalid status selected.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = :status
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':status' => $status,
                        ':id'     => $walkId
                    ]);

                    $successMessage = 'Walk status updated successfully.';
                }
            }

            if ($action === 'assign_walker') {
                $walkerId = isset($_POST['walker_id']) ? (int)$_POST['walker_id'] : 0;

                if ($walkerId <= 0) {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET walker_id = NULL,
                            walker_name = NULL,
                            walker_phone = NULL
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $walkId]);

                    $successMessage = 'Walker removed successfully.';
                } else {
                    $walkerStmt = $pdo->prepare("
                        SELECT id, full_name, phone
                        FROM walkers
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $walkerStmt->execute([':id' => $walkerId]);
                    $walker = $walkerStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$walker) {
                        $errorMessage = 'Selected walker not found.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE walks
                            SET walker_id = :walker_id,
                                walker_name = :walker_name,
                                walker_phone = :walker_phone
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':walker_id'    => (int)$walker['id'],
                            ':walker_name'  => $walker['full_name'],
                            ':walker_phone' => $walker['phone'],
                            ':id'           => $walkId
                        ]);

                        $successMessage = 'Walker assigned successfully.';
                    }
                }
            }

            if ($action === 'save_notes') {
                $walkerNotes = trim($_POST['walker_notes'] ?? '');

                $stmt = $pdo->prepare("
                    UPDATE walks
                    SET walker_notes = :walker_notes
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':walker_notes' => $walkerNotes,
                    ':id'           => $walkId
                ]);

                $successMessage = 'Notes saved successfully.';
            }
        } catch (Throwable $e) {
            $errorMessage = 'Action failed: ' . $e->getMessage();
        }
    }
}

try {
    $walkersStmt = $pdo->query("
        SELECT id, full_name, phone
        FROM walkers
        WHERE is_active = 1
        ORDER BY full_name ASC
    ");
    $walkers = $walkersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $walkers = [];
    $errorMessage = 'Could not load walkers: ' . $e->getMessage();
}

try {
    $walksStmt = $pdo->query("
        SELECT
            id,
            member_id,
            dog_id,
            walk_date,
            walk_time,
            duration_minutes,
            notes,
            status,
            walker_id,
            walker_name,
            walker_phone,
            walker_notes,
            created_at
        FROM walks
        ORDER BY walk_date DESC, walk_time DESC, id DESC
    ");
    $walks = $walksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $walks = [];
    $errorMessage = 'Could not load walks: ' . $e->getMessage();
}

$requestedCount  = 0;
$acceptedCount   = 0;
$inProgressCount = 0;
$completedCount  = 0;
$cancelledCount  = 0;

foreach ($walks as $walk) {
    if ($walk['status'] === 'Requested') $requestedCount++;
    if ($walk['status'] === 'Accepted') $acceptedCount++;
    if ($walk['status'] === 'In Progress') $inProgressCount++;
    if ($walk['status'] === 'Completed') $completedCount++;
    if ($walk['status'] === 'Cancelled') $cancelledCount++;
}

$totalWalks = count($walks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Walks | Doggie Dorian's</title>
    <style>
        :root{
            --bg:#06070b;
            --bg2:#0d1118;
            --panel:rgba(14,17,24,0.86);
            --panel-soft:rgba(255,255,255,0.03);
            --line:rgba(212,175,55,0.16);
            --line-strong:rgba(212,175,55,0.30);
            --gold:#d4af37;
            --gold-soft:#f2df9b;
            --text:#f8f3e8;
            --muted:rgba(248,243,232,0.68);
            --muted-2:rgba(248,243,232,0.45);
            --shadow:0 24px 70px rgba(0,0,0,0.45);
            --radius:24px;
            --sidebar-width:300px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.10), transparent 22%),
                radial-gradient(circle at bottom right, rgba(212,175,55,0.08), transparent 18%),
                linear-gradient(180deg, #040508 0%, #090c12 42%, #0b1017 100%);
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .admin-shell {
            display: grid;
            grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 24px 18px;
            border-right: 1px solid rgba(212,175,55,0.10);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01)),
                rgba(8,10,15,0.92);
            backdrop-filter: blur(10px);
        }

        .brand-card {
            border: 1px solid var(--line);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015)),
                rgba(12,15,21,0.92);
            border-radius: 28px;
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
        }

        .brand-kicker {
            display: inline-block;
            font-size: 11px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--gold-soft);
            margin-bottom: 12px;
        }

        .brand-title {
            font-size: 28px;
            line-height: 1.02;
            letter-spacing: -0.04em;
            font-weight: 760;
            margin: 0 0 10px;
        }

        .brand-text {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .sidebar-group {
            margin-bottom: 18px;
        }

        .sidebar-label {
            color: var(--muted-2);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            padding: 0 10px;
            margin-bottom: 10px;
        }

        .sidebar-nav {
            display: grid;
            gap: 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.05);
            background: rgba(255,255,255,0.025);
            transition: 0.2s ease;
        }

        .nav-item:hover {
            transform: translateX(2px);
            border-color: rgba(212,175,55,0.28);
            background: rgba(212,175,55,0.06);
        }

        .nav-item.active {
            border-color: rgba(212,175,55,0.34);
            background:
                linear-gradient(135deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03)),
                rgba(255,255,255,0.02);
            box-shadow: inset 0 0 0 1px rgba(212,175,55,0.10);
        }

        .nav-item-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .nav-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.05);
            color: var(--gold-soft);
            font-size: 16px;
            flex-shrink: 0;
        }

        .nav-meta {
            min-width: 0;
        }

        .nav-title {
            font-size: 14px;
            font-weight: 650;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-sub {
            font-size: 12px;
            color: var(--muted-2);
            margin-top: 2px;
        }

        .nav-badge {
            min-width: 34px;
            height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(212,175,55,0.12);
            border: 1px solid rgba(212,175,55,0.20);
            color: var(--gold-soft);
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .sidebar-footer {
            margin-top: 18px;
            padding: 16px;
            border-radius: 22px;
            border: 1px solid var(--line);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015)),
                rgba(12,15,21,0.9);
        }

        .sidebar-footer-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }

        .sidebar-footer-text {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .sidebar-footer a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 14px;
            border-radius: 14px;
            background: linear-gradient(135deg, #d4af37 0%, #f2df9b 100%);
            color: #121212;
            font-size: 13px;
            font-weight: 700;
        }

        .main {
            padding: 26px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            background:
                linear-gradient(135deg, rgba(255,255,255,0.045), rgba(255,255,255,0.015)),
                rgba(10,13,19,0.85);
            border-radius: 34px;
            padding: 28px;
            box-shadow: var(--shadow);
            margin-bottom: 22px;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 24%),
                linear-gradient(90deg, transparent, rgba(255,255,255,0.03), transparent);
            pointer-events: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .hero-copy {
            max-width: 760px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(212,175,55,0.16);
            background: rgba(255,255,255,0.03);
            color: var(--gold-soft);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            margin-bottom: 18px;
        }

        .hero h1 {
            margin: 0;
            font-size: 46px;
            line-height: 0.98;
            letter-spacing: -0.05em;
            font-weight: 760;
        }

        .hero p {
            margin: 16px 0 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.75;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero-actions a {
            padding: 13px 18px;
            border-radius: 999px;
            border: 1px solid rgba(212,175,55,0.22);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 14px;
            transition: 0.2s ease;
        }

        .hero-actions a:hover {
            transform: translateY(-1px);
            border-color: rgba(212,175,55,0.40);
            background: rgba(212,175,55,0.08);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .stat-card {
            background:
                linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02)),
                rgba(14,17,24,0.82);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 22px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.55), transparent);
        }

        .stat-label {
            color: var(--muted-2);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            margin-bottom: 14px;
        }

        .stat-value {
            font-size: 40px;
            line-height: 1;
            font-weight: 760;
            margin-bottom: 10px;
        }

        .stat-caption {
            color: var(--muted);
            font-size: 13px;
        }

        .message {
            border-radius: 18px;
            padding: 15px 18px;
            margin-bottom: 18px;
            font-size: 14px;
            box-shadow: var(--shadow);
        }

        .message.success {
            background: rgba(25,135,84,0.14);
            border: 1px solid rgba(25,135,84,0.30);
            color: #d4f4df;
        }

        .message.error {
            background: rgba(220,53,69,0.14);
            border: 1px solid rgba(220,53,69,0.30);
            color: #ffe0e5;
        }

        .walks-grid {
            display: grid;
            gap: 20px;
        }

        .walk-card {
            background:
                linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015)),
                rgba(12,15,21,0.9);
            border: 1px solid var(--line);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .walk-card-top {
            display: grid;
            grid-template-columns: 1.15fr 0.95fr 1fr 1fr;
            gap: 18px;
            padding: 24px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .walk-card-bottom {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
            padding: 24px;
        }

        .block {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.04);
            border-radius: 20px;
            padding: 18px;
        }

        .section-title {
            color: var(--gold-soft);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            margin-bottom: 12px;
        }

        .main-line {
            font-size: 24px;
            line-height: 1.1;
            font-weight: 700;
            margin-bottom: 12px;
            color: #ffffff;
        }

        .detail {
            font-size: 14px;
            line-height: 1.8;
            color: var(--muted);
        }

        .detail strong {
            color: var(--text);
            font-weight: 600;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
            padding: 9px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 12px;
            border: 1px solid transparent;
        }

        .badge.requested {
            background: rgba(255,193,7,0.12);
            color: #ffe7a0;
            border-color: rgba(255,193,7,0.22);
        }

        .badge.accepted {
            background: rgba(13,110,253,0.14);
            color: #cfe0ff;
            border-color: rgba(13,110,253,0.24);
        }

        .badge.in-progress {
            background: rgba(111,66,193,0.16);
            color: #e0d0ff;
            border-color: rgba(111,66,193,0.24);
        }

        .badge.completed {
            background: rgba(25,135,84,0.14);
            color: #d4f4df;
            border-color: rgba(25,135,84,0.24);
        }

        .badge.cancelled {
            background: rgba(220,53,69,0.14);
            color: #ffd8df;
            border-color: rgba(220,53,69,0.24);
        }

        .admin-form {
            display: grid;
            gap: 10px;
        }

        label {
            color: var(--muted);
            font-size: 13px;
        }

        select,
        textarea {
            width: 100%;
            background: rgba(255,255,255,0.05);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 13px 14px;
            font-size: 14px;
            outline: none;
            appearance: none;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        select:focus,
        textarea:focus {
            border-color: rgba(212,175,55,0.42);
            box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
        }

        button {
            border: 0;
            border-radius: 16px;
            padding: 13px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            color: #121212;
            background: linear-gradient(135deg, #d4af37 0%, #f2df9b 100%);
            transition: 0.2s ease;
        }

        button:hover {
            transform: translateY(-1px);
            filter: brightness(1.02);
        }

        .small-text {
            color: var(--muted-2);
            font-size: 12px;
            line-height: 1.6;
        }

        .tracking-link {
            display: inline-flex;
            margin-top: 8px;
            word-break: break-all;
            color: var(--gold-soft);
        }

        .tracking-link:hover {
            text-decoration: underline;
        }

        .empty-state {
            text-align: center;
            padding: 42px 24px;
            border-radius: 28px;
            border: 1px solid var(--line);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015)),
                rgba(12,15,21,0.9);
            box-shadow: var(--shadow);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            font-size: 26px;
            letter-spacing: -0.03em;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        @media (max-width: 1420px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(150px, 1fr));
            }

            .walk-card-top {
                grid-template-columns: 1fr 1fr;
            }

            .walk-card-bottom {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1120px) {
            .admin-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                height: auto;
                border-right: none;
                border-bottom: 1px solid rgba(212,175,55,0.10);
            }

            .main {
                padding-top: 18px;
            }
        }

        @media (max-width: 820px) {
            .hero h1 {
                font-size: 36px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .walk-card-top {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .sidebar,
            .main {
                padding: 14px;
            }

            .hero {
                padding: 20px;
                border-radius: 24px;
            }

            .hero h1 {
                font-size: 30px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 34px;
            }

            .walk-card,
            .empty-state,
            .brand-card {
                border-radius: 22px;
            }

            .walk-card-top,
            .walk-card-bottom {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <aside class="sidebar">
            <div class="brand-card">
                <div class="brand-kicker">Doggie Dorian’s</div>
                <h2 class="brand-title">Admin Suite</h2>
                <p class="brand-text">
                    Private luxury operations for walk management, live tracking, and premium client service.
                </p>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-label">Operations</div>
                <nav class="sidebar-nav">
                    <a href="admin-walks.php" class="nav-item active">
                        <div class="nav-item-left">
                            <div class="nav-icon">🐾</div>
                            <div class="nav-meta">
                                <div class="nav-title">Walks</div>
                                <div class="nav-sub">Requests and assignments</div>
                            </div>
                        </div>
                        <div class="nav-badge"><?php echo $totalWalks; ?></div>
                    </a>

                    <a href="dashboard.php" class="nav-item">
                        <div class="nav-item-left">
                            <div class="nav-icon">👤</div>
                            <div class="nav-meta">
                                <div class="nav-title">Members</div>
                                <div class="nav-sub">Client-facing dashboard</div>
                            </div>
                        </div>
                    </a>

                    <a href="walker-dashboard.php" class="nav-item">
                        <div class="nav-item-left">
                            <div class="nav-icon">🚶</div>
                            <div class="nav-meta">
                                <div class="nav-title">Walkers</div>
                                <div class="nav-sub">Field team access</div>
                            </div>
                        </div>
                    </a>

                    <a href="client-map.php?walk_id=1" class="nav-item">
                        <div class="nav-item-left">
                            <div class="nav-icon">📍</div>
                            <div class="nav-meta">
                                <div class="nav-title">Tracking</div>
                                <div class="nav-sub">Client live map view</div>
                            </div>
                        </div>
                    </a>
                </nav>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-label">Quick Access</div>
                <nav class="sidebar-nav">
                    <a href="book-walk.php" class="nav-item">
                        <div class="nav-item-left">
                            <div class="nav-icon">➕</div>
                            <div class="nav-meta">
                                <div class="nav-title">Book Walk</div>
                                <div class="nav-sub">Create a new request</div>
                            </div>
                        </div>
                    </a>

                    <a href="login.php" class="nav-item">
                        <div class="nav-item-left">
                            <div class="nav-icon">🔐</div>
                            <div class="nav-meta">
                                <div class="nav-title">Login</div>
                                <div class="nav-sub">Access control</div>
                            </div>
                        </div>
                    </a>
                </nav>
            </div>

            <div class="sidebar-footer">
                <div class="sidebar-footer-title">Luxury operations standard</div>
                <div class="sidebar-footer-text">
                    Use this panel to assign walkers, move walks through each stage, and prepare for live GPS tracking.
                </div>
                <a href="book-walk.php">Create Walk Request</a>
            </div>
        </aside>

        <main class="main">
            <section class="hero">
                <div class="hero-inner">
                    <div class="hero-copy">
                        <div class="eyebrow">Private Operations Dashboard</div>
                        <h1>Walk Management</h1>
                        <p>
                            A refined internal control center for managing walk requests, assigning walkers,
                            updating statuses, and delivering a premium service experience across Doggie Dorian’s.
                        </p>
                    </div>

                    <div class="hero-actions">
                        <a href="dashboard.php">Member Dashboard</a>
                        <a href="book-walk.php">Book Walk</a>
                        <a href="login.php">Login</a>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Requested</div>
                    <div class="stat-value"><?php echo $requestedCount; ?></div>
                    <div class="stat-caption">Awaiting review</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Accepted</div>
                    <div class="stat-value"><?php echo $acceptedCount; ?></div>
                    <div class="stat-caption">Confirmed services</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?php echo $inProgressCount; ?></div>
                    <div class="stat-caption">Active walk sessions</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $completedCount; ?></div>
                    <div class="stat-caption">Finished successfully</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Cancelled</div>
                    <div class="stat-value"><?php echo $cancelledCount; ?></div>
                    <div class="stat-caption">Closed or cancelled</div>
                </div>
            </section>

            <?php if ($successMessage): ?>
                <div class="message success"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="message error"><?php echo e($errorMessage); ?></div>
            <?php endif; ?>

            <section class="walks-grid">
                <?php if (!$walks): ?>
                    <div class="empty-state">
                        <h3>No walk requests yet</h3>
                        <p>New walk activity will appear here once bookings begin coming in.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($walks as $walk): ?>
                    <?php
                        $statusClass = strtolower(str_replace(' ', '-', $walk['status']));
                        $trackingLink = 'client-map.php?walk_id=' . (int)$walk['id'];
                    ?>
                    <article class="walk-card">
                        <div class="walk-card-top">
                            <div class="block">
                                <div class="section-title">Walk Overview</div>
                                <div class="badge <?php echo e($statusClass); ?>"><?php echo e($walk['status']); ?></div>
                                <div class="main-line">Walk #<?php echo (int)$walk['id']; ?></div>
                                <div class="detail">
                                    <strong>Date:</strong> <?php echo e(formatDateNice($walk['walk_date'])); ?><br>
                                    <strong>Time:</strong> <?php echo e(formatTimeNice($walk['walk_time'])); ?><br>
                                    <strong>Duration:</strong> <?php echo (int)$walk['duration_minutes']; ?> minutes<br>
                                    <strong>Created:</strong> <?php echo e($walk['created_at'] ? formatDateNice($walk['created_at']) : '—'); ?>
                                </div>
                            </div>

                            <div class="block">
                                <div class="section-title">Request Details</div>
                                <div class="main-line">Member ID #<?php echo (int)$walk['member_id']; ?></div>
                                <div class="detail">
                                    <strong>Dog ID:</strong> <?php echo (int)$walk['dog_id']; ?><br>
                                    <strong>Assigned Walker:</strong> <?php echo e($walk['walker_name'] ?: 'Not assigned'); ?><br>
                                    <strong>Walker Phone:</strong> <?php echo e($walk['walker_phone'] ?: '—'); ?>
                                </div>
                            </div>

                            <div class="block">
                                <div class="section-title">Tracking Access</div>
                                <div class="main-line">Client Tracking Link</div>
                                <div class="detail">
                                    Share this with the client once the walk is live.
                                    <br>
                                    <a class="tracking-link" href="<?php echo e($trackingLink); ?>" target="_blank"><?php echo e($trackingLink); ?></a>
                                </div>
                            </div>

                            <div class="block">
                                <div class="section-title">Client Notes</div>
                                <div class="main-line">Special Instructions</div>
                                <div class="detail">
                                    <?php echo !empty($walk['notes']) ? nl2br(e($walk['notes'])) : 'No client notes were added for this request.'; ?>
                                </div>
                            </div>
                        </div>

                        <div class="walk-card-bottom">
                            <div class="block">
                                <div class="section-title">Assign Walker</div>
                                <form method="post" class="admin-form">
                                    <input type="hidden" name="action" value="assign_walker">
                                    <input type="hidden" name="walk_id" value="<?php echo (int)$walk['id']; ?>">

                                    <label for="walker_<?php echo (int)$walk['id']; ?>">Select active walker</label>
                                    <select name="walker_id" id="walker_<?php echo (int)$walk['id']; ?>">
                                        <option value="0">No walker assigned</option>
                                        <?php foreach ($walkers as $walker): ?>
                                            <option
                                                value="<?php echo (int)$walker['id']; ?>"
                                                <?php echo ((int)$walk['walker_id'] === (int)$walker['id']) ? 'selected' : ''; ?>
                                            >
                                                <?php echo e($walker['full_name']); ?><?php echo !empty($walker['phone']) ? ' • ' . e($walker['phone']) : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit">Save Walker</button>
                                </form>
                            </div>

                            <div class="block">
                                <div class="section-title">Update Status</div>
                                <form method="post" class="admin-form">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="walk_id" value="<?php echo (int)$walk['id']; ?>">

                                    <label for="status_<?php echo (int)$walk['id']; ?>">Walk status</label>
                                    <select name="status" id="status_<?php echo (int)$walk['id']; ?>">
                                        <?php
                                        $statuses = ['Requested', 'Accepted', 'In Progress', 'Completed', 'Cancelled'];
                                        foreach ($statuses as $status):
                                        ?>
                                            <option value="<?php echo e($status); ?>" <?php echo $walk['status'] === $status ? 'selected' : ''; ?>>
                                                <?php echo e($status); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit">Save Status</button>
                                </form>
                            </div>

                            <div class="block">
                                <div class="section-title">Internal Notes</div>
                                <form method="post" class="admin-form">
                                    <input type="hidden" name="action" value="save_notes">
                                    <input type="hidden" name="walk_id" value="<?php echo (int)$walk['id']; ?>">

                                    <label for="notes_<?php echo (int)$walk['id']; ?>">Walker or admin notes</label>
                                    <textarea name="walker_notes" id="notes_<?php echo (int)$walk['id']; ?>" placeholder="Add internal notes, handling details, arrival updates, or team instructions..."><?php echo e($walk['walker_notes'] ?? ''); ?></textarea>

                                    <button type="submit">Save Notes</button>
                                    <div class="small-text">
                                        Use this space for internal service notes only.
                                    </div>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
    </div>
</body>
</html>