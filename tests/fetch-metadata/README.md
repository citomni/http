# Fetch Metadata suite

Checks for the global Fetch Metadata policy: `Kernel::guardFetchMetadata()` and `CitOmni\Http\Policy\FetchMetadataPolicy`, with the real Kernel, Request, Router, `Util\RoutePath` and ErrorHandler. No Composer, no database, no session extension, no environment variables.

```
php tests/fetch-metadata/run.php
```

Expected: `65 passed, 0 failed`

Each case sends real requests to PHP's built-in web server with `server.php` as router. `server.php` builds the configuration from the vendor baseline plus a policy chosen by query flags, and holds the App, maintenance and controller doubles. The App double throws on any other service (session, auth), so a case fails if the guard touches one. The doubles report through `X-Fixture-*` headers whether maintenance, the router and the controller ran.

## Cases

Switching on and the fast path:

- The vendor baseline is off; an explicit `enabled => false` ignores even invalid rules; a non-boolean `enabled` is a 500.
- `same-origin` continues for every method without consulting the exemptions (an invalid rule is present and never fails it); `none` continues too.
- Accepted requests write no request-log record.

Decisions:

- Missing and unknown `Sec-Fetch-Site` follow `allow_missing`; with it off, only an exemption admits the request.
- `same-site` passes only with `allow_same_site`; an untrusted sibling may still navigate a top-level document.
- Cross-site GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS to an app route are 403 before maintenance, also with a cookie.
- Cross-site GET/HEAD top-level navigations pass; navigations to an iframe, frame, image, object or embed destination, or to `empty` or no destination, a cross-site POST navigation and a fetch with destination `document` are 403.
- Header values are compared case-insensitively.

Exemptions:

- Prefix rules cover the path and paths below it, never a sibling name or a path that only appears in the query string; exact rules ignore a trailing slash and nothing else.
- Only the listed methods pass; OPTIONS is not added.
- `false` removes an inherited rule; keys next to a complete rule are ignored; a prefix of `/` covers every path for its methods.
- Invalid rules are a 500 even when another rule matches: missing fields, query, backslash, percent, dot segments, lowercase or empty methods, unknown match mode, numeric rule names, a list instead of a map.
- Exemption paths are resolved by `RoutePath` exactly as Router resolves the route: decoding once, query, trailing slash, mount path from the public root URL or `SCRIPT_NAME`, `http.router_case_insensitive`.
- An exemption leaves routing in control (an unknown path below an exempt prefix is a 404).

Responses:

- Every response of an enabled policy carries `Vary: Sec-Fetch-Site, Sec-Fetch-Mode, Sec-Fetch-Dest`, appended to an existing `Vary`.
- The request body is never read; a refusal is the ErrorHandler's 403 with reason `fetch_metadata_rejected` in `http_request.jsonl`; a custom error handler that returns cannot resume dispatch.

## Notes

- The suite checks the server policy, not which headers browsers send. The browser behaviour (Chromium, WebKit) has to be verified with real browsers on the real hosting path.
- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
