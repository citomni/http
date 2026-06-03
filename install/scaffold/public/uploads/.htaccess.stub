# =============================================================================
# CitOmni — /public/uploads/ hardening
#
# PURPOSE
#   /uploads/ is a PUBLIC, web-reachable directory intended only for safe,
#   static files (e.g., avatars, decorative images). Nothing here should ever
#   require code execution. Sensitive files must live outside webroot and be
#   served via a PHP controller (ideally with X-Sendfile / X-Accel-Redirect).
#
# THREAT MODEL
#   - Prevent execution of scripts accidentally or maliciously placed here.
#   - Prevent "double extension" tricks (e.g., file.php.jpg).
#   - Hide dotfiles (.htaccess, .git, .env).
#   - Avoid directory listing & MIME sniffing pitfalls.
#
# DEPENDENCIES
#   This file is defensive-by-default:
#   - Uses mod_rewrite if available (for robust, case-insensitive blocking).
#   - Falls back to FilesMatch/handler removal if mod_rewrite is unavailable.
#   - Safe on both mod_php and PHP-FPM setups (php_flag engine off is a no-op on FPM).
# =============================================================================


# -----------------------------------------------------------------------------
# 1) PRIMARY GUARD: block script execution (case-insensitive), incl. double-ext
#    WHY: Attackers often try payloads like image.jpg.php or image.PHP to bypass naive filters.
#    (?i)     = case-insensitive
#    \.(...)  = match dangerous extension anywhere in the name
#    (?:\..*)?= allow optional trailing ".something" (double/chain extensions)
# -----------------------------------------------------------------------------
<IfModule mod_rewrite.c>
	RewriteEngine On

	# Deny common script/binary extensions anywhere in filename (incl. double-ext)
	RewriteRule (?i)\.(?:php\d*|phtml|pht|phtm|phar|phps|s?html|htm|cgi|pl|py|sh|bash|exe|dll|so)(?:\..*)?$ - [F,L]
</IfModule>


# -----------------------------------------------------------------------------
# 2) SECONDARY GUARD: explicit deny by extension (fallback if mod_rewrite is off)
#    WHY: Some hosts disable mod_rewrite; this still blocks direct hits to bad types.
# -----------------------------------------------------------------------------
<FilesMatch "(?i)\.(php\d*|phtml|pht|phtm|phar|phps|s?html|htm|cgi|pl|py|sh|bash|exe|dll|so)$">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Deny from all
	</IfModule>
</FilesMatch>


# -----------------------------------------------------------------------------
# 3) BELT & SUSPENDERS for mod_php environments
#    WHY: If Apache runs PHP as a module, this fully disables the PHP engine here.
#    NOTE: On PHP-FPM, these directives are harmless no-ops.
# -----------------------------------------------------------------------------
<IfModule mod_php.c>
	php_flag engine off
</IfModule>
<IfModule mod_php7.c>
	php_flag engine off
</IfModule>
<IfModule mod_php8.c>
	php_flag engine off
</IfModule>


# -----------------------------------------------------------------------------
# 4) Neutralize upstream handlers/types that could re-enable execution
#    WHY: Some parent vhosts/dirs may map handlers/types broadly; we remove them here.
# -----------------------------------------------------------------------------
<IfModule mod_mime.c>
	RemoveHandler .php .phtml .pht .phtm .phar .phps .shtml .html .htm .cgi .pl .py .sh .bash .exe .dll .so
	RemoveType    .php .phtml .pht .phtm .phar .phps .shtml .html .htm .cgi .pl .py .sh .bash .exe .dll .so
	# WHEN: If a handler slips through, force these to render as plain text (defense-in-depth).
	# NOTE: This does not "execute" anything; it only changes Content-Type.
	AddType text/plain .php .phtml .pht .phtm .phar .phps .shtml .html .htm .cgi .pl .py .sh .bash
</IfModule>


# -----------------------------------------------------------------------------
# 5) Hide dotfiles (.htaccess, .git, .env, etc.)
#    WHY: Dotfiles often contain config, secrets, or control behavior.
# -----------------------------------------------------------------------------
<FilesMatch "^\..+">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Deny from all
	</IfModule>
</FilesMatch>


# -----------------------------------------------------------------------------
# 6) Directory options
#    - No indexes: prevents listing contents if no index file exists.
#    - -ExecCGI  : extra guard against CGI execution via handler mappings.
# -----------------------------------------------------------------------------
Options -Indexes -ExecCGI


# -----------------------------------------------------------------------------
# 7) Defensive headers
#    - X-Content-Type-Options: nosniff → prevent browsers from MIME-sniffing.
#    Use "always" so it applies to 403/404 too.
# -----------------------------------------------------------------------------
<IfModule mod_headers.c>
	Header always set X-Content-Type-Options "nosniff"
</IfModule>


# -----------------------------------------------------------------------------
# 8) OPTIONAL: strict allowlist for known-safe file types
#    WHEN: If your app only needs a handful of types (e.g., jpg/png/webp/pdf),
#    you can uncomment this block to hard-enforce it.
#    NOTE: SVG can embed scripts; only allow if you sanitize on upload.
# -----------------------------------------------------------------------------
# <FilesMatch "\.(?i:jpe?g|png|gif|webp|pdf|txt)$">
# 	<IfModule mod_authz_core.c>
# 		Require all granted
# 	</IfModule>
# </FilesMatch>


# -----------------------------------------------------------------------------
# 9) OPTIONAL: caching policy for immutable variants (avatars, thumbnails)
#    WHEN: If your filenames are content/variant-based (e.g., avatar-w200.webp),
#    you may advertise long-lived caches. Prefer setting per-route in app/CDN,
#    but this is a reasonable default for static variants under /uploads/.
# -----------------------------------------------------------------------------
# <IfModule mod_expires.c>
# 	ExpiresActive On
# 	# 1 year for common web images; adjust as needed
# 	ExpiresByType image/jpeg  "access plus 1 year"
# 	ExpiresByType image/png   "access plus 1 year"
# 	ExpiresByType image/webp  "access plus 1 year"
# 	ExpiresByType image/gif   "access plus 1 year"
# 	# If you allow SVG and you sanitize it, you can add it here; otherwise avoid caching it aggressively.
# </IfModule>


# -----------------------------------------------------------------------------
# 10) OPTIONAL: block internal helper folders if you ever add them under /uploads/
#     RECOMMENDATION: Prefer NOT to place _private/_quarantine under /uploads/.
#     If you do, keep them blocked here.
# -----------------------------------------------------------------------------
# <IfModule mod_rewrite.c>
# 	RewriteEngine On
# 	RewriteRule ^(?:_private/|_quarantine/|tmp/)\b - [F,L]
# </IfModule>
