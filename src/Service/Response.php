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

use CitOmni\Http\Util\Url;
use CitOmni\Kernel\Service\BaseService;

/**
 * Response: HTTP service that sends the status, headers and body of the response.
 *
 * Gives controllers and services one place to answer a request, so header injection,
 * charset and the end of the request are handled the same way everywhere. What to answer
 * belongs to the caller; rendering templates belongs to TemplateEngine.
 *
 * Rules:
 * - Sanitize every header name and value that comes from an argument: No CR or LF may reach
 *   a header.
 * - Write a body only in a method that ends the request; such a method never returns.
 * - Never throw because headers were already sent.
 */
class Response extends BaseService {


	// ----------------------------------------------------------------
	// Status and redirects
	// ----------------------------------------------------------------


	/**
	 * Set the status code of the response.
	 *
	 * Behavior:
	 * - When headers are already sent, changes nothing and logs it through the log service,
	 *   when registered.
	 *
	 * @param  int  $statusCode  HTTP status code, such as 404.
	 * @return void
	 */
	public function setStatus(int $statusCode): void {
		
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__, ['status' => $statusCode]);
			return;
		}

		\http_response_code($statusCode);
	}
		

	/**
	 * Redirect the client and end the request.
	 *
	 * Behavior:
	 * - Sends a Location header with $statusCode and writes no body.
	 * - A local path is prefixed with CITOMNI_PUBLIC_ROOT_URL without its trailing slash; any
	 *   other URL is sent as given.
	 * - Removes CR and LF from the Location value.
	 * - When headers are already sent, sends nothing, logs it through the log service when
	 *   registered, and still ends the request.
	 *
	 * Notes:
	 * - Does not guard against open redirects: Check a target taken from the request with
	 *   Url::isLocal() before passing it.
	 * - Assumes that CITOMNI_PUBLIC_ROOT_URL is defined when $url is a local path; without it,
	 *   PHP throws \Error.
	 *
	 * Typical usage:
	 *   $this->app->response->redirect('/login.html');
	 *
	 * @param  string  $url  Local path such as "/login.html", or an absolute URL.
	 * @param  int  $statusCode  Redirect status, such as 301, 302 or 303.
	 * @return never
	 * @see \CitOmni\Http\Util\Url::isLocal()  What counts as a local path.
	 */
	public function redirect(string $url, int $statusCode = 302): never {
		if (!\headers_sent()) {
			
			// A local path is relative to the app's public root, which may lie below a subpath.
			if (Url::isLocal($url)) {
				$url = \rtrim((string)\CITOMNI_PUBLIC_ROOT_URL, '/') . $url;
			}

			$loc = $this->sanitizeHeaderValue($url);
			\header('Location: ' . $loc, true, $statusCode);
			
		} else {
			$this->logHeadersAlreadySent(__METHOD__, ['location' => $url, 'status' => $statusCode]);
		}

		exit;
	}







	// ----------------------------------------------------------------
	// Headers
	// ----------------------------------------------------------------


	/**
	 * Send one response header.
	 *
	 * Behavior:
	 * - Every byte of the name other than an ASCII letter, a digit or "-" becomes "-"; CR and
	 *   LF are removed from the value.
	 * - When headers are already sent, sends nothing and logs it through the log service,
	 *   when registered.
	 *
	 * Typical usage:
	 *   $this->app->response->setHeader('X-Robots-Tag', 'noindex, noarchive');
	 *
	 * @param  string  $name  Header name.
	 * @param  string  $value
	 * @param  bool  $replace  Replace an earlier header of the same name; false adds another.
	 * @param  int  $statusCode  Status code to set with the header; 0 or less leaves the status.
	 * @return void
	 */
	public function setHeader(string $name, string $value, bool $replace = true, int $statusCode = 0): void {
		if (!\headers_sent()) {
			$n = $this->sanitizeHeaderName($name);
			$v = $this->sanitizeHeaderValue($value);
			$line = $n . ': ' . $v;
			if ($statusCode > 0) {
				\header($line, $replace, $statusCode);
			} else {
				\header($line, $replace);
			}
			return;
		}
		$this->logHeadersAlreadySent(__METHOD__, ['name' => $name]);
	}


	/**
	 * Forbid caching of the response.
	 *
	 * Behavior:
	 * - Sends Cache-Control "no-store, no-cache, must-revalidate, max-age=0", Pragma
	 *   "no-cache" and Expires "0", each replacing an earlier header of the same name.
	 * - When headers are already sent, sends nothing and logs it through the log service,
	 *   when registered.
	 *
	 * Typical usage:
	 *   $this->app->response->noCache();
	 *
	 * @return void
	 */
	public function noCache(): void {
		if (!\headers_sent()) {
			\header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			\header('Pragma: no-cache');
			\header('Expires: 0');
		} else {
			$this->logHeadersAlreadySent(__METHOD__);
		}
	}


	/**
	 * Ask search engines not to index or archive the response, and forbid caching.
	 *
	 * Behavior:
	 * - Sends X-Robots-Tag "noindex, noarchive", Cache-Control "no-store, no-cache,
	 *   must-revalidate, max-age=0", Pragma "no-cache" and Expires "0".
	 * - Sends each header through setHeader(), so with headers already sent each one is
	 *   logged.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Response::setHeader()  What happens when headers are already sent.
	 */
	public function noIndex(): void {
		$this->setHeader('X-Robots-Tag', 'noindex, noarchive');
		$this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
		$this->setHeader('Pragma', 'no-cache', true);
		$this->setHeader('Expires', '0', true);
	}



	// ----------------------------------------------------------------
	// Security header presets
	// ----------------------------------------------------------------


	/**
	 * Send the cache, robots and security headers for a page behind a member login.
	 *
	 * Behavior:
	 * - Sends the cache headers of noCache().
	 * - With $noIndex, X-Robots-Tag "noindex, nofollow, noarchive, nosnippet".
	 * - X-Frame-Options "SAMEORIGIN", X-Content-Type-Options "nosniff", Referrer-Policy
	 *   "strict-origin-when-cross-origin" and Permissions-Policy "browsing-topics=()".
	 * - Content-Security-Policy: default-src, base-uri, frame-ancestors, img-src, style-src,
	 *   script-src and connect-src allow 'self', and object-src is 'none'. img-src also allows
	 *   data: and blob:; style-src and script-src also allow 'unsafe-inline'. With
	 *   $allowExternal, img-src, style-src, script-src and connect-src also allow https:.
	 * - When headers are already sent, sends nothing and logs it through the log service,
	 *   when registered.
	 *
	 * Typical usage:
	 *   $this->app->response->memberHeaders();
	 *
	 * @param  bool  $noIndex  Send the X-Robots-Tag header.
	 * @param  bool  $allowExternal  Allow https: sources in the Content-Security-Policy.
	 * @return void
	 */
	public function memberHeaders(bool $noIndex = true, bool $allowExternal = true): void {
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__);
			return;
		}

		
		$this->noCache();
		if ($noIndex) {
			$this->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
		}

		$this->setHeader('X-Frame-Options', 'SAMEORIGIN');
		$this->setHeader('X-Content-Type-Options', 'nosniff');
		$this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
		$this->setHeader('Permissions-Policy', 'browsing-topics=()');

		// Inline styles and scripts stay allowed: Member pages may use inline CSS and JS without nonces.
		$https = $allowExternal ? ' https:' : '';
		$this->setHeader(
			'Content-Security-Policy',
			"default-src 'self'; " .
			"base-uri 'self'; frame-ancestors 'self'; object-src 'none'; " .
			"img-src 'self' data: blob:$https; " .
			"style-src 'self' 'unsafe-inline'$https; " .
			"script-src 'self' 'unsafe-inline'$https; " .
			"connect-src 'self'$https"
		);
	}


	/**
	 * Send the cache, robots and security headers for a page behind an admin login.
	 *
	 * Behavior:
	 * - Sends the cache headers of noCache().
	 * - X-Robots-Tag "noindex, nofollow, noarchive, nosnippet", X-Frame-Options "SAMEORIGIN",
	 *   X-Content-Type-Options "nosniff", Referrer-Policy "strict-origin-when-cross-origin"
	 *   and Permissions-Policy "browsing-topics=()".
	 * - Content-Security-Policy: default-src, base-uri, frame-ancestors, img-src, style-src,
	 *   script-src and connect-src allow 'self', and object-src is 'none'. img-src also allows
	 *   data: and blob:; style-src and script-src also allow 'unsafe-inline'. No external
	 *   source is allowed.
	 * - Over HTTPS, as isHttps() of the request service decides, also Strict-Transport-Security
	 *   "max-age=15552000; includeSubDomains; preload".
	 * - When headers are already sent, sends nothing and logs it through the log service,
	 *   when registered.
	 *
	 * Notes:
	 * - HSTS with includeSubDomains and preload binds every subdomain of the host to HTTPS
	 *   for 180 days. Prefer HSTS from the web server where that is not wanted.
	 *
	 * Typical usage:
	 *   $this->app->response->adminHeaders();
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Request::isHttps()  When the request counts as HTTPS.
	 */
	public function adminHeaders(): void {
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__);
			return;
		}

		$this->noCache();
		
		$this->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
		
		$this->setHeader('X-Frame-Options', 'SAMEORIGIN');
		$this->setHeader('X-Content-Type-Options', 'nosniff');
		$this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
		$this->setHeader('Permissions-Policy', 'browsing-topics=()');

		
		// Inline styles and scripts stay allowed: Assumes admin pages use inline CSS and JS without nonces. Tighten once they carry nonces.
		$this->setHeader(
			'Content-Security-Policy',
			"default-src 'self'; " .
			"base-uri 'self'; frame-ancestors 'self'; object-src 'none'; " .
			"img-src 'self' data: blob:; " .
			"style-src 'self' 'unsafe-inline'; " .
			"script-src 'self' 'unsafe-inline'; " .
			"connect-src 'self'"
		);

		// Transport, not site policy: Browsers ignore HSTS received over plain HTTP.
		if ($this->app->request->isHttps()) {
			$this->setHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains; preload');
		}
	}





	// ----------------------------------------------------------------
	// JSON responses
	// ----------------------------------------------------------------


	/**
	 * Write data as a JSON body and end the request.
	 *
	 * Behavior:
	 * - Sends Content-Type "application/json; charset=" with cfg locale.charset, or UTF-8
	 *   without it, and writes $data as JSON without escaped slashes or Unicode.
	 * - Keeps the status set before the call; use jsonStatus() to set one.
	 * - When headers are already sent, writes the body without Content-Type.
	 *
	 * Typical usage:
	 *   $this->app->response->json(['ok' => true]);
	 *
	 * @param  array<mixed>  $data  Data to encode.
	 * @param  bool  $di  Pretty-print the JSON.
	 * @return never
	 */
	public function json(array $data, bool $di = false): never {
		if (!\headers_sent()) {
			\header('Content-Type: application/json; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
		}
		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($data, $opts);
		exit;
	}


	/**
	 * Forbid caching, then write data as a JSON body and end the request.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonNoCache(['ok' => true]);
	 *
	 * @param  array<mixed>  $data  Data to encode.
	 * @param  bool  $di  Pretty-print the JSON.
	 * @return void  Never returns: json() ends the request.
	 * @see \CitOmni\Http\Service\Response::noCache()  The cache headers.
	 * @see \CitOmni\Http\Service\Response::json()  The body and the end of the request.
	 */
	public function jsonNoCache(array $data, bool $di = false): void {
		$this->noCache();
		$this->json($data, $di);
	}
	

	/**
	 * Set the status, write data as a JSON body and end the request.
	 *
	 * Behavior:
	 * - Sets the status through setStatus(), sends Content-Type "application/json; charset="
	 *   with cfg locale.charset, or UTF-8 without it, and writes $data as JSON without escaped
	 *   slashes or Unicode.
	 * - When headers are already sent, writes the body without status or Content-Type.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonStatus(['ok' => false, 'error' => 'invalid_input'], 422);
	 *
	 * @param  array<mixed>  $data  Data to encode.
	 * @param  int  $statusCode  HTTP status code.
	 * @param  bool  $di  Pretty-print the JSON.
	 * @return never
	 * @see \CitOmni\Http\Service\Response::setStatus()  What happens when headers are already sent.
	 */
	public function jsonStatus(array $data, int $statusCode = 200, bool $di = false): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: application/json; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
		}
		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($data, $opts);
		exit;
	}


	/**
	 * Forbid caching, then set the status, write data as a JSON body and end the request.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonStatusNoCache(['ok' => true], 200);
	 *
	 * @param  array<mixed>  $data  Data to encode.
	 * @param  int  $statusCode  HTTP status code.
	 * @param  bool  $di  Pretty-print the JSON.
	 * @return void  Never returns: jsonStatus() ends the request.
	 * @see \CitOmni\Http\Service\Response::noCache()  The cache headers.
	 * @see \CitOmni\Http\Service\Response::jsonStatus()  The status, the body and the end of the request.
	 */
	public function jsonStatusNoCache(array $data, int $statusCode = 200, bool $di = false): void {
		$this->noCache();
		$this->jsonStatus($data, $statusCode, $di);
	}


	/**
	 * Answer with an RFC 7807 problem details body and end the request.
	 *
	 * Behavior:
	 * - Sets the status through setStatus() and sends Content-Type "application/problem+json;
	 *   charset=" with cfg locale.charset, or UTF-8 without it, and X-Content-Type-Options
	 *   "nosniff".
	 * - The body has type, title, status and detail, and instance with the request URI when
	 *   the request service is registered. Members of $extra follow; one whose name is already
	 *   in the body is dropped.
	 * - Sends no cache headers; call noCache() first when needed.
	 * - When headers are already sent, writes the body without status or headers.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonProblem('Validation failed', 422, 'Email is required.');
	 *
	 * @param  string  $title  Short summary of the problem type.
	 * @param  int  $status  HTTP status code, also written as the status member.
	 * @param  string  $detail  Explanation of this occurrence; "" for none.
	 * @param  string  $type  URI that identifies the problem type, or "about:blank".
	 * @param  array<string,mixed>  $extra  Extension members.
	 * @param  bool  $di  Pretty-print the JSON.
	 * @return never
	 * @see \CitOmni\Http\Service\Request::uri()  The value of instance.
	 */
	public function jsonProblem(string $title, int $status, string $detail = '', string $type = 'about:blank', array $extra = [], bool $di = false): never {
		
		$base = [
			'type'   => $type,
			'title'  => $title,
			'status' => $status,
			'detail' => $detail,
		];

		if ($this->app->hasService('request')) {
			$base['instance'] = $this->app->request->uri();
		}

		// The left operand wins, so $extra cannot replace a standard member.
		$payload = $base + $extra;

		$this->setStatus($status);

		if (!\headers_sent()) {
			\header('X-Content-Type-Options: nosniff');
			\header('Content-Type: application/problem+json; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
		}

		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($payload, $opts);
	
		exit;
	}






	// ----------------------------------------------------------------
	// Text and HTML responses
	// ----------------------------------------------------------------


	/**
	 * Set the status, write a plain text body and end the request.
	 *
	 * Behavior:
	 * - Sets the status through setStatus() and sends Content-Type "text/plain; charset="
	 *   with cfg locale.charset, or UTF-8 without it.
	 * - When headers are already sent, writes the body without status or Content-Type.
	 *
	 * Typical usage:
	 *   $this->app->response->text('pong');
	 *
	 * @param  string  $body  Written as given.
	 * @param  int  $statusCode  HTTP status code.
	 * @return never
	 */
	public function text(string $body, int $statusCode = 200): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: text/plain; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
		}
		echo $body;
		exit;
	}


	/**
	 * Set the status, write an HTML body and end the request.
	 *
	 * Behavior:
	 * - Sets the status through setStatus() and sends Content-Type "text/html; charset="
	 *   with cfg locale.charset, or UTF-8 without it.
	 * - When headers are already sent, writes the body without status or Content-Type.
	 *
	 * Notes:
	 * - Writes $html unescaped: Escape every value from the request before it goes in.
	 *
	 * Typical usage:
	 *   $this->app->response->html($markup, 503);
	 *
	 * @param  string  $html  Markup, written as given.
	 * @param  int  $statusCode  HTTP status code.
	 * @return never
	 */
	public function html(string $html, int $statusCode = 200): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: text/html; charset=' . (string)($this->app->cfg->locale->charset ?? 'UTF-8'));
		}
		echo $html;
		exit;
	}






	// ----------------------------------------------------------------
	// File downloads
	// ----------------------------------------------------------------


	/**
	 * Send a file as a download and end the request.
	 *
	 * Behavior:
	 * - Discards all buffered output first, so the body is the file alone.
	 * - Answers 200 with X-Content-Type-Options "nosniff", Cache-Control "private",
	 *   Accept-Ranges "none", Content-Length when the size is known, and Content-Type from
	 *   mime_content_type(), or "application/octet-stream" when that function is missing or
	 *   detects nothing.
	 * - Content-Disposition is "attachment" with two names (RFC 6266): filename, in which
	 *   every byte other than an ASCII letter, a digit, ".", "_" or "-" becomes "_", and
	 *   filename* with the full name in UTF-8.
	 * - Keeps sending when the client disconnects.
	 *
	 * Notes:
	 * - Goes on when headers were already sent: setStatus() logs it, PHP warns for each other
	 *   header, and the file is appended to the output already sent.
	 * - Sends any readable file the process can read: Never pass a path built from request
	 *   input without checking it.
	 *
	 * Typical usage:
	 *   $this->app->response->download(CITOMNI_APP_PATH . '/var/export/report.csv', 'report.csv');
	 *
	 * @param  string  $path  Filesystem path of the file.
	 * @param  string|null  $downloadName  File name for the client; null or "" for the name in $path.
	 * @return never
	 * @throws \RuntimeException  When $path is not a readable file, before anything is sent; or when it cannot be opened after the headers are sent.
	 */
	public function download(string $path, ?string $downloadName = null): never {
		if (!\is_file($path) || !\is_readable($path)) {
			throw new \RuntimeException('Download source not found or unreadable: ' . $path);
		}

		while (\ob_get_level() > 0) {
			\ob_end_clean();
		}
		\ignore_user_abort(true);

		\clearstatcache(true, $path); // Avoids stale sizes on some FSes
		$size = @\filesize($path);
		$name = $downloadName ?: \basename($path);

		// RFC 6266 / 5987: An ASCII fallback for old clients, the full UTF-8 name in filename*.
		$fallback = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
		$disposition = "attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . \rawurlencode($name);

		$mime = 'application/octet-stream';
		if (\function_exists('mime_content_type')) {
			$detected = @\mime_content_type($path);
			if (\is_string($detected) && $detected !== '') {
				$mime = $detected;
			}
		}

		$this->setStatus(200);
		\header('X-Content-Type-Options: nosniff');
		\header('Content-Type: ' . $mime);
		\header('Content-Disposition: ' . $disposition);
		if (\is_int($size) && $size >= 0) {
			\header('Content-Length: ' . $size);
		}
		\header('Cache-Control: private');
		\header('Accept-Ranges: none');

		$fh = \fopen($path, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('Failed to open download source: ' . $path);
		}
		\fpassthru($fh);
		\fclose($fh);
		exit;
	}





	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------
 

	/**
	 * Remove CR and LF from a header value, so it cannot start another header.
	 *
	 * @param  string  $value
	 * @return string
	 */
	private function sanitizeHeaderValue(string $value): string {
		return \str_replace(["\r", "\n"], '', $value);
	}


	/**
	 * Replace every byte of a header name other than an ASCII letter, a digit or "-" with "-".
	 *
	 * @param  string  $name
	 * @return string  "X-Invalid-Header" when the replacement fails.
	 */
	private function sanitizeHeaderName(string $name): string {
		return \preg_replace('/[^A-Za-z0-9\-]/', '-', $name) ?? 'X-Invalid-Header';
	}


	/**
	 * Log that a method could not send headers because they were already sent.
	 *
	 * Behavior:
	 * - Writes one error record to response_errors.json through the log service, with
	 *   $context and the first four call frames, without arguments.
	 * - Does nothing when the log service is not registered.
	 *
	 * Notes:
	 * - Does not catch a failure of the log service itself.
	 *
	 * @param  string  $method  Name of the calling method, such as __METHOD__.
	 * @param  array<string,mixed>  $context  Values that identify what was not sent.
	 * @return void
	 */
	private function logHeadersAlreadySent(string $method, array $context = []): void {
		if ($this->app->hasService('log')) {
			$this->app->log->write(
				'response_errors.json',
				'error',
				'Headers already sent in ' . $method,
				$context + ['trace' => \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4)]
			);
		}
	}
}
