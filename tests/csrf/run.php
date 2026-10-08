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

namespace CitOmni\Http\Tests\Csrf;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Enum\CsrfFailureReason;
use CitOmni\Http\Exception\CsrfException;
use CitOmni\Http\Exception\CsrfVerificationException;
use CitOmni\Http\Service\Csrf;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Isolated suite for CitOmni\Http\Service\Csrf: the three defense layers (fetch
 * metadata, Origin/Referer, token), token masking, lifecycle and configuration.
 *
 * Usage:
 *   php tests/csrf/run.php
 *
 * Notes:
 * - Runs the real Csrf and Request services against the kernel doubles. Session
 *   and log are doubles; a SessionStore stands for one browser's session across
 *   its requests, and each request gets a fresh Csrf, as in production.
 * - Every case starts from the shipped baseline (Registry::CFG_HTTP) and applies
 *   its own overrides, so a changed default shows up here.
 * - Requests are HTTPS POSTs to https://example.test/form with a same-origin
 *   Origin header unless a case says otherwise.
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

// Registry::CFG_HTTP derives paths from CITOMNI_APP_PATH; this suite writes nothing there.
\define('CITOMNI_APP_PATH', \sys_get_temp_dir() . '/citomni_http_csrf_test_unused');

require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Enum/CsrfFailureReason', 'Exception/CsrfException', 'Exception/CsrfVerificationException', 'Service/Request', 'Service/Csrf'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

const BASE_SERVER = [
	'REQUEST_METHOD' => 'POST',
	'REQUEST_URI'    => '/form',
	'HTTP_HOST'      => 'example.test',
	'HTTPS'          => 'on',
	'SERVER_PORT'    => '443',
	'REMOTE_ADDR'    => '203.0.113.10',
	'HTTP_ORIGIN'    => 'https://example.test',
];

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

/** Run $fn and return what it throws; fail when it returns normally. */
function thrown(callable $fn): \Throwable {
	try {
		$fn();
	} catch (\Throwable $error) {
		return $error;
	}
	throw new \RuntimeException('Expected an exception; none was thrown');
}

/**
 * Session service double: One browser's server-side session, shared by its requests.
 *
 * Follows the Session contract: start() and set() create the session when there is
 * none; get() and remove() never do. $exists records whether it was created.
 */
final class SessionStore {
	public array $data = [];
	public bool $exists = false;

	public function isActive(): bool {
		return $this->exists;
	}

	public function start(): void {
		$this->exists = true;
	}

	public function get(string $key): mixed {
		return $this->data[$key] ?? null;
	}

	public function set(string $key, mixed $value): void {
		$this->exists = true;
		$this->data[$key] = $value;
	}

	public function remove(string $key): void {
		unset($this->data[$key]);
	}
}

/** Log service double that records write() calls. */
final class LogSpy {
	/** @var list<array{channel: string, category: string, message: string, context: array}> */
	public array $entries = [];

	public function write(string $channel, string $category, string $message, array $context = []): void {
		$this->entries[] = ['channel' => $channel, 'category' => $category, 'message' => $message, 'context' => $context];
	}
}

/**
 * Build the Csrf service for one request in a browser session.
 *
 * @param array<string, string|null> $server  Overrides for BASE_SERVER; null removes a key.
 * @param array<string, mixed>       $post    The parsed form body ($_POST).
 * @param array<string, mixed>       $cfg     Overrides for the security.csrf baseline.
 */
function csrfFor(SessionStore $session, array $server = [], array $post = [], array $cfg = [], ?LogSpy $log = null): Csrf {
	$_SERVER = \array_filter(\array_replace(BASE_SERVER, $server), static fn (?string $value): bool => $value !== null);
	$_POST = $post;

	$app = new App(mergeLastWins(
		['http' => Registry::CFG_HTTP['http'], 'security' => ['csrf' => Registry::CFG_HTTP['security']['csrf']]],
		['security' => ['csrf' => $cfg]]
	));
	$app->set('session', $session);
	$app->set('request', new Request($app));
	if ($log !== null) {
		$app->set('log', $log);
	}
	return new Csrf($app);
}

/** Issue a token on a GET page view, as rendering a form does. */
function issueToken(SessionStore $session, array $cfg = []): string {
	return csrfFor($session, ['REQUEST_METHOD' => 'GET'], [], $cfg)->token();
}

/** Run requireValid() and return the failure reason, or null when the request passes. */
function reason(Csrf $csrf): ?string {
	try {
		$csrf->requireValid();
		return null;
	} catch (CsrfVerificationException $error) {
		return $error->reason->value;
	}
}


// -- 1. Request scope ---------------------------------------------------------

