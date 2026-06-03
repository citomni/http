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
 * MAIN APPLICATION CONFIGURATION (HTTP)
 * ------------------------------------------------------------------
 * This file defines all HTTP-facing settings for your CitOmni app.
 *
 * POLICY:
 *   - Keep this file minimal and ONLY declare keys you intend to override from
 *     vendor/provider defaults. Do not mirror baseline config here.
 *
 * MERGE MODEL (cfg, last wins):
 *   1) Vendor HTTP baseline:   \CitOmni\Http\Boot\Config::CFG
 *   2) Provider CFGs (whitelisted in /config/providers.php via CFG_HTTP)
 *   3) App HTTP base:          /config/citomni_http_cfg.php        (this file)
 *   4) App HTTP overlay:       /config/citomni_http_cfg.{ENV}.php  <- wins last
 *
 *   {ENV} comes from CITOMNI_ENVIRONMENT ('dev' | 'stage' | 'prod').
 *
 * MERGE RULES:
 *   - Associative arrays merge recursively; per-key, the last source wins.
 *   - Numeric arrays (lists) are replaced wholesale by the last source.
 *   - Empty values ('', 0, false, null, []) are still valid overrides and they win.
 *
 * ACCESS IN CODE:
 *   - $this->app->cfg->identity->app_name
 *   - $this->app->cfg->http->base_url
 *   - $this->app->cfg->mail->smtp->host
 *
 * BASE URL POLICY:
 *   - If CITOMNI_PUBLIC_ROOT_URL is defined, the kernel uses it verbatim in all environments.
 *   - Dev:
 *       If http.base_url is empty and the constant is not defined, the kernel may auto-detect
 *       CITOMNI_PUBLIC_ROOT_URL from the incoming request (honoring trust_proxy if enabled).
 *   - Stage/Prod:
 *       Never auto-detect. Provide an absolute http.base_url
 *       (e.g. 'https://www.example.com') in citomni_http_cfg.{ENV}.php,
 *       or define CITOMNI_PUBLIC_ROOT_URL yourself.
 *   - Always omit trailing slash in base_url.
 *
 * PROXY TRUST:
 *   - http.trust_proxy toggles honoring Forwarded/X-Forwarded-* headers during base URL detection.
 *   - http.trusted_proxies (array) is passed to Request::setTrustedProxies([...]) for stricter control.
 *   - When in doubt, keep trust_proxy=false. Guessing headers is not a security strategy.
 *
 * LOCALE:
 *   - Kernel enforces locale.timezone via date_default_timezone_set()
 *     and locale.charset via ini_set('default_charset').
 *   - Use valid timezone identifiers ('Europe/Copenhagen') and charsets ('UTF-8').
 *
 * ERROR HANDLER:
 *   - ErrorHandler always renders something (no blank pages).
 *   - Detailed stack traces are ONLY shown when CITOMNI_ENVIRONMENT === 'dev'
 *     and error_handler.render.detail.level >= 1.
 *   - Logs are written in JSONL with bounded rotation under /var/logs.
 *
 * MAINTENANCE:
 *   - $this->app->maintenance->guard() uses cfg.maintenance.* at runtime to decide
 *     if we should short-circuit the request with an HTTP 503.
 *
 * ENV & SECRETS:
 *   - It's okay to keep secrets here; OPcache holds PHP in memory.
 *   - Do not leak sensitive cfg nodes directly to templates. Pass only
 *     the data that view code actually needs.
 *
 * NOTE:
 *   - Controllers/Models/Services typically extend BaseController/BaseModel/BaseService
 *     to gain $this->app for cfg/services access.
 * ------------------------------------------------------------------
 */
