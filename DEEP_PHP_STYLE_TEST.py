#!/usr/bin/env python3
"""Exercise the currently shipped proxy function, not a copied function body."""
import json
import subprocess
import tempfile
from pathlib import Path

root=Path(__file__).resolve().parent
source=(root/'proxy.php').read_text()
functions=source[source.index('function proxy_origin('):source.index('function proxy_rewrite_html(')]
fixture={
    'version':8,
    'sources':{'remote':{'type':'vector','url':'https://tiles.openfreemap.org/data/style.json'}},
    'sprite':'../sprites/default',
    'glyphs':'../fonts/{fontstack}/{range}.pbf',
    'layers':[
        {'id':'a','type':'symbol','layout':{'text-variable-anchor':[], 'text-font':[], 'text-field':['coalesce',['get','name'],'']},
          'paint':{'text-opacity':['case',['in',['get','type'],[]],1,0]}},
        {'id':'b','type':'fill','filter':[],'paint':{'fill-color':'#333333'}}]
}
script='<?php\n'+functions+'\n$raw=base64_decode("'+__import__('base64').b64encode(json.dumps(fixture).encode()).decode()+'");\n'
script+='''$d=json_decode(proxy_rewrite_map_style_json($raw,'https://tiles.openfreemap.org/styles/liberty/style.json','https://fixture.test','/osm/'),true);
$checks=[
  'empty_filter_removed'=>!isset($d['layers'][1]['filter']),
  'invalid_anchor_omitted'=>!isset($d['layers'][0]['layout']['text-variable-anchor']),
  'invalid_empty_font_omitted'=>!isset($d['layers'][0]['layout']['text-font']),
  'nested_empty_literal'=>$d['layers'][0]['paint']['text-opacity'][1][2]===['literal',[]],
  'source_via_proxy'=>str_starts_with($d['sources']['remote']['url'],'https://fixture.test/osm/__proxy?url='),
  'sprite_template_preserved'=>str_starts_with($d['sprite'],'https://tiles.openfreemap.org/'),
  'glyph_template_preserved'=>str_starts_with($d['glyphs'],'https://tiles.openfreemap.org/')
];echo json_encode($checks);if(in_array(false,$checks,true))exit(1);
'''
with tempfile.TemporaryDirectory() as d:
    script_path=Path(d)/'proxy_style_test.php'
    script_path.write_text(script)
    result=subprocess.run(['php',str(script_path)],capture_output=True,text=True)
checks=json.loads(result.stdout)
report={'checks':checks, 'allPassed':result.returncode==0 and all(checks.values())}
(root/'DEEP_PHP_STYLE_CHECK.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
if not report['allPassed']:raise SystemExit(1)
