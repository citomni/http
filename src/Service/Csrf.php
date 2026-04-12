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

use CitOmni\Http\Enum\CsrfFailureReason;
use CitOmni\Http\Exception\CsrfException;
use CitOmni\Http\Exception\CsrfVerificationException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * Csrf: Layered CSRF protection for HTTP requests.
 *
 * Three independent defense layers, evaluated cheapest-first:
 * 1) Fetch Metadata (Sec-Fetch-Site header) - near-zero cost, browser-dependent.
 * 2) Origin/Referer validation - full origin (scheme+host+port) comparison against
 *    the request's own origin and any configured trusted origins.
 * 3) Token match - session-stored token vs. submitted token (header or form field).
 *
 * All enabled layers must pass. A failure in any layer short-circuits further checks.
 *
 * Behavior:
 * - verify() returns bool; requireValid() throws CsrfVerificationException.
 *   Both use the same internal engine. Choose based on flow:
 *   bool for redirect+flash, exception for fail-fast/API/guards.
 * - Requests with methods not in protect_methods pass unconditionally.
 * - When disabled via config, all verification methods return success.
 * - Token is created lazily on first token()/htmlField()/rotate() call.
 * - When mask_tokens is enabled, each token() call returns a differently
 *   masked representation of the same session token. This is correct and
 *   intentional - it prevents BREACH-style compression attacks.
 * - Logging goes through the log service when available and enabled.
 *   The service never writes directly to files.
 * - The session is started automatically when needed. Callers do not need
 *   to ensure session state before calling token(), htmlField(), rotate(),
 *   clear(), or verification methods.
 *
 * Notes:
 * - This service replaces the legacy security service for CSRF concerns.
 *   Auth-specific rotation (login/logout) is the auth package's responsibility:
 *   it reads cfg and calls rotate() explicitly.
 * - trusted_origins config accepts full origins (scheme://host:port, recommended)
 *   or bare hostnames (legacy/convenience - matches any scheme/port for that host).
 *   Full origins are compared strictly; bare hostnames match the host portion only.
 * - For PUT/PATCH/DELETE requests, the token must be submitted via the HTTP header
 *   (headerName). The form field fallback reads only $_POST, which PHP populates
 *   exclusively for POST requests. HTML forms natively support only GET/POST;
 *   method-override (POST with _method) is physically a POST and works with the
 *   form field. JavaScript clients should always use the header.
 * - Request path semantics are explicit:
 *   pathRaw() is transport-facing, while pathFromAppRoot() is app-facing.
 *   Redirect examples use pathFromAppRoot(). Logging records both forms.
 *
 * Config node: security.csrf (see init() for all keys).
 *
 * Typical usage:
 *   // HTML form flow:
 *   if (!$this->app->csrf->verify()) {
 *       $this->app->flash->error('Invalid or expired token.');
 *       $this->app->response->redirect($this->app->request->pathFromAppRoot());
 *   }
 *
 *   // API/fail-fast:
 *   $this->app->csrf->requireValid();
 *
 *   // Template: {{{ $csrfField() }}}
 *   // JS: fetch('/api', { headers: { 'X-CSRF-Token': token } })
 *
 * @throws CsrfException On invalid config at init time, or internal invariant violation.
 * @throws CsrfVerificationException On verification failure (requireValid() only).
 */
final class Csrf extends BaseService {

	/** @var bool Whether CSRF protection is globally enabled. */
	private bool $enabled;

	/** @var string Form field name for the CSRF token. */
	private string $fieldName;

	/** @var string HTTP header name for AJAX/API token submission. */
	private string $headerName;

	/** @var string Session key where the raw token hex is stored. */
	private string $sessionKey;

	/** @var int Number of random bytes for token generation (>= 16). */
	private int $tokenBytes;

	/** @var array<int, string> Uppercased HTTP methods that require CSRF verification. */
	private array $protectMethods;

	/** @var bool Whether to validate Origin/Referer headers. */
	private bool $originCheck;

	/** @var bool Fall back to Referer when Origin is absent on HTTPS. */
	private bool $refererFallbackOnHttps;

	/** @var bool Allow requests without Origin header on plain HTTP (dev, internal). */
	private bool $allowMissingOriginOnHttp;

	/**
	 * @var array<int, string> Pre-normalized full trusted origins (scheme://host[:port]).
	 *
	 * Populated from trusted_origins config entries that contain "://".
	 * Compared strictly against the normalized submitted origin.
	 */
	private array $trustedFullOrigins;

	/**
	 * @var array<int, string> Pre-lowercased bare trusted hostnames.
	 *
	 * Populated from trusted_origins config entries without "://".
	 * Matched against the host portion of the submitted origin only (less strict).
	 */
	private array $trustedHosts;

	/** @var bool Whether Sec-Fetch-Site checking is active. */
	private bool $fetchMetadataEnabled;

	/** @var bool Whether same-site requests pass fetch metadata check. */
	private bool $fetchMetadataAllowSameSite;

	/** @var bool Whether tokens are XOR-masked on output and unmasked on verify. */
	private bool $maskTokens;

	/** @var bool Whether failures are written to the log service. */
	private bool $logFailures;

	/** @var string Logical destination passed to the log service's file parameter. */
	private string $logChannel;

	/** @var ?string Cached normalized origin for the current request (scheme://host[:port]). */
	private ?string $requestOrigin = null;






	// ----------------------------------------------------------------
	// Construction
	// ----------------------------------------------------------------

	/**
	 * One-time initialization. Reads security.csrf config, pre-derives
	 * immutable scalars, validates constraints.
	 *
	 * Behavior:
	 * - All config keys have sensible defaults via ?? (baseline should provide them).
	 * - trusted_origins entries containing "://" are stored as full origins (strict match).
	 *   Entries without "://" are stored as bare hostnames (host-only match, less strict).
	 *   Both are lowercased and default-ports are stripped at init time.
	 * - protect_methods are uppercased at init time for case-insensitive matching.
	 * - Fails fast with CsrfException on invalid config values.
	 *
	 * @return void
	 * @throws CsrfException On invalid config (e.g. token_bytes < 16, empty field_name).
	 */
	protected function init(): void {
		$c = $this->app->cfg->security->csrf;

		$this->enabled		= (bool)($c->enabled ?? true);
		$this->fieldName	= (string)($c->field_name ?? '_csrf');
		$this->headerName	= (string)($c->header_name ?? 'X-CSRF-Token');
		$this->sessionKey	= (string)($c->session_key ?? '_csrf');
		$this->tokenBytes	= (int)($c->token_bytes ?? 32);
		$this->maskTokens	= (bool)($c->mask_tokens ?? true);

		$this->originCheck	= (bool)($c->origin_check ?? true);
		$this->refererFallbackOnHttps = (bool)($c->referer_fallback_on_https ?? true);
		$this->allowMissingOriginOnHttp = (bool)($c->allow_missing_origin_on_http ?? true);

		$this->logFailures	= (bool)($c->log_failures ?? true);
		$this->logChannel	= (string)($c->log_channel ?? 'security');

		// protect_methods - indexed array, normalize to uppercase, trim, deduplicate.
		$rawMethods = $c->protect_methods ?? ['POST', 'PUT', 'PATCH', 'DELETE'];
		if ($rawMethods instanceof Cfg) {
			$rawMethods = $rawMethods->toArray();
		}
		$this->protectMethods = [];
		foreach ((array)$rawMethods as $method) {
			$method = \strtoupper(\trim((string)$method));
			if ($method !== '') {
				$this->protectMethods[] = $method;
			}
		}
		$this->protectMethods = \array_values(\array_unique($this->protectMethods));

		// trusted_origins - split into full origins and bare hostnames.
		$rawOrigins = $c->trusted_origins ?? [];
		if ($rawOrigins instanceof Cfg) {
			$rawOrigins = $rawOrigins->toArray();
		}
		$this->trustedFullOrigins	= [];
		$this->trustedHosts			= [];
		foreach ((array)$rawOrigins as $entry) {
			$str = \trim((string)$entry);
			if ($str === '') {
				continue;
			}
			if (\str_contains($str, '://')) {
				// Full origin - normalize to lowercase, strip default ports.
				$normalized = $this->normalizeOrigin($str);
				if ($normalized !== null) {
					$this->trustedFullOrigins[] = $normalized;
				}
			} else {
				// Bare hostname - host-only match (less strict).
				// Reject entries that look like malformed URLs or contain invalid characters.
				$lower = \strtolower($str);
				if ($lower !== '' && \strpbrk($lower, '/:') === false && !\preg_match('/\s/', $lower)) {
					$this->trustedHosts[] = $lower;
				}
			}
		}

		// fetch_metadata - nested config node.
		if (isset($c->fetch_metadata)) {
			$fm = $c->fetch_metadata;
			$this->fetchMetadataEnabled = (bool)($fm->enabled ?? true);
			$this->fetchMetadataAllowSameSite = (bool)($fm->allow_same_site ?? true);
		} else {
			$this->fetchMetadataEnabled = true;
			$this->fetchMetadataAllowSameSite = true;
		}

		// Validate constraints.
		if ($this->fieldName === '') {
			throw new CsrfException('Config security.csrf.field_name must not be empty.');
		}
		if ($this->headerName === '') {
			throw new CsrfException('Config security.csrf.header_name must not be empty.');
		}
		if ($this->sessionKey === '') {
			throw new CsrfException('Config security.csrf.session_key must not be empty.');
		}
		if ($this->tokenBytes < 16) {
			throw new CsrfException('Config security.csrf.token_bytes must be >= 16 (128 bits minimum).');
		}
	}







	// ----------------------------------------------------------------
	// Public API - Verification
	// ----------------------------------------------------------------

	/**
	 * Verify the current request against all enabled CSRF defense layers.
	 *
	 * Returns true in three cases:
	 * 1) CSRF protection is disabled (cfg: enabled = false).
	 * 2) The request method is not in protect_methods (e.g. GET, HEAD, OPTIONS).
	 * 3) All enabled defense layers passed.
	 *
	 * Returns false when any enabled layer rejects the request.
	 * On failure, the reason is logged (if log_failures is enabled) but not
	 * exposed to the caller. Use requireValid() when the reason is needed.
	 *
	 * Typical usage:
	 *   if (!$this->app->csrf->verify()) {
	 *       $this->app->flash->error('Invalid or expired token.');
	 *       $this->app->response->redirect($this->app->request->pathFromAppRoot());
	 *   }
	 *
	 * @return bool True if the request is allowed, false if rejected.
	 */
	public function verify(): bool {
		if (!$this->enabled || !$this->isProtectedMethod()) {
			return true;
		}

		$reason = $this->runVerification();
		if ($reason === null) {
			return true;
		}

		$this->logFailure($reason);
		return false;
	}


	/**
	 * Verify the current request or throw on failure.
	 *
	 * Same verification engine as verify(), but throws CsrfVerificationException
	 * with the specific CsrfFailureReason on failure. Returns void on success
	 * (including when disabled or method is not protected).
	 *
	 * Typical usage:
	 *   // API endpoint - let error handler catch and render 403:
	 *   $this->app->csrf->requireValid();
	 *
	 *   // With explicit catch:
	 *   try {
	 *       $this->app->csrf->requireValid();
	 *   } catch (CsrfVerificationException $e) {
	 *       $this->app->response->jsonProblem('CSRF rejected', 403, $e->reason->value);
	 *   }
	 *
	 * @return void
	 * @throws CsrfVerificationException With the specific failure reason.
	 */
	public function requireValid(): void {
		if (!$this->enabled || !$this->isProtectedMethod()) {
			return;
		}

		$reason = $this->runVerification();
		if ($reason === null) {
			return;
		}

		$this->logFailure($reason);
		throw new CsrfVerificationException($reason);
	}







	// ----------------------------------------------------------------
	// Public API - Token output
	// ----------------------------------------------------------------

	/**
	 * Return the CSRF token for the current session.
	 *
	 * Creates the session token lazily on first call (starts the session if
	 * not already active). When mask_tokens is enabled, each call returns a
	 * different masked representation (new random mask). All masked variants
	 * resolve to the same underlying session token on verify.
	 *
	 * Typical usage:
	 *   // Meta tag for JS consumption:
	 *   <meta name="csrf-token" content="{{ $this->app->csrf->token() }}">
	 *
	 * @return string Masked or raw token string, depending on config.
	 */
	public function token(): string {
		$hex = $this->ensureSessionToken();
		return $this->maskTokens ? $this->mask($hex) : $hex;
	}


	/**
	 * Return a complete hidden HTML input element containing the CSRF token.
	 *
	 * Output is HTML-escaped. Use triple-brace syntax in templates: {{{ $csrfField() }}}.
	 *
	 * Starts the session if not already active.
	 *
	 * Typical usage:
	 *   {{{ $csrfField() }}}
	 *   // Outputs: <input type="hidden" name="_csrf" value="...">
	 *
	 * @return string Full <input type="hidden"> HTML string.
	 */
	public function htmlField(): string {
		$name	= \htmlspecialchars($this->fieldName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		$value	= \htmlspecialchars($this->token(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		return '<input type="hidden" name="' . $name . '" value="' . $value . '">';
	}


	/**
	 * Return the configured form field name.
	 *
	 * Useful for JS clients that need to know which field name to submit.
	 *
	 * @return string The field name (e.g. '_csrf').
	 */
	public function fieldName(): string {
		return $this->fieldName;
	}


	/**
	 * Return the configured HTTP header name.
	 *
	 * Useful for SPA/AJAX clients that submit tokens via headers.
	 *
	 * @return string The header name (e.g. 'X-CSRF-Token').
	 */
	public function headerName(): string {
		return $this->headerName;
	}








	// ----------------------------------------------------------------
	// Public API - Lifecycle
	// ----------------------------------------------------------------

	/**
	 * Generate a new token, replace the session token, and return it.
	 *
	 * The previous token is immediately invalidated. Any forms rendered with
	 * the old token will fail verification. Call this at privilege-level
	 * transitions (login, logout) to prevent session fixation attacks.
	 *
	 * Starts the session if not already active.
	 *
	 * The auth package is responsible for calling rotate() based on its own
	 * config (rotate_on_login, rotate_on_logout). This service has no
	 * awareness of auth semantics.
	 *
	 * Typical usage:
	 *   // After successful login:
	 *   $newToken = $this->app->csrf->rotate();
	 *
	 * @return string The new token (masked or raw, same format as token()).
	 */
	public function rotate(): string {
		$hex = $this->generateToken();
		return $this->maskTokens ? $this->mask($hex) : $hex;
	}


	/**
	 * Remove the CSRF token from the session.
	 *
	 * Use when destroying a session entirely. After clear(), subsequent
	 * token()/htmlField() calls will create a fresh token.
	 *
	 * Starts the session if not already active (required for remove to be meaningful).
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->ensureSession();
		$this->app->session->remove($this->sessionKey);
	}







	// ----------------------------------------------------------------
	// Public API - Introspection
	// ----------------------------------------------------------------

	/**
	 * Whether CSRF protection is globally enabled.
	 *
	 * @return bool True if enabled in config.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Whether the current HTTP method requires CSRF verification.
	 *
	 * Reads the method from the request service and checks against
	 * the protect_methods config list. Both sides are uppercased to
	 * ensure case-insensitive matching regardless of request-layer behavior.
	 *
	 * @return bool True if the current method is in protect_methods.
	 */
	public function isProtectedMethod(): bool {
		return \in_array(\strtoupper($this->app->request->method()), $this->protectMethods, true);
	}








	// ----------------------------------------------------------------
	// Internal - Verification engine
	// ----------------------------------------------------------------

	/**
	 * Run all enabled defense layers in order.
	 *
	 * @return ?CsrfFailureReason Null on success, reason enum on failure.
	 */
	private function runVerification(): ?CsrfFailureReason {
		// -- 1. Validate fetch metadata ------------------------------------
		// Layer 1: Fetch Metadata (cheapest - header read + string compare).
		if ($this->fetchMetadataEnabled) {
			$reason = $this->checkFetchMetadata();
			if ($reason !== null) {
				return $reason;
			}
		}

		// -- 2. Validate origin or referer ---------------------------------
		// Layer 2: Origin/Referer (header read + origin compare).
		if ($this->originCheck) {
			$reason = $this->checkOrigin();
			if ($reason !== null) {
				return $reason;
			}
		}

		// -- 3. Validate token ---------------------------------------------
		// Layer 3: Token (session read + decode + hash_equals).
		return $this->checkToken();
	}


	/**
	 * Layer 1: Validate Sec-Fetch-Site header.
	 *
	 * Browsers that do not send the header are silently skipped (null = pass).
	 * This is intentional: the header is additive defense, not a hard gate.
	 *
	 * @return ?CsrfFailureReason Null on pass, FetchMetadataRejected on fail.
	 */
	private function checkFetchMetadata(): ?CsrfFailureReason {
		$site = $this->app->request->header('Sec-Fetch-Site');

		// Header absent - browser doesn't support Fetch Metadata. Skip layer.
		if ($site === null || $site === '') {
			return null;
		}

		$site = \strtolower($site);

		// same-origin: always safe.
		if ($site === 'same-origin') {
			return null;
		}

		// none: direct navigation, bookmarks, typed URL. Safe for form submits.
		if ($site === 'none') {
			return null;
		}

		// same-site: configurable - safe for most setups, but some apps
		// serving multiple subdomains may want to restrict this.
		if ($site === 'same-site' && $this->fetchMetadataAllowSameSite) {
			return null;
		}

		return CsrfFailureReason::FetchMetadataRejected;
	}


	/**
	 * Layer 2: Validate Origin header (or Referer fallback).
	 *
	 * Compares full origins (scheme+host+port), not just hostnames.
	 * Default ports (443 for HTTPS, 80 for HTTP) are stripped before comparison.
	 *
	 * Behavior:
	 * - Origin present -> normalize and compare against request origin + trusted origins.
	 * - Origin "null" (privacy redirect) -> treated as absent.
	 * - Origin absent + HTTPS + referer fallback enabled -> extract origin from Referer, compare.
	 * - Origin absent + HTTP + allow_missing_origin_on_http -> pass (dev/internal policy).
	 * - Origin absent + no fallback applicable -> OriginMissing.
	 *
	 * @return ?CsrfFailureReason Null on pass, specific reason on fail.
	 */
	private function checkOrigin(): ?CsrfFailureReason {
		$origin = $this->app->request->header('Origin');

		// -- 1. Validate explicit Origin header -----------------------------
		// Origin present and not the literal string "null" (sent by privacy-sensitive navigations).
		if ($origin !== null && $origin !== '' && \strtolower($origin) !== 'null') {
			$normalized = $this->normalizeOrigin($origin);
			if ($normalized === null) {
				return CsrfFailureReason::OriginMismatch;
			}
			return $this->originMatchesExpected($normalized)
				? null
				: CsrfFailureReason::OriginMismatch;
		}

		// -- 2. Handle HTTPS requests without Origin ------------------------
		if ($this->app->request->isHttps()) {
			if ($this->refererFallbackOnHttps) {
				$referer = $this->app->request->header('Referer');

				if ($referer === null || $referer === '') {
					return CsrfFailureReason::RefererMissing;
				}

				$refOrigin = $this->normalizeOrigin($referer);
				if ($refOrigin === null) {
					return CsrfFailureReason::RefererMismatch;
				}
				return $this->originMatchesExpected($refOrigin)
					? null
					: CsrfFailureReason::RefererMismatch;
			}

			// HTTPS, no referer fallback - cannot verify origin.
			return CsrfFailureReason::OriginMissing;
		}

		// -- 3. Handle HTTP requests without Origin -------------------------
		if ($this->allowMissingOriginOnHttp) {
			return null;
		}

		return CsrfFailureReason::OriginMissing;
	}


	/**
	 * Layer 3: Validate submitted token against session token.
	 *
	 * Reads the submitted token from the header (headerName) first. If absent
	 * and the request method is POST, falls back to the form field (fieldName).
	 * For PUT/PATCH/DELETE, the header is the only accepted transport - PHP only
	 * populates $_POST for POST requests.
	 *
	 * Starts the session if not already active (required for token comparison).
	 *
	 * @return ?CsrfFailureReason Null on pass, specific reason on fail.
	 * @throws CsrfException If the session token exists but is malformed (invariant violation).
	 */
	private function checkToken(): ?CsrfFailureReason {
		$this->ensureSession();

		// -- 1. Load submitted token from header or POST --------------------
		// Read submitted token: header first (all methods), then POST form field
		// (POST only - PHP only populates $_POST for POST requests).
		$submitted = $this->app->request->header($this->headerName);
		if (
			($submitted === null || $submitted === '')
			&& \strtoupper($this->app->request->method()) === 'POST'
		) {
			$submitted = $this->app->request->post($this->fieldName);
		}

		if ($submitted === null || $submitted === '') {
			return CsrfFailureReason::TokenMissing;
		}

		$submitted = (string)$submitted;

		// -- 2. Load and validate session token -----------------------------
		// Read session token.
		$sessionHex = $this->app->session->get($this->sessionKey);

		if ($sessionHex === null || (\is_string($sessionHex) && $sessionHex === '')) {
			return CsrfFailureReason::TokenMissing;
		}

		// Session token exists but is malformed - this is framework-state corruption,
		// not a client-side submission error. Fail fast.
		if (
			!\is_string($sessionHex)
			|| \strlen($sessionHex) !== $this->tokenBytes * 2
			|| !\ctype_xdigit($sessionHex)
		) {
			throw new CsrfException('Internal error: session contains malformed CSRF token.');
		}

		$sessionHex = \strtolower($sessionHex);

		// -- 3. Normalize submitted token ----------------------------------
		// Derive comparable hex from submitted token.
		if ($this->maskTokens) {
			$submittedHex = $this->unmask($submitted);
			if ($submittedHex === null) {
				return CsrfFailureReason::TokenInvalid;
			}
		} else {
			// Unmasked mode: validate submitted value is well-formed hex.
			if (
				\strlen($submitted) !== $this->tokenBytes * 2
				|| !\ctype_xdigit($submitted)
			) {
				return CsrfFailureReason::TokenInvalid;
			}
			$submittedHex = \strtolower($submitted);
		}

		// -- 4. Compare in constant time -----------------------------------
		return \hash_equals($sessionHex, $submittedHex)
			? null
			: CsrfFailureReason::TokenMismatch;
	}







	// ----------------------------------------------------------------
	// Internal - Session management
	// ----------------------------------------------------------------

	/**
	 * Start the session if not already active.
	 *
	 * Delegates to the session service - never calls session_start() directly.
	 *
	 * @return void
	 */
	private function ensureSession(): void {
		if (!$this->app->session->isActive()) {
			$this->app->session->start();
		}
	}








	// ----------------------------------------------------------------
	// Internal - Token management
	// ----------------------------------------------------------------

	/**
	 * Ensure a token exists in the session. Returns the raw hex string (lowercase).
	 *
	 * Validates that the stored value is a hex string of the expected length.
	 * If missing, empty, wrong length, or non-hex: generates a fresh token.
	 *
	 * @return string Lowercase hex-encoded raw token.
	 */
	private function ensureSessionToken(): string {
		$this->ensureSession();

		$hex = $this->app->session->get($this->sessionKey);

		if (
			\is_string($hex)
			&& $hex !== ''
			&& \strlen($hex) === $this->tokenBytes * 2
			&& \ctype_xdigit($hex)
		) {
			return \strtolower($hex);
		}

		return $this->generateToken();
	}


	/**
	 * Generate a new random token, store in session, return lowercase hex.
	 *
	 * @return string Lowercase hex-encoded raw token.
	 */
	private function generateToken(): string {
		$this->ensureSession();
		$hex = \bin2hex(\random_bytes($this->tokenBytes));
		$this->app->session->set($this->sessionKey, $hex);
		return $hex;
	}







	// ----------------------------------------------------------------
	// Internal - Token masking (BREACH mitigation)
	// ----------------------------------------------------------------

	/**
	 * Mask a raw hex token with a random one-time pad.
	 *
	 * Output format: base64(mask || (mask XOR raw_bytes)).
	 * Length of mask equals tokenBytes. Total encoded payload is tokenBytes * 2.
	 *
	 * @param string $rawHex Lowercase hex-encoded raw token (from ensureSessionToken/generateToken).
	 * @return string Base64-encoded masked token.
	 * @throws CsrfException If the hex token cannot be decoded (should never happen with validated input).
	 */
	private function mask(string $rawHex): string {
		$raw = \hex2bin($rawHex);
		if ($raw === false) {
			throw new CsrfException('Internal error: session contains invalid hex token.');
		}
		$mask = \random_bytes($this->tokenBytes);
		return \base64_encode($mask . ($mask ^ $raw));
	}


	/**
	 * Unmask a submitted masked token back to raw hex.
	 *
	 * @param string $masked Base64-encoded masked token.
	 * @return ?string Lowercase hex-encoded raw token, or null on invalid format/length.
	 */
	private function unmask(string $masked): ?string {
		$decoded = \base64_decode($masked, true);
		if ($decoded === false) {
			return null;
		}

		$expectedLen = $this->tokenBytes * 2;
		if (\strlen($decoded) !== $expectedLen) {
			return null;
		}

		$mask	= \substr($decoded, 0, $this->tokenBytes);
		$xored	= \substr($decoded, $this->tokenBytes);
		return \strtolower(\bin2hex($mask ^ $xored));
	}








	// ----------------------------------------------------------------
	// Internal - Origin matching
	// ----------------------------------------------------------------

	/**
	 * Extract and normalize an origin from a URL or origin string.
	 *
	 * Returns lowercase scheme://host (with :port appended only if non-default).
	 * Accepts both full URLs (Referer) and bare origins (Origin header).
	 * Only http and https schemes are accepted - others return null.
	 *
	 * @param string $url URL or origin string.
	 * @return ?string Normalized origin, or null if unparseable or non-HTTP scheme.
	 */
	private function normalizeOrigin(string $url): ?string {
		$parts = \parse_url($url);
		if (!isset($parts['scheme'], $parts['host'])) {
			return null;
		}

		$scheme = \strtolower($parts['scheme']);
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}

		$host = \strtolower($parts['host']);
		$port = $parts['port'] ?? null;

		$origin = $scheme . '://' . $host;

		if ($port !== null) {
			$isDefault = ($scheme === 'https' && (int)$port === 443)
				|| ($scheme === 'http' && (int)$port === 80);
			if (!$isDefault) {
				$origin .= ':' . (int)$port;
			}
		}

		return $origin;
	}


	/**
	 * Check if a normalized origin matches the request's own origin or any trusted origin.
	 *
	 * Matching order:
	 * 1) Exact match against request's own normalized origin (scheme+host+port).
	 * 2) Exact match against trusted full origins (entries configured with "://").
	 * 3) Host-only match against trusted bare hostnames (entries configured without "://").
	 *
	 * @param string $origin Normalized origin (from normalizeOrigin()).
	 * @return bool True if the origin is trusted.
	 */
	private function originMatchesExpected(string $origin): bool {
		// Exact match against request's own origin.
		if ($origin === $this->getRequestOrigin()) {
			return true;
		}

		// Exact match against configured full origins.
		if (\in_array($origin, $this->trustedFullOrigins, true)) {
			return true;
		}

		// Host-only fallback for bare hostname trusted entries.
		if ($this->trustedHosts !== []) {
			$host = \parse_url($origin, \PHP_URL_HOST);
			if (\is_string($host) && \in_array(\strtolower($host), $this->trustedHosts, true)) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Build and cache the request's own normalized origin (scheme://host[:port]).
	 *
	 * Reads from the request service which is proxy-aware (trusted proxies).
	 *
	 * @return string Normalized origin string.
	 */
	private function getRequestOrigin(): string {
		if ($this->requestOrigin !== null) {
			return $this->requestOrigin;
		}

		$scheme = \strtolower($this->app->request->scheme());
		$host = \strtolower($this->app->request->host());
		$port = (int)$this->app->request->port();

		$origin = $scheme . '://' . $host;

		$isDefaultPort = ($scheme === 'https' && $port === 443)
			|| ($scheme === 'http' && $port === 80)
			|| $port === 0;

		if (!$isDefaultPort) {
			$origin .= ':' . $port;
		}

		$this->requestOrigin = $origin;
		return $origin;
	}







	// ----------------------------------------------------------------
	// Internal - Logging
	// ----------------------------------------------------------------

	/**
	 * Log a CSRF verification failure via the log service.
	 *
	 * Silently skipped if log_failures is disabled, the log service is
	 * unavailable, or the log service itself throws. Logging must never
	 * break the verification flow.
	 *
	 * @param CsrfFailureReason $reason The specific failure reason.
	 * @return void
	 */
	private function logFailure(CsrfFailureReason $reason): void {
		if (!$this->logFailures || !$this->app->hasService('log')) {
			return;
		}

		try {
			$pathRaw = \method_exists($this->app->request, 'pathRaw')
				? $this->app->request->pathRaw()
				: $this->app->request->uri();

			$pathFromAppRoot = \method_exists($this->app->request, 'pathFromAppRoot')
				? $this->app->request->pathFromAppRoot()
				: null;

			$this->app->log->write($this->logChannel, 'csrf.failure', $reason->value, [
				'method' => $this->app->request->method(),
				'path_raw' => $pathRaw,
				'path_from_app_root' => $pathFromAppRoot,
				'ip' => $this->app->request->ip(),
			]);
		} catch (\Throwable) {
			// Logging failure must not mask the CSRF verification result.
		}
	}

}
