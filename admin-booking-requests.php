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
        'approved' => 'status approved',
        'declined' => 'status declined',
        default => 'status pending',
    };
}

if (!tableExists($pdo, 'booking_change_requests')) {
    die('booking_change_requests table not found.');
}

$requestColumns = getColumns($pdo, 'booking_change_requests');
$bookingColumns = tableExists($pdo, 'bookings') ? getColumns($pdo, 'bookings') : [];
$userColumns = tableExists($pdo, 'users') ? getColumns($pdo, 'users') : [];

$allowedFilterStatuses = ['all', 'Pending', 'Approved', 'Declined'];
$currentFilter = $_GET['status'] ?? 'all';
if (!in_array($currentFilter, $allowedFilterStatuses, true)) {
    $currentFilter = 'all';
}

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = trim((string) ($_POST['action_type'] ?? ''));

    if ($requestId <= 0 || !in_array($action, ['approve', 'decline'], true)) {
        header('Location: admin-booking-requests.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    $selectParts = ['id'];
    $neededRequestCols = [
        'booking_id',
        'request_type',
        'requested_service_date',
        'requested_service_time',
        'status'
    ];
    foreach ($neededRequestCols as $col) {
        if (hasColumn($requestColumns, $col)) {
            $selectParts[] = $col;
        }
    }

    $reqStmt = $pdo->prepare("
        SELECT " . implode(', ', $selectParts) . "
        FROM booking_change_requests
        WHERE id = :id
        LIMIT 1
    ");
    $reqStmt->execute(['id' => $requestId]);
    $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        header('Location: admin-booking-requests.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    if ($action === 'approve') {
        if (
            tableExists($pdo, 'bookings') &&
            !empty($request['booking_id']) &&
            isset($request['request_type'])
        ) {
            $bookingId = (int) $request['booking_id'];
            $requestType = strtolower((string) $request['request_type']);

            if ($bookingId > 0) {
                $updateParts = [];
                $params = ['id' => $bookingId];

                if ($requestType === 'cancel' || $requestType === 'cancellation') {
                    if (hasColumn($bookingColumns, 'status')) {
                        $updateParts[] = 'status = :booking_status';
                        $params['booking_status'] = 'cancelled';
                    }
                }

                if ($requestType === 'reschedule') {
                    if (hasColumn($bookingColumns, 'service_date') && !empty($request['requested_service_date'])) {
                        $updateParts[] = 'service_date = :service_date';
                        $params['service_date'] = $request['requested_service_date'];
                    }

                    if (hasColumn($bookingColumns, 'service_time') && !empty($request['requested_service_time'])) {
                        $updateParts[] = 'service_time = :service_time';
                        $params['service_time'] = $request['requested_service_time'];
                    }

                    if (hasColumn($bookingColumns, 'status')) {
                        $updateParts[] = 'status = :booking_status';
                        $params['booking_status'] = 'confirmed';
                    }
                }

                if (hasColumn($bookingColumns, 'status_updated_by')) {
                    $updateParts[] = 'status_updated_by = :status_updated_by';
                    $params['status_updated_by'] = 'admin';
                }

                if (hasColumn($bookingColumns, 'status_updated_at')) {
                    $updateParts[] = 'status_updated_at = CURRENT_TIMESTAMP';
                }

                if ($updateParts) {
                    $bookingUpdate = $pdo->prepare("
                        UPDATE bookings
                        SET " . implode(', ', $updateParts) . "
                        WHERE id = :id
                    ");
                    foreach ($params as $key => $value) {
                        $placeholder = ':' . $key;
                        if (is_int($value)) {
                            $bookingUpdate->bindValue($placeholder, $value, PDO::PARAM_INT);
                        } else {
                            $bookingUpdate->bindValue($placeholder, $value);
                        }
                    }
                    $bookingUpdate->execute();
                }
            }
        }

        $statusValue = 'Approved';
    } else {
        $statusValue = 'Declined';
    }

    if (hasColumn($requestColumns, 'status')) {
        $updateReq = $pdo->prepare("
            UPDATE booking_change_requests
            SET status = :status
            WHERE id = :id
        ");
        $updateReq->execute([
            'status' => $statusValue,
            'id' => $requestId,
        ]);
    }

    header('Location: admin-booking-requests.php?updated=1&status=' . urlencode($currentFilter));
    exit;
}

if (isset($_GET['updated'])) {
    $flashMessage = 'Booking request updated successfully.';
} elseif (isset($_GET['error'])) {
    $flashMessage = 'Something went wrong while updating the booking request.';
    $flashType = 'error';
}

$whereSql = '';
$params = [];

if ($currentFilter !== 'all' && hasColumn($requestColumns, 'status')) {
    $whereSql = 'WHERE r.status = :status_filter';
    $params['status_filter'] = $currentFilter;
}

$userLabelSql = "'Unknown user'";
if ($userColumns) {
    if (hasColumn($userColumns, 'email')) {
        $userLabelSql = "COALESCE(u.email, 'Unknown user')";
    } elseif (hasColumn($userColumns, 'name')) {
        $userLabelSql = "COALESCE(u.name, 'Unknown user')";
    } elseif (hasColumn($userColumns, 'full_name')) {
        $userLabelSql = "COALESCE(u.full_name, 'Unknown user')";
    } elseif (hasColumn($userColumns, 'username')) {
        $userLabelSql = "COALESCE(u.username, 'Unknown user')";
    }
}

$selectParts = ['r.id'];
$optionalRequestCols = [
    'booking_id',
    'user_id',
    'request_type',
    'current_service_date',
    'current_service_time',
    'requested_service_date',
    'requested_service_time',
    'note',
    'status',
    'created_at'
];

foreach ($optionalRequestCols as $col) {
    if (hasColumn($requestColumns, $col)) {
        $selectParts[] = 'r.' . $col;
    }
}

$joins = [];
if (tableExists($pdo, 'users') && hasColumn($requestColumns, 'user_id')) {
    $selectParts[] = $userLabelSql . ' AS user_label';
    $joins[] = 'LEFT JOIN users u ON u.id = r.user_id';
}

$sql = "
    SELECT " . implode(', ', $selectParts) . "
    FROM booking_change_requests r
    " . implode(' ', $joins) . "
    $whereSql
    ORDER BY " . (hasColumn($requestColumns, 'created_at') ? 'r.created_at DESC, ' : '') . "r.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'declined' => 0,
];

if (hasColumn($requestColumns, 'status')) {
    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'Declined' THEN 1 ELSE 0 END) AS declined_count
        FROM booking_change_requests
    ");
    if ($summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['total'] = (int) ($summaryRow['total_count'] ?? 0);
        $summary['pending'] = (int) ($summaryRow['pending_count'] ?? 0);
        $summary['approved'] = (int) ($summaryRow['approved_count'] ?? 0);
        $summary['declined'] = (int) ($summaryRow['declined_count'] ?? 0);
    }
} else {
    $summary['total'] = count($requests);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Requests | Doggie Dorian’s Admin</title>
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
      max-width: 1440px;
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
      grid-template-columns: repeat(4, minmax(0, 1fr));
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
      min-width: 1320px;
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

    .status.approved {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.declined {
      background: rgba(255,152,152,0.15);
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
      min-height: 36px;
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

    @media (max-width: 1100px) {
      .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
        <h1>Booking Requests</h1>
        <div class="subtext">
          Review reschedule and cancellation requests submitted by members, then approve or decline them while keeping the main bookings table in sync.
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
        <div class="card-note">All change requests received</div>
      </div>

      <div class="card">
        <div class="card-label">Pending</div>
        <div class="card-value"><?php echo $summary['pending']; ?></div>
        <div class="card-note">Requests awaiting review</div>
      </div>

      <div class="card">
        <div class="card-label">Approved</div>
        <div class="card-value"><?php echo $summary['approved']; ?></div>
        <div class="card-note">Requests approved by admin</div>
      </div>

      <div class="card">
        <div class="card-label">Declined</div>
        <div class="card-value"><?php echo $summary['declined']; ?></div>
        <div class="card-note">Requests declined by admin</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Request Queue</h2>
          <div class="panel-subtitle">
            Review and resolve incoming booking changes without leaving the admin workflow.
          </div>
        </div>

        <div class="filters">
          <a class="filter-pill <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=all">All</a>
          <a class="filter-pill <?php echo $currentFilter === 'Pending' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=Pending">Pending</a>
          <a class="filter-pill <?php echo $currentFilter === 'Approved' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=Approved">Approved</a>
          <a class="filter-pill <?php echo $currentFilter === 'Declined' ? 'active' : ''; ?>" href="admin-booking-requests.php?status=Declined">Declined</a>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$requests): ?>
          <div class="empty-state">
            <strong>No booking requests found</strong>
            There are no requests in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Member</th>
                <th>Booking</th>
                <th>Request Type</th>
                <th>Current Schedule</th>
                <th>Requested Schedule</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <?php
                  $statusValue = strtolower((string) ($request['status'] ?? 'Pending'));
                ?>
                <tr>
                  <td>
                    <div class="primary-text">#<?php echo (int) $request['id']; ?></div>
                    <div class="secondary-text"><?php echo e($request['created_at'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($request['user_label'] ?? 'Unknown user'); ?></div>
                    <div class="secondary-text">User ID: <?php echo (int) ($request['user_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text">Booking #<?php echo (int) ($request['booking_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e(ucfirst((string) ($request['request_type'] ?? 'Request'))); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($request['current_service_date'] ?? ''); ?></div>
                    <div class="secondary-text"><?php echo e($request['current_service_time'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($request['requested_service_date'] ?? ''); ?></div>
                    <div class="secondary-text"><?php echo e($request['requested_service_time'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="secondary-text"><?php echo e($request['note'] ?? ''); ?></div>
                  </td>

                  <td>
                    <span class="<?php echo e(requestStatusClass($statusValue)); ?>">
                      <?php echo e((string) ($request['status'] ?? 'Pending')); ?>
                    </span>
                  </td>

                  <td>
                    <div class="actions">
                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="action_type" value="approve">
                        <button type="submit">Approve</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <input type="hidden" name="action_type" value="decline">
                        <button type="submit">Decline</button>
                      </form>

                      <a href="admin-edit-booking.php?id=<?php echo (int) ($request['booking_id'] ?? 0); ?>" class="top-btn" style="min-height:36px; padding:0 14px; border-radius:10px; justify-content:center;">
                        Edit Booking
                      </a>
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