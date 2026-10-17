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
 * Captcha: Service for session-backed captcha challenges in HTML forms.
 *
 * Keeps each challenge, a code with a render seed, in the session under a random id until the
 * form is posted, and gives it exactly one attempt. Drawing codes, drawing pictures and
 * comparing answers belong to the captchaImage service of citomni/image; serving the picture
 * belongs to CaptchaController.
 *
 * Rules:
 * - Never send a code to the client; only the id and the picture leave the server.
 * - Remove a challenge from the session before its answer is compared, so a wrong answer uses
 *   it up too.
 * - Never start a session for a request without the session cookie, except to issue a
 *   challenge: Such a request cannot hold one, and a session for it would create server state
 *   for every cookieless request.
 * - Check cfg values once, in init(), and report a wrong one with CaptchaConfigException.
 * - Reach the captchaImage service only through images(), so a missing one is reported with
 *   CaptchaConfigException.
 *
 * Typical usage:
 *   // The page with the form: Both calls refer to the same challenge.
 *   $field = $this->app->captcha->htmlField();
 *   $imagePath = $this->app->captcha->imagePath();
 *
 *   // The form's action:
 *   if (!$this->app->captcha->verify()) {
 *       // Wrong, expired or missing: Show the form again with a new challenge.
 *   }
 */
class Captcha extends BaseService {

	/** Pattern of a challenge id: 8 random bytes as 16 lowercase hex characters. */
	private const ID_PATTERN = '/^[0-9a-f]{16}$/D';

	/** Whether captcha protection is on (cfg security.captcha_protection). */
	private bool $enabled;

	/** Session key holding the pending challenges. */
	private string $sessionKey;

	/** Seconds a challenge stays valid. */
	private int $ttl;

	/** Maximum number of pending challenges per session. */
	private int $maxPending;

	/** POST field carrying the challenge id. */
	private string $idField;

	/** POST field carrying the answer. */
	private string $answerField;

	/** App path of the challenge image, without query. */
	private string $imagePath;

	/** Session cookie name: cfg session.name, or PHP's session.name setting when cfg has no non-empty string. */
	private string $cookieName;

	/** Challenge issued by current() during this request. */
	private ?string $current = null;


	/**
	 * Read and check the captcha cfg once.
	 *
	 * Behavior:
	 * - Requires, under cfg security: captcha_protection a bool; captcha.session_key,
	 *   captcha.id_field and captcha.answer_field non-empty strings, the two fields different;
	 *   captcha.ttl and captcha.max_pending ints >= 1; captcha.image_path an app path that
	 *   starts with "/" and has no query, fragment or whitespace.
	 * - Touches no session, request or image service.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When a value breaks these requirements.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
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
	 * Check whether captcha protection is on.
	 *
	 * @return bool  Value of cfg security.captcha_protection.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}


	/**
	 * Issue a new challenge and return its id.
	 *
	 * Stores a new code from the captchaImage service with a random render seed and an expiry
	 * among the session's pending challenges.
	 *
	 * Behavior:
	 * - The challenge stays valid for cfg security.captcha.ttl seconds.
	 * - Drops expired challenges, then the oldest beyond cfg security.captcha.max_pending.
	 * - Starts the session when none is active.
	 * - Issues whether or not captcha protection is on.
	 *
	 * Notes:
	 * - A session can only start while headers can still be sent: Call it before output, or
	 *   rely on output buffering.
	 *
	 * Typical usage:
	 *   $id = $this->app->captcha->issue();
	 *
	 * @return string  Challenge id: 16 lowercase hex characters.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the captchaImage service is not registered; the session is then left untouched.
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
	 * Behavior:
	 * - Every later call on the same instance returns the same id, so the hidden field and the
	 *   image path of one page refer to one challenge.
	 *
	 * @return string  Challenge id.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the first call finds no captchaImage service.
	 * @see \CitOmni\Http\Service\Captcha::issue()  What issuing stores.
	 */
	public function current(): string {
		return $this->current ??= $this->issue();
	}


	/**
	 * Return a hidden input that carries this request's challenge id.
	 *
	 * Behavior:
	 * - The field name is cfg security.captcha.id_field. Name and value are HTML-escaped, so
	 *   the element can be output without further escaping.
	 *
	 * @return string  A complete hidden input element.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the captchaImage service is not registered.
	 * @see \CitOmni\Http\Service\Captcha::current()  Which challenge the id belongs to.
	 */
	public function htmlField(): string {
		return '<input type="hidden" name="' . self::escape($this->idField) . '" value="' . self::escape($this->current()) . '">';
	}


	/**
	 * Return the app path of this request's challenge image.
	 *
	 * @return string  Cfg security.captcha.image_path with "?id=" and the challenge id.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the captchaImage service is not registered.
	 * @see \CitOmni\Http\Service\Captcha::current()  Which challenge the id belongs to.
	 */
	public function imagePath(): string {
		return $this->imagePath . '?id=' . $this->current();
	}


