# CitOmni HTTP

Slim, deterministic HTTP delivery for CitOmni apps.
Zero "magic", PSR-4 all the way, PHP 8.2+, tiny boot, predictable overrides.

---

## Highlights

* **Deterministic boot** -> vendor baseline -> providers -> app (**last wins**)
* **Lean routing** with exact + placeholder/"regex" routes
* **Deep, read-only config** -> `$this->app->cfg->http->base_url`
* **Service maps (no scanning)** -> `$this->app->{id}` resolves instantly (cacheable)
* **Prod-friendly** -> optional compiled caches in `/var/cache/*.php` (atomic writes)
* **HTTP ErrorHandler** (optional, auto-installed if present in package)
* **Maintenance 503** with `Retry-After` and allow-list
* **Security foundations** -> CSRF token helper, cookie/session CSP/Samesite defaults
* **Webhook HMAC** (`WebhooksAuth`) with TTL, clock skew tolerance, nonce/replay protection
* ♻️ **Green by design** - lower memory use and CPU cycles -> less server load, more requests per watt, better scalability, smaller carbon footprint.

---

### Green by design

CitOmni's "Green by design" claim is empirically validated at the framework level.

The core runtime achieves near-floor CPU and memory costs per request on commodity shared infrastructure, sustaining hundreds of RPS per worker with extremely low footprint.

