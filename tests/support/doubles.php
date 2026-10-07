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
 * citomni/kernel doubles for the isolated suites. Not a suite of its own.
 *
 * Suites and built-in server routers require this file instead of a Composer
 * bootstrap. Each runs in its own process, so the real kernel classes are never
 * loaded next to these.
 *
 * - CitOmni\Kernel\Cfg mirrors the kernel's read semantics: unknown keys throw
 *   \OutOfBoundsException, isset() is true for every present key (also null),
 *   empty and associative arrays become nested nodes, lists stay arrays. Services
 *   test "instanceof Cfg" on list-valued keys, so the FQCN must be the kernel's.
 * - CitOmni\Kernel\Service\BaseService keeps the app and options, then calls init().
 * - CitOmni\Http\Tests\Support\App holds cfg, routes and the services a suite
 *   registers. Like the kernel App it resolves services through __get(), throws on
 *   unknown ids and has no __isset().
 * - mergeLastWins() layers cfg overrides on the package baseline the way the kernel
 *   merges cfg: associative arrays merge deeply, lists and scalars are replaced.
 */

namespace CitOmni\Kernel {

	/** Read-only configuration node with the kernel Cfg read semantics. */
	final class Cfg {
		public function __construct(private array $data) {}

		public function __get(string $key): mixed {
			if (!\array_key_exists($key, $this->data)) {
				throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
			}
			$value = $this->data[$key];
			if (\is_array($value) && ($value === [] || !\array_is_list($value))) {
				return new self($value);
			}
			return $value;
		}

		public function __isset(string $key): bool {
			return \array_key_exists($key, $this->data);
		}

		public function toArray(): array {
			return $this->data;
		}
	}
}

namespace CitOmni\Kernel\Service {

	/** Base service double: keeps the app and options, then calls init() when defined. */
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

namespace CitOmni\Http\Tests\Support {

	use CitOmni\Kernel\Cfg;

	/** App double: cfg, routes and the services registered by the suite. */
	final class App {
		public readonly Cfg $cfg;
		public readonly array $routes;

		/** @var array<string, object> */
		private array $services = [];

		public function __construct(array $cfg = [], array $routes = []) {
			$this->cfg = new Cfg($cfg);
			$this->routes = $routes;
		}

		/** Register a service instance under its service id. */
		public function set(string $id, object $service): void {
			$this->services[$id] = $service;
		}

		public function __get(string $id): object {
			return $this->services[$id] ?? throw new \RuntimeException("Unknown app component: app->{$id}");
		}

		public function hasService(string $id): bool {
			return isset($this->services[$id]);
		}
	}

	/**
	 * Layer a cfg override on a baseline: associative arrays merge deeply, lists and
	 * scalars are replaced, as in CitOmni\Kernel\Arr::mergeAssocLastWins().
	 */
	function mergeLastWins(array $base, array $override): array {
		foreach ($override as $key => $value) {
			if (
				\is_string($key)
				&& \is_array($value) && !\array_is_list($value)
				&& \is_array($base[$key] ?? null) && !\array_is_list($base[$key])
			) {
				$base[$key] = mergeLastWins($base[$key], $value);
				continue;
			}
			$base[$key] = $value;
		}
		return $base;
	}
}
