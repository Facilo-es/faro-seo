<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Walks the layers and builds the page's meta.
 */
final class MetaResolver
{
    public const DESCRIPTION_MAX = 160;

    /**
     * @param list<MetaInput> $layers in order: override, field, section map, field defaults, site defaults
     * @param string $elementTitle the element's own title, for {title} and as the last fallback
     * @param string $url the page's own URL, the default canonical
     */
    public static function resolve(
        array $layers,
        string $elementTitle,
        string $siteName,
        string $url,
        ?\DateTimeImmutable $expiry = null,
        ?TitleFormat $siteFormat = null,
    ): Meta {
        $title = self::first($layers, static fn(MetaInput $l) => $l->title);
        $title = $title === '' ? $elementTitle : TitleFormat::tokens($title, $elementTitle, $siteName);
        $description = TitleFormat::tokens(self::first($layers, static fn(MetaInput $l) => $l->description), $elementTitle, $siteName);
        $description = Text::cut($description, self::DESCRIPTION_MAX);
        $imageId = null;
        foreach ($layers as $l) {
            if ($l->imageId !== null) {
                $imageId = $l->imageId;
                break;
            }
        }
        $format = $siteFormat ?? new TitleFormat();
        foreach ($layers as $l) {
            if ($l->titleFormat !== null) {
                $format = $l->titleFormat;
                break;
            }
        }
        $full = $format->format($title, $siteName);

        $social = SeoValue::emptySocial();
        foreach (SeoValue::NETWORKS as $n) {
            $t = self::first($layers, static fn(MetaInput $l) => SeoValue::str($l->social[$n]['title'] ?? ''));
            $d = self::first($layers, static fn(MetaInput $l) => SeoValue::str($l->social[$n]['description'] ?? ''));
            $img = null;
            foreach ($layers as $l) {
                $img = SeoValue::id($l->social[$n]['imageId'] ?? null);
                if ($img !== null) {
                    break;
                }
            }
            $social[$n] = [
                'title' => $t === '' ? $full : TitleFormat::tokens($t, $elementTitle, $siteName),
                'description' => $d === '' ? $description : Text::cut(TitleFormat::tokens($d, $elementTitle, $siteName), 300),
                'imageId' => $img ?? $imageId,
            ];
        }

        $robots = [];
        foreach ($layers as $l) {
            if ($l->robots !== null) {
                $robots = $l->robots;
                break;
            }
        }
        $canonical = self::first($layers, static fn(MetaInput $l) => $l->canonical);

        return new Meta($full, $title, $description, $imageId, $social, $robots, $canonical === '' ? $url : self::absolute($canonical, $url), $expiry);
    }

    /** A canonical typed as a path ("/blog/guide") gets the page's own scheme and host. */
    private static function absolute(string $canonical, string $url): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $canonical) || str_starts_with($canonical, '//')
            || !preg_match('~^([a-z][a-z0-9+.-]*://[^/?#]+)~i', $url, $m)) {
            return $canonical;
        }

        return $m[1] . '/' . ltrim($canonical, '/');
    }

    /**
     * @param list<MetaInput> $layers
     * @param callable(MetaInput): string $pick
     */
    private static function first(array $layers, callable $pick): string
    {
        foreach ($layers as $l) {
            $v = trim($pick($l));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
