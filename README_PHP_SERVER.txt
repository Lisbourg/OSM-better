OpenStreetMap + Better OSM 1.7.9 | Server-proxy and native-base repair

Source and layout
- Reference source: the user-supplied OpenStreetMap.zip, not a redesigned site.
- The native OSM CSS, controls, headings, From/To form, directions table,
  map control placement, and popup styles are retained.
- The saved browser DOM contained previously opened layers/share panes. These
  stale copies were removed. The original OSM JS now creates each pane once
  and loads its fragment into that pane when opened.
- The login-specific capture was removed: OAuth token, user ID, CSRF value,
  profile menu, cached username, and the account avatar image are not bundled.
  Public Log in and Sign up links appear in the original header position.

Proxy
- Automatic remote map resources, styles, sprite JSON/PNG, glyph PBF, tiles,
  routing, search, geocoding, and other HTTP(S) data are routed through the
  same-origin PHP proxy; direct remote requests are blocked by the deployed
  Content-Security-Policy when mod_headers is enabled.
- The proxy has no host allowlist: it forwards to any HTTP(S) host on any
  port, so custom map layers can never fail with a 403 for a missing entry.
- The upstream Referer header defaults to https://www.openstreetmap.org/ on
  every redirect hop (the origin-only contract of a www.openstreetmap.org tab
  under Referrer-Policy: strict-origin-when-cross-origin). Per-host
  exceptions live in referer_overrides, and a custom map layer may declare
  its own Referer through the referer= query parameter.
- Browser-provided Host/SNI/connect target overrides and cookie sessions are
  disabled by default.
- MapLibre's final resource requests are rewritten through transformRequest.
  Sprite BASE and glyph URL templates are kept absolute within style JSON
  until MapLibre has applied @2x suffixes and font-range substitutions.
- A provider requiring an API key still requires the operator's legitimate key.
  Changing Referer does not bypass provider authentication or use restrictions.
- User-initiated external navigation links are not server-proxied. Proxying
  third-party login pages or arbitrary clicks would break their navigation.

Layers and panels
- User-selected raster imagery is a native, mutually exclusive Leaflet base
  layer. Standard/CyclOSM/vector base layers are removed before custom imagery
  is added. Selecting an existing native base removes the custom base.
- The chosen custom base appears as one radio option in the existing native
  Map Layers picker. No separate overlay is used for that selection.
- Clicking an already selected native base repeatedly is ignored, without
  closing the right pane or causing repeated tile loads.
- Captured duplicate panel content is not loaded at boot.
- A same-origin share pane fragment is included for the existing OSM share UI.
- The home route closes search/note/sidebar views without fetching the whole
  homepage as a Turbo fragment. The official route result template stays intact.
- From/To uses draggable official green/red pins. No route recalculation or marker drag changes map zoom or pan.

Deployment
- Requires Apache .htaccess + mod_rewrite + mod_headers, PHP 8.1+, and
  php-curl. External map resources may be HTTP or HTTPS.
- Deploy the entire archive into the site directory, replacing previous files.
- Set app-base.json index_path to the full public index.html path, e.g.
  /osm/index.html. Do not change the original page layout.
- Clear any old site cache or service worker, then force reload.
- Add only authorized keys to proxy-config.php for paid map services.
- If an OAuth token captured in earlier distributed ZIPs was valid, revoke it
  at the identity provider; stripping this package does not revoke old tokens.

Verification and limitations
- All packaged JS, PHP, and inline JS is syntax-checked.
- Tested native base switching across OpenRailwayMap, ESRI, and Standard,
  including repeated Standard clicks, with a mock Leaflet map.
- Tested native directions markup, two draggable pins, drag recalculation,
  and zoom stability with a mocked network/Leaflet response.
- Tested home-route sidebar close and preservation of route HTML.
- Tested PHP sprite/glyph/tile-JSON rewriting without a live upstream.
- Verified the archive has no CJK source text or filename and no captured
  user token or account menu.
- Real provider HTTP requests and production-browser rendering remain
  unverified: the local PHP installation lacks php-curl and automated Chromium
  navigation is blocked by the execution environment. Review TEST_RESULTS.json.

2026-11-05 integration repair
- Native Standard (M) base layer is selected when URL lacks an explicit layer.
- Route recalculation never calls fitBounds or setView; pins remain draggable.
- GraphHopper is not default because the packaged server has no GraphHopper API credentials.
- One Better OSM layer settings control is generated at runtime, not pre-captured.
- The local language Turbo frame returns expected frame ID.
- The local Add a Note route supplies a dismissible note editor and submits to the official OSM Notes API through the PHP proxy when the API and server cURL are available. Live note creation has NOT been validated and may be rejected by upstream policy.
- A delegated closing handler covers left sidebar close controls and local note Cancel; native map-side panel closing remains with the official OSM controller to keep its state synchronized.
- Not all commercially licensed sources can be guaranteed to work without provider API keys.

Final local repair checks (2026-11-05)
- Shared JS test fixtures exercise native raster base switching, one settings gear, and repeated Standard clicks without closing the pane.
- From/To fixtures exercise original direction result DOM, two draggable pins, reroute on drag, and zero fitBounds calls.
- Local panels return Turbo frames with matching IDs; Share and Add a Note are not verified against the production server.
- MapLibre runtime errors reported on remote provider failures cannot be declared resolved without live end-to-end browser testing; invalid API keys and missing style resources remain deployment concerns.
