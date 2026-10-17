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
 * Maintenance: Service that enforces and toggles the site-wide maintenance mode.
 *
 * Keeps the maintenance state in a flag file, so a toggle reaches every later request and
 * process without a deploy, and the flag wins over cfg. Who may toggle the mode belongs to
 * the caller; the look of the branded maintenance page belongs to its template.
 *
 * Rules:
 * - Read the state only through snapshot(), so one request sees one state.
 * - Change the flag only through writeFlagFile(), and clear the snapshot after every write.
 * - Keep the flag file a PHP file that only returns an array: It is included, not parsed.
 * - Accept the literal "unknown" in an allowlist only where allowUnknownToken() allows it.
 */
class Maintenance extends BaseService {

	/** @var array{enabled:bool,allowed_ips:list<string>,retry_after:int,source:'flag'|'cfg'}|null Result of snapshot(); null while not built. */
	private ?array $snapshot = null;


	/** Retry-After in seconds for the next flag write only; null means cfg maintenance.flag.default_retry_after. */
	protected ?int $writeRetryAfter = null;


	/** Regex of a backup name after the flag file name and a dot: Ymd_His, six digits of microseconds, an optional 12-hex-digit nonce, then .bak. */
	private const BACKUP_SUFFIX_PATTERN = '\d{8}_\d{6}_\d{6}(?:_[0-9a-f]{12})?\.bak';


