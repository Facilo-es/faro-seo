<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Reads SEO by Ether Creative's stored values, field settings, redirects,
 * sitemap rows and plugin settings into Faro's own shapes.
 */
final class EtherMapping
{
    /** Ether token text Faro converts; anything else in braces is kept as text and reported. */
    public const KNOWN_TOKENS = [
        '{{ siteName }}' => '{siteName}',
        '{{siteName}}' => '{siteName}',
        '{siteName}' => '{siteName}',
        '{title}' => '{title}',
        '{{ title }}' => '{title}',
        '{{title}}' => '{title}',
        '{{ object.title }}' => '{title}',
        '{{object.title}}' => '{title}',
    ];

    public const SITEMAP_GROUPS = ['sections' => 'section', 'categories' => 'categoryGroup', 'productTypes' => 'productType'];

    /**
     * @param array<string, mixed> $v
     */
    public static function isEtherShape(array $v): bool
    {
        if (array_key_exists('v', $v)) {
            return false;
        }
        // Faro's own social keys mean a Faro value set from code, even without the version key.
        if (is_array($v['social'] ?? null) && (isset($v['social']['og']) || isset($v['social']['x']))) {
            return false;
        }
        foreach (['titleRaw', 'descriptionRaw', 'advanced', 'keyword'] as $k) {
            if (array_key_exists($k, $v)) {
                return true;
            }
        }
        $social = $v['social'] ?? null;

        return is_array($social) && (isset($social['facebook']) || isset($social['twitter']))
            || array_key_exists('title', $v) || array_key_exists('description', $v);
    }

    /**
     * One Ether field value in Faro's shape.
     *
     * @param array<string, mixed> $v
     * @param array<int|string, array<string, mixed>> $tokens the field's title tokens
     */
    public static function value(array $v, array $tokens = []): SeoValue
    {
        $check = [];
        $title = self::title($v, $tokens);
        $title = self::convert($title, $check);
        $description = SeoValue::str($v['descriptionRaw'] ?? $v['description'] ?? '');
        $description = self::convert($description, $check);

        $keywords = SeoValue::keywords($v['keywords'] ?? []);
        if ($keywords === [] && !Text::isBlank($v['keyword'] ?? null)) {
            $keywords = [SeoValue::str($v['keyword'])];
        }

        $social = SeoValue::emptySocial();
        $map = ['facebook' => 'og', 'twitter' => 'x'];
        foreach ($map as $from => $to) {
            $s = is_array($v['social'][$from] ?? null) ? $v['social'][$from] : [];
            $social[$to] = [
                'title' => self::convert(SeoValue::str($s['title'] ?? ''), $check),
                'description' => self::convert(SeoValue::str($s['description'] ?? ''), $check),
                'imageId' => SeoValue::id($s['imageId'] ?? $s['image'] ?? null),
            ];
        }

        $adv = is_array($v['advanced'] ?? null) ? $v['advanced'] : [];

        return new SeoValue(
            $title,
            $description,
            $keywords,
            $social,
            SeoValue::robots($adv['robots'] ?? []),
            SeoValue::str($adv['canonical'] ?? ''),
            true,
            array_values(array_unique($check)),
        );
    }

    /**
     * The user's part of an Ether title: the unlocked tokens joined. Empty when
     * it equals the field's default, so the default (and later changes to it) apply.
     *
     * @param array<string, mixed> $v
     * @param array<int|string, array<string, mixed>> $tokens
     */
    public static function title(array $v, array $tokens): string
    {
        $raw = $v['titleRaw'] ?? $v['title'] ?? [];
        if (is_array($raw) && array_key_exists(0, $raw) && is_array($raw[0])) {
            $raw = $raw[0];
        }
        if (is_string($raw)) {
            return trim($raw) === trim(self::defaultUnlocked($tokens)) ? '' : trim($raw);
        }
        if (!is_array($raw)) {
            return '';
        }
        // Ether shows only the field's own unlocked tokens, in order; other keys are leftovers.
        $keys = [];
        foreach ($tokens as $t) {
            if (!self::isLocked($t)) {
                $keys[] = (string)($t['key'] ?? '');
            }
        }
        if ($tokens === []) {
            $keys = array_map('strval', array_keys($raw));
        }
        $parts = [];
        foreach ($keys as $key) {
            $text = $raw[$key] ?? null;
            if (is_scalar($text) && trim((string)$text) !== '') {
                $parts[] = trim((string)$text);
            }
        }
        $joined = trim(implode(' ', $parts));

        return $joined === trim(self::defaultUnlocked($tokens)) ? '' : $joined;
    }

