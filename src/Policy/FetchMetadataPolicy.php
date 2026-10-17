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
 * FetchMetadataPolicy: Policy that decides whether a request from another context may reach the app.
 *
 * Holds the Fetch Metadata resource-isolation rule in one place: Which requests that are not
 * same-origin may proceed, and which paths are exempt. It only decides; enforcing the
 * decision, and answering a refusal, belong to the caller.
 *
 * Rules:
 * - Compare paths only as RoutePath::resolve() returns them, so an exemption matches the
 *   path that the Router routes.
 * - Fail on a cfg value that cannot be used as written; never skip it.
 * - An exemption bypasses only this rule, never routing, authentication or CSRF verification.
 */
class FetchMetadataPolicy {

	/**
	 * Create the Policy for one app.
	 *
	 * @param  App  $app  Booted HTTP app whose cfg holds security.fetch_metadata.
	 */
	public function __construct(private readonly App $app) {}


	/**
	 * Decide whether a request that is not same-origin or user-initiated may proceed.
	 *
	 * Behavior:
	 * - Any $site other than cross-site and same-site, '' included: True when cfg
	 *   security.fetch_metadata.allow_missing is true; otherwise only an exemption admits it.
	 * - same-site: True when cfg security.fetch_metadata.allow_same_site is true; otherwise
	 *   decided like cross-site.
	 * - cross-site: True for a GET or HEAD top-level navigation, with Sec-Fetch-Mode navigate
	 *   and Sec-Fetch-Dest document in any letter case; otherwise only an exemption admits it.
	 * - An exemption admits the request when its path and its methods match.
	 *
	 * Notes:
	 * - Admit same-origin and none before calling: Here they count as unrecognized values.
	 *
	 * Typical usage:
	 *   $allowed = (new FetchMetadataPolicy($app))->allows($site);
	 *
	 * @param  string  $site  Sec-Fetch-Site in lowercase; '' when the header is absent.
	 * @return bool
	 * @throws \RuntimeException  When a flag that the decision reads is not a boolean, or when the exemptions are consulted and are not a map or hold an invalid rule.
	 * @see \CitOmni\Http\Policy\FetchMetadataPolicy::exempted()  How exemptions match.
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
	 * Check whether an exemption matches the request path and method.
	 *
	 * Behavior:
	 * - The exemptions are a map of named rules: ['path' => '/x', 'match' => 'exact' or
	 *   'prefix', 'methods' => ['GET', ...]]. A rule set to false is skipped, which lets an
	 *   app remove an inherited rule by name. Other keys in a rule are ignored. An empty map
	 *   matches nothing.
	 * - exact matches the path itself. prefix also matches every path below it: "/a" covers
	 *   "/a" and "/a/b", never "/ab". A prefix of "/" covers every path.
	 * - A trailing slash in a rule path is ignored. With cfg http.router_case_insensitive true,
	 *   paths match in any letter case.
	 * - Every rule is validated, also after one has matched, so an invalid rule fails on every
	 *   consulted request.
	 *
	 * @param  string  $method  Uppercase request method.
	 * @param  mixed   $exempt  Cfg security.fetch_metadata.exempt.
	 * @return bool
	 * @throws \RuntimeException  When $exempt is not a map or a rule is invalid.
	 * @see \CitOmni\Http\Policy\FetchMetadataPolicy::rule()  What makes a rule invalid.
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
	 * Validate one exemption rule and return it in comparable form.
	 *
	 * Behavior:
	 * - Invalid, because it cannot match as written:
	 *     - A name that is not a non-empty string.
	 *     - A rule that is not an array with path, match and methods.
	 *     - A path that is not a string or not a decoded app path: No leading slash; a %, ?,
	 *       #, backslash, space, control or non-ASCII byte; "//"; or a "." or ".." segment.
	 *     - A match other than exact and prefix.
	 *     - methods that are not a non-empty list of uppercase letter-only names.
	 *
	 * @param  int|string  $name             Rule name, the map key.
	 * @param  mixed       $rule             Rule definition.
	 * @param  bool        $caseInsensitive  Cfg http.router_case_insensitive; true lowercases the path.
	 * @return array{0:string,1:bool,2:list<string>}  Path without trailing slash ("/" stays), whether it is a prefix, and the methods.
	 * @throws \RuntimeException  When the rule is invalid; the message names its cfg path.
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
	 * Return a security.fetch_metadata flag, or throw when it is not a boolean.
	 *
	 * @param  mixed   $value  The cfg value.
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
