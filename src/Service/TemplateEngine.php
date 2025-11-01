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
 * TemplateEngine: Deterministic multi-layer template rendering for HTTP UI.
 *
 * Responsibilities:
 * - Render a template identified as "path@layer" where "layer" is a named template
 *   root (e.g. "app", "citomni/admin", "aserno/byportal").
 * - Support layout inheritance via `{% extends "layout@layer" %}` and named
 *   `{% block %}` / `{% yield %}` regions, even across layers.
 * - Support partial reuse via `{% include "partial@layer" %}`, even across layers.
 * - Merge globals, dynamic (per-request) vars, and controller data for each render.
 *   Precedence: controller data > dynamic vars > globals.
 * - Expose helpers to templates (`$url`, `$asset`, `$txt`, `$dt`, `$role`, etc.)
 *   as closures bound to the current App.
 * - Support inline PHP tags `{? ... ?}` and `{?= ... ?}`. Inline PHP is enabled
 *   by default and can be disabled via config/constructor options.
 * - Compile templates to disk cache under /var/cache for performance. Cache keys
 *   incorporate both "path" and "layer".
 *
 * Configuration keys read (cfg->view):
 *
 * - template_layers (array<string,string>)
 *     Map of logical layer => absolute template dir.
 *     Example:
 *       'app'             => '/var/www/app/templates'
 *       'citomni/admin'   => '/var/www/app/vendor/citomni/admin/templates'
 *
 * - cache_enabled (bool)
 *     Reuse compiled templates between requests if mtimes haven't changed.
 *
 * - trim_whitespace (bool)
 *     Collapse redundant whitespace outside of sensitive tags (<pre>, <code>, etc.).
 *
 * - remove_html_comments (bool)
 *     Strip ordinary HTML comments ("<!-- ... -->") from final output.
 *
 * - allow_php_tags (bool)
 *     Allow `{? ... ?}` / `{?= ... ?}` inline-php blocks in templates.
 *
 * - asset_version (string)
 *     Global cache-busting token appended by $asset().
 *
 * - marketing_scripts (string)
 *     Raw HTML (analytics, tracking) injected into globals as $marketing_scripts.
 *
 * - icons (array<string,string>)
 *     Map icon name => inline SVG string. Used by $icon('name').
 *
 * - vars (array<string,array>)
 *     Declarative, path-scoped view variables.
 *     Each key is the final variable name that will appear in templates
 *     (e.g. "header", "admin_nav", ...). Each entry decides:
 *       - whether it's 'static' or 'dynamic' (`type`),
 *       - where the data comes from (`source`),
 *       - and which request paths it should apply to (`include` / `exclude`).
 *
 *     We compile this into $this->compiledVars with ready-to-use regexes so that,
 *     for a given request path, we can cheaply inject:
 *       - static data blobs (menus, etc.)
 *       - dynamic provider output (header models, etc.).
 *
 * Behavior:
 * - render() prints final HTML; renderToString() returns it as string.
 * - file references are ALWAYS "relative/path.html@layer". There is no implicit layer.
 *   If the layer slug is invalid or unknown, we fail fast.
 * - Whitespace/HTML comment stripping and inline-PHP parsing happen at compile time.
 * - Cache invalidation is timestamp-based: source and its dependencies (extends/includes).
 *   If newer than compiled cache, we recompile atomically and swap.
 *
 * Notes:
 * - This service is instantiated once per request/process.
 * - init() normalizes config into cheap, immutable scalars/arrays on the object.
 * - We do not catch exceptions globally; failures bubble to the global error handler.
 *
 * Typical usage:
 *   // Controller: direct output
 *   $this->app->tplEngine->render('member/home.html@app', [
 *   	'title' => 'Mit område',
 *   ]);
 *
 *   // Controller: capture as string (for email body, etc.)
 *   $html = $this->app->tplEngine->renderToString('mail/reset.html@citomni/auth', [
 *   	'user'  => $userRow,
 *   	'token' => $token,
 *   ]);
 *
 * Debugging:
 * - If the incoming request query contains "_viewvars" AND the environment is dev/stage,
 *   we emit an HTML comment dump of the final var payload before rendering.
 *
 * @throws \InvalidArgumentException On malformed template reference ("foo@bar").
 * @throws \RuntimeException On illegal path traversal or missing template file.
 */
final class TemplateEngine extends BaseService {

	/** @var array<string,string> Immutable map layer => absolute template dir */
	private array $layersMap = [];

	/** @var string Absolute cache dir path (CITOMNI_APP_PATH . "/var/cache") */
	private string $cacheDir = '';

	/** @var bool */
	private bool $cacheEnabled = false;

	/** @var bool */
	private bool $trimWhitespace = false;

	/** @var bool */
	private bool $removeHtmlComments = false;

	/** @var bool */
	private bool $allowPhpTags = true;

	/** @var string */
	private string $assetVersion = '';

	/** @var array<string,string> Inline SVG icons (iconId => '<svg...>') */
	private array $icons = [];

	/** @var string Raw HTML snippet (analytics, marketing tags) injected globally */
	private string $marketingScripts = '';

	/**
	 * Precompiled, path-scoped template vars from cfg->view->vars.
	 *
	 * Shape:
	 *   [
	 *     'header' => [
	 *       'var'   => 'header',
	 *       'type'  => 'dynamic',
	 *       'call'  => ['class' => \Foo\Model\SitewideModel::class, 'method' => 'header'],
	 *       'ire'   => ['/^\/$/', '/^\/nyheder\/.*\/'], // compiled include regexes
	 *       'ere'   => ['/^\/admin\//'],               // compiled exclude regexes
	 *     ],
	 *     'admin_nav' => [
	 *       'var'   => 'admin_nav',
	 *       'type'  => 'static',
	 *       'data'  => [...],
	 *       'ire'   => ['/^\/admin\//'],
	 *       'ere'   => [],
	 *     ],
	 *     ...
	 *   ]
	 *
	 * Notes:
	 * - Keys are the final template variable names ("header", "footer", "admin_nav", ...).
	 * - Last provider wins on key collision (deterministic CitOmni merge semantics).
	 *
	 * @var array<string, array{
	 *    var:string,
	 *    type:'static'|'dynamic',
	 *    data?:mixed,
	 *    call?:mixed,
	 *    ire:array<int,string>,
	 *    ere:array<int,string>,
	 * }>
	 */
	private array $compiledVars = [];

	/** @var array<string,mixed>|null Cached global vars+helpers for this request */
	private ?array $globals = null;



