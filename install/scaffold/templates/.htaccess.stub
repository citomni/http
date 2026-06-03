# =============================================================================
# CitOmni — Hard-deny for internal directories (i.e. /config, /src, /vendor)
#
# PURPOSE
#   Ensure that *nothing* inside this directory (and its children) can be
#   fetched over HTTP. Use this in any non-public folder when app-root == webroot.
#
# THREAT MODEL
#   - Prevent disclosure of source code, configs, caches, secrets, and tools.
#   - Remain safe even if a parent .htaccess weakens rules later.
#
# DEPENDENCIES
#   - Primary rule uses Apache 2.4+ authorization (mod_authz_core).
#   - Fallback block maintains compatibility with legacy Apache 2.2.
#   - No reliance on mod_rewrite; pure authz directives.
#
# BEHAVIOR
#   - On Apache 2.4+: `Require all denied` forbids all requests (403).
#   - On Apache 2.2: classic `Order allow,deny` + `Deny from all`.
#   - Safe to deploy on both; one of the branches will apply.
#
# NOTES
#   - Place this file *inside* each internal directory you want to protect
#     (e.g., /config/.htaccess, /src/.htaccess, /vendor/.htaccess, /var/.htaccess, …).
#   - Combine with a root .htaccess that 404/403s whole folder names for
#     defense-in-depth and cleaner UX.
# =============================================================================


# --- Primary (Apache 2.4+): deny everything in this directory ---
Require all denied


# --- Fallback (Apache 2.2): deny everything if mod_authz_core is unavailable ---
<IfModule !mod_authz_core.c>
	# Legacy authorization model:
	# - Process "allow" directives first, then apply "deny" (none allowed -> all denied).
	Order allow,deny
	Deny from all
</IfModule>
