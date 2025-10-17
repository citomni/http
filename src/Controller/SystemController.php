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

namespace CitOmni\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;


/**
 * SystemController: Minimal operations and observability endpoints for HTTP mode.
 *
 * Responsibilities:
 * - Expose tiny, deterministic endpoints for admin tasks, uptime, and smoke tests.
 * - Offer protected maintenance/cache controls via HMAC-based WebhooksAuth.
 * - Provide safe runtime snapshots (sysinfo, packages, config export).
 *
 * Collaborators:
 * - Reads: Request, Response, View, ErrorHandler, Maintenance, WebhooksAuth, App cfg wrapper.
 * - Writes: Response (JSON/text/HTML), Maintenance state, cache files (reset/warmup).
 *
 * Security note:
 * - Do NOT store webhook secrets in cfg. The HMAC secret is loaded from:
 *   CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php'
 * - The actual secret file must not be committed; keep only the `.tpl` template in VCS.
 *
 * Configuration keys:
 * - webhooks.secret_file (string) - Filesystem path to a side-effect-free PHP file
 *   that returns ['secret' => <hex>, 'algo' => 'sha256'|'sha512' (optional)].
 * - http.base_url (string) - Fallback for canonical links when CITOMNI_PUBLIC_ROOT_URL is unset.
 * - routes (array) - Listed but not echoed in flat cfg snapshot by default.
 *
 * Error handling:
 * - Fail-fast: Errors bubble to the global error handler.
 * - Protected endpoints deliberately fail with 404 to avoid route disclosure.
 * - No broad try/catch blocks here; logging is centralized.
 *
 * Typical usage:
 * - Called by deployment tooling, monitors, and CI smoke tests to verify liveness and perform safe ops.
 *
 * Examples:
 * - Core (liveness): GET /_system/ping  -> "OK 2025-10-16T22:31:00Z"
 * - Scenario (protected): POST /_system/reset-cache with valid HMAC -> { "ok": true, ... }
 *
 * Failure:
 * - Unauthorized protected calls return 404 via ErrorHandler->httpError(404) to hide presence.
 */
final class SystemController extends BaseController {

	/**
	 * Chosen, consistent failure status for protected endpoints.
	 * 404 hides the endpoint existence in all environments.
	 */
	private const PROTECTED_FAIL_STATUS = 404;

	/**
	 * Called once per request by BaseController.
	 * We keep it empty to avoid any implicit I/O.
	 */
	protected function init(): void {
		// Intentionally empty (no I/O). Each action sets no-cache explicitly.
	}





