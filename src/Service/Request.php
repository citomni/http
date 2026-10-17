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
 * Request: HTTP service that reads the current request from PHP's request globals.
 *
 * Gives controllers and services one read-only view of what the client sent, including which
 * proxy headers to believe. Matching the request to a route belongs to Router; decoding paths
 * and validating input belong to the caller. Behind a trusted proxy, Request believes the scheme,
 * host and port headers as they arrive; removing those the client sent belongs to the proxy.
 *
 * Rules:
 * - Never write to $_SERVER, $_GET, $_POST or $_COOKIE.
 * - Derive the scheme, a URL part or the client IP from a proxy header only behind a trusted
 *   proxy: Proxy trust is on (cfg http.trust_proxy, or the caller's choice where a method
 *   takes it as an argument) and REMOTE_ADDR matches an entry of the trusted proxy list
 *   (cfg http.trusted_proxies, unless replaced through setTrustedProxies()).
 * - Answer missing or malformed client input with null, a default or a documented fallback;
 *   never throw on it.
 * - Read php://input only in json(), and only once per instance.
 */
class Request extends BaseService {


	// ----------------------------------------------------------------
	// State
	// ----------------------------------------------------------------

	/** @var list<string> Trusted proxy list: IP addresses and CIDR blocks, IPv4 or IPv6; empty trusts no proxy. */
	protected array $trustedProxies = [];
	
	/** Decoded JSON body as json() returns it; meaningful only once jsonParsed is true. */
	private ?array $jsonCache = null;

	/** Whether json() has read the body, so jsonCache holds its result. */
	private bool $jsonParsed = false;


	// ----------------------------------------------------------------
	// Setup
	// ----------------------------------------------------------------

	/**
	 * Take the trusted proxy list from cfg http.trusted_proxies.
	 *
	 * Behavior:
	 * - An empty list, or a map instead of a list, leaves no proxy trusted.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// An empty list is a Cfg node, not an array, and leaves the list empty.
		$proxies = $this->app->cfg->http->trusted_proxies;

		if (\is_array($proxies)) {
			$this->trustedProxies = $proxies;
		}
	}



	// ----------------------------------------------------------------
	// Proxy trust
	// ----------------------------------------------------------------

	/**
	 * Return cfg http.trust_proxy as a bool.
	 *
	 * @return bool
	 */
	protected function trustProxy(): bool {
		return (bool)$this->app->cfg->http->trust_proxy;
	}


	/**
	 * Replace the trusted proxy list, including the one init() took from cfg.
	 *
	 * @param  list<string>  $proxies  IP addresses and CIDR blocks, IPv4 or IPv6. An entry that is neither matches nothing; an empty list trusts no proxy.
	 * @return void
	 * @see \CitOmni\Http\Service\Request::ipInCidr()  How an entry matches an address.
	 */
	public function setTrustedProxies(array $proxies): void {
		$this->trustedProxies = $proxies;
	}


	/**
	 * Return the trusted proxy list.
	 *
	 * @return list<string>
	 */
	public function getTrustedProxies(): array {
		return $this->trustedProxies;
	}



	// ----------------------------------------------------------------
	// Method and input
	// ----------------------------------------------------------------

	/**
	 * Return the request method, uppercased.
	 *
	 * @return string  "GET" when REQUEST_METHOD is missing.
	 */
	public function method(): string {
		$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		return \strtoupper((string)$m);
	}


	/**
	 * Return the declared request body length in bytes.
	 *
	 * Behavior:
	 * - Reads CONTENT_LENGTH only, never the body.
	 * - Null when the length is missing, neither an int nor a string of decimal digits,
	 *   negative, or larger than PHP_INT_MAX.
	 * - Zero is a valid length; leading zeroes are accepted.
	 *
	 * @return int|null  Non-negative byte count, or null when the length is unavailable or invalid.
	 */
	public function contentLength(): ?int {
		$value = $_SERVER['CONTENT_LENGTH'] ?? null;
		if (\is_int($value)) {
			return $value >= 0 ? $value : null;
		}
		if (!\is_string($value) || $value === '' || \strspn($value, '0123456789') !== \strlen($value)) {
			return null;
		}

		// Avoid silently overflowing the platform's integer range.
		$digits = \ltrim($value, '0');
		$max = (string)\PHP_INT_MAX;
		$length = \strlen($digits);
		$maxLength = \strlen($max);
		if ($length > $maxLength || ($length === $maxLength && \strcmp($digits, $max) > 0)) {
			return null;
		}

		return (int)$value;
	}


