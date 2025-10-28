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
use LiteView\LiteView;

/**
 * View: Deterministic template rendering with lean global helpers for HTTP UI.
 *
 * Responsibilities:
 * - Render a template file from the application "templates/" root (layer = 'app')
 *   or from a vendor "layer" (e.g., "citomni/auth").
 * - Provide a minimal, fast set of template globals and closures:
 *   1) Identity & locale values (app_name, base_url, language, charset).
 *   2) Environment flags (env.name, env.dev).
 *   3) View passthroughs (marketing_scripts, view_vars).
 *   4) Helpers: $txt, $url, $asset, $hasService, $hasPackage, $csrfField, $currentPath.
 *   5) Dynamic view-vars providers (cfg:view.vars_providers) evaluated per request
 *      using app-relative path matching (include/exclude).
 * - Keep logic lean and deterministic; avoid heavy abstractions or magic.
 *
 * Collaborators:
 * - \LiteView\LiteView        (render engine).
 * - $this->app->cfg           (read-only config wrapper; http/identity/locale/view/security).
 * - $this->app->txt           (i18n lookups for $txt()).
 * - $this->app->datetime      (locale/timezone-aware date formatting for $dt(), $dtNow(), $dtMonth(), $dtWeekday()).
 * - $this->app->security      (optional: CSRF input via $csrfField()).
 * - $this->app->hasService()  (feature probing inside templates).
 * - $this->app->hasPackage()  (layer/package probing inside templates).
 *
 * Configuration keys read:
 * - http.base_url                  (string) Absolute base URL used by $url() and $asset().
 * - identity.app_name              (string) App display name.
 * - locale.language                (string) Language code (e.g., "da", "en").
 * - locale.charset                 (string) Charset (default "UTF-8").
 * - locale.icu_locale, locale.timezone  (indirectly used by Datetime service for $dt/helpers).
 * - view.asset_version             (string) Optional cache-busting value appended by $asset().
 * - view.marketing_scripts         (string) Optional HTML snippet exposed as "marketing_scripts".
 * - view.view_vars                 (array)  Arbitrary read-only vars exposed as "view_vars".
 * - view.cache_enabled             (bool)   Enable LiteView caching (cache dir: <APP>/var/cache).
 * - view.trim_whitespace           (bool)   Trim whitespace in rendered output.
 * - view.remove_html_comments      (bool)   Remove HTML comments in output.
 * - view.allow_php_tags            (bool)   Allow PHP tags in templates for LiteView.
 * - security.csrf_protection       (bool)   Informational flag for templates.
 * - security.honeypot_protection   (bool)   Informational flag for templates.
 * - security.form_action_switching (bool)   Informational flag for templates.
 * - security.captcha_protection    (bool)   Informational flag for templates.
 * - view.vars_providers            (array)  List of providers with {var, call, include?, exclude?}.
 *
 * Behavior:
 * - Path resolution:
 *   1) When layer === 'app', render from the application templates root.
 *   2) When a layer is provided (e.g., "citomni/auth"), resolve vendor root as
 *      CITOMNI_APP_PATH . "/vendor/{layer}/templates".
 *   3) The layer slug must match "vendor/package" (letters, digits, dot, dash, underscore).
 * - URL derivation:
 *   1) base_url = $this->app->cfg->http->base_url (must be absolute).
 *   2) public_root_url = CITOMNI_PUBLIC_ROOT_URL if defined, else base_url.
 * - Globals construction:
 *   1) Built once per request (memoized) and reused for subsequent render() calls.
 *   2) Provide closures that close over $this->app and config (no global state).
 *   3) Caller-provided $data overrides any pre-defined globals by key.
 * - Rendering engine:
 *   - Uses LiteView::render($file, $root, cache_enabled, <APP>/var/cache,
 *     trim_whitespace, remove_html_comments, $finalVars, allow_php_tags).
 * - Datetime helper defaults:
 *   1) Timezone and ICU locale default to service/app configuration.
 *   2) $dtMonth()/$dtWeekday(): $form defaults to 'full'; $locale is optional.
 *   3) Overriding tz/locale is supported per call for edge cases (e.g., audience-specific pages).
 * - Dynamic vars providers:
 *   1) App-relative path is computed from Request::baseUrl() and Request::path().
 *   2) Each provider row = { var: string, call: mixed, include?: string[], exclude?: string[] }.
 *   3) Path matching supports:
 *      - "~...~"   raw PCRE (used as-is).
 *      - "/foo/*"  glob-like prefix (anchored at start).
 *      - "/"       exact frontpage only.
 *      - "*"       match all.
 *   4) Precedence when merging for render(): $data > dynamic($providers) > globals.
 *
 * - Diagnostic output (dev/stage only):
 *   1) If query contains "?_viewvars", the service prints the final vars payload
 *      before template output, wrapped in HTML comments (<!-- ... -->).
 *
 * Helpers exposed to templates (examples assume LiteView syntax):
 * - {{ $txt('key', 'file', 'layer', 'Default', ['NAME' => 'Alice']) }}
 *   Localized string with optional default and vars; layer is optional.
 *
 * - {{ $url('/path', ['q' => 'x']) }}
 *   Joins base_url with a path and optional query map.
 *
 * - {{ $asset('/assets/app.css', '1.2.3') }}
 *   Returns base_url + path and appends ?v=version if provided or if
 *   view.asset_version is set; preserves existing query correctly.
 *
 * - {{ $dt('2025-10-25 16:30', 'yyyy-MM-dd HH:mm') }}
 *   Minimal call (uses default locale/timezone from cfg/service).
 *   Optional overrides: {{ $dt(null, 'yyyy-MM-dd', 'Europe/Copenhagen', 'da_DK') }}
 *
 * - {{ $dtNow('EEEE d. MMMM yyyy') }}
 *   Minimal call (uses defaults). Optional overrides:
 *   {{ $dtNow('yyyy-MM-dd HH:mm', 'Pacific/Apia', 'en_US') }}
 *
 * - {{ $dtMonth(10) }}                {# "October" (default form='full') #}
 *   Optional forms: {{ $dtMonth(10, 'short') }}, {{ $dtMonth(10, 'narrow') }}
 *   Optional locale override: {{ $dtMonth(10, 'short', 'en_US') }}
 *
 * - {{ $dtWeekday(6) }}               {# "Friday" (default form='full') #}
 *   Optional forms: {{ $dtWeekday(6, 'short') }}, {{ $dtWeekday(6, 'narrow') }}
 *   Optional locale override: {{ $dtWeekday(6, 'full', 'da_DK') }}
 *
 * - {% if $hasService('auth') %} ... {% endif %}
 *   Checks for a service id in the service map.
 *
 * - {% if $hasPackage('citomni/auth') %} ... {% endif %}
 *   Checks presence of a vendor/package in the app (by your package probing).
 *
 * - {{{ $csrfField() }}}
 *   Returns a prebuilt hidden input with the CSRF token if the Security
 *   service is present and exposes csrfHiddenInput(); otherwise returns "".
 *   Triple braces to avoid HTML escaping in the engine.
 *
 * Method contracts:
 * - render(string $file, string $layer = 'app', array $data = []): void
 *   Render $file from app templates (layer 'app') or a vendor layer; merges
 *   globals with $data (caller overrides). Prints output; returns void.
 *
 * - buildGlobals(): array<string,mixed>
 *   Construct the globals and closures for templates; memoized per request.
 *
 * - resolveVendorTemplateRoot(string $layer): string
 *   Validate "vendor/package" slug and return the absolute "templates" dir.
 *
 * Error handling:
 * - Invalid layer slugs throw \InvalidArgumentException.
 * - Underlying renderer exceptions (missing template, syntax errors) bubble up
 *   to the global error handler (fail fast); this class does not catch them.
 *
 * Performance & determinism:
 * - No template discovery scans or autoload magic: call render() with explicit
 *   relative paths like "public/login.html".
 * - Globals are constructed per render call; closures do O(1) work and defer
 *   service calls until used in the template.
 * - Asset/version logic is branchless in the common case (no version set).
 *
 * Examples (render template):
 *   // Vendor layer template (CitOmni/Http)
 *   $this->app->view->render('public/home.html', 'citomni/http', [
 *   	'title' => 'Welcome'
 *   ]);
 *
 *   // Provider layer template (e.g., CitOmni/Auth)
 *   $this->app->view->render('public/login.html', 'citomni/auth', [
 *   	'title' => 'Sign in'
 *   ]);
 *
 * Examples (template side):
 *   <!-- Date/time helpers (minimal calls use defaults) -->
 *   <time datetime="{{ $dtNow('yyyy-MM-dd\'T\'HH:mm:ssXXX') }}">  {# Override per call if needed: {{ $dtNow('yyyy-MM-dd HH:mm', 'America/New_York', 'en_US') }} #}
 *   	{{ $dtNow('EEEE d. MMMM yyyy HH:mm') }}
 *   </time>
 *
 *   <!-- Absolute URL from base_url -->
 *   <a href="{{ $url('/member/home.html') }}">Home</a>
 *
 *   <!-- Versioned static asset -->
 *   <link rel="stylesheet" href="{{ $asset('/assets/app.css') }}">
 *
 *   <!-- Conditional menu item if Auth is installed -->
 *   {% if $hasPackage('citomni/auth') %}
 *   	<a href="{{ $url('/member/profile.html') }}">Profile</a>
 *   {% endif %}
 *
 *   <!-- CSRF field inside a POST form -->
 *   <form method="post" action="{{ $url('/login-handler.json') }}">
 *   	{{{ $csrfField() }}}
 *   	<!-- fields -->
 *   </form>
 *
 * Template globals quick reference:
 *  {{ $app_name }}                         				{# "My CitOmni App" #}
 *  {{ $url('/path') }}                     				{# "https://host/base/path" #}
 *  {{ $asset('/assets/app.css') }}         				{# "https://host/base/assets/app.css?v=..." (optional) #}
 *  {% if $hasService('auth') %}...{% endif %}
 *  {% if $hasPackage('citomni/auth') %}...{% endif %}
 *  {{{ $csrfField() }}}
 *  {{ $currentPath() }}
 *  {{ $txt('key', 'file', 'vendor/package') }}
 *  {{ $dt($when, 'pattern') }}                          	{# tz/locale optional #}
 *  {{ $dtNow('pattern') }}                              	{# tz/locale optional #}
 *  {{ $dtMonth(1..12 [, 'full|short|narrow' [, locale]]) }}
 *  {{ $dtWeekday(1..7 [, 'full|short|narrow' [, locale]]) }}
 *
 *
 * Notes:
 * - Ensure http.base_url is absolute and stable across environments.
 * - If CITOMNI_PUBLIC_ROOT_URL is defined (recommended for stage/prod), it is
 *   exposed as public_root_url for templates that need absolute canonical links.
 * - Keep templates ASCII-only when possible; apply the configured charset when
 *   escaping values you embed manually (the engine typically handles escaping).
 *
 * @throws \InvalidArgumentException If $templateLayer is not a valid "vendor/package" slug.
 */
