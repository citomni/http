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
 * WebhooksAuthException: Base exception for the WebhooksAuth service.
 *
 * Catch this to handle all webhook-auth-related failures in a single branch.
 * Subtypes:
 * - WebhooksAuthConfigException - invalid configuration discovered at init time.
 * - WebhooksAuthVerificationException - request failed authentication checks.
 */
class WebhooksAuthException extends \RuntimeException {
}
