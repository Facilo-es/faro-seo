<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\db\Query;
use facilo\faro\migrations\Install;
use yii\web\Response;

/** Faro SEO → Broken links (F11, Pro). */
class BrokenLinksController extends BaseCpController
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('faro-seo:broken-links');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $tab = Craft::$app->getRequest()->getQueryParam('tab');
        $tab = in_array($tab, ['bots', 'fixed'], true) ? $tab : 'visitors';
        return $this->proOrNotice('broken-links', Craft::t('faro-seo', 'Broken links'), function() use ($tab) {
            $nf = $this->plugin()->notFound;
            $counts = [];
            foreach (['visitors' => false, 'bots' => true] as $k => $bot) {
                $counts[$k] = (int)(new Query())->from(Install::NOT_FOUND)->where(['isBot' => $bot, 'status' => 'new'])->count();
            }
            $counts['fixed'] = (int)(new Query())->from(Install::NOT_FOUND)->where(['status' => 'handled'])->count();
            return [
                'tab' => $tab,
                'rows' => $nf->rows($tab),
                'counts' => $counts,
                'thisWeek' => $nf->countThisWeek(),
                'sentRight' => (int)(new Query())->from(Install::REDIRECTS)->where(['origin' => '404'])->sum('[[hits]]'),
                'topSource' => $this->topSource(),
            ];
        });
    }

    /** The site most missed visits came from this week, from the referrer browsers send. */
    private function topSource(): ?string
    {
        $hosts = [];
        foreach ($this->plugin()->notFound->topThisWeek(100) as $row) {
            $host = $row['referrer'] ? parse_url((string)$row['referrer'], PHP_URL_HOST) : null;
            if ($host) {
                $hosts[$host] = ($hosts[$host] ?? 0) + (int)$row['hits'];
            }
        }
        arsort($hosts);
        return array_key_first($hosts);
    }

    public function actionAccept(): Response
    {
        $this->requirePostRequest();
        $this->requireProActive();
        $req = Craft::$app->getRequest();
        $dest = $req->getBodyParam('destination');
        $error = $this->plugin()->notFound->accept((int)$req->getRequiredBodyParam('id'), is_string($dest) ? $dest : null);
        if ($error !== null) {
            return $this->asFailure($error);
        }
        return $this->asSuccess(Craft::t('faro-seo', 'Redirect added. Visitors land on the right page now.'));
    }

    public function actionIgnore(): Response
    {
        $this->requirePostRequest();
        $this->requireProActive();
        $ids = (array)Craft::$app->getRequest()->getRequiredBodyParam('id');
        foreach ($ids as $id) {
            $this->plugin()->notFound->ignore((int)$id);
        }
        return $this->asSuccess(Craft::t('faro-seo', 'Ignored.'));
    }
}
