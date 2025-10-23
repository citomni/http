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
 * Tags: Deterministic parsing, normalization, validation, deduplication, diffing, and optional slugging for tags.
 *
 * Intent:
 * - Provide a small, app-agnostic API for common tag text operations without any I/O or DB assumptions.
 * - Keep allocations low and sequencing deterministic.
 *
 * Behavior:
 * - Immutable config loaded once at init(): options > cfg > defaults.
 * - Case policy: 'preserve' (keep user's casing), 'title' (ucfirst words), 'lower' (lowercase).
 * - Deduplication is performed on normalized labels (case-insensitive).
 *
 * Notes:
 * - Slugs are generated via the Slugger service; uniqueness is not handled here.
 * - For user input, map validation failures to 422 in controllers (do not let exceptions bubble to global handler).
 *
 * Typical usage:
 *   $labels  = $this->app->tags->parse($rawCsv);
 *   $labels  = $this->app->tags->deduplicate($labels);
 *   foreach ($labels as $label) { $this->app->tags->validateLabel($label); }
 *   $ids     = $this->app->tags->resolveTagIds($labels, fn($l,$s) => $repo->getOrCreateTagId($l,$s));
 *   $changes = $this->app->tags->planPivotChanges($currentIds, $ids);
 *
 * @throws \InvalidArgumentException On invalid config or label inputs.
 */
final class Tags extends BaseService {
	
	/** @var string[] */
	private array $delimiters = [',',';'];
	
	/** @var int */
	private int $maxLabelLength = 80;
	
	/** @var string 'preserve'|'title'|'lower' */
	private string $caseMode = 'preserve';
	
	/** @var bool */
	private bool $collapseWhitespace = true;
	
	/** @var string|null PCRE pattern to DISALLOW (if matches -> invalid) */
	private ?string $disallowedPattern = null;
	
	/** @var int|null */
	private ?int $maxTagsPerEntity = null;



	/**
	 * Read config, merge options, validate once, store as scalars.
	 *
	 * @return void
	 */
	protected function init(): void {
		$raw = $this->app->cfg->http->tags ?? [];
		$cfg = \is_array($raw) ? $raw : (method_exists($raw, 'toArray') ? $raw->toArray() : []);

		$opt = $this->options;
		$this->options = [];

		$delims = $opt['delimiters'] ?? $cfg['delimiters'] ?? [',',';'];
		if (!\is_array($delims) || $delims === []) {
			throw new \InvalidArgumentException('tags.delimiters must be a non-empty string array.');
		}
		foreach ($delims as $d) {
			if (!\is_string($d) || $d === '') {
				throw new \InvalidArgumentException('tags.delimiters entries must be non-empty strings.');
			}
		}
		$this->delimiters = \array_values(\array_unique($delims));

		$len = $opt['maxLabelLength'] ?? $cfg['maxLabelLength'] ?? 80;
		if (!\is_int($len) || $len < 1) {
			throw new \InvalidArgumentException('tags.maxLabelLength must be a positive integer.');
		}
		$this->maxLabelLength = $len;

		$mode = $opt['caseMode'] ?? $cfg['caseMode'] ?? 'preserve';
		if (!\in_array($mode, ['preserve','title','lower'], true)) {
			throw new \InvalidArgumentException('tags.caseMode must be one of: preserve|title|lower.');
		}
		$this->caseMode = $mode;

		$this->collapseWhitespace = (bool)($opt['collapseWhitespace'] ?? $cfg['collapseWhitespace'] ?? true);

		$pat = $opt['disallowedPattern'] ?? $cfg['disallowedPattern'] ?? null;
		if ($pat !== null && (!\is_string($pat) || $pat === '')) {
			throw new \InvalidArgumentException('tags.disallowedPattern must be a non-empty string or null.');
		}
		$this->disallowedPattern = $pat;

		$maxTags = $opt['maxTagsPerEntity'] ?? $cfg['maxTagsPerEntity'] ?? null;
		if ($maxTags !== null) {
			if (!\is_int($maxTags) || $maxTags < 1) {
				throw new \InvalidArgumentException('tags.maxTagsPerEntity must be null or an integer >= 1.');
			}
			$this->maxTagsPerEntity = $maxTags;
		}
	}


	/**
	 * Parse a raw delimited string into an array of display labels.
	 *
	 * @param string $raw User-provided input, e.g., "foo, bar;Baz".
	 * @return array<int, string> Ordered labels after trimming/case policy; empty entries removed.
	 */
	public function parse(string $raw): array {
		$s = \trim($raw);
		if ($s === '') {
			return [];
		}
		if (\count($this->delimiters) === 1) {
			$parts = \explode($this->delimiters[0], $s);
		} else {
			$pattern = '/[' . \preg_quote(\implode('', $this->delimiters), '/') . ']/u';
			$parts = (array)\preg_split($pattern, $s);
		}

		$out = [];
		foreach ($parts as $p) {
			$label = $this->normalizeLabel((string)$p);
			if ($label !== '') {
				$out[] = $label;
			}
		}
		if ($this->maxTagsPerEntity !== null && \count($out) > $this->maxTagsPerEntity) {
			$out = \array_slice($out, 0, $this->maxTagsPerEntity);
		}
		return $out;
	}


	/**
	 * Normalize a single label deterministically (trim, collapse spaces, apply caseMode).
	 *
	 * @param string $label Raw label.
	 * @return string Normalized label (may be empty).
	 */
	public function normalizeLabel(string $label): string {
		$s = \trim($label);
		if ($s === '') {
			return '';
		}
		if ($this->collapseWhitespace) {
			$s = (string)\preg_replace('/\s+/u', ' ', $s);
		}
		return $this->applyCaseMode($s);
	}


	/**
	 * Validate a label against current policy.
	 *
	 * @param string $label Candidate label (will be normalized before checks).
	 * @return void
	 * @throws \InvalidArgumentException On empty, too long, or disallowed content.
	 */
	public function validateLabel(string $label): void {
		$s = $this->normalizeLabel($label);
		if ($s === '') {
			throw new \InvalidArgumentException('Tag label cannot be empty.');
		}
		if (\mb_strlen($s, 'UTF-8') > $this->maxLabelLength) {
			throw new \InvalidArgumentException('Tag label exceeds maximum length of ' . $this->maxLabelLength . ' characters.');
		}
		if ($this->disallowedPattern !== null && \preg_match($this->disallowedPattern, $s) === 1) {
			throw new \InvalidArgumentException('Tag label contains disallowed characters.');
		}
	}


	/**
	 * Deduplicate labels after normalization; keep first occurrence for display order.
	 *
	 * @param array<int, string> $labels Raw or semi-normalized labels.
	 * @return array<int, string> De-duplicated, normalized labels in stable order.
	 */
	public function deduplicate(array $labels): array {
		$seen = [];
		$out = [];
		foreach ($labels as $label) {
			$norm = $this->normalizeLabel((string)$label);
			if ($norm === '') {
				continue;
			}
			$key = \mb_strtolower($norm, 'UTF-8');
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$out[] = $norm;
			}
		}
		return $out;
	}


	/**
	 * Diff two label lists as sets (normalized, case-insensitive).
	 *
	 * @param array<int, string> $current Current labels.
	 * @param array<int, string> $desired Desired labels.
	 * @return array{add: array<int, string>, remove: array<int, string>}
	 */
	public function diff(array $current, array $desired): array {
		$curr = $this->deduplicate($current);
		$dest = $this->deduplicate($desired);

		$currSet = [];
		foreach ($curr as $c) { $currSet[\mb_strtolower($c, 'UTF-8')] = $c; }
		$destSet = [];
		foreach ($dest as $d) { $destSet[\mb_strtolower($d, 'UTF-8')] = $d; }

		$add = [];
		foreach ($destSet as $k => $d) {
			if (!isset($currSet[$k])) {
				$add[] = $d;
			}
		}
		$remove = [];
		foreach ($currSet as $k => $c) {
			if (!isset($destSet[$k])) {
				$remove[] = $c;
			}
		}
		return ['add' => $add, 'remove' => $remove];
	}


	/**
	 * Create a slug for a label via Slugger (no uniqueness check).
	 *
	 * @param string $label Raw label.
	 * @return string Slug (may be empty if label normalizes to nothing).
	 */
	public function toSlug(string $label): string {
		$norm = $this->normalizeLabel($label);
		if ($norm === '') {
			return '';
		}
		/** @var \CitOmni\Http\Service\Slugger $slugger */
		$slugger = $this->app->slugger;
		return $slugger->slugify($norm);
	}


	/**
	 * Batch map labels to slugs.
	 *
	 * @param array<int, string> $labels
	 * @return array<string, string> label => slug (only non-empty labels included).
	 */
	public function mapLabelsToSlugs(array $labels): array {
		$map = [];
		foreach ($this->deduplicate($labels) as $label) {
			$slug = $this->toSlug($label);
			if ($slug !== '') {
				$map[$label] = $slug;
			}
		}
		return $map;
	}


	/**
	 * Resolve label list to tag IDs using a user callback that performs get-or-create.
	 *
	 * @param array<int, string> $labels Raw labels.
	 * @param callable $getOrCreate fn(string $label, string $slug): int  Returns tag id.
	 * @return array<int, int> Stable-ordered list of tag IDs (deduplicated).
	 */
	public function resolveTagIds(array $labels, callable $getOrCreate): array {
		$labels = $this->deduplicate($labels);
		$ids = [];
		foreach ($labels as $label) {
			$this->validateLabel($label);
			$slug = $this->toSlug($label);
			// Best effort validate slug shape (won't enforce uniqueness here)
			if ($slug === '') {
				throw new \InvalidArgumentException('Unable to create slug for tag label.');
			}
			$ids[] = (int)$getOrCreate($label, $slug);
		}
		return $ids;
	}


	/**
	 * Compute attach/detach sets for a many-to-many pivot.
	 *
	 * @param array<int, int> $currentTagIds Current IDs in pivot.
	 * @param array<int, int> $desiredTagIds Desired IDs to have in pivot.
	 * @return array{attach: array<int, int>, detach: array<int, int>}
	 */
	public function planPivotChanges(array $currentTagIds, array $desiredTagIds): array {
		$curr = \array_values(\array_unique(\array_map('intval', $currentTagIds)));
		$dest = \array_values(\array_unique(\array_map('intval', $desiredTagIds)));

		$currSet = [];
		foreach ($curr as $id) { $currSet[$id] = true; }
		$destSet = [];
		foreach ($dest as $id) { $destSet[$id] = true; }

		$attach = [];
		foreach ($dest as $id) {
			if (!isset($currSet[$id])) {
				$attach[] = $id;
			}
		}
		$detach = [];
		foreach ($curr as $id) {
			if (!isset($destSet[$id])) {
				$detach[] = $id;
			}
		}
		return ['attach' => $attach, 'detach' => $detach];
	}


	/**
	 * Apply the configured case policy.
	 *
	 * @param string $s
	 * @return string
	 */
	private function applyCaseMode(string $s): string {
		return match ($this->caseMode) {
			'lower'    => \mb_strtolower($s, 'UTF-8'),
			'title'    => $this->toTitleCase($s),
			default    => $s, // preserve
		};
	}


	/**
	 * Lightweight title case: ucfirst each whitespace-separated token.
	 *
	 * @param string $s
	 * @return string
	 */
	private function toTitleCase(string $s): string {
		$parts = \preg_split('/(\s+)/u', $s, -1, \PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			return $s;
		}
		foreach ($parts as $i => $p) {
			// Skip pure whitespace tokens
			if ($p !== '' && \preg_match('/\S/u', $p) === 1) {
				$first = \mb_substr($p, 0, 1, 'UTF-8');
				$rest  = \mb_substr($p, 1, null, 'UTF-8');
				$parts[$i] = \mb_strtoupper($first, 'UTF-8') . \mb_strtolower((string)$rest, 'UTF-8');
			}
		}
		return \implode('', $parts);
	}
	
	
}
