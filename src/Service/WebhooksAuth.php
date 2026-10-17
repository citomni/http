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
 * WebhooksAuth: Service that authenticates inbound webhook requests by HMAC signature.
 *
 * The verifying side of the signing contract that webhook senders follow: A request passes
 * only with a fresh timestamp, an unused nonce and a matching HMAC signature over them and
 * the body, and, with an allow-list, from an allowed source IP. What an authenticated request
 * may do belongs to the adapter; remembering used nonces belongs to the nonce service.
 *
 * Rules:
 * - Fail closed: Reject every request while disabled, and every request that a check cannot
 *   prove authentic.
 * - Keep the secret out of cfg: Read it only from the secret file that cfg names.
 * - Claim a nonce only for a request whose signature matched, so a sender without the secret
 *   never writes the nonce ledger.
 * - Compare signatures in constant time.
 * - Never log the signature or the secret, and never put them in an error context.
 * - Check cfg values once, in init(), and report a wrong one with WebhooksAuthConfigException.
 */
class WebhooksAuth extends BaseService {

	/** Namespace of webhook nonces in the nonce ledger. */
	private const NONCE_NAMESPACE = 'webhooks';

	/** HMAC algorithm when neither cfg nor the secret file names one. */
	private const DEFAULT_ALGO = 'sha256';

	/** HMAC algorithms that cfg and the secret file may name. */
	private const SUPPORTED_ALGOS = ['sha256', 'sha512'];

	/** Length of a hex signature, per HMAC algorithm. */
	private const SIGNATURE_HEX_LENGTH = ['sha256' => 64, 'sha512' => 128];


	// ----------------------------------------------------------------
	// Settings, resolved once in init()
	// ----------------------------------------------------------------

	/** Whether webhook authentication is on (cfg webhooks.enabled). */
	private bool $enabled = false;

	/** HMAC key: The secret file's hex secret in lowercase, used as text; empty while disabled. */
	private string $secret = '';

	/** HMAC algorithm, one of SUPPORTED_ALGOS. */
	private string $algo = self::DEFAULT_ALGO;

	/** Length of a valid hex signature for $algo. */
	private int $signatureHexLength = 64;

	/** Maximum age of a request timestamp in seconds, before the clock skew is added. */
	private int $ttlSeconds = 300;

	/** Tolerated clock difference to the sender in seconds, in either direction. */
	private int $clockSkew = 60;

	/** Whether the signature also binds method, path and query, over a body hash instead of the raw body. */
	private bool $bindContext = true;

	/** @var list<string> Allowed source IPs and CIDR ranges, trimmed; empty for no restriction. */
	private array $allowedIps = [];

	/** $_SERVER key of the signature header. */
	private string $headerSignature = 'HTTP_X_CITOMNI_SIGNATURE';

	/** $_SERVER key of the timestamp header. */
	private string $headerTimestamp = 'HTTP_X_CITOMNI_TIMESTAMP';

	/** $_SERVER key of the nonce header. */
	private string $headerNonce     = 'HTTP_X_CITOMNI_NONCE';

	/** Whether failed verifications are logged. */
	private bool $logFailures = true;

	/** Whether passed verifications are logged. */
	private bool $logSuccesses = false;

	/** Log file of webhook events. */
	private string $logFile = 'webhooks.jsonl';


	// ----------------------------------------------------------------
	// State of the current request
	// ----------------------------------------------------------------

	/** Raw request body once read, or null before the first read. */
	private ?string $cachedBody = null;

	/** Reason of the last failed verification; null before any and after a passed one. */
	private ?WebhooksAuthFailureReason $lastFailureReason = null;





	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Read and check the webhooks cfg once, and load the secret while enabled.
	 *
	 * Behavior:
	 * - Always checks cfg webhooks.algo: A non-empty string must name one of SUPPORTED_ALGOS,
	 *   in any case; any other value, such as null, names no algorithm.
	 * - Only while cfg webhooks.enabled is true:
	 *     - Requires ttl_seconds >= 1, ttl_clock_skew_tolerance >= 0, non-empty header names
	 *       and a non-empty secret_file.
	 *     - Loads the secret file that secret_file names.
	 *     - The algorithm is cfg algo, else the secret file's algo, else DEFAULT_ALGO.
	 * - While disabled, the algorithm is cfg algo or DEFAULT_ALGO, and no file is read.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\WebhooksAuthConfigException  When cfg algo is unsupported, or, while enabled, another value breaks these requirements or the secret file is unusable.
	 * @see \CitOmni\Http\Service\WebhooksAuth::loadSecretFile()  What the secret file must return.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$c = $this->app->cfg->webhooks;