See the full test report here:
[CitOmni Docs → /reports/2025-10-02-capacity-and-green-by-design.md](https://github.com/citomni/docs/blob/main/reports/2025-10-02-capacity-and-green-by-design.md)

---

## Requirements

* PHP **8.2** or newer
* Recommended extensions: `ext-json` (required), `mbstring` (recommended)  
  Optional CitOmni packages: [citomni/infrastructure](https://packagist.org/packages/citomni/infrastructure), [citomni/auth](https://packagist.org/packages/citomni/auth), [citomni/testing](https://packagist.org/packages/citomni/testing)
* OPcache strongly recommended in production

---

## Install

```bash
composer require citomni/http
```

Your app's `composer.json` must PSR-4 map your code:

```json
{
	"autoload": {
		"psr-4": {
			"App\\": "src/"
		}
	}
}
```

Then:

```bash
composer dump-autoload -o
```

---

## Quick start

**`/public/index.php` (minimal front controller):**

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_TIME', microtime(true));
define('CITOMNI_ENVIRONMENT', 'dev');            // 'dev' | 'stage' | 'prod'
define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', \dirname(__DIR__));
// In stage/prod you should define an absolute public root URL (or set http.base_url in cfg):
if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
	define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');
}

require __DIR__ . '/../vendor/autoload.php';

// Hand over to the HTTP Kernel (it will resolve app/config paths from the public dir)
\CitOmni\Http\Kernel::run(__DIR__);
```

**Folder layout (app):**

```
/app-root
  /bin
  /config
    providers.php                # optional list of provider FQCNs
    citomni_http_cfg.php         # app baseline config (HTTP)
    citomni_http_cfg.stage.php   # optional per-env overlay
    citomni_http_cfg.prod.php    # optional per-env overlay
    services.php                 # optional service map overrides/additions
  /public
    index.php
  /src
    /Http/{Controller,Service,Model}
    /Service /Model ...
  /templates
  /var/{cache,flags,logs,nonces,state}
  /vendor
```

---

## Configuration (last wins)

Vendor HTTP baseline lives in `\CitOmni\Http\Boot\Config::CFG`.
At runtime, the app builds config as:

1. **Vendor HTTP baseline**
2. **Provider CFGs** (if any; listed in `/config/providers.php`)
3. **App base cfg** `/config/citomni_http_cfg.php`
4. **App env overlay** `/config/citomni_http_cfg.{env}.php` (optional)

**Merge rules:**

* Associative arrays -> merged per key, **last wins**
* Numeric lists -> **replaced** by the last source
* Empty values (`''`, `false`, `0`, `null`, `[]`) are valid overrides and still win

**Deep access via read-only wrapper:**

```php
$this->app->cfg->locale->timezone;
$this->app->cfg->http->base_url;
$this->app->cfg->routes['/']; // raw array (routes are exposed as raw arrays)
```

### Example `/config/citomni_http_cfg.php`

```php
<?php
declare(strict_types=1);

return [
	'identity' => [
		'app_name' => 'My CitOmni App',
		'email'    => 'support@example.com',
		'phone'    => '(+45) 12 34 56 77',
	],

	'locale' => [
		'language' => 'da',
		'timezone' => 'Europe/Copenhagen',
		'charset'  => 'UTF-8',
	],

	'http' => [
		'base_url'        => '',       // dev will auto-detect when empty
		'trust_proxy'     => false,
		'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16', '::1'],
	],

	'error_handler' => [
		'log_file'       => CITOMNI_APP_PATH . '/var/logs/system_error_log.json',
		'recipient'      => 'errors@example.com',
		'sender'         => 'noreply@example.com',
		'max_log_size'   => 10_485_760,
		'template'       => __DIR__ . '/../vendor/citomni/http/templates/errors/failsafe_error.php',
		'display_errors' => (defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev'),
	],

	'session' => [
		'name'                   => 'CITSESSID',
		'save_path'              => CITOMNI_APP_PATH . '/var/state/php_sessions',
		'gc_maxlifetime'         => 1440,
		'use_strict_mode'        => true,
		'use_only_cookies'       => true,
		'lazy_write'             => true,
		'sid_length'             => 48,
		'sid_bits_per_character' => 6,
		'cookie_secure'          => null,     // auto if https
		'cookie_httponly'        => true,
		'cookie_samesite'        => 'Lax',
		'cookie_path'            => '/',
		'cookie_domain'          => null,
		'rotate_interval'        => 0,
		'fingerprint'            => [
			'bind_user_agent' => false,
			'bind_ip_octets'  => 0,
			'bind_ip_blocks'  => 0,
		],
	],

	'cookie' => [
		'httponly' => true,
		'samesite' => 'Lax',
		'path'     => '/',
		// 'secure' => true,     // optional override
		// 'domain' => 'example.com',
	],

	'view' => [
		'cache_enabled'        => false,
		'trim_whitespace'      => false,
		'remove_html_comments' => false,
		'allow_php_tags'       => false,
		'marketing_scripts'    => '',
		'view_vars'            => [],
		// 'asset_version'      => '2025-09-29',
	],

	'security' => [
		'csrf_protection'      => true,
		'csrf_field_name'      => 'csrf_token',
		'captcha_protection'   => true,
		'honeypot_protection'  => true,
		'form_action_switching'=> true,
	],

	'maintenance' => [
		'flag' => [
			'path'               => CITOMNI_APP_PATH . '/var/flags/maintenance.php',
			'template'           => __DIR__ . '/../vendor/citomni/http/templates/public/maintenance.php',
			'allowed_ips'        => [],
			'default_retry_after'=> 300,
		],
		'backup' => [
			'enabled' => true,
			'keep'    => 3,
			'dir'     => CITOMNI_APP_PATH . '/var/backups/flags',
		],
		'log' => [
			'filename' => 'maintenance.json',
		],
	],

	'webhooks' => [
		'enabled'                   => true,
		'ttl_seconds'               => 300,
		'ttl_clock_skew_tolerance'  => 60,
		'allowed_ips'               => [],
		'nonce_dir'                 => CITOMNI_APP_PATH . '/var/nonces',
		// 'secret'                  => '*** put shared secret here ***',
		// 'bind_context'           => true,
		// 'algo'                   => 'sha512',
	],

	// Routes: you can inline them here or require from a separate file
	'routes' => [
		'/' => [
			'controller'     => \CitOmni\Http\Controller\PublicController::class,
			'action'         => 'index',
			'methods'        => ['GET'],
			'template_file'  => 'public/index.html',
			'template_layer' => 'citomni/http',
		],
		// 403/404/405/500 defaults exist in vendor baseline; override as needed.
		'regex' => [],
	],
];
```

### Per-env overlays (optional)

`/config/citomni_http_cfg.stage.php`

```php
<?php
return [
	'http' => ['base_url' => 'https://stage.example.com'],
];
```

`/config/citomni_http_cfg.prod.php`

```php
<?php
return [
	'http' => ['base_url' => 'https://www.example.com'],
];
```

### Base URL policy

* **dev**: Kernel **auto-detects** when `http.base_url=''`
* **stage/prod**: **no auto-detect** -> require an **absolute** URL in cfg **or** define `CITOMNI_PUBLIC_ROOT_URL`
* Kernel defines `CITOMNI_PUBLIC_ROOT_URL` (no trailing slash)


#### Reverse proxy & base URL

If you run behind Nginx/Apache/Cloudflare, configure **`http.trust_proxy`** and **`http.trusted_proxies`** correctly. Only include **trusted** proxy IPs/CIDR blocks.

**Config:**

```php
'http' => [
	'base_url'        => '',          // dev auto-detects; stage/prod: absolute URL required
	'trust_proxy'     => true,
	'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16', '127.0.0.1', '::1'],
],
```

**Nginx (example):**

```nginx
proxy_set_header  X-Forwarded-Proto   $scheme;
proxy_set_header  X-Forwarded-Host    $host;
proxy_set_header  X-Forwarded-Port    $server_port;
proxy_set_header  X-Forwarded-For     $proxy_add_x_forwarded_for;
```

If you publish under a sub-path (e.g. `https://example.com/app`), make sure your `base_url` includes that path, or set `CITOMNI_PUBLIC_ROOT_URL` accordingly. The router handles base-prefix stripping correctly either way.

