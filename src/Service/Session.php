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
 *          Lazy start with security-first, low-overhead defaults.
 *
 * Highlights
 * - Reads never create a session: get(), has(), remove() and destroy() resume an
 *   existing session and leave a request without one untouched. set(), start() and
 *   regenerate() create it (no global autostart).
 * - Hardened INI defaults (strict mode, only cookies, lazy write).
 * - The session cookie's attributes come from Cookie::attributes(), with the
 *   session.cookie_* settings as overrides, so it follows the same rule as every
 *   other cookie of the application.
 * - Storage settings (save path, retention, garbage collection) are applied explicitly,
 *   and a setting PHP refuses throws instead of leaving the server's value in place.
 * - Minimal overhead: INI/cookie init runs once per request/process before session_start().
 *
 * Responsibilities:
 * - Provide a lean, predictable API for working with $_SESSION.
 *   1) Start session on demand (idempotent; no duplicate headers).
 *   2) Get/set/unset values with null-safe lookups; reads do not start a session.
 *   3) Destroy sessions explicitly (e.g., on logout).
 * - Own session storage: Save path, retention and garbage collection.
 * - Keep deterministic behavior across supported runtime contexts.
 *
 * Collaborators:
 * - $this->app->cfg->session.*   (read)     Storage, hardening and session-cookie overrides.
 * - $this->app->cookie           (required) Cookie::attributes() for the session cookie.
 * - PHP session_*() functions    (native runtime).
 *
 * Storage retention:
 * - session.gc_maxlifetime is how long PHP keeps idle session data. It is not a login
 *   lifetime; a package that accepts a login for longer must raise it, as
 *   citomni/authenticate does.
 * - PHP collects garbage with probability gc_probability/gc_divisor per session start.
 *   The baseline sets 1/1000 explicitly: Debian and Ubuntu ship gc_probability=0 and clean
 *   only the save paths of their php.ini files from cron, which never sees the save path
 *   set here. Set gc_probability to 0 only when another job cleans session.save_path.
 *
 * Notes on cookie lifetime:
 * - This class always uses lifetime 0 (session cookie), also when php.ini says otherwise.
 *   Persistent login belongs to a remember-me mechanism, not to the session cookie.
 *
 * Rotation and concurrency:
 * - regenerate(true) deletes the old session at once. A concurrent request that still
 *   presents the old id then gets a new, empty session, and its Set-Cookie may replace
 *   the new id in the browser. There is no grace window: Rotate at privilege changes,
 *   not on a timer.
 *
 * Reads:
 * - A read resumes the session when one is active, when one was established earlier in
 *   this request and closed since (session_write_close()), or when the request carries
 *   the session cookie. Otherwise it returns the empty result without touching storage
 *   or sending headers, so a guest page that reads Flash, Csrf or Auth state gets no
 *   session cookie, no session file and no session cache headers.
 * - Under strict mode, a cookie whose id storage does not know (expired, collected,
 *   forged) still starts a new, empty session once; its cookie replaces the stale one.
 * - Only the session cookie counts. With session.use_only_cookies off, reads do not
 *   resume an id that arrives in the URL.
 *
 * Behavior:
 * - start():
 *   1) Throws if headers were already sent.
 *   2) Initializes INI/cookie params once; then calls session_start().
 * - get($key): Returns $_SESSION[$key], or null when the key or the session is missing.
 * - set($key,$value): Assigns into $_SESSION (starts the session when needed).
 * - has($key): Key existence; false without a session.
 * - remove($key): Unsets key; no-op without a session.
 * - destroy($forgetCookie=true):
 *   1) No-op without a session.
 *   2) Clears $_SESSION and destroys the session in storage; throws if storage refuses.
 *   3) Expires the session cookie using the active cookie params.
 * - regenerate($deleteOld=true): starts the session if needed, then rotates the id; throws on failure.
 *
 * Error handling:
 * - Public API never throws on missing keys or a missing session.
 * - May throw \RuntimeException when:
 *   1) headers were already sent before start(),
 *   2) session_start() fails,
 *   3) PHP refuses a setting, the session name and the cookie parameters included,
 *   4) session.save_path cannot be created,
 *   5) a removed option (session.rotate_interval, session.fingerprint) is still enabled,
 *   6) session_regenerate_id() or session_destroy() fails.
 * - Invalid cookie attributes and SameSite=None without Secure throw from Cookie::attributes().
 * - Other PHP warnings/notices bubble to the global handler (fail fast).
 *
 * Performance & determinism:
 * - No unnecessary allocations; methods are thin wrappers.
 * - INI/cookie initialization guarded to once-per-request/process.
 *
 * Typical usage:
 *   // Start session at the beginning of a controller flow
 *   $this->app->session->start();
 *
 *   // Persist a value
 *   $this->app->session->set('cart_id', $cartId);
 *
 *   // Rotate the id at a privilege change (citomni/authenticate does it at login and logout)
 *   $this->app->session->regenerate(true);
 *
 *   // Discard the session entirely
 *   $this->app->session->destroy();
 *
 * Method overview:
 * - start(): void
 * - isActive(): bool
 * - id(): string|null
 * - get(string $key): mixed|null
 * - set(string $key, mixed $value): void
 * - has(string $key): bool
 * - remove(string $key): void
 * - destroy(bool $forgetCookie = true): void
 * - regenerate(bool $deleteOld = true): void
 */
