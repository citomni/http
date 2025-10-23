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
 * Slugger: Deterministic slug generation, normalization, validation, and uniqueness helpers.
 *
 * Intent:
 * - Provide a single, predictable pipeline for human-friendly, URL-safe slugs across apps.
 * - No I/O and no storage assumptions; uniqueness is delegated via callback.
 *
 * Behavior:
 * - Immutable config read at init(): options > cfg > defaults (fail fast on invalid).
 * - Transliteration uses iconv if available; includes a tiny ASCII fallback map for common cases (e.g., Danish).
 * - Final slugs respect allowedPattern, separator collapsing, casing policy, and max length.
 *
 * Notes:
 * - Keep allocations low; operate on strings in-place where possible.
 * - Deterministic truncation prefers cutting at separator boundaries.
 *
 * Typical usage:
 *   $base = $this->app->slugger->slugify($title);
 *   $this->app->slugger->validate($base);
 *   $slug = $this->app->slugger->ensureUnique(fn($s) => $repo->slugExists($s), $base);
 *
 * @throws \InvalidArgumentException On invalid inputs or config.
 */
final class Slugger extends BaseService {
	
	/** @var string */
	private string $separator = '-';
	
	/** @var bool */
	private bool $lowercase = true;
	
	/** @var int */
	private int $maxLength = 140;
	
	/** @var bool */
	private bool $transliterate = true;
	
	/** @var array<int, string> */
	private array $reserved = [];
	
	/** @var string PCRE pattern describing characters that are NOT allowed (used for replacement step) */
	private string $notAllowedPattern = '/[^a-z0-9\-]+/i';


	/**
	 * One-time initialization. Read cfg, merge options, pre-validate, and store as immutable scalars.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Read cfg subtree (tolerate absence)
		$raw = $this->app->cfg->http->slug ?? [];
		$cfg = \is_array($raw) ? $raw : (method_exists($raw, 'toArray') ? $raw->toArray() : []);

		// Options override cfg
		$opt = $this->options;
		$this->options = [];

		$this->separator     = self::pickString($opt['separator'] ?? $cfg['separator'] ?? '-', 'separator');
		$this->lowercase     = (bool)($opt['lowercase'] ?? $cfg['lowercase'] ?? true);
		$this->maxLength     = self::pickPositiveInt($opt['maxLength'] ?? $cfg['maxLength'] ?? 140, 'maxLength');
		$this->transliterate = (bool)($opt['transliterate'] ?? $cfg['transliterate'] ?? true);

		$reserved = $opt['reserved'] ?? $cfg['reserved'] ?? [];
		if (!\is_array($reserved)) {
			throw new \InvalidArgumentException('slug.reserved must be an array of strings.');
		}
		// Normalize reserved list (lowercase + unique)
		$norm = [];
		foreach ($reserved as $w) {
			if (!\is_string($w) || $w === '') {
				continue;
			}
			$norm[\strtolower($w)] = true;
		}
		$this->reserved = \array_keys($norm);

		$allowedPattern = $opt['allowedPattern'] ?? $cfg['allowedPattern'] ?? '/[^a-z0-9\-]+/i';
		if (!\is_string($allowedPattern) || $allowedPattern === '') {
			throw new \InvalidArgumentException('slug.allowedPattern must be a non-empty PCRE string.');
		}
		// We store NOT-allowed pattern to use replace() directly.
		$this->notAllowedPattern = $allowedPattern;
	}


	/**
	 * Create a slug from arbitrary source text.
	 *
	 * Pipeline:
	 *  1) Transliterate to ASCII if enabled (iconv if available; else minimal map fallback).
	 *  2) Replace NOT-allowed characters with the configured separator.
	 *  3) Collapse repeated separators and trim at both ends.
	 *  4) Lowercase if configured.
	 *  5) Enforce max length deterministically (prefer cut at separator).
	 *
	 * @param string $source Source text (can be any UTF-8 string).
	 * @return string Deterministic, URL-safe slug. May be empty if source cannot yield any allowed characters.
	 */
	public function slugify(string $source): string {
		$s = \trim($source);

		if ($s !== '' && $this->transliterate) {
			$s = $this->toAscii($s);
		}

		if ($s === '') {
			return '';
		}

		// Replace NOT-allowed chars with separator
		$s = (string)\preg_replace($this->notAllowedPattern, $this->separator, $s);

		// Collapse repeated separators
		if ($this->separator !== '') {
			$quotedSep = \preg_quote($this->separator, '/');
			$s = (string)\preg_replace('/' . $quotedSep . '+/u', $this->separator, $s);
		}

		// Trim separator at both ends
		$s = $this->trimSeparators($s);

		// Lowercase
		if ($this->lowercase) {
			$s = \strtolower($s);
		}

		// Enforce length
		if ($this->maxLength > 0 && \strlen($s) > $this->maxLength) {
			$s = $this->enforceLength($s, $this->maxLength);
		}

		return $s;
	}


