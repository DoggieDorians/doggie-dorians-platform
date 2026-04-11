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

if (!tableExists($pdo, 'bookings')) {
    die('Bookings table not found.');
}

$bookingColumns = getColumns($pdo, 'bookings');

if (!hasColumn($bookingColumns, 'price')) {
    die('Bookings table is missing price column.');
}

$statusColumn = hasColumn($bookingColumns, 'status');
$serviceTypeColumn = hasColumn($bookingColumns, 'service_type');
$serviceDateColumn = hasColumn($bookingColumns, 'service_date');

$summary = [
    'gross_revenue' => 0.0,
    'completed_revenue' => 0.0,
    'pending_revenue' => 0.0,
    'confirmed_revenue' => 0.0,
    'cancelled_revenue' => 0.0,
    'booking_count' => 0,
    'completed_count' => 0,
];

$summarySql = "
    SELECT
        COUNT(*) AS booking_count,
        COALESCE(SUM(price), 0) AS gross_revenue," .
        ($statusColumn ? "
        COALESCE(SUM(CASE WHEN status = 'completed' THEN price ELSE 0 END), 0) AS completed_revenue,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN price ELSE 0 END), 0) AS pending_revenue,
        COALESCE(SUM(CASE WHEN status = 'confirmed' THEN price ELSE 0 END), 0) AS confirmed_revenue,
        COALESCE(SUM(CASE WHEN status = 'cancelled' THEN price ELSE 0 END), 0) AS cancelled_revenue,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        " : "
        0 AS completed_revenue,
        0 AS pending_revenue,
        0 AS confirmed_revenue,
        0 AS cancelled_revenue,
        0 AS completed_count
        ") . "
    FROM bookings
";

$summaryStmt = $pdo->query($summarySql);
if ($summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC)) {
    $summary['gross_revenue'] = (float) ($summaryRow['gross_revenue'] ?? 0);
    $summary['completed_revenue'] = (float) ($summaryRow['completed_revenue'] ?? 0);
    $summary['pending_revenue'] = (float) ($summaryRow['pending_revenue'] ?? 0);
    $summary['confirmed_revenue'] = (float) ($summaryRow['confirmed_revenue'] ?? 0);
    $summary['cancelled_revenue'] = (float) ($summaryRow['cancelled_revenue'] ?? 0);
    $summary['booking_count'] = (int) ($summaryRow['booking_count'] ?? 0);
    $summary['completed_count'] = (int) ($summaryRow['completed_count'] ?? 0);
}

