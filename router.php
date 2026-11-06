<?php
declare(strict_types=1);

// PHP front controller. All URL routing is anchored to the index_path in app-base.json.
require_once __DIR__ . '/app-base-lib.php';

/** Official OSM behavior: ?locale=xx (and the selected-language cookie) choose
 * the data-locale attribute and the matching assets/i18n/xx-<hash>.js bundle.
 * Only locales whose bundle is actually present in assets/i18n/ are accepted;
 * anything else falls back to English. Dropping more bundles into assets/i18n/
 * activates them here with no further code changes.
 */
function osm_available_locale(string $locale): ?string {
    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale)) return null;
    $pattern = __DIR__ . '/assets/i18n/' . $locale . '-*.js';
    $matches = glob($pattern);
    if (empty($matches)) return null;
    return $locale;
}

function osm_selected_locale(): string {
    $param = (string)($_GET['locale'] ?? '');
    // The official application bundle stores the choice in the "_osm_locale"
    // cookie (OSM.cookies.set("_osm_locale", code) + location.reload());
    // "osm_locale" is kept as a legacy alias for older bookmarks/cookies.
    $cookie = (string)($_COOKIE['_osm_locale'] ?? $_COOKIE['osm_locale'] ?? '');
    $candidates = [];
    if ($param !== '') $candidates[] = $param;
    if ($cookie !== '') $candidates[] = $cookie;
    $candidates[] = 'en';
    foreach ($candidates as $candidate) {
        $locale = osm_available_locale($candidate);
        if ($locale !== null) {
            if ($param !== '' && $param === $locale) {
                setcookie('_osm_locale', $locale, ['expires' => 0, 'path' => '/', 'samesite' => 'Lax']);
            }
            return $locale;
        }
    }
    return 'en';
}

/** Serve the unchanged local map page with a non-visual, configured base URL.
 * Deep-link requests cannot load ./app-base.js.php until the browser knows
 * the application directory: the base element must precede every script.
 */
