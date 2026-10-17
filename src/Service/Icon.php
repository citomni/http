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
 * Icon: Service that resolves icon ids to inline SVG markup from layered icon files.
 *
 * Lets the app and every package ship their own icons, each in PHP files under its own
 * assets/icons, and lets callers reach them by id, file and layer. The icons themselves
 * belong to the layer that ships them; placing the markup in a page belongs to the template.
 *
 * Rules:
 * - Treat icon files as trusted code: They are included as PHP, and their SVG is returned
 *   unsanitized. Never put user-controlled data in an icon file.
 * - Validate id, file and layer with the assertValid* methods before they reach a path.
 * - Load an icon file only through loadFileData(), so a valid file is included once per instance.
 * - Report a missing file or id with IconNotFoundException, and a malformed file or value
 *   with IconConfigException.
 */
class Icon extends BaseService {

	/** Icon file, without .php, when a call gives none. */
	private const DEFAULT_FILE = 'icons';

	/** Layer when a call gives none. */
	private const DEFAULT_LAYER = 'citomni/http';

	/** Layer name of the app itself, as opposed to a vendor/package layer. */
	private const APP_LAYER = 'app';

	/** Directory of the app layer's icon files. */
	private const APP_ICONS_PATH = \CITOMNI_APP_PATH . '/assets/icons';

	/** Composer vendor directory, under which a vendor/package layer keeps assets/icons. */
	private const VENDOR_PATH = \CITOMNI_APP_PATH . '/vendor';

	/** Valid icon id: Letters, digits, _ and -, starting and ending with a letter or digit. */
	private const ID_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?$~';

