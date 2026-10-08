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

namespace CitOmni\Http\Tests\Captcha;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Exception\CaptchaConfigException;
use CitOmni\Http\Service\Captcha;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Tests\Support\App;
use CitOmni\Http\Tests\Support\FixtureServer;
use function CitOmni\Http\Tests\Support\headerValues;
use function CitOmni\Http\Tests\Support\mergeLastWins;
use function CitOmni\Http\Tests\Support\removeTree;
use function CitOmni\Http\Tests\Support\tempDir;

/*
 * Isolated suite for CitOmni\Http\Service\Captcha and CaptchaController: the
 * challenge lifecycle (issue, image, one verification per challenge, expiry,
 * eviction), configuration, and the image route in real requests.
 *
 * Usage:
 *   php tests/captcha/run.php
 *
 * Notes:
 * - The first part runs the real Captcha and Request services on the kernel
 *   doubles, with a SessionStore double that counts reads and writes and a
 *   FakeImages double for the captchaImage service of citomni/image. Each
 *   request gets a fresh Captcha, as in production.
 * - The second part sends real requests to PHP's built-in web server with
 *   server.php as router: real Session, Response and CaptchaController, so the
 *   session cookie, headers and session files behave as in production.
 * - Neither part needs citomni/image or ext-gd.
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

// Registry::CFG_HTTP derives paths from CITOMNI_APP_PATH; the first part writes nothing there.
\define('CITOMNI_APP_PATH', \sys_get_temp_dir() . '/citomni_http_captcha_test_unused');

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__) . '/support/fixtures.php';
foreach (['Boot/Registry', 'Exception/CaptchaConfigException', 'Service/Request', 'Service/Captcha'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

const SESSION_NAME = 'CITSESSID';
const BASE_SERVER = [
	'REQUEST_METHOD' => 'POST',
	'REQUEST_URI'    => '/contact',
	'HTTP_HOST'      => 'example.test',
	'HTTPS'          => 'on',
	'SERVER_PORT'    => '443',
	'REMOTE_ADDR'    => '203.0.113.10',
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

/** Session service double: one browser's server-side session. get(), set() and remove() start it, as the real service does. */
final class SessionStore {
	public array $data = [];
	public int $reads = 0;
	public int $writes = 0;
	private bool $active = false;

	public function isActive(): bool {
		return $this->active;
	}

	public function start(): void {
		$this->active = true;
	}

	public function get(string $key): mixed {
		$this->active = true;
		$this->reads++;
		return $this->data[$key] ?? null;
	}

	public function set(string $key, mixed $value): void {
		$this->active = true;
		$this->writes++;
		$this->data[$key] = $value;
	}

	public function remove(string $key): void {
		$this->active = true;
		$this->writes++;
		unset($this->data[$key]);
	}

	/** End the request: the next request finds the session closed until it reads it. */
	public function close(): void {
		$this->active = false;
	}
}

/** Stand-in for the captchaImage service: numbered codes, recorded renders, case-insensitive comparison. */
final class FakeImages {
	/** @var list<array{0:string, 1:mixed}> */
	public array $renders = [];
	private int $codes = 0;

	public function code(): string {
		return 'CODE' . ++$this->codes;
	}

	public function render(string $code, array $options = []): array {
		$this->renders[] = [$code, $options['seed'] ?? null];
		$data = 'png:' . $code . ':' . ($options['seed'] ?? '');
		return ['data' => $data, 'format' => 'png', 'mime' => 'image/png', 'width' => 200, 'height' => 64, 'bytes' => \strlen($data)];
	}

	public function verify(string $expected, string $answer): bool {
		return $expected !== '' && \strcasecmp($expected, \trim($answer)) === 0;
	}
}

/**
 * Build the Captcha service for one request in a browser session.
 *
 * @param array<string, mixed>       $post     The parsed form body ($_POST).
 * @param array<string, string>|null $cookies  Request cookies; null sends the session cookie.
 * @param array<string, mixed>       $security Overrides for the security baseline (captcha_protection, captcha.*).
 */
