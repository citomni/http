<?php
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

// English language settings for Upload service
return [

	// Generic errors
	'err_missing_column_name' => 'Column name is required for deterministic cleanup.',
	'err_no_file'             => 'No file provided.',
	'err_empty_file'          => 'File is empty.',
	'err_too_large_bytes'     => 'File is too large.',
	'err_cfg_dir_missing'     => 'Upload directory is missing in configuration.',
	'err_invalid_mime'        => 'File type not allowed.',
	'err_upload_failed'       => 'File upload failed.',

	// Image-specific
	'err_invalid_image'       => 'Invalid image.',
	'err_too_large_megapixel' => 'Image exceeds megapixel limit.',
	'err_image_memory'        => 'Image is too large to process safely.',
	'err_write_failed'        => 'Failed to write file.',

	// Thumbnails
	'err_thumb_load'          => 'Failed to load image for thumbnail.',
	'err_thumb_wh'            => 'Invalid thumbnail width/height.',
	'err_thumb_process'       => 'Failed to process thumbnail.',
	'err_thumb_write'         => 'Failed to write thumbnail.',

	// Multi-attachments
	'err_max_count_reached'   => 'Maximum number of attachments reached.',

	// PHP upload error code mapping
	'err_partial'             => 'File was only partially uploaded.',
	'err_no_tmp'              => 'Missing a temporary folder.',
	'err_cant_write'          => 'Failed to write file to disk.',
	'err_blocked_ext'         => 'File upload stopped by extension.',

];
