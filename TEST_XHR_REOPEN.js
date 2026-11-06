'use strict';
const fs=require('fs');
const assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/better-osm-runtime.js','utf8');
const start=source.indexOf('  function NativeBridgeXHR() {');
const end=source.indexOf('  NativeBridgeXHR.prototype = nativeXHR.prototype;',start);
assert.ok(start>=0&&end>start);
const body=source.slice(start,end);
class FakeNativeXHR extends EventTarget {
 constructor(){super();this.timeout=0;this.responseType='';}
 get readyState(){return this.nativeReadyState||0;}
 get status(){return 0;}
 get statusText(){return '';}
 get response(){return null;}
 get responseText(){return '';}
 get responseURL(){return '';}
 open(){this.nativeOpen=true;this.nativeReadyState=1;}
 send(){this.nativeSent=true;}
 abort(){this.nativeAborted=true;this.nativeReadyState=0;}
 setRequestHeader(){}
 getAllResponseHeaders(){return '';}
 getResponseHeader(){return null;}
}
const getters=Object.fromEntries(['readyState','status','statusText','response','responseText','responseURL'].map(key=>[key,Object.getOwnPropertyDescriptor(FakeNativeXHR.prototype,key).get]));
const controls=[];
function sendServerRequest(){let done; const p=new Promise(resolve=>done=resolve);let cancelled=false;p.abort=()=>{cancelled=true};controls.push({p,done,get cancelled(){return cancelled}});return p;}
const xhrFactory=new Function('nativeXHR','nativeXhrGetters','routeOfficial','location','nativeXhrOpen','nativeXhrSend','nativeXhrSetHeader','nativeXhrAbort','sendServerRequest','Event',body+'\nreturn NativeBridgeXHR;');
const Bridge=xhrFactory(FakeNativeXHR,getters,url=>url,{href:'https://fixture.test/',origin:'https://fixture.test'},FakeNativeXHR.prototype.open,FakeNativeXHR.prototype.send,FakeNativeXHR.prototype.setRequestHeader,FakeNativeXHR.prototype.abort,sendServerRequest,Event);
(async()=>{
 const xhr=new Bridge();const events=[];
 for(const name of ['abort','load','loadend','error','readystatechange'])xhr.addEventListener(name,()=>events.push(name));
 xhr.open('GET','https://api.example.com/v1');xhr.send();
 assert.equal(controls.length,1);
 xhr.abort();
 assert.ok(controls[0].cancelled,'XHR abort must abort the underlying network request');
 controls[0].done({status:200,statusText:'OK',finalUrl:'https://api.example.com/v1',response:'stale',responseHeaders:'Content-Type: text/plain'});
 await new Promise(setImmediate);
 assert.equal(events.filter(e=>e==='load').length,0,'Cancelled XHR must never produce a late load event');
 const doneEvents=events.filter(e=>e==='loadend').length;
 xhr.open('GET','https://api.example.com/v2');xhr.send();
 assert.equal(controls.length,2);
 controls[1].done({status:200,statusText:'OK',finalUrl:'https://api.example.com/v2',response:'current',responseHeaders:'Content-Type: text/plain'});
 await new Promise(setImmediate);
 assert.equal(xhr.responseText,'current');
 assert.equal(events.filter(e=>e==='load').length,1);
 assert.equal(events.filter(e=>e==='loadend').length,doneEvents+1);
 // Calling open() on an active XHR must cancel the old request, as native XHR does.
 const transition=new Bridge();
 transition.open('GET','https://fixture.test/local');
 transition.send();
 assert.equal(transition.nativeSent,true);
 transition.open('GET','https://api.example.com/remote');
 assert.equal(transition.nativeAborted,true,'Local-to-proxy XHR transition must abort the old local request');
 const reuse=new Bridge();let reusedLoads=0;
 reuse.addEventListener('load',()=>reusedLoads++);
 reuse.open('GET','https://api.example.com/old');reuse.send();
 const old=controls.at(-1);
 reuse.open('GET','https://api.example.com/new');reuse.send();
 const newest=controls.at(-1);
 assert.ok(old.cancelled,'Reopening an active XHR must cancel its prior request');
 old.done({status:200,statusText:'OK',finalUrl:'https://api.example.com/old',response:'old-data',responseHeaders:''});
 await new Promise(setImmediate);
 assert.equal(reusedLoads,0,'The previous response must not update the reopened XHR');
 newest.done({status:200,statusText:'OK',finalUrl:'https://api.example.com/new',response:'new-data',responseHeaders:''});
 await new Promise(setImmediate);
 assert.equal(reuse.responseText,'new-data');
 assert.equal(reusedLoads,1);
 console.log(JSON.stringify({abort_propagates:true,no_stale_load_after_abort:true,reopen_completes:true}));
})().catch(error=>{console.error(error);process.exitCode=1});
