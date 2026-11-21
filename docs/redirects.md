# Redirects

Open **Faro SEO → Redirects**. Each redirect sends visitors from an old address to a new one, and counts every visit it sends ("hits"), so the overview's "Visitors sent to the right page" is a real count.

## Adding one

- **From**: the old address, such as `/old-page`.
- **Match**: *Exactly* the address, an address that *Starts with* it, or a *Pattern (regex)*. In a pattern, `$1`, `$2`… in **To** take the matching parts: from `^/blog/(\d+)/(.*)$` to `/news/$2`.
- **To**: the new address, on this site (`/new-page`) or another (`https://…`).
- **Type**: 301 for a page that moved for good, 302 for a temporary one (307 and 308 keep the request method), or 410 for a page that is gone for good.
- **Query string**: pass it on to the new address, drop it, or match it as part of the address.
- **Site**: one site, or all sites.

Redirects are checked in order, and the first that matches wins. Drag rows to change the order. A redirect only runs when Craft would otherwise show a 404, so it never hides a live page.

## Importing and exporting

**Import** reads a CSV file with the columns `source, destination, matchType, statusCode, queryMode, enabled`. **Export** writes the same columns plus `hits`, so a file from one site imports into another.

With Pro, redirects from Retour, Sprout Redirects or Redirect Manager import in one click when their tables are in the database, and changing a page's address adds a redirect from the old one.

## Redirect health (Pro)

Chains (A to B to C) are listed with a one-click fix that points A straight at C. Loops are stopped and listed. Redirects that lead to a missing page, and ones nobody has used in 12 months, are listed for you to check.

## From Ether SEO

Ether's redirects move with everything else, in the same order and with the same results. See [Moving from Ether SEO](moving-from-ether-seo.md).
