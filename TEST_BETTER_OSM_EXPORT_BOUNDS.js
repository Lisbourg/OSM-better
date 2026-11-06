"use strict";
/* Execute the real Better OSM map bounds helper against absent and partial DOM. */
const fs=require('fs');
const vm=require('vm');
const path=require('path');
const assert=require('assert');
const source=fs.readFileSync(path.join(__dirname,'better-osm-merged.js'),'utf8');
const begin=source.indexOf('async function getMapBounds() {');
const end=source.indexOf('\n/**',begin);
assert(begin>=0 && end>begin);
const fn=source.slice(begin,end);
async function runCase(map, sidebar, exportLink, fields) {
  const events=[];
  const document={
    querySelector(selector){
      if(selector==='#sidebar') return sidebar;
      if(selector.startsWith('nav a[')) return exportLink;
      return fields?.[selector]||null;
    }
  };
  const ctx={document,getMap:()=>map,sleep:()=>Promise.resolve(),console:{warn(...args){events.push(['warn',args])}}};
  vm.createContext(ctx);
  vm.runInContext(fn,ctx);
  return {result:await vm.runInContext('getMapBounds()',ctx),events};
}
(async()=>{
  const native={getBounds:()=>({native:true})};
  assert.deepEqual((await runCase(native)).result,{native:true});
  assert.equal((await runCase(null)).result,null);
  const sidebar={style:{display:'block',width:'350px'}};
  assert.equal((await runCase(null,sidebar,null)).result,null);
  assert.equal(sidebar.style.display,'block');
  let opened=0;
  const exportLink={click(){opened++}};
  assert.equal((await runCase(null,sidebar,exportLink,{})).result,null);
  assert.equal(opened,1);
  assert.equal(sidebar.style.display,'block');
  assert.equal(sidebar.style.width,'350px');
  const fields={'#minlat':{value:'30'},'#maxlat':{value:'31'},'#minlon':{value:'100'},'#maxlon':{value:'102'}};
  const bounds=(await runCase(null,sidebar,exportLink,fields)).result;
  assert.equal(bounds.getNorthWest().lat,31);
  assert.equal(bounds.getNorthWest().lng,100);
  assert.equal(bounds.getSouthEast().lat,30);
  assert.equal(bounds.getSouthEast().lng,102);
  assert.equal(sidebar.style.display,'block');
  assert.equal(sidebar.style.width,'350px');
  const invalid={...fields,'#minlon':{value:''}};
  assert.equal((await runCase(null,sidebar,exportLink,invalid)).result,null);
  console.log(JSON.stringify({ok:true,mapPresent:true,missingSidebar:true,missingExportLink:true,missingFields:true,validExportBounds:true,invalidFields:true,sidebarRestored:true}));
})().catch(err=>{console.error(err);process.exit(1)});
