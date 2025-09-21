<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Http\Service;

use CitOmni\Kernel\Service\BaseService;


/**
 * Cookie: Security-first, deterministic cookie API with cfg-driven defaults.
 *
 * Responsibilities:
 * - Provide a thin, explicit wrapper around setcookie()/$_COOKIE.
 * - Enforce modern security flags (SameSite, Secure, HttpOnly) with safe defaults.
 * - Allow app-wide defaults via cfg->cookie.* and per-call overrides via $options.
 * - Convert friendly 'ttl' to absolute 'expires' automatically.
 *
 * Collaborators:
 * - $this->app->cfg->cookie / http (read-only wrappers; current implementation reads via toArray()).
 * - $this->app->request->isHttps() for Secure inference.
 *
 * Configuration (read during init; last-wins with service $options):
 * - cookie.secure   (bool)   If set, use as-is. Else inferred:
 *                             https base_url OR request->isHttps() ⇒ true.
 * - cookie.samesite (string) "Lax" (default), "Strict", or "None".
 *                             Invariant: "None" requires Secure=true (enforced at init/set/delete).
 * - cookie.path     (string) Default "/" if unset.
 * - cookie.domain   (string|null) If null, inferred from absolute http.base_url host (no leading dot needed).
 *                                 When provided, the value is used as-is.
 * - cookie.httponly (bool)   Default: true.
 *
 * Public API:
 * - set(string $name, string $value, array $options = []): bool
 *     Supported $options (caller overrides cfg defaults):
 *       ttl       (int)    Lifetime seconds; coerced to >= 0; converted to expires = time() + ttl.
 *       expires   (int)    Unix timestamp (overrides ttl).
 *       path      (string)
 *       domain    (string|null) null removes 'domain' in the setcookie() array.
 *       secure    (bool)
 *       httponly  (bool)
 *       samesite  (string: "Lax"|"Strict"|"None")
 *     Returns false if PHP refuses to set the cookie (e.g., headers already sent).
 *     Note: set(): updates $_COOKIE[$name] for the current request iff PHP accepted the cookie and the 
 *           computed scope (domain/path) applies to the current request; otherwise leaves $_COOKIE unchanged.
 *
 * - get(string $name, ?string $default = null): ?string
 *     Return the cookie value as string, or $default when absent.
 *
 * - has(string $name): bool
 *     True if the cookie key is present in the current request.
 *
 * - delete(string $name, array $options = []): bool
 *     Delete by setting an expiry in the past. You may pass path/domain/etc. to match scope.
 *     Returns false if PHP refuses the deletion (e.g., headers already sent).
 *     Note: delete() removes the key from $_COOKIE locally (current-request view).
 *
 * - defaults(): array<string,mixed>
 *     Return the effective merged defaults after initialization (useful for tests/diagnostics).
 *
 * Behavior & invariants:
 * - SameSite=None requires Secure=true across init(), set() and delete(); violations throw.
 * - Cookie names are validated against the RFC6265 token charset; invalid names throw \InvalidArgumentException.
 * - Defaults are built once at init from cfg + service $options (last wins).
 * - set()/delete() merge caller $options over defaults deterministically.
 * - Local view semantics:
 *   - set(): updates $_COOKIE[$name] for the current request iff PHP accepted the cookie
 *     and the computed scope (domain/path) applies to the current request; otherwise leaves $_COOKIE unchanged.
 *   - delete(): unsets $_COOKIE[$name] locally regardless of setcookie()'s return value.
 *
 * Error handling:
 * - User mistakes (missing cookie on get/has) return null/false; no exceptions.
 * - set()/delete() return false if PHP refuses the operation (e.g., headers already sent).
 * - Invalid names or SameSite=None without Secure=true throw \InvalidArgumentException or \RuntimeException.
 * - Misconfiguration may be detected at init() time (e.g., samesite=None + secure!=true).
 * - Unexpected runtime errors bubble to the global error handler (fail fast).
 *
 * Performance:
 * - Deterministic option merging; no unnecessary serialization or allocations.
 * - Domain inference from http.base_url happens once at init.
 *
 * Typical usage:
 *   // 1) Session cookie (until browser close)
 *   $this->app->cookie->set('sid', $token);
 *
 *   // 2) One-hour cookie with strict policy
 *   $this->app->cookie->set('sid', $token, ['ttl' => 3600, 'samesite' => 'Strict', 'httponly' => true]);
 *
 *   // 3) Cross-site (iframe/SaaS) cookie: must be Secure + None
 *   $this->app->cookie->set('auth', $jwt, ['ttl' => 7200, 'samesite' => 'None', 'secure' => true, 'httponly' => true]);
 *
 *   // 4) Read with default fallback
 *   $sid = $this->app->cookie->get('sid', '');
 *
 *   // 5) Delete with path/domain to match scope
 *   $this->app->cookie->delete('sid', ['path' => '/', 'domain' => 'example.com']);
 *
 * Notes:
 * - Prefer 'ttl' for relative lifetimes; use 'expires' only when you have a fixed timestamp.
 * - Negative ttl values are coerced to 0.
 * - Ensure http.base_url is absolute if you expect domain inference.
 * - When behind proxies, ensure https/base_url is correct, otherwise Secure cookies may not stick.
 * - Avoid storing secrets without signing/encryption; cookies are client-held.
 */
