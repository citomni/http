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
 * NonceException: Base exception for the Nonce service.
 *
 * Catch this to handle all nonce-related failures in a single branch.
 * Subtype:
 * - NonceConfigException - invalid configuration discovered at init time.
 *
 * Note:
 * - Runtime check failures (replay, storage write failure, malformed nonce
 *   string from caller) are signalled via boolean return from
 *   Nonce::checkAndStore(). This separation keeps hot-path semantics simple
 *   and lets exceptional config errors fail-fast at boot.
 */
class NonceException extends \RuntimeException {
}
