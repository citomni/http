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

namespace CitOmni\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;
use CitOmni\Kernel\Support\AppInfo;

/**
 * SystemController: HTTP adapter for operations and diagnostics endpoints.
 *
 * Gives monitors, deployment tooling and developers small endpoints for liveness, runtime
 * facts, caches and maintenance mode. It shapes the answers and guards the actions; verifying
 * a signed request belongs to WebhooksAuth, the cache files to the App, the maintenance state
 * to the Maintenance service, and the application snapshot with its secret masking to AppInfo.
 *
 * Rules:
 * - Send no-cache headers with every answer.
 * - Change the App's cache files, maintenance mode or other application state only after
 *   WebhooksAuth::requireOrAbort(PROTECTED_FAIL_STATUS) has accepted the request. What
 *   verification itself records, such as a used nonce, belongs to WebhooksAuth.
 * - Answer a request that a guard rejects with 404, as for a missing route, so the endpoint
 *   is not disclosed.
 * - An action without a guard answers every client: Never put filesystem paths, secrets or
 *   credentials in its answer.
 */
class SystemController extends BaseController {

	/** Status of a rejected protected request; 404 makes the endpoint look like a missing route. */
	private const PROTECTED_FAIL_STATUS = 404;

	/**
	 * Run setup and guards that every action needs.
	 *
	 * Notes:
	 * - Holds no guard, so an action without a guard of its own is exactly as exposed as its
	 *   route. A guard added here applies to every action.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Controller\BaseController::__construct()  Calls it before every action.
	 */
	protected function init(): void {
		// Intentionally empty: No implicit I/O before an action.
	}
	


	/**
	 * Render the application information page.
	 *
	 * Behavior:
	 * - 404 through httpError() when CITOMNI_ENVIRONMENT is defined and is not dev.
	 * - Otherwise renders the route's template with noindex and no-cache headers and the AppInfo
	 *   snapshot, including the dev, stage and prod cfg projections.
	 * - Secrets in the snapshot are masked unless appInfoUnredactedRequested() is true.
	 *
	 * Notes:
	 * - Requires template_file and template_layer on its route.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Support\AppInfo::snapshot()  The snapshot, its secret masking and its failures.
	 */
	public function appinfoHtml(): void {
		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
			$this->app->errorHandler->httpError(404, ['reason' => 'not_found']);
			return;
		}

		$appInfo = new AppInfo($this->app);
		$snapshot = $appInfo->snapshot(
			unredacted: $this->appInfoUnredactedRequested(),
			includeEnvironmentConfigs: true
		);

