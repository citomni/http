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
 * TemplateEngine: Service that compiles CitOmni templates to PHP and renders them.
 *
 * Gives controllers and other services one way to turn a template and its variables into
 * markup. A template is named by an explicit reference, "relative/path.html@layer", where the
 * layer is a key of cfg view.template_layers; there is no implicit template and no search
 * across layers. Templates compile to PHP files under the app's var/cache, which later
 * renders can reuse while the sources are unchanged. Which template answers a request belongs
 * to the caller, the value of a cfg view.vars entry to its source, and the work behind a
 * template helper to the service it calls.
 *
 * Templates and the cache directory are trusted: What they hold runs as PHP inside this
 * engine, with access to the App, and nothing here sandboxes it. Never let an untrusted user
 * choose or change a template.
 *
 * Rules:
 * - Read template source only below the canonical root of its layer.
 * - Make every setting that changes the compiled PHP part of the cache signature.
 * - Never delete a compiled generation while rendering; pruning belongs to cache maintenance.
 * - Assume one request per instance: Request-bound values, such as the globals, may be kept
 *   for the lifetime of the instance.
 */
class TemplateEngine extends BaseService {

	// ----------------------------------------------------------------
	// Compiler contract and state
	// ----------------------------------------------------------------

	/** Format version of compiled generations and manifests; raise it when the compiler emits other PHP for the same source or the manifest changes. */
	private const string COMPILER_VERSION = '3';

	/** Maximum nesting depth of includes. */
	private const int MAX_INCLUDE_DEPTH = 16;

	/** Maximum number of parent layouts in one inheritance chain. */
	private const int MAX_INHERITANCE_DEPTH = 64;

	/** Pattern of an extends directive; group 1 is the parent reference. */
	private const string EXTENDS_PATTERN = '/{%\s*extends\s+["\'](.+?)["\']\s*%}/';

	/** Pattern of an include directive, case-insensitive; group 1 is the reference. */
	private const string INCLUDE_PATTERN = '/{%\s*include\s+["\'](.+?)["\']\s*%}/i';

	/** Pattern of a block with its body, which ends at the first endblock; group 1 is the name, group 2 the body. */
	private const string BLOCK_PATTERN = '/{%\s*block\s+([\w-]+)\s*%}(.*?){%\s*endblock\s*%}/s';

	/** Pattern of a yield directive; group 1 is the block name. */
	private const string YIELD_PATTERN = '/{%\s*yield\s*([\w-]+)\s*%}/';


	/** @var array<string,string> Template root of each layer slug, with a trailing slash. */
	private array $layersMap = [];

	/** Absolute directory of compiled generations, manifests and lock files. */
	private string $cacheDir = '';

	/** Hash of every setting that changes the PHP compiled from one template reference. */
	private string $cacheSignature = '';

	/** Whether a fresh compiled generation is reused instead of compiling again. */
	private bool $cacheEnabled = false;

	/** Whether compilation collapses whitespace runs in unprotected literal markup. */
	private bool $trimWhitespace = false;

	/** Whether compilation removes ordinary HTML comments from literal markup. */
	private bool $removeHtmlComments = false;

	/** Whether "{? ... ?}" and "{?= ... ?}" compile to PHP; when false, they compile to nothing. */
	private bool $allowPhpTags = true;

	/** Charset of escaped output and of the charset global, from cfg locale.charset. */
	private string $charset = 'UTF-8';

	/** Default cache-busting token that $asset() appends; empty for none. */
	private string $assetVersion = '';

	/** Trusted raw markup exposed as the marketing_scripts global. */
	private string $marketingScripts = '';

	/** @var array<string,array{type:'static'|'dynamic',data?:mixed,call?:mixed,ire:list<string>,ere:list<string>}> Entries of cfg view.vars by variable name: data is a static value, call a dynamic source, ire and ere the compiled include and exclude patterns. */
	private array $compiledVars = [];

	/** @var array<string,mixed>|null Globals and helper closures of this request, or null until the first render builds them. */
	private ?array $globals = null;

	/** @var array<string,array{format:string,generation:string,dependencies:array<array{string,string,string,list<int>}>}> Manifests by cache prefix; their sources are checked again before every reuse. */
	private array $manifests = [];

