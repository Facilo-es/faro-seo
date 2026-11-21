<?php

namespace facilo\faro\Craft\jobs;

use craft\queue\BaseJob;
use facilo\faro\Plugin;

/** Works out the suggested fix for one new broken link (F11). */
class SuggestJob extends BaseJob
{
    public int $siteId = 0;
    public string $uriHash = '';

    public function execute($queue): void
    {
        Plugin::getInstance()->notFound->suggest($this->siteId, $this->uriHash);
    }

    protected function defaultDescription(): ?string
    {
        return \Craft::t('faro-seo', 'Finding a fix for a broken link');
    }
}
