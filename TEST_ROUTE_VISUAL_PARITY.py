#!/usr/bin/env python3
"""Offline DOM + real Leaflet rendering parity for native OSM Directions.
Uses the actual saved official marker factory, not a substitute div icon.
"""
from pathlib import Path
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright
import shutil
from pathlib import Path as _P
def _chromium_exec():
    for name in ("chromium","chromium-browser","google-chrome","google-chrome-stable"):
        found=shutil.which(name)
        if found: return found
    cache=_P.home()/".cache/ms-playwright"
    if cache.is_dir():
        for d in sorted(cache.glob("chromium-*/chrome-linux/chrome")): return str(d)
    return None
_chromium_path=_chromium_exec()
import json, re, zipfile, hashlib
root=Path(__file__).resolve().parent
route_file=next((root/'assets/index_modules').glob('directions-*.js'))
source=route_file.read_text()
reference_path='assets/index_modules/'+route_file.name
# The downloaded official source is recorded in the independent manifest.
reference=json.loads((root/'OFFICIAL_ASSET_CHECK.json').read_text())
record=next(e for e in reference if e['path']==reference_path)
assert record['reference_sha256'] and record['identical_to_official'] is False, 'Expected patched native controller to differ from upstream'
# Verify pristine official route styling is untouched by the native compatibility patch.
assert 'L.polyline([],{color:"#03f",opacity:.3,weight:10})' in source
assert 'L.polyline([],{color:"#ff0",opacity:.5,weight:12})' in source
assert 'OSM.getMarker(n),draggable:!0,autoPan:!0' in source
html=BeautifulSoup((root/'index.html').read_text(),'html.parser')
for s in html.select('script'):s.decompose()
app=next((root/'assets').glob('application-*.js')).read_text()
source=source.replace('export default function(e){','window.NativeOSMDirections = function(e){',1)
# The /directions route block lives in the Turbo fragment in this package; the
# mocked loadSidebarContent injects exactly that official fragment.
_frag=BeautifulSoup((root/'panes/directions/index.html').read_text(),'html.parser').select_one('#sidebar_content_frame')
fragment_js=json.dumps(_frag.decode_contents())
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=_chromium_path, headless=True, args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(viewport={'width':1280,'height':800})
    errors=[]
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content(str(html), wait_until='load')
    # Load actual CSS to exercise Leaflet stroke/color and the official pin SVG symbols.
    for item in (root/'assets').glob('*.css'):
        page.add_style_tag(content=item.read_text())
    page.evaluate("window.Turbo={session:{drive:false,history:{stop(){}}}}")
    page.add_script_tag(content=app)
    page.evaluate('window.__directionsFragment = ' + fragment_js)
    page.evaluate('''() => {
      const map=L.map('map',{zoomControl:false}).setView([29.6025,106.5615],18);
      window.__testMap=map;
      map.setSidebarOverlaid=()=>{};
      OSM.cookies={get:()=>'',set:()=>{}};
      OSM.router={replace:()=>{},route:()=>{}};
      OSM.loadSidebarContent=async()=>{document.getElementById('sidebar_content').innerHTML=window.__directionsFragment;return true;};
      OSM.cropLocation=(coord)=>coord;
      OSM.i18n={locale:'en',t:(key,vars)=>key};
      // Critically do NOT replace OSM.getMarker with a fake factory.
      OSM.getMarker({icon:'start',color:'var(--marker-green)'});
      window.__racePending=[];
      window.fetch=async (url)=>{
         const uri=String(url);
         if(uri.includes('/reverse'))return {ok:true,json:async()=>({display_name:'Example Road'})};
         if(uri.includes('/search'))return {ok:true,json:async()=>([{lat:'29.6025',lon:'106.5615',display_name:'Example Road'}])};
         throw new Error('Unexpected fetch '+uri);
      };
    }''')
    startup_errors=list(errors)
    page.add_script_tag(content=source)
    page.evaluate('''() => {
      const engine=OSM.directionsEngines.find(v=>v.id==='fossgis_osrm_car');
      engine.getRoute=()=>Promise.resolve({
        line:[L.latLng(29.60258,106.56116),L.latLng(29.60260,106.56130),L.latLng(29.602589,106.561852)],
        steps:[['start','Example Road',160,[L.latLng(29.60258,106.56116),L.latLng(29.602589,106.561852)]]],
        distance:160,time:60,credit:'Fixture',creditlink:'#',demolink:'#'
      });
      window.__controller=window.NativeOSMDirections(window.__testMap);
      window.__controller.load();
    }''')
    page.wait_for_timeout(60)
    page.evaluate('''() => {
       for(const [selector,value] of [['#route_from','29.602580, 106.561160'],['#route_to','29.602589, 106.561852']]) {
         const input=document.querySelector(selector);input.value=value;input.dispatchEvent(new Event('change',{bubbles:true}));
       }
    }''')
    page.wait_for_function("document.querySelectorAll('#directions_route_steps tr').length > 0", timeout=8000)
    result=page.evaluate('''() => {
      const layers=Object.values(window.__testMap._layers);
      const paths=layers.filter(x=>x instanceof L.Polyline && !(x instanceof L.Polygon));
      const markers=layers.filter(x=>x instanceof L.Marker);
      const line=paths.find(x=>x.options.color==='#03f');
      return {
         lineCount:paths.length,
         lineOptions:line && {color:line.options.color,opacity:line.options.opacity,weight:line.options.weight},
         svgPath:line && {stroke:line._path?.getAttribute('stroke'),strokeOpacity:line._path?.getAttribute('stroke-opacity'),strokeWidth:line._path?.getAttribute('stroke-width')},
         markers:markers.map(x=>({draggable:x.options.draggable,html:x.options.icon.options.html.slice(0,220)})),
         fromTo:[document.querySelector('#route_from')?.value,document.querySelector('#route_to')?.value],
         stepRows:document.querySelectorAll('#directions_route_steps tr').length
      };
    }''')
    assert result['lineCount']==1, result
    assert result['lineOptions']=={'color':'#03f','opacity':0.3,'weight':10}, result
    assert result['svgPath']=={'stroke':'#03f','strokeOpacity':'0.3','strokeWidth':'10'}, result
    assert len(result['markers'])==2 and all(m['draggable'] for m in result['markers']), result
    for m,color,pin in zip(result['markers'],['var(--marker-green)','var(--marker-red)'],['pin-start','pin-destination']):
        assert color in m['html'] and pin in m['html'], m
    assert result['stepRows']==1,result
    # The application bundle has fixture-only errors from its missing complete startup; route events must create none.
    assert errors==startup_errors, {'startup_errors':startup_errors[-3:],'new_errors':errors[len(startup_errors):]}
    print(json.dumps({'ok':True,'result':result,'known_fixture_startup_errors':len(startup_errors)},indent=2))
    browser.close()
