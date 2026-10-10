<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Http\Tests\Maintenance;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\Maintenance;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Maintenance: the backup policy that
 * maintenance.backup.* sets for the flag writes of enable() and disable(), and the
 * names and pruning of the backups.
 *
 * Usage:
 *   php tests/maintenance/run.php
 *
 * Notes:
 * - Runs the real Maintenance service against the kernel doubles, in a temporary
 *   CITOMNI_APP_PATH, so the baseline flag is <root>/var/flags/maintenance.php and
 *   the baseline backup directory is <root>/var/backups/flags.
 * - Cases write the flag through disable(), one also through enable(), which asks a
 *   request double for the client IP. The first write creates the flag; each later
 *   write backs up the flag it replaces, as far as the policy allows. Each write gets
 *   its own retry_after, so a backup's content shows which flag it holds.
 * - Writes are 1 ms apart. The backup names from before microseconds and nonce stop
 *   at 0.1 ms, so they do not collide when the suite runs against that code.
 * - MaintenanceProbe exposes resolveBackupPolicy() for the case with filesystem
 *   roots, where nothing may be written.
 * - No log service is registered, so logToggle() writes nothing.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic the production ErrorHandler would report; like
// that handler, leave diagnostics silenced with @ to PHP. writeFlagFile() relies
// on @ for mkdir() races and best-effort chmod().
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

\error_reporting(\E_ALL);

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__) . '/support/fixtures.php';

