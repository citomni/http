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

use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * Slugger: Service that makes, checks and deduplicates URL slugs.
 *
 * Gives every caller the same slug policy, chosen once from the service options and cfg. It
 * decides what a slug looks like; whether a slug is already taken belongs to the caller, which
 * answers through a callback.
 *
 * Rules:
 * - Derive the policy only in init(); public methods never change it.
 * - Reach no storage: Ask the caller's callback whenever a fact about stored slugs is needed.
 * - Measure slug lengths in bytes.
 *
 * Typical usage:
 *   $base = $this->app->slugger->slugify($title);
 *   $slug = $this->app->slugger->ensureUnique(fn(string $s): bool => $repo->slugExists($s), $base);
 */
class Slugger extends BaseService {
	
	/** Text that replaces disallowed characters and joins a numeric suffix; never empty. */
	private string $separator = '-';
	
	/** Whether slugs must be lowercase. */
	private bool $lowercase = true;
	
	/** Maximum slug length in bytes, at least 1. */
	private int $maxLength = 140;
	
	/** Whether text is transliterated to ASCII before disallowed characters are replaced. */
	private bool $transliterate = true;
	
	/** @var list<int|string> Reserved slugs, lowercased and unique. */
	private array $reserved = [];
	
	/** PCRE that matches the characters a slug must not contain. */
	private string $notAllowedPattern = '/[^a-z0-9\-]+/i';


	/**
	 * Read and check the slug policy once.
	 *
	 * Behavior:
	 * - Each service option wins over the key of the same name in cfg http.slug.
	 * - Lowercases the reserved slugs and drops entries that are not non-empty strings.
	 *
	 * @return void
	 * @throws \InvalidArgumentException  When separator is not a non-empty string, maxLength is not an integer >= 1, reserved is not an array, or allowedPattern is not a non-empty string.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->http->slug;

		$opt = $this->options;
		$this->options = [];

		$this->separator     = self::pickString($opt['separator'] ?? $cfg->separator, 'separator');
		$this->lowercase     = (bool)($opt['lowercase'] ?? $cfg->lowercase);
		$this->maxLength     = self::pickPositiveInt($opt['maxLength'] ?? $cfg->maxLength, 'maxLength');
		$this->transliterate = (bool)($opt['transliterate'] ?? $cfg->transliterate);

		// An empty list is a Cfg node, not an array.
		$reserved = $opt['reserved'] ?? $cfg->reserved;
		if ($reserved instanceof Cfg) {
			$reserved = $reserved->toArray();
		}
		if (!\is_array($reserved)) {
			throw new \InvalidArgumentException('slug.reserved must be an array of strings.');
		}
		$norm = [];
		foreach ($reserved as $w) {
			if (!\is_string($w) || $w === '') {
				continue;
			}
			$norm[\strtolower($w)] = true;
		}
		$this->reserved = \array_keys($norm);

		$allowedPattern = $opt['allowedPattern'] ?? $cfg->allowedPattern;
		if (!\is_string($allowedPattern) || $allowedPattern === '') {
			throw new \InvalidArgumentException('slug.allowedPattern must be a non-empty PCRE string.');
		}
		// Despite its name, allowedPattern matches the disallowed characters, so it is stored as is.
		$this->notAllowedPattern = $allowedPattern;
	}


	/**
	 * Make a slug from any text.
	 *
	 * Behavior:
	 * - Transliterates the trimmed text to ASCII first when transliterate is on.
	 * - Replaces disallowed characters with the separator, collapses repeated separators and
	 *   trims them from both ends.
	 * - Lowercases when lowercase is on.
	 * - Shortens a result longer than maxLength bytes with enforceLength().
	 * - Returns '' when no allowed character is left.
	 * - Does not check the reserved slugs; validate() does.
	 *
	 * Notes:
	 * - Collapsing and trimming repeat only the separator's last character, so they are exact
	 *   only for a one-character separator.
	 *
	 * @param  string  $source  UTF-8 text, such as a title.
	 * @return string
	 */
	public function slugify(string $source): string {
		$s = \trim($source);

		if ($s !== '' && $this->transliterate) {
			$s = $this->toAscii($s);
		}

		if ($s === '') {
			return '';
		}

		$s = (string)\preg_replace($this->notAllowedPattern, $this->separator, $s);

		if ($this->separator !== '') {
			$quotedSep = \preg_quote($this->separator, '/');
			$s = (string)\preg_replace('/' . $quotedSep . '+/u', $this->separator, $s);
		}

		$s = $this->trimSeparators($s);

		if ($this->lowercase) {
			$s = \strtolower($s);
		}

		if ($this->maxLength > 0 && \strlen($s) > $this->maxLength) {
			$s = $this->enforceLength($s, $this->maxLength);
		}

		return $s;
	}


