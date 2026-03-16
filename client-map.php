<?php
session_start();

if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
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

    $walkerColumn = getPreferredColumn($db, 'walkers', [
        'full_name', 'name', 'walker_name', 'first_name', 'email'
    ]);

    $dogSelect = $dogColumn ? "d.$dogColumn AS dog_name" : "'Dog' AS dog_name";
    $walkerSelect = $walkerColumn ? "wa.$walkerColumn AS walker_name" : "'Walker' AS walker_name";

    $sql = "
        SELECT
            w.*,
            $dogSelect,
            $walkerSelect
        FROM walks w
        LEFT JOIN dogs d ON d.id = w.dog_id
        LEFT JOIN walkers wa ON wa.id = w.walker_id
        WHERE w.id = :walk_id
          AND w.member_id = :member_id
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':walk_id' => $walkId,
        ':member_id' => $_SESSION['member_id']
    ]);

    $walk = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$walk) {
        die('Walk not found or not authorized.');
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
    <title>Track My Dog Walk | Doggie Dorian's</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f4ef;
            color: #1f1f1f;
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }

        .hero {
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 32px;
        }

        .meta {
            display: grid;
            gap: 8px;
            color: #555;
        }

        .status-bar {
            margin-top: 16px;
            background: #111;
            color: #fff;
            padding: 12px 16px;
            border-radius: 12px;
            display: inline-block;
            font-weight: 700;
        }

        #map {
            width: 100%;
            height: 560px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin: 18px 0 0;
        }

        .info-card {
            background: #fff;
            padding: 18px;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }

        .label {
            font-size: 13px;
            color: #777;
            margin-bottom: 6px;
        }

        .value {
            font-size: 18px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            #map {
                height: 420px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <h1>Live Dog Walk Tracking</h1>
            <div class="meta">
                <div><strong>Dog:</strong> <?php echo htmlspecialchars($walk['dog_name'] ?? 'Dog'); ?></div>
                <div><strong>Walker:</strong> <?php echo htmlspecialchars($walk['walker_name'] ?? 'Walker'); ?></div>
                <div><strong>Date:</strong> <?php echo htmlspecialchars($walk['walk_date']); ?></div>
                <div><strong>Time:</strong> <?php echo htmlspecialchars($walk['walk_time']); ?></div>
            </div>
            <div class="status-bar">Tracking Walk #<?php echo (int)$walkId; ?></div>
        </div>

        <div id="map"></div>

        <div class="info-grid">
            <div class="info-card">
                <div class="label">Walk Status</div>
                <div class="value" id="walkStatus">Loading...</div>
            </div>
            <div class="info-card">
                <div class="label">Last GPS Update</div>
                <div class="value" id="lastUpdate">Waiting...</div>
            </div>
            <div class="info-card">
                <div class="label">Current Speed</div>
                <div class="value" id="currentSpeed">--</div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        const walkId = <?php echo (int)$walkId; ?>;
        let map = L.map('map').setView([40.7831, -73.9712], 13);
        let marker = null;
        let polyline = L.polyline([], { weight: 5 }).addTo(map);
        let firstLoad = true;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        async function loadTrackingData() {
            try {
                const response = await fetch('tracking-data.php?walk_id=' + encodeURIComponent(walkId));
                const data = await response.json();

                if (!data.success) {
                    return;
                }

                document.getElementById('walkStatus').textContent = data.walk.status || 'Unknown';
                document.getElementById('lastUpdate').textContent = data.latest?.created_at || 'No update yet';

                if (data.latest && data.latest.speed !== null) {
                    const mph = (parseFloat(data.latest.speed) * 2.23694).toFixed(1);
                    document.getElementById('currentSpeed').textContent = mph + ' mph';
                } else {
                    document.getElementById('currentSpeed').textContent = '--';
                }

                if (data.points && data.points.length > 0) {
                    const latLngs = data.points.map(point => [
                        parseFloat(point.latitude),
                        parseFloat(point.longitude)
                    ]);

                    polyline.setLatLngs(latLngs);

                    const latestPoint = latLngs[latLngs.length - 1];

                    if (!marker) {
                        marker = L.marker(latestPoint).addTo(map).bindPopup('Walker current location');
                    } else {
                        marker.setLatLng(latestPoint);
                    }

                    if (firstLoad) {
                        map.setView(latestPoint, 16);
                        firstLoad = false;
                    }
                }
            } catch (error) {
                console.error('Tracking fetch error:', error);
            }
        }

        loadTrackingData();
        setInterval(loadTrackingData, 5000);
    </script>
</body>
</html>