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

use CitOmni\Http\Exception\CaptchaConfigException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Captcha: Session-backed captcha challenges for HTML forms.
 *
 * A challenge is a code from the "captchaImage" service (citomni/image), kept
 * in the session under a random id until the form is posted. The page carries
 * the id in a hidden field and shows the image served by CaptchaController;
 * the form handler calls verify().
 *
 * Behavior:
 * - issue() draws a code and a render seed, stores them in the session with
 *   an expiry, and returns the challenge id. current() issues once per
 *   request and returns that id afterwards, so htmlField() and imagePath()
 *   refer to the same challenge.
 * - image() renders a pending challenge with its stored seed, so every
 *   request for it returns the same picture and a reload gives no second
 *   distortion to compare.
 * - verify() reads the id and answer fields from the POST body;
 *   verifyAnswer() takes them as arguments. Both remove the challenge before
 *   comparing, so every challenge gets exactly one attempt, right or wrong.
 * - Expired challenges never match. At most security.captcha.max_pending
 *   challenges are kept per session (several tabs or forms); issuing beyond
 *   that drops the oldest.
 * - With security.captcha_protection off, verify() and verifyAnswer() return
 *   true, as Csrf::verify() does with CSRF protection off.
 *
 * Notes:
 * - image() and the verify methods return without starting a session when
 *   the request carries no session cookie. Such a request cannot hold a
 *   challenge, and starting a session would create server state for every
 *   cookieless request.
 * - Stored entries without the expected shape are discarded like expired
 *   ones. The state is short-lived, so a format from another version costs
 *   one failed challenge, not an error page.
 * - Codes, pictures and the comparison come from the "captchaImage" service.
 *   Without it, issuing or checking a challenge fails with
 *   CaptchaConfigException.
 * - The package registers no route for CaptchaController; an app opts in with
 *   one route entry in /config/citomni_http_routes.php.
 *
 * Config node: security.captcha_protection and security.captcha (see init()).
 *
 * Typical usage:
 *   // Template, inside the form:
 *   {{{ $captchaField() }}}
 *   <img src="{{ $captchaUrl() }}" width="200" height="64" alt="Security code">
 *   <input type="text" name="captcha" autocomplete="off" required>
 *
 *   // Form handler:
 *   if (!$this->app->captcha->verify()) {
 *       // Wrong, expired or missing: show the form again with a new challenge.
 *   }
 */
final class Captcha extends BaseService {

	// Challenge ids: 8 random bytes as lowercase hex.
	private const ID_PATTERN = '/^[0-9a-f]{16}$/D';

	/** Whether captcha protection is on (security.captcha_protection). */
	private bool $enabled;

	/** Session key holding the pending challenges. */
	private string $sessionKey;

	/** Seconds a challenge stays valid. */
	private int $ttl;

	/** Pending challenges kept per session. */
	private int $maxPending;

	/** POST field carrying the challenge id. */
	private string $idField;

	/** POST field carrying the answer. */
	private string $answerField;

	/** App path routed to CaptchaController::image. */
	private string $imagePath;

	/** Session cookie name: session.name, or PHP's default when that is empty. */
	private string $cookieName;

	/** Challenge issued by current() during this request. */
	private ?string $current = null;


	/**
	 * Read and validate the captcha configuration.
	 *
	 * Behavior:
	 * - security.captcha_protection (bool): Whether verification is enforced.
	 * - security.captcha.session_key (string): Session key for pending challenges.
	 * - security.captcha.ttl (int >= 1): Seconds a challenge stays valid.
	 * - security.captcha.max_pending (int >= 1): Pending challenges per session.
	 * - security.captcha.id_field, answer_field (string): POST field names;
	 *   they must differ.
	 * - security.captcha.image_path (string): App path routed to
	 *   CaptchaController::image, starting with "/", without query or fragment.
	 * - No session, request or image service is touched here.
	 *
	 * @return void
	 * @throws CaptchaConfigException When a value is invalid.
	 */
	protected function init(): void {
		$security = $this->app->cfg->security;
		$cfg = $security->captcha;

		if (!\is_bool($security->captcha_protection)) {
			throw new CaptchaConfigException('Config security.captcha_protection must be a bool.');
		}

		$this->enabled = $security->captcha_protection;
		$this->sessionKey = self::cfgString($cfg->session_key, 'session_key');
		$this->ttl = self::cfgPositiveInt($cfg->ttl, 'ttl');
		$this->maxPending = self::cfgPositiveInt($cfg->max_pending, 'max_pending');
		$this->idField = self::cfgString($cfg->id_field, 'id_field');
		$this->answerField = self::cfgString($cfg->answer_field, 'answer_field');

		if ($this->idField === $this->answerField) {
			throw new CaptchaConfigException('Config security.captcha.id_field and security.captcha.answer_field must differ.');
		}

		if (!\is_string($cfg->image_path) || \preg_match('~^/[^?#\s]*$~D', $cfg->image_path) !== 1) {
			throw new CaptchaConfigException('Config security.captcha.image_path must be an app path that starts with "/" and has no query or fragment.');
		}

		$this->imagePath = $cfg->image_path;

		$name = $this->app->cfg->session->name;
		$this->cookieName = \is_string($name) && $name !== '' ? $name : (string)\ini_get('session.name');
	}


