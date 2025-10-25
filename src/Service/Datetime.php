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
 * Datetime: Localized date/time formatting with a tiny Intl cache.
 *
 * Provides a deterministic, low-overhead API for producing locale-aware date
 * and time strings without mutating global state. Accepts flexible inputs
 * (null|string|int|\DateTimeInterface) and formats via ICU patterns using
 * PHP's IntlDateFormatter. Designed for one instance per request/process.
 *
 * Behavior:
 * - Reads defaults from config and options
 *   1) locale.icu_locale (hyphen tolerated; normalized to underscore for ICU)
 *   2) locale.timezone   (IANA identifier; validated during init)
 *   3) options override config on a per-service basis
 * - Caches IntlDateFormatter by [locale|timezone|pattern]
 *   1) Cache lookups are O(1) by a compact key
 *   2) Reuses a prebuilt DateTimeZone object for the default TZ
 *   3) No cross-request shared state; cache lives for the process/request
 * - Does not modify process-global locale
 *   1) No calls to setlocale()/Locale::setDefault()
 *   2) Locale is always explicit per formatter instance
 *   3) Safe to format multiple locales on the same page
 *
 * Notes:
 * - Requires ext-intl; service fails fast on init if missing.
 * - Returns empty string on Intl formatting failure (pattern/locale issues).
 * - Deterministic: no I/O; minimal allocations; input validation via SPL.
 * - Month/weekday helpers use stand-alone forms (L/LLL/LLLL and E/EEE/EEEE).
 *
 * Typical usage:
 *   Use in controllers/views for UI strings: titles, labels, metadata,
 *   table cells, etc.—either via format(...) directly or the month/weekday
 *   helpers (with optional per-call locale/timezone overrides).
 *
 */
final class Datetime extends BaseService {
	
	/** @var string Default ICU locale, e.g. "da_DK" */
	private string $defaultLocale = 'en_US';
	
	/** @var string Default timezone, e.g. "Europe/Copenhagen" */
	private string $defaultTz = 'UTC';
	
	/** @var \DateTimeZone Pre-built timezone object for $defaultTz */
	private \DateTimeZone $defaultTzObj;
	
	/** @var array<string,\IntlDateFormatter> Cache of formatters keyed by [locale|tz|pattern] */
	private array $cache = [];


	/**
	 * Initialize service defaults and caches.
	 *
	 * Merges options with config to determine default ICU locale and timezone,
	 * normalizes the ICU locale (hyphen→underscore), and prebuilds a DateTimeZone
	 * instance for the default timezone (kept for reuse).
	 *
	 * @return void
	 * @throws \RuntimeException If the intl extension is missing.
	 * @throws \Exception If the configured/overridden timezone identifier is invalid.
	 */
	protected function init(): void {
		if (!\class_exists(\IntlDateFormatter::class)) {
			throw new \RuntimeException('PHP intl extension is required for Datetime service.');
		}

		$cfgLocale = '';
		$cfgTz = '';
		try {
			$cfg = $this->app->cfg->locale ?? (object)[];
			$cfgLocale = (string)($cfg->icu_locale ?? '');
			$cfgTz     = (string)($cfg->timezone   ?? '');
		} catch (\Throwable) {
			$cfgLocale = '';
			$cfgTz     = '';
		}

		$opt = $this->options;
		$this->options = [];

		$optLocale = (string)($opt['icu_locale'] ?? '');
		$optTz     = (string)($opt['timezone']   ?? '');

		$this->defaultLocale = $this->normalizeIcuLocale(
			$optLocale !== '' ? $optLocale : ($cfgLocale !== '' ? $cfgLocale : 'en_US')
		);
		
		$this->defaultTz = $optTz !== '' ? $optTz : ($cfgTz !== '' ? $cfgTz : 'UTC');
		$this->defaultTzObj = new \DateTimeZone($this->defaultTz); // validate + cache

	}


