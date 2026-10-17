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
namespace CitOmni\Http\Util;

/**
 * Url: Util with pure functions over URLs and paths.
 *
 * Keeps URL rules in one place, so every caller applies them the same way. Building URLs from
 * cfg or the request belongs to the services that read them.
 *
 * Rules:
 * - Pure: Every result depends only on the arguments.
 * - Structural checks only: Never resolve, normalize or fetch a URL.
 */
class Url {


	/**
	 * Check whether a string is a path on this site, safe as a redirect target.
	 *
	 * Guards user-supplied redirect targets against open redirects. It checks the structure
	 * only; whether the path exists, or is well-formed beyond that, is not checked.
	 *
	 * Behavior:
	 * - True when the string starts with "/", its second character is neither "/" nor "\",
	 *   and it contains no control character (bytes 0x00 to 0x1F and 0x7F).
	 * - False for "", relative paths, absolute URLs and anything with leading whitespace.
	 * - False for "//host" and "/\host": Browsers read both as a URL on another host.
	 * - False for "/\t/host" and other control characters: Browsers drop tab and newline
	 *   before parsing, which would turn it into "//host".
	 * - Percent-encoded text is not decoded: "/%2F%2Fhost" is local.
	 *
	 * Typical usage:
	 *   $target = Url::isLocal($next) ? $next : '/';
	 *
	 * @param  string  $path  Candidate path, as received.
	 * @return bool
	 */
	public static function isLocal(string $path): bool {
		if ($path === '' || $path[0] !== '/') {
			return false;
		}

		// Reject protocol-relative URLs (//evil.com) and backslash
		// variants that some browsers interpret as slashes.
		if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) {
			return false;
		}

		// Reject control characters; browsers drop tab and newline before parsing.
		return \preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
	}

}
