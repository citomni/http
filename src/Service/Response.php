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
 * Response: Lean HTTP response utilities for CitOmni.
 *
 * Responsibilities:
 * - Emit deterministic HTTP responses:
 *   1) Redirects with CRLF-sanitized Location
 *   2) JSON, text, and HTML bodies with correct Content-Type
 *   3) RFC 7807 Problem Details payloads
 *   4) File downloads with safe headers and clean output buffers
 * - Provide opinionated, inline-friendly hardening headers for Admin/Member pages.
 *
 * Collaborators:
 * - log (optional): Used for diagnostics when headers were already sent.
 * - cfg (read-only): Uses $this->app->cfg->locale->charset for Content-Type.
 *
 * Configuration keys:
 * - cfg.locale.charset (string) - Output charset for textual responses. Default defined by app cfg.
 *
 * Error handling:
 * - Fail fast: No blanket try/catch. Exceptions bubble to the global error handler.
 * - Mutating operations do nothing when headers were already sent and will be logged if log service exists.
 *
 * Typical usage:
 *
 *   // JSON OK
 *   $this->app->response->jsonStatus(['ok' => true], 200);
 *
 *   // Problem Details
 *   $this->app->response->jsonProblem('Not Found', 404, 'User is missing', 'https://docs.example.com/problems/user-missing');
 *
 *   // Admin page security headers (inline CSS/JS allowed)
 *   $this->app->response->adminHeaders();
 *
 *   // Safe download
 *   $this->app->response->download(CITOMNI_APP_PATH . '/var/backups/site.zip', 'backup.zip');
 *
 * Examples:
 *
 *   // Redirect to login, 302
 *   $this->app->response->redirect('/login');
 *
 *   // Member page: do not index, allow https CDNs in CSP
 *   $this->app->response->memberHeaders(true, true);
 *
 * Failure:
 *
 *   // Missing file causes RuntimeException which is logged by global handler
 *   $this->app->response->download('/nope/absent.bin');
 *
 * Standalone:
 *
 *   // This service is invoked via $this->app->response inside controllers or services.
 *   // No separate bootstrap is required beyond CitOmni app initialization.
 */
class Response extends BaseService {

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




/*
 *---------------------------------------------------------------
 * REDIRECTS & STATUS LINE
 *---------------------------------------------------------------
 * PURPOSE
 *   Emit HTTP status lines and redirects in a safe, predictable way.
 *
 * NOTES
 *   - Respect headers_sent(); log and return early when necessary.
 *   - Redirects sanitize header values to avoid injection and call exit.
 */


	/**
	 * Set the HTTP status code for the current response.
	 *
	 * Behavior:
	 * - If headers are already sent, no status line can be emitted.
	 *   Logs the situation (if a logger service exists) and returns early.
	 * - Otherwise sets the status using PHP's native http_response_code().
	 *
	 * Notes:
	 * - This method intentionally does not throw; it fails fast by returning
	 *   when headers are sent, since emitting a status line is no longer possible.
	 *
	 * @param int $statusCode Valid HTTP status code (e.g., 200, 302, 404, 500).
	 * @return void
	 */
	public function setStatus(int $statusCode): void {
		
		// If output (headers) has already begun, we cannot change the status code.
		// We log for diagnostics and return without attempting to set it.
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__, ['status' => $statusCode]);
			return;
		}

		// Safe to set the HTTP status line.
		\http_response_code($statusCode);
	}
		

	/**
	 * Redirects to a given URL with an optional HTTP status code and terminate.
	 *
	 * Behavior:
	 * - Strips CR/LF from $url to prevent header injection.
	 * - Sends Location and optional status code (default 302).
	 * - Terminates execution deterministically.
	 *
	 * Notes:
	 * - If headers are already sent, logs the situation (if log service exists) and still terminates.
	 * - Does not normalize relative URLs to absolute to keep overhead minimal.
	 *
	 * Typical usage:
	 *   $this->app->response->redirect('/login'); // 302 by default
	 *
	 * Examples:
	 *
	 *   // Permanent move
	 *   $this->app->response->redirect('https://example.com/new', 301);
	 *
	 * @param string $url Absolute or relative URL. CR/LF will be removed.
	 * @param int $statusCode 3xx status code (default 302).
	 * @return never
	 */
	public function redirect(string $url, int $statusCode = 302): never {
		if (!\headers_sent()) {
			$loc = $this->sanitizeHeaderValue($url);
			\header('Location: ' . $loc, true, $statusCode);
		} else {
			$this->logHeadersAlreadySent(__METHOD__, ['location' => $url, 'status' => $statusCode]);
		}
		exit;
	}



