<?php
session_start();

if (!isset($_SESSION['walker_id'])) {
    header('Location: walker-login.php');
    exit;
}

$walkerId = (int) $_SESSION['walker_id'];

function getPreferredColumn(PDO $db, string $table, array $preferredColumns): ?string
{
    $stmt = $db->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $available = array_map(fn($col) => $col['name'], $columns);

    foreach ($preferredColumns as $column) {
        if (in_array($column, $available, true)) {
            return $column;
        }
    }

    return null;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/data/members.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dogColumn = getPreferredColumn($db, 'dogs', [
        'name', 'dog_name', 'full_name', 'pet_name', 'first_name'
    ]);

    $memberColumn = getPreferredColumn($db, 'members', [
        'full_name', 'name', 'member_name', 'first_name', 'email'
    ]);

    $walkerColumn = getPreferredColumn($db, 'walkers', [
        'full_name', 'name', 'walker_name', 'first_name', 'email'
    ]);

    $dogSelect = $dogColumn ? "d.$dogColumn AS dog_name" : "'Dog' AS dog_name";
    $memberSelect = $memberColumn ? "m.$memberColumn AS member_name" : "'Member' AS member_name";
    $walkerSelect = $walkerColumn ? "w.$walkerColumn AS walker_name" : "'Walker' AS walker_name";

    $walkerStmt = $db->prepare("
        SELECT $walkerSelect
        FROM walkers w
        WHERE w.id = :walker_id
        LIMIT 1
    ");
    $walkerStmt->execute([':walker_id' => $walkerId]);
    $walker = $walkerStmt->fetch(PDO::FETCH_ASSOC);

    $walksStmt = $db->prepare("
        SELECT
            wa.id,
            wa.member_id,
            wa.dog_id,
            wa.walk_date,
            wa.walk_time,
            wa.duration_minutes,
            wa.notes,
            wa.status,
            $dogSelect,
            $memberSelect
        FROM walks wa
        LEFT JOIN dogs d ON d.id = wa.dog_id
        LEFT JOIN members m ON m.id = wa.member_id
        WHERE wa.walker_id = :walker_id
        ORDER BY
            CASE
                WHEN wa.status = 'In Progress' THEN 1
                WHEN wa.status = 'Assigned' THEN 2
                WHEN wa.status = 'Requested' THEN 3
                WHEN wa.status = 'Completed' THEN 4
                ELSE 5
            END,
            wa.id DESC
    ");
    $walksStmt->execute([':walker_id' => $walkerId]);
    $walks = $walksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

function statusClass(string $status): string
{
    return match ($status) {
        'In Progress' => 'status-progress',
        'Assigned' => 'status-assigned',
        'Completed' => 'status-completed',
        'Requested' => 'status-requested',
        default => 'status-default',
    };
}

function canTrack(string $status): bool
{
    return in_array($status, ['Assigned', 'In Progress'], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walker Dashboard | Doggie Dorian's</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(180deg, #f7f4ef 0%, #efe7dc 100%);
            color: #1e1e1e;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 60px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .eyebrow {
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #8a6f4d;
            font-weight: 700;
        }

        .title {
            font-size: 34px;
            margin: 0;
            line-height: 1.1;
        }

        .subtitle {
            margin: 0;
            color: #666;
            font-size: 15px;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 18px;
            border-radius: 14px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: 0.2s ease;
        }

        .button-primary {
            background: #111;
            color: #fff;
        }

        .button-primary:hover {
            background: #222;
        }

        .button-secondary {
            background: #c9a56d;
            color: #111;
        }

        .button-secondary:hover {
            background: #bb9559;
        }

        .button-outline {
            background: #fff;
            color: #111;
            border: 1px solid rgba(0,0,0,0.10);
        }

        .hero {
            background: rgba(255,255,255,0.90);
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.05);
        }

        .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #7c7c7c;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #111;
        }

        .section-title {
            margin: 32px 0 16px;
            font-size: 24px;
        }

        .walk-grid {
            display: grid;
            gap: 18px;
        }

        .walk-card {
            background: rgba(255,255,255,0.94);
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 14px 32px rgba(0,0,0,0.08);
        }

        .walk-card-top {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .walk-main h3 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .walk-main p {
            margin: 0;
            color: #666;
            font-size: 15px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.4px;
        }

        .status-progress {
            background: #111;
            color: #fff;
        }

        .status-assigned {
            background: #d8c19b;
            color: #111;
        }

        .status-completed {
            background: #d8f1df;
            color: #155724;
        }

        .status-requested {
            background: #d8e8ff;
            color: #13417d;
        }

        .status-default {
            background: #ececec;
            color: #333;
        }

        .walk-meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .meta-box {
            background: #faf8f4;
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 16px;
            padding: 14px;
        }

        .meta-label {
            font-size: 12px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .meta-value {
            font-size: 16px;
            font-weight: 700;
            color: #111;
            word-break: break-word;
        }

        .notes {
            background: #fffaf2;
            border: 1px solid rgba(201,165,109,0.25);
            border-radius: 16px;
            padding: 16px;
            color: #5f513b;
            margin-bottom: 18px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .empty-state {
            background: rgba(255,255,255,0.94);
            border-radius: 22px;
            padding: 30px;
            box-shadow: 0 14px 32px rgba(0,0,0,0.08);
            color: #555;
            text-align: center;
        }

        @media (max-width: 920px) {
            .hero-grid,
            .walk-meta {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .hero-grid,
            .walk-meta {
                grid-template-columns: 1fr;
            }

            .title {
                font-size: 28px;
            }

            .walk-main h3 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">
                <div class="eyebrow">Doggie Dorian's</div>
                <h1 class="title">Walker Dashboard</h1>
                <p class="subtitle">
                    Welcome back<?php echo !empty($walker['walker_name']) ? ', ' . htmlspecialchars($walker['walker_name']) : ''; ?>.
                </p>
            </div>

            <div class="top-actions">
                <a href="walker-logout.php" class="button button-outline">Log Out</a>
            </div>
        </div>

        <?php
        $assignedCount = 0;
        $inProgressCount = 0;
        $completedCount = 0;

        foreach ($walks as $walk) {
            if ($walk['status'] === 'Assigned') {
                $assignedCount++;
            } elseif ($walk['status'] === 'In Progress') {
                $inProgressCount++;
            } elseif ($walk['status'] === 'Completed') {
                $completedCount++;
            }
        }
        ?>

        <div class="hero">
            <div class="eyebrow">Daily Overview</div>
            <h2 style="margin:8px 0 0;font-size:28px;">Your active walk operations</h2>

            <div class="hero-grid">
                <div class="stat-card">
                    <div class="stat-label">Assigned Walks</div>
                    <div class="stat-value"><?php echo $assignedCount; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?php echo $inProgressCount; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $completedCount; ?></div>
                </div>
            </div>
        </div>

        <h2 class="section-title">Your Walks</h2>

        <?php if (empty($walks)): ?>
            <div class="empty-state">
                No walks are currently assigned to your account.
            </div>
        <?php else: ?>
            <div class="walk-grid">
                <?php foreach ($walks as $walk): ?>
                    <div class="walk-card">
                        <div class="walk-card-top">
                            <div class="walk-main">
                                <h3><?php echo htmlspecialchars($walk['dog_name'] ?? 'Dog'); ?></h3>
                                <p>Client: <?php echo htmlspecialchars($walk['member_name'] ?? 'Member'); ?></p>
                            </div>

                            <div class="status-badge <?php echo statusClass($walk['status'] ?? ''); ?>">
                                <?php echo htmlspecialchars($walk['status'] ?? 'Unknown'); ?>
                            </div>
                        </div>

                        <div class="walk-meta">
                            <div class="meta-box">
                                <div class="meta-label">Walk Date</div>
                                <div class="meta-value"><?php echo htmlspecialchars($walk['walk_date'] ?? '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Walk Time</div>
                                <div class="meta-value"><?php echo htmlspecialchars($walk['walk_time'] ?? '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Duration</div>
                                <div class="meta-value">
                                    <?php echo isset($walk['duration_minutes']) ? (int)$walk['duration_minutes'] . ' mins' : '—'; ?>
                                </div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Walk ID</div>
                                <div class="meta-value">#<?php echo (int)$walk['id']; ?></div>
                            </div>
                        </div>

                        <?php if (!empty($walk['notes'])): ?>
                            <div class="notes">
                                <strong>Notes:</strong><br>
                                <?php echo nl2br(htmlspecialchars($walk['notes'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="actions">
                            <?php if (canTrack($walk['status'] ?? '')): ?>
                                <a
                                    href="live-tracking.php?walk_id=<?php echo (int)$walk['id']; ?>"
                                    class="button button-primary"
                                >
                                    <?php echo $walk['status'] === 'In Progress' ? 'Continue Live Tracking' : 'Start Live Tracking'; ?>
                                </a>
                            <?php endif; ?>

                            <a href="walker-dashboard.php" class="button button-secondary">
                                Refresh Dashboard
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>