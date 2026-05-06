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
 * WebhooksAuthConfigException: Invalid configuration for the WebhooksAuth service.
 *
 * Thrown during init() when:
 * - The secret file is missing, unreadable, or returns invalid data.
 * - Required scalar values (ttl_seconds, algo, header names) are out of range.
 * - The HMAC algorithm is unsupported.
 *
 * This is a fail-fast condition: misconfiguration at boot must not silently
 * degrade to "auth always fails" - the application should refuse to start.
 */
class WebhooksAuthConfigException extends WebhooksAuthException {
}