---

## Routes

Keep routes inline under `cfg['routes']` or load them from a separate PHP file.

**Placeholders available:**

* `{id}` -> `[0-9]+`
* `{email}` -> `[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+`
* `{slug}` -> `[a-zA-Z0-9-_]+`
* `{code}` -> `[a-zA-Z0-9]+`
  Unknown placeholders fall back to `[^/]+`.

#### Custom error pages

Override 403/404/405/500 in `cfg['routes']`:

```php
404 => [
	'controller'     => \CitOmni\Http\Controller\PublicController::class,
	'action'         => 'errorPage',
	'methods'        => ['GET'],
	'template_file'  => 'errors/404.html',
	'template_layer' => 'app', // or 'citomni/http' for vendor template
	'params'         => [404],
],
```

Template variables provided to `errorPage`:

* `status_code` (int)
* `errors` (array|null, only filled for 500 when ErrorHandler has entries)

---

## Controllers

* Framework controllers: `CitOmni\Http\Controller\*`
* App controllers: `App\Http\Controller\*`
  The router instantiates controllers and injects the App and view hints:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class HomeController extends BaseController {
	// Optional boot hook (called by BaseController::__construct)
	protected function init(): void {
		// e.g. preload something for the view
	}

	public function index(): void {
		$this->app->view->render(
			$this->routeConfig['template_file']  ?? 'public/index.html',
			$this->routeConfig['template_layer'] ?? 'app',
			[
				'noindex'   => 0,
				'canonical' => \CITOMNI_PUBLIC_ROOT_URL,
			]
		);
	}
}
```

#### Healthcheck

A minimal route for load balancers/uptime checks:

```php
'/health' => [
	'controller' => \CitOmni\Http\Controller\PublicController::class,
	'action'     => 'health',
	'methods'    => ['GET'],
],
```

```php
// In PublicController:
public function health(): void {
	$this->app->response->jsonStatus([
		'status'       => 'ok',
		'env'          => defined('CITOMNI_ENVIRONMENT') ? CITOMNI_ENVIRONMENT : 'unknown',
		'maintenance'  => $this->app->maintenance->isEnabled(),
	], 200);
}
```

---

## Templating with `View` (helpers & examples)

`View` renders LiteView templates from either your app (`/templates`) or a vendor "layer" like `citomni/auth`. It also exposes a small set of globals and closures you can call directly in templates.

### Controller -> render (passes 3 vars)

```php
use CitOmni\Kernel\Controller\BaseController;

