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

namespace CitOmni\Http\Service;

use CitOmni\Kernel\Service\BaseService;


/**
 * Maintenance: site-wide maintenance flag enforcement and toggling.
 *
 * Responsibilities:
 * - Read maintenance flag (flag-first), fall back to cfg defaults only if flag is missing/invalid.
 * - Enforce maintenance early via guard(): emit 503 + headers + optional branded template and exit.
 * - Toggle maintenance ON/OFF by atomically rewriting a small flag PHP file.
 * - Provide small, pure getters (isEnabled/getAllowedIps/getRetryAfter) and a per-request snapshot().
 * - Optionally rotate backups of the previous flag file according to cfg policy.
 *
 * Collaborators:
 * - $this->app->request (read-only): Resolve client IP for allowlist checks.
 * - $this->app->cfg (read-only): Defaults (flag path, template path, retry interval, backup policy).
 * - $this->app->log (optional): If present, writes toggle audit entries.
 *
 * Configuration keys:
 * - maintenance.flag.path (string)               - absolute path to flag file. Default: CITOMNI_APP_PATH . '/var/flags/maintenance.php'
 * - maintenance.flag.template (string)           - absolute path to branded maintenance template (optional).
 * - maintenance.flag.default_retry_after (int)   - fallback Retry-After seconds (>= 0). Example: 600
 * - maintenance.flag.allowed_ips (array<string>) - optional fallback allow-list when flag lacks one.
 * - maintenance.backup.enabled (bool)            - enable lightweight backup rotation (default true).
 * - maintenance.backup.keep (int)                - number of backups to keep (default 3).
 * - maintenance.backup.dir (string)              - directory for backups (default: app /var/backups/flags).
 * - maintenance.log.filename (string)            - audit log filename (default: 'maintenance.json').
 *
 * Error handling:
 * - Fail fast by design. Invalid directories or permissions during writes raise \RuntimeException
 *   and bubble to the global error handler.
 * - Toggle methods (enable/disable) may throw \RuntimeException on I/O failures (directory creation, write, rename).
 * - guard() emits a 503 response and exits the process when the client is not allowed; it does not throw.
 *
 * Typical usage:
 *
 *   // On every HTTP request, as early as possible (front controller or Kernel):
 *   $this->app->maintenance->guard(); // Emits 503 and exits if maintenance is active
 *
 *   // Toggle on (optionally adding the current actor IP in dev/when known):
 *   $this->app->maintenance->setRetryAfter(900)->enable("203.0.113.7"); // 15 minutes
 *
 *   // Toggle off (keeping a short Retry-After for caches/CDNs if desired):
 *   $this->app->maintenance->setRetryAfter(60)->disable();
 *
 * Examples:
 *
 *   // Read effective state without side effects:
 *   if ($this->app->maintenance->isEnabled()) { // show admin banner
 *   	// keep the UI hint minimal for privileged users
 *   }
 *
 *   // Permit a CI pipeline IP during a deployment window:
 *   $this->app->maintenance->enable("198.51.100.10");
 *
 * Failure:
 * - Filesystem misconfiguration (non-writable flag dir, unreadable template) leads to predictable
 *   exceptions on write or to the built-in minimal 503 body on read.
 *
 * Notes:
 * - Snapshot is memoized per request/process to minimize IO; any toggle resets memoization.
 * - In "dev", the special token "unknown" can be allowed to bypass strict IP detection.
 * - 503 responses add "X-Robots-Tag: noindex" and strong no-cache headers.
 * - setRetryAfter() only affects the next write (enable/disable). It never overrides enforcement.
 */
class Maintenance extends BaseService {

	/**
	 * Memoized state for the current request. Built once on first snapshot().
	 * @var array{enabled:bool,allowed_ips:array,retry_after:int,source:string}|null
	 */
	private ?array $snapshot = null;


	// Write-time override for retry_after (does NOT affect enforcement/guard()).
	protected ?int $writeRetryAfter = null;


