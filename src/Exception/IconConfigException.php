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
 * IconConfigException: Exception for an icon file or icon value that exists but is malformed.
 *
 * Lets a caller tell a malformed icon from a missing one. It marks a mistake in a trusted
 * icon file, not in the request: Let it reach the global error handler. Fixing it belongs to
 * the layer that ships the icon file.
 *
 * Rules:
 * - Never use it for a missing icon file or id: That is IconNotFoundException.
 * - Name the icon file and the layer in the message, so it points at the file to fix.
 */
class IconConfigException extends \RuntimeException {
}
