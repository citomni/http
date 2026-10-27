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

namespace CitOmni\Http\Tests\FetchMetadata;

use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\headerValues;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Suite for the global Fetch Metadata policy: Kernel::guardFetchMetadata() and
 * Policy\FetchMetadataPolicy with the real Request, Router, RoutePath and ErrorHandler.
 *
 * Each case sends real requests to PHP's built-in web server with server.php as
 * router. server.php builds the configuration from the vendor baseline plus a policy
 * chosen by query flags, and holds the App, maintenance and controller doubles.
 *
 * Usage:
 *   php tests/fetch-metadata/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}
\set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($message, 0, $errno, $file, $line);
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
		throw new \RuntimeException($label . ' Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
	}
}

/** Assert that allowed requests run the action and denied requests stop before maintenance. */
function response(array $r, int $status, bool $dispatched): void {
	same($status, $r['status'], \substr($r['body'], 0, 400));
	foreach (['X-Fixture-Maintenance', 'X-Fixture-Router', 'X-Fixture-Controller'] as $name) {
		same($dispatched ? ['yes'] : [], headerValues($r['headers'], $name), $name);
	}
	same([], headerValues($r['headers'], 'Set-Cookie'), 'Sessionless request');
}

$root = tempDir('fetch_metadata');
\mkdir($root . '/config');
$server = null;

