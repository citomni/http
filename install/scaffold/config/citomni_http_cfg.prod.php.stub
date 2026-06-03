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
 * HTTP CONFIG OVERLAY (prod)
 * ------------------------------------------------------------------
 * This file overrides /config/citomni_http_cfg.php for the "prod" environment.
 *
 * Merge model (last wins):
 *   vendor baseline -> providers -> app base -> this file (prod overlay)
 *   See: \CitOmni\Http\Boot\Config::CFG and classes listed in /config/providers.php
 *
 * Typical usage:
 *   - Set an absolute http.base_url (required in stage/prod unless the constant
 *     CITOMNI_PUBLIC_ROOT_URL is defined at bootstrap).
 *   - Disable developer niceties (display_errors off, stricter cookies, etc.).
 *   - Adjust transport and logging for production posture.
 *
 * Policy:
 *   - Only place keys here when you intend to *override* vendor/provider defaults.
 *     Do not replicate the full baseline; keep overlays minimal and intentional.
 *   - Production overlays should be deterministic and auditable: prefer explicit
 *     values over auto-detection for anything security- or routing-sensitive.
 *   - /appinfo.html is the authoritative, human-friendly view of the *merged*
 *     runtime configuration (available only in dev and stage). Use it to verify
 *     what will actually be in effect before deploying changes.
 *
 * Quick tips — how to find and copy the exact config for an override:
 *   1) Open /appinfo.html (dev/stage). It renders the complete merged config and routes.
 *   2) Locate the key you want to change (e.g. "error_handler.log.path" or "http.base_url").
 *   3) Copy the displayed array structure and paste it into this file, preserving
 *      PHP array syntax and paths (use CITOMNI_APP_PATH for portable paths).
 *   4) Save and warm config cache (App::warmCache() or your deploy step) so the
 *      kernel sees the overlay deterministically.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "prod" for this file to load.
 *   - Keep base_url absolute, no trailing slash (e.g. "https://www.example.com").
 *   - If you define CITOMNI_PUBLIC_ROOT_URL earlier, the kernel will use it verbatim.
 *   - Keep secrets out of templates; pass only what views need.
 *   - Avoid environment-specific debug flags here; use the dev overlay for diagnostics.
 *
 * @internal App-owned overlay: intended to be small, deliberate, and safe for CI/deploy.
 * ------------------------------------------------------------------
 */
return [


	/*
	 * ------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------
	 * Production must always define a deterministic base URL.
	 * - No trailing slash.
	 * - Never rely on auto-detection in stage/prod.
	 * - Enable trust_proxy only if behind a known reverse proxy or LB.
	 */
	'http' => [
		'base_url' => '%PROD_ROOT_URL%',  // Replace %PROD_ROOT_URL% with your prod-url, i.e. https://www.example.com <- REMEMBER: No trailing slash!
		// 'trust_proxy' => true,                 // Enable only when reverse-proxied
		// 'trusted_proxies' => ['10.0.0.0/8'],  // Optional IP/CIDR allow-list
	],


	/*
	 * ------------------------------------------------------------------
	 * COOKIE
	 * ------------------------------------------------------------------
	 * Secure defaults for production:
	 * - secure=true ensures HTTPS-only cookies.
	 * - httponly=true prevents JavaScript access.
	 * - samesite=Lax avoids most CSRF-style cross-origin requests
	 *   while still allowing normal top-level navigation.
	 */
	'cookie' => [
		'secure'   => true,   // Always HTTPS in production
		'httponly' => true,
		'samesite' => 'Lax',
		'path'     => '/',
		// 'domain' => 'example.com', // Only if sharing cookies across subdomains
	],


	/*
	 * ------------------------------------------------------------------
	 * SESSION
	 * ------------------------------------------------------------------
	 * Mirrors cookie policy for consistency.
	 * Explicitly mark cookies as secure when using HTTPS-only sites.
	 * The save_path uses CITOMNI_APP_PATH to ensure portability.
	 */
	'session' => [
		'save_path'       => \CITOMNI_APP_PATH . '/var/state/php_sessions',
		'cookie_secure'   => true,   // Explicit HTTPS-only session cookie
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
	],


	/*
	 * ------------------------------------------------------------------
	 * VIEW
	 * ------------------------------------------------------------------
	 * Template caching should be enabled in production for performance.
	 */
	'view' => [
		'cache_enabled'			=> true,   // Cache enabled
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
