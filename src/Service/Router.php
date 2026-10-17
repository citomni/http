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

namespace CitOmni\Http\Service;

use CitOmni\Http\Util\RoutePath;
use CitOmni\Kernel\Service\BaseService;


/**
 * Router: Service that matches the request path to a route and dispatches its controller action.
 *
 * Turns the route table into at most one controller call per request, with method
 * negotiation. Building the route table belongs to App, resolving the path to RoutePath, and
 * logging and rendering every 404, 405 and 500 to ErrorHandler::httpError().
 *
 * Rules:
 * - Never write a response body. Answer every HTTP error through ErrorHandler::httpError().
 * - Resolve the path only through RoutePath::resolve(), so pre-dispatch path rules see the
 *   path that routing sees.
 * - Create a controller only from the FQCN in its route.
 */
class Router extends BaseService {

	/** Pattern of each built-in placeholder; any other placeholder matches one path segment. */
	private const PARAM_RULES = [
		'id'    => '[0-9]+',
		'email' => '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}',
		'slug'  => '[a-zA-Z0-9-_]+',
		'code'  => '[a-zA-Z0-9]+',
	];
	

	/**
	 * Match the current request to a route and dispatch it.
	 *
	 * Reads the route table from App::routes, which must have this shape:
	 * - Every top-level key except 'regex' is an exact path, with a leading slash and no
	 *   trailing slash ("/" for the front page), and maps to a route entry.
	 * - 'regex' maps placeholder paths, such as "/user/{id}", to route entries. Each
	 *   placeholder captures one value: {id}, {email}, {slug} and {code} by PARAM_RULES, any
	 *   other name one non-empty path segment. Every other character matches literally.
	 * - A route entry has controller (FQCN), and optionally action (method name, default
	 *   "index"), methods (list of HTTP methods), template_file and template_layer.
	 *
	 * Behavior:
	 * - Matches the path that RoutePath::resolve() returns for REQUEST_URI. With cfg
	 *   http.router_case_insensitive true, paths match in any letter case, and placeholder
	 *   captures arrive lowercased.
	 * - An exact route wins over placeholder routes. Placeholder routes are tried in table
	 *   order, and the first match wins.
	 * - 404 through httpError() when the path contains a non-ASCII byte or no route matches.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Router::dispatch()  What happens with the matched route.
	 * @see \CitOmni\Kernel\App  How the route table is merged.
	 */
	public function run(): void {

		$method = \strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

		// Share the exact path interpretation with pre-dispatch security checks.
		$caseInsensitive = (bool)$this->app->cfg->http->router_case_insensitive;
		$uri = RoutePath::resolve(
			(string)($_SERVER['REQUEST_URI'] ?? '/'),
			\defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : '',
			(string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php')),
			$caseInsensitive
		);

		// Defense in depth: A non-ASCII path is refused before any route is matched.
		if (\preg_match('/[^\x00-\x7F]/', $uri)) {
			$this->app->errorHandler->httpError(404, [
				'path'   => $uri,
				'method' => $method,
				'reason' => 'invalid_uri_non_ascii',
			]);
			return;
		}

		$routes = $this->app->routes ?? null;
		if (!\is_array($routes)) {
			throw new \RuntimeException('App routes must be an array.');
		}

		// RoutePath lowercased the path, so the exact keys are lowered to match. Placeholder
		// paths keep their case; toRegex() makes them case-insensitive instead.
		if ($caseInsensitive) {
			$lowerExact = [];
			foreach ($routes as $k => $v) {
				if ($k === 'regex') { continue; }
				if (\is_string($k)) {
					$lowerExact[\strtolower($k)] = $v;
				}
			}
			$routes = $lowerExact + ['regex' => ($routes['regex'] ?? [])];
		}		

		if (isset($routes[$uri]) && \is_array($routes[$uri])) {
			$this->dispatch($routes[$uri], $method);
			return;
		}

		if (isset($routes['regex']) && \is_array($routes['regex'])) {
			foreach ($routes['regex'] as $path => $route) {
				$pattern = self::toRegex((string)$path, $caseInsensitive);
				if (\preg_match($pattern, $uri, $matches)) {
					// Drop the full match; the captures are the action's arguments.
					\array_shift($matches);
					$this->dispatch((array)$route, $method, $matches);
					return;
				}
			}
		}

		$this->app->errorHandler->httpError(404, [
			'path'   => $uri,
			'method' => $method,
			'reason' => 'route_not_found',
		]);
		return;
	}


	/**
	 * Compile a placeholder path such as "/user/{id}" into an anchored pattern.
	 *
	 * Behavior:
	 * - Every character outside a placeholder matches literally, "." included.
	 * - Each {name} becomes one capture group with its PARAM_RULES pattern, or [^/]+ for an
	 *   unknown name. Braces around anything but letters, digits and underscores stay literal.
	 *
	 * Notes:
	 * - The pattern ends in "$" without the D modifier, so a path with one trailing newline,
	 *   such as a decoded "%0A", also matches.
	 *
	 * @param  string  $path             Route path with optional placeholders.
	 * @param  bool    $caseInsensitive  True adds the i modifier.
	 * @return string  PCRE pattern delimited with "#".
	 */
	private static function toRegex(string $path, bool $caseInsensitive = false): string {
		$tokens = [];

		// Placeholders become random markers first, so preg_quote() escapes only the literal
		// characters around them.
		$tokenized = \preg_replace_callback(
			'/\{(\w+)\}/',
			static function (array $m) use (&$tokens): string {
				$token = '%PH_' . \strtoupper($m[1]) . '_' . \bin2hex(\random_bytes(2)) . '%';
				$tokens[$token] = self::PARAM_RULES[$m[1]] ?? '[^/]+';
				return $token;
			},
			$path
		);

		$quoted = \preg_quote($tokenized, '#');

		foreach ($tokens as $token => $rule) {
			$quoted = \str_replace(\preg_quote($token, '#'), '(' . $rule . ')', $quoted);
		}

		return $caseInsensitive ? ('#^' . $quoted . '$#i') : ('#^' . $quoted . '$#');
	}


	/**
	 * Negotiate the method for one matched route and call its controller action.
	 *
	 * Turns a matched route into exactly one outcome: A negotiation answer, an error through
	 * the ErrorHandler, or the action call.
	 *
	 * Behavior:
	 * - Allowed methods are the route's methods, uppercased; names other than GET, POST, PUT,
	 *   PATCH, DELETE, HEAD and OPTIONS are dropped. GET adds HEAD, and OPTIONS is always
	 *   added, so a methods list without a known name allows OPTIONS only. Without methods,
	 *   or with null, they are GET, HEAD and OPTIONS. The Allow header lists them in the
	 *   order GET, HEAD, OPTIONS, POST, PUT, PATCH, DELETE.
	 * - OPTIONS: 204 with the Allow header and no body; no controller is created.
	 * - A method that is not allowed: 405 through httpError(), with the Allow header.
	 * - 500 through httpError() when the route names no controller or a class that does not
	 *   exist, or when the created controller has no method named by action. In the last case
	 *   the controller's init() has already run.
	 * - Otherwise calls the action with $params as positional arguments. The controller gets
	 *   the route's template_file and template_layer, null when absent.
	 *
	 * Notes:
	 * - HEAD runs the GET action. Whether a body is sent is left to the controller and the SAPI.
	 *
	 * @param  array<string,mixed>  $route   Route entry.
	 * @param  string               $method  Uppercase request method.
	 * @param  list<string>         $params  Placeholder captures, in path order.
	 * @return void
	 * @see \CitOmni\Kernel\Controller\BaseController::__construct()  What the controller receives.
	 */
	private function dispatch(array $route, string $method, array $params = []): void {
		
		$allowed = null;
		if (isset($route['methods'])) {
			$allowed = \array_map('strtoupper', (array)$route['methods']);
			$whitelist = ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'];
			$allowed = \array_values(\array_intersect($allowed, $whitelist));

			if (\in_array('GET', $allowed, true) && !\in_array('HEAD', $allowed, true)) {
				$allowed[] = 'HEAD';
			}
			if (!\in_array('OPTIONS', $allowed, true)) {
				$allowed[] = 'OPTIONS';
			}

			// A fixed order keeps the Allow header the same whatever order the route lists.
			$order = ['GET','HEAD','OPTIONS','POST','PUT','PATCH','DELETE'];
			$allowed = \array_values(\array_unique($allowed));
			\usort($allowed, static function ($a, $b) use ($order) {
				return \array_search($a, $order, true) <=> \array_search($b, $order, true);
			});

			if ($allowed === []) {
				$allowed = ['GET','HEAD','OPTIONS'];
			}
		} else {
			$allowed = ['GET','HEAD','OPTIONS'];
		}

		if ($method === 'OPTIONS') {
			\header('Allow: ' . \implode(', ', $allowed), true);
			\http_response_code(204);
			return;
		}

		if ($allowed && !\in_array($method, $allowed, true)) {
			
			// RFC 9110: A 405 response must include an Allow header listing the methods
			// permitted for the target resource.
			\header('Allow: ' . \implode(', ', $allowed), true);

			$this->app->errorHandler->httpError(405, [
				'method'  => $method,
				'allowed' => $allowed,
				'route'   => $route,
				'reason'  => 'method_not_allowed',
			]);
			return;
		}

		$controller = $route['controller'] ?? null;
		$action = (string)($route['action'] ?? 'index');

		if ($controller === null || !\class_exists($controller)) {
			$this->app->errorHandler->httpError(500, [
				'reason'     => 'controller_missing',
				'controller' => (string)$controller,
				'action'     => $action,
				'route'      => $route,
			]);
			return;
		}

		$controllerInstance = new $controller($this->app, [
			'template_file'  => $route['template_file']  ?? null,
			'template_layer' => $route['template_layer'] ?? null
		]);

		if (!\method_exists($controllerInstance, $action)) {
			$this->app->errorHandler->httpError(500, [
				'reason'     => 'action_missing',
				'controller' => $controller,
				'action'     => $action,
				'route'      => $route,
			]);
			return;
		}

		\call_user_func_array([$controllerInstance, $action], $params);

	}
	
}