	// ----------------------------------------------------------------
	// Public API - Challenges
	// ----------------------------------------------------------------

	/**
	 * Whether captcha protection is on.
	 *
	 * @return bool Value of security.captcha_protection.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Issue a new challenge and return its id.
	 *
	 * Behavior:
	 * - Draws a code with captchaImage->code() and a random render seed, and
	 *   appends them with an expiry to the session's pending challenges.
	 * - Drops expired entries, then the oldest beyond max_pending.
	 * - Starts the session when needed. Works whether or not captcha
	 *   protection is on; templates check isEnabled() first.
	 *
	 * Notes:
	 * - The session may only start while headers can still be sent, as with
	 *   Csrf::token(). Call it before output, or rely on output buffering.
	 *
	 * Typical usage:
	 *   $id = $this->app->captcha->issue();
	 *
	 * @return string Challenge id (16 lowercase hex characters).
	 * @throws CaptchaConfigException When the captchaImage service is not registered.
	 */
	public function issue(): string {
		$images = $this->images();
		$id = \bin2hex(\random_bytes(8));
		$pending = $this->pending();
		$pending[] = [$id, $images->code(), \random_int(0, \PHP_INT_MAX), \time() + $this->ttl];

		$this->app->session->set($this->sessionKey, \array_slice($pending, -$this->maxPending));

		return $id;
	}


	/**
	 * Return this request's challenge id, issuing it on the first call.
	 *
	 * @return string Challenge id.
	 * @throws CaptchaConfigException When the captchaImage service is not registered.
	 */
	public function current(): string {
		return $this->current ??= $this->issue();
	}


	/**
	 * Return a hidden input carrying this request's challenge id.
	 *
	 * Output is HTML-escaped. Use triple braces in templates: {{{ $captchaField() }}}.
	 *
	 * @return string Full <input type="hidden"> element.
	 * @throws CaptchaConfigException When the captchaImage service is not registered.
	 */
	public function htmlField(): string {
		return '<input type="hidden" name="' . self::escape($this->idField) . '" value="' . self::escape($this->current()) . '">';
	}


	/**
	 * Return the app path of this request's challenge image.
	 *
	 * @return string security.captcha.image_path with "?id=" and the challenge id.
	 * @throws CaptchaConfigException When the captchaImage service is not registered.
	 */
	public function imagePath(): string {
		return $this->imagePath . '?id=' . $this->current();
	}


	/**
	 * Render the image of a pending challenge.
	 *
	 * Behavior:
	 * - Renders with the challenge's stored seed, so repeated requests return
	 *   the same picture.
	 * - Returns null for an id that is not a string, is malformed, unknown or
	 *   expired, and for a request without a session cookie, which is answered
	 *   without starting a session.
	 * - Does not consume the challenge and does not write the session.
	 *
	 * Typical usage:
	 *   $png = $this->app->captcha->image($this->app->request->get('id'));
	 *
	 * @param mixed $id Challenge id from the request; untrusted.
	 * @return array{data:string, format:string, mime:string, width:int, height:int, bytes:int}|null PNG, or null.
	 * @throws CaptchaConfigException When the captchaImage service is not registered.
	 */
	public function image(mixed $id): ?array {
		if (!\is_string($id) || \preg_match(self::ID_PATTERN, $id) !== 1 || !$this->hasSession()) {
			return null;
		}

		foreach ($this->pending() as [$pendingId, $code, $seed]) {
			if ($pendingId === $id) {
				return $this->images()->render($code, ['seed' => $seed]);
			}
		}

		return null;
	}


	// ----------------------------------------------------------------
	// Public API - Verification
	// ----------------------------------------------------------------

	/**
	 * Verify the answer posted with the current request.
	 *
	 * Behavior:
	 * - Reads security.captcha.id_field and answer_field from the POST body;
	 *   a field that is missing or not a string counts as empty.
	 * - Otherwise as verifyAnswer().
	 *
	 * Typical usage:
	 *   if (!$this->app->captcha->verify()) {
	 *       $this->app->flash->error('The code was wrong. Try the new one.');
	 *   }
	 *
	 * @return bool True when protection is off, or the answer matches a pending challenge.
	 * @throws CaptchaConfigException When a challenge matches but the captchaImage service is not registered.
	 */
	public function verify(): bool {
		if (!$this->enabled) {
			return true;
		}

		$id = $this->app->request->post($this->idField);
		$answer = $this->app->request->post($this->answerField);

		return $this->verifyAnswer(\is_string($id) ? $id : '', \is_string($answer) ? $answer : '');
	}


