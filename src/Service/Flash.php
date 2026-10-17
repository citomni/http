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
 * Flash: Service that carries messages, old input and field errors across one redirect.
 *
 * Carries what a form post leaves for the page it redirects to (Post/Redirect/Get): Message
 * buckets by name, the old input and field errors. The data stays in the session until a read
 * clears it. Storage, and starting or resuming the session with its failures, belong to
 * Session; what a message says, and how it is shown, belong to the callers.
 *
 * Rules:
 * - Reach session state only through the Session service, under the KEY_* keys.
 * - Create no session on a read or on a call that changes nothing: Only a call that stores
 *   something may create one.
 * - Cap the keys of every bag by MAX_MSG_KEYS or MAX_OLD_KEYS, and throw when a call would
 *   exceed the cap.
 *
 * Typical usage:
 *   // POST: Store, then redirect.
 *   $this->app->flash->error('Invalid login.');
 *   $this->app->flash->old(['username' => $username]);
 *   $this->app->response->redirect('/login.html', 303);
 *
 *   // GET: Read and clear.
 *   $flash = $this->app->flash->pullAll();
 */
class Flash extends BaseService {
	
	/** Session key of the message buckets, by bucket name. */
	private const KEY_MSG  = '_flash.msg';

	/** Session key of the old input, by field name. */
	private const KEY_OLD  = '_flash.old';

	/** Session key of the field errors: A list of messages by field name. */
	private const KEY_ERR  = '_flash.err';

	/** Session key of the flag that makes the next pullAll() keep the bags. */
	private const KEY_KEEP = '_flash.keep';


	/** Maximum number of message buckets. */
	private const MAX_MSG_KEYS      = 32;

	/** Maximum number of messages kept in one list: A bucket that add() grows, or the errors of one field. */
	private const MAX_MSG_LIST_SIZE = 16;

	/** Maximum number of fields in the old input, and of fields in the field errors. */
	private const MAX_OLD_KEYS      = 64;

	/** Maximum length in bytes of a message or field error that is given as a string. */
	private const MAX_MSG_STRLEN    = 2048;


	/**
	 * Set up nothing: Resolving the service must not touch the session.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
	}





	// ----------------------------------------------------------------
	// Message API
	// ----------------------------------------------------------------

	/**
	 * Store a message bucket, replacing any payload under the same name.
	 *
	 * add() appends to a bucket instead.
	 *
	 * Behavior:
	 * - A string payload is cut to MAX_MSG_STRLEN bytes; an array payload is stored as given.
	 *
	 * Typical usage:
	 *   $this->app->flash->set('error', 'Invalid credentials.');
	 *
	 * @param  string  $key  Bucket name, such as success or error.
	 * @param  string|array<mixed>  $message
	 * @return void
	 * @throws \RuntimeException  When the bucket is new and MAX_MSG_KEYS buckets exist.
	 * @see \CitOmni\Http\Service\Flash::trimString()  How a string is cut.
	 */
	public function set(string $key, string|array $message): void {
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (!\array_key_exists($key, $msg) && \count($msg) >= self::MAX_MSG_KEYS) {
			throw new \RuntimeException('Flash message key cap reached (' . self::MAX_MSG_KEYS . ').');
		}

		$msg[$key] = \is_string($message) ? $this->trimString($message) : $message;
		$this->app->session->set(self::KEY_MSG, $msg);
	}


