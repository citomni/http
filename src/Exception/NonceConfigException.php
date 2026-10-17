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
 * NonceConfigException: Exception for nonce ledger settings that cannot work.
 *
 * Marks an error in the host app's configuration or PHP environment, such as an invalid nonce
 * cfg value, not a rejected nonce: Let it reach the global error handler. Fixing it belongs to
 * the host app.
 *
 * Rules:
 * - Name the cfg key or the missing PHP capability to fix in the message.
 */
class NonceConfigException extends NonceException {
}
