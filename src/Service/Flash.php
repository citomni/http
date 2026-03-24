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
 * Flash: Tiny one-request session flash bag for PRG flows.
 *
 * Provides deterministic, low-overhead flash storage across a single redirect
 * boundary (POST -> Redirect -> GET). Uses the Session service exclusively and
 * keeps init() side-effect free for predictable boot cost.
 *
 * Data lives in Session and survives exactly one redirect boundary unless
 * you call keep(). Designed for predictable UX after validation failures,
 * login attempts, etc.
 *
 * Behavior:
 * - Session keys:
 *   1) _flash.msg  : array<string, string|array>              (message buckets by key)
 *   2) _flash.old  : array<string, mixed>                     (old input bag)
 *   3) _flash.err  : array<string, array<int, string>>        (field error bag)
 *   4) _flash.keep : bool                                     (single-shot keep flag)
 * - Limits (fail fast):
 *   1) MAX_MSG_KEYS      : max distinct message buckets
 *   2) MAX_MSG_LIST_SIZE : max list size per bucket or field error list (append; evict oldest)
 *   3) MAX_OLD_KEYS      : max distinct old-input keys and field-error keys
 *   4) MAX_MSG_STRLEN    : byte cap for string payloads (UTF-8 safe trim)
 * - Read semantics:
 *   1) take($key) clears only that message bucket
 *   2) pullAll() clears all bags unless keep() has been set
 *
 * Notes:
 * - Messages can be either strings or arrays; arrays are accepted as-is.
 * - Field errors are stored as array<string, list<string>>.
 * - All interaction with session state goes through the Session service API.
 * - No catch-all exception handling; failures bubble to global ErrorHandler.
 *
 * Validation notes:
 * - add() appends to a bucket and auto-promotes single strings to list form.
 * - fieldErrors() normalizes single strings to one-item lists, trims strings,
 *   skips empty field names, and discards unusable values.
 * - error()/success()/info()/warning() are convenience wrappers around add().
 *
 * Safety / limits:
 * - Hard caps on number of buckets, number of items per bucket, and byte length
 *   of each string payload (UTF-8 safe trim).
 * - Old input and field errors are capped on number of distinct keys.
 *
 * Lifecycle:
 * - pullAll():
 *     returns ['msg'=>..., 'old'=>..., 'err'=>...]
 *     clears all bags, unless keep() had been set (then it just consumes keep()).
 * - keep():
 *     tells Flash "do not clear on the next pullAll(); clear on the one after".
 *
 * Zero coupling:
 * - This service does not depend on $app->cfg or $app->routes.
 * - Only depends on $app->session.
 *
 * Typical usage:
 *   // POST handler
 *   $this->app->flash->error('Invalid login');
 *   $this->app->flash->old(['username' => 'alice']);
 *   $this->app->flash->fieldErrors([
 *   	'username' => ['Username is required.'],
 *   ]);
 *   $this->app->response->redirect('login.html');
 *
 *   // GET page
 *   $flash = $this->app->flash->pullAll(); // read+clear (unless keep() set)
 *   $error = $flash['msg']['error'] ?? null;
 *   $old   = $flash['old'] ?? [];
 *   $err   = $flash['err'] ?? [];
 */
final class Flash extends BaseService {
	
	/** @var string Session key for message buckets. */
	private const KEY_MSG  = '_flash.msg';

	/** @var string Session key for old-input bag. */
	private const KEY_OLD  = '_flash.old';

	/** @var string Session key for field-error bag. */
	private const KEY_ERR  = '_flash.err';

	/** @var string Session key for single-shot keep flag. */
	private const KEY_KEEP = '_flash.keep';


	/** @var int Max distinct message buckets allowed. */
	private const MAX_MSG_KEYS      = 32;

	/** @var int Max list size per bucket or field-error list (oldest evicted on overflow). */
	private const MAX_MSG_LIST_SIZE = 16;

	/** @var int Max distinct keys in old-input bag and field-error bag. */
	private const MAX_OLD_KEYS      = 64;

	/** @var int Max bytes for string message payload (UTF-8 safe). */
	private const MAX_MSG_STRLEN    = 2048;


