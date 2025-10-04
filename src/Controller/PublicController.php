<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * Public-facing pages (home and generic error pages).
 *
 * Lean controller intended for minimal startup logic and simple view rendering.
 * Templates + layer are provided via route config. No "magic", no global state.
 */
class PublicController extends BaseController {
	
	

/*
 *------------------------------------------------------------------
 * BASIC START-UP
 *------------------------------------------------------------------
 * The common fundamentals that are required for all public pages. 
 * 
 */

	/**
	 * Lightweight initialization for public routes.
	 *
	 * Keep this fast and side-effect free; heavy lifting belongs in services.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Do start-up stuff
	}





/*
 *------------------------------------------------------------------
 * PUBLIC PAGES
 *------------------------------------------------------------------
 * 
 */


	/**
	 * Displays the public home page (root url).
	 *
	 * Renders the main public landing page using the configured template and template layer.
	 * Passes login status and other view data to the template.
	 *
	 * @return void
	 */
	public function index(): void {

		$details = \json_encode([
				'citomni' => [
					'mode' => 'http',
					'environment' => CITOMNI_ENVIRONMENT,
				],
				'metrics' => [
					'time_s' => ((float) \sprintf('%.3f', ((($nowNs=\hrtime(true))) - (\defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs)) / 1_000_000_000)),
					// 'mem_peak_kb' => (int)\round(\memory_get_peak_usage(true) / 1024),
					'memory_usage_current_kb:' => (int)\round(\memory_get_usage() / 1024),
					'memory_usage_peak_kb' => (int)\round(\memory_get_peak_usage() / 1024),
				],
				'opcache' => [
					'enabled' => (bool)\filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL),
					'validate_timestamps' => \ini_get('opcache.validate_timestamps') !== '0',
				],
			], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);


		// Render the home page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' 				=> 0,
			
			// Canonical URL
			'canonical' 			=> \CITOMNI_PUBLIC_ROOT_URL,
			
