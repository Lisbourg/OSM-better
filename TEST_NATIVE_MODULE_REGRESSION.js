'use strict';
const fs=require('fs');
const vm=require('vm');
const assert=require('assert/strict');
const path=require('path');
const root=__dirname;
const app=fs.readFileSync(fs.readdirSync(path.join(root,'assets')).filter(f=>/^application-.*\.js$/.test(f)).map(f=>path.join(root,'assets',f))[0],'utf8');
let a=app.indexOf('OSM.MapLibre.setOMTMapLanguage=function(t){');
let b=app.indexOf(',L.OSM.ThunderforestVector=',a);
assert.ok(a>0&&b>a,'Native MapLibre localization must exist');
const functionSource=app.slice(a+'OSM.MapLibre.setOMTMapLanguage='.length,b);
const OSM={preferred_languages:['fr-FR','en']};
const testLanguage=(new Function('OSM','return ('+functionSource+')'))(OSM);
let calls=0;
assert.doesNotThrow(()=>testLanguage(null));
assert.doesNotThrow(()=>testLanguage({style:null}));
assert.doesNotThrow(()=>testLanguage({style:{loaded(){return true},stylesheet:{metadata:{}}},supportedLanguages:['fr_fr'],setLanguage(){calls++}}));
assert.equal(calls,0,'Non-MapTiler style must not be mutated');
let loadCb;
assert.doesNotThrow(()=>testLanguage({style:{loaded(){return false}},once(event,fn){assert.equal(event,'load');loadCb=fn}}));
assert.equal(typeof loadCb,'function','Loading style should wait for native load event');
const maptiler={style:{loaded(){return true},stylesheet:{metadata:{'maptiler:language':'en'}}},supportedLanguages:['fr_fr','en'],setLanguage(v){calls++;assert.equal(v,'fr_fr')}};
assert.doesNotThrow(()=>testLanguage(maptiler));
assert.equal(calls,1,'MapTiler metadata must preserve language functionality');

const modName=fs.readdirSync(path.join(root,'assets','index_modules')).find(f=>f.startsWith('query-')&&f.endsWith('.js'));
const original=fs.readFileSync(path.join(root,'assets','index_modules',modName),'utf8');
assert.equal(original.includes('export default function(e){'),true);
let source=original.replace('export default function(e){','globalThis.OfficialQuery=function(e){');
const requests=[];const aborted=[];const layers=[];const queues=new Map();
class FakeJQ{
 constructor(selector){this.selector=selector;this.values=queues.get(selector)||{};queues.set(selector,this.values);this.length=1;}
 on(){return this;}each(){return this;}removeClass(){return this;}addClass(){return this;}
 hide(){return this;}show(){return this;}empty(){return this;}text(){return this;}appendTo(){return this;}attr(){return this;}
 find(sel){return new FakeJQ(this.selector+' '+sel)}
 data(key,value){if(arguments.length===1)return this.values[key];this.values[key]=value;return this;}
}
const $=s=>new FakeJQ(typeof s==='string'?s:'elem');
const OSMQuery={...OSM,OVERPASS_URL:'https://overpass.private.coffee/api/interpreter',OVERPASS_CREDENTIALS:false,
 i18n:{t(){return 'missing'}},cropLocation(coord){return coord},featurePrefix(){return ''},featureName(){return ''}};
const L={latLng(...args){return {args,wrap(){return this}}},circle(...args){const item={args,addTo(){layers.push(this);return this}};return item}};
const map={getBounds(){return {getSouthWest(){return {lat:28,lng:105}},getNorthEast(){return {lat:31,lng:108}},contains(){return true}}},getZoom(){return 18},setView(){throw Error('Unexpected camera move')},removeLayer(layer){const i=layers.indexOf(layer);if(i>=0)layers.splice(i,1)}};
const context={OSM:OSMQuery,L,$,URLSearchParams,Number,console,location:{hash:'#map=18/29/106'},AbortController,fetch:async (url,opt)=>{requests.push({url,opt});return {ok:true,json:async()=>({elements:[]})}}};
vm.createContext(context);vm.runInContext(source,context,{filename:modName});
const controller=context.OfficialQuery(map);
controller.init('/query');
controller.init('/query?lat=&lon=');
controller.init('/query?lat=Infinity&lon=1');
controller.init('/query?lat=91&lon=0');
controller.init('/query?lat=1&lon=181');
assert.equal(requests.length,0,'Missing/out of range coords must not request remote data');
controller.init('/query?lat=0&lon=0');
assert.equal(requests.length,2,'Valid zero-coordinate query should send the two normal requests');
assert.equal(layers.length,1,'Query should add its original circle marker');
const controllers=[queues.get('#query-nearby').ajax,queues.get('#query-isin').ajax];
controller.unload();
assert.equal(layers.length,0,'Leaving Query should clean temporary circle');
assert.ok(controllers.every(c=>c.signal.aborted),'Leaving Query should abort unfinished requests');
console.log(JSON.stringify({native_maplibre_guard:true,invalid_query_rejected:true,valid_zero_coords:true,query_abort_on_unload:true}));
