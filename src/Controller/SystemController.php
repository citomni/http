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
 * SystemController: Minimal operations and observability endpoints for HTTP mode.
 *
 * Responsibilities:
 * - Expose tiny, deterministic endpoints for admin tasks, uptime, and smoke tests.
 * - Offer protected maintenance/cache controls via HMAC-based WebhooksAuth.
 * - Expose HTTP application-information endpoints backed by Kernel Support\AppInfo.
 *
 * Collaborators:
 * - Reads: Request, Response, TemplateEngine, ErrorHandler, Maintenance, WebhooksAuth, and Kernel Support\AppInfo.
 * - Writes: Response (JSON/text/HTML), Maintenance state, cache files (reset/warmup).
 *
 * Security note:
 * - Protected system actions use WebhooksAuth::requireOrAbort().
 * - Do NOT store webhook secrets in cfg. The HMAC secret is loaded from:
 *   CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
 * - The actual secret file must not be committed; keep only the `.tpl` template in VCS.
 * - Replay protection is handled by the shared Nonce service via cfg.nonce.
 *
 * Configuration keys (via $this->app->cfg):
 * - webhooks.* - HMAC verification, headers, TTL, IP allow-list, logging, and secret file path.
 * - nonce.* - filesystem-backed single-use token ledger used for replay protection.
 * - http.base_url (string) - fallback for canonical links when CITOMNI_PUBLIC_ROOT_URL is unset.
 *
 * Routing:
 * - Route definitions no longer live under cfg.
 *   They are merged separately by App::buildRoutes() and exposed as $this->app->routes.
 *   AppInfo supplies route and command data to the HTTP diagnostics endpoints.
 *
 * Error handling:
 * - Fail-fast: Errors bubble to the global error handler.
 * - Protected endpoints deliberately fail with 404 to avoid route disclosure.
 * - No broad try/catch blocks here; logging is centralized.
 *
 * Typical usage:
 * - Called by deployment tooling, monitors, and CI smoke tests to verify liveness and perform safe ops.
 *
 * Examples:
 * - Core (liveness): GET /_system/ping  -> "OK 2025-10-16T22:31:00Z"
 * - Scenario (protected): POST /_system/reset-cache with valid HMAC -> { "ok": true, ... }
 *
 * Failure:
 * - Unauthorized protected calls return 404 via ErrorHandler->httpError(404) to hide presence.
 */
final class SystemController extends BaseController {

	/**
	 * Chosen, consistent failure status for protected endpoints.
	 * 404 hides the endpoint existence in all environments.
	 */
	private const PROTECTED_FAIL_STATUS = 404;

	/**
	 * Called once per request by BaseController.
	 * We keep it empty to avoid any implicit I/O.
	 */
	protected function init(): void {
		// Intentionally empty (no I/O). Each action sets no-cache explicitly.
	}
	


