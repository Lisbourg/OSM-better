# Manual source recheck - OpenStreetMap / Better OSM integration

## Basis and scope

I compared the actual contents of `OSM_BetterOSM_SOURCE_REVIEWED_REPAIR.zip`
with the unpacked code before editing. I read the native HTML structure,
Directions bridge, sidebar guard, Search / Query handler, dynamic route modules,
PHP front controller, proxy rules, and MapLibre style rewrite function.
The original CSS and visual HTML elements were not edited in this revision.

A JavaScript or PHP parser cannot establish whether a function exists at
runtime or whether a remote service returns the required data. The findings
below are based on reviewing specific execution paths. Test results are a
secondary check, not the basis for declaring any product complete.

## Defects identified and corrected here

1. `better-osm-directions.js`, Reverse Directions click handler: it swapped
   the two text fields without recalculating the route. The displayed line and
   draggable endpoints could therefore disagree with the inputs. It also
   called `preventDefault()` without suppressing the captured OSM bundle's
   delegated click handler, so the swap could be applied a second time.
   The corrected handler stops duplicate processing and recalculates the route
   (or updates partial From/To URL state when a field is missing).
2. `better-osm-search-query.js`: previous request IDs prevented stale results
   being displayed but did not cancel in-flight Search / Query HTTP requests.
   The new shared AbortController cancels a superseded or closed request;
   Query Features also cancels the current upstream attempt when a later
   request wins. A response arriving after close is still discarded.
3. `assets/index_modules/query-*.js`: calling `Number` on a missing URL
   parameter returned zero. A Query route with absent `lat` / `lon` was
   therefore misinterpreted as valid coordinates (0, 0). Missing parameters
   now remain invalid and do not trigger an Equator query.
4. `better-osm-search-query.js`, context coordinates: a missing map centre
   was not clearly distinguished from real zero coordinates. Fallback now
   requires a centre object and checks latitude/longitude limits.

## Manual inspection findings that remain unresolved

1. The archived `assets/index_modules` contains multiple short compatibility
   adapters, not full official OSM page modules. Their presence passes a
   dependency-file check but does not provide the official Notes, History,
   Export, Element, and Changeset workflows. Treating 11 files as proof of
   complete application routing was incorrect.
2. `proxy.php::proxy_rewrite_map_style_json()` edits style expressions using
   inferred rules. In particular, it replaces some empty `match` labels with
   a magic string and wraps other operands as literal arrays. Such type
   changes require real style-level validation; they may be implicated in
   the recurring `undefined.type` and `updateTransitions` renderer errors.
   No safe general correction was established by static inspection alone.
3. `better-osm-panel-guard.js` changes only a subset of language labels.
   English and six hard-coded menu selections do not amount to full native
   localization. Complete locale catalogs and server responses are missing.
4. `proxy-config.php` has empty provider keys for MapTiler and Tracestrack.
   A `403` from these services cannot be fixed by changing Referer headers.
5. `better-osm-panel-guard.js` supports posting public Notes through the
   proxy, but the static archive has no verified official authenticated
   note-submission flow. The endpoint may reject anonymous or invalid input.
6. The custom XHR/fetch monkey-patches and native OSM SPA router operate on
   the same page. Their interaction with every Turbo navigation is not
   verified by a full production browser session.
7. The MapLibre RTL worker bundled in this archive is a small compatibility
   placeholder, not proven equivalent to the official RTL text plugin.
8. Production cURL, third-party map data, provider authentication, feature
   queries and full MapLibre rendering have not been end-to-end verified.

## Explicitly unchanged

- HTML/DOM structure and native OSM controls, except for updating the two
  modified script cache query parameters.
- All CSS and icon files.
- Standard default map layer, no route `fitBounds`, and the distinct
  upper-From/To and lower-Directions close-button model.
- Server-side Referer derivation and CSP rules. (The former PHP outgoing
  host allowlist was later removed on request: the proxy now forwards to any
  HTTP(S) host, so custom map layers cannot 403 on a missing list entry.)

## Reproducible local evidence (not production acceptance)

- `MANUAL_RECHECK_TEST.py`: route, reverse, close-button, drag and camera
  calls in a real Chromium DOM with mocked map/route data.
- `DEEP_REGRESSION_TEST.js`: Query fallback, same-origin proxy URL, stale-result
  handling and AbortSignal cancellation with simulated responses.
- `DEEP_MAPLIBRE_GUARD_TEST.js`: the narrowly scoped language guard only.
- `DEEP_PHP_STYLE_TEST.py`: a synthetic PHP style JSON fixture only.
- `MANUAL_RECHECK_BROWSER_RESULTS.json`, `DEEP_QUERY_REGRESSION.json`, and
  `MANUAL_RECHECK_VALIDATION.json`: actual local execution results.

## Delivery judgment

The corrections are useful, but this archive is NOT a production-complete,
zero-error implementation. The next blockers are reconstructing or integrating
missing native OSM route implementations, checking MapLibre styles against a
real renderer, and executing live PHP/cURL integration tests on the target
server. Do not replace the only working deployment without a backup.