	/**
	 * Validate a slug against current policy.
	 *
	 * @param string $slug Non-empty slug candidate.
	 * @return void
	 * @throws \InvalidArgumentException If invalid or reserved.
	 */
	public function validate(string $slug): void {
		$s = \trim($slug);

		if ($s === '') {
			throw new \InvalidArgumentException('Slug cannot be empty.');
		}

		// Allowed-pattern validation: if we remove NOT-allowed chars and change the string, it's invalid.
		if (\preg_match($this->notAllowedPattern, $s) === 1) {
			throw new \InvalidArgumentException('Slug contains invalid characters.');
		}

		if ($this->lowercase && $s !== \strtolower($s)) {
			throw new \InvalidArgumentException('Slug must be lowercase.');
		}

		if ($this->maxLength > 0 && \strlen($s) > $this->maxLength) {
			throw new \InvalidArgumentException('Slug exceeds maximum length of ' . $this->maxLength . ' bytes.');
		}

		if ($this->isReserved($s)) {
			throw new \InvalidArgumentException('Slug is reserved.');
		}

		// Separator edge cases (no leading/trailing, no doubles)
		if ($this->separator !== '') {
			$sep = \preg_quote($this->separator, '/');
			if (\preg_match('/(^' . $sep . ')|(' . $sep . '$)/', $s) === 1) {
				throw new \InvalidArgumentException('Slug cannot start or end with the separator.');
			}
			if (\preg_match('/' . $sep . '{2,}/', $s) === 1) {
				throw new \InvalidArgumentException('Slug cannot contain repeated separators.');
			}
		}
	}


	/**
	 * Deterministically enforce a maximum length; prefers cutting at the last separator before the limit.
	 *
	 * @param string $slug Input slug (already normalized).
	 * @param int $max Positive max length (bytes).
	 * @return string Shortened slug (never longer than $max).
	 */
	public function enforceLength(string $slug, int $max): string {
		if ($max <= 0) {
			return '';
		}
		if (\strlen($slug) <= $max) {
			return $slug;
		}

		$cut = \substr($slug, 0, $max);
		if ($this->separator !== '') {
			$pos = \strrpos($cut, $this->separator);
			if ($pos !== false && $pos >= (int)\floor($max * 0.6)) {
				$cut = \substr($cut, 0, $pos);
			}
		}

		$cut = $this->trimSeparators($cut);
		return $cut === '' ? \substr($slug, 0, $max) : $cut;
	}


	/**
	 * Check if the slug is on the reserved list (case-insensitive).
	 *
	 * @param string $slug Candidate slug.
	 * @return bool True if reserved.
	 */
	public function isReserved(string $slug): bool {
		return \in_array(\strtolower($slug), $this->reserved, true);
	}


	/**
	 * Normalize for canonical comparison (lowercase if configured, collapse/trim separators).
	 *
	 * @param string $slug Raw slug-ish string.
	 * @return string Canonicalized slug string.
	 */
	public function normalize(string $slug): string {
		$s = \trim($slug);
		if ($s === '') {
			return '';
		}

		// Replace NOT-allowed with separator for a fair normalization pass
		$s = (string)\preg_replace($this->notAllowedPattern, $this->separator, $s);

		if ($this->separator !== '') {
			$sep = \preg_quote($this->separator, '/');
			$s = (string)\preg_replace('/' . $sep . '+/u', $this->separator, $s);
		}

		$s = $this->trimSeparators($s);
		if ($this->lowercase) {
			$s = \strtolower($s);
		}
		return $s;
	}


