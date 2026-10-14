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
use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\headerValues;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\ErrorHandler and its default error template:
 * where and how the JSONL logs are written, rotated and pruned, what a response carries,
 * and how the handler ends a request.
 *
 * Usage:
 *   php tests/error-handler/run.php
 *
 * Notes:
 * - Runs the real ErrorHandler against the kernel doubles, in a temporary
 *   CITOMNI_APP_PATH, so the default log directory is <root>/var/logs.
 * - Log cases call handlePhpError() with an E_USER_WARNING and read the record back. The
 *   baseline render trigger is 0, so nothing is rendered and nothing exits. install() is
 *   never called in this process, so the suite's own error handler stays in place.
 * - FaultWrapper puts a real directory behind faulty://logs and fails the operations a case
 *   names, so every failure path of writeJsonl() and rotate() runs on demand.
 * - Cases that end the process run in worker.php, with the same php.ini as this process plus
 *   their own -d settings. The re-entry case runs this file again as a worker:
 *   php run.php --reentry <log dir>.
 * - Response cases run on PHP's built-in web server, with server.php as router.
 * - PHP's error_log goes to a file in the temporary root and is emptied before each logged
 *   warning. A missing record fails with the last line written there, which is the reason
 *   writeJsonl() gave up.
 * - The temporary root is the working directory while the cases run, so a relative
 *   log directory cannot end up in the caller's tree.
 * - The race case uses a stream wrapper as log directory, so the interleaving of
 *   is_dir() and mkdir() with another request is deterministic.
 * - The multi-process check runs only with CITOMNI_TEST_PARALLEL=1.
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

/**
 * Stream wrapper that puts a real directory behind faulty://logs and fails chosen operations.
 *
 * Behavior:
 * - faulty://logs/<name> is <FaultWrapper::$root>/<name>. Every operation goes to the real
 *   file, except the ones FaultWrapper::$faults names:
 *     - append-short: an append handle ('ab') takes 10 bytes of a write, then nothing,
 *     - append-none: an append handle takes nothing,
 *     - rollback: an append handle takes 10 bytes, then nothing, and cannot be truncated,
 *     - copy-short: a rotation copy ('xb' or 'wb') takes 10 bytes, then nothing,
 *     - rename: rename() fails,
 *     - truncate: the live file opened for rotation ('c+b') cannot be truncated,
 *     - lock: flock() fails.
 * - flock() never locks the real file; the cases run in one process.
 */
final class FaultWrapper {
	public const DIR = 'faulty://logs';

	/** @var resource|null Set by PHP on every wrapper instance. */
	public $context;

	/** Real directory behind faulty://logs. */
	public static string $root = '';

	/** @var list<string> Operations to fail. */
	public static array $faults = [];

	/** @var resource|null Real file handle. */
	private $handle = null;

	/** @var resource|null Real directory handle. */
	private $dir = null;

	private string $mode = '';

	/** Whether a short write has used up what this handle takes. */
	private bool $full = false;

	private static function path(string $url): string {
		return self::$root . \substr($url, \strlen(self::DIR));
	}

	private static function fails(string $fault): bool {
		return \in_array($fault, self::$faults, true);
	}

	public function url_stat(string $url, int $flags): array|false {
		$path = self::path($url);
		return \file_exists($path) ? \stat($path) : false;
	}

	public function mkdir(string $url, int $mode, int $options): bool {
		return \mkdir(self::path($url), $mode, ($options & \STREAM_MKDIR_RECURSIVE) !== 0);
	}

	public function rename(string $from, string $to): bool {
		return !self::fails('rename') && \rename(self::path($from), self::path($to));
	}

	public function unlink(string $url): bool {
		return \unlink(self::path($url));
	}

	public function stream_metadata(string $url, int $option, mixed $value): bool {
		return $option === \STREAM_META_ACCESS && \chmod(self::path($url), (int)$value);
	}

	public function dir_opendir(string $url, int $options): bool {
		$dir = \opendir(self::path($url));
		$this->dir = ($dir === false) ? null : $dir;
		return $dir !== false;
	}