class Cookie extends BaseService {



/*
 *---------------------------------------------------------------
 * CONFIGURATION DEFAULTS & BOOTSTRAP
 *---------------------------------------------------------------
 * PURPOSE
 *   Build effective cookie defaults once per request (cfg + options).
 *
 * NOTES
 *   - Enforces invariants early (e.g., SameSite=None ⇒ Secure=true).
 *   - Domain can be inferred from absolute http.base_url.
 */

	/** @var array<string,mixed> */
	private array $defaults = [];


	/**
	 * Initialize default cookie options from config and runtime environment.
	 *
	 * Determines Secure/SameSite/Path/Domain defaults in a deterministic, low-overhead way.
	 *
	 * Behavior:
	 * - Secure:
	 *   1) Respect cookie.secure if explicitly set (bool).
	 *   2) Else infer from http.base_url (https), CITOMNI_PUBLIC_ROOT_URL (https), or current request (https).
	 * - SameSite:
	 *   - Normalize to 'Lax'|'Strict'|'None'; enforce Secure=true when SameSite=None.
	 * - Domain:
	 *   1) Use cookie.domain if provided ('' => null).
	 *   2) Else derive host from http.base_url; if empty, try CITOMNI_PUBLIC_ROOT_URL.
	 *   3) Do NOT set domain for IP addresses or 'localhost' (browser restrictions).
	 * - Merge:
	 *   - Merge constructor $options into defaults (last wins).
	 *
	 * Notes:
	 * - Path is normalized to a leading slash and no trailing slash (except root '/').
	 * - Keep this method fast; it runs once per request (constructor init path).
	 *
	 * Typical usage:
	 *   // Called automatically by BaseService::__construct() via init()
	 *
	 * @return void
	 * @throws \RuntimeException If SameSite=None is used without Secure=true.
	 */
	protected function init(): void {
		$cfgCookie = isset($this->app->cfg->cookie) ? $this->app->cfg->cookie->toArray() : [];
		$cfgHttp   = isset($this->app->cfg->http)   ? $this->app->cfg->http->toArray()   : [];

		// 1) Determine Secure
		// 1.1) Respect explicit cookie.secure when present.
		$secure = null;
		if (\array_key_exists('secure', $cfgCookie) && \is_bool($cfgCookie['secure'])) {
			$secure = $cfgCookie['secure'];
		} else {
			
			// 1.2) Infer from base URLs or current request (https) - whether cookies should be marked Secure (https-only)?			
			$baseUrl     = (string)($cfgHttp['base_url'] ?? '');                           // App-level base URL (may be empty or relative)
			$fromBaseUrl = ($baseUrl !== '' && \preg_match('#^https://#i', $baseUrl) === 1); // True only if absolute https://

			$fromRootUrl = (\defined('CITOMNI_PUBLIC_ROOT_URL')                              // Prod override constant present...
				&& \preg_match('#^https://#i', (string)\CITOMNI_PUBLIC_ROOT_URL) === 1);    // ...and explicitly https://

			$fromRequest = $this->app->hasService('request')
				? $this->app->request->isHttps()                                            // Proxy-aware check (preferrable)
				: ((!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off')
					|| (int)($_SERVER['SERVER_PORT'] ?? 0) === 443);                        // Fallback: raw server vars / port 443

			$secure = $fromBaseUrl || $fromRootUrl || $fromRequest;                         // Any https signal => set Secure
		}

		// 2) Determine SameSite
		$samesite = $this->normalizeSameSite($cfgCookie['samesite'] ?? 'Lax');
		if ($samesite === 'None' && $secure !== true) {
			// Browsers require Secure when SameSite=None.
			throw new \RuntimeException('cookie.samesite=None requires cookie.secure=true');
		}

		// 3) Path & HttpOnly defaults
		$path     = (string)($cfgCookie['path'] ?? '/');
		$httponly = \array_key_exists('httponly', $cfgCookie) ? (bool)$cfgCookie['httponly'] : true;


		// 4) Determine Domain (skip for IP/localhost)
		// 4.1 Respect explicit cookie.domain if set ('' => null).
		// 4.2 Else derive from http.base_url, else CITOMNI_PUBLIC_ROOT_URL.
		// 4.3 hostFromBaseUrl() returns null for IP/localhost to avoid invalid domain scoping.
		$domain = $cfgCookie['domain'] ?? null;
		
		// Resolve cookie "domain":
		if ($domain === null || $domain === '') {
			
			// Try http.base_url first (preferred source in cfg)...
			$domainCandidate = $this->hostFromBaseUrl((string)($cfgHttp['base_url'] ?? ''));
			
			// ...fallback to CITOMNI_PUBLIC_ROOT_URL if base_url gave nothing.
			if ($domainCandidate === null && \defined('CITOMNI_PUBLIC_ROOT_URL')) {
				$domainCandidate = $this->hostFromBaseUrl((string)\CITOMNI_PUBLIC_ROOT_URL);
			}
			
			$domain = $domainCandidate; // May be null (e.g., IP/localhost) -> scope to current host
			
		} else {
			$domain = (string)$domain;   // Normalize explicit config value to string
		}


		// 5) Final defaults
		$this->defaults = [
			'expires'  => 0,                                  // session cookie by default
			'path'     => $path,
			'domain'   => ($domain === '' ? null : $domain),  // normalize empty string to null
			'secure'   => $secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		];

		// 6) Merge service-level $options (provided via Service definition) over defaults.
		if (!empty($this->options)) {
			$this->defaults = $this->mergeAssoc(
				$this->defaults,
				$this->normalizeOptions($this->options, true)
			);
		}
	}






/*
 *---------------------------------------------------------------
 * PUBLIC API — READ/WRITE/DELETE
 *---------------------------------------------------------------
 * PURPOSE
 *   Minimal, explicit cookie operations with deterministic behavior.
 *
 * NOTES
 *   - set()/delete() return false if headers already sent.
 *   - set() mirrors into $_COOKIE if scope matches this request.
 */ 

	/**
	 * Set a cookie with deterministic merging and safe defaults.
	 *
	 * Behavior:
	 * - Merges $options over precomputed defaults.
	 * - Enforces: SameSite=None requires Secure=true.
	 * - Calls setcookie(); if accepted and scope matches this request, mirrors into $_COOKIE.
	 *
	 * @param string              $name     RFC6265-token cookie name (validated).
	 * @param string              $value    Raw cookie value (no serialization performed).
	 * @param array<string,mixed> $options  ttl|expires|path|domain|secure|httponly|samesite
	 * @return bool True if PHP accepted the cookie; false if headers already sent or refused.
	 * @throws \InvalidArgumentException For invalid cookie name.
	 * @throws \RuntimeException For SameSite=None without Secure=true.
	 */
	public function set(string $name, string $value, array $options = []): bool {
		
		// 1) Validate name early (fail fast on invalid RFC6265 token).
		$this->assertValidName($name);

		// 2) Compute final option set:
		//    - Start from precomputed service defaults (cfg + service options).
		//    - Overlay caller options (last wins).
		//    - normalizeOptions() also handles ttl -> expires, casing, and types.
		$final = $this->mergeAssoc($this->defaults, $this->normalizeOptions($options, false));

		// 3) Enforce invariant: SameSite=None requires Secure=true.
		//    (Checked both here and in delete(); also validated at init() for cfg.)
		$samesite = $this->normalizeSameSite($final['samesite'] ?? 'Lax');
		if ($samesite === 'None' && empty($final['secure'])) {
			throw new \RuntimeException('SameSite=None requires Secure=true');
		}

		// 4) Convert normalized options to the exact array shape setcookie() expects.
		//    Domain is omitted for host-only cookies (null/empty).
		$opts = $this->buildCookieOptions($final);

		// 5) Emit the Set-Cookie header.
		//    Returns false if headers were already sent or PHP rejected the header.
		$ok = \setcookie($name, $value, $opts);

		// 6) Local view: if the browser would see this cookie on *this* request
		//    (domain/path match) and PHP accepted it, mirror the value into $_COOKIE
		//    so downstream code in the same request can read it.
		if ($ok && $this->isCookieVisibleHere($opts)) {
			$_COOKIE[$name] = $value;
		}

		// 7) Report success/failure
		return $ok;
	}


	/**
	 * Read a cookie as string (current request view).
	 *
	 * Notes:
	 * - Returns $default when the cookie key is absent.
	 *
	 * @param string      $name
	 * @param string|null $default
	 * @return string|null Cookie value or $default.
	 * @throws \InvalidArgumentException For invalid cookie name.
	 */
	public function get(string $name, ?string $default = null): ?string {
		$this->assertValidName($name);
		return \array_key_exists($name, $_COOKIE) ? (string)$_COOKIE[$name] : $default;
	}


	/**
	 * Test if a cookie key exists in the current request view.
	 *
	 * @param string $name
	 * @return bool True if present in $_COOKIE.
	 * @throws \InvalidArgumentException For invalid cookie name.
	 */
	public function has(string $name): bool {
		$this->assertValidName($name);
		return \array_key_exists($name, $_COOKIE);
	}


	/**
	 * Delete a cookie (by expiring it in the past).
	 *
	 * Behavior:
	 * - Merges $options over defaults to match the original scope (path/domain/etc.).
	 * - Enforces invariant: SameSite=None requires Secure=true.
	 * - Emits an expired Set-Cookie header; removes local $_COOKIE entry unconditionally.
	 *
	 * @param string              $name
	 * @param array<string,mixed> $options Optional overrides to match cookie scope.
	 * @return bool True if PHP accepted the deletion header; false if headers already sent/refused.
	 * @throws \InvalidArgumentException If cookie name is invalid.
	 * @throws \RuntimeException If SameSite=None without Secure=true.
	 */
	public function delete(string $name, array $options = []): bool {
		// 1) Validate name early (fail fast).
		$this->assertValidName($name);

		// 2) Build the effective option set:
		//    - Start from computed service defaults.
		//    - Overlay caller-provided options (last wins).
		$opts = $this->mergeAssoc($this->defaults, $this->normalizeOptions($options, false));

		// 3) Force expiration into the past to signal deletion to the browser.
		$opts['expires'] = \time() - 3600;

		// 4) Enforce invariant: SameSite=None requires Secure=true.
		$samesite = $this->normalizeSameSite($opts['samesite'] ?? 'Lax');
		if ($samesite === 'None' && empty($opts['secure'])) {
			throw new \RuntimeException('SameSite=None requires Secure=true');
		}

		// 5) Convert to setcookie() options (omit 'domain' if host-only).
		$scOpts = $this->buildCookieOptions($opts);

		// 6) Emit expired Set-Cookie header (empty value). This returns false if
		//    headers were already sent or PHP rejected the header.
		$result = \setcookie($name, '', $scOpts);

		// 7) Local view: always remove the key from $_COOKIE so downstream code in
		//    this same request does not see a ghost value.
		unset($_COOKIE[$name]);

		// 8) Report whether PHP accepted the deletion header.
		return $result;
	}





/*
 *---------------------------------------------------------------
 * DIAGNOSTICS
 *---------------------------------------------------------------
 * PURPOSE
 *   Introspection helpers (useful for tests and debugging).
 */

	/**
	 * Return the effective merged defaults computed at init().
	 * 
	 * NOTE: Exposing the computed defaults with this method can be useful in tests/diagnostics.
	 *
	 * @return array<string,mixed> Keys: expires, path, domain|null, secure, httponly, samesite.
	 */
	public function defaults(): array {
		return $this->defaults;
	}






/*
 *---------------------------------------------------------------
 * INTERNALS — OPTION NORMALIZATION & MERGE
 *---------------------------------------------------------------
 * PURPOSE
 *   Normalize caller/service options and merge “last wins”.
 *
 * NOTES
 *   - ttl → expires
 *   - Validates/normalizes samesite/secure/path/domain types.
 */
 

	/**
	 * Normalize caller/service options to canonical internal shape.
	 *
	 * Behavior:
	 * - ttl -> expires (seconds, coerced to >= 0).
	 * - Normalizes samesite casing, path/domain types, secure/httponly booleans.
	 * - When $forDefaults=true, clamps expires to >= 0.
	 *
	 * @param array<string,mixed> $in
	 * @param bool                $forDefaults Clamp expires when building defaults.
	 * @return array<string,mixed> Normalized option map.
	 */
	private function normalizeOptions(array $in, bool $forDefaults): array {
		$out = $in;

		// ttl -> expires
		if (isset($out['ttl'])) {
			$ttl = (int)$out['ttl'];
			unset($out['ttl']);
			$out['expires'] = \time() + \max(0, $ttl);
		}
		// normalize samesite
		if (isset($out['samesite'])) {
			$out['samesite'] = $this->normalizeSameSite($out['samesite']);
		}
		// path
		if (isset($out['path'])) {
			$out['path'] = (string)$out['path'];
		}
		// domain
		if (\array_key_exists('domain', $out)) {
			$out['domain'] = ($out['domain'] === '' ? null : (string)$out['domain']);
		}
		// secure/httponly
		if (\array_key_exists('secure', $out)) {
			$out['secure'] = (bool)$out['secure'];
		}
		if (\array_key_exists('httponly', $out)) {
			$out['httponly'] = (bool)$out['httponly'];
		}

		// For defaults, ensure expires is int >= 0
		if ($forDefaults && isset($out['expires'])) {
			$out['expires'] = \max(0, (int)$out['expires']);
		}

		return $out;
	}
	

	/**
	 * Shallow associative merge with "last wins" semantics.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 * @return array<string,mixed> Result where keys from $b override $a.
	 */
	private function mergeAssoc(array $a, array $b): array {
		foreach ($b as $k => $v) {
			$a[$k] = $v;
		}
		return $a;
	}




/*
 *---------------------------------------------------------------
 * INTERNALS — SETCOOKIE OPTIONS BUILDER
 *---------------------------------------------------------------
 * PURPOSE
 *   Convert normalized options into setcookie() array shape.
 *
 * NOTES
 *   - Omits 'domain' when null/empty for host-only cookies.
 */

	/**
	 * Build the options array for PHP's setcookie().
	 *
	 * Behavior:
	 * - Normalizes and canonicalizes fields (path casing/shape, domain format, SameSite value).
	 * - Omits 'domain' for host-only cookies (null/empty) per RFC6265.
	 * - Does not enforce invariants here (e.g., SameSite=None ⇒ Secure=true); that is validated by callers.
	 *
	 * @param array<string,mixed> $opts Normalized options (may include: expires, path, domain, secure, httponly, samesite).
	 * @return array<string,mixed> Keys suitable for setcookie(): expires, path, secure, httponly, samesite[, domain].
	 */
	private function buildCookieOptions(array $opts): array {
		
		// 1) Expires
		// Allow 0 (session cookie) or any integer (including negatives for deletions).
		$expires = (int)($opts['expires'] ?? 0);

		// 2) Path
		// Ensure leading "/" and collapse trailing slash (except keep "/" as-is).
		$path = (string)($opts['path'] ?? '/');
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}
		$path = \rtrim($path, '/');
		if ($path === '') {
			$path = '/';
		}

		// 3) Domain
		// Omit when null/empty -> host-only cookie. Canonicalize: lowercase and strip leading dot.
		$domain = null;
		if (isset($opts['domain']) && $opts['domain'] !== null && $opts['domain'] !== '') {
			$domain = \strtolower((string)$opts['domain']);
			$domain = \ltrim($domain, '.'); // leading dot is obsolete; normalize away
			if ($domain === '') {
				$domain = null; // Fall back to host-only if result became empty
			}
		}

		// 4) Secure / httponly
		$secure   = (bool)($opts['secure']   ?? false);
		$httponly = (bool)($opts['httponly'] ?? true);

		// 5) Samesite
		// Accept any casing in input; normalize to 'Lax'|'Strict'|'None'.
		$samesite = $this->normalizeSameSite($opts['samesite'] ?? 'Lax');

		// 6) Assemble setcookie() options
		$out = [
			'expires'  => $expires,
			'path'     => $path,
			'secure'   => $secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		];

		// Only include domain when explicitly set (non-null, non-empty).
		if ($domain !== null) {
			$out['domain'] = $domain;
		}

		return $out;
	}





/*
 *---------------------------------------------------------------
 * INTERNALS — VALIDATION & CANONICALIZATION
 *---------------------------------------------------------------
 * PURPOSE
 *   Guard rails and tiny helpers for correctness.
 */