	/**
	 * One-time initialization for this request/process.
	 *
	 * Behavior:
	 * - Reads relevant `cfg->view` nodes and snapshots them into cheap local scalars/arrays.
	 *   We do this up front so later hot paths do not keep walking the cfg wrapper.
	 * - Normalizes associative cfg maps (`template_layers`, `vars`, `icons`) into plain arrays.
	 *   App::buildConfig() in the kernel already produced one merged config array
	 *   (vendor -> providers -> app -> env; last wins). Each subtree (like cfg->view)
	 *   is exposed through CitOmni\Kernel\Cfg, and we flatten those nodes here so we
	 *   can access them with simple array lookups at runtime.
	 * - Pre-compiles cfg->view->vars (static + dynamic scoped vars) into $this->compiledVars
	 *   with ready-to-use regex filters (include/exclude).
	 * - Captures feature flags (whitespace trimming, comment stripping, etc.).
	 * - Resolves deterministic cache dir.
	 *
	 * Notes:
	 * - We do not realpath() template layer dirs here. Boundary checks happen in loadSource()
	 *   at file-read time. That keeps init() cheap and lets providers mount templates via
	 *   symlinks without us "helpfully" rewriting paths.
	 *
	 * @return void
	 */
	protected function init(): void {
		
		// Snapshot cfg root for the view layer.
		// If cfg->view is missing, fall back to an empty stdClass-like object for convenience.
		$viewCfg = $this->app->cfg->view ?? (object)[];

		// Snapshot and clear service options.
		// Rationale:
		// - The service can be constructed with "options" from the service map.
		// - We read them once here (they override cfg values).
		// - Then we drop $this->options to avoid carrying mutable state.
		$opt = $this->options;
		$this->options = [];


		// ---------------------------------------------------------------------------------
		// 1) Template layers map (layer => absolute dir)
		//
		// - view.template_layers is expected to be something like:
		//     [
		//       'app'              => '/var/www/myapp/templates',
		//       'citomni/admin'    => '/var/www/myapp/vendor/citomni/admin/templates',
		//       'aserno/byportal'  => '/var/www/myapp/vendor/aserno/byportal-core/templates',
		//     ]
		//
		// - normalizeCfgMap() just flattens either a plain PHP array or a Cfg node
		//   (i.e. something with ->toArray()) into a regular associative array.
		//   We want a raw ['layer' => '/abs/path'] map here so runtime lookups are cheap.
		//
		// - We validate each layer slug now (fail fast). "app" is allowed as a special case.
		//   Everything else must look like "vendor/package".
		//
		// - We do NOT realpath() here; loadSource() enforces that templates can't escape
		//   their configured root using ../ tricks. Doing it lazily keeps init() cheap.
		// ---------------------------------------------------------------------------------
		$rawLayers = $this->normalizeCfgMap($viewCfg->template_layers ?? []);
		foreach ($rawLayers as $layerKey => $pathVal) {
			$layer = (string)$layerKey;

			// Validate layer slug: "app" or "vendor/package" (letters, digits, dot, dash, underscore).
			if ($layer !== 'app' && !\preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~i', $layer)) {
				throw new \InvalidArgumentException("TemplateEngine: Invalid layer slug '{$layer}'.");
			}

			// The configured dir must be a non-empty string.
			if (!\is_string($pathVal) || $pathVal === '') {
				throw new \InvalidArgumentException(
					"TemplateEngine: template_layers['{$layer}'] must be a non-empty string (absolute directory)."
				);
			}

			// We just trim trailing slashes here. We do NOT resolve symlinks at this stage.
			$abs = \rtrim($pathVal, "/\\");
			$this->layersMap[$layer] = $abs;
		}


		// ---------------------------------------------------------------------------------
		// 2) Rendering / compilation flags & toggles
		//
		// - Some flags can be overridden via service-map options when wiring the service.
		//   Options win over cfg->view (predictable "last wins" semantics).
		//
		// - We coerce to bool so later checks are cheap.
		//   This avoids repeatedly touching cfg->view in hot render paths.
		// ---------------------------------------------------------------------------------
		$this->cacheEnabled       = (bool)($opt['cache_enabled']        ?? $viewCfg->cache_enabled        ?? false);
		$this->trimWhitespace     = (bool)($opt['trim_whitespace']      ?? $viewCfg->trim_whitespace      ?? false);
		$this->removeHtmlComments = (bool)($opt['remove_html_comments'] ?? $viewCfg->remove_html_comments ?? false);
		$this->allowPhpTags       = (bool)($opt['allow_php_tags']       ?? $viewCfg->allow_php_tags       ?? true);


		// ---------------------------------------------------------------------------------
		// 3) Passthrough view config (asset versioning, marketing snippets, globals)
		//
		// - assetVersion is used by the $asset() helper for cache-busting (?v=...).
		// - marketingScripts is dumped into all templates (e.g. analytics tags).
		// - icons is also normalized into a flat associative array (id => raw <svg>).
		//   This feeds the $icon('home') helper exposed in buildGlobals().
		// ---------------------------------------------------------------------------------
		$this->assetVersion     = (string)($opt['asset_version']      ?? $viewCfg->asset_version      ?? '');
		$this->marketingScripts = (string)($viewCfg->marketing_scripts ?? '');

		// Inline SVG icons (string id => SVG markup). Used by $icon('foo') in templates.
		$this->icons            = $this->normalizeCfgMap($viewCfg->icons ?? []);


		// 4) Pre-compile scoped view vars (static + dynamic)
		//
		// cfg->view->vars is expected to be an associative map:
		//   varName => [
		//     'type'    => 'static' | 'dynamic',
		//     'include' => [...],
		//     'exclude' => [...],
		//     'source'  => ... (literal data OR callable descriptor)
		//   ]
		//
		// We normalize that map up front and compile it into $this->compiledVars
		// so that at request time we only do cheap preg_match() and (maybe) a single
		// provider call per var.
		$this->compiledVars = []; // array<string,array{...}>

		// Flatten cfg->view->vars (Cfg node or plain array) into a plain associative array
		$varsCfg = $this->normalizeCfgMap($viewCfg->vars ?? []);
		
		foreach ($varsCfg as $varName => $row) {
			if (!\is_array($row) || $row === []) {
				continue;
			}

			// We trust the array key as the var name
			$varName = (string)$varName;
			$type    = (string)($row['type'] ?? '');
			$source  = $row['source'] ?? null;

			if ($varName === '' || $type === '' || $source === null) {
				throw new \RuntimeException(
					"TemplateEngine: view.vars['{$varName}'] requires non-empty 'type' and 'source'."
				);
			}

			// Normalize include/exclude lists (can be explicit PCRE "~^...~" or globs "/foo/*")
			$inc = \array_values((array)($row['include'] ?? []));
			$exc = \array_values((array)($row['exclude'] ?? []));

			// Precompile patterns now so runtime only does preg_match on anchored regex.
			$ire = \array_map([$this, 'compilePathMatcher'], $inc);
			$ere = \array_map([$this, 'compilePathMatcher'], $exc);

			if ($type === 'static') {
				// Here 'source' is literal data (array/string/whatever),
				// and we will inject it directly at request time.
				$this->compiledVars[$varName] = [
					'var'   => $varName,
					'type'  => 'static',
					'data'  => $source,
					'ire'   => $ire,
					'ere'   => $ere,
				];
				continue;
			}

			if ($type === 'dynamic') {
				// Here 'source' must describe a callable:
				// - "FQCN::method"
				// - ['class' => FQCN, 'method' => 'm']
				// - ['service' => 'id', 'method' => 'm']
				//
				// We'll just store it as 'call' and reuse invokeProvider() at runtime.
				$this->compiledVars[$varName] = [
					'var'   => $varName,
					'type'  => 'dynamic',
					'call'  => $source, // <- shapes: "FQCN::m", ['class'=>..], ['service'=>..]
					'ire'   => $ire,
					'ere'   => $ere,
				];
				continue;
			}

			throw new \RuntimeException(
				"TemplateEngine: Unsupported view.vars type '{$type}' for var '{$varName}'."
			);
		}


		// ---------------------------------------------------------------------------------
		// 5) Cache dir
		//
		// - All compiled templates are written to CITOMNI_APP_PATH . '/var/cache'.
		// - We don't allow overriding this via config because predictable layout
		//   is part of CitOmni's "no guessing" philosophy (and deploy tooling
		//   assumes this location).
		// ---------------------------------------------------------------------------------
		$this->cacheDir = \CITOMNI_APP_PATH . '/var/cache';
	}



	/**
	 * Render a template "file@layer" directly to output.
	 *
	 * Behavior:
	 * - Builds final vars for this request/view call.
	 * - Optionally emits a debug dump of the final variable payload (if the
	 *   request query contains `_viewvars` and the environment is `dev` or `stage`).
	 * - Compiles the requested template (resolving `{% extends %}` and `{% include %}` across
	 *   layers) to a cached PHP file and then requires it.
	 *
	 * @param string $ref "relative/path.html@layer".
	 * @param array<string,mixed> $data Controller-provided data (highest precedence).
	 * @return void
	 */
	public function render(string $ref, array $data = []): void {
		$vars = $this->buildFinalVars($data);

		if ($this->app->request->get('_viewvars') !== null) {
			$this->printViewVars($vars);
		}

		$file = $this->compile($ref);
		\extract($vars, \EXTR_SKIP);
		require $file;
	}


	/**
	 * Render a template "file@layer" into a string.
	 *
	 * Typical usage:
	 *   $html = $this->app->tplEngine->renderToString('mail/reset.html@citomni/auth', [...]);
	 *
	 * @param string $ref
	 * @param array<string,mixed> $data
	 * @return string Rendered HTML.
	 */
	public function renderToString(string $ref, array $data = []): string {
		$vars = $this->buildFinalVars($data);

		// Debug output for _viewvars is not injected here automatically,
		// because callers usually want clean HTML output (emails etc.).
		$file = $this->compile($ref);

		\ob_start();
		try {
			\extract($vars, \EXTR_SKIP);
			require $file;
			return (string)\ob_get_contents();
		} finally {
			\ob_end_clean();
		}
	}


