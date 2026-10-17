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

use CitOmni\Http\Policy\FetchMetadataPolicy;
use CitOmni\Kernel\App;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Mode;
use CitOmni\Kernel\Runtime;

/**
 * Kernel: Entry point of HTTP mode that boots the App and dispatches one request.
 *
 * Turns an entry path into a booted App and a dispatched request. It owns the order of the
 * steps in between, so that the ErrorHandler service takes over right after the App exists,
 * and a request the kernel refuses reaches no controller. Building cfg and maps belongs to
 * App, process-wide PHP settings to Runtime, logging and rendering failures to the
 * ErrorHandler service, and dispatch to the Router.
 *
 * Rules:
 * - Catch no exceptions: Once boot() has installed the ErrorHandler service, a failure belongs
 *   to it.
 * - End a request that the kernel refuses through ErrorHandler::httpError(), never with a
 *   response of its own.
 */
class Kernel {
	

	/**
	 * Boot the App, check the request and dispatch it through the Router.
	 *
	 * Handles one request from start to end. It starts an output buffer before anything else,
	 * so that the ErrorHandler service can still discard what the request has printed and send
	 * a complete error response in its place.
	 *
	 * Behavior:
	 * - Runs these steps in order; a step that ends the request skips the rest:
	 *     1) boot().
	 *     2) 413 through httpError(), with context source 'request' and reason
	 *        post_max_size_exceeded, when Request::exceedsPostMaxSize() is true.
	 *     3) The Fetch Metadata check; see guardFetchMetadata().
	 *     4) guard() of the maintenance service.
	 *     5) run() of the router service.
	 * - When the query string has _perf and the router returns, appends an HTML comment with the
	 *   seconds since CITOMNI_START_NS (0 when it is undefined), the current and peak memory and
	 *   the number of included files. Unless CITOMNI_ENVIRONMENT is 'prod', a second HTML comment
	 *   lists the included files.
	 *
	 * Notes:
	 * - Any client can add _perf. Outside 'prod', the file list it gets holds absolute paths.
	 * - The _perf comments follow whatever the action printed, also in a response that is not HTML.
	 *
	 * @param  string  $entryPath  Passed to boot().
	 * @param  array<string,mixed>  $opts  Passed to boot().
	 * @return void
	 * @throws \RuntimeException  When boot() fails, or when cfg security.fetch_metadata is invalid.
	 * @see \CitOmni\Http\Kernel::boot()  Accepted entry paths and boot failures.
	 */
	public static function run(string $entryPath, array $opts = []): void {

		// Before anything can print: The ErrorHandler service can then still discard the output
		// and send a complete response with its own status line and headers. Implicit flush is
		// off, so nothing reaches the client early.
		\ob_start();
		\ob_implicit_flush(false);

		$app = self::boot($entryPath, $opts);

		// PHP may discard both POST fields and uploads when the whole body is too large.
		// Reject before maintenance/router/controller work can misreport a missing CSRF token.
		if ($app->request->exceedsPostMaxSize()) {
			$app->errorHandler->httpError(413, [
				'source' => 'request',
				'reason' => 'post_max_size_exceeded',
				'title' => 'Content Too Large',
				'message' => 'The request body exceeds the maximum allowed size.',
				'content_length' => $app->request->contentLength(),
				'post_max_size' => $app->request->postMaxSize(),
			]);
		}

		// Reject disallowed browser contexts before maintenance or controller work.
		self::guardFetchMetadata($app);

		// Deliberately forwards nothing from the entry point or $opts, so that no deployment
		// parameter can override the maintenance flag or drift from it.
		$app->maintenance->guard();		

		$app->router->run();


		if (isset($_GET['_perf'])) {

			// Assumes the front controller defines CITOMNI_START_NS from hrtime(true) as its
			// first statement, so the time covers boot and dispatch.
			//
			// The summary is safe for production: It holds aggregate timing, memory and
			// included-file counts, but no filesystem paths.
			$nowNs = \hrtime(true);
			$startNs = \defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs;
			$elapsedStr = \sprintf('%.3f', ($nowNs - $startNs) / 1_000_000_000);

			echo \PHP_EOL;
			echo '<!-- Execution time: ' . $elapsedStr
				. ' s. Current memory: ' . \memory_get_usage()
				. ' bytes. Peak memory: ' . \memory_get_peak_usage()
				. ' bytes. Included files: ' . \count(\get_included_files())
				. ' -->';
			echo \PHP_EOL;

			// Outside prod, the included-file list helps with deployment and autoload
			// diagnostics. It holds absolute paths, so prod never gets it.
			if (\CITOMNI_ENVIRONMENT !== 'prod') {
				echo '<!--';
				var_dump(\get_included_files());
				echo '-->';
			}
		}

	}
	
	
	/**
	 * Build the App in HTTP mode and prepare the process for a request.
	 *
	 * Installs the ErrorHandler service as soon as the App exists, so that every later uncaught
	 * failure reaches it. Dispatches nothing; run() does.
	 *
	 * Behavior:
	 * - Runs these steps in order:
	 *     1) Builds App in Mode::HTTP from the config directory that $entryPath leads to.
	 *     2) Calls install() on the errorHandler service. A failure in step 1 happens before
	 *        that, so the ErrorHandler service does not report it.
	 *     3) Applies Runtime::configure() to the App's cfg.
	 *     4) Requires the PHP intl extension.
	 *     5) Defines CITOMNI_PUBLIC_ROOT_URL; see definePublicRootUrl().
	 *     6) Passes cfg http.trusted_proxies to Request::setTrustedProxies() when it is a
	 *        non-empty list.
	 *
	 * Notes:
	 * - Assumes CITOMNI_APP_PATH is defined. Kernel defines only CITOMNI_PUBLIC_ROOT_URL.
	 *
	 * @param  string  $entryPath  Path to the app's public directory, its config directory or the app root.
	 * @param  array<string,mixed>  $opts  Not read.
	 * @return App
	 * @throws \RuntimeException  When $entryPath leads to no config directory, when the PHP intl
	 *                            extension is missing, when CITOMNI_ENVIRONMENT is not 'dev' and
	 *                            neither CITOMNI_PUBLIC_ROOT_URL is defined nor cfg http.base_url is
	 *                            absolute, or from App::__construct() and Runtime::configure().
	 * @see \CitOmni\Http\Kernel::resolvePaths()  How $entryPath is resolved.
	 * @see \CitOmni\Kernel\Runtime::configure()  The process-wide settings and their failures.
	 */
	public static function boot(string $entryPath, array $opts = []): App {
		
		[$appRoot, $configDir, $publicPath] = self::resolvePaths($entryPath);

		$app = new App($configDir, Mode::HTTP);

		$app->errorHandler->install();

		Runtime::configure($app->cfg);

		// HTTP needs intl for localized dates and numbers. Runtime::configure() sets the ICU
		// locale only when the extension is loaded, so the requirement is checked here.
		if (!\class_exists(\Locale::class)) {
			throw new \RuntimeException('PHP intl extension is required for localized dates/numbers.');
		}

		self::definePublicRootUrl($app);

		// An empty list is a Cfg node, not an array, and leaves Request as it is.
		$tp = $app->cfg->http->trusted_proxies;
		if (\is_array($tp)) {
			$app->request->setTrustedProxies($tp);
		}
	
		return $app;
	}


