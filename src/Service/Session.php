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
 * Session: Deterministic wrapper around PHP's native session handling.
 *          Lazy autostart with security-first, low-overhead defaults.
 *
 * Highlights
 * - Lazy autostart via ensureStarted() on first use (no global autostart).
 * - Hardened INI defaults (strict mode, only cookies, strong SID, etc.).
 * - Cookie flags derive from session.* → cookie.* → http.base_url / Request::isHttps() (fallback).
 * - Invariant: SameSite=None requires Secure=true (throws on misconfiguration).
 * - Optional rotation (session.rotate_interval) and fingerprint binding (session.fingerprint.*), both disabled by default.
 * - Minimal overhead: INI/cookie init runs once per request/process before session_start().
 *
 * Responsibilities:
 * - Provide a lean, predictable API for working with $_SESSION.
 *   1) Start session on demand (idempotent; no duplicate headers).
 *   2) Get/set/unset values with null-safe lookups.
 *   3) Destroy sessions explicitly (e.g., on logout).
 *   4) Simple flash helpers (set/consume/keep/reflash).
 * - Keep deterministic behavior across environments (CLI/HTTP).
 *
 * Collaborators:
 * - $this->app->cfg->session.*   (optional) Session and hardening options.
 * - $this->app->cfg->cookie.*    (optional) Cookie flag fallbacks.
 * - $this->app->cfg->http.*      (read)     For base_url → https inference.
 * - PHP session_*() functions    (native runtime).
 *
 * Configuration keys (optional; effective defaults):
 * - session.use_strict_mode         (bool)  default true
 * - session.use_only_cookies        (bool)  default true
 * - session.lazy_write              (bool)  default true
 * - session.gc_maxlifetime          (int)   default 1440
 * - session.sid_length              (int)   default 48
 * - session.sid_bits_per_character  (int)   default 6
 * - session.save_path               (string) directory is created if missing
 * - session.name                    (string) cookie name (via session_name)
 * - session.rotate_interval         (int)   seconds; 0 disables rotation
 * - session.fingerprint.bind_user_agent (bool) default false
 * - session.fingerprint.bind_ip_octets    (int 0..4) default 0 (IPv4 prefix)
 * - session.fingerprint.bind_ip_blocks    (int 0..8) default 0 (IPv6 prefix)
 * - cookie.secure                   (bool)  fallback for cookie Secure
 * - cookie.httponly                 (bool)  fallback for HttpOnly
 * - cookie.samesite                 (string "Lax"|"Strict"|"None") fallback for SameSite
 * - cookie.path                     (string) fallback for Path
 * - cookie.domain                   (string|null) fallback for Domain
 *
 * Notes on cookie lifetime:
 * - This class sets session cookie lifetime to 0 (session cookie). There is no cfg override
 *   for lifetime here; adjust globally via php.ini if needed.
 *
 * Behavior:
 * - start():
 *   1) Throws if headers were already sent.
 *   2) Initializes INI/cookie params once; then calls session_start().
 *   3) Applies optional fingerprint binding and rotation policies.
 * - get($key): returns $_SESSION[$key] or null (lazy start).
 * - set($key,$value): assigns into $_SESSION (lazy start).
 * - has($key): key existence (lazy start).
 * - remove($key): unsets key (lazy start).
 * - destroy($forgetCookie=true):
 *   1) session_unset() + session_destroy() + $_SESSION = [].
 *   2) Expires the session cookie using the active cookie params.
 * - regenerate($deleteOld=true): rotates session id; requires active session.
 * - Flash helpers:
 *   - flash($key,$value): set one-time value.
 *   - pull($key,$default=null): get and remove.
 *   - hasFlash($key): presence check.
 *   - keep($key): keep current flash for next request.
 *   - reflash(): keep all flashes for next request.
 * - Fingerprint policy:
 *   - When enabled, stores a compact UA/IP prefix signature in '_sess_fpr'.
 *   - On mismatch, destroys the session, restarts cleanly, and stores new signature
 *     (deterministic “reset-then-continue” behavior).
 *
 * Error handling:
 * - Public API never throws on missing keys or unset session state.
 * - May throw \RuntimeException when:
 *   1) headers were already sent before start(),
 *   2) session_start() fails,
 *   3) SameSite=None is configured without Secure=true,
 *   4) regenerate() is called without an active session.
 * - Other PHP warnings/notices bubble to the global handler (fail fast).
 *
 * Performance & determinism:
 * - No unnecessary allocations; methods are thin wrappers.
 * - INI/cookie initialization guarded to once-per-request/process.
 * - Rotation/fingerprint checks are cheap and disabled unless configured.
 *
 * Typical usage:
 *   // Start session at the beginning of a controller flow
 *   $this->app->session->start();
 *
 *   // Store a flash message
 *   $this->app->session->flash('notice', 'Welcome back!');
 *
 *   // Retrieve and consume a flash message
 *   $msg = $this->app->session->pull('notice');
 *
 *   // Persist login
 *   $this->app->session->set('user_id', $user->id);
 *
 *   // Rotate ID after login (defense-in-depth)
 *   $this->app->session->regenerate(true);
 *
 *   // Logout
 *   $this->app->session->destroy();
 *
 * Method overview:
 * - start(): void
 * - get(string $key): mixed|null
 * - set(string $key, mixed $value): void
 * - has(string $key): bool
 * - remove(string $key): void
 * - destroy(bool $forgetCookie = true): void
 * - regenerate(bool $deleteOld = true): void
 * - isActive(): bool
 * - flash(string $key, mixed $value): void
 * - pull(string $key, mixed $default = null): mixed
 * - hasFlash(string $key): bool
 * - keep(string $key): void
 * - reflash(): void
 */
