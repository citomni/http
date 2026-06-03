# =============================================================================
# CitOmni - robots.txt (PRODUCTION)
#
# PURPOSE
#   Provide clear crawl directives for search engines:
#     1) Allow normal crawling of content, CSS/JS, and static assets.
#     2) Block noisy tracking/sorting/session parameters to reduce duplicate URLs.
#     3) Prevent indexation of thin pages (e.g., /search) via Disallow (with caveat).
#     4) Point crawlers to the canonical sitemap for discovery.
#
# THREAT MODEL
#   - Avoid wasting crawl budget on endless querystring variants (utm_, gclid, etc.).
#   - Prevent duplicate content issues from sort/session params.
#   - Stop bots from hammering search endpoints that generate infinite pages.
#   - Ensure CSS/JS remain fetchable, so Google can render pages correctly.
#
# BEHAVIOR
#   - "Disallow" prevents crawling, but *not necessarily indexing* if URLs are linked externally.
#     -> For true de-indexing, also serve <meta name="robots" content="noindex">
#       or an X-Robots-Tag header on those pages.
#   - "Allow" ensures querystringed CSS/JS (e.g., style.css?v=123) are crawlable.
#   - "Crawl-delay" is non-standard: ignored by Google, but respected by some bots.
#   - "Sitemap" must be an absolute URL.
#
# NOTES
#   - Tailor Disallow list to your app: add /login, /checkout, etc. if appropriate.
#   - In STAGING/DEV, use a blanket "Disallow: /" instead.
# =============================================================================


User-agent: *

# -----------------------------------------------------------------------------
# 1) Block noisy tracking / sorting / session parameters
# -----------------------------------------------------------------------------
Disallow: /*?*utm_
Disallow: /*?*gclid=
Disallow: /*?*fbclid=
Disallow: /*?*sort=
Disallow: /*?*session=

# -----------------------------------------------------------------------------
# 2) Block thin/duplicate pages (search results etc.)
# -----------------------------------------------------------------------------
Disallow: /search
Disallow: /search?

# -----------------------------------------------------------------------------
# 3) Ensure render-critical resources are crawlable (also with cache-busting qs)
# -----------------------------------------------------------------------------
Allow: /*.css*
Allow: /*.js*

# (Optional) Explicitly allow other asset types if blocked higher up
# Allow: /*.png*
# Allow: /*.jpg*
# Allow: /*.jpeg*
# Allow: /*.webp*
# Allow: /*.svg*
# Allow: /*.woff*
# Allow: /*.woff2*
# Allow: /*.ttf*

# -----------------------------------------------------------------------------
# 4) Non-standard directives (optional)
# -----------------------------------------------------------------------------
# Crawl-delay: 2

# -----------------------------------------------------------------------------
# 5) Sitemap location (absolute URL, adjust if app runs in subdir)
# -----------------------------------------------------------------------------
Sitemap: https://example.com/sitemap.xml
