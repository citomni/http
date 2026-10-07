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

/*
 * Isolated suite for CitOmni\Http\Service\Cookie: reading untrusted cookie input.
 *
 * Usage:
 *   php tests/cookie/run.php
 *
 * Notes:
 * - Runs the real Cookie service against a minimal kernel double, without Composer.
 * - $_COOKIE is filled exactly as PHP's request parser fills it, e.g. the header
 *   "Cookie: _auth_rm[]=x" arrives as ['_auth_rm' => ['x']].
 */

namespace CitOmni\Kernel\Service {

	/** Double for the kernel base class: keeps the app and options, then calls init(). */
	abstract class BaseService {
		protected object $app;
		protected array $options;

		public function __construct(object $app, array $options = []) {
			$this->app = $app;
			$this->options = $options;
			if (\method_exists($this, 'init')) {
				$this->init();
			}
		}
	}
}

namespace CitOmni\Http\Tests\Cookie {

	/** Read-only cfg node with the kernel Cfg surface Cookie uses (isset and toArray). */
	final class Cfg {
		public function __construct(private array $data) {}

		public function __isset(string $key): bool {
			return \array_key_exists($key, $this->data);
		}

		public function __get(string $key): mixed {
			if (!\array_key_exists($key, $this->data)) {
				throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
			}
			$value = $this->data[$key];
			return \is_array($value) && !\array_is_list($value) ? new self($value) : $value;
		}

		public function toArray(): array {
			return $this->data;
		}
	}

	/** App double: cfg and hasService(). Like the kernel App, it has no __isset(). */
	final class App {
		public Cfg $cfg;

		public function __construct() {
			$this->cfg = new Cfg([
				'http'   => ['base_url' => 'http://127.0.0.1'],
				'cookie' => ['secure' => false, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/'],
			]);
		}

		public function hasService(string $id): bool {
			return false;
		}
	}
}

namespace CitOmni\Http\Tests\Cookie {

	use CitOmni\Http\Service\Cookie;

	if (\PHP_SAPI !== 'cli') {
		throw new \RuntimeException('CLI only.');
	}

	\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
		throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
	});

	require \dirname(__DIR__, 2) . '/src/Service/Cookie.php';

	$passed = 0;
	$failed = 0;

	function check(string $name, callable $test): void {
		global $passed, $failed;
		try {
			$test();
			$passed++;
			\fwrite(\STDOUT, "PASS {$name}\n");
		} catch (\Throwable $error) {
			$failed++;
			\fwrite(\STDERR, "FAIL {$name} - " . $error->getMessage() . "\n");
		}
	}

	function same(mixed $expected, mixed $actual): void {
		if ($expected !== $actual) {
			throw new \RuntimeException('Expected ' . \var_export($expected, true) . '; got ' . \var_export($actual, true));
		}
	}

	/** Fill $_COOKIE as the request parser would and build a fresh Cookie service. */
	function cookieFor(array $parsedCookies): Cookie {
		$_COOKIE = $parsedCookies;
		return new Cookie(new App());
	}

	check('Array-shaped input is absent for get(), which returns the default', function (): void {
		$cookie = cookieFor(['_auth_rm' => ['x']]);
		same(null, $cookie->get('_auth_rm'));
		same('fallback', $cookie->get('_auth_rm', 'fallback'));
	});

	check('Array-shaped input is absent for has()', function (): void {
		same(false, cookieFor(['_auth_rm' => ['x']])->has('_auth_rm'));
	});

	check('String values, including the empty string, are returned unchanged', function (): void {
		$cookie = cookieFor(['plain' => 'v', 'empty' => '']);
		same('v', $cookie->get('plain', 'fallback'));
		same('', $cookie->get('empty', 'fallback'));
		same(true, $cookie->has('plain'));
		same(true, $cookie->has('empty'));
	});

	check('An absent cookie yields the default and has() is false', function (): void {
		$cookie = cookieFor([]);
		same(null, $cookie->get('missing'));
		same('fallback', $cookie->get('missing', 'fallback'));
		same(false, $cookie->has('missing'));
	});

	\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
	exit($failed === 0 ? 0 : 1);
}
