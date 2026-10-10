# Request suite

Isolated checks for `CitOmni\Http\Service\Request`: proxy trust, URL parts, headers, the client IP and the JSON body. No Composer, no database, no environment variables.

```
php tests/request/run.php
```

Expected: `19 passed, 0 failed`

`cfg.http` is the shipped baseline (`Registry::CFG_HTTP`) plus per-case overrides. Most cases set `$_SERVER` directly. `ip()` always answers `CLI` in CLI, and the request body is empty there, so the last six cases send real requests to PHP's built-in web server with `server.php` as router. Addresses are RFC 5737 and RFC 3849 documentation addresses, which PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` treats as public.

## Cases

Proxy trust:

- `X-Forwarded-Host`, `-Proto` and `-Port` count only when `trust_proxy` is on and the peer is in `trusted_proxies`; an empty list trusts no proxy.
- Trusted proxies match exact IPv4/IPv6 addresses and CIDR ranges, including a partial-byte mask. An out-of-range mask and a non-address entry match nothing.
- An entry with a malformed mask (`10.0.0.0/`, `/x`, `/+8`, `::/`) matches no address; whitespace around a decimal mask is accepted.
- `Forwarded` (RFC 7239) supplies host, port and proto from the first hop, including a quoted, bracketed IPv6 host.

URL parts:

- `host()` strips the port, unwraps bracketed IPv6, falls back to `SERVER_NAME` and then `localhost`, and keeps only host characters.
- `port()` takes `SERVER_PORT`, normalized to 443 behind a TLS terminator, or the scheme default; an untrusted `X-Forwarded-Port` is ignored.
- `isHttps()` reads `HTTPS`, `REQUEST_SCHEME` and port 443.
- `uri()`, `pathRaw()` and `queryString()` split the request target.
- `pathFromAppRoot()` strips the base path of `http.base_url`.
- `baseUrl()` ends `http.base_url` in exactly one slash; `fullUrl()` omits default ports.

Headers and input:

- `header()` maps names to `HTTP_*` keys, plus `Content-Type` and `Content-Length`, with a default; `headers()` lists them lowercased.
- `contentType()` drops parameters and lowercases the media type.
- `sanitize()` trims and escapes string input and treats array-shaped input (`?q[]=x`) as absent, without a PHP warning; with `both`, an array in the query falls through to the form body.

Real requests:

- `ip()` returns a public peer address and ignores `X-Forwarded-For`, `Client-Ip` and `X-Cluster-Client-Ip` from untrusted peers.
- Behind a trusted proxy, `ip()` returns the forwarded client address; `ip(false)` and `ip(true)` override `trust_proxy`.
- `X-Forwarded-For` is read from the right: trusted proxies are skipped, and the first other entry is the client. What the client sent stays on the left and is ignored. A proxy missing from `trusted_proxies`, a malformed entry or a chain of only trusted proxies gives `unknown`.
- `ip()` reads no other client-IP header (`Client-Ip`, `X-Forwarded`, `X-Cluster-Client-Ip`, `Forwarded-For`, `Forwarded`).
- `json()` decodes `application/json`, `text/json` and `+json` bodies (BOM and whitespace allowed, big integers as strings) and yields null for other media types, invalid JSON, scalars and empty bodies.
- `CITOMNI_PUBLIC_ROOT_URL` takes precedence for `baseUrl()` and `pathFromAppRoot()`.

## Regression proof

- Against `ip()` before it read `X-Forwarded-For` from the right (it took the first public entry, then `Client-Ip` and similar headers), the right-to-left case and the other client-IP headers case fail.
- Against `ipInCidr()` before it required a decimal mask (it read a malformed mask as /0), the malformed mask case fails.
- Against `sanitize()` before it checked the value's type (it cast an array to "Array"), the sanitize case fails on the warning.

## Notes

- `contentLength()` and `exceedsPostMaxSize()` are covered by the post-max-size suite (`tests/post-max-size/run.php`).
- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`. Every request reaches it from 127.0.0.1, so it takes the peer address from `?peer=`.
