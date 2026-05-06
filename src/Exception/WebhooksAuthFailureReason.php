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
 * WebhooksAuthFailureReason: Structured reasons for webhook authentication failures.
 *
 * Each case represents a distinct failure mode in the webhook verification
 * pipeline. The fine-grained distinction (e.g. SignatureMalformed vs
 * SignatureMismatch) enables precise logging, debugging, and testing without
 * parsing free-form error strings.
 *
 * Notes:
 * - Values are stable identifiers safe to log and assert against in tests.
 * - Order roughly mirrors the verification pipeline (cheapest checks first).
 */
enum WebhooksAuthFailureReason: string {

	/** Service is configured as disabled - no requests can authenticate. */
	case Disabled = 'disabled';

	/** Source IP not present in non-empty allow-list. */
	case IpNotAllowed = 'ip_not_allowed';

	/** One or more required authentication headers were missing or empty. */
	case HeadersMissing = 'headers_missing';

	/** Signature header has wrong length or contains non-hex characters. */
	case SignatureMalformed = 'signature_malformed';

	/** Timestamp is too old, in the future beyond clock skew, or non-positive. */
	case TimestampOutOfWindow = 'timestamp_out_of_window';

	/** Nonce was already used (replay) or storage failed. */
	case NonceRejected = 'nonce_rejected';

	/** HMAC signature did not match the canonical base string. */
	case SignatureMismatch = 'signature_mismatch';

}
