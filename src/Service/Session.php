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
 * Session: Service around PHP's native session, started only when needed.
 *
 * Owns the session lifecycle and its storage: When a session starts or resumes, which session
 * INI settings apply, where the data is stored and how long PHP keeps it. The default attributes
 * of the session cookie belong to Cookie::attributes(); what each key holds belongs to the callers.
 *
 * Rules:
 * - Never start a session on a read unless the request already has one, by its session cookie
 *   or by a start earlier in this request. A request without a session that only reads gets no
 *   session cookie, no session file and no session cache headers.
 * - Call session_start() only through ensureStarted(), so every session starts with the
 *   settings of initIniOnce().
 * - Apply every session setting through ini(), so a setting PHP refuses throws instead of
 *   leaving the server's value in place.
 * - Never throw for a missing key or a missing session.
 */
class Session extends BaseService {

	/** Whether initIniOnce() has applied the session settings. */
	private bool $iniInitialized = false;

	/** Session cookie name, or null until sessionName() resolves it. */
	private ?string $sessionName = null;




	// ----------------------------------------------------------------
	// Lifecycle
	// ----------------------------------------------------------------


	/**
	 * Start or resume the session unless one is active.
	 *
	 * Start it explicitly when a session must exist before the first write, or when its headers
	 * must go out early.
	 *
	 * Behavior:
	 * - Returns at once when a session is active.
	 * - On return a session is active and $_SESSION is usable.
	 *
	 * Typical usage:
	 *   $this->app->session->start();
	 *
	 * @return void
	 * @throws \RuntimeException  When headers were already sent, the session settings cannot be applied, or session_start() fails.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::initIniOnce()  The settings applied before the first start.
	 */
	public function start(): void {
		$this->ensureStarted();
	}


	/**
	 * Check whether a session is active.
	 *
	 * Notes:
	 * - Never starts or resumes a session, so false does not mean that the request has no
	 *   session: One that the request carries may not have been resumed yet.
	 *
	 * @return bool  True when session_status() is PHP_SESSION_ACTIVE.
	 */
	public function isActive(): bool {
		return \session_status() === \PHP_SESSION_ACTIVE;
	}


	/**
	 * Return the id of the active session.
	 *
	 * Notes:
	 * - Never starts or resumes a session, so a session that has not been resumed yet reports
	 *   null. Call start() first when an id is needed.
	 *
	 * @return string|null  The id, or null when no session is active.
	 */
	public function id(): ?string {
		return \session_status() === \PHP_SESSION_ACTIVE ? \session_id() : null;
	}





	// ----------------------------------------------------------------
	// Read and write
	// ----------------------------------------------------------------


	/**
	 * Return one session value.
	 *
	 * Behavior:
	 * - Resumes the session when the request has one; never starts one otherwise.
	 * - Returns null when the key or the session is missing; has() tells a stored null apart.
	 *
	 * Typical usage:
	 *   $userId = $this->app->session->get('user_id');
	 *
	 * @param  string  $key  Case-sensitive.
	 * @return mixed  The stored value, or null.
	 * @throws \RuntimeException  When an existing session cannot be resumed.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::resume()  When a session counts as existing, and what resuming it does.
	 */
	public function get(string $key): mixed {
		return $this->resume() ? ($_SESSION[$key] ?? null) : null;
	}


	/**
	 * Store one value, starting or resuming the session when none is active.
	 *
	 * A write creates the session when the request has none. The value replaces any value under
	 * the same key.
	 *
	 * Notes:
	 * - Under session.serialize_handler php, PHP's default, a key that PHP turns into an integer,
	 *   such as '42', is not saved with the session (PHP warns), and a key that contains "|" makes
	 *   PHP save the session with no data at all.
	 *
	 * Typical usage:
	 *   $this->app->session->set('cart_id', $cartId);
	 *
	 * @param  string  $key  Case-sensitive.
	 * @param  mixed  $value  Must be serializable by the session serializer.
	 * @return void
	 * @throws \RuntimeException  When headers were already sent, the session settings cannot be applied, or session_start() fails.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 */
	public function set(string $key, mixed $value): void {
		$this->ensureStarted();
		$_SESSION[$key] = $value;
	}


	/**
	 * Check whether a key exists, also when its value is null.
	 *
	 * Behavior:
	 * - Resumes the session when the request has one; never starts one otherwise.
	 * - False without a session.
	 *
	 * @param  string  $key  Case-sensitive.
	 * @return bool
	 * @throws \RuntimeException  When an existing session cannot be resumed.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::resume()  When a session counts as existing, and what resuming it does.
	 */
	public function has(string $key): bool {
		return $this->resume() && \array_key_exists($key, $_SESSION);
	}


	/**
	 * Remove one key from the session.
	 *
	 * Resumes the session when the request has one, and never starts one otherwise. Without a
	 * session or the key, nothing changes.
	 *
	 * Typical usage:
	 *   $this->app->session->remove('cart_id');
	 *
	 * @param  string  $key  Case-sensitive.
	 * @return void
	 * @throws \RuntimeException  When an existing session cannot be resumed.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::resume()  When a session counts as existing, and what resuming it does.
	 */
	public function remove(string $key): void {
		if ($this->resume()) {
			unset($_SESSION[$key]);
		}
	}





