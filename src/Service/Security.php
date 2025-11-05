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
 * Security: CSRF tokens, verification, and failure logging for HTTP flows.
 *
 * Responsibilities:
 * - Issue and cache a per-session CSRF token.
 *   1) Generate cryptographically strong random bytes (32 bytes, fixed).
 *   2) Hex-encode token for safe transport and HTML embedding (64 hex chars).
 *   3) Store in the session under a deterministic key (equal to the field name).
 * - Verify CSRF tokens by comparing the submitted value against the session token
 *   using a timing-safe equality check.
 * - Optionally log failed verifications with minimal, privacy-conscious context.
 *
 * Collaborators:
 * - $this->app->session  (read/write): Persist the session CSRF token.
 * - $this->app->request  (read): Read submitted token and request metadata.
 * - $this->app->cfg      (read): Feature toggles and field name.
 * - $this->app->log      (write): Append JSON entries for failed CSRF checks.
 *
 * Configuration (under $this->app->cfg->security):
 * - csrf_protection   (bool)   Enable/disable CSRF usage in helpers like csrfHiddenInput().
 *                               Default: true if unset.
 *                               Note: verifyCsrf() does not consult this flag; controllers
 *                               should gate verification based on cfg explicitly.
 * - csrf_field_name   (string) HTML form field name used for the token. Default: "csrf_token".
 *                               The session storage key is the same as this field name.
 *
 * Behavior:
 * - Token lifecycle:
 *   1) A single token is issued per session and reused across forms (low overhead).
 *   2) If the session has no token, a new 32-byte token is generated and stored atomically.
 *   3) Token remains stable until session rotation or explicit reset.
 * - Verification:
 *   1) verifyCsrf($submitted) always performs a timing-safe comparison against the session token.
 *   2) If the session token is missing or the submitted token is empty/mismatched, it fails.
 *   3) To disable verification globally, branch in controllers based on cfg->security->csrf_protection.
 * - Logging:
 *   1) On failure, log a compact JSON object (IP, user agent, timestamp, action, URI, extra meta).
 *   2) Avoid sensitive payloads; only high-level context is stored.
 *
 * Error handling:
 * - Public APIs return booleans/strings and do not throw for user mistakes.
 * - Unexpected internal errors bubble to the global error handler (fail fast).
 *
 * Performance & determinism:
 * - Minimal allocations: token generated once per session; hex string reused.
 * - Deterministic field/key naming: session key equals the configured field name.
 * - No I/O on the happy path; log write only on verification failure.
 *
 * Typical usage:
 *   // 1) Render form (controller GET)
 *   $csrf = $this->app->security->csrfToken(); // ensures token exists; returns 64-char hex
 *   echo '<form method="post" action="/contact">';
 *   echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
 *   // ... other inputs ...
 *   echo '</form>';
 *
 *   // 2) Handle submission (controller POST)
 *   if (!empty($this->app->cfg->security->csrf_protection)) {
 *     $ok = $this->app->security->verifyCsrf(
 *       (string)($this->app->request->post('csrf_token') ?? '')
 *     );
 *     if (!$ok) {
 *       $this->app->security->logFailedCsrf('contact_submit', [
 *         'email' => (string)($this->app->request->post('email') ?? ''),
 *       ]);
 *       http_response_code(403);
 *       return;
 *     }
 *   }
 *   // Proceed with validated request...
 *
 * Examples:
 *   // Example A: Issue token explicitly (e.g., JSON bootstrap)
 *   $token = $this->app->security->csrfToken();
 *   $this->app->response->json(['csrf_token' => $token]);
 *
 *   // Example B: Controller-gated verification
 *   if (!empty($this->app->cfg->security->csrf_protection)) {
 *     $ok = $this->app->security->verifyCsrf('submitted-value');
 *   } else {
 *     $ok = true; // disabled by configuration
 *   }
 *
 *   // Example C: Log structured failure with a custom action label
 *   $ok = $this->app->security->verifyCsrf('invalid');
 *   if (!$ok) {
 *     $this->app->security->logFailedCsrf('crm_msg', ['email' => 'alice@example.com']);
 *   }
 *
 * Notes:
 * - Keep your HTML hidden input name in sync with cfg->security->csrf_field_name.
 * - For multi-tab SPAs, a stable per-session token yields lowest friction and overhead.
 * - Consider regenerating the CSRF token when rotating session IDs.
 * - Do not include sensitive payloads in failure logs; prefer minimal, actionable context.
 *
 * Typical keys (effective defaults):
 *   $field = $this->app->cfg->security->csrf_field_name ?? 'csrf_token';
 *   // Session key equals $field; token is fixed at 32 random bytes (64 hex chars).
 *
 * @method string csrfToken()
 *     Return the current session CSRF token, creating it if missing. Hex string, 64 chars (32 random bytes).
 *
 * @method bool verifyCsrf(string $submittedToken)
 *     Timing-safe comparison of the submitted token against the session token.
 *     Does not consult cfg->security->csrf_protection; controllers should gate usage.
 *
 * @method void logFailedCsrf(string $action, array $extra = [])
 *     Append a compact JSON entry to "csrf_failures.jsonl" with IP, user agent, timestamp, action, URI, and extra meta.
 */
