<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Indexing safety: the non-production header and robots.txt.
 */
final class Indexing
{
    public const NON_PRODUCTION_HEADER = 'none, noimageindex';

    /**
     * @param list<string>|string $production environment names that may be indexed
     */
    public static function blocksIndexing(?string $environment, array|string $production): bool
    {
        if (is_string($production)) {
            $production = preg_split('~\s*,\s*~', trim($production)) ?: [];
        }
        $production = array_map('strtolower', array_filter(array_map('trim', $production)));
        if ($production === []) {
            $production = ['production'];
        }

        return !in_array(strtolower(trim((string)$environment)), $production, true);
    }

    public static function defaultRobotsTxt(string $sitemapUrl, bool $blocked = false): string
    {
        if ($blocked) {
            return "User-agent: *\nDisallow: /\n";
        }

        return "User-agent: *\nDisallow: /cpresources/\nDisallow: /vendor/\nDisallow: /.env\nDisallow: /cache/\n\nSitemap: " . $sitemapUrl . "\n";
    }

    /**
     * Fills the tokens Faro allows in robots.txt: {sitemapUrl} and {siteUrl}.
     */
    public static function robotsTxt(string $template, string $sitemapUrl, string $siteUrl): string
    {
        return strtr($template, ['{sitemapUrl}' => $sitemapUrl, '{siteUrl}' => rtrim($siteUrl, '/') . '/']);
    }

    /**
     * The canonical as an HTTP Link header value.
     */
    public static function linkHeader(string $canonical): string
    {
        return '<' . str_replace(['>', "\r", "\n"], ['%3E', '', ''], $canonical) . '>; rel="canonical"';
    }
}
