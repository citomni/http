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

namespace CitOmni\Http\Enum;


/**
 * CsrfFailureReason: Identifies which CSRF defense layer rejected the request.
 *
 * Used by CsrfVerificationException to communicate the specific failure point
 * without requiring exception-per-layer. The string value is safe for logging
 * and structured output (no PII, no secrets).
 *
 * Layer mapping:
 * - Fetch Metadata:  FetchMetadataRejected
 * - Origin/Referer:  OriginMismatch, OriginMissing, RefererMismatch, RefererMissing
 * - Token:           TokenMissing, TokenInvalid, TokenMismatch
 */
enum CsrfFailureReason: string {
	case FetchMetadataRejected = 'fetch_metadata_rejected';
	case OriginMismatch        = 'origin_mismatch';
	case OriginMissing         = 'origin_missing';
	case RefererMismatch       = 'referer_mismatch';
	case RefererMissing        = 'referer_missing';
	case TokenMissing          = 'token_missing';
	case TokenInvalid          = 'token_invalid';
	case TokenMismatch         = 'token_mismatch';
}
