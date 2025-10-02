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
 * ErrorHandler: HTTP-only global error/exception/shutdown handler for CitOmni.
 *
 * Responsibilities:
 * - Install process-wide handlers and guarantee "no blank page".
 *   1) Registers exception, error, and shutdown handlers.
 *   2) Always logs; always renders for fatals/exceptions/router; optional render for non-fatals.
 *   3) Terminates deterministically after rendering (exit(0)).
 * - Emit safe, negotiated responses (HTML/JSON) with correlation.
 *   1) Adds X-Request-Id (uses inbound header if present, otherwise generates).
 *   2) Sends no-store and X-Content-Type-Options=nosniff headers.
 *   3) Replaces any partial output when possible; otherwise appends a tiny HTML tail (error_id).
 * - Write robust JSONL logs with rotation.
 *   1) Atomic-enough append with flock.
 *   2) Size-based rotation via sidecar lock; timestamped files.
 *   3) Prunes rotated siblings according to max_files.
 * - Respect environment and developer ergonomics.
 *   1) Hides details by default; shows traces only in dev and when enabled.
 *   2) Redacts sensitive keys in router context (authorization, tokens, etc.).
 *   3) Never throws from handlers; failures degrade to error_log.
 *
 * Collaborators:
 * - App container (read-only): Reads $this->app->cfg->error_handler during init.
 * - Router: Calls httpError(404|405|5xx, array $context) for HTTP-layer errors.
 * - BaseService: Construction/options plumbing; no other services are resolved here.
 *
 * Configuration keys:
 * - error_handler.render.force_error_reporting (int|null) - If set, forces error_reporting() on install.
 * - error_handler.render.trigger (int bitmask) - Non-fatal PHP errors that should render to the client.
 *   Note: Fatal classes (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR) are ignored here
 *   and handled exclusively by the shutdown handler.
 * - error_handler.render.detail.level (int: 0|1) - 0=minimal, 1=developer details (effective only in dev).
 * - error_handler.render.detail.trace.max_frames (int, default 120)
 * - error_handler.render.detail.trace.max_arg_strlen (int, default 512)
 * - error_handler.render.detail.trace.max_array_items (int, default 20)
 * - error_handler.render.detail.trace.max_depth (int, default 3)
 * - error_handler.render.detail.trace.ellipsis (string, default "...")
 * - error_handler.log.trigger (int bitmask, default E_ALL) - Which PHP errors to log (non-fatal path).
 * - error_handler.log.path (string) - Directory for JSONL logs.
 * - error_handler.log.max_bytes (int, default 2_000_000) - Rotation threshold per live file.
 * - error_handler.log.max_files (int, default 10) - Max rotated siblings to retain (pruned newest-first).
 * - error_handler.templates.html (string) - Primary HTML template path.
 * - error_handler.templates.html_failsafe (string) - Minimal fallback template path.
 * - error_handler.status_defaults.exception|shutdown|php_error|http_error (int HTTP status, default 500).
 *
 * Error handling:
 * - Fail-soft inside handlers: Never throw; unexpected failures are sent to PHP's error_log.
 * - Reentrancy guard prevents recursive handling.
 * - E_USER_ERROR is treated as fatal: Short-circuited in handlePhpError() so shutdown owns logging/render.
 * - If headers were already sent, only a neutral HTML comment with error_id is appended (no header changes).
 *
 * Typical usage:
 *
 *   // HTTP kernel bootstrap
 *   ob_start();           // prevent partial output
 *   ob_implicit_flush(false);
 *   $app = new App($configDir, Mode::HTTP);
 *   $app->errorHandler->install();   // idempotent
 *
 *   // In the router when producing HTTP-layer errors:
 *   $app->errorHandler->httpError(404, ['title' => 'Not Found', 'message' => 'Route not found']);
 *
 * Examples:
 *
 *   // Enable developer details in dev via cfg:
 *   // error_handler.render.detail.level = 1   (effective only when CITOMNI_ENVIRONMENT === 'dev')
 *
 *   // Render notices/warnings in dev, but never in prod:
 *   // error_handler.render.trigger = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
 *
 * Failure:
 *
 *   // Any uncaught exception or fatal error:
 *   // - Logged to JSONL with a fresh error_id
 *   // - Returned as HTML/JSON (negotiated), minimal in prod, detailed in dev (if enabled)
 *   // - Process ends via exit(0) to stop the bleeding
 *
 * Notes:
 * - HTTP-only by design. CLI error handling should live in a separate CLI handler.
 * - This service reads cfg once during init; runtime behavior is driven by those frozen options.
 * - Humor is allowed, but blank pages are not.
 */
final class ErrorHandler extends BaseService {
	
	/** Frozen options: cfg.error_handler merged with ctor $options (last-wins). */
	private array $opt = [];

	/** Non-fatal PHP error bitmask that should render a response (fatals are shutdown-only). */
	private int $renderMask = 0;

	/** PHP error bitmask to log (applies to non-fatals here; fatals logged in shutdown). */
	private int $logMask = 0;

	/** Absolute log directory (no trailing slash). */
	private string $logDir = '';

	/** Max size in bytes of the live JSONL file before rotation. */
	private int $maxBytes = 2_000_000;

	/** How many rotated siblings to keep (newest first). */
	private int $maxFiles = 10;
	// /** If we ever bulk-delete old rotations, threshold goes here. */
	// private int $bulkDeleteThreshold = 10;

	/** Template paths: Primary HTML and minimal failsafe. */
	private array $templates = [
		'html'          => '',
		'html_failsafe' => '',
	];

	/** Default HTTP statuses per event type (used unless overridden at call site). */
	private array $statusDefaults = [
		'exception' => 500,
		'shutdown'  => 500,
		'php_error' => 500,
		'http_error'=> 500,
	];

	/** Reentrancy guard: Prevents recursive handling/loops. */
	private static bool $inHandler = false;

	/** Per-request correlation id (propagated via X-Request-Id). */
	private string $requestId = '';

	/** When true, include developer details (only effective in dev). */
	private bool $devDetail = false;

	/** Trace shaping: Hard caps to keep output safe and bounded. */
	private int $traceMaxFrames = 120;
	private int $traceMaxArgStr = 512;
	private int $traceMaxItems  = 20;
	private int $traceMaxDepth  = 3;
	/** Ellipsis marker for truncated strings/collections. */
	private string $ellipsis    = '...';

	/** Idempotency flag: Ensures install() runs only once. */
	protected bool $installed = false;




/*
 *---------------------------------------------------------------
 * BOOTSTRAP & CONFIG
 *---------------------------------------------------------------
 * PURPOSE
 *   Read configuration, merge options, and install global handlers.
 *
 * NOTES
 *   - Must not resolve other services.
 *   - Keep side effects only in install(); init()/hydrate() are pure-ish.
 *
 */

