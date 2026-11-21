<?php

namespace facilo\faro\Craft;

use craft\helpers\UrlHelper;
use craft\models\Site;

/**
 * A site's root URL, always absolute and without a trailing slash.
 * A site's base URL may be relative ("/" or "@web"), so Craft fills in the request's host, as Ether did.
 */
final class Urls
{
    public static function root(Site $site): string
    {
        return rtrim(UrlHelper::siteUrl('', null, null, (int)$site->id), '/');
    }
}
