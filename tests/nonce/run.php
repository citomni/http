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

namespace CitOmni\Http\Tests\Nonce;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Exception\NonceConfigException;
use CitOmni\Http\Service\Nonce;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Nonce: the filesystem ledger behind
 * webhook replay protection.
 *
 * Usage:
 *   php tests/nonce/run.php
 *   CITOMNI_TEST_PARALLEL=1 php tests/nonce/run.php    (also the multi-process case)
 *
 * Notes:
 * - Runs the real Nonce service against the kernel doubles. The ledger lives in a
 *   temporary CITOMNI_APP_PATH, so the shipped baseline dir (var/nonces) is used.
 * - Entries are aged with touch(); the ledger decides expiry by file mtime.
 * - worker.php is the worker process for the parallel case, not a suite.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic the production ErrorHandler would report; like
// that handler, leave diagnostics silenced with @ to PHP. Nonce relies on @ for
// its expected filesystem failures (an existing entry, an unwritable dir).
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__) . '/support/fixtures.php';
foreach (['Boot/Registry', 'Exception/NonceException', 'Exception/NonceConfigException', 'Service/Nonce'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

const TTL = 60;

$passed  = 0;
$failed  = 0;
$skipped = 0;

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

function skip(string $reason): void {
	global $skipped;
	$skipped++;
	\fwrite(\STDOUT, "SKIP {$reason}\n");
}

function same(mixed $expected, mixed $actual, string $label = ''): void {
	if ($expected !== $actual) {
		throw new \RuntimeException(($label !== '' ? $label . ': ' : '') . 'Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
	}
}

/** Run $fn and return what it throws; fail when it returns normally. */
function thrown(callable $fn): \Throwable {
	try {
		$fn();
	} catch (\Throwable $error) {
		return $error;
	}
	throw new \RuntimeException('Expected an exception; none was thrown');
}

/** Build the Nonce service from the shipped baseline plus overrides for cfg.nonce. */
function nonceFor(array $cfg = []): Nonce {
	return new Nonce(new App(['nonce' => mergeLastWins(Registry::CFG_HTTP['nonce'], $cfg)]));
}

/** Path of the entry for one nonce in the baseline ledger. */
function entry(string $namespace, string $nonce): string {
	return CITOMNI_APP_PATH . '/var/nonces/' . $namespace . '/' . \hash('sha256', $nonce) . '.nonce';
}

/** File names in one namespace of the baseline ledger, sorted. */
function ledger(string $namespace): array {
	\clearstatcache();
	$names = \array_values(\array_diff(\scandir(CITOMNI_APP_PATH . '/var/nonces/' . $namespace), ['.', '..']));
	\sort($names);
	return $names;
}

/** Make a ledger entry look $seconds old. */
function age(string $path, int $seconds): void {
	\touch($path, \time() - $seconds);
	\clearstatcache();
}

$root = tempDir('nonce');
\define('CITOMNI_APP_PATH', $root);

try {

	// -- 1. Single use --------------------------------------------------------

	check('First use is accepted and a replay within the TTL is rejected', function (): void {
		$nonce = nonceFor();
		same(true, $nonce->checkAndStore('webhooks', 'n-1', TTL));
		same(false, $nonce->checkAndStore('webhooks', 'n-1', TTL));
		same(false, nonceFor()->checkAndStore('webhooks', 'n-1', TTL), 'fresh service instance');
		// Only the hash names the entry; the raw nonce is never part of a path.
		same([\hash('sha256', 'n-1') . '.nonce'], ledger('webhooks'));
	});

	check('Namespaces keep separate ledgers', function (): void {
		$nonce = nonceFor();
		same(true, $nonce->checkAndStore('ns-a', 'shared', TTL));
		same(true, $nonce->checkAndStore('ns-b', 'shared', TTL));
		same(false, $nonce->checkAndStore('ns-a', 'shared', TTL));
	});

	check('An entry as old as the TTL has expired and is reaped on reuse', function (): void {
		$nonce = nonceFor();
		same(true, $nonce->checkAndStore('expiry', 'n-2', TTL));

		age(entry('expiry', 'n-2'), TTL / 2);
		same(false, $nonce->checkAndStore('expiry', 'n-2', TTL), 'younger than the TTL');

		age(entry('expiry', 'n-2'), TTL);
		same(true, $nonce->checkAndStore('expiry', 'n-2', TTL), 'as old as the TTL');
		same(false, $nonce->checkAndStore('expiry', 'n-2', TTL), 'stored again on reuse');
	});


	// -- 2. Input contract ----------------------------------------------------

	check('Malformed input is rejected without creating any storage', function (): void {
		$dir = CITOMNI_APP_PATH . '/malformed';
		$nonce = nonceFor(['dir' => $dir]);
		$cases = [
			['ns', 'n', 0], ['ns', 'n', -1],
			['ns', '', TTL], ['ns', 'a/b', TTL], ['ns', '../x', TTL], ['ns', 'a b', TTL], ['ns', "a\0b", TTL], ['ns', "\u{e6}", TTL], ['ns', \str_repeat('a', 129), TTL],
			['', 'n', TTL], ['../x', 'n', TTL], ['a.b', 'n', TTL], ['a/b', 'n', TTL], [\str_repeat('a', 65), 'n', TTL],
		];
		foreach ($cases as [$namespace, $value, $ttl]) {
			same(false, $nonce->checkAndStore($namespace, $value, $ttl), \var_export([$namespace, $value, $ttl], true));
		}
		same(false, \is_dir($dir));
	});

	check('Nonces are URL-safe identifiers of at most max_len bytes', function (): void {
		$nonce = nonceFor();
		foreach (['0123abcdef', 'AbC-_x', '123e4567-e89b-12d3-a456-426614174000', 'urn:x:1.2', \str_repeat('a', 128)] as $value) {
			same(true, $nonce->checkAndStore('charset', $value, TTL), $value);
		}
		same(true, nonceFor()->checkAndStore(\str_repeat('n', 64), 'longest-namespace', TTL));

		$short = nonceFor(['max_len' => 8]);
		same(true, $short->checkAndStore('charset', '12345678', TTL));
		same(false, $short->checkAndStore('charset', '123456789', TTL));
	});

	check('A ledger directory that cannot be created yields false, not an exception', function (): void {
		\file_put_contents(CITOMNI_APP_PATH . '/plain-file', '');
		same(false, nonceFor(['dir' => CITOMNI_APP_PATH . '/plain-file/nonces'])->checkAndStore('ns', 'n', TTL));
	});


	// -- 3. Cleanup -----------------------------------------------------------

	check('purgeExpired() removes only expired ledger entries, at most $max', function (): void {
		$nonce = nonceFor();
		foreach (['old-1', 'old-2', 'fresh'] as $value) {
			$nonce->checkAndStore('purge', $value, TTL);
		}
		\file_put_contents(CITOMNI_APP_PATH . '/var/nonces/purge/keep.txt', 'not a ledger entry');
		age(entry('purge', 'old-1'), TTL);
		age(entry('purge', 'old-2'), TTL);
		age(CITOMNI_APP_PATH . '/var/nonces/purge/keep.txt', TTL);

		same(2, $nonce->purgeExpired('purge', TTL));
		$expected = [\hash('sha256', 'fresh') . '.nonce', 'keep.txt'];
		\sort($expected);
		same($expected, ledger('purge'));

		// Age the entries only after the last store: a store may purge expired entries.
		foreach (['m-1', 'm-2', 'm-3'] as $value) {
			$nonce->checkAndStore('purge-max', $value, TTL);
		}
		foreach (['m-1', 'm-2', 'm-3'] as $value) {
			age(entry('purge-max', $value), TTL);
		}
		same(2, $nonce->purgeExpired('purge-max', TTL, 2));
		same(1, \count(ledger('purge-max')));

		same(0, $nonce->purgeExpired('purge-max', 0));
		same(0, $nonce->purgeExpired('purge-max', TTL, 0));
		same(0, $nonce->purgeExpired('../purge', TTL));
		same(0, $nonce->purgeExpired('never-used', TTL));
	});

	check('With purge_probability 1 every store purges expired entries in its own namespace', function (): void {
		$nonce = nonceFor(['purge_probability' => 1]);
		foreach (['p-1', 'p-2'] as $value) {
			$nonce->checkAndStore('purge-p', $value, TTL);
		}
		$nonce->checkAndStore('purge-q', 'q-1', TTL);
		age(entry('purge-p', 'p-1'), TTL);
		age(entry('purge-p', 'p-2'), TTL);
		age(entry('purge-q', 'q-1'), TTL);

		same(true, $nonce->checkAndStore('purge-p', 'p-3', TTL));
		same([\hash('sha256', 'p-3') . '.nonce'], ledger('purge-p'));
		same([\hash('sha256', 'q-1') . '.nonce'], ledger('purge-q'));
	});


	// -- 4. Configuration -----------------------------------------------------

	check('Invalid configuration fails at construction', function (): void {
		foreach ([['dir' => ''], ['dir' => '   '], ['dir' => "var\0nonces"], ['max_len' => 7], ['max_len' => 1025], ['purge_probability' => 0], ['purge_limit' => 0]] as $cfg) {
			$error = thrown(static fn () => nonceFor($cfg));
			same(NonceConfigException::class, $error::class, \var_export($cfg, true));
		}
		nonceFor(['max_len' => 8]);
		nonceFor(['max_len' => 1024]);
	});


	// -- 5. Concurrency -------------------------------------------------------

	if (\getenv('CITOMNI_TEST_PARALLEL') !== '1') {
		skip('concurrent first uses of one nonce: set CITOMNI_TEST_PARALLEL=1 to run multi-process checks');
	} else {
		check('Concurrent first uses of one nonce: exactly one process is accepted', function (): void {
			$iniArgs = match (true) {
				\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
				\php_ini_scanned_files() !== false => [],
				default                            => ['-n'],
			};
			// The ledger root exists, so workers contend only for the namespace and the entry.
			$dir = CITOMNI_APP_PATH . '/race';
			\mkdir($dir);
			// Workers spin until this moment, then all claim the same nonce.
			$startAt = \sprintf('%.6F', \microtime(true) + 1.0);

			$workers = [];
			for ($i = 0; $i < 8; $i++) {
				$process = \proc_open([\PHP_BINARY, ...$iniArgs, __DIR__ . '/worker.php', $dir, $startAt], [
					0 => ['pipe', 'r'],
					1 => ['pipe', 'w'],
					2 => ['file', 'php://stderr', 'w'],
				], $pipes);
				if (!\is_resource($process)) {
					throw new \RuntimeException('Cannot start worker ' . $i);
				}
				\fclose($pipes[0]);
				$workers[] = [$process, $pipes[1]];
			}

			$results = [];
			foreach ($workers as [$process, $stdout]) {
				$results[] = \trim((string)\stream_get_contents($stdout));
				\fclose($stdout);
				same(0, \proc_close($process), 'worker exit code');
			}
			\sort($results);
			same(['accepted', 'rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'rejected'], $results);
		});
	}

} finally {
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