class Session extends BaseService {
	
	/** Guard: run INI/cookie param setup once. */
	private bool $iniInitialized = false;


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
 * LIFECYCLE & STATE
 *---------------------------------------------------------------
 * PURPOSE
 *   Manage session lifecycle deterministically with lazy autostart.
 *
 * NOTES
 *   - Never starts the session implicitly except via ensureStarted().
 *   - Keep these near the top for discoverability.
 */
 

	/**
	 * Explicitly start a PHP session (idempotent).
	 *
	 * Behavior:
	 * - No-op if a session is already active.
	 * - Otherwise calls ensureStarted() which initializes INI/cookie flags and
	 *   invokes session_start().
	 * - Guarantees a usable $_SESSION superglobal on return.
	 *
	 * Notes:
	 * - Public entrypoint; lazy autostart ensures session will be started anyway
	 *   when other APIs are used.
	 * - Useful when you want to ensure session headers are sent early.
	 *
	 * Typical usage:
	 *   $this->app->session->start();
	 *
	 * Examples:
	 *   // Ensure session exists at the beginning of a controller
	 *   $this->app->session->start();
	 *
	 *   // Safe to call multiple times; only first call has effect
	 *   $this->app->session->start();
	 *   $this->app->session->start();
	 *
	 * @return void
	 * @throws \RuntimeException If headers have already been sent or session_start() fails.
	 */
	public function start(): void {
		$this->ensureStarted();
	}


	/**
	 * Check whether a PHP session is currently active.
	 *
	 * Behavior:
	 * - Returns true if session_status() === PHP_SESSION_ACTIVE.
	 * - Does not attempt to start the session.
	 * - Pure read-only check; no side effects.
	 *
	 * Notes:
	 * - Useful in edge cases where you want to branch logic depending on
	 *   whether a session has already been started (e.g., avoid double headers).
	 * - Contrast with start()/ensureStarted(): those will trigger session_start().
	 *
	 * Typical usage:
	 *   if (!$this->app->session->isActive()) {
	 *       $this->app->session->start();
	 *   }
	 *
	 * Examples:
	 *   var_dump($this->app->session->isActive()); // true or false
	 *
	 *   // Guard before regenerating ID
	 *   if ($this->app->session->isActive()) {
	 *       $this->app->session->regenerate();
	 *   }
	 *
	 * @return bool True if a session is active; false otherwise.
	 */
	public function isActive(): bool {
		return \session_status() === \PHP_SESSION_ACTIVE;
	}


	/**
	 * Get the current PHP session ID if a session is active.
	 *
	 * Behavior:
	 * - Returns the value of session_id() when session_status() === PHP_SESSION_ACTIVE.
	 * - Returns null if no session has been started.
	 * - Does not start the session or modify any state (read-only helper).
	 *
	 * Notes:
	 * - Useful for logging/diagnostics. To ensure a session exists, call start().
	 * - Rotate on privilege changes via regenerate() to mitigate fixation.
	 *
	 * Typical usage:
	 *   $sid = $this->app->session->id(); // e.g., "q3k0l2..." or null
	 *
	 * @return string|null Active session ID, or null when no session is active.
	 */
	public function id(): ?string {
		return \session_status() === \PHP_SESSION_ACTIVE ? \session_id() : null;
	}





/*
 *---------------------------------------------------------------
 * CORE READ/WRITE API
 *---------------------------------------------------------------
 * PURPOSE
 *   Minimal, predictable accessors around $_SESSION.
 *
 * NOTES
 *   - All methods ensure the session is active (lazy).
 *   - Values must be serializable by the configured session handler.
 */


