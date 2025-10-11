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

namespace CitOmni\Http;

use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;


/**
 * Kernel: High-performance HTTP bootstrapper and dispatcher for CitOmni apps.
 *
 * Responsibilities:
 * - Boot the application in HTTP mode with deterministic config/service merges.
 *   1) Resolve [appRoot, configDir, publicPath] from a flexible $entryPath.
 *   2) Instantiate App($configDir, Mode::HTTP). Vendor baseline is merged with providers, app base,
 *      and environment overlays; precompiled caches under /var/cache are used when present.
 *   3) Never catch exceptions in Kernel; downstream failures are handled by the ErrorHandler service.
 *
 * - Apply environment defaults and wire services required early in the request lifecycle.
 *   1) Set timezone (cfg.locale.timezone, default "UTC") and charset (cfg.locale.charset, default "UTF-8").
 *   2) Define CITOMNI_PUBLIC_ROOT_URL (DEV allows auto-detect; non-DEV requires absolute cfg.http.base_url).
 *   3) Apply cfg.http.trusted_proxies to the Request service (enables proxy-aware IP/URL resolution).
 *   4) Install the HTTP ErrorHandler service early ... (reads cfg.error_handler once via its 
 *      constructor/options; no other service resolution).
 *
 * - Dispatch the HTTP request lifecycle.
 *   1) Start a single, top-level output buffer as early as possible to prevent partial output. This allows
 *      the ErrorHandler to fully replace the response on failures (status line + headers + body).
 *   2) Enforce maintenance via $app->maintenance->guard() (flag-first, deterministic behavior).
 *   3) Route and dispatch via $app->router->run() (404/405/5xx are delegated to ErrorHandler::httpError()).
 *   4) Optionally emit a DEV-friendly performance footer when ?_perf is present.
 *
 * Request lifecycle (order of operations):
 *   - Output buffer start -> boot() -> ErrorHandler install -> timezone & charset -> public root URL
 *     -> trusted proxies -> maintenance guard -> router run -> optional perf footer.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App - Container exposing read-only cfg and resolving services (service map may be cached under /var/cache).
 * - request (HTTP Request service) - Reads cfg.http.trusted_proxies; made proxy-aware by Kernel during boot.
 * - maintenance (Maintenance service) - Enforces maintenance mode based on a flag file and service policy. Kernel does not pass arguments.
 * - router (Router service) - Resolves the route (exact or regex with placeholders) and invokes controllers. On errors, delegates to ErrorHandler::httpError($status, $context) for guaranteed responses (404/405/500).
 * - \CitOmni\Http\Service\ErrorHandler - Guarantees "no blank page" for: Uncaught exceptions, Shutdown fatals (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), Router HTTP errors (404/405/5xx). Non-fatal PHP errors (warnings/notices/etc.) may optionally render in DEV via cfg.
 *
 * Configuration keys (relevant excerpts):
 * - cfg.locale.timezone (string) - Default: "UTC". Invalid values throw at boot.
 * - cfg.locale.charset (string) - Default: "UTF-8". If ini_set('default_charset', ...) fails, Kernel throws at boot.
 * - cfg.http.base_url (string; absolute URL, no trailing slash) - Required in non-DEV. In DEV, if missing/relative, Kernel auto-detects (optionally proxy-aware). Example: "https://www.example.com".
 * - cfg.http.trust_proxy (bool) - When true, auto-detection of CITOMNI_PUBLIC_ROOT_URL may honor X-Forwarded-* headers.
 * - cfg.http.trusted_proxies (string[] of IPs/CIDRs) - List of trusted proxies for the Request service (e.g., ["10.0.0.0/8"]).
 * - cfg.error_handler.render.trigger (int; PHP error bitmask) - Controls which non-fatal PHP errors trigger rendering (DEV convenience). Fatals are always rendered and are sanitized out of this mask even if misconfigured.
 * - cfg.error_handler.render.detail.level (int) - 0 = minimal client message (prod/stage), 1 = developer details (only active in 'dev').
 * - cfg.error_handler.render.detail.trace.{max_frames, max_arg_strlen, max_array_items, max_depth, ellipsis} - Bounded trace formatting when detail.level = 1 (and CITOMNI_ENVIRONMENT === 'dev').
 * - cfg.error_handler.render.force_error_reporting (int, optional) - Hard override of error_reporting() at handler install time (e.g., E_ALL in dev).
 * - cfg.error_handler.log.trigger (int; PHP error bitmask), cfg.error_handler.log.path (string),
 *   cfg.error_handler.log.max_bytes (int), cfg.error_handler.log.max_files (int) - Robust JSONL logs with size-based rotation and pruning. Router 404/405/5xx are always logged to dedicated files: http_router_404.jsonl, http_router_405.jsonl, http_router_5xx.jsonl.
 * - cfg.error_handler.templates.html (string), cfg.error_handler.templates.html_failsafe (string|null) - Optional plain-PHP templates for error pages. The handler falls back to a built-in minimal HTML if missing.
 * - cfg.error_handler.status_defaults.{exception, shutdown, php_error, http_error} (int) - Default HTTP status codes used by the handler when a specific mapping is not present.
 *
 * Error handling:
 * - Fail fast in Kernel:
 *     - Invalid timezone -> \RuntimeException.
 *     - Failed default charset -> \RuntimeException.
 *     - Unresolvable entry path / missing config dir -> \RuntimeException.
 *     - Non-DEV without absolute cfg.http.base_url -> \RuntimeException.
 * - No try/catch in Kernel. The ErrorHandler service is installed early and is responsible for:
 *     - Uncaught exceptions,
 *     - Shutdown fatals,
 *     - Router HTTP errors (404/405/5xx),
 *     ensuring a complete client response (HTML or JSON) and structured logs (JSONL).
 * - Output buffering:
 *     - Kernel starts one top-level output buffer before booting the App. This prevents accidental
 *       partial responses and enables the handler to clear buffers and fully replace the response.
 *
 * Typical usage:
 *
 *   // public/index.php
 *   declare(strict_types=1);
 *   define('CITOMNI_START_NS', hrtime(true));
 *   define('CITOMNI_ENVIRONMENT', 'dev');               // or 'prod' in production
 *   define('CITOMNI_PUBLIC_PATH', __DIR__);
 *   define('CITOMNI_APP_PATH', \dirname(__DIR__));
 *   require CITOMNI_APP_PATH . '/vendor/autoload.php';
 *   \CitOmni\Http\Kernel::run(__DIR__);                 // pass public/ as entry
 *
 * Examples:
 *
 *   // A) Boot from /config (equivalent resolution)
 *   \CitOmni\Http\Kernel::run(\dirname(__DIR__) . '/config');
 *
 *   // B) Production with explicit base URL (no trailing slash)
 *   // In /config/citomni_http_cfg.prod.php:
 *   return [
 *     'http' => ['base_url' => 'https://www.example.com', 'trust_proxy' => false],
 *   ];
 *
 *   // C) DEV auto-detect base URL behind a proxy
 *   // In /config/citomni_http_cfg.dev.php:
 *   return [
 *     'http' => ['trust_proxy' => true, 'trusted_proxies' => ['10.0.0.0/8']],
 *   ];
 *
 *   // D) ErrorHandler DEV overlay (render non-fatals + show details)
 *   // In /config/citomni_http_cfg.dev.php:
 *   return [
 *     'error_handler' => [
 *       'render' => [
 *         'trigger' => E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING
 *                    | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR
 *                    | E_DEPRECATED | E_USER_DEPRECATED,
 *         'detail'  => ['level' => 1],
 *         'force_error_reporting' => E_ALL,
 *       ],
 *     ],
 *   ];
 *
 * Failure:
 *
 *   // Non-DEV without absolute base_url:
 *   define('CITOMNI_ENVIRONMENT', 'prod');
 *   // Missing cfg.http.base_url -> \CitOmni\Http\Kernel::boot(...) throws \RuntimeException.
 *
 *   // Invalid timezone (cfg.locale.timezone):
 *   // date_default_timezone_set() fails -> \RuntimeException.
 *
 *   // Invalid charset (cfg.locale.charset):
 *   // ini_set('default_charset', ...) fails or mismatch -> \RuntimeException.
 *
 * Notes:
 * - CITOMNI_PUBLIC_ROOT_URL:
 *     - If pre-defined by the entrypoint or deploy tooling, Kernel respects it.
 *     - In 'dev': if cfg.http.base_url is absolute, it is used; else Kernel auto-detects (optionally
 *       honoring X-Forwarded-* when cfg.http.trust_proxy is true).
 *     - In non-dev: cfg.http.base_url MUST be an absolute URL, otherwise Kernel throws.
 *
 * - Performance footer:
 *     - When the query parameter ?_perf is present, Kernel emits a harmless HTML comment footer with timing,
 *       memory, and included file counts-useful for local diagnostics.
 */
