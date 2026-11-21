<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * One redirect rule, in Faro's own terms.
 */
final class Redirect
{
    public const EXACT = 'exact';
    public const PREFIX = 'prefix';
    public const REGEX = 'regex';
    public const MATCH_TYPES = [self::EXACT, self::PREFIX, self::REGEX];

    public const QUERY_KEEP = 'keep';
    public const QUERY_DROP = 'drop';
    public const QUERY_MATCH = 'match';
    public const QUERY_MODES = [self::QUERY_KEEP, self::QUERY_DROP, self::QUERY_MATCH];

    public const FREE_CODES = [301, 302];
    public const PRO_CODES = [307, 308, 410];

    public readonly string $source;
    public readonly string $destination;

    public function __construct(
        string $source,
        string $destination,
        public readonly string $matchType = self::EXACT,
        public readonly int $statusCode = 301,
        public readonly ?int $siteId = null,
        public readonly string $queryMode = self::QUERY_KEEP,
        public readonly ?int $id = null,
        public readonly bool $enabled = true,
        public readonly int $sortOrder = 0,
    ) {
        $this->source = $matchType === self::REGEX ? trim($source) : Paths::normalize($source);
        $this->destination = trim($destination);
    }

    public function isGone(): bool
    {
        return $this->statusCode === 410;
    }
}
