<?php
session_start();

if (!isset($_SESSION['walker_id'])) {
    header('Location: walker-login.php');
    exit;
}

$walkId = isset($_GET['walk_id']) ? (int) $_GET['walk_id'] : 0;

if ($walkId <= 0) {
    die('Invalid walk ID.');
}

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

    $dogSelect = $dogColumn ? "d.$dogColumn AS dog_name" : "'Dog' AS dog_name";
    $memberSelect = $memberColumn ? "m.$memberColumn AS member_name" : "'Member' AS member_name";

    $sql = "
        SELECT
            w.*,
            $dogSelect,
            $memberSelect
        FROM walks w
        LEFT JOIN dogs d ON d.id = w.dog_id
        LEFT JOIN members m ON m.id = w.member_id
        WHERE w.id = :walk_id
          AND w.walker_id = :walker_id
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':walk_id' => $walkId,
        ':walker_id' => $_SESSION['walker_id']
    ]);

    $walk = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$walk) {
        die('Walk not found or not assigned to you.');
    }
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking | Doggie Dorian's</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f3ee;
            color: #1f1f1f;
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }

        .top-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 32px;
        }

        .meta {
            display: grid;
            gap: 8px;
            margin-top: 12px;
            color: #555;
        }

        .status {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 999px;
            background: #111;
            color: #fff;
            font-weight: bold;
            margin-top: 14px;
        }

        #map {
            width: 100%;
            height: 550px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        button {
            background: #111;
            color: #fff;
            border: none;
            padding: 14px 18px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
        }

        button.secondary {
            background: #c7a97d;
            color: #111;
        }

        .log {
            margin-top: 16px;
            background: #fff;
            padding: 16px;
            border-radius: 16px;
            min-height: 60px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            color: #444;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top-card">
            <h1>Live Walk Tracking</h1>
            <div class="meta">
                <div><strong>Dog:</strong> <?php echo htmlspecialchars($walk['dog_name'] ?? 'Dog'); ?></div>
                <div><strong>Client:</strong> <?php echo htmlspecialchars($walk['member_name'] ?? 'Member'); ?></div>
                <div><strong>Date:</strong> <?php echo htmlspecialchars($walk['walk_date']); ?></div>
                <div><strong>Time:</strong> <?php echo htmlspecialchars($walk['walk_time']); ?></div>
            </div>
            <div class="status">GPS Ready for Walk #<?php echo (int)$walkId; ?></div>
        </div>

        <div class="controls">
            <button id="startTrackingBtn">Start Live Tracking</button>
            <button id="stopTrackingBtn" class="secondary">Stop Tracking</button>
        </div>

        <div id="map"></div>
        <div class="log" id="logBox">Waiting to start GPS tracking...</div>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        const walkId = <?php echo (int)$walkId; ?>;
        let watchId = null;
        let map = L.map('map').setView([40.7831, -73.9712], 13);
        let marker = null;
        let polyline = L.polyline([], { weight: 5 }).addTo(map);
        let routePoints = [];

        const logBox = document.getElementById('logBox');
        const startBtn = document.getElementById('startTrackingBtn');
        const stopBtn = document.getElementById('stopTrackingBtn');

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        function logMessage(message) {
            const now = new Date().toLocaleTimeString();
            logBox.innerHTML = `<strong>${now}</strong> — ${message}`;
        }

        async function sendLocation(position) {
            const payload = {
                walk_id: walkId,
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
                speed: position.coords.speed,
                heading: position.coords.heading
            };

            try {
                const response = await fetch('walker-location-update.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (!result.success) {
                    logMessage('Location save failed: ' + (result.message || 'Unknown error'));
                    return;
                }

                const latLng = [payload.latitude, payload.longitude];

                if (!marker) {
                    marker = L.marker(latLng).addTo(map).bindPopup('Your current location');
                    map.setView(latLng, 16);
                } else {
                    marker.setLatLng(latLng);
                }

                routePoints.push(latLng);
                polyline.setLatLngs(routePoints);

                logMessage(
                    `Location updated. Lat: ${payload.latitude.toFixed(6)}, Lng: ${payload.longitude.toFixed(6)}`
                );
            } catch (error) {
                logMessage('Network error while sending GPS location.');
            }
        }

        function startTracking() {
            if (!navigator.geolocation) {
                logMessage('Geolocation is not supported by this browser.');
                return;
            }

            if (watchId !== null) {
                logMessage('Tracking is already running.');
                return;
            }

            watchId = navigator.geolocation.watchPosition(
                sendLocation,
                function(error) {
                    logMessage('GPS error: ' + error.message);
                },
                {
                    enableHighAccuracy: true,
                    maximumAge: 2000,
                    timeout: 10000
                }
            );

            logMessage('Live GPS tracking started.');
        }

        function stopTracking() {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
                logMessage('Live GPS tracking stopped.');
            } else {
                logMessage('Tracking was not running.');
            }
        }

        startBtn.addEventListener('click', startTracking);
        stopBtn.addEventListener('click', stopTracking);
    </script>
</body>
</html>