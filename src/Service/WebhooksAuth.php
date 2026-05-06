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

use CitOmni\Http\Enum\WebhooksAuthFailureReason;
use CitOmni\Http\Exception\WebhooksAuthConfigException;
use CitOmni\Http\Exception\WebhooksAuthVerificationException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * WebhooksAuth: HMAC-authenticated webhook verification with TTL and replay protection.
 *
 * Verifies inbound webhook requests against a side-effect-free HMAC scheme:
 *   - Signature is computed over a canonical base string (simple or context-bound).
 *   - Timestamp must fall inside a freshness window (TTL plus clock-skew tolerance).
 *   - Nonce must be unique across the full timestamp acceptance window
 *     (replay protection via Nonce service).
 *   - Optional source-IP allow-list restricts traffic to known peers (exact or CIDR).
 *
 * API surface mirrors the CSRF service so adapters can pick the flow that fits:
 *   - verify():           bool, never throws - for "swallow + log + 404" patterns.
 *   - requireValid():     throws WebhooksAuthVerificationException - for fail-fast/API.
 *   - requireOrAbort():   delegates to errorHandler->httpError(); never returns on failure.
 *
 * Behavior:
 * - When cfg.webhooks.enabled is false, all verification methods report failure
 *   with reason Disabled. This is intentional - a disabled webhook surface must
 *   not silently accept any request, only reject every request.
 * - The HMAC secret is loaded from a side-effect-free PHP file at init time.
 *   The cfg never carries the secret value itself.
 * - The HMAC algorithm precedence is: cfg.webhooks.algo > secret_file 'algo' > 'sha256'.
 * - Source IP is resolved through Request::ip() first (which honors the
 *   configured trusted-proxy whitelist for public traffic), then falls back
 *   to $_SERVER['REMOTE_ADDR'] when Request::ip() reports 'unknown' or 'CLI'.
 *   This widens matching to private/internal peers (Docker, LAN, localhost
 *   cron) where Request::ip()'s "must be public" contract is too strict.
 * - Logging goes through the log service when available and enabled. The service
 *   never writes directly to files.
 *
 * Canonical base strings (selected by cfg.webhooks.bind_context):
 * - bind_context = false (loose):
 *       <ts> "." <nonce> "." <rawBody>
 * - bind_context = true (default, stricter):
 *       <ts> "\n" <nonce> "\n" <METHOD> "\n" <PATH> "\n" <QUERY> "\n" sha256(rawBody)
 *
 * Notes:
 * - The body hash inside the context-bound base string is always SHA-256,
 *   regardless of HMAC algorithm. This matches common public schemes and
 *   keeps client implementations simple.
 * - Body bytes are read from php://input once and cached for the request.
 *
 * Config node: webhooks
 *   - enabled               bool      Master switch (default false).
 *   - secret_file           string    Absolute path to side-effect-free PHP file returning array{secret:string,algo?:string}.
 *   - ttl_seconds           int       Maximum age of a request in seconds (default 300).
 *   - ttl_clock_skew_tolerance int    Tolerated clock drift on either side (default 60).
 *   - allowed_ips           array     Empty = no IP restriction. Entries: exact IPv4/IPv6, or CIDR.
 *   - algo                  ?string   'sha256' or 'sha512'. Null defers to secret file or default.
 *   - bind_context          bool      Bind signature to METHOD/PATH/QUERY/body-hash (default true).
 *   - header_signature      string    $_SERVER key for the signature header.
 *   - header_timestamp      string    $_SERVER key for the timestamp header.
 *   - header_nonce          string    $_SERVER key for the nonce header.
 *   - log_failures          bool      Write failure events to the log service (default true).
 *   - log_successes         bool      Write success events to the log service (default false).
 *   - log_file              string    Log filename used by the log service (default 'webhooks.jsonl').
 *
 * Typical usage:
 *
 *   // Common case: terminate on failure, return raw body on success.
 *   $body = $this->app->webhooksAuth->requireOrAbort();
 *
 *   // Bool flow (custom error response):
 *   if (!$this->app->webhooksAuth->verify()) {
 *       $reason = $this->app->webhooksAuth->getLastFailureReason();
 *       // ...
 *   }
 *
 *   // Fail-fast / API:
 *   try {
 *       $body = $this->app->webhooksAuth->requireValid();
 *   } catch (WebhooksAuthVerificationException $e) {
 *       // $e->reason === WebhooksAuthFailureReason::*
 *   }
 *
 * @throws WebhooksAuthConfigException        On invalid config at init time.
 * @throws WebhooksAuthVerificationException  On verification failure (requireValid only).
 */
