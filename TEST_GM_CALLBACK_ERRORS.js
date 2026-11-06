'use strict';
const fs=require('fs');
const assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/better-osm-runtime.js','utf8');
const first=source.indexOf('  function sendServerRequest(details) {');
const last=source.indexOf('  function serverFetch(url, init = {})',first);
assert.ok(first>=0 && last>first);
const factory=new Function('nativeFetch','makeProxyURL','Headers','AbortController','Response','Blob','TextDecoder','setTimeout','clearTimeout',source.slice(first,last)+'\nreturn sendServerRequest;');
let nextMode='reject';
function fakeFetch(url,opts){
  if(nextMode==='reject')return Promise.reject(new Error('network disconnected'));
  return new Promise((resolve,reject)=>{
    opts.signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true});
  });
}
const gm=factory(fakeFetch, url=>'/test/__proxy?url='+encodeURIComponent(url),Headers,AbortController,Response,Blob,TextDecoder,setTimeout,clearTimeout);
const failures=[];
process.on('unhandledRejection',err=>{failures.push(String(err))});
(async()=>{
  let errors=0,aborts=0;
  // Legacy callback-style GM calls do not await the returned request object.
  gm({url:'https://example.com/resource',onerror(){errors++}});
  await new Promise(resolve=>setTimeout(resolve,15));
  assert.equal(errors,1,'The callback must receive transport errors');
  assert.deepEqual(failures,[],'A handled callback failure must not leak an unhandled promise rejection');
  nextMode='abort';
  const req=gm({url:'https://example.com/resource',onabort(){aborts++},onerror(){errors++}});
  req.abort();
  await new Promise(resolve=>setTimeout(resolve,15));
  assert.equal(aborts,1,'Aborted GM request must notify onabort');
  assert.equal(errors,1,'An abort must not also notify onerror');
  assert.deepEqual(failures,[]);
  console.log(JSON.stringify({callback_error:true,no_unhandled_promise:true,abort_callback:true}));
})().catch(err=>{console.error(err);process.exitCode=1});
