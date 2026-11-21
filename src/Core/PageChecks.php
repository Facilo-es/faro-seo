<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Page checks for the field: they count, match words and look at structure,
 * so they work in any language and never judge grammar.
 */
final class PageChecks
{
    public const GOOD = 'good';
    public const FAIR = 'fair';
    public const POOR = 'poor';

    public const TITLE_MIN = 30;
    public const TITLE_MAX = 60;
    public const DESC_MIN = 70;
    public const DESC_MAX = 160;
    public const WORDS_MIN = 300;

    /** Used only when the intl extension is missing. */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * @param list<string> $keywords
     * @param string $html the rendered page (body)
     * @param string $siteHost the site's host, to tell internal links
     * @return array{score: string, checks: list<array{id: string, status: string, value?: int|string}>}
     */
    public static function run(array $keywords, string $title, string $description, string $uri, string $html, string $siteHost = ''): array
    {
        $checks = [];
        $html = (string)preg_replace('~<(script|style|noscript|template)\b[^>]*>.*?</\1>~is', ' ', $html);
        $text = Text::plain($html);
        $words = self::wordCount($text);
        $kws = array_values(array_filter(array_map([self::class, 'fold'], $keywords), static fn($k) => $k !== ''));

        if ($kws !== []) {
            $checks[] = ['id' => 'keywordInTitle', 'status' => self::any($kws, $title) ? self::GOOD : self::POOR];
            $checks[] = ['id' => 'keywordInDescription', 'status' => self::any($kws, $description) ? self::GOOD : self::POOR];
            $slugWords = implode(' ', Paths::words($uri));
            $checks[] = ['id' => 'keywordInUrl', 'status' => self::any(array_map(static fn($k) => str_replace('-', ' ', $k), $kws), $slugWords) ? self::GOOD : self::FAIR];
            $checks[] = ['id' => 'keywordInFirstParagraph', 'status' => self::any($kws, self::firstParagraph($html)) ? self::GOOD : self::FAIR];
            $checks[] = ['id' => 'keywordInHeading', 'status' => self::any($kws, self::headings($html)) ? self::GOOD : self::FAIR];
        }

        $tl = mb_strlen($title);
        $checks[] = ['id' => 'titleLength', 'value' => $tl, 'status' => $tl === 0 || $tl > self::TITLE_MAX + 10 ? self::POOR : ($tl < self::TITLE_MIN || $tl > self::TITLE_MAX ? self::FAIR : self::GOOD)];
        $dl = mb_strlen($description);
        $checks[] = ['id' => 'descriptionLength', 'value' => $dl, 'status' => $dl === 0 || $dl > self::DESC_MAX + 20 ? self::POOR : ($dl < self::DESC_MIN || $dl > self::DESC_MAX ? self::FAIR : self::GOOD)];

        $noAlt = self::imagesWithoutAlt($html);
        $checks[] = ['id' => 'imageAlt', 'value' => $noAlt, 'status' => $noAlt === 0 ? self::GOOD : self::FAIR];
        $checks[] = ['id' => 'internalLink', 'value' => self::internalLinks($html, $siteHost), 'status' => self::internalLinks($html, $siteHost) > 0 ? self::GOOD : self::FAIR];
        $checks[] = ['id' => 'textLength', 'value' => $words, 'status' => $words >= self::WORDS_MIN ? self::GOOD : ($words >= self::WORDS_MIN / 2 ? self::FAIR : self::POOR)];

        return ['score' => self::score($checks), 'checks' => $checks];
    }

    /**
     * @param list<array{status: string}> $checks
     */
    public static function score(array $checks): string
    {
        $points = self::points($checks);

        return $points >= 80 ? self::GOOD : ($points >= 50 ? self::FAIR : self::POOR);
    }

    /**
     * The score as a number from 0 to 100, as the field stores it.
     *
     * @param list<array{status: string}> $checks
     */
    public static function points(array $checks): int
    {
        if ($checks === []) {
            return 50;
        }
        $points = 0;
        foreach ($checks as $c) {
            $points += ['good' => 2, 'fair' => 1, 'poor' => 0][$c['status']] ?? 0;
        }

        return (int)round(100 * $points / (2 * count($checks)));
    }

    /**
     * Lower case, accents removed, spaces collapsed: "Café  Madrid" → "cafe madrid".
     */
    public static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = is_string($n) ? (string)preg_replace('~\p{Mn}+~u', '', $n) : $s;
        } else {
            $s = strtr($s, self::ACCENTS);
        }

        return trim((string)preg_replace('~[^\pL\pN]+~u', ' ', $s));
    }

    public static function wordCount(string $text): int
    {
        return preg_match_all('~[\pL\pN]+(?:[\'’-][\pL\pN]+)*~u', $text);
    }

    /**
     * @param list<string> $kws already folded
     */
    private static function any(array $kws, string $haystack): bool
    {
        $h = ' ' . self::fold($haystack) . ' ';
        foreach ($kws as $k) {
            if (str_contains($h, ' ' . $k . ' ')) {
                return true;
            }
        }

        return false;
    }

    private static function firstParagraph(string $html): string
    {
        if (preg_match('~<p\b[^>]*>(.*?)</p>~is', $html, $m) && trim(Text::plain($m[1])) !== '') {
            return Text::plain($m[1]);
        }

        return Text::cut($html, 300);
    }

    private static function headings(string $html): string
    {
        preg_match_all('~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~is', $html, $m);

        return Text::plain(implode(' | ', $m[1]));
    }

    private static function imagesWithoutAlt(string $html): int
    {
        preg_match_all('~<img\b[^>]*>~i', $html, $m);
        $n = 0;
        foreach ($m[0] as $img) {
            if (!preg_match('~\balt\s*=\s*(["\'])\s*\S.*?\1~is', $img) && !preg_match('~\brole\s*=\s*["\']presentation~i', $img)) {
                $n++;
            }
        }

        return $n;
    }

    private static function internalLinks(string $html, string $host): int
    {
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m);
        $n = 0;
        foreach ($m[2] as $href) {
            $href = trim($href);
            if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto|tel|javascript):~i', $href)) {
                continue;
            }
            $h = parse_url($href, PHP_URL_HOST);
            if ($h === null || $h === false || ($host !== '' && strcasecmp((string)$h, $host) === 0)) {
                $n++;
            }
        }

        return $n;
    }
}
