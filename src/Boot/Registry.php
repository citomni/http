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
 * Registry: Boot contributions of citomni/http, the HTTP mode baseline.
 *
 * Declares the services, cfg baseline and routes of HTTP mode as constant arrays. Merging them
 * with providers and the app, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Ship a baseline for every cfg path of its own that the package reads, with a comment on
 *   what each key controls and its unit.
 * - Ship defaults that are safe in every environment.
 */
class Registry {
	

	/** Services for HTTP. */
	public const array MAP_HTTP = [

		'errorHandler'	=> \CitOmni\Http\Service\ErrorHandler::class,
		'request'		=> \CitOmni\Http\Service\Request::class,
		'response'		=> \CitOmni\Http\Service\Response::class,
		'router'		=> \CitOmni\Http\Service\Router::class,
		'session'		=> \CitOmni\Http\Service\Session::class,
		'flash'			=> \CitOmni\Http\Service\Flash::class,
		'datetime'		=> \CitOmni\Http\Service\Datetime::class,
		'cookie'		=> \CitOmni\Http\Service\Cookie::class,
		'tplEngine'		=> \CitOmni\Http\Service\TemplateEngine::class,
		'csrf'			=> \CitOmni\Http\Service\Csrf::class,
		'captcha'		=> \CitOmni\Http\Service\Captcha::class,
		'nonce'			=> \CitOmni\Http\Service\Nonce::class,
		'maintenance'	=> \CitOmni\Http\Service\Maintenance::class,
		'webhooksAuth'	=> \CitOmni\Http\Service\WebhooksAuth::class,
		'slugger'		=> \CitOmni\Http\Service\Slugger::class,
		'tags'			=> \CitOmni\Http\Service\Tags::class,
		'upload' 		=> \CitOmni\Http\Service\Upload::class,
		'icon'			=> \CitOmni\Http\Service\Icon::class,

	];






