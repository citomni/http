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
 * WebhooksAuthException: Base exception for webhook authentication failures.
 *
 * Lets a caller handle every webhook authentication failure, a configuration error as well as
 * a rejected request, in one catch.
 *
 * Rules:
 * - Throw a subclass: Each kind of failure has its own.
 */
class WebhooksAuthException extends \RuntimeException {
}
