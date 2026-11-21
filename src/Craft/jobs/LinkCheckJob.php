<?php

namespace facilo\faro\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\faro\Craft\Health;
use facilo\faro\Plugin;

/**
 * Checks redirects that point to other sites, one request a second, and
 * remembers which ones answer 404 or 410 (F12).
 */
class LinkCheckJob extends BaseJob
{
    public function execute($queue): void
    {
        $urls = Plugin::getInstance()->health->externalDestinations();
        $client = Craft::createGuzzleClient(['timeout' => 8, 'allow_redirects' => ['max' => 5], 'http_errors' => false]);
        $missing = [];
        $total = max(1, count($urls));
        foreach (array_values($urls) as $i => $url) {
            $this->setProgress($queue, $i / $total, $url);
            try {
                $status = $client->head($url)->getStatusCode();
                if ($status === 405) {
                    $status = $client->get($url, ['headers' => ['Range' => 'bytes=0-0']])->getStatusCode();
                }
                if (in_array($status, [404, 410], true)) {
                    $missing[] = $url;
                }
            } catch (\Throwable) {
                // Unreachable hosts aren't proof the page is gone; the next check tries again.
            }
            if ($i + 1 < count($urls)) {
                sleep(1);
            }
        }
        Craft::$app->getCache()->set(Health::EXTERNAL_CACHE, ['at' => gmdate('c'), 'missing' => $missing], 0);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('faro-seo', 'Checking redirects that lead to other sites');
    }
}
