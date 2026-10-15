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
 * CsrfFailureReason: Enum of the CSRF defense checks that can reject a request.
 *
 * Names the failure point within the three defense layers (Fetch Metadata, Origin with its
 * Referer fallback, and the token), so one exception type can report every layer. The values
 * are safe for logs and structured output: They carry no personal data and no secrets.
 *
 * Rules:
 * - A value is a contract once released. Never rename, reuse or reinterpret one.
 */
enum CsrfFailureReason: string {

	/** Sec-Fetch-Site reports a source that the Fetch Metadata policy does not allow, and the Origin is not trusted. */
	case FetchMetadataRejected = 'fetch_metadata_rejected';

	/** The Origin header is neither the request's own origin nor a trusted origin, or cannot be parsed. */
	case OriginMismatch        = 'origin_mismatch';

	/** No usable Origin header, and no enabled fallback applies to the request. */
	case OriginMissing         = 'origin_missing';

	/** Without a usable Origin, the Referer is neither the request's own origin nor a trusted one, or cannot be parsed. */
	case RefererMismatch       = 'referer_mismatch';

	/** Without a usable Origin, the Referer fallback found no Referer header. */
	case RefererMissing        = 'referer_missing';

	/** No token was submitted, or the session holds none. */
	case TokenMissing          = 'token_missing';

	/** The submitted token is not a string or not in the token format. */
	case TokenInvalid          = 'token_invalid';

	/** The submitted token differs from the session token. */
	case TokenMismatch         = 'token_mismatch';
}
