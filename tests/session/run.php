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

namespace CitOmni\Http\Tests\Session;

use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Session: session id regeneration.
 *
 * Each case is one HTTP request to PHP's built-in web server with server.php as
 * router, so session storage, the session cookie and output behave as in a real
 * request. The server runs with the same php.ini as this suite and uses a
 * temporary document root that also holds the session files.
 *
 * Usage:
 *   php tests/session/run.php
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

require \dirname(__DIR__) . '/support/fixtures.php';

const SESSION_NAME = 'CITSESSID';
const REGENERATE_FAILED = \RuntimeException::class . ': session_regenerate_id() failed; refusing to continue on the previous session id.';

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

/**
 * Run one fixture case.
 *
 * @return array{headers: list<string>, json: array<string, mixed>}
 */
function fetch(FixtureServer $server, string $case, string $dir, string $cookieHeader = ''): array {
	$r = $server->request('GET', '/?case=' . $case . '&dir=' . $dir, $cookieHeader !== '' ? ['Cookie' => $cookieHeader] : []);

	// A case may print output before its report; the report is the JSON object at the end.
	$start = \strpos($r['body'], '{');
	if ($start === false) {
		throw new \RuntimeException('No JSON report in response: ' . \trim($r['body']));
	}
	return ['headers' => $r['headers'], 'json' => \json_decode(\substr($r['body'], $start), true, 512, \JSON_THROW_ON_ERROR)];
}

/** @return list<string> Values of the Set-Cookie headers for the session cookie. */
function sessionCookies(array $headers): array {
	$values = [];
	foreach ($headers as $header) {
		if (\preg_match('/^Set-Cookie:\s*' . SESSION_NAME . '=([^;]*)/i', $header, $m) === 1) {
			$values[] = $m[1];
		}
	}
	return $values;
}

/** @return list<string> Session file names in one case's storage directory, sorted. */
function sessionFiles(string $root, string $dir): array {
	$files = \array_map('basename', \glob($root . '/sessions/' . $dir . '/sess_*') ?: []);
	\sort($files);
	return $files;
}

$root = tempDir('session');
\mkdir($root . '/sessions', 0700);
$server = null;

try {
	$server = new FixtureServer($root, __DIR__ . '/server.php');

	check('regenerate() starts a missing session and sends only the final id', function () use ($server, $root): void {
		$r = fetch($server, 'regenerate_without_session', 'without-session');
		same(false, $r['json']['active_before']);
		same(null, $r['json']['exception']);
		same(true, $r['json']['active_after']);
		same([$r['json']['id']], sessionCookies($r['headers']));
		// The id issued on start is rotated away; its file must not survive.
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'without-session'));
		same([], $r['json']['warnings']);
	});

	check('regenerate() on an existing session rotates the id, keeps its data and deletes the old file', function () use ($server, $root): void {
		$seed = fetch($server, 'seed', 'existing');
		$old = $seed['json']['id'];
		same([$old], sessionCookies($seed['headers']));

		$r = fetch($server, 'regenerate_existing', 'existing', SESSION_NAME . '=' . $old);
		same(null, $r['json']['exception']);
		same($old, $r['json']['id_before']);
		same('value', $r['json']['kept_before']);
		same(true, $r['json']['id'] !== $old);
		same('value', $r['json']['kept_after']);
		same(true, $r['json']['rotated_at_recorded']);
		same([$r['json']['id']], sessionCookies($r['headers']));
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'existing'));
	});

	check('regenerate() throws when storage cannot destroy the old session, and records nothing', function () use ($server): void {
		$r = fetch($server, 'regenerate_storage_failure', 'storage-failure');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['no_new_id']);
		same(false, $r['json']['rotated_at_recorded']);
	});

	check('regenerate() throws after headers were sent instead of keeping the old id', function () use ($server): void {
		$r = fetch($server, 'regenerate_after_output', 'after-output');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['id_unchanged']);
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
