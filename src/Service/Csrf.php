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
 * Csrf: Service that issues CSRF tokens and verifies requests against layered CSRF checks.
 *
 * Gives forms and scripts one session-bound token, and adapters one layered verification of
 * state-changing requests. It decides whether a request passes; how a refusal is answered
 * belongs to the caller, and when to rotate the token, such as at login, belongs to the
 * authentication flow.
 *
 * Rules:
 * - Read cfg security.csrf only in init(). Report a wrong value there with CsrfException,
 *   never by switching a check off.
 * - Never create a session while verifying: A request without one has no token and fails.
 * - Never put a token in a log record, and write records only through the log service, never
 *   to a file of its own.
 * - Report every rejected request as exactly one CsrfFailureReason.
 */
class Csrf extends BaseService {

	/** Whether verification is on; when false, every request passes. */
	private bool $enabled;

	/** Name of the form field that carries the token. */
	private string $fieldName;

	/** Name of the request header that carries the token. */
	private string $headerName;

	/** Session key of the stored token. */
	private string $sessionKey;

	/** Token length in random bytes, at least 16. */
	private int $tokenBytes;

	/** @var list<string> Uppercase HTTP methods that require verification. */
	private array $protectMethods;

	/** Whether the Origin check, with its Referer fallback, runs. */
	private bool $originCheck;

	/** Whether an HTTPS request without Origin is checked by its Referer instead of failing. */
	private bool $refererFallbackOnHttps;

	/** Whether a plain HTTP request without Origin passes the Origin check. */
	private bool $allowMissingOriginOnHttp;

	/** @var list<string> Trusted full origins, as normalizeOrigin() returns them. */
	private array $trustedFullOrigins;

	/** @var list<string> Trusted bare hostnames, lowercased; each matches its host over http and https on any port. */
	private array $trustedHosts;

	/** Whether the Sec-Fetch-Site check runs. */
	private bool $fetchMetadataEnabled;

	/** Whether Sec-Fetch-Site same-site passes the Sec-Fetch-Site check. */
	private bool $fetchMetadataAllowSameSite;

	/** Whether tokens are issued masked with a new random pad each time, and submitted masked. */
	private bool $maskTokens;

	/** Whether verification failures are written to the log service. */
	private bool $logFailures;

	/** Log file name that failure records go to, as the log service's file argument. */
	private string $logChannel;

	/** The request's own normalized origin; null until getRequestOrigin() has built it. */
	private ?string $requestOrigin = null;






	// ----------------------------------------------------------------
	// Construction
	// ----------------------------------------------------------------