foreach (['Boot/Registry', 'Service/Maintenance'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

/** A backup name as writeFlagFile() writes it for the baseline flag. */
const BACKUP_NAME = '/^maintenance\.php\.\d{8}_\d{6}_\d{6}_[0-9a-f]{12}\.bak$/D';

/** Request double: enable() asks only for the client IP. */
final class RequestDouble {
	public function ip(): ?string {
		return '203.0.113.7';
	}
}

/** Maintenance with resolveBackupPolicy() exposed; the probe writes nothing itself. */
final class MaintenanceProbe extends Maintenance {
	public function backupPolicy(): array {
		return $this->resolveBackupPolicy();
	}
}

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void {
	global $passed, $failed;
	try {
		$test();
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (\Throwable $error) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $error->getMessage() . "\n");
	}
}

function same(mixed $expected, mixed $actual, string $label = ''): void {
	if ($expected !== $actual) {
		throw new \RuntimeException(($label !== '' ? $label . ': ' : '') . 'Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
	}
}

/** Build Maintenance from the shipped baseline plus overrides for cfg.maintenance. */
function maintenanceFor(array $cfg = []): MaintenanceProbe {
	$app = new App(['maintenance' => mergeLastWins(Registry::CFG_HTTP['maintenance'], $cfg)]);
	$app->set('request', new RequestDouble());
	return new MaintenanceProbe($app);
}

/** The baseline flag file below the temporary root. */
function flagPath(): string {
	return CITOMNI_APP_PATH . '/var/flags/maintenance.php';
}

/** The baseline backup directory below the temporary root. */
function defaultBackupDir(): string {
	return CITOMNI_APP_PATH . '/var/backups/flags';
}

/** Remove <root>/var, so a case starts without flag and backups. */
function resetVar(): void {
	\clearstatcache();
	if (\is_dir(CITOMNI_APP_PATH . '/var')) {
		removeTree(CITOMNI_APP_PATH . '/var');
	}
}

/** Write one flag per value through disable(), 1 ms apart, each with that retry_after. */
function writeFlags(Maintenance $maintenance, int ...$retryAfter): void {
	foreach ($retryAfter as $i => $seconds) {
		if ($i > 0) {
			\usleep(1000);
		}
		$maintenance->setRetryAfter($seconds)->disable();
	}
}

/** Files named like flag backups in $dir, sorted by name; [] when $dir does not exist. */
function backups(string $dir): array {
	\clearstatcache();
	if (!\is_dir($dir)) {
		return [];
	}
	$files = \glob($dir . '/maintenance.php.*.bak') ?: [];
	\sort($files);
	return $files;
}

/** The array a flag file or backup returns. */
function flagData(string $file): array {
	return include $file;
}

/** retry_after of each file, in the order given. */
function retryAfterOf(array $files): array {
	return \array_map(static fn (string $file): int => flagData($file)['retry_after'], $files);
}

$root = tempDir('maintenance');
\define('CITOMNI_APP_PATH', $root);

try {

	// -- 1. Baseline policy -----------------------------------------------------

	check('The baseline backs up the replaced flag in CITOMNI_APP_PATH/var/backups/flags', function (): void {
		resetVar();
		$maintenance = maintenanceFor();
		writeFlags($maintenance, 1);
		same([], backups(defaultBackupDir()), 'backups after the first write');
		$replaced = (string)\file_get_contents(flagPath());

		writeFlags($maintenance, 2);
		$files = backups(defaultBackupDir());
		same(1, \count($files), 'backup count');
		same($replaced, (string)\file_get_contents($files[0]), 'backup content');
	});

	// -- 2. Policy from maintenance.backup.* ------------------------------------

	check('backup.enabled false writes no backup', function (): void {
		resetVar();
		writeFlags(maintenanceFor(['backup' => ['enabled' => false]]), 1, 2, 3);
		same(3, flagData(flagPath())['retry_after'], 'flag');
		same(false, \is_dir(defaultBackupDir()), 'backup directory created');
	});

	check('backup.keep 0 or below writes no backup', function (): void {
		foreach (['zero' => 0, 'negative' => -1] as $label => $keep) {
			resetVar();
			writeFlags(maintenanceFor(['backup' => ['keep' => $keep]]), 1, 2, 3);
			same(false, \is_dir(defaultBackupDir()), $label . ': backup directory created');
		}
	});

	check('backup.keep 2 keeps the two newest backups', function (): void {
		resetVar();
		writeFlags(maintenanceFor(['backup' => ['keep' => 2]]), 1, 2, 3, 4);
		same([2, 3], retryAfterOf(backups(defaultBackupDir())), 'backed-up retry_after');
	});

	check('backup.dir with a trailing slash receives the backups', function (): void {
		resetVar();
		$dir = CITOMNI_APP_PATH . '/var/custom-backups';
		writeFlags(maintenanceFor(['backup' => ['dir' => $dir . '/']]), 1, 2);
		same([1], retryAfterOf(backups($dir)), 'backed-up retry_after');
		same(false, \is_dir(defaultBackupDir()), 'default backup directory created');
	});

	check('backup.dir is used as configured, filesystem roots included', function (): void {
		foreach (['/', 'C:\\', '/srv/backups/'] as $dir) {
			same($dir, maintenanceFor(['backup' => ['dir' => $dir]])->backupPolicy()['dir'], $dir);
		}
	});

	check('A backup.dir that is not a non-empty string throws before anything is written', function (): void {
		foreach (['empty' => '', 'null' => null, 'false' => false, 'list' => ['var/backups']] as $label => $dir) {
			resetVar();
			$message = null;
			try {
				maintenanceFor(['backup' => ['dir' => $dir]])->disable();
			} catch (\UnexpectedValueException $e) {
				$message = $e->getMessage();
			}
			same('Config maintenance.backup.dir must be a non-empty string.', $message, $label . ': exception');
			same(false, \is_dir(CITOMNI_APP_PATH . '/var'), $label . ': var created');
		}
	});

	// -- 3. Backup names and pruning --------------------------------------------

	check('Backup names carry microseconds and a nonce, and sort by time', function (): void {
		resetVar();
		writeFlags(maintenanceFor(), 1, 2, 3);
		$files = backups(defaultBackupDir());
		foreach ($files as $file) {
			same(1, \preg_match(BACKUP_NAME, \basename($file)), \basename($file));
		}
		same([1, 2], retryAfterOf($files), 'retry_after in name order');
	});

	check('Pruning removes older-format backups and leaves other files alone', function (): void {
		resetVar();
		$dir = defaultBackupDir();
		\mkdir($dir, 0755, true);
		$foreign = ['maintenance.php.keep-me.txt', 'maintenance.php.manual.bak', 'maintenance.php.20200101_000000_123456.bak.orig'];
		$older = 'maintenance.php.20200101_000000_123456.bak';
		foreach ([...$foreign, $older] as $name) {
			\file_put_contents($dir . '/' . $name, '<?php return [];');
			\touch($dir . '/' . $name, \time() - 60);
		}

		writeFlags(maintenanceFor(['backup' => ['keep' => 1]]), 1, 2, 3);
		foreach ($foreign as $name) {
			same(true, \is_file($dir . '/' . $name), $name . ' kept');
		}
		same(false, \is_file($dir . '/' . $older), $older . ' kept');
		$generated = \array_values(\array_filter(\scandir($dir), static fn (string $name): bool => \preg_match(BACKUP_NAME, $name) === 1));
		same(1, \count($generated), 'generated backups');
		same([2], retryAfterOf([$dir . '/' . $generated[0]]), 'backed-up retry_after');
	});

	check('enable() applies the backup policy as disable() does', function (): void {
		resetVar();
		$maintenance = maintenanceFor(['backup' => ['keep' => 1]]);
		$maintenance->enable([]);
		\usleep(1000);
		$maintenance->disable();
		\usleep(1000);
		$maintenance->enable([]);

		$files = backups(defaultBackupDir());
		same(1, \count($files), 'backup count');
		same(false, flagData($files[0])['enabled'], 'backed-up flag enabled');
		$flag = flagData(flagPath());
		same(true, $flag['enabled'], 'flag enabled');
		same(['203.0.113.7'], $flag['allowed_ips'], 'flag allowed_ips');
	});

	// -- 4. Missing policy keys -------------------------------------------------

	check('A backup node emptied by a cfg layer throws before the flag is replaced', function (): void {
		resetVar();
		writeFlags(maintenanceFor(), 1);
		$before = (string)\file_get_contents(flagPath());

		// The kernel merge replaces an associative node with an empty array.
		$message = null;
		try {
			maintenanceFor(['backup' => []])->setRetryAfter(900)->disable();
		} catch (\OutOfBoundsException $e) {
			$message = $e->getMessage();
		}
		same("Unknown cfg key: 'enabled'", $message, 'exception');
		same($before, (string)\file_get_contents(flagPath()), 'flag content');
		same([], backups(defaultBackupDir()), 'backups');
	});

} finally {
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
