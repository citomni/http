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
 * CaptchaController: Serves the images of challenges issued by the captcha service.
 *
 * Routing:
 * - Opt-in. This package registers no route for it, so apps that do not use
 *   captchas expose no endpoint. An app that does adds one entry to
 *   /config/citomni_http_routes.php, at the path in security.captcha.image_path:
 *
 *     '/captcha.png' => [
 *         'controller' => \CitOmni\Http\Controller\CaptchaController::class,
 *         'action'     => 'image',
 *         'methods'    => ['GET'],
 *     ],
 */
class CaptchaController extends BaseController {

	/**
	 * GET {security.captcha.image_path}?id={challenge id}
	 *
	 * Behavior:
	 * - Sends the PNG of a pending challenge with no-store caching and exits.
	 *   A challenge always yields the same picture.
	 * - Answers 404 without a body when captcha protection is off, and when the
	 *   id is missing, malformed, unknown or expired. Nothing is logged: such
	 *   requests are routine for bots.
	 *
	 * Notes:
	 * - A request without a session cookie gets its 404 without a session
	 *   being started.
	 * - Each image costs a few milliseconds of CPU; rate-limit the route where
	 *   that matters.
	 *
	 * @return void
	 */
	public function image(): void {
		$captcha = $this->app->captcha;
		$image = $captcha->isEnabled() ? $captcha->image($this->app->request->get('id')) : null;

		if ($image === null) {
			$this->app->response->setStatus(404);
			$this->app->response->noCache();
			return;
		}

		$this->app->response->noCache();
		$this->app->response->setHeader('Content-Type', $image['mime']);
		$this->app->response->setHeader('Content-Length', (string)$image['bytes']);
		$this->app->response->setHeader('X-Content-Type-Options', 'nosniff');
		echo $image['data'];
		exit;
	}
}
