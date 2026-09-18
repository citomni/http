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
 * Render and compile deterministic, explicitly layered templates for CitOmni HTTP applications.
 *
 * The engine keeps the established CitOmni template contract while compiling trusted template
 * source into reusable PHP generations. Template references always use the explicit
 * "relative/path.html@layer" form, where the layer maps to a configured template root.
 *
 * Behavior:
 * - Supports layout inheritance via `{% extends "layout@layer" %}` and named
 *   `{% block %}` / `{% yield %}` regions across registered layers.
 * - Supports compile-time partial expansion via `{% include "partial@layer" %}`.
 * - Supports escaped `{{ ... }}` output, raw `{{{ ... }}}` output, control directives,
 *   `{% set %}`, native PHP and the optional `{? ... ?}` / `{?= ... ?}` inline-PHP syntax.
 * - Merges template variables with deterministic precedence:
 *   1) Controller data.
 *   2) Path-scoped `cfg->view->vars` values.
 *   3) Request-local globals and helper closures.
 * - Exposes the established helper contract including `$url`, `$asset`, `$txt`, `$icon`,
 *   `$hasIcon`, `$dt`, `$auth`, `$role`, `$csrfField` and related helpers.
 * - Dynamic scoped providers are evaluated for every applicable render; provider results are
 *   deliberately not memoized by this service.
 * - Compiles templates into immutable, content-addressed PHP generations under `var/cache`.
 * - Publishes a non-executable JSON manifest last, after the compiled generation is complete.
 * - On a warm cache hit, validates actual dependency paths and metadata without rereading or
 *   reparsing template source.
 * - Cache identity includes compiler version, PHP version, layer mapping and compile flags so
 *   incompatible compiled output is never reused across compiler/runtime configurations.
 *
 * Notes:
 * - Relevant `cfg->view` configuration is preserved:
 *   1) `template_layers` maps logical layer slugs to template-root directories.
 *   2) `cache_enabled` allows validated compiled generations to be reused across renders.
 *   3) `trim_whitespace` collapses redundant whitespace only in safe, unprotected markup text.
 *   4) `remove_html_comments` removes ordinary HTML comments during compile-time optimization.
 *   5) `allow_php_tags` controls CitOmni's `{? ... ?}` / `{?= ... ?}` inline-PHP syntax.
 *   6) `asset_version` supplies the default cache-busting token used by `$asset()`.
 *   7) `marketing_scripts` is exposed as trusted raw markup through template globals.
 *   8) `vars` defines static or dynamic path-scoped values with include/exclude rules.
 * - Typical `template_layers` configuration:
 *     [
 *         'app' => '/var/www/app/templates',
 *         'citomni/admin' => '/var/www/app/vendor/citomni/admin/templates',
 *     ]
 * - Typical path-scoped `vars` configuration:
 *     [
 *         'admin_nav' => [
 *             'type' => 'static',
 *             'source' => [...],
 *             'include' => ['/admin/*'],
 *             'exclude' => [],
 *         ],
 *         'header' => [
 *             'type' => 'dynamic',
 *             'source' => ['service' => 'sitewide', 'method' => 'header'],
 *             'include' => ['*'],
 *             'exclude' => ['/admin/*'],
 *         ],
 *     ]
 * - `allow_php_tags` controls CitOmni's custom inline-PHP tags; it is not a PHP sandbox and
 *   native PHP in trusted templates remains supported.
 * - Templates and the cache directory are trusted application-controlled inputs. This class is
 *   not intended to execute untrusted template source.
 * - Dependency freshness uses canonical path, mtime, ctime, size, inode and device. An edit that
 *   preserves every compared metadata value requires explicit template-cache invalidation.
 * - Old content-addressed generations are not deleted on request paths. Prune obsolete template
 *   cache artifacts during coordinated maintenance rather than from active renders.
 * - The service retains request-bound globals and is designed for one App/request context.
 * - If `_viewvars` is present on a dev or stage request, render() emits an escaped diagnostic
 *   HTML comment containing the final template-variable payload before rendering.
 *
 * Typical usage:
 *   $this->app->tplEngine->render('member/home.html@app', [
 *   	'title' => 'Member area',
 *   ]);
 *
 *   $html = $this->app->tplEngine->renderToString(
 *   	'mail/reset.html@citomni/authenticate',
 *   	['identity' => $identity]
 *   );
 *
 * @throws \InvalidArgumentException On an invalid template reference or layer definition.
 * @throws \RuntimeException On invalid template structure, inaccessible source or cache failure.
 */
final class TemplateEngine extends BaseService {

	// ----------------------------------------------------------------
	// Compiler contract and state
	// ----------------------------------------------------------------

	/** Compiler/cache format version. Increment when emitted PHP or dependency/compiler semantics change. */
	private const string COMPILER_VERSION = '2';

	/** Maximum recursive include depth before failing fast. */
	private const int MAX_INCLUDE_DEPTH = 16;

	/** Maximum number of parent-layout hops in one inheritance chain. */
	private const int MAX_INHERITANCE_DEPTH = 64;

	/** Canonical parser expression for one explicit extends directive. */
	private const string EXTENDS_PATTERN = '/{%\s*extends\s+["\'](.+?)["\']\s*%}/';

	/** Canonical parser expression for explicit include directives. */
	private const string INCLUDE_PATTERN = '/{%\s*include\s+["\'](.+?)["\']\s*%}/i';

	/** Existing non-nested block grammar used during inheritance resolution. */
	private const string BLOCK_PATTERN = '/{%\s*block\s+([\w-]+)\s*%}(.*?){%\s*endblock\s*%}/s';

	/** Canonical parser expression for named layout yields. */
	private const string YIELD_PATTERN = '/{%\s*yield\s*([\w-]+)\s*%}/';


	/** @var array<string,string> Layer slug to configured template directory. */
	private array $layersMap = [];

	/** Absolute persistent cache root (`CITOMNI_APP_PATH . '/var/cache'`). */
	private string $cacheDir = '';

	/** Hash of compiler/runtime/layer settings that participate in cache identity. */
	private string $cacheSignature = '';

	/** Whether warm compiled generations may be reused across renders. */
	private bool $cacheEnabled = false;

	/** Whether safe literal-text whitespace collapsing is enabled at compile time. */
	private bool $trimWhitespace = false;

	/** Whether ordinary HTML comments are removed by compile-time markup optimization. */
	private bool $removeHtmlComments = false;

	/** Whether CitOmni `{? ... ?}` and `{?= ... ?}` inline-PHP tags are compiled. */
	private bool $allowPhpTags = true;

	/** Global cache-busting version used by the `$asset()` helper. */
	private string $assetVersion = '';

	/** Raw trusted marketing/analytics markup exposed as `marketing_scripts`. */
	private string $marketingScripts = '';

	/**
	 * Precompiled path-scoped template-variable definitions from `cfg->view->vars`.
	 *
	 * Shape:
	 *   [
	 *     'header' => [
	 *       'type' => 'dynamic',
	 *       'call' => ['class' => \Foo\Model\SitewideModel::class, 'method' => 'header'],
	 *       'ire' => ['/^\\/$/', '/^\\/nyheder\\/.*$/'],
	 *       'ere' => ['/^\\/admin\\//'],
	 *     ],
	 *     'admin_nav' => [
	 *       'type' => 'static',
	 *       'data' => [...],
	 *       'ire' => ['/^\\/admin\\//'],
	 *       'ere' => [],
	 *     ],
	 *   ]
	 *
	 * Notes:
	 * - Array keys are the final template variable names exposed to templates.
	 * - Configuration uses `include` / `exclude`; `ire` / `ere` are internal compiled regex arrays.
	 * - Static definitions store their configured `source` as `data`.
	 * - Dynamic definitions store their configured provider `source` as `call`.
	 * - Dynamic provider results are not stored here; they are resolved for every applicable render.
	 *
	 * @var array<string,array{
	 *     type:'static'|'dynamic',
	 *     data?:mixed,
	 *     call?:mixed,
	 *     ire:string[],
	 *     ere:string[]
	 * }>
	 */
	private array $compiledVars = [];

	/** @var array<string,mixed>|null Request-local global variables and helpers. */
	private ?array $globals = null;

	/** @var array<string,array> Successfully loaded manifests; revalidated for each render. */
	private array $manifests = [];

