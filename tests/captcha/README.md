# Captcha suite

Isolated checks for `CitOmni\Http\Service\Captcha` and `CitOmni\Http\Controller\CaptchaController`. No Composer, no database, no environment variables, no citomni/image and no ext-gd.

```
php tests/captcha/run.php
```

Expected: `23 passed, 0 failed`

The first part runs the real `Captcha` and `Request` services on the kernel doubles. Every case starts from the shipped baseline (`Registry::CFG_HTTP`) and applies its own overrides. A `SessionStore` stands for one browser's session across its requests and counts reads and writes; each request gets a fresh `Captcha`, as in production. `FakeImages` replaces the `captchaImage` service of citomni/image: numbered codes, recorded renders, case-insensitive comparison. Unless a case says otherwise, a request carries the session cookie.

The second part sends real requests to PHP's built-in web server with `server.php` as router: the real `Session`, `Cookie`, `Response` and `CaptchaController`, registered as the HTTP service map registers them, so the session cookie, response headers and session files behave as in production. The server runs with the same php.ini as the suite, on a free local port, with a temporary document root that also holds the session files and is removed afterwards.

## Cases

Configuration:

- The baseline constructs without touching session, request or the image service.
- A non-bool `captcha_protection`, an empty `session_key`, `id_field` or `answer_field`, the same name for both fields, a `ttl` or `max_pending` below 1 or not an int, and an `image_path` without a leading slash or with a query, fragment or space fail at construction with `CaptchaConfigException`.

Issuing:

- `issue()` stores the code, an int render seed and an expiry of now plus `ttl` under a new 16-character hex id.
- `current()` issues once per request; `htmlField()` and `imagePath()` use that id, with escaped custom field names and a custom image path.
- Issuing beyond `max_pending` drops the oldest challenges.
- Without the `captchaImage` service, `issue()` fails with `CaptchaConfigException` naming citomni/image, before the session is read or written.

Images:

- `image()` renders a pending challenge with its stored seed, the same on every call, without writing the session or consuming the challenge.
- Unknown, malformed (wrong type, length or case) and expired ids return null.
- A request without the session cookie returns null without reading the session.

Verification:

- A right answer passes once; the challenge is removed, and an empty state removes the session key.
- A wrong answer uses up the challenge; the right answer fails afterwards.
- An expired challenge fails with the right answer and is dropped.
- Challenges of other tabs stay valid after one is verified.
- Missing, array-shaped and unknown fields fail without PHP warnings. Without the challenge's id nothing is consumed; with it, a missing or array-shaped answer is an attempt and uses the challenge up.
- `verifyAnswer()` takes id and answer as arguments, with the same single attempt.
- A request without the session cookie fails without reading the session.
- With `captcha_protection` off, `verify()` and `verifyAnswer()` pass without reading the session.
- Malformed session state (not an array, entries of the wrong length, id, code, seed or expiry, keyed entries) counts as no challenge and is replaced on the next issue.
- Without the `captchaImage` service, a post that matches no challenge fails quietly, and one that matches fails with `CaptchaConfigException`.

Image route and form post in real requests:

- A form page starts a session and issues a challenge; its image is served with `200`, `image/png`, `Content-Length`, `X-Content-Type-Options: nosniff` and `no-store`, the same bytes on every request.
- The route answers 404 without a body for a request without the session cookie (no `Set-Cookie`, no new session file), an unknown or malformed id, and with protection off. The challenge itself is still served afterwards.
- A posted answer verifies once; the replay fails, and the used challenge has no image any more.
- A wrong posted answer uses up the challenge. With protection off, a post passes.

## Notes

- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
- `CaptchaController` is constructed on the `BaseController` double from `tests/support/doubles.php`, which keeps the app and route config like the kernel's.

## Regression proof

- Against a `verifyAnswer()` that removes the challenge only after a right answer, the wrong-answer cases fail: `20 passed, 3 failed`.
- Against a `Captcha` without the session cookie check, the cookieless cases fail, including the session file check in a real request: `20 passed, 3 failed`. A `Session` whose reads never start a session keeps that real-request case passing even then, so with it the result is `21 passed, 2 failed`.
- Against an `image()` that renders without the stored seed, the same-image cases fail: `21 passed, 2 failed`.
