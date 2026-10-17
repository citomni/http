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
 * Upload: Service that checks uploaded files and stores them below the public uploads folder.
 *
 * Turns the files of one request into stored files with public paths: It checks size and type,
 * names the files, resizes and re-encodes images with GD and makes thumbnails. The upload
 * configuration comes from the caller in each call. Saving the paths in records and turning
 * them into URLs belong to the caller.
 *
 * Rules:
 * - Report a rejected file in the result, with a text from the upload language file of
 *   citomni/http; throw only for a broken environment.
 * - Never trust the client's MIME type or file name: Detect the type from the content, and let
 *   the client's name and extension reach a stored name only through sanitizeForFilename()
 *   and normalizeExt().
 * - Write new files only below CITOMNI_PUBLIC_PATH/uploads/u, and delete files only below
 *   CITOMNI_PUBLIC_PATH.
 */
class Upload extends BaseService {

	/** Detector of MIME types from file content, or null without the fileinfo extension. */
	private ?\finfo $finfo = null;

	/**
	 * Open the MIME type detector once, when the fileinfo extension is loaded.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		if (\class_exists(\finfo::class, false)) {
			$this->finfo = new \finfo(\FILEINFO_MIME_TYPE);
		}
	}

	/**
	 * Store the uploaded file of a one-file column, with its thumbnails, and delete the old files.
	 *
	 * For a record column that holds one file, such as a cover image. The method stores the file
	 * and reports its public paths; writing them to the record belongs to the caller. $uploadCfg
	 * takes these keys, each with its default in parentheses:
	 * - dir: Folder below CITOMNI_PUBLIC_PATH/uploads/u, such as "news". Required.
	 * - accept: Allowed MIME types, as detected from the content; empty allows every type ([]).
	 * - maxBytes: Maximum file size in bytes; 0 for only the php.ini limits (0).
	 * - rename: pattern, rand and max for buildBasenameFromPattern() ('', true, 80).
	 * - overwrite: Replace a file of the same name instead of choosing a free name (false).
	 * - deleteOld: Delete the old file and its thumbnails after success (true).
	 * - For JPEG, PNG and WebP images only:
	 *     - encoding: format (webp, jpg or png) and quality (0 to 100) of the stored image (the
	 *       uploaded format, 82). Set, it always re-encodes.
	 *     - w, h: Size of the stored image in pixels; set one to keep the aspect ratio, none to
	 *       keep the size (0).
	 *     - fit: How the image reaches w by h: crop (centered) or stretch (crop).
	 *     - upscale: Allow the stored image to be larger than the upload (true).
	 *     - suffix: Text added to the stored image's name ('').
	 *     - maxMegapixel: Maximum image size in megapixels; 0 for none (0).
	 *     - maxImageRamBytes: Memory budget in bytes for decoding, estimated as 5 bytes per
	 *       pixel; 0 for none (service option maxImageRamBytes, else 128 MiB).
	 *     - exifOrient: Rotate a JPEG by its EXIF orientation, when the exif extension is loaded
	 *       (false).
	 *     - thumbnails: List of thumbnails, each with w and h (set one to keep the aspect ratio),
	 *       fit (crop), format (the stored image's), quality (82), suffix ("_WxH") and column,
	 *       the key under which thumbs returns its public path (none).
	 *
	 * Behavior:
	 * - Fails with status false, path null, no thumbs and no deletions, the reasons in error, when:
	 *     - $columnName is empty, $_FILES holds no file under $fieldName, or PHP reports an
	 *       upload error.
	 *     - The file is empty, larger than maxBytes or the php.ini limits, or of a MIME type
	 *       that accept does not list.
	 *     - dir is empty or contains "..".
	 *     - An image cannot be read or decoded, or exceeds maxMegapixel or maxImageRamBytes.
	 *     - The stored file cannot be written.
	 *     - A thumbnail's size resolves to 0 in a dimension, as with neither w nor h set, or the
	 *       thumbnail cannot be made or written. The stored image and every thumbnail written
	 *       are then deleted again.
	 * - An image is stored resized, re-encoded or rotated as $uploadCfg says, else unchanged;
	 *   every thumbnail is made from the uploaded image, not from the resized one. Any other
	 *   file is stored unchanged, with an extension from its MIME type (bin when unknown) and
	 *   without suffix or thumbnails.
	 * - On success: status true, path the public path of the stored file, thumbs the public
	 *   path of each thumbnail with a column, and deleted the absolute paths of the old files
	 *   deleted.
	 * - Deletes the old files only after success, when deleteOld is on and $currentRow or
	 *   $currentPath is given: The file at a non-empty $currentPath, else at
	 *   $currentRow[$columnName], and its thumbnails.
	 *
	 * Notes:
	 * - When $currentRow[$columnName] is missing or not a string, the old file is taken from the
	 *   first non-empty cover_path, file_path or path column of $currentRow.
	 * - An old thumbnail without a value in its column of $currentRow is looked up by the old
	 *   file's name plus its suffix, so it is missed when the old file was stored with suffix or
	 *   a collision suffix, or when the thumbnail sets only one of w and h and no suffix.
	 * - Thumbnails are named after the generated name, without suffix or collision suffix, and
	 *   replace a file of that name.
	 * - deleteOld deletes by path, so it can delete a file this call wrote at an old file's
	 *   path: The stored file with overwrite on and an unchanged name, or a thumbnail when the
	 *   generated name is unchanged, which rename.rand prevents.
	 *
	 * Typical usage:
	 *   $res = $this->app->upload->saveColumnUpload('cover', $uploadCfg, $payload, $row, columnName: 'cover_path');
	 *
	 * @param  string  $fieldName  Field name in $_FILES.
	 * @param  array<string,mixed>  $uploadCfg  See the description.
	 * @param  array<string,mixed>  $payload  Values for the {col:name} tokens of rename.pattern.
	 * @param  array<string,mixed>|null  $currentRow  Current record, or null for a new one.
	 * @param  int|null  $recordId  Not used.
	 * @param  string|null  $currentPath  Public path of the current file; wins over $currentRow.
	 * @param  string|null  $columnName  Column of $currentRow that holds the current file's path. Required.
	 * @return array{status:bool,path:?string,thumbs:array<string,string>,error:list<string>,deleted:list<string>}  Most error texts end with the client's file name in parentheses, unescaped.
	 * @throws \RuntimeException  When CITOMNI_PUBLIC_PATH is undefined or empty, or the target folder cannot be created.
	 */
	public function saveColumnUpload(string $fieldName, array $uploadCfg, array $payload, ?array $currentRow, ?int $recordId = null, ?string $currentPath = null, ?string $columnName = null): array {

		// The column name identifies the old file for cleanup, so it is required even for a new record.
		if ($columnName === null || $columnName === '') {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_missing_column_name', 'Column name is required for deterministic cleanup.')],
				'deleted'	=> []
			];
		}
		
