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

use CitOmni\Http\Exception\NonceConfigException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Nonce: Filesystem-backed single-use token ledger with namespaced storage and TTL.
 *
 * Provides replay protection for primitives such as webhooks, OTP enrollment,
 * one-time form submissions, and similar single-use scenarios. Each call site
 * picks its own namespace so multiple unrelated ledgers can coexist without
 * collisions, and so opportunistic pruning can use per-namespace TTLs.
 *
 * Behavior:
 * - Storage layout: <root_dir>/<namespace>/<sha256(nonce)>.nonce
 * - First write of a nonce wins (atomic O_EXCL via fopen 'x').
 * - Subsequent writes within TTL are rejected (replay).
 * - Subsequent writes after TTL are accepted (the stale file is reaped first).
 * - Opportunistic purge runs probabilistically inside checkAndStore() to
 *   prevent unbounded growth on long-lived ledgers. Operators may also call
 *   purgeExpired() from a CLI cron for deterministic cleanup.
 * - Nonce strings must match a strict character/length whitelist to keep
 *   filesystem behavior predictable across platforms.
 *
 * Notes:
 * - Boolean return from checkAndStore() conflates "replay" and "transient
 *   storage failure" into a single negative answer. This is intentional:
 *   from a security standpoint both must reject the request, and richer
 *   telemetry can be added later without changing the call sites.
 * - Configuration shape errors fail fast at init(). Runtime storage failures
 *   return false from checkAndStore().
 *
 * Config node: nonce
 *   - dir                 string  Required. Root directory for all ledgers.
 *   - max_len             int     Optional, default 128. Maximum nonce length.
 *   - purge_probability   int     Optional, default 50. 1-in-N chance to opportunistically purge.
 *   - purge_limit         int     Optional, default 25. Max files scanned per opportunistic purge.
 *   - dir_mode            int     Optional, default 0775. Mode used when creating directories.
 *   - file_mode           int     Optional, default 0660. Mode applied to nonce files.
 *
 * Typical usage:
 *   if (!$this->app->nonce->checkAndStore('webhooks', $nonce, 300)) {
 *       // Replay or storage failure - reject the request.
 *   }
 *
 *   // CLI cron:
 *   $deleted = $this->app->nonce->purgeExpired('webhooks', 300);
 *
 * @throws NonceConfigException On invalid config at init time.
 */
final class Nonce extends BaseService {

	/** Hash algorithm used to derive the on-disk filename from the nonce string. */
	private const HASH_ALGO = 'sha256';

	/** Filename suffix for nonce ledger entries. */
	private const EXT = '.nonce';

	/** Maximum length of a namespace label (bounded for filesystem sanity). */
	private const NAMESPACE_MAX_LEN = 64;

	/** Strict allow-list pattern for namespace labels (built from NAMESPACE_MAX_LEN). */
	private const NAMESPACE_PATTERN = '/^[A-Za-z0-9_-]{1,' . self::NAMESPACE_MAX_LEN . '}$/';

	/** Resolved root directory for all namespaced ledgers (no trailing separator). */
	private string $rootDir = '';

	/** Maximum byte length of an accepted nonce string. */
	private int $maxLen = 128;

	/** Compiled regex for nonce-string validation (built from $maxLen at init). */
	private string $nonceCharPattern = '';

	/** Probability divisor for opportunistic purge (1 in N). */
	private int $purgeProbability = 50;

	/** Maximum number of directory entries scanned in one opportunistic purge. */
	private int $purgeLimit = 25;

	/** Octal mode applied to created directories. */
	private int $dirMode = 0775;

	/** Octal mode applied to created nonce files. */
	private int $fileMode = 0660;

	/** @var array<string, true> Namespaces whose subdirectory has already been ensured this request. */
	private array $ensuredNamespaces = [];




	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Read cfg.nonce, validate, and pre-derive immutable scalars.
	 *
	 * Behavior:
	 * - Verifies the configured hash algorithm is supported by ext-hash.
	 * - Does NOT pre-create the root directory; subdirectories are ensured
	 *   lazily on first use within each namespace. This keeps the service
	 *   cheap to instantiate when not actually used.
	 *
	 * @return void
	 * @throws NonceConfigException On invalid configuration.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->nonce;

