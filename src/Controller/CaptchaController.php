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
 * CaptchaController: HTTP adapter that serves the images of pending captcha challenges.
 *
 * Gives the browser the picture of a challenge that the captcha service issued. It shapes the
 * response; which challenges exist, and what they look like, belongs to the captcha service.
 *
 * Rules:
 * - Read only: Never consume or change a challenge. Checking an answer belongs to the form's
 *   own action.
 */
class CaptchaController extends BaseController {

	/**
	 * Send the PNG of the pending challenge that query parameter id names.
	 *
	 * Behavior:
	 * - 200 with the image, its Content-Type and Content-Length, X-Content-Type-Options: nosniff
	 *   and no-cache headers, then ends the request.
	 * - 404 without a body and with no-cache headers when captcha protection is off, or when
	 *   Captcha::image() has no picture for the id. Nothing is logged: Such requests are
	 *   routine for bots.
	 *
	 * Notes:
	 * - Route it for GET at the app path that cfg security.captcha.image_path names, so the
	 *   paths that Captcha::imagePath() returns reach it.
	 * - Each image costs a few milliseconds of CPU; rate-limit the route where that matters.
	 *
	 * @return void
	 * @see \CitOmni\Http\Service\Captcha::image()  Which ids have a picture, the same one on every request, and when no session is started.
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
