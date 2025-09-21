<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Http\Service;

use CitOmni\Kernel\Service\BaseService;


/**
 * Router: Deterministic HTTP routing with subdir-safe path resolution and method policy.
 *
 * Responsibilities:
 * - Resolve the HTTP method and a normalized, subdir-safe request path.
 *   1) Prefer CITOMNI_PUBLIC_ROOT_URL to compute a base prefix.
 *   2) Fallback to SCRIPT_NAME derivation; strip trailing "/public".
 *   3) Decode percent-escapes after trimming; enforce ASCII-only path.
 * - Match routes deterministically:
 *   1) Exact map lookup: $routes["/path"] => route array.
 *   2) Regex map lookup via placeholder compilation (e.g., "/user/{id}").
 *   3) Fallback to 404.
 * - Dispatch controller actions with method constraints and negotiation:
 *   1) HEAD is auto-allowed when GET is allowed.
 *   2) OPTIONS always allowed for matched resources (sends 204 + Allow).
 *   3) 405 for disallowed methods (sends Allow).
 * - Provide robust error routing with a reentrancy guard:
 *   1) First error attempts configured error route or framework default.
 *   2) Nested errors fall back to a minimal plain-text renderer.
 *
 * Collaborators:
 * - $this->app->cfg     (read): Provides the merged routes array and toggles.
 * - Controllers (FQCN): Instantiated as new $controller($this->app, $options).
 * - PHP SAPI globals    (read): $_SERVER for method and URI.
 *
 * Route declarations (configuration shape):
 * - Exact routes:
 *   $cfg['routes']['/'] = [
 *   	'controller'     => \App\Http\Controller\HomeController::class,
 *   	'action'         => 'index',
 *   	'methods'        => ['GET'],        // optional; defaults to GET/HEAD/OPTIONS
 *   	'template_file'  => 'home.html',    // optional
 *   	'template_layer' => 'app/http'      // optional
 *   ];
 *
 * - Regex routes (with placeholders):
 *   $cfg['routes']['regex']['/user/{id}'] = [
 *   	'controller' => \App\Http\Controller\UserController::class,
 *   	'action'     => 'show',
 *   	'methods'    => ['GET']
 *   ];
 *
 * Placeholder rules (built-ins; unknown placeholders match a single segment):
 * - {id}    => [0-9]+
 * - {email} => [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+
 * - {slug}  => [a-zA-Z0-9-_]+
 * - {code}  => [a-zA-Z0-9]+
 *
 * Method policy:
 * - When 'methods' is provided, it is normalized and intersected with:
 *   [GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS].
 * - GET implies HEAD; OPTIONS is always added for negotiation.
 * - If 'methods' is absent/empty, the default is GET/HEAD/OPTIONS.
 *
 * Error handling:
 * - 404: No route matched (exact or regex).
 * - 405: Route matched but the method is not allowed (sends Allow header).
 * - 500: Missing controller class or missing action method.
 * - Error dispatch uses a reentrancy guard; on nested failure a minimal plain
 *   response is emitted with the original status code.
 *
 * Performance & determinism:
 * - Exact-match fast path before regex evaluation.
 * - Placeholder compilation is done per request evaluation; patterns are anchored (#^...$#).
 * - No reflection scans or namespace guessing; controllers must be FQCN strings.
 *
 * Typical usage:
 *   // Front controller (public/index.php)
 *   // define('CITOMNI_ENVIRONMENT', 'prod');
 *   // define('CITOMNI_PUBLIC_PATH', __DIR__);
 *   // define('CITOMNI_APP_PATH', \dirname(__DIR__));
 *   // require CITOMNI_APP_PATH . '/vendor/autoload.php';
 *   // $app = new \CitOmni\Kernel\App(...);
 *   $app->router->run(); // resolves path, matches route, dispatches controller
 *
 * Examples:
 *   // Exact route to a static page
 *   $cfg['routes']['/about'] = [
 *   	'controller' => \App\Http\Controller\PageController::class,
 *   	'action'     => 'about',
 *   	'methods'    => ['GET'],
 *		'template_file' => 'public/about.html',
 *  	'template_layer' => 'citomni/http'
 *   ];
 *
 *   // Regex route with a slug and explicit method set
 *   $cfg['routes']['regex']['/blog/{slug}'] = [
 *   	'controller' => \App\Http\Controller\BlogController::class,
 *   	'action'     => 'show',
 *   	'methods'    => ['GET']
 *		'template_file' => 'public/blogpost.html',
 *  	'template_layer' => 'citomni/blog'
 *   ];
 *
 * Failure:
 *   // Method not allowed
 *   // Request: POST /about    Route allows only GET
 *   // Result: 405 with "Allow: GET, HEAD, OPTIONS" and error dispatch.
 *
 * Notes:
 * - Ensure routes live under the merged config key 'routes' (last-wins layering).
 * - Controllers are constructed with ($app, ['template_file' => ..., 'template_layer' => ...]).
 * - ASCII-only guard is defense-in-depth; non-ASCII paths get a 404.
 */
