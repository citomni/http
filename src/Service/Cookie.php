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
 * Cookie: Service that sets, reads and deletes cookies with one set of default attributes.
 *
 * Gives the application one set of default cookie attributes (Secure, SameSite, HttpOnly, Path
 * and Domain), resolved once per instance from cfg cookie, the service options and the request,
 * with per-call overrides on top. It neither serializes nor signs a value: The browser holds it,
 * so what a value contains, and protecting it, belongs to the caller.
 *
 * Rules:
 * - Resolve the attributes of every outgoing cookie through attributes(), so an override is
 *   checked in the combination that is sent.
 * - Throw for an invalid name, Secure, HttpOnly, SameSite, Path or Domain value rather than fall
 *   back: A typo must not silently change the cookie policy.
 * - Treat cookie input as untrusted: A value that is not a string, such as array-shaped input
 *   (name[]=x), counts as absent and is never cast.
 */
class Cookie extends BaseService {



	// ----------------------------------------------------------------
	// Defaults
	// ----------------------------------------------------------------

	/** @var array<string,mixed> Default attributes of every cookie, before per-call overrides. */
	private array $defaults = [];


	/**
	 * Resolve the default attributes from cfg cookie, the service options and the request.
	 *
	 * Behavior:
	 * - secure: cfg cookie.secure when it is a bool. When it is null, true when the site uses HTTPS
	 *   by its configured URL or by this request; see siteUsesHttps().
	 * - path, domain, httponly and samesite: cfg cookie.*, normalized as by attributes(). A null or
	 *   empty domain means host-only. There is no inference from the base URL, because a Domain
	 *   attribute shares the cookie with every subdomain.
	 * - expires: 0, a session cookie.
	 * - The service options override any attribute and are checked the same way.
	 *
	 * @return void
	 * @throws \RuntimeException  When cfg cookie.secure is neither a bool nor null, or the defaults combine SameSite=None with Secure off.
	 * @throws \InvalidArgumentException  When cfg cookie.path, cookie.domain, cookie.httponly or cookie.samesite, or a service option, has an invalid value.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->cookie->toArray();

		$secure = $cfg['secure'];
		if ($secure === null) {
			$secure = $this->siteUsesHttps();
		} elseif (!\is_bool($secure)) {
			throw new \RuntimeException('cookie.secure must be true, false or null (inferred); got ' . \var_export($secure, true) . '.');
		}

		// Normalized and type-checked like per-call options.
		$this->defaults = ['expires' => 0, 'secure' => $secure] + $this->normalizeOptions([
			'path'     => $cfg['path'],
			'domain'   => $cfg['domain'],
			'httponly' => $cfg['httponly'],
			'samesite' => $cfg['samesite'],
		], true);

		// The service options win over cfg.
		if ($this->options !== []) {
			$this->defaults = $this->mergeAssoc($this->defaults, $this->normalizeOptions($this->options, true));
		}

		// Browsers drop SameSite=None without Secure; check the final combination.
		$this->assertSameSiteNoneIsSecure($this->defaults);
	}






	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Send a cookie and, where this request would see it, mirror it into $_COOKIE.
	 *
	 * Behavior:
	 * - An attribute missing from $options takes its default; see attributes().
	 * - ttl is the lifetime in seconds from now, a negative ttl counting as 0. A non-null ttl wins
	 *   over expires.
	 * - expires is a Unix timestamp; 0 makes a session cookie, which the browser drops when it
	 *   closes.
	 * - Returns false when PHP refuses the header, as when headers were already sent.
	 * - When PHP accepted the cookie and its domain and path match this request, $_COOKIE[$name]
	 *   is set to the value; otherwise $_COOKIE is unchanged.
	 *
	 * Typical usage:
	 *   $this->app->cookie->set('theme', 'dark', ['ttl' => 31536000]);
	 *
	 * @param  string  $name  RFC 6265 token.
	 * @param  string  $value  Not serialized; setcookie() URL-encodes it.
	 * @param  array{ttl?:int,expires?:int,path?:string|null,domain?:string|null,secure?:bool,httponly?:bool,samesite?:string}  $options
	 * @return bool  True when PHP accepted the header.
	 * @throws \InvalidArgumentException  When the name is not an RFC 6265 token, or an option has an invalid value.
	 * @throws \RuntimeException  When the attributes combine SameSite=None with Secure off.
	 * @throws \ValueError  When setcookie() rejects the path, domain or expires, such as a path with ";" or a space.
	 */
	public function set(string $name, string $value, array $options = []): bool {

		$this->assertValidName($name);

		$final = $this->attributes($options);

		$opts = $this->buildCookieOptions($final);

		$ok = \setcookie($name, $value, $opts);

		// Mirror only where the browser would send the cookie back to this URL, so later code in
		// this request reads what the next request will carry.
		if ($ok && $this->isCookieVisibleHere($opts)) {
			$_COOKIE[$name] = $value;
		}

		return $ok;
	}


