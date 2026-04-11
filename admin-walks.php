<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/database/setup.php';
require_once __DIR__ . '/admin-auth.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    $columns = [];
    $stmt = $pdo->query("PRAGMA table_info($table)");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
        }
    }
    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function walkStatusClass(string $status): string
{
    return match ($status) {
        'confirmed'   => 'status confirmed',
        'in_progress' => 'status in-progress',
        'completed'   => 'status completed',
        'cancelled'   => 'status cancelled',
        default       => 'status pending',
    };
}

if (!tableExists($pdo, 'bookings')) {
    die('Bookings table not found.');
}

$bookingColumns = getColumns($pdo, 'bookings');
$userColumns = tableExists($pdo, 'users') ? getColumns($pdo, 'users') : [];
$petColumns = tableExists($pdo, 'pets') ? getColumns($pdo, 'pets') : [];

$allowedFilters = ['all', 'pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'unassigned'];
$currentFilter = $_GET['status'] ?? 'all';
if (!in_array($currentFilter, $allowedFilters, true)) {
    $currentFilter = 'all';
}

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));

    if ($bookingId > 0 && in_array($newStatus, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'], true) && hasColumn($bookingColumns, 'status')) {
        $updateParts = ['status = :status'];
        $params = [
            'status' => $newStatus,
            'id' => $bookingId,
        ];

        if (hasColumn($bookingColumns, 'status_updated_by')) {
            $updateParts[] = 'status_updated_by = :status_updated_by';
            $params['status_updated_by'] = 'admin';
        }

        if (hasColumn($bookingColumns, 'status_updated_at')) {
            $updateParts[] = 'status_updated_at = CURRENT_TIMESTAMP';
        }

        $stmt = $pdo->prepare("
            UPDATE bookings
            SET " . implode(', ', $updateParts) . "
            WHERE id = :id
        ");
        $stmt->execute($params);

        header('Location: admin-walks.php?updated=1&status=' . urlencode($currentFilter) . '&highlight=' . $bookingId);
        exit;
    }

    header('Location: admin-walks.php?error=1&status=' . urlencode($currentFilter));
    exit;
}

if (isset($_GET['updated'])) {
    $flashMessage = 'Walk status updated successfully.';
} elseif (isset($_GET['error'])) {
    $flashMessage = 'Something went wrong while updating the walk.';
    $flashType = 'error';
}

$highlightId = isset($_GET['highlight']) ? (int) $_GET['highlight'] : 0;

$whereClauses = [];
$params = [];

if (hasColumn($bookingColumns, 'service_type')) {
    $whereClauses[] = "b.service_type = 'walk'";
}

if ($currentFilter === 'unassigned') {
    if (hasColumn($bookingColumns, 'assigned_walker_id')) {
        $whereClauses[] = '(b.assigned_walker_id IS NULL OR b.assigned_walker_id = 0)';
    } elseif (hasColumn($bookingColumns, 'walker_name')) {
        $whereClauses[] = "(b.walker_name IS NULL OR b.walker_name = '')";
    }
} elseif ($currentFilter !== 'all' && hasColumn($bookingColumns, 'status')) {
    $whereClauses[] = 'b.status = :status_filter';
    $params['status_filter'] = $currentFilter;
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$userLabelSql = "'Unknown client'";
if ($userColumns) {
    if (hasColumn($userColumns, 'email')) {
        $userLabelSql = "COALESCE(u.email, 'Unknown client')";
    } elseif (hasColumn($userColumns, 'name')) {
        $userLabelSql = "COALESCE(u.name, 'Unknown client')";
    } elseif (hasColumn($userColumns, 'full_name')) {
        $userLabelSql = "COALESCE(u.full_name, 'Unknown client')";
    } elseif (hasColumn($userColumns, 'username')) {
        $userLabelSql = "COALESCE(u.username, 'Unknown client')";
    }
}

$selectParts = ['b.id'];
$optionalBookingColumns = [
    'user_id',
    'pet_id',
    'service_type',
    'service_date',
    'service_time',
    'duration_minutes',
    'status',
    'price',
    'walker_name',
    'assigned_walker_id'
];

foreach ($optionalBookingColumns as $column) {
    if (hasColumn($bookingColumns, $column)) {
        $selectParts[] = 'b.' . $column;
    }
}

$joins = [];

if (tableExists($pdo, 'users') && hasColumn($bookingColumns, 'user_id')) {
    $selectParts[] = $userLabelSql . ' AS client_label';
    $joins[] = 'LEFT JOIN users u ON u.id = b.user_id';
}

if (tableExists($pdo, 'pets') && hasColumn($bookingColumns, 'pet_id') && hasColumn($petColumns, 'id')) {
    if (hasColumn($petColumns, 'pet_name')) {
        $selectParts[] = 'p.pet_name AS pet_name';
    }
    if (hasColumn($petColumns, 'breed')) {
        $selectParts[] = 'p.breed AS pet_breed';
    }
    if (hasColumn($petColumns, 'size')) {
        $selectParts[] = 'p.size AS pet_size';
    }
    $joins[] = 'LEFT JOIN pets p ON p.id = b.pet_id';
}

$sql = "
    SELECT " . implode(', ', $selectParts) . "
    FROM bookings b
    " . implode(' ', $joins) . "
    {$whereSql}
    ORDER BY " . (hasColumn($bookingColumns, 'service_date') ? 'b.service_date DESC, ' : '') . "b.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$walks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'total' => 0,
    'unassigned' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'in_progress' => 0,
    'completed' => 0,
];

$summaryWhere = hasColumn($bookingColumns, 'service_type') ? "WHERE service_type = 'walk'" : '';
$summarySql = "
    SELECT
        COUNT(*) AS total_count," .
        (hasColumn($bookingColumns, 'assigned_walker_id')
            ? " SUM(CASE WHEN assigned_walker_id IS NULL OR assigned_walker_id = 0 THEN 1 ELSE 0 END) AS unassigned_count,"
            : (hasColumn($bookingColumns, 'walker_name')
                ? " SUM(CASE WHEN walker_name IS NULL OR walker_name = '' THEN 1 ELSE 0 END) AS unassigned_count,"
                : " 0 AS unassigned_count,")) .
        (hasColumn($bookingColumns, 'status')
            ? "
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        "
            : "
        0 AS pending_count,
        0 AS confirmed_count,
        0 AS in_progress_count,
        0 AS completed_count
        ") . "
    FROM bookings
    {$summaryWhere}
";

$summaryStmt = $pdo->query($summarySql);
if ($summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC)) {
    $summary['total'] = (int) ($summaryRow['total_count'] ?? 0);
    $summary['unassigned'] = (int) ($summaryRow['unassigned_count'] ?? 0);
    $summary['pending'] = (int) ($summaryRow['pending_count'] ?? 0);
    $summary['confirmed'] = (int) ($summaryRow['confirmed_count'] ?? 0);
    $summary['in_progress'] = (int) ($summaryRow['in_progress_count'] ?? 0);
    $summary['completed'] = (int) ($summaryRow['completed_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Walk Operations | Doggie Dorian’s Admin</title>
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --green: #5ed39a;
      --blue: #66b3ff;
      --red: #ff9898;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    .wrap {
      max-width: 1480px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 26px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 42px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 780px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .flash {
      margin-bottom: 22px;
      padding: 15px 18px;
      border-radius: 16px;
      font-weight: 700;
      box-shadow: var(--shadow);
    }

    .flash.success {
      background: rgba(94,211,154,0.12);
      border: 1px solid rgba(94,211,154,0.30);
      color: #c8ffe2;
    }

    .flash.error {
      background: rgba(255,152,152,0.12);
      border: 1px solid rgba(255,152,152,0.28);
      color: #ffd7d7;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border: 1px solid var(--line);
      border-radius: 20px;
      padding: 20px;
      box-shadow: var(--shadow);
    }

    .card-label {
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .card-value {
      font-size: 34px;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .card-note {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 22px 22px 18px;
      border-bottom: 1px solid var(--line);
      flex-wrap: wrap;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
    }

    .panel-title {
      font-size: 24px;
      font-weight: 800;
      margin: 0;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-top: 6px;
    }

    .filters {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .filter-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 0 16px;
      border-radius: 999px;
      text-decoration: none;
      color: var(--text);
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      font-weight: 700;
      font-size: 14px;
    }

    .filter-pill.active {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1520px;
    }

    thead th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      text-align: left;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      background: rgba(255,255,255,0.01);
    }

    tbody td {
      padding: 18px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
    }

    tbody tr:hover {
      background: rgba(255,255,255,0.015);
    }

    tbody tr.highlight-row {
      background: rgba(212,175,55,0.08);
      box-shadow: inset 4px 0 0 var(--gold);
    }

    .primary-text {
      font-weight: 700;
      font-size: 15px;
      color: var(--text);
    }

    .secondary-text {
      margin-top: 6px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .status {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 12px;
      font-weight: 800;
      text-transform: capitalize;
      letter-spacing: 0.04em;
    }

    .status.pending {
      background: rgba(143,149,163,0.18);
      color: #e5e7ee;
    }

    .status.confirmed {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.in-progress {
      background: rgba(102,179,255,0.18);
      color: #d8ecff;
    }

    .status.completed {
      background: rgba(212,175,55,0.16);
      color: #f6de88;
    }

    .status.cancelled {
      background: rgba(255,152,152,0.15);
      color: #ffd7d7;
    }

    .actions {
      display: grid;
      gap: 8px;
    }

    .actions form {
      margin: 0;
    }

    .actions button,
    .actions a {
      width: 100%;
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 12px;
    }

    .actions button:hover,
    .actions a:hover {
      border-color: rgba(212,175,55,0.26);
    }

    .actions a.track {
      background: rgba(102,179,255,0.12);
      border-color: rgba(102,179,255,0.24);
      color: #d8ecff;
    }

    .actions a.client-track {
      background: rgba(94,211,154,0.12);
      border-color: rgba(94,211,154,0.24);
      color: #c8ffe2;
    }

    .empty-state {
      padding: 44px 22px;
      text-align: center;
      color: var(--muted);
    }

    .empty-state strong {
      display: block;
      color: var(--text);
      font-size: 18px;
      margin-bottom: 10px;
    }

    @media (max-width: 1200px) {
      .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 700px) {
      .summary-grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 32px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Walk Operations</h1>
        <div class="subtext">
          Manage scheduled walks, track assignment status, move services through the live workflow, and launch GPS tracking directly from this page.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-bookings.php" class="top-btn">Main Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
      <div class="flash <?php echo e($flashType); ?>">
        <?php echo e($flashMessage); ?>
      </div>
    <?php endif; ?>

    <div class="summary-grid">
      <div class="card">
        <div class="card-label">Total Walks</div>
        <div class="card-value"><?php echo $summary['total']; ?></div>
        <div class="card-note">All walk bookings in system</div>
      </div>

      <div class="card">
        <div class="card-label">Unassigned</div>
        <div class="card-value"><?php echo $summary['unassigned']; ?></div>
        <div class="card-note">Walks with no walker assigned</div>
      </div>

      <div class="card">
        <div class="card-label">Pending</div>
        <div class="card-value"><?php echo $summary['pending']; ?></div>
        <div class="card-note">Awaiting confirmation</div>
      </div>

      <div class="card">
        <div class="card-label">Confirmed</div>
        <div class="card-value"><?php echo $summary['confirmed']; ?></div>
        <div class="card-note">Ready to begin</div>
      </div>

      <div class="card">
        <div class="card-label">In Progress</div>
        <div class="card-value"><?php echo $summary['in_progress']; ?></div>
        <div class="card-note">Currently active walks</div>
      </div>

      <div class="card">
        <div class="card-label">Completed</div>
        <div class="card-value"><?php echo $summary['completed']; ?></div>
        <div class="card-note">Finished walk services</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Walk Queue</h2>
          <div class="panel-subtitle">
            Keep your walking operation coordinated and launch live tracking directly when needed.
          </div>
        </div>

        <div class="filters">
          <a class="filter-pill <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="admin-walks.php?status=all">All</a>
          <a class="filter-pill <?php echo $currentFilter === 'unassigned' ? 'active' : ''; ?>" href="admin-walks.php?status=unassigned">Unassigned</a>
          <a class="filter-pill <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" href="admin-walks.php?status=pending">Pending</a>
          <a class="filter-pill <?php echo $currentFilter === 'confirmed' ? 'active' : ''; ?>" href="admin-walks.php?status=confirmed">Confirmed</a>
          <a class="filter-pill <?php echo $currentFilter === 'in_progress' ? 'active' : ''; ?>" href="admin-walks.php?status=in_progress">In Progress</a>
          <a class="filter-pill <?php echo $currentFilter === 'completed' ? 'active' : ''; ?>" href="admin-walks.php?status=completed">Completed</a>
          <a class="filter-pill <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" href="admin-walks.php?status=cancelled">Cancelled</a>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$walks): ?>
          <div class="empty-state">
            <strong>No walks found</strong>
            There are no walk bookings in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Pet</th>
                <th>Schedule</th>
                <th>Status</th>
                <th>Walker</th>
                <th>Price</th>
                <th>Tracking</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($walks as $walk): ?>
                <tr class="<?php echo ((int) ($walk['id'] ?? 0) === $highlightId) ? 'highlight-row' : ''; ?>">
                  <td>
                    <div class="primary-text">#<?php echo (int) ($walk['id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['client_label'] ?? 'Unknown client'); ?></div>
                    <div class="secondary-text">User ID: <?php echo (int) ($walk['user_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['pet_name'] ?? 'Pet not found'); ?></div>
                    <div class="secondary-text">
                      <?php
                        $petBits = [];
                        if (!empty($walk['pet_breed'])) {
                            $petBits[] = $walk['pet_breed'];
                        }
                        if (!empty($walk['pet_size'])) {
                            $petBits[] = $walk['pet_size'];
                        }
                        echo e($petBits ? implode(' • ', $petBits) : 'Pet ID: ' . (int) ($walk['pet_id'] ?? 0));
                      ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['service_date'] ?? 'No date'); ?></div>
                    <div class="secondary-text">
                      <?php
                        $sched = [];
                        if (!empty($walk['service_time'])) {
                            $sched[] = $walk['service_time'];
                        }
                        if (isset($walk['duration_minutes']) && $walk['duration_minutes'] !== null) {
                            $sched[] = (int) $walk['duration_minutes'] . ' min';
                        }
                        echo e($sched ? implode(' • ', $sched) : 'No schedule details');
                      ?>
                    </div>
                  </td>

                  <td>
                    <span class="<?php echo e(walkStatusClass((string) ($walk['status'] ?? 'pending'))); ?>">
                      <?php echo e((string) ($walk['status'] ?? 'pending')); ?>
                    </span>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['walker_name'] ?? 'Not assigned'); ?></div>
                    <div class="secondary-text">
                      <?php if (!empty($walk['assigned_walker_id'])): ?>
                        Walker ID: <?php echo (int) $walk['assigned_walker_id']; ?>
                      <?php else: ?>
                        Assignment needed
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text">$<?php echo number_format((float) ($walk['price'] ?? 0), 2); ?></div>
                  </td>

                  <td>
                    <div class="actions">
                      <a class="track" href="live-tracking.php?booking_id=<?php echo (int) ($walk['id'] ?? 0); ?>" target="_blank" rel="noopener noreferrer">
                        Open Walker Track
                      </a>
                      <a class="client-track" href="client-map.php?booking_id=<?php echo (int) ($walk['id'] ?? 0); ?>" target="_blank" rel="noopener noreferrer">
                        Open Client Map
                      </a>
                    </div>
                  </td>

                  <td>
                    <div class="actions">
                      <a href="admin-assign-walker.php?id=<?php echo (int) ($walk['id'] ?? 0); ?>">Assign Walker</a>
                      <a href="admin-edit-booking.php?id=<?php echo (int) ($walk['id'] ?? 0); ?>">Edit Walk</a>

                      <form method="post">
                        <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                        <input type="hidden" name="new_status" value="confirmed">
                        <button type="submit">Confirm</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                        <input type="hidden" name="new_status" value="in_progress">
                        <button type="submit">Start Walk</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                        <input type="hidden" name="new_status" value="completed">
                        <button type="submit">Complete Walk</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>