try {
	$server = new FixtureServer($root, __DIR__ . '/server.php');
	$cross = ['Sec-Fetch-Site' => 'cross-site', 'Accept' => 'application/json'];
	$navigation = $cross + ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

	check('The vendor default leaves existing applications unchanged', function () use ($server, $cross): void {
		$r = $server->request('GET', '/private?baseline=1', $cross);
		response($r, 200, true);
		same(['Accept-Encoding'], headerValues($r['headers'], 'Vary'));
	});
	check('An explicit disabled policy ignores unused invalid exemptions', function () use ($server, $cross): void {
		$r = $server->request('POST', '/private?disabled=1&bad_rule=fields', $cross);
		response($r, 200, true);
		same(['Accept-Encoding'], headerValues($r['headers'], 'Vary'));
	});
	foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
		check('Same-origin ' . $method . ' uses the fast path without consulting exemptions', function () use ($server, $method): void {
			response($server->request($method, '/private?bad_rule=fields', ['Sec-Fetch-Site' => 'same-origin']), 200, true);
		});
	}
	check('Directly initiated requests pass', function () use ($server): void {
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'none']), 200, true);
	});
	check('Missing metadata passes by default', function () use ($server): void {
		response($server->request('POST', '/private'), 200, true);
	});
	check('Unknown future metadata follows the missing-header policy', function () use ($server): void {
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'future-value']), 200, true);
		response($server->request('GET', '/private?strict_missing=1', ['Sec-Fetch-Site' => 'future-value', 'Accept' => 'application/json']), 403, false);
	});
	check('Strict missing-header policy rejects unknown callers', function () use ($server): void {
		response($server->request('GET', '/private?strict_missing=1', ['Accept' => 'application/json']), 403, false);
	});
	check('Missing metadata can still use an explicit authenticated callback exemption', function () use ($server): void {
		response($server->request('POST', '/callback?strict_missing=1'), 200, true);
	});
	check('Sibling subdomains are untrusted by default and explicitly opt in', function () use ($server): void {
		$headers = ['Sec-Fetch-Site' => 'same-site', 'Accept' => 'application/json'];
		response($server->request('GET', '/private', $headers), 403, false);
		response($server->request('GET', '/private?same_site=1', $headers), 200, true);
	});
	foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
		check('Cross-site ' . $method . ' cannot reach ordinary app routes', function () use ($server, $cross, $method): void {
			response($server->request($method, '/private', $cross + ['Cookie' => 'session=already-present']), 403, false);
		});
	}
	foreach (['GET', 'HEAD'] as $method) {
		check('A cross-site top-level ' . $method . ' navigation remains usable', function () use ($server, $navigation, $method): void {
			response($server->request($method, '/private', $navigation), 200, true);
		});
	}
	foreach (['iframe', 'frame', 'image', 'empty', 'object', 'embed', ''] as $destination) {
		check('A cross-site navigation to ' . \var_export($destination, true) . ' stays blocked', function () use ($server, $cross, $destination): void {
			response($server->request('GET', '/private', $cross + ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => $destination]), 403, false);
		});
	}
	check('A cross-site top-level POST is not a navigation exemption', function () use ($server, $navigation): void {
		response($server->request('POST', '/private', $navigation), 403, false);
	});
	check('A document destination alone does not allow a fetch', function () use ($server, $cross): void {
		response($server->request('GET', '/private', $cross + ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'document']), 403, false);
	});
	check('The read-only viewer exemption permits GET and HEAD', function () use ($server, $cross): void {
		foreach (['GET', 'HEAD'] as $method) {
			response($server->request($method, '/_pdf-viewer/assets/worker.js', $cross), 200, true);
		}
	});
	foreach (['POST', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
		check('The viewer exemption does not allow ' . $method, function () use ($server, $cross, $method): void {
			response($server->request($method, '/_pdf-viewer/assets/worker.js', $cross), 403, false);
		});
	}
	check('Prefix matching cannot exempt adjacent names or unrelated viewer routes', function () use ($server, $cross): void {
		foreach (['/_pdf-viewer/assets-evil', '/_pdf-viewer/other', '/private?next=/_pdf-viewer/assets/x'] as $path) {
			response($server->request('GET', $path, $cross), 403, false);
		}
	});
	check('Exact callbacks match only the declared path and HTTP method', function () use ($server, $cross): void {
		response($server->request('POST', '/callback', $cross), 200, true);
		response($server->request('POST', '/callback/', $cross), 200, true);
		response($server->request('POST', '/callback/child', $cross), 403, false);
		response($server->request('GET', '/callback', $cross), 403, false);
	});
	check('A named false override disables an inherited exemption', function () use ($server, $cross): void {
		response($server->request('GET', '/_pdf-viewer/assets/x?disable_viewer=1', $cross), 403, false);
	});
	check('Policy and router share decoding, trailing slashes and query handling', function () use ($server, $cross): void {
		foreach (['/_pdf-viewer/%61ssets/worker.js', '/_pdf-viewer/assets/worker.js/', '/_pdf-viewer/assets/worker.js?q=/private', '/_pdf-viewer/assets%2Fworker.js'] as $path) {
			response($server->request('GET', $path, $cross), 200, true);
		}
		response($server->request('GET', '/_pdf-viewer/%2561ssets/worker.js', $cross), 403, false);
	});
	check('A mounted app matches exemptions relative to the app root', function () use ($server, $cross): void {
		$query = '?root=' . \rawurlencode('https://example.test/sub');
		response($server->request('GET', '/sub/_pdf-viewer/assets/worker.js' . $query, $cross), 200, true);
		response($server->request('GET', '/SUB/_pdf-viewer/assets/worker.js' . $query, $cross), 200, true);
		response($server->request('GET', '/sub/private' . $query, $cross), 403, false);
	});
	check('SCRIPT_NAME fallback agrees with the router in a subdirectory', function () use ($server, $cross): void {
		response($server->request('GET', '/sub/_pdf-viewer/assets/worker.js?script=/sub/public/index.php', $cross), 200, true);
	});
	check('Case-insensitive matching is shared by mount paths and exemptions', function () use ($server, $cross): void {
		response($server->request('GET', '/_PDF-VIEWER/ASSETS/Worker.JS', $cross), 403, false);
		response($server->request('GET', '/_PDF-VIEWER/ASSETS/Worker.JS?ci=1', $cross), 200, true);
		$query = '?ci=1&root=' . \rawurlencode('https://example.test/sub');
		response($server->request('GET', '/SUB/_PDF-VIEWER/ASSETS/Worker.JS' . $query, $cross), 200, true);
	});
	check('A matched exemption leaves routing and method negotiation in control', function () use ($server, $cross): void {
		$r = $server->request('GET', '/_pdf-viewer/assets/one/two', $cross);
		same(404, $r['status']);
		same(['yes'], headerValues($r['headers'], 'X-Fixture-Router'));
		same([], headerValues($r['headers'], 'X-Fixture-Controller'));
	});
	check('Every enabled response preserves existing Vary and declares the policy dimensions', function () use ($server, $cross): void {
		foreach ([$cross, ['Sec-Fetch-Site' => 'same-origin'], []] as $headers) {
			$r = $server->request('GET', '/private', $headers + ['Accept' => 'application/json']);
			$vary = \array_map('trim', \explode(',', \implode(',', headerValues($r['headers'], 'Vary'))));
			foreach (['Accept-Encoding', 'Sec-Fetch-Site', 'Sec-Fetch-Mode', 'Sec-Fetch-Dest'] as $name) {
				same(true, \in_array($name, $vary, true), $name);
			}
		}
	});
	check('The policy never reads or changes the request body', function () use ($server, $cross): void {
		$body = '{"signed":"unchanged"}';
		$r = $server->request('POST', '/callback', $cross + ['Content-Type' => 'application/json'], $body);
		response($r, 200, true);
		same($body, \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR)['body']);
	});
	check('A custom error handler returning cannot resume dispatch', function () use ($server, $cross): void {
		$r = $server->request('GET', '/private?returning_handler=1', $cross);
		response($r, 403, false);
		same(['fetch_metadata_rejected'], headerValues($r['headers'], 'X-Fixture-Error'));
	});
	check('Header values are compared case-insensitively, like the CSRF layer', function () use ($server): void {
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'Cross-Site', 'Accept' => 'application/json']), 403, false);
		response($server->request('POST', '/private', ['Sec-Fetch-Site' => 'SAME-ORIGIN']), 200, true);
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'Navigate', 'Sec-Fetch-Dest' => 'DOCUMENT']), 200, true);
	});
	check('An untrusted sibling site may still navigate a top-level document', function () use ($server): void {
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']), 200, true);
	});
	check('Keys next to a complete rule are ignored', function () use ($server, $cross): void {
		response($server->request('GET', '/_pdf-viewer/assets/worker.js?annotated=1', $cross), 200, true);
	});
	check('A prefix of / exempts every path, for the listed methods only', function () use ($server, $cross): void {
		response($server->request('POST', '/private?everything_post=1', $cross), 200, true);
		response($server->request('GET', '/private?everything_post=1', $cross), 403, false);
	});
	check('Rules need string names so provider and app maps merge by name', function () use ($server, $cross): void {
		response($server->request('GET', '/_pdf-viewer/assets/x?numeric_name=1', $cross), 500, false);
	});
	foreach (['fields', 'query', 'backslash', 'percent', 'dots', 'method', 'empty_methods', 'match'] as $invalid) {
		check('Invalid exemption ' . $invalid . ' fails before an earlier valid rule can allow access', function () use ($server, $cross, $invalid): void {
			response($server->request('GET', '/_pdf-viewer/assets/x?bad_rule=' . $invalid, $cross), 500, false);
		});
	}
	check('Lists cannot silently replace the named exemption contract', function () use ($server, $cross): void {
		response($server->request('GET', '/private?list=1', $cross), 500, false);
	});
	check('A string false cannot silently enable or disable the policy', function () use ($server): void {
		response($server->request('GET', '/private?bad_enabled=1', ['Sec-Fetch-Site' => 'same-origin', 'Accept' => 'application/json']), 500, false);
	});
	check('Accepted requests produce no request-error log records', function () use ($server, $root): void {
		$path = $root . '/logs/http_request.jsonl';
		$before = \file_get_contents($path);
		response($server->request('GET', '/private', ['Sec-Fetch-Site' => 'same-origin']), 200, true);
		same($before, \file_get_contents($path));
	});
	check('Rejections use the existing request-error log and stable reason', function () use ($root): void {
		$records = \file($root . '/logs/http_request.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
		same(true, \count($records) > 0);
		foreach ($records as $line) {
			$record = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
			same(403, $record['status']);
			same('request', $record['context']['source']);
			same('fetch_metadata_rejected', $record['context']['reason']);
		}
	});
} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