	/**
	 * Enforce maintenance mode early in the request lifecycle.
	 *
	 * Behavior:
	 * - Builds a one-shot state via snapshot() (flag-first with cfg fallback).
	 * - If maintenance is enabled and the client IP is not allowlisted, emits:
	 *     1) HTTP 503 status
	 *     2) Retry-After header (seconds)
	 *     3) No-cache headers (Cache-Control, Pragma)
	 *     4) X-Robots-Tag: noindex
	 *     5) Content-Type: text/html; charset=utf-8
	 *     6) Connection: close
	 * - Attempts to render a branded template if configured at maintenance.flag.template;
	 *   otherwise renders a minimal inline HTML page.
	 * - Terminates execution with exit immediately after sending the response.
	 *
	 * Notes:
	 * - This method performs no writes; it only reads the maintenance flag once per request.
	 * - Allowlist normalization accepts the literal "unknown" token only in dev environments.
	 * - If maintenance is disabled or the client is allowlisted, the method returns immediately.
	 *
	 * Typical usage (Kernel):
	 *   $app->maintenance->guard(); // called early, before routing; exits on block
	 *
	 * @return void
	 */
	public function guard(): void {
		
		$state = $this->snapshot(); // built once per request

		// Not enabled -> continue normal request processing
		if (!$state['enabled']) {
			return;
		}

		// Proxy-aware client IP resolution (Kernel::boot already set trusted proxies)
		$clientIp = $this->app->request->ip();

		// Allowlisted? Continue as normal
		if ($clientIp !== null && \in_array($clientIp, $state['allowed_ips'], true)) {
			return;
		}

		// Compute Retry-After (already clamped in snapshot())
		$retry = $state['retry_after'];

		// Emit minimal, cache-safe 503 response
		if (!\headers_sent()) {
			// \header('HTTP/1.1 503 Service Unavailable', true, 503);
			\http_response_code(503);
			\header('Retry-After: ' . $retry);
			\header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			\header('Pragma: no-cache');
			\header('X-Robots-Tag: noindex, noarchive');
			\header('Content-Type: text/html; charset='.(string)$this->app->cfg->locale->charset);
			\header('Connection: close');
		}

		// Optional branded template (kept deliberately simple & deterministic)
		$template = (string)($this->app->cfg->maintenance->flag->template ?? '');
		if ($template !== '' && \is_file($template) && \is_readable($template)) {
            // Variables available to the template (intentionally limited)
			$retry_after   = $retry;
			$contact_email = (string)($this->app->cfg->identity->email ?? ($this->app->cfg->support_email ?? 'support@citomni.com'));

			// Human-readable ETA, based on current default timezone (Kernel enforces timezone earlier)
			$now = new \DateTimeImmutable('now'); // Default tz already applied in kernel
			$resumeAt = $now->add(new \DateInterval('PT' . $retry . 'S'));
			$resume_at = $resumeAt->format('Y-m-d H:i');

			include $template;
		} else {
			echo '<!doctype html><html><head><meta charset="utf-8"><title>Service Unavailable</title></head><body>'
				. '<h1>We&rsquo;ll be right back</h1>'
				. '<p>The site is temporarily down for maintenance. Please try again later.</p>'
				. '</body></html>';
		}

		// Hard stop under maintenance
		exit;
	}


	/**
	 * Fast check: is maintenance mode currently enabled?
	 *
	 * Behavior:
	 * - Relies on the per-request snapshot() which reads the flag at most once.
	 * - Returns a boolean derived from the flag file when present, otherwise cfg fallback.
	 *
	 * Notes:
	 * - No I/O after the first snapshot() call in the same request.
	 * - Use this in controllers or templates when you need a simple boolean.
	 *
	 * @return bool True when maintenance mode is enabled; false otherwise.
	 */
	public function isEnabled(): bool {
		return $this->snapshot()['enabled'];
	}


	/**
	 * Get the normalized allowlist for the current request.
	 *
	 * Behavior:
	 * - Returns the effective allowlist taken from the flag file when present;
	 *   if the flag omits the list entirely, falls back to cfg's maintenance.flag.allowed_ips.
	 * - Normalization rules:
	 *     - Trim and de-duplicate values
	 *     - Validate IPv4/IPv6 addresses
	 *     - Accept the literal "unknown" token only in dev environments
	 *     - Sort deterministically (string sort)
	 *
	 * Notes:
	 * - The allowlist is built once via snapshot() and reused within the same request.
	 *
	 * @return array<string> A list of allowed client identifiers (IPs and/or "unknown").
	 */
	public function getAllowedIps(): array {
		return $this->snapshot()['allowed_ips'];
	}