	/**
	 * Retrieve a session value by key.
	 *
	 * Behavior:
	 * - Ensures session is active (lazy autostart).
	 * - Returns the stored value or null if not set.
	 * - Does not modify the session state.
	 *
	 * Notes:
	 * - Keys are case-sensitive.
	 * - Return type is unconstrained (can be scalar, array, object, etc.).
	 *
	 * Examples:
	 *   $userId = $this->app->session->get('user_id'); // int|null
	 *
	 *   // Defaulting behavior
	 *   $msg = $this->app->session->get('flash') ?? '';
	 *
	 * @param string $key Session key to look up (case-sensitive).
	 * @return mixed|null Stored value, or null if key is missing.
	 */
	public function get(string $key): mixed {
		$this->ensureStarted();
		return $_SESSION[$key] ?? null;
	}


	/**
	 * Store a value in the session.
	 *
	 * Behavior:
	 * - Ensures session is active (lazy autostart).
	 * - Assigns $value directly to $_SESSION[$key].
	 * - Overwrites any existing value under the same key.
	 *
	 * Notes:
	 * - Keys are case-sensitive.
	 * - Values must be serializable by PHP's session handler.
	 *
	 * Typical usage:
	 *   $this->app->session->set('user_id', 42);
	 *
	 * Examples:
	 *   // Persist login
	 *   $this->app->session->set('user_id', $user->id);
	 *
	 *   // Store arbitrary array
	 *   $this->app->session->set('cart', ['sku123' => 2, 'sku999' => 1]);
	 *
	 * @param string $key   Session key (non-empty, case-sensitive).
	 * @param mixed  $value Value to store (must be serializable).
	 * @return void
	 */
	public function set(string $key, mixed $value): void {
		$this->ensureStarted();
		$_SESSION[$key] = $value;
	}


	/**
	 * Determine whether a session key exists.
	 *
	 * Behavior:
	 * - Ensures session is active (lazy autostart).
	 * - Uses array_key_exists() to detect presence even if the value is null.
	 *
	 * Notes:
	 * - Distinguishes between "unset" and "set to null".
	 *
	 * Examples:
	 *   if ($this->app->session->has('user_id')) {
	 *       // user is logged in
	 *   }
	 *
	 *   var_dump($this->app->session->has('missing')); // false
	 *   $this->app->session->set('x', null);
	 *   var_dump($this->app->session->has('x')); // true
	 *
	 * @param string $key Session key (case-sensitive).
	 * @return bool True if the key exists in $_SESSION; false otherwise.
	 */
	public function has(string $key): bool {
		$this->ensureStarted();
		return \array_key_exists($key, $_SESSION);
	}


	/**
	 * Remove a key/value pair from the session.
	 *
	 * Behavior:
	 * - Ensures session is active (lazy autostart).
	 * - Calls unset() on $_SESSION[$key].
	 * - No-op if the key does not exist.
	 *
	 * Notes:
	 * - Does not throw on missing keys.
	 * - Safe to call repeatedly.
	 *
	 * Typical usage:
	 *   $this->app->session->remove('flash');
	 *
	 * Examples:
	 *   // Remove after consumption
	 *   $msg = $this->app->session->get('flash');
	 *   $this->app->session->remove('flash');
	 *
	 *   // Idempotent
	 *   $this->app->session->remove('nonexistent'); // no error
	 *
	 * @param string $key Session key to remove (case-sensitive).
	 * @return void
	 */
	public function remove(string $key): void {
		$this->ensureStarted();
		unset($_SESSION[$key]);
	}





/*
 *---------------------------------------------------------------
 * SECURITY, ROTATION & TERMINATION
 *---------------------------------------------------------------
 * PURPOSE
 *   Control session identity and teardown for security-sensitive flows.
 *
 * NOTES
 *   - Call regenerate() after login/privilege changes to mitigate fixation.
 *   - destroy() clears server state and expires the client cookie.
 */


