# =============================================================================
# CitOmni - robots.txt (STAGING)
#
# PURPOSE
#   Block all search engine crawling on staging environments.
#   Staging sites may contain test content, incomplete features,
#   or duplicate production data. Nothing here should be indexed.
#
# THREAT MODEL
#   - Prevent duplicate indexation of content before launch.
#   - Ensure staging URLs do not leak into SERPs.
#   - Avoid crawl budget waste on temporary environments.
#
# BEHAVIOR
#   - Blanket "Disallow: /" stops bots from crawling any paths.
#   - Some bots may still index if they find external links.
#     -> Serve <meta name="robots" content="noindex"> or X-Robots-Tag
#        headers for true guarantee.
#
# NOTES
#   - This file must never be deployed to production.
#   - If you want *absolute certainty*, also protect staging with HTTP auth.
# =============================================================================


User-agent: *
Disallow: /

# (Optional) Sitemap declaration can be omitted entirely in staging.
# Sitemap: https://staging.example.com/sitemap.xml
