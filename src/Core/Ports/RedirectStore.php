<?php

declare(strict_types=1);

namespace facilo\faro\Core\Ports;

use facilo\faro\Core\Redirect;

interface RedirectStore
{
    /**
     * Enabled redirects for a site (and the all-sites ones), in sort order.
     *
     * @return list<Redirect>
     */
    public function forSite(int $siteId): array;
}
