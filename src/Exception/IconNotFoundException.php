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
 * IconNotFoundException: Exception for an icon file or icon id that does not exist.
 *
 * Lets a caller tell a missing icon from a malformed one. It marks a reference to an icon
 * that its layer does not ship, a mistake in code, not in the request: Let it reach the global
 * error handler. Code for which an icon is optional checks with Icon::has() first.
 *
 * Rules:
 * - Never use it for an icon file or value that exists but is malformed: That is
 *   IconConfigException.
 * - Name the icon file and the layer in the message, so it points at the reference to fix.
 */
class IconNotFoundException extends \RuntimeException {
}
