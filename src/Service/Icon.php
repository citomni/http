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

use CitOmni\Http\Exception\IconConfigException;
use CitOmni\Http\Exception\IconNotFoundException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Layer-aware icon service.
 *
 * Resolves symbolic icon ids to trusted inline SVG markup from layer-owned
 * PHP icon files. Icon files return associative arrays with id => SVG string.
 *
 * Behavior:
 * - Resolves icon files from either the app layer or a vendor/package layer:
 *   1) app:              <CITOMNI_APP_PATH>/assets/icons/{file}.php
 *   2) vendor/package:   <CITOMNI_APP_PATH>/vendor/{layer}/assets/icons/{file}.php
 * - Loads icon files lazily on first access.
 * - Caches each loaded file by absolute path for the current request/process.
 * - Fails fast on missing files, missing ids, invalid payloads, and invalid SVG values.
 *
 * Notes:
 * - Icons are trusted view assets, not config and not language strings.
 * - SVG markup is not sanitized. Never place user-controlled data in icon files.
 * - Templates must render the returned value as raw trusted markup.
 * - The default file is "icons"; the default layer is "citomni/http".
 *
 * Typical usage:
 *   $svg = $this->app->icon->get('home');
 *   $svg = $this->app->icon->get('mfa_totp', 'icons', 'citomni/authenticate');
 *   $svg = $this->app->icon->get('logo', 'brand', 'app');
 *
 * @see \CitOmni\Kernel\Service\BaseService
 */
final class Icon extends BaseService {

	private const DEFAULT_FILE = 'icons';
	private const DEFAULT_LAYER = 'citomni/http';
	private const APP_LAYER = 'app';

	private const APP_ICONS_PATH = \CITOMNI_APP_PATH . '/assets/icons';
	private const VENDOR_PATH = \CITOMNI_APP_PATH . '/vendor';

