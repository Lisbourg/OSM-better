"use strict";
/* Real function execution with Canvas-style Leaflet paths and missing Edit links. */
const fs = require("fs");
const vm = require("vm");
const assert = require("assert");
const path = require("path");
const source = fs.readFileSync(path.join(__dirname, "better-osm-merged.js"), "utf8");
function section(start, end) {
  const begin = source.indexOf(start);
  const finish = source.indexOf(end, begin + start.length);
  assert(begin >= 0 && finish > begin, `Missing source section: ${start}`);
  return source.slice(begin, finish);
}
const created = [];
const fakeWindow = { L: {
  latLng: (...coords) => coords,
  polyline: () => {
    const line = {
      _path: undefined,
      addTo() { created.push(this); return this; },
      bindPopup() { return this; },
      on() { return this; }
    };
    return line;
  }
}};
const sandbox = {
  layers: {customObjects: []},
  cleanCustomObjects() { sandbox.layers.customObjects = []; },
  getWindow: () => fakeWindow,
  getMap: () => ({}),
  intoPage: x => x,
  intoPageWithFun: x => x,
  console,
};
vm.createContext(sandbox);
vm.runInContext(section("function showWay(", "function showWays(") + "\n" + section("function displayWay(", "function showNodeMarker("), sandbox);
vm.runInContext('showWay([[1,2],[3,4]], "red", false, true)', sandbox);
vm.runInContext('displayWay([[1,2],[3,4]], false, "red", 4, null, "customObjects", null, null, true)', sandbox);
assert.equal(created.length, 2, "Both Better OSM lines should render with Canvas-style layers");
// Native Leaflet SVG renderer still receives its CSS stroke class.
let classAdded = false;
fakeWindow.L.polyline = () => ({
  _path: {classList: {add(name) { classAdded = classAdded || name === "stroke-polyline"; }}},
  addTo() { return this; }, bindPopup() {return this;}, on() {return this;}
});
vm.runInContext('showWay([[1,2]], "red", false, true)', sandbox);
assert(classAdded, 'SVG line stroke should still be applied');
// Missing Edit menu should never throw while the map initializes.
const view = {
  location: {hash: "#map=18/29.6025/106.5615"},
  document: {querySelector: () => null},
  getMap: () => null,
  console: {warn() {}, log() {}},
  min: Math.min,
  intoPage: x => x,
  intoPageWithFun: x => x
};
vm.createContext(view);
vm.runInContext(section("function getMapCenter()", "async function getMapBounds()") + "\n" + section("function getZoom()", "function setAttributionPrefix("), view);
const center = vm.runInContext('getMapCenter()', view);
assert.equal(Number(center.lat), 29.6025);
assert.equal(Number(center.lng), 106.5615);
assert.equal(vm.runInContext('getZoom()', view), 18);
view.location.hash = "";
assert.equal(vm.runInContext('getZoom()', view), null);
assert.equal(vm.runInContext('getMapCenter()', view), null);
vm.runInContext('setZoom(14)', view); // Empty map/hash is an allowed no-op.
vm.runInContext(section("function panTo(", "function panInside(") + "\n" + section("function fitBoundsWithPadding(", "const earthRadius"), view);
vm.runInContext('panTo(29.7, 106.8, 17)', view);
assert.equal(view.location.hash, 'map=17/29.7/106.8', 'Fallback pan should use the REQUESTED center');
view.location.hash = '#map=18/29.602/106.561&layers=M';
vm.runInContext('panTo(29.7,106.8,17)',view);
assert.equal(view.location.hash.replace(/^#/,''), 'map=19/29.7/106.8&layers=M', 'Decimal map coordinates must be replaced with the new requested center');
view.location.hash = "";
vm.runInContext('fitBoundsWithPadding([[29.6,106.5],[29.8,106.9]], 0, 19)',view);
assert.equal(view.location.hash, 'map=19/29.700000000000003/106.7', 'Bounds fallback should use the requested bounds center');
view.location.hash = '#map=18/29.602/106.561&layers=M';
vm.runInContext('fitBoundsWithPadding([[29.6,106.5],[29.8,106.9]], 0, 19)',view);
assert.equal(view.location.hash.replace(/^#/,''), 'map=19/29.700000000000003/106.7&layers=M', 'Bounds fallback must preserve hash layers and handle decimal map coordinates');

assert(source.includes('const curURL = editorsListUl.querySelector("li a")?.href\n    if (!curURL) return'), 'Editor link guard is missing');
for (const raw of ['layer._path.classList.', 'line._path.classList.']) assert(!source.includes(raw), `Unguarded SVG path: ${raw}`);
console.log(JSON.stringify({ok:true,canvasPaths:2,svgClassPreserved:classAdded,missingEditMenu:true,zoomFallback:true,panFallback:true,boundsFallback:true}));