	/**
	 * Destroy the current session and (optionally) expire the session cookie.
	 *
	 * Behavior:
	 * - Ensures an active session (lazy start) before destruction.
	 * - Calls session_unset(), session_destroy(), and resets $_SESSION to [].
	 * - When $forgetCookie is true, expires the session cookie using the
	 *   currently active cookie parameters (path, domain, secure, httponly, samesite).
	 *
	 * Notes:
	 * - This method does not throw on missing session keys or unset state.
	 * - After destroy(), a subsequent start() will create a fresh session id.
	 *
	 * Typical usage:
	 *   $this->app->session->destroy();         // logout and forget cookie
	 *   $this->app->session->destroy(false);    // logout but keep client cookie (rare)
	 *
	 * @param bool $forgetCookie When true, expire the client-side session cookie as well.
	 * @return void
	 */
	public function destroy(bool $forgetCookie = true): void {
	
		$this->ensureStarted();	 // Ensure a session exists before manipulating it
		
		@\session_unset();		 // Drop all keys from the current session array
		@\session_destroy();		 // Invalidate the session on the server (removes storage/ID)
		@\session_write_close(); // Immediately release the session lock (prevents blocking in parallel requests)
		$_SESSION = []; 		 // Clear local superglobal snapshot to avoid accidental reuse

		if ($forgetCookie) {  // Also remove the client-side cookie to prevent reuse of the old ID
			
			// Expire the session cookie on the client (respect original params)
			$params = @\session_get_cookie_params();
			$domain = $params['domain'] ?? null;
			
			
			if ($domain === '') { $domain = null; }  // Empty string is not a valid cookie domain; normalize to null
			@\setcookie(
				@\session_name(),
				'',
				[
					'expires'  => \time() - 42000,                  	// Past timestamp -> immediate expiry
					'path'     => $params['path']     ?? '/',       	// Keep original path
					'domain'   => $domain,                          	// Keep original domain (or null)
					'secure'   => (bool)($params['secure']   ?? false),	// Honor Secure flag
					'httponly' => (bool)($params['httponly'] ?? true),	// Honor HttpOnly flag
					'samesite' => $params['samesite'] ?? 'Lax',			// Preserve SameSite policy
				]
			);
		}
	}

	/**
	 * Regenerate the session ID (mitigate fixation; rotate on privilege change).
	 *
	 * Behavior:
	 * - Requires an active session; rotates the session id via session_regenerate_id().
	 * - Records a rotation timestamp in $_SESSION['_sess_rotated_at'].
	 *
	 * Notes:
	 * - Call after login or privilege escalation to prevent session fixation.
	 *
	 * Typical usage:
	 *   $this->app->session->regenerate(true); // delete old id mapping
	 *
	 * @param bool $deleteOld When true, delete old session id mapping on rotation.
	 * @return void
	 * @throws \RuntimeException If no active session exists.
	 */
	public function regenerate(bool $deleteOld = true): void {
		if (\session_status() !== \PHP_SESSION_ACTIVE) {
			throw new \RuntimeException('Cannot regenerate ID: no active session.');
		}
		\session_regenerate_id($deleteOld);
		$_SESSION['_sess_rotated_at'] = \time();
	}






/*
 *---------------------------------------------------------------
 * FLASH MESSAGES (ONE-SHOT UI STATE)
 *---------------------------------------------------------------
 * PURPOSE
 *   Ephemeral, one-request messages (PRG pattern).
 *
 * NOTES
 *   - Stored under $_SESSION['_flash'].
 *   - keep()/reflash() intentionally no-op beyond preserving presence.
 */


	/**
	 * Set a one-time (flash) value for the next request.
	 *
	 * Behavior:
	 * - Stores $value under $_SESSION['_flash'][$key].
	 * - Value remains available until consumed via pull() or cleared by reassign.
	 *
	 * Notes:
	 * - Flash values are meant for short-lived UI messages (e.g., post-redirect-get).
	 *
	 * Typical usage:
	 *   $this->app->session->flash('notice', 'Saved!');
	 *
	 * @param string $key   Flash key (non-empty).
	 * @param mixed  $value Any serializable value.
	 * @return void
	 */
	public function flash(string $key, mixed $value): void {
		$this->ensureStarted();
		$_SESSION['_flash_next'][$key] = $value;
	}


