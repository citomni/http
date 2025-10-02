<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * Public-facing pages (home and generic error pages).
 *
 * Lean controller intended for minimal startup logic and simple view rendering.
 * Templates + layer are provided via route config. No "magic", no global state.
 */
class PublicController extends BaseController {
	
	

/*
 *------------------------------------------------------------------
 * BASIC START-UP
 *------------------------------------------------------------------
 * The common fundamentals that are required for all public pages. 
 * 
 */

	/**
	 * Lightweight initialization for public routes.
	 *
	 * Keep this fast and side-effect free; heavy lifting belongs in services.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Do start-up stuff
	}





/*
 *------------------------------------------------------------------
 * PUBLIC PAGES
 *------------------------------------------------------------------
 * 
 */


	/**
	 * Displays the public home page (root url).
	 *
	 * Renders the main public landing page using the configured template and template layer.
	 * Passes login status and other view data to the template.
	 *
	 * @return void
	 */
	public function index(): void {

// $this->app->errorHandler->httpError(404, [
    // 'title'   => 'Siden blev ikke fundet',
    // 'message' => 'Kontrollér adressen eller gå til forsiden.',
// ]);



		$details = \json_encode([
				'citomni' => [
					'mode' => 'http',
					'environment' => CITOMNI_ENVIRONMENT,
				],
				'metrics' => [
					'time_s' => ((float) \sprintf('%.3f', ((($nowNs=\hrtime(true))) - (\defined('CITOMNI_START_NS') ? (int)\CITOMNI_START_NS : $nowNs)) / 1_000_000_000)),
					// 'mem_peak_kb' => (int)\round(\memory_get_peak_usage(true) / 1024),
					'memory_usage_current_kb:' => (int)\round(\memory_get_usage() / 1024),
					'memory_usage_peak_kb' => (int)\round(\memory_get_peak_usage() / 1024),
				],
				'opcache' => [
					'enabled' => (bool)\filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL),
					'validate_timestamps' => \ini_get('opcache.validate_timestamps') !== '0',
				],
			], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);


		// Render the home page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' 				=> 0,
			
			// Canonical URL
			'canonical' 			=> \CITOMNI_PUBLIC_ROOT_URL,
			
