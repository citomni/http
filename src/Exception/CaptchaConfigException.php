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
 * CaptchaConfigException: The captcha service is misconfigured.
 *
 * Thrown when:
 * - A security.captcha_protection or security.captcha.* value is invalid
 *   (at construction).
 * - A challenge is issued or verified while the "captchaImage" service from
 *   citomni/image is not registered.
 *
 * Notes:
 * - Wrong, expired or missing answers are not exceptions; verify() returns
 *   false for them.
 */
final class CaptchaConfigException extends \RuntimeException {
}
