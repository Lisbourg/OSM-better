<?php
/** Legacy diagnostics/resource URL; never contacts GitHub or CDNs. */
declare(strict_types=1);
$bundle=__DIR__.'/better-osm-merged.js';
if (isset($_GET['status'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode([
   'ok'=>is_file($bundle),'bundle_cached'=>is_file($bundle),
   'bundle_bytes'=>is_file($bundle)?filesize($bundle):0,
   'require_count'=>8,'resource_count'=>3,
   'source'=>'offline-prebuilt', 'remote_downloads'=>false
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
if (isset($_GET['resource'])) {
  $key=(string)$_GET['resource'];
  if (!in_array($key,['OAUTH_HTML','DARK_THEME_FOR_ID_CSS','REGIONAL_TAGINFOS'],true)) {http_response_code(404);exit;}
  $file=__DIR__.'/vendor-resources/resource-'.$key.'.data';
  if (!is_file($file)) {http_response_code(404);exit;}
  header('Content-Type: '.(str_ends_with($key,'_CSS')?'text/css':'text/html').'; charset=utf-8');
  readfile($file);exit;
}
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'Use the prebuilt better-osm-merged.js file. No network build is required.';