	public function dir_readdir(): string|false {
		return \readdir($this->dir);
	}

	public function dir_rewinddir(): bool {
		\rewinddir($this->dir);
		return true;
	}

	public function dir_closedir(): bool {
		\closedir($this->dir);
		return true;
	}

	public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool {
		$handle = @\fopen(self::path($url), $mode);
		if ($handle === false) {
			return false;
		}
		$this->handle = $handle;
		$this->mode = $mode;
		return true;
	}

	public function stream_write(string $data): int {
		$append = $this->mode === 'ab';
		$short = ($append && (self::fails('append-short') || self::fails('rollback')))
			|| (($this->mode === 'xb' || $this->mode === 'wb') && self::fails('copy-short'));
		if (($append && self::fails('append-none')) || ($short && $this->full)) {
			return 0;
		}
		if ($short) {
			$this->full = true;
			$data = \substr($data, 0, 10);
		}
		return (int)\fwrite($this->handle, $data);
	}

	public function stream_read(int $count): string|false {
		return \fread($this->handle, $count);
	}

	public function stream_eof(): bool {
		return \feof($this->handle);
	}

	public function stream_seek(int $offset, int $whence): bool {
		return \fseek($this->handle, $offset, $whence) === 0;
	}

	public function stream_tell(): int {
		return (int)\ftell($this->handle);
	}

	public function stream_stat(): array|false {
		return \fstat($this->handle);
	}

	public function stream_truncate(int $size): bool {
		if (($this->mode === 'ab' && self::fails('rollback')) || ($this->mode === 'c+b' && self::fails('truncate'))) {
			return false;
		}
		return \ftruncate($this->handle, $size);
	}