	// ----------------------------------------------------------------
	// Rotation and teardown
	// ----------------------------------------------------------------


	/**
	 * Destroy the session and, by default, expire its cookie in the browser.
	 *
	 * Ends the session in storage and in this request, as at logout. When the request has no
	 * session, there is nothing to destroy, and the call returns without sending a header.
	 *
	 * Behavior:
	 * - Clears $_SESSION and destroys the session in storage.
	 * - Removes the session cookie from $_COOKIE, so a later read in this request does not resume
	 *   the destroyed id; a later start or write creates a session with a new id.
	 * - With $forgetCookie, sends an expired session cookie with the path, domain, secure,
	 *   httponly and samesite of the session cookie parameters.
	 * - When storage refuses the destruction, PHP closes the session and keeps its data. The
	 *   method then throws before touching $_COOKIE or the browser cookie, so storage and browser
	 *   keep the session and the request can be retried.
	 *
	 * Notes:
	 * - The result of setcookie() is not checked: When headers were already sent, PHP only warns,
	 *   and the browser keeps an id whose session no longer exists. Strict mode gives the next
	 *   request a new session instead.
	 *
	 * Typical usage:
	 *   $this->app->session->destroy();
	 *
	 * @param  bool  $forgetCookie  False keeps the cookie in the browser.
	 * @return void
	 * @throws \RuntimeException  When session_destroy() fails, or an existing session cannot be resumed.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::resume()  When a session counts as existing.
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
	 * Give the session a new id, starting or resuming the session when none is active.
	 *
	 * Rotating at a privilege change, such as login, keeps an id that someone else knew before
	 * (session fixation) from carrying the new privileges. The data moves to the new id.
	 *
	 * Behavior:
	 * - Throws when PHP refuses the rotation, rather than continue on the previous id.
	 * - With $deleteOld, the old session is deleted at once. A concurrent request that still
	 *   presents the old id then gets a new, empty session under strict mode, and its Set-Cookie
	 *   may replace the new id in the browser.
	 * - Without $deleteOld, the old id keeps a copy of the data until garbage collection, so it
	 *   stays usable.
	 *
	 * Notes:
	 * - Rotate at privilege changes, not on a timer: There is no grace window for concurrent
	 *   requests on the old id.
	 * - session_regenerate_id() fails when headers were already sent, and, with $deleteOld, when
	 *   storage cannot delete the old session. In the latter case PHP also closes the session, so
	 *   continuing would let a later write reopen the old id.
	 * - Without an earlier session, session_start() issues an id and this call rotates it once
	 *   more. PHP replaces the queued Set-Cookie header, so only the final id reaches the browser.
	 *
	 * Typical usage:
	 *   $this->app->session->regenerate();
	 *
	 * @param  bool  $deleteOld  True deletes the old session at once.
	 * @return void
	 * @throws \RuntimeException  When headers were already sent, the session settings cannot be applied, session_start() fails, or session_regenerate_id() fails.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 */
	public function regenerate(bool $deleteOld = true): void {
		$this->ensureStarted();
		if (!\session_regenerate_id($deleteOld)) {
			throw new \RuntimeException('session_regenerate_id() failed; refusing to continue on the previous session id.');
		}
	}





	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------


	/**
	 * Resume the session when one exists, without creating one.
	 *
	 * Behavior:
	 * - Returns true when a session is active.
	 * - Starts the session and returns true when session_id() is not empty, as after
	 *   session_write_close() of a session started earlier in this request.
	 * - Starts the session and returns true when the request carries the session cookie as a
	 *   non-empty string. Under strict mode an id that storage does not know (expired, collected
	 *   or forged) gets a new, empty session, whose cookie replaces the stale one.
	 * - Otherwise returns false without touching storage or sending headers.
	 *
	 * Notes:
	 * - Only the session cookie counts: With session.use_only_cookies off, an id that arrives
	 *   only in the URL is not resumed. Array-shaped cookie input (name[]=x) counts as absent.
	 *
	 * @return bool  True when a session is active on return.
	 * @throws \RuntimeException  When an existing session cannot be started; see ensureStarted().
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
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
	 * Return the session cookie name: cfg session.name, or PHP's session name when that is empty.
	 *
	 * @return string
	 */
	private function sessionName(): string {
		if ($this->sessionName === null) {
			// resume() needs the name before initIniOnce() applies it, so cfg decides, not session_name().
			$name = (string)$this->app->cfg->session->name;
			$this->sessionName = $name !== '' ? $name : (string)\session_name();
		}
		return $this->sessionName;
	}