	/**
	 * Answer 503 and end the process while maintenance is on, unless the request may pass.
	 *
	 * Call it before routing, so a blocked request never reaches a controller.
	 *
	 * Behavior:
	 * - Returns without output when maintenance is off, when the app-relative request path
	 *   starts with /_system/, or when Request::ip() is in the allowlist.
	 * - Otherwise answers 503 and exits:
	 *     - Status and headers, unless headers were already sent: Retry-After with the snapshot's
	 *       retry_after, Cache-Control no-store, Pragma no-cache, X-Robots-Tag noindex and
	 *       noarchive, Content-Type text/html with cfg locale.charset (UTF-8 without it), and
	 *       Connection close.
	 *     - Body: The file at cfg maintenance.flag.template when it is a readable file,
	 *       included with $retry_after (seconds), $resume_at (now plus retry_after, as
	 *       'Y-m-d H:i' in PHP's default timezone) and $contact_email (cfg identity.email,
	 *       else cfg support_email, else support@citomni.com); otherwise a minimal built-in
	 *       HTML page.
	 * - Writes no file.
	 *
	 * Notes:
	 * - Requests under /_system/ pass unchecked: Every route there must authenticate its caller.
	 * - The template is included in this method's scope, so it also sees the other locals.
	 *
	 * Typical usage:
	 *   $app->maintenance->guard();
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Maintenance::snapshot()  Where the state, the allowlist and retry_after come from.
	 */
	public function guard(): void {
		
		$state = $this->snapshot();

		if (!$state['enabled']) {
			return;
		}

		// System endpoints stay reachable under maintenance. Assumes each one authenticates its caller.
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		$path = (string)(\explode('?', $uri, 2)[0] ?? '');
		if ($path !== '' && $path[0] !== '/') {
			$path = '/' . $path;
		}

		// The app base path comes from SCRIPT_NAME, so an app in a subdirectory matches too.
		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		$base = \rtrim(\str_replace('\\', '/', \dirname($script)), '/');

		// An entry script in public/ serves the app from the parent directory.
		if ($base !== '' && \substr($base, -7) === '/public') {
			$base = \substr($base, 0, -7);
			$base = $base === '' ? '' : \rtrim($base, '/');
		}

		if ($base !== '' && ($path === $base || \str_starts_with($path, $base . '/'))) {
			$path = \substr($path, \strlen($base));
			if ($path === '') {
				$path = '/';
			}
		}

		if (\str_starts_with($path, '/_system/')) {
			return;
		}

		$clientIp = $this->app->request->ip();

		if ($clientIp !== null && \in_array($clientIp, $state['allowed_ips'], true)) {
			return;
		}

		$retry = $state['retry_after'];

		// No cache may keep serving the 503 after maintenance ends.
		if (!\headers_sent()) {
			\http_response_code(503);
			\header('Retry-After: ' . $retry);
			\header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			\header('Pragma: no-cache');
			\header('X-Robots-Tag: noindex, noarchive');
			\header('Content-Type: text/html; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
			\header('Connection: close');
		}

		$template = (string)$this->app->cfg->maintenance->flag->template;
		if ($template !== '' && \is_file($template) && \is_readable($template)) {

			// Variables meant for the template.
			$retry_after   = $retry;
			$contact_email = (string)($this->app->cfg->identity->email ?? ($this->app->cfg->support_email ?? 'support@citomni.com'));

			// Assumes PHP's default timezone is the app's timezone.
			$now = new \DateTimeImmutable('now');
			$resumeAt = $now->add(new \DateInterval('PT' . $retry . 'S'));
			$resume_at = $resumeAt->format('Y-m-d H:i');

			include $template;
		} else {
			echo '<!doctype html><html><head><meta charset="utf-8"><title>Service Unavailable</title></head><body>'
				. '<h1>We&rsquo;ll be right back</h1>'
				. '<p>The site is temporarily down for maintenance. Please try again later.</p>'
				. '</body></html>';
		}

		exit;
	}


	/**
	 * Check whether maintenance mode is on.
	 *
	 * @return bool
	 * @see \CitOmni\Http\Service\Maintenance::snapshot()  Where the state comes from.
	 */
	public function isEnabled(): bool {
		return $this->snapshot()['enabled'];
	}


	/**
	 * Return the client identifiers that pass guard() while maintenance is on.
	 *
	 * @return list<string>  Valid IP addresses, and "unknown" where allowUnknownToken() allows it; unique and sorted.
	 * @see \CitOmni\Http\Service\Maintenance::snapshot()  Where the allowlist comes from.
	 */
	public function getAllowedIps(): array {
		return $this->snapshot()['allowed_ips'];
	}


	/**
	 * Set the Retry-After that the next enable() or disable() writes to the flag.
	 *
	 * Behavior:
	 * - Applies to the next write only; the write clears it.
	 * - Does not change what guard() or getRetryAfter() report before that write.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->setRetryAfter(900)->enable([]);
	 *
	 * @param  int  $seconds  Seconds; a negative value counts as 0.
	 * @return self  This instance, for chaining.
	 */
	public function setRetryAfter(int $seconds): self {
		$this->writeRetryAfter = \max(0, $seconds);
		return $this;
	}


	/**
	 * Return the Retry-After that guard() sends.
	 *
	 * @return int  Seconds, at least 0.
	 * @see \CitOmni\Http\Service\Maintenance::snapshot()  Where retry_after comes from.
	 */
	public function getRetryAfter(): int {
		return $this->snapshot()['retry_after'];
	}


	/**
	 * Return the maintenance state from the flag file, with cfg as fallback.
	 *
	 * The flag wins over cfg, so a toggle takes effect without a cfg change.
	 *
	 * Behavior:
	 * - When the flag file is readable and returns an array (source 'flag'):
	 *     - enabled is the flag's enabled, false when absent.
	 *     - allowed_ips is the flag's list when it has one, also when empty; otherwise cfg
	 *       maintenance.flag.allowed_ips.
	 *     - retry_after is the flag's retry_after when present; otherwise cfg
	 *       maintenance.flag.default_retry_after.
	 * - Otherwise (source 'cfg'): enabled is false; allowed_ips and retry_after come from cfg
	 *   as above.
	 * - allowed_ips keeps only valid IP addresses, plus "unknown" where allowUnknownToken()
	 *   allows it; trimmed, unique and sorted. retry_after is at least 0.
	 * - Later calls return the same array until enable() or disable() writes the flag.
	 *
	 * Typical usage:
	 *   $state = $this->app->maintenance->snapshot();
	 *
	 * @return array{enabled:bool,allowed_ips:list<string>,retry_after:int,source:'flag'|'cfg'}  retry_after in seconds.
	 */
	public function snapshot(): array {
		if ($this->snapshot !== null) {
			return $this->snapshot;
		}

		$cfgDefaultRetry = \max(0, (int)$this->app->cfg->maintenance->flag->default_retry_after);
		// An empty list is a Cfg node, not an array, and means no allowlist.
		$cfgAllowlist    = $this->app->cfg->maintenance->flag->allowed_ips;
		$cfgAllowlist    = \is_array($cfgAllowlist) ? $cfgAllowlist : [];

		$flag     = $this->readFlag();
		$fromFlag = \is_array($flag);

		$enabledRaw = $fromFlag ? (bool)($flag['enabled'] ?? false) : false;
		$retryRaw   = $fromFlag && \array_key_exists('retry_after', $flag)
			? (int)$flag['retry_after']
			: $cfgDefaultRetry;

		// A list in the flag wins also when empty: An empty list means that no client passes.
		$flagHasAllow = $fromFlag && \array_key_exists('allowed_ips', $flag) && \is_array($flag['allowed_ips']);
		$allowedRaw   = $flagHasAllow ? (array)$flag['allowed_ips'] : $cfgAllowlist;

		$enabled = (bool)$enabledRaw;
		$allowed = $this->normalizeIps($allowedRaw);
		$retry   = \max(0, (int)$retryRaw);
		$source  = $fromFlag ? 'flag' : 'cfg';

		return $this->snapshot = [
			'enabled'     => $enabled,
			'allowed_ips' => $allowed,
			'retry_after' => $retry,
			'source'      => $source,
		];
	}


	/**
	 * Turn maintenance mode on and write it, with its allowlist, to the flag file.
	 *
	 * The flag file is what every later request reads, so the change reaches them without a
	 * cfg change or a deploy.
	 *
	 * Behavior:
	 * - Writes enabled true, the allowlist and retry_after to the flag file:
	 *     - The allowlist is $ips plus the caller's Request::ip(), reduced as in snapshot()
	 *       to unique, valid entries.
	 *     - retry_after is the setRetryAfter() value when set, otherwise cfg
	 *       maintenance.flag.default_retry_after; at least 0.
	 * - Writes also when maintenance is already on.
	 * - Then clears the snapshot and the setRetryAfter() value, and logs the toggle.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->setRetryAfter(600)->enable(['203.0.113.10']);
	 *
	 * @param  array<mixed>  $ips  IP addresses that pass guard(); other entries are dropped.
	 * @return bool  Always true; a failure throws.
	 * @throws \RuntimeException  When the flag file, its directory or a backup cannot be written.
	 * @throws \UnexpectedValueException  When cfg maintenance.backup.dir is not a non-empty string.
	 * @see \CitOmni\Http\Service\Maintenance::writeFlagFile()  How the flag file is replaced and backed up.
	 * @see \CitOmni\Http\Service\Maintenance::logToggle()  What is logged.
	 */
	public function enable(array $ips): bool {
		// The caller passes too, so whoever turns maintenance on is not locked out.
		$clientIp = $this->app->request->ip();
		if ($clientIp === 'unknown' && !$this->allowUnknownToken()) {
			$clientIp = null;
		}
		if ($clientIp !== null) {
			$ips[] = $clientIp;
		}

		$allowlist = $this->normalizeIps($ips);

		$retry = \max(0, (int)($this->writeRetryAfter ?? $this->app->cfg->maintenance->flag->default_retry_after));

		$this->writeFlagFile(true, $allowlist, $retry);

		$this->snapshot = null;
		$this->writeRetryAfter = null;

		$this->logToggle('enabled', ['allowed_ips' => $allowlist]);

		return true;
	}


	/**
	 * Turn maintenance mode off and write it, with an empty allowlist, to the flag file.
	 *
	 * The flag file is what every later request reads, so the change reaches them without a
	 * cfg change or a deploy.
	 *
	 * Behavior:
	 * - Writes enabled false, an empty allowlist and retry_after to the flag file; retry_after
	 *   is the setRetryAfter() value when set, otherwise cfg maintenance.flag.default_retry_after;
	 *   at least 0.
	 * - Writes also when maintenance is already off.
	 * - Then logs the toggle, and clears the snapshot and the setRetryAfter() value.
	 *
	 * Typical usage:
	 *   $this->app->maintenance->disable();
	 *
	 * @return bool  Always true; a failure throws.
	 * @throws \RuntimeException  When the flag file, its directory or a backup cannot be written.
	 * @throws \UnexpectedValueException  When cfg maintenance.backup.dir is not a non-empty string.
	 * @see \CitOmni\Http\Service\Maintenance::writeFlagFile()  How the flag file is replaced and backed up.
	 * @see \CitOmni\Http\Service\Maintenance::logToggle()  What is logged.
	 */
	public function disable(): bool {
		$retry = $this->writeRetryAfter ?? (int)$this->app->cfg->maintenance->flag->default_retry_after;
		$retry = \max(0, $retry);

		$this->writeFlagFile(false, [], $retry);
		
		$this->logToggle('disabled', []);

		$this->snapshot = null;
		$this->writeRetryAfter = null;

		return true;
	}


	/**
	 * Include the flag file and return its array.
	 *
	 * @return array<mixed>|null  The array the file returns; null when the file is missing, unreadable or returns no array.
	 */
	protected function readFlag(): ?array {
		$path = $this->getFlagPath();
		if (!\is_file($path) || !\is_readable($path)) {
			return null;
		}
		\clearstatcache(true, $path);
		$data = include $path;
		return \is_array($data) ? $data : null;
	}


	/**
	 * Return the path of the flag file.
	 *
	 * @return string  Cfg maintenance.flag.path when it is a non-empty string; otherwise var/flags/maintenance.php under CITOMNI_APP_PATH.
	 */
	protected function getFlagPath(): string {
		$path = $this->app->cfg->maintenance->flag->path;
		if (\is_string($path) && $path !== '') {
			return $path;
		}
		return CITOMNI_APP_PATH . '/var/flags/maintenance.php';
	}


	/**
	 * Reduce a list to unique, valid client identifiers.
	 *
	 * Behavior:
	 * - Keeps trimmed strings that are valid IPv4 or IPv6 addresses, and "unknown" where
	 *   allowUnknownToken() allows it. Drops everything else.
	 * - Removes duplicates and sorts as strings.
	 *
	 * @param  array<mixed>  $ips
	 * @return list<string>
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
	 * Replace the flag file atomically, and back up the file it replaces.
	 *
	 * The new flag appears by rename, so a concurrent reader never includes a partial file.
	 * Where rename cannot replace an existing file, the old one is deleted first, and a reader
	 * in between finds no flag.
	 *
	 * Behavior:
	 * - Checks the backup policy before it creates a directory or writes a file.
	 * - Creates the flag directory when missing.
	 * - When backups are on, keep is above 0 and a readable flag file exists: Copies it to the
	 *   backup directory first, as the flag file name, a dot, local Ymd_His, six digits of
	 *   microseconds, the write's 12-hex-digit nonce and .bak, then prunes the backups to keep.
	 *   Otherwise it writes no backup and deletes none.
	 * - Invalidates the flag file in OPcache and sets mode 0644, both best effort.
	 *
	 * Notes:
	 * - Date, time and microseconds come from one clock reading, so the backup names of one
	 *   second sort by time. The nonce keeps concurrent writes apart.
	 *
	 * @param  bool  $enabled
	 * @param  list<string>  $allowedIps  Allowlist as normalizeIps() returns it.
	 * @param  int  $retryAfter  Seconds; a negative value is stored as 0.
	 * @return void
	 * @throws \RuntimeException  When a directory cannot be created or is not writable, or a file cannot be read, written or moved into place.
	 * @throws \UnexpectedValueException  When cfg maintenance.backup.dir is not a non-empty string.
	 * @see \CitOmni\Http\Service\Maintenance::resolveBackupPolicy()  The backup policy.
	 */
	protected function writeFlagFile(bool $enabled, array $allowedIps, int $retryAfter): void {
		$policy   = $this->resolveBackupPolicy();
		$flagPath = $this->getFlagPath();
		$flagDir  = \dirname($flagPath);
		$baseName = \basename($flagPath);
		$nonce    = \bin2hex(\random_bytes(6));
		$tmpPath  = $flagPath . '.' . $nonce . '.tmp';

		// mkdir() fails when a concurrent request created the directory first; is_dir() decides.
		if (!\is_dir($flagDir) && !@\mkdir($flagDir, 0755, true) && !\is_dir($flagDir)) {
			throw new \RuntimeException('Unable to create flag dir: ' . $flagDir);
		}
		if (!\is_writable($flagDir)) {
			throw new \RuntimeException('Flag dir not writable: ' . $flagDir);
		}

		$newArr = [
			'enabled'     => (bool)$enabled,
			'allowed_ips' => \array_values($allowedIps),
			'retry_after' => \max(0, (int)$retryAfter),
		];
		$newSrc = $this->buildFlagPhp($newArr);

		$doBackup = $policy['enabled'] && $policy['keep'] !== 0 && \is_file($flagPath) && \is_readable($flagPath);

		if ($doBackup) {
			$backupDir = $policy['dir'];
			// mkdir() fails when a concurrent request created the directory first; is_dir() decides.
			if (!\is_dir($backupDir) && !@\mkdir($backupDir, 0755, true) && !\is_dir($backupDir)) {
				throw new \RuntimeException('Unable to create backup dir: ' . $backupDir);
			}
			if (!\is_writable($backupDir)) {
				throw new \RuntimeException('Backup dir not writable: ' . $backupDir);
			}
			// microtime() returns "0.12345600 1760090000": fixed-width microseconds and their second.
			// rtrim() keeps a root such as "/" or "C:\" a root: The name follows one separator.
			[$usec, $sec] = \explode(' ', \microtime());
			$prefix = $baseName . '.';
			$backup = \rtrim($backupDir, "/\\") . '/' . $prefix . \date('Ymd_His', (int)$sec) . '_' . \substr($usec, 2, 6) . '_' . $nonce . '.bak';
			$oldSrc = \file_get_contents($flagPath);
			if ($oldSrc === false) {
				throw new \RuntimeException('Failed to read existing flag for backup: ' . $flagPath);
			}
			if (\file_put_contents($backup, $oldSrc, \LOCK_EX) === false) {
				throw new \RuntimeException('Failed to write maintenance flag backup: ' . $backup);
			}
			$this->pruneBackups($backupDir, $prefix, (int)$policy['keep']);
		}

		// Write a temporary file and rename it, so a reader never includes a partial flag.
		if (\file_put_contents($tmpPath, $newSrc, \LOCK_EX) === false) {
			throw new \RuntimeException('Failed to write temporary flag file: ' . $tmpPath);
		}
		if (!@\rename($tmpPath, $flagPath)) {
			// Where rename cannot replace an existing file, delete it and retry.
			@\unlink($flagPath);
			if (!@\rename($tmpPath, $flagPath)) {
				@\unlink($tmpPath);
				throw new \RuntimeException('Failed to atomically move flag into place: ' . $flagPath);
			}
		}

		// The flag is included as PHP, so OPcache must not keep serving the old file.
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($flagPath, true);
		}
		@\chmod($flagPath, 0644);
	}