	/**
	 * Return a cookie of this request as a string.
	 *
	 * Behavior:
	 * - Returns $default when the cookie is absent, or when its value is not a string.
	 *
	 * Typical usage:
	 *   $theme = $this->app->cookie->get('theme', 'light');
	 *
	 * @param  string  $name
	 * @param  string|null  $default
	 * @return string|null  The value, or $default.
	 * @throws \InvalidArgumentException  When the name is not an RFC 6265 token.
	 */
	public function get(string $name, ?string $default = null): ?string {
		$this->assertValidName($name);
		$value = $_COOKIE[$name] ?? null;
		return \is_string($value) ? $value : $default;
	}


	/**
	 * Check whether this request carries a cookie with a string value.
	 *
	 * @param  string  $name
	 * @return bool  False also for a value that is not a string, as in get().
	 * @throws \InvalidArgumentException  When the name is not an RFC 6265 token.
	 */
	public function has(string $name): bool {
		$this->assertValidName($name);
		return \is_string($_COOKIE[$name] ?? null);
	}


	/**
	 * Delete a cookie in the browser and in this request.
	 *
	 * A browser deletes only the cookie with the same name, path and domain, so pass the path and
	 * domain the cookie was set with when they differ from the defaults.
	 *
	 * Behavior:
	 * - Sends the cookie with an empty value and an expiry in the past; ttl and expires in $options
	 *   do not apply.
	 * - Removes $_COOKIE[$name] also when PHP refuses the header.
	 *
	 * Typical usage:
	 *   $this->app->cookie->delete('theme');
	 *
	 * @param  string  $name  RFC 6265 token.
	 * @param  array{ttl?:int,expires?:int,path?:string|null,domain?:string|null,secure?:bool,httponly?:bool,samesite?:string}  $options  Attributes of the cookie to delete, as for set().
	 * @return bool  True when PHP accepted the header; false when it refused it, as when headers were already sent.
	 * @throws \InvalidArgumentException  When the name is not an RFC 6265 token, or an option has an invalid value.
	 * @throws \RuntimeException  When the attributes combine SameSite=None with Secure off.
	 * @throws \ValueError  When setcookie() rejects the path or domain, such as a path with ";" or a space.
	 */
	public function delete(string $name, array $options = []): bool {
		$this->assertValidName($name);

		$opts = $this->attributes($options);
		$opts['expires'] = \time() - 3600;

		$scOpts = $this->buildCookieOptions($opts);

		$result = \setcookie($name, '', $scOpts);

		// Unset whatever setcookie() returned, so later code in this request never reads a cookie
		// that is being deleted.
		unset($_COOKIE[$name]);

		return $result;
	}


	/**
	 * Return the attributes of one cookie: The defaults with $overrides on top.
	 *
	 * Behavior:
	 * - Normalizes $overrides:
	 *     - A non-null ttl becomes expires.
	 *     - samesite gets its canonical casing.
	 *     - path gets a leading slash and no trailing slash; null or "" means "/".
	 *     - domain is lowercased without a leading dot; null or "" means host-only (null).
	 * - Checks the combination that would be sent: SameSite=None requires Secure.
	 *
	 * Typical usage:
	 *   $attributes = $this->app->cookie->attributes(['samesite' => 'Strict']);
	 *
	 * @param  array{ttl?:int,expires?:int,path?:string|null,domain?:string|null,secure?:bool,httponly?:bool,samesite?:string}  $overrides  As the $options of set().
	 * @return array<string,mixed>  expires, path, domain (null for host-only), secure, httponly and samesite, plus any unrecognized key of the service options or $overrides as given.
	 * @throws \InvalidArgumentException  When an override has an invalid value.
	 * @throws \RuntimeException  When the result combines SameSite=None with Secure off.
	 * @see \CitOmni\Http\Service\Cookie::set()  What ttl and expires mean.
	 */
	public function attributes(array $overrides = []): array {
		$attributes = $this->mergeAssoc($this->defaults, $this->normalizeOptions($overrides, false));
		$this->assertSameSiteNoneIsSecure($attributes);
		return $attributes;
	}






