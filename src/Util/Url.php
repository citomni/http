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
 * Pure URL and path helpers for HTTP context.
 *
 * Centralizes small, deterministic rules related to URL and path handling in
 * the HTTP layer.
 *
 * Behavior:
 * - Provides pure helper methods with no dependency on App or service state.
 * - Contains structural checks only; does not perform IO, routing, or path resolution.
 * - Intended for shared low-level rules such as local-path detection.
 *
 * Notes:
 * - This class is deliberately kept small.
 * - Methods in this class must remain pure and deterministic.
 * - Framework- or app-specific URL assembly belongs elsewhere unless it can be
 *   implemented without App, config, or mutable runtime state.
 */
final class Url {


	/**
	 * Check whether a string is a local path.
	 *
	 * A local path starts with exactly one forward slash. Strings starting
	 * with "//" or "/\" are rejected to prevent protocol-relative URLs
	 * and backslash-based open-redirect vectors.
	 *
	 * This is a structural check only - it does not validate path syntax,
	 * resolve the path, or confirm that the target exists.
	 *
	 * @param  string  $path  The path to check.
	 * @return bool  True if the path is local.
	 */
	public static function isLocal(string $path): bool {
		if ($path === '' || $path[0] !== '/') {
			return false;
		}
		if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) {
			return false;
		}
		return true;
	}

}