	/**
	 * Merge globals, dynamic vars, and controller data.
	 *
	 * Precedence (left wins on key collision):
	 * - $data  (controller-supplied vars) >
	 * - $scoped   (dynamic per-request vars from cfg.view.vars) >
	 * - $glb   (globals)
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function buildFinalVars(array $data): array {
		$glb = $this->globals ??= $this->buildGlobals();

		$pathRel = $this->appRelativePath($this->app->request->path());
		$scoped  = $this->buildScopedVarsForPath($pathRel);

		return $data + $scoped + $glb;
	}


	/**
	 * Build globals (memoized once per request).
	 *
	 * Exposes (keys in the template scope):
	 * - Scalars: app_name, base_url, public_root_url, language, charset,
	 *   marketing_scripts, vars.
	 * - Security flags: csrf_protection, honeypot_protection,
	 *   form_action_switching, captcha_protection.
	 * - Environment info: env => { name: string, dev: bool }.
	 * - Lazy helpers (closures): $txt, $dt, $dtNow, $dtMonth, $dtWeekday, $url,
	 *   $asset, $hasService, $hasPackage, $csrfField, $currentPath, $role.
	 *
	 * Notes:
	 * - Closures are bound to this service instance and call into $this->app on-demand.
	 * - $asset() applies cache-busting (cfg->view.asset_version) and keeps existing
	 *   query strings intact.
	 *
	 * @return array<string,mixed>
	 */
	private function buildGlobals(): array {
		$cfg = $this->app->cfg;

		$baseUrl = (string)($cfg->http->base_url
			?? (\defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : ''));

		$publicUrl = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? (string)\CITOMNI_PUBLIC_ROOT_URL
			: $baseUrl;

		return [
			// --- Identity & locale scalars (cheap values used directly in templates)

			'app_name'         => (string)$cfg->identity->app_name,
			'base_url'         => $baseUrl,
			'public_root_url'  => $publicUrl,
			'language'         => (string)$cfg->locale->language,
			'charset'          => (string)$cfg->locale->charset,

			// --- View passthroughs (global marketing snippets etc.)

			'marketing_scripts'=> $this->marketingScripts,

			// --- Security feature flags (informational for UI text, badges, warnings etc.)

			'csrf_protection'       => (bool)$cfg->security->csrf_protection,
			'honeypot_protection'   => (bool)$cfg->security->honeypot_protection,
			'form_action_switching' => (bool)$cfg->security->form_action_switching,
			'captcha_protection'    => (bool)$cfg->security->captcha_protection,

			// --- Environment info (e.g. show debug panels only in dev)

			'env' => [
				'name' => \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod',
				'dev'  => \defined('CITOMNI_ENVIRONMENT') ? (\CITOMNI_ENVIRONMENT === 'dev') : false,
			],


			/**
			 * $txt: Localized text lookup with optional fallback/default.
			 *
			 * Typical usage:
			 *   {{ $txt('login_title', 'auth_login', 'citomni/auth') }}
			 *   {{ $txt('greeting', 'homepage', null, 'Hello guest') }}
			 *
			 * With replacements:
			 *   {{ $txt('welcome_name', 'homepage', 'citomni/auth', 'Hi', {'NAME': $user['first_name']}) }}
			 *
			 * Notes:
			 * - $file is typically the logical language file (without ".php").
			 * - $layer is optional; pass e.g. "citomni/auth" to read provider language.
			 * - If key is missing, $default is returned.
			 *
			 * Throws:
			 * - \RuntimeException if text service is unavailable (misconfigured app).
			 */
			'txt' => function (string $key, string $file, ?string $layer = null, string $default = '', array $vars = []) {
				if (!$this->app->hasService('txt') || !$this->app->hasPackage('citomni/infrastructure')) {
					throw new \RuntimeException(
						"Text service not available. Install 'citomni/infrastructure' and register 'txt' in /config/providers.php."
					);
				}
				return $this->app->txt->get($key, $file, $layer, $default, $vars);
			},


			/**
			 * $dt: Format a specific moment in time using Intl patterns.
			 *
			 * Typical usage:
			 *   {{ $dt('2025-10-25 16:30', 'yyyy-MM-dd HH:mm') }}
			 *   {{ $dt(1735123456, 'EEEE d. MMMM yyyy') }}          {# Unix ts -> localized #}
			 *   {{ $dt($user.created_at, 'yyyy-MM-dd HH:mm') }}     {# DB datetime string #}
			 *
			 * With overrides:
			 *   {{ $dt(null, 'yyyy-MM-dd', 'Europe/Copenhagen', 'da_DK') }}
			 *   {# "now" in explicit tz/locale #}
			 *
			 * Params:
			 * - $when can be null|string|int|\DateTimeInterface
			 *   null = "now" in the app's timezone.
			 * - $pattern is an ICU date/time pattern string.
			 * - $tzName optional timezone override.
			 * - $locale optional locale override (e.g. "en_US").
			 *
			 * Throws:
			 * - \RuntimeException if datetime service is missing.
			 */
			'dt' => function (null|string|int|\DateTimeInterface $when, string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->format($when, $pattern, $tzName, $locale);
			},


			/**
			 * $dtNow: Format "now" with optional tz/locale override.
			 *
			 * Typical usage:
			 *   {{ $dtNow('EEEE d. MMMM yyyy HH:mm') }}
			 *   {{ $dtNow('yyyy-MM-dd HH:mm', 'America/New_York', 'en_US') }}
			 *
			 * Throws:
			 * - \RuntimeException if datetime service is missing.
			 */
			'dtNow' => function (string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->now($pattern, $tzName, $locale);
			},


			/**
			 * $dtMonth: Localized month name from 1..12.
			 *
			 * Typical usage:
			 *   {{ $dtMonth(10) }}                {# "October" / "oktober" #}
			 *   {{ $dtMonth(10, 'short') }}       {# "Oct" / "okt."       #}
			 *   {{ $dtMonth(10, 'narrow') }}      {# "O"                  #}
			 *   {{ $dtMonth(10, 'short', 'en_US') }}
			 *
			 * Params:
			 * - $form: 'full'|'short'|'narrow' (null defaults to 'full').
			 * - $locale override optional.
			 *
			 * Throws:
			 * - \RuntimeException if datetime service is missing.
			 */
			'dtMonth' => function (int $month, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->month($month, $form, $locale);
			},


			/**
			 * $dtWeekday: Localized weekday name from ISO weekday (1=Mon..7=Sun).
			 *
			 * Typical usage:
			 *   {{ $dtWeekday(1) }}                      {# "Monday" / "mandag" #}
			 *   {{ $dtWeekday(5, 'short') }}             {# "Fri" / "fre."      #}
			 *   {{ $dtWeekday(6, 'narrow') }}            {# "F" / "l" etc.      #}
			 *   {{ $dtWeekday(6, 'full', 'da_DK') }}     {# Danish full form    #}
			 *
			 * Params:
			 * - $form: 'full'|'short'|'narrow' (null => 'full').
			 * - $locale override optional.
			 *
			 * Throws:
			 * - \RuntimeException if datetime service is missing.
			 */
			'dtWeekday' => function (int $isoWeekday, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->weekday($isoWeekday, $form, $locale);
			},


			/**
			 * $url: Build an absolute URL under the app's base_url.
			 *
			 * Typical usage:
			 *   <a href="{{ $url('/member/profile') }}">Profil</a>
			 *   <form action="{{ $url('/login') }}" method="post">
			 *
			 * With query params:
			 *   <a href="{{ $url('/search', {'q': 'cochem', 'page': 2}) }}">...</a>
			 *
			 * Notes:
			 * - $path may be "/foo" or "foo"; it will be normalized.
			 * - $query is turned into ?a=1&b=2 (http_build_query).
			 */
			'url' => function (string $path = '', array $query = []) use ($baseUrl): string {
				$p = '/' . \ltrim($path, '/');
				if ($query !== []) {
					$p .= '?' . \http_build_query($query);
				}
				return \rtrim($baseUrl, '/') . $p;
			},


			/**
			 * $asset: Build an absolute asset URL with optional cache-busting.
			 *
			 * Typical usage:
			 *   <link rel="stylesheet" href="{{ $asset('/assets/app.css') }}">
			 *   <script src="{{ $asset('/assets/app.js', 'inline-test-ver') }}"></script>
			 *
			 * Behavior:
			 * - If $path is already absolute (starts with http/https), it's returned unchanged.
			 * - Otherwise we prefix with base_url and append ?v={asset_version} if set.
			 * - If the URL already has a "?", we append "&v=" instead.
			 *
			 * Notes:
			 * - $version param overrides the global cfg->view.asset_version for that call.
			 */
			'asset' => function (string $path, ?string $version = null) use ($baseUrl) {
				if (\preg_match('~^https?://~i', $path)) {
					return $path;
				}
				$url = \rtrim($baseUrl, '/') . '/' . \ltrim($path, '/');
				$ver = $version ?? $this->assetVersion;
				return $ver !== ''
					? $url . (\str_contains($url, '?') ? '&' : '?') . 'v=' . \rawurlencode($ver)
					: $url;
			},


			/**
			 * $hasService: Check if a service ID exists on $this->app.
			 *
			 * Typical usage:
			 *   {% if $hasService('auth') %}
			 *   	<a href="{{ $url('/member/profile') }}">Min profil</a>
			 *   {% endif %}
			 *
			 * Notes:
			 * - This is great for hiding menu items / admin-only panels if a provider
			 *   is not installed in a given project build.
			 */
			'hasService' => function (string $id): bool {
				return $this->app->hasService($id);
			},


			/**
			 * $hasPackage: Check if a provider/package is installed.
			 *
			 * Typical usage:
			 *   {% if $hasPackage('citomni/auth') %}
			 *   	<li><a href="{{ $url('/member/login') }}">Log ind</a></li>
			 *   {% endif %}
			 *
			 * Notes:
			 * - Internally this calls $this->app->hasPackage(), which inspects what
			 *   was registered via providers.
			 */
			'hasPackage' => function (string $slug): bool {
				return $this->app->hasPackage($slug);
			},


			/**
			 * $csrfField: Output a hidden <input> with CSRF token.
			 *
			 * Typical usage in forms:
			 *   <form method="post" action="{{ $url('/login') }}">
			 *   	{{{ $csrfField() }}}
			 *   	<!-- more fields -->
			 *   </form>
			 *
			 * Notes:
			 * - Use TRIPLE braces when rendering ({{{ ... }}}) so we don't escape
			 *   the actual <input> element.
			 * - Returns "" (empty string) if security service is missing.
			 */
			'csrfField' => function (): string {
				if ($this->app->hasService('security') && \method_exists($this->app->security, 'csrfHiddenInput')) {
					return (string)$this->app->security->csrfHiddenInput();
				}
				return '';
			},


			/**
			 * $currentPath: Get the current request path (what the browser asked for).
			 *
			 * Typical usage:
			 *   <li class="{% if $currentPath() === '/member/profile' %}active{% endif %}">
			 *   	<a href="{{ $url('/member/profile') }}">Profil</a>
			 *   </li>
			 *
			 * Notes:
			 * - Returns whatever Request::path() currently reports, e.g. "/member/profile".
			 * - Good for "active" menu highlighting.
			 */
			'currentPath' => function (): string {
				return $this->app->request->path();
			},


			/**
			 * $icon: Inline SVG icon lookup.
			 *
			 * Typical usage (TRIPLE braces for raw HTML):
			 *   {{{ $icon('home') }}}
			 *   <button class="nav-btn" aria-label="Profile">{{{ $icon('user') }}}</button>
			 *
			 * Behavior:
			 * - Returns a `<svg ...>` string with no wrapper span/div.
			 * - SVGs are shipped with `stroke="currentColor"` (or fill), so you can
			 *   tint via CSS `color:` on the parent.
			 *
			 * Errors:
			 * - In dev/stage, unknown icon keys throw \RuntimeException (fail fast).
			 * - In prod, unknown icon keys return '' (quietly hide the icon instead
			 *   of nuking the whole page for a missing cosmetic asset).
			 */
			'icon' => function (string $name): string {
				
				$key = (string)$name;
				if (isset($this->icons[$key])) {
					return $this->icons[$key];
				}

				$isDev = \defined('CITOMNI_ENVIRONMENT') && (\CITOMNI_ENVIRONMENT === 'dev' || \CITOMNI_ENVIRONMENT === 'stage');
				if ($isDev) {
					throw new \RuntimeException("Icon '{$key}' not found in view.icons.");
				}
				return '';
			},


			/**
			 * $role: Role/permission helper for templates (proxy to RoleGate).
			 *
			 * Typical usage:
			 *
			 *   {# Check exact role by name #}
			 *   {% if $role('is', 'admin') %}
			 *   	<p>Hi admin.</p>
			 *   {% endif %}
			 *
			 *   {# Check multiple roles (OR) #}
			 *   {% if $role('any', 'manager', 'operator') %}
			 *   	<a href="{{ $url('/staff/tools') }}">Staff tools</a>
			 *   {% endif %}
			 *
			 *   {# Threshold / hierarchy check (>= operator) #}
			 *   {% if $role('atLeast', 'operator') %}
			 *   	<a href="{{ $url('/admin') }}">Admin panel</a>
			 *   {% endif %}
			 *
			 *   {# Numeric rank (tinyint) #}
			 *   {{ $role('rank') }}   {# e.g. 9 for admin #}
			 *
			 *   {# Localized label for current user #}
			 *   {{ $role('label') }}
			 *
			 *   {# Localized label for a given role id #}
			 *   {{ $role('labelOf', 9) }}
			 *
			 *   {# Map of all role labels (id => label) #}
			 *   {% foreach ($role('labels') as $rid => $lbl) %}
			 *   	<option value="{{ $rid }}">{{ $lbl }}</option>
			 *   {% endforeach %}
			 *
			 * Behavior:
			 * - 'is'       => strict equality by role name ("admin").
			 * - 'any'      => OR check across provided names/ids.
			 * - 'atLeast'  => hierarchy check ("operator or higher").
			 * - 'rank'     => numeric tinyint for current user.
			 * - 'label'    => localized label for current user's role.
			 * - 'labelOf'  => label for a provided role id.
			 * - 'labels'   => {id => label} map of all roles.
			 *
			 * Throws:
			 * - \RuntimeException if role service isn't available.
			 * - \InvalidArgumentException in dev if you call with unknown fn.
			 */
			'role' => function (string $fn, mixed ...$args) {
				if (!$this->app->hasService('role') || !$this->app->hasPackage('citomni/auth')) {
					throw new \RuntimeException(
						"Role service not available. Install 'citomni/auth' and register 'RoleGate' as 'role'."
					);
				}
				$gate = $this->app->role;

				switch ($fn) {
					case 'label':
						return $gate->label(...$args);
					case 'labelOf':
						return $gate->labelOf(...$args);
					case 'labels':
						return $gate->labels(...$args);

					case 'is':
						return $gate->__get((string)$args[0]);
					case 'any':
						return $gate->any(...$args);

					case 'atLeast':
						return $gate->atLeast(...$args);

					case 'rank':
						return $gate->rank();

					default:
						$isDevLike = \defined('CITOMNI_ENVIRONMENT')
							&& (\CITOMNI_ENVIRONMENT === 'dev' || \CITOMNI_ENVIRONMENT === 'stage');
						if ($isDevLike) {
							throw new \InvalidArgumentException("Unknown role helper '{$fn}'.");
						}
						return false;
				}
			},
		];
	}
	
	
	/**
	 * buildScopedVarsForPath: Resolve all configured view vars (static+dynamic)
	 * that apply to the current request path.
	 *
	 * @param string $relPath Normalized app-relative path (e.g. "/admin/users.html").
	 * @return array<string,mixed> Map of varName => value for this request.
	 */
	private function buildScopedVarsForPath(string $relPath): array {
		if ($this->compiledVars === []) {
			return [];
		}

		$out = [];

		foreach ($this->compiledVars as $varName => $v) {
			// Does this var apply on this path?
			if (!$this->pathMatches($relPath, $v['ire'], $v['ere'])) {
				continue;
			}

			if ($v['type'] === 'static') {
				// Just copy the data directly
				$out[$varName] = $v['data'];
				continue;
			}

			// dynamic
			$out[$varName] = $this->invokeProvider($v['call'] ?? null, $varName);
		}

		return $out;
	}


