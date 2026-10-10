# TemplateEngine suite

Regression checks for `CitOmni\Http\Service\TemplateEngine`: syntax, inheritance and includes, template references, the compiled cache, the optional markup transformations, view variables and helpers, and differential checks against an earlier engine. No Composer, no database, no environment variables.

```
php tests/template-engine/run.php
```

Expected: `127 passed, 0 failed`

Where PHP cannot create symlinks, for example on Windows without the symlink privilege, the two symlink cases are skipped. Expected: `125 passed, 0 failed, 2 skipped`

The suite runs the real TemplateEngine against the doubles in `tests/bootstrap.php`. Templates, provider templates and the cache live in a temporary `CITOMNI_APP_PATH`, which the suite removes afterwards.

## Cases

- The public `render()` and `renderToString()` signatures.
- Syntax, 22 cases: escaped, raw and missing echoes, `set`, `if`/`elseif`/`else`, `foreach` with `continue` and `break` and their levels, inline and native PHP, unknown directives, standalone blocks and yields, template comments (unclosed, nested, stray close) and a controller `charset`. With `allow_php_tags` false, inline PHP is removed, ordinary echoes stay and native PHP still runs.
- Block content is inserted literally during inheritance: JavaScript regexes, `$1` and `\1` references, Windows and UNC paths, empty and whitespace-only blocks, binary and Unicode content.
- Inheritance: repeated and compact yields, hyphenated and numeric block names, yield cascades and multi-level inheritance across layers. Duplicate, orphan and missing blocks, circular inheritance and a chain of 65 `extends` are rejected.
- Includes: source inserted literally with shared scope, nested and repeated includes, references inside template comments that are never loaded, no phantom dependency from unused child source, whitespace variants of `extends` and `include`. Circular includes are rejected, and 16 nested includes render while 17 are rejected.
- Template references: a missing layer separator, an empty path or layer, an unknown layer, a missing source, NUL, directory traversal and a directory are rejected. The last `@` separates the layer, and a leading slash is dropped.
- Cache: warm renders notice an mtime moved backwards at an equal size, a size change at an equal mtime, changed includes and layouts, and deleted sources. Compile options and layer directories get their own cache identity, and service options override cfg. A corrupt manifest is rebuilt without being evaluated, a manifest generation outside the cache is not used, and a missing generation is rebuilt. Warm renders rewrite nothing, generations are content-addressed and reused for unchanged compiled bytes, the manifest holds one snapshot per logical dependency, a disabled cache picks up edits that keep size and mtime, the cache directory is created on first use, and a failed publication keeps the destination and removes its temporary file.
- Symlinks: a link out of the layer is rejected, and a retargeted link is noticed on a warm render.
- `trim_whitespace` and `remove_html_comments` leave PHP strings, line comments, heredoc and nowdoc, quoted attributes and the content of `pre`, `code`, `textarea`, `script` and `style` alone, also when a sensitive tag spans PHP or is never closed. Ordinary HTML comments are removed; conditional and unclosed ones stay.
- View variables: controller data wins over `view.vars`, dynamic providers (static method, instance and service) run on every render, also when controller data overrides their variable, and path-scoped `include` and `exclude` rules are evaluated per render.
- Helpers: the names and order of the template globals and the output of `url()` and `asset()`. Without their services, `csrfField()`, `captchaField()` and `captchaUrl()` return `''` and `hasIcon()` false, while `icon()`, `txt()`, `auth()` and `role()` throw. The captcha helpers share one challenge and follow the protection flag.
- The legacy local variables, `render()` output equal to `renderToString()`, the output buffer after a template exception, the `_viewvars` debug comment with hostile and cyclic payloads, and a filesystem root as layer root.
- Differential, against the baseline: every syntax case, 2000 deterministic inputs to `removeTemplateComments()`, and `compileSyntax()` on a grammar sample with `allow_php_tags` on and off.

## Comparing engines

```
php tests/template-engine/run.php [engine] [--baseline=<file>]
```

- `engine`, the first argument when it does not start with `--`, is the TemplateEngine.php under test. Default: `src/Service/TemplateEngine.php`.
- `--baseline` is the earlier TemplateEngine.php for the differential cases. Default: `tests/fixtures/TemplateEngine.literal-fixed.php.txt`, a copy of `src/Service/TemplateEngine.php` at 41a1fa0, the last engine before the rewrite in 8dd9722. The suite renames its class to `BaselineTemplateEngine` and loads it from the temporary root.
- A baseline file that does not exist stops the suite with exit code 2 before anything is created; an existing file without `final class TemplateEngine` ends it with a `RuntimeException`.

## Notes

- The suite used to be `tests/template-engine-regression.php`, which `tests/run.php` did not collect.
- `tests/template-engine-concurrency.php` (simultaneous compilation in worker processes) and `tests/template-engine-benchmark.php` (a timing comparison with the baseline) are run by hand; `tests/run.php` only collects `run.php` and `database.php`.
