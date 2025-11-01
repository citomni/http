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

namespace CitOmni\Http\Boot;

/**
 * Routes: Vendor baseline HTTP routes for CitOmni\Http.
 *
 * This is the first layer in the route merge chain used by \CitOmni\Kernel\App:
 *
 *   1) \CitOmni\Http\Boot\Routes::MAP_HTTP           (this constant, optional)
 *   2) Provider classes from /config/providers.php   (their ROUTES_HTTP const, optional)
 *   3) /config/citomni_http_routes.php               (app override, optional)
 *   4) /config/citomni_http_routes.{ENV}.php         (env override, optional)
 *
 * Merge semantics are "last wins" per associative key, done via
 * Arr::mergeAssocLastWins(). Providers are applied in the order they
 * appear in /config/providers.php. Empty arrays are skipped.
 *
 * VERY IMPORTANT:
 * - Do not try to expose these routes through Config::CFG anymore.
 *   Routes live on $app->routes, not $app->cfg.
 */
final class Routes {
	
	public const MAP_HTTP = [	
	
		'/' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'index',
			'methods' => ['GET'],
			'template_file' => 'public/index.html',
			'template_layer' => 'citomni/http'
		],
		'/legal/website-license' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'websiteLicense',
			'methods' => ['GET']
		],
		// '/legal/website-license' => [
			// 'controller' => \CitOmni\Http\Controller\PublicController::class,
			// 'action' => 'redirectWebsiteLicense',
			// 'methods' => ['GET']
		// ],
		'/legal/website-license/index.html' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'redirectWebsiteLicense',
			'methods' => ['GET']
		],
		
		
		// --- System/ops routes ---
		'/_system/ping' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'ping',
			'methods' => ['GET'],
		],
		'/_system/health' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'health',
			'methods' => ['GET'],
		],
		'/_system/version' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'version',
			'methods' => ['GET'],
		],
		'/_system/time' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'time',
			'methods' => ['GET'],
		],
		'/_system/clientip' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'clientIp',
			'methods' => ['GET'],
		],
		'/_system/request-echo' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'requestEcho',
			'methods' => ['GET'],
		],

		// Protected ops (HMAC via WebhooksAuth):
		'/_system/reset-cache' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'resetCache',
			'methods' => ['POST'],
		],
		'/_system/warmup-cache' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'warmupCache',
			'methods' => ['POST'],
		],
		'/_system/maintenance' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'maintenance',
			'methods' => ['GET'],
		],
		'/_system/maintenance/enable' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'maintenanceEnable',
			'methods' => ['POST'],
		],
		'/_system/maintenance/disable' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'maintenanceDisable',
			'methods' => ['POST'],
		],

		'/_system/_debug/webhook' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action'     => 'webhookDebug',
			'methods'    => ['POST'],
		],
		
		'/_system/appinfo.html' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'appinfoHtml',
			'methods' => ['GET'],
			'template_file' => 'public/appinfo.html',
			'template_layer' => 'citomni/http'
		],			
		'/_system/appinfo.json' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'appinfoJson',
			'methods' => ['GET'],
		],
		'/_system/appinfo-old.html' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'appinfo',
			'methods' => ['GET'],
			'template_file' => 'public/appinfo.html',
			'template_layer' => 'citomni/http'
		],
		
		// --- Regex routes (matches BEFORE top-level placeholders) ---
		'regex' => [
			// '/user/{id}' => [
				// 'controller' => 'CitOmni\\Http\\Controller\\UserController',
				// 'action' => 'getUser',
				// 'methods' => ['GET'],
				// 'template_file' => 'public/example.html',
				// 'template_layer' => 'citomni/http',
			// ],
			// '/email/{email}' => [
				// 'controller' => 'CitOmni\\Http\\Controller\\EmailController',
				// 'action' => 'validateEmail',
				// 'methods' => ['GET'],
				// 'template_file' => 'public/example.html',
				// 'template_layer' => 'citomni/http',
			// ],
			// '/slug/{urlslug}' => [
				// 'controller' => 'CitOmni\\Http\\Controller\\SlugController',
				// 'action' => 'getSlug',
				// 'methods' => ['GET'],
				// 'template_file' => 'public/example.html',
				// 'template_layer' => 'citomni/http',
			// ],
			// '/code/{code}' => [
				// 'controller' => 'CitOmni\\Http\\Controller\\CodeController',
				// 'action' => 'processCode',
				// 'methods' => ['POST'],
			// ],
		],		
		
	];
}
