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
 * Nonce: Filesystem-backed replay protection using single-use tokens.
 *
 * Provides defense-in-depth against replay attacks by tracking unique nonces
 * on the filesystem. Each nonce is persisted atomically as a small file in a
 * writable directory, with the filename derived from HASH_ALGO(nonce).
 *
 * Responsibilities:
 * - Persist and validate single-use nonces to prevent request replays within a bounded TTL window.
 *   1) Enforce strict input constraints (length, whitelist) before hashing
 *   2) Atomically create a file for unseen nonces (fopen with mode "x")
 *   3) Treat file mtime as canonical age; content is informational
 * - Keep hot paths lean and deterministic:
 *   1) No IO during setOptions(); IO occurs only when needed
 *   2) Opportunistic bounded purges to cap disk growth
 *   3) Return booleans on hot paths instead of throwing
 * - Provide basic maintenance utilities for expired-entry cleanup.
 *
 * Design goals:
 * - Minimal runtime overhead: fast atomic file creation via `fopen(..., 'x')`.
 * - Replay resistance: rejects reuse of any nonce still within its TTL window.
 * - Persistence: nonce files are durable until TTL expiration, with optional
 *   purge methods to bound disk growth.
 * - Defense-in-depth: enforces max length and strict character whitelist on
 *   raw nonces, preventing path traversal or resource exhaustion.
 * - Fail-fast: rejects missing/invalid `dir` configuration early.
 *
 * Collaborators:
 * - Consumed by higher-level guards such as WebhooksAuth (read/write).
 * - No direct external services required; operates on local filesystem.
 *
 * Configuration keys:
 * - dir (string, required) - Absolute writable directory for nonce files
 *   - Must resolve under CITOMNI_APP_PATH . "/var/" (enforced at runtime)
 *
 * Storage model:
 * - Filename: HASH_ALGO(nonce) + ".nonce"
 * - Content:  UNIX timestamp (int), with mtime also set to creation time.
 * - Directory: must reside under CITOMNI_APP_PATH/var/ and be writable.
 *
 * Error handling:
 * - setOptions(): fail fast via \RuntimeException on missing/invalid "dir".
 * - checkAndStore()/purgeSomeExpired()/purgeAllExpired(): return status booleans/ints; do not throw.
 * - Filesystem warnings are suppressed deliberately; callers act on return values.
 *
 * Behavior:
 * - Storage model:
 *   - Filename: HASH_ALGO(nonce) . ".nonce" (hex)
 *   - Content: UNIX timestamp string (mtime is the canonical age)
 *   - Directory residency constraint under /var/ to avoid traversal/symlink escape
 * - Determinism:
 *   - Given identical inputs and filesystem state, results are deterministic.
 *   - One-time per-process validation of HASH_ALGO availability.
 * - Side effects and outputs:
 *   - Creates, touches, chmods, and unlinks files in the configured directory.
 *   - May delete a bounded number of expired files during opportunistic purge.
 * - Precedence/ordering rules:
 *   - On collision with expired file: delete then retry once (write wins if retry succeeds).
 *   - On collision with fresh file: treat as replay and return false.
 * - Thread-safety / reentrancy:
 *   - Cross-process contention is guarded by fopen(..., "x") atomicity on POSIX-compliant filesystems.
 *   - Safe to call concurrently across FPM workers as long as they share the same real directory.
 *
 * Design for extension:
 * - Class is non-final by design; override HASH_ALGO in subclasses if required.
 * - Protected constant HASH_ALGO allows algorithm substitution (e.g., "sha512").
 * - Avoids magic; explicit configuration via setOptions().
 * - Rationale for not final: benign to extend for different hash or folder policy while preserving contract.
 *
 * Security boundaries:
 * - Enforces ASCII whitelist and maximum length before hashing.
 * - Requires directory to resolve inside CITOMNI_APP_PATH . "/var/".
 * - Uses hash-derived filenames; never uses raw nonce as a path segment.
 *
 * Performance notes:
 * - Zero IO during setOptions(); minimal syscalls on hot path.
 * - Opportunistic purge runs ~2% of calls and inspects at most 25 items.
 * - No directory scans on every write; only on purge attempts.
 *
 * Typical usage:
 *
 *   $ok = $this->app->nonce
 *       ->setOptions((object)['dir' => CITOMNI_APP_PATH . '/var/nonces'])
 *       ->checkAndStore($nonce, 300);
 *   if (!$ok) {
 *       // Deny request - invalid or replayed nonce, or storage failure
 *   }
 *
 * Examples:
 *
 *   // Bulk cleanup via admin job or cron (bounded)
 *   $removed = $this->app->nonce
 *       ->setOptions(['dir' => CITOMNI_APP_PATH . '/var/nonces'])
 *       ->purgeAllExpired(300, 1000);
 *   // $removed is the number of deleted files
 *
 * Failure:
 *
 *   // Missing required configuration throws immediately
 *   $this->app->nonce->setOptions([]); // throws \RuntimeException
 *
 * Standalone (only if necessary):
 *
 *   // Minimal isolated demo (not typical in app code)
 *   $nonceSvc = (new \CitOmni\Http\Service\Nonce())
 *       ->setOptions(['dir' => CITOMNI_APP_PATH . '/var/nonces']);
 *   if (!$nonceSvc->checkAndStore('abc123', 120)) {
 *       // Handle replay or validation failure
 *   }
 *
 * Notes:
 * - Documentation is ASCII-only; code examples are valid PHP and use // comments.
 * - IPv6 and alternative storage backends are intentionally out of scope for core.
 */
