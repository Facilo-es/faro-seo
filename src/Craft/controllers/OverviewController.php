<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use facilo\faro\Craft\fields\SeoField;
use yii\web\Response;

/** Faro SEO → Overview: "Your site in search". */
class OverviewController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $p = $this->plugin();
        [$withSeo, $pages] = $this->pageCounts();
        return $this->page('overview', [
            'title' => Craft::t('faro-seo', 'Your site in search'),
            'withSeo' => $withSeo,
            'pages' => $pages,
            'redirects' => $p->redirects->count(),
            'sentRight' => $p->redirects->totalHits(),
            'broken' => $p->proActive() ? $p->notFound->countThisWeek() : null,
            'sitemapUrls' => $p->sitemap->urlCount(),
            'etherFound' => $p->mover->etherFound() && $p->mover->lastReport(false) === null,
        ]);
    }

    /**
     * Live pages, and how many have a title or description of their own in a
     * Faro field. Cached for an hour; it reads each page once.
     *
     * @return array{0: int, 1: int}
     */
    private function pageCounts(): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get('faro-overview-pages');
        if (is_array($cached)) {
            return $cached;
        }
        $layoutUids = [];
        foreach (Craft::$app->getFields()->getAllFields() as $f) {
            if ($f instanceof SeoField) {
                foreach ((new Query())->select('config')->from('{{%fieldlayouts}}')->where(['dateDeleted' => null])->column() as $config) {
                    foreach (Json::decodeIfJson((string)$config)['tabs'] ?? [] as $tab) {
                        foreach ($tab['elements'] ?? [] as $el) {
                            if (($el['fieldUid'] ?? null) === $f->uid && isset($el['uid'])) {
                                $layoutUids[$el['uid']] = true;
                            }
                        }
                    }
                }
            }
        }
        $q = (new Query())->select(['es.content'])->from(['es' => '{{%elements_sites}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[es.elementId]]')
            ->where(['es.enabled' => true, 'e.enabled' => true, 'e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->andWhere(['not', ['es.uri' => null]]);
        $pages = (int)$q->count();
        $with = 0;
        if ($layoutUids !== []) {
            foreach ($q->each(500) as $row) {
                $content = Json::decodeIfJson((string)$row['content']);
                foreach (array_intersect_key(is_array($content) ? $content : [], $layoutUids) as $v) {
                    if (is_array($v) && (trim((string)($v['title'] ?? '')) !== '' || trim((string)($v['description'] ?? '')) !== '')) {
                        $with++;
                        break;
                    }
                }
            }
        }
        $cache->set('faro-overview-pages', [$with, $pages], 3600);
        return [$with, $pages];
    }
}
