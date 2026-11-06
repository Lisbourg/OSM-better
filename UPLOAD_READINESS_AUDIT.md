# Final Upload Readiness Review

The archive was re-extracted from the previously delivered ZIP and reviewed again.
This review does not claim proof of production runtime perfection.

## Confirmed changes

1. Native Query Features route: the left sidebar is treated as a real results pane rather than the overlay home search, matching the route's expected panel state. (The former better-osm-panel-guard.js helper was later removed entirely; this route state is handled by the native application code and the merged Better OSM runtime.)
2. Nonvisual Windows tile/manifest metadata: two saved root-relative /assets URLs are now relative to the configured application base, so they resolve correctly after direct deep links and in arbitrary deployment folders. Visual HTML controls and CSS declarations are not changed.
3. The local Photon search client now sets its upstream Referer to the target service origin. This is not a substitute for provider authorization.
4. Seven unused, saved empty Notes API snapshots were removed. Live notes must be fetched from the server at request time, not from a captured browser session.
5. The former panel guard script is fully removed (file, index.html script tag and loader references). No stale script tag points at a deleted file.

## Executable verification

RUN_FINAL_CHECKS.py executes the previous native Directions, drag, close-button, native marker SVG/color, query, language helper, mutual-exclusion base-layer, XHR, GM, MapLibre guard, PHP style and redirect tests, plus the newly added query layout and deployment metadata tests. It also verifies valid syntax, referenced dynamic routes, sanitized auth state and official asset hashes.

## Unverified production prerequisites

- The test environment cannot navigate a browser to any local HTTP/file URL (browser reports ERR_BLOCKED_BY_ADMINISTRATOR).
- PHP cURL is not installed here. Therefore the real upstream network requests and browser WebGL render cannot be end-to-end verified.
- Past production logs included MapLibre placement/render exceptions. Fixture tests do not establish that every real provider style is valid.
- An invalid third-party API key or a 403 authorization response still requires valid service-provider credentials.
- The supplied official archive is a saved page, not a complete signed frontend source tree; the package removes captured login information and preloaded dynamic panes. It is not literally byte- or pixel-identical to the live website.
- Complete independent translation bundles for every locale are not present. The language selector's locally available labels should not be described as full translations.

## Upload instructions

Unpack the ZIP directly into the directory served for your app-base.json index_path.
Its default is /osm/index.html, requiring the files to be served from /osm/.
Do not upload the ZIP one directory above/below that path without updating app-base.json.
Apache mod_rewrite, mod_headers, PHP 8.1+ and PHP cURL are required.
Verify /<your-base>/__health reports curl_enabled=true before relying on the proxy.
Preserve a server backup and perform live end-to-end checks before replacing your only working installation.