	/** Valid icon file name without .php: As ID_PATTERN, with / for subdirectories. */
	private const FILE_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9/_-]*[A-Za-z0-9])?$~';

	/** Valid vendor/package layer: Two segments of letters, digits, ., _ and -. */
	private const LAYER_PATTERN = '~^[a-z0-9._-]+/[a-z0-9._-]+$~i';


	/** @var array<string,array<array-key,mixed>> Payload of each loaded icon file, by absolute path. */
	private array $cache = [];


	/**
	 * Discard the service options.
	 *
	 * The service takes no options; its paths come from CITOMNI_APP_PATH and the layer.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$this->options = [];
	}






	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Return the inline SVG markup of one icon.
	 *
	 * Behavior:
	 * - Reads assets/icons/{file}.php of the layer: Under CITOMNI_APP_PATH for the app layer,
	 *   under CITOMNI_APP_PATH/vendor/{layer} for a vendor/package layer.
	 * - Returns the value as normalizeIconValue() accepts it: Trimmed, starting with an svg
	 *   tag, unsanitized.
	 *
	 * Notes:
	 * - The markup is trusted and unescaped: A template must output it raw.
	 *
	 * Typical usage:
	 *   $svg = $this->app->icon->get('mfa_totp', 'icons', 'citomni/authenticate');
	 *
	 * @param  string  $id  Icon id, such as home or arrow-right.
	 * @param  string  $file  Icon file name without .php; slashes select subdirectories.
	 * @param  string  $layer  "app", or a vendor/package name such as citomni/http.
	 * @return string
	 * @throws \InvalidArgumentException  When the id, the file name or the layer is invalid.
	 * @throws \CitOmni\Http\Exception\IconNotFoundException  When the icon file does not exist or does not define the id.
	 * @throws \CitOmni\Http\Exception\IconConfigException  When the icon file returns no array or the value is not inline SVG.
	 * @see \CitOmni\Http\Service\Icon::normalizeIconValue()  Which values are accepted.
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
	 * Check whether an icon file defines an icon id.
	 *
	 * Behavior:
	 * - False when the icon file does not exist or does not define the id.
	 * - True when the file defines the id, whatever its value: get() may still reject it.
	 *
	 * Typical usage:
	 *   if ($this->app->icon->has('logo', 'brand', 'app')) { ... }
	 *
	 * @param  string  $id  Icon id.
	 * @param  string  $file  Icon file name without .php; slashes select subdirectories.
	 * @param  string  $layer  "app", or a vendor/package name such as citomni/http.
	 * @return bool
	 * @throws \InvalidArgumentException  When the id, the file name or the layer is invalid.
	 * @throws \CitOmni\Http\Exception\IconConfigException  When the icon file exists but returns no array.
	 * @see \CitOmni\Http\Service\Icon::get()  Where the icon file is looked up.
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
	 * List the icon ids that an icon file defines.
	 *
	 * Behavior:
	 * - Returns the keys in the order of the file, without checking the values: get() may
	 *   still reject one.
	 *
	 * Typical usage:
	 *   $ids = $this->app->icon->ids('icons', 'citomni/http');
	 *
	 * @param  string  $file  Icon file name without .php; slashes select subdirectories.
	 * @param  string  $layer  "app", or a vendor/package name such as citomni/http.
	 * @return list<array-key>  The keys of the file's array; PHP turns a decimal key such as "123" into an int.
	 * @throws \InvalidArgumentException  When the file name or the layer is invalid.
	 * @throws \CitOmni\Http\Exception\IconNotFoundException  When the icon file does not exist.
	 * @throws \CitOmni\Http\Exception\IconConfigException  When the icon file returns no array.
	 * @see \CitOmni\Http\Service\Icon::get()  Where the icon file is looked up.
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
	 * Build the path of an icon file.
	 *
	 * @param  string  $file  Icon file name without .php, already validated.
	 * @param  string  $layer  Layer, already validated.
	 * @return string  Absolute path, assuming CITOMNI_APP_PATH is absolute.
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
	 * Return the array that an icon file returns, cached by path.
	 *
	 * Behavior:
	 * - Later calls for the same path return the cached array without touching the file.
	 * - A file that returns no array is not cached, so every call includes it again.
	 *
	 * @param  string  $filePath  Absolute path of the icon file.
	 * @param  string  $file  Icon file name, for the message.
	 * @param  string  $layer  Layer, for the message.
	 * @return array<array-key,mixed>  Icon values by id, unchecked.
	 * @throws \CitOmni\Http\Exception\IconNotFoundException  When the file does not exist.
	 * @throws \CitOmni\Http\Exception\IconConfigException  When the file returns no array.
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
	 * Check one icon value and return it trimmed.
	 *
	 * Behavior:
	 * - Accepts a string that, after trimming, starts with "<svg" in any letter case.
	 * - Checks only the start: The rest of the markup is returned as it is.
	 *
	 * @param  mixed  $value  Value from the icon file.
	 * @param  string  $id  Icon id, for the message.
	 * @param  string  $file  Icon file name, for the message.
	 * @param  string  $layer  Layer, for the message.
	 * @return string
	 * @throws \CitOmni\Http\Exception\IconConfigException  When the value is not a string, is empty after trimming, starts with an XML declaration or a DOCTYPE, or does not start with "<svg".
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
	 * Check an icon id against ID_PATTERN.
	 *
	 * @param  string  $id
	 * @return void
	 * @throws \InvalidArgumentException  When the id does not match ID_PATTERN.
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
	 * Check an icon file name against FILE_PATTERN.
	 *
	 * Slashes select subdirectories below assets/icons; .. and repeated slashes are rejected,
	 * so the name cannot leave that directory.
	 *
	 * @param  string  $file  Icon file name without .php.
	 * @return void
	 * @throws \InvalidArgumentException  When the name contains .. or // or does not match FILE_PATTERN.
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
	 * Check a layer: "app", or a vendor/package name that matches LAYER_PATTERN.
	 *
	 * @param  string  $layer
	 * @return void
	 * @throws \InvalidArgumentException  When the layer is neither "app" nor matches LAYER_PATTERN.
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