		if (!isset($_FILES[$fieldName])) {
			return [
				'status'	=> false,
				'path'		=> null,
				'thumbs'	=> [],
				'error'		=> [$this->t('err_no_file', 'No file provided.')],
				'deleted'	=> []
			];
		}
		
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


		// The client's file name, for the error texts below.
		$orig = $this->safeOrigName($f['name'] ?? '');


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

		// maxBytes 0 leaves only the php.ini limits.
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

		// Size, fit and name suffix of the stored image.
		$fullW      = (int)($uploadCfg['w'] ?? 0);
		$fullH      = (int)($uploadCfg['h'] ?? 0);
		$fullFit    = (string)($uploadCfg['fit'] ?? 'crop');   // 'crop' | 'stretch'
		$fullSuffix = (string)($uploadCfg['suffix'] ?? '');    // added before extension
		$upscale    = (bool)($uploadCfg['upscale'] ?? true);

		$fullBaseName = $base . ($fullSuffix !== '' ? $fullSuffix : '');
		$targetPath   = $absDir . $fullBaseName . '.' . $ext;

		// Images: The stored image and the thumbnails are made from the original, not from each other.
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
				$pixels = (float)$w * (float)$h;
				if ($pixels > ($maxMP * 1_000_000.0)) {
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
			$ramCap = (int)($uploadCfg['maxImageRamBytes'] ?? ($this->options['maxImageRamBytes'] ?? (128 * 1024 * 1024)));
			$estimated = (float)$w * (float)$h * 5.0;
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

			if (!$overwrite && \is_file($targetPath)) {
				$targetPath = $this->resolveUniqueTargetPath($targetPath);
			}

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

			$exifOn = (bool)($uploadCfg['exifOrient'] ?? false);
			$exifAdjusted = false;
			if ($exifOn && $mime === 'image/jpeg' && \function_exists('exif_read_data')) {
				$exifAdjusted = $this->orientImageFromExif($src, $f['tmp_name']);
			}

			// EXIF rotation may swap width and height.
			$w = \imagesx($src);
			$h = \imagesy($src);

			$fullOk = false;
			[$targetW, $targetH] = $this->resolveTargetDimensions(
				$w,
				$h,
				$fullW,
				$fullH,
				$upscale
			);

			if ($targetW > 0 && $targetH > 0) {
				$dst = ($fullFit === 'stretch')
					? $this->imageResizeStretch($src, $targetW, $targetH)
					: $this->imageResizeCropCenter($src, $targetW, $targetH);
				if ($dst) {
					$fullOk = $this->imageSave($dst, $targetPath, $ext, $targetQuality);
					unset($dst);
				}
			} else {
				// Without a size, re-encode only when encoding is set or the format or the orientation
				// changes; otherwise move the upload, which keeps its bytes.
				$needReencode = ($encoding !== []) || !$this->sameFamily($mime, $ext) || $exifAdjusted;
				if ($needReencode) {
					$fullOk = $this->imageSave($src, $targetPath, $ext, $targetQuality);
				} else {
					// Unless overwrite is on, the name was made unique above, so the strict move
					// meets no existing file.
					unset($src);
					$src = false;
					$fullOk = $this->moveUploadedFile($f['tmp_name'], $targetPath, $overwrite, false);
					// The upload is gone after the move, so thumbnails decode the stored file.
					if (($uploadCfg['thumbnails'] ?? []) !== []) {
						$src = $this->imageLoad($targetPath);
					}
				}
			}

			if (!$fullOk) {
				unset($src);
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

			// Thumbnails come from the same decoded original, so none is resampled twice.
			$thumbsCfg = (array)($uploadCfg['thumbnails'] ?? []);
			$thumbCols = [];
			$writtenThumbAbs = [];

			if ($thumbsCfg !== []) {
				$piFull = \pathinfo($targetPath);
				$dirAbs = (string)($piFull['dirname'] ?? $absDir);
				$baseNoExtForThumbSeed = $base; // Each thumbnail has its own suffix, so it starts from the generated name.

				foreach ($thumbsCfg as $tCfg) {
					
					$tw = (int)($tCfg['w'] ?? 0);
					$th = (int)($tCfg['h'] ?? 0);

					// One given dimension keeps the aspect ratio.
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

					$ti = ($tfit === 'stretch')
						? $this->imageResizeStretch($src, $tw, $th)
						: $this->imageResizeCropCenter($src, $tw, $th);
					if (!$ti || !$this->imageSave($ti, $thumbAbs, $tfmt, $tqual)) {
						unset($ti);
						$thumbCols['_errors'][] = $this->t('err_thumb_write','Failed to write thumbnail.');
						$writtenThumbAbs[] = null;
						continue;
					}
					unset($ti);
					$this->tryChmodPublic($thumbAbs);
					$writtenThumbAbs[] = $thumbAbs;

					$pub = $this->toPublicPath($thumbAbs);
					$thumbCols['_paths'][] = $pub;
					if ($tcol !== '') {
						$thumbCols[$tcol] = $pub;
					}
				}
			}

			// One failed thumbnail fails the upload: Remove everything this call wrote.
			if (!empty($thumbCols['_errors'])) {
				
				$this->deleteIfFile($targetPath);
				foreach ((array)($thumbCols['_paths'] ?? []) as $pub) {
					$this->deleteIfFile($this->fromPublicPath((string)$pub));
				}
				unset($src);
				$errs = (array)$thumbCols['_errors'];
				if ($orig !== '') { $errs = \array_map(fn($e) => (string)$e . ' (' . $orig . ')', $errs); }
				return ['status'=>false,'path'=>null,'thumbs'=>[],'error'=>$errs,'deleted'=>[]];
			}
			unset($src);
			unset($thumbCols['_errors'], $thumbCols['_paths']);

			// Only after every write succeeded, so a failed upload does not delete the old files.
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

		// Any other file is moved as it is, without thumbnails.
		$targetPath = $absDir . $base . '.' . $ext; // suffix applies to images only
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
	 * Store the uploaded images of a multi-file field as attachments, each with its thumbnails.
	 *
	 * For images kept as separate attachment records, such as a gallery. Every image is decoded
	 * and re-encoded; the method reports the public paths, and creating the records belongs to the
	 * caller. $attachedCfg takes these keys, each with its default in parentheses:
	 * - inputName: Field name in $_FILES; a trailing "[]" is ignored. Required.
	 * - dir, accept, maxBytes, rename, w, h, fit, upscale, suffix, maxMegapixel,
	 *   maxImageRamBytes, exifOrient and thumbnails: As in saveColumnUpload().
	 * - encoding: format (webp, jpg or png) and quality (0 to 100) of the stored images (webp, 82).
	 * - maxCount: Maximum number of attachments, $currentCount included; 0 for no limit (0).
	 *
	 * Behavior:
	 * - Fails with status false, no files and the reason in error when inputName is empty,
	 *   $_FILES holds no file under it, or dir is empty or contains "..".
	 * - Otherwise status is true, also when every file is rejected, and files holds one result
	 *   per uploaded file, in upload order:
	 *     - ok true with the public path of the stored image and, in thumbs, the public path of
	 *       each thumbnail with a column.
	 *     - ok false with the reason in error when maxCount is reached, PHP reports an upload
	 *       error, or the file is empty, larger than maxBytes or the php.ini limits, of a MIME
	 *       type that accept does not list, not a decodable JPEG, PNG or WebP image, over
	 *       maxMegapixel or maxImageRamBytes, or cannot be written. A failed thumbnail rejects
	 *       its image and deletes what was written for it.
	 * - Chooses free names: A taken name, of an image or a thumbnail, gets a random suffix.
	 *
	 * Typical usage:
	 *   $res = $this->app->upload->addAttachedImages($attachedCfg, $payload, $articleId, $imageCount);
	 *
	 * @param  array<string,mixed>  $attachedCfg  See the description.
	 * @param  array<string,mixed>  $payload  Values for the {col:name} tokens of rename.pattern.
	 * @param  int  $fkId  Not used.
	 * @param  int  $currentCount  Number of attachments the record already has.
	 * @return array{status:bool,files:list<array{ok:true,path:string,thumbs:array<string,string>}|array{ok:false,error:string}>,error:list<string>}  Most error texts end with the client's file name in parentheses, unescaped.
	 * @throws \RuntimeException  When CITOMNI_PUBLIC_PATH is undefined or empty, or the target folder cannot be created.
	 * @see \CitOmni\Http\Service\Upload::saveColumnUpload()  The shared keys of $attachedCfg.
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

		// Size, fit and name suffix of the stored images.
		$fullW      = (int)($attachedCfg['w'] ?? 0);
		$fullH      = (int)($attachedCfg['h'] ?? 0);
		$fullFit    = (string)($attachedCfg['fit'] ?? 'crop');      // 'crop' | 'stretch'
		$fullSuffix = (string)($attachedCfg['suffix'] ?? '');       // added before extension
		$exifOn     = (bool)($attachedCfg['exifOrient'] ?? false);
		$upscale    = (bool)($attachedCfg['upscale'] ?? true);

		$maxCount   = (int)($attachedCfg['maxCount'] ?? 0);
		$remaining  = $maxCount > 0 ? \max(0, $maxCount - \max(0, $currentCount)) : 0;
		$accepted   = 0;

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
			
			// An empty accept allows every type; the image check below still applies.
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
				$pixels = (float)$w * (float)$h;
				if ($pixels > ($maxMP * 1_000_000.0)) {
					$msg = $this->t('err_too_large_megapixel', 'Image exceeds megapixel limit.');
					if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
					$out['files'][] = ['ok' => false, 'error' => $msg];
					continue;
				}
			}
			$estimated = (float)$w * (float)$h * 5.0;
			if ($ramCap > 0 && $estimated > $ramCap) {
				$msg = $this->t('err_image_memory', 'Image is too large to process safely.');
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

			$fullBaseName = $base . ($fullSuffix !== '' ? $fullSuffix : '');
			$fullAbsPath  = $this->resolveUniqueTargetPath($absDir . $fullBaseName . '.' . $ext);

			// Decode once; the stored image and the thumbnails all come from this original.
			$src = $this->imageLoad($f['tmp_name']);
			if (!$src) {
				$msg = $this->t('err_invalid_image', 'Invalid image.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			if ($exifOn && $mime === 'image/jpeg' && \function_exists('exif_read_data')) {
				$this->orientImageFromExif($src, $f['tmp_name']);
			}

			// EXIF rotation may swap width and height.
			$w = \imagesx($src);
			$h = \imagesy($src);

			$fullOk = false;
			[$targetW, $targetH] = $this->resolveTargetDimensions(
				$w,
				$h,
				$fullW,
				$fullH,
				$upscale
			);
			
			if ($targetW > 0 && $targetH > 0) {
				$dst = ($fullFit === 'stretch')
					? $this->imageResizeStretch($src, $targetW, $targetH)
					: $this->imageResizeCropCenter($src, $targetW, $targetH);
				if ($dst) {
					$fullOk = $this->imageSave($dst, $fullAbsPath, $ext, $quality);
					unset($dst);
				}
			} else {
				$fullOk = $this->imageSave($src, $fullAbsPath, $ext, $quality);
			}

			if (!$fullOk) {
				unset($src);
				$msg = $this->t('err_write_failed', 'Failed to write file.');
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}
			$this->tryChmodPublic($fullAbsPath);

			$thumbCols = [];
			$writtenThumbAbs = [];
			if ($thumbsCfg !== []) {
				$piFull = \pathinfo($fullAbsPath);
				$dirAbs = (string)($piFull['dirname'] ?? $absDir);
				$baseNoExtForThumbSeed = $base; // Each thumbnail has its own suffix, so it starts from the generated name.

				foreach ($thumbsCfg as $tCfg) {
					$tw = (int)($tCfg['w'] ?? 0);
					$th = (int)($tCfg['h'] ?? 0);
					
					// One given dimension keeps the aspect ratio.
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

					// Unique, because an attachment's thumbnail must not replace another one's.
					$thumbAbs = $this->resolveUniqueTargetPath($dirAbs . DIRECTORY_SEPARATOR . $baseNoExtForThumbSeed . $tsuf . '.' . $tfmt);

					$ti = ($tfit === 'stretch')
						? $this->imageResizeStretch($src, $tw, $th)
						: $this->imageResizeCropCenter($src, $tw, $th);
					if (!$ti || !$this->imageSave($ti, $thumbAbs, $tfmt, $tqual)) {
						unset($ti);
						$thumbCols['_errors'][] = $this->t('err_thumb_write', 'Failed to write thumbnail.');
						continue;
					}
					unset($ti);
					$this->tryChmodPublic($thumbAbs);
					$writtenThumbAbs[] = $thumbAbs;

					$pub = $this->toPublicPath($thumbAbs);
					$thumbCols['_paths'][] = $pub;
					if ($tcol !== '') {
						$thumbCols[$tcol] = $pub;
					}
				}
													   
			}

			// One failed thumbnail rejects the image: Remove everything written for it.
			if (!empty($thumbCols['_errors'])) {
				$this->deleteIfFile($fullAbsPath);
				foreach ((array)($thumbCols['_paths'] ?? []) as $pub) {
					$this->deleteIfFile($this->fromPublicPath((string)$pub));
				}
				unset($src);
				$msg = \implode(' ', (array)$thumbCols['_errors']);
				if ($orig !== '') { $msg .= ' (' . $orig . ')'; }
				$out['files'][] = ['ok' => false, 'error' => $msg];
				continue;
			}

			unset($src);
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
	 * Store the uploaded files of a multi-file field as attachments, unchanged.
	 *
	 * For files of any type kept as separate attachment records. The method reports the public
	 * paths; creating the records belongs to the caller. $attachedCfg takes these keys, each with
	 * its default in parentheses:
	 * - inputName: Field name in $_FILES; a trailing "[]" is ignored. Required.
	 * - dir, accept, maxBytes and rename: As in saveColumnUpload().
	 * - maxCount: Maximum number of attachments, $currentCount included; 0 for no limit (0).
	 *
	 * Behavior:
	 * - Fails with status false, no files and the reason in error when inputName is empty,
	 *   $_FILES holds no file under it, or dir is empty or contains "..".
	 * - Otherwise status is true, also when every file is rejected, and files holds one result
	 *   per uploaded file, in upload order:
	 *     - ok true with the public path of the stored file.
	 *     - ok false with the reason in error when maxCount is reached, PHP reports an upload
	 *       error, or the file is empty, larger than maxBytes or the php.ini limits, of a MIME
	 *       type that accept does not list, or cannot be written.
	 * - Stores each file with an extension from its detected MIME type. When that type is
	 *   unknown, the client's extension is used if the service knows it, else bin.
	 * - Chooses free names: A taken name gets a random suffix.
	 *
	 * Typical usage:
	 *   $res = $this->app->upload->addAttachedFiles($attachedCfg, $payload, $articleId, $fileCount);
	 *
	 * @param  array<string,mixed>  $attachedCfg  See the description.
	 * @param  array<string,mixed>  $payload  Values for the {col:name} tokens of rename.pattern.
	 * @param  int  $fkId  Not used.
	 * @param  int  $currentCount  Number of attachments the record already has.
	 * @return array{status:bool,files:list<array{ok:true,path:string}|array{ok:false,error:string}>,error:list<string>}  Most error texts end with the client's file name in parentheses, unescaped.
	 * @throws \RuntimeException  When CITOMNI_PUBLIC_PATH is undefined or empty, or the target folder cannot be created.
	 * @see \CitOmni\Http\Service\Upload::saveColumnUpload()  The shared keys of $attachedCfg.
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

			// Auto-suffix on: A file that appears after the name was chosen still does not get replaced.
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





	// ----------------------------------------------------------------
	// File names
	// ----------------------------------------------------------------

	/**
	 * Turn text into a file name of lowercase ASCII letters, digits and single dashes.
	 *
	 * Behavior:
	 * - Transliterates a fixed set of Danish, German and accented Latin letters, lowercases, and
	 *   replaces each run of other characters with one dash. Leading and trailing dashes are
	 *   trimmed, also after shortening.
	 * - Returns '' when nothing usable is left.
	 *
	 * Typical usage:
	 *   $name = $this->app->upload->sanitizeForFilename($title, 60);
	 *
	 * @param  string  $s  UTF-8 text.
	 * @param  int  $max  Maximum length in bytes; 0 for no limit.
	 * @return string  The name without extension.
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
		$s = \strtolower($s);
		$s = (string)\preg_replace('~[^a-z0-9]+~', '-', $s);
		$s = (string)\preg_replace('~-+~', '-', $s);
		$s = \trim($s, '-');
		if ($max > 0 && \strlen($s) > $max) {
			$s = \substr($s, 0, $max);
			$s = \rtrim($s, '-');
		}
		return $s;
	}


	/**
	 * Shorten the client's file name for use in an error text.
	 *
	 * Behavior:
	 * - Drops directory parts, turns runs of CR, LF and tab into one space, and trims.
	 * - Cuts a name longer than 120 characters to 120 and appends an ellipsis character;
	 *   without mbstring it counts bytes.
	 * - Returns '' for an empty name.
	 *
	 * Notes:
	 * - Not escaped for HTML: Whoever renders the error text escapes it.
	 *
	 * @param  string|null  $name  File name as the client sent it.
	 * @return string
	 */
	private function safeOrigName(?string $name): string {
		$b = \basename((string)$name);
		$b = (string)\preg_replace('~[\r\n\t]+~', ' ', $b);
		$b = \trim($b);
		if ($b === '') {
			return '';
		}
		if (\function_exists('mb_strlen')) {
			if (\mb_strlen($b) > 120) {
				$b = \mb_substr($b, 0, 120) . '…';
			}
		} elseif (\strlen($b) > 120) {
			$b = \substr($b, 0, 120) . '…';
		}
		return $b;
	}


	/**
	 * Build a file name, without extension, from a pattern and form values.
	 *
	 * Behavior:
	 * - Replaces each {col:name} token in $pattern with $payload[name] when that is a scalar,
	 *   else with '', and sanitizes the result with sanitizeForFilename().
	 * - Falls back to the sanitized name of $originalName, and then to "file", when the result
	 *   is empty.
	 * - With $addRand, appends a dash, the current Unix time in hex and 4 random hex digits, so
	 *   that names rarely repeat.
	 * - Shortens the result to $maxLen bytes. With $addRand only the part before the random part
	 *   is shortened, and at least one byte of it stays, so a very small $maxLen is exceeded.
	 * - Never returns ''.
	 *
	 * Typical usage:
	 *   $name = $this->app->upload->buildBasenameFromPattern('news-{col:slug}', $payload, $originalName, true, 80);
	 *
	 * @param  string  $pattern  Pattern such as "news-{col:slug}"; '' to use $originalName.
	 * @param  array<string,mixed>  $payload  Values for the {col:name} tokens.
	 * @param  string  $originalName  File name as the client sent it.
	 * @param  bool  $addRand  Whether to append the random part.
	 * @param  int  $maxLen  Maximum length in bytes; 0 for no limit.
	 * @return string
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
			$suffix = '-' . \dechex(\time()) . \bin2hex(\random_bytes(2));
		}
		$out = $base . $suffix;

		if ($maxLen > 0 && \strlen($out) > $maxLen) {
			if ($addRand && $suffix !== '') {
				$allow = $maxLen - \strlen($suffix);
				if ($allow < 1) { $allow = 1; }
				$baseTrimmed = \substr($base, 0, $allow);
				$out = $baseTrimmed . $suffix;
			} else {
				$out = \substr($out, 0, $maxLen);
			}
		}
		$out = (string)\preg_replace('~-+~', '-', $out);
		$out = \trim($out, '-');
		if ($out === '') {
			$out = 'file' . ($addRand ? $suffix : '');
		}
		return $out;
	}

	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Bring one $_FILES entry into a fixed shape.
	 *
	 * Behavior:
	 * - Returns null when the entry has no name or no tmp_name.
	 * - A missing error counts as UPLOAD_ERR_NO_FILE, a missing size as 0.
	 *
	 * @param  array<string,mixed>  $f  The $_FILES entry of a single-file field.
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
	 */
	private function normalizeOneFile(array $f): ?array {

		// Assumes the single-file shape; the arrays of a multi-file field are not handled here.
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
	 * Turn the $_FILES entry of a multi-file field into one entry per file.
	 *
	 * Behavior:
	 * - Accepts the entry of a single-file field too, as one file.
	 * - Keeps the upload order. A missing error counts as UPLOAD_ERR_NO_FILE, a missing size as 0.
	 *
	 * @param  array<string,mixed>  $f  The $_FILES entry of the field.
	 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	private function normalizeMultiFiles(array $f): array {

		$out = [];
		if (!isset($f['name']) || !\is_array($f['name'])) {

			// A field without [] in its name sends one file in the single-file shape.
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

	/**
	 * Return the absolute folder for a folder below the public uploads folder.
	 *
	 * @param  string  $dir  Folder as normalizeDir() returns it.
	 * @return string  CITOMNI_PUBLIC_PATH/uploads/u followed by $dir, ending with a slash.
	 * @throws \RuntimeException  When CITOMNI_PUBLIC_PATH is undefined or empty.
	 */
	private function absUploadsDir(string $dir): string {
		$base = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($base === '') {
			throw new \RuntimeException('CITOMNI_PUBLIC_PATH is not defined.');
		}
		return \rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'u' . $dir;
	}

	/**
	 * Normalize an upload folder from the upload configuration.
	 *
	 * @param  string  $dir  Folder such as "news" or "/news/"; backslashes count as slashes.
	 * @return string  The folder starting and ending with a slash, or '' when it is empty or contains "..".
	 */
	private function normalizeDir(string $dir): string {
		$dir = \trim(\str_replace('\\', '/', $dir));
		if ($dir === '' || \str_contains($dir, '..')) { return ''; }
		if ($dir[0] !== '/') { $dir = '/' . $dir; }
		if (\substr($dir, -1) !== '/') { $dir .= '/'; }
		return $dir;
	}

	/**
	 * Create a folder, with its parents, unless it exists.
	 *
	 * @param  string  $absDir  Absolute path.
	 * @return void
	 * @throws \RuntimeException  When the folder does not exist and cannot be created.
	 */
	private function ensureDir(string $absDir): void {
		if (!\is_dir($absDir)) {
			if (!\mkdir($absDir, 0755, true) && !\is_dir($absDir)) {
				throw new \RuntimeException('Failed to create upload directory: ' . $absDir);
			}
		}
	}

	/**
	 * Detect the MIME type of a file from its content.
	 *
	 * @param  string  $tmpPath  Absolute path.
	 * @return string  The type from fileinfo, else from mime_content_type(), else application/octet-stream.
	 */
	private function detectMime(string $tmpPath): string {
		if ($this->finfo !== null) {
			$mime = @$this->finfo->file($tmpPath);
			if (\is_string($mime) && $mime !== '') {
				return $mime;
			}
		}

		if (\function_exists('mime_content_type')) {
			$mime = @\mime_content_type($tmpPath);
			if (\is_string($mime) && $mime !== '') {
				return $mime;
			}
		}

		return 'application/octet-stream';
	}

	/**
	 * Check whether a MIME type is an image that this service decodes: JPEG, PNG or WebP.
	 *
	 * @param  string  $mime
	 * @return bool
	 */
	private function isImageMime(string $mime): bool {
		return \in_array($mime, ['image/webp', 'image/png', 'image/jpeg'], true);
	}

	/**
	 * Read the pixel size of an image file without decoding it.
	 *
	 * @param  string  $tmpPath  Absolute path.
	 * @return array{0:int,1:int}  Width and height; [0, 0] when the file is not a readable image.
	 */
	private function readImageDimensions(string $tmpPath): array {
		$info = @\getimagesize($tmpPath);
		if ($info === false) {
			return [0, 0];
		}
		return [(int)($info[0] ?? 0), (int)($info[1] ?? 0)];
	}


	/**
	 * Resolve the pixel size of the stored image.
	 *
	 * Behavior:
	 * - One given dimension keeps the source aspect ratio; two keep the requested one.
	 * - Without upscaling, a target larger than the source in either dimension shrinks, keeping
	 *   its aspect ratio, until it fits within the source.
	 * - Returns [0, 0] when the source size is not positive, and a non-positive target when none
	 *   is given, which means: Keep the source size.
	 *
	 * @param  int  $srcW  Source width in pixels.
	 * @param  int  $srcH  Source height in pixels.
	 * @param  int  $targetW  Requested width in pixels; 0 for none.
	 * @param  int  $targetH  Requested height in pixels; 0 for none.
	 * @param  bool  $upscale  Whether the target may be larger than the source.
	 * @return array{0:int,1:int}  Width and height.
	 */
	private function resolveTargetDimensions(int $srcW, int $srcH, int $targetW, int $targetH, bool $upscale): array {
		if ($srcW <= 0 || $srcH <= 0) {
			return [0, 0];
		}

		if ($targetW > 0 && $targetH <= 0) {
			$targetH = (int)\round($targetW * ($srcH / $srcW));
		} elseif ($targetH > 0 && $targetW <= 0) {
			$targetW = (int)\round($targetH * ($srcW / $srcH));
		}

		if (
			!$upscale
			&& $targetW > 0
			&& $targetH > 0
			&& ($targetW > $srcW || $targetH > $srcH)
		) {
			$scale = \min(
				1.0,
				$srcW / $targetW,
				$srcH / $targetH
			);

			$targetW = \max(1, (int)\round($targetW * $scale));
			$targetH = \max(1, (int)\round($targetH * $scale));
		}

		return [$targetW, $targetH];
	}


	/**
	 * Return a path where no file exists yet.
	 *
	 * Behavior:
	 * - Returns the desired path when no file is there; otherwise appends a dash, the current
	 *   Unix time in hex and 4 random hex digits to the name until no file is there.
	 *
	 * Notes:
	 * - Not atomic: Another process can create the file before this one writes it.
	 *
	 * @param  string  $desiredAbsPath  Absolute path.
	 * @return string
	 */
	private function resolveUniqueTargetPath(string $desiredAbsPath): string {
		if (!\is_file($desiredAbsPath)) {
			return $desiredAbsPath;
		}
		$pi = \pathinfo($desiredAbsPath);
		$dirAbs = (string)($pi['dirname'] ?? '');
		$baseNoExt = (string)($pi['filename'] ?? 'file');
		$ext = isset($pi['extension']) ? ('.' . $pi['extension']) : '';
		do {
			$suffix = '-' . \dechex(\time()) . \bin2hex(\random_bytes(2));
			$candidate = $dirAbs . DIRECTORY_SEPARATOR . $baseNoExt . $suffix . $ext;
		} while (\is_file($candidate));
		return $candidate;
	}


	/**
	 * Move an uploaded file to its target path.
	 *
	 * Behavior:
	 * - When a file exists at the target and $overwrite is off: Returns false without moving,
	 *   or with $allowAutoSuffix moves to the target name plus a dash, the current Unix time in
	 *   hex and 4 random hex digits.
	 * - Uses move_uploaded_file() for a file that PHP received in this request, else rename().
	 *
	 * @param  string  $tmpPath  Absolute path of the uploaded file.
	 * @param  string  $targetPath  Absolute target path.
	 * @param  bool  $overwrite  Whether an existing file at the target is replaced.
	 * @param  bool  $allowAutoSuffix  Whether a taken target leads to a new name instead of failure.
	 * @return bool  Whether the file was moved. With an auto suffix, the caller does not learn the new path.
	 */
	private function moveUploadedFile(string $tmpPath, string $targetPath, bool $overwrite, bool $allowAutoSuffix): bool {
		if (\is_file($targetPath) && !$overwrite) {
			if (!$allowAutoSuffix) {
				return false;
			}
			$pi = \pathinfo($targetPath);
			$base = (string)($pi['dirname'] . DIRECTORY_SEPARATOR . ($pi['filename'] ?? 'file'));
			$ext  = isset($pi['extension']) ? ('.' . $pi['extension']) : '';
			$targetPath = $base . '-' . \dechex(\time()) . \bin2hex(\random_bytes(2)) . $ext;
		}
		if (\function_exists('is_uploaded_file') && @\is_uploaded_file($tmpPath)) {
			return @\move_uploaded_file($tmpPath, $targetPath);
		}
		return @\rename($tmpPath, $targetPath);
	}

	/**
	 * Return the file extension of a MIME type.
	 *
	 * @param  string  $mime
	 * @return string  Extension without dot; bin for a type this service does not know.
	 */
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


	/**
	 * Map a format or extension to the extension this service writes.
	 *
	 * @param  string  $format  Format or extension without dot, in any case.
	 * @return string  The known extension, with jpeg written as jpg; bin for anything else.
	 */
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

			// vector (never decoded, so no thumbnails)
			'svg' => 'svg',

			default => 'bin',
		};
	}


	/**
	 * Check whether an image MIME type is already the format of an extension.
	 *
	 * @param  string  $mime
	 * @param  string  $ext  Extension as normalizeExt() returns it.
	 * @return bool  False means the image must be re-encoded to get that extension.
	 */
	private function sameFamily(string $mime, string $ext): bool {
		return ($mime === 'image/jpeg' && $ext === 'jpg')
			|| ($mime === 'image/png' && $ext === 'png')
			|| ($mime === 'image/webp' && $ext === 'webp');
	}

	/**
	 * Limit a maximum file size by the php.ini upload limits.
	 *
	 * Behavior:
	 * - The php.ini limit is the smaller of upload_max_filesize and post_max_size, ignoring one
	 *   that is not positive or unset.
	 * - Returns the smaller of $cfgMax and that limit, the limit alone when $cfgMax is 0 or less,
	 *   and $cfgMax, at least 0, when php.ini sets no limit.
	 *
	 * @param  int  $cfgMax  Maximum size in bytes from the upload configuration; 0 for none.
	 * @return int  Maximum size in bytes; 0 for no limit.
	 */
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
	 * Make thumbnails of an image file that is already stored.
	 *
	 * Behavior:
	 * - Names each thumbnail after the image file plus the thumbnail's suffix, in the same
	 *   folder, and replaces a file of that name.
	 * - Collects a failure per thumbnail in _errors and goes on with the next one; deletes
	 *   nothing. Returns one error and no thumbnails when the image cannot be decoded.
	 *
	 * @param  string  $srcPath  Absolute path of the stored image.
	 * @param  list<array<string,mixed>>  $thumbsCfg  Thumbnails as in saveColumnUpload(), except that w and h are both required.
	 * @return array<string,mixed>  _errors: list of error texts; _paths: list of public paths; plus the public path under each thumbnail's column.
	 */
	private function makeThumbnails(string $srcPath, array $thumbsCfg): array {
		$out = ['_errors' => [], '_paths' => []];

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

			// The default suffix must equal the one deleteOldColumnFiles() derives.
			$suffix = (string)($tCfg['suffix'] ?? ('_' . $w . 'x' . $h));

			$thumb = ($fit === 'stretch')
				? $this->imageResizeStretch($srcImg, $w, $h)
				: $this->imageResizeCropCenter($srcImg, $w, $h);

			if (!$thumb) {
				$out['_errors'][] = $this->t('err_thumb_process', 'Failed to process thumbnail.');
				continue;
			}

			$dstAbs = $dirAbs . DIRECTORY_SEPARATOR . $baseNoExt . $suffix . '.' . $fmt;
			$ok = $this->imageSave($thumb, $dstAbs, $fmt, $q);
			unset($thumb);

			if (!$ok) {
				$out['_errors'][] = $this->t('err_thumb_write', 'Failed to write thumbnail.');
				continue;
			}

			$this->tryChmodPublic($dstAbs);

			$pub = $this->toPublicPath($dstAbs);
			$out['_paths'][] = $pub;

			$col = (string)($tCfg['column'] ?? '');
			if ($col !== '') {
				$out[$col] = $pub;
			}
		}

		unset($srcImg);
		return $out;
	}


	/**
	 * Delete the old file of a column upload and its thumbnails.
	 *
	 * Behavior:
	 * - The old file is the one at a non-empty $explicitPublicPath, else at
	 *   $currentRow[$columnName] when that is a string, else at findCurrentColumnPath().
	 * - A thumbnail is found through its column in $currentRow when that has a value, else from
	 *   the old file's name plus the thumbnail's suffix, or "_WxH" when it has both w and h and
	 *   no suffix, with its format or the old file's extension.
	 * - Skips files that do not exist or cannot be deleted, and deletes each file at most once.
	 *
	 * @param  array<string,mixed>|null  $currentRow  Current record.
	 * @param  array<string,mixed>  $uploadCfg  Upload configuration; only thumbnails is read.
	 * @param  string|null  $explicitPublicPath  Public path of the old file.
	 * @param  string|null  $columnName  Column of $currentRow that holds the old file's path.
	 * @return list<string>  Absolute paths of the deleted files.
	 */
	private function deleteOldColumnFiles(?array $currentRow = null, array $uploadCfg = [], ?string $explicitPublicPath = null, ?string $columnName = null): array {
		$deleted = [];
		$seen = [];

		$origCol = (string)($explicitPublicPath ?? '');
		if ($origCol === '' && $currentRow !== null) {
			if ($columnName !== null && isset($currentRow[$columnName]) && \is_string($currentRow[$columnName])) {
				$origCol = (string)$currentRow[$columnName];
			} else {
				$origCol = $this->findCurrentColumnPath($currentRow, $uploadCfg);
			}
		}

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

		// A thumbnail column with a value names the file exactly.
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

		// Without a column value, derive the thumbnail's name from the old file's name.
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


	/**
	 * Guess the public path of a record's current file from common column names.
	 *
	 * @param  array<string,mixed>  $currentRow  Current record.
	 * @param  array<string,mixed>  $uploadCfg  Not used.
	 * @return string  The first non-empty string among cover_path, file_path and path, in that order; '' when none.
	 */
	private function findCurrentColumnPath(array $currentRow, array $uploadCfg): string {
		foreach (['cover_path', 'file_path', 'path'] as $k) {
			if (isset($currentRow[$k]) && \is_string($currentRow[$k]) && $currentRow[$k] !== '') {
				return (string)$currentRow[$k];
			}
		}
		return '';
	}

	/**
	 * Return the error text for a PHP upload error code.
	 *
	 * @param  int  $code  One of the UPLOAD_ERR_* constants.
	 * @return string
	 */
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

	/**
	 * Return a text from the upload language file of citomni/http.
	 *
	 * @param  string  $key  Key in the language file.
	 * @param  string  $fallback  English text, used when the key has no usable value.
	 * @return string
	 * @see \CitOmni\Infrastructure\Service\Txt::get()  How the text is looked up.
	 */
	private function t(string $key, string $fallback): string {
		return $this->app->txt->get($key, 'upload', 'citomni/http', $fallback);
	}

	// ----------------------------------------------------------------
	// GD image helpers
	// ----------------------------------------------------------------

	/**
	 * Decode a JPEG, PNG or WebP file with GD.
	 *
	 * @param  string  $path  Absolute path.
	 * @return \GdImage|false  False when the file is not a readable image of these types, or GD lacks the decoder.
	 */
	private function imageLoad(string $path): \GdImage|false {
		$info = @\getimagesize($path);
		if ($info === false) { return false; }

		$mime = (string)($info['mime'] ?? '');
		return match ($mime) {
			'image/webp' => \function_exists('imagecreatefromwebp') ? @\imagecreatefromwebp($path) : false,
			'image/png' => \function_exists('imagecreatefrompng') ? @\imagecreatefrompng($path) : false,
			'image/jpeg' => \function_exists('imagecreatefromjpeg') ? @\imagecreatefromjpeg($path) : false,
			default => false
		};
	}

	/**
	 * Resample a whole image to a size, ignoring its aspect ratio.
	 *
	 * @param  \GdImage  $src
	 * @param  int  $w  Width in pixels, at least 1.
	 * @param  int  $h  Height in pixels, at least 1.
	 * @return \GdImage|false  A new image with alpha kept; false when GD fails.
	 */
	private function imageResizeStretch(\GdImage $src, int $w, int $h): \GdImage|false {
		$dst = \imagecreatetruecolor($w, $h);
		if (!$dst) { return false; }
		\imagealphablending($dst, false);
		\imagesavealpha($dst, true);
		$sw = \imagesx($src);
		$sh = \imagesy($src);
		if (!\imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh)) {
			return false;
		}
		return $dst;
	}

	/**
	 * Resample an image to a size, cutting the excess of the wider or taller side evenly from both edges.
	 *
	 * @param  \GdImage  $src
	 * @param  int  $w  Width in pixels, at least 1.
	 * @param  int  $h  Height in pixels, at least 1.
	 * @return \GdImage|false  A new image with alpha kept; false when GD fails.
	 */
	private function imageResizeCropCenter(\GdImage $src, int $w, int $h): \GdImage|false {
		$sw = \imagesx($src);
		$sh = \imagesy($src);
		if ($sw <= 0 || $sh <= 0) { return false; }

		$srcRatio = $sw / $sh;
		$dstRatio = $w / $h;

		if ($srcRatio > $dstRatio) {
			// Source wider than target: Crop the width.
			$newW = (int)\round($sh * $dstRatio);
			$newH = $sh;
			$srcX = (int)\round(($sw - $newW) / 2);
			$srcY = 0;
		} else {
			// Source taller than target: Crop the height.
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
			return false;
		}
		return $dst;
	}

	/**
	 * Encode an image and write it to a file.
	 *
	 * Behavior:
	 * - Clamps the quality to 0 to 100. For png it becomes the compression level, from 9 at
	 *   quality 0 to 0 at quality 100.
	 * - Replaces an existing file at $path.
	 *
	 * @param  \GdImage  $img
	 * @param  string  $path  Absolute target path.
	 * @param  string  $fmt  webp, png or jpg.
	 * @param  int  $q  Quality, 0 to 100.
	 * @return bool  False for another format, when GD lacks the encoder, or when writing fails.
	 */
	private function imageSave(\GdImage $img, string $path, string $fmt, int $q): bool {
		$q = \max(0, \min(100, $q));

		return match ($fmt) {
			'webp' => \function_exists('imagewebp') && @\imagewebp($img, $path, $q),
			'png' => \function_exists('imagepng') && @\imagepng($img, $path, \max(0, \min(9, (int)\round((100 - $q) / 11.111)))),
			'jpg' => \function_exists('imagejpeg') && @\imagejpeg($img, $path, $q),
			default => false
		};
	}

	/**
	 * Decode an image file and write it again in a given format.
	 *
	 * @param  string  $tmpPath  Absolute path of the source image.
	 * @param  string  $targetPath  Absolute target path.
	 * @param  string  $fmt  webp, png or jpg.
	 * @param  int  $q  Quality, 0 to 100.
	 * @param  bool  $applyExif  Whether to rotate by the EXIF orientation first, when the exif extension is loaded.
	 * @return bool  False when the source cannot be decoded or the target cannot be written.
	 * @see \CitOmni\Http\Service\Upload::imageSave()  How the image is encoded.
	 */
	private function reencodeImage(string $tmpPath, string $targetPath, string $fmt, int $q, bool $applyExif): bool {
		$img = $this->imageLoad($tmpPath);
		if (!$img) { return false; }

		if ($applyExif && \function_exists('exif_read_data')) {
			$this->orientImageFromExif($img, $tmpPath);
		}
		$ok = $this->imageSave($img, $targetPath, $fmt, $q);
		unset($img);
		return $ok;
	}

	/**
	 * Rotate an image by the EXIF orientation of its file.
	 *
	 * @param  \GdImage  $img  Decoded image; replaced by the rotated one when rotation happens.
	 * @param  string  $path  Absolute path of the JPEG file that holds the EXIF data.
	 * @return bool  True when $img was replaced.
	 * @see \CitOmni\Http\Service\Upload::applyExifOrientation()  Which orientations rotate.
	 */
	private function orientImageFromExif(\GdImage &$img, string $path): bool {
		$oriented = $this->applyExifOrientation($img, $path);
		if ($oriented === null || $oriented === $img) {
			return false;
		}

		$img = $oriented;
		return true;
	}

	/**
	 * Return an image rotated by the EXIF orientation of its file.
	 *
	 * Behavior:
	 * - Rotates for orientation 3 (180 degrees), 6 (90 degrees clockwise) and 8 (90 degrees
	 *   counter-clockwise).
	 * - Returns $img itself for any other orientation, also the mirrored ones, and when the
	 *   EXIF data cannot be read.
	 *
	 * @param  \GdImage  $img  Decoded image.
	 * @param  string  $path  Absolute path of the JPEG file that holds the EXIF data.
	 * @return \GdImage|null  The rotated image, $img, or null when rotation fails.
	 */
	private function applyExifOrientation(\GdImage $img, string $path): ?\GdImage {
		// EXIF data comes from the client: Any failure keeps the image as decoded.
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

	/**
	 * Rotate an image on a transparent background.
	 *
	 * @param  \GdImage  $img
	 * @param  int  $deg  Degrees counter-clockwise.
	 * @return \GdImage|null  A new image, or null when GD fails.
	 */
	private function rotateGd(\GdImage $img, int $deg): ?\GdImage {
		$bg = \imagecolorallocatealpha($img, 0, 0, 0, 127);
		$out = \imagerotate($img, $deg, $bg);
		if ($out) {
			\imagesavealpha($out, true);
		}
		return $out ?: null;
	}


	// ----------------------------------------------------------------
	// Files below the public folder
	// ----------------------------------------------------------------

	/**
	 * Return the public path of an absolute file path.
	 *
	 * Behavior:
	 * - For a path that starts with CITOMNI_PUBLIC_PATH: The rest of it, with forward slashes.
	 * - For any other path: '' when the service option strictPublicPath is true, or when it is
	 *   unset and CITOMNI_ENVIRONMENT is not dev; otherwise "/folder/file" from the last folder
	 *   name and the file name, which need not point at the file.
	 *
	 * Notes:
	 * - Compares strings only, so a sibling folder whose name starts with the public folder's
	 *   name counts as below it.
	 *
	 * @param  string  $abs  Absolute path.
	 * @return string
	 */
	private function toPublicPath(string $abs): string {
		$pub = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($pub !== '' && \str_starts_with($abs, $pub)) {
			$rel = \substr($abs, \strlen($pub));
			$rel = \str_replace(DIRECTORY_SEPARATOR, '/', $rel);
			return $rel === '' ? '' : $rel;
		}
		
		$strict = (bool)($this->options['strictPublicPath'] ?? (CITOMNI_ENVIRONMENT !== 'dev'));
		if ($strict) {
			return '';
		}
		
		$pi = \pathinfo($abs);
		$dir = (string)($pi['dirname'] ?? '');
		$base = (string)($pi['basename'] ?? '');
		$seg = \explode(DIRECTORY_SEPARATOR, \trim($dir, DIRECTORY_SEPARATOR));
		$tailDir = $seg === [] ? '' : $seg[\count($seg)-1];
		return '/' . $tailDir . '/' . $base;

	}

	/**
	 * Return the absolute path of a public path.
	 *
	 * Behavior:
	 * - Turns backslashes into slashes and removes every "..", so the result stays below
	 *   CITOMNI_PUBLIC_PATH.
	 * - Returns '' when CITOMNI_PUBLIC_PATH is undefined or empty.
	 *
	 * @param  string  $public  Public path, such as a stored column value.
	 * @return string
	 */
	private function fromPublicPath(string $public): string {
		$pub = \defined('CITOMNI_PUBLIC_PATH') ? (string)\constant('CITOMNI_PUBLIC_PATH') : '';
		if ($pub === '') { return ''; }
		$public = \str_replace('\\', '/', $public);
		$public = \str_replace('..', '', $public);
		return \rtrim($pub, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . \ltrim($public, '/');
	}


	/**
	 * Delete a file when it exists, best effort.
	 *
	 * @param  string|null  $abs  Absolute path; null and '' do nothing.
	 * @return void
	 */
	private function deleteIfFile(?string $abs): void {
		if (!$abs) { return; }
		if (\is_file($abs)) { @unlink($abs); }
	}


	/**
	 * Make a written file readable by everyone and writable by its owner (0644), best effort.
	 *
	 * @param  string  $abs  Absolute path.
	 * @return void
	 */
	private function tryChmodPublic(string $abs): void {
		@chmod($abs, 0644);
	}

}
