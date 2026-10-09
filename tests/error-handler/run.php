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

namespace CitOmni\Http\Tests\ErrorHandler;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\ErrorHandler;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\ErrorHandler: the directory its JSONL
 * logs are written to.
 *
 * Usage:
 *   php tests/error-handler/run.php
 *
 * Notes:
 * - Runs the real ErrorHandler against the kernel doubles, in a temporary
 *   CITOMNI_APP_PATH, so the default log directory is <root>/var/logs.
 * - Each case logs one E_USER_WARNING through handlePhpError() and reads the record
 *   back. The baseline render trigger is 0, so nothing is rendered and nothing exits.
 *   install() is never called, so the suite's own error handler stays in place.
 * - PHP's error_log goes to a file in the temporary root. A missing record fails with
 *   the last line written there, which is the reason writeJsonl() gave up.
 * - The temporary root is the working directory while the cases run, so a relative
 *   log directory cannot end up in the caller's tree.
 * - The race case uses a stream wrapper as log directory, so the interleaving of
 *   is_dir() and mkdir() with another request is deterministic.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic the production ErrorHandler would report; like
// that handler, leave diagnostics silenced with @ to PHP. writeJsonl() relies on
// @ for its expected filesystem failures.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// handlePhpError() ignores levels outside error_reporting().
\error_reporting(\E_ALL);

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__) . '/support/fixtures.php';

/** Stand-in for \CitOmni\Kernel\Arr, which the kernel doubles do not provide. */
final class ArrDouble {
	public static function mergeAssocLastWins(array $a, array $b): array {
		return mergeLastWins($a, $b);
	}
}

\class_alias(ArrDouble::class, 'CitOmni\Kernel\Arr');

/**
 * Stream wrapper for a log directory that another request creates first.
 *
 * Behavior:
 * - url_stat() reports the directory as missing until mkdir() is called.
 * - mkdir() loses the race: the directory exists afterwards, but the call fails,
 *   as PHP's mkdir() does when the directory already exists.
 * - Files below the directory are kept in memory and open only once it exists.
 */
final class MkdirRaceWrapper {
	public const DIR = 'mkdirrace://logs';

	/** @var resource|null Set by PHP on every wrapper instance. */
	public $context;

	public static bool $dirExists = false;
	public static int $mkdirCalls = 0;

	/** @var array<string, string> File contents by URL. */
	public static array $files = [];

	private string $url = '';

	public function mkdir(string $url, int $mode, int $options): bool {
		self::$mkdirCalls++;
		self::$dirExists = true;
		return false;
	}

	public function url_stat(string $url, int $flags): array|false {
		if ($url === self::DIR) {
			return self::$dirExists ? ['mode' => 0040775] : false;
		}
		return isset(self::$files[$url]) ? ['mode' => 0100664, 'size' => \strlen(self::$files[$url])] : false;
	}

	public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool {
		if (!self::$dirExists) {
			return false;
		}
		$this->url = $url;
		self::$files[$url] ??= '';
		return true;
	}

	public function stream_write(string $data): int {
		self::$files[$this->url] .= $data;
		return \strlen($data);
	}

	public function stream_lock(int $operation): bool {
		return true;
	}

	public function stream_flush(): bool {
		return true;
	}
}

