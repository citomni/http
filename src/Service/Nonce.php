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
 * Nonce: Service for a filesystem ledger of single-use tokens, kept per namespace for a TTL.
 *
 * Gives replay protection to single-use flows such as signed webhooks: A nonce is accepted
 * once within its TTL. Each caller picks its own namespace and TTL, so unrelated ledgers do
 * not collide. What a nonce protects, and how a rejection is answered, belongs to the callers.
 *
 * Rules:
 * - Store only a hash of a nonce, never the nonce itself.
 * - Answer a replay, malformed input and a storage failure alike with a negative result, never
 *   with an exception: Each means that single use cannot be proven, so the caller rejects.
 * - Check cfg values once, in init(), and report a wrong one with NonceConfigException.
 * - Bound every purge by a number of scanned entries.
 */
class Nonce extends BaseService {

	/** Hash algorithm that turns a nonce into the file name of its entry. */
	private const HASH_ALGO = 'sha256';

	/** File name suffix of a ledger entry. */
	private const EXT = '.nonce';

	/** Maximum length of a namespace name, in characters. */
	private const NAMESPACE_MAX_LEN = 64;

	/** Pattern of a valid namespace name: ASCII letters, digits, _ and -. */
	private const NAMESPACE_PATTERN = '/^[A-Za-z0-9_-]{1,' . self::NAMESPACE_MAX_LEN . '}$/';

	/** Root directory of all namespaces, without a trailing separator. */
	private string $rootDir = '';

	/** Maximum length of an accepted nonce, in bytes. */
	private int $maxLen = 128;

	/** Pattern of a valid nonce: ASCII letters, digits, _ . : and -, at most $maxLen of them. */
	private string $nonceCharPattern = '';

	/** Chance of an opportunistic purge after a stored nonce, as 1 in this number. */
	private int $purgeProbability = 50;

	/** Maximum number of entries scanned in one opportunistic purge. */
	private int $purgeLimit = 25;

	/** Permission mode of a directory that the ledger creates. */
	private int $dirMode = 0775;

	/** Permission mode of a stored entry. */
	private int $fileMode = 0660;

	/** @var array<string,true> Namespaces whose directory this instance has found usable. */
	private array $ensuredNamespaces = [];




	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Read and check the nonce cfg once.
	 *
	 * Behavior:
	 * - Requires, under cfg nonce, with dir cast to string and the numbers to int:
	 *     - dir: Not empty after trimming, and without NUL bytes.
	 *     - max_len: From 8 to 1024.
	 *     - purge_probability and purge_limit: At least 1.
	 * - Touches no directory: A namespace directory is created on its first use.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\NonceConfigException  When a value breaks these requirements, or PHP's hash extension lacks HASH_ALGO.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->nonce;

		$dir = (string)$cfg->dir;
		$dir = \trim($dir);
		if ($dir === '' || \strpos($dir, "\0") !== false) {
			throw new NonceConfigException('Nonce: cfg.nonce.dir must be a non-empty path without null bytes.');
		}
		$this->rootDir = \rtrim($dir, "/\\");

		$maxLen = (int)$cfg->max_len;
		if ($maxLen < 8 || $maxLen > 1024) {
			throw new NonceConfigException('Nonce: cfg.nonce.max_len must be between 8 and 1024.');
		}
		$this->maxLen = $maxLen;
		// Allowed characters cover hex, base64url ('_' and '-'), UUID-like
		// formats with '.' and ':', and similar URL-safe identifier schemes.
		$this->nonceCharPattern = '/^[A-Za-z0-9_.:-]{1,' . $maxLen . '}$/';

		$prob = (int)$cfg->purge_probability;
		if ($prob < 1) {
			throw new NonceConfigException('Nonce: cfg.nonce.purge_probability must be >= 1.');
		}
		$this->purgeProbability = $prob;

		$limit = (int)$cfg->purge_limit;
		if ($limit < 1) {
			throw new NonceConfigException('Nonce: cfg.nonce.purge_limit must be >= 1.');
		}
		$this->purgeLimit = $limit;

		$this->dirMode  = (int)$cfg->dir_mode;
		$this->fileMode = (int)$cfg->file_mode;

