# =============================================================================
# CitOmni - robots.txt (DEVELOPMENT)
#
# PURPOSE
#   Block all crawling and explicitly signal "noindex" intention.
#   Development sites are for internal use only.
#
# THREAT MODEL
#   - Prevent leaks of dev URLs into search engines.
#   - Ensure no accidental crawl/indexing of unfinished features.
#
# BEHAVIOR
#   - Blanket "Disallow: /" denies all crawl paths.
#   - "Noindex: /" (non-standard, but supported by Google & Bing)
#     explicitly tells bots not to index *any* URLs, even if linked.
#
# NOTES
#   - This file must never be deployed to staging or production.
#   - Best practice: Combine with firewall/HTTP auth for stronger protection.
# =============================================================================


User-agent: *
Disallow: /
Noindex: /
