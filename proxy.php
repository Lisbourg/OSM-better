<?php
declare(strict_types=1);

/*
 * OSM reverse proxy. All upstream HTTP(S) traffic is issued by PHP/cURL.
 * Browser requests only reach this same-origin endpoint.
 */

$config = require __DIR__ . '/proxy-config.php';
require_once __DIR__ . '/app-base-lib.php';
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ini_set('zlib.output_compression', '0');

// Keep the captured OSM search forms and sidebar: only return results into the
// already existing Turbo frame, without adding or repositioning page controls.
function proxy_search_render(string $query, array $features): void {
    $basePath = osm_configured_app_base_path();
    $esc = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = [];
    foreach (array_slice($features, 0, 8) as $feature) {
        if (!is_array($feature)) continue;
        $coordinates = $feature['geometry']['coordinates'] ?? [];
        if (!is_array($coordinates) || count($coordinates) < 2) continue;
        $lon = filter_var($coordinates[0], FILTER_VALIDATE_FLOAT);
        $lat = filter_var($coordinates[1], FILTER_VALIDATE_FLOAT);
        if ($lon === false || $lat === false || !is_finite((float)$lon) || !is_finite((float)$lat) ||
            abs((float)$lat) > 90 || abs((float)$lon) > 180) continue;
        $props = (array)($feature['properties'] ?? []);
        $parts = array_filter([
            (string)($props['name'] ?? ''),
            (string)($props['housenumber'] ?? ''),
            (string)($props['street'] ?? ''),
            (string)($props['city'] ?? $props['locality'] ?? ''),
            (string)($props['state'] ?? ''),
            (string)($props['country'] ?? '')
        ], static fn($value) => $value !== '');
        $label = $parts ? implode(', ', array_unique($parts)) : sprintf('%.6F, %.6F', $lat, $lon);
        // OSM's native mlat/mlon URL makes the selected location visible as
        // a marker; the original map UI interprets #map= for viewport changes.
        $latString = sprintf('%.6F', $lat);
        $lonString = sprintf('%.6F', $lon);
        $href = $basePath . '?mlat=' . $latString . '&mlon=' . $lonString .
            '#map=15/' . $latString . '/' . $lonString;
        $items[] = '<li class="list-group-item list-group-item-action"><a class="stretched-link" href="' .
            $esc($href) . '">' . $esc($label) . '</a></li>';
    }
    if (!$items) $items[] = '<li class="list-group-item">No matching places found.</li>';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>' .
        '<turbo-frame id="sidebar_content_frame" target="_top" data-turbo="false">' .
        '<h2 class="me-4 text-break">Search Results</h2><p class="text-body-secondary">' .
        $esc($query) . '</p><ul class="list-group list-group-flush">' . implode('', $items) .
        '</ul></turbo-frame></body></html>';
}

// Official "Where is this?" behavior: /search?lat=..&lon=..&zoom=.. answers
// with a single result naming the place at those coordinates (a reverse
// geocode on the official server). Resolve it server-side in the same
// sidebar result format: Nominatim reverse first (the official data source),
// Photon reverse as fallback, and the bare coordinate label stays the last
// resort so the sidebar never turns into an upstream error page.
function proxy_describe_location_fetch(string $url): ?string {
    $ch = curl_init($url);
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'OSM-frontend-server-search/1.0'
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;
    return (string)$body;
}

function proxy_describe_location(float $lat, float $lon, ?int $zoom): bool {
    if (!extension_loaded('curl')) return false;
    $lat = round($lat, 6);
    $lon = round($lon, 6);
    $zoomValue = max(3, min(19, $zoom ?? 18));
    $acceptLanguage = trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (strlen($acceptLanguage) > 256 || preg_match('/[\x00-\x1f\x7f]/', $acceptLanguage)) {
        $acceptLanguage = '';
    }
    $features = null;

    // Primary: the same Nominatim reverse endpoint the official site uses.
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' .
        rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon) .
        '&zoom=' . $zoomValue . '&addressdetails=1';
    if ($acceptLanguage !== '') $url .= '&accept-language=' . rawurlencode($acceptLanguage);
    $body = proxy_describe_location_fetch($url);
    if ($body !== null) {
        $data = json_decode($body, true);
        if (is_array($data) && trim((string)($data['display_name'] ?? '')) !== '') {
            $features = [[
                'geometry' => ['coordinates' => [$lon, $lat]],
                'properties' => [
                    'name' => trim((string)$data['display_name'])
                ]
            ]];
        }
    }

    // Fallback: Photon reverse, the provider the place search already uses.
    if ($features === null) {
        $url = 'https://photon.komoot.io/reverse?lat=' . rawurlencode((string)$lat) .
            '&lon=' . rawurlencode((string)$lon) . '&limit=1';
        if ($acceptLanguage !== '') {
            $url .= '&lang=' . rawurlencode(substr($acceptLanguage, 0, 8));
        }
        $body = proxy_describe_location_fetch($url);
        if ($body !== null) {
            $data = json_decode($body, true);
            if (is_array($data) && is_array($data['features'] ?? null) && $data['features'] !== []) {
                $features = $data['features'];
            }
        }
    }

    if ($features === null) return false;
    proxy_search_render(sprintf('%.6F, %.6F', $lat, $lon), $features);
    return true;
}