	/**
	 * Append a message to a bucket, keeping its newest MAX_MSG_LIST_SIZE items.
	 *
	 * A new bucket becomes a one-item list, a string bucket becomes a list with the string first,
	 * and an array bucket gets the message appended. On overflow the oldest items are dropped.
	 *
	 * Behavior:
	 * - A string message is cut to MAX_MSG_STRLEN bytes; an array message is appended as one item.
	 *
	 * Typical usage:
	 *   $this->app->flash->add('info', 'Step 1 done.');
	 *
	 * @param  string  $key  Bucket name.
	 * @param  string|array<mixed>  $message
	 * @return void
	 * @throws \RuntimeException  When the bucket is new and MAX_MSG_KEYS buckets exist, or the bucket holds neither a string nor an array.
	 */
	public function add(string $key, string|array $message): void {
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (!\array_key_exists($key, $msg) && \count($msg) >= self::MAX_MSG_KEYS) {
			throw new \RuntimeException('Flash message key cap reached (' . self::MAX_MSG_KEYS . ').');
		}

		$item = \is_string($message) ? $this->trimString($message) : $message;

		if (!\array_key_exists($key, $msg)) {
			$msg[$key] = [$item];
		} else {
			$cur = $msg[$key];

			// set() may have stored a string; it becomes the first item.
			if (\is_string($cur)) {
				$cur = [$cur];
			} elseif (!\is_array($cur)) {
				throw new \RuntimeException('Flash bucket must be string or array.');
			}

			$cur[] = $item;
			$excess = \count($cur) - self::MAX_MSG_LIST_SIZE;
			if ($excess > 0) {
				$cur = \array_slice($cur, $excess);
			}
			$msg[$key] = $cur;
		}

		$this->app->session->set(self::KEY_MSG, $msg);
	}


	/**
	 * Append a message to the success bucket.
	 *
	 * A shorthand for add('success', $message).
	 *
	 * @param  string  $message
	 * @return void
	 * @throws \RuntimeException  When add() refuses the message.
	 * @see \CitOmni\Http\Service\Flash::add()  How the bucket grows.
	 */
	public function success(string $message): void { $this->add('success', $message); }
	
	/**
	 * Append a message to the info bucket.
	 *
	 * A shorthand for add('info', $message).
	 *
	 * @param  string  $message
	 * @return void
	 * @throws \RuntimeException  When add() refuses the message.
	 * @see \CitOmni\Http\Service\Flash::add()  How the bucket grows.
	 */
	public function info(string $message): void { $this->add('info', $message); }
	
	/**
	 * Append a message to the warning bucket.
	 *
	 * A shorthand for add('warning', $message).
	 *
	 * @param  string  $message
	 * @return void
	 * @throws \RuntimeException  When add() refuses the message.
	 * @see \CitOmni\Http\Service\Flash::add()  How the bucket grows.
	 */
	public function warning(string $message): void { $this->add('warning', $message); }
	
	/**
	 * Append a message to the error bucket.
	 *
	 * A shorthand for add('error', $message).
	 *
	 * @param  string  $message
	 * @return void
	 * @throws \RuntimeException  When add() refuses the message.
	 * @see \CitOmni\Http\Service\Flash::add()  How the bucket grows.
	 */
	public function error(string $message): void { $this->add('error', $message); }






	// ----------------------------------------------------------------
	// Old input and field errors
	// ----------------------------------------------------------------

	/**
	 * Store old input for the next request, merged over the old input already stored.
	 *
	 * A new value replaces the stored value of the same field; other fields stay. Values are
	 * stored as given.
	 *
	 * Behavior:
	 * - An empty $fields changes nothing.
	 *
	 * Typical usage:
	 *   $this->app->flash->old($this->app->request->only(['username', 'email']));
	 *
	 * @param  array<string,mixed>  $fields  Values by field name.
	 * @return void
	 * @throws \RuntimeException  When the old input would hold more than MAX_OLD_KEYS fields.
	 */
	public function old(array $fields): void {
		if ($fields === []) {
			return;
		}

		$cur = (array)$this->app->session->get(self::KEY_OLD);
		$newKeys = \array_diff(\array_keys($fields), \array_keys($cur));

		if ((\count($cur) + \count($newKeys)) > self::MAX_OLD_KEYS) {
			throw new \RuntimeException('Flash old-input key cap reached (' . self::MAX_OLD_KEYS . ').');
		}

		// Left-biased merge: new overrides existing keys.
		$this->app->session->set(self::KEY_OLD, $fields + $cur);
	}


