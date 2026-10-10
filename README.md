# CitOmni HTTP

Deterministic HTTP bootstrapping, routing, requests, responses, templating, sessions, cookies, and web security for CitOmni applications.

`citomni/http` is CitOmni's HTTP delivery layer. It boots an application in HTTP mode, composes configuration, routes, and services through `citomni/kernel`, dispatches requests to explicit controllers, and provides the common HTTP-facing tools those controllers need.

The package separates **HTTP mechanics** from application logic. It owns how a request enters an app, which controller receives it, how a response is emitted, and the transport-specific safeguards around that process. It does not own an application's domain model, database queries, authentication identities, or business workflows.

Services are registered by stable IDs and resolved lazily through the CitOmni `App`. Routing is an explicit map, not a controller scan. Templates use CitOmni's own syntax and explicit layers, not an assumed Twig runtime.

---

## Highlights

- **PHP 8.5+** with Composer and `citomni/kernel`.
- **One HTTP lifecycle** through `\CitOmni\Http\Kernel::run()` with early error handling, POST-size protection, maintenance enforcement, and routing.
- **Deterministic configuration, service, and route maps** composed from vendor defaults, providers, application files, and environment overlays.
- **Explicit exact and placeholder routes** with method negotiation, automatic `HEAD`/`OPTIONS`, and consistent 404/405/500 handling.
- **App-aware request and response services** for input, JSON, headers, proxy-aware client information, redirects, API responses, and downloads.
- **CitOmni TemplateEngine** with layered references, escaped and raw output, layouts, partials, helper closures, and compiled caches.
- **Layered CSRF protection** using Fetch Metadata, Origin/Referer, and session tokens, with optional token masking.
- **Deliberate session lifecycle** where reads do not create a new session, writes start one, and sensitive ID rotation is explicit.
- **Shared cookie attribute policy** for application cookies and the session cookie, including host-only defaults and `SameSite` validation.
- **Flash messages, old input, and validation errors** for POST/Redirect/GET flows.
- **Optional image-backed captcha challenges** with one attempt per challenge, using `citomni/image` for code rendering.
- **HMAC-authenticated webhooks** with timestamp freshness, context binding, optional IP restrictions, and filesystem nonce replay protection.
- **Maintenance mode** backed by an atomic flag file, a client IP allowlist, and HTTP 503 responses.
- **Structured HTTP error logging** with rotation, request correlation, and safe error-page fallbacks.
- **HTTP diagnostics and operations endpoints** with access restrictions appropriate to their purpose.
- **Small supporting services** for localized dates, inline icons, slugs, tags, and the package's existing HTTP upload workflows.

---

## What this package is

`citomni/http` is the transport adapter for browser and HTTP API applications built on CitOmni.

In a typical request it:

1. Starts a response buffer.
2. Builds the HTTP-mode `App` from configuration, provider registrations, and optional compiled maps.
3. Installs the HTTP error handler and applies runtime locale, charset, and timezone policy.
4. Resolves the application's public URL and configured trusted proxies.
5. Rejects POST requests whose declared body length exceeds PHP's `post_max_size`.
6. Enforces the maintenance flag.
7. Matches the request against the explicit route table and invokes its controller action.
8. Lets the action render a template or produce a response.

The kernel does not turn every application into a large HTTP framework. Controllers remain thin adapters, and shared business behavior belongs in Operations, Repositories, or other appropriate CitOmni layers.

---

## What this package provides

The vendor HTTP service map currently registers these IDs:

| Service ID | Class | Responsibility |
|---|---|---|
| `request` | `Service\Request` | HTTP input, headers, JSON, URL and client IP |
| `response` | `Service\Response` | Status, headers, redirects, JSON, HTML, text and downloads |
| `router` | `Service\Router` | Route matching and controller dispatch |
| `errorHandler` | `Service\ErrorHandler` | Errors, structured logs and fallback responses |
| `tplEngine` | `Service\TemplateEngine` | Layered template compilation and rendering |
| `session` | `Service\Session` | PHP session storage and identity lifecycle |
| `cookie` | `Service\Cookie` | Cookie writing, reading and attribute resolution |
| `flash` | `Service\Flash` | One-redirect messages, form input and errors |
| `csrf` | `Service\Csrf` | CSRF tokens and layered request verification |
| `captcha` | `Service\Captcha` | Session-backed captcha challenge lifecycle |
| `webhooksAuth` | `Service\WebhooksAuth` | Signed inbound webhook authentication |
| `nonce` | `Service\Nonce` | Single-use, filesystem-backed replay ledger |
| `maintenance` | `Service\Maintenance` | Maintenance flag management and HTTP guard |
| `datetime` | `Service\Datetime` | Localized date/time formatting |
| `icon` | `Service\Icon` | Layer-owned inline SVG icon lookup |
| `slugger` | `Service\Slugger` | Slug normalization, validation and uniqueness planning |
| `tags` | `Service\Tags` | Tag parsing, normalization and change planning |
| `upload` | `Service\Upload` | Existing HTTP column and attachment upload workflows |

These are **18** vendor baseline service IDs. Applications and providers can override registrations through the service-map merge. A different registered implementation may therefore occupy an ID in a particular application.

The package also supplies `Kernel`, its vendor `Boot\Registry`, public and system controllers, a pure `Util\Url` helper, installation scaffolds, templates, translations, and package-specific exceptions.

---

## What this package owns

- The HTTP kernel and request lifecycle.
- The vendor HTTP service, configuration, and route baselines.
- Request parsing and HTTP transport details.
- HTTP route dispatch and method policy.
- Response status, headers, bodies, and downloads.
- Trusted template loading, compilation, and rendering.
- HTTP-oriented CSRF, captcha, cookie, session, and webhook integration.
- Filesystem nonce claims and maintenance flags.
- HTTP error presentation and structured error logs.
- The supplied system routes and installation scaffolds.

---

## What this package does not own

- Application business operations and domain decisions.
- SQL, database schema, or persistence repositories.
- User authentication, accounts, roles, or MFA; those belong to `citomni/authenticate`.
- The underlying image-processing engine; captcha rendering delegates to `citomni/image`.
- The `txt`, database, mail, and application log services from `citomni/infrastructure`.
- Automated CSRF validation on every controller action. A controller must explicitly invoke the CSRF service where needed.
- Webhook delivery by a remote sender. The package verifies inbound requests; the sending system must implement the signing protocol.
- An external template language such as Twig.
- Generic remote file storage, object storage, antivirus scanning, or a database-backed media library.

Application-specific validation and authorization remain the responsibility of the caller, even when the HTTP layer provides useful primitives.

---

## Relationship to other CitOmni packages

```text
citomni/kernel
      ↑
citomni/http
      ↑
HTTP application

Optional integrations used where registered:
  citomni/infrastructure  -> txt, log and other shared services
  citomni/image           -> captchaImage for captcha rendering
  citomni/authenticate    -> identity, roles and auth-aware template helpers
```

`citomni/http` requires `citomni/kernel` but does not require every optional integration. Features depending on another service must have that service registered before use; missing required integrations are not silently emulated.

`citomni/cli` provides the separate CLI transport. Business Operations and Repositories can be shared between HTTP and CLI without embedding request or response handling in them.

---

## Requirements

- PHP **8.5+**.
- Composer with `citomni/kernel` **^1.0.2.5** (required by `composer.json`).
- PHP `ext-intl` in HTTP mode; `Kernel::boot()` checks this explicitly.
- A writable application `var/` area for the state, cache, log, or nonce features actually used.
- A web server/front controller configured to forward application requests to `public/index.php`.

Depending on the selected features:

- PHP `ext-opcache` is recommended for production performance and supports the OPcache administration actions when available.
- PHP `ext-fileinfo` improves MIME detection for downloads and is used by the existing upload service when available.
- GD and `citomni/image` are needed for image-backed captcha rendering; the image package has its own runtime requirements.
- PHP `ext-mbstring` is useful for Unicode-sensitive operations but is not a declared package requirement.

The package does not declare `citomni/infrastructure`, `citomni/image`, or `citomni/authenticate` as unconditional Composer requirements.

---

## Installation

Install the package as a Composer dependency in an application:

```bash
composer require citomni/http
```

Applications autoload their own code, typically with:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

Then rebuild the autoloader if needed:

```bash
composer dump-autoload -o
```

The HTTP baseline comes from `\CitOmni\Http\Boot\Registry` through CitOmni's HTTP-mode application assembly. You do **not** need to copy package source into the application or manually recreate its baseline service and route maps.

The package includes an installer manifest and scaffolds for the app's `public/`, `config/`, `src/Http/`, `templates/`, and supporting server/runtime files. Installation of these scaffolds is handled through CitOmni's installer workflow; **`composer require` alone is not a promise that those application files are materialized**.

For existing applications, inspect the scaffold files under `install/scaffold/` and preserve local application configuration. The manifest distinguishes `create-only` files from installer-managed files.

### Application layout

A typical HTTP application uses:

```text
app-root/
├── config/
│   ├── providers.php
│   ├── citomni_cfg.php
│   ├── citomni_http_cfg.php
│   ├── citomni_http_cfg.dev.php
│   ├── citomni_http_cfg.stage.php
│   ├── citomni_http_cfg.prod.php
│   ├── citomni_http_routes.php
│   ├── citomni_http_routes.dev.php
│   ├── citomni_http_routes.stage.php
│   ├── citomni_http_routes.prod.php
│   ├── services.php
│   └── services_http.php
├── public/
│   └── index.php
├── src/
│   └── Http/
│       ├── Controller/
│       └── Exception/
├── templates/
├── language/
├── var/
│   ├── cache/
│   ├── flags/
│   ├── logs/
│   ├── nonces/
│   ├── secrets/
│   └── state/
└── vendor/
```

Only the files and directories actually needed by the application have to be populated. The package's installer manifest supplies its specific starter files and access restrictions.

---

## Quick start

### HTTP entrypoint

