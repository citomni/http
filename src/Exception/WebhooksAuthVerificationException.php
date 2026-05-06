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

use CitOmni\Http\Enum\WebhooksAuthFailureReason;

/**
 * WebhooksAuthVerificationException: Thrown when a webhook request fails authentication.
 *
 * Carries a WebhooksAuthFailureReason enum identifying the exact verification
 * stage that failed. The reason is available for logging, problem-detail
 * responses, and structured error handling without parsing message strings.
 *
 * Typical usage:
 *   try {
 *       $body = $this->app->webhooksAuth->requireValid();
 *   } catch (WebhooksAuthVerificationException $e) {
 *       // $e->reason === WebhooksAuthFailureReason::NonceRejected, etc.
 *   }
 */
class WebhooksAuthVerificationException extends WebhooksAuthException {
	
	public function __construct(public readonly WebhooksAuthFailureReason $reason, string $message = '', int $code = 0, ?\Throwable $previous = null) {
		parent::__construct(
			$message !== '' ? $message : 'Webhook authentication failed: ' . $reason->value,
			$code,
			$previous
		);
	}
	
}