return [


	/*
	 *------------------------------------------------------------------
	 * APPLICATION IDENTITY
	 *------------------------------------------------------------------
	 */
	'identity' => [

		// Human-readable application name.
		// - Shown in HTML titles, error pages, or fallback legal pages.
		// - Keep it short and brand-like, e.g. "My CitOmni App".
		'app_name' => '%APP_NAME%', // e.g. "My CitOmni App"

		// Legal owner of the application and its content.
		// - Use the full legal entity name (company, org, or person).
		// - Displayed on legal pages like /legal/website-license.
		'owner_name' => '%LEGAL_OWNER_NAME%', // e.g. "ACME Ltd"

		// Contact address for legal/permissions requests.
		// - Typically a compliance, legal, or admin mailbox.
		// - Shown on the website-license page.
		'owner_email'=> '%LEGAL_OWNER_EMAIL%', // e.g. "legal@acme.com"

		// Public homepage of the legal owner.
		// - Used for attribution links in license/terms pages.
		// - Should be a stable corporate URL, not a product subpage.
		'owner_url'  => '%LEGAL_OWNER_URL%', // e.g. "https://www.acme.com"

		// Public-facing support contact (user questions, helpdesk).
		// - This is what end-users see on "Contact us" pages or footers.
		// - Localized labels (see language/contact.php) decide if shown as
		//   "Support", "Helpdesk", "Customer Service", etc.
		'contact_email' => '%OWNER_CONTACT_EMAIL%', // e.g. "support@mycitomniapp.com"

		// Public phone number for end-user support.
		// - Only include if you actually staff the line.
		// - Format: international + local, human-readable.
		'contact_phone' => '%OWNER_CONTACT_PHONE%', // e.g. "(+45) 12 34 56 78"
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
	
	
	/*
	 *------------------------------------------------------------------
	 * LOCALE
	 *------------------------------------------------------------------
	 * Language & formatting settings for this app.
	 *
	 * POLICY:
	 * - Keep 'language' for HTML and i18n signals (BCP47: hyphen form, but we use short 'da' here).
	 * - Use 'icu_locale' (underscore form, e.g. da_DK) for Intl/ICU date/number formatting.
	 * - 'timezone' is the application default for time operations and formatting.
	 * - 'charset' is enforced via ini_set('default_charset') during boot.
	 *
	 * BASELINE VS. APP:
	 * - Framework baseline defaults to: icu_locale=en_US, timezone=UTC.
	 * - This app overrides to Danish + Europe/Copenhagen. Adjust per environment in
	 *   /config/citomni_http_cfg.{ENV}.php if needed (e.g., stage uses UTC, prod uses Europe/Copenhagen).
	 *
	 * REQUIREMENTS:
	 * - PHP ext-intl must be installed for ICU-based formatting.
	 */
	'locale' => [
		// HTML language hint (<html lang="...">) and general language signal.
		// Keep this short BCP47 tag unless you specifically need a region tag (e.g., 'da-DK').
		'language'   => 'da',

		// ICU/Intl locale used by the Dates service (and other Intl helpers).
		// Underscore form is conventional for ICU (da_DK, en_US, de_DE, ...).
		'icu_locale' => 'da_DK',

		// Default application timezone. Use a valid IANA TZ identifier.
		// The kernel applies this via date_default_timezone_set().
		'timezone'   => 'Europe/Copenhagen',

		// Default output/input charset (also applied by the kernel).
		'charset'    => 'UTF-8',
	],


	/*
	 *------------------------------------------------------------------
	 * HTTP SETTINGS
	 *------------------------------------------------------------------
	 */
	// 'http' => [
		// 'base_url' => 'https://www.mycitomniapp.com', // Never include a trailing slash

		// trust_proxy: true only when behind a trusted reverse proxy/LB; enables honoring Forwarded/X-Forwarded-*.
		// trusted_proxies: optional allow-list of proxy IPs/CIDRs given to Request::setTrustedProxies().
		// When in doubt, keep trust_proxy=false. Guessing headers is not a security strategy.
		// 'trust_proxy'     => false,
		// 'trusted_proxies' => ['10.0.0.0/8','192.168.0.0/16','::1'],
	// ],


	/*
	 *------------------------------------------------------------------
	 * VIEW / CONTENT / TEMPLATE ENGINE
	 *------------------------------------------------------------------
	 */
	/*
	'view' => [
		// Cache
		'cache_enabled'        => true,

		// Marketing scripts (inserted into <head>)
		'marketing_scripts'    => '<!-- Your script -->',
		
	],
	*/


	/*
	 *------------------------------------------------------------------
	 * ERROR HANDLER
	 *------------------------------------------------------------------
	 * Overview:
	 * - The HTTP ErrorHandler always logs and always renders for:
	 *   1) Uncaught exceptions,
	 *   2) Shutdown fatals (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR),
	 *   3) Router HTTP errors (404/405/5xx via httpError(...)).
	 *   These are non-optional (to prevent blank pages).
	 *
	 * Rendering of non-fatal PHP errors:
	 * - Controlled by: error_handler.render.trigger (bitmask).
	 * - Baseline should be 0 in prod/stage; in dev you typically enable:
	 *   E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR | E_DEPRECATED | E_USER_DEPRECATED
	 * - Fatal classes are ignored even if set (sanitized by the handler):
	 *   E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR.
	 *
	 * Client detail level:
	 * - error_handler.render.detail.level:
	 *   0 = minimal message to client (prod/stage),
	 *   1 = developer details (stack traces etc.). This only takes effect when
	 *       CITOMNI_ENVIRONMENT === 'dev'. In non-dev it behaves as 0.
	 * - error_handler.render.detail.trace bounds stack output:
	 *   - max_frames, max_arg_strlen, max_array_items, max_depth, ellipsis.
	 *
	 * Optional error_reporting override:
	 * - error_handler.render.force_error_reporting (int|null):
	 *   - If set, the ErrorHandler calls error_reporting(...) during install().
	 *   - Typical in dev: E_ALL. Omit in prod/stage.
	 *
	 * Logging:
	 * - error_handler.log.trigger (bitmask), default typically E_ALL.
	 * - error_handler.log.path (absolute dir): must be writable.
	 * - Rotation: size-guarded; before the next write exceeds max_bytes, the live
	 *   JSONL is rotated with a UTC timestamped sibling; at most max_files rotated
	 *   siblings are kept per base (live file is never deleted by prune()).
	 * - File names (per type):
	 *   - Exceptions:  http_err_exception.jsonl
	 *   - Shutdown:    http_err_shutdown.jsonl
	 *   - PHP errors:  http_err_phperror.jsonl
	 *   - Router:      http_router_404.jsonl, http_router_405.jsonl, http_router_5xx.jsonl
	 *
	 * Templates (HTML responses):
	 * - error_handler.templates.html and error_handler.templates.html_failsafe are
	 *   optional absolute paths to plain PHP templates. They receive a $data array:
	 *   {status, status_text, error_id, title, message, details|null, request_id, year}.
	 * - If unavailable, a minimal inline HTML fallback is used.
	 * - Content negotiation: JSON is returned automatically when the client sends
	 *   Accept: application/json (or +json) or X-Requested-With: XMLHttpRequest.
	 *
	 * Default status codes:
	 * - error_handler.status_defaults provides fallbacks for:
	 *   'exception', 'shutdown', 'php_error', 'http_error' (typically 500).
	 *   Router passes explicit status (e.g., 404/405/5xx) to httpError(...).
	 *
	 * Migration notes (from legacy keys):
	 * - The kernel no longer uses legacy keys like:
	 *   error_handler.log_file, recipient, sender, template, display_errors, max_log_size.
	 * - Replace them with the structured keys under error_handler.render, .log,
	 *   .templates and .status_defaults as described above.
	 *
	 * Config caches:
	 * - When config caches are warm (e.g., var/cache/cfg.http.php), the evaluated
	 *   values are frozen until the cache is warmed again. Changing this file will
	 *   not take effect until the cache is rebuilt.
	 */
	/*
	'error_handler' => [

		'render' => [

			// Which non-fatal PHP error levels (bitmask) should trigger rendering?
			// - 0 (default here): do not render non-fatal errors (prod/stage-friendly).
			// - DO NOT include fatal classes: E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR.
			//   The handler will sanitize fatals away even if misconfigured.
			//
			// Typical dev choice (see dev overlay):
			//   E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING |
			//   E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR |
			//   E_DEPRECATED | E_USER_DEPRECATED
			'trigger' => 0,

			'detail' => [

				// How much detail should be shown to the client?
				// 0 = minimal client message (always recommended for prod/stage)
				// 1 = developer details (stack traces, structured context) – ONLY active when CITOMNI_ENVIRONMENT === 'dev'
				//     (Setting 1 in non-dev envs will still behave as 0.)
				//
				// Logs are always detailed regardless of this flag.

				'level' => 0,

				// Trace formatting limits (only apply when detail.level = 1 AND we are in 'dev').
				// Keep bounded to avoid huge responses and leakage; logs still carry full structured info.
				'trace' => [
					'max_frames'      => 120,  // Maximum number of stack frames included.
					'max_arg_strlen'  => 512,  // Max chars shown per string argument.
					'max_array_items' => 20,   // Max array items per level.
					'max_depth'       => 3,    // Recursion depth when dumping arrays/objects.
					'ellipsis'        => '...',// Ellipsis marker for truncated output.
				],
			],

			// Optional hard override of PHP's error_reporting at install time (int mask).
			// - Leave unset/null to respect ini/error_reporting as-is.
			// - Example (dev overlay): E_ALL
			
			// 'force_error_reporting' => null,
		],

		'log' => [

			// Which PHP error levels (bitmask) should be logged?
			// - Default: E_ALL (recommended; logs are for developers/ops).
			// - Router 404/405/5xx are always logged, but into separate files:
			//   - http_router_404.jsonl
			//   - http_router_405.jsonl
			//   - http_router_5xx.jsonl
			///
			'trigger'    => E_ALL,

			
			// Log directory (absolute). Files are JSONL with size-guarded rotation.
			// - Rotation strategy: sidecar lock + copy+truncate, timestamped rotated files.
			// - Retention: keeps at most 'max_files' rotated siblings per base; live file persists.
			///
			'path'       => \CITOMNI_APP_PATH . '/var/logs',

			
			// Rotate a log before the next write would exceed this many bytes.
			// Keep conservative defaults to protect disk on shared hosts.
			///
			'max_bytes'  => 2_000_000, // ~2MB

			
			// Maximum number of rotated files to keep per base (live file excluded).
			'max_files'  => 10,
		],

		'templates' => [

			// Optional primary HTML template for error pages.
			// - Plain PHP file; receives $data array with keys:
			//   status, status_text, error_id, title, message, details|null, request_id, year
			// - If missing/unreadable, handler falls back to 'html_failsafe' and finally inline minimal HTML.
			'html'          => \CITOMNI_APP_PATH . '/templates/error/error.php',

			// Optional failsafe HTML template. Leave null/empty to skip.
			'html_failsafe' => null,
		],

		'status_defaults' => [

			// Default HTTP status codes when a category-specific status is not explicitly set.
			// - 'exception' and 'shutdown' are almost always 500.
			// - 'php_error' is used when rendering non-fatal PHP errors (dev only by default).
			// - 'http_error' is a fallback; router passes explicit status (404/405/5xx) anyway.
			'exception' => 500,
			'shutdown'  => 500,
			'php_error' => 500,
			'http_error'=> 500,
		],
	],
	*/


];
