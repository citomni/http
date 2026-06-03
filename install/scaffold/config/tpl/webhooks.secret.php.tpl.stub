<?php
declare(strict_types=1);
/*
 * CitOmni Webhooks Secret (TEMPLATE)
 * ------------------------------------------------------------
 * DO NOT COMMIT THE REAL SECRET FILE.
 * Commit only this .tpl template. The real file should be created
 * by your deployment/DevKit on the remote and kept out of VCS.
 *
 * Contract:
 * - This file MUST be side-effect free and return a plain array.
 * - 'secret' must be a hex string (recommended: 64 chars for sha256,
 *   or 128 chars for sha512).
 * - 'algo' is optional. If also set in cfg, cfg wins.
 *
 * Permissions:
 * - Recommended: chmod 0600 (owner read/write only).
 *
 * Typical location:
 *   CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
 */

return [
	// REQUIRED: HMAC secret as hex.
	// Generate example (sha256): php -r "echo bin2hex(random_bytes(32));"
	// Generate example (sha512): php -r "echo bin2hex(random_bytes(64));"
	'secret' => 'REPLACE-WITH-HEX',

	// OPTIONAL: HMAC algorithm. Allowed: 'sha256' (default) or 'sha512'.
	// If omitted here and provided in cfg, cfg value is used. If set in both,
	// cfg takes precedence.
	'algo' => 'sha256',

	// OPTIONAL METADATA (ignored by verification; useful for ops visibility)
	// Use ISO 8601 timestamps if you include these fields.
	// 'rotated_at_utc'   => '2025-10-17T11:12:00Z',
	// 'rotated_at_local' => '2025-10-17T13:12:00+02:00',
	// 'generator'        => 'CitOmni DevKit',
];
