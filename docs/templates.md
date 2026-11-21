# Templates

## Nothing to add

Faro SEO writes its tags through Craft's `{{ head() }}`, which every front-end template that uses `{% extends %}` with `<head>` already calls. Install it and the tags are there.

## Where you want control

Put the tags exactly where you want them with the hook. When the hook runs, the automatic output is skipped, so tags never appear twice.

```twig
<head>
    {% hook 'faro' %}
</head>
```

## Changing one page from its template

```twig
{% do craft.faro.set({
    title: 'Opening hours',
    description: 'When we are open this week.',
    image: entry.heroImage.one(),
}) %}
```

A value set in the template wins over the page's SEO field, then the section's fallbacks, then the field's defaults, then the site's defaults.

## Reading the values

```twig
{% set meta = craft.faro.meta %}
<p>{{ meta.title }}</p>
```

`craft.faro.tags` returns the tags as markup, if you write your own `<head>`.

## Structured data

Faro adds WebSite, Organization and BreadcrumbList JSON-LD by default (Settings → Social). Add any other type with Spatie's schema builder:

```twig
{% set business = craft.schema.localBusiness
    .name('Tienda Faro')
    .telephone('+34 900 000 000') %}
{% do craft.faro.schema(business.toArray()) %}
```

`craft.schema` is Faro's only while SEOMate isn't installed; with SEOMate, its own `craft.schema` is used.

## Ether SEO templates

When Ether SEO is uninstalled, Faro answers its calls, so old templates keep working:

- `{% hook 'seo' %}` outputs Faro's tags. If the template set a variable `seo`, its values are used.
- `craft.seo.custom(title, description, image, social)` works with the same arguments.
- `getSeoField('handle')` returns the Faro field value, with `title`, `description`, `social.twitter`, `social.facebook`, `robots`, `canonical` and `expiry`.

## GraphQL

Each SEO field adds its value to the schema with the same shape Ether had (title, description, keywords, social, advanced), and the `faroMeta(uri: "…", site: "…")` query returns the final tags of any page, for headless sites.