check('Safe methods pass without token, Origin or session', function (): void {
	foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
		$session = new SessionStore();
		$log = new LogSpy();
		$csrf = csrfFor($session, ['REQUEST_METHOD' => $method, 'HTTP_ORIGIN' => 'https://evil.test', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], [], [], $log);
		same(false, $csrf->isProtectedMethod(), $method);
		same(true, $csrf->verify(), $method);
		same(null, reason($csrf), $method);
		same(false, $session->exists, $method);
		same([], $log->entries, $method);
	}
});

check('Disabled protection passes unsafe requests without touching the session', function (): void {
	$session = new SessionStore();
	$csrf = csrfFor($session, ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], [], ['enabled' => false]);
	same(false, $csrf->isEnabled());
	same(true, $csrf->verify());
	same(null, reason($csrf));
	same(false, $session->exists);
});

check('protect_methods replaces the default list and matches case-insensitively', function (): void {
	$cfg = ['protect_methods' => ['post', ' Put ', '']];
	$session = new SessionStore();
	same(false, csrfFor($session, ['REQUEST_METHOD' => 'DELETE'], [], $cfg)->isProtectedMethod());
	same(true, csrfFor($session, ['REQUEST_METHOD' => 'DELETE'], [], $cfg)->verify());
	same('token_missing', reason(csrfFor($session, ['REQUEST_METHOD' => 'PUT'], [], $cfg)));
	same('token_missing', reason(csrfFor($session, ['REQUEST_METHOD' => 'post'], [], $cfg)));
});


// -- 2. Token layer -----------------------------------------------------------

check('A same-origin POST passes with the token in the header or in the form field', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	same(true, $session->exists);
	same(true, csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $token])->verify());
	same(true, csrfFor($session, [], ['_csrf' => $token])->verify());
});

check('Each token() is masked differently and verifies against the same session secret', function (): void {
	$session = new SessionStore();
	$csrf = csrfFor($session, ['REQUEST_METHOD' => 'GET']);
	$first = $csrf->token();
	$secret = $session->data['_csrf'];
	$second = $csrf->token();

	same(1, \preg_match('/^[0-9a-f]{64}$/', $secret), 'secret of the baseline 32 bytes');
	same($secret, $session->data['_csrf']);
	same(true, $first !== $second);
	same(true, $first !== $secret && $second !== $secret);
	same(true, csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $first])->verify());
	same(true, csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $second])->verify());
});

check('requireValid() throws the reason for which verify() returns false', function (): void {
	$session = new SessionStore();
	issueToken($session);
	same(false, csrfFor($session)->verify());

	$error = thrown(static fn () => csrfFor($session)->requireValid());
	same(CsrfVerificationException::class, $error::class);
	same(CsrfFailureReason::TokenMissing, $error->reason);
	same('CSRF verification failed: token_missing', $error->getMessage());
});

check('Foreign, malformed and unbacked tokens fail with their own reasons', function (): void {
	$session = new SessionStore();
	issueToken($session);
	$foreign = issueToken(new SessionStore());

	same('token_mismatch', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $foreign])));
	same('token_invalid', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => 'not base64!'])));
	same('token_invalid', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => \base64_encode('too short')])));
	// A token submitted to a session that holds no secret proves nothing.
	same('token_missing', reason(csrfFor(new SessionStore(), ['HTTP_X_CSRF_TOKEN' => $foreign])));
});

check('The header token wins; the form field is read only for POST', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	same('token_invalid', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => 'garbage'], ['_csrf' => $token])));
	same('token_missing', reason(csrfFor($session, ['REQUEST_METHOD' => 'PUT'], ['_csrf' => $token])));
	same(null, reason(csrfFor($session, ['REQUEST_METHOD' => 'PUT', 'HTTP_X_CSRF_TOKEN' => $token])));

	// Custom names apply to both channels.
	$cfg = ['header_name' => 'X-Token', 'field_name' => 'tok'];
	same(null, reason(csrfFor($session, ['HTTP_X_TOKEN' => $token], [], $cfg)));
	same(null, reason(csrfFor($session, [], ['tok' => $token], $cfg)));
	same('token_missing', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $token], ['_csrf' => $token], $cfg)));
});

check('An array-shaped form token fails as token_invalid, without a PHP warning', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	// PHP parses the body "_csrf[]=x" into ['_csrf' => ['x']].
	same('token_invalid', reason(csrfFor($session, [], ['_csrf' => ['x']])));
	same('token_invalid', reason(csrfFor($session, [], ['_csrf' => [$token]])));
});

check('Unmasked tokens are the raw secret and compare case-insensitively', function (): void {
	$cfg = ['mask_tokens' => false];
	$session = new SessionStore();
	$token = issueToken($session, $cfg);
	same($session->data['_csrf'], $token);
	same(null, reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => \strtoupper($token)], [], $cfg)));
	same('token_invalid', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => \substr($token, 1)], [], $cfg)));
	// A masked token does not pass as a raw one.
	same('token_invalid', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => issueToken($session)], [], $cfg)));
});

