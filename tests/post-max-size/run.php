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

/**
 * Regression suite for POST size detection and early HTTP 413 responses.
 *
 * Typical usage:
 *   php tests/post-max-size/run.php
 *
 * Notes:
 * - tests/run.php collects this suite like the others.
 * - The same file is the router of the built-in server and the --ini-probe worker.
 * - Runs the supplied Request, ErrorHandler and HTTP Kernel unchanged.
 * - Uses test doubles for the unsupplied core container/runtime and dispatch services.
 * - Uses PHP's built-in HTTP server to exercise real POST parsing and response headers.
 * - Does not require Composer, cURL, php-cgi or an installed application.
 */
namespace {
	require \dirname(__DIR__) . '/bootstrap.php';
	class_alias(\CitOmni\Http\Tests\Cfg::class, 'CitOmni\\Kernel\\Cfg');
}

namespace CitOmni\Kernel {
	enum Mode { case HTTP; }

	final class Arr {
		public static function mergeAssocLastWins(array $base, array $overlay): array {
			return \array_replace_recursive($base, $overlay);
		}
	}

	final class Runtime {
		public static function configure(Cfg $cfg): void {}
	}

	final class App {
		public Cfg $cfg;
		private array $services = [];

		public function __construct(string $configDir, Mode $mode) {
			$this->cfg = new Cfg([
				'http' => ['base_url' => 'https://example.test'],
				'locale' => ['language' => 'da'],
				'error_handler' => [
					'log' => ['path' => \CITOMNI_APP_PATH . '/logs'],
					'render' => ['trigger' => E_ALL, 'detail' => ['level' => 1]],
					'templates' => ['html' => \dirname(__DIR__, 2) . '/templates/errors/error.php'],
				],
			]);
		}

		public function __get(string $id): object {
			if (isset($this->services[$id])) {
				return $this->services[$id];
			}
			return $this->services[$id] = match ($id) {
				'request' => new \CitOmni\Http\Service\Request($this),
				'errorHandler' => new \CitOmni\Http\Service\ErrorHandler($this),
				'maintenance' => new class {
					public function guard(): void {
						\file_put_contents(\CITOMNI_APP_PATH . '/lifecycle.txt', "maintenance\n", FILE_APPEND);
					}
				},
				'router' => new class($this) {
					public function __construct(private App $app) {}
					public function run(): void {
						\file_put_contents(\CITOMNI_APP_PATH . '/lifecycle.txt', "router\n", FILE_APPEND);
						if (isset($_GET['router_status'])) {
							$this->app->errorHandler->httpError((int)$_GET['router_status'], ['token' => 'secret']);
						}
						\header('Content-Type: application/json');
						echo \json_encode([
							'post' => $_POST,
							'files' => \array_keys($_FILES),
							'file_errors' => \array_map(static fn(array $file): int => $file['error'], $_FILES),
							'body' => \file_get_contents('php://input'),
						], JSON_THROW_ON_ERROR);
					}
				},
				default => throw new \RuntimeException('Unexpected service: ' . $id),
			};
		}
	}
}

namespace {
	use CitOmni\Http\Service\Request;

	require \dirname(__DIR__, 2) . '/src/Service/Request.php';
	require \dirname(__DIR__, 2) . '/src/Service/ErrorHandler.php';
	require \dirname(__DIR__, 2) . '/src/Kernel.php';

	// The tested guard runs after boot; intl setup itself is outside this suite's scope.
	if (!class_exists(Locale::class)) {
		class Locale {}
	}

	if (PHP_SAPI === 'cli-server') {
		$root = getenv('CITOMNI_POST_MAX_TEST_ROOT');
		if ($root === false || !is_dir($root . '/config')) {
			throw new RuntimeException('Missing HTTP fixture root.');
		}
		define('CITOMNI_APP_PATH', $root);
		define('CITOMNI_ENVIRONMENT', isset($_GET['prod']) ? 'prod' : 'dev');
		file_put_contents($root . '/before.json', json_encode(['post' => $_POST, 'files' => $_FILES], JSON_THROW_ON_ERROR));
		CitOmni\Http\Kernel::run($root);
		return;
	}

	if (($argv[1] ?? '') === '--ini-probe') {
		$request = new Request((object)['cfg' => new \CitOmni\Kernel\Cfg([])]);
		$limit = $request->postMaxSize();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$out = ['limit' => $limit];
		foreach (['below' => max(0, $limit - 1), 'equal' => max(0, $limit), 'above' => max(1, $limit + 1)] as $name => $length) {
			$_SERVER['CONTENT_LENGTH'] = (string)$length;
			$out[$name] = $request->exceedsPostMaxSize();
		}
		echo json_encode($out, JSON_THROW_ON_ERROR);
		return;
	}