	/**
	 * Return PHP's post_max_size in bytes, parsed as PHP parses INI quantities.
	 *
	 * Notes:
	 * - Emits no warning for an invalid value: PHP already interpreted it, with its own
	 *   fallback, when it read the INI setting at startup.
	 *
	 * @return int  Byte limit; 0 or less means PHP enforces no limit.
	 */
	public function postMaxSize(): int {
		return @\ini_parse_quantity((string)\ini_get('post_max_size'));
	}


	/**
	 * Check whether the declared length of a POST body exceeds post_max_size.
	 *
	 * Behavior:
	 * - True only for a POST request whose contentLength() is above a positive postMaxSize(),
	 *   whatever the Content-Type.
	 * - False for any other method, for a missing, invalid or zero length, and for a body of
	 *   exactly the limit.
	 *
	 * @return bool
	 */
	public function exceedsPostMaxSize(): bool {
		if ($this->method() !== 'POST') {
			return false;
		}
		$contentLength = $this->contentLength();
		if ($contentLength === null || $contentLength === 0) {
			return false;
		}

		$limit = $this->postMaxSize();
		return $limit > 0 && $contentLength > $limit;
	}


	/**
	 * Return one query parameter, or all of them.
	 *
	 * @param  string|null  $key  Parameter name; null for the whole $_GET array.
	 * @return mixed  The raw value as PHP parsed it (a string or an array), the whole $_GET array, or null when the key is missing.
	 */
	public function get(?string $key = null): mixed {
		return $key === null ? $_GET : ($_GET[$key] ?? null);
	}


	/**
	 * Return one form body field, or all of them.
	 *
	 * @param  string|null  $key  Field name; null for the whole $_POST array.
	 * @return mixed  The raw value as PHP parsed it (a string or an array), the whole $_POST array, or null when the key is missing.
	 */
	public function post(?string $key = null): mixed {
		return $key === null ? $_POST : ($_POST[$key] ?? null);
	}


	/**
	 * Return one input value, or all input, from the query or the form body.
	 *
	 * Behavior:
	 * - Source 'auto' reads the query for a GET request and the form body for every other
	 *   method, HEAD included.
	 * - Source 'get' reads the query; any other value reads the form body.
	 *
	 * @param  string|null  $key  Input name; null for the whole source array.
	 * @param  mixed  $default  Returned when the key is missing or its value is null.
	 * @param  string  $source  'auto', 'get' or 'post'.
	 * @return mixed  The raw value, the whole source array, or $default.
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
	 * Check whether the query or the form body has a key, whatever its value.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has(string $key): bool {
		return \array_key_exists($key, $_GET) || \array_key_exists($key, $_POST);
	}


	/**
	 * Check whether the form body has a key, whatever its value.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function hasPost(string $key): bool {
		return \array_key_exists($key, $_POST);
	}


	/**
	 * Return the input entries whose keys are listed.
	 *
	 * @param  list<string>  $keys  Keys to keep; a missing key is left out of the result.
	 * @param  string  $source  As in input(): 'auto', 'get' or 'post'.
	 * @return array<string,mixed>  The kept entries, in the order of $keys.
	 * @see \CitOmni\Http\Service\Request::input()  Which source 'auto' reads.
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
	 * Return all input entries except those whose keys are listed.
	 *
	 * @param  list<string>  $keys  Keys to leave out.
	 * @param  string  $source  As in input(): 'auto', 'get' or 'post'.
	 * @return array<string,mixed>
	 * @see \CitOmni\Http\Service\Request::input()  Which source 'auto' reads.
	 */
	public function except(array $keys, string $source = 'auto'): array {
		$bag = (array)$this->input(null, [], $source);
		foreach ($keys as $k) {
			unset($bag[$k]);
		}
		return $bag;
	}


