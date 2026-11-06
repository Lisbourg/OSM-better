# Second Independent Source Review

This review starts from the previously delivered `OSM_BETTER_OSM_REAUDITED_NATIVE_MODULES.zip`, not from a trusted preexisting validation report. The following defects were reproduced with tests before changes and then rechecked after source edits.

## Confirmed defects fixed

1. **Proxied XMLHttpRequest reuse race** (`better-osm-runtime.js`, `NativeBridgeXHR.open/send`). The previous `open()` did not cancel an active remote request. Because callback closures reused the same mutable state, a previous response could dispatch a `load` for a newer request. The fix cancels the previous transport and compares a monotonically increasing request generation before updating XHR fields. A pending native same-origin XHR is also cancelled when switching its object to a proxy request. Reproduction: `node TEST_XHR_REOPEN.js` failed before the fix; it now passes.
2. **GM callback-style error could produce unhandled promise rejections** (`better-osm-runtime.js`, `sendServerRequest`). Legacy GM_xmlhttpRequest consumers frequently only supply `onerror`/`onabort` callbacks and do not await the return. The old implementation rethrew a rejected promise even after invoking the callback. The returned promise now has a non-propagating rejection observer, so awaiters may still catch the original rejection. User aborts now invoke `onabort` rather than `onerror`. Check: `node TEST_GM_CALLBACK_ERRORS.js`.
3. **MapLibre style JSON object shape corruption** (`proxy.php`, `proxy_rewrite_map_style_json`). The old implementation used `json_decode(..., true)` and re-encoded all empty `{}` objects as `[]`. This changes the type of metadata, layer layout and paint properties and can invalidate map styles. The rewrite now preserves original JSON object/list shapes while applying the existing URL and expression substitutions. Reproduction: `python TEST_STYLE_OBJECT_SHAPES.py` failed before the fix; it now passes.
4. **Redirect policy weaker than initial proxy URL validation** (`proxy.php`). An allowlisted HTTPS redirect with an alternate port or embedded credentials previously passed the redirect host check. Both redirect acceptance and per-hop execution now use one strict HTTPS, allowlist, no-credentials, port-443 validator. Check: `python TEST_PROXY_TARGET_GUARD.py`.
5. **Validation script not portable** (`RUN_FINAL_CHECKS.py`). The shipped checker required `/mnt/data/OpenStreetMap.zip` and a separate local reference directory that did not exist in the ZIP. The checker now compares against `FRONTEND_REFERENCE_HASHES.json` bundled in this archive. The hash baseline was generated from the user-supplied official CSS and the sanitized integrated HTML. The original logged-in HTML cannot be used byte for byte without exposing account state.

## Files intentionally unchanged

- All four existing CSS files and their layout/style rules.
- Original Better OSM 1.7.9 source and integrated main Better OSM script.
- Official downloaded OSM dynamic page modules and RTL resource.
- Default Standard and mutually exclusive Better OSM base-layer implementation.

`index.html` changes only the cache query for the corrected runtime JS, not its DOM or styling.

## Reproducible offline regression checks

Run `python RUN_FINAL_CHECKS.py` after extraction. It includes the older navigation/route, custom layer and path tests plus the four new targeted tests. The results are written to `FINAL_VALIDATION.json`.

## Not yet certified

- Real network data, commercial tile key authorizations, and complete MapLibre WebGL rendering against upstream tiles and styles. In particular, previous production `undefined.type` and `updateTransitions` errors are not eliminated by syntax tests alone.
- Real server requests require PHP cURL, which is unavailable in this local test environment.
- Full native multilingual UI resources are absent; the locally bundled language options only change some labels.
- Native routing tests still rely on fixture network/map objects rather than a complete production browser session.

These limitations must not be converted to an assertion of zero runtime errors.