	/**
	 * Render the dev-only application information page.
	 *
	 * Behavior:
	 * - Conceals the endpoint with 404 outside dev.
	 * - Maps ?raw=1 or ?unredacted=1 to AppInfo unredacted output only in dev.
	 * - Delegates shared application/runtime introspection and masking to AppInfo.
	 * - Includes fresh dev/stage/prod configuration projections for the diagnostic page.
	 * - Applies noindex/no-cache headers and renders the configured HTTP template.
	 *
	 * Notes:
	 * - The active cfg and environment projections retain the semantics defined by AppInfo.
	 * - This method owns HTTP exposure only; it does not inspect or mask application data itself.
	 *
	 * @return void
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
	 * Emit the dev-only AppInfo snapshot as JSON.
	 *
	 * Behavior:
	 * - Conceals the endpoint with 404 outside dev.
	 * - Maps ?raw=1 or ?unredacted=1 to AppInfo unredacted output only in dev.
	 * - Includes the established dev/stage/prod configuration projections.
	 * - Returns the AppInfo snapshot directly with no-cache headers.
	 *
	 * Notes:
	 * - Historical flat/PHP-export artifacts are intentionally not recreated.
	 * - Shared introspection and secret masking remain exclusively in AppInfo.
	 *
	 * @return void
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
	 * Determine whether the current request explicitly asks for unredacted AppInfo output.
	 *
	 * Behavior:
	 * - Fails closed outside dev, independent of the calling endpoint guard.
	 * - Accepts standard boolean query values for ?raw or ?unredacted.
	 * - Rejects non-scalar query values instead of coercing arrays or objects.
	 *
	 * @return bool True when unredacted AppInfo output is explicitly requested in dev.
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
	 * Render an AppInfo snapshot with HTTP-only presentation metadata.
	 *
	 * @param array<string,mixed> $snapshot AppInfo snapshot to expose in the template.
	 * @return void
	 */
	private function renderAppInfo(array $snapshot): void {
		$this->app->response->noIndex();

		$canonicalBase = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? (string)\CITOMNI_PUBLIC_ROOT_URL
			: (string)($this->app->cfg->http->base_url ?? '');
		$canonical = \rtrim($canonicalBase, '/') . '/appinfo.html';

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
	 * Return the resolved client IP address (proxy-aware).
	 *
	 * Behavior:
	 * - Uses Request service trust rules (trusted proxies, headers).
	 * - Emits {"ip": "..."} with no-cache headers.
	 *
	 * Notes:
	 * - No remote I/O; constant-time response.
	 *
	 * Typical usage:
	 *   Diagnose proxy chains and LB configuration during rollout.
	 *
	 * Examples:
	 *
	 *   // Direct client
	 *   GET /_system/clientip  -> { "ip": "203.0.113.7" }
	 *
	 *   // Behind proxy
	 *   GET /_system/clientip  -> { "ip": "198.51.100.10" }
	 *
	 * Failure:
	 * - None; falls back to Request->ip() semantics.
	 *
	 * @return void
	 */
	public function clientIp(): void {
		$this->app->response->noCache();
		$ip = $this->app->request->ip();
		$this->app->response->jsonStatus([
			'ip' => $ip,
		], 200);		
	}


	/**
	 * Return a tiny liveness signal.
	 *
	 * Behavior:
	 * - Returns "OK <utc>" as plain text with 200.
	 *
	 * Notes:
	 * - Simplest possible endpoint for external uptime checks.
	 *
	 * Typical usage:
	 *   Used by monitors to verify HTTP reachability without JSON parsing.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   GET /_system/ping -> "OK 2025-10-16T22:31:00Z"
	 *
	 *   // Idempotent
	 *   GET /_system/ping -> will give you the same shape with a new timestamp
	 *
	 * Failure:
	 * - None; purely computed response.
	 *
	 * @return void
	 */
	public function ping(): void {
		$this->app->response->noCache();
		$this->app->response->text('OK ' . \gmdate('Y-m-d\TH:i:s\Z'), 200);
	}
	
	 
	/**
	 * Return a shallow health snapshot without external calls.
	 *
	 * Behavior:
	 * - Emits php_version, environment, opcache_enabled, server_time_utc, timezone.
	 * - Avoids DB and network I/O for speed and determinism.
	 *
	 * Notes:
	 * - Schema is intentionally small. Keep schema stable; tools might diff these fields.
	 * - Returns:
	 * 		- php_version
	 * 		- environment (CITOMNI_ENVIRONMENT, if defined)
	 * 		- opcache_enabled (ini + function_exists check)
	 * 		- server_time_utc (RFC3339)
	 * 		- timezone (default PHP TZ)
	 *
	 * Typical usage:
	 *   Called by CI/CD tooling to confirm runtime flags and clock sanity.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/health -> { "php_version":"8.2.x", ... }
	 *
	 *   // With opcache disabled
	 *   GET /_system/health -> { "opcache_enabled": false, ... }
	 *
	 * Failure:
	 * - None; data is computed from local runtime only.
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
			'php_version'      => \PHP_VERSION,
			'environment'      => $env,
			'opcache_enabled'  => $opcacheEnabled,
			'server_time_utc'  => \gmdate('c'),
			'timezone'         => $tz,
		], 200);
	}


	/**
	 * Report application/framework version markers without I/O.
	 *
	 * Typical markers:
	 * - CITOMNI_VERSION (if you define it in your kernel/build)
	 * - APP_VERSION     (optionally from app)
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
	 * Return server time in UTC and local timezone.
	 *
	 * Behavior:
	 * - Emits time_utc (RFC3339), time_local, and timezone.
	 *
	 * Notes:
	 * - Helps detect drift between client and server clocks.
	 *
	 * Typical usage:
	 *   Used as sanity-check before HMAC timestamp validation.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/time -> { "time_utc":"...", "time_local":"...", "timezone":"..." }
	 *
	 *   // Idempotent
	 *   GET /_system/time -> Same keys, fresh values
	 *
	 * Failure:
	 * - None; values are computed locally.
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
	 * Echo minimal request metadata (dev/stage only).
	 *
	 * Behavior:
	 * - Returns selected headers and routing fields to aid proxy debugging.
	 * - Non-dev/stage requests receive 404 to avoid route disclosure.
	 *
	 * Notes:
	 * - Only exposes a minimal, non-sensitive subset of server vars.
	 *
	 * Typical usage:
	 *   Validate X-Forwarded-* headers and LB behavior during rollout.
	 *
	 * Examples:
	 *
	 *   // Dev
	 *   GET /_system/request-echo -> { "remote_addr":"...", "forwarded":"...", ... }
	 *
	 *   // Prod
	 *   GET /_system/request-echo -> 404
	 *
	 * Failure:
	 * - None; either returns data or delegates 404.
	 *
	 * @return void
	 */
	public function requestEcho(): void {
		$this->app->response->noCache();

		// Only allow this to run in dev and stage
		$env = \defined('CITOMNI_ENVIRONMENT') ? \CITOMNI_ENVIRONMENT : 'prod';
		if ($env !== 'dev' && $env !== 'stage') {
			$this->app->errorHandler->httpError(404, ['title' => 'Not Found']);
			return;
		}

		$server = $_SERVER; // read-only dump, filtered below
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
	 * Reset OPcache and remove CitOmni cache files (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC via WebhooksAuth; unauthorized returns 404.
	 * - OPcache: try opcache_reset(); also invalidate known cache files if any.
	 *   (performs a global opcache_reset() best-effort).
	 * - Files: remove var/cache/{cfg.http.php,routes.http.php,services.http.php} if they exist.
	 *          (Legacy layouts may not have routes.http.php.)
	 * - Accepts optional JSON body with absolute file paths to invalidate:
	 *   Body:
	 *     (optional) JSON { "paths": ["/abs/extra/file1.php", ...] } to invalidate files.
	 *
	 * Notes:
	 * - Only operates on known cache files by default; extra paths must be absolute.
	 * - Idempotent: Missing files are ignored; failures are reported in "failed".
	 *
	 * Typical usage:
	 *   Triggered post-deploy when OPcache timestamps are disabled.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   POST /_system/reset-cache  (HMAC ok) -> { "ok": true, "removed":[...], ... }
	 *
	 *   // Extra file invalidation
	 *   POST body: { "paths": ["/abs/file.php"] }
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler (endpoint remains undisclosed).
	 *
	 * @return void
	 */
	public function resetCache(): void {
		$this->app->response->noCache();
		$raw = $this->app->webhooksAuth->requireOrAbort(self::PROTECTED_FAIL_STATUS);

		$removed = [];
		$failed  = [];
		$invalidated = [];

		// Known cache file candidates (HTTP mode).
		$candidates = [
			\CITOMNI_APP_PATH . '/var/cache/cfg.http.php',
			\CITOMNI_APP_PATH . '/var/cache/routes.http.php',
			\CITOMNI_APP_PATH . '/var/cache/services.http.php',
		];

		// Optional extra files from JSON body (parsed from captured $raw)
		$body = \json_decode($raw, true);
		$body = \is_array($body) ? $body : [];
		if (!empty($body['paths']) && \is_array($body['paths'])) {
			foreach ($body['paths'] as $p) {
				$p = (string)$p;
				
				// Security: only allow absolute paths to avoid cwd tricks
				if ($p !== '' && $p[0] === \DIRECTORY_SEPARATOR) {
					$candidates[] = $p;
				}
			}
		}

		// Invalidate OPcache for candidates (if enabled) before deletion
		$canInvalidate = \function_exists('opcache_invalidate');

		foreach ($candidates as $path) {
			if (\is_file($path)) {
				if ($canInvalidate) {
					@\opcache_invalidate($path, true);
					$invalidated[] = $path;
				}
				if (@\unlink($path)) {
					$removed[] = $path;
				} else {
					$failed[] = $path;
				}
			}
		}

		// Global OPcache reset (best-effort)
		if (\function_exists('opcache_reset')) {
			@\opcache_reset();
		}

		$this->app->response->jsonStatus([
			'ok'           => $failed === [],
			'removed'      => $removed,
			'invalidated'  => $invalidated,
			'failed'       => $failed,
		], 200);
	}


	/**
	 * Warm up CitOmni caches (protected by WebhooksAuth)
	 *
	 * Behavior:
	 * - Verifies HMAC; unauthorized returns 404.
	 * - Calls App::warmCache(overwrite: true, opcacheInvalidate: true).
	 * - Returns number of cache files written.
	 *
	 * Notes:
	 * - Deterministic: cache content depends only on cfg/service maps.
	 *
	 * Typical usage:
	 *   Run post-deploy to ensure next request runs hot (OPcache already primed).
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   POST /_system/warmup-cache -> { "written": 2, "status":"ok" }
	 *
	 *   // Idempotent
	 *   POST again -> written may be equal (overwrites enabled)
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
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
	 * Return current maintenance snapshot (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; returns enabled, allowed_ips, retry_after, source.
	 *
	 * Notes:
	 * - Read-only; does not modify maintenance state.
	 *
	 * Typical usage:
	 *   Confirm who is allowed during a scheduled maintenance window.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/maintenance -> { "enabled":true, "allowed_ips":[...], ... }
	 *
	 *   // Disabled
	 *   GET /_system/maintenance -> { "enabled":false, ... }
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
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
	 * Enable maintenance mode (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; parses JSON body { allowed_ips?:[], retry_after?:int }.
	 * - Optionally sets retry_after hint; enables maintenance with allowlist.
	 *
	 * Notes:
	 * - IPs are taken verbatim; validation lives in the Maintenance service.
	 * - Body (JSON):
	 * 		{
	 *   		"allowed_ips": ["1.2.3.4", "2001:db8::1", "unknown"],  // optional
	 *   		"retry_after": 300  // optional
	 * 		}
	 *
	 * Typical usage:
	 *   Prepare a deploy window while allowing operator IPs through.
	 *
	 * Examples:
	 *
	 *   // Allow current office IP, set retry hint
	 *   POST /_system/maintenance/enable  body: {"allowed_ips":["203.0.113.5"],"retry_after":300}
	 *
	 *   // Minimal
	 *   POST /_system/maintenance/enable  body: {}
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
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
	 * Disable maintenance mode (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; optionally sets retry_after hint for clients.
	 * - Disables maintenance and returns new snapshot.
	 *
	 * Notes:
	 * - retry_after is advisory and does not delay disabling.
	 * - Body (JSON):
	 * 		{
	 *   		"retry_after": 120 // optional (hint to clients)
	 * 		}
	 *
	 * Typical usage:
	 *   End a maintenance window and restore normal access.
	 *
	 * Examples:
	 *
	 *   // Provide a short retry_after hint for caches
	 *   POST /_system/maintenance/disable  body: {"retry_after":120}
	 *
	 *   // Minimal
	 *   POST /_system/maintenance/disable  body: {}
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
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
	 * Diagnose webhook authentication for the current request.
	 *
	 * Behavior:
	 * - Uses the real WebhooksAuth verification engine.
	 * - Returns structured, non-secret diagnostics for production troubleshooting.
	 * - Does not expose the raw body, canonical base string, secret, signature value,
	 *   or calculated HMAC.
	 *
	 * Notes:
	 * - A successful debug request consumes the nonce like any other webhook request.
	 * - This endpoint should live under /_system/ and must be treated as diagnostic
	 *   infrastructure. It uses the same webhook auth headers as real system endpoints,
	 *   but returns controlled diagnostics instead of hiding every failure.
	 *
	 * @return void
	 */
	public function webhookDebug(): void {
		$this->app->response->noCache();

		$cfg = $this->app->cfg->webhooks;

		$headerSignature = (string)($cfg->header_signature ?? 'HTTP_X_CITOMNI_SIGNATURE');
		$headerTimestamp = (string)($cfg->header_timestamp ?? 'HTTP_X_CITOMNI_TIMESTAMP');
		$headerNonce = (string)($cfg->header_nonce ?? 'HTTP_X_CITOMNI_NONCE');

		$rawAllowedIps = $cfg->allowed_ips ?? [];
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
				'bind_context' => (bool)($cfg->bind_context ?? true),
				'ttl_seconds' => (int)($cfg->ttl_seconds ?? 300),
				'ttl_clock_skew_tolerance' => (int)($cfg->ttl_clock_skew_tolerance ?? 60),
				'allowed_ips_configured' => $allowedIpsConfigured,
			],
		], 200);
	}
	
	
}