function captchaFor(SessionStore $session, ?FakeImages $images, array $post = [], ?array $cookies = null, array $security = []): Captcha {
	$session->close();
	$_SERVER = BASE_SERVER;
	$_POST = $post;
	$_GET = [];
	$_COOKIE = $cookies ?? [SESSION_NAME => 'browser-session'];

	$app = new App(mergeLastWins(
		[
			'http' => Registry::CFG_HTTP['http'],
			'session' => Registry::CFG_HTTP['session'],
			'security' => [
				'captcha_protection' => Registry::CFG_HTTP['security']['captcha_protection'],
				'captcha' => Registry::CFG_HTTP['security']['captcha'],
			],
		],
		$security === [] ? [] : ['security' => $security]
	));
	$app->set('session', $session);
	$app->set('request', new Request($app));
	if ($images !== null) {
		$app->set('captchaImage', $images);
	}
	return new Captcha($app);
}

/** Issue a challenge on a GET page view, as rendering a form does; returns [id, code]. */
function issueChallenge(SessionStore $session, FakeImages $images, array $security = []): array {
	$id = captchaFor($session, $images, [], null, $security)->issue();
	foreach ($session->data['_captcha'] as $entry) {
		if ($entry[0] === $id) {
			return [$id, $entry[1]];
		}
	}
	throw new \RuntimeException('Issued challenge not stored');
}

/** Ids of the stored challenges, oldest first. */
function storedIds(SessionStore $session): array {
	return \array_column($session->data['_captcha'] ?? [], 0);
}


// -- 1. Configuration ---------------------------------------------------------

check('The baseline constructs without touching session, request or the image service', function (): void {
	$app = new App(['session' => Registry::CFG_HTTP['session'], 'security' => [
		'captcha_protection' => Registry::CFG_HTTP['security']['captcha_protection'],
		'captcha' => Registry::CFG_HTTP['security']['captcha'],
	]]);
	$captcha = new Captcha($app);
	same(true, $captcha->isEnabled());
});

check('Invalid configuration fails at construction', function (): void {
	foreach ([
		['captcha_protection' => 'yes'],
		['captcha' => ['session_key' => '']],
		['captcha' => ['ttl' => 0]],
		['captcha' => ['ttl' => '1200']],
		['captcha' => ['max_pending' => 0]],
		['captcha' => ['id_field' => '']],
		['captcha' => ['answer_field' => 'captcha_id']],
		['captcha' => ['image_path' => 'captcha.png']],
		['captcha' => ['image_path' => '/captcha.png?x=1']],
		['captcha' => ['image_path' => '/captcha.png#top']],
		['captcha' => ['image_path' => '/cap tcha.png']],
	] as $security) {
		$error = thrown(static fn () => captchaFor(new SessionStore(), new FakeImages(), [], null, $security));
		same(CaptchaConfigException::class, $error::class, \json_encode($security));
	}
});


// -- 2. Issuing ---------------------------------------------------------------

check('issue() stores code, render seed and expiry under a new id', function (): void {
	$session = new SessionStore();
	$before = \time();
	$id = captchaFor($session, new FakeImages())->issue();

	same(1, \preg_match('/^[0-9a-f]{16}$/', $id), 'id format');
	same(1, \count($session->data['_captcha']));
	[$storedId, $code, $seed, $expires] = $session->data['_captcha'][0];
	same([$id, 'CODE1'], [$storedId, $code]);
	same(true, \is_int($seed), 'seed is an int');
	same(true, $expires >= $before + 1200 && $expires <= \time() + 1200, 'expiry is now + ttl');

	$second = captchaFor($session, new FakeImages())->issue();
	same(true, $second !== $id);
	same([$id, $second], storedIds($session));
});

check('current(), htmlField() and imagePath() share one challenge per request', function (): void {
	$session = new SessionStore();
	$captcha = captchaFor($session, new FakeImages());
	$id = $captcha->current();
	same($id, $captcha->current());
	same('<input type="hidden" name="captcha_id" value="' . $id . '">', $captcha->htmlField());
	same('/captcha.png?id=' . $id, $captcha->imagePath());
	same([$id], storedIds($session));

	$custom = captchaFor($session, new FakeImages(), [], null, ['captcha' => ['id_field' => 'c"id', 'image_path' => '/bot/check.png']]);
	same('<input type="hidden" name="c&quot;id" value="' . $custom->current() . '">', $custom->htmlField());
	same('/bot/check.png?id=' . $custom->current(), $custom->imagePath());
	same(2, \count(storedIds($session)), 'a new request issues a new challenge');
});