	/**
	 * Validate cookie name against RFC6265 token charset.
	 *
	 * @param string $name
	 * @return void
	 * @throws \InvalidArgumentException If the name is empty or contains invalid characters.
	 */
	private function assertValidName(string $name): void {
		if ($name === '') {
			throw new \InvalidArgumentException('Cookie name cannot be empty.');
		}
		// RFC6265 token chars (no separators/CTL/SP/";,=" etc.)
		if (!\preg_match('/^[A-Za-z0-9!#$%&\'*+\-.\^_`|~]+$/', $name)) {
			throw new \InvalidArgumentException('Invalid cookie name: ' . $name);
		}
	}


	/**
	 * Normalize SameSite value.
	 *
	 * Behavior:
	 * - Accepts any casing; returns 'Lax', 'Strict', or 'None'.
	 * - Unknown/invalid values fall back to 'Lax'.
	 *
	 * @param mixed $val
	 * @return 'Lax'|'Strict'|'None'
	 */
	private function normalizeSameSite(mixed $val): string {
		if (!\is_string($val)) {
			return 'Lax';
		}
		$s = \ucfirst(\strtolower($val));
		return \in_array($s, ['Lax','Strict','None'], true) ? $s : 'Lax';
	}



/*
 *---------------------------------------------------------------
 * INTERNALS — URL/HOST HELPERS
 *---------------------------------------------------------------
 * PURPOSE
 *   Derive domain hints from base URL when not explicitly set.
 */ 

