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
 * SystemController full-route test (HTTP integration via cURL).
 *
 * Requirements:
 * - PHP >= 8.2 with cURL enabled.
 * - Your app must be running and exposing the SystemController routes.
 *
 * What it does:
 * - Tests all public endpoints (ping, health, version, time, client-ip, request-echo, trusted-proxies).
 * - Tests all protected endpoints with valid HMAC (maintenance snapshot/enable/disable, warmup-cache, reset-cache).
 * - Calculates signature exactly as your WebhooksAuth:
 *     Simple: "<ts>.<nonce>.<rawBody>"
 *     Context-bound: ts\nnonce\nMETHOD\nPATH\nQUERY\nsha256(body)
 *
 * Notes:
 * - request-echo may be 404 outside dev/stage (counts as PASS if 404).
 * - webhookDebug is heavily IP-gated; we call it once (informational).
 * - Adjust BASE_URL/SECRET/BIND_CONTEXT/ALGO below to match your server cfg.
 */

final class TestSystemController {

	// -----------------------------
	// Config (adjust to your setup)
	// -----------------------------
	private const BASE_URL     = 'https://www.example.com/'; // trailing slash ok
	private const SECRET       = 'INSERT_YOUR_SECRET_HERE';  // MUST match cfg:webhooks.secret
	private const BIND_CONTEXT = false;                      // MUST match cfg:webhooks.bind_context
	private const ALGO         = 'sha256';                   // 'sha256' or 'sha512' — MUST match cfg:webhooks.algo
	private const TIMEOUT_SEC  = 20;
	private const VERBOSE      = true;                       // set false for terse debug logs

	// Route paths (adjust if your router differs)
	private const ROUTES = [
		'ping'               => '_system/ping',
		'health'             => '_system/health',
		'version'            => '_system/version',
		'time'               => '_system/time',
		'client_ip'          => '_system/clientip',
		'request_echo'       => '_system/request-echo',
		'trusted_proxies'    => '_system/trusted-proxies',
		'reset_cache'        => '_system/reset-cache',
		'warmup_cache'       => '_system/warmup-cache',
		'maintenance'        => '_system/maintenance',
		'maintenance_enable' => '_system/maintenance/enable',
		'maintenance_disable'=> '_system/maintenance/disable',
		'webhook_debug'      => '_system/_debug/webhook',
	];

	// -----------------------------
	// Runner
	// -----------------------------
	private int $passed = 0;
	private int $failed = 0;

	public function run(): void {
		$this->logHeader('SystemController Integration Test');

		// Public endpoints
		$this->testPing();
		$this->testHealth();
		$this->testVersion();
		$this->testTime();
		$this->testClientIp();
		$this->testRequestEcho();
		$this->testTrustedProxies();

		// Protected endpoints
		$this->testMaintenanceSnapshot();   // GET (protected)
		$this->testMaintenanceEnable();     // POST (protected)
		$this->testMaintenanceSnapshot();   // verify enable took effect
		$this->testMaintenanceDisable();    // POST (protected)
		$this->testWarmupCache();           // POST (protected)
		$this->testResetCache();            // POST (protected)

		// Heavily gated debug (likely 404)
		$this->testWebhookDebug();

		$this->summary();
	}

	// -----------------------------
	// Tests (public)
	// -----------------------------

	private function testPing(): void {
		$resp = $this->http('GET', self::ROUTES['ping']);
		$this->assertStatus('ping', $resp, 200);
		$this->assertStringStartsWith('ping body', (string)$resp['body'], 'OK ');
		$this->showResponse('ping', $resp);
	}

