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

			// Human-readable application name.
			// - Shown in HTML titles, error pages, or fallback legal pages.
			// - Keep it short and brand-like, e.g. "My CitOmni App".
			'app_name' => 'My CitOmni App',

			// Legal owner of the application and its content.
			// - Use the full legal entity name (company, org, or person).
			// - Displayed on legal pages like /legal/website-license.
			'owner_name' => 'ACME Ltd',

			// Contact address for legal/permissions requests.
			// - Typically a compliance, legal, or admin mailbox.
			// - Shown on the website-license page.
			'owner_email'=> 'legal@acme.com',

			// Public homepage of the legal owner.
			// - Used for attribution links in license/terms pages.
			// - Should be a stable corporate URL, not a product subpage.
			'owner_url'  => 'https://www.acme.com',

			// Public-facing support contact (user questions, helpdesk).
			// - This is what end-users see on "Contact us" pages or footers.
			// - Localized labels (see language/contact.php) decide if shown as
			//   "Support", "Helpdesk", "Customer Service", etc.
			'contact_email' => 'support@mycitomniapp.com',

			// Public phone number for end-user support.
			// - Only include if you actually staff the line.
			// - Format: international + local, human-readable.
			'contact_phone' => '(+45) 12 34 56 78',
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
		 * HTTP-SETTINGS (bootstrap policy)
		 *------------------------------------------------------------------
		 *
		 * CITOMNI_PUBLIC_ROOT_URL is defined by Kernel using this policy:
		 *   - DEV:
		 *       * If http.base_url is an absolute URL, use it.
		 *       * Else auto-detect from server vars (optionally proxy-aware).
		 *   - Non-DEV (stage/prod):
		 *       * Require an absolute http.base_url (no trailing slash).
		 *       * If missing/invalid -> RuntimeException (fail fast).
		 *
		 * trust_proxy:
		 *   - When true, auto-detection may honor X-Forwarded-* headers.
		 *   - Only enable when your reverse proxy / LB is trusted and listed
		 *     under http.trusted_proxies at the application layer.
		 *
		 * trusted_proxies:
		 *   - Consumed by the Request service (not by Kernel) to resolve
		 *     client IP/host safely behind trusted proxies.
		 */
		'http' => [
			// 'base_url'    => 'https://www.example.dk', // Never include a trailing slash! Non-DEV MUST override with an absolute URL (e.g., "https://www.example.com")
			'trust_proxy'     => false, // true only when behind a trusted reverse proxy/LB; enables honoring Forwarded/X-Forwarded-* for scheme/host.
			'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16', '::1'], // Optional whitelist of proxy IPs/CIDRs allowed to supply those headers. Empty = trust any proxy (not recommended). e.g., ['10.0.0.0/8', '192.168.0.0/16']
		],


		/*
		 *------------------------------------------------------------------
		 * ERROR HANDLER (HTTP)
		 *------------------------------------------------------------------
		 *
		 * Guarantees:
		 *   - Always logs (JSONL with size-based rotation).
		 *   - Always renders for:
		 *       * Uncaught exceptions,
		 *       * Shutdown fatals (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR),
		 *       * Router HTTP errors (404/405/5xx via httpError(...)).
		 *     These are NOT configurable (to prevent "blank pages").
		 *
		 * Rendering of non-fatal PHP errors (warnings/notices/etc.) is optional and generally
		 * only desirable in DEV. Therefore the baseline keeps it OFF; enable in your dev overlay.
		 *
		 * Templates:
		 *   - Optional: Plain-PHP files receiving $data with:
		 *       status, status_text, error_id, title, message, details*null, request_id, year
		 *   - If missing/unreadable, the handler falls back to a built-in minimal HTML page.
		 */
		'error_handler' => [

			'render' => [

				/*
				 * Which non-fatal PHP errors (bitmask) should trigger **rendering**?
				 * - 0 (baseline): do not render non-fatal errors (prod/stage-friendly).
				 * - DO NOT include fatal classes (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR).
				 *   The handler will sanitize those away even if misconfigured.
				 *
				 * Typical DEV overlay:
				 *   E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING
				 * | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR
				 * | E_DEPRECATED | E_USER_DEPRECATED
				 */
				'trigger' => 0,

				'detail' => [

					/*
					 * How much detail to show to the client?
					 * 0 = minimal client message (prod/stage).
					 * 1 = developer details (stack traces, structured context) – ONLY active when
					 *     CITOMNI_ENVIRONMENT === 'dev'. In non-dev envs this behaves as 0.
					 *
					 * Logs are always detailed regardless of this flag.
					 */
					'level' => 0,

					/*
					 * Trace formatting limits (apply only when detail.level = 1 AND we are in 'dev').
					 * Keep bounded to avoid huge responses; logs still carry full structured info.
					 */
					'trace' => [
						'max_frames'      => 120,   // Maximum number of frames included in traces.
						'max_arg_strlen'  => 512,   // Maximum characters shown per string argument.
						'max_array_items' => 20,    // Maximum array items per level.
						'max_depth'       => 3,     // Maximum recursion depth when dumping arrays/objects.
						'ellipsis'        => '...', // Ellipsis marker for truncated strings/arrays.
					],
				],

				/*
				 * Optional override of PHP error_reporting() at install time.
				 * - Leave unset/null to respect current runtime settings.
				 * - In dev overlay you typically set this to E_ALL.
				 */
				// 'force_error_reporting' => null,
			],

			'log' => [

				/*
				 * Which PHP errors (bitmask) should be **logged**?
				 * - Baseline: log everything (E_ALL). Logs are for developers/ops, not end users.
				 * - Router 404/405/5xx are always logged into separate files:
				 *   http_router_404.jsonl, http_router_405.jsonl, http_router_5xx.jsonl
				 */
				'trigger'   => E_ALL,

				/*
				 * Log directory (absolute). Files are JSONL with size-guarded rotation.
				 * Rotation strategy: sidecar lock + copy+truncate; rotated files are timestamped.
				 * Retention: see 'max_files' below (live file is never deleted by prune()).
				 */
				'path'      => \CITOMNI_APP_PATH . '/var/logs',

				/*
				 * Rotate before the next write would exceed this many bytes.
				 * Keep conservative to protect disk on shared hosts.
				 */
				'max_bytes' => 2_000_000, // ~2 MB

				/*
				 * Maximum number of rotated files to keep per base (live file excluded).
				 * Example: Keep last 10 rotations of http_err_exception.jsonl.*.jsonl
				 */
				'max_files' => 10,
			],

			'templates' => [

				/*
				 * Optional primary HTML template for error pages (plain PHP).
				 * If missing/unreadable, the handler tries 'html_failsafe', then falls back inline.
				 */
				'html'          => __DIR__ . '/../../templates/errors/error.php',

				/*
				 * Optional failsafe HTML template (plain PHP). Leave null to skip.
				 */
				'html_failsafe' => __DIR__ . '/../../templates/errors/error_failsafe.php',
			],

			'status_defaults' => [

				/*
				 * Default HTTP status codes used by the handler when a specific mapping is not set.
				 * - 'exception' and 'shutdown' are almost always 500.
				 * - 'php_error' applies when rendering non-fatal PHP errors (usually only in dev).
				 * - 'http_error' is a fallback; router typically passes explicit status (404/405/5xx).
				 */
				'exception' => 500,
				'shutdown'  => 500,
				'php_error' => 500,
				'http_error'=> 500,
			],
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
			'cache_enabled'			=> true,   // Cache enabled

			// Optimize HTML-output
			'trim_whitespace'		=> false, // Removes linebreaks and tabs
			'remove_html_comments'	=> false, // Removes HTML-comments from HTML-output
			
			'allow_php_tags'		=> true,

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
		 * ROUTES (deterministic map)
		 *------------------------------------------------------------------
		 * Define the routes for the URIs that should be met with a response
		 * The defined routes will be parsed by the dispatcher later on.
		 *
		 * Shape:
		 * - Exact: $routes['/path'] = [controller, action?, methods?, template_file?, template_layer?]
		 * - Regex: $routes['regex']['/user/{id}'] = [...]
		 *
		 * Placeholder rules (built-ins; unknown => [^/]+):
		 * {id} => [0-9]+
		 * {email} => [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+
		 * {slug} => [a-zA-Z0-9-_]+
		 * {code} => [a-zA-Z0-9]+
		 *
		 * Methods policy:
		 * - If omitted -> defaults to GET/HEAD/OPTIONS.
		 * - GET implies HEAD; OPTIONS is always allowed for negotiation (204 + Allow).
		 *
		 * Templates:
		 * - 'template_file' / 'template_layer' are passed to the controller (if it uses them).
		 * 
		 * Apps can point to their own routes and branded templates in their own config.
		 *
		 */
		'routes' => [
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
			'/appinfo.html' => [
				'controller' => \CitOmni\Http\Controller\PublicController::class,
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
		],

	];
}
