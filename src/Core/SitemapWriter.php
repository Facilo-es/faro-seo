<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Writes sitemap index and child documents, valid against sitemaps.org.
 */
final class SitemapWriter
{
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const XHTML = 'http://www.w3.org/1999/xhtml';
    public const FREQS = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public static function freq(string $f): string
    {
        $f = strtolower(trim($f));

        return in_array($f, self::FREQS, true) ? $f : 'weekly';
    }

    public static function priority(mixed $p): float
    {
        $p = is_numeric($p) ? (float)$p : 0.5;

        return round(max(0.0, min(1.0, $p)), 1);
    }

    /**
     * How many child files a source needs.
     */
    public static function pages(int $count, int $limit): int
    {
        return $count <= 0 ? 0 : (int)ceil($count / max(1, $limit));
    }

    /**
     * @param list<array{loc: string, lastmod?: ?\DateTimeInterface}> $children
     */
    public static function index(array $children): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
        foreach ($children as $c) {
            $x .= '<sitemap><loc>' . self::e($c['loc']) . '</loc>';
            if (($c['lastmod'] ?? null) instanceof \DateTimeInterface) {
                $x .= '<lastmod>' . $c['lastmod']->format(DATE_W3C) . '</lastmod>';
            }
            $x .= "</sitemap>\n";
        }

        return $x . '</sitemapindex>';
    }

    /**
     * @param iterable<SitemapUrl> $urls
     */
    public static function urlset(iterable $urls, bool $alternates = true): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="' . self::NS . '"'
            . ($alternates ? ' xmlns:xhtml="' . self::XHTML . '"' : '') . ">\n";
        foreach ($urls as $u) {
            $x .= '<url><loc>' . self::e($u->loc) . '</loc>';
            if ($u->lastmod !== null) {
                $x .= '<lastmod>' . $u->lastmod->format(DATE_W3C) . '</lastmod>';
            }
            if ($u->changefreq !== null) {
                $x .= '<changefreq>' . self::freq($u->changefreq) . '</changefreq>';
            }
            if ($u->priority !== null) {
                $x .= '<priority>' . number_format(self::priority($u->priority), 1, '.', '') . '</priority>';
            }
            if ($alternates && count($u->alternates) > 1) {
                foreach ($u->alternates as $lang => $href) {
                    $x .= '<xhtml:link rel="alternate" hreflang="' . self::e((string)$lang) . '" href="' . self::e($href) . '"/>';
                }
            }
            $x .= "</url>\n";
        }

        return $x . '</urlset>';
    }

    /**
     * Whether a page belongs in the sitemap: not noindex, and no canonical elsewhere.
     */
    public static function include(Meta|SeoValue $meta, string $ownUrl): bool
    {
        $robots = $meta->robots;
        if (in_array('noindex', $robots, true) || in_array('none', $robots, true)) {
            return false;
        }
        $canonical = $meta->canonical;

        return $canonical === '' || self::sameUrl($canonical, $ownUrl);
    }

    public static function sameUrl(string $a, string $b): bool
    {
        $n = static fn(string $u) => rtrim(strtolower((string)preg_replace('~^https?://~i', '', trim($u))), '/');

        return $n($a) === $n($b) || (!Paths::isAbsolute($a) && Paths::normalize($a) === Paths::normalize((string)parse_url($b, PHP_URL_PATH)));
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