	/**
	 * Verify an answer for a challenge id.
	 *
	 * Behavior:
	 * - Returns true without checking when captcha protection is off.
	 * - Removes the challenge from the session first, so it never gets a
	 *   second attempt; expired entries are dropped in the same write.
	 * - Compares with captchaImage->verify(): ASCII case-insensitive,
	 *   whitespace ignored, constant time.
	 * - Returns false without starting a session when the request carries no
	 *   session cookie.
	 *
	 * Typical usage:
	 *   $ok = $this->app->captcha->verifyAnswer($payload['captcha_id'], $payload['captcha']);
	 *
	 * @param string $id Challenge id from the request.
	 * @param string $answer Answer from the request.
	 * @return bool True when protection is off, or the answer matches the pending challenge.
	 * @throws CaptchaConfigException When a challenge matches but the captchaImage service is not registered.
	 */
	public function verifyAnswer(string $id, string $answer): bool {
		if (!$this->enabled) {
			return true;
		}

		if (!$this->hasSession()) {
			return false;
		}

		$pending = $this->pending();
		$code = null;

		foreach ($pending as $index => [$pendingId, $pendingCode]) {
			if ($pendingId === $id) {
				$code = $pendingCode;
				unset($pending[$index]);
				break;
			}
		}

		if ($pending === []) {
			$this->app->session->remove($this->sessionKey);
		} else {
			$this->app->session->set($this->sessionKey, \array_values($pending));
		}

		return $code !== null && $this->images()->verify($code, $answer);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Pending, unexpired challenges from the session, oldest first.
	 *
	 * Starts the session when needed. Entries without the expected shape are
	 * left out.
	 *
	 * @return list<array{0:string, 1:string, 2:int, 3:int}> Id, code, render seed, expiry (Unix time).
	 */
	private function pending(): array {
		$stored = $this->app->session->get($this->sessionKey);

		if (!\is_array($stored)) {
			return [];
		}

		$now = \time();
		$pending = [];

		foreach ($stored as $entry) {
			if (
				\is_array($entry) && \array_is_list($entry) && \count($entry) === 4
				&& \is_string($entry[0]) && \preg_match(self::ID_PATTERN, $entry[0]) === 1
				&& \is_string($entry[1]) && $entry[1] !== ''
				&& \is_int($entry[2])
				&& \is_int($entry[3]) && $entry[3] > $now
			) {
				$pending[] = $entry;
			}
		}

		return $pending;
	}


	/**
	 * Whether the request can belong to a session: one is active, or the
	 * request carries the session cookie.
	 *
	 * @return bool True when reading the session is worthwhile.
	 */
	private function hasSession(): bool {
		return $this->app->session->isActive() || $this->app->request->cookie($this->cookieName) !== null;
	}


	/**
	 * Return the captchaImage service from citomni/image.
	 *
	 * @return object The service.
	 * @throws CaptchaConfigException When it is not registered.
	 */
	private function images(): object {
		if (!$this->app->hasService('captchaImage')) {
			throw new CaptchaConfigException('Captcha challenges need the captchaImage service: install citomni/image and add \CitOmni\Image\Boot\Registry to /config/providers.php.');
		}

		return $this->app->captchaImage;
	}


	/**
	 * Escape a value for an HTML attribute.
	 *
	 * @param string $value Value.
	 * @return string Escaped value.
	 */
	private static function escape(string $value): string {
		return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
	}


	/**
	 * Validate a non-empty string under security.captcha.
	 *
	 * @param mixed $value Raw value.
	 * @param string $key Key below security.captcha.
	 * @return string Value.
	 * @throws CaptchaConfigException When invalid.
	 */
	private static function cfgString(mixed $value, string $key): string {
		if (!\is_string($value) || $value === '') {
			throw new CaptchaConfigException('Config security.captcha.' . $key . ' must be a non-empty string.');
		}

		return $value;
	}


	/**
	 * Validate a positive int under security.captcha.
	 *
	 * @param mixed $value Raw value.
	 * @param string $key Key below security.captcha.
	 * @return int Value.
	 * @throws CaptchaConfigException When invalid.
	 */
	private static function cfgPositiveInt(mixed $value, string $key): int {
		if (!\is_int($value) || $value < 1) {
			throw new CaptchaConfigException('Config security.captcha.' . $key . ' must be an int >= 1.');
		}

		return $value;
	}


}