/*
 *---------------------------------------------------------------
 * GENERIC HEADERS - Low-level primitives
 *---------------------------------------------------------------
 * PURPOSE
 *   Set raw headers and caching directives without side effects.
 *
 * NOTES
 *   - Keep these focused: no body output, no exit.
 *   - Use as building blocks for higher-level responses.
 */


	/**
	 * Send a single HTTP header line (CRLF-safe).
	 *
	 * Behavior:
	 * - Sanitizes header name and value to avoid injection.
	 * - If $statusCode > 0, sets that status as part of the header call.
	 *
	 * Notes:
	 * - No-op if headers were already sent; logs for diagnostics if log service exists.
	 *
	 * Typical usage:
	 *   $this->app->response->setHeader('X-Robots-Tag', 'noindex, noarchive');
	 *
	 * @param string $name Header name (token characters only).
	 * @param string $value Header value.
	 * @param bool $replace Replace previous header with same name (default true).
	 * @param int $statusCode Optional status code to set (0 means none).
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
	 * Sends strict HTTP no-cache headers to the client (idempotent).
	 *
	 * Behavior:
	 * - Sends Cache-Control, Pragma, and Expires headers to prevent caching.
	 *
	 * Notes:
	 * - Safe to call multiple times. No-ops and logs if headers already sent.
	 *
	 * Typical usage:
	 *   $this->app->response->noCache(); // before JSON or HTML
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
	 * Disallow indexing/snippets and apply conservative no-cache.
	 *
	 * Behavior:
	 * - Sends `X-Robots-Tag: noindex, noarchive` and a conservative Cache-Control.
	 *
	 * Notes:
	 * - Safe to combine with noCache().
	 *
	 * Typical usage:
	 *   $this->app->response->noIndex();
	 *
	 * @return void
	 */
	public function noIndex(): void {
		$this->setHeader('X-Robots-Tag', 'noindex, noarchive');
		$this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
		$this->setHeader('Pragma', 'no-cache', true);
		$this->setHeader('Expires', '0', true);
	}