	/**
	 * Store field errors for the next request, replacing the stored errors of the same fields.
	 *
	 * Errors of other fields stay. Each field gets a list of messages.
	 *
	 * Behavior:
	 * - A field name is trimmed of whitespace; an empty name is skipped.
	 * - The messages of a field are a string or the strings of an array, each cut to
	 *   MAX_MSG_STRLEN bytes. Empty strings and other values are dropped, and the newest
	 *   MAX_MSG_LIST_SIZE stay. A field without a message left is skipped.
	 * - Changes nothing when no field is left.
	 *
	 * Typical usage:
	 *   $this->app->flash->fieldErrors(['email' => 'Email address is invalid.']);
	 *
	 * @param  array<string|int,mixed>  $errors  Messages by field name: A string or a list of strings.
	 * @return void
	 * @throws \RuntimeException  When the field errors would hold more than MAX_OLD_KEYS fields.
	 */
	public function fieldErrors(array $errors): void {
		if ($errors === []) {
			return;
		}


		// -- 1. Normalize the field errors -------------------------------
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


		// -- 2. Check the cap and store ----------------------------------
		$newKeys = \array_diff(\array_keys($normalized), \array_keys($cur));
		if ((\count($cur) + \count($newKeys)) > self::MAX_OLD_KEYS) {
			throw new \RuntimeException('Flash field-error key cap reached (' . self::MAX_OLD_KEYS . ').');
		}

		$this->app->session->set(self::KEY_ERR, $normalized + $cur);
	}


	/**
	 * Return one old-input value without removing it.
	 *
	 * @param  string  $key  Field name.
	 * @param  mixed  $default  Returned when the field is absent or its value is null.
	 * @return mixed
	 */
	public function oldValue(string $key, mixed $default = null): mixed {
		$cur = (array)$this->app->session->get(self::KEY_OLD);
		return $cur[$key] ?? $default;
	}


	/**
	 * Check whether the old input has a field, also when its value is null.
	 *
	 * @param  string  $key  Field name.
	 * @return bool
	 */
	public function hasOld(string $key): bool {
		$cur = (array)$this->app->session->get(self::KEY_OLD);
		return \array_key_exists($key, $cur);
	}




	// ----------------------------------------------------------------
	// Read and clear
	// ----------------------------------------------------------------

