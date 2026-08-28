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
 * PublicController: Public-facing pages (home, legal pages).
 *
 * Routing:
 * - A route in $app->routes points at this controller + action.
 * - The Router resolves that route and injects its config into $this->routeConfig
 *   before calling the action. The controller never reads $app->cfg->routes.
 *
 * Templates:
 * - We expect each route entry to define 'template_file' and 'template_layer'.
 *   Example:
 *     [
 *       'controller'      => \CitOmni\Http\Controller\PublicController::class,
 *       'action'          => 'index',
 *       'template_file'   => 'public/welcome.html',
 *       'template_layer'  => 'citomni/http',
 *       'methods'         => ['GET'],
 *     ]
 *
 * Config vs Routes:
 * - Brand / owner / locale / etc. come from $this->app->cfg (deep read-only Cfg).
 * - The final HTTP route table is exposed separately as $this->app->routes (plain array),
 *   assembled by \CitOmni\Kernel\App using vendor baseline + providers + app overrides.
 *
 * Performance policy:
 * - Keep controller logic lean. Heavy lifting (DB, mail, auth, etc.) lives in services.
 * - No global state, no static singletons.
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
	 * Lightweight per-request bootstrap for public routes.
	 *
	 * Runs before each action on this controller.
	 * Keep this fast and side-effect free. Do not perform expensive I/O here;
	 * push that work into dedicated services and call them lazily in the action
	 * that actually needs them.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Cheap pre-action setup (if any)
	}





