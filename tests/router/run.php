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

namespace CitOmni\Http\Tests\Router;

use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\headerValues;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Router: matching, placeholders, method
 * negotiation and the base prefix.
 *
 * Each case sends real requests to PHP's built-in web server with server.php as
 * router, so status codes and the Allow header are the ones a client receives.
 * server.php holds the route table and the controller and error handler doubles.
 *
 * Usage:
 *   php tests/router/run.php
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

$root = tempDir('router');
$server = null;

try {
	$server = new FixtureServer($root, __DIR__ . '/server.php');

	/**
	 * Route one request and return the status, the Allow header and the fixture report.
	 *
	 * @param array<string, string> $settings  Fixture settings: ci, root, script.
	 * @return array{status: int, allow: ?string, report: ?array}
	 */
	$route = static function (string $method, string $target, array $settings = []) use ($server): array {
		$query = $settings !== [] ? (\str_contains($target, '?') ? '&' : '?') . \http_build_query($settings) : '';
		$r = $server->request($method, $target . $query);
		$report = headerValues($r['headers'], 'X-Fixture-Report');
		if (\count($report) > 1 || ($report === [] && $r['body'] !== '')) {
			throw new \RuntimeException("Unexpected response to {$method} {$target}: " . \trim($r['body']));
		}
		return [
			'status' => $r['status'],
			'allow'  => headerValues($r['headers'], 'Allow')[0] ?? null,
			'report' => $report !== [] ? \json_decode($report[0], true, 512, \JSON_THROW_ON_ERROR) : null,
		];
	};

	/** Expected report for an action call. */
	$call = static fn (string $action, array $params = [], string $method = 'GET', array $options = ['template_file' => null, 'template_layer' => null]): array => [
		'action'  => $action,
		'params'  => $params,
		'options' => $options,
		'method'  => $method,
	];

	/** Expected report for an error handler call. */
	$error = static fn (int $status, string $reason, ?array $allowed = null): array => ['error' => $status, 'reason' => $reason, 'allowed' => $allowed];


	// -- 1. Matching ----------------------------------------------------------

	check('An exact route runs its action and passes the route template hints', function () use ($route, $call): void {
		same(['status' => 200, 'allow' => null, 'report' => $call('index')], $route('GET', '/'));
		same($call('index', options: ['template_file' => 'public/read.html', 'template_layer' => 'app']), $route('GET', '/read')['report']);
	});

	check('Trailing slashes and the query string do not affect matching', function () use ($route, $call): void {
		$expected = $call('index', options: ['template_file' => 'public/read.html', 'template_layer' => 'app']);
		same($expected, $route('GET', '/read/')['report'], 'trailing slash');
		same($expected, $route('GET', '/read?page=2')['report'], 'query string');
	});

	check('Placeholders capture one segment each by their rule, in order', function () use ($route, $call, $error): void {
		same($call('show', ['42']), $route('GET', '/user/42')['report']);
		same($call('show', ['my-post_1']), $route('GET', '/post/my-post_1')['report']);
		same($call('show', ['a.b+c@example.test']), $route('GET', '/mail/a.b+c@example.test')['report']);
		same($call('show', ['report.pdf']), $route('GET', '/file/report.pdf')['report'], 'unknown placeholder');
		same($call('show', ['AB12', '7']), $route('GET', '/code/AB12/7')['report']);

		foreach (['/user/abc', '/user/42/x', '/post/a.b', '/mail/not-an-email', '/file/a/b', '/code/AB-1/7'] as $path) {
			same(['status' => 404, 'allow' => null, 'report' => $error(404, 'route_not_found')], $route('GET', $path), $path);
		}
	});

	check('Percent-escapes are decoded before matching; non-ASCII paths are 404', function () use ($route, $call, $error): void {
		same($call('show', ['42']), $route('GET', '/us%65r/42')['report']);
		same(['status' => 404, 'allow' => null, 'report' => $error(404, 'invalid_uri_non_ascii')], $route('GET', '/%C3%A6'));
	});

	check('An unknown path is 404; a missing controller or action is 500', function () use ($route, $error): void {
		same(['status' => 404, 'allow' => null, 'report' => $error(404, 'route_not_found')], $route('GET', '/nowhere'));
		same(['status' => 500, 'allow' => null, 'report' => $error(500, 'controller_missing')], $route('GET', '/missing-controller'));
		same(['status' => 500, 'allow' => null, 'report' => $error(500, 'action_missing')], $route('GET', '/missing-action'));
	});


	// -- 2. Methods -----------------------------------------------------------

	check('A method outside the route\'s list is 405 with an Allow header', function () use ($route, $call, $error): void {
		same(['status' => 405, 'allow' => 'OPTIONS, POST', 'report' => $error(405, 'method_not_allowed', ['OPTIONS', 'POST'])], $route('GET', '/form'));
		same($call('submit', method: 'POST'), $route('POST', '/form')['report']);
	});

	check('Routes without methods allow GET, HEAD and OPTIONS', function () use ($route, $call, $error): void {
		same(['status' => 405, 'allow' => 'GET, HEAD, OPTIONS', 'report' => $error(405, 'method_not_allowed', ['GET', 'HEAD', 'OPTIONS'])], $route('POST', '/'));
		same(['status' => 200, 'allow' => null, 'report' => $call('index', method: 'HEAD')], $route('HEAD', '/'));
	});

	check('GET implies HEAD, and route methods are case-insensitive', function () use ($route, $call, $error): void {
		$options = ['template_file' => 'public/read.html', 'template_layer' => 'app'];
		same($call('index', method: 'HEAD', options: $options), $route('HEAD', '/read')['report']);
		same(['status' => 405, 'allow' => 'GET, HEAD, OPTIONS', 'report' => $error(405, 'method_not_allowed', ['GET', 'HEAD', 'OPTIONS'])], $route('DELETE', '/read'));
	});

	check('OPTIONS answers 204 with Allow and runs no action', function () use ($route): void {
		same(['status' => 204, 'allow' => 'OPTIONS, POST', 'report' => null], $route('OPTIONS', '/form'));
		same(['status' => 204, 'allow' => 'GET, HEAD, OPTIONS', 'report' => null], $route('OPTIONS', '/'));
	});


	// -- 3. Base prefix and case ----------------------------------------------

	check('The base prefix comes from CITOMNI_PUBLIC_ROOT_URL, else from SCRIPT_NAME without /public', function () use ($route, $call): void {
		same($call('show', ['5']), $route('GET', '/sub/user/5', ['root' => 'https://example.test/sub/'])['report'], 'public root path');
		same($call('show', ['5']), $route('GET', '/sub/user/5', ['script' => '/sub/public/index.php'])['report'], 'script in /sub/public');
		same($call('show', ['5']), $route('GET', '/sub/user/5', ['script' => '/sub/index.php'])['report'], 'script in /sub');
		same($call('show', ['5']), $route('GET', '/user/5', ['root' => 'https://example.test/'])['report'], 'public root at /');
		same($call('show', ['5']), $route('GET', '/user/5', ['script' => '/public/index.php'])['report'], 'script in /public');
		same($call('index'), $route('GET', '/sub', ['root' => 'https://example.test/sub'])['report'], 'the prefix itself is /');
	});

	check('Case-insensitive matching is off by default and opt-in through cfg', function () use ($route, $call, $error): void {
		same($error(404, 'route_not_found'), $route('GET', '/MIXED/case')['report']);
		same($call('index'), $route('GET', '/MIXED/case', ['ci' => '1'])['report']);
		same($call('show', ['intro']), $route('GET', '/DOCS/intro', ['ci' => '1'])['report'], 'regex route');
		same($call('show', ['5']), $route('GET', '/SUB/user/5', ['ci' => '1', 'root' => 'https://example.test/sub'])['report'], 'base prefix');
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
