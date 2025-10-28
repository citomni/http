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
 * CitOmni – English error texts per HTTP status code.
 *
 * Notes:
 * - Content text is English by design; inline comments are in English (project rule).
 * - Badge variants: warning for 4xx, danger for 5xx.
 * - Keep messages user-focused and actionable; always reference Error ID in help_text.
 */
return [

	'page_txt' => [
		'timestamp'         => "Timestamp",
		'error_id'          => "Error ID",
		'message'           => "Error message",
		'primary_label'     => "Homepage",
		'secondary_label'   => "Back",
		'tertiary_label'    => "Contact CitOmni",
		'quaternary_label'  => "Create issue on GitHub",
	],


	// --------------------
	// 4xx — Client errors
	// --------------------

	400 => [
		'meta_title'    => '400 | Bad request',
		'badge_variant' => 'badge--warning',
		'title'         => 'Bad request',
		'subtitle'      => 'The server could not understand or process your request.',
		'lead_text'     => 'Check the URL, parameters, or form fields and try again.',
		'help_text'     => 'If the issue persists, contact support and provide the above Error ID.',
	],

	401 => [
		'meta_title'    => '401 | Login required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Login required',
		'subtitle'      => 'This resource requires authentication.',
		'lead_text'     => 'Sign in or provide valid credentials and try again.',
		'help_text'     => 'Do you believe you should have access? Contact support and provide the above Error ID.',
	],

	402 => [
		'meta_title'    => '402 | Payment required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Payment required',
		'subtitle'      => 'Access to this resource requires payment.',
		'lead_text'     => 'Complete the payment and try again.',
		'help_text'     => 'If you have questions about payment, contact support and provide the above Error ID.',
	],

	403 => [
		'meta_title'    => '403 | Access denied',
		'badge_variant' => 'badge--warning',
		'title'         => 'Access denied',
		'subtitle'      => 'You do not have permission to access this resource.',
		'lead_text'     => 'Ask an administrator for access or use a different account.',
		'help_text'     => 'If you believe this is an error, contact support and provide the above Error ID.',
	],

	// 404 => [
		// 'meta_title'    => '404 | Page not found',
		// 'badge_variant' => 'badge--warning',
		// 'title'         => 'Page not found',
		// 'subtitle'      => 'The system could not find the page you are looking for.',
		// 'lead_text'     => 'Check the address, use a different link, or go to the homepage.',
		// 'help_text'     => 'If the issue continues, contact support and provide the above Error ID.',
	// ],

	404 => [
		"meta_title"    => "404 | Page not found",
		"badge_variant" => "badge--warning",
		"title"         => "Page not found",
		"subtitle"      => "The system could not find the page you are looking for.",
		"lead_text"     => "The error may be caused by the page being moved/removed, an incorrect link, or a mistyped address.",
		"help_text"     => "Please check that the address is correct, try another link, or go to the homepage. If the problem continues, please contact our support and provide the above Error ID.",
	],

	405 => [
		'meta_title'    => '405 | Method not allowed',
		'badge_variant' => 'badge--warning',
		'title'         => 'Method not allowed',
		'subtitle'      => 'The HTTP method is not allowed for the requested resource.',
		'lead_text'     => 'Switch method (e.g., GET/POST) or use a different endpoint.',
		'help_text'     => 'Unsure about the correct method? Contact support and provide the above Error ID.',
	],

	406 => [
		'meta_title'    => '406 | Not acceptable',
		'badge_variant' => 'badge--warning',
		'title'         => 'Not acceptable',
		'subtitle'      => 'The server cannot serve content in a format matching the Accept requirements.',
		'lead_text'     => 'Adjust the Accept header or request a different format.',
		'help_text'     => 'If you cannot change the client, contact support and provide the above Error ID.',
	],

	407 => [
		'meta_title'    => '407 | Proxy authentication required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Proxy authentication required',
		'subtitle'      => 'Your request must be authenticated by a proxy before it can proceed.',
		'lead_text'     => 'Authenticate via the proxy and try again.',
		'help_text'     => 'If you do not expect a proxy, contact support and provide the above Error ID.',
	],

	408 => [
		'meta_title'    => '408 | Request timeout',
		'badge_variant' => 'badge--warning',
		'title'         => 'The request took too long',
		'subtitle'      => 'The connection was closed because the client was inactive for too long.',
		'lead_text'     => 'Try again. Ensure a stable network connection.',
		'help_text'     => 'For repeated timeouts, contact support and provide the above Error ID.',
	],

	409 => [
		'meta_title'    => '409 | Conflict',
		'badge_variant' => 'badge--warning',
		'title'         => 'Conflict',
		'subtitle'      => 'The action cannot be completed due to a conflict.',
		'lead_text'     => 'Refresh data and try again (avoid duplicate submission/conflict).',
		'help_text'     => 'If the conflict cannot be resolved, contact support and provide the above Error ID.',
	],

	410 => [
		'meta_title'    => '410 | Page removed',
		'badge_variant' => 'badge--warning',
		'title'         => 'Page removed',
		'subtitle'      => 'The resource is no longer available on the server.',
		'lead_text'     => 'Go to the homepage or use search.',
		'help_text'     => 'If you need the previous page, contact support and provide the above Error ID.',
	],

	411 => [
		'meta_title'    => '411 | Content-Length required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Content-Length required',
		'subtitle'      => 'The request is missing the required Content-Length header.',
		'lead_text'     => 'Add Content-Length and try again.',
		'help_text'     => 'Questions about headers? Contact support and provide the above Error ID.',
	],

	412 => [
		'meta_title'    => '412 | Precondition failed',
		'badge_variant' => 'badge--warning',
		'title'         => 'Precondition failed',
		'subtitle'      => 'A required precondition in the request was not met.',
		'lead_text'     => 'Adjust If-* headers (e.g., If-Match) and try again.',
		'help_text'     => 'Unsure about preconditions? Contact support and provide the above Error ID.',
	],

	413 => [
		'meta_title'    => '413 | Payload too large',
		'badge_variant' => 'badge--warning',
		'title'         => 'Payload too large',
		'subtitle'      => 'The server rejects the request because the payload is too large.',
		'lead_text'     => 'Reduce file size or volume and try again.',
		'help_text'     => 'Contact support if the limit needs to be increased — provide the above Error ID.',
	],

	414 => [
		'meta_title'    => '414 | URL too long',
		'badge_variant' => 'badge--warning',
		'title'         => 'URL too long',
		'subtitle'      => 'The requested address exceeds the allowed length.',
		'lead_text'     => 'Use a shorter URL or move data to the request body.',
		'help_text'     => 'Need higher limits? Contact support and provide the above Error ID.',
	],

	415 => [
		'meta_title'    => '415 | Unsupported media type',
		'badge_variant' => 'badge--warning',
		'title'         => 'Unsupported media type',
		'subtitle'      => 'The server cannot handle the request’s Content-Type.',
		'lead_text'     => 'Switch to a supported media type and try again.',
		'help_text'     => 'Unsure which types are supported? Contact support and provide the above Error ID.',
	],

	416 => [
		'meta_title'    => '416 | Invalid Range',
		'badge_variant' => 'badge--warning',
		'title'         => 'Invalid Range',
		'subtitle'      => 'The requested byte range is outside the resource size.',
		'lead_text'     => 'Correct the Range header or fetch the entire resource.',
		'help_text'     => 'Contact support if the problem continues and provide the above Error ID.',
	],

	417 => [
		'meta_title'    => '417 | Expectation failed',
		'badge_variant' => 'badge--warning',
		'title'         => 'Expectation failed',
		'subtitle'      => 'The server could not meet the requirements from the Expect header.',
		'lead_text'     => 'Remove/alter the Expect header and try again.',
		'help_text'     => 'Contact support if you are unsure — provide the above Error ID.',
	],

	421 => [
		'meta_title'    => '421 | Misdirected request',
		'badge_variant' => 'badge--warning',
		'title'         => 'Misdirected request',
		'subtitle'      => 'The request was sent to a server that cannot answer for this authority/host.',
		'lead_text'     => 'Check Host/Authority and try again.',
		'help_text'     => 'If the issue persists, contact support and provide the above Error ID.',
	],

	422 => [
		'meta_title'    => '422 | Unprocessable content',
		'badge_variant' => 'badge--warning',
		'title'         => 'Unprocessable content',
		'subtitle'      => 'The server could not process the content (validation error).',
		'lead_text'     => 'Fix the highlighted fields/parameters and try again.',
		'help_text'     => 'Need help with validation rules? Contact support and provide the above Error ID.',
	],

	423 => [
		'meta_title'    => '423 | Locked',
		'badge_variant' => 'badge--warning',
		'title'         => 'Resource is locked',
		'subtitle'      => 'The resource cannot be modified right now.',
		'lead_text'     => 'Try again later or release the lock.',
		'help_text'     => 'Contact support if the lock seems erroneous — provide the above Error ID.',
	],

	424 => [
		'meta_title'    => '424 | Failed dependency',
		'badge_variant' => 'badge--warning',
		'title'         => 'Failed dependency',
		'subtitle'      => 'The request could not be completed due to a failed dependency.',
		'lead_text'     => 'Resolve the dependency error and try again.',
		'help_text'     => 'Contact support if the error persists and provide the above Error ID.',
	],

	425 => [
		'meta_title'    => '425 | Too early',
		'badge_variant' => 'badge--warning',
		'title'         => 'Too early',
		'subtitle'      => 'The server refuses to process the request yet.',
		'lead_text'     => 'Wait a moment and try again (especially for retries/early transmission).',
		'help_text'     => 'Contact support if refusals continue and provide the above Error ID.',
	],

	426 => [
		'meta_title'    => '426 | Upgrade required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Upgrade required',
		'subtitle'      => 'Client/protocol must be upgraded to continue.',
		'lead_text'     => 'Switch to the required protocol/client and try again.',
		'help_text'     => 'Contact support for upgrade requirements and provide the above Error ID.',
	],

	428 => [
		'meta_title'    => '428 | Precondition required',
		'badge_variant' => 'badge--warning',
		'title'         => 'Precondition required',
		'subtitle'      => 'This request requires a precondition (e.g., If-Match).',
		'lead_text'     => 'Add the relevant If-* headers and try again.',
		'help_text'     => 'Contact support if you are unsure — provide the above Error ID.',
	],

	429 => [
		'meta_title'    => '429 | Too many requests',
		'badge_variant' => 'badge--warning',
		'title'         => 'Too many requests',
		'subtitle'      => 'You have sent too many requests in a short time.',
		'lead_text'     => 'Wait a moment and try again. Avoid rapid repeated attempts.',
		'help_text'     => 'Need higher limits? Contact support and provide the above Error ID.',
	],

	431 => [
		'meta_title'    => '431 | Request header fields too large',
		'badge_variant' => 'badge--warning',
		'title'         => 'Request header fields too large',
		'subtitle'      => 'One or more request headers are too large.',
		'lead_text'     => 'Reduce the number/size of headers and try again.',
		'help_text'     => 'Contact support for header limits and provide the above Error ID.',
	],

	451 => [
		'meta_title'    => '451 | Unavailable for legal reasons',
		'badge_variant' => 'badge--warning',
		'title'         => 'Unavailable for legal reasons',
		'subtitle'      => 'The content cannot be displayed due to legal restrictions.',
		'lead_text'     => 'Contact the content provider or try again later.',
		'help_text'     => 'Questions? Contact support and provide the above Error ID.',
	],


	// --------------------
	// 5xx — Server errors
	// --------------------

	500 => [
		'meta_title'    => '500 | Internal server error',
		'badge_variant' => 'badge--danger',
		'title'         => 'Internal server error',
		'subtitle'      => 'An unexpected error occurred in the system.',
		'lead_text'     => 'It’s not you — it’s us. Please try again shortly.',
		'help_text'     => 'If the issue continues, contact support and provide the above Error ID.',
	],

	501 => [
		'meta_title'    => '501 | Not implemented',
		'badge_variant' => 'badge--danger',
		'title'         => 'Not implemented',
		'subtitle'      => 'The server does not support the requested functionality.',
		'lead_text'     => 'Use a different endpoint or method.',
		'help_text'     => 'Contact support about the roadmap/alternatives and provide the above Error ID.',
	],

	502 => [
		'meta_title'    => '502 | Bad gateway',
		'badge_variant' => 'badge--danger',
		'title'         => 'Bad gateway',
		'subtitle'      => 'An upstream service returned an invalid response.',
		'lead_text'     => 'The problem is often temporary. Try again shortly.',
		'help_text'     => 'If the issue persists, contact support and provide the above Error ID.',
	],

	503 => [
		'meta_title'    => '503 | Service temporarily unavailable',
		'badge_variant' => 'badge--danger',
		'title'         => 'Service temporarily unavailable',
		'subtitle'      => 'Typically due to maintenance or high load.',
		'lead_text'     => 'Try again later. We are working to restore normal operations.',
		'help_text'     => 'For extended downtime, contact support and provide the above Error ID.',
	],

	504 => [
		'meta_title'    => '504 | Gateway timeout',
		'badge_variant' => 'badge--danger',
		'title'         => 'Gateway timeout',
		'subtitle'      => 'An upstream service did not respond in time.',
		'lead_text'     => 'Reload the page or try again later.',
		'help_text'     => 'If the error continues, contact support and provide the above Error ID.',
	],

	505 => [
		'meta_title'    => '505 | HTTP version not supported',
		'badge_variant' => 'badge--danger',
		'title'         => 'HTTP version not supported',
		'subtitle'      => 'The server does not support the HTTP version used.',
		'lead_text'     => 'Upgrade your client/HTTP stack and try again.',
		'help_text'     => 'Contact support for version requirements and provide the above Error ID.',
	],

	506 => [
		'meta_title'    => '506 | Variant also negotiates',
		'badge_variant' => 'badge--danger',
		'title'         => 'Variant also negotiates',
		'subtitle'      => 'Configuration error in content negotiation.',
		'lead_text'     => 'Please try again later.',
		'help_text'     => 'Contact support — provide the above Error ID.',
	],

	507 => [
		'meta_title'    => '507 | Insufficient storage',
		'badge_variant' => 'badge--danger',
		'title'         => 'Insufficient storage',
		'subtitle'      => 'The server cannot complete the request due to lack of space.',
		'lead_text'     => 'Please try again later.',
		'help_text'     => 'Contact support if the error continues and provide the above Error ID.',
	],

	508 => [
		'meta_title'    => '508 | Loop detected',
		'badge_variant' => 'badge--danger',
		'title'         => 'Loop detected',
		'subtitle'      => 'The server detected an infinite loop during processing.',
		'lead_text'     => 'Please try again later.',
		'help_text'     => 'Contact support and provide the above Error ID.',
	],

	510 => [
		'meta_title'    => '510 | Not extended',
		'badge_variant' => 'badge--danger',
		'title'         => 'Not extended',
		'subtitle'      => 'The request requires additional extensions.',
		'lead_text'     => 'Add the required extensions and try again.',
		'help_text'     => 'Contact support for details and provide the above Error ID.',
	],

	511 => [
		'meta_title'    => '511 | Network authentication required',
		'badge_variant' => 'badge--danger',
		'title'         => 'Network authentication required',
		'subtitle'      => 'Access to the network requires authentication (e.g., captive portal).',
		'lead_text'     => 'Authenticate on the network and try again.',
		'help_text'     => 'If you do not expect this, contact support and provide the above Error ID.',
	],


	// ---------------------------------------
	// De facto / non-standard (optional)
	// ---------------------------------------

	418 => [ // "I'm a teapot" (RFC 2324/7168 joke; often disabled in prod)
		'meta_title'    => '418 | I am a teapot',
		'badge_variant' => 'badge--warning',
		'title'         => 'I am a teapot',
		'subtitle'      => 'The server cannot brew coffee because it is a teapot.',
		'lead_text'     => 'Try a different resource.',
		'help_text'     => 'Contact support if this was not intentional — provide the above Error ID.',
	],

	509 => [ // Bandwidth Limit Exceeded (commonly seen on some stacks; not IANA standard)
		'meta_title'    => '509 | Bandwidth limit exceeded',
		'badge_variant' => 'badge--danger',
		'title'         => 'Bandwidth limit exceeded',
		'subtitle'      => 'The resource’s bandwidth quota has been used up.',
		'lead_text'     => 'Please try again later.',
		'help_text'     => 'Contact support for status and provide the above Error ID.',
	],

	599 => [ // Network Connect Timeout Error (de facto; some proxies)
		'meta_title'    => '599 | Network timeout',
		'badge_variant' => 'badge--danger',
		'title'         => 'Network timeout',
		'subtitle'      => 'An intermediary/proxy reported a timeout during connection.',
		'lead_text'     => 'Please try again later.',
		'help_text'     => 'If the issue persists, contact support and provide the above Error ID.',
	],

];