final class Kernel {	
	

	/**
	 * Run the HTTP application: boot -> maintenance guard -> router -> optional perf footer.
	 *
	 * Notes:
	 * - Maintenance is enforced via $app->maintenance->guard() with no arguments to keep
	 *   flag-first, deterministic behavior.
	 * - The performance footer is emitted only when the request has ?_perf (DEV-friendly).
	 *
	 * @param string $entryPath See boot() for accepted forms.
	 * @param array<string,mixed> $opts Reserved; currently unused.
	 * @return void
	 * @throws \RuntimeException Propagated from boot()/maintenance/router on fatal errors.
	 */
	public static function run(string $entryPath, array $opts = []): void {

		// Start a single, top-level output buffer as early as possible.
		// Goal: prevent partial/fragmented output so ErrorHandler can clear buffers and emit a full response
		// (status line + headers + body) atomically. Disable implicit flush so nothing is sent prematurely.
		\ob_start();
		\ob_implicit_flush(false);

        // Accept either public/, config/, or app-root
		$app = self::boot($entryPath, $opts);

		// Deliberately call guard() with no arguments.
		// Rationale: The maintenance policy (enabled, allowlist, retry_after) is defined by the flag file
		// and the service itself falls back to cfg (maintenance.flag.default_retry_after) only if the flag
		// is missing or incomplete. Injecting values here (e.g., via index.php/$opts) could accidentally
		// override the flag, create environment-specific drift, and bind Kernel behavior to deployment-time
		// parameters. Keeping Kernel argument-free ensures deterministic, flag-first enforcement.
		$app->maintenance->guard();		

		// Router is a service provided by the HTTP package and mapped as 'router'
		$app->router->run();

		if (isset($_GET['_perf'])) {
			
			// Performance monitor in footer (harmless in HTML)
			// CITOMNI_START_NS now holds hrtime(true) in nanoseconds.
			// $startNs    = \defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : \hrtime(true);
			// $elapsedNs  = \hrtime(true) - $startNs;
			// $elapsedSec = $elapsedNs / 1_000_000_000;
			// $elapsedStr = \number_format($elapsedSec, 3, '.', ''); // e.g. "0.123"
			$elapsedStr = \sprintf('%.3f', ((($nowNs=\hrtime(true))) - (\defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs)) / 1_000_000_000);

			echo \PHP_EOL;
			echo '<!-- Execution time: ' . $elapsedStr . ' s. Current memory: ' . \memory_get_usage() . ' bytes. Peak memory: ' . \memory_get_peak_usage() . ' bytes. Included files: ' . \count(\get_included_files()) . ' -->';
			echo \PHP_EOL;
			echo '<!--';
			var_dump(\get_included_files());
			echo '-->';
		}

	}
	
	
	/**
	 * Boot the App in HTTP mode and apply environment defaults.
	 *
	 * Behavior:
	 * - Resolve [appRoot, configDir, publicPath] from $entryPath.
	 * - Instantiate App($configDir, Mode::HTTP).
	 * - Set timezone and default charset from cfg.locale.*.
	 * - Define CITOMNI_PUBLIC_ROOT_URL (see definePublicRootUrl()).
	 * - Apply cfg.http.trusted_proxies to Request (optional).
	 * - Install error handler from cfg.error_handler (optional).
	 *
	 * Notes:
	 * - Expects CITOMNI_APP_PATH to be defined by the entrypoint (used by App caches).
	 * - Kernel does not define CITOMNI_APP_PATH/CITOMNI_PUBLIC_PATH; only PUBLIC_ROOT_URL.
	 *
	 * @param string $entryPath Absolute path to /public, /config, or app root.
	 * @param array<string,mixed> $opts Reserved; currently unused.
	 * @return App
	 * @throws \RuntimeException On invalid timezone/charset or missing/invalid paths.
	 */
	public static function boot(string $entryPath, array $opts = []): App {
		
		// Resolve paths (app root, config dir, public path)
		[$appRoot, $configDir, $publicPath] = self::resolvePaths($entryPath);

		// 1) Construct the App container for HTTP mode
		$app = new App($configDir, Mode::HTTP);

		// 2) Error handler (optional)
		// If you ship a handler, expose a static ::install(array $cfg) method.
		// Reads exclusively from cfg.error_handler
		$app->errorHandler->install();

		// 3) Timezone
		$tz = (string)($app->cfg->locale->timezone ?? 'UTC');
		if (!@date_default_timezone_set((string)$tz)) {
			throw new \RuntimeException('Invalid timezone: ' . (string)$tz);
		}

		// 4) Charset
		$charset = (string)($app->cfg->locale->charset ?? 'UTF-8');
		if (!@ini_set('default_charset', $charset) || \ini_get('default_charset') !== $charset) {
			throw new \RuntimeException('Failed to set default charset to ' . $charset);
		}

		// 5) Define CITOMNI_PUBLIC_ROOT_URL (env-aware)
		self::definePublicRootUrl($app);

		// 6) Wire http.trusted_proxies into Request service
		// If http.trusted_proxies is defined in the configuration, inject it into
		// the Request service. This way we make sure that client IP resolution becomes proxy-aware
		// (i.e. behind load balancers or reverse proxies).
		$tp = $app->cfg->http->trusted_proxies ?? null; // null|array
		if (\is_array($tp)) {
			$app->request->setTrustedProxies($tp);
		}
	
		return $app;
	}