function osm_serve_frontend(): bool {
    $html = file_get_contents(__DIR__ . '/index.html');
    if ($html === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Local map frontend is unavailable.';
        return true;
    }
    $base = htmlspecialchars(osm_configured_app_base_path(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $updated = preg_replace('/(<head\b[^>]*>)/i', '$1<base href="' . $base . '">', $html, 1, $count);
    if ($updated === null || $count !== 1) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Local map frontend has no valid head element.';
        return true;
    }
    // Official locale selection: rewrite <html lang> + <head data-locale> and
    // the i18n bundle script to the chosen locale's own assets/i18n file.
    $locale = osm_selected_locale();
    $updated = preg_replace('/(<html\b[^>]*\slang=")en(")/i',
        '$1' . $locale . '$2', $updated, 1, $langCount);
    $updated = preg_replace('/(<head\b[^>]*data-locale=")en(")/i',
        '$1' . $locale . '$2', $updated, 1, $localeCount);
    if ($langCount === 1 && $localeCount === 1) {
        $bundle = glob(__DIR__ . '/assets/i18n/' . $locale . '-*.js');
        if (!empty($bundle)) {
            $bundleName = basename($bundle[0]);
            $updated = preg_replace('~assets/i18n/[a-zA-Z-]+-[0-9a-f]{64}\.js~',
                'assets/i18n/' . $bundleName, $updated, 1, $i18nCount);
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') echo $updated;
    return true;
}

/** Serve a local Turbo-Frame pane. Root-absolute references inside official
 * pane markup (e.g. the language list's "/?locale=xx" links or the note pane's
 * "/login" link) are re-anchored to the configured app base path, so the
 * deployment works from any subdirectory without hardcoding one.
 */
function osm_serve_pane(string $relativeFile): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $pane = file_get_contents(__DIR__ . '/' . $relativeFile);
    if ($pane === false) {
        http_response_code(500);
        echo 'Local pane is unavailable.';
        return;
    }
    $basePath = osm_configured_app_base_path();
    $base = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo preg_replace(
        '~(\b(?:href|action|src)=")/(?!/)([^"]*)~',
        '$1' . $base . '$2',
        $pane
    );
}

/** Only official client-side routes boot the original HTML here. */
function osm_is_client_route(string $path): bool {
    if (in_array($path, ['/', '/index.html', '/search', '/directions', '/export', '/query', '/account/home', '/note/new', '/history', '/history/friends', '/history/nearby'], true)) return true;
    return (bool) preg_match('~^/(?:note/[0-9]+|changeset/[0-9]+|(?:node|way|relation)/[0-9]+(?:/history(?:/[0-9]+)?)?|user/[^/]+/history)/?$~D', $path);
}

$basePath = osm_configured_app_base_path();
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$queryString = parse_url($requestUri, PHP_URL_QUERY) ?: '';
// Apache's internal rewrite may append this private routing parameter to the
// query string; it is never part of the upstream OSM request.
$queryString = preg_replace('/(^|&)__osm_route=[^&]*/', '', $queryString) ?? $queryString;
$queryString = trim($queryString, '&');

// Apache .htaccess passes the path relative to the directory containing index.html.
// This avoids forwarding /publicurl/1/ as part of the upstream OSM path.
$routeOverride = $_GET['__osm_route'] ?? null;
if (is_string($routeOverride) && $routeOverride !== '') {
    $path = '/' . ltrim($routeOverride, '/');
} else {
    $path = $requestPath;
    $baseWithoutSlash = rtrim($basePath, '/');
    if ($basePath !== '/' && $path === $baseWithoutSlash) {
        $path = '/';
    } elseif ($basePath !== '/' && str_starts_with($path, $basePath)) {
        $path = '/' . substr($path, strlen($basePath));
    }
    if ($path === '') $path = '/';
}

if (str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) {
    http_response_code(400);
    exit;
}
if (in_array($path, ['/router.php', '/proxy-config.php', '/app-base.json', '/app-base-lib.php'], true)) {
    http_response_code(404);
    exit;
}

// The native language dialog requests a Turbo Frame through different paths
// in different versions of the official OSM bundle. Always serve the correct
// local frame whenever that exact frame ID is requested; never proxy it to a
// page which might return an HTML 502 without the requested frame.
if (($_SERVER['HTTP_TURBO_FRAME'] ?? '') === 'select_language_list') {
    osm_serve_pane('panes/languages/index.html');
    return true;
}

// The official language dialog requests the language Turbo Frame on modal open
// (application.js sets the frame src to /panes/languages?source=<route>).
if ($path === '/panes/languages' || $path === '/panes/languages/') {
    osm_serve_pane('panes/languages/index.html');
    return true;
}

// Official SPA controllers load sidebar HTML through Turbo Frames.
// The archive did not contain the Rails-rendered Directions/Query fragments,
// so provide local frames with the same IDs and native result controls.
if (($_SERVER['HTTP_TURBO_FRAME'] ?? '') === 'sidebar_content_frame' &&
    in_array($path, ['/directions', '/directions/', '/query', '/query/'], true)) {
    osm_serve_pane($path === '/query' || $path === '/query/'
        ? 'panes/query/index.html' : 'panes/directions/index.html');
    return true;
}

// Any direct navigation or refresh of a recognized SPA route must load the
// original local page. Its non-visual base element is configured above; no
// fixed deployment prefix is embedded in the HTML, JavaScript, or this route.
if (osm_is_client_route($path) &&
    empty($_SERVER['HTTP_TURBO_FRAME']) &&
    in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    return osm_serve_frontend();
}

// Local Turbo fragments avoid upstream 502 pages appearing in modal/sidebars.
// Full-page note navigation still boots the canonical map layout.
if (in_array($path, ['/note/new', '/note/new/'], true)) {
    if (!empty($_SERVER['HTTP_TURBO_FRAME'])) {
        // The official note pane markup is served verbatim; osm_serve_pane()
        // re-anchors its root-absolute links to the configured app base.
        osm_serve_pane('panes/note-new.html');
    } else {
        return osm_serve_frontend();
    }
    return true;
}
// Local native sidebar fragments. Do not send these requests to the remote
// OSM website or through the generic upstream proxy.
foreach (['/panes/layers' => 'panes/layers/index.html',
          '/panes/share' => 'panes/share/index.html'] as $localPath => $localFile) {
    if ($path === $localPath || $path === $localPath . '/') {
        osm_serve_pane($localFile);
        return true;
    }
}
// The official legend pane is missing from the archive. Serve the operator's
// local copy (panes/legend/index.html, fetchable with the bundled download
// script) with the same pane treatment; without the file, fall through to the
// generic upstream proxy so the legend still works online.
if (($path === '/panes/legend' || $path === '/panes/legend/') &&
    is_file(__DIR__ . '/panes/legend/index.html')) {
    osm_serve_pane('panes/legend/index.html');
    return true;
}
if ($path === '/__health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'ok' => true,
        'curl_enabled' => extension_loaded('curl'),
        'base_path' => $basePath,
        'index_path' => osm_configured_index_path(),
        'curl_version' => extension_loaded('curl') ? (curl_version()['version'] ?? null) : null
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/__proxy' || $path === '/__proxy/') {
    require __DIR__ . '/proxy.php';
    return true;
}

$root = realpath(__DIR__);
$relativePath = rawurldecode(ltrim($path, '/'));
$candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relativePath);
if ($candidate !== false && $root !== false &&
    str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) &&
    is_file($candidate)) {
    return false;
}
if ($candidate !== false && $root !== false &&
    str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) &&
    is_dir($candidate) && is_file($candidate . DIRECTORY_SEPARATOR . 'index.html')) {
    return false;
}
if ($path === '/' && is_file(__DIR__ . '/index.html')) {
    return false;
}

// Preserve the browser's original query string (not the internal rewrite query).
$_SERVER['OSM_PROXY_TARGET'] = 'https://www.openstreetmap.org' . $path .
    ($queryString !== '' ? '?' . $queryString : '');
require __DIR__ . '/proxy.php';
return true;
