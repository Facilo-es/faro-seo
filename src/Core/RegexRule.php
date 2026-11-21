<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Turns a regex redirect source into a PCRE pattern, the way Ether SEO did,
 * so moved rules match the same paths. `blog/([0-9]{4})/(.*)` becomes
 * `/^blog\/([0-9]{4})\/(.*)$/i`; a source written as `/.../flags` is kept.
 */
final class RegexRule
{
    /** Backtracking limit while a redirect pattern runs. */
    public const BACKTRACK_LIMIT = 100000;

    public static function compile(string $source): ?string
    {
        $uri = $source;
        if (preg_match('/\/(.*)\/([g|m|i|x|X|s|u|U|A|J|D]+)/m', $uri) === 0) {
            $uri = self::escapeOutsideGroups($uri, '?');
            $uri = self::escapeOutsideGroups($uri, '/');
        }
        if (!self::valid($uri)) {
            $uri = '/^' . $uri . '$/i';
        }

        return self::valid($uri) ? $uri : null;
    }

    public static function valid(string $pattern): bool
    {
        set_error_handler(static fn() => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs a pattern with the backtracking limit. Null means it hit the limit
     * or failed, which callers treat as "no match".
     *
     * @param array<int|string, string> $groups
     */
    public static function match(string $pattern, string $subject, ?array &$groups = null): ?bool
    {
        $old = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string)self::BACKTRACK_LIMIT);
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $r = @preg_match($pattern, $subject, $groups);
        } finally {
            ini_set('pcre.backtrack_limit', (string)$old);
            ini_set('pcre.jit', (string)$jit);
        }

        return $r === false ? null : $r === 1;
    }

    public static function replace(string $pattern, string $replacement, string $subject): ?string
    {
        $old = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string)self::BACKTRACK_LIMIT);
        try {
            return @preg_replace($pattern, $replacement, $subject);
        } finally {
            ini_set('pcre.backtrack_limit', (string)$old);
        }
    }

    /**
     * Looks like a regex: Ether moved these as regex rules, the rest as exact.
     */
    public static function looksLikeRegex(string $source): bool
    {
        return preg_match('~[()\[\]*+?{}^$|\\\\]~', $source) === 1;
    }

    private static function escapeOutsideGroups(string $uri, string $char): string
    {
        $q = preg_quote($char, '/');
        $n = preg_match_all('/(?<!\\\\)' . $q . '(?![^(]*\))/', $uri, $m, PREG_OFFSET_CAPTURE);
        while ($n--) {
            $uri = substr_replace($uri, '\\' . $char, $m[0][$n][1], 1);
        }

        return $uri;
    }
}
