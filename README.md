# Faro SEO for Craft CMS

## Get found in search, and keep every old link working

**Faro SEO** gives every page on your Craft site its search title, description, share image, sitemap entry and redirects. Broken links with suggested fixes are Pro; everything else is free. It's for agencies and teams running Craft 5 sites, and for sites still on Ether's SEO plugin that need a maintained path to Craft 6.

### Move from Ether SEO in 3 steps

1. **Back up your database.** The backup is how you undo the move. Then install Faro SEO next to Ether SEO.
2. Open **Utilities → Move from Ether SEO**, read the report and click **Move**. Titles, descriptions, share images, field settings, sitemap, custom URLs and redirects all come across. Tested with Ether SEO 5.0.0.
3. Uninstall Ether SEO. Your templates keep working as they are.

### What it doesn't do

No tracking scripts, no keyword rank tracking, no AI-written text and no Craft 4. It writes standard tags; it doesn't promise rankings.

## Install

From the Craft Plugin Store in your control panel (search "Faro SEO"), or with Composer:

```
composer require facilo/faro-seo
php craft plugin/install faro-seo
```

Requires Craft 5.5 or later and PHP 8.2 or later.

## Free

- An SEO field with a live Google preview, length bars, a focus keyword and simple checks with a fix for each
- Share images sized for Facebook, X and LinkedIn, with previews
- Titles and descriptions that fall back to your own fields, so every page has them
- XML sitemaps, robots.txt, canonical URLs, robots settings per page, and language alternates
- Structured data for your site, organisation and breadcrumbs
- Redirects with patterns, per site
- Nothing indexed by accident: staging sites tell search engines to stay away, and the control panel tells you

## Pro: USD 49 per site, then USD 19 a year for updates

- **Broken links, with fixes.** See the addresses that real visitors hit and get a 404 on, where they came from, and a suggested page for each. Accept it in one click. It knows where visitors came from by the referrer their browser sends: no tracking script, no IP address stored.
- **Bots kept apart.** Probes for WordPress logins and config files go to their own tab, so the list shows what matters.
- **Healthy redirects.** Chains fixed in one click, loops stopped, redirects to missing pages and unused ones listed.
- **Bring your redirects.** Import from CSV, Retour, Sprout Redirects or Redirect Manager in one click, and get a redirect automatically when a page's address changes.

If you don't renew, Pro keeps working as it is: Craft licences are perpetual. You just stop getting updates, including security fixes, until you renew.

## Craft 6

Faro SEO 1.x runs on Craft 5. A Craft 6 version is planned, and the stable one follows Craft 6.0.

## Documentation

- [Moving from Ether SEO](docs/moving-from-ether-seo.md)
- [Templates](docs/templates.md)
- [Redirects](docs/redirects.md)
- [Privacy](docs/privacy.md)

## Also from Facilo

Moving from Ether's Maps plugin too? [Rumbo Maps](https://plugins.craftcms.com/rumbo-maps) moves your locations and keeps your map templates working.

## Support

Open an issue on GitHub or email info@facilo.es. We reply by email, in English or Spanish. Security problems: see [SECURITY.md](SECURITY.md).

Made by [Facilo](https://facilo.es/).