	/**
	 * Return one message bucket and remove it.
	 *
	 * Removes only this bucket; the other buckets and bags stay.
	 *
	 * @param  string  $key  Bucket name.
	 * @return string|array<mixed>|null  The payload, or null when the bucket is absent.
	 */
	public function take(string $key): string|array|null {
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
	 * Return one message bucket without removing it.
	 *
	 * @param  string  $key  Bucket name.
	 * @return string|array<mixed>|null  The payload, or null when the bucket is absent.
	 */
	public function peek(string $key): string|array|null {
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		return $msg[$key] ?? null;
	}


	/**
	 * Return all bags without clearing them.
	 *
	 * @return array{msg:array<string,string|array<mixed>>,old:array<string,mixed>,err:array<string,list<string>>}
	 */
	public function peekAll(): array {
		return [
			'msg' => (array)$this->app->session->get(self::KEY_MSG),
			'old' => (array)$this->app->session->get(self::KEY_OLD),
			'err' => (array)$this->app->session->get(self::KEY_ERR),
		];
	}


	/**
	 * Return all bags and clear them, unless keep() asked to keep them once.
	 *
	 * The read side of Post/Redirect/Get: Clearing what it returns keeps a reload of the page from
	 * showing the same data again.
	 *
	 * Behavior:
	 * - Without the keep flag, removes every bag and the flag.
	 * - With the keep flag, removes only the flag, so the bags stay for the next pullAll().
	 *
	 * Typical usage:
	 *   $flash = $this->app->flash->pullAll();
	 *
	 * @return array{msg:array<string,string|array<mixed>>,old:array<string,mixed>,err:array<string,list<string>>}
	 */
	public function pullAll(): array {
		$msg  = (array)$this->app->session->get(self::KEY_MSG);
		$old  = (array)$this->app->session->get(self::KEY_OLD);
		$err  = (array)$this->app->session->get(self::KEY_ERR);
		$keep = (bool)$this->app->session->get(self::KEY_KEEP);

		$out = [
			'msg' => $msg,
			'old' => $old,
			'err' => $err,
		];

		// The bags stay in the session as they are; only the flag is consumed.
		if ($keep) {
			$this->app->session->remove(self::KEY_KEEP);
			return $out;
		}

		$this->app->session->remove(self::KEY_MSG);
		$this->app->session->remove(self::KEY_OLD);
		$this->app->session->remove(self::KEY_ERR);
		$this->app->session->remove(self::KEY_KEEP);

		return $out;
	}


	/**
	 * Make the next pullAll() keep the bags, or remove that request.
	 *
	 * The flag is single-shot: The next pullAll() consumes it and keeps the bags, and the one after
	 * clears them.
	 *
	 * Typical usage:
	 *   $this->app->flash->keep();
	 *
	 * @param  bool  $enable  False removes the flag.
	 * @return void
	 */
	public function keep(bool $enable = true): void {
		if ($enable) {
			$this->app->session->set(self::KEY_KEEP, true);
		} else {
			$this->app->session->remove(self::KEY_KEEP);
		}
	}


	/**
	 * Remove every bag and the keep flag.
	 *
	 * Discards all flash data, also data that an earlier request stored for this one.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->app->session->remove(self::KEY_MSG);
		$this->app->session->remove(self::KEY_OLD);
		$this->app->session->remove(self::KEY_ERR);
		$this->app->session->remove(self::KEY_KEEP);
	}


	/**
	 * Remove one message bucket.
	 *
	 * Changes nothing when the bucket is absent.
	 *
	 * @param  string  $key  Bucket name.
	 * @return void
	 */
	public function forgetMsg(string $key): void {
		$msg = (array)$this->app->session->get(self::KEY_MSG);
		if (\array_key_exists($key, $msg)) {
			unset($msg[$key]);
			$this->app->session->set(self::KEY_MSG, $msg);
		}
	}


	/**
	 * Remove fields from the old input.
	 *
	 * Absent fields are ignored, and the old input is stored again only when a field was removed.
	 *
	 * @param  list<string>  $keys  Field names.
	 * @return void
	 */
	public function forgetOld(array $keys): void {
		if ($keys === []) {
			return;
		}

		$old = (array)$this->app->session->get(self::KEY_OLD);
		$count = \count($old);
		foreach ($keys as $k) {
			unset($old[$k]);
		}
		if (\count($old) !== $count) {
			$this->app->session->set(self::KEY_OLD, $old);
		}
	}






	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize the errors of one field to a list of non-empty strings.
	 *
	 * Behavior:
	 * - A string becomes a one-item list. An array keeps its strings in order and drops other
	 *   values. Any other value gives an empty list.
	 * - Each string is cut to MAX_MSG_STRLEN bytes; empty strings are dropped.
	 * - Keeps the newest MAX_MSG_LIST_SIZE items.
	 *
	 * @param  mixed  $messages  A string or an array of messages.
	 * @return list<string>
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
	 * Cut a string to at most MAX_MSG_STRLEN bytes without ending inside a UTF-8 sequence.
	 *
	 * Behavior:
	 * - Returns a string within the limit unchanged, whether or not it is valid UTF-8.
	 * - Cuts a longer string to the longest prefix within the limit that is valid UTF-8. When an
	 *   invalid byte comes before the limit, the result ends before it and may be empty.
	 *
	 * @param  string  $s
	 * @return string
	 */
	private function trimString(string $s): string {
		if (\strlen($s) <= self::MAX_MSG_STRLEN) {
			return $s;
		}

		$cut = \substr($s, 0, self::MAX_MSG_STRLEN);

		// Drop bytes from the end until $cut is valid UTF-8.
		if (!\preg_match('//u', $cut)) {
			while ($cut !== '' && !\preg_match('//u', $cut)) {
				$cut = \substr($cut, 0, -1);
			}
		}

		return $cut;
	}

}
