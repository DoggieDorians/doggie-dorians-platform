<?php
declare(strict_types=1);

$dbFile = __DIR__ . '/data/members.sqlite';
$dbDir = dirname($dbFile);

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $queries = [

        "CREATE TABLE IF NOT EXISTS members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            phone TEXT,
            password_hash TEXT NOT NULL,
            membership_plan TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS dogs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            dog_name TEXT NOT NULL,
            breed TEXT,
            age TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS walkers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            phone TEXT,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS walks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            dog_id INTEGER NOT NULL,
            walk_date TEXT NOT NULL,
            walk_time TEXT NOT NULL,
            duration_minutes INTEGER NOT NULL,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'Requested',
            walker_id INTEGER,
            walker_name TEXT,
            walker_phone TEXT,
            walker_notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (dog_id) REFERENCES dogs(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS membership_signups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone TEXT,
            email TEXT NOT NULL,
            selected_membership TEXT NOT NULL,
            dog_name TEXT,
            preferred_contact TEXT,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'New',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS non_member_bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone TEXT,
            email TEXT NOT NULL,
            service_type TEXT NOT NULL,
            dog_name TEXT NOT NULL,
            dog_size TEXT,
            walk_duration INTEGER,
            preferred_walk_time TEXT,
            date_start TEXT NOT NULL,
            date_end TEXT,
            feeding_schedule TEXT,
            preferred_contact TEXT,
            notes TEXT,
            estimated_price REAL,
            status TEXT NOT NULL DEFAULT 'New',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
      <meta charset='UTF-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
      <title>Setup Complete</title>
      <style>
        body {
          background:#0a0a0d;
          color:#fff;
          font-family:Arial, sans-serif;
          display:flex;
          align-items:center;
          justify-content:center;
          min-height:100vh;
          margin:0;
          padding:24px;
        }
        .card {
          max-width:700px;
          width:100%;
          background:rgba(255,255,255,0.04);
          border:1px solid rgba(255,255,255,0.08);
          border-radius:24px;
          padding:32px;
        }
        h1 {
          margin:0 0 12px;
          font-family:Georgia, serif;
        }
        p {
          color:rgba(255,255,255,0.78);
          line-height:1.6;
        }
        a {
          color:#f0d77a;
          text-decoration:none;
        }
      </style>
    </head>
    <body>
      <div class='card'>
        <h1>Database setup complete</h1>
        <p>Your database tables were created successfully, including the new <strong>non_member_bookings</strong> table.</p>
        <p><a href='non-member-booking.php'>Go to non-member booking page</a></p>
      </div>
    </body>
    </html>";
} catch (Throwable $e) {
    echo '<pre>Setup failed:' . "\n" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}