check('Verification and clear() never create a session', function (): void {
	$token = issueToken(new SessionStore());
	$session = new SessionStore();
	// A forged POST, with and without a token, to a browser that has no session.
	same('token_missing', reason(csrfFor($session)));
	same('token_missing', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $token])));
	csrfFor($session, ['REQUEST_METHOD' => 'GET'])->clear();
	same(false, $session->exists);
});

check('rotate() and clear() invalidate tokens issued earlier', function (): void {
	$session = new SessionStore();
	$old = issueToken($session);
	$new = csrfFor($session, ['REQUEST_METHOD' => 'GET'])->rotate();
	same('token_mismatch', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $old])));
	same(null, reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $new])));

	csrfFor($session, ['REQUEST_METHOD' => 'GET'])->clear();
	same(false, isset($session->data['_csrf']));
	same('token_missing', reason(csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $new])));
});

check('A corrupted session secret fails fast with CsrfException', function (): void {
	$token = issueToken(new SessionStore());
	foreach (['abc', \str_repeat('z', 64), 12345] as $corrupted) {
		$session = new SessionStore();
		$session->data['_csrf'] = $corrupted;
		$error = thrown(static fn () => csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $token])->verify());
		same(CsrfException::class, $error::class, \var_export($corrupted, true));
		same('Internal error: session contains malformed CSRF token.', $error->getMessage());
	}
});

check('htmlField() renders an escaped hidden input whose token verifies', function (): void {
	$cfg = ['field_name' => 'csrf"x'];
	$session = new SessionStore();
	$field = csrfFor($session, ['REQUEST_METHOD' => 'GET'], [], $cfg)->htmlField();
	same(1, \preg_match('/^<input type="hidden" name="csrf&quot;x" value="([^"]+)">$/', $field, $m), $field);
	same(null, reason(csrfFor($session, [], ['csrf"x' => \html_entity_decode($m[1], \ENT_QUOTES)], $cfg)));
});


// -- 3. Fetch metadata layer --------------------------------------------------

check('Cross-site fetch metadata is rejected before Origin and token are checked', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	same('fetch_metadata_rejected', reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_X_CSRF_TOKEN' => $token])));
	same(null, reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_X_CSRF_TOKEN' => $token], [], ['fetch_metadata' => ['enabled' => false]])));
});

check('same-origin, none and same-site pass fetch metadata; same-site can be refused', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	foreach (['same-origin', 'Same-Origin', 'none', 'same-site'] as $site) {
		same(null, reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => $site, 'HTTP_X_CSRF_TOKEN' => $token])), $site);
	}
	same('fetch_metadata_rejected', reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_X_CSRF_TOKEN' => $token], [], ['fetch_metadata' => ['allow_same_site' => false]])));
});


check('Requests from trusted origins pass fetch metadata; the other layers still apply', function (): void {
	$cfg = ['trusted_origins' => ['https://partner.test', 'admin.example.test'], 'fetch_metadata' => ['allow_same_site' => false]];
	$session = new SessionStore();
	$token = issueToken($session, $cfg);
	$from = static fn (string $site, ?string $origin, string $submitted): ?string => reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => $site, 'HTTP_ORIGIN' => $origin, 'HTTP_X_CSRF_TOKEN' => $submitted], [], $cfg));

	same(null, $from('cross-site', 'https://partner.test', $token), 'trusted full origin');
	same(null, $from('same-site', 'https://admin.example.test', $token), 'trusted bare host while same-site is refused');
	same('fetch_metadata_rejected', $from('cross-site', 'https://evil.test', $token), 'untrusted origin');
	same('fetch_metadata_rejected', $from('same-site', 'https://other.example.test', $token), 'untrusted same-site origin');
	same('fetch_metadata_rejected', $from('cross-site', null, $token), 'no Origin');
	same('fetch_metadata_rejected', $from('cross-site', 'null', $token), 'opaque Origin');
	same('token_mismatch', $from('cross-site', 'https://partner.test', issueToken(new SessionStore())), 'token still checked');
});


// -- 4. Origin and Referer layer ----------------------------------------------

check('A foreign Origin is rejected even with a valid token', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	foreach (['https://evil.test', 'https://example.test.evil.test', 'http://example.test', 'https://example.test:8443', 'not a url'] as $origin) {
		same('origin_mismatch', reason(csrfFor($session, ['HTTP_ORIGIN' => $origin, 'HTTP_X_CSRF_TOKEN' => $token])), $origin);
	}
});