	// ----------------------------------------------------------------
	// Diagnostics
	// ----------------------------------------------------------------

	/**
	 * Return the default attributes that init() resolved, for tests and diagnostics.
	 *
	 * @return array<string,mixed>  expires, path, domain (null for host-only), secure, httponly and samesite, plus any unrecognized key of the service options as given.
	 */
	public function defaults(): array {
		return $this->defaults;
	}






	// ----------------------------------------------------------------
	// Option normalization
	// ----------------------------------------------------------------


	/**
	 * Normalize an options map and check the type of each attribute.
	 *
	 * Behavior:
	 * - A non-null ttl becomes expires = now + ttl, with a negative ttl counted as 0. It replaces
	 *   any expires in the map.
	 * - samesite, path and domain go through normalizeSameSite(), normalizePath() and
	 *   normalizeDomain().
	 * - secure and httponly must be bools.
	 * - With $forDefaults, a non-null expires becomes an int of at least 0.
	 * - Other keys pass unchanged.
	 *
	 * @param  array<string,mixed>  $in
	 * @param  bool  $forDefaults  True when building the defaults.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException  When samesite, path, domain, secure or httponly has an invalid value.
	 */
	private function normalizeOptions(array $in, bool $forDefaults): array {
		$out = $in;

		if (isset($out['ttl'])) {
			$ttl = (int)$out['ttl'];
			unset($out['ttl']);
			$out['expires'] = \time() + \max(0, $ttl);
		}
		if (\array_key_exists('samesite', $out)) {
			$out['samesite'] = $this->normalizeSameSite($out['samesite']);
		}
		if (\array_key_exists('path', $out)) {
			$out['path'] = $this->normalizePath($out['path']);
		}
		if (\array_key_exists('domain', $out)) {
			$out['domain'] = $this->normalizeDomain($out['domain']);
		}
		foreach (['secure', 'httponly'] as $flag) {
			if (\array_key_exists($flag, $out) && !\is_bool($out[$flag])) {
				throw new \InvalidArgumentException("Cookie attribute '{$flag}' must be a bool; got " . \var_export($out[$flag], true) . '.');
			}
		}

		if ($forDefaults && isset($out['expires'])) {
			$out['expires'] = \max(0, (int)$out['expires']);
		}

		return $out;
	}


	/**
	 * Merge two maps shallowly; a key of $b wins over the same key of $a.
	 *
	 * @param  array<string,mixed>  $a
	 * @param  array<string,mixed>  $b
	 * @return array<string,mixed>
	 */
	private function mergeAssoc(array $a, array $b): array {
		foreach ($b as $k => $v) {
			$a[$k] = $v;
		}
		return $a;
	}




	// ----------------------------------------------------------------
	// Setcookie options
	// ----------------------------------------------------------------

	/**
	 * Build the options array of setcookie() from normalized attributes.
	 *
	 * Behavior:
	 * - A missing attribute becomes expires 0, path "/", secure false, httponly true or samesite Lax.
	 * - Omits domain for a host-only cookie.
	 *
	 * Notes:
	 * - Checks no combination of attributes: Assumes that attributes() has checked them.
	 *
	 * @param  array<string,mixed>  $opts  Normalized attributes.
	 * @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string,domain?:string}
	 */
	private function buildCookieOptions(array $opts): array {

		// Not clamped: An expiry in the past deletes the cookie.
		$expires = (int)($opts['expires'] ?? 0);

		// Assumes normalizePath() has run: A leading slash and no trailing slash.
		$path = (string)($opts['path'] ?? '/');

		// null is host-only. Assumes normalizeDomain() has run: Lowercase, no leading dot.
		$domain = $opts['domain'] ?? null;

		$secure   = (bool)($opts['secure']   ?? false);
		$httponly = (bool)($opts['httponly'] ?? true);

		$samesite = $this->normalizeSameSite($opts['samesite'] ?? 'Lax');

		$out = [
			'expires'  => $expires,
			'path'     => $path,
			'secure'   => $secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		];

		// A host-only cookie has no Domain attribute.
		if ($domain !== null) {
			$out['domain'] = $domain;
		}

		return $out;
	}