	private const ID_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?$~';
	private const FILE_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9/_-]*[A-Za-z0-9])?$~';
	private const LAYER_PATTERN = '~^[a-z0-9._-]+/[a-z0-9._-]+$~i';


	/**
	 * Cached icon payloads keyed by absolute file path.
	 *
	 * Array shape:
	 * - <absolute-file-path> => array<string, mixed>
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = [];


	/**
	 * Initialize the service.
	 *
	 * Behavior:
	 * - Clears construction-time options because this service is intentionally
	 *   convention-based and does not consume service options.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->options = [];
	}






	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Resolve an icon id to inline SVG markup.
	 *
	 * Behavior:
	 * - Validates id, file, and layer.
	 * - Loads the requested icon file on first access.
	 * - Returns trimmed inline SVG markup.
	 * - Throws on missing files, missing ids, invalid payloads, or non-SVG values.
	 *
	 * Typical usage:
	 *   $svg = $this->app->icon->get('home');
	 *   $svg = $this->app->icon->get('mfa_totp', 'icons', 'citomni/authenticate');
	 *   $svg = $this->app->icon->get('logo', 'brand', 'app');
	 *
	 * @param string $id Icon id, e.g. "home" or "arrow-right".
	 * @param string $file Icon file name without ".php"; slashes allow subdirectories.
	 * @param string $layer Layer identifier: "app" or "vendor/package".
	 * @return string Trusted inline SVG markup.
	 * @throws \InvalidArgumentException When id, file, or layer is invalid.
	 * @throws IconNotFoundException When the icon file or icon id does not exist.
	 * @throws IconConfigException When the file payload or icon value is invalid.
	 */
	public function get(string $id, string $file = self::DEFAULT_FILE, string $layer = self::DEFAULT_LAYER): string {
		$this->assertValidId($id);
		$this->assertValidFile($file);
		$this->assertValidLayer($layer);

		$filePath = $this->buildFilePath($file, $layer);
		$data = $this->loadFileData($filePath, $file, $layer);

		if (!\array_key_exists($id, $data)) {
			throw new IconNotFoundException(\sprintf(
				'Icon "%s" not found in file "%s" (layer "%s", path "%s").',
				$id,
				$file,
				$layer,
				$filePath
			));
		}

		return $this->normalizeIconValue($data[$id], $id, $file, $layer);
	}


	/**
	 * Check whether an icon id exists in an icon file.
	 *
	 * Behavior:
	 * - Returns false when the file or id is missing.
	 * - Still validates caller input and throws on invalid id, file, or layer.
	 * - Still fails fast if an existing icon file has an invalid payload.
	 * - Does not validate the specific icon value beyond file payload shape.
	 *
	 * Typical usage:
	 *   if ($this->app->icon->has('logo', 'brand', 'app')) {
	 *       $svg = $this->app->icon->get('logo', 'brand', 'app');
	 *   }
	 *
	 * @param string $id Icon id to check.
	 * @param string $file Icon file name without ".php".
	 * @param string $layer Layer identifier: "app" or "vendor/package".
	 * @return bool True when the icon id exists in the requested file.
	 * @throws \InvalidArgumentException When id, file, or layer is invalid.
	 * @throws IconConfigException When an existing file returns an invalid payload.
	 */
	public function has(string $id, string $file = self::DEFAULT_FILE, string $layer = self::DEFAULT_LAYER): bool {
		$this->assertValidId($id);
		$this->assertValidFile($file);
		$this->assertValidLayer($layer);

		$filePath = $this->buildFilePath($file, $layer);

		if (!\is_file($filePath)) {
			return false;
		}

		$data = $this->loadFileData($filePath, $file, $layer);

		return \array_key_exists($id, $data);
	}


	/**
	 * Return all icon ids defined by an icon file.
	 *
	 * Behavior:
	 * - Loads the requested icon file on first access.
	 * - Returns ids in the order defined by the file.
	 * - Fails fast if the file is missing or invalid.
	 * - Does not validate every SVG value; use get() to resolve a specific icon.
	 *
	 * Typical usage:
	 *   $ids = $this->app->icon->ids('icons', 'citomni/http');
	 *
	 * @param string $file Icon file name without ".php".
	 * @param string $layer Layer identifier: "app" or "vendor/package".
	 * @return array<int, string> Icon ids defined by the file.
	 * @throws \InvalidArgumentException When file or layer is invalid.
	 * @throws IconNotFoundException When the icon file does not exist.
	 * @throws IconConfigException When the file payload is invalid.
	 */
	public function ids(string $file = self::DEFAULT_FILE, string $layer = self::DEFAULT_LAYER): array {
		$this->assertValidFile($file);
		$this->assertValidLayer($layer);

		$filePath = $this->buildFilePath($file, $layer);
		$data = $this->loadFileData($filePath, $file, $layer);

		return \array_keys($data);
	}






	// ----------------------------------------------------------------
	// Path resolution
	// ----------------------------------------------------------------

	/**
	 * Build the absolute file path for an icon file and layer.
	 *
	 * @param string $file Validated icon file name without ".php".
	 * @param string $layer Validated layer identifier.
	 * @return string Absolute path to the icon file.
	 */
	private function buildFilePath(string $file, string $layer): string {
		if ($layer === self::APP_LAYER) {
			return self::APP_ICONS_PATH . '/' . $file . '.php';
		}

		return self::VENDOR_PATH . '/' . $layer . '/assets/icons/' . $file . '.php';
	}







	// ----------------------------------------------------------------
	// File loading
	// ----------------------------------------------------------------

	/**
	 * Load and cache an icon file payload.
	 *
	 * Behavior:
	 * - Requires the file only once per absolute path.
	 * - Caches valid payloads for the current request/process.
	 * - Throws on missing files and invalid payloads.
	 *
	 * @param string $filePath Absolute icon file path.
	 * @param string $file Relative icon file name without ".php".
	 * @param string $layer Layer identifier for diagnostics.
	 * @return array<string, mixed> Raw icon payload.
	 * @throws IconNotFoundException When the file does not exist.
	 * @throws IconConfigException When the file does not return an array.
	 */
	private function loadFileData(string $filePath, string $file, string $layer): array {
		if (isset($this->cache[$filePath])) {
			return $this->cache[$filePath];
		}

		if (!\is_file($filePath)) {
			throw new IconNotFoundException(\sprintf(
				'Icon file "%s" not found for file "%s" (layer "%s").',
				$filePath,
				$file,
				$layer
			));
		}

		$data = require $filePath;

		if (!\is_array($data)) {
			throw new IconConfigException(\sprintf(
				'Icon file "%s" (layer "%s") must return an array, got %s.',
				$filePath,
				$layer,
				\get_debug_type($data)
			));
		}

		$this->cache[$filePath] = $data;

		return $this->cache[$filePath];
	}







	// ----------------------------------------------------------------
	// Value normalization
	// ----------------------------------------------------------------

	/**
	 * Validate and normalize one resolved icon value.
	 *
	 * Behavior:
	 * - Requires the value to be a string.
	 * - Trims surrounding whitespace for deterministic inline output.
	 * - Rejects XML declarations and DOCTYPE payloads.
	 * - Requires the final value to start with an inline <svg tag.
	 *
	 * @param mixed $value Raw value from the icon file.
	 * @param string $id Icon id for diagnostics.
	 * @param string $file Icon file for diagnostics.
	 * @param string $layer Layer identifier for diagnostics.
	 * @return string Trimmed inline SVG markup.
	 * @throws IconConfigException When the value is not valid inline SVG markup.
	 */
	private function normalizeIconValue(mixed $value, string $id, string $file, string $layer): string {
		if (!\is_string($value)) {
			throw new IconConfigException(\sprintf(
				'Icon "%s" in file "%s" (layer "%s") must be a string, got %s.',
				$id,
				$file,
				$layer,
				\get_debug_type($value)
			));
		}

		$svg = \trim($value);

		if ($svg === '') {
			throw new IconConfigException(\sprintf(
				'Icon "%s" in file "%s" (layer "%s") must not be empty.',
				$id,
				$file,
				$layer
			));
		}

		if (\str_starts_with($svg, '<?xml') || \str_starts_with($svg, '<!DOCTYPE')) {
			throw new IconConfigException(\sprintf(
				'Icon "%s" in file "%s" (layer "%s") must be inline SVG without XML declaration or DOCTYPE.',
				$id,
				$file,
				$layer
			));
		}

		if (\strncasecmp($svg, '<svg', 4) !== 0) {
			throw new IconConfigException(\sprintf(
				'Icon "%s" in file "%s" (layer "%s") must contain inline SVG markup.',
				$id,
				$file,
				$layer
			));
		}

		return $svg;
	}







	// ----------------------------------------------------------------
	// Input validation
	// ----------------------------------------------------------------

	/**
	 * Validate the icon id format.
	 *
	 * @param string $id Icon id.
	 * @return void
	 * @throws \InvalidArgumentException When the id is empty or unsafe.
	 */
	private function assertValidId(string $id): void {
		if (
			$id === '' ||
			\str_contains($id, '..') ||
			!\preg_match(self::ID_PATTERN, $id)
		) {
			throw new \InvalidArgumentException(\sprintf('Invalid icon id "%s".', $id));
		}
	}


	/**
	 * Validate the icon file identifier.
	 *
	 * Slashes are allowed for explicit subdirectories below assets/icons, but
	 * traversal and repeated slashes are rejected.
	 *
	 * @param string $file Relative icon file name without ".php".
	 * @return void
	 * @throws \InvalidArgumentException When the file name is empty or unsafe.
	 */
	private function assertValidFile(string $file): void {
		if (
			$file === '' ||
			\str_contains($file, '..') ||
			\str_contains($file, '//') ||
			!\preg_match(self::FILE_PATTERN, $file)
		) {
			throw new \InvalidArgumentException(\sprintf('Invalid icon file name "%s".', $file));
		}
	}


	/**
	 * Validate the icon layer identifier.
	 *
	 * @param string $layer Layer identifier. Use "app" or "vendor/package".
	 * @return void
	 * @throws \InvalidArgumentException When the layer is empty or unsafe.
	 */
	private function assertValidLayer(string $layer): void {
		if ($layer === self::APP_LAYER) {
			return;
		}

		if (!\preg_match(self::LAYER_PATTERN, $layer)) {
			throw new \InvalidArgumentException(\sprintf(
				'Invalid icon layer "%s". Expected "app" or "vendor/package" (e.g. "citomni/http").',
				$layer
			));
		}
	}

}