class Session extends BaseService {

	/** Guard: run INI/cookie param setup once. */
	private bool $iniInitialized = false;

	/** Session cookie name, resolved on first use (see sessionName()). */
	private ?string $sessionName = null;




/*
 *---------------------------------------------------------------
 * LIFECYCLE & STATE
 *---------------------------------------------------------------
 * PURPOSE
 *   Manage session lifecycle deterministically with a lazy start.
 *
 * NOTES
 *   - Only start(), set() and regenerate() create a session; reads resume one.
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
	 * - set() and regenerate() start the session when needed; reads do not.
	 * - Useful when a session must exist before the first write, or when its headers
	 *   should be sent early.
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
	 * @throws \RuntimeException If headers have already been sent, a setting is refused or session_start() fails.
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
	 * - False does not mean that the request has no session: A session is resumed on
	 *   its first read or write, so a request with the session cookie reports false
	 *   until then.
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
	 * - Returns null if no session has been started or resumed yet.
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
 *   - Reads resume an existing session and never create one; set() starts it.
 *   - Values must be serializable by the configured session handler.
 */


	/**
	 * Retrieve a session value by key.
	 *
	 * Behavior:
	 * - Resumes an existing session (see the class notes on reads); never creates one.
	 * - Returns the stored value, or null when the key or the session is missing.
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
		return $this->resume() ? ($_SESSION[$key] ?? null) : null;
	}


	/**
	 * Store a value in the session.
	 *
	 * Behavior:
	 * - Starts the session when it is not active (a write creates the session).
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
	 * - Resumes an existing session; never creates one. False without a session.
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
		return $this->resume() && \array_key_exists($key, $_SESSION);
	}


	/**
	 * Remove a key/value pair from the session.
	 *
	 * Behavior:
	 * - Resumes an existing session; never creates one.
	 * - Calls unset() on $_SESSION[$key].
	 * - No-op if the key or the session does not exist.
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
		if ($this->resume()) {
			unset($_SESSION[$key]);
		}
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
	 * - Resumes an existing session; without one there is nothing to destroy, and the
	 *   call returns without headers.
	 * - Clears $_SESSION and destroys the session in storage.
	 * - Removes the session cookie from $_COOKIE, so a later read in this request does
	 *   not resume the destroyed id.
	 * - When $forgetCookie is true, expires the session cookie using the
	 *   currently active cookie parameters (path, domain, secure, httponly, samesite).
	 *
	 * Notes:
	 * - When storage refuses the destruction, PHP closes the session and keeps its data.
	 *   The method then throws before touching the cookie, so storage and browser keep
	 *   the session unchanged and the request can be retried.
	 * - A refused cookie header (headers already sent) does not throw: The browser keeps
	 *   an id whose session no longer exists, and strict mode gives the next request a
	 *   new session instead.
	 * - After destroy(), a subsequent start() will create a fresh session id.
	 *
	 * Typical usage:
	 *   $this->app->session->destroy();         // discard the session and forget the cookie
	 *   $this->app->session->destroy(false);    // discard the session but keep the client cookie (rare)
	 *
	 * @param bool $forgetCookie When true, expire the client-side session cookie as well.
	 * @return void
	 * @throws \RuntimeException If session_destroy() fails.
	 */
	public function destroy(bool $forgetCookie = true): void {
		if (!$this->resume()) {
			return;
		}

		$name   = \session_name();
		$params = \session_get_cookie_params();

		$_SESSION = [];
		if (!\session_destroy()) {
			throw new \RuntimeException('session_destroy() failed; the session data is still in storage, and the browser keeps its cookie.');
		}

		// session_destroy() clears session_id(); the cookie still names the destroyed id.
		unset($_COOKIE[$name]);

		if ($forgetCookie) {
			// Expire the session cookie on the client with the attributes it was set with.
			\setcookie($name, '', [
				'expires'  => \time() - 42000,
				'path'     => $params['path'],
				'domain'   => $params['domain'],
				'secure'   => $params['secure'],
				'httponly' => $params['httponly'],
				'samesite' => $params['samesite'],
			]);
		}
	}

