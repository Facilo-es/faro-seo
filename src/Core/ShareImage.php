<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * A share image after its transform.
 */
final class ShareImage
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $alt = '',
        public readonly string $mimeType = '',
    ) {
    }
}
