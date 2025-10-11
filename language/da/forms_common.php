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





// Common messages for FORMS 
return [
		
		// CSRF
		'err_csrf_invalid_token' => 'Ugyldig token. Prøv venligst igen.',
		
		// Captcha
		'err_incomplete_form_captcha' => 'Udfyld venligst hele formularen og indtast captcha-koden.',
		'err_incorrect_captcha' => 'Captcha-koden var desværre forkert. Prøv venligst igen.',
		
		// Honeypot
		'err_honeypot_not_empty' => 'Din forespørgsel kunne ikke sendes, da der opstod en fejl. Hvis du er en ægte bruger, bedes du prøve igen eller kontakte os direkte.',
		
		// Database connection/query errors
		'err_db_connection' => 'Der opstod fejl i forbindelsen til databasen. Prøv venligst igen eller kontakt os.',
		'err_db_query' => 'Der opstod fejl i forespørgslen til databasen. Prøv venligst igen eller kontakt os.',

];
