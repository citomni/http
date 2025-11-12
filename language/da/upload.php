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

// Danish language settings for Upload service
return [

	// Generic errors
	'err_missing_column_name' => 'Kolonnenavn mangler for deterministisk oprydning.',
	'err_no_file'             => 'Ingen fil angivet.',
	'err_empty_file'          => 'Filen er tom.',
	'err_too_large_bytes'     => 'Filen er for stor.',
	'err_cfg_dir_missing'     => 'Upload-mappe mangler i konfigurationen.',
	'err_invalid_mime'        => 'Filtypen er ikke tilladt.',
	'err_upload_failed'       => 'Filupload mislykkedes.',

	// Image-specific
	'err_invalid_image'       => 'Ugyldigt billede.',
	'err_too_large_megapixel' => 'Billedet overskrider grænsen for megapixel.',
	'err_image_memory'        => 'Billedet er for stort til sikker behandling.',
	'err_write_failed'        => 'Kunne ikke skrive filen.',

	// Thumbnails
	'err_thumb_load'          => 'Kunne ikke indlæse billede til thumbnail.',
	'err_thumb_wh'            => 'Ugyldig thumbnail-bredde/højde.',
	'err_thumb_process'       => 'Thumbnail-behandling mislykkedes.',
	'err_thumb_write'         => 'Kunne ikke skrive thumbnail.',

	// Multi-attachments
	'err_max_count_reached'   => 'Maksimalt antal vedhæftninger er nået.',

	// PHP upload error code mapping
	'err_partial'             => 'Filen blev kun delvist uploadet.',
	'err_no_tmp'              => 'Midlertidig mappe mangler.',
	'err_cant_write'          => 'Kunne ikke skrive filen til disk.',
	'err_blocked_ext'         => 'Filupload blev stoppet af en udvidelse.',

];
