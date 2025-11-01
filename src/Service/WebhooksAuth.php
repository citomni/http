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

namespace CitOmni\Http\Service;

use CitOmni\Kernel\Service\BaseService;


/**
 * WebhooksAuth: HMAC-based verification for administrative webhook requests.
 *
 * Responsibilities:
 * - Enforce a layered authorization policy for inbound webhooks:
 *   1) Feature switch (enabled)
 *   2) Optional source IP allow-list (exact or IPv4 CIDR)
 *   3) Required headers (timestamp, nonce, signature)
 *   4) Freshness window (TTL) with clock-skew tolerance
 *   5) Nonce uniqueness via the App's Nonce service (replay protection)
 *   6) Constant-time HMAC verification over a canonical base string
 * - Offer two caller interfaces:
 *   - guard(): boolean convenience that never throws
 *   - assertAuthorized(): strict validation that throws on failure
 *
 * Collaborators:
 * - $this->app->nonce (read/write) - persists single-use nonces for replay protection.
 *
 * Configuration keys:
 * - webhooks.enabled (bool) - master enable switch (default true)
 * - webhooks.secret_file (string) - absolute path to a side-effect-free PHP file
 *   that returns ['secret' => <hex>, 'algo' => 'sha256'|'sha512' (optional)]
 * - webhooks.ttl_seconds (int) - max age for requests (default 300)
 * - webhooks.ttl_clock_skew_tolerance (int) - +/- seconds tolerance (default 60)
 * - webhooks.allowed_ips (string[]) - exact IPs or IPv4 CIDR
 * - webhooks.nonce_dir (string) - directory for the nonce ledger
 * - webhooks.algo (string) - "sha256" or "sha512" (default "sha256"); if omitted,
 *   and the secret file contains 'algo', the file's value is used.
 * - webhooks.bind_context (bool) - include method/path/query/body hash in HMAC base (default false)
 * - webhooks.header_signature (string) - server key for signature header (default "HTTP_X_CITOMNI_SIGNATURE")
 * - webhooks.header_timestamp (string) - server key for timestamp header (default "HTTP_X_CITOMNI_TIMESTAMP")
 * - webhooks.header_nonce (string) - server key for nonce header (default "HTTP_X_CITOMNI_NONCE")
 *
 * Behavior
 *   Verifies admin webhook requests using an HMAC signature, TTL with clock-skew
 *   tolerance, optional IP allow‑list, and a nonce ledger to block replays.
 *
 *   Default signature base string: "<ts>.<nonce>.<rawBody>" (HMAC).
 *   If `bind_context=true`, the canonical string becomes a multi-line block:
 *   ts + "\n" + nonce + "\n" + method + "\n" + path + "\n" + query + "\n" + sha256(rawBody)
 *   which strengthens request binding at the cost of requiring your client to
 *   include these values consistently.
 *
 * Error handling:
 * - Fail fast in assertAuthorized() with a precise \RuntimeException reason.
 * - guard() intentionally catches all throwables and returns false for lean call sites that only need a boolean.
 *
 * Typical usage in a controller:
 *
 *   $rawBody = \file_get_contents('php://input') ?: '';
 *   $res = $this->app->webhooksAuth
 *          ->setOptions($this->app->cfg->webhooks)
 *          ->guard($_SERVER, $rawBody);
 *   if ($res) {
 *       // Authorized - perform the sensitive action
 *   }
 *
 * Examples:
 *
 *   // Strict path: throw on failure, let global error handler log.
 *   $this->app->webhooksAuth
 *       ->setOptions($this->app->cfg->webhooks)
 *       ->assertAuthorized($_SERVER, $rawBody);
 *   // If we reach here, authorization passed - proceed
 *
 *   // Enabling context binding for stronger request coupling:
 *   $this->app->webhooksAuth->setOptions([
 *       'enabled' => true,
 *       'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',
 *       'nonce_dir' => CITOMNI_APP_PATH . '/var/nonces',
 *       'bind_context' => true, // binds method, path, query, and body hash
 *       'allowed_ips' => ['203.0.113.0/24', '198.51.100.10'],
 *   ])->assertAuthorized($_SERVER, $rawBody);
 *
 * Failure:
 *
 *   // Missing nonce_dir or secret (when enabled) will fail fast:
 *   $this->app->webhooksAuth->setOptions(['enabled' => true])->assertAuthorized($_SERVER, $rawBody);
 *   // Throws \RuntimeException that bubbles to the global error handler
 *
 * Canonical HMAC base string:
 * - Simple mode (bind_context = false):
 *   "<ts>.<nonce>.<rawBody>"
 * - Context-bound mode (bind_context = true):
 *   ts + "\n" + nonce + "\n" + METHOD + "\n" + PATH + "\n" + QUERY + "\n" + sha256(rawBody)
 *
 * Expected headers (customizable via setOptions):
 * - X-Citomni-Timestamp : UNIX seconds when the signature was created.
 * - X-Citomni-Nonce     : Unique, single-use identifier per request.
 * - X-Citomni-Signature : Hex HMAC of the base string (see `algo`).
 *
 * Options object shape (stdClass or array-access):
 * - enabled (bool)                         default: true
 * - secret_file (string)                   required when enabled; absolute path to side-effect-free PHP file
 * - nonce_dir (string)                     required when enabled; writable directory for nonce ledger
 * - ttl_seconds (int)                      default: 300
 * - ttl_clock_skew_tolerance (int)         default: 60
 * - allowed_ips (string[])                 default: []
 * - algo (string)                          default: 'sha256'  // 'sha256' or 'sha512'
 * - bind_context (bool)                    default: false
 * - header_signature (string)              default: 'HTTP_X_CITOMNI_SIGNATURE'
 * - header_timestamp (string)              default: 'HTTP_X_CITOMNI_TIMESTAMP'
 * - header_nonce (string)                  default: 'HTTP_X_CITOMNI_NONCE'
 *
 * Notes:
 * - Nonces are persisted/checked via the App's Nonce service:
 *     $this->app->nonce->setOptions((object)['dir' => $this->nonceDir])->checkAndStore(...)
 * - `allowed_ips` supports exact matches and IPv4 CIDR blocks (IPv6 can be added later).
 * - `guard()` returns bool; `assertAuthorized()` throws \RuntimeException with a precise reason.
 */