	/**
	 * One-time initialization. Intentionally side-effect free.
	 *
	 * Behavior:
	 * - Does not start the session.
	 * - Does not pre-create bags; they are created lazily on first use.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Intentionally empty; keep resolution cheap and deterministic.
	}





	// ----------------------------------------------------------------
	// Message API
	// ----------------------------------------------------------------

	/**
	 * Set/replace a message bucket with a string or array payload.
	 *
	 * Typical usage:
	 *   $this->app->flash->set('error', 'Invalid credentials');
	 *
	 * Failure:
	 * - Throws \RuntimeException if MAX_MSG_KEYS would be exceeded.
	 *
	 * @param string              $key Logical bucket (e.g., 'success', 'error').
	 * @param string|array<mixed> $message String or structured payload.
	 * @return void
	 *
	 * @throws \RuntimeException If message key cap is reached.
	 */
	public function set(string $key, string|array $message): void {
		$this->ensureBags();

		// Retrieve current buckets; enforce key cap on new bucket creation.
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (!\array_key_exists($key, $msg) && \count($msg) >= self::MAX_MSG_KEYS) {
			throw new \RuntimeException('Flash message key cap reached (' . self::MAX_MSG_KEYS . ').');
		}

		// Store string (trimmed by byte cap) or array as-is.
		$msg[$key] = \is_string($message) ? $this->trimString($message) : $message;
		$this->app->session->set(self::KEY_MSG, $msg);
	}


	/**
	 * Append a message to a bucket (promotes string bucket to list).
	 * Oldest elements are evicted on overflow.
	 *
	 * Typical usage:
	 *   $this->app->flash->add('info', 'Step 1 done');
	 *   $this->app->flash->add('info', 'Step 2 done');
	 *
	 * Failure:
	 * - Throws \RuntimeException if MAX_MSG_KEYS would be exceeded.
	 * - Throws \RuntimeException if existing bucket is neither string nor array.
	 *
	 * @param string              $key
	 * @param string|array<mixed> $message
	 * @return void
	 *
	 * @throws \RuntimeException On key cap or invalid bucket type.
	 */
	public function add(string $key, string|array $message): void {
		$this->ensureBags();

		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (!\array_key_exists($key, $msg) && \count($msg) >= self::MAX_MSG_KEYS) {
			throw new \RuntimeException('Flash message key cap reached (' . self::MAX_MSG_KEYS . ').');
		}

		$item = \is_string($message) ? $this->trimString($message) : $message;

		if (!\array_key_exists($key, $msg)) {
			// First item creates a list bucket.
			$msg[$key] = [$item];
		} else {
			$cur = $msg[$key];

			// Promote string bucket to list; validate custom payload types.
			if (\is_string($cur)) {
				$cur = [$cur];
			} elseif (!\is_array($cur)) {
				throw new \RuntimeException('Flash bucket must be string or array.');
			}

			// Append, then evict oldest items on overflow.
			$cur[] = $item;
			$excess = \count($cur) - self::MAX_MSG_LIST_SIZE;
			if ($excess > 0) {
				// Keep the newest MAX_MSG_LIST_SIZE items.
				$cur = \array_slice($cur, $excess);
			}
			$msg[$key] = $cur;
		}

		$this->app->session->set(self::KEY_MSG, $msg);
	}


	/** @return void */
	public function success(string $message): void { $this->add('success', $message); }
	
	/** @return void */
	public function info(string $message): void { $this->add('info', $message); }
	
	/** @return void */
	public function warning(string $message): void { $this->add('warning', $message); }
	
	/** @return void */
	public function error(string $message): void { $this->add('error', $message); }






	// ----------------------------------------------------------------
	// Old input and field errors
	// ----------------------------------------------------------------

	/**
	 * Merge "old input" for the next request (left-biased: new overrides).
	 *
	 * Typical usage:
	 *   $this->app->flash->old($this->app->request->only(['username', 'email']));
	 *
	 * Failure:
	 * - Throws \RuntimeException if MAX_OLD_KEYS would be exceeded.
	 *
	 * @param array<string, mixed> $fields Key-value pairs to persist for next request.
	 * @return void
	 *
	 * @throws \RuntimeException If old-input key cap would be exceeded.
	 */
	public function old(array $fields): void {
		$this->ensureBags();

		$cur = (array)$this->app->session->get(self::KEY_OLD);
		$newKeys = \array_diff(\array_keys($fields), \array_keys($cur));

		if ((\count($cur) + \count($newKeys)) > self::MAX_OLD_KEYS) {
			throw new \RuntimeException('Flash old-input key cap reached (' . self::MAX_OLD_KEYS . ').');
		}

		// Left-biased merge: new overrides existing keys.
		$this->app->session->set(self::KEY_OLD, $fields + $cur);
	}


