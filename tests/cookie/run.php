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

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\Cookie;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Isolated suite for CitOmni\Http\Service\Cookie: Reading untrusted cookie input,
 * resolving the default attributes, and the same-request view after set().
 *
 * Usage:
 *   php tests/cookie/run.php
 *
 * Notes:
 * - Runs the real Cookie and Request services against the kernel doubles, without Composer.
 * - cfg.cookie is the shipped baseline (Registry::CFG_HTTP) plus per-case overrides.
 * - $_COOKIE is filled exactly as PHP's request parser fills it, e.g. the header
 *   "Cookie: _auth_rm[]=x" arrives as ['_auth_rm' => ['x']].
 * - Request::isHttps() reads $_SERVER; cases set HTTPS there. The case with
 *   CITOMNI_PUBLIC_ROOT_URL runs last, because a constant cannot be undefined.
 * - In the CLI, setcookie() succeeds until output passes through PHP's output layer.
 *   The suite reports through STDOUT, which bypasses that layer, so set() still works
 *   after earlier cases have reported.
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
require \dirname(__DIR__, 2) . '/src/Boot/Registry.php';
require \dirname(__DIR__, 2) . '/src/Service/Request.php';
require \dirname(__DIR__, 2) . '/src/Service/Cookie.php';

// Registry::CFG_HTTP evaluates CITOMNI_APP_PATH; any path works here.
\define('CITOMNI_APP_PATH', \sys_get_temp_dir());

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

/** Require $action to throw $class with a message that contains $fragment. */
function throws(string $class, string $fragment, callable $action): void {
	try {
		$action();
	} catch (\Throwable $error) {
		if (!$error instanceof $class || !\str_contains($error->getMessage(), $fragment)) {
			throw new \RuntimeException('Unexpected ' . $error::class . ': ' . $error->getMessage());
		}
		return;
	}
	throw new \RuntimeException("Expected {$class} ({$fragment})");
}

/**
 * Build a Cookie service on the baseline cfg with overrides.
 *
 * @param array<string,mixed> $cookie  Overrides for cfg.cookie.
 * @param string              $baseUrl http.base_url, or '' for none.
 * @param array<string,mixed> $options Service options.
 */
function cookieWith(array $cookie = [], string $baseUrl = 'http://127.0.0.1', array $options = []): Cookie {
	$http = Registry::CFG_HTTP['http'];
	if ($baseUrl !== '') {
		$http['base_url'] = $baseUrl;
	}
	$app = new App([
		'http'   => $http,
		'cookie' => mergeLastWins(Registry::CFG_HTTP['cookie'], $cookie),
	]);
	$app->set('request', new Request($app));
	return new Cookie($app, $options);
}

/** Fill $_COOKIE as the request parser would and build a Cookie service with Secure off. */
function cookieFor(array $parsedCookies): Cookie {
	$_COOKIE = $parsedCookies;
	return cookieWith(['secure' => false]);
}

/**
 * Replace the value a request brought for a host-only cookie and read it back through get().
 *
 * @param string $host HTTP_HOST of the request, as the browser sends it.
 * @return string|null The value get() returns in the same request.
 */
function readBackOn(string $host): ?string {
	$_SERVER['HTTP_HOST'] = $host;
	$_COOKIE = ['c' => 'old'];
	try {
		$cookie = cookieWith(['secure' => false]);
		same(true, $cookie->set('c', 'new'));
		return $cookie->get('c');
	} finally {
		unset($_SERVER['HTTP_HOST']);
		$_COOKIE = [];
	}
}

unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME'], $_SERVER['SERVER_PORT']);


// -- Reading untrusted input -----------------------------------------------

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


// -- Same-request view after set() -----------------------------------------

check('On the IPv6 host [::1], get() returns the value set() wrote in the same request', function (): void {
	same('new', readBackOn('[::1]'));
});

check('On the IPv6 host [2001:db8::1]:8443, get() returns the value set() wrote in the same request', function (): void {
	same('new', readBackOn('[2001:db8::1]:8443'));
});


// -- Default attributes ----------------------------------------------------

check('The baseline defaults are host-only, HttpOnly, SameSite=Lax and path /', function (): void {
	$defaults = cookieWith()->defaults();
	\ksort($defaults);
	same(['domain' => null, 'expires' => 0, 'httponly' => true, 'path' => '/', 'samesite' => 'Lax', 'secure' => false], $defaults);
});

