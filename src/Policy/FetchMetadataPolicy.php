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

namespace CitOmni\Http\Policy;

use CitOmni\Http\Util\RoutePath;
use CitOmni\Kernel\App;
use CitOmni\Kernel\Cfg;

/**
 * Fetch Metadata resource-isolation rule for requests that are not same-origin.
 *
 * Kernel::run() consults this policy before maintenance and routing when
 * security.fetch_metadata.enabled is true and Sec-Fetch-Site is neither
 * "same-origin" nor "none". The policy only decides; the 403 stays with the Kernel.
 *
 * Behavior:
 * - Missing or unrecognized Sec-Fetch-Site: allowed when allow_missing is true,
 *   otherwise only a matching exemption admits the request.
 * - "same-site": allowed when allow_same_site is true, otherwise handled like
 *   "cross-site".
 * - "cross-site" and untrusted "same-site": GET/HEAD top-level navigations
 *   (Sec-Fetch-Mode "navigate", Sec-Fetch-Dest "document") are allowed. Every other
 *   context needs an exemption that matches both path and method.
 * - Exemptions (security.fetch_metadata.exempt) are a map of named rules, so provider
 *   and app entries merge: ['path' => '/x', 'match' => 'exact'|'prefix',
 *   'methods' => ['GET', ...]]. false removes an inherited rule by name. Other keys
 *   in a rule are ignored.
 * - Paths are compared with the route path from Util\RoutePath, so mount path,
 *   decoding, trailing slashes and http.router_case_insensitive follow Router.
 *   "exact" matches the path itself; "prefix" also matches every path below it
 *   ("/a" covers "/a" and "/a/b", never "/ab"). A prefix of "/" covers every path.
 *
 * Notes:
 * - Header values are compared case-insensitively, as in Csrf::checkFetchMetadata().
 * - Every rule is validated whenever the exemptions are consulted, so an invalid rule
 *   fails instead of being skipped. Invalid means it cannot match as written: a path
 *   that is not a decoded app path (no leading slash; %, ?, #, backslash, space,
 *   control or non-ASCII bytes; // or dot segments), an unknown match mode, or
 *   methods that are not a non-empty list of uppercase method names.
 * - An exemption only bypasses this policy. Routing, method negotiation,
 *   authentication and CSRF verification still apply.
 *
 * Typical usage (Kernel::run()):
 *   if (!(new FetchMetadataPolicy($app))->allows($site)) {
 *       $app->errorHandler->httpError(403, [...]);
 *   }
 */
class FetchMetadataPolicy {

	/**
	 * @param App $app Booted HTTP application.
	 */
	public function __construct(private readonly App $app) {}


	/**
	 * Decide whether a request that is not same-origin or user-initiated may be dispatched.
	 *
	 * @param  string  $site  Lowercased Sec-Fetch-Site value; '' when the header is absent.
	 * @return bool  True when the request may continue to maintenance and routing.
	 * @throws \RuntimeException  When a consulted configuration value is invalid.
	 */
	public function allows(string $site): bool {
		$cfg = $this->app->cfg->security->fetch_metadata;
		$browserContext = $site === 'cross-site' || $site === 'same-site';

		if (!$browserContext) {
			if (self::flag($cfg->allow_missing, 'allow_missing')) {
				return true;
			}
		} elseif ($site === 'same-site' && self::flag($cfg->allow_same_site, 'allow_same_site')) {
			return true;
		}

		$request = $this->app->request;
		$method = $request->method();
		if ($browserContext
			&& ($method === 'GET' || $method === 'HEAD')
			&& \strtolower((string)$request->header('Sec-Fetch-Mode', '')) === 'navigate'
			&& \strtolower((string)$request->header('Sec-Fetch-Dest', '')) === 'document') {
			return true;
		}

		return $this->exempted($method, $cfg->exempt);
	}


