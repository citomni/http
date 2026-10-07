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

namespace CitOmni\Http\Tests\Url;

use CitOmni\Http\Util\Url;

/*
 * Isolated suite for CitOmni\Http\Util\Url: the locality check that guards
 * user-supplied redirect targets (e.g. ?next=) against open redirects.
 *
 * Usage:
 *   php tests/url/run.php
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

require \dirname(__DIR__, 2) . '/src/Util/Url.php';

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

check('Paths with exactly one leading slash are local', function (): void {
	foreach (['/', '/login.html', '/a/b?next=/c#top', '/a//b', '/a\\b', '/%2F%2Fevil.test'] as $path) {
		same(true, Url::isLocal($path), $path);
	}
});

check('Absolute, scheme-relative and backslash targets are not local', function (): void {
	foreach (['https://evil.test/', 'http:evil.test', 'javascript:alert(1)', '//evil.test', '//', '/\\evil.test', '\\\\evil.test', '\\/evil.test'] as $target) {
		same(false, Url::isLocal($target), $target);
	}
});

check('Targets with control characters are not local', function (): void {
	// Browsers remove tab and newline characters from a URL before parsing it,
	// so "/\t/evil.test" would be read as "//evil.test".
	foreach (["/\t/evil.test", "/\n/evil.test", "/\r\n/evil.test", "/\t\\evil.test", "/a\x00b", "/a\x1Fb", "/a\x7Fb"] as $target) {
		same(false, Url::isLocal($target), \rawurlencode($target));
	}
});

check('Relative and empty targets are not local', function (): void {
	foreach (['', 'login.html', './login', '../login', ' /login', '?next=/'] as $target) {
		same(false, Url::isLocal($target), \json_encode($target));
	}
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