	/**
	 * Check a slug against the policy.
	 *
	 * Behavior:
	 * - Checks the trimmed slug, so surrounding whitespace is accepted.
	 *
	 * Notes:
	 * - The repeated-separator check repeats only the separator's last character, so it is
	 *   exact only for a one-character separator.
	 *
	 * @param  string  $slug
	 * @return void
	 * @throws \InvalidArgumentException  When the slug is empty, has a disallowed character, has ASCII uppercase while lowercase is on, is longer than maxLength bytes, is reserved, starts or ends with the separator, or repeats it.
	 */
	public function validate(string $slug): void {
		$s = \trim($slug);

		if ($s === '') {
			throw new \InvalidArgumentException('Slug cannot be empty.');
		}

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
	 * Shorten a slug to at most $max bytes.
	 *
	 * Behavior:
	 * - Returns '' when $max is below 1, and the slug unchanged when it fits.
	 * - Otherwise cuts at the last separator within the first $max bytes when its 0-based offset
	 *   is at least floor(0.6 * $max), else at $max bytes, and trims separators from both ends
	 *   of the cut.
	 * - Falls back to the first $max bytes, untrimmed, when trimming leaves nothing.
	 *
	 * Notes:
	 * - Cuts bytes, so a slug with multibyte characters can be cut inside one.
	 *
	 * @param  string  $slug  Normalized slug.
	 * @param  int  $max  Maximum length in bytes.
	 * @return string  At most $max bytes.
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
	 * Check whether a slug is reserved, ignoring ASCII case.
	 *
	 * Notes:
	 * - A reserved slug that PHP turns into an int key, such as 404, never matches.
	 *
	 * @param  string  $slug
	 * @return bool
	 */
	public function isReserved(string $slug): bool {
		return \in_array(\strtolower($slug), $this->reserved, true);
	}


	/**
	 * Normalize a slug-like string for comparison, without transliterating or shortening it.
	 *
	 * Behavior:
	 * - Replaces disallowed characters in the trimmed string with the separator, collapses
	 *   repeated separators, trims them from both ends and lowercases when lowercase is on.
	 * - Returns '' when no allowed character is left.
	 *
	 * Notes:
	 * - Collapsing and trimming repeat only the separator's last character, so they are exact
	 *   only for a one-character separator.
	 *
	 * @param  string  $slug
	 * @return string
	 */
	public function normalize(string $slug): string {
		$s = \trim($slug);
		if ($s === '') {
			return '';
		}

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
	 * Return the first free slug among a base slug and its numbered variants.
	 *
	 * Asks the caller whether each candidate is taken, in order: The normalized base, then base,
	 * separator and 2, 3 and so on up to $maxAttempts + 1. A numbered candidate longer than
	 * maxLength bytes keeps its number and loses the end of the base.
	 *
	 * Behavior:
	 * - Calls $existsFn at most $maxAttempts + 1 times.
	 *
	 * Notes:
	 * - The check and the caller's later write are not atomic. Under concurrent writers, only a
	 *   unique constraint in storage prevents a duplicate.
	 *
	 * @param  callable(string):bool  $existsFn  Returns true when the candidate is already taken.
	 * @param  string  $baseSlug  Normalized with normalize() before use; make it with slugify() from raw text.
	 * @param  int  $maxAttempts  Number of numbered candidates to try, at least 1.
	 * @return string
	 * @throws \InvalidArgumentException  When $maxAttempts is below 1, the base is empty after normalizing, the base or a tried candidate fails validate(), or every candidate is taken.
	 * @see \CitOmni\Http\Service\Slugger::validate()  The checks every candidate must pass.
	 */
	public function ensureUnique(callable $existsFn, string $baseSlug, int $maxAttempts = 50): string {
		if ($maxAttempts < 1) {
			throw new \InvalidArgumentException('maxAttempts must be >= 1.');
		}

		$base = $this->normalize($baseSlug);
		if ($base === '') {
			throw new \InvalidArgumentException('Base slug cannot be empty after normalization.');
		}
		$this->validate($base);

		if (!$existsFn($base)) {
			return $base;
		}

		for ($i = 2; $i <= $maxAttempts + 1; $i++) {
			$candidate = $base . $this->separator . (string)$i;
			if ($this->maxLength > 0 && \strlen($candidate) > $this->maxLength) {
				$candidate = $this->enforceLengthWithSuffix($base, (string)$i);
			}
			$this->validate($candidate);

			if (!$existsFn($candidate)) {
				return $candidate;
			}
		}

		throw new \InvalidArgumentException('Could not find a unique slug within the attempt limit.');
	}


	/**
	 * Transliterate UTF-8 text to ASCII, best effort.
	 *
	 * Behavior:
	 * - Uses iconv when it is available and returns a non-empty result.
	 * - Otherwise replaces a fixed set of Danish, German and accented Latin letters; other
	 *   non-ASCII characters stay.
	 *
	 * Notes:
	 * - The iconv result depends on the platform's iconv implementation and the current locale.
	 *
	 * @param  string  $s  UTF-8 text.
	 * @return string
	 */
	private function toAscii(string $s): string {
		// iconv first: Fast and broad coverage.
		if (\function_exists('iconv')) {
			$out = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if (\is_string($out) && $out !== '') {
				return $out;
			}
		}

		// Fallback for the most common letters only, to keep it cheap.
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
	 * Trim every separator from both ends.
	 *
	 * @param  string  $s
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
	 * Join a base and a numbered suffix within maxLength bytes.
	 *
	 * Behavior:
	 * - Shortens the base with enforceLength() so that base, separator and suffix fit.
	 * - Returns the suffix alone when no part of the base fits.
	 *
	 * @param  string  $base  Slug without the suffix.
	 * @param  string  $suffix  Number, such as "2".
	 * @return string
	 */
	private function enforceLengthWithSuffix(string $base, string $suffix): string {
		$suffixWithSep = $this->separator . $suffix;
		$limit = $this->maxLength - \strlen($suffixWithSep);
		$short = $this->enforceLength($base, \max(0, $limit));
		$candidate = $short === '' ? $suffix : $short . $suffixWithSep;
		return $this->trimSeparators($candidate);
	}


	/**
	 * Return a policy value that must be an integer >= 1.
	 *
	 * @param  mixed  $v
	 * @param  string  $name  Policy key, used in the exception message.
	 * @return int
	 * @throws \InvalidArgumentException  When $v is not an integer >= 1.
	 */
	private static function pickPositiveInt(mixed $v, string $name): int {
		if (!\is_int($v) || $v < 1) {
			throw new \InvalidArgumentException($name . ' must be a positive integer.');
		}
		return $v;
	}


	/**
	 * Return a policy value that must be a non-empty string.
	 *
	 * @param  mixed  $v
	 * @param  string  $name  Policy key, used in the exception message.
	 * @return string
	 * @throws \InvalidArgumentException  When $v is not a non-empty string.
	 */
	private static function pickString(mixed $v, string $name): string {
		if (!\is_string($v) || $v === '') {
			throw new \InvalidArgumentException($name . ' must be a non-empty string.');
		}
		return $v;
	}
	
}
