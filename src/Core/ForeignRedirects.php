<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Turns rows from other redirect plugins' tables into Faro redirects (F13).
 * Each plugin stores the same idea with its own column names; this is the map.
 */
final class ForeignRedirects
{
    public const RETOUR = 'retour';
    public const SPROUT = 'sprout';
    public const DOLPHIQ = 'dolphiq';
    public const SOURCES = [self::RETOUR, self::SPROUT, self::DOLPHIQ];

    /**
     * @param array<string, mixed> $row
     * @param list<int> $allowedCodes
     */
    public static function fromRow(string $source, array $row, array $allowedCodes = [301, 302, 307, 308, 410]): ?Redirect
    {
        [$from, $to, $code, $regex, $siteId, $enabled] = match ($source) {
            self::RETOUR => [
                $row['redirectSrcUrl'] ?? $row['redirectSrcUrlParsed'] ?? '',
                $row['redirectDestUrl'] ?? '',
                $row['redirectHttpCode'] ?? 301,
                ($row['redirectMatchType'] ?? '') === 'regexmatch',
                $row['siteId'] ?? null,
                $row['enabled'] ?? true,
            ],
            self::SPROUT => [
                $row['oldUrl'] ?? '',
                $row['newUrl'] ?? '',
                $row['statusCode'] ?? $row['method'] ?? 301,
                in_array($row['matchStrategy'] ?? '', ['regExMatch', 'regex'], true) || !empty($row['regex']),
                $row['siteId'] ?? null,
                $row['enabled'] ?? true,
            ],
            self::DOLPHIQ => [
                $row['sourceUrl'] ?? '',
                $row['destinationUrl'] ?? '',
                $row['statusCode'] ?? 301,
                false,
                $row['siteId'] ?? null,
                $row['enabled'] ?? true,
            ],
            default => throw new \InvalidArgumentException("Unknown source $source"),
        };
        $from = trim((string)$from);
        $code = (int)$code;
        // Sprout keeps 404 rows as a log of misses, not as redirects.
        if ($from === '' || $code === 404) {
            return null;
        }
        if (!in_array($code, $allowedCodes, true)) {
            $code = 301;
        }
        if (!$regex && preg_match('~^https?://[^/]+(/.*)?$~i', $from, $m)) {
            $from = $m[1] ?? '/';
        }
        return new Redirect(
            $from,
            (string)$to,
            $regex ? Redirect::REGEX : Redirect::EXACT,
            $code,
            $siteId === null || $siteId === '' ? null : (int)$siteId,
            Redirect::QUERY_KEEP,
            null,
            (bool)$enabled,
        );
    }
}