	/**
	 * Ensure uniqueness using a user-supplied existence callback.
	 *
	 * Strategy:
	 *   base, base-2, base-3, ... base-N (N <= maxAttempts)
	 *
	 * @param callable $existsFn fn(string $candidate): bool  Returns true if slug already exists.
	 * @param string $baseSlug Base slug to test first (will be validated/normalized implicitly).
	 * @param int $maxAttempts Maximum number of numeric suffix attempts (>= 1).
	 * @return string First available candidate.
	 * @throws \InvalidArgumentException If inputs are invalid or no candidate found.
	 */
	public function ensureUnique(callable $existsFn, string $baseSlug, int $maxAttempts = 50): string {
		if ($maxAttempts < 1) {
			throw new \InvalidArgumentException('maxAttempts must be >= 1.');
		}

		$base = $this->normalize($baseSlug);
		if ($base === '') {
			throw new \InvalidArgumentException('Base slug cannot be empty after normalization.');
		}
		// Enforce final policies on the base
		$this->validate($base);

		if (!$existsFn($base)) {
			return $base;
		}

		for ($i = 2; $i <= $maxAttempts + 1; $i++) {
			$candidate = $base . $this->separator . (string)$i;
			// Enforce max length (keep numeric suffix)
			if ($this->maxLength > 0 && \strlen($candidate) > $this->maxLength) {
				$candidate = $this->enforceLengthWithSuffix($base, (string)$i);
			}
			// validate structure and policy (throws on bad cases)
			$this->validate($candidate);

			if (!$existsFn($candidate)) {
				return $candidate;
			}
		}

		throw new \InvalidArgumentException('Could not find a unique slug within the attempt limit.');
	}


	/**
	 * Transliterate Unicode to ASCII using iconv when available; falls back to a minimal map.
	 *
	 * @param string $s UTF-8 input.
	 * @return string ASCII-ish output (best effort).
	 */
	private function toAscii(string $s): string {
		// iconv path (fast + broad coverage)
		if (\function_exists('iconv')) {
			$out = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if (\is_string($out) && $out !== '') {
				return $out;
			}
		}

		// Tiny fallback map for common letters (keep cheap)
		$map = [
			'Æ' => 'AE', 'Ø' => 'OE', 'Å' => 'AA',
			'æ' => 'ae', 'ø' => 'oe', 'å' => 'aa',
			'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
			'ß' => 'ss',
			'é' => 'e',  'è' => 'e',  'ê' => 'e',  'ë' => 'e',
			'á' => 'a',  'à' => 'a',  'â' => 'a',  'ã' => 'a',
			'ó' => 'o',  'ò' => 'o',  'ô' => 'o',  'õ' => 'o',
			'í' => 'i',  'ì' => 'i',  'î' => 'i',  'ï' => 'i',
			'ú' => 'u',  'ù' => 'u',  'û' => 'u',  'ñ' => 'n',
		];
		return \strtr($s, $map);
	}


	/**
	 * Trim separator from both ends.
	 *
	 * @param string $s
	 * @return string
	 */
	private function trimSeparators(string $s): string {
		if ($this->separator === '') {
			return $s;
		}
		$sep = \preg_quote($this->separator, '/');
		return (string)\preg_replace('/^' . $sep . '+|' . $sep . '+$/u', '', $s);
	}


	/**
	 * Enforce length while preserving a numeric suffix.
	 *
	 * @param string $base Base part without suffix.
	 * @param string $suffix Numeric suffix (e.g., "2").
	 * @return string Shortened candidate including suffix.
	 */
	private function enforceLengthWithSuffix(string $base, string $suffix): string {
		$suffixWithSep = $this->separator . $suffix;
		$limit = $this->maxLength - \strlen($suffixWithSep);
		$short = $this->enforceLength($base, \max(0, $limit));
		$candidate = $short === '' ? $suffix : $short . $suffixWithSep;
		return $this->trimSeparators($candidate);
	}


	/**
	 * Validate a positive integer (bytes) from mixed input.
	 *
	 * @param mixed $v
	 * @param string $name
	 * @return int
	 * @throws \InvalidArgumentException
	 */
	private static function pickPositiveInt(mixed $v, string $name): int {
		if (!\is_int($v) || $v < 1) {
			throw new \InvalidArgumentException($name . ' must be a positive integer.');
		}
		return $v;
	}


	/**
	 * Validate a non-empty string from mixed input.
	 *
	 * @param mixed $v
	 * @param string $name
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	private static function pickString(mixed $v, string $name): string {
		if (!\is_string($v) || $v === '') {
			throw new \InvalidArgumentException($name . ' must be a non-empty string.');
		}
		return $v;
	}
	
}