	/**
	 * Regenerate the session ID (mitigate fixation; rotate on privilege change).
	 *
	 * Behavior:
	 * - Starts the session when it is not active yet, like set(), so callers never depend
	 *   on an earlier call having started it.
	 * - Rotates the session id via session_regenerate_id(). The session data moves to the
	 *   new id.
	 * - Throws when PHP refuses the rotation. Continuing would leave the request on the
	 *   previous id, which defeats the purpose of rotating at a privilege boundary.
	 *
	 * Notes:
	 * - Call after login or privilege escalation to prevent session fixation.
	 * - With $deleteOld = true, the old session is deleted at once. A concurrent request that
	 *   still presents the old id gets a new, empty session (see the class notes). With
	 *   $deleteOld = false, the old id keeps a copy of the data until garbage collection, so
	 *   it remains usable.
	 * - session_regenerate_id() returns false when headers were already sent, and when the
	 *   storage handler cannot destroy the old session ($deleteOld = true). In the latter case
	 *   PHP also closes the session, so a later write would silently reopen the old id.
	 * - When no session existed yet, session_start() issues an id and this call rotates it once
	 *   more. PHP replaces the queued Set-Cookie header, so only the final id reaches the client.
	 *
	 * Typical usage:
	 *   $this->app->session->regenerate(true); // delete old id mapping
	 *
	 * @param bool $deleteOld When true, delete old session id mapping on rotation.
	 * @return void
	 * @throws \RuntimeException If the session cannot be started (headers already sent,
	 *                           session_start() failure), or if session_regenerate_id() fails.
	 */
	public function regenerate(bool $deleteOld = true): void {
		$this->ensureStarted();
		if (!\session_regenerate_id($deleteOld)) {
			throw new \RuntimeException('session_regenerate_id() failed; refusing to continue on the previous session id.');
		}
	}





/*
 *---------------------------------------------------------------
 * INTERNALS (DO NOT CALL DIRECTLY)
 *---------------------------------------------------------------
 * PURPOSE
 *   One-time INI/cookie setup and helpers.
 *
 * NOTES
 *   - ensureStarted() performs guards + init + start; resume() decides whether a
 *     session exists to start.
 */


