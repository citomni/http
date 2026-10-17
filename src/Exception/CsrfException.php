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
 * CsrfException: Base exception for CSRF failures.
 *
 * Lets callers catch every CSRF failure in one branch. Thrown as this class, it marks a
 * failure on the server's side, such as invalid cfg or a corrupted session token, not a
 * rejected request: Let it reach the global error handler.
 *
 * Rules:
 * - A rejected request gets a subclass, so callers can tell it from a server-side failure.
 */
class CsrfException extends \RuntimeException {
}
