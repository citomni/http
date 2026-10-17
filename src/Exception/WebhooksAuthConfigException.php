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
 * WebhooksAuthConfigException: Exception for webhook authentication settings that the host app set wrongly.
 *
 * Marks a configuration error in the host app, such as an invalid webhooks cfg value or an
 * unusable secret file, not a rejected request: Let it reach the global error handler, so a
 * broken setup fails loudly instead of passing for a webhook that rejects every request.
 * Fixing it belongs to the host app's cfg files or its secret file.
 *
 * Rules:
 * - Name the cfg key or the secret file to fix in the message.
 */
class WebhooksAuthConfigException extends WebhooksAuthException {
}