		$this->enabled = (bool)$c->enabled;

		$this->ttlSeconds = (int)$c->ttl_seconds;
		$this->clockSkew  = (int)$c->ttl_clock_skew_tolerance;
		$this->bindContext = (bool)$c->bind_context;

		$this->headerSignature = (string)$c->header_signature;
		$this->headerTimestamp = (string)$c->header_timestamp;
		$this->headerNonce     = (string)$c->header_nonce;

		$this->logFailures  = (bool)$c->log_failures;
		$this->logSuccesses = (bool)$c->log_successes;
		$this->logFile      = (string)$c->log_file;

		// Cfg wraps an empty allowed_ips array as a Cfg node; a non-empty list stays an array.
		$rawAllowed = $c->allowed_ips;
		if ($rawAllowed instanceof Cfg) {
			$rawAllowed = $rawAllowed->toArray();
		}
		$this->allowedIps = $this->normalizeAllowedIps((array)$rawAllowed);

		// Algo from cfg is meaningful only as a non-empty string; null or '' defers to the
		// secret file's algo, then DEFAULT_ALGO. See SUPPORTED_ALGOS for valid values.
		$cfgAlgo = '';
		$cfgAlgoRaw = $c->algo;
		if (\is_string($cfgAlgoRaw) && $cfgAlgoRaw !== '') {
			$cfgAlgo = \strtolower($cfgAlgoRaw);
			if (!\in_array($cfgAlgo, self::SUPPORTED_ALGOS, true)) {
				throw new WebhooksAuthConfigException(
					"WebhooksAuth: cfg.webhooks.algo must be one of " . \implode('|', self::SUPPORTED_ALGOS) . " (got '{$cfgAlgo}')."
				);
			}
		}

		if (!$this->enabled) {
			// The secret file is not read while disabled; algo and signatureHexLength still get
			// valid values, so nothing depends on enabled being checked first.
			$this->algo = $cfgAlgo !== '' ? $cfgAlgo : self::DEFAULT_ALGO;
			$this->signatureHexLength = self::SIGNATURE_HEX_LENGTH[$this->algo];
			return;
		}

		if ($this->ttlSeconds < 1) {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.ttl_seconds must be >= 1.');
		}
		if ($this->clockSkew < 0) {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.ttl_clock_skew_tolerance must be >= 0.');
		}
		if ($this->headerSignature === '' || $this->headerTimestamp === '' || $this->headerNonce === '') {
			throw new WebhooksAuthConfigException('WebhooksAuth: header_signature, header_timestamp, and header_nonce must be non-empty.');
		}

		$secretFile = (string)$c->secret_file;
		if ($secretFile === '') {
			throw new WebhooksAuthConfigException('WebhooksAuth: cfg.webhooks.secret_file is required when enabled.');
		}

		$loaded = $this->loadSecretFile($secretFile);
		$fileAlgo = $loaded['algo'];

		// Cfg wins over the secret file, so an app can override the algo the file names.
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
	 * Verify the current request and answer with a bool.
	 *
	 * For adapters that shape their own answer to a failure.
	 *
	 * Behavior:
	 * - Passes only when every check passes; the last check claims the request's nonce.
	 * - Turns only a failed check into false; any other exception passes through.
	 * - A failure sets the last failure reason, and a pass clears it.
	 * - Logs a failure when cfg webhooks.log_failures is true, and a pass when
	 *   log_successes is true, through the log service when it is registered.
	 *
	 * Typical usage:
	 *   if (!$this->app->webhooksAuth->verify()) {
	 *       $reason = $this->app->webhooksAuth->getLastFailureReason();
	 *   }
	 *
	 * @return bool  True when the request is authenticated.
	 * @see \CitOmni\Http\Service\WebhooksAuth::verifyOrThrow()  The checks and the reason each one fails with.
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
	 * Verify the current request and return its body, or throw.
	 *
	 * For adapters that want the verified body and let an exception end a failed request.
	 *
	 * Behavior:
	 * - Records the outcome and logs it as verify() does.
	 *
	 * Typical usage:
	 *   $body = $this->app->webhooksAuth->requireValid();
	 *
	 * @return string  Raw request body; may be empty.
	 * @throws \CitOmni\Http\Exception\WebhooksAuthVerificationException  When a check fails; its reason says which.
	 * @see \CitOmni\Http\Service\WebhooksAuth::verify()  The outcome, the recorded reason and the logging.
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
	 * Verify the current request and return its body, or end the request with an error page.
	 *
	 * A one-line guard for an adapter. With the default 404, every failure looks like a
	 * missing route, so a client cannot tell that the endpoint exists.
	 *
	 * Behavior:
	 * - Records the outcome and logs it as verify() does.
	 * - On failure, ends the request through httpError() with $failureStatus, without a title
	 *   or message of its own, and with a meta context of the reason, REMOTE_ADDR and whether
	 *   each auth header was present. An adapter that needs another answer uses verify() or
	 *   requireValid().
	 *
	 * Typical usage:
	 *   $body = $this->app->webhooksAuth->requireOrAbort();
	 *
	 * @param  int  $failureStatus  HTTP status of the error page.
	 * @return string  Raw request body; may be empty.
	 * @see \CitOmni\Http\Service\ErrorHandler::httpError()  How the error is logged and rendered.
	 */
	public function requireOrAbort(int $failureStatus = 404): string {
		if ($this->verify()) {
			return $this->getRawBody();
		}

		$this->app->errorHandler->httpError($failureStatus, [
			'meta' => $this->failureMeta(),
		]);

		// Unreachable: httpError() is typed never.
		return ''; // @codeCoverageIgnore
	}