	/**
	 * Whether a valid exemption matches the request path and method.
	 *
	 * @param  string  $method  Uppercase request method.
	 * @param  mixed   $exempt  security.fetch_metadata.exempt.
	 * @return bool
	 * @throws \RuntimeException  When the map or one of its rules is invalid.
	 */
	private function exempted(string $method, mixed $exempt): bool {
		$rules = $exempt instanceof Cfg ? $exempt->toArray() : $exempt;
		if (!\is_array($rules) || ($rules !== [] && \array_is_list($rules))) {
			throw new \RuntimeException('security.fetch_metadata.exempt must be a map of named rules.');
		}
		if ($rules === []) {
			return false;
		}

		$caseInsensitive = (bool)$this->app->cfg->http->router_case_insensitive;
		$path = RoutePath::resolve(
			(string)($_SERVER['REQUEST_URI'] ?? '/'),
			\defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : '',
			(string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php')),
			$caseInsensitive
		);

		$matched = false;
		foreach ($rules as $name => $rule) {
			if ($rule === false) {
				continue;
			}
			[$rulePath, $prefix, $methods] = self::rule($name, $rule, $caseInsensitive);
			if (!$matched
				&& \in_array($method, $methods, true)
				&& ($path === $rulePath || ($prefix && ($rulePath === '/' || \str_starts_with($path, $rulePath . '/'))))) {
				$matched = true;
			}
		}
		return $matched;
	}


	/**
	 * Validate one exemption rule.
	 *
	 * @param  int|string  $name             Rule name (map key).
	 * @param  mixed       $rule             Rule definition.
	 * @param  bool        $caseInsensitive  http.router_case_insensitive.
	 * @return array{0: string, 1: bool, 2: list<string>}  [route path, is prefix, methods].
	 * @throws \RuntimeException  When the rule cannot match as written.
	 */
	private static function rule(int|string $name, mixed $rule, bool $caseInsensitive): array {
		if (!\is_string($name) || $name === '') {
			throw new \RuntimeException('security.fetch_metadata.exempt rules need non-empty string names.');
		}
		$key = 'security.fetch_metadata.exempt.' . $name;
		if (!\is_array($rule) || !isset($rule['path'], $rule['match'], $rule['methods'])) {
			throw new \RuntimeException($key . ' needs path, match and methods (or false to remove it).');
		}

		$path = $rule['path'];
		if (!\is_string($path) || !\str_starts_with($path, '/')
			|| \preg_match('/[\x00-\x20\x7F-\xFF%?#\\\\]/', $path) === 1
			|| \str_contains($path, '//')
			|| \preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) === 1) {
			throw new \RuntimeException($key . '.path must be a decoded app path such as "/payments/return".');
		}
		if ($rule['match'] !== 'exact' && $rule['match'] !== 'prefix') {
			throw new \RuntimeException($key . '.match must be "exact" or "prefix".');
		}

		$methods = $rule['methods'];
		if (!\is_array($methods) || $methods === [] || !\array_is_list($methods)) {
			throw new \RuntimeException($key . '.methods must be a non-empty list.');
		}
		foreach ($methods as $method) {
			if (!\is_string($method) || \preg_match('/^[A-Z]+$/D', $method) !== 1) {
				throw new \RuntimeException($key . '.methods must contain uppercase method names such as "GET".');
			}
		}

		$path = \rtrim($path, '/') ?: '/';
		return [$caseInsensitive ? \strtolower($path) : $path, $rule['match'] === 'prefix', $methods];
	}


	/**
	 * Read a boolean policy flag.
	 *
	 * @param  mixed   $value  Configured value.
	 * @param  string  $name   Key below security.fetch_metadata, for the message.
	 * @return bool
	 * @throws \RuntimeException  When the value is not a boolean.
	 */
	private static function flag(mixed $value, string $name): bool {
		if (!\is_bool($value)) {
			throw new \RuntimeException('security.fetch_metadata.' . $name . ' must be a boolean.');
		}
		return $value;
	}
}
