<?php
session_start();

$isLoggedIn = isset($_SESSION['member_id']);

if (!$isLoggedIn) {
    header('Location: login.php');
    exit;
}

$dbPath = __DIR__ . '/data/members.sqlite';
$successMessage = '';
$errorMessage = '';

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_service_label(string $serviceType): string {
    return match ($serviceType) {
        'walk' => 'Walk',
        'daycare' => 'Daycare',
        'boarding' => 'Boarding',
        default => 'Unknown'
    };
}

function format_status_label(string $status): string {
    return match ($status) {
        'New' => 'New',
        'Confirmed' => 'Confirmed',
        'Completed' => 'Completed',
        'Cancelled' => 'Cancelled',
        default => $status
    };
}

$bookings = [];

try {
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);

    $db->exec("
        CREATE TABLE IF NOT EXISTS public_booking_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            service_type TEXT NOT NULL,
            walk_duration INTEGER,
            pet_name TEXT NOT NULL,
            pet_size TEXT NOT NULL,
            preferred_date TEXT,
            preferred_time TEXT,
            dropoff_time TEXT,
            pickup_time TEXT,
            checkin_date TEXT,
            checkout_date TEXT,
            checkin_time TEXT,
            checkout_time TEXT,
            feeding_schedule TEXT,
            notes TEXT,
            estimated_price REAL,
            source TEXT NOT NULL DEFAULT 'public_booking_page',
            status TEXT NOT NULL DEFAULT 'New',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $existingColumns = [];
    $columnsResult = $db->query("PRAGMA table_info(public_booking_requests)");
    while ($column = $columnsResult->fetchArray(SQLITE3_ASSOC)) {
        $existingColumns[] = $column['name'];
    }

    $columnsToAdd = [
        'dropoff_time' => 'ALTER TABLE public_booking_requests ADD COLUMN dropoff_time TEXT',
        'pickup_time' => 'ALTER TABLE public_booking_requests ADD COLUMN pickup_time TEXT',
        'checkin_date' => 'ALTER TABLE public_booking_requests ADD COLUMN checkin_date TEXT',
        'checkout_date' => 'ALTER TABLE public_booking_requests ADD COLUMN checkout_date TEXT',
        'checkin_time' => 'ALTER TABLE public_booking_requests ADD COLUMN checkin_time TEXT',
        'checkout_time' => 'ALTER TABLE public_booking_requests ADD COLUMN checkout_time TEXT',
        'estimated_price' => 'ALTER TABLE public_booking_requests ADD COLUMN estimated_price REAL',
    ];

    foreach ($columnsToAdd as $columnName => $sql) {
        if (!in_array($columnName, $existingColumns, true)) {
            $db->exec($sql);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $bookingId = (int)($_POST['booking_id'] ?? 0);

        if ($action === 'update_status' && $bookingId > 0) {
            $newStatus = trim($_POST['status'] ?? '');
            $allowedStatuses = ['New', 'Confirmed', 'Completed', 'Cancelled'];

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $errorMessage = 'Invalid status selected.';
            } else {
                $stmt = $db->prepare("
                    UPDATE public_booking_requests
                    SET status = :status
                    WHERE id = :id
                ");
                $stmt->bindValue(':status', $newStatus, SQLITE3_TEXT);
                $stmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
                $result = $stmt->execute();

                if ($result) {
                    $successMessage = 'Booking status updated successfully.';
                } else {
                    $errorMessage = 'Unable to update booking status.';
                }
            }
        }

        if ($action === 'delete_booking' && $bookingId > 0) {
            $stmt = $db->prepare("
                DELETE FROM public_booking_requests
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
            $result = $stmt->execute();

            if ($result) {
                $successMessage = 'Booking deleted successfully.';
            } else {
                $errorMessage = 'Unable to delete booking.';
            }
        }
    }

    $result = $db->query("
        SELECT *
        FROM public_booking_requests
        ORDER BY datetime(created_at) DESC, id DESC
    ");

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $bookings[] = $row;
    }

    $db->close();
} catch (Throwable $e) {
    $errorMessage = 'Something went wrong while loading booking requests.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Bookings | Doggie Dorian's</title>
  <meta name="description" content="Manage public booking requests for Doggie Dorian's.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #07080b;
      --bg-soft: #0d1016;
      --panel: rgba(255,255,255,0.04);
      --panel-strong: rgba(255,255,255,0.06);
      --line: rgba(255,255,255,0.10);
      --text: #f6f1e8;
      --muted: #c9c0af;
      --soft: #9d968a;
      --gold: #d7b26a;
      --gold-light: #f0d59f;
      --white: #ffffff;
      --danger: #ff9d9d;
      --success: #9fe0b1;
      --blue: #9fc8ff;
      --shadow: 0 22px 65px rgba(0,0,0,0.34);
      --max: 1380px;
      --sidebar: 280px;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.08), transparent 24%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
      color: var(--text);
      line-height: 1.6;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .layout {
      min-height: 100vh;
      display: grid;
      grid-template-columns: var(--sidebar) 1fr;
    }

    .sidebar {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 28px 22px;
      border-right: 1px solid rgba(255,255,255,0.06);
      background: rgba(7,8,11,0.92);
      backdrop-filter: blur(14px);
    }

    .brand {
      display: block;
      font-size: 1.12rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--white);
      font-weight: 700;
      margin-bottom: 28px;
    }

    .side-label {
      color: var(--soft);
      font-size: 0.78rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .side-nav {
      display: grid;
      gap: 10px;
      margin-bottom: 28px;
    }

    .side-nav a {
      display: block;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
      color: var(--muted);
      transition: 0.2s ease;
    }

    .side-nav a:hover,
    .side-nav a.active {
      color: var(--gold);
      border-color: rgba(215,178,106,0.22);
      background: rgba(215,178,106,0.08);
    }

    .sidebar-note {
      margin-top: 18px;
      padding: 16px;
      border-radius: 18px;
      background: rgba(215,178,106,0.08);
      border: 1px solid rgba(215,178,106,0.16);
      color: var(--muted);
      font-size: 0.94rem;
    }

    .main {
      padding: 28px;
    }

    .container {
      width: min(var(--max), 100%);
      margin: 0 auto;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .page-title h1 {
      font-size: clamp(2rem, 4vw, 3.3rem);
      line-height: 1;
      color: var(--white);
      margin-bottom: 8px;
    }

    .page-title p {
      color: var(--muted);
      max-width: 760px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 12px 20px;
      font-size: 0.94rem;
      font-weight: 700;
      border: 1px solid transparent;
      cursor: pointer;
      transition: 0.2s ease;
      min-height: 46px;
    }

    .btn:hover {
      transform: translateY(-1px);
    }

    .btn-gold {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
      color: #17120d;
      box-shadow: 0 16px 38px rgba(215,178,106,0.22);
    }

    .btn-soft {
      background: rgba(255,255,255,0.03);
      border-color: rgba(255,255,255,0.08);
      color: var(--white);
    }

    .btn-danger {
      background: rgba(255,157,157,0.10);
      border-color: rgba(255,157,157,0.25);
      color: #ffd3d3;
    }

    .status-message {
      border-radius: 18px;
      padding: 15px 16px;
      margin-bottom: 18px;
      font-size: 0.96rem;
    }

    .status-message.success {
      background: rgba(159,224,177,0.10);
      border: 1px solid rgba(159,224,177,0.30);
      color: var(--success);
    }

    .status-message.error {
      background: rgba(255,157,157,0.10);
      border: 1px solid rgba(255,157,157,0.30);
      color: var(--danger);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
      margin-bottom: 26px;
    }

    .stat-card {
      border-radius: 22px;
      padding: 22px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .stat-card strong {
      display: block;
      color: #f5ddaf;
      font-size: 1.8rem;
      line-height: 1;
      margin-bottom: 8px;
    }

    .stat-card span {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .bookings-grid {
      display: grid;
      gap: 20px;
    }

    .booking-card {
      border-radius: 28px;
      padding: 24px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .booking-head {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
      margin-bottom: 18px;
      padding-bottom: 18px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .booking-head-left h2 {
      color: var(--white);
      font-size: 1.35rem;
      margin-bottom: 6px;
    }

    .booking-head-left p {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .badge-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    .badge {
      display: inline-block;
      padding: 7px 11px;
      border-radius: 999px;
      font-size: 0.84rem;
      font-weight: 700;
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.04);
      color: var(--white);
    }

    .badge.service {
      border-color: rgba(215,178,106,0.24);
      background: rgba(215,178,106,0.10);
      color: #f2d9a8;
    }

    .badge.status-new {
      border-color: rgba(159,200,255,0.20);
      background: rgba(159,200,255,0.10);
      color: var(--blue);
    }

    .badge.status-confirmed {
      border-color: rgba(159,224,177,0.24);
      background: rgba(159,224,177,0.10);
      color: var(--success);
    }

    .badge.status-completed {
      border-color: rgba(215,178,106,0.24);
      background: rgba(215,178,106,0.10);
      color: #f2d9a8;
    }

    .badge.status-cancelled {
      border-color: rgba(255,157,157,0.24);
      background: rgba(255,157,157,0.10);
      color: #ffd3d3;
    }

    .booking-content {
      display: grid;
      grid-template-columns: 1.1fr 1fr 0.9fr;
      gap: 18px;
      margin-bottom: 18px;
    }

    .detail-box {
      border-radius: 18px;
      padding: 18px;
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.06);
    }

    .detail-box h3 {
      color: var(--white);
      font-size: 1rem;
      margin-bottom: 12px;
    }

    .detail-list {
      display: grid;
      gap: 10px;
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      padding-bottom: 10px;
    }

    .detail-row:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .detail-label {
      color: var(--soft);
      font-size: 0.88rem;
      min-width: 120px;
    }

    .detail-value {
      color: var(--text);
      font-size: 0.93rem;
      text-align: right;
      word-break: break-word;
    }

    .notes-box {
      white-space: pre-wrap;
      color: var(--muted);
      font-size: 0.94rem;
    }

    .booking-actions {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
      padding-top: 18px;
      border-top: 1px solid rgba(255,255,255,0.08);
    }

    .action-group {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    select {
      border: 1px solid rgba(255,255,255,0.10);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      min-height: 46px;
      outline: none;
    }

    .empty-state {
      border-radius: 28px;
      padding: 34px;
      text-align: center;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .empty-state h2 {
      color: var(--white);
      margin-bottom: 10px;
    }

    .empty-state p {
      color: var(--muted);
    }

    @media (max-width: 1200px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .booking-content {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 980px) {
      .layout {
        grid-template-columns: 1fr;
      }

      .sidebar {
        position: static;
        height: auto;
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.06);
      }
    }

    @media (max-width: 640px) {
      .main {
        padding: 18px;
      }

      .stats-grid {
        grid-template-columns: 1fr;
      }

      .booking-card {
        padding: 18px;
      }

      .booking-actions,
      .action-group {
        flex-direction: column;
        align-items: stretch;
      }

      .btn,
      select {
        width: 100%;
      }

      .detail-row {
        flex-direction: column;
      }

      .detail-value {
        text-align: left;
      }
    }
  </style>
</head>
<body>

<div class="layout">
  <aside class="sidebar">
    <a href="dashboard.php" class="brand">Doggie Dorian's</a>

    <div class="side-label">Admin Navigation</div>
    <nav class="side-nav">
      <a href="dashboard.php">Dashboard</a>
      <a href="admin-walks.php">Walks</a>
      <a href="admin-bookings.php" class="active">Public Bookings</a>
      <a href="memberships.php">Memberships</a>
      <a href="index.php">View Website</a>
    </nav>

    <div class="sidebar-note">
      This page shows public walk, daycare, and boarding requests from your booking form in one place.
    </div>
  </aside>

  <main class="main">
    <div class="container">
      <div class="topbar">
        <div class="page-title">
          <h1>Public Booking Requests</h1>
          <p>Review incoming walk, daycare, and boarding requests, update their status, and keep your booking pipeline organized.</p>
        </div>

        <div class="action-group">
          <a href="book-walk.php" class="btn btn-soft">Open Booking Page</a>
          <a href="dashboard.php" class="btn btn-gold">Back to Dashboard</a>
        </div>
      </div>

      <?php if ($successMessage !== ''): ?>
        <div class="status-message success"><?php echo e($successMessage); ?></div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="status-message error"><?php echo e($errorMessage); ?></div>
      <?php endif; ?>

      <?php
        $totalCount = count($bookings);
        $newCount = count(array_filter($bookings, fn($b) => ($b['status'] ?? '') === 'New'));
        $confirmedCount = count(array_filter($bookings, fn($b) => ($b['status'] ?? '') === 'Confirmed'));
        $completedCount = count(array_filter($bookings, fn($b) => ($b['status'] ?? '') === 'Completed'));
      ?>

      <section class="stats-grid">
        <div class="stat-card">
          <strong><?php echo $totalCount; ?></strong>
          <span>Total booking requests</span>
        </div>
        <div class="stat-card">
          <strong><?php echo $newCount; ?></strong>
          <span>New requests</span>
        </div>
        <div class="stat-card">
          <strong><?php echo $confirmedCount; ?></strong>
          <span>Confirmed requests</span>
        </div>
        <div class="stat-card">
          <strong><?php echo $completedCount; ?></strong>
          <span>Completed requests</span>
        </div>
      </section>

      <?php if (empty($bookings)): ?>
        <div class="empty-state">
          <h2>No booking requests yet</h2>
          <p>Once someone submits the public booking form, their request will appear here.</p>
        </div>
      <?php else: ?>
        <section class="bookings-grid">
          <?php foreach ($bookings as $booking): ?>
            <?php
              $serviceType = $booking['service_type'] ?? '';
              $status = $booking['status'] ?? 'New';

              $statusClass = match ($status) {
                  'Confirmed' => 'status-confirmed',
                  'Completed' => 'status-completed',
                  'Cancelled' => 'status-cancelled',
                  default => 'status-new'
              };

              $estimatedPrice = isset($booking['estimated_price']) && $booking['estimated_price'] !== null
                  ? '$' . number_format((float)$booking['estimated_price'], 2)
                  : 'N/A';

              if ($serviceType === 'boarding' && $estimatedPrice !== 'N/A') {
                  $estimatedPrice .= ' / night';
              }
            ?>
            <article class="booking-card">
              <div class="booking-head">
                <div class="booking-head-left">
                  <h2>#<?php echo (int)$booking['id']; ?> — <?php echo e($booking['full_name'] ?? 'Unknown Client'); ?></h2>
                  <p>Submitted on <?php echo e($booking['created_at'] ?? 'Unknown date'); ?></p>

                  <div class="badge-row">
                    <span class="badge service"><?php echo e(format_service_label($serviceType)); ?></span>
                    <span class="badge <?php echo e($statusClass); ?>"><?php echo e(format_status_label($status)); ?></span>
                  </div>
                </div>
              </div>

              <div class="booking-content">
                <div class="detail-box">
                  <h3>Client & Dog Details</h3>
                  <div class="detail-list">
                    <div class="detail-row">
                      <span class="detail-label">Client</span>
                      <span class="detail-value"><?php echo e($booking['full_name'] ?? ''); ?></span>
                    </div>
                    <div class="detail-row">
                      <span class="detail-label">Email</span>
                      <span class="detail-value"><?php echo e($booking['email'] ?? ''); ?></span>
                    </div>
                    <div class="detail-row">
                      <span class="detail-label">Phone</span>
                      <span class="detail-value"><?php echo e($booking['phone'] ?? ''); ?></span>
                    </div>
                    <div class="detail-row">
                      <span class="detail-label">Dog Name</span>
                      <span class="detail-value"><?php echo e($booking['pet_name'] ?? ''); ?></span>
                    </div>
                    <div class="detail-row">
                      <span class="detail-label">Dog Size</span>
                      <span class="detail-value"><?php echo e(ucfirst($booking['pet_size'] ?? '')); ?></span>
                    </div>
                    <div class="detail-row">
                      <span class="detail-label">Estimate</span>
                      <span class="detail-value"><?php echo e($estimatedPrice); ?></span>
                    </div>
                  </div>
                </div>

                <div class="detail-box">
                  <h3>Service Details</h3>
                  <div class="detail-list">
                    <div class="detail-row">
                      <span class="detail-label">Service</span>
                      <span class="detail-value"><?php echo e(format_service_label($serviceType)); ?></span>
                    </div>

                    <?php if ($serviceType === 'walk'): ?>
                      <div class="detail-row">
                        <span class="detail-label">Duration</span>
                        <span class="detail-value">
                          <?php echo !empty($booking['walk_duration']) ? (int)$booking['walk_duration'] . ' minutes' : 'N/A'; ?>
                        </span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Date</span>
                        <span class="detail-value"><?php echo e($booking['preferred_date'] ?? 'N/A'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Time</span>
                        <span class="detail-value"><?php echo e($booking['preferred_time'] ?: 'Not provided'); ?></span>
                      </div>
                    <?php endif; ?>

                    <?php if ($serviceType === 'daycare'): ?>
                      <div class="detail-row">
                        <span class="detail-label">Date</span>
                        <span class="detail-value"><?php echo e($booking['preferred_date'] ?? 'N/A'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Drop-Off</span>
                        <span class="detail-value"><?php echo e($booking['dropoff_time'] ?: 'Not provided'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Pick-Up</span>
                        <span class="detail-value"><?php echo e($booking['pickup_time'] ?: 'Not provided'); ?></span>
                      </div>
                    <?php endif; ?>

                    <?php if ($serviceType === 'boarding'): ?>
                      <div class="detail-row">
                        <span class="detail-label">Check-In Date</span>
                        <span class="detail-value"><?php echo e($booking['checkin_date'] ?: 'N/A'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Check-Out Date</span>
                        <span class="detail-value"><?php echo e($booking['checkout_date'] ?: 'N/A'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Check-In Time</span>
                        <span class="detail-value"><?php echo e($booking['checkin_time'] ?: 'Not provided'); ?></span>
                      </div>
                      <div class="detail-row">
                        <span class="detail-label">Check-Out Time</span>
                        <span class="detail-value"><?php echo e($booking['checkout_time'] ?: 'Not provided'); ?></span>
                      </div>
                    <?php endif; ?>

                    <div class="detail-row">
                      <span class="detail-label">Feeding</span>
                      <span class="detail-value"><?php echo e($booking['feeding_schedule'] ?: 'Not provided'); ?></span>
                    </div>
                  </div>
                </div>

                <div class="detail-box">
                  <h3>Notes</h3>
                  <div class="notes-box"><?php echo e($booking['notes'] ?: 'No additional notes provided.'); ?></div>
                </div>
              </div>

              <div class="booking-actions">
                <form method="post" class="action-group">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">

                  <select name="status">
                    <option value="New" <?php echo $status === 'New' ? 'selected' : ''; ?>>New</option>
                    <option value="Confirmed" <?php echo $status === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="Completed" <?php echo $status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Cancelled" <?php echo $status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                  </select>

                  <button type="submit" class="btn btn-gold">Update Status</button>
                </form>

                <form method="post" class="action-group" onsubmit="return confirm('Are you sure you want to delete this booking?');">
                  <input type="hidden" name="action" value="delete_booking">
                  <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                  <button type="submit" class="btn btn-danger">Delete Booking</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>
</div>

</body>
</html>