	/** @var array<string,string> Cache prefix by "path@layer" reference. */
	private array $cachePrefixes = [];







	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Derive the layers, compile settings, scoped variables and cache signature once.
	 *
	 * Behavior:
	 * - The options cache_enabled, trim_whitespace, remove_html_comments, allow_php_tags and
	 *   asset_version win over the cfg view key of the same name.
	 * - Accepts "app" and "vendor/package" slugs as keys of cfg view.template_layers. A root
	 *   directory is not resolved here; a missing one fails when a template needs it.
	 * - Skips a cfg view.vars entry that is not a non-empty array. Every other entry needs a type,
	 *   static or dynamic, and a source; a dynamic source is checked only when it is called.
	 * - Uses cfg locale.charset, or UTF-8 without it, as the charset of escaped output.
	 * - Creates no directory and writes no file.
	 *
	 * @return void
	 * @throws \InvalidArgumentException  When a key of cfg view.template_layers is not "app" or a vendor/package slug, or its value is not a non-empty string.
	 * @throws \RuntimeException  When a cfg view.vars entry has no type or source, or a type other than static or dynamic.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {

		// -- 1. Snapshot configuration and service options ---------------
		// The view settings are copied to typed properties below, so compile and render paths
		// never walk Cfg for them, and the options are not needed afterwards.

		$viewCfg = $this->app->cfg->view;
		$opt = $this->options;
		$this->options = [];


		// -- 2. Normalize and validate template layers -------------------
		// Roots are not resolved here, so init() does no IO, and resolveSourcePath() resolves a
		// symlinked root again in every pass, which sees a deployment that switches it.

		foreach ($this->normalizeCfgMap($viewCfg->template_layers) as $layerKey => $path) {
			$layer = (string)$layerKey;
			if ($layer !== 'app' && !\preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~i', $layer)) {
				throw new \InvalidArgumentException("TemplateEngine: Invalid layer slug '{$layer}'.");
			}
			if (!\is_string($path) || $path === '') {
				throw new \InvalidArgumentException("TemplateEngine: template_layers['{$layer}'] must be a non-empty string (absolute directory).");
			}
			$this->layersMap[$layer] = \rtrim($path, '/\\') . '/';
		}


		// -- 3. Snapshot compile settings --------------------------------

		$this->cacheEnabled = (bool)($opt['cache_enabled'] ?? $viewCfg->cache_enabled);
		$this->trimWhitespace = (bool)($opt['trim_whitespace'] ?? $viewCfg->trim_whitespace);
		$this->removeHtmlComments = (bool)($opt['remove_html_comments'] ?? $viewCfg->remove_html_comments);
		$this->allowPhpTags = (bool)($opt['allow_php_tags'] ?? $viewCfg->allow_php_tags);

		// locale.charset is app cfg; the fallback assumes the kernel also defaults to UTF-8.
		// compileSyntax() emits it as a literal, so a template variable named charset cannot
		// change escaping.
		$this->charset = (string)($this->app->cfg->locale->charset ?? 'UTF-8');


		// -- 4. Snapshot passthrough view configuration ------------------

		$this->assetVersion = (string)($opt['asset_version'] ?? $viewCfg->asset_version);
		$this->marketingScripts = (string)$viewCfg->marketing_scripts;


		// -- 5. Precompile path-scoped variable definitions --------------
		// The include and exclude patterns are compiled once, so a render only runs
		// preg_match(). A dynamic source is checked when invokeProvider() calls it.

		foreach ($this->normalizeCfgMap($viewCfg->vars) as $varName => $row) {
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


		// -- 6. Build deterministic persistent-cache identity ------------
		// The cache directory is fixed on purpose: It is not a cfg key. The signature covers
		// every setting that changes the PHP compiled from one reference; the layer order does
		// not, so the map is sorted before hashing.

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
			$this->charset,
			$identityLayers,
		]));
	}







	// ----------------------------------------------------------------
	// Rendering and variable assembly
	// ----------------------------------------------------------------

	/**
	 * Render a template to the output.
	 *
	 * Behavior:
	 * - $data wins over a matching cfg view.vars entry, which wins over the globals and helpers.
	 * - When the query string has _viewvars, prints the variables as an HTML comment before the
	 *   template, in the dev and stage environments only.
	 * - Exceptions from the template and its helpers pass through.
	 *
	 * Typical usage:
	 *   $this->app->tplEngine->render('member/home.html@app', ['title' => 'Home']);
	 *
	 * @param  string  $ref  Template reference, "relative/path.html@layer".
	 * @param  array<string,mixed>  $data  Variables for the template, by name.
	 * @return void
	 * @throws \InvalidArgumentException  When $ref, or a reference in an extends or include directive, has no "@", an empty path or layer, or a NUL byte in the path.
	 * @throws \RuntimeException  When the layer is unknown, a template cannot be loaded or compiled, the cache cannot be locked or written, or a matching dynamic cfg view.vars source cannot be called.
	 * @see \CitOmni\Http\Service\TemplateEngine::compile()  The template language and the cache.
	 * @see \CitOmni\Http\Service\TemplateEngine::executeCompiled()  The scope a template runs in.
	 */
	public function render(string $ref, array $data = []): void {
		$vars = $this->buildFinalVars($data);
		if ($this->app->request->get('_viewvars') !== null) {
			$this->printViewVars($vars);
		}
		$this->executeCompiled($this->compile($ref), $vars);
	}


	/**
	 * Render a template and return its output.
	 *
	 * Behavior:
	 * - Same variables and template as render(), without the _viewvars diagnostic.
	 * - Closes the output buffer it opens also when the template throws; the exception passes
	 *   through.
	 *
	 * Typical usage:
	 *   $html = $this->app->tplEngine->renderToString('mail/reset.html@citomni/authenticate', ['identity' => $identity]);
	 *
	 * @param  string  $ref  Template reference, "relative/path.html@layer".
	 * @param  array<string,mixed>  $data  Variables for the template, by name.
	 * @return string
	 * @throws \InvalidArgumentException  When $ref, or a reference in an extends or include directive, has no "@", an empty path or layer, or a NUL byte in the path.
	 * @throws \RuntimeException  When the layer is unknown, a template cannot be loaded or compiled, the cache cannot be locked or written, or a matching dynamic cfg view.vars source cannot be called.
	 * @see \CitOmni\Http\Service\TemplateEngine::render()  The variables a template receives.
	 */
	public function renderToString(string $ref, array $data = []): string {
		$vars = $this->buildFinalVars($data);
		$file = $this->compile($ref);
		\ob_start();
		try {
			$this->executeCompiled($file, $vars);
			return (string)\ob_get_contents();
		} finally {
			\ob_end_clean();
		}
	}


	/**
	 * Run a compiled generation with the final template variables as its locals.
	 *
	 * Takes two positional arguments that the signature deliberately does not declare:
	 * 1) the absolute path of the compiled generation, 2) the final variable map.
	 *
	 * Behavior:
	 * - The template runs with $this bound to this engine and can reach $this->app.
	 * - Every variable becomes a local under its own key. A key named this, or one that is not a
	 *   valid variable name, is skipped.
	 * - Template output and exceptions pass through unchanged.
	 *
	 * @return void
	 */
	private function executeCompiled(): void {
		// No parameters and no locals: extract() skips keys that already exist, so any name
		// declared here would hide the variable with that key from the template.
		\extract(\func_get_arg(1), \EXTR_SKIP);
		require \func_get_arg(0);
	}


	/**
	 * Merge the variables of one render.
	 *
	 * Behavior:
	 * - $data wins over a matching cfg view.vars entry, which wins over the globals and helpers.
	 * - Builds the globals on the first call and keeps them; calls the matching dynamic sources on
	 *   every call.
	 *
	 * @param  array<string,mixed>  $data  Variables from the caller.
	 * @return array<string,mixed>
	 * @throws \RuntimeException  When a matching dynamic cfg view.vars source cannot be called.
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
	 * Build the globals and helper closures that every render receives.
	 *
	 * Behavior:
	 * - base_url is cfg http.base_url, or CITOMNI_PUBLIC_ROOT_URL when the key is empty and the
	 *   constant is defined. public_root_url is the constant when it is defined, otherwise base_url.
	 * - app_name and language are cfg identity.app_name and locale.language, or '' without them.
	 *   charset is the charset of escaped output.
	 * - marketing_scripts is cfg view.marketing_scripts.
	 * - csrf_protection, honeypot_protection, form_action_switching and captcha_protection are cfg
	 *   security.csrf.enabled, security.honeypot_protection, security.form_action_switching and
	 *   security.captcha_protection.
	 * - env.name is CITOMNI_ENVIRONMENT, or prod when it is not defined; env.dev is true only in dev.
	 * - A helper reaches its service only when a template calls it.
	 *
	 * @return array<string,mixed>  Values and helper closures by variable name.
	 */
	private function buildGlobals(): array {
		$cfg = $this->app->cfg;

		$baseUrl = (string)$cfg->http->base_url;
		if ($baseUrl === '' && \defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$baseUrl = (string)\CITOMNI_PUBLIC_ROOT_URL;
		}

		$publicUrl = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? (string)\CITOMNI_PUBLIC_ROOT_URL
			: $baseUrl;


		return [
			'app_name'	=> (string)($cfg->identity->app_name ?? ''),
			'base_url'	=> $baseUrl,
			'public_root_url' => $publicUrl,
			'language'	=> (string)($cfg->locale->language ?? ''),
			'charset'	=> $this->charset,

			'marketing_scripts' => $this->marketingScripts,

			'csrf_protection'		=> (bool)$cfg->security->csrf->enabled,
			'honeypot_protection'	=> (bool)$cfg->security->honeypot_protection,
			'form_action_switching'	=> (bool)$cfg->security->form_action_switching,
			'captcha_protection'	=> (bool)$cfg->security->captcha_protection,

			'env' => [
				'name'	=> \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod',
				'dev'	=> \defined('CITOMNI_ENVIRONMENT') ? (\CITOMNI_ENVIRONMENT === 'dev') : false,
			],


			/**
			 * $txt: Return a translated text.
			 *
			 * Typical usage:
			 *   {{ $txt('login_title', 'authenticate', 'citomni/authenticate') }}
			 *
			 * @param  string  $key  Text key.
			 * @param  string  $file  Language file, without ".php".
			 * @param  string|null  $layer  Layer of the language file; null for app.
			 * @param  string  $default  Fallback text.
			 * @param  array<string,mixed>  $vars  Placeholder values.
			 * @return string
			 * @throws \RuntimeException  When the txt service is not registered or App::hasPackage('citomni/infrastructure') is false.
			 * @see \CitOmni\Infrastructure\Service\Txt::get()  Lookup, fallback and placeholders.
			 */
			'txt' => function (string $key, string $file, ?string $layer = null, string $default = '', array $vars = []) {
				if (!$this->app->hasService('txt') || !$this->app->hasPackage('citomni/infrastructure')) {
					throw new \RuntimeException(
						"Text service not available. Install 'citomni/infrastructure' and register 'txt' in /config/providers.php."
					);
				}
				// Null stands for the default layer of Txt::get(), assumed to be 'app'.
				return $this->app->txt->get($key, $file, $layer ?? 'app', $default, $vars);
			},


			/**
			 * $dt: Format a moment with an ICU pattern.
			 *
			 * Typical usage:
			 *   {{ $dt($user['created_at'], 'yyyy-MM-dd HH:mm') }}
			 *
			 * @param  null|string|int|\DateTimeInterface  $when  Moment; null for now.
			 * @param  string  $pattern  ICU date and time pattern.
			 * @param  string|null  $tzName  Timezone override.
			 * @param  string|null  $locale  Locale override, such as en_US.
			 * @return string
			 * @throws \RuntimeException  When the datetime service is not registered.
			 * @see \CitOmni\Http\Service\Datetime::format()  Accepted values and failures.
			 */
			'dt' => function (null|string|int|\DateTimeInterface $when, string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->format($when, $pattern, $tzName, $locale);
			},


			/**
			 * $dtNow: Format now with an ICU pattern.
			 *
			 * @param  string  $pattern  ICU date and time pattern.
			 * @param  string|null  $tzName  Timezone override.
			 * @param  string|null  $locale  Locale override, such as en_US.
			 * @return string
			 * @throws \RuntimeException  When the datetime service is not registered.
			 * @see \CitOmni\Http\Service\Datetime::now()
			 */
			'dtNow' => function (string $pattern, ?string $tzName = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->now($pattern, $tzName, $locale);
			},


			/**
			 * $dtMonth: Return the localized name of a month.
			 *
			 * Typical usage:
			 *   {{ $dtMonth(10, 'short') }}
			 *
			 * @param  int  $month  1 to 12.
			 * @param  string|null  $form  full, short or narrow; null for full.
			 * @param  string|null  $locale  Locale override, such as en_US.
			 * @return string
			 * @throws \RuntimeException  When the datetime service is not registered.
			 * @see \CitOmni\Http\Service\Datetime::month()
			 */
			'dtMonth' => function (int $month, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->month($month, $form, $locale);
			},


			/**
			 * $dtWeekday: Return the localized name of an ISO weekday.
			 *
			 * @param  int  $isoWeekday  1 for Monday to 7 for Sunday.
			 * @param  string|null  $form  full, short or narrow; null for full.
			 * @param  string|null  $locale  Locale override, such as en_US.
			 * @return string
			 * @throws \RuntimeException  When the datetime service is not registered.
			 * @see \CitOmni\Http\Service\Datetime::weekday()
			 */
			'dtWeekday' => function (int $isoWeekday, ?string $form = null, ?string $locale = null): string {
				if (!$this->app->hasService('datetime')) {
					throw new \RuntimeException("Datetime service not available.");
				}
				return $this->app->datetime->weekday($isoWeekday, $form, $locale);
			},


			/**
			 * $url: Return the absolute URL of an app path.
			 *
			 * Behavior:
			 * - Joins base_url and the trimmed $path with exactly one slash; $path is never taken as an
			 *   absolute URL.
			 * - Appends $query with http_build_query() when it is not empty.
			 *
			 * Typical usage:
			 *   {{ $url('/search', ['q' => $q, 'page' => 2]) }}
			 *
			 * @param  string  $path  App path, with or without a leading slash.
			 * @param  array<string,mixed>  $query  Query parameters.
			 * @return string
			 */
			'url' => function (string $path = '', array $query = []) use ($baseUrl): string {
				$p = '/' . \ltrim(\trim($path), '/');

				if ($query !== []) {
					$p .= '?' . \http_build_query($query);
				}

				return \rtrim($baseUrl, '/') . $p;
			},


			/**
			 * $asset: Return the absolute URL of an asset with a cache-busting version.
			 *
			 * Behavior:
			 * - Returns an http or https URL unchanged, without a version.
			 * - Otherwise joins base_url and $path, and appends "v=" and the URL-encoded version after
			 *   "?", or after "&" when the URL already has a query. An empty version appends nothing.
			 *
			 * Typical usage:
			 *   {{ $asset('/assets/app.css') }}
			 *
			 * @param  string  $path  Asset path, or an absolute http or https URL.
			 * @param  string|null  $version  Version for this call; null for the asset_version setting.
			 * @return string
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
			 * $hasService: Check whether a service id is registered.
			 *
			 * @param  string  $id  Service id.
			 * @return bool
			 * @see \CitOmni\Kernel\App::hasService()
			 */
			'hasService' => function (string $id): bool {
				return $this->app->hasService($id);
			},


			/**
			 * $hasPackage: Check whether the app uses a package.
			 *
			 * @param  string  $slug  Package slug, such as citomni/authenticate.
			 * @return bool
			 * @see \CitOmni\Kernel\App::hasPackage()  How the slug is matched.
			 */
			'hasPackage' => function (string $slug): bool {
				return $this->app->hasPackage($slug);
			},


			/**
			 * $csrfField: Return the hidden field with the CSRF token.
			 *
			 * Behavior:
			 * - Returns '' when the csrf service is not registered.
			 *
			 * Typical usage:
			 *   {{{ $csrfField() }}}
			 *
			 * @return string  Markup; output it with triple braces.
			 * @see \CitOmni\Http\Service\Csrf::htmlField()
			 */
			'csrfField' => function (): string {
				if ($this->app->hasService('csrf')) {
					return $this->app->csrf->htmlField();
				}
				return '';
			},


			/**
			 * $captchaField: Return the hidden field with this request's captcha challenge id.
			 *
			 * Behavior:
			 * - Returns '' when the captcha service is not registered or Captcha::isEnabled() is false.
			 *
			 * Typical usage:
			 *   {{{ $captchaField() }}}
			 *
			 * @return string  Markup; output it with triple braces.
			 * @see \CitOmni\Http\Service\Captcha::htmlField()  The field, its challenge and its exceptions.
			 */
			'captchaField' => function (): string {
				if ($this->app->hasService('captcha') && $this->app->captcha->isEnabled()) {
					return $this->app->captcha->htmlField();
				}
				return '';
			},


			/**
			 * $captchaUrl: Return the absolute URL of this request's captcha image.
			 *
			 * Behavior:
			 * - Returns '' when the captcha service is not registered or Captcha::isEnabled() is false.
			 *
			 * @return string  base_url followed by the image path.
			 * @see \CitOmni\Http\Service\Captcha::imagePath()  The path, its challenge and its exceptions.
			 */
			'captchaUrl' => function () use ($baseUrl): string {
				if ($this->app->hasService('captcha') && $this->app->captcha->isEnabled()) {
					return \rtrim($baseUrl, '/') . $this->app->captcha->imagePath();
				}
				return '';
			},


			/**
			 * $currentPath: Return the request path from the app root.
			 *
			 * Typical usage:
			 *   {% if $currentPath() === '/member/profile' %}active{% endif %}
			 *
			 * @return string
			 * @see \CitOmni\Http\Service\Request::pathFromAppRoot()
			 */
			'currentPath' => function (): string {
				return $this->app->request->pathFromAppRoot();
			},


			/**
			 * $icon: Return the inline SVG markup of an icon.
			 *
			 * Typical usage:
			 *   {{{ $icon('mfa_totp', 'icons', 'citomni/authenticate') }}}
			 *
			 * @param  string  $id  Icon id.
			 * @param  string  $file  Icon file, without ".php".
			 * @param  string  $layer  Layer of the icon file.
			 * @return string  Trusted SVG markup; output it with triple braces.
			 * @throws \RuntimeException  When the icon service is not registered.
			 * @see \CitOmni\Http\Service\Icon::get()  Lookup, and the exceptions for an invalid or missing icon.
			 */
			'icon' => function (string $id, string $file = 'icons', string $layer = 'citomni/http'): string {
				if (!$this->app->hasService('icon')) {
					throw new \RuntimeException("Icon service not available. Register 'icon' in the HTTP service map.");
				}

				return $this->app->icon->get($id, $file, $layer);
			},


			/**
			 * $hasIcon: Check whether an icon exists.
			 *
			 * Behavior:
			 * - Returns false when the icon service is not registered.
			 *
			 * @param  string  $id  Icon id.
			 * @param  string  $file  Icon file, without ".php".
			 * @param  string  $layer  Layer of the icon file.
			 * @return bool
			 * @see \CitOmni\Http\Service\Icon::has()  Which failures still throw.
			 */
			'hasIcon' => function (string $id, string $file = 'icons', string $layer = 'citomni/http'): bool {
				if (!$this->app->hasService('icon')) {
					return false;
				}

				return $this->app->icon->has($id, $file, $layer);
			},


			/**
			 * $auth: Return one fact about the current identity.
			 *
			 * Behavior:
			 * - check, checkStrict, identity, id and role return Auth::check(), Auth::checkStrict(),
			 *   Auth::getIdentity(), Auth::getIdentityId() and Auth::getRole().
			 *
			 * Typical usage:
			 *   {% if $auth('check') %}{{ $auth('id') }}{% endif %}
			 *
			 * @param  string  $fn  check, checkStrict, identity, id or role.
			 * @return bool|int|array<string,mixed>|null  The result of the Auth method.
			 * @throws \RuntimeException  When the auth service is not registered or App::hasPackage('citomni/authenticate') is false.
			 * @throws \InvalidArgumentException  When $fn is none of these.
			 * @see \CitOmni\Authenticate\Service\Auth  What each result means.
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
			 * $role: Return the result of one Role method.
			 *
			 * Behavior:
			 * - $fn names the method: is, any, min, max, id, name, map, rank, label, labelOf or labels.
			 *   any, min, max, label, labelOf and labels get the other arguments; is and id get the first
			 *   one as a string, name as an int, and map and rank none.
			 *
			 * Typical usage:
			 *   {% if $role('min', 'operator') %}...{% endif %}
			 *
			 * @param  string  $fn  Name of the Role method.
			 * @param  mixed  ...$args  Its arguments.
			 * @return mixed  The result of the Role method.
			 * @throws \RuntimeException  When the role service is not registered or App::hasPackage('citomni/authenticate') is false.
			 * @throws \InvalidArgumentException  When $fn names none of these methods.
			 * @see \CitOmni\Authenticate\Service\Role  The methods and their results.
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
	 * Return the values of the cfg view.vars entries that cover a path.
	 *
	 * Behavior:
	 * - A static entry gives its source unchanged; a dynamic entry calls its source on every call,
	 *   and only when the entry covers the path.
	 *
	 * @param  string  $relPath  Request path from the app root, such as /admin/users.html.
	 * @return array<string,mixed>  Values by variable name.
	 * @throws \RuntimeException  When a dynamic source that covers the path cannot be called.
	 * @see \CitOmni\Http\Service\TemplateEngine::pathMatches()  When an entry covers a path.
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
	 * Call the source of a dynamic cfg view.vars entry.
	 *
	 * Behavior:
	 * - Accepts three forms of source:
	 *     - "FQCN::method": Calls the static method with the App.
	 *     - ['class' => FQCN, 'method' => 'm']: Calls m() on a new FQCN instance, constructed
	 *       with the App on every call.
	 *     - ['service' => 'id', 'method' => 'm']: Calls m() on the registered service.
	 * - Returns the result unchanged; exceptions from the source pass through.
	 *
	 * @param  mixed  $call  Source of the entry.
	 * @param  string  $varName  Variable name, for the exception messages.
	 * @return mixed
	 * @throws \RuntimeException  When the form is none of these, the method does not exist or the service is not registered.
	 */
	private function invokeProvider(mixed $call, string $varName): mixed {
		if (\is_string($call) && \strpos($call, '::') !== false) {
			[$cls, $m] = \explode('::', $call, 2);

			if (!\method_exists($cls, $m)) {
				throw new \RuntimeException("Provider method {$cls}::{$m} for var '{$varName}' not found.");
			}

			return $cls::$m($this->app);
		}

		if (\is_array($call) && isset($call['class'], $call['method'])) {
			$cls	= (string)$call['class'];
			$m		= (string)$call['method'];

			if (!\method_exists($cls, $m)) {
				throw new \RuntimeException("Provider method {$cls}::{$m} for var '{$varName}' not found.");
			}

			$inst = new $cls($this->app);

			return $inst->{$m}();
		}

		if (\is_array($call) && isset($call['service'], $call['method'])) {
			$id	= (string)$call['service'];
			$m	= (string)$call['method'];

			if (!$this->app->hasService($id)) {
				throw new \RuntimeException("Service '{$id}' for var '{$varName}' is not available.");
			}

			$svc = $this->app->{$id};

			if (!\method_exists($svc, $m)) {
				throw new \RuntimeException("Service method {$id}::{$m} for var '{$varName}' not found.");
			}

			return $svc->{$m}();
		}

		throw new \RuntimeException("Unsupported provider call definition for var '{$varName}'.");

	}


	/**
	 * Compile an include or exclude pattern of cfg view.vars to a regular expression.
	 *
	 * Behavior:
	 * - '' matches no path.
	 * - A pattern that starts and ends with "~" is a complete regular expression, used unchanged:
	 *   It matches anywhere in the path unless it has its own anchor.
	 * - "*" matches every path, and "/" only the front page.
	 * - Any other pattern is a prefix of the path, with "*" as a wildcard for any characters, so
	 *   "/news" also matches "/newsletter". A missing leading slash is added: "news" is "/news".
	 *
	 * @param  string  $pat  Pattern from include or exclude.
	 * @return string  Pattern for preg_match().
	 */
	private function compilePathMatcher(string $pat): string {
		$pat = (string)$pat;

		if ($pat === '') {
			return '/^\b\B$/';
		}

		if ($pat[0] === '~' && \str_ends_with($pat, '~') && \strlen($pat) >= 2) {
			return $pat;
		}

		if ($pat !== '*' && $pat[0] !== '/') {
			$pat = '/' . $pat;
		}

		if ($pat === '/') {
			return '/^\/$/';
		}

		// Escape everything, then turn only the escaped "*" back into a wildcard.
		$quoted = \preg_quote($pat, '/');
		$quoted = \str_replace('\*', '.*', $quoted);

		// Anchored at the start only: The pattern is a prefix of the path.
		return '/^' . $quoted . '/';
	}


	/**
	 * Check whether an entry with these compiled patterns covers a path.
	 *
	 * Behavior:
	 * - True when the include list is empty or one include matches, and no exclude matches.
	 *
	 * @param  string  $path  Request path from the app root.
	 * @param  list<string>  $incRes  Compiled include patterns.
	 * @param  list<string>  $excRes  Compiled exclude patterns.
	 * @return bool
	 */
	private function pathMatches(string $path, array $incRes, array $excRes): bool {
		// An empty include list includes every path.
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

		// An exclude wins over every include.
		foreach ($excRes as $re) {
			if (\preg_match($re, $path) === 1) {
				return false;
			}
		}

		return true;
	}







	// ----------------------------------------------------------------
	// Compilation and persistent cache
	// ----------------------------------------------------------------

	/**
	 * Return the compiled generation of a template, compiling it when needed.
	 *
	 * A generation is the PHP compiled from one template and every template it extends or
	 * includes, stored under the hash of its content. A JSON manifest per reference names the
	 * current generation and the sources it was compiled from. All files of a reference start
	 * with its cacheFileName() prefix: The generation adds "_", its hash and ".php", the manifest
	 * ".meta.json" and the lock ".lock".
	 *
	 * Behavior:
	 * - With cache_enabled, reuses the generation of the manifest without reading any source when
	 *   the generation file exists and every recorded source still has its canonical path and its
	 *   stat signature. Otherwise compiles.
	 * - Compiles under an exclusive lock per reference; with cache_enabled, checks the manifest
	 *   again once the lock is held.
	 * - Compiles in this order, so a later step sees the result of the earlier ones:
	 *     1) Remove "{# ... #}" comments from each source as it is read.
	 *     2) Resolve "{% extends %}", "{% block %}" and "{% yield %}".
	 *     3) Expand "{% include %}".
	 *     4) Compile output, assignment, control flow and inline PHP.
	 *     5) Remove HTML comments and collapse whitespace, when remove_html_comments or
	 *        trim_whitespace is on.
	 * - Compiles again when a source changed after it was read, and throws after three attempts.
	 * - Writes the generation, unless a file of that name exists, then the manifest, each with an
	 *   atomic rename; also when cache_enabled is off.
	 *
	 * Notes:
	 * - With cache_enabled, a source edit that keeps mtime, ctime, size, inode and device goes
	 *   unseen until the template cache is cleared.
	 * - The content of a generation file never changes, so OPcache may serve it without timestamp
	 *   checks.
	 * - Replaced generations stay on disk.
	 *
	 * @param  string  $ref  Template reference, "relative/path.html@layer".
	 * @return string  Absolute path of the generation.
	 * @throws \InvalidArgumentException  When $ref, or a reference in an extends or include directive, has no "@", an empty path or layer, or a NUL byte in the path.
	 * @throws \RuntimeException  When a layer is unknown, a source is missing, outside its layer root, unreadable or changes while it is read, the template structure is invalid, the sources keep changing, or the cache cannot be locked or written.
	 * @see \CitOmni\Http\Service\TemplateEngine::processExtendsAndBlocks()  Layouts and blocks.
	 * @see \CitOmni\Http\Service\TemplateEngine::processIncludes()  Includes.
	 * @see \CitOmni\Http\Service\TemplateEngine::compileSyntax()  Output, assignment, control flow and inline PHP.
	 * @see \CitOmni\Http\Service\TemplateEngine::optimizeMarkup()  What the markup optimization keeps.
	 */
	private function compile(string $ref): string {

		// -- 1. Resolve logical reference and cache identity -------------
		// The prefix is memoized because one request may render the same template more
		// than once.

		[$rel, $layer] = $this->splitRef($ref);
		$base = $this->cachePrefixes[$rel . '@' . $layer] ??= $this->cacheFileName($rel, $layer);


		// -- 2. Fast-path a valid warm cache generation ------------------
		// A warm hit stays cheap: It stats the recorded sources but never reads them. A
		// memoized manifest is still checked against the sources on every render.

		if ($this->cacheEnabled) {
			$manifest = $this->manifests[$base] ?? $this->readManifest($base);
			if ($manifest !== null && $this->manifestIsFresh($base, $manifest)) {
				$this->manifests[$base] = $manifest;
				return $base . '_' . $manifest['generation'] . '.php';
			}
			unset($this->manifests[$base]);
		}


		// -- 3. Serialize cache misses for this template identity --------
		// Readers never lock. Only a miss takes the writer lock of this reference, so
		// concurrent requests never compile and publish the same reference at once.

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
			// Another request may have published a generation while this one waited for the
			// lock.

			if ($this->cacheEnabled) {
				$manifest = $this->readManifest($base);
				if ($manifest !== null && $this->manifestIsFresh($base, $manifest)) {
					$this->manifests[$base] = $manifest;
					return $base . '_' . $manifest['generation'] . '.php';
				}
			}


			// -- 5. Compile and verify one stable dependency snapshot --------
			// The context of one attempt: sources holds each source read, dependencies the
			// stat snapshot for the manifest, includeStack the active include chain and roots
			// the canonical layer roots. A source that changed during the attempt discards it,
			// so no generation mixes two states of a deployment.
			for ($attempt = 0; $attempt < 3; $attempt++) {
				$context = ['sources' => [], 'dependencies' => [], 'includeStack' => [], 'roots' => []];
				[$code, $sourcePath] = $this->loadSource($rel, $layer, $context);
				$code = $this->processExtendsAndBlocks($code, $layer, $context, [$sourcePath => true]);
				$code = $this->processIncludes($code, 0, $context);
				$code = $this->compileSyntax($code);
				if ($this->removeHtmlComments || $this->trimWhitespace) {
					$code = $this->optimizeMarkup($code);
				}
				// The guard exits when a generation runs without this class loaded, such as on a direct web request.
				$compiled = "<?php class_exists('" . __CLASS__ . "', false) or exit; ?>\n" . \rtrim($code);
				$dependencies = \array_values($context['dependencies']);
				if (!$this->dependenciesAreFresh($dependencies)) {
					continue;
				}


				// -- 6. Publish immutable generation, then mutable manifest ------
				// The generation is named by the hash of its PHP, so identical output reuses the
				// same file. The manifest is written last, so no reader sees a manifest that
				// points at a generation that does not exist yet.

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
			// Closing releases the lock, also when compilation throws.
			\fclose($lock);
		}
	}


	/**
	 * Read and check the manifest of a cache prefix.
	 *
	 * Behavior:
	 * - Returns null when the manifest is missing, unreadable or invalid: Its format must be
	 *   COMPILER_VERSION, its generation 64 lowercase hex characters, and its dependencies a
	 *   non-empty array of [path, layer, canonical path, five integers].
	 * - Decodes the manifest as JSON and never runs it.
	 *
	 * @param  string  $base  Cache prefix.
	 * @return array{format:string,generation:string,dependencies:array<array{string,string,string,list<int>}>}|null  The manifest, or null when it must be rebuilt.
	 */
	private function readManifest(string $base): ?array {

		// -- 1. Load manifest bytes --------------------------------------

		$path = $base . '.meta.json';
		\clearstatcache(false, $path);
		if (!\is_file($path)) {
			return null;
		}
		// Cache maintenance may remove the file after is_file(); a rebuild recovers from that.
		$json = @\file_get_contents($path);
		if ($json === false) {
			return null;
		}


		// -- 2. Validate the complete manifest shape ---------------------

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
	 * Check whether the generation of a manifest exists and its sources are unchanged.
	 *
	 * @param  string  $base  Cache prefix.
	 * @param  array{format:string,generation:string,dependencies:array<array{string,string,string,list<int>}>}  $manifest  Manifest from readManifest().
	 * @return bool
	 * @throws \RuntimeException  When a recorded source now resolves outside its layer root.
	 * @see \CitOmni\Http\Service\TemplateEngine::dependenciesAreFresh()  When a source counts as unchanged.
	 */
	private function manifestIsFresh(string $base, array $manifest): bool {
		$file = $base . '_' . $manifest['generation'] . '.php';
		\clearstatcache(false, $file);
		return \is_file($file) && $this->dependenciesAreFresh($manifest['dependencies']);
	}


	/**
	 * Check whether every recorded source still has its canonical path and stat signature.
	 *
	 * Behavior:
	 * - False when a source is missing, is no regular file, resolves to another canonical path,
	 *   or has another stat signature. Reads no source.
	 * - Compares the signature for equality, so a source whose mtime moves backwards also counts
	 *   as changed.
	 *
	 * @param  array<array{string,string,string,list<int>}>  $dependencies  Path, layer, canonical path and stat signature of each source.
	 * @return bool
	 * @throws \RuntimeException  When a source now resolves outside its layer root.
	 * @see \CitOmni\Http\Service\TemplateEngine::statSignature()  The compared values.
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
	 * Reduce a stat result to the values that freshness checks compare.
	 *
	 * @param  array<int|string,int>  $stat  Result of stat() or fstat().
	 * @return array{int,int,int,int,int}  mtime, ctime, size, inode and device.
	 */
	private function statSignature(array $stat): array {
		return [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino'], $stat['dev']];
	}


	/**
	 * Create the cache directory when it does not exist.
	 *
	 * Behavior:
	 * - A directory that another process creates at the same time counts as success.
	 *
	 * @return void
	 * @throws \RuntimeException  When the directory cannot be created.
	 */
	private function ensureCacheDirectory(): void {
		if (!\is_dir($this->cacheDir) && !@\mkdir($this->cacheDir, 0775, true) && !\is_dir($this->cacheDir)) {
			throw new \RuntimeException('TemplateEngine: Cannot create cache directory: ' . $this->cacheDir);
		}
	}


	/**
	 * Write a cache file atomically through a temporary file in the same directory.
	 *
	 * Behavior:
	 * - A reader sees the previous file or the complete new one, never a partial one.
	 * - Never deletes the existing file, also when the rename fails, and removes its temporary file.
	 * - Sets mode 0644 when it can.
	 *
	 * Notes:
	 * - Assumes that the caller serializes writers of one path.
	 * - Assumes a filesystem on which rename() within one directory replaces atomically.
	 *
	 * @param  string  $path  Absolute path of the file.
	 * @param  string  $contents  Complete contents.
	 * @return void
	 * @throws \RuntimeException  When the temporary file cannot be created, written completely or flushed, or the rename fails.
	 */
	private function writeCacheFile(string $path, string $contents): void {

		// -- 1. Create an exclusive same-directory temporary file --------

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


			// -- 3. Publish without deleting an existing destination ---------

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
	 * Return the cache prefix of a template reference.
	 *
	 * Behavior:
	 * - The prefix is "tpl_v" with COMPILER_VERSION, up to 32 characters of the reference made
	 *   filesystem-safe, and 32 hex characters of a hash of the cache signature and the reference.
	 * - Touches no file.
	 *
	 * @param  string  $rel  Template path below the layer root.
	 * @param  string  $layer  Layer slug.
	 * @return string  Absolute path in the cache directory, without suffix or extension.
	 */
	private function cacheFileName(string $rel, string $layer): string {
		// The slug only makes the name readable; uniqueness comes from the hash.
		$slug = \preg_replace('/[^A-Za-z0-9_]+/', '_', $layer . '__' . $rel);

		// The hash covers the cache signature, so a changed setting never reuses a generation.
		// It is not the hash of the compiled content.
		$hash = \substr(\hash('sha256', $this->cacheSignature . "\0" . $rel . '@' . $layer), 0, 32);

		return $this->cacheDir . '/tpl_v' . self::COMPILER_VERSION . '_' . \substr($slug, 0, 32) . '_' . $hash;
	}







	// ----------------------------------------------------------------
	// Template references and source snapshots
	// ----------------------------------------------------------------

	/**
	 * Split a template reference into its path and layer.
	 *
	 * Behavior:
	 * - Splits at the last "@", so a path may contain "@".
	 * - Removes leading slashes and backslashes from the path.
	 * - Checks the form and the layer only; whether the file exists is checked when it is read.
	 *
	 * @param  string  $ref  Template reference, "relative/path.html@layer".
	 * @return array{string,string}  Path below the layer root, and layer slug.
	 * @throws \InvalidArgumentException  When $ref has no "@", the path or layer is empty, or the path has a NUL byte.
	 * @throws \RuntimeException  When the layer is not a key of cfg view.template_layers.
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
	 * Resolve a template path to its canonical path below the canonical root of its layer.
	 *
	 * Behavior:
	 * - Resolves symlinks in the root and the path, and rejects a path that then lies outside the
	 *   root, such as through ".." or a symlink.
	 * - Reads no source.
	 *
	 * @param  string  $rel  Template path below the layer root.
	 * @param  string  $layer  Layer slug.
	 * @param  bool  $required  Whether a missing root or path throws instead of returning null.
	 * @param  array<string,string>  $roots  Canonical roots by layer; this method adds each root it resolves.
	 * @return string|null  Canonical path, or null when it is missing and not required.
	 * @throws \RuntimeException  When the layer is unknown, the path lies outside the root, or a required path is missing.
	 */
	private function resolveSourcePath(string $rel, string $layer, bool $required, array &$roots): ?string {

		// -- 1. Resolve the configured layer root ------------------------
		// splitRef() rejects an unknown layer too; this guard stays because this method is the
		// filesystem boundary.

		$root = $this->layersMap[$layer] ?? null;
		if ($root === null) {
			throw new \RuntimeException("TemplateEngine: Layer '{$layer}' not registered.");
		}


		// -- 2. Canonicalize root and requested source -------------------
		// The root is memoized in $roots for one pass only; the path is always resolved
		// again, so a deployment that moves a file is seen.

		$candidate = $root . $rel;
		// Clear only these paths, not the whole realpath cache of the process.
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


		// -- 3. Enforce canonical containment ----------------------------
		// The trailing separator keeps a root such as /templates from accepting a sibling
		// such as /templates_backup.

		$prefix = \rtrim($base, '/\\') . \DIRECTORY_SEPARATOR;
		if (!\str_starts_with($target, $prefix)) {
			throw new \RuntimeException("TemplateEngine: Illegal path escape '{$rel}@{$layer}'.");
		}
		return $target;
	}


	/**
	 * Read a template source once per compilation attempt and record it as a dependency.
	 *
	 * Behavior:
	 * - Returns the source read earlier in the same attempt for the same reference.
	 * - Records the reference, the canonical path and the stat signature in the dependencies of
	 *   $context.
	 * - Returns the source with its "{# ... #}" comments removed, so a directive inside a comment
	 *   is never resolved and never becomes a dependency.
	 *
	 * @param  string  $rel  Template path below the layer root.
	 * @param  string  $layer  Layer slug.
	 * @param  array{sources:array<string,array{string,string}>,dependencies:array<string,array{string,string,string,list<int>}>,includeStack:array<string,bool>,roots:array<string,string>}  $context  State of the compilation attempt.
	 * @return array{string,string}  Source without comments, and its canonical path.
	 * @throws \RuntimeException  When the layer is unknown, or the source is missing, outside its layer root, no regular file, unreadable, or changes while it is read.
	 * @see \CitOmni\Http\Service\TemplateEngine::removeTemplateComments()  How comments are removed.
	 */
	private function loadSource(string $rel, string $layer, array &$context): array {

		// -- 1. Reuse an already loaded logical dependency ---------------
		// A template reached twice through layouts or includes uses the same bytes in both
		// places within one attempt.

		$key = $rel . '@' . $layer;
		if (isset($context['sources'][$key])) {
			return $context['sources'][$key];
		}


		// -- 2. Resolve and open one canonical regular file --------------
		// The layer-root check runs before anything is opened, so no reference reads a file
		// outside its layer.

		$path = $this->resolveSourcePath($rel, $layer, true, $context['roots']);
		if (!\is_file($path)) {
			throw new \RuntimeException("TemplateEngine: Source '{$rel}@{$layer}' is not a regular file.");
		}
		$stream = \fopen($path, 'rb');
		if ($stream === false) {
			throw new \RuntimeException("TemplateEngine: Cannot read '{$rel}@{$layer}'.");
		}


		// -- 3. Read one stable byte snapshot ----------------------------
		// fstat() before and after reading the same handle catches a file that is replaced or
		// truncated during the read; the length check catches a short read.

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


		// -- 4. Record dependency metadata and comment-stripped source ---
		// Comments go before layouts and includes are scanned, so a directive inside a
		// comment neither runs nor becomes a dependency.

		$context['dependencies'][$key] = [$rel, $layer, $path, $this->statSignature($after)];
		return $context['sources'][$key] = [$this->removeTemplateComments($code), $path];
	}








	// ----------------------------------------------------------------
	// Inheritance and includes
	// ----------------------------------------------------------------

	/**
	 * Resolve layout inheritance by putting the blocks of each child into the yields of its layout.
	 *
	 * A child names its layout with "{% extends "path@layer" %}" and fills it with
	 * "{% block name %}...{% endblock %}". The layout marks each place with "{% yield name %}" and
	 * may extend a layout of its own, in any layer.
	 *
	 * Behavior:
	 * - Returns the source unchanged when it has no extends directive.
	 * - Follows the first extends directive of each level, up to MAX_INHERITANCE_DEPTH layouts.
	 * - Keeps only the blocks of a child that extends; its other source is dropped.
	 * - A block ends at its first endblock, so blocks do not nest.
	 * - Inserts each block literally into every yield of its name. A block may yield a block that
	 *   comes later in the same child.
	 * - Every block needs a yield in the layout, and every yield of the layout a block in the child.
	 *
	 * @param  string  $code  Source without comments, before includes.
	 * @param  string  $currentLayer  Layer of $code, for the exception messages.
	 * @param  array{sources:array<string,array{string,string}>,dependencies:array<string,array{string,string,string,list<int>}>,includeStack:array<string,bool>,roots:array<string,string>}  $context  State of the compilation attempt.
	 * @param  array<string,bool>  $seen  Canonical paths already in the chain, the root template included.
	 * @return string  Source of the outermost layout with every block inserted.
	 * @throws \InvalidArgumentException  When an extends reference has no "@", an empty path or layer, or a NUL byte in the path.
	 * @throws \RuntimeException  When a layout cannot be loaded, the chain is circular or too deep, a child has two blocks of one name, a block has no yield or a yield no block, or a regular expression fails.
	 */
	private function processExtendsAndBlocks(string $code, string $currentLayer, array &$context, array $seen): string {

		// -- 1. Walk the inheritance chain iteratively -------------------

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
			// $seen holds canonical paths, so A -> B -> A fails at once.

			[$parentRel, $parentLayer] = $this->splitRef($parent[1]);
			[$layout, $layoutPath] = $this->loadSource($parentRel, $parentLayer, $context);
			if (isset($seen[$layoutPath])) {
				throw new \RuntimeException("TemplateEngine: Circular inheritance at '{$parentRel}@{$parentLayer}'.");
			}
			$seen[$layoutPath] = true;


			// -- 3. Extract and validate child blocks ------------------------
			// Duplicate block names fail on purpose; there is no "last wins".
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
				// A block that contains a yield needs the ordered merge, one block at a time, so a
				// yield that an earlier block inserts is filled by a later block. Other layouts take
				// the faster single pass.
				if (!$orderedMerge && \str_contains($block[2], '{%')) {
					$result = \preg_match(self::YIELD_PATTERN, $block[2]);
					if ($result === false) {
						throw new \RuntimeException('TemplateEngine: PCRE error while inspecting block yields.');
					}
					$orderedMerge = $result === 1;
				}
			}


			// -- 4. Merge block source into matching yields literally --------
			// Block bodies go through callbacks, never as preg_replace() replacement text, where
			// backslashes and $1 would be interpreted.

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
				// $used records the inserted blocks, so a block without a yield is reported below.
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
			// A yield left over means the child supplies no block for it; an incomplete layout
			// never renders.

			$leftoverCount = \preg_match_all(self::YIELD_PATTERN, $layout, $leftover);
			if ($leftoverCount === false) {
				throw new \RuntimeException('TemplateEngine: PCRE error while validating remaining yields.');
			}
			if ($leftoverCount > 0) {
				$list = "'" . \implode("', '", \array_unique($leftover[1])) . "'";
				throw new \RuntimeException("TemplateEngine: Missing child blocks {$list} from layer '{$currentLayer}' in parent template: " . $layoutPath);
			}


			// -- 6. Continue with the merged parent as the next child --------

			$code = $layout;
			$currentLayer = $parentLayer;
		}
	}


	/**
	 * Replace each include directive with the source it names, recursively.
	 *
	 * Behavior:
	 * - Replaces "{% include "path@layer" %}" with that template's source, its own includes
	 *   expanded, up to MAX_INCLUDE_DEPTH levels. The keyword is case-insensitive.
	 * - Inserts the source literally, so it becomes part of the including template and shares
	 *   its variables.
	 * - A template may be included several times, but never inside itself.
	 *
	 * Notes:
	 * - Includes are expanded after inheritance: An included template cannot extend a layout.
	 *   Its extends, block, endblock and yield directives are removed, and the content of its
	 *   blocks stays in place.
	 *
	 * @param  string  $code  Source without comments, after inheritance.
	 * @param  int  $depth  Include depth of $code; 0 for the root.
	 * @param  array{sources:array<string,array{string,string}>,dependencies:array<string,array{string,string,string,list<int>}>,includeStack:array<string,bool>,roots:array<string,string>}  $context  State of the compilation attempt.
	 * @return string
	 * @throws \InvalidArgumentException  When an include reference has no "@", an empty path or layer, or a NUL byte in the path.
	 * @throws \RuntimeException  When an included source cannot be loaded, includes are circular or too deep, or a regular expression fails.
	 */
	private function processIncludes(string $code, int $depth, array &$context): string {

		// -- 1. Detect include work and enforce depth --------------------

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


		// -- 2. Expand includes recursively with an active-path stack ----
		// A callback keeps the included source literal; as replacement text, backslashes and
		// $1 would be interpreted.

		$expanded = \preg_replace_callback(self::INCLUDE_PATTERN, function (array $match) use ($depth, &$context): string {
			[$rel, $layer] = $this->splitRef($match[1]);
			[$included, $path] = $this->loadSource($rel, $layer, $context);
			// includeStack holds only the active chain: The same partial in another branch is
			// valid, A -> B -> A is not.
			if (isset($context['includeStack'][$path])) {
				throw new \RuntimeException("TemplateEngine: Circular include at '{$rel}@{$layer}'.");
			}
			$context['includeStack'][$path] = true;
			try {
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
	 * Compile output, assignment, control flow and inline PHP to PHP.
	 *
	 * Expressions are native PHP expressions; the template language has no expression syntax of
	 * its own.
	 *
	 * Behavior:
	 * - "{{ expr }}" echoes expr through htmlspecialchars() with ENT_QUOTES | ENT_SUBSTITUTE in
	 *   the charset of escaped output, which no template variable can change. Null, or an
	 *   undefined variable, key or property as the whole expr, echoes nothing.
	 * - "{{{ expr }}}" echoes expr unescaped; use it only for trusted markup.
	 * - "{% set $name = expr %}" assigns a local variable; the "$" is required.
	 * - "{% if expr %}", "{% elseif expr %}", "{% else %}", "{% endif %}", "{% foreach (expr) %}"
	 *   and "{% endforeach %}" compile to the alternative PHP syntax; foreach needs the
	 *   parentheses.
	 * - "{% break %}" and "{% continue %}" take an optional level, such as "{% break 2 %}", and an
	 *   optional semicolon.
	 * - With allow_php_tags, "{?= expr ?}" echoes expr unescaped and "{? code ?}" runs code;
	 *   without it, both compile to nothing.
	 * - Removes block, endblock, yield and extends directives that are left after inheritance.
	 * - Leaves native PHP tags in the source as they are, whatever allow_php_tags says.
	 *
	 * Notes:
	 * - An if, elseif or foreach expression must fit on one line; output, set and inline PHP
	 *   expressions may span lines.
	 *
	 * @param  string  $code  Source after inheritance and includes.
	 * @return string  PHP mixed with literal markup.
	 * @throws \RuntimeException  When a regular expression fails.
	 */
	private function compileSyntax(string $code): string {

		// -- 1. Skip source with no template marker ----------------------

		if (!\str_contains($code, '{')) {
			return $code;
		}


		// -- 2. Define the grammar in replacement order ------------------
		// The order matters: "{?=" before "{?", and "{{{" before "{{".

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

			// Structural markers are resolved before this point. Leftovers are stripped so no
			// directive reaches the output.
			'/{%\s*block\s+([\w-]+)\s*%}/',
			'/{%\s*endblock\s*%}/',
			'/{%\s*yield\s*([\w-]+)\s*%}/',
			'/{%\s*extends\s+["\'](.+?)["\']\s*%}/',
		];

		// Keep this array in exactly the same order as $patterns above. The $1 and $2 here are
		// compiler-owned; template source never becomes replacement text.
		// The escape charset is a PHP literal; addcslashes() keeps its backslashes and dollar
		// signs literal in the replacement.
		$charset = \addcslashes(\var_export($this->charset, true), '\\$');
		$replacements = [
			// Custom inline PHP. Disabled forms compile to an empty string.
			$this->allowPhpTags ? '<?php echo $1; ?>' : '',
			$this->allowPhpTags ? '<?php $1 ?>' : '',

			// Raw and escaped output.
			'<?php echo $1; ?>',
			'<?php echo htmlspecialchars($1 ?? "", \ENT_QUOTES | \ENT_SUBSTITUTE, ' . $charset . '); ?>',

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


		// -- 3. Compile template directives to PHP -----------------------

		$result = \preg_replace($patterns, $replacements, $code);
		if ($result === null) {
			throw new \RuntimeException('TemplateEngine: PCRE error during compileSyntax().');
		}
		return $result;
	}


	/**
	 * Remove "{# ... #}" comments, which may nest.
	 *
	 * Behavior:
	 * - Removes each outermost comment with everything in it, directives included, so
	 *   "{# a {# b #} c #}" leaves nothing.
	 * - Leaves a "#}" outside a comment as it is.
	 * - An unclosed comment removes the rest of the source.
	 *
	 * @param  string  $code  Template source.
	 * @return string
	 * @throws \RuntimeException  When a regular expression fails.
	 */
	private function removeTemplateComments(string $code): string {

		// -- 1. Locate nested comment markers ----------------------------

		if (!\str_contains($code, '{#')) {
			return $code;
		}
		if (\preg_match_all('/\{#|#\}/', $code, $tokens, \PREG_OFFSET_CAPTURE) === false) {
			throw new \RuntimeException('TemplateEngine: PCRE error while stripping template comments.');
		}


		// -- 2. Copy only source observed outside comment depth ----------

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
	 * Remove HTML comments and collapse whitespace in the literal markup of compiled PHP.
	 *
	 * Behavior:
	 * - Tags with their attributes, kept HTML comments, PHP code, and pre, code, textarea, script
	 *   and style elements stay byte for byte.
	 * - With remove_html_comments, removes HTML comments with everything inside them, template
	 *   directives and PHP included. It keeps unclosed and empty comments, and those whose text
	 *   starts with "[", ">" or "<!", such as conditional comments.
	 * - With trim_whitespace, replaces each run of two or more whitespace characters between tags
	 *   with one space.
	 *
	 * Notes:
	 * - Recognizes elements and comments by pattern; it is no HTML parser and no CSS or JS
	 *   minifier.
	 *
	 * @param  string  $code  Compiled PHP mixed with literal markup.
	 * @return string
	 * @throws \RuntimeException  When a regular expression fails.
	 */
	private function optimizeMarkup(string $code): string {

		// -- 1. Protect compiled PHP fragments ---------------------------
		// PHP is replaced by markers first, so no markup pattern sees a ">" or a quote inside
		// PHP code. The marker is extended until the source does not contain it.

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


		// -- 2. Locate protected markup regions --------------------------
		// Match whole quoted tags and raw-text regions before optimizing the text between them.
		$pattern = '~<(pre|code|textarea|script|style)\b(?:[^\'">]|"[^"]*"|\'[^\']*\')*>.*?(?:</\1\s*>|\z)|<!--.*?(?:-->|\z)|<(?:[^\'">]|"[^"]*"|\'[^\']*\')*>~is';
		if (\preg_match_all($pattern, $markup, $matches, \PREG_OFFSET_CAPTURE) === false) {
			throw new \RuntimeException('TemplateEngine: PCRE error while protecting markup.');
		}


		// -- 3. Optimize unprotected text and restore PHP ----------------

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
	 * Collapse each run of two or more whitespace characters to one space, with trim_whitespace.
	 *
	 * @param  string  $text  Text outside every protected region.
	 * @return string  The text, unchanged without trim_whitespace.
	 * @throws \RuntimeException  When the regular expression fails.
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
	 * Print the template variables as an HTML comment, in the dev and stage environments only.
	 *
	 * Behavior:
	 * - Prints nothing unless CITOMNI_ENVIRONMENT is dev or stage.
	 * - Prints with print_r(), escaped with htmlspecialchars() in UTF-8, so no value can end the
	 *   comment.
	 * - Shows a closure as "[closure]" and a resource by its type. Shows an object by class with
	 *   the properties visible from this class, and an object met again by class with "(seen)".
	 *   Cuts nesting at 10 levels.
	 *
	 * Notes:
	 * - The output can show internal state of the request and the app.
	 *
	 * @param  array<string,mixed>  $vars  Variables of the render.
	 * @return void
	 */
	private function printViewVars(array $vars): void {

		// -- 1. Restrict diagnostics to non-production environments ------
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';

		if ($env !== 'dev' && $env !== 'stage') {
			return;
		}


		// -- 2. Normalize the payload defensively ------------------------
		// Objects already shown; a cycle ends at the second visit.
		$seen = new \SplObjectStorage();

		// Caps the output for deep structures.
		$maxDepth = 10;

		/**
		 * Return a copy of a value that print_r() can show.
		 *
		 * @param  mixed  $v  Value from $vars.
		 * @param  int  $depth  Nesting depth of $v.
		 * @return mixed  The value with closures, objects and resources replaced.
		 */
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
				if ($seen->offsetExists($v)) {
					return '[' . \get_debug_type($v) . ' (seen)]';
				}
				$seen->offsetSet($v);

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


		// -- 3. Emit one escaped diagnostic HTML comment -----------------
		$normalized = $normalize($vars);

		// An HTML comment leaves the rendered page unchanged.
		echo "<!--\n=== CitOmni TemplateEngine Vars (environment: {$env}) ===\n";
		echo \htmlspecialchars(\print_r($normalized, true), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
		echo "\n=== End Vars ===\n-->\n";
	}


	/**
	 * Return a cfg node as a plain array.
	 *
	 * Behavior:
	 * - Returns an array unchanged and the array from toArray() of an object that has the method;
	 *   anything else gives [].
	 * - Checks no entries.
	 *
	 * @param  mixed  $node  Array, Cfg node or any other value.
	 * @return array<int|string,mixed>
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
