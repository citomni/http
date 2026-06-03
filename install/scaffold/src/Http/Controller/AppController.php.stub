<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (C) 2012-present Lars Grove Mortensen
 *
 * CitOmni HTTP Skeleton - Minimal starter for high-performance CitOmni HTTP apps.
 * Source:  https://github.com/citomni/http-skeleton
 * License: See the LICENSE file for full terms.
 */

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * Public-facing pages (home and generic error pages).
 *
 * Lean controller intended for minimal startup logic and simple view rendering.
 * Templates + layer are provided via route config. No "magic", no global state.
 */
class AppController extends BaseController {
	
	

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


	public function index(): void {

		$this->app->tplEngine->render($this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'], [
		
			// Canonical URL
			'canonical' => \CITOMNI_PUBLIC_ROOT_URL,
		
		]);
	
	}


	public function helloworld(): void {

		$this->app->tplEngine->render($this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'], [
		
			// Canonical URL
			'canonical' => \CITOMNI_PUBLIC_ROOT_URL . "/helloworld.html",
		
		]);
	
	}

}
