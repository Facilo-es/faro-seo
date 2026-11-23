# Release Notes for Faro SEO

## 1.0.0 - 2026-11-23

### Added
- SEO field with a live Google preview, length bars, a focus keyword and simple checks with a fix for each.
- Share images and text for Facebook, X and LinkedIn, with previews.
- Titles and descriptions that fall back to your own fields, so every page has them.
- XML sitemaps, robots.txt, canonical URLs, robots settings per page and language alternates.
- Structured data for the site, the organisation and breadcrumbs, and `craft.schema` for your own.
- Redirects per site: exact, starts with, or pattern, with 301, 302, 307, 308 and 410, CSV import and export.
- Staging sites tell search engines to stay away, and the control panel shows a warning.
- Move from Ether SEO: every SEO field value, setting, redirect and sitemap setting in one go, with a dry run and a report. The old `craft.seo` calls and `{% hook 'seo' %}` keep working.
- Optional IndexNow.
- A warning when the web root has its own robots.txt file, which hides the one Faro writes.
- GraphQL `faroMeta` query.
- Pro: broken links with suggested fixes, from real visits, with no IP address stored.
- Pro: bots kept in their own tab.
- Pro: redirect health, with chains fixed in one click, loops stopped, and broken or unused redirects listed.
- Pro: import from Retour, Sprout Redirects and Redirect Manager, and a redirect when a page's address changes.
- English and Spanish.
- Imports and the move from Ether are for admins only, and CSV files are capped at 10 MB.
- Running the move from Ether a second time keeps the Facebook App ID, X handle and robots.txt it moved the first time.