	/**
	 * Render the picture of a pending challenge.
	 *
	 * Behavior:
	 * - Renders with the challenge's stored seed, so every call for one challenge returns the
	 *   same picture and a reload gives no second distortion to compare.
	 * - Returns null when the id is not a string, is malformed, unknown or expired, and when
	 *   no session is active and the request carries no session cookie; no session is started
	 *   then.
	 * - Never consumes the challenge and never writes the session.
	 *
	 * Typical usage:
	 *   $png = $this->app->captcha->image($this->app->request->get('id'));
	 *
	 * @param  mixed  $id  Challenge id from the request; untrusted.
	 * @return array{data:string,format:string,mime:string,width:int,height:int,bytes:int}|null  The picture, or null.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the id names a pending challenge but the captchaImage service is not registered.
	 * @see \CitOmni\Image\Service\CaptchaImage::render()  The picture and the exceptions of rendering it.
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
	 * - Returns true without reading the request when captcha protection is off.
	 * - Reads the id and the answer from the POST fields that cfg security.captcha.id_field
	 *   and answer_field name; a field that is missing or not a string counts as empty.
	 *
	 * Typical usage:
	 *   if (!$this->app->captcha->verify()) {
	 *       $this->app->flash->error('The code was wrong. Try the new one.');
	 *   }
	 *
	 * @return bool  True when protection is off, or the answer matches the pending challenge.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the id names a pending challenge but the captchaImage service is not registered.
	 * @see \CitOmni\Http\Service\Captcha::verifyAnswer()  How the answer is checked and the challenge used up.
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
	 * Verify an answer for a challenge id and use the challenge up.
	 *
	 * Each challenge gets exactly one attempt, right or wrong, so a solved or guessed picture
	 * cannot be replayed.
	 *
	 * Behavior:
	 * - Returns true without reading the session when captcha protection is off.
	 * - Returns false without starting a session when none is active and the request carries
	 *   no session cookie.
	 * - Otherwise removes the challenge from the session before comparing, and drops expired
	 *   challenges in the same write; the session key is removed when nothing is left pending.
	 * - Returns false for an unknown or expired id.
	 *
	 * Typical usage:
	 *   $ok = $this->app->captcha->verifyAnswer($payload['captcha_id'], $payload['captcha']);
	 *
	 * @param  string  $id  Challenge id from the request.
	 * @param  string  $answer  Answer from the request.
	 * @return bool  True when protection is off, or the answer matches the pending challenge.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the id names a pending challenge but the captchaImage service is not registered; the challenge is used up all the same.
	 * @see \CitOmni\Image\Service\CaptchaImage::verify()  How the answer is compared with the code.
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
	 * Return the pending, unexpired challenges from the session, oldest first.
	 *
	 * Behavior:
	 * - Never creates a session.
	 * - Leaves out entries without the expected shape, like expired ones.
	 *
	 * Notes:
	 * - The state is short-lived, so an entry in a format from another version costs one
	 *   failed challenge, not an error page.
	 *
	 * @return list<array{0:string,1:string,2:int,3:int}>  Id, code, render seed, expiry as Unix time.
	 * @see \CitOmni\Http\Service\Session::get()  When a read resumes a session.
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
	 * Check whether the request can belong to a session.
	 *
	 * @return bool  True when a session is active or the request carries the session cookie.
	 */
	private function hasSession(): bool {
		return $this->app->session->isActive() || $this->app->request->cookie($this->cookieName) !== null;
	}


	/**
	 * Return the captchaImage service.
	 *
	 * @return object  The service registered as captchaImage.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When no captchaImage service is registered.
	 */
	private function images(): object {
		if (!$this->app->hasService('captchaImage')) {
			throw new CaptchaConfigException('Captcha challenges need the captchaImage service: install citomni/image and add \CitOmni\Image\Boot\Registry to /config/providers.php.');
		}

		return $this->app->captchaImage;
	}


	/**
	 * Escape a value for a double- or single-quoted HTML attribute.
	 *
	 * @param  string  $value  UTF-8 text; invalid sequences become U+FFFD.
	 * @return string
	 */
	private static function escape(string $value): string {
		return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
	}


	/**
	 * Check a cfg value under security.captcha that must be a non-empty string.
	 *
	 * @param  mixed  $value  Raw cfg value.
	 * @param  string  $key  Key below security.captcha, for the message.
	 * @return string  The value, unchanged.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the value is not a non-empty string.
	 */
	private static function cfgString(mixed $value, string $key): string {
		if (!\is_string($value) || $value === '') {
			throw new CaptchaConfigException('Config security.captcha.' . $key . ' must be a non-empty string.');
		}

		return $value;
	}


	/**
	 * Check a cfg value under security.captcha that must be an int >= 1.
	 *
	 * @param  mixed  $value  Raw cfg value.
	 * @param  string  $key  Key below security.captcha, for the message.
	 * @return int  The value, unchanged.
	 * @throws \CitOmni\Http\Exception\CaptchaConfigException  When the value is not an int >= 1.
	 */
	private static function cfgPositiveInt(mixed $value, string $key): int {
		if (!\is_int($value) || $value < 1) {
			throw new CaptchaConfigException('Config security.captcha.' . $key . ' must be an int >= 1.');
		}

		return $value;
	}


}