// Handles both /search?query=... and /__proxy?url=https://.../search?query=...
// on the PHP server. Place searches use public Photon through server-side cURL;
// coordinate searches are resolved locally without an external dependency.
function proxy_search_fallback(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    $target = (string)($_SERVER['OSM_PROXY_TARGET'] ?? ($_GET['url'] ?? ''));
    if ((string)parse_url($target, PHP_URL_PATH) !== '/search') return false;
    $upstreamQuery = [];
    parse_str((string)(parse_url($target, PHP_URL_QUERY) ?: ''), $upstreamQuery);
    $query = trim((string)($upstreamQuery['query'] ?? ($_GET['query'] ?? '')));
    if ($query === '' && isset($upstreamQuery['lat'], $upstreamQuery['lon'])) {
        $latValue = filter_var($upstreamQuery['lat'], FILTER_VALIDATE_FLOAT);
        $lonValue = filter_var($upstreamQuery['lon'], FILTER_VALIDATE_FLOAT);
        if ($latValue !== false && $lonValue !== false && abs($latValue) <= 90 && abs($lonValue) <= 180) {
            // "Where is this?" lands here as /search?lat=..&lon=..&zoom=..
            // with no query text. Answer with the official reverse geocode
            // result instead of a bare coordinate label; the coordinate
            // fallback below still applies when both geocoders fail.
            if (proxy_describe_location(
                (float)$latValue,
                (float)$lonValue,
                isset($upstreamQuery['zoom']) ? (int)$upstreamQuery['zoom'] : null
            )) {
                return true;
            }
            $query = sprintf('%.6F, %.6F', $latValue, $lonValue);
        }
    }
    if ($query === '' || strlen($query) > 1024 || preg_match('//u', $query) !== 1) return false;
    if (preg_match('/^\s*([+-]?(?:\d+(?:\.\d*)?|\.\d+))\s*[,; ]\s*([+-]?(?:\d+(?:\.\d*)?|\.\d+))\s*$/', $query, $m)) {
        $lat = (float)$m[1];
        $lon = (float)$m[2];
        if (abs($lat) <= 90 && abs($lon) <= 180) {
            proxy_search_render($query, [[
                'geometry' => ['coordinates' => [$lon, $lat]],
                'properties' => ['name' => sprintf('%.6F, %.6F', $lat, $lon)]
            ]]);
            return true;
        }
    }
    if (!extension_loaded('curl')) return false;
    $url = 'https://photon.komoot.io/api/?q=' . rawurlencode($query) . '&limit=8';
    $ch = curl_init($url);
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'OSM-frontend-server-search/1.0',
        CURLOPT_REFERER => 'https://photon.komoot.io/'
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return false;
    $data = json_decode((string)$body, true);
    if (!is_array($data) || !is_array($data['features'] ?? null)) return false;
    proxy_search_render($query, $data['features']);
    return true;
}

// Turbo expects its requested frame even if the upstream map API is unavailable.
// This preserves the original frame container and exposes a real failure status.
function proxy_turbo_error_frame(string $message, int $status): bool {
    $id = (string)($_SERVER['HTTP_TURBO_FRAME'] ?? '');
    if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id)) return false;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>' .
        '<turbo-frame id="' . $id . '"><p class="text-body-secondary">' .
        'Unable to load map data: ' . $escaped . '</p></turbo-frame></body></html>';
    return true;
}

function proxy_json_error(string $message, int $status = 502): never {
    if ($status >= 500 && proxy_search_fallback()) exit;
    if ($status >= 500 && proxy_turbo_error_frame($message, $status)) exit;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// The proxy deliberately keeps no host allowlist: custom map layers define
// their own tile, style and feature URLs on any host, and gating them behind
// a server-side host list turns every unlisted layer into a 403. The only
// targets the proxy refuses are URLs it cannot issue cleanly: schemes other
// than HTTP(S) and URLs with embedded credentials. The same policy applies
// to every redirect hop.
function proxy_target_allowed($parts): bool {
    if (!is_array($parts) ||
        !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) ||
        empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))) return false;
    return true;
}

function proxy_map_value(array $map, string $host): ?string {
    $host = strtolower($host);
    foreach ($map as $pattern => $value) {
        $pattern = strtolower((string)$pattern);
        if ($pattern === $host || ($pattern !== '' && str_starts_with($pattern, '*.') &&
            str_ends_with($host, substr($pattern, 1)))) {
            return (string)$value;
        }
        if ($pattern !== '' && !str_contains($pattern, '*') &&
            ($host === $pattern || str_ends_with($host, '.' . $pattern))) {
            return (string)$value;
        }
    }
    return null;
}

function proxy_valid_header_host(string $host): bool {
    $host = trim(strtolower($host));
    // Syntax check only: no path, credentials, or control characters. There
    // is intentionally no host allowlist behind this check anymore.
    return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/', $host) === 1;
}

function proxy_origin(string $url): string {
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . $port;
}

