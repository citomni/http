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



}
