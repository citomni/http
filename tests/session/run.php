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
 * Isolated suite for CitOmni\Http\Service\Session: Regeneration of the id, the session
 * cookie, storage settings, removed options, destruction, and reads that must not
 * create a session, also through the real Flash and Csrf services.
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
function fetch(FixtureServer $server, string $case, string $dir, string $cookieHeader = '', array $headers = [], string $method = 'GET'): array {
	if ($cookieHeader !== '') {
		$headers['Cookie'] = $cookieHeader;
	}
	$r = $server->request($method, '/?case=' . $case . '&dir=' . $dir, $headers);

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

/** @return list<bool> Whether each Set-Cookie header for the session cookie has the Secure attribute. */
function sessionCookiesSecure(array $headers): array {
	$flags = [];
	foreach ($headers as $header) {
		if (\preg_match('/^Set-Cookie:\s*' . SESSION_NAME . '=/i', $header) === 1) {
			$flags[] = \preg_match('/;\s*secure\s*(;|$)/i', $header) === 1;
		}
	}
	return $flags;
}

/**
 * Attributes of each Set-Cookie header for the session cookie, without the value.
 *
 * @return list<array<string, string|true>> Lowercase names, sorted; a flag such as HttpOnly maps to true.
 */
function sessionCookieAttributes(array $headers): array {
	$cookies = [];
	foreach ($headers as $header) {
		if (\preg_match('/^Set-Cookie:\s*' . SESSION_NAME . '=[^;]*(.*)$/i', $header, $m) !== 1) {
			continue;
		}
		$attributes = [];
		foreach (\array_filter(\array_map('trim', \explode(';', $m[1]))) as $part) {
			$pair = \explode('=', $part, 2);
			$attributes[\strtolower($pair[0])] = $pair[1] ?? true;
		}
		\ksort($attributes);
		$cookies[] = $attributes;
	}
	return $cookies;
}

/** Require $actual to be a string that starts with $prefix. */
function startsWith(string $prefix, mixed $actual): void {
	if (!\is_string($actual) || !\str_starts_with($actual, $prefix)) {
		throw new \RuntimeException('Expected a string starting with ' . \var_export($prefix, true) . '; got ' . \var_export($actual, true));
	}
}

/** @return list<string> The headers that session_start() sends for the default cache limiter. */
function cacheHeaders(array $headers): array {
	return \array_values(\array_filter($headers, static fn (string $header): bool => \preg_match('/^(Expires|Cache-Control|Pragma):/i', $header) === 1));
}

/** Require a response without any trace of a session: No cookie, no file, no cache headers. */
function noSession(array $r, string $root, string $dir): void {
	same([], sessionCookies($r['headers']));
	same([], sessionFiles($root, $dir));
	same([], cacheHeaders($r['headers']));
	same([], $r['json']['warnings']);
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
		same([$r['json']['id']], sessionCookies($r['headers']));
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'existing'));
	});

	check('regenerate() throws when storage cannot destroy the old session', function () use ($server): void {
		$r = fetch($server, 'regenerate_storage_failure', 'storage-failure');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['no_new_id']);
	});

	check('regenerate() throws after headers were sent instead of keeping the old id', function () use ($server): void {
		$r = fetch($server, 'regenerate_after_output', 'after-output');
		same(REGENERATE_FAILED, $r['json']['exception']);
		same(true, $r['json']['id_unchanged']);
	});

	check('Inferred Secure follows the request service behind a trusted TLS proxy', function () use ($server): void {
		$r = fetch($server, 'secure_trusted_proxy', 'trusted-proxy', '', ['X-Forwarded-Proto' => 'https']);
		same(true, $r['json']['request_is_https']);
		same([true], sessionCookiesSecure($r['headers']));
	});

	check('Inferred Secure ignores X-Forwarded-Proto when the proxy is not trusted', function () use ($server): void {
		$r = fetch($server, 'secure_untrusted_proxy', 'untrusted-proxy', '', ['X-Forwarded-Proto' => 'https']);
		same(false, $r['json']['request_is_https']);
		same([false], sessionCookiesSecure($r['headers']));
	});

	check('The session cookie takes its attributes from the Cookie service', function () use ($server): void {
		$r = fetch($server, 'cookie_from_cookie_service', 'from-cookie-service');
		same(null, $r['json']['exception']);
		// cookie.httponly is false, but the baseline pins session.cookie_httponly to true.
		same([['domain' => 'example.test', 'httponly' => true, 'path' => '/app', 'samesite' => 'Strict']], sessionCookieAttributes($r['headers']));
	});

	check('session.cookie_* settings override the Cookie service, and an empty domain means host-only', function () use ($server): void {
		$r = fetch($server, 'cookie_session_overrides', 'session-overrides');
		same(null, $r['json']['exception']);
		same([['httponly' => true, 'path' => '/', 'samesite' => 'Lax']], sessionCookieAttributes($r['headers']));
	});

	check('A session cookie with SameSite=None and without Secure throws before the session starts', function () use ($server, $root): void {
		$r = fetch($server, 'cookie_none_without_secure', 'none-without-secure');
		same(\RuntimeException::class . ': SameSite=None requires Secure=true', $r['json']['exception']);
		same([], sessionCookies($r['headers']));
		same([], sessionFiles($root, 'none-without-secure'));
	});

	check('Removed options throw while they are enabled', function () use ($server): void {
		$r = fetch($server, 'removed_rotate_interval', 'removed-rotate-interval');
		startsWith(\RuntimeException::class . ': session.rotate_interval was removed:', $r['json']['exception']);
		same([], sessionCookies($r['headers']));

		$r = fetch($server, 'removed_fingerprint', 'removed-fingerprint');
		startsWith(\RuntimeException::class . ': session.fingerprint was removed:', $r['json']['exception']);
		same([], sessionCookies($r['headers']));
	});

	check('Removed options with disabled values are ignored', function () use ($server): void {
		$r = fetch($server, 'removed_options_off', 'removed-options-off');
		same(null, $r['json']['exception']);
		same(1, \count(sessionCookies($r['headers'])));
	});

	check('A save path that cannot be created throws', function () use ($server): void {
		$r = fetch($server, 'save_path_not_creatable', 'save-path');
		startsWith(\RuntimeException::class . ': Cannot create session.save_path: ', $r['json']['exception']);
		same(true, \str_ends_with($r['json']['exception'], '/a-file/sessions'));
		same([], sessionCookies($r['headers']));
	});

	check('A setting that PHP refuses throws instead of running on the previous value', function () use ($server): void {
		$r = fetch($server, 'ini_refused', 'ini-refused');
		startsWith(\RuntimeException::class . ': PHP refused session.gc_divisor = 0:', $r['json']['exception']);
		same([], sessionCookies($r['headers']));

		$r = fetch($server, 'name_refused', 'name-refused');
		startsWith(\RuntimeException::class . ': PHP refused session.name = 12345:', $r['json']['exception']);
		same([], \array_values(\array_filter($r['headers'], static fn (string $header): bool => \stripos($header, 'Set-Cookie:') === 0)));
	});

	check('A start after a refused setting in the same request applies the setup again', function () use ($server, $root): void {
		$r = fetch($server, 'retry_after_refusal', 'retry');
		startsWith(\RuntimeException::class . ': PHP refused session.gc_divisor = 0:', $r['json']['first']);
		startsWith(\RuntimeException::class . ': PHP refused session.gc_divisor = 0:', $r['json']['exception']);
		same(false, $r['json']['active']);
		same([], \array_values(\array_filter($r['headers'], static fn (string $header): bool => \stripos($header, 'Set-Cookie:') === 0)));
		same([], sessionFiles($root, 'retry'));
	});

	check('Storage settings and the cookie lifetime come from cfg.session, whatever php.ini says', function () use ($server, $root): void {
		$r = fetch($server, 'storage_settings', 'storage-settings');
		$json = $r['json'];
		same(['1440', '1', '1000', '1', '0'], [$json['gc_maxlifetime'], $json['gc_probability'], $json['gc_divisor'], $json['use_strict_mode'], $json['cookie_lifetime']]);
		same(true, \str_ends_with($json['save_path'], '/sessions/storage-settings'));
		same(1, \count(sessionFiles($root, 'storage-settings')));
		// Lifetime 0: The session cookie has neither Expires nor Max-Age.
		same([['httponly' => true, 'path' => '/', 'samesite' => 'Lax']], sessionCookieAttributes($r['headers']));
	});

	check('destroy() throws when storage refuses, and leaves the session data and the cookie in place', function () use ($server, $root): void {
		$id = fetch($server, 'seed', 'destroy-failure')['json']['id'];

		$r = fetch($server, 'destroy_storage_failure', 'destroy-failure', SESSION_NAME . '=' . $id);
		same('value', $r['json']['kept_before']);
		same(\RuntimeException::class . ': session_destroy() failed; the session data is still in storage, and the browser keeps its cookie.', $r['json']['exception']);
		same([], sessionCookies($r['headers']));
		same(['sess_' . $id], sessionFiles($root, 'destroy-failure'));
		same(true, \str_contains((string)\file_get_contents($root . '/sessions/destroy-failure/sess_' . $id), 'kept|s:5:"value"'));
	});

	check('destroy() deletes the session and expires its cookie with the same attributes', function () use ($server, $root): void {
		$id = fetch($server, 'seed', 'destroy')['json']['id'];

		$r = fetch($server, 'destroy_existing', 'destroy', SESSION_NAME . '=' . $id);
		same('value', $r['json']['kept_before']);
		same(null, $r['json']['exception']);
		same(['deleted'], sessionCookies($r['headers']));
		$expired = sessionCookieAttributes($r['headers'])[0];
		same(['0', true, '/', 'Lax'], [$expired['max-age'] ?? null, $expired['httponly'] ?? null, $expired['path'] ?? null, $expired['samesite'] ?? null]);
		same([], sessionFiles($root, 'destroy'));
	});

	check('Session reads on a request without a session cookie create no session', function () use ($server, $root): void {
		$r = fetch($server, 'guest_read', 'guest-read');
		same([null, false, false], [$r['json']['get'], $r['json']['has'], $r['json']['active']]);
		noSession($r, $root, 'guest-read');
	});

	check('Flash reads, and writes that change nothing, create no session', function () use ($server, $root): void {
		$r = fetch($server, 'flash_guest', 'flash-guest');
		$empty = ['msg' => [], 'old' => [], 'err' => []];
		same([$empty, $empty, null, null, 'default', false, false], [
			$r['json']['pull'], $r['json']['peek_all'], $r['json']['peek'], $r['json']['take'],
			$r['json']['old_value'], $r['json']['has_old'], $r['json']['active'],
		]);
		noSession($r, $root, 'flash-guest');
	});

	check('A flash message survives one redirect through the session and is gone after it', function () use ($server): void {
		$id = sessionCookies(fetch($server, 'flash_write', 'flash')['headers'])[0] ?? '';
		same(true, $id !== '');

		$first = fetch($server, 'flash_pull', 'flash', SESSION_NAME . '=' . $id)['json']['pull'];
		same(['msg' => ['error' => ['Wrong password.']], 'old' => ['username' => 'alice'], 'err' => []], $first);
		same(['msg' => [], 'old' => [], 'err' => []], fetch($server, 'flash_pull', 'flash', SESSION_NAME . '=' . $id)['json']['pull']);
	});

	check('keep() preserves the flash data across one more pullAll()', function () use ($server): void {
		$id = sessionCookies(fetch($server, 'flash_write_kept', 'flash-kept')['headers'])[0] ?? '';
		$expected = ['msg' => ['error' => ['Wrong password.']], 'old' => ['username' => 'alice'], 'err' => []];

		same($expected, fetch($server, 'flash_pull', 'flash-kept', SESSION_NAME . '=' . $id)['json']['pull']);
		same($expected, fetch($server, 'flash_pull', 'flash-kept', SESSION_NAME . '=' . $id)['json']['pull']);
		same(['msg' => [], 'old' => [], 'err' => []], fetch($server, 'flash_pull', 'flash-kept', SESSION_NAME . '=' . $id)['json']['pull']);
	});

	check('A POST without a session fails CSRF verification as token_missing and creates no session', function () use ($server, $root): void {
		$r = fetch($server, 'csrf_guest_post', 'csrf-guest', '', ['X-CSRF-Token' => 'forged'], 'POST');
		same('token_missing', $r['json']['reason']);
		same(false, $r['json']['active']);
		noSession($r, $root, 'csrf-guest');
	});

	check('A CSRF token issued on a form page verifies on the POST that presents the session cookie', function () use ($server): void {
		$issued = fetch($server, 'csrf_issue', 'csrf');
		$id = sessionCookies($issued['headers'])[0] ?? '';
		same(true, $id !== '');

		$r = fetch($server, 'csrf_post', 'csrf', SESSION_NAME . '=' . $id, ['X-CSRF-Token' => $issued['json']['token']], 'POST');
		same(null, $r['json']['reason']);
		same([], sessionCookies($r['headers']));
	});

	check('A read with the session cookie resumes the session without a new cookie', function () use ($server): void {
		$id = fetch($server, 'seed', 'cookie-read')['json']['id'];

		$r = fetch($server, 'cookie_read', 'cookie-read', SESSION_NAME . '=' . $id);
		same(['value', $id], [$r['json']['get'], $r['json']['id']]);
		same([], sessionCookies($r['headers']));
	});

	check('A read with a cookie whose id storage does not know starts one new session', function () use ($server, $root): void {
		$stale = \str_repeat('a', 32);

		$r = fetch($server, 'cookie_read', 'stale-cookie', SESSION_NAME . '=' . $stale);
		same(null, $r['json']['get']);
		same(true, $r['json']['id'] !== $stale);
		same([$r['json']['id']], sessionCookies($r['headers']));
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'stale-cookie'));
	});

	check('A read after session_write_close() in the same request resumes the same session', function () use ($server, $root): void {
		$r = fetch($server, 'read_after_write_close', 'write-close');
		same('value', $r['json']['get']);
		same($r['json']['id_before'], $r['json']['id']);
		same([$r['json']['id']], sessionCookies($r['headers']));
		same(['sess_' . $r['json']['id']], sessionFiles($root, 'write-close'));
	});

	check('A read after destroy() in the same request does not resume the destroyed id', function () use ($server, $root): void {
		$id = fetch($server, 'seed', 'destroy-read')['json']['id'];

		$r = fetch($server, 'read_after_destroy', 'destroy-read', SESSION_NAME . '=' . $id);
		same(['value', null, false], [$r['json']['kept_before'], $r['json']['get'], $r['json']['active']]);
		same(['deleted'], sessionCookies($r['headers']));
		same([], sessionFiles($root, 'destroy-read'));
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
