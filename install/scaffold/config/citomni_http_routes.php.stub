<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (C) 2012-present Lars Grove Mortensen
 *
 * CitOmni HTTP Skeleton - Minimal starter for high-performance CitOmni HTTP apps.
 * Source:  https://github.com/citomni/http-skeleton
 * License: See the LICENSE file for full terms.
 */

/*
 * ------------------------------------------------------------------
 * ROUTE TABLE (BASE LAYER) (HTTP)
 * ------------------------------------------------------------------
 * This file defines HTTP-facing routes for your CitOmni app.
 *
 * RESPONSIBILITY:
 *   - citomni_http_cfg.php    is for config (identity, locale, http.base_url, etc.).
 *   - citomni_http_routes.php is for routes.
 *
 *   The array returned by this file is merged into $this->app->routes at boot.
 *   The Router uses $this->app->routes to dispatch incoming HTTP requests.
 *
 * MERGE MODEL (deterministic "last wins" per route key):
 *   1) Vendor baseline:
 *        \CitOmni\Http\Boot\Routes::MAP_HTTP
 *        (if defined)
 *
 *   2) Provider routes:
 *        Each provider in /config/providers.php may define
 *        public const ROUTES_HTTP = [ ... ];
 *        Providers are processed in the order they appear
 *        in providers.php.
 *
 *   3) /config/citomni_http_routes.php
 *        (this file)
 *
 *   4) /config/citomni_http_routes.{ENV}.php
 *        merged last, where {ENV} = CITOMNI_ENVIRONMENT
 *        ('dev' | 'stage' | 'prod')
 *
 * IMPORTANT:
 *   - "Last wins per key" means: if multiple layers define the same
 *     literal path (e.g. '/login') or the same error code (e.g. 404),
 *     the later layer overrides the earlier one for that key only.
 *
 *   - EMPTY ARRAYS ARE SKIPPED:
 *     If this file returns [], the app does NOT treat that as
 *     "wipe all routes". It simply means "no overrides from app base".
 *     Same for providers with ROUTES_HTTP = [] and for the {ENV} file.
 *
 * RUNTIME ACCESS:
 *   - After merge, the final route table lives on the App instance:
 *
 *         $this->app->routes['/']
 *         $this->app->routes['regex']
 *         $this->app->routes['404']
 *
 * MATCHING RULES:
 *
 *   1) Exact routes (top-level keys like '/' or '/legal/website-license')
 *      - Router tries these first.
 *
 *      Example:
 *        '/' => [
 *          'controller'     => \App\Http\Controller\AppController::class,
 *          'action'         => 'index',
 *          'methods'        => ['GET'], // HEAD auto-added; OPTIONS auto-handled
 *          'template_file'  => 'public/index.html',
 *          'template_layer' => 'app',
 *        ],
 *
 *   2) Regex / placeholder routes
 *      - Under the 'regex' key below.
 *      - Checked in array order.
 *      - Built-in placeholders:
 *           {id}    => [0-9]+
 *           {email} => [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+
 *           {slug}  => [a-zA-Z0-9-_]+
 *           {code}  => [a-zA-Z0-9]+
 *
 *      Example:
 *        'regex' => [
 *          '/user/{id}' => [
 *            'controller'     => \App\Http\Controller\UserController::class,
 *            'action'         => 'show',
 *            'methods'        => ['GET'],
 *            'template_file'  => 'public/user.html',
 *            'template_layer' => 'app',
 *          ],
 *        ],
 *
 *   3) Error routes (optional)
 *      - Numeric keys like 404, 405, 500.
 *      - Router / ErrorHandler can render these on failure.
 *      - If you omit them, CitOmni will fall back to safe defaults.
 *
 * METHODS / ALLOWED VERBS:
 *   - 'methods' => ['GET','POST',...]
 *   - GET implies HEAD (automatically allowed).
 *   - OPTIONS is auto-handled (router responds with Allow: ...).
 *   - If request method is not allowed, router returns 405.
 *
 * TEMPLATING HINTS FOR CONTROLLERS:
 *   - 'template_file'  => 'public/foo.html'
 *   - 'template_layer' => 'app'
 *   These are injected into the controller so it can forward them to
 *   TemplateEngine / View without hardcoding layout knowledge everywhere.
 *
 * DEV-ONLY ROUTES:
 *   - Put debug/admin-only stuff into /config/citomni_http_routes.dev.php
 *     (or .stage.php, etc.). That file is merged LAST, but only if it:
 *       a) exists, and
 *       b) returns a non-empty array.
 *
 *     Typical examples:
 *       '/appinfo.html'    (dumps cfg/services/routes for dev)
 *       '/cache/warm'      (force $this->app->warmCache() in dev)
 *     This avoids shipping debug endpoints to prod by accident.
 */
