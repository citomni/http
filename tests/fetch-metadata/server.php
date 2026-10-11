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

namespace {
	if (PHP_SAPI !== 'cli-server') {
		throw new RuntimeException('Built-in web server router only.');
	}
	define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);
	define('CITOMNI_ENVIRONMENT', 'dev');
	$_SERVER['SCRIPT_NAME'] = (string)($_GET['script'] ?? '/index.php');
	require dirname(__DIR__) . '/support/doubles.php';
	foreach (['Boot/Registry', 'Util/RoutePath', 'Policy/FetchMetadataPolicy', 'Service/Request', 'Service/Router', 'Service/ErrorHandler', 'Kernel'] as $file) {
		require dirname(__DIR__, 2) . '/src/' . $file . '.php';
	}
	// Locale initialization is outside this suite; all tested HTTP classes are real.
	if (!class_exists(Locale::class)) {
		class Locale {}
	}
}

namespace CitOmni\Kernel {
	enum Mode { case HTTP; }
	final class Arr {
		public static function mergeAssocLastWins(array $base, array $overlay): array {
			return \CitOmni\Http\Tests\Support\mergeLastWins($base, $overlay);
		}
	}
	final class Runtime {
		public static function configure(Cfg $cfg): void {}
	}
	final class App {
		public Cfg $cfg;
		public array $routes;
		private array $services = [];

		public function __construct(string $configDir, Mode $mode) {
			$policy = [
				'enabled' => true,
				'allow_same_site' => isset($_GET['same_site']),
				'allow_missing' => !isset($_GET['strict_missing']),
				'exempt' => [
					'viewer' => ['path' => '/_pdf-viewer/assets', 'match' => 'prefix', 'methods' => ['GET', 'HEAD']],
					'callback' => ['path' => '/callback', 'match' => 'exact', 'methods' => ['POST']],
				],
			];
			if (isset($_GET['disable_viewer'])) {
				$policy['exempt']['viewer'] = false;
			}
			if (isset($_GET['annotated'])) {
				$policy['exempt']['viewer']['note'] = 'Annotations next to a complete rule are ignored.';
			}
			if (isset($_GET['everything_post'])) {
				$policy['exempt']['everything_post'] = ['path' => '/', 'match' => 'prefix', 'methods' => ['POST']];
			}
			if (isset($_GET['numeric_name'])) {
				$policy['exempt'][7] = ['path' => '/bad', 'match' => 'exact', 'methods' => ['GET']];
			}
			if (isset($_GET['bad_rule'])) {
				$policy['exempt']['invalid'] = match ($_GET['bad_rule']) {
					'fields' => ['path' => '/bad'],
					'query' => ['path' => '/bad?query', 'match' => 'exact', 'methods' => ['GET']],
					'backslash' => ['path' => '/bad\\path', 'match' => 'exact', 'methods' => ['GET']],
					'percent' => ['path' => '/%62ad', 'match' => 'exact', 'methods' => ['GET']],
					'dots' => ['path' => '/x/../bad', 'match' => 'prefix', 'methods' => ['GET']],
					'method' => ['path' => '/bad', 'match' => 'exact', 'methods' => ['get']],
					'empty_methods' => ['path' => '/bad', 'match' => 'exact', 'methods' => []],
					'match' => ['path' => '/bad', 'match' => 'regex', 'methods' => ['GET']],
					default => throw new \RuntimeException('Unknown fixture.'),
				};
			}
			if (isset($_GET['list'])) {
				$policy['exempt'] = \array_values($policy['exempt']);
			}
			if (isset($_GET['disabled'])) {
				$policy['enabled'] = false;
			}
			if (isset($_GET['bad_enabled'])) {
				$policy['enabled'] = 'false';
			}
			$baseline = \CitOmni\Http\Boot\Registry::CFG_HTTP;
			$overlay = [
				'http' => ['base_url' => (string)($_GET['root'] ?? 'https://example.test'), 'router_case_insensitive' => isset($_GET['ci'])],
				'security' => ['fetch_metadata' => $policy],
				'error_handler' => [
					'log' => ['path' => \CITOMNI_APP_PATH . '/logs'],
					'render' => ['detail' => ['level' => 1]],
					'templates' => ['html' => \dirname(__DIR__, 2) . '/templates/errors/error.php'],
				],
			];
			$data = \CitOmni\Http\Tests\Support\mergeLastWins($baseline, $overlay);
			if (isset($_GET['baseline'])) {
				$data['security']['fetch_metadata'] = $baseline['security']['fetch_metadata'];
			}
			$this->cfg = new Cfg($data);
			$action = ['controller' => \CitOmni\Http\Tests\FetchMetadata\ProbeController::class, 'action' => 'index', 'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']];
			$this->routes = [
				'/private' => $action,
				'/callback' => $action,
				'/callback/child' => $action,
				'/_pdf-viewer/other' => $action,
				'/_pdf-viewer/assets-evil' => $action,
				'/_pdf-viewer/assets' => $action,
				'regex' => ['/_pdf-viewer/assets/{file}' => $action],
			];
		}

		public function __get(string $id): object {
			if (isset($this->services[$id])) {
				return $this->services[$id];
			}
			if ($id === 'router') {
				\header('X-Fixture-Router: yes');
			}
			return $this->services[$id] = match ($id) {
				'request' => new \CitOmni\Http\Service\Request($this),
				'router' => new \CitOmni\Http\Service\Router($this),
				'errorHandler' => isset($_GET['returning_handler'])
					? new \CitOmni\Http\Tests\FetchMetadata\ReturningErrorHandler($this)
					: new \CitOmni\Http\Service\ErrorHandler($this),
				'maintenance' => new class {
					public function guard(): void { \header('X-Fixture-Maintenance: yes'); }
				},
				default => throw new \RuntimeException('Unexpected service access: ' . $id),
			};
		}
	}
}

namespace CitOmni\Http\Tests\FetchMetadata {
	final class ProbeController {
		public function __construct(object $app, array $config) {
			\header('X-Fixture-Controller: yes');
		}
		public function index(string ...$params): void {
			\header('Content-Type: application/json');
			\header('Cache-Control: public, max-age=60');
			echo \json_encode(['params' => $params, 'post' => $_POST, 'body' => \file_get_contents('php://input')], \JSON_THROW_ON_ERROR);
		}
	}
	final class ReturningErrorHandler {
		public function __construct(private object $app) {}
		public function install(): void {
			(new \CitOmni\Http\Service\ErrorHandler($this->app))->install();
		}
		public function httpError(int $status, array $context = []): void {
			\http_response_code($status);
			\header('X-Fixture-Error: ' . ($context['reason'] ?? 'unknown'));
		}
	}
}

namespace {
	// Existing values must survive the policy's Vary append.
	header('Vary: Accept-Encoding');
	CitOmni\Http\Kernel::run(CITOMNI_APP_PATH);
}
