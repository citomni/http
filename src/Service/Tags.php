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
 * Tags: Service that parses, normalizes, checks and compares tag labels.
 *
 * Gives every caller the same label policy, chosen once from the service options and cfg, and
 * plans which tags an entity gains or loses. Slugs belong to the slugger service; storing tags
 * belongs to the caller, which answers through a callback.
 *
 * Rules:
 * - Derive the policy only in init(); public methods never change it.
 * - Reach no storage: Ask the caller's callback whenever a stored tag is needed.
 * - Compare labels after normalizeLabel(), ignoring case.
 *
 * Typical usage:
 *   $labels  = $this->app->tags->parse($raw);
 *   $ids     = $this->app->tags->resolveTagIds($labels, fn(string $label, string $slug): int => $repo->getOrCreateTagId($label, $slug));
 *   $changes = $this->app->tags->planPivotChanges($currentIds, $ids);
 */
class Tags extends BaseService {
	
	/** @var list<string> Label separators; non-empty and unique. */
	private array $delimiters = [',',';'];
	
	/** Maximum label length in characters, at least 1. */
	private int $maxLabelLength = 80;
	
	/** Label casing: preserve, title or lower. */
	private string $caseMode = 'preserve';
	
	/** Whether runs of whitespace in a label become one space. */
	private bool $collapseWhitespace = true;
	
	/** PCRE that a valid label must not match, or null for no such check. */
	private ?string $disallowedPattern = null;
	
	/** Maximum number of labels of one entity, or null for no limit. */
	private ?int $maxTagsPerEntity = null;



	/**
	 * Read and check the label policy once.
	 *
	 * Behavior:
	 * - Each service option wins over the key of the same name in cfg http.tags.
	 * - Drops duplicate delimiters.
	 *
	 * Notes:
	 * - An option set to null counts as unset, so only cfg can set disallowedPattern or
	 *   maxTagsPerEntity to null.
	 *
	 * @return void
	 * @throws \InvalidArgumentException  When delimiters is not a non-empty array of non-empty strings, maxLabelLength is not an integer >= 1, caseMode is not preserve, title or lower, disallowedPattern is neither null nor a non-empty string, or maxTagsPerEntity is neither null nor an integer >= 1.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->http->tags;

		$opt = $this->options;
		$this->options = [];

		// An empty list is a Cfg node, not an array, and is rejected below.
		$delims = $opt['delimiters'] ?? $cfg->delimiters;
		if ($delims instanceof Cfg) {
			$delims = $delims->toArray();
		}
		if (!\is_array($delims) || $delims === []) {
			throw new \InvalidArgumentException('tags.delimiters must be a non-empty string array.');
		}
		foreach ($delims as $d) {
			if (!\is_string($d) || $d === '') {
				throw new \InvalidArgumentException('tags.delimiters entries must be non-empty strings.');
			}
		}
		$this->delimiters = \array_values(\array_unique($delims));

		$len = $opt['maxLabelLength'] ?? $cfg->maxLabelLength;
		if (!\is_int($len) || $len < 1) {
			throw new \InvalidArgumentException('tags.maxLabelLength must be a positive integer.');
		}
		$this->maxLabelLength = $len;

		$mode = $opt['caseMode'] ?? $cfg->caseMode;
		if (!\in_array($mode, ['preserve','title','lower'], true)) {
			throw new \InvalidArgumentException('tags.caseMode must be one of: preserve|title|lower.');
		}
		$this->caseMode = $mode;

		$this->collapseWhitespace = (bool)($opt['collapseWhitespace'] ?? $cfg->collapseWhitespace);

		$pat = $opt['disallowedPattern'] ?? $cfg->disallowedPattern;
		if ($pat !== null && (!\is_string($pat) || $pat === '')) {
			throw new \InvalidArgumentException('tags.disallowedPattern must be a non-empty string or null.');
		}
		$this->disallowedPattern = $pat;

		$maxTags = $opt['maxTagsPerEntity'] ?? $cfg->maxTagsPerEntity;
		if ($maxTags !== null) {
			if (!\is_int($maxTags) || $maxTags < 1) {
				throw new \InvalidArgumentException('tags.maxTagsPerEntity must be null or an integer >= 1.');
			}
			$this->maxTagsPerEntity = $maxTags;
		}
	}


	/**
	 * Split typed text into normalized labels.
	 *
	 * Behavior:
	 * - Splits on the delimiter when there is one, and on each character of every delimiter
	 *   when there are several.
	 * - Normalizes each part with normalizeLabel() and drops empty ones, in input order.
	 * - Keeps duplicates, and they count toward maxTagsPerEntity: Returns at most that many
	 *   labels, the first ones.
	 * - Does not validate the labels; validateLabel() does.
	 *
	 * @param  string  $raw  Typed text, such as "foo, bar;Baz".
	 * @return list<string>
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
	 * Normalize one label.
	 *
	 * Behavior:
	 * - Trims the label, replaces each run of whitespace with one space when collapseWhitespace
	 *   is on, and applies caseMode.
	 * - Returns '' when nothing is left after trimming.
	 *
	 * Notes:
	 * - Returns '' for invalid UTF-8 when collapseWhitespace is on.
	 *
	 * @param  string  $label
	 * @return string
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
	 * Check a label against the policy.
	 *
	 * Notes:
	 * - The exception means invalid input, not a failure: Catch it where labels come from a user.
	 *
	 * @param  string  $label  Normalized with normalizeLabel() before the checks.
	 * @return void
	 * @throws \InvalidArgumentException  When the normalized label is empty, longer than maxLabelLength characters, or matches disallowedPattern.
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
	 * Normalize labels and drop empty ones and duplicates.
	 *
	 * Behavior:
	 * - Labels that differ only in case are duplicates; the first one is kept, in input order.
	 *
	 * @param  array<string>  $labels
	 * @return list<string>  Normalized labels.
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
	 * Compare two label lists as sets.
	 *
	 * Behavior:
	 * - Compares the lists after deduplicate(), so labels match when they differ only in case.
	 * - add holds the desired labels missing from current, remove the current labels missing
	 *   from desired, each normalized and in its list's order.
	 *
	 * @param  array<string>  $current
	 * @param  array<string>  $desired
	 * @return array{add:list<string>,remove:list<string>}
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
	 * Make the slug of a label after normalizeLabel().
	 *
	 * Behavior:
	 * - Returns '' when the label normalizes to '' or yields no slug.
	 * - Does not check whether the slug is taken.
	 *
	 * @param  string  $label
	 * @return string
	 * @see \CitOmni\Http\Service\Slugger::slugify()  How the slug is made.
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
	 * Map labels to their slugs.
	 *
	 * Behavior:
	 * - Deduplicates the labels first and leaves out a label without a slug.
	 *
	 * @param  array<string>  $labels
	 * @return array<array-key,string>  Normalized label => slug; PHP turns a numeric label into an int key.
	 * @see \CitOmni\Http\Service\Tags::toSlug()  How each slug is made.
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
	 * Return the tag ids of labels, through the caller's get-or-create callback.
	 *
	 * The callback finds or creates the stored tag; this method decides which labels reach it
	 * and with which slug.
	 *
	 * Behavior:
	 * - Calls $getOrCreate once per label left by deduplicate(), in that order, with the
	 *   normalized label and its slug from toSlug().
	 * - Returns the ids in the same order, cast to int. Two labels can share an id when the
	 *   callback returns the same one for both.
	 * - Checks each label just before its call, so the callback has already run for the labels
	 *   before one that fails.
	 *
	 * @param  array<string>  $labels
	 * @param  callable(string,string):int  $getOrCreate  Takes label and slug, returns the tag id.
	 * @return list<int>
	 * @throws \InvalidArgumentException  When a label fails validateLabel() or yields no slug.
	 */
	public function resolveTagIds(array $labels, callable $getOrCreate): array {
		$labels = $this->deduplicate($labels);
		$ids = [];
		foreach ($labels as $label) {
			$this->validateLabel($label);
			$slug = $this->toSlug($label);
			if ($slug === '') {
				throw new \InvalidArgumentException('Unable to create slug for tag label.');
			}
			$ids[] = (int)$getOrCreate($label, $slug);
		}
		return $ids;
	}


