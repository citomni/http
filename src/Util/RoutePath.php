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
 * Route path resolution shared by Router and pre-dispatch path rules.
 *
 * Behavior:
 * - Drops the query string, then the mount path: the path of the public root URL or,
 *   when that is empty, the front controller's directory without a trailing "/public".
 *   With $caseInsensitive the mount path also matches in another letter case.
 * - Percent-decodes once, after the mount path is removed.
 * - Removes trailing slashes ("/" stays "/") and lowercases with $caseInsensitive.
 *
 * Notes:
 * - Pure: no globals, configuration or IO. Callers pass the request values.
 * - Dot segments and repeated slashes are kept as sent; Router matches them literally.
 * - Router and Policy\FetchMetadataPolicy both use it, so an exemption and the route
 *   that serves a request always see the same path.
 *
 * Typical usage:
 *   $path = RoutePath::resolve($_SERVER['REQUEST_URI'] ?? '/', \CITOMNI_PUBLIC_ROOT_URL, $_SERVER['SCRIPT_NAME'] ?? '/index.php', false);
 */
final class RoutePath {

	/**
	 * Resolve the app-relative route path of a request.
	 *
	 * @param  string  $requestUri       Raw request URI, query string included.
	 * @param  string  $publicRootUrl    Public root URL, or '' when undefined.
	 * @param  string  $scriptName       Front controller script name (mount path fallback).
	 * @param  bool    $caseInsensitive  http.router_case_insensitive.
	 * @return string  Decoded route path without trailing slashes, or '/'.
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

		if ($basePrefix !== '') {
			$length = \strlen($basePrefix);
			$matches = \strncmp($uri, $basePrefix, $length) === 0
				|| ($caseInsensitive && \strncasecmp($uri, $basePrefix, $length) === 0);
			if ($matches) {
				$uri = \substr($uri, $length);
			}
		}

		$uri = \rtrim(\rawurldecode($uri), '/') ?: '/';
		return $caseInsensitive ? \strtolower($uri) : $uri;
	}
}
