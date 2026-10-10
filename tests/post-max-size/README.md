# Post-max-size suite

Checks for `Request::contentLength()`, `Request::exceedsPostMaxSize()` and the early HTTP 413 in `Kernel::run()`, partly through PHP's built-in web server, where the behavior depends on real POST parsing. No Composer, no database, no cURL.

```
php tests/post-max-size/run.php
```

Expected: `52 passed, 0 failed`

The suite runs the real Request, ErrorHandler and Kernel against the doubles in `tests/bootstrap.php`. Every server and INI probe is a PHP process started with `-n` and its own `post_max_size`, so the results do not depend on the caller's php.ini.

## Cases

- `CONTENT_LENGTH` parsing in 22 forms: zero, decimal, leading zeroes, the platform maximum, missing, empty, negative, signed, fractional, exponent, whitespace, duplicate, hex, suffixed, array, boolean, float and overflowing values.
- `post_max_size` parsing at the strict size boundary for `1024`, `1K`, `2m`, `2G`, `0x10K`, `01024`, `0` and `-1`.
- No 413 is inferred from empty `$_POST` and `$_FILES` without a length, non-POST methods bypass the limit, and nonempty parsed superglobals do not.
- Through the built-in server: an oversized multipart body loses `$_POST` and `$_FILES` and gets 413 before dispatch, a small one keeps its CSRF field and file, and a per-file upload limit is no whole-request 413. URL-encoded bodies at 1023, 1024 and 1025 bytes, JSON bodies rejected by their declared size or still readable, and large PUT bodies, which `post_max_size` does not limit.
- The 413 responses: HTML with the existing Danish copy and safety headers, production JSON without internal metadata, and request-level errors in their own log, while other HTTP errors keep their 404, 405, 500 and 403 logs.
- `post_max_size=0` permits large URL-encoded and multipart bodies, and the legacy INI parser fallback still answers 413 instead of a 500.

## Notes

- `run.php` is also the router of the built-in server and the worker for the INI probes (`--ini-probe`).
- The suite used to be `tests/post-max-size-regression.php`, which `tests/run.php` did not collect.