check('Issuing beyond max_pending drops the oldest challenges', function (): void {
	$session = new SessionStore();
	$ids = [];
	for ($i = 0; $i < 7; $i++) {
		$ids[] = captchaFor($session, new FakeImages())->issue();
	}
	same(\array_slice($ids, 2), storedIds($session));

	$small = new SessionStore();
	$kept = '';
	for ($i = 0; $i < 3; $i++) {
		$kept = captchaFor($small, new FakeImages(), [], null, ['captcha' => ['max_pending' => 1]])->issue();
	}
	same([$kept], storedIds($small));
});

check('issue() without the captchaImage service fails before touching the session', function (): void {
	$session = new SessionStore();
	$error = thrown(static fn () => captchaFor($session, null)->issue());
	same(CaptchaConfigException::class, $error::class);
	same(true, \str_contains($error->getMessage(), 'citomni/image'), 'message names the package');
	same([0, 0], [$session->reads, $session->writes]);
});


// -- 3. Images ----------------------------------------------------------------

check('image() renders a pending challenge with its stored seed, the same on every call', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);
	$seed = $session->data['_captcha'][0][2];
	$writes = $session->writes;

	$first = captchaFor($session, $images)->image($id);
	$second = captchaFor($session, $images)->image($id);
	same('png:' . $code . ':' . $seed, $first['data']);
	same($first, $second);
	same([[$code, $seed], [$code, $seed]], $images->renders);
	same($writes, $session->writes, 'image() does not write the session');
	same([$id], storedIds($session), 'image() does not consume the challenge');
});

check('image() returns null for unknown, malformed and expired ids', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id] = issueChallenge($session, $images);

	foreach ([null, 42, ['x'], '', \strtoupper($id), $id . '0', \str_repeat('0', 16)] as $candidate) {
		same(null, captchaFor($session, $images)->image($candidate), \var_export($candidate, true));
	}

	$session->data['_captcha'][0][3] = \time() - 1;
	same(null, captchaFor($session, $images)->image($id), 'expired');
	same([], $images->renders);
});

check('image() answers a request without a session cookie without reading the session', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id] = issueChallenge($session, $images);
	$reads = $session->reads;

	same(null, captchaFor($session, $images, [], [])->image($id));
	same(null, captchaFor($session, $images, [], ['PHPSESSID' => 'other'])->image($id));
	same($reads, $session->reads);
});


// -- 4. Verification ----------------------------------------------------------

check('A right answer passes once; the challenge is gone afterwards', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);

	same(true, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => ' ' . \strtolower($code) . ' '])->verify());
	same([], storedIds($session));
	same(false, \array_key_exists('_captcha', $session->data), 'empty state is removed');
	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => $code])->verify(), 'replay');
});

check('A wrong answer uses up the challenge', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);

	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => 'WRONG'])->verify());
	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => $code])->verify());
});

check('An expired challenge fails with the right answer and is dropped', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);
	[$other] = issueChallenge($session, $images);
	$session->data['_captcha'][0][3] = \time() - 1;

	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => $code])->verify());
	same([$other], storedIds($session));
});

check('Challenges of other tabs stay valid', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$firstId, $firstCode] = issueChallenge($session, $images);
	[$secondId, $secondCode] = issueChallenge($session, $images);

	same(true, captchaFor($session, $images, ['captcha_id' => $secondId, 'captcha' => $secondCode])->verify());
	same([$firstId], storedIds($session));
	same(true, captchaFor($session, $images, ['captcha_id' => $firstId, 'captcha' => $firstCode])->verify());
});

