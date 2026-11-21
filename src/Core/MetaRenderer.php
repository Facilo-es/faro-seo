<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Turns resolved meta into tags. Every value is escaped; no empty tags.
 */
final class MetaRenderer
{
    /**
     * @param array{og?: ?ShareImage, x?: ?ShareImage} $images
     * @param array<string, string> $alternates language tag => URL
     * @param list<array<string, mixed>> $jsonLd
     * @return list<array{0: string, 1: array<string, string>}> tag name and attributes; 'script' carries its body in 'json'
     */
    public static function tags(
        Meta $meta,
        string $siteName,
        string $locale,
        array $images = [],
        array $alternates = [],
        ?string $xDefault = null,
        string $facebookAppId = '',
        string $xHandle = '',
        array $jsonLd = [],
    ): array {
        $t = [];
        $t[] = ['title', ['text' => $meta->title]];
        self::meta($t, 'name', 'description', $meta->description);
        if ($meta->robots !== []) {
            self::meta($t, 'name', 'robots', $meta->robotsContent());
        }
        if ($meta->expiry !== null) {
            self::meta($t, 'name', 'robots', 'unavailable_after: ' . $meta->expiry->format(DATE_RFC850));
        }
        if ($meta->canonical !== '') {
            $t[] = ['link', ['rel' => 'canonical', 'href' => $meta->canonical]];
        }
        foreach ($alternates as $lang => $href) {
            $t[] = ['link', ['rel' => 'alternate', 'hreflang' => $lang, 'href' => $href]];
        }
        if ($xDefault !== null && $alternates !== []) {
            $t[] = ['link', ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $xDefault]];
        }

        $og = $meta->social['og'];
        self::meta($t, 'property', 'og:type', 'website');
        self::meta($t, 'property', 'og:site_name', $siteName);
        self::meta($t, 'property', 'og:locale', str_replace('-', '_', $locale));
        self::meta($t, 'property', 'og:url', $meta->canonical);
        self::meta($t, 'property', 'og:title', $og['title']);
        self::meta($t, 'property', 'og:description', $og['description']);
        $img = $images['og'] ?? null;
        if ($img !== null && $img->url !== '') {
            self::meta($t, 'property', 'og:image', $img->url);
            self::meta($t, 'property', 'og:image:width', $img->width !== null ? (string)$img->width : '');
            self::meta($t, 'property', 'og:image:height', $img->height !== null ? (string)$img->height : '');
            self::meta($t, 'property', 'og:image:type', $img->mimeType);
            self::meta($t, 'property', 'og:image:alt', $img->alt);
        }
        self::meta($t, 'property', 'fb:app_id', $facebookAppId);

        $x = $meta->social['x'];
        $ximg = $images['x'] ?? null;
        self::meta($t, 'name', 'twitter:card', $ximg !== null && $ximg->url !== '' ? 'summary_large_image' : 'summary');
        self::meta($t, 'name', 'twitter:site', $xHandle === '' ? '' : '@' . ltrim($xHandle, '@'));
        self::meta($t, 'name', 'twitter:title', $x['title']);
        self::meta($t, 'name', 'twitter:description', $x['description']);
        if ($ximg !== null && $ximg->url !== '') {
            self::meta($t, 'name', 'twitter:image', $ximg->url);
            self::meta($t, 'name', 'twitter:image:alt', $ximg->alt);
        }
        foreach ($jsonLd as $graph) {
            $t[] = ['script', ['type' => 'application/ld+json', 'json' => self::json($graph)]];
        }

        return $t;
    }

    /**
     * @param list<array{0: string, 1: array<string, string>}> $tags
     */
    public static function html(array $tags): string
    {
        $out = [];
        foreach ($tags as [$name, $attrs]) {
            if ($name === 'title') {
                $out[] = '<title>' . self::e($attrs['text']) . '</title>';
                continue;
            }
            if ($name === 'script') {
                $out[] = '<script type="application/ld+json">' . $attrs['json'] . '</script>';
                continue;
            }
            $a = '';
            foreach ($attrs as $k => $v) {
                $a .= ' ' . $k . '="' . self::e($v) . '"';
            }
            $out[] = '<' . $name . $a . '>';
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        // JSON_HEX_TAG keeps "</script>" in values from ending the tag.
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param list<array{0: string, 1: array<string, string>}> $t
     */
    private static function meta(array &$t, string $attr, string $name, string $content): void
    {
        if (trim($content) !== '') {
            $t[] = ['meta', [$attr => $name, 'content' => $content]];
        }
    }
}