	/**
	 * Set a write-time Retry-After override (seconds, >= 0) for the next flag write.
	 *
	 * Behavior:
	 * - Affects only enable()/disable() writes performed after this call.
	 * - Does NOT affect guard() enforcement, which always reads Retry-After
	 *   from the flag (or falls back to cfg default when the flag lacks a value).
	 * - The override is one-shot and is cleared automatically after a write.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->setRetryAfter(900)->enable();  // plan a 15-minute window
	 *
	 * @param int $seconds Non-negative Retry-After value in seconds to store into the flag.
	 * @return self Fluent reference for chaining.
	 */
	public function setRetryAfter(int $seconds): self {
		$this->writeRetryAfter = \max(0, $seconds);
		return $this;
	}


	/**
	 * Get the effective Retry-After (seconds, >= 0) for the current request.
	 *
	 * Behavior:
	 * - Prefers the value from the flag file when available.
	 * - Falls back to maintenance.flag.default_retry_after from cfg when the flag
	 *   is missing or does not define a retry value.
	 * - Value is clamped to a non-negative integer.
	 *
	 * Notes:
	 * - This reflects the enforcement value (what guard() will send), not any pending
	 *   write-time override set via setRetryAfter().
	 *
	 * @return int Non-negative Retry-After value in seconds.
	 */
	public function getRetryAfter(): int {
		return $this->snapshot()['retry_after'];
	}


	/**
	 * Build and memoize the maintenance state for the current request.
	 *
	 * Construction rules (flag-first):
	 *  1) If the flag file exists and returns an array:
	 *       - enabled:     (bool)$flag['enabled']      (default false)
	 *       - allowed_ips: if the key exists in the flag, use its array *as-is* (empty means empty);
	 *                      if the key is omitted entirely, fall back to cfg maintenance.flag.allowed_ips.
	 *       - retry_after: (int)$flag['retry_after']   (default cfg maintenance.flag.default_retry_after)
	 *  2) If the flag is missing or invalid:
	 *       - enabled=false; allowed_ips from cfg (optional); retry_after from cfg default.
	 *
	 * Behavior:
	 * - Normalizes allowlist (trim, validate IPs, allow "unknown" only in dev, unique, sorted).
	 * - Clamps retry_after to a non-negative integer.
	 * - Memoizes the result so subsequent calls avoid repeated I/O within the same request.
	 *
	 * Notes:
	 * - This method does not write anything and does not re-validate within the same request.
	 * - The memoized snapshot is cleared when enable()/disable() writes a new flag.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   allowed_ips: array<int, string>,
	 *   retry_after: int,
	 *   source: 'flag'|'cfg'
	 * }
	 */
	public function snapshot(): array {
		if ($this->snapshot !== null) {
			// One read per request; no revalidation within the same request.
			return $this->snapshot;
		}

		// 1) Defaults from cfg (declarative; cfg does not depend on runtime)
		$cfgDefaultRetry = \max(0, (int)($this->app->cfg->maintenance->flag->default_retry_after ?? 300));
		$cfgAllowlist    = \is_array($this->app->cfg->maintenance->flag->allowed_ips ?? null)
			? (array)$this->app->cfg->maintenance->flag->allowed_ips
			: [];

		// 2) Attempt to load the raw flag (no validation here)
		$flag     = $this->readFlag();
		$fromFlag = \is_array($flag);

		// 3) Effective raw fields with "flag overrides cfg" precedence
		$enabledRaw = $fromFlag ? (bool)($flag['enabled'] ?? false) : false;
		$retryRaw   = $fromFlag && \array_key_exists('retry_after', $flag)
			? (int)$flag['retry_after']
			: $cfgDefaultRetry;

		// Build allowedRaw exactly once:
		// - If flag has the 'allowed_ips' key (even if empty), use it verbatim ("empty means empty").
		// - Otherwise, fall back to the cfg-level allowlist.
		$flagHasAllow = $fromFlag && \array_key_exists('allowed_ips', $flag) && \is_array($flag['allowed_ips']);
		$allowedRaw   = $flagHasAllow ? (array)$flag['allowed_ips'] : $cfgAllowlist;

		// 4) Normalize values
		$enabled = (bool)$enabledRaw;
		$allowed = $this->normalizeIps($allowedRaw); // validate/dedupe/sort; allow "unknown" only in dev
		$retry   = \max(0, (int)$retryRaw);
		$source  = $fromFlag ? 'flag' : 'cfg';

		// 5) Memoize and return
		return $this->snapshot = [
			'enabled'     => $enabled,
			'allowed_ips' => $allowed,
			'retry_after' => $retry,
			'source'      => $source,
		];
	}