	/**
	 * Refuse a request whose browser context cfg security.fetch_metadata does not allow.
	 *
	 * Behavior:
	 * - Does nothing unless security.fetch_metadata.enabled is true. A missing
	 *   security.fetch_metadata counts as off.
	 * - When on, the response gets "Vary: Sec-Fetch-Site, Sec-Fetch-Mode, Sec-Fetch-Dest",
	 *   added to any Vary header already set, whatever the outcome.
	 * - A Sec-Fetch-Site of "same-origin" or "none", in any letter case, is allowed. Every other
	 *   request is allowed only when FetchMetadataPolicy::allows() says so.
	 * - A refused request ends in a 403 through httpError(), with context source 'request' and
	 *   reason fetch_metadata_rejected. It never returns to dispatch.
	 * - Starts no session, reads no request body and logs only refusals.
	 *
	 * @param  App  $app  Booted HTTP application.
	 * @return void
	 * @throws \RuntimeException  When security.fetch_metadata is not a map or its enabled is not a
	 *                            boolean, or when FetchMetadataPolicy::allows() rejects the rest of it.
	 * @see \CitOmni\Http\Policy\FetchMetadataPolicy::allows()  Which other requests are allowed.
	 */
	private static function guardFetchMetadata(App $app): void {
		// ?? on an own key, deliberately: A compiled cfg cache may predate the baseline of
		// security.fetch_metadata, and failing here would also fail the request that rebuilds
		// the cache. The keys inside the node come with the same baseline and are read directly.
		$cfg = $app->cfg->security->fetch_metadata ?? null;
		if ($cfg === null) {
			return;
		}
		if (!$cfg instanceof Cfg || !\is_bool($cfg->enabled)) {
			throw new \RuntimeException('security.fetch_metadata must be a map with a boolean "enabled".');
		}
		if (!$cfg->enabled) {
			return;
		}

		// The decision depends on these request headers, also when the request is allowed.
		\header('Vary: Sec-Fetch-Site, Sec-Fetch-Mode, Sec-Fetch-Dest', false);
		$site = \strtolower((string)$app->request->header('Sec-Fetch-Site', ''));
		if ($site === 'same-origin' || $site === 'none') {
			return;
		}
		if ((new FetchMetadataPolicy($app))->allows($site)) {
			return;
		}

		$app->errorHandler->httpError(403, [
			'source'  => 'request',
			'reason'  => 'fetch_metadata_rejected',
			'title'   => 'Forbidden',
			'message' => 'The request context is not allowed for this resource.',
		]);

		// httpError() never returns, but the errorHandler service id may map to another class.
		// A refused request must not resume dispatch whatever that class does.
		exit;
	}


