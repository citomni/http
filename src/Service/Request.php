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
 * Request: Deterministic HTTP request facade (method, URI, headers, input, JSON, IP).
 *
 * NOTE:
 * - This service does not perform or know about route matching.
 *   The effective routing table is assembled by \CitOmni\Kernel\App
 *   into $app->routes (vendor + providers + app + env, last-wins).
 *   Request's job is only "what came in from the client", not "which controller did we pick".
 *
 * Responsibilities:
 * - Provide a lean, read-only API over PHP SAPI globals for controllers/services.
 *   1) Method and URI resolution (front controller owns routing; no rewriting here).
 *   2) Access to GET/POST maps with null-safe lookups and simple sanitization.
 *   3) JSON body decoding (lazy, single-pass).
 *   4) Case-insensitive header reads and header enumeration.
 *   5) Client IP resolution with optional trusted-proxy awareness.
 *   6) URL parts and predicates: scheme, host, port, path, query string, full URL, base URL, isHttps(), isAjax().
 *
 * Collaborators:
 * - $this->app->cfg->http->trust_proxy       (bool) Toggle proxy-aware scheme/IP detection.
 * - $this->app->cfg->http->trusted_proxies   (array) IP/CIDR allowlist for proxy trust.
 * - $this->app->cfg->http->base_url          (string) Optional absolute base URL.
 * - CITOMNI_PUBLIC_ROOT_URL                  (const) Optional absolute base URL (stage/prod recommended).
 *
 * Configuration keys (read-only):
 * - http.trust_proxy       (bool)   Default: false. When true, honor proxy headers from trusted peers.
 * - http.trusted_proxies   (array)  IPs/CIDRs considered trusted for X-Forwarded-* and Forwarded.
 * - http.base_url          (string) Absolute canonical base URL; alternative to CITOMNI_PUBLIC_ROOT_URL.
 *
 * Behavior:
 * - Method:
 *   1) method(): string - uppercased $_SERVER['REQUEST_METHOD'] (defaults to "GET").
 * - URI and URL parts:
 *   1) uri(): string - raw REQUEST_URI (path + query).
 *   2) path(): string - path only (leading slash kept; no decoding).
 *   3) queryString(): string - raw query without leading '?' (or empty).
 *   4) scheme(): "https"|"http" - proxy-aware if trust_proxy and peer is trusted.
 *   5) host(): string - host without port.
 *   6) port(): int - SERVER_PORT or default by scheme (80/443).
 *   7) fullUrl(): string - scheme://host[:port]/path?query (default ports omitted).
 *   8) baseUrl(): string - CITOMNI_PUBLIC_ROOT_URL > cfg.http.base_url > computed best-effort.
 * - Input and JSON:
 *   1) get()/post(): mixed - array or value by key; null if missing.
 *   2) input(): mixed - source 'auto' (POST unless GET), or explicit 'get'/'post'.
 *   3) sanitize(): ?string - trim + htmlspecialchars (UTF-8); null if missing.
 *   4) json(): ?array - parses body if Content-Type includes application/json; null on empty/invalid.
 * - Headers, cookies, server:
 *   1) header(name): ?string - case-insensitive; supports Content-Type/Length without HTTP_ prefix.
 *      Returns $default (null by default) when the header is missing.
 *   2) headers(): array<string,string> - best-effort enumeration.
 *   3) cookie(name, default): mixed - value or default.
 *   4) server(key, default): mixed - raw $_SERVER access.
 *   5) contentType(): ?string - lowercased Content-Type, or null when absent.
 *   6) referer(): ?string - HTTP referrer if present.
 *   7) getUserAgent(): ?string - user agent if present.
 * - Client IP:
 *   1) ip(?bool $trustProxy = null): string - "CLI" in CLI; public IP if resolvable; "unknown" otherwise.
 *   2) Honors X-Forwarded-For / Forwarded only when trust_proxy is enabled AND REMOTE_ADDR is trusted.
 *   3) getClientIp() is an alias of ip().
 * - Predicates:
 *   1) isHttps(): bool - proxy-aware if allowed and trusted.
 *   2) isSecure(): bool - alias of isHttps().
 *   3) isAjax(): bool - X-Requested-With == XMLHttpRequest.
 *
 * Error handling:
 * - Public APIs return null/empty values for user mistakes (e.g., missing keys, invalid JSON).
 * - Unexpected internal errors bubble to the global error handler (fail fast; no blanket try/catch).
 *
 * Performance:
 * - Read-only facade; no global mutation.
 * - JSON parsing is single-pass and only attempted for JSON content type.
 * - Proxy logic is branch-minimal and executed only when enabled.
 * - (Note) Implementations may read cfg via a wrapper instead of toArray() to avoid materializing large arrays.
 *
 * Typical usage:
 *   $method = $this->app->request->method();      // "GET"
 *   $path   = $this->app->request->path();        // "/contact"
 *   $q      = $this->app->request->get();         // array of query params
 *   $email  = (string)($this->app->request->post('email') ?? '');
 *   if ($this->app->request->isAjax()) { // return compact JSON }
 *   $payload = $this->app->request->json() ?? []; // validate before use
 *   $ip = $this->app->request->ip();              // proxy-aware if cfg says so
 *   $full = $this->app->request->fullUrl();       // absolute URL for logs
 *
 * Examples:
 *   // A) Safe reads with defaults
 *   $page = (int)($this->app->request->get('page') ?? 1);
 *   $term = (string)($this->app->request->sanitize('q') ?? '');
 *
 *   // B) Enable proxy trust at runtime (in bootstrap or env-specific provider)
 *   $this->app->request->setTrustedProxies(['10.0.0.0/8', '192.168.0.0/16']);
 *   $realIp = $this->app->request->ip(true);
 *
 * Notes:
 * - Do not URL-decode path() here; routers/controllers decide decoding policy.
 * - Backwards-compatibility aliases (e.g., getClientIp()) are thin wrappers around canonical methods.
 */
class Request extends BaseService {


/*
 *---------------------------------------------------------------
 * STATE & CONSTANTS - Hot-path fields
 *---------------------------------------------------------------
 * PURPOSE
 *   Keep minimal runtime state; avoid allocations on hot paths.
 *
 * NOTES
 *   - trustedProxies is seeded from cfg in init().
 *   - (Optional) jsonCache memoizes parsed request JSON.
 */

	/** Explicitly configured trusted proxies (CIDR or IP). */
	protected array $trustedProxies = [];
	
	/** Parsed JSON payload cache (null when none/invalid). */
	private ?array $jsonCache = null;

	/** Whether we already attempted to parse php://input (memoization guard). */
	private bool $jsonParsed = false;


/*
 *---------------------------------------------------------------
 * CONSTRUCTION - Bootstrap & cfg seeding
 *---------------------------------------------------------------
 * PURPOSE
 *   Initialize from cfg (trusted proxies), no heavy work here.
 */

	/**
	 * Initial actions
	 */
	protected function init(): void {
		// Seed trusted proxies from cfg if available (keeps setTrustedProxies() useful later).
		/* 
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http->toArray() : [];
		if (!empty($http['trusted_proxies']) && \is_array($http['trusted_proxies'])) {
			$this->trustedProxies = $http['trusted_proxies'];
		}
		*/
		
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http : null;
		if ($http !== null && isset($http->trusted_proxies) && \is_array($http->trusted_proxies)) {
			$this->trustedProxies = $http->trusted_proxies;
		}		
	}



/*
 *---------------------------------------------------------------
 * CORE CONFIG HELPERS - Proxy trust & whitelists
 *---------------------------------------------------------------
 * PURPOSE
 *   Centralize proxy trust checks and whitelist management.
 */

	/**
	 * Whether to trust proxy headers for scheme/IP.
	 */
	protected function trustProxy(): bool {
		/* 
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http->toArray() : [];
		return (bool)($http['trust_proxy'] ?? false);
		*/
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http : null;
		return (bool)($http !== null && isset($http->trust_proxy) ? $http->trust_proxy : false);		
	}


	/**
	 * Set explicit proxy whitelist (CIDR or IP).
	 * @param array<int,string> $proxies
	 */
	public function setTrustedProxies(array $proxies): void {
		$this->trustedProxies = $proxies;
	}


	/**
	 * @return array<int,string>
	 */
	public function getTrustedProxies(): array {
		return $this->trustedProxies;
	}



/*
 *---------------------------------------------------------------
 * METHOD & INPUT - GET/POST accessors and helpers
 *---------------------------------------------------------------
 * PURPOSE
 *   Deterministic reads from superglobals (no mutation).
 */

	/**
	 * HTTP method (uppercased, defaults to GET).
	 */
	public function method(): string {
		$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		return \strtoupper((string)$m);
	}


	/**
	 * $_GET accessor (null key = whole array).
	 * @return mixed
	 */
	public function get(?string $key = null): mixed {
		return $key === null ? $_GET : ($_GET[$key] ?? null);
	}


	/**
	 * $_POST accessor (null key = whole array).
	 * @return mixed
	 */
	public function post(?string $key = null): mixed {
		return $key === null ? $_POST : ($_POST[$key] ?? null);
	}


	/**
	 * Unified input: 'auto' = POST on non-GET else GET.
	 * @param mixed $default
	 * @return mixed
	 */
	public function input(?string $key = null, mixed $default = null, string $source = 'auto'): mixed {
		if ($source === 'auto') {
			$source = ($this->method() === 'GET') ? 'get' : 'post';
		}
		if ($key === null) {
			return $source === 'get' ? $_GET : $_POST;
		}
		return $source === 'get' ? ($_GET[$key] ?? $default) : ($_POST[$key] ?? $default);
	}


	/**
	 * Fast boolean presence check across GET/POST.
	 */
	public function has(string $key): bool {
		return \array_key_exists($key, $_GET) || \array_key_exists($key, $_POST);
	}


	/** True if a POST key exists (even if empty string). */
	public function hasPost(string $key): bool {
		return \array_key_exists($key, $_POST);
	}


	/** True if a file was uploaded for $key (not UPLOAD_ERR_NO_FILE). */
	public function hasFile(string $key): bool {
		return $this->app->files->has($key);
	}


	/**
	 * Whitelist subset from GET/POST (auto source).
	 * @param array<int,string> $keys
	 */
	public function only(array $keys, string $source = 'auto'): array {
		$bag = (array)$this->input(null, [], $source);
		$out = [];
		foreach ($keys as $k) {
			if (\array_key_exists($k, $bag)) {
				$out[$k] = $bag[$k];
			}
		}
		return $out;
	}


	/**
	 * Everything except blacklist keys from GET/POST (auto source).
	 * @param array<int,string> $keys
	 */
	public function except(array $keys, string $source = 'auto'): array {
		$bag = (array)$this->input(null, [], $source);
		foreach ($keys as $k) {
			unset($bag[$k]);
		}
		return $bag;
	}


	/**
	 * Simple sanitizer (trim + htmlspecialchars). Returns null if missing.
	 */
	public function sanitize(string $key, string $method = 'both'): ?string {
		$value = null;
		if ($method === 'get' || $method === 'both') {
			$value = $_GET[$key] ?? null;
		}
		if ($value === null && ($method === 'post' || $method === 'both')) {
			$value = $_POST[$key] ?? null;
		}
		return $value !== null ? \htmlspecialchars(\trim((string)$value), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') : null;
	}



/*
 *---------------------------------------------------------------
 * FILES & JSON - Upload map + JSON payload
 *---------------------------------------------------------------
 * PURPOSE
 *   Safe access to $_FILES and (lazy) JSON body parsing.
 */

	/**
	 * Uploaded file wrapper (or null).
	 *
	 * @param string $key
	 * @return \CitOmni\Http\Service\UploadedFile|null
	 */
	public function file(string $key): ?\CitOmni\Http\Service\UploadedFile {
		return $this->app->files->get($key);
	}


	/** All uploaded files as value objects. */
	public function files(): array {
		return $this->app->files->all();
	}


	/**
	 * Parse the request body as JSON (associative array) with caching.
	 *
	 * Behavior:
	 * - Only parses when Content-Type is JSON: "application/json", "text/json", or any subtype ending in "+json".
	 * - Reads php://input exactly once per request; result is cached for subsequent calls.
	 * - Returns null if body is empty, Content-Type is not JSON, decoding fails, or top-level is not an array/object.
	 * - Large integers are preserved as strings via JSON_BIGINT_AS_STRING (no precision loss).
	 *
	 * Notes:
	 * - Idempotent and side-effect free after first call.
	 * - Does not throw; failures return null by design.
	 *
	 * Typical usage:
	 *   $data = $this->app->request->json();
	 *   if ($data !== null) { /* use $data */ /* }
	 *
	 * @return array<string,mixed>|list<mixed>|null Decoded JSON as assoc array, or null if not available/valid.
	 */
	public function json(): ?array {
		
		// Fast path: already parsed
		if ($this->jsonParsed) {
			return $this->jsonCache;
		}
		$this->jsonParsed = true;

		// Accept standard JSON media types (parameters already stripped in contentType())
		$ct = $this->contentType();
		if ($ct === null) {
			return $this->jsonCache = null;
		}
		if ($ct !== 'application/json' && $ct !== 'text/json' && !\str_ends_with($ct, '+json')) {
			return $this->jsonCache = null; // Not a JSON request
		}

		// Read raw body (once); guard against failures and empty payloads
		$raw = \file_get_contents('php://input');
		if ($raw === false) {
			return $this->jsonCache = null;
		}

		// Strip UTF-8 BOM (rare but happens) and trim whitespace
		$raw = \ltrim($raw, "\xEF\xBB\xBF");
		$raw = \trim($raw);
		if ($raw === '') {
			return $this->jsonCache = null;
		}

		// Decode as associative array; keep big integers as strings
		$decoded = \json_decode($raw, true, 512, \JSON_BIGINT_AS_STRING);

		// If decoding failed or top-level is not array/object, standardize to null
		if (\json_last_error() !== \JSON_ERROR_NONE || !\is_array($decoded)) {
			return $this->jsonCache = null;
		}

		// Cache and return
		return $this->jsonCache = $decoded;
	}




/*
 *---------------------------------------------------------------
 * HEADERS, COOKIES & SERVER - Case-insensitive reads
 *---------------------------------------------------------------
 * PURPOSE
 *   Best-effort header enumeration and direct access helpers.
 */


	/**
	 * Fetch a single HTTP header value (case-insensitive).
	 *
	 * Looks up the header in $_SERVER using the conventional "HTTP_*" key and
	 * returns the first value if present. Handles the two special CGI keys that
	 * are not prefixed with "HTTP_": Content-Type and Content-Length.
	 *
	 * Behavior:
	 * - Normalizes the header name to "HTTP_FOO_BAR".
	 * - Returns $default (which may be null) when the header is not set.
	 * - Does not attempt to parse multi-value headers.
	 *
	 * Typical usage:
	 *   $ctype = $this->app->request->header('Content-Type'); // e.g. "application/json"
	 *
	 * @param string     $name    Header name (case-insensitive), e.g. "Content-Type".
	 * @param string|null $default Value to return when the header is missing (default: null).
	 * @return string|null Header value or $default when not present.
	 */
	public function header(string $name, ?string $default = null): ?string {
		
		// Convert "X-Requested-With" -> "HTTP_X_REQUESTED_WITH" for $_SERVER lookup.
		$key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));

		// Fast path: standard HTTP_* server key present.
		if (isset($_SERVER[$key])) {
			return (string)$_SERVER[$key];
		}

		// Special cases: PHP exposes these without the HTTP_ prefix under CGI/FPM.
		// Return $default (possibly null) when the key is absent.
		return match (\strtolower($name)) {
			'content-type'   => isset($_SERVER['CONTENT_TYPE'])   ? (string)$_SERVER['CONTENT_TYPE']   : $default,
			'content-length' => isset($_SERVER['CONTENT_LENGTH']) ? (string)$_SERVER['CONTENT_LENGTH'] : $default,
			default          => $default,
		};
	}


	/**
	 * All HTTP-like headers (best-effort).
	 * @return array<string,string>
	 */
	public function headers(): array {
		$out = [];
		foreach ($_SERVER as $k => $v) {
			if (\strncmp($k, 'HTTP_', 5) === 0) {
				$name = \strtolower(\str_replace('_', '-', \substr($k, 5)));
				$out[$name] = (string)$v;
			}
		}
		if (isset($_SERVER['CONTENT_TYPE'])) {
			$out['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
		}
		if (isset($_SERVER['CONTENT_LENGTH'])) {
			$out['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
		}
		return $out;
	}


	/**
	 * Cookie accessor.
	 * @return mixed
	 */
	public function cookie(string $key, mixed $default = null): mixed {
		return $_COOKIE[$key] ?? $default;
	}


	/**
	 * Raw server value.
	 * @return mixed
	 */
	public function server(string $key, mixed $default = null): mixed {
		return $_SERVER[$key] ?? $default;
	}


	/**
	 * Get normalized MIME type from Content-Type (lowercased, no parameters).
	 *
	 * @return string|null e.g. "application/json" or null if missing.
	 */
	public function contentType(): ?string {
		$ct = $this->header('Content-Type');
		if ($ct === null) {
			return null;
		}
		$semi = \strpos($ct, ';');
		if ($semi !== false) {
			$ct = \substr($ct, 0, $semi);
		}
		return \strtolower(\trim($ct));
	}


	/**
	 * HTTP referrer (if provided).
	 */
	public function referer(): ?string {
		$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
		return $ref !== '' ? $ref : null;
	}


	/**
	 * User agent string (if provided).
	 */
	public function getUserAgent(): ?string {
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
		return $ua !== null ? (string)$ua : null;
	}



/*
 *---------------------------------------------------------------
 * TRANSPORT & URL PARTS - Scheme/host/port/path/query
 *---------------------------------------------------------------
 * PURPOSE
 *   Compute canonical parts and absolute/full URLs.
 *
 * NOTES
 *   - Host/port may be proxy-aware if trust is enabled + remote is trusted.
 */


	/**
	 * URL scheme ('https' or 'http').
	 */
	public function scheme(): string {
		return $this->isHttps() ? 'https' : 'http';
	}


	/**
	 * Resolve the effective request host (without port).
	 *
	 * Behavior:
	 * - Starts from `HTTP_HOST`, falls back to `SERVER_NAME` when missing.
	 * - If proxy trust is enabled *and* the calling peer is trusted, prefers
	 *   the client-facing host from `X-Forwarded-Host` (first entry) or
	 *   RFC 7239 `Forwarded` header's `host=` parameter.
	 * - Strips any `:port` suffix.
	 * - Handles bracketed IPv6 literals: "[2001:db8::1]:8443" -> "2001:db8::1".
	 * - For safety, the result is sanitized to a conservative character set.
	 *
	 * Notes:
	 * - We do not lowercase or punycode the host; callers can normalize if needed.
	 * - Proxy-derived values are only used when both:
	 *     1) http.trust_proxy is true, and
	 *     2) REMOTE_ADDR is in the trusted proxies allowlist.
	 *
	 * @return string Hostname or IP literal (without port). Falls back to "localhost" if empty.
	 */
	public function host(): string {
		// Base host from server globals
		$h = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');

		// Proxy-aware override when explicitly allowed and the peer is trusted
		if ($this->trustProxy() && $this->clientIpIsTrusted($this->trustedProxies)) {
			
			if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
				
				// e.g. "example.com, proxy.local" -> take first
				$h = \trim(\explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
				
			} elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
				
				// RFC 7239 "Forwarded" can contain multiple hops separated by commas.
				// Example:
				//   Forwarded: for=203.0.113.43;proto=https;host=example.com,
				//              for=192.0.2.1;proto=https;host=proxy.local
				// We only care about the first hop (the client-facing proxy).
				$first = \explode(',', (string)$_SERVER['HTTP_FORWARDED'], 2)[0];

				foreach (\explode(';', $first) as $pair) {
					
					// Each hop is a semicolon-separated list of key=value parameters.
					// Split the current "key=value" pair into its parts. If "=" is missing
					// (malformed input), pad with empty strings so $k/$v are always defined.
					// Then trim both to normalize whitespace around keys/values.
					[$k, $v] = \array_map('trim', \explode('=', $pair, 2) + ['', '']);

					// Parameter names are case-insensitive per RFC 7239.
					// We are only interested in "host" and require a non-empty value.
					if (\strtolower($k) === 'host' && $v !== '') {
						
						// The host parameter may be a quoted-string (e.g., host="example.com:8443").
						// Remove surrounding single/double quotes only; keep the inner content intact.
						// Any ":port" part is handled by later normalization logic.
						$h = \trim($v, "\"'");
						break;
					}
				}
			}
		}

		// Sanitize to a conservative set; keep [] and : for subsequent parsing
		// to avoid header smuggling or control chars.
		$h = (string)\preg_replace('/[^a-zA-Z0-9\.\-\[\]:]/', '', $h);

		// Bracketed IPv6: "[2001:db8::1]:8443" or "[2001:db8::1]"
		if ($h !== '' && $h[0] === '[') {
			$rb = \strpos($h, ']');
			if ($rb !== false) {
				$inside = \substr($h, 1, $rb - 1); // content between '[' and ']'
				return $inside !== '' ? $inside : 'localhost';
			}
			// Malformed bracketed form; fall through to generic handling.
		}

		// If there are multiple ":" characters and no brackets, this is most likely
		// a raw (non-bracketed) IPv6 literal without a port. Per RFC, IPv6 in Host
		// should be bracketed when used with a port, so leave it as-is.
		$firstColon = \strpos($h, ':');
		if ($firstColon !== false) {
			$lastColon = \strrpos($h, ':');
			if ($firstColon !== $lastColon) {
				return $h !== '' ? $h : 'localhost';
			}
			// Single ":" -> treat as host:port and strip the port part.
			$hostPart = \substr($h, 0, $firstColon);
			return $hostPart !== '' ? $hostPart : 'localhost';
		}

		// No port delimiter present; return sanitized host or a safe fallback.
		return $h !== '' ? $h : 'localhost';
	}


	/**
	 * Resolve the effective server port (int).
	 *
	 * Behavior:
	 * - If proxies are trusted AND the peer is on the whitelist:
	 *   1) Use X-Forwarded-Port when present (>0).
	 *   2) Else parse RFC 7239 Forwarded header's host token for ":port"
	 *      (supports bracketed IPv6: "[2001:db8::1]:8443").
	 * - Otherwise fall back to SERVER_PORT.
	 * - If still unknown, infer from isHttps(): 443 (https) or 80 (http).
	 *
	 * Notes:
	 * - Proxy-derived data is only honored when both trustProxy() and
	 *   clientIpIsTrusted(...) are true (defense in depth).
	 *
	 * @return int TCP port number (1..65535) or a sane default (80/443).
	 */
	public function port(): int {
		
		// Proxy-aware path (only if we explicitly trust the proxy and the caller)
		if ($this->trustProxy() && $this->clientIpIsTrusted($this->trustedProxies)) {
			
			// 1) X-Forwarded-Port: Direct and unambiguous
			$xfp = (int)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 0);
			if ($xfp > 0) {
				return $xfp;
			}

			// 2) RFC 7239 Forwarded: host=example.com:8443 or host="[::1]:8443"
			if (!empty($_SERVER['HTTP_FORWARDED'])) {
				
				$first = \explode(',', (string)$_SERVER['HTTP_FORWARDED'], 2)[0];
				
				foreach (\explode(';', $first) as $pair) {
					[$k, $v] = \array_map('trim', \explode('=', $pair, 2) + ['', '']);
					if (\strtolower($k) === 'host' && $v !== '') {
						$v = \trim($v, "\"'"); // strip potential quotes

						// Bracketed IPv6 literal: "[2001:db8::1]:8443"
						if ($v !== '' && $v[0] === '[') {
							$rb = \strpos($v, ']');
							// Port present if we have "]:NNNN"
							if ($rb !== false && \strlen($v) > $rb + 1 && $v[$rb + 1] === ':') {
								return (int)\substr($v, $rb + 2);
							}
							break; // no port found in this token
						}

						// Non-bracketed: Take last ":" as delimiter (covers IPv4/hostnames)
						$pos = \strrpos($v, ':');
						if ($pos !== false) {
							$port = (int)\substr($v, $pos + 1);
							if ($port > 0) {
								return $port;
							}
						}
					}
				}
			}
		}

		// Fallback: Direct server value
		$p = (int)($_SERVER['SERVER_PORT'] ?? 0);
		if ($p > 0) {
			return $p;
		}

		// Last resort: Infer from scheme
		return $this->isHttps() ? 443 : 80;
	}


	/**
	 * Raw request URI (path + query).
	 */
	public function uri(): string {
		return (string)($_SERVER['REQUEST_URI'] ?? '/');
	}


	/**
	 * Path part only (no query string), leading slash retained.
	 */
	public function path(): string {
		$uri = $this->uri();
		$qpos = \strpos($uri, '?');
		return $qpos === false ? $uri : \substr($uri, 0, $qpos);
	}


	/**
	 * Path with app base trimmed, e.g. "/subdir/admin/x.html" -> "/admin/x.html".
	 */
	public function pathWithBaseTrimmed(): string {
		$path = $this->path();
		$base = (string)\parse_url($this->baseUrl(), \PHP_URL_PATH);
		$base = \rtrim($base, '/');
		if ($base !== '' && \str_starts_with($path, $base)) {
			$trimmed = \substr($path, \strlen($base));
			return $trimmed !== '' ? $trimmed : '/';
		}
		return $path;
	}


	/**
	 * Query string without '?' or empty string.
	 */
	public function queryString(): string {
		return (string)($_SERVER['QUERY_STRING'] ?? '');
	}


	/**
	 * Return all query parameters (shallow copy of $_GET).
	 *
	 * @return array<string,mixed>
	 */
	public function queryAll(): array {
		return $_GET;
	}


	/**
	 * Full URL (scheme://host[:port]/path?query). Omits default ports.
	 */
	public function fullUrl(): string {
		$scheme = $this->scheme();
		$host   = $this->host();
		$port   = $this->port();
		$uri    = $this->uri();

		$portPart = '';
		if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
			$portPart = ':' . $port;
		}
		return $scheme . '://' . $host . $portPart . $uri;
	}


	/**
	 * Base URL.
	 * Priority:
	 * 1) CITOMNI_PUBLIC_ROOT_URL constant (stage/prod recommended)
	 * 2) $this->app->cfg->http->base_url (absolute)
	 * 3) Computed from current request (best-effort)
	 */
	public function baseUrl(): string {
		
		if (\defined('CITOMNI_PUBLIC_ROOT_URL') && \CITOMNI_PUBLIC_ROOT_URL !== '') {
			return \rtrim((string)\CITOMNI_PUBLIC_ROOT_URL, '/') . '/';
		}
		
		/* 
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http->toArray() : [];
		if (!empty($http['base_url'])) {
			return \rtrim((string)$http['base_url'], '/') . '/';
		}
		*/		
		$http = isset($this->app->cfg->http) ? $this->app->cfg->http : null;
		if ($http !== null && isset($http->base_url) && $http->base_url !== '') {
			return \rtrim((string)$http->base_url, '/') . '/';
		}
		
		// Fallback: scheme + host + (optional port) + detected webroot (shallow, but cheap)
		$scheme = $this->scheme();
		$host   = $this->host();
		$port   = $this->port();
		$portPart = '';
		if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
			$portPart = ':' . $port;
		}
		// Best-effort: strip REQUEST_URI to directory (no disk IO)
		$uri  = $this->uri();
		$dir  = \rtrim(\dirname($uri), '/\\');
		$dir  = $dir === '' ? '' : $dir . '/';
		return $scheme . '://' . $host . $portPart . $dir;
	}