	/**
	 * Enable maintenance mode and persist the allowlist.
	 *
	 * Behavior:
	 * - Requires an array of IP addresses (strings). Invalid/empty entries are ignored.
	 * - The current client identifier is appended automatically when available:
	 *   1) Adds the resolved client IP if valid.
	 *   2) In dev, may add the literal "unknown" token when no IP is resolvable.
	 * - Determines the stored retry_after using (in order):
	 *   1) One-shot override set via setRetryAfter(), if present.
	 *   2) maintenance.flag.default_retry_after from cfg.
	 * - Writes the flag atomically and rotates backups according to maintenance.backup.* policy.
	 * - Clears the per-request snapshot and the one-shot write override after a successful write.
	 * - Emits an audit log entry (if a 'log' service is available).
	 *
	 * Notes:
	 * - This method affects only on-disk state (flag file). Runtime enforcement reads the flag.
	 * - Throws \RuntimeException on I/O/permission errors during write/backup operations.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->enable([]);                       // seed from current client only
	 *   $this->app->maintenance->enable(['203.0.113.10']);         // explicit allowlist
	 *   $this->app->maintenance->setRetryAfter(600)->enable(['1.2.3.4', '2001:db8::1']);
	 *
	 * @param array $ips List of IPs to allow while maintenance is on.
	 * @return bool True on success.
	 * @throws \RuntimeException If the flag file or its directories cannot be written.
	 */
	public function enable(array $ips): bool {
		// Optionally append the caller's IP (makes it easy to not lock yourself out).
		$clientIp = $this->app->request->ip();
		if ($clientIp === 'unknown' && !$this->allowUnknownToken()) {
			$clientIp = null;
		}
		if ($clientIp !== null) {
			$ips[] = $clientIp;
		}

		// Normalize (validates IPs, allows 'unknown' only in dev, dedupes, sorts).
		$allowlist = $this->normalizeIps($ips);

		// Write-time Retry-After: prefer explicit override, else cfg default.
		$retry = \max(0, (int)($this->writeRetryAfter ?? ($this->app->cfg->maintenance->flag->default_retry_after ?? 300)));

		// Persist flag (idempotent write on every enable()).
		$this->writeFlagFile(true, $allowlist, $retry);

		// Clear per-request memo and one-shot write override.
		$this->snapshot = null;
		$this->writeRetryAfter = null;

		// Audit (best effort).
		$this->logToggle('enabled', ['allowed_ips' => $allowlist]);

		return true;
	}


	/**
	 * Disable maintenance mode and clear the allowlist.
	 *
	 * Behavior:
	 * - Determines the stored retry_after using (in order):
	 *     - The one-shot write override set via setRetryAfter(), if present
	 *     - maintenance.flag.default_retry_after from cfg
	 * - Writes the flag atomically with enabled=false and an empty allowlist.
	 * - Rotates backups according to maintenance.backup.* policy.
	 * - Clears the per-request snapshot and the one-shot write override after a successful write.
	 * - Emits an audit log entry (if a 'log' service is available).
	 *
	 * Notes:
	 * - This method affects only on-disk state (flag file). It does not send responses.
	 * - Throws \RuntimeException on I/O/permission errors during write/backup operations.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->disable();
	 *
	 * @return bool True on success.
	 */
	public function disable(): bool {
		// Write-time Retry-After: prefer explicit override, else cfg default.
		$retry = $this->writeRetryAfter ?? (int)($this->app->cfg->maintenance->flag->default_retry_after ?? 300);
		$retry = \max(0, $retry);

		// Write the flag-file
		$this->writeFlagFile(false, [], $retry);
		
		// Log what happened
		$this->logToggle('disabled', []);

		// Reset per-request snapshot and clear the one-shot write override.
		$this->snapshot = null;
		$this->writeRetryAfter = null;

		return true;
	}


	/**
	 * Read the raw flag array from disk, or null if missing/invalid.
	 *
	 * @return array|null
	 */
	protected function readFlag(): ?array {
		$path = $this->getFlagPath();
		if (!\is_file($path) || !\is_readable($path)) {
			return null;
		}
		\clearstatcache(true, $path);
		$data = include $path; // must be a plain "return [ ... ];" file
		return \is_array($data) ? $data : null;
	}


	/**
	 * Get absolute filesystem path to the maintenance flag file.
	 *
	 * @return string
	 */
	protected function getFlagPath(): string {
		$path = $this->app->cfg->maintenance->flag->path ?? null;
		if (\is_string($path) && $path !== '') {
			return $path;
		}
		return CITOMNI_APP_PATH . '/var/flags/maintenance.php';
	}