$serviceBreakdown = [];
if ($serviceTypeColumn) {
    $serviceStmt = $pdo->query("
        SELECT
            service_type,
            COUNT(*) AS booking_count,
            COALESCE(SUM(price), 0) AS total_revenue
        FROM bookings
        GROUP BY service_type
        ORDER BY total_revenue DESC, booking_count DESC
    ");
    $serviceBreakdown = $serviceStmt ? $serviceStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$statusBreakdown = [];
if ($statusColumn) {
    $statusStmt = $pdo->query("
        SELECT
            status,
            COUNT(*) AS booking_count,
            COALESCE(SUM(price), 0) AS total_revenue
        FROM bookings
        GROUP BY status
        ORDER BY total_revenue DESC, booking_count DESC
    ");
    $statusBreakdown = $statusStmt ? $statusStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$dailyBreakdown = [];
if ($serviceDateColumn) {
    $dailyStmt = $pdo->query("
        SELECT
            service_date,
            COUNT(*) AS booking_count,
            COALESCE(SUM(price), 0) AS total_revenue
        FROM bookings
        GROUP BY service_date
        ORDER BY service_date DESC
        LIMIT 20
    ");
    $dailyBreakdown = $dailyStmt ? $dailyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$recentRevenueRows = [];
$recentSelect = ['id', 'price'];

if ($serviceTypeColumn) {
    $recentSelect[] = 'service_type';
}
if ($serviceDateColumn) {
    $recentSelect[] = 'service_date';
}
if ($statusColumn) {
    $recentSelect[] = 'status';
}
if (hasColumn($bookingColumns, 'walker_name')) {
    $recentSelect[] = 'walker_name';
}

$recentStmt = $pdo->query("
    SELECT " . implode(', ', $recentSelect) . "
    FROM bookings
    ORDER BY " . ($serviceDateColumn ? 'service_date DESC, ' : '') . "id DESC
    LIMIT 15
");
$recentRevenueRows = $recentStmt ? $recentStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Revenue Dashboard | Doggie Dorian’s Admin</title>
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
      line-height: 1.5;
    }

    .layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 22px;
      margin-bottom: 24px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-inner {
      padding: 22px;
    }

    .panel-title {
      font-size: 24px;
      font-weight: 800;
      margin: 0 0 8px;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-bottom: 20px;
      line-height: 1.6;
    }

    .list {
      display: grid;
      gap: 12px;
    }

    .list-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(255,255,255,0.03);
      border: 1px solid var(--line);
    }

    .list-left strong {
      display: block;
      font-size: 15px;
      color: var(--text);
      margin-bottom: 4px;
    }

    .list-left span {
      color: var(--muted);
      font-size: 13px;
    }

    .list-right {
      text-align: right;
      flex-shrink: 0;
    }

    .list-right strong {
      display: block;
      color: var(--gold);
      font-size: 16px;
    }

    .list-right span {
      color: var(--muted);
      font-size: 12px;
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 980px;
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
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: capitalize;
      background: rgba(255,255,255,0.06);
      color: var(--text);
    }

    .empty-state {
      padding: 36px 20px;
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

      .layout {
        grid-template-columns: 1fr;
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
        <h1>Revenue Dashboard</h1>
        <div class="subtext">
          Track booked revenue, completed revenue, service mix, and daily production using the same coordinated bookings data powering the rest of the admin system.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-bookings.php" class="top-btn">Main Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <div class="summary-grid">
      <div class="card">
        <div class="card-label">Gross Revenue</div>
        <div class="card-value">$<?php echo number_format($summary['gross_revenue'], 2); ?></div>
        <div class="card-note">All bookings combined</div>
      </div>

      <div class="card">
        <div class="card-label">Completed Revenue</div>
        <div class="card-value">$<?php echo number_format($summary['completed_revenue'], 2); ?></div>
        <div class="card-note">Revenue from completed services only</div>
      </div>

      <div class="card">
        <div class="card-label">Pending Revenue</div>
        <div class="card-value">$<?php echo number_format($summary['pending_revenue'], 2); ?></div>
        <div class="card-note">Still awaiting fulfillment</div>
      </div>

      <div class="card">
        <div class="card-label">Confirmed Revenue</div>
        <div class="card-value">$<?php echo number_format($summary['confirmed_revenue'], 2); ?></div>
        <div class="card-note">Upcoming scheduled revenue</div>
      </div>

      <div class="card">
        <div class="card-label">Cancelled Revenue</div>
        <div class="card-value">$<?php echo number_format($summary['cancelled_revenue'], 2); ?></div>
        <div class="card-note">Value tied to cancelled bookings</div>
      </div>

      <div class="card">
        <div class="card-label">Completed Jobs</div>
        <div class="card-value"><?php echo $summary['completed_count']; ?></div>
        <div class="card-note"><?php echo $summary['booking_count']; ?> total bookings in system</div>
      </div>
    </div>

    <div class="layout">
      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Revenue by Service</h2>
          <div class="panel-subtitle">
            See which service types are producing the most revenue and booking volume.
          </div>

          <?php if (!$serviceBreakdown): ?>
            <div class="empty-state">
              <strong>No service revenue yet</strong>
              No service breakdown is available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($serviceBreakdown as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?php echo e(ucfirst((string) ($row['service_type'] ?? 'Service'))); ?></strong>
                    <span><?php echo (int) ($row['booking_count'] ?? 0); ?> bookings</span>
                  </div>
                  <div class="list-right">
                    <strong>$<?php echo number_format((float) ($row['total_revenue'] ?? 0), 2); ?></strong>
                    <span>Total revenue</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Revenue by Status</h2>
          <div class="panel-subtitle">
            Understand how much revenue sits in each booking stage.
          </div>

          <?php if (!$statusBreakdown): ?>
            <div class="empty-state">
              <strong>No status revenue yet</strong>
              No status breakdown is available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($statusBreakdown as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?php echo e(ucfirst((string) ($row['status'] ?? 'Status'))); ?></strong>
                    <span><?php echo (int) ($row['booking_count'] ?? 0); ?> bookings</span>
                  </div>
                  <div class="list-right">
                    <strong>$<?php echo number_format((float) ($row['total_revenue'] ?? 0), 2); ?></strong>
                    <span>Total revenue</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="layout">
      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Daily Revenue Snapshot</h2>
          <div class="panel-subtitle">
            Recent revenue grouped by service date.
          </div>

          <?php if (!$dailyBreakdown): ?>
            <div class="empty-state">
              <strong>No daily revenue yet</strong>
              No dated booking revenue is available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($dailyBreakdown as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?php echo e($row['service_date'] ?? 'No date'); ?></strong>
                    <span><?php echo (int) ($row['booking_count'] ?? 0); ?> bookings</span>
                  </div>
                  <div class="list-right">
                    <strong>$<?php echo number_format((float) ($row['total_revenue'] ?? 0), 2); ?></strong>
                    <span>Revenue</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Revenue Notes</h2>
          <div class="panel-subtitle">
            Use this page to judge business performance from several angles.
          </div>

          <div class="list">
            <div class="list-row">
              <div class="list-left">
                <strong>Gross vs Completed</strong>
                <span>Gross revenue shows booked value. Completed revenue shows what has actually been fulfilled.</span>
              </div>
            </div>

            <div class="list-row">
              <div class="list-left">
                <strong>Pending and Confirmed</strong>
                <span>These amounts represent future pipeline and help you see what is likely to convert into fulfilled revenue.</span>
              </div>
            </div>

            <div class="list-row">
              <div class="list-left">
                <strong>Cancelled Revenue</strong>
                <span>This can help you spot lost value and identify where operational or client retention issues may exist.</span>
              </div>
            </div>

            <div class="list-row">
              <div class="list-left">
                <strong>Next Upgrade</strong>
                <span>After this, the strongest next page is admin-walks.php so operations and live service flow match the revenue side.</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <h2 class="panel-title">Recent Revenue Rows</h2>
        <div class="panel-subtitle">
          Recent bookings with price and status for quick spot-checking.
        </div>

        <?php if (!$recentRevenueRows): ?>
          <div class="empty-state">
            <strong>No revenue rows yet</strong>
            No bookings are available right now.
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <?php if ($serviceTypeColumn): ?><th>Service</th><?php endif; ?>
                  <?php if ($serviceDateColumn): ?><th>Date</th><?php endif; ?>
                  <?php if ($statusColumn): ?><th>Status</th><?php endif; ?>
                  <?php if (hasColumn($bookingColumns, 'walker_name')): ?><th>Walker</th><?php endif; ?>
                  <th>Price</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentRevenueRows as $row): ?>
                  <tr>
                    <td>
                      <div class="primary-text">#<?php echo (int) ($row['id'] ?? 0); ?></div>
                    </td>

                    <?php if ($serviceTypeColumn): ?>
                      <td>
                        <div class="primary-text"><?php echo e(ucfirst((string) ($row['service_type'] ?? 'Service'))); ?></div>
                      </td>
                    <?php endif; ?>

                    <?php if ($serviceDateColumn): ?>
                      <td>
                        <div class="primary-text"><?php echo e($row['service_date'] ?? 'No date'); ?></div>
                      </td>
                    <?php endif; ?>

                    <?php if ($statusColumn): ?>
                      <td>
                        <span class="status-pill"><?php echo e((string) ($row['status'] ?? '')); ?></span>
                      </td>
                    <?php endif; ?>

                    <?php if (hasColumn($bookingColumns, 'walker_name')): ?>
                      <td>
                        <div class="primary-text"><?php echo e($row['walker_name'] ?? 'Not assigned'); ?></div>
                      </td>
                    <?php endif; ?>

                    <td>
                      <div class="primary-text">$<?php echo number_format((float) ($row['price'] ?? 0), 2); ?></div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>