<?php
declare(strict_types=1);

session_start();

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

function requestStatusClass(string $status): string
{
    return match ($status) {
        'reviewed'  => 'status reviewed',
        'approved'  => 'status approved',
        'converted' => 'status converted',
        'cancelled' => 'status cancelled',
        default     => 'status pending',
    };
}

$tableName = null;
if (tableExists($pdo, 'public_booking_requests')) {
    $tableName = 'public_booking_requests';
} elseif (tableExists($pdo, 'non_member_bookings')) {
    $tableName = 'non_member_bookings';
} elseif (tableExists($pdo, 'public_booking_submissions')) {
    $tableName = 'public_booking_submissions';
}

if ($tableName === null) {
    die('No non-member booking table was found.');
}

$requestColumns = getColumns($pdo, $tableName);

$allowedFilterStatuses = ['all', 'pending', 'reviewed', 'approved', 'converted', 'cancelled'];
$currentFilter = $_GET['status'] ?? 'all';
if (!in_array($currentFilter, $allowedFilterStatuses, true)) {
    $currentFilter = 'all';
}

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));

    if ($requestId <= 0 || !in_array($newStatus, ['pending', 'reviewed', 'approved', 'converted', 'cancelled'], true)) {
        header('Location: admin-non-member-bookings.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    if (hasColumn($requestColumns, 'status')) {
        $stmt = $pdo->prepare("
            UPDATE {$tableName}
            SET status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $newStatus,
            'id' => $requestId,
        ]);

        header('Location: admin-non-member-bookings.php?updated=1&status=' . urlencode($currentFilter));
        exit;
    }

    header('Location: admin-non-member-bookings.php?error=1&status=' . urlencode($currentFilter));
    exit;
}

if (isset($_GET['updated'])) {
    $flashMessage = 'Non-member booking updated successfully.';
} elseif (isset($_GET['error'])) {
    $flashMessage = 'Something went wrong while updating the non-member booking.';
    $flashType = 'error';
}

$whereSql = '';
$params = [];

if ($currentFilter !== 'all' && hasColumn($requestColumns, 'status')) {
    $whereSql = 'WHERE status = :status_filter';
    $params['status_filter'] = $currentFilter;
}

$summary = [
    'total' => 0,
    'pending' => 0,
    'reviewed' => 0,
    'approved' => 0,
    'converted' => 0,
    'cancelled' => 0,
];

if (hasColumn($requestColumns, 'status')) {
    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
        FROM {$tableName}
    ");

    if ($summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['total'] = (int) ($summaryRow['total_count'] ?? 0);
        $summary['pending'] = (int) ($summaryRow['pending_count'] ?? 0);
        $summary['reviewed'] = (int) ($summaryRow['reviewed_count'] ?? 0);
        $summary['approved'] = (int) ($summaryRow['approved_count'] ?? 0);
        $summary['converted'] = (int) ($summaryRow['converted_count'] ?? 0);
        $summary['cancelled'] = (int) ($summaryRow['cancelled_count'] ?? 0);
    }
} else {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM {$tableName}");
    $summary['total'] = (int) $countStmt->fetchColumn();
}

$selectParts = ['id'];
$optionalColumns = [
    'full_name',
    'name',
    'email',
    'phone',
    'pet_name',
    'pet_breed',
    'pet_size',
    'service_type',
    'service_date',
    'service_time',
    'duration_minutes',
    'feeding_schedule',
    'notes',
    'note',
    'price',
    'status',
    'created_at'
];

foreach ($optionalColumns as $column) {
    if (hasColumn($requestColumns, $column)) {
        $selectParts[] = $column;
    }
}

