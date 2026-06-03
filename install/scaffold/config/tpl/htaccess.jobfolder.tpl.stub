# =============================================================================
# CitOmni - /public/jobs/ hardening (DevKit one-shot jobs)
#
# PURPOSE
#   Staging area for DevKit one-shot PHP jobs named like: job*.php
#   - Deny ALL access by default.
#   - Allow ONLY whitelisted job scripts (job*.php).
#   - Serve logs/output files as plain text.
#   - Disable directory listing and caching.
#
# THREAT MODEL
#   - Prevent probing of arbitrary files in the folder.
#   - Prevent exposure of config/artifacts (logs, JSON, temp files).
#   - Make job outputs non-cacheable and easy to fetch as text.
#
# DEPENDENCIES
#   - Apache 2.4+ (mod_authz_core). Legacy 2.2 fallback included (mod_access_compat).
#   - mod_headers for no-cache headers (optional but recommended).
#   - mod_mime for AddType (usually loaded by default).
# =============================================================================


# -----------------------------------------------------------------------------
# 1) DIRECTORY OPTIONS
#    WHY: Hide folder contents if index is missing; reduce surface for probing.
# -----------------------------------------------------------------------------
Options -Indexes


# -----------------------------------------------------------------------------
# 2) DEFAULT DENY (DENY EVERYTHING BY DEFAULT)
#    WHY: Strong baseline-nothing is reachable unless explicitly allowed below.
# -----------------------------------------------------------------------------
# Apache 2.4+
<IfModule mod_authz_core.c>
	<FilesMatch ".*">
		Require all denied
	</FilesMatch>
</IfModule>
# Apache 2.2 fallback
<IfModule !mod_authz_core.c>
	<FilesMatch ".*">
		Order allow,deny
		Deny from all
	</FilesMatch>
</IfModule>


# -----------------------------------------------------------------------------
# 3) ALLOWLIST JOB SCRIPTS: ^job.*\.php$
#    WHY: Only one-shot job scripts uploaded by DevKit should be callable.
#    NOTE: Keep the "job" prefix-do NOT loosen this pattern.
#          Jobs themselves should implement their own authorization/token checks.
# -----------------------------------------------------------------------------
# Apache 2.4+
<IfModule mod_authz_core.c>
	<FilesMatch "^job.*\.php$">
		Require all granted
	</FilesMatch>
</IfModule>
# Apache 2.2 fallback
<IfModule !mod_authz_core.c>
	<FilesMatch "^job.*\.php$">
		Order deny,allow
		Allow from all
	</FilesMatch>
</IfModule>


# -----------------------------------------------------------------------------
# 4) TEXT MIME FOR LOG/OUTPUT ARTIFACTS
#    WHY: Ensure log/output files render as text in the browser (no download prompts).
#    WHEN: Extend this list if your jobs emit other safe, textual artifacts.
# -----------------------------------------------------------------------------
<IfModule mod_mime.c>
	AddType text/plain .log .out .txt .json .bak .old
</IfModule>


# -----------------------------------------------------------------------------
# 5) NO-CACHE HEADERS FOR JOB RESPONSES & ARTIFACTS
#    WHY: Make iterative job runs visible immediately; avoid stale caches.
# -----------------------------------------------------------------------------
<IfModule mod_headers.c>
	Header always set Cache-Control "no-store, no-cache, must-revalidate"
	Header always set Pragma "no-cache"
	Header always set Expires "0"
</IfModule>
