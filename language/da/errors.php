<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */




// Errors language settings
return [

	// 400 Bad Request
	'400_meta_title' => '400 | Ugyldig forespørgsel | CitOmni',
	'400_meta_description' => 'Forespørgslen kunne ikke forstås eller var ugyldig.',
	'400_meta_keywords' => '400, ugyldig forespørgsel, fejl, bad request',
	'400_header' => '400: Ugyldig forespørgsel',
	'400_bodytext' => 'Ups! Din forespørgsel kunne ikke behandles, fordi den var ugyldig.<br>
						Tjek venligst linket eller prøv igen.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 401 Unauthorized
	'401_meta_title' => '401 | Ikke autoriseret | CitOmni',
	'401_meta_description' => 'Du skal være logget ind for at få adgang til denne side.',
	'401_meta_keywords' => '401, ikke autoriseret, login påkrævet, adgang nægtet',
	'401_header' => '401: Ikke autoriseret',
	'401_bodytext' => 'Du skal logge ind for at se denne side.<br>
						Log venligst ind og prøv igen.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '/login.html" class="btn">Login</a>',

	// 403 Forbidden
	'403_meta_title' => '403 | Adgang nægtet | CitOmni',
	'403_meta_description' => 'Du har ikke tilladelse til at få adgang til denne side.',
	'403_meta_keywords' => '403, adgang nægtet, forbidden, ingen adgang',
	'403_header' => '403: Adgang nægtet',
	'403_bodytext' => 'Beklager, du har ikke adgang til denne side.<br>
						Hvis du mener, det er en fejl, kontakt venligst support.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 404 Not Found
	'404_meta_title' => '404 | Siden blev ikke fundet | CitOmni',
	'404_meta_description' => 'Siden blev ikke fundet. Linket er måske forkert eller siden er flyttet. Gå tilbage eller besøg vores forside for at finde det, du søger.',
	'404_meta_keywords' => '404, side ikke fundet, fejl, link virker ikke, dødt link, manglende side, forsvundet side, fejlmeddelelse, broken link',
	'404_header' => '404: Siden blev ikke fundet',
	'404_bodytext' => 'Ups! Den side, du leder efter, findes desværre ikke længere eller er blevet flyttet.<br>
						Det ser ud til, at den side, du leder efter, ikke findes. Måske er linket forkert, eller siden er blevet flyttet.<br>
						Hvis du skrev webadressen manuelt, så tjek venligst, om der er en tastefejl.<br>						
						Har du fulgt et link på vores side og endt her ved en fejl? Så lad os gerne vide det, så vi kan rette det.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',
						

	// 405 Method Not Allowed
	'405_meta_title' => '405 | Metoden er ikke tilladt | CitOmni',
	'405_meta_description' => 'Forespørgselsmetoden understøttes ikke for denne side.',
	'405_meta_keywords' => '405, metode ikke tilladt, fejl',
	'405_header' => '405: Metoden er ikke tilladt',
	'405_bodytext' => 'Den metode, du har brugt til at tilgå siden, er ikke tilladt.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 408 Request Timeout
	'408_meta_title' => '408 | Timeout | CitOmni',
	'408_meta_description' => 'Serveren ventede for længe på din forespørgsel.',
	'408_meta_keywords' => '408, timeout, forespørgsel udløb, fejl',
	'408_header' => '408: Timeout',
	'408_bodytext' => 'Din forespørgsel tog for lang tid, og serveren afbrød.<br>
						Prøv venligst igen.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 409 Conflict
	'409_meta_title' => '409 | Konflikt | CitOmni',
	'409_meta_description' => 'Der opstod en konflikt under behandlingen af din forespørgsel.',
	'409_meta_keywords' => '409, konflikt, fejl',
	'409_header' => '409: Konflikt',
	'409_bodytext' => 'Din forespørgsel kunne ikke gennemføres pga. en konflikt.<br>
						Prøv venligst at opdatere siden og prøve igen.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 410 Gone
	'410_meta_title' => '410 | Siden er fjernet | CitOmni',
	'410_meta_description' => 'Den ønskede ressource er ikke længere tilgængelig.',
	'410_meta_keywords' => '410, fjernet, mangler, ressource slettet',
	'410_header' => '410: Siden er fjernet',
	'410_bodytext' => 'Siden, du prøver at tilgå, er permanent fjernet.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 429 Too Many Requests
	'429_meta_title' => '429 | For mange forespørgsler | CitOmni',
	'429_meta_description' => 'Du har sendt for mange forespørgsler på kort tid.',
	'429_meta_keywords' => '429, for mange forespørgsler, rate limit, fejl',
	'429_header' => '429: For mange forespørgsler',
	'429_bodytext' => 'Du har lavet for mange forespørgsler på kort tid.<br>
						Vent venligst lidt og prøv igen.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 500 Internal Server Error
	'500_meta_title' => '500 | Intern serverfejl | CitOmni',
	'500_meta_description' => 'Serveren stødte på en uventet fejl.',
	'500_meta_keywords' => '500, intern serverfejl, fejl',
	'500_header' => '500: Intern serverfejl',
	'500_bodytext' => 'Ups! Noget gik galt på vores side.<br>
						Prøv igen senere.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 502 Bad Gateway
	'502_meta_title' => '502 | Ugyldigt svar fra server | CitOmni',
	'502_meta_description' => 'Serveren modtog et ugyldigt svar fra en anden server.',
	'502_meta_keywords' => '502, bad gateway, fejl',
	'502_header' => '502: Ugyldigt svar fra server',
	'502_bodytext' => 'Serveren modtog et ugyldigt svar.<br>
						Prøv igen senere.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 503 Service Unavailable
	'503_meta_title' => '503 | Tjenesten er utilgængelig | CitOmni',
	'503_meta_description' => 'Serveren kan midlertidigt ikke håndtere forespørgslen.',
	'503_meta_keywords' => '503, tjeneste utilgængelig, vedligeholdelse, fejl',
	'503_header' => '503: Tjenesten er utilgængelig',
	'503_bodytext' => 'Vores tjeneste er midlertidigt utilgængelig.<br>
						Prøv venligst igen senere.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

	// 504 Gateway Timeout
	'504_meta_title' => '504 | Timeout fra server | CitOmni',
	'504_meta_description' => 'Serveren modtog ikke svar i tide fra en anden server.',
	'504_meta_keywords' => '504, gateway timeout, fejl',
	'504_header' => '504: Timeout fra server',
	'504_bodytext' => 'Serveren svarede ikke i tide.<br>
						Prøv venligst igen senere.<br>
						<a href="' . CITOMNI_PUBLIC_ROOT_URL. '" class="btn">Gå til forsiden</a>',

];