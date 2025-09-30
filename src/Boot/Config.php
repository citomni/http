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

namespace CitOmni\Http\Boot;


/**
 * Vendor baseline HTTP configuration for CitOmni.
 *
 * Behavior:
 * - Deterministic merge order ("last wins"):
 *   1) Vendor baseline: \CitOmni\Http\Boot\Config::CFG
 *   2) Providers: /config/providers.php (their CFG_HTTP blocks)
 *   3) App base: /config/citomni_http_cfg.php
 *   4) Env overlay: /config/citomni_http_cfg.{ENV}.php
 * - Exposes 'routes' as a raw array (router expects plain arrays).
 *
 * Notes:
 * - CITOMNI_APP_PATH must be defined early in public/index.php.
 * - Prefer absolute URLs for http.base_url in stage/prod.
 */
final class Config {
	public const CFG = [
	
		/*
		 *------------------------------------------------------------------
		 * APPLICATION IDENTITY
		 *------------------------------------------------------------------
		 */
		
		'identity' => [
			'app_name'	=> 'My CitOmni App',
			
			// Support contact (public-facing) NOTE: Additional contact-info is found in the language-file contact.php
			'email' 	=> 'support@citomni.com',
			'phone' 	=> '(+45) 12 34 56 77',
		],


		/*
		 *------------------------------------------------------------------
		 * LOCALE
		 *------------------------------------------------------------------
		 */
		 
		'locale' => [
			'language' => 'da',
			'timezone' => 'Europe/Copenhagen',
			'charset'  => 'UTF-8', // PHP default_charset + HTML output
		],		


		/*
		 *------------------------------------------------------------------
		 * HTTP-SETTINGS
		 *------------------------------------------------------------------
		 */
		'http' => [
			// 'base_url'    => 'https://www.example.dk', // Never include a trailing slash!
			'trust_proxy'     => false, // true only when behind a trusted reverse proxy/LB; enables honoring Forwarded/X-Forwarded-* for scheme/host.
			'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16', '::1'], // Optional whitelist of proxy IPs/CIDRs allowed to supply those headers. Empty = trust any proxy (not recommended).
		],


		/*
		 *------------------------------------------------------------------
		 * ERROR-HANDLER
		 *------------------------------------------------------------------
		 */
		'error_handler' => [
			'log_file' 			=> CITOMNI_APP_PATH . '/var/logs/system_error_log.json',
			'recipient' 		=> 'errors@citomni.com',
			// 'sender' 			=> '', // Leave empty to use cfg->mail->from->email (which most servers require)
			'max_log_size'		=> 10485760,
			'template'			=> __DIR__ . '/../../templates/errors/failsafe_error.php',  // Branded template for generic error page
			'display_errors'	=> false,
		],		


		/*
		 *------------------------------------------------------------------
		 * SESSION
		 *------------------------------------------------------------------
		 */
		
		'session' => [
			// Core
			'name'                    => 'CITSESSID',
			'save_path'               => CITOMNI_APP_PATH . '/var/state/php_sessions',
			'gc_maxlifetime'          => 1440,
			'use_strict_mode'         => true,
			'use_only_cookies'        => true,
			'lazy_write'              => true,
			'sid_length'              => 48,
			'sid_bits_per_character'  => 6,

			// Cookie flags
			'cookie_secure'           => null,      // dev: null (auto); stage/prod: set true
			'cookie_httponly'         => true,
			'cookie_samesite'         => 'Lax',     // 'Lax'|'Strict'|'None' (None requires Secure)
			'cookie_path'             => '/',
			'cookie_domain'           => null,

			// Optional hardening (all disabled by default for zero overhead)
			'rotate_interval'         => 0,         // e.g. 1800 to rotate every 30 min
			'fingerprint' => [
				'bind_user_agent'       => false,   // true to bind UA hash
				'bind_ip_octets'        => 0,       // IPv4: 0..4 leading octets
				'bind_ip_blocks'        => 0,       // IPv6: 0..8 leading blocks
			],
		],		


		/*
		 *------------------------------------------------------------------
		 * COOKIE
		 *------------------------------------------------------------------
		 */

		'cookie' => [
			// 'secure'   => true|false, // omit to auto-compute
			'httponly' => true,
			'samesite' => 'Lax',
			'path'     => '/',
			// 'domain' => 'example.com',
		],