	/** @var array<string,string> Cache identity prefixes for references used by this App. */
	private array $cachePrefixes = [];







	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Initialize template configuration, scoped variables and the persistent cache identity.
	 *
	 * Behavior:
	 * - Snapshots `cfg->view` and service-map options once so hot render paths avoid repeatedly
	 *   walking the configuration wrapper.
	 * - Normalizes and validates `template_layers`; `app` is accepted as the built-in layer name
	 *   and other layers must use a `vendor/package` slug.
	 * - Applies existing option precedence for cache, markup and asset settings. Service options
	 *   override corresponding view configuration values where supported.
	 * - Normalizes `cfg->view->vars` into static/dynamic definitions and precompiles each
	 *   include/exclude matcher with compilePathMatcher().
	 * - Builds a deterministic cache signature from compiler version, PHP version, short-open-tag
	 *   state, compile flags and the sorted layer map.
	 * - Resolves the established cache root to `CITOMNI_APP_PATH . '/var/cache'` without creating
	 *   the directory until compilation actually needs it.
	 *
	 * Notes:
	 * - Layer roots are not realpath-resolved during initialization. Boundary resolution happens
	 *   lazily when source files are accessed, preserving symlink-capable layer configuration.
	 * - `cfg->view->vars` accepts `type` = `static` or `dynamic`, a `source`, and optional
	 *   `include` / `exclude` matcher lists.
	 * - Invalid definitions fail fast rather than being silently ignored or guessed.
	 *
	 * @return void
	 * @throws \InvalidArgumentException  On an invalid layer slug or layer path definition.
	 * @throws \RuntimeException  On an invalid scoped-variable definition.
	 */
	protected function init(): void {

		// -- 1. Snapshot configuration and service options ----------------
		// Read the view subtree once so later render/compile paths do not keep walking Cfg.
		// Service-map options are also request-local construction input; they take precedence
		// over matching cfg->view values. Once normalized below, the original options bag is
		// no longer needed and is cleared to avoid carrying redundant mutable state.

		$viewCfg = $this->app->cfg->view ?? (object)[];
		$opt = $this->options;
		$this->options = [];


		// -- 2. Normalize and validate template layers --------------------
		// Template layers map a logical name to one absolute template root.
		//
		// Typical configuration:
		//   [
		//     'app' => '/var/www/myapp/templates',
		//     'citomni/admin' => '/var/www/myapp/vendor/citomni/admin/templates',
		//     'aserno/byportal' => '/var/www/myapp/vendor/aserno/byportal-core/templates',
		//   ]
		//
		// normalizeCfgMap() flattens either a plain array or a Cfg-style node into
		// a regular associative array so runtime lookups stay simple and cheap.
		//
		// Layer slugs are validated here so bad configuration fails during service
		// construction. "app" is the built-in special case; package layers use
		// "vendor/package".
		//
		// We deliberately do NOT realpath() roots here. Canonical resolution and
		// containment checks happen when a concrete template is loaded, which keeps
		// init() cheap and preserves valid symlink-based template roots.

		foreach ($this->normalizeCfgMap($viewCfg->template_layers ?? []) as $layerKey => $path) {
			$layer = (string)$layerKey;
			if ($layer !== 'app' && !\preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~i', $layer)) {
				throw new \InvalidArgumentException("TemplateEngine: Invalid layer slug '{$layer}'.");
			}
			if (!\is_string($path) || $path === '') {
				throw new \InvalidArgumentException("TemplateEngine: template_layers['{$layer}'] must be a non-empty string (absolute directory).");
			}
			$this->layersMap[$layer] = \rtrim($path, '/\\') . '/';
		}


		// -- 3. Snapshot compile settings ----------------------------------
		// These flags participate directly in compilation behavior. Service options
		// override cfg->view, and values are coerced once here so hot paths only read
		// typed object properties.

		$this->cacheEnabled = (bool)($opt['cache_enabled'] ?? $viewCfg->cache_enabled ?? false);
		$this->trimWhitespace = (bool)($opt['trim_whitespace'] ?? $viewCfg->trim_whitespace ?? false);
		$this->removeHtmlComments = (bool)($opt['remove_html_comments'] ?? $viewCfg->remove_html_comments ?? false);
		$this->allowPhpTags = (bool)($opt['allow_php_tags'] ?? $viewCfg->allow_php_tags ?? true);


		// -- 4. Snapshot passthrough view configuration --------------------
		// assetVersion is consumed lazily by $asset() for ?v= cache busting.
		// marketingScripts is exposed as a template global for trusted analytics/
		// marketing markup.
		//
		// Icons are intentionally NOT loaded here. $icon() delegates to the lazy
		// Icon service, so requests that never render an icon pay no icon payload cost.

		$this->assetVersion = (string)($opt['asset_version'] ?? $viewCfg->asset_version ?? '');
		$this->marketingScripts = (string)($viewCfg->marketing_scripts ?? '');


		// -- 5. Precompile path-scoped variable definitions ---------------
		// cfg->view->vars is an associative map keyed by the final template variable:
		//
		//   'header' => [
		//     'type' => 'dynamic',
		//     'source' => ['service' => 'sitewide', 'method' => 'header'],
		//     'include' => ['*'],
		//     'exclude' => ['/admin/*'],
		//   ]
		//
		// Static definitions keep source as literal data. Dynamic definitions keep
		// source as a provider descriptor accepted by invokeProvider():
		// - "FQCN::method"
		// - ['class' => FQCN, 'method' => 'm']
		// - ['service' => 'id', 'method' => 'm']
		//
		// include/exclude rules are compiled once now into anchored regexes so each
		// render only performs preg_match() plus the provider call when applicable.

		foreach ($this->normalizeCfgMap($viewCfg->vars ?? []) as $varName => $row) {
			if (!\is_array($row) || $row === []) {
				continue;
			}
			$varName = (string)$varName;
			$type = (string)($row['type'] ?? '');
			$source = $row['source'] ?? null;
			if ($varName === '' || $type === '' || $source === null) {
				throw new \RuntimeException("TemplateEngine: view.vars['{$varName}'] requires non-empty 'type' and 'source'.");
			}
			if ($type !== 'static' && $type !== 'dynamic') {
				throw new \RuntimeException("TemplateEngine: Unsupported view.vars type '{$type}' for var '{$varName}'.");
			}
			$this->compiledVars[$varName] = [
				'type' => $type,
				$type === 'static' ? 'data' : 'call' => $source,
				'ire' => \array_map($this->compilePathMatcher(...), \array_values((array)($row['include'] ?? []))),
				'ere' => \array_map($this->compilePathMatcher(...), \array_values((array)($row['exclude'] ?? []))),
			];
		}


		// -- 6. Build deterministic persistent-cache identity -------------
		// All template-cache artifacts live under the fixed app var/cache directory.
		// Keeping this location deterministic avoids another configuration branch and
		// matches CitOmni deployment/maintenance expectations.
		//
		// The cache signature describes every compiler/runtime setting that can change
		// emitted PHP for the same logical template reference. Layer ordering itself is
		// irrelevant, so sort the map before hashing it.

		$this->cacheDir = \CITOMNI_APP_PATH . '/var/cache';
		$identityLayers = $this->layersMap;
		\ksort($identityLayers, \SORT_STRING);
		$this->cacheSignature = \hash('sha256', \serialize([
			self::COMPILER_VERSION,
			\PHP_VERSION_ID,
			(bool)\ini_get('short_open_tag'),
			$this->trimWhitespace,
			$this->removeHtmlComments,
			$this->allowPhpTags,
			$identityLayers,
		]));
	}







	// ----------------------------------------------------------------
	// Rendering and variable assembly
	// ----------------------------------------------------------------

