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
 * NonceConfigException: Invalid configuration for the Nonce service.
 *
 * Thrown during init() when:
 * - cfg.nonce.dir is empty or contains a null byte.
 * - The required internal hash algorithm is not supported by ext-hash.
 * - max_len, purge_probability, or purge_limit are out of valid range.
 *
 * Notes:
 * - Directory creation and writability are checked lazily at runtime.
 * - Runtime storage failures are signalled via false from Nonce::checkAndStore().
 */
class NonceConfigException extends NonceException {
}
