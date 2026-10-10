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
* **HTTP ErrorHandler** installed at boot -> JSONL logs with rotation, no blank pages
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
    providers.php                # optional list of provider Registry FQCNs
    citomni_cfg.php              # optional app config shared by HTTP and CLI
    citomni_http_cfg.php         # app baseline config (HTTP)
    citomni_http_cfg.stage.php   # optional per-env overlay
    citomni_http_cfg.prod.php    # optional per-env overlay
    citomni_http_routes.php      # app routes (see Routes)
    services.php                 # optional service map overrides/additions
    services_http.php            # optional HTTP-only service map (wins over services.php)
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

Vendor HTTP baseline lives in `\CitOmni\Http\Boot\Registry::CFG_HTTP`.
At runtime, the app builds config as:

1. **Vendor HTTP baseline**
2. **Provider cfg** (listed in `/config/providers.php`): `CFG_COMMON`, then `CFG_HTTP`, per provider in list order
3. **App base cfg** `/config/citomni_cfg.php`, then `/config/citomni_http_cfg.php` (both optional)
4. **App env overlay** `/config/citomni_cfg.{env}.php`, then `/config/citomni_http_cfg.{env}.php` (both optional)

**Merge rules:**

* Associative arrays -> merged per key, **last wins**
* Numeric lists -> **replaced** by the last source
* Empty values (`''`, `false`, `0`, `null`, `[]`) are valid overrides and still win

**Deep access via read-only wrapper:**

```php
$this->app->cfg->locale->timezone;
$this->app->cfg->http->base_url;
$this->app->routes['/']; // routes are a plain array on the App, not cfg (see Routes)
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
		'render' => [
			'trigger' => 0,                // non-fatal PHP errors to render; fatals always render
			'detail'  => ['level' => 0],   // 1 = developer details, effective only in dev
		],
		'log' => [
			'path'      => CITOMNI_APP_PATH . '/var/logs', // '' falls back to this directory
			'max_bytes' => 2_000_000,      // rotate before a write passes this size
			'max_files' => 10,             // rotated files kept per log
		],
		// Own error page (see Custom error pages). Leave the key out otherwise:
		// an empty 'templates' => [] would drop the vendor templates.
		// 'templates' => [
		// 	'html' => CITOMNI_APP_PATH . '/templates/errors/error.php',
		// ],
	],

	'session' => [
		'name'             => 'CITSESSID',
		'save_path'        => CITOMNI_APP_PATH . '/var/state/php_sessions',
		// 'gc_maxlifetime' => 1440,   // seconds PHP keeps idle session data. citomni/authenticate raises it
		                              // to its idle timeout; app cfg wins, so never set it lower than that.
		'gc_probability'   => 1,        // with gc_divisor: GC chance per session start
		'gc_divisor'       => 1000,
		'use_strict_mode'  => true,
		'use_only_cookies' => true,
		'lazy_write'       => true,
		// Session cookie overrides; null takes the attribute from 'cookie' below
		'cookie_secure'    => null,
		'cookie_httponly'  => true,     // pinned: Scripts must not read the session id
		'cookie_samesite'  => null,
		'cookie_path'      => null,
		'cookie_domain'    => null,     // '' forces host-only
	],

	'cookie' => [
		'secure'   => null,     // null: Inferred from base_url, CITOMNI_PUBLIC_ROOT_URL or the request
		'httponly' => true,
		'samesite' => 'Lax',
		'path'     => '/',
		'domain'   => null,     // null: Host-only; 'example.com' shares cookies with subdomains
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

	// Routes are not cfg: they live in /config/citomni_http_routes.php (see Routes).
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

If you run behind Nginx/Apache/Cloudflare, configure **`http.trust_proxy`** and **`http.trusted_proxies`** correctly. Only include **trusted** proxy IPs/CIDR blocks, but include **every** proxy in the chain (load balancer, CDN ranges): the client IP is read from the right end of `X-Forwarded-For`, and the first address that is not a trusted proxy counts as the client.

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

Routes are not cfg. App routes live in `/config/citomni_http_routes.php`, with an optional `/config/citomni_http_routes.{env}.php` overlay. The kernel merges them (last wins) on top of the vendor baseline `\CitOmni\Http\Boot\Registry::ROUTES_HTTP` and the providers' `ROUTES_HTTP`, and exposes the result as `$this->app->routes`. The scaffolded routes file documents the merge and matching rules.

**Placeholders available:**

* `{id}` -> `[0-9]+`
* `{email}` -> `[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}`
* `{slug}` -> `[a-zA-Z0-9-_]+`
* `{code}` -> `[a-zA-Z0-9]+`
  Unknown placeholders fall back to `[^/]+`.

#### Custom error pages

Error pages are not routes. The Router hands 404, 405 and 500 to `ErrorHandler::httpError()`, which logs the error and renders the page. To change the HTML page, point `error_handler.templates.html` (and optionally `templates.html_failsafe`) at your own plain PHP template:

```php
'error_handler' => [
	'templates' => [
		'html' => CITOMNI_APP_PATH . '/templates/errors/error.php',
	],
],
```

The template receives a `$data` array: `language`, `status`, `status_text`, `error_id`, `title`, `message`, `details` (only in dev with `render.detail.level` 1, otherwise null), `request_id` and `year`. Clients that send an `Accept` header with `application/json` or `+json`, or `X-Requested-With: XMLHttpRequest`, get JSON instead.

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
* `$captchaField(): string` - hidden `<input>` with this request's captcha challenge id; `$captchaUrl(): string` - absolute URL of its image. Both are empty strings when captcha protection is off or the service is missing. See [Captcha](#captcha).
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
\CitOmni\Http\Boot\Registry::MAP_HTTP
// [
	'errorHandler' => \CitOmni\Http\Service\ErrorHandler::class,
	'request'      => \CitOmni\Http\Service\Request::class,
	'response'     => \CitOmni\Http\Service\Response::class,
	'router'       => \CitOmni\Http\Service\Router::class,
	'session'      => \CitOmni\Http\Service\Session::class,
	'flash'        => \CitOmni\Http\Service\Flash::class,
	'datetime'     => \CitOmni\Http\Service\Datetime::class,
	'cookie'       => \CitOmni\Http\Service\Cookie::class,
	'tplEngine'    => \CitOmni\Http\Service\TemplateEngine::class,
	'csrf'         => \CitOmni\Http\Service\Csrf::class,
	'captcha'      => \CitOmni\Http\Service\Captcha::class,
	'nonce'        => \CitOmni\Http\Service\Nonce::class,
	'maintenance'  => \CitOmni\Http\Service\Maintenance::class,
	'webhooksAuth' => \CitOmni\Http\Service\WebhooksAuth::class,
	'slugger'      => \CitOmni\Http\Service\Slugger::class,
	'tags'         => \CitOmni\Http\Service\Tags::class,
	'upload'       => \CitOmni\Http\Service\Upload::class,
	'icon'         => \CitOmni\Http\Service\Icon::class,
// ]
```

