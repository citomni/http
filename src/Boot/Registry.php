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

final class Registry {
	

	/**
	 * Vendor baseline service map for HTTP mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic HTTP service map merge:
	 *   1) Vendor baseline: \CitOmni\Http\Boot\Registry::MAP_HTTP
	 *   2) Providers: /config/providers.php (MAP_COMMON, then MAP_HTTP)
	 *   3) App common map: /config/services.php
	 *   4) App HTTP map: /config/services_http.php
	 * - Service merge semantics use PHP array union (`+`) with each later
	 *   source placed on the left, so later sources override earlier sources.
	 *
	 * Notes:
	 * - Service IDs are resolved via $this->app->{id}.
	 * - Services are instantiated lazily and kept as singletons per request/process.
	 * - Definitions must be either:
	 *   - 'id' => FQCN
	 *   - 'id' => ['class' => FQCN, 'options' => [...]]
	 * - /config/services_http.php has highest precedence in HTTP mode, followed by
	 *   /config/services.php, providers, and the vendor baseline.
	 */
	public const MAP_HTTP = [

		'errorHandler'	=> \CitOmni\Http\Service\ErrorHandler::class,
		'request'		=> \CitOmni\Http\Service\Request::class,
		'response'		=> \CitOmni\Http\Service\Response::class,
		'router'		=> \CitOmni\Http\Service\Router::class,
		'session'		=> \CitOmni\Http\Service\Session::class,
		'flash'			=> \CitOmni\Http\Service\Flash::class,
		'datetime'		=> \CitOmni\Http\Service\Datetime::class,
		'cookie'		=> \CitOmni\Http\Service\Cookie::class,
		// 'view'		=> \CitOmni\Http\Service\View::class,  // Replaced by TemplateEngine, but kept for now for back compat
		'tplEngine'		=> \CitOmni\Http\Service\TemplateEngine::class,
		'security'		=> \CitOmni\Http\Service\Security::class,   // Replaced by the newer CSRF-service, but kept for now for back compat
		'csrf'			=> \CitOmni\Http\Service\Csrf::class,
		'nonce'			=> \CitOmni\Http\Service\Nonce::class,
		'maintenance'	=> \CitOmni\Http\Service\Maintenance::class,
		'webhooksAuth'	=> \CitOmni\Http\Service\WebhooksAuth::class,
		'slugger'		=> \CitOmni\Http\Service\Slugger::class,
		'tags'			=> \CitOmni\Http\Service\Tags::class,
		'upload' 		=> \CitOmni\Http\Service\Upload::class,
		'icon'			=> \CitOmni\Http\Service\Icon::class,

	];






