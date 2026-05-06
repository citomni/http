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

namespace CitOmni\Http\Exception;

/**
 * IconNotFoundException: Requested icon file or icon id was not found.
 *
 * Thrown when the icon service cannot resolve a requested icon resource from
 * the given id, file, and layer.
 *
 * Behavior:
 * - Used when the icon file itself is missing.
 * - Used when the icon file exists, but the requested id is not defined.
 *
 * Notes:
 * - Missing icons are treated as developer errors and should fail fast.
 * - Optional icon checks should use Icon::has() before calling Icon::get().
 *
 * Typical usage:
 *   throw new IconNotFoundException('Icon "home" not found.');
 */
final class IconNotFoundException extends \RuntimeException {
}
