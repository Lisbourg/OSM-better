#!/usr/bin/env python3
"""Guard test for the proxy target policy after the allowlist removal.

The proxy deliberately has NO host allowlist: custom map layers may target any
HTTP(S) host on any port. This test pins that policy by
  1. statically asserting proxy.php / proxy-config.php no longer contain any
     allowlist gate ("allow_hosts", "not allowlisted", host-matching loops);
  2. statically asserting the remaining checks (proxy_target_allowed calls,
     same-origin guard, Referer contract, Referrer-Policy header) are intact;
  3. mirroring proxy_target_allowed() logic 1:1 and exercising it on the
     policy cases (any host, http and https, custom ports, rejected schemes,
     rejected embedded credentials).
"""
from pathlib import Path
import json, re, sys

root = Path(__file__).parent
proxy_php = (root / 'proxy.php').read_text(encoding='utf-8')
config_php = (root / 'proxy-config.php').read_text(encoding='utf-8')

failures = []

# --- 1. The allowlist feature must be gone everywhere. -----------------------
if 'allow_hosts' in proxy_php:
    failures.append('proxy.php still references allow_hosts')
if 'allow_hosts' in config_php:
    failures.append('proxy-config.php still defines allow_hosts')
for marker in ('not allowlisted', "isn't allowlisted", 'proxy_host_allowed',
               'proxy_https_target_allowed'):
    if marker in proxy_php:
        failures.append(f'proxy.php still contains allowlist artifact: {marker}')
if re.search(r"function\s+proxy_host_allowed", proxy_php):
    failures.append('proxy_host_allowed() still defined')

# --- 2. Required pieces of the new policy must be present. -------------------
required_snippets = {
    'target validator defined': 'function proxy_target_allowed($parts): bool',
    'initial URL check uses validator': 'if (!$parsed || !proxy_target_allowed($parsed))',
    'per-hop check uses validator': 'if (!proxy_target_allowed($currentParts))',
    'redirect check uses validator': 'if (!proxy_target_allowed($nextParts))',
    'default OSM Referer contract': "'default_referer' => 'https://www.openstreetmap.org/'",
    'per-hop Referer header injection': "'Referer: ' . $refererOrigin . '/'",
    'Referrer-Policy response header': "header('Referrer-Policy: strict-origin-when-cross-origin')",
    'custom-layer referer parameter': "isset($_GET['referer'])",
    'same-origin proxy guard': "proxy_json_error('Cross-origin proxy request rejected.', 403)",
    'http+https scheme acceptance': "['http', 'https'], true",
    'curl URL built from real scheme': "$curlUrl = $upstreamScheme . '://' . $effectiveSni",
}
for label, snippet in required_snippets.items():
    if snippet not in proxy_php and snippet not in config_php:
        failures.append(f'missing required piece - {label}: {snippet!r}')

# --- 3. 1:1 Python mirror of proxy_target_allowed(). -------------------------
def proxy_target_allowed(parts):
    if not isinstance(parts, dict):
        return False
    if str(parts.get('scheme', '')).lower() not in ('http', 'https'):
        return False
    if not parts.get('host') or 'user' in parts or 'pass' in parts:
        return False
    if 'port' in parts and not (1 <= int(parts['port']) <= 65535):
        return False
    return True

