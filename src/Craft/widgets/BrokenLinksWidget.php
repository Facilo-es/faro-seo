<?php

namespace facilo\faro\Craft\widgets;

use Craft;
use craft\base\Widget;
use facilo\faro\Plugin;

/** Dashboard: "Broken links this week" (F11, Pro). */
class BrokenLinksWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('faro-seo', 'Broken links this week');
    }

    public static function icon(): ?string
    {
        return 'link-slash';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('faro-seo:broken-links');
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();
        $pro = $plugin->proActive();
        return Craft::$app->getView()->renderTemplate('faro-seo/_widget/broken-links', [
            'pro' => $pro,
            'rows' => $pro ? $plugin->notFound->topThisWeek() : [],
            'count' => $pro ? $plugin->notFound->countThisWeek() : 0,
        ]);
    }
}
