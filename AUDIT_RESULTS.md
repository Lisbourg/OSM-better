# Current Integration Audit

## Installed upstream assets

- All eleven full OSM index route modules are included. No generic route
  placeholders are shipped as these module filenames.
- The official mapbox-gl-rtl-text asset is included.
- `OFFICIAL_ASSET_CHECK.json` lists reference and packaged SHA-256 for all
  twelve assets. Ten files match the supplied official downloads byte for byte.
  The native Directions and index entry files contain integration adjustments.
- The original Better OSM 1.7.9 userscript is present; the merged Better OSM
  userscript is loaded once by the browser. The separate custom Directions
  and Search/Query controllers are not included in the HTML script chain.

## Frontend audit

- The supplied official HTML is a browser snapshot containing active dynamic
  pane content and a user's account state; those captured dynamic panes are
  not pre-injected into the deployed main page. Native controls create them
  at runtime. The distributed page uses logged-out Log in / Sign up controls.
- Native search box, original From/To form and Directions table, top nav,
  sidebar close controls, map controls, modal frames and DOM IDs remain.
- No new standalone toolbar or redesigned frontend was introduced.
- The four CSS assets retain the supplied CSS style declarations; two are
  byte-for-byte official and two rewrite `/assets/` URLs to CSS-relative
  resource paths for deployable nested directories.
- This audit compares supplied resources and static/fixture behavior; it is
  not a pixel-level comparison with the live official website.

## Manual source corrections in this package

1. The native panel exit now calls the official SPA router before falling
   back to history replacement. It keeps the current map hash when leaving
   Directions or query-related sidebars, preventing an unintended camera reset.
2. An initial visit without a map hash no longer gets a synthetic `map=3/0/0`
   hash. An explicit map hash with no layer selection still gets Standard
   (`layers=M`) as configured behavior.
3. Removed one intermediate `.js.repaired-backup` from the deployable route
   module directory. The authentic RTL plugin remains.

## Executed evidence

- `VERIFY_LOCAL.py`: checks all JS/PHP syntax, all referenced route modules,
  login-free HTML, scripts/CSS references and basic native structure.
- `TEST_CONFIGURED_BASE.py`: PHP HTTP tests at `/`, `/atlas/`, and
  `/any/deep/path/`, including eight deep-link paths and three Turbo frames.
- `TEST_NATIVE_DIRECTIONS.py`: isolated Chromium DOM event test using actual
  OSM app and Directions JS, and stubbed map responses. It exercises initial
  Directions, the lower result X, endpoint drag, and the upper form X.
  Its `about:blank` fixture emits startup import/Turbo warnings that do
  not validate the live production page and must not be reported as zero
  browser exceptions.
- `TEST_NATIVE_LAYERS.js`: evaluates the actual Better OSM custom-base
  switching function with a small Leaflet API fixture, proving mutual
  exclusivity on that path only.

## Not fully verified

- No real live browser test against production PHP/cURL and real third-party
  tile, routing, search and feature-query hosts was possible.
- Previous production captures include MapLibre `undefined.type` and
  `updateTransitions` errors. There is no complete production proof these
  are resolved by the style and language compatibility corrections.
- Providers that return 403 or require API keys cannot be made operational
  merely by sending a different Referer; legitimate credentials are needed.
- The archived page contains only one complete locale bundle. Native full
  multilingual translations and every upstream authenticated workflow are
  not confirmed.

## Delivery decision

All verified local checks were run on this package. Do not treat this audit
as a guarantee of zero runtime errors on a different server. Deploy on a
staging URL and exercise real network requests before replacing production.

## Independent source re-review corrections

The earlier README listed four tests that were missing from that ZIP. Two tests
only targeted controllers already removed from the page, and were omitted as
obsolete. Active tests were restored or replaced by tests against the deployed
native modules and XMLHttpRequest adapter.

- The native Directions controller used a shared mutable AbortController: a late
  result or `.finally` from an earlier calculation could overwrite a newer
  calculation. A monotonic request token now guards result writes, error writes,
  loader state and cancellation. Unload aborts the current request.
- The native Query controller accepted an empty latitude/longitude as (0,0),
  could crash when sorting results without bounds, and did not abort outstanding
  fetches on unload. These paths are now guarded and tested.
- The proxy XMLHttpRequest bridge chained `.then()` and lost the abort method
  from the underlying request, so remote XHR abort did not cancel work and a
  stale response could emit load after abort. The underlying handle is now
  retained and late callbacks ignored.
- The native MapLibre language helper could read a missing style / mutate a
  non-MapTiler style. It now checks style readiness and provider metadata.
- Native Search and Query sidebar Promise failures are caught rather than
  producing unhandled rejection warnings.

Reference OpenStreetMap HTML is a saved user session: 19 dynamic element IDs
belonging to a pre-opened layers pane and logged-in user data are not embedded
in the deployed static index. Original OSM / Better OSM code re-creates panels
at runtime. The static index is therefore NOT byte-identical to the saved
official HTML, even though the original visible controls and CSS design remain.

Verification evidence: `TEST_NATIVE_DIRECTIONS_RACE.py` exercises both close
buttons and stale route responses with real OSM controller code and mocked
network, `TEST_XHR_ABORT.js` tests the actual XHR adapter, and
`TEST_NATIVE_MODULE_REGRESSION.js` tests native Query and language guards.
None is a substitute for a real production MapLibre render and upstream
provider request. A fully zero-exception production guarantee is not possible
without those end-to-end checks.
