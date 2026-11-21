# Security policy

## Report a problem privately

Please don't open a public issue for a security problem.

- Use GitHub's **private vulnerability reporting**: on the repository, open the **Security** tab and choose **Report a vulnerability**.
- Or email **info@facilo.es** with "Security" in the subject.

Tell us what you found, which version, and how to repeat it. Never send real customer data. Test data is enough.

We read every report and answer as soon as we can. We don't promise a response time. If a report suggests a flaw is being actively exploited, we treat it as urgent.

## How fixes are released

1. We confirm the problem and prepare a fix on the `1.x` branch.
2. We release a patch version, for example 1.0.1, and describe the fix in `CHANGELOG.md`. We say who found it, if you want to be named.
3. We publish the details after a fix is available, so people have time to update.
4. Each release comes with a software bill of materials (CycloneDX).

Fixes come to you through the normal Craft update. The free edition always gets updates. A Pro site must hold a current update licence to receive updates. Licences are perpetual, so a site that stops renewing keeps what it has, but gets no updates, including security fixes.

## Support period

Version 1.x (for Craft 5) gets security fixes for 5 years from the release of 1.0.0, or for as long as Craft 5 is supported, whichever ends first.

When that period ends, we will say so in the changelog. Only the latest 1.x release is supported.

A Craft 6 version (2.x) is planned, and the stable one follows Craft 6.0. Its betas will get fixes in the next beta, not patch releases.

## How Faro protects your site

- Every title, description and share value is escaped before it goes into your pages. Structured data is written so a value can't end its script tag.
- The 404 log keeps the address, the referrer without its query string, a hit count and dates. It never stores IP addresses or user agents, keeps at most 10,000 rows and deletes rows older than 13 months.
- Pattern (regex) redirects run with a backtracking limit, so a slow pattern can't hang a request. A pattern that hits the limit counts as no match.
- Redirects are checked only when Craft would show a 404, so they never change a page that exists.
- Every control panel screen needs its own permission (redirects, broken links, sitemap). Settings need an admin. The move from Ether SEO needs access to its utility.
- CSV import is limited to 10 MB.
- The only public addresses Faro adds are the sitemaps, robots.txt and the IndexNow key file. IndexNow is off until you turn it on, and it only sends your changed page addresses to the shared IndexNow service (api.indexnow.org), which passes them to Bing and other search engines that take part.
- Faro loads no third-party script and sets no cookies on your site.

## Scope

In scope: the Faro SEO plugin code in this repository.

Out of scope: Craft CMS itself, search engines, other SEO plugins, your server, and third-party libraries (report those to their authors, but tell us too if they affect Faro SEO).

## No guarantee

We build Faro SEO with care, but we can't promise it is free of flaws. The software is provided as is, under the terms in `LICENSE.md`.
