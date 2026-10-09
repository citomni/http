# ErrorHandler suite

Isolated checks for the directory `CitOmni\Http\Service\ErrorHandler` writes its JSONL logs to. No Composer, no database, no environment variables.

```
php tests/error-handler/run.php
```

Expected: `6 passed, 0 failed`

The suite runs in a temporary `CITOMNI_APP_PATH`, so the default log directory is `var/logs` below it, and removes it afterwards. Each case logs one `E_USER_WARNING` through `handlePhpError()` and reads the record back from `http_err_phperror.jsonl`. The baseline render trigger is 0, so nothing is rendered and the process does not exit.

## Cases

- An explicit `log.path` with a trailing slash is used, and the default directory is not created.
- An empty or null `log.path` falls back to `CITOMNI_APP_PATH . '/var/logs'`.
- A whitespace-only `log.path` falls back to `var/logs`.
- An empty `log.path` service option wins over an explicit cfg path and falls back to `var/logs`; the cfg directory is not created.
- Surrounding whitespace, a newline included, is trimmed from an explicit `log.path`.
- A log directory that another request creates between `is_dir()` and `mkdir()` is used: `mkdir()` fails, and the record is still written.

## Regression proof

- Against `hydrate()` before the empty-path fallback, the empty, whitespace-only, service-option and trimming cases fail: `2 passed, 4 failed`. The empty and service-option cases fail with the error_log line `[CitOmni] writeJsonl failed: log dir create failed`; in the other two the record went to a relative directory below the working directory.
- Against `writeJsonl()` before it checked `is_dir()` again after a failed `mkdir()`, the race case fails with `log dir create failed`: `5 passed, 1 failed`.

## Notes

- `install()` is never called, so the suite's own error handler stays in place. Like the production ErrorHandler, it leaves diagnostics silenced with `@` to PHP; `writeJsonl()` relies on `@` for its expected filesystem failures.
- PHP's `error_log` goes to a file in the temporary root and is emptied before each record is logged. A missing record fails with the last line written there, which is the reason `writeJsonl()` gave up.
- The temporary root is the working directory while the cases run, so the relative directories of the regression proof stay inside it.
- The race case uses a stream wrapper (`mkdirrace://logs`) as log directory. Its `url_stat()` reports the directory missing until `mkdir()` is called, and `mkdir()` then fails although the directory exists, so the interleaving with the other request is the same on every run. Files below it are kept in memory.
- The kernel doubles in `tests/support/doubles.php` have no `CitOmni\Kernel\Arr`. The suite aliases a stand-in that delegates to `mergeLastWins()`.
