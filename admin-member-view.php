<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/db.php';

function safeRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function formatDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y', $timestamp);
}

function formatDateTime(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatMoney(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return 'N/A';
    }

    if (!is_numeric($amount)) {
        return h((string) $amount);
    }

    return '$' . number_format((float) $amount, 2);
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($table) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function pickExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }

    return null;
}

function buildSelectFragment(?string $column, string $alias, string $fallbackSql = 'NULL', string $tableAlias = ''): string
{
    if ($column === null) {
        return $fallbackSql . ' AS ' . quotedIdentifier($alias);
    }

    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    return $prefix . quotedIdentifier($column) . ' AS ' . quotedIdentifier($alias);
}

function fetchOneSafe(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function buildStatusBadgeClass(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        'requested', 'pending' => 'status-requested',
        'confirmed' => 'status-confirmed',
        'in progress', 'in_progress' => 'status-in-progress',
        'completed' => 'status-completed',
        'cancelled', 'canceled' => 'status-cancelled',
        default => 'status-default',
    };
}

function normalizeStatusLabel(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending', 'requested' => 'Requested',
        'confirmed' => 'Confirmed',
        'in progress', 'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        default => trim((string) $status) !== '' ? ucwords(str_replace(['_', '-'], ' ', trim((string) $status))) : 'Requested',
    };
}

function buildFullNameExpression(string $alias, ?string $nameCol, ?string $firstCol, ?string $lastCol, string $fallback = "'N/A'"): string
{
    if ($nameCol !== null) {
        return "COALESCE(NULLIF($alias." . quotedIdentifier($nameCol) . ", ''), $fallback)";
    }

    $first = $firstCol !== null ? "COALESCE($alias." . quotedIdentifier($firstCol) . ", '')" : "''";
    $last = $lastCol !== null ? "COALESCE($alias." . quotedIdentifier($lastCol) . ", '')" : "''";

    return "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), $fallback)";
}


function valueFromRow(array $row, array $candidates, mixed $default = null): mixed
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function firstExistingColumn(PDO $pdo, string $table, array $choices): ?string
{
    return pickExistingColumn(getColumns($pdo, $table), $choices);
}

function ensureTableColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!tableExists($pdo, $table)) {
        return;
    }

    $columns = getColumns($pdo, $table);
    if (in_array($column, $columns, true)) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE ' . quotedIdentifier($table) . ' ADD COLUMN ' . quotedIdentifier($column) . ' ' . $definition);
    } catch (Throwable $e) {
    }
}

function normalizeServiceType(?string $type): string
{
    $type = strtolower(trim((string) $type));
    $type = str_replace(['-', ' '], '_', $type);

    if ($type === '') {
        return 'service';
    }
    if (str_contains($type, 'walk')) {
        return 'walk';
    }
    if (str_contains($type, 'board')) {
        return 'boarding';
    }
    if (str_contains($type, 'daycare') || str_contains($type, 'day_care')) {
        return 'daycare';
    }
    if (str_contains($type, 'sit')) {
        return 'sitting';
    }
    if (str_contains($type, 'drop')) {
        return 'drop_in';
    }

    return $type;
}

function formatJourneyServiceLabel(?string $serviceType): string
{
    $serviceType = trim((string) $serviceType);

    return match ($serviceType) {
        'walk' => 'Walks',
        'daycare' => 'Daycare Sessions',
        'boarding_night' => 'Boarding Nights',
        'drop_in' => 'Drop-Ins',
        'sitting' => 'Sitting Sessions',
        'boarding' => 'Boarding',
        'drop-in' => 'Drop-Ins',
        '' => 'Auto Calculate',
        default => ucwords(str_replace(['_', '-'], ' ', $serviceType)),
    };
}

function normalizePetKey(?string $value): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
}

function ensureDogJourneySchema(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dog_journey_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                baseline_walks INTEGER NOT NULL DEFAULT 0,
                baseline_daycare_sessions INTEGER NOT NULL DEFAULT 0,
                baseline_boarding_nights INTEGER NOT NULL DEFAULT 0,
                baseline_drop_in_sessions INTEGER NOT NULL DEFAULT 0,
                baseline_sitting_sessions INTEGER NOT NULL DEFAULT 0,
                favorite_service TEXT DEFAULT '',
                milestone_badge TEXT DEFAULT '',
                journey_note TEXT DEFAULT '',
                journey_highlight TEXT DEFAULT '',
                last_service_date TEXT DEFAULT '',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_walks', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_daycare_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_boarding_nights', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_drop_in_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'baseline_sitting_sessions', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'dog_journey_profiles', 'favorite_service', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'milestone_badge', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'journey_note', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'journey_highlight', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'last_service_date', "TEXT DEFAULT ''");
    ensureTableColumn($pdo, 'dog_journey_profiles', 'updated_at', "TEXT DEFAULT CURRENT_TIMESTAMP");

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dog_journey_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                entry_type TEXT NOT NULL DEFAULT 'note',
                service_type TEXT DEFAULT '',
                entry_title TEXT DEFAULT '',
                entry_body TEXT DEFAULT '',
                entry_date TEXT DEFAULT '',
                created_by_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_dog_journey_user_pet ON dog_journey_profiles(user_id, pet_id)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dog_journey_entries_user_pet ON dog_journey_entries(user_id, pet_id)');
    } catch (Throwable $e) {
    }
}

function fetchMemberPetsDetailed(PDO $pdo, int $userId): array
{
    $pets = [];
    $seen = [];

    foreach (['pets', 'dogs'] as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }

        $columns = getColumns($pdo, $table);
        $ownerCol = pickExistingColumn($columns, ['user_id', 'member_id', 'owner_id', 'client_id']);
        $idCol = pickExistingColumn($columns, ['id', 'pet_id', 'dog_id']);
        $nameCol = pickExistingColumn($columns, ['name', 'pet_name', 'dog_name']);
        $breedCol = pickExistingColumn($columns, ['breed']);
        $ageCol = pickExistingColumn($columns, ['age', 'dog_age']);
        $notesCol = pickExistingColumn($columns, ['notes', 'care_notes']);
        $createdCol = pickExistingColumn($columns, ['created_at', 'created_on']);

        if ($ownerCol === null || $idCol === null || $nameCol === null) {
            continue;
        }

        $sql = "
            SELECT
                " . quotedIdentifier($idCol) . " AS " . quotedIdentifier('pet_id') . ",
                " . quotedIdentifier($nameCol) . " AS " . quotedIdentifier('pet_name') . ",
                " . ($breedCol !== null ? quotedIdentifier($breedCol) : "''") . " AS " . quotedIdentifier('breed') . ",
                " . ($ageCol !== null ? quotedIdentifier($ageCol) : "''") . " AS " . quotedIdentifier('age') . ",
                " . ($notesCol !== null ? quotedIdentifier($notesCol) : "''") . " AS " . quotedIdentifier('notes') . ",
                " . ($createdCol !== null ? quotedIdentifier($createdCol) : "''") . " AS " . quotedIdentifier('created_at') . "
            FROM " . quotedIdentifier($table) . "
            WHERE " . quotedIdentifier($ownerCol) . " = :user_id
            ORDER BY " . quotedIdentifier($idCol) . " DESC
        ";

        $rows = fetchAllSafe($pdo, $sql, [':user_id' => $userId]);

        foreach ($rows as $row) {
            $petId = (int) valueFromRow($row, ['pet_id'], 0);
            $petName = trim((string) valueFromRow($row, ['pet_name'], ''));

            if ($petId <= 0 || $petName === '' || isset($seen[$petId])) {
                continue;
            }

            $seen[$petId] = true;
            $pets[] = [
                'pet_id' => $petId,
                'pet_name' => $petName,
                'breed' => (string) valueFromRow($row, ['breed'], ''),
                'age' => (string) valueFromRow($row, ['age'], ''),
                'notes' => (string) valueFromRow($row, ['notes'], ''),
                'created_at' => (string) valueFromRow($row, ['created_at'], ''),
                'display_name' => $petName,
                'display_breed' => (string) valueFromRow($row, ['breed'], ''),
                'display_age' => (string) valueFromRow($row, ['age'], ''),
                'display_notes' => (string) valueFromRow($row, ['notes'], ''),
                'display_created' => (string) valueFromRow($row, ['created_at'], ''),
                'source_table' => $table,
            ];
        }
    }

    return $pets;
}

function loadPetNameById(PDO $pdo, int $petId): string
{
    if ($petId <= 0) {
        return '';
    }

    foreach (['pets', 'dogs'] as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }

        $columns = getColumns($pdo, $table);
        $idCol = pickExistingColumn($columns, ['id', 'pet_id', 'dog_id']);
        $nameCol = pickExistingColumn($columns, ['name', 'pet_name', 'dog_name']);

        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $sql = 'SELECT ' . quotedIdentifier($nameCol) . ' FROM ' . quotedIdentifier($table) . ' WHERE ' . quotedIdentifier($idCol) . ' = :pet_id LIMIT 1';
        $row = fetchOneSafe($pdo, $sql, [':pet_id' => $petId]);

        if ($row) {
            $name = trim((string) array_values($row)[0]);
            if ($name !== '') {
                return $name;
            }
        }
    }

    return '';
}

