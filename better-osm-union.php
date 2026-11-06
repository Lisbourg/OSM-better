<?php
/** Legacy URL compatibility only: no download, download-once cache or JavaScript injection. */
declare(strict_types=1);
$file=__DIR__.'/better-osm-merged.js';
if (!is_file($file)) {http_response_code(503);exit('Locally prebuilt Better OSM bundle missing.');}
header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
readfile($file);
