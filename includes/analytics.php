<?php
declare(strict_types=1);

if (!function_exists('dd_analytics_h')) {
    function dd_analytics_h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dd_analytics_quoted_identifier')) {
    function dd_analytics_quoted_identifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}

if (!function_exists('dd_analytics_table_exists')) {
    function dd_analytics_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];

        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute([':table' => $table]);
            $cache[$key] = (bool) $stmt->fetchColumn();
            return $cache[$key];
        } catch (Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('dd_analytics_get_columns')) {
    function dd_analytics_get_columns(PDO $pdo, string $table): array
    {
        static $cache = [];

        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (!dd_analytics_table_exists($pdo, $table)) {
            $cache[$key] = [];
            return [];
        }

        try {
            $stmt = $pdo->query('PRAGMA table_info(' . dd_analytics_quoted_identifier($table) . ')');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $columns = [];

            foreach ($rows as $row) {
                if (!empty($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }

            $cache[$key] = $columns;
            return $columns;
        } catch (Throwable $e) {
            $cache[$key] = [];
            return [];
        }
    }
}

if (!function_exists('dd_analytics_pick_column')) {
    function dd_analytics_pick_column(array $columns, array $choices): ?string
    {
        foreach ($choices as $choice) {
            if (in_array($choice, $columns, true)) {
                return $choice;
            }
        }

        return null;
    }
}

if (!function_exists('dd_analytics_first_existing_column')) {
    function dd_analytics_first_existing_column(PDO $pdo, string $table, array $choices): ?string
    {
        return dd_analytics_pick_column(dd_analytics_get_columns($pdo, $table), $choices);
    }
}

if (!function_exists('dd_analytics_value_from_row')) {
    function dd_analytics_value_from_row(array $row, array $candidates, mixed $default = null): mixed
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
                return $row[$candidate];
            }
        }

        return $default;
    }
}

if (!function_exists('dd_analytics_safe_fetch_all')) {
    function dd_analytics_safe_fetch_all(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                return [];
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('dd_analytics_safe_fetch_one')) {
    function dd_analytics_safe_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
    {
        try {
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                return null;
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dd_analytics_safe_fetch_value')) {
    function dd_analytics_safe_fetch_value(PDO $pdo, string $sql, array $params = [], mixed $default = null): mixed
    {
        try {
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                return $default;
            }

            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('dd_analytics_ensure_column')) {
    function dd_analytics_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!dd_analytics_table_exists($pdo, $table)) {
            return;
        }

        if (in_array($column, dd_analytics_get_columns($pdo, $table), true)) {
            return;
        }

        try {
            $pdo->exec('ALTER TABLE ' . dd_analytics_quoted_identifier($table) . ' ADD COLUMN ' . dd_analytics_quoted_identifier($column) . ' ' . $definition);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('dd_analytics_now')) {
    function dd_analytics_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('dd_analytics_generate_uuid')) {
    function dd_analytics_generate_uuid(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (Throwable $e) {
            $bytes = md5(uniqid((string) mt_rand(), true), true);
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex((string) $bytes), 4));
    }
}

if (!function_exists('dd_analytics_request_uri_path')) {
    function dd_analytics_request_uri_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return $path;
    }
}

if (!function_exists('dd_analytics_full_url')) {
    function dd_analytics_full_url(): string
    {
        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        ) {
            $scheme = 'https';
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'dorianspetcare.com'));
        if ($host === '') {
            $host = 'dorianspetcare.com';
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '') {
            $uri = '/';
        }

        return $scheme . '://' . $host . $uri;
    }
}

