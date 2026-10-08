# Session suite

Isolated checks for `CitOmni\Http\Service\Session`, and for the `Flash` and `Csrf` services on top of it. No Composer, no database, no environment variables.

```
php tests/session/run.php
```

Expected: `27 passed, 0 failed`

Each case is one request to PHP's built-in web server with `server.php` as router, so session storage, the session cookie and output behave as in a real request. The server runs with the same php.ini as the suite, on a free local port, with a temporary document root that is removed afterwards. Every case gets its own session storage directory.

`cfg.session`, `cfg.cookie` and `cfg.http` are the shipped baseline (`Registry::CFG_HTTP`) plus per-case overrides. Session resolves its cookie through the real `Cookie` service, which asks the real `Request` service for HTTPS. The read cases run the real `Flash` and `Csrf` services on the same `Session`.

## Cases

Rotation:

- `regenerate()` on a request without a session starts one, sends only the final id in `Set-Cookie`, and leaves no file for the id issued on start.
- `regenerate()` on an existing session rotates the id, keeps the data and deletes the old session file.
- `regenerate()` throws when the storage handler cannot destroy the old session.
- `regenerate()` throws when headers were already sent, instead of continuing on the old id.

The session cookie:

- With `session.cookie_secure` and `cookie.secure` unset and an `http://` base URL, a request from a trusted proxy with `X-Forwarded-Proto: https` gets a session cookie with the Secure attribute: The inference asks the request service.
- The same request without `http.trust_proxy` gets no Secure attribute.
- The session cookie takes SameSite, path and domain from `cfg.cookie`, normalized by `Cookie` (`'/app/'` becomes `/app`, `'Example.TEST'` becomes `example.test`). HttpOnly stays on although `cookie.httponly` is false, because the baseline pins `session.cookie_httponly` to true.
- `session.cookie_*` settings override `cfg.cookie`, and `session.cookie_domain` `''` makes the session cookie host-only although `cookie.domain` is set.
- `SameSite=None` without Secure throws before the session starts: No cookie, no session file.

Configuration and storage:

- `session.rotate_interval` above 0 and `session.fingerprint` with a binding throw before the session starts.
- The same options with disabled values are ignored, and the session starts.
- A `session.save_path` that cannot be created throws with the path, before `session_start()`.
- A setting that PHP refuses throws with the directive and the value. The case uses `gc_divisor` 0, which PHP 8.2 and later reject, and the numeric session name `12345`, for which `session_name()` would only warn; a server lock (`php_admin_value`) is refused the same way.
- A caller that catches the refusal and writes again in the same request gets the same exception, not a session on php.ini values: No cookie, no session file.
- `gc_maxlifetime`, `gc_probability`, `gc_divisor` and `use_strict_mode` come from `cfg.session`, and the cookie lifetime is 0, although the router first sets the values of a Debian/Ubuntu php.ini (`gc_probability=0`) and a persistent cookie lifetime. The session cookie has neither Expires nor Max-Age.

Destruction:

- `destroy()` throws when the storage handler refuses, sends no `Set-Cookie`, and leaves the session file with its data in place.
- `destroy()` on an existing session deletes the session file and expires the cookie with the attributes it was set with.

Reads never create a session:

- `get()`, `has()`, `remove()` and `destroy()` on a request without the session cookie send no `Set-Cookie`, create no session file and send none of the cache headers that `session_start()` sends (`Expires`, `Cache-Control`, `Pragma`).
- The same holds for every `Flash` reader (`pullAll()`, `peekAll()`, `peek()`, `take()`, `oldValue()`, `hasOld()`) and for `clear()`, `keep(false)`, `forgetMsg()`, `forgetOld()`, `old([])` and `fieldErrors([])`.
- A flash message and old input written on one request are returned by the next `pullAll()` with the session cookie, and are gone on the one after. With `keep()`, they survive one more `pullAll()`.
- A POST without a session fails CSRF verification as `token_missing`, also with a token header, and `Csrf::clear()` creates no session either.
- A CSRF token issued on a GET verifies on the POST that presents the session cookie.
- A read with the session cookie resumes the session with its data and sends no new cookie.
- A read with a cookie whose id storage does not know starts one new session under strict mode, with a new id in `Set-Cookie`.
- A read after `session_write_close()` in the same request resumes the same session, although the request has no session cookie.
- A read after `destroy()` in the same request does not resume the destroyed id: No new session file, only the expiring `Set-Cookie`.

## Notes

- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
- Like the kernel App, the App double resolves services through `__get()` and has no `__isset()`.
- The router collects PHP warnings and continues, as the production ErrorHandler does for non-fatal errors. A throwing handler would preempt the `false` return values from `session_regenerate_id()` and `session_destroy()` that these cases are about. `run.php` itself throws on every diagnostic that is not silenced with `@`.

## Regression proof

Against `src/` before the session/cookie contract, 8 cases fail: The session cookie ignores `cfg.cookie`, `SameSite=None` without Secure starts a session, `session.rotate_interval` is accepted, the save path that cannot be created surfaces as `session_start() failed.`, the refused `gc_divisor` goes unnoticed (also on the retry), the storage case reports `gc_probability` 0 and `gc_divisor` 100 from the simulated php.ini, and a refused `destroy()` returns without an exception.

With the setup guard set before the steps instead of after them, the retry case fails. With the name applied through `session_name()` instead of `ini_set()`, the refused-setting case fails. With `Cookie` passing paths through unchanged, the case for the attributes from `cfg.cookie` fails.

Against `src/` before reads stopped creating sessions, 4 cases fail: The guest reads, the `Flash` readers and the CSRF verification without a session create one, and a read after `destroy()` starts a new session. With mutations of `Session`, the matching cases fail: Without the `session_id()` check, the read after `session_write_close()`; without the cookie check, every case that resumes a session from its cookie; without removing the cookie in `destroy()`, the read after it.

Against `Session` before the regeneration fix (`regenerate()` required an active session and ignored the return value of `session_regenerate_id()`), the first, third and fourth cases fail. Against `Session` that checked `isset($this->app->request)`, the trusted-proxy case fails, because the check is always false.
