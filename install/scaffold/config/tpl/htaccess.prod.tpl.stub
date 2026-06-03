# =============================================================================
# CitOmni - PUBLIC ROOT (PROD)
#
# PURPOSE
#   Production-grade front controller for CitOmni apps served from /public.
#   - Canonicalize requests (HTTPS; optional WWW).
#   - Serve existing files directly; route all other requests to index.php.
#   - Apply hardened security headers plus sane caching & compression defaults.
#
# THREAT MODEL
#   - Prevent direct access to sensitive files under /public (e.g., .env, composer.*).
#   - Reduce server fingerprinting (suppress X-Powered-By and banner details).
#   - Avoid duplicate content (/index.php vs clean URL) and mixed-protocol access.
#   - Preserve Authorization headers across proxies/FPM.
#   - Disable directory listing and MultiViews content negotiation surprises.
#
# DEPENDENCIES
#   - mod_rewrite recommended (fallback keeps root -> index.php functional).
#   - mod_headers/mod_expires/mod_brotli/mod_deflate used when available.
#   - Honors X-Forwarded-Proto for TLS behind proxies/CDNs.
#   - HSTS is off by default; enable only when 100% HTTPS everywhere.
# =============================================================================


# -----------------------------------------------------------------------------
# 1) INDEX & DIRECTORY OPTIONS
#    WHY: Ensure PHP front controller is the primary index and avoid listings
#         or MultiViews content negotiation surprises.
# -----------------------------------------------------------------------------
DirectoryIndex index.php

# WHEN: Enable only if your host needs it (symlinked asset dirs, etc.)
# Options FollowSymlinks

# Disable directory listing
Options -Indexes

# Disable MultiViews (e.g., prevent /about matching /about.php)
<IfModule mod_negotiation.c>
	Options -MultiViews
</IfModule>


# -----------------------------------------------------------------------------
# 2) FRONT CONTROLLER & CANONICALIZATION (mod_rewrite)
#    WHY: Enforce HTTPS, optional WWW, support subdir installs, preserve auth
#         headers, and route all non-files to index.php.
# -----------------------------------------------------------------------------
<IfModule mod_rewrite.c>
	RewriteEngine On

	# (2.1) Deny unsafe HTTP methods (TRACE/TRACK)
	#       WHY: TRACE/TRACK can be abused for Cross-Site Tracing (XST) attacks and
	#            should never be allowed in production environments.
	RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK)$ [NC]
	RewriteRule ^ - [F,L]

	# (2.2) Canonicalization - HTTPS (301), skip if came from app-root
	#       WHY: Enforce TLS at the edge; honors X-Forwarded-Proto for proxy/CDN setups.
	#            The skip ensures we don't re-redirect requests already canonicalized in app-root.
	RewriteCond %{ENV:FROM_APP_ROOT} !1
	RewriteCond %{HTTPS} !=on
	RewriteCond %{HTTP:X-Forwarded-Proto} !https
	RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

	# (2.3) Canonicalization - WWW (301), skip if came from app-root
	#       WHY: Force a single hostname (with "www.") for SEO and cache consistency.
	#            Skipped when app-root already handled canonicalization.
	RewriteCond %{ENV:FROM_APP_ROOT} !1
	RewriteCond %{HTTP_HOST} !^www\. [NC]
	RewriteRule ^ https://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

	# (2.4) Optional: BASE autodetect for subdir installs
	#       WHY: Only needed if the app is deployed under a subfolder and you want clean URLs.
	#            Leave commented out when docroot is already /public.
	# RewriteCond %{REQUEST_URI}::$1 ^(/.+)/(.*)::\2$
	# RewriteRule ^(.*) - [E=BASE:%1]

	# (2.5) Preserve Authorization header (FPM/CGI)
	#       WHY: Some PHP-FPM/CGI setups drop the HTTP Authorization header.
	#            This rule re-exposes it to the application via environment variable.
	RewriteCond %{HTTP:Authorization} .
	RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

	# (2.6) Redirect /index.php -> clean URL (301)
	#       WHY: Avoid duplicate content and enforce canonical pretty URLs.
	#            IMPORTANT: Never include BASE in the redirect URL (only in internal rewrites).
	RewriteCond %{ENV:REDIRECT_STATUS} ^$
	RewriteRule ^index\.php(?:/(.*)|$) /$1 [R=301,L]

	# (2.7) Serve existing files/dirs directly
	#       WHY: Fast-path for static assets. Requests to existing files or directories
	#            bypass the front controller for performance.
	RewriteCond %{REQUEST_FILENAME} -f [OR]
	RewriteCond %{REQUEST_FILENAME} -d
	RewriteRule ^ - [L]

	# (2.8) Front controller (internal rewrite)
	#       WHY: All other requests are routed into index.php (the PHP front controller).
	#            This allows clean URLs to resolve through the app's router.
	RewriteRule ^ index.php [L]

