<?php
declare(strict_types=1);
/*
 * CitOmni - Webhooks authentication secret template
 *
 * PURPOSE
 *   Provide the expected structure for the private secret file used by
 *   CitOmni\Http\Service\WebhooksAuth.
 *
 * SECURITY
 *   - This template contains no real secret and may be committed to version control.
 *   - Never commit the populated secret file.
 *   - Generate the secret from cryptographically secure random bytes.
 *   - Restrict filesystem access to the populated secret file where supported
 *     (0600 is recommended on Unix-like systems).
 *
 * CONTRACT
 *   - The populated file must return a plain PHP array.
 *   - 'secret' is required and must be a non-empty hexadecimal string.
 *   - 'algo' is optional and must be 'sha256' or 'sha512' when present.
 *   - Algorithm precedence is:
 *       cfg.webhooks.algo
 *       -> this file's 'algo'
 *       -> sha256
 *
 * GENERATION
 *   A 32-byte random secret provides a strong default:
 *
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *
 * TYPICAL TARGET
 *   var/secrets/webhooks.secret.php
 */

return [
	// REQUIRED: Replace with cryptographically secure random hexadecimal data.
	'secret' => 'REPLACE-WITH-RANDOM-HEX',

	// OPTIONAL: Omit to use cfg.webhooks.algo or the default 'sha256'.
	// 'algo' => 'sha256',
];
