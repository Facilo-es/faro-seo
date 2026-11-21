<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * One URL in a sitemap.
 */
final class SitemapUrl
{
    /**
     * @param array<string, string> $alternates language tag => URL
     */
    public function __construct(
        public readonly string $loc,
        public readonly ?\DateTimeInterface $lastmod = null,
        public readonly ?string $changefreq = null,
        public readonly ?float $priority = null,
        public readonly array $alternates = [],
    ) {
    }
}
