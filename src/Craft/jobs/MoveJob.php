<?php

namespace facilo\faro\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\faro\Plugin;

/** "Start the move" from Ether SEO (F8), with progress. The backup is taken before it's queued. */
class MoveJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->mover->move(function(float $p, string $label) use ($queue) {
            $this->setProgress($queue, $p, $label);
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('faro-seo', 'Moving from Ether SEO');
    }
}