final class WebhooksAuth extends BaseService {

	/** Nonce namespace passed to the Nonce service. */
	private const NONCE_NAMESPACE = 'webhooks';

	/** Default HMAC algorithm when neither cfg nor secret file specifies one. */
	private const DEFAULT_ALGO = 'sha256';

	/** Algorithms supported by this service. */
	private const SUPPORTED_ALGOS = ['sha256', 'sha512'];

	/** Expected hex signature length per algorithm. */
	private const SIGNATURE_HEX_LENGTH = ['sha256' => 64, 'sha512' => 128];


	// ---- Configuration (resolved once in init) ----------------------

	private bool $enabled = false;
	private string $secret = '';
	private string $algo = self::DEFAULT_ALGO;
	private int $signatureHexLength = 64;
	private int $ttlSeconds = 300;
	private int $clockSkew = 60;
	private bool $bindContext = true;

	/** @var array<int, string> Pre-trimmed allow-list entries (exact IPs or CIDRs). */
	private array $allowedIps = [];

	private string $headerSignature = 'HTTP_X_CITOMNI_SIGNATURE';
	private string $headerTimestamp = 'HTTP_X_CITOMNI_TIMESTAMP';
	private string $headerNonce     = 'HTTP_X_CITOMNI_NONCE';

	private bool $logFailures = true;
	private bool $logSuccesses = false;
	private string $logFile = 'webhooks.jsonl';


	// ---- Per-request state ------------------------------------------

	/** Cached raw request body (php://input is read at most once per request). */
	private ?string $cachedBody = null;

