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

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

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
function fetch(string $baseUrl, string $case, string $dir, string $cookieHeader = ''): array {
	$context = \stream_context_create(['http' => [
		'method'        => 'GET',
		'header'        => $cookieHeader !== '' ? "Cookie: {$cookieHeader}\r\n" : '',
		'ignore_errors' => true,
		'timeout'       => 5,
	]]);
	$body = \file_get_contents($baseUrl . '/?case=' . $case . '&dir=' . $dir, false, $context);
	$headers = \http_get_last_response_headers() ?? [];

	// A case may print output before its report; the report is the JSON object at the end.
	$start = \strpos($body, '{');
	if ($start === false) {
		throw new \RuntimeException('No JSON report in response: ' . \trim($body));
	}
	return ['headers' => $headers, 'json' => \json_decode(\substr($body, $start), true, 512, \JSON_THROW_ON_ERROR)];
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

function removeTree(string $dir): void {
	foreach (\glob($dir . '/*') ?: [] as $path) {
		if (\is_dir($path)) {
			removeTree($path);
		} else {
			\unlink($path);
		}
	}
	\rmdir($dir);
}

$root = \sys_get_temp_dir() . '/citomni_http_session_test_' . \bin2hex(\random_bytes(6));
\mkdir($root . '/sessions', 0700, true);
$server = null;

try {

	// -- 1. Start the fixture server -------------------------------------------

	// Same php.ini as this process: the loaded file, the default lookup, or none at all.
	$iniArgs = match (true) {
		\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
		\php_ini_scanned_files() !== false => [],
		default                            => ['-n'],
	};

	// Reserve a free port, then hand it to the server.
	$socket = \stream_socket_server('tcp://127.0.0.1:0');
	$address = \stream_socket_get_name($socket, false);
	\fclose($socket);

	$server = \proc_open([\PHP_BINARY, ...$iniArgs, '-S', $address, '-t', $root, __DIR__ . '/server.php'], [
		0 => ['pipe', 'r'],
		1 => ['file', $root . '/server.out', 'w'],
		2 => ['file', $root . '/server.err', 'w'],
	], $pipes);
	if (!\is_resource($server)) {
		throw new \RuntimeException('Cannot start the fixture server.');
	}
	\fclose($pipes[0]);

	// Connection attempts fail with a warning until the server listens.
	$deadline = \microtime(true) + 5;
	while (true) {
		try {
			\fclose(\stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1));
			break;
		} catch (\ErrorException $notListening) {
			if (\microtime(true) >= $deadline) {
				throw new \RuntimeException('Fixture server did not start: ' . $notListening->getMessage());
			}
			\usleep(10_000);
		}
	}
	$baseUrl = 'http://' . $address;


	// -- 2. Cases ----------------------------------------------------------------

	check('regenerate() starts a missing session and sends only the final id', function () use ($baseUrl, $root): void {
		$r = fetch($baseUrl, 'regenerate_without_session', 'without-session');
		same(false, $r['json']['active_before']);
		same(null, $r['json']['exception']);
		same(true, $r['json']['active_after']);
		same([$r['json']['id']], sessionCookies($r['headers']));
		// The id issued on start is rotated away; its file must not survive.
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'without-session'));
		same([], $r['json']['warnings']);
	});

	check('regenerate() on an existing session rotates the id, keeps its data and deletes the old file', function () use ($baseUrl, $root): void {
		$seed = fetch($baseUrl, 'seed', 'existing');
		$old = $seed['json']['id'];
		same([$old], sessionCookies($seed['headers']));

		$r = fetch($baseUrl, 'regenerate_existing', 'existing', SESSION_NAME . '=' . $old);
		same(null, $r['json']['exception']);
		same($old, $r['json']['id_before']);
		same('value', $r['json']['kept_before']);
		same(true, $r['json']['id'] !== $old);
		same('value', $r['json']['kept_after']);
		same(true, $r['json']['rotated_at_recorded']);
		same([$r['json']['id']], sessionCookies($r['headers']));
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'existing'));
	});

	check('regenerate() throws when storage cannot destroy the old session, and records nothing', function () use ($baseUrl): void {
		$r = fetch($baseUrl, 'regenerate_storage_failure', 'storage-failure');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['no_new_id']);
		same(false, $r['json']['rotated_at_recorded']);
	});

	check('regenerate() throws after headers were sent instead of keeping the old id', function () use ($baseUrl): void {
		$r = fetch($baseUrl, 'regenerate_after_output', 'after-output');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['id_unchanged']);
	});

} finally {
	if (\is_resource($server)) {
		\proc_terminate($server);
		\proc_close($server);
	}
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