	/** Cfg baseline for HTTP. */
	public const array CFG_HTTP = [

		'http' => [
			// Public root URL of the app, absolute and without a trailing slash, such as
			// https://www.example.com. '' derives it from the request in dev; in any other
			// environment, boot then fails unless the constant CITOMNI_PUBLIC_ROOT_URL is defined.
			'base_url'                => '',

			// true lets proxy headers, such as Forwarded and X-Forwarded-*, set the scheme,
			// host, port and client IP of a request; Request takes them only from a peer in
			// trusted_proxies. When dev derives the public root URL from the request, the
			// X-Forwarded headers count from any peer. Enable it only behind a reverse proxy or
			// load balancer.
			'trust_proxy'             => false,

			// IP addresses and CIDR ranges, IPv4 or IPv6, of the proxies whose headers are
			// trusted; [] trusts none, and an invalid entry matches nothing. List every proxy
			// of the chain, such as the load balancer and the CDN ranges.
			'trusted_proxies'         => ['10.0.0.0/8', '192.168.0.0/16', '::1'],

			// true matches request paths in any letter case, for routes and fetch_metadata
			// exemptions alike. A local development convenience; keep false in stage and prod.
			'router_case_insensitive' => false,

			// Slugger policy. Service-map options of the slugger service override each key.
			'slug' => [
				'separator'      => '-',               // Text that replaces disallowed characters and joins words; not empty.
				'lowercase'      => true,              // Lowercase slugs; validate() then rejects uppercase.
				'maxLength'      => 140,               // Maximum slug length in bytes, at least 1; slugify() shortens longer slugs, validate() rejects them.
				'transliterate'  => true,              // Transliterate to ASCII before disallowed characters are replaced.
				'reserved'       => [],                // Slugs that validate() rejects, compared case-insensitively.
				'allowedPattern' => '/[^a-z0-9\-]+/i', // PCRE that matches the disallowed characters slugify() replaces with the separator.
			],

			// Tags policy. Service-map options of the tags service override each key.
			'tags' => [
				'delimiters'         => [',', ';'],    // Label separators for parse(), at least one: One entry splits on that string, several split on each of their characters.
				'maxLabelLength'     => 80,            // Maximum label length in characters, at least 1; validateLabel() rejects longer labels.
				'caseMode'           => 'preserve',    // Label casing: preserve, title (each word capitalized, the rest lowercased) or lower.
				'collapseWhitespace' => true,          // Replace runs of whitespace in a label with one space.
				'disallowedPattern'  => null,          // PCRE that makes validateLabel() reject a label when it matches; null for none.
				'maxTagsPerEntity'   => null,          // Maximum labels parse() returns, at least 1; null for no limit.
			],
		],


		// The errorHandler service; its service-map options override any key. Uncaught
		// exceptions, fatal errors and httpError() are logged and rendered whatever the
		// triggers say; the triggers cover only non-fatal PHP errors.
		'error_handler' => [

			'render' => [

				// Bitmask of the non-fatal PHP error levels that end the request with an error
				// response, within error_reporting(); 0 for none. Fatal levels in the mask are
				// ignored. Usually set only in a dev overlay, such as to E_ALL.
				'trigger' => 0,

				'detail' => [

					// What a client sees of an error beyond its status: 0 no internals; 1 or
					// more also exception and PHP error messages, the log record and the
					// httpError() context, but only when CITOMNI_ENVIRONMENT is 'dev'. Logging
					// does not depend on it.
					'level' => 0,

					// Limits of every exception trace, in the log and in the developer detail.
					// max_arg_strlen and max_depth apply to argument values, which only
					// log.trace_args lets in.
					'trace' => [
						'max_frames'      => 120,   // Maximum number of frames in a trace.
						'max_arg_strlen'  => 512,   // Maximum bytes shown of a string argument value.
						'max_array_items' => 20,    // Maximum arguments shown per frame, and items per array value.
						'max_depth'       => 3,     // Maximum nesting depth shown of an argument value.
						'ellipsis'        => '...', // Marker for what a limit cut off an argument list or value.
					],
				],

				'force_error_reporting' => null, // Int mask that install() passes to error_reporting(); null keeps the current level.
			],

			'log' => [

				// Bitmask of the non-fatal PHP error levels that are logged, within
				// error_reporting(); 0 for none.
				'trigger'   => E_ALL,

				// true logs the values of trace arguments, within render.detail.trace; false
				// logs only their types, such as string(15) or array(3). Values can hold
				// passwords and tokens, so true suits dev only.
				'trace_args' => false,

				'path'      => \CITOMNI_APP_PATH . '/var/logs', // Absolute directory of the JSONL error logs, created when missing; empty or whitespace means CITOMNI_APP_PATH . '/var/logs'.

				// Bytes a live log file may grow to: A write that would pass it rotates the
				// file first. One larger record is still written whole. Keep it low on shared
				// hosts.
				'max_bytes' => 2_000_000,

				'max_files' => 10, // Rotated files kept per log file, the live file not counted; 0 keeps all.
			],

			'templates' => [

				// Plain PHP template of the HTML error page, which gets the page values in
				// $data; null or '' for none. When it is missing or throws, html_failsafe is
				// tried, then a built-in page.
				'html'          => __DIR__ . '/../../templates/errors/error.php',

				'html_failsafe' => __DIR__ . '/../../templates/errors/error_failsafe.php', // Plain PHP template tried when html fails; null or '' for none.
			],

			'status_defaults' => [
				'exception' => 500, // HTTP status of the response to an uncaught exception.
				'shutdown'  => 500, // HTTP status of the response to a fatal error.
				'php_error' => 500, // HTTP status of the response to a non-fatal PHP error that render.trigger covers.
			],
		],


		// PHP's native session, as the session service starts it.
		'session' => [
			// Storage
			'name'                    => 'CITSESSID', // Name of the session cookie; '' keeps PHP's session.name.
			'save_path'               => CITOMNI_APP_PATH . '/var/state/php_sessions', // Directory of the session files, created when missing; '' keeps PHP's session.save_path.

			// Seconds of inactivity after which PHP may delete the data of a session. It is
			// storage retention, not a login lifetime: A login that may stay idle longer needs a
			// higher value.
			'gc_maxlifetime'          => 1440,

			// gc_probability / gc_divisor is the chance per session start that PHP deletes
			// expired session data. Set explicitly: Debian and Ubuntu ship gc_probability 0 and
			// clean only the save paths of their php.ini from cron, never this save_path. Set
			// gc_probability to 0 only when a scheduled job cleans save_path.
			'gc_probability'          => 1,
			'gc_divisor'              => 1000,

			'use_strict_mode'         => true,      // PHP session.use_strict_mode: true replaces a session id that storage does not know with a new one.
			'use_only_cookies'        => true,      // PHP session.use_only_cookies: true takes the session id only from the cookie, never from the URL.
			'lazy_write'              => true,      // PHP session.lazy_write: true writes session data only when it changed.

			// Session cookie overrides; null takes the attribute from cfg cookie.
			'cookie_secure'           => null,      // Secure: true or false; null: As cookie.secure.
			'cookie_httponly'         => true,      // HttpOnly: true keeps scripts from reading the session id, whatever cookie.httponly says; null: As cookie.httponly.
			'cookie_samesite'         => null,      // SameSite: 'Lax', 'Strict' or 'None' (None requires Secure); null: As cookie.samesite.
			'cookie_path'             => null,      // Path; null: As cookie.path.
			'cookie_domain'           => null,      // Domain; null: As cookie.domain; '' makes the session cookie host-only even when cookie.domain is set.
		],


		// Default attributes of the cookies that the cookie service sets, and of the session
		// cookie. Service-map options of the cookie service override each key.
		'cookie' => [
			'secure'   => null,     // Secure attribute: true or false; null sets it when the site uses HTTPS by its configured URL or by the request.
			'httponly' => true,     // HttpOnly attribute: true keeps the cookie from scripts.
			'samesite' => 'Lax',    // SameSite attribute: 'Lax', 'Strict' or 'None', in any case; None requires secure.
			'path'     => '/',      // Path attribute; null or '' means '/'.

			// Domain attribute. null or '': Host-only, so the browser returns the cookie only to
			// the host that set it. A domain such as 'example.com' shares the cookie with every
			// subdomain; set one only for that purpose.
			'domain'   => null,
		],


		// Protection of requests against forgery and bots.
		'security' => [

			// Fetch Metadata check of every request: A request that FetchMetadataPolicy refuses
			// under these keys gets 403. Separate from csrf.fetch_metadata, which applies only
			// within CSRF verification.
			'fetch_metadata' => [
				'enabled'         => false, // true turns the check on.
				'allow_same_site' => false, // true allows Sec-Fetch-Site same-site, which trusts sibling subdomains.
				'allow_missing'   => true,  // true allows requests without Sec-Fetch-Site or with an unknown value: Old browsers, and server-to-server calls such as signed webhooks.

				// Named rules that let a request through by path and method. A rule:
				//   'name' => ['path' => '/x', 'match' => 'exact'|'prefix', 'methods' => ['GET']]
				// false as the rule removes an inherited rule of that name.
				'exempt'          => [],
			],
		
			'csrf' => [
				'enabled'                      => true,                              // true verifies the requests of protect_methods; false lets every request pass.
				'field_name'                   => '_csrf',                           // Form field that carries the token in a POST body.
				'header_name'                  => 'X-CSRF-Token',                    // Request header that carries the token; it wins over the form field.
				'session_key'                  => '_csrf',                           // Session key of the token.
				'token_bytes'                  => 32,                                // Random bytes per token; at least 16.
				'protect_methods'              => ['POST', 'PUT', 'PATCH', 'DELETE'], // HTTP methods whose requests are verified; other methods pass.

				'origin_check'                 => true,                              // true also checks Origin, or Referer, against the request's own origin and trusted_origins.
				'referer_fallback_on_https'    => true,                              // true checks the Referer like Origin when an HTTPS request has no Origin; false rejects such a request.
				'allow_missing_origin_on_http' => true,                              // true lets a plain HTTP request without Origin pass the origin check; meant for local development over plain HTTP.

				// Other sites whose requests pass the origin check and csrf.fetch_metadata: A
				// full origin such as 'https://app.example.com' matches exactly; a bare
				// hostname matches that host over http or https on any port.
				'trusted_origins'              => [],

				'fetch_metadata' => [
					'enabled'                   => true,                              // true rejects a request whose Sec-Fetch-Site is not same-origin or none, unless allow_same_site or a trusted Origin admits it; a request without the header passes.
					'allow_same_site'           => true,                              // true lets Sec-Fetch-Site same-site pass.
				],

				'mask_tokens'                  => true,                              // true hands out the token masked with new random bytes on every call, against BREACH-style compression attacks, and accepts only masked tokens.

				// Switches for rotating the CSRF token at login and at logout, for the package
				// that handles login. The csrf service rotates only when rotate() is called.
				'rotate_on_login'              => true,
				'rotate_on_logout'             => true,

				'log_failures'                 => true,                              // true logs every rejected request through the log service, when one is registered.
				'log_channel'                  => 'security',                        // Log file name that the rejections are written to through the log service.
			],
			
			
			// Anti-bots
			'captcha_protection'	=> true, // true makes the captcha service check answers; false accepts every answer, and the captcha image and template helpers give nothing. Challenges need the captchaImage service of citomni/image.
			'honeypot_protection'	=> true, // Provided to templates as $honeypot_protection, for forms with a honeypot field.
			'form_action_switching'	=> true, // Provided to templates as $form_action_switching, for forms that switch their action against bots.

			// Captcha challenges. The package routes no captcha image: An app that uses captcha
			// routes image_path to CaptchaController::image for GET.
			'captcha' => [
				'session_key'	=> '_captcha',		// Session key of the pending challenges.
				'ttl'			=> 1200,			// Seconds a challenge stays valid, at least 1; keep it below session.gc_maxlifetime.
				'max_pending'	=> 5,				// Pending challenges kept per session, for several tabs or forms, at least 1; a new one beyond it drops the oldest.
				'id_field'		=> 'captcha_id',	// Form field with the challenge id, as $captchaField() renders it.
				'answer_field'	=> 'captcha',		// Form field with the visitor's answer; it must differ from id_field.
				'image_path'	=> '/captcha.png',	// App path of the challenge images, starting with "/", without query, fragment or whitespace; $captchaUrl() links to it.
			],
		],


		
		// The tplEngine service. Service-map options of tplEngine override cache_enabled,
		// trim_whitespace, remove_html_comments, allow_php_tags and asset_version.
		'view' => [

			// Template roots by layer, referenced as "path/file.html@layer": 'app' or a
			// 'vendor/package' slug, each mapped to an absolute directory, with or without a
			// trailing slash. A package that ships templates adds its own layer.
			'template_layers' => [			
				'app' 			=> \CITOMNI_APP_PATH . '/templates',  // The app's own templates, referenced as file@app.
				'citomni/http'	=> \CITOMNI_APP_PATH . '/vendor/citomni/http/templates',  // This package's templates, referenced as file@citomni/http.
			],


			'cache_enabled'         => true,  // true reuses a compiled template while its sources are unchanged; false compiles on every render.

			'trim_whitespace'       => false, // true collapses runs of whitespace in the literal markup of templates when they compile; pre, code, textarea, script and style stay as they are.

			'remove_html_comments'  => false, // true removes HTML comments from templates when they compile; conditional comments stay.

			'allow_php_tags'        => true,  // true compiles "{? code ?}" and "{?= expr ?}" to PHP; false compiles them to nothing. Native PHP tags work either way.


			'asset_version'         => '',    // Version that $asset() appends to asset URLs as "v=", such as a build id or commit hash; '' appends none.


			'marketing_scripts'     => '',    // Trusted markup, such as analytics tags, that templates get as $marketing_scripts to output unescaped.


			// Template variables for the pages of the app, keyed by variable name, so each
			// package and the app add their own; a later layer overrides the keys of an entry
			// by its name. A list in place of the map, [] included, replaces the entries of the
			// earlier layers, and list entries give no template variables. An entry:
			//   'header' => [
			//       'type'    => 'dynamic',      // 'static' or 'dynamic'.
			//       'source'  => ['service' => 'sitewide', 'method' => 'header'],
			//       'include' => ['*'],          // Optional; empty or missing covers every page.
			//       'exclude' => ['/admin/*'],   // Optional.
			//   ],
			// A static source is the value itself. A dynamic source is called on every render
			// of a page that the entry covers, so keep it cheap and free of side effects:
			// "FQCN::method" with the App, ['class' => FQCN, 'method' => 'm'] on a new
			// FQCN($app), or ['service' => 'id', 'method' => 'm'] on a registered service.
			// include and exclude are path patterns: '*' for every page, '/' for the front page,
			// '/admin/*' or '/admin' for a prefix, '~regex~' for a regular expression. An entry
			// covers a page that include matches, unless exclude matches it.
			'vars' => [
			],

		],



		// The maintenance service, whose flag file holds the current state.
		'maintenance' => [
			'flag' => [
				'path' => CITOMNI_APP_PATH . '/var/flags/maintenance.php', // Absolute path of the flag file, a PHP file that returns the state; enabling or disabling maintenance rewrites it. '' means var/flags/maintenance.php under CITOMNI_APP_PATH.
				'template' => __DIR__ . '/../../templates/public/maintenance.php',  // Plain PHP template of the 503 maintenance page; '' or a missing file gives a built-in page.
				
				// Client IP addresses, such as '203.0.113.10', that bypass maintenance mode while
				// the flag file has no list of its own; [] for none. Only exact public addresses
				// match, as Request::ip() reports them; in dev, 'unknown' admits every client
				// without one.
				'allowed_ips' => [
				],
				
				// Seconds sent as Retry-After while the flag file holds none, and written to
				// the flag by a toggle unless setRetryAfter() chose a value. Set it to the
				// typical length of a short maintenance window.
				'default_retry_after' => 300,
			],
			// Backups of the flag file that a toggle replaces.
			'backup' => [			
				'enabled' => true, // true backs up the flag file before a toggle replaces it.
				'keep' => 3, // Number of backups kept, newest first; 0 writes none.
				'dir' => CITOMNI_APP_PATH . '/var/backups/flags/' // Directory of the backups, created when missing; must be a non-empty string.
			],
			'log' => [
				'filename' => 'maintenance.json', // Log file name passed to the log service, when one is registered, for the toggle records; '' means 'maintenance.json'.
			],
		],


		// The webhooksAuth service, which authenticates signed webhook requests, such as those
		// to the protected /_system/ routes. The HMAC secret never goes in cfg: It is read from
		// secret_file.
		'webhooks' => [

			// true verifies signed requests; false rejects every request with reason Disabled.
			'enabled' => false,

			// Absolute path of a PHP file without side effects that returns an array with
			// 'secret', a hex string, and optionally 'algo'. Read only while enabled. Keep the
			// file out of version control.
			'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',

			// A timestamp is accepted from ttl_seconds plus ttl_clock_skew_tolerance seconds
			// old to ttl_clock_skew_tolerance seconds ahead.
			'ttl_seconds' => 300, // Maximum age of a request in seconds, at least 1.
			'ttl_clock_skew_tolerance' => 60, // Tolerated clock difference to the sender in seconds, in either direction; at least 0.

			// Source IP addresses and CIDR ranges, IPv4 or IPv6, such as '203.0.113.10' or
			// '198.51.100.0/24', that may send webhooks; [] allows every source. An invalid
			// entry matches nothing.
			'allowed_ips' => [
			],

			// HMAC algorithm: 'sha256' or 'sha512'. null uses the secret file's algo, or
			// 'sha256' when the file names none.
			'algo' => null,

			// true binds the signature to the method, path, query and body hash, besides the
			// timestamp and nonce; false covers only the timestamp, nonce and raw body. Senders
			// must sign the same string.
			'bind_context' => true,

			// $_SERVER keys of the signature, timestamp and nonce headers. Change them only
			// where the server rewrites incoming headers.
			'header_signature' => 'HTTP_X_CITOMNI_SIGNATURE',
			'header_timestamp' => 'HTTP_X_CITOMNI_TIMESTAMP',
			'header_nonce' => 'HTTP_X_CITOMNI_NONCE',

			// Logging through the log service, when one is registered.
			'log_failures' => true, // true logs every rejected request.
			'log_successes' => false, // true logs every accepted request; for debugging an integration.
			'log_file' => 'webhooks.jsonl', // Log file name passed to the log service.
		],


		// The nonce service, a file ledger of used single-use tokens, such as webhook nonces.
		'nonce' => [
			// Directory of the ledgers, one subdirectory per namespace, created on first use.
			// It must be writable; keep it out of version control.
			'dir' => CITOMNI_APP_PATH . '/var/nonces',

			// Maximum length of an accepted nonce in bytes, from 8 to 1024.
			'max_len' => 128,

			// Chance of a purge of expired entries after a stored nonce, as 1 in this number;
			// at least 1. Keep it above 1 on a busy ledger: 1 purges after every stored nonce.
			// Purge a busy or long-lived ledger from a scheduled job as well.
			'purge_probability' => 50,

			// Maximum number of entries that the purge after a stored nonce scans, at least 1.
			'purge_limit' => 25,

			// Permission modes of created directories and nonce files; the process umask may
			// narrow the directory mode.
			'dir_mode' => 0775,
			'file_mode' => 0660,
		],

	];






