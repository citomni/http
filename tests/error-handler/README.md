# ErrorHandler suite

Isolated checks for `CitOmni\Http\Service\ErrorHandler` and its default error template: where and how the JSONL logs are written, rotated and pruned, what a response carries, and how the handler ends a request. No Composer, no database, no environment variables.

```
php tests/error-handler/run.php
```

Expected: `31 passed, 0 failed, 1 skipped`

The skipped check starts several writer processes at once. It runs only when `CITOMNI_TEST_PARALLEL` is `1`, and then the expected result is `32 passed, 0 failed`.

The suite runs in a temporary `CITOMNI_APP_PATH`, so the default log directory is `var/logs` below it, and removes it afterwards. Log cases call `handlePhpError()` with an `E_USER_WARNING` and read the record back. The baseline render trigger is 0, so nothing is rendered and the process does not exit.

| File | Role |
|---|---|
| `run.php` | Runner and checks. Also the re-entry worker: `php run.php --reentry <log dir>`. |
| `worker.php` | Child process for the cases that end the process: out of memory, a fatal template, later shutdown functions, trace arguments and the concurrent writers. |
| `server.php` | Router for PHP's built-in web server, for the cases that need real response headers. |

## Cases

R marks a regression check: it fails against the handler and template as they were before the change it guards. The other checks pin existing behavior.

| | Check | Guards |
|---|---|---|
| | An explicit `log.path` with a trailing slash is used, and the default directory is not created. | Existing behavior |
| | An empty or null `log.path` falls back to `CITOMNI_APP_PATH . '/var/logs'`. | Existing behavior |
| | A whitespace-only `log.path` falls back to `var/logs`. | Existing behavior |
| | An empty `log.path` service option wins over an explicit cfg path and falls back to `var/logs`; the cfg directory is not created. | Existing behavior |
| | Surrounding whitespace, a newline included, is trimmed from an explicit `log.path`. | Existing behavior |
| | A log directory that another request creates between `is_dir()` and `mkdir()` is used. | Existing behavior |
| | `httpError()` called from a shutdown function after an earlier `httpError()` has rendered does not return to its caller. | Existing behavior |
| R | Shutdown functions registered after the handler still run after a fatal error, and their output follows the page. | `handleShutdown()` ended with `exit(0)`, which cancels every shutdown function registered after it. |
| R | After a fatal error, a later warning is logged but not rendered, and a later `httpError()` ends the process without a second response. | Same as above: the later shutdown functions never ran. |
| R | An out-of-memory fatal is logged and answered with the built-in page, with `max_memory_limit` equal to `memory_limit`, for 512- and 4000-byte strings at 16M and 64M. | The shutdown handler ran out of memory itself: no record with 512-byte strings, no page with either. |
| R | A fatal error inside the page template is answered with the built-in page. | The shutdown handler rendered the same template again, which failed the same way: an empty response. |
| R | A logged trace shows argument types, not values, unless `log.trace_args` is on. | Argument values, passwords included, were always logged. |
| R | A line the log takes only in part is cut off again, and PHP's `error_log` names the file. | The partial line stayed and ran into the next record, and nothing was reported. |
| R | A line the log takes none of is reported with the file name. | A failed write was not reported. |
| R | A partial line that cannot be cut off safely stays, and `error_log` says so: when the truncation fails, and when the lock was not taken. | Nothing was reported. |
| R | A rotation moves every record to a file with the live file's permissions and keeps the new record live. | Rotated files were always set to `0644`. |
| R | A rotation whose copy is cut short leaves the live file as it was. | The live file was truncated anyway: the records that did not make it into the copy were lost. |
| R | A rotation whose rename fails removes the copy and leaves the live file as it was. | The live file was truncated before the rename; the records stayed in a `.tmp` file that retention never saw. |
| R | A rotation whose truncation fails keeps the records in both files, and `error_log` says so. | The failure was not reported. |
| R | Without the lock on the live file, nothing is rotated. | The handler copied and truncated without the lock, so a concurrent append could be lost. |
| R | Retention keeps the newest rotations of its own log and leaves every other file alone. | Any `<base>.*<ext>` file counted as a rotation, so `http_err_exception.backup.jsonl` was deleted. |
| R | Retention works in a directory whose path contains glob syntax (`logs[1]`). | `glob()` read the brackets as a character class, found nothing and pruned nothing. |
| R | A warning inside the application's `strtok()` loop leaves the loop intact. | `baseRecord()` called `strtok()` and reset the application's tokenizer. |
| R | An error after download headers sends neither their `Content-Disposition` nor their `Content-Length`. | The error page went out as a download with the file's length. |
| R | After streaming has begun, the error id is appended to an HTML body only. | The HTML comment was appended to a CSV body whenever the client accepted HTML. |
| R | Outside dev, a rendered PHP error shows a generic message, and its record the real one. | The raw PHP message, with its file path, reached the client. |
| R | An output handler that throws while its buffer is discarded leaves the exception's response in place. | The exception escaped the exception handler, and the shutdown handler answered with another error id. |
| R | The default template falls back to English for a language without texts, without diagnostics. | `require` of the missing language file failed, so the failsafe template rendered, and a warning was logged. |
| R | The default template uses its own texts for a status without texts, without diagnostics. | `(string)` bound tighter than `??`: the fallbacks were dead, and each text raised two warnings. |
| R | The default template offers the support links only in dev, and without the query string. | Every production 5xx page linked to CitOmni support and GitHub, prefilled with the full URL. |
| R | The default template escapes the public root URL in its home link. | The URL was printed raw into the `href`. |
| | Concurrent writers lose no records across rotations. | Existing behavior, with `CITOMNI_TEST_PARALLEL=1` only: 4 processes write 100 records each through dozens of rotations. |