	/**
	 * Start or resume the session unless one is active, with the session settings applied first.
	 *
	 * Behavior:
	 * - Returns at once when a session is active.
	 * - Throws before any setting changes when headers were already sent; the message names the
	 *   file and line where the output started.
	 *
	 * @return void
	 * @throws \RuntimeException  When headers were already sent, the session settings cannot be applied, or session_start() fails.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Session::initIniOnce()  The settings, and when they cannot be applied.
	 */
	private function ensureStarted(): void {
		if (\session_status() === \PHP_SESSION_ACTIVE) {
			return;
		}

		// Guard before any INI or cookie parameter changes.
		if (\headers_sent($file, $line)) {
			throw new \RuntimeException("Cannot start session: headers already sent at {$file}:{$line}");
		}

		$this->initIniOnce();

		if (!@\session_start()) {
			throw new \RuntimeException('session_start() failed.');
		}
	}


	/**
	 * Apply the session settings from cfg session.* once per instance.
	 *
	 * The guard is set only after every step succeeded, so after a throw the next call applies
	 * the settings again rather than leaving a session to start on php.ini values.
	 *
	 * Behavior:
	 * - Throws for an enabled session.rotate_interval or session.fingerprint; see refuseRemovedOptions().
	 * - Applies use_strict_mode, use_only_cookies and lazy_write as bools, and gc_maxlifetime,
	 *   gc_probability and gc_divisor as ints.
	 * - Applies a non-empty save_path, after creating it with its parents and mode 0775 when it is
	 *   missing. An empty save_path keeps PHP's.
	 * - Applies a non-empty name; an empty one keeps PHP's.
	 * - Takes the session cookie attributes from Cookie::attributes(), with each non-null
	 *   cookie_secure, cookie_httponly, cookie_samesite, cookie_path and cookie_domain as an
	 *   override, and applies them with lifetime 0. A cookie_domain of "" makes the session cookie
	 *   host-only even when cookie.domain is set.
	 *
	 * @return void
	 * @throws \RuntimeException  When a removed option is enabled, save_path cannot be created, PHP refuses a setting, or the session cookie would have SameSite=None without Secure.
	 * @throws \InvalidArgumentException  When a session.cookie_* value is invalid.
	 * @see \CitOmni\Http\Service\Cookie::attributes()  How the overrides are normalized and checked.
	 */
	private function initIniOnce(): void {
		if ($this->iniInitialized) {
			return;
		}

		$cfg = $this->app->cfg->session->toArray();
		$this->refuseRemovedOptions($cfg);

		// -- 1. Hardening and storage ------------------------------------
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

		// -- 2. Session cookie from Cookie::attributes() -----------------
		$overrides = [];
		foreach (['secure', 'httponly', 'samesite', 'path', 'domain'] as $attribute) {
			if ($cfg['cookie_' . $attribute] !== null) {
				$overrides[$attribute] = $cfg['cookie_' . $attribute];
			}
		}
		$cookie = $this->app->cookie->attributes($overrides);

		// The directives session_set_cookie_params() sets, each checked on its own. Lifetime 0
		// whatever php.ini says: Persistent login belongs to a remember-me mechanism.
		$this->ini('session.cookie_lifetime', 0);
		$this->ini('session.cookie_path',     (string)$cookie['path']);
		$this->ini('session.cookie_domain',   (string)($cookie['domain'] ?? ''));
		$this->ini('session.cookie_secure',   (bool)$cookie['secure']);
		$this->ini('session.cookie_httponly', (bool)$cookie['httponly']);
		$this->ini('session.cookie_samesite', (string)$cookie['samesite']);

		$this->iniInitialized = true;
	}


	/**
	 * Throw for a removed session option that is still enabled.
	 *
	 * Ignoring a security setting that someone enabled on purpose would leave them believing that
	 * it applies, so an enabled one fails loudly. The message says why the option was removed.
	 *
	 * Behavior:
	 * - Throws when session.rotate_interval is above 0.
	 * - Throws when session.fingerprint is an array with a truthy bind_user_agent, or with
	 *   bind_ip_octets or bind_ip_blocks above 0.
	 * - Ignores disabled values (0, false, an empty list), so a cfg that only repeats the old
	 *   defaults keeps working.
	 *
	 * @param  array<string,mixed>  $cfg  The session cfg node.
	 * @return void
	 * @throws \RuntimeException  When a removed option is enabled.
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
	 * Set one INI directive, or throw when PHP keeps a different value.
	 *
	 * Behavior:
	 * - Writes a bool as '1' or '0' and an int as its decimal string.
	 * - Does not throw when ini_set() fails but the directive already holds exactly that value.
	 *
	 * @param  string  $key  INI directive, such as session.use_strict_mode.
	 * @param  bool|int|string  $value
	 * @return void
	 * @throws \RuntimeException  When PHP refuses the value and the directive holds a different one.
	 */
	private function ini(string $key, bool|int|string $value): void {
		$v = \is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		// ini_set() returns false when PHP rejects the value, such as gc_divisor 0, or cannot change
		// the directive here, as when the server locks it with php_admin_value.
		if (@\ini_set($key, $v) === false && \ini_get($key) !== $v) {
			throw new \RuntimeException("PHP refused {$key} = {$v}: The value is invalid, or the server configuration locks the setting.");
		}
	}

}