Extend/override in `/config/services.php` (HTTP and CLI) or `/config/services_http.php` (HTTP only). Precedence: `services_http.php` > `services.php` > providers (`MAP_COMMON`, then `MAP_HTTP`) > vendor baseline.

```php
<?php
return [
	// Simple override:
	'router' => \App\Http\Service\Router::class,

	// With options (constructor is __construct(App $app, array $options = []))
	'myService' => [
		'class'   => \App\Http\Service\MyService::class,
		'options' => ['timeout' => 5],
	],
];
```

Use anywhere:

```php
$this->app->response->noCache();
$this->app->request->json();
$this->app->maintenance->enable(['1.2.3.4']);
```

> Note: `log` and `txt` come from **citomni/infrastructure**, `auth` and `role` from **citomni/authenticate**, and `captchaImage` from **citomni/image**. They are not part of the HTTP baseline; this package checks for them with `hasService()` before use.

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

* `Cookie` owns the default attributes of every cookie, the session cookie included: Host-only unless `cookie.domain` is set, HttpOnly, `SameSite=Lax`, and Secure inferred from `http.base_url`, `CITOMNI_PUBLIC_ROOT_URL` or the request. `attributes()` resolves overrides on top; invalid values throw.
* `Session` owns storage: Save path, retention (`gc_maxlifetime`) and garbage collection are applied explicitly, and a setting PHP refuses throws. `session.cookie_*` overrides single attributes of the session cookie, whose lifetime is always 0.
* `gc_maxlifetime` is storage retention, not a login lifetime. Debian and Ubuntu ship `gc_probability=0` and clean only their php.ini save paths from cron, so the baseline sets 1/1000 explicitly; set `gc_probability` to 0 only when a scheduled job cleans `session.save_path`.
* `regenerate(true)` belongs at privilege changes. It deletes the old session at once, so a concurrent request with the old id gets a new, empty session. `session.rotate_interval` and `session.fingerprint` were removed; Session throws while either is enabled.
* Reads never create a session: `get()`, `has()`, `remove()` and `destroy()` resume a session that the request's cookie (or an earlier start in the same request) names, and otherwise return without a cookie, a session file or cache headers. Writes (`set()`, `start()`, `regenerate()`) create it. Flash readers and CSRF verification follow the same rule, so a guest page that reads them stays session-free.
* Flash (`$this->app->flash`): Messages, old input and field errors across one redirect; `pullAll()` reads and clears, `keep()` keeps them for one more read.

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

## Captcha