	// ----------------------------------------------------------------
	// Validation and HTTPS detection
	// ----------------------------------------------------------------

	/**
	 * Check that a cookie name is an RFC 6265 token.
	 *
	 * @param  string  $name
	 * @return void
	 * @throws \InvalidArgumentException  When the name is empty or has a character outside the token set.
	 */
	private function assertValidName(string $name): void {
		if ($name === '') {
			throw new \InvalidArgumentException('Cookie name cannot be empty.');
		}
		// RFC 6265 token: No separators, control characters or spaces.
		if (!\preg_match('/^[A-Za-z0-9!#$%&\'*+\-.\^_`|~]+$/', $name)) {
			throw new \InvalidArgumentException('Invalid cookie name: ' . $name);
		}
	}


	/**
	 * Return a SameSite value in its canonical casing.
	 *
	 * Behavior:
	 * - Accepts "Lax", "Strict" and "None" in any casing.
	 * - Throws for anything else rather than fall back to Lax, which would hide a typo such as
	 *   "Nnoe" behind a different policy.
	 *
	 * @param  mixed  $val
	 * @return 'Lax'|'Strict'|'None'
	 * @throws \InvalidArgumentException  When the value is not one of the three, in any casing.
	 */
	private function normalizeSameSite(mixed $val): string {
		$s = \is_string($val) ? \ucfirst(\strtolower($val)) : '';
		if (!\in_array($s, ['Lax', 'Strict', 'None'], true)) {
			throw new \InvalidArgumentException('SameSite must be "Lax", "Strict" or "None"; got ' . \var_export($val, true) . '.');
		}
		return $s;
	}


	/**
	 * Return a cookie path with a leading slash and no trailing slash.
	 *
	 * Behavior:
	 * - null and "" mean "/".
	 * - Adds a missing leading slash and drops trailing slashes: "app/" becomes "/app".
	 *
	 * @param  mixed  $val
	 * @return string
	 * @throws \InvalidArgumentException  When the value is neither a string nor null.
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
	 * Return a cookie domain in lowercase without a leading dot, or null for host-only.
	 *
	 * Behavior:
	 * - null, "" and a value of only dots mean host-only.
	 * - Drops leading dots, which RFC 6265 ignores.
	 *
	 * @param  mixed  $val
	 * @return string|null  The domain, or null for host-only.
	 * @throws \InvalidArgumentException  When the value is neither a string nor null.
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
	 * Throw for SameSite=None without Secure, which browsers drop.
	 *
	 * @param  array<string,mixed>  $attributes  Normalized attributes.
	 * @return void
	 * @throws \RuntimeException  When samesite is None and secure is not true.
	 */
	private function assertSameSiteNoneIsSecure(array $attributes): void {
		if (($attributes['samesite'] ?? 'Lax') === 'None' && ($attributes['secure'] ?? false) !== true) {
			throw new \RuntimeException('SameSite=None requires Secure=true');
		}
	}


	/**
	 * Check whether the site uses HTTPS, by its configured public URL or by this request.
	 *
	 * Behavior:
	 * - True when cfg http.base_url or the constant CITOMNI_PUBLIC_ROOT_URL starts with https://,
	 *   in any casing.
	 * - Otherwise the result of Request::isHttps().
	 *
	 * Notes:
	 * - An https URL is a site policy: The result is true even when this request reached PHP over
	 *   HTTP, as behind a TLS proxy that is not trusted, because browsers only see the https side.
	 *
	 * @return bool
	 * @see \CitOmni\Http\Service\Request::isHttps()  Which requests count as HTTPS, behind a proxy included.
	 */
	private function siteUsesHttps(): bool {
		$baseUrl = (string)$this->app->cfg->http->base_url;
		if (\stripos($baseUrl, 'https://') === 0) {
			return true;
		}
		// The constant may be defined independently of http.base_url, and be the only https signal.
		if (\defined('CITOMNI_PUBLIC_ROOT_URL') && \stripos((string)\CITOMNI_PUBLIC_ROOT_URL, 'https://') === 0) {
			return true;
		}
		return $this->app->request->isHttps();
	}






