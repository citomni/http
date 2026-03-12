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

use CitOmni\Http\Enum\CsrfFailureReason;


/**
 * CsrfVerificationException: Thrown when a CSRF verification check fails.
 *
 * Carries a CsrfFailureReason enum that identifies the exact defense layer
 * and failure mode. The reason is available for logging, debugging, and
 * structured error responses without parsing message strings.
 *
 * Typical usage:
 *   try {
 *       $this->app->csrf->requireValid();
 *   } catch (CsrfVerificationException $e) {
 *       $reason = $e->reason; // CsrfFailureReason::TokenMismatch etc.
 *   }
 */
final class CsrfVerificationException extends CsrfException {

	public function __construct(public readonly CsrfFailureReason $reason, string $message = '', int $code = 0,	?\Throwable $previous = null) {
		parent::__construct(
			$message !== '' ? $message : 'CSRF verification failed: ' . $reason->value,
			$code,
			$previous,
		);
	}
}