The following is the relevant minimal boot sequence used by the supplied entrypoint scaffolds:

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_NS', hrtime(true));
define('CITOMNI_ENVIRONMENT', 'dev');
define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', dirname(__DIR__));

require CITOMNI_APP_PATH . '/vendor/autoload.php';

\CitOmni\Http\Kernel::run(__DIR__);
```

Use `stage` or `prod` in the corresponding deployed entrypoint. For a non-development environment, define an absolute `CITOMNI_PUBLIC_ROOT_URL` early or configure an absolute `http.base_url`.

### Declare a route

In `config/citomni_http_routes.php`:

```php
<?php
declare(strict_types=1);

return [
	'/hello' => [
		'controller' => \App\Http\Controller\HelloController::class,
		'action' => 'index',
		'methods' => ['GET'],
	],
];
```

### Implement its controller

In `src/Http/Controller/HelloController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class HelloController extends BaseController {
	public function index(): void {
		$this->app->tplEngine->render('public/hello.html@app', [
			'title' => 'Hello from CitOmni',
		]);
	}
}
```

In `templates/public/hello.html`:

```html
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>{{ $title }}</title>
</head>
<body>
	<h1>{{ $title }}</h1>
</body>
</html>
```

The `{{ ... }}` expression is **CitOmni TemplateEngine syntax** and is HTML-escaped by default. The route need not specify a template hint when the controller supplies an explicit reference itself.

---

## HTTP kernel and request lifecycle

`\CitOmni\Http\Kernel` exposes:

```php
\CitOmni\Http\Kernel::run(__DIR__);
$app = \CitOmni\Http\Kernel::boot(__DIR__);
```

`run()` performs the complete HTTP request lifecycle and is the normal front-controller entry point. `boot()` returns an HTTP-mode `App` without running maintenance and route dispatch, useful for controlled integrations or test setup.

Both accept an application root, its `config/` directory, or its `public/` directory as entry path. The `$opts` argument currently exists but is reserved and unused; do not depend on it for hidden boot overrides.

The important runtime order is:

```text
Kernel::run()
  output buffering
  Kernel::boot()
    resolve app/config/public directories
    create App in HTTP mode
    install ErrorHandler
    configure runtime timezone, charset and locale
    require ext-intl
    resolve public root URL
    configure Request trusted proxies
  Request::exceedsPostMaxSize() -> 413 when exceeded
  Maintenance::guard()       -> 503 when blocked
  Router::run()              -> controller or HTTP error
  optional _perf diagnostics
```

The early `post_max_size` check prevents a too-large POST body, discarded by PHP, from later appearing to the application merely as a missing CSRF token or missing form fields. It relies on a valid declared `CONTENT_LENGTH`, applies to POST, and treats nonpositive `post_max_size` as unlimited.

### Public root URL

The canonical URL follows this policy:

1. Preserve an already-defined `CITOMNI_PUBLIC_ROOT_URL`.
2. In `dev`, use an absolute `http.base_url` if configured; otherwise make a best-effort inference from the request.
3. In `stage` and `prod`, require an absolute `http.base_url` if the constant has not already been defined; otherwise throw rather than guess.

Use a trusted, explicit canonical URL for deployments. Avoid a trailing slash. In development, automatic inference is a convenience, not a production security policy.

### Runtime diagnostics

When `?_perf` is present **and the controller action returns to the kernel**, the kernel appends execution time, memory use, and included-file count as an HTML comment. Responses that terminate execution (such as `response->json()`) do not reach that footer. Outside `prod`, the footer can also disclose the included-file list and absolute filesystem paths; restrict diagnostic access in development and staging.

---

## Configuration, service maps, and routes

CitOmni composes three different kinds of runtime data. They should not be conflated.

### Configuration

The merge order for HTTP configuration, from lowest to highest priority, is:

1. `\CitOmni\Http\Boot\Registry::CFG_HTTP`.
2. Provider `CFG_COMMON` and `CFG_HTTP`, in provider registration order.
3. `config/citomni_cfg.php`.
4. `config/citomni_http_cfg.php`.
5. `config/citomni_cfg.{ENV}.php`.
6. `config/citomni_http_cfg.{ENV}.php`.

Associative values are merged by key with later values taking precedence. Lists are replaced rather than accumulated. Configuration is exposed through the read-only `$this->app->cfg` tree; absent properties fail fast on direct access, while `??` is safe for optional keys.

Example `config/citomni_http_cfg.prod.php`:

```php
<?php
declare(strict_types=1);

return [
	'http' => [
		'base_url' => 'https://example.com',
		'trust_proxy' => false,
		'router_case_insensitive' => false,
	],
	'cookie' => [
		'secure' => true,
		'httponly' => true,
		'samesite' => 'Lax',
		'domain' => null,
	],
	'error_handler' => [
		'render' => [
			'trigger' => 0,
			'detail' => ['level' => 0],
		],
	],
];
```

`null` is meaningful for some keys, for example host-only `cookie.domain` and inferred `cookie.secure`. Never add arbitrary secret values to general configuration just because it is convenient.

### Service definitions

The HTTP service-map precedence is:

1. `\CitOmni\Http\Boot\Registry::MAP_HTTP`.
2. Provider `MAP_COMMON`, then `MAP_HTTP`.
3. `config/services.php`.
4. `config/services_http.php`.

Later definitions override earlier ones. Supported definitions are a class name or a `class`/`options` array. App lazily resolves them once per `App`:

```php
$this->app->request;
$this->app->response;
$this->app->csrf;

$this->app->hasService('csrf');
```

Example service override in `config/services_http.php`:

```php
<?php
declare(strict_types=1);

return [
	'slugger' => [
		'class' => \CitOmni\Http\Service\Slugger::class,
		'options' => [
			'maxLength' => 100,
		],
	],
];
```

Use provider registration for package integrations. Do not construct or copy the vendor service registry inside each application.

### Route definitions

Routes are assembled separately from configuration:

1. `\CitOmni\Http\Boot\Registry::ROUTES_HTTP`.
2. Provider `ROUTES_HTTP` contributions.
3. `config/citomni_http_routes.php`.
4. `config/citomni_http_routes.{ENV}.php`.

The merged route array is exposed as `$this->app->routes`, **not** `$this->app->cfg->routes`. Routes with the same key merge their associative fields, with later values winning; the `methods` list is replaced as one value. A wholly empty route layer contributes nothing, while an explicit nested `'regex' => []` can replace the earlier regex bucket.

`config/citomni_http_routes.dev.php` can supply development-only routes without modifying production routing. Route errors are handled by `ErrorHandler`, not by numeric `403`, `404`, or `500` entries in the route table.

---

## Routing

### Exact routes

```php
return [
	'/contact' => [
		'controller' => \App\Http\Controller\ContactController::class,
		'action' => 'index',
		'methods' => ['GET'],
		'template_file' => 'public/contact.html',
		'template_layer' => 'app',
	],
];
```

`controller` is a real FQCN and `action` is the method to invoke (default `index`). Optional `template_file` and `template_layer` entries are supplied to the controller as route configuration; the router does not automatically render them.

### Placeholder routes

Put placeholder patterns in the `regex` bucket:

```php
return [
	'regex' => [
		'/articles/{id}' => [
			'controller' => \App\Http\Controller\ArticleController::class,
			'action' => 'show',
			'methods' => ['GET'],
		],
	],
];
```

The controller receives captures as positional action arguments:

```php
public function show(string $id): void {
	$this->app->tplEngine->render('public/article.html@app', [
		'id' => $id,
	]);
}
```

Built-in placeholder rules:

| Placeholder | Accepted input |
|---|---|
| `{id}` | ASCII digits |
| `{email}` | Address-like ASCII email pattern |
| `{slug}` | ASCII letters, digits, hyphens and underscores |
| `{code}` | ASCII letters and digits |
| Other names | One non-slash path segment |

A matched pattern is **not** permission to trust the capture. Validate IDs, ownership, authorization, and domain constraints in application code.

Exact routes are tested first. Placeholder routes are tested in declaration order, with anchored matching. The router strips the app base prefix, decodes percent-escapes, rejects non-ASCII decoded paths, and normalizes trailing slashes. `http.router_case_insensitive` optionally lowers exact paths and enables case-insensitive regex matching; leave it false in standard deployments.

### HTTP methods and errors

- A route without `methods` defaults to `GET`, `HEAD`, and `OPTIONS`.
- `GET` automatically allows `HEAD`.
- `OPTIONS` is answered by the router with HTTP 204 and an `Allow` header, without running the controller action.
- A disallowed method produces HTTP 405 and an `Allow` header.
- An unmatched route produces HTTP 404.
- A missing controller class or action produces HTTP 500.

`HEAD` is allowed when `GET` is allowed, but the same controller can still execute. Application code should not assume the action itself is skipped for `HEAD`.

**Routing is not a CSRF or authorization middleware.** A route permitting `POST` does not automatically call `$this->app->csrf->verify()`, and a matching path does not imply the caller is authenticated.

---
## Controllers and transport boundaries

An application HTTP controller normally extends `\CitOmni\Kernel\Controller\BaseController`. The base class provides the current App as `$this->app` and accepts the route metadata supplied by the router.

Controllers own input parsing, request validation, HTTP authentication/authorization checks, CSRF handling, and response shaping. They can call a Repository directly for truly trivial CRUD; non-trivial orchestration belongs in an Operation. SQL belongs in Repositories, not controllers or HTTP services.

A controller action should make its output behavior explicit:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class StatusController extends BaseController {
	public function index(): void {
		$this->app->response->jsonStatus([
			'up' => true,
			'time' => $this->app->datetime->now('yyyy-MM-dd HH:mm:ss'),
		]);
	}
}
```

`jsonStatus()` terminates the response. For an HTML page, call `tplEngine->render()` instead. There is no `view` service in the current vendor service map, and controller code should not depend on one.

Route metadata such as `template_file` and `template_layer` is available to controller implementations that use it; it is not a directive to the router to render a view automatically.

---

## Request

Access the current HTTP request through `$this->app->request`.

