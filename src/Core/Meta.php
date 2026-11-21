<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * The resolved meta for one page, ready to render.
 */
final class Meta
{
    /**
     * @param array{og: array{title: string, description: string, imageId: ?int}, x: array{title: string, description: string, imageId: ?int}} $social
     * @param list<string> $robots
     */
    public function __construct(
        public readonly string $title,
        public readonly string $pageTitle,
        public readonly string $description,
        public readonly ?int $imageId,
        public readonly array $social,
        public readonly array $robots,
        public readonly string $canonical,
        public readonly ?\DateTimeImmutable $expiry = null,
    ) {
    }

    public function isNoindex(): bool
    {
        return in_array('noindex', $this->robots, true) || in_array('none', $this->robots, true);
    }

    public function robotsContent(): string
    {
        return $this->robots === [] ? 'all' : implode(', ', $this->robots);
    }
}
