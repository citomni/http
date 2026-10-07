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

namespace CitOmni\Http\Tests\WebhooksAuth;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Enum\WebhooksAuthFailureReason;
use CitOmni\Http\Exception\WebhooksAuthConfigException;
use CitOmni\Http\Exception\WebhooksAuthVerificationException;
use CitOmni\Http\Service\Nonce;
use CitOmni\Http\Service\WebhooksAuth;
use CitOmni\Http\Tests\Support\App;
use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\WebhooksAuth: HMAC signatures, context
 * binding, the timestamp window, replay protection, the IP allow-list and secret
 * loading.
 *
 * Usage:
 *   php tests/webhooks-auth/run.php
 *
 * Notes:
 * - Runs the real WebhooksAuth and Nonce services against the kernel doubles, from
 *   the shipped baseline (Registry::CFG_HTTP) in a temporary CITOMNI_APP_PATH. The
 *   secret file and the nonce ledger live at their baseline paths below it.
 * - Requests are signed here per the contract documented with the baseline. The
 *   HMAC key is the secret's hex text, as the verifier uses it.
 * - In CLI the request body (php://input) is empty. The last case sends real
 *   bodies through PHP's built-in web server with server.php as router.
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
foreach ([
	'Boot/Registry', 'Enum/WebhooksAuthFailureReason',
	'Exception/WebhooksAuthException', 'Exception/WebhooksAuthConfigException', 'Exception/WebhooksAuthVerificationException',
	'Exception/NonceException', 'Exception/NonceConfigException',
	'Service/Nonce', 'Service/WebhooksAuth',
] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

// One random secret per run; the first step below writes it where the baseline expects it.
\define(__NAMESPACE__ . '\\SECRET', \bin2hex(\random_bytes(32)));
const URI = '/hooks/deploy?env=prod';

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

/** Request service double: the client IP as Request::ip() resolved it. */
final class RequestStub {
	public function __construct(private string $ip) {}

	public function ip(): string {
		return $this->ip;
	}
}

/** Log service double that records write() calls. */
final class LogSpy {
	/** @var list<array{file: string, category: string, message: string, context: array}> */
	public array $entries = [];

	public function write(string $file, string $category, string $message, array $context = []): void {
		$this->entries[] = ['file' => $file, 'category' => $category, 'message' => $message, 'context' => $context];
	}
}

/** ErrorHandler double that records httpError() calls instead of terminating. */
final class ErrorHandlerSpy {
	/** @var list<array{status: int, context: array}> */
	public array $calls = [];

	public function httpError(int $status, array $context = []): void {
		$this->calls[] = ['status' => $status, 'context' => $context];
	}
}

/** Write a secret file per the documented contract and return its path. */
function secretFile(array $contents, string $path = CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'): string {
	if (!\is_dir(\dirname($path))) {
		\mkdir(\dirname($path), 0700, true);
	}
	\file_put_contents($path, '<?php return ' . \var_export($contents, true) . ';');
	return $path;
}

/**
 * Build WebhooksAuth for one request from the shipped baseline plus overrides.
 *
 * @param array<string, mixed> $cfg  Overrides for cfg.webhooks; 'enabled' defaults to true here.
 * @param string               $ip   What Request::ip() returns for this request.
 */
function webhooksAuth(array $cfg = [], string $ip = 'unknown', ?LogSpy $log = null, ?ErrorHandlerSpy $errors = null): WebhooksAuth {
	$app = new App([
		'webhooks' => mergeLastWins(Registry::CFG_HTTP['webhooks'], $cfg + ['enabled' => true]),
		'nonce'    => Registry::CFG_HTTP['nonce'],
	]);
	$app->set('request', new RequestStub($ip));
	$app->set('nonce', new Nonce($app));
	$app->set('errorHandler', $errors ?? new ErrorHandlerSpy());
	if ($log !== null) {
		$app->set('log', $log);
	}
	return new WebhooksAuth($app);
}

/**
 * Fill $_SERVER with a webhook request signed per the documented contract.
 *
 * Context-bound mode signs ts, nonce, METHOD, PATH, QUERY and sha256(body), one per
 * line; simple mode signs "ts.nonce.body". Change $_SERVER afterwards to tamper.
 *
 * @return string The signature sent.
 */
function deliver(string $nonce, ?int $ts = null, string $method = 'POST', string $uri = URI, string $signedBody = '', bool $bindContext = true, string $algo = 'sha256', string $secret = SECRET): string {
	$ts ??= \time();
	[$path, $query] = \array_pad(\explode('?', $uri, 2), 2, '');
	$base = $bindContext
		? \implode("\n", [(string)$ts, $nonce, $method, $path, $query, \hash('sha256', $signedBody)])
		: $ts . '.' . $nonce . '.' . $signedBody;
	$signature = \hash_hmac($algo, $base, $secret);

	$_SERVER = [
		'REQUEST_METHOD'           => $method,
		'REQUEST_URI'              => $uri,
		'REMOTE_ADDR'              => '198.51.100.20',
		'HTTP_X_CITOMNI_SIGNATURE' => $signature,
		'HTTP_X_CITOMNI_TIMESTAMP' => (string)$ts,
		'HTTP_X_CITOMNI_NONCE'     => $nonce,
	];
	return $signature;
}

/** Verify the current request with a fresh service and return the failure reason, or null. */
function reason(array $cfg = [], string $ip = 'unknown'): ?string {
	$auth = webhooksAuth($cfg, $ip);
	$ok = $auth->verify();
	same($ok, $auth->getLastFailureReason() === null, 'verify() agrees with getLastFailureReason()');
	return $auth->getLastError();
}

/** Path of the ledger entry for a webhook nonce. */
function ledgerEntry(string $nonce): string {
	return CITOMNI_APP_PATH . '/var/nonces/webhooks/' . \hash('sha256', $nonce) . '.nonce';
}

/** Whether the ledger holds an entry for a webhook nonce. */
function nonceStored(string $nonce): bool {
	\clearstatcache();
	return \is_file(ledgerEntry($nonce));
}

$root = tempDir('webhooks_auth');
\define('CITOMNI_APP_PATH', $root);
$server = null;

try {
	secretFile(['secret' => SECRET]);

	// -- 1. Signatures --------------------------------------------------------

	check('Disabled webhooks fail every request as disabled, without loading a secret', function (): void {
		deliver('d-1');
		$auth = webhooksAuth(['enabled' => false, 'secret_file' => CITOMNI_APP_PATH . '/missing.php']);
		same(false, $auth->isEnabled());
		same(false, $auth->verify());
		same(WebhooksAuthFailureReason::Disabled, $auth->getLastFailureReason());
		same('disabled', $auth->getLastError());
		same(false, nonceStored('d-1'));
	});

	check('A request signed per the contract is accepted once and its replay is rejected', function (): void {
		deliver('a-1');
		same(null, reason());
		same(true, nonceStored('a-1'));
		same('nonce_rejected', reason(), 'replay');
	});

	check('The signature binds method, path, query and body', function (): void {
		deliver('b-1');
		$_SERVER['REQUEST_METHOD'] = 'PUT';
		same('signature_mismatch', reason(), 'method');

		deliver('b-2');
		$_SERVER['REQUEST_URI'] = '/hooks/other?env=prod';
		same('signature_mismatch', reason(), 'path');

		deliver('b-3');
		$_SERVER['REQUEST_URI'] = '/hooks/deploy?env=dev';
		same('signature_mismatch', reason(), 'query');

		deliver('b-4', signedBody: '{"other":"body"}');
		same('signature_mismatch', reason(), 'body');
	});

	check('Simple mode signs "ts.nonce.body" and leaves method and path unbound', function (): void {
		$cfg = ['bind_context' => false];
		deliver('s-1', bindContext: false);
		same(null, reason($cfg));

		deliver('s-2', bindContext: false);
		$_SERVER['REQUEST_METHOD'] = 'PUT';
		$_SERVER['REQUEST_URI'] = '/elsewhere';
		same(null, reason($cfg));

		// A context-bound signature does not pass in simple mode, and vice versa.
		deliver('s-3');
		same('signature_mismatch', reason($cfg));
		deliver('s-4', bindContext: false);
		same('signature_mismatch', reason());
	});

	check('A wrong signature is rejected without consuming its nonce', function (): void {
		$signature = deliver('w-1');
		$_SERVER['HTTP_X_CITOMNI_SIGNATURE'] = \strrev($signature);
		same('signature_mismatch', reason());
		same(false, nonceStored('w-1'));

		deliver('w-1');
		same(null, reason(), 'the genuine request with the same nonce');
	});

	check('Missing or empty headers fail as headers_missing', function (): void {
		foreach (['HTTP_X_CITOMNI_SIGNATURE', 'HTTP_X_CITOMNI_TIMESTAMP', 'HTTP_X_CITOMNI_NONCE'] as $header) {
			deliver('h-1');
			unset($_SERVER[$header]);
			same('headers_missing', reason(), 'without ' . $header);

			deliver('h-1');
			$_SERVER[$header] = '';
			same('headers_missing', reason(), 'empty ' . $header);
		}
		same(false, nonceStored('h-1'));
	});

	check('Malformed signatures are rejected before the HMAC; uppercase hex is accepted', function (): void {
		$signature = deliver('m-1');
		foreach ([\substr($signature, 1), $signature . 'a', \str_repeat('g', 64)] as $malformed) {
			$_SERVER['HTTP_X_CITOMNI_SIGNATURE'] = $malformed;
			same('signature_malformed', reason(), $malformed);
		}
		$_SERVER['HTTP_X_CITOMNI_SIGNATURE'] = \strtoupper($signature);
		same(null, reason());
	});

	check('Timestamps older than ttl + skew or further ahead than skew are rejected before the ledger', function (): void {
		// Baseline window: 300 s plus 60 s of clock skew; margins absorb a second tick.
		deliver('t-1', \time() - 358);
		same(null, reason(), 'old but inside the window');
		deliver('t-2', \time() + 55);
		same(null, reason(), 'ahead but inside the skew');

		deliver('t-3', \time() - 365);
		same('timestamp_out_of_window', reason(), 'too old');
		deliver('t-4', \time() + 65);
		same('timestamp_out_of_window', reason(), 'too far ahead');
		foreach (['0', '-5', 'abc'] as $ts) {
			deliver('t-5');
			$_SERVER['HTTP_X_CITOMNI_TIMESTAMP'] = $ts;
			same('timestamp_out_of_window', reason(), $ts);
		}
		same(false, nonceStored('t-3') || nonceStored('t-4') || nonceStored('t-5'), 'ledger written for a rejected timestamp');

		$cfg = ['ttl_seconds' => 10, 'ttl_clock_skew_tolerance' => 0];
		deliver('t-6', \time() - 15);
		same('timestamp_out_of_window', reason($cfg), 'custom window');
	});


	check('The ledger keeps a webhook nonce for the whole acceptance window, ttl + 2 x skew', function (): void {
		// Baseline: 300 s + 2 x 60 s. An entry stored 360 s ago can still meet a
		// timestamp that is inside the window on this clock.
		deliver('k-1');
		same(null, reason());

		\touch(ledgerEntry('k-1'), \time() - 360);
		deliver('k-1');
		same('nonce_rejected', reason(), 'replay after ttl + skew');

		\touch(ledgerEntry('k-1'), \time() - 420);
		deliver('k-1');
		same(null, reason(), 'reuse after ttl + 2 x skew');
	});


	// -- 2. IP allow-list -----------------------------------------------------

	check('The allow-list is checked first, against Request::ip() or else REMOTE_ADDR', function (): void {
		$cfg = ['allowed_ips' => ['10.0.0.0/8', '2001:db8::/32', '192.0.2.7']];

		$_SERVER = ['REMOTE_ADDR' => '11.0.0.1'];
		same('ip_not_allowed', reason($cfg), 'unsigned request from an unlisted peer');

		// Request::ip() yields no client IP for private peers and in CLI; REMOTE_ADDR decides.
		foreach (['unknown', 'CLI'] as $resolved) {
			deliver('i-' . $resolved);
			$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
			same(null, reason($cfg, $resolved), 'CIDR via REMOTE_ADDR, ip() = ' . $resolved);
		}
		deliver('i-2');
		$_SERVER['REMOTE_ADDR'] = '2001:db8::5';
		same(null, reason($cfg), 'IPv6 CIDR');
		deliver('i-3');
		$_SERVER['REMOTE_ADDR'] = '2001:db9::5';
		same('ip_not_allowed', reason($cfg), 'outside the IPv6 CIDR');

		// A client IP resolved by Request::ip() takes precedence over the peer address.
		deliver('i-4');
		$_SERVER['REMOTE_ADDR'] = '172.16.0.5';
		same(null, reason($cfg, '192.0.2.7'), 'exact entry via ip()');
		deliver('i-5');
		$_SERVER['REMOTE_ADDR'] = '10.1.1.1';
		same('ip_not_allowed', reason($cfg, '203.0.113.99'), 'ip() outside the list, peer inside');

		// IPv4 never matches an IPv6 entry and vice versa.
		deliver('i-6');
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		same('ip_not_allowed', reason(['allowed_ips' => ['::ffff:0:0/96', '2001:db8::/32']]), 'mixed families');
	});


	check('An allow-list entry with a malformed mask matches no address', function (): void {
		foreach (['10.0.0.0/', '10.0.0.0/x', '::/'] as $entry) {
			foreach (['203.0.113.9', '2001:db8::1'] as $peer) {
				$_SERVER = ['REMOTE_ADDR' => $peer];
				same('ip_not_allowed', reason(['allowed_ips' => [$entry]]), $entry . ' and ' . $peer);
			}
		}
		// Whitespace around a decimal mask is still accepted.
		deliver('c-1');
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		same(null, reason(['allowed_ips' => ['10.0.0.0/ 8']]));
	});


	// -- 3. Secret and algorithm ----------------------------------------------

	check('The secret file selects sha512; a cfg algo overrides the file', function (): void {
		$file = secretFile(['secret' => SECRET, 'algo' => 'SHA512'], CITOMNI_APP_PATH . '/var/secrets/sha512.secret.php');

		deliver('g-1', algo: 'sha512');
		same(128, \strlen($_SERVER['HTTP_X_CITOMNI_SIGNATURE']));
		same(null, reason(['secret_file' => $file]));
		deliver('g-2');
		same('signature_malformed', reason(['secret_file' => $file]), 'sha256 signature for a sha512 secret');

		deliver('g-3');
		same(null, reason(['secret_file' => $file, 'algo' => 'sha256']), 'cfg algo over file algo');
	});

	check('Invalid configuration fails at construction with WebhooksAuthConfigException', function (): void {
		$dir = CITOMNI_APP_PATH . '/var/secrets/';
		\file_put_contents($dir . 'scalar.php', '<?php return "x";');
		$cases = [
			'no secret file'      => ['secret_file' => ''],
			'missing file'        => ['secret_file' => $dir . 'missing.php'],
			'file not an array'   => ['secret_file' => $dir . 'scalar.php'],
			'empty secret'        => ['secret_file' => secretFile(['secret' => ''], $dir . 'blank.php')],
			'secret not hex'      => ['secret_file' => secretFile(['secret' => 'not-hex'], $dir . 'text.php')],
			'unknown file algo'   => ['secret_file' => secretFile(['secret' => SECRET, 'algo' => 'md5'], $dir . 'md5.php')],
			'unknown cfg algo'    => ['algo' => 'md5'],
			'ttl below 1'         => ['ttl_seconds' => 0],
			'negative skew'       => ['ttl_clock_skew_tolerance' => -1],
			'empty header name'   => ['header_nonce' => ''],
		];
		foreach ($cases as $label => $cfg) {
			$error = thrown(static fn () => webhooksAuth($cfg));
			same(WebhooksAuthConfigException::class, $error::class, $label);
		}
		// The cfg algo is validated even while webhooks are disabled.
		$error = thrown(static fn () => webhooksAuth(['enabled' => false, 'algo' => 'md5']));
		same(WebhooksAuthConfigException::class, $error::class, 'unknown cfg algo while disabled');
	});


	// -- 4. Adapters and logging ----------------------------------------------

	check('requireValid() returns the verified body and throws the failure reason', function (): void {
		deliver('r-1');
		same('', webhooksAuth()->requireValid(), 'CLI body');

		$error = thrown(static fn () => webhooksAuth()->requireValid());
		same(WebhooksAuthVerificationException::class, $error::class);
		same(WebhooksAuthFailureReason::NonceRejected, $error->reason);
	});

	check('requireOrAbort() hands the reason to the error handler, without signature material', function (): void {
		$signature = deliver('o-1');
		$_SERVER['HTTP_X_CITOMNI_TIMESTAMP'] = (string)(\time() - 3600);
		$errors = new ErrorHandlerSpy();
		same('', webhooksAuth(errors: $errors)->requireOrAbort());
		same(1, \count($errors->calls));
		same(404, $errors->calls[0]['status']);
		same([
			'webhook_guard_reason' => 'timestamp_out_of_window',
			'remote_addr'          => '198.51.100.20',
			'have_sig'             => true,
			'have_ts'              => true,
			'have_nonce'           => true,
		], $errors->calls[0]['context']['meta']);
		same(false, \str_contains(\serialize($errors->calls), $signature));

		$custom = new ErrorHandlerSpy();
		webhooksAuth(errors: $custom)->requireOrAbort(401);
		same(401, $custom->calls[0]['status']);
	});

	check('Failures, and successes when enabled, are logged without signature material', function (): void {
		$signature = deliver('l-1');
		$_SERVER['HTTP_X_CITOMNI_SIGNATURE'] = \strrev($signature);
		$log = new LogSpy();
		webhooksAuth(log: $log)->verify();
		same(1, \count($log->entries));
		$entry = $log->entries[0];
		same(['webhooks.jsonl', 'webhook.fail', 'Webhook authentication failed', 'signature_mismatch'], [$entry['file'], $entry['category'], $entry['message'], $entry['context']['reason']]);
		$recorded = \serialize($entry);
		same(false, \str_contains($recorded, \strrev($signature)) || \str_contains($recorded, SECRET), 'signature or secret in log');

		$quiet = new LogSpy();
		webhooksAuth(['log_failures' => false], log: $quiet)->verify();
		same([], $quiet->entries);

		deliver('l-2');
		$chatty = new LogSpy();
		same(true, webhooksAuth(['log_successes' => true], log: $chatty)->verify());
		same(['webhook.ok', null], [$chatty->entries[0]['category'], $chatty->entries[0]['context']['reason']]);
	});


	// -- 5. Real requests -----------------------------------------------------

	check('A real POST body is verified byte for byte and returned by requireValid()', function () use ($root, &$server): void {
		$server = new FixtureServer($root, __DIR__ . '/server.php');
		$body = "{\"event\":\"deploy\",\n \"ref\":\"main\"}";

		$send = static function (string $nonce, string $sentBody) use ($server, $body): array {
			deliver($nonce, uri: URI, signedBody: $body);
			$r = $server->request('POST', URI, [
				'Content-Type'        => 'application/json',
				'X-Citomni-Signature' => $_SERVER['HTTP_X_CITOMNI_SIGNATURE'],
				'X-Citomni-Timestamp' => $_SERVER['HTTP_X_CITOMNI_TIMESTAMP'],
				'X-Citomni-Nonce'     => $_SERVER['HTTP_X_CITOMNI_NONCE'],
			], $sentBody);
			same(200, $r['status'], $r['body']);
			return \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
		};

		same(['body' => $body, 'reason' => null], $send('real-1', $body));
		same(['body' => null, 'reason' => 'nonce_rejected'], $send('real-1', $body), 'replay');
		same(['body' => null, 'reason' => 'signature_mismatch'], $send('real-2', \str_replace("\n", '', $body)), 'reformatted body');
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