class View extends BaseService {
	
	/** @var array<string,mixed>|null Cached globals for this request (built once). */
	private ?array $globals = null;
	
	/** @var array<int, array{
	 *    var:string,
	 *    call:mixed,
	 *    inc:array<int,string>,  // original include patterns
	 *    exc:array<int,string>,  // original exclude patterns
	 *    ire:array<int,string>,  // compiled include regexes
	 *    ere:array<int,string>   // compiled exclude regexes
	 * }>|null
	 */
	private ?array $compiledProviders = null;
	
	
	/**
	 * Service bootstrap hook.
	 *
	 * Behavior:
	 * - Keep initialization minimal for cold-start performance.
	 *
	 * Notes:
	 * - This service does not keep internal state; no warm-up required.
	 *
	 * Typical usage:
	 *   // Nothing to call explicitly; the app constructs the service as needed.
	 *
	 * @return void
	 */
	/* 
	protected function init(): void {
		// Intentionally empty (lean boot).
	}
	*/
	

	/**
	 * Render a template with merged globals and caller-provided data.
	 *
	 * The following globals are always available to LiteView templates:
	 * - string  app_name
	 * - string  base_url
	 * - string  public_root_url
	 * - string  language
	 * - string  charset
	 * - string  marketing_scripts
	 * - array   view_vars
	 * - bool    csrf_protection
	 * - bool    honeypot_protection
	 * - bool    form_action_switching
	 * - bool    captcha_protection
	 * - array   env { name: string, dev: bool }
	 * - callable txt(string $key, string $file, ?string $layer = app, string $default = '', array $vars = []): string
	 * - callable url(string $path = '', array $query = []): string
	 * - callable asset(string $path, ?string $version = null): string
	 * - callable hasService(string $id): bool
	 * - callable hasPackage(string $slug): bool
	 * - callable csrfField(): string   // returns '<input type="hidden" ...>' or '' if disabled
	 * - callable currentPath(): string
	 *
	 * Caller-provided $data overrides globals on key collision.
	 *
	 * Dynamic vars providers:
	 * - Evaluated per request based on the app-relative path.
	 * - Merging precedence: $data (controller) > dynamic (providers) > globals.
	 *
	 * Dev/stage diagnostics:
	 * - If the query string contains "_viewvars", prints the final vars payload
	 *   before template output (wrapped in "<!-- -->").
	 *
	 * @param string $file   Relative template path (e.g. "public/login.html").
	 * @param string $layer  "app" or "vendor/package" (e.g. "citomni/auth").
	 * @param array<string,mixed> $data Template variables (caller wins over globals).
	 * @return void
	 *
	 * @example Controller: render an app template
	 *  $this->app->view->render('public/login.html');
	 *
	 * @example Controller: render a vendor-layer template with extra data
	 *  $this->app->view->render('public/register.html', 'citomni/auth', [
	 *  	'title' => 'Create account'
	 *  ]);
	 *
	 * @example Template usage (LiteView)
	 *  {# Build an absolute URL from base_url #}
	 *  <a href="{{ $url('/member/home.html') }}">Home</a>
	 *
	 *  {# Static asset with optional versioning #}
	 *  <link rel="stylesheet" href="{{ $asset('/assets/app.css') }}">
	 *
	 *  {# Conditional UI based on installed package #}
	 *  {% if $hasPackage('citomni/auth') %}
	 *  	<a href="{{ $url('/member/profile.html') }}">Profile</a>
	 *  {% endif %}
	 *
	 *  {# i18n helper #}
	 *  <h1>{{ $txt('login.title', 'auth', 'citomni/auth') }}</h1>
	 *
	 *  {# CSRF field (raw HTML → triple braces) #}
	 *  <form method="post" action="{{ $url('/login-handler.json') }}">
	 *  	{{{ $csrfField() }}}
	 *  	<!-- fields -->
	 *  </form>
	 */
	public function render(string $file, string $layer = 'app', array $data = []): void {
		$templateRoot = ($layer === 'app')
			? \CITOMNI_APP_PATH . '/templates'
			: $this->resolveVendorTemplateRoot($layer);

		// 1) Build globals once
		$vars = $this->globals ??= $this->buildGlobals();

		// 2) Build dynamic vars from providers (only those matching current path)
		$path = $this->appRelativePath($this->app->request->path());
		$dyn  = $this->buildDynamicVars($path);

		// 3) Precedence (left wins): controller $data > provider $dyn > global $vars
		$final = $data + $dyn + $vars;

		// 4) Set cache dir
		$cacheDir = \CITOMNI_APP_PATH . '/var/cache';
		
		// 5) Optional (only on explicit query flag): Print view vars before template output.
		if ($this->app->request->get('_viewvars') !== null) {
			$this->printViewVars($final);
		}

		// 6) Render the template
		LiteView::render(
			$file,
			$templateRoot,
			(bool)$this->app->cfg->view->cache_enabled,
			$cacheDir,
			(bool)$this->app->cfg->view->trim_whitespace,
			(bool)$this->app->cfg->view->remove_html_comments,
			$final,
			(bool)$this->app->cfg->view->allow_php_tags
		);
	}


