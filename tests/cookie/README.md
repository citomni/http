# Cookie suite

Isolated checks for `CitOmni\Http\Service\Cookie`. No Composer, no database, no environment variables.

```
php tests/cookie/run.php
```

Expected: `4 passed, 0 failed`

## Cases

- Array-shaped input is absent for `get()`, which returns the default. PHP parses `Cookie: _auth_rm[]=x` into `['_auth_rm' => ['x']]`; the value must never be cast to `"Array"`.
- The same input is absent for `has()`.
- String values, including the empty string, are returned unchanged.
- An absent cookie yields the default, and `has()` is false.

## Regression proof

Against `Cookie` before the typeguard (`get()` cast with `(string)`, `has()` used `array_key_exists()`), the first two cases fail: `2 passed, 2 failed`.