		$this->renderAppInfo($snapshot);
	}


	/**
	 * Answer the application information as JSON.
	 *
	 * Behavior:
	 * - 404 through httpError() when CITOMNI_ENVIRONMENT is defined and is not dev.
	 * - Otherwise 200 with no-cache headers and the AppInfo snapshot, including the dev, stage
	 *   and prod cfg projections.
	 * - Secrets in the snapshot are masked unless appInfoUnredactedRequested() is true.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Support\AppInfo::snapshot()  The snapshot, its secret masking and its failures.
	 */
	public function appinfoJson(): void {
		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
			$this->app->errorHandler->httpError(404, ['reason' => 'not_found']);
			return;
		}

		$appInfo = new AppInfo($this->app);
		$snapshot = $appInfo->snapshot(
			unredacted: $this->appInfoUnredactedRequested(),
			includeEnvironmentConfigs: true
		);

		$this->app->response->jsonNoCache($snapshot, false);
	}


	/**
	 * Check whether the request asks for unredacted AppInfo output.
	 *
	 * Behavior:
	 * - False unless CITOMNI_ENVIRONMENT is defined and is dev, whatever the calling action
	 *   checked.
	 * - In dev, true when the query value raw or unredacted is a scalar that
	 *   FILTER_VALIDATE_BOOL reads as true, such as 1, true, on or yes. An array value counts
	 *   as false.
	 *
	 * @return bool
	 */
	private function appInfoUnredactedRequested(): bool {
		if (!\defined('CITOMNI_ENVIRONMENT') || \CITOMNI_ENVIRONMENT !== 'dev') {
			return false;
		}

		$raw = $this->app->request->get('raw');
		if (\is_scalar($raw) && (bool)\filter_var($raw, \FILTER_VALIDATE_BOOL)) {
			return true;
		}

		$unredacted = $this->app->request->get('unredacted');

		return \is_scalar($unredacted)
			&& (bool)\filter_var($unredacted, \FILTER_VALIDATE_BOOL);
	}



	/**
	 * Render the route's template with an AppInfo snapshot and the page texts.
	 *
	 * Behavior:
	 * - Sends noindex and no-cache headers.
	 * - The canonical URL is /_system/appinfo.html under CITOMNI_PUBLIC_ROOT_URL, or under cfg
	 *   http.base_url when the constant is not defined. The page's route must have that path.
	 *
	 * @param  array<string,mixed>  $snapshot  AppInfo snapshot, passed to the template as appInfo.
	 * @return void
	 */
	private function renderAppInfo(array $snapshot): void {
		$this->app->response->noIndex();

		$canonicalBase = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? (string)\CITOMNI_PUBLIC_ROOT_URL
			: (string)$this->app->cfg->http->base_url;
		$canonical = \rtrim($canonicalBase, '/') . '/_system/appinfo.html';

		$this->app->tplEngine->render($this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'], [
			'noindex'          => 1,
			'canonical'        => $canonical,
			'meta_title'       => 'Application information',
			'meta_description' => 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'       => 'READY',
			'badge_variant'    => 'badge--success',
			'title'            => 'Application information',
			'subtitle'         => 'CitOmni HTTP is up and running. All systems go.',
			'lead_text'        => 'Green lights across the board. CitOmni is ready for your development!',
			'status_code'      => '200 OK',
			'primary_href'     => 'https://github.com/citomni/http#readme',
			'primary_target'   => '_blank',
			'primary_label'    => 'Open README',
			'secondary_href'   => 'https://github.com/citomni/http/releases',
			'secondary_target' => '_blank',
			'secondary_label'  => 'Changelog',
			'tertiary_href'    => 'https://github.com/citomni/http/issues/new/choose',
			'tertiary_target'  => '_blank',
			'tertiary_label'   => 'Report issue',
			'year'             => \date('Y'),
			'owner'            => 'CitOmni.com',
			'appInfo'          => $snapshot,
		]);
	}


	/**
	 * Answer the client IP address as the Request service resolves it.
	 *
	 * Helps to check proxy and load balancer settings.
	 *
	 * Behavior:
	 * - 200 with {"ip": "..."}.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Request::ip()  How the address is resolved, and its "unknown" value.
	 */
	public function clientIp(): void {
		$this->app->response->noCache();
		$ip = $this->app->request->ip();
		$this->app->response->jsonStatus([
			'ip' => $ip,
		], 200);		
	}


	/**
	 * Answer a plain text liveness signal.
	 *
	 * Behavior:
	 * - 200 with "OK " and the current UTC time, such as "OK 2025-10-16T22:31:00Z".
	 *
	 * @return void
	 */
	public function ping(): void {
		$this->app->response->noCache();
		$this->app->response->text('OK ' . \gmdate('Y-m-d\TH:i:s\Z'), 200);
	}
	
	 
	/**
	 * Answer runtime facts of the PHP process as JSON.
	 *
	 * Behavior:
	 * - 200 with php_version, php_sapi, environment, opcache_enabled, opcache
	 *   (validate_timestamps, revalidate_freq, file_update_protection, revalidate_path, use_cwd,
	 *   reset_available, invalidate_available), realpath_cache_ttl, server_time_utc and timezone.
	 *     - environment is CITOMNI_ENVIRONMENT, or "unknown" when it is not defined.
	 *     - opcache_enabled is true only when opcache.enable is on and opcache_get_status() exists.
	 *     - server_time_utc is ISO 8601; timezone is PHP's default timezone.
	 * - Reads only the PHP process: No database, network or filesystem access.
	 *
	 * @return void
	 */
	public function health(): void {
		$this->app->response->noCache();

		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'unknown';
		$tz  = \date_default_timezone_get() ?: 'UTC';

		$iniEnabled = (string)\ini_get('opcache.enable') !== '' ? (bool)\ini_get('opcache.enable') : false;
		$opcacheEnabled = \function_exists('opcache_get_status') && $iniEnabled;

		$this->app->response->jsonStatus([
			'php_version'     => \PHP_VERSION,
			'php_sapi'        => \PHP_SAPI,
			'environment'     => $env,
			'opcache_enabled' => $opcacheEnabled,
			'opcache' => [
				'validate_timestamps' => (bool)\ini_get('opcache.validate_timestamps'),
				'revalidate_freq' => (int)\ini_get('opcache.revalidate_freq'),
				'file_update_protection' => (int)\ini_get('opcache.file_update_protection'),
				'revalidate_path' => (bool)\ini_get('opcache.revalidate_path'),
				'use_cwd' => (bool)\ini_get('opcache.use_cwd'),
				'reset_available' => \function_exists('opcache_reset'),
				'invalidate_available' => \function_exists('opcache_invalidate'),
			],
			'realpath_cache_ttl' => (int)\ini_get('realpath_cache_ttl'),
			'server_time_utc' => \gmdate('c'),
			'timezone' => $tz,
		], 200);
	}


	/**
	 * Answer the framework and app version markers as JSON.
	 *
	 * Behavior:
	 * - 200 with {"citomni": ..., "app": ...}: The constants CITOMNI_VERSION and APP_VERSION as
	 *   strings, each null when it is not defined.
	 *
	 * @return void
	 */
	public function version(): void {
		$this->app->response->noCache();

		$citomni = \defined('CITOMNI_VERSION') ? (string)\CITOMNI_VERSION : null;
		$app     = \defined('APP_VERSION') ? (string)\APP_VERSION : null;

		$this->app->response->jsonStatus([
			'citomni' => $citomni,
			'app'     => $app,
		], 200);
	}


	/**
	 * Answer the server time as JSON.
	 *
	 * Helps to detect drift between a client's clock and the server's, such as before signing
	 * a webhook request with a timestamp.
	 *
	 * Behavior:
	 * - 200 with time_utc and time_local in ISO 8601, and timezone, PHP's default timezone.
	 *
	 * @return void
	 */
	public function time(): void {
		$this->app->response->noCache();

		$this->app->response->jsonStatus([
			'time_utc'   => \gmdate('c'),
			'time_local' => \date('c'),
			'timezone'   => \date_default_timezone_get() ?: 'UTC',
		], 200);
	}


	/**
	 * Answer selected fields of the request as JSON in dev and stage.
	 *
	 * Helps to check X-Forwarded-* headers and load balancer behavior.
	 *
	 * Behavior:
	 * - 404 through httpError() unless CITOMNI_ENVIRONMENT is dev or stage; an undefined
	 *   CITOMNI_ENVIRONMENT counts as prod.
	 * - Otherwise 200 with remote_addr, forwarded, x_forwarded_for, x_forwarded_host,
	 *   x_forwarded_proto, user_agent, method, host and uri, each the raw server value or ""
	 *   when it is absent.
	 *
	 * @return void
	 */
	public function requestEcho(): void {
		$this->app->response->noCache();

		$env = \defined('CITOMNI_ENVIRONMENT') ? \CITOMNI_ENVIRONMENT : 'prod';
		if ($env !== 'dev' && $env !== 'stage') {
			$this->app->errorHandler->httpError(404, ['title' => 'Not Found']);
			return;
		}

		$server = $_SERVER;
		$out = [
			'remote_addr'        => (string)($server['REMOTE_ADDR'] ?? ''),
			'forwarded'          => (string)($server['HTTP_FORWARDED'] ?? ''),
			'x_forwarded_for'    => (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''),
			'x_forwarded_host'   => (string)($server['HTTP_X_FORWARDED_HOST'] ?? ''),
			'x_forwarded_proto'  => (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''),
			'user_agent'         => (string)($server['HTTP_USER_AGENT'] ?? ''),
			'method'             => (string)($server['REQUEST_METHOD'] ?? ''),
			'host'               => (string)($server['HTTP_HOST'] ?? ''),
			'uri'                => (string)($server['REQUEST_URI'] ?? ''),
		];

		$this->app->response->jsonStatus($out, 200);
	}


	/**
	 * Remove the App's HTTP cache files and reset the web server's OPcache.
	 *
	 * For a deploy where OPcache does not validate timestamps. It runs in the web server's PHP
	 * process, so the invalidation and the reset reach the web server's OPcache, which a call
	 * from the CLI cannot.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted. No cache is touched.
	 * - Otherwise removes the cache files through App::clearCache(), then invalidates the extra
	 *   files that an optional JSON body {"paths": ["/abs/file.php", ...]} lists, then calls
	 *   opcache_reset(), and answers 200 with:
	 *     - removed: The cache files removed; empty when none was present.
	 *     - invalidated: The extra files invalidated in OPcache. They are never removed.
	 *     - opcache: invalidate_available, invalidation_failed (extra files that OPcache did not
	 *       invalidate), reset_available, and reset_succeeded (null without opcache_reset()).
	 *     - ok: Always true; failed: Always empty.
	 * - An extra path that does not start with the directory separator or is not an existing
	 *   file is skipped. Without opcache_invalidate() all extra paths are skipped.
	 * - When App::clearCache() throws, its exception ends the request before any extra file is
	 *   invalidated or OPcache is reset.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 * @see \CitOmni\Kernel\App::clearCache()  Which files are removed, and its failures.
	 */
	public function resetCache(): void {
		$this->app->response->noCache();
		$raw = $this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		// -- 1. Remove the HTTP cache files ------------------------------
		$removed = \array_values(\array_filter($this->app->clearCache()));

		// -- 2. Invalidate the extra files from the JSON body ------------
		$invalidated = [];
		$invalidationFailed = [];
		$canInvalidate = \function_exists('opcache_invalidate');

		$body = \json_decode($raw, true);
		$body = \is_array($body) ? $body : [];
		if ($canInvalidate && !empty($body['paths']) && \is_array($body['paths'])) {
			foreach ($body['paths'] as $p) {
				$p = (string)$p;

				// Security: Only allow absolute paths to avoid cwd tricks
				if ($p === '' || $p[0] !== \DIRECTORY_SEPARATOR || !\is_file($p)) {
					continue;
				}

				if (@\opcache_invalidate($p, true)) {
					$invalidated[] = $p;
				} else {
					$invalidationFailed[] = $p;
				}
			}
		}

		// -- 3. Reset the web server's OPcache ---------------------------
		$canReset = \function_exists('opcache_reset');
		$resetSucceeded = $canReset ? @\opcache_reset() : null;

		$this->app->response->jsonStatus([
			'ok'          => true,
			'removed'     => $removed,
			'invalidated' => $invalidated,
			'failed'      => [],
			'opcache'     => [
				'invalidate_available' => $canInvalidate,
				'invalidation_failed' => $invalidationFailed,
				'reset_available' => $canReset,
				'reset_succeeded' => $resetSucceeded,
			],
		], 200);
	}


	/**
	 * Write the App's HTTP cache files anew.
	 *
	 * Runs in the web server's PHP process, so each written file is invalidated in the web
	 * server's OPcache when opcache_invalidate() exists.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted. No cache is touched.
	 * - Otherwise overwrites every cache file through App::warmCache() and answers 200 with
	 *   {"written": n, "status": "ok"}, n being the number of files written.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 * @see \CitOmni\Kernel\App::warmCache()  Which files are written, and its failures.
	 */
	public function warmupCache(): void {
		$this->app->response->noCache();
		$this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$result = $this->app->warmCache(overwrite: true, opcacheInvalidate: true);

		$this->app->response->jsonStatus([
			'written' => \count(\array_filter($result)),
			'status'  => 'ok',
		], 200);
	}


	/**
	 * Answer the upload limits of the web server's PHP process as JSON.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted.
	 * - Otherwise 200 with upload_max_filesize and post_max_size as the raw INI strings, such
	 *   as "64M", and max_file_uploads as an integer.
	 *
	 * Notes:
	 * - The values include FPM pool settings, PHP_VALUE and per-directory overrides such as
	 *   .user.ini and .htaccess. The CLI can report different values.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 */
	public function uploadLimitsJson(): void {
		$this->app->response->noCache();
		$this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$this->app->response->jsonStatus([
			'upload_max_filesize' => (string)\ini_get('upload_max_filesize'),
			'post_max_size'       => (string)\ini_get('post_max_size'),
			'max_file_uploads'    => (int)\ini_get('max_file_uploads'),
		], 200);
	}


	/**
	 * Answer the maintenance state as JSON.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted.
	 * - Otherwise 200 with enabled, allowed_ips, retry_after and source. Nothing changes.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 * @see \CitOmni\Http\Service\Maintenance::snapshot()  The meaning of the fields.
	 */
	public function maintenance(): void {
		$this->app->response->noCache();
		$this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'enabled'      => (bool)$snap['enabled'],
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
			'source'       => (string)$snap['source'],
		], 200);
	}


	/**
	 * Enable maintenance mode with the allowlist and Retry-After from the request body.
	 *
	 * Lets deployment tooling open a maintenance window while the operators' addresses stay
	 * allowed.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted. The maintenance state does not change.
	 * - Otherwise enables maintenance and answers 200 with {"status": "enabled", "allowed_ips":
	 *   [...], "retry_after": ...} as stored.
	 * - The body is optional JSON {"allowed_ips": ["203.0.113.5", ...], "retry_after": 300}:
	 *     - allowed_ips goes to the Maintenance service as given; it is empty when absent or
	 *       not an array.
	 *     - retry_after is cast to an integer and stored when it is 0 or more; otherwise the
	 *       Maintenance service's default applies.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 * @see \CitOmni\Http\Service\Maintenance::enable()  How the allowlist is checked and stored, and its failures.
	 */
	public function maintenanceEnable(): void {
		$this->app->response->noCache();
		$raw = $this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$body = \json_decode($raw, true);
		$body = \is_array($body) ? $body : [];

		$ips = \is_array($body['allowed_ips'] ?? null) ? (array)$body['allowed_ips'] : [];
		$retry = (int)($body['retry_after'] ?? -1);
		if ($retry >= 0) {
			$this->app->maintenance->setRetryAfter($retry);
		}

		$this->app->maintenance->enable($ips);
		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'status'       => 'enabled',
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
		], 200);
	}


	/**
	 * Disable maintenance mode.
	 *
	 * Behavior:
	 * - PROTECTED_FAIL_STATUS through WebhooksAuth::requireOrAbort() when the request is not
	 *   accepted. The maintenance state does not change.
	 * - Otherwise disables maintenance at once and answers 200 with {"status": "disabled",
	 *   "allowed_ips": [...], "retry_after": ...} as stored.
	 * - The body is optional JSON {"retry_after": 120}. Cast to an integer, a value of 0 or more
	 *   is stored as the Retry-After hint; it does not delay disabling. Otherwise the
	 *   Maintenance service's default applies.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::requireOrAbort()  Which requests are accepted.
	 * @see \CitOmni\Http\Service\Maintenance::disable()  What is stored, and its failures.
	 */
	public function maintenanceDisable(): void {
		$this->app->response->noCache();
		$raw = $this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$body = \json_decode($raw, true);
		$body = \is_array($body) ? $body : [];

		$retry = (int)($body['retry_after'] ?? -1);
		if ($retry >= 0) {
			$this->app->maintenance->setRetryAfter($retry);
		}

		$this->app->maintenance->disable();
		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'status'       => 'disabled',
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
		], 200);
	}


	/**
	 * Diagnose webhook authentication of the current request as JSON.
	 *
	 * Lets a client find out, also in production, why its signed requests are rejected. Unlike
	 * the protected actions, it answers every request, whether WebhooksAuth accepts it or not,
	 * so it reports only facts that are not secret.
	 *
	 * Behavior:
	 * - 200 with:
	 *     - authorized, reason (the WebhooksAuth failure reason value, or null) and message.
	 *     - request: Method, URI, remote address, client IP, content type and length, and the
	 *       body length in bytes.
	 *     - headers: Whether the signature, timestamp and nonce headers are present, the
	 *       lengths of the signature and the nonce, and the timestamp with its age in seconds.
	 *     - webhooks: Whether verification is enabled, bind_context, ttl_seconds,
	 *       ttl_clock_skew_tolerance, and whether cfg webhooks.allowed_ips has a non-empty entry.
	 * - Never contains the body, the signature, the secret or the computed HMAC.
	 * - A request that WebhooksAuth accepts uses up its nonce, like any accepted request.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\WebhooksAuth::verify()  Which requests are accepted, and the side effects.
	 */
	public function webhookDebug(): void {
		$this->app->response->noCache();

		$cfg = $this->app->cfg->webhooks;

		$headerSignature = (string)$cfg->header_signature;
		$headerTimestamp = (string)$cfg->header_timestamp;
		$headerNonce = (string)$cfg->header_nonce;

		$rawAllowedIps = $cfg->allowed_ips;
		if ($rawAllowedIps instanceof \CitOmni\Kernel\Cfg) {
			$rawAllowedIps = $rawAllowedIps->toArray();
		}

		$allowedIpsConfigured = false;
		foreach ((array)$rawAllowedIps as $item) {
			if (!\is_string($item) && !\is_int($item)) {
				continue;
			}

			if (\trim((string)$item) !== '') {
				$allowedIpsConfigured = true;
				break;
			}
		}

		$authorized = $this->app->webhooksAuth->verify();
		$reason = $this->app->webhooksAuth->getLastFailureReason();

		$timestamp = isset($_SERVER[$headerTimestamp]) ? (int)$_SERVER[$headerTimestamp] : 0;
		$now = \time();

		$this->app->response->jsonStatus([
			'authorized' => $authorized,
			'reason' => $reason?->value,
			'message' => $authorized ? 'OK' : 'Webhook authentication failed.',

			'request' => [
				'method' => $_SERVER['REQUEST_METHOD'] ?? null,
				'uri' => $_SERVER['REQUEST_URI'] ?? null,
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
				'client_ip' => $this->app->request->ip(),
				'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
				'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null,
				'body_bytes' => \strlen($this->app->webhooksAuth->getRawBody()),
			],

			'headers' => [
				'signature_present' => isset($_SERVER[$headerSignature]),
				'signature_length' => isset($_SERVER[$headerSignature]) ? \strlen((string)$_SERVER[$headerSignature]) : 0,
				'timestamp_present' => isset($_SERVER[$headerTimestamp]),
				'timestamp_value' => $timestamp > 0 ? $timestamp : null,
				'timestamp_age_seconds' => $timestamp > 0 ? $now - $timestamp : null,
				'nonce_present' => isset($_SERVER[$headerNonce]),
				'nonce_length' => isset($_SERVER[$headerNonce]) ? \strlen((string)$_SERVER[$headerNonce]) : 0,
			],

			'webhooks' => [
				'enabled' => $this->app->webhooksAuth->isEnabled(),
				'bind_context' => (bool)$cfg->bind_context,
				'ttl_seconds' => (int)$cfg->ttl_seconds,
				'ttl_clock_skew_tolerance' => (int)$cfg->ttl_clock_skew_tolerance,
				'allowed_ips_configured' => $allowedIpsConfigured,
			],
		], 200);
	}
	
	
}