	/**
	 * Resume the session when one exists, without creating one.
	 *
	 * Behavior:
	 * - An active session: Returns true.
	 * - session_id() is not empty: A session was established earlier in this request and
	 *   closed since (session_write_close() keeps the id). It is started again, and the
	 *   method returns true.
	 * - The request carries the session cookie as a non-empty string: The session is
	 *   started with that id, and the method returns true. Under strict mode an id that
	 *   storage does not know gets a new, empty session.
	 * - Otherwise it returns false, without touching storage or sending headers.
	 *
	 * Notes:
	 * - Array-shaped cookie input (CITSESSID[]=x) counts as absent.
	 *
	 * @return bool True when a session is active on return.
	 * @throws \RuntimeException As ensureStarted(), when an existing session cannot be started.
	 */
	private function resume(): bool {
		if (\session_status() === \PHP_SESSION_ACTIVE) {
			return true;
		}

		$cookie = $_COOKIE[$this->sessionName()] ?? null;
		if (\session_id() === '' && (!\is_string($cookie) || $cookie === '')) {
			return false;
		}

		$this->ensureStarted();
		return true;
	}


	/**
	 * Name of the session cookie: session.name, or PHP's when that is empty.
	 *
	 * Notes:
	 * - resume() needs the name before initIniOnce() has applied it.
	 *
	 * @return string Session cookie name.
	 */
	private function sessionName(): string {
		if ($this->sessionName === null) {
			$name = (string)$this->app->cfg->session->name;
			$this->sessionName = $name !== '' ? $name : (string)\session_name();
		}
		return $this->sessionName;
	}


	/**
	 * Ensure a session is active, initializing runtime settings once.
	 *
	 * Behavior:
	 * - Returns immediately if a session is already active.
	 * - Verifies headers have not been sent yet (required for session_start()).
	 * - Applies INI and cookie param initialization once via initIniOnce().
	 * - Starts the session.
	 *
	 * Notes:
	 * - The only path to session_start(): start(), set() and regenerate() call it, and
	 *   resume() calls it for a session that exists.
	 * - Throws early on header/state violations to preserve deterministic behavior.
	 *
	 * @return void
	 * @throws \RuntimeException If headers have already been sent, a setting is refused or session_start() fails.
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
	}


	/**
	 * Initialize session-related INI directives and cookie params once.
	 *
	 * Behavior:
	 * - Refuses removed options that are still enabled (see refuseRemovedOptions()).
	 * - Applies the hardening and storage settings from session.* (baseline in
	 *   Registry::CFG_HTTP) and creates save_path when it is missing.
	 * - Resolves the session cookie through Cookie::attributes(), with the non-null
	 *   session.cookie_* settings as overrides, and applies it with lifetime 0.
	 *   session.cookie_domain '' forces host-only even when cookie.domain is set.
	 *
	 * Notes:
	 * - Every setting goes through ini(), the session name and the cookie parameters
	 *   included. PHP refuses one, for example, when the server locks it with
	 *   php_admin_value; continuing would run on the server's value instead of the
	 *   configured one.
	 * - The guard is set only after every step succeeded. After a throw, the next start
	 *   in the same request runs the setup again instead of session_start() on php.ini
	 *   values.
	 *
	 * @return void
	 * @throws \RuntimeException When a removed option is enabled, save_path cannot be
	 *                           created, or PHP refuses a setting.
	 * @throws \InvalidArgumentException For an invalid session.cookie_* value (from Cookie).
	 */
	private function initIniOnce(): void {
		if ($this->iniInitialized) {
			return;
		}

		$cfg = $this->app->cfg->session->toArray();
		$this->refuseRemovedOptions($cfg);

		// -- 1. Hardening and storage --------------------------------------
		$this->ini('session.use_strict_mode',  (bool)$cfg['use_strict_mode']);
		$this->ini('session.use_only_cookies', (bool)$cfg['use_only_cookies']);
		$this->ini('session.lazy_write',       (bool)$cfg['lazy_write']);
		$this->ini('session.gc_maxlifetime',   (int)$cfg['gc_maxlifetime']);
		$this->ini('session.gc_probability',   (int)$cfg['gc_probability']);
		$this->ini('session.gc_divisor',       (int)$cfg['gc_divisor']);

		$savePath = (string)$cfg['save_path'];
		if ($savePath !== '') {
			// mkdir() fails when a concurrent request created the directory first; is_dir() decides.
			if (!\is_dir($savePath) && !@\mkdir($savePath, 0775, true) && !\is_dir($savePath)) {
				throw new \RuntimeException("Cannot create session.save_path: {$savePath}");
			}
			$this->ini('session.save_path', $savePath);
		}

		// session_name() ignores a refused value (PHP only warns), so the name goes through ini().
		$name = (string)$cfg['name'];
		if ($name !== '') {
			$this->ini('session.name', $name);
		}

		// -- 2. Session cookie: Cookie's attributes, session.cookie_* on top --
		$overrides = [];
		foreach (['secure', 'httponly', 'samesite', 'path', 'domain'] as $attribute) {
			if ($cfg['cookie_' . $attribute] !== null) {
				$overrides[$attribute] = $cfg['cookie_' . $attribute];
			}
		}
		$cookie = $this->app->cookie->attributes($overrides);

		// The directives session_set_cookie_params() sets, each checked on its own.
		$this->ini('session.cookie_lifetime', 0);
		$this->ini('session.cookie_path',     (string)$cookie['path']);
		$this->ini('session.cookie_domain',   (string)($cookie['domain'] ?? ''));
		$this->ini('session.cookie_secure',   (bool)$cookie['secure']);
		$this->ini('session.cookie_httponly', (bool)$cookie['httponly']);
		$this->ini('session.cookie_samesite', (string)$cookie['samesite']);

		$this->iniInitialized = true;
	}


