<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * What a redirect lookup found: where to send the visitor, and which rule did it.
 */
final class RedirectMatch
{
    public function __construct(
        public readonly Redirect $redirect,
        public readonly string $destination,
        public readonly int $statusCode,
    ) {
    }

    public function isAbsolute(): bool
    {
        return Paths::isAbsolute($this->destination);
    }
}