		if (!\in_array(self::HASH_ALGO, \hash_algos(), true)) {
			throw new NonceConfigException('Nonce: required hash algorithm not available: ' . self::HASH_ALGO);
		}
	}








	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Claim a nonce in a namespace, once within the TTL.
	 *
	 * Records the nonce so that a second claim within the TTL fails. The TTL belongs to the
	 * caller: It must cover the whole time in which the protected request would still be
	 * accepted.
	 *
	 * Behavior:
	 * - Returns true when no entry for the nonce exists, or the existing one is at least
	 *   $ttlSeconds old; the entry is then stored with the current time.
	 * - Of concurrent claims of a nonce that has no entry yet, at most one returns true.
	 * - Returns false for a replay within the TTL, for a TTL below 1, for a nonce that is
	 *   empty, longer than cfg nonce.max_len bytes or has a character outside A-Z, a-z, 0-9,
	 *   _ . : and -, for a namespace outside NAMESPACE_PATTERN, and when the namespace
	 *   directory cannot be created or written or the entry cannot be read or stored.
	 * - Creates the namespace directory on first use.
	 * - After a stored nonce, purges expired entries of the namespace with a chance of 1 in
	 *   cfg nonce.purge_probability, scanning at most cfg nonce.purge_limit entries.
	 *
	 * Notes:
	 * - The purge judges expiry by this call's TTL: Callers that share a namespace must use
	 *   the same TTL, or a shorter one removes entries that a longer one still needs.
	 * - The nonce and namespace checks let one trailing line feed through, because their
	 *   patterns end in $ without the D modifier.
	 *
	 * Typical usage:
	 *   if (!$this->app->nonce->checkAndStore('webhooks', $nonce, 300)) {
	 *       // Replay or storage failure: Reject the request.
	 *   }
	 *
	 * @param  string  $namespace  Ledger name, such as webhooks.
	 * @param  string  $nonce  Single-use token; only its hash is stored.
	 * @param  int  $ttlSeconds  How long a claim blocks the same nonce, in seconds; at least 1.
	 * @return bool  True when this call claimed the nonce.
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

		// Mode 'x' creates the file or fails when it exists, atomically, so the first claim wins.
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

		// Best-effort write of the timestamp: Only the entry's presence and mtime count.
		@\fwrite($fh, (string)$now);
		@\fclose($fh);
		@\touch($path, $now);
		@\chmod($path, $this->fileMode);

		$this->maybePurge($nsDir, $ttlSeconds);
		return true;
	}


	/**
	 * Remove expired entries of a namespace.
	 *
	 * For deterministic cleanup of busy or long-lived ledgers, such as from a scheduled CLI
	 * command, next to the opportunistic purge of checkAndStore().
	 *
	 * Behavior:
	 * - Scans at most $max ledger entries and removes those at least $ttlSeconds old; other
	 *   files in the directory are left alone and do not count.
	 * - Removes nothing and returns 0 for a TTL or $max below 1, an invalid namespace, and a
	 *   namespace without a readable directory.
	 *
	 * Typical usage:
	 *   $removed = $this->app->nonce->purgeExpired('webhooks', 300);
	 *
	 * @param  string  $namespace  Ledger name, such as webhooks.
	 * @param  int  $ttlSeconds  Age from which an entry has expired, in seconds; use the TTL of the claims.
	 * @param  int  $max  Maximum number of entries scanned.
	 * @return int  Number of entries removed.
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
	 * Return the directory of a namespace, created and writable.
	 *
	 * Behavior:
	 * - Creates the directory, with missing parents, when it does not exist.
	 * - Remembers a usable namespace per instance; later calls for it return the path without
	 *   touching the filesystem.
	 *
	 * @param  string  $namespace  Valid namespace name.
	 * @return string|null  The directory, or null when it cannot be created or is not writable.
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
	 * Remove expired entries of a namespace directory, with a chance of 1 in $purgeProbability.
	 *
	 * Keeps a busy ledger from growing without bound at a small amortized cost.
	 *
	 * Behavior:
	 * - Scans at most $purgeLimit ledger entries and removes those at least $ttlSeconds old.
	 * - Fails silently: Purging is housekeeping, so a filesystem error only ends or skips it.
	 *
	 * @param  string  $nsDir  Namespace directory.
	 * @param  int  $ttlSeconds  Age from which an entry has expired, in seconds.
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
	 * Check whether a file name ends with the ledger entry suffix.
	 *
	 * @param  string  $name  File name without directory.
	 * @return bool
	 */
	private function hasNonceExt(string $name): bool {
		$len = \strlen(self::EXT);
		return \strlen($name) >= $len && \substr($name, -$len) === self::EXT;
	}

}
