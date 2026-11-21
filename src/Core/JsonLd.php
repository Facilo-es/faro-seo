<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * The default structured data: WebSite, Organization and BreadcrumbList.
 */
final class JsonLd
{
    /**
     * @return array<string, mixed>
     */
    public static function website(string $name, string $url, string $language = ''): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $url,
            'inLanguage' => $language,
        ], static fn($v) => $v !== '');
    }

    /**
     * @param list<string> $sameAs
     * @return array<string, mixed>
     */
    public static function organization(string $name, string $url, string $logo = '', array $sameAs = []): array
    {
        $o = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $name, 'url' => $url];
        if ($logo !== '') {
            $o['logo'] = $logo;
        }
        $sameAs = array_values(array_filter($sameAs, static fn($s) => is_string($s) && preg_match('~^https?://~', $s) === 1));
        if ($sameAs !== []) {
            $o['sameAs'] = $sameAs;
        }

        return $o;
    }

    /**
     * Breadcrumbs from the URL path. $names maps a path (without slashes) to a
     * known page title; unknown steps get a title from their slug.
     *
     * @param array<string, string> $names
     * @return array<string, mixed>|null
     */
    public static function breadcrumbs(string $siteUrl, string $siteName, string $path, array $names = []): ?array
    {
        $path = Paths::split($path)[0];
        if ($path === '') {
            return null;
        }
        $base = rtrim($siteUrl, '/');
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $base . '/']];
        $acc = '';
        foreach (explode('/', $path) as $i => $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $name = $names[$acc] ?? ucfirst(str_replace(['-', '_'], ' ', rawurldecode($seg)));
            $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $name, 'item' => $base . '/' . $acc];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
}