	/**
	 * Render an explicit template reference directly to the current output stream.
	 *
	 * Behavior:
	 * - Builds the final variable scope for this render.
	 * - Emits the optional `_viewvars` diagnostic before template output when enabled in dev/stage.
	 * - Resolves or compiles the requested template to an executable cached PHP generation.
	 * - Extracts final template variables with `EXTR_SKIP` and requires the compiled generation in
	 *   the established service scope, preserving the existing `$this` and local-scope contract.
	 *
	 * Notes:
	 * - Template references must use the explicit `relative/path.html@layer` form.
	 * - Controller data has the highest variable precedence.
	 * - Rendering side effects come from the compiled template and any helper/provider calls it uses.
	 *
	 * Typical usage:
	 *   $this->app->tplEngine->render('member/home.html@app', ['title' => 'Home']);
	 *
	 * @param  string  $ref  Explicit template reference such as `member/home.html@app`.
	 * @param  array<string,mixed>  $data  Controller-provided variables.
	 * @return void
	 * @throws \InvalidArgumentException  On an invalid template reference.
	 * @throws \RuntimeException  On template compilation, source or cache failure.
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
	 * Render an explicit template reference into a string.
	 *
	 * Behavior:
	 * - Uses the same variable precedence, compilation path and template execution scope as render().
	 * - Captures only template output and does not inject the `_viewvars` diagnostic dump.
	 * - Always closes the output buffer in `finally`, including when template execution throws.
	 *
	 * Notes:
	 * - Exceptions from compilation, helpers, providers or template execution are not swallowed.
	 *
	 * Typical usage:
	 *   $html = $this->app->tplEngine->renderToString('mail/reset.html@citomni/authenticate', $data);
	 *
	 * @param  string  $ref  Explicit template reference.
	 * @param  array<string,mixed>  $data  Controller-provided variables.
	 * @return string  Rendered template output.
	 * @throws \InvalidArgumentException  On an invalid template reference.
	 * @throws \RuntimeException  On template compilation, source or cache failure.
	 */
	public function renderToString(string $ref, array $data = []): string {
		$vars = $this->buildFinalVars($data);
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
	 * Build the final template-variable map for the current render.
	 *
	 * Behavior:
	 * - Memoizes request-local globals and helper closures on first use.
	 * - Evaluates matching path-scoped variables for every render when configured.
	 * - Applies deterministic left-wins precedence:
	 *   1) Controller data.
	 *   2) Scoped static/dynamic values.
	 *   3) Globals and helper closures.
	 *
	 * Notes:
	 * - PHP array union is intentional here; later sources do not overwrite earlier keys.
	 * - Dynamic provider results are not stored in `$this->globals` and are recomputed per render.
	 *
	 * @param  array<string,mixed>  $data  Controller-provided variables.
	 * @return array<string,mixed>  Final variables for extraction into template scope.
	 */
	private function buildFinalVars(array $data): array {
		$globals = $this->globals ??= $this->buildGlobals();
		if ($this->compiledVars === []) {
			return $data + $globals;
		}
		$scoped = $this->buildScopedVarsForPath($this->app->request->pathFromAppRoot());
		return $data + $scoped + $globals;
	}







	// ----------------------------------------------------------------
	// Template globals and helpers
	// ----------------------------------------------------------------

	/**
	 * Build the established request-local globals and helper closures exposed to templates.
	 *
	 * Behavior:
	 * - Exposes identity/locale scalars including `app_name`, `base_url`, `public_root_url`,
	 *   `language` and `charset`.
	 * - Exposes `marketing_scripts`, security feature flags and environment metadata.
	 * - Exposes lazy App-aware helpers for text, date/time, URLs, assets, services/packages, CSRF,
	 *   current path, SVG icons, authentication and roles.
	 * - Helper closures resolve services lazily through the current App and preserve existing
	 *   fail-fast behavior when a required service is unavailable.
	 *
	 * Notes:
	 * - The result is memoized by buildFinalVars() for this TemplateEngine/App instance.
	 * - `base_url` comes from `cfg->http->base_url` with `CITOMNI_PUBLIC_ROOT_URL` as fallback;
	 *   `public_root_url` prefers the constant when defined.
	 * - `$asset()` applies the configured asset version without discarding an existing query string.
	 * - `$csrfField()` returns an empty string when the CSRF service is not registered.
	 * - Helper implementations intentionally remain closures bound to this service so they can
	 *   access the current App without adding extra service abstractions.
	 *
	 * @return array<string,mixed>  Request-local globals and helper closures.
	 */
	private function buildGlobals(): array {

		// -- 1. Resolve base URLs ------------------------------------------

		$cfg = $this->app->cfg;

		$baseUrl = (string)($cfg->http->base_url
			?? (\defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : ''));

		$publicUrl = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? (string)\CITOMNI_PUBLIC_ROOT_URL
			: $baseUrl;


		return [

			// -- 2. Identity and locale scalars -------------------------------

			'app_name'	=> (string)$cfg->identity->app_name,
			'base_url'	=> $baseUrl,
			'public_root_url' => $publicUrl,
			'language'	=> (string)$cfg->locale->language,
			'charset'	=> (string)$cfg->locale->charset,


			// -- 3. View passthroughs -----------------------------------------

			'marketing_scripts' => $this->marketingScripts,


			// -- 4. Security feature flags ------------------------------------

			'csrf_protection'		=> (bool)($cfg->security->csrf->enabled ?? true),
			'honeypot_protection'	=> (bool)$cfg->security->honeypot_protection,
			'form_action_switching'	=> (bool)$cfg->security->form_action_switching,
			'captcha_protection'	=> (bool)$cfg->security->captcha_protection,


			// -- 5. Environment metadata --------------------------------------

			'env' => [
				'name'	=> \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod',
				'dev'	=> \defined('CITOMNI_ENVIRONMENT') ? (\CITOMNI_ENVIRONMENT === 'dev') : false,
			],



			// -- 6. Lazy template helpers -------------------------------------

			/**
			 * $txt: Localized text lookup with optional fallback/default.
			 *
			 * Typical usage:
			 *   {{ $txt('login_title', 'authenticate', 'citomni/authenticate') }}
			 *   {{ $txt('greeting', 'homepage', null, 'Hello guest') }}
			 *
			 * With replacements:
			 *   {{ $txt('welcome_name', 'homepage', 'citomni/authenticate', 'Hi', ['NAME' => $user['first_name']]) }}
			 *
			 * Notes:
			 * - $file is typically the logical language file (without ".php").
			 * - $layer is optional; pass e.g. "citomni/authenticate" to read provider language.
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
			 *   {{ $dt($user['created_at'], 'yyyy-MM-dd HH:mm') }}     {# DB datetime string #}
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
			 *   <a href="{{ $url('/member/profile') }}">Profile</a>
			 *   <form action="{{ $url('/login') }}" method="post">
			 *
			 * With query params:
			 *   <a href="{{ $url('/search', ['q' => 'cochem', 'page' => 2]) }}">...</a>
			 *
			 * Notes:
			 * - $path may be "/foo" or "foo"; it will be normalized.
			 * - This helper is intended for internal app URLs only.
			 * - $query is turned into ?a=1&b=2 (http_build_query).
			 */
			'url' => function (string $path = '', array $query = []) use ($baseUrl): string {
				$p = '/' . \ltrim(\trim($path), '/');

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
			 * $hasPackage: Check if a package namespace is registered in the App.
			 *
			 * Typical usage:
			 *   {% if $hasPackage('citomni/authenticate') %}
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
			 * - Returns "" (empty string) if the csrf service is not registered.
			 */
			'csrfField' => function (): string {
				if ($this->app->hasService('csrf')) {
					return $this->app->csrf->htmlField();
				}
				return '';
			},


			/**
			 * $currentPath: Get the current app-root-relative request path.
			 *
			 * Typical usage:
			 *   <li class="{% if $currentPath() === '/member/profile' %}active{% endif %}">
			 *   	<a href="{{ $url('/member/profile') }}">Profil</a>
			 *   </li>
			 *
			 * Notes:
			 * - Returns Request::pathFromAppRoot(), e.g. "/member/profile".
			 * - Good for "active" menu highlighting and local route comparisons.
			 */
			'currentPath' => function (): string {
				return $this->app->request->pathFromAppRoot();
			},


			/**
			 * $icon: Inline SVG icon lookup.
			 *
			 * Typical usage (TRIPLE braces for raw trusted markup):
			 *   {{{ $icon('home') }}}
			 *   {{{ $icon('mfa_totp', 'icons', 'citomni/authenticate') }}}
			 *   {{{ $icon('logo', 'brand', 'app') }}}
			 *
			 * Behavior:
			 * - Delegates to the lazy Icon service.
			 * - Returns a `<svg ...>` string with no wrapper span/div.
			 * - The default file is "icons"; the default layer is "citomni/http".
			 * - SVGs are trusted view assets and must be rendered raw.
			 *
			 * Failure:
			 * - Missing files, missing ids, invalid payloads, and malformed SVG values
			 *   fail fast through the Icon service. We do not hide missing icons in prod.
			 */
			'icon' => function (string $id, string $file = 'icons', string $layer = 'citomni/http'): string {
				if (!$this->app->hasService('icon')) {
					throw new \RuntimeException("Icon service not available. Register 'icon' in the HTTP service map.");
				}

				return $this->app->icon->get($id, $file, $layer);
			},


			/**
			 * $hasIcon: Check whether an inline SVG icon exists.
			 *
			 * Typical usage:
			 *   {% if $hasIcon('logo', 'brand', 'app') %}
			 *   	{{{ $icon('logo', 'brand', 'app') }}}
			 *   {% endif %}
			 *
			 * Behavior:
			 * - Delegates to the lazy Icon service.
			 * - Returns false when the requested icon file or id is missing.
			 * - Invalid caller input and invalid existing icon file payloads still fail fast.
			 * - The concrete SVG value is validated by $icon() / Icon::get().
			 */
			'hasIcon' => function (string $id, string $file = 'icons', string $layer = 'citomni/http'): bool {
				if (!$this->app->hasService('icon')) {
					return false;
				}

				return $this->app->icon->has($id, $file, $layer);
			},


			/**
			 * $auth: Authenticated identity helper for templates.
			 *
			 * Typical usage:
			 *
			 *   {% if $auth('check') %}
			 *   	<a href="{{ $url('/member/home.html') }}">Member area</a>
			 *   {% endif %}
			 *
			 *   {% if $auth('checkStrict') %}
			 *   	{% set $identity = $auth('identity') %}
			 *   	<span>{{ $identity['email'] }}</span>
			 *   {% endif %}
			 *
			 *   {{ $auth('id') }}
			 *   {{ $auth('role') }}
			 *
			 * Behavior:
			 * - 'check'       => session-key authentication check, no database IO.
			 * - 'checkStrict' => verified active identity check.
			 * - 'identity'    => current sanitized identity row, or null.
			 * - 'id'          => current identity id from session, or null.
			 * - 'role'        => current role id, or null.
			 *
			 * Throws:
			 * - \RuntimeException if the authenticate Auth service is unavailable.
			 * - \InvalidArgumentException if you call with an unknown fn.
			 */
			'auth' => function (string $fn) {
				if (!$this->app->hasService('auth') || !$this->app->hasPackage('citomni/authenticate')) {
					throw new \RuntimeException(
						"Auth service not available. Install 'citomni/authenticate' and register 'Auth' as 'auth'."
					);
				}

				$auth = $this->app->auth;

				switch ($fn) {
					case 'check':
						return $auth->check();

					case 'checkStrict':
						return $auth->checkStrict();

					case 'identity':
						return $auth->getIdentity();

					case 'id':
						return $auth->getIdentityId();

					case 'role':
						return $auth->getRole();

					default:
						throw new \InvalidArgumentException("Unknown auth helper '{$fn}'.");
				}
			},


			/**
			 * $role: Role/permission helper for templates (proxy to citomni/authenticate Role).
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
			 *   {# Minimum / hierarchy check (>= operator) #}
			 *   {% if $role('min', 'operator') %}
			 *   	<a href="{{ $url('/admin') }}">Admin panel</a>
			 *   {% endif %}
			 *
			 *   {# Maximum / hierarchy check (<= operator) #}
			 *   {% if $role('max', 'operator') %}
			 *   	<p>Standard or support-level access.</p>
			 *   {% endif %}
			 *
			 *   {# Numeric rank (tinyint) #}
			 *   {{ $role('rank') }}   {# e.g. 9 for admin #}
			 *
			 *   {# Role name for a given id #}
			 *   {{ $role('name', 9) }}
			 *
			 *   {# Role id for a given name #}
			 *   {{ $role('id', 'admin') }}
			 *
			 *   {# Raw configured role map (name => id) #}
			 *   {% foreach ($role('map') as $name => $rid) %}
			 *   	<span>{{ $name }}: {{ $rid }}</span>
			 *   {% endforeach %}
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
			 * - 'min'      => hierarchy check ("operator or higher").
			 * - 'max'      => hierarchy check ("operator or lower").
			 * - 'id'       => role name to id lookup.
			 * - 'name'     => role id to name lookup.
			 * - 'map'      => raw configured {name => id} role map.
			 * - 'rank'     => numeric tinyint for current user.
			 * - 'label'    => localized label for current user's role.
			 * - 'labelOf'  => label for a provided role id.
			 * - 'labels'   => {id => label} map of all roles.
			 *
			 * Throws:
			 * - \RuntimeException if the authenticate Role service is unavailable.
			 * - \InvalidArgumentException if you call with an unknown fn.
			 */
			'role' => function (string $fn, mixed ...$args) {
				if (!$this->app->hasService('role') || !$this->app->hasPackage('citomni/authenticate')) {
					throw new \RuntimeException(
						"Role service not available. Install 'citomni/authenticate' and register 'Role' as 'role'."
					);
				}

				$role = $this->app->role;

				switch ($fn) {
					case 'label':
						return $role->label(...$args);

					case 'labelOf':
						return $role->labelOf(...$args);

					case 'labels':
						return $role->labels(...$args);

					case 'is':
						return $role->is((string)$args[0]);

					case 'any':
						return $role->any(...$args);

					case 'min':
						return $role->min(...$args);

					case 'max':
						return $role->max(...$args);

					case 'id':
						return $role->id((string)$args[0]);

					case 'name':
						return $role->name((int)$args[0]);

					case 'map':
						return $role->map();

					case 'rank':
						return $role->rank();

					default:
						throw new \InvalidArgumentException("Unknown role helper '{$fn}'.");
				}
			},
		];
	}







	// ----------------------------------------------------------------
	// Scoped variables and provider dispatch
	// ----------------------------------------------------------------

	/**
	 * Resolve all configured scoped variables that apply to an app-root-relative request path.
	 *
	 * Behavior:
	 * - Evaluates precompiled include/exclude matchers for each `cfg->view->vars` definition.
	 * - Copies static values directly.
	 * - Invokes dynamic provider definitions only when their path rules match.
	 *
	 * Notes:
	 * - Dynamic provider results are intentionally recomputed for every applicable render.
	 * - The path is expected to be normalized by Request::pathFromAppRoot().
	 *
	 * @param  string  $relPath  App-root-relative path such as `/admin/users.html`.
	 * @return array<string,mixed>  Matching scoped values keyed by template variable name.
	 */
	private function buildScopedVarsForPath(string $relPath): array {
		$out = [];
		foreach ($this->compiledVars as $name => $definition) {
			if (!$this->pathMatches($relPath, $definition['ire'], $definition['ere'])) {
				continue;
			}
			$out[$name] = $definition['type'] === 'static'
				? $definition['data']
				: $this->invokeProvider($definition['call'], $name);
		}
		return $out;
	}


	/**
	 * Invoke a configured dynamic scoped-variable provider deterministically.
	 *
	 * Behavior:
	 * - Supports three existing provider forms:
	 *   1) `FQCN::method` invokes the static method with the current App.
	 *   2) `['class' => FQCN, 'method' => 'm']` creates `new FQCN($app)` and calls `m()`.
	 *   3) `['service' => 'id', 'method' => 'm']` resolves the registered service and calls `m()`.
	 * - Validates referenced methods/services before invocation and fails fast on malformed shapes.
	 * - Returns the provider result unchanged for insertion into template scope.
	 *
	 * Notes:
	 * - Provider work happens only after path include/exclude rules have matched.
	 * - Instance providers are created per invocation; registered service providers reuse the App's
	 *   memoized service instance.
	 * - This method does not catch provider exceptions; failures bubble to the global error handler.
	 *
	 * Typical usage:
	 *   $value = $this->invokeProvider(
	 *   	['service' => 'sitewide', 'method' => 'header'],
	 *   	'header'
	 *   );
	 *
	 * @param  mixed  $call  Provider definition from `cfg->view->vars[*]['source']`.
	 * @param  string  $varName  Variable name used in diagnostics.
	 * @return mixed  Provider result.
	 * @throws \RuntimeException  On an unsupported definition or missing class/method/service.
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
			$cls	= (string)$call['class'];
			$m		= (string)$call['method'];

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
			$id	= (string)$call['service'];
			$m	= (string)$call['method'];

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
	 * Compile a configured path matcher into an anchored PCRE expression.
	 *
	 * Behavior:
	 * - Supports these configured input forms:
	 *   1) `''` returns an impossible matcher.
	 *   2) `~...~` is treated as an explicit developer-supplied regular expression and returned unchanged.
	 *   3) `*` matches every app-root-relative path.
	 *   4) `/` matches only the front page `/`.
	 *   5) `/foo` matches that path prefix.
	 *   6) `/foo/*` matches the `/foo/` prefix and descendants.
	 *   7) `news` is normalized to `/news` before compilation.
	 * - Escapes ordinary path characters and expands only `*` as a wildcard before anchoring the
	 *   generated glob expression at the start of the app-relative path.
	 *
	 * Notes:
	 * - Regex semantics are available only through the explicit `~...~` form; glob-style config is
	 *   escaped to avoid accidental regex interpretation.
	 * - The result is passed directly to preg_match() against paths such as `/member/profile`.
	 *
	 * @param  string  $pat  Configured include/exclude matcher.
	 * @return string  PCRE pattern suitable for preg_match().
	 */
	private function compilePathMatcher(string $pat): string {

		// -- 1. Handle empty and explicit-regex forms ---------------------

		$pat = (string)$pat;

		if ($pat === '') {
			return '/^\b\B$/';  // empty rule => "match nothing"
		}

		// If dev provided "~...~", we assume it's already a complete regex
		if ($pat[0] === '~' && \str_ends_with($pat, '~') && \strlen($pat) >= 2) {
			return $pat;
		}


		// -- 2. Normalize path/glob forms ----------------------------------
		// If pattern doesn't start with "/", treat it like a path fragment.
		// We'll normalize so "news" becomes "/news"
		if ($pat !== '*' && $pat[0] !== '/') {
			$pat = '/' . $pat;
		}

		// Special case: "/" means "frontpage only"
		if ($pat === '/') {
			return '/^\/$/';
		}


		// -- 3. Compile the anchored glob expression -----------------------
		// Escape literal chars for regex, but keep "*" as wildcard.
		// Example: "/news/*" -> '/^\/news\/.*/'
		$quoted = \preg_quote($pat, '/');				// turn "/" etc. into "\/", "*" into "\*"
		$quoted = \str_replace('\*', '.*', $quoted);	// replace escaped "*" with ".*" (wildcard)

		return '/^' . $quoted . '/';					// anchor at start of str
	}


	/**
	 * Decide whether a scoped-variable definition applies to a request path.
	 *
	 * Behavior:
	 * - An empty include list means include everything.
	 * - A non-empty include list requires at least one matching include expression.
	 * - Any matching exclude expression rejects the path, even after an include match.
	 * - Returns true only when the path is included and not excluded.
	 *
	 * Notes:
	 * - Matchers are precompiled by compilePathMatcher(), keeping this request-time path lean.
	 * - The path is expected to start with `/` and be app-root-relative.
	 *
	 * @param  string  $path  App-root-relative request path.
	 * @param  string[]  $incRes  Compiled include expressions.
	 * @param  string[]  $excRes  Compiled exclude expressions.
	 * @return bool  Whether the scoped variable applies.
	 */
	private function pathMatches(string $path, array $incRes, array $excRes): bool {

		// -- 1. Evaluate include rules ------------------------------------

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


		// -- 2. Enforce exclude rules --------------------------------------
		// If ANY exclude regex matches, reject.
		foreach ($excRes as $re) {
			if (\preg_match($re, $path) === 1) {
				return false;
			}
		}


		// -- 3. Accept the included path -----------------------------------
		// Included and not excluded => ok
		return true;
	}







	// ----------------------------------------------------------------
	// Compilation and persistent cache
	// ----------------------------------------------------------------

	/**
	 * Resolve a template reference to an immutable compiled PHP generation.
	 *
	 * Behavior:
	 * - Splits and validates the explicit template reference, then derives its versioned cache prefix.
	 * - On a warm cache hit, loads the JSON manifest and validates the compiled generation plus all
	 *   recorded dependencies by canonical path and metadata without reading template source bytes.
	 * - Cache readers do not lock. A cache miss acquires the stable per-template writer lock and
	 *   performs a second cache check to avoid duplicate concurrent compilation.
	 * - Compiles a stable source snapshot in this order:
	 *   1) Load the root source and record its dependency snapshot.
	 *   2) Resolve inheritance and block/yield merging.
	 *   3) Expand includes recursively.
	 *   4) Compile CitOmni template syntax to PHP.
	 *   5) Apply optional safe markup optimization.
	 * - Revalidates every observed dependency before publication. If files changed during the pass,
	 *   compilation is retried up to three times rather than publishing a mixed deployment snapshot.
	 * - Hashes the complete compiled PHP to produce an immutable generation filename.
	 * - Publishes the compiled generation before atomically replacing the JSON manifest pointer.
	 *
	 * Notes:
	 * - With `cache_enabled=false`, source is always recompiled, but generation publication still uses
	 *   the same writer lock and atomic cache-file mechanics.
	 * - The JSON manifest deliberately remains non-executable and outside OPcache; compiled PHP
	 *   generations are immutable and therefore safe for aggressive OPcache reuse.
	 * - Content-addressed generations are reused when newly compiled bytes are unchanged.
	 * - Old generations are not removed from the request path.
	 *
	 * @param  string  $ref  Explicit `relative/path.html@layer` template reference.
	 * @return string  Absolute path to the compiled PHP generation.
	 * @throws \InvalidArgumentException  On an invalid template reference.
	 * @throws \RuntimeException  On source, template structure, locking or cache I/O failure.
	 * @throws \JsonException  If the manifest cannot be encoded.
	 */
	private function compile(string $ref): string {

		// -- 1. Resolve logical reference and cache identity --------------
		// splitRef() validates the explicit path@layer contract. The resulting base
		// prefix is stable for this compiler/runtime configuration and is memoized
		// because renderToString() and render() may resolve the same template more
		// than once within one App/request context.

		[$rel, $layer] = $this->splitRef($ref);
		$base = $this->cachePrefixes[$rel . '@' . $layer] ??= $this->cacheFileName($rel, $layer);


		// -- 2. Fast-path a valid warm cache generation -------------------
		// A warm hit must stay cheap: read/validate the manifest and stat the recorded
		// dependencies, but do NOT read or parse template source again. The manifest may
		// be memoized in this service instance, but dependency metadata is revalidated
		// on every render before the compiled generation is reused.

		if ($this->cacheEnabled) {
			$manifest = $this->manifests[$base] ?? $this->readManifest($base);
			if ($manifest !== null && $this->manifestIsFresh($base, $manifest)) {
				$this->manifests[$base] = $manifest;
				return $base . '_' . $manifest['generation'] . '.php';
			}
			unset($this->manifests[$base]);
		}


		// -- 3. Serialize cache misses for this template identity ---------
		// Readers never lock. Only a miss takes this stable per-template writer lock,
		// preventing concurrent requests from compiling/publishing the same identity
		// simultaneously. The lock filename is stable even though compiled generations
		// are content-addressed and immutable.

		$this->ensureCacheDirectory();
		$lock = \fopen($base . '.lock', 'c+b');
		if ($lock === false) {
			throw new \RuntimeException('TemplateEngine: Unable to open template cache lock.');
		}
		try {
			if (!\flock($lock, \LOCK_EX)) {
				throw new \RuntimeException('TemplateEngine: Unable to acquire template cache lock.');
			}

			// -- 4. Recheck after acquiring the writer lock ------------------
			// Another request may have completed compilation while this request waited for
			// LOCK_EX. Rechecking here avoids duplicate work and immediately reuses the
			// generation that the previous writer just published.

			if ($this->cacheEnabled) {
				$manifest = $this->readManifest($base);
				if ($manifest !== null && $this->manifestIsFresh($base, $manifest)) {
					$this->manifests[$base] = $manifest;
					return $base . '_' . $manifest['generation'] . '.php';
				}
			}


			// -- 5. Compile and verify one stable dependency snapshot --------
			// One context tracks every logical source actually used by this compilation:
			// - sources prevents rereading the same logical dependency within the attempt.
			// - dependencies becomes the manifest freshness snapshot.
			// - includeStack detects active include cycles.
			// - roots memoizes canonical layer roots for containment checks.
			//
			// After compilation, re-stat all dependencies before publication. If a deploy
			// changed files during the pass, discard that mixed snapshot and retry instead
			// of publishing output assembled from two different source states.
			for ($attempt = 0; $attempt < 3; $attempt++) {
				$context = ['sources' => [], 'dependencies' => [], 'includeStack' => [], 'roots' => []];
				[$code, $sourcePath] = $this->loadSource($rel, $layer, $context);
				$code = $this->processExtendsAndBlocks($code, $layer, $context, [$sourcePath => true]);
				$code = $this->processIncludes($code, 0, $context);
				$code = $this->compileSyntax($code);
				if ($this->removeHtmlComments || $this->trimWhitespace) {
					$code = $this->optimizeMarkup($code);
				}
				$compiled = "<?php class_exists('" . __CLASS__ . "', false) or exit; ?>\n" . \rtrim($code);
				$dependencies = \array_values($context['dependencies']);
				if (!$this->dependenciesAreFresh($dependencies)) {
					continue;
				}


				// -- 6. Publish immutable generation, then mutable manifest ------
				// Hash the final PHP bytes, not the source reference. Identical compiled output
				// therefore reuses the same immutable generation file. The JSON manifest is
				// the mutable pointer and is deliberately written LAST, so readers can never
				// observe a manifest that points at a generation which has not been published.

				$generation = \hash('sha256', $compiled);
				$file = $base . '_' . $generation . '.php';
				\clearstatcache(false, $file);
				if (!\is_file($file)) {
					$this->writeCacheFile($file, $compiled);
				}
				$manifest = [
					'format' => self::COMPILER_VERSION,
					'generation' => $generation,
					'dependencies' => $dependencies,
				];
				$json = \json_encode($manifest, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
				$this->writeCacheFile($base . '.meta.json', $json);
				if ($this->cacheEnabled) {
					$this->manifests[$base] = $manifest;
				}
				return $file;
			}
			throw new \RuntimeException('TemplateEngine: Template sources changed repeatedly during compilation. Retry after deployment has completed.');
		} finally {
			// Closing releases the lock, including when compilation or rendering preparation fails.
			\fclose($lock);
		}
	}


	/**
	 * Load and validate a non-executable JSON cache manifest.
	 *
	 * Behavior:
	 * - Treats a missing, concurrently removed, unreadable or malformed manifest as a recoverable
	 *   cache miss so compile() can rebuild it under the writer lock.
	 * - Requires the current compiler format, a 64-character lowercase SHA-256 generation id and a
	 *   non-empty dependency list with the expected tuple/stat shape.
	 * - Never evaluates manifest content as PHP.
	 *
	 * Notes:
	 * - Structural validation intentionally happens before any generation path is constructed.
	 * - Dependency freshness is validated separately by manifestIsFresh().
	 *
	 * @param  string  $base  Absolute cache identity prefix.
	 * @return array|null  Validated manifest, or null when rebuilding is required.
	 */
	private function readManifest(string $base): ?array {

		// -- 1. Load manifest bytes ---------------------------------------

		$path = $base . '.meta.json';
		\clearstatcache(false, $path);
		if (!\is_file($path)) {
			return null;
		}
		// Concurrent cache maintenance may remove the file after is_file(). Rebuilding is recoverable.
		$json = @\file_get_contents($path);
		if ($json === false) {
			return null;
		}


		// -- 2. Validate the complete manifest shape ----------------------

		$data = \json_decode($json, true);
		if (!\is_array($data)
			|| ($data['format'] ?? null) !== self::COMPILER_VERSION
			|| !\is_string($data['generation'] ?? null)
			|| !\preg_match('/\A[a-f0-9]{64}\z/', $data['generation'])
			|| !\is_array($data['dependencies'] ?? null)
			|| $data['dependencies'] === []) {
			return null;
		}
		foreach ($data['dependencies'] as $dependency) {
			if (!\is_array($dependency) || \count($dependency) !== 4
				|| !isset($dependency[0], $dependency[1], $dependency[2], $dependency[3])
				|| !\is_string($dependency[0]) || !\is_string($dependency[1]) || !\is_string($dependency[2])
				|| !\is_array($dependency[3]) || !\array_is_list($dependency[3]) || \count($dependency[3]) !== 5) {
				return null;
			}
			foreach ($dependency[3] as $value) {
				if (!\is_int($value)) {
					return null;
				}
			}
		}
		return $data;
	}


	/**
	 * Check whether a validated manifest still points to a usable compiled generation.
	 *
	 * Behavior:
	 * - Requires the referenced immutable compiled PHP generation to exist.
	 * - Revalidates every recorded source dependency through dependenciesAreFresh().
	 *
	 * @param  string  $base  Absolute cache identity prefix.
	 * @param  array  $manifest  Structurally validated manifest.
	 * @return bool  Whether the cached generation can be reused without compilation.
	 */
	private function manifestIsFresh(string $base, array $manifest): bool {
		$file = $base . '_' . $manifest['generation'] . '.php';
		\clearstatcache(false, $file);
		return \is_file($file) && $this->dependenciesAreFresh($manifest['dependencies']);
	}


	/**
	 * Validate canonical identity and metadata for every recorded source dependency.
	 *
	 * Behavior:
	 * - Resolves each logical `relative-path@layer` reference again without reading source bytes.
	 * - Rejects missing files, changed canonical paths, non-regular files or changed stat signatures.
	 * - Detects ordinary edits even when mtime moves backwards because equality is checked against the
	 *   full stored metadata signature rather than using a newer-than comparison.
	 *
	 * Notes:
	 * - Path containment is rechecked on warm cache hits, not only during compilation.
	 * - Metadata equality is intentionally not treated as cryptographic proof of byte equality.
	 * - `$roots` memoizes canonical layer roots only for this validation pass.
	 *
	 * @param  array<int,array{string,string,string,array<int,int>}>  $dependencies  Recorded snapshots.
	 * @return bool  Whether every dependency still matches its recorded identity and metadata.
	 */
	private function dependenciesAreFresh(array $dependencies): bool {
		$roots = [];
		foreach ($dependencies as [$rel, $layer, $expectedPath, $expectedStat]) {
			$path = $this->resolveSourcePath($rel, $layer, false, $roots);
			if ($path === null || $path !== $expectedPath) {
				return false;
			}
			\clearstatcache(false, $path);
			$stat = @\stat($path);
			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
				|| $this->statSignature($stat) !== $expectedStat) {
				return false;
			}
		}
		return true;
	}


	/**
	 * Reduce a native stat result to the metadata used for dependency freshness checks.
	 *
	 * Behavior:
	 * - Captures modification time, change time, size, inode and device in a stable positional tuple.
	 *
	 * Notes:
	 * - This signature is deliberately metadata-only so warm cache hits avoid reading template bytes.
	 *
	 * @param  array  $stat  Native stat()/fstat() result.
	 * @return array{int,int,int,int,int}  mtime, ctime, size, inode and device.
	 */
	private function statSignature(array $stat): array {
		return [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino'], $stat['dev']];
	}


	/**
	 * Ensure the established template cache directory exists.
	 *
	 * Behavior:
	 * - Creates `CITOMNI_APP_PATH . '/var/cache'` lazily when compilation first needs persistent files.
	 * - Treats a concurrently created directory as success.
	 *
	 * Notes:
	 * - The directory location is intentionally fixed rather than configurable.
	 *
	 * @return void
	 * @throws \RuntimeException  When the cache directory cannot be created.
	 */
	private function ensureCacheDirectory(): void {
		if (!\is_dir($this->cacheDir) && !@\mkdir($this->cacheDir, 0775, true) && !\is_dir($this->cacheDir)) {
			throw new \RuntimeException('TemplateEngine: Cannot create cache directory: ' . $this->cacheDir);
		}
	}


	/**
	 * Atomically publish a complete cache artifact through a same-directory temporary file.
	 *
	 * Behavior:
	 * - Creates a unique temporary file with exclusive creation.
	 * - Writes until the full byte length has been persisted and requires fflush() success.
	 * - Closes the stream, applies the established 0644 mode best-effort and publishes with rename().
	 * - Never unlinks an existing destination as a fallback when publication fails.
	 * - Removes any leftover temporary file in `finally`.
	 *
	 * Notes:
	 * - Writer locking is owned by compile(); this helper assumes publication for a template identity
	 *   is already serialized when replacement could occur.
	 * - Generation files are content-addressed and normally new paths; the manifest is the mutable
	 *   pointer published after its target generation exists.
	 * - Same-directory rename is relied upon for atomic replacement semantics on the deployment filesystem.
	 *
	 * @param  string  $path  Absolute destination path.
	 * @param  string  $contents  Complete file contents.
	 * @return void
	 * @throws \RuntimeException  On temporary-file creation, short write, flush or rename failure.
	 */
	private function writeCacheFile(string $path, string $contents): void {

		// -- 1. Create an exclusive same-directory temporary file ---------

		$tmp = $path . '.' . \bin2hex(\random_bytes(8)) . '.tmp';
		$stream = \fopen($tmp, 'xb');
		if ($stream === false) {
			throw new \RuntimeException('TemplateEngine: Cannot create temporary cache file: ' . $tmp);
		}
		try {

			// -- 2. Write and flush the complete artifact --------------------

			try {
				$length = \strlen($contents);
				$offset = 0;
				while ($offset < $length) {
					$written = \fwrite($stream, $offset === 0 ? $contents : \substr($contents, $offset));
					if ($written === false || $written === 0) {
						throw new \RuntimeException('TemplateEngine: Incomplete cache write: ' . $tmp);
					}
					$offset += $written;
				}
				if (!\fflush($stream)) {
					throw new \RuntimeException('TemplateEngine: Cannot flush cache file: ' . $tmp);
				}
			} finally {
				\fclose($stream);
			}


			// -- 3. Publish without deleting an existing destination ----------

			@\chmod($tmp, 0644);
			if (!@\rename($tmp, $path)) {
				throw new \RuntimeException('TemplateEngine: Cannot publish cache file without removing the existing generation: ' . $path);
			}
			\clearstatcache(true, $path);
		} finally {
			if (\is_file($tmp)) {
				@\unlink($tmp);
			}
		}
	}


	/**
	 * Build the bounded, versioned cache identity prefix for a logical template reference.
	 *
	 * Behavior:
	 * - Combines layer and relative path into a readable sanitized slug, truncated to 32 characters.
	 * - Appends a 32-character prefix of a SHA-256 hash over cache signature plus logical reference.
	 * - Prefixes the artifact with the explicit compiler version.
	 *
	 * Notes:
	 * - The returned value is only a prefix; compiled generations append their content hash and `.php`,
	 *   while the mutable manifest appends `.meta.json`.
	 * - No filesystem access is performed here.
	 *
	 * @param  string  $rel  Relative source path.
	 * @param  string  $layer  Registered layer slug.
	 * @return string  Absolute cache prefix without generation or extension.
	 */
	private function cacheFileName(string $rel, string $layer): string {
		// Normalize the readable part of the logical reference into filesystem-safe text.
		// Example: "citomni/admin__foo/bar.html" -> "citomni_admin__foo_bar_html".
		// Only the first 32 characters are kept; uniqueness does not depend on this slug.
		$slug = \preg_replace('/[^A-Za-z0-9_]+/', '_', $layer . '__' . $rel);

		// The identity hash separates templates that sanitize to the same slug and also
		// incorporates compiler/runtime/layer settings through cacheSignature. This is
		// NOT the compiled-content hash; compile() appends that generation hash later.
		$hash = \substr(\hash('sha256', $this->cacheSignature . "\0" . $rel . '@' . $layer), 0, 32);

		// Example cache identity prefix:
		//   /var/cache/tpl_v2_citomni_admin__foo_bar_html_<identity-hash>
		// compile() then publishes:
		//   <prefix>_<generation-sha256>.php
		//   <prefix>.meta.json
		//   <prefix>.lock
		return $this->cacheDir . '/tpl_v' . self::COMPILER_VERSION . '_' . \substr($slug, 0, 32) . '_' . $hash;
	}







	// ----------------------------------------------------------------
	// Template references and source snapshots
	// ----------------------------------------------------------------

	/**
	 * Parse and validate an explicit `relative/path.html@layer` template reference.
	 *
	 * Behavior:
	 * - Splits on the last `@`, preserving deterministic behavior if an earlier `@` occurs in a path.
	 * - Removes accidental leading `/` or `\\` characters from the relative path.
	 * - Rejects empty components and NUL bytes.
	 * - Requires the referenced layer to have been registered during initialization.
	 *
	 * Notes:
	 * - This method validates reference structure only; source existence and containment are handled by
	 *   resolveSourcePath().
	 *
	 * @param  string  $ref  Explicit template reference.
	 * @return array{string,string}  Relative path and registered layer.
	 * @throws \InvalidArgumentException  On a missing/empty component or NUL-containing path.
	 * @throws \RuntimeException  On an unknown layer.
	 */
	private function splitRef(string $ref): array {
		$pos = \strrpos($ref, '@');
		if ($pos === false) {
			throw new \InvalidArgumentException("TemplateEngine: Template ref '{$ref}' must contain '@layer'.");
		}
		$rel = \ltrim(\substr($ref, 0, $pos), '/\\');
		$layer = \substr($ref, $pos + 1);
		if ($rel === '' || $layer === '' || \str_contains($rel, "\0")) {
			throw new \InvalidArgumentException("TemplateEngine: Invalid template ref '{$ref}'.");
		}
		if (!isset($this->layersMap[$layer])) {
			throw new \RuntimeException("TemplateEngine: Unknown layer '{$layer}' in '{$ref}'.");
		}
		return [$rel, $layer];
	}


	/**
	 * Resolve a logical template reference to a canonical path inside its registered layer root.
	 *
	 * Behavior:
	 * - Builds the candidate from the configured layer root and relative path.
	 * - Resolves the layer root once per validation/compilation pass through the supplied `$roots` map.
	 * - Clears only relevant realpath/stat cache entries before canonical resolution.
	 * - Returns null for a missing optional lookup or throws for a missing required source.
	 * - Enforces canonical path containment below the resolved layer root to reject traversal and
	 *   symlink escapes.
	 *
	 * Notes:
	 * - The source bytes are not read here.
	 * - Canonical paths are later recorded in dependency snapshots and rechecked on warm cache hits.
	 *
	 * @param  string  $rel  Relative template path below the layer root.
	 * @param  string  $layer  Registered layer slug.
	 * @param  bool  $required  Whether a missing source should throw instead of returning null.
	 * @param  array<string,string>  $roots  Per-pass canonical layer-root memoization.
	 * @return string|null  Canonical source path, or null for a missing optional lookup.
	 * @throws \RuntimeException  On an unknown layer, path escape or missing required source.
	 */
	private function resolveSourcePath(string $rel, string $layer, bool $required, array &$roots): ?string {

		// -- 1. Resolve the configured layer root -------------------------
		// layersMap contains the cheap logical layer -> configured root lookup created
		// during init(). Unknown layers should normally have been rejected by splitRef(),
		// but keep this guard because this method is the filesystem boundary.

		$root = $this->layersMap[$layer] ?? null;
		if ($root === null) {
			throw new \RuntimeException("TemplateEngine: Layer '{$layer}' not registered.");
		}


		// -- 2. Canonicalize root and requested source --------------------
		// realpath() is intentionally deferred until a concrete source is needed. The
		// canonical root is memoized only for this validation/compilation pass; the
		// candidate is always resolved afresh so deploy-time path changes are observable.

		$candidate = $root . $rel;
		// Explicit paths are cleared, not the entire process-wide realpath cache.
		$base = $roots[$layer] ?? null;
		if ($base === null) {
			\clearstatcache(true, $root);
			$base = \realpath($root);
			if ($base !== false) {
				$roots[$layer] = $base;
			}
		}
		\clearstatcache(true, $candidate);
		$target = \realpath($candidate);
		if ($base === false || $target === false) {
			if (!$required) {
				return null;
			}
			throw new \RuntimeException("TemplateEngine: Template '{$rel}@{$layer}' not found.");
		}


		// -- 3. Enforce canonical containment -----------------------------
		// Use the canonical root plus a directory separator as the prefix. The separator
		// matters: a root such as /templates must not also accept a sibling like
		// /templates_backup merely because the raw string prefix happens to match.

		$prefix = \rtrim($base, '/\\') . \DIRECTORY_SEPARATOR;
		if (!\str_starts_with($target, $prefix)) {
			throw new \RuntimeException("TemplateEngine: Illegal path escape '{$rel}@{$layer}'.");
		}
		return $target;
	}


	/**
	 * Read and snapshot a template source once per compilation pass.
	 *
	 * Behavior:
	 * - Reuses an already loaded logical dependency from the compilation-local source map.
	 * - Resolves the canonical source path and requires a regular file.
	 * - Opens the source once, captures fstat() before and after reading and rejects incomplete or
	 *   concurrently changing reads.
	 * - Records the logical reference, canonical path and stat signature as an actual dependency.
	 * - Removes nested CitOmni `{# ... #}` comments before caching the source in the compilation context.
	 *
	 * Notes:
	 * - A logical dependency is read at most once during one stable compilation attempt.
	 * - Comment-stripped source is shared by inheritance/include processing for that attempt.
	 *
	 * @param  string  $rel  Relative template path.
	 * @param  string  $layer  Registered layer slug.
	 * @param  array  $context  Compilation-local sources, dependencies, include stack and root cache.
	 * @return array{string,string}  Comment-stripped source and canonical path.
	 * @throws \RuntimeException  On an inaccessible, non-regular, incomplete or changing source.
	 */
	private function loadSource(string $rel, string $layer, array &$context): array {

		// -- 1. Reuse an already loaded logical dependency ---------------
		// A template can be reached more than once through inheritance/includes. Within
		// one compilation attempt we deliberately use one shared byte snapshot per
		// logical path@layer reference so the generated result is deterministic.

		$key = $rel . '@' . $layer;
		if (isset($context['sources'][$key])) {
			return $context['sources'][$key];
		}


		// -- 2. Resolve and open one canonical regular file ---------------
		// resolveSourcePath() performs the layer-boundary check before we open anything,
		// so ../ traversal and symlink escapes cannot turn an explicit template reference
		// into an arbitrary filesystem read.

		$path = $this->resolveSourcePath($rel, $layer, true, $context['roots']);
		if (!\is_file($path)) {
			throw new \RuntimeException("TemplateEngine: Source '{$rel}@{$layer}' is not a regular file.");
		}
		$stream = \fopen($path, 'rb');
		if ($stream === false) {
			throw new \RuntimeException("TemplateEngine: Cannot read '{$rel}@{$layer}'.");
		}


		// -- 3. Read one stable byte snapshot -----------------------------
		// fstat() before and after reading the SAME open handle catches ordinary file
		// replacement/truncation during the read. We also verify that the number of bytes
		// read matches the final size before accepting this source snapshot.

		try {
			$before = \fstat($stream);
			if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
				throw new \RuntimeException("TemplateEngine: Source '{$rel}@{$layer}' is not a regular file.");
			}
			$code = \stream_get_contents($stream);
			$after = \fstat($stream);
			if ($code === false || $after === false || \strlen($code) !== $after['size']
				|| $this->statSignature($before) !== $this->statSignature($after)) {
				throw new \RuntimeException("TemplateEngine: Source '{$rel}@{$layer}' changed or could not be read completely. Retry the render.");
			}
		} finally {
			\fclose($stream);
		}


		// -- 4. Record dependency metadata and comment-stripped source ----
		// Store only dependencies that were actually loaded. Comments are stripped HERE,
		// before inheritance/include scanning, so a directive inside `{# ... #}` neither
		// executes nor becomes a phantom cache dependency.

		$context['dependencies'][$key] = [$rel, $layer, $path, $this->statSignature($after)];
		return $context['sources'][$key] = [$this->removeTemplateComments($code), $path];
	}








	// ----------------------------------------------------------------
	// Inheritance and includes
	// ----------------------------------------------------------------

	/**
	 * Resolve layered template inheritance while preserving the established child-to-parent contract.
	 *
	 * Behavior:
	 * - Follows the first valid `{% extends "path@layer" %}` directive at each inheritance level.
	 * - Loads parent layouts through the shared compilation context so dependencies are snapshotted once.
	 * - Detects circular inheritance by canonical source path and enforces the explicit depth limit.
	 * - Extracts the existing non-nested `{% block name %}...{% endblock %}` grammar and rejects
	 *   duplicate block names.
	 * - Inserts block source literally, so backslashes and `$n` sequences are never interpreted as
	 *   preg_replace() replacement syntax.
	 * - Uses one yield callback pass for ordinary layouts. If block content itself contains a yield,
	 *   the legacy ordered cascade is preserved through literal callback replacements.
	 * - Rejects orphan child blocks and any parent yields left unresolved after the merge.
	 * - Continues iteratively through multi-level inheritance across registered layers.
	 *
	 * Notes:
	 * - Input source has already had template comments removed by loadSource().
	 * - Include expansion and template-syntax compilation happen later in compile().
	 * - The method intentionally preserves the historic block grammar rather than introducing nested
	 *   block parsing or a new expression language.
	 * - A child block without a matching parent yield is a template error.
	 * - A parent yield left without a matching child block is a template error.
	 * - Duplicate block names in the same child template are a template error.
	 *
	 * Typical inheritance:
	 *   `admin/home.html@app`
	 *     -> extends `admin/admin_layout.html@citomni/admin`
	 *     -> extends `base.html@citomni/http`
	 *
	 * @param  string  $code  Comment-stripped child source before include expansion.
	 * @param  string  $currentLayer  Layer of the current child source.
	 * @param  array  $context  Compilation-local source/dependency state.
	 * @param  array<string,bool>  $seen  Canonical paths already present in the inheritance chain.
	 * @return string  Flattened source with inheritance resolved.
	 * @throws \RuntimeException  On cycles, excessive depth, duplicate/orphan/missing blocks or PCRE failure.
	 */
	private function processExtendsAndBlocks(string $code, string $currentLayer, array &$context, array $seen): string {

		// -- 1. Walk the inheritance chain iteratively --------------------

		for ($depth = 0; ; $depth++) {
			if (!\str_contains($code, '{%')) {
				return $code;
			}
			$match = \preg_match(self::EXTENDS_PATTERN, $code, $parent);
			if ($match === false) {
				throw new \RuntimeException('TemplateEngine: PCRE error while reading layout inheritance.');
			}
			if ($match === 0) {
				return $code;
			}
			if ($depth >= self::MAX_INHERITANCE_DEPTH) {
				throw new \RuntimeException('TemplateEngine: Inheritance depth exceeded.');
			}


			// -- 2. Load the parent and reject cycles ------------------------
			// Resolve the parent through the shared compilation context. That both records
			// the layout as a real dependency and ensures a multi-level inheritance chain
			// never rereads the same logical source within this attempt. Canonical paths are
			// tracked in $seen so A -> B -> A fails immediately instead of recursing forever.

			[$parentRel, $parentLayer] = $this->splitRef($parent[1]);
			[$layout, $layoutPath] = $this->loadSource($parentRel, $parentLayer, $context);
			if (isset($seen[$layoutPath])) {
				throw new \RuntimeException("TemplateEngine: Circular inheritance at '{$parentRel}@{$parentLayer}'.");
			}
			$seen[$layoutPath] = true;


			// -- 3. Extract and validate child blocks ------------------------
			// Remove exactly one extends directive from the child so it cannot leak into
			// the merged parent or survive into compileSyntax(). Then extract every named
			// `{% block name %}...{% endblock %}` body into a lookup keyed by block name.
			// Duplicate names are developer errors; there is deliberately no "last wins".
			$child = \preg_replace(self::EXTENDS_PATTERN, '', $code, 1);
			if ($child === null || \preg_match_all(self::BLOCK_PATTERN, $child, $matches, \PREG_SET_ORDER) === false) {
				throw new \RuntimeException('TemplateEngine: PCRE error while extracting child blocks.');
			}
			$blocks = [];
			$orderedMerge = false;
			foreach ($matches as $block) {
				$name = $block[1];
				if (isset($blocks[$name])) {
					throw new \RuntimeException("TemplateEngine: Duplicate block '{$name}' in child template (layer '{$currentLayer}').");
				}
				$blocks[$name] = $block[2];
				// A block may itself contain another block's yield. That legacy edge case needs
				// ordered one-block-at-a-time replacement so an earlier inserted block can expose
				// a yield consumed by a later block. Ordinary layouts use the faster one-pass path.
				if (!$orderedMerge && \str_contains($block[2], '{%')) {
					$result = \preg_match(self::YIELD_PATTERN, $block[2]);
					if ($result === false) {
						throw new \RuntimeException('TemplateEngine: PCRE error while inspecting block yields.');
					}
					$orderedMerge = $result === 1;
				}
			}


			// -- 4. Merge block source into matching yields literally --------
			// IMPORTANT: Block bodies are arbitrary template source. They must be returned
			// from callbacks as literal strings and must never be passed as preg_replace()
			// replacement text, where backslashes and $1/$2-style sequences have semantics.

			if ($orderedMerge) {
				foreach ($blocks as $name => $content) {
					$layout = \preg_replace_callback(
						'/{%\s*yield\s*' . \preg_quote((string)$name, '/') . '\s*%}/',
						static fn(array $match): string => $content,
						$layout,
						-1,
						$count
					);
					if ($layout === null) {
						throw new \RuntimeException("TemplateEngine: Regex replace failed for block '{$name}'.");
					}
					if ($count === 0) {
						throw new \RuntimeException("TemplateEngine: Block '{$name}' from child layer '{$currentLayer}' was not yielded in parent template: " . $layoutPath);
					}
				}
			} else {
				// Normal case: Walk all parent yields once. Matching child blocks are inserted
				// literally and recorded in $used so orphan child blocks can be reported below.
				$used = [];
				$layout = \preg_replace_callback(self::YIELD_PATTERN, static function (array $match) use ($blocks, &$used): string {
					$name = $match[1];
					if (!isset($blocks[$name])) {
						return $match[0];
					}
					$used[$name] = true;
					return $blocks[$name];
				}, $layout);
				if ($layout === null) {
					throw new \RuntimeException('TemplateEngine: PCRE error while merging child blocks.');
				}
				foreach ($blocks as $name => $content) {
					if (!isset($used[$name])) {
						throw new \RuntimeException("TemplateEngine: Block '{$name}' from child layer '{$currentLayer}' was not yielded in parent template: " . $layoutPath);
					}
				}
			}


			// -- 5. Reject unresolved parent yields --------------------------
			// At this point every child block must have matched a parent yield, and every
			// remaining parent yield means required content was never supplied by the child.
			// Fail fast instead of silently rendering an incomplete layout.

			$leftoverCount = \preg_match_all(self::YIELD_PATTERN, $layout, $leftover);
			if ($leftoverCount === false) {
				throw new \RuntimeException('TemplateEngine: PCRE error while validating remaining yields.');
			}
			if ($leftoverCount > 0) {
				$list = "'" . \implode("', '", \array_unique($leftover[1])) . "'";
				throw new \RuntimeException("TemplateEngine: Missing child blocks {$list} from layer '{$currentLayer}' in parent template: " . $layoutPath);
			}


			// -- 6. Continue with the merged parent as the next child --------
			// The merged parent may itself extend another layout. Reuse it as the next
			// child and continue iteratively, e.g. app -> citomni/admin -> citomni/http.

			$code = $layout;
			$currentLayer = $parentLayer;
		}
	}


	/**
	 * Expand explicit `{% include "path@layer" %}` directives recursively at compile time.
	 *
	 * Behavior:
	 * - Returns immediately when the source contains no template directive marker or no include directive.
	 * - Enforces the existing maximum include depth.
	 * - Resolves each include through splitRef() and loadSource(), sharing source snapshots with the rest
	 *   of the current compilation attempt.
	 * - Detects circular includes by canonical source path while the dependency is active on the stack.
	 * - Inserts included source through a callback, preserving it literally before recursively expanding
	 *   nested includes.
	 *
	 * Notes:
	 * - Included source is already comment-stripped by loadSource().
	 * - Because comments are removed before include scanning, a commented-out directive such as
	 *   `{# {% include "debug/panel@app" %} #}` is never loaded and never becomes a cache dependency.
	 * - Template syntax is compiled only after all includes have been expanded.
	 * - resolveSourcePath() enforces layer-root containment for every included source.
	 *
	 * @param  string  $code  Comment-stripped source before syntax compilation.
	 * @param  int  $depth  Current include depth, starting at zero.
	 * @param  array  $context  Compilation-local source snapshots and include stack.
	 * @return string  Source with all reachable include directives expanded.
	 * @throws \RuntimeException  On cycles, excessive depth, source failure or PCRE failure.
	 */
	private function processIncludes(string $code, int $depth, array &$context): string {

		// -- 1. Detect include work and enforce depth ---------------------
		// loadSource() already removed template comments. Consequently a commented-out
		// include such as `{# {% include "debug/panel@app" %} #}` is invisible here:
		// it is never resolved, never loaded and never recorded as a cache dependency.

		if (!\str_contains($code, '{%')) {
			return $code;
		}
		$hasInclude = \preg_match(self::INCLUDE_PATTERN, $code);
		if ($hasInclude === false) {
			throw new \RuntimeException('TemplateEngine: PCRE error while detecting includes.');
		}
		if ($hasInclude === 0) {
			return $code;
		}
		if ($depth >= self::MAX_INCLUDE_DEPTH) {
			throw new \RuntimeException('TemplateEngine: Include depth exceeded.');
		}


		// -- 2. Expand includes recursively with an active-path stack -----
		// Includes are expanded before syntax compilation, so the final compiled PHP sees
		// one flattened source tree. Callback insertion keeps included template bytes
		// literal rather than treating backslashes/$n sequences as replacement syntax.

		$expanded = \preg_replace_callback(self::INCLUDE_PATTERN, function (array $match) use ($depth, &$context): string {
			// Split the explicit path@layer ref, then load it through the shared context so
			// source bytes and dependency metadata are reused if already seen this attempt.
			[$rel, $layer] = $this->splitRef($match[1]);
			[$included, $path] = $this->loadSource($rel, $layer, $context);
			// includeStack contains only the currently active recursion path. Reusing the same
			// partial later in a different branch is valid; A -> B -> A is not.
			if (isset($context['includeStack'][$path])) {
				throw new \RuntimeException("TemplateEngine: Circular include at '{$rel}@{$layer}'.");
			}
			$context['includeStack'][$path] = true;
			try {
				// Resolve nested includes before inserting this partial into its caller.
				return $this->processIncludes($included, $depth + 1, $context);
			} finally {
				unset($context['includeStack'][$path]);
			}
		}, $code);
		if ($expanded === null) {
			throw new \RuntimeException('TemplateEngine: PCRE error during processIncludes().');
		}
		return $expanded;
	}








	// ----------------------------------------------------------------
	// Template syntax and safe markup optimization
	// ----------------------------------------------------------------

	/**
	 * Compile the established CitOmni template grammar into executable PHP and literal markup.
	 *
	 * Behavior:
	 * - Output:
	 *   1) `{{ expr }}` emits HTML-escaped output using the runtime charset.
	 *   2) `{{{ expr }}}` emits raw output for trusted markup.
	 * - Assignment:
	 *   1) `{% set $name = expr %}` assigns to a native local PHP variable.
	 *   2) The `$` prefix on the variable name is required.
	 * - Control flow:
	 *   1) `{% if expr %} ... {% endif %}`
	 *   2) `{% elseif expr %}`
	 *   3) `{% else %}`
	 *   4) `{% foreach ($items as $item) %} ... {% endforeach %}`; parentheses are required.
	 *   5) `{% continue %}` and `{% continue N %}` with an optional trailing semicolon.
	 *   6) `{% break %}` and `{% break N %}` with an optional trailing semicolon.
	 * - Inline PHP:
	 *   1) `{?= expr ?}` emits a raw PHP echo when `allow_php_tags` is enabled.
	 *   2) `{? ... ?}` emits a raw PHP block when `allow_php_tags` is enabled.
	 *   3) Both custom inline-PHP forms are removed when `allow_php_tags` is disabled.
	 * - Structural syntax resolved before or around this phase:
	 *   1) `{% extends "file@layer" %}`
	 *   2) `{% block name %}...{% endblock %}`
	 *   3) `{% yield name %}`
	 *   4) `{% include "file@layer" %}`
	 *   5) `{# ... #}` template comments, including nested comments.
	 * - Strips defensive leftovers for block/endblock/yield/extends markers after inheritance resolution.
	 * - Applies replacements in the historic order so existing template expression semantics remain intact.
	 *
	 * Notes:
	 * - Template expressions are native PHP expressions, not Twig syntax or a separate expression parser.
	 * - Native PHP already present in trusted templates is not removed or sandboxed.
	 * - `{{ ... }}` is escaped; `{{{ ... }}}` is not. Use raw output only for trusted markup.
	 * - Includes and inheritance always use explicit `relative/path.html@layer` references.
	 * - Numeric `$1` / `$2` references in this method are compiler-owned replacement templates; arbitrary
	 *   child/include source is never passed as a preg_replace() replacement string.
	 * - No filesystem I/O occurs here.
	 *
	 * Typical usage:
	 *   $php = $this->compileSyntax($flattenedTemplateSource);
	 *
	 * @param  string  $code  Flattened template source after inheritance and include expansion.
	 * @return string  Executable PHP mixed with literal markup.
	 * @throws \RuntimeException  On PCRE failure.
	 */
	private function compileSyntax(string $code): string {

		// -- 1. Skip source with no template marker -----------------------

		if (!\str_contains($code, '{')) {
			return $code;
		}


		// -- 2. Define the established grammar in replacement order -------

		static $patterns = [
			// Custom inline PHP. These are controlled by allow_php_tags.
			'/{\?=\s*(.+?)\s*\?}/s',
			'/{\?(.+?)\?}/s',

			// Output. Triple braces are raw; double braces are escaped.
			'/\{\{\{\s*(.+?)\s*\}\}\}/s',
			'/\{\{\s*(.+?)\s*\}\}/s',

			// Local assignment. The template variable name must include the leading $.
			'/{%\s*set\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+?)\s*%}/s',

			// Conditional control flow. Expressions remain native PHP expressions.
			'/{%\s*if\s*(.+?)\s*%}/',
			'/{%\s*elseif\s*(.+?)\s*%}/',
			'/{%\s*else\s*%}/',
			'/{%\s*endif\s*%}/',

			// foreach requires the parenthesized native PHP foreach expression.
			'/{%\s*foreach\s*\((.+?)\)\s*%}/',
			'/{%\s*endforeach\s*%}/',

			// Loop control accepts an optional numeric level and optional semicolon.
			'/{%\s*continue\s*((?:\s+\d+)?)\s*;?\s*%}/',
			'/{%\s*break\s*((?:\s+\d+)?)\s*;?\s*%}/',

			// Structural markers should already be resolved. Strip leftovers defensively
			// instead of allowing template directives to leak into rendered output.
			'/{%\s*block\s+([\w-]+)\s*%}/',
			'/{%\s*endblock\s*%}/',
			'/{%\s*yield\s*([\w-]+)\s*%}/',
			'/{%\s*extends\s+["\'](.+?)["\']\s*%}/',
		];

		// Keep this array in exactly the same order as $patterns above. The $1/$2
		// references here are intentional compiler-owned replacements. Arbitrary child
		// block/include source is inserted elsewhere through callbacks and never lands here.
		$replacements = [
			// Custom inline PHP. Disabled forms compile to an empty string.
			$this->allowPhpTags ? '<?php echo $1; ?>' : '',
			$this->allowPhpTags ? '<?php $1 ?>' : '',

			// Raw and escaped output.
			'<?php echo $1; ?>',
			'<?php echo htmlspecialchars($1 ?? "", \ENT_QUOTES | \ENT_SUBSTITUTE, $charset ?? "UTF-8"); ?>',

			// Assignment and control flow.
			'<?php $$1 = $2; ?>',
			'<?php if ($1): ?>',
			'<?php elseif ($1): ?>',
			'<?php else: ?>',
			'<?php endif; ?>',
			'<?php foreach ($1): ?>',
			'<?php endforeach; ?>',
			'<?php continue$1; ?>',
			'<?php break$1; ?>',

			// Defensive structural cleanup.
			'', '', '', '',
		];


		// -- 3. Compile template directives to PHP ------------------------

		$result = \preg_replace($patterns, $replacements, $code);
		if ($result === null) {
			throw new \RuntimeException('TemplateEngine: PCRE error during compileSyntax().');
		}
		return $result;
	}


	/**
	 * Remove nested CitOmni `{# ... #}` template comments while preserving legacy edge cases.
	 *
	 * Behavior:
	 * - Uses a token-offset scan over comment open/close markers with a nesting-depth counter.
	 * - Removes nested comment blocks completely.
	 * - Leaves stray `#}` markers outside an active comment untouched.
	 * - Treats an unclosed comment as extending to end-of-file, discarding the remaining source.
	 *
	 * Notes:
	 * - The fast path returns immediately when no `{#` opener exists.
	 * - This behavior intentionally matches the previous engine even though the implementation is now
	 *   based on preg_match_all() offsets rather than a byte-by-byte scanner.
	 *
	 * Typical usage:
	 *   `{# outer {# inner #} #}` becomes an empty string.
	 *
	 * @param  string  $code  Template source containing zero or more comment markers.
	 * @return string  Comment-stripped source.
	 * @throws \RuntimeException  On PCRE failure.
	 */
	private function removeTemplateComments(string $code): string {


		// -- 1. Locate nested comment markers -----------------------------

		if (!\str_contains($code, '{#')) {
			return $code;
		}
		if (\preg_match_all('/\{#|#\}/', $code, $tokens, \PREG_OFFSET_CAPTURE) === false) {
			throw new \RuntimeException('TemplateEngine: PCRE error while stripping template comments.');
		}


		// -- 2. Copy only source observed outside comment depth -----------

		$depth = 0;
		$cursor = 0;
		$out = '';
		foreach ($tokens[0] as [$token, $offset]) {
			if ($token === '{#') {
				if ($depth === 0) {
					$out .= \substr($code, $cursor, $offset - $cursor);
				}
				$depth++;
			} elseif ($depth > 0) {
				$depth--;
				if ($depth === 0) {
					$cursor = $offset + 2;
				}
			}
		}
		return $depth === 0 ? $out . \substr($code, $cursor) : $out;
	}


	/**
	 * Apply optional HTML-comment and whitespace optimization without changing protected content.
	 *
	 * Behavior:
	 * - Uses Zend's tokenizer to separate compiled PHP fragments from literal markup before optimization.
	 * - Masks PHP strings, comments, heredocs/nowdocs and other PHP tokens with collision-free markers.
	 * - Protects quoted HTML tags and complete `pre`, `code`, `textarea`, `script` and `style` regions.
	 * - Removes ordinary HTML comments only when `remove_html_comments` is enabled while preserving the
	 *   established conditional-comment rule and conservatively retaining unclosed comments.
	 * - Collapses redundant whitespace only in unprotected literal text and only when `trim_whitespace`
	 *   is enabled.
	 * - Restores PHP fragments byte-for-byte after markup processing.
	 *
	 * Notes:
	 * - This runs only while compiling a template, never as an output-buffer filter on warm renders.
	 * - The optimizer is deliberately conservative; it is not a full HTML parser, CSS/JS minifier or
	 *   canonicalizer.
	 * - Protecting PHP fragments prevents the old behavior where whitespace optimization could mutate
	 *   PHP string literals or line comments.
	 *
	 * @param  string  $code  Compiled PHP mixed with literal HTML.
	 * @return string  Optimized PHP and HTML.
	 * @throws \RuntimeException  On PCRE failure.
	 */
	private function optimizeMarkup(string $code): string {

		// -- 1. Protect compiled PHP fragments ----------------------------

		$php = [];
		$markup = $code;
		if (\str_contains($code, '<?')) {
			$marker = "\x1ACITOMNI_PHP_";
			while (\str_contains($code, $marker)) {
				$marker .= '_';
			}
			$markup = '';
			$fragment = '';
			foreach (\token_get_all($code) as $token) {
				if (\is_array($token) && $token[0] === \T_INLINE_HTML) {
					if ($fragment !== '') {
						$key = $marker . \count($php) . "\x1A";
						$php[$key] = $fragment;
						$markup .= $key;
						$fragment = '';
					}
					$markup .= $token[1];
				} else {
					$fragment .= \is_array($token) ? $token[1] : $token;
				}
			}
			if ($fragment !== '') {
				$key = $marker . \count($php) . "\x1A";
				$php[$key] = $fragment;
				$markup .= $key;
			}
		}


		// -- 2. Locate protected markup regions ---------------------------
		// Match whole quoted tags and raw-text regions before optimizing the text between them.
		$pattern = '~<(pre|code|textarea|script|style)\b(?:[^\'">]|"[^"]*"|\'[^\']*\')*>.*?(?:</\1\s*>|\z)|<!--.*?(?:-->|\z)|<(?:[^\'">]|"[^"]*"|\'[^\']*\')*>~is';
		if (\preg_match_all($pattern, $markup, $matches, \PREG_OFFSET_CAPTURE) === false) {
			throw new \RuntimeException('TemplateEngine: PCRE error while protecting markup.');
		}


		// -- 3. Optimize unprotected text and restore PHP -----------------

		$out = '';
		$cursor = 0;
		foreach ($matches[0] as [$protected, $offset]) {
			$out .= $this->collapseWhitespace(\substr($markup, $cursor, $offset - $cursor));
			if (!$this->removeHtmlComments || !\str_starts_with($protected, '<!--')
				|| !\preg_match('/\A<!--(?!<!)[^\[>].*?-->\z/s', $protected)) {
				$out .= $protected;
			}
			$cursor = $offset + \strlen($protected);
		}
		$out .= $this->collapseWhitespace(\substr($markup, $cursor));
		return $php === [] ? $out : \strtr($out, $php);
	}


	/**
	 * Collapse redundant whitespace in an already unprotected literal-text fragment.
	 *
	 * Behavior:
	 * - Returns input unchanged when `trim_whitespace` is disabled or the fragment is empty.
	 * - Replaces runs of two or more whitespace characters with one ordinary space otherwise.
	 *
	 * Notes:
	 * - optimizeMarkup() is responsible for ensuring PHP, quoted tags and sensitive raw-text elements
	 *   never reach this helper as collapsible text.
	 *
	 * @param  string  $text  Unprotected literal-text fragment.
	 * @return string  Original or whitespace-collapsed text.
	 * @throws \RuntimeException  On PCRE failure.
	 */
	private function collapseWhitespace(string $text): string {
		if (!$this->trimWhitespace || $text === '') {
			return $text;
		}
		$result = \preg_replace('/\s{2,}/', ' ', $text);
		if ($result === null) {
			throw new \RuntimeException('TemplateEngine: PCRE error while collapsing whitespace.');
		}
		return $result;
	}







	// ----------------------------------------------------------------
	// Diagnostics and configuration normalization
	// ----------------------------------------------------------------

	/**
	 * Emit the depth-limited template-variable diagnostic as an HTML comment in dev/stage.
	 *
	 * Behavior:
	 * - Returns immediately outside `dev` and `stage` environments.
	 * - Normalizes the final variable payload recursively with a hard depth limit of 10.
	 * - Tracks repeated/cyclic objects, annotates closures and resources, and snapshots public object
	 *   properties without attempting arbitrary serialization.
	 * - Escapes the generated dump before placing it inside the HTML comment so variable payloads cannot
	 *   inject a comment terminator into the response.
	 *
	 * Notes:
	 * - render() invokes this automatically when the request contains `?_viewvars`.
	 * - This method writes directly to output before the compiled template is required.
	 * - The diagnostic may expose internal request/application state and therefore never runs in prod.
	 *
	 * @param  array<string,mixed>  $vars  Final merged template-variable payload.
	 * @return void
	 */
	private function printViewVars(array $vars): void {

		// -- 1. Restrict diagnostics to non-production environments -------
		// Resolve current environment (fallback "prod" if undefined).
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';

		// Only allow debug output in dev/stage.
		if ($env !== 'dev' && $env !== 'stage') {
			return;
		}


		// -- 2. Normalize the payload defensively -------------------------
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
				if ($seen->offsetExists($v)) {
					return '[' . \get_debug_type($v) . ' (seen)]';
				}
				$seen->offsetSet($v);

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


		// -- 3. Emit one escaped diagnostic HTML comment ------------------
		// Produce a safe, serializable version of $vars.
		$normalized = $normalize($vars);

		// Emit as HTML comment so it won't affect DOM/layout.
		echo "<!--\n=== CitOmni TemplateEngine Vars (environment: {$env}) ===\n";
		echo \htmlspecialchars(\print_r($normalized, true), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		echo "\n=== End Vars ===\n-->\n";
	}


	/**
	 * Normalize an associative configuration node into a plain PHP array.
	 *
	 * Behavior:
	 * - Returns plain arrays unchanged.
	 * - Calls `toArray()` on Cfg-style wrapper objects when available.
	 * - Returns an empty array for null, scalar or unsupported object values.
	 *
	 * Notes:
	 * - This helper deliberately performs no recursive schema validation; callers validate the entries
	 *   they consume.
	 *
	 * @param  mixed  $node  Configuration array, Cfg-style wrapper or absent/unsupported value.
	 * @return array  Normalized map, or an empty array when no map is available.
	 */
	private function normalizeCfgMap(mixed $node): array {
		if (\is_array($node)) {
			return $node;
		}
		if (\is_object($node) && \method_exists($node, 'toArray')) {
			$out = $node->toArray();
			return \is_array($out) ? $out : [];
		}
		return [];
	}


}