	/**
	 * Resolve appRoot, configDir and publicPath from a flexible entry path.
	 *
	 * Rules:
	 * - If $entry points to ".../public" (or a dir with index.php):
	 *   appRoot = dirname(entry), configDir = appRoot.'/config', publicPath = entry.
	 * - If $entry points to ".../config" (or contains citomni_http_cfg.php):
	 *   appRoot = dirname(entry), configDir = entry,
	 *   publicPath = appRoot.'/public' if it exists, else dirname($_SERVER['SCRIPT_FILENAME']).
	 * - If $entry points to app-root (has a "config" dir):
	 *   configDir = appRoot.'/config', publicPath = appRoot.'/public' if it exists, else dirname($_SERVER['SCRIPT_FILENAME']).
	 *
	 * All returned paths are normalized; realpath() is used when available.
	 *
	 * @param string $entryPath
	 * @return array{0:string,1:string,2:string} [appRoot, configDir, publicPath]
	 * @throws \RuntimeException If paths cannot be resolved or config dir is missing.
	 */
	private static function resolvePaths(string $entryPath): array {
		$in  = \rtrim($entryPath, '/\\');
		$abs = \realpath($in) ?: $in;

		// Case A: entry is public/
		$isPublic = (\basename($abs) === 'public') || \is_file($abs . '/index.php');
		if (\is_dir($abs) && $isPublic) {
			$appRoot   = \dirname($abs);
			$configDir = $appRoot . '/config';
			if (!\is_dir($configDir)) {
				throw new \RuntimeException("Config directory not found beside public: {$configDir}");
			}
			$publicPath = $abs;
			// Normalize
			$appRoot   = \realpath($appRoot)   ?: $appRoot;
			$configDir = \realpath($configDir) ?: $configDir;
			$publicPath= \realpath($publicPath)?: $publicPath;
			return [$appRoot, $configDir, $publicPath];
		}

		// Case B: entry is config/
		$isConfig = (\basename($abs) === 'config') || \is_file($abs . '/citomni_http_cfg.php');
		if (\is_dir($abs) && $isConfig) {
			$appRoot   = \dirname($abs);
			$configDir = $abs;
			$publicGuess = $appRoot . '/public';
			$publicPath  = \is_dir($publicGuess) ? $publicGuess : (\dirname($_SERVER['SCRIPT_FILENAME'] ?? $publicGuess));
			// Normalize
			$appRoot    = \realpath($appRoot)    ?: $appRoot;
			$configDir  = \realpath($configDir)  ?: $configDir;
			$publicPath = \realpath($publicPath) ?: $publicPath;
			return [$appRoot, $configDir, $publicPath];
		}

		// Case C: entry is app-root (has /config)
		if (\is_dir($abs) && \is_dir($abs . '/config')) {
			$appRoot   = $abs;
			$configDir = $abs . '/config';
			$publicGuess = $abs . '/public';
			$publicPath  = \is_dir($publicGuess) ? $publicGuess : (\dirname($_SERVER['SCRIPT_FILENAME'] ?? $publicGuess));
			// Normalize
			$appRoot    = \realpath($appRoot)    ?: $appRoot;
			$configDir  = \realpath($configDir)  ?: $configDir;
			$publicPath = \realpath($publicPath) ?: $publicPath;
			return [$appRoot, $configDir, $publicPath];
		}

		throw new \RuntimeException("Cannot resolve paths from entry: {$entryPath}");
	}