	/**
	 * Initialize configuration from cfg and constructor options, then hydrate fields.
	 *
	 * Behavior:
	 * - Reads cfg->error_handler exactly once and unwraps it to a plain array.
	 * - Merges ctor $this->options over cfg using deterministic "last wins".
	 * - Freezes runtime options by clearing $this->options after the merge.
	 * - Calls hydrate() to populate all internal fields from $this->opt.
	 * - Swallows cfg read failures (\Throwable) and falls back to an empty config.
	 *
	 * Notes:
	 * - No global side effects: Does not emit headers, modify INI, or resolve services.
	 * - Input shape is tolerant: Accepts either an array or a CitOmni\Kernel\Cfg.
	 * - Determinism: Given the same cfg and options, results are identical across runs.
	 * - This method never throws; failures are treated as "no config".
	 *
	 * Typical usage:
	 *   Called automatically during service construction. Consumers should not call it directly.
	 *
	 * Examples:
	 *   // Service wiring with options that override cfg (last wins):
	 *   // $app->errorHandler is created by the container with:
	 *   //   options = ['render' => ['trigger' => E_WARNING | E_NOTICE]]
	 *   // If cfg has a different 'render.trigger', the options value takes precedence.
	 *
	 * @return void
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
		$this->options = []; // freeze & free

		// Populate internals from $this->opt only: No more cfg reads beyond this point.
		$this->hydrate(); // uses $this->opt
	}


	/**
	 * Hydrate internal fields from merged options.
	 *
	 * Behavior:
	 * - Reads pre-merged $this->opt and assigns strongly-typed properties.
	 * - Sanitizes the render mask to exclude fatal-class errors (shutdown owns those).
	 * - Loads logging configuration (mask, directory, rotation limits).
	 * - Resolves template paths and status defaults for each error bucket.
	 * - Computes developer-detail gating from cfg + environment.
	 * - Applies bounded trace limits (frames, arg length, array items, depth, ellipsis).
	 *
	 * Notes:
	 * - Pure in-memory: No I/O, no service lookups, no global effects.
	 * - Fail-soft casting: Non-integer or missing values are coerced to safe defaults.
	 * - Policy: Fatal-class errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR)
	 *   never trigger render here; they are handled in the shutdown path to avoid partial output.
	 * - Security: Developer details are emitted only when explicitly enabled and in "dev".
	 *
	 * Typical usage:
	 *   Called by init() after cfg+options have been merged; safe to re-run if options change.
	 *
	 * Examples:
	 *
	 *   // Default setup after init()
	 *   $handler->hydrate(); // Uses E_ALL for logging if not provided.
	 *
	 *   // Custom trace bounds picked from options
	 *   $handler->hydrate(); // Applies max_frames/max_depth/etc. caps for safe output.
	 *
	 * Failure:
	 * - Never throws; invalid inputs are sanitized or ignored.
	 *
	 * @return void
	 */
	private function hydrate(): void {

		// Render trigger from options; keep only non-fatal bits (shutdown owns fatals).
		$this->renderMask = (int)($this->opt['render']['trigger'] ?? 0);
		
		// Sanitize: Never allow fatal classes here; those are handled by shutdown()
		// Clear fatal PHP error bits from $this->renderMask so only non-fatal levels can trigger rendering.
		// Bit trick: (~X) flips the bits in X, so the fatal flags become 0; "&=" AND-assign keeps all existing bits
		// except those flags (because bitwise AND with 0 clears them). Equivalent to: mask = mask & ~X.
		$this->renderMask &= ~(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

		// Logging: Mask and rotation knobs (defaults are broad and modest)
		$this->logMask = (int)($this->opt['log']['trigger'] ?? E_ALL);

		// Normalize log directory and rotation limits
		$dir = (string)($this->opt['log']['path'] ?? (\CITOMNI_APP_PATH . '/var/logs'));
		$this->logDir   = \rtrim($dir, '/\\');
		$this->maxBytes = (int)($this->opt['log']['max_bytes'] ?? 2_000_000);
		$this->maxFiles = (int)($this->opt['log']['max_files'] ?? 10);

		// Templates: Primary and failsafe files (empty strings mean "no template")
		$this->templates['html']          = (string)($this->opt['templates']['html']          ?? '');
		$this->templates['html_failsafe'] = (string)($this->opt['templates']['html_failsafe'] ?? '');

		// Status defaults: Only known buckets are honored
		foreach (['exception','shutdown','php_error','http_error'] as $k) {
			if (isset($this->opt['status_defaults'][$k])) {
				$this->statusDefaults[$k] = (int)$this->opt['status_defaults'][$k];
			}
		}

		// Developer detail: Enabled only if cfg level >= 1 AND environment is dev
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
	 * Install global handlers and finalize the error-handling environment.
	 *
	 * Behavior:
	 * - Installs exception, error, and shutdown handlers for the current request.
	 * - Optionally overrides PHP's error_reporting (when configured).
	 * - Forces display_errors=0 to avoid accidental plaintext leaks to clients.
	 * - Ensures every request has a correlation id and emits the X-Request-Id header.
	 * - Idempotent: A second (or tenth) call is a no-op.
	 *
	 * Notes:
	 * - Uses only pre-hydrated options; does not resolve other services.
	 * - Header emission is best-effort and only attempted if headers are not sent.
	 * - The @-operators are intentional here to favor "fail soft" in hostile environments.
	 *
	 * Typical usage:
	 *   Call once during HTTP bootstrap right after creating the App container.
	 *
	 * Examples:
	 *
	 *   // Kernel boot path (happy path):
	 *   $app = CitOmni\Http\Kernel::boot($entryPath);
	 *   $app->errorHandler->install(); // Handlers are now active for this request.
	 *
	 *   // Idempotency:
	 *   $app->errorHandler->install(); // No side effects the second time.
	 *
	 * @return void
	 */
	public function install(): void {
	
		// Already installed for this request? Nothing to do
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

		// Flip the idempotency guard last: If anything above failed, we can try again
		$this->installed = true;
	}	
	





/*
 *---------------------------------------------------------------
 * PUBLIC ENTRY POINTS (HTTP & GLOBAL HANDLERS)
 *---------------------------------------------------------------
 * PURPOSE
 *   External-facing entry points that log and render errors.
 *
 * NOTES
 *   - Always log; rendering is conditional only for non-fatals per cfg.
 *   - Guard re-entrancy via static $inHandler.
 *   - E_USER_ERROR flow: Some PHP setups route E_USER_ERROR through
 *     set_error_handler(). This handler treats it as fatal and returns
 *     early so the shutdown handler owns logging/rendering. This avoids
 *     duplicate logs and partial responses (see isFatal() guard).
 *
 */

	/**
	 * Emit and log an HTTP-layer error (404/405/5xx) on behalf of the Router.
	 *
	 * Behavior:
	 * - Generates a new error id and writes a structured JSONL record
	 * - Redacts obvious secrets in the provided $context (tokens, cookies, etc.)
	 * - Picks a log file based on status class (404, 405, 5xx, other)
	 * - Always renders a client response (HTML or JSON via content negotiation)
	 * - Re-entrancy-safe: A concurrent handler run is ignored
	 *
	 * Notes:
	 * - Intended for Router-originated errors only; application exceptions go through handleException().
	 * - Logging is fail-soft; errors during logging are routed to PHP's error_log.
	 * - The response includes X-Request-Id and a stable error_id for support correlation.
	 *
	 * Typical usage:
	 *   Call when the Router decides on a terminal HTTP outcome and no controller will run.
	 *
	 * Examples:
	 *
	 *   // 404: Route not found
	 *   $this->app->errorHandler->httpError(404, [
	 *     'title'   => 'Not Found',
	 *     'message' => 'No matching route.',
	 *     'route'   => $requestedPath,
	 *   ]);
	 *
	 *   // 405: Method not allowed (Router may also add an Allow header elsewhere)
	 *   $this->app->errorHandler->httpError(405, [
	 *     'title'   => 'Method Not Allowed',
	 *     'message' => 'Use one of: GET, POST.',
	 *   ]);
	 *
	 *   // 500: Router decided this is a server error
	 *   $this->app->errorHandler->httpError(500, [
	 *     'title'   => 'Internal Server Error',
	 *     'message' => 'Unexpected router failure.',
	 *   ]);
	 *
	 * @param int   $status  HTTP status (404, 405, 500, ...).
	 * @param array $context Optional, non-sensitive metadata to log and (in dev) echo back.
	 * @return void
	 */
	public function httpError(int $status, array $context = []): void {
		
		// Prevent recursive entry if we are already inside a handler.
		if (self::$inHandler) {
			return;
		}
		self::$inHandler = true;

		try {
			$errorId = $this->newErrorId();

			// Best-effort scrub for sensitive content (so logs do not become a secrets vault)
			$context = $this->scrubContext($context);

			// Base record + router context for the log.
			$rec = $this->baseRecord('http_error', $errorId, $status) + [
				'context' => $context,
			];

			// Route to a dedicated file per family to keep triage fast
			$logFile = match (true) {
				$status === 404 => $this->logDir . '/http_router_404.jsonl',
				$status === 405 => $this->logDir . '/http_router_405.jsonl',
				$status >= 500  => $this->logDir . '/http_router_5xx.jsonl',
				default         => $this->logDir . '/http_router_other.jsonl',
			};
			$this->writeJsonl($logFile, $rec);

			// Router errors must always produce a response: No blank pages.
			$this->renderResponse($status, $errorId, [
				'title'   => (string)($context['title'] ?? "{$status} Error"),
				'message' => (string)($context['message'] ?? $this->statusText($status)),
				'details' => $this->devDetail ? $context : null, // Only in dev.
				'type'    => 'http_error',
			]);
		} finally {
			// Release the re-entrancy guard even if something above failed
			self::$inHandler = false;
		}
	}


	/**
	 * Handle an uncaught exception and terminate the request with a safe response.
	 *
	 * Behavior:
	 * - Applies a simple re-entrancy guard to avoid recursive handler loops.
	 * - Builds a bounded, scrubbable trace via traceArray(...) (frame/arg/depth limits).
	 * - Logs a structured JSONL record (one line per event) with a fresh error id.
	 * - Chooses the HTTP status from statusDefaults['exception'] (defaults to 500).
	 * - Renders an HTML/JSON error response and ends execution (no blank page).
	 *
	 * Notes:
	 * - Client-facing detail is gated by $this->devDetail; logs are always verbose.
	 * - The response format is negotiated in renderResponse(...); headers are set there.
	 * - Never throws; control flow ends inside renderResponse(...).
	 *
	 * Typical usage:
	 *   Installed once via install(); PHP calls this automatically for uncaught exceptions.
	 *
	 * Examples:
	 *
	 *   // Any uncaught \Throwable during request handling:
	 *   throw new \RuntimeException('Boom'); // Handled here; user gets a 500 with correlation id.
	 *
	 * @return void
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
				'class'   => $e::class,
				'message' => $e->getMessage(),
				'trace'   => $this->traceArray($e->getTrace(), $e->getFile(), $e->getLine()),
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
			// Clear guard even if rendering/logging hit a snag (we fail soft)
			self::$inHandler = false;
		}
	}


	/**
	 * Handle non-fatal PHP errors (warnings/notices/etc.).
	 *
	 * Behavior:
	 * - Short-circuits for fatal-class errors; they are owned by the shutdown handler.
	 * - Logs non-fatal errors when the log mask matches.
	 * - Optionally renders a response when the render mask matches (typically only in dev).
	 * - Always returns true to suppress PHP's internal error handler.
	 *
	 * Notes:
	 * - E_USER_ERROR: Some setups route E_USER_ERROR through set_error_handler(). We treat it as fatal and
	 *   defer to the shutdown handler to avoid duplicate logging and half-rendered responses. The isFatal()
	 *   guard enforces this.
	 * - Never throws; write failures are fail-soft inside writeJsonl().
	 *
	 * Typical usage:
	 *   Installed via install(); do not call directly.
	 *
	 * Examples:
	 *   // Production (render mask off):
	 *   // Warning/notice is logged to JSONL; request continues unless a fatal occurs later.
	 *
	 *   // Development (render mask includes E_WARNING, etc.):
	 *   // Warning triggers an immediate HTML/JSON error response with developer details.
	 *
	 * @return bool True to indicate the error was handled (prevents PHP's internal handler).
	 */
	public function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool {

		// Fatal territory belongs to the shutdown handler: No logging or rendering here.
		// Rationale: Avoid duplicate records and avoid appending to a half-sent response.
		if ($this->isFatal($errno)) {
			return true;
		}

		// Log when this level is enabled in the mask
		if (($errno & $this->logMask) !== 0) {
			$errorId = $this->newErrorId();
			$rec = $this->baseRecord('php_error', $errorId, (int)($this->statusDefaults['php_error'] ?? 500)) + [
				'errno'   => $errno,
				'message' => $errstr,
				'file'    => $errfile,
				'line'    => $errline,
			];
			$this->writeJsonl($this->logDir . '/http_err_phperror.jsonl', $rec);

			// Optional surface to the client (usually only in dev)
			if (($errno & $this->renderMask) !== 0) {
				$this->renderResponse((int)($this->statusDefaults['php_error'] ?? 500), $errorId, [
					'title'   => 'PHP Error',
					'message' => $errstr,
					'details' => $this->devDetail ? $rec : null,
					'type'    => 'php_error',
				]);
			}
		}

		// Tell PHP we took care of it (prevents the internal handler)
		return true;
	}


	/**
	 * Shutdown handler: Detects fatal engine errors and emits the final response.
	 *
	 * Behavior:
	 * - Reads the last PHP error via error_get_last() and inspects its type.
	 * - Acts only on fatal-class errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR).
	 * - Writes a structured JSONL log entry with request metadata and an error id.
	 * - Renders a safe HTML/JSON error page and terminates via renderResponse(...).
	 *
	 * Notes:
	 * - E_USER_ERROR is intentionally handled here: Some setups route it through set_error_handler(),
	 *   but our non-fatal handler returns early for fatal classes to avoid duplicates.
	 * - Runs at the very end of the request: No exceptions should escape; all failures are fail-soft.
	 * - Response replacement vs. tailing is delegated to renderResponse(), which decides based on headers_sent().
	 *
	 * Typical usage:
	 *   Registered by install(); PHP invokes it automatically at request shutdown.
	 *
	 * Examples:
	 *
	 *   // A fatal occurs (e.g., undefined function or parse error):
	 *   // Result: One JSONL line in http_err_shutdown.jsonl and a 500 response with error_id.
	 *
	 *   // Non-fatal warning was the last error:
	 *   // Result: No action here; warnings are handled by handlePhpError() and logging policy.
	 *
	 * @return void
	 */
	public function handleShutdown(): void {
		
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

		// Pick the status code for shutdown fatals and mint a correlation-friendly id.
		$status  = (int)($this->statusDefaults['shutdown'] ?? 500);
		$errorId = $this->newErrorId();

		// Build the log record with bounded, serializable fields.
		$rec = $this->baseRecord('shutdown', $errorId, $status) + [
			'errno'   => $errno,
			'message' => (string)($e['message'] ?? ''),
			'file'    => (string)($e['file'] ?? ''),
			'line'    => (int)($e['line'] ?? 0),
		];

		// Best-effort logging: Never throw from shutdown.
		$this->writeJsonl($this->logDir . '/http_err_shutdown.jsonl', $rec);

		// Own the last word on the wire: Render a clean error page or a minimal tail.
		$this->renderResponse($status, $errorId, [
			'title'   => 'Fatal Error',
			'message' => $this->devDetail ? (string)($e['message'] ?? '') : 'A fatal error occurred.',
			'details' => $this->devDetail ? $rec : null,
			'type'    => 'shutdown',
		]);
	}






/*
 *---------------------------------------------------------------
 * RESPONSE EMISSION (NEGOTIATION & HTML)
 *---------------------------------------------------------------
 * PURPOSE
 *   Decide JSON vs. HTML, write headers, and emit complete bodies.
 *
 * NOTES
 *   - If headers were already sent, never change status/headers; append tail only.
 *   - Clear all output buffers before writing a full replacement response.
 *   - Include X-Request-Id and no-cache headers; set X-Content-Type-Options:nosniff.
 *
 */

	/**
	 * Emits a complete, negotiated error response (HTML or JSON) with safe replacement semantics, and stops execution.
	 *
	 * Behavior:
	 * - If headers are not sent, replace the response atomically:
	 *   1) Clear all output buffers
	 *   2) Set HTTP status, no-cache headers, X-Content-Type-Options, and X-Request-Id
	 *   3) Negotiate body (HTML or JSON), set Content-Type, and emit the full body
	 * - If headers/bytes are already sent, do not change headers or status:
	 *   - Append a minimal, neutral HTML tail with the error_id when acceptable
	 * - Always terminates the request with exit(0)
	 *
	 * Notes:
	 * - Developer details are only included when $this->devDetail is true and a details payload is provided
	 * - JSON uses JSON_PARTIAL_OUTPUT_ON_ERROR for resilience; better a partial payload than a broken response
	 * - HEAD requests are not special-cased; sending a body is acceptable here (error path, not a normal handler)
	 * - Idempotent per request: Once called, control flow ends predictably from this method
	 *
	 * Typical usage:
	 *   Called internally by httpError(), handleException(), handlePhpError() (when configured), and handleShutdown()
	 *
	 * Examples:
	 *
	 *   // Fresh response (headers not sent): Full JSON body with 500
	 *   $this->renderResponse(500, $id, ['type' => 'exception', 'title' => 'Unhandled Exception', 'message' => 'Boom']);
	 *
	 *   // Late failure (headers already sent): Appends <!-- error_id=... --> tail only
	 *   $this->renderResponse(500, $id, ['type' => 'shutdown', 'title' => 'Fatal Error']);
	 *
	 * Failure:
	 * - Never throws. If emission fails, we still terminate cleanly; logging occurred earlier in the flow
	 *
	 * @param int                 $status  HTTP status code to emit (when replaceable)
	 * @param string              $errorId Correlation id shown to the client
	 * @param array<string,mixed> $payload Optional keys: 'title','message','details','type'
	 *
	 * @return void
	 */
	private function renderResponse(int $status, string $errorId, array $payload): void {
		
		// Decide negotiation and whether we can still replace the response
		$wantsJson  = $this->wantsJson();
		$canReplace = !\headers_sent(); // Decisive criterion

		if ($canReplace) {
			// Clean slate: Drop any partial output so we present one coherent response
			$this->clearAllBuffers();

			// Status and safety headers: No cache, no sniff, and include correlation id
			\http_response_code($status);
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
				echo \json_encode(
					$out,
					\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR
				);
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
					]
				);
			}
		} else {
			// Late stage: Headers/body already sent. Do not touch headers or status (we can't replace the response at this point)
			// Append a tiny HTML tail only when the client likely expects HTML. Avoid corrupting JSON streams
			if ($this->wantsHtmlTail()) {
				echo "\n<!-- error_id={$errorId} -->";
			}
		}