## Regression proof

Against the handler, template and baseline before the out-of-memory, response, logging and template changes (`citomni/http` `a0d91a0`), with `CITOMNI_TEST_PARALLEL=1`: `8 passed, 24 failed`. Every R check fails; the 7 existing checks and the concurrency check pass.

Against `hydrate()` before the empty-path fallback, the empty, whitespace-only, service-option and trimming cases fail. Against `writeJsonl()` before it checked `is_dir()` again after a failed `mkdir()`, the race case fails with `log dir create failed`. Against `httpError()` before it exited on re-entry, the re-entry case fails with `nested httpError() returned to its caller`.

## Notes

- `install()` is never called in the main process, so the suite's own error handler stays in place. Like the production ErrorHandler, it leaves diagnostics silenced with `@` to PHP; `writeJsonl()` relies on `@` for its expected filesystem failures.
- PHP's `error_log` goes to a file in the temporary root and is emptied before each logged warning. A missing record fails with the last line written there, which is the reason `writeJsonl()` gave up.
- The temporary root is the working directory while the cases run, so relative directories stay inside it.
- The race case uses a stream wrapper (`mkdirrace://logs`) as log directory. Its `url_stat()` reports the directory missing until `mkdir()` is called, and `mkdir()` then fails although the directory exists, so the interleaving with the other request is the same on every run. Files below it are kept in memory.
- `FaultWrapper` (`faulty://logs`) puts a real directory behind a stream wrapper and fails the operations a case names: a short or empty append, a failed rollback, a short rotation copy, a failed rename, a failed truncation or a failed lock. Its `flock()` never locks the real file. `fsync()` is not available on a stream wrapper, so the rotation copy is synced only on plain files.
- `worker.php` runs with the same php.ini as `run.php` plus the case's `-d` settings. The out-of-memory case sets `max_memory_limit` to `memory_limit`, so the handler cannot raise the limit, and calls `gc_mem_caches()` before it fills memory, so the fatal hits a full heap. The trace case sets `zend.exception_ignore_args=0`, PHP's built-in default.
- The response cases run on PHP's built-in web server through `FixtureServer`, with `server.php` as router. The router installs the handler with the shipped baseline, templates included, behind one top-level output buffer, as `Kernel::run()` does.
- The kernel doubles in `tests/support/doubles.php` have no `CitOmni\Kernel\Arr`. The suite, the worker and the router alias a stand-in that delegates to `mergeLastWins()`.