	/**
	 * format: Produce a localized date/time string from a flexible "when" value.
	 *
	 * Converts null|string|int|\DateTimeInterface into a DateTimeImmutable and
	 * formats it with an ICU pattern using IntlDateFormatter. Locale and timezone
	 * can be overridden per call; otherwise service defaults are used.
	 *
	 * Typical usage:
	 *   Use for one-off date/time strings in templates or controllers, when you
	 *   already know the desired ICU pattern.
	 *
	 * Examples:
	 *
	 *   // Simple date in Danish (service default locale)
	 *   $label = $this->app->datetime->format('2025-12-24', 'EEEE d. MMMM');
	 *
	 *   // Same instant rendered for different audiences (per-call overrides)
	 *   $da   = $this->app->datetime->format(1766531400, 'd. MMMM yyyy HH:mm', 'Europe/Copenhagen', 'da_DK');
	 *   $sv   = $this->app->datetime->format(1766531400, 'd MMMM yyyy HH.mm',  'Europe/Stockholm',  'sv_SE');
	 *   $enUS = $this->app->datetime->format(1766531400, 'MMMM d, yyyy h:mm a', 'America/New_York', 'en_US');
	 *
	 * Failure:
	 * - Returns '' if IntlDateFormatter fails to format (invalid pattern/locale).
	 * - Throws \Exception if the input string/timestamp cannot be parsed.
	 * - Throws \Exception if $tzName is provided but not a valid IANA TZ.
	 *
	 * @param null|string|int|\DateTimeInterface $when   Null => "now"; string (parsed in default TZ unless TZ in string);
	 *                                                   int => Unix timestamp (seconds); DateTimeInterface => used as-is.
	 * @param string                             $pattern ICU pattern (e.g., 'd. MMM', 'EEEE d. MMMM yyyy', 'HH:mm').
	 * @param string|null                        $tzName  Optional timezone override (IANA name), else effective TZ of $when or default.
	 * @param string|null                        $locale  Optional ICU locale (hyphen or underscore). Underscore is enforced internally.
	 * @return string                                     Localized text; empty string on Intl failure.
	 *
	 * @throws \Exception On unparseable $when or invalid $tzName.
	 */
	public function format(null|string|int|\DateTimeInterface $when, string $pattern, ?string $tzName = null, ?string $locale = null): string {
		$dt  = $this->normalizeWhen($when);
		$tz  = $this->resolveTz($dt, $tzName);
		$loc = $this->normalizeIcuLocale($locale ?? $this->defaultLocale);

		$key = $loc . '|' . $tz->getName() . '|' . $pattern;
		$fmt = $this->cache[$key] ??= new \IntlDateFormatter(
			$loc,
			\IntlDateFormatter::NONE,
			\IntlDateFormatter::NONE,
			$tz->getName(),
			\IntlDateFormatter::GREGORIAN,
			$pattern
		);

		$out = $fmt->format($dt);
		return \is_string($out) ? $out : '';
	}


	/**
	 * now: Convenience formatter for the current instant.
	 *
	 * Formats the current time ("now") using the service default timezone unless
	 * a per-call TZ override is provided. Locale can also be overridden per call.
	 *
	 * Typical usage:
	 *   Use when rendering "generated at", clock widgets, or quick timestamps
	 *   where "now" is the intended source time.
	 *
	 * Examples:
	 *
	 *   // Current time in default locale/TZ
	 *   $clock = $this->app->datetime->now('HH:mm');
	 *
	 *   // Current time in a different TZ/locale for a specific audience
	 *   $nyc   = $this->app->datetime->now('MMM d, HH:mm zzzz', 'America/New_York', 'en_US');
	 *
	 * Failure:
	 * - Returns '' if IntlDateFormatter fails to format (invalid pattern/locale).
	 * - Throws \Exception if an invalid $tzName is provided.
	 *
	 * @param string      $pattern ICU pattern.
	 * @param string|null $tzName  Optional IANA timezone override.
	 * @param string|null $locale  Optional ICU locale (hyphen or underscore).
	 * @return string              Localized text; empty string on Intl failure.
	 *
	 * @throws \Exception On invalid $tzName.
	 */
	public function now(string $pattern, ?string $tzName = null, ?string $locale = null): string {
		return $this->format(null, $pattern, $tzName, $locale);
	}


	/**
	 * month: Localized stand-alone month name (1..12).
	 *
	 * Uses stand-alone month patterns for better grammar when a month label
	 * appears by itself (LLLL for full; LLL for short; L for narrow).
	 *
	 * Typical usage:
	 *   Use in calendars, headings, filters, dropdowns, and charts—when the
	 *   month name is shown as a label on its own.
	 *
	 * Examples:
	 *
	 *   // Full (default): "december" in Danish
	 *   $full = $this->app->datetime->month(12);
	 *
	 *   // Short: "dec." in Swedish
	 *   $shortSv = $this->app->datetime->month(12, 'short', 'sv_SE');
	 *
	 *   // Narrow (single-letter, UI badges)
	 *   $narrow = $this->app->datetime->month(12, 'narrow', 'en_US'); // "D"
	 *
	 * Failure:
	 * - Throws \InvalidArgumentException if $month is outside 1..12.
	 * - Returns '' if IntlDateFormatter fails to format (invalid locale/pattern).
	 *
	 * @param int         $month  1..12.
	 * @param null|string $form   null|'full'|'short'|'narrow' (default: 'full').
	 * @param string|null $locale Optional ICU locale override.
	 * @return string             Localized month name (may include a trailing dot in some locales for short form).
	 *
	 * @throws \InvalidArgumentException On out-of-range $month.
	 */
	public function month(int $month, ?string $form = null, ?string $locale = null): string {
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Month must be 1..12.');
		}
		// Use stand-alone month forms: LLL/LLLL (better when shown alone)
		$choice  = $form ?? 'full';
		$pattern = match ($choice) {
			'short'  => 'LLL',
			'narrow' => 'L',
			default  => 'LLLL', // full (default)
		};

		$dt = (new \DateTimeImmutable('2000-01-15 12:00:00', $this->defaultTzObj))->setDate(2000, $month, 15);

		$out = $this->format($dt, $pattern, null, $locale);

