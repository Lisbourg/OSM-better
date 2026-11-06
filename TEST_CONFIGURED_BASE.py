import os,re,json,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,sys
from pathlib import Path
SRC=Path(__file__).resolve().parent
CASES=[('/', 'root'),('/atlas/', 'atlas'),('/any/deep/path/', 'any/deep/path')]
results=[]
for prefix,subdir in CASES:
 with tempfile.TemporaryDirectory(prefix='osm-base-test-') as td:
  doc=Path(td)
  app=doc/subdir if prefix!='/' else doc
  shutil.copytree(SRC,app,dirs_exist_ok=True)
  (app/'app-base.json').write_text(json.dumps({'index_path':prefix+'index.html'})+'\n')
  sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
  proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(doc),str(app/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  try:
   def get(path,headers=None):
    req=urllib.request.Request(f'http://127.0.0.1:{port}{path}',headers=headers or {})
    for j in range(25):
     try:
      with urllib.request.urlopen(req,timeout=5) as res:return res.status,res.read().decode('utf8',errors='replace'),res.headers
     except urllib.error.URLError as e:
      if isinstance(e,urllib.error.HTTPError):return e.code,e.read().decode('utf8',errors='replace'),e.headers
      time.sleep(.08)
    raise Exception('No HTTP server response')
   url=prefix+'directions?engine=fossgis_osrm_car&route=40,-70;41,-71'
   status,body,headers=get(url)
   assert status==200,(prefix,status,body[:100])
   assert f'<base href="{prefix}">' in body,(prefix,'base missing')
   assert body.index('<base href=') < body.index('src="./app-base.js.php"'),prefix
   assert 'better-osm-merged.js' in body,(prefix,'BetterOSM removed')
   assert 'better-osm-panel-guard.js' not in body,(prefix,'Panel guard reference resurrected')
   assert '<form' in body,(prefix,'native front missing')
   paths=[prefix+'app-base.js.php',prefix+'better-osm-runtime.js',prefix+'assets/index_modules/directions-3645c5fd2fd03a58601ab5563ef4543628f8550ad4f0413d75007eefcaa0f4ae.js']
   for u in paths:
    st,t,_=get(u)
    assert st==200,(prefix,u,st,t[:100])
    assert len(t)>(100 if u.endswith('app-base.js.php') else 400),(u,'too short')
   assert 'window.__betterOsmBasePath = '+json.dumps(prefix) in get(paths[0])[1]
   for route in ['search?query=New+York','query?lat=40&lon=-70','note/new','history','export','node/123','changeset/999','user/example/history']:
    st,html,_=get(prefix+route)
    assert st==200,(prefix,route,st)
    assert f'<base href="{prefix}">' in html,(prefix,route,'missing base')
   st,health,_=get(prefix+'__health')
   assert st==200,(prefix,'health',st)
   assert json.loads(health)['base_path']==prefix,(prefix,'bad health base')
   # native Turbo frames use same configured base and must not return full homepage.
   st,html,_=get(prefix+'directions',{'Turbo-Frame':'sidebar_content_frame'})
   assert st==200 and re.search(r'<turbo-frame[^>]*id="sidebar_content_frame"',html) is not None,(prefix,st,'directions frame')
   st,html,_=get(prefix+'query',{'Turbo-Frame':'sidebar_content_frame'})
   assert st==200 and 'id="query-nearby"' in html,(prefix,st,'query frame')
   st,html,_=get(prefix+'panes/languages',{'Turbo-Frame':'select_language_list'})
   assert st==200 and 'select_language_list' in html,(prefix,st,'language frame')
   results.append({'configured_index_path':prefix+'index.html','deep_route':200,'js_bootstrap':200,'official_route_modules':200,'official_routes_tested':8,'turbo_frames_tested':3,'health':200})
  finally:
   proc.terminate()
   try:proc.wait(timeout=3)
   except subprocess.TimeoutExpired:proc.kill()
print(json.dumps(results,indent=2))
(Path(__file__).resolve().parent / 'BASE_PATH_TEST_RESULTS.json').write_text(json.dumps(results,indent=2)+'\n')