check('Origins compare scheme, host and port, with default ports normalized', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	same(null, reason(csrfFor($session, ['HTTP_ORIGIN' => 'https://EXAMPLE.test:443', 'HTTP_X_CSRF_TOKEN' => $token])));

	// A request on a non-default port must name that port.
	$port = ['HTTP_HOST' => 'example.test:8443', 'SERVER_PORT' => '8443', 'HTTP_X_CSRF_TOKEN' => $token];
	same(null, reason(csrfFor($session, $port + ['HTTP_ORIGIN' => 'https://example.test:8443'])));
	same('origin_mismatch', reason(csrfFor($session, $port + ['HTTP_ORIGIN' => 'https://example.test'])));
});

check('HTTPS without Origin falls back to the Referer, unless that is turned off', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	same(null, reason(csrfFor($session, ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://example.test/form?step=2', 'HTTP_X_CSRF_TOKEN' => $token])));
	// The literal "null" Origin counts as absent.
	same(null, reason(csrfFor($session, ['HTTP_ORIGIN' => 'null', 'HTTP_REFERER' => 'https://example.test/', 'HTTP_X_CSRF_TOKEN' => $token])));
	same('referer_missing', reason(csrfFor($session, ['HTTP_ORIGIN' => null, 'HTTP_X_CSRF_TOKEN' => $token])));
	same('referer_mismatch', reason(csrfFor($session, ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://evil.test/form', 'HTTP_X_CSRF_TOKEN' => $token])));
	same('origin_missing', reason(csrfFor($session, ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://example.test/', 'HTTP_X_CSRF_TOKEN' => $token], [], ['referer_fallback_on_https' => false])));
});

check('Plain HTTP may omit Origin unless allow_missing_origin_on_http is off', function (): void {
	$session = new SessionStore();
	$token = issueToken($session);
	$http = ['HTTPS' => null, 'SERVER_PORT' => '80', 'HTTP_X_CSRF_TOKEN' => $token];
	same(null, reason(csrfFor($session, $http + ['HTTP_ORIGIN' => null])));
	same('origin_missing', reason(csrfFor($session, $http + ['HTTP_ORIGIN' => null], [], ['allow_missing_origin_on_http' => false])));
	// A present Origin is still compared.
	same(null, reason(csrfFor($session, $http + ['HTTP_ORIGIN' => 'http://example.test'])));
	same('origin_mismatch', reason(csrfFor($session, $http + ['HTTP_ORIGIN' => 'http://evil.test'])));
});

check('trusted_origins: full origins match exactly, bare hostnames match the host', function (): void {
	$cfg = ['trusted_origins' => ['https://admin.example.test', 'partner.example.test', 'ftp://files.example.test', 'bad host', 'host/path']];
	$session = new SessionStore();
	$token = issueToken($session, $cfg);
	foreach (['https://admin.example.test', 'https://admin.example.test:443', 'http://partner.example.test:8080', 'https://PARTNER.example.test'] as $origin) {
		same(null, reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => $origin, 'HTTP_X_CSRF_TOKEN' => $token], [], $cfg)), $origin);
	}
	foreach (['http://admin.example.test', 'https://admin.example.test:8443', 'https://other.example.test', 'ftp://files.example.test', 'https://files.example.test'] as $origin) {
		same('origin_mismatch', reason(csrfFor($session, ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => $origin, 'HTTP_X_CSRF_TOKEN' => $token], [], $cfg)), $origin);
	}
});


// -- 5. Logging and configuration ---------------------------------------------

check('Failures are logged to the security channel without token values', function (): void {
	$session = new SessionStore();
	issueToken($session);
	$secret = $session->data['_csrf'];
	$foreign = issueToken(new SessionStore());

	$log = new LogSpy();
	same(false, csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $foreign], [], [], $log)->verify(['route' => 'profile']));
	same(1, \count($log->entries));
	$entry = $log->entries[0];
	same(['security', 'csrf.failure', 'token_mismatch'], [$entry['channel'], $entry['category'], $entry['message']]);
	same(['POST', '/form', 'https://example.test', 'profile'], [$entry['context']['method'], $entry['context']['uri'], $entry['context']['origin'], $entry['context']['route']]);
	$recorded = \serialize($entry);
	same(false, \str_contains($recorded, $secret), 'session secret in log');
	same(false, \str_contains($recorded, $foreign), 'submitted token in log');

	$quiet = new LogSpy();
	same(false, csrfFor($session, ['HTTP_X_CSRF_TOKEN' => $foreign], [], ['log_failures' => false], $quiet)->verify());
	same([], $quiet->entries);
});

check('Invalid configuration fails at construction', function (): void {
	foreach ([['token_bytes' => 15], ['field_name' => ''], ['header_name' => ''], ['session_key' => '']] as $cfg) {
		$error = thrown(static fn () => csrfFor(new SessionStore(), [], [], $cfg));
		same(CsrfException::class, $error::class, \json_encode($cfg));
	}
	$session = new SessionStore();
	issueToken($session, ['token_bytes' => 16]);
	same(32, \strlen($session->data['_csrf']), 'secret of 16 bytes');
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