	$passed = 0;
	$failed = 0;
	$servers = [];
	$root = sys_get_temp_dir() . '/citomni-post-max-' . bin2hex(random_bytes(8));
	mkdir($root . '/config', 0700, true);
	$request = new Request((object)['cfg' => new \CitOmni\Kernel\Cfg([])]);

	function check(string $name, callable $test): void {
		global $passed, $failed;
		try {
			$test();
			$passed++;
			echo 'PASS ' . $name . PHP_EOL;
		} catch (Throwable $error) {
			$failed++;
			echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
		}
	}

	function same(mixed $expected, mixed $actual): void {
		if ($expected !== $actual) {
			throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
		}
	}

	/** Start a real HTTP fixture with a request-scoped PHP INI limit. */
	function startServer(string $limit): string {
		global $servers, $root;
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false) {
			throw new RuntimeException('Cannot reserve HTTP test port: ' . $error);
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$process = proc_open([
			PHP_BINARY, '-n', '-d', 'post_max_size=' . $limit,
			'-d', 'upload_max_filesize=512', '-d', 'display_errors=0', '-d', 'log_errors=0',
			'-S', $address, __FILE__,
		], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.out', 'a'], 2 => ['file', $root . '/server.err', 'a']], $pipes, __DIR__, array_merge(getenv(), ['CITOMNI_POST_MAX_TEST_ROOT' => $root]));
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start PHP HTTP fixture.');
		}
		fclose($pipes[0]);
		$servers[] = $process;
		$deadline = microtime(true) + 5;
		do {
			$ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
			if (is_resource($ready)) {
				fclose($ready);
				return 'http://' . $address;
			}
			usleep(10_000);
		} while (microtime(true) < $deadline);
		throw new RuntimeException('PHP HTTP fixture did not start.');
	}