	/**
	 * Read and check cfg security.csrf once.
	 *
	 * Behavior:
	 * - protect_methods match in any letter case; empty entries are ignored.
	 * - A trusted_origins entry with "://" is a full origin: It matches exactly, after
	 *   lowercasing and dropping a default port. An entry without "://" is a hostname: It
	 *   matches an http or https origin with that host on any port.
	 * - A trusted_origins entry that cannot be used is ignored: A full origin without an http
	 *   or https scheme and a host, or a hostname with "/", ":" or whitespace.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\CsrfException  When a flag is not a boolean, protect_methods is not an array, field_name, header_name or session_key is empty, or token_bytes is below 16.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$c = $this->app->cfg->security->csrf;

		$this->enabled		= self::flag($c->enabled, 'enabled');
		$this->fieldName	= (string)$c->field_name;
		$this->headerName	= (string)$c->header_name;
		$this->sessionKey	= (string)$c->session_key;
		$this->tokenBytes	= (int)$c->token_bytes;
		$this->maskTokens	= self::flag($c->mask_tokens, 'mask_tokens');

		$this->originCheck	= self::flag($c->origin_check, 'origin_check');
		$this->refererFallbackOnHttps = self::flag($c->referer_fallback_on_https, 'referer_fallback_on_https');
		$this->allowMissingOriginOnHttp = self::flag($c->allow_missing_origin_on_http, 'allow_missing_origin_on_http');

		$this->logFailures	= self::flag($c->log_failures, 'log_failures');
		$this->logChannel	= (string)$c->log_channel;

		// An empty list arrives as a Cfg node. Any other non-array, null included, throws
		// rather than leave every method unprotected.
		$rawMethods = $c->protect_methods;
		if ($rawMethods instanceof Cfg) {
			$rawMethods = $rawMethods->toArray();
		}
		if (!\is_array($rawMethods)) {
			throw new CsrfException('Config security.csrf.protect_methods must be a list of HTTP methods.');
		}
		$this->protectMethods = [];
		foreach ($rawMethods as $method) {
			$method = \strtoupper(\trim((string)$method));
			if ($method !== '') {
				$this->protectMethods[] = $method;
			}
		}
		$this->protectMethods = \array_values(\array_unique($this->protectMethods));

		$rawOrigins = $c->trusted_origins;
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
				// An entry that normalizeOrigin() rejects could never match a request.
				$normalized = $this->normalizeOrigin($str);
				if ($normalized !== null) {
					$this->trustedFullOrigins[] = $normalized;
				}
			} else {
				// Bare hostname: Matches by host alone. Entries that look like a URL or contain
				// whitespace are dropped.
				$lower = \strtolower($str);
				if ($lower !== '' && \strpbrk($lower, '/:') === false && !\preg_match('/\s/', $lower)) {
					$this->trustedHosts[] = $lower;
				}
			}
		}

		$fm = $c->fetch_metadata;
		$this->fetchMetadataEnabled = self::flag($fm->enabled, 'fetch_metadata.enabled');
		$this->fetchMetadataAllowSameSite = self::flag($fm->allow_same_site, 'fetch_metadata.allow_same_site');

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


	/**
	 * Return a security.csrf flag, or throw when it is not a boolean.
	 *
	 * @param  mixed   $value  The cfg value.
	 * @param  string  $key    Path below security.csrf, for the message.
	 * @return bool
	 * @throws \CitOmni\Http\Exception\CsrfException  When the value is not a boolean.
	 */
	private static function flag(mixed $value, string $key): bool {
		if (!\is_bool($value)) {
			throw new CsrfException("Config security.csrf.{$key} must be a boolean.");
		}
		return $value;
	}







	// ----------------------------------------------------------------
	// Public API - Verification
	// ----------------------------------------------------------------

	/**
	 * Check the current request against every enabled CSRF check.
	 *
	 * Suits a form flow that answers a refusal with a message and a redirect. The checks run in
	 * this order, and the first one that rejects decides the reason:
	 * 1) Sec-Fetch-Site, when cfg security.csrf.fetch_metadata.enabled is true.
	 * 2) Origin, or Referer without Origin, when cfg security.csrf.origin_check is true.
	 * 3) The submitted token against the session token, always.
	 *
	 * Behavior:
	 * - True without any check when cfg security.csrf.enabled is false or the request method is
	 *   not in protect_methods.
	 * - True when every enabled check passes; false otherwise.
	 * - A request whose Origin is in cfg security.csrf.trusted_origins passes the Sec-Fetch-Site
	 *   check whatever the header says. The other checks still apply.
	 * - The token is read from the header named by header_name and, for POST without that
	 *   header, from the form field named by field_name. PUT, PATCH and DELETE must use the header.
	 * - On false, writes one failure record when cfg security.csrf.log_failures is true and the
	 *   log service is registered. The reason is not returned.
	 *
	 * Typical usage:
	 *   if (!$this->app->csrf->verify(['action' => 'profile_save'])) {
	 *       $this->app->flash->error('The form expired. Try again.');
	 *       $this->app->response->redirect($this->app->request->pathFromAppRoot(), 303);
	 *   }
	 *
	 * @param  array<string,mixed>  $context  Extra fields for the failure record, such as the action. Logged as given, so never put secrets in it; a key the record already has is dropped.
	 * @return bool  True when the request may proceed.
	 * @throws \CitOmni\Http\Exception\CsrfException  When the token check reads a session token that is neither null, '' nor token_bytes bytes in hex.
	 * @see \CitOmni\Http\Enum\CsrfFailureReason  The reasons a request is rejected for.
	 * @see \CitOmni\Http\Service\Csrf::requireValid()  The same checks, with the reason in an exception.
	 */
	public function verify(array $context = []): bool {
		if (!$this->enabled || !$this->isProtectedMethod()) {
			return true;
		}

		$reason = $this->runVerification();
		if ($reason === null) {
			return true;
		}

		$this->logFailure($reason, $context);
		return false;
	}