	/**
	 * Retrieve and consume a flash value by key.
	 *
	 * Behavior:
	 * - Returns the stored value and removes it from the flash bag.
	 * - If the key is missing, returns $default unchanged.
	 * - Cleans up the flash bag when it becomes empty.
	 *
	 * Typical usage:
	 *   $msg = $this->app->session->pull('notice') ?? '';
	 *
	 * @param string $key       Flash key to consume.
	 * @param mixed  $default   Value returned when the key is absent.
	 * @return mixed The stored value or $default.
	 */
	public function pull(string $key, mixed $default = null): mixed {
		$this->ensureStarted();
		if (!isset($_SESSION['_flash'][$key])) {
			return $default;
		}
		$val = $_SESSION['_flash'][$key];
		unset($_SESSION['_flash'][$key]);

		// If the flash bag is now empty, drop it entirely to minimize session size.
		if (empty($_SESSION['_flash'])) {
			unset($_SESSION['_flash']);
		}

		return $val;
	}


	/**
	 * Check if a flash key exists (without consuming it).
	 *
	 * Behavior:
	 * - Returns true when $_SESSION['_flash'][$key] is set.
	 *
	 * Typical usage:
	 *   if ($this->app->session->hasFlash('error')) { ... }
	 *
	 * @param string $key Flash key to check.
	 * @return bool True if present; false otherwise.
	 */
	public function hasFlash(string $key): bool {
		$this->ensureStarted();
		return isset($_SESSION['_flash'][$key]);
	}


	/**
	 * Keep a specific flash key for the next request (retain without consuming).
	 *
	 * Copies the current-request flash entry from $_SESSION['_flash'][$key]
	 * into $_SESSION['_flash_next'][$key] so it survives one more request.
	 *
	 * Behavior:
	 * - No effect if the key is not present in the current flash bag.
	 * - Idempotent within a request (re-applying overwrites with same value).
	 * - Does not consume/remove the current flash value.
	 * - Requires an active session (ensureStarted()).
	 *
	 * Typical usage:
	 *   $this->app->session->keep('form_values');
	 *
	 * @param string $key Flash key to preserve.
	 * @return void
	 */
	public function keep(string $key): void {
		$this->ensureStarted();
		if (isset($_SESSION['_flash'][$key])) {
			if (!isset($_SESSION['_flash_next']) || !\is_array($_SESSION['_flash_next'])) {
				$_SESSION['_flash_next'] = [];
			}
			$_SESSION['_flash_next'][$key] = $_SESSION['_flash'][$key];
		}
	}


	/**
	 * Keep all current flash values for the next request.
	 *
	 * Copies the current-request flash bag ($_SESSION['_flash']) into
	 * $_SESSION['_flash_next'] so all entries survive one more request.
	 *
	 * Behavior:
	 * - No-op when the current flash bag is empty.
	 * - Merges into any already queued next-request entries.
	 *   Existing keys in _flash_next are preserved (explicit "next" wins).
	 * - Does not consume/remove current flash values.
	 * - Requires an active session.
	 *
	 * Typical usage:
	 *   // After failed validation, keep all flashes for redisplay
	 *   $this->app->session->reflash();
	 *
	 * @return void
	 */
	public function reflash(): void {
		$this->ensureStarted();

		$curr = $_SESSION['_flash'] ?? null;
		if (!\is_array($curr) || $curr === []) {
			return; // Nothing to carry over
		}

		$next = (isset($_SESSION['_flash_next']) && \is_array($_SESSION['_flash_next']))
			? $_SESSION['_flash_next']
			: [];

		// Preserve already queued "next" values; add the rest from current
		$_SESSION['_flash_next'] = $next + $curr;
	}





/*
 *---------------------------------------------------------------
 * INTERNALS (DO NOT CALL DIRECTLY)
 *---------------------------------------------------------------
 * PURPOSE
 *   One-time INI/cookie setup, fingerprint policy, and helpers.
 *
 * NOTES
 *   - ensureStarted() performs guards + init + start + policies.
 *   - maybeRotateId()/maybeBindFingerprint() are cheap and conditional.
 *   - IPv4/IPv6 prefix helpers are best-effort normalizers.
 */
 