	/**
	 * Invoke a configured provider call deterministically.
	 *
	 * This is how we resolve "dynamic" vars from cfg->view->vars.
	 * In cfg->view->vars each row can declare:
	 *
	 *   [
	 *     'var'    => 'header',        // becomes $header in template scope
	 *     'type'   => 'dynamic',       // <- this path goes through invokeProvider()
	 *     'source' => ['service' => 'sitewide', 'method' => 'header'],
	 *     ... include/exclude ...
	 *   ]
	 *
	 * During init(), we normalize that row into $this->compiledVars[]:
	 *
	 *   [
	 *     'var'  => 'header',
	 *     'type' => 'dynamic',
	 *     'call' => ['service' => 'sitewide', 'method' => 'header'],
	 *     'ire'  => [...compiled include regexes...],
	 *     'ere'  => [...compiled exclude regexes...],
	 *   ]
	 *
	 * Then at request time, buildScopedVarsForPath() says:
	 *   $out['header'] = $this->invokeProvider($v['call'], 'header');
	 *
	 * Supported forms for $call:
	 *
	 * - "FQCN::method"
	 *     Static call.
	 *     We call ClassName::method($app).
	 *     The method must exist and is expected to accept the App as its only parameter
	 *     (or at least be callable with it).
	 *
	 * - ['class' => FQCN, 'method' => 'm']
	 *     Instance call.
	 *     We instantiate new FQCN($this->app) and then call ->m() on that instance.
	 *     The method must exist on that class.
	 *
	 * - ['service' => 'id', 'method' => 'm']
	 *     Service call.
	 *     We grab $this->app->{id} (must be a registered Service on the App),
	 *     then call ->m() on that object.
	 *     The method must exist on that service.
	 *
	 * Behavior:
	 * - Validates that the referenced class/service and method actually exist.
	 * - Calls the method and returns its result (usually array/scalar data for templates).
	 * - Throws \RuntimeException if the definition is malformed, the class/service
	 *   doesn't exist, or the method is missing.
	 * - The provider is assumed to be read-only / side effect free. It is only called
	 *   if cfg->view->vars says it should run for the current request path
	 *   (include/exclude patterns already matched before we get here).
	 *
	 * Notes:
	 * - This is called once per applicable dynamic var per request (not once per render()),
	 *   so provider work should still be reasonably cheap.
	 * - We deliberately do not guess or "best effort" unknown shapes; unsupported
	 *   definitions fail fast so the developer sees the misconfiguration.
	 *
	 * @param mixed  $call    Callable definition for a dynamic var.
	 *                        Originally comes from cfg->view->vars[*]['source']
	 *                        for entries where type === 'dynamic', and was copied
	 *                        to 'call' in $this->compiledVars during init().
	 *
	 * @param string $varName Name of the dynamic variable we are trying to populate
	 *                        (used only for clearer error messages).
	 *
	 * @return mixed          Whatever the provider method returns.
	 *
	 * @throws \RuntimeException On unknown call shape or missing class/method/service.
	 */
	private function invokeProvider(mixed $call, string $varName): mixed {
		// Case 1: "FQCN::method" => call static method directly
		if (\is_string($call) && \strpos($call, '::') !== false) {
			[$cls, $m] = \explode('::', $call, 2);

			// Validate that the target method actually exists
			if (!\method_exists($cls, $m)) {
				throw new \RuntimeException("Provider method {$cls}::{$m} for var '{$varName}' not found.");
			}

			// Contract: static method must accept App $app (we pass $this->app)
			return $cls::$m($this->app);
		}

		// Case 2: ['class' => FQCN, 'method' => 'm']
		// Instantiate a fresh object of that FQCN with $app, then call ->m()
		if (\is_array($call) && isset($call['class'], $call['method'])) {
			$cls = (string)$call['class'];
			$m   = (string)$call['method'];

			// Sanity-check method existence on that class
			if (!\method_exists($cls, $m)) {
				throw new \RuntimeException("Provider method {$cls}::{$m} for var '{$varName}' not found.");
			}

			// Deterministic wiring: provider classes are expected to take ($app) in ctor
			$inst = new $cls($this->app);

			// Call the instance method and bubble return value out
			return $inst->{$m}();
		}

		// Case 3: ['service' => 'id', 'method' => 'm']
		// Reuse an already-constructed Service from $this->app and call ->m()
		if (\is_array($call) && isset($call['service'], $call['method'])) {
			$id = (string)$call['service'];
			$m  = (string)$call['method'];

			// Ensure the service actually exists in this app build
			if (!$this->app->hasService($id)) {
				throw new \RuntimeException("Service '{$id}' for var '{$varName}' is not available.");
			}

			$svc = $this->app->{$id};

			// Ensure the service exposes that method
			if (!\method_exists($svc, $m)) {
				throw new \RuntimeException("Service method {$id}::{$m} for var '{$varName}' not found.");
			}

			// Call the service method and return its result
			return $svc->{$m}();
		}

		// Anything else is unsupported (we don't guess)
		throw new \RuntimeException("Unsupported provider call definition for var '{$varName}'.");
	}


	/**
	 * appRelativePath: Normalize request path to an app-relative path.
	 *
	 * Behavior:
	 * - Derives the effective app base path from Request::baseUrl() (which already
	 *   reflects CITOMNI_PUBLIC_ROOT_URL / cfg->http->base_url).
	 * - Strips that base path prefix from the raw request path.
	 * - Normalizes multiple slashes.
	 * - Guarantees the result starts with "/" and returns "/" for the frontpage.
	 *
	 * Why:
	 * - vars_providers include/exclude patterns are matched against this
	 *   normalized, app-relative path.
	 */
	private function appRelativePath(string $rawPath): string {
		$base = $this->app->request->baseUrl();          // e.g. "https://site.tld/app/" or "/"
		$basePath = (string)\parse_url($base, \PHP_URL_PATH); // "/app/" part from base URL
		$basePath = $basePath !== '' ? '/' . \trim($basePath, '/') . '/' : '/';
		// Now $basePath is guaranteed to look like "/app/" or "/"

		// Normalize the incoming raw path from the request
		$path = $rawPath !== '' ? $rawPath : '/';
		if ($path[0] !== '/') {
			$path = '/' . $path;  // enforce leading "/"
		}

		// If app is mounted under a subdir ("/app/"), strip that prefix out so that
		// "/app/member/profile" becomes "/member/profile"
		if ($basePath !== '/') {
			$prefix = \rtrim($basePath, '/');  // "/app"
			if ($prefix !== '' && \str_starts_with($path, $prefix . '/')) {
				$path = \substr($path, \strlen($prefix)); // drop "/app"
			}
		}

		// Collapse accidental double slashes, etc.
		$path = \preg_replace('~/+~', '/', $path) ?? $path;

		// Ensure we never return "" (return "/" for frontpage)
		return $path === '' ? '/' : $path;
	}