class Router extends BaseService {

	/** Validation rules for dynamic placeholders like {id}, {email}, etc. */
	private const PARAM_RULES = [
		'id'    => '[0-9]+',
		'email' => '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+',
		'slug'  => '[a-zA-Z0-9-_]+',
		'code'  => '[a-zA-Z0-9]+',
	];
	

	/**
	 * Entry point. Call from your front controller: $app->router->run();
	 *
	 * Steps:
	 *  1) Resolve HTTP method and subdir-safe path
	 *  2) Try exact route; then regex routes
	 *  3) Fallback to 404
	 */
	public function run(): void {

		// 1) Resolve method & path (subdir-safe)
		$method = \strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

		// Extract path portion (before '?'), default to '/'
		$uri = \strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

		// --- Determine base prefix (without trailing slash) ---
		// 1.1) Prefer CITOMNI_PUBLIC_ROOT_URL (consistent across setups)
		$basePrefix = '';
		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$parsed = \parse_url(\CITOMNI_PUBLIC_ROOT_URL);
			$basePrefix = isset($parsed['path']) ? \rtrim((string)$parsed['path'], '/') : '';
		}

		// 1.2) Fallback: derive from SCRIPT_NAME, but strip trailing "/public"
		if ($basePrefix === '') {
			$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php'));
			$basePrefix = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');
			if ($basePrefix !== '' && \substr($basePrefix, -7) === '/public') {
				$basePrefix = \substr($basePrefix, 0, -7);
				$basePrefix = ($basePrefix === '') ? '' : \rtrim($basePrefix, '/');
			}
		}

		// 1.3) Apply base prefix to the request URI
		if ($basePrefix !== '' && \strncmp($uri, $basePrefix, \strlen($basePrefix)) === 0) {
			$uri = \substr($uri, \strlen($basePrefix));
		}

		// Decode percent-escapes after subdir trimming
		$uri = \rawurldecode($uri);


		// 1a) ASCII-only guard (defense-in-depth)
		if (\preg_match('/[^\x00-\x7F]/', $uri)) {
			\http_response_code(404);
			$this->dispatchError(404);
			return;
		}

		// 1b) Normalize trailing slash (treat '/path/' as '/path')
		$uri = \rtrim($uri, '/') ?: '/';

		// 2) Load routes (absent -> empty)
		// $routes = \array_key_exists('routes', $this->app->cfg->toArray())
			// ? (array)$this->app->cfg->toArray()['routes']
			// : [];
		// $routes = $this->app->cfg->routes ?? [];
		// if (!\is_array($routes)) {
			// $routes = []; // Defensively (if anybody sabotaged cfg)
		// }		
		$routes = $this->app->cfg->routes ?? null;
		if (!\is_array($routes)) {
			throw new \RuntimeException('Config node "routes" must be an array.');
		}
		

		// 3) Exact match fast-path
		if (isset($routes[$uri]) && \is_array($routes[$uri])) {
			$this->dispatch($routes[$uri], $method);
			return;
		}