	/**
	 * Ensure a PHP session is active; initialize INI/cookie params exactly once.
	 *
	 * Behavior:
	 * - No-op when session_status() is already PHP_SESSION_ACTIVE.
	 * - Fails fast if headers have already been sent (cannot start session).
	 * - Calls initIniOnce() before session_start() (one-time per request/process).
	 * - On successful start, applies optional fingerprint binding and rotation.
	 *
	 * Notes:
	 * - This is called by all public APIs that rely on $_SESSION (lazy autostart).
	 *
	 * @return void
	 * @throws \RuntimeException If headers are already sent or session_start() fails.
	 */
	private function ensureStarted(): void {
		if (\session_status() === \PHP_SESSION_ACTIVE) {
			return;
		}

		// Guard *before* any INI or cookie param changes
		if (\headers_sent($file, $line)) {
			throw new \RuntimeException("Cannot start session: headers already sent at {$file}:{$line}");
		}

		$this->initIniOnce();

		if (!@\session_start()) {
			throw new \RuntimeException('session_start() failed.');
		}

		$this->manageFlashLifecycle();
		$this->maybeBindFingerprint();
		$this->maybeRotateId();
	}


	/**
	 * Initialize session INI and cookie parameters exactly once.
	 *
	 * Behavior:
	 * - Sets strict/lazy/write/entropy INI flags and optional save_path (creates dir).
	 * - Applies session name if configured.
	 * - Derives cookie flags (secure/httponly/samesite/path/domain) from
	 *   session.* -> cookie.* -> http.base_url/request->isHttps().
	 * - Guards: SameSite=None requires Secure=true.
	 * - No-op if headers are already sent.
	 *
	 * Notes:
	 * - Best-effort: ini_set() failures are ignored by design.
	 *
	 * @return void
	 * @throws \RuntimeException If SameSite=None is configured without Secure=true.
	 */
	private function initIniOnce(): void {
		if ($this->iniInitialized) {
			return;
		}
		// If headers are already sent, skip INI/cookie param changes entirely.
		if (\headers_sent()) {
			$this->iniInitialized = true;
			return;
		}
		$this->iniInitialized = true;

		$cfgSess   = isset($this->app->cfg->session) ? $this->app->cfg->session->toArray() : [];
		$cfgCookie = isset($this->app->cfg->cookie)  ? $this->app->cfg->cookie->toArray()  : [];
		$cfgHttp   = isset($this->app->cfg->http)    ? $this->app->cfg->http->toArray()    : [];

		// Harden core INI (best-effort; ignore failures).
		$this->ini('session.use_strict_mode',        $cfgSess['use_strict_mode']        ?? true);
		$this->ini('session.use_only_cookies',       $cfgSess['use_only_cookies']       ?? true);
		$this->ini('session.lazy_write',             $cfgSess['lazy_write']             ?? true);
		$this->ini('session.gc_maxlifetime',         $cfgSess['gc_maxlifetime']         ?? 1440);
		$this->ini('session.sid_length',             $cfgSess['sid_length']             ?? 48);
		$this->ini('session.sid_bits_per_character', $cfgSess['sid_bits_per_character'] ?? 6);

		// Optional save_path (create directory if needed).
		if (!empty($cfgSess['save_path']) && \is_string($cfgSess['save_path'])) {
			$path = (string)$cfgSess['save_path'];
			if (!\is_dir($path)) {
				@mkdir($path, 0775, true);
			}
			$this->ini('session.save_path', $path);
		}

		// Session name.
		if (!empty($cfgSess['name']) && \is_string($cfgSess['name'])) {
			@\session_name($cfgSess['name']);
		}

		// Resolve cookie flags (secure/httponly/samesite/path/domain).
		$secure = $cfgSess['cookie_secure'] ?? null;
		if (!\is_bool($secure)) {
			// Fallback: cookie.secure -> base_url https -> Request::isHttps()
			if (\array_key_exists('secure', $cfgCookie) && \is_bool($cfgCookie['secure'])) {
				$secure = $cfgCookie['secure'];
			} else {
				$baseUrlHttps = isset($cfgHttp['base_url']) && \is_string($cfgHttp['base_url'])
					? (\preg_match('#^https://#i', (string)$cfgHttp['base_url']) === 1)
					: false;
				$fromRequest  = isset($this->app->request) ? $this->app->request->isHttps() : $this->fallbackIsHttps();
				$secure = $baseUrlHttps || $fromRequest;
			}
		}

		$samesite = $cfgSess['cookie_samesite'] ?? ($cfgCookie['samesite'] ?? 'Lax');
		$samesite = \is_string($samesite) ? \ucfirst(\strtolower($samesite)) : 'Lax';
		if (!\in_array($samesite, ['Lax','Strict','None'], true)) {
			$samesite = 'Lax';
		}
		if ($samesite === 'None' && $secure !== true) {
			throw new \RuntimeException('Session cookie SameSite=None requires Secure=true');
		}

		$httponly = (bool)($cfgSess['cookie_httponly'] ?? ($cfgCookie['httponly'] ?? true));
		$path     = (string)($cfgSess['cookie_path']     ?? ($cfgCookie['path'] ?? '/'));
		$domain   = $cfgSess['cookie_domain']            ?? ($cfgCookie['domain'] ?? null);
		$domain   = ($domain === '' ? null : $domain);

		\session_set_cookie_params([
			'lifetime' => 0,
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => (bool)$secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		]);
	}


