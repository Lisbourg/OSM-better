// Exercise the actual Better OSM native-base-switcher function with a minimal
// Leaflet contract. This is a logic test, not a tile-server/network test.
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/better-osm-merged.js','utf8');
const begin=code.indexOf('function betterActivateNativeCustomBase(info, retries = 0) {');
const end=code.indexOf('\nfunction betterSelectNativeCustomBase(info)',begin);
assert(begin>0 && end>begin,'Native-base function not found');
const snippet=code.slice(begin,end);
const enabled=new Set();
const radio={checked:false};
const standard={options:{layerId:'mapnik',code:'M'}};
const map={baseLayers:[standard],hasLayer(l){return enabled.has(l)},removeLayer(l){enabled.delete(l)},addLayer(l){enabled.add(l)},fire(type){this.lastEvent=type}};
enabled.add(standard);
const context=vm.createContext({
  window:{L:{tileLayer(url,options){return {url,options,addTo(m){m.addLayer(this);return this}}}},__betterOsmProxyTileURL:url=>'/proxy?url='+encodeURIComponent(url)},
  betterNativeMap:()=>map,betterNativeCustomEntry:()=>radio,
  setAttributionPrefix:()=>{},console,clearTimeout, setTimeout,
  betterNativeCustomLayer:null,betterNativeCustomRetry:null,
  document:{querySelectorAll:()=>[radio]}
});
vm.runInContext(snippet,context);
vm.runInContext('betterActivateNativeCustomBase({url:"https://example.org/tiles/{z}/{x}/{y}.png",label:"First"})',context);
assert.equal(enabled.size,1,'Old Standard layer was not removed');
let first=[...enabled][0];
assert.equal(first.options.layerId,'better_custom');
assert(first.url.startsWith('/proxy?url='),'The new tile source did not use server proxy');
vm.runInContext('betterActivateNativeCustomBase({url:"https://other.example.org/tiles/{z}/{x}/{y}.png",label:"Second"})',context);
assert.equal(enabled.size,1,'Previous custom layer remains active');
assert(!enabled.has(first),'Previous custom layer remains active');
assert.equal(map.lastEvent,'baselayerchange');
console.log(JSON.stringify({ok:true,activeBaseLayers:enabled.size,mutuallyExclusive:true,proxyURL:true}));