		// 4) Regex routes (placeholder patterns like /user/{id})
		if (isset($routes['regex']) && \is_array($routes['regex'])) {
			foreach ($routes['regex'] as $path => $route) {
				$pattern = self::toRegex((string)$path);
				if (\preg_match($pattern, $uri, $matches)) {
					\array_shift($matches); // drop full match, keep captures
					$this->dispatch((array)$route, $method, $matches);
					return;
				}
			}
		}

		// 5) 404 when no routes matched
		\http_response_code(404);
		$this->dispatchError(404);
	}


	/**
	 * Compile a placeholder path (e.g., "/user/{id}") into a safe regex.
	 *
	 * Behavior:
	 * - All literal characters are escaped via preg_quote().
	 * - Placeholders ({id}, {slug}, etc.) are substituted with pre-defined
	 *   PARAM_RULES (defaults to [^/]+ if unknown).
	 * - The resulting pattern is anchored (#^...$#).
	 *
	 * Notes:
	 * - This approach prevents '.' or other regex metacharacters in route
	 *   strings from being interpreted as special; they match literally.
	 * - Each placeholder is temporarily tokenized before escaping,
	 *   then replaced back into the escaped string.
	 *
	 * @param string $path Route path with optional {placeholders}.
	 * @return string Compiled PCRE pattern, anchored and delimited with '#'.
	 */
	private static function toRegex(string $path): string {
		$tokens = [];

		// 1) Replace placeholders with unique tokens and remember rules
		$tokenized = \preg_replace_callback(
			'/\{(\w+)\}/',
			static function (array $m) use (&$tokens): string {
				// Unique marker to survive preg_quote
				$token = '%PH_' . \strtoupper($m[1]) . '_' . \bin2hex(\random_bytes(2)) . '%';
				$tokens[$token] = self::PARAM_RULES[$m[1]] ?? '[^/]+';
				return $token;
			},
			$path
		);

		// 2) Escape all literal characters
		$quoted = \preg_quote($tokenized, '#');

		// 3) Replace back the tokens with regex capture groups
		foreach ($tokens as $token => $rule) {
			$quoted = \str_replace(\preg_quote($token, '#'), '(' . $rule . ')', $quoted);
		}

		// 4) Anchor pattern
		return '#^' . $quoted . '$#';
	}


	/**
	 * Controller dispatcher with HTTP method handling.
	 *
	 * Quality-of-life:
	 * - HEAD auto-allowed when GET is allowed (note: controller may still emit body).
	 * - OPTIONS always allowed for matched resources (204 + Allow).
	 * - 405 on disallowed methods (with Allow header).
	 *
	 * @param array<string,mixed> $route  Route definition (controller, action, methods?, ...)
	 * @param string $method               Resolved HTTP method (uppercased)
	 * @param list<mixed> $params          Positional params from regex captures
	 * @return void
	 */
	private function dispatch(array $route, string $method, array $params = []): void {
		
		// Normalize allowed methods (route-specified or default GET/HEAD/OPTIONS)
		$allowed = null;
		if (isset($route['methods'])) {
			$allowed = \array_map('strtoupper', (array)$route['methods']);
			$whitelist = ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'];
			$allowed = \array_values(\array_intersect($allowed, $whitelist));

			// Auto-HEAD when GET is allowed
			if (\in_array('GET', $allowed, true) && !\in_array('HEAD', $allowed, true)) {
				$allowed[] = 'HEAD';
			}
			// Always allow OPTIONS negotiation
			if (!\in_array('OPTIONS', $allowed, true)) {
				$allowed[] = 'OPTIONS';
			}

			// Deduplicate and keep a stable, conventional order
			$order = ['GET','HEAD','OPTIONS','POST','PUT','PATCH','DELETE'];
			$allowed = \array_values(\array_unique($allowed));
			\usort($allowed, static function ($a, $b) use ($order) {
				return \array_search($a, $order, true) <=> \array_search($b, $order, true);
			});

			// Empty list -> safe default
			if ($allowed === []) {
				$allowed = ['GET','HEAD','OPTIONS'];
			}
		} else {
			// Default: GET/HEAD/OPTIONS
			$allowed = ['GET','HEAD','OPTIONS'];
		}

		// OPTIONS negotiation (no body)
		if ($method === 'OPTIONS') {
			// $allowed is allways set (either from route or by default GET/HEAD/OPTIONS)
			\header('Allow: ' . \implode(', ', $allowed), true);
			\http_response_code(204);
			return;
		}

		// Guard disallowed methods (405 + Allow)
		if ($allowed && !\in_array($method, $allowed, true)) {
			\http_response_code(405);
			\header('Allow: ' . \implode(', ', $allowed), true);
			$this->dispatchError(405);
			return;
		}

		// Resolve controller/action
		$controller = $route['controller'] ?? null;
		$action = (string)($route['action'] ?? 'index');

		// Controller must exist (FQCN); otherwise treat as server error
		if ($controller === null || !\class_exists($controller)) {
			\http_response_code(500);
			$this->dispatchError(500);
			return;
		}

		// Instantiate controller with App and optional templating hints
		$controllerInstance = new $controller($this->app, [
			'template_file'  => $route['template_file']  ?? null,
			'template_layer' => $route['template_layer'] ?? null
		]);

		// Action must exist; warn for developers and route to 500
		if (!\method_exists($controllerInstance, $action)) {
			\trigger_error("Action '{$action}' not found in controller '{$controller}'.", \E_USER_WARNING);
			\http_response_code(500);
			$this->dispatchError(500);
			return;
		}

		// Dispatch action with positional regex captures
		\call_user_func_array([$controllerInstance, $action], $params);

	}
	

	/**
	 * Dispatch an error route safely.
	 *
	 * Uses a simple reentrancy guard so that if the error route throws,
	 * we fall back to a plain-text minimal error for the original code.
	 *
	 * @param int $code   HTTP status code to render
	 * @param list<mixed> $params Optional params for the error action
	 * @return void
	 */
	private function dispatchError(int $code, array $params = []): void {
		static $errorDepth = 0;   // int
		static $firstCode = null; // int|null

		if ($errorDepth === 0) {
			$firstCode = $code; // remember the original code
		}
		$errorDepth++;

		// If we are already handling an error, do a minimal plain fallback
		if ($errorDepth > 1) {
			$this->renderPlainError($firstCode ?? $code);
			$errorDepth = 0;
			$firstCode = null;
			return;
		}

		$route = $this->errorRoute($code);
		$this->dispatch($route, 'GET', $route['params'] ?? $params);

		// Reset guard state after a single attempt
		$errorDepth = 0;
		$firstCode = null;
	}


	/**
	 * Resolve error route from configuration or use a framework default.
	 *
	 * @param int $code HTTP status code (e.g., 404, 500)
	 * @return array<string,mixed> A route array suitable for dispatch()
	 */
	private function errorRoute(int $code): array {
		// $cfgArr = $this->app->cfg->toArray();
		// if (isset($cfgArr['routes'][$code]) && \is_array($cfgArr['routes'][$code])) {
			// return $cfgArr['routes'][$code];
		// }
		$routes = $this->app->cfg->routes ?? null;
		if (\is_array($routes) && isset($routes[$code]) && \is_array($routes[$code])) {
			return $routes[$code];
		}

		// Framework default error page (adjust to your public controller if needed)
		return [
			'controller'     => \CitOmni\Http\Controller\PublicController::class,
			'action'         => 'errorPage',
			'methods'        => ['GET'],
			'template_file'  => 'errors/error_default.html',
			'template_layer' => 'citomni/http',
			'params'         => [$code]
		];
	}


	/**
	 * Minimal last-resort error output (no templating, no controllers).
	 *
	 * @param int $code HTTP status code to render
	 * @return void
	 */
	private function renderPlainError(int $code): void {
		\http_response_code($code);
		\header('Content-Type: text/plain; charset=UTF-8');
		echo "HTTP {$code}\n";
	}
}
