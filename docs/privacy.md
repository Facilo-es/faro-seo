# Privacy

Faro SEO adds no tracking script, sets no cookie and sends nothing to Facilo.

## Broken links (Pro)

When a visitor gets a 404, Faro saves:

- the address they asked for (up to 2,000 characters),
- the page they came from, as the referrer their browser sent, without its query string,
- how many times it happened, and the first and last date,
- whether it looks like a bot probe.

It never saves an IP address, a user agent, a cookie or anything else about the visitor. Rows older than 13 months are deleted, and the log keeps at most 10,000 rows, dropping the oldest unhandled ones first.

## Redirects

A redirect counts its hits and keeps the date of the last one. Nothing about the visitor is saved.

## IndexNow

If you switch on IndexNow in **Settings → Indexing**, Faro tells search engines the address of a page when you save it. Only public page addresses are sent.

## Third parties

Faro loads nothing from other servers. Share previews in the control panel are drawn by Faro itself.
