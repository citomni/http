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
 * NonceException: Base exception for nonce ledger failures.
 *
 * Lets a caller handle every exception of the nonce ledger in one catch.
 *
 * Rules:
 * - Throw a subclass: Each kind of failure has its own.
 * - Never use it for the outcome of a nonce check: A replayed or malformed nonce and a failed
 *   write are a negative answer, not an exception.
 */
class NonceException extends \RuntimeException {
}