function proxy_resolve_url(string $base, string $relative): string {
    $relative = trim($relative);
    if ($relative === '' || preg_match('/^(?:data:|blob:|javascript:|mailto:|tel:|#)/i', $relative)) return $relative;
    if (preg_match('/^https?:\/\//i', $relative)) return $relative;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $relative;
    $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (str_starts_with($relative, '//')) return $bp['scheme'] . ':' . $relative;
    if (str_starts_with($relative, '/')) return $origin . $relative;
    if (str_starts_with($relative, '?')) return $origin . ($bp['path'] ?? '/') . $relative;
    $path = $bp['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    $joined = $dir . $relative;
    $segments = [];
    foreach (explode('/', $joined) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[] = $segment;
    }
    return $origin . '/' . implode('/', $segments);
}

function proxy_app_base_path(): string {
    return osm_configured_app_base_path();
}

function proxy_endpoint_url(string $target, string $localOrigin, string $appBasePath): string {
    $url = rawurlencode($target);
    // Retain MapLibre/Leaflet template placeholders for normal tile substitution.
    $url = str_ireplace(['%7B', '%7D'], ['{', '}'], $url);
    return $localOrigin . $appBasePath . '__proxy?url=' . $url;
}

function proxy_rewrite_css(string $css, string $baseUrl, string $localOrigin, string $appBasePath): string {
    $css = preg_replace_callback(
        '/url\(\s*(["\']?)(.*?)\1\s*\)/i',
        static function(array $m) use ($baseUrl, $localOrigin, $appBasePath): string {
            $value = trim($m[2]);
            if ($value === '' || preg_match('/^(?:data:|blob:|javascript:|#)/i', $value)) return $m[0];
            $absolute = proxy_resolve_url($baseUrl, $value);
            if (!preg_match('/^https?:\/\//i', $absolute)) return $m[0];
            return 'url("' . proxy_endpoint_url($absolute, $localOrigin, $appBasePath) . '")';
        },
        $css
    ) ?? $css;
    return preg_replace_callback(
        '/@import\s+(["\'])(.*?)\1/i',
        static function(array $m) use ($baseUrl, $localOrigin, $appBasePath): string {
            $absolute = proxy_resolve_url($baseUrl, trim($m[2]));
            if (!preg_match('/^https?:\/\//i', $absolute)) return $m[0];
            return '@import url("' . proxy_endpoint_url($absolute, $localOrigin, $appBasePath) . '")';
        },
        $css
    ) ?? $css;
}

function proxy_rewrite_map_style_json(string $body, string $upstreamUrl, string $localOrigin, string $appBasePath): string {
    $style = json_decode($body, true);
    if (!is_array($style)) return $body;
    // Decode a second time with JSON object types intact. json_decode(...,
    // true) makes {} indistinguishable from [], which can invalidate
    // MapLibre style object properties after json_encode().
    $originalShape = json_decode($body);
    $encodeStyle = static function(array $updated) use ($originalShape, $body): string {
        $restore = static function($before, $after) use (&$restore) {
            if (is_object($before) && is_array($after)) {
                $object = new stdClass();
                foreach ($after as $key => $value) {
                    $name = (string)$key;
                    $object->{$name} = property_exists($before, $name)
                        ? $restore($before->{$name}, $value) : $value;
                }
                return $object;
            }
            if (is_array($before) && is_array($after)) {
                foreach ($after as $key => $value) {
                    if (array_key_exists($key, $before)) {
                        $after[$key] = $restore($before[$key], $value);
                    }
                }
            }
            return $after;
        };
        return json_encode($restore($originalShape, $updated),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: $body;
    };
    $isStyle = isset($style['version']) && is_array($style['sources'] ?? null);
    $isTileJson = is_array($style['tiles'] ?? null);
    if (!$isStyle && !$isTileJson) return $body;
    $proxied = static function($value) use ($upstreamUrl, $localOrigin, $appBasePath) {
        if (!is_string($value) || $value === '' || str_starts_with($value, 'data:')) return $value;
        $absolute = proxy_resolve_url($upstreamUrl, $value);
        return str_starts_with($absolute, 'https://') ? proxy_endpoint_url($absolute, $localOrigin, $appBasePath) : $value;
    };
    if ($isTileJson) $style['tiles'] = array_map($proxied, $style['tiles']);
    if (!$isStyle) return $encodeStyle($style);
    // MapLibre appends @2x.json/@2x.png to a sprite *base* URL, and fills
    // {fontstack}/{range} in glyph URL templates. Proxying the templates here
    // creates broken /__proxy@2x.png paths. Keep absolute upstream templates;
    // the MapLibre transformRequest hook proxies the completed requests.
    if (isset($style['sprite'])) {
        if (is_string($style['sprite'])) {
            $style['sprite'] = proxy_resolve_url($upstreamUrl, $style['sprite']);
        } elseif (is_array($style['sprite'])) {
            foreach ($style['sprite'] as &$sprite) {
                if (is_array($sprite) && isset($sprite['url']) && is_string($sprite['url'])) {
                    $sprite['url'] = proxy_resolve_url($upstreamUrl, $sprite['url']);
                }
            }
            unset($sprite);
        }
    }
    if (isset($style['glyphs']) && is_string($style['glyphs'])) {
        $style['glyphs'] = proxy_resolve_url($upstreamUrl, $style['glyphs']);
    }
    foreach ($style['sources'] as &$source) {
        if (!is_array($source)) continue;
        if (isset($source['url'])) $source['url'] = $proxied($source['url']);
        if (is_array($source['tiles'] ?? null)) $source['tiles'] = array_map($proxied, $source['tiles']);
        if (isset($source['data']) && is_string($source['data']) && preg_match('/^https?:\\/\\//', $source['data']))
            $source['data'] = $proxied($source['data']);
    }
    unset($source);
    // Some third-party styles use empty arrays as literal expression operands.
    // MapLibre interprets [] as an invalid expression and can leave its
    // placement renderer partially initialized. Treat such operands as data.
    $repairExpression = static function ($value) use (&$repairExpression) {
        if (!is_array($value) || !$value || !array_is_list($value)) return $value;
        $op = is_string($value[0] ?? null) ? $value[0] : null;
        // Literal arrays are data, not MapLibre expression operands.
        if ($op === 'literal') return $value;
        foreach ($value as $i => &$operand) {
            if ($i === 0 || !is_array($operand)) continue;
            if ($operand === [] && $op !== null) {
                // An empty array in an expression argument is never a valid
                // expression. Preserve it as a literal value instead.
                $operand = ($op === 'match' && $i >= 2 && $i % 2 === 0 &&
                    $i < count($value) - 1) ? '__osm_unused_empty_match__' : ['literal', []];
            } elseif ($operand !== []) {
                // Traverse nested expressions even under let/var/typeof/etc.
                // Restrict mutations to empty array operands only.
                $operand = $repairExpression($operand);
            }
        }
        unset($operand);
        return $value;
    };
    // Omit empty top-level style properties rather than constructing a
    // synthetic value of an unknown expected type. MapLibre supplies its
    // documented default when an optional style property is absent.
    if (isset($style['layers']) && is_array($style['layers'])) foreach ($style['layers'] as &$layer) {
        if (!is_array($layer)) continue;
        foreach (['filter','layout','paint'] as $key) {
            if (!isset($layer[$key]) || !is_array($layer[$key])) continue;
            if ($key === 'filter') {
                if ($layer[$key] === []) unset($layer[$key]);
                else $layer[$key] = $repairExpression($layer[$key]);
                continue;
            }
            foreach ($layer[$key] as $property => $val) {
                if ($val === []) {
                    // [] is not a legal style expression; removing an invalid
                    // property is safer than assigning a guessed typed value.
                    unset($layer[$key][$property]);
                } else {
                    $layer[$key][$property] = $repairExpression($val);
                }
            }
        }
    }
    unset($layer);
    return $encodeStyle($style);
}

function proxy_rewrite_html(string $html, string $upstreamUrl, string $localOrigin, string $appBasePath): string {
    $localAppBase = rtrim($localOrigin, '/') . $appBasePath;
    $localAppRoot = rtrim($localAppBase, '/');
    $localHost = strtolower((string)parse_url($localOrigin, PHP_URL_HOST));
    $upstreamHost = strtolower((string)parse_url($upstreamUrl, PHP_URL_HOST));
    if ($upstreamHost === 'www.openstreetmap.org' || $upstreamHost === 'openstreetmap.org') {
        $html = str_ireplace(
            ['https://www.openstreetmap.org', 'http://www.openstreetmap.org', 'https://openstreetmap.org', 'http://openstreetmap.org'],
            $localAppRoot,
            $html
        );
    }

    $html = preg_replace_callback(
        '/<(script|img|iframe|source|video|audio|track|embed|object|link)\b[^>]*>/is',
        static function(array $tagMatch) use ($localOrigin, $localHost, $localAppBase, $appBasePath): string {
            $tag = $tagMatch[0];
            $tagName = strtolower($tagMatch[1]);
            $attrs = $tagName === 'object' ? ['data'] : ['src'];
            if ($tagName === 'link') {
                if (!preg_match('/(?:stylesheet|icon|preload|modulepreload|manifest)/i', $tag)) return $tag;
                $attrs[] = 'href';
            }
            foreach ($attrs as $attr) {
                $pattern = '/(\b' . preg_quote($attr, '/') . '\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i';
                $tag = preg_replace_callback($pattern,
                    static function(array $m) use ($localOrigin, $localHost, $localAppBase, $appBasePath): string {
                        $target = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $host = strtolower((string)parse_url($target, PHP_URL_HOST));
                        if (!$host || $host === strtolower($localHost)) return $m[0];
                        return $m[1] . $m[2] . proxy_endpoint_url($target, $localOrigin, $appBasePath) . $m[2];
                    }, $tag) ?? $tag;
            }
            return $tag;
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '/<form\b[^>]*>/i',
        static function(array $m) use ($localOrigin, $localHost, $appBasePath): string {
            $tag = $m[0];
            return preg_replace_callback(
                '/(\baction\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i',
                static function(array $a) use ($localOrigin, $localHost, $appBasePath): string {
                    $target = html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $host = strtolower((string)parse_url($target, PHP_URL_HOST));
                    if (!$host || $host === strtolower($localHost)) return $a[0];
                    return $a[1] . $a[2] . proxy_endpoint_url($target, $localOrigin, $appBasePath) . $a[2];
                },
                $tag
            ) ?? $tag;
        },
        $html
    ) ?? $html;

    // The upstream HTML uses domain-root paths. Rebase those paths beside this
    // deployed index.html so /publicurl/1/ and any other subfolder work unchanged.
    $html = preg_replace_callback(
        '/(\b(?:href|src|action|poster|data)\s*=\s*)(["\'])(\/(?!\/)[^"\']*)\2/i',
        static function(array $m) use ($localAppRoot): string {
            $path = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $m[1] . $m[2] . $localAppRoot . '/' . ltrim($path, '/') . $m[2];
        },
        $html
    ) ?? $html;

    // Merge the Better OSM 1.7.9 code into the server-rendered OSM source.
    // The browser receives ordinary parser-ordered script tags, not a userscript
    // injector that adds <script> elements or fetches runtime GitHub sources.
    if ($upstreamHost === 'www.openstreetmap.org' || $upstreamHost === 'openstreetmap.org') {
        $html = preg_replace('/<base\b[^>]*>/i', '', $html) ?? $html;
        if (!str_contains($html, 'data-better-osm-union')) {
            $prefixScripts =
                '<script data-better-osm-union src="' .
                htmlspecialchars($localAppBase . 'app-base.js.php', ENT_QUOTES | ENT_HTML5, 'UTF-8') .
                '"></script><script src="' .
                htmlspecialchars($localAppBase . 'better-osm-runtime.js?v=union2', ENT_QUOTES | ENT_HTML5, 'UTF-8') .
                '"></script>';
            $html = preg_replace_callback('/<head\b[^>]*>/i',
                static fn(array $m): string => $m[0] . $prefixScripts, $html, 1) ?? $html;
            $mergedScript = '<script src="' .
                htmlspecialchars($localAppBase . 'better-osm-merged.js?v=layers-heading-20px-20261106', ENT_QUOTES | ENT_HTML5, 'UTF-8') .
                '"></script>';
            $installed = false;
            $html = preg_replace_callback(
                '/<script\b[^>]*\bsrc=["\']([^"\']*\/assets\/index-[a-zA-Z0-9]+\.js[^"\']*)["\'][^>]*><\/script>/i',
                static function(array $match) use ($mergedScript, &$installed): string {
                    if ($installed) return $match[0];
                    $installed = true;
                    return $mergedScript . $match[0];
                }, $html, 1) ?? $html;
            if (!$installed) {
                $html = preg_replace('/<\/head>/i', $mergedScript . '</head>', $html, 1) ?? $html;
            }
        }
    }

    return $html;
}

function proxy_redirect_url(string $base, string $location): string {
    return proxy_resolve_url($base, $location);
}

function proxy_headers_from_server(): array {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) return $headers;
    }
    $out = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $out[$name] = (string)$value;
        }
    }
    return $out;
}

