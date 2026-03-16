<?php

require_once __DIR__ . '/../data/config/db.php';

$queries = [];

$queries[] = "PRAGMA foreign_keys = OFF;";

/*
|--------------------------------------------------------------------------
| Drop ALL old tables so the database is truly rebuilt clean
|--------------------------------------------------------------------------
*/
$queries[] = "DROP TABLE IF EXISTS bookings;";
$queries[] = "DROP TABLE IF EXISTS pets;";
$queries[] = "DROP TABLE IF EXISTS client_profiles;";
$queries[] = "DROP TABLE IF EXISTS users;";
$queries[] = "DROP TABLE IF EXISTS custom_plans;";
$queries[] = "DROP TABLE IF EXISTS dogs;";
$queries[] = "DROP TABLE IF EXISTS members;";
$queries[] = "DROP TABLE IF EXISTS walk_sessions;";
$queries[] = "DROP TABLE IF EXISTS walkers;";
$queries[] = "DROP TABLE IF EXISTS walks;";

/*
|--------------------------------------------------------------------------
| Create fresh core tables
|--------------------------------------------------------------------------
*/
$queries[] = "
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    phone TEXT,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    status TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
";

$queries[] = "
CREATE TABLE client_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    address_line1 TEXT,
    address_line2 TEXT,
    city TEXT,
    state TEXT,
    zip_code TEXT,
    emergency_contact_name TEXT,
    emergency_contact_phone TEXT,
    building_access_notes TEXT,
    preferred_contact_method TEXT,
    service_notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
";

$queries[] = "
CREATE TABLE pets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pet_name TEXT NOT NULL,
    breed TEXT,
    age INTEGER,
    weight TEXT,
    birthday TEXT,
    gender TEXT,
    spayed_neutered INTEGER DEFAULT 0,
    photo_path TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
";

$queries[] = "
CREATE TABLE bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pet_id INTEGER NOT NULL,
    assigned_walker_id INTEGER,
    service_type TEXT NOT NULL,
    service_date TEXT NOT NULL,
    service_time TEXT NOT NULL,
    duration_minutes INTEGER,
    status TEXT NOT NULL DEFAULT 'pending',
    access_notes TEXT,
    client_notes TEXT,
    price REAL NOT NULL DEFAULT 0,
    is_instant_booking INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
);
";

$queries[] = "PRAGMA foreign_keys = ON;";

try {
    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    echo "Fresh database setup completed successfully.";
} catch (PDOException $e) {
    die("Setup failed: " . $e->getMessage());
}