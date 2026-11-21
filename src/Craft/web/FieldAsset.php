<?php

namespace facilo\faro\Craft\web;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/** The SEO field: live Google preview, length bars and checks (F1). */
class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__, 2) . '/web/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['faro.css'];
        $this->js = ['field.js'];
        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);
        /** @var \craft\web\View $view */
        $view->registerTranslations('faro-seo', [
            'Good', 'Needs work', 'Poor', 'Not checked yet',
            '{n} characters', '{n} of {max} characters', 'Updates as you type.',
            'Couldn’t check the page. Save it and try again.',
        ]);
    }
}
