# Cookie suite

Isolated checks for `CitOmni\Http\Service\Cookie`. No Composer, no database, no environment variables.

```
php tests/cookie/run.php
```

Expected: `17 passed, 0 failed`

The real `Cookie` and `Request` services run against the kernel doubles. `cfg.cookie` is the shipped baseline (`Registry::CFG_HTTP`) plus per-case overrides.

## Cases

Reading untrusted input:

- Array-shaped input is absent for `get()`, which returns the default. PHP parses `Cookie: _auth_rm[]=x` into `['_auth_rm' => ['x']]`; the value must never be cast to `"Array"`.
- The same input is absent for `has()`.
- String values, including the empty string, are returned unchanged.
- An absent cookie yields the default, and `has()` is false.

Same-request view after `set()`:

- On the IPv6 host `[::1]`, `get()` returns the value `set()` wrote in the same request, not the value the request brought.
- The same holds on `[2001:db8::1]:8443`: The port follows the closing bracket, and the colons inside the brackets belong to the address.

Default attributes:

- The baseline defaults are host-only, HttpOnly, `SameSite=Lax` and path `/`.
- An absolute `http.base_url` does not give the cookies a Domain, with `cookie.domain` null or `''`.
- A configured `cookie.domain` is used lowercase and without a leading dot; `'.'` means host-only.
- Paths get a leading slash and no trailing slash, in the defaults and in `attributes()`; null and `''` mean `/`, and a path that is not a string throws.
- Secure is inferred from an https `http.base_url`, an HTTPS request, and, in the last case, an https `CITOMNI_PUBLIC_ROOT_URL` defined before boot without a base URL. An explicit `cookie.secure` wins.
- Invalid values throw: A `cookie.secure` that is neither a bool nor null, an unknown SameSite, a non-boolean `httponly`, a domain that is not a string.
- `SameSite=None` requires Secure, also when the service options set it.
- `attributes()` puts normalized overrides on top of the defaults without changing them, and validates the combination.
- An invalid SameSite option on `set()` or `delete()` throws before any header.

## Regression proof

Against `Cookie` before the host-only default and the strict validation, 8 cases fail, among them "An absolute base URL does not give the cookies a Domain" (`'example.com'`), "Invalid cfg values throw instead of falling back", the path case (`'/app/'`), the `attributes()` case and the `[::1]` case, since that version parsed the host the same way.

Against `Cookie` before the typeguard (`get()` cast with `(string)`, `has()` used `array_key_exists()`), the first two cases fail.

Against `Cookie` before the IPv6 host fix, the `[::1]` case fails (`Expected 'new'; got 'old'`): After removing the brackets, the port was cut at the first colon, which is part of the address, and the empty host matched no cookie, so `set()` left the request's value in `$_COOKIE`. The `[2001:db8::1]:8443` case passed before as well, only because the host was cut to `2001`, which counts as a name, and a host-only cookie is visible on any name. It guards the parsing of a port after the brackets.
