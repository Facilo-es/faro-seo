<?php

namespace facilo\faro\Craft\web;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/** The Faro SEO screens: redirects, broken links, health, sitemap and settings. */
class PagesAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__, 2) . '/web/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['faro.css'];
        $this->js = ['pages.js'];
        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);
        /** @var \craft\web\View $view */
        $view->registerTranslations('faro-seo', [
            'Something went wrong. Try again.', 'Order saved.', 'Delete this redirect?',
            'Redirect deleted.', '{Page title}',
        ]);
    }
}
