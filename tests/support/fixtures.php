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

/*
 * Fixtures for the isolated suites. Not a suite of its own.
 *
 * - FixtureServer runs PHP's built-in web server with a suite's router script, for
 *   behavior that only exists in a real request: the session cookie, response
 *   headers, php://input, and a SAPI other than cli.
 * - tempDir() and removeTree() manage the per-run directory that holds the server's
 *   document root and logs, and any files a suite writes.
 *
 * Notes:
 * - Suites honor @-suppression in their error handlers, as the production
 *   ErrorHandler does; the readiness probe below relies on it.
 */

namespace CitOmni\Http\Tests\Support;

/**
 * PHP's built-in web server on a free local port, serving one router script.
 *
 * Behavior:
 * - Starts `php -S 127.0.0.1:<port> -t <docroot> <router>` with the same PHP binary
 *   and php.ini as the calling suite: the loaded file, the default lookup, or -n.
 *   -d settings are not passed on, as in tests/run.php.
 * - Waits up to 5 seconds until the port accepts connections.
 * - Server output goes to <docroot>/server.out and <docroot>/server.err.
 *
 * Typical usage:
 *   $server = new FixtureServer($root, __DIR__ . '/server.php');
 *   try {
 *       $r = $server->request('GET', '/?case=seed');
 *   } finally {
 *       $server->stop();
 *   }
 */
final class FixtureServer {
	public readonly string $baseUrl;

	/** @var resource|null */
	private mixed $process = null;

	public function __construct(string $docroot, string $router) {
		$iniArgs = match (true) {
			\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
			\php_ini_scanned_files() !== false => [],
			default                            => ['-n'],
		};

		// Reserve a free port, then hand it to the server.
		$socket = \stream_socket_server('tcp://127.0.0.1:0');
		$address = \stream_socket_get_name($socket, false);
		\fclose($socket);

		$process = \proc_open([\PHP_BINARY, ...$iniArgs, '-S', $address, '-t', $docroot, $router], [
			0 => ['pipe', 'r'],
			1 => ['file', $docroot . '/server.out', 'w'],
			2 => ['file', $docroot . '/server.err', 'w'],
		], $pipes);
		if (!\is_resource($process)) {
			throw new \RuntimeException('Cannot start the fixture server.');
		}
		\fclose($pipes[0]);
		$this->process = $process;

		// Connection attempts fail until the server listens.
		$deadline = \microtime(true) + 5;
		while (($probe = @\stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1)) === false) {
			if (\microtime(true) >= $deadline) {
				$this->stop();
				throw new \RuntimeException("Fixture server did not start on {$address}: {$errstr}");
			}
			\usleep(10_000);
		}
		\fclose($probe);
		$this->baseUrl = 'http://' . $address;
	}

	/**
	 * Send one request and return the response without following redirects.
	 *
	 * Notes:
	 * - Send a Content-Type header with a body; PHP's HTTP client raises a notice
	 *   when it has to assume one.
	 *
	 * @param  string                $method   HTTP method.
	 * @param  string                $target   Path and query, e.g. "/user/5?x=1".
	 * @param  array<string, string> $headers  Request headers by name.
	 * @param  string                $body     Request body.
	 * @return array{status: int, headers: list<string>, body: string}  Header lines without the status line.
	 */
	public function request(string $method, string $target, array $headers = [], string $body = ''): array {
		$lines = '';
		foreach ($headers as $name => $value) {
			$lines .= $name . ': ' . $value . "\r\n";
		}
		$options = [
			'method'          => $method,
			'header'          => $lines,
			'ignore_errors'   => true,
			'follow_location' => 0,
			'timeout'         => 5,
		];
		if ($body !== '') {
			$options['content'] = $body;
		}
		$context = \stream_context_create(['http' => $options]);
		$responseBody = \file_get_contents($this->baseUrl . $target, false, $context);
		$responseHeaders = \http_get_last_response_headers() ?? [];
		if ($responseBody === false || $responseHeaders === []) {
			throw new \RuntimeException("No response for {$method} {$target}");
		}

		$statusLine = \array_shift($responseHeaders);
		if (\preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $m) !== 1) {
			throw new \RuntimeException('Malformed status line: ' . $statusLine);
		}
		return ['status' => (int)$m[1], 'headers' => $responseHeaders, 'body' => $responseBody];
	}

	public function stop(): void {
		if (\is_resource($this->process)) {
			\proc_terminate($this->process);
			\proc_close($this->process);
		}
		$this->process = null;
	}
}

/**
 * Values of one response header, matched case-insensitively.
 *
 * @param  list<string> $headers  Header lines as returned by FixtureServer::request().
 * @return list<string>
 */
function headerValues(array $headers, string $name): array {
	$values = [];
	$prefix = \strtolower($name) . ':';
	foreach ($headers as $line) {
		if (\str_starts_with(\strtolower($line), $prefix)) {
			$values[] = \trim(\substr($line, \strlen($prefix)));
		}
	}
	return $values;
}

/** Create an empty, uniquely named directory under the system temp directory. */
function tempDir(string $suite): string {
	$dir = \sys_get_temp_dir() . '/citomni_http_' . $suite . '_test_' . \bin2hex(\random_bytes(6));
	\mkdir($dir, 0700, true);
	return $dir;
}

/** Remove a directory created by tempDir(), including everything below it. */
function removeTree(string $dir): void {
	foreach (\scandir($dir) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		$path = $dir . '/' . $entry;
		if (\is_dir($path) && !\is_link($path)) {
			removeTree($path);
		} else {
			\unlink($path);
		}
	}
	\rmdir($dir);
}
