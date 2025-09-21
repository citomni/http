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

namespace CitOmni\Http\Exception;

/**
 * ErrorHandler: Centralized static error and exception handling for CitOmni (HTTP).
 *
 * Responsibilities:
 * - Install global PHP handlers (error, exception, shutdown) in an idempotent way.
 * - Produce structured JSON-lines to a writable log file with soft pre-open rotation.
 * - Control error visibility (dev vs. prod) without swallowing exceptions.
 * - Map PHP severities to HTTP response codes in web SAPI.
 *
 * Configuration keys (cfg->error_handler):
 * - log_file (string)  - Absolute path to JSON-lines log file. Default fallback: <package>/src/logs/system_error_log.json.
 * - recipient (string) - Email recipient for critical notifications, '' disables notifications.
 * - sender (string|null) - Email sender (From:). Kernel may derive from cfg->mail->from->email when null.
 * - max_log_size (int) - Rotation threshold in bytes (soft rotate on install if exceeded).
 * - template (string, optional) - Absolute path to non-dev failsafe error template.
 * - display_errors (bool) - Show a single verbose block for the first error in the request.
 *
 * Error handling:
 * - Fail fast. This class does not mask or rethrow. It only logs, sets HTTP code, and renders output.
 * - Reentrancy is guarded to avoid recursive logging if logging itself triggers errors.
 *
 * Typical usage:
 *
 *   // In Kernel boot, after cfg is merged (last-wins) and App is ready:
 *   $ehCfg = (array)($app->cfg->error_handler ?? []);
 *   \CitOmni\Http\Exception\ErrorHandler::install($ehCfg);
 *
 * Examples:
 *
 *   // Emit a user warning somewhere in code; ErrorHandler will log and set HTTP 400 if applicable.
 *   \trigger_error('Deprecated call in controller', E_USER_WARNING);
 *
 *   // Throwing an uncaught exception will be normalized and logged by handleException().
 *   throw new \RuntimeException('Unexpected state');
 *
 * Failure:
 *
 *   // If the log directory cannot be created during install(), a \RuntimeException is thrown.
 *
 * Notes:
 * - This class is marked final to prevent partial extension of static global behavior.
 *   Static, process-wide hooks must remain deterministic.
 * - Do not catch inside handlers unless necessary; fail fast and log.
 */
final class ErrorHandler {

	/** Path to JSON-lines error log (writable). Set during install(). */
	private static string $logFile = '';

	/** Email recipient for critical error notifications (empty disables notifications). */
	private static string $recipient = '';

	/** Sender address used in notifications (From:). */
	private static string $sender = '';

	/** Rotate log when size (bytes) is reached. */
	private static int $maxLogSize = 10_485_760; // 10 MB

	/** Path to non-dev output template. Set during install(). */
	private static string $template = '';

	/** Show one on-screen error block (typically true in dev). */
	private static bool $displayErrors = true;

	/** In-memory log entries for this request. @var array<int, array<string,mixed>> */
	private static array $errors = [];

	/** Idempotency flag: install() has run. */
	private static bool $installed = false;

	/** Most recent error_id for this request. */
	private static ?string $lastErrorId = null;

	/** Reentrancy guard to avoid recursive logging inside handlers. */
	private static bool $inHandler = false;