// Prioritize the local search service, including routed and proxied requests.
if (proxy_search_fallback()) exit;
$targetText = $_SERVER['OSM_PROXY_TARGET'] ?? ($_GET['url'] ?? '');
if (!is_string($targetText) || $targetText === '') {
    proxy_json_error('Missing upstream URL.', 400);
}
// MapLibre font names can contain spaces after PHP decodes the query string.
// FILTER_VALIDATE_URL rejects literal spaces, so encode path/query whitespace
// without modifying already percent-encoded octets. Reject controls first.
if (preg_match('/[\x00-\x1f\x7f]/', $targetText)) {
    proxy_json_error('Invalid control character in upstream URL.', 400);
}
$targetText = preg_replace_callback('/[^\x21-\x7e]/u',
    static fn(array $m): string => rawurlencode($m[0]), $targetText) ?? $targetText;
$target = filter_var($targetText, FILTER_VALIDATE_URL) ? $targetText : '';
if ($target === '') proxy_json_error('Invalid upstream URL.', 400);

$parsed = parse_url($target);
// No host allowlist by design: custom map layers may target any HTTP(S)
// host, on any port, over http or https.
if (!$parsed || !proxy_target_allowed($parsed)) {
    proxy_json_error('Only HTTP(S) upstream URLs without embedded credentials are allowed.', 400);
}