	/** Last verification failure reason, or null after a successful verify(). */
	private ?WebhooksAuthFailureReason $lastFailureReason = null;





	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Read cfg.webhooks, validate, and resolve the signing secret.
	 *
	 * Behavior:
	 * - Always reads non-secret scalars from cfg so isEnabled() is accurate
	 *   regardless of whether webhooks are active.
	 * - Loads and validates the secret file only when enabled is true.
	 *   When disabled, the service is constructed cheaply and rejects every
	 *   verification call with reason Disabled.
	 *
	 * @return void
	 * @throws WebhooksAuthConfigException On any invalid configuration.
	 */
	protected function init(): void {
		$c = $this->app->cfg->webhooks;

		$this->enabled = (bool)($c->enabled ?? false);

		$this->ttlSeconds = (int)($c->ttl_seconds ?? 300);
		$this->clockSkew  = (int)($c->ttl_clock_skew_tolerance ?? 60);
		$this->bindContext = (bool)($c->bind_context ?? true);

		$this->headerSignature = (string)($c->header_signature ?? 'HTTP_X_CITOMNI_SIGNATURE');
		$this->headerTimestamp = (string)($c->header_timestamp ?? 'HTTP_X_CITOMNI_TIMESTAMP');
		$this->headerNonce     = (string)($c->header_nonce     ?? 'HTTP_X_CITOMNI_NONCE');

		$this->logFailures  = (bool)($c->log_failures  ?? true);
		$this->logSuccesses = (bool)($c->log_successes ?? false);
		$this->logFile      = (string)($c->log_file ?? 'webhooks.jsonl');

		// allowed_ips - may be a Cfg instance (empty array) or a list (non-empty).
		$rawAllowed = $c->allowed_ips ?? [];
		if ($rawAllowed instanceof Cfg) {
			$rawAllowed = $rawAllowed->toArray();
		}
		$this->allowedIps = $this->normalizeAllowedIps((array)$rawAllowed);

		// Algo from cfg is meaningful only when present as a non-empty string.
		// Note: PHP's ?? falls back to RHS when __get() returns null even though
		// __isset() returns true, so 'algo' => null in baseline correctly defers
		// to the file's algo or the default. See SUPPORTED_ALGOS for valid values.
		$cfgAlgo = '';
		$cfgAlgoRaw = $c->algo ?? null;
		if (\is_string($cfgAlgoRaw) && $cfgAlgoRaw !== '') {
			$cfgAlgo = \strtolower($cfgAlgoRaw);
			if (!\in_array($cfgAlgo, self::SUPPORTED_ALGOS, true)) {
				throw new WebhooksAuthConfigException(
					"WebhooksAuth: cfg.webhooks.algo must be one of " . \implode('|', self::SUPPORTED_ALGOS) . " (got '{$cfgAlgo}')."
				);
			}
		}

		if (!$this->enabled) {
			// Resolve algo from cfg only (file not loaded when disabled), so
			// signatureHexLength has a sane value should isEnabled() be ignored.
			$this->algo = $cfgAlgo !== '' ? $cfgAlgo : self::DEFAULT_ALGO;
			$this->signatureHexLength = self::SIGNATURE_HEX_LENGTH[$this->algo];
			return;
		}

		// Enabled path: validate the rest and load the secret.
		if ($this->ttlSeconds < 1) {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.ttl_seconds must be >= 1.');
		}
		if ($this->clockSkew < 0) {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.ttl_clock_skew_tolerance must be >= 0.');
		}
		if ($this->headerSignature === '' || $this->headerTimestamp === '' || $this->headerNonce === '') {
			throw new WebhooksAuthConfigException('WebhooksAuth: header_signature, header_timestamp, and header_nonce must be non-empty.');
		}

		$secretFile = (string)($c->secret_file ?? '');
		if ($secretFile === '') {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.secret_file is required when enabled.');
		}

		$loaded = $this->loadSecretFile($secretFile);
		$fileAlgo = $loaded['algo'];

		// Algo precedence: cfg > file > default.
		$resolvedAlgo = $cfgAlgo !== ''
			? $cfgAlgo
			: ($fileAlgo !== null ? $fileAlgo : self::DEFAULT_ALGO);

		$this->algo = $resolvedAlgo;
		$this->signatureHexLength = self::SIGNATURE_HEX_LENGTH[$this->algo];
		$this->secret = $loaded['secret'];
	}








	// ----------------------------------------------------------------
	// Verification (public API)
	// ----------------------------------------------------------------

	/**
	 * Verify the current request without throwing.
	 *
	 * On failure, records the reason (retrievable via getLastFailureReason())
	 * and returns false. On success, clears the last reason and returns true.
	 *
	 * @return bool True if the request authenticates, false otherwise.
	 */
	public function verify(): bool {
		try {
			$this->verifyOrThrow();
			$this->lastFailureReason = null;

			if ($this->logSuccesses) {
				$this->logEvent('webhook.ok', 'Webhook authenticated', null);
			}
			return true;
		} catch (WebhooksAuthVerificationException $e) {
			$this->lastFailureReason = $e->reason;

			if ($this->logFailures) {
				$this->logEvent('webhook.fail', 'Webhook authentication failed', $e->reason);
			}
			return false;
		}
	}


