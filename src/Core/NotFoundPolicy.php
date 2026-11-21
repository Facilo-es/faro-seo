<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * What the broken-links log keeps: no IP, no user agent, referrer without
 * its query, at most 10,000 rows and 13 months.
 */
final class NotFoundPolicy
{
    public const MAX_ROWS = 10000;
    public const MAX_AGE = '-13 months';
    public const URI_MAX = 2000;

    /**
     * @return array{uri: string, uriHash: string, referrer: ?string}
     */
    public static function record(string $uri, ?string $referrer): array
    {
        $uri = mb_substr(Paths::normalize($uri), 0, self::URI_MAX);
        $ref = $referrer === null || trim($referrer) === '' ? null : Paths::withoutQuery($referrer);

        return ['uri' => $uri, 'uriHash' => sha1(mb_strtolower($uri)), 'referrer' => $ref];
    }

    public static function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(self::MAX_AGE);
    }

    /**
     * How many of the oldest unhandled rows to drop to stay under the limit.
     */
    public static function overflow(int $rows): int
    {
        return max(0, $rows - self::MAX_ROWS);
    }
}