	/**
	 * Resolve the app root, the config directory and the public directory from an entry path.
	 *
	 * Behavior:
	 * - The first match wins:
	 *     1) A directory named public, or one that holds index.php, is the public directory.
	 *        The app root is its parent, whose config directory must exist.
	 *     2) A directory named config, or one that holds citomni_http_cfg.php, is the config
	 *        directory. The app root is its parent.
	 *     3) A directory that holds a config directory is the app root.
	 * - In 2) and 3), the public directory is the app root's public directory when it exists,
	 *   otherwise the directory of SCRIPT_FILENAME, or the app root without one.
	 * - Trailing slashes and backslashes of $entryPath are ignored. Each returned path is its
	 *   realpath() when that resolves, otherwise the path as built.
	 *
	 * Notes:
	 * - An app root that holds index.php matches 1), so its parent is taken as the app root.
	 *
	 * @param  string  $entryPath  Path to the public directory, the config directory or the app root.
	 * @return array{0:string,1:string,2:string}  App root, config directory and public directory.
	 * @throws \RuntimeException  When no case matches, or in 1) when the parent has no config directory.
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
			$appRoot    = \realpath($appRoot)    ?: $appRoot;
			$configDir  = \realpath($configDir)  ?: $configDir;
			$publicPath = \realpath($publicPath) ?: $publicPath;
			return [$appRoot, $configDir, $publicPath];
		}

		throw new \RuntimeException("Cannot resolve paths from entry: {$entryPath}");
	}


	/**
	 * Define CITOMNI_PUBLIC_ROOT_URL, the absolute public URL of the app without a trailing slash.
	 *
	 * Behavior:
	 * - Keeps a CITOMNI_PUBLIC_ROOT_URL that is already defined, whatever its value.
	 * - Otherwise, cfg http.base_url without its trailing slashes counts as absolute when it
	 *   starts with http:// or https://, in any letter case. A missing http.base_url counts as
	 *   empty.
	 *     - When CITOMNI_ENVIRONMENT is 'dev': The absolute http.base_url, otherwise the URL that
	 *       autoDetectBaseUrl() derives from the request, with cfg http.trust_proxy.
	 *     - In every other environment, an undefined CITOMNI_ENVIRONMENT included: The absolute
	 *       http.base_url, otherwise a \RuntimeException.
	 *
	 * @param  \CitOmni\Kernel\App  $app  App whose cfg holds http.base_url and http.trust_proxy.
	 * @return void
	 * @throws \RuntimeException  When CITOMNI_PUBLIC_ROOT_URL is undefined, CITOMNI_ENVIRONMENT is not
	 *                            'dev' and http.base_url is not absolute.
	 * @see \CitOmni\Http\Kernel::autoDetectBaseUrl()  The URL used in dev without an absolute base URL.
	 */
	private static function definePublicRootUrl(\CitOmni\Kernel\App $app): void {
		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			return;
		}