class Security extends BaseService {

	/** Default CSRF field name (can be overridden via cfg->security->csrf_field_name). */
	private const DEFAULT_CSRF_FIELD = 'csrf_token';


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
	 * Get or create the CSRF token for the current session.
	 *
	 * Ensures the session is started (if the session service exposes start()).
	 *
	 * @return string Hex-encoded CSRF token.
	 *
	 * @example Obtain token in controller logic
	 *  $token = $this->app->security->csrfToken();
	 *  // pass to a custom view or JSON response if needed
	 */
	public function csrfToken(): string {
		
		// Ensure the session is started before reading/writing
		$this->app->session->start();
		
		$field = $this->csrfFieldName();
		
		if (!$this->app->session->has($field)) {
			$this->app->session->set($field, \bin2hex(\random_bytes(32)));
		}
		
		return (string)$this->app->session->get($field);
	}


	/**
	 * Backwards-compatible alias for csrfToken().
	 *
	 * @return string Hex-encoded CSRF token.
	 *
	 * @example Legacy usage
	 *  $token = $this->app->security->generateCsrf();
	 */
	public function generateCsrf(): string {
		return $this->csrfToken();
	}


	/**
	 * Verify a provided token against the session token using constant-time compare.
	 *
	 * @param ?string $token Token received from client (e.g., from POST). Null/empty fails fast.
	 * @return bool True when valid; false otherwise.
	 *
	 * @example Typical POST handler
	 *  $field = $this->app->security->csrfFieldName();
	 *  $token = $_POST[$field] ?? null;
	 *  if (!$this->app->security->verifyCsrf($token)) {
	 *  	$this->app->security->logFailedCsrf('account.update');
	 *  	throw new \CitOmni\Http\Exception\HttpForbiddenException('Invalid CSRF token');
	 *  }
	 */
	public function verifyCsrf(?string $token): bool {
		$enabled = (bool)($this->app->cfg->security->csrf_protection ?? true);
		if (!$enabled) {
			return true;
		}
		
		// Empty/absent token is an immediate failure; avoids notices and pointless comparisons.
		if ($token === null || $token === '') {
			return false;
		}
		$field  = $this->csrfFieldName();
		$stored = $this->app->session->has($field)
			? (string)$this->app->session->get($field)
			: '';

		return ($stored !== '') && \hash_equals($stored, $token);
	}


	/**
	 * Clear the CSRF token from the session (e.g., on logout or session reset).
	 *
	 * @return void
	 *
	 * @example On logout
	 *  $this->app->security->clearCsrf();
	 */
	public function clearCsrf(): void {
		$this->app->session->remove($this->csrfFieldName());
	}


