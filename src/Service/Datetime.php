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
 * Datetime: Service that formats dates and times as localized text with ICU patterns.
 *
 * Gives templates and controllers locale-aware date text, with a locale and a timezone per
 * call, so one page can show several locales. Choosing the pattern for a context belongs to
 * the caller.
 *
 * Rules:
 * - Never change process-wide state: No setlocale(), Locale::setDefault() or
 *   date_default_timezone_set(). Pass the locale and timezone to each formatter.
 * - Build formatters only in format(), so every formatter is cached by locale, timezone
 *   and pattern.
 * - Set the defaults only in init().
 * - Do no I/O of its own: No files, logs or other services.
 */
class Datetime extends BaseService {
	
	/** ICU locale, in underscore form, for calls that give none. */
	private string $defaultLocale = 'en_US';
	
	/** Timezone identifier for a moment given without a timezone. */
	private string $defaultTz = 'UTC';
	
	/** The timezone of $defaultTz. */
	private \DateTimeZone $defaultTzObj;
	
	/** @var array<string,\IntlDateFormatter> Formatters by "locale|timezone|pattern". */
	private array $cache = [];


	/**
	 * Read the default locale and timezone once.
	 *
	 * Behavior:
	 * - The icu_locale option wins over cfg locale.icu_locale, then en_US. Hyphens become
	 *   underscores.
	 * - The timezone option wins over cfg locale.timezone, then UTC.
	 *
	 * @return void
	 * @throws \RuntimeException  When ext-intl is not loaded.
	 * @throws \DateInvalidTimeZoneException  When the timezone option or cfg locale.timezone is not a valid timezone.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
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
		$this->defaultTzObj = new \DateTimeZone($this->defaultTz); // Also rejects an invalid identifier here, not at the first call.

	}


	/**
	 * Format a moment as localized text with an ICU pattern.
	 *
	 * Behavior:
	 * - Shows the moment in $tzName when given; otherwise in the timezone that $when carries.
	 *     - Null, an int and a string without a timezone carry the default timezone.
	 * - Uses $locale when given; otherwise the default locale.
	 *
	 * Notes:
	 * - A locale that ICU cannot load makes ext-intl throw instead of returning ''.
	 *
	 * Typical usage:
	 *   $label = $this->app->datetime->format(1766531400, 'd. MMMM yyyy HH:mm', 'Europe/Copenhagen', 'da_DK');
	 *
	 * @param  null|string|int|\DateTimeInterface  $when  Null or '' for now; a string any DateTimeImmutable accepts; an int as a Unix timestamp in seconds.
	 * @param  string  $pattern  ICU date pattern, such as 'EEEE d. MMMM yyyy'.
	 * @param  string|null  $tzName  Timezone identifier; null or '' for the timezone of $when.
	 * @param  string|null  $locale  ICU locale with hyphens or underscores; null for the default locale.
	 * @return string  The text; '' when ICU cannot format with the pattern.
	 * @throws \DateMalformedStringException  When $when is a string that cannot be parsed.
	 * @throws \DateInvalidTimeZoneException  When $tzName is not a valid timezone.
	 * @throws \IntlException  When $pattern is not valid UTF-8, or the timezone to format in is one that ICU does not know, such as an offset like +02:00.
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
	 * Format the current time as localized text with an ICU pattern.
	 *
	 * Typical usage:
	 *   $clock = $this->app->datetime->now('HH:mm');
	 *
	 * @param  string  $pattern  ICU date pattern.
	 * @param  string|null  $tzName  Timezone identifier; null or '' for the default timezone.
	 * @param  string|null  $locale  ICU locale with hyphens or underscores; null for the default locale.
	 * @return string  The text; '' when ICU cannot format with the pattern.
	 * @throws \DateInvalidTimeZoneException  When $tzName is not a valid timezone.
	 * @throws \IntlException  When $pattern is not valid UTF-8, or the timezone is one that ICU does not know, such as an offset like +02:00.
	 * @see \CitOmni\Http\Service\Datetime::format()  How the locale and the timezone are applied.
	 */
	public function now(string $pattern, ?string $tzName = null, ?string $locale = null): string {
		return $this->format(null, $pattern, $tzName, $locale);
	}


