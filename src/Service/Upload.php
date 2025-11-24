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
 * Upload: Deterministic file/image upload service with optional GD re-encoding and thumbnails.
 *
 * Behavior:
 * - Column upload (single file) for CRUD columns (e.g., cover_path) with optional thumbnails.
 * - Attached images/files (multi) for separate attachment tables (handled batch-like).
 * - Deterministic rename based on cfg pattern + sanitized basename (+ optional random suffix).
 * - Enforces maxBytes (clamped against php.ini upload_max_filesize/post_max_size) and MIME allowlist.
 * - For images: validates via finfo + GD load; optional megapixel caps, re-encode, and thumbnail set.
 * - No global try/catch; methods return structured results; fatal errors bubble to global handler.
 *
 * Notes:
 * - Storage layout: CITOMNI_PUBLIC_PATH . '/uploads' . <cfg.dir> . <filename>.<ext>
 * - URL generation is not this service's concern; this only writes paths.
 * - i18n: $this->app->txt->get('<key>', 'upload', 'citomni/http', '<fallback>')
 *
 * Typical usage:
 *   $res = $this->app->upload->saveColumnUpload('cover_path', $cfg['columns']['cover_path']['upload'] ?? [], $payload, $row, $id);
 *   // On success, $res['path'] and $res['thumbs'] can be persisted by caller.
 */
final class Upload extends BaseService {

	/** @var \finfo|null */
	private ?\finfo $finfo = null;

	/**
	 * Initialize optional shared resources.
	 *
	 * Notes:
	 * - finfo is opened once per service instance to reduce overhead.
	 */
	protected function init(): void {
		$this->finfo = \class_exists(\finfo::class, false)
			? (@\finfo_open(\FILEINFO_MIME_TYPE) ?: null)
			: null;
	}

	/**
	 * Save a single "column upload" (image/file) with deterministic naming and cleanup.
	 *
	 * Behavior:
	 * - Validates file, dir and MIME per config.
	 * - For images:
	 *   - Decodes original once to a GdImage (optionally EXIF-orients for JPEG).
	 *   - Generates FULLSIZE directly from original when (w,h) are specified (always resample).
	 *   - Applies fullsize suffix (if provided) before extension.
	 *   - Generates all THUMBNAILS directly from the same original (no double-resampling).
	 * - For non-images:
	 *   - Moves file or auto-suffixes to avoid collisions (no thumbs).
	 * - On any thumbnail failure, deletes fullsize + already-written thumbs atomically.
	 * - When deleteOld=true, deletes previous column file and its thumbs.
	 *
	 * Notes:
	 * - Fullsize keys at root of $uploadCfg: w, h, fit ('crop'|'stretch'), suffix (string).
	 * - Thumbnails are configured under $uploadCfg['thumbnails'] as before.
	 *
	 * @param string      $fieldName   Input name in $_FILES.
	 * @param array       $uploadCfg   Upload configuration (see docs).
	 * @param array       $payload     POST payload (for rename pattern {col:...}).
	 * @param array|null  $currentRow  Current DB row for cleanup (may be null on create).
	 * @param int|null    $recordId    DB record id (optional, not required here).
	 * @param string|null $currentPath Current public path for this column (optional).
	 * @param string|null $columnName  Column name (required for deterministic cleanup).
	 * @return array{status:bool,path:?string,thumbs:array,error:array,deleted:array}
	 */
	public function saveColumnUpload(string $fieldName, array $uploadCfg, array $payload, ?array $currentRow, ?int $recordId = null, ?string $currentPath = null, ?string $columnName = null): array {

		// \var_dump(
			// ['$fieldName' => $fieldName],
			// ['$uploadCfg' => $uploadCfg],
			// ['$payload' => $payload],
			// ['$currentRow' => $currentRow],
			// ['$recordId' => $recordId],
			// ['$currentPath' => $currentPath],
			// ['$columnName' => $columnName],
			// ['$_FILES' => $_FILES],
			// ['extension_loaded("gd")' => extension_loaded('gd')],
			// ['function_exists("imagewebp")' =>function_exists('imagewebp')],
			// ['class_exists("Imagick")' => class_exists('Imagick')],
			// ['function_exists("imagecreatefromjpeg")' => function_exists('imagecreatefromjpeg')],
			// ['function_exists("imagecreatefromwebp")' => function_exists('imagecreatefromwebp')]
		// );		
		// exit;


		// Fail-fast on missing deterministic column name (used for cleanup)
		if ($columnName === null || $columnName === '') {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_missing_column_name', 'Column name is required for deterministic cleanup.')],
				'deleted'	=> []
			];
		}
		