/*
 *---------------------------------------------------------------
 * SECURITY & POLICIES - Hardened response presets
 *---------------------------------------------------------------
 * PURPOSE
 *   Emit security-related headers (CSP, XFO, HSTS, etc.) for member/admin UIs.
 *
 * NOTES
 *   - `memberHeaders()` optionally allows external CDNs.
 *   - `adminHeaders()` is stricter; adds HSTS when HTTPS is detected.
 */


	/**
	 * Recommended headers for authenticated member pages.
	 *
	 * Behavior:
	 * - Applies strict no-cache.
	 * - Optionally sends robots noindex/nofollow/noarchive/nosnippet.
	 * - Applies inline-friendly CSP with optional https: allowance.
	 * - Adds basic hardening headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy).
	 *
	 * Notes:
	 * - Idempotent and safe to call early. Logs if headers were already sent.
	 *
	 * Typical usage:
	 *   $this->app->response->memberHeaders(true, true);
	 *
	 * @param bool $noIndex Send X-Robots-Tag noindex (default true).
	 * @param bool $allowExternal Allow https: sources in CSP (default true).
	 * @return void
	 */
	public function memberHeaders(bool $noIndex = true, bool $allowExternal = true): void {
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__);
			return;
		}

		
		// Freshness + privacy
		$this->noCache();
		if ($noIndex) {
			$this->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
		}

		// Security hardening
		$this->setHeader('X-Frame-Options', 'SAMEORIGIN');
		$this->setHeader('X-Content-Type-Options', 'nosniff');
		$this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
		// $this->setHeader('Permissions-Policy', 'interest-cohort=()'); // Deprecated?
		$this->setHeader('Permissions-Policy', 'browsing-topics=()');

		// CSP: Inline-friendly (as in inline CSS/JS), optionally allow HTTPS CDNs/APIs
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
	 * Recommended headers for Admin pages (inline-friendly CSP).
	 *
	 * Behavior:
	 * - Applies strict no-cache and robots noindex/nofollow/noarchive/nosnippet.
	 * - Applies inline-friendly CSP restricted to self.
	 * - Sends basic hardening headers and HSTS if HTTPS is detected.
	 *
	 * Notes:
	 * - Prefer server-level HSTS, but header here is acceptable if needed.
	 * - Logs if headers already sent.
	 *
	 * Typical usage:
	 *   $this->app->response->adminHeaders();
	 *
	 * @return void
	 */
	public function adminHeaders(): void {
		if (\headers_sent()) {
			$this->logHeadersAlreadySent(__METHOD__);
			return;
		}

		// Cache & robots
		$this->noCache();
		
		// Stricter than noIndex(): also prevents snippets/follow
		$this->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
		
		// Security hardening
		$this->setHeader('X-Frame-Options', 'SAMEORIGIN');
		$this->setHeader('X-Content-Type-Options', 'nosniff');
		$this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
		// $this->setHeader('Permissions-Policy', 'interest-cohort=()');  // Deprecated?
		$this->setHeader('Permissions-Policy', 'browsing-topics=()');

		
		// CSP: allow inline since Admin theme is inline; tighten later with nonces if possible
		$this->setHeader(
			'Content-Security-Policy',
			"default-src 'self'; " .
			"base-uri 'self'; frame-ancestors 'self'; object-src 'none'; " .
			"img-src 'self' data: blob:; " .
			"style-src 'self' 'unsafe-inline'; " .
			"script-src 'self' 'unsafe-inline'; " .
			"connect-src 'self'"
		);

		// HSTS only when HTTPS (prefer server-level, but fine here if needed)
		if ($this->app->hasService('request') && $this->app->request->isHttps()) {
			$this->setHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains; preload');
		} else {
			if (
				(!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
				(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
			) {
				$this->setHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains; preload');
			}
		}
	}





/*
 *---------------------------------------------------------------
 * JSON RESPONSES - APIs & AJAX (incl. Problem Details)
 *---------------------------------------------------------------
 * PURPOSE
 *   Serialize arrays to JSON and emit proper status + headers.
 *
 * NOTES
 *   - Content-Type: application/json; charset=<cfg.locale.charset>.
 *   - Uses JSON_THROW_ON_ERROR; fail fast on encoding errors.
 *   - Methods echo the payload and call exit to avoid extra output.
 *   - *NoCache* variants add no-store/no-cache directives.
 *   - Problem Details (RFC 7807): payload shape (type,title,status,detail,...).
 *     For strict media type, set `application/problem+json` explicitly if needed.
 */


	/**
	 * Sends a JSON 200 OK and immediately terminates the script.
	 * Sets the appropriate Content-Type header, encodes the data array as JSON,
	 * outputs the result, and stops further script execution.
	 *
	 * Behavior:
	 * - Sends Content-Type and echoes JSON-encoded payload with strict flags.
	 * - Optional pretty-print for developer inspection when $di === true.
	 *
	 * Notes:
	 * - Deterministic termination. Use jsonStatus() to set a custom status code.
	 *
	 * Typical usage:
	 *   $this->app->response->json(['ok' => true], true); // pretty JSON in dev
	 *
	 * @param array $data JSON-serializable payload.
	 * @param bool  $di   Developer-inspect: when true, adds JSON_PRETTY_PRINT (default: false).
	 * @return never
	 * @throws \JsonException When payload cannot be encoded.
	 */
	public function json(array $data, bool $di = false): never {
		if (!\headers_sent()) {
			\header('Content-Type: application/json; charset=' . $this->app->cfg->locale->charset);
		}
		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($data, $opts);
		exit;
	}


	/**
	 * Sends a JSON 200 OK with strict no-cache and terminates the script.
	 * Emits no-store/no-cache headers before delegating to json().
	 *
	 * Behavior:
	 * - Sets no-cache headers and outputs JSON.
	 * - Optional pretty-print for developer inspection when $di === true.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonNoCache(['fresh' => true], true);
	 *
	 * @param array $data JSON-serializable payload.
	 * @param bool  $di   Developer-inspect: when true, adds JSON_PRETTY_PRINT (default: false).
	 * @return void
	 */
	public function jsonNoCache(array $data, bool $di = false): void {
		$this->noCache();
		$this->json($data, $di);
	}
	

	/**
	 * Sends a JSON response with a custom HTTP status and terminates the script.
	 * Sets the status code, Content-Type header, encodes the data, and exits.
	 *
	 * Behavior:
	 * - Deterministic termination after emitting JSON body and status.
	 * - Optional pretty-print for developer inspection when $di === true.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonStatus(['ok' => true], 201, true);
	 *
	 * @param array $data       JSON-serializable payload.
	 * @param int   $statusCode HTTP status code (e.g., 200, 201, 400).
	 * @param bool  $di         Developer-inspect: when true, adds JSON_PRETTY_PRINT (default: false).
	 * @return never
	 * @throws \JsonException When payload cannot be encoded.
	 */
	public function jsonStatus(array $data, int $statusCode = 200, bool $di = false): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: application/json; charset=' . (string)$this->app->cfg->locale->charset);
		}
		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($data, $opts);
		exit;
	}


	/**
	 * Sends a JSON response with a custom HTTP status and strict no-cache.
	 * Emits no-store/no-cache headers and delegates to jsonStatus().
	 *
	 * Behavior:
	 * - Sets status+no-cache and outputs JSON.
	 * - Optional pretty-print for developer inspection when $di === true.
	 *
	 * Typical usage:
	 *   $this->app->response->jsonStatusNoCache(['ok' => true], 200, true);
	 *
	 * @param array $data       JSON-serializable payload.
	 * @param int   $statusCode HTTP status code.
	 * @param bool  $di         Developer-inspect: when true, adds JSON_PRETTY_PRINT (default: false).
	 * @return void
	 */
	public function jsonStatusNoCache(array $data, int $statusCode = 200, bool $di = false): void {
		$this->noCache();
		$this->jsonStatus($data, $statusCode, $di);
	}


	/**
	 * Sends an RFC 7807 Problem Details JSON response (production-ready).
	 *
	 * Sends `application/problem+json` and terminates execution. Core fields
	 * (`type`, `title`, `status`, `detail`, and `instance` when available) are
	 * protected from being overridden by `$extra` to keep invariants intact.
	 *
	 * Behavior:
	 * - Sets HTTP status code first, then headers (if not already sent).
	 * - Uses `$extra` only to add *additional* members; base keys win on conflict.
	 * - Adds `instance` from the current request URI when the Request service exists.
	 * - Uses JSON_THROW_ON_ERROR for deterministic failures.
	 * - Calls `exit;` to prevent partial responses (fail fast).
	 * - Optional pretty-print for developer inspection when `$di === true`.
	 *
	 * Notes:
	 * - Choose an appropriate 4xx/5xx `$status`.
	 * - If headers are already sent, the body is still emitted without content-type.
	 * - Caching policy is caller's choice; call `$this->noCache()` beforehand if needed.
	 *
	 * Typical usage:
	 *   $this->jsonProblem(
	 *     'Validation failed',
	 *     422,
	 *     'Email is required',
	 *     'https://example.com/probs/validation',
	 *     ['invalid_params' => [['name' => 'email', 'reason' => 'missing']]],
	 *     true // pretty JSON in dev
	 *   );
	 *
	 * @param string $title   Short, human-readable summary of the problem.
	 * @param int    $status  HTTP status code (e.g., 400, 404, 422, 500).
	 * @param string $detail  Longer explanation specific to this occurrence (optional).
	 * @param string $type    URI identifying the problem type, or 'about:blank'.
	 * @param array  $extra   Additional members to include (ignored on key collisions with core fields).
	 * @param bool   $di      Developer-inspect: when true, adds JSON_PRETTY_PRINT (default: false).
	 * @return never
	 * @throws \JsonException If JSON encoding fails.
	 */
	public function jsonProblem(string $title, int $status, string $detail = '', string $type = 'about:blank', array $extra = [], bool $di = false): never {
		
		// Base RFC 7807 members; defaults are explicit and immutable vs $extra.
		$base = [
			'type'   => $type,
			'title'  => $title,
			'status' => $status,
			'detail' => $detail,
		];

		// Include 'instance' if we can resolve the current request URI.
		if ($this->app->hasService('request')) {
			$base['instance'] = $this->app->request->uri();
		}

		// Extras extend the payload but cannot override base keys (left operand wins).
		$payload = $base + $extra;

		// Ensure the HTTP status code is applied before emitting headers/body.
		$this->setStatus($status);

		// Harden content type (no MIME sniffing) and advertise the RFC 7807 media type.
		if (!\headers_sent()) {
			\header('X-Content-Type-Options: nosniff');
			\header('Content-Type: application/problem+json; charset=' . (string)$this->app->cfg->locale->charset);
		}

		$opts = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
		if ($di) {
			$opts |= \JSON_PRETTY_PRINT;
		}
		echo \json_encode($payload, $opts);
	
		exit;
	}