		return $out;
	}


	/**
	 * weekday: Localized weekday name (ISO-8601: 1=Mon .. 7=Sun).
	 *
	 * Formats a weekday label using E/EEE/EEEE (format context). Suitable for
	 * table headers, date chips, and schedule UIs. Use 'short' for compact UI,
	 * 'narrow' for single-letter badges, or default 'full' for clarity.
	 *
	 * Typical usage:
	 *   Use wherever weekday labels are rendered independently of a full date.
	 *
	 * Examples:
	 *
	 *   // Full (default): "mandag"
	 *   $full = $this->app->datetime->weekday(1);
	 *
	 *   // Short: "Wed" in US English; "ons." in Danish
	 *   $short = $this->app->datetime->weekday(3, 'short', 'en_US');
	 *
	 *   // Narrow: "M/T/W…" single-letter UI
	 *   $narrow = $this->app->datetime->weekday(3, 'narrow', 'da_DK');
	 *
	 * Failure:
	 * - Throws \InvalidArgumentException if $isoWeekday is outside 1..7.
	 * - Returns '' if IntlDateFormatter fails to format (invalid locale/pattern).
	 *
	 * @param int         $isoWeekday ISO-8601 weekday: 1=Mon .. 7=Sun.
	 * @param null|string $form       null|'full'|'short'|'narrow' (default: 'full').
	 * @param string|null $locale     Optional ICU locale override.
	 * @return string                 Localized weekday name (short forms may include a trailing dot depending on locale).
	 *
	 * @throws \InvalidArgumentException On out-of-range $isoWeekday.
	 */
	public function weekday(int $isoWeekday, ?string $form = null, ?string $locale = null): string {
		if ($isoWeekday < 1 || $isoWeekday > 7) {
			throw new \InvalidArgumentException('ISO weekday must be 1..7.');
		}
		$choice  = $form ?? 'full';
		$pattern = match ($choice) {
			'short'  => 'EEE',
			'narrow' => 'E',
			default  => 'EEEE', // full (default)
		};

		// 2000-01-03 was a Monday; add (isoWeekday-1) days
		$base = new \DateTimeImmutable('2000-01-03 12:00:00', $this->defaultTzObj);
		$dt   = $base->modify('+' . ($isoWeekday - 1) . ' days');

		$out = $this->format($dt, $pattern, null, $locale);

		return $out;
	}

	



/*
 *---------------------------------------------------------------
 * INTERNAL HELPERS
 *---------------------------------------------------------------
 */


	/**
	 * Normalize a flexible "when" value into a DateTimeImmutable.
	 *
	 * Behavior:
	 * - null  => "now" in the service default timezone.
	 * - int   => Unix timestamp (seconds) converted to default timezone.
	 * - string=> Parsed by DateTimeImmutable using the default timezone (unless the
	 *            string includes an explicit timezone/offset).
	 * - DateTimeInterface => Converted to an immutable clone.
	 *
	 * @param null|string|int|\DateTimeInterface $when Flexible input.
	 * @return \DateTimeImmutable Normalized immutable datetime.
	 * @throws \Exception If the string/timestamp cannot be parsed into a date/time.
	 */
	private function normalizeWhen(null|string|int|\DateTimeInterface $when): \DateTimeImmutable {
		if ($when instanceof \DateTimeImmutable) {
			return $when;
		}
		if ($when instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($when);
		}
		if (\is_int($when)) {
			return (new \DateTimeImmutable('@' . $when))->setTimezone($this->defaultTzObj);
		}
		if (\is_string($when) && $when !== '') {
			$dt = new \DateTimeImmutable($when, $this->defaultTzObj);
			return $dt;
		}
		return new \DateTimeImmutable('now', $this->defaultTzObj);
	}


	/**
	 * Resolve the effective timezone for formatting.
	 *
	 * Prefers an explicit $tzName when provided; otherwise uses the timezone from
	 * the given DateTimeInterface, falling back to the service default timezone.
	 *
	 * @param \DateTimeInterface $dt     Source datetime (may carry its own TZ).
	 * @param string|null        $tzName Optional IANA timezone name override.
	 * @return \DateTimeZone Resolved timezone object.
	 * @throws \Exception If $tzName is provided but invalid.
	 */
	private function resolveTz(\DateTimeInterface $dt, ?string $tzName): \DateTimeZone {
		if ($tzName !== null && $tzName !== '') {
			return new \DateTimeZone($tzName);
		}
		$tz = $dt->getTimezone();
		if ($tz instanceof \DateTimeZone) {
			return $tz;
		}
		return new \DateTimeZone($this->defaultTz);
	}


	/**
	 * Normalize an ICU/Intl locale to underscore form.
	 *
	 * Accepts both hyphen and underscore variants (e.g., "da-DK" or "da_DK") and
	 * returns underscore form; falls back to "en_US" if the input is empty.
	 *
	 * @param string $loc ICU locale string in hyphen or underscore form.
	 * @return string Normalized ICU locale (underscore form).
	 */
	private function normalizeIcuLocale(string $loc): string {
		// Accept both hyphen and underscore; force underscore for ICU.
		$loc = \str_replace('-', '_', \trim($loc));
		return $loc !== '' ? $loc : 'en_US';
	}
}