$originalHost = strtolower(rtrim((string)$parsed['host'], '.'));
// Provider keys are substituted only in PHP; the website never contains them.
// Do not attempt to turn an expired or missing third-party key into a fake key.
$originalTarget = $target;
$apiKey = (string)($config['provider_api_keys'][$originalHost] ?? '');
if ($apiKey !== '' && in_array($originalHost, ['api.maptiler.com', 'tile.tracestrack.com'], true)) {
    $parts = parse_url($target);
    $queryParts = [];
    parse_str((string)($parts['query'] ?? ''), $queryParts);
    if (array_key_exists('key', $queryParts)) {
        $queryParts['key'] = $apiKey;
        $target = 'https://' . $originalHost . (string)($parts['path'] ?? '/') .
            '?' . http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
    }
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$validMethods = ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'];
if (!in_array($method, $validMethods, true)) proxy_json_error('Unsupported HTTP method.', 405);

// Custom map layers may declare the Referer their upstream requires. The
// browser cannot set Referer on fetch/XHR, so the frontend passes it as an
// explicit query parameter. It only becomes the outgoing Referer value for
// the requested HTTP(S) target; it is not an authentication mechanism.
$explicitReferer = '';
if (isset($_GET['referer']) && is_string($_GET['referer'])) {
    $candidate = trim($_GET['referer']);
    if ($candidate !== '') {
        if (strlen($candidate) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $candidate) ||
            !preg_match('#^https?://[a-z0-9._~:-]+#i', $candidate)) {
            proxy_json_error('Invalid referer parameter.', 400);
        }
        $explicitReferer = $candidate;
    }
}