if (!function_exists('dd_analytics_get_client_ip')) {
    function dd_analytics_get_client_ip(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            (string) ($_SERVER['HTTP_CLIENT_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $candidate));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return '';
    }
}

if (!function_exists('dd_analytics_is_public_ip')) {
    function dd_analytics_is_public_ip(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

if (!function_exists('dd_analytics_hash_ip')) {
    function dd_analytics_hash_ip(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        return hash_hmac('sha256', $ip, 'doggie-dorians-analytics-salt-v1');
    }
}

if (!function_exists('dd_analytics_referrer_domain')) {
    function dd_analytics_referrer_domain(string $referrerUrl): string
    {
        if ($referrerUrl === '') {
            return '';
        }

        $host = parse_url($referrerUrl, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}

if (!function_exists('dd_analytics_parse_utm')) {
    function dd_analytics_parse_utm(): array
    {
        return [
            'source' => trim((string) ($_GET['utm_source'] ?? '')),
            'medium' => trim((string) ($_GET['utm_medium'] ?? '')),
            'campaign' => trim((string) ($_GET['utm_campaign'] ?? '')),
            'term' => trim((string) ($_GET['utm_term'] ?? '')),
            'content' => trim((string) ($_GET['utm_content'] ?? '')),
        ];
    }
}

if (!function_exists('dd_analytics_normalize_source')) {
    function dd_analytics_normalize_source(array $sessionRow): string
    {
        $utmSource = trim((string) ($sessionRow['utm_source'] ?? ''));
        $referrerDomain = trim((string) ($sessionRow['referrer_domain'] ?? ''));
        $landingPage = trim((string) ($sessionRow['landing_page'] ?? ''));

        if ($utmSource !== '') {
            return $utmSource;
        }
        if ($referrerDomain !== '') {
            return $referrerDomain;
        }
        if ($landingPage !== '') {
            return 'direct';
        }

        return 'unknown';
    }
}

if (!function_exists('dd_analytics_detect_bot')) {
    function dd_analytics_detect_bot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|preview|monitor|uptime|headless|postman/i', $userAgent);
    }
}

if (!function_exists('dd_analytics_parse_user_agent')) {
    function dd_analytics_parse_user_agent(string $userAgent): array
    {
        $ua = strtolower($userAgent);
        $deviceType = 'desktop';
        $browser = 'Unknown';
        $os = 'Unknown';

        if (preg_match('/ipad|tablet|kindle|silk/i', $userAgent)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/iphone|android.+mobile|windows phone|ipod|mobile/i', $userAgent)) {
            $deviceType = 'mobile';
        }

        if (preg_match('/edg\//i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/opr\/|opera/i', $userAgent)) {
            $browser = 'Opera';
        } elseif (preg_match('/chrome\//i', $userAgent) && !preg_match('/edg\//i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/safari\//i', $userAgent) && !preg_match('/chrome\//i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/firefox\//i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/msie|trident/i', $userAgent)) {
            $browser = 'Internet Explorer';
        }

        if (preg_match('/windows nt/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/mac os x|macintosh/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os,
            'is_bot' => dd_analytics_detect_bot($userAgent) ? 1 : 0,
        ];
    }
}

if (!function_exists('dd_analytics_should_track_request')) {
    function dd_analytics_should_track_request(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $path = dd_analytics_request_uri_path();
        $basename = basename($path);
        $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));

        if (in_array($ext, ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map', 'xml', 'txt', 'pdf', 'zip', 'mp4', 'mov', 'webm'], true)) {
            return false;
        }

        if (
            $basename === 'analytics-collect.php'
            || str_starts_with($basename, 'api-')
            || str_contains($basename, 'webhook')
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('dd_analytics_should_inject_script')) {
    function dd_analytics_should_inject_script(): bool
    {
        if (!dd_analytics_should_track_request()) {
            return false;
        }

        $path = dd_analytics_request_uri_path();
        $basename = basename($path);

        if (str_starts_with($basename, 'api-') || $basename === 'analytics-collect.php') {
            return false;
        }

        return true;
    }
}

if (!function_exists('dd_analytics_ensure_schema')) {
    function dd_analytics_ensure_schema(PDO $pdo): void
    {
        static $done = [];

        $key = spl_object_id($pdo);
        if (!empty($done[$key])) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_visitors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_uuid TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL DEFAULT 0,
                first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                visit_count INTEGER NOT NULL DEFAULT 0,
                session_count INTEGER NOT NULL DEFAULT 0,
                pageview_count INTEGER NOT NULL DEFAULT 0,
                first_landing_page TEXT DEFAULT '',
                latest_landing_page TEXT DEFAULT '',
                first_referrer_url TEXT DEFAULT '',
                latest_referrer_url TEXT DEFAULT '',
                first_referrer_domain TEXT DEFAULT '',
                latest_referrer_domain TEXT DEFAULT '',
                first_utm_source TEXT DEFAULT '',
                latest_utm_source TEXT DEFAULT '',
                first_utm_medium TEXT DEFAULT '',
                latest_utm_medium TEXT DEFAULT '',
                first_utm_campaign TEXT DEFAULT '',
                latest_utm_campaign TEXT DEFAULT '',
                first_country_name TEXT DEFAULT '',
                latest_country_name TEXT DEFAULT '',
                first_region_name TEXT DEFAULT '',
                latest_region_name TEXT DEFAULT '',
                first_city_name TEXT DEFAULT '',
                latest_city_name TEXT DEFAULT '',
                first_device_type TEXT DEFAULT '',
                latest_device_type TEXT DEFAULT '',
                first_browser TEXT DEFAULT '',
                latest_browser TEXT DEFAULT '',
                first_os TEXT DEFAULT '',
                latest_os TEXT DEFAULT '',
                ip_hash TEXT DEFAULT '',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_uuid TEXT NOT NULL,
                session_uuid TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL DEFAULT 0,
                started_at TEXT DEFAULT CURRENT_TIMESTAMP,
                ended_at TEXT DEFAULT '',
                last_activity_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_event_at TEXT DEFAULT '',
                landing_page TEXT DEFAULT '',
                exit_page TEXT DEFAULT '',
                landing_title TEXT DEFAULT '',
                referrer_url TEXT DEFAULT '',
                referrer_domain TEXT DEFAULT '',
                utm_source TEXT DEFAULT '',
                utm_medium TEXT DEFAULT '',
                utm_campaign TEXT DEFAULT '',
                utm_term TEXT DEFAULT '',
                utm_content TEXT DEFAULT '',
                ip_address TEXT DEFAULT '',
                ip_hash TEXT DEFAULT '',
                country_code TEXT DEFAULT '',
                country_name TEXT DEFAULT '',
                region_name TEXT DEFAULT '',
                city_name TEXT DEFAULT '',
                postal_code TEXT DEFAULT '',
                latitude REAL DEFAULT NULL,
                longitude REAL DEFAULT NULL,
                accuracy_radius_km REAL DEFAULT NULL,
                timezone_name TEXT DEFAULT '',
                asn TEXT DEFAULT '',
                isp_name TEXT DEFAULT '',
                is_proxy INTEGER NOT NULL DEFAULT 0,
                device_type TEXT DEFAULT '',
                browser TEXT DEFAULT '',
                os TEXT DEFAULT '',
                language TEXT DEFAULT '',
                timezone_offset_minutes INTEGER DEFAULT NULL,
                screen_width INTEGER DEFAULT NULL,
                screen_height INTEGER DEFAULT NULL,
                viewport_width INTEGER DEFAULT NULL,
                viewport_height INTEGER DEFAULT NULL,
                is_bot INTEGER NOT NULL DEFAULT 0,
                pageview_count INTEGER NOT NULL DEFAULT 0,
                event_count INTEGER NOT NULL DEFAULT 0,
                click_count INTEGER NOT NULL DEFAULT 0,
                form_submit_count INTEGER NOT NULL DEFAULT 0,
                scroll_event_count INTEGER NOT NULL DEFAULT 0,
                approx_time_seconds INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_pageviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_token TEXT NOT NULL UNIQUE,
                visitor_uuid TEXT NOT NULL,
                session_uuid TEXT NOT NULL,
                user_id INTEGER NOT NULL DEFAULT 0,
                page_path TEXT DEFAULT '',
                page_url TEXT DEFAULT '',
                page_title TEXT DEFAULT '',
                query_string TEXT DEFAULT '',
                referrer_url TEXT DEFAULT '',
                is_entry INTEGER NOT NULL DEFAULT 0,
                is_exit INTEGER NOT NULL DEFAULT 0,
                viewed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                duration_seconds INTEGER NOT NULL DEFAULT 0,
                scroll_max_percent INTEGER NOT NULL DEFAULT 0,
                clicks_on_page INTEGER NOT NULL DEFAULT 0,
                form_submits INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_uuid TEXT NOT NULL,
                session_uuid TEXT NOT NULL,
                page_token TEXT DEFAULT '',
                user_id INTEGER NOT NULL DEFAULT 0,
                pet_id INTEGER NOT NULL DEFAULT 0,
                event_type TEXT DEFAULT '',
                event_group TEXT DEFAULT '',
                event_name TEXT DEFAULT '',
                page_path TEXT DEFAULT '',
                page_title TEXT DEFAULT '',
                element_id TEXT DEFAULT '',
                element_text TEXT DEFAULT '',
                label TEXT DEFAULT '',
                value_numeric REAL DEFAULT NULL,
                meta_json TEXT DEFAULT '',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_geo_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_hash TEXT NOT NULL UNIQUE,
                ip_address TEXT DEFAULT '',
                provider TEXT DEFAULT '',
                country_code TEXT DEFAULT '',
                country_name TEXT DEFAULT '',
                region_name TEXT DEFAULT '',
                city_name TEXT DEFAULT '',
                postal_code TEXT DEFAULT '',
                latitude REAL DEFAULT NULL,
                longitude REAL DEFAULT NULL,
                accuracy_radius_km REAL DEFAULT NULL,
                timezone_name TEXT DEFAULT '',
                asn TEXT DEFAULT '',
                isp_name TEXT DEFAULT '',
                is_proxy INTEGER NOT NULL DEFAULT 0,
                raw_json TEXT DEFAULT '',
                looked_up_at TEXT DEFAULT CURRENT_TIMESTAMP,
                success INTEGER NOT NULL DEFAULT 0
            )
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_visitors_user ON analytics_visitors(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_sessions_started_at ON analytics_sessions(started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_sessions_source ON analytics_sessions(utm_source, referrer_domain)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_sessions_geo ON analytics_sessions(country_name, region_name, city_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_session ON analytics_pageviews(session_uuid, viewed_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_path ON analytics_pageviews(page_path)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_events_session ON analytics_events(session_uuid, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_events_type ON analytics_events(event_type, event_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_events_page ON analytics_events(page_path)');

        $done[$key] = true;
    }
}

if (!function_exists('dd_analytics_cookie_options')) {
    function dd_analytics_cookie_options(): array
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        );

        return [
            'expires' => time() + (86400 * 730),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ];
    }
}

if (!function_exists('dd_analytics_current_user_id')) {
    function dd_analytics_current_user_id(): int
    {
        $keys = ['user_id', 'member_id', 'client_id', 'id', 'admin_id'];

        foreach ($keys as $key) {
            if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
                return (int) $_SESSION[$key];
            }
        }

        return 0;
    }
}

if (!function_exists('dd_analytics_get_or_create_visitor_uuid')) {
    function dd_analytics_get_or_create_visitor_uuid(): string
    {
        $cookieKey = 'dd_visitor_id';
        $visitorUuid = trim((string) ($_COOKIE[$cookieKey] ?? ''));

        if ($visitorUuid === '') {
            $visitorUuid = dd_analytics_generate_uuid();
        }

        setcookie($cookieKey, $visitorUuid, dd_analytics_cookie_options());
        $_COOKIE[$cookieKey] = $visitorUuid;

        return $visitorUuid;
    }
}

if (!function_exists('dd_analytics_get_or_create_session_uuid')) {
    function dd_analytics_get_or_create_session_uuid(): array
    {
        $now = time();
        $timeoutSeconds = 1800;
        $existingUuid = trim((string) ($_SESSION['dd_analytics_session_uuid'] ?? ''));
        $lastSeen = (int) ($_SESSION['dd_analytics_session_last_seen'] ?? 0);
        $isNew = true;

        if ($existingUuid !== '' && $lastSeen > 0 && ($now - $lastSeen) <= $timeoutSeconds) {
            $sessionUuid = $existingUuid;
            $isNew = false;
        } else {
            $sessionUuid = dd_analytics_generate_uuid();
            $_SESSION['dd_analytics_session_started_at'] = dd_analytics_now();
            $isNew = true;
        }

        $_SESSION['dd_analytics_session_uuid'] = $sessionUuid;
        $_SESSION['dd_analytics_session_last_seen'] = $now;

        return [
            'session_uuid' => $sessionUuid,
            'is_new' => $isNew,
        ];
    }
}

if (!function_exists('dd_analytics_lookup_geo')) {
    function dd_analytics_lookup_geo(PDO $pdo, string $ipAddress): array
    {
        $blank = [
            'provider' => '',
            'country_code' => '',
            'country_name' => '',
            'region_name' => '',
            'city_name' => '',
            'postal_code' => '',
            'latitude' => null,
            'longitude' => null,
            'accuracy_radius_km' => null,
            'timezone_name' => '',
            'asn' => '',
            'isp_name' => '',
            'is_proxy' => 0,
            'raw_json' => '',
            'success' => 0,
        ];

        if (!dd_analytics_is_public_ip($ipAddress)) {
            return $blank;
        }

        $ipHash = dd_analytics_hash_ip($ipAddress);

        $cached = dd_analytics_safe_fetch_one(
            $pdo,
            'SELECT * FROM analytics_geo_cache WHERE ip_hash = :ip_hash LIMIT 1',
            [':ip_hash' => $ipHash]
        );

        if ($cached) {
            return [
                'provider' => (string) ($cached['provider'] ?? ''),
                'country_code' => (string) ($cached['country_code'] ?? ''),
                'country_name' => (string) ($cached['country_name'] ?? ''),
                'region_name' => (string) ($cached['region_name'] ?? ''),
                'city_name' => (string) ($cached['city_name'] ?? ''),
                'postal_code' => (string) ($cached['postal_code'] ?? ''),
                'latitude' => $cached['latitude'] !== null ? (float) $cached['latitude'] : null,
                'longitude' => $cached['longitude'] !== null ? (float) $cached['longitude'] : null,
                'accuracy_radius_km' => $cached['accuracy_radius_km'] !== null ? (float) $cached['accuracy_radius_km'] : null,
                'timezone_name' => (string) ($cached['timezone_name'] ?? ''),
                'asn' => (string) ($cached['asn'] ?? ''),
                'isp_name' => (string) ($cached['isp_name'] ?? ''),
                'is_proxy' => (int) ($cached['is_proxy'] ?? 0),
                'raw_json' => (string) ($cached['raw_json'] ?? ''),
                'success' => (int) ($cached['success'] ?? 0),
            ];
        }

        $responseBody = '';
        $success = 0;
        $provider = 'ipwho.is';
        $apiUrl = 'https://ipwho.is/' . rawurlencode($ipAddress);

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2.5,
                    'header' => "Accept: application/json\r\nUser-Agent: DoggieDoriansAnalytics/1.0\r\n",
                ],
            ]);

            $body = @file_get_contents($apiUrl, false, $context);
            if (is_string($body) && trim($body) !== '') {
                $responseBody = $body;
            }
        } catch (Throwable $e) {
        }

        $parsed = json_decode($responseBody, true);
        if (is_array($parsed) && !empty($parsed['success'])) {
            $success = 1;
            $blank['provider'] = $provider;
            $blank['country_code'] = trim((string) ($parsed['country_code'] ?? ''));
            $blank['country_name'] = trim((string) ($parsed['country'] ?? ''));
            $blank['region_name'] = trim((string) ($parsed['region'] ?? ''));
            $blank['city_name'] = trim((string) ($parsed['city'] ?? ''));
            $blank['postal_code'] = trim((string) ($parsed['postal'] ?? ''));
            $blank['latitude'] = isset($parsed['latitude']) && is_numeric($parsed['latitude']) ? (float) $parsed['latitude'] : null;
            $blank['longitude'] = isset($parsed['longitude']) && is_numeric($parsed['longitude']) ? (float) $parsed['longitude'] : null;
            $blank['accuracy_radius_km'] = isset($parsed['radius']) && is_numeric($parsed['radius']) ? (float) $parsed['radius'] : null;
            $blank['timezone_name'] = trim((string) dd_analytics_value_from_row((array) ($parsed['timezone'] ?? []), ['id'], ''));
            $blank['asn'] = trim((string) dd_analytics_value_from_row((array) ($parsed['connection'] ?? []), ['asn'], ''));
            $blank['isp_name'] = trim((string) dd_analytics_value_from_row((array) ($parsed['connection'] ?? []), ['isp', 'org'], ''));
            $blank['is_proxy'] = !empty($parsed['security']) && is_array($parsed['security']) && !empty($parsed['security']['proxy']) ? 1 : 0;
            $blank['raw_json'] = $responseBody;
            $blank['success'] = 1;
        } else {
            $blank['provider'] = $provider;
            $blank['raw_json'] = $responseBody;
            $blank['success'] = 0;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT OR REPLACE INTO analytics_geo_cache (
                    ip_hash, ip_address, provider, country_code, country_name, region_name, city_name, postal_code,
                    latitude, longitude, accuracy_radius_km, timezone_name, asn, isp_name, is_proxy, raw_json, looked_up_at, success
                ) VALUES (
                    :ip_hash, :ip_address, :provider, :country_code, :country_name, :region_name, :city_name, :postal_code,
                    :latitude, :longitude, :accuracy_radius_km, :timezone_name, :asn, :isp_name, :is_proxy, :raw_json, :looked_up_at, :success
                )'
            );
            $stmt->execute([
                ':ip_hash' => $ipHash,
                ':ip_address' => $ipAddress,
                ':provider' => $blank['provider'],
                ':country_code' => $blank['country_code'],
                ':country_name' => $blank['country_name'],
                ':region_name' => $blank['region_name'],
                ':city_name' => $blank['city_name'],
                ':postal_code' => $blank['postal_code'],
                ':latitude' => $blank['latitude'],
                ':longitude' => $blank['longitude'],
                ':accuracy_radius_km' => $blank['accuracy_radius_km'],
                ':timezone_name' => $blank['timezone_name'],
                ':asn' => $blank['asn'],
                ':isp_name' => $blank['isp_name'],
                ':is_proxy' => $blank['is_proxy'],
                ':raw_json' => $blank['raw_json'],
                ':looked_up_at' => dd_analytics_now(),
                ':success' => $blank['success'],
            ]);
        } catch (Throwable $e) {
        }

        return $blank;
    }
}

if (!function_exists('dd_analytics_upsert_visitor')) {
    function dd_analytics_upsert_visitor(PDO $pdo, array $context): void
    {
        $existing = dd_analytics_safe_fetch_one(
            $pdo,
            'SELECT id FROM analytics_visitors WHERE visitor_uuid = :visitor_uuid LIMIT 1',
            [':visitor_uuid' => $context['visitor_uuid']]
        );

        if ($existing) {
            $sql = '
                UPDATE analytics_visitors
                SET
                    user_id = CASE WHEN :user_id > 0 THEN :user_id ELSE user_id END,
                    last_seen_at = :now,
                    pageview_count = pageview_count + 1,
                    session_count = session_count + :session_increment,
                    visit_count = visit_count + :visit_increment,
                    latest_landing_page = CASE WHEN :is_new_session = 1 THEN :landing_page ELSE latest_landing_page END,
                    latest_referrer_url = CASE WHEN :referrer_url <> "" THEN :referrer_url ELSE latest_referrer_url END,
                    latest_referrer_domain = CASE WHEN :referrer_domain <> "" THEN :referrer_domain ELSE latest_referrer_domain END,
                    latest_utm_source = CASE WHEN :utm_source <> "" THEN :utm_source ELSE latest_utm_source END,
                    latest_utm_medium = CASE WHEN :utm_medium <> "" THEN :utm_medium ELSE latest_utm_medium END,
                    latest_utm_campaign = CASE WHEN :utm_campaign <> "" THEN :utm_campaign ELSE latest_utm_campaign END,
                    latest_country_name = CASE WHEN :country_name <> "" THEN :country_name ELSE latest_country_name END,
                    latest_region_name = CASE WHEN :region_name <> "" THEN :region_name ELSE latest_region_name END,
                    latest_city_name = CASE WHEN :city_name <> "" THEN :city_name ELSE latest_city_name END,
                    latest_device_type = CASE WHEN :device_type <> "" THEN :device_type ELSE latest_device_type END,
                    latest_browser = CASE WHEN :browser <> "" THEN :browser ELSE latest_browser END,
                    latest_os = CASE WHEN :os <> "" THEN :os ELSE latest_os END,
                    ip_hash = CASE WHEN :ip_hash <> "" THEN :ip_hash ELSE ip_hash END,
                    updated_at = :now
                WHERE visitor_uuid = :visitor_uuid
            ';
        } else {
            $sql = '
                INSERT INTO analytics_visitors (
                    visitor_uuid, user_id, first_seen_at, last_seen_at, visit_count, session_count, pageview_count,
                    first_landing_page, latest_landing_page, first_referrer_url, latest_referrer_url,
                    first_referrer_domain, latest_referrer_domain, first_utm_source, latest_utm_source,
                    first_utm_medium, latest_utm_medium, first_utm_campaign, latest_utm_campaign,
                    first_country_name, latest_country_name, first_region_name, latest_region_name,
                    first_city_name, latest_city_name, first_device_type, latest_device_type,
                    first_browser, latest_browser, first_os, latest_os, ip_hash, created_at, updated_at
                ) VALUES (
                    :visitor_uuid, :user_id, :now, :now, 1, :session_increment, 1,
                    :landing_page, :landing_page, :referrer_url, :referrer_url,
                    :referrer_domain, :referrer_domain, :utm_source, :utm_source,
                    :utm_medium, :utm_medium, :utm_campaign, :utm_campaign,
                    :country_name, :country_name, :region_name, :region_name,
                    :city_name, :city_name, :device_type, :device_type,
                    :browser, :browser, :os, :os, :ip_hash, :now, :now
                )
            ';
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':visitor_uuid' => $context['visitor_uuid'],
                ':user_id' => $context['user_id'],
                ':now' => $context['now'],
                ':session_increment' => $context['is_new_session'] ? 1 : 0,
                ':visit_increment' => $context['is_new_session'] ? 1 : 0,
                ':is_new_session' => $context['is_new_session'] ? 1 : 0,
                ':landing_page' => $context['page_path'],
                ':referrer_url' => $context['referrer_url'],
                ':referrer_domain' => $context['referrer_domain'],
                ':utm_source' => $context['utm']['source'],
                ':utm_medium' => $context['utm']['medium'],
                ':utm_campaign' => $context['utm']['campaign'],
                ':country_name' => $context['geo']['country_name'],
                ':region_name' => $context['geo']['region_name'],
                ':city_name' => $context['geo']['city_name'],
                ':device_type' => $context['ua']['device_type'],
                ':browser' => $context['ua']['browser'],
                ':os' => $context['ua']['os'],
                ':ip_hash' => $context['ip_hash'],
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('dd_analytics_upsert_session')) {
    function dd_analytics_upsert_session(PDO $pdo, array $context): void
    {
        $existing = dd_analytics_safe_fetch_one(
            $pdo,
            'SELECT id, pageview_count FROM analytics_sessions WHERE session_uuid = :session_uuid LIMIT 1',
            [':session_uuid' => $context['session_uuid']]
        );

        if ($existing) {
            $sql = '
                UPDATE analytics_sessions
                SET
                    user_id = CASE WHEN :user_id > 0 THEN :user_id ELSE user_id END,
                    last_activity_at = :now,
                    updated_at = :now,
                    pageview_count = pageview_count + 1,
                    landing_title = CASE WHEN landing_title = "" THEN :landing_title ELSE landing_title END,
                    referrer_url = CASE WHEN referrer_url = "" AND :referrer_url <> "" THEN :referrer_url ELSE referrer_url END,
                    referrer_domain = CASE WHEN referrer_domain = "" AND :referrer_domain <> "" THEN :referrer_domain ELSE referrer_domain END,
                    country_code = CASE WHEN country_code = "" AND :country_code <> "" THEN :country_code ELSE country_code END,
                    country_name = CASE WHEN country_name = "" AND :country_name <> "" THEN :country_name ELSE country_name END,
                    region_name = CASE WHEN region_name = "" AND :region_name <> "" THEN :region_name ELSE region_name END,
                    city_name = CASE WHEN city_name = "" AND :city_name <> "" THEN :city_name ELSE city_name END,
                    postal_code = CASE WHEN postal_code = "" AND :postal_code <> "" THEN :postal_code ELSE postal_code END,
                    latitude = CASE WHEN latitude IS NULL THEN :latitude ELSE latitude END,
                    longitude = CASE WHEN longitude IS NULL THEN :longitude ELSE longitude END,
                    accuracy_radius_km = CASE WHEN accuracy_radius_km IS NULL THEN :accuracy_radius_km ELSE accuracy_radius_km END,
                    timezone_name = CASE WHEN timezone_name = "" AND :timezone_name <> "" THEN :timezone_name ELSE timezone_name END,
                    asn = CASE WHEN asn = "" AND :asn <> "" THEN :asn ELSE asn END,
                    isp_name = CASE WHEN isp_name = "" AND :isp_name <> "" THEN :isp_name ELSE isp_name END,
                    device_type = CASE WHEN device_type = "" THEN :device_type ELSE device_type END,
                    browser = CASE WHEN browser = "" THEN :browser ELSE browser END,
                    os = CASE WHEN os = "" THEN :os ELSE os END
                WHERE session_uuid = :session_uuid
            ';
        } else {
            $sql = '
                INSERT INTO analytics_sessions (
                    visitor_uuid, session_uuid, user_id, started_at, last_activity_at, landing_page, landing_title,
                    referrer_url, referrer_domain, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    ip_address, ip_hash, country_code, country_name, region_name, city_name, postal_code,
                    latitude, longitude, accuracy_radius_km, timezone_name, asn, isp_name, is_proxy,
                    device_type, browser, os, is_bot, pageview_count, created_at, updated_at
                ) VALUES (
                    :visitor_uuid, :session_uuid, :user_id, :started_at, :now, :landing_page, :landing_title,
                    :referrer_url, :referrer_domain, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    :ip_address, :ip_hash, :country_code, :country_name, :region_name, :city_name, :postal_code,
                    :latitude, :longitude, :accuracy_radius_km, :timezone_name, :asn, :isp_name, :is_proxy,
                    :device_type, :browser, :os, :is_bot, 1, :now, :now
                )
            ';
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':visitor_uuid' => $context['visitor_uuid'],
                ':session_uuid' => $context['session_uuid'],
                ':user_id' => $context['user_id'],
                ':started_at' => $context['session_started_at'],
                ':now' => $context['now'],
                ':landing_page' => $context['page_path'],
                ':landing_title' => basename($context['page_path']) !== '' ? basename($context['page_path']) : 'Home',
                ':referrer_url' => $context['referrer_url'],
                ':referrer_domain' => $context['referrer_domain'],
                ':utm_source' => $context['utm']['source'],
                ':utm_medium' => $context['utm']['medium'],
                ':utm_campaign' => $context['utm']['campaign'],
                ':utm_term' => $context['utm']['term'],
                ':utm_content' => $context['utm']['content'],
                ':ip_address' => $context['ip_address'],
                ':ip_hash' => $context['ip_hash'],
                ':country_code' => $context['geo']['country_code'],
                ':country_name' => $context['geo']['country_name'],
                ':region_name' => $context['geo']['region_name'],
                ':city_name' => $context['geo']['city_name'],
                ':postal_code' => $context['geo']['postal_code'],
                ':latitude' => $context['geo']['latitude'],
                ':longitude' => $context['geo']['longitude'],
                ':accuracy_radius_km' => $context['geo']['accuracy_radius_km'],
                ':timezone_name' => $context['geo']['timezone_name'],
                ':asn' => $context['geo']['asn'],
                ':isp_name' => $context['geo']['isp_name'],
                ':is_proxy' => $context['geo']['is_proxy'],
                ':device_type' => $context['ua']['device_type'],
                ':browser' => $context['ua']['browser'],
                ':os' => $context['ua']['os'],
                ':is_bot' => $context['ua']['is_bot'],
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('dd_analytics_insert_pageview')) {
    function dd_analytics_insert_pageview(PDO $pdo, array $context): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO analytics_pageviews (
                    page_token, visitor_uuid, session_uuid, user_id, page_path, page_url, page_title, query_string,
                    referrer_url, is_entry, viewed_at, created_at, updated_at
                ) VALUES (
                    :page_token, :visitor_uuid, :session_uuid, :user_id, :page_path, :page_url, :page_title, :query_string,
                    :referrer_url, :is_entry, :viewed_at, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':page_token' => $context['page_token'],
                ':visitor_uuid' => $context['visitor_uuid'],
                ':session_uuid' => $context['session_uuid'],
                ':user_id' => $context['user_id'],
                ':page_path' => $context['page_path'],
                ':page_url' => $context['page_url'],
                ':page_title' => basename($context['page_path']) !== '' ? basename($context['page_path']) : 'Home',
                ':query_string' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY),
                ':referrer_url' => $context['referrer_url'],
                ':is_entry' => $context['is_new_session'] ? 1 : 0,
                ':viewed_at' => $context['now'],
                ':created_at' => $context['now'],
                ':updated_at' => $context['now'],
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('dd_analytics_build_context')) {
    function dd_analytics_build_context(PDO $pdo): array
    {
        $visitorUuid = dd_analytics_get_or_create_visitor_uuid();
        $sessionInfo = dd_analytics_get_or_create_session_uuid();
        $ipAddress = dd_analytics_get_client_ip();
        $ipHash = dd_analytics_hash_ip($ipAddress);
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $userAgentParsed = dd_analytics_parse_user_agent($userAgent);
        $utm = dd_analytics_parse_utm();
        $referrerUrl = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $pagePath = dd_analytics_request_uri_path();
        $now = dd_analytics_now();
        $geo = dd_analytics_lookup_geo($pdo, $ipAddress);

        return [
            'visitor_uuid' => $visitorUuid,
            'session_uuid' => (string) $sessionInfo['session_uuid'],
            'is_new_session' => !empty($sessionInfo['is_new']),
            'session_started_at' => (string) ($_SESSION['dd_analytics_session_started_at'] ?? $now),
            'page_token' => dd_analytics_generate_uuid(),
            'user_id' => dd_analytics_current_user_id(),
            'page_path' => $pagePath,
            'page_url' => dd_analytics_full_url(),
            'page_title' => basename($pagePath) !== '' ? basename($pagePath) : 'Home',
            'referrer_url' => $referrerUrl,
            'referrer_domain' => dd_analytics_referrer_domain($referrerUrl),
            'utm' => $utm,
            'ip_address' => $ipAddress,
            'ip_hash' => $ipHash,
            'ua_string' => $userAgent,
            'ua' => $userAgentParsed,
            'geo' => $geo,
            'now' => $now,
        ];
    }
}

if (!function_exists('dd_analytics_bootstrap')) {
    function dd_analytics_bootstrap(PDO $pdo): void
    {
        static $started = false;

        if ($started) {
            return;
        }
        $started = true;

        if (!dd_analytics_should_track_request()) {
            return;
        }

        dd_analytics_ensure_schema($pdo);
        $context = dd_analytics_build_context($pdo);
        $GLOBALS['dd_analytics_context'] = $context;

        dd_analytics_upsert_visitor($pdo, $context);
        dd_analytics_upsert_session($pdo, $context);
        dd_analytics_insert_pageview($pdo, $context);

        if (dd_analytics_should_inject_script()) {
            ob_start('dd_analytics_output_buffer_callback');
        }
    }
}

if (!function_exists('dd_analytics_output_buffer_callback')) {
    function dd_analytics_output_buffer_callback(string $buffer): string
    {
        if ($buffer === '') {
            return $buffer;
        }

        $script = dd_analytics_render_tracking_script();
        if ($script === '') {
            return $buffer;
        }

        if (stripos($buffer, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $script . "\n</body>", $buffer, 1) ?? ($buffer . $script);
        }

        return $buffer . $script;
    }
}

if (!function_exists('dd_analytics_collect_url')) {
    function dd_analytics_collect_url(): string
    {
        $path = dd_analytics_request_uri_path();
        $depth = substr_count(trim($path, '/'), '/');
        $relative = str_repeat('../', max(0, $depth)) . 'analytics-collect.php';

        if (str_starts_with($relative, '../')) {
            return $relative;
        }

        return 'analytics-collect.php';
    }
}

if (!function_exists('dd_analytics_render_tracking_script')) {
    function dd_analytics_render_tracking_script(): string
    {
        $context = $GLOBALS['dd_analytics_context'] ?? null;
        if (!is_array($context) || empty($context['page_token'])) {
            return '';
        }

        $config = [
            'collectUrl' => dd_analytics_collect_url(),
            'pageToken' => (string) $context['page_token'],
            'pagePath' => (string) $context['page_path'],
            'pageUrl' => (string) $context['page_url'],
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return '';
        }

        return <<<HTML
<script>
(function(){
    if (window.__ddAnalyticsLoaded) {
        return;
    }
    window.__ddAnalyticsLoaded = true;

    var cfg = {$json};
    var startedAt = Date.now();
    var maxScroll = 0;
    var clickCount = 0;
    var formSubmitCount = 0;
    var sentExit = false;
    var sentScrollMilestones = {};
    var pageTitle = document.title || '';
    var screenWidth = window.screen && window.screen.width ? window.screen.width : null;
    var screenHeight = window.screen && window.screen.height ? window.screen.height : null;
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth || null;
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight || null;

    function send(payload) {
        payload = payload || {};
        payload.page_token = cfg.pageToken;
        payload.page_path = cfg.pagePath;
        payload.page_url = cfg.pageUrl;
        payload.page_title = pageTitle;

        try {
            var formData = new FormData();
            formData.append('payload', JSON.stringify(payload));

            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.collectUrl, formData);
                return;
            }
        } catch (error) {}

        try {
            fetch(cfg.collectUrl, {
                method: 'POST',
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                keepalive: true,
                headers: {'Content-Type': 'application/json'}
            });
        } catch (error) {}
    }

    function buildBase(eventType, eventName, extra) {
        var payload = {
            event_type: eventType || 'custom',
            event_group: eventType || 'custom',
            event_name: eventName || eventType || 'custom',
            page_token: cfg.pageToken
        };

        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    payload[key] = extra[key];
                }
            }
        }

        return payload;
    }

    function elementLabel(el) {
        if (!el) {
            return '';
        }

        var label = el.getAttribute('data-analytics-label')
            || el.getAttribute('aria-label')
            || el.getAttribute('title')
            || el.getAttribute('name')
            || el.id
            || '';

        if (!label && typeof el.innerText === 'string') {
            label = el.innerText.trim();
        }

        return (label || '').slice(0, 160);
    }

    function formLabel(form) {
        if (!form) {
            return '';
        }

        return (form.getAttribute('data-analytics-label')
            || form.getAttribute('id')
            || form.getAttribute('name')
            || form.getAttribute('action')
            || 'form').slice(0, 180);
    }

    function updateScroll() {
        var doc = document.documentElement;
        var body = document.body;
        var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop || 0;
        var scrollHeight = Math.max(body.scrollHeight, doc.scrollHeight, body.offsetHeight, doc.offsetHeight, body.clientHeight, doc.clientHeight);
        var viewHeight = window.innerHeight || doc.clientHeight || body.clientHeight || 0;
        var denominator = Math.max(1, scrollHeight - viewHeight);
        var percent = Math.round((scrollTop / denominator) * 100);

        if (percent > maxScroll) {
            maxScroll = percent;
        }

        [25, 50, 75, 90].forEach(function(threshold){
            if (maxScroll >= threshold && !sentScrollMilestones[threshold]) {
                sentScrollMilestones[threshold] = true;
                send(buildBase('scroll', 'scroll_' + threshold, {
                    label: 'Scroll ' + threshold + '%',
                    value_numeric: threshold,
                    scroll_percent: threshold
                }));
            }
        });
    }

    send(buildBase('page_meta', 'page_loaded', {
        label: 'Page Loaded',
        language: navigator.language || '',
        timezone_offset_minutes: new Date().getTimezoneOffset(),
        screen_width: screenWidth,
        screen_height: screenHeight,
        viewport_width: viewportWidth,
        viewport_height: viewportHeight
    }));

    document.addEventListener('click', function(event){
        var target = event.target && event.target.closest ? event.target.closest('a,button,input[type="submit"],input[type="button"],[data-analytics-label]') : null;
        if (!target) {
            return;
        }

        clickCount += 1;
        var href = '';
        try {
            href = target.href || target.getAttribute('href') || '';
        } catch (error) {}

        send(buildBase('click', 'click', {
            label: elementLabel(target),
            element_id: target.id || '',
            element_text: elementLabel(target),
            href: href
        }));
    }, true);

    document.addEventListener('focusin', function(event){
        var form = event.target && event.target.form ? event.target.form : null;
        if (!form || form.__ddAnalyticsStarted) {
            return;
        }

        form.__ddAnalyticsStarted = true;
        send(buildBase('form_start', 'form_start', {
            label: formLabel(form)
        }));
    }, true);

    document.addEventListener('submit', function(event){
        var form = event.target;
        formSubmitCount += 1;

        send(buildBase('form_submit', 'form_submit', {
            label: formLabel(form),
            form_action: form && form.getAttribute ? (form.getAttribute('action') || '') : ''
        }));
    }, true);

    window.addEventListener('scroll', updateScroll, {passive: true});
    window.addEventListener('resize', function(){
        viewportWidth = window.innerWidth || document.documentElement.clientWidth || null;
        viewportHeight = window.innerHeight || document.documentElement.clientHeight || null;
    }, {passive: true});

    function sendExit() {
        if (sentExit) {
            return;
        }
        sentExit = true;
        updateScroll();

        var durationSeconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000));

        send(buildBase('page_exit', 'page_exit', {
            label: 'Page Exit',
            value_numeric: durationSeconds,
            duration_seconds: durationSeconds,
            scroll_max_percent: maxScroll,
            click_count: clickCount,
            form_submit_count: formSubmitCount,
            screen_width: screenWidth,
            screen_height: screenHeight,
            viewport_width: viewportWidth,
            viewport_height: viewportHeight
        }));
    }

    window.addEventListener('pagehide', sendExit);
    document.addEventListener('visibilitychange', function(){
        if (document.visibilityState === 'hidden') {
            sendExit();
        }
    });
})();
</script>
HTML;
    }
}

if (!function_exists('dd_analytics_resolve_request_identity')) {
    function dd_analytics_resolve_request_identity(): array
    {
        return [
            'visitor_uuid' => trim((string) ($_COOKIE['dd_visitor_id'] ?? '')),
            'session_uuid' => trim((string) ($_SESSION['dd_analytics_session_uuid'] ?? '')),
            'user_id' => dd_analytics_current_user_id(),
        ];
    }
}

if (!function_exists('dd_analytics_log_event')) {
    function dd_analytics_log_event(PDO $pdo, array $payload): void
    {
        $identity = dd_analytics_resolve_request_identity();
        $now = dd_analytics_now();
        $pageToken = trim((string) ($payload['page_token'] ?? ''));
        $pagePath = trim((string) ($payload['page_path'] ?? dd_analytics_request_uri_path()));
        $pageTitle = trim((string) ($payload['page_title'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? 'custom'));
        $eventGroup = trim((string) ($payload['event_group'] ?? $eventType));
        $eventName = trim((string) ($payload['event_name'] ?? $eventType));

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO analytics_events (
                    visitor_uuid, session_uuid, page_token, user_id, pet_id, event_type, event_group, event_name,
                    page_path, page_title, element_id, element_text, label, value_numeric, meta_json, created_at
                ) VALUES (
                    :visitor_uuid, :session_uuid, :page_token, :user_id, :pet_id, :event_type, :event_group, :event_name,
                    :page_path, :page_title, :element_id, :element_text, :label, :value_numeric, :meta_json, :created_at
                )'
            );
            $stmt->execute([
                ':visitor_uuid' => $identity['visitor_uuid'],
                ':session_uuid' => $identity['session_uuid'],
                ':page_token' => $pageToken,
                ':user_id' => $identity['user_id'],
                ':pet_id' => (int) ($payload['pet_id'] ?? 0),
                ':event_type' => $eventType,
                ':event_group' => $eventGroup,
                ':event_name' => $eventName,
                ':page_path' => $pagePath,
                ':page_title' => $pageTitle,
                ':element_id' => trim((string) ($payload['element_id'] ?? '')),
                ':element_text' => trim((string) ($payload['element_text'] ?? '')),
                ':label' => trim((string) ($payload['label'] ?? '')),
                ':value_numeric' => isset($payload['value_numeric']) && is_numeric($payload['value_numeric']) ? (float) $payload['value_numeric'] : null,
                ':meta_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $now,
            ]);
        } catch (Throwable $e) {
        }

        if ($identity['session_uuid'] !== '') {
            $increments = [
                'event_count' => 1,
                'click_count' => $eventType === 'click' ? 1 : 0,
                'form_submit_count' => $eventType === 'form_submit' ? 1 : 0,
                'scroll_event_count' => $eventType === 'scroll' ? 1 : 0,
            ];

            try {
                $stmt = $pdo->prepare(
                    'UPDATE analytics_sessions
                     SET
                        last_activity_at = :now,
                        last_event_at = :now,
                        updated_at = :now,
                        event_count = event_count + :event_count,
                        click_count = click_count + :click_count,
                        form_submit_count = form_submit_count + :form_submit_count,
                        scroll_event_count = scroll_event_count + :scroll_event_count,
                        exit_page = CASE WHEN :event_type = "page_exit" THEN :page_path ELSE exit_page END,
                        ended_at = CASE WHEN :event_type = "page_exit" THEN :now ELSE ended_at END,
                        approx_time_seconds = approx_time_seconds + CASE WHEN :event_type = "page_exit" AND :duration_seconds > 0 THEN :duration_seconds ELSE 0 END,
                        language = CASE WHEN :language <> "" THEN :language ELSE language END,
                        timezone_offset_minutes = CASE WHEN :timezone_offset_minutes IS NOT NULL THEN :timezone_offset_minutes ELSE timezone_offset_minutes END,
                        screen_width = CASE WHEN :screen_width IS NOT NULL THEN :screen_width ELSE screen_width END,
                        screen_height = CASE WHEN :screen_height IS NOT NULL THEN :screen_height ELSE screen_height END,
                        viewport_width = CASE WHEN :viewport_width IS NOT NULL THEN :viewport_width ELSE viewport_width END,
                        viewport_height = CASE WHEN :viewport_height IS NOT NULL THEN :viewport_height ELSE viewport_height END,
                        landing_title = CASE WHEN landing_title = "" AND :page_title <> "" THEN :page_title ELSE landing_title END
                     WHERE session_uuid = :session_uuid'
                );
                $stmt->execute([
                    ':now' => $now,
                    ':event_count' => $increments['event_count'],
                    ':click_count' => $increments['click_count'],
                    ':form_submit_count' => $increments['form_submit_count'],
                    ':scroll_event_count' => $increments['scroll_event_count'],
                    ':event_type' => $eventType,
                    ':page_path' => $pagePath,
                    ':duration_seconds' => isset($payload['duration_seconds']) && is_numeric($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : 0,
                    ':language' => trim((string) ($payload['language'] ?? '')),
                    ':timezone_offset_minutes' => isset($payload['timezone_offset_minutes']) && is_numeric($payload['timezone_offset_minutes']) ? (int) $payload['timezone_offset_minutes'] : null,
                    ':screen_width' => isset($payload['screen_width']) && is_numeric($payload['screen_width']) ? (int) $payload['screen_width'] : null,
                    ':screen_height' => isset($payload['screen_height']) && is_numeric($payload['screen_height']) ? (int) $payload['screen_height'] : null,
                    ':viewport_width' => isset($payload['viewport_width']) && is_numeric($payload['viewport_width']) ? (int) $payload['viewport_width'] : null,
                    ':viewport_height' => isset($payload['viewport_height']) && is_numeric($payload['viewport_height']) ? (int) $payload['viewport_height'] : null,
                    ':page_title' => $pageTitle,
                    ':session_uuid' => $identity['session_uuid'],
                ]);
            } catch (Throwable $e) {
            }
        }

        if ($pageToken !== '') {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE analytics_pageviews
                     SET
                        page_title = CASE WHEN :page_title <> "" THEN :page_title ELSE page_title END,
                        is_exit = CASE WHEN :event_type = "page_exit" THEN 1 ELSE is_exit END,
                        duration_seconds = CASE WHEN :event_type = "page_exit" AND :duration_seconds > duration_seconds THEN :duration_seconds ELSE duration_seconds END,
                        scroll_max_percent = CASE WHEN :scroll_max_percent > scroll_max_percent THEN :scroll_max_percent ELSE scroll_max_percent END,
                        clicks_on_page = clicks_on_page + :click_increment,
                        form_submits = form_submits + :form_increment,
                        updated_at = :now
                     WHERE page_token = :page_token'
                );
                $stmt->execute([
                    ':page_title' => $pageTitle,
                    ':event_type' => $eventType,
                    ':duration_seconds' => isset($payload['duration_seconds']) && is_numeric($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : 0,
                    ':scroll_max_percent' => isset($payload['scroll_max_percent']) && is_numeric($payload['scroll_max_percent']) ? (int) $payload['scroll_max_percent'] : 0,
                    ':click_increment' => $eventType === 'click' ? 1 : 0,
                    ':form_increment' => $eventType === 'form_submit' ? 1 : 0,
                    ':now' => $now,
                    ':page_token' => $pageToken,
                ]);
            } catch (Throwable $e) {
            }
        }
    }
}