class WebhooksAuth extends BaseService {

	// Master enable/disable switch for webhook auth (fast bypass if false)
	private bool $enabled = true;
	
	// Shared secret key used for HMAC signature validation
	private string $secret = '';
	
	// Maximum allowed request age in seconds (rejects stale/replayed requests)
	private int $ttlSeconds = 300;
	
	// Extra tolerance in seconds for clock skew between client and server
	private int $clockSkew = 60;
	
	// Last failure reason; null on success or not called yet.
	private ?string $lastError = null;

	// Optional IP allow-list (supports exact match and IPv4 CIDR)
	/** @var string[] */
	private array $allowedIps = [];
	
	// Filesystem directory for nonce storage (prevents replay attacks)
	private string $nonceDir = '';
	
	// HMAC algorithm to use for signature generation (sha256 or sha512)
	private string $algo = 'sha256';
	
	// Whether to bind signature to method, path, query, and body hash
	private bool $bindContext = false;

	// Filesystem path to secret file (side-effect free; returns array)
	private string $secretFile = '';

	// Header keys (mapped from $_SERVER by PHP)
	// Header carrying the computed HMAC signature
	private string $hSignature = 'HTTP_X_CITOMNI_SIGNATURE';
	
	// Header carrying the client-supplied UNIX timestamp
	private string $hTimestamp = 'HTTP_X_CITOMNI_TIMESTAMP';
	