$sql = "
    SELECT " . implode(', ', $selectParts) . "
    FROM {$tableName}
    {$whereSql}
    ORDER BY " . (hasColumn($requestColumns, 'created_at') ? 'created_at DESC, ' : '') . "id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function firstNonEmpty(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!empty($row[$key])) {
            return (string) $row[$key];
        }
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Non-Member Bookings | Doggie Dorian’s Admin</title>
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
      --red: #ff9898;
      --blue: #66b3ff;
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
      max-width: 1460px;
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
      max-width: 760px;
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
      border: 1px solid rgba(94,211,154,0.3);
      color: #c8ffe2;
    }

    .flash.error {
      background: rgba(255,140,140,0.12);
      border: 1px solid rgba(255,140,140,0.28);
      color: #ffd0d0;
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
      min-width: 1420px;
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
      white-space: pre-wrap;
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

    .status.reviewed {
      background: rgba(102,179,255,0.18);
      color: #d8ecff;
    }

    .status.approved {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.converted {
      background: rgba(212,175,55,0.16);
      color: #f6de88;
    }

    .status.cancelled {
      background: rgba(255,140,140,0.15);
      color: #ffd0d0;
    }

    .actions {
      display: grid;
      gap: 8px;
    }

    .actions form {
      margin: 0;
    }

    .actions button {
      width: 100%;
      min-height: 34px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      font-weight: 800;
      cursor: pointer;
    }

    .actions button:hover {
      border-color: rgba(212,175,55,0.26);
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
        <h1>Non-Member Bookings</h1>
        <div class="subtext">
          Review public booking submissions, organize intake status, and keep non-member requests coordinated before conversion into live client workflows.
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
        <div class="card-label">Total Requests</div>
        <div class="card-value"><?php echo $summary['total']; ?></div>
        <div class="card-note">All non-member requests received</div>
      </div>

      <div class="card">
        <div class="card-label">Pending</div>
        <div class="card-value"><?php echo $summary['pending']; ?></div>
        <div class="card-note">New requests awaiting review</div>
      </div>

      <div class="card">
        <div class="card-label">Reviewed</div>
        <div class="card-value"><?php echo $summary['reviewed']; ?></div>
        <div class="card-note">Checked by admin</div>
      </div>

      <div class="card">
        <div class="card-label">Approved</div>
        <div class="card-value"><?php echo $summary['approved']; ?></div>
        <div class="card-note">Ready for next action</div>
      </div>

      <div class="card">
        <div class="card-label">Converted</div>
        <div class="card-value"><?php echo $summary['converted']; ?></div>
        <div class="card-note">Moved into core workflow</div>
      </div>

      <div class="card">
        <div class="card-label">Cancelled</div>
        <div class="card-value"><?php echo $summary['cancelled']; ?></div>
        <div class="card-note">Closed requests</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Request Queue</h2>
          <div class="panel-subtitle">
            Review incoming public bookings and update their stage in the intake process.
          </div>
        </div>

        <div class="filters">
          <a class="filter-pill <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=all">All</a>
          <a class="filter-pill <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=pending">Pending</a>
          <a class="filter-pill <?php echo $currentFilter === 'reviewed' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=reviewed">Reviewed</a>
          <a class="filter-pill <?php echo $currentFilter === 'approved' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=approved">Approved</a>
          <a class="filter-pill <?php echo $currentFilter === 'converted' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=converted">Converted</a>
          <a class="filter-pill <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" href="admin-non-member-bookings.php?status=cancelled">Cancelled</a>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$requests): ?>
          <div class="empty-state">
            <strong>No non-member bookings found</strong>
            There are no requests in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Pet</th>
                <th>Service</th>
                <th>Schedule</th>
                <th>Price</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <?php
                  $clientName = firstNonEmpty($request, ['full_name', 'name']);
                  $requestNotes = firstNonEmpty($request, ['notes', 'note']);
                ?>
                <tr>
                  <td>
                    <div class="primary-text">#<?php echo (int) $request['id']; ?></div>
                    <div class="secondary-text"><?php echo e($request['created_at'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($clientName ?: 'Unknown client'); ?></div>
                    <div class="secondary-text">
                      <?php echo e($request['email'] ?? ''); ?><br>
                      <?php echo e($request['phone'] ?? ''); ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($request['pet_name'] ?? ''); ?></div>
                    <div class="secondary-text">
                      <?php echo e($request['pet_breed'] ?? ''); ?>
                      <?php if (!empty($request['pet_size'])): ?>
                        <br><?php echo e($request['pet_size']); ?>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e(ucfirst((string) ($request['service_type'] ?? ''))); ?></div>
                    <div class="secondary-text">
                      <?php
                        $duration = $request['duration_minutes'] ?? null;
                        echo $duration ? e((string) $duration . ' min') : 'No duration';
                      ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($request['service_date'] ?? ''); ?></div>
                    <div class="secondary-text"><?php echo e($request['service_time'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="primary-text">$<?php echo number_format((float) ($request['price'] ?? 0), 2); ?></div>
                  </td>

                  <td>
                    <div class="secondary-text"><?php echo e($request['feeding_schedule'] ?? ''); ?></div>
                    <?php if ($requestNotes !== ''): ?>
                      <div class="secondary-text" style="margin-top:8px;"><?php echo e($requestNotes); ?></div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="<?php echo e(requestStatusClass((string) ($request['status'] ?? 'pending'))); ?>">
                      <?php echo e((string) ($request['status'] ?? 'pending')); ?>
                    </span>
                  </td>

                  <td>
                    <div class="actions">
                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="new_status" value="reviewed">
                        <button type="submit">Mark Reviewed</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="new_status" value="approved">
                        <button type="submit">Approve</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="new_status" value="converted">
                        <button type="submit">Mark Converted</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="new_status" value="cancelled">
                        <button type="submit">Cancel</button>
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