	/**
	 * Compile provider patterns once, then evaluate includes/excludes for current path.
	 *
	 * Include/Exclude semantics:
	 * - include=[]  => included by default (match-all) unless excluded.
	 * - include!=[] => at least one include regex must match.
	 * - exclude[]   => any match excludes (takes precedence over include).
	 *
	 * Pattern forms (human-friendly):
	 * - "~...~" => raw PCRE, used as-is.
	 * - "*"     => matches all paths.
	 * - "/"     => matches only the exact frontpage (app-relative "/").
	 * - "/foo/*" or "/foo" => anchored prefix (glob-like).
	 *
	 * @param string $path Current app-relative request path (e.g., "/nyheder/foo-a123.html").
	 * @return array<string,mixed> Map of var => value for matched providers (lazy-invoked).
	 */
	private function buildDynamicVars(string $path): array {
		$cfg = (array)($this->app->cfg->view->vars_providers ?? []);
		if ($cfg === []) {
			return [];
		}

		// Compile once per request
		if ($this->compiledProviders === null) {
			$this->compiledProviders = [];
			foreach ($cfg as $row) {
				$var  = (string)($row['var']  ?? '');
				$call =         ($row['call'] ?? null);
				if ($var === '' || $call === null) {
					// Fail fast in dev; ignore silently in prod.
					if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev') {
						throw new \RuntimeException("view.vars_providers: 'var' and 'call' are required.");
					}
					continue;
				}
				$inc = \array_values((array)($row['include'] ?? []));
				$exc = \array_values((array)($row['exclude'] ?? []));

				$this->compiledProviders[] = [
					'var' => $var,
					'call' => $call,
					'inc' => $inc,
					'exc' => $exc,
					'ire' => \array_map($this->compilePathMatcher(...), $inc),
					'ere' => \array_map($this->compilePathMatcher(...), $exc),
				];
			}
		}

