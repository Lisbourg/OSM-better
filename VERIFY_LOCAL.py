#!/usr/bin/env python3
"""Deterministic checks for the packaged OpenStreetMap / Better OSM project.

These checks do not substitute for a production browser with PHP cURL and
working upstream map providers.
"""
from pathlib import Path
import subprocess, json, re, hashlib, sys
from bs4 import BeautifulSoup
root = Path(__file__).resolve().parent
errors=[]
checks={}

def must(value, label):
    checks[label]=bool(value)
    if not value: errors.append(label)

files = list(root.rglob('*'))
js=sorted(p for p in files if p.is_file() and p.suffix in ('.js','.mjs'))
php=sorted(p for p in files if p.is_file() and p.suffix=='.php')
for p in js:
    r=subprocess.run(['node','--check',str(p)], capture_output=True,text=True)
    if r.returncode: errors.append(f'JS parse error in {p.relative_to(root)}: {r.stderr[:120]}')
for p in php:
    r=subprocess.run(['php','-l',str(p)],capture_output=True,text=True)
    if r.returncode: errors.append(f'PHP parse error in {p.relative_to(root)}: {r.stderr[:120]}')
checks['js_syntax']=not any('JS parse error' in e for e in errors)
checks['php_syntax']=not any('PHP parse error' in e for e in errors)

page=(root/'index.html').read_text('utf-8')
soup=BeautifulSoup(page,'html.parser')
must(bool(soup.select('header')) and bool(soup.select('#map')) and bool(soup.select('#sidebar')), 'native_map_structure')
must(bool(soup.select('form.directions_form')) and 'id="directions_route"' in (root/'panes/directions/index.html').read_text(),'native_directions_structure')
must(not soup.head.has_attr('data-user') and not soup.head.has_attr('data-oauth-token') and not soup.select('.user-menu:not(.logged-out)'), 'no_captured_login_state')
must(not soup.select('.layers-ui') and not soup.select('.share-ui'), 'no_stale_preinserted_dynamic_panels')
configured=json.loads((root/'app-base.json').read_text())
must(configured.get('index_path','').endswith('/index.html'),'config_index_path')
for script in soup.select('script[src]'):
    url=script['src'].split('?')[0]
    if url.startswith(('./','../')):
        must((root/url).resolve().is_file(),f'js_reference:{url}')
for css in soup.select('link[rel=stylesheet]'):
    url=css['href'].split('?')[0]
    if url.startswith(('./','../')): must((root/url).resolve().is_file(),f'css_reference:{url}')
app=next((root/'assets').glob('application-*.js')).read_text()
paths=dict(re.findall(r'(index_(?:changeset|directions|element|export|history|home|index|new_note|note|query|search)):window\.__betterOsmBasePath\+"([^"]+)"',app))
must(len(paths)==11,'eleven_native_route_module_references')
for name,path in paths.items():must((root/path.split('?')[0]).is_file(),f'native_module_present:{name}')
must((root/'assets/@mapbox/mapbox-gl-rtl-text/dist/mapbox-gl-rtl-text-f010fb2232c5df9c248168b47d8ff5d6b3bfd57a51107f9f6cf81ad3ee816c40.js').stat().st_size>100_000,'authentic_rtl_asset')
must(page.count('better-osm-merged.js')==1, 'better_osm_loaded_once')
must('better-osm-directions.js' not in page and 'better-osm-search-query.js' not in page, 'duplicate_route_ui_controllers_disabled')
must('"map=3/0/0&layers=M"' not in (root/'better-osm-runtime.js').read_text(),'no_forced_zero_coordinate_initial_map')
must('better-osm-panel-guard.js' not in page and not (root/'better-osm-panel-guard.js').exists(),'panel_guard_fully_removed')
must('betterActivateNativeCustomBase' in (root/'better-osm-merged.js').read_text(),'better_osm_custom_base_layer_code')
must('map.baseLayers.push(layer)' in (root/'better-osm-merged.js').read_text(),'custom_layer_native_base_registration')
for p in files:
    if not p.is_file(): continue
    must(not re.search(r'[\u3400-\u9fff]',str(p.relative_to(root))), f'no_cjk_filename:{p.name}')
    if p.suffix.lower() in {'.js','.mjs','.php','.html','.css','.json','.md','.txt','.py','.xml','.svg','.htaccess'} or p.name=='.htaccess':
        try:text=p.read_text(encoding='utf-8')
        except (UnicodeError,OSError):continue
        found=re.search(r'[\u3400-\u9fff]',text)
        if found and p.name=='index.html' and p.parent.name=='languages':
            # panes/languages/index.html is a verbatim official OSM fragment:
            # the official language selector renders native names in their own
            # script (zh-CN/zh-TW/ja entries). CJK is allowed only inside
            # native_name elements.
            outside=[ln for ln in text.splitlines() if re.search(r'[\u3400-\u9fff]',ln)
                     and 'native_name' not in ln and not re.match(r'^\s*(<span|</span>)?\s*[^<]*[\u3400-\u9fff]',ln)]
            if not outside: found=None
        if found:errors.append('CJK source: '+str(p.relative_to(root)))
checks['no_cjk_text']=not any(e.startswith('CJK source: ') for e in errors)
result={'ok':not errors,'js_files':len(js),'php_files':len(php),'native_modules':len(paths),'checks':checks,'errors':errors}
print(json.dumps({'ok':result['ok'],'js_files':len(js),'php_files':len(php),'native_modules':len(paths),'failed':errors[:20]},indent=2))
(root/'LOCAL_CHECK_RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')
if errors:sys.exit(1)