class Nonce extends BaseService { 

	/** @var string Absolute path to writable nonce directory. */
	private string $dir = '';

	/** @var int Maximum accepted raw nonce length (defense-in-depth). */
	private int $maxLen = 128;

	/** @var string Allowed raw nonce pattern (defense-in-depth). */
	private string $allowedPattern = '/^[A-Za-z0-9._:-]{1,128}$/';

	// File extension suffix used for all stored nonce files
	private const EXT = '.nonce';

	// Configurable hash algorithm for filename derivation (e.g., 'sha256', 'sha512')
	// Use subclassing to override:
	// class MyNonce extends Nonce { protected const HASH_ALGO = 'sha512'; }
	protected const HASH_ALGO = 'sha256';


	/**
	 * Service bootstrap hook.
	 *
	 * Behavior:
	 * - Keep initialization minimal for cold-start performance.
	 *
	 * Notes:
	 * - This service does not keep internal state; no warm-up required.
	 *
	 * Typical usage:
	 *   // Nothing to call explicitly; the app constructs the service as needed.
	 *
	 * @return void
	 */
	/* 
	protected function init(): void {
		// Intentionally empty (lean boot).
	}
	*/


	/**
	 * Apply configuration for the Nonce service.
	 *
	 * Behavior:
	 * - Accepts array or object with public property "dir".
	 * - Fails fast if "dir" is missing or empty.
	 * - Defers filesystem checks to ensureDir() on first use.
	 *
	 * Notes:
	 * - Keeps hot path free of unnecessary IO.
	 *
	 * Typical usage:
	 *   $this->app->nonce->setOptions(['dir' => CITOMNI_APP_PATH . '/var/nonces']);
	 *
	 * @param array|object $opts Associative array or stdClass with key "dir".
	 * @return self Fluent self for chaining.
	 * @throws \RuntimeException If "dir" is missing, empty, or contains null bytes.
	 */
	public function setOptions($opts): self {
		// Tiny helper to extract values from array|object without overhead.
		$get = static function ($src, string $key, $default = null) {
			if (is_array($src) && array_key_exists($key, $src)) return $src[$key];
			if (is_object($src) && isset($src->{$key})) return $src->{$key};
			return $default;
		};

		// Fetch and minimally sanitize directory value.
		$dir = $get($opts, 'dir', null);

		// Validate presence and type (lean: avoid I/O here).
		if (!is_string($dir) || $dir === '') {
			throw new \RuntimeException('Missing required option: dir (nonce directory).');
		}

		// Defense-in-depth: trim and strip any embedded null bytes.
		$dir = trim($dir);
		if ($dir === '' || strpos($dir, "\0") !== false) {
			throw new \RuntimeException('Invalid nonce directory path.');
		}

		// Set as-is; actual path checks are deferred to ensureDir().
		$this->dir = $dir;

		return $this;
	}

