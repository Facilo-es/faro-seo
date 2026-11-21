<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Plain-text helpers for meta values.
 */
final class Text
{
    /**
     * Strips tags and collapses whitespace.
     */
    public static function plain(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }
        $s = (string)$value;
        $s = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $s) ?? $s;
        $s = preg_replace('~<br\s*/?>|</(p|div|li|h[1-6])>~i', ' ', $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
    }

    /**
     * Cuts at a word boundary at or below $max characters, without "…".
     */
    public static function cut(string $text, int $max): string
    {
        $text = self::plain($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);

        return rtrim($cut, " \t\n,;:-–—");
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }
}
