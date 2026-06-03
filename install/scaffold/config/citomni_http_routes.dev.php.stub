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

/*
 * ------------------------------------------------------------------
 * ROUTE TABLE (DEV-ONLY ROUTES AND OVERRIDES) (HTTP)
 * ------------------------------------------------------------------
 *
 * HOW THIS FILE IS USED:
 *   - When CITOMNI_ENVIRONMENT === 'dev', this file is *considered*
 *     as the final layer in the route merge.
 *
 *   - HOWEVER: The App kernel will only merge this layer if the returned
 *     array is non-empty. Returning [] means "skip me entirely".
 *
 * WHY RETURN [] BY DEFAULT?
 *   - The CitOmni HTTP core (vendor) may already ship helpful dev tools
 *     like /appinfo.html, diagnostics, etc.
 *   - If we shipped conflicting stubs here, we'd accidentally override
 *     the real implementations in vendor for dev environments.
 *
 * HOW TO USE IT IN YOUR APP:
 *   1) Copy one of the commented examples below.
 *   2) Paste it into the return array.
 *   3) Adjust controller/action/template to match your app.
 *
 * EXAMPLE: force-enable a local-only cache warmer endpoint
 *
 * return [
 *     '/cache/warm' => [
 *         'controller'     => \App\Http\Controller\Dev\MaintenanceController::class,
 *         'action'         => 'warmCacheNow',
 *         'methods'        => ['POST','GET'], // allow GET in early dev if you want
 *         'template_file'  => 'dev/cache_warm.html',
 *         'template_layer' => 'app',
 *     ],
 * ];
 *
 * EXAMPLE: custom 404 just for dev (helps debugging broken links)
 *
 * return [
 *     404 => [
 *         'controller'     => \App\Http\Controller\Dev\DebugErrorController::class,
 *         'action'         => 'notFoundDebug',
 *         'methods'        => ['GET'],
 *         'template_file'  => 'dev/error_404_debug.html',
 *         'template_layer' => 'app',
 *         'params'         => [404],
 *     ],
 * ];
 *
 * SECURITY NOTE:
 *   - Anything you add here should *not* be exposed publicly in prod/stage.
 *   - This file is only merged when CITOMNI_ENVIRONMENT === 'dev', but still:
 *     don't put unauthenticated "do dangerous stuff" endpoints in controllers
 *     that end up committed to public repos unless you're okay with that risk.
 */
return [

	// Intentionally empty.
	// Add dev-only routes here if you want to override/extend vendor behavior.
	
];