	/**
	 * Refuse session options that earlier versions supported and this version removed.
	 *
	 * Behavior:
	 * - session.rotate_interval > 0: Periodic rotation called regenerate(true) on a timer and
	 *   so discarded every concurrent request that still presented the old id.
	 * - session.fingerprint with any binding enabled: It bound the session to the raw
	 *   REMOTE_ADDR, which behind a proxy is the proxy's address, and on a mismatch it
	 *   destroyed the session without telling the code that owned its contents.
	 * - Disabled values (0, false, an empty list) are ignored, so a configuration that only
	 *   repeats the old defaults keeps working.
	 *
	 * Notes:
	 * - The settings are not in the baseline anymore; only an application or provider
	 *   config can still contain them. Failing loudly beats silently ignoring a security
	 *   setting that someone enabled on purpose.
	 *
	 * @param array<string,mixed> $cfg The session cfg node.
	 * @return void
	 * @throws \RuntimeException When a removed option is enabled.
	 */
	private function refuseRemovedOptions(array $cfg): void {
		if ((int)($cfg['rotate_interval'] ?? 0) > 0) {
			throw new \RuntimeException('session.rotate_interval was removed: Periodic rotation deleted the old session at once and lost concurrent requests. Remove the setting; regenerate() at privilege changes remains.');
		}

		$fingerprint = $cfg['fingerprint'] ?? [];
		if (\is_array($fingerprint)
			&& (!empty($fingerprint['bind_user_agent'])
				|| (int)($fingerprint['bind_ip_octets'] ?? 0) > 0
				|| (int)($fingerprint['bind_ip_blocks'] ?? 0) > 0)
		) {
			throw new \RuntimeException('session.fingerprint was removed: It bound sessions to REMOTE_ADDR, the proxy address behind a proxy, and reset them without notice. Remove the setting.');
		}
	}


	/**
	 * Set one INI directive, or throw when PHP refuses it.
	 *
	 * Behavior:
	 * - Casts booleans to '1'/'0' and integers to strings.
	 * - ini_set() returns false when PHP rejects the value (for example gc_divisor 0) or
	 *   when the directive cannot be changed here, as when the server locks it with
	 *   php_admin_value. The configured value would then not apply, so this throws,
	 *   unless the directive already holds exactly that value.
	 *
	 * @param string          $key   INI directive name (e.g., "session.use_strict_mode").
	 * @param bool|int|string $value Value to assign.
	 * @return void
	 * @throws \RuntimeException When PHP refuses the value and keeps a different one.
	 */
	private function ini(string $key, bool|int|string $value): void {
		$v = \is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		if (@\ini_set($key, $v) === false && \ini_get($key) !== $v) {
			throw new \RuntimeException("PHP refused {$key} = {$v}: The value is invalid, or the server configuration locks the setting.");
		}
	}

}