check('An absolute base URL does not give the cookies a Domain', function (): void {
	foreach ([null, ''] as $domain) {
		same(null, cookieWith(['domain' => $domain], 'https://example.com')->defaults()['domain']);
	}
});

check('cookie.domain is used as configured, lowercase and without a leading dot', function (): void {
	same('example.com', cookieWith(['domain' => '.Example.COM'])->defaults()['domain']);
	same(null, cookieWith(['domain' => '.'])->defaults()['domain']);
});

check('Paths get a leading slash and no trailing slash, also in attributes()', function (): void {
	same('/app', cookieWith(['path' => '/app/'])->defaults()['path']);
	same('/', cookieWith(['path' => null])->defaults()['path']);
	$cookie = cookieWith();
	same(['/app', '/', '/'], [$cookie->attributes(['path' => 'app'])['path'], $cookie->attributes(['path' => ''])['path'], $cookie->attributes(['path' => '//'])['path']]);
	throws(\InvalidArgumentException::class, 'path must be a string or null', fn () => $cookie->attributes(['path' => ['/app']]));
});

check('Secure is inferred from an https base URL or an HTTPS request', function (): void {
	same(true, cookieWith([], 'https://example.com')->defaults()['secure']);
	same(false, cookieWith([], 'http://example.com')->defaults()['secure']);
	$_SERVER['HTTPS'] = 'on';
	try {
		same(true, cookieWith([], 'http://example.com')->defaults()['secure']);
	} finally {
		unset($_SERVER['HTTPS']);
	}
});

check('An explicit cookie.secure wins over the inference', function (): void {
	same(false, cookieWith(['secure' => false], 'https://example.com')->defaults()['secure']);
	same(true, cookieWith(['secure' => true], 'http://example.com')->defaults()['secure']);
});

check('Invalid cfg values throw instead of falling back', function (): void {
	throws(\RuntimeException::class, 'cookie.secure must be true, false or null', fn () => cookieWith(['secure' => 'yes']));
	throws(\InvalidArgumentException::class, 'SameSite must be', fn () => cookieWith(['samesite' => 'Laxx']));
	throws(\InvalidArgumentException::class, "'httponly' must be a bool", fn () => cookieWith(['httponly' => 1]));
	throws(\InvalidArgumentException::class, 'domain must be a string or null', fn () => cookieWith(['domain' => ['example.com']]));
});

check('SameSite=None requires Secure, also when service options set it', function (): void {
	throws(\RuntimeException::class, 'SameSite=None requires Secure=true', fn () => cookieWith(['samesite' => 'None', 'secure' => false]));
	throws(\RuntimeException::class, 'SameSite=None requires Secure=true', fn () => cookieWith(['secure' => false], 'http://127.0.0.1', ['samesite' => 'none']));
	same('None', cookieWith(['samesite' => 'none', 'secure' => true])->defaults()['samesite']);
});

check('attributes() puts overrides on top of the defaults and validates the result', function (): void {
	$cookie = cookieWith(['secure' => true]);
	$resolved = $cookie->attributes(['samesite' => 'strict', 'domain' => '', 'path' => '/app']);
	same(['Strict', null, '/app', true], [$resolved['samesite'], $resolved['domain'], $resolved['path'], $resolved['secure']]);
	same('Lax', $cookie->defaults()['samesite']);
	throws(\RuntimeException::class, 'SameSite=None requires Secure=true', fn () => $cookie->attributes(['samesite' => 'None', 'secure' => false]));
	throws(\InvalidArgumentException::class, "'secure' must be a bool", fn () => $cookie->attributes(['secure' => 'true']));
});

check('An invalid SameSite option on set() or delete() throws before any header', function (): void {
	$cookie = cookieWith();
	throws(\InvalidArgumentException::class, 'SameSite must be', fn () => $cookie->set('c', 'v', ['samesite' => 'none ']));
	throws(\InvalidArgumentException::class, 'SameSite must be', fn () => $cookie->delete('c', ['samesite' => 'Strictly']));
});

check('Secure is inferred from an https CITOMNI_PUBLIC_ROOT_URL defined before boot', function (): void {
	// Kernel respects a constant that an entry point defines; it may be the only https signal.
	\define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');
	same(true, cookieWith([], '')->defaults()['secure']);
	same(true, cookieWith([], 'http://www.example.com')->defaults()['secure']);
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
