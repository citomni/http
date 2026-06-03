<?php
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
declare(strict_types=1);
	
/*
 *---------------------------------------------------------------
 * SCRIPT START TIME
 *---------------------------------------------------------------
 * Capture precise start time to measure total execution time.
 */
define('CITOMNI_START_NS', hrtime(true));


/*
 *---------------------------------------------------------------
 * APPLICATION ENVIRONMENT
 *---------------------------------------------------------------
 * The environment determines how the application behaves:
 * - 'dev': Development environment with error reporting enabled.
 * - 'stage': Staging environment for final pre-production testing.
 * - 'prod': Production environment with minimal error reporting.
 */
define('CITOMNI_ENVIRONMENT', '%CITOMNI_ENVIRONMENT%'); // 'dev' | 'stage' | 'prod'




/*
 *---------------------------------------------------------------
 * PATH & URL CONSTANTS (read-only)
 *---------------------------------------------------------------
 * These constants are resolved as early as possible for maximum
 * robustness and minimal overhead across the whole app.
 *
 * CITOMNI_PUBLIC_PATH
 *   Absolute path to /public (no trailing slash).
 *
 * CITOMNI_APP_PATH
 *   Absolute path to the application root (one level above /public).
 *
 * CITOMNI_PUBLIC_ROOT_URL  (optional override)
 *   Absolute public base URL (no trailing slash). If undefined:
 *     - dev  : HTTP kernel will auto-detect from the request.
 *     - stage/prod : HTTP kernel requires http.base_url in cfg.
 */

define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', \dirname(__DIR__));

// Optional: set explicitly on stage/prod (fastest & most explicit).
if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
	// e.g. 'https://stage.example.com' or 'https://www.example.com'
	define('CITOMNI_PUBLIC_ROOT_URL', '%CITOMNI_PUBLIC_ROOT_URL%');
}



// \citomni_memmark('index.php før composer');
// \citomni_memdiff('index.php før composer');
/*
 *---------------------------------------------------------------
 * COMPOSER AUTOLOAD
 *---------------------------------------------------------------
 * Loads Composer's autoloader, which makes all project classes 
 * and dependencies available. This includes both CitOmni Core 
 * (installed as a dependency) and any third-party libraries the 
 * application requires.
 *
 * The default location is /app/vendor/autoload.php, since all 
 * dependencies are expected to live inside the app path.
 *
 * If your application uses a different structure, update this 
 * single line to point to the correct autoload.php file.
 */
require __DIR__ . '/../vendor/autoload.php';

// \citomni_memmark('index.php efter composer');
// \citomni_memdiff('index.php efter composer');
/*
 *---------------------------------------------------------------
 * HAND-OFF TO CITOMNI HTTP KERNEL - Now let the magic happen!!
 *---------------------------------------------------------------
 * You made it through index.php. From here, the kernel takes over:
 * - Closes the book on bootstrapping (this file should stay tiny)
 * - Loads and merges config from /config (vendor -> providers.php -> app/env)
 * - Boots the App in HTTP mode and wires core services (request/response,
 *   router, session, security, logging, etc.)
 * - Installs the HTTP error handler (if available) and applies policies
 * - Dispatches the router to your FQCN controllers
 *
 * WHAT *YOU* DO IN index.php (keep it minimal):
 *  1) Require Composer's autoloader
 *     require __DIR__ . '/../vendor/autoload.php';
 *
 *  2) Define your environment early (used for toggles and caches)
 *     define('CITOMNI_ENVIRONMENT', getenv('APP_ENV') ?: 'prod'); // 'dev'|'prod'|...
 *
 *  3) (Optional) Any ultra-light preflight toggles you want *before* kernel:
 *     - HTTP cache headers for static assets behind a front proxy
 *     - A quick maintenance flag check (e.g. var/flags/maintenance)
 *     Keep it fast. Anything heavier belongs in CitOmni config/services.
 *
 * KERNEL OPTIONS (pass as the 2nd argument below):
 *  - display_errors       bool    Show errors in the browser
 *                                  (dev=true, prod=false is typical)
 *  - error_sender         string  From-address for error notifications
 *  - error_recipient      string  Destination mailbox for notifications
 *                                (Prefer same-domain addresses for SPF/DKIM/DMARC)
 *  - error_log_file       string  Absolute path to JSON error log
 *  - error_max_log_size   int     Max size in bytes before rotation/trim
 *
 * NOTES
 * - The kernel defines CITOMNI_PUBLIC_ROOT_URL when it can be derived safely.
 * - Timezone, routes, and service maps come from /config. Access config via
 *   $this->app->cfg (read-only), and services via $this->app->id (map-based).
 * - If var/cache/cfg.http.php and var/cache/services.http.php exist, the
 *   kernel will use them to minimize per-request overhead.
 *
 * LET'S GOOOOO!
 * Hand off control and let CitOmni do the heavy lifting.
 */
\CitOmni\Http\Kernel::run(__DIR__, [
	// Show detailed errors in dev only (never enable in prod).
	// 'display_errors' => (\CITOMNI_ENVIRONMENT === 'dev'),

	// Error handler wiring (overrides cfg.error_handler.*)
	// 'error_recipient'  => 'devops@your-domain.tld',
	// 'error_log_file'   => \CITOMNI_APP_PATH . '/var/logs/error.http.json',
	// 'error_max_log_size' => 5_000_000, // bytes (approx. 5 MB)
]);