	/**
	 * Plan which tag ids to attach to and detach from an entity.
	 *
	 * Behavior:
	 * - Casts the ids to int and ignores duplicates.
	 * - attach holds the desired ids missing from current, in desired order; detach the current
	 *   ids missing from desired, in current order.
	 *
	 * @param  array<int|numeric-string>  $currentTagIds  Ids the entity has.
	 * @param  array<int|numeric-string>  $desiredTagIds  Ids the entity should have.
	 * @return array{attach:list<int>,detach:list<int>}
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
	 * Apply caseMode to a label.
	 *
	 * Behavior:
	 * - lower lowercases, title uses toTitleCase(), preserve returns the label unchanged.
	 *
	 * @param  string  $s
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
	 * Uppercase the first character of each word and lowercase the rest.
	 *
	 * Behavior:
	 * - Words are separated by whitespace, which is kept as it is.
	 * - Returns the text unchanged when it is not valid UTF-8.
	 *
	 * @param  string  $s
	 * @return string
	 */
	private function toTitleCase(string $s): string {
		$parts = \preg_split('/(\s+)/u', $s, -1, \PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			return $s;
		}
		foreach ($parts as $i => $p) {
			if ($p !== '' && \preg_match('/\S/u', $p) === 1) {
				$first = \mb_substr($p, 0, 1, 'UTF-8');
				$rest  = \mb_substr($p, 1, null, 'UTF-8');
				$parts[$i] = \mb_strtoupper($first, 'UTF-8') . \mb_strtolower((string)$rest, 'UTF-8');
			}
		}
		return \implode('', $parts);
	}
	
	
}
