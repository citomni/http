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

namespace CitOmni\Http\Tests\Cookie;

use CitOmni\Http\Service\Cookie;
use CitOmni\Http\Tests\Support\App;

/*
 * Isolated suite for CitOmni\Http\Service\Cookie: reading untrusted cookie input.
 *
 * Usage:
 *   php tests/cookie/run.php
 *
 * Notes:
 * - Runs the real Cookie service against the kernel doubles, without Composer.
 * - $_COOKIE is filled exactly as PHP's request parser fills it, e.g. the header
 *   "Cookie: _auth_rm[]=x" arrives as ['_auth_rm' => ['x']].
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic the production ErrorHandler would report; like
// that handler, leave diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__, 2) . '/src/Service/Cookie.php';

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

function same(mixed $expected, mixed $actual): void {
	if ($expected !== $actual) {
		throw new \RuntimeException('Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
	}
}

/** Fill $_COOKIE as the request parser would and build a fresh Cookie service. */
function cookieFor(array $parsedCookies): Cookie {
	$_COOKIE = $parsedCookies;
	return new Cookie(new App([
		'http'   => ['base_url' => 'http://127.0.0.1'],
		'cookie' => ['secure' => false, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/'],
	]));
}

check('Array-shaped input is absent for get(), which returns the default', function (): void {
	$cookie = cookieFor(['_auth_rm' => ['x']]);
	same(null, $cookie->get('_auth_rm'));
	same('fallback', $cookie->get('_auth_rm', 'fallback'));
});

check('Array-shaped input is absent for has()', function (): void {
	same(false, cookieFor(['_auth_rm' => ['x']])->has('_auth_rm'));
});

check('String values, including the empty string, are returned unchanged', function (): void {
	$cookie = cookieFor(['plain' => 'v', 'empty' => '']);
	same('v', $cookie->get('plain', 'fallback'));
	same('', $cookie->get('empty', 'fallback'));
	same(true, $cookie->has('plain'));
	same(true, $cookie->has('empty'));
});

check('An absent cookie yields the default and has() is false', function (): void {
	$cookie = cookieFor([]);
	same(null, $cookie->get('missing'));
	same('fallback', $cookie->get('missing', 'fallback'));
	same(false, $cookie->has('missing'));
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