		$dir = (string)($cfg->dir ?? '');
		$dir = \trim($dir);
		if ($dir === '' || \strpos($dir, "\0") !== false) {
			throw new NonceConfigException('Nonce: cfg.nonce.dir must be a non-empty path without null bytes.');
		}
		$this->rootDir = \rtrim($dir, "/\\");

		$maxLen = (int)($cfg->max_len ?? 128);
		if ($maxLen < 8 || $maxLen > 1024) {
			throw new NonceConfigException('Nonce: cfg.nonce.max_len must be between 8 and 1024.');
		}
		$this->maxLen = $maxLen;
		// Allowed characters cover hex, base64url ('_' and '-'), UUID-like
		// formats with '.' and ':', and similar URL-safe identifier schemes.
		$this->nonceCharPattern = '/^[A-Za-z0-9_.:-]{1,' . $maxLen . '}$/';

		$prob = (int)($cfg->purge_probability ?? 50);
		if ($prob < 1) {
			throw new NonceConfigException('Nonce: cfg.nonce.purge_probability must be >= 1.');
		}
		$this->purgeProbability = $prob;

		$limit = (int)($cfg->purge_limit ?? 25);
		if ($limit < 1) {
			throw new NonceConfigException('Nonce: cfg.nonce.purge_limit must be >= 1.');
		}
		$this->purgeLimit = $limit;

		$this->dirMode  = (int)($cfg->dir_mode  ?? 0775);
		$this->fileMode = (int)($cfg->file_mode ?? 0660);