check('Missing, array-shaped and unknown fields fail without warnings; only an attempt on a known id uses it up', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);

	// Without the challenge's id, nothing is consumed.
	foreach ([
		[],
		['captcha' => $code],
		['captcha_id' => [$id], 'captcha' => $code],
		['captcha_id' => '0123456789abcdef', 'captcha' => $code],
	] as $post) {
		same(false, captchaFor($session, $images, $post)->verify(), \json_encode($post));
	}
	same([$id], storedIds($session));

	// With it, a missing or array-shaped answer is an attempt and uses up the challenge.
	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => [$code]])->verify(), 'array-shaped answer');
	same([], storedIds($session));
	[$next] = issueChallenge($session, $images);
	same(false, captchaFor($session, $images, ['captcha_id' => $next])->verify(), 'missing answer');
	same([], storedIds($session));
});

check('verifyAnswer() takes id and answer as arguments', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);
	same(true, captchaFor($session, $images)->verifyAnswer($id, $code));
	same(false, captchaFor($session, $images)->verifyAnswer($id, $code));
});

check('A request without a session cookie fails without reading the session', function (): void {
	$session = new SessionStore();
	$images = new FakeImages();
	[$id, $code] = issueChallenge($session, $images);
	$reads = $session->reads;

	same(false, captchaFor($session, $images, ['captcha_id' => $id, 'captcha' => $code], [])->verify());
	same($reads, $session->reads);
	same([$id], storedIds($session));
});

check('With protection off, verify() and verifyAnswer() pass without reading the session', function (): void {
	$session = new SessionStore();
	$captcha = captchaFor($session, null, [], [], ['captcha_protection' => false]);
	same(false, $captcha->isEnabled());
	same(true, $captcha->verify());
	same(true, $captcha->verifyAnswer('', ''));
	same([0, 0], [$session->reads, $session->writes]);
});

check('Malformed session state counts as no challenge and is replaced on the next issue', function (): void {
	$images = new FakeImages();
	foreach ([
		'garbage',
		[['short']],
		[['0123456789abcdef', 'CODE', 1]],
		[['0123456789ABCDEF', 'CODE', 1, \time() + 60]],
		[['0123456789abcdef', '', 1, \time() + 60]],
		[['0123456789abcdef', 'CODE', '1', \time() + 60]],
		[['id' => '0123456789abcdef', 'CODE', 1, \time() + 60]],
	] as $state) {
		$session = new SessionStore();
		$session->data['_captcha'] = $state;
		same(false, captchaFor($session, $images, ['captcha_id' => '0123456789abcdef', 'captcha' => 'CODE'])->verify(), \json_encode($state));
		$session->data['_captcha'] = $state;
		$id = captchaFor($session, $images)->issue();
		same([$id], storedIds($session), \json_encode($state));
	}
});

check('A matching challenge without the captchaImage service fails explicitly', function (): void {
	$session = new SessionStore();
	[$id, $code] = issueChallenge($session, new FakeImages());
	same(false, captchaFor($session, null, ['captcha_id' => 'ffffffffffffffff', 'captcha' => $code])->verify(), 'no match needs no image service');
	same(CaptchaConfigException::class, thrown(static fn () => captchaFor($session, null, ['captcha_id' => $id, 'captcha' => $code])->verify())::class);
});


// -- 5. Image route and form post in real requests ----------------------------

/** One request to the fixture server; the cookie jar holds the browser's session cookie. */
function send(FixtureServer $server, string $method, string $target, string &$cookie, array $form = []): array {
	$headers = [];
	if ($cookie !== '') {
		$headers['Cookie'] = SESSION_NAME . '=' . $cookie;
	}
	$body = '';
	if ($form !== []) {
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		$body = \http_build_query($form);
	}
	$r = $server->request($method, $target, $headers, $body);
	foreach (headerValues($r['headers'], 'Set-Cookie') as $value) {
		if (\preg_match('/^' . SESSION_NAME . '=([^;]*)/', $value, $m) === 1) {
			$cookie = $m[1];
		}
	}
	return $r;
}

/** @return list<string> Session files in one case's storage directory. */
function sessionFiles(string $root, string $dir): array {
	return \glob($root . '/sessions/' . $dir . '/sess_*') ?: [];
}

$root = tempDir('captcha');
\mkdir($root . '/sessions', 0700);
$server = null;