		/*
		 *------------------------------------------------------------------
		 * SECURITY
		 *------------------------------------------------------------------
		 */
		'security' => [
			'csrf_protection'		=> true, // true | false; Prevent CSRF (Cross-Site Request Forgery) attacks.
			'csrf_field_name'		=> 'csrf_token',
			
			// Anti-bots
			'captcha_protection'	=> true, // true | false; The native captcha will help prevent bots from filling out forms.
			'honeypot_protection'	=> true, // true | false; Enables honeypot protection to prevent automated bot submissions.	
			'form_action_switching'	=> true, // true | false; Enables dynamic form action switching to prevent bot submissions.
		],

		
		/*
		 *------------------------------------------------------------------
		 * VIEW / CONTENT / TEMPLATE ENGINE
		 *------------------------------------------------------------------
		 */
		 
		'view' => [
		
			// Cache		
			'cache_enabled'			=> false,   // Cache enabled

			// Optimize HTML-output
			'trim_whitespace'		=> false, // Removes linebreaks and tabs
			'remove_html_comments'	=> false, // Removes HTML-comments from HTML-output
			
			'allow_php_tags'		=> false,

			// Marketing scripts (to be inserted in <HEAD> of templates)
			'marketing_scripts' 	=>	'',

			// Global variables for use in all templates.
			// Any values placed here will automatically be available in every template rendered by the framework.
			// Ideal for site-wide settings, company info, custom flags, or any data that needs to be accessible across all views.
			'view_vars' => [],		
		],


		/**
		 * ------------------------------------------------------------------
		 * MAINTENANCE FLAG
		 * ------------------------------------------------------------------
		 */
		 
		'maintenance' => [
			'flag' => [
				'path' => CITOMNI_APP_PATH . '/var/flags/maintenance.php', // Absolute filesystem path to the flag file. This file is atomically rewritten whenever maintenance mode is toggled.			
				'template' => __DIR__ . '/../../templates/public/maintenance.php',  // Branded template for maintenance mode guard page
				
				// Whitelist of client IPs allowed to bypass maintenance mode
				'allowed_ips' => [
					// '127.0.0.1',      // localhost
					// '192.168.1.100',  // example LAN IP
				],
				
				'default_retry_after' => 300, // Default number of seconds for the Retry-After header when the flag file does not provide a value. Should reflect the typical duration of short maintenance windows (e.g. 300–900 seconds).
			],
			// Controls lightweight rotation of generated maintenance flag files.
			'backup' => [			
				'enabled' => true,
				'keep' => 3, // number of versions to keep (e.g., 0..5)
				'dir' => CITOMNI_APP_PATH . '/var/backups/flags/'
			],
			'log' => [
				'filename' => 'maintenance.json',
			],
		],


		/*
		 *------------------------------------------------------------------
		 * ADMIN WEBHOOKS
		 *------------------------------------------------------------------
		 * Remote control for admin operations (maintenance, deploy, etc.).
		 * Protect with an IP allowlist and a strong shared secret.
		 * 
		 */
		
		'webhooks' => [
			'enabled' => true,  // Master kill-switch for all admin webhooks
			'ttl_seconds' => 300,  // Max allowed request age in seconds (rejects expired/replayed requests)
			'ttl_clock_skew_tolerance' => 60,  // Extra leeway for clock drift, in seconds
			'allowed_ips' => [],  // Optional allow-list of source IPs (empty = no restriction, or filled like ['203.0.113.10','198.51.100.7'])
			'nonce_dir' => CITOMNI_APP_PATH . '/var/nonces/' // Filesystem path for storing used nonces to prevent replay attacks
		],		


		/*
		 *------------------------------------------------------------------
		 * ROUTES
		 *------------------------------------------------------------------
		 * Define the routes for the URIs that should be met with a response
		 * The defined routes will be parsed by the dispatcher later on.
		 *
		 */

		'routes' => [
			'/' => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
				'action' => 'index',
				'methods' => ['GET'],
				'template_file' => 'public/status.html',
				'template_layer' => 'citomni/http'
			],


			403 => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
				'action' => 'errorPage',
				'methods' => ['GET'],
				'template_file' => 'public/status.html',
				'template_layer' => 'citomni/http',
				'params' => [403]
			],
			404 => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
				'action' => 'errorPage',
				'methods' => ['GET'],
				'template_file' => 'public/status.html',
				'template_layer' => 'citomni/http',
				'params' => [404]
			],
			405 => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
				'action' => 'errorPage',
				'methods' => ['GET'],
				'template_file' => 'public/status.html',
				'template_layer' => 'citomni/http',
				'params' => [405]
			],
			500 => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
				'action' => 'errorPage',
				'methods' => ['GET'],
				'template_file' => 'public/status.html',
				'template_layer' => 'citomni/http',
				'params' => [500]
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
		],

	];
}