foreach (['Boot/Registry', 'Service/ErrorHandler'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
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

/** Build the ErrorHandler from the shipped baseline plus overrides for cfg.error_handler. */
function handlerFor(array $cfg = [], array $options = []): ErrorHandler {
	return new ErrorHandler(new App(['error_handler' => mergeLastWins(Registry::CFG_HTTP['error_handler'], $cfg)]), $options);
}

/** The default log directory below the temporary root. */
function defaultLogDir(): string {
	return CITOMNI_APP_PATH . '/var/logs';
}

/** Remove <root>/var, so a case starts without the default log directory. */
function resetDefaultLogDir(): void {
	\clearstatcache();
	if (\is_dir(CITOMNI_APP_PATH . '/var')) {
		removeTree(CITOMNI_APP_PATH . '/var');
	}
}

/** Log one E_USER_WARNING with $message through handlePhpError(), starting with an empty error_log. */
function logWarning(ErrorHandler $handler, string $message): void {
	\file_put_contents(CITOMNI_APP_PATH . '/php-error.log', '');
	same(true, $handler->handlePhpError(\E_USER_WARNING, $message, __FILE__, __LINE__), 'handlePhpError() result');
}

/** Decoded records of http_err_phperror.jsonl in $dir. */
function phpErrorRecords(string $dir): array {
	$file = $dir . '/http_err_phperror.jsonl';
	\clearstatcache();
	if (!\is_file($file)) {
		throw new \RuntimeException("No log file {$file}" . lastErrorLogLine());
	}
	return decodeJsonl((string)\file_get_contents($file));
}

/** Decode JSONL text into a list of records. */
function decodeJsonl(string $jsonl): array {
	$records = [];
	foreach (\explode("\n", \rtrim($jsonl, "\n")) as $line) {
		$records[] = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
	}
	return $records;
}

/** Exactly one php_error record for the E_USER_WARNING logged with $message. */
function assertOneWarning(array $records, string $message): void {
	same(1, \count($records), 'record count');
	same('php_error', $records[0]['type'] ?? null, 'type');
	same(\E_USER_WARNING, $records[0]['errno'] ?? null, 'errno');
	same($message, $records[0]['message'] ?? null, 'message');
}

/** The last line PHP's error_log received since logWarning(), formatted for a failure message. */
function lastErrorLogLine(): string {
	$log = \trim((string)\file_get_contents(CITOMNI_APP_PATH . '/php-error.log'));
	if ($log === '') {
		return '';
	}
	$lines = \explode("\n", $log);
	return ' (error_log: ' . \end($lines) . ')';
}

$root = tempDir('error_handler');
\define('CITOMNI_APP_PATH', $root);
\ini_set('error_log', $root . '/php-error.log');
$cwd = \getcwd();
\chdir($root);

try {

	// -- 1. Log directory from error_handler.log.path ---------------------------

	check('An explicit log.path with a trailing slash is used', function (): void {
		resetDefaultLogDir();
		$dir = CITOMNI_APP_PATH . '/custom-logs';
		logWarning(handlerFor(['log' => ['path' => $dir . '/']]), 'explicit');
		assertOneWarning(phpErrorRecords($dir), 'explicit');
		same(false, \is_dir(defaultLogDir()), 'default directory created');
	});

	check('An empty or null log.path falls back to CITOMNI_APP_PATH/var/logs', function (): void {
		foreach (['empty' => '', 'null' => null] as $label => $path) {
			resetDefaultLogDir();
			logWarning(handlerFor(['log' => ['path' => $path]]), $label);
			assertOneWarning(phpErrorRecords(defaultLogDir()), $label);
		}
	});

	check('A whitespace-only log.path falls back to CITOMNI_APP_PATH/var/logs', function (): void {
		resetDefaultLogDir();
		logWarning(handlerFor(['log' => ['path' => " \t "]]), 'whitespace');
		assertOneWarning(phpErrorRecords(defaultLogDir()), 'whitespace');
	});

	check('An empty log.path service option wins over cfg and falls back to CITOMNI_APP_PATH/var/logs', function (): void {
		resetDefaultLogDir();
		$cfgDir = CITOMNI_APP_PATH . '/cfg-logs';
		logWarning(handlerFor(['log' => ['path' => $cfgDir]], ['log' => ['path' => '']]), 'option');
		assertOneWarning(phpErrorRecords(defaultLogDir()), 'option');
		same(false, \is_dir($cfgDir), 'cfg directory created');
	});

	check('Surrounding whitespace is trimmed from an explicit log.path', function (): void {
		resetDefaultLogDir();
		$dir = CITOMNI_APP_PATH . '/trimmed-logs';
		logWarning(handlerFor(['log' => ['path' => "  {$dir}/\n"]]), 'trimmed');
		assertOneWarning(phpErrorRecords($dir), 'trimmed');
	});

	// -- 2. Log directory creation ----------------------------------------------

	check('A log directory that another request creates first is still used', function (): void {
		// is_dir() finds no directory, then mkdir() fails because the other request
		// has created it in the meantime.
		\stream_wrapper_register('mkdirrace', MkdirRaceWrapper::class);
		try {
			logWarning(handlerFor(['log' => ['path' => MkdirRaceWrapper::DIR]]), 'race');
			same(1, MkdirRaceWrapper::$mkdirCalls, 'mkdir() calls');
			$file = MkdirRaceWrapper::DIR . '/http_err_phperror.jsonl';
			if (!isset(MkdirRaceWrapper::$files[$file])) {
				throw new \RuntimeException("No log file {$file}" . lastErrorLogLine());
			}
			assertOneWarning(decodeJsonl(MkdirRaceWrapper::$files[$file]), 'race');
		} finally {
			\stream_wrapper_unregister('mkdirrace');
		}
	});

} finally {
	if ($cwd !== false) {
		\chdir($cwd);
	}
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