	/**
	 * Return the localized name of a month, in the form for a name that stands alone.
	 *
	 * Behavior:
	 * - Uses the ICU stand-alone month patterns: LLLL for 'full', LLL for 'short' and L for
	 *   'narrow'. ICU formats L as the month number, such as 12, not as a letter.
	 * - Any other $form gives the full name.
	 *
	 * Typical usage:
	 *   $name = $this->app->datetime->month(12, 'short', 'sv_SE');
	 *
	 * @param  int  $month  1 to 12.
	 * @param  string|null  $form  'full', 'short' or 'narrow'; null for 'full'.
	 * @param  string|null  $locale  ICU locale with hyphens or underscores; null for the default locale.
	 * @return string  The name; a short name ends with a dot in some locales. '' when ICU cannot format it.
	 * @throws \InvalidArgumentException  When $month is outside 1 to 12.
	 * @throws \IntlException  When the default timezone is one that ICU does not know, such as an offset like +02:00.
	 * @see \CitOmni\Http\Service\Datetime::format()  How the locale is applied.
	 */
	public function month(int $month, ?string $form = null, ?string $locale = null): string {
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Month must be 1..12.');
		}
		// Stand-alone forms: Some languages inflect a month name inside a date, and here it stands alone.
		$choice  = $form ?? 'full';
		$pattern = match ($choice) {
			'short'  => 'LLL',
			'narrow' => 'L',
			default  => 'LLLL',
		};

		$dt = (new \DateTimeImmutable('2000-01-15 12:00:00', $this->defaultTzObj))->setDate(2000, $month, 15);

		$out = $this->format($dt, $pattern, null, $locale);

		return $out;
	}


	/**
	 * Return the localized name of a weekday.
	 *
	 * Behavior:
	 * - Uses the ICU format patterns EEEE for 'full', EEE for 'short' and E for 'narrow'.
	 *   ICU formats E like EEE, as the abbreviated name, not as one letter.
	 * - Any other $form gives the full name.
	 *
	 * Typical usage:
	 *   $name = $this->app->datetime->weekday(3, 'short', 'en_US');
	 *
	 * @param  int  $isoWeekday  ISO-8601 weekday: 1 for Monday to 7 for Sunday.
	 * @param  string|null  $form  'full', 'short' or 'narrow'; null for 'full'.
	 * @param  string|null  $locale  ICU locale with hyphens or underscores; null for the default locale.
	 * @return string  The name; a short name ends with a dot in some locales. '' when ICU cannot format it.
	 * @throws \InvalidArgumentException  When $isoWeekday is outside 1 to 7.
	 * @throws \IntlException  When the default timezone is one that ICU does not know, such as an offset like +02:00.
	 * @see \CitOmni\Http\Service\Datetime::format()  How the locale is applied.
	 */
	public function weekday(int $isoWeekday, ?string $form = null, ?string $locale = null): string {
		if ($isoWeekday < 1 || $isoWeekday > 7) {
			throw new \InvalidArgumentException('ISO weekday must be 1..7.');
		}
		$choice  = $form ?? 'full';
		$pattern = match ($choice) {
			'short'  => 'EEE',
			'narrow' => 'E',
			default  => 'EEEE',
		};

		// 2000-01-03 was a Monday.
		$base = new \DateTimeImmutable('2000-01-03 12:00:00', $this->defaultTzObj);
		$dt   = $base->modify('+' . ($isoWeekday - 1) . ' days');

		$out = $this->format($dt, $pattern, null, $locale);

		return $out;
	}

	



	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------


	/**
	 * Convert a moment given in any accepted form to a DateTimeImmutable.
	 *
	 * Behavior:
	 * - Null or '': Now, in the default timezone.
	 * - Int: The Unix timestamp in seconds, in the default timezone.
	 * - String: Parsed by DateTimeImmutable, in the default timezone unless the string names
	 *   a timezone or an offset.
	 * - \DateTimeInterface: The same moment and timezone, as an immutable object.
	 *
	 * @param  null|string|int|\DateTimeInterface  $when
	 * @return \DateTimeImmutable
	 * @throws \DateMalformedStringException  When $when is a string that cannot be parsed.
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
	 * Choose the timezone to format in.
	 *
	 * @param  \DateTimeInterface  $dt
	 * @param  string|null  $tzName  Timezone identifier; null or '' for the timezone of $dt.
	 * @return \DateTimeZone  $tzName when given; otherwise the timezone of $dt, or the default timezone when $dt has none.
	 * @throws \DateInvalidTimeZoneException  When $tzName is not a valid timezone.
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
	 * Convert a locale to the underscore form, such as da-DK to da_DK.
	 *
	 * @param  string  $loc  Locale with hyphens or underscores.
	 * @return string  Trimmed, with hyphens replaced by underscores; en_US when empty.
	 */
	private function normalizeIcuLocale(string $loc): string {
		$loc = \str_replace('-', '_', \trim($loc));
		return $loc !== '' ? $loc : 'en_US';
	}
}