// Same-origin POST guard. This is not an authentication system; it prevents a third-party
// website from using a visitor's local proxy session as a cross-site request relay.
$localHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
$localScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && in_array($_SERVER['HTTP_X_FORWARDED_PROTO'], ['http','https'], true)) {
    $localScheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
}
$localOrigin = $localScheme . '://' . $localHost;
$appBasePath = proxy_app_base_path();
$incomingHeaders = proxy_headers_from_server();
$incomingOrigin = $incomingHeaders['Origin'] ?? $incomingHeaders['origin'] ?? '';
if ($incomingOrigin !== '' && proxy_origin($incomingOrigin) !== proxy_origin($localOrigin)) {
    proxy_json_error('Cross-origin proxy request rejected.', 403);
}

// Referer is derived from the actual upstream request URL on each hop.
// Never accept a browser-supplied referer= override, or reuse the app origin.
// This is not an authentication mechanism; providers may still require keys.

$hostHeader = proxy_map_value($config['host_overrides'] ?? [], $originalHost) ?? $originalHost;
$sniHost = proxy_map_value($config['sni_overrides'] ?? [], $originalHost) ?? $originalHost;
$connectHost = proxy_map_value($config['connect_to_overrides'] ?? [], $originalHost) ?? $originalHost;
if (!empty($config['allow_request_header_overrides'])) {
    $hostParam = (string)($_GET['host'] ?? '');
    $sniParam = (string)($_GET['sni'] ?? '');
    $connectParam = (string)($_GET['connect'] ?? '');
    if ($hostParam !== '') $hostHeader = $hostParam;
    if ($sniParam !== '') $sniHost = $sniParam;
    if ($connectParam !== '') $connectHost = $connectParam;
}
foreach ([$hostHeader, $sniHost, $connectHost] as $overrideHost) {
    if (!proxy_valid_header_host($overrideHost)) {
        proxy_json_error('Host/SNI/connect override has an invalid format: ' . $overrideHost, 400);
    }
}

if (!extension_loaded('curl')) {
    proxy_json_error('PHP cURL extension is required. Enable extension=curl in php.ini.', 501);
}