	/**
	 * Return one input value trimmed and HTML-escaped.
	 *
	 * Behavior:
	 * - 'both' reads the query first and falls through to the form body.
	 * - Only string values count. Array-shaped input (PHP parses "?q[]=x" into an array) is
	 *   treated as missing, so 'both' falls through to the form body.
	 * - Escapes with htmlspecialchars(), ENT_QUOTES | ENT_SUBSTITUTE, UTF-8.
	 *
	 * @param  string  $key  Input name.
	 * @param  string  $method  'get', 'post' or 'both'; any other value reads nothing.
	 * @return string|null  Trimmed, escaped value, or null when no string value exists.
	 */
	public function sanitize(string $key, string $method = 'both'): ?string {
		$value = null;
		if ($method === 'get' || $method === 'both') {
			$value = $_GET[$key] ?? null;
		}
		if (!\is_string($value) && ($method === 'post' || $method === 'both')) {
			$value = $_POST[$key] ?? null;
		}
		return \is_string($value) ? \htmlspecialchars(\trim($value), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') : null;
	}



	// ----------------------------------------------------------------
	// JSON body
	// ----------------------------------------------------------------


	/**
	 * Return the request body decoded as JSON.
	 *
	 * Behavior:
	 * - Decodes only when contentType() is "application/json", "text/json" or ends in "+json".
	 * - A UTF-8 BOM at the start of the body and whitespace around the JSON are ignored.
	 * - Objects become associative arrays; integers too large for an int stay strings.
	 * - Null when the media type is not JSON, the body is empty or unreadable, the JSON is
	 *   invalid or nested 512 or more levels deep, or its top level is not an object or array.
	 * - Reads php://input on the first call only; later calls return the same result.
	 *
	 * Typical usage:
	 *   $payload = $this->app->request->json() ?? [];
	 *
	 * @return array<mixed>|null  Decoded object or array, or null when no valid JSON body exists.
	 */
	public function json(): ?array {
		
		if ($this->jsonParsed) {
			return $this->jsonCache;
		}
		$this->jsonParsed = true;

		// contentType() has already stripped the parameters.
		$ct = $this->contentType();
		if ($ct === null) {
			return $this->jsonCache = null;
		}
		if ($ct !== 'application/json' && $ct !== 'text/json' && !\str_ends_with($ct, '+json')) {
			return $this->jsonCache = null;
		}

		$raw = \file_get_contents('php://input');
		if ($raw === false) {
			return $this->jsonCache = null;
		}

		$raw = \ltrim($raw, "\xEF\xBB\xBF");
		$raw = \trim($raw);
		if ($raw === '') {
			return $this->jsonCache = null;
		}

		$decoded = \json_decode($raw, true, 512, \JSON_BIGINT_AS_STRING);

		if (\json_last_error() !== \JSON_ERROR_NONE || !\is_array($decoded)) {
			return $this->jsonCache = null;
		}

		return $this->jsonCache = $decoded;
	}




	// ----------------------------------------------------------------
	// Headers, cookies and server values
	// ----------------------------------------------------------------


	/**
	 * Return one request header.
	 *
	 * Behavior:
	 * - The name is case-insensitive.
	 * - Content-Type and Content-Length are also found without the HTTP_ prefix, as CGI and
	 *   FPM provide them.
	 * - Returns the value as the server provides it; a repeated header is not split.
	 *
	 * Typical usage:
	 *   $token = $this->app->request->header('X-CSRF-Token');
	 *
	 * @param  string  $name  Header name, such as "Content-Type".
	 * @param  string|null  $default  Returned when the header is missing.
	 * @return string|null
	 */
	public function header(string $name, ?string $default = null): ?string {
		
		$key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));

		if (isset($_SERVER[$key])) {
			return (string)$_SERVER[$key];
		}

