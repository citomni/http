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
 * WebhooksAuthFailureReason: Enum of why a webhook request failed authentication.
 *
 * Gives each failure mode one machine-readable value, so logs, tests and adapters tell the
 * modes apart without parsing messages. Which checks run, and in which order, belongs to
 * WebhooksAuth.
 *
 * Rules:
 * - Never rename or remove a backing value: The values are logged and asserted against.
 */
enum WebhooksAuthFailureReason: string {

	/** Webhook authentication is disabled, so no request can authenticate. */
	case Disabled = 'disabled';

	/** The source IP matches no entry of a non-empty allow-list. */
	case IpNotAllowed = 'ip_not_allowed';

	/** A required authentication header is missing or empty. */
	case HeadersMissing = 'headers_missing';

	/** The signature has the wrong length or a character that is not hex. */
	case SignatureMalformed = 'signature_malformed';

	/** The timestamp is not positive, too old, or further ahead than the clock skew allows. */
	case TimestampOutOfWindow = 'timestamp_out_of_window';

	/** The nonce ledger refused to claim the nonce, such as for a replay. */
	case NonceRejected = 'nonce_rejected';

	/** The HMAC signature does not match the canonical base string. */
	case SignatureMismatch = 'signature_mismatch';

}
