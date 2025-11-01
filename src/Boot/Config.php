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
			'language'		=> 'en',	// used for <html lang="..."> etc.
			'icu_locale'	=> 'en_US',	// used by Intl (dates/numbers)
			'timezone' 		=> 'UTC',	
			'charset'		=> 'UTF-8',	// PHP default_charset + HTML output
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

			
			
			/*
			 *------------------------------------------------------------------
			 * ICONS (global inline SVG registry)
			 *------------------------------------------------------------------
			 * Deterministic map of symbolic icon ids to inline SVG markup.
			 *
			 * Purpose:
			 * - Provide a shared visual language ("home", "user", "settings", etc.)
			 *   across status-card screens, member area, and admin dashboards.
			 * - Allow providers to contribute new icons, and allow the app layer
			 *   to override or brand-swap any icon without subclassing services.
			 *
			 * Behavior:
			 * - Keys are stable string ids (e.g. 'home', 'settings', 'power').
			 * - Values are raw <svg>...</svg> strings (no width/height inline).
			 * - Icons are assumed to be safe, trusted markup from the codebase.
			 *   They are NOT user input and should be echoed directly.
			 *
			 * Merge rules:
			 * - Provider CFG_HTTP arrays are merged (first providers, then app cfg,
			 *   then env overlay) using last-wins semantics.
			 * - This means the application can replace any icon or add new ones
			 *   in /config/citomni_http_cfg.php without touching vendor code.
			 *
			 * Usage:
			 *   // Direct via cfg (raw array, because 'icons' is in RAW_ARRAY_SET)
			 *   $svg = $this->app->cfg->icons['home'] ?? '';
			 *   echo $svg;
			 *
			 *   // Or via a tiny IconRegistry service:
			 *   echo $this->app->icon->get('home');
			 *
			 * Notes:
			 * - Keep stroke/fill/style inline in the SVG so the icon is self-contained.
			 * - Do not embed xmlns/width/height here; let CSS size and color them.
			 */
			'icons' => [
				'home' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'home_alt' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 10v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9"/><path d="M10 21v-6h4v6"/></svg>',
				'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09c0 .69.4 1.3 1 1.51.61.21 1.3.05 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06c-.38.52-.54 1.21-.33 1.82.21.61.82 1 1.51 1H21a2 2 0 1 1 0 4h-.09c-.69 0-1.3.4-1.51 1Z"/></svg>',
				'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="4"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>',
				'user_alt' => '<svg viewBox="0 0 24 24" fill="none"><path d="M17 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.6"/></svg>',
				'user_delete' => '<svg viewBox="0 0 24 24" fill="none"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="13" r="2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.5 18a3 3 0 0 1 5 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'log' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h9a3 3 0 0 1 3 3v13l-4.5-2.5L8 20V7a3 3 0 0 1 3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'orders' => '<svg viewBox="0 0 24 24" fill="none"><path d="M3 5h18M7 5v14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M9 9h6M9 13h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
				'products' => '<svg viewBox="0 0 24 24" fill="none"><path d="M3 7l9-4 9 4-9 4-9-4Z" stroke="currentColor" stroke-width="1.6"/><path d="M3 7v10l9 4 9-4V7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'circle' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.6"/></svg>',
				'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/></svg>',
				'menu' => '<svg viewBox="0 0 24 24" fill="none"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'power' => '<svg viewBox="0 0 24 22" fill="none"><path d="M12 3v7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M6.2 5.6a8 8 0 1 0 11.6 0" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
				'search' => '<svg viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
				'calendar' => '<svg viewBox="0 0 24 24" fill="none"><path d="M21 10H3M16 2V6M8 2V6M7.8 22H16.2C17.8802 22 18.7202 22 19.362 21.673C19.9265 21.3854 20.3854 20.9265 20.673 20.362C21 19.7202 21 18.8802 21 17.2V8.8C21 7.11984 21 6.27976 20.673 5.63803C20.3854 5.07354 19.9265 4.6146 19.362 4.32698C18.7202 4 17.8802 4 16.2 4H7.8C6.11984 4 5.27976 4 4.63803 4.32698C4.07354 4.6146 3.6146 5.07354 3.32698 5.63803C3 6.27976 3 7.11984 3 8.8V17.2C3 18.8802 3 19.7202 3.32698 20.362C3.6146 20.9265 4.07354 21.3854 4.63803 21.673C5.27976 22 6.11984 22 7.8 22Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
				'infomation' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 14V10.5M12 7H12.01M9.9 19.2L11.36 21.1467C11.5771 21.4362 11.6857 21.5809 11.8188 21.6327C11.9353 21.678 12.0647 21.678 12.1812 21.6327C12.3143 21.5809 12.4229 21.4362 12.64 21.1467L14.1 19.2C14.3931 18.8091 14.5397 18.6137 14.7185 18.4645C14.9569 18.2656 15.2383 18.1248 15.5405 18.0535C15.7671 18 16.0114 18 16.5 18C17.8978 18 18.5967 18 19.1481 17.7716C19.8831 17.4672 20.4672 16.8831 20.7716 16.1481C21 15.5967 21 14.8978 21 13.5V7.8C21 6.11984 21 5.27976 20.673 4.63803C20.3854 4.07354 19.9265 3.6146 19.362 3.32698C18.7202 3 17.8802 3 16.2 3H7.8C6.11984 3 5.27976 3 4.63803 3.32698C4.07354 3.6146 3.6146 4.07354 3.32698 4.63803C3 5.27976 3 6.11984 3 7.8V13.5C3 14.8978 3 15.5967 3.22836 16.1481C3.53284 16.8831 4.11687 17.4672 4.85195 17.7716C5.40326 18 6.10218 18 7.5 18C7.98858 18 8.23287 18 8.45951 18.0535C8.76169 18.1248 9.04312 18.2656 9.2815 18.4645C9.46028 18.6137 9.60685 18.8091 9.9 19.2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
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
		 * - Remote control endpoints for admin operations (e.g., maintenance, deploy).
		 * - Auth model: HMAC over a canonical base string, TTL with clock-skew tolerance,
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
		 *     'secret' => '<hex>',            // REQUIRED: hex string; recommended 64 (sha256) or 128 (sha512) hex chars
		 *     'algo'   => 'sha256'|'sha512',  // OPTIONAL: used if cfg does not override 'algo'
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
		 *   Notes:
		 *   - The body hash is always SHA-256 (independent of HMAC algo).
		 *   - Stronger request coupling at the cost of a stricter client.
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
		 * - 'nonce_dir'   : writable directory for the nonce ledger (prevents replays).
		 *
		 * IP allow-list semantics (important)
		 * - 'allowed_ips' **empty** => IP check is **disabled** (no restriction).
		 * - 'allowed_ips' non-empty => request IP **must** match an entry:
		 *     * exact IP match, or
		 *     * IPv4 CIDR (e.g., '203.0.113.0/24').
		 * - Only IPv4 CIDR is supported in core (IPv6 lists will not match CIDR).
		 * - Source IP is taken from $_SERVER['REMOTE_ADDR'].
		 *   If behind a reverse proxy, either list the proxy IP/CIDR here or leave
		 *   'allowed_ips' empty (until trusted-proxy client IP rewriting is in place).
		 *
		 * Algo precedence
		 * - If 'algo' is set in cfg, it always wins.
		 * - Otherwise, 'algo' from the secret file is used when present.
		 *
		 * Typical app overrides (env files):
		 *   'webhooks' => [
		 *     'enabled'        => true,
		 *     'secret_file'    => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',
		 *     'nonce_dir'      => CITOMNI_APP_PATH . '/var/nonces',
		 *     'allowed_ips'    => ['203.0.113.10', '198.51.100.0/24'], // leave empty to disable IP check
		 *     // 'ttl_seconds'  => 180,
		 *     // 'ttl_clock_skew_tolerance' => 30,
		 *     // 'algo'         => 'sha512',
		 *     // 'bind_context' => true,
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


	];
}
