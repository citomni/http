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
		'request'      => \CitOmni\Http\Service\Request::class,
		'response'     => \CitOmni\Http\Service\Response::class,
		'router'       => \CitOmni\Http\Service\Router::class,
		'session'      => \CitOmni\Http\Service\Session::class,
		'cookie'       => \CitOmni\Http\Service\Cookie::class,
		'view'         => \CitOmni\Http\Service\View::class,
		'security'     => \CitOmni\Http\Service\Security::class,
		'nonce'        => \CitOmni\Http\Service\Nonce::class,
		'maintenance'  => \CitOmni\Http\Service\Maintenance::class,
		'webhooksAuth' => \CitOmni\Http\Service\WebhooksAuth::class,
	];

}