	/** Send a body through PHP's real HTTP parser and retain the emitted headers. */
	function send(string $url, string $method, string $body, string $type = 'application/x-www-form-urlencoded', string $accept = 'application/json'): array {
		global $root;
		@unlink($root . '/lifecycle.txt');
		$context = stream_context_create(['http' => [
			'method' => $method,
			'header' => "Content-Type: " . $type . "\r\nAccept: " . $accept . "\r\n",
			'content' => $body,
			'ignore_errors' => true,
			'timeout' => 5,
		]]);
		$result = file_get_contents($url, false, $context);
		$headers = http_get_last_response_headers() ?? [];
		if ($result === false) {
			throw new RuntimeException('HTTP request failed.');
		}
		preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $status);
		return ['status' => (int)($status[1] ?? 0), 'headers' => $headers, 'body' => $result];
	}

	function lifecycle(): string {
		global $root;
		return is_file($root . '/lifecycle.txt') ? file_get_contents($root . '/lifecycle.txt') : '';
	}

	/** Verify terminal rejection and prove neither maintenance nor router ran. */
	function rejected(array $response): array {
		same(413, $response['status']);
		same('', lifecycle());
		$payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
		same(413, $payload['status']);
		same('Content Too Large', $payload['title']);
		same('The request body exceeds the maximum allowed size.', $payload['message']);
		same('post_max_size_exceeded', $payload['details']['reason']);
		return $payload;
	}

	try {
		echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . PHP_EOL;
		foreach ([
			'zero' => ['0', 0], 'integer zero' => [0, 0], 'decimal' => ['123', 123],
			'leading zeroes' => ['000123', 123], 'all zeroes' => ['000', 0],
			'integer' => [123, 123], 'platform maximum' => [(string)PHP_INT_MAX, PHP_INT_MAX],
			'missing' => [null, null], 'empty' => ['', null], 'negative' => ['-1', null],
			'negative integer' => [-1, null], 'plus sign' => ['+1', null], 'decimal fraction' => ['1.5', null],
			'exponent' => ['1e3', null], 'whitespace' => [' 123 ', null], 'duplicate values' => ['1, 2', null],
			'hex' => ['0xff', null], 'suffix' => ['1K', null], 'array' => [[], null],
			'boolean' => [true, null], 'float' => [1.0, null], 'overflow' => [(string)PHP_INT_MAX . '0', null],
		] as $name => [$value, $expected]) {
			check('CONTENT_LENGTH ' . $name, function () use ($request, $value, $expected): void {
				$_SERVER['CONTENT_LENGTH'] = $value;
				same($expected, $request->contentLength());
			});
		}
		foreach ([
			'1024' => 1024, '1K' => 1024, '2m' => 2 * 1024 * 1024,
			'2G' => 2 * 1024 * 1024 * 1024, '0x10K' => 16 * 1024,
			'01024' => 532, '0' => 0, '-1' => -1,
		] as $ini => $bytes) {
			check('INI ' . $ini . ' and strict size boundary', function () use ($ini, $bytes): void {
				$process = proc_open([PHP_BINARY, '-n', '-d', 'post_max_size=' . $ini, __FILE__, '--ini-probe'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				if (!is_resource($process)) {
					throw new RuntimeException('Cannot start INI probe.');
				}
				fclose($pipes[0]);
				$out = stream_get_contents($pipes[1]);
				$errors = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				same(0, proc_close($process));
				same('', $errors);
				same(['limit' => $bytes, 'below' => false, 'equal' => false, 'above' => $bytes > 0], json_decode($out, true, 512, JSON_THROW_ON_ERROR));
			});
		}
		check('No inference from empty POST and FILES without a length', function () use ($request): void {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			unset($_SERVER['CONTENT_LENGTH']);
			$_POST = $_FILES = [];
			same(false, $request->exceedsPostMaxSize());
		});
		check('Non-POST methods bypass the POST size limit', function () use ($request): void {
			$_SERVER['CONTENT_LENGTH'] = (string)PHP_INT_MAX;
			foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
				$_SERVER['REQUEST_METHOD'] = $method;
				same(false, $request->exceedsPostMaxSize());
			}
		});
		check('Nonempty parsed superglobals do not bypass the size limit', function () use ($request): void {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['CONTENT_LENGTH'] = (string)PHP_INT_MAX;
			$_POST = ['_csrf' => 'present'];
			$_FILES = ['invoice' => ['error' => 0]];
			same($request->postMaxSize() > 0, $request->exceedsPostMaxSize());
		});

		$url = startServer('1K');
		$boundary = 'citomni-post-max-test';
		$multipart = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\npresent\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"invoice\"; filename=\"invoice.pdf\"\r\nContent-Type: application/pdf\r\n\r\n";
		$ending = "\r\n--" . $boundary . "--\r\n";
		check('Oversized multipart loses POST/FILES and returns 413 before dispatch', function () use ($url, $root, $multipart, $ending, $boundary): void {
			$body = $multipart . str_repeat('a', 2048) . $ending;
			$payload = rejected(send($url, 'POST', $body, 'multipart/form-data; boundary=' . $boundary));
			same(strlen($body), $payload['details']['content_length']);
			same(1024, $payload['details']['post_max_size']);
			same(['post' => [], 'files' => []], json_decode(file_get_contents($root . '/before.json'), true, 512, JSON_THROW_ON_ERROR));
		});
		check('Small multipart preserves CSRF field and uploaded file', function () use ($url, $multipart, $ending, $boundary): void {
			$response = send($url, 'POST', $multipart . '%PDF-test' . $ending, 'multipart/form-data; boundary=' . $boundary);
			same(200, $response['status']);
			$payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
			same('present', $payload['post']['_csrf']);
			same(['invoice'], $payload['files']);
			same("maintenance\nrouter\n", lifecycle());
		});
		check('Per-file upload limit does not become a whole-request 413', function () use ($url, $multipart, $ending, $boundary): void {
			$body = $multipart . str_repeat('a', 600) . $ending;
			same(true, strlen($body) < 1024);
			$response = send($url, 'POST', $body, 'multipart/form-data; boundary=' . $boundary);
			same(200, $response['status']);
			$payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
			same('present', $payload['post']['_csrf']);
			same(UPLOAD_ERR_INI_SIZE, $payload['file_errors']['invoice']);
		});
		foreach ([1023, 1024, 1025] as $length) {
			check('URL-encoded POST boundary at ' . $length . ' bytes', function () use ($url, $length): void {
				$response = send($url, 'POST', 'pad=' . str_repeat('a', $length - 4));
				if ($length > 1024) {
					rejected($response);
				} else {
					same(200, $response['status']);
					same("maintenance\nrouter\n", lifecycle());
					same($length - 4, strlen(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['post']['pad']));
				}
			});
		}
		check('JSON POST is rejected by declared body size', function () use ($url): void {
			rejected(send($url, 'POST', json_encode(['pad' => str_repeat('a', 2048)]), 'application/json'));
		});
		check('Small JSON body remains readable after the guard', function () use ($url): void {
			$body = '{"value":42}';
			$response = send($url, 'POST', $body, 'application/json');
			same(200, $response['status']);
			same($body, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['body']);
		});
		check('Large PUT body is not limited by post_max_size', function () use ($url): void {
			$body = str_repeat('a', 2048);
			$response = send($url, 'PUT', $body, 'application/octet-stream');
			same(200, $response['status']);
			same($body, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['body']);
		});
		check('413 HTML response uses existing Danish copy and safety headers', function () use ($url): void {
			$response = send($url, 'POST', str_repeat('a', 2048), 'text/plain', 'text/html');
			same(413, $response['status']);
			same('', lifecycle());
			same(true, str_contains($response['headers'][0], 'Content Too Large'));
			same(true, str_contains($response['body'], 'Indholdet er for stort'));
			same(true, str_contains($response['body'], 'The request body exceeds the maximum allowed size.'));
			$headers = strtolower(implode("\n", $response['headers']));
			foreach (['content-type: text/html', 'cache-control: no-store', 'x-content-type-options: nosniff', 'x-request-id:'] as $header) {
				same(true, str_contains($headers, $header));
			}
		});
		check('Production JSON 413 retains safety headers and hides internal metadata', function () use ($url): void {
			$response = send($url . '/?prod=1', 'POST', str_repeat('a', 2048), 'text/plain');
			same(413, $response['status']);
			same('', lifecycle());
			$payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
			same(413, $payload['status']);
			same('Content Too Large', $payload['title']);
			same(false, array_key_exists('details', $payload));
			same(true, $payload['error_id'] !== '');
			$headers = strtolower(implode("\n", $response['headers']));
			foreach (['content-type: application/json', 'cache-control: no-store', 'x-content-type-options: nosniff', 'x-request-id:'] as $header) {
				same(true, str_contains($headers, $header));
			}
		});
		check('Request-level errors use their own structured log', function () use ($root): void {
			$records = file($root . '/logs/http_request.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			same(5, count($records));
			foreach ($records as $line) {
				$record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				same('http_error', $record['type']);
				same(413, $record['status']);
				same('request', $record['context']['source']);
				same('post_max_size_exceeded', $record['context']['reason']);
			}
			same(false, is_file($root . '/logs/http_router_other.jsonl'));
		});
		foreach ([404 => 'http_router_404.jsonl', 405 => 'http_router_405.jsonl', 500 => 'http_router_5xx.jsonl', 403 => 'http_router_other.jsonl'] as $status => $file) {
			check('Existing HTTP error call keeps its ' . $status . ' log', function () use ($url, $root, $status, $file): void {
				$response = send($url . '/?router_status=' . $status, 'GET', '');
				same($status, $response['status']);
				same("maintenance\nrouter\n", lifecycle());
				$records = file($root . '/logs/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				same(1, count($records));
				$record = json_decode($records[0], true, 512, JSON_THROW_ON_ERROR);
				same($status, $record['status']);
				same('[redacted]', $record['context']['token']);
			});
		}
		$unlimited = startServer('0');
		check('post_max_size=0 permits large URL-encoded POST bodies', function () use ($unlimited): void {
			$response = send($unlimited, 'POST', 'pad=' . str_repeat('a', 2048));
			same(200, $response['status']);
			same(2048, strlen(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['post']['pad']));
		});
		check('post_max_size=0 permits large multipart POST bodies', function () use ($unlimited, $boundary): void {
			$body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"pad\"\r\n\r\n" . str_repeat('a', 2048) . "\r\n--" . $boundary . "--\r\n";
			$response = send($unlimited, 'POST', $body, 'multipart/form-data; boundary=' . $boundary);
			same(200, $response['status']);
			same(2048, strlen(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['post']['pad']));
		});
		$legacy = startServer('1KB');
		check('Legacy INI parser fallback still returns 413 instead of a duplicate-warning 500', function () use ($legacy): void {
			$payload = rejected(send($legacy, 'POST', 'ab', 'text/plain'));
			same(1, $payload['details']['post_max_size']);
		});
	} finally {
		foreach ($servers as $server) {
			proc_terminate($server);
			proc_close($server);
		}
		\CitOmni\Http\Tests\removeTree($root);
	}
	// Summary line in the format tests/run.php parses.
	echo $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
	exit($failed === 0 ? 0 : 1);
}
