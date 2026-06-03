# =============================================================================
# CitOmni - Root .htaccess (app-root == webroot; route all traffic to /public)
# PROD + STAGE version
#
# PURPOSE
#   Make shared hosting safe when vhost config is not available and app-root IS
#   the webroot. We:
#     1) Deny access to hidden & sensitive files regardless of mod_rewrite.
#     2) Block direct hits to internal directories with a clean 404.
#     3) Route everything else into /public/ (front controller, assets).
#
# THREAT MODEL
#   - Prevent disclosure of source (/src), config (/config), vendor code, caches,
#     and tooling even if a later rewrite rule is changed.
#   - Avoid serving ANY file from app-root except explicit allowlists (e.g. ACME).
#   - Keep UX clean: 404 for internal dirs, 403 for dotfiles.
#
# DEPENDENCIES
#   - Works on Apache 2.4+ (authz_core). mod_rewrite is used for routing, but
#     security denies below DO NOT rely on mod_rewrite.
#   - Fallback redirect to /public/ provided via mod_alias if mod_rewrite is off.
#
# BEHAVIOR
#   - Deny dotfiles and common secrets via Files/FilesMatch (no rewrite needed).
#   - Hard-block requests to internal dirs (404) before any routing.
#   - Route all non-/public/ requests into ./public/ (relative; subdir-safe).
#   - Optional ACME support via local guard for .well-known/.
#
# NOTES
#   - Place additional .htaccess "Require all denied" files inside /config, /src,
#     /vendor, /var, etc. for defense-in-depth.
#   - If your host has AllowOverride None, .htaccess is ignored-verify once.
# =============================================================================


# -----------------------------------------------------------------------------
# 0) Baseline directory options
#    WHY: No directory listings; MultiViews off to avoid content-negotiation quirks.
# -----------------------------------------------------------------------------
Options -Indexes -MultiViews


# -----------------------------------------------------------------------------
# 1) Never expose hidden files or common secrets (independent of mod_rewrite)
# -----------------------------------------------------------------------------
# Block dotfiles (.env, .git, .ht*, etc.)
<FilesMatch "^\.">
	Require all denied
</FilesMatch>

# Block common project/CI/build config files
<FilesMatch "^(composer\.(json|lock)|phpunit\.xml(\.dist)?|README(\.md)?|LICENSE(\.md)?)$">
	Require all denied
</FilesMatch>


# -----------------------------------------------------------------------------
# 2) Primary routing & internal directory hard-blocks (mod_rewrite)
#    Subdirectory-safe: no RewriteBase; patterns are relative to this dir.
# -----------------------------------------------------------------------------
<IfModule mod_rewrite.c>
	RewriteEngine On

	# (2a) Canonicalization - HTTPS (301)
	#      WHY: Enforce TLS at the edge; honors X-Forwarded-Proto for proxy/CDN setups.
	#           This ensures all traffic is redirected to https:// and prevents mixed-protocol access.
	RewriteCond %{HTTPS} !=on
	RewriteCond %{HTTP:X-Forwarded-Proto} !https
	RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

	# (2b) Canonicalization - WWW (301)
	#      WHY: Force a single hostname (with "www.") for SEO and cache consistency.
	#           Skips ACME challenges in /.well-known/ so certificate issuance still works.
	RewriteCond %{REQUEST_URI} !^/\.well-known/ [NC]
	RewriteCond %{HTTP_HOST} !^www\. [NC]
	RewriteRule ^ https://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

	# (2c) Security guards - block sensitive internal directories
	#      WHY: Even if someone tries to hit /src/... we do not "redirect" into /public,
	#           we explicitly 404 these sensitive namespaces to prevent accidental exposure.
	RewriteRule ^(?i:(config|src|vendor|var|bin|tests|templates|language|tools|docs|\.git|\.github))(?=/|$) - [R=404,L]

	# (2d) Security guards - forbid dotfiles via rewrite
	#      WHY: Extra belt-and-suspenders alongside <FilesMatch>; denies direct access to .env, .git etc.
	RewriteRule "(^|/)\." - [F]

	# (2e) Guard: stop processing if already targeting /public
	#      WHY: Prevents infinite loops and lets requests to /public/... pass through unchanged.
	RewriteRule ^public/ - [L]
	# RewriteRule ^\.well-known/ - [L]   # WHEN: uncomment if you keep ACME challenges here.

	# (2f) Internal routing into /public/
	#      WHY: Route everything else into ./public/ while setting an env-flag so
	#           the /public/.htaccess knows this came from app-root and can skip duplicate redirects.
	RewriteRule ^(.*)$ public/$1 [E=FROM_APP_ROOT:1,L]

</IfModule>


# -----------------------------------------------------------------------------
# 3) Fallback routing if mod_rewrite is unavailable (mod_alias)
#    Relative pattern & target -> safe in subdirectories.
# -----------------------------------------------------------------------------
<IfModule !mod_rewrite.c>
	<IfModule mod_alias.c>
		# Preserve method on redirect where supported
		RedirectMatch 307 ^(?!public/|\.well-known/)(.*)$ public/$1
	</IfModule>
</IfModule>