### Input and JSON

```php
$request = $this->app->request;

$method = $request->method();
$search = (string)($request->get('q') ?? '');
$email = (string)($request->post('email') ?? '');
$page = (int)$request->input('page', 1, 'get');

$hasEmail = $request->hasPost('email');
$filtered = $request->only(['email', 'name'], 'post');
$withoutSecrets = $request->except(['password', '_csrf'], 'post');

$json = $request->json(); // Decoded associative array, or null.
```

| Method | Behavior |
|---|---|
| `method()` | Uppercase method (`GET` by default) |
| `get(?string $key = null)` | One query value or the complete GET map |
| `post(?string $key = null)` | One submitted value or the complete POST map |
| `input(?string $key = null, mixed $default = null, string $source = 'auto')` | GET for GET requests, otherwise POST; explicit `get` or `post` supported |
| `has(string $key)` | Key exists in GET or POST, including an empty value |
| `hasPost(string $key)` | Key exists in POST |
| `only(array $keys, string $source = 'auto')` | Selected keys from one input map |
| `except(array $keys, string $source = 'auto')` | Input map without selected keys |
| `sanitize(string $key, string $method = 'both')` | Trimmed, HTML-escaped string or `null` |
| `json()` | Lazily decoded JSON map, or `null` for invalid/non-JSON input |

`sanitize()` is **output escaping**, not domain validation or SQL escaping. Validate length, type, range, encoding, and business rules separately. Prefer storing validated, unescaped data and escaping when rendering it.

JSON parsing accepts JSON content types including `application/json` and `+json` media types, reads the raw body once, and preserves large integers as strings where needed. `json()` returning `null` is not by itself a detailed error report; distinguish an expected object from empty, invalid, or unexpected input in your controller.