final class PublicController extends BaseController
{
	public function index(): void
	{
		$this->app->view->render('public/home.html', 'citomni/http', [
			'title'    => 'Welcome',
			'lead'     => 'Fast, predictable HTTP runtime for CitOmni apps.',
			'cta_path' => '/docs/get-started',
		]);
	}
}
```

### Corresponding template snippet (`public/home.html`)
Using Template helpers (LiteView syntax)

```html
<!doctype html>
<html lang="{{ $language }}">
<head>
	<meta charset="{{ $charset }}">
	<title>{{ $app_name }} - {{ $title }}</title>
	<meta name="description" content="{{ $lead }}">
	<link rel="stylesheet" href="{{ $asset('/assets/app.css') }}">
	{{{ $marketing_scripts }}}  {# optional, if you inject any #}
</head>
<body>
	<main>
		<h1>{{ $title }}</h1>
		<p>{{ $lead }}</p>
		<a class="btn" href="{{ $url($cta_path) }}">
			{{ $txt('cta.get_started', 'home', 'citomni/http', 'Get started') }}
		</a>
	</main>

	{# Existing examples kept #}
	<a href="{{ $url('/member/home.html') }}">Home</a>

	{% if $hasPackage('citomni/auth') %}
		<a href="{{ $url('/member/profile.html') }}">Profile</a>
	{% endif %}

	<form method="post" action="{{ $url('/feedback.json') }}">
		{{{ $csrfField() }}}
		<!-- fields -->
	</form>

	<script src="{{ $asset('/assets/app.js') }}"></script>
</body>
</html>
```

> LiteView syntax: `{{ ... }}` prints escaped, `{{{ ... }}}` prints raw. Control structures use `{% ... %}` and comments use `{# ... #}`. Find more examples in the documentation inside the View-service.

### Globals & closures available in templates

* `app_name` (string)
* `base_url` (string) - from `http.base_url` (or auto-detected in dev)
* `public_root_url` (string) - `CITOMNI_PUBLIC_ROOT_URL` if defined, else `base_url`
* `language` (string), `charset` (string)
* `marketing_scripts` (string), `view_vars` (array)
* `csrf_protection`, `honeypot_protection`, `form_action_switching`, `captcha_protection` (bool flags)
* `env` (array) -> `['name' => 'dev|stage|prod', 'dev' => bool]`

Closures:

* `$txt(string $key, string $file, ?string $layer = null, string $default = '', array $vars = []): string`
  *Requires a registered `txt` service (commonly from `citomni/infrastructure`).*
* `$url(string $path = '', array $query = []): string`
  Joins `base_url` + normalized `path` + optional query.
* `$asset(string $path, ?string $version = null): string`
  Absolute if `path` is already a URL; otherwise `base_url + path`, with `?v=...` appended if `version` or `view.asset_version` is set (preserves existing query).
* `$hasService(string $id): bool` - service id in the map?
* `$hasPackage(string $slug): bool` - vendor/package detected via services/routes?
* `$csrfField(): string` - hidden CSRF `<input>` (empty string if disabled/not available).
* `$currentPath(): string` - request path (lazy; resolves only if called).
* `$role(string $fn, mixed ...$args)` - role checks/labels (if role gate is present)
  Examples: `$role('is','admin')`, `$role('any','manager','operator')`, `$role('label')`.

### Notes & tips

* **Base URL**: set an **absolute** `http.base_url` for stage/prod; dev can auto-detect.
* **Canonical links**: prefer `public_root_url` when constructing canonicals or sitemaps.
* **Vendor layers**: pass `template_layer` (e.g. `citomni/http`) and `template_file` via routes, or call `render('...', 'vendor/package')` directly.
* **i18n**: if you don't use i18n, you can ignore `$txt`; if you do, ensure the `txt` service is registered (typically via a provider).

---

## Services

Baseline map shipped by this package:

```php
\CitOmni\Http\Boot\Services::MAP
// [
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
// ]
```

Extend/override in `/config/services.php`:

```php
<?php
return [
	// Simple override:
	'router' => \CitOmni\Http\Service\Router::class,

	// With options (constructor is __construct(App $app, array $options = []))
	'view' => [
		'class'   => \CitOmni\Http\Service\View::class,
		'options' => ['asset_version' => '2025-09-29'],
	],
];
```

Use anywhere:

```php
$this->app->response->noCache();
$this->app->request->json();
$this->app->maintenance->enable(['1.2.3.4']);
```

> Note: `log`, `mailer`, and `connection` are provided by **citomni/infrastructure** and are not part of the HTTP baseline. This package only references them when present.

---

## Request / Response quick notes

**Request**

* Proxy awareness: `http.trust_proxy` + `http.trusted_proxies`
* `baseUrl()`, `fullUrl()`, `host()`, `port()`, `ip()` (with CIDR trust list)
* `json()` (auto content-type guard; `+json` supported)

**Response**

* `json()/jsonStatus()/jsonProblem()` (`never` return; sends headers+exits)
* `memberHeaders()` / `adminHeaders()` set sane security headers
* `download($path, $name)` with `X-Content-Type-Options: nosniff`

**Session / Cookie**

* Deterministic INI init (secure defaults), Samesite/secure logic
* Flash storage (`flash()`, `pull()`, `reflash()`)

**View**

* Renders via LiteView; exposes helpers: `url()`, `asset()`, `csrfField()`, etc.

**Security**

* CSRF token helpers (`csrfToken()`, `verifyCsrf()`, `csrfHiddenInput()`)

**Nonce**

* File-backed nonce ledger; atomic create; TTL-based purge; replay protection

**Maintenance**

* 503 guard with `Retry-After`, allow-list, flag backup + pruning

**WebhooksAuth**

* HMAC verify with TTL + clock skew tolerance, optional context binding, IP allow-list, nonce replay protection.
* Example (strict mode):

  ```php
  $raw = \file_get_contents('php://input') ?: '';
  $this->app->webhooksAuth
  	->setOptions($this->app->cfg->webhooks)
  	->assertAuthorized($_SERVER, $raw); // throws on failure
  ```

#### Client signing example (PHP)

```php
<?php
$secret = 'shared-secret';
$ts     = time();
$nonce  = bin2hex(random_bytes(16));
$body   = json_encode(['event' => 'ping'], JSON_UNESCAPED_UNICODE);

// Simple mode base: "<ts>.<nonce>.<rawBody>"
$base = $ts . '.' . $nonce . '.' . $body;
$sig  = hash_hmac('sha256', $base, $secret); // hex

$ch = curl_init('https://example.com/admin/webhook');
curl_setopt_array($ch, [
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => $body,
	CURLOPT_HTTPHEADER     => [
		'Content-Type: application/json',
		'X-Citomni-Timestamp: ' . $ts,
		'X-Citomni-Nonce: ' . $nonce,
		'X-Citomni-Signature: ' . $sig,
	],
	CURLOPT_RETURNTRANSFER => true,
]);
$resp = curl_exec($ch);
```

> If you enable `bind_context`, the client must build the canonical string exactly as documented (METHOD, PATH, QUERY, `sha256(body)` on separate lines).

---

#### CSRF example (controller + view)

**Controller (POST handler):**

```php
public function submit(): void {
	$ok = $this->app->security->verifyCsrf($this->app->request->post('csrf_token'));
	if (!$ok) {
		$this->app->security->logFailedCsrf('form.submit');
		$this->app->response->jsonProblem('Invalid CSRF token', 422);
	}
	// ... handle form
	$this->app->response->jsonStatus(['ok' => true], 200);
}
```

**Form (LiteView template):**

```html
<form method="post" action="{{ url('submit') }}">
	{{ csrfField()|raw }}
	<!-- your fields -->
	<button type="submit">Send</button>
</form>
```

---

## Providers (optional)

Providers export their own config/services and are explicitly whitelisted:

**`/config/providers.php`**

```php
<?php
return [
	\Vendor\Foo\Boot\Services::class, // contributes MAP_HTTP + CFG_HTTP
	\Vendor\Bar\Boot\Services::class,
];
```

Providers merge **between** vendor baseline and app overrides (**last wins**).

---

## Error handling

If present, Kernel installs `\CitOmni\Http\Exception\ErrorHandler` using **config** under `cfg['error_handler']` (not runtime args). It supports:

* JSON-lines log file (with rotation by size)
* Friendly details in **dev**; safe minimal output in **prod**
* Optional mail notification via `error_log()` (recipient/sender)

---

## Maintenance mode

Flag file (app-owned): `/var/flags/maintenance.php` returns:

```php
<?php
return [
	'enabled'     => true,
	'allowed_ips' => ['1.2.3.4'],
	'retry_after' => 600,
];
```

HTTP will emit **503** with `Retry-After`; allow-listed IPs bypass maintenance.

---

## Compiled caches (optional, recommended for prod)

Pre-merge and cache:

* `/var/cache/cfg.http.php` -> merged cfg
* `/var/cache/services.http.php` -> final service map

Warm from code:

```php
$result = $this->app->warmCache(overwrite: true, opcacheInvalidate: true);
```

Writes are **atomic** (`tmp` + `rename`), with best-effort OPcache invalidation.

---

#### Security checklist

* [ ] **Prod**: Set absolute `http.base_url` **or** `CITOMNI_PUBLIC_ROOT_URL`
* [ ] **Cookies**: Use `SameSite=None` **only** with `Secure=true`
* [ ] **HTTPS**: Enable HSTS (`adminHeaders()` sets it automatically when HTTPS)
* [ ] **Proxy**: Set `http.trust_proxy=true` + correct `trusted_proxies`
* [ ] **CSRF**: Enable and verify tokens on state-changing routes
* [ ] **Maintenance**: Protect with allow-list; enable backup policy
* [ ] **Webhooks**: Configure `webhooks.secret`, `nonce_dir`, and reasonable `ttl_seconds`
* [ ] **Error output**: `display_errors=false` in prod; use ErrorHandler logging
* [ ] **Sessions**: Consider `rotate_interval` for fixation resistance

---

## Performance tips

* **Composer**

  ```json
  {
    "config": {
      "optimize-autoloader": true,
      "classmap-authoritative": true,
      "apcu-autoloader": true
    }
  }
  ```

  Then: `composer dump-autoload -o`

* **OPcache (prod)**

  ```
  opcache.enable=1
  opcache.validate_timestamps=0   ; reset on deploy
  opcache.revalidate_path=0
  opcache.save_comments=0         ; if you don't need docblocks at runtime
  realpath_cache_size=4096k
  realpath_cache_ttl=600
  ```

* Keep vendor HTTP **baseline lean**. Put optional integrations in providers.

---

## Dev utilities

* Add `?_perf=1` to any URL in **dev** to print execution time, memory, and included files as HTML comments.
* `App::memoryMarker($label, $asHeader=false)` prints a compact perf line (dev only).

---

## Backwards compatibility

* Kernel defines `CITOMNI_PUBLIC_ROOT_URL` (no trailing slash).
* You may keep defining `CITOMNI_APP_PATH` and `CITOMNI_PUBLIC_PATH` in `index.php`.
* Old route entries using FQCN strings still work; prefer `::class` for IDE/rename safety.

---

## FAQ

**Q: Should I auto-detect base URL in prod?**
A: No. Dev -> auto-detect; stage/prod -> set absolute URL in `citomni_http_cfg.{env}.php` or define `CITOMNI_PUBLIC_ROOT_URL`.

**Q: Can I add per-service options?**
A: Yes. All services accept `__construct(App $app, array $options = [])`. Put options in `/config/services.php`.

**Q: Where do role/text helpers come from?**
A: View exposes helpers that call services if present (`role`, `txt`). If those services aren't installed, the helpers gracefully fallback.

---

#### Troubleshooting

**"Base URL is wrong behind proxy"**
Set `http.trust_proxy=true`, fill `trusted_proxies`, and ensure your proxy sets `X-Forwarded-*` headers.

**"Headers already sent"**
Don't `echo`/`var_dump` before using `Response` methods. See `response_errors.json` (requires a log service).

**"CSRF fails on POST"**
Ensure the hidden input `<input name="csrf_token">` exists and matches `security.csrf_field_name`.

**"Nonce storage failed"**
`webhooks.nonce_dir` (or `var/nonces`) must exist and be writable by the PHP process.

---

## Contributing

* Code style: PHP 8.2+, PSR-4, **tabs**, K&R braces.
* Keep vendor files side-effect free (OPcache-friendly).
* Don't swallow exceptions in core; let the global error handler log.

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni HTTP** is open-source under the **MIT License**.  
See: [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may not use the CitOmni name or logo to imply endorsement or affiliation without prior written permission.  
For details, see the project [NOTICE](NOTICE).

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos,  
or imply sponsorship, endorsement, or affiliation without prior written permission.  
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.  
For details, see the project's [NOTICE](NOTICE).

---

## Author

Developed by **Lars Grove Mortensen** © 2012-present
Contributions and pull requests are welcome!

---

Built with ❤️ on the CitOmni philosophy: **low overhead**, **high performance**, and **ready for anything**.