	/**
	 * Vendor baseline configuration for HTTP mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic HTTP config merge:
	 *   1) Vendor baseline: \CitOmni\Http\Boot\Registry::CFG_HTTP
	 *   2) Providers: /config/providers.php (CFG_COMMON, then CFG_HTTP)
	 *   3) App common: /config/citomni_cfg.php
	 *   4) App HTTP: /config/citomni_http_cfg.php
	 *   5) App common env: /config/citomni_cfg.{ENV}.php
	 *   6) App HTTP env: /config/citomni_http_cfg.{ENV}.php
	 * - Config merge semantics are deep associative merge with last wins.
	 *
	 * Notes:
	 * - The baseline must contain the stable top-level HTTP config tree expected by
	 *   CitOmni\Http services and controllers.
	 * - CITOMNI_APP_PATH must be defined before boot.
	 * - In stage/prod, prefer an explicit absolute http.base_url (or
	 *   CITOMNI_PUBLIC_ROOT_URL where that policy is used).
	 * - Providers may extend or override this baseline, and the application layer
	 *   remains the final authority.
	 */
	public const CFG_HTTP = [

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
		 *       language, status, status_text, error_id, title, message, details|null, request_id, year
		 *   - If missing/unreadable, the handler falls back to a built-in minimal HTML page.
		 */
		'error_handler' => [

			'render' => [

				/*
				 * Which non-fatal PHP errors (bitmask) should trigger **rendering**?
				 * - 0 (baseline): do not render non-fatal errors (prod/stage-friendly).
				 * - The active PHP error_reporting() mask is honored first.
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
					 * This flag gates client-facing detail only; logging is never gated by it.
					 * (Logged exception traces are still bounded by the 'trace' caps below.)
					 */
					'level' => 0,

					/*
					 * Trace formatting limits. These caps ALWAYS bound the trace written to the
					 * exception log (http_err_exception.jsonl), in every environment - they are the
					 * only control over logged trace depth/size. detail.level + 'dev' additionally
					 * gate whether the same bounded exception trace is exposed to the client; they
					 * do not widen the caps.
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
				 * Which non-fatal PHP errors (bitmask) should be **logged**?
				 * - Baseline: allow all active non-fatal PHP errors through the log mask (E_ALL).
				 * - The active PHP error_reporting() mask is honored first.
				 * - Exceptions, shutdown fatals and Router HTTP errors are logged independently
				 *   of this mask.
				 * - Router errors use separate files:
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
				 * A single record larger than this is written whole (an empty live file is not rotated).
				 * Keep conservative to protect disk on shared hosts.
				 */
				'max_bytes' => 2_000_000, // ~2 MB

				/*
				 * Maximum number of rotated files to keep per base (live file excluded).
				 * Example: Keep the last 10 http_err_exception.<timestamp>.jsonl rotations.
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
				 */
				'exception' => 500,
				'shutdown'  => 500,
				'php_error' => 500,
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
		
			'csrf' => [
				'enabled'                      => true,                              // Enable or disable CSRF protection globally.
				'field_name'                   => '_csrf',                           // Hidden form field name used for CSRF token submission.
				'header_name'                  => 'X-CSRF-Token',                    // HTTP header name used by AJAX/API clients to submit the CSRF token.
				'session_key'                  => '_csrf',                           // Session key where the raw CSRF token is stored.
				'token_bytes'                  => 32,                                // Number of random bytes used when generating a new CSRF token.
				'protect_methods'              => ['POST', 'PUT', 'PATCH', 'DELETE'], // HTTP methods that require CSRF verification.

				'origin_check'                 => true,                              // Enable Origin/Referer validation as an additional CSRF defense layer.
				'referer_fallback_on_https'    => true,                              // Use Referer validation on HTTPS requests when the Origin header is missing.
				'allow_missing_origin_on_http' => true,                              // Allow missing Origin header on plain HTTP requests (useful for local/dev setups).
				'trusted_origins'              => [],                                // Additional trusted origins or hostnames allowed by Origin/Referer validation.

				'fetch_metadata' => [
					'enabled'                   => true,                              // Enable Fetch Metadata validation via the Sec-Fetch-Site header.
					'allow_same_site'           => true,                              // Allow same-site requests to pass Fetch Metadata validation.
				],

				'mask_tokens'                  => true,                              // Return masked tokens to reduce BREACH-style compression attack exposure.

				'rotate_on_login'              => true,                              // Rotate the CSRF token after successful login.
				'rotate_on_logout'             => true,                              // Rotate the CSRF token during logout flow before session teardown or redirect.

				'log_failures'                 => true,                              // Log CSRF verification failures through the log service.
				'log_channel'                  => 'security',                        // Logical log channel/destination used for CSRF failure logging.
			],
			
			
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

			// -------------------------------------------------
			// Template roots / layers
			// -------------------------------------------------
			//
			// Each key is a "layer" slug used in template refs like:
			//   "member/home.html@app"
			//   "admin/layout.html@citomni/admin"
			//
			// Each value is an absolute directory path where that layer's
			// templates live (no trailing slash required; we'll rtrim it).
			//
			// NOTE: You MUST provide at least 'app' here in the final app config,
			// otherwise nothing can render. Core/provider layers (citomni/auth etc.)
			// are registered by those packages.
			//
			'template_layers' => [			
				'app' 			=> \CITOMNI_APP_PATH . '/templates',
				'citomni/http'	=> \CITOMNI_APP_PATH . '/vendor/citomni/http/templates',
			],


			// -------------------------------------------------
			// Cache / compilation
			// -------------------------------------------------
			//
			// cache_enabled:
			//   true  = compile template -> write to /var/cache -> reuse if fresh
			//   false = always recompile (dev convenience, slower)
			//
			'cache_enabled'         => true,

			// trim_whitespace:
			//   Collapse redundant whitespace in the FINAL HTML output
			//   (outside <pre>, <code>, <textarea>, <script>, <style>).
			//   Good for prod if you like "pretty lean markup".
			//
			'trim_whitespace'       => false,

			// remove_html_comments:
			//   Strip <!-- ... --> comments from the FINAL HTML output
			//   (but keep IE conditional comments etc.).
			//
			'remove_html_comments'  => false,

			// allow_php_tags:
			//   Controls `{? ... ?}` and `{?= ... ?}` inline PHP in templates.
			//   We default to true. Set false for paranoid deployments.
			//
			'allow_php_tags'        => true,


			// -------------------------------------------------
			// Asset/versioning helpers
			// -------------------------------------------------
			//
			// asset_version:
			//   Cache-buster token used by $asset('/css/app.css') -> /css/app.css?v=123
			//   Can be commit hash, build id, timestamp string, whatever.
			//
			'asset_version'         => '',


			// -------------------------------------------------
			// Global head/scripts snippet
			// -------------------------------------------------
			//
			// marketing_scripts:
			//   Raw HTML/JS snippet injected into templates via the global var
			//   `marketing_scripts`. Typical use: analytics tags, marketing pixels,
			//   cookie consent loader, etc.
			//
			'marketing_scripts'     => '',


			// -------------------------------------------------
			// Scoped per-request view vars
			// -------------------------------------------------
			//
			// 'vars' is a list of declarative rules that say:
			//   "Inject this variable into the template scope
			//    IF AND ONLY IF the current request path matches."
			//
			// Each entry describes:
			//   - the variable name (becomes $<name> in templates),
			//   - how/where to get its value,
			//   - which request paths it applies to.
			//
			// Shape per entry:
			// [
			//     'var'     => 'header',        // required
			//                                   // becomes $header in the template scope
			//
			//     'type'    => 'dynamic',       // required, either "dynamic" or "static"
			//
			//     'include' => ['*'],           // optional
			//                                   // list of allowed path patterns
			//                                   // (see "Path matching rules" below)
			//
			//     'exclude' => ['~^/admin/~'],  // optional
			//                                   // list of forbidden path patterns
			//
			//     'source'  => [
			//         // For type "dynamic":
			//         //   - how to compute the value at request time.
			//         //
			//         // Supported callable forms:
			//         //   1) "FQCN::method"
			//         //        Static call. Will be invoked as:
			//         //        FQCN::method(App $app)
			//         //
			//         //   2) ['class' => FQCN, 'method' => 'm']
			//         //        We construct: new FQCN($app), then call ->m()
			//         //
			//         //   3) ['service' => 'id', 'method' => 'm']
			//         //        We reuse an existing service:
			//         //          $this->app->id->m()
			//         //
			//         // For type "static":
			//         //   - literal data structure to inject directly (array/scalar/etc.).
			//         //
			//
			//         // Example for "dynamic":
			//         'service' => 'sitewide',
			//         'method'  => 'headerPayload',
			//
			//         // Example for "static":
			//         // 'sidebar' => [
			//         //     [ 'title' => 'Main', 'items' => [...]],
			//         //     [ 'title' => 'System', 'items' => [...]],
			//         // ],
			//     ],
			// ],
			//
			//
			// Path matching rules:
			// - We evaluate the *app-relative* request path, e.g. "/admin/users" or "/".
			//
			// - 'include':
			//     * If 'include' is missing or empty => "included by default".
			//     * If 'include' has patterns        => path must match at least one.
			//
			// - 'exclude':
			//     * If path matches ANY exclude pattern => var is NOT injected.
			//
			// Pattern syntax:
			//   "*"           => match everything
			//   "/"           => only the frontpage "/"
			//   "/foo/*"      => prefix/glob match
			//   "news"        => treated as "/news" (for convenience)
			//   "~^/admin/~"  => raw PCRE if it starts and ends with "~"
			//
			//
			// Runtime behavior:
			// - For each request, TemplateEngine picks all entries whose include/exclude
			//   rules match the current path.
			//
			// - For "static" entries:
			//     The 'source' block is injected directly, e.g.
			//     $admin_nav = [ 'sidebar' => [...] ] in that template render only.
			//
			// - For "dynamic" entries:
			//     TemplateEngine calls the described provider and injects the return
			//     value. The provider is expected to be read-only / cheap. If it is
			//     missing or invalid, we fail fast (RuntimeException).
			//
			// - Result: Templates just do `{{ $header['title'] }}` or loop `$admin_nav['sidebar']`,
			//   and those vars only exist on pages where they are relevant.
			//
			// Example usage:
			// [
			//     // Dynamic header model for most public pages
			//     'var'     => 'header',
			//     'type'    => 'dynamic',
			//     'include' => ['*'],
			//     'exclude' => ['~^/admin/~'],
			//     'source'  => [
			//         'service' => 'sitewide',
			//         'method'  => 'header',
			//     ],
			// ],
			//
			// [
			//     // Static admin sidebar, only on /admin/*
			//     'var'     => 'admin_nav',
			//     'type'    => 'static',
			//     'include' => ['~^/admin/~'],
			//     'exclude' => [],
			//     'source'  => [
			//         'sidebar' => [
			//             [
			//                 'title' => 'System',
			//                 'items' => [
			//                     [
			//                         'label' => 'Users',
			//                         'icon'  => 'user',
			//                         'url'   => 'admin/users.html',
			//                         'match' => ['admin/users', 'admin/users.html'],
			//                     ],
			//                 ],
			//             ],
			//         ],
			//     ],
			// ],
			//
			'vars' => [
				// (your rules go here)
			],

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
		 * - Remote control endpoints for system/admin operations (e.g., cache warmup,
		 *   maintenance toggles, deploy hooks, scheduled pruning).
		 * - Auth model: HMAC over a canonical base string, timestamp freshness,
		 *   optional source IP allow-list, and a nonce ledger to prevent replays.
		 *
		 * Secrets & file policy (IMPORTANT)
		 * - Do NOT put secrets in cfg. The HMAC secret is loaded from a side-effect-free
		 *   PHP file that returns a plain array (see contract below).
		 * - Default location: CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
		 * - Commit only the template: /var/secrets/webhooks.secret.php.tpl
		 *   Never commit the real secret file to Git.
		 *
		 * Secret file contract (side-effect free; returns array):
		 *   return [
		 *     'secret' => '<hex>',            // REQUIRED: hex string; recommended 64 chars for sha256 or 128 for sha512
		 *     'algo'   => 'sha256'|'sha512',  // OPTIONAL: used when cfg.webhooks.algo is null
		 *     // Optional metadata for ops visibility (ignored by verifier):
		 *     // 'rotated_at_utc' => '2025-10-17T11:12:00Z',
		 *     // 'generator'      => 'CitOmni DevKit',
		 *   ];
		 *
		 * Algo precedence
		 * - cfg.webhooks.algo > secret file 'algo' > 'sha256'.
		 * - The baseline uses 'algo' => null so the secret file may choose the algo.
		 * - Set cfg.webhooks.algo explicitly only when the app should override the
		 *   secret file's algo.
		 *
		 * Canonical signature base string (selected by 'bind_context')
		 * - Simple mode (bind_context=false):
		 *     "<timestamp>.<nonce>.<rawBody>"
		 * - Context-bound mode (bind_context=true; default):
		 *     ts + "\n" + nonce + "\n" + METHOD + "\n" + PATH + "\n" + QUERY + "\n" + sha256(rawBody)
		 *
		 * Notes:
		 * - Context-bound mode is stricter and binds the signature to method, path,
		 *   query string, and body hash. Clients must mirror the exact shape.
		 * - The body hash is always SHA-256, independent of HMAC algo.
		 * - Raw body bytes are signed. Clients must not sign pretty-printed JSON and
		 *   send minified JSON, or vice versa. Bytes are bytes. Annoying, but loyal.
		 *
		 * Required headers (server keys as seen in $_SERVER; names configurable below)
		 * - X-Citomni-Timestamp : UNIX seconds when the signature was created.
		 * - X-Citomni-Nonce     : Unique, single-use identifier (replay-protected).
		 * - X-Citomni-Signature : Hex HMAC of the canonical base string.
		 *
		 * Timestamp and nonce window
		 * - ttl_seconds controls the accepted request age.
		 * - ttl_clock_skew_tolerance allows limited clock drift in both directions.
		 * - Internally, the nonce ledger keeps webhook nonces for:
		 *     ttl_seconds + (2 * ttl_clock_skew_tolerance)
		 *   This covers the full timestamp acceptance window and prevents edge-case
		 *   replay when the sender's clock is ahead of the verifier's clock.
		 *
		 * IP allow-list semantics
		 * - allowed_ips empty => IP check is disabled (no IP restriction).
		 * - allowed_ips non-empty => source IP must match an entry:
		 *     * exact IPv4/IPv6 match, or
		 *     * IPv4/IPv6 CIDR, e.g. '203.0.113.0/24' or '2001:db8::/32'.
		 * - Source IP resolution:
		 *     1) Request::ip(), which honors the configured trusted-proxy whitelist
		 *        for public traffic.
		 *     2) Fallback to $_SERVER['REMOTE_ADDR'] for private/internal peers,
		 *        Docker, LAN, localhost cron, and similar non-public sources.
		 *
		 * Logging
		 * - Failure/success logging is best-effort and only used when the log service
		 *   is registered. Logging failures never change the auth result.
		 * - log_successes should normally stay false unless debugging integration.
		 *
		 * Guarantees
		 * - Deterministic verification.
		 * - Constant-time HMAC comparison.
		 * - Replay protection through the shared Nonce service.
		 * - Stale/future requests rejected before nonce filesystem writes.
		 * - Garbage signatures rejected before nonce filesystem writes.
		 *
		 * Required when enabled
		 * - webhooks.secret_file must exist and be readable.
		 * - nonce.dir must be writable because replay protection is mandatory.
		 *
		 * Typical app overrides (env files):
		 *   'webhooks' => [
		 *     'enabled' => true,
		 *     'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',
		 *     'allowed_ips' => ['203.0.113.10', '198.51.100.0/24'], // leave empty to disable IP check
		 *     // 'ttl_seconds' => 180,
		 *     // 'ttl_clock_skew_tolerance' => 30,
		 *     // 'algo' => 'sha512',
		 *     // 'bind_context' => true,
		 *   ],
		 *
		 *   'nonce' => [
		 *     'dir' => CITOMNI_APP_PATH . '/var/nonces',
		 *   ],
		 */
		'webhooks' => [

			// Master switch. Keep disabled unless actively used.
			// Disabled means every verification fails with reason "Disabled".
			'enabled' => false,

			// Filesystem path to the secret file.
			// The file must be side-effect free and return an array per the contract above.
			'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',

			// Freshness window and clock-skew tolerance in seconds.
			// Defaults accept requests up to 5 minutes old plus 1 minute of clock drift.
			'ttl_seconds' => 300,
			'ttl_clock_skew_tolerance' => 60,

			// Optional allow-list of source IPs. Empty = no IP restriction.
			// Supports exact IPv4/IPv6 and IPv4/IPv6 CIDR.
			'allowed_ips' => [
				// '203.0.113.10',
				// '198.51.100.0/24',
				// '2001:db8::/32',
			],

			// HMAC algorithm override.
			// Precedence: explicit cfg value > secret file 'algo' > 'sha256'.
			// Null means this cfg layer defers to the secret file, or to 'sha256' if the file has no algo.
			// Allowed explicit values: 'sha256' or 'sha512'.
			'algo' => null,

			// Bind signature to METHOD + PATH + QUERY + sha256(rawBody).
			// Stronger default; clients must mirror the canonical base string exactly.
			'bind_context' => true,

			// Header keys as seen in $_SERVER.
			// Override only if your environment rewrites incoming headers.
			'header_signature' => 'HTTP_X_CITOMNI_SIGNATURE',
			'header_timestamp' => 'HTTP_X_CITOMNI_TIMESTAMP',
			'header_nonce' => 'HTTP_X_CITOMNI_NONCE',

			// Best-effort structured logging through the log service, when registered.
			'log_failures' => true,
			'log_successes' => false,
			'log_file' => 'webhooks.jsonl',
		],


		/*
		 *------------------------------------------------------------------
		 * NONCE LEDGER (single-use token storage)
		 *------------------------------------------------------------------
		 *
		 * Purpose
		 * - Shared filesystem-backed ledger for single-use tokens.
		 * - Used by WebhooksAuth for replay protection.
		 * - Can be reused by other short- or long-lived single-use flows by using
		 *   separate namespaces.
		 *
		 * Storage layout
		 * - <dir>/<namespace>/<sha256(nonce)>.nonce
		 * - The raw nonce is never written to disk; only its hash is used as filename.
		 * - First writer wins via atomic fopen('x').
		 *
		 * Runtime behavior
		 * - checkAndStore(namespace, nonce, ttlSeconds) returns:
		 *     true  => first use, or previous entry expired and was reaped.
		 *     false => replay, malformed input, or storage failure.
		 * - Storage failures intentionally return false. From an auth perspective,
		 *   "could not prove single-use" means "reject".
		 *
		 * Nonce format
		 * - Configurable maximum length, default 128 bytes.
		 * - Accepted characters cover common URL-safe tokens:
		 *     A-Z a-z 0-9 _ . : -
		 * - This includes hex, UUID-like values, ULID-like values, JWT-ish segments,
		 *   and base64url tokens without padding.
		 *
		 * Directory policy
		 * - The service lazily creates per-namespace subdirectories.
		 * - The root dir and namespace dirs must be writable at runtime.
		 * - Keep this directory out of VCS.
		 *
		 * Cleanup
		 * - Opportunistic purge runs inside checkAndStore() with bounded work.
		 * - CLI cron may call purgeExpired(namespace, ttlSeconds) for deterministic
		 *   cleanup of busy or long-lived ledgers.
		 */
		'nonce' => [
			// Root directory for all namespaced nonce ledgers.
			// Default matches CitOmni's /var structure.
			'dir' => CITOMNI_APP_PATH . '/var/nonces',

			// Maximum byte length of accepted nonce strings.
			'max_len' => 128,

			// Opportunistic cleanup probability: 1 in N checkAndStore() calls.
			// Keep > 1 for busy systems; use CLI pruning for deterministic cleanup.
			'purge_probability' => 50,

			// Maximum directory entries scanned during one opportunistic purge.
			'purge_limit' => 25,

			// Modes used for lazily created directories and nonce files.
			// Effective permissions may still be narrowed by the process umask.
			'dir_mode' => 0775,
			'file_mode' => 0660,
		],

	];






	/**
	 * Vendor baseline route map for HTTP mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic HTTP route merge:
	 *   1) Vendor baseline: \CitOmni\Http\Boot\Registry::ROUTES_HTTP
	 *   2) Providers: /config/providers.php (their ROUTES_HTTP blocks)
	 *   3) App base: /config/citomni_http_routes.php
	 *   4) Env overlay: /config/citomni_http_routes.{ENV}.php
	 * - Route merge semantics are deep associative merge with last wins.
	 *
	 * Notes:
	 * - Routes live in the dedicated route map, not inside CFG_HTTP.
	 * - The array shape must match the Router contract used by CitOmni\Http.
	 * - Providers may contribute additional routes, while the app layer may
	 *   override or replace vendor/provider routes by path key.
	 * - Empty arrays are ignored during merge.
	 */
	public const ROUTES_HTTP = [
	
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