</IfModule>

# -----------------------------------------------------------------------------
# 3) FALLBACK WHEN mod_rewrite IS UNAVAILABLE
#    WHY: Keep the site functional on constrained hosts (temporary redirect).
# -----------------------------------------------------------------------------
<IfModule !mod_rewrite.c>
	<IfModule mod_alias.c>
		RedirectMatch 307 ^/$ /index.php
	</IfModule>
</IfModule>


# -----------------------------------------------------------------------------
# 4) SECURITY HEADERS (SAFE DEFAULTS)
#    WHY: Reduce server fingerprinting and enable baseline browser protections.
#    NOTE: Set `expose_php = Off` in php.ini/.user.ini to suppress X-Powered-By.
# -----------------------------------------------------------------------------
# ServerTokens Prod
ServerSignature Off

<IfModule mod_headers.c>
	# Minimize tech disclosure
	Header always unset X-Powered-By
	Header always unset X-AspNet-Version
	Header always unset X-AspNetMvc-Version

	# Core protections
	Header always set X-Content-Type-Options "nosniff"
	Header always set X-Frame-Options "SAMEORIGIN"
	Header always set Referrer-Policy "strict-origin-when-cross-origin"
	Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

	# Optional, usually safe:
	Header always set Cross-Origin-Resource-Policy "same-origin"
	# Header always set Cross-Origin-Opener-Policy "same-origin"   # WHEN: enable if you don't rely on cross-origin window integrations

	# Content-Security-Policy
	# WHEN: Customize before enabling; start strict and open up as needed.
	# Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"

	# HSTS (Strict-Transport-Security)
	# WHEN: Enable only when 100% HTTPS everywhere (including subdomains).
	# Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
</IfModule>


# -----------------------------------------------------------------------------
# 5) BLOCK SENSITIVE FILES UNDER /public
#    WHY: Prevent accidental exposure of configs, archives, locks, etc.
# -----------------------------------------------------------------------------
<FilesMatch "(?i)\.(env|log|sql|sqlite|bak|bck|old|dist|ini|phar|lock|swp|tmp|temp|orig|rej|patch|diff|cfg|crt|key|pem|ds_store)$">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Deny from all
	</IfModule>
</FilesMatch>
<FilesMatch "(?i)^(composer\.(json|lock)|package\.json|yarn\.lock|pnpm-lock\.yaml|\.gitignore|\.gitattributes|\.htaccess|\.htpasswd)$">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Deny from all
	</IfModule>
</FilesMatch>
<FilesMatch "^\.[^/]*$">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Deny from all
	</IfModule>
</FilesMatch>


# -----------------------------------------------------------------------------
# 6) EXPIRES / CACHING
#    WHY: Favor long-lived caches for fingerprinted assets; keep HTML/API fresh.
#    NOTE: Tune values to your asset pipeline and CDN strategy.
# -----------------------------------------------------------------------------
<IfModule mod_expires.c>
	ExpiresActive on
	ExpiresDefault                                   "access plus 1 month"

	# CSS
	ExpiresByType text/css                           "access plus 1 year"

	# Data interchange
	ExpiresByType application/rss+xml                "access plus 1 hour"
	ExpiresByType application/json                   "access plus 0 seconds"
	ExpiresByType application/schema+json            "access plus 0 seconds"
	ExpiresByType application/xml                    "access plus 0 seconds"
	ExpiresByType text/xml                           "access plus 0 seconds"

	# Favicon & icons
	ExpiresByType image/vnd.microsoft.icon           "access plus 1 week"
	ExpiresByType image/x-icon                       "access plus 1 week"

	# HTML
	ExpiresByType text/html                          "access plus 0 seconds"

	# JavaScript
	ExpiresByType application/javascript             "access plus 1 year"
	ExpiresByType application/x-javascript           "access plus 1 year"
	ExpiresByType text/javascript                    "access plus 1 year"

	# Images
	ExpiresByType image/gif                          "access plus 1 month"
	ExpiresByType image/jpeg                         "access plus 1 month"
	ExpiresByType image/png                          "access plus 1 month"
	ExpiresByType image/svg+xml                      "access plus 1 month"
	ExpiresByType image/webp                         "access plus 1 month"

	# Fonts
	ExpiresByType application/vnd.ms-fontobject      "access plus 1 month"
	ExpiresByType font/eot                           "access plus 1 month"
	ExpiresByType font/opentype                      "access plus 1 month"
	ExpiresByType application/x-font-ttf             "access plus 1 year"
	ExpiresByType application/font-woff              "access plus 1 month"
	ExpiresByType application/x-font-woff            "access plus 1 month"
	ExpiresByType font/woff                          "access plus 1 month"
	ExpiresByType application/font-woff2             "access plus 1 month"

	# Other
	ExpiresByType text/x-cross-domain-policy         "access plus 1 week"