// Create a private, per-browser server-side cookie jar. OSM login cookies never enter
// the static files or JavaScript and are never sent back to the browser as upstream cookies.
if (!empty($config['cookie_session'])) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_name('OSMProxySession');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => ($localScheme === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sessionKey = session_id();
    session_write_close();

    $cookieDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'osm-proxy-cookie-jars';
    if (!is_dir($cookieDir) && !@mkdir($cookieDir, 0700, true) && !is_dir($cookieDir)) {
        proxy_json_error('Cannot create private cookie-jar directory.', 500);
    }
    @chmod($cookieDir, 0700);
    // Expire inactive upstream login jars after 12 hours; nothing is bundled or permanent.
    foreach (glob($cookieDir . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $oldJar) {
        if (is_file($oldJar) && filemtime($oldJar) < time() - 43200) @unlink($oldJar);
    }
    $cookieJar = $cookieDir . DIRECTORY_SEPARATOR . hash('sha256', $sessionKey) . '.txt';
    if (!is_file($cookieJar)) {
        @file_put_contents($cookieJar, "# Netscape HTTP Cookie File\n", LOCK_EX);
        @chmod($cookieJar, 0600);
    }
} else {
    $cookieJar = null;
}

$body = in_array($method, ['GET','HEAD'], true) ? null : file_get_contents('php://input');
$passHeaders = [];
$passNames = [
    'accept','accept-language','cache-control','pragma','range','if-range',
    'if-none-match','if-modified-since','content-type','authorization','x-requested-with',
    'x-csrf-token','x-http-method-override','prefer','if-match'
];
foreach ($incomingHeaders as $name => $value) {
    $lower = strtolower((string)$name);
    if (in_array($lower, $passNames, true)) {
        $passHeaders[] = $name . ': ' . trim((string)$value);
    }
}
// Origin and Referer are appended per hop, once the final target host is known.
$userAgent = (string)($incomingHeaders['User-Agent'] ?? $incomingHeaders['user-agent'] ?? 'Mozilla/5.0');

$currentUrl = $target;
$currentMethod = $method;
$currentBody = $body;
$maxRedirects = max(0, min(12, (int)($config['max_redirects'] ?? 8)));
// Hosts whose upstream contract requires the short-lived _osm_totp_token
// cookie (proxy-config.php 'totp_cookie'; currently query.openstreetmap.org).
// This is a cookie contract, not an allowlist: every other HTTP(S) target
// keeps working exactly as before.
$totpConfig = osm_totp_normalize_config($config['totp_cookie'] ?? null);
$finalBody = '';
$finalHeaders = [];
$finalStatus = 502;
$finalStatusText = '';
$curlError = '';

for ($hop = 0; $hop <= $maxRedirects; $hop++) {
    $currentParts = parse_url($currentUrl);
    $currentHost = strtolower((string)($currentParts['host'] ?? ''));
    if (!proxy_target_allowed($currentParts)) {
        proxy_json_error('Unsupported upstream target: ' . $currentHost, 400);
    }

    $effectiveHost = $currentHost === $originalHost ? $hostHeader :
        (proxy_map_value($config['host_overrides'] ?? [], $currentHost) ?? $currentHost);
    $effectiveSni = $currentHost === $originalHost ? $sniHost :
        (proxy_map_value($config['sni_overrides'] ?? [], $currentHost) ?? $currentHost);
    $effectiveConnect = $currentHost === $originalHost ? $connectHost :
        (proxy_map_value($config['connect_to_overrides'] ?? [], $currentHost) ?? $currentHost);

    $upstreamScheme = strtolower((string)($currentParts['scheme'] ?? 'https'));
    $defaultPort = $upstreamScheme === 'https' ? 443 : 80;
    $port = (int)($currentParts['port'] ?? $defaultPort);
    $path = $currentParts['path'] ?? '/';
    $query = isset($currentParts['query']) ? '?' . $currentParts['query'] : '';
    $curlUrl = $upstreamScheme . '://' . $effectiveSni . ($port !== $defaultPort ? ':' . $port : '') . $path . $query;

    $requestHeaders = $passHeaders;
    $targetOrigin = proxy_origin($currentUrl);
    // Some upstreams reject tiles unless the Referer matches the site that
    // embeds them. A browser tab on www.openstreetmap.org runs under
    // Referrer-Policy: strict-origin-when-cross-origin, so every cross-origin
    // subresource/API/tile request it issues carries only the page origin
    // ("Referer: https://www.openstreetmap.org/"). This proxy issues the very
    // same requests on the page's behalf, so the origin-only openstreetmap.org
    // Referer is the default for every upstream host. Per-host exceptions stay
    // in referer_overrides (tiles.openrailwaymap.org expects
    // https://www.openrailwaymap.org/, maps.vk.com its own origin).
    // An explicit ?referer= parameter (custom map layers) wins when present.
    $refererOverride = $explicitReferer !== '' ? $explicitReferer :
        proxy_map_value($config['referer_overrides'] ?? [], $currentHost);
    $defaultReferer = rtrim((string)($config['default_referer'] ?? ''), '/');
    if ($refererOverride !== null && $refererOverride !== '') {
        $refererOrigin = rtrim($refererOverride, '/');
    } elseif ($defaultReferer !== '') {
        $refererOrigin = $defaultReferer;
    } else {
        $refererOrigin = $targetOrigin;
    }
    $requestHeaders[] = 'Referer: ' . $refererOrigin . '/';
    if (!in_array($currentMethod, ['GET', 'HEAD'], true)) {
        $requestHeaders[] = 'Origin: ' . $refererOrigin;
    }
    $hostValue = $effectiveHost . ($port !== $defaultPort ? ':' . $port : '');
    $requestHeaders[] = 'Host: ' . $hostValue;
    $requestHeaders[] = 'User-Agent: ' . $userAgent;
    $requestHeaders[] = 'Expect:';

    // Hosts listed in totp_cookie.answer only when the request carries the
    // same short-lived _osm_totp_token cookie the official page's browser
    // session has. The proxy acquires and caches it server-side (see
    // app-base-lib.php); a 403 on such a hop retries once with a fresh token.
    $totpToken = null;
    $totpNeeded = in_array($currentHost, $totpConfig['hosts'], true);
    if ($totpNeeded) {
        $totpToken = osm_totp_acquire_token($totpConfig, false);
        if ($totpToken !== null) {
            $requestHeaders[] = 'Cookie: ' . $totpConfig['name'] . '=' . $totpToken;
        }
    }

    $responseHeaders = [];
    $responseStatusText = '';
    $ch = curl_init($curlUrl);
    if (!$ch) proxy_json_error('Could not initialize cURL.', 500);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CUSTOMREQUEST => $currentMethod,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CONNECTTIMEOUT => max(1, (int)($config['connect_timeout'] ?? 15)),
        CURLOPT_TIMEOUT => max(1, (int)($config['request_timeout'] ?? 90)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$responseHeaders, &$responseStatusText): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '') return $length;
            if (preg_match('/^HTTP\/\S+\s+\d+\s*(.*)$/i', $trimmed, $statusMatch)) {
                $responseHeaders = [];
                $responseStatusText = trim($statusMatch[1] ?? '');
                return $length;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));
                $responseHeaders[$name] = $value;
            }
            return $length;
        }
    ];
    if ($cookieJar !== null) {
        $options[CURLOPT_COOKIEFILE] = $cookieJar;
        $options[CURLOPT_COOKIEJAR] = $cookieJar;
    }
    if ($currentMethod === 'HEAD') {
        $options[CURLOPT_NOBODY] = true;
    } elseif ($currentBody !== null && !in_array($currentMethod, ['GET','HEAD'], true)) {
        $options[CURLOPT_POSTFIELDS] = $currentBody;
    }

    if ($effectiveSni !== $effectiveConnect) {
        if (!defined('CURLOPT_CONNECT_TO')) {
            curl_close($ch);
            proxy_json_error('This PHP/cURL build does not support CURLOPT_CONNECT_TO (needed for separate SNI and connect host).', 501);
        }
        $options[CURLOPT_CONNECT_TO] = [$effectiveSni . ':' . $port . ':' . $effectiveConnect . ':' . $port];
    }

    $status = 0;
    $statusText = '';
    $result = false;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        if ($attempt > 0) {
            $ch = curl_init($curlUrl);
            if (!$ch) proxy_json_error('Could not initialize cURL.', 500);
        }
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        $responseHeaders = [];
        $responseStatusText = '';
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        if ($result === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            proxy_json_error('Upstream cURL error: ' . $curlError, 502);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $statusText = $responseStatusText;
        curl_close($ch);
        $ch = null;

        if ($status !== 403 || !$totpNeeded || $attempt > 0) break;
        // The cached token may have rotated between requests (its TOTP value
        // refreshes hourly): force exactly one re-acquire and retry this hop
        // with the fresh cookie before surfacing the upstream 403.
        $freshToken = osm_totp_acquire_token($totpConfig, true);
        if ($freshToken === null || $freshToken === $totpToken) break;
        $totpToken = $freshToken;
        $requestHeaders = array_values(array_filter($requestHeaders,
            static fn($header) => !str_starts_with(strtolower($header), 'cookie:')));
        $requestHeaders[] = 'Cookie: ' . $totpConfig['name'] . '=' . $totpToken;
    }

    $location = $responseHeaders['location'] ?? '';
    if (in_array($status, [301,302,303,307,308], true) && $location !== '') {
        if ($hop >= $maxRedirects) proxy_json_error('Upstream redirect limit exceeded.', 508);
        $nextUrl = proxy_redirect_url($currentUrl, $location);
        $nextParts = parse_url($nextUrl);
        $nextHost = strtolower((string)($nextParts['host'] ?? ''));
        if (!proxy_target_allowed($nextParts)) {
            proxy_json_error('Upstream redirected to an unsupported target: ' . $nextHost, 400);
        }
        if ($nextHost !== $currentHost) {
            // Match browser redirect credential handling: never leak Authorization across hosts.
            $passHeaders = array_values(array_filter($passHeaders,
                static fn($h) => !str_starts_with(strtolower($h), 'authorization:')));
        }
        if ($status === 303 || (in_array($status, [301,302], true) && $currentMethod === 'POST')) {
            $currentMethod = 'GET';
            $currentBody = null;
            $passHeaders = array_values(array_filter($passHeaders,
                static fn($h) => !str_starts_with(strtolower($h), 'content-type:')));
        }
        $currentUrl = $nextUrl;
        continue;
    }

    $finalBody = (string)$result;
    $finalHeaders = $responseHeaders;
    $finalStatus = $status > 0 ? $status : 502;
    $finalStatusText = $statusText;
    break;
}