Native PHP file uploads arrive in `$_FILES`; `Request::hasFile()` is not part of the currently active API. See [Uploads](#uploads).

### Headers, client information, and URLs

```php
$request = $this->app->request;

$type = $request->contentType();
$token = $request->header('Authorization');
$agent = $request->getUserAgent();
$ip = $request->ip();

$path = $request->pathFromAppRoot();
$query = $request->queryString();
$absolute = $request->fullUrl();
$secure = $request->isHttps();
```

Other accessors include `headers()`, `cookie()`, `server()`, `referer()`, `scheme()`, `host()`, `port()`, `uri()`, `pathRaw()`, `queryAll()`, `baseUrl()`, `isSecure()`, and `isAjax()`. `getClientIp()` is an alias of `ip()`.

`pathRaw()` gives the raw path portion; `pathFromAppRoot()` gives the app-relative path, which is usually what application controllers need. Do not assume this automatically performs input authorization or normalizes arbitrary user-controlled paths.

### Trusted proxies

Proxy trust is **off** by default. To enable it behind a reverse proxy, configure both the switch and explicit trusted peer IPs/CIDRs:

```php
return [
	'http' => [
		'base_url' => 'https://example.com',
		'trust_proxy' => true,
		'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16'],
	],
];
```

Only when the actual connection peer is trusted does the request service use relevant proxy-provided client, scheme, host, or port information. For `X-Forwarded-For`, it walks the chain from the trusted proxy side. A non-public IP can resolve to `unknown` rather than being treated as an Internet client; CLI requests may report `CLI`.

An empty `trusted_proxies` array means trust none. Do not accept arbitrary forwarded headers from public clients, and keep all real proxy hops represented accurately.

`setTrustedProxies(array $proxies)` and `getTrustedProxies()` can modify or inspect the request-local whitelist programmatically. Prefer deployment configuration when a fixed reverse-proxy topology is known.

### POST body size guard

The request exposes three cheap metadata accessors:

```php
$bytes = $this->app->request->contentLength();
$limit = $this->app->request->postMaxSize();
$tooLarge = $this->app->request->exceedsPostMaxSize();
```

`contentLength()` is the declared length or `null`, and `postMaxSize()` resolves PHP's configured byte limit. `exceedsPostMaxSize()` avoids reading the body and is used by `Kernel::run()` before CSRF verification or routing. Oversized declared POST submissions become **HTTP 413**, not misleading empty-form or CSRF failures.

A missing or malformed `CONTENT_LENGTH` cannot provide that early determination; actual transfer limits still depend on the web server and PHP runtime.

---

## Response

`$this->app->response` emits transport output without requiring each controller to write headers and terminate execution manually.

### Status, headers, redirects

```php
$response = $this->app->response;

$response->setStatus(202);
$response->setHeader('X-Request-Mode', 'accepted');
$response->noCache();
$response->noIndex();

$response->redirect('/member/overview', 303);
```

The example's final redirect terminates execution; call the earlier header helpers only when appropriate for the same response. An app-local single-slash path is resolved relative to `CITOMNI_PUBLIC_ROOT_URL`. Fully qualified external destinations are permitted, so **never pass an unvalidated user-supplied return URL to `redirect()`**.

`memberHeaders(bool $noIndex = true, bool $allowExternal = true)` and `adminHeaders()` add bundled hardening headers for their respective page types. They are presets, not a substitute for validating what is rendered or protecting private endpoints.

### JSON and Problem Details

```php
$this->app->response->json([
	'items' => [],
]);

$this->app->response->jsonStatus([
	'id' => 42,
	'created' => true,
], 201);

$this->app->response->jsonProblem(
	'Validation failed',
	422,
	'Email is required.',
	'about:blank',
	['errors' => ['email' => ['Required field']]],
);
```

| Method | Output |
|---|---|
| `json(array $data, bool $di = false)` | JSON with current/default HTTP status; terminates |
| `jsonNoCache(array $data, bool $di = false)` | JSON with no-cache headers; terminates |
| `jsonStatus(array $data, int $statusCode = 200, bool $di = false)` | JSON with explicit status; terminates |
| `jsonStatusNoCache(array $data, int $statusCode = 200, bool $di = false)` | Explicit status and no-cache; terminates |
| `jsonProblem(string $title, int $status, string $detail = '', string $type = 'about:blank', array $extra = [], bool $di = false)` | `application/problem+json` with protected core fields; terminates |
| `text(string $body, int $statusCode = 200)` | Text response; terminates |
| `html(string $html, int $statusCode = 200)` | HTML response; terminates |
| `download(string $path, ?string $downloadName = null)` | Streams a local file; terminates |

The JSON methods use `JSON_THROW_ON_ERROR` with unescaped Unicode and slashes. The optional `$di` flag enables pretty printing for inspection. `jsonProblem()` preserves core problem fields against conflicting `$extra` members and adds the request URI as the `instance` where possible.

Do not call one of the terminating methods and then expect subsequent controller statements to execute. For downloads, always resolve and authorize the **server-owned path** before calling `download()`; a safe `Content-Disposition` filename is not file access control.

---

## TemplateEngine

The current template service is **`$this->app->tplEngine`**, not `$this->app->view`.

### Explicit template references

```php
$this->app->tplEngine->render('public/home.html@app', [
	'title' => 'Home',
	'articles' => $articles,
]);

$html = $this->app->tplEngine->renderToString('mail/notice.html@app', [
	'subject' => $subject,
]);
```

References use `relative/path@layer`. `app` resolves to the app's `templates/` directory. Provider packages can register additional layers such as `citomni/http` or `citomni/authenticate` through `view.template_layers`. Template lookup is explicit and fails when the reference, layer, or file is invalid.

`render()` outputs markup; `renderToString()` captures it and returns a string. Neither method selects an implicit controller template. Do not use Twig syntax simply because certain delimiters resemble it.

### Supported template syntax

```html
{% extends "layouts/main.html@app" %}

{% block content %}
	<h1>{{ $title }}</h1>

	{% if $articles %}
		<ul>
		{% foreach ($articles as $article) %}
			<li>{{ $article['title'] }}</li>
		{% endforeach %}
		</ul>
	{% else %}
		<p>No articles yet.</p>
	{% endif %}

	{% include "shared/footer.html@app" %}
{% endblock %}
```

And in `templates/layouts/main.html`:

```html
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body>
	{% yield content %}
</body>
</html>
```

The compiler supports explicit `{% extends %}`, `{% block %}`/`{% endblock %}`, `{% yield %}`, `{% include %}`, `{% if %}`/`{% elseif %}`/`{% else %}`/`{% endif %}`, `{% foreach %}`/`{% endforeach %}`, `{% set %}`, and `{# ... #}` comments, subject to its documented grammar. Blocks are not a general-purpose nested parser; use the package's tested idioms.

| Expression | Treatment |
|---|---|
| `{{ $title }}` | HTML-escaped output |
| `{{{ $trustedHtml }}}` | Raw output; caller is responsible for safety |
| `{% include "file.html@app" %}` | Compile and include an explicit partial |
| `{% extends "layout.html@app" %}` | Inherit an explicit layout |
| `{? ... ?}` and `{?= ... ?}` | Optional inline PHP, controlled by `view.allow_php_tags` |

**Templates are trusted executable source, not a sandbox for user-provided templates.** The default `view.allow_php_tags` is `true`. Set it to `false` if inline PHP tags are not needed, and never allow an untrusted user to choose arbitrary template sources, expressions, or raw HTML.

### Globals and template helpers

The engine supplies common scalar globals such as `app_name`, `base_url`, `public_root_url`, `language`, `charset`, `marketing_scripts`, `csrf_protection`, `captcha_protection`, `honeypot_protection`, `form_action_switching`, and the `env` map.

Available helper closures include:

| Helper | Purpose |
|---|---|
| `$txt()` | Localized text; requires infrastructure `txt` |
| `$dt()`, `$dtNow()`, `$dtMonth()`, `$dtWeekday()` | Localized date/time functions |
| `$url()` | App-local URL construction |
| `$asset()` | Public asset URL and optional version query |
| `$hasService()`, `$hasPackage()` | Registered capability checks |
| `$csrfField()` | Complete CSRF hidden input |
| `$captchaField()`, `$captchaUrl()` | Captcha hidden input and image URL |
| `$currentPath()` | Current app-relative path |
| `$icon()`, `$hasIcon()` | Layered inline SVG lookup |
| `$auth()`, `$role()` | Optional identity/role integration |

Example form template:

```html
<form method="post" action="/contact">
	{{{ $csrfField() }}}
	<label for="email">Email</label>
	<input id="email" type="email" name="email" required>
	<button type="submit">Send</button>
</form>
```

Use **triple braces only for the already-escaped hidden-field helper output** and other explicitly trusted markup. Double braces are the normal default for content such as names, titles, and form values.

Missing optional integrations are not interchangeable with missing security checks. For example, `$txt()` fails explicitly without the required infrastructure registration; CSRF and captcha helpers can return an empty string when their service is unavailable or disabled, so **controller-side verification still matters**.

### Path-scoped view variables

The actual current `view.vars` shape is an associative map **keyed by the variable name**:

```php
return [
	'view' => [
		'vars' => [
			'campaign' => [
				'type' => 'static',
				'source' => ['headline' => 'Summer offers'],
				'include' => ['/offers/*'],
			],
			'header' => [
				'type' => 'dynamic',
				'source' => [
					'service' => 'sitewide',
					'method' => 'header',
				],
				'include' => ['*'],
				'exclude' => ['/admin/*'],
			],
		],
	],
];
```

`static` injects its `source` value directly. `dynamic` resolves a callable, a constructed class, or a registered service and obtains its result when applicable. Include/exclude patterns can be app paths, wildcard/glob patterns, or supported `~...~` regular expressions.

The variable precedence is **controller render data**, then matching scoped variables, then globals/helpers. Dynamic providers are evaluated on applicable renders, not treated as persistent cached data. Invalid providers fail fast.

### Compilation and caching

`view.cache_enabled` defaults to `true`. The engine compiles trusted template sources into PHP and reuses fresh, content-addressed compiled generations beneath `var/cache/`; manifests track source and dependency freshness. Concurrent compilation is coordinated using locks and atomic publication.

Setting `view.cache_enabled` to `false` disables **warm reuse**, not compilation itself. Every render still compiles to executable PHP and needs a writable cache directory. The default production setting therefore avoids repeated compile work.

`view.trim_whitespace`, `view.remove_html_comments`, and `view.allow_php_tags` control optional markup behavior. `view.asset_version` is used by `$asset()`, and `view.marketing_scripts` is a trusted raw snippet rather than automatically sanitized text.

For `dev` and `stage` diagnostics, `?_viewvars` on a request using `render()` can expose the render variables as an escaped HTML comment. The diagnostic is suppressed in `prod`. Limit access to such pages because rendered variables can include application data.

---

## CSRF protection

The public service is `$this->app->csrf`. The service formerly exposed as `security` is **not registered** in the current vendor map.

### Protect state-changing actions

For a normal form, include the hidden token in the HTML and verify the POST in its controller action:

```php
public function submit(): void {
	if (!$this->app->csrf->verify()) {
		$this->app->flash->error('The form expired. Please try again.');
		$this->app->response->redirect('/contact', 303);
	}

	// Validate input and delegate the business operation here.
	$this->app->flash->success('The request was accepted.');
	$this->app->response->redirect('/contact', 303);
}
```

This assumes the route explicitly allows `POST`. CSRF verification is **not injected automatically by the router**, even if `security.csrf.enabled` is `true`.

For JSON/API controllers, the throwing variant allows a typed mapping to HTTP 403:

```php
use CitOmni\Http\Exception\CsrfVerificationException;

try {
	$this->app->csrf->requireValid();
} catch (CsrfVerificationException $e) {
	$this->app->response->jsonProblem('Forbidden', 403, 'CSRF verification failed.');
}
```

The exception contains a `CsrfFailureReason`. **Unhandled exceptions are not inherently converted to 403 by the HTTP error handler**; handle the exception explicitly when your endpoint contract requires that status.

### Header-based requests

JavaScript can submit the token via the configured `X-CSRF-Token` header:

```html
<meta name="csrf-token" content="{{ $csrfToken }}">
```

In this example `$csrfToken` must be supplied by the controller, for example with `$this->app->csrf->token()`.

```javascript
const token = document.querySelector('meta[name="csrf-token"]').content;

const response = await fetch('/api/profile', {
	method: 'PATCH',
	credentials: 'same-origin',
	headers: {
		'Content-Type': 'application/json',
		'X-CSRF-Token': token,
	},
	body: JSON.stringify({ displayName: 'Example' }),
});
```

The header takes precedence over form submission. For `PUT`, `PATCH`, and `DELETE`, send the token as a header; the form-field fallback is for POST. A valid token does not replace authentication or authorization.

### Verification layers and lifecycle

The enabled verification layers are evaluated together:

1. Fetch Metadata (`Sec-Fetch-Site`) to reject disallowed cross-site contexts.
2. Origin checking, with controlled Referer fallback for HTTPS and configured trusted origins.
3. Session-bound CSRF token checking for protected HTTP methods.

The default protected methods are `POST`, `PUT`, `PATCH`, and `DELETE`; safe methods pass without CSRF token verification. The current baseline uses 32 random token bytes and masks exposed tokens so successive `token()` calls need not return the same string even though they verify against the same session secret.

```php
$token = $this->app->csrf->token();
$field = $this->app->csrf->htmlField();
$allowed = $this->app->csrf->verify();
$this->app->csrf->rotate();
$this->app->csrf->clear();
```

`token()` and `htmlField()` create a session secret lazily and can start a session. **Verification never creates a new session solely to check a missing token**, avoiding a write on anonymous bad requests. `rotate()` invalidates previously issued tokens; `clear()` removes the token from any existing session without starting a new one.

The `security.csrf.rotate_on_login`/`rotate_on_logout` switches express integration policy; actual privilege-transition handling belongs to the authentication flow, not an automatic CSRF router hook.

### CSRF configuration

Configure under `security.csrf`, not the former top-level `security.csrf_protection` or `csrf_field_name` keys:

```php
return [
	'security' => [
		'csrf' => [
			'enabled' => true,
			'field_name' => '_csrf',
			'header_name' => 'X-CSRF-Token',
			'protect_methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],
			'mask_tokens' => true,
			'origin_check' => true,
			'fetch_metadata' => [
				'enabled' => true,
				'allow_same_site' => true,
			],
		],
	],
];
```

Additional keys include `session_key`, `token_bytes`, `referer_fallback_on_https`, `allow_missing_origin_on_http`, `trusted_origins`, `rotate_on_login`, `rotate_on_logout`, `log_failures`, and `log_channel`.

Treat `trusted_origins` as a tightly controlled list. Don't disable protection because an application is behind a proxy; fix the canonical URL and proxy trust configuration first.

---

## Sessions

`$this->app->session` wraps native PHP sessions with explicit read/write and rotation semantics.

```php
$session = $this->app->session;

$selected = $session->get('selected_workspace');
if (!$session->has('selected_workspace')) {
	$session->set('selected_workspace', 42);
}

$sessionId = $session->id();
$isActive = $session->isActive();

$session->remove('selected_workspace');
```

| Method | Semantics |
|---|---|
| `start()` | Explicitly start a session |
| `isActive()` | Whether a native PHP session is active |
| `id()` | Active ID or `null`, without starting a session |
| `get(string $key)` | Resume an existing session, but never create a new one |
| `has(string $key)` | Check an existing session without creating one |
| `set(string $key, mixed $value)` | Start when necessary and store the value |
| `remove(string $key)` | Remove from an existing session, without creating one |
| `regenerate(bool $deleteOld = true)` | Rotate the session ID; throws on failure |
| `destroy(bool $forgetCookie = true)` | Destroy current session and optionally expire cookie; throws on failure |

The session is **lazy**, but not magically short-lived. Its storage retention is configured using `session.gc_maxlifetime` (default 1440 seconds), which is **not** an authenticated user's idle timeout. Login expiration and other identity policies belong to `citomni/authenticate`.

Rotate the session ID after login or privilege escalation:

```php
$this->app->session->regenerate(true);
$this->app->csrf->rotate();
```

Use such calls at a real privilege boundary in the application/authentication flow. Rotation and deletion fail fast, rather than silently preserving an insecure old ID after an unsuccessful security-critical operation. Deleting old sessions immediately can cause concurrent requests using the old ID to see an empty session; the auth workflow must account for this.

### Session cookie policy

The session cookie gets its default scope and attributes from the `cookie` service, with `session.cookie_*` overrides. Its lifetime is always a **browser-session cookie** and `HttpOnly` stays enabled even if the shared application-cookie default is overridden.

```php
return [
	'session' => [
		'name' => 'CITSESSID',
		'save_path' => CITOMNI_APP_PATH . '/var/state/php_sessions',
		'gc_maxlifetime' => 1440,
		'gc_probability' => 1,
		'gc_divisor' => 1000,
		'use_strict_mode' => true,
		'use_only_cookies' => true,
		'lazy_write' => true,
		'cookie_secure' => null,
		'cookie_httponly' => true,
		'cookie_samesite' => null,
		'cookie_path' => null,
		'cookie_domain' => null,
	],
];
```

A `null` cookie override inherits the shared cookie policy. `cookie_domain => ''` explicitly requests host-only behavior. The removed `rotate_interval` and `fingerprint` policies are rejected when enabled; use explicit rotation at known authentication transitions instead.

For a custom `save_path`, ensure the directory exists and is private and writable by the PHP process. The supplied `gc_probability` avoids relying on Linux distribution cleanup jobs that do not know about the app's private session directory.

---

## Cookies

`$this->app->cookie` is the shared resolver for application cookie attributes and the session-cookie baseline.

```php
$cookie = $this->app->cookie;

$cookie->set('display_mode', 'compact', [
	'ttl' => 30 * 86400,
	'samesite' => 'Lax',
]);

$mode = $cookie->get('display_mode', 'normal');
$exists = $cookie->has('display_mode');
$cookie->delete('display_mode');
```

`set()` and `delete()` return `bool` (the result of PHP accepting the cookie header), not a guarantee that a browser saved or deleted it. For deletion to work reliably, the domain and path should match the original cookie's scope.

`get()` and `has()` treat an array-shaped input cookie as absent rather than coercing it into a string. An accepted write is reflected in the request-local `$_COOKIE` view when the cookie scope matches the current request.

### Defaults and attributes

```php
return [
	'cookie' => [
		'secure' => null,
		'httponly' => true,
		'samesite' => 'Lax',
		'path' => '/',
		'domain' => null,
	],
];
```

`secure => null` infers whether the application URL/request uses HTTPS, with proxy awareness. `domain => null` means **host-only**, not automatic sharing across subdomains. An explicit domain should be set only when cross-subdomain cookie access is intended.

`attributes(array $overrides = [])` returns normalized effective attributes; `defaults()` returns the baseline attributes. Supported per-cookie override keys include `ttl`, `expires`, `path`, `domain`, `secure`, `httponly`, and `samesite`.

`SameSite` accepts `Lax`, `Strict`, or `None`; `None` requires `Secure=true` and otherwise throws. Invalid cookie names or attribute values fail fast. If output has already started and headers cannot be changed, the `set()`/`delete()` result can be false.

Use cookies for appropriate client-side preferences, not raw secrets or unprotected authentication decisions. A cookie's content is client-controlled input on subsequent requests.

---

## Flash messages and form state

`$this->app->flash` manages redirect-spanning feedback, retained form input, and field validation errors using the session service.

```php
$this->app->flash->success('Settings saved.');
$this->app->flash->info('Your profile is incomplete.');
$this->app->flash->warning('Review the new options.');
$this->app->flash->error('Please correct the form.');

$this->app->flash->old([
	'email' => (string)($this->app->request->post('email') ?? ''),
]);

$this->app->flash->fieldErrors([
	'email' => ['Enter a valid email address.'],
]);

$this->app->response->redirect('/profile', 303);
```

On the redirected GET request:

```php
$flash = $this->app->flash->pullAll();
$messages = $flash['msg'];
$oldInput = $flash['old'];
$fieldErrors = $flash['err'];
```

The service also offers `set(key, message)`, `add(key, message)`, `take(key)` (consume one message bucket), `peek(key)`, `peekAll()`, `oldValue(key, default)`, `hasOld(key)`, `keep()` (preserve across the next `pullAll()`), `clear()`, `forgetMsg(key)`, and `forgetOld(keys)`.

Flash writes start a session; reading absent flash information does not create one. The `pullAll()` result groups messages, old input, and field errors separately. `keep()` is a single-shot retention instruction, not a permanent no-consume toggle.

Never copy complete `$_POST` blindly into `old()`. Remove passwords, CSRF tokens, MFA codes, personal secrets, and unusually large data before persisting form input in a session.

---

## Captcha

`$this->app->captcha` owns the **challenge lifecycle**: issue, session persistence, expiration, image lookup, and one-time verification. The actual code generation and raster rendering come from the optional `citomni/image` `captchaImage` service.

The `security.captcha_protection` flag defaults to `true`, but a flag is not an automatic captcha on every form. Enable the route, render fields, and call verification for the forms that require it.

### Register the image route

The image route is **opt-in** in `config/citomni_http_routes.php`:

```php
return [
	'/captcha.png' => [
		'controller' => \CitOmni\Http\Controller\CaptchaController::class,
		'action' => 'image',
		'methods' => ['GET'],
	],
];
```

### Render and verify a challenge

```html
<form method="post" action="/contact">
	{{{ $csrfField() }}}
	{{{ $captchaField() }}}
	<img src="{{ $captchaUrl() }}" alt="Captcha challenge">
	<label for="captcha">Enter the characters</label>
	<input id="captcha" name="captcha" required>
	<button type="submit">Send</button>
</form>
```

Then check both defenses in the POST action:

```php
public function submit(): void {
	if (!$this->app->csrf->verify()) {
		$this->app->response->jsonProblem('Forbidden', 403, 'CSRF verification failed.');
	}

	if (!$this->app->captcha->verify()) {
		$this->app->response->jsonProblem('Forbidden', 403, 'Captcha verification failed.');
	}

	// Continue with input validation and the application operation.
	$this->app->response->json(['accepted' => true]);
}
```

This JSON response is only an example; an HTML application may instead put a flash message in the session and redirect. Do not use this image-only captcha as the only anti-abuse control for an exposed, high-value endpoint; use appropriate rate limiting and accessibility alternatives.

### Service contract

```php
$id = $this->app->captcha->issue();
$current = $this->app->captcha->current();
$field = $this->app->captcha->htmlField();
$imageUrl = $this->app->captcha->imagePath();

$image = $this->app->captcha->image($this->app->request->get('id'));
$valid = $this->app->captcha->verify();
```

`image()` returns rendered PNG metadata and bytes, or `null` for an unknown, expired, or malformed challenge. It does not create a new session for a browser without a session cookie. Repeated image loads reuse the challenge's stored render seed rather than producing different pictures for one code.

`verifyAnswer(string $id, string $answer)` allows explicit verification independently of POST fields. A challenge is consumed after **one** submitted answer, whether correct or incorrect. The default lifetime is 1200 seconds and at most five challenges can be pending per session, permitting multiple tabs without an unlimited state build-up.

Defaults are configured under `security.captcha`:

```php
return [
	'security' => [
		'captcha' => [
			'session_key' => '_captcha',
			'ttl' => 1200,
			'max_pending' => 5,
			'id_field' => 'captcha_id',
			'answer_field' => 'captcha',
			'image_path' => '/captcha.png',
		],
	],
];
```

The `captcha` array belongs **inside** `security`, not at the configuration root. Captcha image rendering will fail explicitly when the required `captchaImage` service is absent.

---

## Webhook authentication

`$this->app->webhooksAuth` verifies **inbound** signed webhook requests. It does not send webhooks, create keys in application code, or silently authorize incoming traffic.

The feature is disabled by default and requires explicit configuration of a readable secret file and writable nonce directory when enabled.

### Configure the verifier

In an appropriate application configuration overlay:

```php
return [
	'webhooks' => [
		'enabled' => true,
		'secret_file' => CITOMNI_APP_PATH . '/var/secrets/webhooks.secret.php',
		'allowed_ips' => ['203.0.113.10'],
		'ttl_seconds' => 300,
		'ttl_clock_skew_tolerance' => 60,
		'bind_context' => true,
	],
	'nonce' => [
		'dir' => CITOMNI_APP_PATH . '/var/nonces',
	],
];
```

Keep the real secret **outside source control**. The `secret_file` is a side-effect-free PHP file returning the expected array:

```php
<?php
declare(strict_types=1);

return [
	'secret' => 'REPLACE_WITH_A_LONG_RANDOM_HEX_STRING',
	'algo' => 'sha256',
];
```

The string shown is only a placeholder and **will not** work as a valid configured secret. Use a cryptographically random hexadecimal key appropriate for the selected HMAC algorithm, such as 64 random hex characters for SHA-256. The verifier **lowercases the hexadecimal text** and uses that text as the HMAC key; clients should sign with the same lowercase hex string. Do **not** use `hex2bin()` on the key when implementing this protocol: That would create a different signature.

`webhooks.algo` defaults to `null`, allowing the secret file to choose `sha256` or `sha512`, then falling back to `sha256`. A configured algorithm overrides the secret-file selection. A non-empty `webhooks.allowed_ips` list restricts sources by exact IP or CIDR; an empty list disables only the IP check, **not** the signature requirement.

### Guard an endpoint

```php
public function receive(): void {
	$rawBody = $this->app->webhooksAuth->requireOrAbort();

	$payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($payload)) {
		$this->app->response->jsonProblem('Invalid payload', 400);
	}

	// Validate the payload and delegate processing to an Operation.
	$this->app->response->json(['ok' => true]);
}
```

`requireOrAbort()` responds with HTTP **404 by default** on authentication failure to avoid revealing an endpoint's presence. Pass another status only if the endpoint requires it. The method returns the exact authenticated raw body on success; avoid reconstructing signed data from decoded JSON.

Other public methods:

| Method | Contract |
|---|---|
| `verify(): bool` | Return success/failure without throwing verification failure |
| `requireValid(): string` | Return verified raw body or throw `WebhooksAuthVerificationException` |
| `requireOrAbort(int $failureStatus = 404): string` | Return verified raw body or render and terminate |
| `isEnabled(): bool` | Whether verification is enabled |
| `getLastFailureReason()` | Typed reason enum, or `null` after success |
| `getLastError(): ?string` | Stable failure-reason string, or `null` |
| `getRawBody(): string` | Lazily cached incoming body bytes |

There is **no** `setOptions()` or `assertAuthorized()` on the current `WebhooksAuth` service. Configure the verifier through its App configuration and invoke one of the actual verification methods above.

### Request signing protocol

The default required HTTP headers are:

```text
X-Citomni-Timestamp: <CURRENT_UNIX_TIMESTAMP>
X-Citomni-Nonce: 7bd34e66b99e45ca
X-Citomni-Signature: <lowercase hexadecimal HMAC>
```

Use the **actual current Unix timestamp**, not the placeholder shown above. The nonce must be unique within the accepted lifetime and comply with the nonce service's allowed character set.

With default `webhooks.bind_context => true`, calculate the signature over these six lines, joined by a literal newline (`"\n"`) without a trailing newline:

```text
<timestamp>
<nonce>
<UPPERCASE_HTTP_METHOD>
<REQUEST_PATH_WITH_LEADING_SLASH>
<RAW_QUERY_STRING_WITHOUT_QUESTION_MARK>
<LOWERCASE_HEX_SHA256_OF_EXACT_RAW_BODY>
```

Example PHP signing logic for a separate trusted sender:

```php
$timestamp = (string)time();
$nonce = bin2hex(random_bytes(16));
$method = 'POST';
$path = '/_system/maintenance/enable';
$query = '';
$body = '{}';
$secretHexText = strtolower($configuredSharedSecret);

$base = implode("\n", [
	$timestamp,
	$nonce,
	$method,
	$path,
	$query,
	hash('sha256', $body),
]);

$signature = hash_hmac('sha256', $base, $secretHexText);
```

The client must sign **exactly the body bytes it sends**, together with the actual method, request path, and query string as seen by the receiver. Reformatting JSON after signing invalidates the signature. Timestamp/nonce/body and URL canonicalization must agree at both ends.

If `webhooks.bind_context => false`, the signed base is instead:

```php
$base = $timestamp . '.' . $nonce . '.' . $body;
```

The verifier checks timestamp freshness with configured skew, validates the signature using a constant-time comparison, then claims the nonce. It rejects replays and fails closed when the nonce ledger cannot prove single use.

With defaults, a timestamp can be at most 300 seconds old plus 60 seconds tolerance, or 60 seconds into the future. The nonce claim is retained for the full acceptance window (`ttl_seconds + 2 * ttl_clock_skew_tolerance`).

### Nonce replay protection

`$this->app->nonce` can also be used directly for other one-time workflows:

```php
$firstUse = $this->app->nonce->checkAndStore('password-link', $token, 900);
$removed = $this->app->nonce->purgeExpired('password-link', 900, 500);
```

Nonce values are restricted to `A-Z`, `a-z`, `0-9`, `_`, `.`, `:`, and `-`, with a default maximum of 128 bytes. Namespaces are separate, with a more restricted character set. Storage uses a namespaced directory and hashed nonce filenames, with atomic first-writer wins behavior.

A `false` claim result means replay, invalid input, or a storage failure; do **not** interpret it as authorization. Periodic bounded cleanup is opportunistic on normal writes, with explicit `purgeExpired()` available for maintenance jobs. Protect `var/nonces` against web access and ensure all workers which verify the same class of webhooks share the relevant nonce ledger.

---
## Maintenance mode

`$this->app->maintenance` controls a file-backed maintenance flag. `Kernel::run()` calls `guard()` **before routing**, so ordinary requests receive a controlled **HTTP 503** response when maintenance is active, unless the client IP is allowlisted.

The guard sends a `Retry-After` header, no-cache/no-index directives, and a minimal maintenance page. It renders the configured plain-PHP maintenance template if readable, otherwise a built-in fallback.

### Reading and changing state

```php
$maintenance = $this->app->maintenance;

$enabled = $maintenance->isEnabled();
$state = $maintenance->snapshot();
$allowedIps = $maintenance->getAllowedIps();
$retrySeconds = $maintenance->getRetryAfter();

$maintenance->setRetryAfter(600)->enable(['203.0.113.10']);
$maintenance->disable();
```

`snapshot()` returns a normalized array containing `enabled`, `allowed_ips`, `retry_after`, and `source` (`flag` or `cfg`). The state is memoized within a request and invalidated after a successful write. `setRetryAfter()` applies to the next flag write, not the already-loaded flag, and is consumed after that write.

`enable()` normalizes the allowlist, appends the current client identifier when valid, and persists the flag atomically. `disable()` persists a disabled flag. Writing can fail with an exception if directories or backups cannot be handled; this is a real operational failure and must not be silently ignored.

The configuration baseline is:

```php
return [
	'maintenance' => [
		'flag' => [
			'path' => CITOMNI_APP_PATH . '/var/flags/maintenance.php',
			'template' => CITOMNI_APP_PATH . '/vendor/citomni/http/templates/public/maintenance.php',
			'allowed_ips' => [],
			'default_retry_after' => 300,
		],
		'backup' => [
			'enabled' => true,
			'keep' => 3,
			'dir' => CITOMNI_APP_PATH . '/var/backups/flags/',
		],
		'log' => [
			'filename' => 'maintenance.json',
		],
	],
];
```

**Important exception:** The maintenance guard bypasses paths starting with `/_system/` so protected remote-recovery operations remain reachable. That prefix also includes public diagnostic routes. The bypass is **not HMAC authentication**: each system endpoint must enforce its own security policy.

---

## Error handling and diagnostics

`$this->app->errorHandler` is installed during HTTP boot and owns request-level error handling, exception logging, and fallback error responses.

### Emitting intentional HTTP errors

Controllers can request an error response directly:

```php
if ($article === null) {
	$this->app->errorHandler->httpError(404, [
		'reason' => 'not_found',
	]);
}
```

The error handler renders the terminal response and terminates. Router 404/405 errors and internal HTTP errors flow through the same response machinery. Exceptions and shutdown fatals are also handled independently of the opt-in setting for rendering **non-fatal** PHP warnings/notices.

For an API with a specific JSON error schema, using `response->jsonProblem()` in a controller is often preferable to sending the handler's HTML error page.

### Logging and render policy

The default log directory is `var/logs/`, with JSONL records, size-aware rotation, and retention. HTTP errors are classified into files such as:

```text
http_err_exception.jsonl
http_router_404.jsonl
http_router_405.jsonl
http_router_5xx.jsonl
http_request.jsonl
```

A request identifier is available through the `X-Request-Id` response header and associated diagnostic records. The handler limits exception traces and sanitizes sensitive context so ordinary error messages do not expose full secret-bearing request data.

There are two independent masks:

| Key | Baseline | Meaning |
|---|---|---|
| `error_handler.render.trigger` | `0` | Which non-fatal PHP errors may cause a rendered error response |
| `error_handler.log.trigger` | `E_ALL` | Which active non-fatal PHP errors may be logged |

Uncaught exceptions, shutdown fatals, and explicit terminal HTTP errors still get a response even with `render.trigger = 0`. A non-fatal PHP error is also subject to the process's active `error_reporting()` setting.

`error_handler.render.detail.level` defaults to `0`. Level `1` exposes developer detail **only in `dev`**; production is still restricted. The trace limits under `error_handler.render.detail.trace` bound logged and rendered trace size.

The normal error templates are **plain PHP templates** receiving a `$data` array (including status, error ID, request ID, and optional details). They are not `TemplateEngine` layer references. If the configured template cannot be loaded, the handler attempts the failsafe template and then a minimal inline page.

Example safe production defaults:

```php
return [
	'error_handler' => [
		'render' => [
			'trigger' => 0,
			'detail' => ['level' => 0],
		],
		'log' => [
			'trigger' => E_ALL,
			'path' => CITOMNI_APP_PATH . '/var/logs',
			'max_bytes' => 2_000_000,
			'max_files' => 10,
		],
	],
];
```

Avoid putting sensitive identifiers, webhook secrets, raw session content, or unredacted personal information into explicit error context. Log sanitization is a defense in depth, not permission to hand the logger arbitrary secrets.

---

## Built-in system routes

`\CitOmni\Http\Controller\SystemController` supplies runtime diagnostics and protected operations under `/_system/`.

### Public diagnostics

| Method and path | Behavior |
|---|---|
| `GET /_system/ping` | Minimal plain-text liveness signal |
| `GET /_system/health` | Lightweight health response |
| `GET /_system/version` | Runtime/package version information |
| `GET /_system/time` | Server/runtime time information |
| `GET /_system/clientip` | Request-resolved client IP as JSON |
| `GET /_system/request-echo` | Controlled request diagnostics |

These are part of the vendor baseline and are not automatically secured by HMAC. Expose only what is appropriate for the deployment; restrict or override diagnostic routes if their information should not be public.

### HMAC-protected operations

| Method and path | Behavior |
|---|---|
| `POST /_system/reset-cache` | Reset application compiled caches and relevant runtime caches |
| `POST /_system/warmup-cache` | Warm supported compiled application caches |
| `GET /_system/upload-limits.json` | Report current upload constraints |
| `GET /_system/maintenance` | Inspect maintenance state |
| `POST /_system/maintenance/enable` | Enable maintenance mode |
| `POST /_system/maintenance/disable` | Disable maintenance mode |

These actions call `webhooksAuth->requireOrAbort()` and return an inconspicuous **404** on invalid/missing authentication. They require a configured secret and valid signed request when webhooks are enabled, including for GET operations. A request method alone does not grant access.

`POST /_system/_debug/webhook` is a diagnostic endpoint that runs verification and reports controlled results. Unlike the protected operations above, it is not an invisible 404-only administrative action; avoid exposing it unnecessarily. A successful verification can consume a nonce, just like a normal webhook request.

### Development-only application information

`GET /_system/appinfo.html` and `GET /_system/appinfo.json` are available in `dev` and return 404 outside that environment. They expose App/runtime introspection from the kernel's `AppInfo` facility.

In development, `?raw=1` or `?unredacted=1` requests **unredacted** output. Keep development instances behind appropriate access controls and never expose such diagnostics to arbitrary Internet users.

Because system paths are exempt from the maintenance guard, access rules on individual routes are particularly important during maintenance windows.

---

## Supporting services

The HTTP package includes focused supporting capabilities. They remain transport-friendly utilities; they do not move SQL into HTTP services or decide application workflows.

### Localized date and time

`$this->app->datetime` uses ICU (`ext-intl`) patterns and the app's configured locale/timezone:

```php
$dt = $this->app->datetime;

$created = $dt->format('2026-10-10 11:30:00', 'dd-MM-yyyy HH:mm');
$today = $dt->now('EEEE d. MMMM yyyy');
$month = $dt->month(10, 'full');
$day = $dt->weekday(1, 'short');
```

`format()` accepts a date/time string, Unix timestamp, `DateTimeInterface`, or `null` for now. An optional timezone or locale overrides the app's default for one call. `month()` takes 1-12; `weekday()` takes ISO weekday 1-7. Month and weekday output forms include `full`, `short`, and `narrow`.

The service caches relevant Intl formatters within the service instance. It returns an empty string for some non-formatable inputs rather than inventing a date. It does not persist values or dictate database timezone storage.

### Layered SVG icons

`$this->app->icon` resolves icons from the configured package/application icon files:

```php
$markup = $this->app->icon->get('home');
$exists = $this->app->icon->has('home');
$available = $this->app->icon->ids();

$custom = $this->app->icon->get('dashboard', 'icons', 'app');
```

Methods accept `($id, $file = 'icons', $layer = 'citomni/http')`, with `ids()` omitting the ID argument. Definitions are loaded lazily from the specified layer's `assets/icons/` PHP files. Invalid layers/files or missing icon IDs have explicit package-specific failures.

SVG returned by `get()` is trusted markup. In templates, render it with the `$icon()` helper using raw output only when its definition is trusted:

```html
<span aria-hidden="true">{{{ $icon('home') }}}</span>
```

Do not accept an arbitrary untrusted SVG path, icon file, or markup through this interface.

### Slugs

`$this->app->slugger` converts display text into a configured URL-safe slug and validates candidates:

```php
$slugger = $this->app->slugger;

$slug = $slugger->slugify('A New Article');
$slugger->validate($slug);
$normalized = $slugger->normalize('A---New-Article');
$shortened = $slugger->enforceLength($slug, 60);
$reserved = $slugger->isReserved($slug);
```

For uniqueness, supply the persistence lookup as a callback:

```php
$unique = $this->app->slugger->ensureUnique(
	fn(string $candidate): bool => $repository->slugExists($candidate),
	$this->app->slugger->slugify($title),
);
```

`slugExists()` is **illustrative application Repository code**, not a method provided by `citomni/http`. `ensureUnique()` tests the base and numeric suffixes deterministically; it does not lock a database row or guarantee race-free uniqueness. Enforce a unique index at the persistence boundary.

Optional defaults live under `http.slug`, including `separator`, `maxLength`, `lowercase`, `transliterate`, `reserved`, and `allowedPattern`. Constructor options in a service-map definition can override the configured policy.

### Tags

`$this->app->tags` parses and plans tag changes without doing SQL:

```php
$tags = $this->app->tags;

$labels = $tags->deduplicate($tags->parse('Finance, Property; PHP'));
$diff = $tags->diff(['Property'], $labels);
$slugs = $tags->mapLabelsToSlugs($labels);
$pivot = $tags->planPivotChanges([3, 7], [7, 9]);
```

`diff()` returns `['add' => [...], 'remove' => [...]]`; `planPivotChanges()` returns `['attach' => [...], 'detach' => [...]]`. `normalizeLabel()`, `validateLabel()`, and `toSlug()` are also public.

`resolveTagIds($labels, $getOrCreate)` invokes a caller-supplied callback receiving `(string $label, string $slug): int` for persistence, keeping SQL outside the HTTP service. Use your Repository or Operation to own transactions, tag uniqueness constraints, and pivot writes.

Optional defaults under `http.tags` include `delimiters`, `maxLabelLength`, `caseMode`, `collapseWhitespace`, `disallowedPattern`, and `maxTagsPerEntity`. The tag service depends on `slugger` for tag slugs. It uses Unicode-aware `mb_*` functions for several operations; verify the application's relevant PHP extensions if those features are needed.

---

## Uploads

The vendor service map still contains the existing `CitOmni\Http\Service\Upload` service as `$this->app->upload`. It handles specific HTTP upload structures and local file placement. Do not confuse it with `Request::hasFile()` (not active) or with the separately developed `citomni/upload` package.

Current public methods:

```php
$this->app->upload->saveColumnUpload(
	$fieldName,
	$uploadCfg,
	$payload,
	$currentRow,
	$recordId,
	$currentPath,
	$columnName,
);

$this->app->upload->addAttachedImages($attachedCfg, $payload, $foreignKeyId, $currentCount);
$this->app->upload->addAttachedFiles($attachedCfg, $payload, $foreignKeyId, $currentCount);

$this->app->upload->sanitizeForFilename($originalName);
$this->app->upload->buildBasenameFromPattern($pattern, $payload, $originalName, true, 80);
```

`saveColumnUpload()` returns a structured result with `status`, `path`, `thumbs`, `error`, and `deleted` keys. It requires a meaningful `$columnName` for deterministic cleanup. The attachment methods return `status`, per-file results under `files`, and `error`.

The service reads native `$_FILES`, applies configurable upload constraints, and writes within the public uploads tree. Image workflows can generate resized/encoded variants with GD and perform cleanup on failed writes. The application still owns validation of the related entity, database persistence, access policy, and a transaction/compensation plan that keeps file state and stored references consistent.

Configuration is supplied **per call** through upload and attachment arrays; it is not a generic global upload middleware. Relevant keys depend on the method and include `dir`, `inputName`, `accept`, `maxBytes`, `rename`, `encoding`, `thumbnails`, optional size/fit settings, and `maxCount`.

Upload processing is especially sensitive to file-size limits, MIME/content checks, destination visibility, and stale-file deletion. For new, transport-independent ingestion and storage workflows, evaluate the dedicated [`citomni/upload`](https://github.com/citomni/upload) package according to its own current API rather than assuming the two implementations are interchangeable.

---

## Configuration reference

The baseline comes from `\CitOmni\Http\Boot\Registry::CFG_HTTP`. The table is an orientation to the real tree, not a replacement for the package's shipped baseline.

| Root | Main settings | Notes |
|---|---|---|
| `http` | `base_url`, `trust_proxy`, `trusted_proxies`, `router_case_insensitive` | Canonical URL, proxies, routing |
| `error_handler` | `render`, `log`, `templates`, `status_defaults` | Response detail, JSONL logs, fallback pages |
| `session` | `name`, `save_path`, GC keys, native session flags, `cookie_*` | Session storage and cookie overrides |
| `cookie` | `secure`, `httponly`, `samesite`, `path`, `domain` | Shared cookie policy |
| `security.csrf` | `enabled`, `field_name`, `header_name`, `session_key`, token and checking options | Explicit controller-side CSRF |
| `security.captcha` | `session_key`, `ttl`, `max_pending`, `id_field`, `answer_field`, `image_path` | Challenge lifecycle |
| `security` | `captcha_protection`, `honeypot_protection`, `form_action_switching` | Feature flags; not automatic router middleware |
| `view` | `template_layers`, `cache_enabled`, `trim_whitespace`, `remove_html_comments`, `allow_php_tags`, `asset_version`, `marketing_scripts`, `vars` | Template compilation and render variables |
| `maintenance` | `flag`, `backup`, `log` | Atomic maintenance flag and recovery |
| `webhooks` | `enabled`, `secret_file`, `ttl_seconds`, `ttl_clock_skew_tolerance`, `allowed_ips`, `algo`, `bind_context`, header and log keys | Signed inbound webhooks |
| `nonce` | `dir`, `max_len`, `purge_probability`, `purge_limit`, `dir_mode`, `file_mode` | Single-use ledger |
| `http.slug` | Optional policy keys | Slugger service |
| `http.tags` | Optional policy keys | Tags service |

Config keys are read-only when accessed through `$this->app->cfg`. Missing nested keys can be defaulted deliberately with `??`; a direct read of an unknown key may fail. The app's final configuration is the merged result of the package, providers, and application overlays, not the vendor array alone.

### Environment configuration

Use a common file for portable settings and per-environment overlays for different deployment requirements:

```text
config/citomni_http_cfg.php
config/citomni_http_cfg.dev.php
config/citomni_http_cfg.stage.php
config/citomni_http_cfg.prod.php
```

For production, especially verify the absolute base URL, secure cookie scope, proxy allowlist, non-verbose error pages, cache writability, and protected system operations. Keep actual secrets in appropriate private files or secret facilities, never in tracked config arrays.

### Removed interfaces and configuration names

The current package does **not** provide a registered `$this->app->view` or `$this->app->security` service. Use `tplEngine`, `csrf`, and `captcha` as appropriate.

These older configuration names are not the current contracts:

| Outdated reference | Current home |
|---|---|
| `security.csrf_protection` | `security.csrf.enabled` |
| `security.csrf_field_name` or root `csrf_field_name` | `security.csrf.field_name` |
| `view.view_vars` | `view.vars`, keyed by variable name |
| `webhooks.nonce_dir` | `nonce.dir` |
| `WebhooksAuth::setOptions()` and `assertAuthorized()` | `verify()`, `requireValid()`, `requireOrAbort()` |

The distinction matters when debugging a configuration that appears to be loaded but has no effect. Do not add a compatibility shim merely to preserve outdated documentation examples.

---

## Compiled caches and deployment

CitOmni can persist compiled framework maps beneath the application's `var/cache/` directory. In HTTP mode the three important compiled maps are:

```text
var/cache/cfg.http.php
var/cache/services.http.php
var/cache/routes.http.php
```

**`routes.http.php` is part of the HTTP compiled-map set.** It must be considered alongside configuration and service-map caches when preparing a deployment, resetting caches, or diagnosing a stale route.

These are separate from `TemplateEngine`'s compiled template PHP generations and dependency manifests, which also live under the application's cache tree. Do not manually treat individual compiled files as authoritative configuration; the source configuration, providers, and routes remain authoritative inputs.

The HMAC-protected `/_system/reset-cache` and `/_system/warmup-cache` operations can be used by trusted deployment tooling, subject to their actual action contracts. Avoid public or unsigned cache reset hooks.

When a new route or provider registration does not appear, inspect source maps **and** the compiled maps. When a template unexpectedly remains stale, inspect the template cache's writable directory and freshness tracking separately. Neither symptom is fixed by introducing a legacy `view` service.

---

## Performance characteristics

The HTTP layer deliberately avoids expensive work unless a request actually needs it:

- The HTTP service map is lazily resolved and memoized per App.
- Route matching uses explicit maps instead of controller discovery and scan-based routing.
- Request input is a lightweight facade around the PHP SAPI; JSON decoding occurs only on demand.
- Proxy header interpretation is only enabled for configured trusted peers.
- Session reads avoid creating anonymous sessions, while writes start them when needed.
- CSRF verification avoids generating session state on a missing/bad token.
- The nonce ledger uses atomic filesystem claims and bounded opportunistic cleanup.
- Maintenance state is cached within one request and its flag is updated atomically.
- Template compilation reuses fresh, versioned artifacts instead of recompiling every normal request.
- Error logs rotate by size, with explicit retention limits and bounded stack-trace details.
- Existing file responses are streamed rather than materialized as one giant response string.

A hot route still has real costs. Template compilation, image captcha drawing, session persistence, and file/nonce I/O have different cost profiles; profile the actual application workload rather than assuming all requests use every service.

---

## Security and operational boundaries

CitOmni supplies primitives, not an implicit blanket guarantee of authorization. The most important boundaries for an HTTP deployment are:

1. **Controller-owned checks.** A matching POST route is not automatically authorized and does not automatically verify CSRF. Invoke the correct checks in the actual action.
2. **Session transition policy.** Rotate the session ID and CSRF token on relevant privilege changes. Never ignore failed rotation.
3. **Proxy configuration.** Trust forwarded client information only from explicitly allowlisted reverse proxies.
4. **Host and scheme.** Use a canonical HTTPS public-root URL in non-development deployments, especially for cookies and absolute links.
5. **Webhook integrity.** Keep shared HMAC secrets private, sign exact request bytes, and provide a shared writable nonce ledger.
6. **Template safety.** Render untrusted values through escaped expressions. Do not execute templates supplied by end users.
7. **Diagnostic exposure.** Restrict dev instances, unredacted AppInfo, request echo, performance diagnostics, and webhook debugging.
8. **Filesystem permissions.** Make `var/secrets` private, keep sessions/nonces/cache writable only by appropriate processes, and prevent any public web traversal into `var/`.
9. **Uploads.** Validate file content and paths, enforce size limits, and coordinate file writes/deletion with persistence.
10. **Error presentation.** Keep production details off and avoid intentionally injecting secrets into logs or exceptions.

None of these replace domain-level authorization, rate limiting, or database constraints where the application requires them.

---

## Current limitations and explicit design choices

- Route dispatch is explicit; there is no automatic controller scan or application-wide implicit middleware stack.
- CSRF is opt-in per handling action, even though its baseline configuration is enabled.
- Captcha image routing must be registered by the application; challenges depend on `citomni/image` and session state.
- Built-in HMAC verification is an inbound protocol; external senders must implement the same signing convention.
- Sessions are PHP session-backed, not a custom distributed store. Their behavior across workers depends on the chosen PHP session handler and storage.
- The nonce ledger is filesystem-based. Multi-server deployments must share the relevant ledger or provide another compatible replay strategy before relying on cross-node single-use guarantees.
- TemplateEngine compiles trusted CitOmni template syntax; it is not Twig and not a sandbox for arbitrary user-authored code.
- Template compilation still needs file output when warm reuse is disabled.
- Debug and system endpoints are not all governed by one identical authorization policy.
- The existing HTTP upload service remains focused on the workflows it implements; it is not a general object-store or media-library abstraction.

The absence of implicit behavior is deliberate. Integration points should remain visible and testable in the application's controllers and providers.

---

## Troubleshooting

These are the first checks when an HTTP deployment behaves differently from its source configuration:

| Symptom | Check |
|---|---|
| HTTP 500 during boot | Confirm PHP 8.5+, `ext-intl`, Composer dependencies, application root constants, valid locale settings, and writable required directories |
| Site fails to boot in `stage` or `prod` | Set an absolute `http.base_url` or a valid `CITOMNI_PUBLIC_ROOT_URL`; do not rely on development host inference |
| New route returns 404 | Confirm its key is in `config/citomni_http_routes.php` or the correct environment overlay; inspect `$app->routes` and `var/cache/routes.http.php` |
| Valid path returns 405 | Confirm route `methods`; remember the router adds `HEAD` for `GET` and handles `OPTIONS` itself |
| POST returns 413 | Compare declared `CONTENT_LENGTH` with PHP `post_max_size` and upstream web-server limits |
| Form reports CSRF failure | Confirm the form/header field, same browser session, request Origin/Fetch Metadata, HTTPS and proxy configuration, and explicit controller `csrf->verify()` |
| Login loses session or cookie | Check shared `cookie` attributes, `session.cookie_*` overrides, `SameSite=None`/Secure consistency, host/domain/path, session storage, and required ID rotation |
| Captcha image is missing | Register the configured `security.captcha.image_path` route and ensure `captchaImage` from `citomni/image` is available |
| Webhook fails authentication | Check `webhooks.enabled`, the private secret file, its lowercase-hex text key, exact method/path/query/body signature, Unix timestamp, allowed IPs, and writable `nonce.dir` |
| Template does not update | Check `view.template_layers`, compiled template artifacts beneath `var/cache`, template dependency freshness, and whether cache warm reuse is enabled |
| Endpoint unexpectedly exposes diagnostics | Audit `/_system/` routes, `?_perf`, `?_viewvars`, and dev-only `?raw=1` AppInfo output; restrict the deployment rather than assuming all system routes are protected |
| Error page looks wrong or logs are absent | Inspect `error_handler.templates`, `error_handler.log.path`, write permissions, and `error_handler.render.detail.level`; don't look for a removed `security` or `view` service |

The HTTP error-handler logs and `X-Request-Id` make it easier to correlate a failing response with the corresponding server-side record. The source maps and current code remain the authority when diagnosing a possible stale compiled artifact.

---

## Testing

The checked-in tests are run through a standalone PHP runner; this package's current `composer.json` does **not** declare a `composer test` script.

From the repository root:

```bash
php tests/run.php
```

The runner invokes the component test suites under `tests/`, including:

```text
tests/captcha/run.php
tests/cookie/run.php
tests/csrf/run.php
tests/error-handler/run.php
tests/nonce/run.php
tests/post-max-size/run.php
tests/request/run.php
tests/router/run.php
tests/session/run.php
tests/url/run.php
tests/webhooks-auth/run.php
```

There are also focused TemplateEngine scripts:

```bash
php tests/template-engine-regression.php
php tests/template-engine-concurrency.php
php tests/template-engine-benchmark.php
```

The regression script validates compiler behavior and rendered output. The concurrency script covers simultaneous compilation and needs appropriate process execution support; the benchmark is a diagnostic comparison, not a universal production performance target.

The suite includes HTTP server-backed tests and filesystem/locking checks. Run it in a PHP **8.5+** environment with the dependencies and extensions required by the selected tests. Test prerequisites, supported environments, and individual cases are explained in their corresponding `tests/*/README.md` files.

For development syntax checks on Windows CMD:

```cmd
for /r src %f in (*.php) do @php -l "%f"
for /r tests %f in (*.php) do @php -l "%f"
```

In a `.cmd`/`.bat` file, replace `%f` with `%%f`.

---

## Internal structure

The current package layout is organized by its actual responsibilities:

```text
src/
├── Boot/
│   └── Registry.php
├── Controller/
│   ├── CaptchaController.php
│   ├── PublicController.php
│   └── SystemController.php
├── Enum/
│   ├── CsrfFailureReason.php
│   └── WebhooksAuthFailureReason.php
├── Exception/
│   ├── CaptchaConfigException.php
│   ├── CsrfException.php
│   ├── CsrfVerificationException.php
│   ├── IconConfigException.php
│   ├── IconNotFoundException.php
│   ├── NonceConfigException.php
│   ├── NonceException.php
│   ├── WebhooksAuthConfigException.php
│   ├── WebhooksAuthException.php
│   └── WebhooksAuthVerificationException.php
├── Service/
│   ├── Captcha.php
│   ├── Cookie.php
│   ├── Csrf.php
│   ├── Datetime.php
│   ├── ErrorHandler.php
│   ├── Flash.php
│   ├── Icon.php
│   ├── Maintenance.php
│   ├── Nonce.php
│   ├── Request.php
│   ├── Response.php
│   ├── Router.php
│   ├── Session.php
│   ├── Slugger.php
│   ├── Tags.php
│   ├── TemplateEngine.php
│   ├── Upload.php
│   └── WebhooksAuth.php
├── Util/
│   └── Url.php
└── Kernel.php

install/             Application scaffolds and installer manifest
assets/icons/        Package icon definitions
templates/           Vendor public, maintenance and error templates
language/            Translations
tests/               Focused PHP regression and integration scripts
```

The boundaries are intentional:

- `Kernel` owns HTTP-mode bootstrap and lifecycle.
- `Boot/Registry` declares baseline service, configuration, and route maps.
- `Controller/` contains the package's HTTP adapters, not application business workflows.
- `Service/` provides registered App-aware HTTP capabilities.
- `Enum/` and `Exception/` describe stable, explicit failure semantics.
- `Util/Url` contains focused URL classification logic without an App dependency.

There is no Repository layer in this package because it does not own application SQL. Application Operations and Repositories live in the host app or in their relevant domain packages.

---

## Coding and architectural principles

CitOmni HTTP follows the framework's normal discipline:

- PHP 8.5+, Composer, and PSR-4 autoloading.
- PascalCase classes, camelCase methods and variables, and UPPER_SNAKE_CASE constants.
- Tabs for indentation and K&R braces.
- English PHPDoc and inline comments.
- Explicit configuration, predictable failure, and minimal hidden initialization.
- Transport concerns in HTTP controllers; no SQL in services or controllers.
- Prefer a short implementation when extra abstraction brings no concrete value.
- Preserve public API semantics and avoid back-compatibility shims for removed, unsupported services.
- Optimize normal request paths without compromising security and error visibility.

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:

[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni HTTP** is open source under the **MIT License**.

See [LICENSE](LICENSE).

The name "CitOmni" and the CitOmni logo are separately protected trademarks of **Lars Grove Mortensen**. The MIT code license does not transfer rights to use the marks as independent branding or imply official affiliation. See [NOTICE](NOTICE).

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Factual compatibility references are permitted under the conditions in [NOTICE](NOTICE); do not use the marks as your own product identity or imply sponsorship, endorsement, or certification without permission.

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
