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
use CitOmni\Http\Service\Nonce;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Worker process for the parallel case in tests/nonce/run.php. Not a suite of its
 * own; tests/run.php only collects run.php and database.php.
 *
 * Usage (started by run.php):
 *   php tests/nonce/worker.php <ledger dir> <start time, Unix seconds with fraction>
 *
 * Waits until the start time, claims the one nonce every worker claims, and
 * prints "accepted" or "rejected".
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

if ($argc !== 3 || !\is_dir($argv[1]) || !\is_numeric($argv[2])) {
	throw new \RuntimeException('Usage: worker.php <ledger dir> <start time>');
}
[, $dir, $startAt] = $argv;

\define('CITOMNI_APP_PATH', $dir);
require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Exception/NonceException', 'Exception/NonceConfigException', 'Service/Nonce'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

$nonce = new Nonce(new App(['nonce' => mergeLastWins(Registry::CFG_HTTP['nonce'], ['dir' => $dir])]));

while (\microtime(true) < (float)$startAt) {
	// Spin so that all workers claim at the same moment.
}

\fwrite(\STDOUT, $nonce->checkAndStore('race', 'one-shared-nonce', 60) ? 'accepted' : 'rejected');