	// ----------------------------------------------------------------
	// Local view
	// ----------------------------------------------------------------

	/**
	 * Check whether a browser would send a cookie with these options back to this request's URL.
	 *
	 * Behavior:
	 * - The host is HTTP_HOST, or SERVER_NAME without it, lowercased and without its port and the
	 *   brackets of an IPv6 literal. An empty host matches no cookie.
	 * - On an IP address or localhost only a host-only cookie matches, because browsers typically
	 *   reject a Domain attribute there.
	 * - On any other host a host-only cookie matches, and a Domain cookie matches its domain and
	 *   every subdomain.
	 * - The path matches when the cookie path is "/" or equals the request path, or when the
	 *   request path starts with the cookie path plus "/": "/app" matches "/app/x" but not "/apple".
	 *
	 * Notes:
	 * - A conservative heuristic, not full RFC 6265 parity.
	 *
	 * @param  array<string,mixed>  $opts  Options as built by buildCookieOptions(); only domain and path are read.
	 * @return bool
	 */
	private function isCookieVisibleHere(array $opts): bool {

		// -- 1. Normalize the request host -------------------------------
		// A bracketed IPv6 literal carries its port after the closing bracket, and the colons
		// inside the brackets belong to the address: "[::1]:8080" -> "::1". Without a closing
		// bracket the host is malformed and stays empty. Otherwise a single colon separates the
		// port: "example.com:8080" -> "example.com". More colons mean a raw IPv6 literal, as from
		// SERVER_NAME, without a port.
		$host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
		if ($host !== '' && $host[0] === '[') {
			$rb = \strpos($host, ']');
			$host = ($rb !== false) ? \substr($host, 1, $rb - 1) : '';
		} elseif (\substr_count($host, ':') === 1) {
			$host = \substr($host, 0, \strpos($host, ':'));
		}
		$host = \strtolower($host);


		// -- 2. Normalize the request path -------------------------------
		// The query string is not part of the path.
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
		$qpos = \strpos($uri, '?');
		$reqPath = ($qpos === false) ? $uri : \substr($uri, 0, $qpos);
		if ($reqPath === '') {
			$reqPath = '/';
		}


		// -- 3. Normalize the cookie scope -------------------------------
		// A null or empty domain means host-only.
		$cookieDomain = isset($opts['domain']) && $opts['domain'] !== '' ? \strtolower((string)$opts['domain']) : null;
		$cookiePath   = (string)($opts['path'] ?? '/');
		if ($cookiePath === '' || $cookiePath[0] !== '/') {
			$cookiePath = '/' . $cookiePath;
		}
		$cookiePath = \rtrim($cookiePath, '/');
		if ($cookiePath === '') {
			$cookiePath = '/';
		}


		// -- 4. Match the domain -----------------------------------------
		$hostIsIpOrLocal = ($host === 'localhost') || \filter_var($host, \FILTER_VALIDATE_IP);

		if ($hostIsIpOrLocal) {
			// Browsers typically reject a Domain attribute on an IP address or localhost.
			$domainOk = ($cookieDomain === null) && ($host !== '');
		} else {
			if ($cookieDomain === null) {
				$domainOk = ($host !== '');
			} else {
				$domainOk = ($host === $cookieDomain) || \str_ends_with($host, '.' . $cookieDomain);
			}
		}


		// -- 5. Match the path -------------------------------------------
		// RFC 6265 path-match: "/app" must not match "/apple".
		$pathOk = ($cookiePath === '/')
			? true
			: ($reqPath === $cookiePath || \str_starts_with($reqPath, $cookiePath . '/'));

		return $domainOk && $pathOk;
	}



}
