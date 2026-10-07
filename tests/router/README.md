# Router suite

Isolated checks for `CitOmni\Http\Service\Router`: matching, placeholders, method negotiation and the base prefix. No Composer, no database, no environment variables.

```
php tests/router/run.php
```

Expected: `11 passed, 0 failed`

Each case sends real requests to PHP's built-in web server with `server.php` as router, so status codes and the `Allow` header are the ones a client receives. `server.php` holds the route table and the controller and error handler doubles, which report through an `X-Fixture-Report` header, so HEAD and 204 responses carry a report too.

## Cases

Matching:

- An exact route runs its action with the route's `template_file` and `template_layer` hints.
- Trailing slashes and the query string do not affect matching.
- Placeholders capture one segment each by their rule (`id`, `slug`, `email`, `code`, any other name), in order; a segment that breaks the rule is 404.
- Percent-escapes are decoded before matching; a path with non-ASCII characters is 404.
- An unknown path is 404; a missing controller or action is 500.

Methods:

- A method outside the route's list is 405 with an `Allow` header.
- Routes without `methods` allow GET, HEAD and OPTIONS.
- GET implies HEAD, and route methods are case-insensitive.
- OPTIONS answers 204 with `Allow` and runs no action.

Base prefix and case:

- The base prefix comes from the path of `CITOMNI_PUBLIC_ROOT_URL`, else from the directory of `SCRIPT_NAME` without a trailing `/public`.
- Case-insensitive matching is off by default and turned on by `http.router_case_insensitive`, for exact routes, placeholder routes and the base prefix.

## Notes

- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
- The built-in server reports the request path as `SCRIPT_NAME`. A front controller sees its own script path, so the fixture sets it (`?script=`, default `/index.php`).