	/**
	 * Define the CITOMNI_PUBLIC_ROOT_URL constant.
	 *
	 * Strategy:
	 * - If already defined (e.g., by index.php or a deploy step), respect it.
	 * - DEV environment:
	 *   - If cfg.http.base_url is an absolute URL, use it.
	 *   - Else auto-detect from server variables (optionally proxy-aware).
	 * - Non-DEV environments:
	 *   - Require an absolute cfg.http.base_url; otherwise throw.
	 *
	 * Proxy awareness:
	 * - Honours cfg.http.trust_proxy during auto-detection (X-Forwarded-*).
	 *
	 * @param \CitOmni\Kernel\App $app Current application.
	 * @throws \RuntimeException If no absolute base_url is configured in non-DEV env.
	 * @return void
	 */
	private static function definePublicRootUrl(\CitOmni\Kernel\App $app): void {
		// Respect pre-defined value (set early by index.php or deploy tool)
		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			return;
		}

		// Config wrapper (read-only) and environment (default to 'prod')
		$cfg = $app->cfg;
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';

		// Config base_url (trim trailing slash), or empty string if not set
		$cfgBase = (
			isset($cfg->http) && isset($cfg->http->base_url)
		) ? \rtrim((string)$cfg->http->base_url, '/') : '';

		// Whether to trust proxy headers (X-Forwarded-*) for URL detection
		$trustProxy = (
			isset($cfg->http) && isset($cfg->http->trust_proxy)
		) ? (bool)$cfg->http->trust_proxy : false;

