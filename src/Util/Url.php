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
	 * with "//" or "/\" are rejected because some clients interpret them
	 * as protocol-relative or slash-equivalent external targets.
	 *
	 * This method is intended for low-level locality checks such as
	 * guarding user-supplied redirect targets against open-redirect abuse.
	 *
	 * This is a structural check only. It does not validate path syntax,
	 * normalize the path, resolve it, or confirm that the target exists.
	 *
	 * @param string $path The path to check.
	 * @return bool True when the string is a local path.
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

		return true;
	}

}
