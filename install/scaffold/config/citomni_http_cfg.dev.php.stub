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
 * HTTP CONFIG OVERLAY (dev)
 * ------------------------------------------------------------------
 * This file overrides /config/citomni_http_cfg.php for the "dev" environment.
 *
 * Merge model (last wins):
 *   vendor baseline -> providers -> app base -> this file (dev overlay)
 *   See: \CitOmni\Http\Boot\Config::CFG and classes listed in /config/providers.php
 *
 * Typical usage:
 *   - Allow auto-detected base URL during local development (no need to hardcode).
 *   - Enable developer-friendly error rendering for non-fatal levels.
 *   - Keep tracing on (bounded) and leave logging verbose.
 *   - Disable view caching to see template changes immediately.
 *
 * Policy:
 *   - Only place keys here when you intend to *override* vendor/provider defaults.
 *     Keep overlays minimal and intentional; do not mirror the full baseline.
 *   - Dev overlays may be more permissive for diagnostics, but should remain
 *     bounded and deterministic (no unbounded traces, no surprise side effects).
 *   - /appinfo.html is available in dev and shows the *merged* runtime config
 *     and route table. Use it to verify and to copy exact override syntax.
 *
 * Quick tips — how to find and copy the exact config for an override:
 *   1) Open /appinfo.html (dev). It renders the complete merged config and routes.
 *   2) Locate the key you need (e.g., "view.cache_enabled" or "mail.smtp.host").
 *   3) Copy the array structure as rendered and paste it here (preserve PHP syntax;
 *      prefer CITOMNI_APP_PATH for portable paths).
 *   4) Save and warm config cache if enabled (App::warmCache()) to lock results.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "dev" for this file to load.
 *   - You *may* define CITOMNI_PUBLIC_ROOT_URL for deterministic links on dev,
 *     but auto-detection is acceptable in development.
 *   - Keep secrets out of templates; pass only what views need.
 *
 * @internal App-owned overlay: small, deliberate, developer-focused.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------
	 * In dev we typically let the kernel auto-detect the base URL.
	 * Keep trust_proxy off unless you actually test behind a proxy.
	 */
	'http' => [
		// 'base_url' => 'https://dev.example.local', // optional; usually auto-detected in dev
		'trust_proxy' => false,
		// 'trusted_proxies' => ['127.0.0.1','::1'],
	],


	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * Show non-fatal errors to the developer with bounded traces.
	 * Do NOT include fatal classes in render.trigger; the handler
	 * will sanitize them away even if misconfigured.
	 */
	'error_handler' => [
		'render' => [
			'trigger' => E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING
				| E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR
				| E_DEPRECATED | E_USER_DEPRECATED,
			'detail' => [
				'level' => 1, // developer details (effective only when ENV === 'dev')
				'trace' => [
					'max_frames'      => 120,
					'max_arg_strlen'  => 512,
					'max_array_items' => 20,
					'max_depth'       => 3,
					'ellipsis'        => '...',
				],
			],
			// 'force_error_reporting' => E_ALL, // optional: override ini in dev
		],
		'log' => [
			// Keep logs verbose in dev; defaults are already sensible.
			// 'trigger'    => E_ALL,
			// 'path'       => \CITOMNI_APP_PATH . '/var/logs',
			// 'max_bytes'  => 2_000_000,
			// 'max_files'  => 10,
		],
	],


	/*
	 * ------------------------------------------------------------------
	 * VIEW
	 * ------------------------------------------------------------------
	 * Disable template caching in dev to see changes immediately.
	 */
	'view' => [
		'cache_enabled' => false,
	],


	/*
	 * ------------------------------------------------------------------
	 * COOKIE / SESSION
	 * ------------------------------------------------------------------
	 * On localhost over plain HTTP, cookie "secure" cannot be set to true.
	 * Leave these on auto/null in dev unless you always use HTTPS locally.
	 */
	'cookie' => [
		// 'secure'   => null, // auto-compute; leave unset to inherit baseline
		'httponly' => true,
		'samesite' => 'Lax',
		'path'     => '/',
	],

	'session' => [
		'save_path'       => \CITOMNI_APP_PATH . '/var/state/php_sessions',
		// 'cookie_secure'   => null, // auto in dev; set true only if dev over HTTPS
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
	],


	/*
	 * ------------------------------------------------------------------
	 * WEBHOOKS
	 * ------------------------------------------------------------------
	 * Usually off in dev unless explicitly testing admin flows.
	 */
	'webhooks' => [
		'enabled' => false,
	],
	
	
];