	/**
	 * Store field-specific validation errors for the next request.
	 *
	 * Behavior:
	 * - Accepts field => string|array shapes.
	 * - Normalizes a single string to a one-item list.
	 * - Trims all stored strings by MAX_MSG_STRLEN.
	 * - Skips empty field names and unusable values.
	 * - Replaces existing entries for the same field.
	 *
	 * Typical usage:
	 *   $this->app->flash->fieldErrors([
	 *   	'email' => ['Email address is invalid.'],
	 *   	'password' => [
	 *   		'Password must be at least 12 characters long.',
	 *   		'Password must contain a non-space character.',
	 *   	],
	 *   ]);
	 *
	 * Failure:
	 * - Throws \RuntimeException if MAX_OLD_KEYS would be exceeded.
	 *
	 * @param array<string|int, mixed> $errors Field error map.
	 * @return void
	 *
	 * @throws \RuntimeException If field-error key cap would be exceeded.
	 */
	public function fieldErrors(array $errors): void {
		$this->ensureBags();


		// -- 1. Fast-exit on empty input --------------------------------
		if ($errors === []) {
			return;
		}


		// -- 2. Normalize incoming field errors -------------------------
		$cur = (array)$this->app->session->get(self::KEY_ERR);
		$normalized = [];

		foreach ($errors as $field => $messages) {
			$field = \trim((string)$field);
			if ($field === '') {
				continue;
			}

			$list = $this->normalizeFieldErrorMessages($messages);
			if ($list === []) {
				continue;
			}

			$normalized[$field] = $list;
		}

		if ($normalized === []) {
			return;
		}


		// -- 3. Enforce caps and persist --------------------------------
		$newKeys = \array_diff(\array_keys($normalized), \array_keys($cur));
		if ((\count($cur) + \count($newKeys)) > self::MAX_OLD_KEYS) {
			throw new \RuntimeException('Flash field-error key cap reached (' . self::MAX_OLD_KEYS . ').');
		}

		$this->app->session->set(self::KEY_ERR, $normalized + $cur);
	}


	/**
	 * Read a single old-input value without clearing.
	 *
	 * @param string     $key
	 * @param mixed|null $default Returned when key is absent.
	 * @return mixed
	 */
	public function oldValue(string $key, mixed $default = null): mixed {
		$this->ensureBags();
		$cur = (array)$this->app->session->get(self::KEY_OLD);
		return $cur[$key] ?? $default;
	}


	/**
	 * Check presence of an old-input key.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function hasOld(string $key): bool {
		$this->ensureBags();
		$cur = (array)$this->app->session->get(self::KEY_OLD);
		return \array_key_exists($key, $cur);
	}


	/**
	 * Read and clear a single message bucket.
	 *
	 * @param string $key
	 * @return string|array<mixed>|null Message payload, or null if absent.
	 */
	public function take(string $key): string|array|null {
		$this->ensureBags();

		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (!\array_key_exists($key, $msg)) {
			return null;
		}

		$val = $msg[$key];
		unset($msg[$key]);
		$this->app->session->set(self::KEY_MSG, $msg);
		return $val;
	}


	/**
	 * Read a single message bucket without clearing.
	 *
	 * @param string $key
	 * @return string|array<mixed>|null
	 */
	public function peek(string $key): string|array|null {
		$this->ensureBags();
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		return $msg[$key] ?? null;
	}


	/**
	 * Peek all bags without clearing.
	 *
	 * @return array{
	 * 	msg: array<string, string|array<mixed>>,
	 * 	old: array<string, mixed>,
	 * 	err: array<string, array<int, string>>
	 * }
	 */
	public function peekAll(): array {
		$this->ensureBags();

		return [
			'msg' => (array)$this->app->session->get(self::KEY_MSG),
			'old' => (array)$this->app->session->get(self::KEY_OLD),
			'err' => (array)$this->app->session->get(self::KEY_ERR),
		];
	}


	/**
	 * Pull (read) all and clear all bags unless keep() was set.
	 * If keep() was set prior to this call, bags are preserved and the keep
	 * flag is consumed (single-shot).
	 *
	 * @return array{
	 * 	msg: array<string, string|array<mixed>>,
	 * 	old: array<string, mixed>,
	 * 	err: array<string, array<int, string>>
	 * }
	 */
	public function pullAll(): array {

		$this->ensureBags();


		// -- 1. Load current flash state --------------------------------
		$msg  = (array)$this->app->session->get(self::KEY_MSG);
		$old  = (array)$this->app->session->get(self::KEY_OLD);
		$err  = (array)$this->app->session->get(self::KEY_ERR);
		$keep = (bool)$this->app->session->get(self::KEY_KEEP);

		$out = [
			'msg' => $msg,
			'old' => $old,
			'err' => $err,
		];

		// -- 2. Preserve once when keep() was requested -----------------
		if ($keep) {
			$this->app->session->set(self::KEY_MSG, $msg);
			$this->app->session->set(self::KEY_OLD, $old);
			$this->app->session->set(self::KEY_ERR, $err);
			$this->app->session->remove(self::KEY_KEEP);
			return $out;
		}

		// -- 3. Clear bags after normal pull ----------------------------
		$this->app->session->remove(self::KEY_MSG);
		$this->app->session->remove(self::KEY_OLD);
		$this->app->session->remove(self::KEY_ERR);
		$this->app->session->remove(self::KEY_KEEP);

		return $out;
	}


