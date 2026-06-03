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
 * HTTP CONFIG OVERLAY (stage)
 * ------------------------------------------------------------------
 * This file overrides /config/citomni_http_cfg.php for the "stage" environment.
 *
 * Merge model (last wins):
 *   vendor baseline -> providers -> app base -> this file (stage overlay)
 *   See: \CitOmni\Http\Boot\Config::CFG and classes listed in /config/providers.php
 *
 * Typical usage:
 *   - Set an absolute http.base_url (required in stage/prod unless the constant
 *     CITOMNI_PUBLIC_ROOT_URL is defined at bootstrap).
 *   - Mirror production posture (routing, cookies, sessions, caching) without
 *     exposing developer details to clients.
 *   - Keep logs verbose enough for QA, but never leak internals to the browser.
 *
 * Policy:
 *   - Only put keys here when you intend to *override* vendor/provider defaults.
 *     Keep overlays minimal and intentional; do not mirror the full baseline.
 *   - Prefer explicit values over auto-detection for security- and routing-critical
 *     settings (e.g., base_url, proxy trust).
 *   - /appinfo.html is available in dev and stage and shows the *merged* runtime
 *     configuration. Use it to confirm effective values before deploy.
 *
 * Quick tips — how to find and copy the exact config for an override:
 *   1) Open /appinfo.html (dev/stage) to view the merged config and routes.
 *   2) Find the key you need (e.g., "cookie.secure" or "error_handler.log.path").
 *   3) Copy the rendered array structure and paste it here, preserving PHP syntax
 *      and using CITOMNI_APP_PATH for portable paths.
 *   4) Save and warm config cache (App::warmCache() or your deploy step) so the
 *      kernel loads this overlay deterministically.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "stage" for this file to load.
 *   - Keep base_url absolute, no trailing slash (e.g., "https://stage.example.com").
 *   - If you define CITOMNI_PUBLIC_ROOT_URL earlier, the kernel will use it verbatim.
 *   - Stage should not display developer traces to clients; rely on logs instead.
 *
 * @internal App-owned overlay: small, deliberate, CI-friendly.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------
	 * Stage should use explicit, deterministic routing.
	 * Enable trust_proxy only if your staging sits behind a proxy/LB.
	 */
	'http' => [
		'base_url' => '%STAGE_ROOT_URL%',  // Replace %STAGE_ROOT_URL% with your stage-url, i.e. https://stage.example.com <- REMEMBER: No trailing slash!
		// 'trust_proxy' => true,
		// 'trusted_proxies' => ['10.0.0.0/8','192.168.0.0/16'],
	],


	/*
	 * ------------------------------------------------------------------
	 * COOKIE
	 * ------------------------------------------------------------------
	 * Mirror production cookie posture to surface issues early.
	 */
	'cookie' => [
		'secure'   => true,  // HTTPS-only on staging
		'httponly' => true,
		'samesite' => 'Lax',
		'path'     => '/',
	],


	/*
	 * ------------------------------------------------------------------
	 * SESSION
	 * ------------------------------------------------------------------
	 * Keep session policy aligned with production for realistic QA.
	 */
	'session' => [
		'save_path'       => \CITOMNI_APP_PATH . '/var/state/php_sessions',
		'cookie_secure'   => true,
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
	],


	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * Do not show dev details to clients on stage; keep logs verbose.
	 */
	'error_handler' => [
		'render' => [
			'trigger' => 0,          // no non-fatal render to clients
			'detail'  => ['level' => 0],
			// no force_error_reporting override on stage
		],
		'log' => [
			'trigger'    => E_ALL,   // keep logs informative for QA
			'path'       => \CITOMNI_APP_PATH . '/var/logs',
			'max_bytes'  => 2_000_000,
			'max_files'  => 10,
		],
		'templates' => [
			'html'          => \CITOMNI_APP_PATH . '/templates/error/error.php',
			'html_failsafe' => null,
		],
	],


	/*
	 * ------------------------------------------------------------------
	 * VIEW
	 * ------------------------------------------------------------------
	 * Cache templates to approximate production performance.
	 */
	'view' => [
		'cache_enabled' => true,
	],


	/*
	 * ------------------------------------------------------------------
	 * WEBHOOKS
	 * ------------------------------------------------------------------
	 * Keep admin webhooks disabled unless actively used.
	 * Secrets do NOT live in cfg. The HMAC secret is loaded from:
	 *   CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
	 * Baseline keys live in \CitOmni\Http\Boot\Config::CFG - override here only if needed.
	 */
	/*
	'webhooks' => [
		// Master switch. Keep disabled unless actively used.
		'enabled' => true,

		// Optional allow-list of source IPs (exact or IPv4 CIDR). 
		// Empty list = no IP restriction. Non-empty = request IP must match one of the listed entries.
		// Useful for whitelisting fixed build/deploy agents or CI runners.
		// 'allowed_ips' => [
			// '186.221.2.10',	// Example: Fixed outbound IP of your DevKit or CI server.
		// ],
	],
	*/
	
	
];