	/** HTTP routes. */
	public const array ROUTES_HTTP = [
	
		// Welcome page of a new installation.
		'/' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'index',
			'methods' => ['GET'],
			'template_file' => 'public/index.html',
			'template_layer' => 'citomni/http'
		],
		// Website content license page; /legal/website-license/index.html redirects to it
		// permanently.
		'/legal/website-license' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'websiteLicense',
			'methods' => ['GET']
		],
		'/legal/website-license/index.html' => [
			'controller' => \CitOmni\Http\Controller\PublicController::class,
			'action' => 'redirectWebsiteLicense',
			'methods' => ['GET']
		],
		
		
		// System endpoints for uptime monitors and diagnostics, without authentication.
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

		// System operations and status reports, for webhook-signed requests only; see cfg webhooks.
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
		'/_system/upload-limits.json' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action' => 'uploadLimitsJson',
			'methods' => ['GET'],
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

		// Diagnosis of webhook signing: It answers unsigned requests too, with facts that are
		// not secret.
		'/_system/_debug/webhook' => [
			'controller' => \CitOmni\Http\Controller\SystemController::class,
			'action'     => 'webhookDebug',
			'methods'    => ['POST'],
		],
		
		// Application information as a page and as JSON, for development.
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
		
		// Placeholder routes, such as '/user/{id}'; see Router::run() for the placeholders.
		'regex' => [
		],

	];

	
	
}
