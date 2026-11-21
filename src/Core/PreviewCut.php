<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Where Google cuts a title or description, by pixel width. The field
 * measures in the browser with the real font; this is the same rule with
 * Arial's advance widths, used for checks and the index column.
 */
final class PreviewCut
{
    public const TITLE_PX = 580;
    public const DESCRIPTION_PX = 920;
    public const TITLE_FONT = 20;
    public const DESCRIPTION_FONT = 14;

    /** Arial advance widths in 1/1000 em. */
    private const WIDTHS = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
        ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
        'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722,
        'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
        '[' => 278, '\\' => 278, ']' => 278, '_' => 556,
        'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222,
        'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
        's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
        '|' => 260, '–' => 556, '—' => 1000, '·' => 278, '…' => 1000, '¿' => 611, '¡' => 333,
    ];

    public static function width(string $text, int $fontPx): float
    {
        $w = 0;
        foreach (mb_str_split($text) as $ch) {
            $w += self::WIDTHS[$ch] ?? self::WIDTHS[self::base($ch)] ?? 556;
        }

        return $w * $fontPx / 1000;
    }

    /**
     * The text as Google would show it, cut with "…" when too wide.
     */
    public static function cut(string $text, int $maxPx, int $fontPx): string
    {
        if (self::width($text, $fontPx) <= $maxPx) {
            return $text;
        }
        $room = $maxPx - self::width('…', $fontPx);
        $out = '';
        $w = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $cw = self::width($ch, $fontPx);
            if ($w + $cw > $room) {
                break;
            }
            $out .= $ch;
            $w += $cw;
        }
        $space = mb_strrpos($out, ' ');
        if ($space !== false && $space > mb_strlen($out) * 0.7) {
            $out = mb_substr($out, 0, $space);
        }

        return rtrim($out, ' ,;:-|–—·') . '…';
    }

    public static function title(string $text): string
    {
        return self::cut($text, self::TITLE_PX, self::TITLE_FONT);
    }

    public static function description(string $text): string
    {
        return self::cut($text, self::DESCRIPTION_PX, self::DESCRIPTION_FONT);
    }

    /**
     * green, amber or red for the length bar.
     */
    public static function band(string $text, int $maxPx, int $fontPx): string
    {
        if (trim($text) === '') {
            return 'red';
        }
        $r = self::width($text, $fontPx) / $maxPx;

        return $r > 1 ? 'red' : ($r > 0.9 || $r < 0.4 ? 'amber' : 'green');
    }

    private static function base(string $ch): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($ch, \Normalizer::FORM_D);
            if (is_string($n) && $n !== '') {
                return mb_substr($n, 0, 1);
            }
        }

        return $ch;
    }
}
