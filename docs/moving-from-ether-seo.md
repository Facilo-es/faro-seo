# Moving from Ether SEO

Faro SEO moves everything Ether's "SEO for Craft" plugin stored, and answers Ether's template calls once Ether is gone. Tested with Ether SEO 5.0.0 on Craft 5.

## The three steps

1. **Back up your database.** The backup is the undo: the move changes your SEO fields and adds Faro's tables, and there is no button to reverse it. Then install Faro SEO next to Ether SEO. Leave Ether installed.
2. Open **Utilities → Move from Ether SEO**. The report lists what will move, what to check and anything that won't move. Reading it writes nothing. Click **Move**. A progress bar follows the queue job.
3. When the finished screen shows the counts, uninstall Ether SEO in **Settings → Plugins**. Your templates keep working.

## What moves

- **SEO fields.** Each Ether SEO field becomes a Faro SEO field with the same handle, in every field layout, including fields inside Matrix and other nested entries. The title format, locked title parts, default description and share image become the field's defaults.
- **Page values.** On every site: title, description, keywords (shown as the focus keywords), Facebook and X titles, descriptions and images, robots switches, canonical URL.
- **Redirects.** Every redirect, in the same order, per site or for all sites. Ether's patterns are converted to Faro's pattern redirects and give the same destination for the same address. 301 and 302 keep their codes.
- **Sitemap.** Which sections, category groups and product types are listed, with their change frequency and priority, and every custom URL.
- **Settings.** robots.txt, the default robots, Facebook App ID, X handle, the site-wide share image and the sitemap name.

## What to check

- **Twig in your content.** Ether accepted Twig such as `{{ object.title }}` in titles. Faro turns the known tokens into its own and keeps anything else as plain text, listed in the report.
- **A custom meta template.** If Ether used your own meta template, Faro keeps using it and flags it, because it may read properties only Ether had.
- **Template calls.** The report lists every template line that calls Ether (`{% hook 'seo' %}`, `craft.seo.custom()`, `getSeoField()`). They keep working; the list is there so you know where they are.

## Differences you may see

- Share titles fall back to the page title, as Ether did. Pages where Ether found no SEO field (for example a field with another handle) now get their own title and description instead of the site name.
- `twitter:card` is `summary_large_image` only when there is a share image.
- `og:url` follows the canonical URL.
- Faro adds language alternates (hreflang) and structured data (JSON-LD).

## Production sites with admin changes off

When `allowAdminChanges` is off, fields can't change on production. Run the move on your development copy, deploy the project config as usual, then on production run:

```
php craft faro-seo/ether/data
```

It copies the page values, redirects and sitemap rows that project config doesn't carry. Run `php craft faro-seo/ether/scan` first to read the report in the terminal. Running the move or the data command twice changes nothing the second time.