	/**
	 * Check the current request like verify(), and throw with the reason when it is rejected.
	 *
	 * Suits an endpoint or guard that answers a refusal from the reason, or lets it fail.
	 *
	 * Behavior:
	 * - Returns when verify() would return true.
	 * - Logs a rejection exactly as verify() does before it throws.
	 *
	 * Typical usage:
	 *   try {
	 *       $this->app->csrf->requireValid();
	 *   } catch (CsrfVerificationException $e) {
	 *       $this->app->response->jsonProblem('CSRF rejected', 403, $e->reason->value);
	 *   }
	 *
	 * @param  array<string,mixed>  $context  Extra fields for the failure record, such as the action. Logged as given, so never put secrets in it; a key the record already has is dropped.
	 * @return void
	 * @throws \CitOmni\Http\Exception\CsrfVerificationException  When an enabled check rejects the request; its reason names the check.
	 * @throws \CitOmni\Http\Exception\CsrfException  When the token check reads a session token that is neither null, '' nor token_bytes bytes in hex.
	 * @see \CitOmni\Http\Service\Csrf::verify()  Which checks run, in which order.
	 */
	public function requireValid(array $context = []): void {
		if (!$this->enabled || !$this->isProtectedMethod()) {
			return;
		}

		$reason = $this->runVerification();
		if ($reason === null) {
			return;
		}

		$this->logFailure($reason, $context);
		throw new CsrfVerificationException($reason);
	}








	// ----------------------------------------------------------------
	// Public API - Token output
	// ----------------------------------------------------------------

	/**
	 * Return the token to submit with a protected request.
	 *
	 * Issues the token that a form or script sends back; verification compares it with the
	 * session token.
	 *
	 * Behavior:
	 * - Stores a new session token when the session has none, or one that is not token_bytes
	 *   bytes in hex; storing starts the session when there is none.
	 * - With cfg security.csrf.mask_tokens true, every call returns a different base64 string
	 *   that verifies as the same session token, so the token never repeats in a compressed
	 *   response (BREACH). Otherwise it returns the session token as lowercase hex.
	 *
	 * Typical usage:
	 *   $token = $this->app->csrf->token();
	 *
	 * @return string
	 */
	public function token(): string {
		$hex = $this->ensureSessionToken();
		return $this->maskTokens ? $this->mask($hex) : $hex;
	}