	/**
	 * Verify the current request and return the raw request body.
	 *
	 * Same engine as verify(), but throws on failure. Use this in API/fail-fast
	 * flows where the caller wants the body and an exception cleanly aborts the
	 * handler.
	 *
	 * @return string Raw request body (may be an empty string).
	 * @throws WebhooksAuthVerificationException On any verification failure.
	 */
	public function requireValid(): string {
		try {
			$this->verifyOrThrow();
			$this->lastFailureReason = null;

			if ($this->logSuccesses) {
				$this->logEvent('webhook.ok', 'Webhook authenticated', null);
			}
			return $this->getRawBody();
		} catch (WebhooksAuthVerificationException $e) {
			$this->lastFailureReason = $e->reason;

			if ($this->logFailures) {
				$this->logEvent('webhook.fail', 'Webhook authentication failed', $e->reason);
			}
			throw $e;
		}
	}


	/**
	 * Verify the current request or terminate via errorHandler->httpError().
	 *
	 * Convenience wrapper used by controllers that want a single-line guard.
	 * On failure the call does not return - errorHandler->httpError() emits
	 * the response and exits.
	 *
	 * Behavior:
	 * - The default failureStatus of 404 implements "endpoint hiding": all
	 *   authentication failures look identical to a missing route, so an
	 *   attacker cannot probe whether the webhook endpoint exists.
	 * - Title and message are not passed; errorHandler picks status-appropriate
	 *   defaults. Callers that need a custom HTTP body should use verify() or
	 *   requireValid() and shape their own response.
	 *
	 * @param  int  $failureStatus  HTTP status sent on failure (default 404).
	 * @return string  Raw request body on success.
	 */
	public function requireOrAbort(int $failureStatus = 404): string {
		if ($this->verify()) {
			return $this->getRawBody();
		}

		$this->app->errorHandler->httpError($failureStatus, [
			'meta' => $this->failureMeta(),
		]);

		// errorHandler->httpError() always terminates; this return is unreachable
		// but required by PHP's return-type system for the success branch above.
		return ''; // @codeCoverageIgnore
	}







	// ----------------------------------------------------------------
	// Inspection helpers
	// ----------------------------------------------------------------

	/**
	 * Whether webhook authentication is enabled at all.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Last verification failure reason, or null after a successful verify().
	 */
	public function getLastFailureReason(): ?WebhooksAuthFailureReason {
		return $this->lastFailureReason;
	}


	/**
	 * Last verification failure reason as a stable string identifier, or null.
	 *
	 * Equivalent to getLastFailureReason()?->value - provided for callers that
	 * only need the textual form (e.g. log context, Problem-Detail responses).
	 */
	public function getLastError(): ?string {
		return $this->lastFailureReason?->value;
	}


	/**
	 * Raw request body for the current request, cached after first read.
	 *
	 * Exposed so adapters can use the verified body without re-reading
	 * php://input (which is single-shot in some SAPI configurations).
	 */
	public function getRawBody(): string {
		if ($this->cachedBody === null) {
			$body = @\file_get_contents('php://input');
			$this->cachedBody = ($body === false) ? '' : $body;
		}
		return $this->cachedBody;
	}







	// ----------------------------------------------------------------
	// Verification engine
	// ----------------------------------------------------------------