/*
 *------------------------------------------------------------------
 * PUBLIC PAGES
 *------------------------------------------------------------------
 * 
 */


	/**
	 * GET /
	 *
	 * Renders the public home page.
	 *
	 * Data flow:
	 * - Route match has already happened. Router injected the matched route
	 *   definition into $this->routeConfig. That definition tells us which
	 *   template file + template layer to render.
	 *
	 * - We DO NOT look up routes in $this->app->cfg (routes don't live there).
	 *   $this->app->cfg is now purely settings / identity / locale / etc.
	 *
	 * View model:
	 * - We pass a small diagnostic block ($details) with runtime/env info.
	 * - We also pass SEO-ish metadata (canonical URL, title/description, robot hint).
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
		$this->app->tplEngine->render($this->routeConfig["template_file"] . "@" . $this->routeConfig["template_layer"], [
		
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
	 * GET /legal/website-license/
	 *
	 * Outputs the application's "Website Content License" page.
	 *
	 * Behavior:
	 * - Pulls application, operator, and public contact identity from $this->app->cfg->identity.
	 * - Identifies the application operator without implying ownership of third-party content.
	 * - Uses public_contact.email for permission requests, falling back to operator.email.
	 * - Distinguishes operator-controlled content, third-party content, and CitOmni licensing.
	 * - Sends "noindex" and no-cache headers using Response::noIndex().
	 * - If CITOMNI_PUBLIC_ROOT_URL is defined, emits a canonical Link header.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When neither identity.operator.name nor
	 *                           identity.app_name is configured.
	 */
	public function websiteLicense(): void {
		$cfg = $this->app->cfg;
		$identity = $cfg->identity ?? (object)[];
		$operator = $identity->operator ?? null;
		$publicContact = $identity->public_contact ?? null;

		$operatorName = (string)($operator->name ?? ($identity->app_name ?? ''));
		$operatorUrl = (string)($operator->url ?? '');
		$permissionsEmail = (string)($publicContact->email ?? ($operator->email ?? ''));

		if ($operatorName === '') {
			// Fail fast; config should provide either operator.name or app_name.
			throw new \RuntimeException('Missing identity.operator.name (or identity.app_name) in configuration.');
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

		$html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<meta name="robots" content="noindex,follow">';
		$html .= '<title>Website Content License</title>';
		$html .= '<style>';
		$html .= '*{box-sizing:border-box}';
		$html .= 'body{margin:0;background:#f5f7fa;color:#202124;font:16px/1.65 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}';
		$html .= 'main{max-width:880px;margin:0 auto;padding:56px 24px 72px}';
		$html .= 'article{background:#fff;border:1px solid #e1e5ea;border-radius:12px;padding:44px 48px;box-shadow:0 1px 3px rgba(0,0,0,.04)}';
		$html .= 'h1{margin:0 0 8px;font-size:2rem;line-height:1.2;letter-spacing:-.02em}';
		$html .= 'h2{margin:36px 0 10px;font-size:1.2rem;line-height:1.35}';
		$html .= 'p{margin:0 0 16px}';
		$html .= '.lead{margin-bottom:28px;color:#5f6368;font-size:1.05rem}';
		$html .= '.operator{margin:0 0 32px;padding:15px 18px;border:1px solid #e1e5ea;border-radius:8px;background:#fafbfc}';
		$html .= '.contact{margin-top:14px;padding:16px 18px;border-radius:8px;background:#f5f7fa}';
		$html .= '.contact p:last-child{margin-bottom:0}';
		$html .= '.meta{margin-top:40px;padding-top:20px;border-top:1px solid #e1e5ea;color:#5f6368;font-size:.925rem}';
		$html .= 'a{color:#1558d6;text-decoration-thickness:1px;text-underline-offset:2px}';
		$html .= '@media(max-width:640px){main{padding:24px 14px 40px}article{padding:28px 22px}h1{font-size:1.7rem}}';
		$html .= '</style></head><body><main><article>';
		$html .= '<header><h1>Website Content License</h1>';
		$html .= '<p class="lead">Copyright, permitted use and third-party rights.</p></header>';
		$html .= '<p class="operator"><strong>Website operator</strong><br>' . $e($operatorName) . '</p>';

		$html .= '<h2>Copyright</h2>';
		$html .= '<p>Content created and published by the website operator is protected by copyright unless otherwise stated.</p>';

		$html .= '<h2>Third-party content</h2>';
		$html .= '<p>Images, trademarks, texts and other third-party material remain subject to the rights and licenses of their respective rights holders.</p>';

		$html .= '<h2>Permitted use</h2>';
		$html .= '<p>Content protected by rights held or administered by the website operator may not be copied, redistributed, modified or commercially exploited without permission, except where permitted by applicable law or an explicitly stated license.</p>';

		$html .= '<h2>Permissions</h2>';

		if ($permissionsEmail !== '') {
			$html .= '<div class="contact">';
			$html .= '<p>Requests concerning use of content for which the website operator holds or administers the relevant rights may be directed to:</p>';
			$html .= '<p><a href="mailto:' . $e($permissionsEmail) . '">' . $e($permissionsEmail) . '</a></p>';
			$html .= '</div>';
		} else {
			$html .= '<p>Permission must be obtained from the relevant rights holder where required.</p>';
		}

		$html .= '<h2>Software</h2>';
		$html .= '<p>This website is powered by the <a href="https://www.citomni.com/" target="_blank" rel="noopener">CitOmni framework</a>. '
			. 'CitOmni is separately licensed under the <a href="https://raw.githubusercontent.com/citomni/kernel/refs/heads/main/LICENSE" target="_blank" rel="noopener noreferrer">MIT License</a>. '
			. 'That license applies to the CitOmni framework and does not grant rights to the website&rsquo;s content or other separately licensed software.</p>';

		$html .= '<footer class="meta"><strong>Operator</strong><br>' . $e($operatorName);

		if ($operatorUrl !== '') {
			$html .= ' &middot; <a href="' . $e($operatorUrl) . '">' . $e($operatorUrl) . '</a>';
		}

		$html .= '</footer></article></main></body></html>';

		// Emits Content-Type and exits.
		$this->app->response->html($html, 200);
	}


	/**
	 * 301 redirect helper for alternate license URLs to the canonical one.
	 *
	 * Assumes CITOMNI_PUBLIC_ROOT_URL is defined in the runtime environment
	 * (typical for stage/prod). If it's not defined in your app, consider
	 * overriding this action.
	 *
	 * @return never
	 */
	public function redirectWebsiteLicense(): never {
		$target = \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/';
		$this->app->response->redirect($target, 301);
	}


}