	/**
	 * Extract a cookie-suitable domain from a base URL.
	 *
	 * Returns a normalized host for use as cookie "domain", or null if the host
	 * should not be used for domain scoping (e.g., IP address or 'localhost').
	 *
	 * Behavior:
	 * - Accepts only absolute http/https URLs.
	 * - Parses the host, strips IPv6 brackets, lowercases.
	 * - Returns null for IP literals (IPv4/IPv6) and 'localhost'.
	 * - Returns the normalized host without a leading dot.
	 *
	 * Notes:
	 * - Do NOT prepend a leading dot. Modern browsers handle subdomain scoping.
	 * - This function does not validate public suffix / eTLD+1 boundaries.
	 *
	 * Typical usage:
	 *   $domain = $this->hostFromBaseUrl($cfgHttp['base_url'] ?? '');
	 *   if ($domain !== null) { $opts['domain'] = $domain; }
	 *
	 * @param string $baseUrl Absolute base URL (must start with http:// or https://).
	 * @return ?string Lowercased host suitable for cookie domain, or null when unsuitable.
	 */
	private function hostFromBaseUrl(string $baseUrl): ?string {
		
		// Require http(s) to avoid false positives (e.g., mailto:, about:blank).
		if ($baseUrl === '' || \preg_match('#^https?://#i', $baseUrl) !== 1) {
			return null;
		}

		$host = \parse_url($baseUrl, \PHP_URL_HOST);
		if (!\is_string($host) || $host === '') {
			return null;
		}

		// Strip IPv6 brackets and normalize case.
		$host = \trim($host, '[]');
		$lower = \strtolower($host);

		// Skip domain scoping for IPs and localhost (browsers reject/ignore).
		if ($lower === 'localhost' || \filter_var($host, \FILTER_VALIDATE_IP)) {
			return null;
		}

		// Return normalized registrable host (no leading dot).
		return $lower;
	}





/*
 *---------------------------------------------------------------
 * INTERNALS — VISIBILITY CHECK (LOCAL VIEW)
 *---------------------------------------------------------------
 * PURPOSE
 *   Decide if a just-set cookie should be visible in this request.
 *
 * NOTES
 *   - RFC6265 path-match boundary: "/app" must not match "/apple".
 *   - Domain cookie matches exact domain + subdomains.
 */

