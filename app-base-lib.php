<?php
declare(strict_types=1);

/**
 * Read the public URL path of index.html from the single editable app-base.json.
 * This is deliberately independent of DOCUMENT_ROOT, SCRIPT_NAME and __DIR__.
 */
function osm_configured_index_path(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $configFile = __DIR__ . '/app-base.json';
    if (!is_file($configFile)) {
        throw new RuntimeException('Missing app-base.json beside index.html.');
    }

    $raw = file_get_contents($configFile);
    if ($raw === false) {
        throw new RuntimeException('Cannot read app-base.json.');
    }

    try {
        $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('app-base.json is not valid JSON: ' . $e->getMessage());
    }

    $indexPath = $config['index_path'] ?? null;
    if (!is_string($indexPath) || trim($indexPath) === '') {
        throw new RuntimeException('Set index_path in app-base.json, e.g. /publicurl/1/index.html.');
    }

    $indexPath = trim($indexPath);
    if (str_contains($indexPath, '://') || str_starts_with($indexPath, '//')) {
        throw new RuntimeException('index_path must be a same-site URL path, not a full or protocol-relative URL.');
    }
    if (str_contains($indexPath, '\\') || preg_match('/[?#\x00-\x20]/', $indexPath)) {
        throw new RuntimeException('index_path must not contain backslashes, spaces, query strings or fragments.');
    }
    if ($indexPath[0] !== '/') $indexPath = '/' . $indexPath;

    $segments = explode('/', trim($indexPath, '/'));
    if (!$segments || rawurldecode((string)end($segments)) !== 'index.html') {
        throw new RuntimeException('index_path must point to index.html, e.g. /publicurl/1/index.html.');
    }

    $encodedSegments = [];
    foreach ($segments as $segment) {
        $decoded = rawurldecode($segment);
        if ($decoded === '' || $decoded === '.' || $decoded === '..' ||
            str_contains($decoded, '/') || str_contains($decoded, '\\') ||
            preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
            throw new RuntimeException('index_path contains an invalid path segment.');
        }
        $encodedSegments[] = rawurlencode($decoded);
    }

    $cached = '/' . implode('/', $encodedSegments);
    return $cached;
}

function osm_configured_app_base_path(): string {
    $indexPath = osm_configured_index_path();
    $slash = strrpos($indexPath, '/');
    return $slash === false || $slash === 0 ? '/' : substr($indexPath, 0, $slash + 1);
}

/* ---------------------------------------------------------------------------
 * _osm_totp_token support for https://query.openstreetmap.org/query-features.
 *
 * The official page sends its query-features POSTs with the browser's
 * _osm_totp_token cookie attached (the OSM website sets it for the
 * .openstreetmap.org domain on every visit), and the upstream endpoint
 * answers 403 without it. Proxied requests leave from PHP without that
 * browser cookie store, so the proxy keeps its own server-side copy: any
 * plain GET of the OSM front page hands the token out as a Set-Cookie
 * header with an explicit expiry, so it is fetched here, cached with its
 * exact expiry, and re-acquired whenever upstream still answers 403.
 * ------------------------------------------------------------------------- */

/** Normalize the optional proxy-config 'totp_cookie' block with defaults. */
function osm_totp_normalize_config($config): array {
    $config = is_array($config) ? $config : [];
    $hosts = array_values(array_filter(array_map(
        static fn($host) => strtolower(trim((string)$host)),
        (array)($config['hosts'] ?? ['query.openstreetmap.org'])
    )));
    if (!$hosts) $hosts = ['query.openstreetmap.org'];
    return [
        'hosts' => $hosts,
        'name' => (string)($config['name'] ?? '_osm_totp_token'),
        'source_url' => (string)($config['source_url'] ?? 'https://www.openstreetmap.org/')
    ];
}

function osm_totp_cache_file(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'osm-proxy-totp-cache.json';
}

/** Return the cached token, or null when missing/expired (30s safety margin). */
function osm_totp_read_cached_token(string $name): ?string {
    $file = osm_totp_cache_file();
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $token = (string)($data[$name] ?? '');
    $expiresAt = (int)($data[$name . '_expires'] ?? 0);
    if (!preg_match('/^[0-9]{4,12}$/', $token) || $expiresAt <= time() + 30) return null;
    return $token;
}

/** Atomic store; tokens for other cookie names in the same file stay intact. */
function osm_totp_store_token(string $name, string $token, int $expiresAt): void {
    $file = osm_totp_cache_file();
    $data = [];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = $decoded;
    }
    $data[$name] = $token;
    $data[$name . '_expires'] = $expiresAt;
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($payload === false) return;
    $tmp = $file . '.' . (string)getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }
}

/**
 * Extract ['token' => ..., 'expires_at' => ...] for $name from raw
 * Set-Cookie header lines. The expires attribute is best-effort: the token
 * value itself rotates on Unix-epoch hour boundaries (TOTP interval 3600),
 * which remains the hard limit applied by the caller.
 */
function osm_totp_parse_set_cookie(string $rawHeaders, string $name): ?array {
    if (!preg_match_all('/^set-cookie:\s*(.+)$/im', $rawHeaders, $matches)) return null;
    foreach ($matches[1] as $line) {
        $parts = array_map('trim', explode(';', $line));
        if (!$parts) continue;
        $pair = explode('=', $parts[0], 2);
        if (count($pair) !== 2 || trim($pair[0]) !== $name) continue;
        $token = trim($pair[1]);
        if (!preg_match('/^[0-9]{4,12}$/', $token)) continue;
        $expiresAt = 0;
        foreach (array_slice($parts, 1) as $attribute) {
            $bits = explode('=', $attribute, 2);
            if (count($bits) === 2 && strtolower(trim($bits[0])) === 'expires') {
                $parsed = strtotime(trim($bits[1]));
                if ($parsed !== false) $expiresAt = $parsed;
            }
        }
        return ['token' => $token, 'expires_at' => $expiresAt];
    }
    return null;
}

/**
 * Fetch a fresh token by GETting the OSM front page and reading its
 * Set-Cookie header. Returns null when curl is unavailable, the source page
 * is unreachable, or the response carries no usable token cookie. The cache
 * expiry is capped at the next epoch-hour boundary, when the TOTP value
 * rotates and the cached token would stop being accepted.
 */
function osm_totp_acquire_token(array $totp, bool $forceRefresh): ?string {
    $name = $totp['name'];
    if (!$forceRefresh) {
        $cached = osm_totp_read_cached_token($name);
        if ($cached !== null) return $cached;
    }
    if (!extension_loaded('curl') || !function_exists('curl_init')) return null;
    $parts = parse_url($totp['source_url']);
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        ($parts['host'] ?? '') === '') {
        return null;
    }
    $rawHeaders = '';
    $ch = curl_init($totp['source_url']);
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OSM-frontend-proxy/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$rawHeaders): int {
            $rawHeaders .= $line;
            return strlen($line);
        }
    ]);
    $result = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($result === false || ($status !== 0 && ($status < 200 || $status >= 400))) return null;
    $parsed = osm_totp_parse_set_cookie($rawHeaders, $name);
    if ($parsed === null) return null;
    $rotatesAt = (intdiv(time(), 3600) + 1) * 3600;
    $expiresAt = $parsed['expires_at'] > 0 ? min($parsed['expires_at'], $rotatesAt) : $rotatesAt;
    osm_totp_store_token($name, $parsed['token'], $expiresAt);
    return $parsed['token'];
}