	/**
	 * Validates and atomically stores a nonce if it has not been seen before.
	 *
	 * Behavior:
	 *  1. Validates TTL and nonce format (length + regex whitelist).
	 *  2. Ensures the nonce directory exists and is writable.
	 *  3. Attempts to atomically create a file: HASH_ALGO(nonce) + self::EXT
	 *     - If file exists and is still fresh -> reject as replay.
	 *     - If file exists but expired -> evict and retry once.
	 *     - If filesystem error -> reject.
	 *  4. On success, writes current timestamp and sets mtime/permissions.
	 *  5. Opportunistically purges a few expired nonces to bound growth.
	 *
	 * Storage model:
	 * - Filename: HASH_ALGO(nonce) + self::EXT
	 * - Content:  UNIX timestamp (for reference; mtime is main source of truth).
	 *
	 * TTL bounds:
	 * - Lower bound:  > 0
	 * - Upper bound:  <= 86400 (24h)   // defensive cap to avoid near-permanent entries
	 *
	 * Typical usage:
	 *   if (!$this->app->nonce->checkAndStore($nonce, 300)) {
	 *       // Deny - replay or invalid input
	 *   }
	 *
	 * Notes:
	 * - Returns boolean instead of throwing to keep the hot path lean.
	 * - TTL defensive cap avoids near-permanent retention (1..86400 seconds).
	 *
	 * @param string $nonce      Raw nonce provided by the client.
	 * @param int    $ttlSeconds Time-to-live window in seconds (1..86400).
	 * @return bool              True if nonce was newly accepted, false on replay or failure.
	 */
	public function checkAndStore(string $nonce, int $ttlSeconds): bool {
		// TTL bounds guard (defense-in-depth; avoids pathological long-lived files)
		if ($ttlSeconds <= 0 || $ttlSeconds > 86400) {
			return false;
		}

		// Reject empty or overly long nonces
		if ($nonce === '' || strlen($nonce) > $this->maxLen) {
			return false;
		}

		// Defense-in-depth: enforce character whitelist via regex
		if (!preg_match($this->allowedPattern, $nonce)) {
			return false;
		}

		// Ensure the target directory exists and is writable
		if (!$this->ensureDir()) {
			return false;
		}

		$now  = time();
		$path = $this->buildPath($nonce);

		// Attempt atomic file creation ("x" fails if file already exists)
		$fh = @fopen($path, 'x');
		if ($fh === false) {
			// File already exists -> possible replay or stale nonce
			if (is_file($path)) {
				$age = $now - (int)@filemtime($path);

				// If expired, evict and retry once
				if ($age >= $ttlSeconds) {
					@unlink($path);
					$fh = @fopen($path, 'x');
					if ($fh === false) {
						return false; // Race condition or FS error
					}
				} else {
					return false; // Replay within TTL window
				}
			} else {
				return false; // Filesystem error (permissions, path, etc.)
			}
		}

		// Write current timestamp into the file (optional, mtime is canonical)
		@fwrite($fh, (string)$now);
		@fclose($fh);

		// Ensure correct metadata for auditing and cleanup
		@touch($path, $now);
		@chmod($path, 0660);

		// Opportunistically purge a handful of expired entries (~2% chance)
		$this->purgeSomeExpired($ttlSeconds);

		return true;
	}