	/**
	 * Build the PHP source of a flag file.
	 *
	 * Behavior:
	 * - The source only returns an array with enabled (bool, false when absent), allowed_ips
	 *   (a list, empty when absent) and retry_after (int, at least 0, 300 when absent).
	 * - A comment header carries the generation time in PHP's default timezone and in UTC.
	 *
	 * @param  array<string,mixed>  $arr  Values for enabled, allowed_ips and retry_after; other keys are ignored.
	 * @return string
	 */
	protected function buildFlagPhp(array $arr): string {
		// Plain scalars and lists only, so var_export() writes a literal that include returns as is.
		$payload = [
			'enabled'     => (bool)($arr['enabled'] ?? false),
			'allowed_ips' => \array_values((array)($arr['allowed_ips'] ?? [])),
			'retry_after' => \max(0, (int)($arr['retry_after'] ?? 300)),
		];

		$generatedAtLocal = \date('c');
		$generatedAtUtc   = \gmdate('c');
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

		return $header . \var_export($payload, true) . ";\n";
	}


	/**
	 * Read and check the backup policy in cfg maintenance.backup.
	 *
	 * Notes:
	 * - Checks dir also while backups are off.
	 *
	 * @return array{enabled:bool,keep:int,dir:string}  keep is at least 0; dir is returned as configured.
	 * @throws \UnexpectedValueException  When cfg maintenance.backup.dir is not a non-empty string.
	 */
	protected function resolveBackupPolicy(): array {
		$backup  = $this->app->cfg->maintenance->backup;
		$enabled = (bool)$backup->enabled;
		$keep    = \max(0, (int)$backup->keep);
		$dir     = $backup->dir;

		if (!\is_string($dir) || $dir === '') {
			throw new \UnexpectedValueException('Config maintenance.backup.dir must be a non-empty string.');
		}

		return ['enabled' => $enabled, 'keep' => $keep, 'dir' => $dir];
	}


