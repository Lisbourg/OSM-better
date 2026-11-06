#!/usr/bin/env python3
"""Compare stable native OSM UI structure against the user's official capture.

The captured page baked open menus/panes into its DOM. The live official page
builds those at runtime, and this package mirrors the live behavior: context
menu entries and custom editors are rendered by the JS bundles, and the
directions route block is served by the /directions Turbo fragment. The
baseline therefore splits official ids into
  - static ids that must exist in index.html,
  - dynamic_capture_only_ids (Better OSM note filters / layer labels),
  - runtime_created_ids (context menu, custom editors, directions blocks,
    route sprite symbols) that must NOT exist statically.
The four directions selectors are verified against the fragment file.
Dynamic route rows, private account state, deploy paths and loaded map tiles
are intentionally not part of the structural reference.
"""
from pathlib import Path
from bs4 import BeautifulSoup
import json,sys
root=Path(__file__).parent
base=json.loads((root/'OFFICIAL_FRONTEND_DOM_BASELINE.json').read_text())
baseline=base['signatures']
fragment_baseline=base['fragment_signatures']
local=BeautifulSoup((root/'index.html').read_text(),'html.parser')
fragment=BeautifulSoup((root/'panes/directions/index.html').read_text(),'html.parser')
keys={'id','class','type','name','role','for','viewbox','clip-path','d','fill','stroke','stroke-width','data-bs-toggle','data-bs-target','value'}
def structure(node):
    result=[]
    for item in [node]+node.find_all(True):
        attrs={key:list(val) if isinstance(val,list) else val for key,val in item.attrs.items() if key in keys}
        result.append([item.name,[[k,v] for k,v in sorted(attrs.items())]])
    return result
results={}
for selector,expected in baseline.items():
    actual=[structure(n) for n in local.select(selector)]
    match=actual==expected
    results[selector]={'matched':match,'official_instances':len(expected),'local_instances':len(actual)}
    if not match: print('MISMATCH',selector,file=sys.stderr)
for selector,expected in fragment_baseline.items():
    actual=[structure(n) for n in fragment.select(selector)]
    if selector=='#directions_route_steps':actual=[x[:1] for x in actual]
    match=actual==expected
    results['fragment:'+selector]={'matched':match,'official_instances':len(expected),'local_instances':len(actual)}
    if not match: print('MISMATCH fragment',selector,file=sys.stderr)
assert all(x['matched'] for x in results.values()),'Native route markup changed'
all_local_ids={item['id'] for item in local.select('[id]')}
official_ids=set(base['official_captured_ids'])
dynamic_only=set(base['dynamic_capture_only_ids'])
runtime_ids=set(base['runtime_created_ids'])
static_expected=official_ids-dynamic_only-runtime_ids
assert not (dynamic_only & runtime_ids) and not (dynamic_only & (official_ids-dynamic_only-runtime_ids)) \
    and (static_expected|dynamic_only|runtime_ids)==official_ids, 'baseline partition inconsistent'
assert all_local_ids==static_expected, {
    'missing_static_ids':sorted(static_expected-all_local_ids),
    'unexpected_static_ids':sorted(all_local_ids-static_expected)
}
assert len(all_local_ids)==len(static_expected) and len(dynamic_only)==19 and len(runtime_ids)==56
assert len(all_local_ids)+len(runtime_ids)+len(dynamic_only)==122
assert len(local.select('script[src*="better-osm-merged.js"]'))==1
print(json.dumps({'ok':True,'selectors':results,'static_ids_preserved':len(all_local_ids),'runtime_created_ids_excluded':len(runtime_ids),'dynamically_created_ids_excluded':len(dynamic_only),'scope':'static routing controls and pin SVG; runtime context menu, custom editors and directions fragment intentionally excluded; no live pixel equality claim'},indent=2))
