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

namespace CitOmni\Http\Tests\Request;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Tests\Support\App;
use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Request: proxy trust, URL parts,
 * headers, the client IP and the JSON body.
 *
 * Usage:
 *   php tests/request/run.php
 *
 * Notes:
 * - Runs the real Request service against the kernel doubles, with cfg.http from
 *   the shipped baseline (Registry::CFG_HTTP) plus per-case overrides.
 * - Most cases set $_SERVER directly. ip() always answers "CLI" in CLI and the
 *   body (php://input) is empty there, so the last cases send real requests to
 *   PHP's built-in web server with server.php as router.
 * - Addresses are RFC 5737 / RFC 3849 documentation addresses. PHP's
 *   FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE treats them as public.
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
require \dirname(__DIR__) . '/support/fixtures.php';
foreach (['Boot/Registry', 'Service/Request'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

const BASE_SERVER = [
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI'    => '/path?x=1',
	'QUERY_STRING'   => 'x=1',
	'HTTP_HOST'      => 'example.test',
	'SERVER_PORT'    => '80',
	'REMOTE_ADDR'    => '203.0.113.5',
];

const PROXY_HEADERS = [
	'HTTP_X_FORWARDED_HOST'  => 'www.example.test',
	'HTTP_X_FORWARDED_PROTO' => 'https',
	'HTTP_X_FORWARDED_PORT'  => '8443',
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

/**
 * Build Request for one request.
 *
 * @param array<string, string|null> $server  Overrides for BASE_SERVER; null removes a key.
 * @param array<string, mixed>       $http    Overrides for the cfg.http baseline.
 * @param array<string, mixed>       $get     The parsed query string ($_GET).
 * @param array<string, mixed>       $post    The parsed form body ($_POST).
 */
function requestFor(array $server = [], array $http = [], array $get = [], array $post = []): Request {
	$_SERVER = \array_filter(\array_replace(BASE_SERVER, $server), static fn (?string $value): bool => $value !== null);
	$_GET = $get;
	$_POST = $post;
	return new Request(new App(['http' => mergeLastWins(Registry::CFG_HTTP['http'], $http)]));
}

$root = tempDir('request');
\define('CITOMNI_APP_PATH', $root);
$server = null;

try {

	// -- 1. Proxy trust -------------------------------------------------------

	check('Proxy headers count only when trust_proxy is on and the peer is a trusted proxy', function (): void {
		$direct = static function (Request $request, string $label): void {
			same(['example.test', 80, false], [$request->host(), $request->port(), $request->isHttps()], $label);
		};
		$direct(requestFor(PROXY_HEADERS + ['REMOTE_ADDR' => '10.0.0.5']), 'baseline: trust_proxy off');
		$direct(requestFor(PROXY_HEADERS + ['REMOTE_ADDR' => '203.0.113.5'], ['trust_proxy' => true]), 'peer is not a trusted proxy');
		$direct(requestFor(PROXY_HEADERS + ['REMOTE_ADDR' => '10.0.0.5'], ['trust_proxy' => true, 'trusted_proxies' => []]), 'empty trusted_proxies');

		$request = requestFor(PROXY_HEADERS + ['REMOTE_ADDR' => '10.0.0.5'], ['trust_proxy' => true]);
		same(['www.example.test', 8443, true, 'https'], [$request->host(), $request->port(), $request->isHttps(), $request->scheme()]);
		same('https://www.example.test:8443/path?x=1', $request->fullUrl());
	});

	check('Trusted proxies match exact addresses and IPv4/IPv6 CIDR ranges', function (): void {
		$http = ['trust_proxy' => true, 'trusted_proxies' => ['192.0.2.1', '172.16.0.0/20', '2001:db8::/32', '::1', '10.0.0.0/33', 'not-an-ip']];
		$viaProxy = static fn (string $peer): bool => requestFor(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => 'https'], $http)->isHttps();

		foreach (['192.0.2.1', '172.16.0.1', '172.16.15.255', '2001:db8::1', '2001:db8:ffff::1', '::1'] as $peer) {
			same(true, $viaProxy($peer), $peer);
		}
		// An out-of-range mask and a non-address entry match nothing.
		foreach (['192.0.2.2', '172.16.16.0', '172.15.255.255', '2001:db9::1', '10.0.0.1', 'not-an-ip', ''] as $peer) {
			same(false, $viaProxy($peer), $peer !== '' ? $peer : 'empty REMOTE_ADDR');
		}
	});

	check('A trusted proxy entry with a malformed mask matches no address', function (): void {
		$http = ['trust_proxy' => true, 'trusted_proxies' => ['10.0.0.0/', '10.0.0.0/x', '10.0.0.0/+8', '::/']];
		foreach (['203.0.113.9', '10.1.2.3', '2001:db8::1'] as $peer) {
			same(false, requestFor(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => 'https'], $http)->isHttps(), $peer);
		}
		// Whitespace around a decimal mask is still accepted.
		$http = ['trust_proxy' => true, 'trusted_proxies' => ['10.0.0.0/ 8 ']];
		same(true, requestFor(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'], $http)->isHttps(), 'mask with whitespace');
	});

	check('Forwarded (RFC 7239) supplies host, port and proto from the first hop', function (): void {
		$http = ['trust_proxy' => true];
		$request = requestFor(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_FORWARDED' => 'for=203.0.113.43;proto=https;host="www.example.test:8443", for=10.0.0.9;proto=http;host=proxy.local'], $http);
		same(['www.example.test', 8443, true], [$request->host(), $request->port(), $request->isHttps()]);

		$request = requestFor(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_FORWARDED' => 'Host="[2001:db8::1]:8443";Proto=HTTPS'], $http);
		same(['2001:db8::1', 8443, true], [$request->host(), $request->port(), $request->isHttps()]);
	});


	// -- 2. URL parts ---------------------------------------------------------

	check('host() strips the port, unwraps IPv6 literals and keeps only host characters', function (): void {
		$cases = [
			'example.test:8080'  => 'example.test',
			'[2001:db8::1]:8443' => '2001:db8::1',
			'[::1]'              => '::1',
			'2001:db8::1'        => '2001:db8::1',
		];
		foreach ($cases as $header => $expected) {
			same($expected, requestFor(['HTTP_HOST' => $header])->host(), $header);
		}
		same('srv.example.test', requestFor(['HTTP_HOST' => null, 'SERVER_NAME' => 'srv.example.test'])->host());
		same('localhost', requestFor(['HTTP_HOST' => null])->host());
		foreach (["example.test\r\nX-Injected: 1", 'evil.test/path?q#f', "a b\tc"] as $header) {
			same(1, \preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', requestFor(['HTTP_HOST' => $header])->host()), \json_encode($header));
		}
	});

	check('port() takes SERVER_PORT, normalized behind TLS terminators, or the scheme default', function (): void {
		same(8080, requestFor(['SERVER_PORT' => '8080'])->port());
		same(443, requestFor(['HTTPS' => 'on', 'SERVER_PORT' => '80'])->port());
		same(80, requestFor(['SERVER_PORT' => null])->port());
		same(443, requestFor(['HTTPS' => 'on', 'SERVER_PORT' => null])->port());
		same(8080, requestFor(['SERVER_PORT' => '8080', 'HTTP_X_FORWARDED_PORT' => '9999'])->port(), 'untrusted X-Forwarded-Port');
	});

	check('isHttps() reads HTTPS, REQUEST_SCHEME and port 443', function (): void {
		foreach ([['HTTPS' => 'on'], ['HTTPS' => '1'], ['REQUEST_SCHEME' => 'HTTPS'], ['SERVER_PORT' => '443']] as $server) {
			same(true, requestFor($server)->isHttps(), \json_encode($server));
		}
		foreach ([[], ['HTTPS' => 'off'], ['HTTPS' => 'OFF'], ['HTTPS' => '']] as $server) {
			same(false, requestFor($server)->isHttps(), \json_encode($server));
		}
	});

	check('uri(), pathRaw() and queryString() split the request target', function (): void {
		$request = requestFor(['REQUEST_URI' => '/a/b?x=1&y=2', 'QUERY_STRING' => 'x=1&y=2']);
		same(['/a/b?x=1&y=2', '/a/b', 'x=1&y=2'], [$request->uri(), $request->pathRaw(), $request->queryString()]);
		$request = requestFor(['REQUEST_URI' => null, 'QUERY_STRING' => null]);
		same(['/', '/', ''], [$request->uri(), $request->pathRaw(), $request->queryString()]);
	});

	check('pathFromAppRoot() strips the base path of http.base_url', function (): void {
		$http = ['base_url' => 'https://example.test/app/'];
		foreach (['/app/users' => '/users', '/app/users/' => '/users/', '/app' => '/', '/app/' => '/', '/other' => '/other', '/' => '/'] as $path => $expected) {
			same($expected, requestFor(['REQUEST_URI' => $path . '?x=1'], $http)->pathFromAppRoot(), $path);
		}
		same('/users', requestFor(['REQUEST_URI' => '/users'], ['base_url' => 'https://example.test/'])->pathFromAppRoot(), 'app at the web root');
	});

	check('baseUrl() and fullUrl() normalize slashes and omit default ports', function (): void {
		same('https://example.test/app/', requestFor([], ['base_url' => 'https://example.test/app'])->baseUrl());
		same('https://example.test/app/', requestFor([], ['base_url' => 'https://example.test/app//'])->baseUrl());
		same('https://example.test/x?y', requestFor(['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_URI' => '/x?y'])->fullUrl());
		same('http://example.test:8080/x', requestFor(['SERVER_PORT' => '8080', 'REQUEST_URI' => '/x'])->fullUrl());
	});


	// -- 3. Headers and input -------------------------------------------------

	check('header() maps names to HTTP_* keys and headers() lists them lowercased', function (): void {
		$request = requestFor(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5']);
		same('XMLHttpRequest', $request->header('x-requested-with'));
		same('text/plain', $request->header('Content-Type'));
		same('5', $request->header('content-length'));
		same(null, $request->header('X-Missing'));
		same('fallback', $request->header('X-Missing', 'fallback'));
		same(true, $request->isAjax());
		same(['host' => 'example.test', 'x-requested-with' => 'XMLHttpRequest', 'content-type' => 'text/plain', 'content-length' => '5'], $request->headers());
	});

	check('contentType() drops parameters and lowercases the media type', function (): void {
		same('application/json', requestFor(['CONTENT_TYPE' => ' Application/JSON ; charset=UTF-8'])->contentType());
		same(null, requestFor()->contentType());
	});

	check('sanitize() escapes string input and treats array-shaped input as absent', function (): void {
		// PHP parses "?list[]=x" into ['list' => ['x']].
		$request = requestFor(get: ['q' => ' <b>"x"</b> ', 'list' => ['x']], post: ['list' => 'from body', 'only' => ['y']]);
		same('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $request->sanitize('q'));
		same('from body', $request->sanitize('list'), 'array in the query, string in the body');
		same(null, $request->sanitize('list', 'get'));
		same(null, $request->sanitize('only', 'post'));
		same(null, $request->sanitize('missing'));
	});


	// -- 4. Real requests: client IP, JSON body, public root ------------------

	$server = new FixtureServer($root, __DIR__ . '/server.php');

	/**
	 * Ask the fixture for one report.
	 *
	 * @param array<string, string> $query  Fixture settings: peer, trust, proxies, arg, root.
	 */
	$report = static function (array $query, array $headers = [], string $method = 'GET', string $body = '', string $path = '/report') use ($server): array {
		$r = $server->request($method, $path . '?' . \http_build_query($query), $headers, $body);
		same(200, $r['status'], $r['body']);
		return \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
	};

	check('ip() returns a public peer address and ignores forwarding headers from untrusted peers', function () use ($report): void {
		$spoof = ['X-Forwarded-For' => '198.51.100.7', 'Client-Ip' => '198.51.100.8', 'X-Cluster-Client-Ip' => '198.51.100.9'];
		same('203.0.113.5', $report(['peer' => '203.0.113.5'], $spoof)['ip'], 'baseline: trust_proxy off');
		same('203.0.113.5', $report(['peer' => '203.0.113.5', 'trust' => '1'], $spoof)['ip'], 'peer is not a trusted proxy');
		same('2001:db8::5', $report(['peer' => '2001:db8::5'])['ip'], 'IPv6 peer');
	});

	check('Behind a trusted proxy, ip() returns the forwarded client address', function () use ($report): void {
		$viaProxy = ['peer' => '192.0.2.1', 'proxies' => '192.0.2.1'];
		$forwarded = ['X-Forwarded-For' => '198.51.100.7'];
		same('198.51.100.7', $report($viaProxy + ['trust' => '1'], $forwarded)['ip']);
		same('198.51.100.7', $report($viaProxy + ['trust' => '1'], ['X-Forwarded-For' => '10.1.1.1, 198.51.100.7'])['ip'], 'with a private entry before the client');
		// The argument overrides cfg.http.trust_proxy in both directions.
		same('192.0.2.1', $report($viaProxy + ['trust' => '1', 'arg' => 'false'], $forwarded)['ip'], 'ip(false)');
		same('198.51.100.7', $report($viaProxy + ['arg' => 'true'], $forwarded)['ip'], 'ip(true)');
	});

	check('ip() reads X-Forwarded-For from the right and stops at the first address that is not a trusted proxy', function () use ($report): void {
		$ip = static fn (string $xff): string => $report(['peer' => '10.0.0.5', 'trust' => '1'], ['X-Forwarded-For' => $xff])['ip'];
		// The proxy appends the address it saw; whatever the client sent stays on the left.
		same('198.51.100.7', $ip('1.2.3.4, 198.51.100.7'), 'client-supplied entry on the left');
		same('203.0.113.50', $ip('1.2.3.4, 203.0.113.50, 10.0.0.9'), 'untrusted hop after a trusted one');
		same('198.51.100.7', $ip('198.51.100.7, 10.0.0.9'), 'chain of trusted proxies');
		same('unknown', $ip('198.51.100.7, 172.16.0.9'), 'a proxy missing from trusted_proxies');
		same('unknown', $ip('198.51.100.7, garbage'), 'a malformed entry');
		same('unknown', $ip('10.0.0.7'), 'only trusted proxies');
	});

	check('ip() reads no client-IP header other than X-Forwarded-For', function () use ($report): void {
		$headers = ['Client-Ip' => '1.2.3.4', 'X-Forwarded' => '1.2.3.4', 'X-Cluster-Client-Ip' => '1.2.3.4', 'Forwarded-For' => '1.2.3.4', 'Forwarded' => '1.2.3.4'];
		same('unknown', $report(['peer' => '10.0.0.5', 'trust' => '1'], $headers)['ip'], 'private trusted proxy');
		same('192.0.2.1', $report(['peer' => '192.0.2.1', 'proxies' => '192.0.2.1', 'trust' => '1'], $headers)['ip'], 'public trusted proxy');
	});

	check('json() decodes JSON media types and yields null for anything else', function () use ($report): void {
		$post = static fn (string $type, string $body): mixed => $report([], ['Content-Type' => $type], 'POST', $body)['json'];
		same(['a' => 1, 'big' => '12345678901234567890'], $post('application/json; charset=UTF-8', '{"a":1,"big":12345678901234567890}'));
		same(['data' => []], $post('application/vnd.api+json', '{"data":[]}'));
		same([1, 2], $post('text/json', "\u{FEFF} [1,2] "), 'BOM and whitespace');
		same(null, $post('text/plain', '{"a":1}'), 'not a JSON media type');
		same(null, $post('application/json', '{"a":'), 'invalid JSON');
		same(null, $post('application/json', '"scalar"'), 'scalar top level');
		same(null, $post('application/json', ' '), 'empty body');
	});

	check('CITOMNI_PUBLIC_ROOT_URL takes precedence for baseUrl() and pathFromAppRoot()', function () use ($report): void {
		$r = $report(['root' => 'https://example.test/app'], [], 'GET', '', '/app/users');
		same(['https://example.test/app/', '/users'], [$r['base_url'], $r['path']]);
		$r = $report(['root' => 'https://example.test/'], [], 'GET', '', '/users');
		same(['https://example.test/', '/users'], [$r['base_url'], $r['path']]);
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
