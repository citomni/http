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

		// Render the home page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' => 0,
			
			// Canonical URL
			'canonical' => \CITOMNI_PUBLIC_ROOT_URL,
			
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
		
		
		// Optional logging (only if citomni/infrastructure is installed - there's no hard dependency on that package)
		if ($this->app->hasService('log') && $this->app->hasPackage('citomni/infrastructure')) {
		
			// Capture controller name, method, and URI for logging
			$method	= $_SERVER['REQUEST_METHOD'] ?? 'unknown';
			$uri	= $_SERVER['REQUEST_URI'] ?? 'unknown';

			// Build a meaningful and user-support-friendly log message
			switch ($statusCode) {
				case 403:
					$logMessage = "Access forbidden (403) for user";
					break;
				case 404:
					$logMessage = "Not found (404): No matching route or resource";
					break;
				case 405:
					$logMessage = "HTTP method [{$method}] not allowed";
					break;
				case 500:
					$logMessage = "Internal server error (500)";
					break;
				default:
					$logMessage = "Error page displayed: HTTP {$statusCode}";
			}

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
		
		
		// Render the error page
		$this->app->view->render($this->routeConfig["template_file"], $this->routeConfig["template_layer"], [
		
			// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
			'noindex' => 1,

			// Make status code and errors available to the template
			'status_code' => $statusCode,
			'errors' => $errors,
			
			// User login status (left commented to avoid hard deps)
			// 'is_loggedin' => is_object($this->user_account) && $this->user_account->isLoggedin(),
		]);		
	}

}