/*
 *---------------------------------------------------------------
 * PREDICATES - Transport and request intent
 *---------------------------------------------------------------
 * PURPOSE
 *   Quick booleans often used in controllers/services.
 */

	/**
	 * HTTPS detection (proxy-aware if enabled in cfg or forced).
	 */
	public function isHttps(): bool {
		// Direct server signals:
		$https = $_SERVER['HTTPS'] ?? '';
		if ($https !== '' && \strtolower((string)$https) !== 'off') {
			return true;
		}
		if (\strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
			return true;
		}
		if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
			return true;
		}

		// Proxy-aware path if allowed and remote is trusted:
		if (!$this->trustProxy()) {
			return false;
		}
		if (!$this->clientIpIsTrusted($this->trustedProxies)) {
			return false;
		}

		// RFC 7239 Forwarded: proto=
		$fw = $_SERVER['HTTP_FORWARDED'] ?? '';
		if ($fw !== '') {
			$first = \explode(',', (string)$fw, 2)[0];
			foreach (\explode(';', $first) as $pair) {
				[$k, $v] = \array_map('trim', \explode('=', $pair, 2) + ['', '']);
				if (\strtolower($k) === 'proto' && \strtolower(\trim($v, "\"'")) === 'https') {
					return true;
				}
			}
		}

		$xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
		if ($xfp !== '' && \strtolower(\trim(\explode(',', (string)$xfp, 2)[0])) === 'https') {
			return true;
		}
		$xfs = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
		if ($xfs !== '' && \strtolower((string)$xfs) === 'on') {
			return true;
		}
		$xfScheme = $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? '';
		if ($xfScheme !== '' && \strtolower((string)$xfScheme) === 'https') {
			return true;
		}
		$feh = $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '';
		if ($feh !== '' && \in_array(\strtolower((string)$feh), ['on','1'], true)) {
			return true;
		}
		$xurl = $_SERVER['HTTP_X_URL_SCHEME'] ?? '';
		if ($xurl !== '' && \strtolower((string)$xurl) === 'https') {
			return true;
		}
		$cfv = $_SERVER['HTTP_CF_VISITOR'] ?? '';
		if ($cfv !== '') {
			$j = \json_decode((string)$cfv, true);
			if (\is_array($j) && \strtolower((string)($j['scheme'] ?? '')) === 'https') {
				return true;
			}
		}
		return false;
	}


	/**
	 * Alias for isHttps().
	 */
	public function isSecure(): bool {
		return $this->isHttps();
	}


	/**
	 * True if X-Requested-With=XMLHttpRequest (AJAX).
	 */
	public function isAjax(): bool {
		return \strtolower((string)($this->header('X-Requested-With') ?? '')) === 'xmlhttprequest';
	}