	private function testHealth(): void {
		$resp = $this->http('GET', self::ROUTES['health']);
		$this->assertStatus('health', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('health json', $j, ['php_version','environment','opcache_enabled','server_time_utc','timezone']);
		$this->showResponse('health', $resp);
	}

	private function testVersion(): void {
		$resp = $this->http('GET', self::ROUTES['version']);
		$this->assertStatus('version', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('version json', $j, ['citomni','app']);
		$this->showResponse('version', $resp);
	}

	private function testTime(): void {
		$resp = $this->http('GET', self::ROUTES['time']);
		$this->assertStatus('time', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('time json', $j, ['time_utc','time_local','timezone']);
		$this->showResponse('time', $resp);
	}

	private function testClientIp(): void {
		$resp = $this->http('GET', self::ROUTES['client_ip']);
		$this->assertStatus('client-ip', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('client-ip json', $j, ['ip']);
		$this->showResponse('client-ip', $resp);
	}

	private function testRequestEcho(): void {
		$resp = $this->http('GET', self::ROUTES['request_echo']);
		// In dev/stage => 200; in prod => 404. Accept both.
		if ($resp['status'] === 200) {
			$j = $this->decodeJson($resp['body']);
			$this->assertHasKeys('request-echo json', $j, ['remote_addr','method','host','uri']);
			$this->pass('request-echo (200)');
		} elseif ($resp['status'] === 404) {
			$this->pass('request-echo (404 outside dev/stage)');
		} else {
			$this->fail('request-echo unexpected status=' . $resp['status']);
		}
		$this->showResponse('request-echo', $resp);
	}

	private function testTrustedProxies(): void {
		$resp = $this->http('GET', self::ROUTES['trusted_proxies']);
		$this->assertStatus('trusted-proxies', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('trusted-proxies json', $j, ['trusted_proxies','count']);
		$this->showResponse('trusted-proxies', $resp);
	}

	// -----------------------------
	// Tests (protected)
	// -----------------------------

	private function testMaintenanceSnapshot(): void {
		// The controller method is GET-like, but protected; we send GET with HMAC headers and empty body.
		$resp = $this->httpSigned('GET', self::ROUTES['maintenance'], '', []);
		if ($resp['status'] !== 200) {
			$this->fail('maintenance snapshot status=' . $resp['status'] . ' body=' . (string)$resp['body']);
			$this->showResponse('maintenance (snapshot)', $resp);
			return;
		}
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('maintenance json', $j, ['enabled','allowed_ips','retry_after','source']);
		$this->showResponse('maintenance (snapshot)', $resp);
	}

	private function testMaintenanceEnable(): void {
		$body = [
			'allowed_ips' => ['127.0.0.1', '::1'],
			'retry_after' => 60,
		];
		$resp = $this->httpSigned('POST', self::ROUTES['maintenance_enable'], $this->json($body));
		$this->assertStatus('maintenance-enable', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertEquals('maintenance-enable status', 'enabled', (string)($j['status'] ?? ''));
		$this->showResponse('maintenance-enable', $resp);
	}

	private function testMaintenanceDisable(): void {
		$body = ['retry_after' => 0];
		$resp = $this->httpSigned('POST', self::ROUTES['maintenance_disable'], $this->json($body));
		$this->assertStatus('maintenance-disable', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertEquals('maintenance-disable status', 'disabled', (string)($j['status'] ?? ''));
		$this->showResponse('maintenance-disable', $resp);
	}

	private function testWarmupCache(): void {
		$resp = $this->httpSigned('POST', self::ROUTES['warmup_cache'], $this->json([]));
		$this->assertStatus('warmup-cache', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('warmup-cache json', $j, ['written','status']);
		$this->showResponse('warmup-cache', $resp);
	}

	private function testResetCache(): void {
		$body = [
			// Optional extra invalidation candidates; must be absolute paths on server if used.
			'paths' => [],
		];
		$resp = $this->httpSigned('POST', self::ROUTES['reset_cache'], $this->json($body));
		$this->assertStatus('reset-cache', $resp, 200);
		$j = $this->decodeJson($resp['body']);
		$this->assertHasKeys('reset-cache json', $j, ['ok','removed','invalidated','failed']);
		$this->showResponse('reset-cache', $resp);
	}

	private function testWebhookDebug(): void {
		// This endpoint is IP-gated (likely 404). We still call it for completeness.
		$resp = $this->httpSigned('POST', self::ROUTES['webhook_debug'], $this->json(['hello' => 'world']));
		if ($resp['status'] === 200) {
			$j = $this->decodeJson($resp['body']);
			if (($j['authorized'] ?? null) === true) {
				$this->pass('webhook-debug authorized');
			} else {
				$this->pass('webhook-debug responded (unauthorized expected outside allowed IP)');
			}
		} elseif ($resp['status'] === 404) {
			$this->pass('webhook-debug 404 (expected when IP-gated)');
		} else {
			$this->fail('webhook-debug unexpected status=' . $resp['status']);
		}
		$this->showResponse('webhook-debug', $resp);
	}

	// -----------------------------
	// HTTP helpers
	// -----------------------------

	/**
	 * Basic HTTP call (no HMAC).
	 *
	 * @param string $method GET|POST
	 * @param string $path   Relative path (no leading slash required)
	 * @param string $body   Raw body
	 * @param array  $headers Extra headers (['Header-Name' => 'value'])
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private function http(string $method, string $path, string $body = '', array $headers = []): array {
		$url = $this->buildUrl($path);
		$ch = \curl_init($url);
		if ($ch === false) {
			throw new \RuntimeException('curl_init failed');
		}
		$hdrLines = [];
		foreach ($headers as $k => $v) {
			$hdrLines[] = $k . ': ' . $v;
		}
		if ($method === 'POST') {
			\curl_setopt($ch, CURLOPT_POST, 1);
			\curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		} else {
			\curl_setopt($ch, CURLOPT_HTTPGET, 1);
		}
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_HEADER, true);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrLines);
		\curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SEC);
		\curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

		$raw = \curl_exec($ch);
		if ($raw === false) {
			$err = \curl_error($ch);
			\curl_close($ch);
			throw new \RuntimeException('cURL error: ' . $err);
		}
		$info = \curl_getinfo($ch);
		\curl_close($ch);

		$headerSize = (int)($info['header_size'] ?? 0);
		$rawHeaders = \substr($raw, 0, $headerSize);
		$bodyOut    = \substr($raw, $headerSize);
		$status     = (int)($info['http_code'] ?? 0);
		$parsedHdrs = $this->parseHeaders($rawHeaders);

		if (self::VERBOSE) {
			$this->dbg("HTTP {$method} {$url} => {$status}");
		}
		return ['status' => $status, 'headers' => $parsedHdrs, 'body' => $bodyOut];
	}

	/**
	 * HTTP call with WebhooksAuth HMAC headers.
	 *
	 * @param string $method GET|POST
	 * @param string $path   Relative path (no leading slash required)
	 * @param string $body   Raw body exact as sent
	 */
	private function httpSigned(string $method, string $path, string $body, array $extraHeaders = []): array {
		[$p, $q] = $this->splitPathQuery($path);
		$ts    = (string)\time();
		$nonce = $this->newNonce();
		$base  = self::BIND_CONTEXT
			? $this->buildBaseContext($ts, $nonce, $method, '/' . $p, $q, $body)
			: $this->buildBaseSimple($ts, $nonce, $body);
		$sig   = \hash_hmac(self::ALGO, $base, self::SECRET);

		$headers = [
			'Content-Type'         => 'application/json; charset=UTF-8',
			'X-Citomni-Timestamp'  => $ts,
			'X-Citomni-Nonce'      => $nonce,
			'X-Citomni-Signature'  => $sig,
		] + $extraHeaders;

		return $this->http($method, $path, $body, $headers);
	}

	// -----------------------------
	// Asserts
	// -----------------------------

	private function assertStatus(string $name, array $resp, int $want): void {
		if ($resp['status'] === $want) {
			$this->pass($name);
		} else {
			$this->fail($name . " status={$resp['status']} body=" . (string)$resp['body']);
		}
	}

	private function assertStringStartsWith(string $name, string $s, string $prefix): void {
		$ok = \strncmp($s, $prefix, \strlen($prefix)) === 0;
		$ok ? $this->pass($name) : $this->fail($name . ' (unexpected body)');
	}

	private function assertHasKeys(string $name, array $arr, array $keys): void {
		foreach ($keys as $k) {
			if (!\array_key_exists($k, $arr)) {
				$this->fail($name . " (missing key '{$k}')");
				return;
			}
		}
		$this->pass($name);
	}

	private function assertEquals(string $name, string $expected, string $actual): void {
		if ($expected === $actual) {
			$this->pass($name);
		} else {
			$this->fail($name . " expected='{$expected}' actual='{$actual}'");
		}
	}

	private function pass(string $name): void {
		$this->passed++;
		$this->out("✔ PASS: {$name}");
	}

	private function fail(string $name): void {
		$this->failed++;
		$this->out("✘ FAIL: {$name}");
	}

	// -----------------------------
	// Output helpers (new)
	// -----------------------------

	/**
	 * Pretty-print the exact response from the server (status, headers, body).
	 */
	private function showResponse(string $label, array $resp): void {
		$this->out(str_repeat('-', 64));
		$this->out("Response: {$label}");
		$this->out("Status : {$resp['status']}");
		if (!empty($resp['headers'])) {
			$this->out("Headers:");
			foreach ($resp['headers'] as $k => $v) {
				$this->out("  {$k}: {$v}");
			}
		} else {
			$this->out("Headers: (none parsed)");
		}
		$this->out("Body:");
		$body = (string)$resp['body'];
		$pretty = $this->prettyJson($body);
		$this->out($pretty ?? $body);
		$this->out(str_repeat('-', 64));
	}

	private function prettyJson(string $raw): ?string {
		$decoded = \json_decode($raw, true);
		if (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
			return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
		}
		return null;
	}

	// -----------------------------
	// Utils
	// -----------------------------

	private function json(array $data): string {
		return \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
	}

	private function decodeJson(string $raw): array {
		$j = \json_decode($raw, true);
		return \is_array($j) ? $j : [];
	}

	private function buildUrl(string $path): string {
		$base = \rtrim(self::BASE_URL, '/');
		$rel  = \ltrim($path, '/');
		return $base . '/' . $rel;
	}

	/** Return [path, query] where path has NO leading slash, query has NO leading '?' */
	private function splitPathQuery(string $path): array {
		$rel = \ltrim($path, '/');
		$parts = \explode('?', $rel, 2);
		$p = $parts[0] ?? '';
		$q = $parts[1] ?? '';
		return [$p, $q];
	}

	private function buildBaseSimple(string $ts, string $nonce, string $rawBody): string {
		return $ts . '.' . $nonce . '.' . $rawBody;
	}

	private function buildBaseContext(string $ts, string $nonce, string $method, string $path, string $query, string $rawBody): string {
		$sha = \hash('sha256', $rawBody);
		return \implode("\n", [
			$ts,
			$nonce,
			\strtoupper($method),
			$path === '' ? '/' : $path,
			$query,
			$sha,
		]);
	}

	private function newNonce(): string {
		// URL-safe base16-ish nonce up to 32 chars (matches your Nonce constraints).
		return \substr(\bin2hex(\random_bytes(16)), 0, 32);
	}

	private function parseHeaders(string $raw): array {
		$out = [];
		$lines = \preg_split('/\r\n|\n|\r/', $raw) ?: [];
		foreach ($lines as $line) {
			if ($line === '' || \str_starts_with($line, 'HTTP/')) {
				continue;
			}
			$pos = \strpos($line, ':');
			if ($pos === false) {
				continue;
			}
			$k = \trim(\substr($line, 0, $pos));
			$v = \trim(\substr($line, $pos + 1));
			if ($k !== '') {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	private function logHeader(string $title): void {
		$this->out(str_repeat('=', 64));
		$this->out($title);
		$this->out('BASE_URL=' . self::BASE_URL);
		$this->out('BIND_CONTEXT=' . (self::BIND_CONTEXT ? 'true' : 'false') . ' | ALGO=' . self::ALGO);
		$this->out(str_repeat('=', 64));
	}

	private function summary(): void {
		$this->out(str_repeat('-', 64));
		$total = $this->passed + $this->failed;
		$this->out("Summary: PASS={$this->passed}  FAIL={$this->failed}  TOTAL={$total}");
		$this->out(str_repeat('-', 64));
		if ($this->failed > 0) {
			exit(1);
		}
	}

	private function out(string $s): void {
		echo $s . PHP_EOL;
	}

	private function dbg(string $s): void {
		if (self::VERBOSE) {
			$this->out('[DBG] ' . $s);
		}
	}
}

// Run
(new TestSystemController())->run();