			'meta_title'       		=> 'Installed successfully',
			'meta_description' 		=> 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'       		=> 'READY',
			'badge_variant'    		=> 'badge--success', // green
			'title'            		=> 'Installation complete',
			'subtitle'         		=> 'CitOmni HTTP is up and running.',
			// 'lead_text'        		=> 'You are all set. CitOmni is ready for your development. Update your routes to get started.',
			'lead_text'        		=> 'Green lights across the board. Wire up your routes and let’s make something fast!',
			'status_code'      		=> '200',
			'status_text'      		=> 'OK',
		    'http_method'           => $_SERVER['REQUEST_METHOD'] ?? 'GET',
		    'request_path'          => $_SERVER['REQUEST_URI'] ?? '/',
		    'details_preformatted'  => $details ?? 'Hello, CitOmni. Let’s build something fast.',
		    'primary_href'          => 'https://github.com/citomni/http#readme',
		    'primary_target'		=> '_blank',
		    'primary_label'         => 'Open README',
		    'secondary_href'        => 'https://github.com/citomni/http/releases',
		    'secondary_target'		=> '_blank',
		    'secondary_label'       => 'Changelog',
		    'tertiary_href'			=> 'https://github.com/citomni/http/issues/new/choose',
		    'tertiary_target'		=> '_blank', // _self
		    'tertiary_label'		=> 'Report issue',
		    'year'                  => date('Y'),
		    'owner'                 => 'CitOmni.com',
			
			
			// User login status (left commented to avoid hard deps)
			// 'is_loggedin' => is_object($this->user_account) && $this->user_account->isLoggedin(),
		]);
	}












	/**
	 * Render /legal/website-license (content terms; NOT code license).
	 *
	 * Behavior:
	 * - Pulls owner/app metadata from $this->app->cfg->identity
	 * - Sends "noindex" and no-cache headers (via Response::noIndex())
	 * - Optionally sets a canonical Link header if CITOMNI_PUBLIC_ROOT_URL is defined
	 * - Emits deterministic HTML via Response::html() (which exits)
	 *
	 * @return void
	 * @throws \RuntimeException If neither identity.owner_name nor identity.app_name is set.
	 */
	public function websiteLicense(): void {
		$cfg = $this->app->cfg;
		$identity = $cfg->identity ?? (object)[];

		$ownerName  = (string)($identity->owner_name ?? ($identity->app_name ?? ''));
		$ownerEmail = (string)($identity->owner_email ?? '');
		$ownerUrl   = (string)($identity->owner_url ?? '');
		$year       = (int)\date('Y');

		if ($ownerName === '') {
			// Fail fast; config should provide either owner_name or app_name.
			throw new \RuntimeException('Missing identity.owner_name (or app_name) in configuration.');
		}

		// Robots + no-cache; also safe for member-only pages.
		$this->app->response->noIndex();

		// Optionally advertise canonical URL if root URL is known.
		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$this->app->response->setHeader(
				'Link',
				'<' . \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/>; rel="canonical"',
			);
		}

		$e = static fn(?string $s): string => \htmlspecialchars((string)$s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		$html  = '<!doctype html><html lang="en"><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<meta name="robots" content="noindex,follow">';
		$html .= '<title>Website Content License</title>';
		$html .= '<body style="margin:0;padding:24px;font:16px/1.6 ui-serif,Georgia,Cambria,Times,serif">';
		$html .= '<h1>Website Content License</h1>';
		$html .= '<p>All textual and visual content on this website is &copy; '
			. $e((string)$year) . ' ' . $e($ownerName) . ', unless stated otherwise.</p>';
		$html .= '<p>Content may not be copied, redistributed, or modified without prior written consent.</p>';
		$html .= '<p>This website is built on the <a href="https://www.citomni.com/" target="_blank" rel="noopener">CitOmni framework</A> <a href="https://raw.githubusercontent.com/citomni/kernel/refs/heads/main/LICENSE" target="_blank" rel="noopener noreferrer">(GPL-3.0-or-later)</a>. '
			. 'The framework’s license applies to the framework only, not to the website content.</p>';
		if ($ownerEmail !== '') {
			$html .= '<p>Permissions: ' . $e($ownerEmail) . '</p>';
		}
		if ($ownerUrl !== '') {
			$html .= '<p>Owner: <a href="' . $e($ownerUrl) . '">' . $e($ownerUrl) . '</a></p>';
		}
		$html .= '</body></html>';

		// Emits Content-Type and exits.
		$this->app->response->html($html, 200);
	}


	/**
	 * Redirect any alternate URLs to the canonical URL.
	 *
	 * @return never
	 */
	public function redirectWebsiteLicense(): never {
		$target = \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/';
		$this->app->response->redirect($target, 301);
	}










	/**
	 * Renders the application information/status page (dev-only).
	 *
	 * Shows a production-safe snapshot (when enabled) of runtime metrics,
	 * installed packages, and the active configuration in both JSON and
	 * copy-pasteable PHP array forms. Secrets are deterministically masked.
	 *
	 * Behavior:
	 * - Hard gate: only available when CITOMNI_ENVIRONMENT === "dev|stage".
	 * - On non-dev environments the request is handed to the ErrorHandler
	 *   via Router::httpError(404) to avoid route disclosure.
	 * - Collects runtime metrics (time since boot, memory, included files).
	 * - Builds package list via Composer\InstalledVersions (if available).
	 * - Produces a deep, JSON-serializable config snapshot with masking.
	 * - Provides export strings for convenient cfg overrides in the app.
	 *
	 * Notes:
	 * - No side effects (read-only). Uses LiteView template rendering.
	 * - Keep helper closures lean; if reused elsewhere, consider moving
	 *   them to a small utility class to avoid reallocation per request.
	 *
	 * @return void
	 */
	public function appinfo(): void {
		
		if (\defined('CITOMNI_ENVIRONMENT') && !\in_array(\CITOMNI_ENVIRONMENT, ['dev','stage'], true)) {
			// Use 404 (Not Found) to avoid disclosing the route in prod/stage.
			$this->app->router->httpError(404);
			return;
		}
		
		
		// ----------------------------- Helpers -------------------------------------

		$__maskValue = static function(string $key, mixed $value): mixed {
			$k = \strtolower($key);
			if (\preg_match('~(secret|token|password|pass|api[_-]?key|salt|private|credential|signature|auth|bearer)~i', $k)) {
				return '__redacted__';
			}
			if (\is_string($value)) {
				if (\strlen($value) >= 24 && \preg_match('~[A-Za-z0-9_\-]{24,}~', $value) === 1) {
					return '__redacted__';
				}
				if (\preg_match('~^(?:\w+://)[^/\s]+@~', $value) === 1) {
					return '__redacted__';
				}
			}
			return $value;
		};

		$__seen = new \SplObjectStorage();
		$__maxDepth = 8;

		$__toSafe = static function(mixed $v, int $depth = 0, ?string $kHint = null) use (&$__toSafe, $__maskValue, $__seen, $__maxDepth) {
			if ($depth >= $__maxDepth) {
				return '__max_depth__';
			}
			if ($v === null || \is_scalar($v)) {
				return $kHint !== null ? $__maskValue($kHint, $v) : $v;
			}
			if (\is_array($v)) {
				$out = [];
				foreach ($v as $k => $vv) {
					$ks = \is_int($k) ? (string)$k : (string)$k;
					$out[$k] = $__toSafe($vv, $depth + 1, $ks);
				}
				return $out;
			}
			if (\is_object($v)) {
				if ($__seen->contains($v)) {
					return '__recursion__';
				}
				$__seen->attach($v);

				if ($v instanceof \JsonSerializable) {
					return $__toSafe($v->jsonSerialize(), $depth + 1, $kHint);
				}
				if (\method_exists($v, 'toArray')) { try { return $__toSafe($v->toArray(), $depth + 1, $kHint); } catch (\Throwable) {} }
				if (\method_exists($v, 'getArrayCopy')) { try { return $__toSafe($v->getArrayCopy(), $depth + 1, $kHint); } catch (\Throwable) {} }
				if ($v instanceof \DateTimeInterface) { return $v->format(\DateTimeInterface::RFC3339_EXTENDED); }

				$props = \get_object_vars($v);
				if ($props) { return $__toSafe($props, $depth + 1, $kHint); }
				return '(object) ' . $v::class;
			}
			return '(resource)';
		};

		$__flatten = static function(array $arr, string $prefix = '') use (&$__flatten, $__maskValue) : array {
			$out = [];
			foreach ($arr as $k => $v) {
				$key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
				if (\is_array($v)) {
					$out += $__flatten($v, $key);
				} else {
					$out[$key] = $__maskValue($key, $v);
				}
			}
			\ksort($out);
			return $out;
		};

		/**
		 * Export any PHP value as a short-array string with tabs.
		 * - Uses [] instead of array()
		 * - Keeps integers/bools/null correct
		 * - Quotes strings safely (single quotes)
		 */
		// Declare first so we can reference recursively via `use (&$__exportPhp)`
		$__exportPhp = null;

		/**
		 * Export any PHP value as a short-array string with tabs.
		 * - Uses [] instead of array()
		 * - Preserves ints/bools/null
		 * - Safely quotes strings (single quotes)
		 */
		$__exportPhp = static function(mixed $v, int $level = 0) use (&$__exportPhp): string {
			$tab = "\t";

			if ($v === null) {
				return 'null';
			}
			if (\is_bool($v)) {
				return $v ? 'true' : 'false';
			}
			if (\is_int($v) || \is_float($v)) {
				return (string)$v;
			}
			if (\is_string($v)) {
				return "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
			}
			if (\is_array($v)) {
				$isList = \array_is_list($v);
				if ($v === []) {
					return '[]';
				}
				$indent = \str_repeat($tab, $level);
				$next   = \str_repeat($tab, $level + 1);
				$lines  = [];

				if ($isList) {
					foreach ($v as $vv) {
						$lines[] = $next . $__exportPhp($vv, $level + 1) . ',';
					}
				} else {
					foreach ($v as $kk => $vv) {
						$key = \is_int($kk) ? (string)$kk : "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$kk) . "'";
						$lines[] = $next . $key . ' => ' . $__exportPhp($vv, $level + 1) . ',';
					}
				}
				return "[\n" . \implode("\n", $lines) . "\n" . $indent . "]";
			}

			// objects/resources fall back to strings (kept safe)
			return "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
		};
		
		// Keep selected keys first (in given order), sort the rest alphabetically.
		$__orderTop = static function(array $arr, array $priorityKeys = ['identity']): array {
			$head = [];
			foreach ($priorityKeys as $k) {
				if (\array_key_exists($k, $arr)) {
					$head[$k] = $arr[$k];
					unset($arr[$k]);
				}
			}
			\ksort($arr); // alphabetical for remaining top-level keys
			return $head + $arr;
		};

		// Export ONLY the body of an array (no surrounding [ ... ]).
		// $baseLevel controls left padding of the top-level lines (0 = no indent).
		$__exportPhpBody = static function(array $arr, int $baseLevel = 0) use (&$__exportPhp): string {
			$prefix = \str_repeat("\t", $baseLevel);
			$lines  = [];
			foreach ($arr as $k => $v) {
				$key = \is_int($k) ? (string)$k : "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$k) . "'";
				// Important: pass $baseLevel to value exporter so nested arrays indent correctly
				$lines[] = $prefix . $key . ' => ' . $__exportPhp($v, $baseLevel) . ',';
			}
			return \implode("\n", $lines);
		};

		

		// --------------------------- Composer packages -----------------------------

		$packages = [];
		if (\class_exists(\Composer\InstalledVersions::class)) {
			foreach (\Composer\InstalledVersions::getInstalledPackages() as $pkg) {
				$packages[$pkg] = [
					'version' => \Composer\InstalledVersions::getPrettyVersion($pkg) ?? '',
					'ref'     => \Composer\InstalledVersions::getReference($pkg) ?? null,
				];
			}
			
			// \uksort($packages, static function($a, $b) {
				// $aa = \str_starts_with($a, 'citomni/') ? 0 : 1;
				// $bb = \str_starts_with($b, 'citomni/') ? 0 : 1;
				// return $aa <=> $bb ?: \strcmp($a, $b);
			// });
			
			\ksort($packages);
		}

		// ----------------------------- Active config --------------------------------

		$cfgTree = $__toSafe($this->app->cfg);
		$cfgFlat = $cfgTree;
		unset($cfgFlat['routes']);
		$cfgFlat = \is_array($cfgFlat) ? $__flatten($cfgFlat) : [];

		$nowNs   = \hrtime(true);
		$startNs = \defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs;
		$elapsed = (float)\sprintf('%.3f', ($nowNs - $startNs) / 1_000_000_000);

		$routesCount = (isset($this->app->cfg->routes) && \is_array($this->app->cfg->routes)) ? \count($this->app->cfg->routes) : 0;

		$baseUrl = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? \CITOMNI_PUBLIC_ROOT_URL
			: ($this->app->cfg->http->base_url ?? '');

		// ------------------------------- Build payload ------------------------------

		$sysinfo = [
			'citomni' => [
				'mode'        => 'http',
				'environment' => \defined('CITOMNI_ENVIRONMENT') ? \CITOMNI_ENVIRONMENT : '(unset)',
			],
			'app' => [
				'name'     => $this->app->cfg->identity->name ?? 'My CitOmni App',
				'version'  => $this->app->cfg->identity->version ?? null,
				'channel'  => $this->app->cfg->identity->channel ?? null,
				'base_url' => $baseUrl,
			],
			'metrics' => [
				'time_s'					=> $elapsed,
				'memory_usage_current_kb'	=> (int)\round(\memory_get_usage() / 1024),
				'memory_usage_peak_kb'		=> (int)\round(\memory_get_peak_usage() / 1024),
				'included_files_count'		=> \count(\get_included_files()),
				'routes_count'				=> $routesCount,
			],
			'opcache' => [
				'enabled'             => (bool)\filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL),
				'validate_timestamps' => \ini_get('opcache.validate_timestamps') !== '0',
			],
			'php' => [
				'version' => \PHP_VERSION,
			],
		];
		
		// $packages,
		
		// $cfgTree,
		
		// $cfgFlat,
			
		

		// ------------------------------ Render both ---------------------------------

		$sysinfoJson	= \json_encode($sysinfo, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		$packagesJson	= \json_encode($packages, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		$cfgFlatJson	= \json_encode($cfgFlat, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		
		// Start from your existing tree

		// A) Everything except routes, with identity first, rest A–Z
		$noRoutes = $cfgTree;
		unset($noRoutes['routes']);
		$noRoutes = $__orderTop($noRoutes, ['identity']);
		// $detailsPhpExRoutesFull  = $__exportPhp($noRoutes);     // with [ ... ]
		$cfgPhpExRoutesBody  = $__exportPhpBody($noRoutes); // NO [ ... ], ideal for <pre>

		// B) Routes only (sorted by path if you like)
		$routesOnly = $cfgTree['routes'] ?? [];
		\ksort($routesOnly);
		// $detailsPhpRoutesFull = $__exportPhp(['routes' => $routesOnly]); // pasteable block
		$routesPhpBody = $__exportPhpBody(['routes' => $routesOnly]); // body only

		// Robots + no-cache; also safe for member-only pages.
		$this->app->response->noIndex();
		
		$this->app->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
		

		// Render the home page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' 				=> 1,
			
			// Canonical URL
			'canonical' 			=> \CITOMNI_PUBLIC_ROOT_URL . "/appinfo.html",
			
			'meta_title'       		=> 'Application information',
			'meta_description' 		=> 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'       		=> 'READY',
			'badge_variant'    		=> 'badge--success', // green
			'title'            		=> 'Application information',
			'subtitle'         		=> 'CitOmni HTTP is up and running. All systems go.',
			// 'lead_text'        		=> 'You are all set. CitOmni is ready for your development. Update your routes to get started.',
			'lead_text'        		=> 'Green lights across the board. CitOmni is ready for your development!',
			'status_code'      		=> '200 OK',
			// 'status_text'      		=> '| CitOmni is ready for your development.',
		    // 'http_method'           => $_SERVER['REQUEST_METHOD'] ?? 'GET',
		    // 'request_path'          => $_SERVER['REQUEST_URI'] ?? '/',

		    'primary_href'          => 'https://github.com/citomni/http#readme',
		    'primary_target'		=> '_blank',
		    'primary_label'         => 'Open README',
		    'secondary_href'        => 'https://github.com/citomni/http/releases',
		    'secondary_target'		=> '_blank',
		    'secondary_label'       => 'Changelog',
		    'tertiary_href'			=> 'https://github.com/citomni/http/issues/new/choose',
		    'tertiary_target'		=> '_blank', // _self
		    'tertiary_label'		=> 'Report issue',
		    'year'                  => date('Y'),
		    'owner'                 => 'CitOmni.com',			
			
			'sysinfoJson'			=> $sysinfoJson ?? 'System information not found.',
			'packagesJson'			=> $packagesJson ?? 'No packages found.',
		    'cfgPhpExRoutesBody'	=> $cfgPhpExRoutesBody ?? 'No CFG found.',
		    'routesPhpBody'			=> $routesPhpBody ?? 'No Routes found.',
		    'cfgFlatJson'			=> $cfgFlatJson ?? 'No flat CFG found.',
			
		]);
		
		
	}


}