	/**
	 * Rotate the session ID when rotate_interval is configured and elapsed.
	 *
	 * Behavior:
	 * - Reads session.rotate_interval (seconds). 0 disables rotation.
	 * - Records/compares $_SESSION['_sess_rotated_at'] and calls regenerate(true)
	 *   when due; updates the timestamp.
	 *
	 * @return void
	 */
	private function maybeRotateId(): void {
		$cfgSess = isset($this->app->cfg->session) ? $this->app->cfg->session->toArray() : [];
		$interval = (int)($cfgSess['rotate_interval'] ?? 0);
		if ($interval <= 0) {
			return;
		}
		$now = \time();
		$last = (int)($_SESSION['_sess_rotated_at'] ?? 0);
		if ($last === 0) {
			$_SESSION['_sess_rotated_at'] = $now;
			return;
		}
		if (($now - $last) >= $interval) {
			$this->regenerate(true);
		}
	}


	/**
	 * Promote next-request flash data to the current request.
	 *
	 * Moves queued flash entries from $_SESSION['_flash_next'] into
	 * $_SESSION['_flash'] and clears $_SESSION['_flash_next'].
	 * Intended to be called once per request after session_start(),
	 * before any reads of flash data (e.g., pull()/hasFlash()).
	 *
	 * Behavior:
	 * - Idempotent within a request (safe to call multiple times).
	 * - Does not start the session; caller must ensure an active session.
	 * - If no queued data exists, leaves $_SESSION['_flash'] untouched.
	 * - Avoids creating keys unless data actually exists.
	 *
	 * Notes:
	 * - The flash protocol is:
	 *   - flash($k,$v) writes to $_SESSION['_flash_next'] (visible next request).
	 *   - pull()/hasFlash() read from $_SESSION['_flash'] (visible this request).
	 *   - keep()/reflash() copy current -> next when needed.
	 * - Call this early in the request lifecycle (e.g., from ensureStarted()).
	 *
	 * Typical usage:
	 *   // Internal:
	 *   $this->manageFlashLifecycle(); // right after session_start()
	 *
	 * @return void
	 */
	private function manageFlashLifecycle(): void {
		if (isset($_SESSION['_flash_next'])) {
			$_SESSION['_flash'] = $_SESSION['_flash_next'];
			unset($_SESSION['_flash_next']);
		}
	}


	/**
	 * Bind session to a compact fingerprint (UA hash and/or IP prefix).
	 *
	 * Behavior:
	 * - Builds a signature based on configured fingerprint options.
	 * - On first run stores it in $_SESSION['_sess_fpr'].
	 * - On mismatch: destroys the session, starts a fresh one, then stores new signature.
	 *
	 * Notes:
	 * - Cheap checks; fully disabled when all fingerprint options are off.
	 *
	 * @return void
	 */
	private function maybeBindFingerprint(): void {
		$cfg = isset($this->app->cfg->session) ? $this->app->cfg->session->toArray() : [];
		$fp  = (array)($cfg['fingerprint'] ?? []);

		$bindUA     = (bool)($fp['bind_user_agent'] ?? false);
		$bindV4Oct  = (int)  ($fp['bind_ip_octets'] ?? 0);  // 0..4
		$bindV6Blk  = (int)  ($fp['bind_ip_blocks'] ?? 0);  // 0..8

		if (!$bindUA && $bindV4Oct === 0 && $bindV6Blk === 0) {
			return; // all disabled
		}

		$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

		$parts = [];
		if ($bindUA) {
			$parts[] = 'ua:' . \sha1($ua);
		}
		if ($ip !== '') {
			if (\strpos($ip, ':') !== false) {
				// IPv6
				if ($bindV6Blk > 0) {
					$norm = $this->ipv6Prefix($ip, $bindV6Blk);
					if ($norm !== null) {
						$parts[] = 'v6:' . $norm;
					}
				}
			} else {
				// IPv4
				if ($bindV4Oct > 0) {
					$norm = $this->ipv4Prefix($ip, $bindV4Oct);
					if ($norm !== null) {
						$parts[] = 'v4:' . $norm;
					}
				}
			}
		}

		if ($parts === []) {
			return;
		}

		$fpr = \implode('|', $parts);
		$stored = (string)($_SESSION['_sess_fpr'] ?? '');
		if ($stored === '') {
			$_SESSION['_sess_fpr'] = $fpr;
			return;
		}
		if (!\hash_equals($stored, $fpr)) {
			// Fingerprint mismatch ⇒ reset session (defensive)
			$this->destroy();
			// Start a clean session so app code can continue deterministically:
			$this->start();
			$_SESSION['_sess_fpr'] = $fpr;
		}
	}