	/**
	 * Mark current flash data to persist across the next pullAll().
	 * Pass false to cancel a prior keep() call within the same request.
	 *
	 * Typical usage:
	 *   $this->app->flash->keep();      // persist once
	 *   $this->app->flash->keep(false); // cancel within same request
	 *
	 * @param bool $enable When true, enables keep; when false, clears it.
	 * @return void
	 */
	public function keep(bool $enable = true): void {
		$this->ensureBags();
		if ($enable) {
			$this->app->session->set(self::KEY_KEEP, true);
		} else {
			$this->app->session->remove(self::KEY_KEEP);
		}
	}


	/**
	 * Clear all bags and the keep flag.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->ensureBags();
		$this->app->session->remove(self::KEY_MSG);
		$this->app->session->remove(self::KEY_OLD);
		$this->app->session->remove(self::KEY_ERR);
		$this->app->session->remove(self::KEY_KEEP);
	}


	/**
	 * Remove a single message bucket (no-op if absent).
	 *
	 * @param string $key
	 * @return void
	 */
	public function forgetMsg(string $key): void {
		$this->ensureBags();

		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (\array_key_exists($key, $msg)) {
			unset($msg[$key]);
			$this->app->session->set(self::KEY_MSG, $msg);
		}
	}


	/**
	 * Remove one or more old-input keys (no-op for missing keys).
	 *
	 * @param array<int, string> $keys
	 * @return void
	 */
	public function forgetOld(array $keys): void {
		$this->ensureBags();

		if ($keys === []) {
			return;
		}

		$old = (array)$this->app->session->get(self::KEY_OLD);
		foreach ($keys as $k) {
			unset($old[$k]);
		}
		$this->app->session->set(self::KEY_OLD, $old);
	}






	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Ensure the session is active and required bags exist.
	 *
	 * Behavior:
	 * - Starts the session via Session service if not active.
	 * - Creates empty arrays for _flash.msg, _flash.old, and _flash.err when missing.
	 *
	 * @return void
	 */
	private function ensureBags(): void {
		if (!$this->app->session->isActive()) {
			$this->app->session->start();
		}
		if (!$this->app->session->has(self::KEY_MSG)) {
			$this->app->session->set(self::KEY_MSG, []);
		}
		if (!$this->app->session->has(self::KEY_OLD)) {
			$this->app->session->set(self::KEY_OLD, []);
		}
		if (!$this->app->session->has(self::KEY_ERR)) {
			$this->app->session->set(self::KEY_ERR, []);
		}
	}


	/**
	 * Normalize one field's error payload to a compact list of strings.
	 *
	 * Behavior:
	 * - Accepts a single string or an array of message-like values.
	 * - Trims strings by MAX_MSG_STRLEN.
	 * - Discards empty strings after trim.
	 * - Keeps only the newest MAX_MSG_LIST_SIZE items.
	 *
	 * @param mixed $messages Raw field error payload.
	 * @return array<int, string> Normalized list of messages.
	 */
	private function normalizeFieldErrorMessages(mixed $messages): array {
		if (\is_string($messages)) {
			$message = $this->trimString($messages);
			return $message === '' ? [] : [$message];
		}

		if (!\is_array($messages)) {
			return [];
		}

		$out = [];

		foreach ($messages as $message) {
			if (!\is_string($message)) {
				continue;
			}

			$message = $this->trimString($message);
			if ($message === '') {
				continue;
			}

			$out[] = $message;
		}

		$excess = \count($out) - self::MAX_MSG_LIST_SIZE;
		if ($excess > 0) {
			$out = \array_slice($out, $excess);
		}

		return $out;
	}


	/**
	 * UTF-8 safe tail-truncation by bytes. If the cut lands mid-sequence,
	 * back off to the last valid boundary to preserve valid UTF-8.
	 *
	 * @param string $s Input string to cap by bytes.
	 * @return string String not exceeding MAX_MSG_STRLEN bytes, valid UTF-8.
	 */
	private function trimString(string $s): string {
		if (\strlen($s) <= self::MAX_MSG_STRLEN) {
			return $s;
		}

		$cut = \substr($s, 0, self::MAX_MSG_STRLEN);

		// If $cut breaks a UTF-8 sequence, back off until it is valid UTF-8.
		if (!\preg_match('//u', $cut)) {
			while ($cut !== '' && !\preg_match('//u', $cut)) {
				$cut = \substr($cut, 0, -1);
			}
		}

		return $cut;
	}

}