	/**
	 * Install global handlers (idempotent): error, exception, and shutdown.
	 *
	 * Behavior:
	 * - Validates presence of required cfg keys (types are assumed validated upstream).
	 * - Ensures the log directory exists; attempts soft pre-open rotation if the file exceeds max size.
	 * - Registers error, exception, and shutdown handlers.
	 * - Applies display policy to ini settings and error_reporting mask.
	 *
	 * Notes:
	 * - This method is safe to call multiple times; subsequent calls are no-ops.
	 * - The template path falls back to the package default if missing or unreadable.
	 *
	 * Typical usage:
	 *   ErrorHandler::install((array)$app->cfg->error_handler);
	 *
	 * @param array{
	 *   log_file:string,
	 *   recipient:string,
	 *   sender:string|null,
	 *   max_log_size:int,
	 *   template?:string,
	 *   display_errors:bool
	 * } $opts Installation options (all required keys must be present)
	 * @return void
	 * @throws \InvalidArgumentException If a required option key is missing.
	 * @throws \RuntimeException If the log directory cannot be created.
	 */
	public static function install(array $opts = []): void {
		if (self::$installed) {
			return; // Already installed; no-op
		}

		// Validate required keys (presence only; kernel ensures types)
		foreach (['log_file', 'recipient', 'sender', 'max_log_size', 'display_errors'] as $k) {
			// Using array_key_exists to allow falsy values but still require the key
			if (!\array_key_exists($k, $opts)) {
				throw new \InvalidArgumentException("ErrorHandler::install() missing required option: {$k}");
			}
		}

		// Assign configuration
		self::$logFile       = (string)$opts['log_file'];
		self::$recipient     = \trim((string)$opts['recipient']);
		self::$sender        = \trim((string)$opts['sender']);
		self::$maxLogSize    = (int)$opts['max_log_size'];
		self::$template      = (string)($opts['template'] ?? '');
		self::$displayErrors = (bool)$opts['display_errors'];

		// Fallbacks: log path and template path
		if (self::$logFile === '') {
			self::$logFile = self::packageSrcRoot() . '/logs/system_error_log.json';
		}
		if (self::$template === '' || !\is_file(self::$template)) {
			// From src/Exception to templates/errors is two levels up
			self::$template = \realpath(__DIR__ . '/../../templates/errors/failsafe_error.php') ?: '';
		}

		// Ensure log directory exists (mkdir is idempotent with recursive=true)
		$logDir = \dirname(self::$logFile);
		if (!\is_dir($logDir) && !\mkdir($logDir, 0755, true) && !\is_dir($logDir)) {
			throw new \RuntimeException("Cannot create log directory: {$logDir}");
		}

		// Soft rotation: rename current file before opening when exceeding size
		\clearstatcache(true, self::$logFile);
		$size = \is_file(self::$logFile) ? (int)\filesize(self::$logFile) : 0;
		if ($size >= \max(1, self::$maxLogSize)) {
			$rot = self::$logFile . '_' . \date('Ymd-His-') . \bin2hex(\random_bytes(3));
			// Non-fatal: If rename fails, subsequent writes append to existing file
			@\rename(self::$logFile, $rot);
		}

		// Register global hooks (explicit mask for clarity)
		\set_error_handler([self::class, 'handleError'], E_ALL);
		\set_exception_handler([self::class, 'handleException']);
		\register_shutdown_function([self::class, 'handleShutdown']);

		// Apply visibility + reporting policy
		\ini_set('display_errors', self::$displayErrors ? '1' : '0');
		\ini_set('display_startup_errors', self::$displayErrors ? '1' : '0');

		\error_reporting(
			self::$displayErrors
				? E_ALL
				: (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED)
		);

		self::$installed = true;
	}


	/**
	 * Toggle runtime error display (does not change error_reporting mask).
	 *
	 * Behavior:
	 * - Updates ini toggles to reflect the requested display policy.
	 * - Does not alter the existing error_reporting bitmask.
	 *
	 * Typical usage:
	 *   ErrorHandler::setDisplayErrors(false);
	 *
	 * @param bool $value True to show errors in output, false to hide.
	 * @return void
	 */
	public static function setDisplayErrors(bool $value): void {
		self::$displayErrors = $value;
		\ini_set('display_errors', $value ? '1' : '0');
		\ini_set('display_startup_errors', $value ? '1' : '0');
	}