function bookingBaseTable(PDO $pdo): ?string
{
    foreach (['bookings', 'walks'] as $table) {
        if (tableExists($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function normalizeJourneyStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'canceled', 'cancelled', 'cancelled by client', 'cancelled by walker', 'void' => 'cancelled',
        'done', 'finished', 'closed' => 'completed',
        'assigned', 'confirmed' => 'confirmed',
        'active', 'walking', 'started', 'in progress' => 'in_progress',
        'new', 'open', 'unassigned' => 'requested',
        default => $status !== '' ? $status : 'requested',
    };
}

function fetchJourneyBookings(PDO $pdo, int $userId): array
{
    $table = bookingBaseTable($pdo);

    if ($table === null || $userId <= 0) {
        return [];
    }

    $columns = getColumns($pdo, $table);
    $userCol = pickExistingColumn($columns, ['user_id', 'member_id', 'client_id', 'owner_id']);
    $orderCol = pickExistingColumn($columns, ['service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'created_at', 'id']);

    if ($userCol === null) {
        return [];
    }

    $orderBy = $orderCol !== null ? quotedIdentifier($orderCol) : quotedIdentifier('id');

    $sql = 'SELECT * FROM ' . quotedIdentifier($table) . ' WHERE ' . quotedIdentifier($userCol) . ' = :user_id ORDER BY ' . $orderBy . ' ASC, rowid DESC';
    $rows = fetchAllSafe($pdo, $sql, [':user_id' => $userId]);

    $normalized = [];

    foreach ($rows as $row) {
        $petId = (int) valueFromRow($row, ['pet_id', 'dog_id'], 0);
        $petName = trim((string) valueFromRow($row, ['pet_name', 'dog_name', 'name'], ''));
        if ($petName === '' && $petId > 0) {
            $petName = loadPetNameById($pdo, $petId);
        }

        $serviceType = normalizeServiceType((string) valueFromRow($row, ['service_type', 'service', 'booking_type', 'type', 'category'], 'service'));
        $quantity = 1;
        if ($serviceType === 'boarding') {
            $quantityValue = valueFromRow($row, ['quantity', 'nights', 'boarding_nights', 'total_nights'], 1);
            $quantity = is_numeric($quantityValue) ? max(1, (int) $quantityValue) : 1;
        }

        $normalized[] = [
            'pet_id' => $petId,
            'pet_name' => $petName,
            'service_type' => $serviceType,
            'status' => normalizeJourneyStatus((string) valueFromRow($row, ['status', 'booking_status', 'service_status', 'walk_status'], 'requested')),
            'service_date' => (string) valueFromRow($row, ['service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'], ''),
            'quantity' => $quantity,
        ];
    }

    return $normalized;
}

function fetchDogJourneyProfileMap(PDO $pdo, int $userId): array
{
    if (!tableExists($pdo, 'dog_journey_profiles')) {
        return [];
    }

    $profiles = fetchAllSafe(
        $pdo,
        'SELECT * FROM dog_journey_profiles WHERE user_id = :user_id ORDER BY pet_id ASC, id ASC',
        [':user_id' => $userId]
    );

    $map = [];
    foreach ($profiles as $profile) {
        $map[(int) valueFromRow($profile, ['pet_id'], 0)] = $profile;
    }

    return $map;
}

function fetchDogJourneyEntriesMap(PDO $pdo, int $userId, int $limitPerPet = 4): array
{
    if (!tableExists($pdo, 'dog_journey_entries')) {
        return [];
    }

    $rows = fetchAllSafe(
        $pdo,
        "SELECT * FROM dog_journey_entries WHERE user_id = :user_id ORDER BY COALESCE(NULLIF(entry_date, ''), created_at) DESC, id DESC",
        [':user_id' => $userId]
    );

    $map = [];
    foreach ($rows as $row) {
        $petId = (int) valueFromRow($row, ['pet_id'], 0);
        if ($petId <= 0) {
            continue;
        }

        if (!isset($map[$petId])) {
            $map[$petId] = [];
        }

        if (count($map[$petId]) >= $limitPerPet) {
            continue;
        }

        $map[$petId][] = [
            'entry_type' => (string) valueFromRow($row, ['entry_type'], 'note'),
            'service_type' => (string) valueFromRow($row, ['service_type'], ''),
            'entry_title' => (string) valueFromRow($row, ['entry_title'], ''),
            'entry_body' => (string) valueFromRow($row, ['entry_body'], ''),
            'entry_date' => (string) valueFromRow($row, ['entry_date', 'created_at'], ''),
            'created_at' => (string) valueFromRow($row, ['created_at'], ''),
            'created_by_admin' => (int) valueFromRow($row, ['created_by_admin'], 0),
        ];
    }

    return $map;
}

function fetchLatestDogJourneyEntry(PDO $pdo, int $userId, int $petId, string $entryType = ''): ?array
{
    if (!tableExists($pdo, 'dog_journey_entries') || $userId <= 0 || $petId <= 0) {
        return null;
    }

    $sql = 'SELECT * FROM dog_journey_entries WHERE user_id = :user_id AND pet_id = :pet_id';
    $params = [':user_id' => $userId, ':pet_id' => $petId];

    if ($entryType !== '') {
        $sql .= ' AND entry_type = :entry_type';
        $params[':entry_type'] = $entryType;
    }

    $sql .= " ORDER BY COALESCE(NULLIF(entry_date, ''), created_at) DESC, id DESC LIMIT 1";

    return fetchOneSafe($pdo, $sql, $params);
}

function insertDogJourneyEntry(PDO $pdo, int $userId, int $petId, string $entryType, string $entryTitle, string $entryBody, string $entryDate = '', int $createdByAdmin = 1, string $serviceType = ''): void
{
    if (!tableExists($pdo, 'dog_journey_entries') || $userId <= 0 || $petId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO dog_journey_entries (user_id, pet_id, entry_type, service_type, entry_title, entry_body, entry_date, created_by_admin, created_at, updated_at) '
        . 'VALUES (:user_id, :pet_id, :entry_type, :service_type, :entry_title, :entry_body, :entry_date, :created_by_admin, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':entry_type' => $entryType,
        ':service_type' => $serviceType,
        ':entry_title' => $entryTitle,
        ':entry_body' => $entryBody,
        ':entry_date' => $entryDate,
        ':created_by_admin' => $createdByAdmin,
    ]);
}

function journeyCardForPet(array $cards, int $petId): ?array
{
    foreach ($cards as $card) {
        if ((int) ($card['pet_id'] ?? 0) === $petId) {
            return $card;
        }
    }

    return null;
}

function buildAutoJourneyBadge(int $totalServices): string
{
    if ($totalServices >= 30) {
        return 'Dorian’s Inner Circle';
    }
    if ($totalServices >= 15) {
        return 'VIP Companion';
    }
    if ($totalServices >= 5) {
        return 'Routine Favorite';
    }
    if ($totalServices >= 1) {
        return 'First Strolls';
    }

    return 'Journey Begins';
}

function buildJourneyHighlight(array $counts, string $petName): string
{
    $totalServices =
        (int) ($counts['walk'] ?? 0) +
        (int) ($counts['daycare'] ?? 0) +
        (int) ($counts['boarding_night'] ?? 0) +
        (int) ($counts['drop_in'] ?? 0) +
        (int) ($counts['sitting'] ?? 0);

    if ($totalServices <= 0) {
        return $petName . ' is ready to begin their Dog Journey.';
    }

    arsort($counts);
    $topKey = (string) key($counts);
    $topValue = (int) current($counts);

    return $petName . ' has ' . $totalServices . ' total recorded services so far, with ' . $topValue . ' in ' . formatJourneyServiceLabel($topKey) . '.';
}

function buildDogJourneyCards(PDO $pdo, int $userId, array $pets, array $bookings, string $memberCreatedAt = ''): array
{
    $profiles = fetchDogJourneyProfileMap($pdo, $userId);
    $entriesMap = fetchDogJourneyEntriesMap($pdo, $userId);
    $cards = [];

    foreach ($pets as $pet) {
        $petId = (int) valueFromRow($pet, ['pet_id'], 0);
        $petName = (string) valueFromRow($pet, ['pet_name', 'display_name'], 'Dog');
        $profile = $profiles[$petId] ?? [];

        $liveCounts = [
            'walk' => 0,
            'daycare' => 0,
            'boarding_night' => 0,
            'drop_in' => 0,
            'sitting' => 0,
        ];

        $latestLiveDate = '';

        foreach ($bookings as $booking) {
            $bookingPetId = (int) valueFromRow($booking, ['pet_id'], 0);
            $bookingPetName = normalizePetKey((string) valueFromRow($booking, ['pet_name'], ''));
            $matchesPet = false;

            if ($petId > 0 && $bookingPetId > 0 && $petId === $bookingPetId) {
                $matchesPet = true;
            } elseif ($bookingPetId <= 0 && $bookingPetName !== '' && $bookingPetName === normalizePetKey($petName)) {
                $matchesPet = true;
            }

            if (!$matchesPet || (string) valueFromRow($booking, ['status'], '') === 'cancelled') {
                continue;
            }

            $serviceType = (string) valueFromRow($booking, ['service_type'], 'service');
            $quantity = max(1, (int) valueFromRow($booking, ['quantity'], 1));

            if ($serviceType === 'walk') {
                $liveCounts['walk'] += 1;
            } elseif ($serviceType === 'daycare') {
                $liveCounts['daycare'] += 1;
            } elseif ($serviceType === 'boarding') {
                $liveCounts['boarding_night'] += $quantity;
            } elseif ($serviceType === 'drop_in') {
                $liveCounts['drop_in'] += 1;
            } elseif ($serviceType === 'sitting') {
                $liveCounts['sitting'] += 1;
            }

            $serviceDate = trim((string) valueFromRow($booking, ['service_date'], ''));
            if ($serviceDate !== '' && ($latestLiveDate === '' || strtotime($serviceDate) > strtotime($latestLiveDate))) {
                $latestLiveDate = $serviceDate;
            }
        }

        $baselineCounts = [
            'walk' => (int) valueFromRow($profile, ['baseline_walks'], 0),
            'daycare' => (int) valueFromRow($profile, ['baseline_daycare_sessions'], 0),
            'boarding_night' => (int) valueFromRow($profile, ['baseline_boarding_nights'], 0),
            'drop_in' => (int) valueFromRow($profile, ['baseline_drop_in_sessions'], 0),
            'sitting' => (int) valueFromRow($profile, ['baseline_sitting_sessions'], 0),
        ];

        $displayCounts = [
            'walk' => $baselineCounts['walk'] + $liveCounts['walk'],
            'daycare' => $baselineCounts['daycare'] + $liveCounts['daycare'],
            'boarding_night' => $baselineCounts['boarding_night'] + $liveCounts['boarding_night'],
            'drop_in' => $baselineCounts['drop_in'] + $liveCounts['drop_in'],
            'sitting' => $baselineCounts['sitting'] + $liveCounts['sitting'],
        ];

        arsort($displayCounts);
        $autoFavorite = (string) key($displayCounts);
        $favoriteService = trim((string) valueFromRow($profile, ['favorite_service'], ''));
        if ($favoriteService === '' || !in_array($favoriteService, ['walk', 'daycare', 'boarding_night', 'drop_in', 'sitting'], true)) {
            $favoriteService = $displayCounts[$autoFavorite] > 0 ? $autoFavorite : '';
        }

        $totalServices =
            (int) $displayCounts['walk'] +
            (int) $displayCounts['daycare'] +
            (int) $displayCounts['boarding_night'] +
            (int) $displayCounts['drop_in'] +
            (int) $displayCounts['sitting'];

        $milestoneBadge = trim((string) valueFromRow($profile, ['milestone_badge'], ''));
        if ($milestoneBadge === '') {
            $milestoneBadge = buildAutoJourneyBadge($totalServices);
        }

        $storedLastServiceDate = trim((string) valueFromRow($profile, ['last_service_date'], ''));
        $lastServiceDate = $latestLiveDate !== '' ? $latestLiveDate : $storedLastServiceDate;

        $journeyHighlight = trim((string) valueFromRow($profile, ['journey_highlight'], ''));
        if ($journeyHighlight === '') {
            $journeyHighlight = buildJourneyHighlight($displayCounts, $petName);
        }

        $cards[] = [
            'pet_id' => $petId,
            'pet_name' => $petName,
            'breed' => (string) valueFromRow($pet, ['breed', 'display_breed'], ''),
            'age' => (string) valueFromRow($pet, ['age', 'display_age'], ''),
            'member_since' => $memberCreatedAt,
            'last_service_date' => $lastServiceDate,
            'manual_last_service_date' => $storedLastServiceDate,
            'favorite_service' => $favoriteService,
            'manual_favorite_service' => (string) valueFromRow($profile, ['favorite_service'], ''),
            'milestone_badge' => $milestoneBadge,
            'manual_milestone_badge' => (string) valueFromRow($profile, ['milestone_badge'], ''),
            'journey_note' => (string) valueFromRow($profile, ['journey_note'], ''),
            'manual_journey_note' => (string) valueFromRow($profile, ['journey_note'], ''),
            'journey_highlight' => $journeyHighlight,
            'manual_journey_highlight' => (string) valueFromRow($profile, ['journey_highlight'], ''),
            'counts' => $displayCounts,
            'baseline_counts' => $baselineCounts,
            'live_counts' => $liveCounts,
            'total_services' => $totalServices,
            'journey_entries' => $entriesMap[$petId] ?? [],
        ];
    }

    return $cards;
}

function upsertDogJourneyProfile(PDO $pdo, int $userId, int $petId, array $payload): void
{
    $existing = fetchOneSafe(
        $pdo,
        'SELECT id FROM dog_journey_profiles WHERE user_id = :user_id AND pet_id = :pet_id LIMIT 1',
        [':user_id' => $userId, ':pet_id' => $petId]
    );

    $params = [
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':baseline_walks' => max(0, (int) ($payload['baseline_walks'] ?? 0)),
        ':baseline_daycare_sessions' => max(0, (int) ($payload['baseline_daycare_sessions'] ?? 0)),
        ':baseline_boarding_nights' => max(0, (int) ($payload['baseline_boarding_nights'] ?? 0)),
        ':baseline_drop_in_sessions' => max(0, (int) ($payload['baseline_drop_in_sessions'] ?? 0)),
        ':baseline_sitting_sessions' => max(0, (int) ($payload['baseline_sitting_sessions'] ?? 0)),
        ':favorite_service' => trim((string) ($payload['favorite_service'] ?? '')),
        ':milestone_badge' => trim((string) ($payload['milestone_badge'] ?? '')),
        ':journey_note' => trim((string) ($payload['journey_note'] ?? '')),
        ':journey_highlight' => trim((string) ($payload['journey_highlight'] ?? '')),
        ':last_service_date' => trim((string) ($payload['last_service_date'] ?? '')),
    ];

    if ($existing) {
        $sql = "
            UPDATE dog_journey_profiles
            SET
                baseline_walks = :baseline_walks,
                baseline_daycare_sessions = :baseline_daycare_sessions,
                baseline_boarding_nights = :baseline_boarding_nights,
                baseline_drop_in_sessions = :baseline_drop_in_sessions,
                baseline_sitting_sessions = :baseline_sitting_sessions,
                favorite_service = :favorite_service,
                milestone_badge = :milestone_badge,
                journey_note = :journey_note,
                journey_highlight = :journey_highlight,
                last_service_date = :last_service_date,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id
              AND pet_id = :pet_id
        ";
    } else {
        $sql = "
            INSERT INTO dog_journey_profiles (
                user_id,
                pet_id,
                baseline_walks,
                baseline_daycare_sessions,
                baseline_boarding_nights,
                baseline_drop_in_sessions,
                baseline_sitting_sessions,
                favorite_service,
                milestone_badge,
                journey_note,
                journey_highlight,
                last_service_date,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :pet_id,
                :baseline_walks,
                :baseline_daycare_sessions,
                :baseline_boarding_nights,
                :baseline_drop_in_sessions,
                :baseline_sitting_sessions,
                :favorite_service,
                :milestone_badge,
                :journey_note,
                :journey_highlight,
                :last_service_date,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}



function ensureBadgeVaultSchema(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pet_id INTEGER NOT NULL DEFAULT 0,
                badge_key TEXT NOT NULL,
                badge_name TEXT NOT NULL DEFAULT '',
                badge_mark TEXT NOT NULL DEFAULT '',
                badge_group TEXT NOT NULL DEFAULT '',
                badge_family TEXT NOT NULL DEFAULT '',
                badge_scope TEXT NOT NULL DEFAULT 'member',
                theme_class TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                reward_title TEXT NOT NULL DEFAULT '',
                reward_note TEXT NOT NULL DEFAULT '',
                source_type TEXT NOT NULL DEFAULT '',
                source_reference TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 1,
                is_featured INTEGER NOT NULL DEFAULT 1,
                unlocked_at TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
    }

    ensureTableColumn($pdo, 'member_badges', 'pet_id', 'INTEGER NOT NULL DEFAULT 0');
    ensureTableColumn($pdo, 'member_badges', 'badge_key', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_name', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_mark', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_group', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_family', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'badge_scope', "TEXT NOT NULL DEFAULT 'member'");
    ensureTableColumn($pdo, 'member_badges', 'theme_class', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'description', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'reward_title', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'reward_note', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'source_type', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'source_reference', "TEXT NOT NULL DEFAULT ''");
    ensureTableColumn($pdo, 'member_badges', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
    ensureTableColumn($pdo, 'member_badges', 'is_featured', 'INTEGER NOT NULL DEFAULT 1');
    ensureTableColumn($pdo, 'member_badges', 'unlocked_at', "TEXT DEFAULT CURRENT_TIMESTAMP");
    ensureTableColumn($pdo, 'member_badges', 'created_at', "TEXT DEFAULT CURRENT_TIMESTAMP");
    ensureTableColumn($pdo, 'member_badges', 'updated_at', "TEXT DEFAULT CURRENT_TIMESTAMP");

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_member_badges_user_pet_key ON member_badges(user_id, pet_id, badge_key)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_member_badges_user_group_active ON member_badges(user_id, badge_group, is_active)');
    } catch (Throwable $e) {
    }
}

function normalizeBadgeKey(?string $value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? $value : 'badge';
}

function badgeMarkFromName(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'BDG';
    }

    $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $letters = '';

    foreach ((array) $words as $word) {
        $letters .= strtoupper(substr((string) $word, 0, 1));
        if (strlen($letters) >= 3) {
            break;
        }
    }

    if ($letters === '') {
        $letters = strtoupper(substr((string) preg_replace('/[^a-z0-9]/i', '', $name), 0, 3));
    }

    return $letters !== '' ? $letters : 'BDG';
}

function founderPlanDefaults(): array
{
    return [
        'founder_walk_club' => [
            'name' => 'Founder Walk Club',
            'walk' => 12,
            'daycare' => 0,
            'drop_in' => 0,
            'boarding_night' => 0,
            'service_credit' => 0,
        ],
        'founder_care_club' => [
            'name' => 'Founder Care Club',
            'walk' => 16,
            'daycare' => 2,
            'drop_in' => 2,
            'boarding_night' => 0,
            'service_credit' => 0,
        ],
        'founder_elite_club' => [
            'name' => 'Founder Elite Club',
            'walk' => 20,
            'daycare' => 4,
            'drop_in' => 4,
            'boarding_night' => 3,
            'service_credit' => 0,
        ],
    ];
}

function getMembershipSummary(PDO $pdo, int $userId): array
{
    $result = [
        'membership_name' => 'No Membership',
        'membership_id' => 0,
        'plan_slug' => '',
        'renewal_count' => 0,
        'walk' => 0,
        'daycare' => 0,
        'drop-in' => 0,
        'boarding_night' => 0,
        'service_credit' => 0,
    ];

    if ($userId <= 0 || !tableExists($pdo, 'member_memberships')) {
        return $result;
    }

    $memberIdCol = firstExistingColumn($pdo, 'member_memberships', ['member_id', 'user_id', 'client_id']);
    $planIdCol = firstExistingColumn($pdo, 'member_memberships', ['plan_id']);
    $membershipIdCol = firstExistingColumn($pdo, 'member_memberships', ['id']);
    $createdCol = firstExistingColumn($pdo, 'member_memberships', ['created_at', 'updated_at', 'id']);

    if ($memberIdCol === null || $planIdCol === null || $membershipIdCol === null) {
        return $result;
    }

    $orderBy = $createdCol !== null ? quotedIdentifier($createdCol) : quotedIdentifier($membershipIdCol);

    $membership = fetchOneSafe(
        $pdo,
        "
        SELECT *
        FROM member_memberships
        WHERE " . quotedIdentifier($memberIdCol) . " = :member_id
        ORDER BY {$orderBy} DESC, rowid DESC
        LIMIT 1
        ",
        [':member_id' => $userId]
    );

    if (!$membership) {
        return $result;
    }

    $membershipId = (int) valueFromRow($membership, [$membershipIdCol], 0);
    $planId = (int) valueFromRow($membership, [$planIdCol], 0);

    $result['membership_id'] = $membershipId;
    $result['renewal_count'] = (int) valueFromRow($membership, ['renewal_count'], 0);

    if ($planId > 0 && tableExists($pdo, 'membership_plans')) {
        $planNameCol = firstExistingColumn($pdo, 'membership_plans', ['name', 'plan_name', 'title']);
        $planSlugCol = firstExistingColumn($pdo, 'membership_plans', ['slug', 'plan_slug', 'code']);
        $planIdLookupCol = firstExistingColumn($pdo, 'membership_plans', ['id', 'plan_id']);

        if ($planIdLookupCol !== null) {
            $planRow = fetchOneSafe(
                $pdo,
                'SELECT * FROM membership_plans WHERE ' . quotedIdentifier($planIdLookupCol) . ' = :plan_id LIMIT 1',
                [':plan_id' => $planId]
            );

            if ($planRow) {
                if ($planNameCol !== null && !empty($planRow[$planNameCol])) {
                    $result['membership_name'] = (string) $planRow[$planNameCol];
                }

                if ($planSlugCol !== null && !empty($planRow[$planSlugCol])) {
                    $result['plan_slug'] = strtolower(trim((string) $planRow[$planSlugCol]));
                }
            }
        }
    }

    $hasEntitlementRows = false;

    if ($membershipId > 0 && tableExists($pdo, 'membership_entitlements')) {
        $entMembershipCol = firstExistingColumn($pdo, 'membership_entitlements', ['membership_id']);
        $serviceCol = firstExistingColumn($pdo, 'membership_entitlements', ['entitlement_type', 'service_type', 'type']);
        $remainingCol = firstExistingColumn($pdo, 'membership_entitlements', ['remaining_units', 'units_remaining', 'balance']);
        $totalCol = firstExistingColumn($pdo, 'membership_entitlements', ['total']);
        $usedCol = firstExistingColumn($pdo, 'membership_entitlements', ['used']);

        if ($entMembershipCol !== null && $serviceCol !== null) {
            $entRows = fetchAllSafe(
                $pdo,
                'SELECT * FROM membership_entitlements WHERE ' . quotedIdentifier($entMembershipCol) . ' = :membership_id',
                [':membership_id' => $membershipId]
            );

            foreach ($entRows as $entRow) {
                $hasEntitlementRows = true;

                $serviceType = strtolower(trim((string) valueFromRow($entRow, [$serviceCol], '')));
                $serviceType = str_replace(['-', ' '], '_', $serviceType);
                if ($serviceType === 'dropin') {
                    $serviceType = 'drop_in';
                }
                if ($serviceType === 'boarding') {
                    $serviceType = 'boarding_night';
                }

                $remainingUnits = 0;

                if ($remainingCol !== null && isset($entRow[$remainingCol]) && $entRow[$remainingCol] !== '') {
                    $remainingUnits = (int) $entRow[$remainingCol];
                } else {
                    $totalUnits = $totalCol !== null ? (int) valueFromRow($entRow, [$totalCol], 0) : 0;
                    $usedUnits = $usedCol !== null ? (int) valueFromRow($entRow, [$usedCol], 0) : 0;
                    $remainingUnits = max(0, $totalUnits - $usedUnits);
                }

                if ($serviceType === 'walk') {
                    $result['walk'] = $remainingUnits;
                } elseif ($serviceType === 'daycare') {
                    $result['daycare'] = $remainingUnits;
                } elseif ($serviceType === 'drop_in') {
                    $result['drop-in'] = $remainingUnits;
                } elseif ($serviceType === 'boarding_night') {
                    $result['boarding_night'] = $remainingUnits;
                } elseif ($serviceType === 'service_credit') {
                    $result['service_credit'] = $remainingUnits;
                }
            }
        }
    }

    if (!$hasEntitlementRows && $result['plan_slug'] !== '') {
        $defaults = founderPlanDefaults();
        if (isset($defaults[$result['plan_slug']])) {
            $defaultPlan = $defaults[$result['plan_slug']];
            $result['membership_name'] = $defaultPlan['name'];
            $result['walk'] = (int) $defaultPlan['walk'];
            $result['daycare'] = (int) $defaultPlan['daycare'];
            $result['drop-in'] = (int) $defaultPlan['drop_in'];
            $result['boarding_night'] = (int) $defaultPlan['boarding_night'];
            $result['service_credit'] = (int) $defaultPlan['service_credit'];
        }
    }

    return $result;
}

function founderBadgeCatalogDetailed(): array
{
    return [
        'founder_walk_club' => [
            'badge_key' => 'founder_walk_club',
            'slug' => 'founder_walk_club',
            'membership_name' => 'Founder Walk Club',
            'badge_name' => 'Founding Walker',
            'badge_mark' => 'FW',
            'theme_class' => 'badge-tier-walk',
            'description' => 'Reserved for members who locked in Founder Walk Club access and became part of the first premium walk circle.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ],
        'founder_care_club' => [
            'badge_key' => 'founder_care_club',
            'slug' => 'founder_care_club',
            'membership_name' => 'Founder Care Club',
            'badge_name' => 'Care Circle Founder',
            'badge_mark' => 'FC',
            'theme_class' => 'badge-tier-care',
            'description' => 'Awarded to members who secured Founder Care Club access with expanded recurring care benefits.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ],
        'founder_elite_club' => [
            'badge_key' => 'founder_elite_club',
            'slug' => 'founder_elite_club',
            'membership_name' => 'Founder Elite Club',
            'badge_name' => 'Elite Founding Member',
            'badge_mark' => 'FE',
            'theme_class' => 'badge-tier-elite',
            'description' => 'The highest founder distinction for members who entered the Founder Elite Club collection.',
            'reward_title' => 'Founder Reward Slot',
            'reward_note' => 'Ready for future founder-only perks, credits, or concierge rewards.',
        ],
    ];
}

function dogJourneyBadgeCatalogDetailed(): array
{
    return [
        'journey_begins' => [
            'badge_key' => 'journey_begins',
            'badge_name' => 'Journey Begins',
            'badge_mark' => 'JB',
            'theme_class' => 'badge-tier-journey',
            'description' => 'The first Dog Journey milestone, created when a dog profile begins building its care history.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ],
        'first_strolls' => [
            'badge_key' => 'first_strolls',
            'badge_name' => 'First Strolls',
            'badge_mark' => 'FS',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Unlocked after a dog records its first meaningful set of services and begins a routine.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ],
        'routine_favorite' => [
            'badge_key' => 'routine_favorite',
            'badge_name' => 'Routine Favorite',
            'badge_mark' => 'RF',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Marks a dog that has settled into a dependable luxury care rhythm with Doggie Dorian’s.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ],
        'vip_companion' => [
            'badge_key' => 'vip_companion',
            'badge_name' => 'VIP Companion',
            'badge_mark' => 'VC',
            'theme_class' => 'badge-tier-journey',
            'description' => 'Reserved for dogs with substantial service history and a strong premium care journey.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ],
        'dorians_inner_circle' => [
            'badge_key' => 'dorians_inner_circle',
            'badge_name' => 'Dorian’s Inner Circle',
            'badge_mark' => 'DI',
            'theme_class' => 'badge-tier-journey',
            'description' => 'The signature Dog Journey distinction for dogs with deep ongoing service history.',
            'reward_title' => 'Journey Reward Slot',
            'reward_note' => 'Ready for future welcome treats, profile unlocks, or member surprises.',
        ],
    ];
}

function standardJourneyBadgeKeyByName(?string $name): string
{
    $name = strtolower(trim((string) $name));
    $catalog = dogJourneyBadgeCatalogDetailed();

    foreach ($catalog as $badgeKey => $config) {
        if (strtolower((string) $config['badge_name']) === $name) {
            return (string) $badgeKey;
        }
    }

    return '';
}

function awardOrUpdateMemberBadge(PDO $pdo, array $payload): void
{
    if (!tableExists($pdo, 'member_badges')) {
        return;
    }

    $userId = (int) valueFromRow($payload, ['user_id'], 0);
    $petId = (int) valueFromRow($payload, ['pet_id'], 0);
    $badgeKey = trim((string) valueFromRow($payload, ['badge_key'], ''));

    if ($userId <= 0 || $badgeKey === '') {
        return;
    }

    $existing = fetchOneSafe(
        $pdo,
        'SELECT id FROM member_badges WHERE user_id = :user_id AND pet_id = :pet_id AND badge_key = :badge_key LIMIT 1',
        [
            ':user_id' => $userId,
            ':pet_id' => $petId,
            ':badge_key' => $badgeKey,
        ]
    );

    $params = [
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':badge_key' => $badgeKey,
        ':badge_name' => trim((string) valueFromRow($payload, ['badge_name'], '')),
        ':badge_mark' => trim((string) valueFromRow($payload, ['badge_mark'], '')),
        ':badge_group' => trim((string) valueFromRow($payload, ['badge_group'], '')),
        ':badge_family' => trim((string) valueFromRow($payload, ['badge_family'], '')),
        ':badge_scope' => trim((string) valueFromRow($payload, ['badge_scope'], 'member')),
        ':theme_class' => trim((string) valueFromRow($payload, ['theme_class'], '')),
        ':description' => trim((string) valueFromRow($payload, ['description'], '')),
        ':reward_title' => trim((string) valueFromRow($payload, ['reward_title'], '')),
        ':reward_note' => trim((string) valueFromRow($payload, ['reward_note'], '')),
        ':source_type' => trim((string) valueFromRow($payload, ['source_type'], '')),
        ':source_reference' => trim((string) valueFromRow($payload, ['source_reference'], '')),
        ':is_active' => (int) valueFromRow($payload, ['is_active'], 1) ? 1 : 0,
        ':is_featured' => (int) valueFromRow($payload, ['is_featured'], 1) ? 1 : 0,
        ':unlocked_at' => trim((string) valueFromRow($payload, ['unlocked_at'], '')),
    ];

    if ($params[':badge_mark'] === '') {
        $params[':badge_mark'] = badgeMarkFromName($params[':badge_name']);
    }

    if ($existing) {
        $sql = "
            UPDATE member_badges
            SET
                badge_name = :badge_name,
                badge_mark = :badge_mark,
                badge_group = :badge_group,
                badge_family = :badge_family,
                badge_scope = :badge_scope,
                theme_class = :theme_class,
                description = :description,
                reward_title = :reward_title,
                reward_note = :reward_note,
                source_type = :source_type,
                source_reference = :source_reference,
                is_active = :is_active,
                is_featured = :is_featured,
                unlocked_at = CASE
                    WHEN :unlocked_at <> '' THEN :unlocked_at
                    ELSE COALESCE(NULLIF(unlocked_at, ''), CURRENT_TIMESTAMP)
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id
              AND pet_id = :pet_id
              AND badge_key = :badge_key
        ";
    } else {
        $sql = "
            INSERT INTO member_badges (
                user_id,
                pet_id,
                badge_key,
                badge_name,
                badge_mark,
                badge_group,
                badge_family,
                badge_scope,
                theme_class,
                description,
                reward_title,
                reward_note,
                source_type,
                source_reference,
                is_active,
                is_featured,
                unlocked_at,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :pet_id,
                :badge_key,
                :badge_name,
                :badge_mark,
                :badge_group,
                :badge_family,
                :badge_scope,
                :theme_class,
                :description,
                :reward_title,
                :reward_note,
                :source_type,
                :source_reference,
                :is_active,
                :is_featured,
                CASE WHEN :unlocked_at <> '' THEN :unlocked_at ELSE CURRENT_TIMESTAMP END,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
    }
}

function fetchActiveMemberBadges(PDO $pdo, int $userId, string $badgeGroup = ''): array
{
    if (!tableExists($pdo, 'member_badges') || $userId <= 0) {
        return [];
    }

    $sql = 'SELECT * FROM member_badges WHERE user_id = :user_id AND COALESCE(is_active, 1) = 1';
    $params = [':user_id' => $userId];

    if ($badgeGroup !== '') {
        $sql .= ' AND badge_group = :badge_group';
        $params[':badge_group'] = $badgeGroup;
    }

    $sql .= " ORDER BY COALESCE(NULLIF(unlocked_at, ''), created_at) DESC, id DESC";

    return fetchAllSafe($pdo, $sql, $params);
}

function founderBadgeSlugsFromMembershipHistory(PDO $pdo, int $userId, array $catalog): array
{
    $matched = [];

    if ($userId <= 0 || !tableExists($pdo, 'member_memberships')) {
        return $matched;
    }

    $ownerCol = firstExistingColumn($pdo, 'member_memberships', ['member_id', 'user_id', 'client_id']);
    $planCol = firstExistingColumn($pdo, 'member_memberships', ['plan_id']);

    if ($ownerCol === null || $planCol === null) {
        return $matched;
    }

    $rows = fetchAllSafe(
        $pdo,
        'SELECT * FROM member_memberships WHERE ' . quotedIdentifier($ownerCol) . ' = :owner_id ORDER BY id DESC',
        [':owner_id' => $userId]
    );

    foreach ($rows as $row) {
        $slug = '';
        $planId = (int) valueFromRow($row, [$planCol], 0);

        if ($planId > 0 && tableExists($pdo, 'membership_plans')) {
            $planIdCol = firstExistingColumn($pdo, 'membership_plans', ['id', 'plan_id']);
            $slugCol = firstExistingColumn($pdo, 'membership_plans', ['slug', 'plan_slug', 'code']);
            $nameCol = firstExistingColumn($pdo, 'membership_plans', ['name', 'plan_name', 'title']);

            if ($planIdCol !== null) {
                $planRow = fetchOneSafe(
                    $pdo,
                    'SELECT * FROM membership_plans WHERE ' . quotedIdentifier($planIdCol) . ' = :plan_id LIMIT 1',
                    [':plan_id' => $planId]
                );

                if ($planRow) {
                    $slug = strtolower(trim((string) valueFromRow($planRow, [$slugCol], '')));

                    if ($slug === '' && $nameCol !== null) {
                        $planName = strtolower(trim((string) valueFromRow($planRow, [$nameCol], '')));
                        foreach ($catalog as $catalogSlug => $config) {
                            if (strtolower((string) $config['membership_name']) === $planName) {
                                $slug = (string) $catalogSlug;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($slug !== '' && isset($catalog[$slug])) {
            $matched[$slug] = true;
        }
    }

    return $matched;
}

function syncFounderMembershipBadges(PDO $pdo, int $userId, array $membershipSummary = []): void
{
    $catalog = founderBadgeCatalogDetailed();
    $matched = founderBadgeSlugsFromMembershipHistory($pdo, $userId, $catalog);

    $currentSlug = strtolower(trim((string) valueFromRow($membershipSummary, ['plan_slug'], '')));
    if ($currentSlug !== '' && isset($catalog[$currentSlug])) {
        $matched[$currentSlug] = true;
    }

    foreach ($matched as $slug => $enabled) {
        if (!$enabled || !isset($catalog[$slug])) {
            continue;
        }

        $config = $catalog[$slug];
        awardOrUpdateMemberBadge($pdo, [
            'user_id' => $userId,
            'pet_id' => 0,
            'badge_key' => (string) $config['badge_key'],
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'badge_group' => 'founder',
            'badge_family' => 'founder_membership',
            'badge_scope' => 'member',
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'source_type' => 'membership_sync',
            'source_reference' => (string) $slug,
            'is_active' => 1,
            'is_featured' => 1,
        ]);
    }
}

function extractJourneyBadgeNameFromEntry(array $entry): string
{
    $type = strtolower(trim((string) valueFromRow($entry, ['entry_type'], '')));
    if ($type !== 'badge_award') {
        return '';
    }

    $body = trim((string) valueFromRow($entry, ['entry_body'], ''));
    if ($body !== '') {
        $parts = preg_split('/\s*·\s*/u', $body);
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return trim((string) valueFromRow($entry, ['entry_title'], ''));
}

function syncJourneyMilestoneBadges(PDO $pdo, int $userId, array $journeyCards): void
{
    if ($userId <= 0) {
        return;
    }

    $catalog = dogJourneyBadgeCatalogDetailed();

    foreach ($journeyCards as $card) {
        $petId = (int) valueFromRow($card, ['pet_id'], 0);
        $petName = trim((string) valueFromRow($card, ['pet_name'], 'Dog'));

        if ($petId <= 0) {
            continue;
        }

        $awards = [];
        $currentBadge = trim((string) valueFromRow($card, ['milestone_badge'], ''));
        if ($currentBadge !== '') {
            $awards[$currentBadge] = true;
        }

        foreach ((array) valueFromRow($card, ['journey_entries'], []) as $entry) {
            $entryBadge = trim((string) extractJourneyBadgeNameFromEntry((array) $entry));
            if ($entryBadge !== '') {
                $awards[$entryBadge] = true;
            }
        }

        foreach (array_keys($awards) as $badgeName) {
            $standardKey = standardJourneyBadgeKeyByName($badgeName);

            if ($standardKey !== '' && isset($catalog[$standardKey])) {
                $config = $catalog[$standardKey];
                awardOrUpdateMemberBadge($pdo, [
                    'user_id' => $userId,
                    'pet_id' => $petId,
                    'badge_key' => (string) $standardKey,
                    'badge_name' => (string) $config['badge_name'],
                    'badge_mark' => (string) $config['badge_mark'],
                    'badge_group' => 'journey',
                    'badge_family' => 'journey_milestone',
                    'badge_scope' => 'pet',
                    'theme_class' => (string) $config['theme_class'],
                    'description' => (string) $config['description'],
                    'reward_title' => (string) $config['reward_title'],
                    'reward_note' => (string) $config['reward_note'],
                    'source_type' => 'dog_journey_sync',
                    'source_reference' => 'pet:' . $petId,
                    'is_active' => 1,
                    'is_featured' => 1,
                ]);
            } else {
                $customKey = 'journey_custom_' . normalizeBadgeKey($badgeName) . '_' . $petId;

                awardOrUpdateMemberBadge($pdo, [
                    'user_id' => $userId,
                    'pet_id' => $petId,
                    'badge_key' => $customKey,
                    'badge_name' => (string) $badgeName,
                    'badge_mark' => badgeMarkFromName($badgeName),
                    'badge_group' => 'journey',
                    'badge_family' => 'journey_custom',
                    'badge_scope' => 'pet',
                    'theme_class' => 'badge-tier-journey-custom',
                    'description' => $petName . ' earned a custom Dog Journey distinction.',
                    'reward_title' => 'Journey Reward Slot',
                    'reward_note' => 'Ready for future custom badge rewards, surprises, or premium unlocks.',
                    'source_type' => 'dog_journey_sync',
                    'source_reference' => 'pet:' . $petId,
                    'is_active' => 1,
                    'is_featured' => 1,
                ]);
            }
        }
    }
}

function buildFounderBadgeVault(PDO $pdo, int $userId, array $membershipSummary = []): array
{
    $catalog = founderBadgeCatalogDetailed();
    $items = [];

    foreach ($catalog as $slug => $config) {
        $items[$slug] = [
            'slug' => (string) $slug,
            'badge_key' => (string) $config['badge_key'],
            'membership_name' => (string) $config['membership_name'],
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'unlocked' => false,
            'is_current' => false,
            'status_label' => 'Locked',
        ];
    }

    foreach (fetchActiveMemberBadges($pdo, $userId, 'founder') as $badge) {
        $slug = strtolower(trim((string) valueFromRow($badge, ['badge_key'], '')));
        if ($slug === '' || !isset($items[$slug])) {
            continue;
        }
        $items[$slug]['unlocked'] = true;
    }

    $currentSlug = strtolower(trim((string) valueFromRow($membershipSummary, ['plan_slug'], '')));
    if ($currentSlug !== '' && isset($items[$currentSlug])) {
        $items[$currentSlug]['unlocked'] = true;
        $items[$currentSlug]['is_current'] = true;
    }

    foreach ($items as $slug => $item) {
        if (!empty($item['is_current'])) {
            $items[$slug]['status_label'] = 'Current Founder Badge';
        } elseif (!empty($item['unlocked'])) {
            $items[$slug]['status_label'] = 'Founder Badge Earned';
        }
    }

    return array_values($items);
}

function buildJourneyBadgeVault(PDO $pdo, int $userId): array
{
    $catalog = dogJourneyBadgeCatalogDetailed();
    $milestones = [];

    foreach ($catalog as $badgeKey => $config) {
        $milestones[$badgeKey] = [
            'badge_key' => (string) $badgeKey,
            'badge_name' => (string) $config['badge_name'],
            'badge_mark' => (string) $config['badge_mark'],
            'theme_class' => (string) $config['theme_class'],
            'description' => (string) $config['description'],
            'reward_title' => (string) $config['reward_title'],
            'reward_note' => (string) $config['reward_note'],
            'pet_names' => [],
            'earned_count' => 0,
            'unlocked' => false,
            'status_label' => 'Locked',
        ];
    }

    $custom = [];

    foreach (fetchActiveMemberBadges($pdo, $userId, 'journey') as $badge) {
        $badgeKey = trim((string) valueFromRow($badge, ['badge_key'], ''));
        $badgeName = trim((string) valueFromRow($badge, ['badge_name'], ''));
        $petId = (int) valueFromRow($badge, ['pet_id'], 0);
        $petName = $petId > 0 ? loadPetNameById($pdo, $petId) : '';

        if ($badgeKey !== '' && isset($milestones[$badgeKey])) {
            $milestones[$badgeKey]['unlocked'] = true;
            $milestones[$badgeKey]['earned_count']++;

            if ($petName !== '' && !in_array($petName, $milestones[$badgeKey]['pet_names'], true)) {
                $milestones[$badgeKey]['pet_names'][] = $petName;
            }

            continue;
        }

        $customKey = $badgeKey !== '' ? $badgeKey : normalizeBadgeKey($badgeName);
        if (!isset($custom[$customKey])) {
            $custom[$customKey] = [
                'badge_key' => $customKey,
                'badge_name' => $badgeName !== '' ? $badgeName : 'Custom Badge',
                'badge_mark' => trim((string) valueFromRow($badge, ['badge_mark'], '')) !== '' ? (string) valueFromRow($badge, ['badge_mark'], '') : badgeMarkFromName($badgeName),
                'theme_class' => trim((string) valueFromRow($badge, ['theme_class'], '')) !== '' ? (string) valueFromRow($badge, ['theme_class'], '') : 'badge-tier-journey-custom',
                'description' => trim((string) valueFromRow($badge, ['description'], '')) !== '' ? (string) valueFromRow($badge, ['description'], '') : 'A custom Dog Journey distinction earned by this member.',
                'reward_title' => trim((string) valueFromRow($badge, ['reward_title'], '')) !== '' ? (string) valueFromRow($badge, ['reward_title'], '') : 'Journey Reward Slot',
                'reward_note' => trim((string) valueFromRow($badge, ['reward_note'], '')) !== '' ? (string) valueFromRow($badge, ['reward_note'], '') : 'Ready for future custom badge rewards, surprises, or premium unlocks.',
                'pet_names' => [],
                'earned_count' => 0,
                'unlocked' => true,
                'status_label' => 'Unlocked',
            ];
        }

        $custom[$customKey]['earned_count']++;
        if ($petName !== '' && !in_array($petName, $custom[$customKey]['pet_names'], true)) {
            $custom[$customKey]['pet_names'][] = $petName;
        }
    }

    foreach ($milestones as $badgeKey => $badge) {
        if (!empty($badge['unlocked'])) {
            $milestones[$badgeKey]['status_label'] = 'Unlocked';
        }
    }

    $milestoneItems = array_values($milestones);
    $customItems = array_values($custom);

    usort($milestoneItems, static fn(array $a, array $b): int => strcasecmp((string) ($a['badge_name'] ?? ''), (string) ($b['badge_name'] ?? '')));
    usort($customItems, static fn(array $a, array $b): int => strcasecmp((string) ($a['badge_name'] ?? ''), (string) ($b['badge_name'] ?? '')));

    $unlockedCount = 0;
    foreach ($milestoneItems as $item) {
        if (!empty($item['unlocked'])) {
            $unlockedCount++;
        }
    }
    $unlockedCount += count($customItems);

    return [
        'milestone_collection' => $milestoneItems,
        'custom_collection' => $customItems,
        'unlocked_count' => $unlockedCount,
    ];
}

function badgeVaultUnlockedCount(array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if (!empty($item['unlocked'])) {
            $count++;
        }
    }

    return $count;
}
if (empty($_SESSION['admin_member_view_csrf']) || !is_string($_SESSION['admin_member_view_csrf'])) {
    $_SESSION['admin_member_view_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_member_view_csrf'];

$userId = (int) ($_GET['id'] ?? 0);

if ($userId <= 0) {
    safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode('Invalid member ID'));
}

$allowedStatuses = [
    'Requested',
    'Confirmed',
    'In Progress',
    'Completed',
    'Cancelled',
];

$flashType = trim((string) ($_GET['status_type'] ?? ''));
$flashMessage = trim((string) ($_GET['status_message'] ?? ''));

$user = null;
$dogs = [];
$bookings = [];
$journeyBookings = [];
$journeyCards = [];
$clientProfile = null;
$membershipSummary = [];
$founderBadgeCollection = [];
$journeyMilestoneCollection = [];
$customJourneyBadgeCollection = [];
$founderBadgeUnlockTotal = 0;
$journeyBadgeUnlockTotal = 0;
$totalUnlockedBadgeCount = 0;

$bookingHasAdminNotes = false;
$bookingHasStatusUpdatedAt = false;
$bookingHasStatusUpdatedBy = false;

require_once __DIR__ . '/includes/member-badge-roadmap.php';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from db.php.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    ensureDogJourneySchema($pdo);
    ensureBadgeVaultSchema($pdo);

    $userColumns = getColumns($pdo, 'users');

    $userIdCol = pickExistingColumn($userColumns, ['id', 'user_id']);
    $userNameCol = pickExistingColumn($userColumns, ['full_name', 'name', 'display_name', 'username']);
    $userFirstCol = pickExistingColumn($userColumns, ['first_name']);
    $userLastCol = pickExistingColumn($userColumns, ['last_name']);
    $userEmailCol = pickExistingColumn($userColumns, ['email']);
    $userPhoneCol = pickExistingColumn($userColumns, ['phone', 'phone_number', 'mobile']);
    $userStatusCol = pickExistingColumn($userColumns, ['status']);
    $userRoleCol = pickExistingColumn($userColumns, ['role']);
    $userCreatedCol = pickExistingColumn($userColumns, ['created_at', 'created_on', 'joined_at']);

    if ($userIdCol === null) {
        throw new RuntimeException('The users table is missing an ID column.');
    }

    $userSql = "
        SELECT
            " . buildSelectFragment($userIdCol, 'id', '0') . ",
            " . buildSelectFragment($userNameCol, 'full_name', "''") . ",
            " . buildSelectFragment($userFirstCol, 'first_name', "''") . ",
            " . buildSelectFragment($userLastCol, 'last_name', "''") . ",
            " . buildSelectFragment($userEmailCol, 'email', "''") . ",
            " . buildSelectFragment($userPhoneCol, 'phone', "''") . ",
            " . buildSelectFragment($userStatusCol, 'status', "''") . ",
            " . buildSelectFragment($userRoleCol, 'role', "'member'") . ",
            " . buildSelectFragment($userCreatedCol, 'created_at', "''") . "
        FROM " . quotedIdentifier('users') . "
        WHERE " . quotedIdentifier($userIdCol) . " = :user_id
        LIMIT 1
    ";

    $user = fetchOneSafe($pdo, $userSql, [':user_id' => $userId]);

    if (!$user) {
        safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode('Member not found'));
    }

    $derivedFullName = trim((string) ($user['full_name'] ?? ''));
    if ($derivedFullName === '') {
        $derivedFullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        if ($derivedFullName === '') {
            $derivedFullName = trim((string) ($user['email'] ?? ''));
        }
        if ($derivedFullName === '') {
            $derivedFullName = 'Member';
        }
        $user['full_name'] = $derivedFullName;
    }

    $dogs = fetchMemberPetsDetailed($pdo, $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_dog_journey') {
        $postedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedToken)) {
            safeRedirect('admin-member-view.php?id=' . $userId . '&status_type=error&status_message=' . urlencode('Security check failed. Please try again.'));
        }

        $petId = (int) ($_POST['pet_id'] ?? 0);
        $allowedPetIds = array_map(static fn(array $pet): int => (int) ($pet['pet_id'] ?? 0), $dogs);

        if ($petId <= 0 || !in_array($petId, $allowedPetIds, true)) {
            safeRedirect('admin-member-view.php?id=' . $userId . '&status_type=error&status_message=' . urlencode('That pet could not be matched to this member.'));
        }

        $favoriteService = trim((string) ($_POST['favorite_service'] ?? ''));
        $allowedFavoriteServices = ['', 'walk', 'daycare', 'boarding_night', 'drop_in', 'sitting'];
        if (!in_array($favoriteService, $allowedFavoriteServices, true)) {
            $favoriteService = '';
        }

        $lastServiceDate = trim((string) ($_POST['last_service_date'] ?? ''));
        if ($lastServiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastServiceDate)) {
            $lastServiceDate = '';
        }

        $manualBadge = trim((string) ($_POST['milestone_badge'] ?? ''));
        $journeyNote = trim((string) ($_POST['journey_note'] ?? ''));
        $journeyHighlight = trim((string) ($_POST['journey_highlight'] ?? ''));

        $petName = 'Dog';
        foreach ($dogs as $pet) {
            if ((int) ($pet['pet_id'] ?? 0) === $petId) {
                $petName = (string) ($pet['pet_name'] ?? $pet['display_name'] ?? 'Dog');
                break;
            }
        }

        $currentJourneyCards = buildDogJourneyCards($pdo, $userId, $dogs, fetchJourneyBookings($pdo, $userId), (string) ($user['created_at'] ?? ''));
        $currentJourneyCard = journeyCardForPet($currentJourneyCards, $petId);
        $previousBadge = trim((string) ($currentJourneyCard['milestone_badge'] ?? ''));

        upsertDogJourneyProfile($pdo, $userId, $petId, [
            'baseline_walks' => (int) ($_POST['baseline_walks'] ?? 0),
            'baseline_daycare_sessions' => (int) ($_POST['baseline_daycare_sessions'] ?? 0),
            'baseline_boarding_nights' => (int) ($_POST['baseline_boarding_nights'] ?? 0),
            'baseline_drop_in_sessions' => (int) ($_POST['baseline_drop_in_sessions'] ?? 0),
            'baseline_sitting_sessions' => (int) ($_POST['baseline_sitting_sessions'] ?? 0),
            'favorite_service' => $favoriteService,
            'milestone_badge' => $manualBadge,
            'journey_note' => $journeyNote,
            'journey_highlight' => $journeyHighlight,
            'last_service_date' => $lastServiceDate,
        ]);

        $updatedJourneyCards = buildDogJourneyCards($pdo, $userId, $dogs, fetchJourneyBookings($pdo, $userId), (string) ($user['created_at'] ?? ''));
        syncJourneyMilestoneBadges($pdo, $userId, $updatedJourneyCards);
        $updatedMembershipSummary = getMembershipSummary($pdo, $userId);
        syncFounderMembershipBadges($pdo, $userId, $updatedMembershipSummary);
        $updatedBadgeProgressSnapshot = buildMemberBadgeProgressSnapshot($updatedJourneyCards, fetchJourneyBookings($pdo, $userId), $updatedMembershipSummary, (string) ($user['created_at'] ?? ''));
        syncRoadmapAutoBadges($pdo, $userId, $updatedBadgeProgressSnapshot);
        $updatedJourneyCard = journeyCardForPet($updatedJourneyCards, $petId);
        $newBadge = trim((string) ($updatedJourneyCard['milestone_badge'] ?? ''));

        if ($newBadge !== '' && $newBadge !== $previousBadge) {
            $latestBadgeEntry = fetchLatestDogJourneyEntry($pdo, $userId, $petId, 'badge_award');
            $latestBadgeBody = trim((string) valueFromRow($latestBadgeEntry ?? [], ['entry_body'], ''));

            if ($latestBadgeBody !== $newBadge) {
                $entryDate = $lastServiceDate !== '' ? $lastServiceDate : date('Y-m-d');
                $entryTitle = $petName . ' unlocked a new Dog Journey badge';
                $entryBody = $newBadge;

                if ($manualBadge !== '') {
                    $entryBody .= ' · assigned manually by Doggie Dorian’s';
                } else {
                    $entryBody .= ' · automatically awarded from updated Dog Journey totals';
                }

                insertDogJourneyEntry($pdo, $userId, $petId, 'badge_award', $entryTitle, $entryBody, $entryDate, 1, 'dog_journey');
            }
        }

        $statusMessage = 'Dog Journey baseline saved successfully and the shared badge vault was refreshed.';
        if ($newBadge !== '' && $newBadge !== $previousBadge) {
            $statusMessage = 'Dog Journey baseline saved, the badge vault was refreshed, and ' . $petName . ' earned the ' . $newBadge . ' badge.';
        }

        safeRedirect('admin-member-view.php?id=' . $userId . '&status_type=success&status_message=' . urlencode($statusMessage));
    }

    if (tableExists($pdo, 'bookings')) {
        $bookingColumns = getColumns($pdo, 'bookings');

        $bookingUserCol = pickExistingColumn($bookingColumns, ['member_id', 'user_id', 'client_id']);
        $bookingServiceCol = pickExistingColumn($bookingColumns, ['service_type', 'service', 'booking_type', 'type']);
        $bookingDateCol = pickExistingColumn($bookingColumns, ['service_date', 'booking_date', 'created_at', 'created_on']);
        $bookingTimeCol = pickExistingColumn($bookingColumns, ['service_time', 'booking_time', 'time']);
        $bookingDurationCol = pickExistingColumn($bookingColumns, ['duration_minutes', 'duration']);
        $bookingStatusCol = pickExistingColumn($bookingColumns, ['status', 'booking_status', 'walk_status']);
        $bookingPriceCol = pickExistingColumn($bookingColumns, ['price', 'estimated_price', 'amount', 'total_price']);
        $bookingNotesCol = pickExistingColumn($bookingColumns, ['notes', 'client_notes', 'special_instructions']);
        $bookingAdminNotesCol = pickExistingColumn($bookingColumns, ['admin_notes']);
        $bookingStatusUpdatedAtCol = pickExistingColumn($bookingColumns, ['status_updated_at']);
        $bookingStatusUpdatedByCol = pickExistingColumn($bookingColumns, ['status_updated_by']);

        $bookingHasAdminNotes = $bookingAdminNotesCol !== null;
        $bookingHasStatusUpdatedAt = $bookingStatusUpdatedAtCol !== null;
        $bookingHasStatusUpdatedBy = $bookingStatusUpdatedByCol !== null;

        if ($bookingUserCol !== null) {
            $bookingSelectParts = [
                quotedIdentifier('id'),
                $bookingServiceCol !== null ? quotedIdentifier($bookingServiceCol) . ' AS ' . quotedIdentifier('display_service') : "'Service' AS " . quotedIdentifier('display_service'),
                $bookingDateCol !== null ? quotedIdentifier($bookingDateCol) . ' AS ' . quotedIdentifier('display_date') : "NULL AS " . quotedIdentifier('display_date'),
                $bookingTimeCol !== null ? quotedIdentifier($bookingTimeCol) . ' AS ' . quotedIdentifier('display_time') : "NULL AS " . quotedIdentifier('display_time'),
                $bookingDurationCol !== null ? quotedIdentifier($bookingDurationCol) . ' AS ' . quotedIdentifier('display_duration') : "NULL AS " . quotedIdentifier('display_duration'),
                $bookingStatusCol !== null ? quotedIdentifier($bookingStatusCol) . ' AS ' . quotedIdentifier('display_status') : "'Requested' AS " . quotedIdentifier('display_status'),
                $bookingPriceCol !== null ? quotedIdentifier($bookingPriceCol) . ' AS ' . quotedIdentifier('display_price') : "NULL AS " . quotedIdentifier('display_price'),
                $bookingNotesCol !== null ? quotedIdentifier($bookingNotesCol) . ' AS ' . quotedIdentifier('display_notes') : "NULL AS " . quotedIdentifier('display_notes'),
                $bookingAdminNotesCol !== null ? quotedIdentifier($bookingAdminNotesCol) . ' AS ' . quotedIdentifier('display_admin_notes') : "NULL AS " . quotedIdentifier('display_admin_notes'),
                $bookingStatusUpdatedAtCol !== null ? quotedIdentifier($bookingStatusUpdatedAtCol) . ' AS ' . quotedIdentifier('display_status_updated_at') : "NULL AS " . quotedIdentifier('display_status_updated_at'),
                $bookingStatusUpdatedByCol !== null ? quotedIdentifier($bookingStatusUpdatedByCol) . ' AS ' . quotedIdentifier('display_status_updated_by') : "NULL AS " . quotedIdentifier('display_status_updated_by'),
            ];

            $orderBy = $bookingDateCol !== null ? quotedIdentifier($bookingDateCol) . ' DESC' : quotedIdentifier('id') . ' DESC';

            $bookingSql = "
                SELECT " . implode(', ', $bookingSelectParts) . "
                FROM " . quotedIdentifier('bookings') . "
                WHERE " . quotedIdentifier($bookingUserCol) . " = :user_id
                ORDER BY {$orderBy}
                LIMIT 15
            ";

            $bookings = fetchAllSafe($pdo, $bookingSql, [':user_id' => $userId]);
        }
    }

    if (tableExists($pdo, 'client_profiles')) {
        $profileColumns = getColumns($pdo, 'client_profiles');
        $profileUserCol = pickExistingColumn($profileColumns, ['user_id', 'member_id', 'client_id']);

        if ($profileUserCol !== null) {
            $profileStmt = $pdo->prepare("
                SELECT *
                FROM " . quotedIdentifier('client_profiles') . "
                WHERE " . quotedIdentifier($profileUserCol) . " = :user_id
                LIMIT 1
            ");
            $profileStmt->execute([':user_id' => $userId]);
            $clientProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $journeyBookings = fetchJourneyBookings($pdo, $userId);
    $journeyCards = buildDogJourneyCards($pdo, $userId, $dogs, $journeyBookings, (string) ($user['created_at'] ?? ''));
    $membershipSummary = getMembershipSummary($pdo, $userId);

    syncFounderMembershipBadges($pdo, $userId, $membershipSummary);
    syncJourneyMilestoneBadges($pdo, $userId, $journeyCards);

    $badgeProgressSnapshot = buildMemberBadgeProgressSnapshot($journeyCards, $journeyBookings, $membershipSummary, (string) ($user['created_at'] ?? ''));
    syncRoadmapAutoBadges($pdo, $userId, $badgeProgressSnapshot);

    $founderBadgeCollection = buildFounderBadgeVault($pdo, $userId, $membershipSummary);
    $journeyBadgeVault = buildJourneyBadgeVault($pdo, $userId);
    $roadmapBadgeVault = buildRoadmapBadgeVault($pdo, $userId);
    $rewardTierSnapshot = buildRewardTierSnapshot($pdo, $userId);
    $journeyMilestoneCollection = (array) valueFromRow($journeyBadgeVault, ['milestone_collection'], []);
    $customJourneyBadgeCollection = (array) valueFromRow($journeyBadgeVault, ['custom_collection'], []);
    $roadmapBadgeSections = (array) valueFromRow($roadmapBadgeVault, ['sections'], []);
    $founderBadgeUnlockTotal = badgeVaultUnlockedCount($founderBadgeCollection);
    $journeyBadgeUnlockTotal = (int) valueFromRow($journeyBadgeVault, ['unlocked_count'], 0);
    $roadmapBadgeUnlockTotal = (int) valueFromRow($roadmapBadgeVault, ['unlocked_count'], 0);
    $totalUnlockedBadgeCount = (int) valueFromRow($rewardTierSnapshot, ['total_unlocked'], 0);
} catch (Throwable $e) {
    safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --panel2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --shadow:0 20px 50px rgba(0,0,0,0.35);

            --requested-bg:rgba(212,175,55,0.14);
            --requested-text:#f4e1a1;

            --confirmed-bg:rgba(88,166,255,0.16);
            --confirmed-text:#cde4ff;

            --progress-bg:rgba(168,85,247,0.16);
            --progress-text:#ead5ff;

            --completed-bg:rgba(34,197,94,0.16);
            --completed-text:#d7ffe4;

            --cancelled-bg:rgba(239,68,68,0.16);
            --cancelled-text:#ffd1d1;

            --default-bg:rgba(255,255,255,0.10);
            --default-text:#f8f5ee;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family:Inter, Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(255,255,255,0.05), transparent 24%),
                linear-gradient(180deg, #08080c 0%, #111119 100%);
        }

        .container{
            max-width:1200px;
            margin:40px auto;
            padding:20px;
        }

        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:16px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }

        .topbar h1{
            margin:0 0 8px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
        }

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:12px 16px;
            border-radius:14px;
            text-decoration:none;
            font-weight:800;
            border:none;
            cursor:pointer;
        }

        .btn-primary{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }

        .btn-secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
        }

        .btn-update{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            width:100%;
            margin-top:12px;
            font-size:14px;
        }

        .section{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:24px;
            margin-bottom:20px;
            box-shadow:var(--shadow);
        }

        .section h2{
            margin:0 0 14px;
            font-size:26px;
            letter-spacing:-0.4px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:14px;
        }

        .box{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .label{
            color:var(--gold-soft);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:6px;
            font-weight:800;
        }

        .value{
            color:var(--text);
            font-size:15px;
            line-height:1.5;
        }

        .list{
            display:grid;
            gap:14px;
        }

        .item{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:18px;
            padding:18px;
        }

        .item-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }

        .item-title{
            font-size:18px;
            font-weight:800;
            margin-bottom:8px;
        }

        .item-meta{
            color:var(--muted);
            line-height:1.7;
            font-size:14px;
        }

        .status-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:8px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
            border:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .status-requested{
            background:var(--requested-bg);
            color:var(--requested-text);
        }

        .status-confirmed{
            background:var(--confirmed-bg);
            color:var(--confirmed-text);
        }

        .status-in-progress{
            background:var(--progress-bg);
            color:var(--progress-text);
        }

        .status-completed{
            background:var(--completed-bg);
            color:var(--completed-text);
        }

        .status-cancelled{
            background:var(--cancelled-bg);
            color:var(--cancelled-text);
        }

        .status-default{
            background:var(--default-bg);
            color:var(--default-text);
        }

        .booking-layout{
            display:grid;
            grid-template-columns:1.15fr 0.85fr;
            gap:18px;
            align-items:start;
        }

        .status-form{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .status-form label{
            display:block;
            margin-bottom:8px;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.08em;
            color:var(--gold-soft);
        }

        .status-form select,
        .status-form textarea{
            width:100%;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(0,0,0,0.28);
            color:var(--text);
            padding:12px 13px;
            font:inherit;
            outline:none;
        }

        .status-form textarea{
            min-height:100px;
            resize:vertical;
        }

        .status-history{
            margin-top:10px;
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
        }

        .flash{
            margin-bottom:18px;
            padding:14px 16px;
            border-radius:16px;
            font-weight:700;
            border:1px solid rgba(255,255,255,0.08);
        }

        .flash-success{
            background:rgba(34,197,94,0.14);
            color:#d7ffe4;
        }

        .flash-error{
            background:rgba(239,68,68,0.14);
            color:#ffd1d1;
        }

        .empty{
            border:1px dashed rgba(255,255,255,0.14);
            border-radius:18px;
            padding:24px;
            text-align:center;
            color:var(--muted);
            background:rgba(255,255,255,0.03);
        }

        .journey-shell{
            display:grid;
            gap:18px;
        }

        .journey-card{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:18px;
            padding:18px;
        }

        .journey-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }

        .journey-copy{
            color:var(--muted);
            font-size:14px;
            line-height:1.7;
        }

        .journey-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:8px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
            border:1px solid rgba(212,175,55,0.25);
            background:rgba(212,175,55,0.14);
            color:var(--gold-soft);
            white-space:nowrap;
        }

        .journey-chip-row{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:16px;
        }

        .journey-chip{
            display:inline-flex;
            align-items:center;
            padding:7px 11px;
            border-radius:999px;
            background:rgba(255,255,255,0.05);
            border:1px solid rgba(255,255,255,0.08);
            color:var(--text);
            font-size:12px;
            font-weight:700;
        }

        .journey-highlight{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
            color:var(--text);
            line-height:1.7;
            margin-bottom:16px;
        }

        .journey-note{
            color:var(--muted);
            line-height:1.7;
            margin-top:-4px;
            margin-bottom:16px;
        }

        .journey-totals{
            display:grid;
            grid-template-columns:repeat(5, minmax(0,1fr));
            gap:12px;
            margin-bottom:18px;
        }

        .journey-total{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .journey-total-top{
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:flex-end;
            margin-bottom:8px;
        }

        .journey-total-number{
            font-size:26px;
            font-weight:800;
            color:var(--gold-soft);
            line-height:1;
        }

        .journey-total-meta{
            color:var(--muted);
            font-size:12px;
            line-height:1.6;
        }

        .journey-form{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:16px;
        }

        .journey-form-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:14px;
        }

        .journey-field{
            display:flex;
            flex-direction:column;
            gap:8px;
        }

        .journey-field-full{
            grid-column:1 / -1;
        }

        .journey-field label{
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.08em;
            color:var(--gold-soft);
        }

        .journey-input,
        .journey-select,
        .journey-textarea{
            width:100%;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(0,0,0,0.28);
            color:var(--text);
            padding:12px 13px;
            font:inherit;
            outline:none;
        }

        .journey-textarea{
            min-height:110px;
            resize:vertical;
        }

        .journey-helper{
            color:var(--muted);
            font-size:12px;
            line-height:1.6;
        }

        .badge-vault-shell{
            display:grid;
            gap:18px;
        }

        .badge-vault-top{
            display:grid;
            gap:8px;
            margin-bottom:18px;
        }

        .badge-vault-copy{
            color:var(--muted);
            line-height:1.7;
            font-size:14px;
        }

        .badge-vault-metrics{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:12px;
            margin-bottom:18px;
        }

        .badge-vault-metric{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .badge-vault-metric-label{
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:0.08em;
            font-size:11px;
            font-weight:800;
            margin-bottom:8px;
        }

        .badge-vault-metric-value{
            font-size:24px;
            font-weight:800;
            color:var(--gold-soft);
            line-height:1;
        }

        .badge-vault-section{
            display:grid;
            gap:12px;
        }

        .badge-vault-section-title{
            font-size:14px;
            font-weight:800;
            letter-spacing:0.04em;
        }

        .badge-vault-grid{
            display:grid;
            grid-template-columns:repeat(3, minmax(0,1fr));
            gap:12px;
        }

        .badge-vault-item{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:18px;
            padding:14px;
            display:grid;
            gap:10px;
        }

        .badge-vault-item.locked{
            opacity:0.56;
            background:rgba(255,255,255,0.02);
        }

        .badge-vault-item-top{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .badge-vault-mark{
            width:46px;
            height:46px;
            border-radius:14px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:1px solid rgba(255,255,255,0.08);
            background:rgba(255,255,255,0.05);
            color:var(--text);
            font-weight:900;
            letter-spacing:0.08em;
            font-size:14px;
        }

        .badge-vault-tier{
            display:grid;
            gap:12px;
            padding:16px;
            margin-bottom:18px;
            border-radius:18px;
            border:1px solid rgba(255,255,255,0.08);
            background:rgba(255,255,255,0.04);
        }

        .badge-vault-tier-top,
        .badge-vault-tier-meta{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .badge-vault-tier-label{
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:0.08em;
            font-size:11px;
            font-weight:800;
            margin-bottom:8px;
        }

        .badge-vault-tier-name{
            font-size:24px;
            line-height:1;
            font-weight:900;
            color:var(--gold-soft);
        }

        .badge-vault-tier-count{
            font-size:13px;
            font-weight:800;
            color:var(--muted);
        }

        .badge-vault-tier-copy,
        .badge-vault-tier-meta,
        .badge-vault-tier-reward{
            color:var(--muted);
            font-size:13px;
            line-height:1.7;
        }

        .badge-vault-tier-track{
            position:relative;
            height:10px;
            border-radius:999px;
            background:rgba(255,255,255,0.08);
            overflow:hidden;
        }

        .badge-vault-tier-fill{
            display:block;
            height:100%;
            border-radius:999px;
            background:linear-gradient(135deg, rgba(214,179,93,0.95), rgba(255,232,178,0.95));
        }

        .reward-tier-bronze .badge-vault-tier-fill{
            background:linear-gradient(135deg, rgba(173,119,74,0.95), rgba(238,188,136,0.95));
        }

        .reward-tier-silver .badge-vault-tier-fill{
            background:linear-gradient(135deg, rgba(156,168,184,0.95), rgba(231,237,245,0.95));
        }

        .reward-tier-gold .badge-vault-tier-fill{
            background:linear-gradient(135deg, rgba(214,179,93,0.95), rgba(255,232,178,0.95));
        }

        .reward-tier-platinum .badge-vault-tier-fill{
            background:linear-gradient(135deg, rgba(140,145,255,0.95), rgba(228,230,255,0.95));
        }

        .reward-tier-blacktag .badge-vault-tier-fill{
            background:linear-gradient(135deg, rgba(36,36,36,0.98), rgba(214,179,93,0.95));
        }

        .badge-tier-walk .badge-vault-mark{
            background:linear-gradient(135deg, rgba(177,140,78,0.28), rgba(226,196,141,0.14));
            color:#f4dfb1;
        }

        .badge-tier-care .badge-vault-mark{
            background:linear-gradient(135deg, rgba(110,145,205,0.24), rgba(169,198,255,0.12));
            color:#d8e6ff;
        }

        .badge-tier-elite .badge-vault-mark{
            background:linear-gradient(135deg, rgba(152,109,228,0.28), rgba(230,204,255,0.12));
            color:#ecd8ff;
        }

        .badge-tier-journey .badge-vault-mark{
            background:linear-gradient(135deg, rgba(198,178,139,0.28), rgba(245,224,186,0.12));
            color:#f7e7c6;
        }

        .badge-tier-journey-custom .badge-vault-mark{
            background:linear-gradient(135deg, rgba(118,154,206,0.26), rgba(208,226,255,0.12));
            color:#dce9ff;
        }

        .badge-tier-service-walk .badge-vault-mark{
            background:linear-gradient(135deg, rgba(214,179,93,0.26), rgba(255,232,178,0.08));
            color:#ffe6a8;
        }

        .badge-tier-service-daycare .badge-vault-mark{
            background:linear-gradient(135deg, rgba(138,110,255,0.28), rgba(198,183,255,0.08));
            color:#ece6ff;
        }

        .badge-tier-service-boarding .badge-vault-mark{
            background:linear-gradient(135deg, rgba(88,148,255,0.28), rgba(189,219,255,0.08));
            color:#e4f1ff;
        }

        .badge-tier-service-dropin .badge-vault-mark{
            background:linear-gradient(135deg, rgba(76,190,171,0.28), rgba(193,255,242,0.08));
            color:#dbfff8;
        }

        .badge-tier-service-sitting .badge-vault-mark{
            background:linear-gradient(135deg, rgba(219,133,94,0.28), rgba(255,217,191,0.08));
            color:#fff0e1;
        }

        .badge-tier-service-multi .badge-vault-mark{
            background:linear-gradient(135deg, rgba(244,196,48,0.22), rgba(124,92,255,0.18));
            color:#fff1c6;
        }

        .badge-tier-loyalty .badge-vault-mark{
            background:linear-gradient(135deg, rgba(132,221,118,0.24), rgba(228,255,214,0.08));
            color:#ecffe3;
        }

        .badge-vault-state{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:8px 11px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
            border:1px solid rgba(255,255,255,0.08);
            background:rgba(255,255,255,0.06);
            color:var(--text);
            white-space:nowrap;
        }

        .badge-vault-name{
            font-size:16px;
            font-weight:800;
        }

        .badge-vault-membership{
            color:var(--muted);
            font-size:13px;
            font-weight:700;
        }

        .badge-vault-desc,
        .badge-vault-meta{
            color:var(--muted);
            line-height:1.6;
            font-size:13px;
        }

        .badge-vault-reward{
            color:var(--gold-soft);
            line-height:1.6;
            font-size:12px;
            font-weight:700;
        }

        .badge-vault-list{
            display:grid;
            gap:10px;
        }

        .badge-vault-list-item{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:13px 14px;
            display:grid;
            gap:8px;
        }

        .badge-vault-list-top{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .badge-vault-list-left{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:0;
        }

        .badge-vault-list-name{
            font-size:14px;
            font-weight:800;
        }

        .badge-vault-count{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:7px 10px;
            border-radius:999px;
            background:rgba(212,175,55,0.14);
            border:1px solid rgba(212,175,55,0.22);
            color:var(--gold-soft);
            font-size:11px;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
        }

        @media (max-width: 1100px){
            .grid{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }

            .booking-layout{
                grid-template-columns:1fr;
            }

            .journey-totals,
            .badge-vault-grid,
            .badge-vault-metrics{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
        }

        @media (max-width: 700px){
            .grid{
                grid-template-columns:1fr;
            }

            .journey-form-grid,
            .journey-totals,
            .badge-vault-grid,
            .badge-vault-metrics{
                grid-template-columns:1fr;
            }

            .topbar h1{
                font-size:32px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="topbar">
        <div>
            <h1><?php echo h($user['full_name'] ?? 'Member'); ?></h1>
            <div class="sub">Full member profile, pets, and booking history.</div>
        </div>

        <div class="actions">
            <a href="admin-add-dog.php?user_id=<?php echo (int) $userId; ?>" class="btn btn-primary">+ Add Dog</a>
            <a href="admin-create-booking.php?user_id=<?php echo (int) $userId; ?>" class="btn btn-primary">+ Create Booking</a>
            <a href="admin-members.php" class="btn btn-secondary">← Back to Members</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
            <?php echo h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <section class="section">
        <h2>Member Information</h2>
        <div class="grid">
            <div class="box">
                <div class="label">Full Name</div>
                <div class="value"><?php echo h($user['full_name'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Email</div>
                <div class="value"><?php echo h($user['email'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Phone</div>
                <div class="value"><?php echo h($user['phone'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Status</div>
                <div class="value"><?php echo h($user['status'] !== '' ? $user['status'] : 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Role</div>
                <div class="value"><?php echo h($user['role'] ?? 'member'); ?></div>
            </div>

            <div class="box">
                <div class="label">Joined</div>
                <div class="value"><?php echo formatDate($user['created_at'] ?? ''); ?></div>
            </div>

            <div class="box">
                <div class="label">User ID</div>
                <div class="value"><?php echo h((string) ($user['id'] ?? 'N/A')); ?></div>
            </div>

            <div class="box">
                <div class="label">Client Profile</div>
                <div class="value"><?php echo $clientProfile ? 'Found' : 'Not found'; ?></div>
            </div>
        </div>
    </section>

    <?php if ($clientProfile): ?>
        <section class="section">
            <h2>Client Profile</h2>
            <div class="grid">
                <?php foreach ($clientProfile as $key => $value): ?>
                    <?php if (in_array((string) $key, ['id', 'user_id', 'member_id', 'client_id'], true)) continue; ?>
                    <div class="box">
                        <div class="label"><?php echo h(ucwords(str_replace('_', ' ', (string) $key))); ?></div>
                        <div class="value"><?php echo h((string) ($value !== null && $value !== '' ? $value : 'N/A')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section">
        <h2>Dogs</h2>

        <?php if (empty($dogs)): ?>
            <div class="empty">No dogs found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($dogs as $dog): ?>
                    <div class="item">
                        <div class="item-title"><?php echo h($dog['display_name'] ?? 'Dog'); ?></div>
                        <div class="item-meta">
                            Breed: <?php echo h($dog['display_breed'] ?? 'N/A'); ?><br>
                            Age: <?php echo h((string) ($dog['display_age'] ?? 'N/A')); ?><br>
                            Notes: <?php echo h($dog['display_notes'] ?? 'N/A'); ?><br>
                            Added: <?php echo formatDateTime($dog['display_created'] ?? ''); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>


    <section class="section">
        <h2>Dog Journey Dashboard</h2>
        <div class="sub" style="margin-bottom:16px;">
            Manual baseline totals stack on top of live member booking history so pre-launch care is preserved without changing your live booking records.
        </div>

        <?php if (empty($journeyCards)): ?>
            <div class="empty">No pets are connected to this member yet. Add a dog first, then seed their Dog Journey baseline here.</div>
        <?php else: ?>
            <div class="journey-shell">
                <?php foreach ($journeyCards as $journey): ?>
                    <div class="journey-card">
                        <div class="journey-head">
                            <div>
                                <div class="item-title"><?php echo h($journey['pet_name'] ?? 'Dog'); ?></div>
                                <div class="item-meta">
                                    Breed: <?php echo h($journey['breed'] !== '' ? $journey['breed'] : 'N/A'); ?><br>
                                    Age: <?php echo h($journey['age'] !== '' ? (string) $journey['age'] : 'N/A'); ?><br>
                                    Favorite Service: <?php echo h($journey['favorite_service'] !== '' ? formatJourneyServiceLabel($journey['favorite_service']) : 'Auto Calculate'); ?><br>
                                    Last Service Date: <?php echo formatDate($journey['last_service_date'] ?? ''); ?><br>
                                    Milestone: <?php echo h($journey['milestone_badge'] !== '' ? $journey['milestone_badge'] : 'Journey Begins'); ?>
                                </div>
                            </div>

                            <div class="journey-badge">
                                Total Services: <?php echo (int) ($journey['total_services'] ?? 0); ?>
                            </div>
                        </div>

                        <div class="journey-chip-row">
                            <span class="journey-chip">Displayed totals = Manual baseline + live bookings</span>
                            <span class="journey-chip">Member since <?php echo formatDate($journey['member_since'] ?? ''); ?></span>
                        </div>

                        <div class="journey-highlight">
                            <?php echo h($journey['journey_highlight'] ?? ''); ?>
                        </div>

                        <?php if (trim((string) ($journey['journey_note'] ?? '')) !== ''): ?>
                            <div class="journey-note">
                                Journey Note: <?php echo h($journey['journey_note']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="journey-totals">
                            <?php
                            $journeyMetricLabels = [
                                'walk' => 'Walks',
                                'daycare' => 'Daycare',
                                'boarding_night' => 'Boarding',
                                'drop_in' => 'Drop-Ins',
                                'sitting' => 'Sitting',
                            ];
                            ?>
                            <?php foreach ($journeyMetricLabels as $metricKey => $metricLabel): ?>
                                <div class="journey-total">
                                    <div class="journey-total-top">
                                        <div class="label"><?php echo h($metricLabel); ?></div>
                                        <div class="journey-total-number"><?php echo (int) (($journey['counts'][$metricKey] ?? 0)); ?></div>
                                    </div>
                                    <div class="journey-total-meta">
                                        Baseline: <?php echo (int) (($journey['baseline_counts'][$metricKey] ?? 0)); ?><br>
                                        Live: <?php echo (int) (($journey['live_counts'][$metricKey] ?? 0)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" class="journey-form">
                            <input type="hidden" name="action" value="save_dog_journey">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="pet_id" value="<?php echo (int) ($journey['pet_id'] ?? 0); ?>">

                            <div class="journey-form-grid">
                                <div class="journey-field">
                                    <label for="baseline_walks_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Baseline Walks</label>
                                    <input class="journey-input" type="number" min="0" step="1" name="baseline_walks" id="baseline_walks_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo (int) (($journey['baseline_counts']['walk'] ?? 0)); ?>">
                                </div>

                                <div class="journey-field">
                                    <label for="baseline_daycare_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Baseline Daycare Sessions</label>
                                    <input class="journey-input" type="number" min="0" step="1" name="baseline_daycare_sessions" id="baseline_daycare_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo (int) (($journey['baseline_counts']['daycare'] ?? 0)); ?>">
                                </div>

                                <div class="journey-field">
                                    <label for="baseline_boarding_nights_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Baseline Boarding Nights</label>
                                    <input class="journey-input" type="number" min="0" step="1" name="baseline_boarding_nights" id="baseline_boarding_nights_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo (int) (($journey['baseline_counts']['boarding_night'] ?? 0)); ?>">
                                </div>

                                <div class="journey-field">
                                    <label for="baseline_drop_in_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Baseline Drop-Ins</label>
                                    <input class="journey-input" type="number" min="0" step="1" name="baseline_drop_in_sessions" id="baseline_drop_in_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo (int) (($journey['baseline_counts']['drop_in'] ?? 0)); ?>">
                                </div>

                                <div class="journey-field">
                                    <label for="baseline_sitting_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Baseline Sitting Sessions</label>
                                    <input class="journey-input" type="number" min="0" step="1" name="baseline_sitting_sessions" id="baseline_sitting_sessions_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo (int) (($journey['baseline_counts']['sitting'] ?? 0)); ?>">
                                </div>

                                <div class="journey-field">
                                    <label for="favorite_service_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Favorite Service</label>
                                    <select class="journey-select" name="favorite_service" id="favorite_service_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">
                                        <?php
                                        $favoriteOptions = [
                                            '' => 'Auto Calculate',
                                            'walk' => 'Walks',
                                            'daycare' => 'Daycare Sessions',
                                            'boarding_night' => 'Boarding Nights',
                                            'drop_in' => 'Drop-Ins',
                                            'sitting' => 'Sitting Sessions',
                                        ];
                                        ?>
                                        <?php foreach ($favoriteOptions as $favoriteValue => $favoriteLabel): ?>
                                            <option value="<?php echo h($favoriteValue); ?>" <?php echo ($journey['manual_favorite_service'] ?? '') === $favoriteValue ? 'selected' : ''; ?>>
                                                <?php echo h($favoriteLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="journey-field">
                                    <label for="last_service_date_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Manual Last Service Date</label>
                                    <input class="journey-input" type="date" name="last_service_date" id="last_service_date_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo h((string) ($journey['manual_last_service_date'] ?? '')); ?>">
                                    <div class="journey-helper">Used only when there is no newer live booking date.</div>
                                </div>

                                <div class="journey-field">
                                    <label for="milestone_badge_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Milestone Badge</label>
                                    <input class="journey-input" type="text" name="milestone_badge" id="milestone_badge_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo h((string) ($journey['manual_milestone_badge'] ?? '')); ?>" placeholder="Leave blank to auto-generate">
                                    <div class="journey-helper">Leave blank to auto-award the badge from the updated Dog Journey totals. Saving a new badge now also writes into the shared Member Badge Vault used on the member dashboard.</div>
                                </div>

                                <div class="journey-field journey-field-full">
                                    <label for="journey_highlight_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Journey Highlight</label>
                                    <input class="journey-input" type="text" name="journey_highlight" id="journey_highlight_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" value="<?php echo h((string) ($journey['manual_journey_highlight'] ?? '')); ?>" placeholder="Short premium summary shown on the member dashboard">
                                </div>

                                <div class="journey-field journey-field-full">
                                    <label for="journey_note_<?php echo (int) ($journey['pet_id'] ?? 0); ?>">Journey Note</label>
                                    <textarea class="journey-textarea" name="journey_note" id="journey_note_<?php echo (int) ($journey['pet_id'] ?? 0); ?>" placeholder="Add a private-looking but member-facing note or milestone memory..."><?php echo h((string) ($journey['manual_journey_note'] ?? '')); ?></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-update">Save Dog Journey Baseline</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    
    <section class="section">
        <h2>Member Badge Vault Preview</h2>
        <div class="sub" style="margin-bottom:16px;">
            This preview now mirrors the expanded member dashboard vault, including founder distinctions, journey milestones, service collections, loyalty badges, and the visible reward tier.
        </div>

        <div class="badge-vault-shell">
            <div class="badge-vault-tier <?php echo h((string) ($rewardTierSnapshot['theme_class'] ?? '')); ?>">
                <div class="badge-vault-tier-top">
                    <div>
                        <div class="badge-vault-tier-label">Visible Reward Tier</div>
                        <div class="badge-vault-tier-name"><?php echo h((string) ($rewardTierSnapshot['current_tier_name'] ?? 'Bronze Collar')); ?></div>
                    </div>
                    <div class="badge-vault-tier-count"><?php echo (int) ($rewardTierSnapshot['total_unlocked'] ?? 0); ?> badges</div>
                </div>
                <div class="badge-vault-tier-copy"><?php echo h((string) ($rewardTierSnapshot['reward_note'] ?? 'Your reward tier grows as this member’s badge vault expands.')); ?></div>
                <div class="badge-vault-tier-track">
                    <span class="badge-vault-tier-fill" style="width: <?php echo h((string) ($rewardTierSnapshot['progress_percent'] ?? 0)); ?>%;"></span>
                </div>
                <div class="badge-vault-tier-meta">
                    <span><?php echo h((string) ($rewardTierSnapshot['range_label'] ?? '0+ badges')); ?></span>
                    <span><?php echo h((string) ($rewardTierSnapshot['next_tier_message'] ?? '')); ?></span>
                </div>
                <div class="badge-vault-tier-reward">Current plan: <?php echo h((string) ($membershipSummary['membership_name'] ?? 'No Membership')); ?></div>
            </div>

            <div class="badge-vault-metrics">
                <div class="badge-vault-metric">
                    <div class="badge-vault-metric-label">Unlocked</div>
                    <div class="badge-vault-metric-value"><?php echo (int) $totalUnlockedBadgeCount; ?></div>
                </div>
                <div class="badge-vault-metric">
                    <div class="badge-vault-metric-label">Founder Badges</div>
                    <div class="badge-vault-metric-value"><?php echo (int) $founderBadgeUnlockTotal; ?>/3</div>
                </div>
                <div class="badge-vault-metric">
                    <div class="badge-vault-metric-label">Journey Badges</div>
                    <div class="badge-vault-metric-value"><?php echo (int) $journeyBadgeUnlockTotal; ?></div>
                </div>
                <div class="badge-vault-metric">
                    <div class="badge-vault-metric-label">Roadmap Badges</div>
                    <div class="badge-vault-metric-value"><?php echo (int) $roadmapBadgeUnlockTotal; ?></div>
                </div>
            </div>

            <div class="badge-vault-section">
                <div class="badge-vault-section-title">Founder Membership Badges</div>
                <div class="badge-vault-grid">
                    <?php foreach ($founderBadgeCollection as $badge): ?>
                        <div class="badge-vault-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h((string) ($badge['theme_class'] ?? '')); ?>">
                            <div class="badge-vault-item-top">
                                <div class="badge-vault-mark"><?php echo h((string) ($badge['badge_mark'] ?? 'BDG')); ?></div>
                                <div class="badge-vault-state"><?php echo h((string) ($badge['status_label'] ?? 'Locked')); ?></div>
                            </div>
                            <div class="badge-vault-name"><?php echo h((string) ($badge['badge_name'] ?? 'Badge')); ?></div>
                            <div class="badge-vault-membership"><?php echo h((string) ($badge['membership_name'] ?? 'Founder Membership')); ?></div>
                            <div class="badge-vault-desc"><?php echo h((string) ($badge['description'] ?? '')); ?></div>
                            <div class="badge-vault-reward"><?php echo h((string) ($badge['reward_title'] ?? 'Reward Slot')); ?>: <?php echo h((string) ($badge['reward_note'] ?? 'Ready for future founder rewards.')); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="badge-vault-section">
                <div class="badge-vault-section-title">Dog Journey Milestone Badges</div>
                <div class="badge-vault-grid">
                    <?php foreach ($journeyMilestoneCollection as $badge): ?>
                        <div class="badge-vault-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h((string) ($badge['theme_class'] ?? '')); ?>">
                            <div class="badge-vault-item-top">
                                <div class="badge-vault-mark"><?php echo h((string) ($badge['badge_mark'] ?? 'BDG')); ?></div>
                                <div class="badge-vault-state"><?php echo h((string) ($badge['status_label'] ?? 'Locked')); ?></div>
                            </div>
                            <div class="badge-vault-name"><?php echo h((string) ($badge['badge_name'] ?? 'Badge')); ?></div>
                            <div class="badge-vault-desc"><?php echo h((string) ($badge['description'] ?? '')); ?></div>
                            <?php if (!empty($badge['pet_names'])): ?>
                                <div class="badge-vault-meta">Earned by: <?php echo h(implode(', ', (array) ($badge['pet_names'] ?? []))); ?></div>
                            <?php else: ?>
                                <div class="badge-vault-meta">This milestone stays locked until one of this member’s dogs reaches it.</div>
                            <?php endif; ?>
                            <div class="badge-vault-reward"><?php echo h((string) ($badge['reward_title'] ?? 'Reward Slot')); ?>: <?php echo h((string) ($badge['reward_note'] ?? 'Ready for future journey rewards.')); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($roadmapBadgeSections as $section): ?>
                <div class="badge-vault-section">
                    <div class="badge-vault-section-title"><?php echo h((string) ($section['title'] ?? 'Badge Collection')); ?> · <?php echo (int) ($section['unlocked_count'] ?? 0); ?>/<?php echo (int) ($section['total_count'] ?? 0); ?></div>
                    <div class="badge-vault-grid">
                        <?php foreach ((array) ($section['items'] ?? []) as $badge): ?>
                            <div class="badge-vault-item <?php echo !empty($badge['unlocked']) ? 'unlocked' : 'locked'; ?> <?php echo h((string) ($badge['theme_class'] ?? '')); ?>">
                                <div class="badge-vault-item-top">
                                    <div class="badge-vault-mark"><?php echo h((string) ($badge['badge_mark'] ?? 'BDG')); ?></div>
                                    <div class="badge-vault-state"><?php echo h((string) ($badge['status_label'] ?? 'Locked')); ?></div>
                                </div>
                                <div class="badge-vault-name"><?php echo h((string) ($badge['badge_name'] ?? 'Badge')); ?></div>
                                <div class="badge-vault-desc"><?php echo h((string) ($badge['description'] ?? '')); ?></div>
                                <div class="badge-vault-reward"><?php echo h((string) ($badge['reward_title'] ?? 'Reward Slot')); ?>: <?php echo h((string) ($badge['reward_note'] ?? 'Ready for future member rewards.')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="badge-vault-section">
                <div class="badge-vault-section-title">Custom Journey Badges</div>
                <?php if (empty($customJourneyBadgeCollection)): ?>
                    <div class="empty">Custom Dog Journey badges will appear here whenever a unique collectible is awarded through a member profile update.</div>
                <?php else: ?>
                    <div class="badge-vault-list">
                        <?php foreach ($customJourneyBadgeCollection as $badge): ?>
                            <div class="badge-vault-list-item">
                                <div class="badge-vault-list-top">
                                    <div class="badge-vault-list-left">
                                        <div class="badge-vault-mark"><?php echo h((string) ($badge['badge_mark'] ?? 'BDG')); ?></div>
                                        <div class="badge-vault-list-name"><?php echo h((string) ($badge['badge_name'] ?? 'Custom Badge')); ?></div>
                                    </div>
                                    <div class="badge-vault-count"><?php echo count((array) ($badge['pet_names'] ?? [])); ?> dog<?php echo count((array) ($badge['pet_names'] ?? [])) === 1 ? '' : 's'; ?></div>
                                </div>
                                <div class="badge-vault-meta"><?php echo h((string) ($badge['description'] ?? '')); ?></div>
                                <?php if (!empty($badge['pet_names'])): ?>
                                    <div class="badge-vault-meta">Seen on: <?php echo h(implode(', ', (array) ($badge['pet_names'] ?? []))); ?></div>
                                <?php endif; ?>
                                <div class="badge-vault-reward"><?php echo h((string) ($badge['reward_title'] ?? 'Reward Slot')); ?>: <?php echo h((string) ($badge['reward_note'] ?? 'Ready for future custom rewards.')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

</section>

    <section class="section">
        <h2>Recent Bookings</h2>

        <?php if (empty($bookings)): ?>
            <div class="empty">No bookings found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $bookingId = (int) ($booking['id'] ?? 0);
                    $rawStatus = trim((string) ($booking['display_status'] ?? 'Requested'));
                    $currentStatus = normalizeStatusLabel($rawStatus);
                    $adminNotes = trim((string) ($booking['display_admin_notes'] ?? ''));
                    $statusUpdatedAt = trim((string) ($booking['display_status_updated_at'] ?? ''));
                    $statusUpdatedBy = trim((string) ($booking['display_status_updated_by'] ?? ''));
                    ?>
                    <div class="item">
                        <div class="booking-layout">
                            <div>
                                <div class="item-head">
                                    <div>
                                        <div class="item-title"><?php echo h($booking['display_service'] ?? 'Service'); ?></div>
                                    </div>
                                    <span class="status-badge <?php echo h(buildStatusBadgeClass($rawStatus)); ?>">
                                        <?php echo h($currentStatus); ?>
                                    </span>
                                </div>

                                <div class="item-meta">
                                    Date: <?php echo formatDate($booking['display_date'] ?? ''); ?><br>
                                    Time: <?php echo h($booking['display_time'] ?? 'N/A'); ?><br>
                                    Duration: <?php echo h((string) ($booking['display_duration'] ?? 'N/A')); ?><br>
                                    Price: <?php echo formatMoney($booking['display_price'] ?? null); ?><br>
                                    Client Notes: <?php echo h($booking['display_notes'] ?? 'N/A'); ?><br>
                                    Admin Notes:
                                    <?php
                                    if ($bookingHasAdminNotes) {
                                        echo h($adminNotes !== '' ? $adminNotes : 'None');
                                    } else {
                                        echo 'Run the booking status database upgrade first.';
                                    }
                                    ?>
                                </div>

                                <?php if ($bookingHasStatusUpdatedAt || $bookingHasStatusUpdatedBy): ?>
                                    <div class="status-history">
                                        <?php if ($bookingHasStatusUpdatedAt && $statusUpdatedAt !== ''): ?>
                                            Last updated: <?php echo formatDateTime($statusUpdatedAt); ?><br>
                                        <?php endif; ?>
                                        <?php if ($bookingHasStatusUpdatedBy && $statusUpdatedBy !== ''): ?>
                                            Updated by: <?php echo h($statusUpdatedBy); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <?php if ($bookingHasAdminNotes): ?>
                                    <form method="post" action="admin-update-booking-status.php" class="status-form">
                                        <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                                        <label for="status_<?php echo $bookingId; ?>">Booking Status</label>
                                        <select name="status" id="status_<?php echo $bookingId; ?>" required>
                                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                                <option value="<?php echo h($statusOption); ?>" <?php echo $currentStatus === $statusOption ? 'selected' : ''; ?>>
                                                    <?php echo h($statusOption); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="admin_notes_<?php echo $bookingId; ?>" style="margin-top:12px;">Admin Notes</label>
                                        <textarea
                                            name="admin_notes"
                                            id="admin_notes_<?php echo $bookingId; ?>"
                                            placeholder="Add internal notes about this booking status update..."
                                        ><?php echo h($adminNotes); ?></textarea>

                                        <button type="submit" class="btn btn-update">Update Booking Status</button>
                                    </form>
                                <?php else: ?>
                                    <div class="empty">
                                        This booking table needs the new status fields before inline updates can work.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

</body>
</html>