/*
 *---------------------------------------------------------------
 * TEXT & HTML RESPONSES - Simple body writers
 *---------------------------------------------------------------
 * PURPOSE
 *   Output text or HTML with charset and status.
 *
 * NOTES
 *   - These write the body and then exit.
 */


	/**
	 * Sends a plain text response and terminates execution.
	 * Minimal helper for low-level probes and diagnostics.
	 *
	 * Behavior:
	 * - Sets status, sends Content-Type text/plain, echoes body.
	 *
	 * Notes:
	 * - For diagnostics or simple health checks.
	 *
	 * Typical usage:
	 *   $this->app->response->text('pong', 200);
	 *
	 * @param string $body
	 * @param int $statusCode
	 * @return never
	 */
	public function text(string $body, int $statusCode = 200): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: text/plain; charset=' . $this->app->cfg->locale->charset);
		}
		echo $body;
		exit;
	}


	/**
	 * Sends HTML body and terminate.
	 * Useful for tiny inline pages (maintenance fallback, smoke screens, etc.)
	 *
	 * Behavior:
	 * - Sets status, sends Content-Type text/html, echoes markup.
	 *
	 * Typical usage:
	 *   $this->app->response->html('<h1>Hi</h1>', 200);
	 *
	 * @param string $html
	 * @param int $statusCode
	 * @return never
	 */
	public function html(string $html, int $statusCode = 200): never {
		$this->setStatus($statusCode);
		if (!\headers_sent()) {
			\header('Content-Type: text/html; charset=' . $this->app->cfg->locale->charset);
		}
		echo $html;
		exit;
	}






