<?php
declare(strict_types=1);
require_once __DIR__ . '/app-base-lib.php';

try {
    $basePath = osm_configured_app_base_path();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'console.error(' . json_encode('OpenStreetMap app base configuration error: ' . $e->getMessage()) . ');';
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$encodedBase = json_encode(
    $basePath,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
echo 'window.__betterOsmBasePath = ' . $encodedBase . ";\n";
echo 'if (!document.head.querySelector("base[href]")) document.write("<base href=" + JSON.stringify(window.__betterOsmBasePath) + ">");' . "\n";
