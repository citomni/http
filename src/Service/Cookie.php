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
 * Cookie: Security-first, deterministic cookie API and owner of the cookie attributes.
 *
 * Responsibilities:
 * - Provide a thin, explicit wrapper around setcookie()/$_COOKIE.
 * - Resolve the application's default cookie attributes (Secure, SameSite, HttpOnly,
 *   Path, Domain) once per request from cfg.cookie, the service options and the request.
 *   Session takes the attributes of the PHP session cookie from attributes() as well, so
 *   every cookie of the application follows one rule.
 * - Allow per-call overrides via $options and convert a friendly 'ttl' to 'expires'.
 *
 * Collaborators:
 * - $this->app->cfg->cookie (read): Default attributes, baseline in Registry::CFG_HTTP.
 * - $this->app->cfg->http->base_url and CITOMNI_PUBLIC_ROOT_URL (read): HTTPS signals for
 *   the Secure inference.
 * - $this->app->request (required): isHttps(), the proxy-aware HTTPS signal of this request.
 *   The request service is part of the citomni/http baseline in HTTP mode.
 *
 * Configuration (cfg.cookie; service $options are merged on top, last wins):
 * - secure   (bool|null)   Explicit value, or null to infer: True when http.base_url or
 *                          CITOMNI_PUBLIC_ROOT_URL is https, or when this request is HTTPS.
 * - samesite (string)      "Lax", "Strict" or "None" in any casing. "None" requires Secure.
 * - path     (string|null) Leading slash, no trailing slash except for "/"; null or ""
 *                          means "/".
 * - domain   (string|null) null or "" => host-only: No Domain attribute, so the browser
 *                          returns the cookie only to the host that set it. A domain shares
 *                          the cookie with all its subdomains; set it only for that purpose.
 * - httponly (bool)        Default true.
 *
 * Public API:
 * - set(string $name, string $value, array $options = []): bool
 *     Supported $options (caller overrides the defaults):
 *       ttl       (int)    Lifetime seconds; coerced to >= 0; converted to expires = time() + ttl.
 *       expires   (int)    Unix timestamp (overrides ttl).
 *       path      (string)
 *       domain    (string|null) null or "" means host-only.
 *       secure    (bool)
 *       httponly  (bool)
 *       samesite  (string: "Lax"|"Strict"|"None")
 *     Returns false if PHP refuses to set the cookie (e.g., headers already sent).
 *     Note: set() updates $_COOKIE[$name] for the current request iff PHP accepted the cookie
 *           and the computed scope (domain/path) applies to the current request.
 *
 * - get(string $name, ?string $default = null): ?string
 *     Return the cookie value as string, or $default when absent or not a string.
 *     Cookie input is untrusted: PHP parses "Cookie: name[]=x" into an array, and such
 *     values are treated as absent rather than cast to "Array".
 *
 * - has(string $name): bool
 *     True if the cookie is present in the current request as a string value.
 *
 * - delete(string $name, array $options = []): bool
 *     Delete by setting an expiry in the past. You may pass path/domain/etc. to match scope.
 *     Returns false if PHP refuses the deletion (e.g., headers already sent).
 *     Note: delete() removes the key from $_COOKIE locally (current-request view).
 *
 * - attributes(array $overrides = []): array<string,mixed>
 *     The defaults with normalized $overrides on top, validated as a whole. set(), delete()
 *     and Session use it, so overrides are checked in the combination that is sent.
 *
 * - defaults(): array<string,mixed>
 *     The effective defaults after initialization (useful for tests/diagnostics).
 *
 * Behavior & invariants:
 * - SameSite=None requires Secure=true for the defaults and for every resolved attribute
 *   set; violations throw \RuntimeException.
 * - SameSite values other than Lax, Strict and None throw \InvalidArgumentException, as do
 *   a non-boolean secure or httponly and a non-string domain. A typo must not silently
 *   change the cookie policy.
 * - Cookie names are validated against the RFC6265 token charset; invalid names throw
 *   \InvalidArgumentException.
 * - Defaults are built once at init from cfg + service $options (last wins).
 * - Local view semantics:
 *   - set(): updates $_COOKIE[$name] for the current request iff PHP accepted the cookie
 *     and the computed scope (domain/path) applies to the current request; otherwise leaves
 *     $_COOKIE unchanged.
 *   - delete(): unsets $_COOKIE[$name] locally regardless of setcookie()'s return value.
 *
 * Error handling:
 * - Missing cookies and non-string (array-shaped) values on get/has return $default/false;
 *   no exceptions and no warnings.
 * - set()/delete() return false if PHP refuses the operation (e.g., headers already sent).
 * - Invalid configuration is detected at init(); invalid options at the call.
 * - Unexpected runtime errors bubble to the global error handler (fail fast).
 *
 * Performance:
 * - Deterministic option merging; no unnecessary serialization or allocations.
 * - The Secure inference runs once at init and only when cookie.secure is null.
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
 * - Secure from an https base URL is a site policy: The cookie is Secure even when this
 *   request reached PHP over HTTP, as behind a TLS proxy that is not trusted. Browsers only
 *   see the https side. Response decides HSTS from Request::isHttps() alone.
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
 *   - Enforces invariants early (e.g., SameSite=None => Secure=true).
 *   - Host-only unless cfg.cookie.domain names a domain.
 */

	/** @var array<string,mixed> */
	private array $defaults = [];


	/**
	 * Initialize default cookie options from config and runtime environment.
	 *
	 * Behavior:
	 * - Secure:
	 *   1) Respect cookie.secure when it is a bool.
	 *   2) When it is null, infer from http.base_url, CITOMNI_PUBLIC_ROOT_URL or the
	 *      current request (any https signal => true).
	 * - SameSite: Lax, Strict or None (any casing); None requires Secure.
	 * - Domain: cookie.domain when it names a domain; null or "" => host-only. There is no
	 *   inference from the base URL: A Domain attribute widens the scope to every subdomain,
	 *   so it must be configured explicitly.
	 * - Merge: Constructor $options are merged over the defaults (last wins), and the
	 *   result is validated again.
	 *
	 * Notes:
	 * - Keep this method fast; it runs once per request (constructor init path).
	 *
	 * @return void
	 * @throws \RuntimeException If SameSite=None is used without Secure=true, or if
	 *                           cookie.secure is neither a bool nor null.
	 * @throws \InvalidArgumentException For an invalid SameSite, domain or httponly value.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->cookie->toArray();

		// 1) Secure: Explicit, or inferred from the site's URL and the request.
		$secure = $cfg['secure'];
		if ($secure === null) {
			$secure = $this->siteUsesHttps();
		} elseif (!\is_bool($secure)) {
			throw new \RuntimeException('cookie.secure must be true, false or null (inferred); got ' . \var_export($secure, true) . '.');
		}

		// 2) Remaining attributes, normalized and type-checked like per-call options.
		$this->defaults = ['expires' => 0, 'secure' => $secure] + $this->normalizeOptions([
			'path'     => $cfg['path'],
			'domain'   => $cfg['domain'],
			'httponly' => $cfg['httponly'],
			'samesite' => $cfg['samesite'],
		], true);

		// 3) Service-level $options (from the service definition), last wins.
		if ($this->options !== []) {
			$this->defaults = $this->mergeAssoc($this->defaults, $this->normalizeOptions($this->options, true));
		}

		// 4) Browsers require Secure when SameSite=None; check the final combination.
		$this->assertSameSiteNoneIsSecure($this->defaults);
	}






/*
 *---------------------------------------------------------------
 * PUBLIC API - READ/WRITE/DELETE
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
	 * - Resolves the attributes through attributes($options), which validates them.
	 * - Calls setcookie(); if accepted and scope matches this request, mirrors into $_COOKIE.
	 *
	 * @param string              $name     RFC6265-token cookie name (validated).
	 * @param string              $value    Raw cookie value (no serialization performed).
	 * @param array<string,mixed> $options  ttl|expires|path|domain|secure|httponly|samesite
	 * @return bool True if PHP accepted the cookie; false if headers already sent or refused.
	 * @throws \InvalidArgumentException For an invalid cookie name or option value.
	 * @throws \RuntimeException For SameSite=None without Secure=true.
	 */
	public function set(string $name, string $value, array $options = []): bool {

		// 1) Validate name early (fail fast on invalid RFC6265 token).
		$this->assertValidName($name);

		// 2) Resolve the final attribute set: The defaults with the caller's options on top.
		//    attributes() also handles ttl -> expires and rejects SameSite=None without Secure.
		$final = $this->attributes($options);

		// 3) Convert to the exact array shape setcookie() expects.
		//    Domain is omitted for host-only cookies (null/empty).
		$opts = $this->buildCookieOptions($final);

		// 4) Emit the Set-Cookie header.
		//    Returns false if headers were already sent or PHP rejected the header.
		$ok = \setcookie($name, $value, $opts);

		// 5) Local view: If the browser would see this cookie on *this* request
		//    (domain/path match) and PHP accepted it, mirror the value into $_COOKIE
		//    so downstream code in the same request can read it.
		if ($ok && $this->isCookieVisibleHere($opts)) {
			$_COOKIE[$name] = $value;
		}

		// 6) Report success/failure
		return $ok;
	}


	/**
	 * Read a cookie as string (current request view).
	 *
	 * Notes:
	 * - Returns $default when the cookie key is absent.
	 * - Returns $default when the value is not a string (array-shaped input such as
	 *   "name[]=x"). Never casts untrusted arrays to the string "Array".
	 *
	 * @param string      $name
	 * @param string|null $default
	 * @return string|null Cookie value or $default.
	 * @throws \InvalidArgumentException For invalid cookie name.
	 */
	public function get(string $name, ?string $default = null): ?string {
		$this->assertValidName($name);
		$value = $_COOKIE[$name] ?? null;
		return \is_string($value) ? $value : $default;
	}


	/**
	 * Test if a cookie is present in the current request view.
	 *
	 * Notes:
	 * - Consistent with get(): non-string (array-shaped) values count as absent.
	 *
	 * @param string $name
	 * @return bool True if present in $_COOKIE as a string.
	 * @throws \InvalidArgumentException For invalid cookie name.
	 */
	public function has(string $name): bool {
		$this->assertValidName($name);
		return \is_string($_COOKIE[$name] ?? null);
	}


	/**
	 * Delete a cookie (by expiring it in the past).
	 *
	 * Behavior:
	 * - Resolves the scope through attributes($options) (path/domain/etc.).
	 * - Emits an expired Set-Cookie header; removes local $_COOKIE entry unconditionally.
	 *
	 * @param string              $name
	 * @param array<string,mixed> $options Optional overrides to match cookie scope.
	 * @return bool True if PHP accepted the deletion header; false if headers already sent/refused.
	 * @throws \InvalidArgumentException If cookie name or an option value is invalid.
	 * @throws \RuntimeException If SameSite=None without Secure=true.
	 */
	public function delete(string $name, array $options = []): bool {
		// 1) Validate name early (fail fast).
		$this->assertValidName($name);

		// 2) Resolve the scope and force expiration into the past to signal deletion.
		$opts = $this->attributes($options);
		$opts['expires'] = \time() - 3600;

		// 3) Convert to setcookie() options (omit 'domain' if host-only).
		$scOpts = $this->buildCookieOptions($opts);

		// 4) Emit expired Set-Cookie header (empty value). This returns false if
		//    headers were already sent or PHP rejected the header.
		$result = \setcookie($name, '', $scOpts);

		// 5) Local view: Always remove the key from $_COOKIE so downstream code in
		//    this same request does not see a ghost value.
		unset($_COOKIE[$name]);

		// 6) Report whether PHP accepted the deletion header.
		return $result;
	}


	/**
	 * Resolve a cookie's attributes: The defaults with $overrides on top.
	 *
	 * Behavior:
	 * - Normalizes $overrides like set() options: ttl -> expires, SameSite casing, a
	 *   leading dot and "" in domain, and the types of every attribute.
	 * - Validates the final combination: SameSite=None requires Secure=true.
	 *
	 * Notes:
	 * - Session resolves the PHP session cookie here with its session.cookie_* settings
	 *   as overrides, so it follows the same rule as every other cookie.
	 *
	 * Typical usage:
	 *   $attributes = $this->app->cookie->attributes(['samesite' => 'Strict']);
	 *
	 * @param array<string,mixed> $overrides ttl|expires|path|domain|secure|httponly|samesite
	 * @return array<string,mixed> Keys: expires, path, domain|null, secure, httponly, samesite.
	 * @throws \InvalidArgumentException For an invalid option value.
	 * @throws \RuntimeException For SameSite=None without Secure=true.
	 */
	public function attributes(array $overrides = []): array {
		$attributes = $this->mergeAssoc($this->defaults, $this->normalizeOptions($overrides, false));
		$this->assertSameSiteNoneIsSecure($attributes);
		return $attributes;
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
 * INTERNALS - OPTION NORMALIZATION & MERGE
 *---------------------------------------------------------------
 * PURPOSE
 *   Normalize caller/service options and merge "last wins".
 *
 * NOTES
 *   - ttl -> expires
 *   - Validates/normalizes samesite/secure/httponly/path/domain types.
 */


	/**
	 * Normalize caller/service options to canonical internal shape.
	 *
	 * Behavior:
	 * - ttl -> expires (seconds, coerced to >= 0).
	 * - samesite: Lax, Strict or None in any casing; anything else throws.
	 * - path: A leading slash and no trailing slash, except for "/"; null or "" => "/".
	 * - domain: null or "" => null (host-only); otherwise lowercase without a leading dot.
	 * - secure/httponly: Must be bool.
	 * - When $forDefaults=true, clamps expires to >= 0.
	 *
	 * @param array<string,mixed> $in
	 * @param bool                $forDefaults Clamp expires when building defaults.
	 * @return array<string,mixed> Normalized option map.
	 * @throws \InvalidArgumentException For an invalid samesite, path, domain, secure or httponly value.
	 */
	private function normalizeOptions(array $in, bool $forDefaults): array {
		$out = $in;

		// ttl -> expires
		if (isset($out['ttl'])) {
			$ttl = (int)$out['ttl'];
			unset($out['ttl']);
			$out['expires'] = \time() + \max(0, $ttl);
		}
		// samesite
		if (\array_key_exists('samesite', $out)) {
			$out['samesite'] = $this->normalizeSameSite($out['samesite']);
		}
		// path
		if (\array_key_exists('path', $out)) {
			$out['path'] = $this->normalizePath($out['path']);
		}
		// domain
		if (\array_key_exists('domain', $out)) {
			$out['domain'] = $this->normalizeDomain($out['domain']);
		}
		// secure/httponly
		foreach (['secure', 'httponly'] as $flag) {
			if (\array_key_exists($flag, $out) && !\is_bool($out[$flag])) {
				throw new \InvalidArgumentException("Cookie attribute '{$flag}' must be a bool; got " . \var_export($out[$flag], true) . '.');
			}
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
 * INTERNALS - SETCOOKIE OPTIONS BUILDER
 *---------------------------------------------------------------
 * PURPOSE
 *   Convert normalized options into setcookie() array shape.
 *
 * NOTES
 *   - Omits 'domain' when null for host-only cookies.
 */

	/**
	 * Build the options array for PHP's setcookie().
	 *
	 * Behavior:
	 * - Canonicalizes the SameSite value; normalizeOptions() already canonicalized the path.
	 * - Omits 'domain' for host-only cookies (null) per RFC6265.
	 * - Does not enforce invariants here (e.g., SameSite=None => Secure=true); attributes()
	 *   validated them.
	 *
	 * @param array<string,mixed> $opts Normalized options (may include: expires, path, domain, secure, httponly, samesite).
	 * @return array<string,mixed> Keys suitable for setcookie(): expires, path, secure, httponly, samesite[, domain].
	 */
	private function buildCookieOptions(array $opts): array {

		// 1) Expires
		// Allow 0 (session cookie) or any integer (including negatives for deletions).
		$expires = (int)($opts['expires'] ?? 0);

		// 2) Path
		// normalizePath() already gave it a leading slash and no trailing slash.
		$path = (string)($opts['path'] ?? '/');

		// 3) Domain
		// null -> host-only cookie. normalizeDomain() already lowercased it and dropped a leading dot.
		$domain = $opts['domain'] ?? null;

		// 4) Secure / httponly
		$secure   = (bool)($opts['secure']   ?? false);
		$httponly = (bool)($opts['httponly'] ?? true);

		// 5) Samesite
		$samesite = $this->normalizeSameSite($opts['samesite'] ?? 'Lax');

		// 6) Assemble setcookie() options
		$out = [
			'expires'  => $expires,
			'path'     => $path,
			'secure'   => $secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		];

		// Only include domain when explicitly set (non-null).
		if ($domain !== null) {
			$out['domain'] = $domain;
		}

		return $out;
	}





/*
 *---------------------------------------------------------------
 * INTERNALS - VALIDATION & CANONICALIZATION
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
	 * Normalize a SameSite value.
	 *
	 * Behavior:
	 * - Accepts "Lax", "Strict" and "None" in any casing.
	 * - Throws for anything else. Falling back to Lax would hide a typo such as "Nnoe"
	 *   behind a different policy.
	 *
	 * @param mixed $val
	 * @return 'Lax'|'Strict'|'None'
	 * @throws \InvalidArgumentException For any other value.
	 */
	private function normalizeSameSite(mixed $val): string {
		$s = \is_string($val) ? \ucfirst(\strtolower($val)) : '';
		if (!\in_array($s, ['Lax', 'Strict', 'None'], true)) {
			throw new \InvalidArgumentException('SameSite must be "Lax", "Strict" or "None"; got ' . \var_export($val, true) . '.');
		}
		return $s;
	}


	/**
	 * Normalize a Path value.
	 *
	 * Behavior:
	 * - null and "" mean "/".
	 * - Adds a missing leading slash and drops trailing slashes: "app/" => "/app".
	 *
	 * Notes:
	 * - The session cookie takes its path from attributes(), so it gets the same path
	 *   as every other cookie.
	 *
	 * @param mixed $val
	 * @return string Path.
	 * @throws \InvalidArgumentException For a value that is neither a string nor null.
	 */
	private function normalizePath(mixed $val): string {
		if ($val === null || $val === '') {
			return '/';
		}
		if (!\is_string($val)) {
			throw new \InvalidArgumentException('Cookie path must be a string or null; got ' . \var_export($val, true) . '.');
		}
		$path = \rtrim($val[0] === '/' ? $val : '/' . $val, '/');
		return $path === '' ? '/' : $path;
	}


	/**
	 * Normalize a Domain value.
	 *
	 * Behavior:
	 * - null and "" mean host-only and return null.
	 * - A string is lowercased without a leading dot (obsolete in RFC 6265); a value
	 *   that is empty afterwards also means host-only.
	 *
	 * @param mixed $val
	 * @return string|null Domain, or null for host-only.
	 * @throws \InvalidArgumentException For a value that is neither a string nor null.
	 */
	private function normalizeDomain(mixed $val): ?string {
		if ($val === null || $val === '') {
			return null;
		}
		if (!\is_string($val)) {
			throw new \InvalidArgumentException('Cookie domain must be a string or null; got ' . \var_export($val, true) . '.');
		}
		$domain = \ltrim(\strtolower($val), '.');
		return $domain === '' ? null : $domain;
	}


	/**
	 * Reject SameSite=None without Secure, which browsers drop.
	 *
	 * @param array<string,mixed> $attributes Normalized attributes.
	 * @return void
	 * @throws \RuntimeException When samesite is None and secure is not true.
	 */
	private function assertSameSiteNoneIsSecure(array $attributes): void {
		if (($attributes['samesite'] ?? 'Lax') === 'None' && ($attributes['secure'] ?? false) !== true) {
			throw new \RuntimeException('SameSite=None requires Secure=true');
		}
	}


	/**
	 * Whether the site is served over HTTPS: By its configured public URL or by this request.
	 *
	 * Behavior:
	 * - True when http.base_url starts with https://.
	 * - True when CITOMNI_PUBLIC_ROOT_URL starts with https://. Kernel defines the constant
	 *   from http.base_url, but an entry point or deploy step may define it beforehand,
	 *   which Kernel respects; then it may be the only https signal.
	 * - Otherwise Request::isHttps(), which honors trusted proxies.
	 *
	 * @return bool True when any of the signals says https.
	 */
	private function siteUsesHttps(): bool {
		$baseUrl = (string)($this->app->cfg->http->base_url ?? '');
		if (\stripos($baseUrl, 'https://') === 0) {
			return true;
		}
		if (\defined('CITOMNI_PUBLIC_ROOT_URL') && \stripos((string)\CITOMNI_PUBLIC_ROOT_URL, 'https://') === 0) {
			return true;
		}
		return $this->app->request->isHttps();
	}






/*
 *---------------------------------------------------------------
 * INTERNALS - VISIBILITY CHECK (LOCAL VIEW)
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