	/**
	 * Best-effort check: will a just-set cookie be visible to *this* request?
	 *
	 * Mirrors browser visibility rules enough to safely reflect a successful
	 * setcookie() into $_COOKIE immediately (so downstream code can read it
	 * within the same request).
	 *
	 * Behavior:
	 * - Normalizes the request host (strip IPv6 brackets/port, lowercase) and path.
	 * - Domain rules:
	 *   - If the request host is an IP or "localhost": browsers typically reject
	 *     Domain-scoped cookies; require host-only (cookie domain MUST be null).
	 *   - Else (real registrable host): domain matches on exact host or any subdomain.
	 * - Path rules (RFC6265-ish):
	 *   - "/" matches everything.
	 *   - Otherwise require exact path match OR a prefix + "/" boundary.
	 *
	 * Notes:
	 * - This is a conservative heuristic; it does not attempt full RFC parity.
	 * - Only 'domain' and 'path' keys (as produced by buildCookieOptions()) are used.
	 *
	 * Typical usage:
	 *   $opts = $this->buildCookieOptions($final);
	 *   if ($ok && $this->isCookieVisibleHere($opts)) { $_COOKIE[$name] = $value; }
	 *
	 * @param array $opts Cookie options array (keys: 'domain'?, 'path'?).
	 * @return bool True if the cookie should be visible in this request context.
	 */
	private function isCookieVisibleHere(array $opts): bool {		

		// 1) Normalize the request host for domain matching
		//    - Accept HTTP_HOST (preferred) or SERVER_NAME as fallback
		//    - Handle IPv6 literals (strip brackets) and remove any port suffix
		$host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
		if ($host !== '' && $host[0] === '[') {
			// IPv6 literal like "[::1]:8080" -> "::1"
			$rb = \strpos($host, ']');
			if ($rb !== false) {
				$host = \substr($host, 1, $rb - 1);
			}
		}
		$colon = \strpos($host, ':');
		if ($colon !== false) {
			$host = \substr($host, 0, $colon);
		}
		$host = \strtolower($host);


		// 2) Normalize the request path (ignore query string)
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
		$qpos = \strpos($uri, '?');
		$reqPath = ($qpos === false) ? $uri : \substr($uri, 0, $qpos);
		if ($reqPath === '') {
			$reqPath = '/';
		}


		// 3) Normalize cookie scope from $opts
		//    - domain: null means host-only; non-null means Domain attribute set
		//    - path: ensure leading "/" and remove trailing "/" (except keep "/" as-is)
		$cookieDomain = isset($opts['domain']) && $opts['domain'] !== '' ? \strtolower((string)$opts['domain']) : null;
		$cookiePath   = (string)($opts['path'] ?? '/');
		if ($cookiePath === '' || $cookiePath[0] !== '/') {
			$cookiePath = '/' . $cookiePath;
		}
		$cookiePath = \rtrim($cookiePath, '/');
		if ($cookiePath === '') {
			$cookiePath = '/';
		}


		// 4) Domain matching
		//    Special-case IP/localhost: browsers disallow/ignore Domain= for such hosts.
		//    => Require host-only cookies (cookieDomain === null) in that scenario.
		//    Otherwise (registrable host): accept exact match or subdomain match.
		$hostIsIpOrLocal = ($host === 'localhost') || \filter_var($host, \FILTER_VALIDATE_IP);

		if ($hostIsIpOrLocal) {
			// On IP or "localhost", Domain-scoped cookies are effectively not visible.
			$domainOk = ($cookieDomain === null) && ($host !== '');
		} else {
			if ($cookieDomain === null) {
				// Host-only cookie on a regular hostname -> visible when host is non-empty.
				$domainOk = ($host !== '');
			} else {
				// Domain cookie: matches the domain itself and any subdomain.
				// Example: cookieDomain="example.com" matches "example.com" and "a.example.com".
				$domainOk = ($host === $cookieDomain) || \str_ends_with($host, '.' . $cookieDomain);
			}
		}


		// 5) Path matching (RFC6265 path-match semantics)
		//    - "/" matches everything
		//    - Otherwise require exact match OR prefix + "/" boundary
		$pathOk = ($cookiePath === '/')
			? true
			: ($reqPath === $cookiePath || \str_starts_with($reqPath, $cookiePath . '/'));


		// 6) Final verdict: visible only if both domain and path conditions hold
		return $domainOk && $pathOk;
	}


	
}
