<?php

declare(strict_types=1);

namespace facilo\faro\Core\Ports;

use facilo\faro\Core\SitemapUrl;

interface SitemapSource
{
    public function count(): int;

    /**
     * @return iterable<SitemapUrl>
     */
    public function page(int $offset, int $limit): iterable;
}