	public function appinfoHtml(): void {
		if (\defined('CITOMNI_ENVIRONMENT') && !\in_array(\CITOMNI_ENVIRONMENT, ['dev','stage'], true)) {
			// Use 404 (Not Found) to avoid disclosing the route in prod/stage.
			$this->app->errorHandler->httpError(404, [
				'reason' => 'not_found', // deliberately generic
			]);
			return;
		}

		// Build everything once (single source of truth)
		$g = $this->appinfoGenerator();

		// Robots + no-cache; also safe for member-only pages
		$this->app->response->noIndex();
		$this->app->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');

		// Render using the exact same variables/values/fallbacks as the original
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [

			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex'               => 1,

			// Canonical URL
			'canonical'             => \CITOMNI_PUBLIC_ROOT_URL . "/appinfo.html",

			'meta_title'            => 'Application information',
			'meta_description'      => 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'            => 'READY',
			'badge_variant'         => 'badge--success', // green
			'title'                 => 'Application information',
			'subtitle'              => 'CitOmni HTTP is up and running. All systems go.',
			// 'lead_text'           => 'You are all set. CitOmni is ready for your development. Update your routes to get started.',
			'lead_text'             => 'Green lights across the board. CitOmni is ready for your development!',
			'status_code'           => '200 OK',
			// 'status_text'         => '| CitOmni is ready for your development.',
			// 'http_method'         => $_SERVER['REQUEST_METHOD'] ?? 'GET',
			// 'request_path'        => $_SERVER['REQUEST_URI'] ?? '/',

			'primary_href'          => 'https://github.com/citomni/http#readme',
			'primary_target'        => '_blank',
			'primary_label'         => 'Open README',
			'secondary_href'        => 'https://github.com/citomni/http/releases',
			'secondary_target'      => '_blank',
			'secondary_label'       => 'Changelog',
			'tertiary_href'         => 'https://github.com/citomni/http/issues/new/choose',
			'tertiary_target'       => '_blank', // _self
			'tertiary_label'        => 'Report issue',
			'year'                  => \date('Y'),
			'owner'                 => 'CitOmni.com',

			// JSON + export blocks for the page (exact flags and fallbacks preserved)
			'sysinfoJson'           => $g['sysinfoJson']        ?? 'System information not found.',
			'packagesJson'          => $g['packagesJson']       ?? 'No packages found.',
			'cfgPhpExRoutesBody'    => $g['cfgPhpExRoutesBody'] ?? 'No CFG found.',
			'routesPhpBody'         => $g['routesPhpBody']      ?? 'No Routes found.',
			'cfgFlatJson'           => $g['cfgFlatJson']        ?? 'No flat CFG found.',
		]);
	}


	/**
	 * JSON twin of appinfoHtml(): returns the same data in one JSON payload.
	 * Path suggestion: /_system/appinfo.json (same env-guard as HTML).
	 *
	 * @return void
	 */
	public function appinfoJson(): void {
		if (\defined('CITOMNI_ENVIRONMENT') && !\in_array(\CITOMNI_ENVIRONMENT, ['dev','stage'], true)) {
			$this->app->errorHandler->httpError(404, ['reason' => 'not_found']);
			return;
		}

		$g = $this->appinfoGenerator();

		$payload = [
			// Samme datagrundlag som HTML-varianten bruger
			'sysinfo'                    => $g['_sysinfo_raw'],
			'packages'                   => $g['_packages_raw'],
			//'cfg_flat'                   => $g['_cfg_flat_raw'],
			'cfg'                   => $g['_cfg_tree_raw'],

			// De to PHP-exports som strenge (identisk formatering)
			// 'cfg_php_export_excl_routes' => $g['cfgPhpExRoutesBody'] ?? null,
			// 'routes_php_export'          => $g['routesPhpBody'] ?? null,
		];

		// Sætter no-cache + korrekt Content-Type + afslutter (never)
		$this->app->response->jsonNoCache($payload, true);
	}




	/**
	 * Single source of truth for appinfo data.
	 *
	 * Returns exactly the JSON strings and PHP-export bodies the HTML page expects,
	 * plus the raw arrays for the JSON endpoint.
	 *
	 * @return array{
	 *   sysinfoJson:string,packagesJson:string,cfgFlatJson:string,
	 *   cfgPhpExRoutesBody:string,routesPhpBody:string,
	 *   _sysinfo_raw:array,_packages_raw:array,_cfg_flat_raw:array,_cfg_tree_raw:array
	 * }
	 */
	private function appinfoGenerator(): array {
		// ----------------------------- Helpers -------------------------------------

		// Secret masker: Redact obvious secret-like values (fast heuristic, no DB lookups)
		$__maskValue = static function(string $key, mixed $value): mixed {
			$k = \strtolower($key);
			if (\preg_match('~(secret|token|password|pass|api[_-]?key|salt|private|credential|signature|auth|bearer)~i', $k)) {
				return '__redacted__';
			}
			if (\is_string($value)) {
				// Heuristics for long randoms / hex / base64-ish
				if (\preg_match('~^[A-Fa-f0-9]{32,}$~', $value) === 1) { return '__redacted__'; }
				if (\preg_match('~^[A-Za-z0-9+/=]{40,}$~', $value) === 1) { return '__redacted__'; }
				// Heuristic: URI with userinfo
				if (\preg_match('~^(?:\w+://)[^/\s]+@~', $value) === 1) { return '__redacted__'; }
			}
			return $value;
		};

		// Cyclic reference guard for safe object traversal
		$__seen = new \SplObjectStorage();
		$__maxDepth = 8;  // Defense-in-depth: avoid deep object graphs

		// Convert arbitrary structures into JSON-serializable safe values
		$__toSafe = static function(mixed $v, int $depth = 0, ?string $kHint = null) use (&$__toSafe, $__maskValue, $__seen, $__maxDepth) {
			if ($depth >= $__maxDepth) { return '__max_depth__'; }
			if ($v === null || \is_scalar($v)) { return $kHint !== null ? $__maskValue($kHint, $v) : $v; }
			if (\is_array($v)) {
				$out = [];
				foreach ($v as $k => $vv) {
					$ks = \is_int($k) ? (string)$k : (string)$k;
					$out[$k] = $__toSafe($vv, $depth + 1, $ks);
				}
				return $out;
			}
			if (\is_object($v)) {
				if ($__seen->contains($v)) { return '__cycle__'; }
				$__seen->attach($v);
				try { if ($v instanceof \DateTimeInterface) { return $v->format(\DateTimeInterface::RFC3339_EXTENDED); } } catch (\Throwable) {}
				try {
					$props = \get_object_vars($v);
					if ($props) { return $__toSafe($props, $depth + 1, $kHint); }
				} catch (\Throwable) {}
				return '(object) ' . $v::class;
			}
			return '(resource)';
		};

		// Flatten nested arrays into dot.notation => value for quick scanning
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
		 * - Preserves ints/bools/null
		 * - Safely quotes strings (single quotes)
		 */
		$__exportPhp = null;
		$__exportPhp = static function(mixed $v, int $level = 0) use (&$__exportPhp): string {
			$tab = "\t";
			if ($v === null) { return 'null'; }
			if (\is_bool($v)) { return $v ? 'true' : 'false'; }
			if (\is_int($v) || \is_float($v)) { return (string)$v; }
			if (\is_string($v)) {
				return "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
			}
			if (\is_array($v)) {
				if ($v === []) { return '[]'; }
				$indent = \str_repeat($tab, $level);
				$next   = \str_repeat($tab, $level + 1);
				$lines  = [];
				foreach ($v as $k => $vv) {
					$key = \is_int($k) ? (string)$k : "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$k) . "'";
					$lines[] = $next . $key . ' => ' . $__exportPhp($vv, $level + 1) . ',';
				}
				return "[\n" . \implode("\n", $lines) . "\n" . $indent . "]";
			}
			// objects/resources fall back to strings (kept safe)
			return "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
		};

		// Keep selected keys first (in given order), sort the rest alphabetically
		$__orderTop = static function(array $arr, array $priorityKeys = ['identity']): array {
			$head = [];
			foreach ($priorityKeys as $k) {
				if (isset($arr[$k])) {
					$head[$k] = $arr[$k];
					unset($arr[$k]);
				}
			}
			\ksort($arr); // alphabetical for remaining top-level keys
			return $head + $arr;
		};

		// Export ONLY the body of an array (no surrounding [ ... ])
		// $baseLevel controls left padding of the top-level lines (0 = no indent).
		$__exportPhpBody = static function(array $arr, int $baseLevel = 0) use (&$__exportPhp): string {
			$prefix = \str_repeat("\t", $baseLevel);
			$lines  = [];
			foreach ($arr as $k => $v) {
				$key = \is_int($k) ? (string)$k : "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$k) . "'";
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
			\ksort($packages); // deterministic
		}


		// ----------------------------- Active config --------------------------------
		// Convert the read-only wrapper to a safe, serializable array tree.
		// We MUST end with a real array to keep HTML panels non-empty (1:1 behavior).
		$__cfgToArray = static function(mixed $cfg): array {
			if (\is_array($cfg)) {
				return $cfg;
			}
			if (\is_object($cfg)) {
				// Preferred explicit export if available
				if (\method_exists($cfg, 'toArray')) {
					try { $arr = $cfg->toArray(); if (\is_array($arr)) { return $arr; } } catch (\Throwable) {}
				}
				// Iterator fallback
				if ($cfg instanceof \Traversable) {
					$out = [];
					try { foreach ($cfg as $k => $v) { $out[$k] = $v; } } catch (\Throwable) {}
					return $out;
				}
				// Public props
				try {
					$vars = \get_object_vars($cfg);
					if (!empty($vars)) { return $vars; }
				} catch (\Throwable) {}
			}
			// Last resort: JSON round-trip (safe + stable enough for config)
			$tmp = \json_decode(\json_encode($cfg, \JSON_PARTIAL_OUTPUT_ON_ERROR), true);
			return \is_array($tmp) ? $tmp : [];
		};

		// Build a safe tree first, then apply masking/normalization (like original)
		$cfgTreeRaw = $__cfgToArray($this->app->cfg);
		$cfgTree    = $__toSafe($cfgTreeRaw);

		// Ensure array from here on
		$cfgTreeArr = \is_array($cfgTree) ? $cfgTree : [];

		// Build a flattened config, excluding routes (noise-heavy)
		$cfgFlatSource = $cfgTreeArr;
		if (\array_key_exists('routes', $cfgFlatSource) && \is_array($cfgFlatSource['routes'])) {
			unset($cfgFlatSource['routes']); // Avoid noisy route maps in flat view
		}
		$cfgFlat = $__flatten($cfgFlatSource);

		// Keep the original timing/metrics exactly as before
		$nowNs   = \hrtime(true);
		$startNs = \defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs;
		$elapsed = (float)\sprintf('%.3f', ($nowNs - $startNs) / 1_000_000_000);

		$routesCount = (isset($this->app->cfg->routes) && \is_array($this->app->cfg->routes)) ? \count($this->app->cfg->routes) : 0;

		$baseUrl = \defined('CITOMNI_PUBLIC_ROOT_URL')
			? \CITOMNI_PUBLIC_ROOT_URL
			: ($this->app->cfg->http->base_url ?? '');




		// ------------------------------- Build payload ------------------------------
		$sysinfo = [
			'environment'   => \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : '(unknown)',
			'hostname'      => \gethostname() ?: 'unknown',
			'datetime_local'=> \date('c'),
			'datetime_utc'  => \gmdate('c'),
			'app' => [
				'name'     => $this->app->cfg->identity->name ?? 'My CitOmni App',
				'version'  => $this->app->cfg->identity->version ?? null,
				'channel'  => $this->app->cfg->identity->channel ?? null,
				'base_url' => $baseUrl,
			],
			'metrics' => [
				'time_s'                    => $elapsed,
				'memory_usage_current_kb'   => (int)\round(\memory_get_usage() / 1024),
				'memory_usage_peak_kb'      => (int)\round(\memory_get_peak_usage() / 1024),
				'included_files_count'      => \count(\get_included_files()),
				'routes_count'              => $routesCount,
			],
			'opcache' => [
				'enabled'             => (bool)\filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL),
				'validate_timestamps' => \ini_get('opcache.validate_timestamps') !== '0',
			],
			'php' => [
				'version' => \PHP_VERSION,
			],
		];

		// ------------------------------ JSON strings --------------------------------
		$sysinfoJson  = \json_encode($sysinfo,  \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		$packagesJson = \json_encode($packages, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		$cfgFlatJson  = \json_encode($cfgFlat,  \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);


		// --------------------------- PHP export bodies ------------------------------
		// A) Everything except routes, with identity first, rest A–Z
		$noRoutes = $cfgTreeArr;
		if (\array_key_exists('routes', $noRoutes)) {
			unset($noRoutes['routes']);
		}
		$noRoutes = $__orderTop($noRoutes, ['identity']);
		$cfgPhpExRoutesBody = $__exportPhpBody($noRoutes); // body-only for <pre>

		// B) Routes only (sorted by path)
		$routesOnly = [];
		if (isset($cfgTreeArr['routes']) && \is_array($cfgTreeArr['routes'])) {
			$routesOnly = $cfgTreeArr['routes'];
		}
		\ksort($routesOnly);
		$routesPhpBody = $__exportPhpBody(['routes' => $routesOnly]);



		return [
			// strings used by HTML template (kept 1:1)
			'sysinfoJson'        => $sysinfoJson,
			'packagesJson'       => $packagesJson,
			'cfgFlatJson'        => $cfgFlatJson,
			'cfgPhpExRoutesBody' => $cfgPhpExRoutesBody,
			'routesPhpBody'      => $routesPhpBody,

			// raw data used by JSON endpoint
			'_sysinfo_raw'  => $sysinfo,
			'_packages_raw' => $packages,
			'_cfg_flat_raw' => $cfgFlat,
			'_cfg_tree_raw' => $cfgTree,
		];
	}



	/**
	 * Render a dev/stage application information page with safe snapshots.
	 *
	 * Behavior:
	 * - Gates access to dev/stage; non-dev returns 404 to avoid route disclosure.
	 * - Computes sysinfo metrics and encodes compact JSON payloads.
	 * - Lists Composer packages (if InstalledVersions is available).
	 * - Exposes cfg snapshots (tree, flat, routes) with secret masking.
	 * - Renders a small LiteView template with canonical metadata.
	 *
	 * Notes:
	 * - Read-only operation; no DB or remote I/O.
	 * - Secret masking is heuristic-based and errs on the side of redaction.
	 * - Export helpers format arrays as short syntax with tabs to ease copy/paste.
	 *
	 * Typical usage:
	 *   Used during local development and staging verification to inspect runtime.
	 *
	 * Examples:
	 *
	 *   // Success (dev):
	 *   GET /_system/appinfo.html  -> 200 HTML with JSON+PHP export blocks
	 *
	 *   // Denied (prod):
	 *   GET /_system/appinfo.html  -> 404 via ErrorHandler
	 *
	 * Failure:
	 * - On prod/stage, intentionally surfaced as 404 to hide the endpoint.
	 *
	 * @return void
	 */
	public function appinfo(): void {
		
		if (\defined('CITOMNI_ENVIRONMENT') && !\in_array(\CITOMNI_ENVIRONMENT, ['dev','stage'], true)) {
			// Use 404 (Not Found) to avoid disclosing the route in prod/stage.
			$this->app->errorHandler->httpError(404, [
				'reason' => 'not_found', // deliberately generic
			]);
			return;
		}
		
		
		// ----------------------------- Helpers -------------------------------------

		// Secret masker: Redact obvious secret-like values (fast heuristic, no DB lookups)
		$__maskValue = static function(string $key, mixed $value): mixed {
			$k = \strtolower($key);
			if (\preg_match('~(secret|token|password|pass|api[_-]?key|salt|private|credential|signature|auth|bearer)~i', $k)) {
				return '__redacted__';
			}
			if (\is_string($value)) {
				
				// Heuristic: Long opaque strings (common for tokens/keys)
				if (\strlen($value) >= 24 && \preg_match('~[A-Za-z0-9_\-]{24,}~', $value) === 1) {
					return '__redacted__';
				}
				
				// Heuristic: URI with userinfo
				if (\preg_match('~^(?:\w+://)[^/\s]+@~', $value) === 1) {
					return '__redacted__';
				}
			}
			return $value;
		};
		
		// Cyclic reference guard for safe object traversal
		$__seen = new \SplObjectStorage();
		$__maxDepth = 8;  // Defense-in-depth: avoid deep object graphs

		// Convert arbitrary structures into JSON-serializable safe values
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

				// Favor JsonSerializable or common "to array" affordances
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

		// Flatten nested arrays into dot.notation => value for quick scanning
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
		
		// Keep selected keys first (in given order), sort the rest alphabetically
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

		// Export ONLY the body of an array (no surrounding [ ... ])
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
			
			// Deterministic order: plain ksort, citomni/* will naturally group together
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
		// Convert the read-only wrapper to a safe, serializable structure.
		// Guard against non-array returns to avoid "Cannot unset string offsets".
		$cfgTree = $__toSafe($this->app->cfg);

		// Ensure we work with arrays from here on
		$cfgTreeArr = \is_array($cfgTree) ? $cfgTree : (array)$cfgTree;

		// Build a flattened config, excluding routes (noise-heavy)
		$cfgFlatSource = $cfgTreeArr;
		if (\is_array($cfgFlatSource) && \array_key_exists('routes', $cfgFlatSource)) {
			unset($cfgFlatSource['routes']); // Avoid noisy route maps in flat view
		}
		$cfgFlat = \is_array($cfgFlatSource) ? $__flatten($cfgFlatSource) : [];

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
		// $detailsPhpExRoutesFull  = $__exportPhp($noRoutes);     // with [ ... ] (body-only for compact template inclusion)
		$cfgPhpExRoutesBody  = $__exportPhpBody($noRoutes); // NO [ ... ], ideal for <pre>

		// B) Routes only (sorted by path) — guard if cfgTree isn't an array
		$routesOnly = [];
		if (\is_array($cfgTreeArr) && isset($cfgTreeArr['routes']) && \is_array($cfgTreeArr['routes'])) {
			$routesOnly = $cfgTreeArr['routes'];
		}
		\ksort($routesOnly);
		$routesPhpBody = $__exportPhpBody(['routes' => $routesOnly]);

		// Robots + no-cache; also safe for member-only pages
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


	/**
	 * Return the resolved client IP address (proxy-aware).
	 *
	 * Behavior:
	 * - Uses Request service trust rules (trusted proxies, headers).
	 * - Emits {"ip": "..."} with no-cache headers.
	 *
	 * Notes:
	 * - No remote I/O; constant-time response.
	 *
	 * Typical usage:
	 *   Diagnose proxy chains and LB configuration during rollout.
	 *
	 * Examples:
	 *
	 *   // Direct client
	 *   GET /_system/clientip  -> { "ip": "203.0.113.7" }
	 *
	 *   // Behind proxy
	 *   GET /_system/clientip  -> { "ip": "198.51.100.10" }
	 *
	 * Failure:
	 * - None; falls back to Request->ip() semantics.
	 *
	 * @return void
	 */
	public function clientIp(): void {
		$this->app->response->noCache();
		$ip = $this->app->request->ip();
		$this->app->response->jsonStatus([
			'ip' => $ip,
		], 200);
	}


	/**
	 * Return a tiny liveness signal.
	 *
	 * Behavior:
	 * - Returns "OK <utc>" as plain text with 200.
	 *
	 * Notes:
	 * - Simplest possible endpoint for external uptime checks.
	 *
	 * Typical usage:
	 *   Used by monitors to verify HTTP reachability without JSON parsing.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   GET /_system/ping -> "OK 2025-10-16T22:31:00Z"
	 *
	 *   // Idempotent
	 *   GET /_system/ping -> will give you the same shape with a new timestamp
	 *
	 * Failure:
	 * - None; purely computed response.
	 *
	 * @return void
	 */
	public function ping(): void {
		$this->app->response->noCache();
		$this->app->response->text('OK ' . \gmdate('Y-m-d\TH:i:s\Z'), 200);
	}
	
	 
	/**
	 * Return a shallow health snapshot without external calls.
	 *
	 * Behavior:
	 * - Emits php_version, environment, opcache_enabled, server_time_utc, timezone.
	 * - Avoids DB and network I/O for speed and determinism.
	 *
	 * Notes:
	 * - Schema is intentionally small. Keep schema stable; tools might diff these fields.
	 * - Returns:
	 * 		- php_version
	 * 		- environment (CITOMNI_ENVIRONMENT, if defined)
	 * 		- opcache_enabled (ini + function_exists check)
	 * 		- server_time_utc (RFC3339)
	 * 		- timezone (default PHP TZ)
	 *
	 * Typical usage:
	 *   Called by CI/CD tooling to confirm runtime flags and clock sanity.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/health -> { "php_version":"8.2.x", ... }
	 *
	 *   // With opcache disabled
	 *   GET /_system/health -> { "opcache_enabled": false, ... }
	 *
	 * Failure:
	 * - None; data is computed from local runtime only.
	 *
	 * @return void
	 */
	public function health(): void {
		$this->app->response->noCache();

		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'unknown';
		$tz  = \date_default_timezone_get() ?: 'UTC';

		$iniEnabled = (string)\ini_get('opcache.enable') !== '' ? (bool)\ini_get('opcache.enable') : false;
		$opcacheEnabled = \function_exists('opcache_get_status') && $iniEnabled;

		$this->app->response->jsonStatus([
			'php_version'      => \PHP_VERSION,
			'environment'      => $env,
			'opcache_enabled'  => $opcacheEnabled,
			'server_time_utc'  => \gmdate('c'),
			'timezone'         => $tz,
		], 200);
	}


	/**
	 * Report application/framework version markers without I/O.
	 *
	 * Typical markers:
	 * - CITOMNI_VERSION (if you define it in your kernel/build)
	 * - APP_VERSION     (optionally from app)
	 */
	public function version(): void {
		$this->app->response->noCache();

		$citomni = \defined('CITOMNI_VERSION') ? (string)\CITOMNI_VERSION : null;
		$app     = \defined('APP_VERSION') ? (string)\APP_VERSION : null;

		$this->app->response->jsonStatus([
			'citomni' => $citomni,
			'app'     => $app,
		], 200);
	}


	/**
	 * Return server time in UTC and local timezone.
	 *
	 * Behavior:
	 * - Emits time_utc (RFC3339), time_local, and timezone.
	 *
	 * Notes:
	 * - Helps detect drift between client and server clocks.
	 *
	 * Typical usage:
	 *   Used as sanity-check before HMAC timestamp validation.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/time -> { "time_utc":"...", "time_local":"...", "timezone":"..." }
	 *
	 *   // Idempotent
	 *   GET /_system/time -> Same keys, fresh values
	 *
	 * Failure:
	 * - None; values are computed locally.
	 *
	 * @return void
	 */
	public function time(): void {
		$this->app->response->noCache();

		$this->app->response->jsonStatus([
			'time_utc'   => \gmdate('c'),
			'time_local' => \date('c'),
			'timezone'   => \date_default_timezone_get() ?: 'UTC',
		], 200);
	}


	/**
	 * Echo minimal request metadata (dev/stage only).
	 *
	 * Behavior:
	 * - Returns selected headers and routing fields to aid proxy debugging.
	 * - Non-dev/stage requests receive 404 to avoid route disclosure.
	 *
	 * Notes:
	 * - Only exposes a minimal, non-sensitive subset of server vars.
	 *
	 * Typical usage:
	 *   Validate X-Forwarded-* headers and LB behavior during rollout.
	 *
	 * Examples:
	 *
	 *   // Dev
	 *   GET /_system/request-echo -> { "remote_addr":"...", "forwarded":"...", ... }
	 *
	 *   // Prod
	 *   GET /_system/request-echo -> 404
	 *
	 * Failure:
	 * - None; either returns data or delegates 404.
	 *
	 * @return void
	 */
	public function requestEcho(): void {
		$this->app->response->noCache();

		// Only allow this to run in dev and stage
		if (\CITOMNI_ENVIRONMENT !== 'dev' && \CITOMNI_ENVIRONMENT !== 'stage') {
			$this->app->errorHandler->httpError(404, ['title' => 'Not Found']);
		}

		$server = $_SERVER; // read-only dump, filtered below
		$out = [
			'remote_addr'        => (string)($server['REMOTE_ADDR'] ?? ''),
			'forwarded'          => (string)($server['HTTP_FORWARDED'] ?? ''),
			'x_forwarded_for'    => (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''),
			'x_forwarded_host'   => (string)($server['HTTP_X_FORWARDED_HOST'] ?? ''),
			'x_forwarded_proto'  => (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''),
			'user_agent'         => (string)($server['HTTP_USER_AGENT'] ?? ''),
			'method'             => (string)($server['REQUEST_METHOD'] ?? ''),
			'host'               => (string)($server['HTTP_HOST'] ?? ''),
			'uri'                => (string)($server['REQUEST_URI'] ?? ''),
		];

		$this->app->response->jsonStatus($out, 200);
	}


	/**
	 * Return the current trusted proxy list.
	 *
	 * Behavior:
	 * - Reads Request->getTrustedProxies() and returns a redacted list (IPv6 shortened).
	 *
	 * Notes:
	 * - Public and read-only; helps explain client IP resolution.
	 *
	 * Typical usage:
	 *   Verify trusted proxies after infra changes or CDN rollouts.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/trusted-proxies -> { "trusted_proxies": ["192.0.2.1"], "count": 1 }
	 *
	 *   // IPv6 redaction
	 *   GET /_system/trusted-proxies -> ["2001:db8:85a3:...."]
	 *
	 * Failure:
	 * - None; list may be empty if not configured.
	 *
	 * @return void
	 */
	public function trustedProxies(): void {
		$this->app->response->noCache();

		$proxies = $this->app->request->getTrustedProxies();
		$out = \array_values(\array_map(static function ($x) {
			
			// Optional: Minimal IPv6 redaction to avoid dumping long blocks
			$x = (string)$x;
			if (\strpos($x, ':') !== false && \strlen($x) > 16) {
				return \substr($x, 0, 16) . '...';
			}
			return $x;
		}, $proxies));

		$this->app->response->jsonStatus([
			'trusted_proxies' => $out,
			'count'           => \count($out),
		], 200);
	}


	/**
	 * Reset OPcache and remove CitOmni cache files (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC via WebhooksAuth; unauthorized returns 404.
	 * - OPcache: try opcache_reset(); also invalidate known cache files if any.
	 *   (performs a global opcache_reset() best-effort).
	 * - Files: remove var/cache/{cfg.http.php,services.http.php} if they exist.
	 *          For legacy layouts, also try /var/cfg.http.php and /var/services.http.php.
	 * - Accepts optional JSON body with absolute file paths to invalidate:
	 *   Body:
	 *     (optional) JSON { "paths": ["/abs/extra/file1.php", ...] } to invalidate files.
	 *
	 * Notes:
	 * - Only operates on known cache files by default; extra paths must be absolute.
	 * - Idempotent: Missing files are ignored; failures are reported in "failed".
	 *
	 * Typical usage:
	 *   Triggered post-deploy when OPcache timestamps are disabled.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   POST /_system/reset-cache  (HMAC ok) -> { "ok": true, "removed":[...], ... }
	 *
	 *   // Extra file invalidation
	 *   POST body: { "paths": ["/abs/file.php"] }
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler (endpoint remains undisclosed).
	 *
	 * @return void
	 */
	public function resetCache(): void {
		$this->app->response->noCache();
		$raw = $this->requireWebhookOrAbort(); // 404 on failure (central handler)

		$removed = [];
		$failed  = [];
		$invalidated = [];

		// Known cache file candidates (HTTP mode).
		$candidates = [
			\CITOMNI_APP_PATH . '/var/cache/cfg.http.php',
			\CITOMNI_APP_PATH . '/var/cache/services.http.php',
		];

		// Optional extra files from JSON body (parsed from captured $raw)
		$body = \json_decode($raw, true) ?: [];
		if (!empty($body['paths']) && \is_array($body['paths'])) {
			foreach ($body['paths'] as $p) {
				$p = (string)$p;
				
				// Security: only allow absolute paths to avoid cwd tricks
				if ($p !== '' && $p[0] === \DIRECTORY_SEPARATOR) {
					$candidates[] = $p;
				}
			}
		}

		// Invalidate OPcache for candidates (if enabled) before deletion
		$canInvalidate = \function_exists('opcache_invalidate');

		foreach ($candidates as $path) {
			if (\is_file($path)) {
				if ($canInvalidate) {
					@\opcache_invalidate($path, true);
					$invalidated[] = $path;
				}
				if (@\unlink($path)) {
					$removed[] = $path;
				} else {
					$failed[] = $path;
				}
			}
		}

		// Global OPcache reset (best-effort)
		if (\function_exists('opcache_reset')) {
			@\opcache_reset();
		}

		$this->app->response->jsonStatus([
			'ok'           => $failed === [],
			'removed'      => $removed,
			'invalidated'  => $invalidated,
			'failed'       => $failed,
		], 200);
	}


	/**
	 * Warm up CitOmni caches (protected by WebhooksAuth)
	 *
	 * Behavior:
	 * - Verifies HMAC; unauthorized returns 404.
	 * - Calls App::warmCache(overwrite: true, opcacheInvalidate: true).
	 * - Returns number of cache files written.
	 *
	 * Notes:
	 * - Deterministic: cache content depends only on cfg/service maps.
	 *
	 * Typical usage:
	 *   Run post-deploy to ensure next request runs hot (OPcache already primed).
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   POST /_system/warmup-cache -> { "written": 2, "status":"ok" }
	 *
	 *   // Idempotent
	 *   POST again -> written may be equal (overwrites enabled)
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
	 */
	public function warmupCache(): void {
		$this->app->response->noCache();
		$this->requireWebhookOrAbort(); // 404 on failure

		$written = $this->app->warmCache(overwrite: true, opcacheInvalidate: true);

		$this->app->response->jsonStatus([
			'written' => (int)$written,
			'status'  => 'ok',
		], 200);
	}


	/**
	 * Return current maintenance snapshot (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; returns enabled, allowed_ips, retry_after, source.
	 *
	 * Notes:
	 * - Read-only; does not modify maintenance state.
	 *
	 * Typical usage:
	 *   Confirm who is allowed during a scheduled maintenance window.
	 *
	 * Examples:
	 *
	 *   // Baseline
	 *   GET /_system/maintenance -> { "enabled":true, "allowed_ips":[...], ... }
	 *
	 *   // Disabled
	 *   GET /_system/maintenance -> { "enabled":false, ... }
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
	 */
	public function maintenance(): void {
		$this->app->response->noCache();
		$this->requireWebhookOrAbort(); // read-only; no body needed

		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'enabled'      => (bool)$snap['enabled'],
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
			'source'       => (string)$snap['source'],
		], 200);
	}


	/**
	 * Enable maintenance mode (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; parses JSON body { allowed_ips?:[], retry_after?:int }.
	 * - Optionally sets retry_after hint; enables maintenance with allowlist.
	 *
	 * Notes:
	 * - IPs are taken verbatim; validation lives in the Maintenance service.
	 * - Body (JSON):
	 * 		{
	 *   		"allowed_ips": ["1.2.3.4", "2001:db8::1", "unknown"],  // optional
	 *   		"retry_after": 300  // optional
	 * 		}
	 *
	 * Typical usage:
	 *   Prepare a deploy window while allowing operator IPs through.
	 *
	 * Examples:
	 *
	 *   // Allow current office IP, set retry hint
	 *   POST /_system/maintenance/enable  body: {"allowed_ips":["203.0.113.5"],"retry_after":300}
	 *
	 *   // Minimal
	 *   POST /_system/maintenance/enable  body: {}
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
	 */
	public function maintenanceEnable(): void {
		$this->app->response->noCache();
		$raw = $this->requireWebhookOrAbort();

		$body = \json_decode($raw, true) ?: [];
		$ips  = \is_array($body['allowed_ips'] ?? null) ? (array)$body['allowed_ips'] : [];
		$retry = (int)($body['retry_after'] ?? -1);
		if ($retry >= 0) {
			$this->app->maintenance->setRetryAfter($retry);
		}

		$this->app->maintenance->enable($ips);
		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'status'       => 'enabled',
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
		], 200);
	}


	/**
	 * Disable maintenance mode (protected by WebhooksAuth).
	 *
	 * Behavior:
	 * - Verifies HMAC; optionally sets retry_after hint for clients.
	 * - Disables maintenance and returns new snapshot.
	 *
	 * Notes:
	 * - retry_after is advisory and does not delay disabling.
	 * - Body (JSON):
	 * 		{
	 *   		"retry_after": 120 // optional (hint to clients)
	 * 		}
	 *
	 * Typical usage:
	 *   End a maintenance window and restore normal access.
	 *
	 * Examples:
	 *
	 *   // Provide a short retry_after hint for caches
	 *   POST /_system/maintenance/disable  body: {"retry_after":120}
	 *
	 *   // Minimal
	 *   POST /_system/maintenance/disable  body: {}
	 *
	 * Failure:
	 * - HMAC guard failure -> 404 via ErrorHandler.
	 *
	 * @return void
	 */
	public function maintenanceDisable(): void {
		$this->app->response->noCache();
		$raw = $this->requireWebhookOrAbort();

		$body = \json_decode($raw, true) ?: [];
		$retry = (int)($body['retry_after'] ?? -1);
		if ($retry >= 0) {
			$this->app->maintenance->setRetryAfter($retry);
		}

		$this->app->maintenance->disable();
		$snap = $this->app->maintenance->snapshot();

		$this->app->response->jsonStatus([
			'status'       => 'disabled',
			'allowed_ips'  => (array)$snap['allowed_ips'],
			'retry_after'  => (int)$snap['retry_after'],
		], 200);
	}


	/**
	 * Debug HMAC validation for webhooks (heavily gated).
	 *
	 * Behavior:
	 * - Option A (commented): Restrict to dev/stage.
	 * - Option B (active): Allow exactly one operator IP in prod for short bursts.
	 * - Returns explicit authorization result and echoes seen headers.
	 *
	 * Notes:
	 * - Reads raw body directly (php://input) to keep HMAC bytes exact.
	 * - Replace the placeholder IP before enabling in prod (seriously).
	 *
	 * Typical usage:
	 *   Temporary diagnostics to validate client signatures during setup.
	 *
	 * Examples:
	 *
	 *   // Authorized
	 *   POST /_system/webhook-debug (HMAC ok) -> { "authorized": true, ... }
	 *
	 *   // Unauthorized
	 *   POST /_system/webhook-debug (bad HMAC) -> { "authorized": false, "error":"..." }
	 *
	 * Failure:
	 * - Non-allowed IPs receive 404 via ErrorHandler (conceal endpoint).
	 *
	 * @return void
	 */
	public function webhookDebug(): void {
		$this->app->response->noCache();

		// Gate A: Only allow this in dev/stage
		// if (!\in_array(\CITOMNI_ENVIRONMENT, ['dev', 'stage'], true)) {
		// 	$this->app->errorHandler->httpError(404, ['title' => 'Not Found']);
		// }

		// Gate B: Temporary single IP in prod (replace the address!)
		$allowedIp = 'INSERT_YOUR_OWN_IP_HERE';  // NOTE: You can find it by calling the route: /_system/clientip 
		$clientIp  = $this->app->request->ip();
		if ($clientIp !== $allowedIp) {
			// Security: Conceal this endpoint when IP does not match
			$this->app->errorHandler->httpError(404, ['title' => 'Not Found']);
		}

		// Read raw once (do not use Request->json() here; HMAC needs the exact raw body)
		$raw = (string)(@\file_get_contents('php://input') ?: '');

		try {
			$this->app->webhooksAuth
				->setOptions($this->app->cfg->webhooks)
				->assertAuthorized($_SERVER, $raw);

			$this->app->response->jsonStatus([
				'authorized'   => true,
				'message'      => 'OK',
				'seen_headers' => [
					'signature' => $_SERVER['HTTP_X_CITOMNI_SIGNATURE']  ?? null,
					'timestamp' => $_SERVER['HTTP_X_CITOMNI_TIMESTAMP']  ?? null,
					'nonce'     => $_SERVER['HTTP_X_CITOMNI_NONCE']      ?? null,
				],
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
			], 200);
		} catch (\Throwable $e) {
			
			// Debug mode returns explicit reason; do not replicate elsewhere
			$this->app->response->jsonStatus([
				'authorized'   => false,
				'error'        => $e->getMessage(),
				'seen_headers' => [
					'signature' => $_SERVER['HTTP_X_CITOMNI_SIGNATURE']  ?? null,
					'timestamp' => $_SERVER['HTTP_X_CITOMNI_TIMESTAMP']  ?? null,
					'nonce'     => $_SERVER['HTTP_X_CITOMNI_NONCE']      ?? null,
				],
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
			], 200);
		}
	}





