<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Path helpers shared by redirects, 404s and suggestions.
 */
final class Paths
{
    /**
     * A site-relative path without leading or trailing slashes, query kept.
     */
    public static function normalize(string $uri): string
    {
        $uri = trim($uri);
        if (preg_match('~^[a-z][a-z0-9+.-]*://[^/]*(.*)$~i', $uri, $m)) {
            $uri = $m[1];
        }
        $query = '';
        if (($q = strpos($uri, '?')) !== false) {
            $query = substr($uri, $q);
            $uri = substr($uri, 0, $q);
        }
        $uri = preg_replace('~/{2,}~', '/', $uri) ?? $uri;

        return trim($uri, '/') . ($query === '?' ? '' : $query);
    }

    /**
     * @return array{0: string, 1: string} path and query (without "?")
     */
    public static function split(string $uri): array
    {
        $uri = self::normalize($uri);
        $q = strpos($uri, '?');

        return $q === false ? [$uri, ''] : [substr($uri, 0, $q), substr($uri, $q + 1)];
    }

    /**
     * Drops the query string from a URL or path, for stored referrers.
     */
    public static function withoutQuery(string $url): string
    {
        $url = preg_replace('~[?#].*$~s', '', trim($url)) ?? '';

        return mb_substr($url, 0, 255);
    }

    public static function isAbsolute(string $url): bool
    {
        return str_contains($url, '://') || str_starts_with($url, '//');
    }

    public static function lastSegment(string $path): string
    {
        $path = self::split($path)[0];
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /**
     * Lower-case words of a path, split on separators.
     *
     * @return list<string>
     */
    public static function words(string $path): array
    {
        $parts = preg_split('~[^\pL\pN]+~u', mb_strtolower(self::split($path)[0])) ?: [];

        return array_values(array_filter($parts, static fn(string $w) => $w !== ''));
    }
}
