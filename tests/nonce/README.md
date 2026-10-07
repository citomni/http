# Nonce suite

Isolated checks for `CitOmni\Http\Service\Nonce`, the filesystem ledger behind webhook replay protection. No Composer, no database.

```
php tests/nonce/run.php
```

Expected: `9 passed, 0 failed, 1 skipped`

With `CITOMNI_TEST_PARALLEL=1` the multi-process case runs too. Expected: `10 passed, 0 failed`

The ledger lives in a temporary `CITOMNI_APP_PATH`, so the shipped baseline directory (`var/nonces`) is used, and is removed afterwards. Entries are aged with `touch()`, because the ledger decides expiry by file modification time.

## Cases

- First use is accepted, and a replay within the TTL is rejected, also by a new service instance. The entry is named by the nonce's SHA-256 hash.
- Namespaces keep separate ledgers.
- An entry younger than the TTL is a replay; an entry as old as the TTL has expired, is reaped on reuse and stored again.
- Malformed input is rejected without creating any storage: a TTL below 1, an empty, too long or non-URL-safe nonce, an invalid namespace.
- Nonces are URL-safe identifiers (hex, base64url, UUID, `urn:x:1.2`) of at most `max_len` bytes; namespaces have at most 64 characters.
- A ledger directory that cannot be created makes `checkAndStore()` return false, not throw.
- `purgeExpired()` removes only expired `.nonce` entries, at most `$max`, and leaves other files alone. Invalid arguments and unused namespaces remove nothing.
- With `purge_probability` 1, every store purges expired entries in its own namespace and no other.
- Invalid configuration fails at construction: empty or NUL-containing `dir`, `max_len` outside 8 to 1024, `purge_probability` or `purge_limit` below 1.
- With `CITOMNI_TEST_PARALLEL=1`: eight processes claim one nonce at the same moment, and exactly one is accepted.

## Notes

- `worker.php` is the worker process for the parallel case, not a suite; `tests/run.php` only collects `run.php` and `database.php`.
- The suite leaves diagnostics silenced with `@` to PHP, as the production ErrorHandler does. Nonce relies on `@` for its expected filesystem failures.
