# Url suite

Isolated checks for `CitOmni\Http\Util\Url::isLocal()`, which guards user-supplied redirect targets (such as `?next=`) against open redirects. No Composer, no database, no environment variables.

```
php tests/url/run.php
```

Expected: `4 passed, 0 failed`

## Cases

- Paths with exactly one leading slash are local, whatever follows, including encoded slashes.
- Absolute URLs, scheme-relative targets (`//host`) and backslash variants (`/\host`, `\\host`) are not local.
- Targets with control characters (tab, newline, NUL, DEL and the rest of U+0000 to U+001F) are not local. Browsers drop tab and newline before parsing, so `/<tab>/host` would be read as `//host`.
- Empty and relative targets are not local, nor is a path with leading whitespace.

## Regression proof

- Against `isLocal()` before it rejected control characters, the control characters case fails.