if ($finalStatus >= 500 && proxy_search_fallback()) exit;
if ($finalStatus >= 500 && proxy_turbo_error_frame('Upstream HTTP ' . $finalStatus, $finalStatus)) exit;
$contentType = strtolower((string)($finalHeaders['content-type'] ?? ''));
$finalUrl = $currentUrl;
if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml')) {
    $finalBody = proxy_rewrite_html($finalBody, $finalUrl, $localOrigin, $appBasePath);
} elseif (str_contains($contentType, 'text/css')) {
    $finalBody = proxy_rewrite_css($finalBody, $finalUrl, $localOrigin, $appBasePath);
} elseif (str_contains($contentType, 'json') &&
    (str_contains($finalUrl, 'style.json') || str_contains($finalUrl, '/styles/') ||
     str_contains($finalUrl, '.json') || str_contains($finalUrl, '/tiles/'))) {
    $finalBody = proxy_rewrite_map_style_json($finalBody, $finalUrl, $localOrigin, $appBasePath);
}

http_response_code($finalStatus);
$forwardResponseHeaders = [
    'content-type','cache-control','expires','etag','last-modified','vary',
    'content-range','accept-ranges','content-disposition','content-language',
    'content-location','www-authenticate','authentication-info'
];
foreach ($forwardResponseHeaders as $name) {
    if (isset($finalHeaders[$name])) {
        header($name . ': ' . $finalHeaders[$name], false);
    }
}
if (!isset($finalHeaders['cache-control'])) {
    header('Cache-Control: ' . (in_array($method, ['GET','HEAD'], true) ? 'public, max-age=60' : 'no-store'));
}
header('X-Proxy-Final-Url: ' . $originalTarget);
header('X-Content-Type-Options: nosniff');
// The proxied responses replace the official page's own subresource replies;
// carry the same Referrer-Policy the official site runs under, so the browser
// keeps sending the origin-only Referer this proxy's upstream contract uses.
header('Referrer-Policy: strict-origin-when-cross-origin');
echo $finalBody;