	/**
	 * Turn a human-friendly path matcher into an anchored PCRE.
	 *
	 * Input forms:
	 * - "~...~"
	 *     Treat as a raw regex. We trust it and return it unchanged.
	 *
	 * - "*"
	 *     Match everything (equivalent to a prefix ".*").
	 *
	 * - "/"
	 *     Match only the frontpage "/".
	 *
	 * - "/foo" or "/foo/*"
	 *     Match that path prefix. "*" is treated as a wildcard suffix.
	 *     We escape everything else so that user input can't break the regex.
	 *
	 * Behavior:
	 * - Returns a valid regex string that is anchored to the start of the path (`/^.../`),
	 *   except the special "/" case which becomes `/^\/$/`.
	 * - Normalizes non-slash patterns so "news" becomes "/news" before building the regex.
	 * - If the pattern is the empty string, we return a "match nothing" regex (`/^\b\B$/`).
	 * - If the pattern looks like "~...~" (starts with "~" and ends with "~"), we assume
	 *   the caller provided a full regex and just return it.
	 *
	 * Notes:
	 * - The result is meant to be passed directly to preg_match() against an
	 *   app-relative path like "/member/profile".
	 * - We intentionally escape all literal chars and only treat "*" as a glob.
	 *   This prevents accidental regex injection from config, unless the dev
	 *   explicitly opts in with "~...~".
	 *
	 * @param string $pat Human-friendly matcher from cfg->view->vars_providers[*].include/exclude.
	 *
	 * @return string Anchored PCRE pattern.
	 */
	private function compilePathMatcher(string $pat): string {
		$pat = (string)$pat;

		if ($pat === '') {
			return '/^\b\B$/';  // empty rule => "match nothing"
		}

		// If dev provided "~...~", we assume it's already a complete regex
		if ($pat[0] === '~' && \str_ends_with($pat, '~') && \strlen($pat) >= 2) {
			return $pat;
		}

		// If pattern doesn't start with "/", treat it like a path fragment.
		// We'll normalize so "news" becomes "/news"
		if ($pat !== '*' && $pat[0] !== '/') {
			$pat = '/' . $pat;
		}

		// Special case: "/" means "frontpage only"
		if ($pat === '/') {
			return '/^\/$/';
		}

		// Escape literal chars for regex, but keep "*" as wildcard.
		// Example: "/news/*" -> '/^\/news\/.*/'
		$quoted = \preg_quote($pat, '/');      // turn "/" etc. into "\/", "*" into "\*"
		$quoted = \str_replace('\*', '.*', $quoted); // replace escaped "*" with ".*" (wildcard)

		return '/^' . $quoted . '/';           // anchor at start of string
	}


	/**
	 * Check if a request path should activate a given vars_provider.
	 *
	 * Behavior:
	 * - "Include" rules:
	 *   * If there are no include regexes, we treat that as "include everything".
	 *   * Otherwise, the path must match at least one of the include regexes.
	 *
	 * - "Exclude" rules:
	 *   * If any exclude regex matches the path, the provider is disqualified.
	 *
	 * - Returns true only if:
	 *   (included by the include rules) AND (not matched by any exclude rules).
	 *
	 * Notes:
	 * - This is evaluated against the normalized app-relative path, e.g. "/member/profile".
	 * - include/exclude arrays are produced ahead of time by compilePathMatcher(),
	 *   so at runtime we only run preg_match() on already-anchored, safe regexes.
	 * - This check decides whether we invoke that provider for this request and
	 *   inject its return value into the template scope.
	 *
	 * @param string   $path   App-relative request path (always starts with "/").
	 * @param string[] $incRes Array of compiled "include" regex patterns.
	 * @param string[] $excRes Array of compiled "exclude" regex patterns.
	 *
	 * @return bool True if provider should run for $path.
	 */
	private function pathMatches(string $path, array $incRes, array $excRes): bool {
		// Start pessimistic: if we *have* include rules, you are NOT included
		// until you match one. If include list is empty, you ARE included by default.
		$included = ($incRes === []);

		if (!$included) {
			// Try each include regex; first hit wins.
			foreach ($incRes as $re) {
				if (\preg_match($re, $path) === 1) {
					$included = true;
					break;
				}
			}
		}

		// If nothing matched any include rule, bail early
		if (!$included) {
			return false;
		}

		// Now enforce excludes: if ANY exclude regex matches, reject.
		foreach ($excRes as $re) {
			if (\preg_match($re, $path) === 1) {
				return false;
			}
		}

		// Included and not excluded => ok
		return true;
	}


	/**
	 * Compile "file@layer" to a cached PHP file and return its absolute path.
	 *
	 * Behavior:
	 * - splitRef(): Validate and separate "relative/path.html" and "layer".
	 * - loadSource(): Read the raw template source from the correct layer.
	 * - processExtendsAndBlocks(): Resolve `{% extends "x@y" %}` and merge child `{% block %}` into parent `{% yield %}`.
	 * - processIncludes(): Inline `{% include "x@y" %}` recursively (depth-limited).
	 * - removeTemplateComments(): Strip `{# ... #}` comments (supports nesting).
	 * - compileSyntax(): Turn `{{ }}`, `{{{ }}}`, `{% if %}`, `{% foreach %}`, and `{? ... ?}` into executable PHP.
	 * - Optionally removeHtmlCommentsSafe() and safeTrimWhitespace() based on config.
	 * - Write atomically into `var/cache` under a deterministic filename that includes both path and layer.
	 * - If `cache_enabled` is true, reuse the compiled file if all sources/deps are older than the cached PHP.
	 *
	 * @param string $ref Template reference like "public/login.html@citomni/auth".
	 * @return string Absolute path to a compiled PHP file ready for `require`.
	 */
	private function compile(string $ref): string {
		[$relPath, $layer] = $this->splitRef($ref);

		$cacheFile = $this->cacheFileName($relPath, $layer);

		if ($this->cacheEnabled && \is_file($cacheFile)) {
			// Load source for freshness scan
			[$srcCode, $srcPath] = $this->loadSource($relPath, $layer);

			// Match LiteView semantics:
			// Strip template comments BEFORE scanning for extends/includes,
			// because commented-out includes should NOT keep the cache hot.
			$scanCode = $this->removeTemplateComments($srcCode);

			$deps = $this->collectDependencies($scanCode, $layer, $visited = []);
			$maxMtime = \filemtime($srcPath);

			foreach ($deps as [$depRel, $depLayer]) {
				[, $depAbs] = $this->loadSource($depRel, $depLayer);
				$mt = \filemtime($depAbs);
				if ($mt > $maxMtime) {
					$maxMtime = $mt;
				}
			}

			if ($maxMtime <= \filemtime($cacheFile)) {
				return $cacheFile;
			}
		}

		// We need to (re)compile:
		[$code, $absPath] = $this->loadSource($relPath, $layer);

		// Strip template comments {# ... #} early:
		$code = $this->removeTemplateComments($code);

		// Resolve extends+blocks across layers:
		$code = $this->processExtendsAndBlocks($code, $layer);

		// Resolve {% include "file@layer" %} recursively (depth-limited):
		$code = $this->processIncludes($code, 0);

		// Rewrite template syntax -> PHP:
		$code = $this->compileSyntax($code);

		// Optionally post-process:
		if ($this->removeHtmlComments) {
			$code = $this->removeHtmlCommentsSafe($code);
		}
		if ($this->trimWhitespace) {
			$code = $this->safeTrimWhitespace($code);
		}

		// Wrap final compiled code into a minimal guard:
		$compiledPhp = "<?php class_exists('" . __CLASS__ . "') or exit; ?>\n"
			. \rtrim($code);

		// Atomic write
		$tmp = $this->cacheDir . '/' . \uniqid('tpl_', true) . '.tmp';
		if (\file_put_contents($tmp, $compiledPhp, \LOCK_EX) === false) {
			throw new \RuntimeException('TemplateEngine: Cache directory not writable: ' . $this->cacheDir);
		}
		@\chmod($tmp, 0644);

		// Replace/move into place
		if (!@\rename($tmp, $cacheFile)) {
			@\unlink($cacheFile);
			if (!@\rename($tmp, $cacheFile)) {
				@\unlink($tmp);
				throw new \RuntimeException('TemplateEngine: Failed to move compiled cache file.');
			}
		}

		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($cacheFile, true);
		}
		\clearstatcache(true, $cacheFile);

