# WebhooksAuth suite

Isolated checks for `CitOmni\Http\Service\WebhooksAuth`, together with the real `Nonce` ledger. No Composer, no database, no environment variables.

```
php tests/webhooks-auth/run.php
```

Expected: `17 passed, 0 failed`

Every case starts from the shipped baseline (`Registry::CFG_HTTP`) with `enabled` on, in a temporary `CITOMNI_APP_PATH`, so the secret file and the nonce ledger sit at their baseline paths. The secret is random per run. The suite signs requests per the contract documented with the baseline, with the secret's hex text as HMAC key, as the verifier uses it. In CLI the request body is empty; the last case sends real bodies through PHP's built-in web server with `server.php` as router.

## Cases

Signatures:

- Disabled webhooks fail every request as `disabled`, without loading a secret or touching the ledger.
- A request signed per the contract is accepted once; its replay fails as `nonce_rejected`.
- The signature binds method, path, query and body.
- Simple mode (`bind_context` off) signs `ts.nonce.body` and leaves method and path unbound; a signature for the other mode fails.
- A wrong signature is rejected without consuming its nonce, so the genuine request still passes.
- A missing or empty signature, timestamp or nonce header fails as `headers_missing`.
- Signatures of the wrong length or with non-hex characters fail as `signature_malformed`; uppercase hex is accepted.
- Timestamps older than ttl + skew, further ahead than the skew, zero, negative or non-numeric fail as `timestamp_out_of_window`, and the ledger is not written.
- The ledger keeps a webhook nonce for the whole acceptance window, ttl + 2 x skew; after that the nonce may be reused.

IP allow-list:

- The allow-list is checked before anything else. It matches the IP from `Request::ip()`, or `REMOTE_ADDR` when that is `unknown` or `CLI`, against exact entries and IPv4/IPv6 CIDR ranges. IPv4 never matches an IPv6 entry.
- An entry with a malformed mask (`10.0.0.0/`, `/x`, `::/`) matches no address; whitespace around a decimal mask is accepted.

Secret and algorithm:

- The secret file can select sha512 (128-hex signatures); a cfg `algo` overrides the file.
- Invalid configuration fails at construction with `WebhooksAuthConfigException`: no or missing secret file, a file that returns no array, an empty or non-hex secret, an unknown algo in the file or in cfg (also while disabled), a TTL below 1, a negative skew, an empty header name.

Adapters and logging:

- `requireValid()` returns the verified body and throws `WebhooksAuthVerificationException` with the reason.
- `requireOrAbort()` passes 404 (or the given status) and the failure meta to the error handler; the meta holds no signature.
- Failures are logged as `webhook.fail` with the reason, and successes as `webhook.ok` when `log_successes` is on. Entries hold no signature and no secret; `log_failures` off logs nothing.

Real requests:

- A real POST body is verified byte for byte and returned by `requireValid()`; the replay is rejected, and the same JSON without its line break fails as `signature_mismatch`.

## Regression proof

- Against the allow-list before it required a decimal mask (it read a malformed mask as /0), the malformed mask case fails.

## Notes

- `server.php` is the router, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
