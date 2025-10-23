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












	/**
	 * Render /legal/website-license (content terms; NOT code license).
	 *
	 * Behavior:
	 * - Pulls owner/app metadata from $this->app->cfg->identity
	 * - Sends "noindex" and no-cache headers (via Response::noIndex())
	 * - Optionally sets a canonical Link header if CITOMNI_PUBLIC_ROOT_URL is defined
	 * - Emits deterministic HTML via Response::html() (which exits)
	 *
	 * @return void
	 * @throws \RuntimeException If neither identity.owner_name nor identity.app_name is set.
	 */
	public function websiteLicense(): void {
		$cfg = $this->app->cfg;
		$identity = $cfg->identity ?? (object)[];

		$ownerName  = (string)($identity->owner_name ?? ($identity->app_name ?? ''));
		$ownerEmail = (string)($identity->owner_email ?? '');
		$ownerUrl   = (string)($identity->owner_url ?? '');
		$year       = (int)\date('Y');

		if ($ownerName === '') {
			// Fail fast; config should provide either owner_name or app_name.
			throw new \RuntimeException('Missing identity.owner_name (or app_name) in configuration.');
		}

		// Robots + no-cache; also safe for member-only pages.
		$this->app->response->noIndex();

		// Optionally advertise canonical URL if root URL is known.
		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$this->app->response->setHeader(
				'Link',
				'<' . \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/>; rel="canonical"',
			);
		}

		$e = static fn(?string $s): string => \htmlspecialchars((string)$s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		$html  = '<!doctype html><html lang="en"><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<meta name="robots" content="noindex,follow">';
		$html .= '<title>Website Content License</title>';
		$html .= '<body style="margin:0;padding:24px;font:16px/1.6 ui-serif,Georgia,Cambria,Times,serif">';
		$html .= '<h1>Website Content License</h1>';
		$html .= '<p>All textual and visual content on this website is &copy; '
			. $e((string)$year) . ' ' . $e($ownerName) . ', unless stated otherwise.</p>';
		$html .= '<p>Content may not be copied, redistributed, or modified without prior written consent.</p>';
		$html .= '<p>This website is built on the <a href="https://www.citomni.com/" target="_blank" rel="noopener">CitOmni framework</A> <a href="https://raw.githubusercontent.com/citomni/kernel/refs/heads/main/LICENSE" target="_blank" rel="noopener noreferrer">(MIT)</a>. '
			. 'The framework’s license applies to the framework only, not to the website content.</p>';
		if ($ownerEmail !== '') {
			$html .= '<p>Permissions: ' . $e($ownerEmail) . '</p>';
		}
		if ($ownerUrl !== '') {
			$html .= '<p>Owner: <a href="' . $e($ownerUrl) . '">' . $e($ownerUrl) . '</a></p>';
		}
		$html .= '</body></html>';

		// Emits Content-Type and exits.
		$this->app->response->html($html, 200);
	}


	/**
	 * Redirect any alternate URLs to the canonical URL.
	 *
	 * @return never
	 */
	public function redirectWebsiteLicense(): never {
		$target = \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/';
		$this->app->response->redirect($target, 301);
	}


}
