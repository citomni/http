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
 * CaptchaConfigException: Exception for captcha settings that the host app set up wrongly.
 *
 * Marks a configuration error in the host app, such as an invalid captcha cfg value or a
 * missing captchaImage service, not a wrong answer: Let it reach the global error handler.
 * Fixing it belongs to the host app's cfg and provider files.
 *
 * Rules:
 * - Name the cfg key or the missing service to fix in the message.
 * - Never use it for a wrong, expired or missing answer: That is a failed verification, not
 *   an exception.
 */
class CaptchaConfigException extends \RuntimeException {
}
