# Maintenance suite

Isolated checks for the backups `CitOmni\Http\Service\Maintenance` writes when `enable()` and `disable()` replace the flag: the policy from `maintenance.backup.*`, the backup names and the pruning. No Composer, no database, no environment variables.

```
php tests/maintenance/run.php
```

Expected: `11 passed, 0 failed`

The suite runs in a temporary `CITOMNI_APP_PATH`, so the baseline flag is `var/flags/maintenance.php` and the baseline backup directory is `var/backups/flags` below it, and removes it afterwards. Cases write the flag through `disable()`, one also through `enable()`. The first write creates the flag; each later write backs up the flag it replaces, as far as the policy allows. Every write gets its own `retry_after`, so a backup's content shows which flag it holds.

## Cases

Policy:

- The baseline policy backs up the replaced flag in `var/backups/flags`, with the replaced flag's content.
- `backup.enabled` false writes no backup, and the backup directory is not created. The flag is still written.
- `backup.keep` 0 or -1 writes no backup.
- `backup.keep` 2 keeps the backups of the two newest replaced flags.
- `backup.dir` with a trailing slash receives the backup, and `var/backups/flags` is not created.
- `backup.dir` is used as configured: `/`, `C:\` and a path with a trailing slash come back unchanged from `resolveBackupPolicy()`.
- A `backup.dir` that is `''`, null, false or a list makes `disable()` throw `UnexpectedValueException` before anything is written: `var` is not created.

Names and pruning:

- Backup names end with `_<6-digit microseconds>_<12 hex digits>.bak`, and sorting them by name orders the backups by time.
- With `keep` 1, pruning deletes an older-format backup (`_<6 digits>.bak`) and the older of two new ones, and keeps `maintenance.php.keep-me.txt`, `maintenance.php.manual.bak` and a `.bak.orig` file, although they start with the same prefix and are older.
- `enable()` applies the policy as `disable()` does: with `keep` 1, enable, disable and enable leave one backup, the disabled flag.

Missing keys:

- A `backup` node that a cfg layer replaces with `[]` makes `disable()` throw `OutOfBoundsException` for `enabled` before the flag is replaced, and no backup is written.

## Regression proof

- Against `Maintenance` before this change, every case but the baseline fails: `1 passed, 10 failed`. `resolveBackupPolicy()` tested the keys with `property_exists()`, which never finds them on a `Cfg` node, so every write used enabled, keep 3 and `var/backups/flags`. Backup names had neither microseconds nor nonce, and pruning deleted `maintenance.php.keep-me.txt`.

## Notes

- Writes are 1 ms apart. Names from before microseconds and nonce stop at 0.1 ms, so they do not collide when the suite runs against that code.
- `MaintenanceProbe` exposes `resolveBackupPolicy()` for the case with filesystem roots, where nothing may be written.
- A request double answers `ip()` for `enable()`. No log service is registered, so `logToggle()` writes nothing.
