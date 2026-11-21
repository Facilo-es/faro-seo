<?php

namespace facilo\faro\Craft\utilities;

use Craft;
use craft\base\Utility;
use facilo\faro\Plugin;

/** Utilities → Move from Ether SEO (F14). */
class MoveFromEther extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('faro-seo', 'Move from Ether SEO');
    }

    public static function id(): string
    {
        return 'faro-move-from-ether';
    }

    public static function icon(): ?string
    {
        return 'arrow-right-arrow-left';
    }

    public static function contentHtml(): string
    {
        $mover = Plugin::getInstance()->mover;
        return Craft::$app->getView()->renderTemplate('faro-seo/_utility/move', [
            'found' => $mover->etherFound(),
            'installed' => $mover->etherInstalled(),
            'report' => $mover->lastReport(true),
            'done' => $mover->lastReport(false),
            'adminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