	public function stream_lock(int $operation): bool {
		return !self::fails('lock');
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	public function stream_close(): void {
		\fclose($this->handle);
	}
}

foreach (['Boot/Registry', 'Service/ErrorHandler'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

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

function same(mixed $expected, mixed $actual, string $label = ''): void {
	if ($expected !== $actual) {
		throw new \RuntimeException(($label !== '' ? $label . ': ' : '') . 'Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
	}
}

function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}


// ----------------------------------------------------------------
// Handlers and log files
// ----------------------------------------------------------------

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

/** Decode JSONL text into a list of records; empty text has none. A line that is not JSON throws. */
function decodeJsonl(string $jsonl): array {
	$records = [];
	if ($jsonl === '') {
		return $records;
	}
	foreach (\explode("\n", \rtrim($jsonl, "\n")) as $line) {
		$records[] = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
	}
	return $records;
}

/** Decoded records of a JSONL file; none when it does not exist. */
function records(string $file): array {
	\clearstatcache();
	return \is_file($file) ? decodeJsonl((string)\file_get_contents($file)) : [];
}

/** Exactly one php_error record for the E_USER_WARNING logged with $message. */
function assertOneWarning(array $records, string $message): void {
	same(1, \count($records), 'record count');
	same('php_error', $records[0]['type'] ?? null, 'type');
	same(\E_USER_WARNING, $records[0]['errno'] ?? null, 'errno');
	same($message, $records[0]['message'] ?? null, 'message');
}

/** What PHP's error_log received since logWarning(). */
function errorLog(): string {
	return \trim((string)\file_get_contents(CITOMNI_APP_PATH . '/php-error.log'));
}

/** The last line PHP's error_log received since logWarning(), formatted for a failure message. */
function lastErrorLogLine(): string {
	$log = errorLog();
	if ($log === '') {
		return '';
	}
	$lines = \explode("\n", $log);
	return ' (error_log: ' . \end($lines) . ')';
}

/** Fail unless PHP's error_log received a line containing $text. */
function expectErrorLog(string $text): void {
	expect(\str_contains(errorLog(), $text), "error_log lacks '{$text}': " . \var_export(errorLog(), true));
}

/** $count valid JSONL records of about 200 bytes each. */
function seedLines(int $count): string {
	$out = '';
	for ($i = 1; $i <= $count; $i++) {
		$out .= \json_encode(['seed' => $i, 'pad' => \str_repeat('s', 180)]) . "\n";
	}
	return $out;
}

/**
 * Start a case on a fresh faulty://logs: an empty real directory, http_err_phperror.jsonl seeded
 * with $records records, and $faults failing from now on.
 */
function faulty(array $faults, int $records = 0): void {
	static $n = 0;
	FaultWrapper::$faults = [];
	FaultWrapper::$root = CITOMNI_APP_PATH . '/faulty-' . ++$n;
	\mkdir(FaultWrapper::$root);
	if ($records > 0) {
		\file_put_contents(FaultWrapper::$root . '/http_err_phperror.jsonl', seedLines($records));
	}
	FaultWrapper::$faults = $faults;
}

/** A handler that logs to faulty://logs, with $log on top of the baseline log node. */
function faultyHandler(array $log = []): ErrorHandler {
	return handlerFor(['log' => ['path' => FaultWrapper::DIR] + $log]);
}

/** The real live log behind faulty://logs. */
function faultyLive(): string {
	return FaultWrapper::$root . '/http_err_phperror.jsonl';
}

/**
 * Rotations of http_err_phperror.jsonl in $dir.
 *
 * @return list<string>  File names, sorted.
 */
function rotations(string $dir, string $base = 'http_err_phperror'): array {
	\clearstatcache();
	$names = \array_values(\preg_grep('/^' . \preg_quote($base, '/') . '\.\d{8}T.*\.jsonl$/', \scandir($dir)));
	\sort($names);
	return $names;
}

/**
 * Temporary files a rotation left in $dir.
 *
 * @return list<string>
 */
function tmpFiles(string $dir): array {
	return \array_values(\preg_grep('/\.tmp$/', \scandir($dir)));
}


// ----------------------------------------------------------------
// Child processes
// ----------------------------------------------------------------

/**
 * Command-line arguments for the same php.ini as this process: the loaded file, the default lookup, or none at all.
 *
 * @return list<string>
 */
function iniArgs(): array {
	return match (true) {
		\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
		\php_ini_scanned_files() !== false => [],
		default                            => ['-n'],
	};
}

/**
 * Run one worker.php case to completion; logs land in $dir/logs.
 *
 * @param  array<string, string>  $ini  -d settings on top of this process's php.ini.
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runWorker(string $case, string $dir, string $arg = '', array $ini = []): array {
	$process = startWorker($case, $dir, $arg, $ini, 'worker');
	$exit = \proc_close($process);
	return [
		'exit'   => $exit,
		'stdout' => (string)\file_get_contents($dir . '/worker.out'),
		'stderr' => (string)\file_get_contents($dir . '/worker.err'),
	];
}

/**
 * Start one worker.php case without waiting; stdout and stderr go to $dir/<tag>.out and .err.
 *
 * @param  array<string, string>  $ini  -d settings on top of this process's php.ini.
 * @return resource  Process handle for proc_close().
 */
function startWorker(string $case, string $dir, string $arg, array $ini, string $tag) {
	$cmd = [\PHP_BINARY, ...iniArgs()];
	foreach ($ini as $key => $value) {
		\array_push($cmd, '-d', $key . '=' . $value);
	}
	\array_push($cmd, __DIR__ . '/worker.php', $case, $dir, $arg);
	$process = \proc_open($cmd, [
		0 => ['pipe', 'r'],
		1 => ['file', $dir . '/' . $tag . '.out', 'w'],
		2 => ['file', $dir . '/' . $tag . '.err', 'w'],
	], $pipes);
	if (!\is_resource($process)) {
		throw new \RuntimeException("Cannot start worker case {$case}.");
	}
	\fclose($pipes[0]);
	return $process;
}

/** A new, empty directory below the temporary root. */
function caseDir(string $name): string {
	$dir = CITOMNI_APP_PATH . '/' . $name;
	\mkdir($dir);
	return $dir;
}

/**
 * Worker for the re-entry case: Call httpError(404) with a shutdown function that calls
 * httpError(403) and then prints "continued". The 404 call renders and exits, and exit()
 * skips its finally block, so the shutdown function runs while the handler run is still
 * in progress. Stdout is the 404 response alone when the second call does not return.
 */
function reentryWorker(string $dir): never {
	if (!\is_dir($dir)) {
		throw new \RuntimeException('Usage: run.php --reentry <log dir>');
	}
	\define('CITOMNI_APP_PATH', $dir);
	$_SERVER['HTTP_ACCEPT'] = 'application/json';

	$handler = handlerFor(['log' => ['path' => $dir]]);
	\register_shutdown_function(static function () use ($handler): void {
		$handler->httpError(403, ['reason' => 'nested']);
		\fwrite(\STDOUT, 'continued');
	});
	$handler->httpError(404, ['reason' => 'outer']);
}

if (($argv[1] ?? '') === '--reentry') {
	reentryWorker((string)($argv[2] ?? ''));
}


// ----------------------------------------------------------------
// Responses on the built-in web server
// ----------------------------------------------------------------

/** Docroot of the fixture server; its logs go to <docroot>/logs. */
const SERVER_DIR = 'server';

/** The fixture server, started before the response cases. */
$server = null;

/**
 * Send one request to server.php, starting from an empty log directory.
 *
 * @param  array<string, string>  $headers
 * @return array{status: int, headers: list<string>, body: string}
 */
function serve(string $query, array $headers = []): array {
	global $server;
	$logs = CITOMNI_APP_PATH . '/' . SERVER_DIR . '/logs';
	if (\is_dir($logs)) {
		removeTree($logs);
	}
	return $server->request('GET', '/?' . $query, $headers);
}

/** Records the fixture server wrote to one log file during the last request. */
function serverRecords(string $name): array {
	return records(CITOMNI_APP_PATH . '/' . SERVER_DIR . '/logs/' . $name);
}

/** Request headers of a browser navigation. */
const BROWSER = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];

/** Request headers of an API client. */
const API = ['Accept' => 'application/json'];


$root = tempDir('error_handler');
\define('CITOMNI_APP_PATH', $root);
\ini_set('error_log', $root . '/php-error.log');
$cwd = \getcwd();
\chdir($root);
\stream_wrapper_register('faulty', FaultWrapper::class);

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

	// -- 3. Termination ---------------------------------------------------------

	check('httpError() called while a handler run is in progress does not return', function (): void {
		$dir = CITOMNI_APP_PATH . '/reentry-logs';
		\mkdir($dir);

		$process = \proc_open([\PHP_BINARY, ...iniArgs(), __FILE__, '--reentry', $dir], [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);
		if (!\is_resource($process)) {
			throw new \RuntimeException('Cannot start the re-entry worker.');
		}
		\fclose($pipes[0]);
		$stdout = (string)\stream_get_contents($pipes[1]);
		$stderr = (string)\stream_get_contents($pipes[2]);
		\fclose($pipes[1]);
		\fclose($pipes[2]);

		same(0, \proc_close($process), 'worker exit code');
		same('', $stderr, 'worker stderr');
		same(false, \str_contains($stdout, 'continued'), 'nested httpError() returned to its caller');
		same(404, \json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR)['status'] ?? null, 'response status');
		same(false, \is_file($dir . '/http_router_other.jsonl'), 'nested call logged');
	});

	check('Shutdown functions registered after the handler still run after a fatal error', function (): void {
		$dir = caseDir('later-shutdown-1');
		$r = runWorker('later-shutdown', $dir);
		$page = \strpos($r['stdout'], 'error_id=');
		$after = \strpos($r['stdout'], '[after-warning]');
		expect($page !== false, 'no error page on stdout: ' . \var_export($r, true));
		expect($after !== false && $after > $page, 'the later shutdown function did not run after the page: ' . \var_export($r['stdout'], true));
	});

	check('After a fatal error, a later warning is logged but not rendered, and a later httpError() ends the process', function (): void {
		$dir = caseDir('later-shutdown-2');
		$r = runWorker('later-shutdown', $dir);
		same(1, \substr_count($r['stdout'], '<!doctype html>'), 'pages on stdout');
		same(false, \str_contains($r['stdout'], '[after-http-error]'), 'httpError() returned to its caller');
		$warnings = records($dir . '/logs/http_err_phperror.jsonl');
		same(['late warning'], \array_column($warnings, 'message'), 'logged warnings');
		same(false, \is_file($dir . '/logs/http_router_other.jsonl'), 'later httpError() logged');
		same(1, \count(records($dir . '/logs/http_err_shutdown.jsonl')), 'shutdown records');
	});

	// -- 4. Out of memory and fatal templates -----------------------------------

	check('An out-of-memory fatal is logged and answered with the built-in page, also when memory_limit cannot be raised', function (): void {
		// 512-byte strings fill the size class the record needs; 4000-byte strings fill whole pages.
		foreach ([['16M', 512], ['16M', 4000], ['64M', 512], ['64M', 4000]] as [$limit, $bytes]) {
			$dir = caseDir("oom-{$limit}-{$bytes}");
			$r = runWorker('oom', $dir, (string)$bytes, ['memory_limit' => $limit, 'max_memory_limit' => $limit]);
			$where = " (memory_limit={$limit}, {$bytes}-byte strings)";
			$records = records($dir . '/logs/http_err_shutdown.jsonl');
			same(1, \count($records), 'shutdown records' . $where . ': ' . \trim($r['stderr']));
			same(\E_ERROR, $records[0]['errno'] ?? null, 'errno' . $where);
			expect(\str_contains($r['stdout'], '<title>500 Internal Server Error</title>'), 'no built-in page' . $where . ': ' . \var_export(\substr($r['stdout'], 0, 200), true));
			expect(\str_contains($r['stdout'], 'error_id=' . ($records[0]['error_id'] ?? '?')), 'page error_id differs from the record' . $where);
		}
	});

	check('A fatal error inside the page template is answered with the built-in page', function (): void {
		$dir = caseDir('template-fatal');
		\file_put_contents($dir . '/fatal.php', "<?php\neval('function citomni_eh_template_dup(): void {} function citomni_eh_template_dup(): void {}');\n");
		$r = runWorker('template-fatal', $dir);
		same(1, \count(records($dir . '/logs/http_err_exception.jsonl')), 'exception records');
		$fatal = records($dir . '/logs/http_err_shutdown.jsonl');
		same(\E_COMPILE_ERROR, $fatal[0]['errno'] ?? null, 'shutdown record errno');
		expect(\str_contains($r['stdout'], '<title>500 Internal Server Error</title>'), 'no built-in page: ' . \var_export(\substr($r['stdout'], 0, 200), true));
		expect(\str_contains($r['stdout'], 'error_id=' . ($fatal[0]['error_id'] ?? '?')), 'page error_id differs from the shutdown record');
	});

	// -- 5. Trace arguments ------------------------------------------------------

	check('A logged trace shows argument types, not values, unless log.trace_args is on', function (): void {
		// PHP keeps arguments in exception traces unless zend.exception_ignore_args is on.
		$calls = static function (string $traceArgs): string {
			$dir = caseDir('trace-args-' . $traceArgs);
			runWorker('trace-args', $dir, $traceArgs, ['zend.exception_ignore_args' => '0']);
			$record = records($dir . '/logs/http_err_exception.jsonl')[0] ?? [];
			return \implode("\n", \array_column($record['trace'] ?? [], 'call'));
		};
		$off = $calls('0');
		expect(\str_contains($off, 'verifyLogin(string(16), string(15))'), 'types missing with trace_args off: ' . $off);
		expect(!\str_contains($off, 'Hunter2'), 'password logged with trace_args off');
		$on = $calls('1');
		expect(\str_contains($on, "verifyLogin('anna@example.com', 'Hunter2-secret!')"), 'values missing with trace_args on: ' . $on);
	});

	// -- 6. Appending -----------------------------------------------------------

	check('A line the log takes only in part is cut off again, and error_log names the file', function (): void {
		faulty(['append-short'], 3);
		logWarning(faultyHandler(), 'short write');
		same(3, \count(records(faultyLive())), 'records in the live file');
		expectErrorLog('cannot append to ' . FaultWrapper::DIR . '/http_err_phperror.jsonl: 10 of ');
	});

	check('A line the log takes none of is reported with the file name', function (): void {
		faulty(['append-none'], 3);
		logWarning(faultyHandler(), 'no write');
		same(3, \count(records(faultyLive())), 'records in the live file');
		expectErrorLog('cannot append to ' . FaultWrapper::DIR . '/http_err_phperror.jsonl: 0 of ');
	});

	check('A partial line that cannot be cut off safely stays, and error_log says so', function (): void {
		foreach ([['rollback'], ['lock', 'append-short']] as $faults) {
			faulty($faults, 3);
			logWarning(faultyHandler(), 'short write');
			$text = (string)\file_get_contents(faultyLive());
			same(\strlen(seedLines(3)) + 10, \strlen($text), 'live file bytes with ' . \implode('+', $faults));
			expectErrorLog('the partial line remains');
		}
	});

	// -- 7. Rotation -------------------------------------------------------------

	check('A rotation moves every record to a file with the live file\'s permissions and keeps the new one live', function (): void {
		$dir = caseDir('rotation');
		\file_put_contents($dir . '/http_err_phperror.jsonl', seedLines(10));
		\chmod($dir . '/http_err_phperror.jsonl', 0640);
		logWarning(handlerFor(['log' => ['path' => $dir, 'max_bytes' => 1_000]]), 'after rotation');
		$rotated = rotations($dir);
		same(1, \count($rotated), 'rotations' . lastErrorLogLine());
		same(10, \count(records($dir . '/' . $rotated[0])), 'records in the rotation');
		assertOneWarning(phpErrorRecords($dir), 'after rotation');
		same([], tmpFiles($dir), 'temporary files');
		\clearstatcache();
		same(\fileperms($dir . '/http_err_phperror.jsonl') & 0777, \fileperms($dir . '/' . $rotated[0]) & 0777, 'rotation permissions');
	});

	check('A rotation whose copy is cut short leaves the live file as it was', function (): void {
		faulty(['copy-short'], 10);
		logWarning(faultyHandler(['max_bytes' => 1_000]), 'after failed copy');
		same(11, \count(records(faultyLive())), 'records in the live file');
		same([], rotations(FaultWrapper::$root), 'rotations');
		same([], tmpFiles(FaultWrapper::$root), 'temporary files');
		expectErrorLog('is unchanged');
	});

	check('A rotation whose rename fails removes the copy and leaves the live file as it was', function (): void {
		faulty(['rename'], 10);
		logWarning(faultyHandler(['max_bytes' => 1_000]), 'after failed rename');
		same(11, \count(records(faultyLive())), 'records in the live file');
		same([], rotations(FaultWrapper::$root), 'rotations');
		same([], tmpFiles(FaultWrapper::$root), 'temporary files');
		expectErrorLog('rename failed');
	});

	check('A rotation whose truncation fails keeps the records in both files, and error_log says so', function (): void {
		faulty(['truncate'], 10);
		logWarning(faultyHandler(['max_bytes' => 1_000]), 'after failed truncation');
		$rotated = rotations(FaultWrapper::$root);
		same(1, \count($rotated), 'rotations');
		same(10, \count(records(FaultWrapper::$root . '/' . $rotated[0])), 'records in the rotation');
		same(11, \count(records(faultyLive())), 'records in the live file');
		expectErrorLog('cannot truncate');
	});

	check('Without the lock on the live file, nothing is rotated', function (): void {
		faulty(['lock'], 10);
		logWarning(faultyHandler(['max_bytes' => 1_000]), 'after failed lock');
		same([], rotations(FaultWrapper::$root), 'rotations');
		same(11, \count(records(faultyLive())), 'records in the live file');
		expectErrorLog('cannot lock');
	});

	// -- 8. Retention ------------------------------------------------------------

	check('Retention keeps the newest rotations of its own log and leaves every other file alone', function (): void {
		$dir = caseDir('retention');
		$own = [];
		for ($i = 1; $i <= 5; $i++) {
			$own[] = "http_err_exception.2026010{$i}T120000.000000Z.jsonl";
		}
		$others = [
			'http_err_exception.jsonl',
			'http_err_exception.backup.jsonl',
			'http_err_exception.jsonl.lock',
			'http_err_exception.20260109T120000.000000Z.jsonl.tmp',
			'http_err_phperror.20260101T120000.000000Z.jsonl',
			'http_err_phperror.20260102T120000.000000Z.jsonl',
			'http_err_phperror.20260103T120000.000000Z.jsonl',
		];
		foreach (\array_merge($own, $others) as $i => $name) {
			\file_put_contents($dir . '/' . $name, '');
			// The rotations get mtimes in name order, the foreign files older ones.
			\touch($dir . '/' . $name, \in_array($name, $own, true) ? 1_800_000_000 + $i : 1_700_000_000);
		}
		handlerFor()->prune($dir . '/http_err_exception.jsonl', 2);
		$left = \array_values(\array_diff(\scandir($dir), ['.', '..']));
		\sort($left);
		$expected = \array_merge(\array_slice($own, 3), $others);
		\sort($expected);
		same($expected, $left, 'files left');
	});

	check('Retention works in a directory whose path contains glob syntax', function (): void {
		$dir = caseDir('logs[1]');
		for ($i = 1; $i <= 4; $i++) {
			\file_put_contents($dir . "/http_err_exception.2026010{$i}T120000.000000Z.jsonl", '');
		}
		handlerFor()->prune($dir . '/http_err_exception.jsonl', 2);
		same(['http_err_exception.20260103T120000.000000Z.jsonl', 'http_err_exception.20260104T120000.000000Z.jsonl'], rotations($dir, 'http_err_exception'), 'rotations left');
	});

	// -- 9. Application state ----------------------------------------------------

	check('A warning inside the application\'s strtok() loop leaves the loop intact', function (): void {
		$dir = caseDir('strtok');
		$handler = handlerFor(['log' => ['path' => $dir]]);
		$saved = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/orders?page=2';
		try {
			$seen = [];
			for ($token = \strtok('a,b,c', ','); $token !== false && \count($seen) < 5; $token = \strtok(',')) {
				$seen[] = $token;
				if ($token === 'b') {
					$handler->handlePhpError(\E_USER_WARNING, 'inside the loop', __FILE__, __LINE__);
				}
			}
			same(['a', 'b', 'c'], $seen, 'tokens');
		} finally {
			if ($saved === null) {
				unset($_SERVER['REQUEST_URI']);
			} else {
				$_SERVER['REQUEST_URI'] = $saved;
			}
		}
	});

	// -- 10. Responses -----------------------------------------------------------

	$docroot = caseDir(SERVER_DIR);
	$server = new FixtureServer($docroot, __DIR__ . '/server.php');
	try {

		check('An error after download headers sends neither their Content-Disposition nor their Content-Length', function (): void {
			$r = serve('case=download', BROWSER);
			same(500, $r['status'], 'status');
			same([], headerValues($r['headers'], 'Content-Disposition'), 'Content-Disposition');
			same(false, \in_array('5242880', headerValues($r['headers'], 'Content-Length'), true), 'stale Content-Length');
			expect(\str_contains($r['body'], '<!doctype html>'), 'no error page');
		});

		check('After streaming has begun, the error id is appended to an HTML body only', function (): void {
			$csv = serve('case=stream-csv', BROWSER);
			same("id,amount\n1,100\n", $csv['body'], 'CSV body');
			$html = serve('case=stream-html', BROWSER);
			$id = serverRecords('http_err_exception.jsonl')[0]['error_id'] ?? '?';
			same("<p>first rows</p>\n\n<!-- error_id={$id} -->", $html['body'], 'HTML body');
		});

		check('Outside dev, a rendered PHP error shows a generic message and its record the real one', function (): void {
			$r = serve('case=php-warning&render=' . \E_WARNING, API);
			$json = \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
			same(['php_error', 'An unexpected error occurred.'], [$json['type'] ?? null, $json['message'] ?? null], 'response');
			$record = serverRecords('http_err_phperror.jsonl')[0] ?? [];
			same($json['error_id'] ?? null, $record['error_id'] ?? null, 'error_id');
			expect(\str_contains((string)($record['message'] ?? ''), 'file_get_contents('), 'record lacks the PHP message');
		});

		check('An output handler that throws while its buffer is discarded leaves the exception\'s response in place', function (): void {
			$r = serve('case=output-handler', API);
			$json = \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
			same('exception', $json['type'] ?? null, 'response type');
			same(serverRecords('http_err_exception.jsonl')[0]['error_id'] ?? null, $json['error_id'] ?? null, 'error_id');
			same([], serverRecords('http_err_shutdown.jsonl'), 'shutdown records');
		});

		check('The default template falls back to English for a language without texts, without diagnostics', function (): void {
			$r = serve('case=exception&lang=de', BROWSER);
			expect(\str_contains($r['body'], 'CitOmni Error Panel') && \str_contains($r['body'], 'Internal server error'), 'not the default template in English: ' . \substr($r['body'], 0, 300));
			same([], serverRecords('http_err_phperror.jsonl'), 'PHP errors while rendering');
		});

		check('The default template uses its own texts for a status without texts, without diagnostics', function (): void {
			$r = serve('case=status-419', BROWSER);
			same(419, $r['status'], 'status');
			expect(\str_contains($r['body'], 'Oops, something went wrong'), 'fallback title missing');
			same([], serverRecords('http_err_phperror.jsonl'), 'PHP errors while rendering');
		});

		check('The default template offers the support links only in dev, and without the query string', function (): void {
			$prod = serve('case=exception&token=s3cr3t', BROWSER);
			expect(!\str_contains($prod['body'], 'mailto:') && !\str_contains($prod['body'], 'issues/new'), 'support links outside dev');
			$dev = serve('case=exception&env=dev&token=s3cr3t', BROWSER);
			expect(\str_contains($dev['body'], 'issues/new'), 'no support links in dev');
			expect(!\str_contains($dev['body'], 's3cr3t'), 'query string in the dev page');
		});

		check('The default template escapes the public root URL in its home link', function (): void {
			$r = serve('case=exception&env=dev&root=' . \rawurlencode('https://example.test/"><b>x</b>'), BROWSER);
			expect(!\str_contains($r['body'], '"><b>x</b>'), 'unescaped root URL');
			expect(\str_contains($r['body'], 'https://example.test/&quot;&gt;&lt;b&gt;x&lt;/b&gt;'), 'escaped root URL missing');
		});

	} finally {
		$server->stop();
	}

	// -- 11. Concurrency ---------------------------------------------------------

	$name = 'Concurrent writers lose no records across rotations';
	if (\getenv('CITOMNI_TEST_PARALLEL') === '1') {
		check($name, function (): void {
			$dir = caseDir('parallel');
			$workers = [];
			for ($w = 0; $w < 4; $w++) {
				$workers[$w] = startWorker('parallel', $dir, (string)$w, [], 'parallel-' . $w);
			}
			// Open the start gate only when every worker has been started.
			\touch($dir . '/go');
			foreach ($workers as $w => $process) {
				same(0, \proc_close($process), "worker {$w} exit code");
			}
			$messages = [];
			foreach (\scandir($dir . '/logs') as $file) {
				if (\str_ends_with($file, '.jsonl')) {
					\array_push($messages, ...\array_column(records($dir . '/logs/' . $file), 'message'));
				}
			}
			$expected = [];
			for ($w = 0; $w < 4; $w++) {
				for ($i = 0; $i < 100; $i++) {
					$expected[] = "parallel record {$w}-{$i}";
				}
			}
			\sort($messages);
			\sort($expected);
			same($expected, $messages, 'records across live and rotated files');
			expect(\count(rotations($dir . '/logs')) >= 10, 'expected at least 10 rotations');
			same([], tmpFiles($dir . '/logs'), 'temporary files');
		});
	} else {
		$skipped++;
		\fwrite(\STDOUT, "SKIP {$name}: set CITOMNI_TEST_PARALLEL=1 to run multi-process checks\n");
	}

} finally {
	\stream_wrapper_unregister('faulty');
	if ($cwd !== false) {
		\chdir($cwd);
	}
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