		$cfg = $app->cfg;
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';

		// ?? on an own key, deliberately: A compiled cfg cache may predate the http.base_url
		// baseline, and failing here would also fail the request that rebuilds the cache.
		$cfgBase = \rtrim((string)($cfg->http->base_url ?? ''), '/');

		$trustProxy = (bool)$cfg->http->trust_proxy;

		$isAbsolute = ($cfgBase !== '' && \preg_match('#^https?://#i', $cfgBase) === 1);

		if ($env === 'dev') {
			\define('CITOMNI_PUBLIC_ROOT_URL', $isAbsolute ? $cfgBase : self::autoDetectBaseUrl($trustProxy));
			return;
		}

		if ($isAbsolute) {
			\define('CITOMNI_PUBLIC_ROOT_URL', $cfgBase);
			return;
		}

		throw new \RuntimeException(
			"Missing or invalid http.base_url for environment '{$env}'. " .
			"Set a full URL (e.g. https://www.example.com) in /config/citomni_http_cfg.{$env}.php"
		);
	}


	/**
	 * Derive the app's absolute public URL from the current request.
	 *
	 * Behavior:
	 * - Scheme: https when the HTTPS server variable is non-empty and not "off", when the server
	 *   port is 443, or, with $trustProxy, when X-Forwarded-Proto is https. Otherwise http.
	 * - Host: With $trustProxy and an X-Forwarded-Host header, its first entry; otherwise the
	 *   Host header. When that is empty, SERVER_NAME, or localhost without one. Every character
	 *   other than letters, digits, dot, hyphen and colon is removed.
	 * - Path: The directory of SCRIPT_NAME, or of PHP_SELF without it, minus a trailing /public.
	 *   With $trustProxy, a non-empty X-Forwarded-Prefix is put in front, unless the path
	 *   already starts with it.
	 *
	 * Notes:
	 * - $trustProxy trusts the X-Forwarded headers of every client; the peer is not checked
	 *   against cfg http.trusted_proxies. Without it, the host still comes from a header that
	 *   the client sends.
	 *
	 * @param  bool  $trustProxy  Whether the X-Forwarded headers are used.
	 * @return string  Absolute URL without a trailing slash.
	 */
	private static function autoDetectBaseUrl(bool $trustProxy): string {
		$xfProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
		$isHttps = (
			(!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off')
			|| ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
			|| ($trustProxy && \strtolower($xfProto) === 'https')
		);
		$scheme = $isHttps ? 'https' : 'http';

		$host = (string)($_SERVER['HTTP_HOST'] ?? '');
		if ($trustProxy && isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
			$parts = \explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST']);
			$host = \trim($parts[0] ?? $host);
		}
		if ($host === '') {
			$host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
		}
		$host = (string)\preg_replace('/[^a-zA-Z0-9\.\-:]/', '', $host);

		$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php'));
		$basePath = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');

		// With the web root at the app root, the script runs below /public, but the app's URL is
		// the directory above it.
		if ($basePath !== '' && \substr($basePath, -7) === '/public') {
			$basePath = \substr($basePath, 0, -7);
			$basePath = ($basePath === '') ? '' : \rtrim($basePath, '/');
		}

		// A reverse proxy that mounts the app below a path sends that path as X-Forwarded-Prefix.
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
