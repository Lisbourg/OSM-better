#!/usr/bin/env python3
"""Validate the captured site's nonvisual metadata is deployable in subdirectories."""
from pathlib import Path
from bs4 import BeautifulSoup
from urllib.parse import urljoin, urlparse
import json
root=Path(__file__).resolve().parent
soup=BeautifulSoup((root/'index.html').read_text(),'html.parser')
result={}
for name in ('msapplication-config','msapplication-TileImage'):
    tag=soup.select_one('meta[name="'+name+'"]')
    assert tag and tag.get('content','').startswith('./assets/'), name
    assert (root/tag['content']).is_file(), tag['content']
    for base in ('https://example.test/','https://example.test/atlas/','https://example.test/deep/maps/'):
        url=urljoin(base,tag['content'])
        assert urlparse(url).path.startswith(urlparse(base).path),url
    result[name]=tag['content']
print(json.dumps(result,indent=2))