		// Check if cfgBase is a fully qualified URL
		$isAbsolute = ($cfgBase !== '' && \preg_match('#^https?://#i', $cfgBase) === 1);

		if ($env === 'dev') {
			// Dev: allow flexible setup
			// - Use absolute config base_url if provided
			// - Else auto-detect from server variables
			\define('CITOMNI_PUBLIC_ROOT_URL', $isAbsolute ? $cfgBase : self::autoDetectBaseUrl($trustProxy));
			return;
		}

		if ($isAbsolute) {
			// Non-dev: absolute base_url is mandatory, use it as-is
			\define('CITOMNI_PUBLIC_ROOT_URL', $cfgBase);
			return;
		}

		// Non-dev without absolute base_url -> fatal error
		throw new \RuntimeException(
			"Missing or invalid http.base_url for environment '{$env}'. " .
			"Set a full URL (e.g. https://www.example.com) in /config/citomni_http_cfg.{$env}.php"
		);
	}


	/**
	 * Auto-detect the absolute public root URL (no trailing slash).
	 *
	 * - Scheme: HTTPS if server says so, or if trust_proxy + X-Forwarded-Proto = https.
	 * - Host: HTTP_HOST (or X-Forwarded-Host when trust_proxy), fallback SERVER_NAME.
	 * - Path: derived from SCRIPT_NAME; strips a trailing "/public" segment if present.
	 *
	 * Security:
	 * - X-Forwarded-* headers are trusted only when cfg.http.trust_proxy is truthy.
	 *
	 * @param bool $trustProxy Whether X-Forwarded-* may be trusted.
	 * @return string Absolute URL without trailing slash.
	 */
	private static function autoDetectBaseUrl(bool $trustProxy): string {
		// 1) Scheme
		$xfProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
		$isHttps = (
			(!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off')
			|| ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
			|| ($trustProxy && \strtolower($xfProto) === 'https')
		);
		$scheme = $isHttps ? 'https' : 'http';

		// 2) Host
		$host = (string)($_SERVER['HTTP_HOST'] ?? '');
		if ($trustProxy && isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
			$parts = \explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST']);
			$host = \trim($parts[0] ?? $host);
		}
		if ($host === '') {
			$host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
		}
		$host = (string)\preg_replace('/[^a-zA-Z0-9\.\-:]/', '', $host);

		// 3) Base path (from script)
		$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php'));
		$basePath = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');

		// SPECIAL: strip trailing "/public" (supports both app-root webroot and public/ webroot)
		if ($basePath !== '' && \substr($basePath, -7) === '/public') {
			$basePath = \substr($basePath, 0, -7);
			// normalize to empty when we just removed the only segment
			$basePath = ($basePath === '') ? '' : \rtrim($basePath, '/');
		}

		// 4) Optional reverse-proxy mount path (honour a proxy path prefix if provided)
		if ($trustProxy && isset($_SERVER['HTTP_X_FORWARDED_PREFIX'])) {
			$xfPrefix = \trim((string)$_SERVER['HTTP_X_FORWARDED_PREFIX']);
			if ($xfPrefix !== '') {
				if ($xfPrefix[0] !== '/') {
					$xfPrefix = '/' . $xfPrefix;
				}
				$xfPrefix = \rtrim($xfPrefix, '/');
				if ($basePath === '' || \strpos($basePath, $xfPrefix) !== 0) {
					$basePath = $xfPrefix . $basePath;
				}
			}
		}

		return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
	}

}