cases = {
    # any host is accepted: core OSM, custom layers, LAN/localhost names too
    'osm_www':            ({'scheme': 'https', 'host': 'www.openstreetmap.org', 'path': '/'}, True),
    'osm_query':          ({'scheme': 'https', 'host': 'query.openstreetmap.org', 'path': '/api'}, True),
    'facilmap_tiles':     ({'scheme': 'https', 'host': 'tiles.facilmap.org', 'path': '/{z}/{x}/{y}.png'}, True),
    'pt_facilmap':        ({'scheme': 'https', 'host': 'pt.facilmap.org', 'path': '/'}, True),
    'custom_layer':       ({'scheme': 'https', 'host': 'tiles.my-own-layer.example', 'path': '/t/{z}/{x}/{y}.png'}, True),
    'unlisted_new_host':  ({'scheme': 'https', 'host': 'tile.brand-new-provider.io', 'path': '/'}, True),
    'plain_http':         ({'scheme': 'http', 'host': 'tile.example.org', 'path': '/'}, True),
    'custom_port':        ({'scheme': 'https', 'host': 'tile.lan:8443', 'path': '/'}, True),
    'http_custom_port':   ({'scheme': 'http', 'host': '192.168.1.10', 'port': 8080, 'path': '/tiles'}, True),
    'reject_ftp':         ({'scheme': 'ftp', 'host': 'example.org', 'path': '/'}, False),
    'reject_javascript':  ({'scheme': 'javascript', 'host': 'example.org'}, False),
    'reject_data':        ({'scheme': 'data'}, False),
    'reject_no_host':     ({'scheme': 'https', 'path': '/'}, False),
    'reject_credentials': ({'scheme': 'https', 'host': 'example.org', 'user': 'u', 'pass': 'p'}, False),
    'reject_bad_port':    ({'scheme': 'https', 'host': 'example.org', 'port': 70000}, False),
    'reject_not_array':   (None, False),
}
mirror_results = {name: proxy_target_allowed(parts) == want for name, (parts, want) in cases.items()}
failures += [f'mirror case failed: {n}' for n, ok in mirror_results.items() if not ok]

# The PHP source must express the same conditions the mirror encodes.
php_fn = proxy_php[proxy_php.index('function proxy_target_allowed'):]
php_fn = php_fn[:php_fn.index('}', php_fn.index('return true;')) + 1]
for token in ("'http', 'https'", "empty($parts['host'])", "isset($parts['user'])",
              "isset($parts['pass'])", r"$parts['port']"):
    if token not in php_fn:
        failures.append(f'PHP validator lost condition token: {token}')

# --- 4. When a real PHP binary is available, execute the actual function. ---
import os, shutil
php_bin = os.environ.get('PHP_BINARY') or shutil.which('php')
if php_bin:
    php_fn_src = proxy_php[proxy_php.index('// The proxy deliberately keeps no host allowlist'):]
    php_fn_src = php_fn_src[:php_fn_src.index('function proxy_map_value')]
    php_script = '<?php\n' + php_fn_src + '''
$cases = array(
  'osm_www' => array(array('scheme'=>'https','host'=>'www.openstreetmap.org','path'=>'/'), true),
  'custom_layer' => array(array('scheme'=>'https','host'=>'tiles.my-own-layer.example','path'=>'/t.png'), true),
  'unlisted_new_host' => array(array('scheme'=>'https','host'=>'tile.brand-new-provider.io'), true),
  'plain_http' => array(array('scheme'=>'http','host'=>'tile.example.org'), true),
  'custom_port' => array(array('scheme'=>'https','host'=>'tile.lan','port'=>8443), true),
  'reject_ftp' => array(array('scheme'=>'ftp','host'=>'example.org'), false),
  'reject_no_host' => array(array('scheme'=>'https'), false),
  'reject_credentials' => array(array('scheme'=>'https','host'=>'example.org','user'=>'u','pass'=>'p'), false),
  'reject_bad_port' => array(array('scheme'=>'https','host'=>'example.org','port'=>70000), false)
);
$bad = array();
foreach ($cases as $name => $case) {
  if (proxy_target_allowed($case[0]) !== $case[1]) $bad[] = $name;
}
echo json_encode(array('php_ok' => !$bad, 'failed' => $bad));
if ($bad) exit(1);
'''
    import subprocess, tempfile
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as tf:
        tf.write(php_script)
        tmp = tf.name
    try:
        run = subprocess.run([php_bin, tmp], capture_output=True, text=True, timeout=30)
        if run.returncode != 0 or '"php_ok":true' not in run.stdout.replace(' ', ''):
            failures.append(f'real PHP validator mismatch: {run.stdout!r} {run.stderr!r}')
    finally:
        os.unlink(tmp)

if failures:
    print('FAILURES:')
    for f in failures:
        print(' -', f)
    sys.exit(1)
print(json.dumps({'allowlist_removed': True, 'mirror_cases': len(cases),
                  'policy': 'any HTTP(S) host, any port, no credentials'}, indent=None))
print('OK: proxy has no host allowlist; custom layers cannot 403 on a missing entry')
print('OK: validator accepts any HTTP(S) host/port and rejects non-HTTP(S) schemes and credentials')
print('OK: per-hop Referer (default https://www.openstreetmap.org/) and Referrer-Policy header intact')
