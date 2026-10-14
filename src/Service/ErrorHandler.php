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
 * ErrorHandler: HTTP service that logs and renders uncaught exceptions, PHP errors, fatal errors and terminal HTTP errors.
 *
 * Gives a failed HTTP request a defined end instead of a blank page: A JSONL record with a
 * fresh error id, and an HTML or JSON response that carries the same id. It is the error
 * path, so it must keep working when cfg, templates, memory or the service graph are the
 * cause of the failure. Which errors render, how much detail a client sees and where the logs
 * go are cfg decisions. CLI error handling is left to the CLI package.
 *
 * Rules:
 * - Never let an exception escape a handler path; report internal failures to PHP's error_log.
 *   Only a value in httpError() context that cannot be cast to string or JSON-encoded may
 *   still throw; that failure is the caller's.
 * - Read cfg error_handler only in init(); later code uses the hydrated fields.
 * - Resolve no other services.
 * - Show exception and PHP error messages, traces, log records and the httpError() context to
 *   a client only when developer detail is on; the context keys title and message are client
 *   text.
 * - Pass caller-supplied context through scrubContext() before it is logged.
 * - Log the value of a trace argument only when log.trace_args is on.
 * - Mark the handler run as in progress before rendering, so a nested event never renders a
 *   second response.
 *
 * @see \CitOmni\Http\Boot\Registry::CFG_HTTP  Meaning of the error_handler cfg keys.
 */
final class ErrorHandler extends BaseService {
	
	/** JSON encoding flags: Readable slashes and Unicode; invalid input degrades instead of failing the encode. */
	private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE;

	/** Headers an abandoned response may have queued that would corrupt the error response sent in its place, such as a stale Content-Length. */
	private const REPRESENTATION_HEADERS = ['Content-Length', 'Content-Encoding', 'Content-Disposition', 'Content-Range', 'Content-Location', 'Location', 'ETag', 'Last-Modified', 'Refresh'];

	/** Bytes install() reserves and handleShutdown() releases first, so an out-of-memory fatal leaves room to log and render. */
	private const RESERVED_MEMORY_BYTES = 65536;

	/** Maximum number of previous exceptions an exception record lists. */
	private const MAX_PREVIOUS = 5;

	/** Frozen options: cfg.error_handler merged with ctor $options (last-wins). */
	private array $opt = [];

	/** Non-fatal PHP error bitmask that should render a response (fatals are shutdown-only). */
	private int $renderMask = 0;

	/** PHP error bitmask to log (applies to non-fatals here; fatals logged in shutdown). */
	private int $logMask = 0;

	/** Log directory without trailing separators. */
	private string $logDir = '';

	/** Soft pre-write rotation threshold in bytes; concurrent writers may temporarily exceed it. */
	private int $maxBytes = 2_000_000;

	/** How many rotated siblings to keep (newest first). */
	private int $maxFiles = 10;

	/** Paths of the primary and failsafe HTML templates; an empty string means none. */
	private array $templates = [
		'html'          => '',
		'html_failsafe' => '',
	];

	/** HTTP status rendered per event type: exception, shutdown and php_error. */
	private array $statusDefaults = [
		'exception' => 500,
		'shutdown'  => 500,
		'php_error' => 500,
	];

	/** True while a handler run is in progress; a run that ends in exit() leaves it true. */
	private static bool $inHandler = false;

	/** Memory held from install() until handleShutdown() releases it; null when none is held. */
	private static ?string $reservedMemory = null;

	/** Correlation id sent as X-Request-Id and logged with every record; empty until install(). */
	private string $requestId = '';

	/** Whether clients get developer details; true only in the dev environment. */
	private bool $devDetail = false;

	/** Whether logged traces carry argument values; otherwise each argument is logged as its type. */
	private bool $traceArgs = false;

	/** Maximum number of frames in a formatted trace. */
	private int $traceMaxFrames = 120;

	/** Maximum length of a string argument in a trace, in bytes. */
	private int $traceMaxArgStr = 512;

	/** Maximum number of arguments or array items shown per level in a trace. */
	private int $traceMaxItems  = 20;

	/** Maximum nesting depth of a dumped argument in a trace. */
	private int $traceMaxDepth  = 3;

	/** Marker appended where a trace string, argument list or array was cut. */
	private string $ellipsis    = '...';

	/** True once install() has completed. */
	protected bool $installed = false;



	// ----------------------------------------------------------------
	// Construction and installation
	// ----------------------------------------------------------------

	/**
	 * Merge cfg error_handler with the service options and hydrate the runtime fields.
	 *
	 * Behavior:
	 * - Service options win over cfg; the options are cleared after the merge.
	 * - A cfg read that throws counts as an empty error_handler node.
	 * - Sends no headers, changes no INI settings and registers no handlers; install() does that.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls init() during construction.
	 * @see \CitOmni\Kernel\Arr::mergeAssocLastWins()  Merge semantics.
	 * @see \CitOmni\Http\Service\ErrorHandler::hydrate()  How the merged options become fields.
	 */
	protected function init(): void {

		// Read error-handler config once and normalize to a plain array
		$cfgNode = [];

		try {
			// Input portability: Accept array or Cfg wrapper; unwrap deterministically
			$raw = $this->app->cfg->error_handler ?? [];
			$cfgNode = ($raw instanceof \CitOmni\Kernel\Cfg) ? $raw->toArray() : (\is_array($raw) ? $raw : []);
		} catch (\Throwable) {
			
			// If cfg access blows up, keep the handler alive: Fall back to an empty config.
			$cfgNode = [];
		}

		// Options win over cfg (last wins): Makes per-env or test overrides trivial.
		$this->opt = \CitOmni\Kernel\Arr::mergeAssocLastWins($cfgNode, $this->options);
		
		// Freeze options: Avoid accidental re-merge or reuse later in the request
		$this->options = [];

		// Populate internals from $this->opt only: No error_handler cfg reads beyond this point.
		$this->hydrate();
	}