	// Header carrying the client-supplied unique nonce
	private string $hNonce = 'HTTP_X_CITOMNI_NONCE';


	/**
	 * Service bootstrap hook.
	 *
	 * Behavior:
	 * - Keep initialization minimal for cold-start performance.
	 *
	 * Notes:
	 * - This service does not keep internal state; no warm-up required.
	 *
	 * Typical usage:
	 *   // Nothing to call explicitly; the app constructs the service as needed.
	 *
	 * @return void
	 */
	/* 
	protected function init(): void {
		// Intentionally empty (lean boot).
	}
	*/


	/**
	 * Apply WebhooksAuth options from an array or object.
	 *
	 * Accepts either an associative array or an object (e.g., stdClass) with the following keys:
	 *
	 * Required when `enabled=true`:
	 * - secret_file (string)   // path to PHP file that returns ['secret' => <hex>, 'algo' => ...]
	 * - nonce_dir (string)     // writable directory for nonce ledger
	 *
	 * Optional:
	 * - enabled (bool)                      default: true
	 * - ttl_seconds (int)                   default: 300
	 * - ttl_clock_skew_tolerance (int)      default: 60
	 * - allowed_ips (string[])              default: []
	 * - algo (string)                       default: 'sha256'  // 'sha256' or 'sha512'
	 * - bind_context (bool)                 default: false
	 * - header_signature (string)           default: 'HTTP_X_CITOMNI_SIGNATURE'
	 * - header_timestamp (string)           default: 'HTTP_X_CITOMNI_TIMESTAMP'
	 * - header_nonce (string)               default: 'HTTP_X_CITOMNI_NONCE'
	 *
	 * Behavior:
	 * - Normalizes and stores configuration without performing IO.
	 * - Validates only when enabled to keep overhead low.
	 *
	 * Notes:
	 * - Options are validated only when `enabled=true`. Missing required pieces cause RuntimeException.
	 * - Minimal overhead by design; disk checks for nonce_dir existence/writability are intentionally
	 *   not performed here (can be handled by the Nonce service on first write).
	 *
	 * Typical usage:
	 *   $this->app->webhooksAuth->setOptions($this->app->cfg->webhooks);
	 *
	 * @param array|object $opts Options payload (array or object with public properties).
	 * @return self
	 * @throws \RuntimeException If required values are missing or invalid when enabled.
	 */
	public function setOptions($opts): self {
		
		// Small local getter: returns $src[$key] or $src->{$key}, else $default.
		$get = static function ($src, string $key, $default = null) {
			if (is_array($src) && array_key_exists($key, $src)) return $src[$key];
			if (is_object($src) && isset($src->{$key})) return $src->{$key};
			return $default;
		};

		// Core toggles and parameters
		$this->enabled = (bool)$get($opts, 'enabled', true);
		$this->ttlSeconds = (int)$get($opts, 'ttl_seconds', 300);
		$this->clockSkew = (int)$get($opts, 'ttl_clock_skew_tolerance', 60);
		$this->algo = strtolower((string)$get($opts, 'algo', 'sha256')); // normalize for comparison
		$this->bindContext = (bool)$get($opts, 'bind_context', false);
		
		// Normalize allowed_ips: accept only scalar strings/ints; ignore arrays/objects/null.
		$rawAllowed = (array)$get($opts, 'allowed_ips', []);
		$allowed = [];
		foreach ($rawAllowed as $item) {
			if (\is_string($item) || \is_int($item)) {
				$val = \trim((string)$item);
				if ($val !== '') {
					$allowed[] = $val;
				}
			}
			// Non-scalar entries are ignored to avoid "Array to string conversion".
		}
		$this->allowedIps = $allowed;

		// nonce_dir: prefer explicit option; fallback to cfg->webhooks->nonce_dir; else empty (validated below if enabled)
		$nonceDir = $get($opts, 'nonce_dir', null);

		if ($nonceDir === null) {
			// use ArrayAccess so we don't blow up if 'webhooks' node doesn't exist at all
			if (isset($this->app->cfg['webhooks']) && isset($this->app->cfg['webhooks']['nonce_dir'])) {
				$nonceDir = $this->app->cfg['webhooks']['nonce_dir'];
			}
		}

		$this->nonceDir = (string)$nonceDir;
		
		// Pick up secret_file (defaulting to baseline path if desired)
		$this->secretFile = (string)$get($opts, 'secret_file', CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php');

		// Load secret (and maybe algo) from file immediately
		$this->loadSecretFromFile($this->secretFile);

		// Header keys as seen in $_SERVER (can be overridden)
		$this->hSignature = (string)$get($opts, 'header_signature', 'HTTP_X_CITOMNI_SIGNATURE');
		$this->hTimestamp = (string)$get($opts, 'header_timestamp', 'HTTP_X_CITOMNI_TIMESTAMP');
		$this->hNonce = (string)$get($opts, 'header_nonce', 'HTTP_X_CITOMNI_NONCE');

		// Fail fast if enabled and required pieces are missing/invalid
		if ($this->enabled) {
			
			// Secret must be found in valid secret-file
			if ($this->secret === '') {
				throw new \RuntimeException('WebhooksAuth: Missing HMAC secret (expected via secret_file).');
			}
			// Nonce directory path must be provided (existence/writability is validated lazily by the Nonce service)
			if ($this->nonceDir === '') {
				throw new \RuntimeException('WebhooksAuth: Missing nonce_dir.');
			}
			// TTL must be positive (0 disables all requests instantly)
			if ($this->ttlSeconds < 1) {
				throw new \RuntimeException('WebhooksAuth: ttl_seconds must be >= 1.');
			}
			// Clock skew must be non-negative
			if ($this->clockSkew < 0) {
				throw new \RuntimeException('WebhooksAuth: ttl_clock_skew_tolerance must be >= 0.');
			}
			// Only allow known algorithms
			if ($this->algo !== 'sha256' && $this->algo !== 'sha512') {
				throw new \RuntimeException('WebhooksAuth: Unsupported algo (expected sha256 or sha512).');
			}
		}

		return $this;
	}


	/**
	 * Performs a soft authorization check for incoming webhook requests.
	 *
	 * This is a convenience wrapper around {@see assertAuthorized()}:
	 * - Returns true if the request is authorized.
	 * - Returns false if any validation step fails.
	 *
	 * Diagnostics:
	 * - Never throws. On failure, the last failure reason is captured internally
	 *   and can be retrieved via {@see WebhooksAuth::getLastError()}.
	 * - On success, the internal failure state is cleared.
	 *
	 * Notes:
	 * - This method is suitable when the caller only needs a boolean and logging
	 *   is handled centrally. The failure reason is *not* sent to the client.
	 * - The stored last error is per-service instance; do not rely on it across
	 *   concurrent requests/processes.
	 *
	 * Example:
	 *   if ($this->app->webhooksAuth->guard($_SERVER, $rawBody)) {
	 *       // Authorized
	 *   } else {
	 *       // Failed; fetch internal reason for logs/metrics:
	 *       $reason = $this->app->webhooksAuth->getLastError();
	 *   }
	 *
	 * @param array  $server  Typically the $_SERVER superglobal.
	 * @param string $rawBody Raw request body (as read from php://input).
	 * @return bool           True if authorized; false otherwise.
	 */
	public function guard(array $server, string $rawBody): bool {
		try {
			// Attempt full authorization, throws on any failure.
			$this->assertAuthorized($server, $rawBody);
			
			// Clear previous error on success
			$this->lastError = null; 

			// If no exception was thrown, the request is authorized.
			return true;
		} catch (\Throwable $e) {
			// Swallow all errors/exceptions and return false instead.
			// Reason for failure is intentionally suppressed.
			// return false;
			
			$this->lastError = $e->getMessage(); // capture precise reason
			return false;
		}
	}


	/**
	 * Performs strict authorization for an incoming webhook request.
	 *
	 * Behavior:
	 * - Validates enabled/configured, allow-list, headers, timestamp window, nonce uniqueness, and HMAC.
	 * - Throws \RuntimeException with a precise reason on the first failure encountered.
	 *
	 * This method validates the request against multiple layers of defense:
	 *  1. Service enabled & configured (secret + nonce_dir).
	 *  2. Optional IP allow-list (exact match or IPv4 CIDR).
	 *  3. Required authentication headers (signature, timestamp, nonce).
	 *  4. Timestamp freshness within configured TTL and clock skew tolerance.
	 *  5. Nonce uniqueness, enforced by the Nonce service (atomic storage).
	 *  6. HMAC signature verification in constant time.
	 *
	 * On any failure, a \RuntimeException is thrown with a clear reason.
	 * Callers can either let the global error handler log these, or catch
	 * them in a wrapper like {@see guard()} for boolean checks.
	 *
	 * Notes:
	 * - Use this in strict flows and let the global error handler log failures.
	 *
	 * Typical usage:
	 *   $this->app->webhooksAuth->assertAuthorized($_SERVER, $rawBody); // throws on failure
	 *
	 * @param array  $server   Typically the $_SERVER superglobal.
	 * @param string $rawBody  Raw HTTP request body (from php://input).
	 * @throws \RuntimeException If the request fails validation at any step.
	 */
	public function assertAuthorized(array $server, string $rawBody): void {
		
		// Ensure service is enabled and minimally configured
		if (!$this->enabled) {
			throw new \RuntimeException('Webhooks are disabled.');
		}
		if ($this->secret === '' || $this->nonceDir === '') {
			throw new \RuntimeException('Webhooks not configured (missing secret or nonce_dir).');
		}

		// 1) IP allow-list (supports exact match and CIDR like "203.0.113.0/24")
		if (!empty($this->allowedIps)) {
			$ip = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';
			if ($ip === '' || !$this->ipAllowed($ip, $this->allowedIps)) {
				throw new \RuntimeException('Source IP not allowed.');
			}
		}

		// 2) Required authentication headers
		$sig   = isset($server[$this->hSignature]) ? (string)$server[$this->hSignature] : '';
		$tsRaw = isset($server[$this->hTimestamp]) ? (string)$server[$this->hTimestamp] : '';
		$nonce = isset($server[$this->hNonce])     ? (string)$server[$this->hNonce]     : '';

		if ($sig === '' || $tsRaw === '' || $nonce === '') {
			throw new \RuntimeException('Missing required authentication headers.');
		}

		// Sanity-check signature format (hex string, expected length for algo)
		$wantLen = ($this->algo === 'sha512') ? 128 : 64;
		if (strlen($sig) !== $wantLen || !ctype_xdigit($sig)) {
			throw new \RuntimeException('Malformed signature.');
		}

		// 3) Timestamp validation (must be within TTL + skew tolerance)
		$now    = time();
		$ts     = (int)$tsRaw;
		$maxAge = $this->ttlSeconds + $this->clockSkew;

		// Reject if timestamp is invalid, too old, or too far in the future
		if ($ts <= 0 || ($now - $ts) > $maxAge || ($ts - $now) > $this->clockSkew) {
			throw new \RuntimeException('Request timestamp outside allowed window.');
		}

		// 4) Nonce check: must be unused; persisted via Nonce service
		$nonceOk = $this->app->nonce
			->setOptions((object)['dir' => $this->nonceDir])
			->checkAndStore($nonce, $this->ttlSeconds);

		if (!$nonceOk) {
			throw new \RuntimeException('Nonce already used or storage failure.');
		}

		// 5) Signature verification
		$base = $this->buildBaseString($server, $rawBody, $ts, $nonce);
		$calc = hash_hmac($this->algo, $base, $this->secret);

		// Compare in constant time; normalize client-sent hex to lowercase
		if (!hash_equals($calc, strtolower($sig))) {
			throw new \RuntimeException('Invalid HMAC signature.');
		}
	}


	/**
	 * Builds the canonical string used as HMAC input.
	 *
	 * Behavior:
	 * - Simple mode (bind_context = false): "<ts>.<nonce>.<rawBody>"
	 * - Context-bound (bind_context = true): newline-delimited block
	 *   ts, nonce, METHOD (uppercased), PATH (no query), QUERY (no leading "?"),
	 *   and sha256(rawBody) in hex.
	 *
	 * Two modes exist:
	 * - Simple mode (bind_context = false):
	 *     "<ts>.<nonce>.<rawBody>"
	 *
	 * - Context-bound mode (bind_context = true): a newline-delimited block
	 *     ts        + "\n" +
	 *     nonce     + "\n" +
	 *     METHOD    + "\n" +   // uppercased
	 *     PATH      + "\n" +   // no query string, always begins with "/"
	 *     QUERY     + "\n" +   // without leading "?"
	 *     SHA256    // hex of rawBody (hash of empty string if body is empty)
	 *
	 * Notes:
	 * - Order and exact separators matter; callers must mirror this serialization.
	 * - No trailing newline is appended.
	 *
	 * @param array  $server   Typically the $_SERVER superglobal.
	 * @param string $rawBody  Raw request body (as read from php://input).
	 * @param int    $ts       UNIX timestamp already validated by caller.
	 * @param string $nonce    Client-supplied unique nonce (already validated).
	 * @return string          Canonical base string for HMAC.
	 */
	private function buildBaseString(array $server, string $rawBody, int $ts, string $nonce): string {
		
		// Simple mode: timestamp, nonce, raw body concatenated with dots.
		if (!$this->bindContext) {
			return (string)$ts . '.' . (string)$nonce . '.' . $rawBody;
		}

		// Context-bound mode: include method, path, query, and body hash.

		// HTTP method (uppercased). Missing key yields empty string (keeps deterministic shape).
		$method = isset($server['REQUEST_METHOD']) ? strtoupper((string)$server['REQUEST_METHOD']) : '';

		// Full request-target (path + optional query). Use REQUEST_URI to avoid server-specific recomposition.
		$uri = isset($server['REQUEST_URI']) ? trim((string)$server['REQUEST_URI']) : '';

		// Split on first "?" into path and query parts.
		$path = $uri;
		$query = '';
		$parts = explode('?', $uri, 2);
		if (count($parts) === 2) {
			$path = $parts[0];
			$query = $parts[1];
		}

		// Defensive whitespace normalization.
		$path = trim($path);
		$query = trim($query);

		// Ensure path always starts with "/" to keep canonical shape ("/" for empty paths).
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . ltrim($path, '/');
		}

		// SHA-256 of the raw request body (hex). Empty body -> hash of empty string.
		$bodySha = hash('sha256', $rawBody);

		// Canonical multi-line string in strict order (no trailing newline).
		return implode("\n", [
			(string)$ts,    // Timestamp
			(string)$nonce, // Nonce
			$method,        // HTTP method (uppercased)
			$path,          // Path without query
			$query,         // Query without leading '?'
			$bodySha,       // SHA-256 of body (hex)
		]);
	}


