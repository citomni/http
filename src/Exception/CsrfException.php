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
 * CsrfException: Base exception for the CSRF service.
 *
 * Catch this to handle all CSRF-related failures in a single branch.
 * CsrfVerificationException extends this for verification-specific failures.
 */
class CsrfException extends \RuntimeException {
}