	/**
	 * Builds the absolute on-disk path for a given nonce.
	 *
	 * Nonce values are never used directly as filenames. Instead, a HASH_ALGO hash
	 * of the raw nonce is taken and suffixed with EXT (".nonce"). This ensures
	 * a fixed, safe filename length and prevents path traversal attacks or
	 * unexpected characters from influencing the filesystem.
	 *
	 * Example:
	 *   nonce = "abc123"
	 *   => hash = HASH_ALGO("abc123")  // e.g., sha256 hex string
	 *   => path = /var/.../nonces/<hex>.nonce
	 *
	 * Notes:
	 * - Verifies once per process that HASH_ALGO is available.
	 *
	 * @param string $nonce Raw nonce string.
	 * @return string       Absolute filesystem path where the nonce is stored.
	 * @throws \RuntimeException If the configured HASH_ALGO is not available.
	 */
	private function buildPath(string $nonce): string {
		// Verify the configured algorithm is available (very cheap, done on demand)
		static $algoChecked = false;
		if (!$algoChecked) {
			
			// Run this block only once per process (static guard)
			$algoChecked = true;
			
			// Verify that the configured HASH_ALGO is supported by the current PHP build
			if (!in_array(static::HASH_ALGO, hash_algos(), true)) {
				
				// Fail fast if the chosen algorithm is not available
				throw new \RuntimeException('Nonce: unsupported hash algorithm: ' . static::HASH_ALGO);
			}
		}

		// Derive a fixed-length hex filename from the nonce
		$hash = hash(static::HASH_ALGO, $nonce);

		// Append the configured directory and the static extension
		return $this->dir . DIRECTORY_SEPARATOR . $hash . self::EXT;
	}


