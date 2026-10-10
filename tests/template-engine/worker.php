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

use CitOmni\Http\Service\TemplateEngine;
use CitOmni\Http\Tests\App;

/*
 * Worker process for the parallel cases in tests/template-engine/run.php. Not a suite
 * of its own; tests/run.php only collects run.php and database.php.
 *
 * Usage (started by run.php):
 *   php tests/template-engine/worker.php <app root> <engine file> <gate file> <worker id> <cache 1|0>
 *
 * Loads the engine file, waits until the gate file exists, renders parallel.html@app
 * twelve times with values of its own and prints "OK" when every render matched. A
 * mismatch, a gate that does not appear within 20 seconds or any PHP diagnostic ends
 * it with a message on stderr and a nonzero exit code.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; like the production ErrorHandler, leave diagnostics
// silenced with @ to PHP. TemplateEngine relies on @ for its expected cache races.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

if ($argc !== 6 || !\is_dir($argv[1]) || !\is_file($argv[2]) || !\ctype_digit($argv[4]) || !\in_array($argv[5], ['0', '1'], true)) {
	throw new \RuntimeException('Usage: worker.php <app root> <engine file> <gate file> <worker id> <cache 1|0>');
}
[, $root, $engineFile, $gate, $id, $cache] = $argv;

\define('CITOMNI_APP_PATH', $root);
require \dirname(__DIR__) . '/bootstrap.php';
require $engineFile;

$engine = new TemplateEngine(new App(
	['app' => $root . '/templates', 'test/provider' => $root . '/provider'],
	['cache_enabled' => $cache === '1'],
));

// run.php creates the gate after it has started every worker of the phase.
$deadline = \microtime(true) + 20;
while (!\is_file($gate)) {
	if (\microtime(true) > $deadline) {
		\fwrite(\STDERR, "Gate timeout\n");
		exit(2);
	}
	\usleep(1000);
}

for ($i = 0; $i < 12; $i++) {
	$expected = '<html><main>partial $1\\\\ [' . $id . '-' . $i . ']</main></html>';
	$actual = $engine->renderToString('parallel.html@app', ['value' => $id . '-' . $i]);
	if ($actual !== $expected) {
		\fwrite(\STDERR, 'Output mismatch in worker ' . $id . ': ' . \var_export($actual, true) . "\n");
		exit(1);
	}
}

\fwrite(\STDOUT, "OK\n");