	/**
	 * PHP error handler.
	 *
	 * Behavior:
	 * - Normalizes PHP error into a log entry and appends to the JSON-lines log.
	 * - Returns true to prevent the internal PHP handler from running.
	 *
	 * Notes:
	 * - Reentrancy is guarded to avoid logging loops.
	 *
	 * @param int $errno PHP error level (E_*).
	 * @param string $errstr Error message.
	 * @param string $errfile File path where the error occurred.
	 * @param int $errline Line number of the error.
	 * @return bool True to mark the error as handled.
	 */
	public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
		self::logError($errno, $errstr, $errfile, $errline, null);
		return true;
	}


	/**
	 * PHP exception handler.
	 *
	 * Behavior:
	 * - Converts an uncaught Throwable into a log entry using E_USER_ERROR severity.
	 *
	 * @param \Throwable $exception The uncaught exception.
	 * @return void
	 */
	public static function handleException(\Throwable $exception): void {
		self::logError(
			E_USER_ERROR,
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine(),
			$exception->getTraceAsString()
		);
	}


	/**
	 * Shutdown handler for fatal errors.
	 *
	 * Behavior:
	 * - On script termination, captures the last error (if any) and logs it.
	 *
	 * @return void
	 */
	public static function handleShutdown(): void {
		$error = \error_get_last();
		if ($error) {
			self::logError(
				$error['type'] ?? E_ERROR,
				(string)($error['message'] ?? 'Shutdown Error'),
				(string)($error['file'] ?? 'unknown'),
				(int)($error['line'] ?? 0),
				null
			);
		}
	}


	/**
	 * Core logger for both PHP errors and exceptions.
	 *
	 * Behavior:
	 * - Generates a stable error_id and structured entry.
	 * - Appends entry to the JSON-lines log with advisory locking.
	 * - Maps severity to an HTTP status code (web SAPI) and sets it if headers are not sent.
	 * - Sends best-effort notification for error-grade severities when sender and recipient are set.
	 * - Renders a single dev-visible block per request if display is enabled.
	 *
	 * Notes:
	 * - Reentrancy is guarded to avoid recursive logging if logging fails.
	 *
	 * @param int $type PHP error level (E_*).
	 * @param string $message Human-readable message.
	 * @param string $file Source file path.
	 * @param int $line Source line number.
	 * @param string|null $trace Optional stack trace string.
	 * @return void
	 */
	public static function logError(int $type, string $message, string $file, int $line, ?string $trace = null): void {
		if (self::$inHandler) {
			// Already handling an error; avoid recursive loops
			return;
		}
		self::$inHandler = true;

		// Human-readable severity map
		$levels = [
			E_ERROR             => 'Error',
			E_WARNING           => 'Warning',
			E_PARSE             => 'Parsing Error',
			E_NOTICE            => 'Notice',
			E_CORE_ERROR        => 'Core Error',
			E_CORE_WARNING      => 'Core Warning',
			E_COMPILE_ERROR     => 'Compile Error',
			E_COMPILE_WARNING   => 'Compile Warning',
			E_USER_ERROR        => 'User Error',
			E_USER_WARNING      => 'User Warning',
			E_USER_NOTICE       => 'User Notice',
			E_STRICT            => 'Strict',
			E_RECOVERABLE_ERROR => 'Recoverable Error',
			E_DEPRECATED        => 'Deprecated',
			E_USER_DEPRECATED   => 'User Deprecated',
		];

		// Generate a compact, sortable error id
		$errorId = \date('Ymd-His-') . \bin2hex(\random_bytes(4));
		self::$lastErrorId = $errorId;

		// Build the entry payload (stable keys for processors)
		$entry = [
			'timestamp'  => \date('Y-m-d H:i:s'),
			'error_id'   => $errorId,
			'type'       => $type,
			'type_desc'  => $levels[$type] ?? 'Unknown',
			'message'    => $message,
			'file'       => $file,
			'line'       => $line,
			'trace'      => $trace,
			'ip'         => $_SERVER['REMOTE_ADDR']     ?? 'Unknown',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
		];

		// Keep an in-memory copy for the request (debug screens/tests)
		self::$errors[] = $entry;

		// Append to log with advisory locking; tolerate write failures
		$fh = @\fopen(self::$logFile, 'ab');
		if ($fh) {
			@\flock($fh, LOCK_EX);
			// fwrite($fh, \json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
			
			$lineStr = \json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($lineStr !== false) {
				\fwrite($fh, $lineStr . \PHP_EOL);
			}
			
			\fflush($fh);
			@\flock($fh, LOCK_UN);
			\fclose($fh);
		}

		// Map severity to HTTP status for web SAPI
		self::setHttpResponseCode($type);

		// Notify for error-grade severities if both sender and recipient are set
		if (self::$recipient !== '' && self::$sender !== '' && \in_array($type, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
			self::sendErrorNotification($entry);
		}

		// Render once per request (DEV => verbose; non-DEV => template/fallback)
		static $printed = false;
		if (!$printed) {
			self::renderForEnvironment($entry);
			$printed = true;
		}

		self::$inHandler = false;
	}


	/**
	 * Get all error entries captured during the current request.
	 *
	 * Notes:
	 * - Order is the order of capture.
	 *
	 * @return array<int, array<string,mixed>> Array of log entries.
	 */
	public static function getErrors(): array {
		return self::$errors;
	}


	/**
	 * Get the last generated error id (if any).
	 *
	 * @return string|null The most recent error_id or null if none exists.
	 */
	public static function getLastErrorId(): ?string {
		return self::$lastErrorId;
	}


	/**
	 * Best-effort email-style notification via error_log(type=1).
	 *
	 * Behavior:
	 * - Builds a simple subject and pretty-printed JSON body.
	 * - Uses the configured sender and recipient.
	 * - Suppresses failures (some environments may disable mail via error_log).
	 *
	 * @param array<string,mixed> $entry The log entry payload.
	 * @return void
	 */
	private static function sendErrorNotification(array $entry): void {
		$subject = 'Error: ' . ($entry['type_desc'] ?? 'Unknown');
		$headers = 'From: ' . self::$sender;
		
		// error_log(type=1) may be disabled; suppress failure silently (best-effort)
		@\error_log(
			$subject . "\n\n" . \json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
			1,
			self::$recipient,
			$headers
		);
	}


	/**
	 * Map PHP error levels to HTTP status codes for web SAPI and set it
	 * when headers are not yet sent.
	 *
	 * Mapping:
	 * - Warnings            -> 400
	 * - Notices/Deprecated  -> 200
	 * - All others          -> 500
	 *
	 * Notes:
	 * - No-op if headers are already sent.
	 * - No-op when running under CLI.
	 *
	 * @param int $errorType PHP error level (E_*).
	 * @return void
	 */
	private static function setHttpResponseCode(int $errorType): void {
		if (\PHP_SAPI === 'cli') {
			return; // Do not emit HTTP codes for CLI
		}
		$http = match ($errorType) {
			E_WARNING, E_USER_WARNING                 => 400,
			E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 200,
			default                                   => 500,
		};

		if (!\headers_sent()) {
			\http_response_code($http);
		}
	}


	/**
	 * Render output appropriate to the environment.
	 *
	 * Behavior:
	 * - DEV: Print a verbose HTML list of entry fields.
	 * - Non-DEV: Include configured failsafe template if available, otherwise
	 *            print a minimal generic message with the error id.
	 *
	 * @param array<string,mixed> $entry The log entry payload.
	 * @return void
	 */
	private static function renderForEnvironment(array $entry): void {
		if (\defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev') {
			echo '<h1>Error Details</h1><ul>';
			foreach ($entry as $k => $v) {
				echo '<li><strong>' . \htmlspecialchars((string)$k) . ':</strong> '
					. \nl2br(\htmlspecialchars(\is_scalar($v) ? (string)$v : \print_r($v, true)))
					. '</li>';
			}
			echo '</ul>';
			return;
		}
		
		if (\is_file(self::$template)) {
			// Expose a simple variable the template may use
			$error_id = $entry['error_id'] ?? null;
			include self::$template;
		} else {
			// Minimal fallback when no template is available
			echo '<h1>Oops, something went wrong.</h1>';
			if (!empty($entry['error_id'])) {
				echo '<p>Error ID: ' . \htmlspecialchars((string)$entry['error_id']) . '</p>';
			}
		}
	}


	/**
	 * Absolute path to the package's src root.
	 *
	 * @return string Filesystem path (.../src).
	 */
	private static function packageSrcRoot(): string {
		return \dirname(__DIR__);
	}
}
