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
 * PublicController: HTTP adapter for the default public pages of an app.
 *
 * Gives every app public pages that need no code of its own, such as a welcome page and a
 * website content license. It decides what a page says; who operates the site belongs to the
 * app's cfg, and the look of a rendered page to the route's template.
 *
 * Rules:
 * - Serve only what every visitor may see. A page that needs access control belongs in
 *   another controller.
 * - Escape every value that goes into HTML built in this class.
 */
class PublicController extends BaseController {

	/**
	 * Run setup and guards that every action needs.
	 *
	 * Notes:
	 * - Holds no access guard, so each action is exactly as exposed as its route. A guard added
	 *   here applies to every action.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Controller\BaseController::__construct()  Calls it before every action.
	 */
	protected function init(): void {
	}


	/**
	 * Render the welcome page of a new installation.
	 *
	 * Behavior:
	 * - Renders the route's template with the page texts and links, CITOMNI_PUBLIC_ROOT_URL as
	 *   canonical, the request method and URI, and details_preformatted: Pretty-printed JSON
	 *   with the mode, the environment, the elapsed time in seconds, the current and peak
	 *   memory use in KB, and two OPcache settings.
	 *
	 * Notes:
	 * - Requires template_file and template_layer on its route, and the constants
	 *   CITOMNI_ENVIRONMENT and CITOMNI_PUBLIC_ROOT_URL.
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
					'memory_usage_current_kb:' => (int)\round(\memory_get_usage() / 1024),
					'memory_usage_peak_kb' => (int)\round(\memory_get_peak_usage() / 1024),
				],
				'opcache' => [
					'enabled' => (bool)\filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL),
					'validate_timestamps' => \ini_get('opcache.validate_timestamps') !== '0',
				],
			], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

		$this->app->tplEngine->render($this->routeConfig["template_file"] . "@" . $this->routeConfig["template_layer"], [
			'noindex' 				=> 0,
			'canonical' 			=> \CITOMNI_PUBLIC_ROOT_URL,
			'meta_title'       		=> 'Installed successfully',
			'meta_description' 		=> 'CitOmni HTTP is installed and running. You are seeing the default welcome page.',
			'badge_text'       		=> 'READY',
			'badge_variant'    		=> 'badge--success',
			'title'            		=> 'Installation complete',
			'subtitle'         		=> 'CitOmni HTTP is up and running.',
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
		    'tertiary_target'		=> '_blank',
		    'tertiary_label'		=> 'Report issue',
		    'year'                  => date('Y'),
		    'owner'                 => 'CitOmni.com',
			
			
			// User login status (left commented to avoid hard deps)
			// 'is_loggedin' => is_object($this->user_account) && $this->user_account->isLoggedin(),
		]);
	}


	/**
	 * Answer the website content license page as HTML.
	 *
	 * The page names the site's operator from cfg identity and separates content that the
	 * operator holds rights to from third-party content and from the CitOmni license, without
	 * claiming ownership of third-party content.
	 *
	 * Behavior:
	 * - 200 with a self-contained HTML page, with the headers of Response::noIndex().
	 * - The operator is cfg identity.operator.name, or identity.app_name when operator.name is
	 *   not set. identity.operator.url is linked when it is not empty.
	 * - Permission requests go to identity.public_contact.email, or identity.operator.email when
	 *   public_contact.email is not set. Without either, the page refers to the rights holders.
	 * - When CITOMNI_PUBLIC_ROOT_URL is defined, a Link header names /legal/website-license/
	 *   under it as canonical. The page's route must serve that path.
	 *
	 * @return void
	 * @throws \RuntimeException  When the operator name resolves to an empty string.
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

		$this->app->response->noIndex();

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

		$this->app->response->html($html, 200);
	}


	/**
	 * Redirect permanently to the website content license page.
	 *
	 * Behavior:
	 * - 301 to /legal/website-license/ under CITOMNI_PUBLIC_ROOT_URL.
	 *
	 * Notes:
	 * - Requires the constant CITOMNI_PUBLIC_ROOT_URL.
	 * - Never route it on the target path, with or without the trailing slash, or it redirects
	 *   to itself.
	 *
	 * @return never
	 */
	public function redirectWebsiteLicense(): never {
		$target = \CITOMNI_PUBLIC_ROOT_URL . '/legal/website-license/';
		$this->app->response->redirect($target, 301);
	}


}