	/**
	 * Return a hidden input for use inside HTML forms, or an empty string when
	 * CSRF protection is disabled via cfg->security->csrf_protection.
	 *
	 * Uses cfg->security->csrf_field_name when provided; otherwise "csrf_token".
	 * Output is HTML-escaped for attribute safety.
	 *
	 * @return string HTML: <input type="hidden" name="..." value="..."> or ''.
	 *
	 * @example In a PHP template (raw echo)
	 *  echo $this->app->security->csrfHiddenInput();
	 *
	 * @example In LiteView template (triple braces to avoid escaping)
	 *  {{{ csrfField() }}}
	 */
	public function csrfHiddenInput(): string {
		$enabled = (bool)($this->app->cfg->security->csrf_protection ?? true);
		if (!$enabled) {
			return '';
		}

		$name  = $this->csrfFieldName();
		$value = $this->csrfToken();

		// Attribute-safe encoding
		$ename  = \htmlspecialchars($name, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		$evalue = \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		return '<input type="hidden" name="' . $ename . '" value="' . $evalue . '">';
	}


	/**
	 * Get the configured CSRF field name.
	 *
	 * Defaults to "csrf_token" unless overridden via cfg->security->csrf_field_name.
	 *
	 * @return string Field name to use in forms and POST extraction.
	 *
	 * @example Extract from POST in a controller
	 *  $field = $this->app->security->csrfFieldName();
	 *  $token = (string)($_POST[$field] ?? '');
	 */
	public function csrfFieldName(): string {
		$field = $this->app->cfg->security->csrf_field_name ?? null;
		if (\is_string($field) && $field !== '') {
			return $field;
		}
		return self::DEFAULT_CSRF_FIELD;
	}


	/**
	 * Read the CSRF token from the current request using the configured field name.
	 *
	 * Looks up the POST parameter matching {@see csrfFieldName()} and returns its value.
	 * This method does not perform any validation or normalization beyond a string cast.
	 *
	 * Notes:
	 * - Returns an empty string if the field is missing.
	 * - Respects custom CSRF field names set in config (security.csrf_field_name).
	 * - Use {@see verifyCsrf()} to actually validate the token.
	 *
	 * Example:
	 *  $csrf = $this->app->security->readCsrfFromRequest();
	 *  if (!$this->app->security->verifyCsrf($csrf)) {
	 *      // handle invalid/missing token...
	 *  }
	 *
	 * @return string CSRF token from POST, or '' if absent.
	 */
	public function readCsrfFromRequest(): string {
		return (string)($this->app->request->post($this->csrfFieldName()) ?? '');
	}


	/**
	 * Record a failed CSRF verification attempt to application logs (or PHP error log).
	 *
	 * Behavior:
	 * - Collects request context (IP, user agent, method, URI, referer) to aid triage.
	 * - Merges caller-supplied $extra over defaults (caller values take precedence).
	 * - Uses Infrastructure log service when available; otherwise falls back to error_log().
	 *
	 * Notes:
	 * - Keep payload ASCII-safe and reasonably small; avoid sensitive PII.
	 *
	 * Typical usage:
	 *   $this->app->security->logFailedCsrf('post:contact', ['form' => 'contact']);
	 *
	 * @param string $action Short, machine-friendly label for where the failure occurred.
	 * @param array  $extra  Optional extra context; keys here override default keys on conflict.
	 * @return void
	 */
	public function logFailedCsrf(string $action, array $extra = []): void {
		// Gather baseline request context for debugging/triage.
		$logData = [
			'ip'             => $this->app->request->ip(),
			'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
			'uri'            => $_SERVER['REQUEST_URI'] ?? 'Unknown',
			'referer'        => $_SERVER['HTTP_REFERER'] ?? null,
			'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
			'timestamp'      => \date('Y-m-d H:i:s'),
			'action'         => $action,
			'csrf_field'     => $this->csrfFieldName(),
		];

		// Caller-supplied data wins on key collisions.
		$logData = \array_merge($logData, $extra);

		if ($this->app->hasService('log') && $this->app->hasPackage('citomni/infrastructure')) {
			$this->app->log->write(
				'csrf_failures.jsonl',
				'warning',
				'Failed CSRF verification',
				$logData
			);
		} else {
			// Minimal fallback with safe encoding; suppress warnings in shared-hosting scenarios.
			@\error_log('CSRF failure: ' . \json_encode($logData, \JSON_UNESCAPED_SLASHES));
		}
	}

}