			'meta_title'       		=> 'Installed successfully',
			'meta_description' 		=> 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'       		=> 'READY',
			'badge_variant'    		=> 'badge--success', // green
			'title'            		=> 'Installation complete',
			'subtitle'         		=> 'CitOmni HTTP is up and running.',
			// 'lead_text'        		=> 'You are all set. CitOmni is ready for your development. Update your routes to get started.',
			'lead_text'        		=> 'Green lights across the board. Wire up your routes and let’s make something fast!',
			'status_code'      		=> '200',
			'status_text'      		=> 'OK',
		    'http_method'           => $_SERVER['REQUEST_METHOD'] ?? 'GET',
		    'request_path'          => $_SERVER['REQUEST_URI'] ?? '/',
		    'details_preformatted'  => $details ?? 'Hello, CitOmni. Let’s build something fast.',
		    'primary_href'          => 'https://github.com/citomni/http#readme',
		    'primary_target'		=> '_blank',
		    'primary_label'         => 'Open README',
		    'secondary_href'        => 'https://github.com/citomni/http/releases',
		    'secondary_target'		=> '_blank',
		    'secondary_label'       => 'Changelog',
		    'tertiary_href'			=> 'https://github.com/citomni/http/issues/new/choose',
		    'tertiary_target'		=> '_blank', // _self
		    'tertiary_label'		=> 'Report issue',
		    'year'                  => date('Y'),
		    'owner'                 => 'CitOmni.com',
			
			
			// User login status (left commented to avoid hard deps)
			// 'is_loggedin' => is_object($this->user_account) && $this->user_account->isLoggedin(),
		]);
	}



	/**
	 * Display an error page for a given HTTP status code.
	 *
	 * Sets the response status code, logs the event with request context,
	 * and renders the configured error template. For 500, includes recent
	 * PHP errors from ErrorHandler when available.
	 *
	 * @param int $statusCode HTTP status code (e.g., 403, 404, 405, 500).
	 * @return void
	 */
	public function errorPage(int $statusCode = 500): void {
		
		// Set the HTTP status code sent to the client; determines how browsers and APIs interpret the response (e.g., 404 = Not Found, 500 = Server Error)
		\http_response_code($statusCode);
		
		// For 500 errors, fetch errors from ErrorHandler for detailed logging/view
		$errors = null;
		if ($statusCode === 500 && \class_exists('\CitOmni\Http\Exception\ErrorHandler')) {
			$tmp    = \CitOmni\Http\Exception\ErrorHandler::getErrors();
			$errors = !empty($tmp) ? $tmp : null;
		}

		
		// Capture controller name, method, and URI for logging
		$method	= $_SERVER['REQUEST_METHOD'] ?? 'unknown';
		$uri	= $_SERVER['REQUEST_URI'] ?? 'unknown';

		// Resolve a safe home URL (no trailing magic)
		$home = \defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : '/';
		$docsReadme = 'https://github.com/citomni/http#readme';

		// Build a meaningful and user-support-friendly log message
		switch ($statusCode) {
			case 400:
				$logMessage     = "Bad request (400): Malformed or invalid parameters";
				$meta_title     = "400 Bad Request";
				$badge_text     = "BAD REQUEST";
				$badge_variant  = "badge--warning";
				$title          = "Bad request";
				$subtitle       = "The server could not understand your request.";
				$lead_text      = "Check your input and try again.";
				$status_text    = "Bad Request";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 401:
				$logMessage     = "Unauthorized (401): Authentication required";
				$meta_title     = "401 Unauthorized";
				$badge_text     = "UNAUTHORIZED";
				$badge_variant  = "badge--warning";
				$title          = "Sign-in required";
				$subtitle       = "You must be authenticated to access this resource.";
				$lead_text      = "Log in and try again. If you believe this is an error, contact the site owner.";
				$status_text    = "Unauthorized";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 403:
				$logMessage     = "Access forbidden (403) for user";
				$meta_title     = "403 Forbidden";
				$badge_text     = "FORBIDDEN";
				$badge_variant  = "badge--warning";
				$title          = "Access denied";
				$subtitle       = "You do not have permission to view this resource.";
				$lead_text      = "If you believe this is a mistake, contact the site owner or try a different account.";
				$status_text    = "Forbidden";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 404:
				$logMessage     = "Not found (404): No matching route or resource";
				$meta_title     = "404 Not Found";
				$badge_text     = "NOT FOUND";
				$badge_variant  = "badge--warning";
				$title          = "Page not found";
				$subtitle       = "We couldn't find the page you requested.";
				$lead_text      = "Check the URL for typos or return to the home page.";
				$status_text    = "Not Found";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 405:
				$logMessage     = "HTTP method [{$method}] not allowed";
				$meta_title     = "405 Method Not Allowed";
				$badge_text     = "METHOD NOT ALLOWED";
				$badge_variant  = "badge--warning";
				$title          = "Method not allowed";
				$subtitle       = "The request method is not supported for this route.";
				$lead_text      = "Try a different HTTP method or return to the home page.";
				$status_text    = "Method Not Allowed";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 408:
				$logMessage     = "Request timeout (408)";
				$meta_title     = "408 Request Timeout";
				$badge_text     = "TIMEOUT";
				$badge_variant  = "badge--warning";
				$title          = "Request timed out";
				$subtitle       = "The server took too long to respond.";
				$lead_text      = "Please retry your request.";
				$status_text    = "Request Timeout";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 409:
				$logMessage     = "Conflict (409)";
				$meta_title     = "409 Conflict";
				$badge_text     = "CONFLICT";
				$badge_variant  = "badge--warning";
				$title          = "Conflict";
				$subtitle       = "The request could not be completed due to a conflict.";
				$lead_text      = "Refresh and try again, or resolve the conflict first.";
				$status_text    = "Conflict";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 410:
				$logMessage     = "Gone (410): Resource permanently removed";
				$meta_title     = "410 Gone";
				$badge_text     = "GONE";
				$badge_variant  = "badge--warning";
				$title          = "This page has moved on";
				$subtitle       = "The resource you’re after was removed and is no longer available.";
				$lead_text      = "Try the home page instead.";
				$status_text    = "Gone";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 413:
				$logMessage     = "Payload too large (413)";
				$meta_title     = "413 Payload Too Large";
				$badge_text     = "PAYLOAD TOO LARGE";
				$badge_variant  = "badge--warning";
				$title          = "File or request too large";
				$subtitle       = "The server rejected the request due to its size.";
				$lead_text      = "Reduce the size and try again.";
				$status_text    = "Payload Too Large";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 414:
				$logMessage     = "URI too long (414)";
				$meta_title     = "414 URI Too Long";
				$badge_text     = "URI TOO LONG";
				$badge_variant  = "badge--warning";
				$title          = "URL too long";
				$subtitle       = "The requested URL exceeds the length limit.";
				$lead_text      = "Shorten the URL and try again.";
				$status_text    = "URI Too Long";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 415:
				$logMessage     = "Unsupported media type (415)";
				$meta_title     = "415 Unsupported Media Type";
				$badge_text     = "UNSUPPORTED MEDIA";
				$badge_variant  = "badge--warning";
				$title          = "Unsupported media type";
				$subtitle       = "The server does not support the media format you sent.";
				$lead_text      = "Change the Content-Type and try again.";
				$status_text    = "Unsupported Media Type";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 422:
				$logMessage     = "Unprocessable content (422)";
				$meta_title     = "422 Unprocessable Content";
				$badge_text     = "UNPROCESSABLE";
				$badge_variant  = "badge--warning";
				$title          = "Validation failed";
				$subtitle       = "The request was well-formed but could not be processed.";
				$lead_text      = "Review validation errors and try again.";
				$status_text    = "Unprocessable Content";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 429:
				$logMessage     = "Too many requests (429): Rate limit exceeded";
				$meta_title     = "429 Too Many Requests";
				$badge_text     = "RATE LIMITED";
				$badge_variant  = "badge--warning";
				$title          = "Too many requests";
				$subtitle       = "You have sent too many requests in a short time.";
				$lead_text      = "Wait a bit and try again.";
				$status_text    = "Too Many Requests";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 451:
				$logMessage     = "Unavailable for legal reasons (451)";
				$meta_title     = "451 Unavailable For Legal Reasons";
				$badge_text     = "LEGAL RESTRICTION";
				$badge_variant  = "badge--warning";
				$title          = "Content unavailable";
				$subtitle       = "This content is not available due to a legal request.";
				$lead_text      = "If you believe this is in error, contact the site owner.";
				$status_text    = "Unavailable For Legal Reasons";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 500:
				$logMessage     = "Internal server error (500)";
				$meta_title     = "500 Internal Server Error";
				$badge_text     = "SERVER ERROR";
				$badge_variant  = "badge--danger";
				$title          = "Something went wrong";
				$subtitle       = "An unexpected error occurred while processing your request.";
				$lead_text      = "Please try again later. If the problem persists, contact the site owner.";
				$status_text    = "Internal Server Error";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 501:
				$logMessage     = "Not implemented (501)";
				$meta_title     = "501 Not Implemented";
				$badge_text     = "NOT IMPLEMENTED";
				$badge_variant  = "badge--danger";
				$title          = "Not implemented";
				$subtitle       = "The server does not recognize the request method or lacks the ability to fulfill it.";
				$lead_text      = "Try a different approach or check back later.";
				$status_text    = "Not Implemented";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 502:
				$logMessage     = "Bad gateway (502)";
				$meta_title     = "502 Bad Gateway";
				$badge_text     = "BAD GATEWAY";
				$badge_variant  = "badge--danger";
				$title          = "Upstream error";
				$subtitle       = "A server upstream returned an invalid response.";
				$lead_text      = "Please try again in a moment.";
				$status_text    = "Bad Gateway";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 503:
				$logMessage     = "Service unavailable (503)";
				$meta_title     = "503 Service Unavailable";
				$badge_text     = "SERVICE UNAVAILABLE";
				$badge_variant  = "badge--danger";
				$title          = "Temporarily unavailable";
				$subtitle       = "The service is temporarily unavailable, often due to maintenance or high load.";
				$lead_text      = "Please try again later.";
				$status_text    = "Service Unavailable";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			case 504:
				$logMessage     = "Gateway timeout (504)";
				$meta_title     = "504 Gateway Timeout";
				$badge_text     = "GATEWAY TIMEOUT";
				$badge_variant  = "badge--danger";
				$title          = "Upstream timeout";
				$subtitle       = "A server upstream took too long to respond.";
				$lead_text      = "Please retry your request.";
				$status_text    = "Gateway Timeout";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
				break;

			default:
				$logMessage     = "Error page displayed: HTTP {$statusCode}";
				$meta_title     = "{$statusCode} Error";
				$badge_text     = "HTTP {$statusCode}";
				$badge_variant  = ($statusCode >= 500 ? "badge--danger" : "badge--warning");
				$title          = "Request failed";
				$subtitle       = "The server returned an error for this request.";
				$lead_text      = "Try again or return to the home page.";
				$status_text    = "Error";
				$primary_href   = $home;          $primary_target = "_self";  $primary_label = "Home";
				$secondary_href = "javascript:history.back()"; $secondary_target = "_self"; $secondary_label = "Go Back";
				$tertiary_href  = $docsReadme;    $tertiary_target = "_blank"; $tertiary_label = "Open README";
		}

			
		// Optional logging (only if citomni/infrastructure is installed - there's no hard dependency on that package)
		if ($this->app->hasService('log') && $this->app->hasPackage('citomni/infrastructure')) {
			
			$traceDepth = ($statusCode === 500) ? 15 : 5; // Allways trace, but deeper for 500			
			
			// Log the error event (useful for support and debugging)
			$this->app->log->write(
				'app_error_log.json',
				(string)$statusCode,
				$logMessage,
				[
					'uri'        => $uri,
					'method'     => $method,
					'ip'         => $this->app->request->ip() ?? 'Unknown',
					'referrer'   => $_SERVER['HTTP_REFERER'] ?? null,
					'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
					'php_errors' => $errors, // Array of PHP errors if 500 (else null)
					'trace'		 => \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, $traceDepth),
					
				]
			);
		}
		
		// Prepare errors for template in dev-mode
		if (CITOMNI_ENVIRONMENT === "dev" && is_array($errors)) 
			$prettyErrors = \json_encode($errors, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		
		// Render the error page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' => 1,

			// Make vars available to the template			
			'meta_title'       		=> $meta_title,
			'meta_description' 		=> 'An error occurred.',
			'badge_text'       		=> $badge_text,
			'badge_variant'    		=> $badge_variant,
			'title'            		=> $title,
			'subtitle'         		=> $subtitle,
			'lead_text'        		=> $lead_text,
			'status_code'      		=> $statusCode,
			'status_text'      		=> $status_text,
		    'http_method'           => $_SERVER['REQUEST_METHOD'] ?? 'GET',
		    'request_path'          => $_SERVER['REQUEST_URI'] ?? '/',
		    'details_preformatted'  => $prettyErrors ?? '',
		    'primary_href'          => $primary_href,
		    'primary_target'		=> $primary_target,
		    'primary_label'         => $primary_label,
		    'secondary_href'        => $secondary_href,
		    'secondary_target'		=> $secondary_target,
		    'secondary_label'       => $secondary_label,
		    'tertiary_href'			=> $tertiary_href,
		    'tertiary_target'		=> $tertiary_target,
		    'tertiary_label'		=> $tertiary_label,
		    'year'                  => date('Y'),
		    'owner'                 => 'CitOmni.com',
				
			
			// User login status (left commented to avoid hard deps)
			// 'is_loggedin' => is_object($this->user_account) && $this->user_account->isLoggedin(),
		]);		
	}

}