		// End of the line: Deterministic termination avoids "zombie" work after an error. No "walkers" here ;)
		exit(0);
	}


	/**
	 * Decide whether the client prefers JSON
	 *
	 * Behavior:
	 * - Returns true for XHR requests or when Accept contains "application/json" or "+json"
	 *
	 * Notes:
	 * - Best-effort heuristic; empty Accept means "probably HTML"
	 *
	 * Typical usage:
	 *   if ($this->wantsJson()) { echo json_encode($payload); } else { echo $this->renderHtml(...); }
	 *
	 * Failure:
	 * - None. Never throws.
	 *
	 * @return bool
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
	 * Decide whether it is safe to append an HTML tail
	 *
	 * Behavior:
	 * - Returns true when Accept is empty or includes "text/html"
	 *
	 * Notes:
	 * - Used only after headers/body started to avoid corrupting JSON streams
	 *
	 * Typical usage:
	 *   if ($this->wantsHtmlTail()) { echo "<!-- error_id=... -->"; }
	 *
	 * Failure:
	 * - None. Never throws.
	 *
	 * @return bool
	 */
	private function wantsHtmlTail(): bool {
		$ct = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
		return ($ct === '' || \stripos($ct, 'text/html') !== false); // Neutral HTML comment only when safe
	}


	/**
	 * Clear all active output buffers
	 *
	 * Behavior:
	 * - Repeatedly calls ob_end_clean() until no buffers remain
	 *
	 * Notes:
	 * - Ensures we can emit a full replacement response without leftovers
	 *
	 * Typical usage:
	 *   $this->clearAllBuffers(); // Right before writing status line + headers + body
	 *
	 * Failure:
	 * - None. Suppresses PHP notices during cleanup
	 *
	 * @return void
	 */
	private function clearAllBuffers(): void {
		while (\ob_get_level() > 0) {
			@\ob_end_clean(); // Best-effort: Silence if a layer refuses to close
		}
	}


	/**
	 * Render an HTML error page using primary/failsafe templates with a strict inline fallback.
	 *
	 * Behavior:
	 * - Builds a bounded, template-friendly $data array (safe strings; dev details are gated).
	 * - Tries the primary template; if it fails or is missing, tries the failsafe template.
	 * - If both templates are unavailable or error out, returns a minimal inline HTML page.
	 * - Sanitizes title/message for the inline path to avoid XSS; details are JSON-encoded.
	 *
	 * Notes:
	 * - No headers are sent here; caller (renderResponse) already set status and content type.
	 * - Templates are executed in isolation via includeTemplate(); failures do not recurse.
	 * - Developer details are only exposed when $this->devDetail is true.
	 * - The heredoc markup contains no comments by design (keeps the payload clean).
	 *
	 * Typical usage:
	 *   Called by renderResponse() when the negotiated content is HTML.
	 *
	 * Examples:
	 *   // Build the HTML body for a 500 response:
	 *   $html = $this->renderHtml(500, $errorId, ['title' => 'Internal Error', 'message' => 'Please try again later.']);
	 *
	 * @param int                 $status   HTTP status code (for title/status text and badges).
	 * @param string              $errorId  Correlation identifier to surface to end users.
	 * @param array<string,mixed> $payload  Optional keys: 'title','message','details','type'.
	 * @return string HTML markup for the error body (full page or minimal inline fallback).
	 */
	private function renderHtml(int $status, string $errorId, array $payload): string {
		
		// Build a compact context for templates; only safe, bounded values
		$data = [
			'language'   => $this->app->cfg->locale->language,  // Exposed for templates that localize copy
			'status'     => $status,
			'status_text'=> $this->statusText($status),
			'error_id'   => $errorId,
			'title'      => (string)($payload['title'] ?? $this->statusText($status)),
			'message'    => (string)($payload['message'] ?? 'An error occurred.'),
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

		// Last resort: Minimal inline HTML (no dependencies, no surprises)
		// Title/message are HTML-escaped; details (if any) are JSON-encoded and escaped
		$title   = \htmlspecialchars((string)$data['title'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		$message = \htmlspecialchars((string)$data['message'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		$detail = '';
		if ($this->devDetail && isset($payload['details'])) {
			$detail = '<pre style="white-space:pre-wrap;font:12px/1.4 ui-monospace,Consolas,monospace;">'
				. \htmlspecialchars(
					\json_encode($payload['details'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
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
	 * Include a PHP template safely and capture its output.
	 *
	 * Behavior:
	 * - Exposes variables to the template under a single $data-array.
	 * - Buffers output; returns the rendered markup on success.
	 * - On any Throwable, discards the buffer and returns null.
	 *
	 * Notes:
	 * - No fallback here; the caller decides the next rendering option.
	 * - Prevents partial output leaks by always cleaning the buffer.
	 *
	 * Typical usage:
	 *   $html = $this->includeTemplate($path, $vars);
	 *   if ($html !== null) { return $html; }
	 *
	 * @param string                $file Absolute path to the PHP template file.
	 * @param array<string,mixed>   $vars Data made available to the template as $data.
	 * @return ?string Rendered template, or null if inclusion failed.
	 */
	private function includeTemplate(string $file, array $vars): ?string {
		
		// Keep the template surface clean: Hand over a single $data array.
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







/*
 *---------------------------------------------------------------
 * LOGGING & ROTATION (JSONL)
 *---------------------------------------------------------------
 * PURPOSE
 *   Append robust JSONL records with size-guarded rotation and pruning.
 *
 * NOTES
 *   - Never throw from logging; fail-soft via error_log().
 *   - Rotate using sidecar lock; copy+truncate; timestamped filenames.
 *   - Prune after rotation to keep at most $maxFiles.
 *
 */

	/**
	 * Append one JSON line to a log with size-guarded rotation.
	 *
	 * Behavior:
	 * - Encodes $record once and appends it under an exclusive file lock (best effort).
	 * - Checks current size under lock; if the next line would exceed $this->maxBytes,
	 *   it unlocks/closes, rotates the file, then reopens and appends.
	 * - Never throws to callers; any failure is soft-logged via PHP's error_log.
	 *
	 * Notes:
	 * - Uses JSON_PARTIAL_OUTPUT_ON_ERROR to avoid losing the whole event on edge cases.
	 * - clearstatcache(...) is called before size checks to avoid stale FS metadata.
	 * - Rotation is delegated to rotate($file), which serializes rotations with a sidecar lock.
	 * - This method is safe to call from within error/exception/shutdown handlers.
	 *
	 * Concurrency notes:
	 * - Writers never rotate while holding the main-file lock (prevents deadlocks).
	 * - Size checks use clearstatcache(...) to avoid stale results.
	 * - JSON encoding uses JSON_PARTIAL_OUTPUT_ON_ERROR to avoid losing the event.
	 *
	 * Typical usage:
	 *   Append a single event to the live JSONL log from any handler.
	 *
	 * Examples:
	 *   $this->writeJsonl($this->logDir . '/http_err_exception.jsonl', $rec);
	 *   // Appends one JSON line; rotates first if the write would exceed maxBytes.
	 *
	 * @param string               $file   Absolute path to the live JSONL file (e.g., ".../http_err_exception.jsonl").
	 * @param array<string, mixed> $record Event payload to encode and append.
	 * @return void
	 */
	private function writeJsonl(string $file, array $record): void {
		
		try {
			// Make sure the log directory exists (best effort; do not escalate on failure)
			if (!\is_dir($this->logDir) && !@\mkdir($this->logDir, 0775, true)) {
				throw new \RuntimeException('log dir create failed');
			}

			// Encode once; partial output beats losing the whole event
			$encoded = \json_encode(
				$record,
				\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR
			);
			if ($encoded === false) {
				// Extremely rare with PARTIAL_OUTPUT_ON_ERROR, but keep the line.
				$encoded = '{"encode_error":true}';
			}
			$line = $encoded . "\n";

			$threshold = \max(1, (int)$this->maxBytes);

			// Open for append and take an exclusive lock to serialize writers
			$fh = @\fopen($file, 'ab');
			if ($fh === false) {
				throw new \RuntimeException('open failed');
			}

			try {
				@\flock($fh, \LOCK_EX);

				// Fresh size under lock to avoid races/stale metadata
				\clearstatcache(true, $file);
				$size = @\filesize($file) ?: 0;

				// If this write would cross the threshold, rotate first.
				if (($size + \strlen($line)) > $threshold) {
					// Important: Release the main lock before rotating to avoid deadlocks
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);

					// Rotation takes a sidecar lock and does copy+truncate
					$this->rotate($file);  // sidecar-locked, copy+truncate, timestamped name

					// Back to appending on the fresh file (reopen and re-lock after rotation).
					$fh = @\fopen($file, 'ab');
					if ($fh === false) {
						throw new \RuntimeException('reopen failed');
					}
					@\flock($fh, \LOCK_EX);
				}

				// Append the line and flush to disk
				@\fwrite($fh, $line);
				@\fflush($fh);
				
			} finally {
				// Always release the lock and handle; nothing leaks
				@\flock($fh, \LOCK_UN);
				@\fclose($fh);
			}
			
		} catch (\Throwable $t) {
			// Fail-soft: Do not throw from an error handler; leave a breadcrumb
			@\error_log('[CitOmni] writeJsonl failed: ' . $t->getMessage());
		}
	}
	
	
	/**
	 * Rotate a JSONL log using a sidecar lock and copy-and-truncate strategy.
	 *
	 * Behavior:
	 * - Serializes rotations per file using a sidecar lock: "<file>.lock" (LOCK_EX).
	 * - Locks the live file exclusively, then:
	 *   1) Rechecks size under lock and bails if below threshold.
	 *   2) Builds a unique UTC timestamped sibling name (colon-free; Windows-safe).
	 *   3) Copies current content to a temp file and truncates the live file to zero.
	 *   4) Atomically renames the temp to the final rotated name and sets permissions.
	 * - Releases locks predictably; writers can resume immediately on the live path.
	 *
	 * Notes:
	 * - Fail-soft by design: This method never throws; errors are logged via error_log.
	 * - Retention is delegated to prune(...), called at the end to keep at most $maxFiles.
	 * - Works across typical FPM/Apache/Nginx deployments and on Windows (no colons in names).
	 * - The live file path remains stable; only content is truncated (tailers survive).
	 * 
	 * Guarantees (local filesystems with advisory locks):
	 * - Only one rotation at a time via sidecar lock: <file>.lock (LOCK_EX).
	 * - Exclusive lock on the live file during copy+truncate.
	 * - Rotated name is UTC timestamped: <base>.<YmdTHis.uZ><-suffix>.jsonl
	 *   (colon-free; Windows-safe). On rare collision a short hex suffix is appended.
	 * - Atomic publish of the rotated file: rename(<tmp>, <final>) within the same directory.
	 *
	 * Typical usage:
	 *   Called by writeJsonl(...) when the next append would exceed $maxBytes.
	 *
	 * Examples:
	 *   // Internal call path when threshold is hit:
	 *   // writeJsonl($file, $record) -> rotate($file) -> prune($file, $this->maxFiles)
	 *
	 * @param string $file Absolute path to the live JSONL file (e.g., ".../http_err_exception.jsonl").
	 * @return void
	 */
	private function rotate(string $file): void {
		
		$lockPath = $file . '.lock';

		// Take a sidecar lock so only one worker rotates this file at a time
		$lk = @\fopen($lockPath, 'c+b');
		if ($lk !== false) {
			@\flock($lk, \LOCK_EX);
		}

		try {
			// Open and exclusively lock the live file; abort if it vanished
			$main = @\fopen($file, 'c+b');
			if ($main === false) {
				return; // Nothing to rotate
			}

			try {
				// Lock before any size checks to avoid races with writers
				@\flock($main, \LOCK_EX);

				// Fresh size under lock; another worker may have rotated just now
				\clearstatcache(true, $file);
				$stat = @\fstat($main);
				$size = (int)($stat['size'] ?? 0);
				if ($size <= $this->maxBytes) {
					return; // Already below threshold; skip.
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
					$suffix = '-' . \substr(\bin2hex(\random_bytes(2)), 0, 4);
					$rotated = $prefix . '.' . $ts . $suffix . $ext;
				}

				$tmp = $rotated . '.tmp';

				// Create a temp file for the copy; prefer exclusive create, fallback if FS lacks 'x'
				$tmpH = @\fopen($tmp, 'xb');
				if ($tmpH === false) {
					$tmpH = @\fopen($tmp, 'wb');
					if ($tmpH === false) {
						return; // Cannot rotate safely
					}
				}

				try {
					// Copy current content first, then hand writers a clean slate
					@\rewind($main);
					@\stream_copy_to_stream($main, $tmpH);
					@\fflush($tmpH);

					// Truncate live file to zero while still locked
					@\ftruncate($main, 0);
					@\fflush($main);
				} finally {
					@\fclose($tmpH);
				}

				// Publish the rotated file atomically within the same directory
				@\rename($tmp, $rotated);
				@\chmod($rotated, 0644);

				// Live file remains open (now empty); writers can continue immediately
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
	 * Prune: Delete oldest rotated JSONL siblings, keeping at most $max.
	 *
	 * Behavior:
	 * - Scans for siblings matching "<base>.*<ext>" next to $file.
	 * - Excludes the live file ($file) itself.
	 * - Sorts siblings by modification time (newest first) and deletes any beyond $max.
	 *
	 * Notes:
	 * - Best-effort and non-throwing: Uses silenced ops to avoid surfacing file-system issues from the error path.
	 * - Only touches timestamped rotations produced by rotate() that follow the "<base>.<timestamp><ext>" pattern.
	 * - Ordering uses mtime; ties are rare and acceptable for retention semantics.
	 *
	 * Typical usage:
	 *   Enforce retention after rotate() publishes a new timestamped file.
	 *
	 * Examples:
	 *   // Keep the 20 most recent exception logs:
	 *   $this->prune(CITOMNI_APP_PATH . '/var/logs/http_err_exception.jsonl', 20);
	 *
	 *   // Disable pruning during a forensic session:
	 *   $this->prune($path, 0); // No-op.
	 *
	 * @param string $file Base live JSONL path whose rotated siblings should be pruned.
	 * @param int    $max  Maximum number of rotated files to keep (0 disables pruning).
	 * @return void
	 */
	public function prune(string $file, int $max): void {
		
		// Retention disabled or nothing to do
		if ($max <= 0) {
			return;
		}

		// Split "<prefix><ext>" so we can match "<prefix>.*<ext>" (keeps ".jsonl" intact).
		[$prefix, $ext] = (static function (string $path): array {
			$dot = \strrpos($path, '.');
			return ($dot === false) ? [$path, ''] : [\substr($path, 0, $dot), \substr($path, $dot)];
		})($file);

		// Find rotated siblings in the same directory (non-sorted).
		$pattern = $prefix . '.*' . $ext;
		$list = \glob($pattern, \GLOB_NOSORT) ?: [];

		// Keep only actual rotated files: exclude the live file and non-files (paranoia).
		$list = \array_values(\array_filter($list, static fn(string $p) => $p !== $file && \is_file($p)));

		// Sort newest first by mtime. Quiet fs reads to avoid noise if files disappear mid-flight
		\usort($list, static function (string $a, string $b): int {
			$ma = @\filemtime($a) ?: 0;
			$mb = @\filemtime($b) ?: 0;
			return $mb <=> $ma; // Newest first
		});

		// Delete everything after the first $max. Best-effort: ignore unlink failures.
		$toDelete = \array_slice($list, $max);
		foreach ($toDelete as $path) {
			@\unlink($path);
		}
	}






/*
 *---------------------------------------------------------------
 * CONTEXT, IDS & RECORD SHAPING
 *---------------------------------------------------------------
 * PURPOSE
 *   Build base log records and generate correlation identifiers.
 *
 * NOTES
 *   - Detect incoming X-Request-Id when present; sanitize it.
 *   - Scrub sensitive context keys before logging.
 *
 */

	/**
	 * Build a canonical baseline for log records tied to the current request.
	 *
	 * Behavior:
	 * - Stamps an ISO 8601 timestamp and includes the correlation id.
	 * - Normalizes request metadata (method, URI without query, host, UA, IP).
	 * - Carries caller-supplied category, error id, and HTTP status.
	 *
	 * Notes:
	 * - Query string is intentionally stripped to avoid leaking secrets.
	 * - All request fields are best-effort; empty strings if not available.
	 *
	 * Typical usage:
	 *   $rec = $this->baseRecord('exception', $errorId, 500);
	 *
	 * Failure:
	 * - None. Never throws.
	 *
	 * @param string $type     Category such as 'exception'|'shutdown'|'php_error'|'http_error'.
	 * @param string $errorId  Correlation id for this error event.
	 * @param int    $status   HTTP status intended for the response.
	 * @return array<string,mixed>
	 */
	private function baseRecord(string $type, string $errorId, int $status): array {
		return [
			'ts'       => \date('c'),  // ISO 8601 with timezone offset
			'error_id' => $errorId,  // Stable id shown to client and used in logs
			'type'     => $type,
			'status'   => $status,
			'request'  => [
				'method'     => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
				'uri'        => (string)\strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'),  // Strip query: No secrets in logs
				'host'       => (string)($_SERVER['HTTP_HOST'] ?? ''),  // As sent; may be spoofed by clients
				'ua'         => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),  // Logged verbatim for triage
				'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),  // Direct client IP (proxies not resolved here)
				'request_id' => $this->requestId,  // Set in install(); used for cross-system correlation
			],
		];
	}


	/**
	 * Derive or mint a stable request correlation id.
	 *
	 * Behavior:
	 * - Prefer the incoming X-Request-Id header; otherwise generate time+random.
	 *
	 * Notes:
	 * - Header value is sanitized to a safe ASCII subset.
	 *
	 * Typical usage:
	 *   Attach to responses and logs for cross-system correlation.
	 *
	 * Examples:
	 *
	 *   $id = $this->detectRequestId(); // "20251001123456-AB12-1a2b3c4d"
	 *
	 * Failure:
	 * - None. Never throws.
	 *
	 * @return string
	 */
	private function detectRequestId(): string {
		
		$hdr = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
		
		if ($hdr !== '') {
			return $this->sanitizeId($hdr); // Trust but sanitize
		}
		
		// Very cheap, unique-enough id: time + random
		// Example: 20251001-7H2X-6f4d91c8
		return \date('YmdHis') . '-' . \strtoupper(\bin2hex(\random_bytes(2))) . '-' . \substr(\bin2hex(\random_bytes(5)), 0, 8);
	}


	/**
	 * Sanitize a candidate id to a safe ASCII subset.
	 *
	 * Behavior:
	 * - Truncates to 128 chars, strips everything not in [A-Za-z0-9._-].
	 *
	 * Notes:
	 * - Deterministic transformation; useful for headers and filenames.
	 *
	 * Typical usage:
	 *   $safe = $this->sanitizeId($possiblyHostile);
	 *
	 * Failure:
	 * - None. Never throws.
	 *
	 * @return string
	 */
	private function sanitizeId(string $s): string {
		$s = \substr($s, 0, 128);  // Hard length cap
		return (string)\preg_replace('~[^a-zA-Z0-9._\-]~', '', $s);  // ASCII whitelist
	}


	/**
	 * Generate a new internal error id.
	 *
	 * Behavior:
	 * - Returns "e_" + 16 hex chars from random bytes.
	 *
	 * Notes:
	 * - Intended for logs and client-facing correlation.
	 *
	 * Typical usage:
	 *   $errorId = $this->newErrorId(); // e_1a2b3c4d5e6f7a8b
	 *
	 * Failure:
	 * - None. random_bytes() may throw in extreme environments, but is reliable on supported OSes.
	 *
	 * @return string
	 */
	private function newErrorId(): string {
		return 'e_' . \substr(\bin2hex(\random_bytes(8)), 0, 16);  // 8 bytes -> 16 hex chars
	}
	
	
	/**
	 * Scrub sensitive keys from a context array (recursive, case-insensitive).
	 *
	 * Behavior:
	 * - Redacts values for known sensitive keys like "authorization", "cookie", "password", "token", etc.
	 * - Compares keys case-insensitively (e.g., "Authorization" and "authorization" both match).
	 * - Recurses into nested arrays; scalars and non-matching keys are copied as-is.
	 * - Does not mutate the input array; returns a sanitized copy.
	 *
	 * Notes:
	 * - Only keys are matched, not values. If you log ad-hoc payloads, ensure keys are well-formed.
	 * - Objects are not traversed; expected input is array<string, mixed>.
	 * - The redact list is intentionally conservative. Extend it if your app carries extra secrets.
	 *
	 * Typical usage:
	 *   Sanitize router/context fields before logging an HTTP error event.
	 *
	 * Examples:
	 *   $safe = $this->scrubContext([
	 *     'Authorization' => 'Bearer abc123',
	 *     'note'          => 'ok',
	 *     'nested'        => ['password' => 'shh']
	 *   ]);
	 *   // Result: ['Authorization' => '[redacted]', 'note' => 'ok', 'nested' => ['password' => '[redacted]']]
	 *
	 * @param array<string,mixed> $ctx Arbitrary context payload (headers, route params, etc.).
	 * @return array<string,mixed> Sanitized copy with sensitive values replaced by "[redacted]".
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







/*
 *---------------------------------------------------------------
 * CLASSIFICATION & STATUS TEXT
 *---------------------------------------------------------------
 * PURPOSE
 *   Map PHP error types and HTTP codes to internal decisions/labels.
 *
 * NOTES
 *   - isFatal() defines which PHP errors are handled in shutdown().
 *   - statusText() provides human-readable HTTP status text.
 *
 */

	/**
	 * Tell whether a PHP error number belongs to the fatal-class set.
	 *
	 * Behavior:
	 * - Returns true for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, and E_USER_ERROR.
	 *
	 * Notes:
	 * - Keep in sync with the shutdown path; E_USER_ERROR is treated as fatal to avoid partial output.
	 *
	 * Typical usage:
	 *   Gate logging/rendering paths in handlers to prevent duplicates and half responses.
	 *
	 * Examples:
	 *
	 *   // Fatal: Hand over to shutdown handler
	 *   if ($this->isFatal($errno)) { return true; }
	 *
	 * Failure:
	 * - None. Pure function; does not throw.
	 *
	 * @return bool
	 */
	private function isFatal(int $errno): bool {
		// Policy: Treat user-triggered fatal (E_USER_ERROR) like a core fatal to avoid partial output
		return (\in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true));
	}


	/**
	 * Map an HTTP status code to a short English reason phrase.
	 *
	 * Behavior:
	 * - Returns a stable phrase for common codes; falls back to "HTTP Error".
	 *
	 * Notes:
	 * - Intentionally minimal list; avoid coupling to full IANA registry.
	 *
	 * Typical usage:
	 *   Use for page titles and default messages when composing error responses.
	 *
	 * Examples:
	 *
	 *   // 404 -> "Not Found"; 418 -> "HTTP Error"
	 *   $text = $this->statusText(404);
	 *
	 * Failure:
	 * - None. Unknown codes return "HTTP Error".
	 *
	 * @return string
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
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			default => 'HTTP Error',
		};
	}






/*
 *---------------------------------------------------------------
 * TRACE CAPTURE & SAFE STRINGIFICATION
 *---------------------------------------------------------------
 * PURPOSE
 *   Produce bounded, safe traces and argument dumps for logs/dev detail.
 *
 * NOTES
 *   - All limits (frames/args/depth/strlen) are enforced here.
 *   - Never throw; always return printable strings/arrays.
 *
 * METHODS
 *   private function traceArray(array $trace, string $file, int $line): array
 *   private function formatCall(array $f): string
 *   private function dumpArg(mixed $v, int $depth): string
 *   private function truncate(string $s): string
 *   private function dumpArray(array $a, int $depth): string
 */

	/**
	 * Format a backtrace into a safe, bounded array for logs/templates.
	 *
	 * Behavior:
	 * - Prepends the throw site as the first frame.
	 * - Caps frames at $this->traceMaxFrames and appends an ellipsis marker if truncated.
	 * - Uses formatCall(...) for compact call + args rendering.
	 *
	 * @param array<int,array<string,mixed>> $trace Raw trace from Throwable::getTrace().
	 * @param string                         $file  Throw-site file.
	 * @param int                            $line  Throw-site line.
	 * @return array<int,array<string,mixed>> Bounded, log-friendly frames.
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
	 * Render one trace frame's callable with a compact, safe argument preview.
	 *
	 * Behavior:
	 * - Builds "Class->method(...)" or "function(...)".
	 * - Caps arguments at $this->traceMaxItems; uses dumpArg(...) per value.
	 *
	 * Notes:
	 * - Unknown fields degrade gracefully to "unknown".
	 *
	 * @param array<string,mixed> $f One frame from a backtrace.
	 * @return string Human-readable call signature.
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
				$args[] = $this->dumpArg($a, 0);
			}
		}

		return $call . '(' . \implode(', ', $args) . ')';
	}


	/**
	 * Format a single argument for trace output with depth and size limits.
	 *
	 * Behavior:
	 * - Strings are quoted and truncated via truncate().
	 * - Arrays delegate to dumpArray() with increased depth.
	 * - Objects become "object(FQCN)".
	 *
	 * @param mixed $v     Value to render.
	 * @param int   $depth Current recursion depth (0 for top-level).
	 * @return string Safe, single-line representation.
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
	 * Quote and truncate a string for trace output.
	 *
	 * Behavior:
	 * - Returns a single-quoted string.
	 * - Cuts to $this->traceMaxArgStr chars and appends $this->ellipsis if needed.
	 *
	 * @param string $s Input string.
	 * @return string Quoted, possibly truncated string.
	 */
	private function truncate(string $s): string {
		
		if (\strlen($s) <= $this->traceMaxArgStr) {
			return "'" . $s . "'";
		}
		
		return "'" . \substr($s, 0, $this->traceMaxArgStr) . $this->ellipsis . "'";
	}


	/**
	 * Render an array for traces with item and depth limits.
	 *
	 * Behavior:
	 * - Emits "key=>value" pairs; keys are json-encoded for clarity.
	 * - Caps items at $this->traceMaxItems; appends an ellipsis when truncated.
	 * - Delegates value formatting to dumpArg(...).
	 *
	 * @param array<mixed> $a     Array to render.
	 * @param int          $depth Current recursion depth.
	 * @return string Compact, single-line array representation.
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
			$items[] = \json_encode($k) . '=>' . $this->dumpArg($v, $depth);
		}

		return '[' . \implode(', ', $items) . ']';
	}


}
