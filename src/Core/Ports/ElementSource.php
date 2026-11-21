<?php

declare(strict_types=1);

namespace facilo\faro\Core\Ports;

interface ElementSource
{
    /**
     * Live URIs on a site, for 404 suggestions: uri => element id.
     *
     * @return array<string, int>
     */
    public function liveUris(int $siteId): array;

    /**
     * Earlier URIs of elements on a site: old uri => current uri.
     *
     * @return array<string, string>
     */
    public function earlierUris(int $siteId): array;
}
