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

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\Router;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Built-in web server router for tests/router/run.php. Not a suite of its own;
 * tests/run.php only collects run.php and database.php.
 *
 * Every request runs the real Router over the route table below. The controller
 * and error handler doubles report what happened in an X-Fixture-Report header
 * (JSON), so responses without a body (HEAD, 204) carry a report too.
 *
 * Query parameters (the router's settings; Router ignores the query string):
 * - ci=1            Sets http.router_case_insensitive.
 * - root=<url>      Defines CITOMNI_PUBLIC_ROOT_URL for this request.
 * - script=<path>   SCRIPT_NAME as the front controller sees it; default /index.php.
 *                   The built-in server reports the request path instead.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

\define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);
require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Service/Router'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

function report(array $data): void {
	\header('X-Fixture-Report: ' . \json_encode($data, \JSON_THROW_ON_ERROR));
}

/** Controller double: reports the action, its arguments and the route hints it was built with. */
final class ProbeController {
	public function __construct(private object $app, private array $options = []) {}

	public function index(string ...$params): void {
		$this->reportCall('index', $params);
	}

	public function show(string ...$params): void {
		$this->reportCall('show', $params);
	}

	public function submit(string ...$params): void {
		$this->reportCall('submit', $params);
	}

	private function reportCall(string $action, array $params): void {
		report(['action' => $action, 'params' => $params, 'options' => $this->options, 'method' => $_SERVER['REQUEST_METHOD']]);
	}
}

/** ErrorHandler double: sets the status and reports the error instead of rendering it. */
final class ErrorHandlerProbe {
	public function httpError(int $status, array $context = []): void {
		\http_response_code($status);
		report(['error' => $status, 'reason' => $context['reason'] ?? null, 'allowed' => $context['allowed'] ?? null]);
	}
}

const ROUTES = [
	'/' => ['controller' => ProbeController::class, 'action' => 'index'],
	'/read' => [
		'controller'     => ProbeController::class,
		'action'         => 'index',
		'methods'        => ['get'],
		'template_file'  => 'public/read.html',
		'template_layer' => 'app',
	],
	'/form' => ['controller' => ProbeController::class, 'action' => 'submit', 'methods' => ['POST']],
	'/Mixed/Case' => ['controller' => ProbeController::class, 'action' => 'index'],
	'/missing-controller' => ['controller' => __NAMESPACE__ . '\\NoSuchController', 'action' => 'index'],
	'/missing-action' => ['controller' => ProbeController::class, 'action' => 'nope'],
	'regex' => [
		'/user/{id}'        => ['controller' => ProbeController::class, 'action' => 'show'],
		'/post/{slug}'      => ['controller' => ProbeController::class, 'action' => 'show'],
		'/mail/{email}'     => ['controller' => ProbeController::class, 'action' => 'show'],
		'/file/{name}'      => ['controller' => ProbeController::class, 'action' => 'show'],
		'/code/{code}/{id}' => ['controller' => ProbeController::class, 'action' => 'show'],
		'/Docs/{slug}'      => ['controller' => ProbeController::class, 'action' => 'show'],
	],
];

$_SERVER['SCRIPT_NAME'] = (string)($_GET['script'] ?? '/index.php');
if (isset($_GET['root'])) {
	\define('CITOMNI_PUBLIC_ROOT_URL', (string)$_GET['root']);
}

$app = new App(['http' => mergeLastWins(Registry::CFG_HTTP['http'], ['router_case_insensitive' => ($_GET['ci'] ?? '') === '1'])], ROUTES);
$app->set('errorHandler', new ErrorHandlerProbe());
(new Router($app))->run();