return [


	// -----------------------------------------------------------------
	// Exact routes (match first)
	// -----------------------------------------------------------------

	/* 
	'/' => [
		'controller'     => \App\Http\Controller\AppController::class,
		'action'         => 'index',
		'methods'        => ['GET'], // HEAD auto-added; OPTIONS handled automatically
		'template_file'  => 'public/index.html',
		'template_layer' => 'app',
		// 'params'      => [], // optional, passed positionally to the action
	],
	*/

	// '/helloworld.html' => [
		// 'controller' => \App\Http\Controller\AppController::class,
		// 'action' => 'helloworld',
		// 'methods' => ['GET'],
		// 'template_file' => 'public/helloworld.html',
		// 'template_layer' => 'app'
	// ],



	// -----------------------------------------------------------------
	// Regex / placeholder routes (evaluated in array order)
	// Placeholders supported only here. Built-ins: {id}, {email}, {slug}, {code}
	// -----------------------------------------------------------------

	/*
	'regex' => [
		// '/user/{id}' => [
		// 	'controller'     => \App\Http\Controller\UserController::class,
		// 	'action'         => 'show',
		// 	'methods'        => ['GET'],
		// 	'template_file'  => 'public/user.html',
		// 	'template_layer' => 'app/http',
		// ],

		// '/email/{email}' => [
		// 	'controller' => \App\Http\Controller\EmailController::class,
		// 	'action'     => 'validate',
		// 	'methods'    => ['GET'],
		// ],

		// '/post/{slug}' => [
		// 	'controller' => \App\Http\Controller\PostController::class,
		// 	'action'     => 'view',
		// 	'methods'    => ['GET'],
		// ],
	],
	*/


	// -----------------------------------------------------------------
	// Error routes (optional). If omitted, a sane default is used.
	// Router will fall back to a plain-text error if your error route fails,
	// because infinite error loops are funny exactly once.
	// -----------------------------------------------------------------

	/*
	403 => [
		'controller'     => \CitOmni\Http\Controller\PublicController::class,
		'action'         => 'errorPage',
		'methods'        => ['GET'],
		'template_file'  => 'errors/error_default.html',
		'template_layer' => 'citomni/http',
		'params'         => [403],
	],
	*/

	/*
	404 => [
		'controller'     => \CitOmni\Http\Controller\PublicController::class,
		'action'         => 'errorPage',
		'methods'        => ['GET'],
		'template_file'  => 'errors/error_default.html',
		'template_layer' => 'citomni/http',
		'params'         => [404],
	],
	*/

	/*
	405 => [
		'controller'     => \CitOmni\Http\Controller\PublicController::class,
		'action'         => 'errorPage',
		'methods'        => ['GET'],
		'template_file'  => 'errors/error_default.html',
		'template_layer' => 'citomni/http',
		'params'         => [405],
	],
	*/

	/*
	500 => [
		'controller'     => \CitOmni\Http\Controller\PublicController::class,
		'action'         => 'errorPage',
		'methods'        => ['GET'],
		'template_file'  => 'errors/error_default.html',
		'template_layer' => 'citomni/http',
		'params'         => [500],
	],
	*/

];