		if (!\in_array(self::HASH_ALGO, \hash_algos(), true)) {
			throw new NonceConfigException('Nonce: required hash algorithm not available: ' . self::HASH_ALGO);
		}
	}








	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Atomically claim a nonce within the given namespace.
	 *
	 * Returns true when this is the first time the nonce is seen (or the
	 * previous occurrence has expired and was reaped). Returns false on
	 * replay, on malformed input, or on any storage failure.
	 *
	 * Behavior:
	 * - Uses fopen 'x' for atomic create-or-fail semantics.
	 * - On collision, checks file mtime against TTL; reaps and retries once
	 *   if the existing entry has expired.
	 * - May trigger an opportunistic purge of other expired entries in the
	 *   namespace (probability 1 in cfg.nonce.purge_probability).
	 *
	 * Notes:
	 * - The nonce string is treated as opaque; only its hash is persisted.
	 * - TTL is owned by the caller. Pick whatever fits the use case
	 *   (short-lived webhook windows, longer-lived email confirmation
	 *   tokens, multi-step enrollment flows, etc.). Values <= 0 are
	 *   rejected because they have no meaningful semantics.
	 *
	 * @param  string  $namespace    Logical bucket name (e.g. 'webhooks', 'csrf').
	 * @param  string  $nonce        Opaque single-use token string.
	 * @param  int     $ttlSeconds   Validity window in seconds (must be > 0).
	 * @return bool                  True on success, false on replay/storage failure.
	 */
	public function checkAndStore(string $namespace, string $nonce, int $ttlSeconds): bool {
		if ($ttlSeconds <= 0) {
			return false;
		}
		if ($nonce === '' || \strlen($nonce) > $this->maxLen) {
			return false;
		}
		if (!\preg_match($this->nonceCharPattern, $nonce)) {
			return false;
		}
		if (!\preg_match(self::NAMESPACE_PATTERN, $namespace)) {
			return false;
		}

		$nsDir = $this->ensureNamespaceDir($namespace);
		if ($nsDir === null) {
			return false;
		}

		$path = $nsDir . \DIRECTORY_SEPARATOR . \hash(self::HASH_ALGO, $nonce) . self::EXT;
		$now  = \time();

		$fh = @\fopen($path, 'x');
		if ($fh === false) {
			// Either it exists, or fopen failed for another reason. If it exists,
			// we may be able to reap an expired entry and retry once.
			if (!\is_file($path)) {
				return false;
			}
			$mt = @\filemtime($path);
			if ($mt === false) {
				return false;
			}
			$age = $now - (int)$mt;
			if ($age < $ttlSeconds) {
				return false;
			}
			@\unlink($path);
			$fh = @\fopen($path, 'x');
			if ($fh === false) {
				return false;
			}
		}

		// Best-effort write of the timestamp (file presence alone is what counts).
		@\fwrite($fh, (string)$now);
		@\fclose($fh);
		@\touch($path, $now);
		@\chmod($path, $this->fileMode);

		$this->maybePurge($nsDir, $ttlSeconds);
		return true;
	}


	/**
	 * Deterministically prune expired nonces in a namespace.
	 *
	 * Intended for CLI cron usage. Scans up to $max entries in the namespace
	 * directory and removes those whose mtime is older than $ttlSeconds.
	 *
	 * @param  string  $namespace    Logical bucket name.
	 * @param  int     $ttlSeconds   Age threshold for removal.
	 * @param  int     $max          Maximum entries to scan in one call.
	 * @return int                   Number of files actually removed.
	 */
	public function purgeExpired(string $namespace, int $ttlSeconds, int $max = 500): int {
		if ($ttlSeconds <= 0 || $max <= 0) {
			return 0;
		}
		if (!\preg_match(self::NAMESPACE_PATTERN, $namespace)) {
			return 0;
		}

		$nsDir = $this->rootDir . \DIRECTORY_SEPARATOR . $namespace;
		if (!\is_dir($nsDir)) {
			return 0;
		}

		$dh = @\opendir($nsDir);
		if ($dh === false) {
			return 0;
		}

		$now = \time();
		$removed = 0;
		$processed = 0;

		while ($processed < $max && ($entry = \readdir($dh)) !== false) {
			if ($entry === '.' || $entry === '..' || !$this->hasNonceExt($entry)) {
				continue;
			}
			$path = $nsDir . \DIRECTORY_SEPARATOR . $entry;
			if (!\is_file($path)) {
				continue;
			}
			$mt = @\filemtime($path);
			if ($mt !== false && ($now - (int)$mt) >= $ttlSeconds) {
				if (@\unlink($path)) {
					$removed++;
				}
			}
			$processed++;
		}
		@\closedir($dh);

		return $removed;
	}








	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Ensure the per-namespace subdirectory exists and is writable.
	 *
	 * The result is memoized per request to avoid repeated stat() calls when
	 * the same namespace is hit multiple times.
	 *
	 * @param  string   $namespace
	 * @return string|null  Absolute namespace directory, or null on failure.
	 */
	private function ensureNamespaceDir(string $namespace): ?string {
		$nsDir = $this->rootDir . \DIRECTORY_SEPARATOR . $namespace;

		if (isset($this->ensuredNamespaces[$namespace])) {
			return $nsDir;
		}

		if (!\is_dir($nsDir)) {
			@\mkdir($nsDir, $this->dirMode, true);
			if (!\is_dir($nsDir)) {
				return null;
			}
		}
		if (!\is_writable($nsDir)) {
			return null;
		}

		$this->ensuredNamespaces[$namespace] = true;
		return $nsDir;
	}


	/**
	 * Probabilistic, bounded scan for expired entries.
	 *
	 * Designed to keep amortized cost near zero while preventing unbounded
	 * directory growth on busy ledgers. Failures are silent - purging is
	 * housekeeping, not load-bearing.
	 *
	 * @param  string  $nsDir
	 * @param  int     $ttlSeconds
	 * @return void
	 */
	private function maybePurge(string $nsDir, int $ttlSeconds): void {
		if (\mt_rand(1, $this->purgeProbability) !== 1) {
			return;
		}

		$dh = @\opendir($nsDir);
		if ($dh === false) {
			return;
		}

		$now = \time();
		$processed = 0;

		while ($processed < $this->purgeLimit && ($entry = \readdir($dh)) !== false) {
			if ($entry === '.' || $entry === '..' || !$this->hasNonceExt($entry)) {
				continue;
			}
			$path = $nsDir . \DIRECTORY_SEPARATOR . $entry;
			if (!\is_file($path)) {
				continue;
			}
			$mt = @\filemtime($path);
			if ($mt !== false && ($now - (int)$mt) >= $ttlSeconds) {
				@\unlink($path);
			}
			$processed++;
		}
		@\closedir($dh);
	}


	/**
	 * Cheap suffix check that avoids full pathinfo() overhead.
	 */
	private function hasNonceExt(string $name): bool {
		$len = \strlen(self::EXT);
		return \strlen($name) >= $len && \substr($name, -$len) === self::EXT;
	}

}
