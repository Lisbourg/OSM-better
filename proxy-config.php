<?php
// Edit this file to add per-host Host/SNI/Referer rules and provider API keys.
// There is intentionally no host allowlist here: the proxy forwards to any
// HTTP(S) host the page or a custom map layer requests, so custom layers can
// never be turned into 403s by a missing list entry.
return [
    // Only keys owned by the site operator belong in this server-side file.
    'provider_api_keys' => [
        'api.maptiler.com' => '',
        'tile.tracestrack.com' => ''
    ],
    // Upstream Referer/Origin contract. A browser tab on www.openstreetmap.org
    // runs under Referrer-Policy: strict-origin-when-cross-origin, so every
    // cross-origin subresource/API/tile request it makes carries only the page
    // origin: "Referer: https://www.openstreetmap.org/". The PHP proxy, which
    // issues all upstream requests on the page's behalf, reproduces exactly
    // that contract by default. Hosts that require a different Referer keep
    // their own per-host entry in referer_overrides (an explicit ?referer=
    // parameter from custom map layers still wins over both).
    'default_referer' => 'https://www.openstreetmap.org/',
    'referer_overrides' => [
        'tile.tracestrack.com' => 'https://www.openstreetmap.org/',
        'tiles.indoorequal.org' => 'https://www.openstreetmap.org/',
        'api.maptiler.com' => 'https://www.openstreetmap.org/',
        'api.thunderforest.com' => 'https://www.openstreetmap.org/',
        'tiles.openrailwaymap.org' => 'https://www.openrailwaymap.org/',
        'maps.vk.com' => 'https://maps.vk.com/'
    ],
    'host_overrides' => [],
    'sni_overrides' => [],
    'connect_to_overrides' => [],
    // query.openstreetmap.org answers its query-features endpoint only when
    // the request carries the _osm_totp_token cookie that the official page's
    // browser session has (the official bundle POSTs with credentials:
    // "include", and the OSM website sets that cookie for .openstreetmap.org
    // on every visit; its TOTP value rotates hourly). Proxied requests leave
    // from PHP without the browser's cookie store, so the proxy keeps its own
    // server-side copy: it GETs source_url, caches the Set-Cookie token with
    // its exact expiry (capped at the next hour rotation), attaches it to
    // every hop targeting a host below, and re-acquires a fresh token
    // whenever upstream still answers 403. This is a cookie contract, not an
    // allowlist: every other HTTP(S) host keeps working exactly as before.
    'totp_cookie' => [
        'hosts' => ['query.openstreetmap.org'],
        'name' => '_osm_totp_token',
        'source_url' => 'https://www.openstreetmap.org/'
    ],
    'allow_request_header_overrides' => false,
    'connect_timeout' => 15,
    'request_timeout' => 180,
    'max_redirects' => 8,
    'cookie_session' => false
];
