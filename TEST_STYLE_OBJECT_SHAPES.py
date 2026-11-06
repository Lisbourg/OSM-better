#!/usr/bin/env python3
"""Ensure PHP preserves original JSON object vs array types in a style."""
import base64, json, subprocess, tempfile
from pathlib import Path
root=Path(__file__).resolve().parent
src=(root/'proxy.php').read_text()
func=src[src.index('function proxy_origin('):src.index('function proxy_rewrite_html(')]
fixture={"version":8,"metadata":{},"sources":{"main":{"type":"raster","tiles":["https://tiles.openfreemap.org/{z}/{x}/{y}.png"],"metadata":{}}},"layers":[{"id":"base","type":"background","layout":{},"paint":{}}]}
encoded=base64.b64encode(json.dumps(fixture).encode()).decode()
script='<?php\n'+func+f'\n$raw=base64_decode("{encoded}");\n'
script+='''$result=proxy_rewrite_map_style_json($raw, 'https://tiles.openfreemap.org/styles/demo/style.json','https://local.test','/map/');
$doc=json_decode($result);
$checks=[
  'style_metadata_is_object'=>is_object($doc->metadata),
  'source_metadata_is_object'=>is_object($doc->sources->main->metadata),
  'layer_layout_is_object'=>is_object($doc->layers[0]->layout),
  'layer_paint_is_object'=>is_object($doc->layers[0]->paint),
  'tile_urls_remain_list'=>is_array($doc->sources->main->tiles)
];
echo json_encode($checks);if(in_array(false,$checks,true))exit(1);
'''
with tempfile.TemporaryDirectory() as tmp:
 p=Path(tmp)/'check.php';p.write_text(script)
 r=subprocess.run(['php',str(p)],text=True,capture_output=True)
 print(r.stdout or r.stderr)
 raise SystemExit(r.returncode)