</IfModule>


# -----------------------------------------------------------------------------
# 7) COMPRESSION (Brotli + Deflate + legacy mod_gzip)
#    WHY: Reduce payload size for text-based assets; avoid recompressing binaries.
# -----------------------------------------------------------------------------
<IfModule mod_brotli.c>
	AddOutputFilterByType BROTLI_COMPRESS text/plain 
	AddOutputFilterByType BROTLI_COMPRESS text/html 
	AddOutputFilterByType BROTLI_COMPRESS text/css 
	AddOutputFilterByType BROTLI_COMPRESS text/javascript
	AddOutputFilterByType BROTLI_COMPRESS application/javascript
	AddOutputFilterByType BROTLI_COMPRESS application/json
	AddOutputFilterByType BROTLI_COMPRESS application/ld+json
	AddOutputFilterByType BROTLI_COMPRESS application/manifest+json
	AddOutputFilterByType BROTLI_COMPRESS application/xml
	AddOutputFilterByType BROTLI_COMPRESS application/xhtml+xml
	AddOutputFilterByType BROTLI_COMPRESS application/rss+xml
	AddOutputFilterByType BROTLI_COMPRESS image/svg+xml
</IfModule>

<IfModule mod_deflate.c>
	AddOutputFilterByType DEFLATE text/plain
	AddOutputFilterByType DEFLATE text/html
	AddOutputFilterByType DEFLATE text/css
	AddOutputFilterByType DEFLATE text/javascript
	AddOutputFilterByType DEFLATE text/xml 
	AddOutputFilterByType DEFLATE application/javascript
	AddOutputFilterByType DEFLATE application/json
	AddOutputFilterByType DEFLATE application/ld+json
	AddOutputFilterByType DEFLATE application/manifest+json
	AddOutputFilterByType DEFLATE application/xml
	AddOutputFilterByType DEFLATE application/xhtml+xml
	AddOutputFilterByType DEFLATE application/rss+xml 
	# AddOutputFilterByType DEFLATE application/vnd.ms-fontobject
	# AddOutputFilterByType DEFLATE application/x-font
	# AddOutputFilterByType DEFLATE application/x-font-opentype
	# AddOutputFilterByType DEFLATE application/x-font-otf
	# AddOutputFilterByType DEFLATE application/x-font-truetype
	# AddOutputFilterByType DEFLATE application/x-font-ttf
	# AddOutputFilterByType DEFLATE application/x-javascript 
	# AddOutputFilterByType DEFLATE font/opentype
	# AddOutputFilterByType DEFLATE font/otf
	# AddOutputFilterByType DEFLATE font/ttf
	AddOutputFilterByType DEFLATE image/svg+xml 
	# AddOutputFilterByType DEFLATE image/x-icon
</IfModule>

<IfModule mod_gzip.c>
	mod_gzip_on Yes
	mod_gzip_dechunk Yes
	mod_gzip_item_include file \.(html?|txt|css|js|php|pl)$
	mod_gzip_item_include handler ^cgi-script$
	mod_gzip_item_include mime ^text/.*
	mod_gzip_item_include mime ^application/x-javascript.*
	mod_gzip_item_exclude mime ^image/.*
	mod_gzip_item_exclude rspheader ^Content-Encoding:.*gzip.*
</IfModule>


# -----------------------------------------------------------------------------
# 8) PROTECT SPECIFIC SENSITIVE FILE(S)
#    WHEN: Uncomment to explicitly deny a known file if you keep logs under /public.
#    NOTE: Prefer to store logs outside /public whenever possible.
# -----------------------------------------------------------------------------
# <Files "error_log.json">
# 	<IfModule mod_authz_core.c>Require all denied</IfModule>
# 	<IfModule !mod_authz_core.c>Deny from all</IfModule>
# </Files>


# -----------------------------------------------------------------------------
# 9) ENVIRONMENT IDENTIFICATION HEADERS
#    WHY: Quick verification for ops/health checks; mirror value in CDN/WAF.
# -----------------------------------------------------------------------------
<IfModule mod_headers.c>
	Header always set X-CitOmni-Env "prod"
	# Header always set X-CitOmni-Config "prod-2025-09-04-a1b2c3"
</IfModule>