		return $cacheFile;
	}


	/**
	 * compileSyntax: Transform templating syntax ({{ }}, {% %}, etc.) into executable PHP.
	 *
	 * Converts the high-level template language into plain PHP code. This is the
	 * final transformation step before the compiled template is written to cache.
	 * The resulting string is valid PHP and will later be included via `require`.
	 *
	 * Behavior:
	 * - Variable echo:
	 *   1) `{{ expr }}` becomes `echo htmlspecialchars(expr, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");`
	 *      which is HTML-escaped by default.
	 *   2) `{{{ expr }}}` becomes `echo expr;` (raw, no escaping).
	 *      Use this only for trusted HTML (e.g. CSRF fields).
	 *
	 * - Logic / flow:
	 *   1) `{% if (...) %} ... {% endif %}`
	 *   2) `{% elseif (...) %}`, `{% else %}`
	 *   3) `{% foreach (...) %} ... {% endforeach %}`
	 *   These map directly to native PHP `if (...) {}`, `elseif`, `else`, and `foreach`.
	 *
	 * - Layout blocks:
	 *   1) `{% block name %}...{% endblock %}` is stripped here because block
	 *      resolution is already handled in `processExtendsAndBlocks()`.
	 *   2) `{% yield name %}` is also stripped for the same reason.
	 *   3) `{% extends "file@layer" %}` is stripped because parent/child merge
	 *      is handled before this step.
	 *
	 * - Inline PHP:
	 *   1) `{? some raw php ?}` becomes `<?php some raw php ?>`
	 *   2) `{?= expr ?}` becomes `<?php echo expr; ?>`
	 *   This is only emitted if `$this->allowPhpTags === true`. If inline PHP
	 *   is disabled, those tokens are replaced by an empty string.
	 *
	 * Security / escaping rules:
	 * - `{{ ... }}` is always escaped via `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")`.
	 * - `{{{ ... }}}` is never escaped. The template author is responsible for
	 *   ensuring the content is safe and already sanitized.
	 * - `{? ... ?}` can output arbitrary PHP (including echo, loops, etc.).
	 *   This is powerful and should be considered trusted-only surface; templates
	 *   are expected to be controlled by the application/provider developer, not
	 *   end users. (If an attacker can edit templates, you have bigger problems.)
	 *
	 * Notes:
	 * - The method does not perform any filesystem I/O.
	 * - The method assumes `processExtendsAndBlocks()` and `processIncludes()`
	 *   have already inlined layouts/partials and removed all structural tags
	 *   (extends / block / yield / include), so it can safely strip those tokens.
	 * - `allowPhpTags` is a runtime toggle sourced from config/options. It lets
	 *   you globally forbid `{? ... ?}` in hardened deployments, but by default
	 *   we allow it (developer convenience).
	 *
	 * Typical usage:
	 *   $code = $this->compileSyntax($mergedTemplateSource);
	 *   // $code now contains valid PHP with `<?php ... ?>` and `echo ...;`
	 *   // and is about to be persisted to a cache file, then required.
	 *
	 * @param string $code Raw template code after layout inheritance and includes
	 *                     have already been resolved. May still contain `{{ }}`,
	 *                     `{? ?}`, `{% if %}`, etc.
	 *
	 * @return string PHP source code that can be written directly to a cache file
	 *                and later executed with `require`. The returned code does
	 *                not include the leading `<?php class_exists(...)` guard;
	 *                that wrapper is added by compile().
	 */
	private function compileSyntax(string $code): string {
		// We expand patterns dynamically so `{? ... ?}` can be nuked if allowPhpTags=false
		$patterns = [
			// Raw PHP echo
			'/{\?=\s*(.+?)\s*\?}/s' => $this->allowPhpTags ? '<?php echo $1; ?>' : '',
			// Raw PHP block
			'/{\?(.+?)\?}/s'        => $this->allowPhpTags ? '<?php $1 ?>'       : '',

			// Triple curlies => raw echo
			'/\{\{\{\s*(.+?)\s*\}\}\}/s'
				=> '<?php echo $1; ?>',

			// Double curlies => escaped echo
			'/\{\{\s*(.+?)\s*\}\}/s'
				=> '<?php echo htmlspecialchars($1 ?? "", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?>',

			// Control structures
			'/{%\s*if\s*(.+?)\s*%}/'
				=> '<?php if ($1): ?>',
			'/{%\s*elseif\s*(.+?)\s*%}/'
				=> '<?php elseif ($1): ?>',
			'/{%\s*else\s*%}/'
				=> '<?php else: ?>',
			'/{%\s*endif\s*%}/'
				=> '<?php endif; ?>',

			'/{%\s*foreach\s*\((.+?)\)\s*%}/'
				=> '<?php foreach ($1): ?>',
			'/{%\s*endforeach\s*%}/'
				=> '<?php endforeach; ?>',

			// Blocks/yields are already resolved by processExtendsAndBlocks(),
			// so we just strip any leftover markers gracefully:
			'/{%\s*block\s+([\w-]+)\s*%}/'
				=> '',
			'/{%\s*endblock\s*%}/'
				=> '',
			'/{%\s*yield\s*([\w-]+)\s*%}/'
				=> '',
			
			// Any stray `{% extends ... %}` should be gone already after processExtendsAndBlocks(),
			// but strip just in case:
			'/{%\s*extends\s+["\'](.+?)["\']\s*%}/'
				=> '',
		];

		return (string)\preg_replace(
			\array_keys($patterns),
			\array_values($patterns),
			$code
		);
	}


	/**
	 * printViewVars: Emit a debug dump of the final template variable payload.
	 *
	 * Writes an annotated, depth-limited dump of $vars into the HTML output
	 * as an HTML comment (`<!-- ... -->`) to help developers inspect what the
	 * template actually received. This is meant for troubleshooting controller
	 * data, globals, dynamic providers, etc.
	 *
	 * Behavior:
	 * - Only runs in "dev" or "stage". In "prod" it returns immediately.
	 * - Normalizes values to avoid fatal recursion:
	 *   1) Tracks seen objects to prevent infinite loops.
	 *   2) Replaces closures with "[closure]".
	 *   3) Replaces resources with "[resource:<type>]".
	 *   4) Limits recursion depth (default: 10).
	 * - Output is wrapped in <!-- --> so it is invisible in the DOM but still
	 *   visible via "View Source".
	 *
	 * Notes:
	 * - This method `echo`s directly. It is intentionally side-effecty,
	 *   and should run before the compiled template is required.
	 * - Because it may expose internal state (services, config-derived values),
	 *   it must never run in production environments.
	 *
	 * Typical usage:
	 * - Called automatically by render() if the request contains `?_viewvars`.
	 *
	 * @param array<string,mixed> $vars Final merged vars
	 *                                  (globals + dynamic providers + controller $data).
	 * @return void
	 */
	private function printViewVars(array $vars): void {
		// Resolve current environment (fallback "prod" if undefined).
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';

		// Only allow debug output in dev/stage.
		if ($env !== 'dev' && $env !== 'stage') {
			return;
		}

		// Track seen objects to avoid infinite recursion when normalizing.
		$seen = new \SplObjectStorage();

		// Hard recursion depth limit (defensive so we never explode memory).
		$maxDepth = 10;

		/**
		 * @param mixed $v     Arbitrary value from $vars.
		 * @param int   $depth Current recursion depth.
		 * @return mixed       Normalized scalar/array structure safe to print_r().
		 */
		$normalize = function (mixed $v, int $depth = 0) use (&$normalize, $seen, $maxDepth) {
			// Stop if we hit depth limit.
			if ($depth >= $maxDepth) {
				return '[depth-limit]';
			}

			// Arrays: normalize each element.
			if (\is_array($v)) {
				$out = [];
				foreach ($v as $k => $vv) {
					$out[$k] = $normalize($vv, $depth + 1);
				}
				return $out;
			}

			// Closures: do not attempt to serialize, just annotate.
			if ($v instanceof \Closure) {
				return '[closure]';
			}

			// Objects: prevent infinite loops and dump a shallow snapshot.
			if (\is_object($v)) {
				// If we've already seen this object, mark it as a repeat.
				if ($seen->contains($v)) {
					return '[' . \get_debug_type($v) . ' (seen)]';
				}
				$seen->attach($v);

				// Basic class info.
				$out = ['__class' => \get_class($v)];

				// Shallowly walk public props.
				$props = [];
				foreach (\get_object_vars($v) as $k => $vv) {
					$props[$k] = $normalize($vv, $depth + 1);
				}
				if ($props !== []) {
					$out['props'] = $props;
				}

				return $out;
			}

			// Resources: annotate type only.
			if (\is_resource($v)) {
				return '[resource:' . \get_resource_type($v) . ']';
			}

			// Scalars / null: pass through unchanged.
			return $v;
		};

		// Produce a safe, serializable version of $vars.
		$normalized = $normalize($vars);

		// Emit as HTML comment so it won't affect DOM/layout.
		echo "<!--\n=== CitOmni TemplateEngine Vars (environment: {$env}) ===\n";
		\print_r($normalized);
		echo "\n=== End Vars ===\n-->\n";
	}


	/**
	 * splitRef: Parse a template reference of the form "file@layer".
	 *
	 * A template reference always names both:
	 * - a relative template path (e.g. "admin/panel.html")
	 * - a layer key registered in TemplateEngine::init() (e.g. "citomni/admin")
	 *
	 * Example valid refs:
	 *   "public/home.html@app"
	 *   "admin/admin_layout.html@citomni/admin"
	 *
	 * Behavior:
	 * - Splits on the LAST '@' to allow filenames that might contain '@'
	 *   earlier (edge case, but deterministic).
	 * - Ensures both the relative path and layer are non-empty.
	 * - Ensures the layer exists in $this->layersMap. If the layer is not
	 *   registered, we fail fast.
	 * - Normalizes leading slashes away from the relative path so lookups
	 *   are consistent on disk.
	 *
	 * Notes:
	 * - This method does not check that the template file exists; use loadSource()
	 *   for that.
	 *
	 * @param string $ref Full template reference "path@layer".
	 *
	 * @return array{0:string,1:string} Tuple {relativePath, layer} where:
	 *   - relativePath is a path under that layer's template root
	 *     (no leading slash),
	 *   - layer is the layer identifier as registered in init().
	 *
	 * @throws \InvalidArgumentException If $ref is missing "@", or if either side
	 *                                   of the split is empty.
	 * @throws \RuntimeException         If the referenced layer is unknown.
	 */
	private function splitRef(string $ref): array {
		// Find the *last* "@", to be deterministic even if someone ever does "foo@bar@layer".
		$pos = \strrpos($ref, '@');
		if ($pos === false) {
			throw new \InvalidArgumentException("TemplateEngine: Template ref '{$ref}' must contain '@layer'.");
		}

		// Left side is the template path, right side is the layer key.
		$rel   = \substr($ref, 0, $pos);
		$layer = \substr($ref, $pos + 1);

		// Normalize path: strip any accidental leading "/" or "\".
		$rel = \ltrim($rel, '/\\');

		// Basic validation: no empty parts.
		if ($rel === '' || $layer === '') {
			throw new \InvalidArgumentException("TemplateEngine: Invalid template ref '{$ref}'.");
		}

		// Ensure the layer exists in the precomputed map from init().
		if (!isset($this->layersMap[$layer])) {
			throw new \RuntimeException("TemplateEngine: Unknown layer '{$layer}' in '{$ref}'.");
		}

		return [$rel, $layer];
	}


	/**
	 * loadSource: Resolve a template ref (path + layer) to absolute disk path and read it.
	 *
	 * This is the authoritative I/O step for loading template source code. It:
	 * - Maps the logical layer key (e.g. "citomni/admin") to its absolute template root.
	 * - Resolves the requested relative path under that root.
	 * - Guards against path traversal attempts ("../", symlinks escaping root, etc.).
	 * - Returns both the template code and the absolute on-disk path.
	 *
	 * Security:
	 * - We `realpath()` both the base root and the requested file, then verify
	 *   that the requested file still lives under the base root. If not, we throw.
	 *
	 * Notes:
	 * - This method does NOT cache; caching happens later in compile().
	 * - This method throws on missing templates instead of returning null. We
	 *   prefer fail-fast so the global error handler can report a real error.
	 *
	 * Typical usage:
	 *   [$code, $absPath] = $this->loadSource('admin/panel.html', 'citomni/admin');
	 *
	 * @param string $rel   Relative template path under the layer root.
	 * @param string $layer Layer identifier (must exist in $this->layersMap).
	 *
	 * @return array{0:string,1:string} {code, absPath}
	 *                                  code    = file contents as string
	 *                                  absPath = absolute path to template file
	 *
	 * @throws \RuntimeException If the layer is unknown, the file does not exist,
	 *                           or the resolved path escapes the layer root.
	 */
	private function loadSource(string $rel, string $layer): array {
		// Look up the absolute root directory for this layer.
		$root = $this->layersMap[$layer] ?? null;
		if ($root === null) {
			throw new \RuntimeException("TemplateEngine: Layer '{$layer}' not registered.");
		}

		// Build absolute candidate path "<layerRoot>/<rel>".
		$base   = \rtrim($root, "/\\") . '/';
		$target = \realpath($base . $rel);

		// Bail out if file is missing or unreadable.
		if ($target === false) {
			throw new \RuntimeException("TemplateEngine: Template '{$rel}@{$layer}' not found.");
		}

		// Security: Ensure that the resolved file did not escape the intended base.
		$baseWithSep = \rtrim(\realpath($base) ?: $base, "/\\") . DIRECTORY_SEPARATOR;
		if (\strpos($target, $baseWithSep) !== 0) {
			throw new \RuntimeException("TemplateEngine: Illegal path escape '{$rel}@{$layer}'.");
		}

		// Load file contents (we intentionally do not silence errors here).
		$code = (string)\file_get_contents($target);

		return [$code, $target];
	}


	/**
	 * cacheFileName: Build a deterministic cache filename for a compiled template.
	 *
	 * Each compiled template ("relativePath@layer") becomes a standalone cached
	 * PHP file in the engine's cache directory. We derive a filename that:
	 *
	 * - Encodes both the layer and the relative path (sanitized to safe chars),
	 *   so different layers with the same relative template path never collide.
	 *   Example:
	 *     "header.html@app"              -> app__header_html_xxxxxxxx.php
	 *     "header.html@citomni/admin"    -> citomni_admin__header_html_xxxxxxxx.php
	 *
	 * - Appends a short hash (first 8 chars of sha1) for uniqueness and to keep
	 *   file names stable even if two templates sanitize to the same slug.
	 *
	 * Behavior:
	 * - Uses "/" and "\" normalization to make path segments filesystem-safe.
	 * - Writes into $this->cacheDir, which is resolved in init().
	 *
	 * Notes:
	 * - The hash is based on "<rel>@<layer>", not file contents. We don't need
	 *   content hashing here because we already handle freshness using mtimes
	 *   in compile().
	 *
	 * Typical usage:
	 *   $cacheFile = $this->cacheFileName('admin/panel.html', 'citomni/admin');
	 *
	 * @param string $rel   Relative template path under that layer.
	 * @param string $layer Layer identifier (e.g. "app", "citomni/admin").
	 *
	 * @return string Absolute path to the cache file on disk.
	 */
	private function cacheFileName(string $rel, string $layer): string {
		// Normalize/sanitize the relative path into something filesystem-safe.
		// "foo/bar.html" -> "foo_bar_html"
		$slugRel = \preg_replace(
			'/[^A-Za-z0-9_]+/',
			'_',
			\strtr($rel, ['\\' => '/', '/' => '_'])
		);

		// Normalize/sanitize the layer identifier similarly.
		// "citomni/admin" -> "citomni_admin"
		$slugLayer = \preg_replace('/[^A-Za-z0-9_]+/', '_', $layer);

		// Short hash to avoid collisions if two different refs sanitize to same slugs.
		$hash = \substr(\sha1($rel . '@' . $layer), 0, 8);

		// Example final filename:
		//   /var/cache/citomni_admin__foo_bar_html_1a2b3c4d.php
		return $this->cacheDir . '/' . $slugLayer . '__' . $slugRel . '_' . $hash . '.php';
	}


	/**
	 * processExtendsAndBlocks: Resolve layout inheritance (`{% extends %}`) and block overrides.
	 *
	 * This method applies child->parent template inheritance and returns a "flattened"
	 * template string with all `{% block %}` / `{% yield %}` pairs resolved.
	 * After this step, the result is a single concrete template where:
	 *
	 * - The parent layout's structure is preserved.
	 * - Any `{% yield blockName %}` in the parent has been replaced by the
	 *   corresponding `{% block blockName %}...{% endblock %}` content from
	 *   the child.
	 * - Remaining structural tags (`{% extends ... %}`, `{% block ... %}`,
	 *   `{% endblock %}`, `{% yield ... %}`) are eliminated so that later
	 *   phases (`compileSyntax()`) don't need to know about them.
	 *
	 * Behavior:
	 * - Looks for a single `{% extends "file@layer" %}` in the given $code.
	 *   If not found, returns $code unchanged.
	 *
	 * - If found:
	 *   1) Split the reference to get `$parentRel` and `$parentLayer`.
	 *   2) Strip the `{% extends ... %}` line from the child.
	 *   3) Extract all `{% block blockName %}...{% endblock %}` regions
	 *      from the child and remember them by `blockName`.
	 *   4) Load the parent template source via `loadSource()`, strip template
	 *      comments from the parent, and then replace each `{% yield blockName %}`
	 *      in the parent with the child's content for that block.
	 *   5) If the parent has a `{% yield %}` for which the child never provided
	 *      a matching `{% block %}`, we throw. Missing content is considered a
	 *      template error (fail fast, not silent fallback).
	 *
	 * - Duplicate blocks in the child are illegal:
	 *   `{% block header %}` defined twice in the same child template reports
	 *   a RuntimeException instead of "last wins". We do this to keep behavior
	 *   deterministic and obvious.
	 *
	 * - Recursion:
	 *   After merging child overrides into the parent, we call
	 *   `processExtendsAndBlocks()` again on the merged parent output.
	 *   This supports multi-level inheritance, e.g.:
	 *     app@foo extends citomni/admin@layout
	 *     citomni/admin@layout extends citomni/http@base_layout
	 *
	 * Failure modes (fail fast):
	 * - If a child declares a `{% block %}` that the parent never `{% yield %}`s,
	 *   we throw (developer error in templates).
	 * - If the parent still contains `{% yield ... %}` after merging, we throw
	 *   because that means the child did not supply a required block.
	 * - If a duplicate block name appears in the child, we throw.
	 *
	 * Notes:
	 * - `$code` passed in here is expected to already be stripped of template
	 *   comments (`{# ... #}`) by the caller (compile() does this before calling).
	 *   We still call `removeTemplateComments()` on the parent layout we load.
	 *
	 * - This method does not perform include expansion; `{% include %}` is
	 *   handled later by `processIncludes()`.
	 *
	 * - This method does not perform any of the `{{ }}` / `{? ?}` compilation;
	 *   that is handled by `compileSyntax()`.
	 *
	 * Typical usage:
	 *   // Inside compile():
	 *   $code = $this->removeTemplateComments($code);
	 *   $code = $this->processExtendsAndBlocks($code, $layer);
	 *   $code = $this->processIncludes($code, 0);
	 *   $code = $this->compileSyntax($code);
	 *
	 * @param string $code         Child template source after comment stripping,
	 *                             before include expansion. May contain one
	 *                             `{% extends "..." %}` and zero or more `{% block %}`.
	 * @param string $currentLayer Layer identifier of the child (e.g. "app" or "citomni/admin").
	 *
	 * @return string Flattened template source with inheritance resolved. The
	 *                returned code no longer contains `{% extends %}`,
	 *                `{% block %}`, `{% endblock %}`, or `{% yield %}`.
	 *
	 * @throws \RuntimeException On:
	 *   - Duplicate `{% block %}` names in the child.
	 *   - Child block not yielded by the parent.
	 *   - Parent still containing `{% yield %}` after merge (missing block).
	 *   - Failure to replace yields due to regex errors.
	 *   - Inaccessible parent template.
	 */
	private function processExtendsAndBlocks(string $code, string $currentLayer): string {
		if (\strpos($code, '{% extends') === false) {
			// No parent -> nothing to resolve.
			return $code;
		}
		if (!\preg_match('/{%\s*extends\s+["\'](.+?)["\']\s*%}/', $code, $m)) {
			// Tag looked like it might be there, but not in a valid form.
			return $code;
		}

		[$parentRel, $parentLayer] = $this->splitRef($m[1]);

		// Remove the extends tag from the child to avoid it leaking further down.
		$childNoExtends = \preg_replace(
			'/{%\s*extends\s+["\'](.+?)["\']\s*%}/',
			'',
			$code,
			1
		);

		// Load the parent layout source and strip comments from it.
		[$layoutCode, $layoutAbs] = $this->loadSource($parentRel, $parentLayer);
		$layoutCode = $this->removeTemplateComments($layoutCode);

		// Extract all `{% block name %}...{% endblock %}` from the child.
		\preg_match_all(
			'/{%\s*block\s+([\w-]+)\s*%}(.*?){%\s*endblock\s*%}/s',
			$childNoExtends,
			$matches,
			\PREG_SET_ORDER
		);

		$seen = [];
		foreach ($matches as $match) {
			$name    = $match[1];
			// $content = \trim($match[2]);
			$content = $match[2];

			// Disallow duplicate block definitions in the same child template.
			if (isset($seen[$name])) {
				throw new \RuntimeException(
					"TemplateEngine: Duplicate block '{$name}' in child template (layer '{$currentLayer}')."
				);
			}
			$seen[$name] = true;

			// Replace `{% yield name %}` in the parent with the child's block content.
			$quoted = \preg_quote($name, '/');
			$replaced = \preg_replace(
				'/{%\s*yield\s*' . $quoted . '\s*%}/',
				$content,
				$layoutCode,
				-1,
				$count
			);

			if ($replaced === false || $replaced === null) {
				throw new \RuntimeException("TemplateEngine: Regex replace failed for block '{$name}'.");
			}

			$layoutCode = $replaced;

			// If the parent never yielded this block, the child's block is "orphaned".
			if ($count === 0) {
				throw new \RuntimeException(
					"TemplateEngine: Block '{$name}' from child layer '{$currentLayer}' was not yielded in parent template: " . $layoutAbs
				);
			}
		}

		// After merging: If the parent still has any `{% yield something %}`, then the child failed to provide a block for that something.
		if (\preg_match_all('/{%\s*yield\s*([\w-]+)\s*%}/', $layoutCode, $leftover) && !empty($leftover[1])) {
			$missing = \array_values(\array_unique($leftover[1]));
			$list    = "'" . \implode("', '", $missing) . "'";
			throw new \RuntimeException(
				"TemplateEngine: Missing child blocks {$list} from layer '{$currentLayer}' in parent template: " . $layoutAbs
			);
		}

		// Recursively resolve further extends in the parent.
		// This supports multi-level inheritance chains.
		return $this->processExtendsAndBlocks($layoutCode, $parentLayer);
	}


	/**
	 * processIncludes: Inline `{% include "file@layer" %}` directives recursively.
	 *
	 * Behavior:
	 * - Scans the given template code for `{% include "relative/path.html@layer" %}`.
	 * - For each include:
	 *   1) Splits the reference into (relative path, layer) via splitRef().
	 *   2) Loads the referenced template source from that layer (loadSource()).
	 *   3) Removes `{# ... #}` comments from the included template.
	 *   4) Recursively processes includes inside the included template (depth+1).
	 *   5) Replaces the `{% include ... %}` tag with the fully expanded included code.
	 *
	 * - Enforces a maximum nesting depth of 16 to avoid pathological recursion
	 *   (circular includes, accidental infinite loops, etc.).
	 *
	 * Notes:
	 * - Includes are resolved at compile time, not at runtime, so the final
	 *   compiled template is a single PHP file with everything inlined.
	 * - This method does not apply `compileSyntax()` yet; it operates on the
	 *   pre-compiled template language (still containing {{ }}, {% if %}, etc.).
	 * - Security: loadSource() ensures the resolved absolute path stays within
	 *   the registered template layer directory. Traversal attempts fail fast.
	 *
	 * @param string $code   Raw template code (already had `{# ... #}` comments
	 *                       removed by the caller if desired, but not required).
	 * @param int    $depth  Current recursion depth. Must start at 0. Each nested
	 *                       include increments depth by 1. Hard-capped at 16.
	 *
	 * @return string Fully expanded code with all `{% include ... %}` directives
	 *                inlined for this branch (and their own nested includes
	 *                already resolved).
	 *
	 * @throws \RuntimeException If include nesting exceeds MAX depth (16).
	 * @throws \RuntimeException If an included template cannot be found or escapes
	 *                           its layer root.
	 */
	private function processIncludes(string $code, int $depth): string {
		
		if (\strpos($code, '{% include') === false) {
			return $code;
		}
		if ($depth >= 16) {
			throw new \RuntimeException('TemplateEngine: Include depth exceeded.');
		}

		// Strip comments in the caller BEFORE scanning for includes, so commented-out includes are ignored
		//
		// Note: We intentionally run removeTemplateComments() again inside this method,
		//       even though compile() already stripped comments earlier, because we ALSO
		//       want to ignore commented-out includes INSIDE included partials. That way
		//       `{# {% include "debug/panel@app" %} #}` never keeps a cache dependency alive.
		$clean = $this->removeTemplateComments($code);

		return \preg_replace_callback(
			'/{%\s*include\s+["\'](.+?)["\']\s*%}/i',
			function (array $m) use ($depth) {
				[$rel, $layer] = $this->splitRef($m[1]);

				[$incCode, ] = $this->loadSource($rel, $layer);

				// Strip comments in the included file
				$incCode = $this->removeTemplateComments($incCode);

				// Recurse into nested includes inside that included code
				$incCode = $this->processIncludes($incCode, $depth + 1);

				return $incCode;
			},
			$clean
		);
	}


	/**
	 * Remove `{# ... #}` template comments using a single-pass depth parser (supports nesting).
	 *
	 * Implementation details:
	 * - Uses a single-pass parser (O(n)) with a depth counter, not regex.
	 * - Supports nested comments: Each `{#` increments depth, each `#}` decrements it.
	 * - If a comment is left open (while depth > 0), everything after its start is discarded (fail-safe).
	 * - This guarantees that nested comment blocks are fully removed,
	 *   leaving no stray `#}` or partial fragments in the output.
	 *
	 * Example:
	 *   {# outer
	 *      {# inner #}
	 *   #}
	 *   -> (removed completely, yields empty string)
	 *
	 * @param string $code (template code containing `{# ... #}` comments)
	 * @return string (cleaned template code with all comments removed)
	 */
	private function removeTemplateComments(string $code): string {
		// Fast path: No comment opener, nothing to do
		if (\strpos($code, '{#') === false) {
			return $code;
		}

		$len = \strlen($code);   // Input length in bytes
		$depth = 0;             // Current nesting depth of `{# ... #}` blocks
		$start = 0;             // Last copy position (outside comments)
		$out = '';              // Output buffer

		// Scan the string once, matching `{#` and `#}` pairs
		for ($i = 0; $i < $len; $i++) {
			// Detect comment start `{#}`
			if ($i < $len - 1 && $code[$i] === '{' && $code[$i + 1] === '#') {
				// Append non-comment segment before this opener (only at depth 0)
				if ($depth === 0) {
					$out .= \substr($code, $start, $i - $start);
				}
				$depth++;   // Enter (or go deeper into) a comment block
				$i++;       // Skip the '#' in `{#`
				continue;
			}

			// Detect comment end `#}`
			if ($i < $len - 1 && $code[$i] === '#' && $code[$i + 1] === '}' && $depth > 0) {
				$depth--;              // Leave (or go up one level) of comment block
				$i++;                  // Skip the '}' in `#}`
				$start = $i + 1;       // Next non-comment text starts after this closer
				continue;
			}
		}

		// If we ended outside comments, append any trailing non-comment segment
		if ($depth === 0 && $start < $len) {
			$out .= \substr($code, $start);
		}

		return $out;
	}


	/**
	 * Remove standard HTML comments while keeping conditional comments.
	 *
	 * @param string $code
	 * @return string
	 */
	private function removeHtmlCommentsSafe(string $code): string {
		return (string)\preg_replace('/<!--(?!<!)[^\[>].*?-->/s', '', $code);
	}


	/**
	 * Collapse redundant whitespace in HTML output while preserving sensitive tags.
	 *
	 * Sensitive tags are <pre>, <code>, <textarea>, <script>, and <style>.
	 * Inside those, whitespace must be preserved exactly.
	 *
	 * @param string $html (the compiled template HTML)
	 * @return string (optimized HTML with collapsed whitespace outside sensitive tags)
	 */
	private function safeTrimWhitespace(string $html): string {
	
		// Split HTML into chunks: Alternating between "safe zones" (outside) and "sensitive zones" (inside)
		$parts = preg_split(
			'#(<(?:pre|code|textarea|script|style)\b[^>]*>.*?</(?:pre|code|textarea|script|style)>)#si',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		// preg_split() can return false on failure -> fall back to original HTML
		if ($parts === false) {
			return $html;
		}

		// Iterate through chunks
		foreach ($parts as $i => $chunk) {
			// Even indexes = outside sensitive tags -> safe to collapse
			if (($i % 2) === 0) {
				// Replace runs of 2+ whitespace chars with a single space
				// This avoids breaking inline markup while reducing size
				$parts[$i] = preg_replace('/\s{2,}/', ' ', $chunk);
			}
			// Odd indexes = inside sensitive tags -> leave untouched
		}

		// Recombine and return cleaned HTML
		return implode('', $parts);
	}


	/**
	 * Collect all (rel,layer) pairs that this template depends on via extends/includes.
	 * Used for cache freshness checks.
	 *
	 * @param string $code
	 * @param string $currentLayer Layer of the $code we are scanning.
	 * @param array<string,bool> $visited Keyed by "rel@layer" to avoid loops.
	 * @return array<int,array{0:string,1:string}>
	 */
	private function collectDependencies(string $code, string $currentLayer, array &$visited = []): array {
		$deps = [];

		// Detect parent via {% extends "file@layer" %}
		if (\preg_match('/{%\s*extends\s+["\'](.+?)["\']\s*%}/', $code, $m)) {
			[$parentRel, $parentLayer] = $this->splitRef($m[1]);
			$key = $parentRel . '@' . $parentLayer;
			if (!isset($visited[$key])) {
				$visited[$key] = true;
				$deps[] = [$parentRel, $parentLayer];
				[$parentCode, ] = $this->loadSource($parentRel, $parentLayer);
				$parentCode = $this->removeTemplateComments($parentCode);
				$deps = \array_merge(
					$deps,
					$this->collectDependencies($parentCode, $parentLayer, $visited)
				);
			}
		}

		// Detect includes via {% include "file@layer" %}
		if (\preg_match_all('/{%\s*include\s+["\'](.+?)["\']\s*%}/i', $code, $matches)) {
			foreach ($matches[1] as $incRef) {
				[$incRel, $incLayer] = $this->splitRef($incRef);
				$key = $incRel . '@' . $incLayer;
				if (isset($visited[$key])) {
					continue;
				}
				$visited[$key] = true;
				$deps[] = [$incRel, $incLayer];
				[$incCode, ] = $this->loadSource($incRel, $incLayer);
				$incCode = $this->removeTemplateComments($incCode);
				$deps = \array_merge(
					$deps,
					$this->collectDependencies($incCode, $incLayer, $visited)
				);
			}
		}

		return $deps;
	}


	/**
	 * normalizeCfgMap: Convert a cfg node (expected to behave like an associative map)
	 * into a plain PHP array<string,mixed>.
	 *
	 * Accepts:
	 * - plain array
	 * - CitOmni\Kernel\Cfg (or any object with toArray())
	 * - null / scalar => returns []
	 */
	private function normalizeCfgMap(mixed $node): array {
		// Already an array? great.
		if (\is_array($node)) {
			return $node;
		}

		// Cfg node (or similar) with toArray():
		if (\is_object($node) && \method_exists($node, 'toArray')) {
			$out = $node->toArray(); // Cfg::toArray() returns its raw $data
			return \is_array($out) ? $out : [];
		}

		// Anything else (null, string, etc.) -> treat as empty map
		return [];
	}


}
