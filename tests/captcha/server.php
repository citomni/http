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
use CitOmni\Http\Controller\CaptchaController;
use CitOmni\Http\Service\Captcha;
use CitOmni\Http\Service\Cookie;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Service\Response;
use CitOmni\Http\Service\Session;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Built-in web server router for tests/captcha/run.php. Not a suite of its own;
 * tests/run.php only collects run.php and database.php.
 *
 * Runs the real Captcha, Session, Cookie, Request and Response services and
 * CaptchaController in a real request, registered as the HTTP service map
 * registers them. citomni/image is replaced by FakeCaptchaImage, so the suite
 * needs neither that package nor ext-gd.
 *
 * Paths:
 * - GET /page          Issues the request's challenge as a form page does and
 *                      reports htmlField(), imagePath() and, for the suite
 *                      only, the code.
 * - GET /captcha.png   CaptchaController::image(), routed as an app would.
 * - POST /submit       Reports verify() for the posted form.
 *
 * Every request names its session storage directory with ?dir=, below
 * <document root>/sessions/. ?off=1 turns captcha protection off.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

\define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);

require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Exception/CaptchaConfigException', 'Service/Request', 'Service/Response', 'Service/Cookie', 'Service/Session', 'Service/Captcha', 'Controller/CaptchaController'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

/** Stand-in for the captchaImage service of citomni/image. */
final class FakeCaptchaImage {
	public ?string $lastCode = null;

	public function code(): string {
		return $this->lastCode = 'K7' . \strtoupper(\bin2hex(\random_bytes(2)));
	}

	/** @return array{data:string, format:string, mime:string, width:int, height:int, bytes:int} */
	public function render(string $code, array $options = []): array {
		$data = "\x89PNG fake " . $code . ' seed ' . $options['seed'];
		return ['data' => $data, 'format' => 'png', 'mime' => 'image/png', 'width' => 200, 'height' => 64, 'bytes' => \strlen($data)];
	}

	public function verify(string $expected, string $answer): bool {
		return $expected !== '' && \strcasecmp($expected, \trim($answer)) === 0;
	}
}

$dir = (string)($_GET['dir'] ?? '');
if (\preg_match('/^[a-z0-9-]+$/', $dir) !== 1) {
	throw new \RuntimeException('Missing or invalid ?dir=.');
}

$app = new App(mergeLastWins(
	[
		'http' => Registry::CFG_HTTP['http'],
		'session' => Registry::CFG_HTTP['session'],
		'cookie' => Registry::CFG_HTTP['cookie'],
		'locale' => ['charset' => 'UTF-8'],
		'security' => [
			'captcha_protection' => Registry::CFG_HTTP['security']['captcha_protection'],
			'captcha' => Registry::CFG_HTTP['security']['captcha'],
		],
	],
	[
		'http' => ['base_url' => 'http://127.0.0.1'],
		'session' => ['save_path' => $_SERVER['DOCUMENT_ROOT'] . '/sessions/' . $dir, 'cookie_secure' => false],
		'security' => ['captcha_protection' => ($_GET['off'] ?? '') !== '1'],
	]
));
$images = new FakeCaptchaImage();
$app->set('request', new Request($app));
$app->set('response', new Response($app));
$app->set('cookie', new Cookie($app));
$app->set('session', new Session($app));
$app->set('captchaImage', $images);
$app->set('captcha', new Captcha($app));

$path = (string)\parse_url((string)$_SERVER['REQUEST_URI'], \PHP_URL_PATH);

switch ($path) {
	case '/captcha.png':
		(new CaptchaController($app))->image();
		return;

	case '/page':
		$out = ['field' => $app->captcha->htmlField(), 'path' => $app->captcha->imagePath(), 'id' => $app->captcha->current(), 'code' => $images->lastCode];
		break;

	case '/submit':
		$out = ['ok' => $app->captcha->verify()];
		break;

	default:
		\http_response_code(404);
		return;
}

if (\session_status() === \PHP_SESSION_ACTIVE) {
	\session_write_close();
}

\header('Content-Type: application/json');
echo \json_encode($out, \JSON_THROW_ON_ERROR);