	/**
	 * Return the first N IPv4 octets from a dotted-quad address.
	 *
	 * Behavior:
	 * - Validates the shape; returns "a.b", "a.b.c", or "a.b.c.d" depending on $octets.
	 * - Returns null on invalid input or out-of-range $octets (allowed 1..4).
	 *
	 * @param string $ip     IPv4 address (e.g., "203.0.113.45").
	 * @param int    $octets Number of leading octets to keep (1..4).
	 * @return string|null   Prefix string or null on invalid input.
	 */
	private function ipv4Prefix(string $ip, int $octets): ?string {
		if ($octets < 1 || $octets > 4) return null;
		$parts = \explode('.', $ip);
		if (\count($parts) !== 4) return null;
		return \implode('.', \array_slice($parts, 0, $octets));
	}


	/**
	 * Return the first N IPv6 16-bit blocks from an IPv6 address.
	 *
	 * Behavior:
	 * - Expands the address to 8 blocks (via expandIpv6()) and returns the leading
	 *   $blocks joined by ":".
	 * - Returns null on invalid input or out-of-range $blocks (allowed 1..8).
	 *
	 * @param string $ip     IPv6 address (compressed or full).
	 * @param int    $blocks Number of leading 16-bit blocks to keep (1..8).
	 * @return string|null   Prefix string or null on invalid input.
	 */
	private function ipv6Prefix(string $ip, int $blocks): ?string {
		if ($blocks < 1 || $blocks > 8) return null;
		// Normalize (best-effort): compress consecutive zeros is fine; we only need left blocks
		$expanded = $this->expandIpv6($ip);
		if ($expanded === null) return null;
		$parts = \explode(':', $expanded);
		return \implode(':', \array_slice($parts, 0, $blocks));
	}


	/**
	 * Best-effort expansion of an IPv6 address to 8 groups of 4 hex digits.
	 *
	 * Behavior:
	 * - Uses inet_pton() to validate and normalize, then bin2hex() into 8 groups.
	 * - Returns null if the input is not a valid IPv6 address.
	 *
	 * @param string $ip IPv6 address.
	 * @return string|null Expanded form like "2001:0db8:0000:0000:0000:0000:0000:0001", or null.
	 */
	private function expandIpv6(string $ip): ?string {
		$bin = @\inet_pton($ip);
		if ($bin === false || \strlen($bin) !== 16) return null;
		$hex = \bin2hex($bin);
		$parts = \str_split($hex, 4);
		return \implode(':', $parts);
	}


	/**
	 * Minimal HTTPS detector when Request service is unavailable.
	 *
	 * Behavior:
	 * - Returns true if $_SERVER['HTTPS'] is on or SERVER_PORT is 443.
	 * - Does not trust proxy headers; strictly server vars.
	 *
	 * @return bool True when the current request is HTTPS; false otherwise.
	 */
	private function fallbackIsHttps(): bool {
		$https = $_SERVER['HTTPS'] ?? '';
		if ($https !== '' && \strtolower((string)$https) !== 'off') return true;
		if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
		return false;
	}


	/**
	 * Best-effort ini_set() wrapper.
	 *
	 * Behavior:
	 * - Casts booleans to '1'/'0', others to string; suppresses warnings.
	 * - Used to keep init deterministic without noisy runtime notices.
	 *
	 * @param string $key   INI directive name (e.g., "session.use_strict_mode").
	 * @param mixed  $value Scalar/boolean value to assign.
	 * @return void
	 */
	private function ini(string $key, mixed $value): void {
		$v = \is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		@ini_set($key, $v);
	}

}
