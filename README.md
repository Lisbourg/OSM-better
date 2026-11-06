# OpenStreetMap and Better OSM 1.7.9 PHP integration

The project is an integration of the supplied OpenStreetMap page and Better
OSM. It is not a redesigned map front end.

## Purpose

- Preserve the supplied OSM HTML, CSS and native controls.
- Use Standard as the default base layer; custom Better OSM base layers are
  mutually exclusive with it.
- Forward automatic upstream map, search, routing and feature-data requests
  through the PHP server. PHP determines the upstream Referer from the final
  target origin. A provider may still require its own valid API key.
- Preserve the native Directions table and draggable endpoint pins without
  automatic fitBounds, zoom or map recentering on endpoint drag.
- Close the Directions results independently of the whole From/To form.
- Remove captured user authentication data from the distributed page.

## Required server configuration

- Apache with mod_rewrite and mod_headers, or equivalent URL routing.
- PHP 8.1+ with the cURL extension enabled.
- HTTPS and a web server whose application directory matches app-base.json.
- Set app-base.json index_path to the deployed index.html URL path, for example /maps/index.html or /index.html.
- Configure operator-owned map provider API keys in proxy-config.php when
  using providers that require credentials.

## Deployment

1. Back up the existing application directory.
2. Extract all files into the configured application directory.
3. Check <configured-base>/__health for the configured base path and curl_enabled=true.
4. Force-refresh the browser so the new JavaScript and module URL versions load.
5. Verify route entry, both close buttons, marker drag, Search, Query Features,
   Select Language, Share, custom layers and server proxy behavior on the
   actual production host.

## Validation

Read AUDIT_RESULTS.md first. The review includes confirmed defects,
source corrections, and unresolved implementation limitations. Parsing and
fixture tests do not certify upstream services or production MapLibre rendering.

Reproducible local checks (all included in this ZIP):
- python VERIFY_LOCAL.py
- python TEST_CONFIGURED_BASE.py
- python TEST_NATIVE_DIRECTIONS_RACE.py (requires Playwright + Chromium)
- node TEST_NATIVE_MODULE_REGRESSION.js
- node TEST_XHR_ABORT.js
- node DEEP_MAPLIBRE_GUARD_TEST.js
- node TEST_NATIVE_LAYERS.js
- python DEEP_PHP_STYLE_TEST.py

The native Directions Chromium fixture intentionally loads the original
application bundle without its full real network/Turbo bootstrap. Its known
fixture-only startup warnings do not prove production runtime correctness.
Read FINAL_SOURCE_REVIEW.md for confirmed failures, patches and limitations.

## Configurable public path

`app-base.json` is the one editable deployment-path source. For example,
`{"index_path":"/atlas/index.html"}` deploys the application at `/atlas/`;
`{"index_path":"/index.html"}` deploys it at the site root.

A PHP-generated non-visual base element is included for direct SPA deep-link
loads before relative JS and CSS requests. Original map controls are unchanged.
The native OSM router normalizes paths using the configured base rather
than a literal `/osm` prefix.

This package preserves the Better OSM 1.7.9 userscript integration and the
complete authentic downloaded OSM dynamic route modules. Removing duplicate
custom route controllers does not disable the Better OSM main script.

## Scope and limitations

The authentic OSM route modules and RTL module are present. The two modified
native module files are recorded with SHA-256 values in OFFICIAL_ASSET_CHECK.json.
The original controls, screen styling and Better OSM 1.7.9 script remain.
There is no captured account login in index.html.

This is not a production certificate. A full production browser with working
PHP cURL and valid API keys (where a provider requires one) is still needed to
verify MapLibre rendering and every network-backed Better OSM feature.

## Second source review

`SECOND_SOURCE_REVIEW.md` records additional confirmed runtime and proxy defects
found after the previous audit. Run `python RUN_FINAL_CHECKS.py` for the full
portable suite. The new regression tests are:

- `node TEST_XHR_REOPEN.js`
- `node TEST_GM_CALLBACK_ERRORS.js`
- `python TEST_STYLE_OBJECT_SHAPES.py`
- `python TEST_PROXY_TARGET_GUARD.py`

`FRONTEND_REFERENCE_HASHES.json` contains the independently checked reference
hashes, so the final validation script needs no external files or hardcoded
paths to the author's machine. The original Better OSM source, downloaded OSM
modules, and visible CSS layout remain unchanged.

## Third source review: native directions visual and runtime functions

`THIRD_SOURCE_REVIEW.md` documents the review of official route colors,
endpoint SVG symbols and UI markup against the supplied official capture.
It also documents fixes to Better OSM's Canvas-safe SVG access, missing
Edit/Export controls, and decimal map hash fallback. The pristine original
Better OSM userscript is unchanged. The integrated merged script has the
narrowly scoped runtime safeguards documented in that report.

Additional included tests:
- `python TEST_OFFICIAL_FRONTEND_DOM.py` (native routing DOM against the bundled official reference)
- `python TEST_ROUTE_VISUAL_PARITY.py` (Chromium, real Leaflet, native OSM markers and line options)
- `node TEST_BETTER_OSM_NULL_GUARDS.js`
- `node TEST_BETTER_OSM_EXPORT_BOUNDS.js`

Only the stable routing DOM subset is asserted equivalent to the captured
official HTML. Authentication, saved network data and dynamic populated
routes are intentionally excluded. A production pixel-by-pixel comparison
and proof of zero runtime faults require a full provider-enabled environment.

## Upload readiness review

Read UPLOAD_READINESS_AUDIT.md before uploading this archive.
The latest review includes native Query Features sidebar layout, nonvisual
deployment metadata URL corrections, Photon Referer, and removal of cached
empty Notes browser snapshots. Run `python RUN_FINAL_CHECKS.py` after extraction.
The default app-base.json assumes deployment at /osm/.

## 2026-11-06 official pane & localization integration

The operator supplied the official captures (download.zip: 19 i18n bundles +
the official /panes/languages, /note/new, /directions and /query pages). They
are now integrated verbatim:

- `assets/i18n/` ships the official `en` bundle plus 19 official locale bundles
  (ar de es fa fr hi id it ja ko nl pl pt ru tr uk vi zh-CN zh-TW). The router
  activates any bundle dropped into that directory.
- `panes/languages/index.html` is the official 113-language list (native
  names, official `language_search` filter contract).
- `panes/note-new.html`, `panes/directions/index.html`,
  `panes/query/index.html` are the verbatim official server fragments
  (extracted from the captures exactly as Turbo extracts them).
- `router.php` re-anchors the official root-absolute links inside panes
  (`/?locale=xx`, `/login`, `/user/new`) to the configured app base; nothing
  is hardcoded.
- Locale selection follows the official bundle contract: the dialog writes
  the `_osm_locale` cookie (`OSM.cookies.set`) and reloads; `?locale=xx` is
  also honored and writes the same cookie. `better-osm-runtime.js` relaxes
  the bundle's `Secure` cookie attribute on plain-HTTP deployments only
  (HTTPS deployments keep official behavior).
- The home page ships the official empty `sidebar_content_frame`; every pane
  (search results, directions, query, note) loads through Turbo exactly like
  the official site, and the edit dropdown/context menu are the official
  static skeletons rebuilt at runtime by the merged script.

Still external by design: the optional BetterOSM custom-editor database
(raw.githubusercontent.com, fetched through the proxy, cached 6h) and the
legend pane (missing from the archive; served locally once
`panes/legend/index.html` is provided, proxied upstream otherwise).
