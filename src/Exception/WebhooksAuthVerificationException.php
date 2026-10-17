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
 * WebhooksAuthVerificationException: Exception for a webhook request that failed authentication.
 *
 * Tells the caller which check rejected the request as a WebhooksAuthFailureReason, so an
 * adapter can log it or map it to a response without parsing the message.
 */
class WebhooksAuthVerificationException extends WebhooksAuthException {

	/**
	 * Create the exception with the reason for the rejection.
	 *
	 * @param  WebhooksAuthFailureReason  $reason  Why the request failed authentication.
	 * @param  string  $message  Human-readable message; empty for "Webhook authentication failed: " and the reason's value.
	 * @param  int  $code  Exception code.
	 * @param  \Throwable|null  $previous  Lower-layer cause, when translated.
	 */
	public function __construct(public readonly WebhooksAuthFailureReason $reason, string $message = '', int $code = 0, ?\Throwable $previous = null) {
		parent::__construct(
			$message !== '' ? $message : 'Webhook authentication failed: ' . $reason->value,
			$code,
			$previous
		);
	}
	
}
