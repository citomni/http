# Session suite

Isolated checks for `CitOmni\Http\Service\Session`. No Composer, no database, no environment variables.

```
php tests/session/run.php
```

Expected: `6 passed, 0 failed`

Each case is one request to PHP's built-in web server with `server.php` as router, so session storage, the session cookie and output behave as in a real request. The server runs with the same php.ini as the suite, on a free local port, with a temporary document root that is removed afterwards. Every case gets its own session storage directory.

## Cases

- `regenerate()` on a request without a session starts one, sends only the final id in `Set-Cookie`, and leaves no file for the id issued on start.
- `regenerate()` on an existing session rotates the id, keeps the data, deletes the old session file and records `_sess_rotated_at`.
- `regenerate()` throws when the storage handler cannot destroy the old session, and records nothing.
- `regenerate()` throws when headers were already sent, instead of continuing on the old id.
- With `session.cookie_secure` unset and an `http://` base URL, a request from a trusted proxy with `X-Forwarded-Proto: https` gets a session cookie with the Secure attribute: the inference asks the request service.
- The same request without `http.trust_proxy` gets no Secure attribute.

## Notes

- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
- The Secure cases register the real `Request` service with the case's proxy trust. Like the kernel App, the App double resolves services through `__get()` and has no `__isset()`.
- The router collects PHP warnings and continues, as the production ErrorHandler does for non-fatal errors. A throwing handler would preempt the `false` return value from `session_regenerate_id()` that these cases are about. `run.php` itself throws on every diagnostic that is not silenced with `@`.

## Regression proof

Against `Session` before the fix (`regenerate()` required an active session and ignored the return value of `session_regenerate_id()`), cases 1, 3 and 4 fail: `1 passed, 3 failed`.

Against `Session` that checks `isset($this->app->request)`, case 5 fails, because the check is always false and the inference falls back to the raw server variables: `5 passed, 1 failed`. Case 6 passes either way.