	/**
	 * Run all verification stages in order. Throws on the first failure.
	 *
	 * @return void
	 * @throws WebhooksAuthVerificationException
	 */
	private function verifyOrThrow(): void {

		// -- 1. Master switch --------------------------------------------
		if (!$this->enabled) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::Disabled);
		}

		// -- 2. IP allow-list (cheap rejection before any crypto) --------
		// Uses sourceIp() so we honor configured trusted proxies for public
		// senders (CDN/LB rewriting) but still match private/internal peers.
		if ($this->allowedIps !== []) {
			$ip = $this->sourceIp();
			if ($ip === '' || !$this->ipAllowed($ip)) {
				throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::IpNotAllowed);
			}
		}

		// -- 3. Required headers -----------------------------------------
		$server = $_SERVER;
		$sig   = isset($server[$this->headerSignature]) ? (string)$server[$this->headerSignature] : '';
		$tsRaw = isset($server[$this->headerTimestamp]) ? (string)$server[$this->headerTimestamp] : '';
		$nonce = isset($server[$this->headerNonce])     ? (string)$server[$this->headerNonce]     : '';

		if ($sig === '' || $tsRaw === '' || $nonce === '') {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::HeadersMissing);
		}

		// -- 4. Signature shape ------------------------------------------
		if (\strlen($sig) !== $this->signatureHexLength || !\ctype_xdigit($sig)) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::SignatureMalformed);
		}

		// -- 5. Timestamp window -----------------------------------------
		$ts = (int)$tsRaw;
		$now = \time();
		$maxAge = $this->ttlSeconds + $this->clockSkew;
		if ($ts <= 0 || ($now - $ts) > $maxAge || ($ts - $now) > $this->clockSkew) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::TimestampOutOfWindow);
		}

		// -- 6. HMAC verification (constant-time) ------------------------
		// HMAC is checked BEFORE claiming the nonce. This protects the nonce
		// ledger from being filled with junk by attackers who don't know the
		// secret: a garbage signature is rejected here without filesystem IO,
		// while genuine signed replays are still caught at step 7 below.
		$base = $this->buildBaseString($server, $this->getRawBody(), $ts, $nonce);
		$calc = \hash_hmac($this->algo, $base, $this->secret);
		if (!\hash_equals($calc, \strtolower($sig))) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::SignatureMismatch);
		}

		// -- 7. Replay protection ----------------------------------------
		// Only valid signed requests reach this point, so the nonce ledger
		// only ever stores nonces from authenticated callers.
		//
		// TTL must cover the FULL timestamp acceptance window. A single
		// timestamp ts is valid on the verifier's clock from (ts - skew) to
		// (ts + ttl + skew), a span of (ttl + 2*skew). Worst case: a sender
		// whose clock is skew seconds ahead stamps ts = T1 + skew, where T1
		// is the verifier's arrival time. The nonce is stored at T1, and a
		// captured replay is still accepted by the timestamp check until
		// T1 + ttl + 2*skew. Anything shorter leaves a replay gap at the
		// upper bound of the window.
		$nonceTtl = $this->ttlSeconds + (2 * $this->clockSkew);
		if (!$this->app->nonce->checkAndStore(self::NONCE_NAMESPACE, $nonce, $nonceTtl)) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::NonceRejected);
		}
	}


	/**
	 * Build the canonical base string used as input to HMAC.
	 *
	 * @param  array<string,mixed>  $server  $_SERVER snapshot.
	 * @param  string               $rawBody Raw request body.
	 * @param  int                  $ts      Timestamp from header.
	 * @param  string               $nonce   Nonce from header.
	 * @return string
	 */
	private function buildBaseString(array $server, string $rawBody, int $ts, string $nonce): string {
		if (!$this->bindContext) {
			return $ts . '.' . $nonce . '.' . $rawBody;
		}

		$method = isset($server['REQUEST_METHOD']) ? \strtoupper((string)$server['REQUEST_METHOD']) : '';
		$uri    = isset($server['REQUEST_URI'])    ? (string)$server['REQUEST_URI']               : '';

		$path = $uri;
		$query = '';
		$qPos = \strpos($uri, '?');
		if ($qPos !== false) {
			$path  = \substr($uri, 0, $qPos);
			$query = \substr($uri, $qPos + 1);
		}
		$path = \trim($path);
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . \ltrim($path, '/');
		}
		$query = \trim($query);

		// Body hash is always SHA-256, independent of HMAC algo, so clients
		// have one rule to follow regardless of the chosen HMAC strength.
		$bodySha = \hash('sha256', $rawBody);

		return \implode("\n", [
			(string)$ts,
			$nonce,
			$method,
			$path,
			$query,
			$bodySha,
		]);
	}







	// ----------------------------------------------------------------
	// IP matching (IPv4 + IPv6, exact + CIDR)
	// ----------------------------------------------------------------

	/**
	 * Resolve the source IP for allow-list matching.
	 *
	 * Behavior:
	 * - Prefers the request service's resolved client IP, which honors the
	 *   configured trusted-proxy whitelist for public traffic (CDN, LB).
	 * - Falls back to $_SERVER['REMOTE_ADDR'] when the request service
	 *   reports 'unknown' or 'CLI' (which happens for private peers, Docker
	 *   networks, localhost cron, and similar internal scenarios where there
	 *   is no public client IP to resolve).
	 * - Returns an empty string only when no peer address is available at all.
	 *
	 * Notes:
	 * - This intentionally widens beyond Request::ip() because webhook
	 *   senders are often inside the perimeter (internal services, dev
	 *   loopback, container networks) where Request::ip()'s "must be public"
	 *   contract is too strict.
	 *
	 * @return string  Source IP suitable for inet_pton()/CIDR matching, or empty string.
	 */
	private function sourceIp(): string {
		$ip = $this->app->request->ip();
		if ($ip !== '' && $ip !== 'unknown' && $ip !== 'CLI') {
			return $ip;
		}
		$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
		return \trim($remote);
	}


	/**
	 * Check whether the given IP matches any allow-list entry.
	 *
	 * Supports exact IPv4/IPv6 strings and CIDR notation for both families.
	 * Mixed-family comparisons (IPv4 against IPv6 CIDR or vice versa) are
	 * always rejected.
	 */
	private function ipAllowed(string $ip): bool {
		$ipBin = @\inet_pton($ip);
		if ($ipBin === false) {
			return false;
		}

		foreach ($this->allowedIps as $entry) {
			$slash = \strpos($entry, '/');
			if ($slash === false) {
				$entryBin = @\inet_pton($entry);
				if ($entryBin !== false && $entryBin === $ipBin) {
					return true;
				}
				continue;
			}

			$net  = \substr($entry, 0, $slash);
			$bits = (int)\substr($entry, $slash + 1);
			if ($this->ipMatchesCidr($ipBin, $net, $bits)) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Bitwise CIDR comparison on packed binary addresses.
	 *
	 * @param  string  $ipBin    Packed binary form of the candidate IP.
	 * @param  string  $netStr   Network base address (textual).
	 * @param  int     $bits     Prefix length.
	 */
	private function ipMatchesCidr(string $ipBin, string $netStr, int $bits): bool {
		$netBin = @\inet_pton($netStr);
		if ($netBin === false) {
			return false;
		}
		// Reject mixed-family comparisons.
		if (\strlen($netBin) !== \strlen($ipBin)) {
			return false;
		}

		$maxBits = \strlen($ipBin) * 8;
		if ($bits < 0 || $bits > $maxBits) {
			return false;
		}
		if ($bits === 0) {
			return true;
		}

		$bytesFull = \intdiv($bits, 8);
		$bitsRest  = $bits % 8;

		if ($bytesFull > 0 && \substr($ipBin, 0, $bytesFull) !== \substr($netBin, 0, $bytesFull)) {
			return false;
		}
		if ($bitsRest === 0) {
			return true;
		}

		$mask = \chr((0xFF << (8 - $bitsRest)) & 0xFF);
		return (($ipBin[$bytesFull] ?? "\0") & $mask) === (($netBin[$bytesFull] ?? "\0") & $mask);
	}


	/**
	 * Filter and trim allow-list entries from cfg.
	 *
	 * Entries that are not non-empty strings/ints are silently dropped at init
	 * time so the hot path can iterate without per-entry type checks.
	 *
	 * @param  array<int,mixed>  $list
	 * @return array<int,string>
	 */
	private function normalizeAllowedIps(array $list): array {
		$out = [];
		foreach ($list as $item) {
			if (\is_string($item) || \is_int($item)) {
				$val = \trim((string)$item);
				if ($val !== '') {
					$out[] = $val;
				}
			}
		}
		return $out;
	}








	// ----------------------------------------------------------------
	// Secret loading
	// ----------------------------------------------------------------

	/**
	 * Load the side-effect-free secret file and validate its contract.
	 *
	 * The file MUST `return` an associative array with at minimum:
	 *   - 'secret' => string (hex-only, non-empty)
	 * It MAY also include:
	 *   - 'algo'   => 'sha256' | 'sha512'
	 *
	 * @param  string  $file
	 * @return array{secret:string, algo:?string}
	 * @throws WebhooksAuthConfigException
	 */
	private function loadSecretFile(string $file): array {
		if (!\is_file($file)) {
			throw new WebhooksAuthConfigException("WebhooksAuth: secret_file does not exist: {$file}");
		}
		if (!\is_readable($file)) {
			throw new WebhooksAuthConfigException("WebhooksAuth: secret_file is not readable: {$file}");
		}

		$data = require $file;
		if (!\is_array($data)) {
			throw new WebhooksAuthConfigException("WebhooksAuth: secret_file must return an array: {$file}");
		}

		$secret = (string)($data['secret'] ?? '');
		if ($secret === '' || !\ctype_xdigit($secret)) {
			throw new WebhooksAuthConfigException("WebhooksAuth: secret_file 'secret' must be a non-empty hex string: {$file}");
		}

		$algo = null;
		if (isset($data['algo'])) {
			$candidate = \strtolower((string)$data['algo']);
			if (!\in_array($candidate, self::SUPPORTED_ALGOS, true)) {
				throw new WebhooksAuthConfigException(
					"WebhooksAuth: secret_file 'algo' must be one of " . \implode('|', self::SUPPORTED_ALGOS) . " (got '{$candidate}')."
				);
			}
			$algo = $candidate;
		}

		return [
			'secret' => \strtolower($secret),
			'algo'   => $algo,
		];
	}







	// ----------------------------------------------------------------
	// Logging and meta
	// ----------------------------------------------------------------

	/**
	 * Emit a structured log event when the log service is registered.
	 *
	 * Failures inside the log path are swallowed: a logging outage must never
	 * mask the actual verification result delivered to the caller.
	 *
	 * @param  string                          $category
	 * @param  string                          $message
	 * @param  WebhooksAuthFailureReason|null  $reason
	 */
	private function logEvent(string $category, string $message, ?WebhooksAuthFailureReason $reason): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		try {
			$context = [
				'reason'      => $reason?->value,
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
				'method'      => $_SERVER['REQUEST_METHOD'] ?? null,
				'uri'         => $_SERVER['REQUEST_URI'] ?? null,
				'have_sig'    => isset($_SERVER[$this->headerSignature]),
				'have_ts'     => isset($_SERVER[$this->headerTimestamp]),
				'have_nonce'  => isset($_SERVER[$this->headerNonce]),
				'algo'        => $this->algo,
				'bind_ctx'    => $this->bindContext,
			];
			$this->app->log->write($this->logFile, $category, $message, $context);
		} catch (\Throwable) {
			// Swallow: logging must not affect verification semantics.
		}
	}


	/**
	 * Build a compact meta payload for errorHandler->httpError() context.
	 *
	 * @return array<string,mixed>
	 */
	private function failureMeta(): array {
		return [
			'webhook_guard_reason' => $this->lastFailureReason?->value,
			'remote_addr'          => $_SERVER['REMOTE_ADDR'] ?? null,
			'have_sig'             => isset($_SERVER[$this->headerSignature]),
			'have_ts'              => isset($_SERVER[$this->headerTimestamp]),
			'have_nonce'           => isset($_SERVER[$this->headerNonce]),
		];
	}


}