		$out = [];
		foreach ($this->compiledProviders as $p) {
			if (!$this->pathMatches($path, $p['ire'], $p['ere'])) {
				continue;
			}
			// Compute value lazily only if matched
			$out[$p['var']] = $this->invokeProvider($p['call'], $p['var']);
		}
		return $out;
	}


	/**
	 * Convert a human-friendly matcher into an anchored PCRE.
	 *
	 * Supported forms:
	 * - "~...~"       raw PCRE (returned unchanged).
	 * - "*"           match all.
	 * - "/"           exact frontpage only (app-relative).
	 * - "/foo/*"      glob-like prefix (anchored at start).
	 * - "/foo"        simple anchored prefix.
	 */
	private function compilePathMatcher(string $pat): string {
		$pat = (string)$pat;

		// Empty => match nothing
		if ($pat === '') {
			return '/^\b\B$/';
		}

		// Raw regex form: "~...~"
		if ($pat[0] === '~' && \str_ends_with($pat, '~') && \strlen($pat) >= 2) {
			return $pat;
		}

		// Normalize to app-relative style: ensure leading slash unless it's a pure "*"
		if ($pat !== '*' && $pat[0] !== '/') {
			$pat = '/' . $pat;
		}

		// Special case: exact frontpage "/"
		if ($pat === '/') {
			return '/^\/$/';
		}

		// Glob-like: '*' => '.*' ; prefix match anchored at start
		$quoted = \preg_quote($pat, '/');
		$quoted = \str_replace('\*', '.*', $quoted);

		// Typical intents:
		// "/foo/*"  => any under /foo/
		// "/foo"    => any starting with "/foo" (prefix)
		// "*"       => everything
		return '/^' . $quoted . '/';
	}


	/**
	 * Return true if $path is included and not excluded by provided regex lists.
	 *
	 * Include default:
	 * - If $incRes is empty, the path is considered included unless excluded.
	 *
	 * @param string   $path
	 * @param string[] $incRes
	 * @param string[] $excRes
	 */
	private function pathMatches(string $path, array $incRes, array $excRes): bool {
		// If include is empty => included by default; otherwise require at least one match
		$included = ($incRes === []);
		if (!$included) {
			foreach ($incRes as $re) {
				if (\preg_match($re, $path) === 1) {
					$included = true;
					break;
				}
			}
		}
		if (!$included) {
			return false;
		}
		// Exclusions take precedence
		foreach ($excRes as $re) {
			if (\preg_match($re, $path) === 1) {
				return false;
			}
		}
		return true;
	}


	/**
	 * appRelativePath: Strip the app base path from a raw request path.
	 *
	 * Notes:
	 * - Respects CITOMNI_PUBLIC_ROOT_URL/http.base_url via Request::baseUrl().
	 * - Always returns "/" for the frontpage (never an empty string).
	 *
	 * Examples:
	 *  baseUrl() = "http://localhost/byensportal/byensportal.dk/"
	 *  request->path() = "/byensportal/byensportal.dk/begivenheder/x.html"
	 *  => "/begivenheder/x.html"
	 *
	 * @param string $rawPath Request::path() (always begins with "/").
	 * @return string App-relative path beginning with "/", never empty ("/" for frontpage).
	 */
	private function appRelativePath(string $rawPath): string {
		// 1) Find base path from the configured/public root URL
		$base = $this->app->request->baseUrl(); // ends with "/"
		$basePath = (string)\parse_url($base, \PHP_URL_PATH);
		$basePath = $basePath !== '' ? '/' . \trim($basePath, '/') . '/' : '/';

		// 2) Normalize raw path
		$path = $rawPath !== '' ? $rawPath : '/';
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}

		// 3) Strip prefix if present
		if ($basePath !== '/' ) {
			// Ensure basePath has single trailing slash; match strictly at start
			$prefix = \rtrim($basePath, '/');
			if ($prefix !== '' && \str_starts_with($path, $prefix . '/')) {
				$path = \substr($path, \strlen($prefix));
			}
		}

		// 4) Collapse any accidental '//' (defensive)
		$path = \preg_replace('~/+~', '/', $path) ?? $path;

		return $path === '' ? '/' : $path;
	}


	/**
	 * Invoke a configured provider in a deterministic, minimal way.
	 *
	 * Supported forms:
	 * - "FQCN::method"                       (static; called as Class::method(App $app))
	 * - ["class" => FQCN, "method" => "m"]   (instantiates new Class($this->app); calls ->m())
	 * - ["service" => "id", "method" => "m"] (calls $this->app->id->m())
	 *
	 * Constructor contract:
	 * - When instantiating a class provider, the constructor must be: __construct(App $app, array $options = [])
	 *
	 * @param mixed  $call
	 * @param string $varName For error context only.
	 * @return mixed
	 */
	private function invokeProvider(mixed $call, string $varName): mixed {
		// Static "FQCN::method"
		if (\is_string($call) && \strpos($call, '::') !== false) {
			[$cls, $m] = \explode('::', $call, 2);
			if (!\method_exists($cls, $m)) {
				return $this->failOrNull("Provider method {$cls}::{$m} for var '{$varName}' does not exist.");
			}
			return $cls::$m($this->app);
		}

		// ["class" => FQCN, "method" => "m"]
		if (\is_array($call) && isset($call['class'], $call['method'])) {
			$cls = (string)$call['class'];
			$m   = (string)$call['method'];
			if (!\method_exists($cls, $m)) {
				return $this->failOrNull("Provider method {$cls}::{$m} for var '{$varName}' does not exist.");
			}
			$inst = new $cls($this->app);
			return $inst->{$m}();
		}

		// ["service" => "id", "method" => "m"]
		if (\is_array($call) && isset($call['service'], $call['method'])) {
			$id = (string)$call['service'];
			$m  = (string)$call['method'];
			if (!$this->app->hasService($id)) {
				return $this->failOrNull("Service '{$id}' for var '{$varName}' is not available.");
			}
			$svc = $this->app->{$id};
			if (!\method_exists($svc, $m)) {
				return $this->failOrNull("Service method {$id}::{$m} for var '{$varName}' does not exist.");
			}
			return $svc->{$m}();
		}

		return $this->failOrNull("Unsupported 'call' definition for var '{$varName}'.");
	}


	/**
	 * Fail fast in dev; degrade to null in prod.
	 *
	 * Notes:
	 * - Throws in "dev"; returns null in other environments (stage/prod).
	 *
	 */
	private function failOrNull(string $msg): mixed {
		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev') {
			throw new \RuntimeException($msg);
		}
		return null;
	}


	/**
	 * Build a minimal, deterministic set of globals for templates.
	 *
	 * Notes:
	 * - Keep logic lean; use closures for anything that might touch services.
	 * - Caller-provided $data can override any of these keys in render().
	 * - Exposes 'env' => {name, dev} for template-side environment checks.
	 *
	 * @return array<string,mixed>
	 */
	private function buildGlobals(): array {
		$cfg = $this->app->cfg; // read-only wrapper

		// Resolve base/public URLs (prefer constant when defined)
		$baseUrl = (string)($cfg->http->base_url ?? (\defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : ''));
		$publicUrl = \defined('CITOMNI_PUBLIC_ROOT_URL') ? (string)\CITOMNI_PUBLIC_ROOT_URL : $baseUrl;

		return [
			// --- Core identity & locale
			'app_name'         => (string)$cfg->identity->app_name,
			'base_url'         => $baseUrl,
			'public_root_url'  => $publicUrl,
			'language'         => (string)$cfg->locale->language,
			'charset'          => (string)$cfg->locale->charset,

			// --- View config passthroughs (kept small)
			'marketing_scripts'=> (string)$cfg->view->marketing_scripts,
			'view_vars'        => (array)$cfg->view->view_vars,

			// --- Security toggles (informational for templates)
			'csrf_protection'       => (bool)$cfg->security->csrf_protection,
			'honeypot_protection'   => (bool)$cfg->security->honeypot_protection,
			'form_action_switching' => (bool)$cfg->security->form_action_switching,
			'captcha_protection'    => (bool)$cfg->security->captcha_protection,

			// --- Environment flags
			'env' => [
				'name' => \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod',
				'dev'  => \defined('CITOMNI_ENVIRONMENT') ? (\CITOMNI_ENVIRONMENT === 'dev') : false,
			],

			// --- Lightweight helpers (closures are lazy, only run if used in templates)

			/**
			 * Language helper (layer-aware).
			 * Usage in template: {{ $txt('login.title', 'auth', 'citomni/auth') }}
			 */
			'txt' => function (string $key, string $file, ?string $layer = null, string $default = '', array $vars = []) {				
				if (!$this->app->hasService('txt') || !$this->app->hasPackage('citomni/infrastructure')) {
					throw new \RuntimeException(
						"Text service not available. Install 'citomni/infrastructure' and ensure the 'txt' service provider is registered in /config/providers.php."
					);
				}				
				return $this->app->txt->get($key, $file, $layer, $default, $vars);
			},


			/**
			 * Datetime: format a given moment using Intl patterns.
			 *
			 * Minimal example:
			 *   {{ $dt('2025-10-25 16:30', 'yyyy-MM-dd HH:mm') }}
			 *
			 * Optional/extended examples:
			 *   {{ $dt(null, 'yyyy-MM-dd', 'Europe/Copenhagen', 'da_DK') }}   {# now + explicit tz/locale #}
			 *   {{ $dt(1735123456, 'EEEE d. MMMM yyyy') }}                    {# unix timestamp, defaults for tz/locale #}
			 *   {{ $dt($myDateObj, 'HH:mm:ss', 'Pacific/Apia') }}             {# DateTimeInterface + tz override #}
			 *
			 * Notes:
			 * - `when` may be null|string|int|\DateTimeInterface (null = now).
			 * - Timezone and locale fall back to service/app configuration unless provided.
			 */
			'dt' => function (null|string|int|\DateTimeInterface $when, string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException(
						"Datetime service not available. Ensure the Datetime provider is registered in /config/providers.php."
					);
				}
				return $this->app->datetime->format($when, $pattern, $tzName, $locale);
			},


			/**
			 * Datetime: format "now" using Intl patterns.
			 *
			 * Minimal example:
			 *   {{ $dtNow('EEEE d. MMMM yyyy') }}
			 *
			 * Optional/extended examples:
			 *   {{ $dtNow('yyyy-MM-dd HH:mm', 'Europe/Copenhagen', 'da_DK') }} {# explicit tz/locale #}
			 *   {{ $dtNow('HH:mm:ssXXX', 'America/New_York') }}                {# tz override only #}
			 *
			 * Notes:
			 * - Timezone and locale fall back to service/app configuration unless provided.
			 */
			'dtNow' => function (string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException(
						"Datetime service not available. Ensure the Datetime provider is registered in /config/providers.php."
					);
				}
				return $this->app->datetime->now($pattern, $tzName, $locale);
			},


			/**
			 * Datetime: localized month name (1..12).
			 *
			 * Minimal example:
			 *   {{ $dtMonth(10) }}                                           {# default form='full' #}
			 *
			 * Optional/extended examples:
			 *   {{ $dtMonth(10, 'short') }}                                  {# "Oct" (locale-dependent) #}
			 *   {{ $dtMonth(10, 'narrow') }}                                 {# "O" (locale-dependent) #}
			 *   {{ $dtMonth(10, 'short', 'en_US') }}                         {# explicit locale #}
			 *
			 * Notes:
			 * - Valid forms: 'full' (default), 'short', 'narrow'.
			 * - Locale falls back to service/app configuration unless provided.
			 */
			'dtMonth' => function (int $month, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException(
						"Datetime service not available. Ensure the Datetime provider is registered in /config/providers.php."
					);
				}
				return $this->app->datetime->month($month, $form, $locale);
			},


			/**
			 * Datetime: localized weekday name (ISO 1=Mon..7=Sun).
			 *
			 * Minimal example:
			 *   {{ $dtWeekday(6) }}                                          {# default form='full' (e.g., "Friday") #}
			 *
			 * Optional/extended examples:
			 *   {{ $dtWeekday(6, 'short') }}                                 {# "Fri" #}
			 *   {{ $dtWeekday(6, 'narrow') }}                                {# "F" #}
			 *   {{ $dtWeekday(6, 'full', 'da_DK') }}                         {# explicit locale #}
			 *
			 * Notes:
			 * - Valid forms: 'full' (default), 'short', 'narrow').
			 * - Locale falls back to service/app configuration unless provided.
			 */
			'dtWeekday' => function (int $isoWeekday, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException(
						"Datetime service not available. Ensure the Datetime provider is registered in /config/providers.php."
					);
				}
				return $this->app->datetime->weekday($isoWeekday, $form, $locale);
			},


			/**
			 * URL helper (joins base_url + path + optional query).
			 * Usage: {{ $url('/member/home.html') }} or {{ url('path', {'a': 1}) }}
			 */
			'url' => function (string $path = '', array $query = [] ) use ($baseUrl): string {
				$p = '/' . \ltrim($path, '/');
				if ($query !== []) {
					$p .= '?' . \http_build_query($query);
				}
				return \rtrim($baseUrl, '/') . $p;
			},


			/**
			 * Asset helper with optional cache-busting.
			 * Keep it cheap: default is static version (from cfg) or none.
			 * Usage: {{ $asset('/assets/app.css') }}
			 */
			'asset' => function (string $path, ?string $version = null) use ($baseUrl, $cfg): string {
				if (\preg_match('~^https?://~i', $path)) {
					return $path; // already absolute
				}
				$url = \rtrim($baseUrl, '/') . '/' . \ltrim($path, '/');
				$ver = $version ?? (string)($cfg->view->asset_version ?? '');
				return $ver !== '' ? $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . \rawurlencode($ver) : $url;
			},


			/**
			 * Does a service id exist?
			 * Usage (logic blocks): {% if $hasService('auth') %} ... {% endif %}
			 */
			'hasService' => function (string $id): bool {
				return $this->app->hasService($id);
			},


			/**
			 * Is a vendor/package effectively present (from services/routes)?
			 * Usage: {% if $hasPackage('citomni/auth') %} ... {% endif %}
			 */
			'hasPackage' => function (string $slug): bool {
				return $this->app->hasPackage($slug);
			},


			/**
			 * CSRF hidden input field (safe no-op if security service is absent).
			 * Usage: {{{ $csrfField() }}}  (triple braces → no HTML escaping)
			 */
			'csrfField' => function (): string {
				$svc = \method_exists($this->app, 'hasService') ? 'hasService' : 'has';
				if ($this->app->{$svc}('security') && \method_exists($this->app->security, 'csrfHiddenInput')) {
					return (string)$this->app->security->csrfHiddenInput();
				}
				return '';
			},


			/**
			 * Current request path (lazy: only resolves if template asks).
			 * Usage: {{ $currentPath() }}
			 */
			'currentPath' => function (): string {
				// Only instantiate request service if called
				return $this->app->request->path();
			},


			/**
			 * Role helper (property-style and labels).
			 * Supports role checks and label lookups via RoleGate.
			 *
			 * Usage in template:
			 *   {{ $role('is', 'admin') }}         // true if current user is admin
			 *   {{ $role('any', 'manager','op') }} // true if current role matches any
			 *   {{ $role('label') }}               // localized label for current user
			 *   {{ $role('labelOf', 9) }}          // label for specific id
			 *   {{ $role('labels') }}              // map of id => label
			 */
			'role' => function (string $fn, mixed ...$args) {
				$gate = $this->app->role;
				switch ($fn) {
					case 'label':   return $gate->label(...$args);           // $role('label')
					case 'labelOf': return $gate->labelOf(...$args);         // $role('labelOf', 9)
					case 'labels':  return $gate->labels(...$args);          // $role('labels')
					case 'is':      return $gate->__get((string)$args[0]);   // $role('is','admin')
					case 'any':     return $gate->any(...$args);             // $role('any','manager','operator')
					default:
						if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev') {
							throw new \InvalidArgumentException("Unknown role helper '{$fn}'.");
						}
						return false;
				}
			},

		];
	}


	/**
	 * Resolve the absolute templates root for a given vendor/package layer.
	 *
	 * Validates the slug strictly (guards against traversal), then builds:
	 *   <APP_ROOT>/vendor/{vendor}/{package}/templates
	 *
	 * @param string $layer Slug "vendor/package" (e.g., "citomni/auth").
	 * @return string Absolute filesystem path to the templates directory.
	 *
	 * @example Resolve and render
	 *  $root = $this->resolveVendorTemplateRoot('citomni/auth');
	 *  // LiteView::render('public/login.html', $root, ...);
	 */
	private function resolveVendorTemplateRoot(string $layer): string {
		$slug = \trim($layer, '/');
		if (!\preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~i', $slug)) {
			throw new \InvalidArgumentException(
				"Invalid template layer '{$layer}'. Expected 'vendor/package', e.g. 'citomni/auth'."
			);
		}
		return \CITOMNI_APP_PATH . '/vendor/' . $slug . '/templates';
	}
	
	
	/**
	 * printViewVars: Dump final template vars before output when invoked.
	 *
	 * Behavior:
	 * - Only emits on dev/stage; noop in prod.
	 * - Wrapped in HTML comments so the DOM is not polluted.
	 * - Depth-limited normalization; closures/objects/resources are annotated.
	 *
	 * Trigger:
	 * - Only runs when the query string contains "_viewvars".
	 *
	 * Placement:
	 * - Output is emitted before the template render to ensure visibility in HTML.
	 *
	 * @param array<string,mixed> $vars Final payload passed to LiteView.
	 */
	private function printViewVars(array $vars): void {
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';
		if ($env !== 'dev' && $env !== 'stage') {
			return;
		}

		$seen = new \SplObjectStorage();
		$maxDepth = 10;

		$normalize = function (mixed $v, int $depth = 0) use (&$normalize, $seen, $maxDepth) {
			if ($depth >= $maxDepth) {
				return '[depth-limit]';
			}
			if (\is_array($v)) {
				$out = [];
				foreach ($v as $k => $vv) {
					$out[$k] = $normalize($vv, $depth + 1);
				}
				return $out;
			}
			if ($v instanceof \Closure) {
				return '[closure]';
			}
			if (\is_object($v)) {
				if ($seen->contains($v)) {
					return '[' . \get_debug_type($v) . ' (seen)]';
				}
				$seen->attach($v);
				$out = ['__class' => \get_class($v)];
				$props = [];
				foreach (\get_object_vars($v) as $k => $vv) {
					$props[$k] = $normalize($vv, $depth + 1);
				}
				if ($props !== []) {
					$out['props'] = $props;
				}
				return $out;
			}
			if (\is_resource($v)) {
				return '[resource:' . \get_resource_type($v) . ']';
			}
			return $v;
		};

		$normalized = $normalize($vars);

		echo "<!--\n=== CitOmni View Vars (environment: {$env}) ===\n";
		\print_r($normalized);
		echo "\n=== End View Vars ===\n-->\n";
	}

	
	
}