/*
 *---------------------------------------------------------------
 * CLIENT IP (PROXY-AWARE) - Canonical remote address
 *---------------------------------------------------------------
 * PURPOSE
 *   Resolve client IP with optional trusted-proxy logic.
 */

	/**
	 * Client IP address. If $trustProxy is null, uses http.trust_proxy.
	 * Honors proxy headers only when enabled AND REMOTE_ADDR is trusted.
	 */
	public function ip(?bool $trustProxy = null): string {
		if (\PHP_SAPI === 'cli') {
			return 'CLI';
		}

		$server     = $_SERVER;
		$remoteAddr = (string)($server['REMOTE_ADDR'] ?? '');

		// Decide proxy behavior
		$useProxy = $trustProxy ?? $this->trustProxy();
		$proxyAllowed = $useProxy && $remoteAddr !== '' && $this->clientIpIsTrusted($this->trustedProxies);

		if ($proxyAllowed) {
			// Canonical client IP from X-Forwarded-For (first public)
			$xff = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
			if ($xff !== '') {
				$forwardedIps = \array_map('trim', \explode(',', $xff));
				foreach ($forwardedIps as $candidate) {
					if ($this->isPublicIp($candidate)) {
						return $candidate;
					}
				}
			}
			// Fall back to other headers (rare)
			$headers = [
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED',
				'HTTP_X_CLUSTER_CLIENT_IP',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED'
			];
			foreach ($headers as $h) {
				if (!empty($server[$h])) {
					$ipList = \explode(',', (string)$server[$h]);
					foreach ($ipList as $cand) {
						$cand = \trim($cand);
						if ($this->isPublicIp($cand)) {
							return $cand;
						}
					}
				}
			}
		}

		// No proxy or not trusted -> REMOTE_ADDR if public, else unknown
		if ($this->isPublicIp($remoteAddr)) {
			return $remoteAddr;
		}
		return 'unknown';
	}


	/**
	 * Backwards-compatible alias for ip().
	 */
	public function getClientIp(?bool $trustProxy = null): string {
		return $this->ip($trustProxy);
	}