	/**
	 * Return a hidden form input that carries a token under the configured field name.
	 *
	 * For a form that posts without a script. Like token(), it may store a session token.
	 *
	 * Behavior:
	 * - Name and value are already HTML-escaped: Output the string as it is.
	 *
	 * Typical usage:
	 *   $field = $this->app->csrf->htmlField();
	 *
	 * @return string  One hidden input element as HTML.
	 * @see \CitOmni\Http\Service\Csrf::token()  The token in the value, and when the session starts.
	 */
	public function htmlField(): string {
		$name	= \htmlspecialchars($this->fieldName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		$value	= \htmlspecialchars($this->token(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		return '<input type="hidden" name="' . $name . '" value="' . $value . '">';
	}


	/**
	 * Return the name of the form field that carries the token.
	 *
	 * @return string  Cfg security.csrf.field_name.
	 */
	public function fieldName(): string {
		return $this->fieldName;
	}


	/**
	 * Return the name of the request header that carries the token.
	 *
	 * @return string  Cfg security.csrf.header_name.
	 */
	public function headerName(): string {
		return $this->headerName;
	}








	// ----------------------------------------------------------------
	// Public API - Lifecycle
	// ----------------------------------------------------------------

	/**
	 * Replace the session token with a new one and return it.
	 *
	 * Call it at a privilege change, such as login or logout, so that a token known before the
	 * change, for example through a fixated session, is useless after it.
	 *
	 * Behavior:
	 * - Every token issued before the call fails verification afterwards, also in forms
	 *   rendered with it.
	 * - Starts the session when there is none.
	 *
	 * Typical usage:
	 *   $token = $this->app->csrf->rotate();
	 *
	 * @return string  The new token, in the format of token().
	 */
	public function rotate(): string {
		$hex = $this->generateToken();
		return $this->maskTokens ? $this->mask($hex) : $hex;
	}


	/**
	 * Remove the token from the session.
	 *
	 * Use it when the session ends or changes hands, so no token issued before stays valid.
	 *
	 * Behavior:
	 * - The next token() or htmlField() stores a new token.
	 * - Never creates a session: Without one there is no token to remove.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->app->session->remove($this->sessionKey);
	}







	// ----------------------------------------------------------------
	// Public API - Introspection
	// ----------------------------------------------------------------

	/**
	 * Check whether verification is on.
	 *
	 * @return bool  Cfg security.csrf.enabled.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Check whether the current request method requires verification.
	 *
	 * @return bool  True when the method is in cfg security.csrf.protect_methods, in any letter case.
	 */
	public function isProtectedMethod(): bool {
		return \in_array(\strtoupper($this->app->request->method()), $this->protectMethods, true);
	}








	// ----------------------------------------------------------------
	// Internal - Verification engine
	// ----------------------------------------------------------------

	/**
	 * Run the enabled checks in order and return the first rejection.
	 *
	 * @return CsrfFailureReason|null  The reason, or null when every enabled check passes.
	 * @throws \CitOmni\Http\Exception\CsrfException  When checkToken() finds a malformed session token.
	 * @see \CitOmni\Http\Service\Csrf::verify()  The order of the checks.
	 */
	private function runVerification(): ?CsrfFailureReason {
		// -- 1. Check Sec-Fetch-Site -------------------------------------
		// The header checks come first: They cost no session read.
		if ($this->fetchMetadataEnabled) {
			$reason = $this->checkFetchMetadata();
			if ($reason !== null) {
				return $reason;
			}
		}

		// -- 2. Check Origin or Referer ----------------------------------
		if ($this->originCheck) {
			$reason = $this->checkOrigin();
			if ($reason !== null) {
				return $reason;
			}
		}

		// -- 3. Check the token ------------------------------------------
		return $this->checkToken();
	}


	/**
	 * Check the Sec-Fetch-Site header.
	 *
	 * Behavior:
	 * - Passes when the header is absent or empty.
	 * - Passes same-origin and none, and same-site when cfg security.csrf.fetch_metadata.allow_same_site
	 *   is true. Values match in any letter case.
	 * - Passes any other value only when the Origin header is a trusted origin. Browsers send
	 *   cross-site for every request from another site, so this is what makes a trusted_origins
	 *   entry for another site usable.
	 *
	 * @return CsrfFailureReason|null  FetchMetadataRejected, or null when the check passes.
	 */
	private function checkFetchMetadata(): ?CsrfFailureReason {
		$site = $this->app->request->header('Sec-Fetch-Site');

		// The header is an extra defense, not a gate: Browsers without Fetch Metadata send none.
		if ($site === null || $site === '') {
			return null;
		}

		$site = \strtolower($site);

		if ($site === 'same-origin') {
			return null;
		}

		// none: The user started the request, by a bookmark or a typed URL.
		if ($site === 'none') {
			return null;
		}

		// Configurable, because an app may not trust every sibling subdomain.
		if ($site === 'same-site' && $this->fetchMetadataAllowSameSite) {
			return null;
		}

		// Other sites pass only from a trusted origin; the Origin and token checks still apply.
		if ($this->requestOriginIsTrusted()) {
			return null;
		}

		return CsrfFailureReason::FetchMetadataRejected;
	}


	/**
	 * Check that the request comes from its own origin or a trusted one.
	 *
	 * The browser sets Origin and Referer, so a page on another site cannot claim this
	 * site's origin.
	 *
	 * Behavior:
	 * - An origin matches when it is the request's own origin, compared by scheme, host and
	 *   port without the scheme's default port, or a trusted origin. Only http and https
	 *   origins can match.
	 * - An Origin header must match, or the result is OriginMismatch. An empty Origin and the
	 *   Origin "null" count as absent.
	 * - Without Origin on HTTPS: With cfg security.csrf.referer_fallback_on_https true, the
	 *   origin of the Referer must match; RefererMissing without a Referer, RefererMismatch when
	 *   it does not match. Otherwise OriginMissing.
	 * - Without Origin on plain HTTP: Passes when cfg security.csrf.allow_missing_origin_on_http
	 *   is true; otherwise OriginMissing.
	 *
	 * @return CsrfFailureReason|null  The reason, or null when the check passes.
	 * @see \CitOmni\Http\Service\Csrf::isTrustedOrigin()  When an origin is trusted.
	 * @see \CitOmni\Http\Service\Request::isHttps()  When a request counts as HTTPS.
	 */
	private function checkOrigin(): ?CsrfFailureReason {
		$origin = $this->app->request->header('Origin');

		// Privacy-sensitive navigations send the literal Origin "null".
		if ($origin !== null && $origin !== '' && \strtolower($origin) !== 'null') {
			$normalized = $this->normalizeOrigin($origin);
			if ($normalized === null) {
				return CsrfFailureReason::OriginMismatch;
			}
			return $this->originMatchesExpected($normalized)
				? null
				: CsrfFailureReason::OriginMismatch;
		}

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

			return CsrfFailureReason::OriginMissing;
		}

		if ($this->allowMissingOriginOnHttp) {
			return null;
		}

		return CsrfFailureReason::OriginMissing;
	}


	/**
	 * Check the submitted token against the session token.
	 *
	 * The one check that always runs: Only a page that received the token from this session
	 * can send it back.
	 *
	 * Behavior:
	 * - Reads the token from the header named by headerName; for POST without that header,
	 *   from the form field named by fieldName.
	 * - TokenMissing when no token is submitted or the session holds none.
	 * - TokenInvalid when the submitted token is not a string or not in the format that
	 *   cfg security.csrf.mask_tokens selects.
	 * - TokenMismatch when it differs from the session token. The comparison takes constant time.
	 * - Never creates a session, so a forged request does not create one either.
	 *
	 * @return CsrfFailureReason|null  The reason, or null when the tokens match.
	 * @throws \CitOmni\Http\Exception\CsrfException  When a string token is submitted and the session token is neither null, '' nor token_bytes bytes in hex.
	 */
	private function checkToken(): ?CsrfFailureReason {
		// -- 1. Read the submitted token ---------------------------------
		// PHP fills $_POST only for POST, so other methods must use the header.
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

		// Form input is untrusted: "_csrf[]=x" arrives as an array, which is no token.
		if (!\is_string($submitted)) {
			return CsrfFailureReason::TokenInvalid;
		}

		// -- 2. Read the session token -----------------------------------
		$sessionHex = $this->app->session->get($this->sessionKey);

		if ($sessionHex === null || (\is_string($sessionHex) && $sessionHex === '')) {
			return CsrfFailureReason::TokenMissing;
		}

		// A malformed session token is corrupted server state, not a client error.
		if (
			!\is_string($sessionHex)
			|| \strlen($sessionHex) !== $this->tokenBytes * 2
			|| !\ctype_xdigit($sessionHex)
		) {
			throw new CsrfException('Internal error: session contains malformed CSRF token.');
		}

		$sessionHex = \strtolower($sessionHex);

		// -- 3. Decode the submitted token -------------------------------
		if ($this->maskTokens) {
			$submittedHex = $this->unmask($submitted);
			if ($submittedHex === null) {
				return CsrfFailureReason::TokenInvalid;
			}
		} else {
			if (
				\strlen($submitted) !== $this->tokenBytes * 2
				|| !\ctype_xdigit($submitted)
			) {
				return CsrfFailureReason::TokenInvalid;
			}
			$submittedHex = \strtolower($submitted);
		}

		// -- 4. Compare in constant time ---------------------------------
		return \hash_equals($sessionHex, $submittedHex)
			? null
			: CsrfFailureReason::TokenMismatch;
	}







	// ----------------------------------------------------------------
	// Internal - Token management
	// ----------------------------------------------------------------

	/**
	 * Return the session token, and store a new one when the session holds no valid token.
	 *
	 * Keeps a valid token, so forms issued earlier in the session stay valid.
	 *
	 * Behavior:
	 * - A stored value that is not tokenBytes bytes in hex is replaced, not reported.
	 *
	 * @return string  The token as lowercase hex.
	 */
	private function ensureSessionToken(): string {
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
	 * Store a new random token in the session and return it.
	 *
	 * Creates the session secret that every issued token is derived from.
	 *
	 * Behavior:
	 * - Replaces any stored token, so every token issued before fails verification.
	 * - Starts the session when there is none.
	 *
	 * @return string  The token as lowercase hex.
	 */
	private function generateToken(): string {
		$hex = \bin2hex(\random_bytes($this->tokenBytes));
		$this->app->session->set($this->sessionKey, $hex);
		return $hex;
	}







	// ----------------------------------------------------------------
	// Internal - Token masking (BREACH mitigation)
	// ----------------------------------------------------------------

	/**
	 * Mask a token with a new random pad.
	 *
	 * @param  string  $rawHex  Token of tokenBytes bytes, as hex.
	 * @return string  Base64 of the pad followed by the pad XOR the token bytes; 2 * tokenBytes bytes before encoding.
	 * @throws \CitOmni\Http\Exception\CsrfException  When $rawHex is not valid hex.
	 * @see \CitOmni\Http\Service\Csrf::unmask()  The inverse.
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
	 * Recover the token from a masked token.
	 *
	 * @param  string  $masked  Masked token as submitted.
	 * @return string|null  The token as lowercase hex, or null when the input is not strict base64 of exactly 2 * tokenBytes bytes.
	 * @see \CitOmni\Http\Service\Csrf::mask()  The format.
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
	 * Return the origin of a URL or origin string in a comparable form.
	 *
	 * Behavior:
	 * - Lowercase scheme://host, followed by :port only when the port is not the scheme's
	 *   default (80 for http, 443 for https). Path, query and user info are dropped.
	 * - An IPv6 host keeps its brackets, as parse_url() returns it.
	 *
	 * @param  string  $url  Full URL, such as a Referer, or an origin.
	 * @return string|null  The origin, or null when the string has no scheme or host, or the scheme is not http or https.
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
	 * Check whether an origin is the request's own origin or a trusted one.
	 *
	 * @param  string  $origin  Origin as normalizeOrigin() returns it.
	 * @return bool
	 * @see \CitOmni\Http\Service\Csrf::isTrustedOrigin()  When an origin is trusted.
	 */
	private function originMatchesExpected(string $origin): bool {
		if ($origin === $this->getRequestOrigin()) {
			return true;
		}

		return $this->isTrustedOrigin($origin);
	}


	/**
	 * Check whether an origin is in cfg security.csrf.trusted_origins.
	 *
	 * Behavior:
	 * - True when the origin equals a trusted full origin, or its host equals a trusted
	 *   hostname, whatever its scheme and port.
	 *
	 * @param  string  $origin  Origin as normalizeOrigin() returns it.
	 * @return bool
	 */
	private function isTrustedOrigin(string $origin): bool {
		if (\in_array($origin, $this->trustedFullOrigins, true)) {
			return true;
		}

		if ($this->trustedHosts !== []) {
			$host = \parse_url($origin, \PHP_URL_HOST);
			if (\is_string($host) && \in_array(\strtolower($host), $this->trustedHosts, true)) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Check whether the request's Origin header names a trusted origin.
	 *
	 * @return bool  False also when Origin is absent, "null" or not an http or https origin.
	 * @see \CitOmni\Http\Service\Csrf::isTrustedOrigin()  When an origin is trusted.
	 */
	private function requestOriginIsTrusted(): bool {
		if ($this->trustedFullOrigins === [] && $this->trustedHosts === []) {
			return false;
		}

		$origin = $this->app->request->header('Origin');
		if ($origin === null || $origin === '' || \strtolower($origin) === 'null') {
			return false;
		}

		$normalized = $this->normalizeOrigin($origin);
		return $normalized !== null && $this->isTrustedOrigin($normalized);
	}


	/**
	 * Return the request's own origin, built once per instance.
	 *
	 * Behavior:
	 * - Lowercase scheme://host, followed by :port only when the port is not the scheme's
	 *   default and not 0.
	 *
	 * @return string
	 * @see \CitOmni\Http\Service\Request::host()  How the host is resolved, behind a proxy too.
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
	 * Write one failure record to the log service.
	 *
	 * Leaves a trail of rejected requests without changing their outcome.
	 *
	 * Behavior:
	 * - Writes nothing unless cfg security.csrf.log_failures is true and the log service is
	 *   registered.
	 * - Writes category csrf.failure with the reason value as the message, to the file named by
	 *   cfg security.csrf.log_channel.
	 * - The context is failureLogContext() with the caller's keys added; a caller key never
	 *   replaces a key of failureLogContext().
	 * - Never throws: A failed write is dropped.
	 *
	 * @param  CsrfFailureReason    $reason
	 * @param  array<string,mixed>  $context  Caller context, written as given.
	 * @return void
	 */
	private function logFailure(CsrfFailureReason $reason, array $context = []): void {
		if (!$this->logFailures || !$this->app->hasService('log')) {
			return;
		}

		try {
			$this->app->log->write(
				$this->logChannel,
				'csrf.failure',
				$reason->value,
				$this->failureLogContext() + $context
			);

		} catch (\Throwable) {
			// A failed log write must not replace the verification result.
		}
	}


	/**
	 * Return the request fields of a failure record.
	 *
	 * Behavior:
	 * - Holds the token's field and header names, never a token value.
	 * - path_raw falls back to the URI, and path_from_app_root to null, when the request service
	 *   lacks pathRaw() or pathFromAppRoot().
	 *
	 * @return array{method:string,uri:string,path_raw:string,path_from_app_root:?string,ip:string,referer:?string,user_agent:?string,origin:?string,sec_fetch_site:?string,content_type:?string,field_name:string,header_name:string}
	 */
	private function failureLogContext(): array {
		$pathRaw = \method_exists($this->app->request, 'pathRaw')
			? $this->app->request->pathRaw()
			: $this->app->request->uri();

		$pathFromAppRoot = \method_exists($this->app->request, 'pathFromAppRoot')
			? $this->app->request->pathFromAppRoot()
			: null;

		return [
			'method' => $this->app->request->method(),
			'uri' => $this->app->request->uri(),
			'path_raw' => $pathRaw,
			'path_from_app_root' => $pathFromAppRoot,
			'ip' => $this->app->request->ip(),
			'referer' => $this->app->request->referer(),
			'user_agent' => $this->app->request->getUserAgent(),
			'origin' => $this->app->request->header('Origin'),
			'sec_fetch_site' => $this->app->request->header('Sec-Fetch-Site'),
			'content_type' => $this->app->request->contentType(),
			'field_name' => $this->fieldName,
			'header_name' => $this->headerName,
		];
	}


}