	/**
	 * Delete the backups beyond the $keep newest.
	 *
	 * Behavior:
	 * - Considers only files named $prefix followed by BACKUP_SUFFIX_PATTERN, with or without
	 *   the nonce. Other files in $dir are never deleted, also when they start with $prefix.
	 * - Keeps the $keep newest by mtime; of two equal mtimes, the greater name counts as newer.
	 * - Does nothing when $keep is below 1 or $dir cannot be read. Deletes best effort.
	 *
	 * @param  string  $dir  Backup directory.
	 * @param  string  $prefix  Flag file name and a dot.
	 * @param  int  $keep  Number of backups to keep.
	 * @return void
	 */
	protected function pruneBackups(string $dir, string $prefix, int $keep): void {
		if ($keep <= 0 || !\is_dir($dir)) {
			return;
		}

		$entries = @\scandir($dir);
		if ($entries === false) {
			return;
		}

		$pattern = '/^' . \preg_quote($prefix, '/') . self::BACKUP_SUFFIX_PATTERN . '$/D';
		$base    = \rtrim($dir, "/\\") . '/';

		$candidates = [];
		foreach ($entries as $name) {
			if (\preg_match($pattern, $name) !== 1) {
				continue;
			}
			$path = $base . $name;
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

		// Newest first. Names sort by time within one second, so they break mtime ties.
		\usort($candidates, function (array $a, array $b): int {
			if ($a['mtime'] === $b['mtime']) {
				return \strcmp($b['name'], $a['name']);
			}
			return ($a['mtime'] > $b['mtime']) ? -1 : 1;
		});

		for ($i = $keep; $i < $count; $i++) {
			@\unlink($candidates[$i]['path']);
		}
	}


	/**
	 * Check whether the literal "unknown" may stand in an allowlist.
	 *
	 * The token matches every client for which Request::ip() has no public address, such as a
	 * client on localhost.
	 *
	 * @return bool  True only when CITOMNI_ENVIRONMENT is dev.
	 * @see \CitOmni\Http\Service\Request::ip()  When it answers "unknown".
	 */
	protected function allowUnknownToken(): bool {
		// Not in stage: Stage should behave like production, with real client IPs and proxy cfg.
		return \defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev';
	}
	

	/**
	 * Log a maintenance toggle, when a log service is registered.
	 *
	 * Behavior:
	 * - Does nothing without a log service.
	 * - Writes one entry with category 'toggle maintenance mode', $action as message, and
	 *   $extra plus actor_ip (Request::ip()) and uri (REQUEST_URI, or null) as context.
	 * - Passes cfg maintenance.log.filename as the log file name; maintenance.json when that
	 *   is empty or not a string.
	 *
	 * @param  string  $action  Log message, such as enabled.
	 * @param  array<string,mixed>  $extra  Context fields; they win over actor_ip and uri.
	 * @return void
	 * @see \CitOmni\Infrastructure\Service\Log::write()  How the entry is written, and its exceptions.
	 */
	protected function logToggle(string $action, array $extra): void {
		if ($this->app->hasService('log')) {
			$clientIp = $this->app->request->ip();

			$cfgName  = $this->app->cfg->maintenance->log->filename;
			$filename = (\is_string($cfgName) && $cfgName !== '') ? $cfgName : 'maintenance.json';

			$payload  = $extra + [
				'actor_ip' => $clientIp,
				'uri'      => $_SERVER['REQUEST_URI'] ?? null,
			];

			$this->app->log->write($filename, 'toggle maintenance mode', $action, $payload);
		}
	}

}