    /**
     * The field's title tokens as a Faro title format.
     *
     * @param array<int|string, array<string, mixed>> $tokens
     */
    public static function titleFormat(array $tokens, bool $suffixAsPrefix = false): TitleFormat
    {
        $check = [];
        $before = '';
        $after = '';
        $seenUnlocked = false;
        foreach ($tokens as $t) {
            $text = (string)($t['template'] ?? '');
            if (!self::isLocked($t)) {
                $seenUnlocked = true;
                continue;
            }
            $seenUnlocked ? $after .= $text : $before .= $text;
        }
        if (!$seenUnlocked) {
            $after = $before;
            $before = '';
        }
        if ($suffixAsPrefix && $before === '') {
            [$before, $after] = [$after, ''];
        }
        $before = self::convert($before, $check);
        $after = self::convert($after, $check);
        $site = '{siteName}';
        foreach (TitleFormat::SEPARATORS as $sep) {
            if (trim($before) === '' && preg_match('~^\s*' . preg_quote($sep, '~') . '\s*\{siteName\}\s*$~u', $after)) {
                return new TitleFormat($sep, TitleFormat::AFTER);
            }
            if (trim($after) === '' && preg_match('~^\s*\{siteName\}\s*' . preg_quote($sep, '~') . '\s*$~u', $before)) {
                return new TitleFormat($sep, TitleFormat::BEFORE);
            }
        }
        if (trim($before) === '' && trim($after) === '') {
            return new TitleFormat('|', TitleFormat::NONE);
        }

        return new TitleFormat('|', TitleFormat::AFTER, null, $before . '{title}' . $after);
    }

    /**
     * Converts known Ether tokens; reports any other Twig left as plain text.
     *
     * @param list<string> $check
     */
    public static function convert(string $text, array &$check): string
    {
        $text = strtr($text, self::KNOWN_TOKENS);
        if (preg_match_all('~\{\{.*?\}\}|\{%.*?%\}|\{(?!title\}|siteName\})[a-zA-Z_][\w.]*\}~s', $text, $m)) {
            foreach ($m[0] as $left) {
                $check[] = $left;
            }
        }

        return $text;
    }

    /**
     * Ether matches the full path, site prefix included ("en/old"); Faro matches the path
     * inside the site ("old"). So a plain rule loses its site's prefix, and an all-sites
     * rule that starts with a prefix belongs to that site.
     *
     * @param array<string, mixed> $row seo_redirects row
     * @param array<int, string> $prefixes site id => path prefix, such as [2 => 'en']
     */
    public static function redirect(array $row, array $prefixes = []): Redirect
    {
        $uri = trim((string)($row['uri'] ?? ''));
        $regex = RegexRule::looksLikeRegex($uri);
        $siteId = isset($row['siteId']) && $row['siteId'] !== '' && $row['siteId'] !== null ? (int)$row['siteId'] : null;
        if (!$regex) {
            $uri = Paths::normalize($uri);
            $path = mb_strtolower(explode('?', $uri, 2)[0]);
            $candidates = $siteId !== null ? array_intersect_key($prefixes, [$siteId => true]) : $prefixes;
            uasort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($candidates as $id => $prefix) {
                $prefix = mb_strtolower(trim($prefix, '/'));
                if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                    $uri = ltrim(substr($uri, strlen($prefix)), '/');
                    $siteId = (int)$id;
                    break;
                }
            }
        }

