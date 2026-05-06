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
 * IconConfigException: Invalid icon file or icon payload.
 *
 * Thrown when an icon file exists, but its contents do not satisfy the
 * expected CitOmni icon contract.
 *
 * Behavior:
 * - Used for developer/configuration errors in trusted icon files.
 * - Indicates that the requested resource exists, but is malformed.
 *
 * Notes:
 * - Typical causes are files not returning arrays, non-string icon values,
 *   empty values, XML declarations, DOCTYPE payloads, or non-SVG markup.
 *
 * Typical usage:
 *   throw new IconConfigException('Icon file must return an array.');
 */
final class IconConfigException extends \RuntimeException {
}
