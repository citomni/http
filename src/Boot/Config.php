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
		 * HTTP SETTINGS (bootstrap policy & runtime toggles)
		 *------------------------------------------------------------------
		 *
		 * CITOMNI_PUBLIC_ROOT_URL / http.base_url
		 * - Base URL resolution is prioritized as:
		 *     1) CITOMNI_PUBLIC_ROOT_URL (constant; if defined and non-empty)
		 *     2) http.base_url (absolute URL, no trailing slash)
		 *     3) Best-effort computed from the current request (scheme/host/port)
		 * - Kernel policy:
		 *     DEV:
		 *       * If http.base_url is absolute, it is used.
		 *       * Otherwise Kernel auto-detects from server vars (optionally proxy-aware).
		 *     STAGE/PROD (non-DEV):
		 *       * Require an absolute http.base_url (no trailing slash).
		 *       * Missing/invalid -> RuntimeException (fail fast).
		 *
		 * trust_proxy (bool)
		 * - When true, Request may honor proxy headers for scheme/host/port/client IP,
		 *   but ONLY if the peer (REMOTE_ADDR) is trusted per http.trusted_proxies.
		 * - Keep false by default; enable only behind a trusted reverse proxy/LB.
		 *
		 * trusted_proxies (array of CIDR/IP)
		 * - Allowlist used by Request to decide whether to accept proxy-provided values.
		 * - The current peer (REMOTE_ADDR) must match one of these entries for
		 *   proxy headers to be considered.
		 * - IMPORTANT: An empty list means "trust NO proxies". There is no "trust all" mode.
		 * - Examples: ['10.0.0.0/8', '192.168.0.0/16', '::1']
		 *
		 * Proxy headers considered (when trust_proxy=true AND REMOTE_ADDR is trusted):
		 * - Scheme:   Forwarded: proto=..., X-Forwarded-Proto, X-Forwarded-SSL,
		 *             X-Forwarded-Scheme, Front-End-Https, X-URL-Scheme, CF-Visitor
		 * - Host:     X-Forwarded-Host (first), or Forwarded: host=... (first hop)
		 * - Port:     X-Forwarded-Port (>0), or parsed from Forwarded host token
		 * - Client IP: X-Forwarded-For (first public IP in the list); otherwise REMOTE_ADDR
		 *   (Private/reserved addresses are filtered out.)
		 *
		 * router_case_insensitive (bool)
		 * - When true, Router:
		 *     1) strips base prefix case-insensitively,
		 *     2) matches exact routes through a lowered key map,
		 *     3) compiles regex routes with the 'i' flag.
		 * - Intended for local Windows/XAMPP convenience. Default false (recommended
		 *   for STAGE/PROD). When false, paths are case-sensitive (conventional).
		 *
		 * Notes
		 * - Never include a trailing slash in http.base_url.
		 * - Prefer lowercase URL paths in your app to avoid cross-OS surprises.
		 * - Request enforces an ASCII-only guard for paths (defense in depth).
		 */
		'http' => [
			// 'base_url' => 'https://www.example.com', // Never include a trailing slash! Non-DEV MUST override with an absolute URL (e.g., "https://www.example.com")
			'trust_proxy'             => false,       // Enable only behind a trusted proxy/LB listed below.
			'trusted_proxies'         => ['10.0.0.0/8', '192.168.0.0/16', '::1'], // Empty list means; trust NO proxies.
			'router_case_insensitive' => false,       // Local dev convenience; keep false in STAGE/PROD.
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
		 * ADMIN WEBHOOKS (HMAC auth + replay protection)
		 *------------------------------------------------------------------
		 *
		 * Purpose
		 * - Remote control endpoints for admin operations (e.g., maintenance, deploy).
		 * - Auth model: HMAC over a canonical base string, TTL with clock-skew tolerance,
		 *   optional source IP allow-list, and a nonce ledger to prevent replays.
		 *
		 * Secrets & file policy (IMPORTANT)
		 * - DO NOT put secrets in cfg. The HMAC secret is loaded from a side-effect-free
		 *   PHP file that returns a plain array (see contract below).
		 * - Default location: CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
		 * - Commit only the template: /var/secrets/webhooks.secret.php.tpl
		 *   Never commit the real secret file to GitHub.
		 *
		 * Secret file contract (side-effect free; returns array):
		 *   return [
		 *     'secret' => '<hex>',           // REQUIRED: hex string; 64 chars for sha256, 128 for sha512
		 *     'algo'   => 'sha256'|'sha512', // OPTIONAL: used if cfg does not override 'algo'
		 *     // Optional metadata for ops visibility (ignored by verifier):
		 *     // 'rotated_at_utc' => '2025-10-17T11:12:00Z',
		 *     // 'generator'      => 'CitOmni DevKit',
		 *   ];
		 *
		 * Canonical signature base string (selected by 'bind_context'):
		 * - Simple mode (bind_context=false; default):
		 *     "<timestamp>.<nonce>.<rawBody>"
		 * - Context-bound mode (bind_context=true):
		 *     ts + "\n" + nonce + "\n" + METHOD + "\n" + PATH + "\n" + QUERY + "\n" + sha256(rawBody)
		 *   (Stronger request coupling at the cost of a stricter client.)
		 *
		 * Required headers (server keys as seen in $_SERVER; names configurable below):
		 * - X-Citomni-Timestamp : UNIX seconds when the signature was created.
		 * - X-Citomni-Nonce     : Unique, single-use identifier (replay-protected).
		 * - X-Citomni-Signature : Hex HMAC of the canonical base string.
		 *
		 * Guarantees
		 * - Deterministic verification (constant-time compare).
		 * - Replay protection via Nonce service (filesystem-backed).
		 * - Stale/future requests rejected based on TTL and clock-skew tolerance.
		 *
		 * Required when enabled:
		 * - 'secret_file' : absolute path to the secret file (see contract above).
		 * - 'nonce_dir'   : writable directory for nonce ledger (prevents replays).
		 *
		 * Notes
		 * - 'algo' may be set here or (optionally) in the secret file. If set in both,
		 *   cfg wins (explicit beats implicit).
		 * - 'allowed_ips' supports exact IPs and IPv4 CIDR (e.g., '203.0.113.0/24').
		 *   Empty means "no IP restriction". Prefer restricting in STAGE/PROD.
		 * - Keep 'enabled' = false by default. Enable only when actively used.
		 *
		 * Typical app overrides (env files):
		 *   'webhooks' => [
		 *     'enabled'   => true,
		 *     'allowed_ips' => ['203.0.113.10', '198.51.100.0/24'],
		 *     // Optionally tighten timing:
		 *     // 'ttl_seconds' => 180, 'ttl_clock_skew_tolerance' => 30,
		 *     // Optionally switch algo (must match secret length):
		 *     // 'algo' => 'sha512',
		 *   ]
		 */
		'webhooks' => [
			// Master switch. Keep disabled unless actively used.
			'enabled' => false,

			// Filesystem path to the secret file (side-effect free; returns array per contract).
			// Default path is safe to keep unless your app relocates secrets.
			'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',

			// Directory for the nonce ledger (replay protection). Must be writable.
			'nonce_dir' => CITOMNI_APP_PATH . '/var/nonces',

			// Freshness window and clock-skew tolerance (seconds).
			'ttl_seconds' => 300,
			'ttl_clock_skew_tolerance' => 60,

			// Optional allow-list of source IPs (exact or IPv4 CIDR). Empty = no IP restriction.
			'allowed_ips' => [
				// '203.0.113.10',
				// '198.51.100.0/24',
			],

			// HMAC algorithm. If omitted and present in the secret file, the file's value is used.
			// Allowed: 'sha256' (default) or 'sha512' (requires longer hex secret).
			'algo' => 'sha256',

			// Bind signature to METHOD + PATH + QUERY + body-hash for stronger coupling (client must mirror exact shape).
			'bind_context' => false,

			// Header keys as seen in $_SERVER (override only if your environment requires it).
			'header_signature' => 'HTTP_X_CITOMNI_SIGNATURE',
			'header_timestamp' => 'HTTP_X_CITOMNI_TIMESTAMP',
			'header_nonce' => 'HTTP_X_CITOMNI_NONCE',
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
			'/_system/trusted-proxies' => [
				'controller' => \CitOmni\Http\Controller\SystemController::class,
				'action' => 'trustedProxies',
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
		],

	];
}