        return new Redirect(
            $uri,
            trim((string)($row['to'] ?? '')),
            $regex ? Redirect::REGEX : Redirect::EXACT,
            (int)($row['type'] ?? 301) === 302 ? 302 : 301,
            $siteId,
            // Ether matches against the path and its query; a rule that names a query keeps that.
            str_contains($uri, $regex ? '\\?' : '?') ? Redirect::QUERY_MATCH : Redirect::QUERY_KEEP,
            null,
            true,
            (int)($row['order'] ?? 0),
        );
    }

    /**
     * Turns Ether's robots.txt Twig into Faro's text: comments go, the sitemap and site URLs
     * become {sitemapUrl} and {siteUrl}, and an "is this production" if/else keeps its
     * production side, because Faro blocks robots on other environments by itself.
     * Any other Twig is left as it is, for the scan to report.
     */
    public static function robotsTxt(string $twig): string
    {
        $txt = preg_replace('~\{#.*?#\}~s', '', $twig) ?? $twig;
        $txt = preg_replace('#\{\{\s*url\(\s*seo\.sitemapName\s*~\s*[\'"]\.xml[\'"]\s*\)\s*\}\}#', '{sitemapUrl}', $txt) ?? $txt;
        $txt = preg_replace('~\{\{\s*(siteUrl|url\(\s*[\'"]/?[\'"]\s*\))\s*\}\}/?~', '{siteUrl}', $txt) ?? $txt;
        $env = '(?:craft\.app\.config\.env|craft\.app\.env)';
        $dev = 'craft\.app\.config\.general\.devMode';
        $notLive = "~\{%-?\s*if\s+(?:$env\s*!=\s*['\"]production['\"]|$dev|$env\s*==\s*['\"](?:dev|staging|local)['\"])\s*-?%\}((?:(?!\{%).)*?)\{%-?\s*else\s*-?%\}((?:(?!\{%).)*?)\{%-?\s*endif\s*-?%\}~s";
        $txt = preg_replace($notLive, '$2', $txt) ?? $txt;
        $live = "~\{%-?\s*if\s+(?:$env\s*==\s*['\"]production['\"]|not\s+$dev)\s*-?%\}((?:(?!\{%).)*?)(?:\{%-?\s*else\s*-?%\}((?:(?!\{%).)*?))?\{%-?\s*endif\s*-?%\}~s";
        $txt = preg_replace($live, '$1', $txt) ?? $txt;
        $lines = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $txt)));
        $txt = preg_replace("~\n{3,}~", "\n\n", implode("\n", $lines)) ?? '';

        return trim($txt) . "\n";
    }

    /**
     * Splits seo_sitemap rows into Faro sources and custom URLs.
     * Ether keeps one row per group and id, for all sites; Faro keeps them per site.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $siteIds
     * @param callable(string $group, string $id): ?string $uidOf turns Ether's id into the source's UID
     * @return array{sources: list<array<string, mixed>>, urls: list<array<string, mixed>>}
     */
    public static function sitemap(array $rows, array $siteIds, callable $uidOf): array
    {
        $sources = [];
        $urls = [];
        foreach ($rows as $row) {
            $group = (string)($row['group'] ?? '');
            $base = [
                'enabled' => (bool)($row['enabled'] ?? true),
                'changefreq' => SitemapWriter::freq((string)($row['frequency'] ?? 'weekly')),
                'priority' => SitemapWriter::priority($row['priority'] ?? 0.5),
            ];
            if ($group === 'customUrls') {
                foreach ($siteIds as $siteId) {
                    $urls[] = ['siteId' => $siteId, 'url' => (string)($row['url'] ?? '')] + $base;
                }
                continue;
            }
            if (!isset(self::SITEMAP_GROUPS[$group])) {
                continue;
            }
            $uid = $uidOf($group, (string)($row['url'] ?? ''));
            if ($uid === null) {
                continue;
            }
            foreach ($siteIds as $siteId) {
                $sources[] = ['siteId' => $siteId, 'sourceType' => self::SITEMAP_GROUPS[$group], 'sourceUid' => $uid] + $base;
            }
        }

        return ['sources' => $sources, 'urls' => $urls];
    }

    /**
     * Ether plugin settings in Faro's settings keys. Items needing a look are
     * added to $check.
     *
     * @param array<string, mixed> $s
     * @param list<string> $check
     * @return array<string, mixed>
     */
    public static function settings(array $s, array &$check): array
    {
        $out = [];
        if (!Text::isBlank($s['sitemapName'] ?? null)) {
            $out['sitemapName'] = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)$s['sitemapName']) ?: 'sitemap';
        }
        if (isset($s['sitemapLimit']) && (int)$s['sitemapLimit'] > 0) {
            $out['sitemapLimit'] = min(50000, (int)$s['sitemapLimit']);
        }
        if (array_key_exists('removeAlternateUrls', $s)) {
            $out['sitemapAlternates'] = !$s['removeAlternateUrls'];
        }
        if (is_array($s['title'] ?? null)) {
            $out['titleFormat'] = self::titleFormat($s['title'])->toArray();
        }
        if (!Text::isBlank($s['description'] ?? null)) {
            $out['defaultDescription'] = self::convert((string)$s['description'], $check);
        }
        $img = SeoValue::id($s['socialImage'] ?? null);
        if ($img !== null) {
            $out['defaultImageId'] = $img;
        }
        if (!Text::isBlank($s['metaTemplate'] ?? null)) {
            $out['metaTemplate'] = (string)$s['metaTemplate'];
            $check[] = 'metaTemplate: ' . $s['metaTemplate'];
        }
        if (isset($s['robots'])) {
            $out['robots'] = SeoValue::robots($s['robots']);
        }
        if (!Text::isBlank($s['robotsTxt'] ?? null)) {
            $txt = self::robotsTxt((string)$s['robotsTxt']);
            $out['robotsTxt'] = $txt;
            if (preg_match('~\{%|\{\{~', $txt)) {
                $check[] = 'robotsTxt';
            }
        }
        foreach (['facebookAppId' => 'facebookAppId', 'twitterHandle' => 'xHandle'] as $from => $to) {
            if (!Text::isBlank($s[$from] ?? null)) {
                $out[$to] = ltrim(trim((string)$s[$from]), '@');
            }
        }

        return $out;
    }

    /**
     * Ether field settings in Faro's field settings.
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    public static function fieldSettings(array $f): array
    {
        $check = [];
        $tokens = is_array($f['title'] ?? null) ? $f['title'] : [];

        return [
            'titleFormat' => $tokens === [] ? null : self::titleFormat($tokens, (bool)($f['suffixAsPrefix'] ?? false))->toArray(),
            'defaultDescription' => self::convert(SeoValue::str($f['description'] ?? ''), $check),
            'defaultImageId' => SeoValue::id($f['socialImage'] ?? null),
            'defaultRobots' => SeoValue::robots($f['robots'] ?? []),
            'hideSocial' => (bool)($f['hideSocial'] ?? false),
            'hideAdvanced' => false,
            'etherTokens' => array_values($tokens),
        ];
    }

    /**
     * @param array<string, mixed> $t
     */
    private static function isLocked(array $t): bool
    {
        $l = $t['locked'] ?? false;

        return $l !== false && $l !== '0' && $l !== 0 && $l !== '' && $l !== null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $tokens
     */
    private static function defaultUnlocked(array $tokens): string
    {
        foreach ($tokens as $t) {
            if (!self::isLocked($t)) {
                return (string)($t['template'] ?? '');
            }
        }

        return '';
    }
}