		// Do we have any file input?
		if (!isset($_FILES[$fieldName])) {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_no_file', 'No file provided.')],
				'deleted'	=> []
			];
		}
		
		// Second check for presence of file input
		$f = $this->normalizeOneFile($_FILES[$fieldName]);
		if ($f === null) {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_no_file', 'No file provided.')],
				'deleted'	=> []
			];
		}


		// Get safe orig filename for the following error-messages
		$orig = $this->safeOrigName($f['name'] ?? '');


		// Low-level upload error?
		if ($f['error'] !== \UPLOAD_ERR_OK) {
			$msg = $this->errorFromUploadCode($f['error']);
			if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$msg],
				'deleted'	=> []
			];
		}
		
		// Empty file?
		if ($f['size'] <= 0) {
			$msg = $this->t('err_empty_file','File is empty.');
			if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$msg],
				'deleted'	=> []
			];
		}

		// Enforce max size (clamped by ini)
		// 0 means "no service-level cap" (only PHP ini caps apply via UPLOAD_ERR_* and finfo checks)
		$maxBytesCfg = (int)($uploadCfg['maxBytes'] ?? 0);
		$maxBytes = $this->clampMaxBytesByIni($maxBytesCfg);
		if ($maxBytes > 0 && $f['size'] > $maxBytes) {
			$msg = $this->t('err_too_large_bytes','File is too large.');
			if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$msg],
				'deleted'	=> []
			];
		}

		// Resolve target dir
		$dir = $this->normalizeDir((string)($uploadCfg['dir'] ?? ''));
		if ($dir === '') {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_cfg_dir_missing','Upload directory is missing in configuration.')],
				'deleted'	=> []
			];
		}
		$absDir = $this->absUploadsDir($dir);
		$this->ensureDir($absDir);

		// MIME + accept
		$mime   = $this->detectMime($f['tmp_name']);
		$accept = (array)($uploadCfg['accept'] ?? []);
		if ($accept !== [] && !\in_array($mime, $accept, true)) {
			$msg = $this->t('err_invalid_mime','File type not allowed.');
			if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$msg],
				'deleted'	=> []
			];
		}

		// Basename and extension
		$rename = (array)($uploadCfg['rename'] ?? []);
		$base = $this->buildBasenameFromPattern(
			(string)($rename['pattern'] ?? ''), $payload, (string)$f['name'],
			(bool)($rename['rand'] ?? true), (int)($rename['max'] ?? 80)
		);
		$overwrite = (bool)($uploadCfg['overwrite'] ?? false);
		$deleteOld = (bool)($uploadCfg['deleteOld'] ?? true);
		$deleted = [];

		$isImg          = $this->isImageMime($mime);
		$encoding       = (array)($uploadCfg['encoding'] ?? []);
		$targetFormat   = $isImg ? (string)($encoding['format'] ?? $this->extFromMime($mime)) : $this->extFromMime($mime);
		$targetQuality  = (int)($encoding['quality'] ?? 82);
		$ext            = $this->normalizeExt($targetFormat);

		// Fullsize controls (always resize when both > 0)
		$fullW      = (int)($uploadCfg['w'] ?? 0);
		$fullH      = (int)($uploadCfg['h'] ?? 0);
		$fullFit    = (string)($uploadCfg['fit'] ?? 'crop');   // 'crop' | 'stretch'
		$fullSuffix = (string)($uploadCfg['suffix'] ?? '');    // added before extension

		// Compose final fullsize target path (suffix before extension)
		$fullBaseName = $base . ($fullSuffix !== '' ? $fullSuffix : '');
		$targetPath   = $absDir . $fullBaseName . '.' . $ext;

		// Images: Decode once, then make fullsize + thumbs from the original
		if ($isImg) {
			[$w, $h] = $this->readImageDimensions($f['tmp_name']);
			if ($w <= 0 || $h <= 0) {
				$msg = $this->t('err_invalid_image','Invalid image.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				return [
					'status'	=> false,
					'path'		=> null,
					'thumbs'	=> [],
					'error'		=> [$msg],
					'deleted'	=> []
				];
			}
			$maxMP = (int)($uploadCfg['maxMegapixel'] ?? 0);
			if ($maxMP > 0) {
				$mp = (int)\ceil(($w * $h) / 1_000_000);
				if ($mp > $maxMP) {
					$msg = $this->t('err_too_large_megapixel','Image exceeds megapixel limit.');
					if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
					return [
						'status'	=> false,
						'path'		=> null,
						'thumbs'	=> [],
						'error'		=> [$msg],
						'deleted'	=> []
					];
				}
			}
			$ramCap   = (int)($uploadCfg['maxImageRamBytes'] ?? ($this->options['maxImageRamBytes'] ?? (128 * 1024 * 1024)));
			$estimated = (int)($w * $h * 5);
			if ($ramCap > 0 && $estimated > $ramCap) {
				$msg = $this->t('err_image_memory','Image is too large to process safely.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				return [
					'status'	=> false,
					'path'		=> null,
					'thumbs'	=> [],
					'error'		=> [$msg],
					'deleted'	=> []
				];
			}

			// Collision handling (ensure uniqueness) for fullsize (final name)
			if (!$overwrite && \is_file($targetPath)) {
				$targetPath = $this->resolveUniqueTargetPath($targetPath);
			}

			// Decode original once
			$src = $this->imageLoad($f['tmp_name']);
			if (!$src) {
				$msg = $this->t('err_invalid_image','Invalid image.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				return [
					'status'	=> false,
					'path'		=> null,
					'thumbs'	=> [],
					'error'		=> [$msg],
					'deleted'	=> []
				];
			}

			// Optional EXIF orientation (JPEG only)
			$exifOn = (bool)($uploadCfg['exifOrient'] ?? false);
			if ($exifOn && $mime === 'image/jpeg' && \function_exists('exif_read_data')) {
				$oriented = $this->applyExifOrientation($src, $f['tmp_name']);
				if ($oriented) { $src = $oriented; }
			}

			// --- Fullsize (from original, obviously) ---
			$fullOk = false;
			
			// Compute target size if only one dimension is given.
			$targetW = $fullW;
			$targetH = $fullH;
			if ($fullW > 0 && $fullH <= 0) {
				// Width fixed, derive height from aspect ratio.
				$targetW = $fullW;
				$targetH = (int)\round($fullW * ($h / $w));
			} elseif ($fullH > 0 && $fullW <= 0) {
				// Height fixed, derive width from aspect ratio.
				$targetH = $fullH;
				$targetW = (int)\round($fullH * ($w / $h));
			}

			if ($targetW > 0 && $targetH > 0) {
				$dst = ($fullFit === 'stretch')
					? $this->imageResizeStretch($src, $targetW, $targetH)
					: $this->imageResizeCropCenter($src, $targetW, $targetH);
				if ($dst) {
					$fullOk = $this->imageSave($dst, $targetPath, $ext, $targetQuality);
					\imagedestroy($dst);
				}
			} else {
				// No explicit fullsize (w,h) -> reencode if format differs/encoding set; else move file.
				$needReencode = ($encoding !== []) || !$this->sameFamily($mime, $ext);
				if ($needReencode) {
					$fullOk = $this->imageSave($src, $targetPath, $ext, $targetQuality);
				} else {
					// We still want single decode for thumbs, but moving the uploaded tmp is OK now.
					// Make sure targetPath uniqueness was handled above.
					\imagedestroy($src); // free decoded; thumbs will not be created below in this branch (since no thumbs require src)
					$fullOk = $this->moveUploadedFile($f['tmp_name'], $targetPath, $overwrite, false);
					// Reload original if thumbs exist (so we keep the "from original" guarantee)
					if (($uploadCfg['thumbnails'] ?? []) !== []) {
						$src = $this->imageLoad($targetPath);
					}
				}
			}

			if (!$fullOk) {
				if ($src) { \imagedestroy($src); }
				$msg = $this->t('err_write_failed','Failed to write file.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				return [
					'status'	=> false,
					'path'		=> null,
					'thumbs'	=> [],
					'error'		=> [$msg],
					'deleted'	=> []
				];
			}

			$this->tryChmodPublic($targetPath);

			// --- Thumbnails (also from original/same $src) ---
			$thumbsCfg = (array)($uploadCfg['thumbnails'] ?? []);
			$thumbCols = [];
			$writtenThumbAbs = [];

			if ($thumbsCfg !== []) {
				$piFull = \pathinfo($targetPath);
				$dirAbs = (string)($piFull['dirname'] ?? $absDir);
				$baseNoExtForThumbSeed = $base; // thumbs typically use their own suffix; base is the pattern root

				foreach ($thumbsCfg as $tCfg) {
					
					$tw = (int)($tCfg['w'] ?? 0);
					$th = (int)($tCfg['h'] ?? 0);

					// Derive missing dimension if exactly one is provided.
					if ($tw > 0 && $th <= 0) {
						$th = (int)\round($tw * ($h / $w));
					} elseif ($th > 0 && $tw <= 0) {
						$tw = (int)\round($th * ($w / $h));
					}

					if ($tw <= 0 || $th <= 0) {
						$err = $this->t('err_thumb_wh','Invalid thumbnail width/height.');
						$writtenThumbAbs[] = null;
						$thumbCols['_errors'][] = $err;
						continue;
					}

					$tfit   = (string)($tCfg['fit'] ?? 'crop');
					$tfmt   = $this->normalizeExt((string)($tCfg['format'] ?? $ext));
					$tqual  = (int)($tCfg['quality'] ?? 82);
					$tsuf   = (string)($tCfg['suffix'] ?? ('_' . $tw . 'x' . $th));
					$tcol   = (string)($tCfg['column'] ?? '');

					$thumbAbs = $dirAbs . DIRECTORY_SEPARATOR . $baseNoExtForThumbSeed . $tsuf . '.' . $tfmt;

					// Create from original (no cascading resample)
					$ti = ($tfit === 'stretch')
						? $this->imageResizeStretch($src, $tw, $th)
						: $this->imageResizeCropCenter($src, $tw, $th);
					if (!$ti || !$this->imageSave($ti, $thumbAbs, $tfmt, $tqual)) {
						if ($ti) { \imagedestroy($ti); }
						$thumbCols['_errors'][] = $this->t('err_thumb_write','Failed to write thumbnail.');
						$writtenThumbAbs[] = null;
						continue;
					}
					\imagedestroy($ti);
					$this->tryChmodPublic($thumbAbs);
					$writtenThumbAbs[] = $thumbAbs;

					$pub = $this->toPublicPath($thumbAbs);
					$thumbCols['_paths'][] = $pub;
					if ($tcol !== '') {
						$thumbCols[$tcol] = $pub;
					}
				}
			}

			// Cleanup + error handling for thumbs
			if (!empty($thumbCols['_errors'])) {
				
				// Delete fullsize and any thumbs we managed to write
				$this->deleteIfFile($targetPath);
				foreach ((array)($thumbCols['_paths'] ?? []) as $pub) {
					$this->deleteIfFile($this->fromPublicPath((string)$pub));
				}
				if ($src) { \imagedestroy($src); }
				$errs = (array)$thumbCols['_errors'];
				if ($orig !== '') { $errs = \array_map(fn($e) => (string)$e . ' (' . $orig . ')', $errs); }
				return ['status'=>false,'path'=>null,'thumbs'=>[],'error'=>$errs,'deleted'=>[]];
			}
			if ($src) { \imagedestroy($src); }
			unset($thumbCols['_errors'], $thumbCols['_paths']);

			// Delete old files after successful write
			if ($deleteOld && ($currentRow !== null || $currentPath !== null)) {
				$deleted = \array_merge($deleted, $this->deleteOldColumnFiles($currentRow, $uploadCfg, $currentPath, $columnName));
			}

			return [
				'status'	=> true,
				'path'		=> $this->toPublicPath($targetPath),
				'thumbs'	=> $thumbCols,
				'error'		=> [],
				'deleted'	=> $deleted
			];
		}

		// NON-IMAGES: Simple move (no thumbs)
		$targetPath = $absDir . $base . '.' . $ext; // Suffix only applies to fullsize images
		if (!$overwrite) {
			$targetPath = $this->resolveUniqueTargetPath($targetPath);
		}
		if (!$this->moveUploadedFile($f['tmp_name'], $targetPath, $overwrite, false)) {
			$msg = $this->t('err_write_failed', 'Failed to write file.');
			if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$msg],
				'deleted'	=> []
			];
		}
		$this->tryChmodPublic($targetPath);

		if ($deleteOld && ($currentRow !== null || $currentPath !== null)) {
			$deleted = \array_merge($deleted, $this->deleteOldColumnFiles($currentRow, $uploadCfg, $currentPath, $columnName));
		}

		return [
			'status'	=> true,
			'path'		=> $this->toPublicPath($targetPath),
			'thumbs'	=> [],
			'error'		=> [],
			'deleted'	=> $deleted];
	}


	/**
	 * Add multiple attached images (gallery-like). Fullsize + thumbs are created from the original (single decode).
																											
	 *
	 * Config (root of $attachedCfg):
	 * - inputName: string (required)   Name in $_FILES (multiple allowed)
	 * - dir: string (required)         Public uploads subdir (e.g. "/news/")
	 * - accept: string[]               Allowed MIME types (e.g. ["image/webp","image/png","image/jpeg"])
	 * - maxBytes: int                  Hard cap in bytes (clamped by PHP ini)
	 * - maxMegapixel: int              Max megapixels (W*H / 1_000_000)
	 * - maxImageRamBytes: int          Approx. RAM budget when decoding (W*H*5)
	 * - maxCount: int                  Max number of attachments (0 = unlimited)
	 * - rename: array{pattern?:string,rand?:bool,max?:int}
	 * - encoding: array{format?:string,quality?:int}  Default "webp", 82
	 * - exifOrient: bool               If true, apply EXIF orientation for JPEG inputs
	 *
	 * Fullsize controls at root (optional):
	 * - w: int, h: int                 If both > 0, always resample fullsize
	 * - fit: "crop"|"stretch"          Default "crop"
	 * - suffix: string                 Added before extension for fullsize (e.g. "_1280x720")
	 *
	 * Thumbnails (as before) in $attachedCfg['thumbnails'][]:
	 * - w:int, h:int, fit?:string, suffix?:string, format?:string, quality?:int
	 * - column?:string                 If set, returned thumbs[$column] = public path
	 *
	 * Returns:
	 * - ['status'=>bool, 'files'=>array{...}, 'error'=>array]
	 *   Each entry in files[]:
	 *     ['ok'=>true, 'path'=>string, 'thumbs'=>array<string,string>]
	 *     Or on failure: ['ok'=>false, 'error'=>string]
	 *
	 * @param array $attachedCfg
	 * @param array $payload
	 * @param int   $fkId
	 * @param int   $currentCount
	 * @return array{status:bool,files:array,error:array}
	 */
	public function addAttachedImages(array $attachedCfg, array $payload, int $fkId, int $currentCount = 0): array {
		$out = ['status' => false, 'files' => [], 'error' => []];

		$inputRaw = (string)($attachedCfg['inputName'] ?? '');
		$input    = (string)\preg_replace('~\[\]$~', '', $inputRaw);		
		if ($input === '' || !isset($_FILES[$input])) {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_no_file', 'No file provided.')]];
		}

		$dir = $this->normalizeDir((string)($attachedCfg['dir'] ?? ''));
		if ($dir === '') {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_cfg_dir_missing', 'Upload directory is missing in configuration.')]];
		}
		$absDir = $this->absUploadsDir($dir);
		$this->ensureDir($absDir);

		$files = $this->normalizeMultiFiles($_FILES[$input]);
		if ($files === []) {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_no_file', 'No file provided.')]];
		}

		$accept   = (array)($attachedCfg['accept'] ?? []);
		$maxBytes = $this->clampMaxBytesByIni((int)($attachedCfg['maxBytes'] ?? 0));
		$maxMP    = (int)($attachedCfg['maxMegapixel'] ?? 0);
		$ramCap   = (int)($attachedCfg['maxImageRamBytes'] ?? ($this->options['maxImageRamBytes'] ?? (128 * 1024 * 1024)));

		$rename = (array)($attachedCfg['rename'] ?? []);
		$enc    = (array)($attachedCfg['encoding'] ?? []);
		$thumbsCfg = (array)($attachedCfg['thumbnails'] ?? []);

		$targetFormat = (string)($enc['format'] ?? 'webp');
		$quality      = (int)($enc['quality'] ?? 82);
		$ext          = $this->normalizeExt($targetFormat);

		// Fullsize controls (optional). If both > 0, we ALWAYS resample.
		$fullW      = (int)($attachedCfg['w'] ?? 0);
		$fullH      = (int)($attachedCfg['h'] ?? 0);
		$fullFit    = (string)($attachedCfg['fit'] ?? 'crop');      // 'crop' | 'stretch'
		$fullSuffix = (string)($attachedCfg['suffix'] ?? '');       // added before extension
		$exifOn     = (bool)($attachedCfg['exifOrient'] ?? false);

		$maxCount   = (int)($attachedCfg['maxCount'] ?? 0);
		$remaining  = $maxCount > 0 ? \max(0, $maxCount - \max(0, $currentCount)) : 0;
		$accepted   = 0;

		foreach ($files as $f) {
   
			$orig = $this->safeOrigName($f['name'] ?? '');

			// Enforce max-count (if any)
			if ($maxCount > 0 && $accepted >= $remaining) {
				$out['files'][] = ['ok' => false, 'error' => $this->t('err_max_count_reached', 'Maximum number of attachments reached.')];
				continue;
			}

			// Low-level upload issues
			if ($f['error'] !== \UPLOAD_ERR_OK) {
				$msg = $this->errorFromUploadCode($f['error']);
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if ($f['size'] <= 0) {
				$msg = $this->t('err_empty_file', 'File is empty.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if ($maxBytes > 0 && $f['size'] > $maxBytes) {
				$msg = $this->t('err_too_large_bytes', 'File is too large.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
											 

			$mime = $this->detectMime($f['tmp_name']);
			
			// Only enforce allowlist when provided
			if ($accept !== [] && !\in_array($mime, $accept, true)) {
				$msg = $this->t('err_invalid_mime', 'File type not allowed.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if (!$this->isImageMime($mime)) {
				$msg = $this->t('err_invalid_mime', 'File type not allowed.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			[$w, $h] = $this->readImageDimensions($f['tmp_name']);
			if ($w <= 0 || $h <= 0) {
				$msg = $this->t('err_invalid_image', 'Invalid image.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if ($maxMP > 0) {
				$mp = (int)\ceil(($w * $h) / 1_000_000);
				if ($mp > $maxMP) {
					$msg = $this->t('err_too_large_megapixel', 'Image exceeds megapixel limit.');
					if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
					$out['files'][] = ['ok' => false, 'error' => $msg];
					continue;
				}
			}
			$estimated = (int)($w * $h * 5);
			if ($ramCap > 0 && $estimated > $ramCap) {
				$msg = $this->t('err_image_memory', 'Image is too large to process safely.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			// Build deterministic base name
			$base = $this->buildBasenameFromPattern(
				(string)($rename['pattern'] ?? ''),
				$payload,
				(string)$f['name'],
				(bool)($rename['rand'] ?? true),
				(int)($rename['max'] ?? 80)
			);

			// Compose fullsize target (suffix before extension), and ensure unique path
			$fullBaseName = $base . ($fullSuffix !== '' ? $fullSuffix : '');
			$fullAbsPath  = $this->resolveUniqueTargetPath($absDir . $fullBaseName . '.' . $ext);

			// Decode original once
			$src = $this->imageLoad($f['tmp_name']);
			if (!$src) {
				$msg = $this->t('err_invalid_image', 'Invalid image.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			// Optional EXIF orientation for JPEG source
			if ($exifOn && $mime === 'image/jpeg' && \function_exists('exif_read_data')) {
				$oriented = $this->applyExifOrientation($src, $f['tmp_name']);
				if ($oriented) { $src = $oriented; }
			}

			// ---- Fullsize (from original) ----
			$fullOk = false;
			
			// Derive missing dimension if exactly one is set.
			$targetW = $fullW;
			$targetH = $fullH;
			if ($fullW > 0 && $fullH <= 0) {
				$targetW = $fullW;
				$targetH = (int)\round($fullW * ($h / $w));
			} elseif ($fullH > 0 && $fullW <= 0) {
				$targetH = $fullH;
				$targetW = (int)\round($fullH * ($w / $h));
			}
			
			if ($targetW > 0 && $targetH > 0) {
				$dst = ($fullFit === 'stretch')
					? $this->imageResizeStretch($src, $targetW, $targetH)
					: $this->imageResizeCropCenter($src, $targetW, $targetH);
				if ($dst) {
					$fullOk = $this->imageSave($dst, $fullAbsPath, $ext, $quality);
					\imagedestroy($dst);
				}
			} else {
				$fullOk = $this->imageSave($src, $fullAbsPath, $ext, $quality);
			}

			if (!$fullOk) {
				if ($src) { \imagedestroy($src); }
				$msg = $this->t('err_write_failed', 'Failed to write file.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			$this->tryChmodPublic($fullAbsPath);

			// ---- Thumbnails (also from original) ----
			$thumbCols = [];
			$writtenThumbAbs = [];
			if ($thumbsCfg !== []) {
				$piFull = \pathinfo($fullAbsPath);
				$dirAbs = (string)($piFull['dirname'] ?? $absDir);
				$baseNoExtForThumbSeed = $base; // Each thumb has its own suffix

				foreach ($thumbsCfg as $tCfg) {
					$tw = (int)($tCfg['w'] ?? 0);
					$th = (int)($tCfg['h'] ?? 0);
					
					// Derive missing dimension if exactly one is set.
					if ($tw > 0 && $th <= 0) {
						$th = (int)\round($tw * ($h / $w));
					} elseif ($th > 0 && $tw <= 0) {
						$tw = (int)\round($th * ($w / $h));
					}

					if ($tw <= 0 || $th <= 0) {
						$thumbCols['_errors'][] = $this->t('err_thumb_wh', 'Invalid thumbnail width/height.');
						continue;
					}

					$tfit  = (string)($tCfg['fit'] ?? 'crop');
					$tfmt  = $this->normalizeExt((string)($tCfg['format'] ?? $ext));
					$tqual = (int)($tCfg['quality'] ?? 82);
					$tsuf  = (string)($tCfg['suffix'] ?? ('_' . $tw . 'x' . $th));
					$tcol  = (string)($tCfg['column'] ?? '');

					// Unique per thumb to avoid collisions on repeated uploads
					$thumbAbs = $this->resolveUniqueTargetPath($dirAbs . DIRECTORY_SEPARATOR . $baseNoExtForThumbSeed . $tsuf . '.' . $tfmt);

					$ti = ($tfit === 'stretch')
						? $this->imageResizeStretch($src, $tw, $th)
						: $this->imageResizeCropCenter($src, $tw, $th);
					if (!$ti || !$this->imageSave($ti, $thumbAbs, $tfmt, $tqual)) {
						if ($ti) { \imagedestroy($ti); }
						$thumbCols['_errors'][] = $this->t('err_thumb_write', 'Failed to write thumbnail.');
						continue;
					}
					\imagedestroy($ti);
					$this->tryChmodPublic($thumbAbs);
					$writtenThumbAbs[] = $thumbAbs;

					$pub = $this->toPublicPath($thumbAbs);
					$thumbCols['_paths'][] = $pub;
					if ($tcol !== '') {
						$thumbCols[$tcol] = $pub;
					}
				}
													   
			}

			// Atomic cleanup on any thumb error
			if (!empty($thumbCols['_errors'])) {
				$this->deleteIfFile($fullAbsPath);
				foreach ((array)($thumbCols['_paths'] ?? []) as $pub) {
					$this->deleteIfFile($this->fromPublicPath((string)$pub));
				}
				if ($src) { \imagedestroy($src); }
				$msg = \implode(' ', (array)$thumbCols['_errors']);
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			if ($src) { \imagedestroy($src); }
			unset($thumbCols['_errors'], $thumbCols['_paths']);

			$accepted++;
			$out['files'][] = [
				'ok'    => true,
				'path'  => $this->toPublicPath($fullAbsPath),
				'thumbs'=> $thumbCols
			];
		}

		$out['status'] = true;
		return $out;
	}


	/**
	 * Add attached non-image files (multi). No re-encode, no thumbnails.
	 *
	 * @param array<string,mixed> $attachedCfg Expect keys: inputName, dir, accept, maxBytes, rename, maxCount?
	 * @param array<string,mixed> $payload Effective normalized payload (for rename tokens).
	 * @param int $fkId Foreign key id for context (not used directly by writer).
	 * @param int $currentCount Current number of attachments already persisted (for maxCount enforcement).
	 * @return array{status:bool, files:array<int,array{ok:bool,path?:string,error?:string}>, error:array<int,string>}
	 */
	public function addAttachedFiles(array $attachedCfg, array $payload, int $fkId, int $currentCount = 0): array {
		$out = ['status' => false, 'files' => [], 'error' => []];

		$inputRaw = (string)($attachedCfg['inputName'] ?? '');
		$input    = (string)\preg_replace('~\[\]$~', '', $inputRaw);
		if ($input === '' || !isset($_FILES[$input])) {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_no_file', 'No file provided.')]];
		}
		$dir = $this->normalizeDir((string)($attachedCfg['dir'] ?? ''));
		if ($dir === '') {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_cfg_dir_missing', 'Upload directory is missing in configuration.')]];
		}
		$absDir = $this->absUploadsDir($dir);
		$this->ensureDir($absDir);

		$files = $this->normalizeMultiFiles($_FILES[$input]);
		if ($files === []) {
			return ['status' => false, 'files' => [], 'error' => [$this->t('err_no_file', 'No file provided.')]];
		}

		$accept = (array)($attachedCfg['accept'] ?? []);
		$maxBytes = $this->clampMaxBytesByIni((int)($attachedCfg['maxBytes'] ?? 0));
		$rename = (array)($attachedCfg['rename'] ?? []);

		// Enforce maxCount using currentCount
		$maxCount = (int)($attachedCfg['maxCount'] ?? 0);
		$remaining = $maxCount > 0 ? \max(0, $maxCount - \max(0, $currentCount)) : 0;
		$accepted = 0;

		foreach ($files as $f) {
			
			$orig = $this->safeOrigName($f['name'] ?? '');
			
			if ($maxCount > 0 && $accepted >= $remaining) {
				$out['files'][] = ['ok' => false, 'error' => $this->t('err_max_count_reached', 'Maximum number of attachments reached.')];
				continue;
			}
			if ($f['error'] !== \UPLOAD_ERR_OK) {
				$msg = $this->errorFromUploadCode($f['error']);
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if ($f['size'] <= 0) {
				$msg = $this->t('err_empty_file', 'File is empty.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			if ($maxBytes > 0 && $f['size'] > $maxBytes) {
				$msg = $this->t('err_too_large_bytes', 'File is too large.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			$mime = $this->detectMime($f['tmp_name']);
			if ($accept !== [] && !\in_array($mime, $accept, true)) {
				$msg = $this->t('err_invalid_mime', 'File type not allowed.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			$base = $this->buildBasenameFromPattern(
				(string)($rename['pattern'] ?? ''),
				$payload,
				(string)$f['name'],
				(bool)($rename['rand'] ?? true),
				(int)($rename['max'] ?? 80)
			);

			$ext = $this->normalizeExt($this->extFromMime($mime));
			if ($ext === 'bin') {
				$origExt = \strtolower((string)\pathinfo($f['name'], \PATHINFO_EXTENSION));
				if ($origExt !== '' && ($accept === [] || \in_array($mime, $accept, true))) {
					$extCandidate = $this->normalizeExt($origExt);
					if ($extCandidate !== 'bin') {
						$ext = $extCandidate;
					}
				}
			}

			$targetPath = $absDir . $base . '.' . $ext;

			$targetPath = $this->resolveUniqueTargetPath($targetPath);

			// Keep deterministic auto-suffix for attachments
			if (!$this->moveUploadedFile($f['tmp_name'], $targetPath, false, true)) {
				$msg = $this->t('err_write_failed', 'Failed to write file.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			$this->tryChmodPublic($targetPath);

			$accepted++;

			$out['files'][] = ['ok' => true, 'path' => $this->toPublicPath($targetPath)];
			
		}

		$out['status'] = true;
		return $out;
	}





	/* =========================
	 * Utilities (public, generic)
	 * ========================= */

	/**
	 * Sanitize a string to a filesystem-safe, lowercased slug with ASCII and dashes.
	 *
	 * @param string $s Input string.
	 * @param int $max Maximum length for basename (without extension). 0 means no cap.
	 * @return string Sanitized basename (no extension).
	 */
	public function sanitizeForFilename(string $s, int $max = 80): string {
		$s = \str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $s);
		$map = [
			'Æ'=>'AE','Ø'=>'OE','Å'=>'AA','Ä'=>'A','Ö'=>'O','Ü'=>'U','ß'=>'ss',
			'æ'=>'ae','ø'=>'oe','å'=>'aa','ä'=>'a','ö'=>'o','ü'=>'u',
			'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
			'Á'=>'A','À'=>'A','Â'=>'A','á'=>'a','à'=>'a','â'=>'a',
			'Í'=>'I','Ì'=>'I','Î'=>'I','í'=>'i','ì'=>'i','î'=>'i',
			'Ó'=>'O','Ò'=>'O','Ô'=>'O','ó'=>'o','ò'=>'o','ô'=>'o',
			'Ú'=>'U','Ù'=>'U','Û'=>'U','ú'=>'u','ù'=>'u','û'=>'u',
			'Ç'=>'C','ç'=>'c','Ñ'=>'N','ñ'=>'n'
		];
		$s = \strtr($s, $map);
		$s = \mb_strtolower($s);
		$s = (string)\preg_replace('~[^a-z0-9]+~', '-', $s);
		$s = (string)\preg_replace('~-+~', '-', $s);
		$s = \trim($s, '-');
		if ($max > 0 && \mb_strlen($s) > $max) {
			$s = \mb_substr($s, 0, $max);
			$s = \rtrim($s, '-');
		}
		return $s;
	}


	/**
	 * Make a user-visible version of the original client filename.
	 * Strips directories, control chars and trims overlong names.
	 *
	 * @param string|null $name Original client-provided filename.
	 * @return string Safe, short basename (may be empty).
	 */
	private function safeOrigName(?string $name): string {
		$b = \basename((string)$name);
		$b = (string)\preg_replace('~[\r\n\t]+~', ' ', $b);
		$b = \trim($b);
		if ($b === '') {
			return '';
		}
		if (\mb_strlen($b) > 120) {
			$b = \mb_substr($b, 0, 120) . '…';
		}
		return $b;
	}


	/**
	 * Build basename from a pattern and current payload (without extension).
	 *
	 * @param string $pattern Pattern like 'news-{col:slug}-{col:meta_title}'.
	 * @param array<string,mixed> $payload Normalized POST data used for tokens.
	 * @param string $originalName Original filename for fallback.
	 * @param bool $addRand Whether to append "-<hex(ts)><rand4>".
	 * @param int $maxLen Max length for basename (after suffix). 0 means no cap.
	 * @return string Basename without extension.
	 */
	public function buildBasenameFromPattern(string $pattern, array $payload, string $originalName, bool $addRand, int $maxLen): string {
		$base = '';
		if ($pattern !== '') {
			$base = (string)\preg_replace_callback('~\{col:([A-Za-z0-9_]+)\}~', static function(array $m) use ($payload): string {
				$key = (string)$m[1];
				$val = $payload[$key] ?? '';
				return \is_scalar($val) ? (string)$val : '';
			}, $pattern);
			$base = $this->sanitizeForFilename($base, 0);
		}
		if ($base === '') {
			$fn = $originalName !== '' ? (string)\pathinfo($originalName, \PATHINFO_FILENAME) : 'file';
			$base = $this->sanitizeForFilename($fn, 0);
			if ($base === '') {
				$base = 'file';
			}
		}

		$suffix = '';
		if ($addRand) {
			$hexTs = \dechex(\time());
			
			try {
				$rand4 = \substr(\bin2hex(\random_bytes(2)), 0, 4);
			} catch (\Throwable) {
				$rand4 = \substr(\bin2hex((string)\mt_rand()), 0, 4);
			}
			
			$suffix = '-' . $hexTs . $rand4;
		}
		$out = $base . $suffix;

		if ($maxLen > 0 && \mb_strlen($out) > $maxLen) {
			if ($addRand && $suffix !== '') {
				$allow = $maxLen - \mb_strlen($suffix);
				if ($allow < 1) { $allow = 1; }
				$baseTrimmed = \mb_substr($base, 0, $allow);
				$out = $baseTrimmed . $suffix;
			} else {
				$out = \mb_substr($out, 0, $maxLen);
			}
		}
		$out = (string)\preg_replace('~-+~', '-', $out);
		$out = \trim($out, '-');
		if ($out === '') {
			$out = 'file' . ($addRand ? $suffix : '');
		}
		return $out;
	}

	/* =========================
	 * Internal helpers
	 * ========================= */

	/** @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
	private function normalizeOneFile(array $f): ?array {

		// Simple shape guard; assume it's a single file input (not multiple).
		if (!isset($f['name']) || !isset($f['tmp_name'])) {
			return null;
		}
		return [
			'name' => (string)$f['name'],
			'type' => (string)($f['type'] ?? ''),
			'tmp_name' => (string)$f['tmp_name'],
			'error' => (int)($f['error'] ?? \UPLOAD_ERR_NO_FILE),
			'size' => (int)($f['size'] ?? 0),
		];
	}

	/**
	 * Normalize a multi-file field to a flat list of single-file arrays.
	 *
	 * @param array $f The $_FILES[$name] entry for a multiple input.
	 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	private function normalizeMultiFiles(array $f): array {

		$out = [];
		if (!isset($f['name']) || !\is_array($f['name'])) {

			// Single file sent to a 'multiple' field: normalize with one slot.
			$one = $this->normalizeOneFile($f);
			return $one ? [$one] : [];
		}
		$names = $f['name'];
		$types = $f['type'] ?? [];
		$tmpes = $f['tmp_name'] ?? [];
		$errs  = $f['error'] ?? [];
		$sizes = $f['size'] ?? [];
		$count = \count($names);
		for ($i = 0; $i < $count; $i++) {
			$out[] = [
				'name' => (string)($names[$i] ?? ''),
				'type' => (string)($types[$i] ?? ''),
				'tmp_name' => (string)($tmpes[$i] ?? ''),
				'error' => (int)($errs[$i] ?? \UPLOAD_ERR_NO_FILE),
				'size' => (int)($sizes[$i] ?? 0),
			];
		}
		return $out;
	}

	private function absUploadsDir(string $dir): string {
		$base = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($base === '') {
			throw new \RuntimeException('CITOMNI_PUBLIC_PATH is not defined.');
		}
		return \rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'u' . $dir;
	}

	private function normalizeDir(string $dir): string {
		$dir = \trim(\str_replace('\\', '/', $dir));
		if ($dir === '' || \str_contains($dir, '..')) { return ''; }
		if ($dir[0] !== '/') { $dir = '/' . $dir; }
		if (\substr($dir, -1) !== '/') { $dir .= '/'; }
		return $dir;
	}

	private function ensureDir(string $absDir): void {
		if (!\is_dir($absDir)) {
			if (!\mkdir($absDir, 0755, true) && !\is_dir($absDir)) {
				throw new \RuntimeException('Failed to create upload directory: ' . $absDir);
			}
		}
	}

	private function detectMime(string $tmpPath): string {
		if ($this->finfo) {
			$mime = @\finfo_file($this->finfo, $tmpPath);
			if (\is_string($mime) && $mime !== '') {
				return $mime;
			}
		}
		$f = @\finfo_open(\FILEINFO_MIME_TYPE);
		$mime = $f ? (@\finfo_file($f, $tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';
		if ($f) { @\finfo_close($f); }
		return (string)$mime;
	}

	private function isImageMime(string $mime): bool {
		return \in_array($mime, ['image/webp', 'image/png', 'image/jpeg'], true);
	}

	/** @return array{0:int,1:int} [w,h] */
	private function readImageDimensions(string $tmpPath): array {
		$info = @\getimagesize($tmpPath);
		if ($info === false) {
			return [0, 0];
		}
		return [(int)($info[0] ?? 0), (int)($info[1] ?? 0)];
	}


	/** Resolve a unique target path by suffixing on collision (hex(ts)+rand4). */
	private function resolveUniqueTargetPath(string $desiredAbsPath): string {
		if (!\is_file($desiredAbsPath)) {
			return $desiredAbsPath;
		}
		$pi = \pathinfo($desiredAbsPath);
		$dirAbs = (string)($pi['dirname'] ?? '');
		$baseNoExt = (string)($pi['filename'] ?? 'file');
		$ext = isset($pi['extension']) ? ('.' . $pi['extension']) : '';
		do {
			$suffix = '-' . \dechex(\time()) . \substr(\bin2hex(\random_bytes(2)), 0, 4);
			$candidate = $dirAbs . DIRECTORY_SEPARATOR . $baseNoExt . $suffix . $ext;
		} while (\is_file($candidate));
		return $candidate;
	}


	private function moveUploadedFile(string $tmpPath, string $targetPath, bool $overwrite, bool $allowAutoSuffix): bool {
		if (\is_file($targetPath) && !$overwrite) {
			if (!$allowAutoSuffix) {
				// Strict: Do not write when target exists
				return false;
			}
			// Deterministic suffix for attachments
			$pi = \pathinfo($targetPath);
			$base = (string)($pi['dirname'] . DIRECTORY_SEPARATOR . ($pi['filename'] ?? 'file'));
			$ext  = isset($pi['extension']) ? ('.' . $pi['extension']) : '';
			$targetPath = $base . '-' . \dechex(\time()) . \substr(\bin2hex(\random_bytes(2)), 0, 4) . $ext;
		}
		if (\function_exists('is_uploaded_file') && @\is_uploaded_file($tmpPath)) {
			return @\move_uploaded_file($tmpPath, $targetPath);
		}
		return @\rename($tmpPath, $targetPath);
	}

	private function extFromMime(string $mime): string {
		return match ($mime) {
			// images
			'image/webp' => 'webp',
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/svg+xml' => 'svg',

			// docs (OOXML)
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => 'xlsx',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

			// ODF
			'application/vnd.oasis.opendocument.text'        => 'odt',
			'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
			'application/vnd.oasis.opendocument.presentation'=> 'odp',

			// ebooks/archives
			'application/epub+zip'          => 'epub',
			'application/x-rar-compressed'  => 'rar',
			'application/zip'               => 'zip',

			// text/csv/pdf
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
			'text/csv'        => 'csv',

			default => 'bin',
		};
	}


	private function normalizeExt(string $format): string {
		$format = \strtolower(\trim($format));
		return match ($format) {
			// images
			'webp' => 'webp',
			'jpg', 'jpeg' => 'jpg',
			'png' => 'png',

			// docs (OOXML)
			'docx' => 'docx',
			'xlsx' => 'xlsx',
			'pptx' => 'pptx',

			// ODF
			'odt' => 'odt',
			'ods' => 'ods',
			'odp' => 'odp',

			// ebooks/archives
			'epub' => 'epub',
			'rar'  => 'rar',
			'zip'  => 'zip',

			// text/pdf/csv
			'pdf' => 'pdf',
			'txt' => 'txt',
			'csv' => 'csv',

			// vector (no thumbs)
			'svg' => 'svg',

			default => 'bin',
		};
	}


	private function sameFamily(string $mime, string $ext): bool {
		// Used to decide if re-encode is needed even if encoding is not requested.
		return ($mime === 'image/jpeg' && $ext === 'jpg')
			|| ($mime === 'image/png' && $ext === 'png')
			|| ($mime === 'image/webp' && $ext === 'webp');
	}

	private function clampMaxBytesByIni(int $cfgMax): int {
		$toBytes = static function(string $val): int {
			$val = \trim($val);
			if ($val === '') { return 0; }
			$unit = \strtolower(\substr($val, -1));
			$num  = (float)$val;
			return match ($unit) {
				'g' => (int)($num * 1024 * 1024 * 1024),
				'm' => (int)($num * 1024 * 1024),
				'k' => (int)($num * 1024),
				default => (int)$num
			};
		};
		$iniUpload = $toBytes((string)\ini_get('upload_max_filesize'));
		$iniPost   = $toBytes((string)\ini_get('post_max_size'));
		$hardCap   = ($iniUpload > 0 && $iniPost > 0) ? \min($iniUpload, $iniPost) : \max($iniUpload, $iniPost);
		if ($hardCap > 0) {
			if ($cfgMax <= 0) { return $hardCap; }
			return \min($cfgMax, $hardCap);
		}
		return \max(0, $cfgMax);
	}


	/**
	 * Create thumbnails for a given **written** image path based on cfg.
	 *
	 * @param string $srcPath Absolute path to already written image.
	 * @param array<int,array<string,mixed>> $thumbsCfg Array of thumbnail configs:
	 *        - w:int, h:int (required, >0)
	 *        - fit:'crop'|'stretch' (default 'crop')
	 *        - suffix:string (default '_{w}x{h}')
	 *        - column:string (optional mapping: column => publicPath)
	 *        - format:string ('webp'|'jpg'|'png'), default = src ext
	 *        - quality:int (0..100), default 82
	 * @return array<string,string> Map of column => public path; includes special keys '_errors' and '_paths'.
	 */
	private function makeThumbnails(string $srcPath, array $thumbsCfg): array {
		$out = ['_errors' => [], '_paths' => []];

		// Decode source once (performance).
		$srcImg = $this->imageLoad($srcPath);
		if (!$srcImg) {
			$out['_errors'][] = $this->t('err_thumb_load', 'Failed to load image for thumbnail.');
			return $out;
		}

		$pi = \pathinfo($srcPath);
		$dirAbs = (string)($pi['dirname'] ?? '');
		$baseNoExt = (string)($pi['filename'] ?? 'file');
		$srcExt = (string)($pi['extension'] ?? 'webp');

		foreach ($thumbsCfg as $tCfg) {
			$w = (int)($tCfg['w'] ?? 0);
			$h = (int)($tCfg['h'] ?? 0);
			if ($w <= 0 || $h <= 0) {
				$out['_errors'][] = $this->t('err_thumb_wh', 'Invalid thumbnail width/height.');
				continue;
			}

			$fit = (string)($tCfg['fit'] ?? 'crop');
			$fmt = $this->normalizeExt((string)($tCfg['format'] ?? $srcExt));
			$q   = (int)($tCfg['quality'] ?? 82);

			// Default deterministic suffix mirrors delete routine: _{w}x{h}
			$suffix = (string)($tCfg['suffix'] ?? ('_' . $w . 'x' . $h));

			// Resize from the already-decoded source (no extra decodes).
			$thumb = ($fit === 'stretch')
				? $this->imageResizeStretch($srcImg, $w, $h)
				: $this->imageResizeCropCenter($srcImg, $w, $h);

			if (!$thumb) {
				$out['_errors'][] = $this->t('err_thumb_process', 'Failed to process thumbnail.');
				continue;
			}

			$dstAbs = $dirAbs . DIRECTORY_SEPARATOR . $baseNoExt . $suffix . '.' . $fmt;
			$ok = $this->imageSave($thumb, $dstAbs, $fmt, $q);
			\imagedestroy($thumb);

			if (!$ok) {
				$out['_errors'][] = $this->t('err_thumb_write', 'Failed to write thumbnail.');
				continue;
			}

			$this->tryChmodPublic($dstAbs);

			$pub = $this->toPublicPath($dstAbs);
			$out['_paths'][] = $pub;

			// Optional column mapping -> caller can persist directly
			$col = (string)($tCfg['column'] ?? '');
			if ($col !== '') {
				$out[$col] = $pub;
			}
		}

		\imagedestroy($srcImg);
		return $out;
	}


	/**
	 * Delete previous fullsize + thumbnails for a column upload.
	 *
	 * Strategy:
	 * 1) Delete fullsize by reading current column or explicit path.
	 * 2) For each configured thumbnail:
	 *    - If 'column' is set and $currentRow has a non-empty value, delete that file directly.
	 *    - Else, attempt deterministic deletion using base+suffix(+format) derived from the old fullsize path.
	 * 3) Deduplicate and robustly ignore missing files.
	 *
	 * @param array|null  $currentRow
	 * @param array       $uploadCfg
	 * @param string|null $explicitPublicPath
	 * @param string|null $columnName
	 * @return array<string> Absolute paths deleted
	 */
	private function deleteOldColumnFiles(?array $currentRow = null, array $uploadCfg = [], ?string $explicitPublicPath = null, ?string $columnName = null): array {
		$deleted = [];
		$seen = [];

		// Resolve old fullsize public path (from explicit, column, or fallback finder)
		$origCol = (string)($explicitPublicPath ?? '');
		if ($origCol === '' && $currentRow !== null) {
			if ($columnName !== null && isset($currentRow[$columnName]) && \is_string($currentRow[$columnName])) {
				$origCol = (string)$currentRow[$columnName];
			} else {
				$origCol = $this->findCurrentColumnPath($currentRow, $uploadCfg);
			}
		}

		// Delete fullsize
		if ($origCol !== '') {
			$abs = $this->fromPublicPath($origCol);
			if ($abs !== '' && \is_file($abs)) {
				if (@\unlink($abs)) { $deleted[] = $abs; $seen[$abs] = true; }
			}
		}

		$thumbsCfg = (array)($uploadCfg['thumbnails'] ?? []);
		if ($thumbsCfg === []) {
			return $deleted;
		}

		// Primary: column-based deletion for thumbs
		if ($currentRow !== null) {
			foreach ($thumbsCfg as $tCfg) {
				$col = (string)($tCfg['column'] ?? '');
				if ($col !== '' && isset($currentRow[$col]) && \is_string($currentRow[$col]) && $currentRow[$col] !== '') {
					$abs = $this->fromPublicPath((string)$currentRow[$col]);
					if ($abs !== '' && \is_file($abs) && !isset($seen[$abs])) {
						if (@\unlink($abs)) { $deleted[] = $abs; $seen[$abs] = true; }
					}
				}
			}
		}

		// Fallback: suffix-based deterministic deletion if no column value exists
		if ($origCol !== '') {
			$piSrc   = \pathinfo($this->fromPublicPath($origCol));
			$dirAbs  = (string)($piSrc['dirname'] ?? '');
			$base    = (string)($piSrc['filename'] ?? '');
			$srcExt  = (string)($piSrc['extension'] ?? 'webp');

			foreach ($thumbsCfg as $tCfg) {
				$col = (string)($tCfg['column'] ?? '');
				$colHasValue = ($currentRow !== null && $col !== '' && !empty($currentRow[$col]));
				if ($colHasValue) { continue; }

				$w = (int)($tCfg['w'] ?? 0);
				$h = (int)($tCfg['h'] ?? 0);
				$suffix = (string)($tCfg['suffix'] ?? ($w > 0 && $h > 0 ? ('_' . $w . 'x' . $h) : ''));
				if ($suffix === '') { continue; }

				$fmt = $this->normalizeExt((string)($tCfg['format'] ?? $srcExt));
				$dstAbs = $dirAbs . DIRECTORY_SEPARATOR . $base . $suffix . '.' . $fmt;

				if (\is_file($dstAbs) && !isset($seen[$dstAbs])) {
					if (@\unlink($dstAbs)) { $deleted[] = $dstAbs; $seen[$dstAbs] = true; }
				}
			}
		}

		return $deleted;
	}


	/** Best-effort: Find current public path for original column file. */
	private function findCurrentColumnPath(array $currentRow, array $uploadCfg): string {
		// Deterministic heuristics: Typical names first
		foreach (['cover_path', 'file_path', 'path'] as $k) {
			if (isset($currentRow[$k]) && \is_string($currentRow[$k]) && $currentRow[$k] !== '') {
				return (string)$currentRow[$k];
			}
		}
		// Fallback: none
		return '';
	}

	private function errorFromUploadCode(int $code): string {
		return match ($code) {
			\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $this->t('err_too_large_bytes', 'File is too large.'),
			\UPLOAD_ERR_PARTIAL => $this->t('err_partial', 'File was only partially uploaded.'),
			\UPLOAD_ERR_NO_FILE => $this->t('err_no_file', 'No file provided.'),
			\UPLOAD_ERR_NO_TMP_DIR => $this->t('err_no_tmp', 'Missing a temporary folder.'),
			\UPLOAD_ERR_CANT_WRITE => $this->t('err_cant_write', 'Failed to write file to disk.'),
			\UPLOAD_ERR_EXTENSION => $this->t('err_blocked_ext', 'File upload stopped by extension.'),
			default => $this->t('err_upload_failed', 'File upload failed.')
		};
	}

	private function t(string $key, string $fallback): string {
		return $this->app->txt->get($key, 'upload', 'citomni/http', $fallback);
	}

	/* =========================
	 * GD helpers
	 * ========================= */

	private function imageLoad(string $path): \GdImage|false {
		$info = @\getimagesize($path);
		if ($info === false) { return false; }
		$mime = (string)($info['mime'] ?? '');
		return match ($mime) {
			'image/webp' => @\imagecreatefromwebp($path),
			'image/png'  => @\imagecreatefrompng($path),
			'image/jpeg' => @\imagecreatefromjpeg($path),
			default => false
		};
	}

	private function imageResizeStretch(\GdImage $src, int $w, int $h): \GdImage|false {
		$dst = \imagecreatetruecolor($w, $h);
		if (!$dst) { return false; }
		\imagealphablending($dst, false);
		\imagesavealpha($dst, true);
		$sw = \imagesx($src);
		$sh = \imagesy($src);
		if (!\imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh)) {
			\imagedestroy($dst);
			return false;
		}
		return $dst;
	}

	private function imageResizeCropCenter(\GdImage $src, int $w, int $h): \GdImage|false {
		$sw = \imagesx($src);
		$sh = \imagesy($src);
		if ($sw <= 0 || $sh <= 0) { return false; }

		$srcRatio = $sw / $sh;
		$dstRatio = $w / $h;

		if ($srcRatio > $dstRatio) {
			// Source wider than dest: crop width
			$newW = (int)\round($sh * $dstRatio);
			$newH = $sh;
			$srcX = (int)\round(($sw - $newW) / 2);
			$srcY = 0;
		} else {
			// Source taller than dest: Crop height
			$newW = $sw;
			$newH = (int)\round($sw / $dstRatio);
			$srcX = 0;
			$srcY = (int)\round(($sh - $newH) / 2);
		}

		$dst = \imagecreatetruecolor($w, $h);
		if (!$dst) { return false; }
		\imagealphablending($dst, false);
		\imagesavealpha($dst, true);

		if (!\imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $w, $h, $newW, $newH)) {
			\imagedestroy($dst);
			return false;
		}
		return $dst;
	}

	private function imageSave(\GdImage $img, string $path, string $fmt, int $q): bool {
		return match ($fmt) {
			'webp' => @\imagewebp($img, $path, \max(0, \min(100, $q))),
			'png'  => (function() use ($img, $path, $q): bool {
				$lvl = (int)\round((100 - \max(0, \min(100, $q))) / 11.111); // 0..9
				$lvl = \max(0, \min(9, $lvl));
				return @\imagepng($img, $path, $lvl);
			})(),
			'jpg'  => @\imagejpeg($img, $path, \max(0, \min(100, $q))),
			default => false
		};
	}

	private function reencodeImage(string $tmpPath, string $targetPath, string $fmt, int $q, bool $applyExif): bool {
		$img = $this->imageLoad($tmpPath);
		if (!$img) { return false; }

		if ($applyExif && \function_exists('exif_read_data')) {
			$img = $this->applyExifOrientation($img, $tmpPath) ?: $img;
		}
		$ok = $this->imageSave($img, $targetPath, $fmt, $q);
		\imagedestroy($img);
		return $ok;
	}

	/** @return \GdImage|null */
	private function applyExifOrientation(\GdImage $img, string $path): ?\GdImage {
		try {
			$exif = @\exif_read_data($path);
			$ori = (int)($exif['Orientation'] ?? 1);
			return match ($ori) {
				3 => $this->rotateGd($img, 180),
				6 => $this->rotateGd($img, -90),
				8 => $this->rotateGd($img, 90),
				default => $img
			};
		} catch (\Throwable) {
			return $img;
		}
	}

	/** @return \GdImage|null */
	private function rotateGd(\GdImage $img, int $deg): ?\GdImage {
		$bg = \imagecolorallocatealpha($img, 0, 0, 0, 127);
		$out = \imagerotate($img, $deg, $bg);
		if ($out) {
			\imagesavealpha($out, true);
		}
		return $out ?: null;
	}


	private function toPublicPath(string $abs): string {
		// Turn absolute path under CITOMNI_PUBLIC_PATH into public path fragment.
		$pub = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($pub !== '' && \str_starts_with($abs, $pub)) {
			$rel = \substr($abs, \strlen($pub));
			$rel = \str_replace(DIRECTORY_SEPARATOR, '/', $rel);
			return $rel === '' ? '' : $rel;
		}
		
		// Fallback handling
		$strict = (bool)($this->options['strictPublicPath'] ?? (CITOMNI_ENVIRONMENT !== 'dev'));
		if ($strict) {
			return '';
		}
		
		// Non-strict dev fallback: Last 2 segments
		$pi = \pathinfo($abs);
		$dir = (string)($pi['dirname'] ?? '');
		$base = (string)($pi['basename'] ?? '');
		$seg = \explode(DIRECTORY_SEPARATOR, \trim($dir, DIRECTORY_SEPARATOR));
		$tailDir = $seg === [] ? '' : $seg[\count($seg)-1];
		return '/' . $tailDir . '/' . $base;

	}

	private function fromPublicPath(string $public): string {
		$pub = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($pub === '') { return ''; }
		$public = \str_replace('\\', '/', $public);
		$public = \str_replace('..', '', $public);
		return \rtrim($pub, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . \ltrim($public, '/');
	}


	private function deleteIfFile(?string $abs): void {
		if (!$abs) { return; }
		if (\is_file($abs)) { @unlink($abs); }
	}


	/**
	 * Try to set deterministic file permissions under public path.
	 * No-op on systems that ignore chmod or when path is outside public.
	 *
	 * @param string $abs Absolute filesystem path.
	 * @return void
	 */
	private function tryChmodPublic(string $abs): void {
		// Best-effort only: ignore failures quietly.
		@chmod($abs, 0644);
	}

}
