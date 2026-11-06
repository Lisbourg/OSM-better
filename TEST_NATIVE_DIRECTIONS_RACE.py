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
import json
root=Path(__file__).resolve().parent
html=BeautifulSoup((root/'index.html').read_text(),'html.parser')
for script in html.select('script'):script.decompose()
app=next((root/'assets').glob('application-*.js')).read_text()
source=next((root/'assets/index_modules').glob('directions-*.js')).read_text()
source=source.replace('export default function(e){','window.NativeOSMDirections = function(e){',1)
# The /directions route block lives in the Turbo fragment in this package; the
# mocked loadSidebarContent injects exactly that official fragment.
_frag=BeautifulSoup((root/'panes/directions/index.html').read_text(),'html.parser').select_one('#sidebar_content_frame')
import json as _json
fragment_js=_json.dumps(_frag.decode_contents())
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=_chromium_path,headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
 page=browser.new_page()
 errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)[:500]))
 page.set_content(str(html),wait_until='load')
 page.add_script_tag(content=app)
 page.evaluate('window.__directionsFragment = ' + fragment_js)
 baseErrors=len(errors)
 page.evaluate('''() => {
   window.__betterOsmBasePath='/osm/';
   window.__viewCalls={ fitBounds:0,panTo:0,setView:0 };
   const map=L.map('map',{zoomControl:false}).setView([29.6025,106.5615],18);
   for(const m of ['fitBounds','panTo','setView']) {
      const native=map[m].bind(map);
      map[m]=function(...args){ window.__viewCalls[m]++; return native(...args); };
   }
   window.__testMap=map;
   map.setSidebarOverlaid=function(overlaid){ document.querySelector('#content')?.classList.toggle('overlay-sidebar',overlaid); };
   map.getState=()=>({});
   map.getLayersCode=()=>'M';
   window.OSM.cookies={get:()=>'',set:()=>{}};
   window.OSM.router={replace:()=>{},route:(url)=>{if((url==='/' || url.startsWith('/#') || url.startsWith('/map=')) && window.__nativeDir)window.__nativeDir.unload();},withoutMoveListener:(fn)=>fn()};
   window.OSM.loadSidebarContent=async ()=>{document.getElementById('sidebar_content').innerHTML=window.__directionsFragment;return true;};
   window.OSM.getMarker=()=>L.divIcon({html:'<span class="marker">pin</span>'});
   window.OSM.cropLocation=(coord)=>coord;
   window.OSM.i18n={locale:'en',t:(label,vars)=>(''+label+(vars?.distance?' '+vars.distance:''))};
   window.__requests=[]; window.fetch=async (url,opts)=>{
     window.__requests.push(String(url));
     const uri=String(url);
     if(uri.includes('/reverse'))return {ok:true,json:async()=>({display_name:'Example Road, Chongqing'})};
     if(uri.includes('/search'))return {ok:true,json:async()=>([{lat:'29.6025',lon:'106.5615',display_name:'Example Road'}])};
     if(uri.includes('route/v1'))return {ok:true,json:async()=>({code:'Ok',waypoints:[{hint:'a'},{hint:'b'}],routes:[{legs:[{distance:199,duration:85,steps:[{maneuver:{type:'depart',modifier:'straight'},name:'Example Road',mode:'driving',driving_side:'right',intersections:[],distance:199,duration:85,geometry:'??'},{maneuver:{type:'arrive',modifier:'straight'},name:'Example Road',mode:'driving',driving_side:'right',intersections:[],distance:0,duration:0,geometry:'??'}]}]}]})};
     throw Error('Unexpected request '+uri);
   };
 }''')
 page.add_script_tag(content=source)
 print('ROUTE ENGINE DIRECT', page.evaluate("""async ()=> {try {
  const eng=OSM.directionsEngines.find(x=>x.id==='fossgis_osrm_car');
  const res=await eng.getRoute([L.latLng(29.60258,106.56116),L.latLng(29.602589,106.561852)],undefined);
  return {ok:true,res};
 } catch(e){return {ok:false,message:String(e),stack:e.stack?.slice(0,800)}}}"""))
 try:
  page.evaluate('''() => {
    window.__nativeDir=window.NativeOSMDirections(window.__testMap);
    window.__nativeDir.load();
  }''')
  page.wait_for_timeout(100)
  page.evaluate('''() => {
    for(const [selector, value] of [['#route_from','29.602580, 106.561160'],['#route_to','29.602589, 106.561852']]){
      const input=document.querySelector(selector);input.value=value;input.dispatchEvent(new Event('change',{bubbles:true}));
    }
  }''')
  page.wait_for_timeout(700)
  res=page.evaluate('''() => {
     const route=document.getElementById('directions_route');
     const pins=Object.values(window.__testMap._layers).filter(layer=>layer instanceof L.Marker);
     return {controller:typeof window.__nativeDir,steps:document.querySelectorAll('#directions_route_steps tr').length,
      displayed:!route.hidden, pins:pins.length, formDisplay:getComputedStyle(document.querySelector('.directions_form')).display,
      mapChanges:window.__viewCalls,jsErrors:[],
      requests:window.__requests, errHidden:document.getElementById("directions_error")?.hidden, errorText:document.getElementById("directions_error")?.textContent, routeCount:Object.values(window.__testMap._layers).filter(layer=>layer instanceof L.Polyline).length};
  }''')
  print('NATIVE DOM INITIAL',res,'ERRORS',errors[baseErrors:][:8])

  # Two unresolved real controller requests in reverse order: newer route wins.
  page.evaluate('''() => {
    const engine=OSM.directionsEngines.find(v=>v.id==='fossgis_osrm_car');
    window.__racePending=[];
    engine.getRoute=function(coords,signal){return new Promise((resolve,reject)=>{__racePending.push({coords,signal,resolve,reject})})};
    window.__raceResult=label=>({line:[L.latLng(29.60258,106.56116),L.latLng(29.602589,106.561852)],
       steps:[['start',label,180,[L.latLng(29.60258,106.56116),L.latLng(29.602589,106.561852)]]],
       distance:180,time:85,credit:'Fixture',creditlink:'#',demolink:'#'});
    window.__submitRoute=()=>document.querySelector('.directions_form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    __submitRoute();
  }''')
  page.wait_for_function('window.__racePending.length >= 1')
  page.evaluate('window.__submitRoute()')
  page.wait_for_function('window.__racePending.length >= 2')
  page.evaluate('window.__racePending[1].resolve(window.__raceResult("NEWER_ROUTE"))')
  page.wait_for_function('document.querySelector("#directions_route_steps")?.textContent?.includes("NEWER_ROUTE")')
  page.evaluate('window.__racePending[0].resolve(window.__raceResult("STALE_ROUTE"))')
  page.wait_for_timeout(250)
  assert page.evaluate('document.querySelector("#directions_route_steps").textContent.includes("NEWER_ROUTE")'), 'Latest route result lost'
  assert not page.evaluate('document.querySelector("#directions_route_steps").textContent.includes("STALE_ROUTE")'), 'Stale route overwrote newest'
  assert page.evaluate('!window.__racePending[1].signal.aborted'), 'Newer request was accidentally aborted'
  print('ROUTE ASYNC RACE',{'newer_wins': True,'older_ignored': True, 'newer_not_aborted': True})
  # Native model (official module + official close handlers):
  #   - dragging a pin while directions is active recalculates the route and
  #     must not move the camera;
  #   - the From/To form X routes home, which unloads the directions module
  #     (search form returns, directions form hides, route output removed);
  #   - the sidebar X (already off after unload) only triggers the native
  #     document-level handler and must be harmless afterwards.
  cameraBeforeClose=page.evaluate('window.__viewCalls')
  page.evaluate('''() => {
    const marker=Object.values(window.__testMap._layers).find(layer=>layer instanceof L.Marker);
    if(!marker)throw Error('Start marker missing');
    marker.setLatLng([29.6027,106.5613]);marker.fire('dragend',{target:marker});
  }''')
  page.wait_for_timeout(350)
  afterDrag=page.evaluate('''() => ({steps:document.querySelectorAll('#directions_route_steps tr').length,
    polylines:Object.values(window.__testMap._layers).filter(l=>l instanceof L.Polyline).length,
    formVisible:getComputedStyle(document.querySelector('.directions_form')).display!=='none',
    camera:window.__viewCalls})''')
  assert afterDrag['steps']>0 and afterDrag['polylines']>=1 and afterDrag['formVisible'], afterDrag
  assert afterDrag['camera']==cameraBeforeClose, (afterDrag['camera'],cameraBeforeClose)
  print('AFTER DRAG',afterDrag)
  page.evaluate('''() => document.querySelector('.directions_form button.btn-close').click()''')
  page.wait_for_timeout(100)
  upper=page.evaluate('''() => ({searchVisible:getComputedStyle(document.querySelector('.search_form')).display!=='none',
      formHidden:document.querySelector('.directions_form').style.display==='none' || getComputedStyle(document.querySelector('.directions_form')).display==='none',
      polylines:Object.values(window.__testMap._layers).filter(l=>l instanceof L.Polyline).length,
      steps:document.querySelectorAll('#directions_route_steps tr').length,
      camera:window.__viewCalls})''')
  assert upper['searchVisible'] and upper['formHidden'], upper
  assert upper['polylines']==0 and upper['steps']==0, upper
  assert upper['camera']==cameraBeforeClose, (upper['camera'],cameraBeforeClose)
  print('AFTER UPPER X (native exit to home)',upper)
  page.evaluate('''() => {
    const buttons=[...document.querySelectorAll('#sidebar > .sidebar-close-controls button.btn-close')].filter(b=>!b.disabled);
    if(buttons.length!==1) throw Error('Unexpected native result close controls: '+buttons.length);
    buttons[0].click();
  }''')
  page.wait_for_timeout(100)
  lower=page.evaluate('''() => ({searchVisible:getComputedStyle(document.querySelector('.search_form')).display!=='none',
      camera:window.__viewCalls})''')
  assert lower['searchVisible'] and lower['camera']==cameraBeforeClose, lower
  print('AFTER LOWER X (native, already unloaded)',lower)
 except Exception as e:print('TEST FAILED',str(e)[:1400]);print('PAGE ERRORS',errors[-10:]);raise
 browser.close()
