<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\faro\Core\Indexing;
use facilo\faro\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use facilo\faro\Craft\Urls;

/** Front-end files: sitemap index, sitemap pages, robots.txt and the IndexNow key (F4, F6). */
class SiteController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionSitemap(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        return $this->xml(Plugin::getInstance()->sitemap->indexXml($site));
    }

    public function actionSitemapPage(string $source, string $id, int $page): Response
    {
        $xml = Plugin::getInstance()->sitemap->pageXml(Craft::$app->getSites()->getCurrentSite(), $source, $id, $page);
        if ($xml === null) {
            throw new NotFoundHttpException();
        }
        return $this->xml($xml);
    }

    public function actionRobots(): Response
    {
        $plugin = Plugin::getInstance();
        $s = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteUrl = Urls::root($site);
        $sitemapUrl = $siteUrl . '/' . $s->sitemapName;
        if (!$plugin->isProduction()) {
            $body = Indexing::defaultRobotsTxt($sitemapUrl, true);
        } elseif (trim($s->robotsTxt) !== '') {
            $body = Indexing::robotsTxt($s->robotsTxt, $sitemapUrl, $siteUrl);
        } else {
            $body = Indexing::defaultRobotsTxt($sitemapUrl);
        }
        return $this->text($body);
    }

    public function actionIndexNowKey(): Response
    {
        $s = Plugin::getInstance()->getSettings();
        if (!$s->indexNow || $s->indexNowKey === '') {
            throw new NotFoundHttpException();
        }
        return $this->text($s->indexNowKey);
    }

    private function xml(string $body): Response
    {
        $res = Craft::$app->getResponse();
        $res->format = Response::FORMAT_RAW;
        $res->getHeaders()->set('Content-Type', 'application/xml; charset=UTF-8');
        $res->data = $body;
        return $res;
    }

    private function text(string $body): Response
    {
        $res = Craft::$app->getResponse();
        $res->format = Response::FORMAT_RAW;
        $res->getHeaders()->set('Content-Type', 'text/plain; charset=UTF-8');
        $res->data = $body;
        return $res;
    }
}