/*
 *---------------------------------------------------------------
 * INTERNALS - Helpers & predicates (private/protected)
 *---------------------------------------------------------------
 * PURPOSE
 *   Keep low-level helpers isolated and easy to audit.
 */

	/**
	 * Check if current REMOTE_ADDR is in the given trusted list.
	 *
	 * Iterates the whitelist and calls ipInCidr() for each entry; empty or
	 * missing REMOTE_ADDR fails closed.
	 *
	 * @param array<int,string> $whitelist CIDR blocks or single IPs (IPv4/IPv6).
	 * @return bool True if the client IP is trusted.
	 */
	protected function clientIpIsTrusted(array $whitelist): bool {
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		if ($ip === '') {
			return false;
		}
		foreach ($whitelist as $cidr) {
			if ($this->ipInCidr($ip, (string)$cidr)) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Test whether an IP address belongs to a CIDR block (or equals a single IP).
	 *
	 * Supports IPv4 and IPv6. Returns false on invalid inputs or address-family
	 * mismatch (e.g., IPv4 candidate vs IPv6 CIDR). This method treats a CIDR
	 * without "/" as an exact-IP match.
	 *
	 * Behavior:
	 * - Uses inet_pton() for binary-safe comparisons (no string parsing pitfalls).
	 * - Validates mask length against address family (0..32 for IPv4, 0..128 for IPv6).
	 * - Builds a byte mask and compares the network portions.
	 *
	 * Typical usage:
	 *   $ok = $this->ipInCidr('203.0.113.10', '203.0.113.0/24');   // true
	 *   $ok = $this->ipInCidr('2001:db8::1',  '2001:db8::/32');    // true
	 *   $ok = $this->ipInCidr('203.0.113.10', '2001:db8::/32');    // false (family mismatch)
	 *
	 * @param string $ip   Candidate IP address (IPv4 or IPv6).
	 * @param string $cidr CIDR like "192.168.0.0/16" or a single IP literal.
	 * @return bool True if $ip is contained in $cidr (or equals it when no mask given).
	 */
	protected function ipInCidr(string $ip, string $cidr): bool {
		// Convert the candidate IP to packed binary form.
		// inet_pton() returns false for invalid input.
		$ipBin = @\inet_pton($ip);
		if ($ipBin === false) {
			return false; // Not a valid IP address.
		}

		// If $cidr has no "/", treat it as a single IP literal and compare directly.
		if (!\str_contains($cidr, '/')) {
			$single = @\inet_pton($cidr);
			// Equal only if both parse and their binary forms match exactly.
			return $single !== false && $single === $ipBin;
		}

		// Split "subnet/maskBits" (e.g., "192.168.0.0/16").
		[$subnet, $maskBitsRaw] = \explode('/', $cidr, 2);

		// Convert the subnet to packed binary. Must be same address family as $ipBin.
		$subBin = @\inet_pton($subnet);
		if ($subBin === false || \strlen($subBin) !== \strlen($ipBin)) {
			return false; // Invalid subnet or IPv4/IPv6 family mismatch.
		}

		// Normalize and validate mask length against the address family.
		$maskBits = (int)\trim((string)$maskBitsRaw); // e.g., 16 for IPv4 or 64 for IPv6
		$addrLen  = \strlen($ipBin);      // 4 bytes for IPv4, 16 bytes for IPv6.
		$maxBits  = $addrLen * 8;         // 32 or 128.
		if ($maskBits < 0 || $maskBits > $maxBits) {
			return false; // Out-of-range mask (e.g., /40 on IPv4).
		}

		// Build a byte mask with $maskBits leading 1s.
		// Example (IPv4, /20): 11111111 11111111 11110000 00000000
		$fullBytes = intdiv($maskBits, 8); // Number of 0xFF bytes.
		$remBits   = $maskBits % 8;        // Remaining high bits in the next byte.

		// Start with the full 0xFF bytes.
		$mask = $fullBytes > 0 ? \str_repeat("\xFF", $fullBytes) : '';

		// Append the partial byte if needed (e.g., remBits=3 -> 11100000).
		if ($remBits > 0) {
			$mask .= \chr((0xFF << (8 - $remBits)) & 0xFF);
		}

		// Pad with zero bytes to match the full address length.
		if (\strlen($mask) < $addrLen) {
			$mask .= \str_repeat("\x00", $addrLen - \strlen($mask));
		}

		// Apply the mask and compare network portions.
		// String bitwise AND works byte-wise on packed binary strings.
		return (($ipBin & $mask) === ($subBin & $mask));
	}


	/**
	 * Determine if an IP is public (not private/reserved).
	 *
	 * Uses FILTER_VALIDATE_IP with NO_PRIV_RANGE and NO_RES_RANGE flags.
	 *
	 * @param string $ip IPv4/IPv6 address.
	 * @return bool True if syntactically valid and public.
	 */
	protected function isPublicIp(string $ip): bool {
		return (bool)\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
	}
		
		


	
}
