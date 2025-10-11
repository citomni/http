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




/**
 * CitOmni – Danish error texts per HTTP status code.
 *
 * Notes:
 * - Content text is Danish by design; inline comments are in English (project rule).
 * - Badge variants: warning for 4xx, danger for 5xx.
 * - Keep messages user-focused and actionable; always reference Fejl ID in help_text.
 */
return [

	'page_txt' => [
		'timestamp'			=> "Tidspunkt",
		'error_id'			=> "Fejl ID",
		'message'			=> "Fejlbesked",		
		'primary_label'		=> "Forsiden",		
		'secondary_label'	=> "Tilbage",
		'tertiary_label'	=> "Kontakt CitOmni",
		'quaternary_label'	=> "Opret issue på GitHub",
	],
	
	
	

	

	// --------------------
	// 4xx — Client errors
	// --------------------

	400 => [
		'meta_title'    => '400 | Ugyldig forespørgsel',
		'badge_variant' => 'badge--warning',
		'title'         => 'Ugyldig forespørgsel',
		'subtitle'      => 'Serveren kunne ikke forstå eller behandle din anmodning.',
		'lead_text'     => 'Kontrollér URL, parametre eller formularfelter og prøv igen.',
		'help_text'     => 'Hvis problemet fortsætter, kontakt support og oplys ovenstående Fejl ID.',
	],

	401 => [
		'meta_title'    => '401 | Login påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Login påkrævet',
		'subtitle'      => 'Denne ressource kræver godkendelse.',
		'lead_text'     => 'Log ind, eller angiv gyldige legitimationsoplysninger, og prøv igen.',
		'help_text'     => 'Mener du, at du burde have adgang? Kontakt support og oplys ovenstående Fejl ID.',
	],

	402 => [
		'meta_title'    => '402 | Betaling påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Betaling påkrævet',
		'subtitle'      => 'Adgang til denne ressource kræver betaling.',
		'lead_text'     => 'Fuldfør betaling og prøv igen.',
		'help_text'     => 'Har du spørgsmål til betaling, kontakt support og oplys ovenstående Fejl ID.',
	],

	403 => [
		'meta_title'    => '403 | Adgang nægtet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Adgang nægtet',
		'subtitle'      => 'Du har ikke tilladelse til at få adgang til denne ressource.',
		'lead_text'     => 'Bed en administrator om adgang, eller brug en anden konto.',
		'help_text'     => 'Hvis du mener, dette er en fejl, kontakt support og oplys ovenstående Fejl ID.',
	],

	// 404 => [
		// 'meta_title'    => '404 | Siden blev ikke fundet',
		// 'badge_variant' => 'badge--warning',
		// 'title'         => 'Siden blev ikke fundet',
		// 'subtitle'      => 'Systemet kunne desværre ikke finde den side, du leder efter.',
		// 'lead_text'     => 'Kontrollér adressen, brug et andet link, eller gå til forsiden.',
		// 'help_text'     => 'Fortsætter problemet, kontakt support og oplys ovenstående Fejl ID.',
	// ],
	
	404 => [
		"meta_title"	=> "404 | Siden blev ikke fundet",
		"badge_variant"	=> "badge--warning",
		"title"			=> "Siden blev ikke fundet",
		"subtitle"		=> "Systemet kunne desværre ikke finde den side, du leder efter.",
		"lead_text"		=> "Fejlen kan skyldes, at siden er flyttet/slettet, at linket er forkert, eller at du har indtastet adressen forkert.",
		"help_text"		=> "Prøv kontrollere at adressen er korrekt, prøv et andet link, eller gå til forsiden. Hvis problemet fortsætter, må du meget gerne kontakte vores support og oplys ovenstående Fejl ID.",
	],

	405 => [
		'meta_title'    => '405 | Metode ikke tilladt',
		'badge_variant' => 'badge--warning',
		'title'         => 'Metode ikke tilladt',
		'subtitle'      => 'HTTP-metoden er ikke tilladt for den ønskede ressource.',
		'lead_text'     => 'Skift metode (f.eks. GET/POST) eller brug et andet endpoint.',
		'help_text'     => 'I tvivl om korrekt metode? Kontakt support og oplys ovenstående Fejl ID.',
	],

	406 => [
		'meta_title'    => '406 | Ikke acceptabelt',
		'badge_variant' => 'badge--warning',
		'title'         => 'Ikke acceptabelt',
		'subtitle'      => 'Serveren kan ikke levere indhold i et format, der matcher Accept-kravene.',
		'lead_text'     => 'Juster Accept-header eller anmod om et andet format.',
		'help_text'     => 'Hvis du ikke kan ændre klienten, kontakt support og oplys ovenstående Fejl ID.',
	],

	407 => [
		'meta_title'    => '407 | Proxy-godkendelse påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Proxy-godkendelse påkrævet',
		'subtitle'      => 'Din anmodning skal godkendes af en proxy, før den kan fortsætte.',
		'lead_text'     => 'Godkend via proxyen og prøv igen.',
		'help_text'     => 'Hvis du ikke forventer en proxy, kontakt support og oplys ovenstående Fejl ID.',
	],

	408 => [
		'meta_title'    => '408 | Timeout for anmodning',
		'badge_variant' => 'badge--warning',
		'title'         => 'Anmodningen tog for lang tid',
		'subtitle'      => 'Forbindelsen blev lukket, fordi klienten var inaktiv for længe.',
		'lead_text'     => 'Prøv igen. Sørg for stabil netværksforbindelse.',
		'help_text'     => 'Ved gentagne timeouts, kontakt support og oplys ovenstående Fejl ID.',
	],

	409 => [
		'meta_title'    => '409 | Konflikt',
		'badge_variant' => 'badge--warning',
		'title'         => 'Konflikt',
		'subtitle'      => 'Handlingen kan ikke gennemføres på grund af en konflikt.',
		'lead_text'     => 'Opdater data og forsøg igen (undgå dobbelt indsendelse/konflikt).',
		'help_text'     => 'Hvis konflikten ikke kan løses, kontakt support og oplys ovenstående Fejl ID.',
	],

	410 => [
		'meta_title'    => '410 | Siden er fjernet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Siden er fjernet',
		'subtitle'      => 'Ressourcen findes ikke længere på serveren.',
		'lead_text'     => 'Gå til forsiden eller brug søgefunktionen.',
		'help_text'     => 'Har du brug for den tidligere side, kontakt support og oplys ovenstående Fejl ID.',
	],

	411 => [
		'meta_title'    => '411 | Content-Length påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Content-Length påkrævet',
		'subtitle'      => 'Anmodningen mangler påkrævet Content-Length-header.',
		'lead_text'     => 'Tilføj Content-Length og prøv igen.',
		'help_text'     => 'Har du spørgsmål til headers, kontakt support og oplys ovenstående Fejl ID.',
	],

	412 => [
		'meta_title'    => '412 | Forudsætning fejlede',
		'badge_variant' => 'badge--warning',
		'title'         => 'Forudsætning fejlede',
		'subtitle'      => 'En påkrævet forudsætning i anmodningen blev ikke opfyldt.',
		'lead_text'     => 'Juster If-* headers (fx If-Match) og prøv igen.',
		'help_text'     => 'I tvivl om forudsætninger? Kontakt support og oplys ovenstående Fejl ID.',
	],

	413 => [
		'meta_title'    => '413 | Indholdet er for stort',
		'badge_variant' => 'badge--warning',
		'title'         => 'Indholdet er for stort',
		'subtitle'      => 'Serveren afviser anmodningen, fordi nyttelasten er for stor.',
		'lead_text'     => 'Reducer filstørrelse eller volumen og prøv igen.',
		'help_text'     => 'Kontakt support, hvis grænsen skal hæves – oplys ovenstående Fejl ID.',
	],

	414 => [
		'meta_title'    => '414 | URL er for lang',
		'badge_variant' => 'badge--warning',
		'title'         => 'URL er for lang',
		'subtitle'      => 'Den anmodede adresse overskrider den tilladte længde.',
		'lead_text'     => 'Brug kortere URL eller flyt data til request body.',
		'help_text'     => 'Har du brug for længere grænser, kontakt support og oplys ovenstående Fejl ID.',
	],

	415 => [
		'meta_title'    => '415 | Medietype ikke understøttet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Medietype ikke understøttet',
		'subtitle'      => 'Serveren kan ikke håndtere Content-Type for denne anmodning.',
		'lead_text'     => 'Skift til en understøttet medietype og prøv igen.',
		'help_text'     => 'Usikker på understøttede typer? Kontakt support og oplys ovenstående Fejl ID.',
	],

	416 => [
		'meta_title'    => '416 | Ugyldigt Range-interval',
		'badge_variant' => 'badge--warning',
		'title'         => 'Ugyldigt Range-interval',
		'subtitle'      => 'Anmodet byteområde er uden for ressourcens størrelse.',
		'lead_text'     => 'Ret Range-header eller hent hele ressourcen.',
		'help_text'     => 'Kontakt support ved fortsatte problemer og oplys ovenstående Fejl ID.',
	],

	417 => [
		'meta_title'    => '417 | Expectation fejlede',
		'badge_variant' => 'badge--warning',
		'title'         => 'Expectation fejlede',
		'subtitle'      => 'Serveren kunne ikke opfylde Expect-headerens krav.',
		'lead_text'     => 'Fjern/ændr Expect-header og prøv igen.',
		'help_text'     => 'Kontakt support hvis du er i tvivl – oplys ovenstående Fejl ID.',
	],

	421 => [
		'meta_title'    => '421 | Forkert adresseret anmodning',
		'badge_variant' => 'badge--warning',
		'title'         => 'Forkert adresseret anmodning',
		'subtitle'      => 'Anmodningen blev sendt til en server, som ikke kan svare for denne authority/host.',
		'lead_text'     => 'Kontrollér Host/Authority og prøv igen.',
		'help_text'     => 'Fortsætter problemet, kontakt support og oplys ovenstående Fejl ID.',
	],

	422 => [
		'meta_title'    => '422 | Ugyldigt indhold',
		'badge_variant' => 'badge--warning',
		'title'         => 'Ugyldigt indhold',
		'subtitle'      => 'Serveren kunne ikke behandle indholdet (valideringsfejl).',
		'lead_text'     => 'Ret de markerede felter/parametre og prøv igen.',
		'help_text'     => 'Behøver du hjælp til valideringsreglerne, kontakt support og oplys ovenstående Fejl ID.',
	],

	423 => [
		'meta_title'    => '423 | Låst',
		'badge_variant' => 'badge--warning',
		'title'         => 'Ressourcen er låst',
		'subtitle'      => 'Ressourcen kan ikke ændres i øjeblikket.',
		'lead_text'     => 'Prøv igen senere eller frigør låsen.',
		'help_text'     => 'Kontakt support, hvis låsen virker fejlagtig – oplys ovenstående Fejl ID.',
	],

	424 => [
		'meta_title'    => '424 | Afhængighed fejlede',
		'badge_variant' => 'badge--warning',
		'title'         => 'Afhængighed fejlede',
		'subtitle'      => 'Anmodningen kunne ikke fuldføres pga. en mislykket afhængighed.',
		'lead_text'     => 'Løs afhængighedsfejlen og prøv igen.',
		'help_text'     => 'Kontakt support ved vedvarende fejl og oplys ovenstående Fejl ID.',
	],

	425 => [
		'meta_title'    => '425 | For tidligt',
		'badge_variant' => 'badge--warning',
		'title'         => 'For tidligt',
		'subtitle'      => 'Serveren afviser at behandle anmodningen endnu.',
		'lead_text'     => 'Vent et øjeblik og prøv igen (især ved gentagelser/tidlig transmission).',
		'help_text'     => 'Kontakt support ved fortsatte afvisninger og oplys ovenstående Fejl ID.',
	],

	426 => [
		'meta_title'    => '426 | Opgradering påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Opgradering påkrævet',
		'subtitle'      => 'Klient/protokol skal opgraderes for at fortsætte.',
		'lead_text'     => 'Skift til den krævede protokol/klient og prøv igen.',
		'help_text'     => 'Kontakt support for krav til opgradering og oplys ovenstående Fejl ID.',
	],

	428 => [
		'meta_title'    => '428 | Forudsætning påkrævet',
		'badge_variant' => 'badge--warning',
		'title'         => 'Forudsætning påkrævet',
		'subtitle'      => 'Denne anmodning kræver en forudsætning (fx If-Match).',
		'lead_text'     => 'Tilføj relevante If-* headers og prøv igen.',
		'help_text'     => 'Kontakt support hvis du er i tvivl – oplys ovenstående Fejl ID.',
	],

	429 => [
		'meta_title'    => '429 | For mange forespørgsler',
		'badge_variant' => 'badge--warning',
		'title'         => 'For mange forespørgsler',
		'subtitle'      => 'Du har sendt for mange forespørgsler på kort tid.',
		'lead_text'     => 'Vent et øjeblik og prøv igen. Undgå hurtige gentagne forsøg.',
		'help_text'     => 'Har du brug for højere grænser? Kontakt support og oplys ovenstående Fejl ID.',
	],

	431 => [
		'meta_title'    => '431 | Header-felter er for store',
		'badge_variant' => 'badge--warning',
		'title'         => 'Header-felter er for store',
		'subtitle'      => 'En eller flere request headers er for store.',
		'lead_text'     => 'Reducer antal/størrelse af headers og prøv igen.',
		'help_text'     => 'Kontakt support for header-grænser og oplys ovenstående Fejl ID.',
	],

	451 => [
		'meta_title'    => '451 | Utilgængelig af juridiske årsager',
		'badge_variant' => 'badge--warning',
		'title'         => 'Utilgængelig af juridiske årsager',
		'subtitle'      => 'Indholdet kan ikke vises pga. juridiske begrænsninger.',
		'lead_text'     => 'Kontakt indholdsudbyderen eller prøv igen senere.',
		'help_text'     => 'Spørgsmål? Kontakt support og oplys ovenstående Fejl ID.',
	],


	// --------------------
	// 5xx — Server errors
	// --------------------

	500 => [
		'meta_title'    => '500 | Intern serverfejl',
		'badge_variant' => 'badge--danger',
		'title'         => 'Intern serverfejl',
		'subtitle'      => 'Der opstod en uventet fejl i systemet.',
		'lead_text'     => 'Det er ikke dig – det er os. Prøv igen om lidt.',
		'help_text'     => 'Fortsætter problemet, kontakt support og oplys ovenstående Fejl ID.',
	],

	501 => [
		'meta_title'    => '501 | Ikke implementeret',
		'badge_variant' => 'badge--danger',
		'title'         => 'Ikke implementeret',
		'subtitle'      => 'Serveren understøtter ikke den ønskede funktion.',
		'lead_text'     => 'Brug et andet endpoint eller metode.',
		'help_text'     => 'Kontakt support om roadmap/alternativer og oplys ovenstående Fejl ID.',
	],

	502 => [
		'meta_title'    => '502 | Forkert gateway',
		'badge_variant' => 'badge--danger',
		'title'         => 'Forkert gateway',
		'subtitle'      => 'En upstream-tjeneste returnerede et ugyldigt svar.',
		'lead_text'     => 'Problemet er ofte midlertidigt. Prøv igen om lidt.',
		'help_text'     => 'Fortsætter problemet, kontakt support og oplys ovenstående Fejl ID.',
	],

	503 => [
		'meta_title'    => '503 | Tjenesten er midlertidigt utilgængelig',
		'badge_variant' => 'badge--danger',
		'title'         => 'Tjenesten er midlertidigt utilgængelig',
		'subtitle'      => 'Typisk pga. vedligeholdelse eller høj belastning.',
		'lead_text'     => 'Prøv igen senere. Vi arbejder på at genskabe normal drift.',
		'help_text'     => 'Ved langvarig nedetid, kontakt support og oplys ovenstående Fejl ID.',
	],

	504 => [
		'meta_title'    => '504 | Gateway-timeout',
		'badge_variant' => 'badge--danger',
		'title'         => 'Gateway-timeout',
		'subtitle'      => 'En upstream-tjeneste svarede ikke i tide.',
		'lead_text'     => 'Opdater siden eller prøv igen senere.',
		'help_text'     => 'Fortsætter fejlen, kontakt support og oplys ovenstående Fejl ID.',
	],

	505 => [
		'meta_title'    => '505 | HTTP-version ikke understøttet',
		'badge_variant' => 'badge--danger',
		'title'         => 'HTTP-version ikke understøttet',
		'subtitle'      => 'Serveren understøtter ikke den brugte HTTP-version.',
		'lead_text'     => 'Opgrader din klient/HTTP-stack og prøv igen.',
		'help_text'     => 'Kontakt support for krav til versioner og oplys ovenstående Fejl ID.',
	],

	506 => [
		'meta_title'    => '506 | Variant forhandler selv',
		'badge_variant' => 'badge--danger',
		'title'         => 'Variant forhandler selv',
		'subtitle'      => 'Konfigurationsfejl ved indholdsforhandling.',
		'lead_text'     => 'Prøv igen senere.',
		'help_text'     => 'Kontakt support – oplys ovenstående Fejl ID.',
	],

	507 => [
		'meta_title'    => '507 | Utilstrækkelig lagerplads',
		'badge_variant' => 'badge--danger',
		'title'         => 'Utilstrækkelig lagerplads',
		'subtitle'      => 'Serveren kan ikke fuldføre anmodningen pga. pladsmangel.',
		'lead_text'     => 'Prøv igen senere.',
		'help_text'     => 'Kontakt support hvis fejlen fortsætter og oplys ovenstående Fejl ID.',
	],

	508 => [
		'meta_title'    => '508 | Loop opdaget',
		'badge_variant' => 'badge--danger',
		'title'         => 'Loop opdaget',
		'subtitle'      => 'Serveren detekterede et uendeligt loop i behandlingen.',
		'lead_text'     => 'Prøv igen senere.',
		'help_text'     => 'Kontakt support og oplys ovenstående Fejl ID.',
	],

	510 => [
		'meta_title'    => '510 | Ikke udvidet',
		'badge_variant' => 'badge--danger',
		'title'         => 'Ikke udvidet',
		'subtitle'      => 'Anmodningen kræver yderligere udvidelser.',
		'lead_text'     => 'Tilføj påkrævede udvidelser og prøv igen.',
		'help_text'     => 'Kontakt support for detaljer og oplys ovenstående Fejl ID.',
	],

	511 => [
		'meta_title'    => '511 | Netværksgodkendelse påkrævet',
		'badge_variant' => 'badge--danger',
		'title'         => 'Netværksgodkendelse påkrævet',
		'subtitle'      => 'Adgang til netværket kræver godkendelse (fx captive portal).',
		'lead_text'     => 'Godkend på netværket og prøv igen.',
		'help_text'     => 'Hvis du ikke forventer dette, kontakt support og oplys ovenstående Fejl ID.',
	],


	// ---------------------------------------
	// De facto / non-standard (optional)
	// ---------------------------------------

	418 => [ // "I'm a teapot" (RFC 2324/7168 joke; often disabled in prod)
		'meta_title'    => '418 | Jeg er en tekande',
		'badge_variant' => 'badge--warning',
		'title'         => 'Jeg er en tekande',
		'subtitle'      => 'Serveren kan ikke brygge kaffe, fordi den er en tekande.',
		'lead_text'     => 'Prøv med en anden ressource.',
		'help_text'     => 'Kontakt support hvis dette ikke var med vilje – oplys ovenstående Fejl ID.',
	],

	509 => [ // Bandwidth Limit Exceeded (commonly seen on some stacks; not IANA standard)
		'meta_title'    => '509 | Båndbreddegrænse overskredet',
		'badge_variant' => 'badge--danger',
		'title'         => 'Båndbreddegrænse overskredet',
		'subtitle'      => 'Ressourcens båndbreddekvote er brugt op.',
		'lead_text'     => 'Prøv igen senere.',
		'help_text'     => 'Kontakt support for status og oplys ovenstående Fejl ID.',
	],

	599 => [ // Network Connect Timeout Error (de facto; some proxies)
		'meta_title'    => '599 | Netværkstimeout',
		'badge_variant' => 'badge--danger',
		'title'         => 'Netværkstimeout',
		'subtitle'      => 'En mellemled/proxy rapporterede timeout ved opkobling.',
		'lead_text'     => 'Prøv igen senere.',
		'help_text'     => 'Fortsætter problemet, kontakt support og oplys ovenstående Fejl ID.',
	],

];