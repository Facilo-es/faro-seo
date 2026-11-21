<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use yii\web\Response;

/** Faro SEO → Redirect health (F12, Pro). */
class HealthController extends BaseCpController
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('faro-seo:redirects');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->proOrNotice('health', Craft::t('faro-seo', 'Redirect health'), fn() => ['report' => $this->plugin()->health->report()]);
    }

    public function actionFixChains(): Response
    {
        $this->requirePostRequest();
        $this->requireProActive();
        $n = $this->plugin()->health->fixChains();
        return $this->asSuccess(Craft::t('faro-seo', 'Fixed {n} chains.', ['n' => $n]), [], 'faro-seo/health');
    }

    public function actionDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireProActive();
        $this->plugin()->health->disable((array)Craft::$app->getRequest()->getRequiredBodyParam('ids'));
        return $this->asSuccess(Craft::t('faro-seo', 'Redirects turned off.'), [], 'faro-seo/health');
    }

    public function actionCheckLinks(): Response
    {
        $this->requirePostRequest();
        $this->requireProActive();
        $this->plugin()->health->queueLinkCheck();
        return $this->asSuccess(Craft::t('faro-seo', 'Checking links to other sites in the background.'), [], 'faro-seo/health');
    }
}