	/**
	 * Normalize a list of IPs:
	 * - Trims empties, validates IPv4/IPv6
	 * - Allows literal "unknown" only when CITOMNI_ENVIRONMENT in {dev,stage}
	 * - Sorts and de-duplicates
	 *
	 * @param array $ips
	 * @return string[]
	 */
	protected function normalizeIps(array $ips): array {
		$out = [];
		$allowUnknown = $this->allowUnknownToken();

		foreach ($ips as $ip) {
			if (!\is_string($ip)) {
				continue;
			}
			$ip = \trim($ip);
			if ($ip === '') {
				continue;
			}
			if ($ip === 'unknown') {
				if ($allowUnknown) {
					$out[] = 'unknown';
				}
				continue;
			}
			if (\filter_var($ip, \FILTER_VALIDATE_IP)) {
				$out[] = $ip;
			}
		}

		$out = \array_values(\array_unique($out));
		\sort($out, \SORT_STRING);
		return $out;
	}


	/**
	 * Write the flag atomically and rotate backups (if enabled).
	 *
	 * @param bool     $enabled
	 * @param string[] $allowedIps Normalized allowlist
	 * @param int      $retryAfter Seconds, >= 0
	 * @return void
	 */
	protected function writeFlagFile(bool $enabled, array $allowedIps, int $retryAfter): void {
		$flagPath = $this->getFlagPath();
		$flagDir  = \dirname($flagPath);
		$baseName = \basename($flagPath);
		$tmpPath  = $flagPath . '.' . \bin2hex(\random_bytes(6)) . '.tmp';

		if (!\is_dir($flagDir) && !\mkdir($flagDir, 0755, true)) {
			throw new \RuntimeException('Unable to create flag dir: ' . $flagDir);
		}
		if (!\is_writable($flagDir)) {
			throw new \RuntimeException('Flag dir not writable: ' . $flagDir);
		}

		// Prepare new payload
		$newArr = [
			'enabled'     => (bool)$enabled,
			'allowed_ips' => \array_values($allowedIps),
			'retry_after' => \max(0, (int)$retryAfter),
		];
		$newSrc = $this->buildFlagPhp($newArr);

		// Backup policy
		$policy   = $this->resolveBackupPolicy();
		$doBackup = $policy['enabled'] && $policy['keep'] !== 0 && \is_file($flagPath) && \is_readable($flagPath);

		// If backing up, write a rotating copy first
		if ($doBackup) {
			$backupDir = $policy['dir'];
			if (!\is_dir($backupDir) && !\mkdir($backupDir, 0755, true)) {
				throw new \RuntimeException('Unable to create backup dir: ' . $backupDir);
			}
			if (!\is_writable($backupDir)) {
				throw new \RuntimeException('Backup dir not writable: ' . $backupDir);
			}
			$stamp  = \date('Ymd_His') . '_' . \substr(\str_replace('.', '', (string)\microtime(true)), -6);
			$prefix = $baseName . '.';
			$backup = $backupDir . '/' . $prefix . $stamp . '.bak';
			$oldSrc = \file_get_contents($flagPath);
			if ($oldSrc === false) {
				throw new \RuntimeException('Failed to read existing flag for backup: ' . $flagPath);
			}
			if (\file_put_contents($backup, $oldSrc, \LOCK_EX) === false) {
				throw new \RuntimeException('Failed to write maintenance flag backup: ' . $backup);
			}
			$this->pruneBackups($backupDir, $prefix, (int)$policy['keep']);
		}

		// Atomic write
		if (\file_put_contents($tmpPath, $newSrc, \LOCK_EX) === false) {
			throw new \RuntimeException('Failed to write temporary flag file: ' . $tmpPath);
		}
		if (!@\rename($tmpPath, $flagPath)) {
			@\unlink($flagPath);
			if (!@\rename($tmpPath, $flagPath)) {
				@\unlink($tmpPath);
				throw new \RuntimeException('Failed to atomically move flag into place: ' . $flagPath);
			}
		}

		// Best-effort OPcache invalidation
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($flagPath, true);
		}
		@\chmod($flagPath, 0644);
	}


	/**
	 * Build the PHP payload for the maintenance flag file.
	 * Emits a minimal, side-effect free file that only "return"s an array.
	 *
	 * @param array $arr
	 * @return string
	 */
	protected function buildFlagPhp(array $arr): string {
		// Build payload using real PHP types (safe for var_export()).
		$payload = [
			'enabled'     => (bool)($arr['enabled'] ?? false),
			'allowed_ips' => \array_values((array)($arr['allowed_ips'] ?? [])),
			'retry_after' => \max(0, (int)($arr['retry_after'] ?? 300)),
		];

		// ASCII-only header; no side effects; explains provenance and policy.
		$generatedAtLocal = \date('c');  // The app's TZ (configured in http/Kernel)
		$generatedAtUtc   = \gmdate('c'); // Always UTC
		$header = <<<PHP
		<?php
		/**
		 * Generated by CitOmni Maintenance service.
		 * DO NOT EDIT THIS FILE MANUALLY.
		 *
		 * Generated at (local): {$generatedAtLocal}
		 * Generated at (UTC)  : {$generatedAtUtc}
		 *
		 * This file is intentionally side-effect free:
		 * It must only return a plain array consumed by the runtime.
		 */

		return 
		PHP;

		// Emit the array as valid PHP.
		return $header . \var_export($payload, true) . ";\n";
	}


	/**
	 * Resolve backup policy from cfg.
	 *
	 * @return array{enabled:bool,keep:int,dir:string}
	 */
	protected function resolveBackupPolicy(): array {
		$bk = $this->app->cfg->maintenance->backup ?? null;

		$enabled = true;
		$keep    = 3;
		$dir     = CITOMNI_APP_PATH . '/var/backups/flags';

		if (\is_object($bk)) {
			if (\property_exists($bk, 'enabled')) $enabled = (bool)$bk->enabled;
			if (\property_exists($bk, 'keep'))    $keep    = \max(0, (int)$bk->keep);
			if (\property_exists($bk, 'dir') && \is_string($bk->dir) && $bk->dir !== '') {
				$dir = \rtrim($bk->dir, "/\\");
			}
		}

		return ['enabled' => $enabled, 'keep' => $keep, 'dir' => $dir];
	}


	/**
	 * Delete old backups beyond the retention count.
	 *
	 * @param string $dir
	 * @param string $prefix
	 * @param int    $keep
	 * @return void
	 */
	protected function pruneBackups(string $dir, string $prefix, int $keep): void {
		if ($keep <= 0) {
			return;
		}
		$dir = \rtrim($dir, "/\\");
		if ($dir === '' || !\is_dir($dir)) {
			return;
		}
		
		$entries = @\scandir($dir);
		if ($entries === false) {
			return;
		}

		$candidates = [];
		foreach ($entries as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			if (!\str_starts_with($name, $prefix)) {
				continue;
			}
			$path = $dir . '/' . $name;
			if (!\is_file($path)) {
				continue;
			}
			$mtime = (int)(\filemtime($path) ?: 0);
			$candidates[] = ['name' => $name, 'path' => $path, 'mtime' => $mtime];
		}

		$count = \count($candidates);
		if ($count <= $keep) {
			return;
		}

		// Newest first
		\usort($candidates, function (array $a, array $b): int {
			if ($a['mtime'] === $b['mtime']) {
				return \strcmp($b['name'], $a['name']); // tie-breaker
			}
			return ($a['mtime'] > $b['mtime']) ? -1 : 1;
		});

		for ($i = $keep; $i < $count; $i++) {
			@\unlink($candidates[$i]['path']);
		}
	}


	/**
	 * Allow the literal token "unknown" only in the dev environment.
	 * Rationale: Staging should mimic production; prefer real client IPs and proper proxy config there.
	 */
	protected function allowUnknownToken(): bool {
		return \defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev';
	}
	

	/**
	 * Best-effort toggle logging (optional).
	 * Filename comes literally from cfg (maintenance.log.filename).
	 * Empty or non-string -> fallback to 'maintenance.json'.
	 */
	protected function logToggle(string $action, array $extra): void {
		if ($this->app->hasService('log')) {
			$clientIp = $this->app->request->ip();

			// Take cfg literally, unless value is '' or not a string
			$cfgName  = $this->app->cfg->maintenance->log->filename ?? null;
			$filename = (\is_string($cfgName) && $cfgName !== '') ? $cfgName : 'maintenance.json';

			$payload  = $extra + [
				'actor_ip' => $clientIp,
				'uri'      => $_SERVER['REQUEST_URI'] ?? null,
			];

			$this->app->log->write($filename, 'toggle maintenance mode', $action, $payload);
		}
	}

}
