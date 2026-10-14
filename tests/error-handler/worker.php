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

/*
 * Child process for run.php, for the cases that end the process. Installs the real
 * ErrorHandler on the kernel doubles, with the shipped baseline plus the case's overrides,
 * then runs one case. Responses go to stdout; logs go to <dir>/logs.
 *
 * Usage (run.php builds the command line):
 *   php [-d ...] worker.php <case> <dir> [arg]
 *
 * Cases:
 * - oom <bytes>: Fills memory with strings of <bytes> until PHP stops with an out-of-memory
 *   fatal. gc_mem_caches() first hands every free page back, so the fatal hits a full heap.
 * - template-fatal: An uncaught exception whose page template, <dir>/fatal.php, ends in a
 *   fatal error.
 * - later-shutdown: A fatal error, then two shutdown functions registered after install():
 *   one raises a warning and prints "[after-warning]", one calls httpError(403) and prints
 *   "[after-http-error]".
 * - trace-args <0|1>: An uncaught exception thrown in a function that receives a password,
 *   with log.trace_args off or on.
 * - parallel <id>: Waits for <dir>/go, then logs 100 warnings through handlePhpError()
 *   with log.max_bytes 4096 and every rotation kept.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast during setup. install() replaces this handler.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$case = (string)($argv[1] ?? '');
$dir  = (string)($argv[2] ?? '');
$arg  = (string)($argv[3] ?? '');

if ($dir === '' || !\is_dir($dir)) {
	\fwrite(\STDERR, "Usage: php worker.php <case> <dir> [arg]\n");
	exit(2);
}

\define('CITOMNI_APP_PATH', $dir);
\define('CITOMNI_ENVIRONMENT', 'prod');

require \dirname(__DIR__) . '/support/doubles.php';

/** Stand-in for \CitOmni\Kernel\Arr, which the kernel doubles do not provide. */
final class ArrDouble {
	public static function mergeAssocLastWins(array $a, array $b): array {
		return mergeLastWins($a, $b);
	}
}

\class_alias(ArrDouble::class, 'CitOmni\Kernel\Arr');

require \dirname(__DIR__, 2) . '/src/Boot/Registry.php';
require \dirname(__DIR__, 2) . '/src/Service/ErrorHandler.php';

/** Build the real handler from the shipped baseline plus $cfg, logging to <dir>/logs. */
function handler(array $cfg = []): ErrorHandler {
	$errorHandler = mergeLastWins(Registry::CFG_HTTP['error_handler'], ['log' => ['path' => \CITOMNI_APP_PATH . '/logs']]);
	return new ErrorHandler(new App(['error_handler' => mergeLastWins($errorHandler, $cfg)]));
}

/** Throws from inside a function whose arguments include a password. */
function verifyLogin(string $email, string $password): void {
	throw new \RuntimeException('Login backend unavailable');
}

switch ($case) {

	case 'oom':
		handler()->install();
		(static function (int $bytes): void {
			$rows = [];
			\gc_mem_caches();
			while (true) {
				$rows[] = \str_repeat('r', $bytes);
			}
		})(\max(1, (int)$arg));
		break;

	case 'template-fatal':
		handler(['templates' => ['html' => $dir . '/fatal.php', 'html_failsafe' => '']])->install();
		throw new \RuntimeException('Controller failed');

	case 'later-shutdown':
		$handler = handler(['render' => ['trigger' => \E_ALL], 'templates' => ['html' => '', 'html_failsafe' => '']]);
		$handler->install();
		\register_shutdown_function(static function (): void {
			\trigger_error('late warning', \E_USER_WARNING);
			echo "\n[after-warning]";
		});
		\register_shutdown_function(static function () use ($handler): void {
			$handler->httpError(403, ['reason' => 'late']);
			echo "\n[after-http-error]";
		});
		// E_COMPILE_ERROR never reaches the error handler; only the shutdown handler sees it.
		eval('function citomni_eh_worker_dup(): void {} function citomni_eh_worker_dup(): void {}');
		break;

	case 'trace-args':
		handler(['log' => ['trace_args' => $arg === '1']])->install();
		verifyLogin('anna@example.com', 'Hunter2-secret!');
		break;

	case 'parallel':
		$handler = handler(['log' => ['max_bytes' => 4_096, 'max_files' => 0]]);
		$gate = $dir . '/go';
		$deadline = \microtime(true) + 10;
		while (!\is_file($gate)) {
			if (\microtime(true) > $deadline) {
				\fwrite(\STDERR, "Start gate timeout\n");
				exit(3);
			}
			\usleep(1_000);
		}
		for ($i = 0; $i < 100; $i++) {
			$handler->handlePhpError(\E_USER_WARNING, "parallel record {$arg}-{$i}", __FILE__, __LINE__);
		}
		break;

	default:
		\fwrite(\STDERR, "Unknown case: {$case}\n");
		exit(2);
}

echo "\n[end]";