/*
 *---------------------------------------------------------------
 * FILE TRANSFERS - Downloads & streaming
 *---------------------------------------------------------------
 * PURPOSE
 *   Serve files safely with correct headers and filename handling.
 *
 * NOTES
 *   - Clears output buffers; sets content-length (when determinable).
 *   - Uses RFC 5987 filename* for UTF-8; exits after streaming.
 */


	/**
	 * Streams a local file to the client as a download and terminates execution.
	 *
	 * Behavior:
	 * - Validates existence and readability.
	 * - Clears output buffers to avoid corruption, sets Content-Type, Content-Disposition,
	 *   optional Content-Length, and Cache-Control private. Disables range support.
	 * - Streams file and terminates.
	 *
	 * Sends Content-Type (best effort), Content-Length (if resolvable), and a
	 * safe Content-Disposition. Throws \RuntimeException if the file is missing
	 * or unreadable, letting your global error handler log it.
	 *
	 * Notes:
	 * - Throws RuntimeException if file is missing or unopened; bubbles to global handler.
	 * - Uses RFC 6266/5987 filename encoding with ASCII fallback.
	 *
	 * Typical usage:
	 *   $this->app->response->download(CITOMNI_APP_PATH . '/var/export/report.csv', 'report.csv');
	 *
	 * @param string $path Absolute filesystem path.
	 * @param string|null $downloadName Suggested filename for the client.
	 * @return never
	 * @throws \RuntimeException If the file does not exist or cannot be read/opened.
	 */
	public function download(string $path, ?string $downloadName = null): never {
		if (!\is_file($path) || !\is_readable($path)) {
			throw new \RuntimeException('Download source not found or unreadable: ' . $path);
		}

		// Ensure clean wire format: remove any buffered output.
		while (\ob_get_level() > 0) {
			\ob_end_clean();
		}
		\ignore_user_abort(true);

		\clearstatcache(true, $path); // Avoids stale sizes on some FSes
		$size = @\filesize($path);
		$name = $downloadName ?: \basename($path);

		// RFC 6266 / 5987 filename handling
		$fallback = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
		$disposition = "attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . \rawurlencode($name);

		$mime = 'application/octet-stream';
		if (\function_exists('mime_content_type')) {
			$detected = @\mime_content_type($path);
			if (\is_string($detected) && $detected !== '') {
				$mime = $detected;
			}
		}

		// Send headers
		$this->setStatus(200);
		\header('X-Content-Type-Options: nosniff');
		\header('Content-Type: ' . $mime);
		\header('Content-Disposition: ' . $disposition);
		if (\is_int($size) && $size >= 0) {
			\header('Content-Length: ' . $size);
		}
		\header('Cache-Control: private');  // downloads are typically private
		\header('Accept-Ranges: none');

		// Stream file
		$fh = \fopen($path, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('Failed to open download source: ' . $path);
		}
		\fpassthru($fh);
		\fclose($fh);
		exit;
	}





/*
 *---------------------------------------------------------------
 * INTERNAL UTILITIES - Keep it tidy
 *---------------------------------------------------------------
 * PURPOSE
 *   Helpers for header safety and diagnostics.
 *
 * NOTES
 *   - Private-only; no side effects beyond logging/sanitization.
 */
 

	/**
	 * Strip CR and LF to prevent header injection.
	 *
	 * @param string $value
	 * @return string
	 */
	private function sanitizeHeaderValue(string $value): string {
		return \str_replace(["\r", "\n"], '', $value);
	}


	/**
	 * Sanitize header name to token chars.
	 *
	 * Notes:
	 * - Defensive: replaces non-token chars with '-'.
	 *
	 * @param string $name
	 * @return string
	 */
	private function sanitizeHeaderName(string $name): string {
		return \preg_replace('/[^A-Za-z0-9\-]/', '-', $name) ?? 'X-Invalid-Header';
	}


	/**
	 * Log a headers-sent condition, if log service is mapped.
	 *
	 * @param string $method Calling method.
	 * @param array $context Extra context for diagnostics.
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
