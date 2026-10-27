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
 * RoutePath: Util with the pure function that turns a request URI into the app's route path.
 *
 * Keeps the meaning of a request path in one place, so routing and every pre-dispatch path
 * rule see the same path. Reading the request values belongs to the caller.
 *
 * Rules:
 * - Pure: Every result depends only on the arguments.
 */
class RoutePath {

	/**
	 * Return the route path of a request: The path below the app's mount path, decoded.
	 *
	 * Behavior:
	 * - Drops the query string, then the mount path. The mount path is the path of
	 *   $publicRootUrl without trailing slashes or, when that is empty, the directory of
	 *   $scriptName with backslashes as slashes and without a trailing "/public".
	 * - Removes the mount path in any ASCII letter case, but only where the path ends or
	 *   continues with "/" after it: Mount path "/app" turns "/APP/x" into "/x". Any other
	 *   path, "/apple" included, is kept whole.
	 * - Percent-decodes once (rawurldecode(), so "+" stays "+"), after the mount path is
	 *   removed, so an encoded "%2F" becomes a slash of the route path.
	 * - Removes trailing slashes; an empty result becomes "/".
	 * - With $caseInsensitive, the result is lowercased.
	 * - Keeps dot segments, repeated slashes, control characters and non-ASCII bytes as sent.
	 *
	 * Typical usage:
	 *   $path = RoutePath::resolve('/app/users/42/?tab=1', 'https://example.com/app', '/index.php', false); // '/users/42'
	 *
	 * @param  string  $requestUri       Raw request URI, query string included.
	 * @param  string  $publicRootUrl    Public root URL, or '' to use $scriptName.
	 * @param  string  $scriptName       Script path of the front controller, such as "/app/public/index.php".
	 * @param  bool    $caseInsensitive  True for case-insensitive routing.
	 * @return string  Path with a leading slash when the remainder has one, and no trailing slash except "/".
	 */
	public static function resolve(string $requestUri, string $publicRootUrl, string $scriptName, bool $caseInsensitive): string {
		$query = \strpos($requestUri, '?');
		$uri = ($query === false ? $requestUri : \substr($requestUri, 0, $query)) ?: '/';
		$basePrefix = $publicRootUrl !== '' ? \rtrim((string)(\parse_url($publicRootUrl, \PHP_URL_PATH) ?? ''), '/') : '';

		if ($basePrefix === '') {
			$basePrefix = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');
			if ($basePrefix !== '' && \str_ends_with($basePrefix, '/public')) {
				$basePrefix = \rtrim(\substr($basePrefix, 0, -7), '/');
			}
		}

		// On a case-insensitive file system the server maps the mount path to this script in
		// any letter case, so the request may spell it differently than $publicRootUrl and
		// $scriptName do. Behind a proxy that strips the mount path, a route may start with
		// the same letters; the segment boundary keeps such a route whole.
		if ($basePrefix !== '') {
			$length = \strlen($basePrefix);
			if (\strncasecmp($uri, $basePrefix, $length) === 0 && (!isset($uri[$length]) || $uri[$length] === '/')) {
				$uri = \substr($uri, $length);
			}
		}

		$uri = \rtrim(\rawurldecode($uri), '/') ?: '/';
		return $caseInsensitive ? \strtolower($uri) : $uri;
	}
}
