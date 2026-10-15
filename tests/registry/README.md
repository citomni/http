# Registry suite

Isolated checks for the cfg reads in `src/` against `Registry::CFG_HTTP`. No Composer, no kernel doubles, no database, no environment variables.

```
php tests/registry/run.php
```

Expected: `6 passed, 0 failed`

The suite loads `src/Boot/Registry.php` and scans `src/` with the tokenizer, so commented-out code is ignored. It follows `->cfg->...` chains, also through a local variable assigned from one in the same function, with or without a `??` fallback. Every class in `src/` runs in HTTP mode, so `CFG_HTTP` is the baseline for all of them.

The package owns a path when its root is in `CFG_HTTP`. `security` is shared with other packages, so below `security` the package owns only the keys `CFG_HTTP` ships there.

## Cases

- The scan finds known reads, so a change in the code style cannot make it pass blind.
- Cfg is read with property syntax. Array syntax on a Cfg node (`$this->app->cfg['http']`) fails, because the scan cannot follow it.
- Package-owned cfg is read without fallbacks: no `??`, `isset()`, `empty()` or `?->` on such a read, directly or through a local variable.
- Every package-owned path read in `src/` exists in `CFG_HTTP`.
- Cfg the package does not own, such as `locale.charset` and `identity.app_name`, is read with a fallback.
- Every documented guard is still a guarded read in `src/`, so the exception list cannot outlive its code.

## Documented guards

`DOCUMENTED_GUARDS` in `run.php` lists the guarded reads of own cfg that the reading code explains. Both run before dispatch, so a compiled config from before the key existed would otherwise block the reset-cache webhook that rebuilds it:

- `Kernel.php security.fetch_metadata`: `Kernel::guardFetchMetadata()` treats a missing node as off.
- `Kernel.php http.base_url`: `Kernel::definePublicRootUrl()` treats a missing key as empty.

## Not covered

- Reads from arrays: `ErrorHandler` merges `error_handler` with its options into an array and reads that, `Cookie` and `Session` read `toArray()` results, and `Session::refuseRemovedOptions()` checks with `??` for keys the baseline dropped on purpose.
- A chain ends at a method call (`->toArray()`) and at a dynamic property name. A named function starts a new scope; a closure body keeps the enclosing one. Variables are not followed across functions or through arrays, and a cfg node held in a property is not followed at all.
- A `??` after a closing parenthesis (`($cfg->port) ?? 1`) is not seen, so that read counts as unguarded. `?:` is no guard, since the read still throws on a missing key.
- Every `->cfg` property counts as App cfg.
