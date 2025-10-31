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

namespace CitOmni\Http\Boot;


/**
 * Vendor baseline service map for HTTP mode.
 *
 * Behavior:
 * - This is tier 1 in the deterministic service map merge:
 *   1) Vendor baseline: \CitOmni\Http\Boot\Services::MAP
 *   2) Providers: /config/providers.php (their MAP_HTTP blocks)
 *   3) App map: /config/services.php
 *   Last writer wins by key.
 *
 * Notes:
 * - IDs are resolved via $this->app->{id} and instantiated as singletons.
 * - All definitions must be FQCNs or arrays with ['class'=>FQCN,'options'=>[]].
 */
final class Services {
	public const MAP = [
		'errorHandler'	=> \CitOmni\Http\Service\ErrorHandler::class,
		'request'		=> \CitOmni\Http\Service\Request::class,
		'response'		=> \CitOmni\Http\Service\Response::class,
		'router'		=> \CitOmni\Http\Service\Router::class,
		'session'		=> \CitOmni\Http\Service\Session::class,
		'flash'			=> \CitOmni\Http\Service\Flash::class,
		'datetime'		=> \CitOmni\Http\Service\Datetime::class,
		'cookie'		=> \CitOmni\Http\Service\Cookie::class,
		'view'			=> \CitOmni\Http\Service\View::class,  // Replaced by TemplateEngine, but kept for now for back compat
		'tplEngine'		=> \CitOmni\Http\Service\TemplateEngine::class,
		'security'		=> \CitOmni\Http\Service\Security::class,
		'nonce'			=> \CitOmni\Http\Service\Nonce::class,
		'maintenance'	=> \CitOmni\Http\Service\Maintenance::class,
		'webhooksAuth'	=> \CitOmni\Http\Service\WebhooksAuth::class,
		'slugger'		=> \CitOmni\Http\Service\Slugger::class,
		'tags'			=> \CitOmni\Http\Service\Tags::class,
	];

}
