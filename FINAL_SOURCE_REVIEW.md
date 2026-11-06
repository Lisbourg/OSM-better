# Independent Source Review

## Confirmed source corrections

1. Repaired the native Directions request race. Old route responses can no
   longer overwrite a later route. Unloading aborts in-flight routing and
   protects the results panel against stale completion callbacks.
2. Repaired the native Query Features coordinate validation and abort logic.
   Missing/empty inputs no longer silently become 0,0; a legitimate 0,0 query
   remains valid. Bounds sorting tolerates incomplete records.
3. Corrected the server-proxy XMLHttpRequest abort path, preserving the
   original cancellable promise rather than a chained promise.
4. Guarded native MapLibre localization against not-yet-loaded or non-MapTiler
   styles. Retained the original MapTiler language functionality.
5. Caught native Search and Query asynchronous pane-load errors.
6. Removed tests that referenced obsolete controllers and added executable
   tests against deployed native code.

## What was inspected

- Native OSM dynamic import module paths and authenticity hashes.
- JS/PHP parser checks and HTML-inline JS parsing.
- Query/route state, cancellation and sidebar close behavior in real handler code.
- Same-origin PHP route paths at root, one-level and multi-level base paths.
- Better OSM mutually exclusive custom base-layer selection logic.
- MapLibre localization guards and style JSON rewriting fixture.
- User auth capture in distributed HTML, CJK text and filenames, local
  script/style/module link resolution, and ZIP integrity.

## Explicit remaining limitations

- The archive's MapLibre compiled renderer and live style/tile data have not
  been exercised with genuine upstream responses inside this environment.
  Prior `undefined.type` and `updateTransitions` production exceptions cannot
  be conclusively ruled out by static inspection.
- `php-curl` is absent from the local test environment. Full upstream service
  requests, commercial key validity and real search/reverse-geocode/Query
  Features endpoints require deployment-side tests.
- The provided official HTML is a logged-in browser snapshot, not a source
  checkout. Account details and stale pre-rendered panes were removed from
  the shipped HTML, so literal byte-for-byte HTML equality is impossible
  without restoring the user's login material.
- Complete translations for all OSM languages are not included in the source
  archive; partial local labels do not imply full native translation support.
- Better OSM's compiled userscript is present and key integration behavior was
  regression-tested; all optional external-service enhancements are not
  independently guaranteed.

Do not use a syntax-only pass as evidence of zero runtime errors.