if (!function_exists('dd_analytics_consume_payload')) {
    function dd_analytics_consume_payload(): array
    {
        $payload = [];

        if (!empty($_POST['payload']) && is_string($_POST['payload'])) {
            $decoded = json_decode((string) $_POST['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($payload === []) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        return $payload;
    }
}

if (!function_exists('dd_analytics_timeframe_start')) {
    function dd_analytics_timeframe_start(int $days): string
    {
        $days = max(1, $days);
        return date('Y-m-d H:i:s', time() - ($days * 86400));
    }
}

if (!function_exists('dd_analytics_fetch_kpis')) {
    function dd_analytics_fetch_kpis(PDO $pdo, int $days): array
    {
        $start = dd_analytics_timeframe_start($days);

        $sessions = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM analytics_sessions WHERE started_at >= :start', [':start' => $start], 0);
        $visitors = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(DISTINCT visitor_uuid) FROM analytics_sessions WHERE started_at >= :start', [':start' => $start], 0);
        $pageviews = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at >= :start', [':start' => $start], 0);
        $events = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM analytics_events WHERE created_at >= :start', [':start' => $start], 0);
        $avgPages = $sessions > 0 ? round($pageviews / $sessions, 2) : 0.0;
        $avgSessionSeconds = (float) dd_analytics_safe_fetch_value($pdo, 'SELECT AVG(approx_time_seconds) FROM analytics_sessions WHERE started_at >= :start', [':start' => $start], 0);
        $bounceLike = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM analytics_sessions WHERE started_at >= :start AND pageview_count <= 1', [':start' => $start], 0);

        return [
            'sessions' => $sessions,
            'visitors' => $visitors,
            'pageviews' => $pageviews,
            'events' => $events,
            'avg_pages_per_session' => $avgPages,
            'avg_session_seconds' => (int) round($avgSessionSeconds),
            'bounce_like_sessions' => $bounceLike,
            'bounce_like_rate' => $sessions > 0 ? round(($bounceLike / $sessions) * 100, 1) : 0.0,
        ];
    }
}

if (!function_exists('dd_analytics_fetch_daily_series')) {
    function dd_analytics_fetch_daily_series(PDO $pdo, int $days): array
    {
        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT
                substr(started_at, 1, 10) AS day_label,
                COUNT(*) AS session_count,
                COUNT(DISTINCT visitor_uuid) AS visitor_count,
                SUM(pageview_count) AS pageviews,
                SUM(event_count) AS events
             FROM analytics_sessions
             WHERE started_at >= :start
             GROUP BY substr(started_at, 1, 10)
             ORDER BY day_label ASC",
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_top_pages')) {
    function dd_analytics_fetch_top_pages(PDO $pdo, int $days, int $limit = 12): array
    {
        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT
                page_path,
                COUNT(*) AS views,
                COUNT(DISTINCT session_uuid) AS sessions,
                ROUND(AVG(duration_seconds), 1) AS avg_seconds,
                ROUND(AVG(scroll_max_percent), 1) AS avg_scroll,
                SUM(clicks_on_page) AS total_clicks,
                SUM(form_submits) AS total_form_submits
             FROM analytics_pageviews
             WHERE viewed_at >= :start
             GROUP BY page_path
             ORDER BY views DESC, sessions DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_landing_pages')) {
    function dd_analytics_fetch_landing_pages(PDO $pdo, int $days, int $limit = 8): array
    {
        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT landing_page, COUNT(*) AS sessions
             FROM analytics_sessions
             WHERE started_at >= :start
             GROUP BY landing_page
             ORDER BY sessions DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_exit_pages')) {
    function dd_analytics_fetch_exit_pages(PDO $pdo, int $days, int $limit = 8): array
    {
        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT exit_page, COUNT(*) AS exits
             FROM analytics_sessions
             WHERE started_at >= :start AND exit_page <> ''
             GROUP BY exit_page
             ORDER BY exits DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_top_sources')) {
    function dd_analytics_fetch_top_sources(PDO $pdo, int $days, int $limit = 10): array
    {
        $start = dd_analytics_timeframe_start($days);
        $rows = dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT utm_source, referrer_domain, COUNT(*) AS sessions
             FROM analytics_sessions
             WHERE started_at >= :start
             GROUP BY utm_source, referrer_domain
             ORDER BY sessions DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );

        foreach ($rows as &$row) {
            $row['source_label'] = dd_analytics_normalize_source($row);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('dd_analytics_fetch_geo_breakdown')) {
    function dd_analytics_fetch_geo_breakdown(PDO $pdo, int $days, string $dimension = 'country_name', int $limit = 10): array
    {
        $allowed = ['country_name', 'region_name', 'city_name'];
        if (!in_array($dimension, $allowed, true)) {
            $dimension = 'country_name';
        }

        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT " . dd_analytics_quoted_identifier($dimension) . " AS label, COUNT(*) AS sessions
             FROM analytics_sessions
             WHERE started_at >= :start AND " . dd_analytics_quoted_identifier($dimension) . " <> ''
             GROUP BY " . dd_analytics_quoted_identifier($dimension) . "
             ORDER BY sessions DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_device_breakdown')) {
    function dd_analytics_fetch_device_breakdown(PDO $pdo, int $days, string $column = 'device_type'): array
    {
        $allowed = ['device_type', 'browser', 'os'];
        if (!in_array($column, $allowed, true)) {
            $column = 'device_type';
        }

        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT " . dd_analytics_quoted_identifier($column) . " AS label, COUNT(*) AS sessions
             FROM analytics_sessions
             WHERE started_at >= :start AND " . dd_analytics_quoted_identifier($column) . " <> ''
             GROUP BY " . dd_analytics_quoted_identifier($column) . "
             ORDER BY sessions DESC",
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_recent_events')) {
    function dd_analytics_fetch_recent_events(PDO $pdo, int $limit = 40, int $days = 30): array
    {
        $start = dd_analytics_timeframe_start($days);

        return dd_analytics_safe_fetch_all(
            $pdo,
            "SELECT *
             FROM analytics_events
             WHERE created_at >= :start
             ORDER BY created_at DESC, id DESC
             LIMIT " . (int) $limit,
            [':start' => $start]
        );
    }
}

if (!function_exists('dd_analytics_fetch_filtered_events')) {
    function dd_analytics_fetch_filtered_events(PDO $pdo, array $filters, int $limit = 100): array
    {
        $where = ['created_at >= :start'];
        $params = [':start' => dd_analytics_timeframe_start((int) ($filters['days'] ?? 30))];

        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            $params[':event_type'] = (string) $filters['event_type'];
        }
        if (!empty($filters['page_path'])) {
            $where[] = 'page_path LIKE :page_path';
            $params[':page_path'] = '%' . (string) $filters['page_path'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(label LIKE :search OR event_name LIKE :search OR element_text LIKE :search OR meta_json LIKE :search)';
            $params[':search'] = '%' . (string) $filters['search'] . '%';
        }
        if (!empty($filters['session_uuid'])) {
            $where[] = 'session_uuid = :session_uuid';
            $params[':session_uuid'] = (string) $filters['session_uuid'];
        }

        $sql = 'SELECT * FROM analytics_events WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit;

        return dd_analytics_safe_fetch_all($pdo, $sql, $params);
    }
}

if (!function_exists('dd_analytics_fetch_filtered_sessions')) {
    function dd_analytics_fetch_filtered_sessions(PDO $pdo, array $filters, int $limit = 80): array
    {
        $where = ['started_at >= :start'];
        $params = [':start' => dd_analytics_timeframe_start((int) ($filters['days'] ?? 30))];

        if (!empty($filters['source'])) {
            $where[] = '(utm_source LIKE :source OR referrer_domain LIKE :source)';
            $params[':source'] = '%' . (string) $filters['source'] . '%';
        }
        if (!empty($filters['country'])) {
            $where[] = '(country_name LIKE :country OR region_name LIKE :country OR city_name LIKE :country)';
            $params[':country'] = '%' . (string) $filters['country'] . '%';
        }
        if (!empty($filters['device_type'])) {
            $where[] = 'device_type = :device_type';
            $params[':device_type'] = (string) $filters['device_type'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(landing_page LIKE :search OR referrer_domain LIKE :search OR city_name LIKE :search OR session_uuid LIKE :search OR visitor_uuid LIKE :search)';
            $params[':search'] = '%' . (string) $filters['search'] . '%';
        }
        if (!empty($filters['session_uuid'])) {
            $where[] = 'session_uuid = :session_uuid';
            $params[':session_uuid'] = (string) $filters['session_uuid'];
        }

        $sql = 'SELECT * FROM analytics_sessions WHERE ' . implode(' AND ', $where) . ' ORDER BY started_at DESC, id DESC LIMIT ' . (int) $limit;

        return dd_analytics_safe_fetch_all($pdo, $sql, $params);
    }
}

if (!function_exists('dd_analytics_fetch_session_pageviews')) {
    function dd_analytics_fetch_session_pageviews(PDO $pdo, string $sessionUuid): array
    {
        if ($sessionUuid === '') {
            return [];
        }

        return dd_analytics_safe_fetch_all(
            $pdo,
            'SELECT * FROM analytics_pageviews WHERE session_uuid = :session_uuid ORDER BY viewed_at ASC, id ASC',
            [':session_uuid' => $sessionUuid]
        );
    }
}

if (!function_exists('dd_analytics_fetch_business_snapshot')) {
    function dd_analytics_fetch_business_snapshot(PDO $pdo): array
    {
        $snapshot = [
            'members' => 0,
            'pets' => 0,
            'member_bookings' => 0,
            'public_bookings' => 0,
            'member_revenue' => 0.0,
            'public_revenue' => 0.0,
            'badges' => 0,
            'badged_members' => 0,
            'founder_walk' => 0,
            'founder_care' => 0,
            'founder_elite' => 0,
        ];

        if (dd_analytics_table_exists($pdo, 'users')) {
            $columns = dd_analytics_get_columns($pdo, 'users');
            $roleCol = dd_analytics_pick_column($columns, ['role', 'user_role']);
            if ($roleCol !== null) {
                $snapshot['members'] = (int) dd_analytics_safe_fetch_value(
                    $pdo,
                    'SELECT COUNT(*) FROM users WHERE COALESCE(' . dd_analytics_quoted_identifier($roleCol) . ', "member") NOT IN ("admin", "walker", "staff", "employee")',
                    [],
                    0
                );
            } else {
                $snapshot['members'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM users', [], 0);
            }
        } elseif (dd_analytics_table_exists($pdo, 'members')) {
            $snapshot['members'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM members', [], 0);
        }

        foreach (['pets', 'dogs'] as $table) {
            if (dd_analytics_table_exists($pdo, $table)) {
                $snapshot['pets'] += (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM ' . dd_analytics_quoted_identifier($table), [], 0);
            }
        }

        if (dd_analytics_table_exists($pdo, 'bookings')) {
            $snapshot['member_bookings'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM bookings', [], 0);
            $priceCol = dd_analytics_first_existing_column($pdo, 'bookings', ['price', 'total_price', 'amount', 'estimated_price']);
            if ($priceCol !== null) {
                $snapshot['member_revenue'] = (float) dd_analytics_safe_fetch_value($pdo, 'SELECT COALESCE(SUM(' . dd_analytics_quoted_identifier($priceCol) . '), 0) FROM bookings', [], 0);
            }
        } elseif (dd_analytics_table_exists($pdo, 'walks')) {
            $snapshot['member_bookings'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM walks', [], 0);
        }

        if (dd_analytics_table_exists($pdo, 'non_member_bookings')) {
            $snapshot['public_bookings'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM non_member_bookings', [], 0);
            $priceCol = dd_analytics_first_existing_column($pdo, 'non_member_bookings', ['price', 'total_price', 'amount', 'estimated_price']);
            if ($priceCol !== null) {
                $snapshot['public_revenue'] = (float) dd_analytics_safe_fetch_value($pdo, 'SELECT COALESCE(SUM(' . dd_analytics_quoted_identifier($priceCol) . '), 0) FROM non_member_bookings', [], 0);
            }
        }

        if (dd_analytics_table_exists($pdo, 'member_badges')) {
            $snapshot['badges'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(*) FROM member_badges WHERE COALESCE(is_active, 1) = 1', [], 0);
            $snapshot['badged_members'] = (int) dd_analytics_safe_fetch_value($pdo, 'SELECT COUNT(DISTINCT user_id) FROM member_badges WHERE COALESCE(is_active, 1) = 1', [], 0);

            $snapshot['founder_walk'] = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(DISTINCT user_id) FROM member_badges WHERE (badge_name = 'Founding Walker' OR badge_key LIKE '%walk%founder%' OR badge_key LIKE '%founder_walk%') AND COALESCE(is_active,1)=1", [], 0);
            $snapshot['founder_care'] = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(DISTINCT user_id) FROM member_badges WHERE (badge_name = 'Care Circle Founder' OR badge_key LIKE '%care%founder%' OR badge_key LIKE '%founder_care%') AND COALESCE(is_active,1)=1", [], 0);
            $snapshot['founder_elite'] = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(DISTINCT user_id) FROM member_badges WHERE (badge_name = 'Elite Founding Member' OR badge_key LIKE '%elite%founder%' OR badge_key LIKE '%founder_elite%') AND COALESCE(is_active,1)=1", [], 0);
        }

        return $snapshot;
    }
}

if (!function_exists('dd_analytics_fetch_service_mix')) {
    function dd_analytics_fetch_service_mix(PDO $pdo): array
    {
        $services = [];

        foreach (['bookings', 'non_member_bookings', 'walks'] as $table) {
            if (!dd_analytics_table_exists($pdo, $table)) {
                continue;
            }

            $columns = dd_analytics_get_columns($pdo, $table);
            $serviceCol = dd_analytics_pick_column($columns, ['service_type', 'service', 'booking_type', 'type', 'category']);
            if ($serviceCol === null) {
                continue;
            }

            $rows = dd_analytics_safe_fetch_all(
                $pdo,
                'SELECT ' . dd_analytics_quoted_identifier($serviceCol) . ' AS service_label, COUNT(*) AS item_count
                 FROM ' . dd_analytics_quoted_identifier($table) . '
                 GROUP BY ' . dd_analytics_quoted_identifier($serviceCol) . '
                 ORDER BY item_count DESC'
            );

            foreach ($rows as $row) {
                $label = trim(strtolower((string) ($row['service_label'] ?? 'service')));
                if ($label === '') {
                    $label = 'service';
                }

                if (!isset($services[$label])) {
                    $services[$label] = 0;
                }

                $services[$label] += (int) ($row['item_count'] ?? 0);
            }
        }

        arsort($services);
        $result = [];
        foreach ($services as $label => $count) {
            $result[] = [
                'label' => ucwords(str_replace(['_', '-'], ' ', $label)),
                'count' => $count,
            ];
        }

        return $result;
    }
}

if (!function_exists('dd_analytics_fetch_reward_tiers')) {
    function dd_analytics_fetch_reward_tiers(PDO $pdo): array
    {
        $tiers = [
            ['name' => 'Bronze Collar', 'theme_class' => 'tier-bronze', 'min' => 0, 'max' => 4],
            ['name' => 'Silver Leash', 'theme_class' => 'tier-silver', 'min' => 5, 'max' => 11],
            ['name' => 'Gold Paw', 'theme_class' => 'tier-gold', 'min' => 12, 'max' => 21],
            ['name' => 'Platinum Pack', 'theme_class' => 'tier-platinum', 'min' => 22, 'max' => 34],
            ['name' => 'Black Tag Circle', 'theme_class' => 'tier-black', 'min' => 35, 'max' => null],
        ];

        if (!dd_analytics_table_exists($pdo, 'member_badges')) {
            foreach ($tiers as &$tier) {
                $tier['member_count'] = 0;
            }
            unset($tier);
            return $tiers;
        }

        $rows = dd_analytics_safe_fetch_all(
            $pdo,
            'SELECT user_id, COUNT(*) AS badge_count
             FROM member_badges
             WHERE COALESCE(is_active,1) = 1
             GROUP BY user_id'
        );

        foreach ($tiers as &$tier) {
            $tier['member_count'] = 0;
        }
        unset($tier);

        foreach ($rows as $row) {
            $badgeCount = (int) ($row['badge_count'] ?? 0);
            foreach ($tiers as &$tier) {
                $min = (int) $tier['min'];
                $max = $tier['max'] !== null ? (int) $tier['max'] : null;
                if ($badgeCount >= $min && ($max === null || $badgeCount <= $max)) {
                    $tier['member_count']++;
                    break;
                }
            }
            unset($tier);
        }

        return $tiers;
    }
}

if (!function_exists('dd_analytics_format_duration')) {
    function dd_analytics_format_duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        if ($minutes < 60) {
            return $minutes . 'm ' . $remainder . 's';
        }

        $hours = intdiv($minutes, 60);
        $minutes = $minutes % 60;

        return $hours . 'h ' . $minutes . 'm';
    }
}

if (!function_exists('dd_analytics_guess_funnel_snapshot')) {
    function dd_analytics_guess_funnel_snapshot(PDO $pdo, int $days): array
    {
        $start = dd_analytics_timeframe_start($days);

        $membershipViews = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at >= :start AND page_path LIKE '%memberships.php%'", [':start' => $start], 0);
        $signupViews = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at >= :start AND page_path LIKE '%signup.php%'", [':start' => $start], 0);
        $bookingViews = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at >= :start AND (page_path LIKE '%book-service.php%' OR page_path LIKE '%non-member-booking.php%')", [':start' => $start], 0);
        $paymentSuccessViews = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at >= :start AND page_path LIKE '%payment-success.php%'", [':start' => $start], 0);
        $formSubmits = (int) dd_analytics_safe_fetch_value($pdo, "SELECT COUNT(*) FROM analytics_events WHERE created_at >= :start AND event_type = 'form_submit'", [':start' => $start], 0);

        return [
            'membership_views' => $membershipViews,
            'signup_views' => $signupViews,
            'booking_views' => $bookingViews,
            'payment_success_views' => $paymentSuccessViews,
            'form_submits' => $formSubmits,
        ];
    }
}
