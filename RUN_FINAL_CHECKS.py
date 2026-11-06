#!/usr/bin/env python3
"""Run checks against the files actually packaged for deployment."""
from pathlib import Path
from bs4 import BeautifulSoup
from zipfile import ZipFile
import hashlib,json,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parent
checks={};details={};failures=[]

def run(label,cmd,limit=120):
    try:
        result=subprocess.run(cmd,cwd=root,capture_output=True,text=True,timeout=limit)
        checks[label]=result.returncode==0
        details[label]={'exit_code':result.returncode,'stdout_tail':result.stdout[-700:],'stderr_tail':result.stderr[-500:]}
        if result.returncode:failures.append(label+': '+(result.stderr or result.stdout)[-150:])
    except Exception as exc:
        checks[label]=False;details[label]={'exception':str(exc)};failures.append(label+': '+str(exc))

commands=[
 ('all_js_php_route_refs',['python','VERIFY_LOCAL.py'],25),
 ('three_configured_deployment_paths',['python','TEST_CONFIGURED_BASE.py'],90),
 ('native_route_race_drag_and_two_close_buttons',['python','TEST_NATIVE_DIRECTIONS_RACE.py'],90),
 ('native_query_and_maplibre_language',['node','TEST_NATIVE_MODULE_REGRESSION.js'],20),
 ('real_xhr_abort_bridge',['node','TEST_XHR_ABORT.js'],20),
 ('xhr_open_reuses_and_cancels',['node','TEST_XHR_REOPEN.js'],20),
 ('legacy_gm_callback_errors',['node','TEST_GM_CALLBACK_ERRORS.js'],20),
 ('runtime_language_wrapper',['node','DEEP_MAPLIBRE_GUARD_TEST.js'],20),
 ('mutually_exclusive_better_osm_layers',['node','TEST_NATIVE_LAYERS.js'],20),
 ('php_map_style_rewrite',['python','DEEP_PHP_STYLE_TEST.py'],20),
 ('php_style_preserves_json_objects',['python','TEST_STYLE_OBJECT_SHAPES.py'],20),
 ('proxy_redirect_rejects_unsafe_targets',['python','TEST_PROXY_TARGET_GUARD.py'],20),
 ('official_native_route_svg_color_and_markers',['python','TEST_ROUTE_VISUAL_PARITY.py'],60),
 ('native_official_frontend_dom_structure',['python','TEST_OFFICIAL_FRONTEND_DOM.py'],20),
 ('better_osm_canvas_and_map_hash_fallbacks',['node','TEST_BETTER_OSM_NULL_GUARDS.js'],20),
 ('better_osm_export_bounds_missing_dom',['node','TEST_BETTER_OSM_EXPORT_BOUNDS.js'],20),
 ('deployment_metadata_paths_local',['python','TEST_DEPLOYABLE_META_PATHS.py'],20)
]
for label,command,limit in commands:run(label,command,limit)

page=(root/'index.html').read_text()
soup=BeautifulSoup(page,'html.parser')
inline=[el.get_text() for el in soup.find_all('script') if not el.has_attr('src') and (el.get('type') in (None,'text/javascript','module'))]
with tempfile.TemporaryDirectory(prefix='osm-inline-') as td:
    for index,text in enumerate(inline):
        item=Path(td)/f'inline_{index}.js';item.write_text(text)
        r=subprocess.run(['node','--check',str(item)],capture_output=True,text=True)
        if r.returncode:failures.append('inline_js_parse: '+r.stderr[-150:]);break
    checks['inline_js_parse']=not any(e.startswith('inline_js_parse') for e in failures)

# The checker must run on the user's machine with only this extracted ZIP.
# Reference hashes are stored in the archive; no /mnt/data paths are needed.
reference_info=json.loads((root/'FRONTEND_REFERENCE_HASHES.json').read_text())
strip_cache=lambda value:re.sub(r'(?<=\.js)\?v=[a-zA-Z0-9._-]+','',value)
checks['html_identical_except_asset_versions']=(
    hashlib.sha256(strip_cache(page).encode()).hexdigest()==
    reference_info['html_without_cache_query_sha256'])
if not checks['html_identical_except_asset_versions']:
    failures.append('html_diff_beyond_script_versions')

checks['css_identical_or_equivalent_to_official']=True
for p in (root/'assets').glob('*.css'):
    normalized=p.read_text('utf8').replace('./','/assets/')
    normalized=re.sub(r'\\([0-9a-fA-F]{4,6})\s',
                      lambda m:chr(int(m.group(1),16)),normalized)
    expected=reference_info['css_original_sha256'].get(p.name)
    if expected is None or hashlib.sha256(normalized.encode()).hexdigest()!=expected:
        checks['css_identical_or_equivalent_to_official']=False
        failures.append('css_difference: '+p.name)

manifest=root/'OFFICIAL_ASSET_CHECK.json'
try:
    entries=json.loads(manifest.read_text())
    checks['official_downloads_sha256_consistent']=len(entries)==12 and all(
        hashlib.sha256((root/e['path']).read_bytes()).hexdigest()==e['packaged_sha256']
        and (e['reference_sha256']==e['packaged_sha256'])==e['identical_to_official']
        for e in entries)
except Exception as e:
    checks['official_downloads_sha256_consistent']=False;failures.append('official_assets: '+str(e))

checks['no_account_capture']=not soup.head.has_attr('data-user') and not soup.head.has_attr('data-oauth-token') and not soup.select('.user-menu:not(.logged-out)')
checks['better_osm_script_loaded_once']=page.count('better-osm-merged.js')==1
checks['all_doc_referenced_test_scripts_exist']=all((root/cmd[1]).is_file() for _,cmd,_ in commands)
checks['configured_base_path_no_hardcoded_osm']=not re.search(r'["\']/osm(?:/|["\'])',(root/'better-osm-runtime.js').read_text())
for key,val in checks.items():
    if not val and not any(key in x for x in failures):failures.append(key)
output={'all_local_checks_passed':all(checks.values()),'checks':checks,'details':details,'failures':failures,
 'limits':['External map/search/feature/geocode providers not exercised against production.',
          'MapLibre WebGL rendering with real provider styles not certified.',
          'Chromium native-route test uses a fixture, not the production network.']}
(root/'FINAL_VALIDATION.json').write_text(json.dumps(output,indent=2,ensure_ascii=True)+'\n')
print(json.dumps({'all_local_checks_passed':output['all_local_checks_passed'],'checks':checks,'failures':failures},indent=2))
sys.exit(0 if output['all_local_checks_passed'] else 1)
