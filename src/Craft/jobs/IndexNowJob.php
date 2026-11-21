<?php

namespace facilo\faro\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\faro\Plugin;

/**
 * Tells Bing and Yandex (IndexNow) which pages changed, at most once every
 * ten minutes, in one request per host (F6).
 */
class IndexNowJob extends BaseJob
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function execute($queue): void
    {
        $cache = Craft::$app->getCache();
        $urls = $cache->get('faro-indexnow') ?: [];
        $cache->delete('faro-indexnow');
        $cache->delete('faro-indexnow-queued');
        $key = Plugin::getInstance()->getSettings()->indexNowKey;
        if ($urls === [] || $key === '') {
            return;
        }
        $byHost = [];
        foreach ($urls as $url) {
            $p = parse_url((string)$url);
            if (isset($p['host'], $p['scheme'])) {
                $byHost[$p['scheme'] . '://' . $p['host']][] = $url;
            }
        }
        $client = Craft::createGuzzleClient(['timeout' => 10]);
        foreach ($byHost as $origin => $list) {
            try {
                $client->post(self::ENDPOINT, ['json' => [
                    'host' => (string)parse_url($origin, PHP_URL_HOST),
                    'key' => $key,
                    'keyLocation' => "$origin/$key.txt",
                    'urlList' => array_slice($list, 0, 10000),
                ]]);
            } catch (\Throwable $e) {
                Craft::warning("IndexNow ping for $origin failed: {$e->getMessage()}", 'faro-seo');
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('faro-seo', 'Telling search engines about changed pages');
    }
}
