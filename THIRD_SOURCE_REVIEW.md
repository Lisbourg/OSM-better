# Third Source Review — Directions Visual Parity and Function Safety

## Source comparison

The supplied official OpenStreetMap HTML and downloaded original Directions
module were used as the reference. The integration preserves the native
Directions forms, route-result controls, both close buttons, SVG symbol
nodes and route panel structure. `OFFICIAL_FRONTEND_DOM_BASELINE.json` is a
portable structured reference generated from the official HTML. The test
`TEST_OFFICIAL_FRONTEND_DOM.py` compares the actual element hierarchy and
selected stable attributes for 12 selectors (including repeated forms).
This does not claim that every dynamic panel or the live page pixels are
identical to the public website.

A further global ID inventory of the user-supplied captured official HTML
finds 122 IDs. The sanitized deployment HTML contains 103 of those IDs,
with **no newly invented IDs**. The 19 absent IDs belong to the captured
Better OSM Layers/GPX/Notes panels that should instead be created at runtime;
see `dynamic_capture_only_ids` in the packaged DOM baseline. Thus the stable
static controls preserve their IDs, but this is **not** evidence that the
actual dynamically generated Better OSM panels are pixel identical.

The native Directions controller uses one Leaflet main route polyline with
`color: '#03f'`, `opacity: 0.3`, `weight: 10`. An optional hovered-step line
is yellow `'#ff0'`, `opacity: 0.5`, `weight: 12`. Native endpoint pins use
OSM.getMarker with `icon: 'start'` / `icon: 'destination'` and the original
CSS `--marker-green: #9cef11` / `--marker-red: #f6110a` respectively. These
values match the provided official resources. The independent Chromium test
executes the genuine OSM marker factory and native Directions route module,
then inspects the actual Leaflet line SVG and marker icon HTML. It uses a
stubbed routing response to isolate client rendering.

The prior navigation fixture replaced `OSM.getMarker` with an artificial
icon. That was not an adequate marker color test. The new visual test does
not use that substitution.

## Confirmed defects corrected

1. **Canvas and incomplete SVG renderer initialization:** Better OSM assumed
   `line._path.classList` and `layer._path.classList` always existed. Canvas
   layers and not-yet-mounted SVG layers need not expose `_path`. The
   integrated script now uses optional chaining on these accesses; the
   original SVG stroke class is still attached when a path exists.
2. **Map utilities during incomplete initialization:** Several helper
   functions called `.match()` on an absent Edit URL; getMapCenter/getZoom
   also indexed unmatched regex groups. Missing URL, map and hash are now
   checked before use. The original userscript file is retained unchanged.
3. **Decimal hash coordinate replacement:** The no-map fallback for `panTo`
   and `fitBoundsWithPadding` matched only integral coordinate strings, so
   an ordinary `#map=18/29.602/106.561&layers=M` stayed at the old
   coordinates or discarded the current layer parameter. The fallback now
   replaces decimal hash coordinates and preserves the other hash fields.
4. **Incomplete Export fallback DOM:** `getMapBounds()` accessed missing
   sidebar controls and Export inputs without checking them. It could throw
   before the normal map was initialized and clicked every sidebar close
   button even when unrelated content was open. The optional fallback now
   requires its controls, validates numeric bounds, returns null when
   unavailable and restores previous sidebar inline styles. It no longer
   automatically clicks all sidebar close controls.

## Function and dependency review

The eleven authentic downloaded OSM route modules remain present, including
native Directions, Search and Query Features; no generic placeholders are
reintroduced. The original Better OSM 1.7.9 userscript is retained. The
integrated script has the four narrow fixes above, which are tested by
`TEST_BETTER_OSM_NULL_GUARDS.js` and `TEST_BETTER_OSM_EXPORT_BOUNDS.js`.
Earlier checks for configurable deployment base paths, mutually exclusive
custom base layers, XHR reentrancy, PHP proxy URL validation and native
navigation state continue to run through `RUN_FINAL_CHECKS.py`.

## Critical limits

- Real MapLibre WebGL rendering with live provider style, tiles, sprites and
  glyphs has **not** been verified. Previous production exceptions involving
  `undefined.type` and `updateTransitions` cannot be ruled out by this test.
- Chromium test renders the native Directions controller and genuine Leaflet
  SVGs but does **not** execute every Better OSM enhancement on the full
  production site or prove another runtime script cannot add a visual
  overlay later.
- Licensed/paid third-party tiles may return HTTP 403 without valid keys.
  A correct server-side Referer does not grant authorization.
- The provided official screenshot contains populated network route data;
  this bundle sanitizes captured account/private data and must populate
  route steps dynamically. Exact byte or pixel equality is therefore not
  implied.
- Local PHP lacks cURL, so real upstream search/feature/route requests and
  production runtime CE-freedom remain outside this validation.
