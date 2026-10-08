# Csrf suite

Isolated checks for `CitOmni\Http\Service\Csrf`. No Composer, no database, no environment variables.

```
php tests/csrf/run.php
```

Expected: `24 passed, 0 failed`

Every case starts from the shipped baseline (`Registry::CFG_HTTP`) and applies its own overrides, so a changed default shows up here. The real `Request` reads `$_SERVER` and `$_POST` as each case sets them; session and log are doubles. A `SessionStore` stands for one browser's session across its requests, and each request gets a fresh `Csrf`, as in production. Like `Session`, the double creates the session on `start()` and `set()`, never on `get()` or `remove()`. Unless a case says otherwise, a request is an HTTPS POST to `https://example.test/form` with a same-origin `Origin` header.

## Cases

Request scope:

- Safe methods (GET, HEAD, OPTIONS) pass without token, Origin or session, and nothing is logged.
- Disabled protection passes unsafe requests without starting the session.
- `protect_methods` replaces the default list and matches case-insensitively.

Token layer:

- A same-origin POST passes with the token in the header or in the form field; issuing the token created the session.
- Each `token()` is masked differently and verifies against the same session secret, which is never sent as is.
- `requireValid()` throws `CsrfVerificationException` with the reason for which `verify()` returns false.
- A token from another session fails as `token_mismatch`, a malformed one as `token_invalid`, and one sent to a session without a secret as `token_missing`.
- The header token wins over the form field, and the form field is read only for POST. Custom header and field names replace the defaults.
- An array-shaped form token (`_csrf[]=x`) fails as `token_invalid`, without a PHP warning.
- With `mask_tokens` off, the token is the raw secret and compares case-insensitively; a masked token is refused.
- Verification and `clear()` never create a session: A forged POST to a browser without a session fails as `token_missing`, with or without a token, and no session exists afterwards.
- `rotate()` and `clear()` invalidate tokens issued earlier.
- A corrupted session secret fails fast with `CsrfException` instead of counting as a client error.
- `htmlField()` renders an escaped hidden input whose token verifies.

Fetch metadata layer:

- `Sec-Fetch-Site: cross-site` is rejected before Origin and token are checked; the layer can be turned off.
- `same-origin`, `none` and `same-site` pass; `allow_same_site` off refuses `same-site`.
- A request whose Origin is in `trusted_origins` passes even as `cross-site`, or as `same-site` while `allow_same_site` is off; the token is still checked. Untrusted, missing and `null` origins are refused.

Origin and Referer layer:

- A foreign Origin is rejected even with a valid token: another host, a suffix trick, a scheme downgrade, another port, a non-URL.
- Origins compare scheme, host and port. Default ports are normalized; a request on another port needs that port in its Origin.
- HTTPS without Origin falls back to the Referer, also for `Origin: null`; without the fallback the request fails as `origin_missing`.
- Plain HTTP may omit Origin unless `allow_missing_origin_on_http` is off; an Origin that is present is still compared.
- `trusted_origins`: full origins match exactly, bare hostnames match the host on any scheme and port, and invalid entries are ignored.

Logging and configuration:

- Failures are logged to the `security` channel as `csrf.failure` with the reason and request context, never with the submitted token or the session secret. `log_failures` off logs nothing.
- `token_bytes` below 16 and empty field, header or session key names fail at construction.

## Regression proof

- Against the fetch metadata layer before it consulted `trusted_origins` (it refused every `cross-site` request), the trusted origins case fails.
- Against the token layer before it checked the form field's type (it cast the array to "Array"), the array-shaped token case fails on the warning.
- Against `Csrf` that started the session before reading the token, the case "Verification and clear() never create a session" fails.