	/**
	 * Populate the runtime fields from the merged options.
	 *
	 * Behavior:
	 * - Fatal-class bits are cleared from the render mask; handleShutdown() owns fatal errors.
	 * - An empty or whitespace-only log.path falls back to CITOMNI_APP_PATH . '/var/logs'.
	 * - Developer detail is on only when render.detail.level is 1 or more and CITOMNI_ENVIRONMENT is 'dev'.
	 * - Values are cast to the field types, not validated; a missing key keeps a built-in default.
	 *
	 * @return void
	 */
	private function hydrate(): void {

		$this->renderMask = (int)($this->opt['render']['trigger'] ?? 0);

		// Fatal-class levels never render from here; handleShutdown() owns them.
		// Bit trick: (~X) flips the bits in X, so the fatal flags become 0; "&=" AND-assign keeps all existing bits
		// except those flags (because bitwise AND with 0 clears them). Equivalent to: mask = mask & ~X.
		$this->renderMask &= ~(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

		// Logging: Mask and rotation knobs (defaults are broad and modest)
		$this->logMask = (int)($this->opt['log']['trigger'] ?? E_ALL);

		// Normalize log directory and rotation limits. An empty path would leave logDir
		// empty and make every log write fail, so it falls back to the default directory.
		$dir = \trim((string)($this->opt['log']['path'] ?? ''));
		if ($dir === '') {
			$dir = \CITOMNI_APP_PATH . '/var/logs';
		}
		$this->logDir   = \rtrim($dir, '/\\');
		$this->maxBytes = (int)($this->opt['log']['max_bytes'] ?? 2_000_000);
		$this->maxFiles = (int)($this->opt['log']['max_files'] ?? 10);
		$this->traceArgs = (bool)($this->opt['log']['trace_args'] ?? false);

		// Templates: Primary and failsafe files (empty strings mean "no template")
		$this->templates['html']          = (string)($this->opt['templates']['html']          ?? '');
		$this->templates['html_failsafe'] = (string)($this->opt['templates']['html_failsafe'] ?? '');

		// Status defaults: Only known buckets are honored
		foreach (['exception','shutdown','php_error'] as $k) {
			if (isset($this->opt['status_defaults'][$k])) {
				$this->statusDefaults[$k] = (int)$this->opt['status_defaults'][$k];
			}
		}

		$level = (int)($this->opt['render']['detail']['level'] ?? 0);
		$this->devDetail = ($level >= 1) && (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev');

		// Trace bounds: Keep output finite and template-safe
		$t = (array)($this->opt['render']['detail']['trace'] ?? []);
		$this->traceMaxFrames = (int)($t['max_frames']      ?? 120);
		$this->traceMaxArgStr = (int)($t['max_arg_strlen']  ?? 512);
		$this->traceMaxItems  = (int)($t['max_array_items'] ?? 20);
		$this->traceMaxDepth  = (int)($t['max_depth']       ?? 3);
		$this->ellipsis       = (string)($t['ellipsis']     ?? '...');
	}


	/**
	 * Take over PHP's exception, error and shutdown handling for this request.
	 *
	 * Call it once at boot, before anything can fail: Until then, nothing reaches the handlers,
	 * and a response from httpError() carries an empty X-Request-Id.
	 *
	 * Behavior:
	 * - Registers handleException(), handlePhpError() and handleShutdown() with PHP.
	 * - Sets display_errors to 0, and error_reporting() to render.force_error_reporting when that is an int.
	 * - Adopts the sanitized inbound X-Request-Id, or mints one, and sends it as X-Request-Id
	 *   unless headers were already sent.
	 * - Reserves RESERVED_MEMORY_BYTES of memory for handleShutdown().
	 * - A call after a completed install() does nothing.
	 *
	 * @return void
	 */
	public function install(): void {
	
		if ($this->installed) {
			return;
		}

		// Optional tuning from config: Respect an explicit error_reporting override
		if (\is_int($this->opt['render']['force_error_reporting'] ?? null)) {
			@\error_reporting((int)$this->opt['render']['force_error_reporting']);
		}

		// Never let PHP echo raw errors to the client; we own the response shape
		@\ini_set('display_errors', '0');

		// Adopt upstream X-Request-Id when present, otherwise mint a new one
		$this->requestId = $this->detectRequestId();

		// Emit correlation header if we still can (best-effort, no drama if too late)
		if (!\headers_sent()) {
			\header('X-Request-Id: ' . $this->requestId, true);
		}

		// Wire up the global hooks. From here on out, we own exceptions and fatals
		\set_exception_handler([$this, 'handleException']);
		\set_error_handler([$this, 'handlePhpError']);
		\register_shutdown_function([$this, 'handleShutdown']);

		// After an out-of-memory fatal, the heap is full until handleShutdown() frees this.
		self::$reservedMemory ??= \str_repeat("\0", self::RESERVED_MEMORY_BYTES);

		// Flip the idempotency guard last: If anything above failed, we can try again
		$this->installed = true;
	}



	// ----------------------------------------------------------------
	// Handlers
	// ----------------------------------------------------------------

	/**
	 * Log and render a terminal HTTP error, then end the request.
	 *
	 * For HTTP outcomes that Kernel, Router or an adapter decides on, such as 404, 405 or 413.
	 * An uncaught exception goes through handleException() instead.
	 *
	 * Behavior:
	 * - Writes one http_error record with a new error id and the scrubbed context to:
	 *     - http_request.jsonl when context source is 'request',
	 *     - otherwise http_router_404.jsonl, http_router_405.jsonl, http_router_5xx.jsonl for 500
	 *       and above, or http_router_other.jsonl.
	 * - Renders the status with context title, or "<status> Error", and context message, or the
	 *   status text. The context itself reaches the client only with developer detail.
	 * - A failed log write goes to PHP's error_log; the response is still rendered.
	 * - While another handler run is in progress, terminates at once without logging or
	 *   rendering; the response is what that run has emitted or buffered so far.
	 *
	 * Notes:
	 * - After this method has rendered, the handler run stays in progress, because exit() skips
	 *   the finally block. A call from a shutdown function or destructor therefore also
	 *   terminates without logging or rendering.
	 *
	 * Typical usage:
	 *   $this->app->errorHandler->httpError(404, ['reason' => 'route_not_found']);
	 *
	 * @param  int                  $status   HTTP status to send.
	 * @param  array<string,mixed>  $context  Log context. Keys title and message shape the response;
	 *                                        source 'request' selects http_request.jsonl.
	 * @return never
	 * @see \CitOmni\Http\Service\ErrorHandler::emitResponse()  Negotiation, headers and partial output.
	 */
	public function httpError(int $status, array $context = []): never {
		
		// Prevent recursive entry if we are already inside a handler. That run owns the
		// response; returning would let the caller continue past an outcome it treats as terminal.
		if (self::$inHandler) {
			exit(0);
		}
		self::$inHandler = true;

		try {
			$errorId = $this->newErrorId();

			// Best-effort scrub for sensitive content (so logs do not become a secrets vault)
			$context = $this->scrubContext($context);

			$rec = $this->baseRecord('http_error', $errorId, $status) + [
				'context' => $context,
			];

			// Route to a dedicated file per family to keep triage fast
			$logFile = match (true) {
				($context['source'] ?? null) === 'request' => $this->logDir . '/http_request.jsonl',
				$status === 404 => $this->logDir . '/http_router_404.jsonl',
				$status === 405 => $this->logDir . '/http_router_405.jsonl',
				$status >= 500  => $this->logDir . '/http_router_5xx.jsonl',
				default         => $this->logDir . '/http_router_other.jsonl',
			};
			$this->writeJsonl($logFile, $rec);

			// Terminal HTTP errors must always produce a response: No blank pages.
			$this->renderResponse($status, $errorId, [
				'title'   => (string)($context['title'] ?? "{$status} Error"),
				'message' => (string)($context['message'] ?? $this->statusText($status)),
				'details' => $this->devDetail ? $context : null, // Only in dev.
				'type'    => 'http_error',
			]);
		} finally {
			// Runs only when something above threw: exit() skips finally blocks.
			self::$inHandler = false;
		}
	}


	/**
	 * Log an uncaught exception and render a response, ending the request.
	 *
	 * install() registers it as PHP's exception handler.
	 *
	 * Behavior:
	 * - Writes one exception record to http_err_exception.jsonl: Class, code, message, throw
	 *   site, a trace bounded by the trace limits, and the previous exceptions.
	 * - Renders status_defaults.exception, whatever the exception's code. The exception message,
	 *   and the record as details, reach the client only with developer detail.
	 * - Returns without logging or rendering while another handler run is in progress.
	 *
	 * @param  \Throwable  $e  The uncaught exception.
	 * @return void
	 * @see \CitOmni\Http\Service\ErrorHandler::previousChain()  Which previous exceptions are logged, and how.
	 */
	public function handleException(\Throwable $e): void {
		
		// Re-entrancy guard: If we are already handling an error, do not spiral.
		if (self::$inHandler) {
			return;
		}
		self::$inHandler = true;

		try {
			// Pick status from config defaults (no type-mapping here by design)
			$status  = (int)($this->statusDefaults['exception'] ?? 500);
			$errorId = $this->newErrorId();

			// Assemble a bounded, JSON-serializable record for logging.
			$rec = $this->baseRecord('exception', $errorId, $status) + [
				'class'    => $e::class,
				'code'     => $e->getCode(),  // Logged as is, string codes included; never the status
				'message'  => $e->getMessage(),
				'file'     => $e->getFile(),
				'line'     => $e->getLine(),
				'trace'    => $this->traceArray($e->getTrace(), $e->getFile(), $e->getLine()),
				'previous' => $this->previousChain($e),
			];

			// Fire-and-forget JSONL write: One line, rotated by size elsewhere.
			$this->writeJsonl($this->logDir . '/http_err_exception.jsonl', $rec);

			// Only show internals to the client when explicitly in developer mode
			$details = $this->devDetail ? $rec : null;

			// Negotiate format, emit headers/body, and end the request.
			$this->renderResponse($status, $errorId, [
				'title'   => 'Unhandled Exception',
				'message' => $this->devDetail ? $e->getMessage() : 'An unexpected error occurred.',
				'details' => $details,
				'type'    => 'exception',
			]);
		} finally {
			// Runs only when something above threw: exit() skips finally blocks.
			self::$inHandler = false;
		}
	}


	/**
	 * Log a non-fatal PHP error, and render it when render.trigger includes its level.
	 *
	 * install() registers it as PHP's error handler.
	 *
	 * Behavior:
	 * - Returns false, and does nothing, for a level outside error_reporting(), which includes
	 *   diagnostics suppressed with @.
	 * - Returns false for a fatal-class level, so PHP halts and handleShutdown() logs and renders
	 *   it. E_USER_ERROR is the only one that reaches a user error handler.
	 * - Otherwise returns true, after:
	 *     - writing one php_error record to http_err_phperror.jsonl when log.trigger includes
	 *       the level,
	 *     - rendering status_defaults.php_error and ending the request when render.trigger
	 *       includes the level and no other handler run is in progress. The PHP error message,
	 *       and the record as details, reach the client only with developer detail.
	 *
	 * @param  int     $errno    PHP error level.
	 * @param  string  $errstr   Error message.
	 * @param  string  $errfile  File the error was raised in.
	 * @param  int     $errline  Line the error was raised on.
	 * @return bool  False to let PHP's own handling proceed.
	 */
	public function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool {

		// Respect @-suppression and the active error_reporting() level.
		// Since PHP 8.0 this handler is still invoked for @-suppressed diagnostics, but
		// with a reduced error_reporting() mask. Skipping them keeps the @ operator
		// working and prevents our own fail-soft, @-guarded logging/rotation I/O from
		// being re-reported (and potentially recursing) through this handler.
		if ((\error_reporting() & $errno) === 0) {
			return false;
		}

		// E_USER_ERROR is the only fatal-class error that can reach a user error handler
		// (real engine fatals bypass set_error_handler()). Returning true here would
		// SILENTLY resume execution - verified: the error then never reaches
		// error_get_last() at shutdown, so it would be swallowed with no log and no
		// response. Returning false hands it back to PHP, which halts the request and
		// populates error_get_last(), so handleShutdown() owns the terminal log+render
		// (display_errors is forced to 0 in install(), so PHP emits nothing meanwhile).
		if ($this->isFatal($errno)) {
			return false;
		}

		// Log when this level is enabled in the mask (fail-soft; safe even mid-render).
		$errorId = '';
		$rec     = null;
		if (($errno & $this->logMask) !== 0) {
			$errorId = $this->newErrorId();
			$rec = $this->baseRecord('php_error', $errorId, (int)($this->statusDefaults['php_error'] ?? 500)) + [
				'errno'   => $errno,
				'message' => $errstr,
				'file'    => $errfile,
				'line'    => $errline,
			];
			$this->writeJsonl($this->logDir . '/http_err_phperror.jsonl', $rec);
		}

		// Optional surface to the client (usually only in dev). Never re-enter: if we
		// are already inside a handler/render, skip rendering to avoid nested responses,
		// duplicated headers, and a premature exit().
		if (($errno & $this->renderMask) !== 0 && !self::$inHandler) {
			self::$inHandler = true;
			try {
				if ($errorId === '') {
					$errorId = $this->newErrorId();
				}
				$this->renderResponse((int)($this->statusDefaults['php_error'] ?? 500), $errorId, [
					'title'   => 'PHP Error',
					'message' => $this->devDetail ? $errstr : 'An unexpected error occurred.',
					'details' => $this->devDetail ? $rec : null,
					'type'    => 'php_error',
				]);
			} finally {
				self::$inHandler = false;
			}
		}

		// Tell PHP we took care of it (prevents the internal handler / double logging).
		return true;
	}


	/**
	 * Log and render the fatal error that ended the request, if there was one.
	 *
	 * install() registers it as a shutdown function.
	 *
	 * Behavior:
	 * - Releases the memory install() reserved before anything else.
	 * - Does nothing more unless error_get_last() reports a fatal-class error: E_ERROR, E_PARSE,
	 *   E_CORE_ERROR, E_COMPILE_ERROR or E_USER_ERROR.
	 * - Otherwise writes one shutdown record to http_err_shutdown.jsonl and renders
	 *   status_defaults.shutdown. The PHP error message, and the record as details, reach the
	 *   client only with developer detail.
	 * - Renders the built-in HTML page without trying the templates after an out-of-memory
	 *   fatal, and after a fatal that ended another handler run.
	 * - Renders even while another handler run is in progress, then returns: Shutdown functions
	 *   registered after this one still run, and their output follows the response.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\ErrorHandler::emitResponse()  What a render does once output was sent.
	 */
	public function handleShutdown(): void {

		// First, before anything allocates: After an out-of-memory fatal, this is the room to work in.
		self::$reservedMemory = null;

		// Ask PHP what the last error was; might be null if nothing noteworthy happened.
		$e = \error_get_last();
		if ($e === null) {
			return; // Quiet exit: Nothing to report.
		}

		// Only act on true fatal classes; everything else was or will be handled upstream.
		$errno = (int)($e['type'] ?? 0);
		if (!$this->isFatal($errno)) {
			return; // Not our party.
		}

		$message = (string)($e['message'] ?? '');

		// A template may need more memory than the reserve, and one that has just failed
		// inside another handler run may fail the same way again.
		$builtInOnly = self::$inHandler
			|| \str_starts_with($message, 'Allowed memory size of')
			|| \str_starts_with($message, 'Out of memory');

		// Pick the status code for shutdown fatals and mint a correlation-friendly id.
		$status  = (int)($this->statusDefaults['shutdown'] ?? 500);
		$errorId = $this->newErrorId();

		// Build the log record with bounded, serializable fields.
		$rec = $this->baseRecord('shutdown', $errorId, $status) + [
			'errno'   => $errno,
			'message' => $message,
			'file'    => (string)($e['file'] ?? ''),
			'line'    => (int)($e['line'] ?? 0),
		];

		// Best-effort logging: Never throw from shutdown.
		$this->writeJsonl($this->logDir . '/http_err_shutdown.jsonl', $rec);

		// Claim the handler guard so any diagnostic emitted while we render cannot
		// re-enter handlePhpError() and produce a nested/duplicate response.
		self::$inHandler = true;

		// Own the last word on the wire, without exit(): exit() in a shutdown function cancels
		// every shutdown function registered after it.
		$this->emitResponse($status, $errorId, [
			'title'   => 'Fatal Error',
			'message' => $this->devDetail ? $message : 'A fatal error occurred.',
			'details' => $this->devDetail ? $rec : null,
			'type'    => 'shutdown',
		], $builtInOnly);
	}



	// ----------------------------------------------------------------
	// Response
	// ----------------------------------------------------------------

	/**
	 * Send the error response and end the request with exit(0).
	 *
	 * @param  int                  $status   HTTP status, sent only while headers are not sent.
	 * @param  string               $errorId  Error id shown to the client.
	 * @param  array<string,mixed>  $payload  Optional keys title, message, details and type.
	 * @return never
	 * @see \CitOmni\Http\Service\ErrorHandler::emitResponse()  What is sent.
	 */
	private function renderResponse(int $status, string $errorId, array $payload): never {

		$this->emitResponse($status, $errorId, $payload, false);

		// End of the line: Deterministic termination avoids "zombie" work after an error. No "walkers" here ;)
		exit(0);
	}


	/**
	 * Send the error response without ending the request.
	 *
	 * Behavior:
	 * - While headers are not sent: Discards buffered output where PHP allows it, removes the
	 *   REPRESENTATION_HEADERS an earlier response queued, then sends the status, no-store and
	 *   nosniff headers, X-Request-Id and a body:
	 *     - JSON with error_id, type, status, title and message when the client prefers JSON;
	 *       details only with developer detail,
	 *     - otherwise the HTML page from renderHtml().
	 * - Once headers are sent: Leaves status and headers alone, and appends an HTML comment with
	 *   the error id when the response under way is HTML.
	 *
	 * Notes:
	 * - A HEAD request gets a body too.
	 * - Other headers an earlier response queued, such as Set-Cookie or Content-Security-Policy,
	 *   go out with the error response.
	 *
	 * @param  int                  $status       HTTP status, sent only while headers are not sent.
	 * @param  string               $errorId      Error id shown to the client.
	 * @param  array<string,mixed>  $payload      Optional keys title, message, details and type.
	 * @param  bool                 $builtInOnly  Render the built-in HTML page without trying the templates.
	 * @return void
	 */
	private function emitResponse(int $status, string $errorId, array $payload, bool $builtInOnly): void {

		// Decide negotiation and whether we can still replace the response
		$wantsJson  = $this->wantsJson();
		$canReplace = !\headers_sent();

		if ($canReplace) {
			// Clean slate: Drop any partial output so we present one coherent response
			$this->clearAllBuffers();

			// The abandoned response's own headers would corrupt this one: A stale
			// Content-Length cuts it short, Content-Disposition turns it into a download.
			foreach (self::REPRESENTATION_HEADERS as $name) {
				\header_remove($name);
			}

			// Status line: Force the status ourselves, overriding any raw "HTTP/..." line
			// a prior layer (Response/Router) may have queued. We intentionally avoid
			// http_response_code() here: since PHP 8.5 it emits an E_WARNING ("has no
			// effect") when a raw status line was already set - and in that case it would
			// not override it anyway. Emitting the status line ourselves is last-write-wins
			// and warning-free.
			$proto = (string)($_SERVER['SERVER_PROTOCOL'] ?? '');
			if ($proto === '' || \strncasecmp($proto, 'HTTP/', 5) !== 0) {
				$proto = 'HTTP/1.1';
			}
			\header($proto . ' ' . $status . ' ' . $this->statusText($status), true, $status);

			// Safety headers: No cache, no sniff, and include correlation id
			\header('Cache-Control: no-store, max-age=0', true);
			\header('Pragma: no-cache', true);
			\header('Expires: 0', true);
			\header('X-Content-Type-Options: nosniff', true);
			\header('X-Request-Id: ' . $this->requestId, true);

			// Negotiate body format based on Accept/XHR. Keep it small and robust
			if ($wantsJson) {
				\header('Content-Type: application/json; charset=UTF-8', true);
				$out = [
					'error_id' => $errorId,
					'type'     => (string)($payload['type'] ?? 'error'),
					'status'   => $status,
					'title'    => (string)($payload['title'] ?? $this->statusText($status)),
					'message'  => (string)($payload['message'] ?? 'An error occurred.'),
				];
				// Policy: Only leak internals in dev mode (and only if provided)
				if ($this->devDetail && isset($payload['details'])) {
					$out['details'] = $payload['details'];
				}
				echo \json_encode($out, self::JSON_FLAGS);
			} else {
				\header('Content-Type: text/html; charset=UTF-8', true);
				echo $this->renderHtml(
					$status,
					$errorId,
					[
						'title'   => (string)($payload['title'] ?? $this->statusText($status)),
						'message' => (string)($payload['message'] ?? 'An error occurred.'),
						'details' => $this->devDetail ? ($payload['details'] ?? null) : null,
						'type'    => (string)($payload['type'] ?? 'error'),
					],
					$builtInOnly
				);
			}
		} else {
			// Late stage: Headers/body already sent. Do not touch headers or status (we can't replace the response at this point)
			// Append a tiny HTML tail only to an HTML body. Avoid corrupting JSON, CSV or file streams
			if ($this->sendsHtml()) {
				echo "\n<!-- error_id={$errorId} -->";
			}
		}
	}


	/**
	 * Tell whether the client prefers JSON.
	 *
	 * @return bool  True for X-Requested-With: XMLHttpRequest, or an Accept header that contains
	 *               application/json or +json.
	 */
	private function wantsJson(): bool {
		
		$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
		$xhr    = \strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;

		if ($xhr) {
			return true; // Treat XHR as JSON by convention
		}
		if ($accept !== '' && (\stripos($accept, 'application/json') !== false || \stripos($accept, '+json') !== false)) {
			return true; // Explicit JSON (or JSON subtypes)
		}
		return false; // Fallback: Assume HTML
	}


	/**
	 * Tell whether the response already under way is HTML.
	 *
	 * @return bool  True when its Content-Type is text/html, or when it has none and PHP's
	 *               default_mimetype is text/html.
	 */
	private function sendsHtml(): bool {
		foreach (\headers_list() as $header) {
			if (\strncasecmp($header, 'Content-Type:', 13) === 0) {
				return \stripos($header, 'text/html', 13) !== false;
			}
		}

		// Without a Content-Type header, PHP sent its default_mimetype.
		return \stripos((string)\ini_get('default_mimetype'), 'text/html') !== false;
	}


	/**
	 * Discard buffered output, as far down the buffer stack as PHP allows.
	 *
	 * Behavior:
	 * - Removes output buffers from the top down, discarding their content.
	 * - Stops at the first buffer that cannot be removed, after emptying it when it can be cleaned;
	 *   that buffer and the ones below it stay.
	 * - An exception thrown by a buffer's output handler goes to PHP's error_log; the clearing
	 *   carries on.
	 *
	 * @return void
	 */
	private function clearAllBuffers(): void {
		// Drop partial output so the error response is coherent. Removable buffers are
		// popped; a non-removable buffer (e.g. zlib.output_compression, or one whose flags
		// lack REMOVABLE) cannot be popped, so we at least discard its content when it is
		// cleanable, then stop - we can neither remove it nor reach the buffers beneath it.
		// Stopping also avoids an infinite loop in the one place that must never hang.
		while (($level = \ob_get_level()) > 0) {
			try {
				$removed = @\ob_end_clean();
			} catch (\Throwable $t) {
				// The output handler threw while PHP discarded its buffer; the level check below
				// tells whether the buffer is gone.
				@\error_log('[CitOmni] output handler threw while its buffer was discarded: ' . $t->getMessage());
				$removed = true;
			}
			if ($removed && \ob_get_level() < $level) {
				continue; // Removed a buffer and made progress.
			}
			try {
				@\ob_clean(); // Non-removable: discard content if cleanable (no-op otherwise).
			} catch (\Throwable $t) {
				@\error_log('[CitOmni] output handler threw while its buffer was cleaned: ' . $t->getMessage());
			}
			break;        // Cannot pop past a buffer we cannot remove.
		}
	}


	/**
	 * Build the HTML error page from the primary template, the failsafe template or a built-in page.
	 *
	 * Behavior:
	 * - Tries the primary template, then the failsafe template; a missing file, or a template
	 *   that throws, is skipped. With $builtInOnly, neither is tried and the locale is not read.
	 * - A template gets $data with keys language, status, status_text, error_id, title, message,
	 *   details, request_id and year; details is null without developer detail.
	 * - The built-in page escapes title and message, and shows details as escaped JSON with
	 *   developer detail.
	 * - Sends no headers.
	 *
	 * Notes:
	 * - The values in $data are not escaped; a template must escape what it prints.
	 *
	 * @param  int                  $status       HTTP status shown on the page.
	 * @param  string               $errorId      Error id shown on the page.
	 * @param  array<string,mixed>  $payload      Optional keys title, message, details and type.
	 * @param  bool                 $builtInOnly  Skip the templates and build the built-in page.
	 * @return string  Complete HTML document.
	 */
	private function renderHtml(int $status, string $errorId, array $payload, bool $builtInOnly): string {

		$title   = (string)($payload['title'] ?? $this->statusText($status));
		$message = (string)($payload['message'] ?? 'An error occurred.');

		if (!$builtInOnly) {
			// Locale is read defensively: On the terminal error path we must assume config
			// or application state may itself be broken (often the very reason we are
			// rendering an error), so a missing/defective locale must never be able to throw
			// before the primary/failsafe/inline fallback chain runs. This targeted catch is
			// the sanctioned ErrorHandler exception to the no-broad-catch rule.
			try {
				$language = (string)($this->app->cfg->locale->language ?? '');
			} catch (\Throwable) {
				$language = '';
			}

			// Template data. The values are raw; a template escapes what it prints.
			$data = [
				'language'   => $language,  // Exposed for templates that localize copy
				'status'     => $status,
				'status_text'=> $this->statusText($status),
				'error_id'   => $errorId,
				'title'      => $title,
				'message'    => $message,
				'details'    => $this->devDetail ? ($payload['details'] ?? null) : null,  // Only show details in dev
				'request_id' => $this->requestId,
				'year'       => \date('Y'),
			];

			// Try the primary template first; if it throws or returns null, fall through
			$primary = (string)($this->templates['html'] ?? '');
			if ($primary !== '' && \is_file($primary)) {
				$out = $this->includeTemplate($primary, $data);
				if ($out !== null) {
					return $out;
				}
			}

			// Second chance: Failsafe template (smaller surface area; still skinnable by apps)
			$failsafe = (string)($this->templates['html_failsafe'] ?? '');
			if ($failsafe !== '' && \is_file($failsafe)) {
				$out = $this->includeTemplate($failsafe, $data);
				if ($out !== null) {
					return $out;
				}
			}
		}

		// Last resort: Minimal inline HTML (no dependencies, no surprises)
		// Title/message are HTML-escaped; details (if any) are JSON-encoded and escaped
		$title   = \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		$message = \htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		$detail = '';
		if ($this->devDetail && isset($payload['details'])) {
			$detail = '<pre style="white-space:pre-wrap;font:12px/1.4 ui-monospace,Consolas,monospace;">'
				. \htmlspecialchars(
					\json_encode($payload['details'], self::JSON_FLAGS),
					\ENT_QUOTES | \ENT_SUBSTITUTE,
					'UTF-8'
				  )
				. '</pre>';
		}
			
		return <<<HTML
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>{$status} {$this->statusText($status)}</title>
<style>
	body{margin:0;background:#0b0e13;color:#e7eaee;font:16px/1.6 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
	.wrap{max-width:880px;margin:10vh auto;padding:24px}
	.card{background:#121722;border:1px solid #1f2a3a;border-radius:16px;padding:28px;box-shadow:0 10px 24px rgba(0,0,0,.3)}
	h1{margin:0 0 8px;font-size:28px}
	.mono{font:13px/1.6 ui-monospace,Consolas,monospace;color:#b9c2cc}
	.badge{display:inline-block;border:1px solid #2a3952;background:#182033;border-radius:999px;padding:2px 10px;margin-left:8px;font:12px/1 ui-monospace,Consolas,monospace;color:#9fb2cc}
	p{margin:10px 0 0}
	hr{border:0;border-top:1px solid #263042;margin:18px 0}
	.small{opacity:.8;font-size:12px}
</style>
<div class="wrap">
	<div class="card">
		<h1>{$title}<span class="badge">{$status}</span></h1>
		<p>{$message}</p>
		<hr>
		<p class="mono small">error_id={$errorId} • request_id={$this->requestId}</p>
		{$detail}
	</div>
</div>
HTML;
	}


	/**
	 * Run a PHP template and return what it printed.
	 *
	 * Behavior:
	 * - Returns the captured output, or null when the template throws; partial output is discarded.
	 *
	 * Notes:
	 * - The template runs in this method's scope: Besides $data it sees $file, $vars and $this.
	 *
	 * @param  string               $file  PHP template file.
	 * @param  array<string,mixed>  $vars  Exposed to the template as $data.
	 * @return string|null  Template output, or null when the template threw.
	 */
	private function includeTemplate(string $file, array $vars): ?string {
		
		// Hand the values over as one $data array.
		\extract(['data' => $vars], EXTR_SKIP);

		// Capture everything the template prints. No half-printed templates today.
		\ob_start();
		
		try {
			/** @psalm-suppress UnresolvableInclude */
			include $file;

			// Success path: Take the buffer and return it as a string
			return (string)\ob_get_clean();
		} catch (\Throwable) {
			
			// Failure path: Drop any partial output and let the caller choose a fallback
			\ob_end_clean();
			return null;
		}
	}



	// ----------------------------------------------------------------
	// Logging and rotation
	// ----------------------------------------------------------------

	/**
	 * Append one record to a JSONL file, rotating the file first when the line would cross log.max_bytes.
	 *
	 * Behavior:
	 * - Creates the log directory when it is missing; losing that race to another request is not
	 *   a failure.
	 * - Appends the line under an exclusive lock. A record that cannot be encoded is written as
	 *   {"encode_error":true}.
	 * - Cuts off a line the file took only in part, so the next record does not continue it.
	 * - Reports a failure to PHP's error_log as "[CitOmni] writeJsonl failed: <reason>", naming
	 *   the file and, where PHP gave one, the cause. Nothing is thrown.
	 *
	 * Notes:
	 * - The file lock is released before rotate() runs, so a writer never waits for the rotation
	 *   lock while it holds the file lock.
	 * - log.max_bytes is a soft limit: Concurrent writers can push the live file past it until a
	 *   later rotation.
	 * - Where the lock cannot be taken, the line is still appended, but a partial line stays:
	 *   Cutting it off could cut another writer's record.
	 *
	 * @param  string               $file    Live JSONL file.
	 * @param  array<string,mixed>  $record  Record to append.
	 * @return void
	 */
	private function writeJsonl(string $file, array $record): void {

		try {
			// Make sure the log directory exists (best effort; do not escalate on failure).
			// mkdir() fails when a concurrent request created the directory first; is_dir() decides.
			if (!\is_dir($this->logDir) && !@\mkdir($this->logDir, 0775, true) && !\is_dir($this->logDir)) {
				throw new \RuntimeException('cannot create ' . $this->logDir . $this->lastFailure());
			}

			// Encode once; partial output beats losing the whole event
			$encoded = \json_encode($record, self::JSON_FLAGS);
			if ($encoded === false) {
				// Extremely rare with PARTIAL_OUTPUT_ON_ERROR, but keep the line.
				$encoded = '{"encode_error":true}';
			}
			$line = $encoded . "\n";
			$lineBytes = \strlen($line);

			$threshold = \max(1, (int)$this->maxBytes);

			// Open for append and take an exclusive lock to serialize writers
			$fh = @\fopen($file, 'ab');
			if ($fh === false) {
				throw new \RuntimeException('cannot open ' . $file . $this->lastFailure());
			}

			try {
				$locked = @\flock($fh, \LOCK_EX);

				// Fresh size under lock, read from the handle, so no stat cache is involved
				$size = $this->streamSize($fh);

				// If this write would cross the threshold, rotate first.
				if (($size + $lineBytes) > $threshold) {
					// Important: Release the main lock before rotating to avoid deadlocks
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);
					$fh = false;

					// Rotation takes a sidecar lock and does copy+truncate
					$this->rotate($file, $lineBytes);

					// Back to appending on the fresh file (reopen and re-lock after rotation).
					$fh = @\fopen($file, 'ab');
					if ($fh === false) {
						throw new \RuntimeException('cannot reopen ' . $file . ' after rotation' . $this->lastFailure());
					}
					$locked = @\flock($fh, \LOCK_EX);
					$size = $this->streamSize($fh);
				}

				// Append the line. A full disk or quota takes part of it, or none.
				$written = @\fwrite($fh, $line);
				if ($written !== $lineBytes) {
					$written = \is_int($written) ? $written : 0;
					$cause   = $this->lastFailure();

					// A partial line would run into the next record. Without the lock, the bytes
					// after $size may already belong to another writer, so they stay.
					$cut = ($written === 0) || ($locked && @\ftruncate($fh, $size));
					throw new \RuntimeException("cannot append to {$file}: {$written} of {$lineBytes} bytes written"
						. ($cut ? '' : ', the partial line remains') . $cause);
				}
			} finally {
				// Always release the lock and handle; nothing leaks
				if ($fh !== false) {
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);
				}
			}

		} catch (\Throwable $t) {
			// Fail-soft: Do not throw from an error handler; leave a breadcrumb
			@\error_log('[CitOmni] writeJsonl failed: ' . $t->getMessage());
		}
	}
	
	
	/**
	 * Move a live JSONL file's content to a timestamped sibling, unless the pending line fits after all.
	 *
	 * Behavior:
	 * - Serializes rotations of one file with a sidecar lock, <file>.lock, and works under an
	 *   exclusive lock on the live file. Without that lock it does not rotate.
	 * - Returns without rotating when the live file is empty, or when its size plus $pendingBytes
	 *   fits log.max_bytes.
	 * - Otherwise copies the content to <base>.<timestamp><ext> and truncates the live file. The
	 *   timestamp is UTC, such as 20251001T123045.582913Z, with a short hex suffix on a name
	 *   collision. The live path stays the same, so tailers keep working.
	 * - Truncates only once the copy is complete, synced to disk and renamed into place. A
	 *   failure before that removes the copy and leaves the live file as it was; a failed
	 *   truncation leaves the records in both files.
	 * - The rotated file gets the live file's permission bits.
	 * - After a rotation, prune() keeps log.max_files rotations.
	 * - Reports a failure to PHP's error_log as "[CitOmni] rotate failed: <reason>"; nothing is
	 *   thrown. A live file that cannot be opened skips the rotation silently.
	 *
	 * Notes:
	 * - The locks are advisory and assume a local filesystem. When the lock file cannot be
	 *   opened, rotations are not serialized; the lock on the live file still keeps writers out.
	 * - Where locks or fsync() fail, the live file grows past log.max_bytes instead of rotating.
	 *   A copy behind a stream wrapper other than plain files is not synced.
	 * - A record larger than log.max_bytes is appended whole to an empty live file, so consecutive
	 *   oversized records rotate one file per record.
	 *
	 * @param  string  $file          Live JSONL file.
	 * @param  int     $pendingBytes  Length of the line waiting to be appended, in bytes.
	 * @return void
	 */
	private function rotate(string $file, int $pendingBytes): void {

		$lockPath = $file . '.lock';

		// Take a sidecar lock so only one worker rotates this file at a time
		$lk = @\fopen($lockPath, 'c+b');
		if ($lk !== false) {
			@\flock($lk, \LOCK_EX);
		}

		try {
			$threshold = \max(1, (int)$this->maxBytes);

			// Open and exclusively lock the live file; 'c+b' creates it when missing, so a
			// failure here means it cannot be opened at all.
			$main = @\fopen($file, 'c+b');
			if ($main === false) {
				return; // Cannot rotate
			}

			try {
				// Copy and truncate are only safe while no writer can append in between.
				if (!@\flock($main, \LOCK_EX)) {
					throw new \RuntimeException('cannot lock ' . $file . '; not rotated');
				}

				// Fresh size under lock; another worker may have rotated just now
				$stat = @\fstat($main);
				$size = \is_array($stat) ? (int)$stat['size'] : 0;
				if ($size === 0 || ($size + $pendingBytes) <= $threshold) {
					return; // The pending write still fits, or rotating an empty file cannot help.
				}

				// Compute a Windows-safe, UTC-timestamped target name (no colons)
				[$prefix, $ext] = (static function (string $path): array {
					$dot = \strrpos($path, '.');
					return ($dot === false) ? [$path, ''] : [\substr($path, 0, $dot), \substr($path, $dot)];
				})($file);

				$ts = (static function (): string {
					// Example: 20251001T123045.582913Z
					$mt = \microtime(true);
					$dt = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $mt), new \DateTimeZone('UTC'));
					return $dt ? $dt->format('Ymd\THis.u\Z') : \gmdate('Ymd\THis\Z');
				})();

				$rotated = $prefix . '.' . $ts . $ext;

				// Paranoid but cheap: Handle extremely rare microtime collisions
				$try = 0;
				while (\is_file($rotated) && $try++ < 5) {
					$suffix = '-' . \substr($this->randomHex(2), 0, 4);
					$rotated = $prefix . '.' . $ts . $suffix . $ext;
				}

				$tmp = $rotated . '.tmp';

				// Create a temp file for the copy; prefer exclusive create, fallback if FS lacks 'x'
				$tmpH = @\fopen($tmp, 'xb');
				if ($tmpH === false) {
					$tmpH = @\fopen($tmp, 'wb');
					if ($tmpH === false) {
						throw new \RuntimeException('cannot create ' . $tmp . $this->lastFailure() . '; not rotated');
					}
				}

				// The live file is truncated only once its records are safe in the rotated file:
				// complete, synced and published under its own name. Until then, a failure must
				// leave the live file as it was.
				@\rewind($main);
				$copied  = @\stream_copy_to_stream($main, $tmpH);
				$failure = ($copied === $size) ? '' : (($copied === false) ? 'copy failed' : "copied {$copied} of {$size} bytes") . $this->lastFailure();

				// fsync() exists for plain files only; another stream wrapper keeps its own durability.
				if ($failure === '' && \stream_get_meta_data($tmpH)['wrapper_type'] === 'plainfile' && !@\fsync($tmpH)) {
					$failure = 'fsync failed';
				}
				@\fclose($tmpH);

				// Publish the rotated file atomically within the same directory
				if ($failure === '') {
					@\chmod($tmp, $stat['mode'] & 0777);
					if (!@\rename($tmp, $rotated)) {
						$failure = 'rename failed' . $this->lastFailure();
					}
				}
				if ($failure !== '') {
					@\unlink($tmp);
					throw new \RuntimeException("cannot publish {$rotated}: {$failure}; {$file} is unchanged");
				}

				// Truncate live file to zero while still locked; writers continue on the empty file
				if (!@\ftruncate($main, 0)) {
					throw new \RuntimeException("cannot truncate {$file}; its records are also in {$rotated}");
				}
			} finally {
				@\flock($main, \LOCK_UN);
				@\fclose($main);
			}

			// Keep at most N rotated siblings; leave the live file untouched
			$this->prune($file, $this->maxFiles);

		} catch (\Throwable $t) {
			// Never bubble from the error handler; record and move on
			@\error_log('[CitOmni] rotate failed: ' . $t->getMessage());

		} finally {
			// Always release the sidecar lock (even if we bailed early)
			if (\is_resource($lk)) {
				@\flock($lk, \LOCK_UN);
				@\fclose($lk);
			}
		}
	}


	/**
	 * Delete the oldest rotations of a JSONL file, keeping the newest $max.
	 *
	 * Behavior:
	 * - Counts as a rotation only a file next to $file whose name rotate() could have created:
	 *   <base>.<UTC timestamp><ext>, with or without a collision suffix. Other files, the
	 *   rotations of other logs included, are left alone.
	 * - Keeps the $max newest by the timestamp in their names and deletes the rest; a failed
	 *   deletion is ignored.
	 *
	 * Notes:
	 * - Reads the directory one entry at a time and holds only this log's rotations, so its
	 *   memory does not grow with the other files in the directory.
	 *
	 * @param  string  $file  Live JSONL file.
	 * @param  int     $max   Number of rotations to keep; 0 or less deletes nothing.
	 * @return void
	 */
	public function prune(string $file, int $max): void {

		// Retention disabled or nothing to do
		if ($max <= 0) {
			return;
		}

		$dir  = \dirname($file);
		$name = \basename($file);
		$dot  = \strrpos($name, '.');
		$base = ($dot === false) ? $name : \substr($name, 0, $dot);
		$ext  = ($dot === false) ? '' : \substr($name, $dot);

		$pattern = '~\A' . \preg_quote($base, '~') . '\.\d{8}T\d{6}(?:\.\d{6})?Z(?:-[0-9a-f]{4})?' . \preg_quote($ext, '~') . '\z~';

		// Entry by entry: A full listing costs memory for every file in the directory, and
		// handleShutdown() may be running on the memory reserve. Not glob(): The directory
		// path may contain glob syntax.
		$dh = @\opendir($dir);
		if ($dh === false) {
			return;
		}
		$rotations = [];
		while (($entry = \readdir($dh)) !== false) {
			if (\preg_match($pattern, $entry) === 1) {
				$rotations[] = $entry;
			}
		}
		\closedir($dh);

		if (\count($rotations) <= $max) {
			return;
		}

		// The UTC timestamps sort chronologically as strings; newest first.
		\rsort($rotations, \SORT_STRING);

		// Delete everything after the first $max. Best-effort: ignore unlink failures.
		foreach (\array_slice($rotations, $max) as $entry) {
			@\unlink($dir . '/' . $entry);
		}
	}


	/**
	 * Return the size of an open file.
	 *
	 * @param  resource  $handle  Open file.
	 * @return int  Size in bytes; 0 when it cannot be read.
	 */
	private function streamSize(mixed $handle): int {
		$stat = @\fstat($handle);
		return \is_array($stat) ? (int)$stat['size'] : 0;
	}


	/**
	 * Return PHP's last diagnostic when this file raised it, for an error_log breadcrumb.
	 *
	 * Right after a failed operation that raises one, such as fopen(), mkdir(), rename() or a
	 * write to a plain file, that diagnostic is the operation's cause. After an operation that
	 * fails without one, it can be an earlier diagnostic from this file.
	 *
	 * @return string  ": <diagnostic>", or an empty string when there is none or another file raised it.
	 */
	private function lastFailure(): string {
		$last = \error_get_last();
		return ($last !== null && $last['file'] === __FILE__) ? ': ' . $last['message'] : '';
	}



	// ----------------------------------------------------------------
	// Records and ids
	// ----------------------------------------------------------------

	/**
	 * Build the fields every log record shares.
	 *
	 * Behavior:
	 * - The request fields other than request_id come from $_SERVER as sent, or are empty when
	 *   missing. The query string is cut from the URI, so it cannot leak secrets into the log.
	 * - ts is ISO 8601 in PHP's default timezone.
	 *
	 * @param  string  $type     Event type: exception, shutdown, php_error or http_error.
	 * @param  string  $errorId  Error id of the event.
	 * @param  int     $status   HTTP status the event renders.
	 * @return array{ts:string, error_id:string, type:string, status:int, request:array{method:string, uri:string, host:string, ua:string, ip:string, request_id:string}}
	 */
	private function baseRecord(string $type, string $errorId, int $status): array {
		return [
			'ts'       => \date('c'),  // ISO 8601 with timezone offset
			'error_id' => $errorId,  // Stable id shown to client and used in logs
			'type'     => $type,
			'status'   => $status,
			'request'  => [
				'method'     => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
				// Strip query: No secrets in logs. Not strtok(): handlePhpError() resumes the
				// application, which may be in the middle of its own strtok() loop.
				'uri'        => \explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2)[0],
				'host'       => (string)($_SERVER['HTTP_HOST'] ?? ''),  // As sent; may be spoofed by clients
				'ua'         => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),  // Logged verbatim for triage
				'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),  // Direct client IP (proxies not resolved here)
				'request_id' => $this->requestId,  // Set in install(); used for cross-system correlation
			],
		];
	}


	/**
	 * Summarize the previous-exception chain for an exception record.
	 *
	 * Behavior:
	 * - Follows getPrevious() from the exception's direct cause toward the root cause, and stops
	 *   after MAX_PREVIOUS entries.
	 * - Each entry has the class, code, message, file and line; no trace.
	 *
	 * @param  \Throwable  $e  The uncaught exception.
	 * @return list<array{class: string, code: mixed, message: string, file: string, line: int}>  Empty when it has no previous exception.
	 */
	private function previousChain(\Throwable $e): array {
		$chain = [];
		for ($prev = $e->getPrevious(); $prev !== null && \count($chain) < self::MAX_PREVIOUS; $prev = $prev->getPrevious()) {
			$chain[] = [
				'class'   => $prev::class,
				'code'    => $prev->getCode(),
				'message' => $prev->getMessage(),
				'file'    => $prev->getFile(),
				'line'    => $prev->getLine(),
			];
		}
		return $chain;
	}


	/**
	 * Return the sanitized inbound X-Request-Id, or a new id when nothing survives sanitizing.
	 *
	 * @return string  Inbound id, or a new id formatted YYYYMMDDHHMMSS-XXXX-xxxxxxxx with
	 *                 uppercase and lowercase hex digits.
	 * @see \CitOmni\Http\Service\ErrorHandler::sanitizeId()  What survives sanitizing.
	 */
	private function detectRequestId(): string {
		
		// Sanitize FIRST, then test: a header made only of characters the whitelist
		// strips would otherwise pass the "!== ''" check and yield an empty id.
		$hdr = $this->sanitizeId((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
		
		if ($hdr !== '') {
			return $hdr; // Trusted-but-sanitized inbound id
		}
		
		// Very cheap, unique-enough id: time + random
		// Example: 20251001123045-7A2F-6f4d91c8
		return \date('YmdHis') . '-' . \strtoupper($this->randomHex(2)) . '-' . \substr($this->randomHex(5), 0, 8);
	}


	/**
	 * Reduce a candidate id to a safe ASCII subset.
	 *
	 * Behavior:
	 * - Cuts the input to 128 bytes, then removes every character other than A-Z, a-z, 0-9, dot,
	 *   underscore and hyphen.
	 *
	 * @param  string  $s  Candidate id, possibly hostile.
	 * @return string  Sanitized id; empty when nothing survives.
	 */
	private function sanitizeId(string $s): string {
		$s = \substr($s, 0, 128);  // Hard length cap
		return (string)\preg_replace('~[^a-zA-Z0-9._\-]~', '', $s);  // ASCII whitelist
	}


	/**
	 * Return a new error id: "e_" followed by 16 lowercase hex characters.
	 *
	 * @return string
	 * @see \CitOmni\Http\Service\ErrorHandler::randomHex()  Randomness and its fallback.
	 */
	private function newErrorId(): string {
		return 'e_' . \substr($this->randomHex(8), 0, 16);  // 8 bytes -> 16 hex chars
	}


	/**
	 * Return random bytes as lowercase hex, without ever throwing.
	 *
	 * Behavior:
	 * - Uses random_bytes(). If that throws, falls back to mt_rand(), which is not
	 *   cryptographically strong; correlation ids do not need that.
	 *
	 * @param  int  $bytes  Number of random bytes.
	 * @return string  Hex string of 2 * $bytes characters.
	 */
	private function randomHex(int $bytes): string {
		try {
			return \bin2hex(\random_bytes($bytes));
		} catch (\Throwable) {
			// Fail-soft: Never throw from within an error handler.
			$s = '';
			for ($i = 0; $i < $bytes; $i++) {
				$s .= \sprintf('%02x', \mt_rand(0, 255));
			}
			return $s;
		}
	}
	
	
	/**
	 * Return a copy of a context array with the values of sensitive keys replaced by "[redacted]".
	 *
	 * Behavior:
	 * - A key is sensitive when its lowercase form is one of authorization, cookie, set-cookie,
	 *   password, passwd, token, access_token, refresh_token, secret, api_key or apikey.
	 * - Nested arrays are scrubbed at every depth; objects are not traversed.
	 *
	 * Notes:
	 * - Only keys are matched: A secret under any other key, or inside a value, is logged as it is.
	 *
	 * @param  array<array-key,mixed>  $ctx  Context to scrub.
	 * @return array<array-key,mixed>  Scrubbed copy.
	 */
	private function scrubContext(array $ctx): array {

		// Keys we always redact (lowercase for comparisons)
		$redactKeys = [
			'authorization','cookie','set-cookie','password','passwd',
			'token','access_token','refresh_token','secret','api_key','apikey',
		];

		$out = [];

		foreach ($ctx as $k => $v) {
			// Case-insensitive key match (cheap and reliable)
			$lower = \strtolower((string)$k);

			// Known sensitive key: Replace value with a marker.
			if (\in_array($lower, $redactKeys, true)) {
				$out[$k] = '[redacted]';
				continue;
			}

			// Nested structure: Recurse into arrays; leave scalars/others untouched.
			$out[$k] = \is_array($v) ? $this->scrubContext($v) : $v;
		}

		return $out;
	}



	// ----------------------------------------------------------------
	// Classification
	// ----------------------------------------------------------------

	/**
	 * Tell whether a PHP error level is fatal-class.
	 *
	 * @param  int  $errno  PHP error level.
	 * @return bool  True for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR and E_USER_ERROR.
	 */
	private function isFatal(int $errno): bool {
		// Policy: Treat user-triggered fatal (E_USER_ERROR) like a core fatal to avoid partial output
		return (\in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true));
	}


	/**
	 * Return the reason phrase for an HTTP status.
	 *
	 * @param  int  $code  HTTP status.
	 * @return string  The phrase for a status on the curated list, otherwise "HTTP Error".
	 */
	private function statusText(int $code): string {
		
		// Intentional: Curated phrases only; unknowns use a safe generic
		return match ($code) {
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			409 => 'Conflict',
			413 => 'Content Too Large',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			default => 'HTTP Error',
		};
	}



	// ----------------------------------------------------------------
	// Trace formatting
	// ----------------------------------------------------------------

	/**
	 * Format a backtrace as a bounded list of frames for logs and developer detail.
	 *
	 * Behavior:
	 * - The first frame is the throw site, with function "(thrown)".
	 * - A trace longer than render.detail.trace.max_frames is cut there and ends with
	 *   ['ellipsis' => true].
	 *
	 * @param  array<int,array<string,mixed>>  $trace  Trace from Throwable::getTrace().
	 * @param  string                          $file   Throw-site file.
	 * @param  int                             $line   Throw-site line.
	 * @return list<array{file:string, line:int, function:string}|array{file:string, line:int, call:string}|array{ellipsis:true}>
	 */
	private function traceArray(array $trace, string $file, int $line): array {
		
		// Always start with the throw site for context
		$out = [['file' => $file, 'line' => $line, 'function' => '(thrown)']];
		$count = 0;

		foreach ($trace as $f) {
			
			// Guard against huge traces
			if ($count++ >= $this->traceMaxFrames) {
				$out[] = ['ellipsis' => true]; // Signal truncation explicitly
				break;
			}

			$out[] = [
				'file' => (string)($f['file'] ?? ''),
				'line' => (int)($f['line'] ?? 0),
				'call' => $this->formatCall($f), // Compact signature + args with limits
			];
		}

		return $out;
	}


	/**
	 * Render one trace frame as "Class->method(args)", "Class::method(args)" or "function(args)".
	 *
	 * Behavior:
	 * - Shows at most render.detail.trace.max_array_items arguments, then the ellipsis marker.
	 * - Shows argument values only when log.trace_args is on; otherwise each argument's type.
	 * - A frame without a function name shows "unknown".
	 *
	 * @param  array<string,mixed>  $f  One frame of a backtrace.
	 * @return string
	 * @see \CitOmni\Http\Service\ErrorHandler::dumpArg()  How a value is shown.
	 * @see \CitOmni\Http\Service\ErrorHandler::describeArg()  How a type is shown.
	 */
	private function formatCall(array $f): string {
		
		// Callable name: Class{->|::}function or plain function
		$fn = (string)($f['function'] ?? 'unknown');
		$cls = (string)($f['class'] ?? '');
		$type = (string)($f['type'] ?? '');
		$call = ($cls !== '') ? ($cls . $type . $fn) : $fn;

		// Argument list with strict caps
		$args = [];
		if (isset($f['args']) && \is_array($f['args'])) {
			$i = 0;
			foreach ($f['args'] as $a) {
				if ($i++ >= $this->traceMaxItems) {
					$args[] = $this->ellipsis; // Too many args; hint and stop
					break;
				}
				$args[] = $this->traceArgs ? $this->dumpArg($a, 0) : $this->describeArg($a);
			}
		}

		return $call . '(' . \implode(', ', $args) . ')';
	}


	/**
	 * Render one trace argument as its type, without its value.
	 *
	 * @param  mixed  $v  Argument.
	 * @return string  "string(<bytes>)", "array(<count>)", "object(<class>)" or the type name, such as int or null.
	 */
	private function describeArg(mixed $v): string {
		return match (true) {
			\is_string($v) => 'string(' . \strlen($v) . ')',
			\is_array($v)  => 'array(' . \count($v) . ')',
			\is_object($v) => 'object(' . $v::class . ')',
			\is_int($v)    => 'int',
			\is_float($v)  => 'float',
			\is_bool($v)   => 'bool',
			\is_null($v)   => 'null',
			default        => \gettype($v),
		};
	}


	/**
	 * Render one trace argument as a bounded string.
	 *
	 * Behavior:
	 * - Strings are quoted and cut, arrays are dumped item by item, objects become
	 *   "object(<class>)" and other values their literal or type name.
	 * - At render.detail.trace.max_depth, the value becomes the ellipsis marker.
	 *
	 * @param  mixed  $v      Value to render.
	 * @param  int    $depth  Current nesting depth; 0 for a top-level argument.
	 * @return string
	 */
	private function dumpArg(mixed $v, int $depth): string {
		
		// Depth guard: Avoid runaway recursion.
		if ($depth >= $this->traceMaxDepth) {
			return $this->ellipsis;
		}

		// Keep it deterministic and side-effect free
		return match (true) {
			\is_string($v) => $this->truncate($v),
			\is_int($v), \is_float($v) => (string)$v,
			\is_bool($v) => $v ? 'true' : 'false',
			\is_null($v) => 'null',
			\is_array($v) => $this->dumpArray($v, $depth + 1),
			\is_object($v) => 'object(' . $v::class . ')',
			default => gettype($v),
		};
	}


	/**
	 * Quote a string for a trace, cut to render.detail.trace.max_arg_strlen bytes with the ellipsis marker.
	 *
	 * @param  string  $s  String to quote.
	 * @return string  Single-quoted string; quotes and line breaks inside it are kept as they are.
	 */
	private function truncate(string $s): string {
		
		if (\strlen($s) <= $this->traceMaxArgStr) {
			return "'" . $s . "'";
		}
		
		return "'" . \substr($s, 0, $this->traceMaxArgStr) . $this->ellipsis . "'";
	}


	/**
	 * Render an array for a trace as [key=>value, ...].
	 *
	 * Behavior:
	 * - Keys are JSON-encoded; values are rendered by dumpArg() at the given depth.
	 * - Shows at most render.detail.trace.max_array_items items, then the ellipsis marker.
	 *
	 * @param  array<array-key,mixed>  $a      Array to render.
	 * @param  int                     $depth  Nesting depth of the array's items.
	 * @return string
	 */
	private function dumpArray(array $a, int $depth): string {
		
		$items = [];
		$i = 0;

		foreach ($a as $k => $v) {
			
			// Keep arrays readable and bounded
			if ($i++ >= $this->traceMaxItems) {
				$items[] = $this->ellipsis;
				break;
			}
			
			// json_encode() keeps keys unambiguous (quotes, escapes)
			$items[] = \json_encode($k, self::JSON_FLAGS) . '=>' . $this->dumpArg($v, $depth);
		}

		return '[' . \implode(', ', $items) . ']';
	}


}