// -----------------------
// Helpers (no I/O)
// -----------------------


	/**
	 * Enforce HMAC authorization for protected endpoints or abort with 404.
	 * (require a valid WebhooksAuth signature or abort via central ErrorHandler).
	 *
	 * Behavior:
	 * - Reads the raw request body exactly once from php://input.
	 * - Applies cfg options to WebhooksAuth and runs guard() (fail-soft).
	 * - On failure, delegates to ErrorHandler->httpError(PROTECTED_FAIL_STATUS).
	 * - On success, returns the raw body for optional JSON decoding by callers.
	 *
	 * Notes:
	 * - Uses guard() (boolean) instead of throwing; controllers stay linear.
	 * - 404 is intentional to hide endpoint presence during brute checks.
	 *
	 * Typical usage:
	 *   First line in protected action methods to centralize access control.
	 *
	 * Examples:
	 *
	 *   // Authorized
	 *   $raw = requireWebhookOrAbort(); json_decode($raw, true);
	 *
	 *   // Unauthorized
	 *   requireWebhookOrAbort(); // never returns; ErrorHandler emits 404
	 *
	 * Failure:
	 * - On unauthorized, control never returns; ErrorHandler handles response.
	 *
	 * @return string Raw request body for subsequent parsing.
	 */
	private function requireWebhookOrAbort(): string {
		$raw = (string)(@\file_get_contents('php://input') ?: '');

		$ok = $this->app->webhooksAuth
			->setOptions($this->app->cfg->webhooks)
			->guard($_SERVER, $raw);

		if (!$ok) {
			// Security: Use 404 to avoid revealing protected endpoint existence
			$this->app->errorHandler->httpError(self::PROTECTED_FAIL_STATUS, ['title' => 'Not Found']);
		}

		return $raw;
	}
}