	/**
	 * Checks if a client IP is allowed by an allow-list.
	 *
	 * Behavior:
	 * - Fast exact match first.
	 * - Then checks IPv4 CIDR ranges like "203.0.113.0/24".
	 *
	 * Supports:
	 * - Exact matches (fast path).
	 * - IPv4 CIDR ranges (e.g., "203.0.113.0/24").
	 *
	 * Notes:
	 * - IPv6 entries are skipped (not implemented in lean core).
	 * - Malformed or non-string entries in the list are ignored.
	 *
	 * @param string $ip   Client IP address (typically from $_SERVER['REMOTE_ADDR']).
	 * @param array  $list Array of allowed entries (exact IPs or CIDR blocks).
	 * @return bool        True if allowed, false otherwise.
	 */
	private function ipAllowed(string $ip, array $list): bool {
		
		// Exact match check first (fast path)
		if (in_array($ip, $list, true)) {
			return true;
		}

		// CIDR range checks (currently only IPv4 implemented)
		foreach ($list as $entry) {
			if (!is_string($entry)) {
				continue; // ignore non-string entries
			}

			// Must contain a slash to be a CIDR entry
			$slash = strpos($entry, '/');
			if ($slash === false) {
				continue;
			}

			$net  = substr($entry, 0, $slash);  // Network address part before the slash (e.g. "203.0.113.0")
			$bits = (int)substr($entry, $slash + 1);  // Prefix length after the slash (e.g. 24 -> means /24 subnet)

			// Skip invalid prefix lengths (must be 1..32 for IPv4)
			if ($bits <= 0 || $bits > 32) {
				continue;
			}

			// Only support IPv4 here; skip if not both valid IPv4
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				
				$ipLong  = ip2long($ip);  // Convert client IP to 32-bit integer
				$netLong = ip2long($net);  // Convert network address to 32-bit integer
				
				// If either conversion failed (invalid IPv4 format), skip this entry
				if ($ipLong === false || $netLong === false) {
					continue;
				}

				// Build subnet mask and apply bitwise check
				$mask = -1 << (32 - $bits);
				$mask = $mask & 0xFFFFFFFF;

				// Apply subnet mask to both IP and network, then compare.
				// If masked values match, the client IP is inside the allowed subnet.
				if (($ipLong & $mask) === ($netLong & $mask)) {
					return true;
				}
			}
		}