	/**
	 * Ensures the nonce directory exists, resides under /var/, and is writable.
	 *
	 * Behavior:
	 * - Creates the directory with mode 0775 if it does not exist (recursive).
	 * - Resolves the canonical path via realpath() and rejects if outside
	 *   CITOMNI_APP_PATH . '/var/' (defense-in-depth).
	 * - Returns a boolean instead of throwing to keep hot-path lean.
	 *
	 * Notes:
	 * - Suppresses warnings and leaves the caller to act on false.
	 *
	 * @return bool True if directory is valid and writable; false otherwise.
	 */
	private function ensureDir(): bool {
		// Create directory lazily; suppress warnings (caller handles false return).
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0775, true);
			if (!is_dir($this->dir)) return false;
		}

		// Resolve canonical path; fail if it cannot be resolved.
		$real = @realpath($this->dir);
		if ($real === false) return false;

		// Must be inside CITOMNI_APP_PATH . /var/ (prevents path traversal / symlink escape).
		if (strpos($real, CITOMNI_APP_PATH . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR) !== 0) return false;

		// Finally, require that the resolved directory is writable.
		return is_writable($real);
	}


	/**
	 * Opportunistically purges a bounded number of expired nonce files.
	 *
	 * Behavior:
	 * - ~2 percent random trigger to keep overhead negligible.
	 * - Inspects up to 25 relevant entries per sweep.
	 * - Deletes files whose age (now - mtime) >= $ttlSeconds.
	 *
	 * Design:
	 * - Probabilistic trigger (~2% per call) to keep hot-path overhead negligible.
	 * - Processes at most `$limit` relevant entries per sweep (files with .nonce).
	 * - A file is considered expired if (now - mtime) >= $ttlSeconds.
	 * - Suppresses FS warnings to remain lean (caller doesn't need reasons here).
	 *
	 * Notes:
	 * - Suppresses filesystem warnings; this is best-effort maintenance.
	 *
	 * @param int $ttlSeconds TTL threshold in seconds; non-positive values are ignored.
	 * @return void
	 */
	private function purgeSomeExpired(int $ttlSeconds): void {
		// Guard: ignore nonsensical TTL values (defense-in-depth).
		if ($ttlSeconds <= 0) {
			return;
		}

		// Random gate (~2%): adjust the denominator to tune frequency.
		if (mt_rand(1, 50) !== 1) {
			return;
		}

		// Try opening the directory; abort quietly if not available.
		$dir = @opendir($this->dir);
		if ($dir === false) {
			return;
		}

		$now = time();
		$limit = 25;     // Hard cap: max number of relevant entries to inspect this sweep.
		$processed = 0;  // Count only entries that pass extension + is_file checks.

		while (($entry = readdir($dir)) !== false) {
			// Skip special entries
			if ($entry === '.' || $entry === '..') continue;

			// Consider only entries with the expected nonce extension
			if (!$this->hasExt($entry)) continue;

			$path = $this->dir . DIRECTORY_SEPARATOR . $entry;

			// Ensure it's a regular file (ignore dirs/symlinks)
			if (!is_file($path)) continue;

			// If mtime retrieval fails, skip rather than treating as "very old"
			$mt = @filemtime($path);
			if ($mt !== false) {
				$age = $now - (int)$mt;
				if ($age >= $ttlSeconds) {
					@unlink($path);
				}
			}

			// Decrement cap only for relevant entries we actually processed
			$processed++;
			if ($processed >= $limit) {
				break;
			}
		}

		@closedir($dir);
	}


	/**
	 * Purges up to $max expired nonce files (bulk cleanup).
	 *
	 * Behavior:
	 * - Iterates relevant entries and deletes those with age >= $ttlSeconds.
	 * - Bounded by $max to avoid long-running sweeps.
	 *
	 * Design:
	 * - Iterates directory entries and deletes files with the expected extension
	 *   whose mtime indicates age >= $ttlSeconds.
	 * - Suppresses FS warnings to keep the call lean; returns number of deletions.
	 * - Bounded by $max to avoid long-running sweeps.
	 *
	 * Typical usage:
	 *   $removed = $this->app->nonce->purgeAllExpired(300, 1000);
	 *
	 * Notes:
	 * - Returns number of files removed; suppresses filesystem warnings.
	 *
	 * @param int $ttlSeconds TTL threshold in seconds; non-positive disables purge.
	 * @param int $max        Maximum number of relevant entries to inspect (>=1).
	 * @return int            Number of files successfully removed.
	 */
	public function purgeAllExpired(int $ttlSeconds, int $max = 500): int {
		// Guard: ignore nonsensical inputs
		if ($ttlSeconds <= 0 || $max <= 0) {
			return 0;
		}

		// Quick existence check (no creation here)
		if (!is_dir($this->dir)) {
			return 0;
		}

		$now = time();
		$removed = 0;

		// Open directory (suppress warnings and bail out cleanly on failure)
		$dh = @opendir($this->dir);
		if ($dh === false) {
			return 0;
		}

		// Only count "relevant" entries against $max (files with correct extension)
		$processed = 0;

		while ($processed < $max && ($e = readdir($dh)) !== false) {
			// Skip special entries
			if ($e === '.' || $e === '..') {
				continue;
			}

			// Consider only files with the expected nonce extension
			if (!$this->hasExt($e)) {
				continue;
			}

			$path = $this->dir . DIRECTORY_SEPARATOR . $e;

			// Ensure it's a regular file (skip dirs/symlinks)
			if (!is_file($path)) {
				continue;
			}

			// Retrieve mtime; if it fails, skip rather than assuming "very old"
			$mt = @filemtime($path);
			if ($mt !== false) {
				$age = $now - (int)$mt;
				if ($age >= $ttlSeconds) {
					if (@unlink($path)) {
						$removed++;
					}
				}
			}

			// Count only relevant entries that we inspected
			$processed++;
		}

		@closedir($dh);
		return $removed;
	}


	/**
	 * Checks if a filename ends with the expected nonce file extension.
	 *
	 * Behavior & design:
	 * - Uses strlen + substr for speed and clarity.
	 * - Ensures name length is >= extension length before checking.
	 * - Prevents accidental matches on short or unrelated names.
	 *
	 * @param string $name Filename to check (basename only, not full path).
	 * @return bool        True if filename ends with self::EXT; false otherwise.
	 */
	private function hasExt(string $name): bool {
		// Compute extension length once
		$len = strlen(self::EXT);

		// Guard: skip names shorter than extension
		if (strlen($name) < $len) {
			return false;
		}

		// Compare trailing substring against extension (strict match)
		return substr($name, -$len) === self::EXT;
	}

}
