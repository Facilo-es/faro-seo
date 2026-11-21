<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use yii\web\Response;
use facilo\faro\Craft\Urls;

/** Faro SEO → Sitemap (F6). */
class SitemapController extends BaseCpController
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('faro-seo:sitemap');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $sites = Craft::$app->getSites();
        $handle = Craft::$app->getRequest()->getQueryParam('site');
        $site = ($handle ? $sites->getSiteByHandle((string)$handle) : null) ?? $sites->getPrimarySite();
        $this->plugin()->sitemap->syncSources();
        return $this->page('sitemap', [
            'title' => Craft::t('faro-seo', 'Sitemap'),
            'site' => $site,
            'sources' => $this->plugin()->sitemap->sourceRows((int)$site->id),
            'custom' => $this->plugin()->sitemap->customRows((int)$site->id),
            'sitemapUrl' => Urls::root($site) . '/' . $this->plugin()->getSettings()->sitemapName,
            'freqs' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->getRequest();
        $sitemap = $this->plugin()->sitemap;
        $sitemap->saveSources((array)$req->getBodyParam('sources', []));
        $custom = $req->getBodyParam('custom');
        $sitemap->saveCustom((int)$req->getRequiredBodyParam('siteId'), is_array($custom) ? array_values($custom) : []);
        return $this->asSuccess(Craft::t('faro-seo', 'Sitemap saved.'));
    }
}