	// ----------------------------------------------------------------
	// Inspection
	// ----------------------------------------------------------------

	/**
	 * Check whether webhook authentication is on.
	 *
	 * @return bool  Value of cfg webhooks.enabled.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Return the reason of the last failed verification.
	 *
	 * @return WebhooksAuthFailureReason|null  Null before any verification and after a passed one.
	 */
	public function getLastFailureReason(): ?WebhooksAuthFailureReason {
		return $this->lastFailureReason;
	}


	/**
	 * Return the reason of the last failed verification as its string value.
	 *
	 * For callers that need only the text, such as a log context or a problem response.
	 *
	 * @return string|null  A WebhooksAuthFailureReason value; null before any verification and after a passed one.
	 */
	public function getLastError(): ?string {
		return $this->lastFailureReason?->value;
	}


	/**
	 * Return the raw body of the current request.
	 *
	 * Behavior:
	 * - Reads php://input on the first call only and returns the same bytes afterwards, so an
	 *   adapter gets the verified body even where php://input can be read only once.
	 * - Returns an empty string when the body cannot be read.
	 *
	 * @return string
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
	 * Run every check on the current request, cheapest first, and claim its nonce.
	 *
	 * Rejects a request as early and as cheaply as possible, and claims a nonce only for a
	 * request that proved knowledge of the secret.
	 *
	 * Behavior:
	 * - Throws at the first failed check, with its reason, in this order:
	 *     1) Disabled: Webhook authentication is off.
	 *     2) IpNotAllowed: An allow-list is set, and the source IP matches no entry.
	 *     3) HeadersMissing: The signature, timestamp or nonce header is missing or empty.
	 *     4) SignatureMalformed: The signature is not hex of the algorithm's length; case is
	 *        ignored.
	 *     5) TimestampOutOfWindow: The timestamp, cast to int, is not positive, older than
	 *        ttl_seconds plus the clock skew, or more than the clock skew ahead.
	 *     6) SignatureMismatch: The HMAC of the canonical base string differs.
	 *     7) NonceRejected: The nonce ledger refuses to claim the nonce for ttl_seconds plus
	 *        twice the clock skew.
	 * - Writes the nonce ledger only when checks 1 to 6 pass.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\WebhooksAuthVerificationException  When a check fails.
	 * @see \CitOmni\Http\Service\WebhooksAuth::buildBaseString()  What the signature covers.
	 * @see \CitOmni\Http\Service\Nonce::checkAndStore()  When the ledger refuses a nonce.
	 */
	private function verifyOrThrow(): void {

		// -- 1. Master switch --------------------------------------------
		if (!$this->enabled) {
			throw new WebhooksAuthVerificationException(WebhooksAuthFailureReason::Disabled);
		}

		// -- 2. IP allow-list (cheap rejection before any crypto) --------
		// sourceIp() honors trusted proxies for public senders (CDN, load balancer) and
		// still matches private and internal peers.
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
		// secret: A garbage signature is rejected here without filesystem IO,
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
		// (ts + ttl + skew), a span of (ttl + 2*skew). Worst case: A sender
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
	 * Build the canonical base string that the signature covers.
	 *
	 * Behavior:
	 * - Without context binding: The timestamp, the nonce and the raw body, joined by ".".
	 * - With context binding: The timestamp, the nonce, the method in uppercase, the path, the
	 *   query and the lowercase hex SHA-256 of the raw body, joined by line feeds.
	 *     - Path and query are REQUEST_URI split at the first "?", each trimmed; the path
	 *       starts with "/" and is "/" when empty.
	 *     - The body hash is SHA-256 whatever the HMAC algorithm.
	 *
	 * Notes:
	 * - The body is used byte for byte, so a sender signs exactly the bytes it sends. A body
	 *   that is re-encoded on the way, such as reformatted JSON, no longer matches.
	 *
	 * @param  array<string,mixed>  $server  $_SERVER of the request.
	 * @param  string  $rawBody  Raw request body.
	 * @param  int  $ts  Timestamp from the header.
	 * @param  string  $nonce  Nonce from the header.
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

		// SHA-256 whatever the HMAC algo, like common public signing schemes, so senders have one rule to follow.
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
	 * Return the source IP that the allow-list is matched against.
	 *
	 * Webhook senders are often inside the perimeter, such as internal services, containers
	 * or a local cron job, where Request::ip() has no public address to report. This widens
	 * the match to them on purpose.
	 *
	 * Behavior:
	 * - Request::ip() when it returns an address; otherwise REMOTE_ADDR, trimmed, or an empty
	 *   string without one.
	 *
	 * @return string
	 * @see \CitOmni\Http\Service\Request::ip()  How the client IP is resolved behind trusted proxies.
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
	 * Check whether an IP matches an allow-list entry.
	 *
	 * Behavior:
	 * - An entry is an exact IPv4 or IPv6 address, or a CIDR range of either family.
	 * - An address never matches an entry of the other family.
	 * - An entry whose mask is not a decimal number, such as "10.0.0.0/" or "10.0.0.0/x",
	 *   matches nothing; whitespace around the mask is ignored.
	 * - An IP that is not a valid address matches nothing.
	 *
	 * @param  string  $ip  Source IP.
	 * @return bool
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

			// The mask must be a decimal bit count. (int) would read "" or "x" as 0,
			// and a /0 mask matches every address.
			$bits = \trim(\substr($entry, $slash + 1));
			if (!\ctype_digit($bits)) {
				continue;
			}
			if ($this->ipMatchesCidr($ipBin, \substr($entry, 0, $slash), (int)$bits)) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Check whether a packed address lies in a CIDR range.
	 *
	 * Behavior:
	 * - False for an invalid network address, a network of the other family, and a prefix
	 *   length outside 0 to the address width.
	 *
	 * @param  string  $ipBin  Candidate address as inet_pton() returns it.
	 * @param  string  $netStr  Network address as text.
	 * @param  int  $bits  Prefix length.
	 * @return bool
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
	 * Return the allow-list entries from cfg as trimmed, non-empty strings.
	 *
	 * Behavior:
	 * - Drops entries that are neither string nor int, and entries that are empty after
	 *   trimming, so the matching needs no per-entry type check.
	 *
	 * @param  array<array-key,mixed>  $list  Raw cfg webhooks.allowed_ips.
	 * @return list<string>
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
	 * Load the secret file and check what it returns.
	 *
	 * Behavior:
	 * - The file is a PHP file that is executed with require and must return an array:
	 *     - secret: A non-empty hex string. Required.
	 *     - algo: One of SUPPORTED_ALGOS, in any case. Optional; null counts as absent.
	 *     - Other keys are ignored.
	 * - Returns the secret and the algo in lowercase.
	 *
	 * Notes:
	 * - The file runs as code on every instance while enabled: Keep it free of side effects.
	 * - The secret's length is not checked, and its hex text, not the decoded bytes, is the HMAC
	 *   key. Use at least the digest length in hex digits: 64 for sha256, 128 for sha512.
	 *
	 * @param  string  $file  Path of the secret file.
	 * @return array{secret:string,algo:?string}  Algo is null when the file names none.
	 * @throws \CitOmni\Http\Exception\WebhooksAuthConfigException  When the file is missing or unreadable, returns no array, or holds an invalid secret or algo.
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
	 * Write one webhook event to the log file when the log service is registered.
	 *
	 * Behavior:
	 * - The context holds the reason, REMOTE_ADDR, method, URI, whether each auth header was
	 *   present, the algorithm and the context binding; never a header value.
	 * - Never throws: A logging outage must not change the verification result.
	 *
	 * @param  string  $category  Event category, such as webhook.fail.
	 * @param  string  $message  Human-readable message.
	 * @param  WebhooksAuthFailureReason|null  $reason  Failure reason, or null for a pass.
	 * @return void
	 * @see \CitOmni\Infrastructure\Service\Log::write()  Where and how the event is written.
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
			// Swallow: Logging must not affect verification semantics.
		}
	}


	/**
	 * Build the meta context of a failed request for the error log.
	 *
	 * @return array{webhook_guard_reason:?string,remote_addr:mixed,have_sig:bool,have_ts:bool,have_nonce:bool}  Never a header value.
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