try {
	$server = new FixtureServer($root, __DIR__ . '/server.php');

	check('A form page issues a challenge whose image is served the same on every request, never cached', function () use ($server): void {
		$cookie = '';
		$page = \json_decode(send($server, 'GET', '/page?dir=serve', $cookie)['body'], true, 512, \JSON_THROW_ON_ERROR);
		same(true, $cookie !== '', 'the page starts a session');
		same('<input type="hidden" name="captcha_id" value="' . $page['id'] . '">', $page['field']);
		same('/captcha.png?id=' . $page['id'], $page['path']);

		$first = send($server, 'GET', $page['path'] . '&dir=serve', $cookie);
		same(200, $first['status']);
		same(1, \preg_match('/^\x89PNG fake ' . $page['code'] . ' seed \d+$/', $first['body']), 'body ' . \var_export($first['body'], true));
		same(['image/png'], headerValues($first['headers'], 'Content-Type'));
		same([(string)\strlen($first['body'])], headerValues($first['headers'], 'Content-Length'));
		same(['nosniff'], headerValues($first['headers'], 'X-Content-Type-Options'));
		same(true, \str_contains(\implode(',', headerValues($first['headers'], 'Cache-Control')), 'no-store'), 'no-store');
		same($first['body'], send($server, 'GET', $page['path'] . '&dir=serve', $cookie)['body']);
	});

	check('The image route answers 404 without a body, and starts no session without a cookie', function () use ($server, $root): void {
		$cookie = '';
		$page = \json_decode(send($server, 'GET', '/page?dir=refuse', $cookie)['body'], true, 512, \JSON_THROW_ON_ERROR);
		$files = sessionFiles($root, 'refuse');

		$none = '';
		$cookieless = send($server, 'GET', $page['path'] . '&dir=refuse', $none);
		same([404, '', []], [$cookieless['status'], $cookieless['body'], headerValues($cookieless['headers'], 'Set-Cookie')]);
		same($files, sessionFiles($root, 'refuse'), 'no session file for a cookieless request');

		same(404, send($server, 'GET', '/captcha.png?id=ffffffffffffffff&dir=refuse', $cookie)['status'], 'unknown id');
		same(404, send($server, 'GET', '/captcha.png?id=nope&dir=refuse', $cookie)['status'], 'malformed id');
		same(404, send($server, 'GET', $page['path'] . '&dir=refuse&off=1', $cookie)['status'], 'protection off');
		same(200, send($server, 'GET', $page['path'] . '&dir=refuse', $cookie)['status'], 'the challenge is still served');
	});

	check('A posted answer verifies once', function () use ($server): void {
		$cookie = '';
		$page = \json_decode(send($server, 'GET', '/page?dir=verify', $cookie)['body'], true, 512, \JSON_THROW_ON_ERROR);
		$form = ['captcha_id' => $page['id'], 'captcha' => \strtolower($page['code'])];

		same(['ok' => true], \json_decode(send($server, 'POST', '/submit?dir=verify', $cookie, $form)['body'], true));
		same(['ok' => false], \json_decode(send($server, 'POST', '/submit?dir=verify', $cookie, $form)['body'], true), 'replay');
		same(404, send($server, 'GET', $page['path'] . '&dir=verify', $cookie)['status'], 'a used challenge has no image');
	});

	check('A wrong posted answer uses up the challenge; with protection off a post passes', function () use ($server): void {
		$cookie = '';
		$page = \json_decode(send($server, 'GET', '/page?dir=wrong', $cookie)['body'], true, 512, \JSON_THROW_ON_ERROR);

		same(['ok' => false], \json_decode(send($server, 'POST', '/submit?dir=wrong', $cookie, ['captcha_id' => $page['id'], 'captcha' => 'WRONG'])['body'], true));
		same(['ok' => false], \json_decode(send($server, 'POST', '/submit?dir=wrong', $cookie, ['captcha_id' => $page['id'], 'captcha' => $page['code']])['body'], true));
		same(['ok' => true], \json_decode(send($server, 'POST', '/submit?dir=wrong&off=1', $cookie, ['captcha' => 'anything'])['body'], true));
	});

} finally {
	$server?->stop();
	removeTree($root);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