		// PHP exposes these two without the HTTP_ prefix under CGI/FPM.
		return match (\strtolower($name)) {
			'content-type'   => isset($_SERVER['CONTENT_TYPE'])   ? (string)$_SERVER['CONTENT_TYPE']   : $default,
			'content-length' => isset($_SERVER['CONTENT_LENGTH']) ? (string)$_SERVER['CONTENT_LENGTH'] : $default,
			default          => $default,
		};
	}


	/**
	 * Return every request header the server provides.
	 *
	 * Behavior:
	 * - Names are lowercased with "-" between words, such as "x-requested-with".
	 * - Includes content-type and content-length when the server provides them without the
	 *   HTTP_ prefix.
	 *
	 * @return array<string,string>  Header name to value.
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
	 * Return one request cookie.
	 *
	 * @param  string  $key  Cookie name.
	 * @param  mixed  $default  Returned when the cookie is missing or null.
	 * @return mixed  The raw value from $_COOKIE, or $default.
	 */
	public function cookie(string $key, mixed $default = null): mixed {
		return $_COOKIE[$key] ?? $default;
	}


	/**
	 * Return one raw $_SERVER value.
	 *
	 * @param  string  $key  $_SERVER key, such as "REMOTE_ADDR".
	 * @param  mixed  $default  Returned when the key is missing or null.
	 * @return mixed
	 */
	public function server(string $key, mixed $default = null): mixed {
		return $_SERVER[$key] ?? $default;
	}


	/**
	 * Return the media type of the Content-Type header.
	 *
	 * @return string|null  Lowercased and trimmed, without parameters, such as "application/json"; null when the header is missing.
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
	 * Return the Referer header.
	 *
	 * @return string|null  Null when the header is missing or empty.
	 */
	public function referer(): ?string {
		$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
		return $ref !== '' ? $ref : null;
	}


	/**
	 * Return the User-Agent header.
	 *
	 * @return string|null  Null when the header is missing; an empty header stays "".
	 */
	public function getUserAgent(): ?string {
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
		return $ua !== null ? (string)$ua : null;
	}



	// ----------------------------------------------------------------
	// URL parts
	// ----------------------------------------------------------------


	/**
	 * Return the scheme of the request.
	 *
	 * @return string  "https" when isHttps() is true, otherwise "http".
	 * @see \CitOmni\Http\Service\Request::isHttps()  Which signals count as HTTPS.
	 */
	public function scheme(): string {
		return $this->isHttps() ? 'https' : 'http';
	}


	/**
	 * Return the host the client addressed, without the port.
	 *
	 * Behavior:
	 * - Reads the Host header, or SERVER_NAME when the header is missing.
	 * - Behind a trusted proxy, a proxy header wins, in this order:
	 *     1) The first entry of X-Forwarded-Host, when the header is not empty.
	 *     2) The host parameter of the first hop of Forwarded (RFC 7239), quotes removed,
	 *        when it is present and not empty.
	 * - Keeps only ASCII letters, digits, ".", "-", "[", "]" and ":", so control characters
	 *   and other header text never pass.
	 * - Strips a port. A bracketed IPv6 literal is returned without brackets
	 *   ("[2001:db8::1]:8443" gives "2001:db8::1"); an unbracketed one is returned whole.
	 * - "localhost" when no host is left.
	 *
	 * Notes:
	 * - Neither lowercased nor converted to punycode.
	 *
	 * @return string  Host name or IP literal.
	 */
	public function host(): string {
		$h = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');

		if ($this->trustProxy() && $this->clientIpIsTrusted($this->trustedProxies)) {
			
			if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
				
				$h = \trim(\explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
				
			} elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
				
				// RFC 7239: Hops are separated by commas and parameters by semicolons. Only the
				// first hop counts: Assumes the proxy that faces the client replaced any Forwarded
				// header the client sent.
				$first = \explode(',', (string)$_SERVER['HTTP_FORWARDED'], 2)[0];

				foreach (\explode(';', $first) as $pair) {
					
					// A pair without "=" is padded, so $k and $v are always defined.
					[$k, $v] = \array_map('trim', \explode('=', $pair, 2) + ['', '']);

					// Parameter names are case-insensitive per RFC 7239.
					if (\strtolower($k) === 'host' && $v !== '') {
						
						// The value may be a quoted-string; a port is stripped below.
						$h = \trim($v, "\"'");
						break;
					}
				}
			}
		}

		// Brackets and ":" stay for the IPv6 and port parsing below.
		$h = (string)\preg_replace('/[^a-zA-Z0-9\.\-\[\]:]/', '', $h);

		// Bracketed IPv6: "[2001:db8::1]:8443" or "[2001:db8::1]"
		if ($h !== '' && $h[0] === '[') {
			$rb = \strpos($h, ']');
			if ($rb !== false) {
				$inside = \substr($h, 1, $rb - 1);
				return $inside !== '' ? $inside : 'localhost';
			}
			// Malformed bracketed form; fall through to generic handling.
		}

		// Several ":" without brackets: An IPv6 literal without a port. A Host header must
		// bracket IPv6 when it adds a port, so the value is left as it is.
		$firstColon = \strpos($h, ':');
		if ($firstColon !== false) {
			$lastColon = \strrpos($h, ':');
			if ($firstColon !== $lastColon) {
				return $h !== '' ? $h : 'localhost';
			}
			// One ":" is host:port.
			$hostPart = \substr($h, 0, $firstColon);
			return $hostPart !== '' ? $hostPart : 'localhost';
		}

		return $h !== '' ? $h : 'localhost';
	}


	/**
	 * Return the port the client addressed.
	 *
	 * Behavior:
	 * - Behind a trusted proxy, in this order:
	 *     1) X-Forwarded-Port, when its integer value is positive.
	 *     2) The port in the host parameter of the first hop of Forwarded (RFC 7239), also
	 *        after a bracketed IPv6 literal ("[2001:db8::1]:8443").
	 * - Otherwise SERVER_PORT when it is positive. 80 counts as 443 when isHttps() is true.
	 * - Otherwise 443 when isHttps() is true, else 80.
	 *
	 * Notes:
	 * - Behind a TLS-terminating proxy that is not trusted, SERVER_PORT is the port the proxy
	 *   connects to, not the one the client used.
	 * - A port from a proxy header or SERVER_PORT is not checked against 65535.
	 *
	 * @return int  Positive port number.
	 */
	public function port(): int {
		
		if ($this->trustProxy() && $this->clientIpIsTrusted($this->trustedProxies)) {
			
			$xfp = (int)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 0);
			if ($xfp > 0) {
				return $xfp;
			}

			// RFC 7239 host parameter: host=example.com:8443 or host="[::1]:8443"
			if (!empty($_SERVER['HTTP_FORWARDED'])) {
				
				$first = \explode(',', (string)$_SERVER['HTTP_FORWARDED'], 2)[0];
				
				foreach (\explode(';', $first) as $pair) {
					[$k, $v] = \array_map('trim', \explode('=', $pair, 2) + ['', '']);
					if (\strtolower($k) === 'host' && $v !== '') {
						$v = \trim($v, "\"'");

						// Bracketed IPv6 literal: "[2001:db8::1]:8443"
						if ($v !== '' && $v[0] === '[') {
							$rb = \strpos($v, ']');
							if ($rb !== false && \strlen($v) > $rb + 1 && $v[$rb + 1] === ':') {
								$port = (int)\substr($v, $rb + 2);
								if ($port > 0) {
									return $port;
								}
							}
							break;
						}

						// Not bracketed: The last ":" separates the port of an IPv4 address or name.
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

		$p = (int)($_SERVER['SERVER_PORT'] ?? 0);
		if ($p > 0) {
			$isHttps = $this->isHttps();

			// A TLS terminator may reach PHP on port 80 while the client used 443.
			if ($isHttps && $p === 80) {
				return 443;
			}
			if (!$isHttps && $p === 443) {
				return 80;
			}

			return $p;
		}

		return $this->isHttps() ? 443 : 80;
	}


	/**
	 * Return the request target as the client sent it.
	 *
	 * @return string  REQUEST_URI, path and query, not decoded; "/" when it is missing.
	 */
	public function uri(): string {
		return (string)($_SERVER['REQUEST_URI'] ?? '/');
	}


	/**
	 * Return the path of the request target, without the query string.
	 *
	 * @return string  Not decoded; "/" when REQUEST_URI is missing.
	 */
	public function pathRaw(): string {
		$uri = $this->uri();
		$qpos = \strpos($uri, '?');
		return $qpos === false ? $uri : \substr($uri, 0, $qpos);
	}


	/**
	 * Return the path relative to the app's root.
	 *
	 * Behavior:
	 * - Removes the path of baseUrl() from the front of pathRaw(), so "/app/users" gives
	 *   "/users" for an app at /app; the root itself gives "/".
	 * - Returns pathRaw() unchanged when the app is at the web root or the path does not start
	 *   with the app's path.
	 *
	 * Notes:
	 * - The app's path is matched as a string prefix, not by segment: For an app at /app,
	 *   "/apple" gives "le".
	 *
	 * @return string  Not decoded.
	 * @see \CitOmni\Http\Service\Request::baseUrl()  Where the app's root comes from.
	 */
	public function pathFromAppRoot(): string {
		$path = $this->pathRaw();
		$base = (string)\parse_url($this->baseUrl(), \PHP_URL_PATH);
		$base = \rtrim($base, '/');

		if ($base !== '' && \str_starts_with($path, $base)) {
			$trimmed = \substr($path, \strlen($base));
			return $trimmed !== '' ? $trimmed : '/';
		}

		return $path;
	}


	/**
	 * Return the raw query string.
	 *
	 * @return string  QUERY_STRING without the "?", not decoded; "" when it is missing.
	 */
	public function queryString(): string {
		return (string)($_SERVER['QUERY_STRING'] ?? '');
	}


	/**
	 * Return all query parameters.
	 *
	 * @return array<string,mixed>  A copy of $_GET.
	 */
	public function queryAll(): array {
		return $_GET;
	}


	/**
	 * Return the absolute URL of the request.
	 *
	 * @return string  scheme://host[:port] followed by uri(); the port only when it is not the scheme's default.
	 * @see \CitOmni\Http\Service\Request::host()  The host, also behind a proxy.
	 * @see \CitOmni\Http\Service\Request::port()  The port, also behind a proxy.
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
	 * Return the absolute URL of the app's root.
	 *
	 * Behavior:
	 * - Precedence:
	 *     1) The constant CITOMNI_PUBLIC_ROOT_URL, when it is defined and not empty.
	 *     2) Cfg http.base_url, when it is not empty.
	 *     3) scheme(), host() and port() of this request, the port only when it is not the
	 *        scheme's default, followed by the directory of uri().
	 * - 1) and 2) end in exactly one "/". 3) ends in "/" unless the directory is the web
	 *   root; then it ends with the host or port.
	 *
	 * Notes:
	 * - 1) and 2) are not checked to be absolute URLs.
	 * - 3) is a guess: The directory of uri() is the app's root only for a request to a page
	 *   directly below it.
	 *
	 * @return string
	 */
	public function baseUrl(): string {
		
		if (\defined('CITOMNI_PUBLIC_ROOT_URL') && \CITOMNI_PUBLIC_ROOT_URL !== '') {
			return \rtrim((string)\CITOMNI_PUBLIC_ROOT_URL, '/') . '/';
		}
		
		$cfgBase = (string)$this->app->cfg->http->base_url;

		if ($cfgBase !== '') {
			return \rtrim($cfgBase, '/') . '/';
		}
		
		$scheme = $this->scheme();
		$host   = $this->host();
		$port   = $this->port();
		$portPart = '';
		if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
			$portPart = ':' . $port;
		}
		// Derived from the URI alone, with no filesystem lookup.
		$uri  = $this->uri();
		$dir  = \rtrim(\dirname($uri), '/\\');
		$dir  = $dir === '' ? '' : $dir . '/';
		return $scheme . '://' . $host . $portPart . $dir;
	}




	// ----------------------------------------------------------------
	// Predicates
	// ----------------------------------------------------------------

	/**
	 * Check whether the client reached the app over HTTPS.
	 *
	 * Behavior:
	 * - True when the server reports HTTPS: HTTPS is set, not empty and not "off",
	 *   REQUEST_SCHEME is "https", or SERVER_PORT is 443.
	 * - Otherwise, only behind a trusted proxy, true when one of these headers says HTTPS
	 *   (values compared case-insensitively):
	 *     - Forwarded (RFC 7239): proto=https in the first hop, quotes removed.
	 *     - X-Forwarded-Proto: First entry "https".
	 *     - X-Forwarded-Ssl: "on".
	 *     - X-Forwarded-Scheme: "https".
	 *     - Front-End-Https: "on" or "1".
	 *     - X-Url-Scheme: "https".
	 *     - CF-Visitor: JSON whose scheme is "https".
	 * - False otherwise.
	 *
	 * @return bool
	 */
	public function isHttps(): bool {
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

		if (!$this->trustProxy()) {
			return false;
		}
		if (!$this->clientIpIsTrusted($this->trustedProxies)) {
			return false;
		}

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
	 * Check whether the client reached the app over HTTPS.
	 *
	 * @return bool
	 * @see \CitOmni\Http\Service\Request::isHttps()  Same result.
	 */
	public function isSecure(): bool {
		return $this->isHttps();
	}


	/**
	 * Check whether the X-Requested-With header is "XMLHttpRequest", compared case-insensitively.
	 *
	 * @return bool
	 */
	public function isAjax(): bool {
		return \strtolower((string)($this->header('X-Requested-With') ?? '')) === 'xmlhttprequest';
	}





	// ----------------------------------------------------------------
	// Client IP
	// ----------------------------------------------------------------

	/**
	 * Return the public IP address of the client.
	 *
	 * Behavior:
	 * - "CLI" when PHP_SAPI is "cli".
	 * - REMOTE_ADDR, unless proxy trust is on and REMOTE_ADDR matches the trusted proxy list.
	 * - Behind a trusted proxy, X-Forwarded-For is read from the right: Entries that match
	 *   the trusted proxy list are skipped, and the first other entry, trimmed, is the client.
	 *   Entries to its left are never used.
	 *     - REMOTE_ADDR when X-Forwarded-For is missing or empty.
	 *     - "unknown" when every entry is a trusted proxy.
	 * - The result is returned only when it is a valid IP address outside the private and
	 *   reserved ranges; otherwise "unknown".
	 * - No other header is read: Forwarded, Client-Ip, X-Cluster-Client-Ip and similar
	 *   headers never count.
	 *
	 * Notes:
	 * - Each proxy appends the address it received the request from, so the entries to the
	 *   left of the last untrusted one may be forged by the client. Proxies pass Client-Ip and
	 *   similar headers on from the client unchanged, which is why they never count.
	 * - Every proxy in the chain, such as a load balancer and a CDN, must be in the trusted
	 *   proxy list. The first one missing from it is taken as the client, and a private
	 *   address there gives "unknown".
	 *
	 * Typical usage:
	 *   $ip = $this->app->request->ip();
	 *
	 * @param  bool|null  $trustProxy  Proxy trust for this call; null uses cfg http.trust_proxy.
	 * @return string  Client IP, "unknown" or "CLI".
	 * @see \CitOmni\Http\Service\Request::ipInCidr()  How a trusted proxy entry matches an address.
	 */
	public function ip(?bool $trustProxy = null): string {
		if (\PHP_SAPI === 'cli') {
			return 'CLI';
		}

		$remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		$client = $remoteAddr;

		$useProxy = $trustProxy ?? $this->trustProxy();
		if ($useProxy && $remoteAddr !== '' && $this->clientIpIsTrusted($this->trustedProxies)) {
			$xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
			if ($xff !== '') {
				// Right to left: Only entries appended by trusted proxies can be believed.
				$client = '';
				$hops = \explode(',', $xff);
				for ($i = \count($hops) - 1; $i >= 0; $i--) {
					$hop = \trim($hops[$i]);
					if (!$this->isTrustedProxy($hop)) {
						$client = $hop;
						break;
					}
				}
			}
		}

		return $this->isPublicIp($client) ? $client : 'unknown';
	}


	/**
	 * Return the public IP address of the client.
	 *
	 * @param  bool|null  $trustProxy  Proxy trust for this call; null uses cfg http.trust_proxy.
	 * @return string  Client IP, "unknown" or "CLI".
	 * @see \CitOmni\Http\Service\Request::ip()  Same result.
	 */
	public function getClientIp(?bool $trustProxy = null): string {
		return $this->ip($trustProxy);
	}



	// ----------------------------------------------------------------
	// Address matching
	// ----------------------------------------------------------------

	/**
	 * Check whether REMOTE_ADDR matches an entry of a list.
	 *
	 * @param  list<string>  $whitelist  IP addresses and CIDR blocks, IPv4 or IPv6.
	 * @return bool  False when REMOTE_ADDR is missing or empty.
	 * @see \CitOmni\Http\Service\Request::ipInCidr()  How an entry matches.
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
	 * Check whether an address matches an entry of the trusted proxy list.
	 *
	 * @param  string  $ip  Candidate address, such as one X-Forwarded-For entry.
	 * @return bool  False when the address is not a valid IP address.
	 * @see \CitOmni\Http\Service\Request::ipInCidr()  How an entry matches.
	 */
	private function isTrustedProxy(string $ip): bool {
		foreach ($this->trustedProxies as $cidr) {
			if ($this->ipInCidr($ip, (string)$cidr)) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Check whether an IP address lies in a CIDR block or equals a single address.
	 *
	 * Behavior:
	 * - An entry without "/" matches only the same address, compared in binary form, so
	 *   "::1" and "0:0:0:0:0:0:0:1" are equal.
	 * - An entry with "/" matches every address of the same family whose first N bits, N being
	 *   the mask, equal those of the entry's address. Host bits in the entry are ignored.
	 * - Never matches when the address or the entry's address is not a valid IP address, the
	 *   families differ, or the mask is not a decimal bit count from 0 to 32 (IPv4) or 128
	 *   (IPv6). Whitespace around the mask is allowed; around an address it is not.
	 *
	 * @param  string  $ip  Candidate address, IPv4 or IPv6.
	 * @param  string  $cidr  CIDR block such as "192.168.0.0/16", or a single address.
	 * @return bool
	 */
	protected function ipInCidr(string $ip, string $cidr): bool {
		$ipBin = @\inet_pton($ip);
		if ($ipBin === false) {
			return false;
		}

		if (!\str_contains($cidr, '/')) {
			$single = @\inet_pton($cidr);
			return $single !== false && $single === $ipBin;
		}

		[$subnet, $maskBitsRaw] = \explode('/', $cidr, 2);

		// A different length is a different address family.
		$subBin = @\inet_pton($subnet);
		if ($subBin === false || \strlen($subBin) !== \strlen($ipBin)) {
			return false;
		}

		// The mask must be a decimal bit count. (int) would read "" or "x" as 0, and a
		// /0 mask matches every address.
		$maskBitsRaw = \trim($maskBitsRaw);
		if ($maskBitsRaw === '' || \strspn($maskBitsRaw, '0123456789') !== \strlen($maskBitsRaw)) {
			return false;
		}

		$maskBits = (int)$maskBitsRaw;
		$addrLen  = \strlen($ipBin);      // 4 bytes for IPv4, 16 bytes for IPv6.
		$maxBits  = $addrLen * 8;
		if ($maskBits < 0 || $maskBits > $maxBits) {
			return false;
		}

		// Byte mask with $maskBits leading 1s; IPv4 /20 is 11111111 11111111 11110000 00000000.
		$fullBytes = intdiv($maskBits, 8);
		$remBits   = $maskBits % 8;

		$mask = $fullBytes > 0 ? \str_repeat("\xFF", $fullBytes) : '';

		if ($remBits > 0) {
			$mask .= \chr((0xFF << (8 - $remBits)) & 0xFF);
		}

		if (\strlen($mask) < $addrLen) {
			$mask .= \str_repeat("\x00", $addrLen - \strlen($mask));
		}

		// Bitwise AND on strings works byte by byte on the packed addresses.
		return (($ipBin & $mask) === ($subBin & $mask));
	}


	/**
	 * Check whether a string is a valid IP address outside the private and reserved ranges.
	 *
	 * Notes:
	 * - The ranges are those that PHP's FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE
	 *   exclude.
	 *
	 * @param  string  $ip  Candidate address, IPv4 or IPv6.
	 * @return bool
	 */
	protected function isPublicIp(string $ip): bool {
		return (bool)\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
	}
		
		


	
}