The `captcha` service runs a session-backed challenge for HTML forms: issue a code, show its image, verify the post once. Codes and images come from the `captchaImage` service in [citomni/image](https://github.com/citomni/image), so install that package and register its provider in `/config/providers.php` (`\CitOmni\Image\Boot\Registry::class`).

**1. Route the image (opt-in).** This package registers no captcha route. Add one to `/config/citomni_http_routes.php`, at the path in `security.captcha.image_path`:

```php
'/captcha.png' => [
	'controller' => \CitOmni\Http\Controller\CaptchaController::class,
	'action'     => 'image',
	'methods'    => ['GET'],
],
```

**2. Put the challenge in the form.** `$captchaField()` issues the request's challenge and renders its id as a hidden field; `$captchaUrl()` is the image of the same challenge:

```html
{% if ($captcha_protection) %}
	{{{ $captchaField() }}}
	<img src="{{ $captchaUrl() }}" width="200" height="64" alt="Security code">
	<label for="captcha">Code</label>
	<input type="text" id="captcha" name="captcha" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
{% endif %}
```

**3. Verify the post:**

```php
if (!$this->app->captcha->verify()) {
	// Wrong, expired or missing: render the form again; it gets a new challenge.
}
```

Behavior:

* Every challenge gets exactly one attempt. `verify()` removes it before comparing, right or wrong, so a solved image cannot be replayed.
* Challenges expire after `security.captcha.ttl` seconds. Up to `max_pending` challenges are kept per session, so a form open in several tabs keeps working; issuing more drops the oldest.
* A challenge's image is rendered with a stored seed, so reloading it shows the same picture instead of a second distortion to compare.
* Answers are compared case-insensitively, ignoring whitespace, in constant time (`captchaImage->verify()`).
* With `security.captcha_protection` off, `verify()` passes, `$captchaField()` and `$captchaUrl()` render nothing, and the image route answers 404.
* The image route answers 404 without a body for missing, unknown or expired ids, and starts no session for a request without the session cookie.
* Issuing starts the session. As with `$csrfField()`, render the form before output is flushed, or keep output buffering on.
* Without citomni/image, issuing a challenge fails with `CaptchaConfigException`.

Configuration baseline:

```php
'security' => [
	'captcha_protection' => true,
	'captcha' => [
		'session_key'  => '_captcha',
		'ttl'          => 1200,           // Keep below session.gc_maxlifetime.
		'max_pending'  => 5,
		'id_field'     => 'captcha_id',
		'answer_field' => 'captcha',
		'image_path'   => '/captcha.png',
	],
],
```

Outside HTML forms, use `current()` and `imagePath()` for the challenge and `verifyAnswer($id, $answer)` for the check.

A text captcha slows down generic form bots. It does not stop targeted attacks with OCR or vision models, and it excludes users who cannot solve visual challenges. Rate-limit the image route (each image costs a few milliseconds of CPU), combine the captcha with the honeypot and server-side validation, and offer another way to reach you where accessibility matters. The Danish `forms_common` language file has a message for a wrong code (`err_incorrect_captcha`).

---

## Providers (optional)

Providers export their own config, services and routes through a `Boot\Registry` class and are explicitly whitelisted:

**`/config/providers.php`**

```php
<?php
return [
	\Vendor\Foo\Boot\Registry::class, // may define CFG_COMMON, CFG_HTTP, MAP_COMMON, MAP_HTTP, ROUTES_HTTP
	\Vendor\Bar\Boot\Registry::class,
];
```

Providers merge **between** vendor baseline and app overrides (**last wins**).

---

## Error handling

The Kernel installs the `errorHandler` service (`\CitOmni\Http\Service\ErrorHandler`) right after boot. It logs and answers every uncaught exception, fatal error and router 404/405/5xx with an HTML or JSON page, and is configured under `error_handler`:

* `render.trigger`: non-fatal PHP error levels that also render a page (baseline `0`; fatals always render)
* `render.detail.level`: `1` adds developer details to the page, only when `CITOMNI_ENVIRONMENT` is `dev`
* `log.trigger`, `log.path`, `log.max_bytes`, `log.max_files`: JSONL logs, one file per category (e.g. `http_err_exception.jsonl`, `http_router_404.jsonl`), with size-based rotation. An empty `log.path` falls back to `CITOMNI_APP_PATH . '/var/logs'`.
* `templates.html`, `templates.html_failsafe`: plain PHP templates for HTML pages (see [Custom error pages](#custom-error-pages))
* `status_defaults`: fallback statuses for exceptions, shutdown fatals and PHP errors

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
* [ ] **Error output**: Keep `error_handler.render.trigger` and `render.detail.level` at the baseline `0` outside dev; the ErrorHandler sets `display_errors=0` itself
* [ ] **Sessions**: Rotate with `regenerate(true)` at login, logout and privilege changes; keep `gc_maxlifetime` at least as long as the longest idle login
* [ ] **Cookie domain**: Leave `cookie.domain` null (host-only) unless subdomains must share the cookies

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