		// No match found
		return false;
	}


	/**
	 * Loads the HMAC secret (and optionally algo) from a side-effect-free PHP file.
	 *
	 * File must return an array like:
	 *   ['secret' => <hex>, 'algo' => 'sha256'|'sha512' (optional), ...metadata]
	 *
	 * Precedence:
	 * - If cfg provided 'algo', it wins over the file's 'algo'.
	 *
	 * @param string|null $file Absolute path to secret file. If empty/missing, no-op.
	 * @return void
	 */
	private function loadSecretFromFile(?string $file): void {
		$file = (string)$file;
		if ($file === '' || !is_file($file)) {
			// Keep $this->secret empty; validation will fail on use if enabled.
			return;
		}

		$data = @include $file;
		if (!is_array($data)) {
			throw new \RuntimeException('WebhooksAuth: secret_file did not return an array.');
		}

		$secret = (string)($data['secret'] ?? '');
		if ($secret === '' || !ctype_xdigit($secret)) {
			throw new \RuntimeException('WebhooksAuth: secret_file "secret" must be hex.');
		}
		$this->secret = strtolower($secret);

		// Accept file-provided algo only if cfg didn't explicitly set one different
		if (isset($data['algo'])) {
			$maybe = strtolower((string)$data['algo']);
			if (($this->algo === '' || $this->algo === 'sha256') && ($maybe === 'sha256' || $maybe === 'sha512')) {
				$this->algo = $maybe;
			}
		}
	}


	/**
	 * Returns the most recent failure reason captured by {@see guard()}.
	 *
	 * Behavior:
	 * - Returns a human-readable reason string (from the thrown exception) for the
	 *   last failed guard() call in this instance, or null if the last guard()
	 *   succeeded or guard() has not yet been called.
	 * - The value is cleared on successful guard() calls.
	 *
	 * Notes:
	 * - For diagnostics/logging only. Do not expose this directly in client responses.
	 * - Instance-local state; not shared across requests/processes.
	 *
	 * @return ?string Reason string, or null when unavailable.
	 */
	public function getLastError(): ?string {
		return $this->lastError;
	}

}
